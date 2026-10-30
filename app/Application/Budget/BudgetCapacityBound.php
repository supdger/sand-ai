<?php

declare(strict_types=1);

namespace app\Application\Budget;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\DispatchRouteSnapshot;
use app\Application\Gateway\ProviderRequestBodyBuilder;
use app\Application\Gateway\RequestPayloadFingerprint;
use app\Application\Usage\ResolvedTokenPrice;
use app\Application\Usage\TokenPriceCalculator;
use app\Infrastructure\Persistence\Model\ModelDeployment;
use app\Infrastructure\Persistence\Model\Provider;
use app\Infrastructure\Provider\OpenAiCompatibleExecutionConfigPolicy;
use app\Infrastructure\Provider\ProviderSecretDecryptor;

/** A full protocol capacity reservation; it does not claim to count this input. */
final readonly class BudgetCapacityBound
{
    private function __construct(
        private array $route,
        private string $bodySha256,
        private array $source,
        private int $environmentId,
        private int $outputTokens,
        private int $entryCount,
        private ?string $requestProtocol,
    ) {
    }

    /** Called only with the final SDK body and a trusted frozen route. No I/O to a provider. */
    public static function fromRoute(array $route, array $body, int $environmentId, ?string $requestProtocol = null): self
    {
        if ($environmentId < 1) self::unavailable();
        $route = DispatchRouteSnapshot::assert($route, array_key_exists('max_output_tokens', $route));
        $deployment = ModelDeployment::where('id', $route['deployment_id'])->find();
        $provider = Provider::where('id', $route['provider_id'])->find();
        if ($deployment === null || $provider === null) self::unavailable();
        $config = self::arrayValue($deployment->config);
        $encrypted = self::arrayValue($provider->encrypted_config);
        if ($route['provider_adapter'] !== 'openai_compatible'
            || (string) $provider->adapter !== 'openai_compatible'
            || (int) $deployment->model_id !== $route['model_id']
            || (int) $deployment->provider_id !== $route['provider_id']
            || (int) $deployment->revision !== $route['deployment_revision']
            || (string) $deployment->remote_model !== $route['deployment_remote_model']
            || !hash_equals($route['deployment_config_sha256'], RequestPayloadFingerprint::fingerprint($config, []))
            || !hash_equals($route['provider_config_sha256'], RequestPayloadFingerprint::fingerprint($encrypted, []))) self::unavailable();
        $runtime = (new OpenAiCompatibleExecutionConfigPolicy())->normalize(
            (new ProviderSecretDecryptor())->decrypt($encrypted));
        if ($runtime['base_url'] !== OfficialModelCapacity::BASE_URL
            || $runtime['extra_headers'] !== []
            || $runtime['output_token_parameter'] !== 'max_tokens') self::unavailable();
        $registration = $config['budget_capacity_attestation'] ?? null;
        if (!is_array($registration)) self::unavailable();
        $source = OfficialModelCapacity::resolve($registration, $route['deployment_remote_model'], $route['capability_code']);
        $output = self::assertBody($body, $source, $route['deployment_remote_model'], $requestProtocol);
        $entries = $source['capability_code'] === 'embedding' && is_array($body['input'] ?? null) ? count($body['input']) : 1;
        if ($route['capability_code'] === 'chat' || $source['protocol'] === BudgetChatWireProtocol::VERSION) {
            $configuredOutput = $config['max_output_tokens'] ?? null;
            if (!is_int($configuredOutput) || $configuredOutput < $output || $configuredOutput > $source['max_output_tokens']
                || (isset($route['max_output_tokens']) && $route['max_output_tokens'] !== $output)) self::unavailable();
        }
        return new self($route, ProviderRequestBodyBuilder::digest($body), $source, $environmentId, $output, $entries, $requestProtocol);
    }

    public function assertTarget(ModelDeployment $deployment, int $modelId, int $environmentId, array $route, array $body): void
    {
        $route = DispatchRouteSnapshot::assert($route, array_key_exists('max_output_tokens', $route));
        if ($this->environmentId !== $environmentId
            || $this->route['model_id'] !== $modelId
            || (int) $deployment->id !== $this->route['deployment_id']
            || (int) $deployment->revision !== $this->route['deployment_revision']
            || (int) $deployment->model_id !== $modelId
            || (int) $deployment->provider_id !== $this->route['provider_id']
            || !hash_equals($this->route['deployment_config_sha256'], RequestPayloadFingerprint::fingerprint(self::arrayValue($deployment->config), []))
            || !hash_equals($this->route['snapshot_sha256'], $route['snapshot_sha256'])
            || !hash_equals($this->bodySha256, ProviderRequestBodyBuilder::digest($body))) self::unavailable();
        self::assertBody($body, $this->source, $this->route['deployment_remote_model'], $this->requestProtocol);
    }

    /** Uses the frozen published price; no currency conversion and no counter fee. */
    public function reservation(?ResolvedTokenPrice $price, int $requestedOutput): array
    {
        if ($price === null) throw new ApiProblem('SAND_AI_BUDGET_UNPRICED', 'A published price is required for capacity reservation');
        if ($requestedOutput !== $this->outputTokens) self::unavailable();
        $input = $this->source['input_capacity_tokens'] * $this->entryCount;
        $amount = TokenPriceCalculator::calculateReservation($input, $this->outputTokens,
            $price->inputRate['unit_price'], $price->inputRate['per_quantity'],
            $price->outputRate['unit_price'], $price->outputRate['per_quantity']);
        $bound = [
                'kind' => 'official_protocol_capacity', 'capacity_version' => $this->source['version'],
                'source_code' => $this->source['source_code'], 'source_url' => $this->source['source_url'],
                'source_sha256' => $this->source['source_sha256'], 'definition_url' => $this->source['definition_url'],
                'definition_sha256' => $this->source['definition_sha256'], 'input_capacity_tokens' => $input,
                'route_sha256' => $this->route['snapshot_sha256'], 'body_sha256' => $this->bodySha256,
                'counted_input_tokens' => null,
                'amount_rounding' => TokenPriceCalculator::RESERVATION_ROUNDING,
            ];
        if ($this->source['protocol'] === BudgetChatWireProtocol::VERSION) {
            $bound += ['protocol' => BudgetChatWireProtocol::VERSION,
                'wire_evidence_sha256' => RequestPayloadFingerprint::fingerprint($this->source['wire_evidence'], [])];
        }
        if ($this->source['capability_code'] === 'embedding') {
            $bound += ['protocol' => $this->requestProtocol, 'entry_count' => $this->entryCount,
                'source_input_capacity_tokens' => $this->source['input_capacity_tokens']];
        }
        return ['reserved_input_tokens' => $input, 'output_token_upper_bound' => $this->outputTokens,
            'estimated_amount' => $amount['amount'], 'currency' => $price->currency,
            'price_revision_id' => $price->revisionId, 'input_bound' => $bound];
    }

    private static function assertBody(array $body, array $source, string $model, ?string $requestProtocol): int
    {
        if (($body['model'] ?? null) !== $model) self::unavailable();
        if ($source['protocol'] === 'single_text_embedding') {
            if (is_array($body['input'] ?? null)) {
                if ($model !== $source['batch_model'] || $requestProtocol !== $source['batch_request_protocol']
                    || array_diff(array_keys($body), ['model', 'input', 'encoding_format']) !== []
                    || ($body['encoding_format'] ?? null) !== 'float'
                    || !array_is_list($body['input']) || count($body['input']) < 1
                    || count($body['input']) > $source['max_batch_entries']) self::unavailable();
                $bytes = 0;
                foreach ($body['input'] as $text) {
                    if (!is_string($text) || trim($text) === '' || preg_match('//u', $text) !== 1
                        || strlen($text) > 65536) self::unavailable();
                    $bytes += strlen($text);
                    if ($bytes > 65536) self::unavailable();
                }
                return 0;
            }
            if ($requestProtocol !== null) self::unavailable();
            if (array_diff(array_keys($body), ['model', 'input', 'dimensions', 'encoding_format']) !== []
                || !is_string($body['input'] ?? null) || trim($body['input']) === ''
                || preg_match('//u', $body['input']) !== 1
                || (isset($body['encoding_format']) && $body['encoding_format'] !== 'float')
                || (isset($body['dimensions']) && (!is_int($body['dimensions']) || !in_array($body['dimensions'], [256, 512, 1024, 2048], true)))
                || ($model === 'embedding-2' && isset($body['dimensions']) && $body['dimensions'] !== 1024)) self::unavailable();
            return 0;
        }
        if ($requestProtocol !== null) self::unavailable();
        if ($source['protocol'] === BudgetChatWireProtocol::VERSION) return BudgetChatWireProtocol::assertBody($body, $model, $source['max_output_tokens']);
        $allowed = ['model', 'messages', 'max_tokens', 'stream', 'stream_options', 'reasoning_effort', 'temperature', 'top_p', 'do_sample', 'stop'];
        if (array_diff(array_keys($body), $allowed) !== []
            || !is_array($body['messages'] ?? null) || !array_is_list($body['messages']) || $body['messages'] === []
            || !is_int($body['max_tokens'] ?? null) || $body['max_tokens'] < 1 || $body['max_tokens'] > $source['max_output_tokens']) self::unavailable();
        $hasUser = false;
        foreach ($body['messages'] as $message) {
            if (!is_array($message) || array_diff(array_keys($message), ['role', 'content']) !== []
                || !in_array($message['role'] ?? null, ['system', 'user', 'assistant'], true)
                || !is_string($message['content'] ?? null) || preg_match('//u', $message['content']) !== 1) self::unavailable();
            $hasUser = $hasUser || $message['role'] === 'user';
        }
        if (!$hasUser) self::unavailable();
        return $body['max_tokens'];
    }

    private static function arrayValue(mixed $value): array
    {
        $value = is_object($value) ? (array) $value : $value;
        if (!is_array($value)) self::unavailable();
        return $value;
    }

    private static function unavailable(): never
    {
        throw new ApiProblem('SAND_AI_BUDGET_CAPACITY_UNVERIFIED',
            'The final provider body or frozen route is outside its reviewed official capacity protocol');
    }
}
