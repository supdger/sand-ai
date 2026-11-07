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

---

# WP-B2b：sand-ai 前端存量页面整改（H2/H3/H4/G1）

分支 `fix/0410-phase2`，基于 WP-A2/WP-B2a 之上。本包只改
`sandadmin-artd/src/views/plugin/sand-ai/` 前端视图与展示层，
不改后端 PHP、不改菜单迁移、不动宿主共享表格实现。

## H2 文件主视图（file/index.vue）

- 主视图收敛为：调用密钥、上传文件、文件列表（文件 / 大小 /
  处理进度 / 上传时间 / 操作）。
- 「按编号查询」「长 TXT 来源与旧解析恢复」「找回文件解析」
  「只读查询已有解析」「找回录音转写」「清理结果待确认 details」
  全部移入默认折叠的「高级：按编号查询与找回恢复（一般不用）」
  （原生 `<details>`，未删除任何恢复/找回/只读能力，绑定与
  sessionStorage 幂等逻辑原样保留；原先重复渲染 3 份的清理
  details 合并为 1 份）。
- 锁定态（parse/audio/cleanup pending）在主视图保留一行中文提示，
  指引用户到高级区核对，不再直接平铺表单。
- 行内「操作」改为情境主操作 + 详情 + 刷新 + 删除：
  已解析文件主操作为「查看结果」（原「查看解析出的文字」），
  未解析显示「提交解析」，录音显示「转写录音」，失败/取消才显示
  「继续原解析任务 / 核对取消后重新解析」，解析/转写中显示「看任务」；
  其余解析、转写、清理状态入口移入新增「文件详情」弹窗
  （ElDescriptions + 原操作按钮，disabled/loading 条件与原行一致）。
- 列表不再平铺扩展名/格式/清理状态/解析摘要/任务列；解析摘要改用
  `runStateLabel` 中文状态与 `hintForRuntimeErrorCode` 错误提示，
  不再显示 `parser_code@parser_version` 原始串。

## H3 响应式表格（1280/1440 静态处理）

| 页面 | 处理 |
|---|---|
| file | 主表 10 列 → 5 列（文件/大小/处理进度/上传时间/操作，合计 min≈825px），次要列入详情弹窗 |
| task | 主表 8 列收紧至 ≈920px；类型/资源/状态改中文标签（displayLabels 新增 taskType/resourceType 映射，未知值回退原值）；错误码列改 `hintForRuntimeErrorCode` 中文提示 + tooltip；步骤/文件/引用/音频分段子表同步收窄，来源引用列名 block/file/locator_type/locator → 来源块/文件/定位方式/定位详情 |
| capability | 路由表 6 列 min 合计 990→≈810px（能力140/处理方式130/部署220/解析工具160/优先90/操作80） |
| provider/model | 各 8 列收紧至 ≈910/≈830px，名称列加 overflow tooltip |
| invocation | 9 列收紧至 ≈930px，错误码中文化 + tooltip |
| audit | 8 列收紧至 ≈940px |
| knowledge | 8 列收紧至 ≈900px，位置列加 tooltip |
| usage（费用事件表） | 列多（≈28 列）但经 `ArtTableHeader` columnChecks 提供用户列显隐，且 ArtTable/ElTable 自带容器内横向滚动不裁剪；本包仅将面板内两个查询类实心按钮降为次级，未删列 |
| trial | 表单页无数据表，既有响应式（760px 居中/flex-wrap/单列断点）保持 |

未使用 `overflow-x:hidden`；长文本靠 `showOverflowTooltip`、
`overflow-wrap`、列合并与详情弹窗承载。

## H4 能力/材料配置中文化

- capability/index.vue：说明文案「无需 API Key」→「无需密钥」；
  配置名称占位改中文示例；修订冲突提示中的 `driver_code`
  原始码改经 `driverLabel` 中文展示、部署号加「部署 #」前缀。
