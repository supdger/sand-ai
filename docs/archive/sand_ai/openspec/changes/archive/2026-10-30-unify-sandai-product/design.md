## 当前版本兼容规则（2026-10-11）

用户明确要求所有兼容声明与判断只设最低版本，不设上限。当前两份权威 `info.ini` 为 `support = ">=0.2.3"`，构包目录只声明 `host_min=0.2.3` 并省略 `host_max`。Composer 依赖采用现有已确定最低版本的 `>=`，不以 `^`、`~`、精确 SDK 版本或 major 白名单拒绝较新版本。下面历史开发基线、已验安装版本、冻结来源版本及 ZIP 版本／摘要保留原事实，均不能作为新的兼容上限；最低条件与实际业务验收继续分开记录。SandIAM 随包 `0.8.4` 与 SHA 是工件身份；SandAI 最低兼容要求为 `>=0.8.4`。当前 SandPackage 安装器对已安装 IAM 使用精确相等门槛的限制仍须由基础包修复，不能通过 SandAI 添加无效字段消除。

## Context

见 proposal。用户于 2026-09-18 确认统一产品，2026-09-24 清除旧 Goal 并要求创建新 Goal、拉取依赖、遵守 TypePHP 与研究 AGF PHP。用户随后明确 `/path/to/project/sand_ai` 为任务执行和隔离测试场、`/path/to/project/sand_plugins` 为插件源码统一管理位置。两个目录都有既有未提交内容，不能以 reset 或替换整目录消除冲突。

原消费者需求完整读取：`/path/to/project/lawyer/docs/sand-ai-consumer-requirements-v0.1.md`。其功能与权限边界继续有效，双阶段交付条款由本次用户决定替代。`docs/development/sand-ai-api-contract.md` 继续保存已冻结的 API 细节，新变更须更新受影响段。

2026-09-24 获取的来源：

| 依赖 | 已取得的固定源码 | 当前证据 |
| --- | --- | --- |
| SandAdmin | `supdger/sandadmin` / `e0c563a8fb9a0f872acbbe80ff45f8583ef1bbae` | 临时 checkout `/path/to/evidence/sandai-20260924-admin`；0.1.0，Composer 引入 sand-core/sand-package ^0.1 |
| SandIAM | `supdger/sand-iam` / `0239981af57155c67af321c4255a33609eb7fed0` | 临时 clone `/path/to/evidence/sandai-20260924-iam`；info.ini=0.7.3，支持 SandAdmin 0.1.x |
| AGF PHP | `kof21411/agf-php` / `aa848935a75d2454a7f7cde4cea103f8956d09e2` | 只读临时源码 `/path/to/evidence/sandai-agf-review-20260924`；未执行 |

上表为目标建立时的初查快照。后续按用户确认，`/path/to/project/sand_plugins/` 是所有插件的源码管理位置，SandIAM 以其中 `sand-iam/` 为当前候选源；该目录所在仓库 HEAD `61fd9b1d2692e27035cc33eb5d9c44ee17bdf561`，`sand-iam/` 尚有未提交改动，不能把 HEAD 当作完整候选身份。干净测试宿主从 SandAdmin main `957ee2c2abb256d5112e3149dda7c16278e612d0` 取得，并显式装在现有 `example_db` 数据库；SandIAM 0.7.3 本地 dirty-not-release 候选仅用于测试。实际安装证据及摘要见 evidence。

### 2026-10-03 正式发行依赖基线

用户明确“SandAI正式发布版本一定是在SandIAM的0.8.x之上”。下述依赖进展是当时的历史快照：共享宿主旧0.7.6仅保留历史运行证据；当时源码pin的0.8.3-preview是已验过渡候选，正式0.8.3 stable包尚待核实际ZIP摘要、接口和正常消费；当时开始分析SandCore/SandPackage0.2.x兼容，不能将tag视为消费成功。当前规则以 tasks.md「进度口径与交接」为准：开发与验收固定Core0.2.0／SandPackage0.2.1，源码实际固定正式IAM0.8.4，最低兼容声明>=0.1.0且无上限不替代当前宿主真实适配。IAM084已正常安装；固定SandAI016在独立本地Package021 Runner候选上完成正常Composer state4→1、卸载、全新安装和再次卸载。公开021的旧state4失败仍是历史/公开未修事实，不能作为本地已验候选的持续阻塞；该候选未提交或发布，业务与异常rollback/recover未验。

历史公开工件初查快照（后续已更新）：当时独占负责人安全 `PUBLIC-FOUNDATION02-STATE.json` 表明Core0.2.0 tag指向177a4566…45e5，但本轮exact Composer0.2.0尚不可解析、latest Release仍0.1.9；Package main481fc92e…901尚无0.2.0 tag/Release。已读源码分析显示Core安装器/载荷ABI未变、Package同低版仍拒，TerminalRunner/载荷/builder未变；这是分析事实，后续实际ABI报告及公开工件实机门待核。此约束不改正在验证的固定0.1.5包、authority support字段或当前安装数据，也不证明统一产品完成。

## Goals / Non-Goals

**Goals:** 一套产品、一个当前任务来源；可并行推进几条完整功能线，由主 Agent 控制在制数量；每条有持续负责人、配置/真实 API/页面/来源费用/失败恢复的完整完成标准、剩余问题和外部阻塞。小补丁是修复手段，不单独代表功能完成；向导配置在专业模块可见，旧资产保留并复核。TypePHP 可编译性从新增代码开始约束。

**Non-Goals:** 本轮不复制许可尚未确认的 AGF 源码，不采用 SQLite 或单 worker 全局会话；不扩大为任意系统命令执行平台；不代替律师确认、审批、外发和业务权限。

直接使用 Neuron AI 4 框架，已有对应通用能力须替换重复自研，覆盖各功能线，不限于 Provider 或材料三节点的表面接入；缺少的能力才补齐。IAM、计量费用、来源和持久状态仍由 SandAI 业务适配；确实不适合替换的部分先说明事实、影响和替代方案，由用户决定。此前排除 Neuron 的选择缺少当前版本验证依据，不能当作用户限制。当前隔离宿主的 Composer 声明和锁文件均固定 `neuron-core/neuron-ai` 4.0.2（source reference `cd0d3e48451a234de31d6fc64cc632e3f1fa3b91`），材料服务已通过 Neuron 真实调用 Provider，并把同一逻辑执行对应到 dispatch21、invocation21、receipt19 和 usage26。该历史调用成功，但 run11/task37 的材料业务来源校验失败，原失败证据保留。其后独立 run12/task43、sample14/task46 和 published run15/task47 已真实成功，run12/14 来源 API 与页面成功，run15 原键、来源 API、正式页面与刷新费用读取已成功，run16 跨文件核对在正常同任务定点重试后成功并完成两文件来源读取（具体原件见 evidence.md）；机器人 Agent、ToolNode 和摘要适配候选已合入隔离载荷，完整配置、真实 API／页面、费用来源和失败恢复尚未验收。普通 PHP 证据仅覆盖已运行路径，TypePHP 仍未验证；历史未接入快照保留，不据此宣称框架全面兼容。

