<?php

declare(strict_types=1);

namespace app\Application\Audio;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\RequestPayloadFingerprint;
use app\Application\Task\TaskExecutionLease;
use app\Application\Task\TaskLeasePolicy;
use app\Infrastructure\Persistence\Model\TaskRun;
use think\facade\Db;

/** Registered 111 source: immutable private pages and fenced preparation state. */
final class PostgresMeetingPlanStore
{
    public const VERSION = '202610030111_meeting_durable_plan';
    public const PATH = 'plugin/sand-ai/migrations/main-app/' . self::VERSION . '.pgsql';

    public function loadOrEnroll(TaskRun $task, TaskExecutionLease $lease): array
    {
        return $this->stage($task, $lease, function () use ($task,$lease): array {
            $rows = Db::connect('pgsql')->query('SELECT * FROM sand_ai_meeting_plan_checkpoint WHERE task_id=? FOR UPDATE', [(int) $task->id]);
            if ($rows !== []) return $this->head($rows[0]);
            $facts = (new MeetingSummarySourceReader())->authorize($task, $lease);
            $input = self::input($task); $source = $input['_meeting_summary']; unset($source['audio_authorization']);
            $anchor = ['source' => $source, 'capacity' => $input['_meeting_capacity'], 'route' => $input['_meeting_route'],
                'total_segments' => $facts['total_segments'], 'eligible_segments' => $facts['eligible_segments'],
                'owner_scope_sha256' => RequestPayloadFingerprint::fingerprint($input['_usage_attribution'], [])];
            if ($anchor['total_segments'] < 1 || $anchor['total_segments'] > 10000 || ($anchor['eligible_segments'] !== null && ($anchor['eligible_segments'] < 1
                || $anchor['eligible_segments'] > $anchor['total_segments']))) self::invalid();
            $state = ['source_cursor' => 0, 'eligible_segments' => 0, 'leaf_count' => 0, 'source_page_count' => 0,
                'batch' => [], 'batch_chars' => 0, 'pending' => [], 'tokenizer_call_count' => 0,
                'source_chain_sha256' => str_repeat('0',64), 'leaf_chain_sha256' => str_repeat('0',64)];
            $anchorText = self::encode($anchor); $stateText = self::encode($state);
            $rows = Db::connect('pgsql')->query('INSERT INTO sand_ai_meeting_plan_checkpoint (task_id,anchor,anchor_text,anchor_sha256,state_json,state_text,state_sha256) VALUES (?,?,?,?,?,?,?) RETURNING *',
                [(int) $task->id, $anchorText, $anchorText, hash('sha256',$anchorText), $stateText, $stateText, hash('sha256',$stateText)]);
            if (count($rows) !== 1) self::invalid();
            return $this->head($rows[0]);
        });
    }

    public function advance(TaskRun $task, TaskExecutionLease $lease, array $old, array $state, ?array $page, bool $complete): array
    {
        self::stateBounds($state);
        return $this->stage($task, $lease, function () use ($task,$lease,$old,$state,$page,$complete): array {
            $rows = Db::connect('pgsql')->query('SELECT * FROM sand_ai_meeting_plan_checkpoint WHERE task_id=? FOR UPDATE', [(int)$task->id]);
            if (count($rows)!==1) self::invalid(); $head = $this->head($rows[0]);
            if ($head['phase']!=='preparing' || $head['event_sequence']!==$old['event_sequence']
                || !hash_equals($head['state_sha256'],$old['state_sha256'])) self::invalid();
            $pageId = null; $pageHash = null;
            if ($page!==null) {
                $text=self::encode($page['entries']);
                if (strlen($text)>3145728 || count($page['entries'])<1 || count($page['entries'])>32) self::invalid();
                $saved=Db::connect('pgsql')->query('INSERT INTO sand_ai_meeting_plan_page (task_id,page_kind,sequence_no,entry_count,payload_sha256,private_payload,payload_text) VALUES (?,?,?,?,?,?,?) RETURNING id,payload_sha256',
                    [(int)$task->id,$page['kind'],$page['sequence'],count($page['entries']),hash('sha256',$text),$text,$text]);
                if(count($saved)!==1)self::invalid(); $pageId=(int)$saved[0]['id'];$pageHash=$saved[0]['payload_sha256'];
            }
            $phase=$complete?'complete':'preparing';
            $next=$head; $next['state']=$state; $next['phase']=$phase;
            $manifest=$complete ? RequestPayloadFingerprint::fingerprint($this->manifestFacts($next),[]) : null;
            $stateText=self::encode($state);
            $event=['task_id'=>(int)$task->id,'sequence_no'=>$head['event_sequence']+1,
                'previous_sha256'=>$head['last_event_sha256'],'anchor_sha256'=>$head['anchor_sha256'],
                'previous_state_sha256'=>$head['state_sha256'],'state'=>$state,'state_text'=>$stateText,
                'state_sha256'=>hash('sha256',$stateText),'phase'=>$phase,
                'page_id'=>$pageId,'payload_sha256'=>$pageHash,'manifest_sha256'=>$manifest,'lease_fence'=>$lease->fence];
            $eventText=self::encode($event);
            Db::connect('pgsql')->execute('INSERT INTO sand_ai_meeting_plan_event (task_id,sequence_no,previous_sha256,event_sha256,anchor_sha256,page_id,lease_fence,event_text) VALUES (?,?,?,?,?,?,?,?)',
                [(int)$task->id,$event['sequence_no'],$event['previous_sha256'],hash('sha256',$eventText),$head['anchor_sha256'],$pageId,$lease->fence,$eventText]);
            $rows=Db::connect('pgsql')->query('SELECT * FROM sand_ai_meeting_plan_checkpoint WHERE task_id=?',[(int)$task->id]);
            $next=$this->head($rows[0]);
            if(!hash_equals($next['state_sha256'],hash('sha256',$stateText)))self::invalid();
            if($complete) {
                $snapshot=$this->snapshot($next);
                Db::connect('pgsql')->execute("UPDATE sand_ai_task_run SET input=jsonb_set(input,'{_meeting_plan}',CAST(? AS jsonb),true) WHERE id=?",[self::encode($snapshot),(int)$task->id]);
            }
            return $next;
        });
    }

