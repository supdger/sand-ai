<?php

declare(strict_types=1);

namespace app\Application\Legal;

use app\Api\Support\ApiProblem;

/** Consumer-confirmed civil drafting input, never a new source of verified facts. */
final class ComplaintDraftPolicy
{
    public const SECTION_KEYS = ['parties', 'claims', 'facts_reasons', 'legal_basis', 'evidence', 'closing'];

    /** @param list<array<string,mixed>> $sources @param list<array<string,mixed>> $facts @return array<string,mixed> */
    public function normalize(mixed $input, array $sources, array $facts): array
    {
        $this->exact($input, ['document_type','input_version','party_version','claims_version','parties','claims','evidence','template','source_roles','consumer_confirmed']);
        if ($input['document_type'] !== 'civil_complaint' || $input['consumer_confirmed'] !== true || $facts === []) $this->invalid();
        foreach (['input_version','party_version','claims_version'] as $field) $this->text($input[$field], 512);
        $roles = []; $materialIds = []; $templateIds = []; $allIds = [];
        foreach ($this->items($input['source_roles'], 1, 10) as $role) {
            $this->exact($role, ['source_ref','role','authority_type']);
            $ref = $this->text($role['source_ref'],512);
            if (isset($roles[$ref]) || !in_array($role['role'], ['case_material','legal_authority','firm_template'], true)
                || ($role['role'] === 'legal_authority' ? !in_array($role['authority_type'],['statute','judicial_interpretation','case','guidance','other'],true) : $role['authority_type'] !== null)) $this->invalid();
            $roles[$ref] = $role;
        }
        if (count($roles) !== count($sources)) $this->invalid();
        foreach ($sources as $source) {
            $role = $roles[$source['source_ref']] ?? null;
            if ($role === null) $this->invalid();
            foreach ($source['source_block_ids'] as $id) {
                $allIds[$id] = true;
                if ($role['role'] === 'case_material') $materialIds[$id] = true;
                if ($role['role'] === 'firm_template') $templateIds[$id] = true;
            }
        }
        // Confirmed facts remain case material and cannot come from a template or authority.
        foreach ($facts as $fact) $this->ids($fact['source_block_ids'], $materialIds, true);
        $seen = []; $parties = []; $partyRoles = [];
        foreach ($this->items($input['parties'], 2, 20) as $party) {
            $this->exact($party, ['party_ref','role','name','identity_summary','source_block_ids']);
            $ref = $this->unique($party['party_ref'], $seen);
            if (!in_array($party['role'], ['plaintiff','defendant','third_party'],true)) $this->invalid();
            $partyRoles[$party['role']] = true;
            $parties[] = ['party_ref'=>$ref,'role'=>$party['role'],'name'=>$this->text($party['name'],256),'identity_summary'=>$this->text($party['identity_summary'],2000),'source_block_ids'=>$this->ids($party['source_block_ids'],$materialIds,true)];
        }
        if (!isset($partyRoles['plaintiff'],$partyRoles['defendant'])) $this->invalid();
        $claims = []; $seen = [];
        foreach ($this->items($input['claims'],1,20) as $claim) {
            $this->exact($claim,['claim_ref','content','source_block_ids']);
            $claims[] = ['claim_ref'=>$this->unique($claim['claim_ref'],$seen),'content'=>$this->text($claim['content'],2000),'source_block_ids'=>$this->ids($claim['source_block_ids'],$materialIds,true)];
        }
        $evidence = []; $seen = [];
        foreach ($this->items($input['evidence'],1,30) as $item) {
            $this->exact($item,['evidence_ref','description','source_block_ids']);
            $evidence[] = ['evidence_ref'=>$this->unique($item['evidence_ref'],$seen),'description'=>$this->text($item['description'],2000),'source_block_ids'=>$this->ids($item['source_block_ids'],$materialIds,true)];
        }
        $template = $input['template'];
        $this->exact($template,['template_ref','template_version','source_ref','document_type','sections']);
        if ($template['document_type'] !== 'civil_complaint' || ($roles[$template['source_ref']]['role'] ?? null) !== 'firm_template') $this->invalid();
        $selectedTemplate = null;
        foreach ($sources as $source) if ($source['source_ref'] === $template['source_ref']) $selectedTemplate = $source;
        if ($selectedTemplate === null || $template['template_version'] !== $selectedTemplate['file_sha256'].':'.$selectedTemplate['parse_id']) $this->invalid();
        $sections = []; $seen = [];
        foreach ($this->items($template['sections'],6,6) as $section) {
            $this->exact($section,['key','title','source_block_ids']);
            $key = $this->unique($section['key'],$seen);
            if (!in_array($key,self::SECTION_KEYS,true)) $this->invalid();
            $sections[] = ['key'=>$key,'title'=>$this->text($section['title'],160),'source_block_ids'=>$this->ids($section['source_block_ids'],array_fill_keys($selectedTemplate['source_block_ids'],true),true)];
        }
        if (array_diff(self::SECTION_KEYS,array_keys($seen)) !== []) $this->invalid();
        return ['document_type'=>'civil_complaint','input_version'=>$input['input_version'],'party_version'=>$input['party_version'],'claims_version'=>$input['claims_version'],
            'parties'=>$parties,'claims'=>$claims,'evidence'=>$evidence,'template'=>['template_ref'=>$this->text($template['template_ref'],512),'template_version'=>$template['template_version'],'source_ref'=>$template['source_ref'],'document_type'=>'civil_complaint','sections'=>$sections],
            'source_roles'=>array_values($roles),'consumer_confirmed'=>true];
    }

