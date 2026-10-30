<?php

declare(strict_types=1);

namespace app\Application\Knowledge;

use app\Api\Support\ApiProblem;

final class KnowledgeBasePolicy
{
    /** @param array<string,mixed> $input @return array{code:string,name:string,description:?string,files:list<array{id:int,sha256:string}>,replace_draft:bool} */
    public static function normalizeDraft(array $input): array
    {
        if (array_diff(array_keys($input), ['code', 'name', 'description', 'files', 'replace_draft']) !== []) self::invalid();
        if (!is_string($input['code'] ?? null) || !is_string($input['name'] ?? null) || (array_key_exists('description', $input) && !is_string($input['description']))) self::invalid();
        if (array_key_exists('replace_draft', $input) && !is_bool($input['replace_draft'])) self::invalid();
        $code = strtolower(trim($input['code']));
        $name = trim($input['name']);
        $description = trim((string) ($input['description'] ?? ''));
        if (!preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $code) || $name === '' || mb_strlen($name, 'UTF-8') > 128 || mb_strlen($description, 'UTF-8') > 500) {
            throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Knowledge base input is invalid');
        }
        $files = $input['files'] ?? null;
        if (!is_array($files) || $files === [] || count($files) > 100) {
            throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'At least one parsed file is required');
        }
        $normalized = [];
        foreach ($files as $file) {
            if (!is_array($file) || array_diff(array_keys($file), ['id', 'sha256']) !== [] || !is_int($file['id'] ?? null) || $file['id'] <= 0 || !is_string($file['sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/', $file['sha256'])) {
                throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Each file must include an id and SHA-256');
            }
            if (isset($normalized[$file['id']])) {
                throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'A file may only appear once');
            }
            $normalized[$file['id']] = ['id' => $file['id'], 'sha256' => $file['sha256']];
        }
        ksort($normalized, SORT_NUMERIC);
        return ['code' => $code, 'name' => $name, 'description' => $description === '' ? null : $description, 'files' => array_values($normalized), 'replace_draft' => $input['replace_draft'] ?? false];
    }

    /** @param array<string,mixed> $input @return array{query:string,limit:int} */
    public static function normalizeQuery(array $input): array
    {
        if (array_diff(array_keys($input), ['query', 'limit']) !== [] || !is_string($input['query'] ?? null) || (array_key_exists('limit', $input) && !is_int($input['limit']))) self::invalid();
        $query = trim($input['query']);
        $limit = $input['limit'] ?? 10;
        if ($query === '' || mb_strlen($query, 'UTF-8') > 500 || $limit < 1 || $limit > 20) {
            throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Knowledge query is invalid');
        }
        return ['query' => $query, 'limit' => $limit];
    }

    /** Public consumer options; internal retrieval keeps normalizeQuery's original contract.
     * @param array<string,mixed> $input
     * @return array{query:string,limit:int,rerank:bool,model:?string,rerank_mode?:string}
     */
    public static function normalizeConsumerQuery(array $input): array
    {
        if (array_diff(array_keys($input), ['query', 'limit', 'rerank', 'model', 'rerank_mode']) !== []) self::invalid();
        $rerank = $input['rerank'] ?? false;
        if (!is_bool($rerank) || (array_key_exists('rerank', $input) && $input['rerank'] === null)) self::invalid();
        $search = self::normalizeQuery(['query' => $input['query'] ?? null, 'limit' => $input['limit'] ?? 10]);
        if (array_key_exists('limit', $input) && !is_int($input['limit'])) self::invalid();
        if (!$rerank) {
            if (array_key_exists('model', $input) || array_key_exists('rerank_mode', $input)) self::invalid();
            return $search + ['rerank' => false, 'model' => null];
        }
        if ($search['limit'] > 10) self::invalid();
        if (array_key_exists('rerank_mode', $input)) {
            if ($input['rerank_mode'] !== 'professional' || !is_string($input['model'] ?? null)) self::invalid();
            $model = trim($input['model']);
            if (!mb_check_encoding($model, 'UTF-8') || $model === '' || mb_strlen($model, 'UTF-8') > 128) self::invalid();
            return $search + ['rerank' => true, 'model' => $model, 'rerank_mode' => 'professional'];
        }
        // Reuse the same chat model validation as the existing billed conversation rerank.
        $chat = KnowledgeAnswerPolicy::normalize([
            'query' => $search['query'], 'model' => $input['model'] ?? null,
            'max_output_tokens' => 256, 'rerank' => true,
        ]);
        return $search + ['rerank' => true, 'model' => $chat['model']];
    }

    /** @param array<string,mixed> $value */
    public static function fingerprint(array $value): string
    {
        self::sort($value);
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public static function escapedLike(string $query): string { return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query); }
    /** @param array<mixed> $value */
    private static function sort(array &$value): void { if (!array_is_list($value)) ksort($value, SORT_STRING); foreach ($value as &$item) if (is_array($item)) self::sort($item); }
    private static function invalid(): never { throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Knowledge base input is invalid'); }
}
