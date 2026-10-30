<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\OutcomeReceiptCipher;
use app\Application\Gateway\OutcomeReceiptService;
use app\Application\Gateway\PostgresOutcomeReceiptStore;
use app\Application\Gateway\RequestPayloadFingerprint;
use app\Application\Task\TaskExecutionLease;
use app\Application\Task\TaskLeasePolicy;
use app\Application\Usage\UsageAttribution;
use app\Infrastructure\Persistence\Model\Invocation;
use app\Infrastructure\Persistence\Model\InvocationDispatch;
use app\Infrastructure\Persistence\Model\TaskRun;
use app\Infrastructure\Persistence\Model\TaskStep;
use app\Infrastructure\Persistence\Model\UsageEvent;
use app\Infrastructure\Persistence\Model\WorkflowCrossFileReview;
use app\Infrastructure\Persistence\Model\WorkflowRun;
use think\facade\Db;

/** Bounded official Neuron reviews on the parent task's fenced private journal. */
final class MaterialCrossFileReviewChunkRunner
{
    public const VERSION = 'material_cross_file_chunks@1';

    public function __construct(private readonly MaterialCrossFileReviewInputLoader $loader = new MaterialCrossFileReviewInputLoader(),
        private readonly MaterialCrossFileReviewOutputValidator $validator = new MaterialCrossFileReviewOutputValidator()) {}

