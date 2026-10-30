<?php

declare(strict_types=1);

return [
    // Explicit opt-in only. Copy exact inputSchema/outputSchema from the organization's
    // subscribed tools/list. Keep null until that material is actually available.
    // Missing outputSchema is represented by null; never fabricate a JSON schema.
    // Existing managed MCP connections own endpoint, authorization credential and fees.
    'enabled' => false,
    'search_article' => ['input_schema' => null, 'output_schema' => null],
    'get_article' => ['input_schema' => null, 'output_schema' => null],
    'search_case' => ['input_schema' => null, 'output_schema' => null],
    // Separate published contract. Fill exact tools/list name and schemas, then explicitly choose actual filter names.
    'search_case_filtered' => ['enabled' => false, 'remote_name' => null, 'input_schema' => null,
        'output_schema' => null, 'exposed_filters' => []],
];
