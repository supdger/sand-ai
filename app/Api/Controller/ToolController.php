<?php

declare(strict_types=1);

namespace app\Api\Controller;

use app\Api\Support\ApiProblem;
use app\Api\Support\ApiResponder;
use app\Application\Gateway\SandIamContextAuthenticator;
use app\Application\Tool\ToolGatewayFactory;
use app\Application\Tool\StrictJsonObjectDecoder;
use app\Identity\Infrastructure\RequestAuditId;
use support\Request;
use support\Response;

final class ToolController
{
    public function readVersion(Request $request,string $code,string $version): Response
    {
        $requestId=RequestAuditId::ensure($request);
        try {
            if ($request->get()!==[] || $request->rawBody()!=='' || !ctype_digit($version) || (int)$version<1 || preg_match('/\A[a-z][a-z0-9_]{1,63}\z/D',$code)!==1) throw new ApiProblem('SAND_AI_VALIDATION_ERROR','Tool version read requires an exact published version and no body');
            $authenticated=(new SandIamContextAuthenticator())->authenticate($request,'sand_ai.tool.read');
            return ApiResponder::success((new \app\Application\Tool\PublishedReadonlyToolGateway())->read($authenticated['context'],$code,(int)$version),$requestId)->header('Cache-Control','no-store');
        } catch (ApiProblem $problem) { return ApiResponder::error($problem,$requestId)->header('Cache-Control','no-store'); }
    }

    public function run(Request $request, string $code, string $version): Response
    {
        $responseRequestId = RequestAuditId::ensure($request);
        try {
            $idempotencyKey = $this->idempotencyKey($request);
            try {
                $payload = StrictJsonObjectDecoder::decodeObject($request->rawBody());
            } catch (ApiProblem) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'External tool request must be a bounded JSON object with unique members');
            }
            $keys = is_array($payload) ? array_keys($payload) : [];
            sort($keys, SORT_STRING);
            if (!ctype_digit($version) || (int) $version < 1 || !is_array($payload) || array_is_list($payload)
                || $keys !== ['business_scope', 'input'] || !is_string($payload['business_scope'])
                || (!is_array($payload['input']) && !$payload['input'] instanceof \stdClass)
                || (is_array($payload['input']) && array_is_list($payload['input']))) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'External tool request envelope is invalid');
            }
            $authenticated = (new SandIamContextAuthenticator())->authenticate($request, 'sand_ai.tool.run');
            return ApiResponder::success(ToolGatewayFactory::run()->run(
                $authenticated['context'],
                $code,
                (int) $version,
                $payload['business_scope'],
                $payload['input'],
                $idempotencyKey,
                $request,
                $responseRequestId,
            ), $responseRequestId);
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $responseRequestId);
        }
    }

    /** Find the original persisted run without dispatching or recovering it. */
    public function readByKey(Request $request): Response
    {
        $responseRequestId = RequestAuditId::ensure($request);
        try {
            $query = $request->get();
            $key = $query['request_key'] ?? null;
            if (array_keys($query) !== ['request_key'] || $request->rawBody() !== ''
                || !is_string($key) || trim($key) === '' || strlen($key) > 128) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'External tool lookup requires only the original request_key and no body');
            }
            $authenticated = (new SandIamContextAuthenticator())->authenticate($request, 'sand_ai.tool.read');
            return ApiResponder::success(ToolGatewayFactory::run()->readByKey(
                $authenticated['context'], trim($key), $request, $responseRequestId,
            ), $responseRequestId)->header('Cache-Control', 'no-store');
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $responseRequestId)->header('Cache-Control', 'no-store');
        }
    }

    /** Bind original fulltext to uploaded bytes; no provider dispatch or caller-provided hash. */
    public function bindPrivateFulltextFile(Request $request): Response
    {
        $requestId = RequestAuditId::ensure($request);
        try {
            $payload = StrictJsonObjectDecoder::decodeObject($request->rawBody());
            $keys = is_array($payload) ? array_keys($payload) : [];
            sort($keys, SORT_STRING);
            if ($request->get() !== [] || !is_array($payload) || array_is_list($payload)
                || $keys !== ['file_id', 'request_key'] || !is_string($payload['request_key'])
                || trim($payload['request_key']) === '' || strlen($payload['request_key']) > 128
                || !is_int($payload['file_id']) || $payload['file_id'] < 1) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Private fulltext binding requires only the original request_key and actual file_id');
            }
            $authenticated = (new SandIamContextAuthenticator())->authenticate($request, 'sand_ai.tool.run');
            return ApiResponder::success((new \app\Application\Tool\ToolFulltextFileGateway())->bind(
                $authenticated['context'], trim($payload['request_key']), $payload['file_id'], $request, $requestId,
            ), $requestId)->header('Cache-Control', 'no-store');
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $requestId)->header('Cache-Control', 'no-store');
        }
    }

    /** Server workload consumption only, using the original request key and tool.read resource checks. */
    public function readPrivateFulltextByKey(Request $request): Response
    {
        $responseRequestId = RequestAuditId::ensure($request);
        try {
            $query = $request->get();
            $key = $query['request_key'] ?? null;
            if (array_keys($query) !== ['request_key'] || $request->rawBody() !== ''
                || !is_string($key) || trim($key) === '' || strlen($key) > 128) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Private fulltext lookup requires only the original request_key and no body');
            }
            $authenticated = (new SandIamContextAuthenticator())->authenticate($request, 'sand_ai.tool.read');
            return ApiResponder::success(ToolGatewayFactory::run()->readPrivateFulltextByKey(
                $authenticated['context'], trim($key), $request, $responseRequestId,
            ), $responseRequestId)->header('Cache-Control', 'no-store');
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $responseRequestId)->header('Cache-Control', 'no-store');
        }
    }

    public function read(Request $request, string $runId): Response
    {
        $responseRequestId = RequestAuditId::ensure($request);
        try {
            if (!ctype_digit($runId) || (int) $runId < 1) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'External tool run id is invalid');
            }
            $authenticated = (new SandIamContextAuthenticator())->authenticate($request, 'sand_ai.tool.read');
            return ApiResponder::success(ToolGatewayFactory::run()->read($authenticated['context'], (int) $runId, $request, $responseRequestId), $responseRequestId);
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $responseRequestId);
        }
    }

    private function idempotencyKey(Request $request): string
    {
        $key = $request->header('idempotency-key');
        if (!is_string($key) || trim($key) === '' || strlen($key) > 128) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Idempotency-Key is invalid');
        }
        return trim($key);
    }
}
