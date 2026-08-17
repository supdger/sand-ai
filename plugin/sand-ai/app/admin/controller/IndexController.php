<?php

declare(strict_types=1);

namespace plugin\SandAi\app\admin\controller;

use plugin\sandadmin\basic\BaseController;
use plugin\sandadmin\service\Permission;
use support\Response;

final class IndexController extends BaseController
{
    #[Permission('SandAI 概览', 'sand_ai:overview:index')]
    public function index(): Response
    {
        return $this->success([
            'app' => 'SandAI',
            'plugin' => 'sand-ai',
            'version' => '0.1.0',
            'status' => 'initialized',
        ]);
    }
}
