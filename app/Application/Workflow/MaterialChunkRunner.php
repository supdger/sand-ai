<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\OutcomeReceiptCipher;
use app\Application\Gateway\OutcomeReceiptService;
use app\Application\Gateway\PostgresOutcomeReceiptStore;
use app\Application\File\FileOwnershipGuard;
use app\Application\Task\TaskExecutionLease;
use app\Application\Usage\UsageAttribution;
use app\Infrastructure\Persistence\Model\AiFile;
use app\Infrastructure\Persistence\Model\Invocation;
use app\Infrastructure\Persistence\Model\InvocationDispatch;
use app\Infrastructure\Persistence\Model\TaskRun;
use app\Infrastructure\Persistence\Model\TaskStep;
use app\Infrastructure\Persistence\Model\SourceBlock;
use app\Infrastructure\Persistence\Model\UsageEvent;
use app\Infrastructure\Persistence\Model\WorkflowFileResult;
use app\Infrastructure\Persistence\Model\WorkflowRun;
use app\Infrastructure\Persistence\Model\WorkflowRunFile;

/** Executes a frozen file plan on the existing parent task and dispatch ledger. */
final class MaterialChunkRunner
{
    public function __construct(
        private readonly MaterialChunkPlan $planner = new MaterialChunkPlan(),
        private readonly MaterialChunkCheckpoint $checkpoints = new MaterialChunkCheckpoint(),
        private readonly MaterialChunkMergeExecutor $merger = new MaterialChunkMergeExecutor(),
        private readonly MaterialBudgetRejectionEvidence $budgetRejections = new MaterialBudgetRejectionEvidence(),
    ) {
    }

    /** The factory receives the private node id (null for the final file request) and its frozen attempt. */
    public function execute(array $profile, array $source, WorkflowRun $run, TaskRun $task,
        WorkflowRunFile $file, TaskExecutionLease $lease, \Closure $clientFactory,
        ?\Closure $heartbeat = null, bool $recovery = false): ?array
    {
        $plan = $this->planner->build($profile, $source);
        if ($plan === null) {
            if ($recovery) self::invalid();
            return (new MaterialAnalysisExecutor($clientFactory(null, (int) $file->attempt_count)))
                ->executeWithPrompt($profile, [$source]);
        }
        $this->planner->assertCoverage($plan, $source);
        $journal = $this->checkpoints->freeze($lease, $run, $file, $plan);
        foreach ($plan['nodes'] as $node) {
            $saved = $journal['nodes'][$node['id']];
            if (($saved['state'] ?? null) === 'succeeded') {
                $this->assertSavedNode($run, $task, $file, $node, $saved, $journal);
                continue;
            }
            if ($node['kind'] === 'leaf') {
                $leafFile = $this->planner->fileForLeaf($source, $node);
                $prompt = (new MaterialAnalysisPromptFactory())->build($profile, [$leafFile], true);
                $result = $this->providerNode($node['id'], false, $prompt, $run, $task, $file, $lease,
                    $journal, $clientFactory, static fn (MaterialAnalysisCompletionClient $client): array =>
                        (new NeuronMaterialAnalysisWorkflow(new NeuronMaterialAnalysisResources(
                            $profile, [$leafFile], $client, new MaterialAnalysisPromptFactory(), new MaterialAnalysisOutputValidator(),
                            new MaterialAnalysisProfilePolicy(), $prompt)))->analyze()['manifest'],
                    $heartbeat, $recovery);
                if ($result === null) return null;
                $journal = $result;
                continue;
            }
            $left = $this->checkpoints->manifest($journal['nodes'][$node['children'][0]]);
            $right = $this->checkpoints->manifest($journal['nodes'][$node['children'][1]]);
            try {
                $prepared = $this->merger->prepare($profile, [$source], $left, $right);
            } catch (ApiProblem $failure) {
                if ($failure->errorCode !== 'SAND_AI_MATERIAL_ANALYSIS_INPUT_TOO_LARGE') throw $failure;
                $this->checkpoints->record($lease, $run, $file, $plan['plan_sha256'], $node['id'], [
                    'state' => 'failed', 'attempt_no' => (int) $file->attempt_count,
                    'invocation_id' => null, 'pre_dispatch_error' => true, 'error_code' => $failure->errorCode]);
                throw $failure;
            }
            $journal = $this->checkpoints->freezeReviews($lease, $run, $file, $node['id'], $prepared['review_batches']);
            $reviews = [];
            $lastId = null;
            $lastPrompt = null;
            foreach ($prepared['review_batches'] as $index => $batch) {
                $reviewId = $node['id'] . '_review_' . sprintf('%06d', $index + 1);
                $lastId = $reviewId;
                $lastPrompt = $batch['prompt'];
                $final = $node['id'] === $plan['root_node'] && $index === count($prepared['review_batches']) - 1;
                $result = $this->providerNode($reviewId, $final, $batch['prompt'], $run, $task, $file, $lease,
                    $journal, $clientFactory, fn (MaterialAnalysisCompletionClient $client): array =>
                        $this->merger->review($profile, [$source], $batch, $client), $heartbeat, $recovery);
                if ($result === null) return null;
                $journal = $result;
                $reviews[] = $this->checkpoints->manifest($journal['nodes'][$reviewId]);
            }
            if ($lastId === null || !$lastPrompt instanceof MaterialAnalysisPrompt) self::invalid();
            $manifest = $this->merger->finalize($prepared['manifest'], $reviews);
            $last = $journal['nodes'][$lastId];
            $journal = $this->saveManifest($lease, $run, $file, $journal, $node['id'], $manifest,
                ['invocation_id' => $last['invocation_id'], 'attempt_no' => $last['attempt_no'],
                    'final_review_node' => $lastId, 'prompt_source_ids' => $lastPrompt->sourceBlockIds]);
        }
        $root = $journal['nodes'][$plan['root_node']];
        $this->assertSavedNode($run, $task, $file, $plan['nodes'][count($plan['nodes']) - 1], $root, $journal);
        return ['manifest' => $this->checkpoints->manifest($root),
            'prompt' => new MaterialAnalysisPrompt([], $root['prompt_source_ids']),
            'invocation_id' => $root['invocation_id'], 'chunk_plan' => $plan];
    }

