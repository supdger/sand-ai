<?php

declare(strict_types=1);

namespace app\Application\Legal;

use app\Api\Support\ApiProblem;
use app\Application\Workflow\StructuredWorkflowPolicy;

/** Frozen consumer stage and private sources; no determination of remedy or procedural effect. */
final class RemedyInputPolicy
{
    public const PROFILE_CODES = ['judgment_remedy_planning', 'judgment_remedy_analysis', 'judgment_remedy_drafting'];
    public const MATERIAL_ROLES = ['judgment', 'prior_record', 'evidence', 'case_material'];

    public function normalize(mixed $input, array $sources, array $facts, array $context, string $profileCode): array
    {
        $this->exact($input, ['stage','document_type','input_version','document_version_refs','case_version_refs','evidence_version_refs','source_roles','applicable_authority_refs','template','analysis_snapshot','analysis_package','analysis_package_sha256','consumer_confirmed']);
        if (!in_array($profileCode, self::PROFILE_CODES, true) || $input['consumer_confirmed'] !== true || $facts === []) $this->invalid();
        $stage = $input['stage'];
        $this->exact($stage, ['stage_id','stage_version','judgment_material_version_id','stage_type','judgment_document_type','research_remedy_type','research_goal','research_as_of_date','service_time','effective_state','effective_time','limitation_start_fact','confirmed_by','confirmed_time','snapshot_sha256']);
        foreach (['stage_id','stage_version','judgment_material_version_id'] as $key) $stage[$key] = $this->text($stage[$key], 512);
        foreach (['stage_type','judgment_document_type','effective_state'] as $key) $stage[$key] = $this->text($stage[$key], 128);
        $stage['research_goal'] = $this->text($stage['research_goal'], 2000);
        if (!in_array($stage['research_remedy_type'], ['appeal','retrial','procuratorial_protest','petition'], true)
            || $stage['research_as_of_date'] !== ($context['as_of_date'] ?? null)
            || !is_string($stage['snapshot_sha256']) || preg_match('/\A[a-f0-9]{64}\z/D', $stage['snapshot_sha256']) !== 1) $this->invalid();
        foreach (['service_time','effective_time','limitation_start_fact','confirmed_by','confirmed_time'] as $key) {
            if ($stage[$key] !== null) $stage[$key] = $this->text($stage[$key], $key === 'limitation_start_fact' ? 2000 : 512);
        }
        $roles = []; $materialIds = []; $judgmentIds = []; $sourceByRef = [];
        foreach ($this->items($input['source_roles'], 1, 10) as $role) {
            $this->exact($role, ['source_ref','role','authority_type']);
            $ref = $this->text($role['source_ref'], 512);
            if (isset($roles[$ref]) || !in_array($role['role'], [...self::MATERIAL_ROLES,'legal_authority','firm_template'], true)
                || ($role['role'] === 'legal_authority' ? !in_array($role['authority_type'], ['statute','judicial_interpretation','case','guidance','other'], true) : $role['authority_type'] !== null)) $this->invalid();
            $roles[$ref] = ['source_ref'=>$ref,'role'=>$role['role'],'authority_type'=>$role['authority_type']];
        }
        if (count($roles) !== count($sources)) $this->invalid();
        foreach ($sources as $source) {
            $ref = $source['source_ref']; $role = $roles[$ref] ?? null;
            if ($role === null) $this->invalid();
            $sourceByRef[$ref] = $source;
            foreach ($source['source_block_ids'] as $id) {
                if (in_array($role['role'], self::MATERIAL_ROLES, true)) $materialIds[$id] = true;
                if ($role['role'] === 'judgment') $judgmentIds[$id] = true;
            }
        }
        if ($judgmentIds === []) $this->invalid();
        foreach ($facts as $fact) $this->ids($fact['source_block_ids'], $materialIds, true);
        $documents = []; $seen = []; $documentVersions = []; $documentSources = [];
        foreach ($this->items($input['document_version_refs'], 1, 30) as $versionRef) {
            $this->exact($versionRef, ['document_ref','document_version','source_ref']);
            $sourceRef = $this->text($versionRef['source_ref'], 512);
            if (!isset($sourceByRef[$sourceRef]) || !in_array($roles[$sourceRef]['role'], self::MATERIAL_ROLES, true)) $this->invalid();
            $documentRef=$this->text($versionRef['document_ref'],512);$documentVersion=$this->text($versionRef['document_version'],512);
            $pair=$documentRef."\0".$sourceRef;
            if(isset($seen[$pair])||(isset($documentVersions[$documentRef])&&$documentVersions[$documentRef]!==$documentVersion))$this->invalid();
            $seen[$pair]=true;$documentVersions[$documentRef]=$documentVersion;
            $documents[] = ['document_ref'=>$documentRef,'document_version'=>$documentVersion,'source_ref'=>$sourceRef];
            $documentSources[$sourceRef] = true;
        }
        // A selected judgment must have a consumer-frozen document version reference.
        foreach ($roles as $roleRef=>$role) if ($role['role'] === 'judgment' && !isset($documentSources[$roleRef])) $this->invalid();
        $cases = []; $seen = [];
        foreach ($this->items($input['case_version_refs'], 1, 10) as $caseRow) {
            $this->exact($caseRow, ['case_ref','case_version']);
            $cases[] = ['case_ref'=>$this->unique($caseRow['case_ref'],$seen),'case_version'=>$this->text($caseRow['case_version'],512)];
        }
        $evidence = []; $seen = []; $evidenceVersions = [];
        foreach ($this->items($input['evidence_version_refs'], 0, 30) as $evidenceRow) {
            $this->exact($evidenceRow, ['evidence_ref','evidence_version','source_ref']);
            $sourceRef = $this->text($evidenceRow['source_ref'],512);
            if (!in_array($roles[$sourceRef]['role'] ?? null,self::MATERIAL_ROLES,true)) $this->invalid();
            $evidenceRef=$this->text($evidenceRow['evidence_ref'],512);$evidenceVersion=$this->text($evidenceRow['evidence_version'],512);
            $pair=$evidenceRef."\0".$sourceRef;
            if(isset($seen[$pair])||(isset($evidenceVersions[$evidenceRef])&&$evidenceVersions[$evidenceRef]!==$evidenceVersion))$this->invalid();
            $seen[$pair]=true;$evidenceVersions[$evidenceRef]=$evidenceVersion;
            $evidence[] = ['evidence_ref'=>$evidenceRef,'evidence_version'=>$evidenceVersion,'source_ref'=>$sourceRef];
        }
        $applicable = []; $seen = [];
        foreach ($this->items($input['applicable_authority_refs'], 0, 10) as $authorityRow) {
            $authorityRef = $this->unique($authorityRow,$seen);
            if (($roles[$authorityRef]['role'] ?? null) !== 'legal_authority' || ($sourceByRef[$authorityRef]['declared_effect_status'] ?? 'unknown') !== 'effective') $this->invalid();
            $applicable[] = $authorityRef;
        }
        $documentType = $this->text($input['document_type'],128);
        $template = null;
        if ($profileCode === 'judgment_remedy_planning') {
            if ($input['template'] !== null || $applicable !== [] || $documentType !== $stage['judgment_document_type']) $this->invalid();
            foreach ($roles as $role) if (!in_array($role['role'], self::MATERIAL_ROLES, true)) $this->invalid();
        } elseif ($profileCode === 'judgment_remedy_analysis') {
            if ($input['template'] !== null || $documentType !== $stage['judgment_document_type']) $this->invalid();
            foreach ($roles as $role) if ($role['role'] === 'firm_template') $this->invalid();
        } else {
            $template = $input['template'];
            $this->exact($template, ['template_ref','template_version','source_ref','document_type','sections']);
            $templateRef = $this->text($template['source_ref'],512);
            $selected = $sourceByRef[$templateRef] ?? null;
            if (($roles[$templateRef]['role'] ?? null) !== 'firm_template' || $selected === null || $template['document_type'] !== $documentType
                || $template['template_version'] !== $selected['file_sha256'].':'.$selected['parse_id']) $this->invalid();
            $sections = []; $seen = [];
            foreach ($this->items($template['sections'], 1, 12) as $section) {
                $this->exact($section, ['key','title','source_block_ids']);
                $sections[] = ['key'=>$this->unique($section['key'],$seen),'title'=>$this->text($section['title'],160),'source_block_ids'=>$this->ids($section['source_block_ids'],array_fill_keys($selected['source_block_ids'],true),true)];
            }
            $template = ['template_ref'=>$this->text($template['template_ref'],512),'template_version'=>$template['template_version'],'source_ref'=>$templateRef,'document_type'=>$documentType,'sections'=>$sections];
        }
        $analysisSnapshot = $input['analysis_snapshot'];
        if ($profileCode === 'judgment_remedy_drafting') {
            $this->exact($analysisSnapshot,['analysis_request_ref','run_id','task_id','payload_sha256']);
            $analysisSnapshot['analysis_request_ref']=$this->text($analysisSnapshot['analysis_request_ref'],512);
            $analysisSnapshot['run_id']=$this->text($analysisSnapshot['run_id'],512);
            $analysisSnapshot['task_id']=$this->text($analysisSnapshot['task_id'],512);
            if(!is_string($analysisSnapshot['payload_sha256'])||preg_match('/\A[a-f0-9]{64}\z/D',$analysisSnapshot['payload_sha256'])!==1)$this->invalid();
        } elseif ($analysisSnapshot !== null) $this->invalid();
        $normalized = ['stage'=>$stage,'document_type'=>$documentType,'input_version'=>$this->text($input['input_version'],512),
            'document_version_refs'=>$documents,'case_version_refs'=>$cases,'evidence_version_refs'=>$evidence,
            'source_roles'=>array_values($roles),'applicable_authority_refs'=>$applicable,'template'=>$template,'analysis_snapshot'=>$analysisSnapshot,'analysis_package'=>null,'analysis_package_sha256'=>null,'consumer_confirmed'=>true];
        if ($profileCode === 'judgment_remedy_drafting') {
            $package = $this->analysisPackage($input['analysis_package'], $input['analysis_package_sha256'], $normalized, $sources, $facts);
            $normalized['analysis_package']=$package;
            $normalized['analysis_package_sha256']=$input['analysis_package_sha256'];
        } elseif ($input['analysis_package'] !== null || $input['analysis_package_sha256'] !== null) $this->invalid();
        return $normalized;
    }

