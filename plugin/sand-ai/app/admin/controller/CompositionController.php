<?php

declare(strict_types=1);
namespace plugin\SandAi\app\admin\controller;
use app\Api\Support\ApiProblem;
use app\Application\Composition\CompositionDefinitionGateway;
use app\Application\Composition\CompositionServiceCatalog;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

final class CompositionController extends SandAiAdminController
{
    #[Permission('SandAI 查看 AI 工作流','sand_ai:agent:composition:read')]
    public function index(Request $request): Response { return $this->respond(fn (): array => (new CompositionDefinitionGateway())->index($this->environment($request))); }
    #[Permission('SandAI 读取 AI 工作流版本','sand_ai:agent:composition:read')]
    public function read(Request $request): Response { return $this->respond(function () use ($request): array { $code=$request->get('code'); if (!is_string($code)) self::invalid(); return (new CompositionDefinitionGateway())->read($this->environment($request),$code); }); }
    #[Permission('SandAI 查看可组合服务','sand_ai:agent:composition:read')]
    public function catalog(Request $request): Response { return $this->respond(fn (): array => (new CompositionServiceCatalog())->catalog($this->environment($request))); }
    #[Permission('SandAI 编辑 AI 工作流','sand_ai:agent:composition:manage')]
    public function saveDraft(Request $request): Response { return $this->mutate($request,'draft',['environment_id','code','name','expected_revision','graph']); }
    #[Permission('SandAI 发布 AI 工作流','sand_ai:agent:composition:manage')]
    public function publish(Request $request): Response { return $this->mutate($request,'publish',['environment_id','code','expected_revision']); }
    #[Permission('SandAI 停用 AI 工作流','sand_ai:agent:composition:manage')]
    public function disable(Request $request): Response { return $this->mutate($request,'disable',['environment_id','code']); }
    #[Permission('SandAI 找回 AI 工作流操作','sand_ai:agent:composition:manage')]
    public function byRequestKey(Request $request): Response
    {
        return $this->respond(function () use ($request): array {
            $operation=$request->get('operation'); $key=$request->get('request_key');
            if (!is_string($operation) || !in_array($operation,['draft','publish','disable'],true) || !is_string($key)) self::invalid();
            return (new CompositionDefinitionGateway())->readOperation($this->environment($request),$operation,$key,(string)$this->authenticatedAdminId());
        });
    }
    private function mutate(Request $request,string $operation,array $allowed): Response
    {
        return $this->respond(function () use ($request,$operation,$allowed): array {
            $body=$request->post(); $key=$request->header('idempotency-key');
            $optional=in_array($operation,['draft','publish'],true)?['version_id']:[];
            if (!is_array($body) || array_diff(array_keys($body),array_merge($allowed,$optional))!==[]
                || array_diff($allowed,array_keys($body))!==[] || !is_string($key) || !is_int($body['environment_id']??null)) self::invalid();
            $admin=$this->authenticatedAdminId();
            $authorize=function () use ($admin): void {
                if ($this->authenticatedAdminId()!==$admin || ($admin!==1 && !in_array('sand_ai:agent:composition:manage',\plugin\sandadmin\app\cache\UserAuthCache::getUserAuth($admin),true))) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND','工作流管理权限已变化');
            };
            return (new CompositionDefinitionGateway())->mutate($body['environment_id'],$operation,$body,$key,(string)$admin,$authorize);
        });
    }
    private function environment(Request $request): int { $id=filter_var($request->get('environment_id'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]); if ($id===false) self::invalid(); return (int)$id; }
    private function respond(\Closure $callback): Response { try { $this->authenticatedAdminId(); return $this->success($callback(),'AI 工作流操作已完成')->header('Cache-Control','no-store'); } catch (ApiProblem $problem) { throw new ApiException($problem->errorCode . ': ' . $problem->getMessage()); } }
    private static function invalid(): never { throw new ApiProblem('SAND_AI_VALIDATION_ERROR','AI 工作流配置请求无效'); }
}
