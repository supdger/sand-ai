<?php

declare(strict_types=1);

namespace app\Api\Controller;

use app\Api\Support\ApiProblem;
use app\Api\Support\ApiResponder;
use app\Application\Channel\FeishuWebhookGateway;
use app\Identity\Infrastructure\RequestAuditId;
use support\Request;
use support\Response;

final class FeishuWebhookController
{
    public function receive(Request $request, string $code): Response
    {
        $requestId = RequestAuditId::ensure($request);
        try {
            $result = (new FeishuWebhookGateway())->receive($request, $code, $requestId);
            if (isset($result['challenge'])) return json(['challenge' => $result['challenge']]);
            return json(['code' => 0]);
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $requestId);
        }
    }
}
