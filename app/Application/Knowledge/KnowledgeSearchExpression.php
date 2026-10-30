<?php

declare(strict_types=1);

namespace app\Application\Knowledge;

use app\Api\Support\ApiProblem;

/**
 * Bounded lexical recall for the published-source queries. PostgreSQL's
 * "simple" text search does not segment Chinese text into useful terms.
 */
final class KnowledgeSearchExpression
{
    public const LEGACY_ALGORITHM = 'lexical_013';
    public const CURRENT_ALGORITHM = 'lexical_contiguous_v1';

    private const CHINESE_FRAGMENT_LIMIT = 8;

    /** @return array{score_sql:string,match_sql:string,score_binds:list<string>,match_binds:list<string>} */
    public static function build(string $query, string $alias = 'b', string $algorithm = self::CURRENT_ALGORITHM): array
    {
        if (!in_array($algorithm, [self::LEGACY_ALGORITHM, self::CURRENT_ALGORITHM], true)) {
            throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Knowledge retrieval algorithm is unavailable');
        }
        if (preg_match('/\A[a-z_][a-z0-9_]*\z/', $alias) !== 1) {
            throw new \InvalidArgumentException('Knowledge search alias is invalid');
        }
        $vector = $alias . '.search_vector';
        $content = $alias . '.content';
        $phrase = KnowledgeBasePolicy::escapedLike($query);
        $score = [
            "CASE WHEN {$vector} @@ plainto_tsquery('simple', ?) THEN ts_rank_cd({$vector}, plainto_tsquery('simple', ?)) ELSE 0 END",
            "CASE WHEN {$content} ILIKE '%' || ? || '%' ESCAPE E'\\\\' THEN 1 ELSE 0 END",
        ];
        $match = [
            "{$vector} @@ plainto_tsquery('simple', ?)",
            "{$content} ILIKE '%' || ? || '%' ESCAPE E'\\\\'",
        ];
        $scoreBinds = [$query, $query, $phrase];
        $matchBinds = [$query, $phrase];
        $terms = array_values(array_unique([...self::chineseBigrams($query), ...self::latinTerms($query)]));
        $weight = $terms === [] ? 0.0 : 0.8 / count($terms);
        foreach ($terms as $term) {
            $score[] = "CASE WHEN {$content} ILIKE '%' || ? || '%' ESCAPE E'\\\\' THEN " . number_format($weight, 6, '.', '') . ' ELSE 0 END';
            $match[] = "{$content} ILIKE '%' || ? || '%' ESCAPE E'\\\\'";
            $escaped = KnowledgeBasePolicy::escapedLike($term);
            $scoreBinds[] = $escaped;
            $matchBinds[] = $escaped;
        }
        if ($algorithm === self::CURRENT_ALGORITHM) {
            // Longer phrases only reorder existing candidates. Persisted
            // consumers can select the frozen legacy order during recovery.
            foreach ([3 => 800000, 4 => 1200000] as $width => $coverageMicros) {
                $fragments = self::chineseFragments($query, $width);
                if ($fragments === []) {
                    continue;
                }
                $fragmentWeight = number_format(
                    intdiv($coverageMicros, count($fragments)) / 1000000, 6, '.', '',
                );
                foreach ($fragments as $fragment) {
                    $score[] = "CASE WHEN {$content} ILIKE '%' || ? || '%' ESCAPE E'\\\\' THEN "
                        . $fragmentWeight . ' ELSE 0 END';
                    $scoreBinds[] = KnowledgeBasePolicy::escapedLike($fragment);
                }
            }
        }
        return [
            'score_sql' => '(' . implode(' + ', $score) . ')',
            'match_sql' => '(' . implode(' OR ', $match) . ')',
            'score_binds' => $scoreBinds,
            'match_binds' => $matchBinds,
        ];
    }

    /**
     * Keep a bounded whole Latin identifier from a natural-language question.
     * Han characters may delimit an identifier; accented Latin letters and
     * Unicode digits or combining marks may not turn a larger identifier
     * into a partial match. A final dot delimits prose; internal dots remain
     * part of identifiers such as foo.bar.
     * SQL receives escaped bind values, never an interpolated query term.
     *
     * @return list<string>
     */
    public static function latinTerms(string $query): array
    {
        if (preg_match_all('/(?<![\p{Latin}\p{N}\p{M}_.-])[A-Za-z0-9]+(?:[._-][A-Za-z0-9]+)*(?![\p{Latin}\p{N}\p{M}_-]|\.[A-Za-z0-9])/u', $query, $matches) === false) {
            throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Knowledge query encoding is invalid');
        }
        $stopWords = ['the', 'and', 'for', 'with', 'from', 'this', 'that', 'these', 'those', 'please', 'tell', 'about', 'what', 'which', 'where', 'when', 'how', 'can', 'could', 'would', 'should', 'into'];
        $terms = [];
        foreach ($matches[0] as $term) {
            $term = strtolower($term);
            if (strlen($term) < 3 || strlen($term) > 64 || in_array($term, $stopWords, true)) {
                continue;
            }
            $terms[$term] = $term;
            if (count($terms) === 8) {
                break;
            }
        }
        return array_values($terms);
    }

    /** @return list<string> */
    public static function chineseBigrams(string $query): array
    {
        // Preserve the 0.1.3 implementation for persisted ordered evidence.
        if (preg_match_all('/\p{Han}{2,}/u', $query, $runs) === false) {
            throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Knowledge query encoding is invalid');
        }
        $unique = [];
        foreach ($runs[0] as $run) {
            $length = mb_strlen($run, 'UTF-8');
            for ($index = 0; $index + 1 < $length; ++$index) {
                $term = mb_substr($run, $index, 2, 'UTF-8');
                $unique[$term] = $term;
            }
        }
        $terms = array_values($unique);
        if (count($terms) <= 8) {
            return $terms;
        }
        $sampled = [];
        $last = count($terms) - 1;
        for ($index = 0; $index < 8; ++$index) {
            $term = $terms[(int) round($index * $last / 7)];
            $sampled[$term] = $term;
        }
        return array_values($sampled);
    }

    /**
     * Sample at most eight distinct fragments per width, including both ends
     * of a long question. Never bridge punctuation, spaces or Latin tokens.
     *
     * @return list<string>
     */
    private static function chineseFragments(string $query, int $width): array
    {
        if (preg_match_all('/\p{Han}{2,}/u', $query, $runs) === false) {
            throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Knowledge query encoding is invalid');
        }
        $unique = [];
        foreach ($runs[0] as $run) {
            $length = mb_strlen($run, 'UTF-8');
            for ($index = 0; $index + $width <= $length; ++$index) {
                $term = mb_substr($run, $index, $width, 'UTF-8');
                $unique[$term] = $term;
            }
        }
        $terms = array_values($unique);
        if (count($terms) <= self::CHINESE_FRAGMENT_LIMIT) {
            return $terms;
        }
        $sampled = [];
        $last = count($terms) - 1;
        for ($index = 0; $index < self::CHINESE_FRAGMENT_LIMIT; ++$index) {
            $term = $terms[(int) round($index * $last / (self::CHINESE_FRAGMENT_LIMIT - 1))];
            $sampled[$term] = $term;
        }
        return array_values($sampled);
    }
}
