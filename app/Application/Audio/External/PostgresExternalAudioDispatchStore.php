<?php

declare(strict_types=1);

namespace app\Application\Audio\External;

use app\Api\Support\ApiProblem;
use app\Application\Audio\AudioBudgetGuard;
use app\Application\Usage\AudioCallStartPricingSnapshot;
use app\Application\Usage\AudioSeconds;
use app\Application\Usage\UsageAttribution;
use app\Application\Budget\ChatBudgetGuard;
use app\Identity\Infrastructure\AudioTaskAccessGuard;
use app\Infrastructure\Persistence\Model\TaskRun;
use think\facade\Db;

/** PostgreSQL implementation; callers provide their enclosing task/Usage transaction. */
final class PostgresExternalAudioDispatchStore implements ExternalAudioDispatchStore
{
    public function prepare(ExternalAudioDispatchRequest $request, int $environmentId, int $taskRunId): array
    {
        $existing = Db::connect('pgsql')->table('sand_ai_external_audio_dispatch')->where('environment_id', $environmentId)->where('logical_execution_id', $request->logicalExecutionId)->find();
        if (is_array($existing)) {
            if ((int)($existing['task_run_id']??0)!==$taskRunId || !hash_equals((string)($existing['request_fingerprint']??''),$request->fingerprint()) || $this->canonical($existing['frozen_request']??null)!==$this->canonical($request->frozen())) throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT','External audio logical execution differs from its frozen request');
            return $existing;
        }
        $inserted=Db::connect('pgsql')->table('sand_ai_external_audio_dispatch')->insert(['environment_id'=>$environmentId,'task_run_id'=>$taskRunId,'logical_execution_id'=>$request->logicalExecutionId,'request_fingerprint'=>$request->fingerprint(),'frozen_request'=>$request->frozen(),'state'=>ExternalAudioDispatchStatePolicy::PREPARED,'create_time'=>gmdate('Y-m-d H:i:s'),'update_time'=>gmdate('Y-m-d H:i:s')]);
        if ($inserted !== 1) throw new ApiProblem('SAND_AI_EXTERNAL_AUDIO_PERSISTENCE_FAILED','External audio dispatch cannot be prepared',true);
        return Db::connect('pgsql')->table('sand_ai_external_audio_dispatch')->where('environment_id',$environmentId)->where('logical_execution_id',$request->logicalExecutionId)->find() ?? throw new \RuntimeException('External audio dispatch cannot be read');
    }
    public static function freezeDuration(int $dispatchId, string $fingerprint, AudioSeconds $seconds): void
    {
        $changed = Db::connect('pgsql')->table('sand_ai_external_audio_dispatch')->where('id',$dispatchId)->where('request_fingerprint',$fingerprint)->where('state','prepared')->whereNull('budget_audio_seconds')->update(['budget_audio_seconds'=>$seconds->value]);
        if ($changed !== 1) {
            $row = Db::connect('pgsql')->table('sand_ai_external_audio_dispatch')->where('id',$dispatchId)->where('request_fingerprint',$fingerprint)->find();
            if (!is_array($row) || ($row['budget_audio_seconds'] ?? null) === null
                || (new AudioSeconds((string)$row['budget_audio_seconds']))->value !== $seconds->value) {
                throw new ApiProblem('SAND_AI_BUDGET_IDEMPOTENCY_CONFLICT','Verified audio duration changed');
            }
        }
    }

