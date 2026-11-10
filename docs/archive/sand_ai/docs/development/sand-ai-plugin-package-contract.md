# SandAI 统一安装包契约

> 状态：统一产品安装结构已确定；源码包与安装器的静态、录制式验证已完成。新宿主接入现有 `example_db`、真实 PostgreSQL 安装/升级/卸载、HTTP 和管理页面验收尚未完成，不能据此声明可发布。

## 1. 单一产品与源码归属

SandAI 只有一套面向内部及开源开发者的安装结构。开发和隔离测试在 `/path/to/project/sand_ai` 进行；最终可安装源码由 `/path/to/project/sand_plugins/sand-ai/` 管理。测试宿主及其已安装副本均不是源码来源。

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

发行清单 `release-payload.json` 只声明正式 SandPackage `0.2.1` builder 实际构造的安装 ZIP 成员及摘要，不代表整个源码目录。该 builder 的普通载荷包括根部必备元数据与生命周期文件、当前声明的依赖 bundle、插件目录、管理端目录及受控宿主载荷；`release-build-contract.json` 只为受信 runtime vendor 提供文件数与树摘要，不是额外文档入口。安装、API、IAM 边界／需求及 SandWorkflow 消费正文在包内 `plugin/sand-ai/docs/` 单一维护，保留原子目录以使内部相对链接闭合；完整更新日志单一维护于 `plugin/sand-ai/CHANGELOG.md`。源码根部对应路径保留薄索引而非重复正文，工作区唯一 API 契约正文仍由原任务入口维护；必要修改同步权威正文，不维护第二API。历史 `dependencies/sand-iam-0.7.6.zip` 只保留为历史升级输入，不列入当前正式 IAM0.8.x 发行载荷；官方 builder 只打包 config.json 当前声明的0.8.4 bundle。历史 `dependencies/sand-iam-0.8.3.zip` 亦保留源码而不进入本候选发行 ZIP。正式0.8.4工件已独立核摘要、CRC与原生元数据；源码接入不等于已安装或业务验收。安装 ZIP 必须逐成员核对发行清单，不能手工补 ZIP、扩大构包规则或用源码清单代替实际发行摘要。

`host-payload.json` 的清单与实际文件集合必须完全一致。安装器在写入前检查路径、摘要、文件归属和冲突；升级和卸载只处理该版本明确拥有的文件，并提供可恢复记录。仅复制 `plugin/sand-ai/`、仅拉取源码或只通过 PHP 语法检查，都不构成安装验收。

## 3. 宿主与数据库

SandAdmin 宿主只消费锁定版本的 Sand Core 与 Sand Package；SandAI 安装器能力来自 SandPackage 权威源码，不把测试宿主的修改反向同步到基础包。宿主先安装并配置 SandIAM，再安装 SandAI。当前统一 SandAI `0.1.6` 未发布候选精确依赖正式 SandIAM `0.8.4`，公开工件为 https://github.com/supdger/sand-iam/releases/download/v0.8.4/sand-iam-0.8.4.zip ，SHA-256为 `1a3352a6a5556bb8f605e783dd8f66981d5d3436d0af33c053fcb618424e9366`；安装器依赖声明只接受固定version与sha256，不接受版本范围或download_url字段。宿主兼容遵循[插件宿主兼容范围](https://github.com/supdger/sandadmin/blob/main/docs/repository-plugin-distribution.md#插件宿主兼容范围)：最低宿主 `host_min=0.1.0`，默认不设置 `host_max`，允许最低及以上。两处运行 `info.ini` 使用 `support = ">=0.1.0"`；原 `0.1.x | 0.2.x` 枚举声明已撤回。没有依赖功能证据时不抬高原最低0.1.0。正式builder第四参数传 `0.1.0`、省略第五参数，生成的发布目录 `versions[0].host_min` 是字段实际落点；config.json和source release-payload.json不是兼容目录，不塞入无效字段。实际测试revision、环境、生命周期和业务限制另行记录，不因未验未来宿主而设置人为上限；当前固定开发与验收宿主为Core0.2.0／Package0.2.1／正式IAM0.8.x（本候选固定stable0.8.4），开发必须实际适配该基线；旧Core0.1.9及固定015链仅历史证据，不能代替当前适配。IAM0.8.4已在Core0.2.0／Package0.2.1隔离宿主正常安装；SandAI候选依赖安装失败state4，完整载荷加载与业务未验。固定0.1.5已在带临时TerminalRunner修补的独占宿主完成正常升级、卸载和真fresh安装；公共默认入口与完整业务验收仍分层保留。旧统一 `0.1.1` 包精确依赖 `0.7.6`，只能作为受控历史升级起点。先通过 SandIAM 正常升级入口满足当前版本，再安装／升级 SandAI。SandAI 缺少正式 SandIAM 端口时失败关闭，不创建自己的组织、应用、客户端、凭证、服务授权或访问审计体系。

