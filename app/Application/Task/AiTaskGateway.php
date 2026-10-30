<?php

declare(strict_types=1);

namespace app\Application\Task;

use app\Api\Support\ApiProblem;
use app\Application\Audio\MeetingSummaryEvidence;
use app\Application\Audio\MeetingSummaryOutputContract;
use app\Application\Audio\MeetingLocalPlanning;
use app\Application\Gateway\DispatchRouteSnapshot;
use app\Application\Skill\SkillOutputStrategy;
use app\Infrastructure\Persistence\Model\ConfigRevision;
use app\Infrastructure\Persistence\Model\ModelDeployment;
use app\Application\Audio\MeetingSummaryCapacity;
use app\Application\Audio\MeetingExecutionRuntime;
use app\Application\Audio\MeetingSummaryPlan;
use app\Application\Audio\MeetingSummarySource;
use app\Application\Audio\AudioTranscriptSealLedger;
use app\Application\Audio\MeetingSummaryRunner;
use app\Application\Audio\PostgresMeetingDurableWorker;
use app\Application\Audio\MeetingSummaryRoute;
use app\Application\Gateway\ChatGateway;
use app\Application\Gateway\DispatchExecutionScope;
use app\Application\Gateway\RequestIdempotencyKey;
use app\Application\Gateway\RequestPayloadFingerprint;
use app\Application\Usage\UsageAttribution;
use app\Identity\Infrastructure\AiTaskSourceFacts;
use app\Identity\Infrastructure\AudioTaskAccessGuard;
use app\Identity\Infrastructure\TaskRunAccessGuard;
use app\Infrastructure\Persistence\Model\AiFile;
use app\Infrastructure\Persistence\Model\AuditLog;
use app\Infrastructure\Persistence\Model\SourceBlock;
use app\Infrastructure\Persistence\Model\TaskRun;
use app\Infrastructure\Persistence\Model\TaskStep;
use support\Log;
use think\facade\Db;
use Throwable;

final class AiTaskGateway
{
    private ?\Closure $sourceBlockLoader;

