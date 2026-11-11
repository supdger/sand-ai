<?php

declare(strict_types=1);

namespace app\Application\Usage;

use app\Api\Support\ApiProblem;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Calendar-bucket normalization for the admin usage period endpoints. Month
 * and day labels are host-local; persisted usage timestamps stay UTC.
 */
final class UsagePeriodPolicy
{
    private const MAX_DAYS = 93;
    private const MAX_MONTHS = 12;
    private const FALLBACK_TIMEZONE = 'Asia/Shanghai';
    private const UTC = 'UTC';

    /** @var list<string> */
    private const FILTER_FIELDS = ['organization_id', 'environment_id', 'application_id', 'provider_id', 'model_id'];
    /** @var list<string> */
    private const MONTHLY_FIELDS = ['group_by', 'month', 'month_from', 'month_to'];
    /** @var list<string> */
    private const DAILY_FIELDS = ['month', 'date_from', 'date_to'];

    public function __construct(
        private readonly PricingValuePolicy $values = new PricingValuePolicy(),
        private readonly ?string $bucketTimezone = null,
    ) {
    }

    /** Host business timezone for calendar labels; falls back when unconfigured. */
    public function timezone(): string
    {
        if ($this->bucketTimezone !== null) {
            return $this->bucketTimezone;
        }
        $zone = function_exists('config') ? config('app.default_timezone') : null;

        return is_string($zone) && in_array($zone, timezone_identifiers_list(DateTimeZone::ALL_WITH_BC), true)
            ? $zone
            : self::FALLBACK_TIMEZONE;
    }

    /**
     * @param array<string,mixed> $query
     * @return array{filters:array<string,int|string|null>,group_by:string,timezone:string,month_from:string,month_to:string}
     */
    public function monthly(array $query, ?DateTimeImmutable $now = null): array
    {
        $this->assertKeys($query, [...self::FILTER_FIELDS, ...self::MONTHLY_FIELDS]);
        $timezone = $this->timezone();
        $zone = new DateTimeZone($timezone);
        $now ??= new DateTimeImmutable('now', $zone);
        [$monthFrom, $monthTo] = $this->monthRange($query, $now);
        $fromLocal = new DateTimeImmutable($monthFrom . '-01 00:00:00', $zone);
        $toLocal = (new DateTimeImmutable($monthTo . '-01 00:00:00', $zone))->modify('first day of next month');
        $utc = new DateTimeZone(self::UTC);

        return [
            'filters' => $this->filters($query) + [
                'from_time' => $fromLocal->setTimezone($utc)->format('Y-m-d H:i:s'),
                'to_time' => $toLocal->setTimezone($utc)->format('Y-m-d H:i:s'),
            ],
            'group_by' => $this->groupBy($query['group_by'] ?? null),
            'timezone' => $timezone,
            'month_from' => $monthFrom,
            'month_to' => $monthTo,
        ];
    }

    /**
     * @param array<string,mixed> $query
     * @return array{filters:array<string,int|string|null>,timezone:string,date_from:string,date_to:string}
     */
    public function daily(array $query, ?DateTimeImmutable $now = null): array
    {
        $this->assertKeys($query, [...self::FILTER_FIELDS, ...self::DAILY_FIELDS]);
        $timezone = $this->timezone();
        $zone = new DateTimeZone($timezone);
        $now ??= new DateTimeImmutable('now', $zone);
        [$dateFrom, $dateTo] = $this->dateRange($query, $now);
        $fromLocal = new DateTimeImmutable($dateFrom . ' 00:00:00', $zone);
        $toLocal = (new DateTimeImmutable($dateTo . ' 00:00:00', $zone))->modify('+1 day');
        $utc = new DateTimeZone(self::UTC);

        return [
            'filters' => $this->filters($query) + [
                'from_time' => $fromLocal->setTimezone($utc)->format('Y-m-d H:i:s'),
                'to_time' => $toLocal->setTimezone($utc)->format('Y-m-d H:i:s'),
            ],
            'timezone' => $timezone,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ];
    }

