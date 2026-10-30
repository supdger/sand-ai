<?php

declare(strict_types=1);

namespace app\Application\Gateway;

use app\Api\Support\ApiProblem;
use app\Application\Budget\BudgetCapacityBound;
use app\Infrastructure\Persistence\Model\CapabilityProfile;
use app\Infrastructure\Persistence\Model\CapabilityRoute;
use app\Infrastructure\Persistence\Model\ConfigRevision;
use app\Application\Capability\CapabilityProfileResolver;
use app\Application\Usage\PriceResolver;
use app\Infrastructure\Persistence\Model\AiModel;
use app\Infrastructure\Persistence\Model\BudgetWindow;
use app\Application\Budget\BudgetPolicyService;
use app\Application\Budget\BudgetDecimal;
use app\Application\Budget\BudgetReservationStateMachine;
use app\Application\Usage\UsageAttribution;
use app\Infrastructure\Persistence\Model\ModelDeployment;
use app\Infrastructure\Persistence\Model\Provider;
use app\Infrastructure\Provider\OpenAiCompatibleChatProvider;
use app\Infrastructure\Provider\OpenAiCompatibleExecutionConfigPolicy;
use app\Infrastructure\Provider\ProviderSecretDecryptor;

/** Explicit public-chat choice among published routes with comparable upper-bound prices. */
final class LowCostChatRouteResolver
{
    public const REQUEST_MODEL = '@lowest-cost';

