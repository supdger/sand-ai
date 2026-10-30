<?php

declare(strict_types=1);

namespace app\Infrastructure\Parsing;

use NeuronAI\RAG\Document;
use NeuronAI\RAG\Splitter\DelimiterTextSplitter;

final class NeuronSourceTextSplitter
{
    /** @return list<string> */
    public static function split(string $content, int $maxCharacters): array
    {
        if ($maxCharacters < 1 || $maxCharacters > 16384 || !mb_check_encoding($content, 'UTF-8')) {
            throw new ParseException('SAND_AI_PARSE_FAILED', 'Text source split bounds are invalid');
        }
        if ($content === '') return [];
        // The SDK requires a nonempty delimiter and does not split a single unbroken word.
        // Project Unicode code points as delimited words; only the official splitter chooses chunks.
        $separatorNo = 0;
        do {
            $separator = "\x1f" . $separatorNo++ . "\x1e";
        } while (str_contains($content, $separator));
        $projected = preg_replace('/(?<=.)(?=.)/us', $separator, $content);
        if (!is_string($projected)) {
            throw new ParseException('SAND_AI_PARSE_FAILED', 'Text source positions could not be preserved');
        }
        $maxProjectedLength = $maxCharacters + ($maxCharacters - 1) * mb_strlen($separator, 'UTF-8');
        $splitter = new DelimiterTextSplitter($maxProjectedLength, $separator, 0, 0);
        $pieces = [];
        $covered = 0;
        foreach ($splitter->splitDocument(new Document($projected)) as $document) {
            $piece = str_replace($separator, '', $document->getContent());
            $length = mb_strlen($piece, 'UTF-8');
            if ($length < 1 || $length > $maxCharacters || !mb_check_encoding($piece, 'UTF-8')
                || $piece !== mb_substr($content, $covered, $length, 'UTF-8')) {
                throw new ParseException('SAND_AI_PARSE_FAILED', 'Text source positions could not be preserved');
            }
            $pieces[] = $piece;
            $covered += $length;
        }
        if ($covered !== mb_strlen($content, 'UTF-8')) {
            throw new ParseException('SAND_AI_PARSE_FAILED', 'Text source positions could not be preserved');
        }
        return $pieces;
    }

}
