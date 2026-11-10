## Purpose

让应用管理员从没有模型凭据的初始状态开始，通过中文引导配置和发布通用 AI 服务，并能理解服务所用资料、方法、工具、模型及费用。统一同一产品的后台配置与运行能力，支持专业人员在引导产生的相同配置上自由组合。

## ADDED Requirements

### Requirement: Single product and configurable services
SandAI SHALL 使用同一套业务实现、数据库和版本服务后台配置入口与应用调用入口；后台配置位于 plugin，AI 服务位于 app。Codex 负责前后端。业务应用 SHALL 通过授权服务调用而无需持有模型 Key。

#### Scenario: Developer installation
- **WHEN** 开发者在干净 SandAdmin 宿主安装统一 SandAI 包
- **THEN** 宿主获得同一版本的 `server/plugin/sand-ai/**` 后台配置、`server/app/**` 对外服务和 SandAI 运行配置；无需再安装第二套 SandAI 实现或手工复制应用代码。

#### Scenario: Guided and professional configuration
- **WHEN** 管理员通过材料整理引导完成配置后进入专业模块
- **THEN** 能看到并编辑相同的服务商、模型、知识与工作流配置，中文名称为主，代码和版本作为专业详情。

### Requirement: First activation without credentials
SandAI SHALL 引导用户选择目标、配置服务商/外部服务账号、了解获取 Key 和计费方式、验证连接、发现或手工校验模型、检查能力、运行样本并发布。失败 SHALL 提供具体恢复动作且不展示秘密。

#### Scenario: Empty installation
- **WHEN** 新安装没有服务商或 Key
- **THEN** 不要求从空模型列表选择；先引导配置连接，再展示已验证的可选能力。

### Requirement: Traceable structured material processing
SandAI SHALL 接受不透明 matter_ref、固定文件与业务快照及版本化 Schema，支持解析/OCR/表格、归类、实体和时间线、重复/冲突/待核实项、部分成功、取消和定点重试。事实字段 SHALL 可回到授权来源位置。
直接文件、解析、来源检索和任务引用 SHALL 绑定上传时的可信 SandIAM 调用方归属；历史缺失归属不得仅凭 environment 自动授予访问。

#### Scenario: Conflicting material
- **WHEN** 来源不全或多个文件存在冲突
- **THEN** 返回待核实项和来源，不自动形成已确认事实；review_required 仅表示复核标记。

#### Scenario: One material file fails
- **WHEN** 固定文件集中的一个文件处理失败而其他文件已产生可验证结果
- **THEN** 已成功文件的结果及来源保持可读且不重复计费；重试只执行失败文件，最终状态准确区分成功、失败和未完成文件。

### Requirement: Reusable expert and knowledge services
SandAI SHALL 提供版本化知识库、混合检索/rerank、Skill、智能体、机器人和 AI 工作流；以用途解释选用条件。个人 Skill 支持授权资料提炼、草稿、测试、发布、固定版本运行、撤销和费用查看，禁止未经确认自动学习或发布。

#### Scenario: Same agent through different channels
- **WHEN** 管理员将同一个知识助手绑定两个机器人渠道
- **THEN** 两个入口使用相同的已发布智能体能力，仍分别校验调用身份和会话归属。

### Requirement: External capabilities and legal sources
SandAI SHALL 提供可替换的 OCR、ASR、TTS、搜索、Embedding 和受控只读 MCP/外部工具能力。模型不得获得工具凭据。法律来源 SHALL 经消费者 LegalSourceGateway 的标准只读结果或快照进入，法律规则和模板由版本化配置承载。