    /** Revalidate the actual analysis subset; its full-result hash is verified by the consumer. */
    private function analysisPackage(mixed $package, mixed $sha256, array $current, array $sources, array $facts): array
    {
        $metadata=['schema','stage_snapshot','document_type','input_version','document_version_refs','case_version_refs','evidence_version_refs','analysis_snapshot'];
        $contentKeys=['judgment_findings','claim_comparison','remedy_research_questions','procedural_risks','missing_information','outline','citations','legal_basis','case_authorities','research_plan'];
        $this->exact($package,[...$metadata,...$contentKeys]);
        if (!is_string($sha256) || preg_match('/\A[a-f0-9]{64}\z/D',$sha256)!==1
            || !hash_equals($sha256,StructuredWorkflowPolicy::fingerprint($package))
            || $package['schema']!==RemedyOutputPolicy::PACKAGE_SCHEMA
            || $package['document_type']!==$current['stage']['judgment_document_type']
            || $package['analysis_snapshot']!==null) $this->invalid();
        foreach (['stage_snapshot'=>'stage','document_version_refs'=>'document_version_refs','case_version_refs'=>'case_version_refs','evidence_version_refs'=>'evidence_version_refs'] as $original=>$field) {
            if (!is_array($package[$original]) || !hash_equals(StructuredWorkflowPolicy::fingerprint($package[$original]),StructuredWorkflowPolicy::fingerprint($current[$field]))) $this->invalid();
        }
        $analysis=$current;
        $analysis['document_type']=$package['document_type'];
        $analysis['input_version']=$this->text($package['input_version'],512);
        $analysis['template']=null;
        $analysis['analysis_snapshot']=null;
        $content=[];foreach($contentKeys as $key)$content[$key]=$package[$key];
        if(!is_array($package['citations'])||!array_is_list($package['citations']))$this->invalid();
        $validated=(new RemedyOutputPolicy())->validate(
            json_encode($content,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            $analysis,$sources,$facts,$package['citations'],'judgment_remedy_analysis',
        );
        if (!hash_equals($sha256,StructuredWorkflowPolicy::fingerprint($validated['remedy']))) $this->invalid();
        return $package;
    }

    /** Titles must occur in actual frozen private parsed template blocks. */
    public function assertTemplate(array $remedy, array $resolved): void
    {
        if ($remedy['template'] === null) return;
        $template = $remedy['template']; $blocks = [];
        foreach ($resolved['sources'] as $source) if ($source['source_ref'] === $template['source_ref']) {
            if ($template['template_version'] !== $source['file_sha256'].':'.$source['parse_id']) $this->snapshotInvalid();
            foreach ($source['blocks'] as $block) $blocks[$block['source_block_id']] = $block['content'];
        }
        foreach ($template['sections'] as $section) {
            $matched = false;
            foreach ($section['source_block_ids'] as $id) {
                if (!isset($blocks[$id])) $this->snapshotInvalid();
                if (str_contains($blocks[$id],$section['title'])) $matched = true;
            }
            if (!$matched) $this->snapshotInvalid();
        }
    }

    private function items(mixed $value,int $minimum,int $maximum): array { if (!is_array($value)||!array_is_list($value)||count($value)<$minimum||count($value)>$maximum) $this->invalid(); return $value; }
    private function exact(mixed $value,array $keys): void { if (!is_array($value)||array_is_list($value)||count($value)!==count($keys)||array_diff(array_keys($value),$keys)!==[]) $this->invalid(); }
    private function text(mixed $value,int $maximum): string { if (!is_string($value)||trim($value)===''||!mb_check_encoding($value,'UTF-8')||mb_strlen($value,'UTF-8')>$maximum||preg_match('/[\x00-\x1f\x7f]/',$value)===1) $this->invalid(); return trim($value); }
    private function unique(mixed $value,array &$seen): string { $ref=$this->text($value,512);if(isset($seen[$ref])) $this->invalid();$seen[$ref]=true;return $ref; }
    private function ids(mixed $value,array $allowed,bool $required): array { $values=$this->items($value,$required?1:0,40);$seen=[];foreach($values as $id){if(!is_int($id)||!isset($allowed[$id])||isset($seen[$id])) $this->invalid();$seen[$id]=true;}return array_keys($seen); }
    private function snapshotInvalid(): never { throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID','A remedy section title is not present in its frozen private template'); }
    private function invalid(): never { throw new ApiProblem('SAND_AI_VALIDATION_ERROR','The frozen judgment remedy research input is invalid'); }
}
