<?php

declare(strict_types=1);

// Public hosts/origins are an explicit deployment allowlist, never taken from request input.
return [
    'allowed_hosts' => ['localhost', '127.0.0.1', '[::1]'],
    'allowed_origins' => [],
    'max_body_bytes' => 1048576,
    'max_response_bytes' => 2097152,
    'session_ttl' => 1800,
];
