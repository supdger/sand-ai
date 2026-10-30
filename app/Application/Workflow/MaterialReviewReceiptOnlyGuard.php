<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use app\Api\Support\ApiProblem;
use app\Application\Task\TaskState;
use app\Application\File\FileOwnershipGuard;
use app\Application\Usage\UsageAttribution;
use app\Application\Gateway\OutcomeReceiptService;
use app\Application\Gateway\OutcomeReceiptCipher;
use app\Application\Gateway\PostgresOutcomeReceiptStore;
use app\Infrastructure\Persistence\Model\AiFile;
use app\Infrastructure\Persistence\Model\Artifact;
use app\Infrastructure\Persistence\Model\AiFileParse;
use app\Infrastructure\Persistence\Model\Invocation;
use app\Infrastructure\Persistence\Model\InvocationDispatch;
use app\Infrastructure\Persistence\Model\InvocationOutcomeReceipt;
use app\Infrastructure\Persistence\Model\TaskRun;
use app\Infrastructure\Persistence\Model\TaskStep;
use app\Infrastructure\Persistence\Model\SourceBlock;
use app\Infrastructure\Persistence\Model\UsageEvent;
use app\Infrastructure\Persistence\Model\WorkflowCrossFileReview;
use app\Infrastructure\Persistence\Model\WorkflowCrossFileReviewResult;
use app\Infrastructure\Persistence\Model\WorkflowFileResult;
use app\Infrastructure\Persistence\Model\WorkflowFileResultSource;
use app\Infrastructure\Persistence\Model\WorkflowRun;
use app\Infrastructure\Persistence\Model\WorkflowRunFile;

