<?php

declare(strict_types=1);

namespace app\Application\Workflow;

final class MaterialReviewRequirement
{
    /** @param array<string,mixed> $payload */
    public function required(bool $configured, array $payload): bool
    {
        if ($configured || ($payload['items_to_verify'] ?? []) !== []
            || ($payload['conflicts'] ?? []) !== []) {
            return true;
        }
        foreach ($payload['documents'] ?? [] as $document) {
            if (($document['classification']['evidence_state'] ?? null) === 'missing_source'
                || ($document['title']['evidence_state'] ?? null) === 'missing_source') {
                return true;
            }
        }
        return false;
    }
}