    public function completed(TaskRun $task,TaskExecutionLease $lease):array
    {
        return $this->stage($task,$lease,function()use($task):array{
            $rows=Db::connect('pgsql')->query('SELECT * FROM sand_ai_meeting_plan_checkpoint WHERE task_id=?',[(int)$task->id]);
            if(count($rows)!==1)self::invalid();$head=$this->head($rows[0]);
            if($head['phase']!=='complete'||!is_string($head['manifest_sha256'])
                ||!hash_equals($head['manifest_sha256'],RequestPayloadFingerprint::fingerprint($this->manifestFacts($head),[])))self::invalid();
            return $head;
        });
    }

    public function readPage(TaskRun $task,TaskExecutionLease $lease,string $kind,int $sequence):array
    {
        if(!in_array($kind,['source','leaf'],true)||$sequence<1||$sequence>100000)self::invalid();
        return $this->stage($task,$lease,function()use($task,$kind,$sequence):array{
            $rows=Db::connect('pgsql')->query("SELECT p.private_payload,p.payload_sha256,p.entry_count FROM sand_ai_meeting_plan_page p JOIN sand_ai_meeting_plan_checkpoint c ON c.task_id=p.task_id WHERE p.task_id=? AND p.page_kind=? AND p.sequence_no=? AND c.phase IN ('preparing','complete') AND c.content_purged_time IS NULL",[(int)$task->id,$kind,$sequence]);
            if(count($rows)!==1||!is_string($rows[0]['private_payload'])||strlen($rows[0]['private_payload'])>4194304)self::invalid();
            $entries=json_decode($rows[0]['private_payload'],true,64,JSON_THROW_ON_ERROR);
            if(!is_array($entries)||!array_is_list($entries)||count($entries)<1||count($entries)>32
                ||count($entries)!==(int)$rows[0]['entry_count']||!hash_equals($rows[0]['payload_sha256'],RequestPayloadFingerprint::fingerprint($entries,[])))self::invalid();
            return $entries;
        });
    }

