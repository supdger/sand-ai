# SandAI

SandAI 是面向 SandAdmin 的 AI 能力插件，为业务应用提供模型调用、私有文件解析、异步任务、用量和来源追溯 API。管理员在后台配置模型服务与运行参数；接入开发者通过 API 使用能力，身份、应用与调用授权由 SandIAM 提供。

SandAI 的统一产品开发候选使用 [Neuron AI](https://github.com/neuron-core/neuron-ai) **4.x** 作为 AI 开发框架，当前候选固定依赖 `neuron-core/neuron-ai` **4.0.2**，版本随发行依赖管理。该候选已在本地源码中接入模型 Provider、Agent 与工具调用、RAG 检索与文本分块、Workflow、结构化输出及语音接口；安装与真实业务验收分别记录。

**当前公开 `main` 源码和 `0.1.0` 预览包仍是旧插件结构，尚未包含上述 Neuron AI 4.x 依赖及统一应用载荷。** 下方安装说明适用于公开插件包；统一候选的源码和完整安装包发布后，再按对应版本升级。

## Neuron AI 与 SandAI 的职责

| 组件 | 负责什么 |
| --- | --- |
| Neuron AI 4.x | 为统一候选提供模型接口、智能体、工具、检索及 AI 处理节点编排 |
| SandAI | 提供管理页面与业务 API，管理模型配置、私有文件、任务持久化和恢复、来源追溯、用量及费用 |
| SandIAM | 管理身份、应用、工作负载凭证和服务授权；SandAI 消费验证后的调用上下文 |
| SandWorkflow | 提供独立的业务流程与审批；Neuron Workflow 用于 AI 处理节点编排 |

文件和 OCR 使用私有存储，AI 成果需要关联来源块。模型调用凭证由平台管理员管理，业务应用通过 SandIAM 授权使用 SandAI。

## 源码与安装包

本仓是 SandAI 插件源码，包含模型配置、私有文件、解析、任务、用量及来源追溯功能。开发从 [源码主线](https://github.com/supdger/sand-ai/tree/main) 开始；安装使用 [公开发行页](https://github.com/supdger/sand-ai/releases) 的完整插件 ZIP，通过 SandPackage 上传。

GitHub 的 Source code 压缩包是源码，不是完整插件安装包。包的适用范围、依赖版本和升级条件见 [版本更新与升级影响](https://github.com/supdger/sandadmin/wiki/plugin-updates)。

## 版本更新

[版本更新与升级影响（Wiki）](https://github.com/supdger/sandadmin/wiki/plugin-updates)按组件说明功能变化、修复和升级注意事项；[完整更新日志](CHANGELOG.md)保留历史记录。公开发行与下载见 [GitHub Releases](https://github.com/supdger/sand-ai/releases)。

## 安装与使用

准备一个已运行的 PostgreSQL SandAdmin 宿主，由管理员提供宿主 URL、插件管理权限及现有数据库。使用前由维护者确认 SandAdmin、SandPackage、SandIAM 的兼容版本和插件包摘要；公开包的适用范围见 [版本更新](https://github.com/supdger/sandadmin/wiki/plugin-updates)。

1. 先在隔离宿主安装并配置兼容的 SandIAM，身份及调用授权按 [SandIAM 安装指南](https://github.com/supdger/sand-iam/wiki/Installation-and-upgrade)确认；版本组合未经验证时不要继续安装。
2. 通过 SandPackage 上传完整 SandAI ZIP，核对根部生命周期 SQL、后端和管理端载荷。管理端部署、路由加载和 worker 启动使用宿主已确认的流程；本仓没有宿主构建或启动命令。
3. 默认私有存储为 `local-private`，由运维负责人确认 worker 可写的私有目录；可在宿主运行环境通过 `SAND_AI_LOCAL_STORAGE_ROOT` 指定。完整字段见 [storage.php](plugin/sand-ai/config/storage.php)，队列参数见 [task.php](plugin/sand-ai/config/task.php)。模型提供方凭证由提供方管理员供给，业务调用上下文和服务授权由 SandIAM 管理员供给。
4. 路由已加载后，在终端输入宿主实际 URL，执行只读探针：

   ```sh
   printf '隔离宿主 URL（含协议和端口）：'
   IFS= read -r SAND_AI_HOST_URL
   case "$SAND_AI_HOST_URL" in
     http://?*|https://?*) ;;
     *) printf '需要非空的 http:// 或 https:// 宿主 URL。\n' >&2; exit 1 ;;
   esac
   printf '正在检查隔离宿主的 SandAI 插件探针……\n'
   curl --fail --silent --show-error "${SAND_AI_HOST_URL%/}/api/sand-ai/v1/health"
   ```

   预期 JSON 为 `code: 200`，`data.app: SandAI`、`data.delivery: full_plugin`、`data.status: ok`。这只证明插件探针可达，不证明数据库、worker、SandIAM 授权或模型调用成功。完整安装和业务首用尚未验证；失败时保留安装阶段及脱敏错误，停止后续生命周期，不以重装修复已有数据。

## 包内容

- `plugin/sand-ai/`：本地运行 API、管理 Controller、Model、Logic、Worker、PostgreSQL 生命周期脚本；
- `sandadmin-artd/src/views/plugin/sand-ai/`：管理前端载荷；
- 根目录 `install.sql`、`update.sql`、`uninstall.sql`：SandPackage 读取的 PostgreSQL 生命周期入口。

## 文档与反馈

- [Wiki](https://github.com/supdger/sandadmin/wiki/Home)：宿主安装、插件管理与维护
- [源码维护与验证](https://github.com/supdger/sandadmin/wiki/sandai-plugin-maintenance)：源码归属、受控同步及验证边界
- [Issues](https://github.com/supdger/sand-ai/issues)：问题与建议
