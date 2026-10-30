<?php

declare(strict_types=1);

namespace app\Infrastructure\Persistence\Model;

final class ToolOutcomeReceipt extends ImmutableSandAiModel
{
    protected $table = 'sand_ai_tool_outcome_receipt';
    protected $hidden = ['key_id', 'nonce', 'tag', 'ciphertext', 'content_hmac', 'plaintext_bytes', 'ciphertext_bytes'];
}
