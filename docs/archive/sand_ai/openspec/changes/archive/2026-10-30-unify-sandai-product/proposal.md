## Why

管理员需要从没有模型 Key 的全新安装开始，配置并向示例项目和其他应用提供可追溯、可计费的 AI 服务。旧目标将同一产品分为主应用和另一套插件实现，且 SandIAM 模拟端口偏离真实接口，历史通过率不能说明最新产品可用。2026-09-24 按用户确认的统一产品边界重建目标。

## What Changes

- **BREAKING** 废止自用/开源双实现及 Cursor 独占 Web 的开发边界：同一 SandAI 产品由 Codex 全栈维护，后台配置在 `server/plugin/sand-ai/`，服务及共享业务实现沿用 `server/app/`。两入口共享业务、数据和版本。
- 固定最新 SandAdmin/SandIAM 来源，修复真实身份消费契约，并以允许、拒绝、撤权及审计证明接入。
- 保留零 Key 场景向导、模型/外部能力、知识库、Skill、智能体、机器人、AI 工作流、来源、费用、预算、账单与三个应用场景的完整目标。
- 当前已明确需求由 SandWorkflow 现有能力满足，无需改造该插件；消费接入在 SandAI／消费应用侧完成。真实业务定义与调用入口待确认，6.1 仍须真实集成验收，独立材料功能继续推进。
- 定向审查 AGF PHP，吸收可验证的会话恢复、流式状态、知识源处理状态、工具试用及统一搜索体验；具体纳入项和不采用项记录在 design。
- TypePHP 编译友好写法与实际编译验证分别验收；不以 PHP lint 或依赖存在代替编译成功。
- 当前变更为唯一新任务来源；旧票据保留作历史资产映射，旧 67% 不继承。

## Capabilities

### New Capabilities

- `unified-ai-platform`：统一产品布局、首次开通、通用能力与专家服务、费用和应用体验。
- `sandiam-consumption`：固定服务身份、真实调用授权、撤权、可信资源事实与审计。
- `typephp-readiness`：编译友好代码、依赖矩阵、可重现编译和运行对照。

### Modified Capabilities

无已有 OpenSpec 主规格；原产品/API 文档作为需求来源，不按不存在的规格生成修改。

## Impact

当前工作区 `/path/to/project/sand_ai` 负责统一产品任务执行与隔离验收；插件唯一权威源码位于 `/path/to/project/sand_plugins/sand-ai/`。同一可安装包中的 `app/**` 承载对外服务，`server/plugin/sand-ai/**` 承载后台配置。涉及身份、Gateway、后台控制器/路由、Vue 管理端、迁移及测试；宿主消费副本不作为源码权威。

依赖来源为 `supdger/sandadmin` 和 `supdger/sand-iam`；不直接编辑 IAM 业务表。真实数据库迁移、服务操作、Key、宿主同步及发布仍按有效授权执行。直接使用 Neuron 4 框架；当前隔离宿主已锁定并安装 `neuron-core/neuron-ai` 4.0.2，材料服务已通过该框架真实调用 Provider。AGF PHP 保留为设计参考；框架接入不代表材料业务来源校验、机器人整链或 TypePHP 验收已通过。