2026-10-09 原4.5会议通用序列已SOURCE交付：MeetingSummaryRunner→NeuronMeetingSummaryWorkflow官方Prepare／Completion／Validation三Node；SDK fresh start(recoverFailed:false)，恢复不带sendcallback，unknown沿原receipt不再派发；原durable step／lease／request／seal、source与计费保持。八业务＋host／release十成员实交见 `tmp/sandai-source-resume-20261009/neuron-material-meeting-source-completion/delivery-integration/SOURCE-DELIVERY-RESULT.json`，无API改动、未运行。此仅会议切片；KnowledgeConversationSummaryGateway的独立摘要后继已5/5 SOURCE实交：KnowledgeAnswerController实际caller→KnowledgeConversationSummaryGateway→官方Summarization.generateSummary／formatMessagesForSummarization→formal ChatGateway一次派发；旧pending保原hash／messages／key，coverage／当前授权／计量保持，unknown不换号；见 `tmp/sandai-source-resume-20261009/neuron-runtime-source-completion/delivery-integration/SOURCE-DELIVERY-RESULT.json`。这不是借Agent摘要链推断，且运行未证。

材料的通用实现替换按实际调用分层核定：单文件 `MaterialAnalysisExecutor` 已进入官方 `Workflow` 的 Prepare／Completion／Validation 三 Node，厂商聊天由 `NeuronMaterialChatProvider` 直接继承官方 `OpenAI`，完整 JSON 提取使用官方 `JsonExtractor`，候选 schema／类型使用官方 `JsonSchema`、`Deserializer` 与 `Validator`。原文严格类型、精确字段、配置白名单、不可变来源、当前 IAM、租约、canonical DB 和实际费用是 SandAI 业务约束。跨文件复核此前仍在 Gateway 内顺序执行，现独立 8PHP 候选已把真实 InputLoader／CompletionClient／ResultWriter 接入官方三 Node，删除该重复顺序核心；fresh InMemory、maxSteps=3、recoverFailed=false，不启用 SDK resume 或外部效果自动重试。`receipt_only` 沿原回执业务路径处理，不能进入 Completion Node。候选语法和官方只读拓扑不等于加载或真实运行，旧 run15／16 不能证明新 ReviewWorkflow 成功；具体冻结与验证边界见 evidence.md。

随后 exact9 已交付并完整刷新隔离后台；新 run18/task55 真实执行两次文件分析和一次复核，三次均成功计量，但复核输出含契约外 `reason=single_source`，由严格业务校验终结为 `SAND_AI_SCHEMA_UNSUPPORTED`，没有最终成果。新冻结的材料 `review_output_strategy` 使用官方完整 JsonSchema instruction／JsonExtractor，或显式选择官方 ZAI JSON 对象／OpenAI JSON Schema 协议；对应内部输出合同仅用于新多文件运行，历史缺标记保持原快照与请求，不能重写旧回执来套用新策略。该输出合同与表单随后以 exact23 交付权威源码和指定消费宿主，并正常完整启动新的 HTTP／TaskWorker；当前只进入受影响表单验收，新增策略尚无真实模型运行证据，未修复或重放 run18。费用、失败页面、来源与分层交付证据见 evidence.md。

## Decisions

### 2026-10-03 当前开发基线与最低兼容声明纠正

用户确认开发阶段须实际适配固定当前宿主：Sand Core0.2.0／SandPackage0.2.1／正式SandIAM0.8.x，本候选精确依赖正式0.8.4。当前同固定包在独立本地Package Runner候选上已有正常安装／依赖完成／两次卸载及重装证据；公开版本修复交付和真实业务仍分别待验。先前083／preview equality及旧015／Core019仅历史证据。六个持续执行组及唯一公共契约／集成写者按 tasks.md 交接，不新建队列；具体执行仍受宿主、Key／云和真实消费者前提约束。

按SandAdmin仓库分发规范114–120，最低兼容声明与验收范围分离：本候选host_min=0.1.0，不设置host_max；运行两info.ini 使用合法INI表达式 `support = ">=0.1.0"`，解析后的app.support真值为>=0.1.0。没有真实宿主功能依赖证据时不提高原最低值，不用0.1.x|0.2.x等枚举人为收窄。官方builder第四参数是host_min，第五host_max省略；字段实际位于生成目录manifest.json的versions[0]，不塞入config.json或source release-payload.json的无效层。0.1.6仍未发布；当前宿主真实安装、生命周期及业务路径由独占验收另留证。

### 统一目录与状态归属

`sand_plugins/sand-ai/app/Application` 和 `Infrastructure` 承载共享 AI 业务/持久化，`app/Api` 承载服务入口；后台配置控制器位于同一权威包的 `server/plugin/sand-ai/app/admin`，前端为 `sandadmin-artd/src/views/plugin/sand-ai`。安装可携带的插件配置只登记指向应用 Controller 的对外路由和应用 Worker 进程；共享宿主 `config/route.php`、`config/process.php` 不承载 SandAI 专属登记。所有入口共享服务层，不再复制 domain/model。不为改名引入 `app/ai` 第二命名空间。

当前工作区负责实现验证与安装试验；所有最终插件源码直接交付 `/path/to/project/sand_plugins/sand-ai/`，不在两个仓库并行维护权威实现。既有发布树与测试场候选须做差异映射；统一发行必须包含实际 app 服务载荷，并验证安装器支持，不能只发布薄后台壳，也不能把运行服务继续复制为插件内第二实现。旧目录既有改动保留，逐路由切换并有回退记录。

### 长会议摘要全量覆盖（4.5 目标）

