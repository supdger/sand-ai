<?php

declare(strict_types=1);

return [
    'profiles' => [
        'judgment_remedy_planning' => [
            'version' => '1.0.0',
            'draft_kind' => 'research_plan',
            'output_language' => 'zh-CN',
            'max_prompt_characters' => 96000,
            'max_output_characters' => 64000,
        ],
        'judgment_remedy_analysis' => [
            'version' => '1.0.0',
            'draft_kind' => 'judgment_remedy_memo',
            'output_language' => 'zh-CN',
            'max_prompt_characters' => 96000,
            'max_output_characters' => 96000,
        ],
        'judgment_remedy_drafting' => [
            'version' => '1.0.0',
            'draft_kind' => 'remedy_document_draft',
            'output_language' => 'zh-CN',
            'max_prompt_characters' => 96000,
            'max_output_characters' => 96000,
        ],
        'legal_research_planning' => [
            'version' => '1.0.0',
            'draft_kind' => 'research_plan',
            'output_language' => 'zh-CN',
            'max_prompt_characters' => 48000,
            'max_output_characters' => 32000,
        ],
        // Source preset only. Publication remains environment-scoped and explicit.
        'complaint_drafting' => [
            'version' => '1.0.0',
            'draft_kind' => 'complaint_draft',
            'output_language' => 'zh-CN',
            'max_prompt_characters' => 64000,
            'max_output_characters' => 64000,
        ],
        'legal_research' => [
            'version' => '1.0.0',
            'draft_kind' => 'research_memo',
            'output_language' => 'zh-CN',
            'max_prompt_characters' => 48000,
            'max_output_characters' => 32000,
        ],
        'litigation_preparation' => [
            'version' => '1.0.0',
            'draft_kind' => 'litigation_outline',
            'output_language' => 'zh-CN',
            'max_prompt_characters' => 48000,
            'max_output_characters' => 32000,
        ],
        'judgment_analysis' => [
            'version' => '1.0.0',
            'draft_kind' => 'judgment_analysis_memo',
            'output_language' => 'zh-CN',
            'max_prompt_characters' => 48000,
            'max_output_characters' => 32000,
        ],
    ],
];
