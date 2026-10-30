<?php

declare(strict_types=1);

namespace app\Application\Legal;

use app\Api\Support\ApiProblem;
use NeuronAI\StructuredOutput\JsonExtractor;

final class LegalAnalysisOutputValidator
{
    /**
     * @param array<string,mixed> $profile
     * @param list<int> $allowedSourceBlockIds
     * @param list<array<string,mixed>> $fixedSources
     * @param list<array<string,mixed>> $facts
     * @return array{payload:array<string,mixed>,field_sources:list<array{field_path:string,source_block_id:int}>}
     */
    public function validate(string $json, array $profile, array $allowedSourceBlockIds, array $fixedSources, array $facts, ?LegalOutputContract $contract = null, ?array $complaintInput = null, ?array $remedyInput = null): array
    {
        if (mb_strlen($json, 'UTF-8') > ($profile['max_output_characters'] ?? 0)) {
            $this->invalid();
        }
        if ($contract !== null) $json = $contract->originalJson($json);
        // The legacy legal wire format requires a complete object. SDK extraction has no
        // Markdown/bracket recovery; citation and draft checks remain business-owned.
        $structuredJson = (new JsonExtractor())
            ->setExtractors([static fn (string $text): array => [$text]])
            ->getJson($json);
        if ($structuredJson === null) {
            $this->invalid();
        }
        try {
            $raw = json_decode($json, false, 128, JSON_THROW_ON_ERROR);
            $value = json_decode($json, true, 128, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->invalid();
        }
        if (!$raw instanceof \stdClass || !is_array($value) || array_is_list($value)) {
            $this->invalid();
        }
        if ($contract !== null) $this->assertOriginalContainers($raw);
        $isComplaint = ($profile['draft_kind'] ?? null) === 'complaint_draft';
        $isRemedy = in_array($profile['code'] ?? null, RemedyInputPolicy::PROFILE_CODES, true);
        if ($isRemedy !== ($remedyInput !== null) || ($isRemedy && $complaintInput !== null)) $this->invalid();
        $this->exact($value, $isComplaint ? ['findings', 'risks', 'unverified_items', 'draft', 'review_required', 'complaint'] : ['findings', 'risks', 'unverified_items', 'draft', 'review_required']);
        if ($value['review_required'] !== true) {
            $this->invalid();
        }
        $allowed = [];
        foreach ($allowedSourceBlockIds as $id) {
            if (!is_int($id) || $id < 1 || isset($allowed[$id])) {
                throw new ApiProblem('SAND_AI_ARTIFACT_PROVENANCE_INVALID', 'The legal prompt source set is invalid');
            }
            $allowed[$id] = true;
        }
        if ($allowed === []) {
            throw new ApiProblem('SAND_AI_ARTIFACT_PROVENANCE_INVALID', 'The legal prompt has no sources');
        }
        $complaintResult = null;
        if ($isComplaint) {
            if ($complaintInput === null) $this->invalid();
            $this->exact($value['draft'], ['kind','content','source_block_ids']);
            if ($value['draft']['kind'] !== 'complaint_draft' || $value['draft']['content'] !== '' || $value['draft']['source_block_ids'] !== []) $this->invalid();
            $complaintResult = (new ComplaintDraftOutputValidator())->validate($value['complaint'], $raw->complaint ?? null, $complaintInput, $allowed, $fixedSources, $facts);
            $value['draft'] = $complaintResult['draft'];
        } elseif ($complaintInput !== null) $this->invalid();
        if ($isRemedy && ($value['findings'] !== [] || $value['risks'] !== [] || $value['unverified_items'] !== [])) $this->invalid();
        $fieldSources = $complaintResult['field_sources'] ?? [];
        $claimAllowed = $allowed;
        if ($complaintInput !== null) {
            $roles=[]; foreach ($complaintInput['source_roles'] as $role) $roles[$role['source_ref']]=$role['role'];
            foreach ($fixedSources as $source) if (($roles[$source['source_ref']]??null)==='firm_template') foreach ($source['source_block_ids'] as $id) unset($claimAllowed[$id]);
        }
        $findings = [];
        foreach ($this->list($value['findings'], 30) as $index => $finding) {
            $this->exact($finding, ['conclusion', 'supporting_source_block_ids', 'contrary_source_block_ids']);
            $support = $this->sourceIds($finding['supporting_source_block_ids'], $claimAllowed, true);
            $contrary = $this->sourceIds($finding['contrary_source_block_ids'], $claimAllowed, false);
            if (array_intersect($support, $contrary) !== []) {
                throw new ApiProblem('SAND_AI_ARTIFACT_PROVENANCE_INVALID', 'A legal source cannot be both supporting and contrary for one finding');
            }
            $findings[] = [
                'conclusion' => $this->text($finding['conclusion'], 2000),
                'supporting_source_block_ids' => $support,
                'contrary_source_block_ids' => $contrary,
            ];
            $this->addSources($fieldSources, "/findings/{$index}/conclusion", [...$support, ...$contrary]);
        }
        $risks = [];
        foreach ($this->list($value['risks'], 30) as $index => $risk) {
            $this->exact($risk, ['description', 'source_block_ids']);
            $sources = $this->sourceIds($risk['source_block_ids'], $claimAllowed, true);
            $risks[] = ['description' => $this->text($risk['description'], 2000), 'source_block_ids' => $sources];
            $this->addSources($fieldSources, "/risks/{$index}/description", $sources);
        }
        $unverified = [];
        foreach ($this->list($value['unverified_items'], 30) as $index => $item) {
            $this->exact($item, ['description', 'source_block_ids']);
            $sources = $this->sourceIds($item['source_block_ids'], $allowed, false);
            $unverified[] = ['description' => $this->text($item['description'], 2000), 'source_block_ids' => $sources];
            $this->addSources($fieldSources, "/unverified_items/{$index}/description", $sources);
        }
        $draft = $value['draft'];
        $this->exact($draft, ['kind', 'content', 'source_block_ids']);
        if (($draft['kind'] ?? null) !== ($profile['draft_kind'] ?? null)) {
            $this->invalid();
        }
        $draftContent = $draft['content'];
        if (!is_string($draftContent) || mb_strlen($draftContent, 'UTF-8') > ($isRemedy ? $profile['max_output_characters'] : 16000)) {
            $this->invalid();
        }
        $draftSources = $this->sourceIds($draft['source_block_ids'], $allowed, trim($draftContent) !== '');
        if (trim($draftContent) === '' && $draftSources !== []) {
            throw new ApiProblem('SAND_AI_ARTIFACT_PROVENANCE_INVALID', 'An empty legal draft cannot claim sources');
        }
        $this->addSources($fieldSources, '/draft/content', $draftSources);
        $researchPlan = null;
        if ($profile['code'] === 'legal_research_planning') {
            if ($findings !== [] || $risks !== []) $this->invalid();
            $planned = (new LegalResearchPlanPolicy())->validate($draftContent, $facts, $draftSources);
            $researchPlan = $planned['plan'];
            array_push($fieldSources, ...$planned['field_sources']);
        }
        $remedyResult = null;
        if ($isRemedy) {
            $remedyResult = (new RemedyOutputPolicy())->validate($draftContent, $remedyInput, $fixedSources, $facts, $draftSources, $profile['code']);
            $draftContent = $remedyResult['draft_content'];
            if (mb_strlen($draftContent, 'UTF-8') > $profile['max_output_characters']) $this->invalid();
            $researchPlan = $remedyResult['research_plan'];
            array_push($fieldSources, ...$remedyResult['field_sources']);
        }
        $sourceVersions = array_map(static fn (array $source): array => [
            'source_ref' => $source['source_ref'],
            'source_version' => $source['source_version'],
            'authority_state' => 'consumer_declared',
            'declared_effect_status' => $source['declared_effect_status'] ?? 'unknown',
            'file_id' => $source['file_id'],
            'file_sha256' => $source['file_sha256'],
            'parse_id' => $source['parse_id'],
            'version_ref' => $source['version_ref'],
            'source_block_ids' => $source['source_block_ids'],
        ], $fixedSources);
        $sourceWarnings = [];
        foreach ($fixedSources as $source) {
            $effectStatus = $source['declared_effect_status'] ?? 'unknown';
            $warningCode = match ($effectStatus) {
                'amended' => 'declared_amendment_check_required',
                'repealed' => 'declared_repealed_source',
                'unknown' => 'effect_status_unverified',
                'effective' => null,
                default => throw new ApiProblem('SAND_AI_ARTIFACT_PROVENANCE_INVALID', 'The declared legal source status is invalid'),
            };
            if ($warningCode !== null) {
                $warningIndex = count($sourceWarnings);
                $sourceWarnings[] = [
                    'source_ref' => $source['source_ref'],
                    'source_version' => $source['source_version'],
                    'declared_effect_status' => $effectStatus,
                    'warning_code' => $warningCode,
                    'source_block_ids' => $source['source_block_ids'],
                ];
                $this->addSources($fieldSources, "/source_warnings/{$warningIndex}/warning_code", $source['source_block_ids']);
            }
        }
        $factVersions = array_map(static fn (array $fact): array => [
            'fact_ref' => $fact['fact_ref'],
            'fact_version' => $fact['fact_version'],
            'source_block_ids' => $fact['source_block_ids'],
        ], $facts);
        return [
            'payload' => [
                'schema' => ['code' => 'sand_ai.legal_analysis', 'version' => '1.0.0'],
                'legal_profile' => ['code' => $profile['code'], 'version' => $profile['version']],
                'source_versions' => $sourceVersions,
                'source_warnings' => $sourceWarnings,
                'fact_versions' => $factVersions,
                'findings' => $findings,
                'risks' => $risks,
                'unverified_items' => $unverified,
                'draft' => ['kind' => $profile['draft_kind'], 'content' => $draftContent, 'source_block_ids' => $draftSources],
                'review_required' => true,
                ...($researchPlan !== null ? ['research_plan' => $researchPlan] : []),
                ...($remedyResult !== null ? ['remedy' => $remedyResult['remedy']] : []),
                ...($complaintResult !== null ? ['complaint' => $complaintResult['complaint']] : []),
            ],
            'field_sources' => $fieldSources,
        ];
    }

    /** SDK deserialization must not turn numeric-key JSON objects into legal lists. */
    private function assertOriginalContainers(\stdClass $raw): void
    {
        foreach (['findings' => ['supporting_source_block_ids', 'contrary_source_block_ids'],
            'risks' => ['source_block_ids'], 'unverified_items' => ['source_block_ids']] as $field => $citations) {
            if (!is_array($raw->{$field} ?? null)) $this->invalid();
            foreach ($raw->{$field} as $item) {
                if (!$item instanceof \stdClass) $this->invalid();
                foreach ($citations as $citation) {
                    if (!is_array($item->{$citation} ?? null)) $this->invalid();
                }
            }
        }
        if (!(($raw->draft ?? null) instanceof \stdClass)
            || !is_array($raw->draft->source_block_ids ?? null)) {
            $this->invalid();
        }
    }

    /** @param array<int,true> $allowed @return list<int> */
    private function sourceIds(mixed $value, array $allowed, bool $required): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 40 || ($required && $value === [])) {
            throw new ApiProblem('SAND_AI_ARTIFACT_PROVENANCE_INVALID', 'Legal output citations are invalid');
        }
        $ids = [];
        foreach ($value as $id) {
            if (!is_int($id) || !isset($allowed[$id]) || isset($ids[$id])) {
                throw new ApiProblem('SAND_AI_ARTIFACT_PROVENANCE_INVALID', 'Legal output cites a source outside the fixed prompt');
            }
            $ids[$id] = true;
        }
        return array_keys($ids);
    }

    /** @return list<mixed> */
    private function list(mixed $value, int $maximum): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > $maximum) {
            $this->invalid();
        }
        return $value;
    }

    /** @param list<string> $fields */
    private function exact(mixed $value, array $fields): void
    {
        if (!is_array($value) || array_is_list($value) || count($value) !== count($fields)
            || array_diff(array_keys($value), $fields) !== []) {
            $this->invalid();
        }
    }

    private function text(mixed $value, int $maximum): string
    {
        if (!is_string($value) || trim($value) === '' || mb_strlen($value, 'UTF-8') > $maximum) {
            $this->invalid();
        }
        return trim($value);
    }

    /** @param list<array{field_path:string,source_block_id:int}> $fieldSources @param list<int> $sourceIds */
    private function addSources(array &$fieldSources, string $fieldPath, array $sourceIds): void
    {
        foreach ($sourceIds as $sourceId) {
            $fieldSources[] = ['field_path' => $fieldPath, 'source_block_id' => $sourceId];
        }
    }

    private function invalid(): never
    {
        throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'The legal analysis output is invalid');
    }
}