    public function assertFileCheckpoint(WorkflowRun $run, TaskRun $task, WorkflowRunFile $file, array $journal): void
    {
        if (($journal['version'] ?? null) !== MaterialChunkCheckpoint::VERSION
            || ($journal['execution_snapshot_sha256'] ?? null) !== (string) $run->execution_snapshot_sha256
            || ($journal['parse_id'] ?? null) !== (int) $file->parse_id
            || ($journal['source_sha256'] ?? null) !== (string) $file->sha256) self::invalid();
        foreach ($journal['plan']['nodes'] as $node) {
            $saved = $journal['nodes'][$node['id']] ?? null;
            if (!is_array($saved) || ($saved['state'] ?? null) !== 'succeeded') self::invalid();
            $this->assertSavedNode($run, $task, $file, $node, $saved, $journal);
        }
    }

    /** A current budget failure requires proof; this hint never authorizes a retry. */
    public function hasCurrentBudgetStop(WorkflowRun $run, TaskRun $task): bool
    {
        foreach (WorkflowRunFile::where('workflow_run_id', (int) $run->id)->select() as $file) {
            if (!in_array((string) $file->analysis_state, ['failed', 'running', 'recovery_pending'], true)
                || (int) $file->attempt_count < 1
                || WorkflowFileResult::where('workflow_run_file_id', (int) $file->id)->find() !== null) continue;
            $journal = $this->checkpoints->read((int) $task->id, (int) $file->id);
            if (!is_array($journal) || !is_array($journal['nodes'] ?? null)) continue;
            foreach ($journal['nodes'] as $node) {
                if (!is_array($node) || !in_array($node['state'] ?? null, ['failed', 'running', 'recovery_pending'], true)
                    || ($node['error_code'] ?? null) !== 'SAND_AI_BUDGET_EXCEEDED') continue;
                $attempt = $node['attempt_no'] ?? null;
                if (!is_int($attempt) || $attempt < 1 || $attempt > (int) $file->attempt_count) self::invalid();
                if ($attempt === (int) $file->attempt_count) return true;
            }
        }
        return false;
    }

