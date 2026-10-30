<?php

declare(strict_types=1);

namespace app\Application\Audio\External;

use app\Api\Support\ApiProblem;
use app\Application\Task\AudioTaskResultPresenter;

/** Queries only the job named by a verified accepted receipt; it has no submit path. */
final class ExternalAudioAcceptedRecoveryService
{
    public function __construct(private readonly ExternalAudioAcceptedRecoveryStore $store, private readonly ExternalAudioReceiptCipher $cipher) {}
    /** @return array{state:string} */
    public function recover(int $dispatchId, ExternalAudioDispatchClaim $claim, ExternalAudioAdapter $adapter): array
    {
        $record=$this->store->accepted($dispatchId,$claim); if(!is_array($record)||($record['state']??null)!=='accepted'||!is_array($record['accepted_receipt']??null)) throw new ApiProblem('SAND_AI_EXTERNAL_AUDIO_STATE_INVALID','External audio accepted dispatch is unavailable');
        $frozen=$record['frozen_request']??null; if(is_string($frozen)){try{$frozen=json_decode($frozen,true,512,JSON_THROW_ON_ERROR);}catch(\Throwable){$frozen=null;}} if(!is_array($frozen)) throw new ApiProblem('SAND_AI_EXTERNAL_AUDIO_RECEIPT_INVALID','External audio frozen request is invalid');
        $request=ExternalAudioDispatchRequest::fromFrozen($frozen); if(!hash_equals((string)($record['request_fingerprint']??''),$request->fingerprint())) throw new ApiProblem('SAND_AI_EXTERNAL_AUDIO_RECEIPT_INVALID','External audio frozen request is invalid');
        $accepted=$this->cipher->decrypt($record['accepted_receipt'],$this->context($record,$request));
        try{$value=json_decode($accepted,true,16,JSON_THROW_ON_ERROR);}catch(\Throwable){throw new ApiProblem('SAND_AI_EXTERNAL_AUDIO_RECEIPT_INVALID','External audio accepted receipt is invalid');}
        if(!is_array($value)||array_keys($value)!==['provider_job_id']||!is_string($value['provider_job_id'])||preg_match('/\A[a-zA-Z0-9._:-]{1,191}\z/',$value['provider_job_id'])!==1||!hash_equals((string)($record['accepted_provider_job_id']??''),$value['provider_job_id']))throw new ApiProblem('SAND_AI_EXTERNAL_AUDIO_RECEIPT_INVALID','External audio accepted receipt is invalid');
        $adapter->preflight($request); $result=$adapter->query($value['provider_job_id']); if($result===null)return ['state'=>'accepted'];
        if(array_keys($result)!==['result_receipt']||!is_string($result['result_receipt']))throw new ApiProblem('SAND_AI_EXTERNAL_AUDIO_RESPONSE_INVALID','External audio recovery response is invalid');
        try { $canonical=json_decode($result['result_receipt'],true,32,JSON_THROW_ON_ERROR); } catch (\Throwable) { throw new ApiProblem('SAND_AI_EXTERNAL_AUDIO_RESPONSE_INVALID','External audio recovery response is invalid'); }
        if(!is_array($canonical)) throw new ApiProblem('SAND_AI_EXTERNAL_AUDIO_RESPONSE_INVALID','External audio recovery response is invalid');
        ExternalAudioRecoveryPolicy::assertCanonicalResult($canonical,$request->driverCode,$request->driverVersion);
        (new AudioTaskResultPresenter())->present($canonical,$request->driverCode,$request->driverVersion);
        $this->store->persistResultRecovery($dispatchId,$claim,$this->cipher->encrypt('result',$result['result_receipt'],$this->context($record,$request))); return ['state'=>'recovery_pending'];
    }
    /** @param array<string,mixed> $r @return array<string,mixed> */
    private function context(array $r,ExternalAudioDispatchRequest $q):array{return ['environment_id'=>(int)$r['environment_id'],'task_run_id'=>(int)$r['task_run_id'],'dispatch_id'=>(int)$r['id'],'request_fingerprint'=>$q->fingerprint(),'logical_execution_id'=>$q->logicalExecutionId,'provider_id'=>$q->providerId,'model_id'=>$q->modelId,'deployment_id'=>$q->deploymentId,'profile_id'=>$q->profileId,'profile_revision'=>$q->profileRevision,'route_id'=>$q->routeId,'credential_revision_id'=>$q->credentialRevisionId,'credential_digest'=>$q->credentialDigest,'price_snapshot_hash'=>$q->priceSnapshotHash()];}
}