现有音频摘要以单次模型调用处理，长转写可能被输入限制截断。沿既有 `POST /api/sand-ai/v1/audio-transcriptions/{taskId}/summary` 与任务读取入口，把最多 10000 个冻结片段按可证明的输入预算确定性分块，逐块提取，再有界分层合并。`max_output_tokens` 限制每次调用。规划器版本、复核版本、转写摘要、节点输入摘要和模型目标随任务冻结；不能容纳完整片段时在派发前失败，不通过只取前段或压低覆盖率换取成功。`sand_ai.meeting_summary/1.1.0` 成功结果携带全部有文字片段的覆盖证明，每条陈述仍回溯原始音频片段。

保留现有 `ai_completion` 父 `TaskStep` 作为编排根，每个 chunk/merge 节点各有真实子 `TaskStep`，序号从 2 开始。`sand_ai_meeting_summary_node` 以任务、节点码和步骤建立唯一关联，保存冻结的范围、输入/输出私有 JSON、摘要、最后调用 ID；节点状态只由对应 `TaskStep.state` 表示。增量合并期间可有多个暂未挂父节点的前沿结果，最终成功前须恰有一个完整覆盖的根。父节点层级必须高于子节点、范围包含子节点且仅能为 merge，阻断自指和环。父步骤不额外计费。每个实际模型调用单独走授权、预算、派发、加密回执、不可变计量；调用引用在数据库中必须同时匹配任务、步骤、节点码及 `ai_task` 派发范围，费用读取也按此归属复核。未知派发先沿原请求号查证，不能自动重发。已封存节点在恢复时须校验输入和结果摘要，失败节点仅在确定未派发且原冻结输入不变时按现有重试语义继续。部分完成、失败、取消均保留已发生调用费用和覆盖进度，不产生伪完整最终成果。私有节点正文随原音频生命周期清理；任务读取只投影安全进度和费用。

### 身份切片优先

已核实最新 IAM 仍返回 `exp`、提供 `verifyForService` 和 `ServiceInvocationAuthorizer`，旧 SandAI 则读取 `expires_at` 并调用不存在的授权类。先修入口验签适配和测试，再接执行前授权与可信事实，不让一次入口修复被报告为整链完成。

服务端固定 `sand_ai` / `sand-ai`；从连接取得 IP，不能直接信任 X-Forwarded-For。显式注入/可追踪依赖优先，不新增动态类名工厂。请求号关联审计，业务幂等另用 operation_id。错误仅映射正式机器码，不转发 provider/数据库原文。

事实 resolver 从 SandAI 实际业务资源读取四级归属与 data_class；不得把请求或 context 原样作为授权事实。异步原 context 加密保留到执行前重验，300 秒到期关闭失败，超期体验与新业务意图分开。SandIAM 问题需最小重现后向提供方反馈，不能本地补平行 IAM。

2026-09-24 的正式端口核对进一步确认：`ServiceInvocationAuthorizer::authorizeInvocation` 接收原签名 context、固定服务/受众/动作、注册的 resolver code + resource key、可信 IP、独立 operation/request ID，成功返回 `authorization_id` 等事实及 replay 标记；拒绝抛正式异常。旧代码中的 `ServiceActionAuthorizer`、`authorizeServiceActionForClient`、`allowed/expires_at` 响应均不存在。原 context 在当前入口验签后丢失，普通 Chat 在旧结果回放前也未做执行授权。因此先将原文保存在请求局部的专用凭据载体并建立真实资源事实，再接同步调用及回放前授权；异步路径须同时完成加密原文保管和执行前 `revalidateInvocation`。`UsageAttribution` 只保留非秘密归属，不能当资源事实或原 context。现有 Invocation 无完整四级归属及数据分级，历史缺失事实需拒绝。不可给缺失的 `data_class` 猜默认值。直到真实 IAM/PG 验证，任何 object fake 不证明额度、撤权或审计整链。

AI Task 的 `source_block_ids` 在提交和 Worker 执行时均要解析到底层私有文件事实；四级归属必须与可信身份一致，所有来源文件的数据分级必须一致，并将该分级冻结在任务的结构化列和受控 input 中交叉验证。没有来源块时任务分级为 `null`；旧任务缺可信事实直接拒绝，不按空值回填。Agent 的 `retrieval.search` 在 Worker 阶段才选出来源块，任务级 `data_class=null` 不能代表这些文件已获授权；执行前必须先重验原签名上下文，再对每个实际命中的私有文件执行 SandIAM 资源事实授权，全部允许后才能把摘录放入模型输入。检索结果若跨分级或归属不一致，须在模型调用前拒绝；不得因任务级授权成功而跳过文件级检查。

Agent 使用两个独立 SandIAM 动作：`sand_ai.agent.submit` 授权尚未产生检索来源的任务（空分级），`sand_ai.agent.run` 在 Worker 对实际命中的每个文件授权；无命中时对任务授权运行。结果读取重新授权任务及持久化的来源。SandIAM 对每次新的调用授权按对应 grant 计额度，因此一次 Agent 运行命中多个文件会占用多次 `agent.run` 额度。

对外 `retrieval.search` 返回私有来源摘录前，也根据实际命中块和文件的持久化事实逐文件取得 `sand_ai.retrieval.search` 正式授权；跨归属、混合分级或部分授权拒绝时整个结果失败关闭。空命中不含私有来源，无文件级授权额度消耗。后续真实 PostgreSQL 验收需覆盖查得来源到授权期间的状态变化及撤权。

调用状态及单次费用读取以持久化 Invocation 与 Dispatch 为同一个 `sand_ai.invocation` 资源事实，分别要求 `sand_ai.invocation.read` 与 `sand_ai.usage.read` 正式授权。解析器核对组织、应用、环境、工作负载客户端、数据分级及执行来源；费用事件还需与已授权调用的四级归属一致。旧记录缺可信派发事实时拒绝，不以同环境或请求号替代所有权。

知识库创建环境是来源归属，一个 SandIAM 环境只属于一个应用；现有按创建环境过滤的查询无法实现跨应用共享。后续引入 SandAI 内容发布关系，以消费环境内唯一别名定位已发布知识库版本；该关系不复制 SandIAM 服务 grant。查询的正式资源事实使用消费环境的四级归属和从同一来源分级冻结的版本数据，仍由 SandIAM 校验 `sand_ai.knowledge.query` grant。后台发布前先验证所有来源文件的可信上传归属、同一组织/应用/环境及一致分级；旧文件缺事实、跨应用拼接或混合分级均拒绝。精确版本 Skill 消费也必须走该发布关系及当前正式授权，不能借创建环境查询绕过。

