<?php

declare(strict_types=1);

namespace app\Application\Legal;

use app\Api\Support\ApiProblem;

final class LegalAnalysisInputPolicy
{
    /**
     * @param array<string,mixed> $input
     * @return array{profile:array<string,mixed>,context:array<string,mixed>,facts:list<array<string,mixed>>,sources:list<array<string,mixed>>}
     */
    public function normalize(int $environmentId, array $input): array
    {
        $isComplaint = ($input['legal_profile']['code'] ?? null) === 'complaint_drafting';
        $isRemedy = in_array($input['legal_profile']['code'] ?? null, RemedyInputPolicy::PROFILE_CODES, true);
        $this->exact($input, $isComplaint ? ['legal_profile', 'legal_context', 'confirmed_facts', 'legal_sources', 'complaint_input'] : ($isRemedy ? ['legal_profile', 'legal_context', 'confirmed_facts', 'legal_sources', 'remedy_input'] : ['legal_profile', 'legal_context', 'confirmed_facts', 'legal_sources']));
        $selection = $input['legal_profile'] ?? null;
        $this->exact($selection, ['code', 'version']);
        if (!is_string($selection['code'] ?? null) || !is_string($selection['version'] ?? null)) {
            $this->invalid();
        }
        $profile = (new LegalAnalysisProfileRegistry())->profile($environmentId, $selection['code'], $selection['version']);

        $context = $input['legal_context'] ?? null;
        $this->exact($context, ['issues', 'jurisdiction', 'case_type', 'as_of_date']);
        $issues = $context['issues'] ?? null;
        if (!is_array($issues) || !array_is_list($issues) || $issues === [] || count($issues) > 5) {
            $this->invalid();
        }
        $normalizedIssues = [];
        foreach ($issues as $issue) {
            $normalizedIssues[] = $this->text($issue, 500);
        }
        $date = $context['as_of_date'] ?? null;
        $parsedDate = is_string($date) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
        if (!is_string($date) || !preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $date)
            || $parsedDate === false || $parsedDate->format('Y-m-d') !== $date) {
            $this->invalid();
        }
        $normalizedContext = [
            'issues' => $normalizedIssues,
            'jurisdiction' => $this->text($context['jurisdiction'] ?? null, 128),
            'case_type' => $this->text($context['case_type'] ?? null, 128),
            'as_of_date' => $date,
        ];

        $sources = (new LegalSourceGateway())->normalize(['sources' => $input['legal_sources'] ?? null]);
        $allowedBlocks = [];
        foreach ($sources as $source) {
            foreach ($source['source_block_ids'] as $blockId) {
                $allowedBlocks[$blockId] = true;
            }
        }
        $facts = $input['confirmed_facts'] ?? null;
        if (!is_array($facts) || !array_is_list($facts) || count($facts) > 30 || ($profile['code'] === 'legal_research_planning' && $facts === [])) {
            $this->invalid();
        }
        $normalizedFacts = [];
        $refs = [];
        foreach ($facts as $fact) {
            $this->exact($fact, ['fact_ref', 'fact_version', 'summary', 'source_block_ids']);
            $ref = $this->text($fact['fact_ref'] ?? null, 512);
            if (isset($refs[$ref])) {
                $this->invalid();
            }
            $refs[$ref] = true;
            $ids = $this->sourceIds($fact['source_block_ids'] ?? null, $allowedBlocks, true);
            $normalizedFacts[] = [
                'fact_ref' => $ref,
                'fact_version' => $this->text($fact['fact_version'] ?? null, 512),
                'summary' => $this->text($fact['summary'] ?? null, 2000),
                'source_block_ids' => $ids,
            ];
        }
        return [
            'profile' => $profile,
            'context' => $normalizedContext,
            'facts' => $normalizedFacts,
            'sources' => $sources,
            ...($isRemedy ? ['remedy' => (new RemedyInputPolicy())->normalize($input['remedy_input'], $sources, $normalizedFacts, $normalizedContext, $profile['code'])] : []),
            ...($isComplaint ? ['complaint' => (new ComplaintDraftPolicy())->normalize($input['complaint_input'], $sources, $normalizedFacts)] : []),
        ];
    }

    /** @param array<int,true> $allowed @return list<int> */
    private function sourceIds(mixed $value, array $allowed, bool $required): array
    {
        if (!is_array($value) || !array_is_list($value) || ($required && $value === []) || count($value) > 8) {
            $this->invalid();
        }
        $ids = [];
        foreach ($value as $id) {
            if (!is_int($id) || $id < 1 || !isset($allowed[$id]) || isset($ids[$id])) {
                $this->invalid();
            }
            $ids[$id] = true;
        }
        return array_keys($ids);
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

    private function invalid(): never
    {
        throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'The legal analysis input is invalid');
    }
}
