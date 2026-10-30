<?php

declare(strict_types=1);

namespace app\Identity\Infrastructure;

use app\Identity\Contract\IdentityContextException;
use plugin\SandIam\app\runtime\ServiceInvocationFactResolver;
use Throwable;

/** Resolves a current client from SandIAM before a resource row exists. */
final class HostSandIamWorkloadClientFactResolver implements ServiceInvocationFactResolver
{
    public function __construct(private readonly ?object $hostVerifier = null)
    {
    }

    /** @return array{organization_id:int,application_id:int,environment_id:int,workload_client_id:int,data_class:?string,resource_type:string,resource_ref:string} */
    public function resolve(string $serviceCode, string $actionCode, string $resourceKey): array
    {
        if ($serviceCode !== 'sand_ai' || !in_array($actionCode, [
            'sand_ai.file.write', 'sand_ai.model.read', 'sand_ai.retrieval.search',
            'sand_ai.skill.read', 'sand_ai.skill.manage', 'sand_ai.skill.test', 'sand_ai.skill.publish', 'sand_ai.skill.run',
        ], true)
            || preg_match('/^[1-9][0-9]{0,18}$/D', $resourceKey) !== 1) {
            self::unverified();
        }
        $clientId = filter_var($resourceKey, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($clientId === false || (string) $clientId !== $resourceKey) self::unverified();
        $host = $this->hostVerifier ?? HostSandIamRuntime::workloadClientReferenceVerifier();
        if ($host === null || !method_exists($host, 'verifyForService')) self::unverified();
        try {
            $reference = $host->verifyForService($clientId);
        } catch (Throwable) {
            self::unverified();
        }
        if (!is_array($reference) || ($reference['status'] ?? null) !== 1
            || ($reference['workload_client_id'] ?? null) !== $clientId) {
            self::unverified();
        }
        foreach (['organization_id', 'application_id', 'environment_id'] as $field) {
            if (!is_int($reference[$field] ?? null) || $reference[$field] < 1) self::unverified();
        }
        return [
            'organization_id' => $reference['organization_id'],
            'application_id' => $reference['application_id'],
            'environment_id' => $reference['environment_id'],
            'workload_client_id' => $clientId,
            'data_class' => null,
            'resource_type' => 'sand_iam.workload_client',
            'resource_ref' => $resourceKey,
        ];
    }

    private static function unverified(): never
    {
        throw new IdentityContextException('SAND_AI_INVOCATION_FACTS_UNVERIFIED');
    }
}
