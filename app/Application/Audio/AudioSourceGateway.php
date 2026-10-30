<?php

declare(strict_types=1);

namespace app\Application\Audio;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\RequestIdempotencyKey;
use app\Application\Usage\UsageAttribution;
use app\Infrastructure\Persistence\Model\AiFile;
use app\Infrastructure\Storage\StorageDriverFactory;

/** Reads an authorized private recording for immediate review playback. */
final class AudioSourceGateway
{
    /** @return array{content_type:string,bytes:string} */
    public function read(int $environmentId, int $audioTaskId, UsageAttribution $identity): array
    {
        $source = (new MeetingSummarySource())->load($environmentId, $audioTaskId, $identity, null, 0, true);
        $file = AiFile::where('id', $source['file_id'])->where('environment_id', $environmentId)
            ->where('file_ref', $source['file_ref'])
            ->where('owner_scope', RequestIdempotencyKey::trustedScopeForAttribution($identity))
            ->where('state', 'uploaded')->where('status', 1)->whereNull('delete_time')->find();
        if ($file === null) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Private audio source not found');
        }
        try {
            $bytes = StorageDriverFactory::create((string) $file->storage_driver)->read((string) $file->object_key);
        } catch (\Throwable) {
            throw new ApiProblem('SAND_AI_STORAGE_UNAVAILABLE', 'Private audio source is unavailable', true);
        }
        $result = self::verifiedContent(
            $bytes, (string) $file->media_type, (int) $file->size_bytes, (string) $file->sha256,
        );
        // Recheck the private source after storage I/O, including deletion and
        // the frozen task-to-file binding.
        $current = (new MeetingSummarySource())->load($environmentId, $audioTaskId, $identity, null, 0, true);
        if ($current['file_id'] !== $source['file_id']
            || !hash_equals($current['file_ref'], $source['file_ref'])
            || !hash_equals($current['sha256'], $source['sha256'])) {
            throw new ApiProblem('SAND_AI_AUDIO_SUMMARY_SOURCE_INVALID', 'The private audio source changed');
        }
        return $result;
    }

    /** @return array{content_type:string,bytes:string} */
    public static function verifiedContent(string $bytes, string $mediaType, int $sizeBytes, string $sha256): array
    {
        if (!in_array($mediaType, ['audio/mpeg', 'audio/mp4', 'audio/ogg', 'audio/wav', 'audio/webm', 'audio/flac'], true)
            || $sizeBytes < 1 || strlen($bytes) !== $sizeBytes
            || !preg_match('/\A[a-f0-9]{64}\z/', $sha256)
            || !hash_equals($sha256, hash('sha256', $bytes))) {
            throw new ApiProblem('SAND_AI_AUDIO_SUMMARY_SOURCE_INVALID', 'The private audio source changed');
        }
        return ['content_type' => $mediaType, 'bytes' => $bytes];
    }
}
