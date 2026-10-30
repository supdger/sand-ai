<?php

declare(strict_types=1);

namespace app\Application\Onboarding;

use think\db\ConnectionInterface;

/** Loads one publication chain from fresh database rows under a fixed lock order. */
final class DeploymentPublicationChainLoader
{
    /**
     * @return array{reference:?array<string,mixed>,provider:?array<string,mixed>,model:?array<string,mixed>,deployment:?array<string,mixed>,revision:?array<string,mixed>}
     */
    public function lockCurrent(ConnectionInterface $connection, int $deploymentId): array
    {
        $reference = $connection->table('sand_ai_model_deployment')
            ->field(['id', 'provider_id', 'model_id', 'revision'])
            ->where('id', $deploymentId)->whereNull('delete_time')->find();
        if (!is_array($reference)) {
            return ['reference' => null, 'provider' => null, 'model' => null, 'deployment' => null, 'revision' => null];
        }

        // Do not filter mutable status/configuration before rebuilding the
        // tested snapshot. A changed row must be classified as drift, not as a
        // missing configuration. These are direct uncached reads on the same
        // transaction connection, never ORM relations or caller-held models.
        $provider = $connection->table('sand_ai_provider')->json(['encrypted_config'], true)
            ->where('id', (int) $reference['provider_id'])->whereNull('delete_time')->lock(true)->find();
        $model = $connection->table('sand_ai_model')->json(['capabilities', 'config'], true)
            ->where('id', (int) $reference['model_id'])->whereNull('delete_time')->lock(true)->find();
        $deployment = $connection->table('sand_ai_model_deployment')->json(['config'], true)
            ->where('id', $deploymentId)->whereNull('delete_time')->lock(true)->find();
        // Lock the revision named by the initial reference snapshot. If the
        // locked deployment switched its revision meanwhile, the chain policy
        // rejects that association rather than following the new row.
        $revision = $connection->table('sand_ai_config_revision')->json(['payload'], true)
            ->where('resource_type', 'model_deployment')->where('resource_id', $deploymentId)
            ->where('revision', (int) ($reference['revision'] ?? 0))->whereNull('delete_time')->lock(true)->find();

        return [
            'reference' => $reference,
            'provider' => is_array($provider) ? $provider : null,
            'model' => is_array($model) ? $model : null,
            'deployment' => is_array($deployment) ? $deployment : null,
            'revision' => is_array($revision) ? $revision : null,
        ];
    }
}
