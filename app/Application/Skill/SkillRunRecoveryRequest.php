<?php

declare(strict_types=1);

namespace app\Application\Skill;

use app\Api\Support\ApiProblem;

/** Explicit paid recovery of one observed terminal failure. */
final readonly class SkillRunRecoveryRequest
{
    public function __construct(public bool $resumeFailed, public ?int $failedRunId)
    {
    }

    public static function fromPayload(array $payload): self
    {
        $resume = array_key_exists('resume_failed', $payload) ? $payload['resume_failed'] : false;
        $failed = $payload['failed_run_id'] ?? null;
        if (!is_bool($resume) || ($resume && (!is_int($failed) || $failed < 1))
            || (!$resume && array_key_exists('failed_run_id', $payload))) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Skill recovery requires the observed failed run');
        }
        return new self($resume, $failed);
    }

    public static function attemptNumber(mixed $value): ?int
    {
        if ($value === null) return null;
        if ((is_int($value) && $value >= 1 && $value <= 3)
            || (is_string($value) && preg_match('/\A[1-3]\z/D', $value) === 1)) return (int) $value;
        throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Skill attempt number is invalid');
    }
}
