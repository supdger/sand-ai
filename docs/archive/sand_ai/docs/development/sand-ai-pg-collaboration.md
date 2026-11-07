# SandAI PostgreSQL 协作约定

> **实时任务状态、负责人、依赖和交接包见 [SandAI 任务看板](sand-ai-task-board.md)。** 本文保留责任边界、既往验收和决策记录；第 3 节不再作为实时状态来源。

> 状态：执行中  
> 目标：SandAI 只支持 PostgreSQL；在不重复劳动、不覆盖彼此改动的前提下，使后端与管理前端可并行推进。

## 1. 责任边界

| 交付物 | 主责 | 协作方 | 完成标准 |
| --- | --- | --- | --- |
| PostgreSQL 基线、迁移、安装与回滚策略 | Codex | Cursor 只读 | 空数据库可初始化；不再依赖 MySQL 方言或连接配置 |
| `server/app/**` 运行面、鉴权、领域服务与 API | Codex | Cursor 按契约消费 | 契约测试和目标接口验证通过 |
| SandAdmin 管理接口及权限 | Codex | Cursor 按契约消费 | DTO、分页、筛选、错误码固定 |
| `sandadmin-artd/src/views/plugin/sand-ai` 页面 | Cursor | Codex 提供接口支撑 | 页面按冻结的 API 契约完成真实联调 |
| 契约、联调、回归与最终验收 | Codex | Cursor 修正前端问题 | 在 PostgreSQL 实例上的真实路径验收通过 |

Codex 和 Cursor 都可以修改前后端，但默认不跨越上述主责边界；跨界修复必须在交接记录中说明原因和影响文件。

## 2. 不可同时编辑的区域

- Codex 独占：`server/app/**`、`server/plugin/sand-ai/` 的 PHP、数据库迁移与安装脚本、API 契约文档。
- Cursor 独占：`sandadmin-artd/src/views/plugin/sand-ai/` 的 Vue/TypeScript 页面与样式。
- 共享前先冻结：路由、Request/Response DTO、错误码、权限标识、页面字段字典。
- 不允许：在 SandAI 中新增 MySQL 兼容分支；业务项目直连 SandAI PostgreSQL；前端根据猜测的字段反向定义后端。

## 3. 历史任务队列（按依赖推进，不按时间推进）

