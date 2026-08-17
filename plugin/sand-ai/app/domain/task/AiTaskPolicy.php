<?php

declare(strict_types=1);

namespace plugin\SandAi\app\domain\task;

use plugin\SandAi\app\api\support\ApiProblem;

final class AiTaskPolicy
{
    /** @param array<string, mixed> $input @return array{model: string, messages: list<array{role: string, content: string}>, source_block_ids: list<int>} */
    public static function normalizeInput(array $input): array
    {
        $model = trim((string) ($input['model'] ?? ''));
        $rawMessages = $input['messages'] ?? null;
        if ($model === '' || !is_array($rawMessages) || $rawMessages === [] || count($rawMessages) > 100) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'model and one to one hundred messages are required');
        }

        $messages = [];
        foreach ($rawMessages as $message) {
            if (!is_array($message) || !in_array($message['role'] ?? null, ['system', 'user', 'assistant'], true) || !is_string($message['content'] ?? null)) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Each message requires a supported role and content');
            }
            $content = trim($message['content']);
            if ($content === '' || mb_strlen($content, 'UTF-8') > 32000) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Each message content must contain at most 32000 characters');
            }
            $messages[] = ['role' => $message['role'], 'content' => $content];
        }

        $rawSourceBlockIds = $input['source_block_ids'] ?? [];
        if (!is_array($rawSourceBlockIds) || count($rawSourceBlockIds) > 50) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'source_block_ids must contain at most fifty ids');
        }
        $sourceBlockIds = [];
        foreach ($rawSourceBlockIds as $id) {
            $parsed = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($parsed === false) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'source_block_ids must contain positive integers');
            }
            $sourceBlockIds[] = (int) $parsed;
        }

        return ['model' => $model, 'messages' => $messages, 'source_block_ids' => array_values(array_unique($sourceBlockIds))];
    }
}
