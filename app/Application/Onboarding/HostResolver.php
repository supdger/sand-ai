<?php

declare(strict_types=1);

namespace app\Application\Onboarding;

interface HostResolver
{
    /** @return list<string> */
    public function resolve(string $host): array;
}
