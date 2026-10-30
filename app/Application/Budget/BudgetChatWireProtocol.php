<?php

declare(strict_types=1);

namespace app\Application\Budget;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\ProviderRequestBodyBuilder;

/** Financial wire shape, not a promise that a provider implements each output mode. */
final class BudgetChatWireProtocol
{
    public const VERSION = 'text_messages_tools_schema@1';
    private const SCHEMA_KEYS = ['type', 'properties', 'required', 'items', 'enum', 'description', 'title',
        'minimum', 'maximum', 'minLength', 'maxLength', 'minItems', 'maxItems', 'additionalProperties'];

    /** Validate the actual SDK body without modifying it or its canonical digest. */
    public static function assertBody(array $body, string $model, int $maxOutput): int
    {
        $nodes = 0;
        self::jsonTree($body, 0, $nodes);
        if (strlen(ProviderRequestBodyBuilder::bytes($body)) > 1048576
            || array_diff(array_keys($body), ['model', 'messages', 'max_tokens', 'stream', 'stream_options',
                'tool_stream', 'reasoning_effort', 'temperature', 'top_p', 'do_sample', 'stop',
                'tools', 'tool_choice', 'response_format']) !== []
            || ($body['model'] ?? null) !== $model
            || !is_int($body['max_tokens'] ?? null) || $body['max_tokens'] < 1 || $body['max_tokens'] > $maxOutput) self::invalid();
        foreach (['stream', 'tool_stream', 'do_sample'] as $key) if (array_key_exists($key, $body) && !is_bool($body[$key])) self::invalid();
        if (array_key_exists('stream_options', $body)) {
            $options = self::object($body['stream_options']);
            if (($body['stream'] ?? false) !== true || array_keys($options) !== ['include_usage'] || $options['include_usage'] !== true) self::invalid();
        }
        if (($body['tool_stream'] ?? false) && ($body['stream'] ?? false) !== true) self::invalid();
        if (array_key_exists('reasoning_effort', $body) && !in_array($body['reasoning_effort'], ['low', 'high', 'max'], true)) self::invalid();
        foreach (['temperature', 'top_p'] as $key) {
            if (array_key_exists($key, $body) && ((!is_int($body[$key]) && !is_float($body[$key]))
                || !is_finite((float) $body[$key]) || $body[$key] < ($key === 'top_p' ? 0.01 : 0) || $body[$key] > 1)) self::invalid();
        }
        if (array_key_exists('stop', $body)) {
            if (!is_array($body['stop']) || !array_is_list($body['stop']) || count($body['stop']) < 1 || count($body['stop']) > 4) self::invalid();
            foreach ($body['stop'] as $text) self::text($text, 256, true);
        }
        $names = [];
        if (array_key_exists('tools', $body)) {
            if (!is_array($body['tools']) || !array_is_list($body['tools']) || count($body['tools']) < 1 || count($body['tools']) > 128) self::invalid();
            foreach ($body['tools'] as $tool) {
                $tool = self::object($tool);
                if (array_diff(array_keys($tool), ['type', 'function']) !== [] || ($tool['type'] ?? null) !== 'function') self::invalid();
                $function = self::object($tool['function'] ?? null);
                if (array_diff(array_keys($function), ['name', 'description', 'parameters', 'strict']) !== []) self::invalid();
                $name = self::name($function['name'] ?? null);
                if (isset($names[$name])) self::invalid();
                $names[$name] = true;
                if (array_key_exists('description', $function)) self::text($function['description'], 8192);
                if (array_key_exists('strict', $function) && !is_bool($function['strict'])) self::invalid();
                self::schema($function['parameters'] ?? null, 0, true);
            }
        }
        // Official BigModel currently documents only auto. Other SDK choices
        // need a separately reviewed protocol, rather than an inferred alias.
        if (array_key_exists('tool_choice', $body) && ($body['tool_choice'] !== 'auto' || $names === [])) self::invalid();
        if (array_key_exists('response_format', $body)) {
            if ($names !== [] || ($body['stream'] ?? false) === true) self::invalid();
            $format = self::object($body['response_format']);
            if (in_array($format['type'] ?? null, ['text', 'json_object'], true)) {
                if (array_keys($format) !== ['type']) self::invalid();
            } elseif (($format['type'] ?? null) === 'json_schema') {
                if (array_diff(array_keys($format), ['type', 'json_schema']) !== []) self::invalid();
                $schema = self::object($format['json_schema'] ?? null);
                if (array_diff(array_keys($schema), ['name', 'description', 'schema', 'strict']) !== []
                    || !is_bool($schema['strict'] ?? null)) self::invalid();
                self::name($schema['name'] ?? null);
                if (array_key_exists('description', $schema)) self::text($schema['description'], 8192);
                self::schema($schema['schema'] ?? null, 0, true);
                // A bounded JSON schema is input to the financial proof. Its
                // supplier functionality remains a separately configured mode.
            } else self::invalid();
        }
        if (!is_array($body['messages'] ?? null) || !array_is_list($body['messages'])
            || count($body['messages']) < 1 || count($body['messages']) > 64) self::invalid();
        $pending = []; $seen = []; $user = false;
        foreach ($body['messages'] as $message) {
            $message = self::object($message);
            $role = $message['role'] ?? null;
            if ($role === 'tool') {
                if (array_diff(array_keys($message), ['role', 'content', 'tool_call_id']) !== []) self::invalid();
                $id = self::callId($message['tool_call_id'] ?? null);
                if (!isset($pending[$id])) self::invalid();
                self::content($message['content'] ?? null, 32768);
                unset($pending[$id]);
            } elseif ($role === 'assistant' && array_key_exists('tool_calls', $message)) {
                if ($pending !== [] || array_diff(array_keys($message), ['role', 'content', 'tool_calls']) !== []
                    || !is_array($message['tool_calls']) || !array_is_list($message['tool_calls'])
                    || count($message['tool_calls']) < 1 || count($message['tool_calls']) > 128) self::invalid();
                if (($message['content'] ?? null) !== null) self::content($message['content'], 131072);
                foreach ($message['tool_calls'] as $call) {
                    $call = self::object($call);
                    if (array_diff(array_keys($call), ['id', 'type', 'function']) !== [] || ($call['type'] ?? null) !== 'function') self::invalid();
                    $id = self::callId($call['id'] ?? null);
                    if (isset($seen[$id])) self::invalid();
                    $function = self::object($call['function'] ?? null);
                    if (array_diff(array_keys($function), ['name', 'arguments']) !== [] || !isset($names[self::name($function['name'] ?? null)])) self::invalid();
                    self::text($function['arguments'] ?? null, 32768, true);
                    try { $arguments = json_decode($function['arguments'], false, 12, JSON_THROW_ON_ERROR); }
                    catch (\JsonException) { self::invalid(); }
                    if (!$arguments instanceof \stdClass) self::invalid();
                    $pending[$id] = true; $seen[$id] = true;
                }
            } else {
                if ($pending !== [] || !in_array($role, ['system', 'user', 'assistant'], true)
                    || array_diff(array_keys($message), ['role', 'content']) !== []) self::invalid();
                self::content($message['content'] ?? null, 131072);
                $user = $user || $role === 'user';
            }
        }
        if (!$user || $pending !== []) self::invalid();
        return $body['max_tokens'];
    }