知识库发布关系表为 `sand_ai_knowledge_publication`，主键 `id`；消费环境内的 `code` 和 `(knowledge_base_id, consumer_environment_id)` 均唯一。`owner_environment_id` 指向资料所属环境，消费环境的组织/应用由 SandIAM 官方端口核实。迁移 `202609250038_knowledge_publication.pgsql` 同时给不可变版本加入来源归属摘要和 `data_class`，历史已发布版本缺摘要时拒绝服务，不回填猜测值。后台发布/撤销路由和权限以 API 契约 5.2 为准；对外路由保持 `/api/sand-ai/v1/knowledge-bases/{code}/query`，其 code 为消费环境别名。服务端锁定发布关系与版本到检索及返回前重验结束，撤销不能让已失效内容通过新查询。

知识库管理面板为来源环境的当前已发布版本增加独立检索试用。后台权限 `sand_ai:knowledge:query:test` 与消费应用 SandIAM grant 分开；服务端核对 SandIAM 环境、固定版本及来源归属后返回短摘录和定位。精确版本检索与公开查询使用相同的全文相关度及短语匹配排序，避免 Skill 或管理员试用按来源块 ID 截取首批结果。具体请求和返回字段以 API 契约为准。

### 材料整理配置的版本与归属（3.3 目标）

材料分析运行配置已在权威源码中改为按环境读取持久化的已发布版本；提交时沿用工作流运行快照，向导与专业模块共用草稿编辑组件。`config/sand_ai_material_analysis.php` 仅作为首次创建草稿的通用模板，不作为已发布运行配置。应用发起样本、样本证据发布与后台撤销已有源码；本任务指定隔离临时库已通过正式迁移入口 readback 119 项 applied，原校验和保持不变，这只证明该隔离载荷的迁移安装。新 profile4 的向导创建、专业编辑同一配置、sample14 试用、人工选样本发布与 published run15 正式调用已有真实证据；3.3 完整条款由负责人矩阵和主 Agent 判定，未据此宣称全产品安装或3.6整线通过。环境引用须经 SandIAM 正式端口核验，不在 SandAI 建应用、凭证或授权表。

材料样本走现有结构化工作流执行与计量链。当前按已获 `sand_ai.workflow.run` 授权的应用发起：应用在专用样本入口指定精确草稿修订，环境、组织、应用和工作负载客户端均来自 SandIAM context，费用记在同一应用/调用；后台管理员不冒充应用，也不签发平行凭证。服务端在运行的输入快照和执行快照固定 profile ID、精确修订号、配置摘要；发布前核对成功任务、成果和同一调用的不可变计量记录及应用归属。普通对外调用不能自报该内部标记。

配置由环境、code、语义版本和修订状态定位。权威记录归 SandAI 的 `sand_ai_material_analysis_profile`（主键 `id`，`environment_id` 为边界，`code` 与 `version` 在环境内唯一，`name` 为中文显示名）；041 创建配置表，后续 042 保存样本运行、成果和计量引用及发布/撤销时间，保留 041 原校验和。后台路由使用 `/app/sand-ai/admin/setup/material-analysis/profiles/*`，权限为 `sand_ai:setup:material-profile:read`、`:save`、`:publish`、`:revoke`；样本由应用按 SandIAM `sand_ai.workflow.run` 授权发起。草稿可编辑；通过同一材料处理链的受控样本试用后才能发布；已发布版本不可原地改写或删除，变更产生新版本，撤销后新调用拒绝。专业配置页与中文向导读写同一记录，不复制一套配置。对外普通请求只提交已发布的 code/version；服务端依当前授权环境解析配置并冻结完整 profile、输出 Schema 标识、文件和业务快照到运行记录。排队执行与定点重试只使用该运行快照，不回读当前草稿或新发布版本；结果字段继续指向受授权来源块。静态旧配置与历史运行保留兼容读取，但不能默认为新环境已发布或绕过样本试用。

### 材料逐文件结果与定点重试（3.4 目标）

**历史候选阶段说明：** 以下至“材料逐文件正文摘要版本”之前保留迁移043及后续逐文件／复核候选阶段的设计和当时未完成项；其中“当前”“尚未接入”“尚未启用”“仍待实现”指当时阶段，不代表2026-10-03的现载荷状态。原失败证据与阶段约束保留，当前运行范围见下方补记。

当前材料执行把固定文件集交给同一次模型请求，`file_states` 随父任务整体转移，不能证明逐文件部分成功。整批模式在标记成功前须确认模型成果的 `documents` 至少覆盖每个固定 `file_id`；同一文件可有多个文档条目，漏文件以受控来源错误拒绝，不把所有文件误标为成功。

真正的部分成功须为每个文件建立稳定的执行身份和尝试号，将已验证的文件结果、来源映射及对应的终端调用分别持久化。重试只读取原运行冻结的配置、文件版本和业务快照，只重做失败文件；成功文件的结果不可由重试覆盖或重复计费。每次实际模型派发、不可变用量事件和收据绑定具体尝试，父运行仅汇总且不再次计费；跨文件冲突/汇总如需模型调用，作为独立可幂等步骤记录。取消、撤权、Worker 崩溃及并发重试都不得把未完成文件报为成功，也不能让旧授权读取已保留成果。最终成果合并需保留原文件 `file_id`、来源块和尝试引用。数据库迁移、API 的部分成功状态与定点重试输入、真实计费/恢复测试完成前，不能以当前 `file_states` 声称已交付部分成功。

单文件成功证据由 SandAI 的 `sand_ai_workflow_file_result` 持有，主键 `id`；`workflow_run_file_id` 唯一且指向固定文件快照，`invocation_id` 唯一且指向一次真实终端调用，`attempt_no`、`execution_snapshot_sha256`、Schema 标识、已验证 `payload` 与摘要一并保留。字段来源归 `sand_ai_workflow_file_result_source`，以 `result_id` 和 `source_block_id` 关联，不存文件内容或授权密文；结果记录的 `field_source_count` 通过延迟约束与来源行数相符，两表写入后不可修改或删除，后续也不能给已封存结果补加来源。文件所属环境/组织/应用从运行与 SandIAM 事实取得，不接受请求提供的归属。迁移 043 仅建立这个成功证据载体；写入事务、部分结果读取、分文件派发、跨文件汇总及重试仍须后续实现和验收，既有整批运行不得因新表而改写。

