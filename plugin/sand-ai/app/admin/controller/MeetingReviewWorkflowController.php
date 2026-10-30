<?php

declare(strict_types=1);

namespace plugin\SandAi\app\admin\controller;

use app\Api\Support\ApiProblem;
use app\Application\Workflow\MeetingReviewWorkflowBridge;
use app\Identity\Contract\IdentityContextException;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

/**
 * The explicit human entry point for handing a review_required meeting summary
 * to the installed SandWorkflow plugin. Nothing here approves, rejects or acts
 * on the business outcome; those stay with SandWorkflow and its own endpoints.
 */
final class MeetingReviewWorkflowController extends SandAiAdminController
{
    #[Permission('SandAI 提交会议摘要人工复核审批', 'sand_ai:meeting-review:submit')]
    public function submit(Request $request): Response
    {
        return $this->respond(fn (): array => (new MeetingReviewWorkflowBridge())
            ->submit($request->post(), $this->authenticatedAdminId()));
    }

    #[Permission('SandAI 读取会议摘要人工复核审批', 'sand_ai:meeting-review:read')]
    public function read(Request $request): Response
    {
        return $this->respond(fn (): array => (new MeetingReviewWorkflowBridge())
            ->read($request->get(), $this->authenticatedAdminId()));
    }

    private function respond(\Closure $operation): Response
    {
        try {
            return $this->success($operation());
        } catch (IdentityContextException $exception) {
            return json(['code' => 400, 'message' => $exception->errorCode . ': 当前环境归属无法核验，请检查 SandIAM 配置'])
                ->withStatus(400);
        } catch (ApiProblem $problem) {
            $status = $problem->errorCode === 'SAND_AI_MEETING_REVIEW_HANDOFF_FAILED' ? 409 : 400;
            return json(['code' => $status, 'message' => $problem->errorCode . ': ' . $problem->getMessage()])
                ->withStatus($status);
        }
    }
}
