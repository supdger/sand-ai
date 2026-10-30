<?php

declare(strict_types=1);

namespace app\Application\Audio\Chunk;

use app\Api\Support\ApiProblem;
use think\facade\Db;

/** Runtime opt-in requires the exact registered migrations and their enforcement triggers. */
final class AudioChunkRuntimeSchema
{
    public static function present(): bool
    {
        $rows = Db::connect('pgsql')->query("SELECT to_regclass('sand_ai_audio_chunk_preparation') IS NOT NULL AS installed");
        return in_array($rows[0]['installed'] ?? null, [true, 1, '1', 't'], true);
    }

    public static function assertReady(): void
    {
        if (!self::present()) self::unsupported();
        foreach (['202610020106_audio_chunk_manifest', '202610020107_audio_chunk_dispatch',
            '202610020108_audio_chunk_object_ownership'] as $version) {
            $path = base_path('plugin/sand-ai/migrations/main-app/' . $version . '.pgsql');
            $checksum = is_file($path) ? hash_file('sha256', $path) : false;
            $actual = Db::connect('pgsql')->table('sand_ai_schema_migration')->where('version', $version)->value('checksum');
            if (!is_string($checksum) || !is_string($actual) || !hash_equals($checksum, $actual)) self::unsupported();
        }
        $required = ['sand_ai_audio_manifest_coverage_v106', 'sand_ai_audio_chunk_coverage_v106', 'sand_ai_audio_manifest_immutable_v106',
            'sand_ai_audio_chunk_immutable_v106', 'sand_ai_audio_manifest_source_guard_v106',
            'sand_ai_external_audio_chunk_guard_v107', 'sand_ai_audio_chunk_store_immutable_v108',
            'sand_ai_audio_chunk_object_guard_v108', 'sand_ai_audio_chunk_preparation_guard_v108',
            'sand_ai_audio_chunk_durable_guard_v108'];
        $triggers = Db::connect('pgsql')->query("SELECT tgname FROM pg_trigger WHERE NOT tgisinternal
            AND tgenabled IN ('O','A') AND tgrelid IN (to_regclass('sand_ai_audio_chunk_manifest'),
            to_regclass('sand_ai_audio_chunk'),to_regclass('sand_ai_external_audio_dispatch'),
            to_regclass('sand_ai_audio_chunk_store'),to_regclass('sand_ai_audio_chunk_object'),
            to_regclass('sand_ai_audio_chunk_preparation'))");
        if (array_diff($required, array_column($triggers, 'tgname')) !== []) self::unsupported();
    }

    private static function unsupported(): never
    {
        throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'Long recording processing requires the registered audio chunk migrations');
    }
}
