<?php

declare(strict_types=1);

namespace app\Infrastructure\Persistence\Model;

final class MeetingExecutionRegistration extends SandAiModel
{
    protected $table = 'sand_ai_meeting_execution_registration';
    protected $json = ['route_snapshot', 'capacity'];
}
