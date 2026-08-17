<?php

declare(strict_types=1);

namespace plugin\SandAi\app\model;

final class AuditLog extends SandAiPluginModel
{
    protected $table = 'sand_ai_audit_log';
    protected $json = ['context'];
}
