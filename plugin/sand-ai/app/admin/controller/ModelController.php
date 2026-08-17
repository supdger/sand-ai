<?php

declare(strict_types=1);

namespace plugin\SandAi\app\admin\controller;

use plugin\SandAi\app\admin\logic\ModelLogic;
use plugin\sandadmin\basic\BaseController;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

final class ModelController extends BaseController
{
    #[Permission('SandAI 模型列表', 'sand_ai:model:index')]
    public function index(Request $request): Response
    {
        return $this->success((new ModelLogic())->index($request->get()));
    }

    #[Permission('SandAI 模型读取', 'sand_ai:model:read')]
    public function read(Request $request): Response
    {
        return $this->success((new ModelLogic())->read($request->get() + $request->post()));
    }

    #[Permission('SandAI 模型保存', 'sand_ai:model:save')]
    public function save(Request $request): Response
    {
        return $this->success(['id' => (new ModelLogic())->create($request->post())], '保存成功');
    }

    #[Permission('SandAI 模型更新', 'sand_ai:model:update')]
    public function update(Request $request): Response
    {
        (new ModelLogic())->update($request->post() + $request->get());
        return $this->success('更新成功');
    }

    #[Permission('SandAI 模型删除', 'sand_ai:model:destroy')]
    public function destroy(Request $request): Response
    {
        (new ModelLogic())->destroy($request->post());
        return $this->success('删除成功');
    }
}
