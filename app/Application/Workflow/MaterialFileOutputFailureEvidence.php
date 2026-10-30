<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use app\Api\Support\ApiProblem;
use think\facade\Db;

/** Reads the append-only proof for a metered response rejected by output validation. */
final class MaterialFileOutputFailureEvidence
{
    public static function schemaAvailable(): bool
    {
        $rows = Db::connect('pgsql')->query(
            "SELECT CASE WHEN to_regclass('sand_ai_workflow_file_output_failure') IS NOT NULL "
            . "AND to_regclass('sand_ai_schema_migration') IS NOT NULL "
            . "AND (SELECT count(*) FROM pg_attribute WHERE attrelid = to_regclass('sand_ai_workflow_file_output_failure') "
            . "AND NOT attisdropped AND attname = ANY (ARRAY['id','workflow_run_id','workflow_run_file_id',"
            . "'task_run_id','attempt_no','invocation_id','dispatch_id','receipt_id','usage_event_id',"
            . "'execution_snapshot_sha256','error_code','create_time'])) = 12 "
            . "AND (SELECT count(*) FROM pg_constraint WHERE conrelid = to_regclass('sand_ai_workflow_file_output_failure') "
            . "AND contype = 'f' AND convalidated) = 7 "
            . "AND (SELECT count(*) FROM pg_constraint WHERE conrelid = to_regclass('sand_ai_workflow_file_output_failure') "
            . "AND contype = 'u' AND convalidated AND conname = ANY (ARRAY["
            . "'sand_ai_workflow_file_output_failure_attempt_uq','sand_ai_workflow_file_output_failure_invocation_uq',"
            . "'sand_ai_workflow_file_output_failure_dispatch_uq','sand_ai_workflow_file_output_failure_receipt_uq',"
            . "'sand_ai_workflow_file_output_failure_usage_uq'])) = 5 "
            . "AND EXISTS (SELECT 1 FROM pg_trigger WHERE tgrelid = to_regclass('sand_ai_workflow_file_output_failure') "
            . "AND tgname = 'sand_ai_workflow_file_output_failure_no_mutation' AND NOT tgisinternal "
            . "AND tgenabled IN ('O','A') AND tgtype = 27 "
            . "AND tgfoid = to_regprocedure('sand_ai_workflow_file_output_failure_immutable()')) "
            . "THEN 1 ELSE 0 END AS available",
        );
        if (!is_array($rows) || !isset($rows[0]) || !is_array($rows[0])
            || (int) ($rows[0]['available'] ?? 0) !== 1) {
            return false;
        }
        return Db::connect('pgsql')->table('sand_ai_schema_migration')
            ->where('version', '202610020101_material_output_failure_evidence')
            ->where('checksum', '87052b0c056f1842f5d1f0eb589d35d88f380bfc703960d3f03f8099e72da8ed')
            ->count() === 1;
    }

    public static function assertSchemaAvailable(): void
    {
        if (!self::schemaAvailable()) {
            throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'Material output failure evidence schema is not installed');
        }
    }

    /** @return array<int,array<string,mixed>> */
    public static function byDispatchIds(array $dispatchIds): array
    {
        $ids = array_values(array_unique(array_filter($dispatchIds, static fn (mixed $id): bool => is_int($id) && $id > 0)));
        if ($ids === []) {
            return [];
        }
        self::assertSchemaAvailable();
        $found = [];
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = Db::connect('pgsql')->query(
            'SELECT evidence.* FROM sand_ai_workflow_file_output_failure evidence '
            . 'JOIN sand_ai_invocation_outcome_receipt receipt '
            . 'ON receipt.id=evidence.receipt_id AND receipt.invocation_id=evidence.invocation_id '
            . 'JOIN sand_ai_usage_event usage_event '
            . 'ON usage_event.id=evidence.usage_event_id AND usage_event.invocation_id=evidence.invocation_id '
            . 'WHERE evidence.dispatch_id IN (' . $placeholders . ')',
            $ids,
        );
        foreach ($rows as $facts) {
            if (is_array($facts)
                && preg_match('/\A[1-9][0-9]*\z/D', (string) ($facts['dispatch_id'] ?? '')) === 1) {
                $found[(int) $facts['dispatch_id']] = $facts;
            }
        }
        return $found;
    }

    public static function matches(
        ?array $evidence, int $runId, int $fileId, int $taskId, int $attemptNo,
        int $invocationId, int $dispatchId, string $snapshotSha256,
    ): bool {
        return is_array($evidence)
            && (int) ($evidence['workflow_run_id'] ?? 0) === $runId
            && (int) ($evidence['workflow_run_file_id'] ?? 0) === $fileId
            && (int) ($evidence['task_run_id'] ?? 0) === $taskId
            && (int) ($evidence['attempt_no'] ?? 0) === $attemptNo
            && (int) ($evidence['invocation_id'] ?? 0) === $invocationId
            && (int) ($evidence['dispatch_id'] ?? 0) === $dispatchId
            && is_string($evidence['execution_snapshot_sha256'] ?? null)
            && hash_equals($snapshotSha256, $evidence['execution_snapshot_sha256'])
            && in_array($evidence['error_code'] ?? null, [
                'SAND_AI_SCHEMA_UNSUPPORTED', 'SAND_AI_ARTIFACT_PROVENANCE_INVALID',
            ], true);
    }
}
