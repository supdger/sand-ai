<?php

declare(strict_types=1);

namespace app\Application\Audio;

use app\Api\Support\ApiProblem;
use app\Application\File\FileOwnershipGuard;
use app\Infrastructure\Persistence\Model\AiFile;
use app\Infrastructure\Storage\StorageDriverFactory;

/** The only component that sees a storage object key; it never returns it. */
final class PostgresPrivateAudioFileLoader implements PrivateAudioFileLoader
{
    public function withInput(int $environmentId, VerifiedPrivateAudioReference $reference, callable $operation): mixed
    {
        if ($reference->trustedScope === null) throw new ApiProblem('SAND_AI_AUDIO_REFERENCE_FORBIDDEN', 'The private audio file is unavailable');
        $file = AiFile::where('environment_id', $environmentId)->where('file_ref', $reference->fileRef)
            ->where('owner_scope', $reference->trustedScope)->where('state', 'uploaded')->where('status', 1)->whereNull('delete_time')->find();
        if ($file === null || (string) $file->sha256 !== $reference->contentSha256 || (string) $file->media_type !== $reference->mediaType) throw new ApiProblem('SAND_AI_AUDIO_REFERENCE_FORBIDDEN', 'The private audio file is unavailable');
        try { (new FileOwnershipGuard())->assert($file, $reference->trustedScope); } catch (ApiProblem) {
            throw new ApiProblem('SAND_AI_AUDIO_REFERENCE_FORBIDDEN', 'The private audio file is unavailable');
        }
        $path = tempnam(sys_get_temp_dir(), 'sand-ai-audio-');
        if ($path === false) throw new ApiProblem('SAND_AI_AUDIO_UNAVAILABLE', 'A controlled audio input cannot be created', true);
        try {
            StorageDriverFactory::create((string) $file->storage_driver)->downloadFile((string) $file->object_key, $path);
            if (!hash_equals($reference->contentSha256, (string) hash_file('sha256', $path))) throw new ApiProblem('SAND_AI_AUDIO_REFERENCE_FORBIDDEN', 'The private audio content changed');
            return $operation(new ControlledAudioInput($path, $reference));
        } finally { @unlink($path); }
    }
}
