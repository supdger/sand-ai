<?php

declare(strict_types=1);

namespace app\Application\Gateway;

interface DispatchFinalizationStore
{
    /** @template T @param callable():T $write @return T */
    public function finalize(callable $write): mixed;
}
