<?php

declare(strict_types=1);

namespace app\Application\Knowledge;

/** Authenticated provider accounting facts; contains no vector or source text. */
final readonly class KnowledgeEmbeddingAccountingOutcome
{
    private function __construct(
        public int $indexId,
        public int $sourceBlockId,
        public string $contentSha256,
        public int $inputTokens,
        public bool $usageMeasured,
        public int $latencyMs,
        public array $attribution,
        public array $provider,
        public array $model,
        public array $deployment,
    ) {
    }

    public static function fromOutcome(KnowledgeEmbeddingOutcome $outcome): self
    {
        return new self(
            $outcome->indexId, $outcome->sourceBlockId, $outcome->contentSha256,
            $outcome->inputTokens, $outcome->usageMeasured, $outcome->latencyMs,
            $outcome->attribution, $outcome->provider, $outcome->model, $outcome->deployment,
        );
    }
}
