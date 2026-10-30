<?php

declare(strict_types=1);

/*
 * Consumer-facing fields are selected through a versioned profile.  The
 * default deliberately stays cross-domain: a consumer may add a profile in
 * its own controlled deployment, but SandAI does not learn case, matter, or
 * industry-specific field names here.
 */
return [
    'profiles' => [
        'generic_material_analysis' => [
            'version' => '1.0.0',
            'output_language' => 'zh-CN',
            'document_classifications' => [
                'correspondence',
                'record',
                'report',
                'form',
                'statement',
                'other',
            ],
            'entity_types' => [
                'person',
                'organization',
                'location',
                'identifier',
                'amount',
                'date',
            ],
            // Extension values are disabled by default.  A future controlled
            // profile may register narrowly validated extension field codes.
            'extension_fields' => [],
            'max_prompt_characters' => 48000,
            'max_output_characters' => 120000,
        ],
    ],
];
