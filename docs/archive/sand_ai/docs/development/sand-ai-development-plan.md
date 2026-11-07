# SandAI 开发实施文档

> 文档状态：Ready for Development  
> 版本：1.0  
> 更新日期：2026-07-24  
> 开工日期：2026-07-27（周一）  
> 目标：把产品功能拆成可以编码、联调、测试和验收的开发任务。

## 1. 开发目标

第一条交付主线不是一次做完整 AI 平台，而是按以下顺序建立可复用底座：

1. 跑通“应用凭证 → 逻辑模型 → 供应商模型 → 用量与审计”的最小闭环；
2. 建立统一扩展协议和场景化配置机制；
3. 增加文件、异步 AI 任务、结构化成果和回调；
4. 用示例项目“案件材料智能整理”验证复杂任务底座；
5. 增加跨项目知识库；
6. 最后增加智能体、工具、MCP 和机器人渠道。

本文假设基础团队为 2 名后端、1 名前端、1 名测试，产品与示例项目代表参与验收。人数变化时
调整并行度，不改变任务依赖和验收标准。

## 2. 已确定的开发边界

### 2.1 必须遵守

- 后端服务目录：`server`；
- 运行面代码目录：`server/app/sandAi`；
- 运行面 PHP 命名空间：`app\SandAi`；
- 管理插件：`server/plugin/sand-ai`，命名空间 `plugin\SandAi`；
- 管理前端：`sandadmin-artd/src/views/plugin/sand-ai`；
- 对外 API 基础路径：`/api/sand-ai`；
- 配置键、表名主体和权限标识使用 `sand_ai`；
- 运行面不得引用 `plugin\SandAi` 或 SaiAdmin 控制器；
- 管理插件未安装时，已完成运行配置的 API 仍能启动；
- 不修改、不接入、不复制现有独立流程引擎；
- AI Task/Run 只表示 AI 执行，不使用“审批流”语义。

### 2.2 首期技术取舍

- 首个供应商协议采用 OpenAI Compatible，便于连接多个兼容服务；
- 测试必须提供本地 Fake Provider，不依赖真实厂商和余额；
- 首期只实现同步文本调用，流式输出排在第二个迭代；
- 业务代码只依赖内部接口，不直接依赖某个 AI 编排库；
- Neuron AI 验证作为独立技术任务，不进入首期关键路径；
- 配置扩展使用“元信息 + 配置 Schema + 健康检查 + 运行适配器”统一模型；
- 后台配置必须经历“草稿 → 测试 → 发布”，运行面只读取已发布版本；
- 敏感凭证加密保存，API Key 只保存不可逆摘要，明文只在签发时显示一次。

## 3. 总体结构

```text
业务项目
  └─ /api/sand-ai/v1/*
       └─ server/app/sandAi
            ├─ Api                 对外接口、鉴权、请求上下文
            ├─ Application         应用用例
            ├─ Domain              领域模型与端口
            └─ Infrastructure      数据库、供应商、存储、队列等适配

SandAdmin
  ├─ server/plugin/sand-ai         管理接口、菜单、权限
  └─ sandadmin-artd/.../sand-ai    管理页面
        └─ 调用运行面应用服务，不复制领域逻辑
```

依赖规则：

```text
Api / Admin Adapter
       ↓
Application Use Case
       ↓
Domain Port
       ↓
Infrastructure Adapter
```

禁止反向依赖，禁止 Controller 直接解析厂商响应或直接执行模型、解析、向量化任务。

## 4. 运行面模块

