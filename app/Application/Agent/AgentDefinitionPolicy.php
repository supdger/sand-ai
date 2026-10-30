<?php

declare(strict_types=1);

namespace app\Application\Agent;

use app\Api\Support\ApiProblem;
use app\Infrastructure\Agent\AgentToolRegistry;

final class AgentDefinitionPolicy
{
    /** @param array<string,mixed> $input @return array<string,mixed> */
    public static function draft(array $input): array
    {
        if (array_diff(array_keys($input), ['code', 'name', 'model', 'instructions', 'tools', 'review_required', 'max_output_tokens', 'knowledge_base_code', 'context_turn_limit', 'readonly_tools']) !== []) {
            self::invalid();
        }
        foreach (['code', 'name', 'model', 'instructions'] as $field) {
            if (!is_string($input[$field] ?? null)) self::invalid();
        }
        $code = trim($input['code']);
        $name = trim($input['name']);
        $model = trim($input['model']);
        $instructions = trim($input['instructions']);
        if (preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $code) !== 1
            || $name === '' || mb_strlen($name, 'UTF-8') > 128
            || $model === '' || mb_strlen($model, 'UTF-8') > 128
            || $instructions === '' || mb_strlen($instructions, 'UTF-8') > 16000) {
            self::invalid();
        }
        $tools = $input['tools'] ?? ['retrieval.search'];
        if (!is_array($tools) || !array_is_list($tools) || $tools === [] || count($tools) > 10) self::invalid();
        $normalizedTools = [];
        foreach ($tools as $tool) {
            if (!is_string($tool) || $tool === '' || trim($tool) !== $tool || in_array($tool, $normalizedTools, true)) self::invalid();
            AgentToolRegistry::tool($tool);
            $normalizedTools[] = $tool;
        }
        if (!in_array('retrieval.search', $normalizedTools, true)) self::invalid();
        $reviewRequired = $input['review_required'] ?? true;
        $maxOutputTokens = $input['max_output_tokens'] ?? null;
        $knowledgeBaseCode = $input['knowledge_base_code'] ?? null;
        $contextTurnLimit = $input['context_turn_limit'] ?? 2;
        if (!is_bool($reviewRequired) || ($maxOutputTokens !== null
            && (!is_int($maxOutputTokens) || $maxOutputTokens < 1 || $maxOutputTokens > 2048))
            || ($knowledgeBaseCode !== null
                && (!is_string($knowledgeBaseCode)
                    || preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $knowledgeBaseCode) !== 1))
            || !is_int($contextTurnLimit) || $contextTurnLimit < 0 || $contextTurnLimit > 4) self::invalid();
        $readonlyTools = AgentReadonlyToolSelection::normalize($input['readonly_tools'] ?? []);
        if ($readonlyTools !== []) {
            if ($maxOutputTokens !== null && $maxOutputTokens > 512) self::invalid();
            $maxOutputTokens ??= 512;
        }
        return [
            ...($readonlyTools === [] ? [] : ['readonly_tools' => $readonlyTools]),
            'code' => $code, 'name' => $name, 'model' => $model,
            'instructions' => $instructions, 'tools' => $normalizedTools,
            'review_required' => $reviewRequired, 'max_output_tokens' => $maxOutputTokens,
            'knowledge_base_code' => $knowledgeBaseCode,
            'context_turn_limit' => $contextTurnLimit,
        ];
    }

    /** @param array<string,mixed> $version @return array<string,mixed> */
    public static function publishedConfig(string $code, array $version): array
    {
        $tools = $version['tools'] ?? null;
        if (is_string($tools)) $tools = json_decode($tools, true, 8);
        $readonly = self::readonlyBindings($version);
        try {
            $normalized = self::draft([
                'readonly_tools' => array_column($readonly, 'selection'),
                'code' => $code, 'name' => 'Published agent',
                'model' => $version['model'] ?? null,
                'instructions' => $version['instructions'] ?? null,
                'tools' => $tools,
                'review_required' => self::dbBoolean($version['review_required'] ?? null),
                'max_output_tokens' => self::dbInteger($version['max_output_tokens'] ?? null),
                'knowledge_base_code' => $version['knowledge_base_code'] ?? null,
                'context_turn_limit' => self::dbInteger($version['context_turn_limit'] ?? 2),
            ]);
        } catch (ApiProblem) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Published agent configuration is invalid');
        }
        $config = self::config($normalized);
        if (!is_int($version['version_no'] ?? null) && (!is_string($version['version_no'] ?? null) || !ctype_digit($version['version_no']))) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Published agent version is invalid');
        }
        $versionNo = (int) $version['version_no'];
        $beforeContextLimit = $config;
        unset($beforeContextLimit['context_turn_limit'], $beforeContextLimit['readonly_tools']);
        $beforeKnowledge = $beforeContextLimit;
        unset($beforeKnowledge['knowledge_base_code']);
        if ($versionNo < 1 || !is_string($version['snapshot_sha256'] ?? null)
            || (!hash_equals($version['snapshot_sha256'], self::snapshot($code, $versionNo, $config, $readonly))
                && ($readonly !== [] || $config['context_turn_limit'] !== 2
                    || !hash_equals($version['snapshot_sha256'], self::snapshot($code, $versionNo, $beforeContextLimit)))
                && ($readonly !== [] || $config['context_turn_limit'] !== 2 || $config['knowledge_base_code'] !== null
                    || !hash_equals($version['snapshot_sha256'], self::snapshot($code, $versionNo, $beforeKnowledge))))) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Published agent snapshot changed');
        }
        return $config;
    }

    /** @param array<string,mixed> $input @return array{query:string} */
    public static function runInput(array $input): array
    {
        if (array_diff(array_keys($input), ['query', 'conversation_id']) !== [] || !is_string($input['query'] ?? null)) self::invalid();
        $query = trim($input['query']);
        if ($query === '' || mb_strlen($query, 'UTF-8') > 500) self::invalid();
        return ['query' => $query] + (array_key_exists('conversation_id', $input)
            ? ['conversation_id' => AgentConversationGateway::conversationId($input['conversation_id'])] : []);
    }

    /** @param array<string,mixed> $draft @return array<string,mixed> */
    public static function config(array $draft): array
    {
        return [
            ...(empty($draft['readonly_tools']) ? [] : ['readonly_tools' => $draft['readonly_tools']]),
            'model' => $draft['model'], 'instructions' => $draft['instructions'],
            'tools' => $draft['tools'], 'review_required' => $draft['review_required'],
            'max_output_tokens' => $draft['max_output_tokens'],
            'knowledge_base_code' => $draft['knowledge_base_code'],
            'context_turn_limit' => $draft['context_turn_limit'],
        ];
    }

    /** @param array<string,mixed> $config */
    public static function snapshot(string $code, int $versionNo, array $config, array $readonlyBindings = []): string
    {
        if ($readonlyBindings !== []) $config['readonly_tool_bindings_sha256'] = \app\Application\Tool\ToolFingerprint::for('agent_readonly_catalog', ['bindings' => $readonlyBindings]);
        return hash('sha256', json_encode([
            'code' => $code, 'version_no' => $versionNo, 'config' => $config,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** Private server bindings stored in the version column; never spread into public detail. */
    public static function readonlyBindings(array $version): array
    {
        $bindings = $version['readonly_tools'] ?? [];
        if (is_string($bindings)) {
            try { $bindings = json_decode($bindings, true, 32, JSON_THROW_ON_ERROR); }
            catch (\Throwable) { self::invalid(); }
        }
        if (!is_array($bindings) || !array_is_list($bindings) || count($bindings) > 2) self::invalid();
        $selections = [];
        foreach ($bindings as $binding) {
            if (!is_array($binding) || ($binding['version'] ?? null) !== 1 || !is_array($binding['selection'] ?? null)
                || !is_string($binding['snapshot_sha256'] ?? null)) self::invalid();
            $unsigned = $binding;
            unset($unsigned['snapshot_sha256']);
            if (!hash_equals($binding['snapshot_sha256'], \app\Application\Tool\ToolFingerprint::for('agent_readonly_binding', $unsigned))) self::invalid();
            $selections[] = $binding['selection'];
        }
        AgentReadonlyToolSelection::normalize($selections);
        return $bindings;
    }

    private static function dbBoolean(mixed $value): ?bool
    {
        return match ($value) {
            true, 't', '1', 1 => true,
            false, 'f', '0', 0 => false,
            default => null,
        };
    }

    private static function dbInteger(mixed $value): ?int
    {
        if ($value === null) return null;
        if (is_int($value)) return $value;
        return is_string($value) && ctype_digit($value) ? (int) $value : 0;
    }

    private static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Agent definition input is invalid');
    }
}
