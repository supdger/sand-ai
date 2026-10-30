<?php

declare(strict_types=1);

namespace app\Application\Audio;

use Closure;
use LogicException;
use NeuronAI\Workflow\WorkflowResources;

final class NeuronMeetingSummaryResources extends WorkflowResources
{
    /**
     * @param array<string,mixed> $nodeInput
     * @param array<string,array<string,mixed>> $sources
     * @param null|Closure(array):array $completion
     * @param array<string,mixed>|null $recoveredOutcome
     */
    public function __construct(
        public readonly array $nodeInput,
        public readonly array $sources,
        public readonly ?MeetingSummaryOutputContract $outputContract,
        public readonly int $nodeId,
        public readonly int $stepId,
        public readonly string $requestId,
        public readonly int $maxResultCharacters,
        public readonly ?Closure $completion,
        public readonly ?array $recoveredOutcome,
    ) {
        parent::__construct();
        if (($completion === null) === ($recoveredOutcome === null)) {
            throw new LogicException('A meeting graph requires one completion path or one recovered outcome.');
        }
    }
}
