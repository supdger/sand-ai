<?php

declare(strict_types=1);

namespace app\Application\Gateway;

use app\Api\Support\ApiProblem;
use app\Application\Usage\UsageAttribution;
use app\Identity\Contract\IdentityContext;
use support\Request;

/** Public workload idempotency keys never fall back to a trace identifier. */
final class RequestIdempotencyKey
{
    /**
     * @param string|null $scopeDiscriminator optional extra scope dimension
     *        (e.g. the trial administrator id) hashed into the derived key
     *        without changing trustedScope(); public/workload callers omit it
     *        and keep the legacy derivation.
     */
    public static function required(Request $request, string $domain, IdentityContext $context, ?string $scopeDiscriminator = null): string
    {
        return self::fromCallerKey((string) $request->header('idempotency-key', ''), $domain, $context, $scopeDiscriminator);
    }

    /**
     * Cheap pre-issuance check of the raw caller header. The scope-bound
     * derivation still requires the issued identity context, so callers run
     * `required()` after issuance; this rejects missing or malformed keys
     * before any credential or audit row is written.
     */
    public static function preflight(Request $request): void
    {
        self::headerValue($request);
    }

    /**
     * Derive the original request id without submitting another workload.
     * $scopeDiscriminator is hashed into a derived scope so callers that share
     * one workload identity (the application-level managed trial client) can
     * still isolate keys per principal; it never alters trustedScope() itself.
     */
    public static function fromCallerKey(string $key, string $domain, IdentityContext $context, ?string $scopeDiscriminator = null): string
    {
        $key = self::validatedCallerKey($key);
        $scope = self::trustedScope($context);
        if ($scopeDiscriminator !== null && $scopeDiscriminator !== '') {
            $scope = hash('sha256', json_encode([
                'scope' => $scope,
                'discriminator' => $scopeDiscriminator,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        }
        return $domain . ':' . hash('sha256', $scope . ':' . $key);
    }

    /** Bind a caller key to a resource while keeping the persisted key bounded. */
    public static function requiredForResource(Request $request, string $domain, IdentityContext $context, string $resourceId): string
    {
        $key = self::headerValue($request);
        if ($resourceId === '' || strlen($domain) > 30) {
            throw new ApiProblem('SAND_AI_IDEMPOTENCY_KEY_REQUIRED', 'Idempotency scope is invalid');
        }
        return $domain . ':' . hash('sha256', json_encode([
            self::trustedScope($context), $resourceId, $key,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private static function headerValue(Request $request): string
    {
        return self::validatedCallerKey((string) $request->header('idempotency-key', ''));
    }

    private static function validatedCallerKey(string $key): string
    {
        $key = trim($key);
        if ($key === '' || strlen($key) > 512 || preg_match('/\A[\x21-\x7e]+\z/', $key) !== 1) {
            throw new ApiProblem('SAND_AI_IDEMPOTENCY_KEY_REQUIRED', 'Idempotency-Key is required and must be visible ASCII');
        }
        return $key;
    }

    /** Stable opaque scope persisted only as part of a request fingerprint. */
    public static function trustedScope(IdentityContext $context): string
    {
        return self::trustedScopeForValues(
            $context->environmentId,
            $context->applicationId,
            $context->workloadClientId,
        );
    }

    /**
     * Uses the exact same canonical identity representation for persisted task
     * replay fingerprints as for public Idempotency-Key derivation.
     */
    public static function trustedScopeForAttribution(UsageAttribution $attribution): string
    {
        return self::trustedScopeForCanonicalIdentity($attribution->canonicalTrustedIdentity());
    }

    /** Stable opaque scope; identifiers are structurally encoded, never joined. */
    public static function trustedScopeForValues(int $environmentId, string $applicationId, string $workloadClientId): string
    {
        // JSON keeps the three IAM values structurally separate. Delimiter
        // concatenation is ambiguous because SandIAM identifiers are strings.
        return hash('sha256', json_encode([
            'version' => 1,
            'environment_id' => $environmentId,
            'application_id' => $applicationId,
            'workload_client_id' => $workloadClientId,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** @param array<string,int|string|null> $identity */
    public static function trustedScopeForCanonicalIdentity(array $identity): string
    {
        // Persisted task scopes must bind every frozen identity dimension,
        // including the optional local ids. The explicit null values are part
        // of the encoding, so changing a local application/workload id while
        // preserving an opaque SandIAM ref is never a replay match.
        return hash('sha256', json_encode([
            'version' => 2,
            'identity' => $identity,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
