<?php

declare(strict_types=1);

namespace plugin\SandAi\app\admin\controller;

use plugin\SandAi\app\admin\logic\InvocationLogic;
use plugin\sandadmin\basic\BaseController;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

final class InvocationController extends BaseController
{
    #[Permission('SandAI 调用记录', 'sand_ai:invocation:index')]
    public function index(Request $request): Response
    {
        return $this->success((new InvocationLogic())->index($request->get()));
    }
}
