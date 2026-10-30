<?php

declare(strict_types=1);

namespace app\Application\Audio;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\DispatchRouteSnapshot;
use app\Application\Gateway\RequestPayloadFingerprint;
use app\Infrastructure\Persistence\Model\ModelDeployment;
use app\Infrastructure\Persistence\Model\Provider;
use app\Infrastructure\Provider\BigModelMeetingTokenCounter;
use app\Infrastructure\Provider\ProviderSecretDecryptor;

/** Exact published route plus an explicitly registered official capacity source. */
final class MeetingSummaryCapacity
{
    public const VERSION = 'meeting_capacity@1';
    private ?BigModelMeetingTokenCounter $counter = null;

    /** @param array<string,mixed> $snapshot */
    public function __construct(public readonly array $snapshot, private readonly ?\Closure $pulse = null)
    {
        self::assert($snapshot);
    }

    /** @param array<string,mixed> $route @return array<string,mixed> */
    public static function freeze(array $route, int $outputTokens, bool $lockRegistration = false): array
    {
        $route = DispatchRouteSnapshot::assert($route, false);
        $registered = (new MeetingExecutionGateway())->enabledForRoute($route, $lockRegistration);
        if ($registered !== null) return self::freezeRegistered($route, $outputTokens, $registered);
        if (config('sand_ai_meeting_summary.durable_worker.enabled', false) !== true) {
            throw new ApiProblem('SAND_AI_MEETING_EXECUTION_DISABLED', '请在后台会议执行设置中登记并启用当前发布部署');
        }
        $registrations = config('sand_ai_meeting_summary.registrations', []);
        $registration = is_array($registrations) ? ($registrations[$route['snapshot_sha256']] ?? null) : null;
        if (!is_array($registration) || $route['provider_adapter'] !== 'openai_compatible'
            || $route['deployment_remote_model'] !== 'glm-5.3-flash') self::unavailable();
        $deployment = ModelDeployment::where('id', $route['deployment_id'])->where('status', 1)->find();
        $config = $deployment?->config;
        $config = is_object($config) ? (array) $config : $config;
        if ($deployment === null || (int) $deployment->revision !== $route['deployment_revision']
            || !is_array($config)
            || !hash_equals($route['deployment_config_sha256'], RequestPayloadFingerprint::fingerprint($config, []))
            || !is_int($config['max_output_tokens'] ?? null)
            || $outputTokens < 1 || $outputTokens > $config['max_output_tokens']) self::unavailable();
        $context = $registration['context_window_tokens'] ?? null;
        $input = $registration['input_token_limit'] ?? null;
        if (!is_int($context) || $context < 2 || $context > 1000000
            || !is_int($input) || $input < 1 || $input > $context - $outputTokens
            || (isset($config['input_token_ceiling']) && (!is_int($config['input_token_ceiling'])
                || $config['input_token_ceiling'] < 1 || $input > $config['input_token_ceiling']))) self::unavailable();
        $snapshot = [
            'version' => self::VERSION, 'route' => $route,
            'counter' => 'bigmodel_tokenizer@1', 'endpoint' => BigModelMeetingTokenCounter::ENDPOINT,
            'context_window_tokens' => $context, 'input_token_limit' => $input,
            'max_output_tokens' => $outputTokens,
            'max_tokenizer_requests' => $registration['max_tokenizer_requests'] ?? 100000,
            'capacity_source_url' => $registration['capacity_source_url'] ?? null,
            'capacity_source_sha256' => $registration['capacity_source_sha256'] ?? null,
        ];
        $snapshot['snapshot_sha256'] = RequestPayloadFingerprint::fingerprint($snapshot, []);
        self::assert($snapshot);
        return $snapshot;
    }

