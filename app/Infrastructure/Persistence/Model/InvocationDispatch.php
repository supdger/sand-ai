<?php

declare(strict_types=1);

namespace app\Infrastructure\Persistence\Model;

final class InvocationDispatch extends SandAiModel
{
    protected $table = 'sand_ai_invocation_dispatch';

    // The dispatch ledger is immutable and its PostgreSQL contract deliberately
    // has no soft-delete column. Inheriting SandAiModel's default would add an
    // invalid `delete_time IS NULL` predicate to every ledger query.
    protected $deleteTime = false;

    protected $json = ['route_snapshot', 'price_snapshot'];

    protected $hidden = ['provider_idempotency_key', 'lease_token'];
}
