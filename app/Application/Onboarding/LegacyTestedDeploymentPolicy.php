<?php

declare(strict_types=1);

namespace app\Application\Onboarding;

use app\Api\Support\ApiProblem;

/** Narrow compatibility gate for API 1.17 drafts that predate API 1.42. */
final class LegacyTestedDeploymentPolicy
{
    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed>|null $audit
     */
    public function assertUpgradeable(array $payload, ?array $audit, int $deploymentId, int $revision): void
    {
        $usage = $payload['validation_usage'] ?? null;
        $digest = $payload['execution_snapshot_digest'] ?? null;
        $validatedTime = is_array($usage) ? ($usage['validated_time'] ?? null) : null;
        $context = is_array($audit) && is_array($audit['context'] ?? null) ? $audit['context'] : [];
        if (($payload['onboarding'] ?? false) !== true || !is_array($usage)
            || !is_array($payload['execution_snapshot'] ?? null)
            || !is_string($digest) || preg_match('/\A[a-f0-9]{64}\z/', $digest) !== 1
            || !is_bool($usage['usage_measured'] ?? null)
            || !$this->validUsage($usage) || !$this->trustedTime($validatedTime)
            || ($usage['cost_tracking_state'] ?? null) !== 'untracked'
            || (int) ($audit['resource_id'] ?? 0) !== $deploymentId
            || ($context['outcome'] ?? null) !== 'passed'
            || ($context['revision'] ?? null) !== $revision
            || ($context['execution_snapshot_digest'] ?? null) !== $digest
            || ($context['validated_time'] ?? null) !== $validatedTime
            || !$this->trustedTime($context['validated_time'] ?? null)) {
            throw new ApiProblem('SAND_AI_CONFIGURATION_DRIFTED', 'Legacy tested deployment provenance is incomplete');
        }
    }

    /** @param array<string,mixed> $usage */
    private function validUsage(array $usage): bool
    {
        if ($usage['usage_measured'] === false) {
            return array_key_exists('input_tokens', $usage) && $usage['input_tokens'] === null
                && array_key_exists('output_tokens', $usage) && $usage['output_tokens'] === null
                && array_key_exists('total_tokens', $usage) && $usage['total_tokens'] === null;
        }
        $input = $usage['input_tokens'] ?? null;
        $output = $usage['output_tokens'] ?? null;
        $total = $usage['total_tokens'] ?? null;
        return is_int($input) && is_int($output) && is_int($total)
            && $input >= 0 && $output >= 0 && $input <= PHP_INT_MAX - $output
            && $total === $input + $output;
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
}