| ID | 主责 | 任务 | 前置 | 交付/完成条件 | 状态 |
| --- | --- | --- | --- | --- | --- |
| C-01 | Codex | 核对 `example-host` 的 PostgreSQL 约定，输出 SandAI 改造清单 | 无 | 明确驱动、连接名、迁移入口、主键/JSON/时间字段与宿主差异 | 已完成 |
| C-02 | Codex | 建立 SandAI PostgreSQL-only 运行与初始化基线 | C-01 | 空 PG 数据库可初始化；没有 MySQL 默认连接或运行依赖 | 已完成 |
| C-03 | Codex | 落地 SandAI 首期表、迁移、模型和约束 | C-02 | 应用、凭证、供应商、模型、调用、用量、审计表可迁移并有关键约束 | 已完成 |
| C-04 | Codex | 冻结首批管理/API 契约 | C-03 | 路由、权限、DTO、枚举、错误码、分页及示例固定在接口文档 | 已完成 |
| C-05 | Codex | 实现并验证首批管理接口与运行调用闭环 | C-04 | 后台配置、发布、业务 API 调用、用量/审计查询在 PG 上通过 | 已完成 |
| U-01 | Cursor | 建立 `sand-ai` 管理端路由、菜单壳和页面目录 | 无 | 可进入管理端模块；不依赖真实后端字段 | 已完成 |
| U-02 | Cursor | 实现应用、供应商、模型、用量/审计页面骨架和 mock 状态 | U-01 | 页面流程与加载/空/失败状态齐全；不猜测未冻结字段 | 已完成 |
| U-03 | Cursor | 按 C-04 契约接入真实管理接口 | C-04, U-02 | TypeScript 类型、筛选、分页、权限、错误提示与真实接口一致 | 已完成 |
| U-04 | Cursor | 完成 PG 实例上的管理端联调与视觉修复 | C-05, U-03 | 真实后台路径可用，构建与关键页面验证通过 | 已完成 |
| A-01 | Codex + Cursor | 端到端验收 | C-05, U-04 | 从后台配置到业务调用及审计查询的完整真实路径通过 | 已完成 |
| SAND-101 | Codex | 私有 StorageDriver 与 OSS 适配 | A-01 | 本地私有读写删通过；OSS 配置与私有签名访问就绪 | 已完成 |
| SAND-102 | Codex | 文件表与上传/查询/删除运行 API | SAND-101 | 文件环境隔离、格式/大小/哈希校验、私有对象受控删除通过 | 已完成 |
| SAND-103 | Codex | ParseDriver 与 PDF/DOCX/图片首批解析 | SAND-102 | 已拆为 SAND-103A（已完成）和 SAND-103B（OCR 独立等待决策），以任务看板为准 | 已迁移至任务看板 |
| U-05 | Cursor | 文件管理页（列表/状态/解析摘要/受控删除）按管理契约接入 | API 契约 1.3 | 管理端可查看文件元数据与解析状态；不暴露 bucket/object key/永久 URL | 已完成 |
| U-06 | Cursor | 任务中心（API 1.4） | API 1.4 | 任务查询/取消/步骤与文件异步解析 | 已完成 |
| U-07 | Cursor | 总览用已冻结列表 API 聚合 + 知识库占位 | 已冻结管理 index API | 去掉纯 mock；不猜未冻结 stats | 已完成 |

执行规则：主责人只领取一个“进行中”任务；任务完成后提供证据并更新状态，再领取其直接后继任务。没有前置条件的 Codex 与 Cursor 任务可以并行，但不得改动对方独占目录。

任何阶段发现需要改变接口或表结构，先在本文件的交接记录新增一项，再修改代码；未冻结的接口不得被视为前端阻塞。

## 4. 进度反馈规则

- 只在任务状态变化时反馈：**任务 ID、完成项、证据、风险、已解锁的下一任务**。
- Cursor 完成一个页面或遇到接口阻塞时，应给出：任务 ID、页面路径、使用的接口/字段、截图或构建证据、阻塞所需的最小后端变更。
- 用户无需充当中转站：只在产品取舍、凭证/环境、或不可逆数据库操作需要授权时请求决定。

## 5. 当前起点

- SandAI 当前仓库仍保留 MySQL 默认连接与 SQL 基础，尚未证明 PostgreSQL 初始化和核心调用闭环。
- 已确定目标为 **PostgreSQL-only**；`example-host` 是宿主参考，不代表 SandAI 已完成适配。
- 首个后端阶段的出口是：可复现的 PG 配置/迁移基线，以及供管理前端消费的第一批 API 契约。

### C-01 结论（已完成）

- `example-host` 的两个 ORM 连接均以 `pgsql` 为默认连接名，默认端口为 `5432`，字符集为 `utf8`；SandAI 仍以 `mysql`、`3306` 和 `utf8mb4` 为默认值。
- PG 宿主安装基础表时使用 `*.pgsql` 脚本、`to_regclass` 检查已安装状态，并在同一事务中导入 Think ORM 的 `pgsql12.sql` 元数据函数。
- SandAI 的 SaiAdmin 安装控制器原先只认 MySQL；`saipackage` 使用中性的 `install.sql`/`update.sql` 约定。C-02 已替换前者并提供 PG 基础脚本；C-03 的 `sand-ai` 插件将沿用该文件名，但其中只允许 PostgreSQL SQL。
- 当前 PHP 已加载 `pdo_pgsql` 与 `pgsql` 扩展；因此 C-02 的环境前置条件已满足。

### C-02 结论（已完成）

