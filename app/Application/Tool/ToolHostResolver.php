<?php

declare(strict_types=1);

namespace app\Application\Tool;

interface ToolHostResolver
{
    /** @return list<string> */
    public function resolve(string $host): array;
}
