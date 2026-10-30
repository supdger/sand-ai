<?php

declare(strict_types=1);

namespace app\Api\Controller;

use app\Api\Support\ApiProblem;
use app\Api\Support\ApiResponder;
use app\Application\Gateway\SandIamContextAuthenticator;
use app\Application\Gateway\RequestIdempotencyKey;
use app\Application\Task\AiTaskGateway;
use app\Application\Usage\UsageAttribution;
use app\Identity\Infrastructure\RequestAuditId;
use app\Identity\Infrastructure\TaskRunAccessGuard;
use support\Request;
use support\Response;

final class AiTaskController
{
    public function submit(Request $request): Response
    {
        $requestId = RequestAuditId::ensure($request);
        try {
            $context = (new SandIamContextAuthenticator())->authenticate($request, 'sand_ai.chat.complete');
            $idempotencyRequestId = RequestIdempotencyKey::required($request, 'ai_task', $context['context']);
            return ApiResponder::success((new AiTaskGateway())->submit(
                $context['environment_id'],
                $request->post(),
                $idempotencyRequestId,
                UsageAttribution::fromIdentity($context['context'], 'ai_task', 'sand_ai.chat.complete'),
                static fn (int $taskId): array => (new TaskRunAccessGuard())->authorize(
                    $request, $context['context'], $taskId, 'ai_completion', $requestId,
                ),
            ), $requestId);
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $requestId);
        }
    }
}
