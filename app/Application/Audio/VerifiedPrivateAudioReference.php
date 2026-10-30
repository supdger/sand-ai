<?php

declare(strict_types=1);

namespace app\Application\Audio;

use app\Api\Support\ApiProblem;

/** Safe result of an authoritative private-file check; it deliberately contains no storage location. */
final class VerifiedPrivateAudioReference
{
    /** @var list<string> */
    private const MEDIA_TYPES = ['audio/mpeg', 'audio/mp4', 'audio/ogg', 'audio/wav', 'audio/webm', 'audio/flac'];

    public function __construct(
        public readonly string $fileRef,
        public readonly string $mediaType,
        public readonly string $contentSha256,
        public readonly ?string $trustedScope = null,
    ) {
        if (preg_match('/\Asaf_[A-Za-z0-9_-]{16,120}\z/D', $fileRef) !== 1
            || !in_array($mediaType, self::MEDIA_TYPES, true)
            || preg_match('/\A[a-f0-9]{64}\z/D', $contentSha256) !== 1
            || ($trustedScope !== null && preg_match('/\A[a-f0-9]{64}\z/D', $trustedScope) !== 1)) {
            throw new ApiProblem('SAND_AI_AUDIO_REFERENCE_FORBIDDEN', 'The private audio reference is invalid');
        }
    }
}
