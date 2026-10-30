<?php

declare(strict_types=1);

namespace plugin\SandAi\app\admin\controller;

use app\Api\Support\ApiProblem;
use app\Application\Onboarding\DeploymentDirectoryGateway;
use app\Application\Onboarding\DeploymentDraftVerificationService;
use app\Application\Onboarding\DeploymentDraftSaveService;
use app\Application\Onboarding\DeploymentDraftSaveRequestPolicy;
use app\Application\Onboarding\GuzzleOnboardingHttpTransport;
use app\Application\Onboarding\OnboardingHttpTransport;
use app\Application\Onboarding\OnboardingRequestSecrets;
use app\Application\Onboarding\OnboardingStatus;
use app\Application\Onboarding\InternalSecurityInitialization;
use app\Application\Onboarding\OnboardingPublicationService;
use app\Application\Onboarding\OnboardingTokenBoundPolicy;
use app\Application\Onboarding\OpenAiCompatibleOnboardingClient;
use app\Application\Onboarding\ProviderConnectionPolicy;
use app\Application\Onboarding\ProviderGuideCatalog;
use app\Application\Onboarding\RemoteModelIdentifier;
use app\Application\Onboarding\SolutionGuideCatalog;
use app\Application\Onboarding\SystemHostResolver;
use app\Application\Onboarding\VerifiedModelDraftService;
use app\Application\Audio\AudioTranscriptionCatalog;
use app\Application\Capability\CapabilityProfileGateway;
use app\Application\Capability\CapabilityProfilePolicy;
use app\Infrastructure\Persistence\Model\AiModel;
use app\Infrastructure\Persistence\Model\ConfigRevision;
use app\Infrastructure\Persistence\Model\ModelDeployment;
use app\Infrastructure\Persistence\Model\Provider;
use app\Identity\Infrastructure\HostSandIamServiceCatalog;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\service\Permission;
use support\Container;
use support\Request;
use support\Response;

/** SandAI-owned provider/model onboarding only; SandIAM owns application setup. */
final class SetupController extends SandAiAdminController
{
    #[Permission('SandAI 录音转写契约', 'sand_ai:setup:audio-transcription:read')]
    public function audioTranscriptionMetadata(): Response
    {
        return $this->capabilityResponse(static fn (): array => AudioTranscriptionCatalog::index());
    }

    #[Permission('SandAI 中文场景向导', 'sand_ai:onboarding:read')]
    public function solutionGuideIndex(): Response
    {
        try {
            return $this->success(SolutionGuideCatalog::index());
        } catch (ApiProblem $problem) {
            throw new ApiException($problem->errorCode . ': ' . $problem->getMessage());
        }
    }

    #[Permission('SandAI 能力配置元数据', 'sand_ai:setup:capability:metadata')]
    public function capabilityMetadata(): Response
    {
        return $this->capabilityResponse(
            static fn (): array => (new CapabilityProfileGateway())->metadata(),
        );
    }

    #[Permission('SandAI 能力配置读取', 'sand_ai:setup:capability:read')]
    public function capabilityProfileRead(Request $request): Response
    {
        return $this->capabilityResponse(function () use ($request): array {
            $input = $this->capabilityQuery($request, ['environment_id']);

            return ['profile' => (new CapabilityProfileGateway())->read($this->positiveInt($input['environment_id'] ?? null))];
        });
    }

    #[Permission('SandAI 能力配置草稿', 'sand_ai:setup:capability:save')]
    public function capabilityProfileSave(Request $request): Response
    {
        return $this->capabilityResponse(function () use ($request): array {
            $input = $this->capabilitySaveInput($request->post());

            return ['profile' => (new CapabilityProfileGateway())->save($input, $this->capabilityActor())];
        }, '能力配置草稿已保存');
    }

