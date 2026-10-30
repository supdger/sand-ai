<?php

declare(strict_types=1);

namespace plugin\SandAi\app\admin\controller;

use app\Api\Support\ApiProblem;
use app\Application\Channel\ChannelBindingGateway;
use app\Application\Channel\ChannelDeliveryGateway;
use app\Application\Channel\FeishuCredentialGateway;
use app\Application\Channel\FeishuCredentialRequestSecrets;
use app\Application\Channel\FeishuChannelStatusGateway;
use app\Application\Channel\WecomCredentialGateway;
use app\Application\Channel\WecomCredentialRequestSecrets;
use app\Application\Channel\WecomDeliveryGateway;
use app\Application\Channel\WecomChannelStatusGateway;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

final class ChannelBindingController extends SandAiAdminController
{
    #[Permission('SandAI 保存渠道绑定', 'sand_ai:channel:binding:manage')]
    public function save(Request $request): Response
    {
        return $this->guard(function () use ($request): array {
            $body = $this->body($request, ['environment_id','code','name','agent_definition_id','application_id','workload_client_id']);
            $environmentId = $this->positiveInt($body['environment_id'] ?? null);
            unset($body['environment_id']);
            return (new ChannelBindingGateway())->save($environmentId, $body, $this->key($request), (string) $this->authenticatedAdminId());
        });
    }

    #[Permission('SandAI 停用渠道绑定', 'sand_ai:channel:binding:manage')]
    public function disable(Request $request): Response
    {
        return $this->guard(function () use ($request): array {
            $body = $this->body($request, ['environment_id','channel_binding_id']);
            return (new ChannelBindingGateway())->disable($this->positiveInt($body['environment_id'] ?? null), $this->positiveInt($body['channel_binding_id'] ?? null), $this->key($request), (string) $this->authenticatedAdminId());
        });
    }