    /** Read-only reconstruction; the retry gateway owns the task/file/context CAS. */
    public function budgetStopRecovery(WorkflowRun $run, TaskRun $task): ?array
    {
        $input = is_object($run->input_snapshot) ? (array) $run->input_snapshot : $run->input_snapshot;
        $snapshot = is_object($run->execution_snapshot) ? (array) $run->execution_snapshot : $run->execution_snapshot;
        if (!is_array($input) || !is_array($snapshot)
            || (string) $run->workflow_code !== 'material_analysis'
            || ($input['material_execution_mode'] ?? null) !== 'single_file_v1'
            || ($input['material_chunk_policy'] ?? null) !== MaterialChunkCheckpoint::VERSION
            || !is_array($snapshot['analysis_profile'] ?? null) || !is_array($input['analysis_profile'] ?? null)
            || !hash_equals(StructuredWorkflowPolicy::fingerprint($snapshot['analysis_profile']),
                StructuredWorkflowPolicy::fingerprint($input['analysis_profile']))
            || !is_array($input['analysis_profile']['snapshot'] ?? null)) return null;
        $profile = (new MaterialAnalysisProfilePolicy())->validate($input['analysis_profile']['snapshot']);
        $attribution = is_object($run->attribution_snapshot) ? (array) $run->attribution_snapshot : $run->attribution_snapshot;
        try {
            $usage = UsageAttribution::fromCurrentPersisted(is_array($attribution) ? $attribution : null,
                (int) $run->environment_id, 'structured_workflow', 'sand_ai.workflow.run');
        } catch (\InvalidArgumentException) { return null; }
        $sources = [];
        $files = [];
        foreach (WorkflowRunFile::where('workflow_run_id', (int) $run->id)->order('id')->select() as $file) {
            $stored = AiFile::where('id', (int) $file->file_id)->where('environment_id', (int) $run->environment_id)
                ->where('owner_scope', FileOwnershipGuard::scope($usage))->where('status', 1)->find();
            if ($stored === null || (int) $file->status !== 1 || $file->delete_time !== null
                || !hash_equals((string) $file->sha256, (string) $stored->sha256)) return null;
            $blocks = [];
            foreach (SourceBlock::where('file_id', (int) $file->file_id)->where('parse_id', (int) $file->parse_id)
                ->where('status', 1)->order('sequence_no')->select() as $block) {
                $locator = is_object($block->locator) ? (array) $block->locator : $block->locator;
                $blocks[] = ['id' => (int) $block->id, 'sequence_no' => (int) $block->sequence_no,
                    'locator_type' => (string) $block->locator_type, 'locator' => is_array($locator) ? $locator : [],
                    'content' => (string) $block->content, 'content_sha256' => (string) $block->content_sha256];
            }
            $sources[(int) $file->id] = ['file_id' => (int) $file->file_id, 'parse_id' => (int) $file->parse_id,
                'sha256' => (string) $file->sha256, 'version_ref' => (string) $file->version_ref, 'blocks' => $blocks];
            $files[(int) $file->id] = $file;
        }
        (new ExecutionSnapshotBuilder())->assertRebuild($snapshot, (string) $run->execution_snapshot_sha256, array_values($sources));
        $journals = [];
        $stopped = [];
        foreach ($files as $fileId => $file) {
            $journal = $this->checkpoints->read((int) $task->id, $fileId);
            if ($journal === null) {
                if ((string) $file->analysis_state === 'recovery_pending') return null;
                continue;
            }
            $source = $sources[$fileId];
            $plan = $this->planner->build($profile, $source);
            if ($plan === null || ($journal['version'] ?? null) !== MaterialChunkCheckpoint::VERSION
                || ($journal['plan'] ?? null) !== $plan
                || ($journal['execution_snapshot_sha256'] ?? null) !== (string) $run->execution_snapshot_sha256
                || ($journal['parse_id'] ?? null) !== (int) $file->parse_id
                || ($journal['source_sha256'] ?? null) !== (string) $file->sha256) return null;
            $prompts = [];
            foreach ($plan['nodes'] as $node) {
                $saved = $journal['nodes'][$node['id']] ?? null;
                if (!is_array($saved)) return null;
                if (($saved['state'] ?? null) === 'succeeded') {
                    $this->assertSavedNode($run, $task, $file, $node, $saved, $journal);
                }
                if ($node['kind'] === 'leaf') {
                    $prompts[$node['id']] = [(new MaterialAnalysisPromptFactory())->build(
                        $profile, [$this->planner->fileForLeaf($source, $node)], true), false];
                } elseif (isset($saved['review_plan'])) {
                    $prepared = $this->merger->prepare($profile, [$source],
                        $this->checkpoints->manifest($journal['nodes'][$node['children'][0]]),
                        $this->checkpoints->manifest($journal['nodes'][$node['children'][1]]));
                    $reviewPlan = [];
                    foreach ($prepared['review_batches'] as $index => $batch) {
                        $id = $node['id'] . '_review_' . sprintf('%06d', $index + 1);
                        $prompt = $batch['prompt'];
                        $reviewPlan[$id] = ['prompt_sha256' => hash('sha256', json_encode($prompt->messages,
                            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
                            'source_block_ids' => $prompt->sourceBlockIds];
                        if (($journal['nodes'][$id]['input'] ?? null) !== $reviewPlan[$id]) return null;
                        $prompts[$id] = [$prompt, $node['id'] === $plan['root_node']
                            && $index === count($prepared['review_batches']) - 1];
                    }
                    if ($saved['review_plan'] !== $reviewPlan) return null;
                }
            }
            $changed = false;
            $currentBudgetStop = false;
            foreach ($journal['nodes'] as $journalNodeId => $saved) {
                if (!isset($prompts[$journalNodeId]) && !in_array($journalNodeId, array_column($plan['nodes'], 'id'), true)) return null;
                $state = $saved['state'] ?? null;
                if ($state === 'succeeded') {
                    if (isset($prompts[$journalNodeId])) $this->assertProviderNode($run, $task, $file, (string) $journalNodeId, $saved, $prompts[$journalNodeId][1]);
                    continue;
                }
                if ($state === 'pending' && ($saved['attempt_no'] ?? null) === null
                    && ($saved['invocation_id'] ?? null) === null) continue;
                if ($state === 'failed') {
                    if (($saved['error_code'] ?? null) === 'SAND_AI_BUDGET_EXCEEDED') {
                        if (!isset($prompts[$journalNodeId])
                            || ($saved['prompt_sha256'] ?? null) !== hash('sha256', json_encode($prompts[$journalNodeId][0]->messages,
                                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))
                            || ($saved['legacy_identity'] ?? null) !== $prompts[$journalNodeId][1]
                            || !$this->budgetFailedNode($run, $task, $file, (string) $journalNodeId, $saved)) return null;
                        if (($saved['attempt_no'] ?? null) === (int) $file->attempt_count
                            && in_array((string) $file->analysis_state, ['failed', 'recovery_pending'], true)) {
                            $currentBudgetStop = true;
                        }
                    }
                    continue;
                }
                if (!in_array($state, ['running', 'recovery_pending'], true) || !isset($prompts[$journalNodeId])
                    || ($saved['attempt_no'] ?? null) !== (int) $file->attempt_count) return null;
                [$prompt, $final] = $prompts[$journalNodeId];
                $promptHash = hash('sha256', json_encode($prompt->messages,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                if (($saved['prompt_sha256'] ?? null) !== $promptHash
                    || ($saved['legacy_identity'] ?? null) !== $final) return null;
                $journalNodeIdentity = $final ? new MaterialAnalysisFileAttemptIdentity((string) $run->logical_execution_id, $fileId, (int) $file->attempt_count)
                    : new MaterialChunkAttemptIdentity((string) $run->logical_execution_id, $fileId, (string) $journalNodeId, (int) $file->attempt_count);
                $invocation = Invocation::where('environment_id', (int) $run->environment_id)->where('request_id', $journalNodeIdentity->requestId())->find();
                if ($invocation === null || (isset($saved['invocation_id']) && $saved['invocation_id'] !== (int) $invocation->id)) return null;
                $proof = $this->captureBudgetRejection($run, $task, $file, $invocation, (string) $journalNodeId,
                    (int) $file->attempt_count, $final, $prompt);
                if ($proof === null) return null;
                $journal['nodes'][$journalNodeId] = ['state' => 'failed', 'invocation_id' => (int) $invocation->id,
                    'budget_rejection' => $proof, 'pre_dispatch_error' => true,
                    'error_code' => 'SAND_AI_BUDGET_EXCEEDED'] + $saved;
                $changed = true;
                $currentBudgetStop = true;
            }
            if ($currentBudgetStop) {
                if ((new MaterialChunkDispatchEvidence())->unresolved($run, $task, $file)) return null;
                if ($changed) $journals[$fileId] = $journal;
                $stopped[$fileId] = (int) $file->attempt_count;
            }
        }
        return $stopped === [] ? null : ['journals' => $journals, 'files' => $stopped];
    }

    private function providerNode(string $id, bool $final, MaterialAnalysisPrompt $prompt,
        WorkflowRun $run, TaskRun $task, WorkflowRunFile $file, TaskExecutionLease $lease,
        array $journal, \Closure $factory, \Closure $execute, ?\Closure $heartbeat, bool $recovery): ?array
    {
        $saved = $journal['nodes'][$id];
        $promptSha256 = hash('sha256', json_encode($prompt->messages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        if (isset($saved['prompt_sha256']) && !hash_equals((string) $saved['prompt_sha256'], $promptSha256)) self::invalid();
        if (($saved['state'] ?? null) === 'succeeded') {
            $this->assertProviderNode($run, $task, $file, $id, $saved, $final);
            $this->checkpoints->manifest($saved);
            return $journal;
        }
        if (($saved['error_code'] ?? null) === 'SAND_AI_BUDGET_EXCEEDED'
            && ($saved['state'] ?? null) === 'failed'
            && !$this->budgetFailedNode($run, $task, $file, $id, $saved)) self::invalid();
        $attempt = ($saved['state'] ?? null) === 'failed' && ($saved['attempt_no'] ?? null) < (int) $file->attempt_count
            ? (int) $file->attempt_count : ($saved['attempt_no'] ?? (int) $file->attempt_count);
        if (!is_int($attempt) || $attempt < 1 || $attempt > (int) $file->attempt_count) self::invalid();
        $identity = $final ? new MaterialAnalysisFileAttemptIdentity((string) $run->logical_execution_id, (int) $file->id, $attempt)
            : new MaterialChunkAttemptIdentity((string) $run->logical_execution_id, (int) $file->id, $id, $attempt);
        $invocation = Invocation::where('environment_id', (int) $run->environment_id)
            ->where('request_id', $identity->requestId())->find();
        $dispatch = $invocation === null ? null : InvocationDispatch::where('invocation_id', (int) $invocation->id)->find();
        if ($invocation !== null && (string) $invocation->error_code === 'SAND_AI_BUDGET_EXCEEDED') {
            $proof = $this->captureBudgetRejection($run, $task, $file, $invocation, $id, $attempt, $final, $prompt);
            if ($proof === null) self::pending();
            $this->checkpoints->record($lease, $run, $file, $journal['plan']['plan_sha256'], $id,
                ['state' => 'failed', 'attempt_no' => $attempt, 'invocation_id' => (int) $invocation->id,
                    'legacy_identity' => $final, 'prompt_sha256' => $promptSha256,
                    'budget_rejection' => $proof, 'pre_dispatch_error' => true,
                    'error_code' => 'SAND_AI_BUDGET_EXCEEDED']);
            throw new ApiProblem('SAND_AI_BUDGET_EXCEEDED', 'The original material node was stopped before dispatch');
        }
        // A previous unclaimed/prepared attempt has not crossed the provider boundary.
        // A visible or unknown attempt keeps its original identity even when the parent generation advances.
        if ($attempt < (int) $file->attempt_count && ($saved['state'] ?? null) !== 'recovery_pending'
            && ($invocation === null || ($dispatch !== null && in_array((string) $dispatch->state, ['prepared', 'not_dispatched'], true)))) {
            $attempt = (int) $file->attempt_count;
            $identity = $final ? new MaterialAnalysisFileAttemptIdentity((string) $run->logical_execution_id, (int) $file->id, $attempt)
                : new MaterialChunkAttemptIdentity((string) $run->logical_execution_id, (int) $file->id, $id, $attempt);
            $invocation = Invocation::where('environment_id', (int) $run->environment_id)->where('request_id', $identity->requestId())->find();
            $dispatch = $invocation === null ? null : InvocationDispatch::where('invocation_id', (int) $invocation->id)->find();
        }
        if (($saved['state'] ?? null) === 'failed' && ($saved['attempt_no'] ?? null) === $attempt) {
            throw new ApiProblem((string) $saved['error_code'], 'The original material node requires an explicit failed-file retry');
        }
        // A committed not_dispatched terminal is recoverable failure evidence, never a new send permission.
        if ($invocation !== null && $dispatch !== null
            && $this->notDispatchedFailure($run, $task, $file, $invocation, $dispatch, $id, $attempt, $final)) {
            $code = (string) $invocation->error_code;
            $this->checkpoints->record($lease, $run, $file, $journal['plan']['plan_sha256'], $id,
                ['state' => 'failed', 'attempt_no' => $attempt, 'invocation_id' => (int) $invocation->id,
                    'legacy_identity' => $final, 'error_code' => $code]);
            throw new ApiProblem($code, 'The original material node was not dispatched; explicit retry is required');
        }
        if ($invocation !== null && (string) $invocation->state === 'failed' && $dispatch !== null && (string) $dispatch->state === 'failed') {
            $this->assertIdentity($run, $task, $file, $invocation, $dispatch, $id, $attempt, $final);
            $code = (string) $invocation->error_code;
            if ($code !== 'SAND_AI_PROVIDER_REJECTED') self::pending();
            $this->checkpoints->record($lease, $run, $file, $journal['plan']['plan_sha256'], $id,
                ['state' => 'failed', 'attempt_no' => $attempt, 'invocation_id' => (int) $invocation->id,
                    'legacy_identity' => $final, 'error_code' => $code]);
            throw new ApiProblem($code, 'The original material node was rejected');
        }
        if ($invocation !== null && (string) $invocation->state === 'succeeded' && $dispatch !== null && (string) $dispatch->state === 'succeeded') {
            $this->assertIdentity($run, $task, $file, $invocation, $dispatch, $id, $attempt, $final);
            $client = $this->receiptClient($invocation);
        } else {
            $visible = $dispatch !== null && in_array((string) $dispatch->state, ['dispatching', 'ambiguous', 'succeeded'], true);
            if ($visible || ($saved['state'] ?? null) === 'recovery_pending') {
                $this->checkpoints->record($lease, $run, $file, $journal['plan']['plan_sha256'], $id,
                    ['state' => 'recovery_pending', 'attempt_no' => $attempt,
                        'invocation_id' => $invocation === null ? null : (int) $invocation->id, 'legacy_identity' => $final]);
                self::pending();
            }
            if ($recovery) return null;
            $client = $factory($final ? null : $id, $attempt);
        }
        $journal = $this->checkpoints->record($lease, $run, $file, $journal['plan']['plan_sha256'], $id,
            ['state' => 'running', 'attempt_no' => $attempt, 'legacy_identity' => $final,
                'budget_rejection' => null, 'pre_dispatch_error' => false, 'error_code' => null,
                'prompt_sha256' => hash('sha256', json_encode($prompt->messages,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))]);
        $heartbeat?->__invoke();
        try {
            $manifest = $execute($client);
        } catch (MaterialAnalysisOutputRejected|ApiProblem $failure) {
            $invocation = Invocation::where('environment_id', (int) $run->environment_id)->where('request_id', $identity->requestId())->find();
            $dispatch = $invocation === null ? null : InvocationDispatch::where('invocation_id', (int) $invocation->id)->find();
            $code = $failure->errorCode;
            $notDispatched = $invocation !== null && $dispatch !== null
                && $this->notDispatchedFailure($run, $task, $file, $invocation, $dispatch, $id, $attempt, $final);
            if ($notDispatched && (string) $invocation->error_code !== $code) self::invalid();
            $known = $invocation !== null && $dispatch !== null
                && ($notDispatched || ($failure instanceof MaterialAnalysisOutputRejected && (string) $invocation->state === 'succeeded' && (string) $dispatch->state === 'succeeded')
                    || ($code === 'SAND_AI_PROVIDER_REJECTED' && (string) $invocation->state === 'failed' && (string) $dispatch->state === 'failed'));
            if ($known) $this->assertIdentity($run, $task, $file, $invocation, $dispatch, $id, $attempt, $final);
            $budgetProof = $code === 'SAND_AI_BUDGET_EXCEEDED' && $invocation !== null
                ? $this->captureBudgetRejection($run, $task, $file, $invocation, $id, $attempt, $final, $prompt) : null;
            $this->checkpoints->record($lease, $run, $file, $journal['plan']['plan_sha256'], $id,
                ['state' => $known || $budgetProof !== null ? 'failed' : 'recovery_pending', 'attempt_no' => $attempt,
                    'invocation_id' => $invocation === null ? null : (int) $invocation->id,
                    'legacy_identity' => $final, 'error_code' => $code,
                    'budget_rejection' => $budgetProof, 'pre_dispatch_error' => $budgetProof !== null]);
            throw $failure;
        }
        $heartbeat?->__invoke();
        $invocation = Invocation::where('environment_id', (int) $run->environment_id)->where('request_id', $identity->requestId())->find();
        $dispatch = $invocation === null ? null : InvocationDispatch::where('invocation_id', (int) $invocation->id)->find();
        if ($invocation === null || $dispatch === null || (string) $invocation->state !== 'succeeded' || (string) $dispatch->state !== 'succeeded') self::pending();
        $this->assertIdentity($run, $task, $file, $invocation, $dispatch, $id, $attempt, $final);
        return $this->saveManifest($lease, $run, $file, $journal, $id, $manifest,
            ['attempt_no' => $attempt, 'invocation_id' => (int) $invocation->id, 'legacy_identity' => $final]);
    }

    /** A failed admission has no dispatch to settle; validate its original node key instead. */
    public function budgetFailedNode(WorkflowRun $run, TaskRun $task, WorkflowRunFile $file, string $id, array $saved): bool
    {
        $attempt = $saved['attempt_no'] ?? null;
        $proof = $saved['budget_rejection'] ?? null;
        $final = $saved['legacy_identity'] ?? null;
        if (($saved['state'] ?? null) !== 'failed' || ($saved['error_code'] ?? null) !== 'SAND_AI_BUDGET_EXCEEDED'
            || ($saved['pre_dispatch_error'] ?? null) !== true || !is_array($proof)
            || !is_int($attempt) || $attempt < 1 || $attempt > (int) $file->attempt_count || !is_bool($final)
            || ($saved['prompt_sha256'] ?? null) !== ($proof['prompt_sha256'] ?? null)
            || ($saved['invocation_id'] ?? null) !== ($proof['invocation_id'] ?? null)) return false;
        $identity = $final ? new MaterialAnalysisFileAttemptIdentity((string) $run->logical_execution_id, (int) $file->id, $attempt)
            : new MaterialChunkAttemptIdentity((string) $run->logical_execution_id, (int) $file->id, $id, $attempt);
        $invocation = Invocation::where('id', (int) $saved['invocation_id'])->find();
        $step = TaskStep::where('task_id', (int) $task->id)->where('sequence_no', 1)->find();
        $journal = $this->checkpoints->read((int) $task->id, (int) $file->id);
        $root = is_array($journal) ? ($journal['nodes'][$journal['plan']['root_node'] ?? ''] ?? null) : null;
        $reviewPlan = is_array($root) ? ($root['review_plan'] ?? null) : null;
        $expectedFinal = is_array($reviewPlan) && $id === array_key_last($reviewPlan);
        if ($final !== $expectedFinal) return false;
        return $invocation !== null && $step !== null
            && $this->budgetRejections->matches($run, $task, $invocation, $identity->requestId(),
                $final ? (string) $step->step_code : 'material_' . $id, $attempt, $proof);
    }

    private function captureBudgetRejection(WorkflowRun $run, TaskRun $task, WorkflowRunFile $file,
        Invocation $invocation, string $id, int $attempt, bool $final, MaterialAnalysisPrompt $prompt): ?array
    {
        $config = config('sand_ai_material_analysis_dispatch');
        $tokens = is_array($config) ? ($config['max_output_tokens'] ?? null) : null;
        $step = TaskStep::where('task_id', (int) $task->id)->where('sequence_no', 1)->find();
        if (!is_int($tokens) || $tokens < 1 || $step === null) return null;
        $identity = $final ? new MaterialAnalysisFileAttemptIdentity((string) $run->logical_execution_id, (int) $file->id, $attempt)
            : new MaterialChunkAttemptIdentity((string) $run->logical_execution_id, (int) $file->id, $id, $attempt);
        return $this->budgetRejections->capture($run, $task, $invocation, $identity->requestId(),
            $final ? (string) $step->step_code : 'material_' . $id, $attempt, $prompt->messages, $tokens);
    }

    private function saveManifest(TaskExecutionLease $lease, WorkflowRun $run, WorkflowRunFile $file,
        array $journal, string $id, array $manifest, array $facts): array
    {
        $json = json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return $this->checkpoints->record($lease, $run, $file, $journal['plan']['plan_sha256'], $id,
            ['state' => 'succeeded', 'output_json' => $json, 'output_sha256' => hash('sha256', $json),
                'payload_sha256' => MaterialAnalysisFilePayloadDigest::version2($manifest['payload']), 'error_code' => null] + $facts);
    }

    private function assertSavedNode(WorkflowRun $run, TaskRun $task, WorkflowRunFile $file,
        array $node, array $saved, array $journal): void
    {
        $this->checkpoints->manifest($saved);
        if ($node['kind'] === 'leaf') {
            $this->assertProviderNode($run, $task, $file, $node['id'], $saved, false);
            return;
        }
        $spec = $saved['review_plan'] ?? null;
        if (!is_array($spec) || $spec === []) self::invalid();
        foreach ($spec as $id => $input) {
            $review = $journal['nodes'][$id] ?? null;
            if (!is_array($review) || ($review['input'] ?? null) !== $input) self::invalid();
            $this->assertProviderNode($run, $task, $file, $id, $review, (bool) ($review['legacy_identity'] ?? false));
            $this->checkpoints->manifest($review);
        }
    }

    /** Durable terminal evidence only. No fixture record, timeout or local exception proves an unsent call. */
    public function notDispatchedFailure(WorkflowRun $run, TaskRun $task, WorkflowRunFile $file,
        Invocation $invocation, InvocationDispatch $dispatch, string $nodeId, int $attempt, bool $final): bool
    {
        if ((string) $dispatch->state !== 'not_dispatched') return false;
        $this->assertIdentity($run, $task, $file, $invocation, $dispatch, $nodeId, $attempt, $final);
        $code = (string) $invocation->error_code;
        if ((string) $invocation->state !== 'failed' || $invocation->completed_at === null
            || $dispatch->dispatch_complete_time === null || $code === ''
            || (string) $dispatch->error_code !== $code
            || UsageEvent::where('invocation_id', (int) $invocation->id)->find() !== null
            || \think\facade\Db::connect('pgsql')->table('sand_ai_provider_response_identity')
                ->where('dispatch_id', (int) $dispatch->id)->find() !== null) self::invalid();
        return true;
    }

    public function assertProviderNode(WorkflowRun $run, TaskRun $task, WorkflowRunFile $file,
        string $id, array $saved, bool $final): void
    {
        $invocation = Invocation::where('id', (int) ($saved['invocation_id'] ?? 0))->find();
        $dispatch = $invocation === null ? null : InvocationDispatch::where('invocation_id', (int) $invocation->id)->find();
        if ($invocation === null || $dispatch === null || ($saved['state'] ?? null) !== 'succeeded'
            || (string) $invocation->state !== 'succeeded' || (string) $dispatch->state !== 'succeeded') self::invalid();
        $this->assertIdentity($run, $task, $file, $invocation, $dispatch, $id, (int) $saved['attempt_no'], $final);
    }

    public function assertIdentity(WorkflowRun $run, TaskRun $task, WorkflowRunFile $file,
        Invocation $invocation, InvocationDispatch $dispatch, string $nodeId, int $attempt, bool $final): void
    {
        $identity = $final ? new MaterialAnalysisFileAttemptIdentity((string) $run->logical_execution_id, (int) $file->id, $attempt)
            : new MaterialChunkAttemptIdentity((string) $run->logical_execution_id, (int) $file->id, $nodeId, $attempt);
        $step = TaskStep::where('task_id', (int) $task->id)->where('sequence_no', 1)->find();
        if ($step === null || $attempt < 1 || $attempt > (int) $file->attempt_count) self::invalid();
        $node = $final ? (string) $step->step_code : 'material_' . $nodeId;
        foreach (['environment_id', 'organization_id', 'application_id', 'workload_client_id'] as $field) {
            if ((int) $run->getAttr($field) < 1 || (int) $invocation->getAttr($field) !== (int) $run->getAttr($field)) self::invalid();
        }
        foreach (['parent_workflow_run_id' => (int) $run->id, 'parent_task_run_id' => (int) $task->id,
            'parent_task_step_id' => (int) $step->id, 'parent_workflow_run_file_id' => (int) $file->id] as $field => $expected) {
            if ((int) $invocation->getAttr($field) !== $expected || (int) $dispatch->getAttr($field) !== $expected) self::invalid();
        }
        if ((int) $invocation->status !== 1 || $invocation->delete_time !== null
            || (int) $invocation->execution_attempt_no !== $attempt || (int) $dispatch->attempt_no !== $attempt
            || (string) $invocation->workflow_node_code !== $node || (string) $dispatch->workflow_node_code !== $node
            || (int) $dispatch->invocation_id !== (int) $invocation->id
            || (int) $dispatch->environment_id !== (int) $run->environment_id
            || (int) $dispatch->application_id !== (int) $run->application_id
            || (string) $dispatch->execution_scope !== 'structured_workflow'
            || (string) $dispatch->service_action !== 'sand_ai.workflow.run'
            || (string) $dispatch->source_kind !== 'structured_workflow'
            || (string) $dispatch->capability_code !== 'structured_extraction'
            || (int) $dispatch->model_id !== (int) $invocation->model_id || (int) $dispatch->deployment_id !== (int) $invocation->deployment_id
            || !hash_equals($identity->requestId(), (string) $invocation->request_id)
            || !hash_equals($identity->dispatchLogicalExecutionId(), (string) $invocation->logical_execution_id)
            || !hash_equals($identity->dispatchLogicalExecutionId(), (string) $dispatch->logical_execution_id)
            || !hash_equals(hash('sha256', $identity->requestId()), (string) $dispatch->logical_request_id)
            || !hash_equals((string) $run->execution_snapshot_sha256, (string) $invocation->execution_snapshot_sha256)
            || !hash_equals((string) $run->execution_snapshot_sha256, (string) $dispatch->execution_snapshot_sha256)) self::invalid();
        if ((string) $invocation->state === 'succeeded') {
            $usage = UsageEvent::where('invocation_id', (int) $invocation->id)->find();
            if ($usage === null || (string) $usage->idempotency_key !== 'invocation:' . (int) $invocation->id) self::invalid();
            foreach (['environment_id', 'organization_id', 'application_id', 'workload_client_id'] as $field) {
                if ((int) $usage->getAttr($field) !== (int) $run->getAttr($field)) self::invalid();
            }
            if ((int) $usage->model_id !== (int) $invocation->model_id || (int) $usage->deployment_id !== (int) $invocation->deployment_id
                || (string) $usage->service_action !== 'sand_ai.workflow.run' || (string) $usage->source_kind !== 'structured_workflow'
                || (string) $usage->capability_code !== 'structured_extraction') self::invalid();
        }
    }

    private function receiptClient(Invocation $invocation): MaterialAnalysisCompletionClient
    {
        $config = config('sand_ai_outcome_receipt');
        if (!is_array($config)) throw new ApiProblem('SAND_AI_RECEIPT_UNAVAILABLE', 'The original material node receipt is unavailable');
        $content = (new OutcomeReceiptService(new PostgresOutcomeReceiptStore(), new OutcomeReceiptCipher($config),
            (int) ($config['ttl_seconds'] ?? 0)))->replay([
                'environment_id' => (int) $invocation->environment_id, 'invocation_id' => (int) $invocation->id,
                'request_fingerprint' => (string) $invocation->request_fingerprint, 'capability_code' => 'structured_extraction',
                'model_id' => (int) $invocation->model_id, 'deployment_id' => (int) $invocation->deployment_id,
                'version' => OutcomeReceiptCipher::RECEIPT_VERSION]);
        return new class(StructuredProviderOutcome::content($content)) implements MaterialAnalysisCompletionClient {
            public function __construct(private readonly string $content) {}
            public function complete(array $messages): string { return $this->content; }
        };
    }

    private static function pending(): never { throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'The original material node outcome is unresolved'); }
    private static function invalid(): never { throw new ApiProblem('SAND_AI_ARTIFACT_PROVENANCE_INVALID', 'Material chunk evidence changed'); }
}
