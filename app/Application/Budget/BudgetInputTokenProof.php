<?php

declare(strict_types=1);

namespace app\Application\Budget;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\DispatchRouteSnapshot;
use app\Application\Gateway\ProviderRequestBodyBuilder;
use app\Application\Gateway\RequestPayloadFingerprint;
use app\Application\Audio\MeetingSummaryCapacity;
use app\Application\Audio\PostgresMeetingTokenCountLedger;
use app\Infrastructure\Persistence\Model\AiModel;
use app\Infrastructure\Persistence\Model\ModelDeployment;
use app\Infrastructure\Persistence\Model\Provider;
use app\Infrastructure\Persistence\Model\TaskRun;
use think\facade\Db;

/**
 * Server-owned proof for the exact text-only request counted by the official
 * tokenizer. No byte estimate or caller-supplied token count can create it.
 * Other models and protocols require their own verified counter integration.
 */
final readonly class BudgetInputTokenProof
{
    /** @param array<string,mixed> $route */
    private function __construct(
        public int $promptTokens,
        public int $externalRequestEventId,
        private string $messagesSha256,
        private string $requestBodySha256,
        private int $maxOutputTokens,
        private array $route,
        private int $environmentId,
    ) {
    }

    /**
     * Read and verify the existing append-only official tokenizer outcome under
     * this task's frozen capacity/route. A detached receipt or positive event id
     * cannot create proof. This method performs no tokenizer/provider request.
     *
     * @param array<string,mixed> $route
     * @param array<string,mixed> $body Actual SDK-generated provider body.
     */
    public static function fromMeetingTask(TaskRun $task, array $route, array $body): self
    {
        $route = DispatchRouteSnapshot::assert($route, false);
        $input = is_object($task->input) ? (array) $task->input : $task->input;
        $capacity = is_array($input) ? ($input['_meeting_capacity'] ?? null) : null;
        if (!is_array($capacity)) self::unavailable();
        MeetingSummaryCapacity::assert($capacity);
        if (!hash_equals($route['snapshot_sha256'], $capacity['route']['snapshot_sha256'])) self::unavailable();
        $messages = self::countedMessages($body);
        $digest = RequestPayloadFingerprint::fingerprint($messages, []);
        $rows = Db::connect('pgsql')->query(
            "SELECT o.capacity_receipt FROM sand_ai_meeting_token_count_request r JOIN sand_ai_meeting_token_count_outcome o ON o.request_event_id=r.id WHERE r.task_id=? AND r.input_sha256=? AND o.state='succeeded' AND o.http_status=200 LIMIT 2",
            [(int) $task->id, $digest],
        );
        if (count($rows) !== 1 || !is_string($rows[0]['capacity_receipt'] ?? null)) self::unavailable();
        try {
            $receipt = json_decode($rows[0]['capacity_receipt'], true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            self::unavailable();
        }
        if (!is_array($receipt)) self::unavailable();
        PostgresMeetingTokenCountLedger::verifyReceipt($task, $capacity, $receipt);
        if ($route['provider_adapter'] !== 'openai_compatible'
            || $route['deployment_remote_model'] !== 'glm-5.3-flash'
            || ($receipt['counter'] ?? null) !== 'bigmodel_tokenizer@1'
            || ($receipt['remote_model'] ?? null) !== $route['deployment_remote_model']
            || !is_int($receipt['prompt_tokens'] ?? null) || $receipt['prompt_tokens'] < 1
            || $receipt['prompt_tokens'] > 1000000
            || !is_int($receipt['external_request_event_id'] ?? null) || $receipt['external_request_event_id'] < 1
            || !is_string($receipt['provider_request_id'] ?? null) || $receipt['provider_request_id'] === ''
            || !is_string($receipt['request_id'] ?? null) || $receipt['request_id'] === '') {
            self::unavailable();
        }
        if (!is_string($receipt['input_sha256'] ?? null)
            || !hash_equals($digest, $receipt['input_sha256'])
            || ($body['model'] ?? null) !== $route['deployment_remote_model']) {
            self::unavailable();
        }
        $output = self::outputBound($body);
        return new self($receipt['prompt_tokens'], $receipt['external_request_event_id'],
            $digest, ProviderRequestBodyBuilder::digest($body), $output, $route, (int) $task->environment_id);
    }

    /**
     * Reject any changed provider body, route or configured bound before
     * reservation. The dispatch coordinator freezes this same body and route.
     *
     * @param array<string,mixed> $body
     * @param array<string,mixed> $route
     */
    public function assertRequest(array $body, array $route, BudgetTokenProfile $profile, int $outputTokens): void
    {
        $route = DispatchRouteSnapshot::assert($route, false);
        if (!hash_equals($this->requestBodySha256, ProviderRequestBodyBuilder::digest($body))
            || !hash_equals($this->route['snapshot_sha256'], $route['snapshot_sha256'])
            || self::outputBound($body) !== $outputTokens) {
            self::unavailable();
        }
        $this->assertInput(self::countedMessages($body), $profile, $outputTokens);
    }

    /** @param list<array{role:string,content:string}> $messages */
    public function assertInput(array $messages, BudgetTokenProfile $profile, int $outputTokens): void
    {
        if (!hash_equals($this->messagesSha256, RequestPayloadFingerprint::fingerprint($messages, []))
            || $this->maxOutputTokens !== $outputTokens) {
            self::unavailable();
        }
        if ($this->promptTokens > $profile->inputTokenCeiling) {
            throw new ApiProblem('SAND_AI_BUDGET_INPUT_BOUND_EXCEEDED',
                'The officially counted input exceeds the published budget input bound; provider generation was not sent');
        }
        $profile->assertRequestedOutputTokens($outputTokens);
    }

    public function assertReservationTarget(ModelDeployment $deployment, int $modelId, int $environmentId): void
    {
        if ($environmentId !== $this->environmentId || $environmentId < 1
            || $modelId !== $this->route['model_id']
            || (int) $deployment->model_id !== $modelId
            || (int) $deployment->id !== $this->route['deployment_id']
            || (int) $deployment->provider_id !== $this->route['provider_id']
            || (int) $deployment->revision !== $this->route['deployment_revision']
            || (string) $deployment->remote_model !== $this->route['deployment_remote_model']
            || !hash_equals($this->route['deployment_config_sha256'], self::hashValue($deployment->config))) {
            self::unavailable();
        }
    }

    /**
     * Lowest-cost discovery is advisory. Verify its exact candidate identity
     * before using an official proof to compare any upper-bound amount.
     *
     * @param array<string,mixed> $route
     */
    public function assertCandidate(array $route, ModelDeployment $deployment, Provider $provider, AiModel $model): void
    {
        foreach (['route_id', 'profile_id', 'profile_revision'] as $field) {
            if (($route[$field] ?? null) !== $this->route[$field]) self::unavailable();
        }
        if ((int) $deployment->id !== $this->route['deployment_id']
            || (int) $deployment->revision !== $this->route['deployment_revision']
            || (int) $provider->id !== $this->route['provider_id']
            || (int) $model->id !== $this->route['model_id']
            || (string) $deployment->remote_model !== $this->route['deployment_remote_model']
            || (string) $provider->adapter !== $this->route['provider_adapter']
            || !hash_equals($this->route['deployment_config_sha256'], self::hashValue($deployment->config))
            || !hash_equals($this->route['provider_config_sha256'], self::hashValue($provider->encrypted_config))) {
            self::unavailable();
        }
    }

    /** Official current counter supports only plain system/user messages. @return list<array{role:string,content:string}> */
    private static function countedMessages(array $body): array
    {
        $allowed = ['model', 'messages', 'stream', 'stream_options', 'max_tokens', 'max_completion_tokens', 'reasoning_effort'];
        $messages = $body['messages'] ?? null;
        if (array_diff(array_keys($body), $allowed) !== []
            || !is_array($messages) || !array_is_list($messages) || count($messages) !== 2
            || array_column($messages, 'role') !== ['system', 'user']) self::unavailable();
        foreach ($messages as $message) {
            if (!is_array($message) || array_diff(array_keys($message), ['role', 'content']) !== []
                || !is_string($message['content'] ?? null) || trim($message['content']) === ''
                || preg_match('//u', $message['content']) !== 1
                || mb_strlen($message['content'], 'UTF-8') > 32000) self::unavailable();
        }
        return $messages;
    }

    /** One actual output limit must be enforced by the provider body. */
    private static function outputBound(array $body): int
    {
        $values = array_intersect_key($body, array_flip(['max_tokens', 'max_completion_tokens']));
        if (count($values) !== 1) self::unavailable();
        $value = reset($values);
        if (!is_int($value) || $value < 1 || $value > BudgetTokenProfile::MAX_TOKEN_BOUND) self::unavailable();
        return $value;
    }

    private static function hashValue(mixed $value): string
    {
        return RequestPayloadFingerprint::fingerprint(is_object($value) ? (array) $value : (is_array($value) ? $value : []), []);
    }

    private static function unavailable(): never
    {
        throw new ApiProblem('SAND_AI_BUDGET_INPUT_PROOF_REQUIRED',
            'This exact provider request has no supported official input-token proof; generation was not sent');
    }
}
