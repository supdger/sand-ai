<?php

declare(strict_types=1);

namespace app\Application\Legal;

use app\Api\Support\ApiProblem;

/** Business validation after the existing Neuron structured completion node. */
final class LegalResearchPlanPolicy
{
    public function validate(string $json, array $facts, array $draftSources): array
    {
        try { $raw = json_decode($json, false, 32, JSON_THROW_ON_ERROR); $plan = json_decode($json, true, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { $this->invalid(); }
        if (!$raw instanceof \stdClass || !is_array($raw->issues ?? null) || !is_array($plan)) $this->invalid();
        $this->exact($plan, ['issues','review_required']);
        if ($plan['review_required'] !== true || !is_array($plan['issues']) || !array_is_list($plan['issues']) || count($plan['issues']) < 1 || count($plan['issues']) > 5) $this->invalid();
        $byRef = []; foreach ($facts as $fact) $byRef[$fact['fact_ref']] = $fact['source_block_ids'];
        $all = []; $fieldSources = [];
        foreach ($plan['issues'] as $index => $issue) {
            $this->exact($issue, ['issue_ref','summary','fact_refs','law_query','case_query']);
            if ($issue['issue_ref'] !== 'issue_' . ($index + 1) || !is_array($raw->issues[$index]->fact_refs ?? null) || !is_array($issue['fact_refs']) || !array_is_list($issue['fact_refs']) || count($issue['fact_refs']) < 1 || count($issue['fact_refs']) > 30) $this->invalid();
            $seen = []; $ids = [];
            foreach ($issue['fact_refs'] as $ref) {
                if (!is_string($ref) || !isset($byRef[$ref]) || isset($seen[$ref])) $this->invalid();
                $seen[$ref] = true; foreach ($byRef[$ref] as $id) { $ids[$id] = true; $all[$id] = true; }
            }
            foreach (['summary'=>500,'law_query'=>1000,'case_query'=>1000] as $field=>$maximum) {
                $text=$issue[$field];
                if (!is_string($text) || trim($text)==='' || !mb_check_encoding($text,'UTF-8') || mb_strlen($text,'UTF-8')>$maximum || preg_match('/[\x00-\x1f\x7f]/',$text)===1) $this->invalid();
                foreach (array_keys($ids) as $id) $fieldSources[]=['field_path'=>"/research_plan/issues/{$index}/{$field}",'source_block_id'=>$id];
            }
        }
        $expected=array_keys($all); sort($expected,SORT_NUMERIC); $actual=$draftSources; sort($actual,SORT_NUMERIC);
        if ($expected !== $actual) throw new ApiProblem('SAND_AI_ARTIFACT_PROVENANCE_INVALID','The research plan citations do not match its frozen fact references');
        return ['plan'=>$plan,'field_sources'=>$fieldSources];
    }
    private function exact(mixed $value,array $keys):void { if (!is_array($value) || array_is_list($value) || count($value)!==count($keys) || array_diff(array_keys($value),$keys)!==[]) $this->invalid(); }
    private function invalid():never { throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED','The research plan must use actual frozen facts and bounded search proposals'); }
}
