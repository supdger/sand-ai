<?php

declare(strict_types=1);

namespace app\Application\Budget;

use app\Api\Support\ApiProblem;
use app\Application\Usage\UsageAttribution;
use think\facade\Db;

/** Trusted dimensions of one actual capability call, never request-supplied ownership. */
final readonly class BudgetScopeContext
{
    /** @param list<array{scope_kind:string,scope_ref:string}> $scopes */
    private function __construct(public array $scopes)
    {
    }

    public static function forCall(UsageAttribution $attribution, int $modelId, string $capabilityCode): self
    {
        $organization = $attribution->identitySnapshot['organization_ref'] ?? null;
        $application = $attribution->identitySnapshot['application_ref'] ?? null;
        if ($attribution->environmentId < 1 || $modelId < 0
            || !is_string($organization) || trim($organization) === ''
            || !is_string($application) || trim($application) === ''
            || $attribution->capabilityCode !== $capabilityCode) {
            throw new ApiProblem('SAND_AI_BUDGET_SCOPE_UNVERIFIED', 'Trusted identity and capability are required for budget selection');
        }
        $scopes = [
            ['scope_kind' => 'organization', 'scope_ref' => $organization],
            ['scope_kind' => 'application', 'scope_ref' => $application],
            ['scope_kind' => 'environment', 'scope_ref' => (string) $attribution->environmentId],
            ['scope_kind' => 'service', 'scope_ref' => 'sand_ai'],
            ['scope_kind' => 'capability', 'scope_ref' => $capabilityCode],
        ];
        if ($modelId > 0) $scopes[] = ['scope_kind' => 'model', 'scope_ref' => (string) $modelId];
        return new self($scopes);
    }

    public static function key(string $kind, string $reference): string
    {
        return 'sand_ai_budget_scope:' . $kind . ':' . $reference;
    }

    /** Called in the preparation transaction before locking any policy/window. */
    public function lockForSelection(): void
    {
        $keys = array_map(static fn (array $scope): string => self::key($scope['scope_kind'], $scope['scope_ref']), $this->scopes);
        sort($keys, SORT_STRING);
        foreach ($keys as $key) {
            Db::connect('pgsql')->query('SELECT pg_advisory_xact_lock_shared(hashtextextended(?, 132))', [$key]);
        }
    }
}
