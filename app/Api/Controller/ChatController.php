<?php

declare(strict_types=1);

namespace app\Api\Controller;

use app\Api\Support\ApiProblem;
use app\Api\Support\ApiResponder;
use app\Application\Gateway\SandIamContextAuthenticator;
use app\Application\Gateway\ChatGateway;
use app\Application\Gateway\ChatCompletionOptionsPolicy;
use app\Application\Gateway\RequestIdempotencyKey;
use app\Application\Usage\UsageAttribution;
use app\Identity\Infrastructure\RequestAuditId;
use app\Identity\Infrastructure\ChatInvocationAccessGuard;
use support\Request;
use support\Response;

final class ChatController
{
    public function complete(Request $request): Response
    {
        $requestId = RequestAuditId::ensure($request);
        try {
            $context = (new SandIamContextAuthenticator())->authenticate($request, 'sand_ai.chat.complete');
            $idempotencyRequestId = RequestIdempotencyKey::required($request, 'chat', $context['context']);
            $payload = $request->post();
            $model = trim((string) ($payload['model'] ?? ''));
            if ($model === '' || !is_array($payload['messages'] ?? null) || $payload['messages'] === []) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'model and messages are required');
            }
            $messages = [];
            foreach ($payload['messages'] as $message) {
                if (!is_array($message) || !in_array($message['role'] ?? null, ['system', 'user', 'assistant'], true) || !is_string($message['content'] ?? null) || trim($message['content']) === '') {
                    throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Each message requires a supported role and non-empty content');
                }
                $messages[] = ['role' => $message['role'], 'content' => $message['content']];
            }
            $maxOutputTokens = ChatCompletionOptionsPolicy::normalize($payload['max_output_tokens'] ?? null)->maxOutputTokens;
            $accessGuard = new ChatInvocationAccessGuard();
            $decisions = [];
            $authorizeInvocation = function (int $invocationId) use ($accessGuard, &$decisions, $request, $context, $requestId): void {
                if (!isset($decisions[$invocationId])) {
                    $decisions[$invocationId] = $accessGuard->authorize($request, $context['context'], $invocationId, $requestId);
                    return;
                }
                $accessGuard->revalidate($request, $context['context'], $invocationId, $requestId, $decisions[$invocationId]);
            };
            return ApiResponder::success((new ChatGateway())->complete(
                $context['environment_id'],
                $model,
                $messages,
                $idempotencyRequestId,
                UsageAttribution::fromIdentity($context['context'], 'chat', 'sand_ai.chat.complete'),
                $maxOutputTokens,
                authorizePublicInvocation: $authorizeInvocation,
            ), $requestId);
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $requestId);
        }
    }
}
