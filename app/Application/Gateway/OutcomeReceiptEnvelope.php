<?php

declare(strict_types=1);

namespace app\Application\Gateway;

/** @internal Ciphertext-only value; never expose through runtime DTOs. */
final readonly class OutcomeReceiptEnvelope
{
    public function __construct(
        public string $receiptVersion,
        public string $cipherVersion,
        public string $keyId,
        public string $nonce,
        public string $tag,
        public string $ciphertext,
        public int $plaintextBytes,
        public string $contentHmac,
    ) {
    }

    public function ciphertextBytes(): int
    {
        return strlen($this->ciphertext);
    }
}