| 模块 | 责任 | 首期核心对象 |
| --- | --- | --- |
| Application | 业务项目、环境、状态、配额归属 | Application、Environment |
| Credential | API Key 签发、校验、轮换、吊销 | ApiCredential |
| Provider | 服务商配置、连接测试、适配器注册 | ProviderConnection |
| Model | 逻辑模型、物理模型、能力与发布版本 | LogicalModel、ModelDeployment |
| Gateway | 鉴权、幂等、调用、错误映射 | InvocationRequest、InvocationResult |
| Usage | token、请求、耗时和费用事实 | UsageRecord |
| Audit | 配置和调用追踪 | AuditEvent |
| Configuration | 草稿、检查、发布和回退 | ConfigRevision、ValidationResult |
| File | 文件登记、解析、来源块和保留 | FileAsset、ParsedArtifact |
| Task | AI 任务、步骤、尝试、取消和重试 | TaskRun、TaskStep |
| Artifact | 结构化成果、Schema 和过期 | Artifact |
| Knowledge | 知识库、文档、索引和授权 | KnowledgeBase、Grant、IndexVersion |
| Retrieval | 检索、过滤、重排和引用 | RetrievalQuery、Citation |
| Agent | 智能体、版本、会话 | Agent、AgentRevision |
| Capability | 工具、MCP 和业务能力 | CapabilityDefinition |
| Channel | 机器人、入站消息和出站消息 | ChannelConnection |

## 5. 推荐代码目录

```text
server/app/sandAi/
├── Api/
│   ├── Controller/
│   ├── Middleware/
│   ├── Request/
│   └── Response/
├── Application/
│   ├── Application/
│   ├── Credential/
│   ├── Provider/
│   ├── Model/
│   ├── Gateway/
│   ├── Usage/
│   ├── File/
│   ├── Task/
│   └── Knowledge/
├── Domain/
│   ├── Application/
│   ├── Credential/
│   ├── Provider/
│   ├── Model/
│   ├── Gateway/
│   ├── File/
│   ├── Task/
│   ├── Knowledge/
│   └── Shared/
└── Infrastructure/
    ├── Persistence/
    ├── Provider/
    ├── Security/
    ├── Storage/
    ├── Queue/
    └── Observability/
```

SaiAdmin 管理插件按功能组织：

```text
server/plugin/sand-ai/app/
├── admin/controller/
├── admin/logic/
├── model/
└── validate/

sandadmin-artd/src/views/plugin/sand-ai/
├── api/
├── dashboard/
├── application/
├── provider/
├── model/
├── setup/
├── usage/
├── audit/
├── file/
├── task/
└── knowledge/
```

## 6. 数据库实施

所有表使用 `sand_ai_` 前缀，包含 SaiAdmin 标准主键、状态、创建人、更新人、创建时间、
更新时间和软删除字段。运行面数据初始化必须有独立入口，不能只依赖管理插件安装动作。

### 6.1 第一阶段表

| 表 | 关键字段 | 约束 |
| --- | --- | --- |
| `sand_ai_application` | code、name、description、quota_policy | code 唯一 |
| `sand_ai_environment` | application_id、code、name | application_id + code 唯一 |
| `sand_ai_api_credential` | environment_id、key_prefix、secret_hash、scopes、expires_at、revoked_at | 只存摘要 |
| `sand_ai_provider` | code、name、adapter、encrypted_config、timeout_ms | code 唯一 |
| `sand_ai_model` | code、name、type、capabilities | code 唯一 |
| `sand_ai_model_deployment` | model_id、provider_id、remote_model、config、revision | 唯一发布版本 |
| `sand_ai_application_model` | environment_id、model_id、quota、status | 环境授权 |
| `sand_ai_config_revision` | resource_type、resource_id、revision、payload、state、published_at | draft/tested/published |
| `sand_ai_invocation` | request_id、environment_id、model_id、deployment_id、state、latency_ms、error_code | request_id 唯一 |
| `sand_ai_usage` | invocation_id、input_tokens、output_tokens、total_tokens、cost_amount、currency、source | invocation_id 索引 |
| `sand_ai_audit_log` | actor_type、actor_ref、action、resource_type、resource_id、summary | 不存敏感原文 |

数据库约束：

- 删除 Provider 或 Model 前必须检查引用；
- 已发布配置不可原地修改，只能生成新 revision；
- Invocation 不保存完整 API Key、供应商密钥和默认完整 Prompt；
- `request_id`、应用、模型、时间、状态必须建查询索引；
- JSON 字段进入代码后必须转换为明确 DTO，不在业务层传播松散数组。

### 6.2 第二阶段表

