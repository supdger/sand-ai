<?php

declare(strict_types=1);

namespace app\Application\File;

use app\Api\Support\ApiProblem;
use app\Application\Task\TaskExecutionLease;
use app\Application\Task\TaskGateway;
use app\Application\Task\TaskState;
use app\Application\Usage\UsageAttribution;
use app\Identity\Infrastructure\FileParseAccessGuard;
use app\Infrastructure\Parsing\ParseDriver;
use app\Infrastructure\Persistence\Model\AiFileParse;
use app\Infrastructure\Persistence\Model\TaskRun;
use think\facade\Db;

/** One immutable request event per OCR send boundary, including failed attempts. */
final class OcrUsageLedger
{
    /**
     * Called inside the file deletion transaction before clearing source content.
     * A canceled parse may still have an OCR request with an unknown outcome.
     */
    public function assertSettledForFile(int $environmentId, int $fileId): void
    {
        $this->assertInstalled();
        $db = Db::connect('pgsql');
        try {
            $tasks = $db->query(
                "SELECT id,state,file_parse_id FROM sand_ai_task_run
                 WHERE environment_id=? AND task_type='file_parse'
                   AND resource_type='file' AND resource_id=?
                 ORDER BY id FOR UPDATE NOWAIT",
                [$environmentId, $fileId],
            );
        } catch (\think\db\exception\PDOException $failure) {
            if (($failure->getData()['PDO Error Info']['SQLSTATE'] ?? null) !== '55P03') {
                throw $failure;
            }
            throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'File parsing is changing during file deletion');
        }
        foreach ($tasks as $task) {
            if (!TaskState::terminal((string) $task['state'])) {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Finish or cancel file parsing before deleting the file');
            }
        }
        // OCR preparation takes the same parent lock and requires a running
        // lease. The terminal locks above fence any late preparation until the
        // file purge commits; terminal task state alone cannot settle a send.
        $unsettled = $db->query(
            "SELECT 1 FROM sand_ai_ocr_dispatch dispatch
             LEFT JOIN sand_ai_ocr_dispatch_outcome outcome
               ON outcome.ocr_dispatch_id=dispatch.id
             LEFT JOIN sand_ai_task_run task ON task.id=dispatch.task_run_id
             WHERE dispatch.file_id=?
               AND (dispatch.environment_id<>?
                 OR task.id IS NULL OR task.environment_id<>dispatch.environment_id
                 OR task.task_type<>'file_parse' OR task.resource_type<>'file'
                 OR task.resource_id<>dispatch.file_id
                 OR task.file_parse_id IS DISTINCT FROM dispatch.file_parse_id
                 OR task.state NOT IN ('succeeded','failed','canceled')
                 OR outcome.state IS DISTINCT FROM 'response_known')
             LIMIT 1",
            [$fileId, $environmentId],
        );
        if ($unsettled !== []) {
            throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Reconcile OCR dispatch outcomes before deleting their source file');
        }
    }

    public function start(TaskRun $task, TaskExecutionLease $lease, AiFileParse $parse, ParseDriver $driver, ?array $expectedRoute): int
    {
        $this->assertInstalled();
        $db = Db::connect('pgsql');
        $this->assertCommittedBoundary($db);
        $id = $db->transaction(function () use ($db, $task, $lease, $parse, $driver, $expectedRoute): int {
            (new \app\Application\Capability\EnvironmentCapabilityLock())->acquire($db, (int) $task->environment_id);
            $currentRoute = (new \app\Application\Capability\CapabilityProfileResolver())->primary((int) $task->environment_id, 'image_ocr');
            if ($currentRoute !== $expectedRoute || ($currentRoute !== null && ($currentRoute['driver_code'] ?? null) !== $driver->code())) {
                throw new ApiProblem('SAND_AI_CONFIGURATION_DRIFTED', 'The OCR route changed before dispatch');
            }
            $target = $currentRoute === null || !isset($currentRoute['deployment_id']) ? null
                : LocalOcrDeploymentPolicy::publishedTarget((int) $currentRoute['deployment_id'], true);
            if ($target !== null) {
                $target['deployment_snapshot']['route'] = ['profile_id' => $currentRoute['profile_id'],
                    'profile_revision' => $currentRoute['profile_revision'], 'route_id' => $currentRoute['route_id']];
            }
            $taskInput = is_object($task->input) ? (array) $task->input : $task->input;
            try {
                $preparedAttribution = UsageAttribution::fromCurrentPersisted(
                    is_array($taskInput) && is_array($taskInput['_usage_attribution'] ?? null) ? $taskInput['_usage_attribution'] : null,
                    (int) $task->environment_id, 'capability', 'sand_ai.document_parse')->withCapability('image_ocr');
                $preparedAttribution->canonicalTrustedIdentity();
            } catch (\InvalidArgumentException) {
                throw new ApiProblem('SAND_AI_BUDGET_SCOPE_UNVERIFIED', 'OCR budget attribution is unavailable');
            }
            $calledAt = gmdate('Y-m-d H:i:s');
            $price = OcrCallStartPrice::resolve($target, $calledAt);
            $budget = new OcrBudgetGuard();
            $preparedBudget = $budget->prepare($preparedAttribution, $target['model_id'] ?? 0, $price, $calledAt);
            (new TaskGateway())->assertRunningLease($lease);
            $current = TaskRun::where('id', $lease->taskId)->lock(true)->find();
            $currentParse = AiFileParse::where('id', (int) $parse->id)->lock(true)->find();
            if ($current === null || $currentParse === null || (string) $current->task_type !== 'file_parse'
                || (int) $current->file_parse_id !== (int) $currentParse->id
                || (int) $current->resource_id !== (int) $currentParse->file_id
                || (int) $current->attempt_count !== (int) $task->attempt_count
                || (string) $currentParse->state !== 'running'
                || (string) $currentParse->parser_code !== $driver->code()
                || (string) $currentParse->parser_version !== $driver->version()) {
                throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'OCR dispatch ownership changed');
            }
            $input = is_object($current->input) ? (array) $current->input : $current->input;
            $authorization = is_array($input) ? ($input['iam_authorization'] ?? null) : null;
            if (!is_array($authorization)) throw new ApiProblem('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE', 'OCR dispatch authorization is unavailable');
            (new FileParseAccessGuard())->revalidateQueued($authorization, $current);
            try {
                $attribution = UsageAttribution::fromCurrentPersisted(
                    is_array($input['_usage_attribution'] ?? null) ? $input['_usage_attribution'] : null,
                    (int) $current->environment_id, 'capability', 'sand_ai.document_parse',
                )->withCapability('image_ocr');
                $attribution->canonicalTrustedIdentity();
            } catch (\InvalidArgumentException) {
                throw new ApiProblem('SAND_AI_BUDGET_SCOPE_UNVERIFIED', 'OCR usage attribution is unavailable');
            }
            if ($preparedAttribution->canonicalTrustedIdentity() !== $attribution->canonicalTrustedIdentity()) {
                throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'OCR identity changed before dispatch');
            }
            $previous = Db::connect('pgsql')->query(
                "SELECT dispatch.id, dispatch.lease_fence, outcome.state
                 FROM sand_ai_ocr_dispatch dispatch
                 LEFT JOIN sand_ai_ocr_dispatch_outcome outcome ON outcome.ocr_dispatch_id=dispatch.id
                 WHERE dispatch.task_run_id=? ORDER BY dispatch.id",
                [$lease->taskId],
            );
            foreach ($previous as $row) {
                if ((int) $row['lease_fence'] === $lease->fence || $row['state'] === null || $row['state'] === 'uncertain') {
                    throw new ApiProblem('SAND_AI_OCR_DISPATCH_AMBIGUOUS', 'Prior OCR dispatch requires review before another request');
                }
            }
            (new FileParseAccessGuard())->revalidateQueued($authorization, $current);
            (new TaskGateway())->assertRunningLease($lease);
            // Recheck time-based price/policy selection after identity and owner lock waits.
            $started = gmdate('Y-m-d H:i:s');
            $finalPrice = OcrCallStartPrice::resolve($target, $started);
            $currentPolicies = (new \app\Application\Budget\BudgetPolicyService())->applicable($attribution, $target['model_id'] ?? 0, 'image_ocr', $started, true);
            $policyIds = array_map(static fn (\app\Infrastructure\Persistence\Model\BudgetPolicy $policy): int => (int) $policy->id, $currentPolicies);
            if ($finalPrice->snapshot !== $price->snapshot || $policyIds !== array_column($preparedBudget, 'policy_id')) {
                throw new ApiProblem('SAND_AI_CONFIGURATION_DRIFTED', 'OCR price or budget period changed before dispatch');
            }
            $attributionJson = json_encode($attribution->toPersisted(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $execution = $target ?? [];
            $pricing = ['price_revision_id' => $price->revisionId, 'currency' => $price->currency,
                'amount' => $price->amount, 'snapshot' => $price->snapshot];
            $fingerprint = \app\Application\Gateway\RequestPayloadFingerprint::fingerprint([
                'task_run_id' => $lease->taskId, 'file_parse_id' => (int) $currentParse->id,
                'file_id' => (int) $currentParse->file_id, 'attempt_no' => (int) $current->attempt_count,
                'lease_fence' => $lease->fence, 'execution_target' => $execution, 'pricing' => $pricing,
                'attribution' => $attribution->toPersisted()]);
            $rows = $db->query(
                "INSERT INTO sand_ai_ocr_dispatch
                 (environment_id,task_run_id,file_parse_id,file_id,attempt_no,lease_fence,parser_code,parser_version,attribution,create_time,
                  execution_target,pricing,request_fingerprint)
                 VALUES (?,?,?,?,?,?,?,?,CAST(? AS jsonb),?,CAST(? AS jsonb),CAST(? AS jsonb),?) RETURNING id",
                [(int) $current->environment_id, $lease->taskId, (int) $currentParse->id, (int) $currentParse->file_id,
                    (int) $current->attempt_count, $lease->fence, $driver->code(), $driver->version(), $attributionJson, $started,
                    json_encode((object) $execution, JSON_THROW_ON_ERROR), json_encode($pricing, JSON_THROW_ON_ERROR), $fingerprint],
            );
            $id = (int) ($rows[0]['id'] ?? 0);
            if ($id < 1) throw new ApiProblem('SAND_AI_OCR_USAGE_UNAVAILABLE', 'OCR dispatch could not be recorded');
            $budget->reserve($id, $fingerprint, $price, $preparedBudget);
            $deploymentSnapshot = $target['deployment_snapshot'] ?? ['task_run_id' => $lease->taskId,
                'file_parse_id' => (int) $currentParse->id, 'file_id' => (int) $currentParse->file_id,
                'attempt_no' => (int) $current->attempt_count, 'lease_fence' => $lease->fence,
                'parser_code' => $driver->code(), 'parser_version' => $driver->version()];
            $usage = $db->query(
                "INSERT INTO sand_ai_usage_event
                 (organization_id,application_id,environment_id,workload_client_id,identity_snapshot,provider_snapshot,
                  model_snapshot,deployment_snapshot,invocation_id,provider_id,model_id,deployment_id,ocr_dispatch_id,
                  service_action,source_kind,capability_code,metering_unit,metering_quantity,input_tokens,output_tokens,
                  total_tokens,usage_state,price_revision_id,price_snapshot,metered_amount,reconciled_amount,currency,
                  pricing_state,reconciliation_state,idempotency_key,create_time)
                 VALUES (?,?,?,?,CAST(? AS jsonb),CAST(? AS jsonb),CAST(? AS jsonb),CAST(? AS jsonb),
                  NULL,?,?,?,?,'sand_ai.document_parse','capability','image_ocr','external_request',1,0,0,0,
                  'measured',?,CAST(? AS jsonb),CAST(? AS numeric(20,6)),NULL,?,?,'unreconciled',?,?) RETURNING id",
                [$attribution->organizationId, $attribution->applicationId, $attribution->environmentId, $attribution->workloadClientId,
                    json_encode($attribution->identitySnapshot, JSON_THROW_ON_ERROR),
                    json_encode($target['provider_snapshot'] ?? ['kind' => 'private_ocr_driver', 'code' => $driver->code()], JSON_THROW_ON_ERROR),
                    json_encode((object) ($target['model_snapshot'] ?? []), JSON_THROW_ON_ERROR),
                    json_encode($deploymentSnapshot, JSON_THROW_ON_ERROR),
                    $target['provider_id'] ?? null, $target['model_id'] ?? null, $target['deployment_id'] ?? null,
                    $id, $price->revisionId, json_encode($price->snapshot, JSON_THROW_ON_ERROR), $price->amount,
                    $price->currency, $price->revisionId === null ? 'unpriced' : 'priced', 'ocr-dispatch:' . $id, $started],
            );
            if ((int) ($usage[0]['id'] ?? 0) < 1) {
                throw new ApiProblem('SAND_AI_OCR_USAGE_UNAVAILABLE', 'OCR request usage could not be recorded');
            }
            return $id;
        });
        // A savepoint return cannot authorize Curl; the immutable record must
        // have survived the outermost commit before this callback returns.
        $this->assertCommittedBoundary($db);
        return $id;
    }

    public function assertNoDispatchForTask(TaskRun $task): void
    {
        $this->assertInstalled();
        $rows = Db::connect('pgsql')->query(
            'SELECT 1 FROM sand_ai_ocr_dispatch WHERE task_run_id=? OR file_parse_id=? LIMIT 1',
            [(int) $task->id, (int) $task->file_parse_id],
        );
        if ($rows !== []) {
            throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'The original OCR request must be retained; it cannot use before-dispatch recovery');
        }
    }

    private function assertCommittedBoundary(\think\db\PDOConnection $db): void
    {
        $pdo = $db->getPdo();
        if (!$pdo instanceof \PDO || $pdo->inTransaction()) {
            throw new ApiProblem('SAND_AI_OCR_USAGE_UNAVAILABLE', 'OCR dispatch requires its own committed metering boundary');
        }
    }

    /** Receipt metadata only: no OCR text, private path or provider response is persisted. */
    public function outcome(int $dispatchId, string $state, ?int $httpStatus): void
    {
        if ($dispatchId < 1 || !in_array($state, ['response_known', 'uncertain'], true)
            || ($state === 'uncertain' && $httpStatus !== null)
            || ($state === 'response_known' && ($httpStatus === null || $httpStatus < 100 || $httpStatus > 599))) {
            throw new ApiProblem('SAND_AI_OCR_USAGE_UNAVAILABLE', 'OCR dispatch outcome is invalid');
        }
        // Observation of an already-authorized request survives cancellation or
        // lease replacement; it cannot authorize a new send or commit parse data.
        Db::connect('pgsql')->transaction(function () use ($dispatchId, $state, $httpStatus): void {
            $budget = new OcrBudgetGuard();
            $anchor = $budget->lock($dispatchId);
            $dispatch = Db::connect('pgsql')->table('sand_ai_ocr_dispatch')->where('id', $dispatchId)->lock(true)->find();
            if (!is_array($dispatch)) throw new ApiProblem('SAND_AI_OCR_USAGE_UNAVAILABLE', 'OCR dispatch evidence is unavailable');
            $existing = Db::connect('pgsql')->table('sand_ai_ocr_dispatch_outcome')->where('ocr_dispatch_id', $dispatchId)->find();
            if (is_array($existing)) {
                if ($existing['state'] !== $state || ($existing['http_status'] === null ? null : (int) $existing['http_status']) !== $httpStatus) {
                    throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'OCR outcome conflicts with its immutable receipt');
                }
                return;
            }
            $pricing = is_array($dispatch['pricing']) ? $dispatch['pricing'] : json_decode((string) $dispatch['pricing'], true, 32, JSON_THROW_ON_ERROR);
            Db::connect('pgsql')->execute(
                "INSERT INTO sand_ai_ocr_dispatch_outcome (ocr_dispatch_id,state,http_status,create_time) VALUES (?,?,?,?)",
                [$dispatchId, $state, $httpStatus, gmdate('Y-m-d H:i:s')],
            );
            $budget->finish($anchor, $state, $pricing);
        });
    }

    private function assertInstalled(): void
    {
        try {
            $rows = Db::connect('pgsql')->query(
                "SELECT to_regclass('sand_ai_ocr_dispatch') IS NOT NULL AS dispatch_present,
                        to_regclass('sand_ai_ocr_dispatch_outcome') IS NOT NULL AS outcome_present,
                        (SELECT count(*) FROM information_schema.columns WHERE table_schema=current_schema()
                           AND table_name='sand_ai_usage_event' AND column_name='ocr_dispatch_id'
                           AND data_type='bigint' AND is_nullable='YES' AND column_default IS NULL) AS column_count,
                        (SELECT count(*) FROM information_schema.columns WHERE table_schema=current_schema()
                           AND table_name='sand_ai_ocr_dispatch' AND column_name IN ('execution_target','pricing','request_fingerprint')) AS frozen_column_count,
                        (SELECT count(*) FROM information_schema.columns WHERE table_schema=current_schema()
                           AND table_name IN ('sand_ai_budget_reservation','sand_ai_budget_event') AND column_name='ocr_dispatch_id' AND data_type='bigint') AS budget_column_count,
                        (SELECT count(*) FROM pg_trigger WHERE NOT tgisinternal AND tgenabled IN ('O','A')
                           AND ((tgrelid=to_regclass('sand_ai_ocr_dispatch')
                             AND tgname IN ('trg_sand_ai_ocr_dispatch_binding_v103','trg_sand_ai_ocr_dispatch_immutable_v103'))
                            OR (tgrelid=to_regclass('sand_ai_ocr_dispatch') AND tgname='trg_sand_ai_ocr_frozen_binding_v150')
                            OR (tgrelid=to_regclass('sand_ai_ocr_dispatch_outcome') AND tgname='trg_sand_ai_ocr_outcome_immutable_v103')
                            OR (tgrelid=to_regclass('sand_ai_usage_event')
                              AND tgname IN ('trg_sand_ai_ocr_usage_binding_v103','trg_sand_ai_usage_event_immutable')))) AS trigger_count",
            );
            $row = $rows[0] ?? [];
            if (!in_array($row['dispatch_present'] ?? null, [true, 't', 1, '1'], true)
                || !in_array($row['outcome_present'] ?? null, [true, 't', 1, '1'], true)
                || (int) ($row['column_count'] ?? 0) !== 1 || (int) ($row['trigger_count'] ?? 0) !== 6
                || (int) ($row['frozen_column_count'] ?? 0) !== 3 || (int) ($row['budget_column_count'] ?? 0) !== 2) {
                throw new \RuntimeException('OCR schema is unavailable');
            }
        } catch (\Throwable) {
            throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'OCR dispatch metering migration is unavailable');
        }
    }
}
