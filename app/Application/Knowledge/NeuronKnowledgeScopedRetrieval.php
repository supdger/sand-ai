<?php

declare(strict_types=1);

namespace app\Application\Knowledge;

use app\Api\Support\ApiProblem;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Retrieval\RetrievalInterface;
use NeuronAI\RAG\VectorStore\Filter\FilterExpression;

/** Each request owns its reader; the closure keeps the sealed publication scope. */
final class NeuronKnowledgeScopedRetrieval implements RetrievalInterface
{
    /** @var array{code:string,version:int}|null */
    private ?array $knowledgeBase = null;

    /** @param \Closure(string):array<string,mixed> $reader */
    public function __construct(
        private readonly string $expectedQuery,
        private readonly string $branch,
        private readonly \Closure $reader,
    ) {
    }

    /** @return list<Document> */
    public function retrieve(Message $query, ?FilterExpression $filters = null): array
    {
        $text = $query->getContent();
        // SandAI's publication readers already constrain the current version
        // and live private sources. Never silently discard an SDK filter.
        if ($filters !== null || !is_string($text) || !hash_equals($this->expectedQuery, $text)) {
            throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Knowledge retrieval scope changed');
        }
        $result = ($this->reader)($text);
        $citations = $result['citations'] ?? null;
        if (!is_array($citations) || !array_is_list($citations)) {
            throw new ApiProblem('SAND_AI_KNOWLEDGE_SOURCE_DRIFTED', 'Knowledge candidates are invalid');
        }
        if (is_array($result['knowledge_base'] ?? null)) {
            $this->knowledgeBase = $result['knowledge_base'];
        }
        $documents = [];
        foreach ($citations as $position => $citation) {
            $id = $citation['source_block_id'] ?? null;
            $excerpt = $citation['excerpt'] ?? null;
            if (!is_int($id) || $id < 1 || !is_string($excerpt)) {
                throw new ApiProblem('SAND_AI_KNOWLEDGE_SOURCE_DRIFTED', 'Knowledge candidate is invalid');
            }
            unset($citation['score']);
            $documents[] = (new Document($excerpt))->setId($id)->setSourceType('sand_ai_private')
                ->setSourceName($this->branch)->setMetadata([
                    'citation' => $citation, 'rank' => $position + 1,
                ]);
        }
        return $documents;
    }

    /** @return array{code:string,version:int} */
    public function knowledgeBase(): array
    {
        if ($this->knowledgeBase === null) {
            throw new ApiProblem('SAND_AI_KNOWLEDGE_SOURCE_DRIFTED', 'Knowledge retrieval metadata is missing');
        }
        return $this->knowledgeBase;
    }
}
