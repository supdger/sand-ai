<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use app\Api\Support\ApiProblem;
use plugin\sandworkflow\app\contract\ActorRef;
use plugin\sandworkflow\app\contract\WorkflowStartContext;
use plugin\sandworkflow\app\service\WorkflowRuntimeService;

/** Explicit business handoff to the optional, installed Workflow plugin. */
final class SandWorkflowConsumer
{
    /** @param array<string,mixed> $formValue @return array<string,mixed> */
    public function start(
        int $definitionId,
        string $name,
        array $formValue,
        WorkflowStartContext $context,
    ): array {
        if ($definitionId < 1 || trim($name) === ''
            || trim($context->requestId) === '' || strlen($context->requestId) > 64) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'An explicit workflow definition, name and stable request key are required');
        }
        return $this->runtime()->start([
            'definition_id' => $definitionId,
            'name' => $name,
            'form_value' => $formValue,
        ], $context);
    }

    /**
     * The caller supplies a verified actor and Workflow business scope.
     * @param array{tenant_id:string,owner_scope:string,business_type:string,business_id:string} $scope
     * @return array<string,mixed>
     */
    public function detail(string $instanceId, ActorRef $actor, array $scope): array
    {
        $fields = ['tenant_id', 'owner_scope', 'business_type', 'business_id'];
        if ($instanceId === '' || count($scope) !== count($fields)
            || array_diff($fields, array_keys($scope)) !== []) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'The workflow instance and complete business scope are required');
        }
        foreach ($fields as $field) {
            if (!is_string($scope[$field]) || $scope[$field] === '' || strlen($scope[$field]) > 64) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'The workflow business scope is invalid');
            }
        }
        return $this->runtime()->detail($instanceId, $actor, $scope);
    }

    private function runtime(): WorkflowRuntimeService
    {
        if (!class_exists(WorkflowRuntimeService::class)) {
            throw new ApiProblem('SAND_AI_WORKFLOW_UNSUPPORTED', 'The optional SandWorkflow plugin is not installed');
        }
        return new WorkflowRuntimeService();
    }
}