单文件调用请求号固定为 `structured_{parent_logical_execution_id}_file_{workflow_run_file_id}_attempt_{attempt_no}`，派发逻辑执行 ID 由父执行 ID、固定文件和尝试号稳定派生；同一固定文件与尝试号只能复用原调用，新尝试必须显式增加尝试号。内部派发账本保留真实尝试号，发送模型前重新核对文件仍在当前运行、状态为 `running` 且尝试号一致；整批旧路径继续沿用父执行 ID 和尝试号 1。单文件结果写入器只接收内部 Worker 的租约和已验证的模型成果，在事务中锁定调用、派发、任务、步骤、运行与固定文件，核对运行冻结的四级归属、执行快照、成功派发和同一调用的不可变用量事件；实际进入提示词的来源块必须与固定 parse 当前块一致。结果、字段来源、该文件的 `succeeded` 状态及 `file_result:{result_id}` 加密收据确认在同一事务写入，重复写入只接受原调用、摘要与来源集合完全一致。收据清理须重新核对不可变结果、文件状态及调用关系；原整批 `artifact` 收据条件继续适用。父任务重试、失败或取消不得覆盖已有单文件成功结果及尝试号；未完成文件仍随父任务转移。父任务的派发重试与租约恢复检查只略过结果、文件状态、调用和尝试号一致证明已完成的单文件派发；其余服务商可见派发继续封锁自动重发，旧整批运行仍沿原规则。该写入器尚未接到 Worker；数据库迁移未执行，不能把代码路径当成已完成的部分成功。

单文件结果汇合按固定 `workflow_run_file_id` 顺序拼接已验证的文档、实体、事件、冲突及待核实项，逐类重算来源字段路径；来源块仍须属于该文件，文档只能指向本文件。最终落库事务要求运行明确冻结 `single_file_v1` 模式、父任务租约有效、所有固定文件均有对应的不可变成功结果，且文件状态、尝试号、调用、执行快照及来源块与结果一致；随后写入汇总成果和来源、将父任务与步骤同时置为成功。结果收据已由逐文件写入事务确认，汇总成果不冒充单笔 Provider 收据。此汇合步骤只组合已有事实，不额外推断跨文件冲突；跨文件综合需要另行受控执行并保留来源、调用与费用证据。最终落库器尚未接入 Worker，`single_file_v1` 模式尚未启用。已封存单文件成果的受授权读取路径已交入权威源码，真实 PostgreSQL/HTTP 验收仍待新模式运行后完成。

全部固定文件结果已封存、且单文件无需跨文件核对或多文件核对结果已封存时，若最终汇总前 Worker 失败或租约过期，父任务转入本地恢复队列，不再消耗 Provider 尝试次数；恢复先重验原 SandIAM 授权，再由持有有效租约的 Worker 核对全部结果与来源并仅提交最终成果。恢复任务的有效租约阻止第二 Worker 领取；到期后可重新领取，取消递增栅栏并阻止旧恢复者提交。当前放入权威插件的恢复源码只核对文件是否全部成功，尚未读取跨文件核对证据；需先按下一段修正，再接入逐文件 Worker 和真实 PostgreSQL 并发、撤权、取消验收，不能以纯策略测试判定业务闭环。

跨文件“同一事实”不能从当前成果的 `entity.type`、扩展字段 code 或自由文本事件自动推断：不同文件里的两个金额、日期或人员可能分别属于不同事项，按类型拼接也不能发现真正的矛盾。因此多文件 `single_file_v1` 在最终落库前增加独立的跨文件核对步骤，而不是让确定性汇合器猜测事实关系。它以已封存候选及其实际引用的来源块构造有界提示词；先重新核对文件归属、parse、摘要和原 SandIAM 授权，提示词超限时失败而不静默删减；再按独立请求号、派发账本、不可变用量和加密收据完成 Provider 调用。

模型只返回带来源和至少两个不同文件候选字段路径的待核实项，验证器从 `reason=conflict` 生成对应冲突项。核对结果单独封存且不修改任何单文件成功证据。最终落库器在一个事务中核对结果、原文件结果和父任务租约；受影响的单文件候选及其字段来源暂不纳入最终成果，其来源转入待核实项，避免最终成果同时保留互斥的 `confirmed` 值。事件候选按整个事件项排除，包含摘要、日期和两者的来源映射。未受影响候选仍可汇合，含待核实项时成果标记 `review_required`。单文件无需核对；多文件无核对证据不得成功，已封存文件在核对失败、取消或不确定派发时仍按授权可读。

恢复流程必须区分文件、跨文件核对和本地最终提交三个阶段：只有单文件或核对结果已封存的多文件运行可进入纯本地提交恢复；核对调用成功而落库失败只恢复收据；核对尚未派发或确定失败时按冻结策略建立新的核对尝试；服务商可见而结果不确定时禁止自动重发。对外 `cross_file_review` 状态及 `target=cross_file_review` 重试输入将文件成功与核对失败分开，不能把失败映射到已成功文件。核对每次调用进入父费用汇总的独立身份，不伪装成文件尝试。上述状态、持久化、重试、费用拓展及真实 PostgreSQL/HTTP 仍待实现，当前权威本地恢复策略只看文件成功，逐文件候选不能启用。

**2026-10-03 当前事实补记：** `single_file_v1` 已启用并接入Worker。published run15/task47 成功，原键、来源与费用真实读取成立；run16/task49 的两文件已封存，复核首尝试因旧上下文过期在派发前拒绝，同四级caller正常 `target=cross_file_review` 定点重试后成功，成功文件未重复发送，总计量CNY.004789。具体API／页面／费用证据见本变更 `evidence.md` 的run15、run16条目，不能用此局部成功覆盖取消、撤权、OCR等未验收门。

新冲突 run17/task52 的三个实际调用均已发生计量（priced/unreconciled，不是账单实付），仍为recovery_pending；原异常未保留，回执与冻结候选差异可从源码推导来源拒绝分支；27PHP已交付并刷新真实HTTP／非reloadableTaskWorker，`receipt_only` 正常入口及安全DTO已加载，实际UI资格available=true、count0/max1、按钮enabled已核，但唯一恢复POST尚未执行，原费用/结果/回执未改。会议已有七typed Event→官方Node→Stop源码与只读14边拓扑并已装载，尚无该新Graph真实节点执行或完整会议验收；源码、拓扑和真实Provider路径证据分别记录，不宣称Neuron所有替换或全产品完成。

