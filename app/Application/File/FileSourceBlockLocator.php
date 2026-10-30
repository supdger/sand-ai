<?php

declare(strict_types=1);

namespace app\Application\File;

use app\Api\Support\ApiProblem;
use app\Application\Audio\AudioTimeRangeLocator;

final class FileSourceBlockLocator
{
    /** @param array<string,mixed> $locator @return array<string,mixed> */
    public static function project(string $type, array $locator): array
    {
        // Only source positions are projected. Labels, paths and unrecognized fields never leave storage.
        $position = match ($type) {
            'line' => ['line' => self::integer($locator, 'line', 1)],
            'page' => ['page' => self::integer($locator, 'page', 1)],
            'paragraph' => ['paragraph' => self::integer($locator, 'paragraph', 1)],
            'table_row' => self::tableRow($locator),
            'page_region' => self::pageRegion($locator),
            'audio_time_range' => self::audioRange($locator),
            default => self::invalid(),
        };
        if (array_key_exists('char_start', $locator) || array_key_exists('char_end', $locator)) {
            if ($type !== 'line') self::invalid();
            $start = self::integer($locator, 'char_start', 1);
            $end = self::integer($locator, 'char_end', 1);
            if ($end < $start) self::invalid();
            $position['char_start'] = $start;
            $position['char_end'] = $end;
        }
        if (array_key_exists('part', $locator)) {
            $position['part'] = self::integer($locator, 'part', 2);
        }
        if (array_key_exists('document_part', $locator)) {
            if (!in_array($type, ['paragraph', 'table_row'], true)
                || !in_array($locator['document_part'], ['header', 'footer', 'footnote', 'endnote'], true)) {
                self::invalid();
            }
            $position['document_part'] = $locator['document_part'];
            if (in_array($locator['document_part'], ['header', 'footer'], true)) {
                if (array_key_exists('reference_id', $locator)) self::invalid();
                $position['part_index'] = self::integer($locator, 'part_index', 1);
            } else {
                if (array_key_exists('part_index', $locator)) self::invalid();
                $position['reference_id'] = self::integer($locator, 'reference_id', 0);
            }
        } elseif (array_key_exists('part_index', $locator) || array_key_exists('reference_id', $locator)) {
            self::invalid();
        }
        if (array_key_exists('textbox', $locator)) {
            if (!in_array($type, ['paragraph', 'table_row'], true)) self::invalid();
            $position['textbox'] = self::integer($locator, 'textbox', 1);
            $position['anchor_paragraph'] = self::integer($locator, 'anchor_paragraph', 1);
            $hasTableAnchor = array_key_exists('anchor_table', $locator)
                || array_key_exists('anchor_row', $locator) || array_key_exists('anchor_cell', $locator);
            if ($hasTableAnchor) {
                $position['anchor_table'] = self::integer($locator, 'anchor_table', 1);
                $position['anchor_row'] = self::integer($locator, 'anchor_row', 1);
                $cell = $locator['anchor_cell'] ?? null;
                if (!is_string($cell) || preg_match('/^([A-Z]{1,3})([1-9][0-9]{0,6})$/D', $cell, $matches) !== 1
                    || (int) $matches[2] !== $position['anchor_row'] || $position['anchor_row'] > 1048576) self::invalid();
                $column = 0;
                foreach (str_split($matches[1]) as $letter) $column = $column * 26 + ord($letter) - 64;
                if ($column > 16384) self::invalid();
                $position['anchor_cell'] = $cell;
            }
        } elseif (array_key_exists('anchor_paragraph', $locator) || array_key_exists('anchor_table', $locator)
            || array_key_exists('anchor_row', $locator) || array_key_exists('anchor_cell', $locator)) {
            self::invalid();
        }
        return $position;
    }

    /** @param array<string,mixed> $locator @return array<string,mixed> */
    private static function tableRow(array $locator): array
    {
        // Worksheet name is part of the actual XLSX position: row alone is ambiguous across sheets.
        $position = ['row' => self::integer($locator, 'row', 1)];
        if (array_key_exists('sheet', $locator)) {
            $sheet = $locator['sheet'];
            if (!is_string($sheet) || trim($sheet) === '' || !mb_check_encoding($sheet, 'UTF-8')
                || preg_match('/[\x00-\x1f\x7f]/u', $sheet) !== 0) {
                self::invalid();
            }
            $position['sheet'] = $sheet;
        }
        if (array_key_exists('table', $locator)) {
            $position['table'] = self::integer($locator, 'table', 1);
        }
        return $position;
    }

    /** @param array<string,mixed> $locator @return array<string,mixed> */
    private static function pageRegion(array $locator): array
    {
        $position = ['page' => self::integer($locator, 'page', 1)];
        foreach (['x', 'y', 'width', 'height'] as $field) {
            $position[$field] = self::integer($locator, $field, 0);
        }
        return $position;
    }

    /** @param array<string,mixed> $locator @return array<string,mixed> */
    private static function audioRange(array $locator): array
    {
        $start = self::integer($locator, 'start_ms', 0);
        $end = self::integer($locator, 'end_ms', 1);
        $position = ['type' => $locator['type'] ?? null, 'start_ms' => $start, 'end_ms' => $end];
        if (array_key_exists('granularity', $locator)) {
            $position['granularity'] = $locator['granularity'];
        }
        try {
            return AudioTimeRangeLocator::normalize($position, $start, $end);
        } catch (ApiProblem) {
            self::invalid();
        }
    }

    /** @param array<string,mixed> $locator */
    private static function integer(array $locator, string $field, int $minimum): int
    {
        $value = $locator[$field] ?? null;
        if (!is_int($value) || $value < $minimum) {
            self::invalid();
        }
        return $value;
    }

    private static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'A source block has no valid public source position');
    }
}
