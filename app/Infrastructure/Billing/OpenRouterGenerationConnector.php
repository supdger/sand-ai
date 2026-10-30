<?php

declare(strict_types=1);

namespace app\Infrastructure\Billing;

use app\Api\Support\ApiProblem;
use app\Application\Billing\ProviderBillingConnector;
use app\Application\Tool\StrictJsonObjectDecoder;
use DateTimeImmutable;
use DateTimeZone;

/** The official generation record is a USD request cost, never a refund proof. */
final class OpenRouterGenerationConnector implements ProviderBillingConnector
{
    public function __construct(
        private readonly int $providerId,
        private readonly BillingHttpTransport $transport,
        private readonly OpenRouterGenerationUsage $usage = new OpenRouterGenerationUsage(),
        private readonly LosslessJsonDecoder $decoder = new LosslessJsonDecoder(),
        private readonly OpenAiCostDecimal $decimal = new OpenAiCostDecimal(),
    ) {
    }

    public function providerCode(): string { return 'openrouter'; }

    public function fetch(array $request): array
    {
        if (($request['provider_id'] ?? null) !== $this->providerId) throw $this->invalid();
        $credential = $this->usage->credentials($this->providerId);
        $candidates = $this->usage->candidates($this->providerId, $request['period_start'], $request['period_end'], $credential);
        if ($candidates === []) return [];
        $key = $this->read('/api/v1/key', [], $credential);
        if (!array_key_exists('organization_id', $key) || !array_key_exists('workspace_id', $key)) throw $this->invalid();
        if ($key['organization_id'] === null) {
            $owner = 'personal:' . $this->identifier($key['creator_user_id'] ?? null);
        } else {
            $owner = 'organization:' . $this->identifier($key['organization_id']);
        }
        $workspace = $key['workspace_id'] === null ? null : $this->identifier($key['workspace_id']);
        $account = hash('sha256', $owner . "\n" . ($workspace ?? ''));
        $lines = [];
        foreach ($candidates as $candidate) {
            $generation = $this->read('/api/v1/generation', ['id' => $candidate['generation_id']], $credential);
            if (($generation['id'] ?? null) !== $candidate['generation_id']
                || ($generation['model'] ?? null) !== $candidate['remote_model']
                || ($generation['is_byok'] ?? null) !== false
                || ($generation['api_type'] ?? null) !== 'completions'
                || ($generation['cancelled'] ?? null) !== false
                || !is_string($generation['finish_reason'] ?? null) || $generation['finish_reason'] === ''
                || !array_key_exists('workspace_id', $generation)
                || $generation['workspace_id'] !== $workspace) throw $this->invalid();
            // Subtotals such as reasoning/cached tokens are already included in
            // native prompt/completion. They never become additional charges.
            $input = $this->tokens($generation['native_tokens_prompt'] ?? null);
            $output = $this->tokens($generation['native_tokens_completion'] ?? null);
            if ($input > PHP_INT_MAX - $output) throw $this->invalid();
            foreach (['num_media_prompt','num_media_completion','num_input_audio_prompt','num_search_results','num_fetches'] as $field) {
                if (!array_key_exists($field, $generation) || $this->tokens($generation[$field]) !== 0) throw $this->invalid();
            }
            if (!($generation['total_cost'] ?? null) instanceof LosslessJsonNumber) throw $this->invalid();
            try { $amount = $this->decimal->normalize($generation['total_cost']->value); }
            catch (ApiProblem) { throw new ApiProblem('SAND_AI_BILLING_NORMALIZATION_FAILED', 'Generation cost exceeds supported exact decimal precision'); }
            $time = $this->timestamp($generation['created_at'] ?? null);
            // Import windows are UTC, half-open. Do not silently assign an
            // out-of-period generation to the observed usage event.
            if ($time < $request['period_start'] || $time >= $request['period_end']) throw $this->invalid();
            $lines[] = [
                'external_line_id' => $candidate['generation_id'], 'model_code' => $candidate['model_code'],
                'capability_code' => $candidate['capability_code'], 'meter_code' => 'total_tokens',
                'quantity' => (string) ($input + $output), 'amount' => $amount, 'currency' => 'USD',
                'occurred_start' => $time, 'occurred_end' => (new DateTimeImmutable($time, new DateTimeZone('UTC')))->modify('+1 second')->format('Y-m-d H:i:s'),
                '_fact_namespace' => 'openrouter_generation_v1', '_fact_account_scope' => $account,
                '_fact_request' => [
                    'usage_event_id' => (int) $candidate['usage_event_id'],
                    'invocation_id' => (int) $candidate['invocation_id'],
                    'dispatch_id' => (int) $candidate['dispatch_id'],
                    'generation_id' => $candidate['generation_id'],
                    'request_body_digest' => $candidate['request_body_digest'],
                    'execution_snapshot_sha256' => $candidate['execution_snapshot_sha256'],
                    'provider_config_sha256' => $credential['config_sha256'],
                    'generation_created_at' => $time, 'native_input_tokens' => $input, 'native_output_tokens' => $output,
                ],
            ];
        }
        $this->usage->assertCurrent($this->providerId, $credential['config_sha256']);
        return $lines;
    }