/** Narrow eligibility proof for an existing paid review, not a generation route. */
final class MaterialReviewReceiptOnlyGuard
{
    public function available(TaskRun $task): bool
    {
        if ((int) $task->local_recovery_count >= (int) $task->local_recovery_max
            || TaskRun::where('id', (int) $task->id)
                ->whereRaw('((lease_owner IS NULL AND lease_token IS NULL AND lease_expire_time IS NULL)
                    OR lease_expire_time <= (clock_timestamp() AT TIME ZONE ?))',
                    [\app\Application\Task\TaskLeasePolicy::runningTimezone()])->find() === null) {
            return false;
        }
        try {
            $this->assert($task);
            return true;
        } catch (\Throwable $failure) {
            MaterialOutputFailureDiagnostics::record(
                $failure, (int) $task->id, null, (int) $task->resource_id, 'receipt_only_availability');
            return false;
        }
    }

    public function assert(TaskRun $task, bool $lock = false): WorkflowRun
    {
        $runQuery = WorkflowRun::where('id', (int) $task->resource_id)->where('task_id', (int) $task->id)
            ->where('environment_id', (int) $task->environment_id);
        $run = $lock ? $runQuery->lock(true)->find() : $runQuery->find();
        $reviewQuery = WorkflowCrossFileReview::where('workflow_run_id', (int) $task->resource_id);
        $review = $lock ? $reviewQuery->lock(true)->find() : $reviewQuery->find();
        $sealedReviewQuery = WorkflowCrossFileReviewResult::where('workflow_run_id', (int) $task->resource_id);
        $sealedReview = $lock ? $sealedReviewQuery->lock(true)->find() : $sealedReviewQuery->find();
        $input = $run === null ? null : (is_object($run->input_snapshot) ? (array) $run->input_snapshot : $run->input_snapshot);
        $step = TaskStep::where('task_id', (int) $task->id)->where('sequence_no', 1)->find();
        if ((string) $task->task_type !== 'structured_workflow' || (string) $task->resource_type !== 'workflow_run'
            || (string) $task->state !== TaskState::RECOVERY_PENDING
            || (int) $task->status !== 1 || $task->delete_time !== null || $task->content_purged_time !== null
            || $run === null || (string) $run->workflow_code !== 'material_analysis'
            || (int) $run->status !== 1 || $run->delete_time !== null
            || !is_array($input) || ($input['material_execution_mode'] ?? null) !== 'single_file_v1'
            || $step === null || (string) $step->state !== TaskState::FAILED
            || $review === null || (int) $review->attempt_count < 1
            || !(((string) $review->state === 'recovery_pending' && $sealedReview === null)
                || ((string) $review->state === 'succeeded' && $sealedReview !== null
                    && Artifact::where('workflow_run_id', (int) $run->id)->find() === null))) {
            self::unavailable();
        }
        $attribution = is_object($run->attribution_snapshot) ? (array) $run->attribution_snapshot : $run->attribution_snapshot;
        try {
            $scope = FileOwnershipGuard::scope(UsageAttribution::fromCurrentPersisted(
                is_array($attribution) ? $attribution : null, (int) $run->environment_id,
                'structured_workflow', 'sand_ai.workflow.run',
            ));
        } catch (\InvalidArgumentException) {
            self::unavailable();
        }
        $filesQuery = WorkflowRunFile::where('workflow_run_id', (int) $run->id)->order('id');
        $files = ($lock ? $filesQuery->lock(true) : $filesQuery)->select();
        if (count($files) < 2) self::unavailable();
        $chunkRunner = new MaterialCrossFileReviewChunkRunner();
        $chunked = $chunkRunner->active($run, $task);
        $reviewPromptFiles = [];
        $sealedFileSet = [];
        $fileResultIds = [];
        foreach ($files as $file) {
            $result = WorkflowFileResult::where('workflow_run_file_id', (int) $file->id)->find();
            $originalFile = AiFile::where('id', (int) $file->file_id)->find();
            $parse = AiFileParse::where('id', (int) $file->parse_id)->find();
            if ((string) $file->analysis_state !== 'succeeded' || $result === null
                || (int) $file->status !== 1 || $file->delete_time !== null
                || $originalFile === null || $parse === null
                || (int) $originalFile->status !== 1 || $originalFile->delete_time !== null
                || (int) $originalFile->environment_id !== (int) $run->environment_id
                || !hash_equals((string) $file->sha256, (string) $originalFile->sha256)
                || (int) $parse->file_id !== (int) $originalFile->id
                || (string) $originalFile->state !== 'parsed'
                || (int) $parse->status !== 1 || $parse->delete_time !== null
                || (string) $parse->state !== 'succeeded'
                || (int) $file->attempt_count !== (int) $result->attempt_no
                || (int) $file->last_invocation_id !== (int) $result->invocation_id
                || !hash_equals((string) $run->execution_snapshot_sha256, (string) $result->execution_snapshot_sha256)) {
                self::unavailable();
            }
            (new FileOwnershipGuard())->assert($originalFile, $scope);
            $payload = MaterialAnalysisFilePayloadDigest::verified(
                $result->payload, (string) $result->payload_sha256,
                $result->payload_digest_version, $result->content_purged_time,
            );
            $sources = WorkflowFileResultSource::where('result_id', (int) $result->id)
                ->order('field_path')->order('source_block_id')->select();
            if (count($sources) !== (int) $result->field_source_count) self::unavailable();
            foreach ($sources as $link) {
                $block = SourceBlock::where('id', (int) $link->source_block_id)->find();
                if ($block === null || (int) $block->status !== 1 || $block->delete_time !== null
                    || (int) $block->file_id !== (int) $file->file_id
                    || (int) $block->parse_id !== (int) $file->parse_id
                    || !hash_equals((string) $block->content_sha256, hash('sha256', (string) $block->content))) self::unavailable();
            }
            $fileResultIds[] = (int) $result->id;
            $sealedFileSet[] = [(int) $file->id, (int) $result->id, (string) $result->payload_sha256];
            if ($sealedReview !== null || $chunked) {
                $blocks = [];
                foreach (SourceBlock::where('file_id', (int) $file->file_id)->where('parse_id', (int) $file->parse_id)
                    ->where('status', 1)->whereNull('delete_time')->order('sequence_no')->select() as $block) {
                    if (!hash_equals((string) $block->content_sha256, hash('sha256', (string) $block->content))) self::unavailable();
                    $locator = is_object($block->locator) ? (array) $block->locator : $block->locator;
                    $blocks[] = ['id' => (int) $block->id, 'file_id' => (int) $file->file_id,
                        'parse_id' => (int) $file->parse_id, 'sequence_no' => (int) $block->sequence_no,
                        'locator_type' => (string) $block->locator_type, 'locator' => is_array($locator) ? $locator : [],
                        'content' => (string) $block->content, 'content_sha256' => (string) $block->content_sha256];
                }
                $sourceRows = [];
                foreach ($sources as $source) {
                    $sourceRows[] = ['field_path' => (string) $source->field_path, 'source_block_id' => (int) $source->source_block_id];
                }
                $reviewPromptFiles[] = ['workflow_run_file_id' => (int) $file->id,
                    'file_id' => (int) $file->file_id, 'parse_id' => (int) $file->parse_id,
                    'payload' => $payload, 'field_sources' => $sourceRows, 'blocks' => $blocks];
            }
        }
        if ($sealedReview !== null) {
            // Reuse the finalizer's exact immutable input/result/paid provenance proof.
            // The normal recovery path will only commit this already sealed result.
            (new MaterialAnalysisFileFinalizer())->sealedReview($run, $task, $step, $input, $reviewPromptFiles, $lock);
        }
        if ($chunked) {
            $snapshot = is_object($run->execution_snapshot) ? (array) $run->execution_snapshot : $run->execution_snapshot;
            if (!is_array($snapshot) || !hash_equals((string) $run->execution_snapshot_sha256, StructuredWorkflowPolicy::fingerprint($snapshot))) self::unavailable();
            $profile = $input['analysis_profile']['snapshot'] ?? null;
            if (!is_array($profile)) self::unavailable();
            $prompt = (new MaterialCrossFileReviewPromptFactory())->build((new MaterialAnalysisProfilePolicy())->validate($profile),
                $reviewPromptFiles, MaterialReviewOutputContract::fromSnapshot($snapshot), true);
            $completeInput = ['workflow_run_id' => (int) $run->id, 'execution_snapshot_sha256' => (string) $run->execution_snapshot_sha256,
                'file_result_ids' => $fileResultIds, 'sealed_set_sha256' => hash('sha256', json_encode($sealedFileSet, JSON_THROW_ON_ERROR))] + $prompt;
            if (array_key_exists('material_review_output_contract', $snapshot)) $completeInput['material_review_output_contract'] = $snapshot['material_review_output_contract'];
            $chunkRunner->assertPaidSet($run, $task, $completeInput);
            return $run;
        }
        $identity = new MaterialCrossFileReviewAttemptIdentity((string) $run->logical_execution_id, (int) $review->attempt_count);
        $invocation = Invocation::where('environment_id', (int) $run->environment_id)
            ->where('request_id', $identity->requestId())->where('state', 'succeeded')->find();
        $dispatch = $invocation === null ? null : InvocationDispatch::where('invocation_id', (int) $invocation->id)->find();
        $receipt = $invocation === null ? null : InvocationOutcomeReceipt::where('invocation_id', (int) $invocation->id)->find();
        $usage = $invocation === null ? null : UsageEvent::where('invocation_id', (int) $invocation->id)->find();
        if ($invocation === null || $dispatch === null || $receipt === null || $usage === null
            || (int) $invocation->status !== 1 || $invocation->delete_time !== null
            || (string) $dispatch->state !== 'succeeded'
            || (string) $dispatch->execution_scope !== 'structured_workflow'
            || (string) $dispatch->capability_code !== 'structured_extraction'
            || (string) $receipt->state !== 'available'
            || ($sealedReview === null
                ? $receipt->consumer_ack_time !== null
                : ($receipt->consumer_ack_time === null
                    || !hash_equals('cross_file_review:' . (int) $sealedReview->id, (string) $receipt->consumer_ref)))
            || (string) $receipt->capability_code !== 'structured_extraction'
            || InvocationOutcomeReceipt::where('id', (int) $receipt->id)->whereRaw("expire_time > (clock_timestamp() AT TIME ZONE 'UTC')")->find() === null) {
            self::unavailable();
        }
        foreach (['environment_id', 'organization_id', 'application_id', 'workload_client_id'] as $field) {
            if ((int) $run->{$field} < 1 || (int) $invocation->{$field} !== (int) $run->{$field}
                || (int) $usage->{$field} !== (int) $run->{$field}) self::unavailable();
        }
        foreach ([$invocation, $dispatch] as $row) {
            if ((int) $row->parent_workflow_run_id !== (int) $run->id
                || (int) $row->parent_task_run_id !== (int) $task->id
                || (int) $row->parent_task_step_id !== (int) $step->id
                || $row->parent_workflow_run_file_id !== null
                || (string) $row->workflow_node_code !== 'cross_file_review'
                || !hash_equals((string) $run->execution_snapshot_sha256, (string) $row->execution_snapshot_sha256)
                || !hash_equals($identity->dispatchLogicalExecutionId(), (string) $row->logical_execution_id)) {
                self::unavailable();
            }
        }
        if ((int) $dispatch->attempt_no !== (int) $review->attempt_count
            || (int) $invocation->execution_attempt_no !== (int) $review->attempt_count
            || (int) $dispatch->environment_id !== (int) $run->environment_id
            || (int) $dispatch->model_id !== (int) $invocation->model_id
            || (int) $dispatch->deployment_id !== (int) $invocation->deployment_id
            || (string) $usage->source_kind !== 'structured_workflow'
            || (string) $usage->service_action !== 'sand_ai.workflow.run'
            || (string) $usage->usage_state !== 'measured'
            || (int) $usage->provider_id !== (int) $dispatch->provider_id
            || (string) $usage->idempotency_key !== 'invocation:' . (int) $invocation->id
            || (int) $receipt->environment_id !== (int) $run->environment_id
            || (int) $receipt->model_id !== (int) $invocation->model_id
            || (int) $receipt->deployment_id !== (int) $invocation->deployment_id
            || (int) $usage->model_id !== (int) $invocation->model_id
            || (int) $usage->deployment_id !== (int) $invocation->deployment_id
            || (string) $usage->capability_code !== 'structured_extraction'
            || !hash_equals((string) $receipt->request_fingerprint, (string) $invocation->request_fingerprint)) {
            self::unavailable();
        }
        $config = config('sand_ai_outcome_receipt');
        if (!is_array($config)) self::unavailable();
        $accounted = StructuredProviderOutcome::decode((new OutcomeReceiptService(
            new PostgresOutcomeReceiptStore(), new OutcomeReceiptCipher($config), (int) ($config['ttl_seconds'] ?? 0),
        ))->replay([
            'environment_id' => (int) $run->environment_id, 'invocation_id' => (int) $invocation->id,
            'request_fingerprint' => (string) $invocation->request_fingerprint,
            'capability_code' => 'structured_extraction', 'model_id' => (int) $invocation->model_id,
            'deployment_id' => (int) $invocation->deployment_id, 'version' => OutcomeReceiptCipher::RECEIPT_VERSION,
        ]));
        if ($accounted === null || !$accounted->usageMeasured
            || (int) $usage->input_tokens !== $accounted->inputTokens
            || (int) $usage->output_tokens !== $accounted->outputTokens
            || (int) $usage->total_tokens !== $accounted->inputTokens + $accounted->outputTokens
            || (int) $usage->provider_id !== ($accounted->provider['id'] ?? null)
            || (int) $usage->model_id !== ($accounted->model['id'] ?? null)
            || (int) $usage->deployment_id !== ($accounted->deployment['id'] ?? null)) self::unavailable();
        foreach (['environment_id', 'organization_id', 'application_id', 'workload_client_id'] as $field) {
            if ((int) $run->{$field} !== ($accounted->attribution[$field] ?? null)) self::unavailable();
        }
        return $run;
    }

    private static function unavailable(): never
    {
        throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'This task has no complete paid cross-file review eligible for receipt-only recovery');
    }
}
