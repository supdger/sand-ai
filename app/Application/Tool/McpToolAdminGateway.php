<?php

declare(strict_types=1);

namespace app\Application\Tool;

use app\Api\Support\ApiProblem;
use app\Application\Budget\BudgetPolicyService;
use app\Application\Usage\UsageAttribution;
use app\Api\Support\IdentityContextProblem;
use app\Identity\Contract\EnvironmentReferenceVerifier;
use app\Identity\Contract\IdentityContextException;
use app\Identity\Infrastructure\HostSandIamEnvironmentReferenceVerifier;
use app\Identity\Infrastructure\HostSandIamEnvironmentFacts;
use app\Infrastructure\Persistence\Model\AuditLog;
use app\Infrastructure\Persistence\Model\ToolConnection;
use app\Infrastructure\Persistence\Model\ToolConnectionRevision;
use app\Infrastructure\Persistence\Model\ToolMcpSnapshot;
use app\Infrastructure\Persistence\Model\ToolMcpSnapshotItem;
use app\Infrastructure\Persistence\Model\ToolProtocolRequest;
use think\facade\Db;

final class McpToolAdminGateway
{
    public function __construct(
        private readonly ?EnvironmentReferenceVerifier $environmentReferences = null,
        private readonly ?ToolEndpointVerifier $endpointPolicy = null,
        private readonly ?StandardReadonlyHttpTransport $transport = null,
        private readonly ?ToolConnectionSecretCipher $connectionCipher = null,
        private readonly ?McpReadonlyToolRegistry $registry = null,
        private readonly ?ToolUsageMeter $usageMeter = null,
    ) {
    }

