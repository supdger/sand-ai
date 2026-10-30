<?php

declare(strict_types=1);
namespace app\Api\Controller;
use app\Api\Support\ApiProblem;
use app\Api\Support\ApiResponder;
use app\Application\Composition\CompositionRunGateway;
use app\Application\Composition\CompositionServiceAdapter;
use app\Application\Gateway\SandIamContextAuthenticator;
use app\Identity\Infrastructure\RequestAuditId;
use support\Request;
use support\Response;

final class CompositionController
{
    public function submit(Request $request, string $code, string $version): Response
    {
        return $this->respond($request, function () use ($request, $code, $version): array {
            $context = (new SandIamContextAuthenticator())->authenticate($request, 'sand_ai.workflow.run')['context'];
            $body = $request->post();
            if (!is_array($body) || array_keys($body) !== ['input'] || !is_array($body['input']) || ($body['input'] !== [] && array_is_list($body['input']))) self::invalid();
            $read = (new SandIamContextAuthenticator())->authenticate($request, 'sand_ai.workflow.read')['context'];
            if ([$read->environmentId,$read->organizationId,$read->applicationId,$read->workloadClientId] !== [$context->environmentId,$context->organizationId,$context->applicationId,$context->workloadClientId]) self::invalid();
            $gateway = new CompositionRunGateway();
            $result = $gateway->submit($context, $code, $this->id($version), $body['input'], $this->key($request));
            return $gateway->read($read, $result['id'], new CompositionServiceAdapter($request));
        });
    }
    public function read(Request $request, string $runId): Response
    {
        return $this->respond($request, fn (): array => (new CompositionRunGateway())->read(
            (new SandIamContextAuthenticator())->authenticate($request, 'sand_ai.workflow.read')['context'],
            $this->id($runId), new CompositionServiceAdapter($request)));
    }
    public function byRequestKey(Request $request): Response
    {
        return $this->respond($request, function () use ($request): array {
            $query = $request->get();
            if (!is_array($query) || array_diff(array_keys($query), ['code','version','request_key']) !== [] || count($query) !== 3
                || !is_string($query['code']) || !is_string($query['request_key'])) self::invalid();
            $context = (new SandIamContextAuthenticator())->authenticate($request, 'sand_ai.workflow.read')['context'];
            $gateway = new CompositionRunGateway();
            return $gateway->read($context, $gateway->byKey($context, $query['code'], $this->id((string) $query['version']), $query['request_key']), new CompositionServiceAdapter($request));
        });
    }
    public function readOperation(Request $request, string $runId): Response
    {
        return $this->respond($request, function () use ($request, $runId): array {
            if (array_keys($request->get()) !== ['request_key'] || !is_string($request->get('request_key'))) self::invalid();
            return (new CompositionRunGateway())->readOperation((new SandIamContextAuthenticator())->authenticate($request, 'sand_ai.workflow.read')['context'], $this->id($runId), $request->get('request_key'));
        });
    }
    public function advance(Request $request, string $runId): Response { return $this->operation($request, $runId, 'advance'); }
    public function cancel(Request $request, string $runId): Response { return $this->operation($request, $runId, 'cancel'); }
    public function purgeInput(Request $request, string $runId): Response { return $this->operation($request, $runId, 'purge_input'); }
    public function retry(Request $request, string $runId, string $nodeId): Response
    { if (preg_match('/\A[a-z][a-z0-9_]{0,31}\z/', $nodeId) !== 1) return ApiResponder::error(new ApiProblem('SAND_AI_VALIDATION_ERROR','节点编号无效'), RequestAuditId::ensure($request)); return $this->operation($request, $runId, 'retry:' . $nodeId); }
    private function operation(Request $request, string $runId, string $operation): Response
    {
        return $this->respond($request, function () use ($request, $runId, $operation): array {
            if ($request->get() !== [] || !in_array(trim($request->rawBody()), ['', '{}'], true)) self::invalid();
            $context = (new SandIamContextAuthenticator())->authenticate($request, 'sand_ai.workflow.run')['context'];
            $authorize = function () use ($request, $context): void {
                $current = (new SandIamContextAuthenticator())->authenticate($request, 'sand_ai.workflow.run')['context'];
                if ([$current->environmentId,$current->organizationId,$current->applicationId,$current->workloadClientId] !== [$context->environmentId,$context->organizationId,$context->applicationId,$context->workloadClientId]) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND','工作流原调用归属已变化');
            };
            return (new CompositionRunGateway())->operate($context,$this->id($runId),$operation,$this->key($request),new CompositionServiceAdapter($request),$authorize);
        });
    }
    private function respond(Request $request, \Closure $callback): Response
    {
        $requestId=RequestAuditId::ensure($request);
        try { return ApiResponder::success($callback(),$requestId)->header('Cache-Control','no-store'); }
        catch (ApiProblem $problem) { return ApiResponder::error($problem,$requestId)->header('Cache-Control','no-store'); }
    }
    private function id(string $value): int { $id=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]); if ($id===false) self::invalid(); return (int)$id; }
    private function key(Request $request): string { $key=$request->header('idempotency-key'); if (!is_string($key)) self::invalid(); return $key; }
    private static function invalid(): never { throw new ApiProblem('SAND_AI_VALIDATION_ERROR','AI 工作流请求字段无效'); }
}
