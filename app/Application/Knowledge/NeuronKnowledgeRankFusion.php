<?php

declare(strict_types=1);

namespace app\Application\Knowledge;

use app\Api\Support\ApiProblem;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\PostProcessor\PostProcessorInterface;

/** SDK CompositeRetrieval has no rank fusion; preserve SandAI's published RRF. */
final class NeuronKnowledgeRankFusion implements PostProcessorInterface
{
    public function __construct(private readonly int $limit)
    {
        if ($limit < 1 || $limit > 20) {
            throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Knowledge retrieval limit is invalid');
        }
    }

    /** @param list<Document> $documents @return list<Document> */
    public function process(Message $question, array $documents): array
    {
        $merged = [];
        foreach ($documents as $document) {
            $metadata = $document->getMetadata();
            $citation = $metadata['citation'] ?? null;
            $rank = $metadata['rank'] ?? null;
            $id = is_array($citation) ? ($citation['source_block_id'] ?? null) : null;
            if (!is_array($citation) || !is_int($id) || $id < 1
                || $document->getId() !== $id || !is_int($rank) || $rank < 1) {
                throw new ApiProblem('SAND_AI_KNOWLEDGE_SOURCE_DRIFTED', 'Knowledge candidate is invalid');
            }
            if (isset($merged[$id]) && $merged[$id]['source'] !== $citation) {
                throw new ApiProblem('SAND_AI_KNOWLEDGE_SOURCE_DRIFTED', 'Knowledge candidate changed between readers');
            }
            if (!isset($merged[$id])) {
                $merged[$id] = ['source' => $citation, 'document' => $document, 'rank_score' => 0.0];
            }
            $merged[$id]['rank_score'] += 1.0 / (60 + $rank);
        }
        $ranked = array_values($merged);
        usort($ranked, static function (array $left, array $right): int {
            $score = $right['rank_score'] <=> $left['rank_score'];
            return $score !== 0 ? $score : $left['source']['source_block_id'] <=> $right['source']['source_block_id'];
        });
        $result = [];
        foreach (array_slice($ranked, 0, $this->limit) as $item) {
            $result[] = $item['document']->setScore($item['rank_score']);
        }
        return $result;
    }
}
