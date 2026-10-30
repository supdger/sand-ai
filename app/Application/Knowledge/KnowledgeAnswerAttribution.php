<?php

declare(strict_types=1);

namespace app\Application\Knowledge;

use app\Api\Support\ApiProblem;

/** Frozen, verified knowledge source facts carried into the billed chat invocation. */
final readonly class KnowledgeAnswerAttribution
{
    public function __construct(
        public int $publicationId,
        public int $versionId,
        public string $sourceScopeSha256,
        public ?string $dataClass,
    ) {
        if ($publicationId < 1 || $versionId < 1
            || preg_match('/^[a-f0-9]{64}$/D', $sourceScopeSha256) !== 1
            || ($dataClass !== null && preg_match('/^[a-z0-9][a-z0-9._-]{1,31}$/D', $dataClass) !== 1)) {
            throw new ApiProblem('SAND_AI_KNOWLEDGE_SOURCE_INVALID', 'Knowledge answer source facts are invalid');
        }
    }

    /** @param array<string,mixed> $publication */
    public static function fromPublication(array $publication): self
    {
        return new self(
            (int) ($publication['id'] ?? 0),
            (int) ($publication['active_version_id'] ?? 0),
            (string) ($publication['source_scope_sha256'] ?? ''),
            isset($publication['data_class']) ? (string) $publication['data_class'] : null,
        );
    }
}
