<?php

declare(strict_types=1);

namespace plugin\SandAi\app\admin\logic;

use plugin\SandAi\app\admin\validate\ModelValidate;
use plugin\SandAi\app\model\AiModel;
use plugin\SandAi\app\model\ModelDeployment;
use plugin\sandadmin\exception\ApiException;

/** Package-owned model catalogue management use case. */
final class ModelLogic
{
    public function __construct(private readonly ModelValidate $validate = new ModelValidate())
    {
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function index(array $input): array
    {
        $page = max(1, (int) ($input['page'] ?? 1));
        $limit = min(100, max(1, (int) ($input['limit'] ?? 10)));
        $query = AiModel::order('id', 'desc');

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
        $model = AiModel::create($this->validate->create($input));
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
        if (ModelDeployment::whereIn('model_id', $ids)->count() > 0) {
            throw new ApiException('SAND_AI_RESOURCE_REFERENCED: model is deployed');
        }
        AiModel::destroy($ids);
    }

    /** @param array<string, mixed> $input */
    private function find(array $input): AiModel
    {
        $id = (int) ($input['id'] ?? 0);
        $model = AiModel::findOrEmpty($id);
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
        if (isset($row['capabilities']) && is_object($row['capabilities'])) {
            $row['capabilities'] = array_values(array_filter((array) $row['capabilities'], 'is_string'));
        }
        return $row;
    }
}
