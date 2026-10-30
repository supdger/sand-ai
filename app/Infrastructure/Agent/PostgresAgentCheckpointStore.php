<?php

declare(strict_types=1);
namespace app\Infrastructure\Agent;

use app\Api\Support\ApiProblem;
use app\Application\Billing\BillingConfigCipher;
use NeuronAI\Workflow\Executor\WorkflowRunStore;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use PDO;

/** Opaque official Workflow records; no identity, credential or authorization is owned here. */
final class PostgresAgentCheckpointStore implements PersistenceInterface
{
    private NeuronCheckpointCipher $cipher;

    public function __construct(private readonly PDO $pdo, private readonly int $environmentId, private readonly int $taskId, ?BillingConfigCipher $cipher = null)
    {
        if ($environmentId < 1 || $taskId < 1 || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql'
            || $pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) $this->invalid();
        $this->cipher = new NeuronCheckpointCipher($cipher);
    }

    public function get(string $partition, string $key): ?string
    {
        $this->assertTaskAvailable();
        $binding = $this->binding($partition, $key);
        $stmt = $this->pdo->prepare('SELECT encrypted_config FROM sand_ai_agent_checkpoint WHERE environment_id=:environment_id AND task_id=:task_id AND partition_sha256=:partition_sha256 AND record_key_sha256=:record_key_sha256');
        $stmt->execute($binding);
        $value = $stmt->fetchColumn();
        return $value === false ? null : $this->cipher->open((string) $value, $binding);
    }

    public function initializeIfAbsent(string $partition, string $conditionKey, string $initialValue, array $records = []): bool
    {
        $records[$conditionKey] = $initialValue;
        return $this->commit($partition, $conditionKey, null, $records);
    }

    public function writeIfUnchanged(string $partition, string $conditionKey, string $expectedValue, array $records): bool
    {
        return $this->commit($partition, $conditionKey, $expectedValue, $records);
    }

    public function deleteIfUnchanged(string $partition, string $conditionKey, string $expectedValue): bool
    {
        return $this->commit($partition, $conditionKey, $expectedValue, [], true);
    }

    /** Internal private projection for the currently authorized original task; never return runtime in a public DTO. */
    public function inspect(string $workflowId): ?array
    {
        $serializer = new NeuronCheckpointSerializer();
        $store = new WorkflowRunStore($this, $serializer, $workflowId);
        $control = $store->loadControl();
        if ($control === null) return null;
        $raw = $this->get($workflowId, NeuronAgentExecutionJournal::RECORD_KEY);
        $runtime = $raw === null ? [] : $serializer->unserialize($raw);
        if (!is_array($runtime)) $this->invalid();
        return ['run_id' => $control->runId, 'state' => $control->status->value,
            'execution_attempt' => $control->executionAttempt, 'lease_expires_at' => $control->leaseExpiresAt,
            'checkpoint_available' => $store->loadIgnition() !== null && $raw !== null && is_string($runtime['definition_sha256']??null), 'runtime' => $runtime];
    }

    /** Safe availability metadata: do not decrypt the ignition, working history or any outcome. */
    public function inspectMetadata(string $workflowId): ?array
    {
        $store = new WorkflowRunStore($this, new NeuronCheckpointSerializer(), $workflowId);
        $control = $store->loadControl();
        if ($control === null) return null;
        return ['run_id' => $control->runId, 'state' => $control->status->value,
            'execution_attempt' => $control->executionAttempt, 'lease_expires_at' => $control->leaseExpiresAt,
            'checkpoint_available' => $this->exists($workflowId, '__ignition') && $this->exists($workflowId, NeuronAgentExecutionJournal::RECORD_KEY)];
    }

    private function exists(string $partition, string $key): bool
    {
        $this->assertTaskAvailable();
        $stmt=$this->pdo->prepare('SELECT 1 FROM sand_ai_agent_checkpoint WHERE environment_id=:environment_id AND task_id=:task_id AND partition_sha256=:partition_sha256 AND record_key_sha256=:record_key_sha256');
        $stmt->execute($this->binding($partition,$key));
        return $stmt->fetchColumn()!==false;
    }