- MaterialProfileEditor.vue：主表单只留 名称 / 「按项目整理」
  （document_classifications）/「要整理出的人、机构等」
  （entity_types）/「要额外整理的内容（可不填）」
  （extension_fields）；代码、版本、输出语言、跨文件复核输出、
  提示词/输出字符上限移入默认折叠「高级设置（开发者一般不用改）」。
  「标准 JSON」选项改显示「标准输出」，`neuron_*` payload 值不变；
  校验规则（小写字段码正则、必填、长度、去重）全部保留，
  仅校验文案改用新标签。
- MaterialSampleRunner.vue：来源弹窗的 `locator_type`+JSON 移入
  「技术详情」折叠；服务端校验失败提示同步新标签；
  「业务范围」占位去掉「代码」字样。
- 历史配置字段名、payload、revision/幂等/撤销流程均未改动；
  后端 `message` 原样透传未包裹。

## G1 主按钮统一（每操作区至多一个实心主按钮）

- file：行内「提交解析/转写录音」实心按钮全部移除（情境操作
  统一默认按钮）；录音提交弹窗内「提交转写」保留为该弹窗唯一主按钮。
- task：「查询」及详情区「使用当前授权继续原任务」「使用一次机会
  恢复原 OCR 任务」「保存复核」「生成会议摘要」「纠正失败节点并继续」
  均降为默认按钮；全页仅保留「提交 AI 任务」一个实心主按钮。
- knowledge：「检索」降为默认，「用选中来源提交 AI」为唯一实心主按钮。
- usage-cost-panel：「应用筛选」「查看费用汇总」降为默认
  （查询类不与主操作竞争）。
- capability：能力表单「保存草稿」为该表单区唯一实心主按钮；
  「启用配置档」为 success 语义钮；MaterialProfileEditor 同理
  （保存草稿 + 核验样本并启用 success）。
- provider/model：各页仅「新增」一个实心主按钮；编辑/删除为
  link/danger。invocation/audit/usage/index/trial 无新增实心按钮
  冲突；trial 页实心按钮分属互斥状态（空态/错误态/发送）。
- 组件级（DeploymentSetup/TrialPanel 等表单、弹窗、折叠区）各
  操作区均至多一个实心主按钮，未做机械替换。

## 实际执行命令与结果

| 命令 | 退出码 | 输出尾部 |
|---|---|---|
| `php tools/frontend-gate.php . sand-ai` | 0 | `前端闸门：相对 import 解析 156 个文件 / 587 条相对 import` `前端闸门：通过` |
| `python3 tools/scan_relative_imports.py …` | — | 本仓无此脚本；等价扫描已由 frontend-gate 覆盖（见上） |
| 禁用词扫描（`198\.18\|fake-ip\|SSRF\|sk-\|api[_-]?key[:=]`，改动文件 diff） | 1（零命中） | 无输出 |

## 未执行/未验证项与风险

- `vue-tsc --noEmit` 未运行：本仓 `sandadmin-artd/` 仅视图源码树，
  类型检查依赖宿主工程（同 WP-A2 说明）。
- 未做浏览器实测：无运行中宿主/后端；1280/1440 无横向滚动为
  静态列宽核算 + 既有响应式类复核，未截图/DOM 实测。
- usage 费用事件表列数多，依赖既有列显隐开关与表格内部滚动；
  如验收要求默认列进一步收敛，需产品确认可隐藏的次要维度。
- 「按项目整理」为 document_classifications 的用户侧命名映射，
  「要整理出的人、机构等」为 entity_types 的映射；字段与
  payload 未变，若产品另有指定文案可再调。

# WP-F1：WP-C1 取证修复（sand-ai 侧 HIGH/MEDIUM）

- 工作包：WP-F1（第二批修复，固定模型 SWE-2）
- 基线：`b757e0c`（fix/0410-phase2）；本包提交：`50d18c6`、`4a6111e`、`ff304d4`（另有本文档提交）
- 对应 WP-C1 发现：HIGH-1、HIGH-2、HIGH-3、MEDIUM（config.json 依赖下限）

## HIGH-1：富声明未接入生产注册路径

- `HostSandIamServiceCatalog::registerActions()`/`inspectActions()` 原先固定发送
  旧版 `self::SERVICE` + `code=>name` 载荷，`serviceDeclaration()`/
  `actionDeclarations()` 仅被测试引用，导致 `sand_iam_service_action.description`
  恒 NULL，B3 `visibleCatalog` 过滤全部 sand_ai 动作。