    /** @param array<string,mixed> $route @param array<string,mixed> $registration @return array<string,mixed> */
    private static function freezeRegistered(array $route, int $outputTokens, array $registration): array
    {
        $row = $registration['row'];
        $owner = $registration['owner'];
        $deployment = ModelDeployment::where('id', $route['deployment_id'])->where('status', 1)->find();
        $config = MeetingExecutionGateway::object($deployment?->config);
        if ($deployment === null || (int) $deployment->revision !== $route['deployment_revision']
            || !hash_equals($route['deployment_config_sha256'], RequestPayloadFingerprint::fingerprint($config, []))
            || !is_int($config['max_output_tokens'] ?? null) || $outputTokens < 1 || $outputTokens > min(4096, $config['max_output_tokens'])) self::unavailable();
        $capacity = MeetingExecutionPolicy::capacity(MeetingExecutionGateway::object($row['capacity']), $route,
            $outputTokens, $config['input_token_ceiling'] ?? null);
        $snapshot = [
            'version' => MeetingExecutionRuntime::CAPACITY_VERSION, 'route' => $route,
            'counter' => $capacity['tokenizer_driver'] ?? null, 'endpoint' => isset($capacity['tokenizer_driver']) ? MeetingTokenizerDrivers::endpoint($capacity['tokenizer_driver']) : null,
            'context_window_tokens' => $capacity['context_window_tokens'], 'input_token_limit' => $capacity['input_token_limit'],
            'max_output_tokens' => $outputTokens, 'max_tokenizer_requests' => $capacity['max_tokenizer_requests'],
            'capacity_source_url' => $capacity['capacity_source_url'], 'capacity_source_sha256' => $capacity['capacity_source_sha256'],
            'registration' => ['id' => (int) $row['id'], 'revision' => (int) $row['revision'],
                'organization_id' => $owner['organization_id'], 'application_id' => $owner['application_id'],
                'environment_id' => $owner['environment_id'], 'route_sha256' => $route['snapshot_sha256']],
        ];
        if (MeetingLocalPlanning::enabled($capacity)) $snapshot['planning_driver'] = MeetingLocalPlanning::DRIVER;
        $snapshot['snapshot_sha256'] = RequestPayloadFingerprint::fingerprint($snapshot, []);
        self::assert($snapshot);
        return $snapshot;
    }

