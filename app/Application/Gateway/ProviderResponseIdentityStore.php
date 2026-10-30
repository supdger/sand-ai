<?php

declare(strict_types=1);

namespace app\Application\Gateway;

use app\Api\Support\ApiProblem;
use app\Infrastructure\Persistence\Model\Invocation;
use think\facade\Db;

/** Independently committed, bounded observations from the original dispatch. */
final class ProviderResponseIdentityStore
{
    private const REQUIRED_MIGRATIONS = [
        '202610100153_provider_response_identity' => '4d7e97b606bbf60fb6568df0d7fe7d0077e4f0f6e581fe8547a3e384cc34e308',
        '202610300161_provider_identity_admin_scope' => '3d1561d6dab24098aa2978e5ee2522ac749edcdde0fdfba522983471b5e1b9ec',
    ];

    public function assertAvailable(int $invocationId, int $dispatchId): void
    {
        $db = Db::connect('pgsql');
        $this->assertIndependent($db);
        try {
            $rows = $db->query('SELECT d.id FROM sand_ai_invocation_dispatch d
                JOIN sand_ai_invocation i ON i.id=d.invocation_id
                WHERE d.id=? AND i.id=? AND d.environment_id=i.environment_id
                  AND d.model_id=i.model_id AND d.deployment_id=i.deployment_id AND d.application_id=i.application_id
                  AND d.state=\'dispatching\' AND d.dispatch_start_time IS NOT NULL', [$dispatchId, $invocationId]);
            if (count($rows) !== 1) throw $this->unavailable();
            // Resolve both the table and its exact migrations before any I/O.
            // An administrator-scope dispatch has no workload client, so the
            // admin-scope migration must be registered too; otherwise storage
            // is rejected here, before the send, instead of after it.
            $db->query('SELECT id FROM sand_ai_provider_response_identity WHERE dispatch_id=? LIMIT 1', [$dispatchId]);
            foreach (self::REQUIRED_MIGRATIONS as $version => $checksum) {
                $versions = $db->query('SELECT checksum FROM sand_ai_schema_migration WHERE version=?', [$version]);
                if (count($versions) !== 1 || !hash_equals($checksum, (string) $versions[0]['checksum'])) throw $this->unavailable();
            }
        } catch (\Throwable $failure) {
            throw $this->unavailable();
        }
    }

    public function record(int $invocationId, int $dispatchId, ProviderResponseIdentity $identity): void
    {
        $db = Db::connect('pgsql');
        $this->assertIndependent($db);
        try {
            $db->transaction(function () use ($db, $invocationId, $dispatchId, $identity): void {
                // The trigger takes this same lock and enforces the count limits.
                $rows = $db->query('SELECT id FROM sand_ai_invocation_dispatch WHERE id=? AND invocation_id=? FOR UPDATE', [$dispatchId, $invocationId]);
                if (count($rows) !== 1) throw $this->unavailable();
                $previous = $db->query('SELECT id FROM sand_ai_provider_response_identity WHERE dispatch_id=? AND source=? AND identifier=?',
                    [$dispatchId, $identity->source, $identity->value]);
                if ($previous !== []) return;
                $inserted = $db->execute('INSERT INTO sand_ai_provider_response_identity
                    (invocation_id,dispatch_id,organization_id,application_id,environment_id,workload_client_id,
                     provider_id,model_id,deployment_id,attempt_no,execution_snapshot_sha256,request_body_digest,
                     identity_kind,source,identifier)
                    SELECT i.id,d.id,i.organization_id,i.application_id,i.environment_id,i.workload_client_id,
                           d.provider_id,d.model_id,d.deployment_id,d.attempt_no,d.execution_snapshot_sha256,d.request_body_digest,?,?,?
                    FROM sand_ai_invocation i JOIN sand_ai_invocation_dispatch d ON d.invocation_id=i.id
                    WHERE i.id=? AND d.id=?', [$identity->kind,$identity->source,$identity->value,$invocationId,$dispatchId]);
                if ($inserted !== 1) throw $this->unavailable();
            });
            $this->assertIndependent($db);
        } catch (\Throwable $failure) {
            // Do not turn a failed observation into a saved receipt or another send.
            throw $this->unavailable();
        }
    }

    /** @return array{state:string,identities:list<array{kind:string,source:string,value:string,received_time:string}>} */
    public function present(Invocation $invocation): array
    {
        try {
            $rows = Db::connect('pgsql')->query('SELECT identity.*,d.invocation_id AS owner_invocation_id,
                d.provider_id AS owner_provider_id,d.model_id AS owner_model_id,d.deployment_id AS owner_deployment_id,
                d.attempt_no AS owner_attempt_no,d.execution_snapshot_sha256 AS owner_snapshot,d.request_body_digest AS owner_body
            FROM sand_ai_provider_response_identity identity
            JOIN sand_ai_invocation_dispatch d ON d.id=identity.dispatch_id
            WHERE identity.invocation_id=? ORDER BY identity.id LIMIT 9', [(int) $invocation->id]);
        } catch (\Throwable) {
            throw $this->unavailable();
        }
        if (count($rows) > 8) throw $this->unavailable();
        $items = []; $values = []; $sources = [];
        foreach ($rows as $row) {
            foreach (['organization_id','application_id','environment_id','model_id','deployment_id'] as $field) {
                if ((int) $row[$field] < 1 || (int) $row[$field] !== (int) $invocation->getAttr($field)) throw $this->unavailable();
            }
            // Administrator-scope invocations have no workload client. Absent
            // on both sides is still the same scope; it is never read as zero.
            $observedClient = $row['workload_client_id'] === null ? null : (int) $row['workload_client_id'];
            $ownerClient = $invocation->getAttr('workload_client_id') === null ? null : (int) $invocation->getAttr('workload_client_id');
            if ($observedClient !== $ownerClient || ($observedClient !== null && $observedClient < 1)) throw $this->unavailable();
            foreach (['invocation_id','provider_id','model_id','deployment_id','attempt_no'] as $field) {
                if ((int) $row[$field] !== (int) $row['owner_' . $field]) throw $this->unavailable();
            }
            if (!hash_equals((string) $row['execution_snapshot_sha256'], (string) $row['owner_snapshot'])
                || !hash_equals((string) $row['request_body_digest'], (string) $row['owner_body'])) throw $this->unavailable();
            $identity = ProviderResponseIdentity::received((string) $row['source'], $row['identifier']);
            if ($identity === null || $identity->kind !== $row['identity_kind']
                || !is_string($row['received_time']) || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/D', $row['received_time']) !== 1) throw $this->unavailable();
            $sources[$identity->source][$identity->value] = true;
            if (count($sources[$identity->source]) > 2) throw $this->unavailable();
            $values[$identity->kind][$identity->value] = true;
            $items[] = ['kind'=>$identity->kind,'source'=>$identity->source,'value'=>$identity->value,'received_time'=>$row['received_time']];
        }
        $conflicting = count($values['request_id'] ?? []) > 1 || count($values['response_id'] ?? []) > 1;
        return ['state'=>$items === [] ? 'not_observed' : ($conflicting ? 'conflicting' : 'observed'), 'identities'=>$items];
    }

    private function assertIndependent(\think\db\PDOConnection $db): void
    {
        $pdo = $db->getPdo();
        if (!$pdo instanceof \PDO || $pdo->inTransaction()) throw $this->unavailable();
    }

    private function unavailable(): ApiProblem
    {
        return new ApiProblem('SAND_AI_PROVIDER_IDENTITY_UNAVAILABLE', 'Provider identifier observation storage is unavailable', true);
    }
}
