<?php

declare(strict_types=1);

namespace app\Application\Task;

use app\Api\Support\ApiProblem;
use app\Application\Audio\AudioTranscriptionRequestPolicy;
use app\Application\Audio\AudioTranscriptSealLedger;
use app\Application\Audio\AudioBudgetGuard;
use app\Application\Audio\ControlledAudioInput;
use app\Application\Audio\LocalAudioDurationProbe;
use app\Application\Audio\External\FrozenAudioTaskInputPolicy;
use app\Application\Audio\AudioTranscriptionService;
use app\Application\Audio\External\DisabledExternalAudioTaskExecutor;
use app\Application\Audio\External\ExternalAudioTaskExecutor;
use app\Application\Audio\External\PostgresExternalAudioTaskExecutor;
use app\Application\Audio\FrozenAudioReferenceVerifier;
use app\Application\Audio\LoadedLocalAudioDriver;
use app\Application\Audio\PostgresPrivateAudioFileLoader;
use app\Application\Audio\VerifiedPrivateAudioReference;
use app\Application\Audio\WhisperCppAudioTranscriptionDriver;
use app\Application\Audio\WhisperCppExecutionConfig;
use app\Application\Capability\CapabilityProfileResolver;
use app\Application\Usage\AudioCallStartPricingSnapshot;
use app\Application\Usage\AudioExecutionTarget;
use app\Application\Usage\AudioPriceResolver;
use app\Application\Usage\AudioSeconds;
use app\Application\Usage\ResolvedAudioPrice;
use app\Application\Usage\AudioUsageMeter;
use app\Application\Usage\AudioUsageRequest;
use app\Application\Usage\PostgresAudioPriceRevisionStore;
use app\Application\Usage\PostgresAudioUsageEventStore;
use app\Application\Usage\UsageAttribution;
use app\Application\Gateway\RequestIdempotencyKey;
use app\Identity\Infrastructure\AudioTaskAccessGuard;
use app\Infrastructure\Persistence\Model\AiFile;
use app\Infrastructure\Persistence\Model\AiModel;
use app\Infrastructure\Persistence\Model\ModelDeployment;
use app\Infrastructure\Persistence\Model\Provider;
use app\Infrastructure\Persistence\Model\TaskRun;
use app\Infrastructure\Persistence\Model\TaskStep;
use think\facade\Db;

/** Local-only audio TaskRun path. It deliberately has no Invocation/dispatch ledger. */
final class AudioTaskGateway
{
    public function __construct(private readonly ?ExternalAudioTaskExecutor $externalExecutor = null) {}
    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function submit(int $environmentId, array $input, string $requestId, UsageAttribution $attribution, array $iamAuthorization): array
    {
        return Db::connect('pgsql')->transaction(function () use ($environmentId, $input, $requestId, $attribution, $iamAuthorization): array {
            $request=(new AudioTranscriptionRequestPolicy())->normalize($input);
            $existing=TaskRun::where('environment_id',$environmentId)->where('request_id',$requestId)->find();
            if ($existing !== null) {
                $persisted=is_array($existing->input)?$existing->input:(array)$existing->input;
                if ((string)$existing->task_type !== 'audio_transcription'
                    || !\app\Application\Audio\AudioSnapshot::same($persisted['request']??null,$request)
                    || !\app\Application\Audio\AudioSnapshot::same($persisted['usage_attribution']??null,$attribution->toPersisted())
                    || ($persisted['file_id']??null)!==($iamAuthorization['file_id']??null)) {
                    throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT','The idempotency key belongs to a different audio task');
                }
                $result = ['idempotent_replay'=>true,'task'=>(new TaskGateway())->summary($existing)];
                $this->revalidateSubmission($existing, $iamAuthorization, true);
                return $result;
            }
            $prepared=$this->prepared($environmentId,$request,$attribution);
            if (($iamAuthorization['file_id']??null)!==$prepared['file_id']) throw new ApiProblem('SAND_AI_AUDIO_REFERENCE_FORBIDDEN','The authorized private audio file changed');
            $prepared['iam_authorization']=$iamAuthorization;
            $prepared=FrozenAudioTaskInputPolicy::assert($prepared,$environmentId);
            $task=TaskRun::create(['environment_id'=>$environmentId,'task_type'=>'audio_transcription','resource_type'=>'audio_transcription','resource_id'=>0,'request_id'=>$requestId,'state'=>TaskState::QUEUED,'attempt_count'=>0,'max_attempts'=>max(1,(int)config('sand_ai_task.max_attempts',3)),'available_at'=>date('Y-m-d H:i:s'),'input'=>$prepared,'status'=>1]);
            $stepCode=in_array($prepared['execution_driver']['code']??null,['openai_compatible_audio','glm_asr_audio'],true)?'external_audio_transcribe':'local_transcribe';
            $task->save(['resource_id'=>(int)$task->id]); TaskStep::create(['task_id'=>(int)$task->id,'step_code'=>$stepCode,'sequence_no'=>1,'state'=>TaskState::QUEUED,'attempt_count'=>0,'context'=>['capability_code'=>'audio_transcription','driver_code'=>$prepared['execution_driver']['code']],'status'=>1]);
            $result = ['idempotent_replay'=>false,'task'=>(new TaskGateway())->summary($task)];
            $this->revalidateSubmission($task, $iamAuthorization, false);
            return $result;
        });
    }