    private static function schema(mixed $value, int $depth, bool $root = false): void
    {
        $schema = self::object($value);
        $type = $schema['type'] ?? null;
        if ($depth > 8 || array_diff(array_keys($schema), self::SCHEMA_KEYS) !== []
            || !in_array($type, ['object', 'array', 'string', 'integer', 'number', 'boolean'], true)
            || ($root && $type !== 'object') || strlen(json_encode($value, JSON_THROW_ON_ERROR)) > 32768) self::invalid();
        if (array_key_exists('properties', $schema)) {
            if ($type !== 'object') self::invalid();
            $properties = self::object($schema['properties'], true);
            if (count($properties) > 64) self::invalid();
            foreach ($properties as $name => $child) { self::text($name, 128, true); self::schema($child, $depth + 1); }
        }
        if (array_key_exists('required', $schema)) {
            if ($type !== 'object' || !is_array($schema['required']) || !array_is_list($schema['required'])
                || count($schema['required']) > 64 || count(array_unique($schema['required'], SORT_REGULAR)) !== count($schema['required'])) self::invalid();
            $properties = self::object($schema['properties'] ?? new \stdClass(), true);
            foreach ($schema['required'] as $name) { self::text($name, 128, true); if (!array_key_exists($name, $properties)) self::invalid(); }
        }
        if (array_key_exists('additionalProperties', $schema) && ($type !== 'object' || !is_bool($schema['additionalProperties']))) self::invalid();
        if (array_key_exists('items', $schema)) { if ($type !== 'array') self::invalid(); self::schema($schema['items'], $depth + 1); }
        foreach (['title', 'description'] as $key) if (array_key_exists($key, $schema)) self::text($schema[$key], 8192);
        foreach (['minLength', 'maxLength', 'minItems', 'maxItems'] as $key) {
            if (array_key_exists($key, $schema) && ($type !== (str_ends_with($key, 'Length') ? 'string' : 'array')
                || !is_int($schema[$key]) || $schema[$key] < 0 || $schema[$key] > 100000)) self::invalid();
        }
        foreach (['minimum', 'maximum'] as $key) if (array_key_exists($key, $schema)
            && (!in_array($type, ['integer', 'number'], true) || (!is_int($schema[$key]) && !is_float($schema[$key])) || !is_finite((float) $schema[$key]))) self::invalid();
        foreach ([['minLength', 'maxLength'], ['minItems', 'maxItems'], ['minimum', 'maximum']] as [$min, $max])
            if (isset($schema[$min], $schema[$max]) && $schema[$min] > $schema[$max]) self::invalid();
        if (array_key_exists('enum', $schema) && (!is_array($schema['enum']) || !array_is_list($schema['enum'])
            || count($schema['enum']) < 1 || count($schema['enum']) > 100)) self::invalid();
    }

