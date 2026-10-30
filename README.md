# SandAI

SandAI 是面向 SandAdmin 的 AI 能力插件，为业务应用提供统一的 AI API。管理员在后台配置模型服务、能力路由、私有文件、知识库和任务；接入开发者通过 API 调用聊天、材料整理、知识问答、智能体和音频处理，并查看来源与费用。

北大法宝法规候选适配是可选源码增量，默认关闭，不改变统一产品的厂商选择。服务端管理员在 `config/sand_ai_pkulaw.php` 填入组织实际订阅工具的原始 schema，再通过既有受管 MCP 连接发现和发布入口使用；没有实际 schema 时不注册工具。候选仅保留来源元数据、原平台定位和返回条文摘要哈希，不持久化条文原文、不登记确认法源。配置、公开协议及剩余许可/版本条件见[API 契约](plugin/sand-ai/docs/development/sand-ai-api-contract.md#pkulaw-article-candidate-adapters)。当前仅交付源码，未安装、加载或调用厂商服务。

SandAI 使用 [Neuron AI](https://github.com/neuron-core/neuron-ai) 作为 AI 开发框架。当前安装包在 [config.json](config.json) 中声明最低依赖 `neuron-core/neuron-ai >=4.0.2`，不设置版本上限；实际安装版本由宿主 Composer 锁文件记录。源码已接入其模型 Provider、Agent 与工具调用、RAG 检索与文本分块、Workflow、结构化输出以及语音接口；具体模型和能力仍须按服务商支持范围配置、验证后启用。

Neuron AI 提供通用 AI 编排能力；SandAI 负责模型与环境配置、任务持久化和恢复、私有来源校验、用量及费用记录。身份、应用、工作负载凭证和服务授权由 SandIAM 提供，SandAI 消费其授权并在执行前重验。业务审批使用独立的 SandWorkflow 插件；Neuron Workflow 用于 AI 处理节点编排。

SandAI 通过一个 SandPackage ZIP 安装，包含 PHP/Webman 后端、Vue 管理端与 PostgreSQL 生命周期文件。`plugin/sand-ai/` 承载后台配置，`app/` 承载对外 API 和共享服务，随同一安装包交付。

## 能力与使用场景

下表说明当前源码中的能力范围。各候选版本的安装与真实业务验收状态见下方说明及[完整更新日志](plugin/sand-ai/CHANGELOG.md)。

| 使用场景 | SandAI 提供的能力 |
| --- | --- |
| 为应用接入模型 | 管理服务商与模型部署，验证、发布并按应用环境绑定能力路由 |
| 整理私有材料 | 上传与解析文件，结构化抽取、跨文件复核，并将结果关联到来源块 |
| 查询企业知识 | 管理知识库版本与发布范围，检索私有来源，并在问答结果中保留引用 |
| 执行智能体与 Skill | 发布机器人和方法包配置，调用受控检索及只读工具，记录任务与恢复状态 |
| 处理录音与语音 | 音频转写、片段复核、会议摘要与语音合成 |
| 追踪运行和费用 | 查看任务、调用、用量与预算；无法确认的费用保留为未知 |

文件和 OCR 使用私有存储；AI 成果需要回到来源块。接入应用使用 SandIAM 的调用凭证和服务授权，无需持有模型服务商 API Key。

### 应用通过MCP接入（当前SOURCE，未安装或运行验证）

接入开发者将MCP客户端入口设为 `/api/sand-ai/mcp`。使用SandIAM签发给该工作负载、面向 `sand_ai`／`sand-ai` 的 `Authorization: Bearer <调用上下文>`；管理员登录JWT不能替代。宿主管理员在共享 `config/sand_ai_mcp_server.php` 明确允许访问的hosts/origins，使用现发布业务配置与最小服务授权，不把模型Key交给MCP客户端。

官方 `mcp/sdk`（最低 `>=0.8.1`）同入口处理 `2025-11-25` initialize握手与 `2026-07-28`现代逐请求；客户端沿SDK处理发现和tools调用。握手会话使用 `Mcp-Session-Id`，现代路径不创建持久会话。只支持POST及握手会话DELETE，不提供GET事件流或Last-Event-ID回放。首次可选择只读 `sand_ai_task_read`（参数原 `task_id`，授权 `sand_ai.task.read`），或沿工具目录选已授权的知识／Skill／只读工具服务；完整十工具参数、原号恢复及授权边界见[服务契约](plugin/sand-ai/docs/development/sand-ai-mcp-server-contract.md)。

可能计费的query／run发前保存原业务幂等键与完整input，JSON-RPC id不是业务键；断线或未知只用对应recover读取原号，404不证明未发送，不换号重发。当前源码只声明 `mcp/sdk:>=0.8.1` 与 `guzzlehttp/psr7:>=2.7`，未安装SDK、启动服务、调用工具或验证业务可用。

## 安装与第一次使用

需要已有的 SandAdmin `>=0.2.3` PostgreSQL 宿主。安装前由宿主管理员通过根 Composer 的正式依赖管理确认 Sand Core 稳定版 `>=0.2.4`、SandPackage 稳定版 `>=0.2.7`，业务插件最低依赖为 SandIAM `>=0.8.4`，随包提供的 `0.8.4` ZIP 与摘要用于确认工件身份。Package `0.2.7` 沿用自动安装 IAM 后的加载续接和 CLI 重载修复，并统一完整插件 ZIP 的压缩容量上限为 16 MiB，见[公开发行](https://github.com/supdger/sand-package/releases/tag/0.2.7)。完整候选包超过旧 5 MiB 上限，安装和恢复须先满足新的 Package 最低条件。这三个产品版号分别判断，SandAdmin 最低宿主声明保持不变。SandAI 加载守卫读取 Composer InstalledVersions 中的实际 Core／Package 版本；不足、未知或预发行版本只阻止本插件路由／worker 登记，其他插件不动。该守卫不是安装 SQL 前的版本预检，也不自动更新宿主依赖。当前最低版本调整仅为源码候选，尚未加载或安装验证；详见[统一安装包契约](plugin/sand-ai/docs/development/sand-ai-plugin-package-contract.md#宿主基础依赖最低条件)。

PDF 正文解析源码候选使用 Neuron AI `PdfReader` 逐页调用本地 Poppler `pdftotext`，Composer 依赖声明增加 `symfony/process:>=6.4`。管理员需在宿主已有运行配置中设置 `SAND_AI_PDFTOTEXT_PATH` 为实际可执行文件的完整绝对路径；`SAND_AI_PDF_READ_TIMEOUT_SECONDS` 默认每页 60 秒，允许 1–300 秒。PHP 必须允许 `proc_open`，运行账户必须能读取受控私有文件并执行该工具。页数、原页序和图像事实继续由 Smalot 提供，本方案不调用 `pdfinfo`。缺少依赖、工具或有效配置时任务显示可操作的配置错误，补齐后按原权限及剩余尝试次数，沿原任务重试入口重新提交，不会静默切回另一正文引擎。文本引擎变化可能改变新解析的文字顺序和来源块摘要，已有封存来源不改写；当前仅完成源码适配，依赖安装与真实 PDF／OCR 路径尚未验证。

PDF 页面含图片或 Form 嵌入内容时，即使能提取页码、页眉等文字，也须使用该环境已配置的私有 OCR 路由。未配置时任务保持 `SAND_AI_OCR_REQUIRED`；管理员补齐 OCR 后，从原失败任务继续。OCR 必须覆盖每个含原生文字或图片／Form 的原页，缺页或页码越界时不会标为解析成功。本次保护只有源码与文档候选，混合 PDF／OCR 操作尚未运行验证。

本地 OCR 正式配置的源码候选使用 `local_ocr_runtime` 服务商、`image_ocr` 模型和 `local_paddle_ocr` 部署，保存、实际验证并发布后，在同一环境的图片 OCR 路线选择该部署。现有 `SAND_AI_PADDLE_OCR_BASE_URL` 只能指向本机 loopback HTTP 服务；连接地址和发送限制以私有 HMAC 冻结，改变后需新草稿验证。管理员为该服务商与模型发布 `external_request` 价格，每次已授权发送尝试计一次请求。明确零价只表示配置的服务费为零，不含机器算力、实际现金对账；没有正式价格时硬预算继续拒绝。超时或结果不明保留原任务与预算占用，先只读找回，不换号重发。部署字段、价格和恢复边界见[API 契约](plugin/sand-ai/docs/development/sand-ai-api-contract.md)。本候选未执行迁移、启动服务或实际 OCR 验收。

使用 OpenAI 兼容外部录音转写时，Worker 仍需本机 FFprobe：将既有 `SAND_AI_FFPROBE_EXECUTABLE` 指向运行账户可读取、可执行的绝对路径。每次新转写先核实私有录音真实时长，未设置或无法执行时显示未配置，不发送服务商；这项依赖不要求本机 Whisper 模型或 FFmpeg。详细配置与原号恢复见[外部录音转写依赖](#external-audio-prerequisite)。当前仅完成源码说明，未安装、探测或运行验收。

DOCX 原生解析 `docx/zip-xml@1.5` 沿现有 `native_document_parse` 配置，读取正文、可见表格、实际节引用的启用页眉/页脚、被引用脚注/尾注和文本框；来源显示部件编号、注释引用号及文本框位置，不含私有 ZIP 路径。删除修订、隐藏文字和未引用部件不作为来源。读取 XML 部件的累计解压字节数沿现 `SAND_AI_FILE_MAX_BYTES`（默认50MiB），超限明确失败。存在不能提取文字的可见图片、图形或嵌入对象时不会把部分正文标为完整解析；请在私有处理范围导出保留视觉正文的 PDF，再走上述 PDF/OCR 解析。新解析记录新版本，已有解析块与冻结材料运行不改写；字段见 [API 契约](plugin/sand-ai/docs/development/sand-ai-api-contract.md)。本次仅源码与说明修改，DOCX 操作尚未运行。

当前统一安装包仍是未公开候选；公开 `0.1.0` 预览版使用旧结构。使用完整候选包时，按下方[开发首次使用说明](#development-first-use)核对包来源、依赖、私有安全配置和宿主条件。

1. 先由宿主管理员确认 Core `>=0.2.4`、Package `>=0.2.7` 并完成正常重载，再安装、配置 SandIAM，通过 SandPackage 安装完整 SandAI ZIP；按安装契约完成管理端加载和运行准备。
2. 打开 **开始使用 SandAI**，检查 SandIAM 连接并登记所需服务动作。可以先配置无需模型 API Key 的本地文本解析。
3. 要使用聊天，在页面填写服务商提供的连接地址、API Key 和模型标识，保存草稿、验证并发布模型连接，再为目标应用环境绑定聊天能力。验证会调用服务商，可能产生费用。
4. 由 SandIAM 管理员为应用的工作负载客户端授予所需服务动作；接入开发者按 [API 契约](plugin/sand-ai/docs/development/sand-ai-api-contract.md)使用授权上下文调用接口，例如 `POST /api/sand-ai/v1/chat/completions`。

首次接入应确认：已授权的聊天请求返回结果和可查询的调用记录，未获授权或已撤权的请求被拒绝。后台保存成功、SDK 接入或安装成功各自不代表该业务链已验收。

材料整理还需为同一应用环境发布 `structured_extraction` 能力路由及模型部署。在后台 **材料整理配置** 保存草稿，由已获 `sand_ai.workflow.run` 授权的应用运行该精确修订的私有材料样本，再选择成功样本并点击 **核验样本并发布**；正式调用使用已发布的代码和版本。样本及正式运行可能产生模型费用，发送前仍核对当前 SandIAM 授权、私有来源、已发布路由、出站策略和预算。

材料与共用结构化调用链默认允许进入上述受控执行流程。维护时可在宿主运行环境显式设置 `SAND_AI_MATERIAL_ANALYSIS_DISPATCH_ENABLED=false`，并按宿主既有服务管理流程重新加载 HTTP 与任务 Worker；此时拒绝新的结构化发送，不取消已发请求，也不改写已有成果或费用。解除维护时设为 `true`（或移除该覆盖值）并重新加载。升级前若需继续暂停已排队但尚未发送的任务，请保留显式 `false`；配置文件缺失或无效仍拒绝发送。

## 版本更新

[版本更新与升级影响（Wiki）](https://github.com/supdger/sandadmin/wiki/plugin-updates)按组件说明功能变化、修复和升级注意事项；[完整更新日志](plugin/sand-ai/CHANGELOG.md)保留历史记录。公开发行与下载见 [GitHub Releases](https://github.com/supdger/sand-ai/releases)。

## 0.2.26 源码候选

本地候选 `0.2.26` 完善文件清理的向量状态反馈，区分当前能力“不适用”与清理“未确认”，保留已有任务与费用。版本身份和 PUBLIC010 桥接目标同步，不新增迁移；旧 `0.2.25` 包保留。当前未公开发行，正式元数据读回与真实清理结果分别记录。详见[完整更新日志](plugin/sand-ai/CHANGELOG.md)。

## 0.2.25 历史源码候选

本地候选 `0.2.25` 纳入会议 `missing_sources` 拒绝证据与原失败节点纠正入口修复，保留既有成功节点及费用。版本身份和 PUBLIC010 桥接目标同步，不新增迁移；旧 `0.2.24` 包保留。当前未公开发行，安装后的只读投影与真实纠正结果分别记录，不自动重试。详见[完整更新日志](plugin/sand-ai/CHANGELOG.md)。

## 0.2.24 历史源码候选

本地候选 `0.2.24` 纳入 FileGateway 文件删除误阻修复，保留组合费用与非计费证明源码。版本身份及 PUBLIC010 桥接目标同步更新，不新增迁移；旧 `0.2.23` 完整包保留。当前仅准备源码与完整工件，正式升级及原文件删除结果另行记录，未公开发行。详见[完整更新日志](plugin/sand-ai/CHANGELOG.md)。

## 0.2.23 历史源码候选

本地源码候选 `0.2.23` 纳入会议 v2 有界合并容量及原拒绝节点纠正索引修复，并完善渠道原回执缺失、恢复次数耗尽和费用未知的反馈。旧会议任务保留原冻结协议，原调用与费用保持；未知成果不补造成功或重发。154 生命周期、依赖、宿主范围和 Worker 重启边界不变，详见[版本身份与升级](plugin/sand-ai/docs/development/sand-ai-plugin-package-contract.md#版本身份与升级)及[完整更新日志](plugin/sand-ai/CHANGELOG.md)。本版仅为未公开的本地候选；源码、完整工件、升级和业务运行分别记录，旧 `0.2.22` ZIP 保留原件。

## 0.2.22 源码候选

本地源码候选 `0.2.22` 纳入文件中心原失败解析重试，以及 Agent 原任务重试／续行确认后的失败代际核对。操作保留原任务、调用和费用，确认后重新读取原状态；次数耗尽、需正式续权或结果未知时沿原恢复入口处理。154 生命周期、依赖、宿主范围及 Worker 重启边界保持，详见[版本身份与升级](plugin/sand-ai/docs/development/sand-ai-plugin-package-contract.md#版本身份与升级)及[完整更新日志](plugin/sand-ai/CHANGELOG.md)。本版为未公开的本地候选，源码、完整工件、正常升级和业务验证分别记录；旧 `0.2.21` ZIP 保留原件。

## 0.2.21 源码候选

本地源码候选 `0.2.21` 将已交付的任务领取 PDO 修复与 Worker 重载排空源码绑定到独立版本，保留原授权、租约、尝试次数、调用回执和费用。旧 Worker 没有新增重载回调时，首次升级须在包安装完成后通过宿主正式服务控制入口进行一次 restart；仅 HTTP reload 不表示任务 Worker 已加载新源码。154 生命周期、依赖和宿主范围保持，详见[版本身份与升级](plugin/sand-ai/docs/development/sand-ai-plugin-package-contract.md#版本身份与升级)及[完整更新日志](plugin/sand-ai/CHANGELOG.md)。当前仅隔离元数据候选，尚未交付本版元数据、归档、安装或运行验证；旧 `0.2.20` ZIP 保留原件，未公开发行。

## 0.2.20 源码候选

本地源码候选 `0.2.20` 补齐个人 Skill 失败轮次迁移 154 的正常升级及卸载入口。升级按原 version／checksum 与事务账本只执行缺失的 154，已登记的迁移不重放；未登记的既有轮次对象继续要求显式接纳。卸载只在准确账本成立时清理自有对象，保留未知对象保护。旧候选升级未执行 154 的宿主须沿实际旧版本到较高版本的正式 SandPackage 升级入口处理，不能手工 DDL 或补账。154 原文件、安装入口、原数据、依赖和宿主范围保持，详见[版本身份与升级](plugin/sand-ai/docs/development/sand-ai-plugin-package-contract.md#版本身份与升级)。当前仅隔离 SOURCE 候选，尚未交付、构包、执行 SQL、安装或运行验证；旧 ZIP 和历史记录保留，未公开发行。

## 0.2.19 源码候选

本地源码候选 `0.2.19` 为渠道原消息提供当前授权下的回执恢复：只有原模型与必要工具回执完整且可认证时，才沿原任务受理本地恢复；响应未知后按原恢复操作号只读找回。固定来源、模型请求号、计量和未知结果保留，不重发模型、检索或工具请求。安装与后台版本使用 `0.2.19`，不新增迁移，既有迁移 154、Core >=0.2.4、Package >=0.2.7、SandIAM 0.8.4、宿主范围与 SDK 依赖保持。详见[版本身份与升级](plugin/sand-ai/docs/development/sand-ai-plugin-package-contract.md#版本身份与升级)及[完整更新日志](plugin/sand-ai/CHANGELOG.md)。当前仅隔离源码元数据候选，尚未交付该版本元数据、构包、安装、运行验证或公开发行；旧 ZIP 和历史记录保留。

## 0.2.18 源码候选

本地源码候选 `0.2.18` 补齐个人 Skill 已确认输出失败的独立轮次恢复、会议纠正的原子领取及正式授权续行，并将 Skill 业务失败关联到原费用汇总。原输入、固定来源、每轮调用和费用保留；恢复仍需当前授权，未知结果沿原号只读找回。安装和后台版本统一为 `0.2.18`，新增迁移 154；Core >=0.2.4、Package >=0.2.7、SandIAM 0.8.4、宿主范围与 SDK 依赖保持。详见[版本身份与升级](plugin/sand-ai/docs/development/sand-ai-plugin-package-contract.md#版本身份与升级)及[完整更新日志](plugin/sand-ai/CHANGELOG.md)。当前仅本地源码候选，尚未构包、安装或执行迁移 154，未公开发行；源码交付不表示业务验收完成。

## 0.2.17 源码候选

本地源码候选 `0.2.17` 为个人范围 Skill 的原号评测和运行读取补充当前未受理状态：只有当前身份、固定版本和来源授权合法且原号记录真实不存在（包含软删核对）才允许消费端明确确认后同原键继续。普通404、坏关联、未知或拒权仍保留原号及未知费用；沿正常幂等受理，不自动重发。安装与后台版本统一为 `0.2.17`，生命周期SQL、Core >=0.2.4、Package >=0.2.7、SandIAM 0.8.4及现SDK依赖保持。详见[版本身份与升级](plugin/sand-ai/docs/development/sand-ai-plugin-package-contract.md#版本身份与升级)。本地候选包、正常安装和实际业务验证分别判断，未公开发行。

## 0.2.16 源码候选

本地源码候选 `0.2.16` 修复 Skill 来源提炼的正式请求：真实查询与已持久化的 canonical 来源证据作为 user 消息提交，system 保留来源、输出结构与业务规则。沿原私有来源、当前 IAM、价格预算及原建议恢复入口处理，不新增迁移，不重发历史未知调用。安装和后台版本统一为 `0.2.16`，管理员沿实际旧版本的正常 SandPackage 升级流程操作；Core >=0.2.4、Package >=0.2.7、宿主支持范围与 SandIAM 依赖保持。详见[版本身份与升级](plugin/sand-ai/docs/development/sand-ai-plugin-package-contract.md#版本身份与升级)。源码交付、本地候选包、宿主升级和真实业务验证分别记录；候选尚未公开发行。

## 0.2.15 源码候选

本地源码候选 `0.2.15` 为原调用保存服务商实际返回的安全请求／响应标识，并在现有单次费用详情展示接收来源、UTC 时间及未知／冲突状态。流中断时此前已保存的标识仍保留；标识不证明完整成果、计量、付款或账单匹配，不允许释放预算或重发。历史未知调用不补造标识，保留原任务与资金持有。正常升级目标为已安装 `0.2.14` 到 `0.2.15`，新增迁移 153 及既有生命周期声明，历史迁移、IAM、密钥、业务依赖与 Core >=0.2.4、Package >=0.2.7 前提适用；详见[版本身份与升级](plugin/sand-ai/docs/development/sand-ai-plugin-package-contract.md#版本身份与升级)。源码、本地完整包、临时宿主升级和真实费用／恢复操作分别记录；候选不代表公开发行或完整业务验收。

## 0.2.2 修复候选记录

本轮以永久版本 `0.2.2` 准备材料首次使用门槛、任务执行租约与原归属续接、费用投影和预算预占向上取整修复，供已安装 `0.2.1` 的宿主沿 SandPackage 正常版本流程升级。相关修复已完成限定独立源码复核；材料入口说明和全部固定源码合入后，由整合者派生最终载荷，构包、正常升级及真实业务验收分别记录。宿主最低版本、foundation、SandIAM、Neuron 依赖以及历史迁移 checksum 保持不变，详见[版本身份与升级](plugin/sand-ai/docs/development/sand-ai-plugin-package-contract.md#版本身份与升级)。当前仅固定版本元数据候选，尚未运行验收或公开发布 `0.2.2`。

## 0.2.1 修复候选记录

本轮以永久版本 `0.2.1` 准备知识页布局、任务权限提示及 MCP 握手响应队列修复，供已安装 `0.2.0` 的宿主沿 SandPackage 正常版本流程升级。页面已通过限定独立成品复验，MCP 修复已通过限定独立 SDK 复验；最终整合载荷固定后，构包与真实升级结果另行记录。宿主最低版本、foundation、SandIAM、Neuron 依赖以及历史迁移 checksum 保持不变，详见[版本身份与升级](plugin/sand-ai/docs/development/sand-ai-plugin-package-contract.md#版本身份与升级)。当前为整合修复源码候选，未公开发布 `0.2.1`；live MCP 和完整业务验收尚未完成。

## 0.2.0 源码候选记录

当前统一产品源码使用永久版本 `0.2.0`，供已安装 `0.1.22` 的宿主沿 SandPackage 正常版本流程升级。两份安装元数据、后台运行版本与发行载荷清单统一为 `0.2.0`；已落源的功能和原号恢复、清理接线随同一载荷交付，历史迁移及 checksum 保持原样。宿主最低版本、foundation 与 SandIAM 依赖保持不变，详见[版本身份与升级](plugin/sand-ai/docs/development/sand-ai-plugin-package-contract.md#版本身份与升级)。本次只固定版本源码候选，尚未构包、执行 `0.1.22→0.2.0` 升级或发布。

## 0.1.22 源码候选记录

当前 MCP 接入、原请求恢复、DOCX 解析及宿主兼容守卫修复使用永久版本 `0.1.22`。旧 `0.1.21` 冻结 ZIP 保留为实际升级输入，不表示公开发行。安装元数据、后台运行版本与发行载荷清单统一为 `0.1.22`；升级沿 SandPackage 的正常版本流程，迁移文件与历史 checksum 保持原样，详见[版本身份与升级](plugin/sand-ai/docs/development/sand-ai-plugin-package-contract.md#版本身份与升级)。本次只交付源码候选，未构包、安装或发布 `0.1.22`。

## 0.1.20 源码修复候选

知识索引状态读取重新核对当前来源；读取失败页面显示待核验并保留原请求。ASR 请求和正式语音部署验证复用 Neuron 官方 SDK，旧封存请求不改写。已发送但费用未核实的拒绝请求保留预算。行为边界见[API 契约](plugin/sand-ai/docs/development/sand-ai-api-contract.md#知识索引状态与外发费用恢复0120-源码候选)；正常加载及业务验收另行记录。

## 0.1.21 恢复与费用界面候选

机器人未知提交通过原键只读找回，未知操作保留原请求；个人 Skill 历史费用按原业务归属读取，渠道未知凭据保存保留原作用域。原成果清理增加未结派发保护，法律 JSON 容器校验保留旧协议。详见[API 契约](plugin/sand-ai/docs/development/sand-ai-api-contract.md#原请求找回与恢复界面0121-源码候选)。正常加载及真实功能验收另行记录。

## Development first use

The complete installation ZIP includes the [installation contract](plugin/sand-ai/docs/development/sand-ai-plugin-package-contract.md), [API contract](plugin/sand-ai/docs/development/sand-ai-api-contract.md), and [SandWorkflow consumption guide](plugin/sand-ai/docs/sandworkflow-consumption.md). These are the single maintained document bodies under the ordinary plugin payload; their relative IAM links resolve within the same ZIP. Root source-document paths retain thin indexes.

Package maintainers build this unified payload with the public SandPackage >=0.2.7 distribution's `server/scripts/build-plugin-package.php`. Follow the [complete-package build procedure](plugin/sand-ai/docs/development/sand-ai-plugin-package-contract.md#统一包构建入口); it covers application files, manifests and dependency bundles.

This source candidate is still under development; installation and business acceptance on a real host remain pending. The [public 0.1.0 preview](https://github.com/supdger/sand-ai/releases) contains the earlier plugin-only layout and is not a release of this unified application payload.

For an isolated host, obtain the exact SandAI ZIP and its trusted registry digest from the package maintainer. Obtain an existing PostgreSQL database and the host URL from the host operator. The current [config.json](config.json) identifies the bundled SandIAM `0.8.4` ZIP and the SHA-256 of the [stable v0.8.4 ZIP](https://github.com/supdger/sand-iam/releases/download/v0.8.4/sand-iam-0.8.4.zip). These values identify an exact installation artifact; SandAI requires SandIAM >=0.8.4 without an upper bound. Use the matching trusted dependency bundle, a stable SandPackage installer >=0.2.7 with the capabilities described below, and a host version whose compatibility has been checked. If these inputs are unavailable, stop before installation.

Historical package and installation evidence:

| SandAI package | Required installed SandIAM | Status |
| --- | --- | --- |
| Unified `0.1.22` source candidate | stable `0.8.4` | Minimum host declaration `>=0.2.3`; development basis SandAdmin 0.2.3 with Core/Package 0.2.4; current candidate compatibility remains unverified |
| Unified `0.1.5` candidate | `0.8.3` from `v0.8.3-preview` | Isolated normal upgrade, uninstall and fresh install passed with a temporary host `TerminalRunner` patch; affected business acceptance remains separate |
| Unified `0.1.4` candidate | `0.8.3` from `v0.8.3-preview` | Isolated upgrade → uninstall → fresh install → uninstall passed with a temporary host `TerminalRunner` patch; unpublished |
| Unified `0.1.3` candidate | `0.8.3` from `v0.8.3-preview` | Isolated upgrade completed; uninstall stopped before file deletion because the cleanup inventory omitted meeting objects |
| Unified `0.1.1` package | `0.7.6` from `v0.7.6-preview.2` | Historical local upgrade input |

The current `0.2.26` source candidate declares `support = ">=0.2.3"` in both `info.ini` files. The official package builder must receive `0.2.3` as `host_min` and omit `host_max`; these fields belong to the generated catalog release entry, not `config.json` or `release-payload.json`. The old `>=0.1.0` declaration is withdrawn for the current source candidate. The historical development basis was SandAdmin distribution `0.2.3`, Sand Core/SandPackage `0.2.4`, and SandIAM `0.8.4`. The standard host owns the foundation packages in its root Composer requirements and lockfile; the current prerequisites are stable Sand Core >=0.2.4 and stable SandPackage >=0.2.7; SandAI does not install them as business plugins or add a second foundation dependency tree. SandPackage evaluates `info.ini` support against `plugin.sandadmin.app.version` (the frozen current runtime source reports `0.2.4`), which is distinct from the SandAdmin repository release tag. This existing check enforces the declared host range. SandAI now implements its own loading guard using Composer InstalledVersions: installed Core must be stable >=0.2.4 and installed Package must be stable >=0.2.7. This loading gate is not an installer pre-SQL version check. Missing, unknown, prerelease or insufficient versions prevent only SandAI routes and workers from registering; other plugins remain untouched. This source implementation has not been validated at runtime and adds no package-manifest compatibility field. The minimum declaration and known development basis do not establish compatibility acceptance for the current candidate or later hosts. The bundled SandIAM `0.8.4` artifact remains bound to SHA-256 `1a3352a6a5556bb8f605e783dd8f66981d5d3436d0af33c053fcb618424e9366`; its artifact identity does not set a compatibility ceiling. SandAI requires installed SandIAM >=0.8.4. The current SandPackage installer compares the installed plugin version with the bundle version for exact equality and rejects a later installed SandIAM version; this supplier limitation remains unresolved and is not removed by the SandAI dependency declaration changes. Earlier host/package results remain historical.

### Meeting execution setup (unpublished source candidate)

After normal installation or upgrade, open **开始使用 SandAI → 会议执行设置**. Select the SandIAM environment and its published chat route, then explicitly choose the supported planning method. The `0.1.12` source candidate adds local Neuron `DelimiterTextSplitter` planning; it makes no tokenizer requests and reports token counts as not measured. Existing remote tokenizer registrations retain their original method. Enter the operator-confirmed context/input limits, official capacity-document URL and the SHA-256 of that document's actual content; remote planning also needs a tokenizer request limit. Unknown capability values must be verified by the operator; neither a model alias nor a URL hash is capacity evidence. Saving stores a disabled registration. Review the saved values and explicitly enable execution; revoke disables new summaries. A published route/revision change requires fresh confirmation and registration. The interface reports missing execution schema or unsupported planning methods instead of silently enabling them. This setup makes no remote capacity probe or model call.

Migration `124` persists the registration through normal install/update/uninstall; local planning additionally requires migration `127`. New tasks freeze `meeting_capacity@2` with exact route, registration revision and trusted environment ownership; historical `@1` tasks retain their original frozen/configuration recovery behavior. Local planning evidence does not measure tokens or reduce the separate conservative financial reservation. Source authorization, complete coverage, limits and immutable fees remain execution gates. The setting is not an IAM grant. The `0.1.12` local variant is a source candidate pending normal installation, page and business acceptance. Details and exact endpoints are in the bundled [API contract](plugin/sand-ai/docs/development/sand-ai-api-contract.md#会议执行设置2026-10-04-冻结候选未安装).

The `0.1.5` candidate formally registers migrations `120`–`123` in order: meeting terminal transitions, the terminal-step alias repair, private Agent stream events, and Skill draft revision constraints. This includes the forward repairs required by the current meeting and Skill code; installing `120` without `121` would restore the known terminal-step alias collision. All migration files and their historical checksums remain unchanged, with matching uninstall cleanup. Upgrade through SandPackage's normal versioned flow before enabling the affected features; no runtime auto-migration or adoption of unregistered candidate tables is performed. The fixed `0.1.5` artifact passed normal upgrade, uninstall and fresh installation on an isolated consumer using a temporary host `TerminalRunner` patch. That result does not establish public default consumption or complete affected business acceptance. The fixed `0.1.4` lifecycle artifact remains separate.

The `0.1.4` candidate completes the meeting-table and function cleanup inventory and uses Neuron's numeric validation rules. The lifecycle owner has verified normal upgrade to `0.1.4`, uninstall, fresh installation and a second uninstall on the isolated host, using a temporary host `TerminalRunner` patch. This verifies that patched consumer path; it does not establish that the public SandPackage RC default flow passes without the patch, nor does it verify the `0.1.5` candidate. The fixed `0.1.4` artifact and its historical digests remain unchanged.

SandPackage checks the exact declared version and does not accept a compatibility range. The retained source-only `0.7.6` bundle is a historical upgrade input and is excluded from the current installation ZIP; it does not satisfy the current candidate. Upgrade SandIAM through its normal installer before installing the current SandAI candidate.

Install the complete package through SandPackage, review the service-catalog preflight below, and have the SandIAM administrator grant the required service actions to the application's workload client. A successful installation must publish the declared plugin, application, configuration and admin UI payloads; seeing a ZIP or passing a source check is insufficient. Existing tables require the explicit adoption path below. There is no verified end-to-end first-use recipe for this candidate yet; model/provider credentials come from the provider operator and IAM credentials and grants from the SandIAM administrator. Do not treat this checklist as an installation or model-invocation acceptance record.

Run these commands as the operator who owns the installed host. `chmod go-w .` removes group/other write access from the current `server` directory before the private lock is used; it does not change configuration contents.

The host installer currently creates its environment configuration as 0644. `protect-config` explicitly preserves its contents and tightens only the current operator’s owned, ordinary file to 0600, with a private original backup; unsafe ownership, links or conflicting backups are refused. It generates no keys and restarts no service.

For a complete candidate ZIP that includes `sand-ai:security`, finish formal installation first, then run the following from that installed host's `server` directory as its operator:

```sh
chmod go-w .
php webman sand-ai:security protect-config
php webman sand-ai:security initialize
php webman sand-ai:security status
```

Initialization privately saves only missing SandAI internal encryption/HMAC material; it does not generate a provider API Key or IAM credential. `status` must report internal material `ready`, which does not establish model, IAM or business readiness. After a changed configuration, the operator must reload the host through its existing service lifecycle before configuring models. Existing valid material is preserved. Incomplete or invalid material, historical business data, concurrent configuration changes or unsafe file permissions stop initialization: retain the original configuration and restore the same instance's backup rather than replacing keys. Upgrades and uninstall preserve the material needed by retained encrypted data; removal requires a separate data/backup cleanup decision. This CLI is a source candidate, absent from the old public preview, and has passed this CLI path on an isolated POSIX host: explicit permission protection, fresh initialization, unchanged repeat, and refusal of partial or historically required missing material. The temporary consumer used the recorded SandPackage launcher patch; this does not establish an unpatched package lifecycle or full product acceptance. Windows ACL initialization remains unverified and is refused.



The external-tool bundle has its own first-use gate. After normal installation of
a candidate that includes these commands, run `php webman sand-ai:security
status-tools` from the owned installed `server` directory. If all tool material
is absent and there is no tool connection, operation, run or receipt history,
run `php webman sand-ai:security initialize-tools`, then run `status-tools` in
a fresh CLI process and reload the host through its normal lifecycle. Valid
existing material is preserved. Partial or historical missing material requires
the same instance's original backup; initialization refuses replacement.
The private tool backup is `.env.sand-ai-tool-security-original`. Tool readiness
is reported separately and does not establish endpoint, IAM or model readiness.
These added commands are source candidates until the normal installed CLI path
has been verified; they do not initialize a provider Key or an IAM signer.

### Skill 历史费用（0.1.14 未公开候选）

消费后端用原测试或运行的稳定业务键直接读取历史费用，并通过当前 `sand_ai.usage.read` 校验原消费者与运行归属。费用入口独立于成果读取；未知费用保持 null，无关联调用返回 404。正常升级加载后再验证真实调用，路径与参数见 [API 契约](plugin/sand-ai/docs/development/sand-ai-api-contract.md#scoped-skill-历史费用只读入口2026-10-04-冻结候选未安装)。

### 受管文档工具（0.1.13 未公开候选）

通过正常升级加载迁移 `128` 后，受管 MCP 接受 `2025-11-25` 与 `2025-06-18` 两个稳定协议；新连接冻结实际协商版本，旧快照按原版本恢复。后台仍使用现有连接创建、发现和发布入口。新本地工具为 `external_document_search` 与 `external_document_fetch`；实际远程 schema 必须匹配服务端登记，来源返回受控引用和实际内容摘要。原 `external_search` 配置及摘要保持。

宿主操作员可成对配置私有进程变量 `SAND_AI_TOOL_PINNED_HOST` / `SAND_AI_TOOL_PINNED_IP`，与 Provider 的变量分开。只有具时间和来源的公开 DNS 证据可作为配置依据；地址仍须经过公网 HTTPS/443、TLS 校验。非法或不完整配置拒绝请求，不自动换地址。保存后通过宿主正常生命周期加载，应用请求不能传 pin。工具密钥 CLI ready 不代表 Worker 已加载或工具业务通过。详情见[API 契约](plugin/sand-ai/docs/development/sand-ai-api-contract.md#managed-document-tool-adapters)；候选目录和文档读取实证不替代正常安装、SandIAM 授权、计量与恢复验收。

`host-payload.json` declares every packaged `app/` and `config/sand_ai_*.php`
file with its SHA-256 digest. It is an input for a host installer that supports
per-file ownership, collision checks, upgrade recovery, and uninstall. The
current SandPackage source candidate implements this contract, but the fresh
SandAdmin host at `957ee2c2abb256d5112e3149dda7c16278e612d0` still ships
an installer without host-payload support. A ZIP and a source-level plan do not
prove that host has installed the application services.
Before installation, pin the SandAdmin and SandPackage revisions and verify
the exact SandAI ZIP SHA-256 against the selected package record. Use the
ordinary package upload/install lifecycle in an installer that supports
`plugin_dependencies`, `service_catalog`, and `host-payload.json`. Metadata
validation alone does not prove dependency compatibility or real host
installation.

Before installing into a database with existing `sand_ai_*` tables, review and adopt those tables explicitly. Fresh installation refuses to overwrite them. Database migration, service restart, and uninstall are separate operator actions.

The proposed `0.1.2` upgrade adds migration `101` for material output-failure evidence. Back up the database and installed files, then follow the current package's instructions through SandPackage's formal upgrade flow before enabling the new material runtime. Installation, upgrade, failure recovery and uninstall for this candidate have not yet passed lifecycle acceptance. Do not treat a same-version repack as an upgrade.

For the proposed `0.1.3` meeting-summary candidate, ask the host operator to register the exact published deployment route in `config/sand_ai_meeting_summary.php`. The operator supplies its route snapshot SHA-256, verified official capacity-source URL and content SHA-256, context/input limits and tokenizer request limit; callers cannot provide these settings. Only the explicitly registered direct BigModel `glm-5.3-flash` route is supported by this candidate. An empty registration rejects new summaries; missing migration `102` or its immutable ledger prevents new tokenizer calls.

Back up the database and installed files, complete the formal SandPackage upgrade and verify migration `102` before enabling the new meeting worker. After a committed upgrade, retain the new ledger and call evidence when rolling back runtime files; do not run an older migration runner or uninstall script against that schema. Tokenizer amounts remain unknown (`unpriced`), not zero. Installation, upgrade, recovery and long-meeting runtime acceptance remain pending.

The resumable long-meeting candidate additionally requires migrations `109`, `111`, and `112`, in that order. It is disabled by default: after target-host acceptance, the operator sets `durable_worker.enabled` to `true` in the installed `config/sand_ai_meeting_summary.php` and loads that configuration through the host's normal process lifecycle. The existing plugin task worker runs the application service; no second worker is required. Provider configuration and IAM grants remain in their existing administration screens.

Submit a completed recording through the existing meeting-summary action. New tasks use the enabled execution mode; older tasks retain their recorded mode. Poll the existing task status: a successful task returns the summary and source references, while queued or running means preparation or execution is continuing. If the switch is disabled, new submissions keep the earlier path and resumable tasks cannot continue; restore the accepted configuration before retrying them. The candidate accepts at most 10,000 transcript segments within the audio producer's existing 8 MiB transcript limit. It does not impose a meeting-duration promise or treat tokenizer charges as zero. Full installation and runtime acceptance of this mode remain pending.

For the reviewed pre-unification 18-table `sandai` schema, the package includes
`plugin/sand-ai/tools/archive-legacy-sandai.sql`. The optional builder prints
that reviewed SQL without connecting to PostgreSQL:

```sh
php plugin/sand-ai/tools/build-legacy-adoption-sql.php > sand-ai-archive.sql
```

The SQL candidate only archives the 18 legacy tables and their owned sequences
in the existing `sandai` database. It does not install the new schema. Review
`docs/legacy-adoption-20260925/README.md` and obtain explicit migration and
service authorization before execution. After archival, install SandAI once
through the ordinary SandPackage fresh-install lifecycle, which executes
`install.sql` and publishes the plugin, application services, configuration,
and admin UI. The old application, environment, and credential rows remain
archived; they do not become SandIAM grants or active SandAI credentials.
Until the installer and real host complete this sequence, installation is
unverified.

For the exact public `0.1.0` preview, a separate source candidate uses the
explicit `--public010` branch. It accepts only the published ZIP SHA-256 and
its exact 14-table PostgreSQL profile; it does not adopt the 18-table snapshot
above, pre-repair v093, or arbitrary missing-ledger databases. The default plan
prints safe JSON and never connects to a database:

```sh
php plugin/sand-ai/tools/build-legacy-adoption-sql.php --public010
```

`prepare` generates SQL into a new private output file; `bridge` exports the
exact candidate `update.sql` for comparison. The actual upgrade follows
SandPackage upload → install upgrade → SSE execution. Before SQL execution, the operator needs protected database, installed-file and
private-storage backups, a rehearsed restore, stopped legacy consumers, and
explicit database authorization. Current IAM access must be provisioned through
SandIAM; old grants, credentials and default-zero usage are not converted into
current authorization or measured fees. See [the public 0.1.0 bridge
instructions](plugin/sand-ai/docs/development/sand-ai-plugin-package-contract.md#公开010升级桥接源码候选)
for exact scope, input provenance and recovery. This bridge is unpublished
source and has not been run; the public preview still has the plugin-only layout.


With stable SandPackage >=0.2.7 that supports `plugin_dependencies`,
`service_catalog`, and `host-payload.json`, installation verifies the pinned
SandIAM bundle, installs it first when absent and its database is fresh, then
checks the installed version and previews the declared service actions. It then
installs SandAI and registers the `sand_ai` service and all actions declared in
`config.json` through SandIAM's official catalog port. Registration creates
missing entries; disabled services or actions block installation and are never
restored automatically. It does not issue grants. An authorized SandAI
administrator can check
`GET /app/sand-ai/admin/setup/service-catalog/preflight` after installation.
The separate `POST /app/sand-ai/admin/setup/service-catalog/register` endpoint
remains available for an explicit catalog repair on installations that did not
use this installer. Skill and audio have separate preflight and registration
endpoints for the same purpose.
The outward model catalog requires `sand_ai.model.read` invocation authorization
against SandIAM's current workload client reference before listing models.
Managed external tool connections require an operator-verified `data_class`.
New runs freeze that class from the immutable connection revision; legacy
unclassified revisions cannot run. Dispatch, replay, and result reads require
SandIAM invocation authorization against the persisted tool run. Authorization
is revalidated before each external request and before a result is returned.
The read-only preflight reports whether the SandIAM port is available and
whether the service and each declared action is missing, active, or disabled.
It permits explicit registration only when none is disabled. It does not prove
that the catalog has been registered or issue a grant. The SandAI onboarding
page exposes both steps and directs administrators to SandIAM when a service
or action is disabled. Real host acceptance remains pending.

Published Agents now bind a same-environment published knowledge alias through
`knowledge_base_code` in the administrator draft API. Publishing requires this
binding. At dispatch, the application searches only its current published
version and freezes its publication ID, version ID, and source scope in each
task. Execution, channel history, and result reads reject a changed version;
private file access is rechecked before model delivery. Published Agent and
channel callers need `sand_ai.knowledge.query` in addition to
`sand_ai.agent.submit` and `sand_ai.agent.run`. The worker authorizes the
pinned publication through SandIAM before loading excerpts and revalidates
that grant before model dispatch; result reads and Feishu delivery obtain a
fresh publication authorization.
The Agent administration page selects a published knowledge alias available to
the chosen application environment and saves it in the draft. Publishing still
rechecks the binding on the server. Migration 054 is source-only and has not
been run against `sandai`.

The SandAI Agent page now has four tabs: published Agent configuration, channel binding,
published-version trial, and the legacy per-request trial. Administrators need SandIAM
environment and workload-client list permissions plus SandAI definition/channel read or
management permissions for the corresponding controls. Channel bindings name an existing
SandIAM application and workload client; the page does not create SandIAM credentials or grants.
The published-version trial uses a caller-supplied SandIAM workload context. Channel
message entry can be called by an adapter that already verifies the platform webhook and maps its
sender. The Feishu v2 text webhook at `POST /api/sand-ai/v1/channels/{code}/feishu/events`
validates the Feishu signature and token, obtains a SandIAM workload context in the
host process, and submits a channel task. Administrators can configure
`encrypt_key`, `verification_token`, `workload_credential`, `app_id`, and `app_secret`
through the channel binding page. These values are encrypted in a separate
write-only credential table using a key derived from the SandAI IAM context
keyring; the page shows only whether managed credentials exist. A callback code
can have one managed binding across environments. Existing
`SAND_AI_FEISHU_CHANNELS_JSON` entries remain a fallback for channels without
managed credentials. Set `SAND_AI_FEISHU_WORKER_SOURCE_IP` to the worker address
allowed by the SandIAM service grant. The callback records an encrypted chat target
and acknowledges submission. A separate worker sends the terminal result after
rechecking SandIAM authorization, channel and Agent revisions, and private sources.
It sends a notice in place of results requiring review. Retries retain one Feishu
message UUID per task. The WeCom self-built application text adapter has source
for signed encrypted callbacks, managed credentials, and asynchronous replies.
Its callback is `GET|POST /api/sand-ai/v1/channels/{code}/wecom/events`.
The channel binding page saves the callback Token, EncodingAESKey, enterprise ID,
AgentId, application Secret, and SandIAM workload credential as one write-only
set. `SAND_AI_WECOM_CHANNELS_JSON` remains a fallback for channels without managed
credentials. Set `SAND_AI_WECOM_WORKER_SOURCE_IP` to the address allowed by the
SandIAM service grant. Replies with an uncertain send result stay in `uncertain`
for operator verification and are not retried automatically. The page separately
shows verified callbacks, accepted messages, recent delivery state and
controlled errors without message text or recipient IDs. A confirmed platform
send followed by an uncertain local status write also stays out of automatic
retry. DingTalk remains pending. WeCom migrations 078–080, a running installed host,
and real platform callbacks and delivery remain separate acceptance steps.

Agent callers need both `sand_ai.agent.submit` and `sand_ai.agent.run` grants.
The submit grant authorizes the persisted task with no data class. At dispatch,
SandAI authorizes `agent.run` against each file actually retrieved before its
excerpt reaches the model; an empty result authorizes the task itself. SandIAM
counts each new authorization against the corresponding grant's invocation
quota. The original task grant and each source grant are revalidated before
the model request; AI tasks also recheck their frozen source facts there.
Idempotent result replay revalidates the original task grant. Result reads
authorize the task and its persisted sources again.

Public retrieval requires a `sand_ai.retrieval.search` grant. SandAI authorizes
the current workload client through SandIAM before searching and revalidates
before returning, including for empty results. It also authorizes every
returned private file through SandIAM before sending its excerpt.
Matches with different data classes are rejected together. An empty result
contains no private source and consumes no file authorization quota.

Reading metadata or deleting an existing private file requires its respective
`sand_ai.file.read` or `sand_ai.file.write` grant. SandAI authorizes the
persisted file through SandIAM before either operation. Upload has no file ID
yet: it first authorizes the active workload client resolved by SandIAM from
its own records, before writing to private storage. An unavailable or revoked
client or grant closes the upload path.

Invocation status and per-invocation cost reads require separate
`sand_ai.invocation.read` and `sand_ai.usage.read` grants. Each read authorizes
the persisted invocation and dispatch ownership through SandIAM before
returning a result. Historical invocations without verifiable ownership fail
closed. When no immutable usage event exists, the single-invocation cost API
reports the durable dispatch state with unknown amounts and tokens left null.
It does not turn a provider failure or a missing event into a zero-cost claim.

Knowledge base drafts and publication require every source file to retain a
verifiable upload owner. Sources in one version must share organization,
application, environment, and data class; different workload clients of that
application may contribute. The administrator publishes a consumer binding
through `/app/sand-ai/admin/knowledge-bases/publications/publish` and can revoke
it through `/publications/revoke`. The consumer environment gets a unique query
code. A caller also needs the `sand_ai.knowledge.query` service action grant in
SandIAM; SandAI resolves the published version and its source data class through
SandIAM's official invocation port before returning excerpts. Historical
versions without a verified source scope are unavailable to this API. Migration
038 and real PostgreSQL/SandIAM/HTTP behavior still require installation and
acceptance against an authorized host.
The SandAI knowledge page lets administrators select environments from SandIAM,
choose an eligible published knowledge base, and inspect, publish, or revoke
consumer bindings. SandIAM environment-list and SandAI publication-list
permissions are required for the page to load.

Personal Skill has a separate administrator action: check
`GET /app/sand-ai/admin/setup/service-catalog/skill/preflight` and explicitly call
`POST /app/sand-ai/admin/setup/service-catalog/skill/register` with an empty body.
This declares only `sand_ai.skill.run`; it does not issue a grant or prove a live
Skill invocation. The onboarding page exposes this second step separately.

Audio transcription has its own administrator action: check
`GET /app/sand-ai/admin/setup/service-catalog/audio/preflight` and explicitly
call `POST /app/sand-ai/admin/setup/service-catalog/audio/register` with an
empty body. This declares only `sand_ai.audio.transcription`; an administrator
must then grant the application and workload client access in SandIAM. Audio
submission authorizes the persisted private file, queued execution revalidates
the encrypted original context before dispatch, and task reads and manual
retries require a current grant. Catalog registration and local checks do not
prove a live audio invocation.

### External audio prerequisite

OpenAI-compatible external transcription uses the existing
`SAND_AI_FFPROBE_EXECUTABLE` on the worker host, even when no budget policy is
configured. Its default is empty. Set a trusted absolute executable path that
the worker account can read and execute; the external path does not require the
local whisper.cpp executable, model, or FFmpeg converter. Before each new
transcription, SandAI reads the private recording's container duration and
first audio track timeline, then freezes the verified duration before sending
it to the provider. Missing probe configuration yields
`SAND_AI_AUDIO_NOT_CONFIGURED`; an unreadable or invalid duration yields
`SAND_AI_AUDIO_UNAVAILABLE`, without a provider request.

The metadata state `adapter_kinds.external` also depends on the existing
external and adapter enablement settings. Correct the worker configuration and
read its configuration state again; a configured probe alone does not grant
SandIAM access or publish an audio route. For an uncertain submitted task,
read the original task/request key instead of automatically sending another
request. An unsent prepared task may continue through its existing execution or
retry boundary after the administrator fixes the configuration; reading metadata
only confirms configuration. Existing frozen requests, receipts and costs retain
their original facts. These are source behavior and configuration instructions; no probe,
installation or runtime acceptance was performed for this change.

For local whisper.cpp transcription, configure
`SAND_AI_WHISPER_CPP_EXECUTABLE`, `SAND_AI_WHISPER_CPP_MODEL_PATH`,
`SAND_AI_FFPROBE_EXECUTABLE`, and `SAND_AI_FFMPEG_EXECUTABLE` as absolute paths
on the worker host. The configured executables must be readable and executable
by the worker.
SandAI probes the complete private audio file before transcription, records
its duration rounded up to milliseconds for usage, and rejects a file whose
duration cannot be verified. FFmpeg converts the private recording to temporary
16 kHz mono PCM WAV for whisper.cpp and removes the converted file after the
attempt. An empty valid transcription leaves a blank full-duration segment for
administrator review. For local transcription, a BCP 47 language variant uses
its primary language code in whisper.cpp, and nonempty glossary terms are
passed as initial prompt hints; recognition of every hint is not guaranteed.

Task read, cancel, and retry operations each authorize the persisted task ID
through SandIAM using facts checked against the task's original resource.
Responses revalidate the grant before returning task state or results.

### 材料冲突字段定位（0.1.15 未公开候选）

材料成果页把冲突标签和值定位到已封存的原文件字段，并提供对应来源。定位证据不足时显示未确认；定位已核对也仍须业务核实。升级不重写历史成果或费用。字段和来源规则见 [API 契约](plugin/sand-ai/docs/development/sand-ai-api-contract.md)。正常安装、现有成果读取和页面验收另行确认。

### 工具及结构化聊天容量登记（0.1.17 未公开候选）

预算启用的工具或结构化聊天部署须在新的正常部署修订中显式选择受管 `bigmodel_flash_chat_wire@20261004` 容量证明。它核对实际 SDK 最终正文与已发布路由，不删除工具或 schema 字段，也不以本地分词降低官方完整容量预占。旧部署、Skill 版本和已发生费用保持原快照；结构化载体的财务准入不代表供应商支持该功能。配置字段和恢复边界见 [API 契约](plugin/sand-ai/docs/development/sand-ai-api-contract.md)。正常升级加载及新部署发布后再核实际报价与业务结果。

### 首次开通输入修正与知识来源（0.1.18 未公开候选）

明确本地输入错误会给出中文修正提示；只有本次保存未提交的可信证明才允许修改并重新保存。已有结果不明的保存或验证须保留原参数与幂等键，先查原记录。聊天验证复用官方 Neuron4，沿原受控网络与计量恢复边界。查询引用的知识来源可按公开知识版本与来源块读取当前已发布正文，授权及内容摘要在返回前再次核验。正式字段和恢复限制见 [API 契约](plugin/sand-ai/docs/development/sand-ai-api-contract.md)。本候选不代表供应商验证、实际页面或知识业务验收已通过。

### 机器人只读工具与配置回退（未发布后继候选）

在机器人定义中选择最多两个已发布的只读工具版本与业务用途；知识检索仍保留。新版本冻结工具绑定，外部工具沿用独立的 SandIAM 授权、回执与计量。带只读工具的新任务最多四轮模型调用，外部请求未定价不表示免费；启用了向量索引的检索还须另核 Embedding 费用。已知回执恢复与未知派发分别处理，不能借手动重试重复发送。

“版本”入口查看已发布配置并执行明确回退；回退重新核对当前模型及知识别名，不恢复旧任务或历史知识内容。任务显示其实际冻结版本，历史读取继续核对当前父任务与工具读取权限。正常升级须包含迁移129。源码、整包、加载、模型和工具业务结果分别验收，详见 [API 契约](plugin/sand-ai/docs/development/sand-ai-api-contract.md)。

### 本地会议结构化输出（未发布后继候选）

选择已发布且显式配置结构化输出的聊天部署，重新确认当前路由的本地会议执行登记后，新任务才能冻结结构化输出契约。模型正文由官方 Neuron JSON 提取器和原业务来源校验共同验证；失败不隐藏重试。历史任务保留原协议和费用。该候选尚待统一包、正常加载与新会议业务验收。

### 模型验证费用（未发布后继候选）

后台费用页的“模型验证费用”单独显示平台管理员验证，不计入环境业务总额。正式部署验证沿调用开始时真实价格记录；临时连接尚无正式模型和价格，保留真实 Token，金额显示未知。旧验证仍标记未追踪，不补写账单或将未知填零。查看入口使用现有用量查看权限，摘要使用现有用量汇总权限；正常升级须包含迁移130，加载与实际验证费用验收另行记录。

### 请求费用同步（本地后继候选）

后台费用页的账单同步按已配置服务商选择协议。OpenAI 组织 Costs 使用专用账单管理员凭据，仍按汇总记录展示。实际模型连接指向 OpenRouter 官方 API 时，先查询凭据状态，页面说明会切换为使用原模型连接读取已记录调用的费用，无需另存账单 Key。同步使用 UTC 账期；原调用 ID、连接、账户、模型、计量与冻结金额都能核实且一致时才自动核对。连接改变、非文本/BYOK、未知 native Token 或超出精确金额范围会拒绝，不把未知填零。

请求费用记录不证明已退款；退款仍须同一调用的已核对账单、私有凭证和明确人工审核。此候选尚未公开发布，受控行为验收与真实厂商账单、现金准确性分开记录。字段与限制见 [账单契约](plugin/sand-ai/docs/development/sand-ai-api-contract.md#账单管理凭据保存原号恢复原-41)，公开升级记录仍沿用 [现有 Wiki](https://github.com/supdger/sandadmin/wiki/plugin-updates)。


### 人工账单原件重导入（本地后继候选）

在费用页“导入账单”选择服务商，上传其原始 CSV、PDF 或非宏 XLSX，再逐行填写金额、数量、币种、UTC 时间窗及原件位置，例如“用量明细!第2行”。附件保存在私有存储，不自动解析或执行工作簿内容。重导同一原件时沿用完全相同的定位：事实相同复用原行和审核，事实不同拒绝整批，不覆盖历史。

原件摘要与人工定位只用于重复识别，不证明厂商账户或付款。账户及逐请求关联须在人工审核中核查；汇总账单、套餐扣量和未出账金额保持原粒度，不能摊成单调用现金。没有原件绑定的旧 JSON 导入仍不具跨批事实去重依据。正常升级须包含迁移155（来源绑定）和156（原始XLSX的MIME字段约束），当前仅本地候选，尚未公开发布。完整字段见 [账单契约](docs/development/sand-ai-api-contract.md#人工账单原件与跨批次重导入本地后继候选)，升级说明沿用 [现有 Wiki](https://github.com/supdger/sandadmin/wiki/plugin-updates)。

### 北大法宝案例候选（源码增量）

已有可选法规适配增加`pkulaw_case_search`：使用实际订阅的search_case schema、沿现受管MCP发现/发布后，以query/limit映射text/size检索。默认仍关闭，不选择或开通厂商。候选只保留案号/法院/日期/案由等实际元数据、原平台链接和返回内容摘要哈希；不保存判决段落、不确认法源、裁判效力或全文版本。配置沿`config/sand_ai_pkulaw.php`，详情见[案例候选契约](plugin/sand-ai/docs/development/sand-ai-api-contract.md#pkulaw-case-candidate-adapter)。本次只交源码，未业务调用、安装或运行验证。


## 独立民事起诉状草稿（SOURCE候选）

法律能力新增 `complaint_drafting` 发布配置，以同一次已授权私有材料、确认输入和固定模板产生待律师复核的完整民事起诉状；旧诉讼准备提纲保持原语义。来源及版本不足明确留空待核，律师保存文书由消费应用现有版本服务负责。详见[API契约](docs/development/sand-ai-api-contract.md#独立民事起诉状草稿62-source2026-10-06)。必要profile迁移135仅保存源码，未执行升级、配置发布、模型/法库调用或业务验收；6.2仍未完成。


## 研究争点与检索式规划（6.2源码增量）

法律分析配置新增可明确发布的 `legal_research_planning`，复用现TaskGateway及Neuron Workflow，供Lawyer冻结案件事实后的法规/案例双路候选检索编排。正式当前环境工具版本GET只读已有发布与MCP schema绑定；不执行检索。候选始终等待人工来源确认，详见[API契约](docs/development/sand-ai-api-contract.md#冻结事实争点规划与双路检索编排62-source2026-10-06)。136迁移和安装升级入口仅源码，未执行配置发布、模型工具、数据库升级或业务验收，6.2不据此标完成。


## 裁判分析与救济模板草稿（6.2源码增量）

法律分析管理员配置新增三个独立发布模式：`judgment_remedy_planning`、`judgment_remedy_analysis`、`judgment_remedy_drafting`。Lawyer分别消费真实判决/前案规划、人工法源后的完整裁判包，以及原分析正文与私有模板草稿；均走现TaskGateway/Neuron，不自动救济或提交。详见[API契约](docs/development/sand-ai-api-contract.md#原裁判分析包与救济研究模板草稿62-source)。139和安装/升级入口仅源码并保此前138，不执行数据库升级；普通旧16k与旧输出契约保持，实际profile、来源许可及业务验收仍单独判定。


### 已冻结请求帧与header（未运行示例）

两代均 `POST /api/sand-ai/mcp`，请求header为 `Authorization: Bearer <SandIAM工作负载调用上下文>`、`Content-Type: application/json`、`Accept: application/json, text/event-stream`。此处仅占位符，不提供或记录真实凭据。

现代请求必须带 `Mcp-Protocol-Version: 2026-07-28`，且header与正文协议版本一致；header单独不足以选择现代协议，每个body的params._meta还须声明 `io.modelcontextprotocol/protocolVersion` 和 `io.modelcontextprotocol/clientCapabilities`，不带会话。每次请求的 `Mcp-Method` 必须与正文method一致；下列发现请求带 `Mcp-Method: server/discover`。发现示例：

```json
{"jsonrpc":"2.0","id":1,"method":"server/discover","params":{"_meta":{"io.modelcontextprotocol/protocolVersion":"2026-07-28","io.modelcontextprotocol/clientCapabilities":{}}}}
```

列工具将正文method及 `Mcp-Method` 同时改为 `tools/list`，其余同。下列只读任务调用须带 `Mcp-Method: tools/call`、`Mcp-Name: sand_ai_task_read` 和 `Mcp-Protocol-Version: 2026-07-28`；`Mcp-Name` 与正文params.name一致（此工具名可直接作header值）。123必须替换为该caller已有权读取的真实任务整数ID，不是业务幂等键：

```json
{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"sand_ai_task_read","arguments":{"task_id":123},"_meta":{"io.modelcontextprotocol/protocolVersion":"2026-07-28","io.modelcontextprotocol/clientCapabilities":{}}}}
```

握手时代先initialize：

```json
{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"sandai-caller","version":"1.0.0"}}}
```

取得响应`Mcp-Session-Id`后，每次带原Bearer、该session及`Mcp-Protocol-Version: 2025-11-25`，发送`{"jsonrpc":"2.0","method":"notifications/initialized"}`，再用`{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}`发现工具；tools/call同上name/arguments，去掉_meta。可能计费工具须按固定schema传原业务request key，恢复工具只读；会话和JSON-RPC id不代替业务号。

动作映射：task_read→sand_ai.task.read；workflow_source→sand_ai.workflow.read；audio_review_read→sand_ai.audio.transcription＋sand_ai.audio.review；knowledge_query/recover→sand_ai.knowledge.query；skill_run/recover→sand_ai.skill.run；tool_run→sand_ai.tool.run、tool_recover→sand_ai.tool.read。表述中的工具均带`sand_ai_`前缀，每次执行仍校验实际资源授权。


### 专用材料调用次数窗口（源码候选）

宿主操作员可通过sand-ai:provider-call-limit CLI为专用调用方建立固定次数窗口，材料分块、合并、跨文件复核与重试共享上限；未知调用停止后继，窗口到期或关闭不会解除限制。金额预算与IAM授权独立生效。正常升级须包含迁移157；尚未安装或运行验证。输入来源、操作与原号恢复见[API契约](plugin/sand-ai/docs/development/sand-ai-api-contract.md#专用调用方的共享材料模型次数窗口157源码候选)，公开升级说明仍沿[已有Wiki](https://github.com/supdger/sandadmin/wiki/plugin-updates)。
