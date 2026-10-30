<?php

declare(strict_types=1);

namespace app\Application\Tool;

use app\Api\Support\ApiProblem;
use app\Application\Budget\BudgetDecimal;
use app\Application\Usage\UsageAttribution;
use app\Infrastructure\Persistence\Model\ToolConnection;
use app\Infrastructure\Persistence\Model\ToolConnectionRevision;
use app\Infrastructure\Persistence\Model\ToolRun;
use app\Infrastructure\Persistence\Model\ToolMcpSnapshot;
use app\Infrastructure\Persistence\Model\ToolProtocolRequest;
use app\Infrastructure\Persistence\Model\ToolVersion;
use app\Infrastructure\Persistence\Model\UsageEvent;
use think\facade\Db;

/** Standard HTTP records before network I/O; MCP seals one immutable aggregate before receipt/finalization. */
final class ToolUsageMeter
{
    /** @return array<string,mixed> */
    public function record(ToolRun $run, ToolVersion $version, ToolConnection $connection, ToolConnectionRevision $revision): array
    {
        return $this->recordQuantity($run, $version, $connection, $revision, 1, 'standard_readonly_http');
    }

    /** @return array<string,mixed> */
    public function recordQuantity(ToolRun $run, ToolVersion $version, ToolConnection $connection, ToolConnectionRevision $revision, int $quantity, string $adapter): array
    {
        if (!in_array($adapter, ['standard_readonly_http', 'mcp_streamable_http'], true)) {
            throw new ApiProblem('SAND_AI_TOOL_USAGE_PERSISTENCE_FAILED', 'External request usage quantity is invalid', true);
        }
        $quantity = $this->runtimeQuantity($run, $quantity, $adapter);
        $existing = UsageEvent::where('tool_run_id', (int) $run->id)->lock(true)->find();
        if ($existing !== null) {
            $this->assertExisting($existing, $run, $version, $connection, $revision, $quantity, $adapter);
            return $this->summary($existing);
        }
        $identity = is_array($run->identity_snapshot) ? $run->identity_snapshot : [];
        $deploymentSnapshot = ['tool_id' => (int) $run->tool_id, 'tool_version_id' => (int) $version->id, 'adapter' => $adapter, 'operation' => 'runtime', 'effect_class' => 'read_only'];
        if ($adapter === 'mcp_streamable_http') $deploymentSnapshot['protocol_version'] = McpStreamableHttpClient::protocolVersionFromWireContract($run->execution_snapshot['wire_contract'] ?? null);
        $rows = Db::connect('pgsql')->query(
            "INSERT INTO sand_ai_usage_event (organization_id, application_id, environment_id, workload_client_id, identity_snapshot, provider_snapshot, model_snapshot, deployment_snapshot, invocation_id, provider_id, model_id, deployment_id, skill_id, skill_version_id, skill_run_id, tool_id, tool_version_id, tool_run_id, tool_connection_id, tool_connection_revision_id, service_action, source_kind, capability_code, metering_unit, metering_quantity, input_tokens, output_tokens, total_tokens, usage_state, price_revision_id, price_snapshot, metered_amount, reconciled_amount, currency, pricing_state, reconciliation_state, idempotency_key, create_time) VALUES (?, ?, ?, ?, CAST(? AS jsonb), CAST(? AS jsonb), CAST(? AS jsonb), CAST(? AS jsonb), NULL, NULL, NULL, NULL, NULL, NULL, NULL, ?, ?, ?, ?, ?, 'sand_ai.tool.run', 'tool', 'external_readonly', 'external_request', ?, 0, 0, 0, 'measured', NULL, CAST(? AS jsonb), NULL, NULL, NULL, 'unpriced', 'unreconciled', ?, ?) RETURNING id",
            [
                $run->organization_id, $run->application_id, (int) $run->environment_id, $run->workload_client_id,
                json_encode($identity, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                json_encode(['kind' => 'managed_external_connection', 'connection_id' => (int) $connection->id, 'connection_revision_id' => (int) $revision->id, 'code' => (string) $connection->code, 'name' => (string) $connection->getAttr('name')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                json_encode([], JSON_THROW_ON_ERROR),
                json_encode($deploymentSnapshot, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                (int) $run->tool_id, (int) $version->id, (int) $run->id, (int) $connection->id, (int) $revision->id, $quantity,
                json_encode(['unit' => 'external_request', 'quantity' => $quantity, 'rate' => null], JSON_THROW_ON_ERROR),
                'tool-run:' . (int) $run->id, gmdate('Y-m-d H:i:s'),
            ],
        );
        $event = UsageEvent::find((int) $rows[0]['id']);
        if ($event === null) throw new ApiProblem('SAND_AI_TOOL_USAGE_PERSISTENCE_FAILED', 'External request usage could not be recorded', true);
        return $this->summary($event);
    }

    /** @return array<string,mixed> */
    public function requireRecordedQuantity(ToolRun $run, ToolVersion $version, ToolConnection $connection, ToolConnectionRevision $revision, int $quantity, string $adapter): array
    {
        if (!in_array($adapter, ['standard_readonly_http', 'mcp_streamable_http'], true)) {
            throw new ApiProblem('SAND_AI_TOOL_USAGE_PERSISTENCE_FAILED', 'External request usage quantity is invalid', true);
        }
        $quantity = $this->runtimeQuantity($run, $quantity, $adapter);
        $existing = UsageEvent::where('tool_run_id', (int) $run->id)->lock(true)->find();
        if ($existing === null) {
            throw new ApiProblem('SAND_AI_TOOL_USAGE_PERSISTENCE_FAILED', 'External request usage evidence is unavailable', true);
        }
        $this->assertExisting($existing, $run, $version, $connection, $revision, $quantity, $adapter);
        return $this->summary($existing);
    }

    public function recordedQuantity(ToolRun $run, ToolVersion $version, ToolConnection $connection, ToolConnectionRevision $revision, string $adapter): ?int
    {
        if (!in_array($adapter, ['standard_readonly_http', 'mcp_streamable_http'], true)) {
            throw new ApiProblem('SAND_AI_TOOL_USAGE_PERSISTENCE_FAILED', 'External request usage adapter is invalid', true);
        }
        $existing = UsageEvent::where('tool_run_id', (int) $run->id)->lock(true)->find();
        if ($existing === null) return null;
        $quantity = (int) $existing->metering_quantity;
        $quantity = $this->runtimeQuantity($run, $quantity, $adapter);
        $this->assertExisting($existing, $run, $version, $connection, $revision, $quantity, $adapter);
        return $quantity;
    }

    public function recordProtocolExchangeEvent(ToolRun $run, string $kind, int $sequence, string $eventKind, ?int $httpStatus): void
    {
        if (!in_array($kind, ['initialize', 'initialized', 'tools_list', 'tools_call'], true) || $sequence < 1 || $sequence > 1024
            || !in_array($eventKind, ['dispatch_started', 'response_known'], true)) {
            throw new ApiProblem('SAND_AI_TOOL_USAGE_PERSISTENCE_FAILED', 'MCP request boundary is invalid', true);
        }
        McpProtocolExchangePolicy::assertRecordable($eventKind, $httpStatus);
        try {
            if ($eventKind === 'dispatch_started' && $sequence !== $this->protocolRequestCount($run) + 1) {
                throw new \RuntimeException('MCP request sequence mismatch');
            }
            if ($eventKind === 'response_known' && ToolProtocolRequest::where('tool_run_id', (int) $run->id)->where('sequence_no', $sequence)->where('event_kind', 'dispatch_started')->find() === null) {
                throw new \RuntimeException('MCP response has no dispatch boundary');
            }
            ToolProtocolRequest::create([
                'tool_run_id' => (int) $run->id, 'mcp_snapshot_id' => null, 'sequence_no' => $sequence,
                'request_kind' => $kind, 'event_kind' => $eventKind, 'http_status' => $httpStatus,
            ]);
        } catch (\Throwable) {
            throw new ApiProblem('SAND_AI_TOOL_USAGE_PERSISTENCE_FAILED', 'MCP request usage could not be recorded', true);
        }
    }

    /** @return array<string,mixed> */
    public function recordDiscovery(ToolMcpSnapshot $snapshot, ToolConnection $connection, ToolConnectionRevision $revision, int $quantity, UsageAttribution $attribution): array
    {
        $organizationRef = $attribution->identitySnapshot['organization_ref'] ?? null;
        $applicationRef = $attribution->identitySnapshot['application_ref'] ?? null;
        if ($attribution->environmentId !== (int) $snapshot->environment_id
            || $attribution->organizationId === null || $attribution->applicationId === null
            || $organizationRef !== (string) $attribution->organizationId
            || $applicationRef !== (string) $attribution->applicationId
            || $attribution->workloadClientId !== null || isset($attribution->identitySnapshot['workload_client_ref'])
            || $attribution->capabilityCode !== 'external_readonly'
            || $attribution->serviceAction !== 'sand_ai.tool.mcp.discover' || $attribution->sourceKind !== 'tool') {
            throw new ApiProblem('SAND_AI_BUDGET_SCOPE_UNVERIFIED', 'Frozen MCP discovery ownership is unavailable for usage attribution');
        }
        $identitySnapshot = $attribution->identitySnapshot + ['actor_type' => 'admin'];
        $protocolVersion = (string) $snapshot->protocol_version;
        McpStreamableHttpClient::assertProtocolVersion($protocolVersion);
        $actualQuantity = (int) ToolProtocolRequest::where('mcp_snapshot_id', (int) $snapshot->id)
            ->where('event_kind', 'dispatch_started')->count();
        if ($actualQuantity < 1 || $actualQuantity > 1024 || $quantity !== $actualQuantity) {
            throw new ApiProblem('SAND_AI_TOOL_USAGE_PERSISTENCE_FAILED', 'MCP discovery request quantity is invalid', true);
        }
        $quantity = $actualQuantity;
        $existing = UsageEvent::where('mcp_snapshot_id', (int) $snapshot->id)->lock(true)->find();
        if ($existing !== null) {
            $existingIdentity = is_array($existing->identity_snapshot) ? $existing->identity_snapshot : [];
            if ((int) $existing->environment_id !== (int) $snapshot->environment_id
                || (int) $existing->organization_id !== $attribution->organizationId
                || (int) $existing->application_id !== $attribution->applicationId
                || $existing->workload_client_id !== null
                || ($existingIdentity['organization_ref'] ?? null) !== $organizationRef
                || ($existingIdentity['application_ref'] ?? null) !== $applicationRef
                || ($existingIdentity['actor_type'] ?? null) !== 'admin'
                || isset($existingIdentity['workload_client_ref'])
                || (string) $existing->capability_code !== 'external_readonly'
                || (string) $existing->service_action !== 'sand_ai.tool.mcp.discover'
                || (string) $existing->pricing_state !== 'unpriced'
                || $existing->metered_amount !== null || $existing->currency !== null
                || $existing->price_revision_id !== null || $existing->model_id !== null
                || (int) $existing->tool_connection_id !== (int) $connection->id
                || (int) $existing->tool_connection_revision_id !== (int) $revision->id
                || !$this->quantityMatches($existing->metering_quantity, $quantity)
                || !is_array($existing->deployment_snapshot) || ($existing->deployment_snapshot['protocol_version'] ?? null) !== $protocolVersion) {
                throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'MCP discovery usage does not match its immutable snapshot');
            }
            return $this->summary($existing);
        }
        $rows = Db::connect('pgsql')->query(
            "INSERT INTO sand_ai_usage_event (organization_id, application_id, environment_id, workload_client_id, identity_snapshot, provider_snapshot, model_snapshot, deployment_snapshot, invocation_id, provider_id, model_id, deployment_id, skill_id, skill_version_id, skill_run_id, tool_id, tool_version_id, tool_run_id, tool_connection_id, tool_connection_revision_id, mcp_snapshot_id, service_action, source_kind, capability_code, metering_unit, metering_quantity, input_tokens, output_tokens, total_tokens, usage_state, price_revision_id, price_snapshot, metered_amount, reconciled_amount, currency, pricing_state, reconciliation_state, idempotency_key, create_time) VALUES (?, ?, ?, NULL, CAST(? AS jsonb), CAST(? AS jsonb), CAST(? AS jsonb), CAST(? AS jsonb), NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, ?, ?, ?, 'sand_ai.tool.mcp.discover', 'tool', 'external_readonly', 'external_request', ?, 0, 0, 0, 'measured', NULL, CAST(? AS jsonb), NULL, NULL, NULL, 'unpriced', 'unreconciled', ?, ?) RETURNING id",
            [
                $attribution->organizationId, $attribution->applicationId, (int) $snapshot->environment_id,
                json_encode($identitySnapshot, JSON_THROW_ON_ERROR),
                json_encode(['kind' => 'managed_external_connection', 'connection_id' => (int) $connection->id, 'connection_revision_id' => (int) $revision->id], JSON_THROW_ON_ERROR),
                json_encode([], JSON_THROW_ON_ERROR),
                json_encode(['adapter' => 'mcp_streamable_http', 'operation' => 'discovery', 'protocol_version' => $protocolVersion], JSON_THROW_ON_ERROR),
                (int) $connection->id, (int) $revision->id, (int) $snapshot->id, $quantity,
                json_encode(['unit' => 'external_request', 'quantity' => $quantity, 'rate' => null], JSON_THROW_ON_ERROR),
                'mcp-discovery:' . (int) $snapshot->id, gmdate('Y-m-d H:i:s'),
            ],
        );
        $event = UsageEvent::find((int) ($rows[0]['id'] ?? 0));
        if ($event === null) throw new ApiProblem('SAND_AI_TOOL_USAGE_PERSISTENCE_FAILED', 'MCP discovery usage could not be recorded', true);
        return $this->summary($event);
    }

    public function protocolRequestCount(ToolRun $run): int
    {
        return (int) ToolProtocolRequest::where('tool_run_id', (int) $run->id)->where('event_kind', 'dispatch_started')->count();
    }

    private function runtimeQuantity(ToolRun $run, int $claimedQuantity, string $adapter): int
    {
        if ($adapter === 'standard_readonly_http') {
            if ($claimedQuantity !== 1) {
                throw new ApiProblem('SAND_AI_TOOL_USAGE_PERSISTENCE_FAILED', 'Standard HTTP usage quantity must equal one', true);
            }
            return 1;
        }
        $actualQuantity = $this->protocolRequestCount($run);
        if ($actualQuantity < 1 || $actualQuantity > 1024 || $claimedQuantity !== $actualQuantity) {
            throw new ApiProblem('SAND_AI_TOOL_USAGE_PERSISTENCE_FAILED', 'MCP usage quantity does not match immutable dispatch evidence', true);
        }
        return $actualQuantity;
    }

    /** @return array{request_count:int,tools_call_dispatched:bool,tools_call_response_known:bool,tools_call_http_status:?int} */
    public function protocolRecoveryEvidence(ToolRun $run): array
    {
        try {
            $events = [];
            foreach (ToolProtocolRequest::where('tool_run_id', (int) $run->id)->order('sequence_no', 'asc')->select() as $event) {
                $events[] = [
                    'tool_run_id' => (int) $event->tool_run_id,
                    'sequence_no' => (int) $event->sequence_no,
                    'request_kind' => (string) $event->request_kind,
                    'event_kind' => (string) $event->event_kind,
                    'http_status' => $event->http_status === null ? null : (int) $event->http_status,
                ];
            }
            return McpProtocolDispatchEvidence::analyze((int) $run->id, $events);
        } catch (ApiProblem $problem) {
            throw $problem;
        } catch (\Throwable) {
            throw new ApiProblem('SAND_AI_TOOL_USAGE_PERSISTENCE_FAILED', 'MCP request recovery evidence is unavailable', true);
        }
    }

    private function assertExisting(UsageEvent $event, ToolRun $run, ToolVersion $version, ToolConnection $connection, ToolConnectionRevision $revision, int $quantity, string $adapter): void
    {
        $expected = [
            'environment_id' => (int) $run->environment_id, 'tool_id' => (int) $run->tool_id,
            'tool_version_id' => (int) $version->id, 'tool_run_id' => (int) $run->id,
            'tool_connection_id' => (int) $connection->id, 'tool_connection_revision_id' => (int) $revision->id,
            'service_action' => 'sand_ai.tool.run', 'source_kind' => 'tool', 'capability_code' => 'external_readonly',
            'metering_unit' => 'external_request', 'metering_quantity' => $quantity, 'pricing_state' => 'unpriced',
        ];
        foreach ($expected as $field => $value) {
            $matches = $field === 'metering_quantity'
                ? $this->quantityMatches($event->getAttr($field), $quantity)
                : (string) $event->getAttr($field) === (string) $value;
            if (!$matches) {
                throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'External request usage attribution does not match the original run');
            }
        }
        $deployment = $event->deployment_snapshot;
        $legacyStandardRuntime = is_array($deployment)
            && $adapter === 'standard_readonly_http'
            && !array_key_exists('operation', $deployment);
        if (!is_array($deployment) || ($deployment['adapter'] ?? null) !== $adapter
            || (($deployment['operation'] ?? null) !== 'runtime' && !$legacyStandardRuntime)
            || (int) ($deployment['tool_id'] ?? 0) !== (int) $run->tool_id
            || (int) ($deployment['tool_version_id'] ?? 0) !== (int) $version->id
            || ($deployment['effect_class'] ?? null) !== 'read_only') {
            throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'External request usage deployment snapshot does not match the original run');
        }
        if ($adapter === 'mcp_streamable_http') {
            $protocolVersion = McpStreamableHttpClient::protocolVersionFromWireContract($run->execution_snapshot['wire_contract'] ?? null);
            // Existing November usage rows predate this explicit field. Never rewrite them.
            $recordedProtocol = array_key_exists('protocol_version', $deployment)
                ? $deployment['protocol_version'] : McpStreamableHttpClient::PROTOCOL_VERSION;
            if ($recordedProtocol !== $protocolVersion) throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'MCP usage protocol differs from its immutable run');
        }
        if ($event->metered_amount !== null || $event->currency !== null || (int) $event->total_tokens !== 0) {
            throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'External request usage pricing state does not match the original run');
        }
    }

    private function quantityMatches(mixed $actual, int $expected): bool
    {
        // PostgreSQL numeric(20,6) returns "1.000000" for a request count
        // of one. Compare its exact value, never an integer truncation.
        if (!is_string($actual) && !is_int($actual)) {
            return false;
        }
        try {
            return BudgetDecimal::normalize((string) $actual, false)
                === BudgetDecimal::normalize((string) $expected, false);
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    /** @return array{unit:string,quantity:int,pricing_state:string,metered_amount:null,reconciled_amount:null,currency:null} */
    public function summary(UsageEvent $event): array
    {
        return ['unit' => 'external_request', 'quantity' => (int) $event->metering_quantity, 'pricing_state' => 'unpriced', 'metered_amount' => null, 'reconciled_amount' => null, 'currency' => null];
    }
}