    #[Permission('SandAI 找回渠道绑定原请求', 'sand_ai:channel:binding:manage')]
    public function readOperationByRequestKey(Request $request): Response
    {
        return $this->guard(function () use ($request): array {
            $query = $request->get();
            if (!is_array($query) || $request->rawBody() !== '' || count($query) !== 5
                || !in_array($query['operation'] ?? null, ['save', 'disable'], true)
                || !is_string($query['request_key'] ?? null) || trim($query['request_key']) === ''
                || strlen($query['request_key']) > 128 || preg_match('/[\x00-\x1f\x7f]/', $query['request_key']) === 1
                || !is_string($query['input_sha256'] ?? null)) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Original channel query is invalid');
            }
            $operation = $query['operation'];
            $targetField = $operation === 'save' ? 'code' : 'channel_binding_id';
            if (array_diff(array_keys($query), ['environment_id', 'operation', 'request_key', 'input_sha256', $targetField]) !== []
                || ($operation === 'save' && !is_string($query['code'] ?? null))) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Original channel target is invalid');
            }
            $target = $operation === 'save' ? $query['code'] : $this->queryInt($query['channel_binding_id'] ?? null);
            $adminId = $this->authenticatedAdminId();
            $revalidateAdmin = function () use ($adminId): void {
                if ($this->authenticatedAdminId() !== $adminId || ($adminId !== 1
                    && !in_array('sand_ai:channel:binding:manage', \plugin\sandadmin\app\cache\UserAuthCache::getUserAuth($adminId), true))) {
                    throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Original channel operation not found');
                }
            };
            return (new ChannelBindingGateway())->readOperation($this->queryInt($query['environment_id'] ?? null),
                $operation, $query['request_key'], $query['input_sha256'], $target, (string) $adminId, $revalidateAdmin);
        })->header('Cache-Control', 'no-store');
    }

    #[Permission('SandAI 查看渠道绑定', 'sand_ai:channel:binding:read')]
    public function read(Request $request): Response
    {
        return $this->guard(function () use ($request): array {
            $id = filter_var($request->get('environment_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $code = $request->get('code');
            if ($id === false || !is_string($code)) throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Channel query is invalid');
            return (new ChannelBindingGateway())->read((int) $id, $code);
        });
    }

    #[Permission('SandAI 查看渠道绑定列表', 'sand_ai:channel:binding:read')]
    public function index(Request $request): Response
    {
        return $this->guard(function () use ($request): array {
            $environmentId = $this->queryInt($request->get('environment_id'));
            $page = $this->queryInt($request->get('page', 1));
            $limit = $this->queryInt($request->get('limit', 20));
            return (new ChannelBindingGateway())->list($environmentId, $page, $limit);
        });
    }

    #[Permission('SandAI 查看渠道回信状态', 'sand_ai:channel:binding:read')]
    public function deliveries(Request $request): Response
    {
        return $this->guard(function () use ($request): array {
            return (new ChannelDeliveryGateway())->list(
                $this->queryInt($request->get('environment_id')),
                $this->queryInt($request->get('channel_binding_id')),
                $this->queryInt($request->get('page', 1)),
                $this->queryInt($request->get('limit', 20)),
            );
        });
    }

    #[Permission('SandAI 查看企业微信回信状态', 'sand_ai:channel:binding:read')]
    public function wecomDeliveries(Request $request): Response
    {
        return $this->guard(function () use ($request): array {
            return (new WecomDeliveryGateway())->list(
                $this->queryInt($request->get('environment_id')),
                $this->queryInt($request->get('channel_binding_id')),
                $this->queryInt($request->get('page', 1)),
                $this->queryInt($request->get('limit', 20)),
            );
        });
    }

    #[Permission('SandAI 查看飞书渠道状态', 'sand_ai:channel:binding:read')]
    public function feishuStatus(Request $request): Response
    {
        return $this->guard(function () use ($request): array {
            return (new FeishuChannelStatusGateway())->read(
                $this->queryInt($request->get('environment_id')),
                $this->queryInt($request->get('channel_binding_id')),
            );
        });
    }

    #[Permission('SandAI 查看企业微信渠道状态', 'sand_ai:channel:binding:read')]
    public function wecomStatus(Request $request): Response
    {
        return $this->guard(function () use ($request): array {
            return (new WecomChannelStatusGateway())->read(
                $this->queryInt($request->get('environment_id')),
                $this->queryInt($request->get('channel_binding_id')),
            );
        });
    }

    #[Permission('SandAI 重试渠道回信', 'sand_ai:channel:binding:manage')]
    public function retryDelivery(Request $request): Response
    {
        return $this->guard(function () use ($request): array {
            $body = $this->body($request, ['environment_id','channel_binding_id','task_id']);
            return (new ChannelDeliveryGateway())->retry(
                $this->positiveInt($body['environment_id'] ?? null),
                $this->positiveInt($body['channel_binding_id'] ?? null),
                $this->positiveInt($body['task_id'] ?? null),
                $this->key($request),
                (string) $this->authenticatedAdminId(),
            );
        });
    }

    #[Permission('SandAI 重试企业微信回信', 'sand_ai:channel:binding:manage')]
    public function retryWecomDelivery(Request $request): Response
    {
        return $this->guard(function () use ($request): array {
            $body = $this->body($request, ['environment_id','channel_binding_id','task_id']);
            return (new WecomDeliveryGateway())->retry(
                $this->positiveInt($body['environment_id'] ?? null),
                $this->positiveInt($body['channel_binding_id'] ?? null),
                $this->positiveInt($body['task_id'] ?? null),
                $this->key($request),
                (string) $this->authenticatedAdminId(),
            );
        });
    }

    #[Permission('SandAI 找回渠道回信重试', 'sand_ai:channel:binding:manage')]
    public function readDeliveryRetryByRequestKey(Request $request): Response
    {
        return $this->readRetryRequest($request, false);
    }

    #[Permission('SandAI 找回企业微信回信重试', 'sand_ai:channel:binding:manage')]
    public function readWecomDeliveryRetryByRequestKey(Request $request): Response
    {
        return $this->readRetryRequest($request, true);
    }

    private function readRetryRequest(Request $request, bool $wecom): Response
    {
        try {
            $query = $request->get();
            if (!is_array($query) || array_diff(array_keys($query), ['environment_id', 'channel_binding_id', 'task_id', 'request_key']) !== []
                || count($query) !== 4 || $request->rawBody() !== '') {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Delivery retry lookup query is invalid');
            }
            $ids = [];
            foreach (['environment_id', 'channel_binding_id', 'task_id'] as $field) {
                if (!is_string($query[$field] ?? null) && !is_int($query[$field] ?? null)) {
                    throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Delivery retry lookup id is invalid');
                }
                $ids[$field] = $this->queryInt($query[$field]);
            }
            $key = $query['request_key'];
            if (!is_string($key) || trim($key) === '' || strlen($key) > 128) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Delivery retry request key is invalid');
            }
            $adminId = $this->authenticatedAdminId();
            $revalidateAdmin = function () use ($adminId): void {
                if ($this->authenticatedAdminId() !== $adminId || ($adminId !== 1
                    && !in_array('sand_ai:channel:binding:manage', \plugin\sandadmin\app\cache\UserAuthCache::getUserAuth($adminId), true))) {
                    throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Delivery retry request not found');
                }
            };
            $revalidateAdmin();
            $result = $wecom
                ? (new WecomDeliveryGateway())->readRetryByRequestKey($ids['environment_id'], $ids['channel_binding_id'],
                    $ids['task_id'], trim($key), (string) $adminId, $revalidateAdmin)
                : (new ChannelDeliveryGateway())->readRetryByRequestKey($ids['environment_id'], $ids['channel_binding_id'],
                    $ids['task_id'], trim($key), (string) $adminId, $revalidateAdmin);
            $revalidateAdmin();
            return $this->success($result, '已找回原回信重试结果')->header('Cache-Control', 'no-store');
        } catch (ApiProblem $problem) {
            throw new ApiException($problem->errorCode . ': ' . $problem->getMessage());
        }
    }

    #[Permission('SandAI 保存飞书渠道凭据', 'sand_ai:channel:binding:manage')]
    public function saveFeishuCredentials(Request $request): Response
    {
        return $this->guard(function () use ($request): array {
            $body = FeishuCredentialRequestSecrets::take($request);
            if (!is_array($body) || array_diff(array_keys($body), [
                'environment_id','channel_binding_id','encrypt_key','verification_token',
                'workload_credential','app_id','app_secret',
            ]) !== []) throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Channel request is invalid');
            $environmentId = $this->positiveInt($body['environment_id'] ?? null);
            $bindingId = $this->positiveInt($body['channel_binding_id'] ?? null);
            unset($body['environment_id'], $body['channel_binding_id']);
            $adminId = $this->authenticatedAdminId();
            return (new FeishuCredentialGateway())->save($environmentId, $bindingId, $body,
                $this->key($request), (string) $adminId, $this->credentialAdminGuard($adminId));
        });
    }

    #[Permission('SandAI 保存企业微信渠道凭据', 'sand_ai:channel:binding:manage')]
    public function saveWecomCredentials(Request $request): Response
    {
        return $this->guard(function () use ($request): array {
            $body = WecomCredentialRequestSecrets::take($request);
            if (!is_array($body) || array_diff(array_keys($body), [
                'environment_id','channel_binding_id','token','encoding_aes_key',
                'corp_id','agent_id','corp_secret','workload_credential',
            ]) !== []) throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Channel request is invalid');
            $environmentId = $this->positiveInt($body['environment_id'] ?? null);
            $bindingId = $this->positiveInt($body['channel_binding_id'] ?? null);
            unset($body['environment_id'], $body['channel_binding_id']);
            $adminId = $this->authenticatedAdminId();
            return (new WecomCredentialGateway())->save($environmentId, $bindingId, $body,
                $this->key($request), (string) $adminId, $this->credentialAdminGuard($adminId));
        });
    }

    #[Permission('SandAI 找回飞书渠道凭据保存', 'sand_ai:channel:binding:manage')]
    public function readFeishuCredentialsByRequestKey(Request $request): Response
    {
        return $this->readCredentialRequest($request, false);
    }

    #[Permission('SandAI 找回企业微信渠道凭据保存', 'sand_ai:channel:binding:manage')]
    public function readWecomCredentialsByRequestKey(Request $request): Response
    {
        return $this->readCredentialRequest($request, true);
    }

    private function readCredentialRequest(Request $request, bool $wecom): Response
    {
        return $this->guard(function () use ($request, $wecom): array {
            $query = $request->get();
            if (!is_array($query) || $request->rawBody() !== '' || count($query) !== 5
                || array_diff(array_keys($query), ['environment_id', 'channel_binding_id', 'code', 'request_key', 'input_sha256']) !== []) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Original credential query is invalid');
            }
            foreach (['environment_id', 'channel_binding_id'] as $field) {
                if (!is_int($query[$field] ?? null) && !is_string($query[$field] ?? null)) {
                    throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Original credential target is invalid');
                }
            }
            foreach (['code', 'request_key', 'input_sha256'] as $field) {
                if (!is_string($query[$field] ?? null)) {
                    throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Original credential reference is invalid');
                }
            }
            $adminId = $this->authenticatedAdminId();
            $revalidateAdmin = $this->credentialAdminGuard($adminId);
            return $wecom
                ? (new WecomCredentialGateway())->readOperation($this->queryInt($query['environment_id']),
                    $this->queryInt($query['channel_binding_id']), $query['code'], $query['request_key'],
                    $query['input_sha256'], (string) $adminId, $revalidateAdmin)
                : (new FeishuCredentialGateway())->readOperation($this->queryInt($query['environment_id']),
                    $this->queryInt($query['channel_binding_id']), $query['code'], $query['request_key'],
                    $query['input_sha256'], (string) $adminId, $revalidateAdmin);
        }, '已查询原渠道凭据保存结果')->header('Cache-Control', 'private, no-store');
    }

    private function credentialAdminGuard(int $adminId): \Closure
    {
        return function () use ($adminId): void {
            if ($this->authenticatedAdminId() !== $adminId || ($adminId !== 1
                && !in_array('sand_ai:channel:binding:manage', \plugin\sandadmin\app\cache\UserAuthCache::getUserAuth($adminId), true))) {
                throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Original credential operation is unavailable');
            }
        };
    }

    /** @param callable():array<string,mixed> $callback */
    private function guard(callable $callback, string $message = '渠道绑定已更新'): Response { try { return $this->success($callback(), $message); } catch (ApiProblem $problem) { throw new ApiException($problem->errorCode . ': ' . $problem->getMessage()); } }
    /** @param list<string> $keys @return array<string,mixed> */
    private function body(Request $request, array $keys): array { $body = $request->post(); if (!is_array($body) || array_diff(array_keys($body), $keys) !== []) throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Channel request is invalid'); return $body; }
    private function positiveInt(mixed $value): int { if (!is_int($value) || $value <= 0) throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Channel request is invalid'); return $value; }
    private function queryInt(mixed $value): int { $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]); if ($parsed === false) throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Pagination is invalid'); return (int) $parsed; }
    private function key(Request $request): string { $key = $request->header('idempotency-key'); if (!is_string($key) || trim($key) === '' || strlen($key) > 128) throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Idempotency-Key is invalid'); return trim($key); }
}