    /** @param list<array{role:string,content:string}> $messages
     *  @return array{estimated_amount:string,currency:string,priority:int,route_id:int,model_code:string,deployment_id:int,price_revision_id:int,route_snapshot_sha256:string,request_body_sha256:string}
     */
    public function select(int $environmentId, array $messages, ?int $maxOutputTokens, UsageAttribution $attribution, array $inputProofs = [], bool $streamSegments = false): array
    {
        if ($maxOutputTokens === null) {
            throw new ApiProblem('SAND_AI_BUDGET_OUTPUT_BOUND_REQUIRED', 'Lowest-cost routing requires max_output_tokens');
        }
        $resolver = new CapabilityProfileResolver();
        $routes = $resolver->routes($environmentId, 'chat');
        if (!$resolver->hasPublishedProfile($environmentId)) {
            throw new ApiProblem('SAND_AI_CONFIGURATION_NOT_PUBLISHED', 'No published capability profile is available for this environment');
        }
        $now = gmdate('Y-m-d H:i:s');
        $prices = new PriceResolver();
        $candidates = [];
        foreach ($routes as $route) {
            if (($route['route_kind'] ?? null) !== 'model_deployment') continue;
            $deployment = ModelDeployment::where('id', (int) $route['deployment_id'])->where('status', 1)->find();
            $model = $deployment === null ? null : AiModel::where('id', (int) $deployment->model_id)->where('status', 1)->find();
            $provider = $deployment === null ? null : Provider::where('id', (int) $deployment->provider_id)->where('status', 1)->find();
            if ($deployment === null || $model === null || $provider === null || (string) $model->type !== 'chat'
                || !in_array((string) $provider->adapter, ['fake', 'openai_compatible'], true)) continue;
            if ((string) $provider->adapter === 'fake') {
                try {
                    FakeProviderPolicy::assertExecutable(
                        filter_var(env('APP_DEBUG', false), FILTER_VALIDATE_BOOL),
                        filter_var(env('SAND_AI_ALLOW_FAKE_PROVIDER', false), FILTER_VALIDATE_BOOL),
                    );
                } catch (ApiProblem) {
                    continue;
                }
            }
            $config = is_object($deployment->config) ? (array) $deployment->config : $deployment->config;
            if (!is_array($config) || !array_key_exists('budget_capacity_attestation', $config)) continue;
            $price = $prices->resolve((int) $provider->id, (int) $model->id, 'chat', $now);
            if ($price === null) continue;
            try {
                if (is_array($config) && array_key_exists('budget_capacity_attestation', $config)) {
                    $frozen = $this->candidateSnapshot($environmentId, $route, $deployment, $provider, $model);
                    $snapshot = $frozen['route_snapshot'];
                    $options = new ChatCompletionOptions($maxOutputTokens,
                        (string) $provider->adapter === 'openai_compatible'
                            ? DispatchRouteSnapshot::reasoningEffortFromRevision($snapshot, $frozen['deployment_revision']) : null);
                    if ((string) $provider->adapter === 'openai_compatible') {
                        $encrypted = is_object($provider->encrypted_config) ? (array) $provider->encrypted_config : $provider->encrypted_config;
                        if (!is_array($encrypted)) {
                            throw new ApiProblem('SAND_AI_PROVIDER_CONFIG_INVALID', 'Provider configuration is incomplete');
                        }
                        $runtime = (new OpenAiCompatibleExecutionConfigPolicy())->normalize(
                            (new ProviderSecretDecryptor())->decrypt($encrypted));
                        $body = (new OpenAiCompatibleChatProvider())->requestBody(
                            $runtime, (string) $deployment->remote_model, $messages, $options, $streamSegments);
                    } else {
                        $body = ProviderRequestBodyBuilder::chat((string) $deployment->remote_model, $messages, $options);
                    }
                    $capacity = BudgetCapacityBound::fromRoute($snapshot, $body, $environmentId);
                    $capacity->assertTarget($deployment, (int) $model->id, $environmentId, $snapshot, $body);
                    $estimate = $capacity->reservation($price, $maxOutputTokens);
                } else {
                    continue;
                }
            } catch (ApiProblem $problem) {
                if (!in_array($problem->errorCode, ['SAND_AI_BUDGET_INPUT_PROOF_REQUIRED', 'SAND_AI_BUDGET_INPUT_BOUND_EXCEEDED', 'SAND_AI_BUDGET_CAPACITY_UNVERIFIED', 'SAND_AI_CONFIGURATION_NOT_PUBLISHED',
                    'SAND_AI_SCOPE_SNAPSHOT_INVALID', 'SAND_AI_PROVIDER_CONFIG_INVALID'], true)) throw $problem;
                continue;
            }
            $policies = (new BudgetPolicyService())->applicable($attribution, (int) $model->id, 'chat', $now);
            $eligible = true;
            foreach ($policies as $policy) {
                if ((string) $policy->currency !== $price->currency || !in_array((string) $policy->enforcement, ['hard', 'soft'], true)
                    || (string) $policy->unpriced_policy !== 'block' || (string) $policy->unmeasured_policy !== 'hold') {
                    $eligible = false;
                    break;
                }
                $window = BudgetWindow::where('policy_id', (int) $policy->id)->find();
                if ($window === null) { $eligible = false; break; }
                $amount = BudgetDecimal::isPositive($estimate['estimated_amount']) ? $estimate['estimated_amount'] : '0.000001';
                try {
                    (new BudgetReservationStateMachine())->reserve([
                        'currency' => (string) $window->currency, 'limit_amount' => (string) $window->limit_amount,
                        'reserved_amount' => (string) $window->reserved_amount, 'settled_amount' => (string) $window->settled_amount,
                        'held_amount' => (string) $window->held_amount,
                    ], $amount, $price->currency, (string) $policy->enforcement === 'soft');
                } catch (ApiProblem $problem) {
                    if ($problem->errorCode !== 'SAND_AI_BUDGET_EXCEEDED') throw $problem;
                    $eligible = false;
                    break;
                }
            }
            if (!$eligible) continue;
            $candidates[] = [
                'estimated_amount' => $estimate['estimated_amount'], 'currency' => $price->currency,
                'priority' => (int) $route['priority'], 'route_id' => (int) $route['route_id'],
                'model_code' => (string) $model->code, 'deployment_id' => (int) $deployment->id,
                'price_revision_id' => $price->revisionId,
                'route_snapshot_sha256' => $snapshot['snapshot_sha256'],
                'request_body_sha256' => ProviderRequestBodyBuilder::digest($body),
            ];
        }
        return LowCostRoutePolicy::choose($candidates);
    }
    /** Same published route facts as dispatch; selection itself performs no reservation.
     * @return array{route_snapshot:array<string,mixed>,deployment_revision:ConfigRevision}
     */
    private function candidateSnapshot(int $environmentId, array $route, ModelDeployment $deployment, Provider $provider, AiModel $model): array
    {
        $profile = CapabilityProfile::where('id', (int) $route['profile_id'])->where('environment_id', $environmentId)
            ->where('revision', (int) $route['profile_revision'])->where('state', 'published')->where('status', 1)->find();
        $entry = $profile === null ? null : CapabilityRoute::where('id', (int) $route['route_id'])
            ->where('profile_id', (int) $profile->id)->where('deployment_id', (int) $deployment->id)
            ->where('capability_code', 'chat')->where('status', 1)->find();
        $profileRevision = $profile === null ? null : ConfigRevision::where('resource_type', 'capability_profile')
            ->where('resource_id', (int) $profile->id)->where('revision', (int) $profile->revision)
            ->where('state', 'published')->where('status', 1)->find();
        $deploymentRevision = ConfigRevision::where('resource_type', 'model_deployment')
            ->where('resource_id', (int) $deployment->id)->where('revision', (int) $deployment->revision)
            ->where('state', 'published')->where('status', 1)->find();
        if ($profile === null || $entry === null || $profileRevision === null || $deploymentRevision === null) {
            throw new ApiProblem('SAND_AI_CONFIGURATION_NOT_PUBLISHED', 'Lowest-cost capacity route is no longer published');
        }
        return [
            'route_snapshot' => DispatchRouteSnapshot::freeze($profile, $entry, $deployment, $model, $provider,
                $deploymentRevision, $profileRevision, selectionKind: 'lowest_cost'),
            'deployment_revision' => $deploymentRevision,
        ];
    }

}
