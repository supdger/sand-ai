<?php

declare(strict_types=1);

namespace app\Application\Tool;

use app\Api\Support\ApiProblem;
use app\Infrastructure\Persistence\Model\ToolConnectionRevision;

final class ToolConnectionConfigLoader
{
    public function __construct(private readonly ?ToolConnectionSecretCipher $cipher = null)
    {
    }

    /** @return array{origin:string,path:string,authorization:array{mode:string,credential:?string}} */
    public function load(ToolConnectionRevision $revision): array
    {
        $cipher = $this->cipher ?? new ToolConnectionSecretCipher();
        $keyId = is_string($revision->encryption_key_id) ? $revision->encryption_key_id : '';
        $config = $cipher->decrypt(is_array($revision->encrypted_config) ? $revision->encrypted_config : [], $keyId);
        if (array_keys($config) !== ['authorization', 'origin', 'path'] || !is_array($config['authorization'])
            || !is_string($config['origin']) || !is_string($config['path']) || !in_array($config['authorization']['mode'] ?? null, ['none', 'bearer'], true)) {
            throw new ApiProblem('SAND_AI_TOOL_CONFIGURATION_DRIFTED', 'Managed external tool connection is invalid');
        }
        $authorization = $config['authorization'];
        $authorizationKeys = array_keys($authorization);
        sort($authorizationKeys, SORT_STRING);
        if ($authorizationKeys !== ['credential', 'mode'] || !array_key_exists('credential', $authorization)
            || ($authorization['mode'] === 'bearer' && (!is_string($authorization['credential']) || trim($authorization['credential']) === '' || strlen($authorization['credential']) > 2048 || preg_match('/[\r\n]/', $authorization['credential']) === 1))
            || ($authorization['mode'] === 'none' && $authorization['credential'] !== null)) {
            throw new ApiProblem('SAND_AI_TOOL_CONFIGURATION_DRIFTED', 'Managed external tool authorization is invalid');
        }
        $fingerprint = $cipher->fingerprint($config, $keyId);
        if (!hash_equals((string) $revision->config_fingerprint, $fingerprint)) {
            throw new ApiProblem('SAND_AI_TOOL_CONFIGURATION_DRIFTED', 'Managed external tool connection changed after publication');
        }
        return $config;
    }
}
