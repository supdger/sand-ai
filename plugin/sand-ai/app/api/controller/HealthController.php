<?php

declare(strict_types=1);

namespace plugin\SandAi\app\api\controller;

use support\Response;

/** Disclosure-minimal local package probe; business endpoints require SandIAM. */
final class HealthController
{
    public function index(): Response
    {
        return json([
            'code' => 200,
            'message' => 'success',
            'data' => ['app' => 'SandAI', 'delivery' => 'full_plugin', 'status' => 'ok'],
        ]);
    }
}
