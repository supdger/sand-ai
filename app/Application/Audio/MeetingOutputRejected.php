<?php

declare(strict_types=1);

namespace app\Application\Audio;

/** A validator rejection after a successful, retained Provider outcome. */
final class MeetingOutputRejected extends \RuntimeException
{
    public function __construct(
        public readonly int $nodeId,
        public readonly int $stepId,
        public readonly int $invocationId,
        public readonly string $contentSha256,
        public readonly ?string $missingSourcePath = null,
    ) {
        parent::__construct('The retained meeting output does not satisfy the required JSON contract');
    }
}