    /** Factory receives null for the legacy final request, otherwise batch_000001 and its original attempt. */
    public function execute(WorkflowRun $run, TaskRun $task, TaskExecutionLease $lease, \Closure $clientFactory,
        ?\Closure $heartbeat = null, bool $recovery = false): ?array
    {
        if (!$this->active($run, $task)) self::invalid();
        $input = $this->loader->load((int) $run->id, $lease);
        $review = WorkflowCrossFileReview::where('workflow_run_id', (int) $run->id)->find();
        if ($review === null || (int) $review->attempt_count < 1) self::invalid();
        $journal = $this->mutate($lease, $run, static function (?array $old) use ($input): array {
            if ($old !== null) { self::assertPlan($old, $input); return $old; }
            $nodes = [];
            foreach ($input['review_plan'] as $batch) $nodes[$batch['id']] = ['state' => 'pending', 'attempt_no' => null, 'invocation_id' => null];
            return ['version' => self::VERSION, 'execution_snapshot_sha256' => $input['execution_snapshot_sha256'],
                'sealed_set_sha256' => $input['sealed_set_sha256'], 'input_sha256' => $input['input_sha256'],
                'plan' => $input['review_plan'], 'material_review_output_contract' => $input['material_review_output_contract'] ?? null, 'nodes' => $nodes];
        });
        foreach ($input['review_batches'] as $index => $batch) {
            $id = $batch['id'];
            $final = $index === count($input['review_batches']) - 1;
            $batch = $this->batchInput($input, $batch);
            $saved = $journal['nodes'][$id];
            if (($saved['state'] ?? null) === 'succeeded') {
                $this->assertNode($run, $task, $batch, $saved, $final);
                continue;
            }
            if (is_array($saved['budget_rejection'] ?? null)) {
                $this->assertBudgetStop($run, $task, $batch, $saved, $final);
            }
            $attempt = ($saved['state'] ?? null) === 'failed' && ($saved['attempt_no'] ?? null) < (int) $review->attempt_count
                ? (int) $review->attempt_count : ($saved['attempt_no'] ?? (int) $review->attempt_count);
            if (!is_int($attempt) || $attempt < 1 || $attempt > (int) $review->attempt_count) self::invalid();
            $identity = $this->identity($run, $id, $attempt, $final);
            $invocation = Invocation::where('environment_id', (int) $run->environment_id)->where('request_id', $identity->requestId())->find();
            $dispatch = $invocation === null ? null : InvocationDispatch::where('invocation_id', (int) $invocation->id)->find();
            if (($saved['state'] ?? null) === 'failed' && ($saved['attempt_no'] ?? null) === $attempt) {
                throw new ApiProblem((string) $saved['error_code'], 'The original cross-file batch requires an explicit retry');
            }
            if ($invocation !== null && $dispatch !== null && (string) $invocation->state === 'failed' && (string) $dispatch->state === 'failed') {
                $this->assertIdentity($run, $task, $invocation, $dispatch, $id, $attempt, $final);
                if ((string) $invocation->error_code !== 'SAND_AI_PROVIDER_REJECTED') self::pending();
                $journal = $this->record($lease, $run, $input, $id, ['state' => 'failed', 'attempt_no' => $attempt,
                    'invocation_id' => (int) $invocation->id, 'legacy_identity' => $final, 'error_code' => 'SAND_AI_PROVIDER_REJECTED']);
                throw new ApiProblem('SAND_AI_PROVIDER_REJECTED', 'The original cross-file batch was rejected');
            }
            if ($invocation !== null && $dispatch !== null && (string) $invocation->state === 'succeeded' && (string) $dispatch->state === 'succeeded') {
                $this->assertIdentity($run, $task, $invocation, $dispatch, $id, $attempt, $final);
                $this->assertRequest($run, $invocation, $dispatch, $batch);
                $content = $this->receiptContent($invocation);
                $client = new class($content) implements MaterialAnalysisCompletionClient {
                    public function __construct(private readonly string $content) {}
                    public function complete(array $messages): string { return $this->content; }
                };
            } else {
                $visible = $dispatch !== null && in_array((string) $dispatch->state, ['dispatching', 'ambiguous', 'succeeded'], true);
                if ($visible || ($saved['state'] ?? null) === 'recovery_pending') {
                    $this->record($lease, $run, $input, $id, ['state' => 'recovery_pending', 'attempt_no' => $attempt,
                        'invocation_id' => $invocation === null ? null : (int) $invocation->id, 'legacy_identity' => $final]);
                    self::pending();
                }
                if ($recovery) return null;
                $client = $clientFactory($final ? null : $id, $attempt);
            }
            $journal = $this->record($lease, $run, $input, $id, ['state' => 'running', 'attempt_no' => $attempt,
                'legacy_identity' => $final, 'budget_rejection' => null, 'pre_dispatch_error' => false, 'error_code' => null]);
            $raw = null;
            $heartbeat?->__invoke();
            try {
                (new NeuronMaterialReviewWorkflow(new NeuronMaterialReviewResources((int) $run->id, $lease,
                    static fn (array $prepared): string => $client->complete($prepared['messages']),
                    function (array $prepared, string $content) use (&$raw, $run, $attempt, $identity, $batch): void {
                        $original = Invocation::where('environment_id', (int) $run->environment_id)->where('request_id', $identity->requestId())->find();
                        try { $this->validate($run, $batch, $content); }
                        catch (MaterialReviewOutputRejected $failure) {
                            if ($original !== null) throw $failure->atPaidOutcome((int) $run->id, (int) $original->id, $attempt, $content);
                            throw $failure;
                        }
                        $raw = $content;
                    }, preparedInput: $batch)))->review();
            } catch (MaterialReviewOutputRejected|ApiProblem $failure) {
                $invocation = Invocation::where('environment_id', (int) $run->environment_id)->where('request_id', $identity->requestId())->find();
                $dispatch = $invocation === null ? null : InvocationDispatch::where('invocation_id', (int) $invocation->id)->find();
                $known = $invocation !== null && $dispatch !== null
                    && (($failure instanceof MaterialReviewOutputRejected && (string) $invocation->state === 'succeeded' && (string) $dispatch->state === 'succeeded')
                        || ($failure->errorCode === 'SAND_AI_PROVIDER_REJECTED' && (string) $invocation->state === 'failed' && (string) $dispatch->state === 'failed'));
                if ($known) $this->assertIdentity($run, $task, $invocation, $dispatch, $id, $attempt, $final);
                $budget = $failure->errorCode === 'SAND_AI_BUDGET_EXCEEDED' && $invocation !== null
                    ? $this->captureBudgetStop($run, $task, $batch, $invocation, $attempt, $final) : null;
                $known = $known || $budget !== null;
                $this->record($lease, $run, $input, $id, ['state' => $known ? 'failed' : 'recovery_pending',
                    'attempt_no' => $attempt, 'invocation_id' => $invocation === null ? null : (int) $invocation->id,
                    'legacy_identity' => $final, 'error_code' => $failure->errorCode,
                    'budget_rejection' => $budget, 'pre_dispatch_error' => $budget !== null,
                    'rejected_content_sha256' => $failure instanceof MaterialReviewOutputRejected ? $failure->contentSha256 : null]);
                throw $failure;
            }
            $heartbeat?->__invoke();
            if (!is_string($raw)) self::invalid();
            $invocation = Invocation::where('environment_id', (int) $run->environment_id)->where('request_id', $identity->requestId())->find();
            $dispatch = $invocation === null ? null : InvocationDispatch::where('invocation_id', (int) $invocation->id)->find();
            if ($invocation === null || $dispatch === null || (string) $invocation->state !== 'succeeded' || (string) $dispatch->state !== 'succeeded') self::pending();
            $this->assertIdentity($run, $task, $invocation, $dispatch, $id, $attempt, $final);
            $this->assertRequest($run, $invocation, $dispatch, $batch);
            if (!hash_equals(hash('sha256', $this->receiptContent($invocation)), hash('sha256', $raw))) self::invalid();
            $journal = $this->record($lease, $run, $input, $id, ['state' => 'succeeded', 'attempt_no' => $attempt,
                'invocation_id' => (int) $invocation->id, 'legacy_identity' => $final,
                'output_json' => $raw, 'output_sha256' => hash('sha256', $raw), 'error_code' => null]);
        }
        return $this->complete($run, $task, $input);
    }

