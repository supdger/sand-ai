<?php

declare(strict_types=1);

namespace plugin\SandAi\app\admin\controller;

use plugin\SandAi\app\support\HostFoundationCompatibility;
use support\Request;
use support\Response;

/** Remains loadable when the host Core is missing or too old for business APIs. */
final class HostCompatibilityController
{
    public function unavailable(Request $request, string $path = ''): Response
    {
        $status = HostFoundationCompatibility::status();
        $requestId = trim((string) $request->header('x-request-id', ''));
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{7,95}$/D', $requestId) !== 1) {
            $requestId = 'sand-ai-host-' . bin2hex(random_bytes(12));
        }
        // These fallback routes were registered instead of the business routes.
        // A subsequent dependency change needs the normal host reload; it must
        // not begin a caller operation through this recovery-only controller.
        return json([
            'code' => 400,
            'message' => $status['ready']
                ? '宿主基础依赖现已满足要求；SandAI 业务入口尚未重新登记，请管理员按宿主维护流程重载。'
                : $status['message'],
            'data' => [
                'error_code' => $status['ready'] ? 'SAND_AI_HOST_FOUNDATION_RELOAD_REQUIRED'
                    : 'SAND_AI_HOST_FOUNDATION_INCOMPATIBLE',
                'retryable' => false,
            ],
            'request_id' => $requestId,
        ])->withStatus(400)->header('Cache-Control', 'no-store');
    }
}
