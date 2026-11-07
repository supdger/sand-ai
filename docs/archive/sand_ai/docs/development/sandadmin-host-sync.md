# SandAI 消费 SandAdmin 宿主

SandAI 拥有主应用代码和本仓覆盖层，但不拥有 SandAdmin 核心。宿主核心唯一权威来源是 `/path/to/project/sandadmin`；同步采用“SandAdmin 发布/通知，SandAI 主动拉取”。SandAdmin 不得通过 post-commit hook 写入本仓，本仓也不得把应用覆盖层反向同步到 SandAdmin。

若 SandAI 发现疑似宿主问题，先冻结 SandAdmin revision、SandAI revision、复现步骤和去敏证据。SandAI 特有复现默认在本仓权威源码解决；只有零插件基线、中立扩展或多个独立消费者复现的通用缺陷才向 SandAdmin 提交 HOST/COMPAT 请求。宿主方案必须尽量保持 SaiAdmin 上游结构和升级路径，优先扩展契约、适配器或兼容层。禁止在本仓宿主副本中直接修宿主核心，也禁止同时修改 SandAdmin 与 SandAI 来猜测归因。

第 1、2 轮单变量诊断仍不能缩小范围时，第 3 轮使用 `gpt-6-astra/high` 做有界根因审查，并记录请求配置、实际任务模型证据和新增信息。第 3 轮仍无根因或下一项可区分实验时标记 `BLOCKED_ROOT_CAUSE_UNKNOWN` 并停止修改，不自动开始第 4 轮。

## 同步流程

1. 选择 SandAdmin 已发布 tag、release candidate 或明确 commit。
2. 运行 `scripts/sync-sandadmin-host.sh --dry-run`，核对将变更的宿主文件与受保护覆盖层。
3. 取得本次写宿主授权后运行 `scripts/sync-sandadmin-host.sh --apply`。
4. 检查生成的 `sandadmin-host.lock`。正式验证要求 `source_state=clean`；`dirty-local` 只用于探索。
5. 依次执行 SandAI 的后端、管理端、SandIAM fail-closed 和真实业务路径验证。

脚本保护 SandAI 的 `server/app` 领域目录、路由、进程、SandAI 配置/数据库/测试、业务插件目录和管理端页面。同步冲突不得通过扩大 exclude 或反向复制解决；应判断能力属于宿主还是 SandAI，再分别在权威仓修改。

宿主同步不授权数据库创建/迁移、服务启停、插件安装、提交、推送或部署。
