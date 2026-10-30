<?php

declare(strict_types=1);

namespace app\Application\Onboarding;

/** Only local enums, HTTP status and bounded structural counts cross this boundary. */
final class EmbeddingVerificationFailure extends \RuntimeException
{
    private const STAGES = ['http_status', 'response_limit', 'invalid_json', 'response_shape', 'transport'];

    /** @param array<string,mixed>|null $shape */
    public function __construct(public readonly ?int $httpStatus, public readonly string $stage, public readonly ?array $shape = null)
    {
        parent::__construct('Embedding verification outcome is ambiguous');
    }

    /** @return array<string,mixed> */
    public function diagnostic(): array
    {
        return self::readDiagnostic(['http_status' => $this->httpStatus, 'failure_stage' => $this->stage, 'response_shape' => $this->shape]) ?? [];
    }

    /** Never project unknown stored fields or vendor strings. @return array<string,mixed>|null */
    public static function readDiagnostic(mixed $value): ?array
    {
        if (!is_array($value) || !in_array($value['failure_stage'] ?? null, self::STAGES, true)) {
            return null;
        }
        $status = $value['http_status'] ?? null;
        if ($status !== null && (!is_int($status) || $status < 100 || $status > 599)) {
            return null;
        }
        $shape = $value['response_shape'] ?? null;
        $safeShape = null;
        if ($shape !== null) {
            if (!is_array($shape) || !in_array($shape['root_type'] ?? null, ['object', 'array', 'scalar'], true)
                || !is_bool($shape['data_is_list'] ?? null) || !is_bool($shape['usage_present'] ?? null)
                || !is_bool($shape['error_present'] ?? null)) {
                return null;
            }
            foreach (['data_count', 'first_vector_dimensions'] as $field) {
                $count = $shape[$field] ?? null;
                if ($count !== null && (!is_int($count) || $count < 0 || $count > 1000000)) {
                    return null;
                }
            }
            $safeShape = [
                'root_type' => $shape['root_type'], 'data_is_list' => $shape['data_is_list'],
                'data_count' => $shape['data_count'] ?? null, 'first_vector_dimensions' => $shape['first_vector_dimensions'] ?? null,
                'usage_present' => $shape['usage_present'], 'error_present' => $shape['error_present'],
            ];
        }
        return ['http_status' => $status, 'failure_stage' => $value['failure_stage'], 'response_shape' => $safeShape];
    }
}
