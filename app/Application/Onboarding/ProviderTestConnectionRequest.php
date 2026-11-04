<?php

declare(strict_types=1);

namespace app\Application\Onboarding;

use app\Api\Support\ApiProblem;

/**
 * Resolves the two mutually exclusive test-connection request forms after the
 * route guard has already moved `connection` into the request vault:
 * a fresh `{connection:{base_url,api_key}}` or a saved `{provider_id}`.
 * The redacted POST body never contains `connection` itself.
 */
final class ProviderTestConnectionRequest
{
    /**
     * @param array<string, mixed> $input redacted POST body
     * @param array<string, mixed>|null $vaultedConnection
     * @return array{connection: array<string, mixed>|null, provider_id: int|null}
     */
    public static function resolve(array $input, ?array $vaultedConnection): array
    {
        if ($vaultedConnection !== null) {
            if ($input !== []) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', '测试连接只能使用一种方式：填写接口地址和 API Key，或提供已保存服务商的编号');
            }

            return ['connection' => $vaultedConnection, 'provider_id' => null];
        }
        if (array_keys($input) !== ['provider_id']) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', '请填写接口地址和 API Key，或提供要测试的已保存服务商编号');
        }
        $id = filter_var($input['provider_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!is_int($id)) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', '请选择有效的服务商');
        }

        return ['connection' => null, 'provider_id' => $id];
    }
}
