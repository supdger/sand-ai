<?php

declare(strict_types=1);

namespace app\Application\Onboarding;

use app\Api\Support\ApiProblem;
use app\Application\Billing\ProviderBillingEligibilityPolicy;
use app\Infrastructure\Persistence\Model\AiModel;
use app\Infrastructure\Persistence\Model\AuditLog;
use app\Infrastructure\Persistence\Model\ConfigRevision;
use app\Infrastructure\Persistence\Model\ModelDeployment;
use app\Infrastructure\Persistence\Model\Provider;
use app\Infrastructure\Provider\ProviderSecretEncryptor;
use think\facade\Db;

/**
 * Persists a successful onboarding verification as a tested draft only.
 * It intentionally does not publish the deployment or bind it to an
 * environment/service grant; those are separate administrator decisions.
 */
final class VerifiedModelDraftService
{
    public function __construct(private readonly ProviderSecretEncryptor $encryptor = new ProviderSecretEncryptor())
    {
    }

    /**
     * @param array{endpoint: VerifiedEndpoint, api_key: string, extra_headers: array<string, string>} $connection
     * @return array{provider: array{code: string, name: string}, model: array{code: string, name: string}, deployment: array{id: int, revision: int, state: string}}
     */
    /**
     * @param array{usage_measured: bool, input_tokens: int|null, output_tokens: int|null, total_tokens: int|null} $validationUsage
     */
    public function create(array $connection, string $remoteModel, ?string $requestId, array $validationUsage, string $actorRef, int $maxOutputTokens, ?int $inputTokenCeiling = null, ?int $platformAttemptId = null): array
    {
        $inputTokens = $validationUsage['input_tokens'] ?? null;
        $outputTokens = $validationUsage['output_tokens'] ?? null;
        $totalTokens = $validationUsage['total_tokens'] ?? null;
        $measured = $validationUsage['usage_measured'] ?? null;
        if (!is_bool($measured)
            || ($measured && (!is_int($inputTokens) || !is_int($outputTokens) || !is_int($totalTokens)
                || $inputTokens < 0 || $outputTokens < 0
                || $inputTokens > PHP_INT_MAX - $outputTokens
                || $totalTokens !== $inputTokens + $outputTokens))
            || (!$measured && ($inputTokens !== null || $outputTokens !== null || $totalTokens !== null))) {
            throw new ApiProblem('SAND_AI_PROVIDER_RESPONSE_INVALID', 'Provider verification usage is invalid');
        }
        $actorRef = trim($actorRef);
        if ($actorRef === '') {
            throw new ApiProblem('SAND_AI_AUTHENTICATION_FAILED', 'An authenticated administrator is required');
        }
        $deploymentConfig = (new DeploymentDraftConfigurationPolicy())->normalize(array_filter(
            ['max_output_tokens' => $maxOutputTokens, 'input_token_ceiling' => $inputTokenCeiling],
            static fn (mixed $value): bool => $value !== null,
        ));
        (new DeploymentDraftConfigurationPolicy())->assertForModel($deploymentConfig, 'chat');
        $encryptedConfig = $this->encryptor->encrypt([
            'base_url' => $connection['endpoint']->baseUrl,
            'api_key' => $connection['api_key'],
            'extra_headers' => $connection['extra_headers'],
        ]);
        $providerCode = ((new ProviderBillingEligibilityPolicy())->isOfficialBaseUrl($connection['endpoint']->baseUrl)
            ? 'openai_'
            : 'onboarding_') . bin2hex(random_bytes(8));
        $modelCode = 'onboarding_chat_' . bin2hex(random_bytes(8));

        $usage = [
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'total_tokens' => $totalTokens,
            'usage_measured' => $measured,
            'cost_tracking_state' => $platformAttemptId === null ? 'untracked' : 'recorded',
            'validated_time' => date('Y-m-d H:i:s'),
        ];

        return Db::connect('pgsql')->transaction(function () use ($encryptedConfig, $providerCode, $modelCode, $remoteModel, $requestId, $usage, $actorRef, $deploymentConfig, $platformAttemptId): array {
            $provider = Provider::create([
                'code' => $providerCode,
                'name' => '已验证 AI 服务账号',
                'adapter' => 'openai_compatible',
                'encrypted_config' => $encryptedConfig,
                'timeout_ms' => OpenAiCompatibleOnboardingClient::DEFAULT_VERIFICATION_TIMEOUT_MS,
                'status' => 1,
            ]);
            $model = AiModel::create([
                'code' => $modelCode,
                'name' => RemoteModelIdentifier::displayName($remoteModel),
                'type' => 'chat',
                'capabilities' => ['chat'],
                'status' => 1,
            ]);
            $deployment = ModelDeployment::create([
                'model_id' => (int) $model->id,
                'provider_id' => (int) $provider->id,
                'remote_model' => $remoteModel,
                // This is an administrator-confirmed inference bound, not a
                // provider secret or an inferred model limit. Hard budgets may
                // only reserve a published deployment that froze this value
                // during its successful verification.
                'config' => $deploymentConfig,
                'revision' => 1,
                'status' => 2,
            ]);
            $execution = (new DeploymentExecutionSnapshot())->capture($provider, $model, $deployment);
            $configuration = ConfigRevision::create([
                'resource_type' => 'model_deployment',
                'resource_id' => (int) $deployment->id,
                'revision' => 1,
                'payload' => [
                    'remote_model' => $remoteModel,
                    'onboarding' => true,
                    'validation_usage' => $usage,
                    'execution_snapshot' => $execution['snapshot'],
                    'execution_snapshot_digest' => $execution['sha256'],
                ],
                'state' => 'tested',
                'status' => 1,
            ]);
            $draft = [
                'provider' => ['code' => (string) $provider->code, 'name' => (string) $provider->getAttr('name')],
                'model' => ['code' => (string) $model->code, 'name' => (string) $model->getAttr('name')],
                'deployment' => ['id' => (int) $deployment->id, 'revision' => 1, 'state' => 'tested'],
            ];
            if ($platformAttemptId !== null) {
                $meter = new \app\Application\Usage\AdminValidationUsage();
                $meter->bindDraft(Db::connect('pgsql'), $platformAttemptId, (int) $configuration->id, $draft);
                $payload = $configuration->payload;
                $payload['processing_policy'] = (new DeploymentProcessingPolicy())->create('public_cloud');
                $payload['deployment_validation'] = (new DeploymentValidationEvidence())->create((int) $deployment->id, 1, $actorRef,
                    ['usage_measured' => $usage['usage_measured'], 'input_tokens' => $usage['input_tokens'], 'output_tokens' => $usage['output_tokens']], $meter->projection($platformAttemptId));
                $configuration->save(['payload' => $payload]);
            }
            AuditLog::create([
                'actor_type' => 'admin',
                'actor_ref' => $actorRef,
                'action' => 'onboarding.model.verified',
                'resource_type' => 'model_deployment',
                'resource_id' => (int) $deployment->id,
                'summary' => 'Created tested SandAI onboarding draft',
                'context' => [
                    'outcome' => 'passed',
                    'request_id' => $requestId,
                    'revision' => 1,
                    'execution_snapshot_digest' => $execution['sha256'],
                    'validated_time' => $usage['validated_time'],
                ],
            ]);

            return $draft;
        });
    }
}
