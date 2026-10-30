<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use app\Api\Support\ApiProblem;

/** Versioned, server-owned labels for the public structured-service projection. */
final class WorkflowServiceCatalog
{
    /** @param array<string,mixed>|null $catalog */
    public function __construct(private readonly ?array $catalog = null)
    {
    }

    /** @return array{code:string,version:string,name:string} */
    public function service(string $code, string $version): array
    {
        $catalog = $this->catalog ?? config('sand_ai_workflow_services', []);
        if (!is_array($catalog)
            || array_keys($catalog) !== ['catalog_version', 'services']
            || !is_string($catalog['catalog_version']) || $catalog['catalog_version'] === ''
            || !is_array($catalog['services'])) {
            throw new ApiProblem('SAND_AI_WORKFLOW_SERVICE_CATALOG_INVALID', 'The structured workflow service catalog is invalid');
        }

        $versions = $catalog['services'][$code] ?? null;
        $entry = is_array($versions) ? ($versions[$version] ?? null) : null;
        if (!is_array($entry) || array_keys($entry) !== ['name'] || !is_string($entry['name']) || trim($entry['name']) === '') {
            throw new ApiProblem('SAND_AI_WORKFLOW_SERVICE_CATALOG_INVALID', 'The structured workflow service is not registered');
        }

        return ['code' => $code, 'version' => $version, 'name' => $entry['name']];
    }
}