- Webman Database、Think ORM 和安装页均固定为 `pgsql`、`5432`、`utf8`；核心安装器只使用 PostgreSQL DSN、`to_regclass` 和 `*.pgsql` 基础脚本。
- 新增 `saiadmin-6.0.pgsql` 与 `saiadmin-pure.pgsql`，使用 PostgreSQL identity 主键与序列同步语句；安装时同时导入 Think ORM 的 `pgsql12.sql` 元数据。
- 已在 `example-host` 同一 PostgreSQL 实例的隔离库 `example_db_smoke_20260729` 完成真实导入：`sa_system_menu` 存在、演示用户数为 1、`table_msg` ORM 元数据函数可查询。
- `example-host` 演示宿主当前运行且配置与实际连接均为 PostgreSQL；后续 C-03 起的 SandAI 功能只在该宿主安装、联调和演示。

### C-03 结论（已完成）

- `sand-ai` 插件已提供 PostgreSQL-only 的 `install.sql` 和 `uninstall.sql`，覆盖应用、环境、凭证、服务商、模型、部署、授权、配置版本、调用、用量与审计共 11 张首期表。
- 所有表均包含状态、创建/更新人、创建/更新时间和软删除字段；关键外键、唯一约束、状态约束和调用/审计查询索引已建立。凭证仅保存 `secret_hash`，服务商配置只以 `encrypted_config` 存储。
- 运行面新增独立的 `InitialSchema` 入口与 11 个 PostgreSQL 持久化模型，运行面模型不依赖 `plugin\\SandAi` 管理代码。
- 已在隔离库和 `example-host` 演示库应用同一迁移；演示库确认存在 11 张 `sand_ai_*` 表，`AiApplication` 与 `Provider` 模型均经 Think ORM 的 `pgsql` 连接查询成功。

### C-04 结论（已完成）

- 管理 API 的路径、权限、分页壳、DTO、枚举和稳定错误码已冻结在 `sand-ai-api-contract.md`。
- 契约覆盖 Cursor 已完成 mock 的应用、服务商、模型、调用记录、用量和审计页面；U-03 可以据此切换到真实接口。

### C-05 结论（已完成）

- 管理端已实现应用、服务商、模型、记录查询，以及环境、一次性凭证、部署草稿和发布接口；所有路由在 `example-host` 的 SaiAdmin 登录/权限链中注册。
- 运行端已实现 API Key scope 鉴权、已授权模型查询、幂等聊天调用和调用摘要查询；仅已发布且启用的部署可被调用。
- 已在 `example-host` 的 PostgreSQL 实例验证配置草稿发布到 revision 2，随后运行 API 成功选中 revision 2；调用、用量与审计均落库。首期使用本地 Fake Provider，不含任何真实供应商密钥或外部消费。

## 6. 交接记录

