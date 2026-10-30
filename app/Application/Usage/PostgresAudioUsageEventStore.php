<?php

declare(strict_types=1);

namespace app\Application\Usage;

use think\facade\Db;

/** PostgreSQL adapter; schema constraints remain the final immutable boundary. */
final class PostgresAudioUsageEventStore implements AudioUsageEventStore
{
    public function findByTaskRunId(int $taskRunId): ?array
    {
        $row = Db::connect('pgsql')->table('sand_ai_usage_event')->where('audio_task_run_id', $taskRunId)->find();
        return is_array($row) ? $row : null;
    }
    public function findByExecution(AudioUsageRequest $request): ?array
    {
        $query = Db::connect('pgsql')->table('sand_ai_usage_event');
        if ($request->externalDispatchId !== null) {
            $query->where('external_audio_dispatch_id',$request->externalDispatchId);
        } else {
            $query->where('audio_task_run_id',$request->taskRunId);
            $request->executionId === null ? $query->whereNull('audio_execution_id') : $query->where('audio_execution_id',$request->executionId);
            $query->whereNull('external_audio_dispatch_id');
        }
        $row = $query->find();
        return is_array($row) ? $row : null;
    }
    public function append(array $event): array
    {
        $columns = ['audio_task_run_id','audio_execution_id','external_audio_dispatch_id','organization_id','application_id','environment_id','workload_client_id','identity_snapshot','provider_snapshot','model_snapshot','deployment_snapshot','provider_id','model_id','deployment_id','service_action','source_kind','capability_code','metering_unit','metering_quantity','input_tokens','output_tokens','total_tokens','usage_state','price_revision_id','price_snapshot','metered_amount','reconciled_amount','currency','pricing_state','reconciliation_state','idempotency_key','create_time'];
        $rows = Db::connect('pgsql')->query(
            'INSERT INTO sand_ai_usage_event (' . implode(',', $columns) . ') VALUES (?, ?, ?, ?, ?, ?, ?, CAST(? AS jsonb), CAST(? AS jsonb), CAST(? AS jsonb), CAST(? AS jsonb), ?, ?, ?, ?, ?, ?, ?, CAST(? AS numeric(20,6)), ?, ?, ?, ?, ?, CAST(? AS jsonb), CAST(? AS numeric(20,6)), CAST(? AS numeric(20,6)), ?, ?, ?, ?, ?) RETURNING id',
            [$event['audio_task_run_id'],$event['audio_execution_id'],$event['external_audio_dispatch_id'],$event['organization_id'],$event['application_id'],$event['environment_id'],$event['workload_client_id'],json_encode($event['identity_snapshot'], JSON_THROW_ON_ERROR),json_encode($event['provider_snapshot'], JSON_THROW_ON_ERROR),json_encode($event['model_snapshot'], JSON_THROW_ON_ERROR),json_encode($event['deployment_snapshot'], JSON_THROW_ON_ERROR),$event['provider_id'],$event['model_id'],$event['deployment_id'],$event['service_action'],$event['source_kind'],$event['capability_code'],'audio_seconds',...NumericSql::binds($event['metering_quantity']),$event['input_tokens'],$event['output_tokens'],$event['total_tokens'],$event['usage_state'],$event['price_revision_id'],json_encode($event['price_snapshot'], JSON_THROW_ON_ERROR),...NumericSql::binds($event['metered_amount'],$event['reconciled_amount']),$event['currency'],$event['pricing_state'],$event['reconciliation_state'],$event['idempotency_key'],$event['create_time']]
        );
        $row = Db::connect('pgsql')->table('sand_ai_usage_event')->where('id',(int)$rows[0]['id'])->find();
        return is_array($row) ? $row : throw new \RuntimeException('Inserted Audio Usage event cannot be re-read');
    }
}
