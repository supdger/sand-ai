<?php

declare(strict_types=1);

namespace app\Application\Audio\External;

use app\Api\Support\ApiProblem;
use app\Application\Audio\AudioBudgetGuard;
use think\facade\Db;

/** One PostgreSQL connection owns accepted receipt read, unique result write, and accepted→recovery_pending CAS. */
final class PostgresExternalAudioAcceptedRecoveryStore implements ExternalAudioAcceptedRecoveryStore
{
    public function accepted(int $dispatchId, ExternalAudioDispatchClaim $claim): ?array
    {
        return Db::connect('pgsql')->transaction(function () use ($dispatchId,$claim): ?array {
            (new AudioBudgetGuard())->lock($dispatchId,$claim->taskRunId,null);
            $task=Db::connect('pgsql')->table('sand_ai_task_run')->where('id',$claim->taskRunId)->where('state','running')->where('lease_owner',$claim->owner)->where('lease_token',$claim->token)->where('lease_fence',$claim->fence)->whereNull('delete_time')->lock(true)->find();
            $step=Db::connect('pgsql')->table('sand_ai_task_step')->where('task_id',$claim->taskRunId)->where('sequence_no',1)->where('state','running')->whereNull('delete_time')->lock(true)->find();
            $dispatch=Db::connect('pgsql')->table('sand_ai_external_audio_dispatch')->where('id',$dispatchId)->where('task_run_id',$claim->taskRunId)->where('state','accepted')->where('lease_owner',$claim->owner)->where('lease_token',$claim->token)->where('lease_fence',$claim->fence)->lock(true)->find();
            if(!is_array($task)||!is_array($step)||!is_array($dispatch))return null;
            $receipt=Db::connect('pgsql')->table('sand_ai_external_audio_receipt')->where('external_audio_dispatch_id',$dispatchId)->where('receipt_kind','accepted')->where('expire_time','>',gmdate('Y-m-d H:i:s'))->lock(true)->find();
            if(!is_array($receipt))return null; $dispatch['accepted_receipt']=$receipt; return $dispatch;
        });
    }
    public function persistResultRecovery(int $dispatchId, ExternalAudioDispatchClaim $claim, array $receipt): void
    {
        Db::connect('pgsql')->transaction(function () use ($dispatchId,$claim,$receipt): void {
            (new AudioBudgetGuard())->lock($dispatchId,$claim->taskRunId,null);
            $task=Db::connect('pgsql')->table('sand_ai_task_run')->where('id',$claim->taskRunId)->where('state','running')->where('lease_owner',$claim->owner)->where('lease_token',$claim->token)->where('lease_fence',$claim->fence)->whereNull('delete_time')->lock(true)->find();
            $step=Db::connect('pgsql')->table('sand_ai_task_step')->where('task_id',$claim->taskRunId)->where('sequence_no',1)->where('state','running')->whereNull('delete_time')->lock(true)->find();
            $dispatch=Db::connect('pgsql')->table('sand_ai_external_audio_dispatch')->where('id',$dispatchId)->where('task_run_id',$claim->taskRunId)->where('state','accepted')->where('lease_owner',$claim->owner)->where('lease_token',$claim->token)->where('lease_fence',$claim->fence)->lock(true)->find();
            if(!is_array($task)||!is_array($step)||!is_array($dispatch))throw new ApiProblem('SAND_AI_EXTERNAL_AUDIO_LEASE_LOST','External audio accepted recovery lease is lost');
            $accepted=Db::connect('pgsql')->table('sand_ai_external_audio_receipt')->where('external_audio_dispatch_id',$dispatchId)->where('receipt_kind','accepted')->where('expire_time','>',gmdate('Y-m-d H:i:s'))->lock(true)->find();
            if(!is_array($accepted))throw new ApiProblem('SAND_AI_EXTERNAL_AUDIO_RECEIPT_INVALID','External audio accepted receipt is unavailable');
            $inserted=Db::connect('pgsql')->table('sand_ai_external_audio_receipt')->insert(['external_audio_dispatch_id'=>$dispatchId,'receipt_kind'=>'result','receipt_version'=>$receipt['receipt_version'],'key_id'=>$receipt['key_id'],'nonce'=>$receipt['nonce'],'tag'=>$receipt['tag'],'ciphertext'=>$receipt['ciphertext'],'plaintext_bytes'=>$receipt['plaintext_bytes'],'content_hmac'=>$receipt['content_hmac'],'expire_time'=>$receipt['expire_time'],'retain_until'=>$receipt['retain_until'],'acknowledged_time'=>null,'create_time'=>gmdate('Y-m-d H:i:s')]);
            if($inserted!==1)throw new ApiProblem('SAND_AI_EXTERNAL_AUDIO_PERSISTENCE_FAILED','External audio result receipt cannot be persisted',true);
            $updated=Db::connect('pgsql')->table('sand_ai_external_audio_dispatch')->where('id',$dispatchId)->where('state','accepted')->where('lease_owner',$claim->owner)->where('lease_token',$claim->token)->where('lease_fence',$claim->fence)->update(['state'=>'recovery_pending','update_time'=>gmdate('Y-m-d H:i:s')]);
            if($updated!==1)throw new ApiProblem('SAND_AI_EXTERNAL_AUDIO_LEASE_LOST','External audio accepted recovery transition is lost');
        });
    }
}
