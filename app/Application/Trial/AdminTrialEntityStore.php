<?php

declare(strict_types=1);

namespace app\Application\Trial;

/**
 * Persistence boundary for the administrator trial channel. The production
 * implementation consumes SandIAM's own models and services; tests substitute
 * an in-memory double. All failures surface as ApiProblem so the controller
 * can render Chinese guidance without leaking internals.
 */
interface AdminTrialEntityStore
{
    /**
     * Lazily ensure the dedicated trial chain exists on the same canonical
     * defaults as the access-matrix chain: default organization, the `lvxu`
     * (律序) application, the `prod` (正式) environment and the isolated
     * trial workload client `admin_trial` (audience `sand-ai`). Existing
     * operator-disabled entities are reported, never silently re-enabled.
     *
     * @return array{organization_id:int,application_id:int,environment_id:int,workload_client_id:int}
     */
    public function ensureTrialClient(): array;

    /**
     * Create the missing trial grants only; an existing inactive or revoked
     * grant is an operator decision and must fail the channel instead of
     * being revived.
     *
     * @param list<string> $actionCodes
     * @return array<string,int> action code => service_action_id granted
     */
    public function ensureGrants(int $workloadClientId, array $actionCodes): array;

    /**
     * Issue a fresh short-lived workload credential. The plaintext is
     * returned once and must stay inside this request; only `key_prefix`
     * may be recorded afterwards.
     *
     * @return array{id:int,key_prefix:string,credential:string}
     */
    public function issueCredential(
        int $workloadClientId,
        string $expireTime,
        string $requestId,
        string $actor,
        ?int $organizationId,
        ?int $applicationId,
    ): array;
}