    public function claimDispatch(int $dispatchId, ExternalAudioDispatchClaim $claim): ?array
    {
        return Db::connect('pgsql')->transaction(function () use ($dispatchId, $claim): ?array {
            $probe = Db::connect('pgsql')->table('sand_ai_external_audio_dispatch')->where('id',$dispatchId)->where('task_run_id',$claim->taskRunId)->where('state','prepared')->find();
            if (!is_array($probe)) { return null; }
            $frozen = is_array($probe['frozen_request']) ? $probe['frozen_request'] : json_decode((string) $probe['frozen_request'], true, 512, JSON_THROW_ON_ERROR);
            $bound = ExternalAudioDispatchRequest::fromFrozen($frozen);
            $attribution = UsageAttribution::fromCurrentPersisted($bound->usageAttribution,(int)$probe['environment_id'],'capability','sand_ai.audio.transcription');
            $pricing = AudioCallStartPricingSnapshot::fromPersistence($bound->priceSnapshot);
            $duration = ($probe['budget_audio_seconds'] ?? null) === null ? null : new AudioSeconds((string)$probe['budget_audio_seconds']);
            (new AudioBudgetGuard())->reserve($dispatchId,$claim->taskRunId,null,$attribution,$bound->modelId,$pricing,$duration,$bound->fingerprint());
            // Budget windows -> reservations -> TaskRun -> TaskStep -> dispatch.
            $task=TaskRun::where('id',$claim->taskRunId)->where('state','running')->where('lease_owner',$claim->owner)->where('lease_token',$claim->token)->where('lease_fence',$claim->fence)->whereNull('delete_time')->lock(true)->find();
            if (!$task instanceof TaskRun || (string)$task->task_type!=='audio_transcription') throw new ApiProblem('SAND_AI_EXTERNAL_AUDIO_LEASE_LOST','Audio task lease was lost before dispatch');
            $taskInput=is_object($task->input)?(array)$task->input:$task->input;
            if(!is_array($taskInput)) throw new ApiProblem('SAND_AI_EXTERNAL_AUDIO_REQUEST_INVALID','Frozen audio task input is invalid');
            FrozenAudioTaskInputPolicy::assert($taskInput,(int)$task->environment_id);
            $step=Db::connect('pgsql')->table('sand_ai_task_step')->where('task_id',$claim->taskRunId)->where('sequence_no',1)->where('state','running')->whereNull('delete_time')->lock(true)->find();
            if (!is_array($step)) throw new ApiProblem('SAND_AI_EXTERNAL_AUDIO_LEASE_LOST','Audio step lease was lost before dispatch');
            $dispatch=Db::connect('pgsql')->table('sand_ai_external_audio_dispatch')->where('id',$dispatchId)->where('task_run_id',$claim->taskRunId)->where('state',ExternalAudioDispatchStatePolicy::PREPARED)->whereNull('lease_owner')->lock(true)->find();
            if (!is_array($dispatch)) throw new ApiProblem('SAND_AI_EXTERNAL_AUDIO_LEASE_LOST','Audio dispatch lease was lost before dispatch');
            $frozen=$dispatch['frozen_request']??null; if(is_string($frozen)){try{$frozen=json_decode($frozen,true,512,JSON_THROW_ON_ERROR);}catch(\Throwable){throw new ApiProblem('SAND_AI_EXTERNAL_AUDIO_REQUEST_INVALID','Frozen external audio dispatch is invalid');}}
            if(!is_array($frozen)) throw new ApiProblem('SAND_AI_EXTERNAL_AUDIO_REQUEST_INVALID','Frozen external audio dispatch is invalid');
            $request=ExternalAudioDispatchRequest::fromFrozen($frozen); if(!hash_equals((string)($dispatch['request_fingerprint']??''),$request->fingerprint())) throw new ApiProblem('SAND_AI_EXTERNAL_AUDIO_REQUEST_INVALID','Frozen external audio dispatch is invalid');
            FrozenAudioTaskInputPolicy::assertMatchesExternal($taskInput,(int)$task->environment_id,$request);
            // Private-file loading and preflight may outlive the Worker-entry
            // check. Revalidate the original encrypted context before the send
            // claim; rejection rolls back this transaction and its reservation.
            (new AudioTaskAccessGuard())->revalidateQueued($taskInput['iam_authorization'],$task);
            $updated = Db::connect('pgsql')->table('sand_ai_external_audio_dispatch')->where('id',$dispatchId)->where('state',ExternalAudioDispatchStatePolicy::PREPARED)->whereNull('lease_owner')->update(['state'=>ExternalAudioDispatchStatePolicy::DISPATCHING,'lease_owner'=>$claim->owner,'lease_token'=>$claim->token,'lease_fence'=>$claim->fence,'update_time'=>gmdate('Y-m-d H:i:s')]);
            if ($updated !== 1) { throw new ApiProblem('SAND_AI_EXTERNAL_AUDIO_LEASE_LOST','Audio dispatch claim failed'); }
            return Db::connect('pgsql')->table('sand_ai_external_audio_dispatch')->where('id',$dispatchId)->find();
        });
    }
    public function persistAccepted(int $dispatchId, ExternalAudioDispatchClaim $claim, string $providerJobId, array $receipt): void
    {
        Db::connect('pgsql')->transaction(function () use ($dispatchId,$claim,$providerJobId,$receipt): void {
            (new AudioBudgetGuard())->lock($dispatchId,$claim->taskRunId,null);
            $task=Db::connect('pgsql')->table('sand_ai_task_run')->where('id',$claim->taskRunId)->where('state','running')->where('lease_owner',$claim->owner)->where('lease_token',$claim->token)->where('lease_fence',$claim->fence)->whereNull('delete_time')->lock(true)->find();
            $step=Db::connect('pgsql')->table('sand_ai_task_step')->where('task_id',$claim->taskRunId)->where('sequence_no',1)->where('state','running')->whereNull('delete_time')->lock(true)->find();
            $dispatch=Db::connect('pgsql')->table('sand_ai_external_audio_dispatch')->where('id',$dispatchId)->where('task_run_id',$claim->taskRunId)->where('state','dispatching')->where('lease_owner',$claim->owner)->where('lease_token',$claim->token)->where('lease_fence',$claim->fence)->lock(true)->find();
            if(!is_array($task)||!is_array($step)||!is_array($dispatch)) throw new ApiProblem('SAND_AI_EXTERNAL_AUDIO_LEASE_LOST','External audio acceptance lease is lost');
            $inserted=Db::connect('pgsql')->table('sand_ai_external_audio_receipt')->insert(['external_audio_dispatch_id'=>$dispatchId,'receipt_kind'=>'accepted','receipt_version'=>$receipt['receipt_version'],'key_id'=>$receipt['key_id'],'nonce'=>$receipt['nonce'],'tag'=>$receipt['tag'],'ciphertext'=>$receipt['ciphertext'],'plaintext_bytes'=>$receipt['plaintext_bytes'],'content_hmac'=>$receipt['content_hmac'],'expire_time'=>$receipt['expire_time'],'retain_until'=>$receipt['retain_until'],'acknowledged_time'=>null,'create_time'=>gmdate('Y-m-d H:i:s')]);
            if($inserted!==1) throw new ApiProblem('SAND_AI_EXTERNAL_AUDIO_PERSISTENCE_FAILED','External audio acceptance receipt cannot be persisted',true);
            $updated=Db::connect('pgsql')->table('sand_ai_external_audio_dispatch')->where('id',$dispatchId)->where('state','dispatching')->where('lease_owner',$claim->owner)->where('lease_token',$claim->token)->where('lease_fence',$claim->fence)->update(['state'=>'accepted','accepted_provider_job_id'=>$providerJobId,'accepted_at'=>gmdate('Y-m-d H:i:s'),'update_time'=>gmdate('Y-m-d H:i:s')]);
            if($updated!==1) throw new ApiProblem('SAND_AI_EXTERNAL_AUDIO_LEASE_LOST','External audio acceptance transition is lost');
        });
    }
    public function persistCompleted(int $dispatchId, ExternalAudioDispatchClaim $claim, array $receipt): void
    {
        Db::connect('pgsql')->transaction(function () use ($dispatchId,$claim,$receipt): void {
            (new AudioBudgetGuard())->lock($dispatchId,$claim->taskRunId,null);
            // Lock order is identical to every other 121D/121E mutation.
            $task=Db::connect('pgsql')->table('sand_ai_task_run')->where('id',$claim->taskRunId)->where('state','running')->where('lease_owner',$claim->owner)->where('lease_token',$claim->token)->where('lease_fence',$claim->fence)->whereNull('delete_time')->lock(true)->find();
            $step=Db::connect('pgsql')->table('sand_ai_task_step')->where('task_id',$claim->taskRunId)->where('sequence_no',1)->where('state','running')->whereNull('delete_time')->lock(true)->find();
            $dispatch=Db::connect('pgsql')->table('sand_ai_external_audio_dispatch')->where('id',$dispatchId)->where('task_run_id',$claim->taskRunId)->where('state','dispatching')->where('lease_owner',$claim->owner)->where('lease_token',$claim->token)->where('lease_fence',$claim->fence)->lock(true)->find();
            if(!is_array($task)||!is_array($step)||!is_array($dispatch)) throw new ApiProblem('SAND_AI_EXTERNAL_AUDIO_LEASE_LOST','External audio completion lease is lost');
            $inserted=Db::connect('pgsql')->table('sand_ai_external_audio_receipt')->insert(['external_audio_dispatch_id'=>$dispatchId,'receipt_kind'=>'result','receipt_version'=>$receipt['receipt_version'],'key_id'=>$receipt['key_id'],'nonce'=>$receipt['nonce'],'tag'=>$receipt['tag'],'ciphertext'=>$receipt['ciphertext'],'plaintext_bytes'=>$receipt['plaintext_bytes'],'content_hmac'=>$receipt['content_hmac'],'expire_time'=>$receipt['expire_time'],'retain_until'=>$receipt['retain_until'],'acknowledged_time'=>null,'create_time'=>gmdate('Y-m-d H:i:s')]);
            if($inserted!==1) throw new ApiProblem('SAND_AI_EXTERNAL_AUDIO_PERSISTENCE_FAILED','External audio result receipt cannot be persisted',true);
            $updated=Db::connect('pgsql')->table('sand_ai_external_audio_dispatch')->where('id',$dispatchId)->where('state','dispatching')->where('lease_owner',$claim->owner)->where('lease_token',$claim->token)->where('lease_fence',$claim->fence)->update(['state'=>'recovery_pending','update_time'=>gmdate('Y-m-d H:i:s')]);
            if($updated!==1) throw new ApiProblem('SAND_AI_EXTERNAL_AUDIO_LEASE_LOST','External audio completion transition is lost');
        });
    }
    public function terminalize(int $dispatchId, ExternalAudioDispatchClaim $claim, string $state, array $terminal): void
    {
        Db::connect('pgsql')->transaction(function () use ($dispatchId,$claim,$state,$terminal): void {
        ExternalAudioDispatchStatePolicy::assertTransition(ExternalAudioDispatchStatePolicy::DISPATCHING, $state);
        $anchor = (new AudioBudgetGuard())->lock($dispatchId,$claim->taskRunId,null);
        $values=['state'=>$state,'update_time'=>gmdate('Y-m-d H:i:s')];
        if($state==='accepted') { $values += ['accepted_provider_job_id'=>$terminal['provider_job_id']??null,'accepted_at'=>gmdate('Y-m-d H:i:s')]; } else { $values += ['terminal_snapshot'=>$terminal]; }
        $updated=Db::connect('pgsql')->table('sand_ai_external_audio_dispatch')->where('id',$dispatchId)->where('task_run_id',$claim->taskRunId)->where('lease_owner',$claim->owner)->where('lease_token',$claim->token)->where('lease_fence',$claim->fence)->where('state',ExternalAudioDispatchStatePolicy::DISPATCHING)->update($values);
        if($updated!==1) throw new ApiProblem('SAND_AI_EXTERNAL_AUDIO_LEASE_LOST','External audio terminal lease is lost');
        if ($anchor !== null) {
            if ($state === ExternalAudioDispatchStatePolicy::NOT_DISPATCHED) { (new ChatBudgetGuard())->release($anchor,'audio_not_dispatched'); }
            elseif ($state === ExternalAudioDispatchStatePolicy::AMBIGUOUS) { (new AudioBudgetGuard())->finish($anchor,null,null); }
        }
        });
    }
    private function canonical(mixed $value): string { if(is_string($value)){try{$value=json_decode($value,true,512,JSON_THROW_ON_ERROR);}catch(\Throwable){}} if(is_array($value)&&!array_is_list($value))ksort($value); if(is_array($value))foreach($value as &$item)$item=json_decode($this->canonical($item),true,512,JSON_THROW_ON_ERROR); unset($item); return json_encode($value,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION); }
}
