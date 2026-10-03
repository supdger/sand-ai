# 更新日志

## 0.1.0 预览版 · 2026-09-21

公开版本：[sand-ai-v0.1.0-preview-2821503](https://github.com/supdger/sand-ai/releases/tag/sand-ai-v0.1.0-preview-2821503)。

- 从独立 SandAI 仓库重新构建预览包，包内文档明确 `supdger/sand-ai` 为唯一权威源码。
- 本预览基线采用插件独立布局，提供管理端与 PostgreSQL 生命周期入口；模型、私有文件、解析、任务、用量和来源追溯由插件内运行代码承担，身份与服务授权依赖 SandIAM。此为基线能力说明，不补造更早版本新增时间。

升级影响：不包含当前统一宿主应用载荷候选。安装前由维护者核实 SandAdmin、SandPackage、SandIAM 的版本组合和包摘要；不能因版本号相近认定兼容。真实宿主升级及完整业务链验收尚未完成，暂不建议用于正式环境。安装边界见 [README](README.md)。

[版本更新与升级影响（Wiki）](https://github.com/supdger/sandadmin/wiki/plugin-updates) · [全部公开发布记录](https://github.com/supdger/sand-ai/releases)
