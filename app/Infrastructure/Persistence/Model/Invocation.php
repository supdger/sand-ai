<?php

declare(strict_types=1);

namespace app\Infrastructure\Persistence\Model;

final class Invocation extends SandAiModel
{
    protected $table = 'sand_ai_invocation';

    protected $json = ['call_start_price_snapshot'];

    protected $hidden = [
        'parent_workflow_run_id', 'parent_task_run_id', 'parent_task_step_id', 'parent_workflow_run_file_id',
        'workflow_node_code', 'logical_execution_id', 'execution_attempt_no', 'execution_snapshot_sha256',
        'organization_id', 'application_id', 'workload_client_id', 'data_class',
        'knowledge_publication_id', 'knowledge_base_version_id', 'knowledge_source_scope_sha256',
    ];
}
