<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use app\Api\Support\ApiProblem;

final class MaterialAnalysisProfileDraftPolicy
{
    /** @param array<string,mixed> $input @return array{environment_id:int,expected_revision:?int,name:string,config:array<string,mixed>} */
    public function normalize(array $input): array
    {
        if (array_diff(array_keys($input), ['environment_id', 'expected_revision', 'profile']) !== []
            || !array_key_exists('expected_revision', $input)
            || !is_int($input['environment_id'] ?? null) || $input['environment_id'] < 1
            || !is_array($input['profile'] ?? null)) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', '材料整理配置请求字段无效');
        }
        $expected = $input['expected_revision'];
        if ($expected !== null && (!is_int($expected) || $expected < 1)) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', '材料整理配置修订号无效');
        }
        $profile = $input['profile'];
        if (array_diff(array_keys($profile), ['code', 'version', 'name', 'output_language', 'document_classifications',
            'entity_types', 'extension_fields', 'max_prompt_characters', 'max_output_characters', 'review_output_strategy']) !== []
            || !is_string($profile['name'] ?? null)) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', '材料整理配置字段无效');
        }
        $name = trim($profile['name']);
        if ($name === '' || preg_match('/\A[^\x00-\x1f\x7f]{1,160}\z/uD', $name) !== 1) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', '材料整理配置名称无效');
        }
        unset($profile['name']);
        $config = (new MaterialAnalysisProfilePolicy())->validate($profile);
        return [
            'environment_id' => $input['environment_id'],
            'expected_revision' => $expected,
            'name' => $name,
            'config' => $config,
        ];
    }
}
