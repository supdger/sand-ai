# SandAI 架构与分期设计

> 状态：框架初始化设计
> 更新日期：2026-08-05
> 本文只定义边界与路线，不代表对应业务已经实现。

## 1. 结论先行

SandAI 应定位为“供多个业务项目复用的 AI 能力服务”，而不是另一个业务后台，也不是
流程审批平台。

推荐的首期路线是：

1. 先完成 SandIAM 应用身份与服务授权接入、模型接入、统一调用、用量与审计闭环。
2. 再完成文件、AI 任务与示例项目材料智能整理的首个复杂场景。
3. 随后把知识库做成按应用授权的跨项目能力。
4. 智能体和机器人/渠道接入后置。
5. 不把已有流程引擎搬入 SandAI，也不使用 Neuron Workflow 替代业务审批流程。

Neuron AI 值得进入技术验证，但当前结论是“候选底层库”，不是“已验证可直接用于
Webman 生产环境”。首期必须通过兼容性小试验后才能决定是否纳入运行依赖。

## 2. SandAI 做什么、不做什么

### 2.1 做什么

- 为业务项目提供稳定、可鉴权、可计量的 `/api/sand-ai` API。
- 统一封装模型供应商差异，避免业务项目直接持有供应商密钥。
- 消费 SandIAM 已授权的应用/环境上下文，管理模型配置、AI 能力限额、用量与执行审计。
- 提供可被多个业务项目授权复用的知识库、文档与检索能力。
- 在后续阶段提供智能体、工具和机器人/渠道适配。
- 通过 SandAdmin 插件提供管理面，不让管理插件进入运行面核心调用链。

### 2.2 不做什么

- 不承载业务项目自身的会员、订单、库存等领域逻辑。
- 不成为文件网盘；文件模块只服务于 AI 输入、解析、索引和追溯。
- 不在当前阶段实现智能体、知识库、异步任务或机器人。
- 不实现流程/审批引擎。当前已明确需求由 SandWorkflow 现有能力满足，无需改造插件；
  消费接入由 SandAI／消费应用侧使用现有公开契约完成，不复制流程引擎。真实业务定义和
  调用入口仍待确认，统一产品 6.1 保留集成验收门；独立材料功能不以该业务确认作为前置。
- 不把 Neuron 的 agentic workflow 等同于企业审批流程。前者是 AI 执行编排能力，
  后者仍归独立流程引擎。

## 3. 命名约定

| 场景 | 约定 | 示例 |
| --- | --- | --- |
| 产品展示 | `SandAI` | 后台标题 |
| 仓库、插件标识、路由段 | `sand-ai` | `/api/sand-ai` |
| 后端服务目录 | `server` | `server/` |
| 运行时代码目录 | `app/*` | `server/app/Api`、`server/app/Domain` |
| PHP 命名空间、类、模块 | `app\*` | `app\Api` |
| 配置键、表名、权限标识 | `sand_ai` | `sand_ai:overview:index` |

`sand-ai` 含连字符，不能直接作为 PHP 命名空间。管理插件目录使用
`plugin/sand-ai`，PHP 使用 `plugin\SandAi`，通过 Composer PSR-4 显式映射；路由也
必须显式绑定控制器，不能依赖 Webman 默认路由推导。

## 4. 运行面与管理面

### 4.1 运行面（Data Plane）

SandAI 主应用的确认运行面是本仓 `server/app/**` 与 `server/config/**`，并消费随仓 SandAdmin
作为管理宿主；SandIAM 是该宿主中的身份与服务授权插件。另行分发的完整 `sand-ai` 插件归
`sand_plugins/sand-ai`，不得与主应用运行面混用。主应用运行面负责：

- API 鉴权、租户/应用上下文和请求幂等；
- 模型路由、超时、重试、熔断和供应商适配；
- 同步响应与流式响应；
- 知识检索、提示组装和调用执行；
- 用量事件、审计事件和异步任务投递。

