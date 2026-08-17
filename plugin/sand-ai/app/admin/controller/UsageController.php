<?php

declare(strict_types=1);

namespace plugin\SandAi\app\admin\controller;

use plugin\SandAi\app\admin\logic\UsageLogic;
use plugin\sandadmin\basic\BaseController;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

final class UsageController extends BaseController
{
    #[Permission('SandAI 用量记录', 'sand_ai:usage:index')]
    public function index(Request $request): Response
    {
        return $this->success((new UsageLogic())->index($request->get()));
    }
}
