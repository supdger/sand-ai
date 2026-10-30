<?php

declare(strict_types=1);

namespace app\Infrastructure\Persistence\Model;

/** Internal persistence model. Its cipher fields must never be serialized in an API DTO. */
final class InvocationOutcomeReceipt extends SandAiModel
{
    protected $table = 'sand_ai_invocation_outcome_receipt';

    // Receipt lifecycle is enforced by its state machine and immutable trigger;
    // the PostgreSQL contract deliberately has no generic soft-delete column.
    protected $deleteTime = false;

    protected $hidden = ['nonce', 'tag', 'ciphertext', 'content_hmac', 'key_id', 'plaintext_bytes', 'ciphertext_bytes', 'cleanup_owner', 'delete_time'];
}
