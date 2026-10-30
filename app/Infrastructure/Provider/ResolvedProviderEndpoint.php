<?php

declare(strict_types=1);

namespace app\Infrastructure\Provider;

/** URL plus an optional transport-level DNS pin produced by a verified policy. */
final readonly class ResolvedProviderEndpoint
{
    public function __construct(public string $url, public ?string $curlResolveEntry)
    {
    }
}
