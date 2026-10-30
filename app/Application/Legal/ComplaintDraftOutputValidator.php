<?php

declare(strict_types=1);

namespace app\Application\Legal;

use app\Api\Support\ApiProblem;

/** Validates every section and matrix against the exact frozen civil drafting inputs. */
final class ComplaintDraftOutputValidator
{
    /** @return array{complaint:array<string,mixed>,draft:array<string,mixed>,field_sources:list<array{field_path:string,source_block_id:int}>} */
    public function validate(mixed $value, mixed $raw, array $input, array $allowed, array $sources, array $facts): array
    {
        $this->exact($value,['sections','element_matrix','possible_defenses','missing_evidence','pending_decisions']);
        if (!$raw instanceof \stdClass) $this->invalid();
        foreach (['sections','element_matrix','possible_defenses','missing_evidence','pending_decisions'] as $field) {
            if (!is_array($raw->{$field} ?? null)) $this->invalid();
            foreach ($raw->{$field} as $item) {
                if (!$item instanceof \stdClass || !is_array($item->source_block_ids ?? null)) $this->invalid();
                if ($field === 'element_matrix' && (!is_array($item->fact_refs ?? null) || !is_array($item->evidence_refs ?? null))) $this->invalid();
            }
        }
        $roles = []; foreach ($input['source_roles'] as $role) $roles[$role['source_ref']] = $role;
        $material = []; $authority = []; $statutes = []; $cases = []; $gaps = [];
        foreach ($sources as $source) {
            $role = $roles[$source['source_ref']] ?? null;
            if ($role === null) $this->invalid();
            if ($role['role'] === 'case_material') foreach ($source['source_block_ids'] as $id) $material[$id] = true;
            if ($role['role'] !== 'legal_authority') continue;
            foreach ($source['source_block_ids'] as $id) $authority[$id] = true;
            $effective = ($source['declared_effect_status'] ?? 'unknown') === 'effective';
            if ($effective && in_array($role['authority_type'],['statute','judicial_interpretation'],true)) foreach ($source['source_block_ids'] as $id) $statutes[$id] = true;
            if ($effective && $role['authority_type'] === 'case') foreach ($source['source_block_ids'] as $id) $cases[$id] = true;
            $gaps[] = ['description'=>$effective ? '法源效力由消费方声明，本次未独立核验适用版本、效力和原平台定位，须律师复核。' : '引用版本或效力尚未确认；该来源不能生成完成的法律依据章节。','source_block_ids'=>$source['source_block_ids']];
        }
        if ($statutes === []) $gaps[] = ['description'=>'未找到本次固定版本且声明有效的可用法规或司法解释，法律依据章节留空待补。','source_block_ids'=>[]];
        if ($cases === []) $gaps[] = ['description'=>'未找到本次可引用的相似案例；不得补写案号或裁判结论。','source_block_ids'=>[]];
        $sections = []; $fieldSources = []; $parts = []; $draftIds = [];
        $models = $this->items($value['sections'],6);
        if (count($models) !== 6) $this->invalid();
        foreach ($input['template']['sections'] as $index=>$template) {
            $section = $models[$index] ?? null;
            $this->exact($section,['key','title','content','source_block_ids']);
            if ($section['key'] !== $template['key'] || $section['title'] !== $template['title'] || !is_string($section['content']) || mb_strlen($section['content'],'UTF-8')>16000) $this->invalid();
            $content = trim($section['content']);
            $ids = $this->ids($section['source_block_ids'],$allowed,$content !== '');
            if ($content === '' && $ids !== []) $this->invalid();
            if ($template['key'] === 'legal_basis') {
                if ($statutes === [] && $content !== '') $this->invalid();
                // Any generated legal assertion must include a fixed actual authority block.
                if ($content !== '' && array_intersect($ids,array_keys($statutes)) === []) $this->invalid();
            } elseif ($content === '') $this->invalid();
            if (in_array($template['key'],['parties','claims','facts_reasons','evidence'],true) && array_intersect($ids,array_keys($material)) === []) $this->invalid();
            // Legal body citations may use only declared-effective fixed authorities.
            foreach ($ids as $id) if (isset($authority[$id]) && !isset($statutes[$id]) && !isset($cases[$id])) $this->invalid();
            $sections[] = ['key'=>$template['key'],'title'=>$template['title'],'content'=>$content,'source_block_ids'=>$ids];
            $this->add($fieldSources,"/complaint/sections/{$index}/title",$template['source_block_ids']);
            $this->add($fieldSources,"/complaint/sections/{$index}/content",$ids);
            $parts[] = $template['title']."\n".($content === '' ? '【法律依据不足，待律师补充核验】' : $content);
            foreach ([...$ids,...$template['source_block_ids']] as $id) $draftIds[$id] = true;
        }
        $sectionsByKey = []; foreach ($sections as $section) $sectionsByKey[$section['key']] = $section;
        foreach ($input['parties'] as $party) {
            if (!str_contains($sectionsByKey['parties']['content'],$party['name']) || !str_contains($sectionsByKey['parties']['content'],$party['identity_summary']) || array_diff($party['source_block_ids'],$sectionsByKey['parties']['source_block_ids']) !== []) $this->invalid();
        }
        foreach ($input['claims'] as $claim) if (!str_contains($sectionsByKey['claims']['content'],$claim['content']) || array_diff($claim['source_block_ids'],$sectionsByKey['claims']['source_block_ids']) !== []) $this->invalid();
        foreach ($input['evidence'] as $item) if (!str_contains($sectionsByKey['evidence']['content'],$item['description']) || array_diff($item['source_block_ids'],$sectionsByKey['evidence']['source_block_ids']) !== []) $this->invalid();
        $factIndex = []; foreach ($facts as $fact) $factIndex[$fact['fact_ref']] = $fact['source_block_ids'];
        $evidenceIndex = []; foreach ($input['evidence'] as $evidence) $evidenceIndex[$evidence['evidence_ref']] = $evidence['source_block_ids'];
        $matrix = [];
        foreach ($this->items($value['element_matrix'],30) as $index=>$row) {
            $this->exact($row,['element','fact_refs','evidence_refs','source_block_ids']);
            $factRefs = $this->refs($row['fact_refs'],$factIndex); $evidenceRefs = $this->refs($row['evidence_refs'],$evidenceIndex);
            $ids = $this->ids($row['source_block_ids'],$allowed,true);
            if ($factRefs === [] && $evidenceRefs === []) $this->invalid();
            if (array_intersect($ids,array_keys($material)) === []) $this->invalid();
            foreach ($factRefs as $ref) if (array_diff($factIndex[$ref],$ids) !== []) $this->invalid();
            foreach ($evidenceRefs as $ref) if (array_diff($evidenceIndex[$ref],$ids) !== []) $this->invalid();
            $matrix[] = ['element'=>$this->text($row['element']),'fact_refs'=>$factRefs,'evidence_refs'=>$evidenceRefs,'source_block_ids'=>$ids];
            $this->add($fieldSources,"/complaint/element_matrix/{$index}/element",$ids);
        }
        if ($matrix === []) $this->invalid();
        $notes = [];
        foreach (['possible_defenses','missing_evidence','pending_decisions'] as $field) {
            $notes[$field] = [];
            foreach ($this->items($value[$field],30) as $index=>$note) {
                $this->exact($note,['description','source_block_ids']);
                $ids = $this->ids($note['source_block_ids'],$allowed,false);
                $notes[$field][] = ['description'=>$this->text($note['description']),'source_block_ids'=>$ids];
                $this->add($fieldSources,"/complaint/{$field}/{$index}/description",$ids);
            }
        }
        foreach ($gaps as $index=>$gap) $this->add($fieldSources,"/complaint/authority_gaps/{$index}/description",$gap['source_block_ids']);
        $body = implode("\n\n",$parts);
        if (mb_strlen($body,'UTF-8')>16000) $this->invalid();
        return ['complaint'=>['document_type'=>'civil_complaint','input_version'=>$input['input_version'],'template_version'=>$input['template']['template_version'],'sections'=>$sections,'element_matrix'=>$matrix,...$notes,'authority_gaps'=>$gaps],
            'draft'=>['kind'=>'complaint_draft','content'=>$body,'source_block_ids'=>array_keys($draftIds)],'field_sources'=>$fieldSources];
    }

