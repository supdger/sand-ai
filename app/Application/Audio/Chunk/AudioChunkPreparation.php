<?php

declare(strict_types=1);

namespace app\Application\Audio\Chunk;

use app\Api\Support\ApiProblem;
use app\Application\Audio\ControlledAudioInput;
use app\Application\Audio\PostgresPrivateAudioFileLoader;
use app\Application\Audio\VerifiedPrivateAudioReference;
use app\Application\Task\TaskExecutionLease;
use app\Identity\Infrastructure\AudioTaskAccessGuard;
use app\Infrastructure\Persistence\Model\TaskRun;
use think\facade\Db;

/** Concrete private-file -> durable chunk manifest entrypoint. No Provider call or Worker wiring. */
final readonly class AudioChunkPreparation
{
    public function __construct(private LocalPrivateAudioChunkObjects $objects, private int $capacityBytes,
        private PostgresAudioChunkPreparationLedger $ledger = new PostgresAudioChunkPreparationLedger()) {}

    public function prepareCanonicalWav(int $environmentId, TaskExecutionLease $lease): int
    {
        $input = Db::connect('pgsql')->transaction(fn (): array => $this->ledger->guard($environmentId, $lease));
        $task = TaskRun::where('id', $lease->taskId)->where('environment_id', $environmentId)->find();
        if (!$task instanceof TaskRun) self::invalid();
        (new AudioTaskAccessGuard())->revalidateQueued($input['iam_authorization'], $task);
        $source = new VerifiedPrivateAudioReference($input['request']['file_ref'], $input['file_media_type'], $input['file_sha256'], $input['_trusted_identity_scope']);
        if ($source->mediaType !== 'audio/wav') self::invalid();
        return (new PostgresPrivateAudioFileLoader())->withInput($environmentId, $source,
            function (ControlledAudioInput $audio) use ($environmentId, $lease, $source): int {
                clearstatcache(true, $audio->temporaryPath);
                $bytes = filesize($audio->temporaryPath);
                if (!is_int($bytes) || $bytes < 46 || ($bytes - 44) % 2 !== 0) self::invalid();
                $plan = new AudioChunkPlan(intdiv($bytes - 44, 2));
                $chunker = new PrivatePcmAudioChunker();
                if (file_get_contents($audio->temporaryPath, false, null, 0, 44) !== $chunker->header($plan->sampleCount)) self::invalid();
                $prep = $this->ledger->begin($environmentId, $lease, $source, $plan, $source->contentSha256, $this->objects->storeCode, $this->objects->rootFingerprint(), $this->capacityBytes);
                if ($prep['state'] === 'sealed') return $this->existingManifest($environmentId, $lease, $source, $plan, $prep);
                $this->persist($environmentId, $lease, (int) $prep['id'], 0, $audio->temporaryPath, $source->contentSha256);
                $chunks = $chunker->split($audio->temporaryPath, $source->contentSha256, $plan,
                    fn (string $path, array $range, string $sha): string => $this->persist($environmentId, $lease, (int) $prep['id'], $range['sequence_no'], $path, $sha));
                $manifest = new AudioChunkManifest($source, $source->contentSha256, $plan, $chunks);
                return $this->ledger->seal($environmentId, $lease, $manifest);
            });
    }

    private function persist(int $environmentId, TaskExecutionLease $lease, int $preparationId, int $sequence, string $path, string $sha): string
    {
        $registered = $this->ledger->object($preparationId, $sequence);
        $ref = (string) $registered['chunk_ref'];
        return $this->objects->locked($ref, function () use ($environmentId, $lease, $sequence, $path, $sha, $ref): string {
            $this->ledger->assertStore($this->objects->storeCode, $this->objects->rootFingerprint());
            $object = $this->ledger->bind($environmentId, $lease, $sequence, $sha);
            if ($object['chunk_ref'] !== $ref) self::invalid();
            // A process can die mid-write. Under the stable lock, an allocated object's partial bytes
            // are safe to discard; ready content is immutable and is only verified/reused.
            if ($object['state'] === 'allocated') $this->objects->delete($ref);
            $this->objects->put($ref, $path, (int) $object['byte_size'], $sha);
            $this->ledger->assertStore($this->objects->storeCode, $this->objects->rootFingerprint());
            $this->ledger->ready($environmentId, $lease, (int) $object['id']);
            return $ref;
        });
    }

    private function existingManifest(int $environmentId, TaskExecutionLease $lease, VerifiedPrivateAudioReference $source, AudioChunkPlan $plan, array $prep): int
    {
        $chunks = [];
        for ($sequence = 1; $sequence <= $plan->count(); $sequence++) {
            $object = $this->ledger->object((int) $prep['id'], $sequence);
            $ref = (string) $object['chunk_ref'];
            $this->objects->locked($ref, function () use ($object, $ref): void {
                $this->ledger->assertStore($this->objects->storeCode, $this->objects->rootFingerprint());
                if ($object['state'] !== 'ready') self::invalid();
                $this->objects->withVerifiedPath($ref, (int) $object['byte_size'], (string) $object['content_sha256'], static fn (string $path): bool => true);
            });
            $chunks[] = $plan->chunk($sequence) + ['chunk_ref' => $ref, 'content_sha256' => (string) $object['content_sha256']];
        }
        return $this->ledger->seal($environmentId, $lease, new AudioChunkManifest($source, (string) $prep['normalized_sha256'], $plan, $chunks));
    }

    private static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_AUDIO_CHUNK_INPUT_UNSUPPORTED', 'This preparation path requires a canonical 16 kHz mono PCM WAV recording');
    }
}
