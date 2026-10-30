<?php

declare(strict_types=1);

namespace app\Application\Composition;

use app\Api\Support\ApiProblem;
use app\Application\Agent\AgentDefinitionGateway;
use app\Application\Gateway\RequestPayloadFingerprint;
use app\Application\Knowledge\KnowledgePublicationGateway;
use app\Application\Workflow\MaterialAnalysisProfileRegistry;
use app\Application\Workflow\WorkflowDefinitionRegistry;
use think\facade\Db;

/** Only existing publications are selectable; freezing never creates a service. */
final class CompositionServiceCatalog
{
    public function catalog(int $environmentId): array
    {
        $db = Db::connect('pgsql');
        $selections = [];
        foreach ($db->query("SELECT d.code,d.name,v.version_no FROM sand_ai_agent_definition d JOIN sand_ai_agent_version v ON v.id=d.active_version_id WHERE d.environment_id=? AND d.state='published' AND d.status=1 AND d.delete_time IS NULL AND v.state='published' ORDER BY d.id", [$environmentId]) as $row) $selections[] = ['kind'=>'agent','code'=>$row['code'],'version'=>(int)$row['version_no']];
        foreach ($db->query("SELECT s.code,v.version_no FROM sand_ai_skill s JOIN sand_ai_skill_version v ON v.skill_id=s.id WHERE s.environment_id=? AND s.state='published' AND s.status=1 AND s.delete_time IS NULL AND COALESCE(s.scope_type,'legacy')='legacy' AND v.state='published' ORDER BY s.id,v.version_no", [$environmentId]) as $row) $selections[] = ['kind'=>'skill','code'=>$row['code'],'version'=>(int)$row['version_no']];
        foreach ($db->query("SELECT code,version FROM sand_ai_material_analysis_profile WHERE environment_id=? AND state='published' AND status=1 AND delete_time IS NULL ORDER BY id", [$environmentId]) as $row) $selections[] = ['kind'=>'material','code'=>$row['code'],'version'=>$row['version']];
        foreach ($db->query("SELECT p.code,v.version_no FROM sand_ai_knowledge_publication p JOIN sand_ai_knowledge_base b ON b.id=p.knowledge_base_id JOIN sand_ai_knowledge_base_version v ON v.id=b.active_version_id WHERE p.consumer_environment_id=? AND p.owner_environment_id=? AND p.state='published' AND b.state='published' AND b.status=1 AND b.delete_time IS NULL AND v.state='published' ORDER BY p.id", [$environmentId,$environmentId]) as $row) $selections[] = ['kind'=>'knowledge','code'=>$row['code'],'version'=>(int)$row['version_no']];
        foreach ($db->query("SELECT t.code,v.version_no FROM sand_ai_tool_environment_binding b JOIN sand_ai_tool t ON t.id=b.tool_id JOIN sand_ai_tool_version v ON v.id=b.tool_version_id WHERE b.environment_id=? AND b.state='published' AND b.status=1 AND t.state='published' AND t.status=1 AND t.effect_class='read_only' AND v.state='published' ORDER BY b.id", [$environmentId]) as $row) $selections[] = ['kind'=>'tool','code'=>$row['code'],'version'=>(int)$row['version_no']];
        $items = [];
        foreach ($selections as $selection) {
            try { $frozen = $this->freeze($environmentId, $selection); }
            catch (ApiProblem $problem) { if ($problem->errorCode === 'SAND_AI_COMPOSITION_REFERENCE_CHANGED') continue; throw $problem; }
            $items[] = array_intersect_key($frozen, array_flip(['kind','code','version','name','reference_sha256','input_fields','output_schema']));
        }
        return ['items'=>$items];
    }

