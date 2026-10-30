<?php

declare(strict_types=1);

namespace app\Infrastructure\Persistence\Model;

final class MeetingSummaryNode extends SandAiModel
{
    protected $table = 'sand_ai_meeting_summary_node';

    protected $deleteTime = false;

    protected $json = ['input_snapshot', 'result_snapshot'];
}