    /** Replaying a task never renews its original queued context; recheck the current caller after preparing the response.
     * @param array<string,mixed> $currentAuthorization
     */
    private function revalidateSubmission(TaskRun $task, array $currentAuthorization, bool $existing): void
    {
        $access = new AudioTaskAccessGuard();
        if ($existing && in_array((string) $task->state, [TaskState::QUEUED, TaskState::RETRYING], true)) {
            $input = is_object($task->input) ? (array) $task->input : $task->input;
            $originalAuthorization = is_array($input) ? ($input['iam_authorization'] ?? null) : null;
            if (!is_array($originalAuthorization)) {
                throw new ApiProblem('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE', 'Queued audio identity context is unavailable');
            }
            $access->revalidateQueued($originalAuthorization, $task);
        }
        $access->revalidateQueued($currentAuthorization, $task);
    }

    /** @return array<string,mixed> */
    public function executeTask(TaskRun $task, TaskExecutionLease $lease): array
    {
        $input=is_array($task->input)?$task->input:(array)$task->input;
        $executionRequest = (new AudioTranscriptionRequestPolicy())->fromPersisted($input['request'] ?? []);
        $request = (new AudioTranscriptionRequestPolicy())->normalize($executionRequest);
        (new AudioTaskAccessGuard())->revalidateQueued(is_array($input['iam_authorization']??null)?$input['iam_authorization']:[],$task);
        $driverCode=(string)($input['execution_driver']['code']??'');
        if (in_array($driverCode, ['openai_compatible_audio', 'glm_asr_audio'], true)) {
            if ($request['data_egress_policy'] !== 'approved_cloud' || (bool)config('sand_ai_audio_execution.external_enabled',false)!==true) throw new ApiProblem('SAND_AI_AUDIO_NOT_CONFIGURED','OpenAI-compatible audio is not configured');
            $chunks = new \app\Application\Audio\Chunk\AudioChunkTaskRunner();
            if ($chunks->selected($task, $input)) return $chunks->advance($task, $lease);
            return ($this->externalExecutor ?? new PostgresExternalAudioTaskExecutor())->execute((int)$task->environment_id,(int)$task->id,$lease,$input);
        }
        if ($driverCode !== 'local_whisper_cpp' || !in_array($request['data_egress_policy'], ['local_only', 'private_only', 'approved_cloud'], true)) throw new ApiProblem('SAND_AI_DATA_EGRESS_FORBIDDEN','Audio tasks require a configured route');
        $pricing=$this->pricing($input['pricing_snapshot']??null); $target=$this->target($input['execution_target']??null);
        $this->assertCurrentRoute((int)$task->environment_id,$target);
        $attribution=UsageAttribution::fromCurrentPersisted($input['usage_attribution']??[],(int)$task->environment_id,'capability','sand_ai.audio.transcription');
        $reference=new VerifiedPrivateAudioReference($request['file_ref'],(string)($input['file_media_type']??''),(string)($input['file_sha256']??''),RequestIdempotencyKey::trustedScopeForAttribution($attribution));
        $driver=new WhisperCppAudioTranscriptionDriver(WhisperCppExecutionConfig::fromConfig((array)config('sand_ai_audio_execution.local_whisper_cpp',[])));
        $executionId = 'task:' . (int)$task->id . ':attempt:' . $lease->fence;
        $verifiedSeconds = (new PostgresPrivateAudioFileLoader())->withInput((int)$task->environment_id,$reference,
            fn (ControlledAudioInput $controlled): AudioSeconds => new AudioSeconds((new LocalAudioDurationProbe())->seconds(
                (string)config('sand_ai_audio_execution.local_whisper_cpp.probe_executable',''),$controlled->temporaryPath,30)));
        $anchor = Db::connect('pgsql')->transaction(function () use ($task,$lease,$executionId,$attribution,$target,$pricing,$verifiedSeconds,$input): ?int {
            $anchor = (new AudioBudgetGuard())->reserve(null,(int)$task->id,$executionId,$attribution,$target->model['id'],$pricing,$verifiedSeconds,hash('sha256',$this->canonical($input)));
            $locked = TaskRun::where('id',(int)$task->id)->where('state',TaskState::RUNNING)->where('lease_owner',$lease->owner)->where('lease_token',$lease->token)->where('lease_fence',$lease->fence)->lock(true)->find();
            if ($locked === null) { throw new ApiProblem('SAND_AI_TASK_LEASE_LOST','Audio execution lease was lost before compute'); }
            return $anchor;
        });
        try {
        $sourced=(new AudioTranscriptionService(new FrozenAudioReferenceVerifier($reference),new LoadedLocalAudioDriver((int)$task->environment_id,new PostgresPrivateAudioFileLoader(),$driver)))->transcribe($executionRequest);
        } catch (\Throwable $error) {
            Db::connect('pgsql')->transaction(fn () => (new AudioBudgetGuard())->finish($anchor,null,null));
            throw $error;
        }
        $result=(new AudioTaskResultPresenter())->present($sourced); $seconds=new AudioSeconds($result['usage']['audio_seconds']);
        Db::connect('pgsql')->transaction(function () use ($task,$lease,$attribution,$target,$seconds,$pricing,$result,$executionId): void { (new AudioUsageMeter(new PostgresAudioUsageEventStore()))->meter(new AudioUsageRequest((int)$task->id,$attribution,$target->provider['id'],$target->model['id'],$target->deployment['id'],$target,$seconds,$executionId),$pricing); $now=date('Y-m-d H:i:s'); $updated=TaskRun::where('id',(int)$task->id)->where('state',TaskState::RUNNING)->where('lease_owner',$lease->owner)->where('lease_token',$lease->token)->where('lease_fence',$lease->fence)->update(['state'=>TaskState::SUCCEEDED,'result'=>$result,'completed_at'=>$now,'locked_at'=>null,'locked_by'=>null,'lease_owner'=>null,'lease_token'=>null,'lease_expire_time'=>null,'heartbeat_time'=>null]); $step=TaskStep::where('task_id',(int)$task->id)->where('sequence_no',1)->where('state',TaskState::RUNNING)->update(['state'=>TaskState::SUCCEEDED,'completed_at'=>$now]); TaskLeaseTerminalGuard::assertUpdated($updated,$step,'audio completion'); (new AudioTranscriptSealLedger())->sealBase((int)$task->id); });
        return $result+['_terminal_committed'=>true];
    }

