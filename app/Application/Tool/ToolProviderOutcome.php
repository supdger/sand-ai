<?php

declare(strict_types=1);

namespace app\Application\Tool;

use app\Api\Support\ApiProblem;

/** Normalized, non-raw external result retained in the encrypted receipt. */
final readonly class ToolProviderOutcome
{
    /** @param array<string,mixed>|\stdClass|null $result @param list<array<string,mixed>> $citations */
    public function __construct(
        public string $state,
        public array|\stdClass|null $result,
        public array $citations,
        public ?string $errorCode,
        public ?int $httpStatus,
        public int $latencyMs,
    ) {
        if (!in_array($state, ['succeeded', 'failed', 'ambiguous'], true) || $latencyMs < 0
            || ($state === 'succeeded' && ($result === null || $errorCode !== null))
            || ($result !== null && !((is_array($result) && !array_is_list($result))
                || ($result instanceof \stdClass && get_object_vars($result) === [])))
            || ($state !== 'succeeded' && ($result !== null || !is_string($errorCode) || $errorCode === ''))) {
            throw new ApiProblem('SAND_AI_TOOL_RECEIPT_INVALID', 'External tool outcome is invalid');
        }
    }

    public function encode(): string
    {
        return ToolFingerprint::canonicalJson([
            'citations' => $this->citations,
            'error_code' => $this->errorCode,
            'http_status' => $this->httpStatus,
            'latency_ms' => $this->latencyMs,
            'result' => $this->result,
            'state' => $this->state,
        ]);
    }

    public static function decode(string $encoded): self
    {
        try {
            $value = StrictJsonObjectDecoder::decodeObject($encoded);
        } catch (\Throwable) {
            throw new ApiProblem('SAND_AI_TOOL_RECEIPT_INVALID', 'External tool receipt is invalid');
        }
        if (!is_array($value) || array_keys($value) !== ['citations', 'error_code', 'http_status', 'latency_ms', 'result', 'state']
            || !is_array($value['citations']) || !array_is_list($value['citations']) || !is_int($value['latency_ms'])
            || (!is_null($value['http_status']) && !is_int($value['http_status']))) {
            throw new ApiProblem('SAND_AI_TOOL_RECEIPT_INVALID', 'External tool receipt is invalid');
        }
        return new self((string) $value['state'], (is_array($value['result']) || $value['result'] instanceof \stdClass) ? $value['result'] : null, $value['citations'], is_string($value['error_code']) ? $value['error_code'] : null, $value['http_status'], $value['latency_ms']);
    }
}
