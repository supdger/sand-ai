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

---

# PR 草稿：SandAI 0.4.10 Phase-2 B2「试一试」页 + G2 菜单统一（WP-B2a）

- 目标分支（PR base）：`fix/0410-phase1`
- 工作分支：`fix/0410-phase2`（基于 WP-A2 的 `c19e772`）
- 仓库：`sand-ai`（前端仅 `sandadmin-artd/src/views/plugin/sand-ai/` 视图树；
  不触碰其他仓库与第一批目录）
- 契约：`SHARED-CONTRACT-P2.md`、`evidence/b2-b3-spec.md`、
  `evidence/proxy-hint-copy.md`、`evidence/fix-list.md`（G1/G2）

## 变更内容

### 新前端页面（`sandadmin-artd/src/views/plugin/sand-ai/trial/index.vue`）

- 标题「试一试」、副标题「写一句话，看看 AI 怎么回答。」，整页包
  `SandAiErrorBoundary`，内容区 `max-width:760px` 居中，正文 ≥14px，
  只用 `var(--el-*)` 主题变量（亮/暗兼容），长文本 `overflow-wrap:anywhere`。
- 服务商区三态：0 个 → 空态卡「还没有可用的 AI 服务商，请先去连接」+
  唯一主按钮〔去连接〕→ `/sand-ai/provider`，不渲染输入区；1 个 → 灰字
  「使用：{中文名}」；≥2 个 → 「用哪个服务商」下拉，默认第一项，只列
  有效中文名的服务商（空名项前端过滤，绝不回退代码）。
- 输入：单 `ElInput` textarea（autosize 2–4 行），占位逐字
  「写一句话，比如：帮我把这段话改得更礼貌」；Enter 发送、
  Shift+Enter 换行、`isComposing`/keyCode 229 跳过中文输入法组词；
  空输入时〔发送〕禁用。
- 回复卡：无占位空卡；成功显示正文 + 「由 {服务商名} 回复 · 用时 N 秒」
  + 文字按钮〔复制回复〕；〔发送〕保持主按钮可继续追问。
- 「高级设置（一般不用改）」`ElCollapse` 默认收起：模型下拉
  （默认值由系统选好，可清除回退后端默认）+ 回复长度
  （`ElInputNumber`，回填 `default_max_output_tokens`，清空即不传）。
- 发送中：〔发送〕loading、输入只读、Spin 卡「正在等 AI 回复……」、
  文字按钮〔停止〕——只 abort 本地等待并如实提示
  「已停止等待；这次请求可能仍在后台完成，结果请在调用记录中查看。」，
  不宣称服务端取消/退费（与 WP-A2 口径一致）。
- 错误分类（对齐 `HttpError.transport` 约定：`undefined`=业务信封、
  `'http'`=真实 HTTP、其他=传输层）：
  - 业务信封 502 或「连不上」前缀 → warning 横幅
    「连不上「{服务商名}」：{后端白话原因原文}」+ 唯一主按钮
    〔去检查连接〕→ `/sand-ai/provider`（服务商页无详情深链，
    见风险节）；〔发送〕降为普通按钮，输入保留。
  - 其它业务错误 → warning 横幅显示后端 `message` 原文
    （剥 `SAND_AI_/SAND_IAM_` 稳定码前缀；非中文兜底固定中文）。
  - 网络/超时/真实 HTTP ≥500/业务信封 ≥500 → danger 横幅固定文案
    「系统出了点问题，请稍后再试」+〔复制问题信息〕（剪贴板仅含
    页面/错误码/时间/版本/请求编号不上屏，Toast「已复制，可发给管理员」）
    + 文字按钮〔重新发送〕——复用同一 `Idempotency-Key` 与同载荷重发，
    绝不换键；此态〔发送〕降为普通按钮。
