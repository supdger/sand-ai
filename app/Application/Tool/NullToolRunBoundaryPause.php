<?php

declare(strict_types=1);

namespace app\Application\Tool;

use app\Infrastructure\Persistence\Model\ToolRun;

final class NullToolRunBoundaryPause implements ToolRunBoundaryPause
{
    public function pause(string $boundary, ToolRun $run, array $lease): void
    {
    }
}
