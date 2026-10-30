<?php

declare(strict_types=1);

namespace app\Application\Audio\External;

use app\Application\Task\TaskExecutionLease;

/** Production seam: the external branch is injectable and can be tested without PG or HTTP. */
interface ExternalAudioTaskExecutor
{
    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function execute(int $environmentId, int $taskRunId, TaskExecutionLease $lease, array $input): array;
}
