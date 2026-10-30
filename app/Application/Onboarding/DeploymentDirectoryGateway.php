<?php

declare(strict_types=1);

namespace app\Application\Onboarding;

use app\Api\Support\IdentityContextProblem;
use app\Api\Support\ApiProblem;
use app\Application\File\LocalOcrDeploymentPolicy;
use app\Identity\Contract\EnvironmentReferenceVerifier;
use app\Identity\Contract\IdentityContextException;
use app\Identity\Infrastructure\HostSandIamEnvironmentReferenceVerifier;
use app\Infrastructure\Capability\CapabilityDriverRegistry;
use app\Infrastructure\Persistence\Model\CapabilityProfile;
use app\Infrastructure\Persistence\Model\CapabilityRoute;
use app\Infrastructure\Persistence\Model\ConfigRevision;
use app\Infrastructure\Persistence\Model\ModelDeployment;

/** Reads selectable, published model deployments without exposing configuration. */
final class DeploymentDirectoryGateway
{
    public function __construct(
        private readonly ?EnvironmentReferenceVerifier $environmentReferences = null,
        private readonly DeploymentDirectoryQueryPolicy $queryPolicy = new DeploymentDirectoryQueryPolicy(),
        private readonly DeploymentProcessingPolicy $processingPolicy = new DeploymentProcessingPolicy(),
        private readonly DeploymentValidationEvidence $validationEvidence = new DeploymentValidationEvidence(),
    ) {
    }

    /** @param array<string, mixed> $query @return array<string, mixed> */
    public function index(array $query): array
    {
        $filters = $this->queryPolicy->normalize($query);
        $environmentId = $filters['environment_id'];
        $routeCapabilities = [];
        $egressPolicy = null;
        $environmentSource = 'not_configured';
        if ($environmentId !== null) {
            $this->requireEnvironment($environmentId);
            [$routeCapabilities, $egressPolicy] = $this->publishedEnvironmentRoutes($environmentId, $filters['capability_code']);
            if ($filters['selection_scope'] === 'ocr_route_configuration' && $egressPolicy !== null) {
                $routeCapabilities = $this->ocrConfigurationCandidates();
            }
            $routeCapabilities = $this->retainTypeCompatibleRouteCapabilities(
                $routeCapabilities,
                $this->environmentDeploymentModelTypes($routeCapabilities),
                $filters['model_type'],
            );
            $routeCapabilities = $this->retainEgressCompatibleRouteCapabilities($routeCapabilities, $egressPolicy);
            $environmentSource = $filters['selection_scope'] === 'ocr_route_configuration'
                ? 'published_capability_profile_configuration'
                : 'published_capability_profile';
            if ($routeCapabilities === []) {
                return $this->emptyPage($filters['page'], $filters['limit']);
            }
        }

        $directory = ModelDeployment::alias('deployment')
            ->join('sand_ai_provider provider', 'provider.id = deployment.provider_id')
            ->join('sand_ai_model model', 'model.id = deployment.model_id')
            ->join('sand_ai_config_revision configuration', "configuration.resource_type = 'model_deployment' AND configuration.resource_id = deployment.id AND configuration.revision = deployment.revision")
            ->where('deployment.status', 1)->whereNull('deployment.delete_time')
            ->where('provider.status', 1)->whereNull('provider.delete_time')
            ->where('model.status', 1)->whereNull('model.delete_time')
            ->where('configuration.status', 1)->where('configuration.state', 'published')->whereNull('configuration.delete_time')
            ->field([
                'deployment.id', 'deployment.revision', 'deployment.remote_model',
                'model.name AS model_name', 'model.type AS model_type',
                'provider.name AS provider_name', 'provider.adapter AS provider_adapter', 'configuration.payload AS configuration_payload',
            ]);
        if ($filters['model_type'] !== null) {
            $directory->where('model.type', $filters['model_type']);
        }
        if ($environmentId !== null) {
            $directory->whereIn('deployment.id', array_keys($routeCapabilities));
        }
        if ($filters['name'] !== null) {
            $nameSearch = $this->nameSearch($filters['name']);
            $directory->whereRaw($nameSearch['sql'], $nameSearch['bindings']);
        }

        $result = $directory->order('deployment.id')->paginate(['page' => $filters['page'], 'list_rows' => $filters['limit']])->toArray();
        $data = [];
        foreach ($result['data'] as $row) {
            $capabilities = $environmentId === null
                ? []
                : $this->compatibleCapabilities($routeCapabilities[(int) $row['id']] ?? [], (string) $row['model_type']);
            $data[] = $this->summary($row, $capabilities, $egressPolicy, $environmentSource);
        }
        $result['data'] = $data;

        return $result;
    }

