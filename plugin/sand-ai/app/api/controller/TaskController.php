<?php

declare(strict_types=1);

namespace plugin\SandAi\app\api\controller;

use plugin\SandAi\app\api\support\ApiProblem;
use plugin\SandAi\app\api\support\ApiResponder;
use plugin\SandAi\app\api\support\IdentityContextProblem;
use plugin\SandAi\app\contract\IdentityContextException;
use plugin\SandAi\app\contract\IdentityContextProvider;
use plugin\SandAi\app\domain\task\TaskGateway;
use support\Request;
use support\Response;

/** Local package task observation; never represents a business approval state. */
final class TaskController
{
    public function __construct(private readonly IdentityContextProvider $identityContexts)
    {
    }

    public function read(Request $request, string $taskId): Response
    {
        return $this->respond($request, fn (): array => (new TaskGateway())->read(
            $this->identityContexts->requireContext($request, 'sand_ai.task.read')->requireAction('sand_ai.task.read')->environmentId,
            $this->id($taskId),
        ));
    }

    public function cancel(Request $request, string $taskId): Response
    {
        return $this->respond($request, fn (): array => (new TaskGateway())->cancel(
            $this->identityContexts->requireContext($request, 'sand_ai.task.cancel')->requireAction('sand_ai.task.cancel')->environmentId,
            $this->id($taskId),
        ));
    }

    public function retry(Request $request, string $taskId): Response
    {
        return $this->respond($request, fn (): array => (new TaskGateway())->retry(
            $this->identityContexts->requireContext($request, 'sand_ai.task.retry')->requireAction('sand_ai.task.retry')->environmentId,
            $this->id($taskId),
        ));
    }

    private function id(string $taskId): int
    {
        $id = filter_var($taskId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'A valid task id is required');
        }

        return $id;
    }

    /** @param callable(): array<string, mixed> $action */
    private function respond(Request $request, callable $action): Response
    {
        $requestId = trim((string) $request->header('x-request-id', '')) ?: 'req_' . bin2hex(random_bytes(12));
        try {
            return ApiResponder::success($action(), $requestId);
        } catch (IdentityContextException $exception) {
            return ApiResponder::error(IdentityContextProblem::from($exception), $requestId);
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $requestId);
        }
    }
}