#### 材料逐文件正文摘要版本（3.4 内部完整性切片）

逐文件正文完整性采用内部 `payload_digest_version`，由 SandAI 的结果来源域持有，不进入对外字段。新增迁移 `202610010097_material_file_payload_digest.pgsql` 为 `sand_ai_workflow_file_result` 增加 nullable smallint，只允许 NULL／2，不设默认、不回填旧记录、不改历史 043／071。新写入标记 2，递归按字符串顺序排序对象／map 的键，保持 list 顺序和空 extensions 对象，沿用原 JSON 编码 flags 后计算 SHA-256。保存幂等先验证既有正文，再比较同一规范化语义、调用、尝试和来源集合。跨文件提示词、最终汇合、受授权读取及配置样本采用都必须先校验正文；清理正文、未知版本或摘要不符以 `SAND_AI_ARTIFACT_PROVENANCE_INVALID` 失败关闭，不发送正文给 Provider。

NULL 旧证据仅按当前持久化可观察顺序和原 JSON flags 复算一次（extensions 依固定 Schema 保持对象）；匹配原摘要才允许消费。jsonb 丢失原键顺序时不能排列猜测、按 Schema 拼回旧顺序、改写摘要或自动重跑。无法证明完整性的旧成果、来源和已发生费用仍保留；消费被拒绝。新的提交与已有逐文件队列执行在 Provider 前核对摘要版本列的类型、可空、无默认和 NULL／2 已验证约束；迁移缺失以 `SAND_AI_SCHEMA_UNSUPPORTED` 拒绝。

**2026-10-01 历史候选记录：** 当时源码候选隔离于 `/path/to/evidence/sandai-material-digest-20261001/candidate`，基线为092–096候选；当时真实PostgreSQL jsonb往返、旧证据拒绝、清理及Provider前无派发尚待验收。该原记录保留，不代表现逐文件执行尚未启用；未实证的完整性、清理、撤权与取消门仍保留。3.4 当前继续未勾选。

### 个人 Skill 固定版本案例评测（5.2）

案例归 SandAI，不进入 SandIAM 授权表。`sand_ai_skill_case` 的主键为 `id`，绑定不可变的 `skill_version_id`，以版本内 `case_no` 排序；输入和预期检查说明创建后不改写，废弃只改变状态并留下理由及操作者。`sand_ai_skill_case_evaluation` 以 `id` 为主键，绑定案例与现有成功的 `sand_ai_skill_run` 测试运行，保存人工通过/失败判定、说明和操作者；评测行只追加。案例测试仍走原 Skill 模型调用和计量链，判定不得伪造测试调用或覆盖原输出。发布与案例增退、判定共用固定版本行锁；发布条件为至少一个有效案例且每例的最新判定通过，旧通过不能盖过新失败。048 迁移只随统一包交付，执行须另有现有 `example_db` 数据库迁移授权。

### AGF PHP 的吸收与取舍

