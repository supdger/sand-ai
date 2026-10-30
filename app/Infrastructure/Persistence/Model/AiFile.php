<?php

declare(strict_types=1);

namespace app\Infrastructure\Persistence\Model;

final class AiFile extends SandAiModel
{
    protected $table = 'sand_ai_file';

    protected $json = ['owner_attribution'];

    protected $hidden = ['upload_request_key_sha256', 'owner_scope', 'owner_attribution', 'object_key', 'storage_driver', 'delete_time'];
}
