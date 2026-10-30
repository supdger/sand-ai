<?php

declare(strict_types=1);

return [
    'definitions' => [
        'source_manifest' => [
            'version' => '1.0.0',
            'data_class' => null,
            'input_schema' => [
                'business_scope' => ['ref', 'snapshot_ref', 'snapshot_version'],
                'files' => ['file_id', 'sha256', 'version_ref'],
            ],
            'output_schema' => [
                'code' => 'sand_ai.source_manifest',
                'version' => '1.0.0',
            ],
            'steps' => ['build_source_manifest'],
        ],
        'material_analysis' => [
            'version' => '1.0.0',
            'data_class' => null,
            'input_schema' => [
                'business_scope' => ['ref', 'snapshot_ref', 'snapshot_version'],
                'files' => ['file_id', 'sha256', 'version_ref'],
                'analysis_profile' => ['code', 'version'],
            ],
            'analysis_profile_required' => true,
            'output_schema' => [
                'code' => 'sand_ai.material_analysis',
                'version' => '1.0.0',
            ],
            'steps' => ['analyze_materials'],
        ],
        'legal_analysis' => [
            'version' => '1.0.0',
            'data_class' => null,
            'input_schema' => [
                'business_scope' => ['ref', 'snapshot_ref', 'snapshot_version'],
                'files' => ['file_id', 'sha256', 'version_ref'],
                'legal_profile' => ['code', 'version'],
                'legal_context' => ['issues', 'jurisdiction', 'case_type', 'as_of_date'],
                'confirmed_facts' => ['fact_ref', 'fact_version', 'summary', 'source_block_ids'],
                'legal_sources' => ['source_ref', 'source_version', 'file_id', 'file_sha256', 'parse_id', 'version_ref', 'source_block_ids'],
            ],
            'output_schema' => [
                'code' => 'sand_ai.legal_analysis',
                'version' => '1.0.0',
            ],
            'steps' => ['analyze_legal_sources'],
        ],
    ],
];
