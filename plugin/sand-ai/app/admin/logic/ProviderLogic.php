<?php

declare(strict_types=1);

namespace plugin\SandAi\app\admin\logic;

use plugin\SandAi\app\admin\validate\ProviderValidate;
use app\Application\Onboarding\CatalogOperationReceipt;
use app\Api\Support\ApiProblem;
use app\Infrastructure\Persistence\Model\ModelDeployment;
use app\Infrastructure\Persistence\Model\Provider;
use plugin\sandadmin\exception\ApiException;

/**
 * Provider management use case for the installable package.
 *
 * Controllers only translate HTTP. Validation, secret handling and persistence
 * stay inside this package-owned application layer.
 */
final class ProviderLogic
{
    public function __construct(private readonly ProviderValidate $validate = new ProviderValidate())
    {
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function index(array $input): array
    {
        $page = max(1, (int) ($input['page'] ?? 1));
        $limit = min(100, max(1, (int) ($input['limit'] ?? 10)));
        $query = Provider::order('id', 'desc');

        foreach (['code', 'name'] as $field) {
            $value = trim((string) ($input[$field] ?? ''));
            if ($value !== '') {
                $query->whereLike($field, '%' . $value . '%');
            }
        }
        if (($input['status'] ?? '') !== '') {
            $query->where('status', (int) $input['status']);
        }

        $paginator = $query->paginate(['page' => $page, 'list_rows' => $limit]);
        $result = $paginator->toArray();
        $result['data'] = array_map(fn (Provider $model): array => $this->present($model), $paginator->items());
        return $result;
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function read(array $input): array
    {
        return $this->present($this->find($input));
    }

    /** @param array<string, mixed> $input */
    public function create(array $input, int $admin = 0, ?string $key = null, ?\Closure $guard = null): int
    {
        if ($key === null) {
            $model = Provider::create($this->validate->create($input));
            return (int) $model->getKey();
        }
        if ($guard === null) throw new ApiProblem('SAND_AI_AUTHENTICATION_FAILED', 'Catalogue write identity is missing');
        $receipt = (new CatalogOperationReceipt())->execute('provider', 'save', $input, $admin, $key, $guard, function () use ($input): array {
            $model = Provider::create($this->validate->create($input));
            return $this->read(['id' => (int) $model->getKey()]);
        });
        return $receipt['id'];
    }

    /** @param array<string, mixed> $input */
    public function update(array $input, int $admin = 0, ?string $key = null, ?\Closure $guard = null): void
    {
        if ($key === null) {
            $model = $this->find($input);
            $model->save($this->validate->update($input));
            return;
        }
        if ($guard === null) throw new ApiProblem('SAND_AI_AUTHENTICATION_FAILED', 'Catalogue write identity is missing');
        (new CatalogOperationReceipt())->execute('provider', 'update', $input, $admin, $key, $guard, function () use ($input): array {
            $model = Provider::where('id', (int) ($input['id'] ?? 0))->lock(true)->find();
            if ($model === null) throw new ApiException('SAND_AI_RESOURCE_NOT_FOUND');
            $model->save($this->validate->update($input));
            return $this->read(['id' => (int) $model->getKey()]);
        });
    }

    /** @param array<string, mixed> $input */
    public function destroy(array $input): void
    {
        $ids = $this->ids($input['ids'] ?? []);
        if (ModelDeployment::whereIn('provider_id', $ids)->count() > 0) {
            throw new ApiException('SAND_AI_RESOURCE_REFERENCED: provider has deployments');
        }
        Provider::destroy($ids);
    }

    /** @param array<string, mixed> $input */
    private function find(array $input): Provider
    {
        $id = (int) ($input['id'] ?? 0);
        $model = Provider::findOrEmpty($id);
        if ($id <= 0 || $model->isEmpty()) {
            throw new ApiException('SAND_AI_RESOURCE_NOT_FOUND');
        }
        return $model;
    }

    /** @param mixed $value @return list<int> */
    private function ids(mixed $value): array
    {
        $values = is_array($value) ? $value : explode(',', (string) $value);
        $ids = array_values(array_filter(array_map('intval', $values)));
        if ($ids === []) {
            throw new ApiException('SAND_AI_VALIDATION_ERROR: ids is required');
        }
        return $ids;
    }

    /** @return array<string, mixed> */
    private function present(Provider $model): array
    {
        $row = $model->toArray();
        $row['has_encrypted_config'] = !empty($model->getAttr('encrypted_config'));
        unset($row['encrypted_config']);
        return $row;
    }
}