管理面复用宿主 SandAdmin 的插件加载、账号和权限能力，主应用通过 SandIAM 获取应用授权上下文；
不得再建立独立登录、用户、角色、application、credential 或 grant 体系。

### 4.2 管理面（Control Plane）

主应用管理面位于 `server/app/Admin/**` 与 `sandadmin-artd/src/views/plugin/sand-ai`；完整插件包
在其自身 `plugin/sand-ai/**` 与前端载荷中提供等价管理能力。管理面只负责：

- SandIAM 身份上下文消费；
- 供应商凭证和模型目录管理；
- 知识库、文件、解析和索引状态管理；
- 配额、用量、调用日志与审计查询；
- 后台菜单、按钮权限和运维操作。

管理面通过运行面定义的应用服务或管理接口修改配置，不在控制器中直接执行模型调用、
文档解析或向量化。

### 4.2.1 代码归属与交付形态

SandAI 有两种隔离的正式交付形态：本仓主应用拥有 `server/app/**`、配置、迁移和外部服务 API；
`sand_plugins/sand-ai` 的完整插件拥有自身的 Model、Validate、Logic、Controller、迁移、管理 UI
与宿主本地业务 API。两者都通过 SandIAM 获取应用和服务授权，但不得互相转发或把迁移期副本
当成另一条正式运行面。

### 4.3 依赖方向

```text
业务项目 ──HTTP/API──> SandAI 运行面 ──> 领域接口 ──> 供应商/存储适配器
                              │
                              └──> 用量、日志、异步事件

SandAdmin 管理插件 ──> SandAI 管理用例 ──> 同一领域接口

独立流程引擎插件  （无依赖、无接入）
```

### 4.3.1 业务 API、MCP 与后台插件不是同一概念

SandAI 可把同一受控能力以不同调用入口提供，但入口不能互相替代：

> **已确认（2026-08-13）：** AI 产品市场里的“插件”是产品交付/接入项，不能仅凭
> 市场界面把它判定为 MCP 服务；SandAI 对这类接入按实际协议分流，而非按名称分流。

| 入口 | 调用方 | 责任 | 当前状态 |
| --- | --- | --- | --- |
| HTTP 运行 API | 示例项目等业务应用 | 稳定业务系统集成、身份上下文校验、用量和审计 | 已有并持续演进 |
| MCP Server | 具备 MCP Client 的 AI Agent | 将已授权的 SandAI 能力暴露为可发现、可调用的 Agent 工具 | 后续能力，不等于现有 SandAdmin 插件 |
| SandAdmin 插件 | 平台管理员和运维人员 | 配置、发布、观测和审计；全量上架包另带宿主本地业务 API | 当前管理整合存在；全量发布包待 SAND-113 |

因此，Kimi 等产品市场中的“插件”可能把一个或多个 MCP Server、Skill、OAuth 授权和
商店展示封装成安装项；**MCP 是 Agent 调用工具的协议，插件是产品/交付封装**。SandAI、
SandIAM、SandWorkflow 的 SandAdmin 插件按本仓库的业务插件规则交付，不能因名称相同就
视为 MCP 服务。未来新增 SandAI MCP Server 时，必须复用 SandIAM 授权、能力路由、私有
文件边界、来源引用和审计，且先冻结独立 MCP 工具契约。

#### 市场“插件”的判定与 SandAI 接入分层

从插件市场的卡片或“已安装”状态，最多只能确认该项能让 AI 产品调用外部能力；**不能
据此断言它一定是 MCP 服务**。同一类产品入口在底层可能是 MCP Server、厂商私有 Tool
协议、Skill、内建连接器，或带 OAuth 的服务适配器。只有能取得并验证 MCP transport、
`tools/list` 与工具 Schema 等协议证据时，才把它标记为 MCP。

