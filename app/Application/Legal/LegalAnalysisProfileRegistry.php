<?php

declare(strict_types=1);

namespace app\Application\Legal;

use app\Api\Support\ApiProblem;
use app\Application\Workflow\StructuredWorkflowPolicy;
use app\Infrastructure\Persistence\Model\LegalAnalysisProfile;

/** Published environment profile; caller requests cannot supply a prompt or config. */
final class LegalAnalysisProfileRegistry
{
    /** @return array{code:string,version:string,draft_kind:string,output_language:string,max_prompt_characters:int,max_output_characters:int} */
    public function profile(int $environmentId, string $code, string $version): array
    {
        return $this->read($environmentId, $code, $version, false);
    }

    /** Call under the workflow submit or enqueue transaction. */
    public function assertSnapshotCurrent(int $environmentId, array $snapshot): void
    {
        $current = $this->read($environmentId, (string) ($snapshot['code'] ?? ''), (string) ($snapshot['version'] ?? ''), true);
        if (!hash_equals(StructuredWorkflowPolicy::fingerprint($current), StructuredWorkflowPolicy::fingerprint($snapshot))) {
            throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'The published legal analysis profile changed before submission');
        }
    }

    /** @return array{code:string,version:string,draft_kind:string,output_language:string,max_prompt_characters:int,max_output_characters:int} */
    public function validate(array $profile): array
    {
        $code = $profile['code'] ?? null;
        $version = $profile['version'] ?? null;
        $kinds = [
            'legal_research' => 'research_memo',
            'legal_research_planning' => 'research_plan',
            'judgment_remedy_planning' => 'research_plan',
            'judgment_remedy_analysis' => 'judgment_remedy_memo',
            'judgment_remedy_drafting' => 'remedy_document_draft',
            'litigation_preparation' => 'litigation_outline',
            'judgment_analysis' => 'judgment_analysis_memo',
            'complaint_drafting' => 'complaint_draft',
        ];
        if (!is_string($code) || !isset($kinds[$code]) || !is_string($version)
            || array_diff(array_keys($profile), ['code', 'version', 'draft_kind', 'output_language', 'max_prompt_characters', 'max_output_characters']) !== []
            || count($profile) !== 6
            || strlen($version) > 32
            || preg_match('/\A[0-9]+\.[0-9]+\.[0-9]+\z/D', $version) !== 1
            || ($profile['draft_kind'] ?? null) !== $kinds[$code]
            || !in_array($profile['output_language'] ?? null, ['zh-CN', 'en'], true)
            || !is_int($profile['max_prompt_characters'] ?? null)
            || $profile['max_prompt_characters'] < 1000 || $profile['max_prompt_characters'] > 200000
            || !is_int($profile['max_output_characters'] ?? null)
            || $profile['max_output_characters'] < 1000 || $profile['max_output_characters'] > 500000) {
            throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'The legal analysis profile is not registered');
        }
        return $profile;
    }

    /** @return array<string,mixed> */
    private function read(int $environmentId, string $code, string $version, bool $forUpdate): array
    {
        if ($environmentId < 1 || preg_match('/\A[0-9]+\.[0-9]+\.[0-9]+\z/D', $version) !== 1) {
            throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'The legal analysis profile is not published for this environment');
        }
        $query = LegalAnalysisProfile::where('environment_id', $environmentId)
            ->where('code', $code)->where('version', $version)->where('state', 'published')
            ->where('status', 1)->whereNull('delete_time');
        $row = ($forUpdate ? $query->lock(true) : $query)->find();
        $config = $row?->config;
        if ($row === null || !is_array($config)) {
            throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'The legal analysis profile is not published for this environment');
        }
        $profile = $this->validate($config);
        if ($profile['code'] !== $code || $profile['version'] !== $version) {
            throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'The published legal analysis profile is inconsistent');
        }
        return $profile;
    }
}
