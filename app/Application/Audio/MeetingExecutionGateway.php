<?php

declare(strict_types=1);

namespace app\Application\Audio;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\DispatchRouteSnapshot;
use app\Application\Gateway\RequestPayloadFingerprint;
use app\Identity\Infrastructure\HostSandIamEnvironmentFacts;
use app\Infrastructure\Persistence\Model\AiModel;
use app\Infrastructure\Persistence\Model\CapabilityProfile;
use app\Infrastructure\Persistence\Model\ModelDeployment;
use think\facade\Db;

final class MeetingExecutionGateway
{
    /** @param array<string,mixed> $query @return array<string,mixed> */
    public function read(array $query): array
    {
        $query = MeetingExecutionPolicy::read($query);
        $owner = (new HostSandIamEnvironmentFacts())->resolve($query['environment_id']);
        $routes = [];
        foreach (AiModel::where('type', 'chat')->where('status', 1)->order('id')->select() as $model) {
            try {
                [, $public] = $this->route($owner['environment_id'], (string) $model->code);
                $routes[] = $public;
            } catch (ApiProblem $problem) {
                if (!in_array($problem->errorCode, ['SAND_AI_MODEL_NOT_AVAILABLE', 'SAND_AI_CONFIGURATION_NOT_PUBLISHED'], true)) throw $problem;
            }
        }
        $selection = null;
        if ($query['model'] !== null) {
            [$route, $public] = $this->route($owner['environment_id'], $query['model']);
            $selection = $this->selection($owner, $route, $public, $this->row($owner, $query['model']));
        }
        return ['owner' => $owner, 'routes' => $routes, 'selection' => $selection];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function save(array $input, int $actor, bool $state): array
    {
        $input = MeetingExecutionPolicy::mutation($input, $state);
        $owner = (new HostSandIamEnvironmentFacts())->resolve($input['environment_id']);
        if (!MeetingExecutionRuntime::installed()) $this->notReady();
        return Db::connect('pgsql')->transaction(function () use ($input, $owner, $actor, $state): array {
            // Serialize competing route/profile publication and first registration.
            $profile = CapabilityProfile::where('environment_id', $owner['environment_id'])
                ->where('state', 'published')->where('status', 1)->order('revision', 'desc')->lock(true)->find();
            if ($profile === null) $this->routeMissing();
            [$route, $public] = $this->route($owner['environment_id'], $input['model']);
            $deployment = ModelDeployment::where('id', $route['deployment_id'])->lock(true)->find();
            if ($deployment === null) $this->routeMissing();
            [$lockedRoute, $public] = $this->route($owner['environment_id'], $input['model']);
            if (!hash_equals($route['snapshot_sha256'], $lockedRoute['snapshot_sha256'])
                || !hash_equals($input['expected_route_sha256'], $route['snapshot_sha256'])
                || $input['expected_deployment_revision'] !== $route['deployment_revision']
                || $input['expected_profile_revision'] !== $route['profile_revision']) $this->conflict();
            $row = $this->row($owner, $input['model'], true);
            if (($row === null ? 0 : (int) $row['revision']) !== $input['expected_revision']) $this->conflict();
            if ($state) {
                if ($row === null) $this->conflict();
                $target = $input['enabled'] ? 'enabled' : 'revoked';
                // Revoke is allowed even after a route has changed, but its expected
                // fields refer to the current route rather than claiming the old one.
                if ($input['enabled']) {
                    if (!hash_equals($row['route_sha256'], $route['snapshot_sha256'])) $this->conflict();
                    $capacity = MeetingExecutionPolicy::capacity(self::object($row['capacity']), $route,
                        $public['max_output_tokens'], $public['input_token_ceiling']);
                    $this->assertPlanningReady($capacity, $route);
                    MeetingExecutionRuntime::assertSchemaReady();
                }
                if ($row['state'] !== $target) {
                    Db::connect('pgsql')->execute(
                        'UPDATE sand_ai_meeting_execution_registration SET state=?,revision=revision+1,updated_by=?,update_time=CURRENT_TIMESTAMP(0) WHERE id=? AND revision=?',
                        [$target, $actor, (int) $row['id'], $input['expected_revision']]);
                }
            } else {
                $local = array_key_exists('planning_driver', $input['capacity']);
                $driver = $local ? ($input['capacity']['planning_driver'] ?? null) : ($input['capacity']['tokenizer_driver'] ?? null);
                if (!in_array($driver, array_column($public[$local ? 'planning_drivers' : 'tokenizer_drivers'], 'code'), true)) {
                    throw new ApiProblem('SAND_AI_MEETING_TOKENIZER_UNSUPPORTED', '请选择当前路由可执行的分词驱动');
                }
                $capacity = MeetingExecutionPolicy::capacity($input['capacity'], $route,
                    $public['max_output_tokens'], $public['input_token_ceiling']);
                if ($local) MeetingLocalPlanning::assertSchemaReady();
                if ($row === null) {
                    Db::connect('pgsql')->execute(
                        'INSERT INTO sand_ai_meeting_execution_registration (organization_id,application_id,environment_id,model_code,revision,state,route_snapshot,route_sha256,capacity,created_by,updated_by) VALUES (?,?,?,?,1,\'disabled\',?::jsonb,?,?::jsonb,?,?)',
                        [$owner['organization_id'], $owner['application_id'], $owner['environment_id'], $input['model'],
                            self::encode($route), $route['snapshot_sha256'], self::encode($capacity), $actor, $actor]);
                } else {
                    Db::connect('pgsql')->execute(
                        'UPDATE sand_ai_meeting_execution_registration SET revision=revision+1,state=\'disabled\',route_snapshot=?::jsonb,route_sha256=?,capacity=?::jsonb,updated_by=?,update_time=CURRENT_TIMESTAMP(0) WHERE id=? AND revision=?',
                        [self::encode($route), $route['snapshot_sha256'], self::encode($capacity), $actor, (int) $row['id'], $input['expected_revision']]);
                }
            }
            return ['selection' => $this->selection($owner, $route, $public, $this->row($owner, $input['model']))];
        });
    }

    /** @param array<string,mixed> $route @return array<string,mixed>|null */
    public function enabledForRoute(array $route, bool $lock = false): ?array
    {
        if (!MeetingExecutionRuntime::installed()) return null;
        $profile = CapabilityProfile::where('id', $route['profile_id'])->where('revision', $route['profile_revision'])
            ->where('state', 'published')->where('status', 1)->find();
        if ($profile === null) $this->routeMissing();
        $owner = (new HostSandIamEnvironmentFacts())->resolve((int) $profile->environment_id);
        $row = $this->row($owner, $route['model_code'], $lock);
        if ($row === null || $row['state'] !== 'enabled'
            || !hash_equals($row['route_sha256'], $route['snapshot_sha256'])) {
            throw new ApiProblem('SAND_AI_MEETING_EXECUTION_DISABLED', '请在开始使用 SandAI 的会议执行设置中登记当前发布部署的容量，并明确启用');
        }
        $frozen = DispatchRouteSnapshot::assert(self::object($row['route_snapshot']), false);
        if (!hash_equals($route['snapshot_sha256'], $frozen['snapshot_sha256'])) $this->conflict();
        [$resolvedRoute, $public] = $this->route($owner['environment_id'], $route['model_code']);
        MeetingSummaryRoute::assertSame($route, $resolvedRoute);
        $capacity = MeetingExecutionPolicy::capacity(self::object($row['capacity']), $route,
            $public['max_output_tokens'], $public['input_token_ceiling']);
        $this->assertPlanningReady($capacity, $route);
        MeetingExecutionRuntime::assertSchemaReady();
        return ['row' => $row, 'owner' => $owner];
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>} */
    private function route(int $environmentId, string $model): array
    {
        // Ask the existing route resolver for the same route used by task submission.
        $deploymentConfigOutput = 1;
        $route = (new MeetingSummaryRoute())->freeze($environmentId, $model, $deploymentConfigOutput);
        $deployment = ModelDeployment::where('id', $route['deployment_id'])->where('status', 1)->find();
        $config = self::object($deployment?->config);
        if (!is_int($config['max_output_tokens'] ?? null) || $config['max_output_tokens'] < 1
            || !hash_equals($route['deployment_config_sha256'], RequestPayloadFingerprint::fingerprint($config, []))) $this->routeMissing();
        $ceiling = $config['input_token_ceiling'] ?? null;
        if ($ceiling !== null && (!is_int($ceiling) || $ceiling < 1)) $this->routeMissing();
        $record = AiModel::where('id', $route['model_id'])->find();
        $drivers = MeetingTokenizerDrivers::forRoute($route);
        if ($drivers !== []) {
            try { MeetingTokenizerDrivers::assertEndpoint($route); }
            catch (ApiProblem) { $drivers = []; }
        }
        return [$route, [
            'model' => $model, 'display_name' => (string) ($record?->name ?? $model),
            'deployment_id' => $route['deployment_id'], 'deployment_revision' => $route['deployment_revision'],
            'profile_id' => $route['profile_id'], 'profile_revision' => $route['profile_revision'],
            'route_sha256' => $route['snapshot_sha256'], 'max_output_tokens' => min(4096, $config['max_output_tokens']),
            'input_token_ceiling' => $ceiling, 'tokenizer_drivers' => $drivers,
            'planning_drivers' => [['code' => MeetingLocalPlanning::DRIVER] + MeetingLocalPlanning::metadata()],
        ]];
    }

    /** @param array<string,int> $owner @return array<string,mixed>|null */
    private function row(array $owner, string $model, bool $lock = false): ?array
    {
        if (!MeetingExecutionRuntime::installed()) return null;
        $rows = Db::connect('pgsql')->query(
            'SELECT * FROM sand_ai_meeting_execution_registration WHERE environment_id=? AND model_code=? AND status=1 AND delete_time IS NULL' . ($lock ? ' FOR UPDATE' : ''),
            [$owner['environment_id'], $model]);
        if ($rows === []) return null;
        if (count($rows) !== 1 || (int) $rows[0]['organization_id'] !== $owner['organization_id']
            || (int) $rows[0]['application_id'] !== $owner['application_id']) $this->conflict();
        return $rows[0];
    }

    /** @param array<string,int> $owner @param array<string,mixed> $route @param array<string,mixed> $public @param array<string,mixed>|null $row @return array<string,mixed> */
    private function selection(array $owner, array $route, array $public, ?array $row): array
    {
        $reasons = [];
        $ready = true;
        try { MeetingExecutionRuntime::assertSchemaReady(); }
        catch (ApiProblem $problem) { $ready = false; $reasons[] = $problem->errorCode; }
        $state = $row === null ? 'unconfigured' : $row['state'];
        $registration = null;
        if ($row !== null) {
            $stored = DispatchRouteSnapshot::assert(self::object($row['route_snapshot']), false);
            if (!hash_equals($stored['snapshot_sha256'], $row['route_sha256'])) $this->conflict();
            $registration = [
                'id' => (int) $row['id'], 'revision' => (int) $row['revision'], 'state' => $row['state'],
                'route_sha256' => $row['route_sha256'], 'deployment_revision' => $stored['deployment_revision'],
                'profile_revision' => $stored['profile_revision'], 'capacity' => self::object($row['capacity']),
            ];
            if (!hash_equals($row['route_sha256'], $route['snapshot_sha256'])) $state = 'stale';
            if (MeetingLocalPlanning::enabled(self::object($row['capacity']))) {
                try { MeetingLocalPlanning::assertSchemaReady(); }
                catch (ApiProblem $problem) { $ready = false; $reasons[] = $problem->errorCode; }
            }
        }
        if ($public['tokenizer_drivers'] === [] && $public['planning_drivers'] === []) {
            $state = 'unsupported'; $reasons[] = 'SAND_AI_MEETING_TOKENIZER_UNSUPPORTED';
        }
        $canEnable = $row !== null && $state !== 'stale' && $state !== 'unsupported' && $ready;
        if (!$ready && !in_array($state, ['stale', 'unsupported'], true)) $state = 'worker_unavailable';
        $next = match ($state) {
            'enabled' => '会议执行已启用；业务调用仍需当前 SandIAM 授权及已复核音频来源',
            'disabled', 'revoked' => '核对已保存容量与依据，确认后明确启用会议执行',
            'stale' => '发布路由已变化，请核对新部署并重新保存容量登记',
            'unsupported' => '当前模型没有已实现的分词驱动，请选择支持的已发布部署',
            'worker_unavailable' => '请通过正常安装或升级完成会议执行结构，再启用设置',
            default => '请提供官方能力依据，保存容量设置后再明确启用',
        };
        return ['route' => $public, 'registration' => $registration, 'state' => $state,
            'can_enable' => $canEnable, 'readiness' => ['ready' => $ready, 'reason_codes' => array_values(array_unique($reasons))],
            'next_action' => $next];
    }

    /** @return array<string,mixed> */
    public static function object(mixed $value): array
    {
        if (is_string($value)) $value = json_decode($value, true, 64, JSON_THROW_ON_ERROR);
        if (is_object($value)) $value = (array) $value;
        if (!is_array($value) || array_is_list($value)) throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', '会议执行设置对象无效');
        return $value;
    }

    /** @param array<string,mixed> $value */
    private static function encode(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** @param array<string,mixed> $capacity @param array<string,mixed> $route */
    private function assertPlanningReady(array $capacity, array $route): void
    {
        if (MeetingLocalPlanning::enabled($capacity)) {
            MeetingLocalPlanning::assertSchemaReady();
            return;
        }
        MeetingTokenizerDrivers::assertEndpoint($route);
    }

    private function conflict(): never
    {
        throw new ApiProblem('SAND_AI_MEETING_EXECUTION_CONFLICT', '发布路由或设置版本已变化，请重新读取后核对');
    }

    private function routeMissing(): never
    {
        throw new ApiProblem('SAND_AI_CONFIGURATION_NOT_PUBLISHED', '请先为当前环境发布并绑定聊天模型部署');
    }

    private function notReady(): never
    {
        throw new ApiProblem('SAND_AI_MEETING_WORKER_UNAVAILABLE', '会议设置尚未安装，请先完成正常升级');
    }
}