    public function freeze(int $environmentId, array $service): array
    {
        if ($environmentId < 1 || array_diff(array_keys($service), ['kind','code','version']) !== []
            || !is_string($service['code'] ?? null) || !in_array($service['kind'] ?? null, ['agent','skill','material','knowledge','tool'], true)
            || (!is_int($service['version'] ?? null) && !is_string($service['version'] ?? null))) self::changed();
        $kind = $service['kind']; $code = $service['code']; $version = $service['version'];
        if ($kind !== 'material' && (!is_int($version) || $version < 1)) self::changed();
        if ($kind === 'material' && (!is_string($version) || preg_match('/\A[0-9]+\.[0-9]+\.[0-9]+\z/', $version) !== 1)) self::changed();
        $db = Db::connect('pgsql');
        try {
            switch ($kind) {
                case 'agent':
                    $target = (new AgentDefinitionGateway())->resolve($environmentId, $code);
                    $rows = $db->query('SELECT d.name,v.version_no FROM sand_ai_agent_definition d JOIN sand_ai_agent_version v ON v.id=d.active_version_id WHERE d.environment_id=? AND d.code=? AND v.id=?', [$environmentId,$code,$target['binding']['version_id']]);
                    if (count($rows)!==1 || (int)$rows[0]['version_no']!==$version) self::changed();
                    $name = (string)$rows[0]['name'];
                    $schema = ['type'=>'object','additionalProperties'=>false,'properties'=>['query'=>['type'=>'string','minLength'=>1,'maxLength'=>500],'conversation_id'=>['type'=>'string','pattern'=>'^[A-Za-z0-9][A-Za-z0-9_.:-]{0,95}$']],'required'=>['query']];
                    break;
                case 'skill':
                    $rows = $db->query("SELECT s.id AS skill_id,v.* FROM sand_ai_skill s JOIN sand_ai_skill_version v ON v.skill_id=s.id WHERE s.environment_id=? AND s.code=? AND s.status=1 AND s.state='published' AND s.delete_time IS NULL AND COALESCE(s.scope_type,'legacy')='legacy' AND v.version_no=? AND v.state='published'", [$environmentId,$code,$version]);
                    if (count($rows)!==1) self::changed();
                    $row=$rows[0]; $name=(string)$row['name']; $schema=\app\Application\Skill\SkillSchemaPolicy::normalize($this->json($row['input_schema']));
                    $target=['skill_id'=>(int)$row['skill_id'],'version_id'=>(int)$row['id'],'input_schema'=>$schema,'output_schema'=>\app\Application\Skill\SkillSchemaPolicy::normalize($this->json($row['output_schema'])),'execution_snapshot'=>$this->json($row['execution_snapshot']),'instruction'=>$row['instruction'],'review_policy'=>$this->json($row['review_policy'])];
                    $refs=$db->query('SELECT knowledge_base_id,knowledge_base_version_id,snapshot_sha256 FROM sand_ai_skill_knowledge_ref WHERE skill_version_id=?', [(int)$row['id']]);
                    if (count($refs)!==1) self::changed();
                    $target['knowledge_reference']=$refs[0];
                    $target['knowledge_publication']=(new \app\Application\Skill\SkillKnowledgePublication())->resolve(
                        $environmentId,(int)$refs[0]['knowledge_base_version_id'],(string)$refs[0]['snapshot_sha256'],
                    );
                    break;
                case 'material':
                    $profile=(new MaterialAnalysisProfileRegistry())->profile($environmentId,$code,$version);
                    $definition=(new WorkflowDefinitionRegistry())->definition('material_analysis','1.0.0');
                    $rows=$db->query("SELECT name FROM sand_ai_material_analysis_profile WHERE environment_id=? AND code=? AND version=? AND state='published' AND status=1 AND delete_time IS NULL",[$environmentId,$code,$version]);
                    if (count($rows)!==1) self::changed();
                    $name=(string)$rows[0]['name']; $target=['profile'=>$profile,'workflow'=>['code'=>'material_analysis','version'=>'1.0.0'],'output_schema'=>$definition['output_schema']];
                    $schema=['type'=>'object','additionalProperties'=>false,'properties'=>[
                        'business_scope'=>['type'=>'object','additionalProperties'=>false,'properties'=>['ref'=>['type'=>'string'],'snapshot_ref'=>['type'=>'string'],'snapshot_version'=>['type'=>'string']],'required'=>['ref','snapshot_ref','snapshot_version']],
                        'files'=>['type'=>'array','minItems'=>1,'maxItems'=>100,'items'=>['type'=>'object','additionalProperties'=>false,'properties'=>['file_id'=>['type'=>'integer','minimum'=>1],'sha256'=>['type'=>'string','pattern'=>'^[a-f0-9]{64}$'],'version_ref'=>['type'=>'string']],'required'=>['file_id','sha256','version_ref']]],
                    ],'required'=>['business_scope','files']];
                    break;
                case 'knowledge':
                    $publication=(new KnowledgePublicationGateway())->findForConsumer($environmentId,$code);
                    if ((int)$publication['owner_environment_id']!==$environmentId || (int)$publication['version_no']!==$version) self::changed();
                    $rows=$db->query('SELECT name FROM sand_ai_knowledge_base WHERE id=?',[(int)$publication['knowledge_base_id']]);
                    if (count($rows)!==1) self::changed();
                    $name=(string)$rows[0]['name']; $target=$publication;
                    $schema=['type'=>'object','additionalProperties'=>false,'properties'=>['query'=>['type'=>'string'],'limit'=>['type'=>'integer'],'rerank'=>['type'=>'boolean'],'model'=>['type'=>'string'],'rerank_mode'=>['type'=>'string','enum'=>['professional']]],'required'=>['query']];
                    break;
                case 'tool':
                    $binding=$this->readonlyToolBinding($environmentId,$code,$version);
                    $rows=$db->query('SELECT name FROM sand_ai_tool WHERE id=?',[$binding['tool_id']]);
                    if (count($rows)!==1) self::changed();
                    $name=(string)$rows[0]['name']; $target=$binding;
                    $schema=['type'=>'object','additionalProperties'=>false,'properties'=>['business_scope'=>['type'=>'string','pattern'=>'^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$'],'input'=>$binding['input_schema']],'required'=>['business_scope','input']];
                    break;
            }
            if (($schema['type'] ?? null)!=='object' || !is_array($schema['properties'] ?? null)) self::changed();
            $fields=[];
            foreach ($schema['properties'] as $field=>$property) {
                if (!is_string($field) || !is_array($property) || !is_string($property['type'] ?? null)) self::changed();
                $fields[]=['name'=>$field,'label'=>is_string($property['title'] ?? null)?$property['title']:$field,'required'=>in_array($field,$schema['required'] ?? [],true),'schema'=>$property];
            }
            $frozen=['environment_id'=>$environmentId,'service'=>$service,'kind'=>$kind,'code'=>$code,'version'=>$version,'name'=>$name,'target'=>$target,'input_schema'=>$schema,'input_fields'=>$fields,'output_schema'=>$this->outputSchema($kind,$target)];
            return $frozen+['reference_sha256'=>RequestPayloadFingerprint::fingerprint($frozen)];
        } catch (\JsonException) {
            self::changed();
        } catch (ApiProblem $problem) {
            if ($problem->errorCode==='SAND_AI_COMPOSITION_REFERENCE_CHANGED') throw $problem;
            self::changed();
        }
    }

