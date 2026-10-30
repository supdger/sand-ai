<?php

declare(strict_types=1);

namespace app\Application\Gateway;

/** Whitelisted received identifiers, never a supplier billing or payment fact. */
final class ProviderResponseIdentity
{
    private function __construct(public readonly string $kind, public readonly string $source, public readonly string $value)
    {
    }

    /** @param array<string,string> $headers @return list<string> */
    public static function credentials(array $headers): array
    {
        $values = [];
        foreach ($headers as $value) {
            if (!is_string($value) || $value === '') continue;
            $values[] = $value;
            if (str_starts_with($value, 'Bearer ')) $values[] = substr($value, 7);
        }
        return $values;
    }

    /** @param list<string> $credentials */
    public static function received(string $source, mixed $value, array $credentials = []): ?self
    {
        $kind = match ($source) {
            'header.x-request-id', 'header.request-id' => 'request_id',
            'body.id', 'stream.id' => 'response_id',
            default => null,
        };
        if ($kind === null || !is_string($value) || preg_match('/\A[A-Za-z0-9_.:-]{1,128}\z/D', $value) !== 1) {
            return null;
        }
        foreach ($credentials as $credential) {
            if ($credential !== '' && (str_contains($value, $credential) || str_contains($credential, $value))) {
                return null;
            }
        }
        return new self($kind, $source, $value);
    }
}