    #[Permission('SandAI 发布能力配置', 'sand_ai:setup:capability:publish')]
    public function capabilityProfilePublish(Request $request): Response
    {
        return $this->capabilityResponse(function () use ($request): array {
            $input = $this->capabilityPost($request, ['environment_id', 'id', 'expected_revision']);

            return ['profile' => (new CapabilityProfileGateway())->publish(
                $this->positiveInt($input['environment_id'] ?? null),
                $this->positiveInt($input['id'] ?? null),
                $this->capabilityActor(),
                $this->optionalExpectedRevision($input),
            )];
        }, '能力配置已发布');
    }

    #[Permission('SandAI 已发布模型部署目录', 'sand_ai:setup:deployment:read')]
    public function deploymentIndex(Request $request): Response
    {
        try {
            return $this->success((new DeploymentDirectoryGateway())->index($request->get()));
        } catch (ApiProblem $problem) {
            throw $this->onboardingProblemException($problem);
        }
    }

    #[Permission('SandAI 读取部署草稿', 'sand_ai:setup:deployment:read')]
    public function deploymentDraftReadByRequestKey(Request $request): Response
    {
        try {
            $query = $request->get();
            $key = $query['request_key'] ?? null;
            if (array_keys($query) !== ['request_key'] || $request->rawBody() !== ''
                || !is_string($key) || trim($key) === '' || strlen($key) > 128) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Draft lookup requires only the original request_key and no body');
            }
            return $this->success((new DeploymentDraftSaveService())->readByRequestKey(
                $key, (string) $this->authenticatedAdminId(),
            ))->header('Cache-Control', 'no-store');
        } catch (ApiProblem $problem) {
            $exception = $this->onboardingProblemException($problem);
            return json(['code' => 400, 'message' => $exception->getMessage(), 'data' => null])
                ->header('Cache-Control', 'no-store');
        }
    }

    #[Permission('SandAI 读取部署草稿', 'sand_ai:setup:deployment:read')]
    public function deploymentDraftRead(Request $request): Response
    {
        try {
            $input = $this->deploymentDraftQuery($request);
            return $this->success((new DeploymentDraftSaveService())->read(
                $this->positiveInt($input['deployment_id'] ?? null),
                array_key_exists('revision', $input) ? $this->positiveInt($input['revision']) : null,
            ));
        } catch (ApiProblem $problem) {
            throw $this->onboardingProblemException($problem);
        }
    }

    #[Permission('SandAI 保存部署草稿', 'sand_ai:setup:deployment:save')]
    public function deploymentDraftSave(Request $request): Response
    {
        try {
            $input = $this->deploymentDraftSaveInput($request);
            $connectionMaterial = OnboardingRequestSecrets::consumeDeploymentConnection($request);
            // This pure preflight runs before save(), its lock/replay handling,
            // or any Provider/model/deployment write. Later failures lack this proof.
            (new DeploymentDraftSaveRequestPolicy())->normalize($input, $connectionMaterial !== null);
        } catch (ApiProblem $problem) {
            $exception = $this->onboardingProblemException($problem);
            if ($exception->getCode() !== 400) {
                throw $exception;
            }
            return json([
                'code' => 400,
                'message' => $exception->getMessage(),
                'data' => ['error_code' => $problem->errorCode, 'retryable' => false, 'submission_state' => 'not_submitted'],
            ]);
        }
        try {
            return $this->success((new DeploymentDraftSaveService())->save(
                $input,
                $connectionMaterial,
                (string) $request->header('Idempotency-Key', ''),
                $this->capabilityActor(),
            ), '部署草稿已保存');
        } catch (ApiProblem $problem) {
            throw $this->onboardingProblemException($problem);
        }
    }

    #[Permission('SandAI 读取部署验证状态', 'sand_ai:setup:deployment:read')]
    public function deploymentVerificationRead(Request $request): Response
    {
        try {
            $service = $this->deploymentVerificationService();
            $key = $request->header('Idempotency-Key');
            if ($key !== null) {
                return $this->success($service->readByRequestKey(
                    $this->positiveInt($request->get('deployment_id')),
                    $this->positiveInt($request->get('revision')),
                    (string) $key,
                    (string) $this->authenticatedAdminId(),
                ));
            }
            return $this->success($service->read(
                $this->positiveInt($request->get('deployment_id')),
                $this->positiveInt($request->get('revision')),
            ));
        } catch (ApiProblem $problem) {
            throw $this->onboardingProblemException($problem);
        }
    }

    #[Permission('SandAI 验证既有部署草稿', 'sand_ai:setup:deployment:verify')]
    public function deploymentVerify(Request $request): Response
    {
        $input = $request->post();
        if (!is_array($input) || array_diff(array_keys($input), ['deployment_id', 'revision', 'processing_location']) !== []) {
            throw new ApiException('SAND_AI_VALIDATION_ERROR: 验证请求只接受部署号、修订号和处理位置，请删除不支持的字段', 400);
        }
        try {
            return $this->success(($this->deploymentVerificationService())->verify(
                $this->positiveInt($input['deployment_id'] ?? null),
                $this->positiveInt($input['revision'] ?? null),
                is_string($input['processing_location'] ?? null) ? $input['processing_location'] : '',
                (string) $request->header('Idempotency-Key', ''),
                (string) $this->authenticatedAdminId(),
            ));
        } catch (ApiProblem $problem) {
            throw $this->onboardingProblemException($problem);
        }
    }

    #[Permission('SandAI 服务目录端口预检', 'sand_ai:setup:service-catalog:read')]
    public function serviceCatalogPreflight(): Response
    {
        try {
            return $this->success((new HostSandIamServiceCatalog())->preflight());
        } catch (ApiProblem $problem) {
            throw new ApiException($problem->errorCode . ': ' . $problem->getMessage(), 400);
        }
    }

    #[Permission('SandAI 显式登记服务动作', 'sand_ai:setup:service-catalog:register')]
    public function serviceCatalogRegister(Request $request): Response
    {
        $input = $request->post();
        if ($input !== []) {
            throw new ApiException('SAND_AI_VALIDATION_ERROR: service catalog registration accepts an empty body', 400);
        }
        try {
            return $this->success((new HostSandIamServiceCatalog())->register(), '服务目录登记完成');
        } catch (ApiProblem $problem) {
            throw new ApiException($problem->errorCode . ': ' . $problem->getMessage(), 400);
        }
    }

    #[Permission('SandAI Skill 服务动作端口预检', 'sand_ai:setup:service-catalog:read')]
    public function skillServiceCatalogPreflight(): Response
    {
        try {
            return $this->success((new HostSandIamServiceCatalog())->preflightSkill());
        } catch (ApiProblem $problem) {
            throw new ApiException($problem->errorCode . ': ' . $problem->getMessage(), 400);
        }
    }

    #[Permission('SandAI 显式登记 Skill 服务动作', 'sand_ai:setup:service-catalog:register')]
    public function skillServiceCatalogRegister(Request $request): Response
    {
        if ($request->post() !== []) {
            throw new ApiException('SAND_AI_VALIDATION_ERROR: Skill service catalog registration accepts an empty body', 400);
        }
        try {
            return $this->success((new HostSandIamServiceCatalog())->registerSkill(), 'Skill 服务动作登记完成');
        } catch (ApiProblem $problem) {
            throw new ApiException($problem->errorCode . ': ' . $problem->getMessage(), 400);
        }
    }

    #[Permission('SandAI 音频服务动作端口预检', 'sand_ai:setup:service-catalog:read')]
    public function audioServiceCatalogPreflight(): Response
    {
        try {
            return $this->success((new HostSandIamServiceCatalog())->preflightAudio());
        } catch (ApiProblem $problem) {
            throw new ApiException($problem->errorCode . ': ' . $problem->getMessage(), 400);
        }
    }

    #[Permission('SandAI 显式登记音频服务动作', 'sand_ai:setup:service-catalog:register')]
    public function audioServiceCatalogRegister(Request $request): Response
    {
        if ($request->post() !== []) {
            throw new ApiException('SAND_AI_VALIDATION_ERROR: audio service catalog registration accepts an empty body', 400);
        }
        try {
            return $this->success((new HostSandIamServiceCatalog())->registerAudio(), '音频服务动作登记完成');
        } catch (ApiProblem $problem) {
            throw new ApiException($problem->errorCode . ': ' . $problem->getMessage(), 400);
        }
    }

    #[Permission('SandAI 首次开通状态', 'sand_ai:onboarding:read')]
    public function onboardingStatus(Request $request): Response
    {
        try {
            $environmentId = $this->positiveInt($request->get('environment_id'));
        } catch (ApiProblem $problem) {
            throw $this->onboardingProblemException($problem);
        }
        $chains = [];
        $publishedDeploymentIds = [];
        foreach (ModelDeployment::whereIn('status', [1, 2])->select() as $deployment) {
            $provider = Provider::where('id', (int) $deployment->provider_id)->where('status', 1)->find();
            $model = AiModel::where('id', (int) $deployment->model_id)->where('status', 1)->find();
            $revision = ConfigRevision::where('resource_type', 'model_deployment')
                ->where('resource_id', (int) $deployment->id)
                ->where('revision', (int) $deployment->revision)
                ->where('status', 1)
                ->find();
            if ($provider === null || $model === null || $revision === null) {
                continue;
            }
            $chains[] = [
                'tested' => in_array((string) $revision->state, ['tested', 'published'], true),
                'published' => (string) $revision->state === 'published',
            ];
            if ((string) $revision->state === 'published' && (int) $deployment->status === 1) {
                $publishedDeploymentIds[] = (int) $deployment->id;
            }
        }

        try {
            $profile = (new CapabilityProfileGateway())->readPublished($environmentId);
        } catch (ApiProblem $problem) {
            throw $this->onboardingProblemException($problem);
        }
        $defaultChatBound = false;
        foreach (is_array($profile['routes'] ?? null) ? $profile['routes'] : [] as $route) {
            if (($profile['state'] ?? null) !== 'published' || (int) ($profile['status'] ?? 0) !== 1
                || ($route['capability_code'] ?? null) !== 'chat' || (int) ($route['status'] ?? 0) !== 1) {
                continue;
            }
            // Routes are returned in ascending priority. The first active chat
            // route is the environment default; a lower-priority fallback must
            // not make onboarding appear complete by itself.
            $defaultChatBound = ($route['route_kind'] ?? null) === 'model_deployment'
                && in_array((int) ($route['deployment_id'] ?? 0), $publishedDeploymentIds, true);
            break;
        }

        $status = OnboardingStatus::fromDeploymentChains(
            Provider::where('status', 1)->count(),
            AiModel::where('status', 1)->count(),
            $chains,
            $environmentId,
            $defaultChatBound,
        );
        $status['internal_security'] = InternalSecurityInitialization::status();
        if (!$status['internal_security']['ready']) {
            $status['stage']['next_step'] = '请先联系宿主操作员在已安装宿主的 server 目录检查 SandAI 内部安全材料；首次初始化后按正式生命周期重载服务，已有数据或损坏材料应恢复原备份。';
        }
        return $this->success($status);
    }

    #[Permission('SandAI 首次开通服务商指引', 'sand_ai:onboarding:read')]
    public function onboardingProviderGuides(): Response
    {
        return $this->success(['guides' => ProviderGuideCatalog::all($this->debugMode())]);
    }

    #[Permission('SandAI 发现服务商模型', 'sand_ai:onboarding:discover')]
    public function onboardingDiscoverModels(Request $request): Response
    {
        $connection = OnboardingRequestSecrets::take($request);
        if (!is_array($connection)) {
            throw new ApiException('SAND_AI_ONBOARDING_CONNECTION_INVALID: 请填写 HTTPS 服务地址和 API Key，连接信息须为对象', 400);
        }
        try {
            $input = $request->post();
            if (!is_array($input) || $input !== []) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', '模型发现仅接受服务商连接信息');
            }
            $models = $this->onboardingClient()->discover($connection);
        } catch (ApiProblem $problem) {
            throw $this->onboardingProblemException($problem);
        }

        return $this->success(['models' => $models, 'state' => 'discovered'], '模型发现完成，尚未保存或发布');
    }

    #[Permission('SandAI 验证服务商模型', 'sand_ai:onboarding:verify')]
    public function onboardingVerifyModel(Request $request): Response
    {
        $connection = OnboardingRequestSecrets::take($request);
        $input = $request->post();
        if (!is_array($connection)) {
            throw new ApiException('SAND_AI_ONBOARDING_CONNECTION_INVALID: 请填写 HTTPS 服务地址和 API Key，连接信息须为对象；随后选择或填写模型标识', 400);
        }
        try {
            if (!is_array($input) || array_diff(array_keys($input), ['remote_model', 'max_output_tokens', 'input_token_ceiling']) !== []) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', '模型验证请求包含不支持的字段');
            }
            // Validate the complete local request before DNS or a potentially
            // billable provider call. Normalize before trimming so control
            // characters cannot be silently removed from the model identifier.
            $remoteModel = RemoteModelIdentifier::normalize($input['remote_model'] ?? null);
            $maxOutputTokens = $this->requiredMaxOutputTokens($input);
            $inputTokenCeiling = $this->optionalInputTokenCeiling($input);
            $actorRef = $this->capabilityActor();
            $client = $this->onboardingClient();
            $normalized = (new ProviderConnectionPolicy(new SystemHostResolver()))->normalize($connection);
            $result = (new \app\Application\Onboarding\TemporaryModelVerificationService())->verify(
                $client, $normalized, $remoteModel, $maxOutputTokens, $inputTokenCeiling,
                trim((string) $request->header('Idempotency-Key', '')), (int) $actorRef, $this->requestId($request),
            );
        } catch (ApiProblem $problem) {
            throw $this->onboardingProblemException($problem);
        }

        return $this->success($result, '模型验证事实已记录；已完成的结果保留为未发布草稿');
    }

    #[Permission('SandAI 发布已测试模型', 'sand_ai:onboarding:publish')]
    public function onboardingPublishDeployment(Request $request): Response
    {
        try {
            $result = (new OnboardingPublicationService())->publishTestedDeployment(
                $this->positiveInt($request->post('deployment_id')),
                (string) $this->authenticatedAdminId(),
                $request->post('revision') === null ? null : $this->positiveInt($request->post('revision')),
            );
        } catch (ApiProblem $problem) {
            throw $this->onboardingProblemException($problem);
        }
        return $this->success($result, '模型配置已发布，下一步请选择业务环境');
    }

    #[Permission('SandAI 绑定默认聊天能力', 'sand_ai:onboarding:publish')]
    public function onboardingBindDefaultChat(Request $request): Response
    {
        try {
            $input = $request->post();
            $result = (new OnboardingPublicationService())->bindDefaultChatRoute(
                $this->positiveInt($request->post('environment_id')),
                $this->positiveInt($request->post('deployment_id')),
                (string) $this->authenticatedAdminId(),
                $this->optionalExpectedRevision($input),
            );
        } catch (ApiProblem $problem) {
            throw $this->onboardingProblemException($problem);
        }
        return $this->success($result, '默认聊天能力已绑定到目标环境，可以由应用调用');
    }

    /** Explicit input and idempotency refusals become 400; Provider and unknown failures retain their contract. */
    private function onboardingProblemException(ApiProblem $problem): ApiException
    {
        $inputCodes = [
            'SAND_AI_IDEMPOTENCY_CONFLICT',
            'SAND_AI_VALIDATION_ERROR',
            'SAND_AI_ONBOARDING_CONNECTION_INVALID',
            'SAND_AI_ONBOARDING_HEADER_INVALID',
            'SAND_AI_ONBOARDING_MODEL_INVALID',
        ];
        if (!in_array($problem->errorCode, $inputCodes, true)) {
            return new ApiException($problem->errorCode . ': ' . $problem->getMessage());
        }
        $message = $problem->getMessage();
        if ($problem->errorCode === 'SAND_AI_VALIDATION_ERROR') {
            $message = match ($message) {
                'max_output_tokens is required and must be an integer from 1 to 131072',
                'max_output_tokens must be an integer from 1 to 131072' => '请填写输出上限，必须是 1–131072 的整数',
                'input_token_ceiling must be an integer from 1 to 131072' => '输入额度必须是 1–131072 的整数；未指定时请省略该字段',
                'timeout_ms must be a JSON integer in range' => '模型请求超时必须是 1000–120000 毫秒的整数，请核对超时设置',
                'New model must include its model capability',
                'New model type or capabilities are invalid',
                'Model capabilities are invalid' => '模型能力必须包含所选模型类型，请核对聊天或其他能力选择',
                'An existing provider cannot be updated with connection' => '复用已有服务商时请省略连接信息；需要修改连接时请到服务商配置页操作',
                'A new provider requires a valid connection' => '新增服务商须填写有效的 HTTPS 服务地址和 API Key',
                'A positive identifier is required' => '请选择有效的环境或部署，并使用正整数编号',
                default => '输入不符合要求，请核对连接、模型配置、处理位置及请求字段后重试',
            };
        }
        return new ApiException($problem->errorCode . ': ' . $message, 400);
    }

    private function onboardingClient(): OpenAiCompatibleOnboardingClient
    {
        return new OpenAiCompatibleOnboardingClient(
            new ProviderConnectionPolicy(new SystemHostResolver()),
            Container::has(OnboardingHttpTransport::class)
                ? Container::get(OnboardingHttpTransport::class)
                : new GuzzleOnboardingHttpTransport(),
        );
    }

    private function deploymentVerificationService(): DeploymentDraftVerificationService
    {
        return Container::has(DeploymentDraftVerificationService::class)
            ? Container::get(DeploymentDraftVerificationService::class)
            : new DeploymentDraftVerificationService();
    }

    private function debugMode(): bool
    {
        return filter_var(env('APP_DEBUG', false), FILTER_VALIDATE_BOOL);
    }

    private function requestId(Request $request): ?string
    {
        $requestId = trim((string) $request->header('X-Request-Id', ''));
        if ($requestId === '' || strlen($requestId) > 128 || preg_match('/^[A-Za-z0-9._:-]+$/', $requestId) !== 1) {
            return null;
        }

        return $requestId;
    }

    /** @param list<string> $allowedFields @return array<string,mixed> */
    private function capabilityQuery(Request $request, array $allowedFields): array
    {
        $query = $request->get();
        if (!is_array($query) || array_diff(array_keys($query), $allowedFields) !== []) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Capability profile query contains unsupported fields');
        }

        return $query;
    }

    /** @param list<string> $allowedFields @return array<string,mixed> */
    private function capabilityPost(Request $request, array $allowedFields): array
    {
        $input = $request->post();
        if (!is_array($input) || array_diff(array_keys($input), $allowedFields) !== []) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Capability profile request contains unsupported fields');
        }

        return $input;
    }

    /** @param mixed $input @return array<string,mixed> */
    private function capabilitySaveInput(mixed $input): array
    {
        if (!is_array($input) || array_diff(array_keys($input), ['environment_id', 'expected_revision', 'name', 'template_code', 'data_egress_policy', 'routes']) !== []) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Capability profile request contains unsupported fields');
        }
        if (!array_key_exists('expected_revision', $input)
            || ($input['expected_revision'] !== null
                && (!is_int($input['expected_revision']) || $input['expected_revision'] < 1))) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'expected_revision must be null or a positive integer');
        }
        if (!is_string($input['name'] ?? null) || trim($input['name']) === '' || !array_key_exists('routes', $input) || !is_array($input['routes'])) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'environment_id, name and routes are required');
        }
        CapabilityProfilePolicy::assertRouteRequestShape($input['routes']);
        if (array_key_exists('template_code', $input)
            && $input['template_code'] !== null
            && !is_string($input['template_code'])) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'template_code must be a string or null');
        }
        if (array_key_exists('data_egress_policy', $input) && !is_string($input['data_egress_policy'])) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'data_egress_policy must be a string');
        }

        return [
            'environment_id' => $this->positiveInt($input['environment_id'] ?? null),
            'expected_revision' => $input['expected_revision'],
            'name' => trim($input['name']),
            'template_code' => $input['template_code'] ?? null,
            'data_egress_policy' => $input['data_egress_policy'] ?? null,
            'routes' => $input['routes'],
        ];
    }

    private function capabilityActor(): string
    {
        $actor = trim((string) $this->authenticatedAdminId());
        if ($actor === '') {
            throw new ApiProblem('SAND_AI_AUTHENTICATION_FAILED', 'An authenticated administrator is required');
        }

        return $actor;
    }

    /** @return array<string,mixed> */
    private function deploymentDraftQuery(Request $request): array
    {
        $query = $request->get();
        if (!is_array($query) || array_diff(array_keys($query), ['deployment_id', 'revision']) !== []) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Deployment draft query contains unsupported fields');
        }
        return $query;
    }

    /** @return array<string,mixed> */
    private function deploymentDraftSaveInput(Request $request): array
    {
        $input = $request->post();
        $allowed = ['provider_id', 'provider', 'model_id', 'model', 'deployment_id', 'remote_model', 'config', '_sand_ai_connection_submitted'];
        if (!is_array($input) || array_diff(array_keys($input), $allowed) !== []) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Deployment draft request contains unsupported fields');
        }
        $connectionSubmitted = $input['_sand_ai_connection_submitted'] ?? false;
        if (!is_bool($connectionSubmitted)) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Deployment connection state is invalid');
        }
        unset($input['_sand_ai_connection_submitted']);
        $input['connection_submitted'] = $connectionSubmitted;
        return $input;
    }

    /** @param callable(): array<string,mixed> $operation */
    private function capabilityResponse(callable $operation, string $message = 'success'): Response
    {
        try {
            return $this->success($operation(), $message);
        } catch (ApiProblem $problem) {
            throw new ApiException($problem->errorCode . ': ' . $problem->getMessage());
        }
    }

    /** @param array<string,mixed> $input */
    private function optionalExpectedRevision(array $input): ?int
    {
        if (!array_key_exists('expected_revision', $input)) {
            return null;
        }
        if (!is_int($input['expected_revision']) || $input['expected_revision'] < 1) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'expected_revision must be a positive integer');
        }
        return $input['expected_revision'];
    }

    private function positiveInt(mixed $value): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'A positive identifier is required');
        }
        return (int) $id;
    }

    /** @param array<string,mixed> $input */
    private function optionalInputTokenCeiling(array $input): ?int
    {
        return $this->optionalTokenBound($input, 'input_token_ceiling');
    }

    /** @param array<string,mixed> $input */
    private function requiredMaxOutputTokens(array $input): int
    {
        $bound = $this->optionalTokenBound($input, 'max_output_tokens');
        if ($bound === null) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'max_output_tokens is required and must be an integer from 1 to 131072');
        }
        return $bound;
    }

    /** @param array<string,mixed> $input */
    private function optionalTokenBound(array $input, string $field): ?int
    {
        return OnboardingTokenBoundPolicy::optional($input, $field);
    }
}