    public function active(WorkflowRun $run, TaskRun $task): bool
    {
        $input = is_object($run->input_snapshot) ? (array) $run->input_snapshot : $run->input_snapshot;
        return is_array($input) && ($input['material_chunk_policy'] ?? null) === MaterialChunkCheckpoint::VERSION;
    }

    public function knowsBatch(int $taskId, string $batchId): bool
    {
        $journal = $this->read($taskId);
        return $journal !== null && ($journal['version'] ?? null) === self::VERSION && isset($journal['nodes'][$batchId]);
    }

    public function read(int $taskId): ?array
    {
        $step = TaskStep::where('task_id', $taskId)->where('sequence_no', 1)->find();
        if ($step === null) self::invalid();
        $context = is_object($step->context) ? (array) $step->context : $step->context;
        $journal = is_array($context) ? ($context['material_cross_file_chunks'] ?? null) : null;
        if ($journal !== null && !is_array($journal)) self::invalid();
        return $journal;
    }

    /** Original identity lookup for output rejection and recovery, including the legacy final batch. */
    public function invocationBatch(WorkflowRun $run, TaskRun $task, Invocation $invocation): array
    {
        $journal = $this->read((int) $task->id);
        if ($journal === null) self::invalid();
        foreach ($journal['plan'] as $index => $batch) {
            $final = $index === count($journal['plan']) - 1;
            $identity = $this->identity($run, $batch['id'], (int) $invocation->execution_attempt_no, $final);
            if (hash_equals($identity->requestId(), (string) $invocation->request_id)) return ['id' => $batch['id'], 'final' => $final, 'identity' => $identity];
        }
        self::invalid();
    }

    /** Persist only explicit node transitions while the same parent lease owns the review. */
    private function record(TaskExecutionLease $lease, WorkflowRun $run, array $input, string $id, array $patch): array
    {
        return $this->mutate($lease, $run, static function (?array $journal) use ($input, $id, $patch): array {
            if ($journal === null || !isset($journal['nodes'][$id])) self::invalid();
            self::assertPlan($journal, $input);
            $old = $journal['nodes'][$id];
            if (($old['state'] ?? null) === 'succeeded') {
                if (($patch['state'] ?? null) !== 'succeeded' || ($patch['output_sha256'] ?? null) !== ($old['output_sha256'] ?? null)
                    || ($patch['invocation_id'] ?? null) !== ($old['invocation_id'] ?? null)) self::invalid();
                return $journal;
            }
            $history = $old['attempts'] ?? [];
            if (is_int($old['attempt_no'] ?? null)) $history[(string) $old['attempt_no']] = array_diff_key($old, ['attempts' => true]);
            $journal['nodes'][$id] = $patch + $old;
            $journal['nodes'][$id]['attempts'] = $history;
            return $journal;
        });
    }

