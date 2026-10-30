<?php

declare(strict_types=1);

namespace app\Application\Onboarding;

use app\Api\Support\ApiProblem;

/** Builds and validates the non-sensitive validation evidence projection. */
final class DeploymentValidationEvidence
{
    public const VERSION = 'deployment_validation@1';
    public const TRACKED_VERSION = 'deployment_validation@2';
    public const RERANK_VERSION = 'deployment_validation@3';
    /** @var list<string> */
    private const FIELDS = [
        'version', 'outcome', 'deployment_id', 'deployment_revision', 'validated_time', 'validated_by',
        'usage_measured', 'input_tokens', 'output_tokens', 'total_tokens', 'cost_tracking_state', 'evidence_sha256',
    ];

    /** @return array<string,mixed> */
    public function create(int $deploymentId, int $revision, string $validatedBy, array $verification, ?array $platformUsage = null): array
    {
        $measured = $verification['usage_measured'] ?? null;
        $input = $verification['input_tokens'] ?? null;
        $output = $verification['output_tokens'] ?? null;
        $rerank = array_key_exists('rerank_meter_code', $verification);
        if ($rerank) {
            if (!$this->validRerankUsage($verification) || $platformUsage === null) {
                throw new ApiProblem('SAND_AI_PROVIDER_RESPONSE_INVALID', 'Professional rerank verification usage is invalid');
            }
        } elseif (!is_bool($measured)
            || ($measured && (!is_int($input) || !is_int($output)
                || $input < 0 || $output < 0 || $input > PHP_INT_MAX - $output))
            || (!$measured && ($input !== null || $output !== null))) {
            throw new ApiProblem('SAND_AI_PROVIDER_RESPONSE_INVALID', 'Deployment verification usage is invalid');
        }
        $evidence = [
            'version' => self::VERSION,
            'outcome' => 'passed',
            'deployment_id' => $deploymentId,
            'deployment_revision' => $revision,
            'validated_time' => date('Y-m-d H:i:s'),
            'validated_by' => $validatedBy,
            'usage_measured' => $measured,
            'input_tokens' => $input,
            'output_tokens' => $output,
            'total_tokens' => !$rerank && $measured ? $input + $output : null,
            'cost_tracking_state' => 'untracked',
        ];
        if ($platformUsage !== null) {
            if (($platformUsage['state'] ?? null) !== 'recorded' || !is_int($platformUsage['attempt_id'] ?? null) || !is_int($platformUsage['usage_event_id'] ?? null)) throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Tracked deployment validation requires its immutable usage event');
            $evidence['version'] = self::TRACKED_VERSION;
            $evidence['cost_tracking_state'] = 'recorded';
            $evidence['platform_attempt_id'] = $platformUsage['attempt_id'];
            $evidence['usage_event_id'] = $platformUsage['usage_event_id'];
        }
        if ($rerank) {
            $evidence['version'] = self::RERANK_VERSION;
            $evidence['total_tokens'] = null;
            $evidence['rerank_meter_code'] = $verification['rerank_meter_code'];
            $evidence['rerank_quantity'] = $verification['rerank_quantity'];
        }
        $evidence['evidence_sha256'] = hash('sha256', $this->encode($evidence));
        return $evidence;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed>|null */
    public function fromPayload(array $payload, int $deploymentId, int $revision): ?array
    {
        $evidence = $payload['deployment_validation'] ?? null;
        $rerank = is_array($evidence) && ($evidence['version'] ?? null) === self::RERANK_VERSION;
        $tracked = is_array($evidence) && in_array($evidence['version'] ?? null, [self::TRACKED_VERSION, self::RERANK_VERSION], true);
        $fields = $tracked ? [...self::FIELDS, 'platform_attempt_id', 'usage_event_id'] : self::FIELDS;
        if ($rerank) $fields = [...$fields, 'rerank_meter_code', 'rerank_quantity'];
        if (!is_array($evidence) || array_diff(array_keys($evidence), $fields) !== [] || !in_array($evidence['version'] ?? null, [self::VERSION, self::TRACKED_VERSION, self::RERANK_VERSION], true)
            || ($evidence['outcome'] ?? null) !== 'passed'
            || ($evidence['deployment_id'] ?? null) !== $deploymentId
            || ($evidence['deployment_revision'] ?? null) !== $revision
            || !$this->trustedTime($evidence['validated_time'] ?? null)
            || !is_string($evidence['validated_by'] ?? null) || trim($evidence['validated_by']) === ''
            || !is_bool($evidence['usage_measured'] ?? null)
            || !is_string($evidence['evidence_sha256'] ?? null)
            || ($evidence['cost_tracking_state'] ?? null) !== ($tracked ? 'recorded' : 'untracked')
            || !$this->validUsage($evidence)) {
            return null;
        }
        if ($tracked && !(new \app\Application\Usage\AdminValidationUsage())->matchesEvidence($evidence, $deploymentId, $revision)) return null;
        $storedHash = $evidence['evidence_sha256'];
        unset($evidence['evidence_sha256']);
        if (!preg_match('/\A[a-f0-9]{64}\z/', $storedHash) || !hash_equals($storedHash, hash('sha256', $this->encode($evidence)))) {
            return null;
        }
        $evidence['evidence_sha256'] = $storedHash;
        return $evidence;
    }

    /** @param array<string,mixed> $evidence */
    private function validUsage(array $evidence): bool
    {
        if (($evidence['version'] ?? null) === self::RERANK_VERSION) {
            return $this->validRerankUsage($evidence) && array_key_exists('total_tokens', $evidence) && $evidence['total_tokens'] === null;
        }
        if ($evidence['usage_measured'] === false) {
            return array_key_exists('input_tokens', $evidence) && $evidence['input_tokens'] === null
                && array_key_exists('output_tokens', $evidence) && $evidence['output_tokens'] === null
                && array_key_exists('total_tokens', $evidence) && $evidence['total_tokens'] === null;
        }
        $input = $evidence['input_tokens'] ?? null;
        $output = $evidence['output_tokens'] ?? null;
        $total = $evidence['total_tokens'] ?? null;
        return is_int($input) && is_int($output) && is_int($total)
            && $input >= 0 && $output >= 0 && $input <= PHP_INT_MAX - $output
            && $total === $input + $output;
    }

    /** @param array<string,mixed> $value */
    private function validRerankUsage(array $value): bool
    {
        $quantity = $value['rerank_quantity'] ?? null;
        return in_array($value['rerank_meter_code'] ?? null, ['rerank_search_units', 'rerank_tokens'], true)
            && array_key_exists('rerank_quantity', $value)
            && ($quantity === null || (is_int($quantity) && $quantity >= 0))
            && ($value['usage_measured'] ?? null) === ($quantity !== null)
            && array_key_exists('input_tokens', $value) && $value['input_tokens'] === null
            && array_key_exists('output_tokens', $value) && $value['output_tokens'] === null;
    }

    private function trustedTime(mixed $value): bool
    {
        if (!is_string($value) || trim($value) === '') {
            return false;
        }
        $time = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        return $time !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $time->format('Y-m-d H:i:s') === $value && $time->getTimestamp() <= time();
    }

    /** @param array<string,mixed> $value */
    private function encode(array $value): string
    {
        ksort($value, SORT_STRING);
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
