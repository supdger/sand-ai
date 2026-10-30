<?php

declare(strict_types=1);

namespace plugin\sandworkflow\app\logic {
    // Isolate persistence, while exercising the installed RuntimeService and DTOs.
    final class InstanceLogic
    {
        public static array $calls = [];

        public function startForContext(array $input, object $context, ?object $materializer = null): array
        {
            self::$calls[] = ['start', $input, $context, $materializer];
            return ['id' => 'instance-one'];
        }

        public function detailForActor(string $id, object $actor, array $scope, bool $monitoring): array
        {
            self::$calls[] = ['detail', $id, $actor, $scope, $monitoring];
            return ['id' => $id];
        }
    }
}

namespace {
    $root = getenv('SANDPACKAGE_TEST_ROOT') ?: dirname(__DIR__);
    $workflowRoot = getenv('SANDWORKFLOW_TEST_ROOT') ?: dirname($root) . '/sandworkflow';
    require $root . '/app/Api/Support/ApiProblem.php';
    require $root . '/app/Application/Workflow/SandWorkflowConsumer.php';
    require $workflowRoot . '/plugin/sandworkflow/app/contract/ActorRef.php';
    require $workflowRoot . '/plugin/sandworkflow/app/contract/WorkflowStartContext.php';

    use app\Api\Support\ApiProblem;
    use app\Application\Workflow\SandWorkflowConsumer;
    use plugin\sandworkflow\app\contract\ActorRef;
    use plugin\sandworkflow\app\contract\WorkflowStartContext;
    use plugin\sandworkflow\app\logic\InstanceLogic;

    $checks = 0;
    $assert = static function (bool $condition, string $message) use (&$checks): void {
        if (!$condition) throw new \RuntimeException($message);
        $checks++;
    };
    $consumer = new SandWorkflowConsumer();
    $actor = new ActorRef('sand_iam', 'person-42', ['name' => 'Verified person']);
    $context = new WorkflowStartContext('tenant-one', 'application-one', 'case', 'case-7', $actor,
        ['reviewer' => ['account_space' => 'sand_iam', 'account_id' => 'person-43']], 'case-7-material-v1');
    $scope = ['tenant_id' => 'tenant-one', 'owner_scope' => 'application-one',
        'business_type' => 'case', 'business_id' => 'case-7'];
    try {
        $consumer->start(17, 'Explicit business request', [], $context);
        throw new \RuntimeException('Missing optional Workflow was accepted');
    } catch (ApiProblem $problem) {
        $assert($problem->errorCode === 'SAND_AI_WORKFLOW_UNSUPPORTED', 'Missing optional plugin lost precise refusal');
    }
    $assert(InstanceLogic::$calls === [], 'Missing plugin reached persistence');

    require $workflowRoot . '/plugin/sandworkflow/app/service/WorkflowRuntimeService.php';
    $form = ['artifact_ref' => 'artifact-9', 'review_required' => true];
    $assert($consumer->start(17, 'Explicit business request', $form, $context) === ['id' => 'instance-one'], 'Start result changed');
    $start = InstanceLogic::$calls[0];
    $assert($start[1] === ['definition_id' => 17, 'name' => 'Explicit business request', 'form_value' => $form], 'Input gained a service node or changed business data');
    $assert($start[2] === $context && $start[2]->initiator === $actor, 'Verified business identity was replaced');
    $assert($start[2]->requestId === 'case-7-material-v1', 'Stable caller request key changed');
    $assert($start[2]->participants['reviewer']['account_id'] === 'person-43', 'Verified participant changed');
    $assert($start[3] === null, 'Adapter invented an organization provider');
    $assert(count(InstanceLogic::$calls) === 1, 'review_required triggered an extra workflow operation');
    $assert($consumer->detail('instance-one', $actor, $scope) === ['id' => 'instance-one'], 'Detail result changed');
    $detail = InstanceLogic::$calls[1];
    $assert($detail[2] === $actor && $detail[3] === $scope, 'Detail actor or full business scope changed');
    $assert($detail[4] === false, 'Detail enabled monitoring permission bypass');
    foreach ([[], array_diff_key($scope, ['tenant_id' => true]), $scope + ['organization_id' => '1'],
        array_replace($scope, ['tenant_id' => 1]), array_replace($scope, ['business_id' => ''])] as $invalid) {
        try {
            $consumer->detail('instance-one', $actor, $invalid);
            throw new \RuntimeException('An incomplete or inferred scope was accepted');
        } catch (ApiProblem $problem) {
            $assert($problem->errorCode === 'SAND_AI_VALIDATION_ERROR', 'Invalid scope lost precise refusal');
        }
    }
    $assert(count(InstanceLogic::$calls) === 2, 'Invalid scope reached Workflow');
    $emptyKey = new WorkflowStartContext('tenant-one', 'application-one', 'case', 'case-7', $actor, [], '');
    foreach ([$emptyKey, new WorkflowStartContext('tenant-one', 'application-one', 'case', 'case-7', $actor, [], str_repeat('x', 65))] as $invalidContext) {
        try {
            $consumer->start(17, 'Explicit business request', [], $invalidContext);
            throw new \RuntimeException('An unstable request key was accepted');
        } catch (ApiProblem $problem) {
            $assert($problem->errorCode === 'SAND_AI_VALIDATION_ERROR', 'Invalid request key lost precise refusal');
        }
    }
    $assert(count(InstanceLogic::$calls) === 2, 'Invalid request key reached Workflow');
    $assert(!method_exists($consumer, 'approve'), 'Adapter exposes machine approval');
    echo 'sandworkflow-consumer ' . $checks . '/' . $checks . "\n";
}