| 表 | 用途 |
| --- | --- |
| `sand_ai_file` | 文件元数据、哈希、存储引用、状态和保留策略 |
| `sand_ai_file_parse` | 解析尝试、解析器、状态、错误和结果引用 |
| `sand_ai_source_block` | 页码、段落、文本块和定位信息 |
| `sand_ai_task_template` | AI 任务模板及版本 |
| `sand_ai_task_run` | 一次任务运行和固定输入快照 |
| `sand_ai_task_step` | 阶段、尝试、耗时、状态和错误 |
| `sand_ai_artifact` | 结构化成果、Schema、版本和过期时间 |
| `sand_ai_callback_channel` | 可信回调配置 |
| `sand_ai_callback_delivery` | 回调投递、重试和响应摘要 |

### 6.3 第三阶段表

| 表 | 用途 |
| --- | --- |
| `sand_ai_knowledge_base` | 知识库基本信息 |
| `sand_ai_knowledge_grant` | 应用对知识库的授权 |
| `sand_ai_document` | 文档与来源 |
| `sand_ai_chunk` | 可追溯切片 |
| `sand_ai_index_version` | 索引版本、构建状态和当前指针 |
| `sand_ai_retrieval_log` | 检索条件、命中和耗时摘要 |

## 7. 对外 API 契约

### 7.1 通用请求头

| 请求头 | 要求 |
| --- | --- |
| `Authorization: Bearer <key>` | 应用凭证 |
| `X-Request-Id` | 调用方请求编号；缺省时由 SandAI 生成 |
| `Idempotency-Key` | 创建资源或长任务时必填 |
| `Content-Type` | JSON 或明确的上传类型 |

统一响应：

```json
{
  "code": 200,
  "message": "success",
  "data": {},
  "request_id": "req_xxx"
}
```

统一错误至少包含：

- 稳定业务错误码；
- 可公开的错误说明；
- `request_id`；
- 是否可重试；
- 不包含供应商密钥、内部 Prompt、堆栈和原始敏感响应。

### 7.2 第一阶段 API

| 方法与路径 | 用途 | 验收重点 |
| --- | --- | --- |
| `GET /api/sand-ai/health` | 服务健康检查 | 无管理插件依赖 |
| `GET /api/sand-ai/v1/models` | 查询当前应用可用逻辑模型 | 只返回已授权模型 |
| `POST /api/sand-ai/v1/chat/completions` | 文本调用 | 鉴权、幂等、超时、用量 |
| `GET /api/sand-ai/v1/invocations/{requestId}` | 查询调用摘要 | 只能查本应用 |

聊天请求首期支持：

```json
{
  "model": "general-chat",
  "messages": [
    {"role": "user", "content": "你好"}
  ],
  "response_format": {
    "type": "text"
  }
}
```

首期暂不支持任意 Provider 参数透传。需要开放的参数必须进入统一白名单。

### 7.3 第二阶段 API

| 方法与路径 | 用途 |
| --- | --- |
| `POST /api/sand-ai/v1/files` | 创建上传或登记文件 |
| `GET /api/sand-ai/v1/files/{fileId}` | 查询文件和解析状态 |
| `DELETE /api/sand-ai/v1/files/{fileId}` | 请求受控删除 |
| `POST /api/sand-ai/v1/task-runs` | 创建 AI 任务运行 |
| `GET /api/sand-ai/v1/task-runs/{runId}` | 查询运行状态和阶段 |
| `POST /api/sand-ai/v1/task-runs/{runId}/cancel` | 请求取消 |
| `POST /api/sand-ai/v1/task-runs/{runId}/retry` | 重试失败步骤或文件 |
| `GET /api/sand-ai/v1/artifacts/{artifactId}` | 获取结构化成果 |

使用 `task-runs`，不使用审批或业务流程接口命名。

任务状态：

```text
queued
running
review_required
succeeded
partially_succeeded
failed
cancel_requested
canceled
expired
```

`review_required` 表示 AI 成果等待业务用户校对，不表示审批。

### 7.4 示例项目材料整理模板

模板标识：

```text
legal.case-material-organize@1
```

输入最少包含：

- 不透明业务作用域；
- 文件选择快照；
- 文件 ID 列表；
- 材料分类方案；
- 输出语言；
- 可选知识库授权；
- 已登记回调通道。

