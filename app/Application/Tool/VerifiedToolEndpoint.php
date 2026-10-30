<?php

declare(strict_types=1);

namespace app\Application\Tool;

final readonly class VerifiedToolEndpoint
{
    /** @param list<string> $addresses */
    public function __construct(public string $origin, public string $host, public string $path, public array $addresses)
    {
    }

    public function url(): string
    {
        return $this->origin . $this->path;
    }

    public function curlResolveEntry(): string
    {
        $address = $this->addresses[0];
        if (str_contains($address, ':')) $address = '[' . $address . ']';
        return $this->host . ':443:' . $address;
    }
}
