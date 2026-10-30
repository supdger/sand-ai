# SandAI 统一安装包契约

## 统一包构建入口

发布维护者使用正常 Composer 消费的公开 SandPackage `>=0.2.7` 发行包中的 CLI `server/scripts/build-plugin-package.php`。下面的路径由维护者按实际检出位置填写，输出目录应为本次构建独占目录：

```sh
php "$SANDPACKAGE_SOURCE/server/scripts/build-plugin-package.php" \
  "$SANDAI_SOURCE" "$SANDAI_OUTPUT" v0.2.23 0.2.3
```

第四参数为当前最低宿主版本 `0.2.3`，省略最高版本参数。当前源码候选版本为 `0.2.23`；示例为维护者本地构建入口，不执行发布、安装或升级。该入口校验并打包统一的 `plugin/`、受控 `app/` 和 `config/`、前端、`host-payload.json` 及声明的依赖 ZIP；`release-payload.json` 是构建侧文件清单，用于校验源与 ZIP 的文件摘要，不作为 ZIP 成员。公开 `0.2.7` collector 不收集源码根 `CHANGELOG.md` 薄索引，该文件保留为源码导航，不列入发行清单；包内 `plugin/sand-ai/CHANGELOG.md` 完整正文仍随插件载荷交付。源码根 `docs/development/sand-ai-api-contract.md` 也不在官方 collector 的安装范围，仅保留为源码合同入口，不列入发行清单；包内 `plugin/sand-ai/docs/development/sand-ai-api-contract.md` 正文继续随插件载荷交付。正式构包与安装上传／在线下载采用同一 16 MiB 压缩 ZIP 上限；原 2048 成员及 64 MiB 解压容量边界保持。

若 SandPackage 源码检出没有自己的 Composer autoload，可使用脚本已有的 `SANDPACKAGE_TEST_VENDOR` 环境参数，指向本次隔离宿主已安装的 `server/vendor/autoload.php`，再运行同一公开 CLI。这个参数只选择依赖加载入口，不执行测试。不得因旧消费宿主存在同名脚本就替代当前入口；本轮旧 SandAdmin 脚本实际只生成插件载荷，缺少应用与依赖，旧失败 ZIP 已保留。

构建完成后核对 ZIP 的 CRC、重复及不安全路径，并逐项验证两份载荷清单与 ZIP 文件 SHA；构建成功不等于正常安装、运行加载或业务验收。`0.1.13` 本轮采用当前入口完成本地整包构建；公开 Release 和安装仍按各自证据记录。

> 状态：统一产品安装结构已确定；源码包与安装器的静态、录制式验证已完成。新宿主接入现有 `sandai`、真实 PostgreSQL 安装/升级/卸载、HTTP 和管理页面验收尚未完成，不能据此声明可发布。

## 宿主基础依赖最低条件

SandAdmin 宿主支持仍为 `>=0.2.3`，两份 `info.ini` 和 builder 的 `host_min=0.2.3` 不变。宿主根 Composer 管理的基础包分别要求正式稳定版 Sand Core `>=0.2.4`、SandPackage `>=0.2.7`；业务插件最低依赖 SandIAM `>=0.8.4`，不设置上限；当前 bundle 仍以精确版本 `0.8.4` 与原 SHA 识别工件。SandPackage 的 `plugin_dependencies` 字段只接受该工件身份，当前安装器还用精确相等判断已安装插件版本，因而会拒绝较新 IAM；此基础包门槛须由 SandPackage 修复，不能在 SandAI 添加未定义的范围字段或宣称已解决。不要用 Package 的版号替换宿主支持范围，或把基础包加入 `plugin_dependencies`／另一套 Composer 树。

