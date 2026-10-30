<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use app\Api\Support\ApiProblem;

final class MaterialAnalysisProfilePolicy
{
    // Profile labels must fit the same fields accepted by the result Schema.
    public const MAX_ENTITY_TYPE_CHARACTERS = 64;
    public const MAX_DOCUMENT_CLASSIFICATION_CHARACTERS = 2000;

    private const MAX_PROMPT_CHARACTERS = 200000;
    private const MAX_OUTPUT_CHARACTERS = 500000;

    /** @var list<string> */
    private const FIELDS = [
        'code',
        'version',
        'output_language',
        'document_classifications',
        'entity_types',
        'extension_fields',
        'max_prompt_characters',
        'max_output_characters',
    ];

    /** @param array<string,mixed> $profile @return array{code:string,version:string,output_language:string,document_classifications:list<string>,entity_types:list<string>,extension_fields:list<string>,max_prompt_characters:int,max_output_characters:int} */
    public function validate(array $profile): array
    {
        if (array_diff(array_keys($profile), [...self::FIELDS, 'review_output_strategy']) !== [] || array_diff(self::FIELDS, array_keys($profile)) !== []) {
            throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'The material analysis profile has unsupported fields');
        }

        $code = $this->code($profile['code'] ?? null);
        $version = $profile['version'] ?? null;
        if (!is_string($version) || strlen($version) > 32 || !preg_match('/^\d+\.\d+\.\d+$/D', $version)) {
            throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', '材料整理配置版本须为三个数字段，例如 1.0.1，最多 32 个字符');
        }
        $language = $profile['output_language'] ?? null;
        if (!is_string($language) || !in_array($language, ['zh-CN', 'en'], true)) {
            throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'The material analysis profile output language is invalid');
        }

        $normalized = [
            'code' => $code,
            'version' => $version,
            'output_language' => $language,
            'document_classifications' => $this->stringList($profile['document_classifications'] ?? null, '材料分类', true, false, self::MAX_DOCUMENT_CLASSIFICATION_CHARACTERS),
            'entity_types' => $this->stringList($profile['entity_types'] ?? null, '实体类型', true, false, self::MAX_ENTITY_TYPE_CHARACTERS),
            'extension_fields' => $this->stringList($profile['extension_fields'] ?? null, '扩展字段', false, true, 64),
            'max_prompt_characters' => $this->limit($profile['max_prompt_characters'] ?? null, 'prompt', self::MAX_PROMPT_CHARACTERS),
            'max_output_characters' => $this->limit($profile['max_output_characters'] ?? null, 'output', self::MAX_OUTPUT_CHARACTERS),
        ];
        if (array_key_exists('review_output_strategy', $profile)) {
            $strategy = $profile['review_output_strategy'];
            if (!is_string($strategy) || !in_array($strategy, [
                \app\Application\Skill\SkillOutputStrategy::EXTRACT,
                \app\Application\Skill\SkillOutputStrategy::ZAI,
                \app\Application\Skill\SkillOutputStrategy::OPENAI,
            ], true)) {
                throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'Review output strategy is unsupported');
            }
            $normalized['review_output_strategy'] = $strategy;
        }
        return $normalized;
    }

    private function code(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $value)) {
            throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', '材料整理配置代码须以小写字母开头，只含小写字母、数字和下划线，最多 64 个字符');
        }
        return $value;
    }

    /** @return list<string> */
    private function stringList(mixed $value, string $name, bool $required, bool $fieldCodes, int $maximum): array
    {
        if (!is_array($value) || !array_is_list($value) || ($required && $value === [])) {
            throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', "{$name}须为列表" . ($required ? '，且至少填写一项' : '，无内容时使用空列表'));
        }
        $items = [];
        foreach ($value as $item) {
            if (!is_string($item) || $item === '' || !mb_check_encoding($item, 'UTF-8')
                || mb_strlen($item, 'UTF-8') > $maximum
                || preg_match('/[\p{Cc}\p{Zl}\p{Zp}]|\A\s|\s\z/u', $item) !== 0) {
                throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', "{$name}每项须为 1–{$maximum} 个有效字符，不含控制字符、换行或首尾空白");
            }
            if ($fieldCodes && !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $item)) {
                throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', '扩展字段须以小写字母开头，只含小写字母、数字和下划线，最多 64 个字符');
            }
            if (in_array($item, $items, true)) {
                throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', "{$name}不能重复");
            }
            $items[] = $item;
        }
        return $items;
    }

    private function limit(mixed $value, string $name, int $maximum): int
    {
        if (!is_int($value) || $value < 1 || $value > $maximum) {
            throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', "The material analysis profile {$name} limit is invalid");
        }
        return $value;
    }
}
