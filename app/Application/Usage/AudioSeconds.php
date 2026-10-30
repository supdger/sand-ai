<?php

declare(strict_types=1);

namespace app\Application\Usage;

use app\Api\Support\ApiProblem;

/** A positive, canonical numeric(20,6)-compatible duration; never a float. */
final readonly class AudioSeconds
{
    public string $value;

    public function __construct(string $value)
    {
        if (preg_match('/\A([0-9]{1,14})(?:\.([0-9]{1,6}))?\z/D', $value, $matches) !== 1
            || (strlen($matches[1]) > 1 && $matches[1][0] === '0')) {
            throw new ApiProblem('SAND_AI_AUDIO_SECONDS_INVALID', 'audio_seconds must be a positive decimal(20,6) string');
        }
        $normalized = $matches[1] . '.' . str_pad($matches[2] ?? '', 6, '0');
        if ($normalized === '0.000000') {
            throw new ApiProblem('SAND_AI_AUDIO_SECONDS_INVALID', 'audio_seconds must be positive');
        }
        $this->value = $normalized;
    }
}
