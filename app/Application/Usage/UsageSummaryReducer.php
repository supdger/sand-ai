<?php

declare(strict_types=1);

namespace app\Application\Usage;

/** Formats database aggregate rows without converting monetary values to float. */
final class UsageSummaryReducer
{
    /** @param list<array<string, mixed>> $rows @return list<array<string, mixed>> */
    public function rows(array $rows, string $dimension): array
    {
        return array_map(static function (array $row) use ($dimension): array {
            $failedEvents = (int) ($row['failed_events'] ?? 0);
            $failedUnknownAmountEvents = (int) ($row['failed_unknown_amount_events'] ?? 0);
            $failedKnownAmount = ($row['failed_metered_amount'] ?? null) === null || $row['failed_metered_amount'] === ''
                ? null : (string) $row['failed_metered_amount'];
            $businessFailedEvents = (int) ($row['business_failed_events'] ?? 0);
            $businessFailedUnknown = (int) ($row['business_failed_unknown_amount_events'] ?? 0);
            $businessFailedKnown = ($row['business_failed_metered_amount'] ?? null) === null || $row['business_failed_metered_amount'] === ''
                ? null : (string) $row['business_failed_metered_amount'];
            $unmeasuredEvents = (int) ($row['unmeasured_events'] ?? 0);
            $unpricedEvents = (int) ($row['unpriced_events'] ?? 0);
            $knownMeteredAmount = ($row['metered_amount'] ?? null) === null || $row['metered_amount'] === ''
                ? null : (string) $row['metered_amount'];
            return [
                'dimension' => $dimension,
                'value' => $row['dimension_value'] ?? null,
                'currency' => $row['currency'] ?? null,
                'total_events' => (int) ($row['total_events'] ?? 0),
                'measured_events' => (int) ($row['measured_events'] ?? 0),
                'priced_events' => (int) ($row['priced_events'] ?? 0),
                'unmeasured_events' => $unmeasuredEvents,
                'unpriced_events' => $unpricedEvents,
                'known_metered_amount' => $knownMeteredAmount,
                'metered_amount' => $unmeasuredEvents === 0 && $unpricedEvents === 0
                    ? $knownMeteredAmount : null,
                'failed_events' => $failedEvents,
                'failed_unknown_amount_events' => $failedUnknownAmountEvents,
                'failed_known_amount' => $failedKnownAmount ?? ($failedEvents === 0 ? '0' : null),
                'failed_metered_amount' => $failedUnknownAmountEvents === 0
                    ? ($failedKnownAmount ?? '0') : null,
                'business_failed_events' => $businessFailedEvents,
                'business_failed_unknown_amount_events' => $businessFailedUnknown,
                'business_failed_known_amount' => $businessFailedKnown ?? ($businessFailedEvents === 0 ? '0' : null),
                'business_failed_metered_amount' => $businessFailedUnknown === 0
                    ? ($businessFailedKnown ?? '0') : null,
                'reconciled_events' => (int) ($row['reconciled_events'] ?? 0),
                'discrepancy_events' => (int) ($row['discrepancy_events'] ?? 0),
                'unreconciled_events' => (int) ($row['unreconciled_events'] ?? 0),
                'reconciled_amount' => $row['reconciled_amount'] === null || $row['reconciled_amount'] === '' ? null : (string) $row['reconciled_amount'],
                'difference_amount' => $row['difference_amount'] === null || $row['difference_amount'] === '' ? null : (string) $row['difference_amount'],
            ];
        }, $this->ordered($rows));
    }

    /** @param list<array<string, mixed>> $rows @return list<array<string, mixed>> */
    private function ordered(array $rows): array
    {
        usort($rows, static function (array $left, array $right): int {
            // Keep currencies separate before comparing amounts; unknown currency is last.
            $leftCurrency = $left['currency'] ?? null;
            $rightCurrency = $right['currency'] ?? null;
            $currencyOrder = $leftCurrency === null
                ? ($rightCurrency === null ? 0 : 1)
                : ($rightCurrency === null ? -1 : strcmp((string) $leftCurrency, (string) $rightCurrency));
            if ($currencyOrder !== 0) return $currencyOrder;
            $amountOrder = self::compareNullableAmount($left['metered_amount'] ?? null, $right['metered_amount'] ?? null);
            if ($amountOrder !== 0) {
                return -$amountOrder;
            }
            $dimensionOrder = strcmp((string) ($left['dimension_value'] ?? ''), (string) ($right['dimension_value'] ?? ''));
            if ($dimensionOrder !== 0) {
                return $dimensionOrder;
            }

            return strcmp((string) ($left['currency'] ?? ''), (string) ($right['currency'] ?? ''));
        });

        return $rows;
    }

    private static function compareNullableAmount(mixed $left, mixed $right): int
    {
        if ($left === null || $left === '') {
            return $right === null || $right === '' ? 0 : -1;
        }
        if ($right === null || $right === '') {
            return 1;
        }

        [$leftWhole, $leftFraction] = array_pad(explode('.', (string) $left, 2), 2, '');
        [$rightWhole, $rightFraction] = array_pad(explode('.', (string) $right, 2), 2, '');
        $wholeOrder = strlen(ltrim($leftWhole, '0')) <=> strlen(ltrim($rightWhole, '0'));
        if ($wholeOrder !== 0) {
            return $wholeOrder;
        }
        $wholeOrder = strcmp(ltrim($leftWhole, '0') ?: '0', ltrim($rightWhole, '0') ?: '0');
        if ($wholeOrder !== 0) {
            return $wholeOrder;
        }

        $precision = max(strlen($leftFraction), strlen($rightFraction));

        return strcmp(str_pad($leftFraction, $precision, '0'), str_pad($rightFraction, $precision, '0'));
    }
}
