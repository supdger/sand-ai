<?php

declare(strict_types=1);

namespace app\Application\Usage;

use app\Api\Support\ApiProblem;
use app\Application\Budget\BudgetDecimal;
use InvalidArgumentException;

/** Operator review of a real refund attachment; never a provider-authentication claim. */
final class UsageRefundPolicy
{
    public const INVALID = 'SAND_AI_USAGE_ADJUSTMENT_INVALID';

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function normalize(array $input): array
    {
        $this->only($input, ['environment_id', 'usage_event_id', 'evidence_id', 'evidence_locator',
            'provider_refund_reference', 'refund_amount', 'currency', 'provider_refund_confirmed',
            'idempotency_key', 'note']);
        $data = [];
        foreach (['environment_id', 'usage_event_id', 'evidence_id'] as $field) {
            $data[$field] = $this->id($input[$field] ?? null, $field);
        }
        foreach (['evidence_locator' => 256, 'provider_refund_reference' => 128,
            'idempotency_key' => 128, 'note' => 2000] as $field => $maximum) {
            $value = $input[$field] ?? null;
            if (!is_string($value) || preg_match('//u', $value) !== 1
                || preg_match('/[\p{Cc}]/u', $value) === 1
                || trim($value) === '' || mb_strlen($value, 'UTF-8') > $maximum) {
                throw new ApiProblem(self::INVALID, '退款凭证定位、引用、请求键和审核说明须为有效文本');
            }
            $data[$field] = trim($value);
        }
        try {
            $amount = BudgetDecimal::normalize($input['refund_amount'] ?? null, false);
            if (BudgetDecimal::compare($amount, '0.000000') <= 0) {
                throw new InvalidArgumentException('Positive refund amount required');
            }
        } catch (InvalidArgumentException) {
            throw new ApiProblem(self::INVALID, '退款金额须为大于零、最多六位小数的精确金额字符串');
        }
        $data['refund_amount'] = $amount;
        $data['currency'] = (new PricingValuePolicy())->currency($input['currency'] ?? null, self::INVALID);
        if (($input['provider_refund_confirmed'] ?? null) !== true) {
            throw new ApiProblem(self::INVALID, '请确认已人工核对实际退款凭证、原调用、币种和金额');
        }
        $data['provider_refund_confirmed'] = true;
        return $data;
    }

    /** @param array<string,mixed> $input @return array{environment_id:int,usage_event_id:int,page:int,limit:int} */
    public function listQuery(array $input): array
    {
        $this->only($input, ['environment_id', 'usage_event_id', 'page', 'limit']);
        $limit = $this->id($input['limit'] ?? 20, 'limit');
        if ($limit > 100) {
            throw new ApiProblem(self::INVALID, '每页最多读取 100 条退款调整');
        }
        return ['environment_id' => $this->id($input['environment_id'] ?? null, 'environment_id'),
            'usage_event_id' => $this->id($input['usage_event_id'] ?? null, 'usage_event_id'),
            'page' => $this->id($input['page'] ?? 1, 'page'), 'limit' => $limit];
    }

    /** @param array<string,mixed> $input @return array{environment_id:int,id:int} */
    public function readQuery(array $input): array
    {
        $this->only($input, ['environment_id', 'id']);
        return ['environment_id' => $this->id($input['environment_id'] ?? null, 'environment_id'),
            'id' => $this->id($input['id'] ?? null, 'id')];
    }

    /** @param array<string,mixed> $normalized */
    public function fingerprint(array $normalized): string
    {
        unset($normalized['idempotency_key']);
        ksort($normalized, SORT_STRING);
        return hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function id(mixed $value, string $field): int
    {
        return (new PricingValuePolicy())->positiveId($value, $field, self::INVALID);
    }

    /** @param array<string,mixed> $input @param list<string> $allowed */
    private function only(array $input, array $allowed): void
    {
        if (array_diff(array_keys($input), $allowed) !== []) {
            throw new ApiProblem(self::INVALID, '退款调整请求包含未支持字段');
        }
    }
}