    /** @return array<int,list<string>> */
    private function ocrConfigurationCandidates(): array
    {
        $deployments = ModelDeployment::alias('deployment')
            ->join('sand_ai_provider provider', 'provider.id = deployment.provider_id')
            ->join('sand_ai_model model', 'model.id = deployment.model_id')
            ->join('sand_ai_config_revision configuration', "configuration.resource_type = 'model_deployment' AND configuration.resource_id = deployment.id AND configuration.revision = deployment.revision")
            ->where('deployment.status', 1)->whereNull('deployment.delete_time')
            ->where('provider.status', 1)->whereNull('provider.delete_time')
            ->where('model.status', 1)->whereNull('model.delete_time')
            ->where('configuration.status', 1)->where('configuration.state', 'published')->whereNull('configuration.delete_time')
            ->where('provider.adapter', 'local_ocr_runtime')->where('model.type', 'image_ocr')
            ->where('deployment.remote_model', 'local_paddle_ocr')
            ->field(['deployment.id'])->order('deployment.id')->select();
        $eligible = [];
        foreach ($deployments as $deployment) {
            $id = (int) $deployment->id;
            try {
                LocalOcrDeploymentPolicy::publishedTarget($id);
            } catch (ApiProblem $problem) {
                if (!in_array($problem->errorCode, [
                    'SAND_AI_CAPABILITY_UNSUPPORTED', 'SAND_AI_CONFIGURATION_DRIFTED',
                    'SAND_AI_CONFIGURATION_NOT_PUBLISHED',
                ], true)) {
                    throw $problem;
                }
                continue;
            }
            $eligible[$id] = ['image_ocr'];
        }

        return $eligible;
    }

    /** @return array{0:array<int,list<string>>,1:string|null} */
    private function publishedEnvironmentRoutes(int $environmentId, ?string $capabilityFilter): array
    {
        $profile = CapabilityProfile::where('environment_id', $environmentId)
            ->where('state', 'published')->where('status', 1)->whereNull('delete_time')
            ->order('revision', 'desc')->find();
        if ($profile === null || !ConfigRevision::where('resource_type', 'capability_profile')
            ->where('resource_id', (int) $profile->id)->where('revision', (int) $profile->revision)
            ->where('state', 'published')->where('status', 1)->whereNull('delete_time')->find()) {
            return [[], null];
        }

        $routes = CapabilityRoute::where('profile_id', (int) $profile->id)->where('status', 1)->whereNull('delete_time')
            ->whereNotNull('deployment_id')->order('priority')->select();
        $byDeployment = [];
        foreach ($routes as $route) {
            $capabilityCode = (string) $route->capability_code;
            try {
                $metadata = CapabilityDriverRegistry::capability($capabilityCode);
            } catch (\Throwable) {
                continue;
            }
            if ((!in_array($metadata['route_kind'] ?? null, ['model_deployment', 'audio_driver'], true) && $capabilityCode !== 'image_ocr')
                || ($capabilityFilter !== null && $capabilityCode !== $capabilityFilter)) {
                continue;
            }
            if (($metadata['route_kind'] ?? null) === 'audio_driver'
                && !isset($metadata['drivers'][(string) ($route->driver_code ?? '')])) {
                continue;
            }
            $deploymentId = (int) $route->deployment_id;
            if ($deploymentId > 0) {
                $byDeployment[$deploymentId][$capabilityCode] = true;
            }
        }

        return [array_map(static fn (array $codes): array => array_keys($codes), $byDeployment), (string) $profile->data_egress_policy];
    }

    /** @param array<int,list<string>> $routeCapabilities @return array<int,string> */
    private function environmentDeploymentModelTypes(array $routeCapabilities): array
    {
        if ($routeCapabilities === []) {
            return [];
        }
        $rows = ModelDeployment::alias('deployment')
            ->join('sand_ai_model model', 'model.id = deployment.model_id')
            ->whereIn('deployment.id', array_keys($routeCapabilities))
            ->where('deployment.status', 1)->whereNull('deployment.delete_time')
            ->where('model.status', 1)->whereNull('model.delete_time')
            ->field(['deployment.id', 'model.type AS model_type'])->select()->toArray();
        $types = [];
        foreach ($rows as $row) {
            $types[(int) $row['id']] = (string) $row['model_type'];
        }

        return $types;
    }

    /** @param array<int,list<string>> $routeCapabilities @param array<int,string> $modelTypes @return array<int,list<string>> */
    private function retainTypeCompatibleRouteCapabilities(array $routeCapabilities, array $modelTypes, ?string $requestedModelType): array
    {
        $eligible = [];
        foreach ($routeCapabilities as $deploymentId => $capabilityCodes) {
            $modelType = $modelTypes[$deploymentId] ?? null;
            if ($modelType === null || ($requestedModelType !== null && $modelType !== $requestedModelType)) {
                continue;
            }
            $capabilities = $this->compatibleCapabilities($capabilityCodes, $modelType);
            if ($capabilities !== []) {
                $eligible[$deploymentId] = $capabilities;
            }
        }

        return $eligible;
    }

