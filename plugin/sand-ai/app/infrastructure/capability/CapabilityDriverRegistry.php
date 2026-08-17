<?php

declare(strict_types=1);

namespace plugin\SandAi\app\infrastructure\capability;

use plugin\SandAi\app\api\support\ApiProblem;

/** Code-owned capability metadata; database configuration cannot invent drivers. */
final class CapabilityDriverRegistry
{
    /** @return array<string, array<string, mixed>> */
    public static function metadata(): array
    {
        return [
            'chat' => [
                'route_kind' => 'model_deployment',
                'description' => 'Chat completion model deployment',
                'model_types' => ['chat'],
            ],
            'vision' => [
                'route_kind' => 'model_deployment',
                'description' => 'Vision model deployment',
                'model_types' => ['chat'],
            ],
            'embedding' => [
                'route_kind' => 'model_deployment',
                'description' => 'Embedding model deployment',
                'model_types' => ['embedding'],
            ],
            'rerank' => [
                'route_kind' => 'model_deployment',
                'description' => 'Rerank model deployment',
                'model_types' => ['rerank'],
            ],
            'document_parse' => [
                'route_kind' => 'parse_driver',
                'description' => 'Private document parsing driver',
                'drivers' => [
                    'native_document_parse' => [
                        'data_egress' => 'local_only',
                        'media_types' => ['text/plain', 'application/pdf', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
                    ],
                ],
            ],
            'image_ocr' => [
                'route_kind' => 'parse_driver',
                'description' => 'Image or scanned-document OCR driver',
                'drivers' => [
                    'ocr_unconfigured' => [
                        'data_egress' => 'local_only',
                        'media_types' => ['image/png', 'image/jpeg', 'image/webp', 'application/pdf'],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function capability(string $code): array
    {
        $metadata = self::metadata()[$code] ?? null;
        if ($metadata === null) {
            throw new ApiProblem('SAND_AI_CAPABILITY_UNSUPPORTED', 'The capability code is not registered');
        }

        return $metadata;
    }

    /** @return array<string, mixed> */
    public static function parseDriver(string $capabilityCode, string $driverCode): array
    {
        $capability = self::capability($capabilityCode);
        $driver = $capability['drivers'][$driverCode] ?? null;
        if (!is_array($driver)) {
            throw new ApiProblem('SAND_AI_CAPABILITY_UNSUPPORTED', 'The parse driver is not registered for this capability');
        }

        return $driver;
    }
}
