<?php

declare(strict_types=1);

namespace app\Application\Audio\External;

use app\Api\Support\ApiProblem;

/**
 * The only adapter result accepted by the durable audio coordinator.  In
 * particular, a synchronous completion is not represented as an empty async
 * job: it carries the canonical result which is persisted before metering.
 */
final readonly class ExternalAudioDispatchOutcome
{
    private function __construct(
        public string $state,
        public ?string $providerJobId = null,
        public ?string $acceptedReceipt = null,
        /** @var array<string,mixed>|null */
        public ?array $canonicalResult = null,
    ) {
        if (!in_array($state, ['completed', 'accepted', 'ambiguous', 'not_dispatched'], true)) {
            throw self::invalid();
        }
        if ($state === 'accepted') {
            if (!is_string($providerJobId) || preg_match('/\A[a-zA-Z0-9._:-]{1,191}\z/', $providerJobId) !== 1 || !is_string($acceptedReceipt) || $acceptedReceipt === '' || strlen($acceptedReceipt) > 1024) {
                throw self::invalid();
            }
            return;
        }
        if ($state === 'completed' && (!is_array($canonicalResult) || $providerJobId !== null || $acceptedReceipt !== null)) {
            throw self::invalid();
        }
        if ($state !== 'completed' && ($providerJobId !== null || $acceptedReceipt !== null || $canonicalResult !== null)) {
            throw self::invalid();
        }
    }

    /** @param array<string,mixed> $canonicalResult */
    public static function completed(array $canonicalResult): self { return new self('completed', canonicalResult: $canonicalResult); }
    public static function accepted(string $providerJobId, string $acceptedReceipt): self { return new self('accepted', $providerJobId, $acceptedReceipt); }
    public static function ambiguous(): self { return new self('ambiguous'); }
    public static function notDispatched(): self { return new self('not_dispatched'); }
    private static function invalid(): ApiProblem { return new ApiProblem('SAND_AI_EXTERNAL_AUDIO_RESPONSE_INVALID', 'External audio adapter outcome is invalid'); }
}
