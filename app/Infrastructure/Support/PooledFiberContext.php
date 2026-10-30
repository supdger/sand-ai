<?php

declare(strict_types=1);

namespace app\Infrastructure\Support;

use ArrayObject;
use Webman\Context;

/**
 * Keeps a nested \Fiber on its caller's pooled database connection.
 *
 * webman/think-orm checks a connection out of the per-worker pool the first
 * time a context asks for one, and only returns it when that context is
 * destroyed (Webman\Context::onDestroy -> DestructionWatcher). A \Fiber gets
 * its own context keyed by the fiber object, so any query issued inside a
 * nested fiber takes a *second* connection and holds it until the fiber itself
 * is freed. Provider stream fibers stay reachable through a reference cycle
 * (the fiber body binds $this, the object stores the fiber), so they survive
 * until PHP's cycle collector runs -- which, in a long-lived worker, happens
 * far later than the pool's max_connections. Each abandoned stream therefore
 * retired one connection permanently and the worker died once the pool drained.
 *
 * Adopting the caller's context makes the nested fiber reuse the connection the
 * caller already holds: no second checkout, and release stays owned by the
 * request or worker tick that acquired it. This is safe because SandAI's
 * fibers are strictly nested and cooperative -- the caller is blocked while the
 * fiber runs and the fiber is suspended while the caller runs -- so the two
 * never touch the connection at the same time.
 */
final class PooledFiberContext
{
    /** Capture the current context. Call this on the caller's side. */
    public static function capture(): ArrayObject
    {
        return Context::get();
    }

    /** Adopt a captured context. Call this as the first statement in the fiber body. */
    public static function adopt(ArrayObject $context): void
    {
        Context::reset($context);
    }
}