    private function assertTaskAvailable(bool $lock = false): void
    {
        $stmt=$this->pdo->prepare('SELECT id FROM sand_ai_task_run WHERE id=:id AND environment_id=:environment_id AND status=1 AND delete_time IS NULL AND content_purged_time IS NULL'.($lock?' FOR SHARE':''));
        $stmt->execute(['id'=>$this->taskId,'environment_id'=>$this->environmentId]);
        if ($stmt->fetchColumn()===false) throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID','The original agent task content is unavailable');
    }

    private function commit(string $partition, string $conditionKey, ?string $expected, array $records, bool $delete = false): bool
    {
        $condition = $this->binding($partition, $conditionKey);
        $writes = [];
        foreach ($records as $key => $value) {
            if (!is_string($value)) $this->invalid();
            $binding = $this->binding($partition, (string) $key);
            $writes[] = [$binding, $this->cipher->seal($value, $binding)];
        }
        $nested = $this->pdo->inTransaction();
        $savepoint = 'sand_ai_agent_checkpoint_' . bin2hex(random_bytes(6));
        if ($nested) $this->pdo->exec('SAVEPOINT ' . $savepoint); else $this->pdo->beginTransaction();
        try {
            $this->assertTaskAvailable(true);
            if ($expected === null) {
                $value = $records[$conditionKey] ?? null;
                if (!is_string($value)) $this->invalid();
                $stmt = $this->pdo->prepare('INSERT INTO sand_ai_agent_checkpoint (environment_id,task_id,partition_sha256,record_key_sha256,encrypted_config) VALUES (:environment_id,:task_id,:partition_sha256,:record_key_sha256,:encrypted_config) ON CONFLICT (environment_id,task_id,partition_sha256,record_key_sha256) DO NOTHING');
                $stmt->execute([...$condition, 'encrypted_config' => $this->cipher->seal($value, $condition)]);
                $matches = $stmt->rowCount() === 1;
            } else {
                $stmt = $this->pdo->prepare('SELECT encrypted_config FROM sand_ai_agent_checkpoint WHERE environment_id=:environment_id AND task_id=:task_id AND partition_sha256=:partition_sha256 AND record_key_sha256=:record_key_sha256 FOR UPDATE');
                $stmt->execute($condition);
                $current = $stmt->fetchColumn();
                $matches = $current !== false && hash_equals($expected, $this->cipher->open((string) $current, $condition));
            }
            if ($matches) {
                if ($delete) {
                    unset($condition['record_key_sha256']);
                    $stmt = $this->pdo->prepare('DELETE FROM sand_ai_agent_checkpoint WHERE environment_id=:environment_id AND task_id=:task_id AND partition_sha256=:partition_sha256');
                    $stmt->execute($condition);
                } else {
                    foreach ($writes as [$binding, $encrypted]) {
                        if ($expected === null && $binding === $condition) continue;
                        $stmt = $this->pdo->prepare('INSERT INTO sand_ai_agent_checkpoint (environment_id,task_id,partition_sha256,record_key_sha256,encrypted_config) VALUES (:environment_id,:task_id,:partition_sha256,:record_key_sha256,:encrypted_config) ON CONFLICT (environment_id,task_id,partition_sha256,record_key_sha256) DO UPDATE SET encrypted_config=EXCLUDED.encrypted_config,update_time=CURRENT_TIMESTAMP');
                        $stmt->execute([...$binding, 'encrypted_config' => $encrypted]);
                    }
                }
            }
            if ($nested) $this->pdo->exec('RELEASE SAVEPOINT ' . $savepoint); else $this->pdo->commit();
            return $matches;
        } catch (\Throwable $failure) {
            if ($nested) { $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint); $this->pdo->exec('RELEASE SAVEPOINT ' . $savepoint); }
            elseif ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $failure;
        }
    }

    private function binding(string $partition, string $key): array
    {
        if ($partition === '' || $key === '' || strlen($partition) > 255 || strlen($key) > 1024) $this->invalid();
        return ['environment_id' => $this->environmentId, 'task_id' => $this->taskId,
            'partition_sha256' => hash('sha256', $partition), 'record_key_sha256' => hash('sha256', $key)];
    }

    private function invalid(): never { throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Private agent checkpoint storage is invalid'); }
}
