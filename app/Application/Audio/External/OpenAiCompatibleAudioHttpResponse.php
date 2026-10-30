<?php

declare(strict_types=1);

namespace app\Application\Audio\External;

/** Bounded body supplied by the transport; truncated bodies must be marked explicitly. */
final readonly class OpenAiCompatibleAudioHttpResponse
{
    public function __construct(public int $status, public string $body, public bool $truncated = false) {}
}