    public function assertCurrent(int $environmentId, array $frozen): void
    {
        if (!is_array($frozen['service'] ?? null) || ($frozen['environment_id'] ?? null)!==$environmentId || !is_string($frozen['reference_sha256'] ?? null)) self::changed();
        $unsigned=$frozen; unset($unsigned['reference_sha256']);
        if (!hash_equals($frozen['reference_sha256'],RequestPayloadFingerprint::fingerprint($unsigned))) self::changed();
        $current=$this->freeze($environmentId,$frozen['service']);
        if (!hash_equals($frozen['reference_sha256'],$current['reference_sha256'])) self::changed();
    }

    private function json(mixed $value): array
    {
        if (is_string($value)) $value=json_decode($value,true,64,JSON_THROW_ON_ERROR);
        if (!is_array($value) || array_is_list($value)) self::changed();
        return $value;
    }

    /** Only proven wrapper paths are typed. Dynamic content has no invented nested field schema. */
    private function outputSchema(string $kind,array $target): array
    {
        $citations=['type'=>'array','items'=>['type'=>'object','additionalProperties'=>true]];
        $properties=match ($kind) {
            'skill'=>['output'=>$target['output_schema'],'citations'=>$citations,'review_required'=>['type'=>'boolean']],
            'tool'=>['result'=>$target['output_schema'],'citations'=>$citations,'review_required'=>['type'=>'boolean']],
            'material'=>['result'=>['type'=>'object','properties'=>[
                'schema'=>['type'=>'object','properties'=>['code'=>['type'=>'string'],'version'=>['type'=>'string']],'required'=>['code','version'],'additionalProperties'=>false],
                'payload'=>['type'=>'object','additionalProperties'=>true],
            ],'required'=>['schema','payload'],'additionalProperties'=>false],'citations'=>$citations,'review_required'=>['type'=>'boolean'],
                'file_results'=>['type'=>'array','items'=>['type'=>'object','additionalProperties'=>true]]],
            'knowledge'=>['knowledge_base'=>['type'=>'object','additionalProperties'=>true],'citations'=>$citations],
            'agent'=>['result'=>['type'=>'object','properties'=>[
                'output'=>['type'=>'object','properties'=>['content'=>['type'=>'string']],'required'=>['content'],'additionalProperties'=>false],
                'source_references'=>['type'=>'array','items'=>['type'=>'object','properties'=>[
                    'source_block_id'=>['type'=>'integer'],'file_id'=>['type'=>'integer'],'locator_type'=>['type'=>'string'],'locator'=>['type'=>'object','additionalProperties'=>true],
                ],'additionalProperties'=>false]],
                'tool_calls'=>['type'=>'array','items'=>['type'=>'object','properties'=>['tool'=>['type'=>'string'],'source_block_ids'=>['type'=>'array','items'=>['type'=>'integer']]],'additionalProperties'=>false]],
                'review_required'=>['type'=>'boolean'],
            ],'additionalProperties'=>false]],
        };
        return ['type'=>'object','properties'=>$properties,'additionalProperties'=>false];
    }

