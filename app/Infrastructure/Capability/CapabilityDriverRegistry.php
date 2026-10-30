<?php

declare(strict_types=1);

namespace app\Infrastructure\Capability;

use app\Api\Support\ApiProblem;

/**
 * Code-owned metadata: database profiles may select drivers but never invent
 * executable driver names or relax a driver's data-egress declaration.
 */
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
                'pricing_meters' => ['input_tokens', 'output_tokens'],
            ],
            'structured_extraction' => [
                'route_kind' => 'model_deployment',
                'description' => 'Structured extraction model deployment',
                'model_types' => ['chat'],
                'pricing_meters' => ['input_tokens', 'output_tokens'],
            ],
            'vision' => [
                'route_kind' => 'model_deployment',
                'description' => 'Vision model deployment',
                'model_types' => ['chat'],
                'pricing_meters' => ['input_tokens', 'output_tokens'],
            ],
            'embedding' => [
                'route_kind' => 'model_deployment',
                'description' => 'Embedding model deployment',
                'model_types' => ['embedding'],
                'pricing_meters' => ['input_tokens', 'output_tokens'],
            ],
            'rerank' => [
                'route_kind' => 'model_deployment',
                'description' => '专业重排模型部署',
                'display_name' => '专业重排',
                'model_types' => ['rerank'],
                'provider_adapters' => ['cohere_rerank', 'jina_rerank'],
                'pricing_meters' => ['rerank_search_units', 'rerank_tokens'],
                'pricing_meters_by_provider' => [
                    'cohere_rerank' => ['rerank_search_units'],
                    'jina_rerank' => ['rerank_tokens'],
                ],
            ],
            'document_parse' => [
                'route_kind' => 'parse_driver',
                'description' => 'Private document parsing driver',
                'drivers' => [
                    'native_document_parse' => [
                        'data_egress' => 'local_only',
                        'media_types' => ['text/plain', 'text/csv', 'application/pdf', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
                    ],
                ],
            ],
            'image_ocr' => [
                'route_kind' => 'parse_driver',
                'description' => 'Image or scanned-document OCR driver',
                'model_types' => ['image_ocr'],
                'provider_adapters' => ['local_ocr_runtime'],
                'pricing_meters' => ['external_request'],
                'drivers' => [
                    'local_paddle_ocr' => [
                        'data_egress' => 'local_only',
                        'media_types' => ['image/png', 'image/jpeg', 'image/webp', 'application/pdf'],
                    ],
                    'ocr_unconfigured' => [
                        'data_egress' => 'local_only',
                        'media_types' => ['image/png', 'image/jpeg', 'image/webp', 'application/pdf'],
                    ],
                ],
            ],
            'audio_transcription' => [
                'route_kind' => 'audio_driver',
                'description' => 'Private audio transcription driver',
                'display_name' => '录音转写',
                'capability_version' => 'audio_transcription@1.0.0',
                'availability' => 'configurable',
                'model_types' => ['audio_transcription'],
                'pricing_meters' => ['audio_seconds'],
                'drivers' => [
                    'glm_asr_audio' => ['data_egress' => 'approved_cloud', 'media_types' => ['audio/wav', 'audio/mpeg'], 'max_bytes' => 25000000, 'max_duration_ms' => 30000, 'speaker_diarization' => false, 'timing_granularity' => 'input_range_only'],
                    'local_whisper_cpp' => ['data_egress' => 'local_only', 'media_types' => ['audio/mpeg', 'audio/mp4', 'audio/ogg', 'audio/wav', 'audio/webm', 'audio/flac']],
                    // Registered metadata only. Runtime remains not_configured until a published route and private config exist.
                    'openai_compatible_audio' => ['data_egress' => 'approved_cloud', 'media_types' => ['audio/mpeg', 'audio/mp4', 'audio/ogg', 'audio/wav', 'audio/webm', 'audio/flac']],
                ],
            ],
            'audio_speech' => [
                'route_kind' => 'audio_driver',
                'description' => 'Private speech synthesis driver',
                'display_name' => '语音合成',
                'capability_version' => 'audio_speech@1.0.0',
                'availability' => 'configurable',
                'model_types' => ['audio_speech'],
                'pricing_meters' => ['input_characters'],
                'drivers' => [
                    'openai_compatible_speech' => [
                        'data_egress' => 'approved_cloud',
                        'media_types' => ['audio/mpeg', 'audio/wav'],
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
