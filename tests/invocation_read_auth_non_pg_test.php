<?php

declare(strict_types=1);

namespace InvocationReadFixture {
    final class Query
    {
        private array $filters = [];

        public function __construct(private readonly array $rows, private readonly ?string $model = null)
        {
        }

        public function where(string $field, mixed $value): self
        {
            $field = str_contains($field, '.') ? substr($field, strrpos($field, '.') + 1) : $field;
            $this->filters[$field] = $value;
            return $this;
        }

        public function leftJoin(string $table, string $on): self { return $this; }
        public function field(string $fields): self { return $this; }
        public function fieldRaw(string $fields): self { return $this; }

        public function whereNull(string $field): self
        {
            $this->filters[$field] = null;
            return $this;
        }

        public function find(): ?object
        {
            foreach ($this->rows as $row) {
                foreach ($this->filters as $field => $value) {
                    if (($row[$field] ?? null) !== $value) continue 2;
                }
                return $this->model === null ? (object) $row : new ($this->model)($row);
            }
            return null;
        }
    }
}

namespace app\Infrastructure\Persistence\Model {
    class Invocation
    {
        public static array $rows = [];
        public function __construct(private readonly array $row) {}
        public function __get(string $field): mixed { return $this->row[$field] ?? null; }
        public function getAttr(string $field): mixed { return $this->row[$field] ?? null; }
        public static function where(string $field, mixed $value): \InvocationReadFixture\Query
        {
            return (new \InvocationReadFixture\Query(self::$rows, self::class))->where($field, $value);
        }
    }

    class InvocationDispatch
    {
        public static array $rows = [];
        public function __construct(private readonly array $row) {}
        public function __get(string $field): mixed { return $this->row[$field] ?? null; }
        public static function where(string $field, mixed $value): \InvocationReadFixture\Query
        {
            return (new \InvocationReadFixture\Query(self::$rows, self::class))->where($field, $value);
        }
    }

    class UsageEvent
    {
        public static int $reads = 0;
        public static array $rows = [];
        public function __construct(private readonly array $row) {}
        public function __get(string $field): mixed { return $this->row[$field] ?? null; }
        public function getAttr(string $field): mixed { return $this->row[$field] ?? null; }
        public static function alias(string $alias): \InvocationReadFixture\Query
        {
            ++self::$reads;
            return new \InvocationReadFixture\Query(self::$rows, self::class);
        }
    }
}

