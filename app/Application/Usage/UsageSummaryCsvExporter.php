<?php

declare(strict_types=1);

namespace app\Application\Usage;

use RuntimeException;

/** Exports the bounded admin summary DTO without exposing source snapshots. */
final class UsageSummaryCsvExporter
{
    private const GROUPS = [
        'overall', 'by_service', 'by_application', 'by_service_action', 'by_capability',
        'by_skill', 'by_skill_version', 'by_skill_run', 'by_provider', 'by_model',
        'by_organization', 'by_environment', 'by_source_kind', 'by_workflow_run', 'by_composition_run', 'by_composition_node',
        'by_agent_run', 'by_agent_definition', 'by_agent_version', 'by_channel_binding', 'by_delivery_platform',
        'by_knowledge_base', 'by_knowledge_base_version', 'by_knowledge_query', 'by_knowledge_index',
    ];
    private const FIELDS = [
        'dimension', 'value', 'currency',
        'total_events', 'measured_events', 'priced_events', 'unmeasured_events', 'unpriced_events',
        'known_metered_amount', 'metered_amount',
        'failed_events', 'failed_unknown_amount_events', 'failed_known_amount', 'failed_metered_amount',
        'reconciled_events', 'discrepancy_events', 'unreconciled_events',
        'reconciled_amount', 'difference_amount',
    ];
    private const BUSINESS_FIELDS = [
        'business_failed_events', 'business_failed_unknown_amount_events',
        'business_failed_known_amount', 'business_failed_metered_amount',
    ];
    private const ESTIMATE_FIELDS = [
        'estimate_basis', 'estimated_budget_upper_bound', 'estimated_currency',
        'known_estimate_events', 'unknown_estimate_events', 'held_estimate_events',
        'known_estimated_budget_upper_bounds',
    ];
    private const COUNTS = [
        'total_events', 'measured_events', 'priced_events', 'unmeasured_events', 'unpriced_events',
        'failed_events', 'failed_unknown_amount_events',
        'business_failed_events', 'business_failed_unknown_amount_events',
        'reconciled_events', 'discrepancy_events', 'unreconciled_events',
    ];
    private const AMOUNTS = [
        'known_metered_amount', 'metered_amount', 'failed_known_amount',
        'failed_metered_amount', 'reconciled_amount', 'difference_amount',
        'business_failed_known_amount', 'business_failed_metered_amount',
    ];

    /** @param array<string, mixed> $summary */
    public function export(array $summary): string
    {
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) {
            throw new RuntimeException('Cannot open usage summary CSV stream');
        }
        try {
            $this->write($stream, array_merge(['group', 'row_kind'], self::FIELDS, ['truncated', 'ambiguous_count'], self::BUSINESS_FIELDS, self::ESTIMATE_FIELDS));
            $ambiguousCount = ($summary['ambiguous']['count'] ?? null) === null ? '' : (string) (int) $summary['ambiguous']['count'];
            foreach (self::GROUPS as $group) {
                $aggregate = $summary[$group];
                $this->write($stream, array_merge(
                    [$group, 'metadata'],
                    array_fill(0, count(self::FIELDS), ''),
                    [($aggregate['truncated'] ?? false) ? 'true' : 'false', $ambiguousCount],
                    array_fill(0, count(self::BUSINESS_FIELDS), ''),
                    array_fill(0, count(self::ESTIMATE_FIELDS), ''),
                ));
                foreach ($aggregate['rows'] as $row) {
                    $cells = [$group, 'data'];
                    foreach (self::FIELDS as $field) {
                        $value = $row[$field] ?? null;
                        if ($value === null) {
                            $cells[] = '';
                        } elseif (in_array($field, self::COUNTS, true)) {
                            $cells[] = (string) (int) $value;
                        } elseif (in_array($field, self::AMOUNTS, true) && preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/', (string) $value) === 1) {
                            $cells[] = (string) $value;
                        } else {
                            $cells[] = $this->safeText((string) $value);
                        }
                    }
                    $businessCells = [];
                    foreach (self::BUSINESS_FIELDS as $field) {
                        $value = $row[$field] ?? null;
                        $businessCells[] = $value === null ? '' : (string) $value;
                    }
                    $this->write($stream, array_merge($cells, ['', ''], $businessCells, $this->estimateCells($row)));
                }
            }
            rewind($stream);
            $csv = stream_get_contents($stream);
            if ($csv === false) {
                throw new RuntimeException('Cannot read usage summary CSV stream');
            }

            return $csv;
        } finally {
            fclose($stream);
        }
    }

    private function safeText(string $value): string
    {
        return preg_match('/^[\x00-\x20]*[=+\-@]/', $value) === 1 ? "'" . $value : $value;
    }

    /** Only original frozen budget bounds, never predicted cash charges.
     *  @param array<string,mixed> $row @return list<string>
     */
    private function estimateCells(array $row): array
    {
        $estimate = $row['estimate'] ?? null;
        if ($estimate === null) return array_fill(0, count(self::ESTIMATE_FIELDS), '');
        return [
            $this->safeText($estimate['basis']),
            $estimate['amount'] ?? '', $this->safeText($estimate['currency'] ?? ''),
            (string) $estimate['known_events'], (string) $estimate['unknown_events'],
            (string) $estimate['held_events'],
            $this->safeText(json_encode($estimate['known_amounts'], JSON_THROW_ON_ERROR)),
        ];
    }

    /** @param resource $stream @param list<string> $cells */
    private function write($stream, array $cells): void
    {
        if (fputcsv($stream, $cells, ',', '"', '') === false) {
            throw new RuntimeException('Cannot write usage summary CSV');
        }
    }
}
