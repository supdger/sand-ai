<?php

declare(strict_types=1);

namespace app\Application\Audio;

use app\Api\Support\ApiProblem;
use app\Application\Capability\CapabilityProfileResolver;
use app\Application\Gateway\DispatchExecutionScope;
use app\Application\Gateway\DispatchRouteSnapshot;
use app\Infrastructure\Persistence\Model\AiModel;
use app\Infrastructure\Persistence\Model\CapabilityProfile;
use app\Infrastructure\Persistence\Model\CapabilityRoute;
use app\Infrastructure\Persistence\Model\ConfigRevision;
use app\Infrastructure\Persistence\Model\InvocationDispatch;
use app\Infrastructure\Persistence\Model\ModelDeployment;
use app\Infrastructure\Persistence\Model\Provider;
use app\Infrastructure\Persistence\Model\TaskRun;
use think\facade\Db;

/** One published model route for every leaf and merge in a meeting task. */
final class MeetingSummaryRoute
{
    /** @return array<string,mixed> */
    public function freeze(int $environmentId, string $modelCode, ?int $requestedOutput): array
    {
        $model = AiModel::where('code', $modelCode)->where('status', 1)->find();
        if ($model === null || (string) $model->type !== 'chat') {
            throw new ApiProblem('SAND_AI_MODEL_NOT_AVAILABLE', 'Select an available chat model for this meeting');
        }
        foreach ((new CapabilityProfileResolver())->routes($environmentId, 'chat') as $candidate) {
            if (($candidate['route_kind'] ?? null) !== 'model_deployment'
                || (int) $candidate['model_id'] !== (int) $model->id) continue;
            $deployment = ModelDeployment::where('id', (int) $candidate['deployment_id'])->where('status', 1)->find();
            $provider = $deployment === null ? null : Provider::where('id', (int) $deployment->provider_id)->where('status', 1)->find();
            $profile = CapabilityProfile::where('id', (int) $candidate['profile_id'])
                ->where('environment_id', $environmentId)->where('revision', (int) $candidate['profile_revision'])
                ->where('state', 'published')->where('status', 1)->find();
            $route = CapabilityRoute::where('id', (int) $candidate['route_id'])
                ->where('profile_id', (int) $candidate['profile_id'])->where('capability_code', 'chat')
                ->where('deployment_id', (int) $candidate['deployment_id'])->where('status', 1)->find();
            $revision = $deployment === null ? null : ConfigRevision::where('resource_type', 'model_deployment')
                ->where('resource_id', (int) $deployment->id)->where('revision', (int) $deployment->revision)
                ->where('state', 'published')->where('status', 1)->find();
            $profileRevision = $profile === null ? null : ConfigRevision::where('resource_type', 'capability_profile')
                ->where('resource_id', (int) $profile->id)->where('revision', (int) $profile->revision)
                ->where('state', 'published')->where('status', 1)->find();
            if ($deployment === null || $provider === null || $profile === null || $route === null
                || $revision === null || $profileRevision === null
                || !in_array((string) $provider->adapter, ['fake', 'openai_compatible'], true)) {
                throw new ApiProblem('SAND_AI_CONFIGURATION_NOT_PUBLISHED', 'The selected meeting model is unavailable');
            }
            self::assertOutputBound($deployment->config, min(4096, $requestedOutput ?? 4096));
            return DispatchRouteSnapshot::freeze($profile, $route, $deployment, $model, $provider, $revision, $profileRevision);
        }
        throw new ApiProblem('SAND_AI_MODEL_NOT_AVAILABLE', 'Select an available chat model for this meeting');
    }

