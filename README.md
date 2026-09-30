# SandAI 开源插件包

> **状态：开发中。** 当前仓库和预发布安装包供开发与隔离测试使用；尚未完成真实宿主上的完整安装、升级、卸载及业务链验收，暂不建议用于正式环境。

本仓库维护面向 SandAdmin 的 SandAI 插件包。唯一权威源码为
`supdger/sand-ai`。消费工作区的安装副本只用于受控同步和验收，不得作为并行开发源。
它不是 SandAdmin 的内置应用，也不依赖宿主的
`server/app/**` 中存在任何 SandAI 运行代码。

## 隔离测试入口

从 [GitHub Releases](https://github.com/supdger/sand-ai/releases) 获取 `0.1.0` 完整预发布 ZIP；该包是本仓的插件形态，不包含另一个工作区的统一应用载荷。由维护者确认目标 SandAdmin、SandPackage 和 SandIAM 的版本组合及包摘要，再由宿主管理员提供已有 PostgreSQL 数据库、隔离宿主 URL 和插件管理权限；本包元数据没有冻结 SandIAM 版本，不能只凭同为 `0.1.x` 认定兼容。

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

## 边界

安装前，宿主必须已安装 SandIAM。SandIAM 负责身份、application、environment、workload client、
credential、service grant 与访问审计；SandAI 仅消费已验证的上下文，并负责模型、能力路由、私有文件、
解析、任务、用量和来源追溯。

SandAI 的主应用开发、演示和生产运行归独立 SandAI 工作区；本目录仅维护开源插件包及其 SandAdmin
安装验证。发布前必须在可丢弃的 PostgreSQL SandAdmin 宿主完成 `uninstall -> install -> update -> uninstall`
生命周期和真实 API/管理端验收。

修改必须先落在权威仓库，再受控同步到验证宿主；同步后，从权威源码执行
`tools/check-sandadmin-export.sh`，确认验证副本与源码一致：

```sh
printf '已同步的 SandAdmin 宿主根目录：'
IFS= read -r SANDADMIN_ROOT
if [ -z "$SANDADMIN_ROOT" ]; then
  printf '宿主根目录不能为空。\n' >&2
  exit 1
fi
printf '正在检查已同步副本与源码是否一致……\n'
SANDADMIN_ROOT="$SANDADMIN_ROOT" bash tools/check-sandadmin-export.sh
```

输入由宿主管理员提供，应为含 `plugins/sand-ai/` 的宿主根目录。脚本未设置该变量时使用维护者本机路径 `/Users/code/project/sandadmin`；该默认值不适用于其他机器。退出码为 `0` 并显示 `SandAI plugin source and SandAdmin export are identical.` 才表示副本一致；缺目录或存在差异会失败。脚本不安装或同步文件。该文件一致性检查不代替 PostgreSQL
生命周期、真实 API 或管理端验收。