以固定 commit 的 [源码](https://github.com/kof21411/agf-php/tree/aa848935a75d2454a7f7cde4cea103f8956d09e2) 为证据：

- `SessionSummarization.php` → 会话摘要显示覆盖范围和近期原消息，压缩不能冒充原始历史。
- `ChatDispatchService.php` / `WebSocketService.php` → 正文、工具状态、结果、失败的统一事件时间线和断线后查询同一运行。只呈现可公开状态，不输出模型内部推理或原始错误。
- `ToolRegistry::directTest` → Schema 参数表单、工具配置检查、重名冲突提示；试用也走权限、计量及审计。
- `KnowledgeBaseService.php` / `ResourceTrainingService.php` → 导入、解析、索引、检索就绪分别显示，独立检索试用；不把建索引叫训练模型。
- 已有统一搜索能力细化为多供应商配置与同一来源 DTO，不新建重复搜索系统。
- `ToolApprovalService` 与聊天/规划/执行模式仅作后续参考。本阶段只读工具，不引入副作用批准或自治执行器；review_required 仍归消费者处理。

AGF 的 SQLite DATETIME/AUTOINCREMENT DDL、文件向量库、单 worker 静态 sessions/busy、反射扫描工具、var_export 写 PHP 密钥配置不适配本项目。根仓未发现 LICENSE，依赖 MIT 不代表项目许可：学习交互思想，不复制实现。Workerman 锁 v5.1.10、NeuronAI 3.3.0；没有确认其 TypePHP 编译或自动化回归。

### TypePHP 与验证

明确类型、显式方法/工厂、声明文件无顶层初始化、请求状态不共享；不新增反射扫描/eval/动态业务 include。不为方便测试使用闭包重绑定。金额沿现有十进制契约。框架现有动态调用须在固定编译矩阵下验证，不根据源码风格宣称兼容。

当前仓已固定本机 TypePHP 诊断矩阵与可复跑源码基线；发行支持矩阵及完整运行产物仍未确认，在 tasks 中保留实际编译和运行门。不得全仓 ignore 业务、直接改 vendor 或在未核验版本下抬高兼容状态。

2026-09-24 的**本机诊断矩阵**固定为 macOS arm64、Apple Clang 21、PHP 8.5.10 ZTS/embed（`/path/to/tools/php-zts`）、TypePHP 0.9.0 `f127dadf`、PHPX 2.9.0 `6f208937`；SandAI 锁定 Webman 2.2.3、Workerman 5.2.2、ThinkORM 3.0.34、Carbon 3.13.2。旧 PHP 8.4 NTS 缺 `libphp.dylib` 的诊断已过时；当前最小原生程序生成并运行 arm64 Mach-O。由 `start.php` 语义包装的入口可编译成二进制，但只扫描 1 个入口文件，不是应用编译；直接扫描 `server/app/**` 在未包含 vendor 的 `think\model\concern\SoftDelete` trait 处停止。此前首个业务源码阻断为 `ProviderBillingRuntimeFactory` 顶层 `require_once`，现已通过拆分 PSR-4 声明修复。2026-09-24 已新增 `server/typephp-baseline.yml`，编译输入列出完整 `app/**`、锁定 ThinkORM 核心及实际用到的适配/PSR/Helper 声明，不忽略 SandAI 源码，也不使用临时 vendor 改写。PHP 8.5 ZTS/TypePHP 0.9.0 的 `--dry` 扫描 536 个文件，首个失败为旧 `app/Admin/Support/ResourceController.php` 所继承的宿主基类不在输入内；临时诊断绕过该历史类后又证实 ThinkORM 3.0.34 的局部变量类型变化会被 TypePHP 拒绝。诊断矩阵和首个失败已满足 1.4 的基线门，发行矩阵、完整源码覆盖和 6.5 的实际产物/拒绝行为仍未通过。需选择 Webman/SandAdmin 适配入口与运行资源，并解决已记录的依赖及扩展异常边界问题。

## Risks / Trade-offs

- 旧 fixture 自证旧协议 → 新测试参数/结果绑定最新 IAM 的公开端口，真实 IAM/PG 门单列。
- 新 SandAdmin Composer 发布可覆盖既有插件目录 → 依赖先隔离获取，差异审查后单独更新，暂不运行安装脚本。
- 统一发行中 app 与宿主文件冲突 → 包布局/安装/升级/卸载必须验收；临时 checkout 不是产品安装。
- 原 67% 混合底座与产品 → 新 tasks 为执行进度；端到端产品成果独立计数，旧证据仅作为资产引用。

## Migration Plan

旧 `.codex/autopilot/state.json` 当前 `enabled=false`；新变更由 Goal 驱动，旧 tasks 改为历史索引，不重新启用 Stop 队列。先更新入口决策，再逐项实现。

资产映射：SAND-118/119→材料与来源；121→语音；126/129→计量价格；130/131→账单；132→预算；133→知识库；134→Skill；135→工具/MCP；136→场景引导；141A→工作流服务节点。源码和原记录均保留，未验独立终审不能勾选。

每个完整功能先核配置与契约，再完成真实 PostgreSQL/HTTP/SandIAM/Provider、管理页面、来源费用和失败恢复；局部补丁与静态检查只记实现进度，不能据此结束整项功能。主 Agent 控制在制功能线数量，每条明确持续负责人、完整完成标准、剩余问题及外部权限/业务身份/服务阻塞。已有功能线必须继续收尾，不默默弃置；能力充足且现有线有人持续负责时，可以并行进入新功能线。框架选型调研用于纠正当前实现路线，不另开功能。安装和 TypePHP 编译分别留证，功能全部完成前不运行 TypePHP。版本升级回退使用已记录源码/发布包及数据库备份方案，不修改历史迁移 checksum。

### SandAI 消费 SandWorkflow（6.1 目标，2026-09-28 纠正）

已确认：针对当前已明确需求，SandWorkflow 现有能力满足要求，无需改造；消费接入归 SandAI／消费应用侧。权威 Workflow 已恢复通用 1.0.7。当前 `SandWorkflowConsumer` 没有生产业务 caller，具体流程定义、发起时点和参与人属于待确认业务，不能据此认定插件功能不足，也不阻塞独立材料功能。

材料服务独立运行。SandAI 自身负责 AI 异步执行、输入冻结、原键找回、当前授权读取、来源及费用追溯。业务编排由 SandAI／消费应用的适配层管理，调用现有 Workflow 插件公开契约；Workflow 保留通用定义、实例、任务和业务审批，不理解材料服务的请求或成果字段。

同宿主可信业务调用方明确要求创建业务流程时，可使用 SandAI-own `SandWorkflowConsumer::start(definitionId, name, formValue, WorkflowStartContext)`；定义、发起人、参与人、业务范围和稳定请求键必须来自已核验的业务上下文。查询通过 `detail(instanceId, ActorRef, scope)` 调用既有 RuntimeService，完整保留身份与 Workflow 四项业务范围，禁止 monitoring 绕过。适配器不提供 approve，不将工作负载隐式转换成人，不按分析成功或 review_required 自动启动流程。Workflow 业务范围与 SandIAM 四级身份不能隐式互换。启动幂等沿用既有接口，不能宣称强制同键异输入冲突或跨系统事务已实现。

完整消费方案须由真实业务明确何时提交 AI、轮询和交接流程；既有 AI 提交／原键找回／授权读取和上述显式流程交接是接口基础，尚无真实业务闭环证据。此前 SERVICE=7、机器启动、双向通知、执行表及专用硬编码是已撤回的实现选择，不能作为扩展其他插件的必要性证明。流程内自动等待外部服务尚未成为已明确需求；若后续真实需求确需提供方变更，先向用户描述现有接口失败复现、替代方案及 sand_plugins 通用性／必要性论证，待用户审阅决定。6.1 保持未完成真实集成验收。

### 法律分析环境级配置（6.2 目标）

法律研究、诉讼准备和裁判分析各有固定代码及服务端限定的草稿类型。`config/sand_ai_legal.php` 仅为后台创建草稿的初始模板；`sand_ai_legal_analysis_profile` 按 SandIAM 环境、代码和语义版本保存修订、状态与非秘密参数。后台经 SaiAdmin 登录、按钮权限及 SandIAM 环境引用核验保存草稿、明确发布或撤销；发布版本不可原地更改。运行 API 仅接受代码和版本，读取该环境的发布记录并冻结完整配置；提交和授权后入队均复核发布状态，Worker 在外发前再次比对。法律来源权威状态仍由消费者声明，结果必须人工复核，诉讼准备仅输出提纲。此配置发布不创建 SandIAM grant，也不代表真实样本验收。


## 2026-10-06：独立民事起诉状草稿 SOURCE 分支

已确认原一期“可编辑起诉状草稿”不能用诉讼准备提纲代替。本SOURCE候选新增 `complaint_drafting`，首包只承载模板声明的民事起诉状；现trial提纲不改变。律师在现案件入口明确确认当事人/诉讼请求/证据、已确认事实版本、冻结材料及固定私有模板解析版本。正文六章及要件—事实—证据矩阵、抗辩、缺证、待决定和法源缺口一次交付，所有输入和产物引用回到本次当前授权私有来源；正文按验证后固定模板组装，引用不能静默改写。

独立分支不自动调用未获许可的法库，不从Search候选合成确认全文。缺可用法规/版本时法律依据章节留空，缺案例单独提示；消费者声明有效仍需律师核查。律师编辑/删除/采纳后仅明确保存到本案现有draft civil_complaint文书版本，原保存号纯读取恢复，不定稿或进入法院/Workflow。字段、当前身份/案件/来源、模板SHA/parse及保存合同见Lawyer后端契约第21节。

**历史分支状态：** 本轮只完成这条源码分支，并保存必要profile迁移SOURCE；未运行或升级宿主。第3节自动拆争点/请求/抗辩→法规/案例合检→合法全文与效力/版本确认的专库链、专库真实协议和许可、模型选型及第6节完整救济仍保独立缺口；不能用该SOURCE或资料模板起草等同一期真实业务验收。6.2不勾。 后继实际SOURCE21+16／23+20／12／13+17已补原专库/全文/采用对应实现；该旧源码缺口描述不作当前重新派发依据，真实协议订阅/许可与业务验收仍分层。2026-10-09 RemedyOutputPolicy单PHP校验后法源登记修复已连页面/载荷SOURCE4/4实交，回执`tmp/sandai-source-resume-20261009/legal-remedy-authority-adoption/delivery-integration/SOURCE-DELIVERY-RESULT.json`，未运行。


## 2026-10-06：人工法源声明与重新绑定 SOURCE

一期引用版本/效力确认增加可由当前律师完成的来源入口：基已登记许可、当前授权真实私有材料与固定解析块，逐块读取依据后声明具体版本、适用日/范围、原定位和意见。可信当前审阅者身份与服务端时间写入新不可变 `consumer_import` 快照；保留原unknown/供应商注记和绑定，不包装为官方核验。原号GET与依据原文GET纯读，拒权/变更清当前页面而不删除持久记录；新来源必须明确重新绑定才能消费。

声明适用日与本次分析严格一致，日期未知/超范围和当前撤权均拒绝声明来源消费。起诉状仅以本次有效声明且当前已授权的法规/解释来源组织法律依据，人工声明与未独立核验提示保留；法律依据不足仍留空待核。候选跳转仅当前原研究号纯GET预选关联，许可全文、声明和绑定均须明确完成，绝不自动将Search候选升为确认法源。字段及回看/恢复契约见Lawyer后端契约第22节。

该增量补可确定的消费SOURCE入口，不代替厂商许可、完整tools/list schema、版本事实、运行配置或真实一期验收；自动争点及双路检索编排为独立包。仅源码，未运行检查/数据库/服务/查询/验收；6.2不勾。


## 2026-10-06 可配置Neuron官方专业重排 SOURCE补充

专业重排是显式可选的知识查询付费阶段，能力归属 `knowledge_query / sand_ai.knowledge.query / rerank`。旧无 marker 的 chat 重排仍为原实现和原收费归属。专业网关与旧网关提供相同 rerank/readOriginal 参数与 citations/invocation/usage/state 返回形状；权限回调复用当前 knowledge publication 决策和检索再验证，不通过 chat invocation guard。模型目录与 assertAvailable 复用专业网关的当前正式 rerank 配置判定，避免接入者获得不可提交的模型选项或在 embedding 计费后才发现专业配置无效。

本次已获明确SOURCE授权的可选专业阶段，沿provider.adapter唯一驱动及正式部署／能力／价格目录配置，专业model与当前知识授权精确绑定；0／1来源不外发，原号读取不调用财务finalizer。实际评分与计量来自冻结Neuron4.0.2专业节点；不将RRF称为官方算法，不把候选数或字节估算作专业计费量。

Cohere／Jina两driver当前metering_upper_bound=null。hard或含hard的混合预算下原key未发送拒绝capacity_unverified；soft预算沿原未定价策略允许未知费用待结并提醒，按原冻结价格与真实量结算，缺量或价格未确认保held。无预算沿真实量处理，不自动重发／chat降级。137及正式安装升级卸载只源码，未执行、未真实调用、未测试或验收，不增加完成勾选。

## 2026-10-06 专业重排软预算 SOURCE 补充

软限制的未知费用以NULL义务持久保存，动态响应soft_cost_pending/held与SAND_AI_BUDGET_COST_PENDING提示；真实量使用原冻结价格结算，不能伪造估值、预占或零费用。soft未定价block仍拒绝，allow保原未定价快照；hard/mixed缺可靠上界仍拒发。当前/原scope与相交账期在共同selector和claim的原锁内承接未确认义务，策略改版不能逃逸，released/settled与不相交账期不阻。原号读取纯读，无重发或财务变更，旧chat及usage契约不变。

138及install/update/uninstall正式入口仅SOURCE，未执行迁移、业务、静态检查、测试或验收，不增加32项或四场景完成计数。

## 2026-10-08 已确认MCP双向接入分工

用户回复“那懂了，没什么问题了，你继续吧”，承接官方Neuron客户端＋SandAI业务transport适配及SandAI MCP服务端分工说明；旧transport方案待选条件已解除。出站MCP消费沿已接入的官方Neuron客户端（NeuronReadonlyMcpClient extends NeuronAI\MCP\McpClient），保留SandAI业务transport以实现冻结DNS／端点校验、byte cap、严格帧及原请求dispatch ledger、授权与费用约束。入站SandAI MCP工具入口复用现app service，由正式SandIAM进行身份与执行授权，不创建平行IAM或重复业务服务。服务端SDK经知识作者有界primary实际比较、主控按用户“不是我优先，是先分析根据实际来考虑”纠正后选择官方`mcp/sdk` v0.8.1，Packagist source `c5dbfb64e5a2a30872ec3927b4cf13a2eb05e10e`：同一服务入口可支持handshake及2026 modern；当前SandAI对外通用客户端协议未限定仅新版，Tinywan0.1.4仅2026会缩窄目标。选型依据是服务端实际协议覆盖，不是出站Neuron约束、沉没成本或用户偏好。旧“以当前Neuron4／2025 initialize限制服务端SDK”理由已撤回，保留为历史误判，不作现行依据。作者正在续两代协议接线，具体fields／action映射与PSR bridge真实依赖随最终完整SOURCE包冻结；未安装、加载或运行验证，SOURCE实施继续。 此决定纳入原4.4／6.4，源码实现与运行正确性分别留证；6.5共享编译基础仍暂缓，完整原32目标active，全部功能源码完成前不测试／审计／编译／静态检查／安装／宿主准备，未新增其他操作授权。

现有官方通用链的正向回交仅复用既有实现、0新补丁：RAG的CompositeRetrieval／PostProcessNode，Agent的setTools／chat／run／events及Composition官方Workflow／Node，Agent消息存储与Summarization父方法／官方checkpoint，MCP官方McpClient及业务transport；专业重排使用官方Cohere／Jina.process。RRF无官方对应，保业务PostProcessor；旧chatmarker／来源ID／费用合同是冻结兼容，不能冒称官方专业重排。
