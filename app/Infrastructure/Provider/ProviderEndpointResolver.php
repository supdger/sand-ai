<?php

declare(strict_types=1);

namespace app\Infrastructure\Provider;

interface ProviderEndpointResolver
{
    /** @param array<string,mixed> $config */
    public function chatCompletions(array $config): ResolvedProviderEndpoint;

    /** @param array<string,mixed> $config */
    public function embeddings(array $config): ResolvedProviderEndpoint;
}