| 看到的对象 | 产品层含义 | 是否必然是 MCP | SandAI 的处理方式 |
| --- | --- | --- | --- |
| AI 产品市场中的 GitHub、数据库、云服务“插件” | 让 Agent 可选用的外部工具/数据连接 | 否；可能是 MCP，也可能是原生连接器或 Skill | 先登记为“外部工具接入”；按实际协议选择 MCP Client 或专用 Adapter |
| 一个 MCP Server | 标准化工具发现与调用端点 | 是 | 作为受管 MCP Server，登记 Server、Tool、版本、Schema、健康状态和风险等级 |
| SandAI HTTP API | 业务应用调用的稳定服务接口 | 否 | 示例项目等应用直接调用；SandIAM 校验工作负载上下文并审计 |
| SandAdmin / Sand 业务插件 | 管理、安装或宿主本地业务能力的交付包 | 否 | 按全量插件或管理整合层规则交付；不因名称相同被接入 Agent 工具链 |

SandAI 的中转/能力网关作用是统一身份、能力选择、数据外发策略、审计与安全边界，而不是
把所有外部服务都强制改造成 MCP：MCP 可接入时走 MCP；已有稳定 HTTP/SDK 能力时可走
受控 Adapter；两者在 Agent 侧都表现为“可用工具”。任何第三方工具在被 Agent 调用前，
都必须有明确的调用主体、允许的 service action、输入/输出 Schema、数据范围、超时/限流
和只读或显式确认的副作用策略。

### 4.4 平台主体与业务用户边界

平台超级管理员是 Sand 平台全局治理者；示例项目等接入方在产品视角上是平台客户，在
SandIAM 数据模型中表示为 `organization + application`，并通过 environment、
workload client 和 service grant 使用 SandAI。示例项目自身的律师、客户和律所管理员可使用
SandIAM 的应用身份、用户类型、角色和数据范围能力；案件与其他业务数据仍归示例项目管理。

完整要求见 [SandIAM 与 SandAI 服务接入边界](sand-iam-access-boundary.md)。

## 5. 推荐领域模块

| 模块 | 责任 | 首期 |
| --- | --- | --- |
| SandIAM Access | 消费外部业务应用、环境、工作负载客户端和服务授权上下文；权威数据归 SandIAM | 平台依赖 |
| Provider | 供应商连接、密钥引用、超时和可用性 | 必做 |
| Model | 统一模型标识、能力标签、供应商映射、路由策略 | 必做 |
| Gateway | 对外请求协议、鉴权、幂等、限流、错误映射 | 必做 |
| Usage | token/请求/费用事实记录、按应用聚合 | 必做 |
| AuditLog | 管理操作和运行调用追溯，敏感内容脱敏 | 必做 |
| File | 文件元数据、存储引用、校验和、生命周期 | 二期 |
| AsyncTask | 解析、抽取、切片、向量化、重建索引等长任务 | 二期 |
| Knowledge | 知识库、文档、切片、索引版本和访问授权 | 三期 |
| Retrieval | 检索策略、过滤、召回、重排和引用 | 三期 |
| Agent | 指令、工具、模型、知识库的版本化组合 | 四期 |
| Channel | 企业微信、飞书、钉钉等机器人驱动与消息映射 | 四期 |

供应商、向量库、对象存储和解析器都应通过接口加驱动注册，不把厂商字段泄露给 Controller
或领域模型。

## 6. 首期 MVP

### 6.1 目标

让至少一个业务项目能通过 SandAI 的统一 API 调用一个已配置模型，并能回答：

- 谁在调用；
- 调用了哪个逻辑模型和哪个实际供应商模型；
- 是否成功、耗时多少；
- 消耗多少 token/额度；
- 出错时可否定位，但不泄露供应商密钥和敏感提示内容。

### 6.2 最小范围

- SandIAM 应用/环境上下文接入；
- 工作负载凭证验证、服务动作授权、轮换与吊销状态消费；
- 一个供应商、一个同步文本模型的适配；
- 逻辑模型到供应商模型的显式映射；
- `/api/sand-ai` 下的版本化调用接口；
- 统一响应壳、错误码、超时和请求 ID；
- 用量事实表、调用日志和最小后台查询；
- 密钥加密保存、日志脱敏和基础限流；
- 契约测试与供应商适配测试。