namespace {
    $vendor = getenv('SANDPACKAGE_TEST_VENDOR');
    if (!is_string($vendor) || !is_file($vendor)) {
        throw new \RuntimeException('Set SANDPACKAGE_TEST_VENDOR to a Composer autoload file');
    }
    require $vendor;
    $app = dirname(__DIR__) . '/app/';
    spl_autoload_register(static function (string $class) use ($app): void {
        if (!str_starts_with($class, 'app\\')) return;
        $path = $app . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($path)) require $path;
    }, true, true);
    require dirname(__DIR__) . '/plugin/sand-ai/app/admin/logic/UsageCostLogic.php';

    $invocation = [
        'id' => 91, 'status' => 1, 'delete_time' => null,
        'request_id' => 'cost-request-1234', 'state' => 'succeeded',
        'organization_id' => 11, 'application_id' => 22, 'environment_id' => 33,
        'workload_client_id' => 44, 'data_class' => null,
        'latency_ms' => 5, 'error_code' => null,
    ];
    $dispatch = [
        'invocation_id' => 91, 'environment_id' => 33, 'application_id' => 22,
        'execution_scope' => 'chat', 'source_kind' => 'chat',
        'service_action' => 'sand_ai.chat.complete',
    ];
    \app\Infrastructure\Persistence\Model\Invocation::$rows = [$invocation];
    \app\Infrastructure\Persistence\Model\InvocationDispatch::$rows = [$dispatch];
    $resolver = new \app\Identity\Infrastructure\InvocationReadFacts();
    foreach (['sand_ai.invocation.read', 'sand_ai.usage.read'] as $action) {
        $facts = $resolver->resolve('sand_ai', $action, '91');
        if ($facts !== [
            'organization_id' => 11, 'application_id' => 22, 'environment_id' => 33,
            'workload_client_id' => 44, 'data_class' => null,
            'resource_type' => 'sand_ai.invocation', 'resource_ref' => '91',
        ]) throw new \RuntimeException('Invocation read facts are incorrect');
    }
    $catalogPort = new class {
        public array $actions = [];
        public function inspectServiceActions(array $service, array $actions): array
        {
            return ['service_code' => $service['code'], 'service_status' => 'missing',
                'action_statuses' => array_fill_keys(array_keys($actions), 'missing'), 'can_register' => true];
        }
        public function registerServiceActions(array $service, array $actions): void
        {
            if ($service['code'] !== 'sand_ai') throw new \RuntimeException('Wrong service');
            $this->actions = $actions;
        }
    };
    (new \app\Identity\Infrastructure\HostSandIamServiceCatalog($catalogPort))->register();
    foreach (['sand_ai.invocation.read', 'sand_ai.usage.read'] as $action) {
        if (!isset($catalogPort->actions[$action])) {
            throw new \RuntimeException('Read action missing from SandIAM catalog');
        }
    }
    foreach ([
        ['ai_task', 'ai_task', 'sand_ai.chat.complete'],
        ['agent', 'agent', 'sand_ai.agent.run'],
        ['structured_workflow', 'structured_workflow', 'sand_ai.workflow.run'],
        ['skill', 'skill', 'sand_ai.skill.run'],
        ['skill', 'skill_test', 'sand_ai.skill.run'],
    ] as [$scope, $kind, $action]) {
        $facts = \app\Identity\Infrastructure\InvocationReadFacts::fromPersisted(
            [...$invocation, 'data_class' => 'confidential'],
            [...$dispatch, 'execution_scope' => $scope, 'source_kind' => $kind, 'service_action' => $action],
            91,
        );
        if ($facts['data_class'] !== 'confidential') {
            throw new \RuntimeException('Classified invocation facts were lost');
        }
    }

    $rejectFacts = static function (array $changedInvocation, array $changedDispatch) use ($resolver): void {
        \app\Infrastructure\Persistence\Model\Invocation::$rows = [$changedInvocation];
        \app\Infrastructure\Persistence\Model\InvocationDispatch::$rows = [$changedDispatch];
        try {
            $resolver->resolve('sand_ai', 'sand_ai.usage.read', '91');
        } catch (\app\Identity\Contract\IdentityContextException $error) {
            if ($error->errorCode === 'SAND_AI_INVOCATION_FACTS_UNVERIFIED') return;
            throw $error;
        }
        throw new \RuntimeException('Invalid invocation facts were accepted');
    };
    $rejectFacts([...$invocation, 'application_id' => 23], $dispatch);
    $rejectFacts([...$invocation, 'workload_client_id' => null], $dispatch);
    $rejectFacts($invocation, [...$dispatch, 'execution_scope' => 'legacy_unverified']);
    $rejectFacts($invocation, [...$dispatch, 'service_action' => 'sand_ai.agent.run']);
    \app\Infrastructure\Persistence\Model\Invocation::$rows = [$invocation];
    \app\Infrastructure\Persistence\Model\InvocationDispatch::$rows = [$dispatch];

    $deny = static function (): never {
        throw new \app\Api\Support\ApiProblem('SAND_AI_SERVICE_ACTION_FORBIDDEN', 'Read denied');
    };
    foreach ([
        static fn () => (new \app\Application\Gateway\ChatGateway())->invocation(
            33, 'cost-request-1234', $deny,
        ),
        static fn () => (new \app\Application\Usage\UsageGateway())->invocationCost(
            33, 'cost-request-1234', $deny,
        ),
    ] as $read) {
        try {
            $read();
            throw new \RuntimeException('Invocation read returned without authorization');
        } catch (\app\Api\Support\ApiProblem $error) {
            if ($error->errorCode !== 'SAND_AI_SERVICE_ACTION_FORBIDDEN') throw $error;
        }
    }
    if (\app\Infrastructure\Persistence\Model\UsageEvent::$reads !== 0) {
        throw new \RuntimeException('Usage was queried before authorization');
    }
    \app\Infrastructure\Persistence\Model\UsageEvent::$rows = [[
        'invocation_id' => 91, 'organization_id' => 11, 'application_id' => 23,
        'environment_id' => 33, 'workload_client_id' => 44,
    ]];
    try {
        (new \app\Application\Usage\UsageGateway())->invocationCost(
            33, 'cost-request-1234', static function (): void {},
        );
        throw new \RuntimeException('Cross-application usage event was returned');
    } catch (\app\Api\Support\ApiProblem $error) {
        if ($error->errorCode !== 'SAND_AI_INVOCATION_FACTS_UNVERIFIED') throw $error;
    }
    \app\Infrastructure\Persistence\Model\UsageEvent::$rows = [];
    foreach ([
        'not_dispatched' => ['not_dispatched', 'not_applicable', 'provider_not_dispatched'],
        'failed' => ['failed', 'held', 'provider_rejected_without_usage'],
        'ambiguous' => ['ambiguous', 'held', 'dispatch_ambiguous'],
        'prepared' => ['pending', 'pending', 'dispatch_in_progress'],
        'dispatching' => ['pending', 'pending', 'dispatch_in_progress'],
        'succeeded' => ['missing_event', 'held', 'usage_event_missing'],
    ] as $state => [$usageState, $reconciliationState, $reason]) {
        \app\Infrastructure\Persistence\Model\InvocationDispatch::$rows = [[
            ...$dispatch, 'state' => $state, 'route_snapshot' => [],
            'price_snapshot' => [], 'deployment_id' => 56,
            'capability_code' => 'chat',
        ]];
        $cost = (new \app\Application\Usage\UsageGateway())->invocationCost(
            33, 'cost-request-1234', static function (): void {},
        );
        if ($cost['usage']['state'] !== $usageState
            || $cost['reconciliation']['state'] !== $reconciliationState
            || $cost['reconciliation']['reason'] !== $reason
            || $cost['price']['metered_amount'] !== null
            || $cost['reconciliation']['billed_amount'] !== null
            || $cost['tokens']['total_tokens'] !== null) {
            throw new \RuntimeException("Dispatch {$state} invented a cost or returned the wrong status");
        }
    }
    foreach (['unmeasured' => null, 'measured' => 0] as $state => $expectedTokens) {
        $row = [
            'invocation_id' => 91, 'organization_id' => 11, 'application_id' => 22,
            'environment_id' => 33, 'workload_client_id' => 44,
            'usage_state' => $state, 'pricing_state' => 'unpriced',
            'input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0,
            'deployment_id' => 56, 'capability_code' => 'chat',
            'service_action' => 'sand_ai.chat.complete', 'source_kind' => 'chat',
        ];
        \app\Infrastructure\Persistence\Model\UsageEvent::$rows = [$row];
        $cost = (new \app\Application\Usage\UsageGateway())->invocationCost(
            33, 'cost-request-1234', static function (): void {},
        );
        $summary = (new \app\Application\Usage\UsageMeter())->summary(
            new \app\Infrastructure\Persistence\Model\UsageEvent($row),
        );
        foreach ([$cost['tokens'], $summary['tokens']] as $tokens) {
            foreach (['input_tokens', 'output_tokens', 'total_tokens'] as $field) {
                if ($tokens[$field] !== $expectedTokens) {
                    throw new \RuntimeException("{$state} {$field} was presented incorrectly");
                }
            }
        }
    }
    \app\Infrastructure\Persistence\Model\InvocationDispatch::$rows = [[
        ...$dispatch, 'state' => 'succeeded', 'route_snapshot' => [],
        'price_snapshot' => [], 'deployment_id' => 56, 'capability_code' => 'chat',
    ]];
    \app\Infrastructure\Persistence\Model\UsageEvent::$rows = [];
    $adminCost = (new \plugin\SandAi\app\admin\logic\UsageCostLogic())->read([
        'environment_id' => '33', 'request_id' => 'cost-request-1234',
    ]);
    if ($adminCost['usage']['state'] !== 'missing_event'
        || $adminCost['price']['metered_amount'] !== null) {
        throw new \RuntimeException('Administrator cost invented a missing usage event');
    }
    $readsBeforeReject = \app\Infrastructure\Persistence\Model\UsageEvent::$reads;
    \app\Infrastructure\Persistence\Model\InvocationDispatch::$rows[0]['application_id'] = 23;
    try {
        (new \plugin\SandAi\app\admin\logic\UsageCostLogic())->read([
            'environment_id' => '33', 'request_id' => 'cost-request-1234',
        ]);
        throw new \RuntimeException('Administrator cost accepted mismatched dispatch ownership');
    } catch (\app\Api\Support\ApiProblem $error) {
        if ($error->errorCode !== 'SAND_AI_INVOCATION_FACTS_UNVERIFIED') throw $error;
    }
    if (\app\Infrastructure\Persistence\Model\UsageEvent::$reads !== $readsBeforeReject) {
        throw new \RuntimeException('Administrator cost queried usage before verifying invocation facts');
    }
    \app\Infrastructure\Persistence\Model\InvocationDispatch::$rows[0]['application_id'] = 22;
    \app\Infrastructure\Persistence\Model\UsageEvent::$rows = [[
        'invocation_id' => 91, 'organization_id' => 11, 'application_id' => 22,
        'environment_id' => 33, 'workload_client_id' => 44,
        'usage_state' => 'unmeasured', 'pricing_state' => 'unpriced',
        'input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0,
        'deployment_id' => 56, 'capability_code' => 'chat',
        'service_action' => 'sand_ai.chat.complete', 'source_kind' => 'chat',
    ]];
    $adminCost = (new \plugin\SandAi\app\admin\logic\UsageCostLogic())->read([
        'environment_id' => '33', 'request_id' => 'cost-request-1234',
    ]);
    if ($adminCost['usage']['state'] !== 'unmeasured'
        || $adminCost['tokens']['total_tokens'] !== null
        || $adminCost['price']['metered_amount'] !== null) {
        throw new \RuntimeException('Administrator cost misrepresented an unmeasured usage event');
    }
    foreach ([['environment_id' => '0', 'request_id' => 'cost-request-1234'], ['environment_id' => '33', 'request_id' => ''], ['environment_id' => '33', 'request_id' => 'cost-request-1234', 'status' => '1']] as $invalid) {
        try {
            (new \plugin\SandAi\app\admin\logic\UsageCostLogic())->read($invalid);
            throw new \RuntimeException('Invalid administrator cost query was accepted');
        } catch (\app\Api\Support\ApiProblem $error) {
            if ($error->errorCode !== 'SAND_AI_VALIDATION_ERROR') throw $error;
        }
    }
    echo "invocation read and dispatch cost non-PG checks passed\n";
}
