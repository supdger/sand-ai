<?php

declare(strict_types=1);

namespace app\Application\Audio;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\RequestPayloadFingerprint;
use app\Application\Task\AiTaskGateway;
use app\Application\Task\TaskExecutionLease;
use app\Application\Task\TaskLeasePolicy;
use app\Application\Usage\UsageAttribution;
use app\Identity\Infrastructure\AudioTaskAccessGuard;
use app\Identity\Infrastructure\TaskRunAccessGuard;
use app\Infrastructure\Persistence\Model\TaskRun;
use think\facade\Db;

/** Typed seal/aggregation source port independent of completed worker enrollment. */
final class MeetingSummarySourceReader
{
    public function authorize(TaskRun $task,TaskExecutionLease $lease):array
    {
        $input=$this->input($task);$meeting=$input['_meeting_summary'];
        if(is_array($meeting['aggregation_source']??null)) {
            return (new PostgresMeetingAggregationSource())->authorize($task,$lease);
        }
        $this->lease($task,$lease);
        (new TaskRunAccessGuard())->revalidateQueued($input['iam_authorization'],$task);
        $attribution=AiTaskGateway::attributionFromPersistedInput($input,(int)$task->environment_id);
        AiTaskGateway::assertCurrentSourceFacts($task,$attribution);
        $identity=UsageAttribution::fromCurrentPersisted($input['_usage_attribution'],(int)$task->environment_id,'capability','sand_ai.audio.transcription');
        $seal=AudioTranscriptSealLedger::normalizeReference($meeting['transcript_seal']??null);
        if($seal===null)self::invalid();
        $facts=(new MeetingSummarySource())->current((int)$task->environment_id,(int)$meeting['audio_task_id'],$identity,(string)$meeting['sha256'],(int)$meeting['review_revision'],$seal);
        if((int)$facts['file_id']!==(int)$meeting['file_id'])self::invalid();
        (new AudioTaskAccessGuard())->revalidateQueued($meeting['audio_authorization'],$facts['task']);
        $this->lease($task,$lease);return $facts;
    }

