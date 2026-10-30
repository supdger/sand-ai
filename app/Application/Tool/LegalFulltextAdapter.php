<?php

declare(strict_types=1);

namespace app\Application\Tool;

use app\Api\Support\ApiProblem;

/** Exact remote arguments and administrator-selected JSON fields; no vendor assumptions. */
final class LegalFulltextAdapter
{
    public static function supports(mixed $kind): bool
    {
        return $kind === 'legal_fulltext_fetch@1';
    }

    /** @return array<string,mixed> */
    public static function normalize(array $adapter, array $inputSchema): array
    {
        $keys = array_keys($adapter);
        sort($keys, SORT_STRING);
        if ($keys !== ['kind', 'remote_input_schema', 'remote_output_schema', 'result_fields', 'result_mode']
            || !self::supports($adapter['kind'] ?? null)) self::invalid();
        $remote = McpDocumentAdapter::remoteSchema($adapter['remote_input_schema']);
        // Do not rename, default, strip constraints or manufacture enumerations.
        $local = self::inputSchema($remote);
        if (ToolFingerprint::canonicalJson($local) !== ToolFingerprint::canonicalJson($inputSchema)) self::invalid();
        $output = $adapter['remote_output_schema'] === null ? null : McpDocumentAdapter::remoteSchema($adapter['remote_output_schema']);
        $mode = $adapter['result_mode'];
        $fields = $adapter['result_fields'];
        if (!in_array($mode, ['structured_content', 'mcp_text'], true) || !is_array($fields)
            || array_is_list($fields) || !array_key_exists('title', $fields)) self::invalid();
        if ($mode === 'structured_content') {
            if (!array_key_exists('content', $fields)
                || array_diff(array_keys($fields), ['content', 'title', 'source_version', 'license_scope']) !== []) self::invalid();
        } elseif (array_keys($fields) !== ['title']) self::invalid();
        foreach ($fields as $pointer) self::pointerSegments($pointer);
        if (count(array_unique(array_values($fields), SORT_STRING)) !== count($fields)) self::invalid();
        return ['kind' => 'legal_fulltext_fetch@1', 'remote_input_schema' => $remote,
            'remote_output_schema' => $output, 'result_mode' => $mode, 'result_fields' => $fields];
    }

    /** Actual names and validation constraints; descriptive annotations are not execution defaults. */
    public static function inputSchema(array $remote): array
    {
        return ToolSchemaPolicy::normalize(self::executionSchema(McpDocumentAdapter::remoteSchema($remote)));
    }

    /** Strip only descriptive annotations, never unsupported validation keywords. */
    private static function executionSchema(array $schema): array
    {
        unset($schema['description'], $schema['title'], $schema['$schema'], $schema['default'], $schema['examples']);
        if (is_array($schema['properties'] ?? null)) {
            foreach ($schema['properties'] as $name => $property) {
                if (is_array($property)) $schema['properties'][$name] = self::executionSchema($property);
            }
        }
        if (is_array($schema['items'] ?? null)) $schema['items'] = self::executionSchema($schema['items']);
        return $schema;
    }

