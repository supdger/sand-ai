<?php

declare(strict_types=1);
namespace app\Infrastructure\Agent;

use app\Api\Support\ApiProblem;
use app\Application\Billing\BillingConfigCipher;
use app\Infrastructure\Billing\ProviderConfigCipher;

/** Thin codec over the existing internal configuration cipher; authenticated plaintext binds each record to its task. */
final class NeuronCheckpointCipher
{
    private const MAX_BYTES = 262144;
    private readonly BillingConfigCipher $cipher;

    public function __construct(?BillingConfigCipher $cipher = null) { $this->cipher = $cipher ?? new ProviderConfigCipher(); }

    public function seal(string $plaintext, array $binding): string
    {
        $this->assertBinding($binding);
        if ($plaintext === '' || strlen($plaintext) > self::MAX_BYTES) $this->invalid();
        return json_encode($this->cipher->encrypt(['domain' => 'sand-ai/agent-checkpoint/v1',
            'binding' => $binding, 'record' => base64_encode($plaintext)]), JSON_THROW_ON_ERROR);
    }

    public function open(string $encrypted, array $binding): string
    {
        $this->assertBinding($binding);
        if (strlen($encrypted) > self::MAX_BYTES * 2 + 4096) $this->invalid();
        $envelope = json_decode($encrypted, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($envelope)) $this->invalid();
        $value = $this->cipher->decrypt($envelope);
        if (array_keys($value) !== ['domain', 'binding', 'record'] || $value['domain'] !== 'sand-ai/agent-checkpoint/v1'
            || $value['binding'] !== $binding || !is_string($value['record'])) $this->invalid();
        $plaintext = base64_decode($value['record'], true);
        if (!is_string($plaintext) || $plaintext === '' || strlen($plaintext) > self::MAX_BYTES) $this->invalid();
        return $plaintext;
    }

    private function assertBinding(array $binding): void
    {
        if (array_keys($binding) !== ['environment_id', 'task_id', 'partition_sha256', 'record_key_sha256']
            || !is_int($binding['environment_id']) || !is_int($binding['task_id']) || min($binding['environment_id'], $binding['task_id']) < 1) $this->invalid();
        foreach (['partition_sha256', 'record_key_sha256'] as $field) if (!is_string($binding[$field]) || preg_match('/\A[0-9a-f]{64}\z/', $binding[$field]) !== 1) $this->invalid();
    }

    private function invalid(): never { throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Private agent checkpoint binding is invalid'); }
}