### 6.3 明确不进 MVP

知识库、文件解析、向量库、智能体、工具调用、MCP、多模态、机器人渠道、模型自动
故障转移和 AI Workflow 均不进入首期。

## 7. 知识库如何成为跨项目能力

知识库不应挂在某个业务项目的内部表上，而应是 SandAI 的一级资源：

- `knowledge_base` 表示知识集合；
- `document` 表示原始文档和来源；
- `chunk` 表示可追溯切片；
- `index_version` 表示一次完整索引版本；
- SandAI 的发布关系表示知识库向哪个消费应用环境开放，以及该环境使用的稳定查询 code；SandIAM 仍负责应用身份、工作负载客户端和服务动作 grant。

每次检索从已验证的 SandIAM 上下文取得消费环境和客户端，先核对 SandAI 发布关系，再由 SandIAM 对已发布知识库版本执行 `sand_ai.knowledge.query` 正式授权，最后做向量/关键词检索。知识库创建环境是资料归属，不等于消费环境；不能用“与知识库同环境”代替跨应用发布。发布关系撤销、知识库停用、来源漂移或 SandIAM grant 撤销后均须在返回摘录前拒绝。向量元数据至少
包含知识库、文档、切片、索引版本和数据作用域，返回结果保留引用链。

知识库草稿与发布仅接受具有当前可信上传归属的文件；所有来源须属于同一组织、应用、环境和数据分级，可由该应用的不同工作负载客户端上传。旧文件缺归属、跨应用拼接或混合分级均拒绝，不能猜默认分级。数据分级随已发布版本冻结，查询时还须核对来源的当前归属与版本快照。SandAI 发布关系只描述内容开放范围，不存服务凭证、额度或 SandIAM grant；消费环境所属组织和应用由 SandIAM 官方环境引用验证。

更新采用“新索引版本构建完成后原子切换”，避免业务项目读到半成品。业务项目只依赖
稳定的检索/问答 API，不直接访问向量库、对象存储或 SandAI 数据库。

## 8. 业务项目接入

推荐接入协议：

1. 在 SandIAM 管理面登记客户主体、应用和环境；
2. 由 SandIAM 为应用环境创建工作负载客户端，并授予有明确动作、audience 和有效期的 SandAI 服务权限；
3. 业务项目通过 HTTPS 调用 `/api/sand-ai/v1/...`；
4. 请求携带 API Key、请求 ID 和可选幂等键；
5. SandAI 返回统一响应壳和可追踪的调用 ID；
6. 长任务返回任务 ID，由业务项目查询或接收签名回调。

不共享数据库，不把供应商密钥下发给业务项目。业务项目不需要自行安装或复制 SandAdmin/
SandIAM 插件；需要使用统一应用身份与权限时，接入平台已经安装的一套 SandIAM，且不复用
SandAdmin 后台账号。
高频 PHP 调用方可在协议稳定后提供薄 SDK，但 SDK 只封装签名、重试和 DTO，不包含
领域决策。

## 9. Neuron AI 适配评估

### 9.1 已核实事实

