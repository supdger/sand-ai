<?php

declare(strict_types=1);

namespace app\Application\Legal;

use app\Api\Support\ApiProblem;
use app\Application\Workflow\StructuredWorkflowPolicy;

/** Business provenance validation on the existing Neuron legal response's JSON draft. */
final class RemedyOutputPolicy
{
    public const PACKAGE_SCHEMA = 'judgment-analysis-package.v1';

    public function validate(string $json, array $input, array $sources, array $facts, array $draftSources, string $profileCode): array
    {
        $planning=$profileCode==='judgment_remedy_planning';
        $drafting=$profileCode==='judgment_remedy_drafting';
        if(!in_array($profileCode,RemedyInputPolicy::PROFILE_CODES,true))$this->invalid();
        try {
            $raw = json_decode($json, false, 64, JSON_THROW_ON_ERROR);
            $value = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) { $this->invalid(); }
        if (!$raw instanceof \stdClass) $this->invalid();
        $this->exact($value, ['judgment_findings','claim_comparison','remedy_research_questions','procedural_risks','missing_information','outline','citations','legal_basis','case_authorities','research_plan']);
        foreach (['judgment_findings','claim_comparison','remedy_research_questions','procedural_risks','missing_information','outline','citations','legal_basis','case_authorities'] as $field) {
            if (!is_array($raw->{$field} ?? null)) $this->invalid();
        }
        $roles = []; foreach ($input['source_roles'] as $role) $roles[$role['source_ref']] = $role;
        $roleIds = []; $sourceByRef = []; $material = []; $authority = [];
        foreach ($sources as $source) {
            $ref = $source['source_ref']; $role = $roles[$ref] ?? null;
            if ($role === null) $this->invalid();
            $sourceByRef[$ref] = $source;
            foreach ($source['source_block_ids'] as $id) {
                $roleIds[$role['role']][$id] = true;
                if (in_array($role['role'], RemedyInputPolicy::MATERIAL_ROLES, true)) $material[$id] = true;
                if ($role['role'] === 'legal_authority' && in_array($ref, $input['applicable_authority_refs'], true) && ($source['declared_effect_status'] ?? 'unknown') === 'effective') $authority[$id] = true;
            }
        }
        $substantive = $material + $authority; $all = []; $fieldSources = [];
        $judgment = $roleIds['judgment'] ?? []; $prior = $roleIds['prior_record'] ?? [];
        foreach ($this->items($value['judgment_findings'], 1, 30) as $index=>$finding) {
            $this->exact($finding, ['kind','description','source_block_ids']);
            if (!in_array($finding['kind'], ['disposition','facts','reasoning','cited_authority','cost_allocation'], true)) $this->invalid();
            $value['judgment_findings'][$index]['description'] = $this->text($finding['description'],4000);
            $ids = $this->ids($finding['source_block_ids'],$raw->judgment_findings[$index]->source_block_ids ?? null,$judgment,true);
            $this->record($fieldSources,$all,"/remedy/judgment_findings/{$index}/description",$ids);
            $this->record($fieldSources,$all,"/remedy/judgment_findings/{$index}/kind",$ids);
        }
        foreach ($this->items($value['claim_comparison'], 0, 30) as $index=>$comparison) {
            $this->exact($comparison, ['description','status','prior_source_block_ids','judgment_source_block_ids']);
            if (!in_array($comparison['status'], ['accepted','not_accepted','unanswered','requires_review'], true)) $this->invalid();
            $value['claim_comparison'][$index]['description'] = $this->text($comparison['description'],4000);
            $priorIds=$this->ids($comparison['prior_source_block_ids'],$raw->claim_comparison[$index]->prior_source_block_ids ?? null,$prior,true);
            $judgmentIds=$this->ids($comparison['judgment_source_block_ids'],$raw->claim_comparison[$index]->judgment_source_block_ids ?? null,$judgment,true);
            $this->record($fieldSources,$all,"/remedy/claim_comparison/{$index}/description",[...$priorIds,...$judgmentIds]);
            $this->record($fieldSources,$all,"/remedy/claim_comparison/{$index}/status",[...$priorIds,...$judgmentIds]);
        }
        if ($prior !== [] && $value['claim_comparison'] === []) $this->invalid();
        foreach ($this->items($value['remedy_research_questions'], 1, 20) as $index=>$question) {
            $this->exact($question, ['description','supporting_source_block_ids','contrary_source_block_ids']);
            $value['remedy_research_questions'][$index]['description']=$this->text($question['description'],4000);
            $support=$this->ids($question['supporting_source_block_ids'],$raw->remedy_research_questions[$index]->supporting_source_block_ids ?? null,$substantive,false);
            $contrary=$this->ids($question['contrary_source_block_ids'],$raw->remedy_research_questions[$index]->contrary_source_block_ids ?? null,$substantive,false);
            if ([...$support,...$contrary] === [] || array_intersect($support,$contrary) !== []) $this->invalid();
            $this->record($fieldSources,$all,"/remedy/remedy_research_questions/{$index}/description",[...$support,...$contrary]);
        }
        foreach (['procedural_risks','missing_information'] as $field) {
            foreach ($this->items($value[$field], 0, 30) as $index=>$item) {
                $this->exact($item, ['description','source_block_ids']);
                $value[$field][$index]['description']=$this->text($item['description'],4000);
                $ids=$this->ids($item['source_block_ids'],$raw->{$field}[$index]->source_block_ids ?? null,$substantive,$field==='procedural_risks');
                $this->record($fieldSources,$all,"/remedy/{$field}/{$index}/description",$ids);
            }
        }
        $usedAuthorities=[];
        foreach (['legal_basis'=>['statute','judicial_interpretation'],'case_authorities'=>['case']] as $field=>$types) {
            foreach ($this->items($value[$field],0,30) as $index=>$item) {
                $this->exact($item,['source_ref','description','source_block_ids']);
                $ref=$item['source_ref'];
                if ($planning || !is_string($ref) || !in_array($ref,$input['applicable_authority_refs'],true)
                    || ($roles[$ref]['role'] ?? null)!=='legal_authority' || !in_array($roles[$ref]['authority_type'] ?? null,$types,true)
                    || ($sourceByRef[$ref]['declared_effect_status'] ?? 'unknown')!=='effective') $this->invalid();
                $value[$field][$index]['description']=$this->text($item['description'],4000);
                $ids=$this->ids($item['source_block_ids'],$raw->{$field}[$index]->source_block_ids ?? null,array_fill_keys($sourceByRef[$ref]['source_block_ids'],true),true);
                $usedAuthorities[$ref]=true;
                $this->record($fieldSources,$all,"/remedy/{$field}/{$index}/description",$ids);
                $this->record($fieldSources,$all,"/remedy/{$field}/{$index}/source_ref",$ids);
            }
        }
        foreach ($input['applicable_authority_refs'] as $ref) {
            if (in_array($roles[$ref]['authority_type'],['statute','judicial_interpretation','case'],true) && !isset($usedAuthorities[$ref])) $this->invalid();
        }
        if ($drafting) {
            $original=$input['analysis_package'] ?? null;
            if(!is_array($original))$this->invalid();
            foreach(['judgment_findings','claim_comparison','remedy_research_questions','procedural_risks','missing_information','legal_basis','case_authorities'] as $field) {
                if(!hash_equals(StructuredWorkflowPolicy::fingerprint([$field=>$original[$field]]),StructuredWorkflowPolicy::fingerprint([$field=>$value[$field]])))$this->invalid();
            }
        }
        $rendered = []; $template=$input['template'];
        if (!$drafting) {
            if ($value['outline'] !== [] || $template !== null || ($planning ? !($raw->research_plan instanceof \stdClass) : $value['research_plan'] !== null)) $this->invalid();
        } else {
            if ($value['research_plan'] !== null || $template === null) $this->invalid();
            $sections=$this->items($value['outline'],count($template['sections']),count($template['sections']));
            foreach ($sections as $index=>$section) {
                $this->exact($section,['key','title','content','source_block_ids']);
                $fixed=$template['sections'][$index];
                if ($section['key']!==$fixed['key'] || $section['title']!==$fixed['title']) $this->invalid();
                $content=$this->text($section['content'],16000,true);
                $allowed=$substantive + array_fill_keys($fixed['source_block_ids'],true);
                $ids=$this->ids($section['source_block_ids'],$raw->outline[$index]->source_block_ids ?? null,$allowed,true);
                if (array_diff($fixed['source_block_ids'],$ids)!==[] || array_intersect($ids,array_keys($substantive))===[]) $this->invalid();
                $value['outline'][$index]['content']=$content;
                $this->record($fieldSources,$all,"/remedy/outline/{$index}/title",$fixed['source_block_ids']);
                $this->record($fieldSources,$all,"/remedy/outline/{$index}/content",$ids);
                $rendered[]=$fixed['title']."\n".$content;
            }
        }
        $plan=null;
        if ($planning) {
            if (!is_array($raw->research_plan->issues ?? null)) $this->invalid();
            $planIds=[]; $byRef=[];foreach($facts as $fact)$byRef[$fact['fact_ref']]=$fact['source_block_ids'];
            foreach ($this->items($value['research_plan']['issues'] ?? null,1,5) as $issue) {
                foreach ($this->items($issue['fact_refs'] ?? null,1,30) as $ref) {
                    if (!is_string($ref)||!isset($byRef[$ref])) $this->invalid();
                    foreach($byRef[$ref] as $id) $planIds[$id]=true;
                }
            }
            $planJson=json_encode($raw->research_plan,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $planned=(new LegalResearchPlanPolicy())->validate($planJson,$facts,array_keys($planIds));
            $plan=$planned['plan'];
            foreach ($planned['field_sources'] as $link) {
                $fieldSources[]=$link;
                $fieldSources[]=['field_path'=>'/remedy'.$link['field_path'],'source_block_id'=>$link['source_block_id']];
            }
            foreach(array_keys($planIds) as $id)$all[$id]=true;
        }
        $citations=$this->ids($value['citations'],$raw->citations,$substantive + ($roleIds['firm_template'] ?? []),true);
        $expected=array_keys($all);sort($expected,SORT_NUMERIC);$actual=$citations;sort($actual,SORT_NUMERIC);$draftActual=$draftSources;sort($draftActual,SORT_NUMERIC);
        if ($actual!==$expected || $draftActual!==$expected) throw new ApiProblem('SAND_AI_ARTIFACT_PROVENANCE_INVALID','The remedy package citations do not match its actual field sources');
        return ['remedy'=>['schema'=>self::PACKAGE_SCHEMA,'stage_snapshot'=>$input['stage'],'document_type'=>$input['document_type'],'input_version'=>$input['input_version'],
                'document_version_refs'=>$input['document_version_refs'],'case_version_refs'=>$input['case_version_refs'],'evidence_version_refs'=>$input['evidence_version_refs'],'analysis_snapshot'=>$input['analysis_snapshot'],
                ...($drafting?['analysis_package_sha256'=>$input['analysis_package_sha256']]:[]),...$value],
            'research_plan'=>$plan,'draft_content'=>!$drafting?$json:implode("\n\n",$rendered),'source_block_ids'=>$citations,'field_sources'=>$fieldSources];
    }

    private function record(array &$fieldSources,array &$all,string $path,array $ids):void { foreach($ids as $id){$all[$id]=true;$fieldSources[]=['field_path'=>$path,'source_block_id'=>$id];} }
    private function ids(mixed $value,mixed $raw,array $allowed,bool $required):array { if(!is_array($raw))$this->invalid();$values=$this->items($value,$required?1:0,40);$seen=[];foreach($values as $id){if(!is_int($id)||!isset($allowed[$id])||isset($seen[$id]))throw new ApiProblem('SAND_AI_ARTIFACT_PROVENANCE_INVALID','A remedy field cites a source outside its frozen role');$seen[$id]=true;}return array_keys($seen); }
    private function items(mixed $value,int $minimum,int $maximum):array { if(!is_array($value)||!array_is_list($value)||count($value)<$minimum||count($value)>$maximum)$this->invalid();return $value; }
    private function exact(mixed $value,array $keys):void { if(!is_array($value)||array_is_list($value)||count($value)!==count($keys)||array_diff(array_keys($value),$keys)!==[])$this->invalid(); }
    private function text(mixed $value,int $maximum,bool $multiline=false):string { if(!is_string($value)||trim($value)===''||!mb_check_encoding($value,'UTF-8')||mb_strlen($value,'UTF-8')>$maximum||preg_match($multiline?'/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/':'/[\x00-\x1f\x7f]/',$value)===1)$this->invalid();return trim($value); }
    private function invalid():never { throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED','The judgment remedy package must preserve actual judgment, prior record and private authority sources'); }
}
