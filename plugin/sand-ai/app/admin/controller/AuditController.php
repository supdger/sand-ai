<?php

declare(strict_types=1);

namespace plugin\SandAi\app\admin\controller;

use plugin\SandAi\app\admin\logic\AuditLogic;
use plugin\sandadmin\basic\BaseController;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

final class AuditController extends BaseController
{
    #[Permission('SandAI 审计记录', 'sand_ai:audit:index')]
    public function index(Request $request): Response
    {
        return $this->success((new AuditLogic())->index($request->get()));
    }
}