输出 Schema：

```text
case-material-package.v1
```

成果必须包含：

- documents；
- entities；
- timeline_events；
- conflicts；
- items_to_verify；
- warnings；
- partial_failures；
- 每个事实字段的 citations。

### 7.5 回调

回调事件至少包含：

- event_id；
- event_type；
- occurred_at；
- application_id；
- run_id；
- attempt；
- state；
- stage；
- artifact_id 或结构化错误；
- 签名、时间戳和 nonce。

回调地址只能从应用已登记通道中选择，不接受请求临时传入任意 URL。

## 8. 管理 API 与权限

管理接口统一位于：

```text
/app/sand-ai/admin/...
```

权限标识统一使用：

```text
sand_ai:<module>:<action>
```

首期菜单：

```text
SandAI
├── 总览
├── 快速配置
├── 应用管理
├── 服务商
├── 模型管理
├── 调用记录
├── 用量统计
└── 审计日志
```

第二阶段增加：

```text
├── 文件中心
├── AI 任务
├── 任务模板
└── 回调记录
```

第三阶段增加：

```text
├── 知识库
├── 文档与索引
├── 智能体
├── 能力中心
└── 渠道管理
```

所有新增、修改、读取、删除、测试、发布、回退、签发、轮换和吊销动作必须有独立权限。

## 9. 扩展协议

### 9.1 扩展类型

运行面定义以下端口，具体实现通过注册表接入：

| 端口 | 最少能力 |
| --- | --- |
| ProviderAdapter | 元信息、配置校验、连接测试、文本调用、错误映射 |
| ModelCatalogProvider | 拉取或校验模型目录与能力 |
| ParseDriver | 支持格式、配置校验、解析、来源定位 |
| StorageDriver | 上传、读取、短期访问、删除 |
| VectorStoreDriver | 建索引、写入、删除、检索、健康检查 |
| RerankerDriver | 重排和分数解释 |
| CapabilityDriver | 输入 Schema、输出 Schema、执行和风险级别 |
| ChannelDriver | 回调校验、消息解析、发送、媒体能力 |
| TaskTemplate | 输入 Schema、步骤、输出 Schema、版本和失败策略 |
| SetupRecipe | 场景向导步骤、依赖、检查、测试和发布 |

### 9.2 统一扩展元信息

每个扩展必须声明：

- 唯一 code 和版本；
- 名称、说明和分类；
- 支持能力；
- 配置字段、默认值和帮助文本；
- 敏感字段；
- 依赖和兼容范围；
- 健康检查；
- 测试样例；
- 是否可用于生产；
- 升级和回退说明。

后台根据元信息生成表单，不要求每个扩展手写一套管理页。

### 9.3 扩展验收

- 未配置时能说明缺什么；
- 错误配置不能发布；
- 健康检查有明确超时；
- 厂商错误转换成统一错误；
- 敏感字段不回显、不写日志；
- 扩展停用前检查引用；
- 扩展升级不静默改变在途调用；
- 至少有 Fake 或契约测试。

## 10. 快速配置中心实施

### 10.1 内置 Recipe

按顺序实现：

1. `model-api`：应用 + Provider + Model + Credential + 调用测试；
2. `legal-material-organize`：应用 + 文件 + 解析 + Task Template + 回调；
3. `knowledge-qa`：应用 + Embedding + Vector + Parser + Knowledge + 测试；
4. `agent-chat`：应用 + Agent + Model + 可选 Knowledge/Capability；
5. `channel-bot`：Agent + Channel + 回调验证 + 测试消息。

### 10.2 Recipe 状态

```text
editing → validating → test_passed → published
                  └── test_failed
published → superseded
```

### 10.3 首期页面

- 选择场景；
- 选择或创建应用环境；
- 填写 Provider 凭证；
- 自动获取或填写模型；
- 自动测试连接；
- 签发应用凭证；
- 展示可复制的调用示例；
- 发布前检查；
- 完成页展示“已可用”和后续入口。

### 10.4 关键校验