    /** Direct original positions; private bodies bounded to 32, never complete JSON hydration. */
    public function readPositions(TaskRun $task,TaskExecutionLease $lease,array $positions):array
    {
        if(!array_is_list($positions)||count($positions)<1||count($positions)>32)self::invalid();
        $previous=-1;foreach($positions as $p){if(!is_int($p)||$p<0||$p>9999||$p<=$previous)self::invalid();$previous=$p;}
        $input=$this->input($task);$meeting=$input['_meeting_summary'];
        if(is_array($meeting['aggregation_source']??null)) {
            $result=(new PostgresMeetingAggregationSource())->readPositions($task,$lease,$positions);
            if(!is_array($result['sources']??null))self::invalid();return $result['sources'];
        }
        $facts=$this->authorize($task,$lease);
        $audioInput=is_object($facts['task']->input)?(array)$facts['task']->input:$facts['task']->input;
        $fileHash=is_array($audioInput)?($audioInput['file_sha256']??null):null;
        if(!is_string($fileHash))self::invalid();
        $holders=implode(',',array_fill(0,count($positions),'(CAST(? AS integer))'));
        $sql="WITH positions(position_no) AS (VALUES $holders) SELECT p.position_no,
            CASE WHEN octet_length(projected.segment::text)<=65536 THEN projected.segment::text ELSE NULL END AS segment
            FROM positions p JOIN sand_ai_audio_transcript_seal seal ON seal.id=?
            JOIN sand_ai_task_run audio ON audio.id=seal.audio_task_id
            CROSS JOIN LATERAL (SELECT audio.result->'segments'->p.position_no AS segment) original
            LEFT JOIN LATERAL (SELECT id,review_text FROM sand_ai_audio_segment_review WHERE audio_task_id=audio.id AND segment_index=p.position_no AND revision<=seal.review_revision ORDER BY revision DESC LIMIT 1) correction ON true
            LEFT JOIN LATERAL (SELECT id,review_speaker FROM sand_ai_audio_segment_review WHERE audio_task_id=audio.id AND segment_index=p.position_no AND revision<=seal.review_revision AND speaker_changed ORDER BY revision DESC LIMIT 1) speaker ON true
            CROSS JOIN LATERAL (SELECT jsonb_build_object('text',CASE WHEN correction.id IS NULL THEN original.segment->>'text' ELSE btrim(correction.review_text,E' \\t\\n\\r\\013') END,
                'speaker',CASE WHEN speaker.id IS NULL THEN original.segment->'speaker' ELSE to_jsonb(speaker.review_speaker) END,
                'start_ms',original.segment->'start_ms','end_ms',original.segment->'end_ms','source',original.segment->'source','warnings',original.segment->'warnings') AS segment) projected
            WHERE seal.content_purged_time IS NULL AND audio.content_purged_time IS NULL AND audio.status=1 AND audio.delete_time IS NULL
                AND seal.environment_id=? AND audio.environment_id=seal.environment_id AND seal.total_segments=? ORDER BY p.position_no";
        $rows=Db::connect('pgsql')->query($sql,array_merge($positions,[$meeting['transcript_seal']['id'],(int)$task->environment_id,$facts['total_segments']]));
        if(count($rows)!==count($positions))self::invalid();$result=[];
        foreach($rows as $offset=>$row){
            if((int)$row['position_no']!==$positions[$offset]||!is_string($row['segment'])||strlen($row['segment'])>65536)self::invalid();
            $segment=json_decode($row['segment'],true,32,JSON_THROW_ON_ERROR);$index=$positions[$offset];
            if(!is_array($segment)||!is_string($segment['text']??null)||preg_match('//u',$segment['text'])!==1
                ||mb_strlen($segment['text'],'UTF-8')>10000||!is_int($segment['start_ms']??null)||!is_int($segment['end_ms']??null)
                ||$segment['start_ms']<0||$segment['end_ms']<=$segment['start_ms']||!is_array($segment['source']??null)
                ||!is_array($segment['warnings']??null)||(!is_string($segment['speaker']??null)&&($segment['speaker']??null)!==null))self::invalid();
            AudioTimeRangeLocator::normalize($segment['source']['locator']??null,$segment['start_ms'],$segment['end_ms']);
            if(($segment['source']['file_ref']??null)!==$facts['file_ref']||!is_string($segment['source']['content_sha256']??null)
                ||$segment['source']['content_sha256']!==$fileHash
                ||preg_match('/\A[a-f0-9]{64}\z/D',$segment['source']['content_sha256'])!==1)self::invalid();
            if(trim($segment['text'])===''&&!in_array('no_recognized_speech',$segment['warnings'],true))self::invalid();
            $proof=['segment_id'=>'S'.($index+1),'text_sha256'=>hash('sha256',$segment['text']),
                'unicode_characters'=>mb_strlen($segment['text'],'UTF-8'),'source_sha256'=>RequestPayloadFingerprint::fingerprint([
                    'speaker'=>$segment['speaker']??null,'start_ms'=>$segment['start_ms'],'end_ms'=>$segment['end_ms'],'source'=>$segment['source']],[]),
                'exclusion'=>trim($segment['text'])===''?'no_usable_text':null];
            $result[]=['segment_index'=>$index,'proof'=>$proof,'private_segment'=>$segment];
        }
        $this->authorize($task,$lease);return $result;
    }

    private function lease(TaskRun $task,TaskExecutionLease $lease):void
    {
        if((int)$task->id!==$lease->taskId)self::invalid();
        $rows=Db::connect('pgsql')->query("SELECT id FROM sand_ai_task_run WHERE id=? AND environment_id=? AND state='running' AND status=1 AND delete_time IS NULL AND content_purged_time IS NULL AND lease_owner=? AND lease_token=? AND lease_fence=? AND lease_expire_time>(clock_timestamp() AT TIME ZONE ?)",[$lease->taskId,(int)$task->environment_id,$lease->owner,$lease->token,$lease->fence,TaskLeasePolicy::runningTimezone()]);
        if(count($rows)!==1)throw new ApiProblem('SAND_AI_TASK_LEASE_LOST','会议准备任务租约已变更');
    }
    private function input(TaskRun $task):array
    {
        $input=is_object($task->input)?(array)$task->input:$task->input;
        if(!is_array($input)||!is_array($input['_meeting_summary']??null)||!is_array($input['iam_authorization']??null)
            ||!is_array($input['_usage_attribution']??null)||!is_array($input['_meeting_summary']['audio_authorization']??null))self::invalid();return $input;
    }
    private static function invalid():never
    {
        throw new ApiProblem('SAND_AI_AUDIO_SUMMARY_SOURCE_INVALID','会议私有来源已变化或不可用');
    }
}