    private function mutate(TaskExecutionLease $lease, WorkflowRun $run, \Closure $change): array
    {
        return Db::connect('pgsql')->transaction(function () use ($lease, $run, $change): array {
            $task = TaskRun::where('id', $lease->taskId)->lock(true)->find();
            $step = TaskStep::where('task_id', $lease->taskId)->where('sequence_no', 1)->lock(true)->find();
            $current = WorkflowRun::where('id', (int) $run->id)->lock(true)->find();
            $review = WorkflowCrossFileReview::where('workflow_run_id', (int) $run->id)->lock(true)->find();
            if ($task === null || $step === null || $current === null || $review === null
                || (int) $current->task_id !== $lease->taskId || $current->content_purged_time !== null
                || !hash_equals((string) $run->execution_snapshot_sha256, (string) $current->execution_snapshot_sha256)
                || !in_array((string) $task->state, ['running', 'recovery_pending'], true) || $task->canceled_at !== null
                || (string) $step->state !== ((string) $task->state === 'running' ? 'running' : 'failed')
                || (string) $review->state !== ((string) $task->state === 'running' ? 'running' : 'recovery_pending')
                || (string) $task->lease_owner !== $lease->owner || !hash_equals($lease->token, (string) $task->lease_token)
                || (int) $task->lease_fence !== $lease->fence) throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'The cross-file journal no longer owns the parent review');
            $timezone = TaskLeasePolicy::runningTimezone();
            if (TaskRun::where('id', $lease->taskId)->whereRaw('lease_expire_time > (clock_timestamp() AT TIME ZONE ?)', [$timezone])->value('id') === null)
                throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'The cross-file journal lease expired');
            $context = is_object($step->context) ? (array) $step->context : $step->context;
            if (!is_array($context)) $context = [];
            $old = $context['material_cross_file_chunks'] ?? null;
            if ($old !== null && !is_array($old)) self::invalid();
            $next = $change($old);
            $context['material_cross_file_chunks'] = $next;
            $step->save(['context' => $context]);
            return $next;
        });
    }

    private static function assertPlan(array $journal, array $input): void
    {
        if (($journal['version'] ?? null) !== self::VERSION || ($journal['execution_snapshot_sha256'] ?? null) !== ($input['execution_snapshot_sha256'] ?? null)
            || ($journal['sealed_set_sha256'] ?? null) !== ($input['sealed_set_sha256'] ?? null)
            || ($journal['input_sha256'] ?? null) !== ($input['input_sha256'] ?? null)
            || !RequestPayloadFingerprint::same(
                ['material_review_output_contract' => $journal['material_review_output_contract'] ?? null, 'plan' => $journal['plan'] ?? null],
                ['material_review_output_contract' => $input['material_review_output_contract'] ?? null, 'plan' => $input['review_plan'] ?? null],
                [],
            )) self::invalid();
    }

    private function batchInput(array $input, array $batch): array
    {
        return $batch + array_intersect_key($input, array_flip(['workflow_run_id', 'execution_snapshot_sha256', 'file_result_ids',
            'sealed_set_sha256', 'material_review_output_contract']));
    }

    private function identity(WorkflowRun $run, string $id, int $attempt, bool $final): MaterialCrossFileReviewAttemptIdentity|MaterialCrossFileReviewChunkAttemptIdentity
    {
        return $final ? new MaterialCrossFileReviewAttemptIdentity((string) $run->logical_execution_id, $attempt)
            : new MaterialCrossFileReviewChunkAttemptIdentity((string) $run->logical_execution_id, $id, $attempt);
    }

    public function assertIdentity(WorkflowRun $run, TaskRun $task, Invocation $invocation, InvocationDispatch $dispatch,
        string $id, int $attempt, bool $final): void
    {
        $review = WorkflowCrossFileReview::where('workflow_run_id', (int) $run->id)->find();
        $step = TaskStep::where('task_id', (int) $task->id)->where('sequence_no', 1)->find();
        if ($step === null || $review === null || $attempt < 1 || $attempt > (int) $review->attempt_count) self::invalid();
        $identity = $this->identity($run, $id, $attempt, $final);
        foreach (['environment_id', 'organization_id', 'application_id', 'workload_client_id'] as $field) {
            if ((int) $run->getAttr($field) < 1 || (int) $invocation->getAttr($field) !== (int) $run->getAttr($field)) self::invalid();
        }
        foreach ([$invocation, $dispatch] as $row) {
            if ((int) $row->parent_workflow_run_id !== (int) $run->id || (int) $row->parent_task_run_id !== (int) $task->id
                || (int) $row->parent_task_step_id !== (int) $step->id || $row->parent_workflow_run_file_id !== null
                || (string) $row->workflow_node_code !== 'cross_file_review'
                || !hash_equals((string) $run->execution_snapshot_sha256, (string) $row->execution_snapshot_sha256)
                || !hash_equals($identity->dispatchLogicalExecutionId(), (string) $row->logical_execution_id)) self::invalid();
        }
        if ((int) $invocation->status !== 1 || $invocation->delete_time !== null
            || (int) $invocation->execution_attempt_no !== $attempt || (int) $dispatch->attempt_no !== $attempt
            || (int) $dispatch->invocation_id !== (int) $invocation->id || (int) $dispatch->environment_id !== (int) $run->environment_id
            || (int) $dispatch->application_id !== (int) $run->application_id || (string) $dispatch->execution_scope !== 'structured_workflow'
            || (string) $dispatch->service_action !== 'sand_ai.workflow.run' || (string) $dispatch->source_kind !== 'structured_workflow'
            || (string) $dispatch->capability_code !== 'structured_extraction'
            || (int) $dispatch->model_id !== (int) $invocation->model_id || (int) $dispatch->deployment_id !== (int) $invocation->deployment_id
            || !hash_equals($identity->requestId(), (string) $invocation->request_id)
            || !hash_equals(hash('sha256', $identity->requestId()), (string) $dispatch->logical_request_id)) self::invalid();
        if ((string) $invocation->state === 'succeeded') {
            $usage = UsageEvent::where('invocation_id', (int) $invocation->id)->find();
            if ($usage === null || (string) $usage->idempotency_key !== 'invocation:' . (int) $invocation->id
                || (string) $usage->service_action !== 'sand_ai.workflow.run' || (string) $usage->source_kind !== 'structured_workflow'
                || (string) $usage->capability_code !== 'structured_extraction'
                || (int) $usage->model_id !== (int) $invocation->model_id || (int) $usage->deployment_id !== (int) $invocation->deployment_id) self::invalid();
            foreach (['environment_id', 'organization_id', 'application_id', 'workload_client_id'] as $field) {
                if ((int) $usage->getAttr($field) !== (int) $run->getAttr($field)) self::invalid();
            }
        }
    }

    public function assertRequest(WorkflowRun $run, Invocation $invocation, InvocationDispatch $dispatch, array $batch): void
    {
        $review = WorkflowCrossFileReview::where('workflow_run_id', (int) $run->id)->find();
        $attribution = is_object($run->attribution_snapshot) ? (array) $run->attribution_snapshot : $run->attribution_snapshot;
        $route = is_object($dispatch->route_snapshot) ? (array) $dispatch->route_snapshot : $dispatch->route_snapshot;
        if ($review === null || !is_array($route) || !is_int($review->max_output_tokens) || !is_string($review->output_token_parameter)) self::invalid();
        try { $usage = UsageAttribution::fromCurrentPersisted(is_array($attribution) ? $attribution : null,
            (int) $run->environment_id, 'structured_workflow', 'sand_ai.workflow.run'); }
        catch (\InvalidArgumentException) { self::invalid(); }
        MaterialCrossFileReviewRequestEvidence::assert((int) $run->environment_id, $usage,
            (int) $invocation->model_id, (int) $invocation->deployment_id, $route,
            $review->max_output_tokens, $review->output_token_parameter, $batch['messages'],
            (string) $invocation->request_fingerprint, (string) $dispatch->request_body_digest,
            MaterialReviewOutputContract::fromSnapshot($batch));
    }

    private function receiptContent(Invocation $invocation): string
    {
        $config = config('sand_ai_outcome_receipt');
        if (!is_array($config)) throw new ApiProblem('SAND_AI_RECEIPT_UNAVAILABLE', 'The original cross-file batch receipt is unavailable');
        $stored = (new OutcomeReceiptService(new PostgresOutcomeReceiptStore(),
            new OutcomeReceiptCipher($config), (int) ($config['ttl_seconds'] ?? 0)))->replay([
                'environment_id' => (int) $invocation->environment_id, 'invocation_id' => (int) $invocation->id,
                'request_fingerprint' => (string) $invocation->request_fingerprint, 'capability_code' => 'structured_extraction',
                'model_id' => (int) $invocation->model_id, 'deployment_id' => (int) $invocation->deployment_id,
                'version' => OutcomeReceiptCipher::RECEIPT_VERSION]);
        $outcome = StructuredProviderOutcome::decode($stored);
        $usage = UsageEvent::where('invocation_id', (int) $invocation->id)->find();
        if ($outcome === null || !$outcome->usageMeasured || $usage === null || (string) $usage->usage_state !== 'measured'
            || (int) $usage->input_tokens !== $outcome->inputTokens || (int) $usage->output_tokens !== $outcome->outputTokens
            || (int) $usage->total_tokens !== $outcome->inputTokens + $outcome->outputTokens
            || (int) $usage->provider_id !== ($outcome->provider['id'] ?? null)
            || (int) $usage->model_id !== ($outcome->model['id'] ?? null)
            || (int) $usage->deployment_id !== ($outcome->deployment['id'] ?? null)) self::invalid();
        foreach (['environment_id', 'organization_id', 'application_id', 'workload_client_id'] as $field) {
            if ((int) $invocation->getAttr($field) !== ($outcome->attribution[$field] ?? null)) self::invalid();
        }
        return $outcome->content;
    }

    private function validate(WorkflowRun $run, array $batch, string $raw): array
    {
        $input = is_object($run->input_snapshot) ? (array) $run->input_snapshot : $run->input_snapshot;
        $profile = $input['analysis_profile']['snapshot'] ?? null;
        if (!is_array($profile)) self::invalid();
        return $this->validator->validate($raw, $batch['source_file_ids'], $batch['candidates'],
            (new MaterialAnalysisProfilePolicy())->validate($profile)['max_output_characters'], MaterialReviewOutputContract::fromSnapshot($batch));
    }

    private function assertNode(WorkflowRun $run, TaskRun $task, array $batch, array $saved, bool $final): void
    {
        $raw = $saved['output_json'] ?? null;
        if (($saved['state'] ?? null) !== 'succeeded' || !is_string($raw) || !is_string($saved['output_sha256'] ?? null)
            || !hash_equals($saved['output_sha256'], hash('sha256', $raw))) self::invalid();
        $invocation = Invocation::where('id', (int) ($saved['invocation_id'] ?? 0))->find();
        $dispatch = $invocation === null ? null : InvocationDispatch::where('invocation_id', (int) $invocation->id)->find();
        if ($invocation === null || $dispatch === null || (string) $invocation->state !== 'succeeded' || (string) $dispatch->state !== 'succeeded') self::invalid();
        $this->assertIdentity($run, $task, $invocation, $dispatch, $batch['id'], (int) $saved['attempt_no'], $final);
        $this->assertRequest($run, $invocation, $dispatch, $batch);
        $this->validate($run, $batch, $raw);
    }

    /** All batch results must remain bound to the rebuilt complete pair plan before sealing. */
    public function complete(WorkflowRun $run, TaskRun $task, array $input): array
    {
        $journal = $this->read((int) $task->id);
        if ($journal === null) self::invalid();
        self::assertPlan($journal, $input);
        $items = [];
        $last = null;
        foreach ($input['review_batches'] as $index => $batch) {
            $saved = $journal['nodes'][$batch['id']] ?? null;
            if (!is_array($saved)) self::invalid();
            $batch = $this->batchInput($input, $batch);
            $this->assertNode($run, $task, $batch, $saved, $index === count($input['review_batches']) - 1);
            $value = json_decode($saved['output_json'], true, 512, JSON_THROW_ON_ERROR);
            foreach ($value['items_to_verify'] as $item) $items[StructuredWorkflowPolicy::fingerprint($item)] = $item;
            $last = $saved;
        }
        if ($last === null) self::invalid();
        $json = json_encode(['items_to_verify' => array_values($items)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return ['input' => $input, 'raw_json' => $json, 'invocation_id' => $last['invocation_id'],
            'manifest' => $this->validator->validate($json, $input['source_file_ids'], $input['candidates'],
                max(1, mb_strlen($json, 'UTF-8')), MaterialReviewOutputContract::fromSnapshot($input))];
    }

    /** Prepare the same storage projection as the historical result policy, from all sealed batch outputs. */
    public function prepareSealed(WorkflowRun $run, TaskRun $task, array $input, array $current, string $rawJson, int $invocationId): array
    {
        if ($input !== $current) self::invalid();
        $complete = $this->complete($run, $task, $current);
        if ($complete['invocation_id'] !== $invocationId || !hash_equals($complete['raw_json'], $rawJson)) self::invalid();
        $manifest = $complete['manifest'];
        $sources = $manifest['field_sources'];
        $withheld = $manifest['withheld_candidates'];
        usort($sources, static fn (array $left, array $right): int => strcmp($left['field_path'], $right['field_path']) ?: $left['source_block_id'] <=> $right['source_block_id']);
        usort($withheld, static fn (array $left, array $right): int => $left['workflow_run_file_id'] <=> $right['workflow_run_file_id'] ?: strcmp($left['field_path'], $right['field_path']));
        return ['payload' => $manifest['payload'], 'payload_sha256' => StructuredWorkflowPolicy::fingerprint($manifest['payload']),
            'input_sha256' => $input['input_sha256'], 'source_rows' => $sources, 'withheld_rows' => $withheld,
            'field_source_count' => count($sources), 'withheld_candidate_count' => count($withheld)];
    }

    /** Receipt-only recovery requires a complete paid set, including every intermediate batch. */
    public function assertPaidSet(WorkflowRun $run, TaskRun $task, array $input): void
    {
        $journal = $this->read((int) $task->id);
        if ($journal === null) self::invalid();
        self::assertPlan($journal, $input);
        foreach ($input['review_batches'] as $index => $batch) {
            $saved = $journal['nodes'][$batch['id']] ?? null;
            if (!is_array($saved) || !is_int($saved['attempt_no'] ?? null) || ($saved['state'] ?? null) === 'failed') self::pending();
            $identity = $this->identity($run, $batch['id'], $saved['attempt_no'], $index === count($input['review_batches']) - 1);
            $invocation = Invocation::where('environment_id', (int) $run->environment_id)->where('request_id', $identity->requestId())->find();
            $dispatch = $invocation === null ? null : InvocationDispatch::where('invocation_id', (int) $invocation->id)->find();
            if ($invocation === null || $dispatch === null || (string) $invocation->state !== 'succeeded' || (string) $dispatch->state !== 'succeeded') self::pending();
            $batch = $this->batchInput($input, $batch);
            $this->assertIdentity($run, $task, $invocation, $dispatch, $batch['id'], $saved['attempt_no'], $index === count($input['review_batches']) - 1);
            $this->assertRequest($run, $invocation, $dispatch, $batch);
            $content = $this->receiptContent($invocation);
            $this->validate($run, $batch, $content);
            if (($saved['state'] ?? null) === 'succeeded' && !hash_equals((string) $saved['output_sha256'], hash('sha256', $content))) self::invalid();
        }
    }

    public function rootInvocationAttemptNo(WorkflowRun $run, TaskRun $task, int $invocationId): int
    {
        $journal = $this->read((int) $task->id);
        if ($journal === null || ($journal['version'] ?? null) !== self::VERSION
            || ($journal['execution_snapshot_sha256'] ?? null) !== (string) $run->execution_snapshot_sha256) self::invalid();
        $batch = $journal['plan'][count($journal['plan']) - 1] ?? null;
        $saved = is_array($batch) ? ($journal['nodes'][$batch['id']] ?? null) : null;
        if (!is_array($saved) || ($saved['invocation_id'] ?? null) !== $invocationId) self::invalid();
        if (is_array($journal['material_review_output_contract'] ?? null)) $batch['material_review_output_contract'] = $journal['material_review_output_contract'];
        $this->assertNode($run, $task, $batch, $saved, true);
        return (int) $saved['attempt_no'];
    }

    /** Never open a generation route while an original dispatched batch is unresolved. */
    public function canResume(WorkflowRun $run, TaskRun $task): bool
    {
        if (!$this->active($run, $task)) return false;
        $settled = $this->settledDispatchIds($run, $task);
        foreach (InvocationDispatch::where('parent_workflow_run_id', (int) $run->id)
            ->where('parent_task_run_id', (int) $task->id)->where('workflow_node_code', 'cross_file_review')->select() as $dispatch) {
            if (in_array((string) $dispatch->state, ['dispatching', 'ambiguous', 'succeeded', 'failed'], true)
                && !isset($settled[(int) $dispatch->id])) return false;
        }
        return true;
    }

    /** Confirmed terminal batches only; any unknown outcome keeps the task retry fence closed. */
    public function settledDispatchIds(WorkflowRun $run, TaskRun $task): array
    {
        if (!$this->active($run, $task)) return [];
        $journal = $this->read((int) $task->id);
        if ($journal === null) return [];
        if (($journal['version'] ?? null) !== self::VERSION
            || ($journal['execution_snapshot_sha256'] ?? null) !== (string) $run->execution_snapshot_sha256) self::invalid();
        $ids = [];
        foreach ($journal['plan'] as $index => $batch) {
            $node = $journal['nodes'][$batch['id']] ?? null;
            if (!is_array($node)) self::invalid();
            $attempts = $node['attempts'] ?? [];
            $attempts[] = $node;
            foreach ($attempts as $saved) {
                if (!in_array($saved['state'] ?? null, ['succeeded', 'failed'], true)) continue;
                $invocation = Invocation::where('id', (int) ($saved['invocation_id'] ?? 0))->find();
                $dispatch = $invocation === null ? null : InvocationDispatch::where('invocation_id', (int) $invocation->id)->find();
                if (is_array($saved['budget_rejection'] ?? null)) {
                    $this->assertBudgetStop($run, $task, $batch, $saved, $index === count($journal['plan']) - 1);
                    continue;
                }
                if ($invocation === null || $dispatch === null) self::invalid();
                $this->assertIdentity($run, $task, $invocation, $dispatch, $batch['id'], (int) $saved['attempt_no'], $index === count($journal['plan']) - 1);
                $batchInput = $batch;
                if (is_array($journal['material_review_output_contract'] ?? null)) $batchInput['material_review_output_contract'] = $journal['material_review_output_contract'];
                $this->assertRequest($run, $invocation, $dispatch, $batchInput);
                if (($saved['state'] ?? null) === 'succeeded') {
                    $this->validate($run, $batchInput, (string) ($saved['output_json'] ?? ''));
                    if ((string) $invocation->state !== 'succeeded' || (string) $dispatch->state !== 'succeeded'
                        || !is_string($saved['output_json'] ?? null) || !is_string($saved['output_sha256'] ?? null)
                        || !hash_equals($saved['output_sha256'], hash('sha256', $saved['output_json']))) self::invalid();
                } elseif (($saved['error_code'] ?? null) === 'SAND_AI_PROVIDER_REJECTED') {
                    if ((string) $invocation->state !== 'failed' || (string) $dispatch->state !== 'failed'
                        || (string) $invocation->error_code !== 'SAND_AI_PROVIDER_REJECTED') self::invalid();
                } else {
                    if ((string) $invocation->state !== 'succeeded' || (string) $dispatch->state !== 'succeeded'
                        || !is_string($saved['rejected_content_sha256'] ?? null)
                        || !hash_equals($saved['rejected_content_sha256'], hash('sha256', $this->receiptContent($invocation)))) self::invalid();
                }
                $ids[(int) $dispatch->id] = true;
            }
        }
        return $ids;
    }

    private function captureBudgetStop(WorkflowRun $run, TaskRun $task, array $batch,
        Invocation $invocation, int $attempt, bool $final): ?array
    {
        $review = WorkflowCrossFileReview::where('workflow_run_id', (int) $run->id)->find();
        if ($review === null || !is_int($review->max_output_tokens) || $attempt < 1
            || $attempt > (int) $review->attempt_count) return null;
        return (new MaterialBudgetRejectionEvidence())->capture($run, $task, $invocation,
            $this->identity($run, $batch['id'], $attempt, $final)->requestId(), 'cross_file_review',
            $attempt, $batch['messages'], $review->max_output_tokens, MaterialReviewOutputContract::fromSnapshot($batch));
    }

    private function assertBudgetStop(WorkflowRun $run, TaskRun $task, array $batch, array $saved, bool $final): void
    {
        $review = WorkflowCrossFileReview::where('workflow_run_id', (int) $run->id)->find();
        $proof = $saved['budget_rejection'] ?? null;
        $attempt = $saved['attempt_no'] ?? null;
        $invocation = Invocation::where('id', (int) ($saved['invocation_id'] ?? 0))->find();
        if ($review === null || !is_array($proof) || !is_int($attempt) || $attempt < 1
            || $attempt > (int) $review->attempt_count || $invocation === null
            || ($saved['state'] ?? null) !== 'failed' || ($saved['pre_dispatch_error'] ?? null) !== true
            || ($saved['error_code'] ?? null) !== 'SAND_AI_BUDGET_EXCEEDED'
            || ($proof['prompt_sha256'] ?? null) !== hash('sha256', json_encode($batch['messages'],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))
            || !(new MaterialBudgetRejectionEvidence())->matches($run, $task, $invocation,
                $this->identity($run, $batch['id'], $attempt, $final)->requestId(), 'cross_file_review', $attempt, $proof)) self::invalid();
    }

    /** Read-only projection for the existing authorized task retry transaction. */
    public function budgetStopRecovery(WorkflowRun $run, TaskRun $task): ?array
    {
        if (!$this->active($run, $task)) return null;
        $journal = $this->read((int) $task->id);
        $review = WorkflowCrossFileReview::where('workflow_run_id', (int) $run->id)->find();
        if ($journal === null || $review === null || ($journal['version'] ?? null) !== self::VERSION
            || ($journal['execution_snapshot_sha256'] ?? null) !== (string) $run->execution_snapshot_sha256) return null;
        $found = false;
        foreach ($journal['plan'] as $index => $batch) {
            $saved = $journal['nodes'][$batch['id']] ?? null;
            if (!is_array($saved)) return null;
            if (($saved['state'] ?? null) === 'succeeded' || ($saved['state'] ?? null) === 'pending') continue;
            $final = $index === count($journal['plan']) - 1;
            $batch = $this->batchInput($journal, $batch);
            if (is_array($saved['budget_rejection'] ?? null)) {
                $this->assertBudgetStop($run, $task, $batch, $saved, $final);
                $found = true;
                continue;
            }
            // Older journals had no proof marker and incorrectly called this unknown.
            if (!in_array($saved['state'] ?? null, ['running', 'recovery_pending', 'failed'], true)
                || !is_int($saved['attempt_no'] ?? null) || $saved['attempt_no'] !== (int) $review->attempt_count) return null;
            $identity = $this->identity($run, $batch['id'], $saved['attempt_no'], $final);
            $invocation = Invocation::where('environment_id', (int) $run->environment_id)->where('request_id', $identity->requestId())->find();
            if ($invocation === null || ($saved['invocation_id'] ?? null) !== (int) $invocation->id) return null;
            $proof = $this->captureBudgetStop($run, $task, $batch, $invocation, $saved['attempt_no'], $final);
            if ($proof === null) return null;
            $journal['nodes'][$batch['id']] = ['state' => 'failed', 'error_code' => 'SAND_AI_BUDGET_EXCEEDED',
                'budget_rejection' => $proof, 'pre_dispatch_error' => true] + $saved;
            $found = true;
        }
        if (!$found) return null;
        $settled = $this->settledDispatchIds($run, $task);
        foreach (InvocationDispatch::where('parent_workflow_run_id', (int) $run->id)
            ->where('parent_task_run_id', (int) $task->id)->where('workflow_node_code', 'cross_file_review')->select() as $dispatch) {
            if (!isset($settled[(int) $dispatch->id])) return null;
        }
        return $journal;
    }

    private static function pending(): never { throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'The original cross-file batch outcome is unresolved'); }
    private static function invalid(): never { throw new ApiProblem('SAND_AI_ARTIFACT_PROVENANCE_INVALID', 'The complete cross-file batch evidence changed'); }
}
