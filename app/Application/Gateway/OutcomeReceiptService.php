<?php

declare(strict_types=1);

namespace app\Application\Gateway;

use app\Api\Support\ApiProblem;
use app\Application\Knowledge\KnowledgeProviderOutcome;
use app\Application\Knowledge\KnowledgeEmbeddingAccountingOutcome;
use app\Application\Knowledge\KnowledgeEmbeddingOutcome;

final class OutcomeReceiptService
{
    public function __construct(
        private readonly OutcomeReceiptStore $store,
        private readonly OutcomeReceiptCipher $cipher,
        private readonly int $ttlSeconds,
    ) {
        if ($ttlSeconds < 60 || $ttlSeconds > 604800) {
            throw new ApiProblem('SAND_AI_RECEIPT_UNAVAILABLE', 'Outcome receipt retention is not configured');
        }
    }

    public function preflight(string $capabilityCode, ?OutcomeReceiptRequest $request): bool
    {
        if ($request === null) {
            return false;
        }
        if (!in_array($capabilityCode, ['structured_extraction', 'skill', 'knowledge_answer', 'skill_source_suggestion', 'meeting_summary', 'embedding', 'agent_tool', 'rerank'], true)) {
            throw new ApiProblem('SAND_AI_RECEIPT_FORBIDDEN', 'Public chat does not retain replayable outcomes');
        }
        if ($request->maxPlaintextBytes > $this->cipher->maxPlaintextBytes()) {
            throw new ApiProblem('SAND_AI_RECEIPT_INPUT_TOO_LARGE', 'Outcome receipt output bound exceeds its configured safety limit');
        }
        return true;
    }

    /** @param array<string,mixed> $context */
    public function write(string $content, array $context, int $requestPlaintextLimit): void
    {
        try {
            $plaintext = json_encode(['content' => $content], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if ($requestPlaintextLimit < 1 || strlen($plaintext) > $requestPlaintextLimit) {
                throw new ApiProblem('SAND_AI_RECEIPT_INPUT_TOO_LARGE', 'Outcome receipt content exceeds its requested safety limit');
            }
            $envelope = $this->cipher->encrypt($plaintext, $context);
            $record = [
                'invocation_id' => $context['invocation_id'], 'environment_id' => $context['environment_id'],
                'capability_code' => $context['capability_code'], 'model_id' => $context['model_id'], 'deployment_id' => $context['deployment_id'],
                'request_fingerprint' => $context['request_fingerprint'], 'receipt_version' => $envelope->receiptVersion,
                'cipher_version' => $envelope->cipherVersion, 'key_id' => $envelope->keyId,
                'plaintext_bytes' => $envelope->plaintextBytes, 'ciphertext_bytes' => $envelope->ciphertextBytes(), 'content_hmac' => $envelope->contentHmac,
                'nonce' => $envelope->nonce, 'tag' => $envelope->tag, 'ciphertext' => $envelope->ciphertext,
                'expire_time' => gmdate('Y-m-d H:i:s', time() + $this->ttlSeconds),
            ];
            $result = $this->store->createOrGet($record);
            if (!$result['created'] && !$this->sameEncryptedRecord($record, $result['record'])) {
                throw new ApiProblem('SAND_AI_RECEIPT_CONFLICT', 'Outcome receipt cannot be replaced');
            }
        } catch (ApiProblem $problem) {
            throw $problem;
        } catch (\Throwable) {
            throw new ApiProblem('SAND_AI_RECEIPT_PERSISTENCE_FAILED', 'Outcome receipt could not be recorded', true);
        }
    }

    /** @param array<string,mixed> $context */
    public function probe(int $invocationId, array $context): string
    {
        $record = $this->store->findByInvocationId($invocationId);
        if ($record === null) {
            return 'missing';
        }
        if ((string) ($record['state'] ?? '') !== 'available'
            || !is_string($record['expire_time'] ?? null) || (string) $record['expire_time'] <= gmdate('Y-m-d H:i:s')
            || !$this->sameContext($record, $context)) {
            return 'invalid';
        }
        return 'valid';
    }

    /** @param array<string,mixed> $context */
    public function replay(array $context): string
    {
        $record = $this->store->findByInvocationId((int) $context['invocation_id']);
        if ($this->probe((int) $context['invocation_id'], $context) !== 'valid' || $record === null) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Outcome receipt is unavailable for replay');
        }
        return $this->decryptContent($record, $context);
    }