    /** @param array<string,mixed> $request @return array<string,mixed> */
    private function prepared(int $environmentId,array $request,UsageAttribution $attribution): array
    {
        $file=$this->audioFile($environmentId,$request['file_ref'],$attribution,true);
        $route=(new CapabilityProfileResolver())->primary($environmentId,'audio_transcription'); $driverCode=(string)($route['driver_code']??''); if($route===null || ($route['route_kind']??null)!=='audio_driver' || !in_array($driverCode,['local_whisper_cpp','openai_compatible_audio','glm_asr_audio'],true)) throw new ApiProblem('SAND_AI_AUDIO_NOT_CONFIGURED','No audio route is published');
        $deployment=ModelDeployment::where('id',(int)$route['deployment_id'])->where('status',1)->find(); $model=$deployment===null?null:AiModel::where('id',(int)$deployment->model_id)->where('status',1)->find(); $provider=$deployment===null?null:Provider::where('id',(int)$deployment->provider_id)->where('status',1)->find(); if($deployment===null||$model===null||$provider===null) throw new ApiProblem('SAND_AI_AUDIO_NOT_CONFIGURED','The local audio cost target is unavailable');
        $target=AudioExecutionTarget::fromSnapshots((int)$provider->id,(int)$model->id,(int)$deployment->id,['id'=>(int)$provider->id,'code'=>(string)$provider->code,'name'=>(string)$provider->getAttr('name')],['id'=>(int)$model->id,'code'=>(string)$model->code,'name'=>(string)$model->getAttr('name')],['id'=>(int)$deployment->id,'remote_model'=>(string)$deployment->remote_model,'revision'=>(int)$deployment->revision,'route'=>['selection_kind'=>'capability_profile','profile_id'=>(int)$route['profile_id'],'profile_revision'=>(int)$route['profile_revision'],'route_id'=>(int)$route['route_id']]]);
        $pricing=AudioCallStartPricingSnapshot::resolve(new AudioPriceResolver(new PostgresAudioPriceRevisionStore()),(int)$provider->id,(int)$model->id,gmdate('Y-m-d H:i:s'));
        $external = in_array($driverCode, ['openai_compatible_audio', 'glm_asr_audio'], true); if ($external && $request['data_egress_policy'] !== 'approved_cloud') throw new ApiProblem('SAND_AI_DATA_EGRESS_FORBIDDEN','Audio tasks require the published data egress policy');
        if ($driverCode === 'local_whisper_cpp' && $request['speaker_diarization']) {
            throw new ApiProblem('SAND_AI_AUDIO_DIARIZATION_UNAVAILABLE', 'The published local audio route cannot identify speakers');
        }
        if ($external && (bool)config('sand_ai_audio_execution.external_enabled',false)!==true) throw new ApiProblem('SAND_AI_AUDIO_NOT_CONFIGURED','OpenAI-compatible audio is not configured');
        if ($driverCode==='openai_compatible_audio' && $request['speaker_diarization'] && $request['glossary'] !== []) throw new ApiProblem('SAND_AI_AUDIO_TRANSCRIPTION_VALIDATION_ERROR','The diarized audio model does not support glossary prompts');
        if ($driverCode==='openai_compatible_audio' && $request['language'] !== 'auto'
            && preg_match('/\A[a-z]{2}\z/D', explode('-', $request['language'], 2)[0]) !== 1) {
            throw new ApiProblem('SAND_AI_AUDIO_TRANSCRIPTION_VALIDATION_ERROR','External transcription requires an ISO 639-1 language');
        }
        if ($driverCode === 'glm_asr_audio') {
            \app\Application\Audio\External\GlmAsrAudioInputPolicy::assertParameters($request);
            if (!in_array((string) $file->media_type, ['audio/wav', 'audio/mpeg'], true)) {
                throw new ApiProblem('SAND_AI_AUDIO_TRANSCRIPTION_MEDIA_UNSUPPORTED', 'GLM ASR accepts WAV or MP3 recordings only');
            }
            if ((string) $deployment->remote_model !== 'glm-asr-2512') {
                throw new ApiProblem('SAND_AI_AUDIO_NOT_CONFIGURED', 'The published GLM ASR model is unsupported');
            }
        }
        if ($driverCode === 'openai_compatible_audio') {
            $remoteModel = (string) $deployment->remote_model;
            $expectedModel = $request['speaker_diarization'] ? 'gpt-4o-transcribe-diarize' : 'whisper-1';
            if ($remoteModel !== $expectedModel) {
                throw new ApiProblem('SAND_AI_AUDIO_NOT_CONFIGURED', 'The published audio model does not support the requested transcription mode');
            }
        }
        return ['request'=>$request,'file_sha256'=>(string)$file->sha256,'file_media_type'=>(string)$file->media_type,'usage_attribution_version'=>'audio_usage_attribution@1','execution_target_version'=>'audio_execution_target@1','usage_attribution'=>$attribution->toPersisted(),'_trusted_identity_scope'=>self::trustedIdentityScope($attribution),'execution_target'=>$target->persistence(),'pricing_snapshot'=>$pricing->persistence(),'execution_driver'=>['code'=>$driverCode,'version'=>'1.0.0'],'file_id'=>(int)$file->id];
    }
    /** @param array<string,mixed> $input */
    public function fileIdForAuthorization(int $environmentId,array $input,UsageAttribution $attribution): int
    {
        $request=(new AudioTranscriptionRequestPolicy())->normalize($input);
        return (int)$this->audioFile($environmentId,$request['file_ref'],$attribution)->id;
    }
    private function audioFile(int $environmentId,string $fileRef,UsageAttribution $attribution,bool $lock=false): AiFile
    {
        $query=AiFile::where('environment_id',$environmentId)->where('file_ref',$fileRef)->where('owner_scope',self::trustedIdentityScope($attribution))->where('state','uploaded')->where('status',1)->whereNull('delete_time');
        $file=($lock?$query->lock(true):$query)->find();
        if($file===null) throw new ApiProblem('SAND_AI_AUDIO_REFERENCE_FORBIDDEN','The private audio file is unavailable');
        return $file;
    }
    private function pricing(mixed $value): AudioCallStartPricingSnapshot { if(!is_array($value)||!is_string($value['called_at']??null)) throw new ApiProblem('SAND_AI_PRICING_SNAPSHOT_INVALID','Audio pricing snapshot is missing'); if(($value['pricing_state']??null)==='unpriced') return AudioCallStartPricingSnapshot::unpriced($value['called_at']); $snapshot=$value['price_snapshot']??null; if(($value['pricing_state']??null)!=='priced'||!is_int($value['price_revision_id']??null)||!is_array($snapshot)||!is_string($snapshot['currency']??null)||!is_string($snapshot['effective_from']??null)||(($snapshot['effective_until']??null)!==null&&!is_string($snapshot['effective_until']))||!is_array($snapshot['rates']['audio_seconds']??null)||!is_string($snapshot['rates']['audio_seconds']['unit_price']??null)||!is_int($snapshot['rates']['audio_seconds']['per_quantity']??null)) throw new ApiProblem('SAND_AI_PRICING_SNAPSHOT_INVALID','Audio priced retry snapshot is invalid'); return AudioCallStartPricingSnapshot::priced($value['called_at'],new ResolvedAudioPrice($value['price_revision_id'],$snapshot['currency'],['unit_price'=>$snapshot['rates']['audio_seconds']['unit_price'],'per_quantity'=>$snapshot['rates']['audio_seconds']['per_quantity']],$snapshot['effective_from'],$snapshot['effective_until'])); }
    private function target(mixed $value): AudioExecutionTarget { if(!is_array($value)) throw new ApiProblem('SAND_AI_AUDIO_TARGET_INVALID','Audio target is missing'); return AudioExecutionTarget::fromPersistence($value); }
    public static function trustedIdentityScope(UsageAttribution $attribution): string { return RequestIdempotencyKey::trustedScopeForAttribution($attribution); }
    private function assertCurrentRoute(int $environmentId, AudioExecutionTarget $target): void { $route=(new CapabilityProfileResolver())->primary($environmentId,'audio_transcription'); if($route===null||($route['route_kind']??null)!=='audio_driver'||($route['driver_code']??null)!=='local_whisper_cpp') throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID','The frozen local audio route changed'); $deployment=ModelDeployment::where('id',(int)($route['deployment_id']??0))->where('status',1)->find(); $model=$deployment===null?null:AiModel::where('id',(int)$deployment->model_id)->where('status',1)->find(); $provider=$deployment===null?null:Provider::where('id',(int)$deployment->provider_id)->where('status',1)->find(); if($deployment===null||$model===null||$provider===null) throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID','The frozen local audio target changed'); $current=AudioExecutionTarget::fromSnapshots((int)$provider->id,(int)$model->id,(int)$deployment->id,['id'=>(int)$provider->id,'code'=>(string)$provider->code,'name'=>(string)$provider->getAttr('name')],['id'=>(int)$model->id,'code'=>(string)$model->code,'name'=>(string)$model->getAttr('name')],['id'=>(int)$deployment->id,'remote_model'=>(string)$deployment->remote_model,'revision'=>(int)$deployment->revision,'route'=>['selection_kind'=>'capability_profile','profile_id'=>(int)($route['profile_id']??0),'profile_revision'=>(int)($route['profile_revision']??0),'route_id'=>(int)($route['route_id']??0)]]); if(!\app\Application\Audio\AudioSnapshot::same($current->persistence(),$target->persistence())) throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID','The frozen local audio target changed'); }
    private function canonical(mixed $value): string { return json_encode($value,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION); }
}
