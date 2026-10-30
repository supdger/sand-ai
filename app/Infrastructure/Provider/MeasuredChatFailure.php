<?php

declare(strict_types=1);

namespace app\Infrastructure\Provider;

use RuntimeException;
use app\Api\Support\ApiProblem;

/** A completed provider response with measured usage but no usable completion. */
final class MeasuredChatFailure extends RuntimeException
{
    public function __construct(
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly ?ApiProblem $problem = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($problem?->getMessage() ?? 'Provider returned no completion content',0,$previous);
    }
}
