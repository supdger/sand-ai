<?php

declare(strict_types=1);

return [
    // Existing managed MCP connection/discovery/publication/binding owns credentials and fees.
    // No remote tool name, schema, endpoint, provider or licence is selected by default.
    'enabled' => false,
    'tools' => [],
    // Each tools[administrator_selected_local_code] requires:
    // enabled: true; name/description: local operator text;
    // remote_name: exact tools/list name;
    // input_schema: actual tools/list inputSchema, retaining original field names and constraints;
    // local input uses ToolSchemaPolicy's closed bounded subset. Descriptive annotations are
    // excluded locally (description/title/$schema/default/examples); no defaults are applied.
    // Unsupported validation keywords are rejected, never silently dropped.
    // output_schema: exact tools/list outputSchema or explicit null when absent;
    // result_mode: 'structured_content' or explicit 'mcp_text';
    // structured_content result_fields: content/title and optional source_version/license_scope,
    // each a RFC6901 JSON Pointer relative to the actual structuredContent object.
    // mcp_text result_fields: title only, a RFC6901 JSON Pointer relative to actual input.
    // MCP text mode joins actual text blocks; no title/version/licence defaults or executable mappings.
    // Missing remote_name/input_schema registers nothing. Unsupported schemas fail closed.
];