- 修复：新增 `portPayload()` + `acceptsCatalogMetadata()`。解析到的宿主端口对象
  经 `ReflectionObject` 探测 sand-iam >=0.9.7 `ServiceCatalog` 的声明 schema 标记
  （私有常量 `MAX_DESCRIPTION` + 私有方法 `optionalAudience()`，仅 0.9.7 存在）：
  - 富端口：发送 `serviceDeclaration()`（`code/name/group_name/audience`）与
    `actionDeclarations()`（每动作 `name`+`description`）；
  - 旧端口：保持 `['code','name']` + `code=>name` 字符串载荷——旧端口忽略多余
    service 键，但会把数组动作值静默写成字面量 `"Array"`，因此必须探测而非
    直接发送。
- 影响面：`ServiceCatalogBootstrapService::bootstrap()`、
  `AdminTrialContextService::ensureServiceCatalog()`、SetupController 的
  preflight/register/registerSkill/registerAudio 全部经同一 `registerActions`/
  `inspectActions` 路径，均自动获得富载荷。

## HIGH-2：serviceDeclaration 缺 audience

- `serviceDeclaration()` 增加 `audience => 'sand-ai'`（新私有常量
  `SERVICE_AUDIENCE`），与 SandIAM `AccessMatrixInput::DEFAULT_AUDIENCE`、
  `AdminTrialIamGateway::AUDIENCE`、`AdminTrialContextService::AUDIENCE` 及
  §3.1 托管绑定约定一致。迁移 044 仅从历史 grant 回填
  `sand_iam_service.audience`，全新安装为 NULL 时
  `ApplicationCapabilityService::managedClientFor` 报「该服务尚未登记可用的
  调用受众」；声明自带 audience 后 `persist()` 对已有服务行也会回写该列。

## HIGH-3：host-payload.json / release-payload.json 过期

- `host-payload.json` 缺 `app/Application/Trial/AdminTrialIamGateway.php` 与
  `AdminTrialService.php` 条目，`HostSandIamServiceCatalog.php` 摘要过期。
  仓库无 host-payload 生成工具（沙箱包 `HostPayloadManifest` 只校验不生成），
  按既有格式（`schema:1`、`app`、按 path 排序的 `files[]`）以实际 `app/`+`config/`
  全量文件重算 SHA-256 重写：1016 → 1018 条。已用 sand-package
  `HostPayloadManifest::inspectDirectory` 实跑校验通过。
- `release-payload.json`：`php tools/build-release-payload.php`（无
  `--iam-bundle`，按 config.json 声明记录依赖摘要）重写，枚举 1427 个成员，
  `--check` 复核一致。除本包改动外还带入此前漏更的 `install.sql`、
  `AdminTrialController.php`、`config/route.php` 等摘要。

## MEDIUM：plugin_dependencies.sand-iam 版本下限

- 评估结论：升至 `0.9.7`。`PluginDependencyPolicy`/`InstallLogic` 将
  `plugin_dependencies.*.version` 按最低版本校验
  （`installedDependencyMeetsMinimum`），且捆绑工件名
  `dependencies/sand-iam-<version>.zip` 随版本变化；B2/B3 依赖 sand-iam
  0.9.7 的迁移 044 列与 `ServiceCatalog` 富声明 schema，低于下限的环境
  必须先升级 sand-iam，故下限必须上调。
- `config.json` 与 `plugin/sand-ai/config.json` 同步 `0.9.6 → 0.9.7`；
  `sha256` 维持全零占位符（发布时回填，不伪造）。`HostFoundationCompatibility`
  只门禁 sand-core/sand-package，与本项无联动。
- `config.json` 的 `service_catalog` 声明保持 `service={code,name}` +
  `actions=code=>name` 旧形状：sand-package `PluginServiceCatalogPolicy`
  强制该 schema（service 恰好 code/name 两键、动作值必须字符串），安装期
  登记仍走旧载荷；运行期 `HostSandIamServiceCatalog` 富登记会由
  `persist()` 回写 group_name/audience/description。

