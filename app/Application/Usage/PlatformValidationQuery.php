<?php

declare(strict_types=1);

namespace app\Application\Usage;

use app\Api\Support\ApiProblem;
use app\Application\Budget\BudgetDecimal;
use think\facade\Db;

/** Explicit platform scope; never joins or accepts a workload selector. */
final class PlatformValidationQuery
{
    private const STATE = "CASE WHEN r.id IS NULL THEN 'pending' WHEN r.outcome_state='response_known' THEN 'recorded' ELSE r.outcome_state END";
    private const JOIN = ' FROM sand_ai_admin_validation_attempt a LEFT JOIN sand_ai_admin_validation_receipt r ON r.attempt_id=a.id LEFT JOIN sand_ai_usage_event u ON u.admin_validation_attempt_id=a.id';

    public function index(array $input): array
    {
        [$filters, $where, $params] = $this->filters($input, true);
        $db = Db::connect('pgsql');
        $count = $db->query('SELECT count(*) AS total' . self::JOIN . $where, $params);
        $total = (int) $count[0]['total'];
        $limit = $filters['limit'];
        $page = $filters['page'];
        $rows = $db->query('SELECT a.id,a.target_kind,a.admin_actor_id,a.provider_id,a.model_id,a.deployment_id,a.deployment_revision,a.capability_code,a.service_action,a.call_started_at,a.create_time,u.input_tokens,u.output_tokens,u.total_tokens,u.usage_state,u.id AS usage_event_id' . self::JOIN . $where . ' ORDER BY a.call_started_at DESC,a.id DESC LIMIT ? OFFSET ?', [...$params, $limit, ($page - 1) * $limit]);
        return ['scope_kind' => 'platform_admin', 'total' => $total, 'per_page' => $limit, 'current_page' => $page, 'last_page' => max(1, (int) ceil($total / $limit)),
            'data' => array_map(function (array $row): array {
                foreach (['id','admin_actor_id','provider_id','model_id','deployment_id','deployment_revision'] as $field) $row[$field] = $row[$field] === null ? null : (int) $row[$field];
                foreach (['input_tokens','output_tokens','total_tokens'] as $field) $row[$field] = $row['usage_state'] === 'measured' ? (int) $row[$field] : null;
                unset($row['usage_state'], $row['usage_event_id']);
                return $row + ['source_kind' => 'admin_verify', 'platform_usage' => (new AdminValidationUsage())->projection($row['id'])];
            }, $rows)];
    }

