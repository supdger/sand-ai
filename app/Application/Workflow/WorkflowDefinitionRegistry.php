<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use app\Api\Support\ApiProblem;

final class WorkflowDefinitionRegistry
{
    /** @param array<string, array<string, mixed>>|null $definitions */
    public function __construct(private readonly ?array $definitions = null)
    {
    }

    /** @return array<string, mixed> */
    public function definition(string $code, string $version): array
    {
        $definition = ($this->definitions ?? config('sand_ai_workflow.definitions', []))[$code] ?? null;
        if (!is_array($definition) || ($definition['version'] ?? null) !== $version) {
            throw new ApiProblem('SAND_AI_WORKFLOW_UNSUPPORTED', 'The workflow code or version is not registered');
        }
        if (!array_key_exists('data_class', $definition)
            || ($definition['data_class'] !== null
                && (!is_string($definition['data_class'])
                    || preg_match('/^[a-z0-9][a-z0-9._-]{1,31}$/D', $definition['data_class']) !== 1))) {
            throw new ApiProblem('SAND_AI_WORKFLOW_UNSUPPORTED', 'The workflow data classification is not configured');
        }

        return $definition;
    }
}
