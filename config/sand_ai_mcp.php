<?php

declare(strict_types=1);

return [
    'protocol_version' => '2025-11-25',
    'max_pages' => 20,
    'max_tools' => 500,
    'max_schema_bytes' => 524288,
    'tools' => [
        'external_search' => [
            'name' => '外部来源搜索',
            'description' => '使用受管只读搜索连接检索来源；结果必须带可核对的版本或内容摘要引用',
            'remote_name' => 'search',
            'citation_mode' => 'sand_ai_standard',
            'input_schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['query', 'limit'],
                'properties' => [
                    'query' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 2000],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20],
                ],
            ],
            'output_schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['matches'],
                'properties' => [
                    'matches' => [
                        'type' => 'array',
                        'maxItems' => 20,
                        'items' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'required' => ['source_ref', 'title', 'excerpt'],
                            'properties' => [
                                'source_ref' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 256],
                                'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 300],
                                'excerpt' => ['type' => 'string', 'maxLength' => 1000],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'external_knowledge_query' => [
            'name' => '外部知识查询',
            'description' => '从受管外部知识源执行只读查询并返回带来源的结构化结果',
            'remote_name' => 'knowledge_query',
            'citation_mode' => 'sand_ai_standard',
            'input_schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['query'],
                'properties' => [
                    'query' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 4000],
                ],
            ],
            'output_schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['answer'],
                'properties' => [
                    'answer' => ['type' => 'string', 'maxLength' => 65536],
                ],
            ],
        ],
        'external_document_search' => [
            'name' => '公开文档搜索',
            'description' => '从已注册的公开文档连接搜索，返回受控来源与实际内容摘要',
            'remote_name' => 'microsoft_docs_search',
            'citation_mode' => 'sand_ai_standard',
            'input_schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['query', 'limit'],
                'properties' => [
                    'query' => [
                        'type' => 'string',
                        'minLength' => 1,
                        'maxLength' => 2000,
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => 20,
                    ],
                ],
            ],
            'output_schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['matches'],
                'properties' => [
                    'matches' => [
                        'type' => 'array',
                        'maxItems' => 20,
                        'items' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'required' => ['source_ref', 'title', 'excerpt'],
                            'properties' => [
                                'source_ref' => [
                                    'type' => 'string',
                                    'minLength' => 1,
                                    'maxLength' => 256,
                                ],
                                'title' => [
                                    'type' => 'string',
                                    'minLength' => 1,
                                    'maxLength' => 300,
                                ],
                                'excerpt' => [
                                    'type' => 'string',
                                    'maxLength' => 1000,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'adapter' => [
                'kind' => 'document_search@1',
                'remote_input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'description' => 'a query or topic about Microsoft/Azure products, services, platforms, developer tools, frameworks, or APIs',
                            'type' => 'string',
                            'default' => null,
                        ],
                    ],
                ],
                'remote_output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'results' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'id' => [
                                        'type' => ['string', 'null'],
                                    ],
                                    'title' => [
                                        'type' => 'string',
                                    ],
                                    'content' => [
                                        'type' => ['string', 'null'],
                                    ],
                                    'contentUrl' => [
                                        'type' => 'string',
                                    ],
                                    'content_omitted' => [
                                        'type' => 'boolean',
                                    ],
                                    'extensionData' => [
                                        'type' => ['object', 'null'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'argument_map' => [
                    'query' => 'query',
                ],
                'source_hosts' => ['learn.microsoft.com'],
                'search_fields' => [
                    'items' => 'results',
                    'title' => 'title',
                    'content' => 'content',
                    'url' => 'contentUrl',
                ],
            ],
        ],
        'external_document_fetch' => [
            'name' => '公开文档读取',
            'description' => '读取已注册公开域名上的文档，返回实际文本与来源摘要',
            'remote_name' => 'microsoft_docs_fetch',
            'citation_mode' => 'sand_ai_standard',
            'input_schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['url'],
                'properties' => [
                    'url' => [
                        'type' => 'string',
                        'minLength' => 1,
                        'maxLength' => 512,
                    ],
                ],
            ],
            'output_schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['content'],
                'properties' => [
                    'content' => [
                        'type' => 'string',
                        'minLength' => 1,
                        'maxLength' => 60000,
                    ],
                ],
            ],
            'adapter' => [
                'kind' => 'document_fetch@1',
                'remote_input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'url' => [
                            'description' => 'URL of the Microsoft documentation page to read',
                            'type' => 'string',
                        ],
                    ],
                    'required' => ['url'],
                ],
                'remote_output_schema' => null,
                'argument_map' => [
                    'url' => 'url',
                ],
                'source_hosts' => ['learn.microsoft.com'],
            ],
        ],
    ],
];