    private function exact(mixed $value,array $keys):void { if(!is_array($value)||array_is_list($value)||count($value)!==count($keys)||array_diff(array_keys($value),$keys)!==[]) $this->invalid(); }
    private function items(mixed $value,int $max):array {if(!is_array($value)||!array_is_list($value)||count($value)>$max) $this->invalid();return $value;}
    private function ids(mixed $value,array $allowed,bool $required):array { $list=$this->items($value,40);if($required&&$list===[]) $this->invalid();$seen=[];foreach($list as $id){if(!is_int($id)||!isset($allowed[$id])||isset($seen[$id])) $this->invalid();$seen[$id]=true;}return array_keys($seen); }
    private function refs(mixed $value,array $index):array {$list=$this->items($value,30);$seen=[];foreach($list as $ref){if(!is_string($ref)||!isset($index[$ref])||isset($seen[$ref])) $this->invalid();$seen[$ref]=true;}return array_keys($seen);}
    private function text(mixed $value):string {if(!is_string($value)||trim($value)===''||mb_strlen($value,'UTF-8')>2000) $this->invalid();return trim($value);}
    private function add(array &$rows,string $path,array $ids):void {foreach($ids as $id)$rows[]=['field_path'=>$path,'source_block_id'=>$id];}
    private function invalid():never {throw new ApiProblem('SAND_AI_ARTIFACT_PROVENANCE_INVALID','The civil complaint does not match its frozen template, facts or sources');}
}
