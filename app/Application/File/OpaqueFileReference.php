<?php

declare(strict_types=1);

namespace app\Application\File;

use app\Api\Support\ApiProblem;

/** New upload-only opaque reference; it deliberately cannot be derived from a file id or storage value. */
final class OpaqueFileReference
{
    public static function new(): string
    {
        return 'saf_' . rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    }

    public static function assert(string $value): void
    {
        if (preg_match('/\Asaf_[A-Za-z0-9_-]{16,120}\z/D', $value) !== 1 || str_contains($value, '/') || str_contains($value, ':')) {
            throw new ApiProblem('SAND_AI_AUDIO_REFERENCE_FORBIDDEN', 'The file reference is invalid');
        }
    }
}
