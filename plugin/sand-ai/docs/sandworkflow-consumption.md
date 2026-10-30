# SandAI 使用 SandWorkflow

材料分析的异步任务、请求幂等、恢复、轮询和成果校验由 SandAI 负责。
`review_required` 是成果需要人工核实的标记，它本身不发起审批、不调用 Workflow，
也不代表 AI 任务失败。材料整理不要求安装 SandWorkflow。

唯一例外是下文“会议摘要人工复核”：它仍然不是由 `review_required` 自动触发，
而是具名管理员读到该标记后显式提交；未标记复核的任务不产生审批实例。

业务调用方明确要求启动已发布的业务流程时，同一宿主中的可信业务代码可以
调用 `app\Application\Workflow\SandWorkflowConsumer::start()`。
调用方提供流程定义 ID、实例名称、表单数据和既有的
`plugin\sandworkflow\app\contract\WorkflowStartContext`；该对象包含真实
发起人 `ActorRef`、参与人、业务范围和稳定请求键。适配器直接调用
`WorkflowRuntimeService::start()`，不创建新的节点、机器路由或流程执行账本。

```php
$consumer = new \app\Application\Workflow\SandWorkflowConsumer();
$instance = $consumer->start($definitionId, $name, $formValue, $verifiedStartContext);
$detail = $consumer->detail((string) $instance['id'], $verifiedActor, $verifiedWorkflowScope);
```

这些变量须来自调用方已经核验的业务上下文，不能直接反序列化外部请求。
Workflow 的 `tenant_id`、`owner_scope`、`business_type`、`business_id` 不是
SandIAM 的组织、应用、环境、客户端 ID 的隐式别名；调用方必须维护可信映射，
同时检查操作权限与可用的流程定义、参与人。SandAI 工作负载身份不能自动
转换为人。详情读取继续由 Workflow 校验同一业务范围和参与人，适配器不启用
monitoring 绕过参与人限制，也不提供 approve 入口。

请求键最长 64 字节，同一发起人与键应固定同一业务范围、定义和输入。
Workflow 1.0.7 的启动接口按发起人和请求键复用已有实例；当前适配器不增加
跨系统事务，也不能宣称已有接口能拒绝同键异输入。调用方不得跨业务复用键。

跨宿主 HTTP 调用、流程图内部等待 AI、同键异输入的强制冲突校验均没有通过
这层适配器实现。真实业务若必须依赖这些能力，应先向 sand_plugins 提交
现有接口失败复现、替代方案和通用性／必要性论证，再决定是否修改提供方。

契约回归使用实际 Workflow DTO 与 RuntimeService 核对参数传递、身份与范围
保留、缺失范围拒绝和不触发审批；它不连接数据库，不能代替真实宿主业务验收。

## 会议摘要人工复核（2026-10-30 实测集成）

这是当前唯一落地的真实业务集成：会议摘要输出契约置 `review_required=true`
后，具名 SandAdmin 管理员在后台显式提交，经 SandWorkflow 公开契约创建审批实例。

- 适配器 `app\Application\Workflow\MeetingReviewWorkflowBridge`，蓝图
  `app\Application\Workflow\MeetingReviewWorkflowDefinition`，二者只调用
  `WorkflowDefinitionService::publish()` 与既有 `SandWorkflowConsumer`
  （`WorkflowRuntimeService::start()/detail()`），不改 SandWorkflow 源码。
- 业务范围固定为 `tenant_id=sandadmin`、`owner_scope=sand_ai`、
  `business_type=sand_ai_meeting_review`、`business_id=<SandAI 任务号>`。
  它们不是 SandIAM 组织／应用／环境／客户端 ID 的别名；SandAI 的
  `{organization_id,application_id,environment_id}` 另存于本侧引用表并写入
  表单字段供复核人核对。`owner_scope=sand_ai` 使 SandAdmin 通用流程详情
  （固定 `sandadmin/sandadmin` 且 monitoring）读不到该实例。
- 发起人是当前登录管理员，复核人是请求显式指定的在职后台用户；二者都经
  `SystemUser` 核验后以 `ActorRef('sandadmin', id)` 传入。SandAI 工作负载身份
  不自动转换为人，流程节点不配置固定账号，只配置参与人键
  `meeting_summary_reviewer`。
- 稳定请求键为 `sand_ai_meeting_review_<task_id>_r<转写复核版本>`。原键再次提交
  只回原实例（`replayed=true`），不新建；同环境同蓝图只发布一次流程定义。
- 表单只写引用：任务号、转写任务号、转写复核版本、摘要契约 code@version、
  条目统计、覆盖结论、归属与 `GET /api/sand-ai/v1/tasks/{id}` 入口。摘要正文、
  引用段、私有对象定位与厂商原始响应都不复制进 Workflow 存储。
- 适配器不提供 approve／reject／cancel，也不回写 SandAI 任务状态。审批结论由
  SandWorkflow 自己的公开端点处理，SandAI 始终从 `detail()` 读回当前状态。

已知边界：同一任务同一转写复核版本只允许一轮审批；驳回后需要先修正转写产生
新的复核版本才能再次提交。审批通过本身不改变 SandAI 任务或成果状态，后续业务
动作仍须单独确认。
