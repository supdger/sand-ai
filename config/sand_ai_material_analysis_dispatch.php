<?php

return [
    // Operators can pause trusted structured dispatch explicitly during maintenance.
    'material_analysis_dispatch_enabled' => filter_var(env('SAND_AI_MATERIAL_ANALYSIS_DISPATCH_ENABLED', true), FILTER_VALIDATE_BOOL),
    // Hard budgets require an explicit output bound. This is server-owned and
    // never accepted from a public workflow request.
    'max_output_tokens' => (int) env('SAND_AI_MATERIAL_ANALYSIS_MAX_OUTPUT_TOKENS', 4096),
];
