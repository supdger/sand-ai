<?php

declare(strict_types=1);

namespace plugin\SandAi\app\admin\controller;

use app\Api\Support\ApiProblem;
use app\Application\Audio\MeetingExecutionGateway;
use app\Identity\Contract\IdentityContextException;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

final class MeetingExecutionController extends SandAiAdminController
{
    #[Permission('SandAI 读取会议执行设置', 'sand_ai:setup:deployment:read')]
    public function read(Request $request): Response
    {
        return $this->respond(fn (): array => (new MeetingExecutionGateway())->read($request->get()));
    }

    #[Permission('SandAI 保存会议执行设置', 'sand_ai:setup:deployment:save')]
    public function register(Request $request): Response
    {
        return $this->respond(fn (): array => (new MeetingExecutionGateway())->save($request->post(), $this->authenticatedAdminId(), false));
    }

    #[Permission('SandAI 启用或撤销会议执行', 'sand_ai:onboarding:publish')]
    public function state(Request $request): Response
    {
        return $this->respond(fn (): array => (new MeetingExecutionGateway())->save($request->post(), $this->authenticatedAdminId(), true));
    }

    private function respond(\Closure $operation): Response
    {
        try { return $this->success($operation()); }
        catch (IdentityContextException $exception) {
            return json(['code' => 400, 'message' => $exception->errorCode . ': 当前环境归属无法核验，请检查 SandIAM 配置'])->withStatus(400);
        } catch (ApiProblem $problem) {
            $status = in_array($problem->errorCode, ['SAND_AI_MEETING_EXECUTION_CONFLICT', 'SAND_AI_MEETING_WORKER_UNAVAILABLE'], true) ? 409 : 400;
            return json(['code' => $status, 'message' => $problem->errorCode . ': ' . $problem->getMessage()])->withStatus($status);
        }
    }
}
