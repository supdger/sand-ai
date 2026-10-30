<?php

declare(strict_types=1);

namespace app\Application\Knowledge;

use app\Api\Support\ApiProblem;
use app\Identity\Contract\IdentityContext;

final class KnowledgeConversationPolicy
{
    public static function ownerScope(IdentityContext $identity): string
    {
        return hash('sha256', json_encode([
            'organization_id' => $identity->organizationId,
            'application_id' => $identity->applicationId,
            'environment_id' => $identity->environmentId,
            'workload_client_id' => $identity->workloadClientId,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** @param array<string,mixed> $publication */
    public static function assertBound(array $conversation, array $publication, IdentityContext $identity): void
    {
        self::assertReadable($conversation, $publication, $identity);
        if (($conversation['state'] ?? null) !== 'active') {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Knowledge conversation is unavailable');
        }
    }

    /** @param array<string,mixed> $publication */
    public static function assertReadable(array $conversation, array $publication, IdentityContext $identity): void
    {
        if ((int) ($conversation['environment_id'] ?? 0) !== $identity->environmentId
            || !hash_equals((string) ($conversation['owner_scope'] ?? ''), self::ownerScope($identity))
            || (int) ($conversation['publication_id'] ?? 0) !== (int) $publication['id']
            || (int) ($conversation['knowledge_base_version_id'] ?? 0) !== (int) $publication['active_version_id']
            || (string) ($conversation['source_scope_sha256'] ?? '') !== (string) $publication['source_scope_sha256']
            || ($conversation['data_class'] ?? null) !== ($publication['data_class'] ?? null)
            || !in_array($conversation['state'] ?? null, ['active', 'closed'], true)) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Knowledge conversation is unavailable');
        }
    }

    /** @param list<array<string,mixed>> $turns @return list<array{query:string,answer:string}> */
    public static function history(array $turns): array
    {
        $history = [];
        foreach ($turns as $turn) {
            $answer = $turn['answer'] ?? null;
            if (($turn['state'] ?? null) !== 'answered' || !is_string($turn['query'] ?? null)
                || !is_string($answer) || trim($answer) === '') {
                continue;
            }
            $history[] = [
                'query' => mb_strimwidth(KnowledgeConversationContextMask::text($turn['query']), 0, 500, '', 'UTF-8'),
                'answer' => mb_strimwidth(KnowledgeConversationContextMask::text($answer), 0, 1000, '', 'UTF-8'),
            ];
        }
        return array_slice($history, -4);
    }

    /**
     * Earlier turns provide topic context only. Their answers never become
     * evidence for a new answer; sequence IDs identify the persisted turns.
     * @param list<array<string,mixed>> $turns oldest first, at most 13
     * @return array{history:list<array{query:string,answer:string}>,earlier_questions:list<array{sequence_no:int,query:string,answer_excerpt:string}>,coverage:array{earlier_question_sequences:list<int>,earlier_answer_excerpt_sequences:list<int>,recent_answered_sequences:list<int>,has_omitted_earlier_answered:bool,omitted_answered_count:int}}
     */
    public static function context(array $turns, ?int $totalAnswered = null): array
    {
        $answered = array_values(array_filter($turns, static fn (array $turn): bool =>
            ($turn['state'] ?? null) === 'answered'
            && is_string($turn['query'] ?? null)
            && is_string($turn['answer'] ?? null)
            && trim($turn['answer']) !== ''
            && (int) ($turn['sequence_no'] ?? 0) > 0,
        ));
        $omittedCount = max(0, ($totalAnswered ?? count($answered)) - 12);
        $answered = array_slice($answered, -12);
        $recent = array_slice($answered, -4);
        $earlier = array_slice($answered, 0, -4);
        $questions = array_map(static fn (array $turn): array => [
            'sequence_no' => (int) $turn['sequence_no'],
            'query' => mb_strimwidth(KnowledgeConversationContextMask::text($turn['query']), 0, 160, '', 'UTF-8'),
            'answer_excerpt' => mb_strimwidth(KnowledgeConversationContextMask::text($turn['answer']), 0, 300, '', 'UTF-8'),
        ], $earlier);
        return [
            'history' => self::history($recent),
            'earlier_questions' => $questions,
            'coverage' => [
                'earlier_question_sequences' => array_column($questions, 'sequence_no'),
                'earlier_answer_excerpt_sequences' => array_column($questions, 'sequence_no'),
                'recent_answered_sequences' => array_map(static fn (array $turn): int => (int) $turn['sequence_no'], $recent),
                'has_omitted_earlier_answered' => $omittedCount > 0,
                'omitted_answered_count' => $omittedCount,
            ],
        ];
    }

    /** @param array<string,mixed> $turn */
    public static function executionState(array $turn): string
    {
        $state = $turn['state'] ?? null;
        if (in_array($state, ['answered', 'needs_review', 'no_sources'], true)) return 'completed';
        if ($state === 'failed') return 'failed';
        if ($state === 'cancelled') return 'cancelled';
        if ($state !== 'pending') {
            throw new ApiProblem('SAND_AI_CONVERSATION_UNAVAILABLE', 'Knowledge conversation state is invalid');
        }
        $active = (int) ($turn['claim_active'] ?? 0) === 1;
        $invocation = null;
        $dispatch = null;
        // The answer follows rerank, summaries and query embedding. Select
        // one stage's pair; an older dispatch cannot describe a later call.
        foreach (['', 'rerank_', 'summary_', 'embedding_'] as $stage) {
            $stageInvocation = $turn[$stage . 'invocation_state'] ?? null;
            $stageDispatch = $turn[$stage . 'dispatch_state'] ?? null;
            if ($stageInvocation !== null || $stageDispatch !== null) {
                $invocation = $stageInvocation;
                $dispatch = $stageDispatch;
                break;
            }
        }
        if ($dispatch === 'ambiguous') return 'outcome_unknown';
        if ($dispatch === 'dispatching') return $active ? 'provider_pending' : 'outcome_unknown';
        if ($invocation === 'succeeded' || $invocation === 'failed'
            || $dispatch === 'succeeded' || $dispatch === 'failed') {
            return $active ? 'finalizing' : 'recovery_required';
        }
        if ($invocation === null || $invocation === 'running') {
            if ($dispatch === null || in_array($dispatch, ['prepared', 'not_dispatched'], true)) {
                return $active ? 'preparing' : 'recovery_required';
            }
        }
        return 'outcome_unknown';
    }
}
