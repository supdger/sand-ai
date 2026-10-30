<?php

declare(strict_types=1);

namespace app\Identity\Infrastructure;

use plugin\SandIam\app\runtime\ServiceInvocationFactResolver;

final class HostSandIamKnowledgeFactResolver implements ServiceInvocationFactResolver
{
    /** @return array{organization_id:int,application_id:int,environment_id:int,workload_client_id:int,data_class:?string,resource_type:string,resource_ref:string} */
    public function resolve(string $serviceCode, string $actionCode, string $resourceKey): array
    {
        return (new KnowledgePublicationFacts())->resolve($serviceCode, $actionCode, $resourceKey);
    }
}