    /** Exact source slots: never hydrate neighboring bodies on the same source page. */
    public function readSourceWindow(TaskRun $task, TaskExecutionLease $lease, array $positions): array
    {
        if (!array_is_list($positions) || count($positions) < 1 || count($positions) > 32) self::invalid();
        $last = -1;
        foreach ($positions as $position) {
            if (!is_int($position) || $position <= $last || $position > 9999) self::invalid();
            $last = $position;
        }
        return $this->stage($task, $lease, function () use ($task, $positions): array {
            $values = implode(',', array_fill(0, count($positions), '(CAST(? AS integer))'));
            $rows = Db::connect('pgsql')->query("WITH requested(position_no) AS (VALUES $values)
                SELECT requested.position_no,
                    CASE WHEN octet_length(slot.entry::text)<=73728 THEN slot.entry::text ELSE NULL END AS entry
                FROM requested JOIN sand_ai_meeting_plan_page p ON p.task_id=? AND p.page_kind='source'
                    AND p.sequence_no=requested.position_no/32+1
                JOIN sand_ai_meeting_plan_checkpoint c ON c.task_id=p.task_id
                JOIN sand_ai_meeting_plan_event e ON e.page_id=p.id AND e.task_id=p.task_id
                CROSS JOIN LATERAL (SELECT p.private_payload->(requested.position_no%32) AS entry) slot
                WHERE c.phase IN ('preparing','complete') AND c.content_purged_time IS NULL
                    AND p.private_payload IS NOT NULL ORDER BY requested.position_no", array_merge($positions, [(int)$task->id]));
            if (count($rows)!==count($positions)) self::invalid();
            $entries=[];
            foreach ($rows as $offset=>$row) {
                if ((int)$row['position_no']!==$positions[$offset] || !is_string($row['entry']) || strlen($row['entry'])>73728) self::invalid();
                $entry=json_decode($row['entry'],true,32,JSON_THROW_ON_ERROR);$index=$positions[$offset];
                if (!is_array($entry) || ($entry['segment_index']??null)!==$index || !is_array($entry['proof']??null)
                    || !is_array($entry['private_segment']??null)) self::invalid();
                $segment=$entry['private_segment'];
                if (!is_string($segment['text']??null)||preg_match('//u',$segment['text'])!==1)self::invalid();
                $proof=['segment_id'=>'S'.($index+1),'text_sha256'=>hash('sha256',$segment['text']),
                    'unicode_characters'=>mb_strlen($segment['text'],'UTF-8'),
                    'source_sha256'=>RequestPayloadFingerprint::fingerprint(['speaker'=>$segment['speaker']??null,
                        'start_ms'=>$segment['start_ms'],'end_ms'=>$segment['end_ms'],'source'=>$segment['source']],[]),
                    'exclusion'=>trim($segment['text'])===''?'no_usable_text':null];
                if (!RequestPayloadFingerprint::same($entry['proof'],$proof,[]))self::invalid();
                $entries[]=$entry;
            }
            return $entries;
        });
    }

    public function snapshot(array $head):array
    {
        $facts=$this->manifestFacts($head);$facts['manifest_sha256']=$head['manifest_sha256'];
        $facts['prepared_plan_ref']=['version'=>PostgresMeetingDurablePlanner::VERSION,'task_id'=>$head['task_id'],
            'anchor_sha256'=>$head['anchor_sha256'],'manifest_sha256'=>$head['manifest_sha256']];
        return $facts;
    }

    private function manifestFacts(array $head):array
    {
        $local = MeetingLocalPlanning::enabled($head['anchor']['capacity']);
        return ['version'=>MeetingSummaryPlan::CAPACITY_VERSION,'normalization'=>'none_unicode_codepoint@1',
            'transcript_sha256'=>$head['anchor']['source']['sha256'],'capacity_sha256'=>$head['anchor']['capacity']['snapshot_sha256'],
            'total_segments'=>$head['anchor']['total_segments'],'eligible_segments'=>$head['state']['eligible_segments'],
            'leaf_count'=>$head['state']['leaf_count'],'source_page_count'=>$head['state']['source_page_count'],
            'source_chain_sha256'=>$head['state']['source_chain_sha256'],'leaf_chain_sha256'=>$head['state']['leaf_chain_sha256'],
            'tokenizer_call_count'=>$head['state']['tokenizer_call_count'],'tokenizer_pricing_state'=>$local ? 'not_applicable' : 'unpriced',
            ...($local ? ['planning'=>MeetingLocalPlanning::metadata()] : [])];
    }

    public function assertSchemaReady(): void
    {
        $foundationPath=base_path(PostgresMeetingWorkerLedger::DRAFT_PATH);
        $foundationChecksum=is_file($foundationPath)?hash_file('sha256',$foundationPath):false;
        $foundationReady=Db::connect('pgsql')->query('SELECT checksum FROM sand_ai_schema_migration WHERE version=?',[PostgresMeetingWorkerLedger::VERSION]);
        if(!is_string($foundationChecksum)||count($foundationReady)!==1||!hash_equals($foundationChecksum,(string)$foundationReady[0]['checksum']))self::invalid();
        $path=base_path(self::PATH);$checksum=is_file($path)?hash_file('sha256',$path):false;
        $triggers=Db::connect('pgsql')->query("SELECT count(*) AS total FROM (VALUES ('sand_ai_meeting_plan_checkpoint','sand_ai_meeting_plan_checkpoint_guard'),('sand_ai_meeting_plan_page','sand_ai_meeting_plan_page_guard'),('sand_ai_meeting_plan_page','sand_ai_meeting_plan_page_commit'),('sand_ai_meeting_plan_event','sand_ai_meeting_plan_event_guard'),('sand_ai_meeting_plan_event','sand_ai_meeting_plan_event_head'),('sand_ai_task_run','sand_ai_meeting_plan_task_guard'),('sand_ai_task_run','sand_ai_meeting_plan_purge'),('sand_ai_meeting_plan_tokenizer_counter','sand_ai_meeting_plan_tokenizer_counter_guard'),('sand_ai_meeting_plan_checkpoint','sand_ai_meeting_plan_tokenizer_counter_init'),('sand_ai_meeting_token_count_request','sand_ai_meeting_plan_tokenizer_counter_add'),('sand_ai_meeting_plan_event','sand_ai_meeting_plan_event_commit'),('sand_ai_meeting_plan_checkpoint','sand_ai_meeting_plan_checkpoint_commit')) expected(relation_name,trigger_name) JOIN pg_trigger t ON t.tgrelid=to_regclass(expected.relation_name) AND t.tgname=expected.trigger_name WHERE NOT t.tgisinternal AND t.tgenabled IN ('O','A')");
        if(count($triggers)!==1||(int)$triggers[0]['total']!==12)self::invalid();
        $ready=Db::connect('pgsql')->query('SELECT checksum FROM sand_ai_schema_migration WHERE version=?',[self::VERSION]);
        if(!is_string($checksum)||count($ready)!==1||!hash_equals($checksum,(string)$ready[0]['checksum']))self::invalid();
    }

    private function stage(TaskRun $task,TaskExecutionLease $lease,\Closure $action):array
    {
        if(!MeetingExecutionRuntime::forTask($task) || (int)$task->id!==$lease->taskId)self::invalid();
        $this->assertSchemaReady();
        return Db::connect('pgsql')->transaction(function()use($task,$lease,$action):array{
            Db::connect('pgsql')->query("SELECT set_config('sand_ai.running_timezone',?,true),set_config('sand_ai.worker_owner',?,true),set_config('sand_ai.worker_token',?,true),set_config('sand_ai.worker_fence',?,true)",[TaskLeasePolicy::runningTimezone(),$lease->owner,$lease->token,(string)$lease->fence]);
            Db::connect('pgsql')->query('SELECT (sand_ai_meeting_worker_task(?)).id',[$lease->taskId]);
            (new MeetingSummarySourceReader())->authorize($task,$lease);
            $result=$action();
            (new MeetingSummarySourceReader())->authorize($task,$lease);
            Db::connect('pgsql')->query('SELECT (sand_ai_meeting_worker_task(?)).id',[$lease->taskId]);
            return $result;
        });
    }

    private function head(array $row):array
    {
        if($row['content_purged_time']!==null)self::invalid();
        $row['task_id']=(int)$row['task_id'];$row['event_sequence']=(int)$row['event_sequence'];
        $row['anchor']=is_string($row['anchor'])?json_decode($row['anchor'],true,32,JSON_THROW_ON_ERROR):$row['anchor'];
        $row['state']=is_string($row['state_json'])?json_decode($row['state_json'],true,32,JSON_THROW_ON_ERROR):$row['state_json'];
        if(!is_array($row['anchor'])||!is_array($row['state'])
            ||!hash_equals($row['anchor_sha256'],RequestPayloadFingerprint::fingerprint($row['anchor'],[]))
            ||!hash_equals($row['state_sha256'],RequestPayloadFingerprint::fingerprint($row['state'],[])))self::invalid();
        self::stateBounds($row['state']);return $row;
    }

    private static function stateBounds(array $state):void
    {
        if(strlen(self::encode($state))>262144||count($state['pending']??[])>128||count($state['batch']??[])>512
            ||($state['source_cursor']??-1)<0||$state['source_cursor']>10000||($state['leaf_count']??-1)<0
            ||$state['leaf_count']>100000)self::invalid();
        foreach($state['pending'] as $parts)if(!array_is_list($parts)||count($parts)<1||count($parts)>512)self::invalid();
    }

    private static function input(TaskRun $task):array
    {
        $input=is_object($task->input)?(array)$task->input:$task->input;
        if(!is_array($input)||!is_array($input['_meeting_summary']??null)||!is_array($input['_meeting_capacity']??null)
            ||!is_array($input['_meeting_route']??null)||!is_array($input['_usage_attribution']??null))self::invalid();
        MeetingSummaryCapacity::assert($input['_meeting_capacity']);
        if(!RequestPayloadFingerprint::same($input['_meeting_route'],$input['_meeting_capacity']['route'],[]))self::invalid();
        return $input;
    }

    private static function encode(array $value):string
    {
        return json_encode(self::canonical($value),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    }
    private static function canonical(mixed $value):mixed
    {
        if(is_float($value)||is_object($value)||is_resource($value))self::invalid();
        if(!is_array($value))return $value;$result=[];
        foreach($value as $key=>$item)$result[$key]=self::canonical($item);
        if(!array_is_list($value))ksort($result,SORT_STRING);return $result;
    }
    private static function invalid():never
    {
        throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID','会议计划准备账本未就绪或已清理，保留已有费用事实');
    }
}
