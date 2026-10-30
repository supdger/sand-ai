<?php

declare(strict_types=1);

namespace app\Api\Controller;

use app\Api\Support\ApiProblem;
use app\Api\Support\ApiResponder;
use app\Api\Support\ExpectedEnvironmentGuard;
use app\Application\Gateway\SandIamContextAuthenticator;
use app\Application\Workflow\StructuredWorkflowGateway;
use app\Application\Usage\UsageAttribution;
use app\Identity\Infrastructure\RequestAuditId;
use app\Identity\Infrastructure\WorkflowRunAccessGuard;
use support\Request;
use support\Response;

final class StructuredWorkflowController
{
    public function submit(Request $request): Response
    {
        return $this->submitRun($request, false);
    }

    public function sample(Request $request): Response
    {
        return $this->submitRun($request, true);
    }

    private function submitRun(Request $request, bool $sample): Response
    {
        $requestId = $this->requestId($request);
        try {
            $context = (new SandIamContextAuthenticator())->authenticate($request, 'sand_ai.workflow.run');
            if ($sample) {
                (new ExpectedEnvironmentGuard())->assert($request, $context['environment_id']);
            }
            $idempotencyKey = trim((string) $request->header('idempotency-key', ''));
            $payload = $request->post();
            if (!is_array($payload)) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'A structured workflow request body is required');
            }
            foreach (['_usage_attribution', 'execution_snapshot', 'execution_snapshot_sha256', 'logical_execution_id', 'retain_until', 'consumer_ack_time', 'consumer_ack_ref', 'material_profile_sample'] as $internalField) {
                if (array_key_exists($internalField, $payload)) {
                    throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Internal structured workflow fields are not accepted from callers');
                }
            }
            $revision = 0;
            if ($sample) {
                $requestedRevision = filter_var($payload['profile_revision'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($requestedRevision === false) {
                    throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'The exact material profile revision is required');
                }
                $revision = (int) $requestedRevision;
                unset($payload['profile_revision']);
            }
            $gateway = new StructuredWorkflowGateway();
            $attribution = UsageAttribution::fromIdentity($context['context'], 'structured_workflow', 'sand_ai.workflow.run', 'workflow');
            $authorize = fn (int $runId): array => (new WorkflowRunAccessGuard())->authorizeRun($request, $context['context'], $runId, $requestId);
            $result = $sample
                ? $gateway->submitSample($context['environment_id'], $payload, $revision, $idempotencyKey, $attribution, $authorize)
                : $gateway->submit($context['environment_id'], $payload, $idempotencyKey, $attribution, $authorize);
            return ApiResponder::success($result, $requestId);
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $requestId)->header('Cache-Control', 'no-store');
        }
    }

    public function read(Request $request, string $runId): Response
    {
        $requestId = $this->requestId($request);
        try {
            $context = (new SandIamContextAuthenticator())->authenticate($request, 'sand_ai.workflow.read');
            (new ExpectedEnvironmentGuard())->assert($request, $context['environment_id']);
            $id = filter_var($runId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'A valid structured workflow run id is required');
            }
            $guard = new WorkflowRunAccessGuard();
            $decision = $guard->authorizeRead($request, $context['context'], (int) $id, $requestId);
            $result = (new StructuredWorkflowGateway())->read($context['environment_id'], (int) $id);
            $guard->revalidateRead($request, $context['context'], (int) $id, $requestId, $decision);
            return ApiResponder::success($result, $requestId)->header('Cache-Control', 'no-store');
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $requestId)->header('Cache-Control', 'no-store');
        }
    }

    public function sampleByRequestKey(Request $request): Response
    {
        $requestId = $this->requestId($request);
        try {
            $context = (new SandIamContextAuthenticator())->authenticate($request, 'sand_ai.workflow.read');
            (new ExpectedEnvironmentGuard())->assert($request, $context['environment_id']);
            $key = trim((string) $request->get('request_key', ''));
            $attribution = UsageAttribution::fromIdentity($context['context'], 'structured_workflow', 'sand_ai.workflow.read');
            $gateway = new StructuredWorkflowGateway();
            $runId = $gateway->sampleRunIdByRequestKey($context['environment_id'], $attribution, $key);
            $guard = new WorkflowRunAccessGuard();
            $decision = $guard->authorizeRead($request, $context['context'], $runId, $requestId);
            $result = $gateway->read($context['environment_id'], $runId);
            $guard->revalidateRead($request, $context['context'], $runId, $requestId, $decision);
            return ApiResponder::success($result, $requestId)
                ->header('Cache-Control', 'no-store');
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $requestId)->header('Cache-Control', 'no-store');
        }
    }

    public function runByRequestKey(Request $request): Response
    {
        $requestId = $this->requestId($request);
        try {
            $context = (new SandIamContextAuthenticator())->authenticate($request, 'sand_ai.workflow.read');
            (new ExpectedEnvironmentGuard())->assert($request, $context['environment_id']);
            $key = trim((string) $request->get('request_key', ''));
            $attribution = UsageAttribution::fromIdentity($context['context'], 'structured_workflow', 'sand_ai.workflow.read');
            $gateway = new StructuredWorkflowGateway();
            $runId = $gateway->runIdByRequestKey($context['environment_id'], $attribution, $key);
            $guard = new WorkflowRunAccessGuard();
            $decision = $guard->authorizeRead($request, $context['context'], $runId, $requestId);
            $result = $gateway->read($context['environment_id'], $runId);
            $guard->revalidateRead($request, $context['context'], $runId, $requestId, $decision);
            return ApiResponder::success($result, $requestId)
                ->header('Cache-Control', 'no-store');
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $requestId)->header('Cache-Control', 'no-store');
        }
    }

    public function fileResult(Request $request, string $runId, string $fileId): Response
    {
        $requestId = $this->requestId($request);
        try {
            $context = (new SandIamContextAuthenticator())->authenticate($request, 'sand_ai.workflow.read');
            (new ExpectedEnvironmentGuard())->assert($request, $context['environment_id']);
            $id = filter_var($runId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $requestedFileId = filter_var($fileId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $section = $request->get('section');
            $extensionField = $request->get('extension_field');
            $offset = filter_var($request->get('offset', 0), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 9007199254740991]]);
            $limit = filter_var($request->get('limit', 50), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
            if ($id === false || $requestedFileId === false || !is_string($section)
                || !in_array($section, ['documents', 'entities', 'events', 'conflicts', 'items_to_verify', 'warnings', 'extensions'], true)
                || ($extensionField !== null && !is_string($extensionField))
                || ($section === 'extensions' && ($extensionField === null || $extensionField === ''))
                || ($section !== 'extensions' && $extensionField !== null)
                || $offset === false || $limit === false) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Valid workflow run, file and material result pagination parameters are required');
            }
            $guard = new WorkflowRunAccessGuard();
            $decision = $guard->authorizeRead($request, $context['context'], (int) $id, $requestId);
            $result = (new StructuredWorkflowGateway())->materialFileResultPage(
                $context['environment_id'], (int) $id, (int) $requestedFileId,
                $section, $extensionField, (int) $offset, (int) $limit,
            );
            $guard->revalidateRead($request, $context['context'], (int) $id, $requestId, $decision);
            return ApiResponder::success($result, $requestId)->header('Cache-Control', 'no-store');
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $requestId)->header('Cache-Control', 'no-store');
        }
    }

    public function source(Request $request, string $runId, string $sourceBlockId): Response
    {
        $requestId = $this->requestId($request);
        try {
            $context = (new SandIamContextAuthenticator())->authenticate($request, 'sand_ai.workflow.read');
            (new ExpectedEnvironmentGuard())->assert($request, $context['environment_id']);
            $id = filter_var($runId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $blockId = filter_var($sourceBlockId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false || $blockId === false) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Valid workflow run and source block ids are required');
            }
            $guard = new WorkflowRunAccessGuard();
            $decision = $guard->authorizeRead($request, $context['context'], (int) $id, $requestId);
            $result = (new StructuredWorkflowGateway())->source($context['environment_id'], (int) $id, (int) $blockId);
            $guard->revalidateRead($request, $context['context'], (int) $id, $requestId, $decision);
            return ApiResponder::success($result, $requestId)->header('Cache-Control', 'no-store');
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $requestId)->header('Cache-Control', 'no-store');
        }
    }

    private function requestId(Request $request): string
    {
        return RequestAuditId::ensure($request);
    }
}