- 只读：`hasAuth('sand_ai:trial:chat')` 为假时页面可看、输入与发送禁用，
  显示「你只能查看，不能修改。请找管理员开通试用对话权限。」；
  后端 403 仍是最终 enforcement。
- 应用上下文：`iamApi.activeApplications()`；恰好一个自动使用；
  多个时显示「用哪个应用」下拉（切换重新拉 options 并清空会话态）；
  0 个时先幂等 `ensureDefaultAccess()`（与首页一致），仍为 0 显示引导卡
  「还没有可用的应用。请先在「应用接入」创建应用，再回来试用。」+
  〔去应用接入〕→ `/sand-iam/application`；列表读取失败给
  错误卡 +〔重新加载〕。
- 每屏至多一个主按钮；fail/syserr 态主按钮让位给横幅按钮（规格允许的
  例外已按状态核对）。

### 新 API 封装（`sandadmin-artd/src/views/plugin/sand-ai/api/trial.ts`）

- `trialOptions(applicationId)`：`GET ${ADMIN_API_PREFIX}/trial/options`，
  `params.application_id`，`Cache-Control: no-store`，关闭默认错误 Toast。
- `trialChat(input, idempotencyKey, signal?)`：`POST .../trial/chat`，
  Header `Idempotency-Key`，`timeout:150000`（高于服务商默认 120s，
  覆盖 ChatGateway 全链耗时），支持 `AbortSignal`；载荷仅
  `application_id/provider_id/message/model_id?/max_output_tokens?`。
- 幂等键：`crypto.randomUUID()`（降级时间戳+随机串）；仅用户主动发送
  生成新键，系统故障「重新发送」复用同键同载荷。

### G2 菜单迁移（`202611060169_g2_trial_menu.pgsql`）

- 新增一级页 `SandAITrial`：parent=SandAI 根、name「试用」、
  path `/sand-ai/trial`（绝对路径，沿用 164 约定，深链稳定）、
  component `/plugin/sand-ai/trial/index`、sort=90
  （sort 降序下紧随首页 95、位于模型 65 之上）、is_hidden=0、status=1。
- `sand_ai:trial:options`/`sand_ai:trial:chat` 两个按钮权限（168 种子）
  re-parent 到 `SandAITrial` 下便于角色配置分组；code/slug/角色链接不变。
  `sand_ai:trial:context` 保留在 `SandAITaskTrial`（高级组）下，
  因其仍服务旧 admin-trial 通道。
- 已持有 `sand_ai:trial:options` 的角色自动补 SandAI 根与页面链接
  （与页面种子同约定，仅修复漂移，不新增授权）。
- 文件中心/费用与账单保持一级原名原路原组件（wire 侧栏即此两名）；
  任务与会议试用等低频面沿用 164 已归入「高级」的结构，本迁移不重复搬动；
  首页保留一级（见「待产品确认」）。
- 幂等 `DO` 块：`LOCK TABLE ... SHARE ROW EXCLUSIVE`、根节点唯一性断言、
  重复/冲突检查、ownership 快照登记；可重复执行收敛。
- `install.sql` 由 `php tools/build-install-sql.php --write` 重新生成
  （+115 行：迁移正文 + 台账 sha256 `dd896d48…a1dd`），`--check` 通过。

### 兼容性

- 旧试用能力全部保留：`home` 内嵌 `ChatRouteTrialPanel`、`task` 页
  任务/会议试用、`admin/trial/context` 端点均未改动。
- 旧菜单 code/slug/path/component 与角色授权不变；绝对路径保证
  `/sand-ai/task` 等深链在 regroup 后仍逐字节有效。
- `plugin/sand-ai/config/menu.php` 为空数组，菜单唯一来源仍是
  install.sql/迁移，无第二声明点。

## 实际执行命令与结果

