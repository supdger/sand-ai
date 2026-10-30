<?php

declare(strict_types=1);

namespace app\Api\Controller;

use app\Api\Support\ApiProblem;
use app\Api\Support\ApiResponder;
use app\Api\Support\ExpectedEnvironmentGuard;
use app\Application\File\FileGateway;
use app\Application\File\FileOwnershipGuard;
use app\Application\File\FileParseGateway;
use app\Application\File\FileSourceBlockGateway;
use app\Application\Gateway\RequestIdempotencyKey;
use app\Application\Gateway\SandIamContextAuthenticator;
use app\Application\Usage\UsageAttribution;
use app\Identity\Infrastructure\FileParseAccessGuard;
use app\Identity\Infrastructure\FileUploadAccessGuard;
use app\Identity\Infrastructure\HostSandIamInvocationAuthorizer;
use app\Identity\Infrastructure\RequestAuditId;
use support\Request;
use support\Response;
use Webman\Http\UploadFile;

final class FileController
{
    public function upload(Request $request): Response
    {
        $originalKey = trim((string) $request->header('x-request-id', ''));
        $requestId = $this->requestId($request);
        try {
            if (!RequestAuditId::isValid($originalKey)) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Upload requires a caller-retained X-Request-Id');
            }
            $context = (new SandIamContextAuthenticator())->authenticate($request, 'sand_ai.file.write');
            (new ExpectedEnvironmentGuard())->assert($request, $context['environment_id']);
            $parts = $request->file();
            if ($request->post() !== [] || !is_array($parts) || array_keys($parts) !== ['file']) {
                throw new ApiProblem('SAND_AI_FILE_INVALID', 'Only one file part and no extra form fields are accepted');
            }
            $upload = $request->file('file');
            if (!$upload instanceof UploadFile) {
                throw new ApiProblem('SAND_AI_FILE_INVALID', 'A single multipart file field named file is required');
            }
            $decision = (new FileUploadAccessGuard())->authorize($request, $context['context'], $requestId);
            $result = (new FileGateway())->upload(
                $context['environment_id'], $upload,
                UsageAttribution::fromIdentity($context['context'], 'file', 'sand_ai.file.write', 'file'),
                $requestId,
            );
            $header = trim((string) $request->header('authorization', ''));
            $signed = str_starts_with($header, 'Bearer ') ? trim(substr($header, 7)) : '';
            (new HostSandIamInvocationAuthorizer())->revalidate($decision['authorization_id'], $signed,
                $context['context'], 'sand_ai.file.write', 'sand_iam.workload_client',
                $context['context']->workloadClientId, $request->getRemoteIp(), $requestId);
            return ApiResponder::success($result, $requestId)->header('Cache-Control', 'no-store');
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $requestId);
        }
    }

    /** Opaque current server-verified identity partition for retained upload requests. */
    public function uploadContext(Request $request): Response
    {
        $trace = $this->requestId($request);
        try {
            if ($request->get() !== [] || $request->rawBody() !== '') {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Upload context accepts no input');
            }
            $context = (new SandIamContextAuthenticator())->authenticate($request, 'sand_ai.file.write');
            (new ExpectedEnvironmentGuard())->assert($request, $context['environment_id']);
            $decision = (new FileUploadAccessGuard())->authorize($request, $context['context'], $trace);
            $scope = FileOwnershipGuard::scope(UsageAttribution::fromIdentity(
                $context['context'], 'file', 'sand_ai.file.write', 'file'));
            $header = trim((string) $request->header('authorization', ''));
            $signed = str_starts_with($header, 'Bearer ') ? trim(substr($header, 7)) : '';
            (new HostSandIamInvocationAuthorizer())->revalidate($decision['authorization_id'], $signed,
                $context['context'], 'sand_ai.file.write', 'sand_iam.workload_client',
                $context['context']->workloadClientId, $request->getRemoteIp(), $trace);
            return ApiResponder::success(['scope' => $scope, 'environment_id' => $context['environment_id']], $trace)
                ->header('Cache-Control', 'no-store');
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $trace)->header('Cache-Control', 'no-store');
        }
    }

    /** Resolve a new upload's original key without issuing another upload. */
    public function uploadByRequestKey(Request $request): Response
    {
        $trace = $this->requestId($request);
        try {
            $query = $request->get();
            $key = is_array($query) ? ($query['request_key'] ?? null) : null;
            if (!is_array($query) || array_keys($query) !== ['request_key'] || $request->rawBody() !== ''
                || !is_string($key) || !RequestAuditId::isValid($key)) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Upload lookup requires the original X-Request-Id and no body');
            }
            $context = (new SandIamContextAuthenticator())->authenticate($request, 'sand_ai.file.read');
            (new ExpectedEnvironmentGuard())->assert($request, $context['environment_id']);
            $authorizer = new HostSandIamInvocationAuthorizer();
            $identity = $context['context'];
            $result = (new FileGateway())->uploadByRequestKey($context['environment_id'],
                RequestIdempotencyKey::trustedScopeForAttribution(
                    UsageAttribution::fromIdentity($identity, 'file', 'sand_ai.file.read', 'file')),
                $key);
            $resolver = $result['file'] === null ? 'sand_iam.workload_client' : 'sand_ai.file_metadata';
            $resource = $result['file'] === null ? $identity->workloadClientId : (string) $result['file']['id'];
            $decision = $authorizer->authorize($request, $identity, 'sand_ai.file.read',
                $resolver, $resource, 'file-upload-read:' . bin2hex(random_bytes(16)), $trace);
            $header = trim((string) $request->header('authorization', ''));
            $signed = str_starts_with($header, 'Bearer ') ? trim(substr($header, 7)) : '';
            $authorizer->revalidate($decision['authorization_id'], $signed, $identity, 'sand_ai.file.read',
                $resolver, $resource, $request->getRemoteIp(), $trace);
            return ApiResponder::success($result, $trace)->header('Cache-Control', 'no-store');
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $trace)->header('Cache-Control', 'no-store');
        }
    }

    public function read(Request $request, string $fileId): Response
    {
        $requestId = $this->requestId($request);
        try {
            $context = (new SandIamContextAuthenticator())->authenticate($request, 'sand_ai.file.read');
            (new ExpectedEnvironmentGuard())->assert($request, $context['environment_id']);
            $id = $this->id($fileId);
            $authorizer = new HostSandIamInvocationAuthorizer();
            $decision = $authorizer->authorize(
                $request, $context['context'], 'sand_ai.file.read', 'sand_ai.file_metadata',
                (string) $id, 'file-read:' . bin2hex(random_bytes(16)), $requestId,
            );
            $result = (new FileGateway())->read(
                $context['environment_id'], $id,
                RequestIdempotencyKey::trustedScopeForAttribution(
                    UsageAttribution::fromIdentity($context['context'], 'file', 'sand_ai.file.read', 'file'),
                ),
            );
            $authorizationHeader = trim((string) $request->header('authorization', ''));
            $signedContext = str_starts_with($authorizationHeader, 'Bearer ')
                ? trim(substr($authorizationHeader, 7)) : '';
            // Reuse this read's authorization after metadata and parse reads.
            // A revoked grant must not release the DTO or consume a second quota.
            $authorizer->revalidate(
                $decision['authorization_id'], $signedContext, $context['context'],
                'sand_ai.file.read', 'sand_ai.file_metadata', (string) $id,
                $request->getRemoteIp(), $requestId,
            );
            return ApiResponder::success($result, $requestId)->header('Cache-Control', 'no-store');
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $requestId);
        }
    }

    public function sourceBlocks(Request $request, string $fileId): Response
    {
        $requestId = $this->requestId($request);
        try {
            $context = (new SandIamContextAuthenticator())->authenticate($request, 'sand_ai.file.read');
            (new ExpectedEnvironmentGuard())->assert($request, $context['environment_id']);
            $id = $this->id($fileId);
            $query = $request->get();
            if (!is_array($query) || array_diff(array_keys($query), ['after_id', 'limit', 'parse_id']) !== []) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Only after_id, limit and parse_id are supported');
            }
            $afterId = array_key_exists('after_id', $query) ? $this->sourceBlockQueryId($query['after_id'], 'after_id') : null;
            $parseId = array_key_exists('parse_id', $query) ? $this->sourceBlockQueryId($query['parse_id'], 'parse_id') : null;
            $limit = array_key_exists('limit', $query) ? $this->sourceBlockQueryId($query['limit'], 'limit') : 40;
            if ($limit > 40) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'limit must be between 1 and 40');
            }
            $authorizer = new HostSandIamInvocationAuthorizer();
            $decision = $authorizer->authorize(
                $request, $context['context'], 'sand_ai.file.read', 'sand_ai.file',
                (string) $id, 'file-source-blocks:' . bin2hex(random_bytes(16)), $requestId,
            );
            $result = (new FileSourceBlockGateway())->read(
                $context['environment_id'], $id,
                RequestIdempotencyKey::trustedScopeForAttribution(
                    UsageAttribution::fromIdentity($context['context'], 'file', 'sand_ai.file.read', 'file'),
                ),
                $decision['data_class'], $afterId, $limit, $parseId,
            );
            $authorizationHeader = trim((string) $request->header('authorization', ''));
            $signedContext = str_starts_with($authorizationHeader, 'Bearer ')
                ? trim(substr($authorizationHeader, 7)) : '';
            $currentDecision = $authorizer->revalidate(
                $decision['authorization_id'], $signedContext, $context['context'],
                'sand_ai.file.read', 'sand_ai.file', (string) $id,
                $request->getRemoteIp(), $requestId,
            );
            if ($currentDecision['data_class'] !== $decision['data_class']) {
                throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'The file classification changed during the read');
            }
            return ApiResponder::success($result, $requestId)->header('Cache-Control', 'no-store');
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $requestId)->header('Cache-Control', 'no-store');
        }
    }

    private function sourceBlockQueryId(mixed $value, string $name): int
    {
        if ((!is_string($value) && !is_int($value)) || preg_match('/\A[1-9][0-9]*\z/D', (string) $value) !== 1) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', $name . ' must be a positive integer');
        }
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', $name . ' must be a positive integer');
        }
        return $id;
    }

    public function destroy(Request $request, string $fileId): Response
    {
        $requestId = $this->requestId($request);
        try {
            $context = (new SandIamContextAuthenticator())->authenticate($request, 'sand_ai.file.write');
            $id = $this->id($fileId);
            (new HostSandIamInvocationAuthorizer())->authorize(
                $request, $context['context'], 'sand_ai.file.write', 'sand_ai.file',
                (string) $id, 'file-delete:' . bin2hex(random_bytes(16)), $requestId,
            );
            return ApiResponder::success((new FileGateway())->destroy(
                $context['environment_id'], $id,
                RequestIdempotencyKey::trustedScopeForAttribution(
                    UsageAttribution::fromIdentity($context['context'], 'file', 'sand_ai.file.write', 'file'),
                ),
            ), $requestId);
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $requestId);
        }
    }

    /** Read the exact original parse task; this endpoint never queues or retries it. */
    public function parseByRequestKey(Request $request, string $fileId): Response
    {
        $trace = $this->requestId($request);
        try {
            $query = $request->get();
            $key = is_array($query) ? ($query['request_key'] ?? null) : null;
            if (!is_array($query) || array_keys($query) !== ['request_key']
                || $request->rawBody() !== '' || !is_string($key) || !RequestAuditId::isValid($key)) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Parse lookup requires the original X-Request-Id and no body');
            }
            $context = (new SandIamContextAuthenticator())->authenticate($request, 'sand_ai.task.read');
            (new ExpectedEnvironmentGuard())->assert($request, $context['environment_id']);
            $id = $this->id($fileId);
            $task = \app\Infrastructure\Persistence\Model\TaskRun::where('environment_id', $context['environment_id'])
                ->where('task_type', 'file_parse')->where('resource_type', 'file')
                ->where('resource_id', $id)->where('request_id', $key)
                ->where('status', 1)->whereNull('delete_time')->find();
            if ($task === null) {
                throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'File parse task not found');
            }
            (new \app\Application\Task\TaskInputAccessGuard())->assert($task,
                RequestIdempotencyKey::trustedScopeForAttribution(
                    UsageAttribution::fromIdentity($context['context'], 'task', 'sand_ai.task.read', 'task'),
                ),
            );
            return (new TaskController())->read($request, (string) $task->id)->header('Cache-Control', 'no-store');
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $trace)->header('Cache-Control', 'no-store');
        }
    }

    public function parse(Request $request, string $fileId): Response
    {
        $requestId = $this->requestId($request);
        try {
            $context = (new SandIamContextAuthenticator())->authenticate($request, 'sand_ai.document_parse');
            (new ExpectedEnvironmentGuard())->assert($request, $context['environment_id']);
            $id = $this->id($fileId);
            $authorization = (new FileParseAccessGuard())->authorize($request, $context['context'], $id, $requestId);
            return ApiResponder::success((new FileParseGateway())->parse(
                $context['environment_id'], $id, $requestId,
                UsageAttribution::fromIdentity($context['context'], 'file_parse', 'sand_ai.document_parse', 'document_parse'),
                $authorization,
            ), $requestId);
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $requestId);
        }
    }

    private function id(string $fileId): int
    {
        $id = filter_var($fileId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'A valid file id is required');
        }

        return $id;
    }

    private function requestId(Request $request): string
    {
        return RequestAuditId::ensure($request);
    }
}
