<?php

declare(strict_types=1);

namespace app\Application\Usage;

/** Persistence port for the single immutable audio Usage Event per task run. */
interface AudioUsageEventStore
{
    /** @return array<string,mixed>|null */
    public function findByTaskRunId(int $taskRunId): ?array;

    /** @param array<string,mixed> $event @return array<string,mixed> */
    public function append(array $event): array;
}
