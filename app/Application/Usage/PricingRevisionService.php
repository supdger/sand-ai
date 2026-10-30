<?php

declare(strict_types=1);

namespace app\Application\Usage;

use app\Api\Support\ApiProblem;
use app\Infrastructure\Capability\CapabilityDriverRegistry;
use app\Infrastructure\Persistence\Model\PriceRate;
use app\Infrastructure\Persistence\Model\PriceRevision;
use think\db\ConnectionInterface;
use think\facade\Db;
use Throwable;

final class PricingRevisionService
{
    /** @return array<string, mixed> */
    public function list(int $page, int $limit, array $filters): array
    {
        $query = PriceRevision::order('effective_from', 'desc')->order('id', 'desc');
        foreach (['provider_id', 'model_id'] as $field) {
            if (($filters[$field] ?? null) !== null) {
                $query->where($field, (int) $filters[$field]);
            }
        }
        foreach (['capability_code', 'state'] as $field) {
            if (($filters[$field] ?? null) !== null) {
                $query->where($field, (string) $filters[$field]);
            }
        }

        $result = $query->paginate(['page' => $page, 'list_rows' => $limit])->toArray();
        $result['data'] = array_map(fn (array $row): array => $this->summary($row), $result['data']);

        return $result;
    }

    /** @return array<string, mixed> */
    public function read(int $id): array
    {
        $revision = PriceRevision::find($id);
        if ($revision === null) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Price revision not found');
        }

        $data = $revision->toArray();
        $data['rates'] = PriceRate::where('price_revision_id', $id)->order('meter_code')->select()->toArray();

