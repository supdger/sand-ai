# SandAI

> **状态：测试中。** 最新公开发行版是 [v0.3.0](https://github.com/supdger/sand-ai/releases/tag/v0.3.0)（2026-10-30 发布，资产 `sand-ai-0.3.0.zip`），仓内两份 `info.ini` 的源码版本同为 `0.3.0`；此前另有正式发行 [v0.2.42](https://github.com/supdger/sand-ai/releases/tag/v0.2.42) 与预发行 [0.1.0 预览](https://github.com/supdger/sand-ai/releases/tag/sand-ai-v0.1.0-preview-2821503)（旧的仅插件目录结构）。公开发行包可下载，本仓 `0.3.0` 源码候选已进入测试阶段，真实宿主端到端安装与业务验收正在进行；阅读口径见[当前状态与验收边界](#当前状态与验收边界)。

SandAI 是面向 SandAdmin 的 AI 能力插件，为业务应用提供统一的 AI API。管理员在后台配置模型服务、能力路由、私有文件、知识库和任务；接入开发者通过 API 调用聊天、材料整理、知识问答、智能体和音频处理，并查看来源与费用。

SandAI 使用 [Neuron AI](https://github.com/neuron-core/neuron-ai) 作为 AI 开发框架。当前安装包在 [config.json](config.json) 中声明最低依赖 `neuron-core/neuron-ai >=4.0.2`，不设置版本上限；实际安装版本由宿主 Composer 锁文件记录。源码已接入其模型 Provider、Agent 与工具调用、RAG 检索与文本分块、Workflow、结构化输出以及语音接口；具体模型和能力仍须按服务商支持范围配置、验证后启用。

Neuron AI 提供通用 AI 编排能力；SandAI 负责模型与环境配置、任务持久化和恢复、私有来源校验、用量及费用记录。身份、应用、工作负载凭证和服务授权由 SandIAM 提供，SandAI 消费其授权并在执行前重验。业务审批使用独立的 SandWorkflow 插件；Neuron Workflow 用于 AI 处理节点编排。

SandAI 通过一个 SandPackage ZIP 安装，包含 PHP/Webman 后端、Vue 管理端与 PostgreSQL 生命周期文件。`plugin/sand-ai/` 承载后台配置，`app/` 承载对外 API 和共享服务，随同一安装包交付。

## 当前状态与验收边界

本文档描述的是源码当前实现的行为。全文按下表区分交付阶段，各节不再逐段重复同类免责说明。

| 阶段 | 含义 |
| --- | --- |
| 源码候选 | 代码与文档已落地，未构包、未安装、未运行 |
| 已构包 | 已生成安装 ZIP 并记录摘要，未安装 |
| 已安装 | 已通过 SandPackage 正常流程安装或升级并加载，未做业务验证 |
| 已业务验收 | 已在真实宿主用真实凭据和真实数据跑通，并核对结果、计量与费用 |

当前整体状态：

- 已公开发行：`v0.3.0`（最新）、`v0.2.42`，以及 `0.1.0` 预发行预览。
- 测试中：本仓 `0.3.0` 源码候选的重新构包、真实宿主安装与端到端业务验收；法律能力阶段整体未完成。
- 后台保存成功、SDK 接入成功、安装成功、迁移执行成功彼此独立，任一项都不代表对应业务链已验收。
- 未知的调用结果、回执和费用一律保持「未知」，不补造成功、不填零、不自动重发；恢复只通过原业务幂等键只读找回。
- 公开发行与下载见 [GitHub Releases](https://github.com/supdger/sand-ai/releases)；逐版本变化见[完整更新日志](plugin/sand-ai/CHANGELOG.md)。

全文约定：

- `NNN` 形式的三位编号指插件自带的 PostgreSQL 版本化迁移序号，文件在 `plugin/sand-ai/migrations/main-app/`，由 SandPackage 的正常升级流程按序执行；不手工 DDL，也不在运行时自动迁移。
- 文档正文的唯一维护副本在 `plugin/sand-ai/docs/` 下；其中 `docs/development/` 与 `docs/product/` 仅在源码仓维护、不进安装包，随统一安装包交付的只有 `docs/architecture/` 与 `docs/sandworkflow-consumption.md` 等少数文档。仓库根 `docs/` 下的同名文件只是薄索引，本文档一律链接前者。

## 能力与使用场景

下表说明当前源码中的能力范围与各自的启用前置条件。

| 使用场景 | SandAI 提供的能力 | 状态 |
| --- | --- | --- |
| 为应用接入模型 | 管理服务商与模型部署，验证、发布并按应用环境绑定能力路由 | 随 `0.3.0` 交付；需管理员填入服务商连接并验证、发布后可用 |
| 整理私有材料 | 上传与解析文件，结构化抽取、跨文件复核，并将结果关联到来源块 | 随 `0.3.0` 交付；需另发布 `structured_extraction` 能力路由并完成样本核验 |
| 查询企业知识 | 管理知识库版本与发布范围，检索私有来源，并在问答结果中保留引用 | 随 `0.3.0` 交付；需发布知识库版本并建立消费端绑定 |
| 执行智能体与 Skill | 发布机器人和方法包配置，调用受控检索及只读工具，记录任务与恢复状态 | 随 `0.3.0` 交付；飞书与企业微信渠道已有源码，钉钉渠道未实现 |
| 处理录音与语音 | 音频转写、片段复核、会议摘要与语音合成 | 随 `0.3.0` 交付；需宿主预先配置 FFprobe（外部转写）或 whisper.cpp 全套可执行文件（本地转写） |
| 追踪运行和费用 | 查看任务、调用、用量与预算；无法确认的费用保留为未知 | 随 `0.3.0` 交付；账单同步与人工账单导入仍为未公开的本地候选 |

文件和 OCR 使用私有存储；AI 成果需要回到来源块。接入应用使用 SandIAM 的调用凭证和服务授权，无需持有模型服务商 API Key。

### 通过 MCP 接入

接入开发者将 MCP 客户端入口设为 `/api/sand-ai/mcp`，使用 SandIAM 签发给该工作负载、面向 `sand_ai`／`sand-ai` 的 `Authorization: Bearer <调用上下文>`；管理员登录 JWT 不能替代。宿主管理员在共享 `config/sand_ai_mcp_server.php` 中明确允许访问的 hosts 与 origins，使用现发布的业务配置和最小服务授权，不把模型 Key 交给 MCP 客户端。

官方 `mcp/sdk`（最低 `>=0.8.1`）在同一入口处理 `2025-11-25` 的 initialize 握手与 `2026-07-28` 的现代逐请求两种协议，客户端沿 SDK 处理发现与 tools 调用。握手会话使用 `Mcp-Session-Id`，现代路径不创建持久会话。只支持 POST 以及握手会话的 DELETE，不提供 GET 事件流或 `Last-Event-ID` 回放。

首次接入可以选择只读 `sand_ai_task_read`（参数 `task_id`，授权 `sand_ai.task.read`），或沿工具目录选择已授权的知识、Skill 与只读工具服务；完整十个工具的参数、原号恢复与授权边界见[服务契约](plugin/sand-ai/docs/development/sand-ai-mcp-server-contract.md)。

可能计费的 query 与 run 在发送前保存原业务幂等键与完整 input，JSON-RPC id 不是业务键；断线或结果未知时只用对应的 recover 读取原号，404 不证明未发送，不换号重发。

当前状态：源码只声明 `mcp/sdk:>=0.8.1` 与 `guzzlehttp/psr7:>=2.7`，未安装 SDK、未启动服务、未调用工具，也未验证业务可用。

#### 已冻结的请求帧与 header（未运行示例）

两代协议都使用 `POST /api/sand-ai/mcp`，请求 header 为 `Authorization: Bearer <SandIAM 工作负载调用上下文>`、`Content-Type: application/json`、`Accept: application/json, text/event-stream`。下列示例只给占位符，不提供也不记录真实凭据。

现代请求必须带 `Mcp-Protocol-Version: 2026-07-28`，且 header 与正文的协议版本一致；仅凭 header 不足以选择现代协议，每个 body 的 `params._meta` 还须声明 `io.modelcontextprotocol/protocolVersion` 与 `io.modelcontextprotocol/clientCapabilities`，且不带会话。每次请求的 `Mcp-Method` 必须与正文 method 一致；下列发现请求带 `Mcp-Method: server/discover`。

```json
{"jsonrpc":"2.0","id":1,"method":"server/discover","params":{"_meta":{"io.modelcontextprotocol/protocolVersion":"2026-07-28","io.modelcontextprotocol/clientCapabilities":{}}}}
```

列工具时将正文 method 与 `Mcp-Method` 同时改为 `tools/list`，其余相同。下列只读任务调用须带 `Mcp-Method: tools/call`、`Mcp-Name: sand_ai_task_read` 与 `Mcp-Protocol-Version: 2026-07-28`；`Mcp-Name` 与正文 `params.name` 一致（此工具名可直接作为 header 值）。示例中的 `123` 必须替换为该调用方有权读取的真实任务整数 ID，不是业务幂等键。

```json
{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"sand_ai_task_read","arguments":{"task_id":123},"_meta":{"io.modelcontextprotocol/protocolVersion":"2026-07-28","io.modelcontextprotocol/clientCapabilities":{}}}}
```

握手时代先发 initialize：

```json
{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"sandai-caller","version":"1.0.0"}}}
```

取得响应的 `Mcp-Session-Id` 后，每次请求带原 Bearer、该 session 与 `Mcp-Protocol-Version: 2025-11-25`，先发送 `{"jsonrpc":"2.0","method":"notifications/initialized"}`，再用 `{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}` 发现工具；`tools/call` 的 name 与 arguments 同上，去掉 `_meta`。可能计费的工具须按固定 schema 传入原业务 request key，恢复类工具只读；会话和 JSON-RPC id 都不代替业务号。

工具与所需 SandIAM 服务动作的映射：

| MCP 工具 | 所需服务动作 |
| --- | --- |
| `sand_ai_task_read` | `sand_ai.task.read` |
| `sand_ai_workflow_source` | `sand_ai.workflow.read` |
| `sand_ai_audio_review_read` | `sand_ai.audio.transcription` + `sand_ai.audio.review` |
| `sand_ai_knowledge_query`、`sand_ai_knowledge_recover` | `sand_ai.knowledge.query` |
| `sand_ai_skill_run`、`sand_ai_skill_recover` | `sand_ai.skill.run` |
| `sand_ai_tool_run` | `sand_ai.tool.run` |
| `sand_ai_tool_recover` | `sand_ai.tool.read` |

工具名一律带 `sand_ai_` 前缀，每次执行仍校验实际资源授权。

## 安装与第一次使用

### 宿主基础依赖

需要已有的 SandAdmin `>=0.2.3` PostgreSQL 宿主。安装前由宿主管理员通过根 Composer 的正式依赖管理确认 Sand Core 稳定版 `>=0.2.4`、SandPackage 稳定版 `>=0.2.24`；业务插件最低依赖为 SandIAM `>=0.9.7`，随包捆绑声明的 `0.9.7` ZIP 与摘要用于确认工件身份。

SandAI `0.4.10` 把已安装 SandPackage 的最低稳定版定为 `>=0.2.24`；`0.2.23` 起进程登记的 `full_restart` 落在 sand-package（[sand-package#48](https://github.com/supdger/sand-package/pull/48)），`0.2.24` 继续承接该修复线。SandPackage `0.2.7` 仍是自动安装 IAM 后的加载续接、CLI 重载修复，以及完整插件 ZIP 16 MiB 压缩上限的来源，见[公开发行](https://github.com/supdger/sand-package/releases/tag/0.2.7)；它不再满足 `0.4.10` 的加载门。这三个产品的版本号分别判断，SandAdmin 的最低宿主声明保持 `>=0.2.3`。

SandAI 的加载守卫读取 Composer `InstalledVersions` 中实际安装的 Core 与 Package 版本；不足、未知或预发行版本只阻止本插件的路由与 worker 登记，其他插件不受影响。该守卫不是安装 SQL 前的版本预检，也不会自动更新宿主依赖。详见[统一安装包契约](plugin/sand-ai/docs/development/sand-ai-plugin-package-contract.md#宿主基础依赖最低条件)。

当前状态：最低版本调整为源码候选，尚未加载或安装验证。

### 安装步骤

1. 先由宿主管理员确认 Core `>=0.2.4`、Package `>=0.2.24` 并完成正常重载，再安装、配置 SandIAM，通过 SandPackage 安装完整 SandAI ZIP；按安装契约完成管理端加载和运行准备。
2. 打开 **开始使用 SandAI**，检查 SandIAM 连接并登记所需服务动作。可以先配置无需模型 API Key 的本地文本解析。
3. 要使用聊天，在页面填写服务商提供的连接地址、API Key 和模型标识，保存草稿、验证并发布模型连接，再为目标应用环境绑定聊天能力。验证会调用服务商，可能产生费用。
4. 由 SandIAM 管理员为应用的工作负载客户端授予所需服务动作；接入开发者按 [API 契约](plugin/sand-ai/docs/development/sand-ai-api-contract.md)使用授权上下文调用接口，例如 `POST /api/sand-ai/v1/chat/completions`。

首次接入应确认：已授权的聊天请求返回结果和可查询的调用记录，未获授权或已撤权的请求被拒绝。

使用完整候选包时，按[开发者首次使用](#开发者首次使用)核对包来源、依赖、私有安全配置和宿主条件。

### 宿主必需运行密钥

安装完成不代表运行就绪。以下宿主 `.env` 项缺失时，对应功能会以明确的 `*_UNAVAILABLE` 错误拒绝，须由宿主管理员生成随机值（建议 `openssl rand -hex 32` 或等效的 ≥32 字节随机串）后重载：

- `SAND_IAM_CONTEXT_SIGNING_KEY`：SandIAM 签名上下文的签发与验签；缺失时所有 `/api/sand-ai/v1/*` 调用拒绝 `SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE`。
- `SAND_AI_CONFIG_KEY`：服务商连接配置（`base_url`、API Key）加密落库；缺失时服务商保存拒绝 `SAND_AI_PROVIDER_CONFIG_INVALID`。
- `SAND_AI_IDEMPOTENCY_KEY`：部署草稿与目录写操作的幂等证明材料；缺失时部署保存拒绝 `SAND_AI_IDEMPOTENCY_KEY_UNAVAILABLE`。多实例轮换可用 `SAND_AI_IDEMPOTENCY_KEY_ID` 与 `SAND_AI_IDEMPOTENCY_KEYRING`。
- macOS 宿主建议设置 `PGGSSENCMODE=disable`：本机 libpq 的 GSS/Kerberos 协商会在 fork 出的 Webman worker 内触发 `dispatch_apply` 段错误（查询型页面直接断连）；Linux 宿主不受影响。

### 材料整理的额外发布步骤

材料整理还需为同一应用环境发布 `structured_extraction` 能力路由及模型部署。在后台 **材料整理配置** 保存草稿，由已获 `sand_ai.workflow.run` 授权的应用运行该精确修订的私有材料样本，再选择成功样本并点击 **核验样本并发布**；正式调用使用已发布的代码和版本。样本及正式运行可能产生模型费用，发送前仍核对当前 SandIAM 授权、私有来源、已发布路由、出站策略和预算。

材料与共用结构化调用链默认允许进入上述受控执行流程。维护时可在宿主运行环境显式设置 `SAND_AI_MATERIAL_ANALYSIS_DISPATCH_ENABLED=false`，并按宿主既有服务管理流程重新加载 HTTP 与任务 Worker；此时拒绝新的结构化发送，不取消已发请求，也不改写已有成果或费用。解除维护时设为 `true`（或移除该覆盖值）并重新加载。升级前若需继续暂停已排队但尚未发送的任务，请保留显式 `false`；配置文件缺失或无效仍拒绝发送。

## 文件解析与外部依赖配置

### PDF 正文解析

PDF 正文解析使用 Neuron AI 的 `PdfReader` 逐页调用本地 Poppler `pdftotext`，Composer 依赖声明增加 `symfony/process:>=6.4`。管理员需在宿主已有运行配置中设置 `SAND_AI_PDFTOTEXT_PATH` 为实际可执行文件的完整绝对路径；`SAND_AI_PDF_READ_TIMEOUT_SECONDS` 默认每页 60 秒，允许 1–300 秒。PHP 必须允许 `proc_open`，运行账户必须能读取受控私有文件并执行该工具。

页数、原页序和图像事实继续由 Smalot 提供，本方案不调用 `pdfinfo`。缺少依赖、工具或有效配置时任务显示可操作的配置错误，补齐后按原权限及剩余尝试次数，沿原任务重试入口重新提交，不会静默切回另一正文引擎。文本引擎变化可能改变新解析的文字顺序和来源块摘要，已有封存来源不改写。

当前状态：仅完成源码适配，依赖安装与真实 PDF／OCR 路径尚未验证。

### 混合 PDF 与 OCR

PDF 页面含图片或 Form 嵌入内容时，即使能提取页码、页眉等文字，也须使用该环境已配置的私有 OCR 路由。未配置时任务保持 `SAND_AI_OCR_REQUIRED`；管理员补齐 OCR 后，从原失败任务继续。OCR 必须覆盖每个含原生文字或图片／Form 的原页，缺页或页码越界时不会标为解析成功。

当前状态：仅有源码与文档，混合 PDF／OCR 操作尚未运行验证。

### 本地 OCR 部署

本地 OCR 的正式配置使用 `local_ocr_runtime` 服务商、`image_ocr` 模型和 `local_paddle_ocr` 部署，保存、实际验证并发布后，在同一环境的图片 OCR 路线选择该部署。现有 `SAND_AI_PADDLE_OCR_BASE_URL` 只能指向本机 loopback HTTP 服务；连接地址和发送限制以私有 HMAC 冻结，改变后需新草稿验证。

管理员须为该服务商与模型发布 `external_request` 价格，每次已授权发送尝试计一次请求。明确零价只表示配置的服务费为零，不含机器算力和实际现金对账；没有正式价格时硬预算继续拒绝。超时或结果不明保留原任务与预算占用，先只读找回，不换号重发。部署字段、价格和恢复边界见 [API 契约](plugin/sand-ai/docs/development/sand-ai-api-contract.md)。

当前状态：未执行迁移、未启动服务、未做实际 OCR 验收。

### DOCX 原生解析

DOCX 原生解析 `docx/zip-xml@1.5` 沿现有 `native_document_parse` 配置，读取正文、可见表格、实际节引用的启用页眉/页脚、被引用脚注/尾注和文本框；来源显示部件编号、注释引用号及文本框位置，不含私有 ZIP 路径。删除修订、隐藏文字和未引用部件不作为来源。

读取 XML 部件的累计解压字节数沿现 `SAND_AI_FILE_MAX_BYTES`（默认 50 MiB），超限明确失败。存在不能提取文字的可见图片、图形或嵌入对象时不会把部分正文标为完整解析；请在私有处理范围导出保留视觉正文的 PDF，再走上述 PDF／OCR 解析。新解析记录新版本，已有解析块与冻结材料运行不改写；字段见 [API 契约](plugin/sand-ai/docs/development/sand-ai-api-contract.md)。

当前状态：仅源码与说明，DOCX 操作尚未运行。

### 外部录音转写依赖

使用 OpenAI 兼容的外部录音转写时，Worker 仍需本机 FFprobe，即使没有配置预算策略也一样。`SAND_AI_FFPROBE_EXECUTABLE` 默认为空，须设置为运行账户可读取、可执行的可信绝对路径；外部路径不要求本机 whisper.cpp 可执行文件、模型或 FFmpeg 转换器。

每次新转写前，SandAI 先读取私有录音的容器时长与首条音轨时间线，核实后冻结该时长再发送给服务商。缺少探测配置返回 `SAND_AI_AUDIO_NOT_CONFIGURED`；时长不可读或非法返回 `SAND_AI_AUDIO_UNAVAILABLE`，且不发出服务商请求。

元数据状态 `adapter_kinds.external` 还取决于既有的外部与适配器启用设置。修正 Worker 配置后重新读取其配置状态；仅配置好探测工具既不授予 SandIAM 访问权，也不发布音频路由。对结果不确定的已提交任务，读取原任务／请求键，不自动再发一次请求。尚未发送的已准备任务，可在管理员修好配置后沿其既有执行或重试边界继续；读取元数据只确认配置。已冻结的请求、回执和费用保留原事实。

当前状态：仅源码行为与配置说明，本项未做探测、安装或运行验收。

### 本地 whisper.cpp 转写

本地 whisper.cpp 转写需在 Worker 宿主上将 `SAND_AI_WHISPER_CPP_EXECUTABLE`、`SAND_AI_WHISPER_CPP_MODEL_PATH`、`SAND_AI_FFPROBE_EXECUTABLE`、`SAND_AI_FFMPEG_EXECUTABLE` 配置为绝对路径，且这些可执行文件对 Worker 账户可读、可执行。

SandAI 在转写前探测完整的私有音频文件，按向上取整到毫秒记录时长用于计量，并拒绝时长无法核实的文件。FFmpeg 将私有录音转换为临时的 16 kHz 单声道 PCM WAV 供 whisper.cpp 使用，并在尝试结束后删除转换文件。有效但为空的转写会留下一个空白的全时长片段供管理员复核。本地转写时，BCP 47 语言变体在 whisper.cpp 中使用其主语言代码，非空术语表条目作为 initial prompt 提示传入；不保证每条提示都被识别。

## 版本更新

[版本更新与升级影响（Wiki）](https://github.com/supdger/sandadmin/wiki/plugin-updates)按组件说明功能变化、修复和升级注意事项；[完整更新日志](plugin/sand-ai/CHANGELOG.md)保留逐版本历史记录，包括 `0.1.x`–`0.2.x` 各本地候选的迁移、依赖与验收状态，本文档不再逐版本重复。公开发行与下载见 [GitHub Releases](https://github.com/supdger/sand-ai/releases)。

## 开发者首次使用

### 文档与构包

完整安装 ZIP 随包交付的文档正文只有 [SandWorkflow 消费指南](plugin/sand-ai/docs/sandworkflow-consumption.md) 与 [IAM 接入边界](plugin/sand-ai/docs/architecture/sand-iam-access-boundary.md)；[安装契约](plugin/sand-ai/docs/development/sand-ai-plugin-package-contract.md) 与 [API 契约](plugin/sand-ai/docs/development/sand-ai-api-contract.md) 位于 `plugin/sand-ai/docs/development/`，仅在源码仓维护、不进安装包。随包文档的相对链接均指向同样随包的文件，在同一个 ZIP 内可解析；仓库根的源码文档路径只保留薄索引。

包维护者使用稳定 SandPackage `>=0.2.24` 中的 `server/scripts/build-plugin-package.php` 构建该统一载荷，流程见[统一包构建入口](plugin/sand-ai/docs/development/sand-ai-plugin-package-contract.md#统一包构建入口)，覆盖应用文件、清单与依赖捆绑。

### 隔离宿主的输入

为隔离宿主准备时，向包维护者取得确切的 SandAI ZIP 及其可信登记摘要，向宿主运维取得已有的 PostgreSQL 数据库和宿主 URL。当前 [config.json](config.json) 以 `version` 与 `sha256` 标识捆绑的 SandIAM `0.9.7` 工件身份；这些值只标识一个确切的安装工件，SandAI 要求已安装 SandIAM `>=0.9.7`。请使用匹配的可信依赖捆绑、具备下述能力的稳定 SandPackage 安装器 `>=0.2.24`，以及已核对兼容性的宿主版本。上述任一输入缺失时，在安装前停止。

历史构包与安装证据：

| SandAI 包 | 要求已安装的 SandIAM | 状态 |
| --- | --- | --- |
| 统一 `0.1.22` 源码候选 | 稳定版 `0.8.4` | 最低宿主声明 `>=0.2.3`；开发基线为 SandAdmin 0.2.3 与 Core/Package 0.2.4；当前候选的兼容性仍未核实 |
| 统一 `0.1.5` 候选 | `v0.8.3-preview` 的 `0.8.3` | 在隔离环境以临时宿主 `TerminalRunner` 补丁通过正常升级、卸载与全新安装；受影响的业务验收另行判断 |
| 统一 `0.1.4` 候选 | `v0.8.3-preview` 的 `0.8.3` | 在隔离环境以临时宿主 `TerminalRunner` 补丁通过升级 → 卸载 → 全新安装 → 卸载；未公开 |
| 统一 `0.1.3` 候选 | `v0.8.3-preview` 的 `0.8.3` | 隔离升级完成；卸载在删除文件前停止，因为清理清单遗漏了会议对象 |
| 统一 `0.1.1` 包 | `v0.7.6-preview.2` 的 `0.7.6` | 历史本地升级输入 |

### 宿主兼容范围与加载守卫

当前源码候选在两份 `info.ini` 中都声明 `support = ">=0.2.3"`。官方构包器必须收到 `0.2.3` 作为 `host_min` 并省略 `host_max`；这两个字段属于生成的 catalog 发行条目，不属于 `config.json` 或 `release-payload.json`。旧的 `>=0.1.0` 声明对当前源码候选已撤回。

历史开发基线是 SandAdmin 发行版 `0.2.3`、Sand Core/SandPackage `0.2.4`、SandIAM `0.8.4`。标准宿主在其根 Composer 依赖与锁文件中持有基础包；当前前提是稳定版 Sand Core `>=0.2.4` 与稳定版 SandPackage `>=0.2.24`；SandAI 不把它们作为业务插件安装，也不新增第二套基础依赖树。

SandPackage 用 `plugin.sandadmin.app.version`（当前冻结运行源报告 `0.2.4`）核对 `info.ini` 的 `support`，该值与 SandAdmin 仓库的发行 tag 不同。这项既有检查强制执行已声明的宿主范围。

SandAI 另外用 Composer `InstalledVersions` 实现自己的加载守卫：已安装的 Core 必须是稳定版 `>=0.2.4`，已安装的 Package 必须是稳定版 `>=0.2.24`。该加载闸门不是安装器在执行 SQL 前的版本预检。缺失、未知、预发行或版本不足只阻止 SandAI 的路由与 worker 登记，其他插件不受影响。该源码实现未经运行时验证，也没有新增包清单的兼容性字段。最低声明与已知开发基线都不构成当前候选或更高宿主版本的兼容性验收。

捆绑的 SandIAM `0.9.7` 工件仍绑定在 `config.json` 声明的 SHA-256 上，其工件身份不设兼容性上限；SandAI 要求已安装的 SandIAM `>=0.9.7`。早期 SandPackage 安装器把已安装插件版本与捆绑版本做完全相等比较，会拒绝更高的已安装 SandIAM；SandPackage `0.2.13` 把该检查改为 `InstallLogic::installedDependencyMeetsMinimum()` 的最低版本比较，`0.2.14` 修正了大数值字段的比较，因此更高的已安装 SandIAM 现在可以通过，无需降级或重装。捆绑 ZIP 的版本与 SHA-256 仍与已声明的工件身份完全比对。早期的宿主与包结果仅作历史记录。

SandPackage 只检查确切声明的版本，不接受兼容区间。保留的仅源码 `0.7.6` 捆绑是历史升级输入，已从当前安装 ZIP 中排除，不满足当前候选；请先通过 SandIAM 自身的安装器升级，再安装当前 SandAI 候选。

### 安装前后的核对

通过 SandPackage 安装完整包，检查下述服务目录预检，并由 SandIAM 管理员为应用的工作负载客户端授予所需服务动作。安装成功必须发布已声明的插件、应用、配置和管理端载荷；看到 ZIP 或通过源码检查都不够。既有表需要走下述显式接纳路径。

当前状态：本候选还没有经过验证的端到端首次使用配方。模型与服务商凭据来自服务商运维，IAM 凭据与授权来自 SandIAM 管理员。本清单不是安装或模型调用的验收记录。

### 内部密钥初始化 CLI

以下命令由持有已安装宿主的运维账户执行。`chmod go-w .` 移除当前 `server` 目录的 group/other 写权限，在使用私有锁之前执行，不改变配置内容。

宿主安装器目前以 0644 创建其环境配置文件。`protect-config` 明确保留其内容，只把当前运维账户自己拥有的普通文件收紧到 0600，并保留一份私有原件备份；不安全的属主、链接或冲突备份会被拒绝。它不生成密钥，也不重启服务。

对包含 `sand-ai:security` 的完整候选 ZIP，先完成正式安装，然后在该已安装宿主的 `server` 目录下以其运维账户执行：

```sh
chmod go-w .
php webman sand-ai:security protect-config
php webman sand-ai:security initialize
php webman sand-ai:security status
```

初始化只私有地保存缺失的 SandAI 内部加密／HMAC 材料，不生成服务商 API Key 或 IAM 凭据。`status` 必须报告内部材料 `ready`，但这不构成模型、IAM 或业务就绪。配置变更后，运维须通过宿主既有的服务生命周期重载，再配置模型。已有的有效材料会被保留。材料不完整或非法、存在历史业务数据、有并发配置变更或文件权限不安全时，初始化会停止：请保留原配置并恢复同一实例的备份，不要替换密钥。升级与卸载会保留留存加密数据所需的材料；删除需要单独的数据／备份清理决策。

当前状态：该 CLI 是源码候选，旧公开预览中不存在；已在隔离 POSIX 宿主上走通此 CLI 路径——显式权限保护、全新初始化、重复执行不变更、以及对部分缺失或历史必需材料缺失的拒绝。临时消费者使用了已记录的 SandPackage 启动器补丁，这不构成未打补丁的包生命周期或完整产品验收。Windows ACL 初始化仍未验证并被拒绝。

### 外部工具密钥初始化

外部工具捆绑有自己的首次使用闸门。正常安装包含这些命令的候选后，在自有的已安装 `server` 目录执行 `php webman sand-ai:security status-tools`。如果全部工具材料都不存在，且没有工具连接、操作、运行或回执历史，执行 `php webman sand-ai:security initialize-tools`，然后在新的 CLI 进程中执行 `status-tools`，并通过宿主正常生命周期重载。已有的有效材料会被保留；部分缺失或历史缺失的材料需要同一实例的原件备份，初始化拒绝替换。私有工具备份文件是 `.env.sand-ai-tool-security-original`。工具就绪状态单独报告，不构成端点、IAM 或模型就绪。

当前状态：这些新增命令在正常安装的 CLI 路径验证前仍是源码候选；它们不会初始化服务商 Key 或 IAM 签名者。

### host-payload.json 与安装器要求

`host-payload.json` 声明每个打包的 `app/` 与 `config/sand_ai_*.php` 文件及其 SHA-256 摘要。它是安装器的输入，要求该安装器支持逐文件归属、冲突检查、升级恢复与卸载。SandAI `0.4.10` 要求的安装器是稳定 SandPackage `>=0.2.24`（`host-payload` 能力自 `0.2.7` 起存在；`0.4.10` 另外要求进程登记 `full_restart`）。ZIP 与源码层面的计划都不能证明某个宿主已经安装了这些应用服务。

安装前请锁定 SandAdmin 与 SandPackage 的修订版本，并按所选包记录核对确切的 SandAI ZIP SHA-256。使用支持 `plugin_dependencies`、`service_catalog` 与 `host-payload.json` 的安装器，走普通的包上传／安装生命周期。仅通过元数据校验不能证明依赖兼容或真实宿主安装。

### 既有 sand_ai_* 表的显式接纳

向已有 `sand_ai_*` 表的数据库安装前，先审阅并显式接纳这些表。全新安装会拒绝覆盖它们。数据库迁移、服务重启和卸载是分开的运维动作。

### 会议摘要路由登记

会议摘要候选要求宿主运维在 `config/sand_ai_meeting_summary.php` 中登记确切的已发布部署路由。运维提供其路由快照 SHA-256、已核实的官方容量来源 URL 与内容 SHA-256、上下文／输入限额以及分词器请求限额；调用方不能提供这些设置。该候选只支持显式登记的直连 BigModel `glm-5.3-flash` 路由。空登记会拒绝新摘要；缺少对应迁移或其不可变账本会阻止新的分词器调用。

先备份数据库与已安装文件，完成正式的 SandPackage 升级并核实迁移，再启用新的会议 worker。升级提交后，回滚运行时文件时仍须保留新的账本与调用证据；不要对该 schema 执行更旧的迁移运行器或卸载脚本。分词器金额保持未知（`unpriced`），不是零。

### 可恢复长会议执行模式

可恢复长会议候选还需要按顺序执行迁移 `109`、`111`、`112`。它默认关闭：目标宿主验收后，运维在已安装的 `config/sand_ai_meeting_summary.php` 中把 `durable_worker.enabled` 设为 `true`，并通过宿主正常进程生命周期加载该配置。现有的插件任务 worker 运行该应用服务，不需要第二个 worker。服务商配置与 IAM 授权仍留在其既有管理页面。

通过既有的会议摘要动作提交一份完整录音。新任务使用已启用的执行模式，旧任务保留其已记录的模式。轮询既有任务状态：成功任务返回摘要与来源引用，`queued` 或 `running` 表示准备或执行仍在继续。如果开关关闭，新提交会走此前的路径且可恢复任务无法继续；重试前请先恢复已验收的配置。该候选在音频生产者既有 8 MiB 转写文本限额内最多接受 10000 个转写片段。它不承诺会议时长上限，也不把分词器费用当作零。

当前状态：该模式的完整安装与运行时验收仍未完成。

### 会议执行设置

正常安装或升级后，打开 **开始使用 SandAI → 会议执行设置**。选择 SandIAM 环境及其已发布的聊天路由，然后显式选择受支持的规划方法。本地 Neuron `DelimiterTextSplitter` 规划不发出分词器请求，token 数报告为未计量；既有的远程分词器登记保留其原方法。

填入运维确认的上下文／输入限额、官方容量文档 URL 以及该文档实际内容的 SHA-256；远程规划还需要分词器请求限额。未知的能力值必须由运维核实；模型别名和 URL 哈希都不是容量证据。保存会存入一条处于停用状态的登记。复核已保存的值后显式启用执行；撤销会停用新摘要。已发布路由或修订变化需要重新确认并重新登记。界面会报告缺失的执行 schema 或不受支持的规划方法，而不是静默启用。此设置不发出远程容量探测或模型调用。

迁移 `124` 通过正常安装／更新／卸载持久化该登记，本地规划另需迁移 `127`。新任务冻结 `meeting_capacity@2`，含确切路由、登记修订与可信环境归属；历史 `@1` 任务保留其原有的冻结／配置恢复行为。本地规划证据不计量 token，也不降低单独的保守财务预占。来源授权、完整覆盖、限额与不可变费用仍是执行闸门；该设置不是 IAM 授权。

当前状态：本地规划变体是源码候选，正常安装、页面与业务验收仍待完成。确切端点见源码仓维护的 [API 契约](plugin/sand-ai/docs/development/sand-ai-api-contract.md#会议执行设置2026-10-04-冻结候选未安装)（该文档仅在源码仓维护，不随安装包交付）。

### 受管文档工具

通过正常升级加载迁移 `128` 后，受管 MCP 接受 `2025-11-25` 与 `2025-06-18` 两个稳定协议；新连接冻结实际协商版本，旧快照按原版本恢复。后台仍使用现有的连接创建、发现和发布入口。新本地工具为 `external_document_search` 与 `external_document_fetch`；实际远程 schema 必须匹配服务端登记，来源返回受控引用和实际内容摘要。原 `external_search` 配置及摘要保持不变。

宿主运维可成对配置私有进程变量 `SAND_AI_TOOL_PINNED_HOST` / `SAND_AI_TOOL_PINNED_IP`，与 Provider 的同类变量分开。只有带时间和来源的公开 DNS 证据可作为配置依据；地址仍须经过公网 HTTPS/443 与 TLS 校验。非法或不完整的配置会拒绝请求，不自动换地址。保存后通过宿主正常生命周期加载，应用请求不能传入 pin。

### 出站地址放行名单（管理员显式收窄默认防护）

服务商连接测试、模型发现、验证与所有经 `ProviderConnectionPolicy` 校验的出站地址默认拒绝解析到内网、回环、元数据或保留网段的结果。本机代理（Clash/Surge 类）的 fake-IP 池会把 `open.bigmodel.cn` 等公网域解析到 `198.18.0.0/15`，此时连接被正确拦截，管理页提示管理员在代理软件中把该域设为「直连」或暂时关闭代理。

确认确属可信解析结果后，宿主管理员可在宿主 `.env` 显式收窄默认防护：

- `SAND_AI_EGRESS_ALLOW_CIDRS`：逗号分隔的 CIDR 放行名单，默认空。只有解析地址落入明确列出的网段才放行；未列入的私网、回环、元数据与其他保留网段继续阻断，名单存在本身不放行任何类别。条目会被规范化（`198.18.0.123/15` 视为 `198.18.0.0/15`）；任一条目非法时整个配置拒绝生效，并以 `SAND_AI_EGRESS_ALLOWLIST_INVALID` 明确报错，不静默部分应用。

**安全警告：此设置会降低安全防护，只放行你信任的服务地址。** 放行名单生效于全部 Provider 出站校验（首次开通测试与已保存服务商的运行时端点解析都遵守）。请只列入确有证据的最小网段（如代理 fake-IP 池 `198.18.0.0/15`），不要列入 `0.0.0.0/0` 或整段私网；配置变更后按宿主正常生命周期重载。修复代理 DNS 使其返回公网地址永远是首选路径，放行名单只是受控例外。

受管外部工具连接要求运维已核实的 `data_class`。新运行从不可变的连接修订冻结该分类，未分类的历史修订不能运行。派发、重放和结果读取都要求针对已持久化的工具运行取得 SandIAM 调用授权，且在每次外部请求前和返回结果前重新校验。

当前状态：工具密钥 CLI 报告 ready 不代表 Worker 已加载或工具业务通过。详情见 [API 契约](plugin/sand-ai/docs/development/sand-ai-api-contract.md)；候选目录与文档读取的实证不替代正常安装、SandIAM 授权、计量与恢复验收。

### 服务目录预检与注册

使用支持 `plugin_dependencies`、`service_catalog` 与 `host-payload.json` 的稳定 SandPackage `>=0.2.24` 时，安装会校验锁定的 SandIAM 捆绑，在其缺失且数据库为全新时先安装它，然后检查已安装版本并预览已声明的服务动作。随后安装 SandAI，并通过 SandIAM 的官方 catalog 端口注册 `sand_ai` 服务以及 `config.json` 中声明的全部动作。注册会创建缺失的条目；已停用的服务或动作会阻止安装，且永不自动恢复。注册不签发授权。

已获授权的 SandAI 管理员可在安装后检查 `GET /app/sand-ai/admin/setup/service-catalog/preflight`。另一个 `POST /app/sand-ai/admin/setup/service-catalog/register` 端点仍可用于对未使用该安装器的安装做显式目录修复。Skill 与音频有用于同一目的的独立预检与注册端点。

该只读预检报告 SandIAM 端口是否可用，以及服务和每个已声明动作是缺失、active 还是 disabled。只有在没有任何一项被停用时才允许显式注册。它既不证明目录已注册，也不签发授权。SandAI 的开通页同时暴露这两个步骤，并在服务或动作被停用时引导管理员前往 SandIAM。

对外的模型目录在列出模型前，要求针对 SandIAM 当前工作负载客户端引用的 `sand_ai.model.read` 调用授权。

当前状态：真实宿主验收仍待完成。

### 历史遗留库归档

对已审阅的、统一前 18 表 `sandai` schema，包内附带 `plugin/sand-ai/tools/archive-legacy-sandai.sql`。可选的构建器在不连接 PostgreSQL 的情况下打印该已审阅 SQL：

```sh
php plugin/sand-ai/tools/build-legacy-adoption-sql.php > sand-ai-archive.sql
```

该 SQL 候选只归档既有 `sandai` 数据库中的 18 张遗留表及其自有序列，不安装新 schema。执行前请审阅 [docs/legacy-adoption-20260925/README.md](docs/legacy-adoption-20260925/README.md) 并取得明确的迁移与服务授权。归档后，通过普通的 SandPackage 全新安装生命周期安装一次 SandAI，该流程执行 `install.sql` 并发布插件、应用服务、配置和管理端 UI。旧的应用、环境与凭据行仍保持归档状态，它们不会变成 SandIAM 授权或可用的 SandAI 凭据。

当前状态：在安装器与真实宿主完成该序列之前，安装未经验证。

### 公开 0.1.0 预览版升级桥接

针对确切的公开 `0.1.0` 预览版，另有一个源码候选使用显式的 `--public010`（公开 `0.1.0` 预览版桥接）分支。它只接受已发布 ZIP 的 SHA-256 及其确切的 14 表 PostgreSQL 画像；不接纳上述 18 表快照、修复前的 v093，或任意缺少账本的数据库。默认计划打印安全的 JSON，且从不连接数据库：

```sh
php plugin/sand-ai/tools/build-legacy-adoption-sql.php --public010
```

`prepare` 把 SQL 生成到一个新的私有输出文件；`bridge` 导出确切的候选 `update.sql` 以便比对。实际升级遵循 SandPackage 上传 → 安装升级 → SSE 执行。执行 SQL 前，运维需要受保护的数据库、已安装文件与私有存储备份、一次演练过的恢复、已停止的遗留消费者，以及明确的数据库授权。当前的 IAM 访问必须通过 SandIAM 配置；旧的授权、凭据和默认为零的用量不会被转换成当前授权或已计量费用。确切范围、输入来源与恢复见[安装契约](plugin/sand-ai/docs/development/sand-ai-plugin-package-contract.md)。

当前状态：该桥接是未公开源码且尚未运行；公开预览仍是仅插件的目录结构。

## 授权与运行边界

### 智能体

智能体调用方同时需要 `sand_ai.agent.submit` 与 `sand_ai.agent.run` 授权。submit 授权针对已持久化的任务且不带数据分类。派发时，SandAI 在每个实际取回文件的摘录到达模型之前针对该文件校验 `agent.run`；结果为空时对任务本身授权。SandIAM 按对应授权的调用配额计入每次新授权。模型请求前重新校验原任务授权和每个来源授权；AI 任务在该处还会重新核对其冻结的来源事实。幂等的结果重放会重新校验原任务授权。结果读取会再次对任务及其已持久化来源授权。

已发布的智能体通过管理员草稿 API 的 `knowledge_base_code` 绑定同环境的已发布知识别名，发布时要求该绑定。派发时，应用只检索其当前已发布版本，并在每个任务中冻结其发布 ID、版本 ID 与来源范围。执行、渠道历史和结果读取都拒绝已变化的版本；私有文件访问在送达模型前重新检查。已发布智能体与渠道调用方在 `sand_ai.agent.submit`、`sand_ai.agent.run` 之外还需要 `sand_ai.knowledge.query`。Worker 在加载摘录前通过 SandIAM 对锁定的发布授权，并在模型派发前重新校验该授权；结果读取与飞书投递会获取新的发布授权。

智能体管理页从所选应用环境可用的已发布知识别名中选择并保存到草稿，发布时服务端仍会重新检查该绑定。迁移 `054` 仅为源码，未对 `sandai` 执行。

### 智能体管理页与渠道

SandAI 智能体页有四个标签：已发布智能体配置、渠道绑定、已发布版本试用、以及旧的逐请求试用。管理员需要 SandIAM 的环境与工作负载客户端列表权限，加上 SandAI 的定义／渠道读取或管理权限，才能使用对应控件。渠道绑定指名一个既有的 SandIAM 应用和工作负载客户端；该页不创建 SandIAM 凭据或授权。已发布版本试用使用调用方提供的 SandIAM 工作负载上下文。

渠道消息入口可由已自行校验平台 webhook 并映射其发送者的适配器调用。飞书 v2 文本 webhook 位于 `POST /api/sand-ai/v1/channels/{code}/feishu/events`，校验飞书签名与 token，在宿主进程中取得 SandIAM 工作负载上下文，并提交一个渠道任务。管理员可通过渠道绑定页配置 `encrypt_key`、`verification_token`、`workload_credential`、`app_id` 与 `app_secret`。这些值使用从 SandAI IAM 上下文 keyring 派生的密钥，加密存放在一张单独的只写凭据表中；页面只显示受管凭据是否存在。一个回调 code 在跨环境范围内只能有一个受管绑定。既有的 `SAND_AI_FEISHU_CHANNELS_JSON` 条目仍是没有受管凭据的渠道的回退。请把 `SAND_AI_FEISHU_WORKER_SOURCE_IP` 设为 SandIAM 服务授权允许的 Worker 地址。回调记录一个加密的会话目标并确认提交；另一个 Worker 在重新检查 SandIAM 授权、渠道与智能体修订以及私有来源后发送终态结果，对需要复核的结果改发通知。重试对每个任务保留同一个飞书消息 UUID。

企业微信自建应用文本适配器已有源码，支持签名加密回调、受管凭据与异步回复，其回调为 `GET|POST /api/sand-ai/v1/channels/{code}/wecom/events`。渠道绑定页把回调 Token、EncodingAESKey、企业 ID、AgentId、应用 Secret 与 SandIAM 工作负载凭据作为一组只写值保存。`SAND_AI_WECOM_CHANNELS_JSON` 仍是没有受管凭据的渠道的回退。请把 `SAND_AI_WECOM_WORKER_SOURCE_IP` 设为 SandIAM 服务授权允许的地址。发送结果不确定的回复停留在 `uncertain` 供运维核实，不自动重试。该页分别展示已校验的回调、已受理的消息、近期投递状态和受控错误，不显示消息正文或接收者 ID。平台确认已发送、但随后本地状态写入不确定的情况同样不进入自动重试。

当前状态：钉钉渠道仍待实现。企业微信迁移 `078`–`080`、一个运行中的已安装宿主，以及真实平台回调与投递，是各自独立的验收步骤。

### 检索

公开检索要求 `sand_ai.retrieval.search` 授权。SandAI 在检索前通过 SandIAM 对当前工作负载客户端授权，并在返回前重新校验，空结果同样如此。它也在发送摘录前通过 SandIAM 对每个返回的私有文件授权。数据分类不同的命中会被一并拒绝。空结果不含私有来源，也不消耗文件授权配额。

### 文件

读取元数据或删除既有私有文件分别要求 `sand_ai.file.read` 或 `sand_ai.file.write` 授权。SandAI 在两种操作之前都通过 SandIAM 对已持久化的文件授权。上传此时还没有文件 ID：它先对 SandIAM 从自身记录解析出的当前有效工作负载客户端授权，然后才写入私有存储。客户端或授权不可用、已撤销时，上传路径关闭。

### 调用与费用读取

调用状态与单次调用费用读取分别要求 `sand_ai.invocation.read` 与 `sand_ai.usage.read` 授权。每次读取在返回结果前通过 SandIAM 对已持久化的调用及派发归属授权。归属无法核实的历史调用一律失败关闭。不存在不可变用量事件时，单次调用费用 API 报告持久的派发状态，金额为未知、token 留空；它不会把服务商失败或缺失事件变成零费用主张。

### 知识库

知识库草稿与发布要求每个来源文件都保留可核实的上传归属。同一版本内的来源必须共享组织、应用、环境和数据分类；该应用的不同工作负载客户端可以共同贡献。管理员通过 `/app/sand-ai/admin/knowledge-bases/publications/publish` 发布消费端绑定，并可通过 `/publications/revoke` 撤销；消费环境获得一个唯一查询 code。

调用方还需要 SandIAM 中的 `sand_ai.knowledge.query` 服务动作授权；SandAI 在返回摘录前通过 SandIAM 的官方调用端口解析已发布版本及其来源数据分类。没有已核实来源范围的历史版本对该 API 不可用。

SandAI 知识页让管理员从 SandIAM 选择环境、选择符合条件的已发布知识库，并查看、发布或撤销消费端绑定。该页加载需要 SandIAM 的环境列表权限和 SandAI 的发布列表权限。

当前状态：迁移 `038` 与真实的 PostgreSQL／SandIAM／HTTP 行为仍需在已授权宿主上完成安装与验收。

### 个人 Skill

个人 Skill 有独立的管理员动作：检查 `GET /app/sand-ai/admin/setup/service-catalog/skill/preflight`，并以空 body 显式调用 `POST /app/sand-ai/admin/setup/service-catalog/skill/register`。这只声明 `sand_ai.skill.run`，既不签发授权，也不证明有过一次真实的 Skill 调用。开通页单独暴露这第二个步骤。

消费后端用原测试或运行的稳定业务键直接读取历史费用，并通过当前 `sand_ai.usage.read` 校验原消费者与运行归属。费用入口独立于成果读取；未知费用保持 null，无关联调用返回 404。路径与参数见 [API 契约](plugin/sand-ai/docs/development/sand-ai-api-contract.md#scoped-skill-历史费用只读入口2026-10-04-冻结候选未安装)。

### 音频

音频转写有自己的管理员动作：检查 `GET /app/sand-ai/admin/setup/service-catalog/audio/preflight`，并以空 body 显式调用 `POST /app/sand-ai/admin/setup/service-catalog/audio/register`。这只声明 `sand_ai.audio.transcription`；管理员随后必须在 SandIAM 中为应用和工作负载客户端授予访问权。音频提交对已持久化的私有文件授权，排队执行在派发前重新校验加密的原始上下文，任务读取与手动重试要求当前有效授权。目录注册与本地检查都不能证明有过一次真实的音频调用。

### 任务读取、取消与重试

任务的读取、取消和重试操作各自使用对照任务原始资源核验过的事实，通过 SandIAM 对已持久化的任务 ID 授权；响应在返回任务状态或结果前重新校验该授权。

## 法律能力（源码增量）

本节的法律能力在 API 契约中归属同一个未完成阶段（契约内标记为 `6.2`），各入口分别发布、分别验收。本节全部内容只交付源码，未执行数据库升级、未发布配置、未调用模型或法库，也未做业务验收。

### 独立民事起诉状草稿

法律能力新增 `complaint_drafting` 发布配置，以同一次已授权的私有材料、确认输入和固定模板，产生待律师复核的完整民事起诉状；旧的诉讼准备提纲保持原语义。来源及版本不足时明确留空待核，律师保存文书由消费应用现有的版本服务负责。详见 [API 契约](plugin/sand-ai/docs/development/sand-ai-api-contract.md#独立民事起诉状草稿62-source2026-10-06)。必要的 profile 迁移 `135` 仅保存源码。

### 研究争点与检索式规划

法律分析配置新增可显式发布的 `legal_research_planning`，复用现有 TaskGateway 与 Neuron Workflow，供律师冻结案件事实后做法规／案例双路候选检索编排。正式的当前环境工具版本 GET 只读已有发布与 MCP schema 绑定，不执行检索。候选始终等待人工来源确认，详见 [API 契约](plugin/sand-ai/docs/development/sand-ai-api-contract.md#冻结事实争点规划与双路检索编排62-source2026-10-06)。迁移 `136` 与安装升级入口仅为源码。

### 裁判分析与救济模板草稿

法律分析的管理员配置新增三个独立发布模式：`judgment_remedy_planning`、`judgment_remedy_analysis`、`judgment_remedy_drafting`。律师分别消费真实判决／前案规划、人工法源后的完整裁判包，以及原分析正文与私有模板草稿；均走现有 TaskGateway／Neuron，不自动救济或提交。详见 [API 契约](plugin/sand-ai/docs/development/sand-ai-api-contract.md#原裁判分析包与救济研究模板草稿62-source)。迁移 `139` 与安装／升级入口仅为源码并保留此前的 `138`；旧有 16K token 上下文与旧输出契约保持不变，实际 profile、来源许可及业务验收仍单独判定。

### 北大法宝法规与案例候选适配

北大法宝法规候选适配是可选源码增量，默认关闭，不改变统一产品的厂商选择。服务端管理员在 `config/sand_ai_pkulaw.php` 填入组织实际订阅工具的原始 schema，再通过既有的受管 MCP 连接发现和发布入口使用；没有实际 schema 时不注册工具。候选仅保留来源元数据、原平台定位和返回条文摘要哈希，不持久化条文原文，也不登记确认法源。配置、公开协议及剩余的许可／版本条件见 [API 契约](plugin/sand-ai/docs/development/sand-ai-api-contract.md#pkulaw-article-candidate-adapters)。

已有的可选法规适配增加 `pkulaw_case_search`：使用实际订阅的 `search_case` schema、沿现有受管 MCP 发现／发布后，以 `query`／`limit` 映射 `text`／`size` 检索。默认仍关闭，不选择或开通厂商。候选只保留案号、法院、日期、案由等实际元数据、原平台链接和返回内容摘要哈希；不保存判决段落，不确认法源、裁判效力或全文版本。详情见[案例候选契约](plugin/sand-ai/docs/development/sand-ai-api-contract.md#pkulaw-case-candidate-adapter)。

当前状态：仅交付源码，未安装、加载或调用厂商服务。

## 其他源码候选

以下条目均为未公开或未发行的源码候选，正常升级加载与真实业务验收分别记录。

### 材料冲突字段定位

材料成果页把冲突标签和值定位到已封存的原文件字段，并提供对应来源。定位证据不足时显示未确认；定位已核对也仍须业务核实。升级不重写历史成果或费用。字段和来源规则见 [API 契约](plugin/sand-ai/docs/development/sand-ai-api-contract.md)。

### 工具及结构化聊天容量登记

预算启用的工具或结构化聊天部署，须在新的正常部署修订中显式选择受管 `bigmodel_flash_chat_wire@20261004` 容量证明。它核对实际 SDK 最终正文与已发布路由，不删除工具或 schema 字段，也不以本地分词降低官方完整容量预占。旧部署、Skill 版本和已发生费用保持原快照；结构化载体的财务准入不代表供应商支持该功能。配置字段和恢复边界见 [API 契约](plugin/sand-ai/docs/development/sand-ai-api-contract.md)。

### 首次开通输入修正与知识来源

明确的本地输入错误会给出中文修正提示；只有本次保存未提交的可信证明才允许修改并重新保存。已有结果不明的保存或验证须保留原参数与幂等键，先查原记录。聊天验证复用官方 Neuron AI 4.x，沿原受控网络与计量恢复边界。查询引用的知识来源可按公开知识版本与来源块读取当前已发布正文，授权及内容摘要在返回前再次核验。正式字段和恢复限制见 [API 契约](plugin/sand-ai/docs/development/sand-ai-api-contract.md)。

### 机器人只读工具与配置回退

在机器人定义中选择最多两个已发布的只读工具版本与业务用途；知识检索仍保留。新版本冻结工具绑定，外部工具沿用独立的 SandIAM 授权、回执与计量。带只读工具的新任务最多四轮模型调用，外部请求未定价不表示免费；启用了向量索引的检索还须另核 Embedding 费用。已知回执恢复与未知派发分别处理，不能借手动重试重复发送。

**版本** 入口查看已发布配置并执行明确回退；回退重新核对当前模型及知识别名，不恢复旧任务或历史知识内容。任务显示其实际冻结版本，历史读取继续核对当前父任务与工具读取权限。正常升级须包含迁移 `129`。源码、整包、加载、模型和工具业务结果分别验收，详见 [API 契约](plugin/sand-ai/docs/development/sand-ai-api-contract.md)。

### 本地会议结构化输出

选择已发布且显式配置结构化输出的聊天部署，重新确认当前路由的本地会议执行登记后，新任务才能冻结结构化输出契约。模型正文由官方 Neuron JSON 提取器和原业务来源校验共同验证，失败不隐藏重试。历史任务保留原协议和费用。该候选尚待统一包、正常加载与新会议业务验收。

### 模型验证费用

后台费用页的「模型验证费用」单独显示平台管理员验证，不计入环境业务总额。正式部署验证沿调用开始时的真实价格记录；临时连接尚无正式模型和价格，保留真实 token，金额显示未知。旧验证仍标记未追踪，不补写账单或把未知填零。查看入口使用现有的用量查看权限，摘要使用现有的用量汇总权限；正常升级须包含迁移 `130`。

### 请求费用同步

后台费用页的账单同步按已配置服务商选择协议。OpenAI 组织 Costs 使用专用账单管理员凭据，仍按汇总记录展示。实际模型连接指向 OpenRouter 官方 API 时，先查询凭据状态，页面说明会切换为使用原模型连接读取已记录调用的费用，无需另存账单 Key。同步使用 UTC 账期；原调用 ID、连接、账户、模型、计量与冻结金额都能核实且一致时才自动核对。连接改变、非文本／BYOK、未知 native token 或超出精确金额范围会拒绝，不把未知填零。

请求费用记录不证明已退款；退款仍须同一调用的已核对账单、私有凭证和明确的人工审核。字段与限制见[账单契约](plugin/sand-ai/docs/development/sand-ai-api-contract.md#账单管理凭据保存原号恢复原-41)，公开升级记录仍沿用[现有 Wiki](https://github.com/supdger/sandadmin/wiki/plugin-updates)。

### 人工账单原件重导入

在费用页 **导入账单** 选择服务商，上传其原始 CSV、PDF 或非宏 XLSX，再逐行填写金额、数量、币种、UTC 时间窗及原件位置，例如「用量明细!第 2 行」。附件保存在私有存储，不自动解析或执行工作簿内容。重导同一原件时沿用完全相同的定位：事实相同复用原行和审核，事实不同拒绝整批，不覆盖历史。

原件摘要与人工定位只用于重复识别，不证明厂商账户或付款。账户及逐请求关联须在人工审核中核查；汇总账单、套餐扣量和未出账金额保持原粒度，不能摊成单调用现金。没有原件绑定的旧 JSON 导入仍不具跨批事实去重依据。正常升级须包含迁移 `155`（来源绑定）和 `156`（原始 XLSX 的 MIME 字段约束）。完整字段见[账单契约](plugin/sand-ai/docs/development/sand-ai-api-contract.md#人工账单原件与跨批次重导入本地后继候选)，升级说明沿用[现有 Wiki](https://github.com/supdger/sandadmin/wiki/plugin-updates)。

### 专用材料调用次数窗口

宿主运维可通过 `sand-ai:provider-call-limit` CLI 为专用调用方建立固定次数窗口，材料分块、合并、跨文件复核与重试共享上限；未知调用停止后继，窗口到期或关闭不会解除限制。金额预算与 IAM 授权独立生效。正常升级须包含迁移 `157`。输入来源、操作与原号恢复见 [API 契约](plugin/sand-ai/docs/development/sand-ai-api-contract.md#专用调用方的共享材料模型次数窗口157源码候选)，公开升级说明仍沿用[现有 Wiki](https://github.com/supdger/sandadmin/wiki/plugin-updates)。

## 许可与反馈

- 许可：见 [LICENSE](LICENSE)。
- 问题反馈与功能建议：[GitHub Issues](https://github.com/supdger/sand-ai/issues)。
- 发行与下载：[GitHub Releases](https://github.com/supdger/sand-ai/releases)；逐版本变化见[完整更新日志](plugin/sand-ai/CHANGELOG.md)。