| 日期 | 阶段 | 交接内容 | 状态 | 证据 |
| --- | --- | --- | --- | --- |
| 2026-07-29 | 协作启动 | 确认 PostgreSQL-only 与 Codex/Cursor 责任边界 | 已冻结 | 本文档 |
| 2026-07-29 | 任务拆分 | 取消按时轮询，改为任务队列与前置依赖 | 已冻结 | 本文档第 3、4 节 |
| 2026-07-29 | U-01 | Cursor 建立 sand-ai 路由/菜单壳/页面目录；开发环境用 frontend 模式进入 | 已完成 | `router/modules/sand-ai.ts`、`views/plugin/sand-ai/**`、`.env.development` |
| 2026-07-29 | 跨界说明 | Cursor 改动了 `saiadmin-artd/src/router/modules/` 与 `.env.development`（非独占目录，仅为可进入模块）；backend 菜单种子仍待 Codex 对齐 path/component | 待 Codex | 菜单需对齐 `/sand-ai/*` 与 `/plugin/sand-ai/*` |
| 2026-07-29 | U-02 | Cursor 完成应用/服务商/模型/用量/审计 mock 列表与加载/空/失败状态 | 已完成 | `composables/useMockList.ts`、`components/MockResourcePage.vue` 及对应页面 |
| 2026-07-29 | 命名修正 | 业务插件表统一为 `sand_<plugin>_<resource>`；SandAI 从 `sa_sand_ai_*` 无损迁移为 `sand_ai_*` | 已完成 | `update.sql`、`saiadmin6` Skill、PG 演示库验证 |
| 2026-07-29 | U-02 补齐 | Cursor 补齐总览/快速配置向导/调用记录 mock 状态；U-03 仍等待 C-04 | 已完成 | `dashboard/`、`setup/`、`invocation/`、`composables/useMockView.ts` |
| 2026-07-29 | U-02.5 | Cursor 按 C-03 schema 升级管理页为可筛选/分页/CRUD（schema mock，非真实 HTTP） | 已完成 | `api/types.ts`、`api/*.ts`、应用/服务商/模型编辑壳、用量/审计/调用只读列表 |
| 2026-07-29 | C-05 后端交接 | 管理路由、运行 API 与 Fake Provider 已部署到 `example-host`；运行 API 已在 PG 真实写入调用/用量/审计。Cursor 联调目标为 `http://127.0.0.1:8788`，须将 schema mock 切换为真实 HTTP 后再进行 UI 验收。 | 待 U-03/C-05 联调 | `/app/sand-ai/admin/*`、`/api/sand-ai/v1/*`、PostgreSQL 验证记录 |
| 2026-07-29 | U-03 | Cursor 按 C-04 切换真实 HTTP：`USE_SCHEMA_MOCK=false`，代理 `8788`，应用/服务商/模型 CRUD 与调用/用量/审计列表对齐契约；`encrypted_config` 只写不回显 | 已完成 | `api/*.ts`、编辑对话框、`.env.development`；探活 `8788/.../application/index` 返回 401 登录态校验 |
| 2026-07-29 | U-04 联调 | SandAI 的真实开发前端固定为 `http://127.0.0.1:3007`；`3006` 由 `/path/to/project/demo/saiadmin-artd` 占用，不能作为本项目验收入口。SandAI 前端构建及 `vue-tsc --noEmit` 已通过；应用、服务商、模型、调用记录、用量、审计六页均报告 `const searchForm` 绑定 `v-model` 的运行时赋值警告，需由 Cursor 修正。 | 待 Cursor 修复及登录态验收 | `corepack pnpm run build`；`3007/#/sand-ai/application` 跳转至 SandAI 登录页 |
| 2026-07-29 | C-05 契约补充 | 增加环境、一次性凭证、部署草稿与发布接口；同一模型仅一个已启用发布部署，运行面只读已发布版本。 | 已冻结 | `sand-ai-api-contract.md` 2.5、版本 1.1 |
| 2026-07-29 | C-05 完成 | 在 PG 演示站完成管理发布控制器与运行 API 闭环；revision 2 发布后真实调用成功，调用/用量/审计均写入。 | 已完成 | `SetupController`、`ChatGateway`、`/api/sand-ai/v1/*` |
| 2026-07-29 | U-04 | Cursor 修复六页 `searchForm` 为 `ref`；开发端口固定 `3007`；快速配置接入契约 2.5 真实 setup API；`vite build` + `vue-tsc` 通过 | 已完成 | `application/provider/model/invocation/usage/audit`、`api/setup.ts`、`setup/index.vue`、`.env.development` |
| 2026-07-29 | A-01 | 在登录态的 `3007` 管理端验证应用、服务商、模型、调用记录、用量、审计和快速配置入口均为真实 HTTP，并读取 `8788` PostgreSQL 演示数据；后端 `composer check` 与运行健康接口均通过。发布部署后的运行调用、用量和审计写入沿用 C-05 的真实 PG 记录。 | 已完成 | `corepack pnpm run build`、`composer check`、`GET /api/sand-ai/health`、`3007/#/sand-ai/*` |
| 2026-07-29 | 范围说明 | 总览页仍明确标注为 mock 展示，不属于 A-01 的真实后台验收路径；不得将它视为已接入的实时统计。 | 已冻结 | `views/plugin/sand-ai/dashboard/index.vue` |
| 2026-07-29 | SAND-102 契约 | 文件 API 仅限环境凭证；新增 `files:read`、`files:write` scope。上传为单文件 multipart，查询仅返回元数据，删除受控执行；首期不下发下载 URL、bucket 或 object key。 | 已冻结 | `sand-ai-api-contract.md` 2.6、版本 1.2 |
| 2026-07-29 | SAND-102 完成 | `StorageDriver` 新增临时文件上传能力；文件表、格式/MIME/50 MiB/哈希校验与运行 API 已落地到 `example-host`。演示站使用临时 `files:read/files:write` 凭证完成上传、查询、删除，凭证已吊销。 | 已完成 | `composer check`、`composer test:sand-ai-storage`、`composer test:sand-ai-file`、`8788 /api/sand-ai/v1/files` |
| 2026-08-03 | SAND-103 启动 | 解析边界冻结为来源块与定位，不含队列、任务或业务审批；PDF 使用纯 PHP 解析，DOCX 使用 ZIP/XML，图片仅在接入可信 OCR 后可输出文本。 | 进行中 | 契约 1.3、`Infrastructure/Parsing/*` |
| 2026-08-03 | SAND-103 部分验收 | `example-host:8788` 已真实验证 TXT、DOCX 与 PDF 经私有 OSS 上传、解析、状态读取、删除；来源块分别保留行号、段落号、页码。图片明确返回 OCR 未配置，不伪造已解析状态。 | 等待 OCR 授权 | `composer check`、三组 Smoke Test、`POST /api/sand-ai/v1/files/{id}/parse` |
| 2026-08-03 | 产品决策（用户授权 Cursor/Codex 自定） | 1) 首期验收继续用本地 Fake Provider，用户暂不申请真实模型；仅当要接真实供应商或生产联调时再通知用户开通。2) 分工严格按本文第 1–2 节：Codex 后端独占、Cursor 前端独占。3) OSS/文件存储不重做（SAND-101/102 已完成）。4) 原“U-05 等待 SAND-103”已被拆分：U-05 现可消费 API 1.3，OCR 单列为 SAND-103B。 | 已更新 | `sand-ai-task-board.md` |
| 2026-08-14 | U-20 | Cursor 将管理端目录 `saiadmin-artd/` 迁为 `sandadmin-artd/`，并更新独占路径、契约前端载荷与 `.env.example` `FRONTEND_DIR`。跨界改了 `server/scripts/audit-sand-ai-plugin-package.php` 路径字符串（回滚=还原路径）。PHP 命名空间与 Composer 核心映射仍待 Codex。 | 已完成 | `git mv`；`pnpm run build`；`vue-tsc --noEmit`；`docs/development/sandadmin-rename-notice.md` |
| 2026-08-31 | 协作握手 | Codex 已恢复；Cursor Autopilot/DETECT 重新启用。契约仍 1.14，无可领取前端项。U-23 保持返工中（6/6 登录态）；U-24/U-25A 已结清。Codex 队列首项仍为 SAND-116，勿重写已冻结 1.14。 | 进行中 | 看板 2026-08-31 交接包；`.cursor/autopilot/detect-status.md` |
| 2026-08-30 | 协作恢复 | Cursor DETECT 与 Codex 原子队列重新接通：Codex Autopilot 曾 `manual off` 且队列无未勾选项。已按看板快照把 SAND-116/115/117 写回 `.codex/autopilot/tasks.md`（116 为首项以解锁 U-23），Codex `enabled=true`。回滚=删除这三条 `- [ ]`。 | 进行中 | 看板交接包；`.codex/autopilot/tasks.md`；`.cursor/autopilot/detect-status.md` |
