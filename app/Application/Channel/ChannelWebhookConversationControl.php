<?php

declare(strict_types=1);

namespace app\Application\Channel;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\RequestIdempotencyKey;
use app\Application\Usage\UsageAttribution;
use app\Identity\Contract\IdentityContext;
use app\Identity\Contract\IdentityContextException;
use app\Identity\Infrastructure\HostSandIamIdentityContextProvider;
use app\Api\Support\IdentityContextProblem;

/** Receives only a platform scope constructed after callback signature verification. */
final class ChannelWebhookConversationControl
{
    /** @param array<string,mixed> $binding @return array{conversation_closed:bool,closed_after_task_id:int,idempotent_replay:bool} */
    public static function close(
        IdentityContext $identity,
        HostSandIamIdentityContextProvider $provider,
        string $signed,
        string $sourceIp,
        string $requestId,
        array $binding,
        string $key,
    ): array {
        if (!in_array($binding['delivery_platform'] ?? null, ['feishu', 'wecom'], true)) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Verified channel platform is unavailable');
        }
        $scope = RequestIdempotencyKey::trustedScope($identity);
        foreach (['sand_ai.agent.submit', 'sand_ai.task.read'] as $action) {
            try {
                $current = $provider->requireSignedContext(
                    $signed, $action, $sourceIp,
                    'channel-close-' . substr(hash('sha256', $requestId . ':' . $action), 0, 40),
                );
            } catch (IdentityContextException $exception) {
                throw IdentityContextProblem::from($exception);
            }
            if (!hash_equals($scope, RequestIdempotencyKey::trustedScope($current))) {
                throw new ApiProblem('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE', 'Channel close identity changed');
            }
        }
        (new ChannelBindingGateway())->assertCurrent($identity->environmentId, $binding);
        $closed = (new ChannelConversationGateway())->close(
            $identity->environmentId,
            UsageAttribution::fromIdentity($identity, 'agent', 'sand_ai.agent.submit'),
            $binding, $key,
        );
        return [
            'conversation_closed' => true,
            'closed_after_task_id' => $closed['closed_after_task_id'],
            'idempotent_replay' => $closed['idempotent_replay'],
        ];
    }
}
