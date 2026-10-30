<?php

declare(strict_types=1);

namespace app\Identity\Infrastructure;

interface QueuedSandIamContextAad
{
    public function aad(): string;

    public function expiresAt(): int;
}
