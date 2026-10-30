<?php

declare(strict_types=1);

namespace app\Application\Audio;

use app\Api\Support\ApiProblem;
use app\Infrastructure\Persistence\Model\TaskRun;
use think\facade\Db;

/** Schema readiness and frozen task activation are separate from mutable settings. */
final class MeetingExecutionRuntime
{
    public const VERSION = '202610040124_meeting_execution_registration';
    public const CAPACITY_VERSION = 'meeting_capacity@2';

    public static function installed(): bool
    {
        $exists = Db::connect('pgsql')->query("SELECT to_regclass('sand_ai_meeting_execution_registration') AS relation");
        return count($exists) === 1 && $exists[0]['relation'] !== null;
    }

    /** Global queue support; per-task guards still distinguish historical @1. */
    public static function available(): bool
    {
        return config('sand_ai_meeting_summary.durable_worker.enabled', false) === true || self::installed();
    }

    /** @param array<string,mixed> $input */
    public static function forInput(array $input): bool
    {
        if (($input['_meeting_capacity']['version'] ?? null) === self::CAPACITY_VERSION) {
            MeetingSummaryCapacity::assert($input['_meeting_capacity']);
            return true;
        }
        return config('sand_ai_meeting_summary.durable_worker.enabled', false) === true;
    }

    public static function forTask(TaskRun $task): bool
    {
        $input = $task->input;
        $input = is_object($input) ? (array) $input : (is_array($input) ? $input : []);
        if (($input['_meeting_capacity']['version'] ?? null) === self::CAPACITY_VERSION) {
            $proof = $input['_meeting_capacity']['registration'] ?? null;
            if (!is_array($proof) || ($proof['environment_id'] ?? null) !== (int) $task->environment_id
                || ($proof['organization_id'] ?? null) !== (int) $task->organization_id
                || ($proof['application_id'] ?? null) !== (int) $task->application_id) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', '会议执行登记与任务归属不一致');
            }
        }
        return self::forInput($input);
    }

    public static function forTaskId(int $id): bool
    {
        $task = TaskRun::where('id', $id)->find();
        return $task !== null && self::forTask($task);
    }

    public static function assertSchemaReady(): void
    {
        if (!self::installed()) self::unavailable();
        foreach ([
            self::VERSION,
            '202610020102_meeting_token_count_ledger',
            '202610030111_meeting_durable_plan',
            '202610030114_meeting_plan_source_partition',
            '202610030121_meeting_terminal_step_alias',
        ] as $version) {
            $path = base_path('plugin/sand-ai/migrations/main-app/' . $version . '.pgsql');
            $sha = is_file($path) ? hash_file('sha256', $path) : false;
            $rows = Db::connect('pgsql')->query('SELECT checksum FROM sand_ai_schema_migration WHERE version=?', [$version]);
            if (!is_string($sha) || count($rows) !== 1 || !hash_equals($sha, (string) $rows[0]['checksum'])) self::unavailable();
        }
        // Preserve the worker's exact checks for its foundation, leaf materialization,
        // terminal migration and all execution triggers; readiness never creates schema.
        try {
            (new PostgresMeetingWorkerLedger())->assertReady(false);
            (new PostgresMeetingPlanStore())->assertSchemaReady();
        } catch (ApiProblem) {
            // Administration has a stable readiness refusal; task proof errors
            // retain their original codes in the actual execution path.
            self::unavailable();
        }
    }

    private static function unavailable(): never
    {
        throw new ApiProblem('SAND_AI_MEETING_WORKER_UNAVAILABLE', '会议执行结构尚未完整安装，请先通过正常安装或升级完成迁移，再启用会议执行设置');
    }
}
