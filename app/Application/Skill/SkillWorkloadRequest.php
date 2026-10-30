<?php

declare(strict_types=1);

namespace app\Application\Skill;

use app\Api\Support\ApiProblem;
use app\Identity\Contract\IdentityContext;

/** Trusted IAM envelope consumed by the public Skill workload endpoint. */
final readonly class SkillWorkloadRequest
{
    /** @param array{context:IdentityContext,environment_id:int} $identity */
    public static function fromAuthenticated(array $identity, mixed $input, mixed $requestId): self
    {
        if (!isset($identity['context']) || !$identity['context'] instanceof IdentityContext
            || !is_int($identity['environment_id']) || $identity['environment_id'] < 1
            || !is_array($input) || !is_string($requestId) || $requestId === '') {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Skill request envelope is invalid');
        }

        return new self($identity['context'], $identity['environment_id'], $input, $requestId);
    }

    /** @param array<string,mixed> $input */
    private function __construct(
        public IdentityContext $context,
        public int $environmentId,
        public array $input,
        public string $requestId,
    ) {
    }
}