    /** @return array<string,mixed> */
    public function latestTested(int $environmentId, int $connectionId, int $connectionRevisionId): array
    {
        $this->requireEnvironment($environmentId);
        if (min($connectionId, $connectionRevisionId) < 1) $this->invalid();
        $connection = ToolConnection::where('id', $connectionId)
            ->where('environment_id', $environmentId)->where('active_revision_id', $connectionRevisionId)
            ->where('state', 'published')->where('status', 1)->whereNull('delete_time')->find();
        $revision = ToolConnectionRevision::where('id', $connectionRevisionId)
            ->where('tool_connection_id', $connectionId)->where('state', 'published')->find();
        if ($connection === null || $revision === null) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Managed MCP connection not found');
        }
        $snapshot = ToolMcpSnapshot::where('environment_id', $environmentId)
            ->where('tool_connection_id', $connectionId)
            ->where('tool_connection_revision_id', $connectionRevisionId)
            ->where('state', 'tested')->order('id', 'desc')->find();
        if ($snapshot === null) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Managed MCP snapshot not found');
        }
        return $this->summary($snapshot, false);
    }

    /** The same original discovery only; this read never leases, retries, or exchanges MCP messages. */
    public function readOriginal(int $environmentId, string $requestKey, string $targetSha256, string $actor): array
    {
        $this->requireEnvironment($environmentId);
        $key = trim($requestKey);
        if ($key === '' || strlen($key) > 128 || $actor === ''
            || preg_match('/\A[a-f0-9]{64}\z/D', $targetSha256) !== 1) $this->invalid();
        $snapshot = ToolMcpSnapshot::where('environment_id', $environmentId)->where('request_id', $key)->find();
        if ($snapshot === null) {
            return (new ToolAdminGateway($this->environmentReferences))->readOperation(
                'mcp_discovery', $environmentId, $key, $targetSha256, $actor,
            );
        }
        $connectionId = (int) $snapshot->tool_connection_id;
        $revisionId = (int) $snapshot->tool_connection_revision_id;
        $target = ['connection_id' => $connectionId, 'connection_revision_id' => $revisionId];
        if (!hash_equals($targetSha256, hash('sha256', ToolFingerprint::canonicalJson($target)))
            || !hash_equals((string) $snapshot->request_fingerprint,
                $this->originalFingerprint($environmentId, $connectionId, $revisionId, $actor))) {
            // Older fingerprints do not bind a trusted administrator; never guess their owner.
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Original MCP discovery is unavailable');
        }
        $connection = ToolConnection::where('id', $connectionId)->where('environment_id', $environmentId)
            ->whereNull('delete_time')->find();
        $revision = $connection === null ? null : ToolConnectionRevision::where('id', $revisionId)
            ->where('tool_connection_id', $connectionId)->find();
        if ($revision === null) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Original MCP discovery is unavailable');
        if ((string) $snapshot->state !== 'tested') {
            if (in_array((string) $snapshot->state, ['failed', 'ambiguous'], true)) {
                throw ToolRunError::persisted(is_array($snapshot->error_detail) ? $snapshot->error_detail : []);
            }
            throw new ApiProblem('SAND_AI_MCP_DISCOVERY_IN_PROGRESS', 'Original MCP discovery has no confirmed completed receipt', true);
        }
        $result = $this->summary($snapshot, true);
        $this->requireEnvironment($environmentId);
        return ['operation' => 'mcp_discovery', 'environment_id' => $environmentId,
            'request_key' => $key, 'target_sha256' => $targetSha256, 'result' => $result];
    }

    private function originalFingerprint(int $environmentId, int $connectionId, int $connectionRevisionId, string $actor): string
    {
        if ($actor === '' || trim($actor) !== $actor) $this->invalid();
        return ToolFingerprint::for('mcp_discovery', [
            'environment_id' => $environmentId, 'connection_id' => $connectionId,
            'connection_revision_id' => $connectionRevisionId,
            'protocol_version' => McpStreamableHttpClient::PROTOCOL_VERSION,
            'trusted_admin_ref' => $actor,
        ]);
    }

    /** @return array<string,mixed> */
    public function discover(int $environmentId, int $connectionId, int $connectionRevisionId, string $idempotencyKey, string $actor): array
    {
        $this->requireEnvironment($environmentId);
        if (min($environmentId, $connectionId, $connectionRevisionId) < 1) $this->invalid();
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 128) $this->invalid();
        $fingerprint = $this->originalFingerprint($environmentId, $connectionId, $connectionRevisionId, $actor);
        $claim = Db::connect('pgsql')->transaction(function () use ($environmentId, $connectionId, $connectionRevisionId, $idempotencyKey, $fingerprint, $actor): array {
            $this->lock('mcp-discovery:' . $environmentId . ':' . $idempotencyKey);
            $receipts = new ToolAdminGateway($this->environmentReferences);
            // This same-key lock also protects a definitive rejection before any protocol dispatch.
            if ($receipts->operationReplay('environment:' . $environmentId, 'mcp_discovery', $idempotencyKey, $fingerprint, $actor) !== null) {
                throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'MCP discovery receipt has an invalid completion kind');
            }
            $existing = ToolMcpSnapshot::where('environment_id', $environmentId)->where('request_id', $idempotencyKey)->lock(true)->find();
            if ($existing !== null) {
                if (!hash_equals((string) $existing->request_fingerprint, $fingerprint)) {
                    throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'MCP discovery idempotency key belongs to another request');
                }
                if ((string) $existing->state === 'tested') return ['replay' => true, 'snapshot' => $existing];
                if ((string) $existing->state === 'discovering') {
                    if (!$this->expired($existing->lease_expires_at)) throw new ApiProblem('SAND_AI_MCP_DISCOVERY_IN_PROGRESS', 'MCP discovery is still in progress', true);
                    $lease = $this->lease((int) $existing->lease_fence + 1);
                    $existing->save($lease);
                    $connection = ToolConnection::where('id', $connectionId)->where('environment_id', $environmentId)->where('state', 'published')->where('status', 1)->lock(true)->find();
                    $revision = ToolConnectionRevision::where('id', $connectionRevisionId)->where('tool_connection_id', $connectionId)->where('state', 'published')->lock(true)->find();
                    if ($connection === null || $revision === null) throw new ApiProblem('SAND_AI_TOOL_CONFIGURATION_DRIFTED', 'Managed MCP connection revision is unavailable');
                    if ($this->requestCount($existing) > 0) return ['recover_ambiguous' => true, 'snapshot' => $existing, 'connection' => $connection, 'revision' => $revision, 'lease' => $lease];
                    return ['snapshot' => $existing, 'connection' => $connection, 'revision' => $revision, 'lease' => $lease, 'recovered' => true];
                }
                throw ToolRunError::persisted(is_array($existing->error_detail) ? $existing->error_detail : []);
            }
            $connection = ToolConnection::where('id', $connectionId)->where('environment_id', $environmentId)->where('state', 'published')->where('status', 1)->lock(true)->find();
            $revision = ToolConnectionRevision::where('id', $connectionRevisionId)->where('tool_connection_id', $connectionId)->where('state', 'published')->lock(true)->find();
            if ($connection === null || $revision === null) {
                // The locked original key has neither a snapshot nor any outbound protocol request.
                $receipts->recordRejectedMcpClaim($environmentId, $idempotencyKey, $fingerprint, $actor, [
                    'connection_id' => $connectionId, 'connection_revision_id' => $connectionRevisionId,
                ]);
                return ['rejected' => true];
            }
            $lease = $this->lease(1);
            $snapshot = ToolMcpSnapshot::create([
                'environment_id' => $environmentId,
                'tool_connection_id' => $connectionId,
                'tool_connection_revision_id' => $connectionRevisionId,
                'request_id' => $idempotencyKey,
                'request_fingerprint' => $fingerprint,
                'protocol_version' => McpStreamableHttpClient::PROTOCOL_VERSION,
                'state' => 'discovering', ...$lease,
            ]);
            return compact('snapshot', 'connection', 'revision', 'lease');
        });
        if (($claim['rejected'] ?? false) === true) throw ToolRunError::problem('SAND_AI_TOOL_CONFIGURATION_DRIFTED');
        if (($claim['replay'] ?? false) === true) return $this->summary($claim['snapshot'], true);

        /** @var ToolMcpSnapshot $snapshot */
        $snapshot = $claim['snapshot'];
        /** @var ToolConnectionRevision $revision */
        $revision = $claim['revision'];
        $lease = $claim['lease'];
        if (($claim['recover_ambiguous'] ?? false) === true) {
            $this->fail($snapshot, $lease, 'SAND_AI_TOOL_DISPATCH_AMBIGUOUS', true, $actor);
            throw ToolRunError::problem('SAND_AI_TOOL_DISPATCH_AMBIGUOUS');
        }
        try {
            $attribution = $this->discoveryAttribution((int) $snapshot->environment_id);
            $config = (new ToolConnectionConfigLoader($this->connectionCipher))->load($revision);
            $endpoint = ($this->endpointPolicy ?? new ToolEndpointPolicy())->verify($config['origin'], $config['path']);
            $headers = [];
            if ($config['authorization']['mode'] === 'bearer') $headers['Authorization'] = 'Bearer ' . $config['authorization']['credential'];
            $client = new McpStreamableHttpClient(
                $this->transport ?? new GuzzleStandardReadonlyHttpTransport(),
                (int) config('sand_ai_mcp.max_pages', 20),
                (int) config('sand_ai_mcp.max_tools', 500),
                (int) config('sand_ai_mcp.max_schema_bytes', 524288),
            );
            $discovered = $client->discover(
                $endpoint,
                $headers,
                10000,
                1048576,
                function (string $kind, int $sequence, string $eventKind, ?int $httpStatus) use ($snapshot, $lease, $attribution, $actor): void {
                    $this->recordExchangeEvent($snapshot, $lease, $kind, $sequence, $eventKind, $httpStatus, $attribution, $actor);
                },
                function (string $protocolVersion) use ($snapshot, $lease): void {
                    Db::connect('pgsql')->transaction(function () use ($snapshot, $lease, $protocolVersion): void {
                        $locked = ToolMcpSnapshot::where('id', (int) $snapshot->id)->where('state', 'discovering')->lock(true)->find();
                        if ($locked === null || !$this->owns($locked, $lease)) throw new ApiProblem('SAND_AI_MCP_DISCOVERY_IN_PROGRESS', 'MCP discovery lease changed during initialization');
                        $locked->save(['protocol_version' => $protocolVersion]);
                    });
                },
            );
            $canonical = McpSnapshotPolicy::build($discovered['tools'], $this->registry ?? new McpReadonlyToolRegistry(), $discovered['protocol_version']);
        } catch (ApiProblem $problem) {
            $this->fail($snapshot, $lease, $problem->errorCode, $this->ambiguous($problem->errorCode), $actor);
            throw ToolRunError::problem($problem->errorCode);
        } catch (\Throwable) {
            $this->fail($snapshot, $lease, 'SAND_AI_TOOL_DISPATCH_AMBIGUOUS', true, $actor);
            throw ToolRunError::problem('SAND_AI_TOOL_DISPATCH_AMBIGUOUS');
        }

        Db::connect('pgsql')->transaction(function () use ($snapshot, $discovered, $canonical, $actor, $lease, $revision): void {
            $locked = ToolMcpSnapshot::where('id', (int) $snapshot->id)->where('state', 'discovering')->lock(true)->find();
            if ($locked === null || !$this->owns($locked, $lease)) throw new ApiProblem('SAND_AI_MCP_DISCOVERY_IN_PROGRESS', 'MCP discovery state changed before publication');
            $count = $this->requestCount($locked);
            if ($count !== (int) $discovered['request_count']) throw new ApiProblem('SAND_AI_TOOL_USAGE_PERSISTENCE_FAILED', 'MCP discovery request count is inconsistent', true);
            foreach ($canonical['items'] as $sequence => $item) {
                ToolMcpSnapshotItem::create([
                    'mcp_snapshot_id' => (int) $locked->id,
                    'sequence_no' => $sequence + 1,
                    ...$item,
                ]);
            }
            $connection = ToolConnection::where('id', (int) $locked->tool_connection_id)->find();
            if ($connection === null) throw new ApiProblem('SAND_AI_TOOL_CONFIGURATION_DRIFTED', 'Managed MCP connection is unavailable');
            $metering = ($this->usageMeter ?? new ToolUsageMeter())->recordDiscovery($locked, $connection, $revision, $count, $this->frozenDiscoveryAttribution($locked));
            $locked->save([
                'protocol_version' => $discovered['protocol_version'],
                'server_name' => $discovered['server_name'],
                'server_version' => $discovered['server_version'],
                'snapshot_hash' => $canonical['snapshot_hash'],
                'item_count' => count($canonical['items']),
                'request_count' => $count,
                'metering' => $metering,
                'state' => 'tested',
                'lease_expires_at' => null,
                'completed_time' => gmdate('Y-m-d H:i:s'),
            ]);
            AuditLog::create([
                'actor_type' => 'admin', 'actor_ref' => $actor, 'action' => 'sand_ai.tool.mcp.discover',
                'resource_type' => 'tool_mcp_snapshot', 'resource_id' => (int) $locked->id,
                'summary' => 'SandAI managed MCP catalog snapshot tested',
                'context' => ['environment_id' => (int) $locked->environment_id, 'connection_id' => (int) $locked->tool_connection_id, 'request_count' => $count, 'protocol_version' => $discovered['protocol_version']],
            ]);
        });
        $terminal = ToolMcpSnapshot::find((int) $snapshot->id);
        if ($terminal === null) throw new ApiProblem('SAND_AI_MCP_DISCOVERY_INVALID', 'MCP discovery result is unavailable');
        return $this->summary($terminal, false);
    }

    private function recordExchangeEvent(ToolMcpSnapshot $snapshot, array $lease, string $kind, int $sequence, string $eventKind, ?int $httpStatus, UsageAttribution $attribution, string $actor): void
    {
        Db::connect('pgsql')->transaction(function () use ($snapshot, $lease, $kind, $sequence, $eventKind, $httpStatus, $attribution, $actor): void {
            if ($eventKind === 'dispatch_started') {
                (new BudgetPolicyService())->assertUnpricedCallAllowed($attribution, 'external_readonly', gmdate('Y-m-d H:i:s'));
            }
            $locked = ToolMcpSnapshot::where('id', (int) $snapshot->id)->where('state', 'discovering')->lock(true)->find();
            if ($locked === null || !$this->owns($locked, $lease)) throw new ApiProblem('SAND_AI_MCP_DISCOVERY_IN_PROGRESS', 'MCP discovery no longer owns its request boundary');
            if (!in_array($kind, ['initialize', 'initialized', 'tools_list'], true) || $sequence < 1 || $sequence > 1024
                || !in_array($eventKind, ['dispatch_started', 'response_known'], true)) {
                throw new ApiProblem('SAND_AI_TOOL_USAGE_PERSISTENCE_FAILED', 'MCP discovery request boundary is invalid', true);
            }
            McpProtocolExchangePolicy::assertRecordable($eventKind, $httpStatus);
            if ($eventKind === 'dispatch_started' && $sequence !== $this->requestCount($locked) + 1) {
                throw new ApiProblem('SAND_AI_TOOL_USAGE_PERSISTENCE_FAILED', 'MCP discovery request sequence is inconsistent', true);
            }
            if ($eventKind === 'response_known' && ToolProtocolRequest::where('mcp_snapshot_id', (int) $locked->id)->where('sequence_no', $sequence)->where('event_kind', 'dispatch_started')->find() === null) {
                throw new ApiProblem('SAND_AI_TOOL_USAGE_PERSISTENCE_FAILED', 'MCP discovery response has no dispatch boundary', true);
            }
            if ($eventKind === 'dispatch_started' && $sequence === 1) {
                AuditLog::create([
                    'actor_type' => 'admin', 'actor_ref' => $actor, 'action' => 'sand_ai.tool.mcp.discover_dispatch',
                    'resource_type' => 'tool_mcp_snapshot', 'resource_id' => (int) $locked->id,
                    'summary' => 'SandAI MCP discovery identity frozen before external dispatch',
                    'context' => ['attribution' => $attribution->toPersisted(), 'service_action' => 'sand_ai.tool.mcp.discover'],
                ]);
            }
            ToolProtocolRequest::create([
                'tool_run_id' => null, 'mcp_snapshot_id' => (int) $locked->id, 'sequence_no' => $sequence,
                'request_kind' => $kind, 'event_kind' => $eventKind, 'http_status' => $httpStatus,
            ]);
        });
    }

    private function discoveryAttribution(int $environmentId): UsageAttribution
    {
        try {
            $facts = (new HostSandIamEnvironmentFacts())->resolve($environmentId);
        } catch (IdentityContextException $exception) {
            throw new ApiProblem('SAND_AI_BUDGET_SCOPE_UNVERIFIED', 'MCP discovery environment ownership cannot be verified for budget checking');
        }
        return new UsageAttribution(
            $facts['environment_id'], $facts['organization_id'], $facts['application_id'], null,
            ['organization_ref' => (string) $facts['organization_id'], 'application_ref' => (string) $facts['application_id']],
            'tool', 'sand_ai.tool.mcp.discover', 'external_readonly',
        );
    }

    private function frozenDiscoveryAttribution(ToolMcpSnapshot $snapshot): UsageAttribution
    {
        $audit = AuditLog::where('action', 'sand_ai.tool.mcp.discover_dispatch')
            ->where('resource_type', 'tool_mcp_snapshot')->where('resource_id', (int) $snapshot->id)->order('id', 'asc')->find();
        try {
            if ($audit === null || (string) $audit->actor_type !== 'admin') {
                throw new \InvalidArgumentException('Frozen discovery attribution is missing');
            }
            $context = json_decode(json_encode($audit->context, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
            $persisted = is_array($context) && is_array($context['attribution'] ?? null) ? $context['attribution'] : null;
            if ($persisted === null || ($persisted['format_version'] ?? null) !== UsageAttribution::PERSISTED_FORMAT_VERSION) {
                throw new \InvalidArgumentException('Frozen discovery attribution format is invalid');
            }
            return UsageAttribution::fromPersisted($persisted, (int) $snapshot->environment_id, 'tool', 'sand_ai.tool.mcp.discover');
        } catch (\InvalidArgumentException|\JsonException $exception) {
            throw new ApiProblem('SAND_AI_BUDGET_SCOPE_UNVERIFIED', 'The original MCP discovery ownership cannot be verified for usage attribution');
        }
    }

    private function fail(ToolMcpSnapshot $snapshot, array $lease, string $errorCode, bool $ambiguous, string $actor): void
    {
        Db::connect('pgsql')->transaction(function () use ($snapshot, $lease, $errorCode, $ambiguous, $actor): void {
            $locked = ToolMcpSnapshot::where('id', (int) $snapshot->id)->where('state', 'discovering')->lock(true)->find();
            if ($locked === null || !$this->owns($locked, $lease)) return;
            $count = $this->requestCount($locked);
            $metering = $count > 0 ? ($this->usageMeter ?? new ToolUsageMeter())->recordDiscovery(
                $locked,
                ToolConnection::find((int) $locked->tool_connection_id) ?? throw new ApiProblem('SAND_AI_TOOL_CONFIGURATION_DRIFTED', 'Managed MCP connection is unavailable'),
                ToolConnectionRevision::find((int) $locked->tool_connection_revision_id) ?? throw new ApiProblem('SAND_AI_TOOL_CONFIGURATION_DRIFTED', 'Managed MCP connection revision is unavailable'),
                $count, $this->frozenDiscoveryAttribution($locked),
            ) : null;
            $locked->save([
                'request_count' => $count,
                'metering' => $metering,
                'state' => $ambiguous ? 'ambiguous' : 'failed',
                'error_code' => $errorCode,
                'error_detail' => ToolRunError::fromCode($errorCode),
                'lease_expires_at' => null,
                'completed_time' => gmdate('Y-m-d H:i:s'),
            ]);
            AuditLog::create([
                'actor_type' => 'admin', 'actor_ref' => $actor, 'action' => 'sand_ai.tool.mcp.discover_failed',
                'resource_type' => 'tool_mcp_snapshot', 'resource_id' => (int) $locked->id,
                'summary' => 'SandAI managed MCP discovery stopped without retry',
                'context' => ['request_count' => $count, 'error_code' => $errorCode],
            ]);
        });
    }

    /** @return array<string,mixed> */
    private function summary(ToolMcpSnapshot $snapshot, bool $replay): array
    {
        $registry = $this->registry ?? new McpReadonlyToolRegistry();
        $tools = [];
        $frozenItems = [];
        foreach (ToolMcpSnapshotItem::where('mcp_snapshot_id', (int) $snapshot->id)->order('sequence_no', 'asc')->select() as $item) {
            $definition = $registry->require((string) $item->local_tool_code);
            $frozenItems[] = $this->snapshotItem($item);
            $tools[] = [
                'snapshot_item_id' => (int) $item->id,
                'code' => (string) $item->local_tool_code,
                'name' => (string) $definition['name'],
                'remote_name' => (string) $item->remote_name,
            ];
        }
        if ((string) $snapshot->state === 'tested') {
            if ((int) $snapshot->item_count !== count($frozenItems)) throw new ApiProblem('SAND_AI_MCP_SCHEMA_DRIFTED', 'Managed MCP snapshot item count changed');
            McpSnapshotPolicy::assertSnapshot((string) $snapshot->snapshot_hash, $frozenItems, $registry, (string) $snapshot->protocol_version);
        }
        return [
            'snapshot_id' => (int) $snapshot->id,
            'state' => (string) $snapshot->state,
            'protocol_version' => (string) $snapshot->protocol_version,
            'connection_id' => (int) $snapshot->tool_connection_id,
            'connection_revision_id' => (int) $snapshot->tool_connection_revision_id,
            'server' => ['name' => $snapshot->server_name, 'version' => $snapshot->server_version],
            'tools' => $tools,
            'metering' => $snapshot->metering,
            'idempotent_replay' => $replay,
        ];
    }

    private function requestCount(ToolMcpSnapshot $snapshot): int
    {
        return (int) ToolProtocolRequest::where('mcp_snapshot_id', (int) $snapshot->id)->where('event_kind', 'dispatch_started')->count();
    }

    private function ambiguous(string $errorCode): bool
    {
        return in_array($errorCode, ['SAND_AI_TOOL_DISPATCH_AMBIGUOUS', 'SAND_AI_TOOL_TIMEOUT', 'SAND_AI_TOOL_USAGE_PERSISTENCE_FAILED'], true);
    }

    private function requireEnvironment(int $environmentId): void
    {
        try {
            ($this->environmentReferences ?? new HostSandIamEnvironmentReferenceVerifier())->requireEnvironment($environmentId);
        } catch (IdentityContextException $exception) {
            throw IdentityContextProblem::from($exception);
        }
    }

    private function invalid(): never
    {
        throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Managed MCP discovery request is invalid');
    }

    /** @return array<string,mixed> */
    private function snapshotItem(ToolMcpSnapshotItem $item): array
    {
        return [
            'local_tool_code' => (string) $item->local_tool_code,
            'remote_name' => (string) $item->remote_name,
            'remote_description' => (string) $item->remote_description,
            'remote_input_schema' => (array) $item->remote_input_schema,
            'remote_output_schema' => $item->remote_output_schema === null ? null : (array) $item->remote_output_schema,
            'input_schema' => (array) $item->input_schema,
            'output_schema' => (array) $item->output_schema,
            'citation_mode' => (string) $item->citation_mode,
            'registry_definition_hash' => (string) $item->registry_definition_hash,
            'item_hash' => (string) $item->item_hash,
        ];
    }

    /** @return array{lease_token:string,lease_fence:int,lease_expires_at:string} */
    private function lease(int $fence): array
    {
        return ['lease_token' => bin2hex(random_bytes(24)), 'lease_fence' => $fence, 'lease_expires_at' => gmdate('Y-m-d H:i:s', time() + 330)];
    }

    /** @param array<string,mixed> $lease */
    private function owns(ToolMcpSnapshot $snapshot, array $lease): bool
    {
        return is_string($snapshot->lease_token) && hash_equals($snapshot->lease_token, (string) $lease['lease_token'])
            && (int) $snapshot->lease_fence === (int) $lease['lease_fence'];
    }

    private function expired(mixed $expiresAt): bool
    {
        return !is_string($expiresAt) || $expiresAt <= gmdate('Y-m-d H:i:s');
    }

    private function lock(string $scope): void
    {
        Db::connect('pgsql')->query('SELECT pg_advisory_xact_lock(hashtext(?))', [$scope]);
    }
}