        return $this->summary($data);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function saveDraft(array $input, int $adminId, ?string $requestKey = null, ?\Closure $revalidate = null): array
    {
        $this->assertAdmin($adminId);
        $revalidate?->__invoke();
        $operations = new PricingOperationReceipt();
        $requestKey = $operations->key($requestKey);
        $hash = $operations->fingerprint($input);
        $data = (new PricingRevisionPolicy())->normalizeDraft($input);
        $maxAttempts = $data['id'] === null ? 2 : 1;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                return Db::connect('pgsql')->transaction(function (ConnectionInterface $connection) use ($data, $adminId, $operations, $requestKey, $hash, $revalidate): array {
                    $revalidate?->__invoke();
                    if ($requestKey !== null && ($original = $operations->replay($connection, $adminId, 'save', $requestKey, $hash)) !== null) {
                        $revalidate?->__invoke();
                        return $original;
                    }
                    $provider = $this->lockRuntimeTargets($connection, $data['provider_id'], $data['model_id'], $data['capability_code']);
                    $data['rates'] = (new PricingValuePolicy())->draftRates($data['rates'], $data['capability_code'], (string) $provider['adapter']);
                    $revisionId = $data['id'] === null
                        ? $this->createDraft($connection, $data, $adminId)
                        : $this->updateDraft($connection, (int) $data['id'], $data, $adminId);
                    $connection->table('sand_ai_price_rate')->where('price_revision_id', $revisionId)->delete();
                    foreach ($data['rates'] as $rate) {
                        $connection->query(
                            'INSERT INTO sand_ai_price_rate (price_revision_id, meter_code, unit_price, per_quantity) VALUES (?, ?, CAST(? AS numeric(20,8)), ?) RETURNING id',
                            [$revisionId, $rate['meter_code'], ...NumericSql::binds($rate['unit_price']), $rate['per_quantity']]
                        );
                    }

                    $this->audit($connection, $adminId, 'pricing.revision.draft', $revisionId, 'Saved SandAI capability price draft');

                    $receipt = $this->readFromConnection($connection, $revisionId);
                    if ($requestKey !== null) $operations->record($connection, $adminId, 'save', $requestKey, $hash, $data['id'], $receipt);
                    $revalidate?->__invoke();
                    return $receipt;
                });
            } catch (Throwable $throwable) {
                if (!$this->isUniqueViolation($throwable)) {
                    throw $throwable;
                }
                if ($attempt < $maxAttempts) {
                    continue;
                }
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'A concurrent price revision already claimed this version');
            }
        }

        throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'The price revision could not be allocated');
    }

    /** @return array<string, mixed> */
    public function publish(int $id, int $adminId, ?string $requestKey = null, ?\Closure $revalidate = null): array
    {
        $this->assertAdmin($adminId);
        $revalidate?->__invoke();
        $operations = new PricingOperationReceipt();
        $requestKey = $operations->key($requestKey);
        $hash = $operations->fingerprint(['id' => $id]);
        return Db::connect('pgsql')->transaction(function (ConnectionInterface $connection) use ($id, $adminId, $operations, $requestKey, $hash, $revalidate): array {
            $revalidate?->__invoke();
            if ($requestKey !== null && ($original = $operations->replay($connection, $adminId, 'publish', $requestKey, $hash)) !== null) {
                $revalidate?->__invoke();
                return $original;
            }
            $reference = $connection->table('sand_ai_price_revision')
                ->field(['id', 'provider_id', 'model_id', 'capability_code'])->where('id', $id)->find();
            if (!is_array($reference)) {
                throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Price revision not found');
            }
            $provider = $this->lockRuntimeTargets(
                $connection, (int) $reference['provider_id'], (int) $reference['model_id'], (string) $reference['capability_code'],
            );
            $revision = $connection->table('sand_ai_price_revision')->where('id', $id)->lock(true)->find();
            if (!is_array($revision)
                || (int) $revision['provider_id'] !== (int) $reference['provider_id']
                || (int) $revision['model_id'] !== (int) $reference['model_id']
                || (string) $revision['capability_code'] !== (string) $reference['capability_code']) {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'The price revision target changed while it was being locked');
            }
            if ((string) $revision['state'] === 'published') {
                $receipt = $this->readFromConnection($connection, $id);
                if ($requestKey !== null) $operations->record($connection, $adminId, 'publish', $requestKey, $hash, $id, $receipt);
                $revalidate?->__invoke();
                return $receipt + ['idempotent_replay' => true];
            }
            $lockedRates = $connection->table('sand_ai_price_rate')
                ->where('price_revision_id', $id)->order('meter_code')->lock(true)->select()->toArray();
            $meters = [];
            foreach ($lockedRates as $rate) {
                $meters[] = (string) $rate['meter_code'];
            }
            (new PricingRevisionPublicationPolicy())->assertPublishable((string) $revision['state'], $meters, (string) $revision['capability_code'], (string) $provider['adapter']);
            $connection->table('sand_ai_price_revision')->where('id', $id)
                ->update([
                    'state' => 'published',
                    'published_time' => gmdate('Y-m-d H:i:s'),
                    'updated_by' => $adminId,
                ]);
            $this->audit($connection, $adminId, 'pricing.revision.publish', $id, 'Published SandAI capability price revision');

            $receipt = $this->readFromConnection($connection, $id);
            if ($requestKey !== null) $operations->record($connection, $adminId, 'publish', $requestKey, $hash, $id, $receipt);
            $revalidate?->__invoke();
            return $receipt + ['idempotent_replay' => false];
        });
    }

    /** @param array<string, mixed> $data */
    private function createDraft(ConnectionInterface $connection, array $data, int $adminId): int
    {
        $maximum = $connection->table('sand_ai_price_revision')
            ->where('provider_id', $data['provider_id'])->where('model_id', $data['model_id'])
            ->where('capability_code', $data['capability_code'])->max('revision');
        $revision = max(1, (int) $maximum + 1);
        $persisted = $this->persistedDraft($data);
        $rows = $connection->query(
            'INSERT INTO sand_ai_price_revision (revision, provider_id, model_id, capability_code, name, currency, effective_from, effective_until, state, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id',
            [$revision, $persisted['provider_id'], $persisted['model_id'], $persisted['capability_code'], $persisted['name'],
                $persisted['currency'], $persisted['effective_from'], $persisted['effective_until'], 'draft', $adminId, $adminId]
        );

        return (int) $rows[0]['id'];
    }

    /** @param array<string, mixed> $data */
    private function updateDraft(ConnectionInterface $connection, int $id, array $data, int $adminId): int
    {
        $revision = $connection->table('sand_ai_price_revision')->where('id', $id)->lock(true)->find();
        if (!is_array($revision)) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Price revision not found');
        }
        if ((string) $revision['state'] !== 'draft') {
            throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Published price revisions are immutable');
        }
        if ((int) $revision['provider_id'] !== $data['provider_id'] || (int) $revision['model_id'] !== $data['model_id']
            || (string) $revision['capability_code'] !== $data['capability_code']) {
            throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'A price draft cannot move to another provider, model, or capability partition');
        }
        $connection->table('sand_ai_price_revision')->where('id', $id)->update(
            $this->persistedDraft($data) + ['updated_by' => $adminId]
        );

        return $id;
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function persistedDraft(array $data): array
    {
        return array_intersect_key($data, array_flip([
            'provider_id', 'model_id', 'capability_code', 'name', 'currency', 'effective_from', 'effective_until',
        ]));
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function summary(array $data): array
    {
        return array_intersect_key($data, array_flip([
            'id', 'revision', 'provider_id', 'model_id', 'capability_code', 'name', 'currency', 'effective_from',
            'effective_until', 'state', 'published_time', 'create_time', 'update_time', 'rates',
        ]));
    }

    private function assertAdmin(int $adminId): void
    {
        if ($adminId <= 0) {
            throw new ApiProblem('SAND_AI_AUTHENTICATION_FAILED', 'An authenticated administrator is required');
        }
    }

    private function lockRuntimeTargets(ConnectionInterface $connection, int $providerId, int $modelId, string $capabilityCode): array
    {
        $provider = $connection->table('sand_ai_provider')->where('id', $providerId)->whereNull('delete_time')->lock(true)->find();
        if (!is_array($provider)) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Provider not found');
        }
        if ((int) ($provider['status'] ?? 0) !== 1) {
            throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Provider is not active');
        }
        $model = $connection->table('sand_ai_model')->json(['capabilities'], true)
            ->where('id', $modelId)->whereNull('delete_time')->lock(true)->find();
        if (!is_array($model)) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Model not found');
        }
        if ((int) ($model['status'] ?? 0) !== 1) {
            throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Model is not active');
        }
        $capability = CapabilityDriverRegistry::capability($capabilityCode);
        $modelCapabilities = is_array($model['capabilities'] ?? null) ? $model['capabilities'] : [];
        if ((!in_array((string) ($capability['route_kind'] ?? ''), ['model_deployment', 'audio_driver'], true) && $capabilityCode !== 'image_ocr')
            || !is_array($capability['pricing_meters'] ?? null) || $capability['pricing_meters'] === []
            || !in_array((string) ($model['type'] ?? ''), $capability['model_types'] ?? [], true)
            || !in_array($capabilityCode, $modelCapabilities, true)
            || (isset($capability['provider_adapters']) && !in_array((string) $provider['adapter'], $capability['provider_adapters'], true))) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'The active model does not support this capability');
        }
        return $provider;
    }

    /** @return array<string,mixed> */
    private function readFromConnection(ConnectionInterface $connection, int $id): array
    {
        $revision = $connection->table('sand_ai_price_revision')->where('id', $id)->find();
        if (!is_array($revision)) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Price revision not found');
        }
        $revision['rates'] = $connection->table('sand_ai_price_rate')
            ->where('price_revision_id', $id)->order('meter_code')->select()->toArray();

        return $this->summary($revision);
    }

    private function audit(ConnectionInterface $connection, int $adminId, string $action, int $resourceId, string $summary): void
    {
        $connection->query(
            'INSERT INTO sand_ai_audit_log (actor_type, actor_ref, action, resource_type, resource_id, summary, context) VALUES (?, ?, ?, ?, ?, ?, CAST(? AS jsonb)) RETURNING id',
            ['admin', (string) $adminId, $action, 'price_revision', $resourceId, $summary, '{}']
        );
    }

    private function isUniqueViolation(Throwable $throwable): bool
    {
        for ($current = $throwable; $current !== null; $current = $current->getPrevious()) {
            if ((string) $current->getCode() === '23505' || str_contains($current->getMessage(), 'SQLSTATE[23505]')) {
                return true;
            }
        }

        return false;
    }
}
