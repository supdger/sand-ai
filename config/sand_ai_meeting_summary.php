<?php

declare(strict_types=1);

return [
    // Historical meeting_capacity@1 tasks retain their existing config-driven mode.
    // New @2 tasks use the administrator meeting-execution registration and its
    // frozen owner/route/capacity proof; they do not require editing this file.
    'durable_worker' => ['enabled' => false],
    // Historical @1 registrations only, keyed by published route snapshot SHA-256.
    // Use the normal administration setup for new persistent @2 registrations.
    // No model alias, proxy, Coding Plan endpoint, or fallback is inferred.
    // Values: context_window_tokens, input_token_limit, capacity_source_url,
    // capacity_source_sha256, max_tokenizer_requests (default 100000, min 40000).
    // Segment count is <=10000; the audio producer also retains its 8 MiB
    // total transcript limit. Tokenizer requests include preparation and merges.
    // The official Flash document says 1M; a registration
    // may use at most the conservative 1,000,000 ceiling.
    'registrations' => [],
];