2026-10-03 核对官方发布 [4.0.2](https://github.com/neuron-core/neuron-ai/releases/tag/4.0.2)，固定提交
[`cd0d3e48451a234de31d6fc64cc632e3f1fa3b91`](https://github.com/neuron-core/neuron-ai/tree/cd0d3e48451a234de31d6fc64cc632e3f1fa3b91)。版本与归档证据见本任务 `.acceptance/neuron4-integration-assessment-20261003/evidence/upstream-version.json`。

- Composer 包为 `neuron-core/neuron-ai`，MIT，运行依赖为 PHP `^8.1`、`ext-curl` 与 `psr/event-dispatcher ^1.0`。
- Guzzle、PSR HTTP Message、Amp 等列在 `require-dev`；Inspector 不再是直接运行依赖。此前按 v3 的 Guzzle/Inspector 依赖排除理由已过时，AGF 未发现 LICENSE 的情况不适用于 Neuron。
- 官方源码包含 Agent、Provider、工具、MCP、结构化输出、RAG、数据加载、向量库、聊天历史、事件及 Workflow 能力。
- OpenAI Provider 默认使用 `CurlHttpClient`，接收可注入的 `HttpClientInterface`；Guzzle/Amp 客户端是可选实现，采用时须另核实际依赖与运行语义。
- 本次尚未证明完整 Webman／普通 PHP／TypePHP 兼容性。未验证是待执行的适配门，不是拒绝复用的依据。

### 9.2 与 Webman 的适配判断

纯 Composer/PSR 设计使它“可以被 Webman 代码引用”，但这不等于生产兼容已验证。
主要风险是：

- Webman 默认非协程进程中，同步阻塞 HTTP 可能占用当前 worker；默认 Curl 与选用客户端的超时、常驻进程和吞吐须实测。
- Webman 可使用 Swoole、Swow 或 Fiber 协程，但
  [官方手册](https://webman.workerman.net/doc/zh-cn/coroutine/coroutine.html)
  明确说明 Fiber 遇阻塞 I/O 仍会阻塞进程，第三方库还涉及资源竞争和连接池问题。
- Neuron 的 Amp 并行执行器与 Workerman 事件循环如何共存，尚无本项目实测证据。
- Neuron 的 Generator 流式输出需要桥接为 Webman SSE，并验证客户端断开、背压、
  超时、worker 回收和异常清理。
- Neuron 4 的 Agent/RAG/Workflow 运行语义应按固定 4.0.2 源码验证，不能沿用 v3 评估结果。
- 事件与可观测集成须保留提示脱敏和出站边界；4.0.2 不强制 Inspector，不能把旧依赖风险当作当前拒绝理由。

### 9.3 采用建议

采用“内部端口 + 可替换适配器”，不让业务代码直接继承 Neuron 类：

```text
SandAi\Domain\Ai\AgentRunner
SandAi\Domain\Ai\ModelGateway
SandAi\Domain\Knowledge\Retriever
             │
             ├── Neuron*Adapter（验证通过后）
             └── DirectProviderAdapter（最小替代）
```

通用模型调用与相关能力优先复用 Neuron 4，并以 SandAI 适配保留现有 SandIAM 身份、预算、派发、计量、加密回执、来源校验和持久状态。当前先在材料结构化调用的受控 send/response 边界验证 SDK，避免重建通用 AI 能力；不得使用框架自动重试绕过已付费派发保护。此处尚未接入运行，候选、依赖解析与真实材料服务调用分别留证。

### 9.4 必须完成的验证

- 在本项目 PHP/Webman 版本上完成 Composer 依赖求解；
- 同步调用、超时、异常映射和内存增长测试；
- 1/10/50 并发下的阻塞基线；
- SSE 首包时间、断线取消和长连接稳定性测试；
- Worker 常驻下 Agent/Provider 对象复用与请求状态污染测试；
- 协程关闭、Swoole/Swow（若采用）两种部署对比；
- Amp AsyncExecutor 单独试验，不与业务审批流程混用；
- RAG 的切片、向量库和索引一致性验证；
- 实际接入的事件监听器及可观测输出验证脱敏、出站与生命周期边界。

验证未完成前，文档和代码都不得宣称“Neuron 已适配 Webman”。

### 9.5 替代边界

- 多供应商统一调用优先验证 Neuron 4 现成 Provider，并接入 SandAI 受控 HTTP 适配；内部接口继续隔离平台身份与计量。
- 需要完整 Agent/RAG/工具/MCP：优先验证 Neuron。
- Symfony AI 可作为备选组件集合，但其官方仓库当前仍标注 experimental，不受向后
  兼容承诺保护，不能因为 Symfony 品牌而跳过验证。
- Prism 面向 Laravel，除非未来技术栈改变，不作为 Webman 的首选。

## 10. 对 Dux AI 的借鉴与舍弃

本次参考 Dux AI 官方仓库提交
[`2fd1051`](https://github.com/duxweb/dux-ai/tree/2fd10510155b7b01fa6da7664cfe731746d95f89)
及其[公开说明](https://github.com/duxweb/dux-ai)。

已核实 Dux AI 当前要求 PHP `^8.4`，依赖 `neuron-core/neuron-ai:^3.0`。其公开代码将：

- `Ai` 模块用于 Provider、Model、Agent、RAG、解析、向量、能力和异步调度；
- `Boot` 模块用于钉钉、飞书、QQ、企业微信等机器人驱动；
- `System` 模块用于权限、文件、队列、调度、日志和后台基础设施；
- `Data` 模块用于独立 API/数据能力；
- Provider、解析器、向量库、Toolkit 和 Bot 都采用注册/驱动边界；
- 文档解析、图片/视频和等待任务进入队列/调度，而不是阻塞后台请求。

值得借鉴：

- 智能体、机器人渠道、知识库、文件解析、异步任务、API 和后台管理分模块；
- 以驱动注册隔离供应商差异；
- 把耗时解析/生成任务异步化；
- 把会话、消息、用量、调用日志作为可追溯资源；
- 管理面、Web/API 入口和核心 Service 分开。

不直接照搬：

- Dux AI 是一体化平台，SandAI 要保持运行面可脱离管理插件；
- Dux AI 的 PHP 8.4、Dux Lite、Symfony Messenger 等依赖不适用于当前 Webman 基线；
- Dux 的 Flow/Workflow 模块不进入 SandAI，避免与现有独立流程引擎职责重叠；
- 不复制其数据表和类结构，只借鉴职责拆分和驱动模式。

## 11. 分期路线

### Phase 0：框架与验证（当前）

- Webman + SandAdmin 基线；
- `/api/sand-ai/health`；
- `sand-ai` 管理插件骨架；
- 命名、依赖方向和安全边界；
- Neuron/Webman 技术试验计划。

退出条件：后端能启动、健康检查通过、前端能构建、无流程引擎代码。

### Phase 1：模型网关 MVP

- SandIAM Access 集成、Provider、Model、Gateway、Usage、AuditLog；
- 单供应商单模型；
- 鉴权、限流、请求 ID、超时、脱敏与用量闭环；
- 最小管理页面和权限。

退出条件：一个真实业务项目通过统一 API 稳定调用并完成用量核对。

### Phase 2：文件、AI 任务与示例项目材料整理

- File、AsyncTask、Artifact 与可信回调；
- 对象存储、文件校验、解析/OCR 和来源定位；
- 任务进度、取消、重试、部分成功和结构化成果；
- 首个任务模板 `legal.case-material-organize@1`；
- 先支持少量明确文件格式，不追求全格式；
- 只使用脱敏案件材料完成示例项目受控试点。

退出条件：示例项目可完成材料整理端到端试点，已保存事实均可回到来源，未授权文件进入
SandAI 的事件为 0。

### Phase 3：跨项目知识库

- Knowledge、Retrieval；
- 文档、切片、向量化和版本化索引；
- 按应用授权、引用追溯、重建与回滚；
- 支持关键词、向量、重排和来源引用。

退出条件：两个业务项目复用同一知识库且权限隔离、索引切换和引用可验证。

### Phase 4：智能体与渠道

- Agent、Tool、MCP（经安全白名单）、会话与消息；
- Channel 驱动和 webhook 安全；
- 工具审批只处理 AI 危险动作确认，不承担企业业务审批。

退出条件：智能体版本可追溯、工具最小权限、渠道消息幂等且可审计。

### MCP 的定位（后续阶段）

MCP 是供 AI 客户端或 Agent 发现并调用受控工具的标准协议；它不是另一套业务后端，
也不替代 SandAI 对业务项目提供的 HTTP API。

| 调用关系 | 使用方式 | 谁选择具体动作 |
| --- | --- | --- |
| 示例项目 → SandAI | 调用稳定的 `/api/sand-ai` 业务 API | 示例项目页面或后端代码预先确定 |
| SandAI Agent → 工具 | 通过 MCP（或 SandAI 内置 Capability）调用工具 | Agent 根据用户问题，在已授权工具中选择 |

MCP Server 的工具通常仍会调用既有的业务应用服务或 HTTP API。例如，示例项目可以先有
“按案件权限检索材料”的内部能力；后续再将其包装为 `search_case_materials` MCP 工具，
使 Agent 能在回答“这笔借款有哪些证据”时按需调用。MCP 不会自动获得数据权限、
业务判断能力或写入权限。

SandAI 在这一阶段作为 MCP Client 与能力网关，示例项目仍只调用 SandAI API。优先考虑的
工具是“已授权法律检索”和“当前案件只读材料/已确认事实查询”；它们用于生成带来源的
待复核答案、证据缺口和文书草稿。案件状态变更、创建审批待办、盖章、发送、归档或
提交等业务写操作，不由 Agent 或 MCP 直接完成。

任何 MCP 接入必须满足以下约束：

- 只允许已登记的 Server、Tool 与版本，并有明确输入/输出 Schema、风险等级和健康检查；
- 每次调用携带应用、当前用户和案件/资源作用域，由示例项目或 SandAI 服务端重新鉴权；
- 初期工具只读；需要产生外部副作用时，必须返回待确认建议，并由示例项目页面显式确认；
- 禁止向模型暴露任意 SQL、任意 HTTP、任意文件系统访问或跨项目数据；
- 返回事实必须带来源定位；调用、授权判断、工具版本和结果摘要必须进入审计日志。

只有当一个 Agent 确实需要在多个工具之间自主选择，或需要接入已有的第三方 MCP
服务时，MCP 才比直接调用 SandAI 内部 Capability 更有价值。首期模型网关、文件任务
和示例项目材料整理均不以 MCP 为前置条件。

### Phase 5：规模化与拆分

先保持模块化单体；出现以下信号再拆服务：

- 模型长连接耗尽 Web worker，且独立扩容收益明确；
- 文档解析/向量化的 CPU、内存或系统依赖与 API 节点冲突；
- 知识索引需要独立发布、扩缩容或故障域；
- 渠道 webhook 的峰值、重试和可用性要求独立；
- 团队边界、发布频率和 SLA 已经不同；
- 有持续压测和成本数据证明拆分收益大于分布式复杂度。

优先拆 Worker/异步任务，再拆知识索引，最后才考虑拆模型网关。数据库是否拆分由实际
负载和数据所有权决定，不预先微服务化。

## 12. 待验证与决策清单

- SandAI 生产 PHP 小版本和 Webman 事件循环方案；
- SandAdmin 数据库初始化方式和部署拓扑；
- 供应商首选、模型协议和国内网络可用性；
- API Key 加密/哈希、密钥托管与轮换方案；
- 用量/费用以供应商返回还是本地估算为准；
- SSE、取消、重试和幂等语义；
- 对象存储、解析器、向量库与重排器选型；
- 知识库数据驻留、删除证明和敏感数据策略；
- 队列、失败重试、死信和任务可观测性；
- Neuron 版本锁定、Webman 兼容性和 Inspector 合规结论。

这些事项在验证前均不得写成既定能力。

## 13. 资料来源

- [SaiAdmin 6.x 快速安装](https://saithink.top/documents/v6/install.html)
- [SaiAdmin 6.x 后端说明](https://saithink.top/documents/v6/back/)
- [Webman 协程手册](https://webman.workerman.net/doc/zh-cn/coroutine/coroutine.html)
- [Neuron AI 官方仓库](https://github.com/neuron-core/neuron-ai)
- [Neuron AI 官方文档](https://docs.neuron-ai.dev/)
- [Dux AI 官方仓库](https://github.com/duxweb/dux-ai)
- [Dux AI 官方文档仓库](https://github.com/duxweb/dux-ai-docs)
- [Symfony AI Platform（experimental）](https://github.com/symfony/ai-platform)
