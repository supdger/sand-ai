<?php

declare(strict_types=1);

namespace app\Application\Tool;

use app\Api\Support\ApiProblem;

/** Search matches must correspond exactly to controlled, versioned citations. */
final class ExternalSearchResultPolicy
{
    /** @param array<string,mixed> $result @param list<array<string,mixed>> $citations */
    public static function assertSources(array $result, array $citations, int $limit): void
    {
        if (!is_array($result['matches'] ?? null) || !array_is_list($result['matches'])
            || $limit < 1 || $limit > 20 || count($result['matches']) > $limit) {
            self::invalid();
        }
        $matches = [];
        foreach ($result['matches'] as $match) {
            if (!is_array($match) || !is_string($match['source_ref'] ?? null)
                || isset($matches[$match['source_ref']])) self::invalid();
            $matches[$match['source_ref']] = true;
        }
        $sources = [];
        foreach ($citations as $citation) {
            $source = $citation['source_ref'];
            if (!isset($matches[$source])) self::invalid();
            $sources[$source] = true;
        }
        if (array_diff_key($matches, $sources) !== []) self::invalid();
    }

    private static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_TOOL_RESPONSE_SCHEMA_INVALID', 'External search matches require matching controlled sources');
    }
}