- Provider 连接成功；
- Model 可调用；
- Application 已授权 Model；
- Credential 有正确 scope 和有效期；
- 配额存在；
- 调用测试成功并生成 Invocation/Usage；
- 所有敏感字段已加密或摘要处理；
- 发布版本可被运行面读取。

## 11. 开发任务清单

### 11.1 第 1 周：2026-07-27 ～ 2026-07-31

目标：完成第一条可演示的“配置一个模型并通过 SandAI 调用”纵向链路。

| ID | 任务 | 角色 | 依赖 | 完成标准 |
| --- | --- | --- | --- | --- |
| SAND-001 | 确认首个真实 Provider、测试模型和测试账号 | 产品/后端 | 无 | 真实测试参数可用；未确定时用 Fake 开工 |
| SAND-002 | 确认数据库、密钥主密钥和环境配置方式 | 后端/运维 | 无 | 开发环境能安全加载配置 |
| SAND-003 | 建立运行面分层目录、公共 DTO、错误码和请求上下文 | 后端 A | 无 | 健康接口回归通过 |
| SAND-004 | 建立第一阶段数据库迁移与仓储 | 后端 A | SAND-002 | 表、索引、约束可重复初始化 |
| SAND-005 | 实现 Application/Environment 管理用例 | 后端 A | SAND-004 | 增删改查、启停和唯一性测试通过 |
| SAND-006 | 实现 Credential 签发、校验、轮换、吊销 | 后端 A | SAND-004 | 明文只返回一次，旧凭证按规则失效 |
| SAND-007 | 定义 ProviderAdapter 与 Fake Provider | 后端 B | SAND-003 | 成功、超时、限流、厂商失败可测试 |
| SAND-008 | 实现 OpenAI Compatible 文本适配器 | 后端 B | SAND-007 | 真实或受控测试调用通过 |
| SAND-009 | 实现 Provider/Model/Deployment 管理用例 | 后端 B | SAND-004、007 | 支持草稿、测试和发布 |
| SAND-010 | 实现鉴权中间件和应用上下文 | 后端 A | SAND-006 | 禁用、过期、错误 scope 被拒绝 |
| SAND-011 | 实现 `/v1/models` 和同步 `/v1/chat/completions` | 后端 B | SAND-008～010 | 统一响应、request_id、超时和错误映射 |
| SAND-012 | 记录 Invocation、Usage 和 Audit | 后端 A | SAND-011 | 成功失败均可追踪且无敏感明文 |
| SAND-013 | 建立管理菜单、权限和后端 Controller/Logic | 后端 A | SAND-005、009 | 管理接口符合 SaiAdmin 规范 |
| SAND-014 | 完成应用、Provider、Model 基础管理页 | 前端 | SAND-013 | 类型检查和构建通过 |
| SAND-015 | 完成 `model-api` 配置向导最小页面 | 前端 | SAND-009、013 | 能创建、测试、发布并展示调用示例 |
| SAND-016 | 建立 API/Provider 契约测试 | 测试/后端 B | SAND-007、011 | Fake 与真实适配器使用同一测试集 |
| SAND-017 | 完成首周安全检查与端到端演示 | 全员 | SAND-001～016 | 从空配置到成功调用并查到用量 |

首周每日建议：

- 周一：SAND-001～004、007，冻结接口和迁移；
- 周二：SAND-005、006、008、009；
- 周三：SAND-010～012，跑通 Fake Provider；
- 周四：SAND-013～016，接管理页面和真实 Provider；
- 周五：缺陷修复、安全检查、演示和下周评审。

若团队只有 1 名后端，首周保留 SAND-003、004、006～012 和最小配置页面，其余管理 CRUD
顺延，不降低鉴权、密钥和测试要求。

### 11.2 第 2 周：统一模型服务完成

| ID | 任务 | 完成标准 |
| --- | --- | --- |
| SAND-018 | 配置 revision、发布和回退 | 运行面只读取 published |
| SAND-019 | 请求幂等与基础限流 | 重复请求不重复计费 |
| SAND-020 | 流式输出 | 首包、断开、超时和错误测试通过 |
| SAND-021 | 用量与费用规则 | 可区分厂商返回和本地估算 |
| SAND-022 | 总览、调用记录、用量和审计页面 | 可按应用/模型/时间检索 |
| SAND-023 | `model-api` 向导完善 | 空环境 5 分钟内跑通 |
| SAND-024 | 第一个业务项目联调 | 通过 SandAI 调用且不持有厂商密钥 |

