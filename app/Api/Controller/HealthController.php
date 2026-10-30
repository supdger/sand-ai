<?php

declare(strict_types=1);

namespace app\Api\Controller;

use support\Response;

final class HealthController
{
    public function index(): Response
    {
        return json([
            'code' => 200,
            'message' => 'success',
            'data' => [
                'app' => 'SandAI',
                'service' => 'sand-ai',
                'status' => 'ok',
            ],
        ]);
    }
}
