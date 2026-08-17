<?php

use plugin\sandadmin\app\middleware\CheckAuth;
use plugin\sandadmin\app\middleware\CheckLogin;
use plugin\sandadmin\app\middleware\SystemLog;
use plugin\SandAi\app\admin\controller\AuditController;
use plugin\SandAi\app\admin\controller\CapabilityProfileController;
use plugin\SandAi\app\admin\controller\IndexController;
use plugin\SandAi\app\admin\controller\InvocationController;
use plugin\SandAi\app\admin\controller\ModelController;
use plugin\SandAi\app\admin\controller\ModelDeploymentController;
use plugin\SandAi\app\admin\controller\ProviderController;
use plugin\SandAi\app\admin\controller\UsageController;
use plugin\SandAi\app\api\controller\HealthController;
use plugin\SandAi\app\api\controller\FileController;
use plugin\SandAi\app\api\controller\RetrievalController;
use plugin\SandAi\app\api\controller\TaskController;
use plugin\SandAi\app\api\controller\ModelsController;
use plugin\SandAi\app\api\controller\AiTaskController;
use plugin\SandAi\app\api\controller\AgentController;
use plugin\SandAi\app\api\controller\InvocationController as RuntimeInvocationController;
use Webman\Route;

Route::group('/app/sand-ai/admin', function (): void {
    Route::get('/index', [IndexController::class, 'index']);
    Route::get('/provider/index', [ProviderController::class, 'index']);
    Route::get('/provider/read', [ProviderController::class, 'read']);
    Route::post('/provider/save', [ProviderController::class, 'save']);
    Route::post('/provider/update', [ProviderController::class, 'update']);
    Route::post('/provider/destroy', [ProviderController::class, 'destroy']);
    Route::get('/model/index', [ModelController::class, 'index']);
    Route::get('/model/read', [ModelController::class, 'read']);
    Route::post('/model/save', [ModelController::class, 'save']);
    Route::post('/model/update', [ModelController::class, 'update']);
    Route::post('/model/destroy', [ModelController::class, 'destroy']);
    Route::get('/invocation/index', [InvocationController::class, 'index']);
    Route::get('/usage/index', [UsageController::class, 'index']);
    Route::get('/audit/index', [AuditController::class, 'index']);
    Route::get('/capability/metadata', [CapabilityProfileController::class, 'metadata']);
    Route::get('/capability/read', [CapabilityProfileController::class, 'read']);
    Route::post('/capability/save', [CapabilityProfileController::class, 'save']);
    Route::post('/capability/publish', [CapabilityProfileController::class, 'publish']);

    // 同仓主应用已有同一路径；只有完整插件
    // 安装到没有 SandAI 主应用的宿主时，才由插件实现这些本地管理入口。
    if (!class_exists('app\\Api\\Controller\\FileController')) {
        Route::post('/setup/deployment/save', [ModelDeploymentController::class, 'save']);
        Route::post('/setup/deployment/publish', [ModelDeploymentController::class, 'publish']);
        Route::get('/setup/capability/metadata', [CapabilityProfileController::class, 'metadata']);
        Route::get('/setup/capability/read', [CapabilityProfileController::class, 'read']);
        Route::post('/setup/capability/save', [CapabilityProfileController::class, 'save']);
        Route::post('/setup/capability/publish', [CapabilityProfileController::class, 'publish']);
    }
})->middleware([
    CheckLogin::class,
    CheckAuth::class,
    SystemLog::class,
]);

// “sand-ai” 是合法插件标识，但不是合法 PHP 命名空间。
// 管理路由必须显式绑定到 plugin\SandAi，禁止依赖 Webman 默认路由推导。
Route::disableDefaultRoute(IndexController::class);

Route::get('/api/sand-ai/v1/health', [HealthController::class, 'index']);

// This repository runs the private platform main application alongside its
// management integration. A published full package has no app\Api runtime,
// so it owns these local routes. Do not register both implementations here.
if (!class_exists('app\\Api\\Controller\\FileController')) {
    Route::get('/api/sand-ai/v1/models', [ModelsController::class, 'index']);
    Route::post('/api/sand-ai/v1/ai-tasks', [AiTaskController::class, 'submit']);
    Route::post('/api/sand-ai/v1/agents/runs', [AgentController::class, 'submit']);
    Route::get('/api/sand-ai/v1/invocations/{requestId}', [RuntimeInvocationController::class, 'read']);
    Route::post('/api/sand-ai/v1/files', [FileController::class, 'upload']);
    Route::get('/api/sand-ai/v1/files/{fileId}', [FileController::class, 'read']);
    Route::delete('/api/sand-ai/v1/files/{fileId}', [FileController::class, 'destroy']);
    Route::post('/api/sand-ai/v1/files/{fileId}/parse', [FileController::class, 'parse']);
    Route::get('/api/sand-ai/v1/tasks/{taskId}', [TaskController::class, 'read']);
    Route::post('/api/sand-ai/v1/tasks/{taskId}/cancel', [TaskController::class, 'cancel']);
    Route::post('/api/sand-ai/v1/tasks/{taskId}/retry', [TaskController::class, 'retry']);
    Route::post('/api/sand-ai/v1/retrieval/search', [RetrievalController::class, 'search']);
}