    /** @param array<int,list<string>> $routeCapabilities @return array<int,list<string>> */
    private function retainEgressCompatibleRouteCapabilities(array $routeCapabilities, ?string $egressPolicy): array
    {
        if ($routeCapabilities === [] || $egressPolicy === null) {
            return [];
        }
        $rows = ConfigRevision::alias('configuration')
            ->join('sand_ai_model_deployment deployment', 'deployment.id = configuration.resource_id AND deployment.revision = configuration.revision')
            ->where('configuration.resource_type', 'model_deployment')->whereIn('configuration.resource_id', array_keys($routeCapabilities))
            ->where('configuration.state', 'published')->where('configuration.status', 1)->whereNull('configuration.delete_time')
            ->field(['configuration.resource_id', 'configuration.payload'])->select();
        $eligible = [];
        foreach ($rows as $row) {
            $payload = is_array($row->payload) ? $row->payload : [];
            $policy = $this->processingPolicy->fromPublishedPayload($payload);
            if ($policy['policy'] !== null && in_array($egressPolicy, $policy['compatible_data_egress_policies'], true)) {
                $eligible[(int) $row->resource_id] = $routeCapabilities[(int) $row->resource_id];
            }
        }
        return $eligible;
    }

    /** @param list<string> $capabilityCodes @return list<string> */
    private function compatibleCapabilities(array $capabilityCodes, string $modelType): array
    {
        $available = [];
        foreach ($capabilityCodes as $capabilityCode) {
            $metadata = CapabilityDriverRegistry::capability($capabilityCode);
            if ((in_array($metadata['route_kind'] ?? null, ['model_deployment', 'audio_driver'], true) || $capabilityCode === 'image_ocr')
                && in_array($modelType, is_array($metadata['model_types'] ?? null) ? $metadata['model_types'] : [], true)) {
                $available[$capabilityCode] = true;
            }
        }
        $result = array_keys($available);
        sort($result, SORT_STRING);

        return $result;
    }

    /** @param array<string,mixed> $row @param list<string> $capabilities @return array<string,mixed> */
    private function summary(array $row, array $capabilities, ?string $egressPolicy, string $environmentSource): array
    {
        $modelName = (string) $row['model_name'];
        $providerName = (string) $row['provider_name'];
        $remoteModel = (string) $row['remote_model'];

        $payload = $this->arrayPayload($row['configuration_payload'] ?? null);
        $processing = $this->processingPolicy->fromPublishedPayload($payload);
        return [
            'id' => (int) $row['id'],
            'display_name' => $modelName . ' / ' . $providerName . ' / ' . $remoteModel,
            'model_name' => $modelName,
            'model_type' => (string) $row['model_type'],
            'provider_name' => $providerName,
            'provider_adapter' => (string) $row['provider_adapter'],
            'remote_model' => $remoteModel,
            'publication_status' => 'published',
            'deployment_revision' => (int) $row['revision'],
            'processing_location' => $processing['processing_location'],
            'compatible_data_egress_policies' => $processing['compatible_data_egress_policies'],
            'egress_source' => $processing['egress_source'],
            'egress_policy' => $egressPolicy,
            'source' => ['deployment' => 'published_configuration', 'environment' => $environmentSource],
            'capabilities' => $capabilities,
            'validation_evidence' => $this->validationEvidence->fromPayload($payload, (int) $row['id'], (int) $row['revision']),
        ];
    }

    /** @return array{current_page:int,per_page:int,total:int,data:list<array<string,mixed>>} */
    private function emptyPage(int $page, int $limit): array
    {
        return ['current_page' => $page, 'per_page' => $limit, 'total' => 0, 'data' => []];
    }

    /** @return array<string,mixed> */
    private function arrayPayload(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value)) {
            return [];
        }
        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        return is_array($decoded) ? $decoded : [];
    }

    private function requireEnvironment(int $environmentId): void
    {
        try {
            ($this->environmentReferences ?? new HostSandIamEnvironmentReferenceVerifier())->requireEnvironment($environmentId);
        } catch (IdentityContextException $exception) {
            throw IdentityContextProblem::from($exception);
        }
    }

    /** @return array{sql:string,bindings:list<string>} */
    private function nameSearch(string $value): array
    {
        // PostgreSQL receives one literal `!` escape character. It avoids the
        // standard_conforming_strings ambiguity of a backslash SQL literal.
        $pattern = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value) . '%';
        $pattern = mb_strtolower($pattern);

        return [
            'sql' => "(LOWER(model.name) LIKE ? ESCAPE '!' OR LOWER(provider.name) LIKE ? ESCAPE '!' OR LOWER(deployment.remote_model) LIKE ? ESCAPE '!')",
            'bindings' => [$pattern, $pattern, $pattern],
        ];
    }
}
