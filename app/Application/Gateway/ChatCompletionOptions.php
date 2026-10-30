<?php

declare(strict_types=1);

namespace app\Application\Gateway;

use app\Api\Support\ApiProblem;

/** Validated, provider-neutral completion controls. */
final readonly class ChatCompletionOptions
{
    public function __construct(public ?int $maxOutputTokens, public ?string $reasoningEffort = null, public ?NeuronOutputContract $skillOutput = null)
    {
        if ($reasoningEffort !== null && !in_array($reasoningEffort, ['low', 'high', 'max'], true)) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Unsupported reasoning effort');
        }
    }
}
