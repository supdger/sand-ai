<?php

declare(strict_types=1);

namespace app\Application\Workflow;

/**
 * A completed model response failed the material output contract.
 *
 * Carry only the controlled error code. Raw output and exception messages
 * must not enter task rows, audits, or worker logs.
 */
final class MaterialAnalysisOutputRejected extends \RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct('Material analysis output failed validation');
    }
}
