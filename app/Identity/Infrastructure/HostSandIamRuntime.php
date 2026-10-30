<?php

declare(strict_types=1);

namespace app\Identity\Infrastructure;

/** Resolves SandIAM runtime ports without importing SandIAM models or tables. */
final class HostSandIamRuntime
{
    public static function identityContextProvider(): ?object
    {
        if (!class_exists(\plugin\SandIam\app\runtime\IdentityContextProvider::class)) {
            return null;
        }

        return new \plugin\SandIam\app\runtime\IdentityContextProvider();
    }

    public static function environmentReferenceVerifier(): ?object
    {
        return self::instance('plugin\\SandIam\\app\\runtime\\EnvironmentReferenceVerifier');
    }

    public static function workloadClientReferenceVerifier(): ?object
    {
        return self::instance('plugin\\SandIam\\app\\runtime\\WorkloadClientReferenceVerifier');
    }

    public static function serviceCatalog(): ?object
    {
        return self::instance('plugin\\SandIam\\app\\runtime\\ServiceCatalog');
    }

    public static function invocationAuthorizer(): ?object
    {
        if (!class_exists(\plugin\SandIam\app\runtime\ServiceInvocationAuthorizer::class)
            || !class_exists(\plugin\SandIam\app\runtime\ServiceInvocationFactResolverRegistry::class)
            || !interface_exists(\plugin\SandIam\app\runtime\ServiceInvocationFactResolver::class)) {
            return null;
        }

        $resolvers = new \plugin\SandIam\app\runtime\ServiceInvocationFactResolverRegistry([
            'sand_ai.workflow_run' => new HostSandIamWorkflowFactResolver(),
            'sand_ai.composition_cost' => new HostSandIamCompositionCostFactResolver(),
            'sand_ai.chat_invocation' => new HostSandIamChatFactResolver(),
            'sand_ai.file' => new HostSandIamFileFactResolver(),
            'sand_ai.file_metadata' => new HostSandIamFileMetadataFactResolver(),
            'sand_ai.skill_invocation' => new HostSandIamSkillFactResolver(),
            'sand_ai.skill' => new HostSandIamSkillManagementFactResolver(),
            'sand_ai.skill_knowledge' => new HostSandIamSkillKnowledgeFactResolver(),
            'sand_ai.skill_revoke' => new HostSandIamSkillRevokeFactResolver(),
            'sand_ai.skill_source_suggestion' => new HostSandIamSkillSourceSuggestionFactResolver(),
            'sand_ai.task_run' => new HostSandIamTaskFactResolver(),
            'sand_ai.task_operation' => new HostSandIamTaskOperationFactResolver(),
            'sand_ai.invocation' => new HostSandIamInvocationReadFactResolver(),
            'sand_ai.tool_run' => new HostSandIamToolFactResolver(),
            'sand_ai.knowledge_publication' => new HostSandIamKnowledgeFactResolver(),
            'sand_ai.speech_run' => new HostSandIamSpeechFactResolver(),
            'sand_iam.workload_client' => new HostSandIamWorkloadClientFactResolver(),
        ]);
        return new \plugin\SandIam\app\runtime\ServiceInvocationAuthorizer(
            new \plugin\SandIam\app\runtime\IdentityContextProvider(),
            new \plugin\SandIam\app\service\AuditWriter(),
            $resolvers,
        );
    }

    private static function instance(string $className): ?object
    {
        if (!class_exists($className)) {
            return null;
        }

        $instance = new $className();

        return is_object($instance) ? $instance : null;
    }
}
