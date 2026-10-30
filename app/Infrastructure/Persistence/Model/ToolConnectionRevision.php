<?php

declare(strict_types=1);

namespace app\Infrastructure\Persistence\Model;

final class ToolConnectionRevision extends ImmutableSandAiModel
{
    protected $table = 'sand_ai_tool_connection_revision';
    protected $json = ['encrypted_config'];
    protected $jsonAssoc = true;
    protected $hidden = ['encrypted_config', 'encryption_key_id', 'config_fingerprint'];
}