    /** Tool publication metadata only. Business scope is a runtime input, never a catalog default. */
    private function readonlyToolBinding(int $environmentId,string $code,int $versionNo): array
    {
        $tool=\app\Infrastructure\Persistence\Model\Tool::where('code',$code)->where('state','published')
            ->where('status',1)->where('effect_class','read_only')->whereNull('delete_time')
            ->whereIn('adapter',['standard_readonly_http','mcp_streamable_http'])->find();
        $version=$tool===null?null:\app\Infrastructure\Persistence\Model\ToolVersion::where('tool_id',(int)$tool->id)
            ->where('version_no',$versionNo)->where('state','published')->find();
        if ($version===null) self::changed();
        $binding=\app\Infrastructure\Persistence\Model\ToolEnvironmentBinding::where('environment_id',$environmentId)
            ->where('tool_id',(int)$tool->id)->where('tool_version_id',(int)$version->id)
            ->where('state','published')->where('status',1)->find();
        if ($binding===null) self::changed();
        $connection=\app\Infrastructure\Persistence\Model\ToolConnection::where('id',(int)$binding->tool_connection_id)
            ->where('environment_id',$environmentId)->where('state','published')->where('status',1)->find();
        $revision=\app\Infrastructure\Persistence\Model\ToolConnectionRevision::where('id',(int)$binding->tool_connection_revision_id)
            ->where('tool_connection_id',(int)$binding->tool_connection_id)->where('state','published')->find();
        if ($connection===null || $revision===null || !is_string($revision->data_class)
            || preg_match('/\A[a-z0-9][a-z0-9._-]{1,31}\z/D',$revision->data_class)!==1
            || !is_array($version->review_policy) || !is_bool($version->review_policy['review_required'] ?? null)) self::changed();
        $input=\app\Application\Tool\ToolSchemaPolicy::normalize($version->input_schema);
        $output=\app\Application\Tool\ToolSchemaPolicy::normalize($version->output_schema);
        $mcp=null;
        if ((string)$tool->adapter==='mcp_streamable_http') {
            $item=\app\Infrastructure\Persistence\Model\ToolMcpSnapshotItem::where('id',(int)$version->mcp_snapshot_item_id)->find();
            $snapshot=$item===null?null:\app\Infrastructure\Persistence\Model\ToolMcpSnapshot::where('id',(int)$item->mcp_snapshot_id)->where('state','tested')->find();
            if ($snapshot===null || (int)$snapshot->environment_id!==$environmentId
                || (int)$snapshot->tool_connection_id!==(int)$connection->id
                || (int)$snapshot->tool_connection_revision_id!==(int)$revision->id) self::changed();
            \app\Application\Tool\McpStreamableHttpClient::assertProtocolVersion((string)$snapshot->protocol_version);
            \app\Application\Tool\McpSnapshotPolicy::assertFrozen([
                'local_tool_code'=>(string)$item->local_tool_code,'remote_name'=>(string)$item->remote_name,
                'remote_description'=>(string)$item->remote_description,'remote_input_schema'=>$item->remote_input_schema,
                'remote_output_schema'=>is_array($item->remote_output_schema)?$item->remote_output_schema:null,
                'input_schema'=>$item->input_schema,'output_schema'=>$item->output_schema,
                'citation_mode'=>(string)$item->citation_mode,'registry_definition_hash'=>(string)$item->registry_definition_hash,
                'item_hash'=>(string)$item->item_hash,
            ],new \app\Application\Tool\McpReadonlyToolRegistry());
            $mcp=['snapshot_id'=>(int)$snapshot->id,'item_id'=>(int)$item->id,
                'item_hash'=>(string)$item->item_hash,'registry_definition_hash'=>(string)$item->registry_definition_hash,
                'protocol_version'=>(string)$snapshot->protocol_version];
        } elseif ($version->mcp_snapshot_item_id!==null) self::changed();
        return [
            'tool_id'=>(int)$tool->id,'tool_version_id'=>(int)$version->id,
            'binding_id'=>(int)$binding->id,'connection_id'=>(int)$connection->id,
            'connection_revision_id'=>(int)$revision->id,'connection_config_fingerprint'=>(string)$revision->config_fingerprint,
            'adapter'=>(string)$tool->adapter,'effect_class'=>'read_only','data_class'=>$revision->data_class,
            'input_schema'=>$input,'output_schema'=>$output,'review_required'=>$version->review_policy['review_required'],
            'timeout_ms'=>(int)$version->timeout_ms,'response_max_bytes'=>(int)$version->response_max_bytes,'mcp'=>$mcp,
        ];
    }

    private static function changed(): never { throw new ApiProblem('SAND_AI_COMPOSITION_REFERENCE_CHANGED','Selected service publication or fixed reference is unavailable or changed'); }
}