当前测试场使用 `/path/to/project/sand_ai`。安装前冻结本次明确获授权的 PostgreSQL 目标数据库并使用显式 DSN；配置或代码回退到 `example_db_fallback` 属宿主安装问题。安装器不得隐式建库；临时测试建库须单独明确授权，测试结束精确清理本轮创建的临时库。未经本次授权，不触碰既有 `example_db`／`example_db_fallback` 或共享任务数据库。已有 SandAdmin、SandIAM 或 SandAI schema 不能回放新库初始化 SQL，也不能以“空库安装成功”代替已有 schema 接入验证。复用、迁移或卸载既有表之前要识别版本、归属及恢复路径；实际迁移、服务启停和密钥操作分别按任务授权执行。

## 4. 运行与授权

对外路径统一从安装包的 `plugin/sand-ai/config/route.php` 注册到宿主 `app/Api/Controller/**`。管理路径仍为 `/app/sand-ai/admin/**`，后台配置入口只调用宿主应用的共享服务层。关闭默认路由推导；不得通过插件本地 API、匿名入口或管理登录态绕过 SandIAM 的工作负载服务授权。

SandIAM 拥有组织、应用、环境、工作负载客户端、凭证、服务目录、授权和访问审计；SandAI 只保存经验证的引用和自身业务事实。调用前使用正式 `verifyForService` 与执行授权端口；异步执行前重验原授权。缺失、过期、撤权或事实不足时失败关闭。文件和 OCR 仅走私有存储；响应不得泄漏对象 key、Bucket、永久 URL、密钥或原始厂商响应。业务字段与错误码以 [API 契约](sand-ai-api-contract.md) 中已冻结部分为准。

## 5. 安装与验收门槛

1. 固定干净 SandAdmin revision、SandPackage revision、SandIAM 候选版本及统一 SandAI 包 SHA-256；冻结本次获授权目标数据库与显式 DSN，确认安装不会隐式建库；新临时库与既有 schema 接入分别留证。
2. 按宿主正式流程接入现有库，安装并配置 SandIAM，再经 SandPackage 上传、安装 SandAI ZIP。检查插件后台、`app/**`、运行配置和管理页面均由安装过程部署，无手工复制。
3. 验证允许、拒绝、撤权、幂等及审计的真实 PostgreSQL/HTTP 路径；管理员通过页面完成服务配置并由应用调用。
4. 在受控备份与版本前置条件下分别验证升级、失败恢复和卸载；核对宿主文件归属与数据库对象边界。卸载不得删除 SandIAM 或其他插件数据。
5. 分开记录源码修改、静态检查、录制式测试、真实安装、业务闭环、部署及线上验证。某一层通过不推断下一层通过。

当前安装器录制式测试使用临时文件和模拟 PostgreSQL 连接，只证明包解析与代码路径；它不证明第 2–4 步的真实宿主结果。现有 `example_db` 无官方“附加既有库”流程的问题已提交 SandAdmin 跟踪，真实安装门保持未通过。
