<?php

declare(strict_types=1);

namespace app\Application\Tool;

use app\Infrastructure\Persistence\Model\ToolRun;

/** Test seam for deterministic lease-fence races; production uses the no-op implementation. */
interface ToolRunBoundaryPause
{
    /** @param array<string,mixed> $lease */
    public function pause(string $boundary, ToolRun $run, array $lease): void;
}
