<?php

declare(strict_types=1);

namespace plugin\SandAi\app\admin\logic;

use plugin\SandAi\app\admin\validate\ProviderValidate;
use plugin\SandAi\app\model\ModelDeployment;
use plugin\SandAi\app\model\Provider;
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

        $result = $query->paginate(['page' => $page, 'list_rows' => $limit])->toArray();
        $result['data'] = array_map(fn (array $row): array => $this->present($row), $result['data']);
        return $result;
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function read(array $input): array
    {
        return $this->present($this->find($input)->toArray());
    }

    /** @param array<string, mixed> $input */
    public function create(array $input): int
    {
        $model = Provider::create($this->validate->create($input));
        return (int) $model->getKey();
    }

    /** @param array<string, mixed> $input */
    public function update(array $input): void
    {
        $model = $this->find($input);
        $model->save($this->validate->update($input));
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

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function present(array $row): array
    {
        $row['has_encrypted_config'] = !empty($row['encrypted_config']);
        unset($row['encrypted_config']);
        return $row;
    }
}