[SandPackage 0.2.7](https://github.com/supdger/sand-package/releases/tag/0.2.7) 沿用自动安装 IAM 后的正式加载续接和 Recover CLI 重载目标修复（[#22](https://github.com/supdger/sand-package/issues/22)、[#23](https://github.com/supdger/sand-package/issues/23)），并把完整插件 ZIP 的构包、上传与在线下载统一到 16 MiB 压缩上限。本候选完整载荷超过旧 5 MiB 上限，不能由 `0.2.6` 安装器消费。管理员在首次安装或恢复前，先沿宿主根 Composer 的正常依赖维护流程满足该最低条件，并完成正常重载。按稳定版本与最低版本比较判断后续版本，不使用已测版本白名单。

`HostFoundationCompatibility` 使用 Composer InstalledVersions 分别比较两项最低条件，版本不足、未知或预发行时只阻止 SandAI 路由和 worker 登记，并在提示中报告各包实际最低条件及当前版本。它不是安装 SQL 前的硬门，不代表旧安装器会自动升级自己。`config.json` 不新增未定义的安装器版本字段。基础依赖守卫由当前候选 `0.2.23` 继续交付；最低版本与业务依赖不变。本候选包含上述 Skill 轮次迁移 154，源码、迁移执行、运行加载及生命周期验证分别记录。

## 1. 单一产品与源码归属

SandAI 只有一套面向内部及开源开发者的安装结构。开发和隔离测试在 `/Users/code/project/sand_ai` 进行；最终可安装源码由 `/Users/code/project/sand_plugins/sand-ai/` 管理。测试宿主及其已安装副本均不是源码来源。

安装包一次交付后台配置插件、宿主应用中的对外 AI 服务、运行配置、PostgreSQL 生命周期 SQL 和管理端资源。后台与应用入口共享同一业务实现、数据及版本。不得在 `plugin/sand-ai/app/api/` 或插件内的 domain/model 树保留第二套对外运行面；也不得依赖未随包交付的私有 `server/app/**` 代码。

## 2. 包结构与文件边界

| 包内路径 | 责任 |
| --- | --- |
| `plugin/sand-ai/app/admin/**`、`plugin/sand-ai/config/**` | 管理配置、权限、显式路由和进程登记；对外路由指向宿主 `app\Api\Controller` |
| `app/**` | 对外接口、AI 业务服务、SandIAM 消费端口、私有文件及任务运行实现 |
| `config/sand_ai_*.php` | 随包安装的 SandAI 运行配置；不得覆盖宿主其他配置 |
| `sandadmin-artd/src/views/plugin/sand-ai/**` | 管理端页面与 API 调用 |
| 根 `install.sql`、`update.sql`、`uninstall.sql` | SandPackage 执行的 PostgreSQL 生命周期入口 |
| `host-payload.json` | 每个 `app/**` 与 `config/sand_ai_*.php` 文件的精确路径和 SHA-256 清单 |
| 根 `info.ini`、`config.json`、`README.md`、`LICENSE` | 插件元数据、依赖及使用边界 |

发行清单 `release-payload.json` 声明官方 SandPackage builder 的统一安装 ZIP 成员及摘要（当前开发基线为 `0.2.23`），不代表整个源码目录。该 builder 的普通载荷包括根部必备元数据与生命周期文件、当前声明的依赖 bundle、插件目录、管理端目录及受控宿主载荷；`release-build-contract.json` 只为受信 runtime vendor 提供文件数与树摘要，不是额外文档入口。安装、API、IAM 边界／需求及 SandWorkflow 消费正文在包内 `plugin/sand-ai/docs/` 单一维护，保留原子目录以使内部相对链接闭合；完整更新日志单一维护于 `plugin/sand-ai/CHANGELOG.md`。源码根部对应路径保留薄索引而非重复正文，工作区唯一 API 契约正文仍由原任务入口维护；必要修改同步权威正文，不维护第二API。历史 `dependencies/sand-iam-0.7.6.zip` 只保留为历史升级输入，不列入当前正式 IAM0.8.x 发行载荷；官方 builder 只打包 config.json 当前声明的0.8.4 bundle。历史 `dependencies/sand-iam-0.8.3.zip` 亦保留源码而不进入本候选发行 ZIP。正式0.8.4工件已独立核摘要、CRC与原生元数据；源码接入不等于已安装或业务验收。安装 ZIP 必须逐成员核对发行清单，不能手工补 ZIP、扩大构包规则或用源码清单代替实际发行摘要。

`plugin/sand-ai/resources/verification/ocr-sample.png` 是随包交付的固定非敏感 OCR 部署验证样本（70095 字节），供 `DeploymentOcrVerifier` 以包内文件路径读取并核对精确 SHA-256；它不含业务上传资料，也不登记为公开 HTTP 资源。正式部署验证通过同一本地 loopback OCR 驱动发送该固定样本；实际上传文件与扫描 PDF 的受控临时副本、OCR 输出和来源仍走私有存储，不返回对象 key、机器路径、密钥或原始响应。该资源进入插件发行载荷；新增 OCR 运行适配类进入受控 `app/` 宿主载荷，迁移 150 沿现安装、更新及迁移目录入口声明，编写源码与执行迁移、配置服务、真实部署验证分开记录。

`host-payload.json` 的清单与实际文件集合必须完全一致。安装器在写入前检查路径、摘要、文件归属和冲突；升级和卸载只处理该版本明确拥有的文件，并提供可恢复记录。仅复制 `plugin/sand-ai/`、仅拉取源码或只通过 PHP 语法检查，都不构成安装验收。

### 版本身份与升级

当前文件清理向量状态投影修复使用独立 `0.2.26` 本地版本候选；两份 `info.ini`、`plugin/sand-ai/config/app.php` 与 `release-payload.json` 使用同一版本。正式 builder 从根 `info.ini` 读取版本并核对后台运行版本，后续生成独立的 `sand-ai-0.2.26.zip` 和 catalog 候选；旧 `0.2.17`／`0.2.18`／`0.2.19`／`0.2.20`／`0.2.21`／`0.2.22`／`0.2.23`／`0.2.24`／`0.2.25` ZIP 及摘要保留原件。

正常升级沿实际已安装版本到 `0.2.26` 的 SandPackage 入口进行。先固定实际旧版本的完整工件、精确 SHA-256 及原宿主备份，再上传完整新候选。安装器要求新版本严格大于已安装版本，并按实际旧版本保存 `upgrade_from_version` 和升级确认；旧版本为 `0.2.25` 时，确认内容为 `UPGRADE sand-ai@0.2.25->0.2.26`。根 `update.sql` 沿原事务锁、严格 version／checksum 预检和连续性检查，保留已补齐的登记清单与执行段及既有迁移 `202610100154_skill_failed_attempt`。准确登记的 153／154 跳过原执行段；缺失的 154 执行原 migration 内容后登记同 checksum，任一失败沿原事务回滚。已有轮次列、索引、约束、触发器或函数却缺准确 154 账本时拒绝升级，不隐式接纳。

本版不新增迁移，不改 154 原文件或 `install.sql`。`uninstall.sql` 在清理前要求 154 checksum 与现有轮次对象归属成立；原表删除后只移除 154 自有函数，触发器、约束及索引随表移除，不使用 CASCADE。旧候选升级未执行 154 的宿主须通过较高版本正式升级，不手工 DDL 或补账；是否实际执行和原数据保留由升级回执单独确认。

个人 Skill 的已确认 schema 失败通过 `resume_failed:true` 和原 `failed_run_id` 显式恢复，同父失败最多一个子轮次、含初次最多三轮；每轮保留独立 invocation、来源和费用。原请求键及输入不变，当前授权、固定版本和模型完成证明重验，未知／进行中调用不能新增轮次。会议纠正及正式继续沿原任务与节点恢复，prepared 调用保留原身份和预算，已发送或未知调用只恢复原回执；无进展失败进入正式授权暂停，保留任务和节点次数。费用汇总将归属可证的 Skill 失败计入 `business_failed_*`，原调用失败及现金账单状态分别保留。原 `0.2.17` 未受理安全投影、`0.2.16` 来源提炼和 `0.2.15` 服务商标识继续交付。

渠道消息恢复使用当前正式授权、原 task version／lease fence 及独立稳定恢复操作号；只有原模型和必要工具回执完整封存且可认证时，才受理本地恢复。恢复响应未知后沿原意图只读找回，原入队授权、固定 Agent／来源、模型请求号、计量和未知结果保留；不新建模型轮次或重发模型、检索、工具请求。

本次为已交付的文件清理向量状态投影修复准备 0.2.26 版本元数据，保留原成功节点、任务、费用及既有文件删除修复；“不适用”不表示已执行向量删除；SOURCE 声明为 host 994 成员、release 1378 成员，成员集合保持。PUBLIC010 的目标版本、备份示例与置零 profile 摘要同步绑定本候选，不改变原事务或版本化迁移链。根源码合同和未声明的 `.DS_Store` 不进入发行清单，插件内 API 合同继续随包交付。源码、完整工件、正式升级与真实业务结果分别记录；本版未公开发行。154 原 migration checksum、依赖、密钥和最低宿主声明保持。

Worker 收到重载通知后取消轮询，不再领取任务；当前 tick 返回或异常展开后退出，由原宿主进程监护重新启动。`reloadable=false` 保留，已发送或未知的外部请求不能因重载视为完成。没有新增回调的旧 Worker 首次升级需在安装完成后使用宿主正式服务控制入口进行一次 restart；仅 HTTP reload 不证明 Worker 已加载本版源码。`process.php` 在主进程预加载 handler 类；后续再修改 TaskWorker handler 本身时，也需考虑通过宿主正式入口完整 restart，不能将 child reload 排空视为全部类源码更新。本轮仅准备元数据，不执行该服务操作。

历史 `0.2.18` 首轮归档曾出现声明 1371／实际 1370：根目录源码合同不在官方 collector 范围；声明已移除该项，原失败 ZIP 保留原件。本次 1371 来自新增 Gateway，未恢复该非安装合同声明。构包、正常升级、迁移执行与真实业务验证继续分别记录。

失败时沿安装器保存的原候选备份和正式恢复入口处理，恢复目标为原操作记录中的实际旧版本。未知或未完成恢复时先读取原操作状态，不上传另一候选覆盖；精确工件摘要、升级和恢复结果由对应实际回执绑定。

### 公开0.1.0升级桥接（源码候选）

此入口供维护已有公开 `0.1.0` 预览版的宿主管理员使用。它只接纳 ZIP SHA-256
`509fbb9ab18b112400d6979443fd00821e4bea293f033a0b130bc8ad8ac2909e`
及对应的精确 14 表 PostgreSQL 结构；数据库和 schema 必须是已有目标，不建库。
pre-repair v093、18 表历史接纳快照及其他缺账本状态不在支持范围内。未知对象、外部依赖、
归属不符或备份／源码摘要不匹配时拒绝桥接，不以补造迁移账本绕过。
这是尚未执行、未公开的 SOURCE 候选，不表示公开预览包已具备统一 app 载荷，也不表示升级已验证。

维护者从同一冻结目标候选取得 `plugin/sand-ai/tools/build-legacy-adoption-sql.php`
及原版本化 `update.sql`，固定旧包与目标更新文件的精确摘要。无参数调用仍只输出原 18 表
归档 SQL；必须显式使用 `--public010` 才进入本节分支。默认 `plan` 仅输出安全 JSON，
不连接数据库，不返回私有路径、凭据、配置值或原始业务记录：

```sh
php plugin/sand-ai/tools/build-legacy-adoption-sql.php --public010
```

进入实际维护前，先取得停旧消费者、备份和数据库操作的相应授权，确认所有使用旧表的
应用与 Worker 已停止。准备受保护的完整数据库（含数据和 schema）、已安装文件及私有存储
备份，并在已获授权的隔离环境演练恢复。使用随同一候选交付的
[`backup-manifest.example.json`](../../tools/public010-upgrade/backup-manifest.example.json)
填写 `sand-ai-public010-backup/v1` 私有 JSON 清单：记录已有数据库／schema、旧公开包摘要、
`target_version`、目标 `update.sql` 摘要及各备份工件的字节数和 SHA-256。
`table_snapshot` 来自同目录 [`snapshot.sql`](../../tools/public010-upgrade/snapshot.sql)
以单一 SELECT 返回的完整 14 项 JSON 数组，不改写表名、行摘要或序列状态；
执行该只读快照 SQL 也须有对应数据库读取授权。
模板中的 `consumers_stopped` 与 `restore_rehearsed` 初始均为 `false`，只有实际完成停消费者和
恢复演练后才能分别改为 `true`。模板及声明本身不是已完成备份、停止或演练的证据。
保留原加密材料和私有对象，勿把备份清单放入公开仓库。

以下变量由维护者设置为该私有清单和两个尚不存在的私有输出文件路径；它们不是 CLI 的
额外参数。`prepare` 生成准备 SQL，`bridge` 仅导出同候选的精确 `update.sql` 供核对；
两条命令不执行 SQL，也不调用模型：

```sh
php plugin/sand-ai/tools/build-legacy-adoption-sql.php --public010 --stage=prepare \
  --backup-manifest="$PRIVATE_BACKUP_MANIFEST" --output="$PRIVATE_PREPARE_SQL"
php plugin/sand-ai/tools/build-legacy-adoption-sql.php --public010 --stage=bridge \
  --backup-manifest="$PRIVATE_BACKUP_MANIFEST" --output="$PRIVATE_BRIDGE_SQL"
```

后续获数据库执行授权的维护窗口按以下顺序进行，不能将生成文件视为完成升级：

1. 对已有目标执行 prepare SQL。它核对精确 14 表／引用结构、关系及序列归属和外部依赖，
   只写入 SandAI 自有 `sand_ai_public010_upgrade` prepared 意图，绑定旧结构、备份清单摘要
   和目标更新摘要；此步另需数据库执行授权，不归档、不建新基线、不安装文件，也不授予 IAM 权限。
2. 保持旧消费者停止，沿正式 SandPackage upload → install 升级 → SSE 执行入口消费同一完整候选。
   桥接随其正常 `update.sql` 事务执行，不手工执行导出的 bridge SQL 替代 Package 生命周期。
   它再核旧对象，将全部 14 表和自有 identity 序列移入固定的插件档案 schema，执行原统一基线，
   登记其真实未改 checksum，再继续既有缺失版本迁移链；不伪造已应用版本。
3. 文件与 SQL 阶段按 SandPackage 正式操作记录及恢复入口完成。当前身份、工作负载客户端、
   凭据与服务授权仍由 SandIAM 正式流程配置，运行与业务验收留到允许阶段。

目标绑定分两层：CLI 与正式载荷绑定实际 `update.sql` 的 `target_update_sha256`；
prepare 意图同时保存 `target_version` 与 `target_profile_sha256`。后者由 CLI 对全文中唯一
`bridge_profile text := '64hex';` 声明的 64 位值归零后计算 SHA-256，其他摘要不归零；
声明缺失、重复或与计算值不符时拒绝。Package 在同一连接逐 statement 执行生命周期 SQL，
数据库不能用 `current_query()` 证明完整文件 SHA；数据库按声明值比对 prepared 意图中的
`target_profile_sha256` 和 `target_version`，全文原始摘要核对仍由 CLI 与正式载荷负责。

桥接保留每一条旧记录、密文和私有对象引用作为档案；不把它们变成当前 Provider、活跃任务、
IAM 授权、实际计量或现金收款。旧 usage 的默认 `0` 不是测得用量，不映射为当前费用的零值。
正常 SandAI 卸载保留这些旧档案；档案清理是另行授权的数据处置。

bridge 失败时事务回滚全部桥接／schema 变化，prepared 意图保留供同候选沿正式恢复入口重试。
先读取 SandPackage 原操作状态，不上传另一候选覆盖未知状态。SandPackage 的文件／SQL 阶段恢复
与数据库完整回退分别处理：schema 已提交推进后，只恢复旧文件不能回退数据库；应完成同候选的
正式恢复，或在单独恢复授权下使用受保护的全量备份。此 CLI 不实现数据库创建、密钥轮换、
服务启停或模型派发。当前未执行上述命令、SQL、安装或恢复，未做运行与业务验收。

### 入站MCP载荷与第一次接入（SOURCE）

当前0.2.3载荷沿用app/Api/Controller/McpController.php、app/Mcp身份／会话／业务／PSR桥及共享config/sand_ai_mcp_server.php；root及plugin config.json同声明mcp/sdk>=0.8.1与guzzlehttp/psr7:>=2.7。共享app/config进入host-payload，plugin route登记/api/sand-ai/mcp；安装包正文见[服务契约](sand-ai-mcp-server-contract.md)。客户端以正式SandIAM工作负载Bearer接入，十工具复用现app Controller/Gateway及资源授权，原业务号recover不重派；2025握手及2026现代协议由官方SDK处理，不创建第二安装器或平行IAM。此为SOURCE载荷声明，未安装依赖／同步宿主／加载／调用／验收。
