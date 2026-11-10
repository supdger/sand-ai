# SandAdmin 宿主更名通知

> 状态：源码层的前端目录、PHP 命名空间、Composer 映射、宿主变量、命令和锁文件已切换到 SandAdmin；空白宿主运行时验收属于独立的 SAND-113F 发布门槛。本文只处理 SandAI 对后台宿主的技术引用；`/api/sand-ai/*` 运行 API、`sand_ai_*` 表、SandIAM 的身份/授权契约和已冻结业务接口不改名。

## 变更摘要

原 PostgreSQL 宿主 **SaiAdmin-PG** 已更名为 **SandAdmin**。核心包、目录、命名空间和发布仓库随之更名；历史核心 `sa_*` 表保留，不能为这次更名新建或改写 `sand_ai_*` 迁移。

## SandAI 必须调整的引用

| 旧引用 | 新引用 | 本仓状态 |
| --- | --- | --- |
| `plugin\\saiadmin\\...` | `plugin\\sandadmin\\...` | 已完成；插件与随仓宿主引用已切换 |
| `plugin.saiadmin...` | `plugin.sandadmin...` | 已完成 |
| `saiadmin-artd/` | `sandadmin-artd/` | Cursor U-20 已完成 |
| `FRONTEND_DIR=saiadmin-artd` | `FRONTEND_DIR=sandadmin-artd` | 已完成 |
| `SAIADMIN_*` | `SANDADMIN_*` | 已完成；含 `SANDADMIN_SERVER_PORT` |
| `sai:*` 开发命令 | `sand:*` 开发命令 | 已完成 |

目标插件和随仓宿主均已使用 `plugin\\sandadmin`。`server/composer.json` 不再将 `example-vendor/saiadmin` 作为目标宿主依赖；原核心的运行依赖已在 SandAI 根 `require` 中显式声明，并通过受控 Composer 刷新同步锁文件，未手工编辑。

## 不变项

- 插件显示名 `SandAI`、插件/路由 `sand-ai`、PHP 命名空间 `plugin\\SandAi` 不变；
- `sand_ai_*` 表、SandAI 管理 API 和运行 API 路径不变；
- SandIAM 仍是身份、接入应用、凭证和服务授权的唯一所有者；
- 已安装宿主的 `sa_system_*` 等核心表保持不变。

## 发布门槛

1. 以 SandAdmin 的正式发布标签为基础更新依赖与自动加载映射；
2. 将插件包前端载荷路径更新为 `sandadmin-artd/src/views/plugin/sand-ai/`，并通过管理端构建和类型检查；
3. 运行 `composer check` 与受影响的 SandAI 测试；
4. 在同一 SandAdmin 宿主完成受保护的管理 API 与 `/api/sand-ai/health` 冒烟验证；
5. 不将旧的 SaiAdmin-PG 复制核心与新 SandAdmin 核心同时加载。

门槛 1、2、3 和源码层 PHP/Composer 宿主切换已完成。门槛 4、5 是空白 SandAdmin 宿主的真实安装与受保护路径验收，不以静态构建替代。

## 发布协调

SandAdmin 首版标签、GitHub 新仓库地址和兼容窗口以宿主发布说明为准。本通知不授权发布真实密钥、修改既有数据或绕过 SandIAM 的授权链。
