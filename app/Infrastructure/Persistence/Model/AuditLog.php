<?php

declare(strict_types=1);

namespace app\Infrastructure\Persistence\Model;

final class AuditLog extends SandAiModel
{
    protected $table = 'sand_ai_audit_log';
    protected $json = ['context'];
}
