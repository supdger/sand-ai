<?php

declare(strict_types=1);

namespace app\Infrastructure\Persistence\Model;

final class KnowledgeBaseVersion extends ImmutableSandAiModel
{
    protected $table = 'sand_ai_knowledge_base_version';
}