    private static function jsonTree(mixed $value, int $depth, int &$nodes): void
    {
        if ($depth > 16 || ++$nodes > 16384) self::invalid();
        if (is_array($value) || $value instanceof \stdClass) {
            foreach ($value as $key => $child) {
                if (!is_int($key) && !is_string($key)) self::invalid();
                if (is_string($key)) self::text($key, 128, true);
                self::jsonTree($child, $depth + 1, $nodes);
            }
        } elseif (is_string($value)) self::text($value, 131072);
        elseif ($value !== null && !is_bool($value) && !is_int($value) && (!is_float($value) || !is_finite($value))) self::invalid();
    }

    private static function object(mixed $value, bool $empty = false): array
    {
        if ($value instanceof \stdClass) return (array) $value;
        if (!is_array($value) || (array_is_list($value) && !($empty && $value === []))) self::invalid();
        return $value;
    }

    /** Official Neuron MessageMapper emits plain text blocks for structured output. */
    private static function content(mixed $value, int $max): void
    {
        if (is_string($value)) { self::text($value, $max); return; }
        if (!is_array($value) || !array_is_list($value) || count($value) < 1 || count($value) > 64) self::invalid();
        $bytes = 0;
        foreach ($value as $block) {
            $block = self::object($block);
            if (array_diff(array_keys($block), ['type', 'text']) !== [] || ($block['type'] ?? null) !== 'text') self::invalid();
            self::text($block['text'] ?? null, $max);
            $bytes += strlen($block['text']);
            if ($bytes > $max) self::invalid();
        }
    }

    private static function text(mixed $value, int $max, bool $nonempty = false): void
    {
        if (!is_string($value) || strlen($value) > $max || preg_match('//u', $value) !== 1
            || ($nonempty && trim($value) === '')) self::invalid();
    }

    private static function name(mixed $value): string
    {
        if (!is_string($value) || preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D', $value) !== 1) self::invalid();
        return $value;
    }

    private static function callId(mixed $value): string
    {
        if (!is_string($value) || preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', $value) !== 1) self::invalid();
        return $value;
    }

    private static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_BUDGET_CAPACITY_UNVERIFIED', 'The final SDK chat wire body is outside its reviewed bounded text/tool/schema protocol');
    }
}