    public function __construct(
        ?callable $sourceBlockLoader = null,
        private readonly ?ChatGateway $chatGateway = null,
    )
    {
        $this->sourceBlockLoader = $sourceBlockLoader === null ? null : \Closure::fromCallable($sourceBlockLoader);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function submit(int $environmentId, array $input, string $requestId, ?UsageAttribution $attribution = null, ?\Closure $authorizeTask = null, ?array $meetingSource = null): array
    {
        if ($attribution === null || $attribution->organizationId === null
            || $attribution->applicationId === null || $attribution->workloadClientId === null
            || $authorizeTask === null) {
            throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'AI task authorization facts are unavailable');
        }
        $meetingPlanning = null;
        $durable = false;
        if ($meetingSource !== null) {
            $meetingSource = self::meetingSource($meetingSource);
            $oldMeetingTask = TaskRun::where('request_id', $requestId)->find();
            $durable = $oldMeetingTask === null ? MeetingExecutionRuntime::available() : MeetingExecutionRuntime::forTask($oldMeetingTask);
            $source = $durable
                ? (new MeetingSummarySource())->current($environmentId, $meetingSource['audio_task_id'], $attribution,
                    $meetingSource['sha256'], $meetingSource['review_revision'], $meetingSource['transcript_seal'], $meetingSource['aggregation_source'])
                : (new MeetingSummarySource())->load($environmentId, $meetingSource['audio_task_id'], $attribution,
                    $meetingSource['sha256'], $meetingSource['review_revision'], true);
            self::assertMeetingFacts($meetingSource, $source);
            $meetingSource['total_segments'] = $source['total_segments'];
            $meetingSource['eligible_segments'] = $source['eligible_segments'];
            if ($source['eligible_segments'] !== null && $source['eligible_segments'] < 1) {
                throw new ApiProblem('SAND_AI_AUDIO_SUMMARY_SOURCE_INVALID', '当前转写没有可整理的文字，请先回听并复核片段后再创建摘要');
            }
            $meetingSource['transcript_seal'] = $source['transcript_seal'];
            if (($input['messages'] ?? null) !== MeetingSummarySource::taskMarker(
                $meetingSource['audio_task_id'], $meetingSource['sha256'],
            )) {
                throw new ApiProblem('SAND_AI_AUDIO_SUMMARY_SOURCE_INVALID', 'The meeting summary task input is invalid');
            }
            // Submission only freezes local published facts. Tokenization is
            // queued and durably accounted after task authorization and claim.
            if (TaskRun::where('request_id', $requestId)->find() === null) {
                (new AudioTaskAccessGuard())->revalidateQueued($meetingSource['audio_authorization'], $source['task']);
                $business = AiTaskPolicy::normalizeInput($input);
                $route = (new MeetingSummaryRoute())->freeze($environmentId, $business['model'], $business['max_output_tokens']);
                $capacity = MeetingSummaryCapacity::freeze($route, min(4096, $business['max_output_tokens'] ?? 4096));
                $meetingPlanning = ['route' => $route, 'capacity' => $capacity];
            }
        }
        $prepared = Db::connect('pgsql')->transaction(function () use ($environmentId, $input, $requestId, $attribution, $meetingSource, $meetingPlanning, $durable): array {
            if ($meetingSource !== null) {
                $file = AiFile::where('id', $meetingSource['file_id'])
                    ->where('environment_id', $environmentId)->lock(true)->find();
                if ($file === null || (string) $file->state !== 'uploaded'
                    || (int) $file->status !== 1 || $file->delete_time !== null) {
                    throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Private audio source not found');
                }
                $source = $durable
                    ? (new MeetingSummarySource())->current($environmentId, $meetingSource['audio_task_id'], $attribution,
                        $meetingSource['sha256'], $meetingSource['review_revision'], $meetingSource['transcript_seal'], $meetingSource['aggregation_source'])
                    : (new MeetingSummarySource())->load($environmentId, $meetingSource['audio_task_id'], $attribution,
                        $meetingSource['sha256'], $meetingSource['review_revision'], true);
                self::assertMeetingFacts($meetingSource, $source);
            }
            $existing = TaskRun::where('request_id', $requestId)->find();
            if ($existing !== null) {
                if ((int) $existing->environment_id !== $environmentId || (string) $existing->task_type !== 'ai_completion') {
                    throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'The request id belongs to another task');
                }
                $this->resolveSubmissionInput($environmentId, $input, $existing->input, $attribution);
                self::assertMeetingReplay($existing->input, $meetingSource);
                return ['idempotent_replay' => true, 'task_id' => (int) $existing->id];
            }
            $input = $this->resolveSubmissionInput($environmentId, $input, null, $attribution)['input'];
            if ($meetingSource === null) {
                self::lockSourceFiles($environmentId, $input['source_block_ids']);
            }
            if ($meetingSource !== null) {
                if ($meetingPlanning === null) {
                    throw new ApiProblem('SAND_AI_AUDIO_SUMMARY_CAPACITY_UNAVAILABLE', '原会议任务在预检期间发生变化，请携原请求键重试，不会发送摘要调用');
                }
                $input['_meeting_summary'] = self::meetingSource($meetingSource);
                $currentRoute = (new MeetingSummaryRoute())->freeze(
                    $environmentId, $input['model'], $input['max_output_tokens'],
                );
                MeetingSummaryRoute::assertSame($meetingPlanning['route'], $currentRoute);
                if (($meetingPlanning['capacity']['version'] ?? null) === MeetingExecutionRuntime::CAPACITY_VERSION) {
                    $confirmedCapacity = MeetingSummaryCapacity::freeze($currentRoute, $meetingPlanning['capacity']['max_output_tokens'], true);
                    if (!hash_equals($confirmedCapacity['snapshot_sha256'], $meetingPlanning['capacity']['snapshot_sha256'])) {
                        throw new ApiProblem('SAND_AI_MEETING_EXECUTION_CONFLICT', '会议执行设置在任务预检期间变化，请重新确认后提交');
                    }
                }
                $input['_meeting_route'] = $meetingPlanning['route'];
                $input['_meeting_capacity'] = $meetingPlanning['capacity'];
                $input['_meeting_summary']['planner_version'] = MeetingSummaryPlan::CAPACITY_VERSION;
                if (MeetingExecutionRuntime::forInput($input)) {
                    $input['_meeting_durable'] = true;
                    $input['_meeting_summary']['output_contract_revision'] = 2;
                    $deploymentConfig = self::meetingDeploymentConfig($currentRoute);
                    if (MeetingLocalPlanning::enabled($input['_meeting_capacity'])
                        && SkillOutputStrategy::isStructured($deploymentConfig['skill_output_strategy'] ?? null)) {
                        $input['_meeting_summary']['output_contract'] = MeetingSummaryOutputContract::forNewTask(
                            $deploymentConfig, $currentRoute['provider_adapter'], $input['_meeting_capacity'],
                        );
                    }
                }
            }
            $dataClass = $meetingSource === null
                ? (new AiTaskSourceFacts())->resolve($environmentId, $input['source_block_ids'], $attribution)
                : $meetingSource['data_class'];
            $input['_resource_data_class'] = $dataClass;

            $task = TaskRun::create([
                'environment_id' => $environmentId,
                'organization_id' => $attribution->organizationId,
                'application_id' => $attribution->applicationId,
                'workload_client_id' => $attribution->workloadClientId,
                'data_class' => $dataClass,
                'task_type' => 'ai_completion',
                'resource_type' => 'ai_task',
                'resource_id' => 0,
                'request_id' => $requestId,
                'state' => TaskState::AUTHORIZATION_PENDING,
                'attempt_count' => 0,
                'max_attempts' => max(1, (int) config('sand_ai_task.max_attempts', 3)),
                'available_at' => date('Y-m-d H:i:s'),
                'input' => $input,
                'status' => 1,
            ]);
            $task->save(['resource_id' => (int) $task->id]);
            TaskStep::create([
                'task_id' => (int) $task->id,
                'step_code' => 'invoke_model',
                'sequence_no' => 1,
                'state' => TaskState::AUTHORIZATION_PENDING,
                'attempt_count' => 0,
                'context' => ['model' => $input['model'], 'source_block_count' => count($input['source_block_ids'])],
                'status' => 1,
            ]);
            $this->audit($environmentId, 'ai_task.authorization_pending', (int) $task->id, 'SandAI AI completion task awaits authorization', [
                'model' => $input['model'],
                'source_block_count' => count($input['source_block_ids']),
                'message_count' => count($input['messages']),
            ]);

            return ['idempotent_replay' => false, 'task_id' => (int) $task->id];
        });
        try {
            $authorization = $authorizeTask($prepared['task_id']);
            if (!is_array($authorization)) {
                throw new ApiProblem('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE', 'AI task authorization is unavailable');
            }
        } catch (Throwable $failure) {
            if (!$prepared['idempotent_replay']) {
                try {
                    $this->rejectPendingAuthorization($prepared['task_id'], $failure instanceof ApiProblem
                        ? $failure->errorCode : 'SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE');
                } catch (Throwable $cleanupFailure) {
                    Log::warning('SandAI AI task authorization cleanup failed', [
                        'task_id' => $prepared['task_id'],
                        'exception_type' => $cleanupFailure::class,
                    ]);
                }
            }
            throw $failure;
        }
        return $this->activateAuthorizedTask(
            $prepared['task_id'], $environmentId, $authorization, $prepared['idempotent_replay'],
        );
    }

    /** @param array<string,mixed> $authorization @return array<string,mixed> */
    private function activateAuthorizedTask(int $taskId, int $environmentId, array $authorization, bool $replay): array
    {
        return Db::connect('pgsql')->transaction(function () use ($taskId, $environmentId, $authorization, $replay): array {
            $task = TaskRun::where('id', $taskId)->where('environment_id', $environmentId)
                ->where('task_type', 'ai_completion')->lock(true)->find();
            if ($task === null || (string) $task->resource_type !== 'ai_task' || (int) $task->resource_id !== $taskId) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Authorized AI task changed before enqueue');
            }
            $input = is_object($task->input) ? (array) $task->input : $task->input;
            if (!is_array($input) || !array_key_exists('_resource_data_class', $input)
                || $input['_resource_data_class'] !== $task->data_class) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Authorized AI task facts changed');
            }
            if ($task->content_purged_time !== null) {
                throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'AI task source content is unavailable');
            }
            $state = (string) $task->state;
            $pending = $state === TaskState::AUTHORIZATION_PENDING
                || ($state === TaskState::FAILED && (int) $task->attempt_count === 0
                    && !isset($input['iam_authorization']));
            $accessGuard = new TaskRunAccessGuard();
            if ($pending || in_array($state, [TaskState::QUEUED, TaskState::RETRYING], true)) {
                if (!isset($input['_meeting_summary'])) {
                    self::lockSourceFiles($environmentId, $input['source_block_ids'] ?? []);
                    self::assertCurrentSourceFacts($task, self::attributionFromPersistedInput($input, $environmentId));
                }
            }
            if ($pending) {
                $saved = $task->save([
                    'input' => [...$input, 'iam_authorization' => $authorization],
                    'state' => TaskState::QUEUED,
                    'available_at' => date('Y-m-d H:i:s'),
                    'completed_at' => null,
                    'error_code' => null,
                    'error_summary' => null,
                ]);
                if ($saved !== true) {
                    throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Authorized AI task changed before enqueue');
                }
                $stepUpdated = TaskStep::where('task_id', $taskId)
                    ->whereIn('state', [TaskState::AUTHORIZATION_PENDING, TaskState::FAILED])
                    ->update([
                        'state' => TaskState::QUEUED, 'completed_at' => null,
                        'error_code' => null, 'error_summary' => null,
                    ]);
                if ($stepUpdated !== 1) {
                    throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Authorized AI task step changed');
                }
                $this->audit($environmentId, 'ai_task.queued', $taskId, 'Queued SandAI AI completion task', []);
            } elseif (in_array($state, [TaskState::QUEUED, TaskState::RETRYING], true)) {
                // An idempotent submission cannot renew the worker's identity.
                $originalAuthorization = $input['iam_authorization'] ?? null;
                if (!is_array($originalAuthorization)) {
                    throw new ApiProblem('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE', 'Queued AI task authorization is unavailable');
                }
                $accessGuard->revalidateQueued($originalAuthorization, $task);
            }
            $result = ['idempotent_replay' => $replay, 'task' => (new TaskGateway())->summary($task)];
            // Reuse this request's current authorization after lock waits and reads.
            $accessGuard->revalidateQueued($authorization, $task);
            return $result;
        });
    }

    private function rejectPendingAuthorization(int $taskId, string $errorCode): void
    {
        Db::connect('pgsql')->transaction(function () use ($taskId, $errorCode): void {
            $task = TaskRun::where('id', $taskId)->where('state', TaskState::AUTHORIZATION_PENDING)->lock(true)->find();
            if ($task === null) return;
            $now = date('Y-m-d H:i:s');
            $task->save([
                'state' => TaskState::FAILED, 'completed_at' => $now,
                'error_code' => $errorCode, 'error_summary' => 'SandIAM invocation authorization was refused',
            ]);
            $updated = TaskStep::where('task_id', $taskId)->where('state', TaskState::AUTHORIZATION_PENDING)
                ->update([
                    'state' => TaskState::FAILED, 'completed_at' => $now,
                    'error_code' => $errorCode, 'error_summary' => 'SandIAM invocation authorization was refused',
                ]);
            if ($updated !== 1) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'AI task authorization state changed');
            }
            $this->audit((int) $task->environment_id, 'ai_task.authorization_denied', $taskId,
                'SandAI AI completion task authorization was refused', ['error_code' => $errorCode]);
        });
    }

    /** @return array<string, mixed> */
    public function executeTask(TaskRun $task, TaskExecutionLease $lease): array
    {
        $persistedInput = $task->input;
        $persistedInput = is_object($persistedInput) ? (array) $persistedInput : $persistedInput;
        if (!is_array($persistedInput)) {
            throw new ApiProblem('SAND_AI_TASK_INVALID', 'The AI task payload is invalid');
        }
        $attribution = self::attributionFromPersistedInput($persistedInput, (int) $task->environment_id);
        self::assertPersistedTrustedIdentityScope($persistedInput, $attribution);
        $input = AiTaskPolicy::normalizeInput(self::businessInput($persistedInput));
        $meeting = $persistedInput['_meeting_summary'] ?? null;
        $authorization = $persistedInput['iam_authorization'] ?? null;
        if (!is_array($authorization)) {
            throw new ApiProblem('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE', 'Queued AI task authorization is unavailable');
        }
        $accessGuard = new TaskRunAccessGuard();
        $accessGuard->revalidateQueued($authorization, $task);
        self::assertCurrentSourceFacts($task, $attribution);
        if (($persistedInput['_meeting_durable'] ?? false) === true) {
            if (!MeetingExecutionRuntime::forInput($persistedInput)) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', '会议持久执行尚未启用，原任务已保留');
            }
            $meeting = self::meetingSource($meeting);
            $audioIdentity = UsageAttribution::fromCurrentPersisted($persistedInput['_usage_attribution'],
                (int) $task->environment_id, 'capability', 'sand_ai.audio.transcription');
            $authorize = static function (int $invocationId) use ($task, $attribution, $audioIdentity, $meeting, $authorization, $accessGuard): void {
                $accessGuard->revalidateQueued($authorization, $task);
                self::assertCurrentSourceFacts($task, $attribution);
                $source = (new MeetingSummarySource())->current((int) $task->environment_id, $meeting['audio_task_id'],
                    $audioIdentity, $meeting['sha256'], $meeting['review_revision'], $meeting['transcript_seal'], $meeting['aggregation_source']);
                self::assertMeetingFacts($meeting, $source);
                (new AudioTaskAccessGuard())->revalidateQueued($meeting['audio_authorization'], $source['task']);
            };
            return (new PostgresMeetingDurableWorker($this->chatGateway))->tick($task, $lease, $input, $attribution, $authorize);
        }
        $meetingPrepared = null;
        $meetingAudioIdentity = null;
        if ($meeting !== null) {
            $meeting = self::meetingSource($meeting);
            $meetingAudioIdentity = UsageAttribution::fromCurrentPersisted(
                $persistedInput['_usage_attribution'], (int) $task->environment_id,
                'capability', 'sand_ai.audio.transcription',
            );
            $source = (new MeetingSummarySource())->load(
                (int) $task->environment_id, $meeting['audio_task_id'], $meetingAudioIdentity, $meeting['sha256'], $meeting['review_revision'],
                ($meeting['planner_version'] ?? null) === MeetingSummaryPlan::CAPACITY_VERSION
                    && !isset($persistedInput['_meeting_plan']), $persistedInput['_meeting_plan'] ?? null,
                isset($persistedInput['_meeting_plan']) ? ($persistedInput['_meeting_capacity'] ?? null) : null,
            );
            if ($source['file_id'] !== $meeting['file_id']) {
                throw new ApiProblem('SAND_AI_AUDIO_SUMMARY_SOURCE_INVALID', 'The meeting source file changed');
            }
            self::assertMeetingFacts($meeting, $source);
            (new AudioTaskAccessGuard())->revalidateQueued(
                $meeting['audio_authorization'], $source['task'],
            );
            $meetingPrepared = $source['prepared'];
            if ($input['messages'] !== MeetingSummarySource::taskMarker(
                $meeting['audio_task_id'], $meeting['sha256'],
            ) || $input['source_block_ids'] !== []) {
                throw new ApiProblem('SAND_AI_AUDIO_SUMMARY_SOURCE_INVALID', 'The meeting summary input changed');
            }
        }
        if ($meeting !== null && $meetingAudioIdentity !== null) {
            $authorizeMeeting = static function (int $invocationId) use ($accessGuard, $authorization, $task, $attribution, $meeting, $meetingAudioIdentity): void {
                    $accessGuard->revalidateQueued($authorization, $task);
                    self::assertCurrentSourceFacts($task, $attribution);
                    $source = (new MeetingSummarySource())->current(
                        (int) $task->environment_id, $meeting['audio_task_id'],
                        $meetingAudioIdentity, $meeting['sha256'], $meeting['review_revision'], $meeting['transcript_seal'],
                    );
                    if ($source['file_id'] !== $meeting['file_id']) {
                        throw new ApiProblem('SAND_AI_AUDIO_SUMMARY_SOURCE_INVALID', 'The meeting source file changed');
                    }
                    self::assertMeetingFacts($meeting, $source);
                    (new AudioTaskAccessGuard())->revalidateQueued($meeting['audio_authorization'], $source['task']);
                };
            $runner = new MeetingSummaryRunner($this->chatGateway);
            if (($meeting['planner_version'] ?? null) === MeetingSummaryPlan::CAPACITY_VERSION
                && !isset($persistedInput['_meeting_plan'])) {
                $meetingPrepared = $runner->prepare($task, $lease, $source['transcript'], $meeting, $authorizeMeeting);
            }
            return $runner->execute($task, $lease, $meetingPrepared, $meeting, $input, $attribution, $authorizeMeeting);
        }
        $sources = $this->sourceBlocks(
            (int) $task->environment_id, $input['source_block_ids'], self::trustedIdentityScope($attribution),
        );
        $messages = $meetingPrepared === null ? $input['messages'] : $meetingPrepared['messages'];
        if ($sources !== []) {
            $sourceText = [];
            foreach ($sources as $source) {
                $sourceText[] = sprintf('[Source block %d, file %d] %s', $source['id'], $source['file_id'], $source['content']);
            }
            $messages[] = [
                'role' => 'system',
                'content' => "Use the following controlled source blocks when relevant. Cite their block ids in your answer.\n" . implode("\n\n", $sourceText),
            ];
        }
        $invocationRequestId = sprintf('task_%d_attempt_%d', (int) $task->id, max(1, (int) $task->attempt_count));
        $step = TaskStep::where('task_id', (int) $task->id)->where('sequence_no', 1)->find();
        if ($step === null) {
            throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'The task step is unavailable for dispatch');
        }
        $response = ($this->chatGateway ?? new ChatGateway())->complete(
            (int) $task->environment_id,
            $input['model'],
            $messages,
            $invocationRequestId,
            $attribution,
            $input['max_output_tokens'],
            capabilityCode: 'chat',
            executionScope: DispatchExecutionScope::task('ai_task', (int) $task->id, (int) $step->id, 'invoke_model', max(1, (int) $task->attempt_count), $lease),
            authorizePublicInvocation: static function (int $invocationId) use ($accessGuard, $authorization, $task, $attribution, $meeting, $meetingAudioIdentity): void {
                self::assertCurrentSourceFacts($task, $attribution);
                if ($meeting !== null && $meetingAudioIdentity !== null) {
                    $source = (new MeetingSummarySource())->current(
                        (int) $task->environment_id, $meeting['audio_task_id'],
                        $meetingAudioIdentity, $meeting['sha256'], $meeting['review_revision'], $meeting['transcript_seal'],
                    );
                    if ($source['file_id'] !== $meeting['file_id']) {
                        throw new ApiProblem('SAND_AI_AUDIO_SUMMARY_SOURCE_INVALID', 'The meeting source file changed');
                    }
                    self::assertMeetingFacts($meeting, $source);
                    (new AudioTaskAccessGuard())->revalidateQueued(
                        $meeting['audio_authorization'], $source['task'],
                    );
                }
                // Source reads can wait too. Finish with the original queued
                // context's current IAM/expiry check; never renew that context.
                $accessGuard->revalidateQueued($authorization, $task);
            },
        );
        if (!is_string($response['content'] ?? null)) {
            throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'The provider did not return task output', true);
        }

        if ($meetingPrepared !== null) {
            try {
                $summary = (new MeetingSummaryEvidence())->present(
                    $response['content'], $meetingPrepared['sources'],
                );
                $summaryError = null;
            } catch (ApiProblem $problem) {
                if ($problem->errorCode !== 'SAND_AI_AUDIO_SUMMARY_RESULT_INVALID') {
                    throw $problem;
                }
                // The provider call and its usage already exist. Persist a
                // terminal, reviewable failure instead of dispatching it again.
                $summary = null;
                $summaryError = $problem->errorCode;
            }
            return [
                'output' => ['content' => $response['content']],
                'meeting_summary' => $summary,
                'meeting_summary_error' => $summaryError,
                'audio_review_revision' => $meeting['review_revision'],
                'invocation' => $response['invocation'] ?? null,
                'review_required' => true,
            ];
        }

        return [
            'output' => ['content' => $response['content']],
            'invocation' => $response['invocation'] ?? null,
            'source_references' => array_map(static fn (array $source): array => [
                'source_block_id' => $source['id'],
                'file_id' => $source['file_id'],
                'locator_type' => $source['locator_type'],
                'locator' => $source['locator'],
            ], $sources),
        ];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public static function preparedInput(array $input, ?UsageAttribution $attribution): array
    {
        $input = AiTaskPolicy::normalizeInput(array_diff_key($input, ['_usage_attribution' => true]));
        if ($attribution !== null) {
            $input['_usage_attribution'] = $attribution->toPersisted();
            $input['_trusted_identity_scope'] = self::trustedIdentityScope($attribution);
        }

        return $input;
    }

    public static function assertCurrentSourceFacts(TaskRun $task, UsageAttribution $attribution): void
    {
        $persisted = is_object($task->input) ? (array) $task->input : $task->input;
        if (!is_array($persisted) || (string) $task->task_type !== 'ai_completion'
            || (int) $task->environment_id !== $attribution->environmentId) {
            throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'AI task source facts are unavailable');
        }
        $input = AiTaskPolicy::normalizeInput(self::businessInput($persisted));
        $meeting = $persisted['_meeting_summary'] ?? null;
        if ($meeting !== null) {
            $meeting = self::meetingSource($meeting);
            if ($input['source_block_ids'] !== []
                || $input['messages'] !== MeetingSummarySource::taskMarker(
                    $meeting['audio_task_id'], $meeting['sha256'],
                )) {
                throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Meeting source facts changed');
            }
            $source = (new MeetingSummarySource())->current(
                (int) $task->environment_id, $meeting['audio_task_id'],
                $attribution, $meeting['sha256'], $meeting['review_revision'], $meeting['transcript_seal'], $meeting['aggregation_source'],
            );
            self::assertMeetingFacts($meeting, $source);
            $dataClass = $source['data_class'];
        } else {
            $dataClass = (new AiTaskSourceFacts())->resolve(
                (int) $task->environment_id, $input['source_block_ids'], $attribution,
            );
        }
        if (!array_key_exists('_resource_data_class', $persisted)
            || $dataClass !== $persisted['_resource_data_class']
            || $dataClass !== $task->data_class) {
            throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'AI task source facts changed');
        }
    }

    /**
     * Resolves source availability only for a new request. Existing request IDs are
     * compared against their stable business input before any external resource lookup.
     *
     * @param array<string,mixed> $input
     * @param array<string,mixed>|null $existingInput
     * @return array{idempotent_replay:bool,input:array<string,mixed>}
     */
    public function resolveSubmissionInput(int $environmentId, array $input, ?array $existingInput, ?UsageAttribution $attribution = null): array
    {
        $normalized = self::preparedInput($input, $attribution);
        if ($existingInput !== null) {
            self::assertReplayInput($existingInput, $normalized);

            return ['idempotent_replay' => true, 'input' => $normalized];
        }
        if ($normalized['source_block_ids'] !== [] && $attribution === null) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Trusted source ownership is required');
        }
        $this->sourceBlocks(
            $environmentId, $normalized['source_block_ids'],
            $attribution === null ? null : self::trustedIdentityScope($attribution),
        );

        return ['idempotent_replay' => false, 'input' => $normalized];
    }

    /** @param mixed $existingInput @param array<string,mixed> $currentInput */
    public static function assertReplayInput(mixed $existingInput, array $currentInput): void
    {
        $existing = is_object($existingInput) ? (array) $existingInput : $existingInput;
        if (!is_array($existing)) {
            throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'The request id belongs to a different task input');
        }
        self::assertTrustedIdentityScope($existing, $currentInput);
        try {
            $existing = AiTaskPolicy::normalizeInput(self::businessInput($existing));
            $current = AiTaskPolicy::normalizeInput(self::businessInput($currentInput));
        } catch (ApiProblem) {
            throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'The request id belongs to a different task input');
        }
        if (!RequestPayloadFingerprint::same($existing, $current)) {
            throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'The request id belongs to a different task input');
        }
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private static function businessInput(array $input): array
    {
        return array_diff_key($input, [
            '_usage_attribution' => true,
            '_trusted_identity_scope' => true,
            '_resolved_source_snapshots' => true,
            '_resolved_source_blocks' => true,
            '_resource_data_class' => true,
            'iam_authorization' => true,
            '_meeting_summary' => true,
            '_meeting_route' => true,
            '_meeting_capacity' => true,
            '_meeting_plan' => true,
            '_meeting_durable' => true,
        ]);
    }

    /** Resolve the exact published revision already bound by the new task route. */
    private static function meetingDeploymentConfig(array $route): array
    {
        $deployment = ModelDeployment::where('id', $route['deployment_id'])
            ->where('revision', $route['deployment_revision'])->where('status', 1)->find();
        $config = $deployment === null ? null : (is_object($deployment->config) ? (array) $deployment->config : $deployment->config);
        if (!is_array($config) || !hash_equals($route['deployment_config_sha256'], RequestPayloadFingerprint::fingerprint($config, []))) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Meeting deployment configuration changed');
        }
        if (!SkillOutputStrategy::isStructured($config['skill_output_strategy'] ?? null)) return $config;
        $revision = ConfigRevision::where('id', $route['config_revision_id'])
            ->where('state', 'published')->where('status', 1)->find();
        if ($revision === null) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Meeting deployment revision is unavailable');
        }
        // This existing verifier checks revision identity, payload and execution/config digests.
        DispatchRouteSnapshot::reasoningEffortFromRevision($route, $revision);
        $payload = is_object($revision->payload) ? (array) $revision->payload : $revision->payload;
        $field = array_key_exists('published_execution_snapshot', $payload)
            || array_key_exists('published_execution_snapshot_digest', $payload)
            ? 'published_execution_snapshot' : 'execution_snapshot';
        return $payload[$field]['deployment']['config'];
    }

    /** @param mixed $value @return array<string,mixed> */
    private static function meetingSource(mixed $value): array
    {
        if (!is_array($value) || !is_int($value['audio_task_id'] ?? null) || $value['audio_task_id'] < 1
            || !is_int($value['file_id'] ?? null) || $value['file_id'] < 1
            || !is_string($value['sha256'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/D', $value['sha256']) !== 1
            || !is_int($value['review_revision'] ?? 0)
            || ($value['review_revision'] ?? 0) < 0
            || ($value['review_revision'] ?? 0) > 10000
            || !array_key_exists('data_class', $value)
            || ($value['data_class'] !== null && (!is_string($value['data_class'])
                || preg_match('/\A[a-z0-9][a-z0-9._-]{1,31}\z/D', $value['data_class']) !== 1))
            || ($value['data_egress_policy'] ?? null) !== 'approved_cloud'
            || !is_array($value['audio_authorization'] ?? null)) {
            throw new ApiProblem('SAND_AI_AUDIO_SUMMARY_SOURCE_INVALID', 'The meeting summary source is invalid');
        }
        if (isset($value['output_contract_revision']) && $value['output_contract_revision'] !== 2) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Meeting output contract revision is invalid');
        }
        return [
            ...(isset($value['output_contract_revision']) ? ['output_contract_revision' => 2] : []),
            ...(array_key_exists('output_contract', $value)
                ? ['output_contract' => MeetingSummaryOutputContract::assertMarker($value['output_contract'])] : []),
            'audio_task_id' => $value['audio_task_id'],
            'file_id' => $value['file_id'],
            'sha256' => $value['sha256'],
            'review_revision' => $value['review_revision'] ?? 0,
            'data_class' => $value['data_class'],
            'data_egress_policy' => $value['data_egress_policy'],
            'audio_authorization' => $value['audio_authorization'],
            'transcript_seal' => AudioTranscriptSealLedger::normalizeReference($value['transcript_seal'] ?? null),
            'aggregation_source' => is_array($value['aggregation_source'] ?? null) ? $value['aggregation_source'] : null,
            'total_segments' => is_int($value['total_segments'] ?? null) ? $value['total_segments'] : null,
            'eligible_segments' => is_int($value['eligible_segments'] ?? null) ? $value['eligible_segments'] : null,
            'planner_version' => $value['planner_version'] ?? MeetingSummaryPlan::VERSION,
        ];
    }

    /** @param array<string,mixed> $meeting @param array<string,mixed> $source */
    private static function assertMeetingFacts(array $meeting, array $source): void
    {
        if ($source['file_id'] !== $meeting['file_id']
            || $source['review_revision'] !== $meeting['review_revision']
            || $source['data_class'] !== $meeting['data_class']
            || $source['data_egress_policy'] !== $meeting['data_egress_policy']
            || $source['data_egress_policy'] !== 'approved_cloud'
            || ($meeting['transcript_seal'] !== null && $meeting['transcript_seal'] !== $source['transcript_seal'])
            || ($meeting['aggregation_source'] !== null
                && !RequestPayloadFingerprint::same($meeting['aggregation_source'], $source['aggregation_source'] ?? [], []))
            || (isset($meeting['total_segments'])
                && $meeting['total_segments'] !== $source['total_segments'])
            || (isset($meeting['eligible_segments'])
                && $meeting['eligible_segments'] !== $source['eligible_segments'])) {
            throw new ApiProblem('SAND_AI_AUDIO_SUMMARY_SOURCE_INVALID', 'The meeting source policy changed');
        }
    }

    /** @param mixed $persisted @param array<string,mixed>|null $current */
    private static function assertMeetingReplay(mixed $persisted, ?array $current): void
    {
        $persisted = is_object($persisted) ? (array) $persisted : $persisted;
        $old = is_array($persisted) ? ($persisted['_meeting_summary'] ?? null) : null;
        if ($old === null && $current === null) {
            return;
        }
        if ($old === null || $current === null) {
            throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'The request id belongs to another task');
        }
        $old = self::meetingSource($old);
        $current = self::meetingSource($current);
        foreach (['audio_task_id', 'file_id', 'sha256', 'review_revision', 'data_class', 'data_egress_policy'] as $field) {
            if ($old[$field] !== $current[$field]) {
                throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'The request id belongs to another meeting source');
            }
        }
        if ($old['transcript_seal'] !== null && $old['transcript_seal'] !== $current['transcript_seal']) {
            throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'The frozen transcript evidence changed');
        }
        foreach (['total_segments', 'eligible_segments'] as $field) {
            if ($old[$field] !== null && $old[$field] !== $current[$field]) {
                throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'The request id belongs to another meeting source');
            }
        }
    }

    private static function trustedIdentityScope(UsageAttribution $attribution): string
    {
        return RequestIdempotencyKey::trustedScopeForAttribution($attribution);
    }

    /** @param array<string,mixed> $persistedInput */
    private static function assertPersistedTrustedIdentityScope(array $persistedInput, UsageAttribution $attribution): void
    {
        $persistedScope = $persistedInput['_trusted_identity_scope'] ?? null;
        $currentScope = self::trustedIdentityScope($attribution);
        if (!is_string($persistedScope) || preg_match('/\A[a-f0-9]{64}\z/', $persistedScope) !== 1 || !hash_equals($persistedScope, $currentScope)) {
            throw new ApiProblem('SAND_AI_TASK_INVALID', 'The AI task trusted workload identity is invalid');
        }
    }

    /** @param array<string,mixed> $existing @param array<string,mixed> $current */
    private static function assertTrustedIdentityScope(array $existing, array $current): void
    {
        $left = $existing['_trusted_identity_scope'] ?? null;
        $right = $current['_trusted_identity_scope'] ?? null;
        if ($left === null && $right === null) return; // pre-1.46 persisted task
        if (!is_string($left) || !is_string($right) || preg_match('/\A[a-f0-9]{64}\z/', $left) !== 1 || !hash_equals($left, $right)) {
            throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'The request id belongs to another trusted workload identity');
        }
    }

    /** @param array<string, mixed> $persistedInput */
    public static function attributionFromPersistedInput(array $persistedInput, int $environmentId): UsageAttribution
    {
        $value = $persistedInput['_usage_attribution'] ?? null;
        if (!is_array($value)
            || !is_int($value['environment_id'] ?? null)
            || (int) $value['environment_id'] !== $environmentId
            || !is_array($value['identity_snapshot'] ?? null)
            || !is_string($value['identity_snapshot']['application_ref'] ?? null)
            || trim($value['identity_snapshot']['application_ref']) === ''
            || !is_string($value['identity_snapshot']['workload_client_ref'] ?? null)
            || trim($value['identity_snapshot']['workload_client_ref']) === '') {
            throw new ApiProblem('SAND_AI_TASK_INVALID', 'The AI task trusted usage attribution is invalid');
        }
        try {
            return UsageAttribution::fromCurrentPersisted($value, $environmentId, 'ai_task', 'sand_ai.chat.complete');
        } catch (\Throwable) {
            throw new ApiProblem('SAND_AI_TASK_INVALID', 'The AI task trusted usage attribution is invalid');
        }
    }

    /**
     * Called in a submission/retry transaction so private file deletion cannot
     * miss an AI task created or requeued while its source facts are checked.
     * @param list<int> $sourceBlockIds
     */
    public static function lockSourceFiles(int $environmentId, array $sourceBlockIds): void
    {
        $fileIds = [];
        foreach ($sourceBlockIds as $blockId) {
            if (!is_int($blockId) || $blockId < 1) {
                throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'AI task source identifiers are invalid');
            }
            $block = SourceBlock::where('id', $blockId)->where('status', 1)->whereNull('delete_time')->find();
            if ($block === null) {
                throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'AI task source block is unavailable');
            }
            $fileIds[(int) $block->file_id] = (int) $block->file_id;
        }
        sort($fileIds, SORT_NUMERIC);
        foreach ($fileIds as $fileId) {
            $file = AiFile::where('id', $fileId)->where('environment_id', $environmentId)->lock(true)->find();
            if ($file === null || (string) $file->state !== 'parsed'
                || (int) $file->status !== 1 || $file->delete_time !== null) {
                throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'AI task source file is unavailable');
            }
        }
    }

    /** @param list<int> $sourceBlockIds @return list<array{id: int, file_id: int, locator_type: string, locator: array, content: string}> */
    private function sourceBlocks(int $environmentId, array $sourceBlockIds, ?string $trustedIdentityScope): array
    {
        if ($this->sourceBlockLoader !== null) {
            $sources = ($this->sourceBlockLoader)($environmentId, $sourceBlockIds);
            if (!is_array($sources)) {
                throw new ApiProblem('SAND_AI_TASK_INVALID', 'The source block loader returned an invalid result');
            }

            return $sources;
        }
        $sources = [];
        foreach ($sourceBlockIds as $sourceBlockId) {
            $block = SourceBlock::where('id', $sourceBlockId)->where('status', 1)->find();
            if ($block === null) {
                throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Source block not found');
            }
            $file = AiFile::where('id', (int) $block->file_id)->where('environment_id', $environmentId)
                ->where('owner_scope', $trustedIdentityScope)->where('status', 1)->find();
            if ($file === null) {
                throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Source block is not available to this environment');
            }
            $locator = $block->locator;
            $locator = is_object($locator) ? (array) $locator : $locator;
            $sources[] = [
                'id' => (int) $block->id,
                'file_id' => (int) $block->file_id,
                'locator_type' => (string) $block->locator_type,
                'locator' => is_array($locator) ? $locator : [],
                'content' => (string) $block->content,
            ];
        }

        return $sources;
    }

    /** @param array<string, mixed> $context */
    private function audit(int $environmentId, string $action, int $taskId, string $summary, array $context): void
    {
        AuditLog::create([
            'actor_type' => 'api_credential',
            'actor_ref' => (string) $environmentId,
            'action' => $action,
            'resource_type' => 'task',
            'resource_id' => $taskId,
            'summary' => $summary,
            'context' => $context,
        ]);
    }
}
