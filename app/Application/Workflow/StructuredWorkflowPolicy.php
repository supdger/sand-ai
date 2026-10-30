<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use app\Api\Support\ApiProblem;
use app\Application\Legal\LegalAnalysisInputPolicy;

final class StructuredWorkflowPolicy
{
    /** @param array<string, mixed> $input @return array<string, mixed> */
    public static function normalizeRequest(
        array $input,
        WorkflowDefinitionRegistry $definitions,
        int $environmentId,
        ?MaterialAnalysisProfileRegistry $profiles = null,
        ?array $sampleDraft = null,
    ): array
    {
        self::rejectUnknown($input, [
            'business_scope', 'workflow', 'files', 'output_schema', 'analysis_profile', 'review_required',
            'legal_profile', 'legal_context', 'confirmed_facts', 'legal_sources', 'complaint_input', 'remedy_input',
        ]);
        $scope = self::object($input['business_scope'] ?? null, 'business_scope');
        self::rejectUnknown($scope, ['ref', 'snapshot_ref', 'snapshot_version']);
        $businessScope = [
            'ref' => self::opaqueReference($scope['ref'] ?? null, 'business_scope.ref'),
            'snapshot_ref' => self::opaqueReference($scope['snapshot_ref'] ?? null, 'business_scope.snapshot_ref'),
            'snapshot_version' => self::opaqueReference($scope['snapshot_version'] ?? null, 'business_scope.snapshot_version'),
        ];

        $workflow = self::object($input['workflow'] ?? null, 'workflow');
        self::rejectUnknown($workflow, ['code', 'version']);
        $workflowCode = self::code($workflow['code'] ?? null, 'workflow.code');
        $workflowVersion = self::version($workflow['version'] ?? null, 'workflow.version');
        $definition = $definitions->definition($workflowCode, $workflowVersion);
        $legalFields = ['legal_profile', 'legal_context', 'confirmed_facts', 'legal_sources'];
        $legalAnalysis = null;
        if ($workflowCode === 'legal_analysis') {
            $legalInput = [];
            foreach ($legalFields as $field) {
                if (!array_key_exists($field, $input)) {
                    throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'The legal analysis request is incomplete');
                }
                $legalInput[$field] = $input[$field];
            }
            if (array_key_exists('complaint_input', $input)) $legalInput['complaint_input'] = $input['complaint_input'];
            if (array_key_exists('remedy_input', $input)) $legalInput['remedy_input'] = $input['remedy_input'];
            $legalAnalysis = (new LegalAnalysisInputPolicy())->normalize($environmentId, $legalInput);
        } elseif (array_intersect(array_keys($input), [...$legalFields, 'complaint_input', 'remedy_input']) !== []) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Legal analysis input is not supported by this workflow');
        }

        $analysisProfile = null;
        if (($definition['analysis_profile_required'] ?? false) === true) {
            $rawProfile = self::object($input['analysis_profile'] ?? null, 'analysis_profile');
            self::rejectUnknown($rawProfile, ['code', 'version']);
            $profileCode = self::code($rawProfile['code'] ?? null, 'analysis_profile.code');
            $profileVersion = self::version($rawProfile['version'] ?? null, 'analysis_profile.version');
            if ($sampleDraft !== null) {
                if ($workflowCode !== 'material_analysis'
                    || ($sampleDraft['environment_id'] ?? null) !== $environmentId
                    || ($sampleDraft['code'] ?? null) !== $profileCode
                    || ($sampleDraft['version'] ?? null) !== $profileVersion
                    || !is_array($sampleDraft['config'] ?? null)) {
                    throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'The material sample draft does not match the workflow');
                }
                $profile = (new MaterialAnalysisProfilePolicy())->validate($sampleDraft['config']);
            } else {
                $profile = ($profiles ?? new MaterialAnalysisProfileRegistry())->profile($environmentId, $profileCode, $profileVersion);
            }
            $analysisProfile = ['code' => $profileCode, 'version' => $profileVersion, 'snapshot' => $profile];
        } elseif (array_key_exists('analysis_profile', $input)) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'analysis_profile is not supported by this workflow');
        } elseif ($sampleDraft !== null) {
            throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'The material sample requires a material analysis workflow');
        }

        $outputSchema = self::object($input['output_schema'] ?? null, 'output_schema');
        self::rejectUnknown($outputSchema, ['code', 'version']);
        $expectedSchema = $definition['output_schema'] ?? [];
        if (($outputSchema['code'] ?? null) !== ($expectedSchema['code'] ?? null) || ($outputSchema['version'] ?? null) !== ($expectedSchema['version'] ?? null)) {
            throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'The output schema is not supported by the workflow definition');
        }

        $rawFiles = $input['files'] ?? null;
        if (!is_array($rawFiles) || $rawFiles === [] || count($rawFiles) > 100) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'files must contain one to one hundred fixed file snapshots');
        }
        $files = [];
        foreach ($rawFiles as $rawFile) {
            $file = self::object($rawFile, 'files[]');
            self::rejectUnknown($file, ['file_id', 'sha256', 'version_ref']);
            $fileId = filter_var($file['file_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $sha256 = strtolower(trim((string) ($file['sha256'] ?? '')));
            if ($fileId === false || !preg_match('/^[a-f0-9]{64}$/', $sha256)) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Each file snapshot requires a positive file_id and SHA-256');
            }
            $files[(int) $fileId] = [
                'file_id' => (int) $fileId,
                'sha256' => $sha256,
                'version_ref' => self::opaqueReference($file['version_ref'] ?? null, 'files[].version_ref'),
            ];
        }
        if (count($files) !== count($rawFiles)) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'A fixed file snapshot cannot contain duplicate file ids');
        }
        ksort($files, SORT_NUMERIC);
        if ($legalAnalysis !== null) {
            $legalFiles = [];
            foreach ($legalAnalysis['sources'] as $source) {
                $fileId = $source['file_id'];
                if (!isset($files[$fileId])
                    || $files[$fileId]['sha256'] !== $source['file_sha256']
                    || $files[$fileId]['version_ref'] !== $source['version_ref']) {
                    throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'A legal source is outside the fixed workflow files');
                }
                $legalFiles[$fileId] = true;
            }
            if (count($legalFiles) !== count($files)) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'The fixed legal workflow files are inconsistent');
            }
        }

        $reviewRequired = $input['review_required'] ?? false;
        if (!is_bool($reviewRequired)) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'review_required must be boolean');
        }
        if ($legalAnalysis !== null) {
            if ($reviewRequired === false && array_key_exists('review_required', $input)) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Legal analysis always requires human review');
            }
            $reviewRequired = true;
        }

        return [
            'business_scope' => $businessScope,
            'workflow' => ['code' => $workflowCode, 'version' => $workflowVersion],
            'files' => array_values($files),
            'output_schema' => ['code' => (string) $expectedSchema['code'], 'version' => (string) $expectedSchema['version']],
            'review_required' => $reviewRequired,
            'definition' => $definition,
            'analysis_profile' => $analysisProfile,
            'legal_analysis' => $legalAnalysis,
        ];
    }

    /** @param array<string, mixed> $value */
    public static function fingerprint(array $value): string
    {
        return hash('sha256', json_encode(self::canonicalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private static function opaqueReference(mixed $value, string $name): string
    {
        if (!is_string($value)) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', "{$name} must be an opaque reference");
        }
        $value = trim($value);
        if ($value === '' || mb_strlen($value, 'UTF-8') > 512) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', "{$name} must contain one to 512 characters");
        }
        return $value;
    }

    /** @return array<string, mixed> */
    private static function object(mixed $value, string $name): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', "{$name} must be an object");
        }
        return $value;
    }

    /** @param array<string, mixed> $value @param list<string> $allowed */
    private static function rejectUnknown(array $value, array $allowed): void
    {
        if (array_diff(array_keys($value), $allowed) !== []) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'The request contains unsupported fields');
        }
    }

    private static function code(mixed $value, string $name): string
    {
        $value = is_string($value) ? trim($value) : '';
        if (!preg_match('/^[a-z][a-z0-9_]{1,63}$/', $value)) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', "{$name} is invalid");
        }
        return $value;
    }

    private static function version(mixed $value, string $name): string
    {
        $value = is_string($value) ? trim($value) : '';
        if (!preg_match('/^\d+\.\d+\.\d+$/', $value)) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', "{$name} is invalid");
        }
        return $value;
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::canonicalize(...), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }
        return $value;
    }
}