    /** @param array<string,mixed> $snapshot */
    public static function assert(array $snapshot): void
    {
        $keys = ['version', 'route', 'counter', 'endpoint', 'context_window_tokens', 'input_token_limit', 'max_tokenizer_requests',
            'max_output_tokens', 'capacity_source_url', 'capacity_source_sha256', 'snapshot_sha256'];
        $registered = ($snapshot['version'] ?? null) === MeetingExecutionRuntime::CAPACITY_VERSION;
        if ($registered) $keys[] = 'registration';
        $local = MeetingLocalPlanning::enabled($snapshot);
        if ($local) $keys[] = 'planning_driver';
        if (array_diff(array_keys($snapshot), $keys) !== [] || array_diff($keys, array_keys($snapshot)) !== []
            || (! $registered && ($snapshot['version'] ?? null) !== self::VERSION) || !is_array($snapshot['route'] ?? null)
            || ($local ? (!$registered || $snapshot['counter'] !== null || $snapshot['endpoint'] !== null)
                : (($snapshot['counter'] ?? null) !== 'bigmodel_tokenizer@1' || ($snapshot['endpoint'] ?? null) !== BigModelMeetingTokenCounter::ENDPOINT))
            || (!$registered && ($snapshot['capacity_source_url'] ?? null) !== 'https://docs.bigmodel.cn/cn/guide/models/vlm/glm-5.3-flash')
            || !is_string($snapshot['capacity_source_sha256'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/D', $snapshot['capacity_source_sha256']) !== 1
            || !is_int($snapshot['context_window_tokens'] ?? null)
            || $snapshot['context_window_tokens'] < 2 || $snapshot['context_window_tokens'] > ($local ? 1048576 : 1000000)
            || !is_int($snapshot['max_output_tokens'] ?? null) || $snapshot['max_output_tokens'] < 1
            || $snapshot['max_output_tokens'] > 4096
            || !is_int($snapshot['input_token_limit'] ?? null) || $snapshot['input_token_limit'] < 1
            || $snapshot['input_token_limit'] > $snapshot['context_window_tokens'] - $snapshot['max_output_tokens']
            || !is_int($snapshot['max_tokenizer_requests'] ?? null)
            || ($local ? $snapshot['max_tokenizer_requests'] !== 0
                : ($snapshot['max_tokenizer_requests'] < 40000 || $snapshot['max_tokenizer_requests'] > 100000))
            || !is_string($snapshot['snapshot_sha256'] ?? null)) self::unavailable();
        $route = DispatchRouteSnapshot::assert($snapshot['route'], false);
        if (!$local && ($route['provider_adapter'] !== 'openai_compatible'
            || $route['deployment_remote_model'] !== 'glm-5.3-flash')) self::unavailable();
        if ($registered) {
            $proof = $snapshot['registration'] ?? null;
            $proofKeys = ['id', 'revision', 'organization_id', 'application_id', 'environment_id', 'route_sha256'];
            if (!is_array($proof) || array_diff(array_keys($proof), $proofKeys) !== []
                || array_diff($proofKeys, array_keys($proof)) !== []) self::unavailable();
            foreach (['id', 'revision', 'organization_id', 'application_id', 'environment_id'] as $key) {
                if (!is_int($proof[$key] ?? null) || $proof[$key] < 1) self::unavailable();
            }
            if (!is_string($proof['route_sha256']) || !hash_equals($route['snapshot_sha256'], $proof['route_sha256'])) self::unavailable();
            MeetingExecutionPolicy::capacity([
                ...($local ? ['planning_driver' => MeetingLocalPlanning::DRIVER] : ['tokenizer_driver' => $snapshot['counter']]), 'context_window_tokens' => $snapshot['context_window_tokens'],
                'input_token_limit' => $snapshot['input_token_limit'], 'max_tokenizer_requests' => $snapshot['max_tokenizer_requests'],
                'capacity_source_url' => $snapshot['capacity_source_url'], 'capacity_source_sha256' => $snapshot['capacity_source_sha256'],
            ], $route, $snapshot['max_output_tokens'], null);
        }
        $hashInput = $snapshot;
        unset($hashInput['snapshot_sha256']);
        if (!hash_equals($snapshot['snapshot_sha256'], RequestPayloadFingerprint::fingerprint($hashInput, []))) self::unavailable();
    }

    /** @param list<array{role:string,content:string}> $messages @return array<string,mixed> */
    public function measure(array $messages): array
    {
        if (MeetingLocalPlanning::enabled($this->snapshot)) self::unavailable();
        $this->counter ??= $this->loadCounter();
        if ($this->pulse !== null) ($this->pulse)();
        $receipt = $this->counter->count($messages);
        if ($this->pulse !== null) ($this->pulse)();
        return $receipt;
    }

    /** @param array<string,mixed> $receipt @param list<array{role:string,content:string}> $messages */
    public function assertFits(array $receipt, array $messages): void
    {
        if (($receipt['counter'] ?? null) !== $this->snapshot['counter']
            || ($receipt['remote_model'] ?? null) !== $this->snapshot['route']['deployment_remote_model']
            || ($receipt['input_sha256'] ?? null) !== RequestPayloadFingerprint::fingerprint($messages, [])
            || !is_int($receipt['prompt_tokens'] ?? null) || $receipt['prompt_tokens'] < 1) self::unavailable();
        if ($receipt['prompt_tokens'] > $this->snapshot['input_token_limit']
            || $receipt['prompt_tokens'] > $this->snapshot['context_window_tokens'] - $this->snapshot['max_output_tokens']) {
            throw new ApiProblem('SAND_AI_AUDIO_SUMMARY_INPUT_CAPACITY_EXCEEDED', '当前会议节点超过已登记的模型输入容量，尚未发送摘要调用；请选用容量更大的已登记部署');
        }
    }

    public function assertOutputAllowance(int $requestedTokens): void
    {
        if ($requestedTokens < 1 || min(4096, $requestedTokens) !== $this->snapshot['max_output_tokens']) self::unavailable();
    }

    public function callCount(): int
    {
        return $this->counter?->persistedCallCount() ?? 0;
    }

    public function bindLedger(MeetingSummaryTokenCountLedger $ledger): void
    {
        if (MeetingLocalPlanning::enabled($this->snapshot) || $this->counter !== null) self::unavailable();
        $this->counter = $this->loadCounter($ledger);
    }

    private function loadCounter(?MeetingSummaryTokenCountLedger $ledger = null): BigModelMeetingTokenCounter
    {
        $route = $this->snapshot['route'];
        $provider = Provider::where('id', $route['provider_id'])->where('status', 1)->find();
        $encrypted = $provider?->encrypted_config;
        $encrypted = is_object($encrypted) ? (array) $encrypted : $encrypted;
        if ($provider === null || (string) $provider->adapter !== $route['provider_adapter']
            || !is_array($encrypted)
            || !hash_equals($route['provider_config_sha256'], RequestPayloadFingerprint::fingerprint($encrypted, []))) self::unavailable();
        return new BigModelMeetingTokenCounter((new ProviderSecretDecryptor())->decrypt($encrypted),
            $route['deployment_remote_model'], ledger: $ledger);
    }

    private static function unavailable(): never
    {
        throw new ApiProblem('SAND_AI_AUDIO_SUMMARY_CAPACITY_UNAVAILABLE', '会议摘要尚未配置可核验的模型容量，请管理员为当前发布部署登记官方容量来源和分词接口后重试');
    }
}
