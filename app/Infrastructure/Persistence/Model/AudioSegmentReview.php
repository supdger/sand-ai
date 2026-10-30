<?php

declare(strict_types=1);

namespace app\Infrastructure\Persistence\Model;

use support\think\Model;

/** Append-only corrections; original provider evidence remains in TaskRun.result. */
final class AudioSegmentReview extends Model
{
    protected $connection = 'pgsql';
    protected $table = 'sand_ai_audio_segment_review';
    protected $pk = 'id';
    protected $createTime = 'create_time';
    protected $updateTime = false;
}