    public function summary(array $input): array
    {
        [$filters, $where, $params] = $this->filters($input, false);
        $db = Db::connect('pgsql');
        $states = ['pending' => 0, 'not_dispatched' => 0, 'ambiguous' => 0, 'recorded' => 0];
        foreach ($db->query('SELECT ' . self::STATE . ' AS state,count(*) AS count' . self::JOIN . $where . ' GROUP BY ' . self::STATE, $params) as $row) $states[$row['state']] = (int) $row['count'];
        $rows = $db->query("SELECT NULL::text AS dimension_value,u.currency,count(*) AS total_events,
            count(*) FILTER(WHERE u.usage_state='measured') AS measured_events,
            count(*) FILTER(WHERE u.pricing_state='priced') AS priced_events,
            count(*) FILTER(WHERE u.usage_state='unmeasured') AS unmeasured_events,
            count(*) FILTER(WHERE u.pricing_state='unpriced') AS unpriced_events,
            sum(u.metered_amount) AS metered_amount,
            count(*) FILTER(WHERE r.error_code IS NOT NULL) AS failed_events,
            count(*) FILTER(WHERE r.error_code IS NOT NULL AND u.metered_amount IS NULL) AS failed_unknown_amount_events,
            sum(u.metered_amount) FILTER(WHERE r.error_code IS NOT NULL) AS failed_metered_amount,
            0 AS business_failed_events,0 AS business_failed_unknown_amount_events,NULL::numeric AS business_failed_metered_amount,
            0 AS reconciled_events,0 AS discrepancy_events,count(*) AS unreconciled_events,
            NULL::numeric AS reconciled_amount,NULL::numeric AS difference_amount" . self::JOIN . $where . ' AND u.id IS NOT NULL GROUP BY u.currency', $params);
        $overall = (new UsageSummaryReducer())->rows($rows, 'overall');
        foreach ($overall as &$row) foreach (['known_metered_amount','metered_amount','failed_known_amount','failed_metered_amount','business_failed_known_amount','business_failed_metered_amount','reconciled_amount','difference_amount'] as $field) {
            if ($row[$field] !== null) $row[$field] = BudgetDecimal::normalize((string) $row[$field], false);
        }
        unset($row);
        return ['scope_kind' => 'platform_admin', 'from' => $filters['from'], 'to' => $filters['to'], 'total_attempts' => array_sum($states), 'total_events' => array_sum(array_column($overall, 'total_events')), 'states' => $states, 'overall' => $overall];
    }

    private function filters(array $input, bool $pagination): array
    {
        $allowed = ['from','to','provider_id','model_id','deployment_id','admin_actor_id','capability_code','state'];
        if ($pagination) $allowed = [...$allowed, 'page', 'limit'];
        if (array_diff(array_keys($input), $allowed) !== []) $this->invalid('Unknown platform validation query field');
        $from = $this->utc($input['from'] ?? gmdate('Y-m-d H:i:s', time() - 86400));
        $to = $this->utc($input['to'] ?? gmdate('Y-m-d H:i:s'));
        $difference = strtotime($to . ' UTC') - strtotime($from . ' UTC');
        if ($difference <= 0 || $difference > 366 * 86400) $this->invalid('Platform time range must be positive and at most 366 days');
        $filters = ['from' => $from, 'to' => $to];
        $where = ' WHERE a.call_started_at>=CAST(? AS timestamp) AND a.call_started_at<CAST(? AS timestamp)';
        $params = [$from, $to];
        foreach (['provider_id','model_id','deployment_id','admin_actor_id'] as $field) {
            if (($input[$field] ?? '') === '' || ($input[$field] ?? null) === null) continue;
            $where .= ' AND a.' . $field . '=?'; $params[] = $this->positive($input[$field], PHP_INT_MAX);
        }
        foreach (['capability_code' => ['chat','embedding','audio_transcription','audio_speech'], 'state' => ['pending','not_dispatched','ambiguous','recorded']] as $field => $values) {
            if (($input[$field] ?? '') === '' || ($input[$field] ?? null) === null) continue;
            if (!is_string($input[$field]) || !in_array($input[$field], $values, true)) $this->invalid('Platform validation state/capability is invalid');
            $where .= ' AND ' . ($field === 'state' ? '(' . self::STATE . ')' : 'a.capability_code') . '=?'; $params[] = $input[$field];
        }
        if ($pagination) $filters += ['page' => $this->positive($input['page'] ?? 1, 1000000), 'limit' => $this->positive($input['limit'] ?? 10, 100)];
        return [$filters, $where, $params];
    }

    private function positive(mixed $value, int $maximum): int
    {
        if ((!is_int($value) && !is_string($value)) || ($id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $maximum]])) === false) $this->invalid('Expected a positive bounded integer');
        return $id;
    }

    private function utc(mixed $value): string
    {
        if (!is_string($value)) $this->invalid('Expected UTC YYYY-MM-DD HH:mm:ss');
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));
        if ($parsed === false || $parsed->format('Y-m-d H:i:s') !== $value) $this->invalid('Expected valid UTC YYYY-MM-DD HH:mm:ss');
        return $value;
    }

    private function invalid(string $message): never
    {
        throw new ApiProblem('SAND_AI_VALIDATION_ERROR', $message);
    }
}
