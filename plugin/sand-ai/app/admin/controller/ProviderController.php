<?php

declare(strict_types=1);

namespace plugin\SandAi\app\admin\controller;

use plugin\SandAi\app\admin\logic\ProviderLogic;
use plugin\sandadmin\basic\BaseController;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

final class ProviderController extends BaseController
{
    #[Permission('SandAI 服务商列表', 'sand_ai:provider:index')]
    public function index(Request $request): Response
    {
        return $this->success((new ProviderLogic())->index($request->get()));
    }

    #[Permission('SandAI 服务商读取', 'sand_ai:provider:read')]
    public function read(Request $request): Response
    {
        return $this->success((new ProviderLogic())->read($request->get() + $request->post()));
    }

    #[Permission('SandAI 服务商保存', 'sand_ai:provider:save')]
    public function save(Request $request): Response
    {
        return $this->success(['id' => (new ProviderLogic())->create($request->post())], '保存成功');
    }

    #[Permission('SandAI 服务商更新', 'sand_ai:provider:update')]
    public function update(Request $request): Response
    {
        (new ProviderLogic())->update($request->post() + $request->get());
        return $this->success('更新成功');
    }

    #[Permission('SandAI 服务商删除', 'sand_ai:provider:destroy')]
    public function destroy(Request $request): Response
    {
        (new ProviderLogic())->destroy($request->post());
        return $this->success('删除成功');
    }
}
