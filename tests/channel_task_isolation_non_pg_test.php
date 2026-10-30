<?php

declare(strict_types=1);

namespace app\Infrastructure\Persistence\Model {
    final class TaskRun
    {
        public array $input = ['_channel' => ['channel_binding_id' => 20]];
        public static function where(string $field, mixed $value): self { return new self(); }
        public function find(): self { return $this; }
    }
}
namespace app\Application\Task {
    final class TaskInputAccessGuard { public function assert(\app\Infrastructure\Persistence\Model\TaskRun $task, ?string $scope): void {} }
    final class StructuredTaskAccessGuard { public function assert(\app\Infrastructure\Persistence\Model\TaskRun $task, ?string $scope): void {} }
}
namespace {
    require dirname(__DIR__) . '/app/Api/Support/ApiProblem.php';
    require dirname(__DIR__) . '/app/Application/Task/TaskGateway.php';
    $gateway = new \app\Application\Task\TaskGateway();
    $called = false;
    foreach (['read', 'cancel', 'retry'] as $method) {
        try {
            $gateway->$method(33, 77, str_repeat('a', 64), function () use (&$called): void { $called = true; });
            throw new \RuntimeException('Generic task action accepted channel task');
        } catch (\app\Api\Support\ApiProblem $problem) {
            if ($problem->errorCode !== 'SAND_AI_RESOURCE_NOT_FOUND') throw $problem;
        }
    }
    if ($called) throw new \RuntimeException('Generic task authorization reached channel task');
    echo "channel-task-isolation 3/3\n";
}