    /** Match section titles against the actual private parsed template, not caller text alone. */
    public function assertTemplate(array $complaint, array $resolved): void
    {
        $blocks = [];
        foreach ($resolved['sources'] as $source) if ($source['source_ref'] === $complaint['template']['source_ref']) {
            if ($complaint['template']['template_version'] !== $source['file_sha256'].':'.$source['parse_id']) $this->invalid();
            foreach ($source['blocks'] as $block) $blocks[$block['source_block_id']] = $block['content'];
        }
        foreach ($complaint['template']['sections'] as $section) {
            $matched = false;
            foreach ($section['source_block_ids'] as $id) {
                if (!isset($blocks[$id])) $this->invalid();
                if (str_contains($blocks[$id],$section['title'])) $matched = true;
            }
            if (!$matched) throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID','A complaint section title is not present in its frozen private template');
        }
    }

    /** @return list<mixed> */
    private function items(mixed $value,int $minimum,int $maximum): array { if (!is_array($value)||!array_is_list($value)||count($value)<$minimum||count($value)>$maximum) $this->invalid(); return $value; }
    private function exact(mixed $value,array $keys): void { if (!is_array($value)||array_is_list($value)||count($value)!==count($keys)||array_diff(array_keys($value),$keys)!==[]) $this->invalid(); }
    private function text(mixed $value,int $maximum): string { if (!is_string($value)||trim($value)===''||mb_strlen($value,'UTF-8')>$maximum) $this->invalid(); return trim($value); }
    private function unique(mixed $value,array &$seen): string { $ref=$this->text($value,512); if (isset($seen[$ref])) $this->invalid(); $seen[$ref]=true; return $ref; }
    /** @return list<int> */
    public function ids(mixed $value,array $allowed,bool $required): array { $values=$this->items($value,$required?1:0,40);$seen=[];foreach($values as $id){if(!is_int($id)||!isset($allowed[$id])||isset($seen[$id])) $this->invalid();$seen[$id]=true;}return array_keys($seen); }
    private function invalid(): never { throw new ApiProblem('SAND_AI_VALIDATION_ERROR','The frozen civil complaint input is invalid'); }
}
