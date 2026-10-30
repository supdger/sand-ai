<?php

declare(strict_types=1);

namespace app\Application\Audio\Speech;

use app\Application\Usage\UsageAttribution;
use think\facade\Db;

/** Recover measured private audio without re-resolving a route or re-sending to the provider. */
final class SpeechUsageRecovery
{
    private int $afterId = 0;

    /** @return array{completed:int,failed:int} */
    public function run(int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));
        $rows = Db::connect('pgsql')->query(
            "SELECT id,environment_id,identity_snapshot FROM sand_ai_speech_run
             WHERE state='awaiting_usage' AND id>? ORDER BY id LIMIT ?", [$this->afterId, $limit],
        );
        if ($rows === [] && $this->afterId > 0) {
            $this->afterId = 0;
            $rows = Db::connect('pgsql')->query(
                "SELECT id,environment_id,identity_snapshot FROM sand_ai_speech_run
                 WHERE state='awaiting_usage' ORDER BY id LIMIT ?", [$limit],
            );
        }
        $completed = 0;
        $failed = 0;
        foreach ($rows as $row) {
            $this->afterId = (int) $row['id'];
            try {
                $snapshot = is_array($row['identity_snapshot']) ? $row['identity_snapshot']
                    : json_decode((string) $row['identity_snapshot'], true);
                $attribution = UsageAttribution::fromCurrentPersisted(
                    $snapshot, (int) $row['environment_id'], 'capability', 'sand_ai.audio.speech',
                );
                if ($attribution->capabilityCode !== 'audio_speech') {
                    throw new \UnexpectedValueException('Speech recovery attribution is invalid');
                }
                (new SpeechUsageFinalizer())->finalize((int) $row['id'], $attribution);
                $completed++;
            } catch (\Throwable) {
                $failed++;
            }
        }
        return ['completed' => $completed, 'failed' => $failed];
    }
}