出站MCP消费沿已接入的官方Neuron客户端（NeuronReadonlyMcpClient extends NeuronAI\MCP\McpClient），保留SandAI业务transport以实现冻结DNS／端点校验、byte cap、严格帧及原请求dispatch ledger、授权与费用约束。入站SandAI MCP工具入口复用现app service，由正式SandIAM进行身份与执行授权，不创建平行IAM或重复业务服务。服务端SDK经知识作者有界primary实际比较、主控按用户“不是我优先，是先分析根据实际来考虑”纠正后选择官方`mcp/sdk` v0.8.1，Packagist source `c5dbfb64e5a2a30872ec3927b4cf13a2eb05e10e`：同一服务入口可支持handshake及2026 modern；当前SandAI对外通用客户端协议未限定仅新版，Tinywan0.1.4仅2026会缩窄目标。选型依据是服务端实际协议覆盖，不是出站Neuron约束、沉没成本或用户偏好。旧“以当前Neuron4／2025 initialize限制服务端SDK”理由已撤回，保留为历史误判，不作现行依据。两作者完整SOURCE包已固定并经本轮精确用户授权实际交付authority19/19，具体fields／action映射、官方mcp/sdk0.8.1与guzzlehttp/psr7^2.7依赖声明及必要host/release已落源；固定清单`tmp/sandai-mcp-server-source-20261008/integration/manifest.json`与同目录`SOURCE-DELIVERY-RESULT.json`保留实际交付依据。此仅源码交付，未安装SDK、加载、调用或验证运行正确性，不证明原32／四闭环通过，不自动切验证阶段。

#### Scenario: Meeting transcription
- **WHEN** 授权应用提交录音
- **THEN** 返回分段时间、说话人、置信度/警告和来源，音频以实际秒数计量，未识别部分可定位。

#### Scenario: Long meeting summary
- **WHEN** 已授权应用对含最多 10000 个转写片段的录音提交摘要
- **THEN** SandAI 使用冻结的片段与复核版本确定性分块、逐块提取并有界合并；成功结果证明全部有文字片段已覆盖，陈述引用可回溯原始片段，逐节点调用、预算和费用可核对。
- **AND** 节点失败、取消或派发结果不明时保留已发生的费用和安全进度，不返回伪完整摘要，也不自动重复可能已计费的模型调用。

### Requirement: Three cost states and multidimensional accounting
SandAI SHALL 分别展示预估、计量、对账金额及币种，未知金额为未计价/未对账而非零。不可变用量记录 SHALL 关联组织、应用、环境、身份、服务动作、运行/节点、能力实现、连接、模型、单位数量和价格版本。

#### Scenario: Workflow retries and reconciliation
- **WHEN** 工作流包含重试、缓存、失败、退款或重复请求
- **THEN** 各实际计费尝试独立记录，父流程仅去重汇总；退款采用调整记录；价格沿调用时版本，对账差异可解释，币种不混加。

### Requirement: Budgets and cost visibility
SandAI SHALL 支持组织/应用/环境/服务/能力/模型预算提醒、软限制、硬限制和显式低成本路由，提供上述维度及工作流/Skill/机器人/知识库的成本看板和单次运行分解。

#### Scenario: Hard budget limit
- **WHEN** 新调用超过硬预算
- **THEN** 在外部调用前阻止或按已配置策略降级，记录原因；已开始任务遵循明确策略。

### Requirement: Recoverable conversation and observable processing
SandAI SHALL 提供可恢复的私有对话历史、流式执行状态、知识源处理状态、工具受控试用和搜索来源追踪，用户能区分正在生成、完成、断线、失败和取消。

#### Scenario: Reconnect without duplicate billing
- **WHEN** 客户端在模型输出期间断线后恢复
- **THEN** 可查询原运行与已保存历史，不自动重新调用模型；其他应用或未授权身份不能恢复该会话。

### Requirement: Consumer-owned final actions and lifecycle
SandAI SHALL 通过任务/事件契约提供材料、会议、客服和法律研究成果；诉讼准备与裁判分析保留事实/法源版本、依据、风险和草稿。业务审批和最终动作属于消费者/SandWorkflow；文件、派生文本、向量和成果清理 SHALL 可追踪。
当前已明确需求由 SandWorkflow 现有能力满足，无需改造插件；SandAI／消费应用 SHALL 使用其现有公开契约完成消费接入，并以真实业务定义、参与人及调用入口进行集成验收。

#### Scenario: Review and deletion
- **WHEN** 材料结果要求人工复核或消费者撤销资料授权
- **THEN** 由消费者决定审批/业务状态；SandAI 执行受授权的生命周期策略并返回清理状态，材料整理无需安装 SandWorkflow。

#### Scenario: Business workflow is not yet defined
- **WHEN** 业务尚未确认流程定义、发起时点、参与人或真实调用入口
- **THEN** SandAI 不虚构审批或 caller，独立材料服务仍可推进；业务流程集成保持待验收，不归因为 SandWorkflow 功能不足。