    /**
     * Old queued tasks with no dispatch acquire their route once under the task
     * row lock. Existing legacy calls keep receipt recovery without guessing a
     * route for new work after a historically unfrozen call.
     *
     * @return array<string,mixed>|null
     */
    public function forNode(DispatchExecutionScope $scope, int $environmentId, bool $existing): ?array
    {
        return Db::connect('pgsql')->transaction(function () use ($scope, $environmentId, $existing): ?array {
            $task = TaskRun::where('id', $scope->taskRunId)->where('environment_id', $environmentId)->lock(true)->find();
            $input = $task?->input;
            $input = is_object($input) ? (array) $input : $input;
            if ($task === null || !is_array($input) || !is_array($input['_meeting_summary'] ?? null)
                || !is_string($input['model'] ?? null)
                || (string) $task->lease_owner !== $scope->leaseOwner
                || !hash_equals((string) $task->lease_token, $scope->leaseToken)
                || (int) $task->lease_fence !== $scope->leaseFence
                || !in_array((string) $task->state, $existing ? ['running', 'recovery_pending'] : ['running'], true)
                || $task->canceled_at !== null || $task->content_purged_time !== null) {
                throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'The meeting task changed before model selection');
            }
            if (!array_key_exists('_meeting_route', $input)) {
                if ($existing) return null;
                if (InvocationDispatch::where('parent_task_run_id', (int) $task->id)
                    ->whereIn('state', ['prepared', 'dispatching', 'ambiguous', 'succeeded', 'failed'])->find() !== null) {
                    throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'This older meeting has no frozen model route; recover its existing calls and submit a new summary');
                }
                $input['_meeting_route'] = $this->freeze($environmentId, $input['model'], $input['max_output_tokens'] ?? null);
                $task->save(['input' => $input]);
            }
            $snapshot = $input['_meeting_route'];
            if (!is_array($snapshot)) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'The frozen meeting model is invalid');
            }
            $snapshot = DispatchRouteSnapshot::assert($snapshot, false);
            if ($snapshot['capability_code'] !== 'chat' || $snapshot['route_kind'] !== 'model_deployment'
                || $snapshot['selection_kind'] !== 'capability_profile' || $snapshot['model_code'] !== $input['model']) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'The frozen meeting model changed');
            }
            return $snapshot;
        });
    }

    /** @return array<string,mixed> */
    public function forPrepared(DispatchExecutionScope $scope, int $environmentId): array
    {
        return Db::connect('pgsql')->transaction(function () use ($scope, $environmentId): array {
            $task = TaskRun::where('id', $scope->taskRunId)->where('environment_id', $environmentId)->lock(true)->find();
            $input = $task?->input;
            $input = is_object($input) ? (array) $input : $input;
            if ($task === null || !is_array($input) || !is_array($input['_meeting_summary'] ?? null)
                || (string) $task->state !== 'running' || $task->canceled_at !== null
                || $task->content_purged_time !== null
                || (string) $task->lease_owner !== $scope->leaseOwner
                || !hash_equals((string) $task->lease_token, $scope->leaseToken)
                || (int) $task->lease_fence !== $scope->leaseFence) {
                throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Meeting lease changed before route recovery');
            }
            if (array_key_exists('_meeting_route', $input)) {
                if (!is_array($input['_meeting_route'])) {
                    throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Meeting route is unavailable');
                }
                return DispatchRouteSnapshot::assert($input['_meeting_route'], false);
            }
            $snapshot = null;
            $foundCurrent = false;
            foreach (InvocationDispatch::where('parent_task_run_id', (int) $task->id)
                ->whereIn('state', ['prepared', 'dispatching', 'ambiguous', 'succeeded', 'failed'])->select() as $ledger) {
                // A historically sent call does not prove one route for the
                // remainder of an unfrozen meeting. Its receipt stays recoverable.
                $route = is_object($ledger->route_snapshot) ? (array) $ledger->route_snapshot : $ledger->route_snapshot;
                if ((string) $ledger->state !== 'prepared' || $ledger->dispatch_start_time !== null
                    || !is_array($route)) {
                    throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'The older meeting route cannot be safely recovered; retain its calls and submit a new summary');
                }
                $route = DispatchRouteSnapshot::assert($route, false);
                if ($route['selection_kind'] !== 'capability_profile' || $route['capability_code'] !== 'chat'
                    || $route['route_kind'] !== 'model_deployment' || $route['model_code'] !== ($input['model'] ?? null)
                    || (string) $ledger->execution_scope !== 'ai_task'
                    || (int) $ledger->environment_id !== $environmentId
                    || (int) $ledger->deployment_id !== $route['deployment_id']
                    || (int) $ledger->provider_id !== $route['provider_id']
                    || (int) $ledger->model_id !== $route['model_id']) {
                    throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'The original meeting deployment cannot be verified');
                }
                if ($snapshot !== null) self::assertSame($snapshot, $route);
                $snapshot = $route;
                $foundCurrent = $foundCurrent || ((int) $ledger->parent_task_step_id === $scope->taskStepId
                    && (string) $ledger->workflow_node_code === $scope->logicalNode
                    && (int) $ledger->attempt_no === $scope->attemptNo);
            }
            if ($snapshot === null || !$foundCurrent) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'The original meeting route is unavailable');
            }
            $input['_meeting_route'] = $snapshot;
            $task->save(['input' => $input]);
            return $snapshot;
        });
    }

    /** @param array<string,mixed> $expected */
    public static function assertSame(array $expected, mixed $actual): void
    {
        $expected = DispatchRouteSnapshot::assert($expected, false);
        $actual = is_object($actual) ? (array) $actual : $actual;
        if (!is_array($actual)) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'The frozen meeting model route is unavailable');
        }
        $actual = DispatchRouteSnapshot::assert($actual, false);
        if (!hash_equals($expected['snapshot_sha256'], $actual['snapshot_sha256'])) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'The meeting model configuration changed; retain existing results and submit a new summary');
        }
    }

    public static function assertOutputBound(mixed $config, ?int $outputTokens): void
    {
        $config = is_object($config) ? (array) $config : $config;
        if (!is_array($config) || !is_int($config['max_output_tokens'] ?? null)
            || $config['max_output_tokens'] < 1 || $config['max_output_tokens'] > 131072
            || $outputTokens === null || $outputTokens < 1 || $outputTokens > $config['max_output_tokens']) {
            throw new ApiProblem('SAND_AI_BUDGET_OUTPUT_BOUND_REQUIRED', 'The meeting output limit exceeds the published model limit; choose a smaller output limit or configure the model');
        }
    }
}
