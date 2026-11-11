# AGENTS.md — SandAI

## 本仓是什么

SandAdmin 的 AI 能力插件（基于 Neuron AI 框架），为业务应用提供统一 AI API：模型服务与能力路由、私有文件、知识库、智能体/Skill、音频处理、任务与用量费用记录。身份与授权消费 SandIAM，业务审批走 SandWorkflow。

主要目录：

- `app/`：插件对外 API 与共享服务（`Api/`、`Application/`、`Identity/`、`Infrastructure/`、`Mcp/`、`command/`、`process/`）。
- `plugin/sand-ai/`：插件本体——`app/admin` 后台、`migrations/main-app/*.pgsql` 版本化迁移、`config/`、`tools/`、`docs/`、`info.ini`。
- `config/` 与 `config.json`：宿主配置与插件清单（Composer 依赖、`plugin_dependencies`、服务目录、数据库类型）。
- `sandadmin-artd/src/views/`：插件管理端视图（无独立 package.json，由宿主前端工程承载）。
- `tests/`：可直接 `php` 执行的隔离测试脚本；`tools/`：构包与发布工具。
- 根级 `install.sql` / `update.sql` / `uninstall.sql`、`docs/`。

## 命名与注释规范

PHP ≥8.2（Webman/Workerman，PSR-12/PSR-4）+ Vue3/TS 前端。完整规范见 `/Users/supdger/agent-work/grokbot/devin-queue/命名与注释规范.md`，要点：

**数据库（PostgreSQL，小写蛇形）**

- 业务表前缀 `sand_ai_*`，主键 `id`；列名主体 ≤3 词、≤30 字符，不以本表名词开头。
- 布尔 `is_`/`has_`/`can_`/`must_` 或形容词；时间点 `*_time`、日期 `*_date`，固定三列 `create_time`/`update_time`/`delete_time`；数量带单位。
- `status` 表示启停（1 启用 / 2 停用），`state` 表示流转，两者分开；索引约束 `uk_`/`ix_`/`fk_`/`ck_` + 完整表名。

**接口与契约**

- 路径全小写连字符、资源用复数；JSON/查询参数小写蛇形、与列同名。
- 枚举小写蛇形（任务状态 `queued`/`running`/`succeeded`/`failed`/`cancelled`）；错误码大写蛇形；权限码 `域:资源:动作`。
- 迁移文件 `<时间戳>_<动词>_<对象>.pgsql`（本仓现行格式，位于 `plugin/sand-ai/migrations/main-app/`），已发布不改名、不改内容。

**代码**

- 类/组件/类型 PascalCase，函数/变量/属性 camelCase，常量大写蛇形；与列、接口字段一一对应的键保持蛇形原样。
- 函数/方法 ≤4 词且动词开头；不用 Manager/Helper/Util/Processor/Wrapper/Info/Data 收尾；不用 usr/pwd/cnt/msg/btn/tbl/mgr/svc/cfg/val/ret 等自造缩写；变量名不带类型后缀；布尔以 is/has/can/should 开头。
- 前端组件 `PascalCase.tsx`（一文件一主组件，文件名同组件名），hook `useXxx.ts`，测试 `同名.test.ts(x)`。

**注释**

- 只写代码看不出的原因、约束、单位、取值含义；禁逐行翻译、禁「此函数用于……」、禁流水账、禁横幅分隔线、禁表情、禁改动记录、禁注释掉的旧代码。
- 新文件注释用简体中文；TODO 写成 `TODO(负责人或#编号): 原因`。

**统一叫法**（同一概念全仓一个词）：`dept`、`file`、`create_time`/`update_time`/`delete_time`、`status`=启停、`state`=流转、`announcement`、`task`/`task_run`（后台任务）、`schedule`/`schedule_run`（定时任务）、`dict_type`、`audit_log`、`visibility`、`cancelled`（双写 l）、`client`（OAuth 接入方）。

交付前自查：`python3 /Users/supdger/agent-work/grokbot/devin-queue/naming_check.py sql|code|notes|terms ...`（用法见脚本开头）。

## 提交规矩

- 只 `git add` 具体文件，禁止 `git add -A` / `git add .`。
- 不提交 `.env`、密钥、token、`glm.key` 等敏感文件。
- 不 force push、不 `reset --hard`、不用 `--no-verify`；不合并自己的 PR。

## 测试和检查命令

- `php tests/egress-policy-test.php`、`php tests/trial-managed-chat-test.php`、`php tests/update_sql_migration_ledger_test.php`（直接执行，无 phpunit）。
- `php -l <file>` 语法检查；`tools/*.php`（如 `php tools/build-package.php`、`php tools/frontend-gate.php`）是构包/发布工具，不是测试。
- 无 composer.json/package.json 根清单、无 CI；前端视图随宿主 `sandadmin-artd` 工程构建，本仓不单独构建。

## 许可证

MIT（根 LICENSE）。
