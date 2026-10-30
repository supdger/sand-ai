<?php

declare(strict_types=1);
namespace app\Application\Composition;

use app\Api\Support\ApiProblem;

/** Validates administrator-owned DAGs; input/result values are resolved only under the runtime caller. */
final class CompositionGraphPolicy
{
    public function validate(array $graph): array
    {
        self::fields($graph, ['nodes', 'output_nodes', 'failure_policy']);
        $nodes = $graph['nodes'] ?? null;
        if (!is_array($nodes) || !array_is_list($nodes) || count($nodes) < 1 || count($nodes) > 12
            || !in_array($graph['failure_policy'] ?? null, ['stop', 'continue_independent'], true)) self::invalid();
        $byId = [];
        foreach ($nodes as $node) {
            if (!is_array($node)) self::invalid();
            self::fields($node, ['id', 'name', 'service', 'depends_on', 'inputs']);
            if (!is_string($node['id'] ?? null) || preg_match('/\A[a-z][a-z0-9_]{0,31}\z/', $node['id']) !== 1
                || isset($byId[$node['id']]) || !is_string($node['name'] ?? null) || trim($node['name']) === ''
                || strlen($node['name']) > 200 || !is_array($node['service'] ?? null)) self::invalid();
            self::fields($node['service'], ['kind', 'code', 'version']);
            if (!in_array($node['service']['kind'] ?? null, ['agent', 'skill', 'material', 'knowledge', 'tool'], true)
                || !is_string($node['service']['code'] ?? null) || preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/', $node['service']['code']) !== 1
                || (!is_int($node['service']['version'] ?? null) && !is_string($node['service']['version'] ?? null))) self::invalid();
            $dependencies = $node['depends_on'] ?? null;
            if (!is_array($dependencies) || !array_is_list($dependencies) || count($dependencies) > 11
                || count(array_unique($dependencies, SORT_REGULAR)) !== count($dependencies)) self::invalid();
            foreach ($dependencies as $dependency) if (!is_string($dependency) || $dependency === $node['id']) self::invalid();
            $inputs = $node['inputs'] ?? null;
            if (!is_array($inputs) || ($inputs !== [] && array_is_list($inputs)) || count($inputs) > 32) self::invalid();
            foreach ($inputs as $name => $binding) {
                if (!is_string($name) || preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $name) !== 1
                    || in_array($name, ['request_id', 'request_key', 'environment_id', 'organization_id', 'application_id', 'workload_client_id', 'execution_snapshot', 'idempotency_key'], true)
                    || !is_array($binding)) self::invalid();
                $source = $binding['source'] ?? null;
                if ($source === 'literal') {
                    self::fields($binding, ['source', 'value']);
                    if (!array_key_exists('value', $binding) || strlen(self::json($binding['value'])) > 32768) self::invalid();
                } elseif ($source === 'input' || $source === 'node') {
                    self::fields($binding, $source === 'node' ? ['source', 'node_id', 'path'] : ['source', 'path']);
                    self::path($binding['path'] ?? null);
                    if ($source === 'node' && (!is_string($binding['node_id'] ?? null)
                        || !in_array($binding['node_id'], $dependencies, true))) self::invalid();
                } else self::invalid();
            }
            $byId[$node['id']] = $node;
        }
        $ordered = [];
        while (count($ordered) < count($byId)) {
            $previous = count($ordered);
            foreach ($byId as $id => $node) {
                if (isset($ordered[$id])) continue;
                foreach ($node['depends_on'] as $dependency) if (!isset($byId[$dependency])) self::invalid();
                if (array_diff($node['depends_on'], array_keys($ordered)) === []) $ordered[$id] = $node;
            }
            if ($previous === count($ordered)) self::invalid();
        }
        $outputs = $graph['output_nodes'] ?? null;
        if (!is_array($outputs) || !array_is_list($outputs) || $outputs === [] || count($outputs) > 12
            || count(array_unique($outputs, SORT_REGULAR)) !== count($outputs)) self::invalid();
        foreach ($outputs as $id) if (!is_string($id) || !isset($byId[$id])) self::invalid();
        $result = ['nodes' => array_values($ordered), 'output_nodes' => $outputs, 'failure_policy' => $graph['failure_policy']];
        if (strlen(self::json($result)) > 131072) self::invalid();
        return $result;
    }

    public function validateInputs(array $node, array $fields): void
    {
        $allowed = [];
        foreach ($fields as $field) {
            $allowed[] = $field['name'];
            if ($field['required'] && !array_key_exists($field['name'], $node['inputs'])) self::invalid();
            if (isset($node['inputs'][$field['name']]) && $node['inputs'][$field['name']]['source'] === 'literal') {
                self::valueType($node['inputs'][$field['name']]['value'], $field['schema'] ?? []);
            }
        }
        if (array_diff(array_keys($node['inputs']), $allowed) !== []) self::invalid();
    }

    public function inputs(array $node, array $input, array $results, array $fields): array
    {
        $bound = [];
        foreach ($node['inputs'] as $name => $binding) {
            $bound[$name] = match ($binding['source']) {
                'literal' => $binding['value'],
                'input' => self::pointer($input, $binding['path']),
                'node' => self::pointer($results[$binding['node_id']] ?? self::missing(), $binding['path']),
                default => self::missing(),
            };
        }
        foreach ($fields as $field) {
            if (!array_key_exists($field['name'], $bound)) { if ($field['required']) self::invalid(); continue; }
            self::valueType($bound[$field['name']], $field['schema'] ?? []);
        }
        if (strlen(self::json($bound)) > 131072) self::invalid();
        return $bound;
    }

    public function validateOutputBindings(array $graph, array $references): void
    {
        foreach ($graph['nodes'] as $node) {
            $fields = [];
            foreach ($references[$node['id']]['input_fields'] as $field) $fields[$field['name']] = $field['schema'];
            foreach ($node['inputs'] as $name => $binding) {
                if ($binding['source'] !== 'node') continue;
                $schema = $references[$binding['node_id']]['output_schema'] ?? null;
                if (!is_array($schema)) self::invalid();
                if ($binding['path'] !== '') foreach (explode('/', substr($binding['path'], 1)) as $part) {
                    $part = str_replace(['~1','~0'], ['/','~'], $part);
                    if (($schema['type'] ?? null) === 'array' && preg_match('/\A(?:0|[1-9][0-9]*)\z/', $part) === 1 && is_array($schema['items'] ?? null)) $schema = $schema['items'];
                    elseif (is_array($schema['properties'][$part] ?? null)) $schema = $schema['properties'][$part];
                    else self::invalid();
                }
                $from = $schema['type'] ?? null; $to = $fields[$name]['type'] ?? null;
                if ($from === null || $to === null || ($from !== $to && !($from === 'integer' && $to === 'number'))) self::invalid();
            }
        }
    }

    private static function valueType(mixed $value, array $schema): void
    {
        $type = $schema['type'] ?? null;
        if ($type === null) return;
        $types = is_array($type) ? $type : [$type];
        foreach ($types as $allowed) {
            $valid = match ($allowed) {
                'string' => is_string($value), 'integer' => is_int($value),
                'number' => is_int($value) || is_float($value), 'boolean' => is_bool($value),
                'null' => $value === null, 'array' => is_array($value) && array_is_list($value),
                'object' => $value instanceof \stdClass || (is_array($value) && (!array_is_list($value) || $value === [])),
                default => false,
            };
            if ($valid) return;
        }
        self::invalid();
    }

    private static function pointer(mixed $value, string $path): mixed
    {
        self::path($path);
        if ($path === '') return $value;
        foreach (explode('/', substr($path, 1)) as $token) {
            $key = str_replace(['~1', '~0'], ['/', '~'], $token);
            if (is_object($value)) $value = (array) $value;
            if (!is_array($value) || !array_key_exists($key, $value)) self::invalid();
            $value = $value[$key];
        }
        return $value;
    }

    private static function path(mixed $path): void
    {
        if (!is_string($path) || strlen($path) > 512 || ($path !== '' && $path[0] !== '/') || preg_match('/~(?![01])/', $path) === 1) self::invalid();
    }
    private static function fields(array $value, array $allowed): void { if (array_diff(array_keys($value), $allowed) !== [] || count($value) !== count($allowed)) self::invalid(); }
    public static function json(mixed $value): string { return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
    public static function digest(mixed $value): string { return hash('sha256', self::json(self::canonical($value))); }
    private static function canonical(mixed $value): mixed { if (!is_array($value)) return $value; if (!array_is_list($value)) ksort($value, SORT_STRING); foreach ($value as $key => $child) $value[$key] = self::canonical($child); return $value; }
    private static function missing(): never { self::invalid(); }
    private static function invalid(): never { throw new ApiProblem('SAND_AI_COMPOSITION_GRAPH_INVALID', '请核对节点、依赖、参数来源与服务输入类型'); }
}
