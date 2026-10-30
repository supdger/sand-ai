<?php

declare(strict_types=1);

namespace app\Application\Agent;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\ChatCompletionOptionsPolicy;
use app\Infrastructure\Agent\AgentToolRegistry;

final class AgentPolicy
{
    /** @param array<string, mixed> $input @return array<string,mixed> */
    public static function normalizeInput(array $input): array
    {
        if (array_diff(array_keys($input), ['model', 'instructions', 'query', 'tools', 'review_required', 'max_output_tokens', 'knowledge_base_code', 'context_turn_limit', 'conversation_id', 'readonly_tools']) !== []) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Agent input contains unsupported fields');
        }
        $model = trim((string) ($input['model'] ?? ''));
        $instructions = trim((string) ($input['instructions'] ?? ''));
        $query = trim((string) ($input['query'] ?? ''));
        if ($model === '' || $instructions === '' || $query === '') {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'model, instructions and query are required');
        }
        if (mb_strlen($instructions, 'UTF-8') > 16000 || mb_strlen($query, 'UTF-8') > 500) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'instructions or query exceeds the permitted length');
        }
        $tools = $input['tools'] ?? ['retrieval.search'];
        if (!is_array($tools) || $tools === [] || count($tools) > 10) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'tools must contain one to ten registered names');
        }
        $normalizedTools = [];
        foreach ($tools as $tool) {
            $tool = trim((string) $tool);
            AgentToolRegistry::tool($tool);
            $normalizedTools[] = $tool;
        }
        $reviewRequired = $input['review_required'] ?? false;
        $knowledgeBaseCode = $input['knowledge_base_code'] ?? null;
        $contextTurnLimit = $input['context_turn_limit'] ?? 2;
        if (!is_bool($reviewRequired)) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'review_required must be boolean');
        }
        if ($knowledgeBaseCode !== null && (!is_string($knowledgeBaseCode)
            || preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $knowledgeBaseCode) !== 1)) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'knowledge_base_code is invalid');
        }
        if (!is_int($contextTurnLimit) || $contextTurnLimit < 0 || $contextTurnLimit > 4) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'context_turn_limit is invalid');
        }

        $maxOutputTokens = ChatCompletionOptionsPolicy::normalize($input['max_output_tokens'] ?? null)->maxOutputTokens;
        $readonlyTools = AgentReadonlyToolSelection::normalize($input['readonly_tools'] ?? []);
        if ($readonlyTools !== []) {
            if ($maxOutputTokens !== null && $maxOutputTokens > 512) throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Readonly agent rounds require an output ceiling of 512');
            $maxOutputTokens ??= 512;
        }

        $conversation = array_key_exists('conversation_id', $input)
            ? ['conversation_id' => AgentConversationGateway::conversationId($input['conversation_id'])] : [];
        return [
            ...$conversation,
            ...($readonlyTools === [] ? [] : ['readonly_tools' => $readonlyTools]),
            'model' => $model,
            'instructions' => $instructions,
            'query' => $query,
            'tools' => array_values(array_unique($normalizedTools)),
            'review_required' => $reviewRequired,
            'max_output_tokens' => $maxOutputTokens,
            'knowledge_base_code' => $knowledgeBaseCode,
            'context_turn_limit' => $contextTurnLimit,
        ];
    }
}