第一阶段退出条件：

- 一个真实业务项目稳定调用；
- 成功与失败均有 request_id；
- 用量可与厂商记录抽样核对；
- 禁用应用、吊销凭证、停用模型立即生效；
- 管理插件停止不影响已发布运行配置。

### 11.3 第 3～4 周：文件与 AI 任务

| ID | 任务 | 完成标准 |
| --- | --- | --- |
| SAND-101 | StorageDriver 和本地/OSS 私有兼容实现 | 私有文件可上传、读取、删除；生产使用 OSS 私有桶 |
| SAND-102 | File API 与格式/大小/哈希校验 | 非法文件被拒绝；文件只属于单一环境且不暴露对象存储信息 |
| SAND-103 | ParseDriver 和 PDF/DOCX/图片首批解析 | 保留页码或文本块定位 |
| SAND-104 | 队列、TaskRun、TaskStep 和 Worker | 重启后状态可恢复 |
| SAND-105 | 取消、重试、部分成功 | 可只重试失败文件 |
| SAND-106 | Artifact 与 Schema 校验 | 非法输出不能标记成功 |
| SAND-107 | 回调签名、投递、重试和对账 | 重复回调可幂等消费 |
| SAND-108 | 文件、任务、成果和回调管理页 | 支持排障 |
| SAND-109 | 文件/任务/回调集成测试 | 异常链路完整覆盖 |

### 11.4 第 5～6 周：示例项目材料整理试点

| ID | 任务 | 完成标准 |
| --- | --- | --- |
| SAND-201 | 定义 `case-material-package.v1` | 示例项目与 SandAI 共同确认 |
| SAND-202 | 材料分类步骤 | 分类、置信度、引用 |
| SAND-203 | 实体与事件抽取步骤 | 不确定值返回 null + 原因 |
| SAND-204 | 归并、冲突和疑似重复 | 不自动裁决冲突 |
| SAND-205 | 时间线和待核实清单 | 每项有来源 |
| SAND-206 | 示例项目任务创建、查询、取消和回调联调 | 页面关闭后任务继续 |
| SAND-207 | 脱敏样本评测集 | 覆盖正常、扫描、损坏和冲突材料 |
| SAND-208 | 受控试点验收 | 100% 已保存事实可回到来源 |

### 11.5 第 7～8 周：跨项目知识库

| ID | 任务 | 完成标准 |
| --- | --- | --- |
| SAND-301 | Knowledge/Document/Grant | 应用授权隔离 |
| SAND-302 | Chunk 与 IndexVersion | 新索引完成后原子切换 |
| SAND-303 | VectorStoreDriver 与首个正式驱动 | 维度校验、健康检查 |
| SAND-304 | Embedding 与批量索引任务 | 可重试、可恢复 |
| SAND-305 | 检索、过滤、重排和 Citation | 结果可回到来源 |
| SAND-306 | 知识库后台与测试台 | 10 分钟内完成小文档问答 |
| SAND-307 | 两业务项目复用验收 | 权限隔离和引用通过 |

### 11.6 后续：智能体、能力与渠道

顺序：

1. Agent 与版本；
2. 会话与消息；
3. CapabilityDriver；
4. HTTP/Function/Knowledge 内置能力；
5. MCP 白名单；
6. ChannelDriver；
7. 企业微信、飞书、钉钉；
8. 场景套餐导入导出。

不在前八周承诺完整多智能体、电脑控制、图片/视频生成和自由画布式编排。

## 12. 测试计划

### 12.1 单元测试

- API Key 摘要校验、过期、轮换和吊销；
- 配置 revision 状态转换；
- Provider 错误映射；
- 用量计算；
- 幂等键；
- Task 状态转换；
- 引用与输出 Schema 校验；
- 应用和知识库授权。

### 12.2 契约测试

- ProviderAdapter 统一测试集；
- ParseDriver 统一测试集；
- VectorStoreDriver 统一测试集；
- ChannelDriver 统一测试集；
- 回调签名与重复/乱序投递；
- 示例项目成果 Schema。

