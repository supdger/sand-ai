<?php

declare(strict_types=1);

namespace app\Api\Controller;

use app\Api\Support\ApiProblem;
use app\Api\Support\ApiResponder;
use app\Application\Channel\WecomWebhookGateway;
use app\Identity\Infrastructure\RequestAuditId;
use support\Request;
use support\Response;

final class WecomWebhookController
{
    public function verify(Request $request, string $code): Response
    {
        $requestId = RequestAuditId::ensure($request);
        try {
            $challenge = (new WecomWebhookGateway())->verify($request, $code);
            return new Response(200, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store'], $challenge);
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $requestId);
        }
    }

    public function receive(Request $request, string $code): Response
    {
        $requestId = RequestAuditId::ensure($request);
        try {
            (new WecomWebhookGateway())->receive($request, $code, $requestId);
            return new Response(200, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store'], 'success');
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $requestId);
        }
    }
}