    /**
     * Internal accounting projection only. Expiry forbids vector delivery but
     * must not strand already incurred usage. The caller verifies the frozen
     * invocation/dispatch identity before settlement and cannot index this DTO.
     * @param array<string,mixed> $context
     */
    public function readKnowledgeEmbeddingAccounting(array $context): ?KnowledgeEmbeddingAccountingOutcome
    {
        if (($context['capability_code'] ?? null) !== 'embedding') {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Embedding accounting receipt context is invalid');
        }
        $record = $this->store->findByInvocationId((int) ($context['invocation_id'] ?? 0));
        if ($record === null || ($record['state'] ?? null) === 'destroyed') {
            return null;
        }
        if (!in_array($record['state'] ?? null, ['available', 'expired'], true)
            || !$this->sameContext($record, $context)) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Embedding accounting receipt is invalid');
        }
        return KnowledgeEmbeddingAccountingOutcome::fromOutcome(
            KnowledgeEmbeddingOutcome::decode($this->decryptContent($record, $context)),
        );
    }

    /** Internal purge projection only; caller holds the authorized owner lock. */
    public function readSkillSourceSuggestionOutcomeForPurge(int $invocationId, int $suggestionId): ?KnowledgeProviderOutcome
    {
        if ($invocationId < 1 || $suggestionId < 1) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Skill source suggestion receipt owner is invalid');
        }
        $record = $this->store->findSkillSourceSuggestionForPurge($invocationId, $suggestionId);
        if ($record === null || ($record['state'] ?? null) === 'destroyed') {
            return null;
        }
        if (!in_array($record['state'] ?? null, ['available', 'expired'], true)) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Skill source suggestion receipt state is invalid');
        }
        $context = [
            'environment_id' => (int)$record['environment_id'],
            'invocation_id' => (int)$record['invocation_id'],
            'request_fingerprint' => (string)$record['request_fingerprint'],
            'capability_code' => 'skill_source_suggestion',
            'model_id' => (int)$record['model_id'],
            'deployment_id' => (int)$record['deployment_id'],
            'version' => OutcomeReceiptCipher::RECEIPT_VERSION,
        ];
        // Expiry limits delivery, not the in-memory projection of already received
        // accounting facts immediately before explicit ciphertext destruction.
        return KnowledgeProviderOutcome::decode($this->decryptContent($record, $context));
    }

    public function destroySkillSourceSuggestion(int $invocationId, int $suggestionId, string $purgedAt): bool
    {
        if ($invocationId < 1 || $suggestionId < 1 || strtotime($purgedAt) === false) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Skill source suggestion receipt purge is invalid');
        }
        return $this->store->destroySkillSourceSuggestion($invocationId, $suggestionId, $purgedAt);
    }

    /** @param array<string,mixed> $record @param array<string,mixed> $context */
    private function decryptContent(array $record, array $context): string
    {
        try {
            $plaintext = $this->cipher->decrypt(new OutcomeReceiptEnvelope(
                (string) ($record['receipt_version'] ?? ''), (string) ($record['cipher_version'] ?? ''), (string) ($record['key_id'] ?? ''),
                self::bytes($record['nonce'] ?? null), self::bytes($record['tag'] ?? null), self::bytes($record['ciphertext'] ?? null),
                self::positiveInt($record['plaintext_bytes'] ?? null), self::bytes($record['content_hmac'] ?? null),
            ), $context);
            if (strlen(self::bytes($record['ciphertext'] ?? null)) !== self::positiveInt($record['ciphertext_bytes'] ?? null)) {
                throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Outcome receipt is unavailable for replay');
            }
            $payload = json_decode($plaintext, true, 4, JSON_THROW_ON_ERROR);
            if (!is_array($payload) || array_keys($payload) !== ['content'] || !is_string($payload['content'])) {
                throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Outcome receipt is unavailable for replay');
            }
            return $payload['content'];
        } catch (ApiProblem $problem) {
            throw $problem;
        } catch (\Throwable) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Outcome receipt is unavailable for replay');
        }
    }

    public function acknowledgeArtifact(int $invocationId, int $artifactId, string $acknowledgedAt, string $retainUntil): void
    {
        if ($invocationId < 1 || $artifactId < 1 || strtotime($acknowledgedAt) === false || strtotime($retainUntil) === false || $retainUntil <= $acknowledgedAt) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Outcome receipt acknowledgement is invalid');
        }
        if (!$this->store->acknowledgeArtifact($invocationId, $artifactId, $acknowledgedAt, $retainUntil)) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Outcome receipt could not be acknowledged for this artifact');
        }
    }

    public function acknowledgeFileResult(int $invocationId, int $resultId, string $acknowledgedAt, string $retainUntil): void
    {
        if ($invocationId < 1 || $resultId < 1 || strtotime($acknowledgedAt) === false
            || strtotime($retainUntil) === false || $retainUntil <= $acknowledgedAt) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Outcome receipt acknowledgement is invalid');
        }
        if (!$this->store->acknowledgeFileResult($invocationId, $resultId, $acknowledgedAt, $retainUntil)) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Outcome receipt could not be acknowledged for this file result');
        }
    }

    public function acknowledgeCrossFileReview(int $invocationId, int $resultId, string $acknowledgedAt, string $retainUntil): void
    {
        if ($invocationId < 1 || $resultId < 1 || strtotime($acknowledgedAt) === false
            || strtotime($retainUntil) === false || $retainUntil <= $acknowledgedAt) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Outcome receipt acknowledgement is invalid');
        }
        if (!$this->store->acknowledgeCrossFileReview($invocationId, $resultId, $acknowledgedAt, $retainUntil)) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Outcome receipt could not be acknowledged for this cross-file review');
        }
    }

    public function acknowledgeSkillSourceSuggestion(int $invocationId, int $suggestionId, string $acknowledgedAt, string $retainUntil): void
    {
        if ($invocationId < 1 || $suggestionId < 1 || strtotime($acknowledgedAt) === false
            || strtotime($retainUntil) === false || $retainUntil <= $acknowledgedAt
            || !$this->store->acknowledgeSkillSourceSuggestion($invocationId, $suggestionId, $acknowledgedAt, $retainUntil)) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Outcome receipt could not be acknowledged for this Skill source suggestion');
        }
    }

    public function renewPendingSkillSourceSuggestion(int $invocationId, int $suggestionId): void
    {
        if ($invocationId < 1 || $suggestionId < 1) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Skill source suggestion receipt owner is invalid');
        }
        // A missing receipt remains the normal dispatch ambiguity path. The store
        // only extends an available, unconsumed receipt of the exact pending owner.
        $this->store->renewPendingSkillSourceSuggestion($invocationId, $suggestionId, gmdate('Y-m-d H:i:s', time() + $this->ttlSeconds));
    }

    /** @param array<string,mixed> $record @param array<string,mixed> $context */
    private function sameContext(array $record, array $context): bool
    {
        foreach (['invocation_id', 'environment_id', 'capability_code', 'model_id', 'deployment_id', 'request_fingerprint'] as $field) {
            if (!array_key_exists($field, $record) || !array_key_exists($field, $context) || (string) $record[$field] !== (string) $context[$field]) {
                return false;
            }
        }
        return (string) ($record['receipt_version'] ?? '') === OutcomeReceiptCipher::RECEIPT_VERSION;
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    private function sameEncryptedRecord(array $left, array $right): bool
    {
        foreach (['invocation_id', 'environment_id', 'capability_code', 'model_id', 'deployment_id', 'request_fingerprint', 'receipt_version', 'cipher_version', 'key_id', 'plaintext_bytes', 'ciphertext_bytes'] as $field) {
            if ((string) ($left[$field] ?? '') !== (string) ($right[$field] ?? '')) {
                return false;
            }
        }
        foreach (['content_hmac'] as $field) {
            if (!is_string($left[$field] ?? null) || !is_string($right[$field] ?? null) || !hash_equals($left[$field], $right[$field])) {
                return false;
            }
        }
        return true;
    }

    private static function bytes(mixed $value): string
    {
        if (!is_string($value)) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Outcome receipt is unavailable for replay');
        }
        return $value;
    }

    private static function positiveInt(mixed $value): int
    {
        if ((!is_int($value) && (!is_string($value) || !ctype_digit($value))) || (int) $value < 1) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Outcome receipt is unavailable for replay');
        }
        return (int) $value;
    }
}