### 12.3 集成测试

- 应用创建、凭证签发、模型授权和调用；
- 模型停用、凭证吊销和配额耗尽；
- 文件上传、解析、任务、成果和回调；
- 部分文件失败和失败项重试；
- 知识库授权、索引切换和跨应用拒绝；
- 管理插件停止后运行 API 继续可用。

### 12.4 前端验收

- TypeScript 不使用 `any` 逃逸、`@ts-ignore` 或 `@ts-nocheck`；
- 所有敏感字段只显示掩码；
- 超过三个搜索项时使用展开区域；
- 所有按钮权限与后端权限一致；
- 配置向导可中断恢复；
- 每个错误都给出影响和修复入口；
- 构建和基础页面流程通过。

### 12.5 性能与稳定性

- 1/10/50 并发文本调用基线；
- 超时、客户端断开和 Worker 常驻内存；
- 流式首包时间和断开清理；
- 任务 Worker 重启恢复；
- 大批文件的队列积压和限速；
- 日志和用量写入不得明显拖慢主调用。

## 13. Definition of Done

一个任务只有同时满足以下条件才算完成：

- 功能符合产品文档；
- 权限、输入校验和错误码完整；
- 敏感数据不进入返回和普通日志；
- 数据迁移可重复执行；
- 单元或契约测试覆盖关键分支；
- 管理页面权限与后端一致；
- 后端 `composer check` 通过；
- 前端类型检查和构建通过；
- 接口或配置文档同步；
- 有可复现的验收步骤；
- 未修改或接入独立流程引擎。

## 14. 7 月 27 日开工前的确认项

周一上午确认，未及时确认时按默认方案继续，不阻塞骨架开发：

| 决策 | 首选 | 未确认时默认 |
| --- | --- | --- |
| 首个真实模型平台 | 由业务提供可用账号 | Fake + OpenAI Compatible |
| 首个逻辑模型名 | `general-chat` | `general-chat` |
| 数据库环境 | 现有 MySQL 8 | 本地开发库 |
| 主密钥管理 | 环境密钥/密钥服务 | 开发环境变量，禁止提交 |
| 用量来源 | 厂商返回优先 | 厂商返回，缺失时标记估算 |
| 首批示例项目样本 | 脱敏 PDF/DOCX/图片 | 先造测试样本 |
| 队列方案 | 完成技术验证后确定 | 先定义端口，不影响第一阶段 |
| 对象存储 | S3 兼容 | 开发环境本地私有存储 |

必须由人确认、不得自动采用默认值的事项：

- 真实客户数据是否允许进入某个模型供应商；
- 数据驻留、保留期限和删除证明；
- 法源授权；
- 生产密钥托管方式；
- 示例项目试点案件和可接受质量阈值。

## 15. 风险与控制

| 风险 | 控制 |
| --- | --- |
| Neuron 与 Webman 兼容性未验证 | 不进入首期关键路径，单独试验 |
| 同步模型调用阻塞 Worker | 建立并发基线，必要时拆独立调用 Worker |
| 配置很多但实际不可用 | 强制健康检查、试运行和发布前体检 |
| 厂商差异渗入业务代码 | ProviderAdapter + 契约测试 |
| AI 任务与审批流混淆 | 使用 TaskRun 术语，禁止流程引擎依赖 |
| 文件包含敏感信息或恶意指令 | 受控上传、隔离解析、最小权限和工具白名单 |
| AI 输出无来源 | 事实字段 Citation 强校验 |
| 跨项目数据泄露 | 应用上下文、授权过滤和隔离测试 |
| 在途任务受配置升级影响 | 固定配置、模板、模型和 Schema 版本 |
| 范围过大导致无法交付 | 每阶段只以纵向可验收链路退出 |

## 16. 开工结论

2026-07-27 可以直接开始 SAND-001～017。首周不依赖 Neuron、知识库、流程引擎或示例项目
真实代码即可完成统一模型服务的第一条纵向链路；从第二阶段开始再接入文件、AI Task
和示例项目脱敏样本。
