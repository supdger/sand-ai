<?php

declare(strict_types=1);

namespace app\Application\Capability;

use app\Api\Support\ApiProblem;
use PDO;
use think\db\ConnectionInterface;
use think\db\PDOConnection;

/** One collision-free PostgreSQL row lock for all capability writes in an environment. */
final class EnvironmentCapabilityLock
{
    public function acquire(ConnectionInterface $connection, int $environmentId): void
    {
        if ($environmentId < 1) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'A positive environment reference is required');
        }
        $pdo = $connection instanceof PDOConnection ? $connection->getPdo() : false;
        if (!$pdo instanceof PDO || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            throw new ApiProblem('SAND_AI_CONFIGURATION_INVALID', 'Capability profile writes require the active PostgreSQL transaction');
        }
        if (!$pdo->inTransaction()) {
            throw new ApiProblem('SAND_AI_CONFIGURATION_INVALID', 'Capability environment locks require the caller transaction');
        }

        $insert = $pdo->prepare('INSERT INTO sand_ai_capability_environment_lock (environment_id, create_time) VALUES (?, CURRENT_TIMESTAMP) ON CONFLICT (environment_id) DO NOTHING');
        $insert->bindValue(1, $environmentId, PDO::PARAM_INT);
        $insert->execute();

        $lock = $pdo->prepare('SELECT environment_id FROM sand_ai_capability_environment_lock WHERE environment_id = ? FOR UPDATE');
        $lock->bindValue(1, $environmentId, PDO::PARAM_INT);
        $lock->execute();
        if ((int) $lock->fetchColumn() !== $environmentId) {
            throw new ApiProblem('SAND_AI_CONFIGURATION_INVALID', 'Capability environment lock could not be acquired');
        }
    }

    public function key(int $environmentId): string
    {
        return 'sand_ai:capability_profile:environment:' . $environmentId;
    }
}