    /** @param array{secret:string,config_sha256:string} $credential @return array<string,mixed> */
    private function read(string $path, array $query, array $credential): array
    {
        $this->usage->assertCurrent($this->providerId, $credential['config_sha256']);
        try { $response = $this->transport->get($path, $query, $credential['secret']); }
        catch (ApiProblem $problem) { throw $problem; }
        catch (\Throwable) { throw new ApiProblem('SAND_AI_BILLING_UNAVAILABLE', 'Generation metadata is unavailable'); }
        $status = $response['status'] ?? null;
        if ($status === 401 || $status === 403) throw new ApiProblem('SAND_AI_BILLING_AUTH_FAILED', 'Generation metadata authorization failed');
        if ($status === 429) throw new ApiProblem('SAND_AI_BILLING_RATE_LIMITED', 'Generation metadata is rate limited');
        if ($status !== 200 || !is_string($response['body'] ?? null)) throw new ApiProblem('SAND_AI_BILLING_UNAVAILABLE', 'Generation metadata is unavailable');
        try {
            StrictJsonObjectDecoder::decodeObject($response['body']);
            $body = $this->decoder->decodeTyped($response['body']);
        } catch (\Throwable) { throw $this->invalid(); }
        if (!is_array($body['data'] ?? null) || array_is_list($body['data'])) throw $this->invalid();
        return $body['data'];
    }

    private function tokens(mixed $value): int
    {
        if (!$value instanceof LosslessJsonNumber || preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value->value) !== 1
            || strlen($value->value) > strlen((string) PHP_INT_MAX)
            || (strlen($value->value) === strlen((string) PHP_INT_MAX) && strcmp($value->value, (string) PHP_INT_MAX) > 0)) throw $this->invalid();
        return (int) $value->value;
    }

    private function identifier(mixed $value): string
    {
        if (!is_string($value) || preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', $value) !== 1) throw $this->invalid();
        return $value;
    }

    private function timestamp(mixed $value): string
    {
        if (!is_string($value) || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,6})?(?:Z|[+-][0-9]{2}:[0-9]{2})\z/D', $value) !== 1) throw $this->invalid();
        try {
            $time = new DateTimeImmutable($value);
            $errors = DateTimeImmutable::getLastErrors();
            if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) throw $this->invalid();
        } catch (\Throwable) { throw $this->invalid(); }
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function invalid(): ApiProblem
    {
        return new ApiProblem('SAND_AI_BILLING_REQUEST_IDENTITY_UNVERIFIED', 'Generation metadata does not prove the original request cost');
    }
}
