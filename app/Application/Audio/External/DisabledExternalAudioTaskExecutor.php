<?php

declare(strict_types=1);

namespace app\Application\Audio\External;

use app\Api\Support\ApiProblem;
use app\Application\Task\TaskExecutionLease;

/** Default-safe executor: route registration alone never turns on cloud audio. */
final class DisabledExternalAudioTaskExecutor implements ExternalAudioTaskExecutor
{
    public function execute(int $environmentId, int $taskRunId, TaskExecutionLease $lease, array $input): array
    {
        throw new ApiProblem('SAND_AI_AUDIO_NOT_CONFIGURED', 'OpenAI-compatible audio is not configured');
    }
}
