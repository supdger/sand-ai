<?php

declare(strict_types=1);

namespace app\Application\Knowledge;

use app\Api\Support\ApiProblem;
use app\Identity\Contract\IdentityContextException;
use app\Identity\Infrastructure\FileInvocationFacts;

final class KnowledgeSourceAttribution
{
    /** @param array<string,mixed> $file
     *  @return array{organization_id:int,application_id:int,environment_id:int,data_class:?string}
     */
    public static function fromFile(array $file, int $environmentId): array
    {
        try {
            $facts = FileInvocationFacts::fromPersisted($file, (int) ($file['id'] ?? 0));
        } catch (IdentityContextException $exception) {
            throw new ApiProblem('SAND_AI_KNOWLEDGE_SOURCE_INVALID', 'Knowledge source has no verified owner');
        }
        if ($facts['environment_id'] !== $environmentId || ($file['state'] ?? null) !== 'parsed') {
            throw new ApiProblem('SAND_AI_KNOWLEDGE_SOURCE_INVALID', 'Knowledge source is outside its environment');
        }
        return [
            'organization_id' => $facts['organization_id'],
            'application_id' => $facts['application_id'],
            'environment_id' => $facts['environment_id'],
            'data_class' => $facts['data_class'],
        ];
    }

    /** @param array{organization_id:int,application_id:int,environment_id:int,data_class:?string} $expected
     *  @param array{organization_id:int,application_id:int,environment_id:int,data_class:?string} $actual
     */
    public static function assertCompatible(array $expected, array $actual): void
    {
        if ($expected !== $actual) {
            throw new ApiProblem('SAND_AI_KNOWLEDGE_SOURCE_INVALID', 'Knowledge sources have different ownership or data classes');
        }
    }
}