    /** @return array<string,mixed> */
    public static function outputSchema(array $fields): array
    {
        $properties = [
            'content' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 60000],
            'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 300],
        ];
        foreach (['source_version', 'license_scope'] as $optional) {
            if (array_key_exists($optional, $fields)) {
                $properties[$optional] = ['type' => 'string', 'minLength' => 1, 'maxLength' => 512];
            }
        }
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['content', 'title'],
            'properties' => $properties];
    }

    /** @return array<string,mixed> */
    public static function arguments(array $adapter, array|\stdClass $input): array
    {
        ToolSchemaPolicy::assertValue(self::inputSchema($adapter['remote_input_schema']), $input);
        return (array) $input;
    }

    /** @return array{result:array<string,string>,citations:list<array<string,mixed>>} */
    public static function result(array $adapter, array $response, array $input): array
    {
        if (array_key_exists('isError', $response) && $response['isError'] !== false) self::invalidResult();
        $fields = $adapter['result_fields'];
        $canonical = [];
        if ($adapter['result_mode'] === 'structured_content') {
            $payload = $response['structuredContent'] ?? null;
            if (!is_array($payload) || array_is_list($payload)) self::invalidResult();
            foreach ($fields as $name => $pointer) {
                [$exists, $value] = self::at($payload, $pointer);
                if ((!$exists || $value === null) && in_array($name, ['source_version', 'license_scope'], true)) continue;
                $canonical[$name] = self::stringValue($value, $name);
            }
        } else {
            $blocks = $response['content'] ?? null;
            if (!is_array($blocks) || !array_is_list($blocks) || $blocks === [] || count($blocks) > 32) self::invalidResult();
            $parts = [];
            $bytes = 0;
            foreach ($blocks as $block) {
                if (!is_array($block) || ($block['type'] ?? null) !== 'text' || !is_string($block['text'] ?? null)
                    || !self::text($block['text'])) self::invalidResult();
                $bytes += strlen($block['text']) + ($parts === [] ? 0 : 1);
                if ($bytes > 60000) self::invalidResult();
                $parts[] = $block['text'];
            }
            [, $title] = self::at($input, $fields['title']);
            $canonical = ['content' => self::stringValue(implode("\n", $parts), 'content'),
                'title' => self::stringValue($title, 'title')];
        }
        // The existing ToolSchemaPolicy bounds the whole JSON envelope, including escaping.
        ToolSchemaPolicy::assertValue(self::outputSchema($fields), $canonical);
        $hash = hash('sha256', $canonical['content']);
        $ref = 'legal_fulltext:' . hash('sha256', ToolFingerprint::canonicalJson($input) . "\0" . $hash);
        return ['result' => $canonical, 'citations' => [
            ['source_ref' => $ref, 'locator' => ['type' => 'opaque', 'value' => $ref], 'content_hash' => $hash],
        ]];
    }

    /** Only RFC6901 member/index traversal. No queries, wildcards, code or templates. @return list<string> */
    private static function pointerSegments(mixed $pointer): array
    {
        if (!is_string($pointer) || strlen($pointer) > 512 || !str_starts_with($pointer, '/')
            || !self::text($pointer) || preg_match('/~(?![01])/', $pointer) === 1) self::invalid();
        $segments = explode('/', substr($pointer, 1));
        if (count($segments) > 8) self::invalid();
        return array_map(static fn (string $segment): string => str_replace(['~1', '~0'], ['/', '~'], $segment), $segments);
    }

    /** @return array{bool,mixed} */
    private static function at(array $payload, string $pointer): array
    {
        $value = $payload;
        foreach (self::pointerSegments($pointer) as $segment) {
            if (!is_array($value)) return [false, null];
            if (array_is_list($value)) {
                if (preg_match('/\A(?:0|[1-9][0-9]{0,8})\z/D', $segment) !== 1) return [false, null];
                $index = (int) $segment;
                if (!array_key_exists($index, $value)) return [false, null];
                $value = $value[$index];
            } else {
                if (!array_key_exists($segment, $value)) return [false, null];
                $value = $value[$segment];
            }
        }
        return [true, $value];
    }

    private static function stringValue(mixed $value, string $field): string
    {
        $limit = match ($field) { 'content' => 60000, 'title' => 300, default => 512 };
        if (!is_string($value) || trim($value) === '' || !self::text($value)
            || strlen($value) > ($field === 'content' ? 60000 : $limit * 4)
            || mb_strlen($value, 'UTF-8') > $limit) self::invalidResult();
        return $value;
    }

    private static function text(string $value): bool
    {
        return preg_match('//u', $value) === 1 && preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value) !== 1;
    }

    private static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_MCP_REGISTRY_INVALID', 'Legal fulltext registration needs exact supported schemas and explicit result field paths');
    }

    private static function invalidResult(): never
    {
        throw new ApiProblem('SAND_AI_TOOL_RESPONSE_SCHEMA_INVALID', 'Legal fulltext result does not match its published private content contract');
    }
}