| 命令 | 退出码 | 输出尾部 |
|---|---|---|
| `php tools/build-install-sql.php --write` | 0 | `build-install-sql written 836082 bytes` |
| `php tools/build-install-sql.php --check` | 0 | `build-install-sql check ok 836082 bytes` |
| `psql -f install.sql`（临时 PG 18.4 unix-socket 实例 + 宿主表桩） | 0 | `DO` / `INSERT 0 1` / `COMMIT` |
| 迁移 `202611060169` 单独重跑 ×3 | 0 ×3 | `DO`（无重复行、无报错） |
| 角色授权修复路径实测（授 `trial:options` 后重跑） | 0 | 角色自动获得 SandAI 根+页面链接，重跑不重复 |
| `php tools/frontend-gate.php . sand-ai` | 0 | `相对 import 解析 156 个文件 / 585 条相对 import` `前端闸门：通过` |
| 禁用词扫描 `198\.18\|fake-ip\|SSRF\|work_item\.read\|provider-`（trial/ + api/trial.ts） | 1（零命中） | 无输出 |
| 逐字文案 grep（标题/副标题/占位/各状态按钮与横幅文案 20 项） | 0 | 全部 `OK` |
| 发布树卫生扫描 `律序\|lvxu\|lvku`（新文件） | 1（零命中） | 无输出 |
| `shasum -a 256 …/202611060169_g2_trial_menu.pgsql` | 0 | `dd896d48e2a89f4931dc816881b067fa57a8d4e6e65abcca0b3cddf74be7a1dd` |

菜单树实测（scratch 库）：`SandAITrial` 为 SandAI 根直接子级，
`试用 / /sand-ai/trial / /plugin/sand-ai/trial/index / sort=90 /
is_hidden=0 / status=1`；两个 B2 权限节点 parent=96（SandAITrial），
`trial:context` 仍在 `SandAITaskTrial`（高级组 id=92）下；
高级组下任务与会议试用/调用记录/审计等 8 个旧面 path 均为绝对路由。

## 未执行/未验证项与风险

- `vue-tsc --noEmit` 未运行：本仓 `sandadmin-artd/` 仅为视图源码树
  （无 package.json/tsconfig/node_modules），类型检查依赖宿主工程；
  已由 frontend-gate 相对 import 扫描（156 文件/585 条全解析）+
  人工复核兜底，宿主侧 vue-tsc 需在联调环境补跑。
- 未做浏览器实测：无运行中前后端；1280/1440 无横向滚动仅静态审查
  （760px 居中 + flex-wrap + grid 单列断点 + overflow-wrap），
  亮/暗主题经 `var(--el-*)` 静态核对，未截图验证。
- 未发起真实 `trial/chat` 请求、未执行数据库迁移（迁移仅在临时
  scratch 库验证，未触碰任何运行库）。
- **待产品确认 1（规格缺口）**：`trial/options` 必须带 `application_id`，
  但 B2 规格未定义应用选择 UI；本包按任务要求实现「1 个自动 / 多个
  下拉「用哪个应用」/ 0 个引导」三态，位置在服务商区上方，
  需产品确认是否符合预期。
- **待产品确认 2**：「去检查连接」目标为 `/sand-ai/provider` 列表页——
  服务商页目前无按 id 深链到编辑/测试弹窗的能力；如需直达详情需
  另开前端包给 provider 页加 query 支持。
- **待产品确认 3**：wire 侧栏不含「模型」一级项，但 fix-list G2 未点名
  要求移动；本包按最小改动保留模型在一级（sort=65），是否下沉
  「高级」待产品拍板。
- 「复制问题信息」中的请求编号依赖 `HttpError` 自带 `requestId/request_id`
  字段；宿主若不透传则该行省略（其余字段不受影响）。
- 代理 fake-ip 提示：`trial/chat` 当前只回中文业务 message、无结构化
  egress 字段，本页按「原样显示后端白话原因」实现，未单独渲染
  proxy-hint-copy 的「重新测试/放行名单」交互；如后续后端补结构化
  字段需再接 `EgressBlockedHint` 同款卡片。
