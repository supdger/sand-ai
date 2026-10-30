<?php
declare(strict_types=1);
namespace plugin\SandAi\app\admin\controller;
use app\Api\Support\ApiProblem;
use app\Application\Skill\SkillGateway;
use app\Application\Skill\SkillCaseGateway;
use app\Application\Skill\SkillWorkspaceGateway;
use app\Application\Usage\UsageAttribution;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;
final class SkillController extends SandAiAdminController {
 #[Permission('SandAI 读取 Skill','sand_ai:skill:read')] public function index(Request $r): Response { return $this->readOnly(fn()=>(new SkillWorkspaceGateway())->list($this->queryId($r->get('environment_id')),$this->queryId($r->get('page',1)),$this->queryId($r->get('limit',20)))); }
 #[Permission('SandAI 读取 Skill','sand_ai:skill:read')] public function read(Request $r): Response { return $this->readOnly(fn()=>(new SkillWorkspaceGateway())->read($this->queryId($r->get('environment_id')),$this->queryId($r->get('skill_id')))); }
 #[Permission('SandAI 读取 Skill 资料候选','sand_ai:skill:read')] public function sources(Request $r): Response { return $this->readOnly(fn()=>(new SkillWorkspaceGateway())->sources($this->queryId($r->get('environment_id')),$this->queryId($r->get('page',1)),$this->queryId($r->get('limit',20)))); }
 #[Permission('SandAI 读取 Skill 案例','sand_ai:skill:read')] public function cases(Request $r): Response { return $this->readOnly(fn()=>(new SkillCaseGateway())->list($this->queryId($r->get('environment_id')),$this->queryId($r->get('skill_version_id')))); }
 #[Permission('SandAI 创建 Skill 案例','sand_ai:skill:case:manage')] public function caseCreate(Request $r): Response { return $this->write($r,fn(array $b,string $k)=>(new SkillCaseGateway())->create($this->id($b['environment_id']??null),$this->id($b['skill_id']??null),$this->id($b['skill_version_id']??null),(string)($b['name']??''),$this->array($b['input']??null),(string)($b['expected_criteria']??''),$k,(string)$this->authenticatedAdminId()),'Skill 案例已创建'); }
 #[Permission('SandAI 判定 Skill 案例','sand_ai:skill:case:manage')] public function caseReview(Request $r): Response { return $this->write($r,fn(array $b,string $k)=>(new SkillCaseGateway())->review($this->id($b['environment_id']??null),$this->id($b['case_id']??null),$this->id($b['skill_run_id']??null),(string)($b['verdict']??''),(string)($b['note']??''),$k,(string)$this->authenticatedAdminId()),'Skill 案例已判定'); }
 #[Permission('SandAI 废弃 Skill 案例','sand_ai:skill:case:manage')] public function caseRetire(Request $r): Response { return $this->write($r,fn(array $b,string $k)=>(new SkillCaseGateway())->retire($this->id($b['environment_id']??null),$this->id($b['case_id']??null),(string)($b['reason']??''),$k,(string)$this->authenticatedAdminId()),'Skill 案例已废弃'); }
 #[Permission('SandAI 创建 Skill 草稿','sand_ai:skill:create')] public function createDraft(Request $r): Response { return $this->write($r, fn(array $b,string $k)=>(new SkillGateway())->createDraft($this->id($b['environment_id']??null),$this->without($b,'environment_id'),$k,(string)$this->authenticatedAdminId()),'Skill 草稿已创建'); }
 #[Permission('SandAI 测试 Skill','sand_ai:skill:test')] public function testRun(Request $r): Response { return $this->write($r, fn(array $b,string $k)=>(new SkillGateway())->run($this->id($b['environment_id']??null),(string)($b['code']??''),$this->id($b['version']??null),$this->array($b['input']??null),$k,UsageAttribution::fromEnvironmentFacts($this->id($b['environment_id']??null),'skill_test','sand_ai.skill.run'),true),'Skill 测试已完成'); }
 #[Permission('SandAI 读取原 Skill 测试','sand_ai:skill:test')] public function readTest(Request $r): Response { return $this->readOnly(function () use ($r): array {
     $this->authenticatedAdminId();
     $q=$r->get();if(!is_array($q)||array_diff(array_keys($q),['environment_id','code','version','request_id'])!==[]||!is_string($q['code']??null)||!is_string($q['request_id']??null))throw new ApiProblem('SAND_AI_VALIDATION_ERROR','Skill query is invalid');
     return (new SkillGateway())->readAdminTestRun($this->queryId($q['environment_id']??null),$q['code'],$this->queryId($q['version']??null),$q['request_id']);
 }); }
 #[Permission('SandAI 发布 Skill','sand_ai:skill:publish')] public function publish(Request $r): Response { return $this->write($r, fn(array $b,string $k)=>(new SkillGateway())->publish($this->id($b['environment_id']??null),$this->id($b['skill_id']??null),$k,(string)$this->authenticatedAdminId(),versionNo:array_key_exists('version',$b)?$this->id($b['version']):null),'Skill 已发布'); }
 #[Permission('SandAI 撤销 Skill','sand_ai:skill:revoke')] public function revoke(Request $r): Response { return $this->write($r, fn(array $b,string $k)=>(new SkillGateway())->revoke($this->id($b['environment_id']??null),$this->id($b['skill_id']??null),$k,(string)$this->authenticatedAdminId()),'Skill 已撤销'); }
 #[Permission('SandAI 读取原 Skill 草稿','sand_ai:skill:create')] public function readDraftReceipt(Request $r): Response { return $this->adminOperationReceipt($r,'create_draft',[]); }
 #[Permission('SandAI 读取原 Skill 发布','sand_ai:skill:publish')] public function readPublishReceipt(Request $r): Response { return $this->adminOperationReceipt($r,'publish',['skill_id','version']); }
 #[Permission('SandAI 读取原 Skill 撤销','sand_ai:skill:revoke')] public function readRevokeReceipt(Request $r): Response { return $this->adminOperationReceipt($r,'revoke',['skill_id']); }
 #[Permission('SandAI 读取原 Skill 案例创建','sand_ai:skill:case:manage')] public function readCaseCreateReceipt(Request $r): Response { return $this->adminCaseReceipt($r,'create','skill_version_id'); }
 #[Permission('SandAI 读取原 Skill 案例审核','sand_ai:skill:case:manage')] public function readCaseReviewReceipt(Request $r): Response { return $this->adminCaseReceipt($r,'review','case_id'); }
 #[Permission('SandAI 读取原 Skill 案例废弃','sand_ai:skill:case:manage')] public function readCaseRetireReceipt(Request $r): Response { return $this->adminCaseReceipt($r,'retire','case_id'); }
 private function adminOperationReceipt(Request $r,string $operation,array $targets): Response { return $this->readOnly(function () use($r,$operation,$targets):array {
     $actor=(string)$this->authenticatedAdminId();$q=$this->receiptQuery($r,$targets);
     return (new SkillGateway())->readAdminOperationReceipt($this->queryId($q['environment_id']),$operation,$q['request_id'],$actor,
         isset($q['skill_id'])?$this->queryId($q['skill_id']):null,isset($q['version'])?$this->queryId($q['version']):null);
 }); }
 private function adminCaseReceipt(Request $r,string $operation,string $target): Response { return $this->readOnly(function () use($r,$operation,$target):array {
     $actor=(string)$this->authenticatedAdminId();$q=$this->receiptQuery($r,[$target]);
     return (new SkillCaseGateway())->readAdminReceipt($this->queryId($q['environment_id']),$operation,$q['request_id'],$actor,
         $target==='skill_version_id'?$this->queryId($q[$target]):null,$target==='case_id'?$this->queryId($q[$target]):null);
 }); }
 private function receiptQuery(Request $r,array $targets):array {
     $q=$r->get();$fields=['environment_id','request_id',...$targets];
     if(!is_array($q)||array_diff(array_keys($q),$fields)!==[]||array_diff($fields,array_keys($q))!==[]
         ||!is_string($q['request_id'])||trim($q['request_id'])!==$q['request_id']||$q['request_id']===''||strlen($q['request_id'])>128)
         throw new ApiProblem('SAND_AI_VALIDATION_ERROR','Skill receipt query is invalid');
     return $q;
 }
 private function write(Request $r,callable $fn,string $m): Response { try{$b=$r->post();if(!is_array($b))throw new ApiProblem('SAND_AI_VALIDATION_ERROR','Skill request envelope is invalid');$k=$r->header('idempotency-key');if(!is_string($k)||trim($k)===''||strlen($k)>128)throw new ApiProblem('SAND_AI_VALIDATION_ERROR','Idempotency-Key is invalid');return $this->success($fn($b,trim($k)),$m);}catch(ApiProblem $e){throw new ApiException($e->errorCode.': '.$e->getMessage());} }
 private function readOnly(callable $fn): Response { try{return $this->success($fn());}catch(ApiProblem $e){throw new ApiException($e->errorCode.': '.$e->getMessage());} }
 private function queryId(mixed $v): int { $id=filter_var($v,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if($id===false)throw new ApiProblem('SAND_AI_VALIDATION_ERROR','Skill query is invalid');return(int)$id; }
 private function id(mixed $v): int {if((!is_int($v)&&(!is_string($v)||!ctype_digit($v)))||(int)$v<1)throw new ApiProblem('SAND_AI_VALIDATION_ERROR','Skill request envelope is invalid');return(int)$v;} private function array(mixed $v):array{if(!is_array($v))throw new ApiProblem('SAND_AI_VALIDATION_ERROR','Skill request envelope is invalid');return$v;} private function without(array $a,string $k):array{unset($a[$k]);return$a;}
}
