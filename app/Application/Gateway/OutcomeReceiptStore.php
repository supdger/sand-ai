<?php

declare(strict_types=1);

namespace app\Application\Gateway;

interface OutcomeReceiptStore
{
    /** @param array<string,mixed> $record @return array{created:bool,record:array<string,mixed>} */
    public function createOrGet(array $record): array;

    /** @return array<string,mixed>|null */
    public function findByInvocationId(int $invocationId): ?array;

    public function acknowledgeArtifact(int $invocationId, int $artifactId, string $acknowledgedAt, string $retainUntil): bool;

    public function acknowledgeFileResult(int $invocationId, int $resultId, string $acknowledgedAt, string $retainUntil): bool;

    public function acknowledgeCrossFileReview(int $invocationId, int $resultId, string $acknowledgedAt, string $retainUntil): bool;
    public function acknowledgeSkillSourceSuggestion(int $invocationId, int $suggestionId, string $acknowledgedAt, string $retainUntil): bool;

    public function renewPendingSkillSourceSuggestion(int $invocationId, int $suggestionId, string $expiresAt): bool;
    /** Caller holds the source-suggestion owner lock in its transaction. @return array<string,mixed>|null */
    public function findSkillSourceSuggestionForPurge(int $invocationId, int $suggestionId): ?array;

    /** Caller holds the source-suggestion owner lock and its durable purge tombstone. */
    public function destroySkillSourceSuggestion(int $invocationId, int $suggestionId, string $purgedAt): bool;
}


