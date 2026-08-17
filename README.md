# SandAI 开源插件包

这是面向 SandAdmin 的完整、可安装 SandAI 插件发布形态。唯一权威源码为
`/Users/code/project/sand_plugins/sand-ai/`；`/Users/code/project/sandadmin/plugins/sand-ai/` 是受控同步的
发布副本，不得直接作为开发源或独立提交目标。它不是 SandAdmin 的内置应用，也不依赖宿主的
`server/app/**` 中存在任何 SandAI 运行代码。

## 包内容

- `plugin/sand-ai/`：本地运行 API、管理 Controller、Model、Logic、Worker、PostgreSQL 生命周期脚本；
- `sandadmin-artd/src/views/plugin/sand-ai/`：管理前端载荷；
- 根目录 `install.sql`、`update.sql`、`uninstall.sql`：SandPackage 读取的 PostgreSQL 生命周期入口。

## 边界

安装前，宿主必须已安装 SandIAM。SandIAM 负责身份、application、environment、workload client、
credential、service grant 与访问审计；SandAI 仅消费已验证的上下文，并负责模型、能力路由、私有文件、
解析、任务、用量和来源追溯。

SandAI 的主应用开发、演示和生产运行归独立 SandAI 工作区；本目录仅维护开源插件包及其 SandAdmin
安装验证。发布前必须在可丢弃的 PostgreSQL SandAdmin 宿主完成 `uninstall -> install -> update -> uninstall`
生命周期和真实 API/管理端验收。

修改必须先落在权威源码，再受控同步发布副本；同步后，从权威源码执行
`tools/check-sandadmin-export.sh`，确认发布副本与源码一致。该文件一致性检查不代替 PostgreSQL
生命周期、真实 API 或管理端验收。
