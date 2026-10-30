<?php

declare(strict_types=1);
namespace app\Application\Composition;
use app\Api\Support\ApiProblem;
use app\Infrastructure\Billing\ProviderConfigCipher;

/** Uses the installed private cipher, with no new credential or plaintext body copy. */
final class CompositionInputVault
{
    public function seal(array $input, array $binding): string
    {
        $body = CompositionGraphPolicy::json($input);
        if (strlen($body) > 131072) throw new ApiProblem('SAND_AI_COMPOSITION_GRAPH_INVALID', '工作流输入过大');
        return CompositionGraphPolicy::json((new ProviderConfigCipher())->encrypt([
            'domain' => 'sand-ai/composition-input/v1', 'binding' => $binding, 'input' => $input,
        ]));
    }
    public function open(mixed $encrypted, array $binding): array
    {
        if ($encrypted === null) throw new ApiProblem('SAND_AI_CONTENT_PURGED', '本次私有输入已清理；原子运行、费用及来源仍沿原服务查询');
        $plain = (new ProviderConfigCipher())->decrypt(CompositionDefinitionGateway::decode($encrypted));
        if (($plain['domain'] ?? null) !== 'sand-ai/composition-input/v1' || ($plain['binding'] ?? null) !== $binding
            || !is_array($plain['input'] ?? null)) throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', '工作流私有输入绑定无效');
        return $plain['input'];
    }
}
