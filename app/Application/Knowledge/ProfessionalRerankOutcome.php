<?php

declare(strict_types=1);

namespace app\Application\Knowledge;

use app\Api\Support\ApiProblem;

/** Provider evidence retained separately from success of its source ordering. */
final readonly class ProfessionalRerankOutcome
{
    /**
     * @param list<array{source_block_id:int,relevance_score:float}> $rankings
     */
    public function __construct(
        public array $rankings,
        public string $meteringUnit,
        public ?int $meteringQuantity,
        public bool $usageMeasured,
        public bool $httpResponded,
        public ?int $httpStatus,
    ) {
        if (!array_is_list($rankings) || count($rankings) > 10
            || !in_array($meteringUnit, ['rerank_search_units', 'rerank_tokens'], true)
            || $usageMeasured !== ($meteringQuantity !== null)
            || ($meteringQuantity !== null && $meteringQuantity < 0)
            || $httpResponded !== ($httpStatus !== null)
            || ($httpStatus !== null && ($httpStatus < 100 || $httpStatus > 599))
            || ($usageMeasured && !$httpResponded)) {
            throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Professional rerank evidence is invalid', true);
        }
        $seen = [];
        foreach ($rankings as $ranking) {
            $id = is_array($ranking) ? ($ranking['source_block_id'] ?? null) : null;
            $score = is_array($ranking) ? ($ranking['relevance_score'] ?? null) : null;
            if (!is_array($ranking) || count($ranking) !== 2 || !is_int($id) || $id < 1 || isset($seen[$id])
                || !is_float($score) || !is_finite($score)) {
                throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Professional rerank source ordering is invalid', true);
            }
            $seen[$id] = true;
        }
        if ($rankings !== [] && (!$httpResponded || $httpStatus < 200 || $httpStatus >= 300)) {
            throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Professional rerank ordering has no successful response', true);
        }
    }

    /** @param list<array{source_block_id:int,relevance_score:float}> $rankings */
    public function withRankings(array $rankings): self
    {
        return new self($rankings, $this->meteringUnit, $this->meteringQuantity,
            $this->usageMeasured, $this->httpResponded, $this->httpStatus);
    }

    /** @return array<string,mixed> Internal receipt only; contains no vendor payload. */
    public function toArray(): array
    {
        return [
            'rankings' => $this->rankings,
            'metering_unit' => $this->meteringUnit,
            'metering_quantity' => $this->meteringQuantity,
            'usage_measured' => $this->usageMeasured,
            'http_responded' => $this->httpResponded,
            'http_status' => $this->httpStatus,
        ];
    }
}
