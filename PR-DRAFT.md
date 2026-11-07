# PR 草稿：SandAI 0.4.10 Phase-2 B2「试一试」后端（WP-A2）

- 目标分支（PR base）：`fix/0410-phase1`
- 工作分支：`fix/0410-phase2`
- 仓库：`sand-ai`（仅后端；前端为后续包）
- 契约：`SHARED-CONTRACT-P2.md` §3.1/§3.2、`evidence/b2-b3-spec.md`、`evidence/proxy-hint-copy.md`

## 变更内容

### 新端点（admin 组，CheckLogin + CheckAuth + SystemLog，与 `trial/context` 同组）

- `GET /app/sand-ai/admin/trial/options?application_id=<id>`
  - 权限 slug：`sand_ai:trial:options`（`#[Permission]` + 迁移种子）。
  - `application_id` 必填正整数；应用不存在/停用/所属组织停用 → 404；
    超出当前管理员 SandIAM 管理范围 → 403（语义对齐
    `plugin\SandIam\app\admin\support\AdminOrganizationAccess`：超管放行，
    委托管理员按 `AdminApplicationGrant`/`AdminOrganizationGrant` 判定）。
  - 经 §3.1 托管绑定（`workload_client.code='managed'`、`audience='sand-ai'`、
    环境属于该应用）定位受控环境，返回该环境已发布 `chat` 路线投影：
    `{application:{id,name}, providers:[{id,name,models:[{id,name}],default_model_id}], default_max_output_tokens}`。
    仅含存在已发布 chat 路线且 provider/model 有效的服务商；按
    (priority, id) 稳定排序；`default_max_output_tokens=1024`（与既有
    ChatRouteTrialPanel 默认值一致）；不做任何外呼探测。
- `POST /app/sand-ai/admin/trial/chat`
  - 权限 slug：`sand_ai:trial:chat`；Header `Idempotency-Key` 必填
    （复用 `RequestIdempotencyKey`，作用域含托管身份三元组）。
  - Body 白名单：`application_id, provider_id, message, model_id?, max_output_tokens?`。
  - 链路：应用管理范围校验 → 托管绑定定位（0 个 → 中文「还没开通」；
    多个 → 409 冲突，绝不猜第一条）→ `sand_ai.chat.complete` 授权实时复核
    （status=1、未 revoked、未过期、service/action 有效，镜像 SandIAM
    `resolveServiceGrants`；网络策略与配额仍由 `issue`/`verifyForService` 强制）
    → `model_id` 缺省取该 provider 已发布路线中优先级最高者，显式值必须
    属于同 provider 同环境已发布路线 → 为托管客户端签发 300s context /
    330s credential（最小 actions 仅 `sand_ai.chat.complete`，不复用
    `admin_trial` 默认链，绝不调用 `ensureGrants`）→ 签名上下文经
    `verifyForService` 复核（撤销即拒）→ `ChatGateway::complete` 全链
    （预算/计费/幂等/receipt/审计无旁路）→ 返回
    `{application, provider, model, content, latency_ms, usage, invocation_id, idempotent_replay}`。
  - 每次 provider 派发前经 `ChatInvocationAccessGuard::authorize` 重新授权，
    授权在派发间被撤销仍会被拒。
  - 「停止」：本期不提供取消端点；底层无真实取消语义，前端文案按契约
    使用「仅停止等待」，后端不声明服务端取消或退费。
  - 错误：全部经中文白话 `message` 返回（ApiException），状态码
    400/403/404/409/429/502/503 分级；网关内部英文诊断统一收敛为中文文案。

### 服务目录元数据（向后兼容）

- `HostSandIamServiceCatalog` 新增 `serviceDeclaration()`（含
  `group_name='AI 能力'`）与 `actionDeclarations()`（`name`+中文
  `description`），`ACTION_DETAILS` 覆盖全部已登记动作。
- 运行时 `registerServiceActions`/`inspectServiceActions` 载荷保持
  旧版 `code=>name` 形状：当前 SandIAM `ServiceCatalog::declaration()`
  对非字符串值会产生「Array」名称污染，旧端口必须继续收字符串。
  等 WP-A1 的 SandIAM 目录端口接受元数据后，登记调用可切换到富声明。

### 迁移与种子

- `plugin/sand-ai/migrations/main-app/202611060168_trial_managed_permissions.pgsql`：
  幂等种子 `sand_ai:trial:options`、`sand_ai:trial:chat`（type=3 按钮权限，
  挂在试用页/引导页/根节点下，不授予任何角色）。
- `install.sql` 同步追加该迁移正文与 `sand_ai_schema_migration` 台账行
  （sha256 `b438cf4f…b3ae8`）。

### 兼容性

- 旧 `POST /app/sand-ai/admin/trial/context` 与 `/api/sand-ai/v1/*` 未改动。
- 无 SandIAM 插件时新端点返回 503 中文提示，不影响其他功能。
- SandIAM 侧仅消费既有模型/服务类与 `AdminOrganizationAccess`、
  `CredentialIssuanceService`、`IdentityContextProvider`；不读写 SandIAM
  业务表、不建授权。

## 实际执行命令与结果

| 命令 | 退出码 | 输出尾部 |
|---|---|---|
| `php -l`（全部改动 PHP 文件，6 个） | 0 | `No syntax errors detected` ×6 |
| `php tests/trial-managed-chat-test.php` | 0 | `通过 31，失败 0` |
| `shasum -a 256 plugin/sand-ai/migrations/main-app/202611060168_trial_managed_permissions.pgsql` | 0 | `b438cf4f3f791cf380e673efb25197a0c3145a5a7e1df0873be707181f5b3ae8` |

## 未执行/未验证项与风险

- 未执行数据库迁移（无授权、无运行库）；`install.sql` 与迁移文件一致性
  仅经文本核对。
- 未做 PostgreSQL 集成测试：本机无可用 SandIAM+SandAI 运行时，
  options 投影、grant 复核、凭证签发、ChatGateway 全链仅有代码路径与
  单测覆盖；跨仓联调（WP-A1/B3 落地后）需补 fixture 验证。
- `sand_iam_service_grant` 复核 SQL 与 SandIAM `resolveServiceGrants`
  语义对齐但未含 network_policy/quota 预检（由 `issue`/`verifyForService`
  兜底，失败时映射为中文拒绝）。
- 「仅停止等待」：后端本期无取消端点；如需显式 stop 语义字段需前端包确认。
- `AdminTrialService::chat` 的 `ChatGateway` 返回 `content` 在
  `content_purged` 场景为空串，前端需按 `idempotent_replay`/usage 兜底。
