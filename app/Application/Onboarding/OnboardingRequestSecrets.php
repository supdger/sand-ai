<?php

declare(strict_types=1);

namespace app\Application\Onboarding;

use ReflectionClass;

/**
 * Keeps onboarding credentials outside serializable request parameters after
 * the route guard has redacted the request. Values exist only for the lifetime
 * of the in-process request object.
 */
final class OnboardingRequestSecrets
{
    /** @var \WeakMap<object, array<string, mixed>>|null */
    private static ?\WeakMap $connections = null;

    /** @var \WeakMap<object, string>|null */
    private static ?\WeakMap $billingAdminKeys = null;

    /** @var \WeakMap<object, string>|null */
    private static ?\WeakMap $billingOrganizationIds = null;

    /** @var \WeakMap<object, \app\Infrastructure\Provider\RequestScopedProviderConnection>|null */
    private static ?\WeakMap $deploymentConnections = null;

    /** @param array<string, mixed> $connection */
    public static function store(object $request, array $connection): void
    {
        self::$connections ??= new \WeakMap();
        self::$connections[$request] = $connection;
    }

    /** @return array<string, mixed>|null */
    public static function take(object $request): ?array
    {
        return self::$connections[$request] ?? null;
    }

    public static function storeBillingAdminKey(object $request, string $key): void
    {
        self::$billingAdminKeys ??= new \WeakMap();
        self::$billingAdminKeys[$request] = $key;
    }

    public static function billingAdminKey(object $request): ?string
    {
        return self::$billingAdminKeys[$request] ?? null;
    }

    public static function storeBillingOrganizationId(object $request, string $organizationId): void
    {
        self::$billingOrganizationIds ??= new \WeakMap();
        self::$billingOrganizationIds[$request] = $organizationId;
    }

    public static function billingOrganizationId(object $request): ?string
    {
        return self::$billingOrganizationIds[$request] ?? null;
    }

    public static function storeDeploymentConnection(object $request, \app\Infrastructure\Provider\RequestScopedProviderConnection $connection): void
    {
        self::$deploymentConnections ??= new \WeakMap();
        self::$deploymentConnections[$request] = $connection;
    }

    /** One request may consume sealed deployment material only once. */
    public static function consumeDeploymentConnection(object $request): ?\app\Infrastructure\Provider\RequestScopedProviderConnection
    {
        $connection = self::$deploymentConnections[$request] ?? null;
        if (self::$deploymentConnections !== null) {
            unset(self::$deploymentConnections[$request]);
        }
        return $connection;
    }

    public static function clear(object $request): void
    {
        if (self::$connections !== null) {
            unset(self::$connections[$request]);
        }
        if (self::$billingAdminKeys !== null) {
            unset(self::$billingAdminKeys[$request]);
        }
        if (self::$billingOrganizationIds !== null) {
            unset(self::$billingOrganizationIds[$request]);
        }
        if (self::$deploymentConnections !== null) {
            unset(self::$deploymentConnections[$request]);
        }
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public static function redact(array $payload): array
    {
        $result = [];
        foreach ($payload as $key => $value) {
            $name = is_string($key) ? $key : (string) $key;
            if (self::isSensitiveName($name)) {
                $result[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $result[$key] = self::redact($value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /** Remove a connection object entirely once its contents were placed in the request vault. */
    public static function withoutConnection(array $payload): array
    {
        unset($payload['connection']);
        return self::redact($payload);
    }

    /**
     * Webman's request has no public post replacement API in the supported
     * dependency range. Keep this narrowly scoped and fail closed if the
     * underlying request cannot be redacted before auth/controller handling.
     *
     * @param array<string, mixed> $payload
     */
    public static function replacePost(object $request, array $payload): bool
    {
        for ($class = new ReflectionClass($request); $class instanceof ReflectionClass; $class = $class->getParentClass()) {
            // Workerman stores parsed request values in the inherited $data
            // array; there is no standalone $post property to replace.
            if (!$class->hasProperty('data')) {
                continue;
            }
            $property = $class->getProperty('data');
            $property->setAccessible(true);
            $data = $property->getValue($request);
            if (!is_array($data)) {
                return false;
            }
            $data['post'] = $payload;
            $property->setValue($request, $data);
            return true;
        }

        return false;
    }

    /**
     * Replaces the raw body as well as Content-Length so later middleware,
     * exception rendering and request stringification cannot recover secrets.
     *
     * @param array<string, mixed> $payload
     */
    public static function replaceRawBody(object $request, array $payload): bool
    {
        try {
            $contentType = method_exists($request, 'header') ? (string) $request->header('content-type', '') : '';
        } catch (\Throwable) {
            // Header parsing can itself fail for a malformed Workerman buffer.
            // Destroy it before reporting the normal replacement failure.
            self::redactRawBody($request);
            return false;
        }
        try {
            $body = str_contains(strtolower($contentType), 'json')
                ? json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : http_build_query($payload);
        } catch (\JsonException) {
            return false;
        }

        return self::replaceBufferBody($request, $body, false);
    }

    public static function redactRawBody(object $request): bool
    {
        return self::replaceBufferBody($request, '[REDACTED]', true);
    }

    /**
     * A malformed buffer cannot be reconstructed as an HTTP request.  In that
     * case a normal replacement reports failure, but first overwrites the
     * buffer; the caller must still reject the request.  The dedicated
     * redaction path permits that destroyed-buffer result as success.
     */
    private static function replaceBufferBody(object $request, string $body, bool $allowDestroyedBuffer): bool
    {
        for ($class = new ReflectionClass($request); $class instanceof ReflectionClass; $class = $class->getParentClass()) {
            if (!$class->hasProperty('buffer')) {
                continue;
            }
            $property = $class->getProperty('buffer');
            $property->setAccessible(true);
            $buffer = $property->getValue($request);
            if (!is_string($buffer)) {
                return false;
            }
            if (($separator = strpos($buffer, "\r\n\r\n")) === false) {
                $property->setValue($request, '[REDACTED]');
                return $allowDestroyedBuffer;
            }
            $head = substr($buffer, 0, $separator);
            $lengthHeader = "\r\nContent-Length: " . strlen($body);
            $updatedHead = preg_replace('/\r\nContent-Length\s*:\s*[0-9]+/i', $lengthHeader, $head, 1, $count);
            if (!is_string($updatedHead)) {
                return false;
            }
            if ($count === 0) {
                $updatedHead .= $lengthHeader;
            }
            $property->setValue($request, $updatedHead . "\r\n\r\n" . $body);
            return true;
        }

        return false;
    }

    private static function isSensitiveName(string $name): bool
    {
        // A verified input bound is public configuration, not an API bearer
        // token. Keep this exact exception before the broad historical
        // `token` secret detector so controllers can freeze it in the tested
        // deployment snapshot.
        if (in_array($name, ['input_token_ceiling', 'max_output_tokens'], true)) {
            return false;
        }

        return preg_match('/(?:api[-_]?key|billing[-_]?admin[-_]?key|secret|authorization|token|password)/i', $name) === 1;
    }
}