## sand-iam 端口字段对齐表（对照 0.9.7 `app/runtime/ServiceCatalog.php`）

| 声明字段 | 端口接受面 | 持久化位置 | 本包取值 |
|---|---|---|---|
| service.code | 必填，`^[a-z0-9][a-z0-9_-]{1,63}$` | `sand_iam_service.code` | `sand_ai` |
| service.name | 必填，非空 ≤128 无控制字符 | `sand_iam_service.name` | `SandAI` |
| service.group_name | 可选 ≤128 无控制字符 | `sand_iam_service.group_name` | `AI 能力` |
| service.audience | 可选 ≤128，`^[a-zA-Z0-9][a-zA-Z0-9._~-]*$` | `sand_iam_service.audience` | `sand-ai` |
| actions `code=>string` | 旧形状，name 非空 ≤128 | `sand_iam_service_action.name` | 全部 27 动作中文名 |
| actions `code=>{name,description}` | 0.9.7 形状，description 可选 ≤512 无控制字符 | `sand_iam_service_action.name/description` | name 沿用旧名，description 取 `ACTION_DETAILS`（缺省回退 name） |

- 旧端口（0.9.6 及更早）对照：service 多余键被忽略；数组动作值会被
  `(string)` 强转为 `"Array"` 静默落库——故旧端口一律发旧形状。
- `persist()` 语义：新建行写入全部非空字段；已存在行始终回写 `name`，
  仅当 `group_name`/`audience`/`description` 非空时回写对应列（旧声明
  不会清掉新元数据）。

## 测试与门禁（实跑）

| 命令 | 退出码 | 输出末段 |
|---|---|---|
| `php -l app/Identity/Infrastructure/HostSandIamServiceCatalog.php` | 0 | `No syntax errors detected` |
| `php -l tests/trial-managed-chat-test.php` | 0 | `No syntax errors detected` |
| `php -l tools/build-package.php` | 0 | `No syntax errors detected` |
| `php tests/trial-managed-chat-test.php` | 0 | `通过 37，失败 0`（新增 6 条：audience 断言 + 富/旧端口 inspect/register 载荷断言） |
| `php tools/build-release-payload.php --check`（修复前复现） | 1 | `失败：release-payload.json 与实际源码成员集合不一致` |
| `php tools/build-release-payload.php`（重写） | 0 | `成功：…（1427 个成员）` |
| `php tools/build-release-payload.php --check`（修复后） | 0 | `一致：1427 个成员` |
| `HostPayloadManifest::inspectDirectory(., 'sand-ai')`（sand-package 0.9.7 侧实跑） | 0 | `inspectDirectory OK: 1018 files` |
| `php tools/build-install-sql.php --check` | 0 | `build-install-sql check ok 836082 bytes` |
| `php tools/frontend-gate.php . sand-ai` | 0 | `前端闸门：相对 import 解析 156 个文件 / 587 条相对 import` `前端闸门：通过` |

- pre-commit（`scan_change_quality.py --repository-review`）：每次提交前
  prepare → 写 review 结论（/tmp，仓库外）→ record → check，三次提交均通过，
  未删 hook、未用 `--no-verify`、未用 `git add -A`。

## 遗留偏差与未验证项

- 端口能力探测依赖 sand-iam `ServiceCatalog` 的私有成员名
  （`MAX_DESCRIPTION`/`optionalAudience`）；若 sand-iam 后续重构内部实现而
  保留 schema，探测会回退旧载荷（降级为不写元数据，不会写坏数据），需在
  sand-iam 变更时复核。
- 安装期 `preflightPluginServiceCatalog`/`registerServiceCatalog` 走
  `config.json service_catalog` 旧形状（sand-package schema 限定），装完后
  由 SandAI 运行期富登记回写元数据；B3 目录在首次 bootstrap/试用前可能短暂
  为空目录，属既有时序。
- `dependencies/sand-iam-0.9.7.zip` 真实工件与 sha256 待发布时由维护者提供；
  构建闸门在此之前按设计 fail-closed。
- 未做真实 PG 集成验证（B3 端点联调属 WP-A1/WP-C2 范围）；本包验证止于
  载荷形状断言、清单校验与静态门禁。