    /** @param array<string,mixed> $query @param list<string> $allowed */
    private function assertKeys(array $query, array $allowed): void
    {
        if (array_diff(array_keys($query), $allowed) !== []) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Usage period query contains unknown fields');
        }
    }

    /** @param array<string,mixed> $query @return array{0:string,1:string} */
    private function monthRange(array $query, DateTimeImmutable $now): array
    {
        $month = $this->optionalMonth($query['month'] ?? null);
        $from = $this->optionalMonth($query['month_from'] ?? null);
        $to = $this->optionalMonth($query['month_to'] ?? null);
        if ($month !== null) {
            if ($from !== null || $to !== null) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'month cannot be combined with month_from or month_to');
            }

            return [$month, $month];
        }
        if ($from === null && $to === null) {
            $current = $now->format('Y-m');

            return [$current, $current];
        }
        if ($from === null || $to === null) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'month_from and month_to must be provided together');
        }
        if ($from > $to) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'month_from must not be later than month_to');
        }
        [$yearFrom, $monthFrom] = array_map('intval', explode('-', $from));
        [$yearTo, $monthTo] = array_map('intval', explode('-', $to));
        if (($yearTo - $yearFrom) * 12 + ($monthTo - $monthFrom) + 1 > self::MAX_MONTHS) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'The requested month range must be positive and no longer than 12 months');
        }

        return [$from, $to];
    }

    /** @param array<string,mixed> $query @return array{0:string,1:string} */
    private function dateRange(array $query, DateTimeImmutable $now): array
    {
        $month = $this->optionalMonth($query['month'] ?? null);
        $from = $this->optionalDate($query['date_from'] ?? null);
        $to = $this->optionalDate($query['date_to'] ?? null);
        if ($month !== null) {
            if ($from !== null || $to !== null) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'month cannot be combined with date_from or date_to');
            }
            $first = new DateTimeImmutable($month . '-01', $now->getTimezone());

            return [$first->format('Y-m-d'), $first->modify('last day of this month')->format('Y-m-d')];
        }
        if ($from === null && $to === null) {
            $first = new DateTimeImmutable($now->format('Y-m') . '-01', $now->getTimezone());

            return [$first->format('Y-m-d'), $first->modify('last day of this month')->format('Y-m-d')];
        }
        if ($from === null || $to === null) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'date_from and date_to must be provided together');
        }
        if ($from > $to) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'date_from must not be later than date_to');
        }
        $fromDate = new DateTimeImmutable($from . ' 00:00:00', new DateTimeZone(self::UTC));
        $toExclusive = (new DateTimeImmutable($to . ' 00:00:00', new DateTimeZone(self::UTC)))->modify('+1 day');
        if ($fromDate->diff($toExclusive)->days > self::MAX_DAYS) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'The requested date range must be positive and no longer than 93 days');
        }

        return [$from, $to];
    }

    private function optionalMonth(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || preg_match('/\A\d{4}-(0[1-9]|1[0-2])\z/D', $value) !== 1) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Month filters must use YYYY-MM');
        }

        return $value;
    }

    private function optionalDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Date filters must use YYYY-MM-DD');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone(self::UTC));
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || $date->format('Y-m-d') !== $value
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Date filters must use YYYY-MM-DD');
        }

        return $value;
    }

    private function groupBy(mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'application';
        }
        if ($value === 'application' || $value === 'model') {
            return $value;
        }
        throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'group_by must be application or model');
    }

    /** @param array<string,mixed> $query @return array<string,int|string|null> */
    private function filters(array $query): array
    {
        return [
            'organization_id' => $this->optionalId($query['organization_id'] ?? null, 'organization_id'),
            'environment_id' => $this->optionalId($query['environment_id'] ?? null, 'environment_id'),
            'application_id' => $this->optionalId($query['application_id'] ?? null, 'application_id'),
            'provider_id' => $this->optionalId($query['provider_id'] ?? null, 'provider_id'),
            'model_id' => $this->optionalId($query['model_id'] ?? null, 'model_id'),
        ];
    }

    private function optionalId(mixed $value, string $field): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->values->positiveId($value, $field);
    }
}
