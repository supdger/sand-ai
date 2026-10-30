<?php

declare(strict_types=1);

namespace app\Application\Audio\External;

use app\Api\Support\ApiProblem;
use app\Application\Audio\AudioBudgetGuard;
use app\Application\Audio\AudioTranscriptSealLedger;
use app\Application\Task\AudioTaskResultPresenter;
use app\Application\Gateway\RequestIdempotencyKey;
use app\Application\Usage\AudioCallStartPricingSnapshot;
use app\Application\Usage\AudioExecutionTarget;
use app\Application\Usage\AudioSeconds;
use app\Application\Usage\AudioUsageMeter;
use app\Application\Usage\AudioUsageRequest;
use app\Application\Usage\UsageAttribution;
use app\Application\Usage\PostgresAudioUsageEventStore;
use think\facade\Db;

/** Receipt-only recovery: callers cannot supply result or duration authority. */
final class PostgresExternalAudioResultRecoveryPort implements ExternalAudioResultRecoveryPort
{
    public function __construct(private readonly ExternalAudioReceiptCipher $cipher) {}

    public function recover(int $dispatchId, ExternalAudioDispatchClaim $claim): array
    {
        return Db::connect('pgsql')->transaction(function () use ($dispatchId,$claim): array {
            (new AudioBudgetGuard())->lock($dispatchId,$claim->taskRunId,null);
            // All external audio mutation/read paths take locks TaskRun -> TaskStep -> dispatch.
            $task=Db::connect('pgsql')->table('sand_ai_task_run')->where('id',$claim->taskRunId)->where('task_type','audio_transcription')->whereNull('delete_time')->lock(true)->find();
            $step=Db::connect('pgsql')->table('sand_ai_task_step')->where('task_id',$claim->taskRunId)->where('sequence_no',1)->whereNull('delete_time')->lock(true)->find();
            $dispatch=Db::connect('pgsql')->table('sand_ai_external_audio_dispatch')->where('id',$dispatchId)->lock(true)->find();
            if(!is_array($dispatch)||!is_array($task)||!is_array($step)||(int)($dispatch['task_run_id']??0)!==$claim->taskRunId||(int)($dispatch['environment_id']??0)!==(int)($task['environment_id']??0)||(string)($dispatch['lease_owner']??'')!==$claim->owner||(string)($dispatch['lease_token']??'')!==$claim->token||(int)($dispatch['lease_fence']??0)!==$claim->fence) throw new ApiProblem('SAND_AI_EXTERNAL_AUDIO_LEASE_LOST','External audio recovery lease is lost');
            if(($task['content_purged_time']??null)!==null||($dispatch['content_purged_time']??null)!==null) throw new ApiProblem('SAND_AI_AUDIO_CONTENT_PURGED','Audio content has been purged');
            $frozen=$dispatch['frozen_request']??null; if(is_string($frozen)){try{$frozen=json_decode($frozen,true,512,JSON_THROW_ON_ERROR);}catch(\Throwable){$frozen=null;}} if(!is_array($frozen)) throw new ApiProblem('SAND_AI_EXTERNAL_AUDIO_RECEIPT_INVALID','External audio frozen request is invalid');
            $request=ExternalAudioDispatchRequest::fromFrozen($frozen); if(!hash_equals((string)($dispatch['request_fingerprint']??''),$request->fingerprint())) throw new ApiProblem('SAND_AI_EXTERNAL_AUDIO_RECEIPT_INVALID','External audio frozen request is invalid');
            $state=(string)($dispatch['state']??''); if($state==='succeeded') { if(($task['state']??null)!=='succeeded'||($step['state']??null)!=='succeeded'||!is_array($task['result']??null)) throw new ApiProblem('SAND_AI_EXTERNAL_AUDIO_RECEIPT_INVALID','External audio terminal result is invalid'); (new AudioTaskResultPresenter())->present($task['result'],$request->driverCode,$request->driverVersion); $existing=Db::connect('pgsql')->table('sand_ai_usage_event')->where('external_audio_dispatch_id',$dispatchId)->lock(true)->find(); if(!is_array($existing)) throw new ApiProblem('SAND_AI_EXTERNAL_AUDIO_RECEIPT_INVALID','External audio terminal usage is missing'); return $this->summary($existing); } if(($task['state']??null)!=='running'||($step['state']??null)!=='running'||!in_array($state,['accepted','recovery_pending'],true)) throw new ApiProblem('SAND_AI_EXTERNAL_AUDIO_STATE_INVALID','External audio dispatch cannot recover its result');
            $receipt=Db::connect('pgsql')->table('sand_ai_external_audio_receipt')->where('external_audio_dispatch_id',$dispatchId)->where('receipt_kind','result')->where('expire_time','>',gmdate('Y-m-d H:i:s'))->lock(true)->find();
            if(!is_array($receipt)) throw new ApiProblem('SAND_AI_EXTERNAL_AUDIO_RECEIPT_INVALID','External audio result receipt is unavailable');
            $plain=$this->cipher->decrypt($receipt,$this->context($dispatch,$request)); try{$decoded=json_decode($plain,true,32,JSON_THROW_ON_ERROR);}catch(\Throwable){throw new ApiProblem('SAND_AI_EXTERNAL_AUDIO_RECEIPT_INVALID','External audio result receipt is invalid');} if(!is_array($decoded)) throw new ApiProblem('SAND_AI_EXTERNAL_AUDIO_RECEIPT_INVALID','External audio result receipt is invalid');
            $result=(new AudioTaskResultPresenter())->present($decoded,$request->driverCode,$request->driverVersion); $seconds=new AudioSeconds((string)$result['usage']['audio_seconds']);
            // The immutable execution policy determines metering authority.
            // Legacy receipts keep their original policy and sealed representation.
            $verifiedResult = $request->driverCode === 'glm_asr_audio'
                || $request->durationPolicy === ExternalAudioDispatchRequest::VERIFIED_DURATION_POLICY;
            if ($verifiedResult && (($dispatch['budget_audio_seconds'] ?? null) === null
                || (new AudioSeconds((string)$dispatch['budget_audio_seconds']))->value !== $seconds->value)) {
                throw new ApiProblem('SAND_AI_EXTERNAL_AUDIO_RECEIPT_INVALID', 'Verified audio duration changed');
            }
            try { $attribution=UsageAttribution::fromCurrentPersisted($request->usageAttribution,(int)$task['environment_id'],'capability','sand_ai.audio.transcription'); $target=AudioExecutionTarget::fromPersistence($request->executionTarget); $pricing=AudioCallStartPricingSnapshot::fromPersistence($request->priceSnapshot); if(!hash_equals($request->trustedScope,RequestIdempotencyKey::trustedScopeForAttribution($attribution))) throw new \InvalidArgumentException('scope'); } catch (\Throwable) { throw new ApiProblem('SAND_AI_EXTERNAL_AUDIO_RECEIPT_INVALID','External audio frozen metering authority is invalid'); }
            $bound=new AudioUsageRequest($claim->taskRunId,$attribution,$target->provider['id'],$target->model['id'],$target->deployment['id'],$target,$seconds,null,$dispatchId); $summary=(new AudioUsageMeter(new PostgresAudioUsageEventStore()))->meter($bound,$pricing);
            $now=gmdate('Y-m-d H:i:s');
            $stepUpdated=Db::connect('pgsql')->table('sand_ai_task_step')->where('id',$step['id'])->where('state','running')->update(['state'=>'succeeded','completed_at'=>$now,'update_time'=>$now]);
            $taskUpdated=Db::connect('pgsql')->table('sand_ai_task_run')->where('id',$claim->taskRunId)->where('state','running')->where('lease_owner',$claim->owner)->where('lease_token',$claim->token)->where('lease_fence',$claim->fence)->update(['state'=>'succeeded','result'=>$result,'completed_at'=>$now,'locked_at'=>null,'locked_by'=>null,'lease_owner'=>null,'lease_token'=>null,'lease_expire_time'=>null,'heartbeat_time'=>null,'update_time'=>$now]);
            $updated=Db::connect('pgsql')->table('sand_ai_external_audio_dispatch')->where('id',$dispatchId)->where('state',$state)->where('lease_owner',$claim->owner)->where('lease_token',$claim->token)->where('lease_fence',$claim->fence)->update(['state'=>'succeeded','terminal_snapshot'=>['usage'=>$summary,'result'=>$result],'update_time'=>$now]);
            $acked=Db::connect('pgsql')->table('sand_ai_external_audio_receipt')->where('id',$receipt['id'])->whereNull('acknowledged_time')->where('retain_until','>=',$now)->update(['acknowledged_time'=>$now]);
            if($stepUpdated!==1||$taskUpdated!==1||$updated!==1||$acked!==1) throw new ApiProblem('SAND_AI_EXTERNAL_AUDIO_LEASE_LOST','External audio recovery terminal lease is lost'); (new AudioTranscriptSealLedger())->sealBase($claim->taskRunId); return $summary;
        });
    }
    /** @param array<string,mixed> $record @return array<string,mixed> */
    private function context(array $record, ExternalAudioDispatchRequest $request): array { return ['environment_id'=>(int)$record['environment_id'],'task_run_id'=>(int)$record['task_run_id'],'dispatch_id'=>(int)$record['id'],'request_fingerprint'=>$request->fingerprint(),'logical_execution_id'=>$request->logicalExecutionId,'provider_id'=>$request->providerId,'model_id'=>$request->modelId,'deployment_id'=>$request->deploymentId,'profile_id'=>$request->profileId,'profile_revision'=>$request->profileRevision,'route_id'=>$request->routeId,'credential_revision_id'=>$request->credentialRevisionId,'credential_digest'=>$request->credentialDigest,'price_snapshot_hash'=>$request->priceSnapshotHash()]; }
    /** @param array<string,mixed> $usage @return array<string,mixed> */
    private function summary(array $usage): array { return ['usage'=>['audio_seconds'=>(string)($usage['metering_quantity']??''),'tokens'=>['input_tokens'=>0,'output_tokens'=>0,'total_tokens'=>0]],'cost'=>['pricing_state'=>(string)($usage['pricing_state']??''),'price_revision_id'=>($usage['price_revision_id']??null)===null?null:(int)$usage['price_revision_id'],'metered_amount'=>($usage['metered_amount']??null)===null?null:(string)$usage['metered_amount'],'currency'=>($usage['currency']??null)===null?null:(string)$usage['currency']]]; }
}
