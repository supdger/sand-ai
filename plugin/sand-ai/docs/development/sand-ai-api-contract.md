# SandAI 首期 API 契约

## 渠道原回执的当前授权恢复（2.3/5.4/5.5 SOURCE候选）

原渠道会话页通过现有原消息读取取得服务端恢复投影，只对已经完整封存且可认证的原模型／必要工具回执显式恢复当前授权。不发送模型、检索或工具请求，不产生新模型轮次或模型请求号；原未知、运行中且无可验完成回执的调用保持未知。此增量是 SOURCE 候选，未运行、测试或编译。

### 正式入口与可信定位

`POST /api/sand-ai/v1/channels/{code}/messages/{taskId}/receipt-recovery`，必须提供独立稳定 `Idempotency-Key`。请求体只允许：

```json
{"conversation_id":"原会话标识","sender_id":"原发送者定位标识","expected_task_version":1,"expected_lease_fence":0}
```

可选 `delivery_platform` 沿现渠道平台约定；不接受其他字段／query 参数。`expected_task_version` 为正整数，`expected_lease_fence` 为非负整数，均来自本次服务端投影。客户端不提交 `message_id`、原模型 request key、回执／dispatch 状态、身份、授权或 private source facts。会话和 sender 字符串仅用于定位；正式当前 SandIAM caller 必须匹配原持久任务 owner 与渠道绑定。服务端在原 task 锁内保留持久 `_channel.message_scope`、conversation scope、原 request_id、固定 Agent 版本和来源，输入不得覆盖这些事实。

当前正式授权为 `sand_ai.task.read`、`sand_ai.task.retry`、`sand_ai.agent.submit`、`sand_ai.agent.run`、`sand_ai.knowledge.query`，每项核对同一可信调用范围及原任务／渠道／来源／必要工具资源。新授权独立保存，不覆盖原入队加密授权。成功回执与幂等重放仍须当前读取授权，不旁路来源访问。

### 消费投影与返回

原 `ChannelMessage.task` / TaskSummary 增 `channel_receipt_recovery`，非原渠道 Agent 任务为 null：

```ts
{
  mode: 'receipt_only'
  available: boolean
  reason: string | null
  provider_reissued: false
  expected_task_version: number
  expected_lease_fence: number
  accepted: {
    intent_key_sha256: string
    previous_task_version: number
    previous_lease_fence: number
  } | null
}
```

`available=true` 只能来自服务端原 task 状态、过期／无租约、未耗尽本地恢复额度、原 SDK checkpoint、固定渠道／Agent／来源以及所有必要原模型与工具回执的正式认证；单有恢复 marker、Invocation 状态或回执元数据不能使其为 true。投影不承诺页面当前权限足够，POST 受理及 worker 执行仍重验当前授权和原事实。未知／缺失／损坏／过期／清除回执、缺工具回执、活动租约、来源变更、不可恢复 checkpoint、额度耗尽均保持 unavailable；稳定 `reason` 取服务端错误码或当前状态说明，不猜 dispatch 已完成。

POST 返回原 TaskSummary（同原任务／请求号）；只受理本地恢复意图，不宣称已完成。`accepted.intent_key_sha256` 是原客户端 Idempotency-Key 的 SHA256，不是模型号或 scope 派生内部 key。客户端在发送前持久保存本次原 tuple、task ID、version/fence、独立恢复 key；响应未知后只读 `GET /api/sand-ai/v1/channels/{code}/messages/{taskId}/receipt-recovery/by-request-key?request_key=<原恢复意图key>&conversation_id=<原会话>&sender_id=<原发送者定位>`（可选原 delivery_platform）。此入口沿当前 task.read/agent.submit/agent.run/knowledge.query 与原 owner/source/tool 范围授权，查询永久恢复记录列表而非 latest marker；回 `{task:TaskSummary,receipt_recovery:{mode:'receipt_only',accepted:true,intent_key_sha256,previous_task_version,previous_lease_fence}}`，task.result 为 null。只有 receipt hash 与原本地 key 的摘要及原 version/fence 都一致，才确认该恢复意图已受理；不匹配／拒读／RESOURCE_NOT_FOUND 不推断未提交，不自动重发或换 key。后继意图不会覆盖原记录的找回。受理后等待原任务结果，禁止将 receipt-only 恢复误接为发送新消息或模型 retry。

原 failed/recovery_pending 渠道消息读取在当前 IAM 授权后提供恢复元数据 shell：`query:null`、`task.result:null` 与该恢复投影；不把旧入队授权过期当作当前读取身份，不泄露旧问题／成果／来源正文。其他已完成消息保持原读取边界。shell 仅证明原任务及本地恢复事实，不代表成果可读或 current POST 权限已满足。

### 稳定拒绝与执行边界

- `SAND_AI_VALIDATION_ERROR`：请求字段／类型或平台参数非法。
- `SAND_AI_IDEMPOTENCY_KEY_REQUIRED`：缺少／非法恢复 key。
- `SAND_AI_RESOURCE_NOT_FOUND`：原 owner、渠道或会话范围不匹配／原资源不存在。
- `SAND_AI_IDEMPOTENCY_CONFLICT`：同恢复 key 的定位／并发预期改变。
- `SAND_AI_RESOURCE_CONFLICT`：task 版本／fence、活动租约或恢复次数门不满足。
- `SAND_AI_DISPATCH_AMBIGUOUS`：原完成回执缺失／未知，不可本地恢复。
- `SAND_AI_SCOPE_SNAPSHOT_INVALID`：原冻结事实或 checkpoint 不可证。
- 原 IAM、来源、工具及正式回执认证错误沿其既有错误码返回；不得把拒读变成零费用、未发送或成功。

只消耗原有界 `local_recovery_count/local_recovery_max`，原 attempt_count、max_attempts、模型调用 key、来源、计量、unknown outcome 与旧授权历史不变。

当前回执续权在 worker 领取后过期、被撤销或被当前 IAM 拒绝时，沿原 lease/fence 持久暂停为等待当前合法调用者。自动领取必须排除这一等待状态，不反复使用旧 intent 或消耗本地恢复次数；正式当前 caller 通过同原任务、会话、身份范围和版本/fence 的显式续权后才能解除。恢复意图及旧授权保留历史，不以页面 marker 延长旧 TTL。

若授权拒绝发生在最后一次已经计入 `local_recovery_count` 的原恢复领取内，续权只允许重新绑定这一尚未执行完的原领取机会；记录并核对原 local count、原 task attempt、原 intent 与 claim fence，保持次数和上限原值，不创建额外恢复额度。该机会只在授权拒绝时保留，非授权执行失败不得借续权重复使用；新意图仅恢复当前授权，领取须原子消费对应许可。旧 key 幂等回放及 history GET 只读取原受理事实，不能清除后续暂停或自动重开许可。未知或无可验原回执仍不可继续。
缺任一原 model/tool receipt、原 round/summary 身份或已保存 retrieval/tool 结果时严格拒绝；不会生成新 invocation、new round、检索、工具或 Provider 请求。原 B21 流式 generic 身份错误仍保真实历史，本候选不据无阶段证据修改 stream 或宣称两个任务已恢复。


## 长录音原分段结果回执恢复（4.5 源码候选）

已分段的原 `audio_transcription` 父任务处于 `recovery_pending` 时，Worker 仅在按原片段顺序最早未成功的子派发同为 `recovery_pending`，且其原 `result` 加密回执未清除、未销毁、未确认、未过期并仍在保留期内时领取恢复租约。领取沿原父任务和步骤恢复，不改变原请求键、输入、片段清单、模型尝试次数上限或已成功片段；只消耗既有 `local_recovery_count`／`local_recovery_max` 的有界本地恢复额度。已用尽模型尝试的恢复续跑仍须满足原实际失败次数与已消耗本地恢复次数的有界关系，不重置尝试历史。

恢复通过原当前 SandIAM、私有来源、租约栅栏、冻结请求和回执认证，再复用既有分段结果／唯一用量封存路径。该恢复片段不调用 Provider；封存成功后，未执行片段继续原固定清单及既有派发／费用规则。`accepted`、`ambiguous`、缺失／清除／销毁／确认／过期回执及耗尽恢复额度不自动领取，不重新发送未知请求。公开请求字段、路线与 Task DTO 沿现契约。此段仅冻结源码语义，未运行 Worker、数据库、模型、计量或真实会议验收。

> 状态：C-04 已冻结。实现以本文为准；变更必须新增版本记录，不能让前端按猜测兼容。

> C-05 补充版本：管理端配置发布接口已加入第 2.5 节；它们不改变已冻结的应用、服务商、模型和记录接口。

> SAND-111 架构决定：应用登记、环境、工作负载客户端、调用凭证和服务授权的长期权威归 SandIAM。本文第 2.1 与第 2.5 中已经实现的 SandAI 接口属于迁移期兼容契约；在 SandIAM 接入与数据迁移完成前保持可用，但不得继续扩展为第二套 IAM。详见 [SandIAM 与 SandAI 服务接入边界](../architecture/sand-iam-access-boundary.md)。

> 交付边界：本文冻结的是平台主应用运行面 `/api/sand-ai/v1/*` 的契约。SAND-113A 已将全量插件包的本地业务调用入口、宿主、身份依赖、安装升级和验收边界冻结在 [全量插件包契约](sand-ai-plugin-package-contract.md)：插件同样使用本地 `/api/sand-ai/v1/*`，但不能擅自把平台主应用地址当作安装后的依赖。

## 1. 共用约定

- 管理 API 前缀：`/app/sand-ai/admin`。
- 认证：SaiAdmin 登录态与按钮权限；运行 API 不复用管理登录态。
- 管理响应壳：`{ "code": 200, "message": "success", "data": ... }`。
- 分页响应：`data` 固定为 `{ current_page, per_page, total, data }`。
- `status`：`1` 启用，`2` 停用。软删除记录不出现在列表中。
- 分页参数：`page`（默认 1）、`limit`（默认 10，最大 100）。
- 列表查询只接受本文定义的字段；未知查询字段返回 `SAND_AI_VALIDATION_ERROR`。

主应用迁移115增加已存在页面的配置/试用导航：`/sand-ai/agent`、`/sand-ai/knowledge` 分别读取正式 `sand_ai:agent:definition:read`、`sand_ai:knowledge:index` 后台资格，已有该读取权限的角色仅补导航，不新授API动作。`/sand-ai/task` 标为“任务与会议试用”，沿宿主现 `UserMenuCache` 超级管理员 `uid==1` 的原导航判定，不添加任何任务页面角色链接或后台任务API权限；实际试用仍必须提供合法SandIAM工作负载身份并通过原服务动作授权，管理员登录态不能转换为应用身份。菜单碰撞或异常任务角色链接明确拒绝，新增节点/导航链接复用原精确所有权卸载清理，借用/已编辑/带外部子节点或角色链接的菜单保持。历史迁移099/112及其它已装checksum不改。

## 2. 管理资源

### 2.1 应用（迁移期兼容）

这里的记录表示调用 SandAI 的外部业务应用，不是 SandAI 内部多应用模式，也不是业务
应用中的最终用户。目标架构中权威 application 由 SandIAM 管理；下列接口仅描述当前
演示站已运行的兼容行为，后续由 SandIAM 应用读取/授权契约替代。

| 目的 | 方法与路径 | 权限 |
| --- | --- | --- |
| 列表 | `GET /application/index` | `sand_ai:application:index` |
| 读取 | `GET /application/read?id={id}` | `sand_ai:application:read` |
| 新增 | `POST /application/save` | `sand_ai:application:save` |
| 更新 | `POST /application/update` | `sand_ai:application:update` |
| 软删除 | `POST /application/destroy` | `sand_ai:application:destroy` |

列表查询：`page`、`limit`、`code`、`name`、`status`。

写入 DTO：`{ code, name, description|null, quota_policy, status }`。`code` 只允许小写字母、数字、连字符和下划线，创建后不可更新。

返回 DTO：`{ id, code, name, description, quota_policy, status, create_time, update_time }`。

### 2.2 服务商

| 目的 | 方法与路径 | 权限 |
| --- | --- | --- |
| 列表 | `GET /provider/index` | `sand_ai:provider:index` |
| 读取 | `GET /provider/read?id={id}` | `sand_ai:provider:read` |
| 新增 | `POST /provider/save` | `sand_ai:provider:save` |
| 更新 | `POST /provider/update` | `sand_ai:provider:update` |
| 软删除 | `POST /provider/destroy` | `sand_ai:provider:destroy` |

列表查询：`page`、`limit`、`code`、`name`、`status`。

写入 DTO：`{ code, name, adapter, encrypted_config, timeout_ms, status }`。`encrypted_config` 仅可写，不会在读取或列表响应中返回；读取响应只返回 `has_encrypted_config: boolean`。

首期运行时支持 `fake`（仅受控开发链路）和 `openai_compatible`。后者的只写配置为 `{ base_url, api_key, extra_headers? }`：`base_url` 必须是 HTTPS API 基址，SandAI 固定追加 `/chat/completions`；`api_key` 仅在服务端解密后用于请求；`extra_headers` 仅允许字母、数字和连字符组成的头名。运行 API、日志、审计读取 DTO 均不返回这些字段。百炼等 OpenAI Compatible 服务以 Provider 配置接入，不改变业务项目的逻辑模型名或运行 API。

返回 DTO：`{ id, code, name, adapter, timeout_ms, has_encrypted_config, status, create_time, update_time }`。

### 2.3 逻辑模型

| 目的 | 方法与路径 | 权限 |
| --- | --- | --- |
| 列表 | `GET /model/index` | `sand_ai:model:index` |
| 读取 | `GET /model/read?id={id}` | `sand_ai:model:read` |
| 新增 | `POST /model/save` | `sand_ai:model:save` |
| 更新 | `POST /model/update` | `sand_ai:model:update` |
| 软删除 | `POST /model/destroy` | `sand_ai:model:destroy` |

列表查询：`page`、`limit`、`code`、`name`、`status`。

写入 DTO：`{ code, name, type, capabilities, status }`。`capabilities` 为字符串数组，首期 `type` 取值为 `chat`、`embedding` 或 `rerank`。

返回 DTO：`{ id, code, name, type, capabilities, status, create_time, update_time }`。

### 2.4 只读记录

| 资源 | 路径 | 权限 | 查询字段 | 返回字段 |
| --- | --- | --- | --- | --- |
| 调用记录 | `GET /invocation/index` | `sand_ai:invocation:index` | `page,limit,request_id,state,status` | `id,request_id,environment_id,model_id,deployment_id,state,latency_ms,error_code,error_summary,request_at,completed_at,status,create_time,update_time` |
| 用量事件 | `GET /usage/index` | `sand_ai:usage:index` | `page,limit,environment_id,application_id,provider_id,model_id,service_action,capability_code,usage_state,pricing_state` | `id,invocation_id,organization_id,application_id,environment_id,provider_id,model_id,service_action,source_kind,capability_code,metering_unit,metering_quantity,input_tokens,output_tokens,total_tokens,usage_state,price_revision_id,metered_amount,currency,pricing_state,create_time` |
| 审计日志 | `GET /audit/index` | `sand_ai:audit:index` | `page,limit,name,action,status` | `id,actor_type,actor_ref,action,resource_type,resource_id,summary,status,create_time,update_time` |

用量列表只读取不可变 `sand_ai_usage_event`，按管理员权限查看，精确筛选应用、环境、动作、能力、服务商、模型和状态。三个 Token 字段在 `unmeasured` 或非 Token 计量单位下为 `null`；`audio_seconds` 与 `external_request` 使用 `metering_quantity`，金额未知时 `metered_amount` 为 `null`。列表不返回身份、服务商、价格等原始快照，也不把旧 `sand_ai_usage` 当成费用事实。`page` 和 `limit` 为正整数，`limit` 最大 100；未知查询字段拒绝。审计的 `name` 是 `summary`/`actor_ref` 模糊匹配；调用记录不接受 `name`。

后台 `GET /app/sand-ai/admin/usage/summary` 使用 `sand_ai:usage:summary` 权限，必填 `environment_id`；可用 `from_time`、`to_time` 指定 UTC 的 `Y-m-d H:i:s` 区间，默认近 30 天，跨度最多 93 天。可选精确筛选字段为 `application_id,provider_id,model_id,skill_id,skill_version_id,service_action,source_kind,capability_code,pricing_state,usage_state,reconciliation_state`。返回 `overall,by_application,by_service_action,by_capability,by_skill,by_skill_version,by_provider,by_model`，每项含 `rows` 与 `truncated`；行内给出维度值、币种、事件/测量/定价/对账状态计数，以及字符串金额 `metered_amount,reconciled_amount,difference_amount`（未知为 `null`）。失败调用的计量投影另返回 `failed_events,failed_unknown_amount_events,failed_known_amount,failed_metered_amount`：只以当前 `invocation.state=failed` 且已有不可变用量事件为范围；仅已测得、已定价的金额进入已知部分，有未知金额的失败事件时 `failed_metered_amount=null`，不把已知部分冒充全部损失，也不估算节省。`ambiguous.count` 是有派发歧义且无用量事件的调用数，相关金额为 `null`。不同币种的金额不得相加或按金额互相排名；接口最多返回每维度 100 行，`truncated=true` 时页面不能称结果为完整排名。
汇总行另返回 `known_metered_amount`，仅表示已测得且已定价事件的金额之和；只要该行存在未测得或未定价事件，完整的 `metered_amount` 必须为 `null`。管理页显示“未知（已知部分 …）”，不得把已知部分标成总额或用于完整金额排行。

费用汇总的 `by_application`、`by_provider`、`by_model` 行 `value` 分别使用不可变事件自身的 `application_id`、`provider_id`、`model_id` 十进制字符串；缺少对应 ID 时为 `unattributed`，不从身份或厂商快照的 `code`、`application_ref` 推断编号。外部工具连接的快照 `code` 不属于模型服务商 ID，其费用进入未归属服务商组；该组保持原未知金额语义且不能按 ID 筛选。CSV 复用相同分组值，不新增列。后台维度明细可按有效应用／模型／服务商 ID、服务动作或能力套用原环境及其他筛选条件，读取事件从第一页开始；用量列表仍不限时间，不宣称与近 30 天汇总完全等量。Skill、Skill 版本及未归属行不提供事件筛选；单次费用继续沿用现有事件调用 ID 的授权抽屉。

后台 `GET /app/sand-ai/admin/usage/summary/export.csv` 使用与汇总接口相同的 `sand_ai:usage:summary` 权限、查询字段、UTC 时间默认值与 93 天上限；它复用同一聚合结果，仅导出各维度当前最多 100 行的摘要，不代表完整明细或完整排名。成功响应为 UTF-8 `text/csv` 附件，固定文件名 `sand-ai-usage-summary.csv`，禁止缓存和 MIME 猜测。列顺序固定为 `group,row_kind,dimension,value,currency,total_events,measured_events,priced_events,unmeasured_events,unpriced_events,known_metered_amount,metered_amount,failed_events,failed_unknown_amount_events,failed_known_amount,failed_metered_amount,reconciled_events,discrepancy_events,unreconciled_events,reconciled_amount,difference_amount,truncated,ambiguous_count`。`group` 对应 `overall,by_application,by_service_action,by_capability,by_skill,by_skill_version,by_provider,by_model`；每组先输出一行 `row_kind=metadata`，即使该组没有数据也以 `truncated=true/false` 显示是否截断，并在 `ambiguous_count` 列给出整个筛选区间的派发歧义数；随后输出该组 `row_kind=data` 行。元数据行的业务维度、计数与金额留空；数据行的 `truncated`、`ambiguous_count` 留空。金额维持原接口的十进制字符串和币种，未知 `null` 输出空单元格，计数输出十进制整数；不跨币种合计。对文本单元格防止表格公式执行，合法负数金额仍按数字输出。

后台页面的计量金额排行只在所选维度 `truncated=false` 时显示；同一维度值的任一币种行若存在未测得、未定价或未知金额，整项不进入排行，仍留在明细。可排行项按币种分别以十进制字符串精确比较，同金额并列名次；此排行表达已完整计量的金额，不等同于真实账单支出或跨币种总额。

后台 `GET /app/sand-ai/admin/usage/cost` 使用独立的 `sand_ai:usage:cost` 管理权限，必填正整数 `environment_id`，并且只能提供 `request_id` 或正整数 `invocation_id` 二者之一；两者同时提供、两者缺失、空值、无效 ID 或未知查询字段均返回 HTTP 400 `SAND_AI_VALIDATION_ERROR`。原 `{environment_id,request_id}` 查询保持兼容；从用量事件进入单次费用时使用该行已有的 `{environment_id,invocation_id}`，无需读取全量调用记录来猜测 request_id。ID 查询先按环境与调用 ID 定位持久 Invocation，以其 request_id 进入原费用读取路径，并在读取费用前再次核对同一调用 ID、环境、Invocation/Dispatch 的四级归属及执行来源事实。不存在或位于其他环境的调用统一返回 HTTP 404 `SAND_AI_RESOURCE_NOT_FOUND`，缺可信归属/执行事实返回 HTTP 403 `SAND_AI_INVOCATION_FACTS_UNVERIFIED`；错误不返回身份或其他环境事实。它返回与对外 `/api/sand-ai/v1/invocations/{requestId}/cost` 相同的费用 DTO：用量状态、冻结价格版本与币种、可空 Token/计量金额、可空账单金额与对账状态；不返回身份、服务商或价格原始快照。此后台权限属于 SandAdmin 管理员，不替代应用通过 SandIAM `sand_ai.usage.read` 读取自身费用；`sand_ai:usage:index` 不因此获得单次费用权限，用量列表 DTO 不变。派发不确定或未计量时金额为 `null`。

4.2 单次费用分解候选为上述共享费用 DTO 增加 `usage.metering_unit`（`total_tokens|null`）、`usage.metering_quantity`（可空十进制字符串）及 `price.breakdown_state`（`available|unavailable|held`）、`price.breakdown`（可空数组）。计量单位与数量仅来自完整、守恒的已测 Token 事件，数量取其不可变 `total_tokens`；未测量、缺少数量、数量不守恒、非 Token 或仅有 Dispatch 时，单位、数量及三个 Token 字段均为 `null`，不把占位或缺失值变成零。没有 `invocation_id` 的音频和工具事件不强行关联到调用费用。

`price.breakdown_state=available` 时，`price.breakdown` 固定按输入、输出顺序返回两项 `{meter_code:'input_tokens'|'output_tokens',quantity:string,unit_price:string,per_quantity:int,metered_amount:string}`。数量取不可变事件，单位价、计价数量和金额只取调用时冻结价格快照；须核对同价格版本、同币种、完整费率和金额，并用原十进制规则验证历史分项的舍入、相加及总金额。缺必要证据为 `unavailable/null`；已有事实格式损坏、Token 总数不守恒或冻结分项与价格/总金额矛盾为 `held/null`。必要快照字段为 `price_revision_id,currency,effective_from,effective_until,amounts,rates`，缺字段或必要值为 `null` 均不能分解；`effective_until` 必须存在，其 `null` 合法且表示开放结束时间。已有快照、金额/费率容器、费率项或其字段类型非法保持 `held`。`priced` 总金额不能单独证明两个分项已知；分解不可用也不改写原总金额。真实测得且冻结计价的零保留零，未知金额和币种保持 `null`；不按当前价格重算输出，不使用浮点数，不返回原始快照。

调用前预算上界按下述冻结依据投影；原计量、账单、已对账及人工审核字段语义不变；沿用上述权限、Invocation/Dispatch 四级归属核对和 SandIAM 读取后重验。后端候选已通过语法检查及独立静态审查，尚未安装，真实 HTTP、数据库事实与页面路径均未验收；本段冻结字段和语义，不表示 4.2 已完成或安装环境已提供新增字段。

单次费用的 `price.estimated_amount` 为原调用前预算预占上界，不是实际 Token 预测、已计量费用或已付现金。新增可选字段 `estimate_basis:'budget_reservation_upper_bound'|null`、`estimate_state:'available'|'unavailable'|'held'`、`estimate_reason:'frozen_budget_reservation'|'no_frozen_quote'|'frozen_quote_unavailable'|'frozen_quote_conflict'`、`estimated_currency:string|null`、`estimated_price_revision_id:number|null`。这些字段同时用于原调用费用、无用量事件的派发费用及已授权任务的单次费用投影，不新增路由或权限。

只有原调用、request/fingerprint、四级归属、派发 body/route 摘要、调用时价格及原不可变 reserve 事件形成完整一致的证明链时，才返回 `available/frozen_budget_reservation`。读取原预算组的一个相同上界，不相加多个 scope 的重复预占；用原冻结费率和容量数量核对上界及最小正预占 `0.000001`。不查询当前价格、不生成新报价、不回填历史。缺原预占为 `unavailable/no_frozen_quote`；缺证明为 `unavailable/frozen_quote_unavailable`；现有事实冲突为 `held/frozen_quote_conflict`。不可用或冲突时上述金额、币种、报价版本与依据均为 `null`。

预估币种和报价版本只来自这条原冻结报价链，独立于计量的 `price.currency/revision_id`；未测量或未定价的实际用量仍保持其原未知字段，不能用预占报价填充计量事实。已有 priced 用量的价格/币种须与原调用价一致，否则暂停该预估投影。旧响应缺新增可选字段时界面保持未知，不假定 available；界面明确展示“调用前预算上界”和预算报价价格版本，并说明其预算预占用途及最小预占口径。

手工账单导入 `POST /app/sand-ai/admin/billing/import` 与服务商账单同步 `POST .../billing/sync` 最终写入不可变账单行；`GET .../billing/index` 和 `GET .../billing/read?id=` 读取导入结果。自动匹配仅针对同服务商、模型、能力、币种和发生时间窗中唯一的 `total_tokens` 用量事件。仅在该事件 `usage_state=measured` 且 Token 数存在、`pricing_state=priced` 且计量金额存在时，才能计算数量和金额差额；缺任一事实的单候选账单行标为 `unmatched`，原因分别为 `usage_unmeasured` 或 `usage_unpriced`，差额与关联用量事件均为 `null`。真实测得的零仍按数值零比较；不能把空值当零并标记差异或已核对。多个候选保持 `ambiguous`，服务商聚合成本行保持未匹配，均不能伪装成单次调用账单。


### 已接收的服务商调用标识（原 3.5／4.1／5.4）

上述单次调用费用 DTO 追加可选 `provider_response_identity:{state:'not_observed'|'observed'|'conflicting',identities:Array<{kind:'request_id'|'response_id',source:'header.x-request-id'|'header.request-id'|'body.id'|'stream.id',value:string,received_time:string}>}`。沿原费用 GET、权限、Invocation/Dispatch 四级归属；公开费用 API 沿原返回前当前授权重验；不新增端点、调用参数或账单自动匹配规则。服务商实际返回的两种请求头仅归为 `request_id`，JSON 响应或流事件的 `id` 仅归为 `response_id`，绝不将响应 ID 或 SandAI 内部 request_id 当作服务商账单请求号。

仅保存本次已开始派发后实际接收的白名单标识：ASCII 字母、数字、下划线、点、冒号或连字符，长度 1–128，拒绝控制字符、URL、空白和请求凭据值。每个来源最多保留两种不同值，单次调用最多八项；同一来源的相同值幂等，同种标识在任何来源收到不同值均保留并投影 `conflicting`。时间为服务器实际接收时刻的 UTC `Y-m-d H:i:s`，不是供应方完成时间。`not_observed` 表示没有持久标识证据，不证明没有外发；旧响应缺字段时也按未知显示。`observed` 只证明已收到标识，既不证明内容完整、计量成功或已付款，也不允许释放预算或重发。

服务商接收头及有效 JSON／流事件标识先于完整结果和用量落库，使用独立提交的追加证据保存；流中断、后续校验或成果持久化失败仍保留已获得标识。缺少字段、无效值及未接入该桥的能力保持未观测，不猜测或回填旧调用。存储不可用在发送前拒绝；发送后证据持久化失败保持原未知耗用与预算，不自动再次 POST；若既有流适配器已完整测得失败用量，仍按原测得失败计量路径保留费用，不将标识观测失败当作计量未知。只保存类型、来源、标识、时间和现有调用／派发归属，不保存完整请求头、正文、原始响应或秘密。本证据仅供已有账单及原调用人工核查，不能单独触发对账、补计量、完成任务或释放资金；inv8/9 不因新增代码获得历史证据。

标识存储不可用返回 `SAND_AI_PROVIDER_IDENTITY_UNAVAILABLE`（沿原错误响应；公开费用 GET 为 business/HTTP 400，retryable=true）；发送前失败沿既有未发送终结，发送后失败沿原不确定持有，`retryable` 不授予重新发送。费用读取也可返回此码，不能将读取失败视作没有标识。

账单事实身份独立于批次幂等键、`external_line_id` 标签和金额。OpenAI Costs 同步前，后台账单凭证保存 `{provider_id,billing_admin_key,billing_organization_id}`；组织 ID 首次登记后不可在同一 Provider 改写，轮换 Key 不改变身份范围。同步请求显式选择该组织；仅当成功的每页响应 `openai-organization` 头与登记值完全相同时，日桶、`project_id`、`line_item` 和币种的稳定组合才为 `verified` 事实身份。金额和数量只进入内容摘要，不参与身份；同一身份同内容跨批复用账单事实及其原对账记录，每个导入批次仍有独立关联；同一身份不同内容返回 HTTP 409 `SAND_AI_BILLING_FACT_CONFLICT`，普通导入不更正旧事实。缺失或不一致的归属头、以及当前只接受任意 JSON 行的手工入口均标 `identity_unverified`，返回 `unmatched/identity_unverified`，不得凭 Provider ID、Key 或客户端 `external_line_id` 跨批归并或自动对账。绑定受管原件后，手工来源仅按实际文件摘要及管理员声明定位进行同文件重导去重，具体规则见“人工账单原件与跨批次重导入”；该定位不作为厂商认证或固定解析序号证明。`billing/read` 行增加 `identity_state`，不返回原始服务商响应或凭证。

后台人工账单审核使用独立权限 `sand_ai:billing:review`，不改变上述自动核对或 `identity_state`。`POST .../billing/evidence` 接收单个 multipart `file`（CSV/PDF/非宏XLSX，1字节至10 MiB）和 `provider_id`，由服务器计算 SHA-256并存入私有存储；同 Provider 同文件摘要返回原 evidence。`GET .../billing/evidence?id=` 仅代理附件下载，`DELETE .../billing/evidence?id=` 清除正文后保留 hash/审计并返回 `evidence_unavailable`；不接受 URL、path、object key，不返回永久URL或内部key。附件不自动解析，文件一致性不等于厂商认证。

`POST .../billing/reviews` 接收 `billing_line_id,evidence_id,evidence_locator,granularity,decision,idempotency_key,note`。`granularity` 为 `request` 或 `aggregate`；`decision` 为 `compare`、`reject`、`unresolved`，note必填。request compare另必填 `environment_id,usage_event_id,request_evidence_confirmed=true`：该确认代表管理员已人工检查原件逐请求粒度、账户与关联依据，不作为厂商认证。服务器验证同provider、币种、模型、能力、时间窗及事件与Invocation的持久四级归属；未知用量或价格返回 `unmatched`、差额为null。aggregate不接受event/environment或request确认字段，只记录 `aggregate_only`、拒绝或待查，不摊到单调用。金额和数量来自不可变账单行，审核请求不能改写。

`GET .../billing/reviews?billing_line_id=` 返回至多100条、按ID倒序的 append-only审核记录：`id,billing_line_id,evidence_id,admin_id`为正整数，`usage_event_id,environment_id`可空；字符串为 `idempotency_key,evidence_locator,granularity,decision,note,reason,create_time,evidence_sha256`，`trust_basis=operator_reviewed,locator_basis=operator_declared`，`comparison_state`为 `reviewed_match/reviewed_discrepancy/unmatched/aggregate_only/rejected/unresolved`，`quantity_difference,amount_difference`为可空十进制字符串，`evidence_state`为 `available/evidence_unavailable`。审核写入返回 `{idempotent_replay,review}`。管理员/账单行同幂等键同完整指纹回放，异内容409；首版compare或reject后不能追加最终审核，一个event只能接受一次request compare，不支持隐式改判。unresolved可追加。当前费用DTO新增独立可空 `operator_review`，仅含 `trust_basis,comparison_state,reason,quantity_difference,amount_difference,evidence_state`，不暴露管理员/备注/证据ID，始终不填或替换既有 `reconciliation.billed_amount` 等厂商核对字段；原事实、价格、费用及090约束保持不变。

`billing/read`的每行新增 `billing_line_id`，其余字段不变；不得用外部标签猜内部ID。证据DTO为 `{id,provider_id,sha256,byte_size,media_type,trust_basis:'operator_supplied',state:'available'|'upload_pending'|'clearing'|'evidence_unavailable'}`。上传先提交耐久owner/key，再上传私有文件；返回 `{idempotent_replay,upload_complete,evidence}`，`upload_complete=false`只能显示待恢复，保留同文件/provider显式重试，沿原owner/key恢复或按该ID清除。pending原件不能下载/审核，同摘要已清除原件不隐式复活。清除先耐久标记 `clearing` 禁读取/新审核，再删私有对象并封墓碑；失败保留同ID可继续清除，不恢复available，不将未知回包显示为成功。清除返回 `{cleared,evidence}`，已清除重放为 `cleared=false`。读取、历史和人工审核核对实际文件摘要/字节数，缺失或不符显示不可用，不能只信available标记。未定价事件的空币种允许保持 `unmatched/usage_unpriced` 与空差额，已定价事件仍严格同币种；不填币种、汇率或零价。操作失败时仍保留原键/完整请求供显式恢复，禁止自动更换键；审核500与文件响应不确定不自动重试，历史可按原幂等键找已完成记录，附件下载使用鉴权代理Blob且不内联CSV。

#### 4.2 费用看板维度与业务失败关联费用（2026-10-03 候选）

汇总和 CSV 在原分组后追加 `by_organization,by_environment,by_source_kind,by_workflow_run,by_agent_run,by_knowledge_base`；原必填 `environment_id` 和管理员权限不变，环境维度仅显示所选环境，不能跨环境聚合。组织、环境及来源类别取不可变事件本身；工作流、机器人维度分别表示实际工作流运行 ID、机器人任务运行 ID，不表示定义 ID。知识库维度取明确版本外键的知识库 ID；索引 Embedding 按实际 invocation 的持久关系 DISTINCT，只有唯一且归属可证的知识库才关联。对象分组不读取知识来源正文；冻结输入仅提取已有归属证明所需元数据，不返回私有输入内容或对象名称。缺少或不能证明归属的对象 `value=null`，页面显示“未关联 / 归属不可确定”；其原事件及金额仍参与分币种汇总，不猜对象 ID。

父运行关联须同时核对唯一 Dispatch、Invocation 的父任务/步骤/工作流 ID、事件与 Invocation 的四级身份、真实任务关系及既有可信归属。工作流 TaskRun 的旧可空身份列不能推断所有权，需通过 WorkflowRun 的正式四级归属与原 attribution 证明；已有非空身份列必须一致。机器人任务沿用其正式任务身份、冻结 attribution 和原可信 scope 证明。当前状态仅用于失败投影，不改写不可变事件；无效、删除或归属不可证的关系不标记对象或业务失败。

机器人调用沿既有正式唯一 Dispatch 的父任务/步骤链核对真实任务、节点、调用请求身份、四级归属及原冻结可信 scope；Invocation 父任务/步骤字段为 null 时不据此拒绝该合法关联，已有非空字段必须与 Dispatch 一致。此可空例外仅限 agent 执行范围、agent 来源及真实 agent_run 任务；工作流运行继续要求双方父 ID 与 WorkflowRun 正式归属一致，不补写历史父字段。

每个汇总行追加 `business_failed_events,business_failed_unknown_amount_events,business_failed_known_amount,business_failed_metered_amount`，仅计入正式父任务最终 `failed` 且关联归属可证的调用事件，同一事件计一次；`pending,running,retrying,recovery_pending,canceled` 不纳入。原 `failed_*` 仍只表示 Invocation 失败，二者不得相加。已测得且已定价金额进入已知部分；任一关联事件金额未知时完整 `business_failed_metered_amount=null`。无业务失败关联事件时两个金额为字符串 `"0"`，表示该范围内此种已确认关联事件为零，不推断未知调用费用为零。UI 显示“业务失败关联调用费用”，说明可能包含可用的部分成果，不等于全部损失。无同条件可比基线，节省金额保持未测 / 无法计算，不按账单差额、缓存命中或模型选择捏造节省。

新增分组沿用每维度最多 100 行、`truncated` 标记、币种分组、未知金额和精确排行规则。CSV 原列顺序不变，末尾追加上述四个 `business_failed_*` 列；新增分组元数据行也说明截断，未知金额输出空单元格。现有用量事件列表不新增对象筛选；组织、环境、来源和对象分组没有对应已冻结事件查询时不提供伪明细跳转。汇总或 CSV 的非法参数统一返回 HTTP 400 / `code=400`，提示 `SAND_AI_VALIDATION_ERROR`。

本段冻结候选协议；真实载荷、HTTP 和独立页面验收分别记录，未验收不代表 4.2 完成。

### 2.4.1 六维预算管理

预算范围为组织、应用、环境、服务、能力、模型。身份及应用环境仍由 SandIAM 提供；SandAI 不创建平行身份目录。服务指正式服务码 `sand_ai`，不是 `sand_ai.chat.complete` 等服务动作。当前后台适配无法证明普通管理员完整的组织管理范围，因此预算读取及写入均要求已有平台超级管理员身份，同时保留原预算权限；预算权限本身不会升级为跨组织管理权力。

预算后台接口统一使用 `/app/sand-ai/admin/budget` 前缀：

| 目的 | 方法与路径 | 管理权限 | 参数及返回 |
| --- | --- | --- | --- |
| 分页读取版本 | `GET /policy/index` | `sand_ai:budget:index` | `scope_kind`、`scope_ref`、`page`、`limit`；返回预算版本分页；旧 `environment_id` 表示环境筛选 |
| 读取版本 | `GET /policy/read` | `sand_ai:budget:index` | `id`；返回预算版本 |
| 安全对象目录 | `GET /references` | `sand_ai:budget:index` | `page`；返回启用模型分页 `{id,code,name}` 与真实能力 `{code,name}`，无服务商配置或秘密 |
| 保存草稿 | `POST /policy/save-draft` | `sand_ai:budget:save` | `id?`、`scope_kind`、`scope_ref`、`reference_environment_id?`、`period_start`、`period_end`、`currency`、`limit_amount`、`enforcement=hard\|soft`、`unpriced_policy=block`、`unmeasured_policy=hold`；返回版本 |
| 发布版本 | `POST /policy/publish` | `sand_ai:budget:publish` | `id`、`reference_environment_id?`；返回已发布版本 |
| 归档版本 | `POST /policy/archive` | `sand_ai:budget:publish` | `id`、`reference_environment_id?`；返回原预算版本，状态为 `archived` |
| 读取额度 | `GET /summary` | `sand_ai:budget:index` | `policy_id`；返回 `{ policy, window, alert }` |
| 读取当前额度 | `GET /active` | `sand_ai:budget:index` | `scope_kind`、`scope_ref`；返回 `{ active, summary }`，无当前生效版本时 `summary=null`；旧 `environment_id` 仅作为环境范围别名 |

`scope_kind=organization|application|environment|service|capability|model`。`scope_ref` 以字符串保持精确引用；当前正式 SandIAM 目录及引用验证口采用 bigint ID，组织、应用、环境与模型使用目录 ID 的规范正整数十进制字符串，不接受前导零或有损转换。运行侧组织/应用仍精确匹配可信身份快照的原始文本，不用可空数值投影代替。服务固定 `sand_ai`；能力取实际执行与计量的代码，包括 `external_readonly`、`image_ocr`、`audio_transcription`、`audio_speech` 及模型能力。只在环境范围保留 `environment_id`，并须与 `scope_ref` 一致；其他范围该字段为 `null`。

组织/应用草稿保存与发布须选择同属该范围的 `reference_environment_id`，由 SandIAM 官方引用验证口核验；此参数是只读验证见证，不保存为预算限制条件，预算仍覆盖整个组织/应用。对象选择消费既有 SandIAM 受权目录；目录读取失败明确展示权限/服务错误，不将失败写成“没有对象”。任意非数值 SandIAM 引用的配置需要未来正式引用口支持，本批不能宣称已兼容。

归档只受理正整数`id`和可选正整数`reference_environment_id`，拒绝未知字段；沿预算发布权限及平台超级管理员门，组织／应用须提供同属范围的验证环境，环境范围按原环境事实核验。可归档已发布版本，已归档重放返回原版本且不重复写审计，草稿拒绝为`SAND_AI_BUDGET_POLICY_IMMUTABLE`；不存在为`SAND_AI_BUDGET_POLICY_NOT_FOUND`，参数错误为`SAND_AI_BUDGET_VALIDATION_ERROR`，来源核验失败为`SAND_AI_BUDGET_SCOPE_UNVERIFIED`。归档在同范围的独占advisory锁和版本行锁内，只CAS修改`state`及`update_time`并记录一次管理审计；与派发前选预算的共享范围锁串行，保留全部窗口、预占、待核实占额、冻结价格与用量事实。模型／能力停用不阻止超级管理员归档其已有预算。归档后新的调用不再命中此版本；无其他匹配预算时没有该预算限额，页面须在确认前明确提示。已发送调用继续按原预算成员结算，不退款或释放未知占额；归档版本仍可读取原额度，管理员可正常发布替代版本。失败或未知结果不自动重发，按原`id`读取版本确认。

时间使用 UTC `Y-m-d H:i:s`，时段为正且最长 366 天；同一 `scope_kind+scope_ref` 的已发布时段 `[start,end)` 不重叠，不以不同币种绕开冲突。已发布策略不可编辑，作用范围、时间、币种和金额随版本冻结。一个实际调用命中的全部预算共同生效；每个维度完整累计该调用金额，用量事实只记录一次，各维度窗口金额不能相加作为费用。任一硬预算不足时，调用及全部预占在发送前整体拒绝，不留下部分扣额；软预算允许超额并逐策略记录 `enforcement=soft` 与 `overrun=true`。所有策略必须与调用冻结价格使用同币种，不换汇。

`window` 含币种和字符串金额 `limit_amount`、`reserved_amount`、`settled_amount`、`held_amount`、`available_amount`；软预算可用金额可以为负。`alert` 含 `level=normal|notice|warning|exhausted`、`threshold_percent=null|80|90|100`，由当前窗口精确比较产生。管理页按范围读取当前额度并每分钟刷新；这是页内提醒，不发送站外通知。`active` 只表示所选单维度的额度，不推断某模型调用的全部适用预算。

两种方式都要求派发前可冻结价格与 Token/字符/音频秒数上界；未定价或不能证明上界的调用受预算时拒绝，不能伪装免费。未知已发生用量或未知服务商结果保持占额，不写零费用；确定未发送才释放。调用的单个 `reservation_id` 是同一实际调用预算组的锚点，结算、释放、持有、到期检查和重放覆盖全部成员，币种/价格/归属必须一致。音频转写按真实外部派发或本地执行尝试归属，不伪造模型 Invocation。

预算预占处于 `reserved` 或 `held` 时，内部 `metered_amount` 保持 `null`；`held_amount` 是按冻结预估保留的待核实额度，不能作为已计量费用。可用额度仍按 `limit_amount - settled_amount - reserved_amount - held_amount` 计算，未知计量金额不释放占额。历史 `held` 的零占位仅在锁定预算组后以 `state=held AND metered_amount=0` 的 CAS 规范化为 `null`，不重复移动窗口金额、生成持有事件或改写原到期原因；它不能证明调用免费。收到可信实际用量后按冻结价格结算，真实已测量的零费用仍可结算为零。该内部金额语义不增加公开预算响应字段，也不回填历史用量事实。

已跨发送边界的调用按冻结事实结算，后续预算耗尽或归档不取消其费用；父任务开始不代表未来步骤预获额度，每个实际模型节点、工具或重试独立检查当前策略。断线、取消、结果不明和恢复沿原键查询，不自动重新派发。公开聊天已具备显式 `@lowest-cost` 选择；候选按自身实际模型核对六维预算、同币种及可用硬额度，真实预占仍在原子发送准备事务中，竞态不足明确拒绝而不派发后重选。内部冻结模型不自动切换。

本节为六维候选冻结契约；源码候选、安装/迁移、真实限额并发及浏览器验收分别记录，不能据此勾选 4.3。

### 2.5 配置发布（C-05 补充）

环境和凭证相关接口同样属于迁移期兼容面。Provider、model、deployment 和 SandAI
capability profile 继续由 SandAI 管理；application、environment、workload client、
credential 和 service grant 的创建、轮换、撤销与访问审计迁移到 SandIAM。

| 目的 | 方法与路径 | 权限 | 写入/返回要点 |
| --- | --- | --- | --- |
| 查询环境 | `GET /setup/environment/index?application_id={id}` | `sand_ai:setup:environment:index` | 返回环境列表 |
| 创建环境 | `POST /setup/environment/save` | `sand_ai:setup:environment:save` | `{ application_id, code, name, status }` |
| 签发凭证 | `POST /setup/credential/issue` | `sand_ai:setup:credential:issue` | `{ environment_id, name, scopes, expires_at? }`；响应中的 `secret` 仅此一次返回 |
| 创建部署草稿 | `POST /setup/deployment/save` | `sand_ai:setup:deployment:save` | `{ model_id, provider_id, remote_model, config }`；新部署不可被运行面调用 |
| 发布部署 | `POST /setup/deployment/publish` | `sand_ai:setup:deployment:publish` | `{ id }`；同一逻辑模型只保留一个已启用部署 |

聊天模型的 `POST /app/sand-ai/admin/setup/deployment/save` 接收 `config: { max_output_tokens, input_token_ceiling?, reasoning_effort? }`。`reasoning_effort` 仅接受 `"low"`、`"high"`、`"max"`，显式 `null` 或其他值非法；省略时不向模型服务发送该字段，沿用其默认行为。管理员须按所选模型的说明确认是否支持，选项不代表所有服务商均支持，也不表示固定价格或质量保证。该值属于部署修订，不属于共享服务商连接或应用逐次请求参数；修改后须以新的保存幂等键保存、验证并发布。保存响应及 `GET /app/sand-ai/admin/setup/deployment/read?deployment_id=&revision=` 返回 `deployment: { remote_model, config }`；读回以指定修订的冻结 `draft_configuration` 为准，不读取可变的当前部署配置覆盖旧修订。管理端同键重试保持首次提交的额度，保存后锁定并展示服务器返回的修订值。

聊天模型部署 `config` 追加可选 `skill_output_strategy`：仅接受 `neuron_json_extract_v1`、`neuron_zai_json_object_v1`、`neuron_openai_json_schema_v1`；显式 null、其他值或非 chat 模型均拒绝。保存/指定 revision 读取响应的 `deployment.config` 反映原冻结值，不回填旧修订。首次配置页面“方法包输出”默认标准输出，另外可选 JSON 对象与 JSON Schema；保存前可选，保存幂等意图形成后锁定，重试保持原 DTO。选项是管理员对该模型协议能力的明确选择，不是按 endpoint/model 名猜测：JSON 对象要求 ZAI json_object 协议，JSON Schema 要求 OpenAI json_schema 协议。通用聊天验证不证明方法包格式或业务质量，仍需真实案例评测和显式发布。策略不改变普通 chat、机器人、材料等调用的输出协议。

正常首次配置页提供“已保存模型配置（只读）”：按正式已发布聊天部署列表的名称和修订选择，显式读取精确部署修订的冻结保存值；历史回执编号作为专家备用，也可从 deployment_id/revision 页面链接参数发起同正式GET。返回必须匹配所选id/revision。只读面板保留旧可选字段absence，不注入新建草稿或解锁模型验证/发布；不保存、不自动调用模型、不绑定环境。部署读取仍由当前后台管理权限控制，不作个人／团队或environment归属证明。

本地音频部署沿用 `POST /app/sand-ai/admin/setup/deployment/save`：新 Provider 的 `provider.adapter="local_runtime"`，模型为 `type="audio_transcription"` 且 `capabilities` 包含 `audio_transcription`，`remote_model="local_whisper_cpp"`、`config={}`。新 Provider 必须省略 `connection`，远端连接或密钥拒绝；已有 Provider 用 `provider_id` 复用，不能覆写连接。工具、模型、FFprobe/FFmpeg 和超时由宿主现有 `sand_ai_audio_execution.local_whisper_cpp` 配置提供，接口不接受本机路径。实际文件摘要与配置由私有 HMAC 冻结于草稿和执行快照，保存、验证、发布或执行时漂移均拒绝，公开响应不返回路径、原始输出或私有指纹。

`POST /app/sand-ai/admin/setup/deployment/verify` 对本地部署要求 `processing_location="local_runtime"`，保持 `deployment_id`、`revision` 和 `Idempotency-Key`。验证必须对包内固定摘要的 `transcription-sample.wav` 实际转码、探测时长和 Whisper 识别，取得非空且时间合法的片段后才成为 `tested`。失败保持 `verification_ambiguous`，公开 `failure.reason="local_runtime_verification_failed"`、`recovery_action="configure_local_runtime_and_save_draft"` 和安全中文恢复提示，须新草稿修订重验；不回传进程输出。Token 用量未知为 `null`、费用为 `untracked`，不推定免费。正式发布与能力档保存、发布入口不变；`local_whisper_cpp` 路线只能绑定 `local_runtime` 的已发布对应部署，外部路线仍只绑定 `openai_compatible` 部署。

已发布部署不得携原 `deployment_id` 原位保存；服务端返回 `SAND_AI_RESOURCE_CONFLICT`，要求另存后继部署。后继保存使用原 `provider_id`、`model_id`、冻结的 `remote_model` 与新的 `config`，省略 `deployment_id`、`provider`、`model`、`connection`，并使用新的 `Idempotency-Key`；既有 Provider 不接受连接覆写，服务器复用其已保存连接，前端不得读回或要求重填 Key。`GET /app/sand-ai/admin/setup/deployment/index?model_type=chat` 返回 `deployment_revision`，用于读取选定已发布修订。保存返回新部署 ID 和修订；验证、发布均作用于这个新对象。正式 onboarding 发布后继不会停用旧部署，也不自动切换已有能力路线；已有不同能力档不能用首次开通的 `bind-default-chat` 覆盖，管理员须在能力配置页保留其他路线、显式选择新部署，按 `expected_revision` 保存并发布新的能力档。

凭证 scope 仅允许 `models:read`、`chat:write`、`invocations:read`、`files:read`、`files:write`、`tasks:read`、`tasks:write`。发布会将对应配置版本固定为 `published`；运行 API 只读取已发布且启用的部署。

对外 `GET /api/sand-ai/v1/invocations/{requestId}` 与 `GET /api/sand-ai/v1/invocations/{requestId}/cost` 分别使用 SandIAM `sand_ai.invocation.read`、`sand_ai.usage.read` 动作。应用按环境及请求号找到持久调用后，以调用和派发账本中的组织、应用、环境、工作负载客户端、分级事实取得正式资源授权，才返回状态或费用；费用事件的四级归属还须与已授权调用一致。历史记录缺可信归属/派发事实时拒绝，不按环境相同推断有权读取。

上述两个读取入口在网关读完结果／费用后、返回成功响应前，使用同一正式 `authorization_id`、原签名上下文、可信连接 IP、请求号和持久调用资源，再经 SandIAM `revalidateInvocation` 重验当前授权；失败不返回已读 DTO。此重验复用原授权，不再发起一次消耗调用配额的授权。它是读取边界的源码约束，真实撤权、过期、配额及审计验收仍待完成。

单次调用没有用量事件时，费用接口依据持久派发终态返回 `usage.state`：`not_dispatched`（未发给服务商）、`failed`（确定拒绝）、`ambiguous`（结果不确定）、`pending`（准备中或派发中）、`missing_event`（派发成功但用量事件缺失）。这些状态的 Token、计量金额、账单金额均为 `null`，不伪造零费用；`reconciliation.state` 分别为 `not_applicable`、`held`、`held`、`pending`、`held`。这里的 `held` 表示账单核对待处理，不等于预算预占仍被持有。价格修订和币种只能来自调用开始时冻结的有效价格快照。`missing_event` 是待排障状态，不代表成功计量。

已有用量事件但 `usage.state=unmeasured` 时，单次费用接口、运行摘要、即时调用结果及 Skill 幂等重放的三个 Token 字段均返回 `null`；只有已测得用量才返回数字，实际测得的 `0` 保留为 `0`。未知用量不能由存储层占位零推断为零消耗。

聊天模型草稿的 `POST /app/sand-ai/admin/setup/deployment/verify` 和首次开通的 `POST /app/sand-ai/admin/setup/onboarding/verify-model` 均以服务商返回的非空聊天内容确认该模型可调用。服务商未返回有效 Token 用量时仍可验证和发布，但证据中 `usage_measured=false`，三个 Token 字段为 `null`；已测得用量时必须保存非负整数和精确总数，不能把缺失值写成零。验证成功不等于已定价或可用于依赖精确用量的预算、低成本路由；实际运行仍按用量事件的 `unmeasured` 状态计费与展示。证据与处理位置、部署修订和冻结执行快照继续绑定，幂等及来源校验不放宽。

首次开通 3.2 的严格输入与失败反馈已按通过独立安全复核的源码候选同步；该候选尚未安装宿主，真实 HTTP、Provider 与页面验收尚未完成。以下契约不表示错误 Key、超时、无权或无模型 Key 应用调用链已通过运行验收。首次使用者为已登录的 SandAI 后台管理员，使用服务商控制台提供的 HTTPS API 基址、Key 与远端聊天模型标识；业务应用继续通过 SandIAM 授权调用已发布服务，不接收服务商 Key。

`POST /app/sand-ai/admin/setup/onboarding/discover-models` 仅接受 `{ connection }`；`POST /app/sand-ai/admin/setup/onboarding/verify-model` 仅接受 `{ connection, remote_model, max_output_tokens, input_token_ceiling? }`，Token 额度沿用 1–131072 整数契约。`connection` 仅接受 `{ base_url: string, api_key: string, extra_headers? }`；非字符串、非法 UTF-8、控制字符及未知字段返回 `SAND_AI_ONBOARDING_CONNECTION_INVALID`。控制字符须在去除首尾空格之前拒绝，不能静默删除换行或制表符。请求字段、远端模型标识、Token 额度、管理员身份及 `extra_headers` 的字段、数量和内容须在 DNS 解析和可能计费的临时模型验证之前通过检查；请求头规范化结果复用，非法请求不得触发 DNS 或第三方外发。字段严格化不再兼容依赖未知字段被静默忽略的调用方，现有发现请求 `{ connection }` 保持符合契约。

首次开通的聊天草稿使用既有 `provider.timeout_ms`，向导以秒设置 1–120，默认 120 秒；保存意图形成后冻结该值，已有部署及服务商不因默认值调整而迁移。直接 `verify-model` 的新验证及成功后新建服务商默认使用 120000 毫秒；模型发现仍使用 10000 毫秒。临时验证传输上限为 120 秒，部署验证页面单次等待 150 秒，沿用宿主零自动重试。已有部署验证继续使用该服务商原保存值及原快照／回执；超时或浏览器中断仍可能已发送并计费，须读取原修订并核对服务商记录，不自动重发。此默认值只限定等待时间，不保证模型在期限内成功。

首次开通的明确本地输入错误沿宿主响应封套返回 `code: 400`（HTTP 200）：`SAND_AI_VALIDATION_ERROR`、`SAND_AI_ONBOARDING_CONNECTION_INVALID`、`SAND_AI_ONBOARDING_HEADER_INVALID`、`SAND_AI_ONBOARDING_MODEL_INVALID`。空／全空格 Key、控制字符、缺失或错误类型的连接对象、非法模型标识及 Token／超时边界不是服务器故障，响应提供固定中文修正指引。只有敏感中间件尚未进入下游 handler，或草稿控制器在进入持久 save 服务前的纯请求策略预检拒绝，才返回 `data: { error_code, retryable: false, submission_state: "not_submitted" }`。SandAI 保存 API 通过宿主现成的每请求 `transformResponse` 保留这组有限元数据，响应封套原样交给原业务与登录拦截器，不改全局 HTTP 客户端或建立第二客户端。页面须同时核实 `code: 400`、上述有限输入码及结构化 `submission_state=not_submitted` 才解除尚未保存的输入锁，让管理员修正后重新保存，不依赖中文文案判定。同一保存意图若已有结果不明的较早尝试，即使随后获得本次未提交证明，也须保留原参数与幂等键，不能据此释放或重新建配置。其他阶段即使返回 400，也不提供该证明；已保存、部分失败、未知或服务商失败继续保留原冻结保存意图与幂等键。`not_submitted` 只证明该保存操作未进入持久保存／Provider 派发，不改变平台认证防重放 nonce，也不解锁原验证或未知回执。格式错误的 JSON、非对象请求和不支持的配置字段在上述中间件前置阶段拒绝，所有失败路径继续清除秘密。后台权限拒绝、未登录、服务商失败和未知系统错误保留各自原分类；不由 400 推断已验证能力、无费用或可以重发原未知验证。

发现与临时手动验证继续使用既有错误码和已净化的本地文案，不返回服务商原始响应。服务商 HTTP 401 返回 `SAND_AI_PROVIDER_REJECTED`，提示核对 Key 有效性及所属服务地址；服务商 HTTP 403 返回同一码，提示核对账号、项目、模型和接口权限。模型列表 HTTP 404/405 返回同一码，提示从控制台取得聊天模型标识并手动填写后验证，不能由此断言聊天接口不可用。cURL errno 28 返回 `SAND_AI_PROVIDER_UNAVAILABLE`，提示区分模型发现超时与临时模型验证超时，后者可能已发送并产生费用；其他网络失败沿用通用不可用提示。成功响应缺少有效聊天内容返回 `SAND_AI_PROVIDER_FAILURE`，要求确认聊天能力，不按模型名推断能力。空列表仍返回 `{ models: [], state: "discovered" }`，页面应允许手动填写，不伪造可用模型。

密钥保护中间件清除请求参数和原始请求体中的秘密字段，并在 `finally` 清除请求级 Vault；保留宿主 `ApiException` 的公开业务响应。仅已核实宿主 `CheckAuth` 中 code 为 400 且固定消息为“权限不足，无法访问或操作”的异常转换为管理响应 `code: 403`，消息为“当前后台账号没有执行此操作的权限”；这表示后台权限不足，不能解释为服务商拒绝 Key 或账号权限。未知系统异常仍泛化为 `SAND_AI_ONBOARDING_REQUEST_FAILED`，不暴露异常原文；宿主升级后须重新核实上述异常格式。

页面使用的 `POST /app/sand-ai/admin/setup/deployment/verify` 继续沿部署草稿验证链执行。非流式响应先按服务商 HTTP 状态分类，401/403 即使正文为 HTML 或空也返回既有 `SAND_AI_PROVIDER_REJECTED`；流式与非流式共用本地状态分类，cURL errno 28 归 `SAND_AI_PROVIDER_UNAVAILABLE`。400/422 仅说明模型或参数被拒绝，不能推断 Key 无效；404 仅说明请求的模型或接口未提供，不能断言模型不存在；405 表示聊天接口未被接受。

`GET /app/sand-ai/admin/setup/deployment/verification/read?deployment_id=&revision=` 与部署验证成功响应在既有 DTO 中增加可选兼容字段 `failure`，其结构为：

```json
{
  "failure": {
    "error_code": "SAND_AI_PROVIDER_REJECTED",
    "reason": "invalid_api_key",
    "message": "服务商未接受 API Key，请核对有效性及所属服务地址，修正后可重新配置连接",
    "recovery_action": "reconfigure_connection",
    "outcome_uncertain": false,
    "failed_at": "2026-10-02 12:00:00"
  }
}
```

`failure` 缺失或为 `null` 不表示从未失败，而是当前状态没有可公开的明确失败反馈；旧调用方可以忽略该字段。`tested`、`published`、`verifying` 状态返回 `null`，避免旧失败覆盖新状态。失败反馈仅从指定修订 payload 的本地固定 `code`、有限 `reason` 和时间重建，不保存或回显异常消息、服务商正文、端点、Key 或连接参数。`failed_at` 为字符串或 `null`，时间格式不可信时返回 `null`。

Embedding 部署验证失败还可包含 `failure.diagnostic`，只用于安全归因，旧修订没有诊断时为 `null`。它仅接受 `{ http_status: integer|null, failure_stage, response_shape: object|null }`：状态码范围 100–599；阶段为 `http_status`、`response_limit`、`invalid_json`、`response_shape` 或 `transport`。形状对象只含 `root_type`（`object`/`array`/`scalar`）、`data_is_list`（布尔）、`data_count` 与 `first_vector_dimensions`（非负整数或 `null`）、`usage_present` 与 `error_present`（布尔），不含向量值、服务商错误文本、响应正文、HTTP 头或请求参数。未知／损坏诊断返回 `null`，历史状态码不补推。非 2xx 只证明 HTTP 状态，不足以判断余额、权限或 Key；形状摘要也不证明计量。上述失败仍保持 `verification_ambiguous`、费用未知和原恢复指引，不提供自动重发许可。

| `reason` | 匹配的 `error_code` | 本地提示含义 |
| --- | --- | --- |
| `invalid_api_key` | `SAND_AI_PROVIDER_REJECTED` | Key 未被接受，核对有效性及所属服务地址 |
| `provider_access_denied` | `SAND_AI_PROVIDER_REJECTED` | 核对账号、项目、模型和聊天接口权限 |
| `model_or_endpoint_unavailable` | `SAND_AI_PROVIDER_REJECTED` | 核对聊天模型标识与 API 基础地址 |
| `chat_endpoint_unsupported` | `SAND_AI_PROVIDER_REJECTED` | 确认模型支持聊天且 API 基础地址正确 |
| `model_or_parameters_rejected` | `SAND_AI_PROVIDER_REJECTED` | 核对聊天能力、Token 额度与推理额度 |
| `provider_rejected` | `SAND_AI_PROVIDER_REJECTED` | 通用拒绝，核对 Key、权限、模型和参数 |
| `provider_timeout` | `SAND_AI_PROVIDER_UNAVAILABLE` | 验证超时，可能已产生费用，先核对调用记录 |
| `provider_unavailable` | `SAND_AI_PROVIDER_UNAVAILABLE` | 连接或响应未确认，核对调用记录和当前修订 |
| `provider_rate_limited` | `SAND_AI_PROVIDER_RATE_LIMITED` | 限流，先核对当前修订与调用记录 |
| `verification_ambiguous` | `SAND_AI_DEPLOYMENT_VERIFICATION_AMBIGUOUS` | 结果不明，保留部署与修订号并联系管理员核查 |

`reason` 仅接受上表有限值，须与 `error_code` 匹配；未知、损坏或不匹配的历史原因返回 `failure: null`，不回显历史内容。历史失败只有 code 时仅重建通用原因，不推断 Key 无效或超时。`message` 为后端按上述事实重建的固定安全文案，页面只显示该 DTO 的文案，不从异常原文、HTTP 状态或模型名称自行推导失败原因。

`outcome_uncertain` 根据当前修订状态决定，`recovery_action` 仅为 `check_provider_record` 或 `reconfigure_connection`。当前状态为 `verification_ambiguous` 时，`outcome_uncertain=true`、`recovery_action=check_provider_record`，先核查服务商调用记录，不重复验证此修订；聊天请求确定拒绝并回到 `draft` 时，`outcome_uncertain=false`、`recovery_action=reconfigure_connection`，修正后重新配置连接。状态判定、幂等和结果不明的保守规则保持不变，`failure` 不提供自动重发许可。其他模型类型派发后失败仍保持结果不明，即使原因是服务商拒绝也须先核查记录。前端须读取精确部署修订后据该状态与 DTO 控制恢复动作；缺失或 `null` 时保留通用指引，不能借缺失反馈放开结果不明的重复请求。

`GET /app/sand-ai/admin/setup/onboarding/status?environment_id=` 沿现 `sand_ai:onboarding:read` 权限增加可选 `internal_security: { ready: boolean, missing_groups: string[], invalid_groups: string[], recovery_action }`。类别只允许 `provider_config`、`idempotency`、`queued_identity`、`outcome_receipt`；恢复动作为 `none`、`initialize_or_restore` 或 `restore_original_material`。状态只读当前进程已加载的六项内部加密/HMAC配置及相关已有keyring合法性，不读取或返回具体值、Key ID、路径或指纹，`ready` 不表示文件权限、模型、IAM或整产品已验收。缺项时已有 `stage.next_step` 提示联系宿主操作员，不提供自动初始化按钮/HTTP写入入口。

完整源码候选提供正式 Webman CLI `php webman sand-ai:security protect-config`、`php webman sand-ai:security initialize` 与 `php webman sand-ai:security status`，操作员在正式安装后的宿主 `server` 目录执行；同owner先 `chmod go-w .` 收紧该目录组/其它写权限，再执行 `protect-config`。该显式动作只接受owner普通非链接、单硬链接且权限恰0644或0600的已有配置，稳定锁/CAS与私有原字节备份后原子保存0600，不生成内部材料；其它权限、所有权、链接或备份冲突拒绝。初始化仅在完整普通私有0600环境配置与可靠首次数据保护下生成全缺组；partial、非法、历史数据、原配置漂移或备份冲突明确拒绝。旧配置原字节、合法材料和keyring永不覆盖，四组独立随机；Provider API Key、人员Token与IAM凭据不生成。成功保存后先新CLI进程status，再由操作员按正式服务生命周期reload；初始化命令不重启/迁移/建库/验证模型。升级、卸载及保留加密数据的回退保留密钥，删除需另有数据与备份清理授权。当前是源码候选，旧公开包没有该CLI，本任务独立POSIX宿主已实测权限保护、fresh initialize/status、重复不变、partial及历史依赖丢Key拒绝；消费Package用了已记录临时launcher补丁，未证明纯原版生命周期，升级/卸载/回退全门仍未完成，Windows ACL初始化未验证并明确拒绝，不能冒称安装自动hook完成。

首次开通的模型发现、临时手动验证与部署草稿保存共用 `remote_model` 标识规则。标识从服务商控制台或模型列表取得；须为有效 UTF-8，先拒绝全部 Unicode `Cc` 控制字符（含 C1），再去除前后空格；处理后非空且最多 256 字节。允许 `namespace/model`，拒绝前导／重复斜杠、反斜杠及 `.`／`..` 跳转段。手动验证和草稿保存的非法标识返回 `SAND_AI_ONBOARDING_MODEL_INVALID`；发现列表过滤非法标识，原列表非空但无可用项时返回 `SAND_AI_PROVIDER_FAILURE`，不同有效标识超过 100 个时返回 `SAND_AI_ONBOARDING_RESPONSE_TOO_LARGE`。标识作为固定服务商端点请求体中的模型值，不用于构造 URL、路由或文件路径。后台逻辑模型名称仍最多 160 字节；向导可另填显示名称，默认名称安全缩短不改变完整远端标识。发现成功只说明账号返回该标识，聊天和材料能力仍须实际模型验证及材料样本核验。

既有部署草稿的保存、验证、发布入口还支持 `audio_speech` 模型。新模型的 `type` 与 `capabilities` 均须包含 `audio_speech`；部署 `config` 只接受 `{ verification_voice }`，音色须符合受控标识格式，聊天部署接受上述 Token 上下限及可选推理额度。验证请求继续只传部署 ID、修订、处理位置及幂等键；服务端以冻结的远端模型、音色和固定短文本向受控语音合成连接器发出一次真实请求，响应须通过媒体类型、长度、ID3 标签和连续 MP3 帧结构检查才形成通过证据；该检查不代表完整音频解码验收。验证音频不返回管理端、不存储为业务结果；该次验证费用状态为 `untracked`，Token 用量为 `null`，不冒充正式语音合成运行的字符计量。派发后的失败保持结果不明，不能按同一草稿修订自动重发。首次开通的 `verify-model` 仍仅验证聊天模型；发布语音部署也不自动绑定环境能力路由或签发 SandIAM 授权。

同一部署草稿入口还接受 `audio_transcription` 模型，其 `capabilities` 须包含该类型，部署 `config` 必须是空对象，远端模型仅为 `whisper-1`、`gpt-4o-transcribe-diarize` 或 `glm-asr-2512`。验证使用随应用载荷交付、摘要固定的英文短口播 WAV，经受控 HTTPS 音频连接器发出一次请求；`whisper-1` 要求分段 JSON，`gpt-4o-transcribe-diarize` 要求带 `chunking_strategy=auto` 的说话人 JSON；`glm-asr-2512` 使用独立文字转写连接器和可信媒体时长探测，将有效非空文字绑定到整个验证样本范围，不要求不存在的分段或说话人字段。响应须符合现有转写结果契约且至少含一个非空识别片段。通过只证明该部署能返回结构有效的语音识别结果，不证明会议场景准确率。验证失败保持结果不明，同一草稿修订不重发；该次调用不建立业务音频任务或用量事件，费用状态为 `untracked`。发布不自动绑定能力路由或签发 SandIAM 授权，首次开通的 `verify-model` 仍仅验证聊天模型。

同一部署草稿入口还接受 `embedding` 模型，其 `capabilities` 须包含 `embedding`，部署 `config` 必须是空对象。验证使用固定的非敏感短文本，对冻结远端模型的受控 HTTPS `/embeddings` 端点仅发出一次请求；响应必须恰有索引 `0` 的一个向量，维度为 1–4096、各分量为有限数且向量范数大于零。验证向量和服务商原始响应不返回、不记录为业务结果。`usage.prompt_tokens` 为非负整数时记录已测得 `input_tokens`、`output_tokens=0` 和相同总数；缺失或无效时三个 Token 字段为 `null`、`usage_measured=false`，费用状态仍为 `untracked`。可能已发出的失败保持结果不明，同一草稿修订不得自动重发；通过证据只证明一次结构有效的 Embedding 响应，不代表知识索引质量或运行计费验收。发布不自动绑定能力路由或签发 SandIAM 授权，首次开通的 `verify-model` 仍仅验证聊天模型。

首次开通发布模型后，管理员可显式调用 `POST /app/sand-ai/admin/setup/onboarding/bind-default-chat`，请求体为 `{ environment_id, deployment_id }`，权限 `sand_ai:onboarding:publish`。只允许已发布且与目标 SandIAM 环境相容的部署；目标环境已有不同的已发布能力档时返回冲突，不覆盖现有路由。页面须要求管理员先选择环境并点击绑定，不能将“发布模型”显示为“应用已可调用”。

材料整理引导保存聊天模型草稿时，可由管理员选择是否在模型能力声明中加入 `structured_extraction`。该声明仅允许后续配置候选路由；聊天验证不证明材料抽取质量。材料整理配置仍须通过实际样本、来源与计量核验后单独发布；未选择时不得自动给模型补上该能力。

### 2.5.2 已发布模型部署目录（API 1.14 / SAND-116）

此接口只供管理端以业务名称选择可用模型部署；它不创建、保存、发布、绑定或调用任何资源。

| 目的 | 方法与路径 | 权限 |
| --- | --- | --- |
| 分页读取可选择部署 | `GET /setup/deployment/index` | `sand_ai:setup:deployment:read` |

仅接受查询字段 `page`、`limit`、`name`、`model_type`、`capability_code`、`environment_id`。`page` 默认 `1`，`limit` 默认 `10`、最大 `100`；未知字段、空白后仍非法的 ID、超界分页、未注册能力、非模型部署能力、模型类型不在代码注册表、或不满足该类型的能力，均返回 `SAND_AI_VALIDATION_ERROR`。

- `name` 是对逻辑模型名称、服务商名称和供应商模型名的大小写无关子串筛选，最大 160 个字符；不按配置、密钥或数据库内部 JSON 搜索。
- `model_type` 只接受代码注册的模型部署能力所声明的类型（当前为 `chat`、`embedding`、`rerank`）。
- `capability_code` 只接受 `CapabilityDriverRegistry` 中 `route_kind = model_deployment` 的能力，且必须与 `model_type` 相容并同时传入 `environment_id`；它不会采信 `sand_ai_model.capabilities` 中任意未注册值。
- 未传 `environment_id` 时，目录只说明一个部署已发布；`egress_policy` 为 `null`，`source.environment` 固定为 `not_configured`，`capabilities` 为 `[]`。这不是“可被任何环境调用”的声明。
- 传 `environment_id` 时，先通过 SandIAM 的 `EnvironmentReferenceVerifier` 验证该不透明引用；SandAI 不查询或写入 `sand_iam_*`。结果仅保留该环境当前 `published + active` capability profile 的 `active` model-deployment routes，`capabilities` 仅列出代码注册、模型类型相容且实际指向该部署的能力；`egress_policy` 来自该已发布 profile，`source.environment` 为 `published_capability_profile`。没有可用 profile/route 时返回空分页，而非回退到模型声明能力。

每一项固定返回：`id`、派生的 `display_name`、`model_name`、`model_type`、`provider_name`、`provider_adapter`、`remote_model`、`publication_status`、`deployment_revision`、`egress_policy`、`source` 和 `capabilities`。`display_name` 只能由 `model_name`、`provider_name`、`remote_model` 以固定顺序派生。所有项必须同时满足 Provider、逻辑模型和 Deployment active 且未软删，以及当前 ConfigRevision 为 `published + active`；不得读取、解密、返回或日志化 `encrypted_config`、deployment `config`、revision `payload`，也不得写入 audit、Usage 或任何业务表。

这是向后兼容的读取增量；既有保存、发布、能力配置和运行 API 的路径、DTO 与语义不变。U-23 可在本节契约和无数据库验收均完成后消费；真实 SaiAdmin 登录态、SandIAM 环境授权和 HTTP 路径仍须另行验收。

### 2.6 环境能力配置档（SAND-110）

能力配置档是**环境级**的选择层：同一 SandAI 底座可让不同应用/环境选用不同模型部署、解析驱动、优先级和数据外发策略。它不是把厂商密钥、对象存储位置或业务审批状态下放给运行 API。

| 目的 | 方法与路径 | 权限 | 要点 |
| --- | --- | --- | --- |
| 读取能力与模板元数据 | `GET /setup/capability/metadata` | `sand_ai:setup:capability:metadata` | 返回代码注册的能力/驱动元数据及简化模板 |
| 读取最新配置档 | `GET /setup/capability/profile/read?environment_id={id}` | `sand_ai:setup:capability:read` | 返回配置档和路由，不返回任何密钥 |
| 保存配置草稿 | `POST /setup/capability/profile/save` | `sand_ai:setup:capability:save` | `{ environment_id, expected_revision, name, template_code?, data_egress_policy?, routes }`；首次创建传 `null`，修改传读取结果中的正整数 `revision`；服务端在环境事务锁内对比最新修订，不一致返回 `SAND_AI_RESOURCE_CONFLICT`，不创建草稿或覆盖既有路线 |
| 发布配置档 | `POST /setup/capability/profile/publish` | `sand_ai:setup:capability:publish` | `{ environment_id, id }`；每环境仅一个已发布版本 |

读取、保存及发布响应均为 `{ profile }`；无配置档时读取的 `profile` 为 `null`。响应中每条路由包含 `id`、`capability_code`、`route_kind`、`deployment_id`、`driver_code`、`priority`、`status`。只有服务端确认存储配置为空时才额外返回 `config: {}`（JSON 对象，不能返回空数组）；当前持久化转换会将合法空对象或空数组解码为空数组。非空、缺失、异常或其他类型的历史配置一律省略 `config`，不得返回其内容。编辑器读取既有配置档后，必须确认每条原路由均有空对象 `config` 才能保存；字段缺失不表示空，不得用 `{}` 补齐后覆盖，也不得通过删除该路由或应用模板绕过检查。

保存请求的 `config` 只允许省略或为空对象 `{}`，不接受非空配置。保存时服务端在环境锁与最新配置档锁下复核该档的全部路由（含停用路由）：任何存储配置不能确认为空时，返回 `SAND_AI_RESOURCE_CONFLICT`，不创建新配置档、配置修订或审计记录，保护旧 API 客户端免于静默替换历史配置。原档及路由值保持保留；历史非空或异常配置须经专门处理后才能编辑，当前向导和专业配置页不提供此处理。此保护不改变空配置的正常保存、权限或发布契约。

`routes` 中每项为 `{ capability_code, route_kind, deployment_id?|driver_code?, priority, config? }`。`route_kind` 必须与代码注册表匹配；同一能力内 `priority` 唯一。模型能力只能指向类型匹配、启用且已发布的部署；解析驱动只能选择代码注册驱动。`audio_driver`（录音转写、语音合成）同时要求 `deployment_id` 与该能力注册的 `driver_code`，管理端须允许填写并一并提交；保存和发布仍由服务端复核部署、驱动与外发策略。

已发布部署目录 `GET /app/sand-ai/admin/setup/deployment/index` 的 `model_type` 支持注册为 `model_deployment` 或 `audio_driver` 的模型类型；`environment_id` 加 `capability_code` 的过滤也支持这两类已发布路由，音频路由须有注册的 `driver_code`。目录按模型类型、已发布部署修订及处理位置过滤，不返回服务商密钥。能力配置页对两类需要部署的路由均按名称选取已发布部署，最终仍由保存及发布入口校验能力、驱动和外发策略。

`data_egress_policy` 取值：`local_only`、`private_only`、`approved_cloud`。前两者只允许本地驱动/本地 Provider；`approved_cloud` 才允许已审核的云 Provider。保存、发布和运行时均校验，不能通过数据库配置绕过。

模板：`local_development`（本地文档解析）、`private_data`（私有数据优先）、`domestic_cloud`（已审核国内云）、`hybrid_best`（本地解析加可选云模型）。模板只是起点；高级配置通过 `routes` 明确选择，避免不同项目被单一方案锁死。

运行选择规则：某环境有已发布能力档时，聊天模型清单与聊天调用仅使用该档的 `chat` 路由，文件解析仅使用该档的 `document_parse` 路由；未发布能力档的既有环境继续使用原 `ApplicationModelGrant` 与本地解析回退，保证向后兼容。能力档不含某能力时，运行面返回稳定能力不可用错误，不偷偷降级到未审核的厂商。

### 2.7 文件运行 API（SAND-102）

文件属于单一 `environment_id`，运行 API 只能访问当前凭证所属环境的文件。数据库只保存 SandAI 相对 `object_key`，不向客户端返回 bucket、endpoint、对象存储密钥或永久 URL。

| 目的 | 方法与路径 | 凭证 scope | 请求/返回要点 |
| --- | --- | --- | --- |
| 上传文件 | `POST /api/sand-ai/v1/files` | `files:write` | `multipart/form-data`，单文件字段 `file` |
| 查询文件 | `GET /api/sand-ai/v1/files/{fileId}` | `files:read` | 返回所属环境的文件元数据、状态和最近一次解析摘要 |
| 提交解析 | `POST /api/sand-ai/v1/files/{fileId}/parse` | `files:write` | 创建或返回同一文件的活动 `file_parse` 任务；响应立即返回，不同步提取正文 |
| 删除文件 | `DELETE /api/sand-ai/v1/files/{fileId}` | `files:write` | 先标记删除，再删除私有对象；失败保留可排障状态 |
| 查询任务 | `GET /api/sand-ai/v1/tasks/{taskId}` | `tasks:read` | 返回本环境内任务及步骤状态，不返回原文、对象 key、密钥或完整处理结果 |
| 取消任务 | `POST /api/sand-ai/v1/tasks/{taskId}/cancel` | `tasks:write` | 只可取消非终态任务；已被 Worker 完成的任务保留最终状态 |
| 重试失败任务 | `POST /api/sand-ai/v1/tasks/{taskId}/retry` | `tasks:write` | 默认仅允许 `failed` 任务重新入队；不修改历史尝试次数。材料逐文件重试目标及限定 `receipt_only` 恢复见 §5.5 |
| 提交 AI 任务 | `POST /api/sand-ai/v1/ai-tasks` | `tasks:write` | `{ model, messages, source_block_ids? }`；异步返回任务，不在请求内调用模型 |
| 私有来源检索 | `POST /api/sand-ai/v1/retrieval/search` | `retrieval:read` | `{ query, limit? }`；只检索当前环境已解析私有文件的来源块 |

上传文档允许 `pdf`、`docx`、`txt`、`csv`、`xlsx`、`png`、`jpg`、`jpeg`、`webp`，默认上限为 50 MiB（可通过运行环境配置收紧）。服务端以临时文件实际大小、文件扩展名和探测 MIME 校验，并计算 SHA-256；不得信任客户端声明的 MIME 或文件名路径。CSV 使用逗号分隔及双引号转义，带 UTF-8 BOM 或常见中文编码的单元格可解析；非空记录形成 `table_row` 来源块，`locator.row` 是该记录起始的物理行号，块内容以单元格坐标为键。XLSX 只解析可见工作表；非空行形成 `table_row` 来源块，`locator` 包含工作表名称及行号，内容按单元格坐标索引。数值保留文件中的原始值，不按样式推断日期或货币；可见工作表中的公式或错误单元格、以及外部工作表关系均拒绝解析，避免把未核实的计算缓存写成事实。

文件 DTO：`{ id, original_name, extension, media_type, size_bytes, sha256, state, latest_parse|null, create_time, update_time }`。`latest_parse` 为 `{ id, parser_code, parser_version, state, source_block_count, error_code|null, error_summary|null, started_at, completed_at, task? }`；它不返回原文、对象 key、Bucket 或下载 URL。

材料样本页面上传、读取文件、提交解析、提交样本、按原请求键找回样本以及读取运行／来源时，携带可选声明头 `X-Sand-AI-Environment-ID`，值为页面所选环境的规范正整数十进制 ID。服务端先取得 SandIAM 已验证身份，再将声明与该身份的环境 ID 精确比较；不相符返回 HTTP 400 `SAND_AI_ENVIRONMENT_MISMATCH`，空值、前导零、非整数或超出运行时整数范围返回 HTTP 400 `SAND_AI_VALIDATION_ERROR`。比较发生在文件元数据读取、私有对象写入、解析任务创建、样本查找／入队或来源读取之前；声明不能指定或覆盖实际文件、调用及计量归属，原 SandIAM 资源授权继续执行。未携带该头的既有应用请求保留原行为。普通工作流提交和按键找回不新增该头约定；运行 ID 读取／来源是样本复用的既有入口，携头时执行上述比较。

提交解析的响应为 `{ idempotent_replay, file_id, parse, task }`。相同环境内同一个 `X-Request-Id` 重放会返回原 `task`，其持久 `task.file_parse_id` 指向原解析；响应顶层 `parse` 来自文件当前最新解析摘要，不保证与原 `task` 属于同一次解析，不能将整个响应视为原始冻结结果。同一文件已有 `queued`、`running` 或 `retrying` 任务时返回该活动任务，不创建重复解析。`task` 为 `{ id, task_type, resource_type, resource_id, file_parse_id, state, attempt_count, max_attempts, available_at, started_at, completed_at, canceled_at, error_code, error_summary }`。任务查询额外包含 `steps`，每个步骤为 `{ code, state, attempt_count, started_at, completed_at, error_code, error_summary }`。

仅 `task_type=agent_run` 的任务读取另返回 `embedding_invocation: null|{state,error_code}` 与 `embedding_usage_cost: null|{usage_state,input_tokens,output_tokens,total_tokens,pricing_state,metered_amount,currency,price_revision_id}`。本次任务未登记查询 Embedding 子调用时，两项为 `null`；子调用已登记后，其派发状态独立于父任务展示，`state` 使用子派发账本的 `prepared`、`dispatching`、`ambiguous`、`not_dispatched`、`failed` 或 `succeeded`，受控失败只给 `error_code`。已产生的计量事件独立投影到 `embedding_usage_cost`，即使父任务随后失败、取消或当前索引失效也须保留；尚无计量事件时该项为 `null`，不能据此认定免费。`usage_state=unmeasured` 时三个 Token 为 `null`；`pricing_state` 未确定费用时 `metered_amount=null`，不得显示为零，金额也不代表服务商已对账。查询 Embedding 的稳定内部子调用键由原任务 ID、固定检索节点 `retrieval.search` 和知识发布 ID 派生；活动索引身份冻结在请求指纹与派发范围中，索引切换须拒绝，不能换键重发。同一任务重试先恢复该子调用，派发结果不明时不得自动重发。任务响应不返回子调用请求号、调用 ID、查询向量、加密回执或服务商原始响应。

任务仅表达 AI 处理工作，不代表或驱动业务审批。Worker 使用条件领取、有限重试和过期租约恢复；格式不支持、空内容、文件无效及未配置能力等确定性的解析拒绝不自动重试，暂时不可用的 OCR 可重试。处理失败时仅返回稳定错误码与摘要，绝不返回原文件文本、密钥、堆栈或厂商原始响应。

AI 任务 `ai_completion` 使用同一任务状态机。`source_block_ids` 必须都属于当前环境的私有文件；Worker 将受控来源块传给已被该环境能力档批准的模型，并在 `task.result.source_references` 返回 `{ source_block_id, file_id, locator_type, locator }`。任务读取结果可以包含生成内容与调用摘要，但永不回显原始 prompt、来源块原文、对象 key、密钥或厂商原始响应。任务请求的 `X-Request-Id` 只会创建一个 AI 任务；重试使用独立的调用记录以保留每次尝试的审计证据。

AI 任务的排队授权在 Worker 开始执行及模型发送前均须重验；发送前还须重新核对持久来源块归属和冻结分级。幂等结果重放前也重验原任务授权，撤权或来源漂移不得返回旧结果。

检索结果为 `{ query, matches }`，其中每个命中仅包含 `{ source_block_id, file_id, locator_type, locator, score, excerpt }`。`excerpt` 最多 500 个字符，仅通过当前环境的凭证返回，不能视为公开下载能力。首期使用 PostgreSQL 内置全文检索、转义后的整句 `ILIKE` 与最多 8 个中文相邻字片段召回；智能体与私有检索入口共用该词法规则，结果仍只来自当前环境和身份范围内的私有来源块。GIN 索引通过迁移建立；后续向量召回只能作为同一私有来源块上的附加排序层，不能另存公开副本。
私有检索只召回文件当前最新成功解析中的有效来源块：文件、解析和块均未删除且启用，块正文的 SHA-256 与持久摘要一致。智能体在来源进入模型前和读取既有结果时复核相同的解析版本与摘要；重解析后的旧来源不得作为当前引用继续使用。

公开检索使用 SandIAM `sand_ai.retrieval.search` grant；查询前以当前已验证工作负载客户端的 SandIAM 事实正式授权，返回前重验。返回命中前，应用还根据持久化来源块和文件事实，对每个实际文件取得正式资源授权。跨分级、来源与文件不符或授权拒绝时，整个结果拒绝返回；空结果没有私有来源，也不消耗文件调用授权额度。

Agent 提交为 `POST /api/sand-ai/v1/agents/runs`，凭证 scope 为 `agents:write`，DTO 为 `{ model, instructions, query, tools?, review_required? }`。首期唯一允许的工具是代码注册的 `retrieval.search`；未知工具稳定返回 `SAND_AI_AGENT_TOOL_FORBIDDEN`。Agent 结果包含生成内容、来源引用、执行过的工具名称和 `review_required` 标记；不回显 Agent 指令、查询或工具输入。`review_required` 仅要求业务方复核 AI 结果，**不创建审批流、待办、角色动作或业务状态迁移**。

可发布智能体定义由 SandAI 后台配置，使用 `sand_ai_agent_definition`、`sand_ai_agent_version` 和 `sand_ai_agent_operation` 保存环境内唯一 `code`、草稿/发布版本及管理写入幂等证据；不保存 SandIAM 凭证或 grant。后台 `POST /app/sand-ai/admin/agents/definitions/draft` 接收 `{environment_id,code,name,model,instructions,tools?,review_required?,max_output_tokens?,knowledge_base_code?,context_turn_limit?}`，`POST .../publish` 和 `POST .../disable` 接收 `{environment_id,agent_definition_id}`，三者均要求 `Idempotency-Key` 和 `sand_ai:agent:definition:manage`；`GET .../read?environment_id=&code=` 要求 `sand_ai:agent:definition:read`。`context_turn_limit` 是整数 0–4，省略时为 2，随草稿版本冻结；`read.latest_version` 返回该值。渠道运行只从同一已发布版本带入最近对应轮数的已送达、无需人工复核的回答，0 表示不带历史，超过上下文字符上限仍拒绝；旧已发布版本维持 2 轮。管理员可以用新的幂等键再次保存当前草稿，只更新草稿版本的模型、指令、工具、知识库和输出设置；已发布版本保持不变。已发布智能体的名称不可在草稿期更改；发布时检查当前聊天模型目录，并要求 `knowledge_base_code` 指向当前环境可消费、来源也属于当前环境的已发布知识库。`GET .../index?environment_id=&page=&limit=` 要求 `sand_ai:agent:definition:read`，返回环境内草稿及已发布定义的分页摘要。停用或发布新版本后旧版本不能再派发或读取运行结果。

后台 `GET /app/sand-ai/admin/agents/definitions/models?environment_id=` 要求 `sand_ai:agent:definition:read`，按已核实的 SandIAM 环境返回当前已发布聊天能力档可用的模型 `{data:[{id,code,name,type,capabilities}]}`。页面用它选择草稿模型；发布事务仍重新核对当前能力档，不把列表读取当成发布授权。

应用使用 `POST /api/sand-ai/v1/agents/{code}/runs`，只提交 `{query}` 和 `Idempotency-Key`。服务端从该环境当前发布的固定智能体版本取得模型、指令、工具、知识库和输出上限，并把智能体版本 ID/摘要以及知识库发布 ID、版本 ID、来源范围摘要冻结到任务；调用者不得覆盖配置。提交响应不确定时，携原 `Idempotency-Key` 调用 `GET /api/sand-ai/v1/agents/{code}/runs/by-request-key` 只读找回原任务；此接口按当前 SandIAM 身份与智能体代码重新归属请求键，再复用任务读取的正式授权和来源核验，不创建或派发任务。发布定义只能从绑定知识库的当前已发布版本检索；来源漂移、撤销或换版在模型派发前拒绝，结果读取仍复核原版本快照与逐文件授权。渠道连续对话只复用相同知识发布版本的轮次。已发布 Agent 的提交上下文同时要求 `sand_ai.agent.submit`、`sand_ai.agent.run` 和 `sand_ai.knowledge.query`；Worker 在读取知识摘录前对固定知识发布版本取得 SandIAM 正式资源授权，模型派发前和调用后重验，结果读取与飞书回信重新授权该发布资源及逐文件来源。原逐次指定配置的 `/agents/runs` 路径仍要求 `agent.submit` 和 `agent.run`，保持现有检索契约。同键重试必须匹配原身份、查询和版本；停用或换版后拒绝旧任务再派发及读取结果。管理端试用页只保存未确认请求号和已确认任务号，刷新后可找回，任务有调用请求号时可在单独授权下读取费用。渠道绑定后须引用同一发布定义与版本，不新建第二套智能体运行面；真实渠道场景仍待验收。

新受理的知识索引／查询Embedding采用官方Neuron4 `OpenAIEmbeddingsProvider::embedDocuments`，完整请求体为`model`、原`input`及`encoding_format=float`；未配置维数用官方`withoutDimensions`省略`dimensions`，不猜厂商维数。新请求指纹输入加入内部`embedding_request_protocol=neuron_embedding_batch@1`，继续由现`request_fingerprint`与`request_body_digest`固定，不新增数据库列或公开DTO。已存在调用恢复只接受由同一完整旧／新输入计算后与原指纹相等的协议；prepared派发的原body摘要再与相应官方或历史确定请求字节精确一致，任何未知摘要／指纹拒绝，旧字段缺失不当作新协议。历史未发送的旧prepared按原body继续，已发送／未知只重用原回执，禁止替换body、重置计量或换键。所有新调用仅走官方SDK，不保留第二个可选新调用驱动；正式DNS／私有结果／边界／计价／来源／IAM及有界响应校验保持。

Agent 使用固定发布知识库的当前有效向量索引时，查询 Embedding 先核验消费工作负载的 `sand_ai.knowledge.query` 授权，按来源环境的索引模型路由，并把预算、调用、用量和费用归于消费环境与工作负载；只有词法检索或没有有效索引时不产生该子调用。混合检索只能融合该任务原已授权的发布版本、来源范围及逐文件有效来源块；索引切换、来源漂移、发布撤销或授权撤销须拒绝摘录与结果，不得扩大到其他知识库或私有文件。原逐次配置 Agent 的私有检索仍限当前环境、当前身份的有效来源块。父任务失败、取消或未命中不能抹去已实际发生的查询 Embedding 用量；子调用结果不明须保留原键等待恢复。

SandIAM 服务调用需同时授予 `sand_ai.agent.submit`（提交任务，空分级）与 `sand_ai.agent.run`（运行时逐文件校验真实检索来源；无来源时校验任务）。Worker 在来源摘录进入模型前完成 `agent.run` 授权，并在模型发送前重新核验原任务授权、每个已授权来源的当前事实及其原授权；幂等结果重放前也重验。读取已完成结果时重新校验任务和引用来源。每次新调用授权按所属 grant 消耗 SandIAM 调用额度，重验原授权不创建新的调用额度。

PDF 正文读取源码候选复用 Neuron AI 4.0.2 `PdfReader` 的正式 `setOptions()` 页范围（`-f/-l`）与 UTF-8 编码逐页 `read()`，不依据整份文本的分隔符推断页号。Smalot 仅提供原页列表、页数和 XObject 图像／Form 事实，不再调用其正文抽取方法；来源块仍为 `page`／`locator.page`，非空块顺序号连续，空白页不压缩原页号。新成功解析的 `parser_code` 为 `pdf/neuron`、`parser_version` 为 `4.0.2`；能力档的 `driver_code=native_document_parse` 保持，既有发布路由无需更名。实际正文引擎改变可能影响新解析文本及摘要，历史封存来源不回填。

每个原页均检查 XObject，不以已有原生文字、文字长度或比例推断图片正文已完整提取。页上含 `Image` 时，即使还能读取页码、页眉或其他文字，也触发既有私有 PDF OCR；`Form` 可能包装未确认图像，因此同样保守触发。整份 OCR 结果继续由 `PdfOcrCoveragePolicy` 核对：所有含原生文字或上述图像／Form 的原页均须有非空来源块，页码不可越界。OCR 未配置时返回既有 `SAND_AI_OCR_REQUIRED`，缺页或越界时返回 `SAND_AI_PARSE_FAILED`；原生片段不会写为这份文件的成功解析，也不能进入材料分析。纯空白且没有上述 XObject 的页可跳过。此保护不证明 OCR 文字识别准确或表格结构已还原；取消及原任务重试接口保持。

运行前置是 `neuron-core/neuron-ai >=4.0.2`、`symfony/process:>=6.4`、允许本地进程执行的 PHP，以及配置的 Poppler `pdftotext` 可执行文件。管理员通过宿主环境设置 `SAND_AI_PDFTOTEXT_PATH`，并可设置 `SAND_AI_PDF_READ_TIMEOUT_SECONDS`（默认每页 60 秒，1–300 秒）；本方案沿 Smalot 页事实，不调用 SDK `getPageCount()`，因此不要求 `pdfinfo` 路径配置。未配置、不可执行或依赖缺失返回既有 `SAND_AI_CAPABILITY_UNSUPPORTED` 与具体管理员处理提示，不自动重试、不暴露私有路径／原生工具输出、不切换正文引擎。依赖补齐后可按原权限及剩余尝试次数，沿既有失败任务重试入口再次提交。工具实际读取失败沿既有 `SAND_AI_PARSE_FAILED` 处理；原 PDF 空内容与 OCR 错误码保持。本候选未执行依赖安装、PDF／OCR 业务验证或 TypePHP。

首批驱动支持 TXT（行号定位）、DOCX（正文段落号或表格行定位）与文本型 PDF（页码定位）。DOCX 解析器 1.3 按原文顺序保留正文段落和表格单元格内部的显式 `w:tab` 为制表符、`w:br`／`w:cr` 为换行，相邻 `w:t` 文本片段直接拼接；既有段落及来源块的首尾 `trim` 继续生效，不保证首尾空白或分隔符原样保留。DOCX 的非空表格行形成 `table_row` 来源块，`locator.table` 和 `locator.row` 标明表格及行号，块内容以单元格坐标为键并按正文顺序排列；嵌套表格明确返回 `SAND_AI_PARSE_UNSUPPORTED`，不能悄悄遗漏其文字。任一原页含图片或 Form 嵌入内容的 PDF，即使该页已有页码、页眉或其他可提取文字，也返回 `SAND_AI_OCR_REQUIRED` 并进入已配置的 PDF OCR 路由；空白页可跳过，全文均为空白时返回 `SAND_AI_PARSE_EMPTY`。混合 PDF 的 OCR 结果会替代原生解析结果，因此必须对原文件中每个有可提取文字或图片／Form 的原页返回至少一个非空来源块，页码不得超出原文件页数；缺页或越界时不能把其他页的结果作为整份文件解析成功。本机 OCR 返回有正文的块必须有正整数页码，缺失或非法定位不能默认为第 1 页、不能作为成功来源写入。图片在 OCR 服务尚未配置时返回 `SAND_AI_OCR_UNAVAILABLE`，不得将图片误标为已解析。

## 3. 稳定错误码

| 业务码 | HTTP/响应语义 | 适用情况 |
| --- | --- | --- |
| `SAND_AI_VALIDATION_ERROR` | 参数错误 | DTO、状态枚举或分页参数无效 |
| `SAND_AI_RESOURCE_NOT_FOUND` | 资源不存在 | `read`、`update`、`destroy` 的 id 无效 |
| `SAND_AI_RESOURCE_CONFLICT` | 资源冲突 | code、凭证摘要或版本号重复 |
| `SAND_AI_RESOURCE_REFERENCED` | 不能删除 | Provider/Model 被部署或授权引用 |
| `SAND_AI_SECRET_WRITE_ONLY` | 不可读取 | 试图读取服务商密文或凭证明文 |
| `SAND_AI_FILE_INVALID` | 参数或文件内容错误 | 缺少文件、格式/MIME/大小不合规或上传失败 |
| `SAND_AI_STORAGE_UNAVAILABLE` | 存储不可用 | 私有存储配置、上传、读取或删除失败 |
| `SAND_AI_PARSE_UNSUPPORTED` | 不支持的解析类型 | 已上传文件不存在可用 ParseDriver，或 XLSX 含未核实公式/错误单元格 |
| `SAND_AI_PARSE_EMPTY` | 未提取到文本 | 文本、DOCX、CSV 或 XLSX 没有有效内容 |
| `SAND_AI_PARSE_FAILED` | 解析失败 | 文件损坏、解析器异常或来源块写入失败 |
| `SAND_AI_OCR_REQUIRED` | PDF 图像／嵌入正文需要 OCR | 有图片或 Form 的原页，即使已有文字，也不能以局部原生文字认定完整 |
| `SAND_AI_OCR_UNAVAILABLE` | OCR 尚未配置 | 图片解析尚未接入可信 OCR 服务 |
| `SAND_AI_TASK_UNSUPPORTED` | Worker 不支持任务类型 | 队列中存在当前部署未注册的任务驱动 |
| `SAND_AI_TASK_INVALID` | 任务输入无效 | 队列任务缺少受控的资源标识 |
| `SAND_AI_CAPABILITY_UNSUPPORTED` | 能力或驱动不可用 | 配置档引用未注册能力/驱动，或已发布档未为运行能力配置路由 |
| `SAND_AI_DATA_EGRESS_FORBIDDEN` | 数据外发策略拒绝 | `local_only`/`private_only` 档案试图使用非本地驱动或 Provider |
| `SAND_AI_PROVIDER_CONFIG_INVALID` | 服务商配置不可用 | 密钥配置无法安全解密、缺少必填字段或 API 基址不合规 |
| `SAND_AI_PROVIDER_UNAVAILABLE` | 服务商暂不可用 | 网络或服务端故障，可重试 |
| `SAND_AI_PROVIDER_RATE_LIMITED` | 服务商限流 | 厂商返回限流，可重试 |
| `SAND_AI_PROVIDER_REJECTED` | 服务商拒绝请求 | 鉴权、模型或参数被厂商拒绝，不回显厂商原文 |
| `SAND_AI_SERVICE_ACTION_FORBIDDEN` | 服务授权拒绝 | SandIAM 已识别调用方但未授予当前语义服务动作，或 audience 不匹配 |
| `SAND_AI_ENVIRONMENT_MISMATCH` | 页面环境与应用身份不符 | 材料样本声明的所选环境与 SandIAM 已验证环境不一致；不能据声明改变归属 |
| `SAND_AI_AUTHENTICATION_FAILED` | 身份上下文无效 | 全量插件已安装 adapter，但缺少 Bearer、签名无效或上下文格式无法解析 |
| `SAND_AI_IDENTITY_CONTEXT_EXPIRED` | 身份上下文过期 | SandIAM 已验证签名，但短期 workload context 超过有效期 |
| `SAND_AI_CREDENTIAL_REVOKED` | 工作负载凭证失效 | 签发该 context 的 credential、client 或 environment 已撤销/停用 |
| `SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE` | 宿主身份上下文不可用 | 全量插件未安装可验证的 SandIAM context adapter，或宿主 signer 不可用；不可回退 API Key |

错误响应的 `data` 固定为 `{ error_code, retryable: false }`；不得返回凭证、供应商密钥、完整 prompt、堆栈或原始厂商响应。

## 4. 对 Cursor 的接入要求

- 将 `USE_SCHEMA_MOCK` 切为 `false` 后，仅调用本文的路径和字段；不得传 `mockId`、`mockTitle` 等展示字段。
- `provider` 页面以 `has_encrypted_config` 表示已有密钥；编辑时只有用户新输入配置才提交 `encrypted_config`。
- 列表统一消费第 1 节分页壳；保存返回的 `{ id }` 可用于刷新列表，不假定完整资源回显。
- 新增接口或字段前，先更新本文并由 C-05 提供实现与验证证据。

## 5. 运行 API 边界

`/api/sand-ai/v1/*` 当前继续兼容开发计划第 7 节的应用凭证协议。目标调用链由 SandIAM
工作负载身份签发短期、限定 audience 和 service action 的调用上下文，SandAI 通过
`IdentityContextProvider` 验证并解析该上下文。管理 API 只能配置与审计，不能将管理
登录态或 `encrypted_config` 暴露给业务调用方。

全量插件包使用同一路径形状，但身份由宿主安装的 SandIAM context adapter 提供；包内在
宿主未安装 `plugin\\SandIam\\app\\runtime\\IdentityContextProvider` 时**一律拒绝**，返回
HTTP 400、`SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE` 和 `retryable: false`。它不接受旧 API Key
作为回退，也不会把请求转发给平台主应用。该错误只表示宿主尚未提供经验证的工作负载
上下文，不泄露应用、环境、对象存储或来源数据。

`POST /api/sand-ai/v1/chat/completions` 可显式传 `model="@lowest-cost"`，并须传正整数 `max_output_tokens`。仅此公开聊天入口支持该选择；Skill、知识问答、任务及内部结构化抽取继续使用各自已冻结的模型。服务端仅比较当前环境已发布聊天能力路由中可执行、具备验证过的输入/输出 Token 上界及当前已发布价格的部署，按同一币种的费用上界精确选最低者；同价按路由优先级和 ID 决定。当前预算有币种时只考虑同币种部署；无预算但候选币种不同时拒绝比较，不做汇率猜测。无合格路由、币种不可比较或选中价格在调用起点变化时明确拒绝，不降级到未发布路由。成功与幂等重放的响应增加 `selected_model`；请求指纹仍使用原始 `@lowest-cost`，已创建调用的重试沿用冻结的模型与部署，不因目录或价格变化重选。派发快照和用量来源保留 `selection_kind=lowest_cost`，调用前仍走 SandIAM 授权、预算预占、价格快照和派发围栏。

### 5.1 全量插件 SandIAM adapter（1.13）

本地运行 API 从 `Authorization: Bearer <context>` 读取 SandIAM 签发的短期 compact token；
固定 service code 为 `sand_ai`、audience 为 `sand-ai`。入口适配器调用宿主
`IdentityContextProvider::verifyForService(context, service_code, audience, action, trusted_source_ip, request_id)`，
从宿主结果读取 `exp` 并复核四级身份与动作；`trusted_source_ip` 来自实际连接，不取调用方转发头。资源操作另通过注册的事实解析器调用 SandIAM `ServiceInvocationAuthorizer::authorizeInvocation`，排队操作须使用加密保留的原 context 执行前重验。环境引用通过
`EnvironmentReferenceVerifier::verifyEnvironmentReferenceForClient(environment_id)` 验证；SandAI 的运行调用归属校验不读取 SandIAM 表或模型；费用后台正式管理目录只读消费例外见“费用管理正式范围与全维下钻”。

#### 2.2／2.3 执行前当前授权与原 context 有效期

执行及幂等回放须先取得当前资源授权，才可发送或读取旧结果；四级归属和 `data_class` 来自注册解析器核对的持久资源事实，不接受请求方自报。排队执行使用私有加密保存的原签名 context、原授权号、可信连接 IP、请求号和有效期调用正式 `ServiceInvocationAuthorizer::revalidateInvocation`，不得自动续签或重新申请 quota。共享 `HostSandIamInvocationAuthorizer` 在正式端口调用前及成功返回后均按原 `identity->expiresAt` 检查；行锁等待跨过原有效期，即使宿主返回允许，也以 `SAND_AI_IDENTITY_CONTEXT_EXPIRED` 拒绝。

2026-10-02 源码候选补齐两个发送边界：外部 ASR 在私有文件加载／probe／预检后、`prepared → dispatching` CAS 前，从原加密 context 再验当前授权；TTS 在预算预占后、同一派发事务提交前，以已有授权回调最后重验。重验失败从事务抛出，源码路径回滚本次预算窗口占额、预占记录和派发状态／事件，后续 Provider 调用不可达；不新增授权或 quota。OCR 与工具沿既有发送前重验链消费共享的返回后有效期检查，本次不修改其业务文件。

上述为隔离源码及静态独审结论，2.2／2.3 尚未验收。真实 SandIAM／PostgreSQL／HTTP 仍须验证撤权与锁等待跨有效期、预算／窗口／状态回滚、稳定拒绝码、重验不新增 quota、零 Provider 发送及审计保留。FormalWorkflow 的前置分类将无效 version、非整数或非正 `expires_at` 返回 `SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE`，规范正整数已过期时返回 `SAND_AI_IDENTITY_CONTEXT_EXPIRED`，正式重验返回后、生成派发 decision 前再次检查原有效期；其他信封／密文失效继续由正式 guard 拒绝，不保证所有损坏信封优先返回 UNAVAILABLE。共享 adapter 的首次授权与排队中新发现来源授权也在正式端口成功返回后检查原有效期；此类初次授权可能已在 IAM 内部提交调用尝试 quota 和允许审计，本地过期拒绝不代表额度退款或零费用。IAM 审计若共用外层 PostgreSQL 事务，拒绝后的外层回滚可能同时撤销该审计，无外层事务时则可能保留 IAM allowed 与 SandAI expired 的不同层次结论，须在 2.5 真实审计门核验。后续派发门的有效期错误码及更晚的实际发送竞态不由这次检查统一或消除。

| 宿主端口结果 | SandAI `error_code` |
| --- | --- |
| 未安装端口 / signer 不可用 | `SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE` |
| 缺少 Bearer、签名失败或载荷无法解析 | `SAND_AI_AUTHENTICATION_FAILED` |
| context 过期 | `SAND_AI_IDENTITY_CONTEXT_EXPIRED` |
| credential/client 已撤销 | `SAND_AI_CREDENTIAL_REVOKED` |
| audience 不匹配或缺少 service grant | `SAND_AI_SERVICE_ACTION_FORBIDDEN` |

Controller 在 adapter 验证成功后仍须调用 `IdentityContext::requireAction()`。拒绝响应的
`data` 仍为 `{ error_code, retryable: false }`，不得返回对象位置、来源正文或厂商原文。

### 5.2 跨应用知识库查询目标契约（待实现与验收）

`POST /api/sand-ai/v1/knowledge-bases/{code}/query` 中的 `code` 是消费环境内唯一的发布别名，不是调用方声明的知识库归属。请求体保留 `query`、`limit`；组织、应用、环境、工作负载客户端和数据分级均不得从请求体读取。服务端从已验证的 SandIAM context 取得消费身份，按 `(consumer_environment_id, code)` 找到有效发布关系及当前已发布版本。知识库的创建环境可以与消费环境不同。

SandAI 的发布关系仅确定哪些消费应用环境能读取哪个知识库；SandIAM 的 `sand_ai.knowledge.query` 服务动作 grant 仍是调用权限的唯一权威。正式资源事实为 `sand_ai.knowledge_base_version`，引用已发布版本与发布关系，四级归属为经 SandIAM 验证的**消费**组织、应用、环境和当前工作负载客户端；`data_class` 来自来源一致性已核实的版本快照。SandIAM 对该资源授权成功后才可检索或返回摘录；返回引用保留知识库版本、文件、解析和来源块标识。发布关系撤销、版本失效、来源归属/分级漂移或 SandIAM grant 撤销都须拒绝整个结果，不能回退到同环境查询或部分摘录。

已发布来源的检索在原有全文索引和整句匹配外，使用最多 8 个中文相邻字片段召回，并按命中覆盖度排序。该行为同时用于对外查询、问答取来源、固定版本 Skill 检索和后台独立检索试用；只扩大已核实来源内的词法召回，不改变归属、授权、引用格式或 `limit`。存在当前有效的 Embedding 索引时，对外查询和问答使用来源环境创建索引时的同一模型生成查询向量，再融合词法与语义候选；没有活动索引时沿用词法检索。索引、发布版本或来源变化后旧向量不得用于新索引，后台试用和固定版本 Skill 暂不使用查询 Embedding。

活动索引存在时，`POST /api/sand-ai/v1/knowledge-bases/{code}/query` 必须携带 `Idempotency-Key`；消费工作负载的 `sand_ai.knowledge.query` 当前授权覆盖本次查询，Embedding 路由归来源环境，预算、调用、用量与费用归消费环境和工作负载，记录 `source_kind=knowledge_query`。查询请求体仍只接受 `{query,limit?}`，响应在原有 `knowledge_base,query,citations` 外返回 `embedding_invocation,embedding_usage`；无活动索引时两项为 `null`。即使混合检索没有命中，已实际生成的查询向量仍有独立费用，不能显示为免费。查询向量仅保存于私有加密回执，不返回给客户端；同键重试从回执恢复，结果不明不得改键自动重发。服务商已成功的用量按冻结回执结算，随后才检查现时授权、活动索引及来源是否允许返回摘录。

知识库管理页以 `GET /app/sand-ai/admin/knowledge-bases/index?environment_id=&page=&limit=` 读取当前环境知识库、已发布及草稿版本、来源状态；要求 `sand_ai:knowledge:index` 后台权限，`limit` 最多 100。`source_state=ready` 表示当前版本的来源文件、最新成功解析、来源块正文摘要和冻结快照均通过现时核对，`source_changed` 表示需重建草稿，`unavailable` 表示没有版本，`purged` 表示停用后已解除来源引用；另返回 `sources_purged` 布尔值。此状态不表示向量索引已经建立，也不表示原文件已删除。`GET /app/sand-ai/admin/knowledge-bases/eligible-files?environment_id=&before?` 按文件 ID 倒序分页返回当前 SandIAM 环境中归属有效、最新解析完整的文件名称、ID、SHA-256、来源块数量和数据分级，不返回私有存储位置；`next_before` 为下一页游标。`POST /app/sand-ai/admin/knowledge-bases/create-draft` 可带 `replace_draft=true` 在同一事务内将旧草稿标为 `abandoned` 并冻结新草稿；原已发布版本保持不变，旧草稿的同键重试明确拒绝。新草稿来源核验失败时整笔回滚。创建及发布仍分别要求既有 `sand_ai:knowledge:create`、`sand_ai:knowledge:publish` 权限和独立 `Idempotency-Key`；发布时重新核验来源，不信任列表状态。

知识向量索引的后台操作要求独立权限 `sand_ai:knowledge:index:build`。`POST /app/sand-ai/admin/knowledge-bases/embedding-index/start` 接收整数 `environment_id`、`knowledge_base_version_id` 和 `Idempotency-Key`，冻结当前已发布版本与 Embedding 路由，返回 `{id,state,source_count,dimensions,idempotent_replay}`，不调用服务商。`POST /app/sand-ai/admin/knowledge-bases/embedding-index/advance` 接收整数 `environment_id`、`index_id`，每次最多处理一个尚未索引的来源块并记录独立的调用和费用；全部来源完成后，下一次调用将完整索引切换为当前索引。响应按 `state` 区分：

- `indexed`：`{state,index_id,source_block_id,invocation_id,recovered,usage}`。`usage` 为本次调用的计量结果，包含 `metered_amount`、`currency`、`pricing_state`、`usage_state`、`reconciliation_state` 和 token 数量；`metered_amount=null` 表示本次费用尚不能确定，不能展示为零费用或整个索引的总费用。
- `in_progress`、`outcome_unknown`：`{state,index_id,source_block_id}`。两者均不表示本次调用免费或失败；只读状态仍为 `building` 时也不能据此清除结果不明提示。
- `ready`：`{state,index}`，其中 `index` 与 start 的索引摘要同形，表示完整索引已切换为当前索引。

`GET /app/sand-ai/admin/knowledge-bases/embedding-index/status?environment_id=&index_id=` 只读返回 `{id,state,source_count,indexed_count,dimensions}`，不产生服务商调用。响应丢失时先读状态，再用相同索引 ID 继续；派发结果不明时不得换请求号重发同一来源。

停用知识库仍使用 `POST /app/sand-ai/admin/knowledge-bases/disable`，请求 `{environment_id,knowledge_base_id}` 和 `Idempotency-Key`，要求 `sand_ai:knowledge:disable`。若该库有待处理知识问答轮次，或任一向量索引还有 `prepared`、`dispatching` 或 `ambiguous` 的 Embedding 派发，停用拒绝，须先处理原调用状态。停用后可单独调用 `POST /app/sand-ai/admin/knowledge-bases/embedding-index/purge`，传同形正文和独立 `Idempotency-Key`，复用该权限；成功返回 `{knowledge_base_id,state:"purged",idempotent_replay}`。清理在同一事务内移除该知识库所有版本的当前索引指针、私有向量和索引记录，保留不可变的调用、用量和审计事实；未解决的派发同样拒绝清理。该操作不删除原文件、解析文字或任务成果，完整来源及成果清理仍是独立生命周期工作。

停用后还可调用 `POST /app/sand-ai/admin/knowledge-bases/sources/purge`，正文 `{environment_id,knowledge_base_id}`，携带独立 `Idempotency-Key`，权限为 `sand_ai:knowledge:disable`。调用前须撤销该库所有应用发布关系、清除全部向量索引，且不能有待处理知识问答轮次、尚未终结的普通知识调用，或仍为 `prepared`、`dispatching`、`ambiguous` 的相关派发；否则返回 `SAND_AI_RESOURCE_CONFLICT`。成功在同一数据库事务删除该库知识问答会话与轮次、全部版本的来源引用，并销毁与该库持久发布关系及精确版本匹配的 `knowledge_answer` 私有加密回执，包括普通单次答案、会话答案及每次摘要；返回 `{knowledge_base_id,state:"purged",remaining_sources:0,remaining_conversations:0,answer_receipts_purged:int,idempotent_replay}`。`answer_receipts_purged` 是本次实际销毁数量，无残余的原键回放为 0；删除及销毁数量写入审计。已发布版本元数据、调用、派发、不可变用量、费用和审计事实保留；原文件、解析文字、其他业务成果及本次 `knowledge_answer` 范围以外的回执沿各自生命周期处理，原资料所有者可在无其他发布引用后另行调用文件删除。该操作不证明全部派生结果已清除。

知识答案回执清理按持久 `knowledge_publication_id`、`knowledge_base_version_id` 和来源归属摘要定位，复核同一知识库、消费环境、模型、部署及请求指纹，未知或交叉归属失败关闭。销毁沿现有 `available/expired → expired`（增加清理 fence）`→ destroyed` 状态路径清空密钥引用及密文材料。来源删除持有知识库行锁并更新其行版本；回执写入取得同一知识库共享锁后重新核对精确版本的来源仍存在，阻止清理后的迟到写回。原清理键回放会补查残余回执，但要求知识库仍停用且来源余量为零，资料后来重建时拒绝。已有 Skill 来源建议继续使用其专用 owner、墓碑及费用恢复链，不按普通知识答案清理。以上是 6.3 隔离源码及独立静态审查结果，真实 PostgreSQL 锁等待／隔离级别、并发、HTTP、私有存储及费用验收尚未完成。

管理端收到明确的 `SAND_AI_CONFIGURATION_NOT_PUBLISHED` 错误时，该次推进在服务商发送前被拒绝。刷新只读状态后，可由管理员明确确认，使用新请求号准备替代索引；原索引已成功处理的来源块可能重复计费。网络错误、`in_progress` 或 `outcome_unknown` 不属于这一恢复条件。

后台通过 `POST /app/sand-ai/admin/knowledge-bases/publications/publish` 接收 `owner_environment_id`、`knowledge_base_id`、`consumer_environment_id`、`code` 和 `Idempotency-Key`；通过 `POST /app/sand-ai/admin/knowledge-bases/publications/revoke` 接收 `owner_environment_id`、`publication_id` 和 `Idempotency-Key`。不接受由管理请求声明的组织、应用或工作负载客户端 ID。发布与撤销分别要求 `sand_ai:knowledge:publication:publish`、`sand_ai:knowledge:publication:revoke` 后台权限。SandIAM 官方环境引用端口验证创建及消费环境的组织/应用；SandAI 只保存外部 ID、发布别名和内容开放状态，不保存服务 grant 或凭证。数据库表为 `sand_ai_knowledge_publication`，消费环境内的 `code` 唯一；`sand_ai_knowledge_base_version` 保存可信来源归属摘要和数据分级，旧版本缺摘要时拒绝对外读取。当前实现状态及真实验收见任务看板。

管理端读取使用 `GET /app/sand-ai/admin/knowledge-bases/publications/publishable?owner_environment_id=&page=&limit=`、`GET /app/sand-ai/admin/knowledge-bases/publications/index?owner_environment_id=&knowledge_base_id=&page=&limit=` 与 `GET /app/sand-ai/admin/knowledge-bases/publications/consumer?consumer_environment_id=&page=&limit=`，均要求 `sand_ai:knowledge:publication:index`。分页参数从 1 开始，`limit` 至多 100，返回共用的 `{current_page, per_page, total, data}`；前者只列出已发布且具有可信来源摘要的知识库，第二个列出指定知识库的消费环境、别名及 `published`/`revoked` 状态。消费环境列表只列出当前已发布的别名及有效知识库版本，包含别名 `code`、知识库名称、来源环境 ID、当前版本与分级，供智能体草稿选择；发布智能体时仍由服务端重新核验来源与授权。管理页面的环境和应用名称从 SandIAM 管理 API 读取，不在 SandAI 建镜像表。

管理员独立检索试用使用 `POST /app/sand-ai/admin/knowledge-bases/trial`，要求 `sand_ai:knowledge:query:test` 后台权限，请求 `{owner_environment_id,knowledge_base_version_id,query,limit?}`；`query` 为 1–500 字，`limit` 为 1–20。只接受该来源环境当前已发布、启用的固定版本；服务端核实 SandIAM 环境和版本来源归属摘要，来源漂移、失效文件或来源块均拒绝。响应 `{knowledge_base_id,knowledge_base_version_id,query,matches}` 中的每个命中含知识库 code/version、`file_id`、`parse_id`、`source_block_id`、定位和至多 500 字节摘录，不含原文件内容、私有对象 key 或存储地址。该后台试用不代表消费应用取得 `sand_ai.knowledge.query` 授权；正式对外查询仍按 SandIAM 当前 grant 授权与重验。

消费应用可用 `POST /api/sand-ai/v1/knowledge-bases/{code}/answer` 获取带来源的问答结果。请求体只接受 `{query,model,max_output_tokens?,rerank?}`，单次问答的 `rerank` 只能为 `false`；模型输出上限默认 512、最高 2048；必须提供 `Idempotency-Key`。同一已验证工作负载需同时具有 SandIAM `sand_ai.knowledge.query` 与 `sand_ai.chat.complete` 授权。服务端固定当前发布版本、来源范围摘要和数据分级，最多取 5 个命中摘录；模型派发前后均重验知识发布关系及来源事实，回答模型调用仍走聊天的权限、预算和计量链。有活动向量索引时查询 Embedding 另记一次消费应用费用，返回 `embedding_invocation,embedding_usage`；无索引时两项为 `null`。无命中时返回 `answer_state=no_sources`、空引用与空回答模型调用/费用，但已发生的查询 Embedding 费用仍保留；模型输出不是规定的 JSON 或引用了本次命中之外的来源块时返回 `answer_state=needs_review`、空回答与空引用，但保留已发生调用和费用。合格输出为 `answer_state=answered`、回答、实际引用的来源定位、`review_required=true`、调用摘要、用量及 `idempotent_replay`；引用 ID 经服务端核对，回答内容仍需业务复核。成功调用的同键重试从私有加密结果回执恢复原文并重新核对引用，回执需要配置 `SAND_AI_OUTCOME_RECEIPT_CURRENT_KEY_ID` 和 `SAND_AI_OUTCOME_RECEIPT_CURRENT_KEY`，默认保留 24 小时；过期或来源漂移时明确报错，不再次调用模型。调用者不能在请求体指定归属或分级。调用费用可通过返回的调用请求号查询；后台已提供会话应用试用入口，真实浏览器验收仍待完成。

知识问答会话沿用上述已发布知识和双动作授权：`POST /api/sand-ai/v1/knowledge-bases/{code}/conversations` 以空请求体和 `Idempotency-Key` 创建会话；创建响应不确定时，可携带原 `Idempotency-Key` 请求 `GET /api/sand-ai/v1/knowledge-bases/{code}/conversations/by-request-key`，只读找回当前身份与知识发布范围内的原会话，未找到时可沿原键重试创建。`POST /api/sand-ai/v1/knowledge-bases/{code}/conversations/{conversationId}/answer` 使用相同问答请求体和独立的 `Idempotency-Key` 追加一轮；`GET /api/sand-ai/v1/knowledge-bases/{code}/conversations/{conversationId}` 读取最近 20 轮，可带 `before=<sequence_no>` 读取更早记录。会话仅供原组织、应用、环境和工作负载在授权仍有效时访问；知识发布版本、来源范围或分级变化时停止读取和追加。每轮最多带入最近 4 个已回答问题供理解上下文，既往回答不能作为事实来源，新回答仍须引用本轮命中的来源。一个会话同时只受理一个未完成的新问题；同键重试恢复已完成的调用和结果，换键并发返回 `SAND_AI_CONVERSATION_BUSY`。领取过期后，同键恢复更换领取令牌；旧请求即使随后收到模型结果，也不能封存该轮，只有当前令牌可完成写入。明确未派发或明确失败的调用封存为 `failed`，同键重试返回原错误，新键可继续提问；结果不明时保持待恢复状态，禁止自动重发。模型已返回且私有加密回执完整时，同键重试先复验身份和来源，再以原调用的计价、模型及归属事实在一个事务内封存用量、预算和派发状态，不再次调用模型；无回执、回执失效及旧版缺计量事实的待处理调用不得自动恢复。返回的会话轮次保存问题、回答状态、实际引用和调用摘要，费用以调用请求号核对。每轮增加只读 `execution_state`，取值为 `preparing`、`provider_pending`、`outcome_unknown`、`finalizing`、`recovery_required`、`completed`、`failed`、`cancelled`，由轮次、调用和派发账本投影；它不代表客户端连接存活，也不包含服务商原始响应。`provider_pending` 表示已跨服务商发送边界、结果尚未封存；`outcome_unknown` 不得自动重发；`recovery_required` 只提示以原键恢复，不承诺一定成功。`POST /api/sand-ai/v1/knowledge-bases/{code}/conversations/{conversationId}/turns/{sequenceNo}/resume` 以空请求体恢复仍为 pending 的指定轮次，不要求浏览器保存原 `Idempotency-Key`；服务端在当前双动作授权及原会话归属检查后读取该轮冻结的问题、模型、输出上限与已归属化请求号，再沿原领取、来源复核、回执和计费链处理。已完成或不属于当前会话的轮次不得重新领取；无可靠回执时仍不得自动重新派发。渠道绑定另见下文。

`POST /api/sand-ai/v1/knowledge-bases/{code}/conversations/{conversationId}/turns/{sequenceNo}/cancel` 使用空请求体及当前知识查询、聊天双动作授权，仅允许取消仍为 `pending` 且回答、查询 Embedding、重排三种调用均尚无调用账本的轮次。取消与轮次领取共用会话和轮次行锁，更新为 `state=cancelled`、`answer_state=cancelled`，清除领取令牌并记录审计；旧执行者在外发前必须复核令牌，不能再派发模型。同一轮再次取消返回 `idempotent_replay=true`；已完成轮次或已有任一调用账本时返回 `SAND_AI_RESOURCE_CONFLICT`，后者须读取或按原请求恢复，不得声称未计费。取消后原提问键只读重放取消结果，新键可提交下一轮。

有活动向量索引时，待处理轮次的 `execution_state` 在回答模型调用出现前投影本轮查询 Embedding 的调用与派发状态；`provider_pending` 和 `outcome_unknown` 同样适用于该前置调用。只读投影必须核对 Embedding 调用的消费身份、发布版本、来源范围与动作，且不向客户端返回查询向量。

知识对话追加问题可设置 `rerank=true`。服务端最多检索 10 个当前授权来源块，沿用本轮聊天模型、SandIAM 双动作授权、预算与费用链独立调用一次模型排序，再从排后候选选最多 5 个供回答；模型只能返回全部候选块 ID 的完整排列，输出不合格时沿原顺序回答，并保留已产生的重排调用和费用。没有来源时不调用重排或回答模型，活动索引的查询 Embedding 仍独立计费。轮次冻结重排设置，同键重试或按轮次恢复沿用原设置；回答结果单独返回 `rerank_invocation`、`rerank_usage`、`rerank_state`，费用可按重排调用请求号查询。重排仅改变当前来源的顺序，不扩大私有来源范围。

`GET /api/sand-ai/v1/knowledge-bases/{code}/conversations/{conversationId}/turns/{sequenceNo}/events` 返回 `text/event-stream`，要求 HTTP/1.1 和与会话读取相同的 SandIAM 双动作授权。服务端每秒读取指定轮次的持久状态，状态变化时发 `turn` 事件，`data` 为 `{conversation_id,turn}`，其中 `turn` 与会话读取的轮次 DTO 相同；15 秒无变化发注释心跳。终态发出后关闭连接；90 秒仍未完成时发 `reconnect` 并关闭，客户端可重新订阅同一轮次。每次读取均复核知识发布、来源和授权，失效时发仅含 `error_code` 的 `error` 事件并关闭。订阅、重连和只读轮询都不派发模型，也不新增计量。

逐段回答使用独立的 `stream_segments` 布尔输入，仅会话追加回答可指定，默认 `false`，并冻结到轮次及同键恢复输入；单次知识问答不接受该字段。启用时模型须输出最多 16 条逐段完整的 JSON 对象，且每条仅含 `text` 和 `citations` 两个字段；对象字段顺序无关，未知或缺失字段、错误类型仍拒绝。每段 `text` 是以句号、问号或叹号结束的不超过 1000 字的完整句子，`citations` 只可包含本轮命中来源块 ID。服务端逐段完成格式、来源 ID、长度及可识别敏感字段遮盖后才持久化；原始 Provider SSE、未完整记录和未验证内容不得进入对外事件。事件流在原 `turn` 外增加 `segment`，事件 `id` 为该轮单调递增的段号，`data` 为 `{conversation_id,sequence_no,segment_no,text,source_block_ids,provisional:true}`；事件从当前身份、发布范围和原轮次读取，可使用 `Last-Event-ID`（非负十进制段号）只读续传。每段绑定同一轮次、领取令牌及派发调用；旧领取者不能补写。逐段授权仅复核原运行的 SandIAM authorization_id、当前身份及来源，不新建业务调用或费用。已验证段落仍只是进行中预览，不能替代 `turn.result.answer`、引用、费用或终态；模型断流、段落无效、来源失效、取消或封存失败时，客户端以终态 `turn` 清除预览，不把部分段落显示为已回答。终态的完整答案、引用、调用和用量仍依原结果与私有回执封存；无可靠终结及用量时标记结果不明，不自动重派发或重计费。此协议的实时粒度为**完整且已验证的段落**，不承诺原始模型 token 逐字外发。

会话读取和 `turn` 事件的每轮 DTO 增加只读 `stream_segments` 布尔值。进行中的 `POST .../answer` 尚未返回时，客户端以原 `Idempotency-Key` 调用 `GET /api/sand-ai/v1/knowledge-bases/{code}/conversations/{conversationId}/turns/by-request-key`，在同一 SandIAM 双动作授权、发布事实和原会话归属下只读定位精确轮次，返回 `{sequence_no,state,stream_segments}`；未落库返回 `SAND_AI_RESOURCE_NOT_FOUND`，客户端可继续只读查询，不能猜序号或重新派发。取得 `stream_segments=true` 的真实 `sequence_no` 后订阅事件。会话同一时间只受理一轮 pending；找回、读取和订阅均不新增调用或费用。

已回答轮次超过近期 4 轮时，服务端在本轮检索命中后，按序将更早已回答轮次分批压缩为会话摘要；每批最多 4 轮，每个回答请求最多 4 次摘要调用，仍有未覆盖轮次时标记 `summary_state=partial`，后续提问继续推进。每批摘要使用独立且由会话、原提问键、输入和模型确定的调用请求号，走聊天授权、预算、计量和加密结果回执；调用成功但摘要保存失败时沿原请求恢复，不重新计费。摘要持久绑定原会话和知识发布范围，来源清理时随会话删除。只有实际处理并封存的旧轮次才计入 `summary_answered_count` 和 `summary_through_sequence_no`；摘要输出无效时沿用已封存摘要，并继续使用未覆盖轮次中最多 8 条短摘录，不宣称完整覆盖。短摘录分别截取问题 160 字、旧回答 300 字，只作为话题线索；**摘要与旧回答均不能作为事实或引用来源**。旧问答在形成摘录前，以及历史问答、已有摘要和本次摘要进入模型前，遮盖可识别的密钥字段、Bearer 令牌、中国大陆手机号与身份证号；原始会话记录不因上下文遮盖而改写。此规则只覆盖可识别格式，不保证识别任意自由文本中的秘密。

完成轮次的 `context_coverage` 记录 `summary_text`、`summary_through_sequence_no`、`summary_answered_count`、`summary_state`（`current`、`partial` 或 `invalid_output`）、`summary_used_for_model`，以及实际带入的 `earlier_question_sequences`、`earlier_answer_excerpt_sequences`、`recent_answered_sequences`、`omitted_answered_count` 和 `used_for_model`。摘要单独返回 `summary_invocations`、`summary_usage` 数组，费用按各调用请求号核对。无来源时不生成新摘要，`used_for_model=false`；已有旧回答的覆盖信息仍以原选择范围为准，摘要字段可缺省。此字段随结果持久化并在同键重放和会话读取时返回。模型逐字流式输出仍待实现。

`GET /api/sand-ai/v1/knowledge-bases/{code}/conversations` 供断线后找回当前工作负载的会话 ID。沿用知识问答的双动作授权，仅列出原组织、应用、环境、工作负载且当前发布版本、来源范围、分级仍匹配的会话，包含已关闭会话；按创建序号倒序返回最多 20 条摘要，不返回问题或答案。响应为 `{conversations, next_before}`，`next_before` 是下一页使用的公开会话 ID，末页为 `null`；请求 `before=<next_before>` 时，游标也必须属于当前可读范围，否则返回 `SAND_AI_RESOURCE_NOT_FOUND`。发布、来源或授权变化后停止列出旧会话。

`POST /api/sand-ai/v1/knowledge-bases/{code}/conversations/{conversationId}/close` 使用空请求体，经过同样的双动作授权和会话归属核验后将会话关闭；无待处理问题时自然幂等，返回会话摘要及 `idempotent_replay`。有待处理问题时返回 `SAND_AI_CONVERSATION_BUSY`，客户端须先按原键恢复或等待该轮封存。关闭后不能追加问题，原工作负载仍可在发布关系、来源和授权有效时分页读取既有历史；撤权或来源失效时不返回旧内容。

个人 Skill 引用的 `knowledge_base_version_id` 与 `snapshot_sha256` 在草稿、发布和运行时均按 Skill 所属的**消费环境**查找有效知识库发布关系。固定版本可以早于知识库当前活动版本，但该版本必须仍为已发布、快照相符、来源归属和数据分级未漂移。公开运行在读取摘录前对 `(publication_id, workload_client_id, fixed_version_id)` 取得 SandIAM `sand_ai.knowledge.query` 授权，并在模型调用和幂等回放的 Skill 资源事实中再次核验发布关系；撤销发布或 grant 后不返回旧结果。管理端测试依后台权限执行，但同样要求有效发布关系与固定版本来源。`sand_ai.skill.run` 与 `sand_ai.knowledge.query` 是两个独立的服务动作授权，消费身份均来自已验证 context，不从 Skill 输入读取。

正式应用调用使用 `POST /api/sand-ai/v1/skills/{code}/versions/{version}/runs`，以 SandIAM 应用 Bearer 传 `{input: <符合固定版本输入 Schema 的对象>, request_id: <本次逻辑调用的稳定标识>}`；`code` 和 `version` 指向已发布的固定版本，不从管理登录态取身份。返回 `run_id`、`state`、结构化 `output`、最多 500 字节的私有来源 `citations`、`review_required`、`invocation_request_id` 和 `idempotent_replay`。不确定响应可用同一应用 Bearer 请求 `GET /api/sand-ai/v1/skills/{code}/versions/{version}/runs/by-request-key?request_id=<原标识>` 只读找回，不触发模型派发；读取按原输入和当前可信应用范围核对指纹，重新核验知识发布及 SandIAM 授权。运行中返回 `state=running`，终态返回原运行摘要；请求不存在或不属于当前身份返回资源不存在。原请求体重试仍须保持原 `request_id`，改变输入应使用新标识。具备独立 `sand_ai.usage.read` 授权的同一应用可按 `GET /api/sand-ai/v1/invocations/{invocation_request_id}/cost` 读取本次计量，费用缺失或无读取授权不应抹去已返回的运行结果。管理端“按应用身份运行”入口保存未确认调用的请求号到当前会话，刷新后可找回；后台 `test-run` 仍作为单独计费的草稿评测。

个人 Skill 管理工作区的只读接口为 `GET /app/sand-ai/admin/skills/index?environment_id=&page=&limit=`、`GET .../read?environment_id=&skill_id=` 和 `GET .../sources?environment_id=&page=&limit=`，均要求 `sand_ai:skill:read` 后台权限并核验 SandIAM 环境引用；分页从 1 开始，`limit` 至多 100。`index` 返回 `{current_page,per_page,total,data:[{id,code,name,state,status,active_version_id,active_version,draft_version}]}`；`read` 返回 `{skill,versions:[{id,version,state,name,description,instruction,input_schema,output_schema,review_policy,knowledge_base_version_id,snapshot_sha256,tested}]}`，`tested` 仅表示该固定版本至少一次成功测试，不回传冻结的模型连接配置；`sources` 返回消费环境中已开放且当前版本已发布的知识候选 `{publication_id,code,name,knowledge_base_version_id,version,snapshot_sha256,data_class}`。候选列表只是填写辅助，草稿、测试、发布和运行仍按实时来源事实核验。写入沿用 `create-draft`、`test-run`、`publish`、`revoke` 和各自的 `Idempotency-Key`；发布前须有该草稿固定版本的成功测试，界面不自动发布测试结果。

成组案例评测属于固定草稿版本。后台 `GET /app/sand-ai/admin/skills/cases?environment_id=&skill_version_id=` 要求 `sand_ai:skill:read`，返回每例 `{id,skill_version_id,case_no,name,input,expected_criteria,state,latest_evaluation}`；`POST .../cases/create` 接收 `{environment_id,skill_id,skill_version_id,name,input,expected_criteria}`，`POST .../cases/review` 接收 `{environment_id,case_id,skill_run_id,verdict,note}`，`POST .../cases/retire` 接收 `{environment_id,case_id,reason}`，三个写入均要求 `sand_ai:skill:case:manage` 与 `Idempotency-Key`。案例输入必须符合该草稿的输入 Schema，测试仍用已有 `test-run` 执行并独立计量；人工判定只接受案例创建后、输入完全相同、同一固定版本且已成功的测试运行。评测记录只追加，案例内容不可原地改写；废弃案例保留理由及审计。发布时至少有一个有效案例，且每个有效案例的**最新**判定为通过；失败、未判定或被新判定覆盖的旧通过均阻止发布。判定不替代模型输出 Schema、来源授权或业务审批，测试结果不自动发布。

后台 `test-run` 的结果增加 `invocation_request_id: string|null`，仅在对应调用记录已关联时返回用于费用查询的持久请求号；它与 Skill 测试的 `Idempotency-Key` 不同。管理端以此调用已有 `GET /app/sand-ai/admin/usage/cost?environment_id=&request_id=`，该接口仍要求 `sand_ai:usage:cost` 后台权限及 SandIAM 调用读取事实，返回计量状态、金额、币种、Token 与对账字段。金额或 Token 未测得时保持 `null`，页面显示“未知”；费用读取失败可重试，不改变测试运行或案例判定。

### 5.2.1 消费应用受托管理个人 Skill（5.2、5.6，源码目标）

SandAI 只验证 SandIAM 工作负载的组织、应用、环境及客户端上下文；律序等消费应用负责真实人员身份、团队成员、资料复用许可和最终业务权限。公开管理请求的 `scope={type:personal|team|organization,ref:<不透明业务引用>}` 与 `actor_ref` 明确标为 `consumer_declared`，不是平台已验证用户或 SandIAM 授权事实；不得转发浏览器人员 Token、创建平行用户/成员/grant 表或强制 `actor_ref==scope.ref`。个人、团队引用及操作者引用非空、至多 128 UTF-8 字节且不得含控制字符或仅空白；组织引用由服务端固定为已验证组织 ID，拒绝不同组织。组织作用域仍仅限当前应用，不自动跨应用共享。

作用域及创建组织/应用/环境创建后不可改写，保留环境内 `code` 唯一。所有公开管理、测试、运行及回放均加载持久归属核验当前 context；无作用域的旧运行入口不得读写新 scoped Skill。后台旧管理入口只处理 legacy 环境配置，不能冒充个人/团队操作者创建、评测或发布 scoped 内容。每次操作和幂等记录保留可信四级调用归属、作用域及声明操作者；同键换输入、作用域或操作者拒绝，回放仍取得当前正式服务授权。

公开管理以 `/api/sand-ai/v1/skills` 为前缀。所有写请求携 `Idempotency-Key`；POST 作用域与操作者放请求体，GET 放 `scope_type,scope_ref,actor_ref` 查询参数。组织/应用/环境/客户端只能来自已验证 Bearer，不接受请求自报。

| 目的 | 方法与相对路径 | SandIAM 动作 | 请求与返回 |
| --- | --- | --- | --- |
| 列出同作用域方法包 | `GET /` | `sand_ai.skill.read` | `page,limit` 与作用域/操作者，返回既有工作区分页加 `scope,scope_source`，不含其他作用域 |
| 读取方法包与版本 | `GET /{code}` | `sand_ai.skill.read` | 作用域/操作者，返回既有 `skill,versions` 及固定作用域，不返回模型凭据 |
| 读取知识候选 | `GET /sources` | `sand_ai.skill.read` | 作用域/操作者与分页，仍只列当前消费环境有效发布关系 |
| 保存草稿版本 | `POST /drafts` | `sand_ai.skill.manage` | `{scope,actor_ref,draft:<既有中文草稿 DTO>}`；返回既有草稿结果加作用域，不能修改原归属 |
| 读取案例 | `GET /{code}/versions/{version}/cases` | `sand_ai.skill.read` | 作用域/操作者，返回固定版本案例及最新判定 |
| 创建案例 | `POST /{code}/versions/{version}/cases` | `sand_ai.skill.manage` | `{scope,actor_ref,name,input,expected_criteria}` |
| 测试固定草稿 | `POST /{code}/versions/{version}/tests` | `sand_ai.skill.test` | `{scope,actor_ref,input}`；正式知识授权和模型调用授权后走既有计量/收据链，独立计费，不能借后台测试绕过授权 |
| 找回原案例测试 | `GET /{code}/versions/{version}/tests/by-request-key` | `sand_ai.skill.read` 与原 `sand_ai.skill.test` | `request_id=<原测试 Idempotency-Key>` 与作用域/操作者查询字段；只读返回原测试运行摘要，不派发模型或接管租约 |
| 判定案例 | `POST /{code}/versions/{version}/cases/{caseId}/review` | `sand_ai.skill.manage` | `{scope,actor_ref,skill_run_id,verdict,note}`；只接受同作用域、同输入、成功的固定版本测试 |
| 废弃案例 | `POST /{code}/versions/{version}/cases/{caseId}/retire` | `sand_ai.skill.manage` | `{scope,actor_ref,reason}`；保留理由及审计 |
| 发布固定版本 | `POST /{code}/versions/{version}/publish` | `sand_ai.skill.publish` | `{scope,actor_ref}`；该版本必须仍为草稿、全部有效案例最新判定通过且来源仍有效 |
| 撤销后续调用与重放 | `POST /{code}/revoke` | `sand_ai.skill.publish` | `{scope,actor_ref}`；保留历史费用和审计，撤销后不得读回旧成果 |
| 回退活动版本 | `POST /{code}/versions/{version}/rollback` | `sand_ai.skill.publish` | `{scope,actor_ref}`；只选择同归属的既有已发布版本，重新核对来源与案例门，追加审计且不改写版本 |

新 scoped Skill 的既有 `POST /{code}/versions/{version}/runs` 增加必填 `scope,actor_ref`，仍使用 `sand_ai.skill.run` 和独立 `sand_ai.knowledge.query` 授权；GET `.../runs/by-request-key` 同样提供作用域/操作者查询字段。原请求号需保持稳定；响应丢失后找回只读原调用。旧 legacy Skill 沿原兼容契约，不能通过缺省作用域读取 scoped 行。运行始终绑定精确版本，回退 `active_version` 不撤销其他历史固定发布版本。发布、撤销和回退不自动调用模型或发起业务审批。资料来源变化、撤权或归属不一致时失败关闭。

scoped 个人／团队／组织 Skill 的正式运行 `request_id` 必填且非 null，消费后端须在首次 POST 前持久化稳定业务标识；缺失、null 或空字符串返回 `SAND_AI_VALIDATION_ERROR`，不受理 Skill 运行，不以本次 HTTP 审计请求号代替业务幂等键。响应丢失后，原 POST 重试与 `GET .../runs/by-request-key` 使用同一原 `request_id`、作用域、声明操作者和当前可信工作负载身份，找回原精确版本运行。GET 核对保存输入、consumer context、可信身份及固定执行快照的原指纹，只读返回原状态，不派发模型或接管租约；POST 同一持久 key 换输入返回 `SAND_AI_IDEMPOTENCY_CONFLICT`。读取及重放仍核验当前知识发布、来源和已有调用的正式 SandIAM 授权，撤权或撤销后不返回旧成果。有效既有 key、调用号、回执及费用保持；若已知旧 scoped 运行曾回退使用的原审计号，可明确以该原值找回。

无消费 scope/actor 声明的 legacy 运行入口仅保留原 legacy Skill 兼容行为，包括缺失 request_id 时的审计号回退；它不能因省略作用域而访问 scoped Skill，不能作为律序个人／团队正式运行的省略请求号入口。草稿的“知识库版本引用”为 JSON 对象，字段顺序不决定合法性或幂等身份；必须包含且仅包含 `knowledge_base_version_id` 与 `snapshot_sha256`，原类型、快照及当前来源授权要求保持。当前宿主 JSON 解码会折叠原始重复对象键，本次对象字段顺序修复不改变解析器；原始重复 wire 字段拒绝未实现、未验收，不由字段集合校验推断。

上述是 5.6 已独审源码目标，真实律序 caller、人员／团队防伪和创建／测试／发布／运行／撤销闭环，以及真实 HTTP／PostgreSQL／SandIAM／Provider 费用仍未验收。原消费者要求的结构化适用案由、任务类型、触发条件、禁止项及启用前后比较指标尚未冻结统一 DTO／执行语义；来源建议的 trigger/prohibitions 和案例 pass/fail 不等同于这些能力。可逆停用／恢复与现有整体 revoke、固定历史版本及 rollback 的关系也待明确，不虚构新字段或恢复入口。5.6 保持未勾选。

已发布个人／团队 Skill 的显式撤销及原键撤销回放，仅以当前 SandIAM 工作负载身份和固定已发布版本的可信持久元数据取得正式 `sand_ai.skill.publish` 资源授权：核对 Skill 归属、消费发布关系及不可变发布上下文，从精确知识版本的快照和已验证来源归属摘要读取 `data_class`。原知识发布已撤销、知识库已停用或来源内容已清理，不阻止该管理撤销；可信历史事实缺失仍拒绝，不猜默认分级。该元数据事实只用于撤销，不读取来源正文或运行成果；读取、运行、测试、发布及回退仍沿实时来源校验，不能借撤销授权恢复内容访问。原作用域、声明操作者及当前正式服务授权仍必需，历史费用和审计保留。此行为为已独审源码目标，真实 SandIAM／PostgreSQL／HTTP 验收尚未完成，5.2 保持未勾选。

个人／团队案例测试响应丢失后，消费后端用原测试 `Idempotency-Key` 作为 `request_id`，携原 `scope_type,scope_ref,actor_ref` 查询 `tests/by-request-key`。读取核对当前可信工作负载、声明操作者及作用域、固定版本和保存输入的原指纹，重新取得当前读取、测试、知识来源和已有调用事实授权。该入口只读取 scoped `run_kind=test`，不能读取 legacy 后台测试或 `run_kind=workload` 正式运行；不同人员声明、应用、环境或客户端不能读回。固定版本须仍为草稿，Skill 不得已撤销；已发布版本的测试沿原测试生命周期拒绝读取。返回原 `run_id,state,output,citations,error_code,error,review_required,invocation_id,invocation_request_id,idempotent_replay=true`；运行中只返回已持久状态，未关联调用时费用请求号为 `null`。不触发模型、抢占租约、恢复回执或写终态；需要恢复执行时仍显式重试原 POST 与原输入／原键。测试失败保留原错误和费用事实，不能换键重复付费。取得 `invocation_request_id` 后沿既有费用接口和独立 `sand_ai.usage.read` 读取实际费用；费用无权或未知不抹去测试状态，撤销也不删除计量与审计。

案例测试与正式运行首次受理时，服务端将固定版本的输出 Schema 连同方法指令、业务输入和已授权摘录明确提供给模型，字段、类型、必填项及允许值由该 Schema 决定；模型返回后仍由原服务端 Schema 校验器确认，提示词不代替校验或人工案例判定。内部 `prompt_contract_version` 在创建运行时冻结，不能通过公开请求指定或改写。增量迁移 `202610010100_skill_prompt_contract.pgsql` 将已有运行保持为原提示格式1，新运行明确记录格式2；重试与回执恢复沿原运行的格式、输入、固定输出 Schema 和来源重建同一提示，不改已派发请求号、调用指纹、收据或费用。缺少新结构时拒绝新运行，已有格式1运行仍沿原读回／恢复路径。输出无效仍保留真实失败及费用，不自动修订、另发模型调用或发布 Skill。该行为须在真实 Provider、HTTP 和旧回执恢复验收后确认，源码和语法检查不证明模型结果质量。

#### 官方 Neuron 方法包输出策略

新建或修订产生的新 Skill 版本，`execution_snapshot` 必须冻结 `skill_output_strategy` 并纳入 snapshot SHA；当前发布部署未指定该字段时，新版本默认 `neuron_json_extract_v1`。新策略仅接现正式 `openai_compatible` chat 路由；不匹配的路由在派发前拒绝。已存在快照缺字段只属于历史兼容，不回填或重算其 SHA，不改变旧请求指纹、prompt、provider body、receipt、费用或原键恢复。

默认策略直接使用官方 Neuron 4.0.2 chat/Message 与 JsonExtractor；Extractor 只接受完整 JSON 文档，不容忍 Markdown 包裹或文档外文本。显式 JSON 对象／JSON Schema 策略分别调用官方 ZAI／OpenAI `structured()`，由官方 SDK 构造消息、schema 和 response_format；OpenAI strict=false 保持原动态业务 schema 的 required/optional 语义，不自动改写 schema。该选择通过未来正常保存、验证、发布的新部署及能力配置版本进入新 Skill 快照，不静默修改现已发布部署/profile/Skill。历史路由保持原配置可追溯。

官方 SDK 生成的实际请求体在派发前由无 I/O 适配器捕获，按既有账本冻结 body digest；实际运行再次由同 SDK 构造，并经过既有 NeuronControlledHttpClient 校验 method/URL/headers/body SHA，唯一 ProviderSendBoundary 只允许一次发送。无 Agent 循环或自动重试；schema/消息字节进入现预算的 advisory，硬预占仍按已验证的 input_token_ceiling 和输出上限，不造新预算政策。

动态 SkillSchemaPolicy 的大小、深度、必填、额外字段、类型、范围与 enum 是 SandAI 业务契约；官方 typed Validator/Deserializer 对静态 PHP 实体属性的校验不能替代这些动态 schema 约束。官方提取先确认完整 JSON，业务数组映射沿同一原文保留数字类型和深度64，避免 SDK canonical re-encode 将 1.0 转为1而弱化 integer/number 区分。业务校验继续拒绝错误结果，既有来源、授权、显式评测/发布、撤销与费用语义保持。运行 DTO 未增加消费者可控策略/schema 参数。

新增 Skill 元数据、版本/运行/操作/案例的声明上下文和不可变归属约束随增量 PostgreSQL 迁移交付；未安装所需结构时公开管理拒绝，不回填历史个人身份。服务目录须同批显式声明 `sand_ai.skill.read/manage/test/publish`，不自动恢复被停用动作。上述是源码目标契约，不证明真实人员隔离；5.6 须由律序真实后端证明用户不能伪造归属、本人/他人/团队允许拒绝及撤销，费用仍需真实 Provider/HTTP 验收。

SandAI 现有“知识库配置”页新增“应用方法包 · 消费范围”样本入口，沿上述正式工作负载 API 管理草稿、固定版本案例、明确判定、发布、运行、撤销与回退。后台登录不提供消费身份；Bearer 仅在该页面内存保留，范围与操作者必须来自消费后端并明确为 `consumer_declared`，不显示为已验证个人／团队，不替代律序真实消费后端的人员、成员及业务权限。旧环境管理入口仍只处理 legacy 内容。版本内容只读，修改保存为新草稿；模型测试和运行需显式确认且独立计量，成功结果不会自动判定或发布。

首次发送前，本机会话仅保存原请求键、代码、固定版本、调用类型及输入 SHA；不保存 Bearer 或输入正文。管理请求未确认时保留同意图原键；模型请求异常或失败保留原意图并阻止换键重复发送。找回沿原键只读 API；明确失败且本页持有原输入 SHA 时，用户可显式以同键同输入恢复，仍由服务端未派发／已有调用事实门决定。手动找回而无原输入指纹时仅提供只读恢复，不能自造输入重发；结果不明不提供新派发。费用沿原 `invocation_request_id` 独立读取，授权或费用读取失败不抹去已取得的运行结果，撤销／成果拒绝不删除计量；金额未知不显示为零。该新增样本入口目前仅有源码及类型检查证据，正式加载、页面业务操作、个人／团队／真实律序人员验收仍待完成，不改变 5.2／5.6 状态。

#### Skill 草稿修订与稳定身份（2026-10-03）

#### POST /api/sand-ai/v1/skills/drafts

现有正常草稿请求保持兼容。修订已保存草稿时，在 `draft` 外层同时增加 `replace_draft: true` 和正整数 `expected_draft_version`，且 `draft.code` 必须是当前方法包代码。缺字段、非 true 或非正整数使用现有 `SAND_AI_VALIDATION_ERROR`。

同原请求键先查持久操作结果。已成功同键同输入、同 consumer_context，在当前 Skill/知识/正式资源授权仍允许读取时返回原结果并置 `idempotent_replay: true`，绝不创建新版本；原草稿已变为 abandoned 不构成新的回放冲突。结果中的 state 是原操作持久回执；普通详情 GET 显示该版本当前生命周期状态。改变输入仍使用现有 `SAND_AI_IDEMPOTENCY_CONFLICT`。

首次修订锁定方法包与当前草稿，CAS 验证其版本等于 expected_draft_version；不存在当前草稿、竞争导致 base 过时、未安装迁移 123、原草稿存在 running 测试或 prepared/dispatching/ambiguous 派发证据均使用现有 `SAND_AI_RESOURCE_CONFLICT`，不修改原证据、不接管派发。确认可修订后，同一事务仅将旧 draft 状态变为 abandoned，新增最大版本号加一的 draft 及新的固定知识引用与执行快照。旧内容、案例、评测、运行、计量和活动发布版本不变；新版本案例为空、tested=false，必须重新创建案例、测试、明确判定后显式发布。

abandoned 版本可读详情/案例和原测试只读 GET；原测试仍须同 consumer_context/输入指纹、当前知识与原 invocation 授权，方法包撤销或来源清理依原门拒绝。POST test、失败恢复、发布均仍只允许 draft。published 测试只读门保持既有行为。abandoned 不能修改、测试、发布或运行。

实际授权调用数量：普通草稿仍有管理 workload_client 前后两次和 knowledge.query 一次；修订增加目标旧版本 skill.manage 资源前后各一次，共增加两次正式资源授权尝试，沿原 SandIAM quota，不旁路、不凭空新增额度政策。每次 operation_id 使用现有生成方式，request_id 仍为原请求审计 ID。

#### GET /api/sand-ai/v1/skills

成功分页返回在现有字段上增加 `workload_identity` 对象，严格四项：organization_id、application_id、environment_id、workload_client_id；均为正十进制字符串，来自当前正式验证后的 IdentityContext，且位于既有前后资源授权成功之后。不包含 Bearer/context_id，不证明实际人员/团队身份；没有新增 API 调用或授权额度调用。

#### 既有知识库页消费范围方法包入口

正常管理账号加上述四 ID、consumer_declared scope/actor 和原操作输入的规范指纹形成会话存储 namespace；Bearer 只在本页内存，换短上下文不改变业务 namespace。上下文/声明/管理账号改变时，先清当前内容及身份事实并禁写；用户显式读取工作区、得到当前正式四 ID 后，才恢复同身份的原运行键和未确认管理键。旧 Bearer hash 格式不猜迁移。

会话仅持久原键、操作/输入 SHA 和草稿 code/base version 定位，不持久 Bearer 或业务正文。未确认管理请求锁定原操作与原指纹；换上下文后需同原内容显式恢复，不能换输入或换键自动发送。已确认成功才清 pending。案例评测、发布和计费运行仍是独立明确动作；未确认管理期间不新增计费请求。

#### SQL 123 影响

新增 `202610030123_skill_draft_revision.pgsql`，不改已安装 095 或其他旧迁移校验和。只替换 skill_version 状态 CHECK 并增加 abandoned；替换既有 version immutable 函数，保留 095 精确来源脱敏例外，限制旧草稿 payload 不变，仅允许状态切换及正常 published_time/published_consumer_context 写入；替换固定知识引用函数，固定引用不能 UPDATE/DELETE，新引用仅可插入 draft。不回填或删除现存行，不改 IAM、费用、Provider、模型、预算、运行状态、索引或活动发布快照。

SQL 由正式公共迁移入口在明确授权的目标库执行。现有源码没有修改/删除草稿知识引用的业务入口；来源清理只沿既有 provenance 脱敏例外。唯一整合者登记新迁移并按已授权隔离库公共迁移入口执行；source 静态检查不证明数据库触发器安装/并发或真实 UI 业务成功。


### 5.2.2 获授权来源提炼与人工采用（5.2、5.6，源码目标）

来源提炼沿同一工作负载与 `consumer_declared` 作用域，不转发人员 Token。提炼需 `sand_ai.skill.manage`、`sand_ai.knowledge.query` 和正式模型消费动作；来源读取先用固定知识版本与快照核验当前发布关系，再用真实来源块检索，不能让模型判断资料复用许可。`reuse_declaration` 是消费应用提供的资料复用及脱敏确认审计引用，来源标为 `consumer_declared`，不证明平台独立验证了法律许可。

| 目的 | 方法与相对路径 | 请求与返回 |
| --- | --- | --- |
| 提炼待审方法建议 | `POST /source-suggestions` | `{scope,actor_ref,source:{knowledge_base_version_id,snapshot_sha256,query,model,reuse_declaration}}`；携稳定 `Idempotency-Key`，固定来源版本和实际命中块；返回 `source_suggestion_id,state,methods,citations,invocation_id,usage,error_code,idempotent_replay,review_required=true` |
| 只读找回原来源建议 | `GET /source-suggestions/by-request-key` | `request_id=<原提炼Idempotency-Key>&scope_type=&scope_ref=&actor_ref=`；当前 `sand_ai.skill.manage` 及正式持久资源授权，返回下述原建议摘要或已清理墓碑，不触发生成／账务恢复 |
| 人工采用方法建议 | `POST /source-suggestions/{suggestionId}/adopt` | `{scope,actor_ref,selected:<suggestion_no列表>,draft:<既有中文草稿DTO>}`；携独立稳定 `Idempotency-Key`，只能采用同作用域待审候选，草稿引用必须保持原知识版本及快照；返回既有草稿保存结果，不发布 |

只读找回完整路径为 `GET /api/sand-ai/v1/skills/source-suggestions/by-request-key`。查询仅接受必填 `request_id,scope_type,scope_ref,actor_ref` 四项；未知字段或无效类型返回 `SAND_AI_VALIDATION_ERROR`。`request_id` 是原提炼请求的原始稳定键，不是服务端hash，沿既有key规则为非空字符串、最多128字节、不得含ASCII控制字符；作用域／操作者沿5.2.1规则，不新增人员身份模型。服务端以当前完整consumer snapshot（可信四ID、声明作用域和操作者）及该原键构造既有 `SkillConsumerContext::key`，按 `environment_id,request_id` 定位唯一持久建议，再精确核对 `consumer_context`；不存在或不同consumer返回 `SAND_AI_RESOURCE_NOT_FOUND`，不透露其他归属。当前 `sand_ai.skill.manage` 正式资源为 `sand_ai.skill_source_suggestion`，资源引用沿既有 `suggestionId:workloadClientId`；不能借后台登录或原已过期上下文读取。

未清理建议沿当前知识发布／版本来源授权，已关联正整数invocation时沿既有Chat调用事实授权。成功返回原摘要 `source_suggestion_id:int,state:generating|pending_review|adopted|failed,methods:list,citations:list,invocation_id:int,usage:object|[],error_code:string|null,idempotent_replay:true,review_required:true`；尚无调用保持既有 `invocation_id=0`／空usage表示未确认，不推断零费用。`generating`只报告持久状态，不接管租约、封存回执、派发、写用量／预算或暗示可换键重发。已清理建议只沿当前同consumer及正式manage资源授权返回5.2.3既有 `content_purged` 墓碑；不读来源正文或要求已撤销来源再次提供正文。剩余内容／回执计数来自当前只读检查，当次 `provenance_versions_purged=0,receipt_ciphertexts_purged=0`；`accounting_state`只投影现事件和原持久proof为 `settled|pending|not_invoked|unknown`，不得调用 `recoverPurgedAccounting` 或创建账务。整个GET不做SandAI业务状态、账务或执行租约写入；正式SandIAM授权自身审计仍按原端口执行。原POST／adopt／DELETE字段和显式恢复语义不变。

提炼 `query` 为非空至多 500 个 UTF-8 字符，`model` 为现正式模型 code；模型发送走现有授权、计量与派发围栏，输出上限固定 4096 token。`reuse_declaration` 为非空至多 1000 UTF-8 字节的消费审计引用。模型输出至多 10 条建议，每条包含 `name,method,trigger,prohibitions,source_block_ids`；服务端赋 `suggestion_no`，逐条要求引用本次实际检索命中集合中的一个或多个来源块，不接收模型自造来源。封存状态为 `pending_review`，禁止自动发布或自动学习。

人工采用选择互不重复的建议编号并明确给出可评测草稿 DTO，仍走既有草稿、案例、测试及发布门。不可变版本保留原生成调用、固定知识快照、逐条已采用方法、实际来源摘要与消费应用的采用者声明；幂等回放重新核验当前正式服务授权及来源。消费后端负责实际人员的资料复用/脱敏确认和最终采用。

新受理的来源建议调用在已有请求指纹输入中加入内部`skill_source_suggestion_driver=neuron_chat@1`，默认通过官方Neuron4受控聊天；不新增公开字段。恢复按完整旧／新请求指纹精确核对，旧无marker的持久Invocation或发送前拒绝保留原指纹／驱动协议；prepared只沿原派发围栏继续，dispatching或未知结果只核原回执，不改冻结消息、body摘要、账务或自动重发。并发期间若冻结事实不符继续幂等冲突拒绝，不能以协议升级覆盖历史调用。

持久生成状态为 `generating|pending_review|adopted|failed`。结果恢复必须沿原生成请求号及调用回执，不得自动改键重复付费；普通公开 Chat 禁止留存可重放正文，不能扩大该边界以满足来源提炼。来源提炼采用服务端从持久 owner、完整调用归属和实际来源摘要构造的 typed 内部上下文，独立回执目的为 `skill_source_suggestion`；在模型可发送的接受事务内绑定唯一调用，先写加密原结果，再幂等计量和预算结算。原键可恢复已计量但尚未封存的同一结果，合法建议或非法格式失败均与回执消费确认在同一事务封存；非法输出保留真实费用及 `error_code`，不自动换键。该源码恢复链仍须独立审查及真实中断、Provider 费用、律序隔离和采用验收。来源建议的清理契约见下节，不以源码实现代替真实删除和费用验收。

### 5.2.3 来源建议内容清理（6.3，源码目标）

`DELETE /api/sand-ai/v1/skills/source-suggestions/{suggestionId}/content` 携 `Idempotency-Key` 和 JSON `{scope,actor_ref}`，只接受这两个业务字段。取得当前 `sand_ai.skill.manage` 及正式持久资源 `sand_ai.skill_source_suggestion` 授权后，核对持久消费组织、应用、环境及声明作用域。资源授权使用 owner 冻结的来源 `data_class`，不把消费声明作为已验证人员身份；撤销原知识或 Chat 读取权不会阻止该管理清理。服务目录只复用既有 manage 动作，不自动建 grant。

成功返回 `{suggestion_id,state:'content_purged',content_purged_at,idempotent_replay,remaining_private_fields,remaining_receipt_ciphertexts,provenance_versions_purged,receipt_ciphertexts_purged,accounting_state}`。`accounting_state` 为 `settled|pending|not_invoked|unknown`：已测量并按原冻结价格结算才是 settled，待恢复或真实用量未知不得当作零费用。两项剩余内容计数来自实际持久检查；同清理键换目标或声明上下文拒绝，已经清理的同对象重试仍检查真实余量并尝试纯账务恢复。

同一 owner 事务清空证据、建议、查询与资料复用声明，以及所有采用版本的 `provenance` 私有复制快照；版本仅保留固定来源 ID/hash 墓碑与 `source_provenance_purged_time`，不改人工草稿指令、Schema、状态或独立 Skill 产物。固定版本不可变保护只允许真实同 owner/scope、精确墓碑和其余全行相同的单向删内容。原调用、作用域审计、不可变用量、价格和预算累计保留。仅销毁该调用 purpose=`skill_source_suggestion` 的私有回执，不能清理其他调用成果。

销毁前若存在真实 Provider 结果，先持久化仅含真实用量、归属、路由和价格绑定的 typed 无正文账务证明；内容事务提交后再调用原费用引擎恢复结算，无网络和新派发。Provider 晚到同样只封存无正文证明并保留真实费用。未知用量保持未知或待恢复；不能用空正文制造失败、换键再付费或恢复已经删除的内容。

原提炼键重试先取得当前 manage 正式 owner 授权并核对原请求指纹及完整消费上下文；已清理时返回上述无正文墓碑及账务状态，不要求当前知识或 Chat 内容读取权。普通提炼重放、封存及采用在返回前重新检查 owner 墓碑和当前来源权限；采用已清理建议返回 HTTP/code `410`、`SAND_AI_SKILL_SOURCE_CONTENT_PURGED`。历史版本仍有独立读取权时只能返回来源墓碑，不以墓碑扩大读取权限。

既有后台 `KnowledgeSourcePurgeGateway::purgeDisabled` 继续要求知识库停用、所有发布撤销和向量已清理；在删除来源映射前按固定知识版本关系清理全部来源建议 owner 及采用副本，随后在内容事务外恢复费用。响应增加 `source_suggestions_purged,source_suggestion_receipts_purged,remaining_source_suggestion_content,source_suggestion_accounting_pending`；不返回其他消费应用的私有正文或人员引用。原删除操作幂等回放仍补查派生 owner 余量。

增量迁移 `202609280095_skill_source_content_lifecycle.pgsql` 保持历史 094 原文：从持久固定版本、快照及唯一消费发布关系校验回填 typed 来源关系，无法核实或未知旧墓碑即失败关闭，不猜造人员事实。此契约仅冻结源码入口；直接删除、重复键、撤权清理、来源级联、Provider 晚到和费用不减均须真实 HTTP/数据库/Provider 验收，本轮不执行这些操作。

### 5.3 SandIAM 服务目录预检（2.4）

`GET /app/sand-ai/admin/setup/service-catalog/preflight` 以及 Skill、音频对应的预检路由均要求 `sand_ai:setup:service-catalog:read`，响应保留 `port_available`、`service_code`、`action_codes`，增加 `service_status: missing|active|disabled|null`、`action_statuses: Record<action_code, missing|active|disabled>` 和 `can_register: boolean`。端口缺失时 `service_status=null`、`action_statuses={}`、`can_register=false`；端口可用时动作状态必须完整覆盖声明。后端仅调用 SandIAM `ServiceCatalog::inspectServiceActions` 本地只读端口，不读写 `sand_iam_*` 表。响应无效或读取失败须关闭失败，不能把未知状态当作“可登记”。

管理端逐项显示已停用的服务或动作，并禁用登记按钮；缺失项允许管理员通过既有 `POST .../register` 明确登记。SandIAM 的正式登记事务重新检查停用状态，预检与点击之间若发生变动，以登记结果为准。所有目录接口只涉及服务与动作声明，不创建组织、应用、环境、客户端、凭证或 grant，也不恢复已停用项。

基础登记须覆盖对外入口所要求的 `sand_ai.file.write`、`sand_ai.file.read`、`sand_ai.model.read`、`sand_ai.task.read`、`sand_ai.task.cancel`、`sand_ai.task.retry`、`sand_ai.tool.run`、`sand_ai.tool.read`，连同既有聊天、Agent、检索、调用、费用、知识、解析和工作流动作。个人 Skill 和音频动作仍按各自显式登记入口办理。任何对外 Controller 新增的 SandIAM 动作必须出现在这三组目录声明之一，否则安装后无法配置 grant；目录声明覆盖测试须检查此约束。

音频登记入口目前声明 `sand_ai.audio.transcription`、`sand_ai.audio.review`、`sand_ai.audio.speech`、`sand_ai.audio.speech.read`。开通页须同时识别这四项预检和登记结果；将音频目录误判为单个转写动作，会使实际登记成功后仍显示失败。新增音频动作时，开通页应保留对既有四项的核验，并接受目录返回的新增声明。

已有私有文件的 `GET /api/sand-ai/v1/files/{fileId}` 和 `DELETE /api/sand-ai/v1/files/{fileId}` 在读取元数据或变更文件状态前，须以服务端持久文件 ID 通过 SandIAM `authorizeInvocation` 核验 `sand_ai.file.read` 或 `sand_ai.file.write`；资源事实解析器从当前文件记录取得四级归属与 `data_class`，不接受请求体自报。GET 读完文件元数据及最新解析信息后、返回成功响应前，使用同一 `authorization_id`、原签名 context、相同 `sand_ai.file.read` 动作与文件资源键、可信身份、连接 IP 和请求号，经正式 `revalidateInvocation` 重验当前授权；拒绝不返回已读取的 DTO，也不再次申请调用授权或消耗 quota。最后检查到实际响应发送之间仍有小窗口，真实并发撤权行为尚未验收。

私有对象删除失败后，文件保留 `delete_failed` 状态供原工作负载重新发起 `DELETE`；删除进程中断后遗留的 `deleting` 状态也允许原工作负载重试。两种状态仅允许 `sand_ai.file.write` 的删除事实核验，`sand_ai.file.read` 及其他文件消费动作均不得读取。删除阶段以数据库行锁覆盖私有对象删除和状态封存；同时重试只能由一个事务完成，已成功删除的第二次请求返回资源不可用。已删除状态不开放调用；文件元数据读取在正式授权后仍复核当前状态、启用和删除时间，避免授权到返回之间的状态变化泄露旧信息。

文件仍有未终结的解析、音频转写、会议摘要、引用该文件来源块的普通 `ai_completion` 任务或结构化工作流时，删除返回 `SAND_AI_RESOURCE_CONFLICT`，须先完成或取消任务。转写与摘要提交在写入任务的事务中锁定私有文件并复核其仍为 `uploaded`；删除与新任务提交据此按文件行串行。普通 AI 来源任务创建、同键重新入队及手动 retry 同样在事务中按文件 ID 排序锁定来源文件，并重新核对当前状态与来源事实。工作流冻结文件时锁定文件行；授权失败后的同键重放在重新入队前复核原固定文件及解析版本。未被已发布知识版本引用的文件在私有对象删除成功后，于同一数据库事务清空其所有来源块正文、封存来源块及解析记录，并清理所有引用该文件的历史工作流运行快照、最终成果、逐文件成果、跨文件核对成果及相关调用的加密结果回执；原执行身份、来源关系、费用与审计事实保留，清理数量写入文件删除审计。多文件运行只要有一个文件被删除，整个运行的成果正文即不可用，其他文件原件和其他不相关运行保持不变。数据库事务失败留下 `deleting` 供原身份重试。已发布知识源继续拒绝直接删除，需先处理发布引用。

普通私有来源 AI 任务以持久 numeric `input.source_block_ids` 关联来源块的可信 `file_id`；文件删除仅清空来源块正文并封存状态，保留块 ID 和文件关系供历史清理定位。删除第二阶段对匹配的终态任务取得 `NOWAIT` 行锁，存在重试／修改竞争时返回资源冲突；在原删除事务中清空 `result`、设置 `content_purged_time`，并销毁该任务持久 `ai_task` 派发关联的结果回执。文件删除审计增加 `ai_task_content_purged:{tasks:int,receipts:int}`，对外 DELETE 响应仍为 `{id,state:"deleted"}`。清理后的来源／成果读取及原键重新入队拒绝，不能借重试恢复正文；原任务、调用、用量、费用及审计保留，迟到已发生调用仍须记费用。新增 source-only 迁移 `202610020104_ai_source_task_content_purge.pgsql` 仅扩展 086 清理约束，允许非会议且带非空来源块数组的普通 AI completion 设置清理标记；原音频、会议及 Agent 条件保持，来源为空的普通聊天不随文件删除清理。迁移尚未执行，真实 PostgreSQL、私有存储、并发及 HTTP 门未验，6.3 不因源码或静态审查通过而完成。

智能体任务在模型请求前登记本轮和历史上下文使用的私有文件。删除文件时，正在运行或等待结果恢复的智能体任务，以及其他尚未终结且已登记该文件的任务，返回 `SAND_AI_RESOURCE_CONFLICT`；失败任务重新入队前须复核已登记文件仍可用。删除成功会清空引用该文件的终态智能体结果正文，保留任务、调用、用量及费用事实；待发送的渠道消息转为失败，正在发送的消息须先结束。旧成功任务按已保存的 `source_references` 一并清理。已发送到外部渠道的消息不由本地文件删除撤回。

同一删除事务还清空该文件关联的终态录音转写、会议摘要结果与转写请求术语表，清空人工复核文字和外部音频终态快照，销毁外部音频及摘要模型调用的加密回执正文。任务 ID、修订号、调用身份、不可变用量、费用及审计事实保留。清理标记阻止失败任务重试和迟到回执重建；迟到模型成功响应仍须记录实际用量与费用。迁移和真实 PostgreSQL 并发验收完成前不得将该清理视为已部署。

已清理工作流详情保留原任务状态及费用摘要，并返回 `content_purged=true`、`result=null`、`citations=[]`、`artifact=null`、`file_results=[]`；来源块读取返回 `SAND_AI_RESOURCE_NOT_FOUND`。未清理运行返回 `content_purged=false`。失败任务重试在入队事务内重新锁定固定文件、核对当前文件和清理状态；文件已删除或正在删除时拒绝重试。若被取消的模型请求在清理后才返回成功，服务端不得重新保存正文回执或成果，但仍按实际响应记录用量、费用和调用状态。

`POST /api/sand-ai/v1/files` 在生成文件 ID 前，以已验证 SandIAM context 中的 `workload_client_id` 为资源键，经 SandIAM 宿主本地 `WorkloadClientReferenceVerifier::verifyForService` 只读端口取得当前启用、未删除的工作负载客户端四级归属，形成 `sand_iam.workload_client` 资源事实（`data_class=null`）。文件上传前调用 SandIAM `authorizeInvocation` 要求 `sand_ai.file.write`；SandIAM 再比较该资源事实与原签名 context，不接受请求体中的身份、归属或数据分级。上传后的文件仍持久化自身可信归属，后续读删使用文件事实解析器。端口缺失、引用失效或正式授权拒绝时，不得写入私有存储或文件记录。

`GET /api/sand-ai/v1/models` 在读取当前环境的模型目录前，以已验证 SandIAM context 中的 `workload_client_id` 为资源键，复用 SandIAM 工作负载客户端只读引用事实（`sand_iam.workload_client`、`data_class=null`），调用正式 `authorizeInvocation` 要求 `sand_ai.model.read`。引用失效、四级归属不符或 grant 拒绝时不得返回模型元数据。

受管外部资料连接的创建请求必须由管理员声明来源结果的 `data_class`，该值写入不可变的 `sand_ai_tool_connection_revision`，并随工具运行执行快照固定；不得从对外调用的 `business_scope` 或输入推断分级。旧连接修订没有可核实分级时保持不可运行，须由管理员重新核实来源并建立带分级的新连接。此声明代表管理员对所配置来源的分类责任，真实来源内容与声明是否相符仍须独立验收。

`POST /api/sand-ai/v1/tools/{code}/versions/{version}/runs` 在生成运行记录后、任何 HTTP/MCP 派发前，以持久工具运行 ID 为 `sand_ai.tool_run` 资源键，要求 SandIAM `authorizeInvocation` 的 `sand_ai.tool.run` 动作。事实解析器从运行记录、身份快照、连接修订和冻结分级核对四级归属及 `data_class`；授权拒绝须终结未派发的运行记录且不请求外部服务。每次传输前及成功结果返回前均须 `revalidateInvocation`；撤权后停止后续 MCP 请求，不返回已持久化的旧结果，并保留此前已发生请求的计量证据。幂等重放也必须重新授权。`GET /api/sand-ai/v1/tool-runs/{runId}` 要求 `sand_ai.tool.read` 的正式调用授权，在返回状态或结果前再次重验；仅有 SandAI 本地身份快照匹配不足以读取结果。

管理端工具试用只调用上述应用接口。操作员填写已发布且已绑定当前工作负载环境的工具代码、固定版本、业务范围和符合该版本 Schema 的 JSON 对象；页面固定同一次试用的 `Idempotency-Key`，结果未确认时只能沿原键重试或按已返回的运行号读取。页面呈现受控结果、来源引用、人工复核标记和 `external_request` 计量摘要，不显示连接凭证或原始 MCP/HTTP 响应。试用不发布、绑定或修改工具。

后台 `GET /app/sand-ai/admin/tools/available?environment_id=&page=&limit=` 要求 `sand_ai:tool:read`，先由 SandIAM 只读确认环境引用，再只返回该环境中状态有效、工具与版本均已发布的绑定。分页从 1 开始，`limit` 至多 100；每项只含 `{ code,name,version,adapter,input_schema }`，不得返回连接地址、授权配置、凭证、MCP 协议原文或跨环境绑定。此目录只辅助选择；应用运行接口仍按工作负载 Bearer 所属环境和当前绑定独立核验。

后台工具配置按显式步骤调用既有 `POST /app/sand-ai/admin/tools/connections/create`、`tools/mcp/discover`、`tools/create-draft`、`tools/publish`、`tools/bind-environment`。创建连接需要当前 SandIAM 环境及管理员声明的 `data_class`，可选 Bearer 只在创建请求中提交、页面在成功后清空；MCP 发现只把本地只读注册表允许的快照项交给管理员选择，再用 `mcp_snapshot_item_id` 创建草稿。发布和环境绑定均由管理员分别触发，试用不会自动执行这些操作。每个写动作单独使用 `Idempotency-Key`，结果未确认时沿原键重试。

受管 MCP 连接固定 2025-11-25 Streamable HTTP。请求同时声明接受 `application/json` 和 `text/event-stream`；完整 SSE POST 响应只取与当前 JSON-RPC 请求 ID 相符的一条结果，忽略服务端通知，拒绝服务端主动请求、错 ID、重复结果和无效帧。流在结果前结束时标为结果不明，不重发工具调用；超时、响应字节上限、私有连接凭据、SandIAM 授权及结果 Schema 校验仍沿现有工具运行链执行。SSE 断流后的会话续接尚未实现，不能把该路径称为完整 MCP 传输验收。

后台 `GET /app/sand-ai/admin/tools/connections/index?environment_id=&page=&limit=`、`GET .../connections/read?environment_id=&connection_id=` 及 `GET .../connections/by-code?environment_id=&code=` 要求 `sand_ai:tool:read`，核验 SandIAM 环境引用后返回该环境当前有效连接的 `{ connection_id,connection_revision_id,code,name,data_class }`；按 ID 读取可找回不在列表第一页的连接，按代码读取可找回创建成功但响应丢失的连接。三者均不返回地址、路径、授权方式或凭证。`GET /app/sand-ai/admin/tools/mcp/latest?environment_id=&connection_id=&connection_revision_id=` 使用相同读取权限，核验当前环境与连接修订后，仅返回该修订最近一次 `tested` 发现快照的受控摘要及本地只读工具项；没有有效快照时返回资源不存在。这些只读入口帮助页面恢复连接与发现选择，不触发 MCP 请求或改变绑定。

后台 `GET /app/sand-ai/admin/tools/workspace?environment_id=&connection_id=&code=` 也要求 `sand_ai:tool:read`，先核验环境与该环境当前有效连接，再按精确工具代码读取全局定义、当前草稿、当前发布版本及该环境的绑定事实。MCP 版本只有在冻结发现快照属于所选环境、连接与修订且仍为 `tested` 时才作为可继续版本返回。响应只含工具代码、名称、适配器、版本 ID/序号/状态和绑定 ID/引用，不回传 Schema、连接配置、凭证或原始 MCP 响应。页面刷新后以会话内非秘密的工具代码重新读取服务端状态；发布和绑定仍须由管理员分别显式触发并由写接口再次核验。

`GET /api/sand-ai/v1/tasks/{taskId}`、`POST /api/sand-ai/v1/tasks/{taskId}/cancel` 和 `POST /api/sand-ai/v1/tasks/{taskId}/retry` 分别要求 `sand_ai.task.read`、`sand_ai.task.cancel` 和 `sand_ai.task.retry` 的正式调用授权。资源键是持久任务 ID；事实解析器从任务及其原始来源（AI/Agent 任务快照、文件或音频对应的现存文件、工作流运行）核对四级归属与数据分级，不接受请求自报。读取结果、取消或重试前先授权，返回结果前重验；来源事实缺失或变化时拒绝。底层资源动作原有的授权与重试前校验继续执行。
工作流任务手动重试还须使用当前签名上下文重新取得 `sand_ai.workflow.run` 对原 `workflow_run` 的授权，并替换队列中绑定该运行的加密上下文；不能沿用上次执行已过期的授权。Worker 仍在执行前调用 SandIAM 重验当前授权。

### 5.3.1 受控外部工具、搜索与 OCR 计量（4.4，已独审源码目标）

本段记录 4.4 已独审的源码目标契约，真实 PostgreSQL、HTTP、SandIAM、Provider 和页面尚未验收。工具连接继续使用既有标准只读 HTTP／固定 2025-11-25 MCP Streamable HTTP、发布版本和环境绑定，不增加 Agent 副作用工具或全局唯一厂商。`external_search` 是代码所有的只读连接预设；后台配置、MCP 发现及冻结、固定版本试用、SandIAM 发送前重验、受控结果和 ToolUsageMeter 均沿现有链，不能把源码串接称为实际调用闭环通过。

`external_search` 的 `input` 是禁止额外字段且必含 `query`、`limit` 的对象：`query` 为 1–2000 字符字符串，`limit` 为 1–20 的整数。`result` 是禁止额外字段且必含 `matches` 的对象；`matches` 为数组，至多 20 项且不得超过请求的 `limit`。每项是禁止额外字段且必含 `source_ref`、`title`、`excerpt` 的对象：`source_ref` 为 1–256 字符、`title` 为 1–300 字符、`excerpt` 至多 1000 字符。每个 match 的 `source_ref` 唯一，并须在既有受控 `citations` 中有对应来源版本／内容摘要引用；每条 citation 的来源也必须存在于 matches，不得返回孤立引用。合法无结果为 `result={"matches":[]}` 且 `citations=[]`；其他工具仍保留既有非空来源要求。标准 HTTP 草稿采用此预设时也必须遵守相同固定 Schema，MCP 使用注册表允许的 remote `search` 与冻结 Schema。

工具运行请求、结果、指纹、存储和加密 outcome 回执严格区分合法空对象 `{}` 与数组 `[]`；只有允许零字段的对象 Schema 可接受空对象，数组不能代替对象。原始 JSON 重复对象成员拒绝，不以框架折叠后的字段冒充严格校验。标准 HTTP／MCP 的响应在每个接收 chunk 写入底层流之前核验剩余字节容量，超限立即终止，保留最终长度核验；不能仅完整读取后才检查。传输固定 CurlHandler 和受控解析地址，禁代理及重定向。MCP 初始化、initialized 和发现／调用共享单调时钟传输预算，各次请求使用剩余时限；授权回调／数据库耗时并非严格全 RPC 时延上界，仍需真实慢响应验收。

默认 `external_knowledge_query` 的 answer 最大长度由超出本地 Schema 子集的 200000 修为 65536，并加入 external_search 后，代码注册表 Schema／hash 改变；旧已测试发现快照因漂移拒绝使用。管理员须重新发现，创建并测试、发布新的固定工具版本，再明确绑定环境；不自动重新发布、重绑或绕过冻结校验。

本地 OCR 发送前要求安装 `202610020103_ocr_dispatch_usage.pgsql` 的受控账本结构及关键触发器，否则返回 `SAND_AI_SCHEMA_UNSUPPORTED` 并拒绝发送。FileParseGateway 的图片 OCR 和 PDF OCR fallback 在明确的 `curl_exec` 边界前，于同一事务校验并锁定当前 task／parse／attempt／lease fence／parser 归属，重验排队的 SandIAM 授权，原子写入不可变 dispatch 和绑定的 usage_event。单位为 `external_request=1`，只表示进入已授权 Curl 边界的一次请求尝试，不证明 Provider 收到、处理成功或收费；事务完成后、Curl 执行前崩溃也可能留下该尝试事实。不得从来源 blocks 推导 OCR 页数，也不编造 token 或账单用量。

OCR 价格 revision／rate／金额／currency 保持 NULL、`unpriced`，不能将未知费用显示为零；非 token 单位沿既有 token 占位 0 的存储约束，不代表 OCR 免费。完整响应仅追加 `response_known` 和 HTTP 状态；异常、超时、截断、接收超限或无有效状态追加 `uncertain`。无 outcome、uncertain 或同一 fence 已有 dispatch 都禁止自动重发，返回 `SAND_AI_OCR_DISPATCH_AMBIGUOUS`；`SAND_AI_OCR_USAGE_UNAVAILABLE` 表示受控计量边界不可用，两者均不自动重试。已知完整 HTTP 5xx 可沿既有任务恢复创建新 attempt，每次重新授权和计量；没有 OCR 正文回执重放或人工解除不明发送的接口，不能承诺已知响应后的解析／持久化失败恢复绝无第二次请求。账本只保存身份及发送／结果元数据，不保存私有路径、密钥、OCR 正文或原始厂商响应。

本地 OCR 响应在 Curl 接收回调中限为 `SAND_AI_PADDLE_OCR_RESPONSE_MAX_BYTES`：默认 8388608 字节，允许 1024–16777216 字节；timeout 上限 300000 ms。连接仍为私有文件的 loopback HTTP sidecar，拒绝 URL 凭证、query、fragment，禁代理／重定向且 localhost 固定 loopback。ParseDriver／ParseInput／ParseResult、驱动注册及环境路由保持替换契约；云 OCR 是媒体设计中的未来可配置驱动，本轮未接入云 OCR 厂商。ASR、TTS、Embedding 沿既有可配置驱动和真实单位链，仍需分别以真实连接验收。4.4 保持未勾选。

### 法律来源只读快照（6.2 首个入口）

`POST /api/sand-ai/v1/legal-sources/snapshots` 接受 `{ sources: [{ source_ref, source_version, file_id, file_sha256, parse_id, version_ref, source_block_ids }] }`。`sources` 为 1–10 项，每项引用 1–40 个来源块，整次至多 40 个来源块、64000 个 Unicode 字符；`source_ref`、`source_version`、`version_ref` 是 1–512 字符的不透明消费者引用。文件摘要为小写 SHA-256，ID 为正整数；未知字段、重复来源引用或重复来源块均拒绝。调用者须持有 `sand_ai.file.read`，每个文件都经 SandIAM 文件资源授权和当前身份的文件归属核对；文件必须仍为已解析状态，文件摘要、最新成功解析 ID、来源块归属及内容摘要必须与请求和存储相符。

响应按请求顺序返回同名 `sources` 列表；每项含固定 `source_ref,source_version,file_id,file_sha256,parse_id,version_ref`、`authority_state="consumer_declared"` 及 `blocks:[{source_block_id,content,content_sha256,locator_type,locator}]`。`source_ref` 与版本只代表消费者声明，SandAI 不把它们当作官方法源、效力或真实性核验结论。响应设置 `Cache-Control: no-store`，不返回对象 key、Bucket、存储地址或凭据。此入口只读取现有私有文件及解析块，不创建法律研究任务、不产生模型费用；法律研究、诉讼准备和裁判分析的模型执行与版本配置仍须另行接通。

法律分析运行输入冻结为四个字段：`legal_profile:{code,version}`、`legal_context:{issues,jurisdiction,case_type,as_of_date}`、`confirmed_facts:[{fact_ref,fact_version,summary,source_block_ids}]`、`legal_sources`（沿用上述来源快照请求的 `sources[]` 项）。三种服务端配置代码固定为 `legal_research`、`litigation_preparation`、`judgment_analysis`；调用者只选择版本，不传提示词或配置正文。`issues` 为 1–5 个不超过 500 字的待研究问题，法域与案件类型为不超过 128 字的消费者标签，`as_of_date` 为有效公历日期；已确认事实最多 30 项，必须引用本次法源块，且其确认责任仍在消费者。该输入与工作流的 `business_scope`、`files`、`output_schema` 一同冻结和授权；只读快照入口本身不提交分析。诉讼准备模式只产生提纲，正式文书正文须另有提纲确认版本，不由此模式直接生成。

法律分析通过现有 `POST /api/sand-ai/v1/structured-workflows/runs` 提交，`workflow={code:"legal_analysis",version:"1.0.0"}`，`output_schema={code:"sand_ai.legal_analysis",version:"1.0.0"}`；同时提交上述四字段、固定 `business_scope` 及覆盖全部法源文件的 `files[]`。`legal_sources[].file_sha256`、`version_ref` 和 `parse_id` 必须与当前文件、工作流冻结文件及最新成功解析一致。三种 profile 各有服务端版本 1.0.0，运行冻结完整配置；未声明人工复核时服务端强制 `review_required=true`，显式传 false 则拒绝。成果带 `source_versions:[{source_ref,source_version,authority_state,file_id,file_sha256,parse_id,version_ref,source_block_ids}]`、`fact_versions:[{fact_ref,fact_version,source_block_ids}]`、`findings:[{conclusion,supporting_source_block_ids,contrary_source_block_ids}]`、`risks:[{description,source_block_ids}]`、`unverified_items:[{description,source_block_ids}]`、`draft:{kind,content,source_block_ids}` 和 `review_required=true`。所有结论与风险至少引用一个本次实际模型输入来源块，草稿非空时也须引用；`draft.kind` 分别为 `research_memo`、`litigation_outline`、`judgment_analysis_memo`。来源路径、当前授权回看、真实调用用量与计价沿现有工作流结果读取；不把消费者声明的法源效力当作 SandAI 核定事实。

后台环境级法律分析配置使用 `/app/sand-ai/admin/setup/legal-analysis/profiles`。`GET /templates` 返回三个服务端默认模板，仅供创建草稿，不作为已发布配置；`GET /index?environment_id=` 返回该环境各版本摘要；`GET /read?environment_id=&code=&version=` 返回精确版本及非秘密配置。后台读权限为 `sand_ai:setup:legal-profile:read`，登录后仍通过 SandIAM 正式端口核验环境引用。

`POST /draft/save` 接收 `{environment_id,expected_revision,profile:{code,version,name,output_language,max_prompt_characters,max_output_characters}}`，新建的 `expected_revision=null`，编辑草稿必须提供当前正整数修订号；权限 `sand_ai:setup:legal-profile:save`。`draft_kind` 由三个固定模式在服务端决定，后台和应用调用者均不得提供提示词或更改诉讼提纲限制。`POST /publish` 接收 `{environment_id,code,version,revision}`，仅发布精确草稿修订；`POST /revoke` 接收 `{environment_id,code,version}`，只撤销已发布版本，分别要求 `sand_ai:setup:legal-profile:publish` 和 `sand_ai:setup:legal-profile:revoke`。已发布或已撤销版本不可原地编辑；更新时建立新语义版本。三类后台写操作记管理员与版本审计，不创建 SandIAM grant。

对外法律分析只读取已验证 SandIAM 环境内当前 `published` 配置，提交与授权后入队均锁定并比对版本快照；草稿、撤销或跨环境版本以 `SAND_AI_SCHEMA_UNSUPPORTED` 拒绝，不回退静态模板。Worker 与收据恢复使用冻结配置，并在送模前复核版本仍已发布。管理页面、真实数据库迁移及实际样本未验收前不宣称配置链完成。

法律来源的每个 `sources[]` 项和工作流 `legal_sources[]` 项可选传 `declared_effect_status`，取 `effective|amended|repealed|unknown`，缺省 `unknown`。该字段是消费者声明，随来源版本冻结；只读快照也回传它，不表示 SandAI 已核实法源效力。模型输入显式注明这一边界。法律分析成果的 `source_versions[]` 增加该字段，并由服务端确定性生成 `source_warnings:[{source_ref,source_version,declared_effect_status,warning_code,source_block_ids}]`：`amended` 对应 `declared_amendment_check_required`，`repealed` 对应 `declared_repealed_source`，`unknown` 对应 `effect_status_unverified`，`effective` 不产生该项。风险提示来源于冻结输入，不受模型是否提及影响；即使声明 `effective`，其 `authority_state` 仍为 `consumer_declared`，正式效力需业务方核验。

### 5.4 环境级材料整理配置目标契约（3.3，待实现）

材料后台配置响应沿宿主数值壳：仅 `MaterialProfileController` 捕获的 `SAND_AI_SCHEMA_UNSUPPORTED`／`SAND_AI_VALIDATION_ERROR` 明确输入错误使用 HTTP 200、业务 `code:400`，`message` 保留原错误码前缀和原说明。其它错误保持原路径，不泛化为输入错误。此处仅 `/app/sand-ai/admin/setup/material-analysis/profiles/*` 管理端响应，不改变公共 `/api/sand-ai/v1/*` 的实际 HTTP 状态和错误码契约。

`config/sand_ai_material_analysis.php` 保留为首次创建草稿的通用模板；权威源码中的 `MaterialAnalysisProfileRegistry` 已改为按 SandIAM 环境读取持久化的已发布配置，不再从该静态模板提供运行配置。后台列表、读取、草稿保存、样本证据发布、撤销和应用发起样本提交已有源码，但 041/042 迁移尚未执行，真实应用调用仍待验收，不能把编辑界面当作服务已开通。

- 后台 `GET /app/sand-ai/admin/setup/material-analysis/profiles/index` 以 `environment_id` 查询当前环境的 code、version、修订号、状态和中文名称；`GET .../profiles/read` 以 `environment_id`、`code`、`version` 读取完整非秘密配置。两处环境引用均由 SandIAM 正式端口验证，不通过读取 `sand_iam_*` 表猜测归属。
- 后台 `GET .../profiles/samples` 以 `environment_id`、`code`、`version`、正整数 `revision` 查询该环境精确草稿修订最近 20 条已完成样本候选，仅返回 `sample_run_id` 和 `completed_at`。候选至少有成功任务、成果、终端调用/派发和计量事件，且服务端样本标记包含同一 profile ID、修订号和配置摘要；列表不构成发布批准，`publish` 必须在事务内重新核验全部证据。向导和专业模块共用该列表，不在浏览器保存样本运行记录。
- 后台 `POST .../profiles/draft/save` 的请求体为 `{ environment_id, expected_revision: int|null, profile: { code, version, name, output_language, document_classifications, entity_types, extension_fields, max_prompt_characters, max_output_characters, review_output_strategy? } }`。新建须传 `expected_revision=null`；修改已有草稿须传当前修订号，冲突以 `SAND_AI_RESOURCE_CONFLICT` 拒绝。配置字段约束沿 `MaterialAnalysisProfilePolicy`；`name` 只用于后台展示，不进入提示词或输出字段代码。首次创建可从通用静态模板预填，但必须明确保存草稿。保存同一草稿时增加修订号并清除旧样本证据；`published` 和 `revoked` 版本均不可原地改写，变更须建立新语义版本。
- 材料配置新增可选 `review_output_strategy`，保存为该 profile 的非秘密配置：只接受 `neuron_json_extract_v1`、`neuron_zai_json_object_v1`、`neuron_openai_json_schema_v1`；显式 null、非字符串或未知值以 `SAND_AI_SCHEMA_UNSUPPORTED` 拒绝。历史配置缺字段时读取保持缺省，不补写配置或重算其摘要。新受理的多文件材料运行缺省采用 `neuron_json_extract_v1`；另两策略须管理员显式选择模型所支持的协议，不能根据厂商、endpoint 或模型名称推断。实际适配器不支持所选协议时在派发前以 `SAND_AI_CAPABILITY_UNSUPPORTED` 拒绝，不降级、不自动重试。单文件材料与其他业务的输出协议不因此改变。此处为新增冻结契约，实现、加载及真实业务验收分别记录。
- 材料配置的枚举项须为有效 UTF-8：`document_classifications[]` 每项为 1–2000 个 Unicode 字符，`entity_types[]` 每项为 1–64 个 Unicode 字符，按 Unicode 码点计数，不按字节或界面字形计数；均不接受控制字符（`Cc`）、换行（包括 `Zl`／`Zp`）或首尾空白。`extension_fields[]` 沿用既有最多 64 字符的小写代码规则；`version` 保持三个数字段且最多 32 个字符。保存、精确草稿样本、发布及正式运行共用这些约束，违反时沿用 `SAND_AI_SCHEMA_UNSUPPORTED`，错误不回显枚举值。
- 已保存的非法旧配置不自动截断、拆分、去空白或改写；编辑器保留 API 原始枚举项，先按原项校验并禁用样本与发布，只有管理员明确编辑相应字段后才构造新的逐行列表，仍须显式保存。草稿修正后须重新验证样本；已发布或已撤销版本须复制为新版本后修正、试用并显式发布。非法旧配置及其冻结队列在模型派发前拒绝，不改写历史快照或已发生费用。
- 管理端“复制为新版本”保留当前配置全部字段并预填未占用的下一补丁版本号，由管理员核对后显式保存；复用既有草稿保存接口并传 `expected_revision=null`，不复制样本记录或发布状态。材料分类和实体类型可直接填写中文，扩展字段仍使用原小写代码规则。专业模块与首次开通共用该编辑器。样本页面的材料准备复用已配置的 SandIAM 运行身份和文件 API：显式上传、提交解析、读取异步进度后，只有已解析且有来源块的文件可加入样本；解析响应不明时保留同一 `X-Request-Id` 供管理员确认，上传响应不明不自动再次上传。页面身份／环境切换清空材料准备内容，不新增 Bearer 缓存。真实样本成功且完成计量后，仍须由管理员选定成功样本并显式发布。
- 应用以 SandIAM 工作负载 context 和 `sand_ai.workflow.run` grant 调用 `POST /api/sand-ai/v1/material-analysis/samples`。请求体为普通结构化工作流的材料分析请求，加上正整数 `profile_revision`；`analysis_profile` 只含 `code`、`version`，`workflow.code` 必须为 `material_analysis`。必须提供 `Idempotency-Key`；响应与 `structured-workflows/runs` 提交一致，仍通过该运行读取入口查询状态。环境、组织、应用、客户端及计量归属来自已验证 context，不接受请求自报；样本须引用同环境的精确草稿修订和已授权文件快照。服务端在输入与执行快照中固定内部 `material_profile_sample` 标记，包含 profile ID、修订号及配置摘要；两个提交入口都拒绝调用者自报该标记。提交、SandIAM 授权后入队和 Worker 调用模型前复核草稿状态、修订及配置摘要。发布证据核验要求同一修订的配置快照、成功任务与成果、同一调用的不可变计量事件，以及环境、组织、应用、客户端归属一致。试用失败或仅静态校验通过均不能标记为可发布。
- 样本提交响应不明或页面刷新后，调用方可用原工作负载 Bearer 请求 `GET /api/sand-ai/v1/material-analysis/samples/by-request-key?request_key=<原 Idempotency-Key>`。入口要求 `sand_ai.workflow.read`，以当前环境和 SandIAM 应用归属查找材料样本运行，再对该运行资源执行读取授权，返回与 `structured-workflows/runs/{runId}` 相同的只读结果；不存在或不是材料样本时返回 `SAND_AI_RESOURCE_NOT_FOUND`。查询不创建任务、不派发模型。浏览器会话只保存请求键；找不到原运行时须重新填写原样本输入并沿原键重试，不得把授权或网络错误当作未落库。
- 后台 `POST .../profiles/publish` 以 `environment_id`、`code`、`version`、正整数 `revision` 和正整数 `sample_run_id` 发布同一草稿。服务端锁定精确草稿及样本任务、运行和成果，复核终端 Invocation 与不可变 Dispatch 属于同一任务和执行快照，再从不可变计量事件核对同一调用、模型部署及环境、组织、应用、客户端归属。历史整批运行的终端调用沿用父运行 `structured_{logical_execution_id}`；`single_file_v1` 单文件运行的终端调用为该封存文件的尝试调用，多文件运行的终端调用为跨文件核对尝试调用。新模式还须逐项复核全部冻结文件的封存结果、尝试身份、调用、派发、计量及当前来源；多文件须核对跨文件结果与终端调用。失败、未完成、缺少计量事件或修订不符都拒绝。042 迁移保存样本运行、成果、计量引用和发布时间；`POST .../profiles/revoke` 撤销精确发布版本并保留样本引用、记录撤销时间。状态变化留操作人、时间及审计；不得自动创建 SandIAM grant。向导与专业模块调用同一组接口和记录，不复制配置。
- 普通结构化材料运行提交响应丢失时，消费应用可携原 SandIAM 工作负载 Bearer 请求 `GET /api/sand-ai/v1/structured-workflows/runs/by-request-key?request_key=<原 Idempotency-Key>`。该只读入口要求 `sand_ai.workflow.read`，只在当前环境与应用归属中查找非样本运行，随后对原运行执行当前资源授权并返回与按运行 ID 读取相同的投影；不存在、归属不符或样本运行均返回资源不存在。它不创建任务、不重发模型、不代替原提交的幂等输入校验。SandAI／消费应用的业务适配层可将明确业务尝试映射到稳定提交键，响应不明时先用该入口找回；它不得凭 `review_required` 自动创建审批或改变业务状态。

#### SandAI 消费现有 SandWorkflow 契约（统一产品 6.1，业务联调待完成）

- 2026-09-28 已确认：针对当前已明确需求，SandWorkflow 现有能力满足要求，无需改造；接入工作归 SandAI／消费应用侧。当前适配器没有生产业务 caller，具体定义、发起时点与参与人须由真实业务确认，不虚构 HTTP 入口或审批动作，不以此阻塞独立材料功能。
- AI 提交仍使用 `POST /api/sand-ai/v1/structured-workflows/runs` 和 `sand_ai.workflow.run`，响应不明使用原请求键 `GET .../runs/by-request-key` 找回，运行及成果读取要求当前 `sand_ai.workflow.read` 资源授权。禁止为网络或授权不明换键重发；来源与费用沿已冻结 AI 运行，不复制新的执行账本到 Workflow。
- 本轮撤回 `service_handoff` 请求字段、专用通知和 Workflow 机器启动／SERVICE 节点协议；新请求携带该字段以 `SAND_AI_VALIDATION_ERROR` 拒绝。没有 SandWorkflow 时材料服务正常运行。AI 运行的单调 `state_version` 与事务内状态修订继续保留供轮询；终态一致性继续校验，不创建终态通知 outbox。
- 同宿主可信调用方显式使用 `app\Application\Workflow\SandWorkflowConsumer::start(int definitionId, string name, array formValue, WorkflowStartContext context)`，适配器直接调用已发布插件的 `WorkflowRuntimeService::start()`。发起人、参与人、Workflow 业务范围及请求键须来自已核验业务上下文；稳定请求键非空且最长64字节，不跨业务复用。缺少可选 Workflow 时返回 `SAND_AI_WORKFLOW_UNSUPPORTED`。未增加 HTTP 路由，不能把这个进程内入口直接暴露给任意请求。
- 查询使用 `detail(string instanceId, ActorRef actor, array scope)`，scope严格包含 `tenant_id,owner_scope,business_type,business_id` 四个非空字符串，由原插件继续校验参与人及范围；不启用 monitoring、不提供 approve。调用方维护 SandIAM 归属到 Workflow 业务范围的可信映射并核验业务权限，禁止自报或默认映射身份。
- `review_required` 不触发任何 Workflow 调用或审批。分析成功也不自动启动业务流程；业务决定与实际参与人操作仍属于消费者。现有启动接口按发起人和请求键查重，没有证据证明同键异输入强制冲突或跨系统原子事务。流程图内部自动等待 AI 与跨宿主调用未由此适配器解决，且尚未成为已明确需求；若后续真实需求确需提供方变更，先向用户描述现有接口失败证据及替代方案，待用户审阅决定。6.1 保持未完成真实业务集成验收。

- 对外 `structured-workflows/runs` 保持现有 `analysis_profile: { code, version }` 入参，不接受请求自带 profile 定义。服务端依据已验证 SandIAM context 的 `environment_id` 只读取该环境当前 `published` 版本，提交时冻结完整配置、输出 Schema 标识、业务与文件快照；提交事务与 SandIAM 授权后的入队事务均锁定并复核该已发布版本与冻结配置一致，撤销或改动后拒绝入队。Worker、重试和读取使用该运行快照并重新核验授权。缺失、未发布、已撤销或跨环境版本以稳定的 `SAND_AI_SCHEMA_UNSUPPORTED` 拒绝，不回退宿主静态模板。
- 运行读取投影增加 `analysis_profile`：`null`，或 `{code:string,version:string,origin:"published",revision:null}`，或 `{code:string,version:string,origin:"draft_sample",revision:positive_integer}`。身份只能从服务端原冻结输入与执行快照的闭合校验派生，白名单投影不返回完整profile配置。published运行使用原固定code／version，历史未冻结revision时不得补造修订号；draft_sample须核原样本标记、正整数revision与配置摘要一致。缺少可信冻结身份或内容已清理时返回null，不妨碍当前四级授权下的独立任务读取。材料当前配置页恢复必须拒绝null、来源状态不同或code／version不匹配；draft还须精确匹配当前revision。published响应丢失仍以原请求键GET找回，不新派发；URL中的run ID不证明原键恢复。该响应契约已冻结，源码与真实恢复验收分别记录。
- 新的材料整理提交（包括样本）必须有逐文件结果结构；多文件还必须有跨文件核对结构。数据库尚未安装对应迁移时，以 `SAND_AI_SCHEMA_UNSUPPORTED` 拒绝提交，不建立旧整批运行或静默省略跨文件核对。已持久化的旧整批运行保留其冻结执行方式供读取和恢复。

配置表名固定为 `sand_ai_material_analysis_profile`，主键为 `id`，`environment_id` 是环境边界，`(environment_id, code, version)` 唯一。应用发起样本使用 SandIAM `sand_ai.workflow.run`，不设后台样本权限；后台读取、草稿保存、发布、撤销权限依次为 `sand_ai:setup:material-profile:read`、`:save`、`:publish`、`:revoke`，路由须同时经过 `CheckLogin` 与 `CheckAuth`，并复用 SandIAM 环境引用核验，不建立平行应用或授权表。

### 5.5 真实平台会话只读恢复

渠道会话 history、messages/by-request-key、messages/{taskId}/sources/{sourceBlockId}、messages/{taskId}/events 的 query 可带 `delivery_platform=feishu|wecom`；conversations/{conversationId}/close 的正文可带相同可选字段。省略保持原应用试聊作用域。服务端先沿当前 SandIAM 上下文核验启用绑定和发布 Agent，再沿正式平台凭据配置读取口确认当前 code 的平台及托管环境/绑定归属；不接受其他值，不新建 grant，不返回凭据。平台参数参与与已验签回调完全相同的会话/消息 hash，并核对原任务冻结的 delivery_platform；历史、来源、费用、事件仍各自当前授权，不能借另一平台或应用试聊身份读原任务。

真实平台 `messages/by-request-key` 必须使用原平台消息 ID 同时作为 message_id 与 Idempotency-Key，携原 conversation_id、sender_id。只读缺失返回原 RESOURCE_NOT_FOUND，不推断尚未发送，不转 POST，不重新生成回复；旧未冻结 delivery_platform 的记录不自动改作平台记录。事件断线续读保留原 taskId/event cursor，不派模型。平台恢复页面仅提供历史、原消息、来源、费用、已允许的原节点续跑和明确关闭，不开放 submitMessage；人工再次发送须回飞书或企业微信客户端产生真实新消息，复用当前已发布 Agent 并按新消息独立计量。回信 uncertain 保持原 outbox 待核对，不因读取 task 自动再次外发。

### 5.5 材料成果的来源标记（3.4 部分）

`GET /api/sand-ai/v1/structured-workflows/runs/{runId}/sources/{sourceBlockId}` 用于从已返回的材料成果回看单个来源块。它要求与运行详情相同的 `sand_ai.workflow.read` 当前 SandIAM 授权；服务端重新核对运行、固定文件、当前文件归属与解析、来源摘要，并且只允许读取该运行最终成果或已封存逐文件成果实际引用的块。响应仅含 `source_block_id,file_id,locator_type,locator,content,field_paths`，设置 `Cache-Control: no-store`；不返回对象键、Bucket、存储地址或其他文件内容。来源失效或块未被成果引用时拒绝，不把任意来源块 ID 当作读取凭证。

材料成果中的候选值只能引用当前运行固定文件快照内、且实际进入模型提示词的来源块。`documents[].classification` 和 `documents[].title` 只能引用该文档 `file_id` 对应文件的来源块，不能借用同次运行中另一文件的来源。`confirmed` 至少引用两个不同来源块，`uncertain` 恰好引用一个；`items_to_verify.reason=single_source` 也必须恰好引用一个，`conflict` 至少引用两个。`missing_source` 不得保留无来源断言，摘要为 `null` 且来源列表为空。字段来源映射继续随成果保存；来源数、文件归属与标记矛盾时以 `SAND_AI_ARTIFACT_PROVENANCE_INVALID` 拒绝整个成果，不把它伪装成成功输出。

文档标题或分类在原文中缺失时，对应候选固定为 `{ value: null, source_block_ids: [], evidence_state: "missing_source" }`，不得猜测名称或分类，也不为该空值生成字段来源。其余非空候选仍遵守上述引用规则。即使整份已解析文件没有可支持的结论，也可封存含缺失字段的结果并保留实际调用与计量证据；`citations` 可以为空。缺失字段、非空的 `conflicts` 或 `items_to_verify` 均使成果 `review_required=true`，包括升级后恢复的旧整批运行。多文件核对仍运行并计量；如果不足两个文件拥有被引用的来源，核对只能返回空的 `items_to_verify`，不得凭没有来源的字段产生跨文件冲突。

`GET /api/sand-ai/v1/structured-workflows/runs/{runId}` 及提交后的运行摘要增加 `file_states`，每项为 `{ file_id, analysis_state, attempt_count, error_code|null }`；仅当前失败文件具有完整、已持久化的模型输出拒绝证据时，额外返回 `failure_stage: "model_output_validation"` 与受控 `failure_summary`。此证明须匹配当前 run/file/task/attempt/执行快照、成功 dispatch/invocation、加密 receipt 与不可变 usage；它明确模型已返回并产生用量，但成果格式或来源校验未通过。内部和对外历史 `error_code` 保持原值，不回填历史、不输出拒绝正文或具体值；没有证明的配置/数据库不支持错误、旧整批状态和其他阶段不增加该标记。前端应依据 `failure_stage` 解释模型输出失败，不能仅以 `SAND_AI_SCHEMA_UNSUPPORTED` 断言模型失败。状态可为 `pending`、`running`、`retrying`、`recovery_pending`、`succeeded`、`failed`、`canceled`；错误码只返回受控 `SAND_AI_*` 值，不能返回文件正文或 Provider 消息。旧整批模式的文件状态随同一任务转换，不表示逐文件部分成功；新运行只有在所需结果表和收据约束实际就绪时才冻结 `single_file_v1`。整批成果成功时 `documents` 必须至少包含每个固定 `file_id` 的一个条目；一个文件可对应多个条目，缺失文件以 `SAND_AI_ARTIFACT_PROVENANCE_INVALID` 拒绝整批成功。`single_file_v1` 的成功成果、定点重试与费用按下文独立契约处理。

逐文件成功证据的内部表固定为 `sand_ai_workflow_file_result`（单个 `workflow_run_file_id` 只允许一份成功结果，`invocation_id` 唯一）与 `sand_ai_workflow_file_result_source`（字段路径和实际来源块）。记录必须绑定原运行的执行快照和实际调用。冻结 `single_file_v1` 的运行经 SandIAM `sand_ai.workflow.read` 当前授权后，`GET /api/sand-ai/v1/structured-workflows/runs/{runId}` 增加 `file_results`：按固定文件顺序返回已封存成功文件的 `{ file_id, attempt_count, result: { schema: { code, version }, payload }, citations: [{ field_path, source_block_id, file_id, locator_type, locator }] }`；未完成文件不出现，仍由 `file_states` 展示。读取必须复核固定文件的成功状态、尝试号、调用、运行快照及每个来源块的文件/parse/当前访问归属；证据不一致或来源已不可读时整体拒绝，不返回部分泄漏。提交响应、旧整批运行不增加该字段；不返回内部结果 ID、对象 key、原始来源正文、厂商原文或凭据。定点重试输入见本节末的 `target` 契约。
单文件结果写入成功时，该固定文件的 `analysis_state=succeeded` 和 `last_invocation_id` 与结果、字段来源同事务提交。父任务后续重试、失败或取消只转换未完成文件；已有结果必须与文件的成功状态、调用 ID 和尝试号一致，否则拒绝状态转换。
逐文件正文完整性采用内部 `payload_digest_version`，由 SandAI 的结果来源域持有，不进入对外字段。新增迁移 `202610010097_material_file_payload_digest.pgsql` 为 `sand_ai_workflow_file_result` 增加 nullable smallint，只允许 NULL／2，不设默认、不回填旧记录、不改历史 043／071。新写入标记 2，递归按字符串顺序排序对象／map 的键，保持 list 顺序和空 extensions 对象，沿用原 JSON 编码 flags 后计算 SHA-256。保存幂等先验证既有正文，再比较同一规范化语义、调用、尝试和来源集合。跨文件提示词、最终汇合、受授权读取及配置样本采用都必须先校验正文；清理正文、未知版本或摘要不符以 `SAND_AI_ARTIFACT_PROVENANCE_INVALID` 失败关闭，不发送正文给 Provider。

NULL 旧证据仅按当前持久化可观察顺序和原 JSON flags 复算一次（extensions 依固定 Schema 保持对象）；匹配原摘要才允许消费。jsonb 丢失原键顺序时不能排列猜测、按 Schema 拼回旧顺序、改写摘要或自动重跑。无法证明完整性的旧成果、来源和已发生费用仍保留；消费被拒绝。新的提交与已有逐文件队列执行在 Provider 前核对摘要版本列的类型、可空、无默认和 NULL／2 已验证约束；迁移缺失以 `SAND_AI_SCHEMA_UNSUPPORTED` 拒绝。

逐文件执行遇到派发前的单文件输入超限，或派发账本已确认 `failed` 的服务商明确拒绝时，继续处理其余固定文件。处理完后父任务以 `SAND_AI_MATERIAL_FILES_INCOMPLETE` 失败，已封存成果仍可读，未封存文件进入 `failed_files` 定点重试；不执行跨文件核对或最终合并。其他异常、结果不明的派发和已成功但尚未封存的调用仍按原恢复栅栏停止，不能借此继续派发。
可独立继续的失败须在继续下一个文件前持久化该文件的 `failed` 状态及原受控错误码。父任务自动重试或回执恢复保留其尝试号和错误，不重新执行该已明确失败文件；恢复同批其他成功回执后，父任务仍以 `SAND_AI_MATERIAL_FILES_INCOMPLETE` 失败，等待调用方显式重新入队。取消仍将未封存文件改为 `canceled`，已有成功成果继续保留；失败落库与取消、租约接管共用任务行锁及租约栅栏。显式 `failed_files` 重试重新取得当前授权，只将未封存失败文件入队，成功文件与原费用不重算。
同一事务还以 `file_result:{result_id}` 确认该 invocation 的加密结果收据；收据清理需再次核对不可变单文件结果、固定文件的成功状态与尝试号、调用和运行快照。整批成果原有的 `artifact:{artifact_id}` 确认与清理条件继续适用。该内部收据引用不进入对外响应。
内部单文件调用的幂等请求号为 `structured_{parent_logical_execution_id}_file_{workflow_run_file_id}_attempt_{attempt_no}`；派发账本使用由这三项稳定派生的独立 48 位逻辑执行 ID，并写入真实尝试号。派发前须重新核对固定文件仍属本运行且处于该尝试的 `running` 状态；保存成功结果前须核对该调用、成功派发及同一调用的不可变用量事件。整批旧调用继续使用父逻辑执行 ID 和尝试号 1。当前对外请求体和响应不因内部请求号而改变。
父任务的派发恢复检查只排除已有不可变单文件成功结果、固定文件成功状态、同一调用和尝试号共同证明已完成的派发；任何缺少该证据的服务商可见派发仍阻止自动重发。旧整批运行沿原派发恢复规则。
冻结为 `single_file_v1` 的任务若全部固定文件状态已为 `succeeded`，但最终成果尚未提交，异常或过期租约回收须先判定下一步：单文件运行或多文件跨文件核对结果已封存，进入不消耗服务商重试次数的本地最终提交恢复；多文件核对尚未完成时，按其独立派发、收据和重试状态恢复核对，不得把文件全成功误当成“无需再调用 Provider”。纯本地恢复重新核验原 SandIAM 授权、结果和来源，再由有效恢复租约提交最终成果，不再次派发模型。恢复队列的有效租约不得被另一 Worker 抢占，过期后方可重新领取；恢复中取消须递增租约栅栏并阻止最终成果提交，已成功文件的不可变证据仍保留。源码已按核对状态分流恢复；真实 PostgreSQL、Worker、SandIAM 与 HTTP 时序仍需验收。

新受理的材料及跨文件核对调用在原 Invocation 中固定真实 `execution_attempt_no`，与原 Dispatch 的 `attempt_no` 一致；尝试号来自可信内部执行上下文，调用者不得自报。结构化私有加密回执另封存原服务商返回的 Token 测量状态、可信四级归属及服务商／模型／部署快照，不将这些内部事实加入对外 DTO。服务商已返回、回执已持久而后续计量事务失败时，原调用保持 `running`、原派发保持 `dispatching`；任务异常或租约过期后，只进入已有 `recovery_pending` 本地恢复，不自动重派发。恢复先重验当前 SandIAM 授权、私有来源和原执行快照，再核对当前恢复 owner／token／fence／未过期租约、原节点／文件／尝试／请求号、归属及冻结路由／价格；通过后同事务恢复同一原调用的唯一不可变用量事件、兼容用量、全部预算成员结算与原调用／派发成功状态，再由原来源和成果校验封存结果。恢复不调用服务商、不读取后来发布的价格或推算未知 Token；未测量、未定价及未知币种继续为未知，不能写成零。旧已成功的正文回执继续可读；旧未完成的正文回执、已封存 `ambiguous`／`failed`、缺失／过期／损坏回执及归属不一致均拒绝自动恢复并留待复核，不改写旧终态。计量恢复后若成果校验失败，已发生费用仍保留。该路径覆盖新单文件、跨文件和原整批结构化材料／法律分析，真实 PostgreSQL、Provider、HTTP 及费用幂等验收仍待完成。

冻结为 `single_file_v1` 的运行摘要，其 `usage` 和 `cost` 必须覆盖该运行所有固定文件的实际调用尝试，包括失败后重试已有的用量事件；不以父运行旧整批请求号的一条事件代替总数。同一调用只计一次，未派发且没有事件的尝试不伪造费用；服务商可见派发缺事件时，汇总状态为 `unavailable` 且总 Token 与总金额为 `null`。任一事件未测量时，`usage.state=unmeasured` 且三个 Token 为 `null`；完整测量才汇总整数 Token。`cost.by_currency` 按币种列出已知且已定价事件的字符串金额，不跨币种相加；只有全部应计事件已定价且仅一个币种时 `cost.state=priced` 并给出 `metered_amount` 和 `currency`，全部已定价但有多个币种时 `cost.state=mixed_currency` 且总金额与币种为 `null`，事件缺失或未定价时 `cost.state=unavailable` 且总金额为 `null`。`usage` 增加 `attempt_count,event_count,missing_event_count,unmeasured_count`，`cost` 增加 `unpriced_count,by_currency`，这些计数用于说明汇总完整性；读取响应中原有 `usage_cost` 对逐文件运行也使用同一汇总对象并带 `aggregation=single_file_v1`，不再声称只有一个 `invocation_id`。后台逐调用用量事件仍是单次金额的权威明细。旧整批运行的响应口径不变。

多文件的 `single_file_v1` 运行在全部固定文件结果封存后、最终成果成功前，还必须执行一次跨文件核对；单文件运行不需要该步骤。核对输入仅包括当前运行已封存的候选成果和它们实际引用、当前仍可读取的来源块；来源块的 `file_id`、`parse_id`、内容摘要和调用方归属必须重新验证。候选或来源超出冻结提示词上限时明确保持未完成，不截断后宣称完成核对。核对模型只返回 `{ items_to_verify }`；每项为 `{ reason: conflict|ambiguous, field, summary, source_block_ids, affected_candidates }`，其中 `affected_candidates` 至少列出两个不同固定文件各自的 `{ workflow_run_file_id, field_path }`。所有字段路径必须指向实际已封存的候选值，引用块必须属于这些候选且实际进入核对提示词，并跨两个文件。验证器从 `reason=conflict` 的项生成最终 `conflicts`，确保每条冲突必有对应待核实项；不同值若无法证明属于同一事实，只能标为 `ambiguous`。核对不会改写不可变单文件结果，但最终合并成果必须暂不纳入所有受影响候选及其原字段来源，将其证据转入带来源的待核实项；事件候选以整个事件项为排除单位，包含 `summary`、`occurred_on` 和两者的来源，不能只删摘要而留下未复核日期。未受影响候选可继续保留，含待核实项的最终成果须标记 `review_required=true`。缺核对证据时父运行不得成功；已封存单文件结果仍按授权可读，且其中的 `confirmed` 只表明该文件内部来源充足，不能替代跨文件结论。

新受理的 `material_analysis` 多文件运行在执行快照中冻结内部 `material_review_output_contract: { version: "material_review_output_contract@2", strategy, schema_sha256, schema }` 并纳入原执行摘要；调用方不得自报该内部标记。`schema` 由 Neuron AI 4 官方 `JsonSchema::generate(MaterialReviewResponseSchema)` 生成，覆盖完整 `{ items_to_verify }`、`reason: conflict|ambiguous`、精确 item／candidate 字段及整数来源 ID，`schema_sha256` 绑定该冻结 Schema。默认 `neuron_json_extract_v1` 在原消息后追加包含完整官方 Schema 的 system instruction，并使用官方 JsonExtractor 严格校验完整 JSON；显式 JSON 对象／JSON Schema 策略复用官方 ZAI／OpenAI 结构化输出适配及原受控一次发送路径。业务层继续校验整数类型、当前来源、冻结候选路径及跨文件归属，不因结构化协议放松这些要求。历史运行缺此标记时只沿原消息与请求摘要路径，不修改旧 snapshot、prompt、provider body、receipt 或费用；原回执恢复不得套用新策略。当前仅冻结契约，未声明这些新增路径已经加载或通过真实复核。

核对拥有独立的 `pending|running|recovery_pending|succeeded|failed|ambiguous|canceled` 状态及尝试号。受 `sand_ai.workflow.read` 当前授权保护的多文件运行读取返回 `cross_file_review: { state, attempt_count, error_code }`；错误码只允许受控 `SAND_AI_*` 值，非失败状态为 `null`，不把核对失败映射为某个已成功文件失败。`POST /api/sand-ai/v1/tasks/{taskId}/retry` 可提交 `{ "target": "failed_files" }` 或 `{ "target": "cross_file_review" }`：前者要求父任务失败且至少一个未封存固定文件为失败状态，只重新执行失败文件；后者要求父任务失败、全部固定文件成果已封存且核对仍待派发或已经失败，只建立新核对尝试。两个目标均须重新取得任务重试及工作流运行的 SandIAM 当前授权，并通过派发重发栅栏；不确定的服务商派发不能重发。显式目标在父任务自动尝试次数耗尽时仅增加一次可领取次数，保留历史尝试次数、已封存成果和费用；再次失败须再次显式请求。省略 `target` 的既有重试规则不变，其他目标值拒绝。核对是独立的受控 Provider 调用，具有自己的稳定请求/派发身份、终端结果、不可变用量事件和恢复收据；父运行费用汇总计入核对调用及其失败尝试，不重复计费，也不伪造 `file_id`。已确定失败的核对派发允许新尝试；调用成功但结果落库失败时只恢复原收据，服务商可见而结果不确定时禁止自动重发。以上为源码契约，真实 PostgreSQL/HTTP、并发取消及当前 `sandai` 上的安装验收仍单列。

#### 跨文件复核的显式回执恢复

既有 `POST /api/sand-ai/v1/tasks/{taskId}/retry` 新增可选 `mode: "receipt_only"`，仅允许与 `target: "cross_file_review"` 组合；未知 mode／不合法组合拒绝。省略 mode 保留原 `failed` 任务重新派发的重试语义。回执恢复不创建新的运行或模型请求，返回现有 TaskSummary；恢复完成与恢复受理须按真实状态区分。

`receipt_only` 仅用于同归属 `structured_workflow`／`material_analysis`／`single_file_v1` 的 `recovery_pending` 父任务，且至少两文件均已有有效封存成功成果。准入保留两种精确本地情形：①复核尚待恢复，存在匹配同 run／task／执行快照／复核尝试的成功 paid invocation／dispatch、可用且未过期的原加密回执及实际不可变 usage；②复核已 succeeded 且唯一封存复核结果与原执行快照、冻结输入摘要、已计费成功 invocation／dispatch、已确认消费到该唯一封存复核成果且未过期可解密的原回执、实际 usage 完整匹配，父任务的最终 artifact 尚未封存。第二种情形仅用已有封存复核成果完成父任务封口，不重做复核或发送模型。两种情形均须保持原文件／来源／固定配置／执行快照完整，并正式重验当前可信身份及来源。动作复用正式 `sand_ai.task.retry` 任务资源授权及四级身份，不接受调用者自报事实；正常新上下文须与原任务同一可信工作负载身份，更新排队授权，不能修改原 prompt、运行身份、成果或回执。

恢复按原租约 fence 与 CAS 精确取得该 task，要求 `local_recovery_count < local_recovery_max`，不重置计数；复用 LocalOutcomeRecoveryService 的 exact-task 回执恢复路径。该模式只允许恢复原回执并持久化已有输出，禁止回到普通 resume／排队任何后续模型或新的 Provider 发送。已确定的业务输出拒绝保留稳定受控错误并终结为 failed；未知结果或持久化失败保留 recovery_pending，不把它当作可重复派发。计数用尽、状态不符、回执不可用或恢复结果未知时只读取原任务，不自动重复 POST。当前为冻结目标契约；入口源码、实际加载和零 Provider 的恢复验收分别留证，不据此执行历史 task52。

TaskSummary 可选增加 `receipt_only_recovery?: { target: "cross_file_review", available: boolean, attempt_count: number, max_attempts: number }`。仅任务属于 `structured_workflow`／`material_analysis`／`single_file_v1`、状态为 `recovery_pending` 且 run／task／environment 关联相符时投影；离开该状态不返回此字段。`attempt_count`／`max_attempts` 只取该任务真实 `local_recovery_count`／`local_recovery_max`，不使用模型尝试号、不重置机会。

`available` 仅在上述关联及至少两文件完整封存成功、真实计数小于上限、任务无活动租约（或租约已到期），且上述两种情形之一的实际证明闭合时为true：复核pending分支须有匹配成功 paid dispatch、可用且未消费未过期的原receipt及usage；复核succeeded分支须有唯一封存复核结果、原冻结摘要及成功paid dispatch／已确认消费到同一封存复核成果且未过期可解密的原receipt／usage匹配，且父最终artifact尚未封存。两分支均核当前身份与完整来源事实；任一条件不满足为false。投影不暴露回执ID、私有marker或密钥，也不是对当前调用方已获 `sand_ai.task.retry` 的承诺；实际POST仍核新context的可信四级身份、当前IAM授权及来源。前端仅在真实可用时提供显式本地恢复，恢复后可能成功或得到稳定业务输出拒绝，绝无新Provider；机会耗尽、租约中或回执不可用只读状态，不伪造可用机会。


### 5.6 音频转写运行与结果读取（4.5 部分）

后台 `GET /app/sand-ai/admin/setup/audio-transcription/metadata` 是只读配置预检：`runtime_state=route_available` 只表示统一安装已交付下述应用接口，不表示已发布环境能力路由或完成真实录音。`adapter_kinds[].runtime_state` 为 `configured|not_configured`；本地状态核对宿主配置的可执行文件、可读模型文件和超时时间，外部状态核对总开关与至少一个已注册外部驱动的显式启用配置；GLM 还须有可执行的可信 FFprobe，且 `provider_idempotency_mode=none`、`synchronous_only=true`。响应仅显示状态，不回传二进制路径、模型路径或服务商连接信息；配置通过后仍需能力路由、SandIAM grant 和真实样本验证。

对外入口由已安装的 `app/Api/Controller/AudioTaskController` 实现：`POST /api/sand-ai/v1/audio-transcriptions` 使用 SandIAM `sand_ai.audio.transcription` 工作负载授权和必填 `Idempotency-Key`，请求体为 `{ schema: { code: "sand_ai.audio_transcription_request", version: "1.0.0" }, file_ref, language, speaker_diarization, glossary, data_egress_policy }`。`file_ref` 是同环境私有文件的不透明引用；服务端按已发布能力路由选择本地或外部驱动，不接受调用方指定服务商密钥或驱动。本地 whisper.cpp 将 BCP 47 地区/文字变体语言代码映射为识别器的主语言代码，并将非空 `glossary` 作为初始提示词传入；词汇是识别提示，不保证每项都被识别。本地路由收到 `speaker_diarization=true` 时在提交阶段返回 `SAND_AI_AUDIO_DIARIZATION_UNAVAILABLE`，不创建必然失败的任务。`openai_compatible_audio` 分段转写只向服务商发送两字母 ISO 639-1 主语言代码，例如 `zh-CN` 发送为 `zh`；三字母主语言代码在提交时返回 `SAND_AI_AUDIO_TRANSCRIPTION_VALIDATION_ERROR`，调用者可改用 `auto`。外部 `gpt-4o-transcribe-diarize` 请求固定设置 `chunking_strategy=auto` 以支持长录音；该模型不支持 `prompt`，因此外部说话人区分与非空 `glossary` 组合在提交时返回同一验证错误，不派发服务商。该分段驱动的已发布模型与请求模式不一致（说话人区分需 `gpt-4o-transcribe-diarize`，普通分段转写需 `whisper-1`）时在提交阶段返回 `SAND_AI_AUDIO_NOT_CONFIGURED`，不创建任务。响应是 `{ idempotent_replay, task }`，后续由 `GET /api/sand-ai/v1/tasks/{taskId}` 读取，不另建后台对外服务接口。

本地 `local_whisper_cpp` 兼容 `local_only|private_only|approved_cloud`，音频 metadata 的 `supported_data_egress_policies` 显示这三个值。它表示本地处理兼容调用者许可，不表示云外发；转写任务保留原请求策略，不能因实际驱动为本地而强制改成 `local_only`。外部驱动仍只接受 `approved_cloud`。正式任务按真实容器时长计量，缺价格保持 `unpriced`、金额 `null`。

`glm_asr_audio` 是独立注册的可配置外部 ASR 驱动，复用现有 `openai_compatible` 连接、已发布 `audio_transcription` 部署、`approved_cloud` 能力路由及 SandIAM 工作负载授权，远端模型固定为 `glm-asr-2512`。它仅接受同环境私有 WAV（`audio/wav`）或 MP3（`audio/mpeg`），实际加载字节数不超过 **25,000,000**，可信 FFprobe 容器时长向上取整到毫秒后不超过 **30,000ms**；不自动切片。`language` 必须为 `auto`，其他值在提交时返回 `SAND_AI_AUDIO_TRANSCRIPTION_VALIDATION_ERROR`；`speaker_diarization=true` 返回 `SAND_AI_AUDIO_DIARIZATION_UNAVAILABLE`。词汇表映射为服务商支持的 `hotwords` 提示，仍不保证识别质量。连接器只发送同步 multipart 请求，不将服务商 `request_id` 当作幂等或结果查询保证。

该驱动在私有输入加载、内容摘要／媒体头与时长核对之后、持久派发准备／领取之前拒绝超限输入。字节超限、时长超限、媒体不支持分别使用非重试错误码 `SAND_AI_AUDIO_TRANSCRIPTION_INPUT_TOO_LARGE`、`SAND_AI_AUDIO_TRANSCRIPTION_INPUT_TOO_LONG`、`SAND_AI_AUDIO_TRANSCRIPTION_MEDIA_UNSUPPORTED`；后两项不能凭文件名或调用方时长替代实际检查。提交阶段可拒绝媒体元数据或不支持的请求参数，实际字节／时长错误也可能在已入队任务执行后通过终态 `error_code` 读取。探测或私有存储失败不得派发，也不得伪造成功时长。运行默认关闭；启用须显式配置 `sand_ai_audio_execution.external_enabled` 和 `glm_asr_audio.enabled`，并由 `SAND_AI_FFPROBE_EXECUTABLE` 提供可信可执行探测路径，部署发布与路由／授权仍分别办理。

安装载荷包含增量迁移 `202610020105_registered_audio_routes.pgsql`：替换历史仅允许本地 Whisper 的音频路由约束，允许且仅允许 `local_whisper_cpp|openai_compatible_audio|glm_asr_audio`，保持驱动和部署引用非空。`NOT VALID` 保留历史行，约束后续写入；不据此宣称历史数据已通过验证。干净安装由 `install.sql` 顺序包含该迁移，升级由 `update.sql` 核对历史及新迁移摘要、按缺失账本项执行并登记；保留旧迁移字节和摘要。本轮仅交付迁移源码，数据库安装／升级验收另计。

终态音频任务的 `result.schema.code` 为 `sand_ai.audio_transcription`，`segments` 每项含 `start_ms,end_ms,speaker,text,confidence,warnings,source`；`usage.audio_seconds` 为三位小数秒数字符串，`review_required=true`。本地 Whisper 驱动按受控私有文件的媒体容器时长计量，向上取整到毫秒；不能用最后一个识别片段的结束时间代替整段时长，探测失败时不得写成功计量。本地录音先在私有临时文件中转成 16 kHz 单声道 PCM WAV，再交给 whisper.cpp；转码失败或输出无效时不得启动识别，临时转码文件在任务尝试后删除。引擎没有提供可用文字但有时间范围时，该段 `text=""` 且 `warnings` 含 `no_recognized_speech`，界面按时间提示回听。本地引擎识别片段之间及首尾的未覆盖时间范围也返回空白片段，并额外标记 `uncovered_audio_range`；这只表示识别器没有输出，不断言该时段有人声。本地引擎返回空片段列表时使用已验证的整段时长形成一个空白待复核片段。不得将空白段当成已识别事实，摘要不得引用它，整份无可用文字时拒绝摘要。`source` 只含不透明 `file_ref`、内容摘要和音频时间范围，不返回对象位置、永久 URL 或厂商原文。读取须重新取得当前任务及私有文件授权；公开结果按任务冻结的驱动和文件引用/摘要校验，不一致时不返回转写结果。

本地转写的 `start_ms/end_ms` 和 `source.locator` 时间基准是原容器的播放时间，零点为容器起点；不是抽取音轨后的零点。可信 FFprobe 与 FFmpeg 必须同选首音轨 `a:0`／`0:a:0`。WAV／原生 FLAC 的无时间戳样本时钟从零开始；带时间戳的容器须能核实容器起点与该音轨起点，无法核实时以 `SAND_AI_AUDIO_UNAVAILABLE` 拒绝，在识别前终止。本地转码固定 `-copyts -start_at_zero`，由 FFmpeg 仅减一次原容器起点，配合 `aresample=async=1:first_pts=0:min_comp=0:min_hard_comp=0` 按原播放时钟填充首部和时间间隙／修剪负时间样本，不把音轨起点再加到识别结果。例如容器起点0、音轨起点5秒，归一 PCM 保留首部5秒，识别器的5秒仍定位原文件5秒。转码后须独立核实 PCM 时长；超出可信原容器时长时不得启动识别。最终识别边界仅能按 PCM 实际时长裁掉既有允许的解码尾部填充，PCM结束后的容器尾部保持空白待复核；`usage.audio_seconds` 仍是可信原容器时长向上取整到毫秒，不改成音轨或识别时长。公开 Schema／来源字段形状不变，已封存历史结果不重写。

本地私有执行指纹升级为 `local_audio_execution_config@2`，除四工具／模型路径及摘要外冻结 `timeline_policy=original_container_playback@1`。旧草稿、已验证或已发布执行快照不满足新指纹，沿既有漂移拒绝链处理，必须正式新草稿／successor取得新验证证据再发布；不能复用旧验证或改写旧来源来宣称已映射。

音频来源 `source.locator` 保持 `{ type:"audio_time_range",start_ms,end_ms }`，新增可选 `granularity: "engine_segment"|"input_range_only"`。`engine_segment` 只表示确有引擎分段范围依据，仍不保证人工验证或逐字准确；`input_range_only` 表示仅能回到完整输入范围，不证明某句话发生于该范围中的具体时刻。旧记录缺字段时保持缺失，不补默认值，不改既有封存摘要；界面显示粒度未说明，不推断为引擎分段。任务来源、字段来源、复核读取、摘要冻结与引用都保留原有可选字段，不让模型添加或细化定位。

GLM 同步响应只有文字及调用元数据，没有逐句时间或说话人字段；成功结果恰有一段 `[0,可信整段时长]`，`source.locator.granularity=input_range_only`、`speaker=null`、`review_required=true`。新段的 `confidence=null` 配合 `confidence_unavailable` 表示未提供置信度；历史封存 `confidence="0.0000"` 配合该警告按未知展示，原值与摘要不改；`timing_unavailable` 表示没有逐句时间。文字为空时另标 `no_recognized_speech`，不能成为摘要事实。管理端须展示整段范围、缺少时间／说话人能力和人工核对限制，不能把一个整段标签冒称自动说话人分离或精确时间线。

长录音候选沿用 `sand_ai.audio_transcription@1.0.0`：只有冻结清单中全部片段均取得成功且未清理的不可变派发回执及对应用量事件后，父音频任务与原转写步骤才同事务进入 `succeeded`，持久化全片结果；未知、失败、缺失或重复片段不得产生完整成功结果。每段公开来源仍指向原私有文件 `file_ref`／上传 SHA-256，时间为原音频绝对范围。长 GLM 每个完整输入片只产生一个 `input_range_only` 段，保留 `speaker=null`、`confidence_unavailable` 和 `timing_unavailable`；切片不产生逐句时间或自动说话人能力。短 GLM 的一个完整输入段及超限拒绝语义继续适用短录音执行路径。

长录音的 `result.usage.audio_seconds` 为冻结完整音频范围的可信时长；公开 `audio_usage_cost` 保持既有 `{ state,audio_seconds,metered_amount,currency,price_revision_id }` 形状，但从逐派发的不可变用量事件去重、校验并全量累加，不额外计费父步骤，未知金额保持 `null`。公开任务读取、音频复核与会议摘要来源读取均从持久清单和逐片回执重新构造转写，核对父结果及冻结来源后才使用，不以父结果内自报费用或摘要代替证据。持久 `TaskRun.result` 私有字段 `_audio_chunk_aggregation` 含 `{ version:"audio_chunk_aggregation@1",manifest_sha256,transcript_sha256,chunk_count,dispatch_ids,usage_event_ids }`，仅作服务端完整性校验标记；任何公开结果与摘要模型输入均剥离该字段和内部事件 ID。该候选尚未启用，Worker 接续、迁移安装、真实 API／音频与费用验收另计，4.5 保持未完成。

`openai_compatible_audio` 分段转写将服务商的秒数时间戳四舍五入到毫秒，允许服务商返回超过三位小数的浮点时间；负数、非有限数和超限值拒绝。它按响应声明的完整时长补齐识别片段之间及首尾未覆盖的时间范围，空白片段标记 `no_recognized_speech` 和 `uncovered_audio_range`，供逐段回听；它不证明该范围确有人声。外部响应片段重叠、超出声明时长或补齐后超过 10000 段时拒绝该响应，不能把不完整时间线作为成功成果。

成功音频任务读取另返回 `audio_usage_cost: { state, audio_seconds, metered_amount, currency, price_revision_id }`，其金额来自与任务冻结归属、目标、时长和价格快照核对一致的不可变计量事件。`state=priced` 才提供字符串计量金额与币种；`unpriced` 和缺失事件的 `unavailable` 都返回 `metered_amount=null`，不把未知记为零，也不把计量金额称为服务商已对账金额。管理端任务页展示时间、说话人、文本、置信度、复核提示和计量金额，不展示 `source` 内部引用。GLM 短录音保持一次业务调用、一次持久派发、一个加密标准结果回执和一条 `audio_seconds` 用量事件，时长冻结为可信整段输入时长，恢复回执须与之相符再计量。未知价格保留 `unpriced`／金额 `null`；发送后超时、错误或非标准响应保留结果不明，不自动重发可能已收费的调用。单独的转写结果不等于会议摘要或真实会议业务闭环。

`POST /api/sand-ai/v1/audio-transcriptions/{taskId}/summary` 为已完成的音频任务创建独立、计费的异步摘要任务。请求须有 `Idempotency-Key`，正文只接受 `{ model, max_output_tokens?, review_revision? }`；同一工作负载须同时具备 `sand_ai.audio.transcription` 对原私有文件的授权和 `sand_ai.chat.complete` 对摘要任务的授权。当前只有原音频请求明确为 `approved_cloud` 时允许云摘要，实际使用本地转写驱动不改变该许可；`local_only` 与 `private_only` 均拒绝云外发。服务端从原任务重新取得转写和真实文件数据级别，不接受调用方提交转写正文或来源 ID；任务输入只保存原音频任务 ID、转写摘要和权限快照，执行及模型派发前复核。提交响应仍为 `{ idempotent_replay, task }`，摘要任务沿 `GET /api/sand-ai/v1/tasks/{taskId}` 读取。

音频片段复核由应用运行面承载：`GET /api/sand-ai/v1/audio-transcriptions/{taskId}/reviews` 返回 `{ revision, segments:[{ segment_index,start_ms,end_ms,speaker,original_text,text,review_revision,source }] }`；`POST` 同路径须有 `Idempotency-Key`，正文为 `{ expected_revision, segment_index, text, speaker? }`，文字留空表示该段仍未获得可用文字。`speaker` 不传时沿用该段已有标记，显式 `null` 清除标记，非空时只接受 `speaker_1` 至 `speaker_9999` 匿名编号；它是人工归类标记，不是已核实的人名或自动说话人分离结果。两者都要求同一 SandIAM 工作负载具备 `sand_ai.audio.transcription` 与单独登记的 `sand_ai.audio.review`，后者对原私有文件经正式资源事实授权。复核记录按任务修订号追加，绑定原转写摘要、工作负载和精确片段；冲突修订拒绝，不改写原始转写。摘要按指定复核版本读取文字和说话人标记。`GET` 新增的 `source` 是原片段的安全来源，保留可选定位粒度；`POST` 仍只能修订文字与匿名说话人标记，不能拆段、改时间或补造逐句定位。对 GLM 的一个完整输入段，人工标记也只作用于整段。SandAI 只能证明工作负载归属，不能把应用提交当作已核实的人类姓名。

回听原音频使用 `GET /api/sand-ai/v1/audio-transcriptions/{taskId}/source`，要求与片段复核相同的 SandIAM 双动作和原文件资源事实授权。成功返回原文件的音频 MIME 与二进制内容，响应 `Cache-Control: no-store`，不返回对象键、Bucket 或存储 URL。服务端重新核对已完成音频任务、私有文件状态、工作负载归属、音频 MIME、字节数和上传时 SHA-256；存储不可用或内容漂移时失败关闭。管理端只在当前任务页持有临时播放对象，切换任务或离开页面即释放；播放内容不写入浏览器持久存储。

摘要提交可额外指定整数 `review_revision`，缺省为 0（原始转写）。指定版本必须在该音频任务的追加复核记录中完整存在；摘要输入、Worker 和读取冻结该版本及修订后的转写摘要，后续复核不能改变旧任务的来源。返回的摘要任务 `result.audio_review_revision` 标明所用版本。只有有文字的片段可成为摘要引用，复核标记不代表业务审批。

会议摘要采用 `schema={code:"sand_ai.meeting_summary",version:"1.1.0"}`。提交路由、双动作授权、幂等键和正文 `{ model,max_output_tokens?,review_revision? }` 保持上述约定；`max_output_tokens` 是**每次**模型调用的上限，不是整个摘要任务的总上限。服务端冻结最多 10000 个原转写片段、复核版本、规划器版本、来源摘要和模型目标，按模型输入预算确定性分块，逐块提取，再对已封存子结果进行有界分层合并；不得只取前若干片段、静默截断、跳过不可容纳的片段，或把未覆盖结果标为成功。无法规划全量输入时在派发前明确失败。块节点与合并节点各有独立的真实 `TaskStep`、稳定节点码、调用请求号、预算预占、派发回执、不可变用量事件与加密结果回执；原 `ai_completion` 父步骤保留为整项编排步骤，父步骤不得作为额外模型调用计费。恢复只重用已验证的同节点已封存结果或沿原请求号核查未知派发；不得因 Worker 重启、租约回收或提交重放而自动重发可能已计费的调用。

成功的 `result.meeting_summary` 含 `summary,key_points,decisions,action_items,unresolved` 五组陈述；每条为 `{ text,references:[{segment_id,source}] }`。每层输出的引用只能来自该节点实际输入的原片段或已验证子节点引用；最终每条引用仍指向冻结转写中的真实片段及音频时间范围，并保留原有可选 `source.locator.granularity`，`review_required=true`。GLM 引用仅能定位完整输入范围；摘要分块或文字复核不能提高其时间精度，原转写的时间／置信度不可用提示仍须可见。摘要同时返回 `coverage:{ total_segments,eligible_segments,covered_segments,uncovered_segment_ids,complete }`；`total_segments` 包括空白段，`eligible_segments` 只计有可用文字的段。只有所有有文字片段进入已封存块节点、合并层完整覆盖这些块、最终引用均可回溯，且 `covered_segments=eligible_segments`、`uncovered_segment_ids=[]`、`complete=true` 时任务可成功；覆盖表示已纳入归纳，不保证模型提到每个片段的每项事实。纯空白转写仍拒绝摘要。冻结复核版本之后的新复核不改变旧任务来源。

`GET /api/sand-ai/v1/tasks/{taskId}` 对会议摘要任务额外返回 `summary_progress:{ planned_nodes,completed_nodes,failed_nodes,active_node_code,covered_segments,total_segments }`；数值从持久节点及 `TaskStep` 投影，节点 `state` 只取对应 `TaskStep`，不提供另一套状态字段。任务 `queued|running|retrying` 时 `meeting_summary=null`，费用可展示已发生的调用。节点失败、取消或派发结果不明时，任务不得返回伪完整摘要；保留可授权读取的已封存节点进度及已发生费用。无效节点输出使用 `SAND_AI_AUDIO_SUMMARY_RESULT_INVALID`，覆盖缺口使用 `SAND_AI_AUDIO_SUMMARY_COVERAGE_INCOMPLETE`，派发未知使用 `SAND_AI_AUDIO_SUMMARY_DISPATCH_AMBIGUOUS`；取消沿用任务 `canceled` 状态，不把它写成模型失败。内部回执过期且无法确定是否派发时须停在待人工核查，不以新请求号重发。接口不暴露私有节点输入、内部回执或服务商原文。

任务 DTO 顶层的 `meeting_summary_cost` 按各实际节点的用量事件返回 `calls:[{ node_code,invocation_id,input_tokens,output_tokens,total_tokens,price_revision_id,metered_amount,currency,pricing_state }]` 和 `totals:[{ currency,input_tokens,output_tokens,total_tokens,metered_amount }]`。调用与节点、任务及 `ai_task` 派发范围须一致；无可信归属的旧调用不纳入费用。未知 Token 或金额保留 `null`，同币种任一调用金额未知时该币种合计金额也为 `null`，未知币种独立成组，不同币种不得相加，父步骤不重复计费。任务读取从当前受授权的冻结转写派生 `result.unrecognized_audio_ranges=[{start_ms,end_ms}]`，只包含未识别文字且标记 `no_recognized_speech` 的音频范围，相接或重叠范围合并；空数组不表示已人工复核。该字段不进入模型输入，也不修改已封存摘要。原音频触发内容清理时，节点私有输入和结果正文同事务清空，保留摘要和已发生费用；清理后的晚到回执不得回填私有正文。管理端任务页可展示节点进度、陈述、可回溯时间、待回听范围和逐调用费用。真实音频及已安装宿主验收另计。

### 5.6.1 长会议容量与分词账本（0.1.3 候选，未运行验收）

摘要公开请求正文仍为 `{ model,max_output_tokens?,review_revision? }`，不得传入容量、来源摘要、消息或账本身份。提交只重新授权私有来源并冻结当前已发布路由、复核版本和可信本地容量登记；官方计数由已持久且取得有效租约的 Worker 执行，完整覆盖 manifest 封存后才创建摘要节点。缺登记返回 `SAND_AI_AUDIO_SUMMARY_CAPACITY_UNAVAILABLE`，不会退回字符数估计。

历史 `meeting_capacity@1` 可信登记由宿主操作员在 `config/sand_ai_meeting_summary.php` 的 `registrations` 配置中提供；新开通使用下述后台持久会议执行设置，不要求手写配置。旧配置的登记键为 exact published route snapshot SHA-256；值包含 `context_window_tokens,input_token_limit,capacity_source_url,capacity_source_sha256,max_tokenizer_requests`。目前只允许显式登记的直连 `openai_compatible/glm-5.3-flash`、`https://open.bigmodel.cn/api/paas/v4`，容量来源 URL 为 `https://docs.bigmodel.cn/cn/guide/models/vlm/glm-5.3-flash`；不推断代理、别名、Coding Plan、Neuron/HF 或其他模型支持。上下文上限为 1,000,000，输入不得超过上下文减真实输出余量；每次输出余量与 dispatch 一致，最多 4096。请求上限 40,000–100,000，默认 100,000，计入所有发送意图（含失败与未知），成功缓存不重复计数。部署更改后须重新登记其新 route digest，不能沿用旧登记。

内部 `_meeting_capacity` 冻结 `meeting_capacity@1`、exact route/config digest、官方 endpoint、容量与请求上限、来源 URL/内容 SHA、snapshot SHA；`_meeting_summary.planner_version=meeting-summary-v3`，`_meeting_plan` 保存原文区间覆盖 manifest。这些不作为公开请求字段返回。v3 对冻结复核转写不新增 trim；非空段按 Unicode 码点区间保留完整来源，空白段记录有意排除。按原顺序恰好覆盖每个 eligible 段 `[0,length)` 一次才可成功；覆盖表示进入归纳，不保证每项事实都被模型提及。每叶与合并节点以完整 system+user 消息的官方 `prompt_tokens` 检查容量，不能用字符数替代。低输入容量触发分裂时仍受持久请求上限约束，超过即明确失败，不截断余下内容。

#### 会议摘要人工复核交接（2026-10-30 冻结并实测）

会议摘要输出契约置 `review_required=true` 后，由具名管理员在后台显式提交，经已安装 SandWorkflow 的公开契约创建人工复核审批实例。`review_required` 本身不创建实例、不做最终业务动作；未标记复核、未成功、非会议摘要或成果已清理的任务一律拒绝交接。

两个管理端点均使用既有 `CheckLogin`、`CheckAuth`：

| 端点 | 请求 | 后台权限 |
| --- | --- | --- |
| `GET /app/sand-ai/admin/meeting-review/workflow/read` | `environment_id:positiveInt`、`task_id:positiveInt` | `sand_ai:meeting-review:read` |
| `POST /app/sand-ai/admin/meeting-review/workflow/submit` | `environment_id:positiveInt`、`task_id:positiveInt`、`reviewer_admin_id:positiveInt`，可选 `definition_id:positiveInt`、`group_id:positiveInt` | `sand_ai:meeting-review:submit` |

顶层字段严格限于上列；多余字段为 HTTP400 `SAND_AI_SCHEMA_UNSUPPORTED`。归属只从当前 SandIAM 环境核验取得 `{organization_id,application_id,environment_id}`，请求不得提供这三个字段，也不登记 workload client。`reviewer_admin_id` 必须是在职后台用户；发起人是当前登录管理员。请求键由服务器从任务号与冻结的转写复核版本生成为 `sand_ai_meeting_review_<task_id>_r<review_revision>`，调用方不提供、不能跨业务复用。

两端点返回同一投影 `data`：

```json
{
  "environment_id": 1,
  "task_run_id": 52,
  "audio_review_revision": 0,
  "request_key": "sand_ai_meeting_review_52_r0",
  "replayed": true,
  "workflow": {
    "definition_id": 1,
    "definition_sha256": "<64 位小写十六进制>",
    "instance_id": "<SandWorkflow 实例标识>",
    "tenant_id": "sandadmin",
    "owner_scope": "sand_ai",
    "business_type": "sand_ai_meeting_review",
    "business_id": "52"
  },
  "initiator": { "account_space": "sandadmin", "account_id": "1" },
  "participants": {
    "meeting_summary_reviewer": { "account_space": "sandadmin", "account_id": "1", "name": "复核人" }
  },
  "instance_state": {
    "flow_status": 0,
    "act_node_id": "UT_sand_ai_meeting_review",
    "ended_time": null,
    "pending_tasks": [
      { "task_id": "...", "node_id": "UT_sand_ai_meeting_review", "node_name": "会议摘要人工复核",
        "assignees": [{ "account_space": "sandadmin", "account_id": "1" }] }
    ]
  }
}
```

`flow_status` 为 SandWorkflow 原值：`0` 进行中、`1` 通过、`2` 驳回、`3` 撤销；它每次都从 SandWorkflow `detail()` 读回，不在 SandAI 侧镜像。`replayed=true` 表示本次没有新建实例。流程表单只携带引用（任务号、转写任务号与复核版本、摘要契约 `code@version`、条目统计、覆盖结论、归属、`GET /api/sand-ai/v1/tasks/{taskId}` 入口），不含摘要正文、引用段文本、对象 key、Bucket、永久 URL 或厂商原始响应。

业务错误使用正常 HTTP 与稳定域码：字段或类型不受支持为 HTTP400 `SAND_AI_SCHEMA_UNSUPPORTED`；环境不可核验保持原 IAM 域码；当前环境无该任务为 HTTP400 `SAND_AI_MEETING_REVIEW_TASK_NOT_FOUND`；非会议摘要、未成功或未标记复核为 HTTP400 `SAND_AI_MEETING_REVIEW_NOT_REQUIRED`；成果已按保留策略清理为 HTTP400 `SAND_AI_MEETING_REVIEW_CONTENT_PURGED`；复核人不存在或已停用为 HTTP400 `SAND_AI_MEETING_REVIEW_ACTOR_UNAVAILABLE`；尚未提交复核时读取为 HTTP400 `SAND_AI_MEETING_REVIEW_NOT_STARTED`；未安装 SandWorkflow 为 HTTP400 `SAND_AI_WORKFLOW_UNSUPPORTED`；交接未能完整持久化为 HTTP409 `SAND_AI_MEETING_REVIEW_HANDOFF_FAILED`。

通过与驳回不在 SandAI 暴露。复核人使用 SandWorkflow 既有公开端点 `POST /app/sandworkflow/admin/instance/approve`、`/instance/reject` 处理待办，SandAI 只读回状态，不镜像结论、不自动改写任务或成果状态。持久引用表为 `sand_ai_meeting_review_workflow`（迁移 `202610310162`），唯一键为 `(environment_id,task_run_id,audio_review_revision)` 与 `(environment_id,request_key)`；同环境同蓝图只发布一次流程定义。同一任务同一转写复核版本只允许一轮审批，驳回后须先产生新的转写复核版本。

#### 会议执行设置（2026-10-04 冻结，候选未安装）

正常后台首次配置页提供会议执行设置。它登记管理员核定的真实模型容量与现有分词驱动，保存不会启用、调用模型、调用分词服务或抓取容量来源 URL。登记归属仅从当前 SandIAM 环境核验取得 `{organization_id,application_id,environment_id}`；请求不得提供这三个 owner 字段（仅以 `environment_id` 选择环境），不登记 workload client，也不授予任何 SandIAM 服务权限。运行时仍核验当前调用方四级身份、来源、费用和全部 Worker 迁移／触发器。

三个管理端点均使用既有 `CheckLogin`、`CheckAuth`：

| 端点 | 请求 | 后台权限 |
| --- | --- | --- |
| `GET /app/sand-ai/admin/setup/meeting-execution/read` | `environment_id:positiveInt`，可选 `model:nonemptyString` | `sand_ai:setup:deployment:read` |
| `POST /app/sand-ai/admin/setup/meeting-execution/register` | 下述 expected 字段及 `capacity` | `sand_ai:setup:deployment:save` |
| `POST /app/sand-ai/admin/setup/meeting-execution/state` | 同 expected 字段及 `enabled:boolean` | 既有 `sand_ai:onboarding:publish` |

POST 顶层字段严格限于 `environment_id,model,expected_route_sha256,expected_deployment_revision,expected_profile_revision,expected_revision` 加该端点专属字段。route SHA 为 64 位小写十六进制；deployment/profile revision 为正整数；registration `expected_revision` 为非负整数，首次登记为 0，后续必须等于服务器最新 revision。管理员不得提交 route 对象、provider endpoint、owner 或任意分词 URL。服务器重新解析环境当前已发布 chat route，严格比对 SHA、部署 revision、profile revision；被撤销或变化的路由不能启用。每次实际保存或状态切换递增 registration revision；同状态重复提交不修改 revision，仍须通过 expected guards。

`capacity` 精确为 `{tokenizer_driver:string,context_window_tokens:int,input_token_limit:int,max_tokenizer_requests:int,capacity_source_url:string,capacity_source_sha256:string}`。driver 必须来自本次 route 返回的可执行驱动列表；驱动已有生产适配器决定支持的路由与上下文安全上限，不按任意厂商名称推断。context 范围为 `[2,driver.context_window_ceiling]`；input 为 `[1,min(context_window_tokens-route.max_output_tokens,route.input_token_ceiling若存在)]`；分词请求数在驱动返回的闭区间内（现有驱动 40000–100000）。来源 URL 必须为无用户名／密码的 HTTPS URL，SHA 为 64 位小写十六进制。它们只记录授权管理员核定依据，不证明已自动验证来源或模型能力；保存及启用不发生远程探测。真实分词计量、失败／未知意图限制、输出余量最多 4096、段数与完整覆盖保持原规则。

GET 返回正常管理响应 `data={owner,routes,selection}`。`routes` 仅含环境当前可选择的已发布 chat route；选定 model 时 `selection` 为该 route 设置，否则 `null`。每个 route 为：
`{model,display_name,deployment_id,deployment_revision,profile_id,profile_revision,route_sha256,max_output_tokens,input_token_ceiling:int|null,tokenizer_drivers:[{code,display_name,context_window_ceiling,request_limit_min,request_limit_max}]}`。
不支持分词的已发布 route 可见，驱动列表为空并明确不可配置。无已发布 route 时列表为空，不猜默认部署。

`selection={route,registration,state,can_enable,readiness:{ready,reason_codes:string[]},next_action:string}`。
`registration` 为 `null` 或 `{id,revision,state:"disabled"|"enabled"|"revoked",route_sha256,deployment_revision,profile_revision,capacity}`。设置状态为 `unconfigured|disabled|enabled|revoked|stale|unsupported|worker_unavailable`；stale 表示保存的 route 与当前 route 不同，原容量仅供核对，必须重新登记。`can_enable` 仅在存在与当前 route 完全匹配的登记、driver 可用且全部 Worker readiness 成立时为 true；它不是当前用户拥有发布权限的承诺。readiness 的原因列表仅返回稳定域码，`next_action` 为可读操作建议。POST 返回 `data={selection}`，不返回内部 snapshot、凭据、对象路径或原始厂商响应。

保存把登记置为 `disabled`；显式 `enabled:true` 才启用。`enabled:false` 把登记置为 `revoked`。启用前重新核验 route、owner、capacity、完整 Worker readiness；来源证据不是绕过 readiness 的手段。关闭后拒绝新任务；已冻结任务保持原不可变容量与合法来源／IAM／回执恢复规则，不以 mutable 设置改写旧快照。路由或部署版本变化后旧登记状态只投影为 stale，不自动启用新版本。

无权限使用既有后台授权拒绝；业务错误统一正常 HTTP 与稳定域码：未知字段／类型／容量为 HTTP400 `SAND_AI_SCHEMA_UNSUPPORTED`，环境不可核验保持原 IAM 域码；无已发布 route 为 HTTP400 `SAND_AI_CONFIGURATION_NOT_PUBLISHED`；不支持 driver 为 HTTP400 `SAND_AI_MEETING_TOKENIZER_UNSUPPORTED`；expected route 或 revision 冲突为 HTTP409 `SAND_AI_MEETING_EXECUTION_CONFLICT`；未配置／disabled／revoked 的新任务为 HTTP400 `SAND_AI_MEETING_EXECUTION_DISABLED`，提示后台登记并启用；完整 Worker 不就绪为 HTTP409 `SAND_AI_MEETING_WORKER_UNAVAILABLE`，提示完成正常安装／升级。不得把默认 disabled 当通用服务崩溃或自动启用。

持久登记表为 `sand_ai_meeting_execution_registration`，不属于 IAM。新任务容量冻结 `meeting_capacity@2`，增加服务端登记 `{id,revision,organization_id,application_id,environment_id,route_sha256}` 证明并纳入原容量 snapshot SHA；已有 `meeting_capacity@1` 字段、摘要、历史 config 注册路径和合法恢复保持不变。新登记迁移与完整 install/update/uninstall 注册属于候选源码，本段冻结不表示 SQL 已执行或页面／业务已验收。

迁移 `102` 提供独立不可变 `sand_ai_meeting_token_count_request` 与 `sand_ai_meeting_token_count_outcome`。新计数在外发前独立提交发送意图，绑定真实 task、工作负载、来源、冻结 route/capacity、消息摘要与请求身份；响应另追加 succeeded/rejected/ambiguous 事实。发送意图不证明服务商已接收；只有意图或结果未知/失败的原键不得自动重发。已成功的同 task/capacity/messages 回执可复用，持久化成功之前不进入内存缓存。该账本不伪造模型 Invocation/ToolRun；`prompt_tokens` 仅是容量观测，不并入 generation token 费用。真实官方价格未核，金额/币种保留 `null`、`unpriced`，不称免费或已对账。

`GET /api/sand-ai/v1/tasks/{taskId}` 的 `summary_progress` 新增 `planning_state:"legacy"|"pending"|"sealed"` 与 `tokenizer_request_limit:int|null`。仅当前未清理且已重新授权来源的 v3 任务增加 DTO 顶层：

```json
{
  "meeting_token_count_cost": {
    "request_count": 0,
    "succeeded_count": 0,
    "rejected_count": 0,
    "unknown_count": 0,
    "metering_unit": "external_request",
    "metering_quantity": "0",
    "pricing_state": "unpriced",
    "metered_amount": null,
    "currency": null
  }
}
```

示例只说明字段形状，不是实测结果。`request_count` 为不可变发送意图数量，`unknown_count` 包括未终结/ambiguous，不保证全部已外发或计费。该对象独立于原 `meeting_summary_cost`，不返回私有摘要、内部事件 ID、供应商请求 ID 或原始响应。叶回执读取按 64 个 ID 一页核对真实不可变结果，失败不显示伪完整摘要。

缺 `102`、精确迁移摘要或启用的不可变触发器时，`SAND_AI_AUDIO_SUMMARY_TOKEN_COUNT_AUDIT_UNAVAILABLE` 拒绝新的计数/证据读取。计数连接/返回值不可核使用 `...TOKEN_COUNT_UNAVAILABLE`；原请求未知或失败使用 `...TOKEN_COUNT_RECOVERY_REQUIRED` 并要求保留费用事实和核查；达到登记上限使用 `...TOKEN_COUNT_LIMIT_REACHED`；消息超容量使用 `SAND_AI_AUDIO_SUMMARY_INPUT_CAPACITY_EXCEEDED`。无一错误允许静默截断或以新计数键自动避开旧未知请求。

租约比较独立于 PG session TimeZone：RUNNING 的既存无时区时间按可信 `app.default_timezone` 解释，使用 `clock_timestamp() AT TIME ZONE :trusted_app_timezone`，参数来自服务端 `TaskLeasePolicy::runningTimezone()`；配置须合法 IANA 且与有效 PHP 时区一致。RECOVERY_PENDING 保留既存 UTC 语义。等待行锁后及计数发送意图提交前再次核当前租约，已失效 owner 不得继续外发或写恢复状态；已外发计数的结果事实仍可追加，不得回填清理正文。

本节是源码/API 候选：真实 102 安装与升级、触发器、等待锁/时区反例、未知请求恢复、10000 段全覆盖与资源/队列、正式任务/页面和准确费用均未验收；4.5 保持未完成。0.1.3 升级须先备份并按包指引完成正式安装流程，提交后回退旧 runtime 须保留新账本且禁用旧 migration runner/旧 uninstall；具体操作授权与执行证据另记。

### 5.7 语音合成（4.4 部分）

应用调用 `POST /api/sand-ai/v1/speech-syntheses`，使用 SandIAM `sand_ai.audio.speech` 工作负载授权及必填 `Idempotency-Key`。请求体只接受 `{ text, voice }`：`text` 为 1–4096 个 UTF-8 字符，`voice` 为受控音色标识。服务端从已发布的 `audio_speech` 能力配置选择服务商、模型和部署；调用方不能传模型、服务商地址或密钥。同一请求键只允许同一身份、输入和音色重放；尚未派发的预留运行还须匹配冻结目标。服务端优先按原键查找已持久化的运行；已派发的运行返回原状态，已保存音频但待记账的运行先沿冻结事实补记账，均不重新解析当前能力路由，也不再次派发服务商。首次请求仍须解析当前已发布目标；一旦开始向服务商派发，结果不明时不得自动重发。

提交及 `GET /api/sand-ai/v1/speech-syntheses/{runId}` 返回 `{ public_id, state, input_characters, voice, audio_bytes, error_code, create_time, complete_time, cost }`。`runId` 是返回的 32 位小写十六进制 `public_id`，不是内部数据库 ID。`state` 为 `prepared|dispatching|awaiting_usage|succeeded|failed|ambiguous`；只有私有音频保存并在同一事务中写入不可变字符用量事件后才能返回 `succeeded`。`cost` 在未记账时为 `null`；已记账时为 `{ pricing_state, price_revision_id, metered_amount, currency }`。价格按服务商调用开始时已发布的 `input_characters` 单价冻结；无有效价格时 `pricing_state=unpriced` 且金额、币种为 `null`，不把未知费用记为零。

`GET /api/sand-ai/v1/speech-syntheses/by-request-key` 使用原 `Idempotency-Key` 请求头和当前 `sand_ai.audio.speech.read` 工作负载授权，只读查找原请求并返回相同运行摘要。它在请求超时或后台页面刷新后找回服务端已持久化的运行；未找到返回资源不存在，不会创建、恢复派发或再次调用服务商。后台试用页仅在当前浏览器会话保存不含文字、音色或 Bearer 的原请求键，并在新试用前先提示处理未确认的旧请求。

语音合成命中的组织、应用、环境、服务、能力与模型预算共同生效。服务端在发送准备事务中按冻结字符数和单价整体预占；任一硬限额不足、价格未知或币种不一致时拒绝派发，软预算沿现有超额策略执行。连接器明确证明尚未进入服务商请求入口的失败，在同一事务中把运行记为 `failed` 并释放全部预占，不产生用量事件；原键重放只读取该失败，不再次派发。已进入请求入口、服务商结果不明或不能提供可靠发送边界的驱动保持占额；已经取得合成结果后的私有存储失败也不得释放已发生用量。成功用量事件与预算结算在同一事务写入，冻结价格和调用事实不受后续策略变化改写；到期预占沿用保持占额规则。无有效预算策略时仍保留字符计量和价格快照。

音频已保存而费用尚未完成时，后台恢复进程按冻结身份补写同一用量事件和预算结算；它不依赖当前能力路由，也不再次调用服务商。费用事实先于结果读取授权完成，撤权不能抹去已发生的调用费用；任何结果返回仍要求当前 SandIAM 授权。

`GET /api/sand-ai/v1/speech-syntheses/{runId}/audio` 在 `succeeded` 后返回 `audio/mpeg` 二进制，使用 `Cache-Control: private, no-store`。两个 GET 都要求同一工作负载具备 `sand_ai.audio.speech.read` 当前授权，并在 `sand_ai.speech_run` 资源事实下重新授权。音频从私有存储读取后核对字节数与 SHA-256；任何接口均不返回对象键、Bucket、永久 URL 或服务商原始响应。后台开通页的试用组件由操作员提供工作负载 Bearer，调用上述应用接口并以私有二进制流播放结果；后台自身不增加语音合成服务接口。上述为源码契约；迁移、SandIAM 动作登记、真实服务商与 HTTP 验收尚未完成。

## 6. 版本记录

| 版本 | 阶段 | 变更 |
| --- | --- | --- |
| 1.0 | C-04 | 冻结应用、服务商、模型、记录管理接口与运行 API 边界 |
| 1.1 | C-05 | 增加环境、一次性凭证签发、部署草稿和发布接口；不变更 1.0 路径与 DTO |
| 1.2 | SAND-102 | 冻结文件上传、元数据查询、受控删除和文件凭证 scope；不提供下载 URL |
| 1.3 | SAND-103 | 增加文件同步解析、最近解析摘要、来源定位和 OCR 未配置的稳定错误码 |
| 1.4 | SAND-104 | 解析改为异步任务；增加任务查询/取消、`tasks:*` scope、任务步骤 DTO、幂等重放与活动任务去重规则 |
| 1.5 | SAND-110 | 增加环境级能力配置档、代码注册驱动、简化模板和运行时能力路由；不改变 1.0–1.4 已冻结路径与 DTO |
| 1.6 | SAND-105 | 增加异步 `ai_completion` 提交、失败任务手动重试、结构化输出及环境隔离的来源引用；不改变 1.0–1.5 已冻结路径与 DTO |
| 1.7 | SAND-106 | 增加环境隔离的私有来源检索、定位可回跳的命中 DTO 与 PostgreSQL FTS 索引；不改变 1.0–1.6 已冻结路径与 DTO |
| 1.8 | SAND-107 | 增加受控 Agent 任务、代码允许的检索工具、来源引用与非业务审批的复核标记；不改变 1.0–1.7 已冻结路径与 DTO |
| 1.9 | SAND-105 补充 | 增加服务端 `openai_compatible` Provider 适配器与稳定错误映射；不改变管理或运行 API 路径、DTO，Provider 密钥保持只写。 |
| 1.10 | SAND-111 | 冻结 SandIAM 的平台客户、应用、工作负载客户端与服务授权权威；现有 application/environment/credential 接口标记为迁移期兼容，不代表迁移已经完成。 |
| 1.11 | SAND-113B 局部冻结 | 全量插件本地 API 的未安装 SandIAM context adapter 行为固定为 `SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE`、不可重试、无旧 API Key 回退；完整宿主 adapter 与端到端安装验收仍属于 SAND-113C/F。 |
| 1.13 | SAND-113C | 全量插件宿主 SandIAM adapter：Bearer workload context、audience `sand-ai`、verify/environment 端口映射与稳定错误码；无端口时 1.11 fail-closed 不变。不改变 1.0–1.12 路径与 DTO。 |
| 1.14 | SAND-116 | 新增受 SaiAdmin 登录态/按钮权限保护的只读 `GET /app/sand-ai/admin/setup/deployment/index`。仅列出 Provider→Model→Deployment→ConfigRevision 发布链完整且 active 的部署；可按已发布环境 capability profile 缩小为实际可路由能力。不得读取密钥/配置 JSON、SandIAM 表，或写入审计/用量。 |
| 1.15 | 统一产品 4.4 | 冻结应用侧语音合成提交、运行读取和授权私有音频读取；按调用开始时的字符价格记录不可变用量，未知价格明确未计价。 |
| 1.16 | 统一产品 4.5 有界候选 | 增加 GLM 短录音驱动、可选音频定位粒度和复核来源；超限拒绝、不伪造时间／说话人，迁移105仅交付源码，真实会议验收未完成。 |

机器人渠道适配入口使用 SandAI 的 `sand_ai_channel_binding` 配置表及 `sand_ai_channel_operation` 管理幂等表。绑定主键为 `id`，环境内 `code` 唯一；只保存 SandIAM `application_id`、`workload_client_id` 的引用，不保存其凭证或 grant。后台 `POST /app/sand-ai/admin/channels/bindings/save` 接收 `{environment_id,code,name,agent_definition_id,application_id,workload_client_id}`，`POST .../disable` 接收 `{environment_id,channel_binding_id}`；均需 `Idempotency-Key` 与 `sand_ai:channel:binding:manage`。`GET .../read?environment_id=&code=` 需 `sand_ai:channel:binding:read`。保存绑定要求智能体当前已发布；重新绑定增加 revision，使旧渠道任务失效。`GET .../index?environment_id=&page=&limit=` 要求 `sand_ai:channel:binding:read`，返回环境内绑定的分页摘要。

渠道适配器以 SandIAM 工作负载上下文调用 `POST /api/sand-ai/v1/channels/{code}/messages`，请求只含 `{message_id,conversation_id,sender_id,query}` 与 `Idempotency-Key`。提交沿用智能体任务响应 `{idempotent_replay,task}`，真实任务编号为 `task.id`，不返回顶层 `task_id`；新提交与同键重放保持同一格式，管理页和平台回调均从该编号继续读取任务或建立回信记录。服务端要求该上下文的应用和工作负载客户端与绑定一致，并沿用 `sand_ai.agent.submit`、`sand_ai.agent.run`、任务、来源和费用链；外部消息 ID、会话 ID、发送者 ID 只以摘要进入任务。适配器的 `GET /api/sand-ai/v1/channels/{code}/messages/{taskId}?conversation_id=&sender_id=` 在同一身份、绑定 revision、会话与发送者范围内读取该任务。通用任务读取/取消/重试不能访问渠道任务。两个渠道可以绑定同一个发布智能体，但运行消息和会话范围按渠道隔离。

同一渠道、会话和发送者一次只受理一条未结束的消息；相同请求键优先返回原任务，另一个消息并发提交返回 `SAND_AI_CONVERSATION_BUSY`。执行新消息时读取最近两轮成功的消息作为上下文，要求组织、应用、环境、工作负载、渠道绑定及 revision、发布智能体版本和身份范围一致。最近两轮的问题与回答总长不得超过 12000 字符，超出返回 `SAND_AI_CONVERSATION_CONTEXT_LIMIT`，适配器应开始新会话。历史轮次涉及的私有来源在派发前和调用前重新复验 SandIAM 授权；当前回答仍须以本轮检索结果为事实依据。读取任务结果时复验本轮及上下文引用的来源。通用适配器负责平台签名验真及身份映射；飞书与企业微信文本收发驱动已有源码，钉钉驱动仍待实现，真实平台验收仍待完成。

历史回答传递其完整 `source_references`，包括生成该回答实际使用的历史及当前私有来源；`turn_source_references` 仍只表示当轮新检索，不能代替完整依赖。即使只保留一轮上下文，祖先来源仍进入下一轮文件依赖和最终 `source_references`。来源按 `source_block_id` 去重，同块的文件或定位事实冲突返回 `SAND_AI_SCOPE_SNAPSHOT_INVALID`；生成前、实际模型派发前和调用后分别按当前 SandIAM 正式授权复核，每批最多 8 个来源块。历史与当前合计最多 64 个不同来源块，超限返回 `SAND_AI_CONVERSATION_CONTEXT_LIMIT`，不截断依赖或继续聊天派发；此前已发生的查询 Embedding 调用仍保留真实费用。

参与新上下文的历史结果必须具备服务端内部 `source_provenance_version=2`，且无需人工复核；缺失或未知标记的旧结果不猜测、回填祖先来源，也不加入新提示词，需先结束当前对话后重新提问。该标记不接受外部输入，也不增加任务公开输出字段。旧任务读取仍沿现有当前授权契约，不能据此保证此前已缺失的历史来源完整性；已有字符和轮次配置限制保持适用。

飞书与企业微信回调在会话摘要和消息幂等摘要中固定各自的平台域；即使复用同一绑定及相同外部字符串，也不会混合会话或误认消息重放。平台消息的后续模型上下文只选该平台回信 outbox 已确认 `sent`、且 AI 结果无需人工复核的轮次；`pending`、`failed`、`uncertain`、尚无 outbox 的任务和待复核答案不作为用户已看到的回答。通用适配器提交的消息仍使用原会话范围，其历史也排除待复核答案。平台范围改变后，旧未标记平台的任务不会自动并入新会话；历史兼容和已有数据处理须在实际升级前核对。
平台域切换后，旧回调重投先计算原版请求键并只读查找已有任务；存在旧任务时仍按原冻结会话/消息范围调用既有 `AgentGateway` 幂等重放，随后核对或补建原平台 outbox，不产生第二个 AI 任务。旧键若属于另一平台、任务类型或冻结输入不符则拒绝；不存在旧任务时才使用新平台域。该兼容只覆盖同一已安装实例按顺序升级后的重投；新旧 webhook 代码同时受理同一消息的滚动切换窗口仍须在部署流程中排除或实证核验。

飞书 v2 文本回调入口 `POST /api/sand-ai/v1/channels/{code}/feishu/events` 使用每渠道环境配置 `SAND_AI_FEISHU_CHANNELS_JSON` 中的 `encrypt_key`、`verification_token`、`workload_credential`。验签和校验 token 后，由宿主内 SandIAM 端口签发并验证正式工作负载上下文，再调用现有渠道任务提交链。URL 验证返回 `{challenge}`；文本事件受理返回 `{code:0}`，回调不等待任务完成。异步回信使用 `sand_ai_feishu_delivery` 持久记录加密后的 chat ID。独立 Worker 仅领取终态任务，以当前 SandIAM 工作负载凭证重新签发 `sand_ai.task.read`、`sand_ai.agent.submit`、`sand_ai.agent.run`，复验任务、渠道和智能体版本、私有来源，再用任务 ID 派生的稳定 UUID 发送文本；需审核时只发待审核通知。失败最多尝试 5 次，发送状态保存在 outbox。真实飞书与已安装宿主仍待验收。

飞书渠道凭据可在后台通过 `POST /app/sand-ai/admin/channels/bindings/feishu-credentials/save` 配置，请求为 `{environment_id,channel_binding_id,encrypt_key,verification_token,workload_credential,app_id,app_secret}`，要求 `sand_ai:channel:binding:manage` 与 `Idempotency-Key`。五项均为完整替换，缺一拒绝；响应和渠道列表仅返回 `feishu_configured` 状态，永不回显凭据。凭据写入路由在登录与权限中间件之前清除请求体中的秘密字段，不进入 SandAdmin `SystemLog`。凭据独立于绑定引用，以加密信封持久化；现有 SandAI IAM context 密钥环按飞书凭据专用域派生加密密钥，轮换时保留旧 key ID 以读取历史信封。一个飞书回调 `code` 在已管理凭据中只能属于一个绑定；回调与 Worker 必须在 SandIAM 签发身份后再次核对凭据所属环境和绑定。已管理凭据优先，未配置者保留 `SAND_AI_FEISHU_CHANNELS_JSON` 兼容读取；后台不得读回环境变量或已存的明文。保存凭据不创建 SandIAM grant，不执行数据库迁移或密钥变更。

后台 `GET /app/sand-ai/admin/channels/bindings/feishu-status?environment_id=&channel_binding_id=` 要求 `sand_ai:channel:binding:read`，只返回该绑定的 `last_verified_callback_at`、`last_accepted_message_at`、`last_callback_error_code`/`last_callback_error_at`、`last_delivery_state`/`last_delivery_at` 及 `last_delivery_error_code`/`last_delivery_error_at`；从不返回原始事件、消息正文、chat ID、凭据或外部响应。通过飞书验签且 token 有效才更新回调阶段；未知或签名无效请求不污染状态。托管凭据可在 SandIAM 签发前确定绑定并记录经验证的回调及后续失败；环境变量配置的消息需待正式身份与绑定核对后才记录。回信 Worker 的受控失败码独立记录，重试将 outbox 重新入队后仍能看到最近异常。状态记录故障不阻断原回调，后台展示“已验签”“已受理”“已送达”的分别证据，不把其中一个阶段推断为整条链路成功。

企业微信自建应用文本回调入口为 `GET|POST /api/sand-ai/v1/channels/{code}/wecom/events`。GET 必须校验 `msg_signature`、`timestamp`、`nonce` 和加密的 `echostr`，按 `EncodingAESKey` 解密并核对 `corp_id`，以 `text/plain` 原样返回挑战明文；POST 对加密 XML 的 `Encrypt` 同样验签、解密并核对企业 ID，只接受文本消息，按 `MsgId`、发送人、应用 `AgentID`、内容映射到现有渠道任务链。两个入口都拒绝未签名、过期、错误企业或无效 XML；POST 仅在 SandIAM 正式工作负载身份、当前渠道绑定、任务和加密回信 outbox 均固定后返回 `success`。后台 `POST /app/sand-ai/admin/channels/bindings/wecom-credentials/save` 以 `sand_ai:channel:binding:manage` 和 `Idempotency-Key` 完整保存 `{environment_id,channel_binding_id,token,encoding_aes_key,corp_id,agent_id,corp_secret,workload_credential}`；独立敏感请求中间件在登录/权限校验前清除请求体，路由不挂 SandAdmin `SystemLog`，响应和绑定列表只显示 `wecom_configured`。已托管凭据按渠道、环境和绑定加密保存，回调与 Worker 在 SandIAM 签发后复核归属；无托管记录时兼容按 code 读取 `SAND_AI_WECOM_CHANNELS_JSON`。`sand_ai_wecom_delivery` 加密保存收件人并异步发送；POST 发送结果不明确时标记 `uncertain`，不自动重发。后台 `GET /app/sand-ai/admin/channels/bindings/wecom-deliveries` 按环境与绑定分页读取任务、状态、尝试次数、受控错误码和时间，不返回消息、收件人、密文或厂商响应。上述源码仍待迁移、宿主安装、真实企业微信收发与页面验收，不得据此宣称渠道可用。
企业微信回信按单条不超过 2048 个字符、4000 字节拆分；当前任务输出最多 4000 个字符，拆分最多 8 条，超过上限明确失败而不静默丢弃正文。`sand_ai_wecom_delivery` 固定原回答摘要、总段数和下一待发段号，每条确认成功后先持久化进度才发送下一条；重试只从已确认进度续发。发送结果不明或已确认发送但进度无法证明落库时进入 `uncertain`，禁止自动重发该段。多段回信带段序号，完整回答仍保留在原受授权任务结果；后台 `wecom-deliveries` 增加 `part_count` 和 `confirmed_parts`，未准备的回答两项为 `null`，不返回正文或摘要。`081` 迁移仅是源码，未执行。
后台 `POST /app/sand-ai/admin/channels/bindings/wecom-deliveries/retry` 接受 `{environment_id,channel_binding_id,task_id}` 与 `Idempotency-Key`，要求 `sand_ai:channel:binding:manage`。仅当前启用且版本未变的绑定、终态任务和 `failed` 回信可重试；`uncertain`、`sending`、`sent` 均拒绝。重试清零领取次数和当前错误，但保留原回答摘要、总段数和下一待发段号，响应为 `{task_id,state:"pending",confirmed_parts}`；同键重放只返回原结果。操作记入独立的 `retry_wecom_delivery` 幂等账本及管理审计。`082` 迁移将操作码列扩到 32 字符并允许该新动作，仅交付源码，未执行。

后台 `GET /app/sand-ai/admin/channels/bindings/wecom-status?environment_id=&channel_binding_id=` 以 `sand_ai:channel:binding:read` 读取企业微信渠道的验签回调时间、已受理消息时间、最近回调错误码与时间，以及最近回信状态、时间和历史错误码与时间。管理页分别展示阶段；有效加密 GET challenge 可记验签，POST 在文本内容验签解密后记验签，任务与加密 outbox 固定后才记受理。未知或验签失败的请求不污染状态。托管凭据在 SandIAM 签发失败时可按已知绑定记受控错误；环境变量回退需待身份和绑定核对后记状态。状态写入故障不得阻断有效回调。回信发送结果不明确时，outbox 应进入 `uncertain` 而非自动再次发送；平台已确认发送但本地提交报错时先核对当前 outbox 状态，无法证明已写成 `sent` 才由原租约转为 `uncertain`。

后台 `GET /app/sand-ai/admin/channels/bindings/deliveries?environment_id=&channel_binding_id=&page=&limit=` 沿用 `sand_ai:channel:binding:read`，按绑定分页返回飞书回信的任务 ID、绑定修订号、任务/发送状态、发送次数、受控错误码和时间；不返回消息正文、chat ID 或加密信封。`POST .../deliveries/retry` 接收 `{environment_id,channel_binding_id,task_id}` 与 `Idempotency-Key`，要求 `sand_ai:channel:binding:manage`；仅当前启用且修订号未变化的绑定、终态任务及 `failed` 回信可由管理员显式重新入队。重试归零该次发送次数并保留原任务 ID，使飞书端继续使用同一稳定 UUID；Worker 发送前仍须重新验证 SandIAM、智能体、来源和绑定。已送达、发送中或旧修订回信不能重试，管理页不展示回信内容。

适配器可用 `GET /api/sand-ai/v1/channels/{code}/conversations/{conversationId}/messages?sender_id=&before_task_id=&limit=` 恢复会话消息。`limit` 默认为 20、最大 20；响应 `messages` 按任务 ID 降序，每项含 `query` 和原渠道任务读取响应 `task`，还有下一页游标 `next_before_task_id`。缺省游标从最新任务开始，下一页将游标作为 `before_task_id`。接口只查询当前绑定 revision 下原组织、应用、工作负载和会话/发送者范围的任务，并逐条复验任务、智能体版本与私有来源授权；任一轮授权失效则整页拒绝。

渠道适配器可用 `POST /api/sand-ai/v1/channels/{code}/conversations/{conversationId}/close` 结束当前连续对话，正文只含 `{sender_id}`，并传 `Idempotency-Key`。服务端要求同一工作负载同时具备 `sand_ai.agent.submit` 与 `sand_ai.task.read`，重新核验渠道绑定；有未终结消息时返回 `SAND_AI_CONVERSATION_BUSY`。成功返回 `{id,closed_after_task_id,idempotent_replay}`，不调用模型或产生费用。关闭标记与新消息提交使用同一会话锁；之后的新消息仍可使用原外部会话 ID，但只带入标记之后的成功轮次。旧任务保持只读且继续受当前绑定、智能体版本及私有来源授权约束。会话消息读取响应增加 `closed_after_task_id`，客户端可据此区分当前上下文和历史消息；分页仍包含旧轮次。

飞书与企业微信文本回调的源码候选支持精确原文 `/结束对话`：只在原协议验签、解密及当前绑定／正式 SandIAM 身份核验后，由解码器对未 trim 的原始文本严格比较。首尾空格、制表符或换行不能触发关闭；普通消息 `query` 仍沿原规则归一化。平台关闭固定适配器的平台常量、原会话和发送者范围，关闭前再次核验同一主体的 `sand_ai.agent.submit`、`sand_ai.task.read` 及当前绑定；有未终结消息仍返回 `SAND_AI_CONVERSATION_BUSY`，不隐式取消。

平台控制消息与普通任务共用消息幂等锁，并在会话锁和当前绑定行锁内检查互斥：同一 `MsgId`／平台消息号不能从普通任务改成关闭，也不能反向改作任务；重复关闭复用原关闭标记，不关闭之后的新消息。关闭只写已有关闭账本和审计，不创建 AI 任务、outbox、模型调用或平台确认回信；HTTP 沿用飞书 `{code:0}`／企业微信 `success`，不将控制操作计为任务受理。之后同一外部会话仅使用关闭标记之后的成功轮次。普通应用 close API 继续只作用于通用会话范围，不接受平台字段或指定平台身份。两平台关闭、授权拒绝、重投、并发和使用者可感知反馈均待真实验收；本段源码契约不证明真实平台已可用，5.5 仍未完成。

### 渠道原消息恢复与 Neuron 调用费用（2026-10-03 候选契约）

本段对应已冻结的机器人整包候选；PHP／SDK 接口与前端类型、构建检查不证明宿主已经加载或真实业务通过。真实工具执行、页面恢复及平台收发仍须按统一任务 5.4、5.5 验收；后续摘要与 token 流不属于本段已实现范围。

`GET /api/sand-ai/v1/channels/{code}/messages/by-request-key` 携原 `Idempotency-Key`、当前有效 SandIAM Bearer context，query 必须包含原 `message_id`、`conversation_id`、`sender_id`（各为非空字符串，最多 128 字符）。要求 `sand_ai.task.read`、`sand_ai.agent.submit`、`sand_ai.knowledge.query` 的实际上下文范围一致；返回既有 `ChannelMessage`：`{query:string,task:SandAiTask}`。按正式渠道派生算法定位原 `agent_run`，复核当前身份、绑定、会话、发布版本与私有来源授权，不提交任务或调用模型。缺失返回 `SAND_AI_RESOURCE_NOT_FOUND`，缺 key 返回 `SAND_AI_IDEMPOTENCY_KEY_REQUIRED`，字段错误返回 `SAND_AI_VALIDATION_ERROR`。

新内部冻结 Neuron 任务的 `SandAiTask.agent_invocations?` 为最多三条 `{request_id:string,state:string,latency_ms:number|null,error_code:string|null}`，按实际模型回合排序；由 invocation／dispatch／task step／deployment 联表核对任务、环境、组织、应用、工作负载及模型归属。尚未创建调用可返回 `[]`，旧任务不增加该字段。费用继续使用 `GET /invocations/{request_id}/cost`；数组存在时不重复累计兼容 `result.invocation`。步骤原 key 固定 task 与 round，不带 Worker attempt；unknown 或缺失／无效私有 receipt 不换 key 自动重发，已付结果只能在当前授权与匹配 receipt 下恢复。

`SandAiTask.embedding_invocations?` 为最多两条 `{request_id:string,invocation:SandAiTask['embedding_invocation'],usage_cost:SandAiTask['embedding_usage_cost']}`，仅由真实工具检索的模型索引 embedding 步骤和既有 usage／price 投影；同查询复用稳定子 key，改变查询以 task 与查询摘要派生，继续核对知识发布、步骤和四级归属。数组存在时替代兼容单字段累计。`invocation=null` 是尚无可确认调用，`usage_cost=null` 是费用未确认，均不表示 0；纯 lexical 检索为 `[]`，不构成收费 Embedding 验收。金额按币种分别累计，未知价格或币种保持未确认。

页面 sessionStorage 只保留公开绑定范围、会话／发送者／消息／任务 ID、原请求与关闭 key，不保存 Bearer、问题、答案或来源。当前身份或绑定变化立即清除已显示成果、费用并使旧请求响应失效。刷新后先按原 key 只读找回；任务确实不存在且浏览器没有原文时，需重新输入相同原文并明确确认同 key 提交；已知任务找回失败不创建替代调用。此通用 API 不将消息伪装为飞书或企业微信平台消息。

机器人官方摘要契约已冻结并安装到隔离宿主，尚未执行真实机器人摘要调用：`SandAiTask.summary_invocation?` 为 `null` 或一个与 `agent_invocations` 相同的安全实际调用摘要；`summary_coverage?` 为 `null` 或 `{version:1,state:string,context_turn_limit:number,history_task_ids:number[],covered_task_ids:number[],partially_covered_task_ids:number[],retained_task_ids:number[],excluded_task_ids:number[],omitted_prior_turn_count:number|null,closed_after_task_id:number}`。两条历史消息完整归入摘要才列 `covered_task_ids`，部分归入同时标记 `partially_covered_task_ids` 与实际保留集合；未加载的更早历史只报告已知轮数或 `null`，不虚构身份或覆盖。摘要是上下文，不代替本轮授权事实引用；原历史保持只读。独立摘要调用使用既有费用 API，唯一请求 ID 去重，与最多三次 Agent 工具模型回合分开标注。只摘要原冻结 context 范围，稳定 task／覆盖 key 不含 attempt；未知结果、缺失 receipt、覆盖变化或当前授权拒绝不换 key 重发，失败停止后续派发。摘要原文、来源闭包和私有协议不进入公开响应。原生流契约见下段，poll 进度不表示流式输出已完成。

### 机器人原生 token 流（2026-10-03，隔离宿主已安装，真实业务待验）

新任务内部冻结 `_agent_stream=true`，公开 `SandAiTask.streaming_enabled?:boolean` 表明该原任务是否支持原生流；旧任务不改为流式任务。`GET /api/sand-ai/v1/channels/{code}/messages/{taskId}/events` 只读订阅原渠道任务，query 为原 `conversation_id`、`sender_id` 与可选十进制 `after_event_id`（0–4096，默认 0），可使用相同值的 `Last-Event-ID`；两者冲突拒绝。携当前真实 Bearer，复用渠道任务读取的 task.read／agent.submit／knowledge.query、身份、绑定、会话、发布版本及私有来源复验，逐批额外核验已持久事件的完整来源闭包。每个HTTP订阅内，每项精确action／resolver／服务端资源引用首次取得正式资源授权；后续tick前后及事件发送前沿原context与authorization_id调用正式revalidate，仍重新解析当前资源事实、四级身份、有效期、撤权、网络与分级并保留审计，不再次创建调用额度。新出现来源资源须首次授权；连接局部状态不得跨HTTP、身份或context复用，普通任务GET保持逐请求授权。订阅不会提交任务或派发模型。

SSE `token` 事件的 id 是 task 内递增事件号，data 为 `{task_id:number,event_no:number,round:number,text:string,provisional:true,source_block_ids:number[]}`。只记录官方 SDK 实际 `TextChunk.content`，不输出工具参数、推理 chunk、原始厂商响应或摘要文本。`task` 事件为 `{task:SandAiTask}`；`error` 为受控 `{error_code:string}`；90 秒连接上限可发 `reconnect`。页面显示“生成中的预览”，仅正式 succeeded 任务的输出才是完整回复；任务失败、待恢复或断线仍沿原任务查询费用与结果，不能用已暂存文字冒充成功。

迁移 122 仅新增 `sand_ai_agent_stream_event` 私有事件表与索引。2026-10-03 已通过隔离宿主既有公开迁移入口正式执行，并读回该版本与冻结校验和；这不证明真实机器人流式调用通过。事件正文及来源闭包以现有 OutcomeReceiptCipher 加密并绑定真实调用、任务和事件号；最多 4096 个真实事件、每事件文本最多 8192 字节、每任务文本总计最多 262144 字节。越界立即报有界错误并停止，已确认的真实用量保留原计量路径；尚未取得真实 usage／完整 receipt 时费用保持未知，不补 0、不换 key 重发。文件派生清理同步清除对应任务事件。

恢复按事件号只读取已持久事件，不重跑模型；浏览器只在当前页保存预览与游标，刷新后可用原 task/key 重新从事件 0 读取，不在 sessionStorage 保存正文或 Bearer。当前身份／绑定变化或组件离开会取消读取并清除预览。SSE 发送缓冲上限 64 KiB，慢消费者或发送失败断开并清理 Timer，读取每批最多 32 事件；固定生产／持久化上限避免无界增长。慢消费者、断线／刷新、授权变化、真实原生 chunk、usage/footer 与 receipt/费用恢复仍须实际验收，poll 状态和完整回答拆分不构成该验收。

### 渠道任务来源回看 (2026-10-03)

`GET /api/sand-ai/v1/channels/{code}/messages/{taskId}/sources/{sourceBlockId}?conversation_id=&sender_id=&event_no?`

沿渠道任务读取的正式 workload Bearer、当前四级身份、发布版本/绑定/知识发布及任务域授权；另核真实来源文件的当前 `sand_ai.agent.run` 资源授权、分级、最新有效解析及正文摘要。客户端不能提交覆盖服务端 scope、file_id 或 locator 的字段。

未传 event_no 时只接受 succeeded 任务正式 result.source_references 的实际引用。event_no 为1–4096整数时只接受该任务、该事件的私有已封存原生流来源闭包；回复为预览引用，即使后来任务成功也不伪造最终成果归属。块未被对应成果/事件引用返回 `SAND_AI_RESOURCE_NOT_FOUND`；非法编号/参数沿 `SAND_AI_VALIDATION_ERROR`；封存闭包或正文/定位与当前来源不一致沿 `SAND_AI_SCOPE_SNAPSHOT_INVALID`；来源当前授权及事实不可用保留既有正式 IAM/`SAND_AI_INVOCATION_FACTS_UNVERIFIED` 拒绝。

DTO `{task_id,source_block_id,file_id,locator_type,locator,content,reference_kind:"result"|"stream_preview",event_no:number|null}`。仅安全来源正文与定位，不返回文件存储键、Bucket、下载 URL、prompt 或原始厂商响应。`Cache-Control:no-store`。服务端读取前后重验当前任务绑定、来源事实及授权，不启动模型、检索或计量，不修改旧任务及费用。每 HTTP 精确资源首次授权后正式 revalidate；新连接仍有独立授权，不称零 quota。

渠道页沿现有内存 Bearer 打开来源；来源正文仅组件内存，身份/会话/绑定/凭据改变即清空，关闭后清空。恢复待处理时“预览来源”明确区别于完整回复依据；不存入 sessionStorage。

### Skill 原上下文幂等与发送前拒绝恢复候选（2026-10-03）

以下补充既有 5.2.1 test/run 恢复契约，DTO 不新增字段，`prompt_contract_version` 及旧指纹保持原契约；新恢复行为尚未 HTTP／PostgreSQL／SandIAM／真实 Provider 验收，不能据此勾选 5.2 或 5.6。

案例创建、判定和草稿／发布／回退／撤销操作的原 key 重放，除原业务指纹外还核对持久完整 `consumer_context`（可信四级身份、声明作用域与操作者）；缺失或交叉上下文拒绝，不回填旧人员事实。Skill 模型结果不明（`SAND_AI_DISPATCH_AMBIGUOUS`）、回执无效／待封存或计量封存失败时，运行保留 `state=running`；仅在原租约 fence 内绑定已有 `invocation_id` 并保留 `error_code/error`，原 key GET 只读投影实际费用请求号，不抢租约或重复派发；成功恢复清除待恢复错误。

若 `state=failed` 且 `invocation_id=null`，GET 或原 POST 重试只有在冻结版本、输入、consumer、完整身份、固定提示消息指纹与现有不可变 `execution_scope=skill` 且 `invocation_id=null` 的发送前拒绝证据一致，并确认原 Skill 调用域不存在 Invocation 时才能读取／重放原错误。无可信拒绝证据仍返回 `SAND_AI_INVOCATION_FACTS_UNVERIFIED`；金额未知保持 `null`，不表示免费。

无 Invocation 的正式运行失败读取／回放新增一次当前 `sand_ai.skill.run` 的 Skill 资源授权（`sand_ai.skill`、持久 Skill ID、精确版本与当前 client），该次授权计入对应 grant 调用额度；既有 Invocation 路径沿原调用资源授权，不新增此次数。测试管理路径复用现有 `skill.test/read` 资源授权。

对应 `sand_ai.skill` 事实 resolver 的 `sand_ai.skill.run` 防线只接受启用且已发布的 Skill 和精确已发布 SkillVersion，继续验证真实工作负载的组织／应用／环境、持久 consumer 声明的 personal／team／organization 范围、该精确版本和知识来源的真实 data_class；不放宽 legacy 事实或默认来源。草稿、撤销或停用事实在 resolver 内返回 `SAND_AI_INVOCATION_FACTS_UNVERIFIED`。正式 Gateway 已有的生命周期检查仍优先保留原 `SAND_AI_SKILL_VERSION_REVOKED` 等对外错误，不将所有外部拒绝改成事实错误；管理动作保持原条件。该 resolver 候选仅通过源码与 PHP 语法检查，真实授权／数据库／HTTP 效果仍待验。

Skill 资源事实的外层与工作负载引用适配同时允许 `sand_ai.skill.run`；依然由正式 SandIAM 端口核对当前有效的组织、应用、环境与 workload client 四级引用，不能把适配动作白名单当作 grant 或人员认证。缺失正式端口、无效 client ID、未知服务、不受支持动作、停用引用或不一致事实仍沿用 `SAND_AI_INVOCATION_FACTS_UNVERIFIED`；最终 signed claims、live grant、服务动作、网络、受众、data_class、有效期和额度仍由 SandIAM 正式授权端口决定。本适配增量未完成真实授权／HTTP 验收。


### 5.3 Generic Agent 来源读取（2026-10-04 冻结）

`GET /api/sand-ai/v1/agents/tasks/{taskId}/sources/{sourceBlockId}` 仅读取同一已接受任务的持久结果或事件来源；可选 query `event_no` 是 1..4096 正整数，未知字段拒绝。请求必须有当前 `sand_ai.task.read`、`sand_ai.agent.submit`、`sand_ai.agent.run`；发布 Agent 额外要求 `sand_ai.knowledge.query`。当前身份范围、原运行、定义、知识版本和私有来源在返回前再次复验；Channel 任务被拒绝，沿其既有渠道来源入口。

成功内容仅含 `task_id, source_block_id, file_id, locator_type, locator, content, reference_kind, event_no` 的持久来源投影，响应 `Cache-Control: no-store`，不返回存储路径、对象 key、身份封套或原厂商响应。当前身份无权、资源不可见、参数非法、来源在读取期间变化分别沿既有授权拒绝、`SAND_AI_RESOURCE_NOT_FOUND`、`SAND_AI_VALIDATION_ERROR`、`SAND_AI_SCOPE_SNAPSHOT_INVALID`。此 GET 不推理、不恢复写入、不产生新费用。

### 5.4 Generic Agent 持久流与会话候选（2026-10-04 冻结，待实现及真实验收）

`GET /api/sand-ai/v1/agents/{code}/tasks/{taskId}/events` 仅读同一任务。`Last-Event-ID` 或 query `after_event_id` 是 0..4096 十进制游标；两者同时提供必须一致。当前权限、原运行及全部来源再次复验，定义 code 必须匹配且不能是 Channel 任务。事件仅提供安全已持久的 token `id,event_no,round,text,provisional,source_block_ids` 和 task/reconnect/error；历史未标记 `text_policy_version=1` 的原始流拒绝为 `SAND_AI_CAPABILITY_UNSUPPORTED`。最长90秒、15秒心跳、64KiB发送缓冲；断线重连不创建任务、不重新计费，无法确认完成的 Provider 回执沿原未知状态等待恢复。

既有发布 Agent submit 可选 `conversation_id` 为匹配 `[A-Za-z0-9][A-Za-z0-9_.:-]{0,95}` 的 1..96 字符，显式 null 拒绝；缺省保持旧单任务及其冻结 digest，不补 null。历史上限4个，任务 JSONB 只冻结可信身份、完整定义/知识快照、原历史任务 IDs 和已省略数量；恢复不取新历史，未完成/待复核/已清除任务不进入历史。会话锁用于并发，原幂等键重放在 busy 判断前找回原任务；逐来源仍复核当前授权。候选 `GET /api/sand-ai/v1/agents/{code}/conversations/{conversationId}/tasks` 支持正整数 `before_task_id` 及 `limit=1..20`，仅读自己范围普通任务投影与下一游标，不输出内部封套。此冻结不代表功能或业务闭环已完成。


### Frozen source 0.1.9: persisted Agent streams and legacy deployment readback

Published Agent SSE is `GET /api/sand-ai/v1/agents/{code}/tasks/{taskId}/events`; session history is `GET /api/sand-ai/v1/agents/{code}/conversations/{conversationId}/tasks`. Both require current task.read, agent.submit, agent.run and knowledge.query in the exact scope. Current definition, publication and private sources are revalidated. Nonterminal tasks also revalidate their original execution context; succeeded, failed and canceled history is readable with current authorization even if its original context naturally expired. Original encrypted context is never renewed or replaced for reads.

SSE token data is `{task_id,event_no,round,text,provisional:true,source_block_ids}` with task/reconnect/error events. Only version-1 masked persisted text is streamed; historical unmarked text returns `SAND_AI_CAPABILITY_UNSUPPORTED`. Current authorization and source checks apply on reconnect; reads do not submit, redispatch or charge. Cursor bounds and conflict handling, 90-second limit, 15-second heartbeat and 64KiB backpressure remain as previously frozen. Public agent task output is also masked for historical tasks.

Optional published `conversation_id` preserves the legacy request digest when absent; explicit null is rejected. The trusted task input freezes at most four prior task IDs and omitted count. Public tasks expose `conversation_context={conversation_id,history_task_ids,omitted_prior_turn_count,context_turn_limit}`; history responds with `conversation_id,tasks,next_before_task_id`. Recovery uses those frozen IDs rather than discovering new history. AdHoc events and their UI are a later candidate and are not loaded in this batch.

Existing deployment/read DTO accepts a narrow read-only API1.17 onboarding tested/published revision only when its original verification audit, full frozen snapshot digest, IDs, timestamps, usage and provenance chain agree. Published revisions additionally require a complete matching publication chain with only the supported status transition. Missing or inconsistent provenance remains rejected. This compatibility does not rewrite old verification fields or call the provider again.

Budget reservations require a supported typed official input-token proof bound to the final SDK body and frozen route. The meeting factory reads an existing unique successful tokenizer ledger under the original task/capacity snapshot; it sends no tokenizer request. Unsupported request shapes have no fabricated proof. This source connection does not prove a tokenizer fee bound, universal model support, lowest-cost completion, or actual business acceptance.


### Frozen source 0.1.10: capacity bounds, actual-source retrieval and AdHoc events

Budgeted new chat and embedding calls reserve a compiled, reviewed official protocol capacity from the final SDK body and frozen published route. `budget_capacity_attestation` is an optional deployment configuration object with exactly `version,source_code,source_sha256,definition_sha256`; no caller supplied capacity number is accepted. Registration must match the actual remote model and model type at save, verification and publication. Invalid registration fails closed; publication retains its existing CONFIGURATION_DRIFTED wrapper.

Flash plain text messages use the conservative full input capacity 1048576 plus the actual final max_tokens output bound. Embedding uses the registered single-string protocol and compiled model capacity. Unsupported tool/schema/body formats remain rejected for active budgets. No tokenizer request is added; previous estimated token counts cannot lower this bound. Full capacity reservations retain original price/body/route/source evidence. Both final prepared claim paths revalidate the immutable original reservation before dispatch; insufficient old unsent prepared calls fail BUDGET_PREPARED_REVALIDATION_REQUIRED. Started outcome recovery retains its original held accounting and does not redispatch.

Retrieval search/source entry verifies current identity and declared action, authorizes every actual returned source file, revalidates the original authorization IDs and verifies the current identity before return. It does not add a synthetic client-null resource check or derive file classification from a grant. Empty responses also verify the current entry grant without fabricating a resource or consuming resource quota.

AdHoc SSE is `GET /api/sand-ai/v1/agents/tasks/{taskId}/events`. It requires current task.read/agent.submit/agent.run and actual-source authorization in the exact scope; published-definition and channel tasks are excluded. Published events retain their additional knowledge.query/current definition/publication checks. Both use the frozen version-1 masked token projection and cursor/heartbeat/backpressure rules. Nonterminal reads additionally revalidate original execution context; terminal history uses current authorization. AdHoc remains one run without a published conversation identifier.

Streaming clients hold bearer input only in memory, recover with GET and the same cursor, and never resubmit on reconnect. Current IAM/credential/grant/quota denial clears stale result, provisional preview, history and costs. Actual native PostgreSQL definition booleans are bound as explicit database boolean text; public DTOs and idempotency fingerprints remain unchanged.


### Frozen source 0.1.11: scoped Skill reads, task clocks and measured cost adjustments

Scoped Skill index and sources return current_page, per_page, data and next_cursor.
before_id is an optional positive integer; next_cursor is only the last authorized
returned ID, or null for an empty page. It does not assert another page exists.
Scoped responses expose no total or has_more. Existing administrator pagination
retains its total. Skill read/manage authorization uses the real pinned knowledge
publication/version plus the exact workload owner through sand_ai.skill_knowledge;
knowledge.query remains a separate actual publication authorization. Every
returned resource is authorized and the original IDs/current identity are
revalidated before response, including the current entry check for empty results.

New task business available_at/started_at/completed_at writes and availability
comparisons use explicit UTC. Existing RUNNING lease, heartbeat, lock and stale
lease comparisons retain the coherent host-local TaskLeasePolicy contract.
Manual retry clears started_at before the next real claim; old terminal history,
IAM expiry, original receipts, provider requests and global PHP timezone are not
rewritten. Meeting recovery requeue and node business timestamps use the same UTC
business clock. Agent conversation scope hashing restores the official fixed
seven-field definition ordering so PostgreSQL JSONB ordering cannot change the
existing scope digest.

Embedding-3 official capacity accepts only the actual neuron_embedding_batch@1
protocol, final model/input UTF8 string list of 1..16 entries, float format and no
dimensions. Each entry reserves the compiled 3072-token input capacity; entry
count, actual protocol, source, body, route and price are frozen and revalidated
for unsent prepared dispatch. Consumers pass the exact final SDK body, route and
protocol through the optional providerRequestProtocol argument. Legacy request
digests are preserved and cannot fabricate this capacity proof.

Administrator refund adjustment endpoints are POST /app/sand-ai/admin/usage/adjustment/refund,
GET /app/sand-ai/admin/usage/adjustment/index and /read. They require the existing
platform superadministrator and newly declared financial API permissions; no role
is automatically granted these permissions. Refund input is exactly environment_id,
usage_event_id, evidence_id, evidence_locator, provider_refund_reference,
refund_amount (positive decimal string), currency, provider_refund_confirmed=true,
idempotency_key and note. A real matching private evidence attachment and the
original reconciled bill are required. Append-only cumulative refunds are locked
per original event under READ COMMITTED and cannot exceed that reconciled billed
amount. trust_basis is operator_reviewed; amount_basis is reconciled_bill.
Evidence consistency does not establish provider authentication. Existing cost
may expose adjustments with gross/refund/net billed amounts; no event yields
null and unknown billing is never displayed as zero. Original usage, pricing and
bill remain immutable. Budget reservation release is not a refund.

Speech deployment configuration accepts speech_response_format mp3 or wav beside
verification_voice; missing format preserves the MP3 protocol and old fingerprint.
Explicit null is invalid. Public submit text/voice is unchanged. Private binary
downloads use the original frozen run's audio/mpeg with speech.mp3, or audio/wav
with speech.wav. A changed current deployment does not alter old prepared runs or
justify replay of an unknown provider outcome.

The installed operator CLI separately exposes sand-ai:security status-tools and
initialize-tools. Existing four-category initialization remains unchanged. Missing
tool material can be initialized only before relevant tool history and with the
normal private owned-file/history lock gates. The tool backup is private and
separate; no keys appear in status output. Normal host reload and endpoint/IAM
acceptance remain separate runtime gates.


### 冻结候选 0.1.12：显式本地会议规划与未确认结果

会议执行现有 register/state/read 入口增加显式本地规划选择。route.planning_drivers 的 code 为 `neuron_delimiter_splitter@1`，附 SDK 包、版本4.0.2、max_length12000、word_overlap0、min_length0，以及 token_count:null、token_count_state:not_measured、counter_request_count:0。

本地 capacity 严格只接受 planning_driver、context_window_tokens、input_token_limit、max_tokenizer_requests、capacity_source_url、capacity_source_sha256；planning_driver 必须匹配上述值，max_tokenizer_requests 必须为整数0，不带 tokenizer_driver。远程计数 variant 保留原字段与精确回执规则。两者沿用 capacity@2 注册、原路由摘要与版本并发校验；本地启用额外核对迁移127 checksum。无法核对时拒绝，不从缺回执自动改用本地模式。

本地规划直接调用 Neuron DelimiterTextSplitter，规划证据保留原文位置、消息摘要和完整分区，不声称精确 Token 或降低资金输入上界。正常任务读取通过当前 IAM 与私有来源校验后，只有实际确认没有 counter 请求，才返回 meeting_token_count_cost 的 request_count:0、pricing_state:not_applicable、token_count:null、token_count_state:not_measured；metered_amount/currency 保持null，不将“不适用”解释为已核对的零费用。模型生成仍按最终SDK请求正文、冻结路由、官方完整容量与冻结价格单独预占。

材料 structured_workflow 的 recovery_pending 任务增可选 provider_outcome_state:null|'unconfirmed'。仅当前已授权任务、环境及 material run 绑定一致，且实际关联 dispatch 为 ambiguous、其 invocation/provider/model 绑定核对一致，才返回 unconfirmed；不从 recovery_pending 或 LOCAL_RECOVERY_PENDING 推断外部调用状态。不返回 Provider 原文、端点、Key 或内部对象定位。费用未知时保持原成本 DTO 的null/不可用，提示只刷新现有任务，禁止重新提交猜测结果。

已准备但未派发的预算调用在最终claim比较当前适用策略集合与原冻结预占集合。策略ID或revision变化时明确拒绝并按原未派发路径释放，不静默新增预占、不改价格或请求正文。已派发、未知或已封存调用继续使用原冻结事实恢复。检查采用同scope锁与严格 READ COMMITTED，不依赖旧事务快照。

Operator 私有 native stream trace 默认为关闭。只在明确环境匹配的本轮正常配置中开启；记录序号、TextChunk 字节区间及是否跨现有遮盖边界，不记录正文、摘要、Key 或原厂响应，不返回调用者界面。文件和目录权限、inode/owner/nlink及累计大小核对失败不改变业务结果；真实跨chunk与费用验收仍独立留证。


### 冻结候选 023：受管 MCP 稳定协议与原回执失败收口

受管 MCP Streamable HTTP 的 `protocol_version` 只允许 `2025-11-25` 或 `2025-06-18`。初始协商默认请求仍为 `2025-11-25`；SDK 正式协议协商回调得到实际版本，后台新发现快照保存该版本，快照摘要包含它，后续请求头使用同一实际版本。其它版本在初始化阶段拒绝，不能继续目录分页或工具调用。协议版本来自受控服务端协商，不增加应用请求或连接创建的猜测字段。

已有快照继续按原冻结版本和原摘要恢复，原 `2025-11-25` 运行的请求指纹与回执认证上下文不变。新运行在已有 `execution_snapshot.wire_contract` 中冻结实际协议；传输前须与其引用的不可变发现快照版本相同，不能采用当前连接配置或重新协商结果静默更改旧运行。`McpSnapshotPolicy` 的末尾协议参数缺省为 `2025-11-25`，保持旧摘要字节；新版本摘要须显式传入实际协商版本。迁移 `202610040128_mcp_stable_protocol_profiles` 只扩展原协议 CHECK 为上述两值，默认值和旧迁移校验和保持。实际安装、发现、运行及恢复仍各自验收。

服务器进程可选 `SAND_AI_TOOL_PINNED_HOST` 与 `SAND_AI_TOOL_PINNED_IP` 成对配置受管工具的公网连接绑定。它们不属于连接创建或应用请求字段，与 `SAND_AI_PROVIDER_*` 分开；部分或非法配置拒绝解析。匹配主机后仍经 ToolEndpointPolicy 的公网地址、HTTPS 和443端口校验，受控传输保留 TLS/SNI，禁止代理、重定向和请求失败后自动换目标。正常 DNS 被 FakeIP 干扰时，运行负责人须凭有时间与来源的公开 DNS 证据显式复核地址，保存私有配置后通过正常生命周期加载。工具密钥 CLI ready 不表示 Worker 已加载，也不表示 MCP 业务就绪。

Published Agent 的检索来源使用精确冻结定义、知识发布及版本的正式 `sand_ai.knowledge.query` 授权，逐块核对当前成员、解析版本、定位与摘要；不借来源所有者的调用身份，不扩大通用 AdHoc 或材料直接文件所有权规则。返回前重验原授权ID与当前身份。原任务恢复仍保留其执行封套。

普通 Neuron Agent 的过期已知回执收口只处理原始加密封套已认证且已过期、无活动租约、所有实际子调用均已有真实成功回执及不可变计量的任务。正常 Worker 在原运行入口执行此检查；满足条件只写 FAILED 状态与错误元数据，保留原 input、授权封套、回执、用量和费用。它不重新执行 SandIAM 远端授权、不续签、不发布结果、不派发 Provider；未知、坏封套、四维不一致或回执未齐时拒绝该收口。实际 task21 终态、审计与费用不变须在正常安装后验证。

### Managed document tool adapters

- The server registry may freeze a code-owned optional `adapter` with `kind` equal to `document_search@1` or `document_fetch@1`. Its only configuration fields are the actual remote input/output schemas, `argument_map`, exact public `source_hosts`, and search field mappings for items/title/content/URL. An absent remote output schema stays null. This is not an application request field or an arbitrary user-supplied mapping.
- New local tool codes are `external_document_search` (bounded local query/limit, remote query projection) and `external_document_fetch` (local URL). The existing `external_search` definition, defaults, canonical hashes and strict standard result contract remain unchanged. Definitions with an adapter include its complete frozen configuration in registry, item and snapshot digests.
- Remote schemas must match the frozen profile exactly. SDK ToolPropertyFactory validates representability before a call; the local closed and bounded schema remains authoritative, with a verified flat required argument projection. A missing, changed or unsupported schema fails before invocation.
- Sources use only returned document content or the fetched content. Each URL must match an exact profile host and HTTPS port443 without userinfo or a query; document bytes and result counts retain bounded limits. The content hash is computed from the actual provided content, never an invented source version. A fetch without a returned title does not claim a server title. An empty search may return an empty result and citations.
- The ordinary Tool result/citation DTO remains the public boundary. No raw remote result, session ID, endpoint secret, server pin or adapter field is exposed. Protocol negotiation, raw catalog/source mapping evidence, normal installation and real business invocation are distinct acceptance layers.

### Legal output contract@2

- New legal runs freeze the internal `legal_output_contract@2` marker, strategy, schema and canonical schema digest alongside the exact published legal profile. Public submission fields and the existing `sand_ai.legal_analysis/1.0.0` artifact shape stay unchanged; callers cannot supply this internal marker or a response format.
- The actual SDK request uses the supported output strategy and a schema with positive integer source-block IDs, fixed profile draft kind, `review_required=true` and closed objects. The final request body, route, output settings and full financial capacity are bound through the ordinary canonical dispatch path.
- Neuron's JSON extraction, deserialization and validation operate on a single complete JSON document; business validation receives the original scalar types and source-block references. Source ownership, current authorization, frozen versions and provenance are still mandatory.
- A known paid result that violates schema or provenance may terminate the legal run with `SAND_AI_SCHEMA_UNSUPPORTED` or `SAND_AI_ARTIFACT_PROVENANCE_INVALID` only after matching its original receipt, invocation, dispatch, task lease, immutable usage and frozen run. No artifact is published and no Provider request is repeated; the actual fee and original receipt remain.
- A legacy run without the new marker keeps its original frozen body and validation path. It is not silently converted to@2, re-signed, repriced or redispatched. Source acceptance of@2 does not make the old run3/task22 a successful legal result.

## Scoped Skill 历史费用只读入口（2026-10-04 冻结候选，未安装）

消费后端可用原稳定业务键查询历史费用：

- `GET /api/sand-ai/v1/skills/{code}/versions/{version}/tests/by-request-key/cost`
- `GET /api/sand-ai/v1/skills/{code}/versions/{version}/runs/by-request-key/cost`

查询仅接受必填 `request_id,scope_type,scope_ref,actor_ref`，无请求体。`request_id` 为原测试 POST 的 `Idempotency-Key` 或正式运行 POST 的原 `request_id`，不是 Invocation ID、服务端转换键、运行 ID 或审计号。字段验证沿既有 Skill 规则；未知字段、缺失字段或无效类型返回 HTTP400 `SAND_AI_VALIDATION_ERROR`。

入口认证当前 SandIAM 工作负载上下文，动作仅为 `sand_ai.usage.read`。服务端根据当前可信组织、应用、环境、工作负载客户端、声明操作者和作用域，核对原持久 SkillRun 的完整 `consumer_context`、固定版本、原键与输入指纹，再从原运行关联解析 Invocation；不接受调用方提交 Invocation ID。Invocation、Dispatch 和已有 UsageEvent 必须匹配原 Skill、版本、运行、操作类型和可信四维。测试入口只接受原 `run_kind=test`，运行入口只接受原 `run_kind=workload`，二者不得互换。

当前费用资源授权复用 `sand_ai.invocation` 及服务端解析的真实正整数 Invocation ID、真实分类；返回前使用同一个 `authorization_id` 正式重验，并重查原运行的归属与链接。无新增 IAM 动作、分类回退或自动 grant；不重复授权消耗额度。费用读取不要求当前测试、运行、成果读取或知识来源动作，也不激活已经撤销的 Skill。

成功 `data` 复用既有 Invocation 费用 DTO，包括真实未知/null、计量、账单核对与退款摘要。固定版本发布、弃用或 Skill 撤销本身不阻止合法原消费者读取保留费用；元数据和原归属证明仍须存在。入口不返回或恢复成果、输入、instructions、引用块、回执正文、完整执行快照或消费者身份，不派发模型、不接管租约、不写终态。原成果读取与授权生命周期保持既有契约。

不存在或不属于当前消费者的 Skill、版本、原键或操作类型返回 HTTP404 `SAND_AI_RESOURCE_NOT_FOUND`；原运行尚无关联 Invocation 时也返回该404，不能显示为免费。原归属、关联或指纹证明缺失/无效返回 HTTP403 `SAND_AI_INVOCATION_FACTS_UNVERIFIED`；当前 IAM 动作、分类、撤权或过期错误沿既有错误码。历史 legacy 行缺少可信 scoped 证明时失败关闭。

消费应用的历史费用页必须由自身当前身份和费用权限构造声明与原键，直接调用本入口，不先依赖旧成果 GET 取得调用号。个人、团队人员与成员权限仍归消费应用。源码/API 候选与正常安装后的跨归属、发布/撤销费只读、费用不可变及真实页面验收分别留证。

## Material conflict original-file field location（2026-10-04 冻结候选，未安装）

材料成果的每个 `conflicts[]` 可增加只读可选 `field_location`。原 `field`、来源、成果状态及费用保留，调用方不得提交或覆盖此对象：

```ts
field_location?: {
  version: 'file_result_fields@1';
  state: 'verified' | 'unconfirmed';
  scope: 'file_result' | null;
  references: Array<{
    file_id: number;
    field_path: string;
    label: string;
    value: string;
    source_block_ids: number[];
  }>;
};
```

`verified` 仅表示原文件字段定位和来源对应已核对，不表示模型事实、冲突或业务审批已确认。前端显示各原文件的标签、原字符串值和来源，并继续标注“待核实”；不得将旧 `field` 裸指针当作汇总成果数组中的正确地址。

服务器只在当前已授权、按既有固定文件/解析版本/摘要/来源规则校验的 sealed `file_result` 原 payload 中，按原冲突 `field` 查找原 leaf。返回的每条引用必须精确匹配该 leaf 的既有 `field_sources`/citations 与冲突 source participants，属于同一原运行的固定文件；至少两个不同文件须提供相同业务标签、不同原字符串值，且完整来源并集与冲突参与来源一致。现有响应前来源授权与重验保持；不接收 caller 提供的 file、path、value 或 scope 作为证明。

不能解析 merged artifact 指针、按当前汇总数组猜新索引、模糊推断字段或把缺失 scope 当作当前数组。无法完整证明时返回 `state='unconfirmed',scope=null,references=[]`，保留原成果/费用；旧实现缺少整个可选字段时，消费端也按定位未确认处理。读取投影不得修改旧 artifact、模型回执、输入/输出 fingerprint 或财务事件。新成果的 Merger 使用相同原文件规则保存字段引用；旧 OutputValidator/CrossValidator 的归一化与 fingerprint 不改变。

这是现有材料读 DTO 的增量，不新增 API route、IAM action、数据库迁移或模型请求。精确固定候选的源码/资产、正常加载、既有实际原文件金额100/200与来源80/81投影，以及真实受影响页面分别验收；此文档冻结不代表运行通过。


### 已发布聊天部署的工具与结构化输出容量证明

- `budget_capacity_attestation` 继续使用既有四字段 `version/source_code/source_sha256/definition_sha256`；`version` 保持 `official_capacity@1`。新增受管源码 selector `bigmodel_flash_chat_wire@20261004`，其内部协议为 `text_messages_tools_schema@1`。本轮不增加公开请求参数。
- 旧 `bigmodel_flash_text@20261004` 的纯文本协议、旧回执及旧 prepared 事实保留。新 selector 只通过正常 deployment save/read/verify/publish 冻结到新 revision，不能静默修改旧部署、Skill version 或已派发正文。
- 新证明必须使用真实 SDK 最终 wire body 的完整摘要和精确已发布 route；工具声明、tool choice、assistant 工具调用、tool result、结构化 response format 均必须通过受管严格形状及边界校验。不得删除这些字段后以纯文本消息冒充最终正文，也不接受 caller 的 token/count 估算作财务上界。
- 每实际模型调用按独立官方完整输入容量及实际冻结输出上限预占。工具 schema、工具结果、结构化输出规划信息不降低容量；不宣称本地分词已取得实测 token 回执。实际 prepared/final claim/恢复必须核对同一 body、route、selector、协议、官方证据及原定价 revision。
- 原 embedding 容量协议及四级 scope、当前策略检查、unknown 回执 hold 与正常未发送释放规则保持。功能源码、资产、正常安装与实际业务验收分别留证。

#### 未发布后继：独立知识来源正文读取

`GET /api/sand-ai/v1/knowledge-bases/{code}/versions/{version_no}/sources/{source_block_id}` 读取一个当前仍有效的知识来源块。`code` 为消费环境内发布别名；`version_no`、`source_block_id` 均为规范正整数，分别对应查询引用的 `knowledge_base_version` 和 `source_block_id`，不是数据库版本 ID 或工具来源引用。入口不接受查询参数，不接受调用方声明的归属、数据分级或存储位置。

使用当前已验证的 SandIAM `sand_ai.knowledge.query` 身份、消费四级归属及实际版本数据分级，按当前发布关系取得一次正式资源授权。仅当前启用、已发布知识库的当前已发布版本可读；旧版本号不能用于读取历史已撤销来源。服务端核对整个冻结版本的来源成员、来源归属摘要、最新成功解析、正文摘要和定位一致性，并锁定发布关系及版本；返回前复读来源并以同一 `authorization_id` 正式重验当前授权，不新建第二次业务授权。发布撤销、停用、版本切换、来源删除、解析或归属/分级漂移均拒绝整个响应，不回退到同环境私有文件读取。

成功 DTO 仅为 `{knowledge_base_code:string,knowledge_base_version:int,file_id:int,parse_id:int,source_block_id:int,locator_type:string,locator:object,content_sha256:string,content:string}`。标识和定位沿用查询引用，`content` 为该来源块完整已校验正文，`content_sha256` 为冻结正文的 SHA-256；不含查询分数、对象 key、Bucket、私有 URL、向量或厂商响应。成功与失败响应均设置 `Cache-Control:no-store`。该 GET 不启动检索、Embedding、聊天、任务或费用计量；首次资源授权仍沿正式 IAM 配额与审计语义，不能称零 quota。

错误沿现有响应壳与 HTTP 400：非法路径整数或额外查询参数为 `SAND_AI_KNOWLEDGE_VALIDATION_ERROR`；发布别名不可用、版本号不是当前发布版本或来源块不属于该版本为 `SAND_AI_RESOURCE_NOT_FOUND`；冻结快照、正文、定位或最新解析失效为 `SAND_AI_KNOWLEDGE_SOURCE_DRIFTED`；身份、当前服务动作、分级和撤权错误沿正式 IAM adapter 原稳定码。失败响应不含来源正文或位置。此段仅冻结未发布后继契约，不表示当前 0.1.17 已安装该端点或真实 HTTP 已通过。


### 3.2 官方聊天验证适配（后继28冻结）

聊天模型草稿验证与临时手动验证复用已登记 Neuron4 的官方聊天能力，沿原单次受控 transport 发出实际最终正文；正文摘要、SSRF/TLS、固定超时、HTTP错误、计量完整性与未知结果恢复保持。发现模型的专用协议沿既有薄适配。部署草稿验证固定公开测试消息和最多1024输出；其普通聊天通过不证明 Skill ZAI 结构化输出成功，业务版本仍须冻结实际输出策略并以正式调用另验。SDK接入和零网络捕获不等于真实供应商或应用验收。


### 未发布后继：Agent 受管只读工具选择（迁移129，仅源码候选）

Agent 草稿和按次组合请求新增可选 `readonly_tools`，缺省空数组，至多2项。每项闭合为 `{code:string,version:positiveInt,business_scope:string}`，code沿正式ToolRun规则，business_scope为1–128位既有字母数字及`_.:-`范围。精确code/version组合不得重复。原 `tools:['retrieval.search']` 兼容且知识检索仍必需；旧版本缺此字段时保持原摘要，不回填旧版本/任务。

选择仅接受当前环境已绑定、已发布、effect_class=read_only且无危险确认动作的Tool精确版本。复用现有后台 `/app/sand-ai/admin/tools/available` 列表；管理员选择不是应用授权。草稿/已发布版本保存完整服务器解析的不可变绑定、Schema、连接修订、MCP registry/item/snapshot/protocol、响应和超时边界。上述私有快照不由调用者提交，不向公开DTO返回。模型仅填写冻结input_schema的input，不得选择版本、business_scope、目标地址或凭证。已有工具Schema中受控公开文档URL输入仍受code-owned source_hosts约束，不能作为连接地址。

后继Task DTO可选 `readonly_tool_runs:[{run_id,code,version,state,review_required,metering}]`，run_id/version为正整数，state沿正式ToolRun状态，review_required布尔，metering为null或既有 `{unit:'external_request',quantity:nonnegativeInt,pricing_state:'unpriced',metered_amount:null,reconciled_amount:null,currency:null}`。仅投影真实绑定当前任务的子工具记录，不返回参数、vendor原文、秘密URL或密文。不存在记录时空数组；旧Task缺字段兼容。数量已计量不表示免费，费用未知保持null。

工具来源独立于 `source_block_id`：从该真实子工具已有 `GET /api/sand-ai/v1/tool-runs/{run_id}` 读取安全规范化结果与citations。后继Agent子工具读取在原tool.read之外还正式重验当前parent task.read，拒跨caller/环境/作用域；返回前再次重验。仅允许受控公共文档引用或不透明来源编号及既有locator/source_version/content_hash，禁止把工具引用伪造成私有材料来源块。

Worker使用父任务原加密上下文、原IP与稳定requestID，经正式SandIAM requireSignedContext验证tool.run真实动作和四级/exp一致，再以真实ToolRun资源、实际scope/data_class执行stable authorizeSigned及每次派发/返回revalidate。不得fakeRequest、手工扩IdentityContext.actions、重签父身份或替换原封套。子ToolRun有typed父TaskRun/TaskStep FK及原decision；恢复同一子请求重验原decision，派发未知不能换授权或请求键。

SDK执行复用Neuron4 Tool/ToolPropertyFactory/Agent/ToolNode和既有ToolRun执行核心、收据、租约、计量。固定工具总执行界限及模型轮次，不自动重试/并行外部请求。TaskStep记录实际工具选择和状态，模型后续失败不重复已有工具费用。TaskGateway重试门必须核真实子工具未知派发/待收据，`SAND_AI_TOOL_FINALIZATION_PENDING`不得触发新模型轮或新工具键。

错误沿既有正式码：`SAND_AI_VALIDATION_ERROR`（闭合字段/数量/版本/Schema参数错误）、`SAND_AI_AGENT_TOOL_FORBIDDEN`（不允许的Agent工具）、`SAND_AI_TOOL_VERSION_NOT_PUBLISHED`、`SAND_AI_TOOL_NOT_BOUND`、`SAND_AI_TOOL_CONFIGURATION_DRIFTED`、`SAND_AI_SCOPE_SNAPSHOT_INVALID`（父子/快照不一致）、`SAND_AI_TASK_LEASE_LOST`、`SAND_AI_TOOL_DISPATCH_AMBIGUOUS`、`SAND_AI_TOOL_FINALIZATION_PENDING`，IAM拒权保留原正式错误码。任何源码通过或零网络SDK捕获均不代表正式ToolRun/Agent运行和费用闭环。

后继新绑定执行快照 `agent_readonly_tools@1`：检索最多1次、每项只读工具最多1次、工具执行总数最多3次、模型最多4轮、每轮输出上限512，无并行工具调用、无隐式重试。无绑定的历史版本及任务保留原2次工具／3轮模型协议，不回填。每轮和工具的实际计量分别进入报价与预算边界，新4轮案例必须重新取得对应报价和许可。

### 未发布后继：智能体配置版本历史与回退

- `GET /app/sand-ai/admin/agents/definitions/versions?environment_id&code&page&limit`：沿 `sand_ai:agent:definition:read`，page≥1、limit1..100；返回既有分页壳。每项 `{version_no,state,snapshot_sha256,created_at,is_active,config}`，state为draft/published，config为既有安全配置及可选readonly_tools。草稿仍可修改，published不可变；不返回私有tool binding、厂商秘密或操作原键。
- `POST /app/sand-ai/admin/agents/definitions/rollback`：沿manage权限，body严格 `{environment_id,agent_definition_id,version_no}`（正整数）+Idempotency-Key≤128。仅同definition曾published且published_time非空的版本；当前definition必须published/status1。原子切换active_version_id并保留原版本、原任务，沿原operation账本及admin审计。返回既有mutation DTO `{agent_definition_id,agent_version_id,code,version,state,snapshot_sha256,idempotent_replay?}`。
- 回退恢复定义配置。历史model代码及KB别名按执行时当前环境可用路由与已发布内容解析；不声称还原历史模型路由或历史知识库正文。新readonly版本的精确工具binding/schema/MCP仍须当前可用且未漂移。旧任务继续原快照及active-version失效策略。
- Task DTO新增可选 `agent_definition_version:{code,version_no,snapshot_sha256}`，只从原持久绑定version_id对应不可变版本及原snapshot复核得出，不由当前active版本推导；AdHoc无此字段。新增字段不修改历史任务input/binding。
- 使用已有错误码：输入错误VALIDATION_ERROR；跨环境/不存在RESOURCE_NOT_FOUND；disabled/draft/未曾发布RESOURCE_CONFLICT；原键body冲突IDEMPOTENCY_CONFLICT；不可用模型MODEL_UNAVAILABLE；KB沿原Knowledge错误；工具沿既有TOOL_VERSION_NOT_PUBLISHED/TOOL_NOT_BOUND/TOOL_CONFIGURATION_DRIFTED/MCP_SNAPSHOT_NOT_TESTED；冻结不符SCOPE_SNAPSHOT_INVALID。所有完整码保留SAND_AI_前缀。



## 会议结构化输出契约（2026-10-04 未发布后继候选）

现有 `POST /api/sand-ai/v1/audio-transcriptions/{taskId}/summary` 的调用字段、正式来源授权和显式任务重试入口不变，不增加 caller 指定 schema 或输出策略的参数。仅新建任务在本地会议规划与已发布部署显式结构化策略同时成立时，由服务端冻结 `_meeting_summary.output_contract`：`version=meeting_output_contract@2`、`strategy`、官方 JsonSchema 生成的分块基础 `schema` 与 `schema_sha256`；同键重放及历史任务不自动追加或替换该标记。

每个真实 `chunk`／`merge` 节点从已冻结任务和节点输入构造输出契约，阶段、输入摘要和有序来源 ID 摘要进入调用指纹。共享模型入口核对真实任务、节点、步骤、原消费者、请求 ID 和当前租约后，复用现有 Neuron 输出适配器。官方 JsonExtractor 的完整单文档 JSON 结果进入原业务来源、时间与覆盖校验；原模型正文仍保留在原回执，不经重试修补。

输出由 `summary`、`key_points`、`decisions`、`action_items`、`unresolved` 五个数组组成；`summary` 非空。v2 的 chunk 每个数组最多 40 项；merge 各类上限等于两个已核验相邻子摘要该类条数之和，空类上限为 0。每项只有 `text`（1–1000 字符非空文本）与 `segment_ids`（1–20 个不同来源 ID）。引用必须属于该真实节点来源，完整覆盖与合并来源规则仍按原会议业务校验执行。旧任务无标记时保持原纯文本协议；显式空标记或标记、节点、来源不匹配均拒绝，不回退为另一协议。



v2 先将合并输入与真实封存子摘要重建比对，再从同一冻结输入派生逐节点 schema 的 `maxItems`；版本、具体 schema、节点输入摘要进入原调用指纹。正常消费、原回执恢复、封存节点重读与最终成果读取使用同一上限；节点类型、输入或协议不完整时拒绝，不回退。单次输出与 compact 子结果仍最多 10000 个 Unicode 字符，merge 输入仍最多 30000 字符，原输出 Token 与预算限制保持。超限不截断事实、不自动重发。既有 `meeting_output_contract@1` 继续每类最多 40 项，历史任务与回执不升级；task23 的原 v1 末次输出及已发生费用保持原失败历史，v2 不代表该旧任务已恢复。

该后继目前为源码候选；新的正常会议登记、实际最终 SDK 正文和价格报价、模型执行、节点封存及页面验收分别留证。旧 Task33 与其旧路由、来源和费用记录不回填。



## 平台模型验证费用（2026-10-04 未发布后继冻结契约）

未来验证采用真实管理员归属与独立平台统计，不借用业务客户端身份；正常安装须包含迁移130。旧验证 @1 保持原 untracked 真相，不回填价格、事件或回执。以下已冻结契约的源码仍待完整独审与正常加载，当前费用页面不据此宣称完成。

## Frozen public admin DTO

Existing verification/read and verify response add platform_usage only:
{scope_kind:'platform_admin',attempt_id:int|null,usage_event_id:int|null,
 state:'untracked'|'pending'|'not_dispatched'|'ambiguous'|'recorded',
 usage_state:'measured'|'unmeasured'|null,pricing_state:'priced'|'unpriced'|null,
 price_revision_id:int|null,metered_amount:decimal6string|null,currency:string|null,
 reconciliation_state:'unreconciled',cash_billed_amount:null}.
No evidence/body/key/ciphertext or provider-authentication assertion in this DTO.
Legacy without attempt returns untracked/all amount fields null.

## Frozen platform statistics API and UI contract

GET /app/sand-ai/admin/usage/platform-validation/index and /summary.
Both use existing host CheckLogin + CheckAuth, the exact authenticatedAdminId
check (checked and current sandadmin actor IDs must match), and respectively
sand_ai:usage:index and sand_ai:usage:summary Permission attributes. No additional
superadmin guard, invented role or hardcoded administrator ID. Host authentication
and permission failures retain the existing host envelope/status. No new IAM.

Index accepts only page (default1, positive <=1000000), limit(default10,1..100),
from/to (UTC YYYY-MM-DD HH:mm:ss, half-open [from,to), default now-24h/now,
maximum366days), provider_id/model_id/deployment_id/admin_actor_id (optional
positive IDs), capability_code (chat|embedding|audio_transcription|audio_speech),
state (pending|not_dispatched|ambiguous|recorded). No workload/scope selectors.
Summary accepts the same filters except page/limit. Unknown query fields, invalid
IDs/time/state/range return HTTP400 with code400 and message prefix
SAND_AI_VALIDATION_ERROR. Authenticated actor unavailable returns HTTP401,
code401/message SAND_AI_AUTHENTICATION_FAILED. Unexpected invariant failures
return HTTP409/code409/message SAND_AI_INVOCATION_FACTS_UNVERIFIED; server failure
returns existing host500 without provider/raw evidence payload.
Success uses existing host {code:200,message:string,data:...}.

Index data = {scope_kind:'platform_admin',total:int,per_page:int,
 current_page:int,last_page:int,data:PlatformValidationRow[]}.
PlatformValidationRow = {id:int,source_kind:'admin_verify',
 target_kind:'deployment'|'temporary_connection',admin_actor_id:int,
 provider_id:int|null,model_id:int|null,deployment_id:int|null,
 deployment_revision:int|null,capability_code:string,service_action:string,
 call_started_at:UTCstring,create_time:UTCstring,
 input_tokens:int|null,output_tokens:int|null,total_tokens:int|null,
 platform_usage:exact DTO above}.
ID is immutable attempt PK. No raw remote model/endpoint/request/key/execution
snapshot/receipt content is exposed. Temporary target extension below is frozen by root; UI supports nullable IDs
without inferring a target.

Summary data = {scope_kind:'platform_admin',from:UTCstring,to:UTCstring,
 total_attempts:int,total_events:int,states:{pending:int,not_dispatched:int,
 ambiguous:int,recorded:int},overall:PlatformCurrencySummary[]}.
Each overall row uses UsageSummaryReducer::rows(rows,'overall') EXACT output
fields: dimension:'overall',value:null,currency:string|null,total_events,
measured_events,priced_events,unmeasured_events,unpriced_events,
known_metered_amount:string|null,metered_amount:string|null,failed_events,
failed_unknown_amount_events,failed_known_amount:string|null,
failed_metered_amount:string|null,business_failed_events,
business_failed_unknown_amount_events,business_failed_known_amount:string|null,
business_failed_metered_amount:string|null,reconciled_events,discrepancy_events,
unreconciled_events,reconciled_amount:string|null,difference_amount:string|null.
Money strings are normalized decimal6; never sum different currencies. Pending,
ambiguous and not_dispatched attempts have no event and are counted separately,
not fabricated zero-cost currency rows. Unknown events remain currency null /
amount null; known subtotal may be shown but never label the incomplete amount
as total provider cash spend. No billing reconciliation is fabricated.

Fee page adds explicit tab/entry '模型验证费用', gated by actual usage:index
permission; summary request only with actual usage:summary permission. Separate
filters/GETs and totals from environment workload statistics; no implicit global
addition. Empty real attempt list is an empty state, not proof legacy verification
was free. 42 owns fee tab/panel/API candidate; 64 owns shared routes.

## Frozen temporary/manual onboarding actual fee extension

SetupController::onboardingVerifyModel calls official Neuron chat BEFORE
VerifiedModelDraftService creates provider/model/deployment. Permission is the
real sand_ai:onboarding:verify and actor is authenticatedAdminId via
capabilityActor(). It can incur usage with no formal target; success later saves
real usage in validation_usage but marks untracked, failure may save nothing.
Discovery GET /models is distinct and MUST NOT fabricate chat usage.

Frozen target_kind='temporary_connection' attempt branch: config revision,
provider/model/deployment and deployment revision all NULL; capability chat;
service_action sand_ai.onboarding.verify; authenticated admin actor required;
non-sensitive connection_fingerprint HMAC of canonical validated endpoint +
remote-model + adapter (no API key, raw endpoint or model in public DTO), actual
request/body digests and safe maxoutput settings frozen before send. Call-start
price unpriced with reason temporary_target_unregistered; amount/currency/price
revision null. Actual measured token receipt creates one canonical unpriced
admin event with all formal targets NULL, preserving real quantities. Later draft
creation must not reprice or create a second event. Immutable optional binding
carrier may link resulting formal draft only as provenance, never alter old event.
No fake provider/model IDs, no admin-client, no synthetic tariff or cash bill.
An idempotency key is required before send; old verification without a new attempt
remains @1/untracked. Root froze this extension on 2026-10-04. Both actual inference entrypoints are
covered by the same candidate; discovery stays distinct.

## Frozen incremental integration delta

Migration130 adds the strict target_kind subtype plus immutable draft_binding
(attempt_id unique, resulting config_revision_id unique, safe existing draft DTO)
for temporary success replay. It provides provenance only; formal target columns
on the original event remain NULL and no price is selected afterward. Known
receipt must have exactly one event at transaction commit (deferred coverage
trigger); ambiguous/not_dispatched must have none. @2 provenance checks original
attempt/receipt/event including actor and quantities; @1 checks are unchanged.

Temporary onboarding verify now requires Idempotency-Key before send. Same actor
and key with changed credential-sensitive fingerprint or request rejects with
SAND_AI_IDEMPOTENCY_CONFLICT; pending/unknown never retries automatically. Existing
legacy normal onboarding UI uses formal deployment verify and already sends its
key; manual API users must retain their key. New normal response includes the
frozen platform_usage and idempotent_replay fields. Read-only fee list shows all
platform attempts; it never resubmits a verification.

Normal admin `POST /app/sand-ai/admin/setup/deployment/verify` and
`POST /app/sand-ai/admin/setup/onboarding/verify-model` retain the host HTTP 200
response envelope. `SAND_AI_IDEMPOTENCY_CONFLICT` is a business refusal with
`code: 400`; its domain code and message remain in `message`. Changed-request
conflicts reject before Provider dispatch and do not add an attempt, receipt, or
usage event. This mapping does not change Provider or unknown failure responses.



## 知识索引状态与外发费用恢复（0.1.20 源码候选）

现有 `GET /app/sand-ai/admin/knowledge-bases/embedding-index/status` 不新增字段或权限，仍返回 `id/state/source_count/indexed_count/dimensions`。报告状态前按冻结索引复核当前已发布版本及来源完整性；来源漂移或失效沿现有错误码拒绝，不以历史 `ready` 行代替当前就绪证明，不调用模型或修改索引。页面读取失败撤销上次就绪操作许可及计数、显示“待核验”，保留原索引和请求号；正式状态读取成功后才恢复操作。来源漂移恢复沿现有新草稿、发布和选择新版本入口，旧索引不自动清理，未知请求不换键重发。

已开始的聊天或 Embedding 请求仅有供应商拒绝错误、没有可靠计量事实时，沿既有 `ambiguous` 与预算保留路径；HTTP 拒绝不能证明费用为零。明确未发送请求及真实计量结算仍沿原路径；历史派发、回执与费用不回写。

### ASR 官方 SDK 请求适配（内部兼容行为）

新请求由 Neuron4 官方 OpenAI/ZAI 语音转写提供者组装，SandAI 仅适配业务规定的语言自动省略、受控私有上传文件名/MIME 和历史字段顺序，并严格核对其余 SDK 字段。运行时 Whisper、diarized 及 GLM-ASR 的既有字段、模型、受控音频、时长限制、来源归一化、身份、计量与实际传输边界保持。正式部署语音验证另用内部验证档：原 WAV 样本、`verification.wav`、`en`、无词表及原字段顺序；该档不是公开参数。捕获 SDK 请求在实际传输前结束，不制造模型响应或费用回执。无新增 API 字段、路由、配置选择器、迁移或调用重试。旧 prepared/started/ambiguous/封存请求与原恢复事实不重建、不改写。

本段仅说明源码候选，正常安装、真实页面与语音业务验收分别记录；不代表已运行或零费用。


## 原请求找回与恢复界面（0.1.21 源码候选）

### AdHoc 机器人原键只读找回

新增 `GET /api/sand-ai/v1/agents/runs/by-request-key`，无 query/body 字段，`Idempotency-Key` 为原 AdHoc 提交的稳定业务键，沿既有 `required('agent', currentSubmitContext)` 校验。当前签名上下文须具 `sand_ai.agent.submit` 与 `sand_ai.agent.run`，组织/应用/环境/工作负载客户端四维完全匹配。按真实环境、类型和原请求号定位，仅接受原 AdHoc：无渠道、已发布定义或会话绑定且 `knowledge_base_code` 为空。不存在或不属当前范围统一 `SAND_AI_RESOURCE_NOT_FOUND`；其余当前授权及事实拒绝保留。

命中后委托原 Task 读取入口，以当前 `sand_ai.task.read`、来源与子工具读取权限完成正式授权、资源事实核验及重验；响应为既有 `SandAiTask` DTO，`Cache-Control: no-store`。该 GET 不重派发、不更换请求号、不替换原执行封套、不修改任务或生成费用。未找到、读失败或未知状态均不能证明原提交未受理，页面保留原键及原 payload，只用合法当前身份找回；旧过期上下文不充当当前身份。

### 原费用和凭据作用域

个人 Skill 页面通过既有测试/运行费用入口，以原 code/version/kind/key 与当前 scope/actor 读取历史费用，不先读取成果或使用可编辑调用 ID 代替原归属。成果撤销不抹除原费用定位，身份/范围变化与旧异步响应仍按当前范围防护；未知金额保持未知，不补零。后台与原成果生命周期不变。

渠道凭据保存结果未知时，页面保留原键、原草稿及原环境/绑定作用域；切换环境不丢失未知操作，旧环境响应不清除或刷新新环境操作。返回原作用域才可按正式幂等语义处理；不增加凭据持久化或新接口字段。

机器人恢复界面区分未知提交、原任务恢复和可合法开始的新任务。未核实模型/摘要/Embedding/子工具状态时不以新键逃离旧请求；只读 SSE 的同一任务状态恢复不续签原执行身份。实际服务器原重试、幂等、来源与费用门仍生效。

### 原成果清理与法律 JSON 容器

文件及音频成果清理除任务终态外，仍核对关联模型及外部派发是否存在未结事实；取消任务不使未知派发可删除。音频清理使用受控锁冲突路径，不等待并发反向锁；原财务、回执和审计保留。法律 `legal_output_contract@2` 验原 JSON 容器类型：列表须为数组、项目与草稿须为对象，复用官方 SDK 和原来源/业务校验；旧无 marker 路径不改。无新增迁移、错误枚举、费用重算或外发重试。

本段是源码候选；正常包、加载与各实际页面/业务门分别记录，不作为新调用或凭据授予许可。


### 智能体持久检查点安全续跑（SOURCE实现，未运行验收）

`POST /api/sand-ai/v1/agents/tasks/{taskId}/continue` 不接受query，raw body必须为空（不发送`{}`）；携稳定独立的`Idempotency-Key`，返回既有任务DTO。续跑键与首次提交键分开，响应未知时保留同任务、原键及原快照，不换键逃离未知状态；仅确认该次尝试已推进后允许下一显式续跑。

任务读取DTO可附加`continuation:{available:boolean,reason:ContinuationReason|null}`。稳定原因仅为`checkpoint_missing`、`task_not_failed`、`lease_active`、`dispatch_unknown`、`attempts_exhausted`、`source_unavailable`、`configuration_changed`、`authorization_required`；结构可用时`available=true`且`reason=null`。投影不授予当前调用方权限，也不泄露私有检查点、密文、原授权或厂商响应；POST仍核当前可信四级身份、`sand_ai.task.retry`、`sand_ai.agent.submit`、`sand_ai.agent.run`、原固定配置与完整来源。拒绝沿现错误封套及域码，不新增HTTP映射。

执行复用官方Neuron4 `ExecutionRequest::start(event,runId,recoverFailed:true)`，以持久检查点/执行日志及租约fencing恢复原执行，失败流程不伪装成InterruptRequest。无检查点的历史任务明确拒绝，未核实派发unknown不入队；不替换已派发步骤的原授权/回执。已封存工具成果只在当前`tool.read`及来源授权仍有效时读取复用，未执行的新工具仍要求当前`tool.run`；已完成模型/工具成果沿原封存回执复用，不重复发送或计费，真正后续新调用沿既有预占/派发/计量链处理。

私有加密检查点使用迁移`202610050131_agent_continuation_checkpoint`和既有配置密文能力，不新增Key或平行IAM。迁移仅SOURCE编写及安装/升级/卸载SQL接线，未执行；SDK/后端/中文续跑入口源码交付不代表安装、加载或业务验收通过。


## 工具原请求只读找回（4.4 源码候选）

工具试用首次 POST 未收到运行 ID 时，保留该次原 `Idempotency-Key`，通过 `GET /api/sand-ai/v1/tool-runs/by-key?request_key=<原业务键>` 只读找回。查询字段闭合为 `request_key`，必须是原提交键，非空且至多 128 字节，沿原工具键规则 trim；不接受正文或其他查询字段。请求使用当前签名工作负载上下文与 `sand_ai.tool.read`，不以请求自报组织、应用、环境或客户端代替可信身份。

服务端按现有 `sand_ai_tool_run.environment_id + request_id` 定位持久运行；`request_id` 是原工具提交键，保持既有存储方式。组织、应用、环境和工作负载客户端必须完全匹配；不存在或不属当前 caller 统一返回现有 `SAND_AI_RESOURCE_NOT_FOUND` 错误壳。命中后复用正式运行读取的工具资源授权、原来源／只读 Agent 父任务事实与返回前重验，拒绝当前权限或事实不满足的请求，不泄露其他范围运行是否存在。

返回与 `GET /api/sand-ai/v1/tool-runs/{run_id}` 相同 DTO：`run_id,tool,version,state,result,citations,review_required,metering,audit_ref,error,idempotent_replay`，其中只读找回 `idempotent_replay=false`。响应使用 `Cache-Control: no-store`。该 GET 不调用 Provider、不创建运行、不执行恢复派发、不写计量；找回失败不允许客户端换键或新增 POST 以规避原未知请求。源码实现与真实 API／页面恢复、权限及费用验收分别记录。

工具试用参数由所选已发布固定版本的 input_schema 生成：文字、数值／整数、布尔和 enum 使用对应输入控件；可选字段显式选择是否提交，嵌套对象／数组保留局部 Schema 并可填 JSON。提交前按 ToolSchemaPolicy 现有有界子集递归检查类型、必填、枚举、未知字段、字符串／数值／数组限制及节点／字节／深度上限；未取得该固定版本 Schema 或配置非法时不能开始新试用，原运行找回仍可用。服务端正式 Schema、权限、计量和审计仍为最终执行边界，页面校验不代表运行通过。

首次提交结果不明时保留原请求键，页面只读 GET 找回，不沿未知 POST 再次提交，也不换新键；即使重新载入页面也继续锁住未确认请求。已有运行号提供独立正式 GET 读取。会话存储仅保留不透明原键、运行号与未确认标记，不保存参数、结果或 Bearer；待确认期间不能开始新试用。配置页对既有连接代码、撤销代码、既有草稿、不同 adapter 或不同连接绑定的已知冲突给出中文恢复入口；仅识别现 HttpError 保留的精确服务端消息，其他未知错误保留原键。


### 原聊天 request key 状态读取

`GET /api/sand-ai/v1/chat/completions/by-request-key?request_key={原调用方键}` 只接受唯一查询字段 `request_key`，无请求体。该键是原 `POST /api/sand-ai/v1/chat/completions` 的 `Idempotency-Key`，不是服务端派生的调用号、审计号或新的提交键。沿原规则 trim 后须为 1–512 字节的可见 ASCII；缺失、空或不合法键返回 `SAND_AI_IDEMPOTENCY_KEY_REQUIRED`，非字符串键、未知查询字段或请求体返回 `SAND_AI_VALIDATION_ERROR`。

当前可信 SandIAM 上下文须拥有 `sand_ai.invocation.read`，不要求 `sand_ai.chat.complete`。服务端复用原 chat 域与可信 scope 的键派生算法，按环境和原调用号找到已持久化调用，再沿现有 `sand_ai.invocation` 正式资源授权核对调用与派发账本的四级身份和归属；返回前重验同一个 `authorization_id`。不存在或派生到其他 scope 的键返回 HTTP404 `SAND_AI_RESOURCE_NOT_FOUND`；正式授权、撤权、过期或事实不完整错误保持现有读取规则。

成功 `data` 仅为既有调用状态摘要 `{ request_id, state, latency_ms, error_code }`，不返回或恢复正文。该入口不新增 chat 调用、预算预占、派发或模型费用；正常读取资源授权和审计仍按 SandIAM 原路径执行。原 `GET /api/sand-ai/v1/invocations/{requestId}` 的授权与响应保持原契约。


### 公开聊天显式低成本试用入口（SOURCE实现）

能力页ChatRouteTrialPanel仅以用户当前提供的正式应用IAM context读取`/api/sand-ai/v1/models`及提交公开聊天；模型服务商Key和后台登录都不能替代此上下文。可选固定目录模型或显式`@lowest-cost`，正文沿既有`{model,messages,max_output_tokens}`；max_output_tokens明确选择，最低成本不持久化为策略、不改变内部任务冻结。目录只给现模型/能力字段，无价格币种承诺；成功POST使用真实selected_model，实际调用模型/价格版本及金额沿本调用`/invocations/{request_id}/cost`与独立usage.read获取，无权/未知单独显示。

原调用键提交前仅以opaque key存会话，Bearer不持久化，generation变化拒绝旧身份响应。未确认期间只读状态/cost、禁止新POST/新键；failed可能含未知派发，不作为未发送证据，404/无权也不解锁。仅确认succeeded才可开始新调用；只读恢复不返旧回答，正文只显示成功POST实际结果。预算页明示既有提醒/占用/已开始逐节点策略及停用归档行为，不新增policy字段。

### GET `/api/sand-ai/v1/files/{fileId}/source-blocks`

读取当前文件解析的来源块，供材料与法源选择器绑定真实来源。只使用既有私有文件范围动作 `sand_ai.file.read`，授权资源为 `sand_ai.file/{fileId}`；使用可信身份的 organization/application/environment/client 文件归属范围及持久文件事实的真实数据分级。现有法源快照读取已用该动作读取完整来源块正文。遵守既有 `X-Sand-AI-Environment-Id` 检查；读取开始、结束核对文件分级，并在返回前以本次同一 `authorization_id` 正式重验当前授权及分级，不签发权限或凭证。成功与失败响应均设置 `Cache-Control: no-store`。

仅支持以下查询参数；其他参数、数组、浮点数、空值或非正整数拒绝：

| 参数 | 类型与约束 |
| --- | --- |
| `after_id` | 可选正整数；必须是同文件、当前解析内仍有效的来源块 ID。 |
| `limit` | 可选整数 `1..40`，默认 `40`。 |
| `parse_id` | 可选正整数；若提供，必须与当前最大解析 ID 一致。 |

成功响应沿用统一响应壳；`data` 固定为：

```ts
{
  file: { id: number; sha256: string; parse_id: number };
  blocks: Array<{
    source_block_id: number;
    content_sha256: string;
    locator_type: string;
    locator: Record<string, unknown>;
    preview: string;
  }>;
  next_after_id: number | null;
}
```

文件必须有效且为 `parsed`；选择最大解析 ID 后，要求该解析有效且为 `succeeded`，不回退到较旧成功解析，包括当前解析已失效或删除的情况。来源块必须有效、属于同文件与解析，其持久化内容 SHA 必须与实际内容一致。按来源块 ID 升序读取 `limit + 1`；还有下一页时 `next_after_id` 为本页最后一个块 ID，否则为 `null`。后续分页应保留首个响应的 `file.parse_id`，解析变化明确拒绝，不混合不同解析的块。

`preview` 为实际内容的前至多 500 个 Unicode 字符。`locator` 仅投影已验证的来源位置：`line`、`page`、`paragraph`；`table_row` 的 `row` 与存在时的 `table`；`page_region` 的 `page/x/y/width/height`；`audio_time_range` 的既有规范时间范围及合法粒度。分块存在时保留有效 `part`。工作表标签及其他额外字段省略；不返回对象 key、私有路径、存储信息、原始厂商响应或永久 URL。未知位置类型、缺失或无效的必要位置拒绝。

既有错误码：

| 错误码 | 条件 |
| --- | --- |
| `SAND_AI_VALIDATION_ERROR` | 路径或查询参数不合法。 |
| `SAND_AI_RESOURCE_NOT_FOUND` | 文件不存在、失效或不属于当前可信范围。 |
| `SAND_AI_RESOURCE_CONFLICT` | 文件当前未完成解析。 |
| `SAND_AI_SNAPSHOT_FILE_MISMATCH` | 当前解析未成功/失效、显式解析 ID 不一致、游标不属于当前文件解析，或读取期间快照变化。 |
| `SAND_AI_SCOPE_SNAPSHOT_INVALID` | 文件/块 SHA、内容或来源位置不合法。 |
| `SAND_AI_INVOCATION_FACTS_UNVERIFIED` | 读取期间文件数据分级变化。 |

本接口不构造 `version_ref`。消费者保留真实材料版本引用，并用响应中的文件 SHA、解析 ID、块 ID 与内容 SHA 绑定来源；业务提交仍遵守原有材料/法源验证契约。

### GET `/app/sand-ai/admin/usage/comparison`

权限复用 `sand_ai:usage:cost`。无 body。query 仅接受：

- `environment_id`、`current_invocation_id`、`baseline_invocation_id`：必填正整数。
- `current_price_revision_id`：可选正整数。省略时要求原 provider/model/capability 在当前 UTC 时刻只有一个有效 published revision；显式指定仍须匹配原 provider/model/capability，且当前有效。

沿用费用接口 success 外壳。data：

```text
current: {id: int, cost: 原 UsageCost DTO}
baseline: {id: int, cost: 原 UsageCost DTO}
comparison: {
  comparable: bool,
  reason: string|null,
  kind: "metered_not_cash",
  currencies: [{
    currency: string|null,
    current_metered_amount: decimal-string|null,
    baseline_metered_amount: decimal-string|null,
    difference_amount: decimal-string|null
  }]
}
scenario: {
  state: "estimated"|"unavailable",
  reason: string|null,
  kind: "same_tokens_published_price_estimate",
  price_revision_id: int|null,
  currency: string|null,
  input_tokens: int|null,
  output_tokens: int|null,
  frozen_amount: decimal-string|null,
  published_amount: decimal-string|null,
  difference_amount: decimal-string|null
}
```

comparison reason：`null`、`same_invocation`、`ownership_differs`、`execution_context_differs`、`input_context_unverified`、`input_context_differs`、`usage_unavailable`、`quantity_differs`、`pricing_unverified`、`currency_differs`。

scenario reason：`null`、`usage_unavailable`、`frozen_price_unverified`、`published_price_unavailable`、`published_price_unverified`、`currency_differs`、`amount_out_of_range`。

comparison 差额为 baseline minus current；scenario 差额为 frozen minus published。均为六位精确十进制字符串。不同币种分行，未知币种用 null；不可比/未知差额始终 null。金额不表示实际现金节省。

两次读取均复用 `UsageCostLogic::read` → `UsageGateway::invocationCost`，包含原 `InvocationReadFacts` 来源证明。另核对读取后四级归属、原 request bridge、派发的 model/deployment/capability/service/source 与唯一 usage event 证据。异常继续 400/403/404。

完整请求指纹包含 model/messages/options/trusted identity/source context。额外要求实际 Provider body digest 相等，以及经 `DispatchRouteSnapshot::assert` 验证且与派发 provider/model/deployment/capability 绑定的完整冻结配置摘要相等。三者只接受有效 SHA256 且完整相等；不拆除 model 或推测缺失输入证明。因此跨模型历史调用通常不可比，仍显示原费用与原因；同一调用两次选择也不可比。价格场景独立于基线可比判定。

生产接线已存在，不新增字段或迁移：`ChatGateway::complete` 的 229-277 行构造并生成完整请求 fingerprint，660 行传 `ChatBudgetGuard::prepare`，`ChatBudgetGuard::createInvocation` 522-528 行持久化 `Invocation.request_fingerprint`。指纹不包含 request_id/时间，因此同输入/model/scope的新 request_id 可形成不同 Invocation 且相同 input fingerprint。`ChatGateway` 556 行冻结 route snapshot，601 行生成 Provider body digest，618-625 行派发准备保存 `route_snapshot` 与 `request_body_digest`；它们由同一生产执行路径创建，后端 reader 直接消费其不可变事实。历史任一证明缺失保持 `input_context_unverified`，不回填。

当前场景不改写原 event/price snapshot。发布价解析复用 `PriceResolver::resolveRevision`，额外要求仅两个唯一 token meters，并复用 `PricingValuePolicy` 验证 canonical rates；按原输入/输出 Token 调用 `TokenPriceCalculator`，金额差复用 `BudgetDecimal`。

### DOCX 可见部件来源（3.4 SOURCE）

新 `docx/zip-xml@1.5` 沿既有 `native_document_parse` 路线读取实际节引用的启用页眉/页脚、可见脚注/尾注引用与文本框；未引用部件、删除修订和隐藏文字不作为来源。原 `paragraph/table_row` 和分片 `part` 保留，位置可追加以下无私有路径字段：`document_part:header|footer|footnote|endnote`；页眉/页脚使用正整数 `part_index`，脚注/尾注使用非负整数 `reference_id`；文本框使用正整数 `textbox/anchor_paragraph`，表格内文本框还使用正整数 `anchor_table/anchor_row` 和规范 `anchor_cell` 坐标。旧正文位置无这些字段仍可读。编号只定位同一冻结解析，不表示渲染页码。

读取 XML 部件的累计解压字节数沿现 `SAND_AI_FILE_MAX_BYTES`（默认50MiB）；关系只解析私有 ZIP 内受控相对路径，XML 禁止DOCTYPE及外部网络。图片、不能提取文字的图形/对象或外部嵌入内容明确拒绝完整解析，不持久化部分成功块。DOCX不自动转换或发送OCR；需在私有处理范围导出保留视觉正文的PDF，再沿既有PDF/OCR路线解析。新解析记录新驱动版本，已有parse/block和冻结run不改写。

### 完全相同材料提示（3.4 源码候选）

原规格的不透明 `matter_ref` 语义沿现有 `business_scope.ref` 表达；业务快照仍为 `snapshot_ref`／`snapshot_version`，不增加平行请求字段。

新 `single_file_v1` 运行最终汇合时，以该运行已经冻结的文件 SHA-256 识别不同 `file_id` 的完全相同文件字节。同组以最小 `file_id` 为锚，按其他成员 `file_id` 顺序逐对追加现有 `payload.warnings` 字符串；提示明确参与文件、成果与费用保留，以及仅判断完整文件字节相同。每条提示的 `/warnings/{index}` 经既有 `citations` 持久绑定这两文件各自当前冻结解析中的最小 `source_block_id`，返回现有安全文件／来源定位 DTO。页面根据该路径的两份真实引用显示来源按钮，自由 warning 文字不生成来源关系。

不去重文件、事实或来源，不改写任何已封存单文件成果、历史已完成 artifact、回执或费用，也不新增模型调用。完全相同提示不是近似文本、语义重复或独立双来源证明；已有 evidence_state 与跨文件核对规则保持。此候选只为新最终汇合追加提示，不回填历史成果，未完成或取消运行继续按既有 file_results 读取成功文件。

实际业务与页面验收尚未执行；源码与语法检查不证明 3.4 整条完成。

### 费用管理正式范围与全维下钻

GET `/app/sand-ai/admin/usage/scopes`，权限 `sand_ai:usage:summary`，无 query/body。

沿用 success 外壳，data：

```text
{
  service_code: "sand_ai",
  coverage: "authorized_environment_subtotal",
  environments: [{id:int, organization_id:int, application_id:int, name:string, code:string}]
}
```

当前管理员上下文只取宿主登录中间件核验后的 `request->header('check_admin', [])`。后台 scope adapter 按官方 `AdminResourceController::access()` 方式构造 `AdminOrganizationAccess`，使用其 `isSuperAdmin()`／`applicationIds()` 获取实际管理委派，再读取官方 `Environment` 目录。与正式 `EnvironmentController.index` 的管理范围一致，不额外排除 status2 停用环境；通过官方 `Application`、`Organization` 模型核验真实链。非超级管理员仍沿用正式 applicationIds 的启用应用／组织与管理委派限制；超级管理员沿正式目录范围。运行时 active verifier 不定义历史管理读取权限。

官方源码依据：

- `/Users/code/project/sand_plugins/sand-iam/plugin/sand-iam/app/admin/support/AdminResourceController.php:275`
- `/Users/code/project/sand_plugins/sand-iam/plugin/sand-iam/app/admin/support/AdminOrganizationAccess.php:21`
- `/Users/code/project/sand_plugins/sand-iam/plugin/sand-iam/app/admin/support/ApplicationResourceController.php:16`
- `/Users/code/project/sand_plugins/sand-iam/plugin/sand-iam/app/admin/controller/EnvironmentController.php:23`

无委派返回空目录；IAM 正式接口缺失 fail closed。范围最多 1000 个环境，超限明确拒绝而不截断。停用环境按正式管理读取范围纳入，消失的真实环境／应用／组织链不伪造。所有金额明确为当前正式允许环境范围小计，不称组织／应用历史全额。

## 汇总、导出、事件下钻

GET `/app/sand-ai/admin/usage/summary` 和 `/summary/export.csv` 保留 `sand_ai:usage:summary`；GET `/app/sand-ai/admin/usage/index` 保留 `sand_ai:usage:index`。三者都只接受 query，无 body。

同一闭合筛选：

- 可选正整数：`organization_id`、`environment_id`、`application_id`、`provider_id`、`model_id`、`skill_id`、`skill_version_id`、`workflow_run_id`、`agent_run_id`、`knowledge_base_id`。
- 可选 `service_code`，仅 `"sand_ai"`，默认 `"sand_ai"`；动作独立使用 `service_action`。
- 现有 `source_kind`、`capability_code`、`usage_state`、`pricing_state`、`reconciliation_state`。
- `from_time`、`to_time`：准确 UTC `Y-m-d H:i:s`；省略默认最近 30 天，正范围最多 93 天。
- index 另接受 `page`、`limit`，limit 最多 100。

`environment_id` 省略或空值为服务器计算的全部正式允许环境。组织／应用／环境选择互相不匹配或越出该范围返回 403；前端不能提交 allowed environment ID 列表。

汇总保留原 DTO，新增：

```text
scope: {service_code:"sand_ai", coverage:"authorized_environment_subtotal", environment_ids:int[]}
by_service: 原 UsageSummaryGroup DTO（dimension="service", value="sand_ai"）
ambiguous: {
  count:int|null,
  held_amount:null, tokens:null, actual_amount:null,
  scope_state:"verified"|"association_unavailable"
}
```

单服务分组仅来自正式存储事件的 `sand_ai.` 动作；不虚构其他服务。原 13 个分组与新增服务分组均使用同一个事件查询。金额仍按币种分别聚合，未知计量不补零。每组仍最多 100 行并保留 truncated。

index 保留原分页外壳和行字段，新增：

```text
service_code: "sand_ai"
skill_id:int|null
skill_version_id:int|null
workflow_run_id:int|null
agent_run_id:int|null
knowledge_base_id:int|null
```

事件查询对每个环境同时约束真实环境／组织／应用三元组。工作流、Agent、知识库筛选与返回 ID 复用 `UsageSummaryAssociationSql` 的正式持久证明；不直接用裸父 FK。共用查询不会把关联 join 变成额外事件数量。

工作流／Agent／知识库筛选时，尚无 usage event 的 ambiguous dispatch 缺少正式事件关联证明，count 返回 null 与 `association_unavailable`，不虚报为零。一般筛选仍独立计数 scope 内无 usage event 的 ambiguous dispatch；不与计量事件重计。CSV 新增 `by_service` 分组，ambiguous count 为 null 时元数据列留空，不转成零。

## 错误与合入

HTTP 400：`SAND_AI_VALIDATION_ERROR`。

HTTP 403：`SAND_AI_USAGE_SCOPE_DENIED`、`SAND_AI_USAGE_SCOPE_TOO_LARGE`。

HTTP 503：`SAND_AI_USAGE_SCOPE_UNAVAILABLE`。

单次费用 `/usage/cost` 和比较 `/usage/comparison` 同样由 Controller 传递当前 `check_admin`，`UsageCostLogic` 在原 `InvocationReadFacts` 严格验证后核对同一正式允许环境的组织／应用／环境三元组；比较读取两次沿用同一管理员上下文。原四级正整数与可信来源要求不放宽，原事实解析没有 platform/null 分支，本次不猜测增加该例外，也不修改独立 `PlatformValidationUsage` 路径。

完整业务候选 9 文件（7 改、2 新），共享 Controller／route 仅 3 精准 patch。所有原 SHA、候选 SHA、patch 目标 SHA 见 `manifest.json`。类型以 frontend 的 `TYPES-EXACT-FRAGMENTS.json` 为唯一片段，本目录不重复覆盖类型文件。

运行调用归属仍通过SandIAM正式端口，不读取其表或模型。仅本段后台管理目录按宿主官方AdminOrganizationAccess和Environment/Application/Organization管理模型只读消费可信管理授权及引用链，未建立平行IAM、不签凭据或修改权限；不将运行态active校验代替历史管理读取授权。

## 会议摘要原请求只读找回（4.5 源码候选）

会议摘要首次 POST 未收到任务 ID 时，保留原 `Idempotency-Key`，请求 `GET /api/sand-ai/v1/audio-transcriptions/{taskId}/summary/by-request-key?request_key=<原 caller 键>`；路径 `taskId` 是原摘要来源的转写任务 ID。查询字段闭合为 `request_key`，正文必须为空。原键沿当前 `RequestIdempotencyKey::fromCallerKey` 规则 trim 后校验：非空、至多 512 字节可见 ASCII，以原 `meeting_summary` 域及当前可信调用上下文派生持久请求号；不得直接将 caller 键当作 `TaskRun.request_id`。

当前 `sand_ai.task.read` 上下文用于定位同环境、同派生请求号的 `ai_completion`；正式 `TaskInputAccessGuard` 核对原持久 canonical 身份（组织、应用、环境、工作负载客户端及原可空本地 ID），冻结 `_meeting_summary.audio_task_id` 必须是正整数且精确匹配路径父转写任务。不存在、跨调用归属或不同父来源统一沿现有 `SAND_AI_RESOURCE_NOT_FOUND` 错误壳，不泄露其他范围任务是否存在。

命中后直接委托正式 `TaskController::read`，保持其当前 `sand_ai.task.read`、`sand_ai.chat.complete` 与 `sand_ai.audio.transcription` 授权链、摘要任务资源、当前私有音频／父来源事实及返回前重验。额外动作沿现读取模型，不表示此 GET 发送摘要或转写请求。返回完全相同的正式 Task DTO，成功及错误均使用 `Cache-Control: no-store`；不新增第二种摘要结果 DTO 或读取授权模型。

此 GET 不创建任务、不派发模型、不执行重试或恢复调用、不改费用事实。未找到或读取失败时保留原键及未知状态，不自动换键或再次 POST；只读找回失败不证明原调用未发送。源码检查与真实 API、权限、父来源、页面恢复和费用验收分别记录。

### 文件清理状态读取（原6.3）

GET `/api/sand-ai/v1/files/{id}` 沿现 `sand_ai.file.read`、当前所有者四级归属与数据分级、响应前授权重验。`status=1` 且未软删除的 `deleting/delete_failed` 文件可返回原安全 summary 生命周期元数据；这两状态 `latest_parse=null`，不返回正文、来源预览、对象 key 或私有路径，不开放解析、来源正文或下载。已完成删除的文件仍沿原 not-found 拒读。

文件中心显示“清理进行中”并仅允许状态刷新；“清理失败”可通过原 DELETE 在原文件上重试清理。删除响应不明保留原文件行，先刷新状态，不自动重建上传或更换文件。仅匹配原文件 ID 且 `state=deleted` 的正式 DELETE 回应显示清理完成。无新字段、路径、权限或迁移。

## 音频转写原请求只读找回（4.4／4.5 源码候选）

音频转写首次 POST 未收到任务 ID 时，保留该次原 `Idempotency-Key`，通过 `GET /api/sand-ai/v1/audio-transcriptions/by-request-key?request_key=<原 caller 键>` 只读找回。查询字段闭合为字符串 `request_key`，正文必须为空；沿现 `RequestIdempotencyKey::fromCallerKey` 原规则 trim 后校验非空、至多 512 字节可见 ASCII，以 `audio_transcription` 域和当前可信上下文派生原持久请求号。caller 键不得直接作为数据库请求号。

以当前 `sand_ai.task.read` 上下文定位同环境、同原请求号、`audio_transcription` 类型的持久任务，复用正式 `TaskInputAccessGuard` 核对完整 canonical 组织／应用／环境／工作负载客户端及原本地 ID 快照。无记录、跨归属或不可验证快照沿现 `SAND_AI_RESOURCE_NOT_FOUND` 错误壳，不返回其他范围的存在性事实。

命中后委托正式 `TaskController::read`，完全保持原 Task DTO、现 `sand_ai.task.read` 与 `sand_ai.audio.transcription` 读取授权链、私有文件／data_class／当前来源事实及返回前重验；不增加读取授权模型或发送动作。成功及错误均返回 `Cache-Control: no-store`。此 GET 不创建任务、不恢复执行、不调用 Provider、不新增派发或费用事实。

未找到或读取失败不证明原请求未发送。页面保留原键及待核查状态，不允许自动换键、重新上传或再次 POST 规避未知原请求。源码检查、真实 API／页面恢复、权限与费用闭环分别记录。

### 渠道回信重试原请求查回（源码候选）

- 飞书：`GET /app/sand-ai/admin/channels/bindings/deliveries/retry/by-request-key`。
- 企业微信：`GET /app/sand-ai/admin/channels/bindings/wecom-deliveries/retry/by-request-key`。
- 沿用当前后台登录与 `sand_ai:channel:binding:manage` 权限。仅原操作创建管理员可以查回。
- Query 必须且仅包含 `environment_id`、`channel_binding_id`、`task_id` 三个正整数及 `request_key` 字符串；body 必须为空。`request_key` 原始长度最多 128 字节，trim 后非空，原样匹配已提交 POST 的 Idempotency-Key，不读取 GET header 替代键。
- 通过既有后台成功响应壳返回原 retry DTO，并附 `idempotent_replay: true`。飞书 data 为 `{task_id: int, state: "pending", idempotent_replay: true}`；企业微信另有原操作的 `confirmed_parts: int`。这是原操作响应，当前投递状态仍从现有回信列表读取。
- 仅查询现有操作快照；当前权限、创建者、平台、环境、绑定、任务、请求指纹及原 outbox 消息归属须一致，并在返回前重验。记录缺失或作用域变化返回既有 `SAND_AI_RESOURCE_NOT_FOUND` 拒绝，不创建 retry、重置 outbox、排队、外发或执行模型。
- 原 POST 参数、header 键规则及回信分段/未知发送处理保持现有契约。本轮证据仅为源码与 PHP 语法，未运行接口或业务验收。

# 4.1 账单追溯字段：64 冻结片段

仅新增两个现有 DTO 字段；端点、请求参数、权限和原账单／审核事实不变：

- GET `/app/sand-ai/admin/billing/read?id=…`：每个 `lines[]` 增加 `trace`。
- GET `/app/sand-ai/admin/billing/reviews?billing_line_id=…`：每个审核记录增加 `trace`。

```typescript
interface BillingUsageTrace {
  usage_event_id: number
  environment_id: number
  invocation_id: number
}
// 两处 DTO 均使用 trace: BillingUsageTrace | null。
```

三个 ID 均为正整数。只有持久核对／审核记录确实关联该账单行和该使用事件，原 InvocationReadFacts 四级正归属、状态与可信派发来源完整，且事件的模型／厂商／部署／能力／服务动作／来源／账单时间窗口／适用币种与原账单事实相符时，才可返回。缺事件、缺证明、范围不可用或事件不属于当前正式管理员环境范围，一律 `trace:null`；不把缺证推定为平台调用，不填假 ID。

Controller 显式传递当前宿主核验的 `check_admin`，经现正式 `UsageAdminScopeLogic` 生成范围，再配置 store 的可选追溯 reader；不读取隐藏全局 request。范围读取失败只使追溯未知，保留原账单事实。默认未配置 reader 的 store 也只输出 null，不能匿名取得追溯坐标。

非空 trace 的按钮用 `environment_id` 与 `invocation_id` 读取原 `/usage/cost`。账单读取／审核权限不代替单次费用权限；目标仍独立验证当前管理员范围和 InvocationReadFacts。trace 不改自动核对结论、人工审核 trust_basis 或现金金额。

候选 3 源码、2 Controller 精准 patch，原／候选／patch SHA 见 `manifest.json`。依赖已冻结费用范围线的 `UsageAdminScopeLogic` 与 `UsageEventQuery`。未新增解析器、端点、迁移或运行验证；未写权威源码。

### 首次开通原验证请求找回与发布修订核对（源码）

`GET /app/sand-ai/admin/setup/deployment/verification/read?deployment_id=&revision=` 可携带可选 `Idempotency-Key`，按当前已认证管理员、精确配置修订和该原键的 HMAC 只读查找原验证尝试及其回执；无请求头保留原修订状态读取。原尝试没有回执时返回该尝试的 `verifying`、空验证证据与空失败反馈，找不到时返回既有 `SAND_AI_RESOURCE_NOT_FOUND`。不使用该修订的最新尝试冒充原请求，不返回原键、HMAC、连接秘密或执行快照；状态读取不重发模型验证。原处理位置仅从三个现有注册值与该尝试冻结的请求摘要唯一匹配复建，不借用后来的配置。

`POST /app/sand-ai/admin/setup/onboarding/publish-deployment` 兼容可选正整数 `revision`，含义为用户确认的部署修订号。提供时在原发布锁中同时核对当前部署和配置修订号，变化返回 `SAND_AI_CONFIGURATION_DRIFTED`；省略时保留旧调用行为。首次开通页面发布必传原保存的修订号，并只读确认该精确修订已发布。

页面在验证发送前仅于当前管理员命名空间的 `sessionStorage` 保留管理员 ID、部署号、修订号与原验证请求号；不保存服务商 Key、应用 Bearer、配置或结果。刷新或返回页面仅用原号找回，缺失、读取失败、未决或不确定状态保持新保存、验证和重新配置锁定；只有该原尝试明确终态才能解除。原请求号与验证费用尝试 ID 不同，不把未知费用显示为零。

### 智能体管理原操作查回与草稿发布保护（源码候选）

`GET /app/sand-ai/admin/agents/definitions/operations/by-request-key` 沿用后台登录和 `sand_ai:agent:definition:manage` 权限。query 必须且仅包含正整数 `environment_id`、`operation: draft | publish | disable | rollback`、现有规范的 `code`、`request_key`；body 为空。原键保持字符串原始长度不超过 128 字节，trim 后非空，不取 header 替代。

仅原操作创建管理员可读取现有 `sand_ai_agent_operation` 安全响应。返回原操作 DTO 加 `idempotent_replay: true`，沿用后台响应壳并禁止缓存。当前管理员、权限、环境、code、definition 和原 version 关联在读取前后复核；原 publish/rollback 回执按原已发布版本验证，无须该版本仍 active，不受后续新草稿影响。原 draft 回执保留原响应 SHA，后续草稿更新不会被当作本次执行。关联缺失或作用域不符沿用 `SAND_AI_RESOURCE_NOT_FOUND`，GET 不保存、发布、回退、排队或运行模型。

现有发布 POST 可增加 `expected_snapshot_sha256`，必须为 64 位小写十六进制，来自现有公开 versions API 的草稿 SHA。服务在锁定 draft 后比对，不一致拒绝 `SAND_AI_RESOURCE_CONFLICT`。提供 SHA 时其值进入原键指纹；不提供时保持原指纹兼容。同一键原已完成回执优先回读，不重新验证后来新增或编辑的 draft；同键不同请求仍沿用原 `SAND_AI_IDEMPOTENCY_CONFLICT`。

本轮只提供源码与必要 PHP 语法证据，未执行 API、数据库、构建或业务验收。

当正式发布请求提供的 `expected_snapshot_sha256` 与已锁定草稿 SHA 确认不一致，且当前管理授权、环境与原 definition/version 关联均成立时，仅提交原 `sand_ai_agent_operation` 拒绝回执，随后 POST 仍抛原 `SAND_AI_RESOURCE_CONFLICT`，不发布。同键同指纹 POST 固定重放该拒绝，不作为成功返回；其他授权失败、资源缺失或异常不写此回执。

GET 的拒绝分支精确为 `{operation_state: "rejected", reason: "configuration_changed", agent_definition_id: positive integer, code: string, expected_snapshot_sha256: original 64 lowercase hex, idempotent_replay: true}`。expected SHA 是原请求已确认值，与原请求指纹核对；内部核原版本关联，不返回版本字段，不要求后来可变的版本 SHA 等于被拒绝 expected SHA。只有这一明确回执允许界面结束原未知提交并读取当前草稿，再由用户确认新键提交；普通错误或未找到不代表拒绝回执。

### 原文件解析恢复（3.4）

GET `/api/sand-ai/v1/files/{fileId}/parse/by-request-key?request_key=原X-Request-Id` 仅允许这一 query 字段及空 body。请求号复用 `RequestAuditId` 的 8–96 字符规则，原 POST 与持久值不改为新 domain/hash。按当前正式 task.read 上下文定位 environment/file_parse/file/fileId/request_id，原 canonical caller 守卫后复用原 TaskController.read 的 document_parse、私有文件归属、数据分级与末端授权重验，返回原 task DTO。成功及域错误 no-store；无排队、派发、重试或写入。

文件中心发送解析前保存并显示原文件号、请求号及收到后的任务号，只持久 opaque 标识。刷新及凭证切换均保原号、撤销上次读取状态；未知、404、拒读、失败或取消不生成另一 POST。已知失败/取消通过原 task retry 与任务详情继续。原解析成功后才可明确提交另一解析；当前仍有解析任务时进入原任务，不重建解析。

### 发布修订冲突的当前目标恢复（源码）

`GET /app/sand-ai/admin/setup/deployment/read?deployment_id=` 可省略 `revision`，此时沿原管理读取权限和 Provider → Model → Deployment → ConfigRevision 锁顺序，在锁定部署后读取其当前 `revision` 对应的配置修订，返回原安全 `DraftSave`；显式修订读取保持原行为。仅提供当前修订与安全模型配置，不回显连接秘密。

首次开通页发布未确认或修订冲突后，锁定继续发布，并提供“读取并确认当前修订”：纯读取当前部署修订及该精确修订的验证状态，展示模型、输出上限、推理额度、方法包输出及真实状态。用户明确“确认使用此修订”后才更新页面目标；读取失败或 404 保持确认要求。当前修订已发布时按该真实状态继续绑定，未发布时按其真实状态验证或发布，不自动执行。此恢复不证明原发布未执行，不创建拒绝回执，也不解除原验证的任何未决请求锁。

### 知识发布冻结快照与原结果/拒绝恢复（5.1）

原 index 新增可选 `draft_snapshot_sha256:string|null`，仅投影现草稿冻结 SHA。原 POST publish 新增 optional `expected_snapshot_sha256`（lowerhex64），新页面必带；省略字段保原 legacy 行为与成功/错误封套。新 fenced 成功操作 fingerprint 绑定 baseId、预期 SHA 与服务端正式 current actorRef；不回填旧记录，不把新字段补入旧原请求。

新 fenced 请求只有在原 key 无既有 operation、当前正式管理员/环境/知识库均合法、事务锁当前 draft、预期 SHA 明确不符且发布写入尚未开始时，保存原 `sand_ai_knowledge_operation` 的 publish 拒绝回执。使用允许的 `version_id=NULL` 加专用拒绝域 fingerprint（state/reason/baseId/expected SHA/current actorRef）；绝不把 NULL 单独推定拒绝，不覆盖成功/未知/legacy 原 operation。事务提交后返回正式拒绝 union：`{state:'rejected',submitted:false,reason:'draft_snapshot_changed',environment_id,knowledge_base_id,request_key,expected_snapshot_sha256,idempotent_replay:boolean}`。初次为 false，同 tuple 重放为 true。无新增表/列/迁移，不靠泛 HTTP400 或域错误码证明未发布。

GET `/app/sand-ai/admin/knowledge-bases/publish/by-request-key` 严格仅 `environment_id`、`knowledge_base_id`、`request_key`，另可选 `expected_snapshot_sha256`（lowerhex64），空 body；新 UI 必带原预期 SHA。沿原知识发布管理员权限、当前环境引用和正式 admin 身份。成功结果按原 operation/version SHA 重算 current actor fingerprint并末端复核关联/生命周期，返回原安全 knowledge_base/version/idempotent_replay DTO。拒绝结果仅 NULL version 且原 env/base/key/预期 SHA/current actor 的专用拒绝 fingerprint 相等，末端资源复核后返回上述正式拒绝 union。成功及域错误 no-store，不返回正文、私有路径或凭证。无操作、legacy 无 actor 证明、其他 null 或任何 tuple 不匹配仍 not-found/拒绝，页面保持锁；不 fallback POST。

页面按正式 current admin/environment 隔离持久 opaque env/base/key/snapshot。未知结果禁另一次发布/草稿替换。成功原结果 base/snapshot 严格一致才显示原版本/来源块数并清锁。拒绝 union 严格匹配 env/base/key/预期 SHA/submitted/reason 后显示“本次已确认未发布”和“刷新草稿并重新确认”；仅用户明确点击且当前草稿正式只读成功后清除此 pending，再由用户检查版本及来源状态并点击发布，形成新意图，不自动 POST、不篡改原 SHA。不匹配回执、404、拒读或普通错误不解锁。刷新失败、存储无法清除保原回执。凭证/管理员代际变化撤销旧已读判断，必须再次正式查询。

### 已发布知识检索与来源读取的可选环境核对头

- `POST /api/sand-ai/v1/knowledge-bases/{code}/query` 与 `GET /api/sand-ai/v1/knowledge-bases/{code}/versions/{version_no}/sources/{source_block_id}` 可携带现有 `X-Sand-AI-Environment-ID` 正整数头。复用 `ExpectedEnvironmentGuard`：所选环境须与 SandIAM 已核实 workload context 的 environment_id 一致；不一致沿现 `SAND_AI_ENVIRONMENT_MISMATCH`，格式无效沿现 `SAND_AI_VALIDATION_ERROR`。不携带时保持原兼容行为；该头不提供身份或授权。
- Query 请求仍仅 `{query,limit}` 与原 `Idempotency-Key`，当前 publication/四级归属/data_class/IAM 重验及原 Embedding 幂等计费规则不变。查询成功及失败封套均 `Cache-Control: no-store`，来源原 no-store 保留。
- 响应使用原 `{knowledge_base:{code,version},query,citations,embedding_invocation,embedding_usage}`。`citations` 保持真实版本/file/parse/source/locator/excerpt/score。`embedding_invocation` 为原 `{id,state}`，无新 request_id 字段；查询 Embedding 费用使用原 `embedding_usage.request_id` 经现 `/invocations/{request_id}/cost` 与 current usage.read 重新读取。两 Embedding 字段为 null 表示本次词法检索且无查询 Embedding 调用；未知金额不可当作零。
- 管理页“知识库共享”新增独立“应用知识检索”，从当前消费环境的真实 published 关系选择 alias，用正式 workload Bearer 查询。保持服务端原排名和分数，核对原引用后从现 source API 回看正文。结果不明只允许原查询/body/key/页面内原凭证重试，不自动新键、不新增恢复 API 或持久查询存储。独立查询不启用 rerank；会话来源重排沿既有单独调用与费用规则。

### 任务取消与明确未派发预占收口

原任务取消在当前正式任务、来源和归属授权下，仅对冻结事实完整、仍为 prepared 且无发送开始、用量事件或同一逻辑执行的其他发送／未知证据的子调用，在同一事务内封存 not_dispatched 并释放其原预算预占。已发送、结果未知、已有计量或无法证明安全释放的调用不作此处理；费用读取仍遵循原状态与空值口径，不伪造零费用。原任务关联的发送准备事务在既有预算、调用和派发行锁后重验父任务、步骤、租约、执行次数及可信归属；取消已先完成时整笔准备回滚。没有父任务的直接调用保持原行为。此行为不新增公开字段、价格报价、预算策略或模型调用。

### 工具发送前明确失败后的原节点续跑

正式智能体 continue 沿用原路径、当前权限、任务 DTO 与 continuation 原枚举。只有当前持久事实能严格证明失败工具从未派发、原 checkpoint 及输入／绑定一致时，才可在原父任务和官方工作流运行内，以稳定续跑请求号建立后继工具步骤；当前 tool.run 仍须正式授权。原失败步骤、工具运行、授权决定及审计保留，成功节点和已封存工具回执按当前 tool.read 与来源权限复用，不重复派发或计量。后继工具的实际回执、费用、任务历史、渠道读取和过期恢复保持真实父节点关联。已发送、结果未知或证明不足的失败继续拒绝，不能重置原尝试次数、改请求号绕过重试门或借读取续发模型。可续跑元数据不代替 POST 当前权限与租约复验。

### 公开最低成本聊天的冻结路由和请求绑定

现有 @lowest-cost 候选容量检查使用实际 Provider 请求正文、冻结部署修订的推理设置及原 streamSegments 协议，与最终派发请求采用同一算法。冻结配置或容量无效的候选不进入选择，其他合法候选仍按原规则选择。选定后，在预算预占准备前须核对精确 route_id、冻结路由摘要和最终请求正文摘要；变化沿已有 SAND_AI_LOW_COST_ROUTE_CHANGED 拒绝，不改旧请求的冻结路由或重新选择。内部 route_snapshot_sha256／request_body_sha256 不成为公开字段。试用界面明确提示路由漂移并提供现有路线处理入口，仍保留原请求号只读恢复与未知锁，不据错误码自动改键或重新派发。原权限、价格、费用、预算策略与公开请求 DTO 保持。

### 渠道流式显示与正式拒读隐私边界

渠道管理预览沿用现有流式接口及正式 Agent 轮次范围 1–4，readonly 工具后的第4轮正文正常投影，不增加模型轮数或公开字段。HTTP／SSE 的正式拒读保留现有错误类型、状态和域码；确认当前身份、来源或读取授权拒绝时，界面先使旧响应代际失效并中止旧读取，清除私密流式正文、历史摘要、来源、费用和查询草稿，防止迟到响应回填。原不透明消息键、任务标识、关闭及重试未知锁仍保留。普通网络断线保留原运行的正文与游标，恢复仍读取同一运行，不自动创建任务、换请求键或重新派发。此行为不改变后台权限、原请求 DTO 或渠道重试操作。

### 材料成果与来源的末端重验

原 `GET /structured-workflows/runs/{runId}`、`GET /structured-workflows/runs/{runId}/sources/{sourceBlockId}`、样本与正式运行 by-request-key 只读入口均沿原 `sand_ai.workflow.read`。`WorkflowRunAccessGuard.authorizeRead` 保留正式内部授权决策及 `WorkflowInvocationFacts` 的七项持久事实（组织、应用、环境、客户端、data_class、resource_type、resource_ref），加载原成果/正文后重新解析这些事实并严格等值比较，再使用同一 original decision 的 authorization_id 和原 current context 调用正式 revalidate；等待返回后再次确认资源事实。内部决策不进入公开 DTO，不新增权限、路径或来源可读范围。读封套 no-store。任务页取消/重试的权限拒绝复用现有清理方法，移除旧私密任务、成果与来源预览；取消成功后刷新当前材料逐文件状态与封存成果。

### 已取消文件解析的明确操作

- 原 Task DTO 新增可选 `parse_restart: {allowed:true,file_id:<原file ID>,task_id:<原task ID>} | null`。此生命周期提示不授权派发、不表示免费。仅 `CANCELED file_parse`、原 task/file/current latest parse 完全匹配、正式 canonical 所有权和私有文件仍合法、原解析无残留来源块且完整证明原 task 无 `sand_ai_ocr_dispatch` 时可为 true；缺 schema、未知或不匹配返回 null。`failed` 仍只走原 task retry；TaskGateway.retry 仍只接受 FAILED。
- 原 `POST /files/{fileId}/parse` 不新增 body、path 或 action。旧请求键重放保持原行为。`parse_canceled` 文件使用新请求键前，事务锁原 CANCELED parent/current file/latest parse，在现 caller scope 下重新核实相同证明；任何原 OCR dispatch（含已发送、已知失败、结果不明）均拒绝新解析。
- 证明不只依赖派发表为空。原 `OcrUsageLedger.start` 在写 dispatch 前须锁同 parent 并核 running lease/attempt/fence 与正式 queued authorization；取消持同 parent 锁并变更 CANCELED/fence。重新解析检查持同 canceled parent 锁直到新 parse 提交，因此迟到的旧 OCR prepare 无法通过 running lease。原 OCR 算法、账本和费用规则不变。
- 文件页取消状态不显示失败任务 retry。只有正式原 Task 读返回 true 且 file/task 精确匹配，用户确认后才生成并先保存新的 opaque 解析请求号，再调用原 POST。新提交丢失仍按该新原号只读恢复；旧 unknown、404、拒绝或通用错误不解除旧锁，也不自动换键。页面明确“取消不代表没有费用”，无完整证明时保留原任务核对。

### 4.3 预算草稿原请求找回（源码实现）

使用者是具备 `sand_ai:budget:save` 的当前平台超级管理员。原 `POST /app/sand-ai/admin/budget/policy/save-draft` 的 body 字段不变；新界面必须提交不含秘密、长度 1 至 128 的可见 ASCII `Idempotency-Key`，服务端原样使用，不 trim。省略 header 的旧调用保留原保存语义；提供空或非法 header 则拒绝。原 actor 是正式登录管理员的规范 ID，不使用共享 `saiadmin` 值。

携原键的保存按管理员及请求键持久唯一。已保存收据的指纹覆盖完整 `normalizeDraft`（含原策略 ID、作用域、核验环境及所有原策略字段）；同键同规范载荷返回当时的安全策略摘要，不重建策略／版本，不补写审计。异载荷沿 `SAND_AI_IDEMPOTENCY_CONFLICT` 拒绝。策略保存、原响应快照、首次审计在同一事务提交；后续编辑、发布或归档不改原响应。

新增只读 `GET /app/sand-ai/admin/budget/policy/read-draft-by-request-key`，query 必须且仅为 `request_key:string,scope_kind:string,scope_ref:string`，无 body。沿当前超级管理员、保存权限、原管理员、原 receipt 作用域及正式 reference 校验，并核对当前策略仍存在且作用域／版本对应；响应 `Cache-Control: no-store`。成功的 `data` 是**原保存时**的既有策略摘要加 `idempotent_replay:true`，其 `state:"draft"` 不表示该策略目前仍可编辑；界面须再沿既有 id 读取当前策略。

仅对合法作用域和正式 reference 已核验、原键没有收据、尚未任何策略写入时可证明的前置业务拒绝，持久保存 `not_saved` 终态收据，再在事务提交后沿原业务错误壳拒绝 POST。当前可记录代码只有 `SAND_AI_BUDGET_VALIDATION_ERROR`（日期、金额、币种、枚举及字段等规范校验）、`SAND_AI_BUDGET_IMMUTABLE`（同作用域策略已发布／归档）、`SAND_AI_RESOURCE_NOT_FOUND`（原目标策略未找到）。拒绝指纹采用独立 `budget_policy_draft_rejected@1` 域和完整原输入 canonical fingerprint；同键变更任何原输入不得从拒绝变成保存。GET 对该收据返回唯一失败 union `{state:"not_saved",error_code:string,message:string,idempotent_replay:true}`，message 仅原稳定安全业务说明，不包含 raw input、SQL 或秘密。

作用域／正式身份／reference 核验失败、SQL 或任何进入策略写入后的异常不记录 `not_saved`。GET 未找到沿 `SAND_AI_RESOURCE_NOT_FOUND`；未找到、无权或网络异常都不证明原保存未发生，不允许界面换键 POST、再次提交或丢弃未确认状态。只有可靠 `not_saved` 收据允许用户修正原稿并开始新的保存。每个 pending 仅冻结原作用域及目标草稿，不阻止其他草稿操作。

132 迁移仅编写；本轮未执行数据库、HTTP、钱包、报价或模型调用，未完成真实保存／恢复验收。

### 独立知识检索显式重排（原5.1，SOURCE接线）

原 `POST /api/sand-ai/v1/knowledge-bases/{code}/query` 接受 `{query,limit?,rerank?,model?}`。`rerank` 是可选 boolean，默认 false；false 沿用原 query/limit（1..20），不允许 model。true 必须显式提供按现聊天模型规则验证的 model，limit 限1..10。没有默认模型或默认付费重排，不引入专业 rerank provider。内部检索 `normalizeQuery` 不变。

true 即使0/1来源也要求同一当前正式workload同时有 `sand_ai.knowledge.query`、`sand_ai.chat.complete`，四级归属/audience相同；现可选 `X-Sand-AI-Environment-ID` 仅核对当前环境，不充当身份。true 必须提供原 `Idempotency-Key`；活动索引及其他原key规则保持。0来源/1来源不调用重排，分别返回 no_sources/single_source；两个以上来源沿现 `KnowledgeRerankGateway` 的官方 `PostProcessNode` 与chat全ID唯一排列协议真实执行，输出上限256，不增加回答模型调用。

响应原 knowledge_base/query/citations 与 embedding_invocation/embedding_usage 保留。每个citation仍为当前冻结版本的真实来源，score仍为原检索分数（不伪装为模型重排分数），重排只改变排列。新增字段始终存在：

- rerank_invocation：null，或现ChatGateway摘要 `{request_id:string,state:string,latency_ms:number|null,error_code:string|null}`，无id。
- rerank_usage：null，或现会话ChatGateway原 SandAiInvocationCost 费用投影（不猜未测费用，不将未知视为0）。费用沿真实usage.request_id正式读取。
- rerank_state：not_requested/no_sources/single_source/reordered/invalid_output。前三态invocation/usage均null；后两态保留真实调用与费用。invalid_output严格回原已授权来源排列，仍保已发生费用。

SOURCE迁移133提供正式 sand_ai_knowledge_query_intent。原derived knowledge-query key按可信四scope唯一，首次固定完整normalized query/limit/rerank/model、canonicalrefs/audience、publication/version/sourceScope/data_class及实际active Embedding index ID|null的fingerprint。初次检索完成后仅一次CAS绑定有序完整真实citation snapshot SHA；scope/body/version不可修改，retrievalSHA只允许NULL→一次值。同key改变rerank/model/query/limit/publicationversion或原index选择均拒绝为 SAND_AI_IDEMPOTENCY_CONFLICT，改变来源为 SAND_AI_KNOWLEDGE_SOURCE_DRIFTED。已知旧 paid Invocation/PreDispatchRejection 而缺完整意图记录时拒绝，不补写猜测、不改key重新付费。

原查询完整回执的只读找回使用 `POST /api/sand-ai/v1/knowledge-bases/{code}/query/by-request-key`。这是只读 POST，不是 GET；请求必须保留原不透明 `Idempotency-Key`、同一 workload Bearer 与原完整 `{query,limit?,rerank?,model?}` body。可选 `X-Sand-AI-Environment-ID` 仍只核对当前环境。133 只存意图指纹和检索摘要，不存查询正文，故不能仅凭请求键还原或推断 body。页面在未确认提交结果时冻结原 body/key，恢复只调用这个入口，禁止转回普通 query 提交、改键或自动补发。

读取复用原 `sand_ai.knowledge.query` 授权；`rerank=true` 仍要求同一四级归属/audience 的当前 `sand_ai.chat.complete` 授权，包括0/1来源。入口只 SELECT 已有完整意图，核对 normalized body、scope/publication/version/sourceScope/data_class/活动索引指纹与原 retrievalSHA。原检索已绑定完整有序引用摘要，必要的 Embedding 和 rerank Invocation/Dispatch 均已 succeeded，封存向量/全ID排列回执有效且原费用事件存在时，才返回完全相同的 knowledge_base/query/citations/embedding_invocation/embedding_usage/rerank_invocation/rerank_usage/rerank_state DTO。恢复只通过当前仍有效来源重建原引用及分数，按原回执重放排列，不生成查询向量或模型排列。

缺原意图或必要调用返回现 `SAND_AI_RESOURCE_NOT_FOUND`；原意图尚未绑定检索或调用仍未完成返回 `SAND_AI_DISPATCH_AMBIGUOUS`（retryable）；失败调用沿原安全 error_code 或 `SAND_AI_INVOCATION_NOT_SUCCEEDED`。body/归属/版本/索引冲突、来源漂移、回执失效/加密不可用、费用事件缺失分别沿现 `SAND_AI_IDEMPOTENCY_CONFLICT`、`SAND_AI_KNOWLEDGE_SOURCE_DRIFTED`、`SAND_AI_RECEIPT_INVALID`/`SAND_AI_RECEIPT_UNAVAILABLE`、`SAND_AI_USAGE_PERSISTENCE_FAILED`，不伪造成功结果。任何读取失败、未知或不存在均保原 body/key，不证明未提交，也不解锁自动改键再调用。

只读入口不调用 admit/bindRetrieval、prepared 过期恢复、finalize、预算 prepare/settle/release 或 Provider，不使用 FOR UPDATE，不写意图、Invocation、Dispatch、回执或费用账本；既有 SandIAM 当前认证/资源授权及访问审计照常执行。所有来源、版本、索引和原 decision 在读取与返回前重验，返回仍为 no-store。普通 query 提交保原预算与派发/回执/结算规则。133仅源码交付，未执行迁移；本候选未运行验收或付费调用。


### 飞书回信结果未知的原投递保护（2026-10-06 SOURCE 候选）

飞书沿既有 `sand_ai_feishu_delivery`、任务 ID 派生 UUID、绑定 revision 和消息范围恢复。正文 POST 已跨发送边界且超时、HTTP／JSON 回执不能可靠证明结果，或成功回执缺少有效 message ID 时，回信转为 `uncertain`，受控错误码为 `SAND_AI_CHANNEL_DELIVERY_UNCERTAIN`；过期的 `sending` 租约同样转为该状态，不重新领取。token 获取或正文发送前授权检查失败沿原确定状态处理，不能把它们归为已发送。服务商已确认成功但本地完成写入报错时，只读原 outbox；原行已为 `sent` 且消息摘要匹配则保留，否则只在原领取令牌下记录 `uncertain`，不再次 POST。

既有后台 `GET .../channels/bindings/deliveries` 的 `state` 与 `GET .../channels/bindings/feishu-status` 的 `last_delivery_state` 增加双渠道已有的 `uncertain`，无新增路径或公开字段。刷新只读取原投递事实；原回信重试 POST 继续只允许确定的 `failed`，`uncertain` 或该未知错误码均拒绝 `SAND_AI_RESOURCE_CONFLICT`。同键重放已经封存的重试受理快照不改变当前投递状态，需读取原列表核对。未知回信不重置次数、不换任务／UUID／消息键、不猜厂商回执查询接口，也不把它加入已送达的对话上下文。

迁移 `202610060134_feishu_delivery_uncertain` 仅交付源码：在保留原 053 校验和的前提下扩展状态约束；旧 `sending` 以及已尝试的旧 `pending`／`failed` 缺乏可信未发送证据，保守转为 `uncertain`，未尝试的 `pending` 和已 `sent` 保持原事实。fresh install 与 update 同步该迁移和校验和；本轮未执行迁移、历史数据修复、Worker、平台外发或运行验收，5.5 不据此判完成。


<a id="pkulaw-article-candidate-adapters"></a>
### 北大法宝法规候选专用适配（2026-10-06 源码增量）

归属：这是 Lawyer 一期供应商需求的可选能力适配，不是 SandAI 统一产品的默认或必选厂商。本增量没有改动 MCP/Neuron 底层、身份、凭证、费用、来源许可或协议版本。新增工具使用现有受管连接、发现、固定快照、发布、当前授权运行与原号读取入口；已有工具不变。

| 本地工具 | 必填本地输入 | 远端名称与参数 | 结果 |
| --- | --- | --- | --- |
| `pkulaw_article_search` | `query` 1–2000字、`limit` 1–20 | `search_article`：`text=query`、`size=limit` | `matches`，最多limit项；0命中是空数组 |
| `pkulaw_article_lookup` | `title` 1–300字、`number` 1–128字 | `get_article`：原样传title/number | `matches`，最多1项；不猜法规名或中文条号 |

每个match仍是`source_ref/title/excerpt`；引用仍走既有`source_ref/locator/content_hash`。source_ref固定绑定实际gid、原平台url和实际article字节SHA；content_hash仅是返回条文的摘要哈希，不是已获许可全文、独立法规版本或私有材料SHA。没有伪造source_version。

取数遵循官方CLI0.3.2 Application规则：非空structuredContent对象优先；旧服务从content的JSON文本块回退。只接受官方参考文档明确的法规搜索数组或精确查询对象，不能识别的包装或字段变化失败关闭，不猜新路径。只保留实际gid、title、url及doc_no/timeliness/effectiveness/issue_department/issue_date/implementation_date的候选注记。article仅用于内存校验和SHA，不进入投影结果；原始厂商payload、条文原文及Token不进入候选结果。来源标注不提升为本系统核实效力，日期为空不补值。

#### 开发者/管理员的实际配置入口

1. 取得本组织已订阅服务实际`tools/list`资料。公开参数表不是完整JSON Schema；应从官方控制台或官方CLI支持入口取得每个工具完整`inputSchema`及实际`outputSchema`，不存在outputSchema时明确记录null。本次没有运行上述发现或业务查询，没有替管理员开通、充值或配置凭据。
2. 在随同正常安装载荷交付的`config/sand_ai_pkulaw.php`填写对应原始schema并显式设`enabled=true`。两工具可以独立登记；input_schema为null的工具不注册。无实际schema时保持默认停用，禁止依据示例合成schema。
3. 用既有后台**受管工具 → MCP连接**配置组织实际端点及受管Authorization凭证；使用现发现、核对并发布固定版本入口。端点/密钥继续由该入口持有，不写进本配置或Lawyer/browser。发现仍要求完整远端schema与配置逐字hash一致，发布冻结实际协商协议与来源；运行仍重验IAM与既有费用边界。schema漂移须重新获取实际资料并正常发现/发布，旧快照与原号不改写。
4. Lawyer管理员可在其原环境入口选择`LAWYER_LEGAL_SEARCH_TOOL_CODE=pkulaw_article_search`及对应已发布版本。精确lookup可通过既有工具固定版本运行入口按title/number调用；本增量不新增Lawyer精确查询表单或自动调用链。

新增注册只接受上述官方明确的参数名及本地映射，不透传其他可选过滤条件。异常沿既有`SAND_AI_MCP_REGISTRY_INVALID`、`SAND_AI_MCP_SCHEMA_DRIFTED`、`SAND_AI_TOOL_RESPONSE_SCHEMA_INVALID`及受管执行错误回执；Lawyer继续给安全中文摘要并保留原号，不展示厂商raw response。

#### 正式公开依据与剩余条件

- [官方快速开始](https://mcp.pkulaw.com/docs?doc=quick-start)：Bearer、按服务开通；REST章节要求联系提供，占位符请求不是本适配依据。
- [法规语义/指定条文正式参考](https://mcp.pkulaw.com/docs?doc=3836b0ee-676a-43bb-b2a0-89826017910a)：search_article的text/size、get_article的title/number和gid/title/article/timeliness/implementation_date/url字段。
- [官方CLI](https://www.npmjs.com/package/@pkulaw/mcp-cli)0.3.2及其公开包：tools/list、tools/call、输入schema应以实际服务为准、Application取数规则。CLI的MIT许可只覆盖源码，不能替代法源数据许可。

本次源码未调用任何厂商服务。实际订阅/Token/完整schema、所用端点的初始化及支持协议尚未运行确认；全文展示/缓存/传给模型/嵌入/跨案复用的许可、可核实引用版本和真实法律查询验收未完成。案例语义/关键词、司法解释独立分类与版本确认、自动诉讼起草及裁判救济完整链没有被这两个候选工具抵消。候选不能自动登记confirmed法源；仍须沿原私有材料与明确权利/许可的真实登记链。


<a id="pkulaw-case-candidate-adapter"></a>
### 北大法宝案例语义候选适配（2026-10-06 后续源码增量）

新增可选本地工具`pkulaw_case_search`，必填输入query（1–2000字）/limit（1–20），实际映射官方`search_case`的text/size。复用原MCP/Neuron、受管凭证、IAM、预算、固定工具版本与原号读回；不改底层协议、不默认选择PKULAW。

开发者/管理员在已存在`config/sand_ai_pkulaw.php`的search_case条目填本组织实际`tools/list`完整input_schema和output_schema（实际未提供outputSchema则明确null），再显式enabled并沿现受管MCP连接的发现/发布入口发布版本。空input_schema不注册；完整schema hash门槛不变，不从公开参数表猜schema元数据。法规两工具配置仍独立保存。公开可选case_type/doc_type/courthouse_name/courthouse_province/decision_date_start/end过滤字段未从本地透传，本增量只实现明确的text/size。

结果继续`matches:[{source_ref,title,excerpt}]`与标准citations。只读实际gid/title/url和case_number/courthouse_name/decision_date/case_type/doc_type/cause_of_action元数据，excerpt不含判决段落。ascertain/identified/referee_result只在内存校验并形成固定键的规范JSON，content_hash为这三个实际返回字符串的canonicalJSON SHA-256；它不是完整判决字节SHA、独立文书版本或已确认引用。三段可能为空，hash也只证明本次返回的字段状态，不证明有足够正文。source_ref绑定gid/url/hash；定位只接受HTTPS的pkulaw.com/www.pkulaw.com既有受控原文路径。正文、raw response、密钥不进入投影持久结果；0命中返回空matches，不虚构案例或案号。包装/类型漂移拒绝，异常沿既有安全错误回执。

[官方案例语义参考](https://mcp.pkulaw.com/docs?doc=6c681f58-4f11-4e0c-98ec-cd6252dfe486)明确search_case的text/size、返回gid/title/case_number/courthouse_name/decision_date/cause_of_action/ascertain/identified/referee_result/url。取数与受控URL逻辑复用已交法规adapter及[官方CLI](https://www.npmjs.com/package/@pkulaw/mcp-cli)0.3.2的Application规则，不新造MCP传输。

本次无厂商业务调用或凭据配置。实际工具完整schema、组织订阅/费用/端点协议仍须正常取得及加载；判决内容展示/保存/传模型/嵌入/跨案复用许可和可确认引用版本仍未知。关键词案例的深度争点/裁判依据25+字段未在本包实现；自动起诉状和裁判救济业务完整链由独立合同继续，不能以可调用候选工具计为完成。


## 独立民事起诉状草稿（6.2 SOURCE，2026-10-06）

本增量不改变法律研究、诉讼准备提纲或裁判分析。新增环境级已发布 `complaint_drafting`，服务端固定 `draft_kind=complaint_draft`；复用原 `legal_analysis@1.0.0`、`sand_ai.legal_analysis@1.0.0` 与 Neuron 三节点及当前 IAM/费用/原号恢复。新profile需要源码迁移 `202610060135_complaint_drafting_profile.pgsql` 扩既有code约束；本轮只保存迁移文件，没有执行数据库升级或发布配置。默认模板是非秘密初始值，不能充当已发布版本。

仅该profile另需 `complaint_input`，旧profile禁止此字段。冻结对象包含 `document_type:"civil_complaint"`、`input_version,party_version,claims_version`（律师显式确认输入版本，不是系统核实）、`parties:[{party_ref,role:plaintiff|defendant|third_party,name,identity_summary,source_block_ids}]`、`claims:[{claim_ref,content,source_block_ids}]`、`evidence:[{evidence_ref,description,source_block_ids}]`、`template:{template_ref,template_version,source_ref,document_type,sections:[{key,title,source_block_ids}]}`、`source_roles:[{source_ref,role:case_material|legal_authority|firm_template,authority_type:null|statute|judicial_interpretation|case|guidance|other}]` 与 `consumer_confirmed:true`。当事人2–20项，须含原告与被告；请求1–20，证据1–30；均引用本次案件材料。已确认事实必须非空且只引案件材料，不用模板/法源代事实。全部来源仍以同次 `legal_sources` 的真实私有file/SHA/parse/version/block集合承载；总共最多40块。

模板首包声明适用民事起诉状，必须固定实际私有来源。`template_version` 严格等于对应file SHA与parse ID的 `sha256:parse_id` 字符串（sha256此处为64个十六进制字符，无前缀）。六个章节key是 `parties,claims,facts_reasons,legal_basis,evidence,closing`，顺序按固定模板选择；每个标题须实际出现在它映射的模板解析块中，不能仅自报标题/版本或传任意模板正文。role与authority_type是消费者作用声明，不代表SandAI独立确认来源法律效力、许可或官方身份；Lawyer由已有法源登记与许可关联生成这些标签。

新run的 `legal_output_contract@2` 按新profile冻结独立 Neuron typed schema；历史旧run的输出schema/语义不变。模型交 `complaint:{sections:[{key,title,content,source_block_ids}],element_matrix:[{element,fact_refs,evidence_refs,source_block_ids}],possible_defenses:[{description,source_block_ids}],missing_evidence:[同],pending_decisions:[同]}`。服务端验证模板顺序与标题、来源白名单、已确认事实/证据ref及相应来源块，当事人姓名和身份、诉讼请求、证据说明按冻结输入保留；再确定性组装 `draft.content`，而非采信模型另传正文。模板不能作为结论/风险的法源。

公开成果在旧外壳追加 `complaint:{document_type,input_version,template_version,sections,element_matrix,possible_defenses,missing_evidence,pending_decisions,authority_gaps}`，所有文字附实际 `source_block_ids` 和field_sources。法规/司法解释不具本次固定私有版本且声明有效时 `legal_basis` 必须空、确定性缺口提示保留；缺相似案例另提示。声明有效亦明确未独立核实效力、引用版本和原平台定位；其它未核实来源只作待核项，不能形成完成的法律依据。草稿始终 `review_required=true`，无自动定稿、法院提交或Workflow动作。

Lawyer消费与编辑保存合同见其后端契约“独立民事起诉状草稿”。当前只交SOURCE，未执行实际Provider/法库查询、正常加载、配置发布、材料解析、页面/API、费用或保存/撤权恢复验收。不勾6.2，也不将本独立私有材料起草分支视为自动争点/检索式→法规/案例合检→合法全文/版本确认的一期专库完整链已完成。


### 2026-10-06：Lawyer人工法源声明消费边界（SOURCE）

Lawyer在现已许可真实私有材料/固定parse/block绑定上增加独立人工声明：新 `consumer_import` 快照定位保留 `consumer_authority_declaration`，`authority_state=consumer_declared`、`officially_verified=false`，当前可信human/org/app/env及服务端UTC声明时间、明确适用版本/日期范围、真实依据块和原定位全部不可变。原unknown/供应商注记及绑定不变；URL只元数据不访问。

Lawyer BFF 的 `POST cases/{id}/legal-analysis/source-declarations`、同 `/by-request-key` 原号纯GET和 `/binding-sources/{sourceRef}?binding_request_id=原绑定号` 真实原文纯GET的精确字段见其后端契约第22节。显式重新绑定后版本包含私有SHA与声明SHA；消费方在提交及原运行/保存读取重验当前许可/私有来源版本，`legal_context.as_of_date` 必须与声明适用日一致且不超范围，未知声明拒绝消费。纯GET拒读不改持久记录，只清消费UI私密展示。

SandAI现来源合同没有新增body字段或平行效力核验服务。消费方传递本次已授权的固定私有来源与 `declared_effect_status`；只声明有效的法规/解释可支撑起诉状法律依据，来源角色仍是消费声明，结果明确未独立核验并保效力/版本缺口。检索candidate只能通过当前原号查回后预选登记关联，不能自动成为确认法源。无新增表/IAM/迁移；SOURCE不代表运行配置、真实法源数据许可或验收完成，6.2保持未完成。


## 冻结事实争点规划与双路检索编排（6.2 SOURCE，2026-10-06）

本次增量将一期自动检索编排落为消费侧有界操作，不改变 SandAI 默认厂商，也不将北大法宝的一期选择变成统一产品必选。模式 `legal_research_planning`（`draft_kind=research_plan`）须由管理员沿现法律分析配置创建并明确发布；136 CHECK 迁移及 install/update 入口仅为源码，尚未执行。模式复用正式 `legal_analysis@1.0.0` TaskGateway 和 Neuron prepare/completion/validation Workflow 节点，旧研究备忘录、诉讼提纲、裁判分析及起诉状语义保持各自正式入口。

### 正式发布版本只读入口

`GET /api/sand-ai/v1/tools/{code}/versions/{version}` 无查询参数、无正文；沿现 `sand_ai.tool.read` 验证当前工作负载。仅返回 `{code,version,environment_id,state:"published",effect_class:"read_only",input_schema,output_schema,snapshot_sha256}`。服务端复用受管只读工具目录重验当前环境绑定、连接修订、真实 MCP schema hash 和发布版本；不返回端点、凭据或厂商响应，不调用工具。读取失败不能推断工具已尝试或尚未提交。

### Lawyer 独立操作

- `GET /api/lawyer/v1/cases/{id}/legal-research/orchestrations`：当前人员/案件权限下读取准备材料、事实、已发布规划模式及双路选择；工具选择来自 `server/config/lawyer.php` 的 `integrations.legal_research_orchestration`，环境变量 `LAWYER_LEGAL_RESEARCH_LAW_VERSION`、`LAWYER_LEGAL_RESEARCH_CASE_VERSION`、`LAWYER_LEGAL_RESEARCH_LIMIT`。版本默认0代表未选择，必须由管理员先沿现 SandAI 受管 MCP 发现、配置、发布及环境绑定入口准备真实版本；不新增秘密配置或授权体系。
- `POST` 同路径，须有原 `Idempotency-Key`：精确输入 `{material_snapshot_id,fact_version_id,binding_request_ids,planning_profile:{code:"legal_research_planning",version},legal_context:{issues,jurisdiction,case_type,as_of_date},tools:{law:{code:"pkulaw_article_search",version,limit},case:{code:"pkulaw_case_search",version,limit}}}`。快照/事实ID为正整数，binding原号1至10条，只能是真实本案快照成员的 `case_material`；来源块最多40。研究目标1至5项各最多500字，仅标为使用者目标；不能变成事实。法域/案型各最多128字、日期须真实YYYY-MM-DD。版本为管理员发布的固定正整数、limit1至20，无 environment_id 请求字段。
- `GET` 同路径 `?request_id=<原号>`：当前人员、工作负载、案件及私有来源复核后，纯读原 planner task 与每路原 Search 请求，不创建记录、不推进阶段。

规划结果 `research_plan={issues:[{issue_ref:"issue_1"…"issue_N",summary,fact_refs,law_query,case_query}],review_required:true}`，争点1至5；summary最多500字、两个检索式各最多1000字。每项必须引用冻结事实白名单中的实际 fact_ref，并将其真实来源块的并集绑定到成果引用；模型不能联网、核实法律效力或发出法源确认。JSON计划通过既有 Neuron 结构化完成后再作业务来源校验。

消费侧先持久保存固定 body、事实/材料来源、当前 human/case/env/client、两版本发布凭据、原 planner 与原分支 key，以及尝试标记，再执行正式提交。成功计划与精确两路查询在搜索前封存；成功子请求只读复用。未知、404或缺记录绝不解释为未提交。已有 outer 请求的显式 POST 只有当前纯 GET 的 `can_continue=true` 才推进确证未尝试阶段；已尝试问题不能重派。父分支标记已写但 child 记录缺失时仅保未知原号。已有 child 仅在原 GET 明确可继续且没有未决项时用相同原号/相同 body 续其真正未尝试查询。部分失败保已完成候选，错误仅安全中文说明，不透传厂商 payload。

返回状态 planning/searching/unknown/failed/awaiting_source_confirmation；包含 planner 原号、plan、两 branch 原号/state/can_continue/items、候选 branch/request_id/query_index/candidate_ref。`legal_sources=[]`、`next_action="confirm_sources"` 永久保候选与确认边界。页面入口 `/#/lawyer/cases/:id/legal-research`；选候选仅导航到原案件来源登记区，携带 child `research_request_id` 与 opaque `research_candidate_ref`，由该入口当前授权纯 GET 核对再预选，不自动登记、声明、绑定或起草。

实际开通仍需当前正式 IAM/CaseAccess、已发布规划模型配置、真实厂商 tools/list schema、当前环境工具绑定及商业账号条件；本文没有声明这些已具备。官方 CLI 参数支持不等于全文缓存、展示、传模型、跨案复用许可，也不提供独立引用版本或已核实效力。人工私有全文许可、SHA、效力/版本声明使用已独立交付的明确确认链；候选与规划不能绕过它。本批仅 SOURCE，未安装、执行迁移、配置凭据、调用模型/法库、测试、构建或业务验收，原6.2完整业务与费用闭环仍未验收。

文件解析 OCR 同样遵守未结派发门。原 DELETE 的内容清理事务在任何正文或私有对象删除前，按原文件和环境锁定全部解析父任务；锁竞争、非终态、派发归属不一致、缺失或 `uncertain` 结果返回现 `SAND_AI_RESOURCE_CONFLICT`，不因父任务取消而删除。只有完整证据证明从未派发 OCR，或全部派发已有不可变 `response_known` 结果且父任务终态，才继续原清理；OCR 证据结构不可用沿现 Schema 拒绝关闭。父任务锁与既有 OCR `start` 的运行租约及栅栏核对共用，阻止迟到准备穿过删除事务。原派发、请求计量、费用和审计保留，无新增公开字段、迁移、派发或费用算法。

后台知识草稿创建未决时，按原管理员／环境／请求元数据保留原意图，仅由用户显式以原幂等键及原输入调用既有 POST 恢复；普通错误或404不证明未执行，不自动换键。正式回执须核对原code／version／source_count及输入SHA；恢复锁仅持久不透明元数据，身份或环境切换隔离原意图，不持久正文或凭据。

## Embedding 部署配置与原保存键找回（4.4 源码候选）

首次开通页增加 Embedding 部署配置，沿现有部署保存、验证、读取和发布接口。新部署的逻辑模型使用 `type: "embedding"`、`capabilities: ["embedding"]`，配置为 `{}`；不新增维度、厂商、价格或容量字段。发布后仍需管理员在目标环境选择已发布部署并发布能力档，知识索引沿现有入口消费。连接密钥仅进入原保存请求，不持久保存到浏览器会话。

新增 `GET /app/sand-ai/admin/setup/deployment/by-request-key?request_key=<原保存 Idempotency-Key>`，只接受字符串查询字段 `request_key`，正文必须为空。原键按现部署保存规则 trim 后非空、最长 128 字节且不含 CR/LF。入口沿当前后台登录及 `sand_ai:setup:deployment:read` 权限；管理员身份只来自当前登录态，不接受请求自报身份或环境。

部署草稿是现有全局后台配置，不新增环境字段。服务端沿 `sand_ai_config_revision.payload.draft_save` 的 `draft_save@1` 回执，复核现有 `key_version`、`key_id`、原请求键与当前管理员参与的 HMAC，要求只有一个已保存修订匹配，再复核原部署、修订和保存回执。没有公开正文 SHA 字段，不增加或推测该字段；不需要重建含凭据的原请求。

成功返回现有 `DraftSave` 安全投影，`deployment_id` 和 `revision` 指向原保存修订，`state` 来自该修订的真实当前状态，`idempotent_replay: true`。不返回 HMAC、密钥、连接配置、原请求、服务商原始响应或回执正文；不保存、验证、发布或改写任何记录。成功及控制器处理的失败响应均为 `Cache-Control: no-store`。

未命中返回现有 `SAND_AI_RESOURCE_NOT_FOUND`；多匹配、回执或链漂移拒绝读取。未命中、网络失败、权限拒绝或无法核对均不证明原保存未提交，不允许换键创建新草稿。Embedding 页面按原管理员和保存键保留不含秘密的会话标记，刷新后使用该 GET 找回；只有确认原部署类型为 Embedding、原修订与空配置一致并完成正式只读状态读取后才解除保存锁。拒权或身份变化清除私有表单和旧成果显示，原不透明历史标记保留。恢复不会自动验证或发布。

本增量仅为源码候选；未运行保存、验证、业务调用、数据库或页面验收，4.4 仍保留原真实验收门。


## 2026-10-06 可配置Neuron官方专业重排 SOURCE契约

消费接入者在已有知识库查询入口显式选用专业重排，仍由当前 `sand_ai.knowledge.query` publication/version/sourceScope 与 organization/application/environment/workload client 四维授权控制。专业模式不要求 `sand_ai.chat.complete`，也不授予模型目录、调用记录或费用读取权限。原独立 `sand_ai.invocation.read` / `sand_ai.usage.read` 费用路径继续生效。

- `GET /api/sand-ai/v1/knowledge-bases/{code}/rerank-models`：无 query 参数，按当前知识库授权返回 `data: [{model: string, display_name: string, rerank_mode: "professional"}]`。目录只含当前环境已发布 profile 的 rerank primary 路由与正式 published rerank deployment 匹配的可用官方模型；`model` 是查询提交的模型 code，不是 deployment id。目录不返回 provider、remote model、凭据或配置，不选择默认模型、不自动开通服务。无可选专业模型时不得将 chat 模型混入或自动降级。
- 现有 `POST /api/sand-ai/v1/knowledge-bases/{code}/query` 和 `POST .../query/by-request-key` body 保留 `{query, limit?, rerank?, model?}`，只新增可选 `rerank_mode: "professional"`。该字段只有 `rerank: true` 时可用，且必须显式提供 `model`，`limit` 在 1..10；`rerank: false` 不接受 `model` 或 `rerank_mode`。其他 marker 值无效。
- 不含 `rerank_mode` 的请求保持旧 chat 重排规范化 body、指纹和 outcome receipt 路径；规范化时不注入默认 mode 字段。专业 mode 原样参与完整 query intent fingerprint；同一原幂等键不得切换专业/chat 模式、model、发布版本或检索来源。child request id 继续是 `knowledge-rerank:` 加原 knowledge query key 的 SHA-256；既有 chat child invocation/拒绝不能成为专业调用的恢复或降级入口。
- 专业 query 在可能付费的 query embedding 之前检查正式 model 和已发布 rerank profile；网关每次外发前后复核当前 knowledge 授权、发布/version/sourceScope、检索来源及专业路由。仅向专业网关传入已授权检索候选，不添加新来源。
- 专业 response 增加 `rerank_mode: "professional"`；成功排序为 `rerank_state: "reordered"`。每个 citation 保留原检索 `score`，另有由真实专业结果提供的 `rerank_relevance_score: number`，不得按名次制造分值。无候选/单候选分别为 `no_sources`/`single_source`，只核配置、授权和来源，不外发，不生成 invocation/usage，也不制造专业分数。未知状态、无效评分或不匹配来源严格失败，不退回 chat 或无付费排序成功。
- 原请求找回必须提交原 body、原 `Idempotency-Key`。专业 `query/by-request-key` 只调用原 intent/readOriginal 与原专业网关 readOriginal；不 admit、不外发、不 finalize、不 settle、不回填。未完成/失败/原来源变更保持对应错误，不发起替代请求；成功恢复只读取原收据与原计量。
- 所有目录、查询与找回响应使用 `Cache-Control: no-store`。

## 调用、计量与预算

# 专业重排源码合同追加（5.1）

仅 SOURCE 候选，不代表已加载、测试、构建、迁移、安装、真实外发或业务验收。未调用模型、未启停服务、未改密钥、未操作 Git。

- `rerank_mode=professional` 使用 `capability_code=rerank` 的当前已发布 primary 能力配置；请求 model 必须精确等于所选专业模型 code。driver 唯一来自 provider.adapter：`cohere_rerank|jina_rerank`。route_kind 沿 `model_deployment`，deployment 不添加第二 driver。
- 外发归属保持知识权限：`source_kind=knowledge_query`、`service_action=sand_ai.knowledge.query`、`capability_code=rerank`；保存 publication/version/source_scope/data_class，消费 SandIAM 当前授权 callback，无平行 IAM、无 chat 授权替代。
- 子键沿 `knowledge-rerank:sha256(originalkey)`；专业 intent fingerprint 显式含 mode/protocol/driver/完整当前 route/来源/身份/候选。现存旧 chat 同键返回 `SAND_AI_IDEMPOTENCY_CONFLICT`；旧无 marker 调用仍由旧 chat gateway 只读恢复，新专业网关无 chat 降级。
- 0/1 来源只核当前合法专业配置与输入，返回 `no_sources|single_source`，`invocation=null,usage=null`，不预算、不建调用、不外发。当前来源访问由上层已重新授权；不制造 invocation ID 调 callback。
- 成功 response 沿 `{citations,invocation,usage,state:'reordered'}`，每条 citation 保留原 `score`，额外 `rerank_relevance_score` 为服务真实分数，不添加或遗漏来源。
- 费用 response 沿 UsageGateway：`usage.{state,metering_unit,metering_quantity}`；unit 为 `rerank_search_units|rerank_tokens`，quantity 为十进制整数字符串，未知为 null。`tokens.input_tokens/output_tokens/total_tokens` 全为 null；`price` 与现费用接口字段一致。
- Cohere 只采 `meta.billed_units.search_units`，Jina 只采 `usage.total_tokens`。非负实际整数（包含 0）表示 measured；缺失、非整数、非法字段保留 unmeasured/null，禁止候选数量/UTF8 字节猜量或猜 0。
- Admin验证 receipt/evidence 增加 `rerank_meter_code` 和 `rerank_quantity`；`usage_measured=(rerank_quantity!==null)`；token 三字段 null。验证调用费用使用管理员平台 scope，失败排序仍保留真实费用，不作为 passed。
- SOURCE 对官方 Cohere `https://api.cohere.com/v2/rerank`、Neuron 实际 `model/query/top_n/documents` 请求体和已明确处理限制的模型形成容量证明：`rerank-v3.5`、`rerank-english-v3.0`、`rerank-multilingual-v3.0` 的 query 上限为 2048 tokens，`rerank-v4.0-pro`、`rerank-v4.0-fast` 为 16384；v2 未覆盖时 document 默认截断 4096。按官方每 500 query+document tokens 拆计费文档、每 100 文档一 search unit，保守预留 `ceil(N*ceil((4096+query_limit)/500)/100)`，N 为实际 2..10 个文档。该推导是预留上界，实际量仍只采厂商 billed_units。证明绑定端点、模型、请求体摘要及原冻结路由/价格，claim 与发送前重验；预留金额按 decimal6 向上取整，实际计价保持原舍入口径。Jina、未知模型、自定义端点或请求形状没有官方证明时上界仍为 null，hard或含hard的混合预算在外发前返回 `SAND_AI_BUDGET_CAPACITY_UNVERIFIED`，保存原键 failed，未建派发/未外发。soft预算沿原未定价策略允许未知费用待结并提醒，取得真实量后按原冻结价格结算，缺量或价格未确认保held；无预算可以按真实服务量计价。容量证明不能用本地 token 估算代替，也不证明账号实际账单或费率已验收。
- 有合法上界时 reserve event 绑定 body digest/route digest/unit/upper bound/原价格 revision；共同 claim 再锁当前预算成员、原快照、原费率、route，再放行。原价格 revision 在调用前冻结。
- 已 HTTP 外发且失败但实际 quantity 已知：原调用 failed，ledger failed，按实际 unit 记 immutable usage，原预算实际 settle（无价格则 hold）。已外发且量未知：immutable usage unmeasured，ledger ambiguous，原预算 hold，禁止自动重发/换 key/降级 chat。
- 已 claim 但 HTTP boundary 未开始的失败：ledger failed、原预算 release，明确未 HTTP 外发。prepared claim 前授权/配置失败沿现 not_dispatched/release。
- 成功评分而计量未知：可密封真实排序，state reordered，原预算 hold；未知量不改零费用。
- 错误评分、遗漏来源或回执失败会记录实际费用并失败，不回退旧序。真实费用封存/终结失败保原 unresolved key；`readOriginal` 不执行任何 finalizer/settle/release/外发。
- `readOriginal` 仅 SELECT、加密回执 replay 与 current source/identity/route/fingerprint 再授权；核调用/派发/价格/真实单位数量与金额一致，财务不写。pending/dispatching/ambiguous 返回 `SAND_AI_DISPATCH_AMBIGUOUS`。缺回执、变更事实等保持原键并报 `SAND_AI_RECEIPT_INVALID`，不重新调用。
- send 后访问授权失效：先收口已发生实际计量；随后恢复读取当前授权失败，不返回私人来源结果。

迁移 `202610060137_knowledge_professional_rerank.pgsql` 仅 SOURCE：扩专业 meter、scope，保留既有 CHECK arm；管理员专业 receipt 两字段、真实量价绑定 guard；保持现财务事件/调用价格 immutable。未执行迁移。

## 正式配置与价格目录

## 5.1 专业重排正式配置 SOURCE 候选（2026-10-06）

本候选使用现 provider.adapter 唯一驱动：`cohere_rerank`、`jina_rerank`；model.type=`rerank` 且 capabilities 精确为 `["rerank"]`。deployment.remote_model 来自管理员实际选择的模型标识，deployment.config=`{}`。不在 config 重复保存 driver，不设全局默认服务商。现 provider.encrypted_config 继续沿 base_url、api_key、extra_headers、response_max_bytes、idempotency_mode/header 加密写入与 HMAC 指纹；禁止回显凭据、地址或原服务商响应。环境专业能力沿已发布 capability profile 的 capability_code=`rerank`、route_kind=`model_deployment`、deployment_id；不得选择聊天/Embedding 模型或其他 adapter。

### 部署生命周期 API

- POST `/app/sand-ai/admin/setup/deployment/save`：沿现 `Idempotency-Key`，新增专业输入 `{provider:{code,name,adapter,timeout_ms},model:{code,name,type:"rerank",capabilities:["rerank"]},connection:{base_url,api_key},remote_model,config:{}}`；现 provider_id/model_id/deployment_id 选择形式继续有效，类型/驱动必须匹配。仅保存不发送服务调用。
- GET `/app/sand-ai/admin/setup/deployment/by-request-key?request_key=...`：当前管理员原保存只读找回；完整 DraftSave DTO沿现返回，provider含adapter，model含type/capabilities，deployment含remote_model/config。页面只保存管理员、opaque key、driver、模型/config SHA，不存凭据或请求正文；同页未知重试固定同body/key，刷新后只能GET找回。
- POST `/app/sand-ai/admin/setup/deployment/verify`：沿原 `{deployment_id,revision,processing_location}`、`Idempotency-Key`，显式发送一次非敏感固定示例，复用官方 Neuron专业 PostProcessor。GET `/app/sand-ai/admin/setup/deployment/verification/read` +原key只读核原尝试。专业验证证据 `version="deployment_validation@3"`，`usage_measured=rerank_quantity!==null`，`input_tokens/output_tokens/total_tokens=null`，新增 `rerank_meter_code` 与 `rerank_quantity`。Cohere单位 `rerank_search_units`，Jina单位 `rerank_tokens`；quantity为非负int或null，真实0可0，缺值不猜。计量与费用归平台验证，不借工作负载身份。已响应排序无效保持失败并记录真实量；未确认响应保留unknown与原key，不重发。
- POST `/app/sand-ai/admin/setup/onboarding/publish-deployment`：沿 `{deployment_id,revision}`，只有正式tested证据及当前配置指纹有效才发布；不自动发布环境能力路由。
- 已有错误码原样：`SAND_AI_VALIDATION_ERROR`、`SAND_AI_CAPABILITY_UNSUPPORTED`、`SAND_AI_CONFIGURATION_DRIFTED`、`SAND_AI_IDEMPOTENCY_CONFLICT`、`SAND_AI_DEPLOYMENT_VERIFICATION_IN_PROGRESS`、`SAND_AI_DEPLOYMENT_VERIFICATION_AMBIGUOUS`；官方响应不合格沿`SAND_AI_PROVIDER_FAILURE`，不将聊天token补成专业用量。

### 专业价格目录 API

- GET `/app/sand-ai/admin/pricing/revision/index`：筛选provider_id/model_id/capability_code=`rerank`，原分页summary；GET `/read?id=...`取得该版本及rates。
- POST `/app/sand-ai/admin/pricing/revision/save-draft`：现字段 `{provider_id,model_id,capability_code:"rerank",name,currency,effective_from,effective_until?,rates:[{meter_code,unit_price,per_quantity}]}`。currency为3位大写，日期为精确UTC `YYYY-MM-DD HH:mm:ss`；unit_price非负 decimal(20,8)字符串，per_quantity正32位int；Cohere只允许一项rerank_search_units，Jina只允许一项rerank_tokens。DTO阶段先允许专业单位之一，事务锁provider/model后核实际adapter；publish再核同一单位。
- POST `/app/sand-ai/admin/pricing/revision/publish`：沿 `{id}`；返回原价格版本DTO，发布不可变。原权限` sand_ai:pricing:index/save/publish`复用。
- 原价格保存API没有请求键，本候选不包装为可安全重发。保存已返回id时保opaque id+规范DTO SHA，GET目录/read确认该编号；未回id结果未知时仅显示目录并锁住后续写操作，由管理员核查原操作，不借同内容另一个版本冒称原结果。发布未知按原已知id只读核回，不自动补发。

### 入口与费用边界

模型管理页显式展开“专业重排配置”；专业部署草稿存在时才显示该provider/model价格面板与实际单位。管理员显式保存、验证、发布；当前应用环境专业路线另在能力配置发布，消费端专业重排由知识查询面板显式选择。官方 Cohere 已明确模型/端点/实际请求体可按上述容量证明预留，hard及含hard的混合预算仍须具备有效同币种冻结价格、正数上界金额及全部预算余量；没有容量证明的 Jina、未知模型或自定义端点继续拒绝并保原key未发送状态。soft预算沿原未定价策略允许未知费用待结并提醒，实际量按冻结价格结算，缺量保held；源码容量证明不声称真实预算业务或账号费率已验证。

仅SOURCE：本候选未执行测试、静态检查、构建、模型HTTP、数据库迁移、服务启停、提交或部署；5.1真实业务门不勾完成。

## 2026-10-06 专业重排软预算 SOURCE 契约

## Policy and finance

- Hard, including any hard member in a mixed scope group: null official upper bound remains SAND_AI_BUDGET_CAPACITY_UNVERIFIED before dispatch. An original rejected key never automatically retries.
- Soft: unpriced_policy=block still rejects SAND_AI_BUDGET_UNPRICED if no published price revision. A known frozen price with unknown quantity/cost bound is distinct from an unpublished price, and is admitted as a pending obligation.
- soft unpriced_policy=allow can admit an unpublished original price, preserving that exact unpriced snapshot. A later price cannot be borrowed for settlement.
- unmeasured_policy=hold is applied after a provider call whose actual quantity/cost remains unknown. unmeasured_policy=allow permits the business outcome to continue; it does not convert unknown money to zero or to financial settled state. Both settings remain frozen and revalidated before dispatch.
- Pending obligations carry metered_amount=NULL, no estimated amount, no monetary reservation and no fake positive/zero estimate. Current known reserved/held/settled amounts remain governed by the existing window. Actual 80/90/100 percent alerts reuse BudgetAlertPolicy; pending is a separate cost warning, never a fabricated exhausted threshold.
- Original policy membership/revisions, identity, current period, body/route/price snapshot and prepared expiry are checked under current selection locks before send. Revoke/change/expiry rejects and releases the provably unsent original obligation. Windows lock before pending rows and invocation/dispatch rows; all operations remain inside the existing preparation/claim/finalization transactions.
- Real provider integer quantity is recorded by the existing immutable usage path. Original frozen price revision and actual metered amount settle each applicable soft window atomically. Known real zero amount can settle zero; missing quantity or price remains NULL/held with a budget.soft.held audit event. Invalid scores with known usage still settle actual incurred cost.
- Provider-started ambiguous/error outcomes retain held obligations and original dispatch state; no resending, switching key or chat fallback. Read-original remains SELECT/replay only and never writes finance.

## Public DTO

Every professional Gateway normal return, including zero/one sources and historical professional invocations without this new ledger, has:

    budget: null | {state:'soft_cost_pending'|'held'|'settled'|'released',warnings:Array<{code:'SAND_AI_BUDGET_COST_PENDING'}>}

Internal prepared maps to soft_cost_pending. Only pending/held carry exactly one warning; settled/released warnings are empty. No policy ids or budget-private amounts are exposed. Projection is dynamically read after existing invocation/source authorization, and is not inserted into the sealed result fingerprint. The existing usage quantity/unit/state/amount DTO is unchanged. Legacy chat rerank is unchanged.

## Persistence and delivery

Migration 202610060138_knowledge_professional_rerank_soft_budget introduces only sand_ai_rerank_soft_budget, its index, unique/strict nullable-cost constraints, and sand_ai_rerank_soft_budget_guard_v138()/trigger. The guard binds original invocation/dispatch/price and immutable terminal usage, and prevents deletion or terminal overwrite. Install/update register exact migration checksum; update refuses unregistered conflicting owned objects. Uninstall declares only these new owned objects with exact registered-checksum protection; original migrations through137 remain intact.

SOURCE only. No tests, static checks, lint, SQL/database execution, builds, TypePHP, Git, services, hosts, keys, business HTTP, deployment or business acceptance were performed.

## Subsequent hard admission and concurrency

BudgetPolicyService::applicable(lock=true) is the common admission gate. Under its existing sorted scope/current-policy locks, the new helper locks the complete sorted current/original window set, followed by pending members. The complete set includes a prepared claimant's original standard reservations and soft members before the normal invocation/dispatch locks. A prepared or held NULL-cost obligation in the same scope_kind/ref and intersecting original/current budget periods refuses a current hard member with SAND_AI_BUDGET_CAPACITY_UNVERIFIED. Policy replacement or mixed scopes cannot hide an obligation by changing policy id. Released/settled obligations and nonintersecting prior periods do not block. This is a capacity refusal without an estimated money amount or currency conversion.

ChatBudgetGuard and the professional prepare method keep selection inside their existing rejection-record try so the original rejected key is retained. PreparedBudgetPolicySelection defers this capacity problem until normal claimed-scope refusal can release the original provably unsent reservation/soft obligation. A dispatched held obligation is not released for policy change. All common lock=false reads remain pure SELECT with no admission refusal, locks or finance mutation. Known actual threshold events continue to use existing window amounts; unknown liabilities carry their separate business audit event.

## 冻结 API 响应

仅 `rerank_mode:"professional"` 的知识查询及原请求只读找回结果增加 `budget`：

```text
budget: null | {
  state: "soft_cost_pending" | "held" | "settled" | "released",
  warnings: [{code: "SAND_AI_BUDGET_COST_PENDING"}] | []
}
```

- `soft_cost_pending` 与 `held` 必须且只能返回一项 `{code:"SAND_AI_BUDGET_COST_PENDING"}`；`settled` 与 `released` 必须为 `[]`。`budget=null` 用于 0/1 来源或不存在适用 soft ledger 的专业结果。warning 表示费用仍待确认，不能解释为已超额度，也不表示可免费使用。
- budget 由专业 accounting gateway 的当前纯读取投影提供。Controller 的 query/by-request-key 复用同一数组 guard；缺少显式 budget、未知状态、额外字段、非法或不匹配 warning 均沿既有 `SAND_AI_RECEIPT_INVALID` 严格拒绝。不默认 null、不制造状态，不回显 policy id、预算内部金额或凭据。
- 不含 professional marker 的旧 chat 请求保持原响应，不增加 budget 字段；请求 body、完整 intent fingerprint、child id 与原加密 outcome receipt 不变。历史专业收据不要求存储 budget；没有 pending ledger 时由当前 Gateway 返回 null。动态预算状态不得写入 sealed outcome，也不能改写旧收据以补字段。
- query/by-request-key 保持原 body、原 `Idempotency-Key` 与原专业 `readOriginal` 路径，只读当前 budget；不触发新的 admit、外发、重发、finalize、settle 或 release。结果来源及知识授权沿已有原请求复核。
- 费用仍由原 usage 返回真实 metering unit、quantity 与 money 字段；不能根据 budget warning 猜测零量、零费用或 chat token。

## soft 与 hard 预算边界

新 `soft_cost_pending` 表达缺少可证明专业计量上界时的 soft 预算待确认状态；实际 selection/ledger 判定由本轮独占 accounting writer 负责。既有 `ChatBudgetGuard` 的 settle 路径在 `meteredAmount=null` 时保持 `held`（原事实位置 435/464），因此费用或金额未确认时可以继续返回 held，不能将 hold 当作已经 settle 的零费结果。

mixed selection 中的 hard 预算仍在无可证明上界时拒绝，现有 soft unpriced block 策略保留。本次专业 soft 接线不声称硬预算容量已可用，不放松硬限制，不把未知费用 warning 当作超额许可。

## 原裁判分析包与救济研究、模板草稿（6.2 SOURCE）

本增量对应 Lawyer 一期方案第6节、详细设计3.5及消费者要求4.2；复用现 `legal_analysis@1.0.0` TaskGateway 与 Neuron Workflow。已确认审级的程序、研究目标、基准日、送达/生效/起算事实是律师声明，不是系统判断。系统不决定是否救济、不计算期限、不确认日期、不创建法院或检察办理任务、不提交或发送文书。

### 四段明确操作

1. 当前人员选择本案确认审级、冻结材料、真实判决解析绑定、此前文书版本及其私有绑定、已确认事实/证据目录。先固定版本与 SHA，再用 `judgment_remedy_planning` 输出原裁判七字段结构和检索计划，沿两个独立已发布版本检索法规/案例。规划与候选不自动确认法源。
2. 律师核对候选，沿既有私有全文登记、许可确认、人工引用版本/效力/适用日声明和新绑定入口完成真实来源，再明确提交 `judgment_remedy_analysis`。此阶段实际消费获授权法规/案例，交付理由前后对照、支持/不支持因素、程序事实待核问题；不能沿旧无法源占位结果冒充完整裁判分析。
3. 律师读取原分析，明确选定程序所对应的真实本案草稿文书和私有模板章节，提交 `judgment_remedy_drafting`。模板标题必须对应本次真实解析块；不硬编码新救济文种枚举，不从文件名推断程序。模板是布局数据，不能替代事实或法律依据。
4. 律师编辑、删除或采用草稿和引用，明确保存到现本案 draft 文书的新版本。原草稿和保存号仅只读恢复；不自动定稿、发送或进入业务流程。

三个配置均由现法律分析管理员入口明确发布。139迁移和安装/升级入口仅源码；不得替代已占用137/138，也没有执行数据库升级。真实IAM、案件权限、已发布模型与工具版本、完整MCP schema、商业账号、数据许可和引用版本事实仍是实际启用条件；未运行不能据此倒推源码欠账，SOURCE不证明完整6.2业务验收。

### 成果不改原七字段名称

`judgment-analysis-package.v1` 保留 `judgment_findings`、`claim_comparison`、`remedy_research_questions`、`procedural_risks`、`missing_information`、`outline`、`citations`。判断书引用的条文只是判决原文，不自动成为经确认法源。

- judgment_findings：`{kind,description,source_block_ids}`，kind 区分主文、事实、说理、援引依据、费用责任；引用实际判决块。
- claim_comparison：`{description,status,prior_source_block_ids,judgment_source_block_ids}`，status 区分 accepted/not_accepted/unanswered/requires_review；不把待核对比变成确定裁判结论。
- remedy_research_questions：`{description,supporting_source_block_ids,contrary_source_block_ids}`，正反因素保真实来源。
- procedural_risks/missing_information：`{description,source_block_ids}`，保留待律师核验标识。
- outline：`{key,title,content,source_block_ids}`；分析阶段为空待选模板，明确起草后与本次实际模板章节一一对应，再由服务端组装可编辑正文。
- citations：实际来源块整数列表，由消费者转成当前原成果可读取的 opaque 引用，不暴露私有存储 key、永久URL或厂商原始响应。

额外 `legal_basis`、`case_authorities` 为 `{source_ref,description,source_block_ids}`；只接受本次明确声明且适用日匹配、当前可读的真实法源类型。规划阶段为空；分析和草稿阶段保已确认法规/案例引用及缺口。人工有效声明不等于独立官方核验。规划额外 `research_plan` 仍使用真实 fact_ref 白名单、1–5 争点、两类检索式及 review_required；研究目标不是事实。完整目标未被截断或替换为模型选择。

每段固定 human/case/env/client、审级/判决/此前文书/事实/证据及法源版本和原请求键；当前源权限或原版本变化拒绝继续。未知只读原号；404/缺记录不表示未提交。只有当前 GET 的 can_continue 和未尝试标记能支持显式同号继续。成功结果封存复用，失败/未决不得换号隐式重派。草稿引用新增/排除必须明确，保存后仍待律师复核。费用只从现受管任务/工具费用归属读取，缺少收费数据保未知，不从Token或候选数猜金额。

### 精确消费HTTP入口

统一前缀 `/api/lawyer/v1/cases/{id}/remedy-analysis`；所有 GET 无正文、不执行模型或工具，不创建或推进请求。

| 后缀 | GET | POST 精确字段 |
| --- | --- | --- |
| 空 | 无参数准备；`request_id`恢复原规划/双路号 | `stage_id,stage_version,material_snapshot_id,fact_version_id,evidence_catalog_version_id,prior_document_version_id,judgment_binding_request_id,prior_binding_request_id,binding_request_ids,planning_profile,tools,jurisdiction,confirmed` |
| `/results` | `request_id`读取原完整裁判结果 | `research_request_id,legal_profile,binding_request_ids,confirmed` |
| `/drafts` | `request_id`读取原模板草稿 | `analysis_request_id,legal_profile,binding_request_ids,template_binding_request_id,template_sections,document_id,confirmed` |
| `/drafts/documents` | `request_id`读取原保存回执 | `request_id,document_id,expected_document_version,content,source_refs,confirmed` |

POST各有独立原 `Idempotency-Key`。planning_profile固定 `judgment_remedy_planning`，results legal_profile固定 `judgment_remedy_analysis`，draft legal_profile固定 `judgment_remedy_drafting`，均 `{code,version}` 来自当前正式发布配置。tools为law/case两个固定已发布版本和limit；confirmed必须true。research外部body不允许浏览器自填stage日期、模型、原结果内容或法源效力；服务器派生真实确认审级，保留完整研究目标。results的新增绑定只新增人工确认法源，不替换原判决/此前文书/事实/证据；draft的新增模板只布局，不替换原分析法源。法源/模板可来自本案后续真实私有版本，不要求它们已经存在于研究前快照，但各自许可、来源SHA/parse、声明日期、当前授权须独立通过。

template_sections为1至12个 `{key,title,source_refs}`，opaque来源必须对应选定模板当前私有解析块，title来自实际原文；document_id须本案当前draft文书，真实document_type由服务端读取，不新增硬编码救济文种枚举。

- `/cost`、`/results/cost`、`/drafts/cost`：GET仅`request_id`，当前正式任务/工具费用归属；无实际计费数据则unknown，不能猜。
- `/sources/{sourceRef}`、`/results/sources/{sourceRef}`、`/drafts/sources/{sourceRef}`：GET仅`request_id`，当前原成果引用读取。
- `/bindings/{bindingRequestId}/sources/{sourceRef}`：GET无参数，选模板等人工准备时读取当前case/actor实际已绑定私有原文，不调模型。

草稿通过服务器当前原分析的实际run/task与完整payload SHA，并冻结actual analysis_package及其独立SHA，供模型按私有模板组织文稿。package摘要校验不冒充对完整上游payload的独立核验；完整payload/current授权/版本一致性由消费侧重验。既有16k模式保持原限与旧schema哈希语义；救济draft按已发布profile冻结的content_limit限制。新保存入口仅为可配置模板正文采用明确2MiB请求上限，编辑内容仍不得超过原草稿published content_limit及绝对500000字符；不截断、不扩大模型输出限，旧64KiB消费接口及民事起诉状保存边界不变。

安全失败沿现Lawyer响应壳：只在本次目标POST尚无记录或副作用时标not_submitted；unknown保持原号且不可重派。撤权、不属于当前案件、原审级/材料/分析/法源版本变化、超模板或正文限均拒绝并保可操作中文说明；不返厂商payload、存储key或凭据。本批只是这些HTTP行为的源码，没有执行GET、POST、模型、工具或保存。


### 案例语义筛选独立发布合同（6.2 SOURCE 候选，2026-10-06）

新增独立 `pkulaw_case_search_filtered@1`，不修改 `pkulaw_case_search@1` 的 query/limit、指纹或历史回放。管理员在 `config/sand_ai_pkulaw.php` 的 `search_case_filtered` 显式启用，并提供实际订阅 tools/list 的 remote_name/input_schema/output_schema，以及明确开放的 `exposed_filters` 原字段名；不能猜字段、类型或枚举。真实 remote_input_schema/output_schema 完整保留在受管 MCP 快照，正式已发布版本固定本地 input_schema、映射与开放字段；现发现、发布、环境绑定、正式 MCP/Neuron 执行和 IAM/费用链继续负责运行。配置本身不自动发布或授权。

query/limit 仍映射实际 text/size。仅 remote properties 实际声明且管理员开放的其他字段进入本地 Schema，以原名和原值透传；远端必填的额外字段没有开放时拒绝配置。发布能力限正式 ToolSchemaPolicy 的字符串、整数、数值、布尔和一层基础类型数组及既有 enum/长度/数量/数值边界；仅移除 title/description/default 注释元数据，不丢弃语义约束。不支持对象、嵌套数组、nullable、除标准 date/date-time 外的 format、复合 Schema 或非 ASCII 标识字段时明确返回配置错误，不静默过滤后继续发布，不另造通用 Schema 引擎。query/limit/text/size 不能作为筛选原名。

结果复用现 PkulawCaseAdapter 的真实案例元数据及 citations 投影。判决正文和原始厂商回包不持久化，筛选命中也不自动成为已确认法源。此 SOURCE 未运行订阅服务、加载、配置发布、页面/API、费用或同号恢复验证，不勾选 6.2。


### 案例筛选截止日期格式SOURCE补齐（2026-10-06）

现正式 ToolSchemaPolicy 在既有 string 分支接受 `format:date` 与 `format:date-time`，不新增独立 Schema 验证器，不扩展其他 format。真实远端字段的标准格式与原名进入发布 input_schema；没有真实字段时不猜截止日期字段。date 只接受有效日历的 YYYY-MM-DD；date-time 接受 RFC3339（秒、可选小数秒、Z 或明确 ±HH:mm，T/Z 可小写），拒绝缺时区和非法日期/时刻；秒范围为00–59，不接受未有真实上下文的闰秒60。校验保持原字符串，不默认时区、不转成时间戳或改写输入；旧无 format 规则继续原合同。

Lawyer DTO 原字符串、发布摘要、所选值和原输入封存继续沿现链透传；日期控件按真实 Schema 显示，日期时间使用提示显式时区要求的文本控件，不用 datetime-local 丢弃 offset。恢复读取原值，不改键、版本或 Schema。这里只补源码，未运行加载、配置发布、厂商检索、页面/API或业务验收，6.2不勾。


### 许可全文私有 Tool 合同（6.2 SOURCE 候选，2026-10-06）

`legal_fulltext_fetch@1` 是可选通用受管 MCP 投影，不选择厂商、订阅、端点、凭据或法源。`config/sand_ai_legal_fulltext.php` 默认 `enabled=false`、`tools=[]`。管理员逐项显式启用并提供本地 code/name/description、实际订阅 tools/list 的 `remote_name`、完整 `input_schema`、完整 `output_schema` 或明确缺省 `null`、`result_mode` 和 `result_fields`。未知 remote_name/input_schema 时不注册；已填写但不受支持的 Schema/映射明确拒绝。沿既有管理界面的连接、MCP 发现、创建草稿、发布、环境绑定使用，不增加 Provider、自动发布或授权。本地 Schema 保留真实远端原字段、类型、必填及语义约束，使用 ToolSchemaPolicy 现有闭合、有界支持范围；仅在本地去除 description/title/$schema/default/examples 注释，不应用默认值，不丢弃不支持的校验关键字。完整远端 Schema 留在原 MCP 指纹链，参数按真实原名原值发送。

`result_mode=structured_content` 时 `result_fields` 为 `content`、`title` 及可选 `source_version`、`license_scope` 到相对 `structuredContent` 的 RFC6901 JSON Pointer 映射，仅支持成员/数字索引路径，不支持代码、模板、查询或通配符。`result_mode=mcp_text` 必须显式选择，`result_fields` 仅含 `title`，其 Pointer 指向实际输入中的标题；正文仅拼接实际 MCP text 块。两种模式的私有 canonical 结果固定为 `{content:string,title:string,source_version?:string,license_scope?:string}`；标题不默认或猜测，实际可选字段缺失则不返回，正文不截断。正文最多60000字节、标题最多300字符、实际可选字段最多512字符，整个 JSON 仍受现 ToolSchemaPolicy 的65536字节限制；超界明确失败。其余回包成员不会返回或持久化为原始回包，不新增远端URL、Bucket、object key、凭据或原厂商响应字段。

citations 只返回不透明 `legal_fulltext:<sha256>` source_ref、同值的 `locator:{type:'opaque',value:source_ref}` 和真实正文 SHA-256 `content_hash`；ref由实际原输入及正文hash生成。actual source_version/license_scope 只是输出中的来源声明，引用不生成已核验版本、许可证、官方性或法律效力事实；接入应用确认法源仍独立进行。

正式派发仍为 `POST /api/sand-ai/v1/tools/{code}/versions/{version}/runs`，使用既有 `{business_scope,input}`、原 `Idempotency-Key`、`sand_ai.tool.run`、原版本/连接指纹、资源IAM重验及既有 ToolRun/费用/未知提交恢复链。新adapter在执行快照与原请求指纹内固定 `result_visibility=private_fulltext`。普通POST回执及现 `GET /tool-runs/by-key`、`GET /tool-runs/{runId}` 对此能力的 `result` 恒为 `null`，只保留不透明citations、状态及现费用/错误元数据；既有document_fetch/搜索结果合同保持其原行为。

新增 `GET /api/sand-ai/v1/tool-runs/by-key/private-fulltext?request_key=<原key>`，仅接受该查询、无请求体，响应 `Cache-Control:no-store`。使用现SandIAM已验证工作负载上下文和 `sand_ai.tool.read`，核对原organization/application/environment/workload所有权、现资源权限及父任务权限，返回前重验；没有管理登录态或匿名读入口。仅读取已封存私有标记的原ToolRun，不派发、不恢复、不重计费用，其他adapter/别人的原号返回资源不存在。返回现ToolRun标准DTO，成功时其中 `result` 为上述 canonical 结果并校验原正文hash；其余状态result为null。接入应用仅在服务端读取并转入当前授权的私有材料链，浏览器仅见应用自己的摘要及登记状态，不得到content/raw/存储定位。GET读取可能写入既有IAM访问审计，绝不新增业务派发或材料/解析/法源写入。

本次仅交付候选源码及冻结协议，未运行语法、构建、Schema检查、MCP发现/发布、IAM/费用、页面/API、厂商获取、原号恢复或业务验收；未安装、提交、发布或部署，6.2不勾选。


私有全文能力前置识别：现 `GET /api/sand-ai/v1/tools/{code}/versions/{version}` 在既有已发布连接/版本/环境/Schema/快照核对后，仅对 `legal_fulltext_fetch@1` 增加 `result_contract:'legal_fulltext_fetch@1'`、`result_visibility:'private_fulltext'`。这是公开的能力元数据，不含正文或凭据。其他既有工具不增加字段。消费端在冻结 profile 与首个派发前要求两字段精确匹配，并核对实际Schema/快照，不能以其他工具恰好含content字段代替本合同。私有读取成功DTO保留现tool字符串code和version正整数；配置可选元数据的实际null/缺失均不生成该字段，不把未知值编造为来源事实。


## 2026-10-06：私有全文工具与实际文件生命周期关联（SOURCE）

`POST /api/sand-ai/v1/tool-runs/by-key/private-fulltext/file` 仅接受精确JSON `{request_key:原全文工具号,file_id:实际正整数文件ID}`。当前正式workload须同时满足原工具tool.run/tool.read及真实文件file.read资源授权，父级资源和返回前授权重验沿正式链。服务器解密原sealed成功回执，与原Run规范化正文/引用摘要核对，实际content SHA必须等于当前同归属、同环境文件的真实sha256；请求不能提交SHA、路径、对象key或browser自行证明。相同原run/file读取原关联并返回 `idempotent_replay:true`，其他文件冲突不能替换。返回精确 `{run_id,private_file_id,idempotent_replay}`。关联独立持久，原execution_snapshot/输入/原号不变，无外部派发或额外Provider计量。

专用原键全文GET仅追加 `private_file_id:null|实际正整数`，并在已关联时重新核对真实文件归属、当前file.read、SHA和删除状态；普通ToolRun DTO保持旧合同，不加该字段且本全文能力result仍null。已清理墓碑时私有GET/关联拒复用，不将缺失或拒读当未曾发送。

现 FileGateway.destroy 在锁定真实文件、完成其已deleted/status2/delete_time标记的同事务中，调用本新正式关联清理：仅本private全文、实际已完成的原Run/Attempt/usage/回执均可确认时，抹Run.result正文及sealed receipt加密载荷，保存内容墓碑；未知派发或未结凭证拒绝清理。原request key、Run.state/status、attempt、计量费用、原固定输入/快照、合法审计和非文本最小凭证保留，不通过删除义务或原记录允许重发。存储删除失败沿现事务回滚与delete_failed处理，不能虚称完整清理。既有非全文文件与工具行为不变。

140迁移及install/update/uninstall登记只交SOURCE：正式不可变关联与严格墓碑/内容清理例外，不能泛放开原不可变触发器。未执行SQL、升级、关联/清理HTTP、测试或运行验收，不增加6.2/6.3或12/32、0/4。

## 通用多节点 AI 工作流（5.3 SOURCE，2026-10-07）

管理员从“智能体与渠道 → AI 工作流”选择环境，创建/编辑带名称的节点，选择真实已发布 Skill、Agent、材料配置、知识库或只读工具版本，设置依赖与参数来源，保存草稿并明确发布。审批仍归消费者/SandWorkflow。管理与应用运行不共用身份；无后台账号冒充调用者。服务端完整校验 DAG、输入绑定与当前服务版本，发布冻结 graph 与每个服务 reference；运行/续行重验当前授权及引用，绝不将新活动版本替换旧绑定。

管理入口 `/app/sand-ai/admin/ai-workflows`：GET `/index?environment_id=`、`/read?environment_id=&code=`、`/catalog?environment_id=`、`/by-request-key?environment_id=&operation=draft|publish|disable&request_key=`；POST `/save-draft`、`/publish`、`/disable`。read 返回 `{definition:{id,environment_id,code,name,state,active_version_id},versions:[{id,version_no,state,revision,snapshot_sha256,graph}]}`；catalog 返回 `{items:[{kind,code,version,name,reference_sha256,input_fields:[{name,label,required,schema}]}]}`，只有本环境真实已发布可组合服务，无默认创建/发布。

save-draft body `{environment_id,code,name,expected_revision,graph}`：首次 expected_revision=0，修改须精确现草稿revision；graph=`{nodes,output_nodes,failure_policy}`，failure_policy=`stop|continue_independent`。nodes 为1..12项 `{id,name,service:{kind,code,version},depends_on:[node_id],inputs:{field_name:binding}}`；id 为 `[a-z][a-z0-9_]{0,31}`，kind=`agent|skill|material|knowledge|tool`，code、version来自catalog；material.version为SemVer string，agent/skill/knowledge/tool为integer。schema保实际输入字段类型与复合结构；Skill目录只含legacy工作负载可消费范围，不凭空声明个人owner。binding=`{source:'input',path:'/RFC6901/path'}`、`{source:'node',node_id,path:'/RFC6901/path'}` 或 `{source:'literal',value:JSON}`；node引用必须属于显式depends_on，禁止循环/自指/重复/缺节点/未知字段/内部归属或原请求字段。output_nodes为真实节点ID、无重复、非空。输入绑定路径必须合法；运行前校验冻结服务当前输入实际契约，失败不派发。publish body `{environment_id,code,expected_revision}`，disable `{environment_id,code}`；三个变更入口要求原Idempotency-Key，操作账本绑定管理员、环境、body及原响应。已发布版本不可改写；保存后续草稿产生新version_no。

保存草稿及发布可选 `version_id: 正整数`，绑定已读取的当前草稿 ID，并与 `expected_revision` 同时比较；管理页面编辑已有草稿或发布时必须发送此字段。首次创建新草稿（`expected_revision=0`）不传 `version_id`。当前草稿已被发布、替换或修订时，以既有 `SAND_AI_COMPOSITION_REVISION_CONFLICT` 拒绝，不写入另一版本；同原 `Idempotency-Key` 的字段变化以既有 `SAND_AI_IDEMPOTENCY_CONFLICT` 拒绝。省略此字段的旧客户端沿原请求体和原指纹重放，封存历史不改。页面在请求前冻结节点、依赖、服务版本、原修订及本页提交正文；会话记录仅保存管理员／环境／工作流／动作／原号／已知版本与预期修订等元信息，不保存图正文、固定值或应用 Bearer。记录写入并读回成功才发送；本页可证尚未发送时只允许明确提交同一原意图，刷新后不能凭记录或 GET 无结果推断未发送。未知结果只用原 `by-request-key` GET 找回，核回执 code、状态、已知 definition_id/version_id/version_no 和原预期 revision，再读取当前定义并确认原记录清除；存储或读取失败保留原号，不换号重发。身份／环境变化清除本页私有草稿与迟到响应；原运行、节点、来源与费用继续使用现有独立授权入口。


应用 POST `/api/sand-ai/v1/ai-workflows/{code}/versions/{version}/runs` body仅 `{input:object}`、原Idempotency-Key，当前SandIAM `sand_ai.workflow.run`；四级归属完全从可信context取得。GET `/api/sand-ai/v1/ai-workflow-runs/by-request-key?code=&version=&request_key=` 和 `/{runId}` 要求 `sand_ai.workflow.read`，同四级归属。POST `/{runId}/advance` 空body+Idempotency-Key，重验workflow.run及每个真实服务授权；只读恢复已尝试节点、显式派下一尚未尝试且依赖成功节点。POST `/{runId}/nodes/{nodeId}/retry` 空body+Idempotency-Key，仅允许已证明失败并具现业务重试能力的原子任务，由原业务正式retry/continuation决定资格；unknown不允许重发。POST `/{runId}/cancel` 空body+Idempotency-Key停止后续节点，保留已发生子运行与费用；不自动取消/删除子任务。

运行DTO `{id,code,version,state,snapshot_sha256,nodes:[{id,name,state,request_key,error_code,child,result}],output,usage:{basis:'child_runs',parent_charge:false}}`。parent state=`pending|running|waiting|partial_failed|succeeded|cancelled`；node state=`pending|submitting|waiting|succeeded|failed|unknown|blocked`；child是原服务运行安全定位，result仅当前IAM复核后公开。父图本身不新增模型调用/费用；每个子调用仍走原预算/dispatch/usage，不对父子重复sum。原node key稳定派生且持久；提交前先落submitting，进程中断/未知响应只用现业务by-request-key GET恢复，404不证明未外发，不新key。复用成果前须沿原子服务重新授权并核输出摘要与发布快照；节点和成果来源仍沿原子服务受授权来源入口。节点原输入及输出私有保存，前端只持久opaque run/key，不存Bearer/输入/成果。NeuronAI4 Workflow/Node/WorkflowState实际负责图推进；SandAI持久记录守卫所有外部副作用，不启SDK未知自动重发。

错误：`SAND_AI_COMPOSITION_GRAPH_INVALID` 无效图/输入绑定；`SAND_AI_COMPOSITION_REFERENCE_CHANGED` 发布引用失效；`SAND_AI_COMPOSITION_REVISION_CONFLICT` 草稿乐观版本失效；`SAND_AI_COMPOSITION_RUN_BUSY` 并发推进；`SAND_AI_COMPOSITION_NODE_UNKNOWN` 原派发未明；`SAND_AI_COMPOSITION_RETRY_UNSUPPORTED` 无现业务安全重试入口；其余沿当前IAM/原子服务错误。SOURCE迁移只保存文件，本轮无DB/HTTP/模型/构建/验收，12/32和0/4不变。

补充正式形状：index=`{items:[definition]}`；变更回执=`{definition_id,version_id,code,version_no,revision,state,snapshot_sha256}`。新增GET `/{runId}/operations/by-request-key?request_key=` 返回 `{operation,request_key,state:'started'|'finished',run_id}`；同workflow.read与四级归属。started只可沿原key显式reconcile已尝试节点，不启动新节点/重试；已finished同key只读原运行。404不证明未执行。DELETE `/{runId}/input` 空body+原key复用cancel，清父加密输入、停止后续节点并保子运行/费用；24h周期与父读取入口清理到期输入。父input使用现ProviderConfigCipher按run及四级scope加密，node只child定位/input/output摘要，不保存成果正文。知识原请求找回必须重建原参数，父输入清理后只能保审计/费用定位，明确input_state=purged，无伪造查询或重复外发。

正式管理权限 `sand_ai:agent:composition:read/manage`，菜单SandAIComposition挂现root、组件`/plugin/sand-ai/composition/index`，141精确归属安装/升级/卸载；现`sand_ai.workflow.run/read`正式服务声明沿用，不自动登记或授权。父未purged才从原子服务重验读取result；费用使用各原服务真实invocation request ID/子task/run计量，不虚构tool模型费用、不sum不同币种；来源仍沿原服务真实source接口。已发布图的上游绑定仅支持可证明的实际稳定output_schema路径/类型，动态payload内部路径无schema时拒绝发布，允许绑定已定义的整个对象/数组并于运行前按服务完整schema校验。

父run读取只通过现子服务GET/只读原键恢复物化已证实节点进度，未派发新的节点。原action started在其已尝试节点均有原服务明确状态后可查finished；同原action POST仅恢复已试节点，不再推进新节点。创建运行需workflow.run/read同四级scope，返回完整pending运行DTO。固定值保存到版本配置；本次私有输入保加密24h，依赖成果只内存绑定。


### 材料时间线成果展示（原3.4 SOURCE）

`MaterialResult` 沿用 `sand_ai.material_analysis@1.0.0` 的原 `events[{summary,occurred_on,source_block_ids,evidence_state}]`。页面将实际有效 `YYYY-MM-DD` 日期事件按日期升序显示，同日保持原成果顺序；没有有效日期的事件单独显示“日期待核实”，不推测日期、不隐藏原事件或把不确定事实变为确认。显示顺序不改写已封存 payload、原数组索引、`/events/{index}/summary` 和 `/events/{index}/occurred_on` 来源路径；查看来源仍发送原 `source_block_id`，复用当前授权读取。完整结构化数据继续显示原 payload。无新增 API 字段、模型调用、结果重写或费用。仅 SOURCE，未运行页面或业务验收。

### 通用 AI 工作流费用归属（原4.2 SOURCE候选）

费用汇总、事件列表与 CSV 新增独立 `by_composition_run` / `by_composition_node` 维度，分别投影 `sand_ai_composition_run.id` 和 `<run_id>:<node_code>`。旧 `by_workflow_run` 与 `workflow_run_id` 仍表示材料工作流，编号不混用。查询新增正整数 `composition_run_id` 及规范节点码 `composition_node_code`，节点码必须伴随原运行编号。事件响应新增可空 `composition_run_id`、`composition_node_code`。维度组沿现 rows/truncated，事件下钻沿同 UTC 区间和当前后台正式授权环境小计。

关联依据是持久编排节点的原请求键、发布快照/固定服务版本、实际原子账本及不可变 usage 事件同组织/应用/环境/工作负载四级归属。Agent 匹配原正式任务及真实模型/Embedding/只读工具子账本；Skill 沿原 SkillRun/Invocation；材料沿原 WorkflowRun/Dispatch；知识沿原 query intent 及真实 Embedding/rerank 请求；工具沿 ToolRun 与真实 external_request 事件，无虚构 Invocation。关联用标量子查询且只有唯一节点证明时才投影；聚合由唯一 usage 事件行出发，每事件计一次。父图不新增费用或用量。`partial_failed` 的已证明关联调用费用只作为业务失败关联费用，不等同于全部浪费，不与其他失败组相加。

运行与节点金额均复用既有十进制聚合及分币种规则。任何未测得/未定价/缺实际金额使完整计量金额为 null，已知部分仍独立显示。任一事件尚未得到完整 reconciled 厂商账单证明，完整账单金额与差额均为 null；人工 review 不填厂商核对字段。没有事件不是免费，不以 Token 估造外部请求价。原退款调整账本及净额读取保持其现有独立入口，不改写原费用事实。

`GET /api/sand-ai/v1/ai-workflow-runs/{runId}/cost` 只接受正整数原运行编号，无查询字段/正文；当前 SandIAM `sand_ai.workflow.read`、`sand_ai.usage.read` 必须同四级 scope，并用正式 `sand_ai.composition_cost` fact resolver 对原父运行资源分别 authorize/revalidate。该资源只投影持久父 owner 非正文费用元数据，data_class=null 不代表私有子输入的分类；不返回任何输入、结果正文、来源摘录、原始快照、内部 key 或凭据。读取前/返回前重验原资源，运行或节点并发改变则拒绝旧小计，失去当前授权则不返旧金额。

返回 `{run_id,basis:'immutable_child_usage_events',parent_charge:false,coverage:'complete'|'pending',overall:{rows,truncated},nodes:[{node_code,state,coverage,rows}]}`，rows 沿 SandAiUsageSummaryRow。范围为该原运行创建至当前 UTC 的全部已证明子事件。仅原父及全部节点成功、各节点已有证明事件且组未截断才给 complete；缺事件（含纯词法知识查询）或未决节点保 pending，显示已记录子事件小计，不充完整运行总额。计价/账单金额仍按自身 null 状态展示，coverage 不是计价或现金核对证明。父运行总额与节点分解是同一事件集合的两种展示，不再相加。

此聚合未提供父图预算预估；界面明确未提供，并保已有按节点原调用读取冻结预算上界的入口，不生成新报价、不 sum 重复 scope 占额。运行费用 GET 不恢复子调用、不推进图、不改财务账本、不重派模型/工具。当前仅 SOURCE，不表示接口运行或原4.2验收已完成。

### 通用AI编排父输入生命周期与真实Schema选择（原6.3/5.3 SOURCE）

运行DTO沿原字段增加必需 `input_state:'retained'|'purged'`、`retain_until:string`、`content_purged_time:string|null`；均直接投影原父记录，时间沿当前数据库记录，不推断未发生的子成果清理。`DELETE /api/sand-ai/v1/ai-workflow-runs/{runId}/input` 空正文/查询、原Idempotency-Key，复用现 `sand_ai.workflow.run` 与同四级owner，独立 `purge_input` 操作账本；不同于cancel，原键绑定该操作和原scope/run，未知回执只GET现operations/by-request-key和原run找回，不重DELETE/换键。

清理、取消、管理员停用及24小时TTL都抹父encrypted_input，保原succeeded/partial_failed语义与全部子节点原key、子定位/摘要、原调用费用及审计；未结束父运行停止为cancelled。父purged禁续行和重试，尚未尝试节点投影blocked，不返回父input或派生成果正文；实际已产生的Agent/Skill/材料/知识/工具成果仍归各原业务，只有当前原业务授权可读及按其已有清理链删除，本操作不冒称删除共享来源或子成果。UI提供清理私有输入及状态/留存时间，停用/清理确认立即失效当前正文/来源/旧费用读响应，保opaque原号用于找回。原运行费用入口保持，只按现事件读取。

管理员catalog item 增 `output_schema:Record<string,unknown>`，直接复用本环境现已发布服务freeze所含实际输出Schema，不公开target/instruction/连接/厂商正文；中文配置器据该Schema选择上游真实字段，不创造动态object的内部字段。input_fields仍保现真实schema/type/required，输入与固定值依真实Schema控件配置，可选字段未启用时不发送，不用手写JSON作为唯一object/array操作。服务端既有完整DAG/输入输出绑定验证保持。此批仅SOURCE，不新增迁移/权限/计费，不表示6.3/5.3或12/32、0/4运行通过。

清理UI使用独立opaque purge journal，保留未明advance原key及metadata，两者不覆盖；清理原key只GET操作回执/原run恢复，不以清理成功称原未明子调用未发送或账目消失。活动lease的RUN_BUSY可能对应原已started操作，须按真实操作回执区分已拒绝与未明，不仅凭错误名换键。引用失效或输入已清理的正式错误会立即失效旧正文/来源与旧费用响应，保现凭证及opaque原号供重新授权读取。

现GET `/{runId}/operations/by-request-key` 回执增 `admission:'admitted'|'rejected'`、`error_code:string|null`。仅首次原key在事务内证明活动lease导致尚未受理时，按原scope/run/operation/fingerprint持久finished拒绝回执（admission=rejected，error_code=SAND_AI_COMPOSITION_RUN_BUSY）后返回兼容旧BUSY码；同拒绝原key不可转为新派发。已有started操作或尾部fence的BUSY不写拒绝，仍admitted/未明。UI只在当前run/key精确匹配的正式GET finished+rejected回执后解除独立purge待确认锁，保拒绝原号metadata并允许明确稍后申请；404、一般BUSY、网络异常都不证明未受理。拒绝不会清输入、推进节点或释放任何原子费用义务。

### Skill资料提炼与知识会话段落流的官方Neuron复用（原5.2/5.4/5.6 SOURCE）

`SkillSourceSuggestionGateway` 的提炼输出采用现正式Skill同源的官方 `JsonExtractor.setExtractors([$text]).getJson` 完整原文门，再沿原depth32解码与数值/来源ID业务校验；不容许从杂文截取片段当完整提炼结果，1.0的float类型及真实来源关联保持。草稿、人工采用、固定评测/发布/运行/撤销及原号/费用公开合同不变。

知识会话段落流经冻结的NeuronAI4.0.2（source ref `cd0d3e48451a234de31d6fc64cc632e3f1fa3b91`）官方OpenAI stream、SSEParser与StreamState解析和组装，再由SandAI `SourceBoundAnswerSegments` 校验实际文本中的业务JSONL/来源绑定与段落存储；保原16段/4000字/24000待分段缓冲限制及最终完整内容一致约束。SandAI仍持有固定HTTP/DNS、once-send、真实计量与SDK量一致校验及MeasuredChatFailure业务适配，未知不自动重发。段落流拒绝工具调用且只接受正式stop；原Agent流默认allowToolCalls=true行为保持。旧自建SSE类留作原源码，本入口已消费官方流能力。公开DTO、路径、请求指纹、同原run GET恢复、摘要/来源/费用归属均不变；仅SOURCE，未执行流或业务验收。

### 外部资料工具原请求读取与明确拒绝恢复（原4.4 SOURCE）

同现已认证管理组增加六个GET：`/app/sand-ai/admin/tools/operations/connection`、`/operations/draft`、`/operations/publish`、`/operations/bind`、`/operations/revoke`、`/operations/discovery`，分别读取create_connection、create_draft、publish、bind_environment、revoke、mcp_discovery的原操作。各自复用原动作Permission `sand_ai:tool:connection:create`、`sand_ai:tool:create`、`sand_ai:tool:publish`、`sand_ai:tool:bind`、`sand_ai:tool:revoke`、`sand_ai:tool:mcp:discover`，无新授权体系。查询严格且仅含environment_id正整数、request_key原键字符串（最长128）、target_sha256小写64位hex；正文为空，Cache-Control为no-store。

成功envelope为`{operation,environment_id,request_key,target_sha256,result}`，operation为上述六种原动作；result为原动作成功DTO，或`{state:'rejected',submission_state:'not_submitted',error_code:string}`。读取精确匹配当前admin与原env/action/targetSHA、不可变请求指纹和结果hash；actor/fingerprint仅内部保存，不输出。旧记录没有admin证明、404或当前资源状态均不能解除unknown。原成功回执可在资源后来撤销时读取，并保持原实体关系，不据此重新发布。

目标hash使用PHP ToolFingerprint::canonicalJson与前端排序keys的UTF-8 JSON：connection为`{code}`；standard draft为`{adapter:'standard_readonly_http',code}`，MCP draft为`{adapter:'mcp_streamable_http',mcp_snapshot_item_id}`；publish/revoke为`{tool_id}`；bind为`{tool_id,tool_version_id,connection_id,connection_revision_id}`；discovery为`{connection_id,connection_revision_id}`。createDraft、publish、revoke新UI POST增加environment_id；旧调用可省略，旧回执不据此推断环境；其他POST字段不变。

持久intent只含admin/env/action/key/target/hash/hadUncertainOutcome，不含secret、DTO、schema、Bearer或result。未知刷新后独立原号GET，不用当前code或状态替代原回执，不允许放弃原键。POST错误不自动解锁；仅GET匹配真实rejected后，管理员显式确认修改配置才释放新键。连接、草稿、发布、绑定、撤销与MCP发现均保留各自原意图和读取入口，撤销使用原正式业务动作。

原ToolOperation.result._recovery记录内部恢复metadata，无新迁移。五个本地动作仅在真实事务确定本地ApiProblem且完成回滚后，重取同scope/key互斥锁、证明无既存operation时保存拒绝。连接先建立可信code/env/key并对全输入HMAC，再在同原号受理点验证；草稿先建立可信target/env/key，完整DTO指纹和输入验证置于同scope锁，现tool-code业务锁保持。MCP仅初次claim无snapshot、尚未外发且原连接或版本不存在时保存真实拒绝；已有snapshot或外发失败保持未知。新env调用的draft scope为`environment:{env}:draft:{targetsha}`；无env旧API保原scope/fingerprint。旧无metadata连接成功仍可按legacy fingerprint重放，旧GET拒读。现IAM、readonly工具、秘密屏蔽、私有来源、原key、计量费用及官方Neuron stream均保留；仅SOURCE，不表示业务运行或验收通过。

### 2026-10-07 Billing original-request recovery

GET ADMIN_API_PREFIX/billing/import-by-request-key requires current sand_ai:billing:index; GET billing/sync/by-request-key requires sand_ai:billing:sync; GET billing/reviews/by-request-key requires sand_ai:billing:review. Import/sync exact query: {provider_id,request_key,input_sha256}. Review exact query: {billing_line_id,request_key,input_sha256}. Targets positive integers, request key 1..128 nonempty text without controls, SHA lowercase hex64. SHA of original public POST JSON (including idempotency_key), recursively sorted object keys, original array order, Unicode and slashes unescaped. Sync excludes internal start_unix/end_unix.

Response data: {operation:'import'|'sync'|'review',actor_id:number,request_key:string,input_sha256:string,state:'unknown'|'completed'|'rejected',billing_import:BillingDetail|null,review:BillingReview|null,sync_status:null|'imported'|'no_data',rejection:null|{error_code:string}}. Actual persisted actor/operation/target/key/input digest must match; GET does not fetch providers, import, reconcile, review, or alter financial state. Missing facts remain unknown, never proof of not submitted. Different input at the original key: HTTP409 SAND_AI_BILLING_RECOVERY_CONFLICT. Legacy facts without the original digest remain unknown. Operator review never becomes cash reconciled.

Frontend journal: current admin-scoped sessionStorage, only operation/key/input SHA/provider or line ID/known environment ID. No amounts, documents, keys, review note/locator, or results. Persist before send; storage failure blocks sending. Reload/open performs GET only. Pending recovery controls remain visible independently of final review state. Never auto-POST or replace key. Lost input may only resume after explicit re-entry of exactly matching input SHA and user confirmation. Admin switch clears memory and blocks old-owner responses/sends.


Formal rejection is read exclusively from sand_ai_billing_operation_receipt after exact actor/operation/target/key/input-SHA confirmation. unknown/completed require rejection:null; rejected requires all three financial result fields null and the recorded domain error only. Local import/review record rejection only after their original PostgreSQL transaction fully rolled back on the same live PDO, then reacquire the original success lock and confirm no original success or another claim, including another actor, without disclosure. Commit/rollback uncertainty, input/key conflicts with prior facts and started provider fetch never create rejection. Original-key POST consumes a rejection and cannot execute it again; HTTP409 SAND_AI_BILLING_OPERATION_REJECTED or SAND_AI_BILLING_OPERATION_UNKNOWN alone never permits clearing a journal.

Official sync uses a nonfinancial prepared claim committed under the exact original BillingImportAdvisoryLock domain, then a second short transaction marks the same owned claim started before connector fetch. No transaction spans HTTP. Prepared/started cannot be relabeled completed; GET completion still requires the real original import/no_data proof and its actual financial fingerprint. Duplicate/in-flight keys do not fetch again. Only a claimed request still prepared and a proven local pre-fetch domain refusal may transition to rejected; claim token and original facts are immutable. The schema/migration is SOURCE only and is not executed here.

Users explicitly acknowledge a strict original GET rejected proof; the UI re-reads that same proof before removing only its metadata and allowing corrected input. The next explicit submit generates a new key. Unknown journals remain frozen and GET-only after reload; amounts, private evidence, review text and credentials remain excluded from journal.

### 渠道绑定保存/停用原请求只读恢复（原5.5 SOURCE）

新增GET `/app/sand-ai/admin/channels/bindings/operations/by-request-key`，沿原 `sand_ai:channel:binding:manage`，当前管理员、原环境范围读前/返回前重验，Cache-Control:no-store。空正文；严格query为environment_id正整数、operation=save|disable、request_key原键（1..128且无控制字符）、input_sha256小写64hex，以及save的code或disable的channel_binding_id正整数；五字段无额外项。save input SHA沿ChannelBindingPolicy规范化后完整配置（不含environment_id），RequestPayloadFingerprint递归排序键、UTF-8/不转义斜线JSON；disable沿原SHA256十进制binding ID字符串，不改旧金融或渠道指纹。

返回`{operation,environment_id,request_key,input_sha256,target:{code}|{channel_binding_id},state:'unknown'|'completed',binding:原ChannelBindingSnapshot|null}`。仅现sand_ai_channel_operation的原created_by、env/action/key、request_fingerprint、channel_binding_id与原response_snapshot/目标关系全部证明一致才completed；原绑定当前还须属于同env/目标且未删除。保存回执复核快照的规范化配置SHA；停用复核原ID和status=2。原快照保原revision/status，后来的停用/重新启用不重写原成功。无记录或旧缺actor证明保unknown，不以列表状态/404或错误名推断未受理，不虚构rejected。读取不保存、不停用、不外发。

save/disable原POST重放新增created_by精确原actor守卫，跨管理员不返回旧快照。UI只持久当前admin/env/action/key/固定目标/inputSHA等opaque元数据，不持久配置正文/身份凭据/厂商秘密/回执结果；发送前保存失败则阻止发送，刷新后独立列表只读原GET，unknown禁重POST/换键，只有严格同原回执completed才清原journal并允许下一明确操作。启用沿既有save status=1，停用沿既有disable，不新造revoke动作；双渠道平台配置、会话/费用/回信恢复入口保持。本批仅SOURCE，不表示平台运行或5.5验收。

### 音频人工复核原请求只读恢复（原4.5 SOURCE）

新增GET `/api/sand-ai/v1/audio-transcriptions/{taskId}/reviews/by-request-key`，空正文、查询仅request_key原caller键（可见ASCII，最长512）。沿现AudioSegmentReviewController.authorize的音频/复核双能力、当前可信scope及私有源授权，读取前/返回前重验且身份须保持一致；Cache-Control:no-store。按原audio_review caller键规范化查询既存AudioSegmentReview，核owner_scope、原task/base_sha256、expected_revision+1、segment_index，并沿MeetingSummarySource读取原exact revision的连续ledger/seal及原文字/speaker一致证明；不新建复核、不派模型、不改变来源或费用。

回执只含`{audio_task_id:int,expected_revision:int,segment_index:int,revision:int,request_key:string}`五项元数据，不含复核正文、speaker或存储位置。原404/拒权/一般错误均不证明旧保存未发送，未知保持原键，只GET找回，不重POST/换号。只有当前授权的原task/key/index/expected+1回执精确匹配，才确认原保存成功。

复核与摘要opaque journal按当前owner/task保原号及reviewRevision，发送前持久读回失败阻POST；原正文不持久化。已确认revision先保留再用于摘要，未明复核、未保存草稿或未读取版本禁新摘要；旧全局摘要原号须GET当前授权证明后才迁移，不忽略旧号自动创建新请求。原POST明确本地RESOURCE_CONFLICT/VALIDATION_ERROR只沿原Gateway未写或完整回滚事实保草稿重申请，GET/404/未知不解除锁；不新增rejected GET或假拒绝ledger。摘要公开API不变，仍使用原review revision及source seal，既有完整摘要/来源/费用链保持。仅SOURCE，未运行或验收。

## 后台 Skill 固定版本发布与原测试只读恢复（原5.2 SOURCE）

`POST ADMIN_API_PREFIX/skills/publish` 保留原 `{environment_id,skill_id}` 与 `Idempotency-Key`，新增可选正整数 `version`；当前管理员页面必须传用户所见的原草稿版本号。服务端只发布该固定目标，当前草稿已变化时报 `SAND_AI_RESOURCE_CONFLICT`，原请求号绑定不同版本时报 `SAND_AI_IDEMPOTENCY_CONFLICT`；同原号已发布时只返回原操作结果，不发布后继草稿。页端发布门核至少一个有效案例、案例属于同一固定版本且最新判定全为通过；人工判定只接同版本、同案例的成功测试结果，服务端继续核封存输入、案例内容、运行时序与真实成功事实。

`GET ADMIN_API_PREFIX/skills/tests/by-request-key`（当前 `/app/sand-ai/admin/skills/tests/by-request-key`）仅接受 query `{environment_id,code,version,request_id}`；`request_id` 是原测试 POST 的原始 `Idempotency-Key`，GET 不传该 header，也不携私有 input。后台按当前管理员权限、环境、原 code/version/key 查原 legacy test，并重验封存输入、来源快照及历史意图指纹；不伪造消费身份、不 claim/send/finalize。返回现 `SkillTestResult`（`run_id/invocation_id/invocation_request_id/state/output/citations/review_required/error_code/idempotent_replay`），状态沿 `running/succeeded/failed`，不加字段；已 published/abandoned 原测试可按当前权限只读，Skill 撤销/删除或知识来源不可读仍拒绝。不存在、拒绝、网络错误或未知不证明未派发，页面保留原 scope/code/version/key，不换号重 POST。`SAND_AI_USAGE_PERSISTENCE_FAILED`、`SAND_AI_SKILL_RUN_IN_PROGRESS`、`SAND_AI_RECEIPT_INVALID`、`SAND_AI_DISPATCH_AMBIGUOUS` 保持未确认，即使旧记录为 failed 也不解原锁；failed 不证明未派发或未收费。页面明确显示原失败后，只有人确认“另起一次新的付费评测/调用”，并再次 GET 核原失败终态后才准备新意图，原号元数据与原费用保留，不自动再次发送。费用继续按原 invocation 与当前费用授权独立读取，不因成果不可读而抹除记录。

消费范围 Skill 个人/团队/组织生命周期继续原 `ConsumerSkillPanel` 与正式 scope/actor API。`consumer_declared` 是消费应用声明，真实人员和团队成员须由已授权消费应用核实并传入；手填引用不代表 SandIAM 真人或成员认证，不新增平行 IAM。消费运行可指定任一仍 published 的固定版本，不要求当前 active_version_id；整 Skill revoked 禁新调用。草稿替换、案例、同版本成功评测和最新 pass 发布门、原请求恢复、下一版复制、废弃案例及独立历史费用入口保留。页面身份、权限凭证或范围变化清除私有输入/Schema/成果；会话仅留管理员/环境或已核消费范围的原操作号、固定目标和意图摘要，未确认原请求不会因修改输入而取得新号。上述是功能 SOURCE，本轮未执行评测、模型调用、上传或运行验收。

## Skill 草稿与案例保存回执恢复（增量）

新增只读接口：

- `GET /api/sand-ai/v1/skills/drafts/by-request-key` → `SkillManagementController::readDraftReceipt`。
- `GET /api/sand-ai/v1/skills/{code}/versions/{version}/cases/by-request-key` → `SkillManagementController::readCreatedCase`；code/version 为原固定目标。

两接口仅接受 query `request_id`（原 POST 的原始 Idempotency-Key）、`scope_type`、`scope_ref`、`actor_ref`。须当前可信工作负载授权 `sand_ai.skill.manage` 和 `sand_ai.skill.read`。服务端通过原 consumer snapshot（含可信 organization/application/environment/workload_client 与原 actor/scope）生成同一内部 key；SELECT 原草稿 operation / 原固定版本 case 的不可变唯一记录，核原 consumer，并重验当前 Skill 固定版本、来源快照及 Skill/manage/read、knowledge/query 授权后返回。

draft data 为原持久六字段操作结果（`skill_id,version_id,code,version,state:draft,idempotent_replay:true`），附 `request_id`（原始请求号）、`request_fingerprint`（原 operation 规范指纹，opaque）、现 `scope/scope_source`。case data 为 `case_id,case_no,skill_id,version_id,code,version,idempotent_replay:true`，附原 `request_id`、从真实不可变 case name/input/criteria 和固定 code/version 得出的服务端 `request_fingerprint`（opaque）、现 `scope/scope_source`。不返回草稿或案例私有输入，不改变现 operation JSON / case schema / 幂等指纹 / 费用。

未找到仅返回现 `SAND_AI_RESOURCE_NOT_FOUND`；缺真实原指纹按现 `SAND_AI_INVOCATION_FACTS_UNVERIFIED`；其余现身份、范围、来源拒读原错误保留。任何 404、状态变化、无权、网络失败或不匹配回执均不代表原 POST 未发生，不解除 UI 原号、不自动 POST 或换号。前端 intent_hash 仅约束用户重填完全相同原 body 的显式同号 POST，不与服务端 opaque fingerprint 比较。

刷新后同页独立显示原 draft 保存号；case 新 pending 只新增无正文 `{operation:case,code,version}` 目标元数据。既有 draft pending 可直接依原号找回；旧 case pending 缺固定目标不能猜当前版本，保原号并提示重新填写完全相同原内容走现同号 POST。切管理员/消费身份/环境/声明范围失效旧页面正文与显示，已存在持久 pending 按原分区保存。

## 管理端知识索引原准备结果恢复（增量）

新增 `GET /app/sand-ai/admin/knowledge-bases/embedding-index/by-request-key`，仅 query `environment_id,knowledge_base_version_id,request_id`（原 start POST 原始 Idempotency-Key），无请求正文。实际入口 `KnowledgeBaseController::embeddingIndexByRequestKey`，沿现登录中间件及 `sand_ai:knowledge:index:build` 权限；不声称 index 存在原 actor 字段或服务端跨管理员原凭据证明。与现 index/status 查看授权一致，当前管理员重验前后身份，响应 `Cache-Control:no-store`。

`KnowledgeEmbeddingIndexGateway::readByRequestKey` SELECT 现表唯一 request_id 并限定原 env/version，调用现 status 的完整当前私有来源、活动版本和 source scope 重验，返回现 id/state/source_count/indexed_count/dimensions，再附 `owner_environment_id,knowledge_base_version_id,request_id,idempotent_replay:true`。不创建 index、不 advance、不恢复/新发模型调用、不改当前 route/provider/模型价格、既有 per-block 请求或索引算法。不新增 schema/迁移。

缺原记录现 `SAND_AI_RESOURCE_NOT_FOUND`；来源、活动版本变化沿现 SOURCE_DRIFTED/INVALID 等拒读错误。任何 404/拒读/网络错误/回执不匹配均保原目标及原号，不证明原准备未发生，不解除未知，不自动重新 POST 或换号。

UI 新 `sand-ai-embedding-index:v2:<当前admin>:<env>:<原version>` session record 保原 key/已知 index/unknown 及计数，按 admin/env 独立枚举原目标列表；刷新或 active_version 切换不删除列表。未知 start 只能用原号 GET 找回；每块 advance 保持既有同 index/block 恢复协议。旧 env/version session 无原 admin 元数据，不自动绑定或显示旧 key；选择该版本时阻止新准备，显式按当前查看权限只读读取原 key/env/version 后才绑定当前会话，失败仍保原记录。切 admin/env 清当前状态、列表与费用展示，旧 namespace 记录保留。

准备/状态及新增恢复 API 响应均须核正整数 ID/计数/维数和 ready 完整形状；新增恢复再核原 env/version/key/已知 index ID，失败不得将索引标就绪或解除 unknown。原 KnowledgeBasePanel createDraft 完整原 body/key 同管理员/env 持久和服务 create_draft operation 指纹幂等已存在，本稿未修改；已交 disable/purge 与专业检索保持。


### 知识会话发送/取消/关闭刷新后原目标恢复（原5.4 SOURCE）

现知识会话接口/字段不变。发送沿原conversation/key的`turns/by-request-key`和原会话GET，原创建沿现`conversations/by-request-key`；当前正式可信caller/scope与私有源授权由服务端原GET重验。UI按当前管理员/env/code/原conversation/原key等opaque元数据持久并独立显示原目标，不将Bearer或Bearer摘要当续凭据后的人员身份；旧env-only记录仅显式用当前凭据原GET读取证明后绑定当前session。切管理员/消费凭据/环境即清私密输入、输出、来源与旧响应，保安全原号恢复入口，不自动重POST/换原号。存储保存与读回失败在发送前阻止请求；问题、秘密、摘要正文、回答或来源不入journal。

现取消和关闭都没有Idempotency-Key或操作账本，不新增虚构键、GET或拒绝ledger。UI发送前持久原conversation及取消的原sequence/动作，仅沿现GET读当前授权目标；原turn的cancelled终态或原会话closed只证明当前目标已达该终态，不声称原动作已执行或未执行。原preparing/active、未找到、拒权、网络失败或形状/目标不匹配保未知动作锁，无自动POST；切换或刷新不删除原目标。现原cancelPending/close服务行为、取消前未调用约束与已发生费用保持。

原号定位回执、原会话与原turn响应按实际DTO/固定目标严格守卫；SSE继续消费同原sequence与现cursor/已存段落，不新建run或重发模型。既有官方Neuron stream、敏感屏蔽、来源/摘要/费用语义不变。本批仅SOURCE，不表示真实会话或断线业务验收。


### 文件/任务清理分类及软删除元数据读取（原6.3 SOURCE）

现 GET `/api/sand-ai/v1/files/{id}`、文件 DELETE 和 task DTO 增加 `cleanup` 投影，无新路由、权限动作或数据库结构。`cleanup` 字段如下：

```text
{
  state: retained | pending | failed | purged | unconfirmed,
  completed_at: string | null,
  source_available: boolean,
  resources: {
    file: retained | pending | failed | purged | unconfirmed,
    derived_text: retained | pending | failed | purged | unconfirmed,
    vectors: retained | pending | failed | purged | unconfirmed | not_applicable,
    results: retained | pending | failed | purged | unconfirmed,
    usage_history: retained | pending | failed | purged | unconfirmed,
    audit_history: retained | pending | failed | purged | unconfirmed
  },
  source_block_count: number | null,
  parse_record_count: number | null
}
```

文件 DELETE 返回 `{id, state: 'deleted', cleanup}`。文件 purged 只依同 file/environment/actor 已提交的 `file.delete` 记录及真实非负整数清理计数；旧记录缺少所需证据时以 `unconfirmed`/null 表达，不以空正文或缺记录补零/宣称清理完成。任务成果沿原 `content_purged_time`；structured workflow 沿原 run 清理标记，parse 来源沿真实文件生命周期。向量在实际 embedding row 的 index/source 关联证据可确认时标 retained。仅已删除文件的原 file.delete 回执确认文件已清理，且该文件没有知识库来源关联或 embedding 行、原环境没有任何 embedding index 或持久 embedding dispatch 时，vectors 返回 not_applicable，表示当前无向量资源需清理，不表示执行过向量删除或推断历史从未索引。任一条件不满足仍 unconfirmed；prepared/dispatching/ambiguous 等任意历史派发均不能走此分支。文件删除不代表知识库向量已删除，分类不跨资产串推。usage_history/audit_history 的 retained 表示既有清理保留历史，不表示费用为零或免费。

文件元数据 GET 仅内部 `sand_ai.file_metadata` FactResolver，通过原 `sand_ai`/`file.read` 当前 owner/organization/application/environment/data_class 事实授权并在读取前后重验；可读取真实软删除行的安全元数据。删除后的元数据不返回 file_ref/latest_parse、对象 key、正文或下载位置。原 `sand_ai.file` resolve/fromPersisted 活文件门保持，来源正文、音频和下载权限不放开。无清理执行、业务运行或验收声明。


### 原4.2资源费用关联增量（2026-10-07，SOURCE）

既有管理员费用 summary、index 和费用 CSV 导出使用同一当前 IAM 授权环境／组织／应用 scope；不新增端点。以下查询字段同时用于 summary、事件列表及 CSV 的原筛选：

| 查询／事件DTO字段 | 类型 | 汇总组 | 真实来源 |
|---|---|---|---|
| skill_run_id | 正整数；事件 null 表示未关联 | by_skill_run | usage_event.skill_run_id 与原 invocation、Skill run／版本 FK 同一链 |
| agent_definition_id | 同上 | by_agent_definition | 原 agent task 冻结 definition_id 与 published agent version／snapshot |
| agent_version_id | 同上 | by_agent_version | 同一冻结 version_id／snapshot_sha256 |
| channel_binding_id | 同上 | by_channel_binding | 原 task 冻结 channel_binding_id；原 channel_operation 保存回执证实绑定 revision／owner |
| delivery_platform | feishu 或 wecom；事件可 null | by_delivery_platform | 原 task 冻结的显式 delivery_platform，缺值不推断 |
| knowledge_base_version_id | 正整数；事件可 null | by_knowledge_base_version | 原 invocation publication／version 与实际 indexing child／version 唯一一致关联 |
| knowledge_query_id | 同上 | by_knowledge_query | knowledge_query_intent.id 四级 owner，原 publication／version／scope SHA 与实际 embedding／rerank child |
| knowledge_index_id | 同上 | by_knowledge_index | 原 embedding index 与 source FK、真实 immutable child request／logical execution |

新增汇总组沿原 SandAiUsageSummaryGroup DTO，dimension 分别 skill_run、agent_definition、agent_version、channel_binding、delivery_platform、knowledge_base_version、knowledge_query、knowledge_index；按原 currency 分开。正整数筛选可单独使用；delivery_platform 限上述两个枚举。事件 DTO 上述新增字段均存在且可 null；事件增加 tool_run_id: number|null 公开真实 tool 调用定位。单次运行选中汇总行后进入同筛选下的原 usage 列表；invocation_id 或 tool_run_id 提供原调用定位，tool 不伪造 invocation。

by_agent_run／agent_run_id 使用同一标量真实 task 关联覆盖原模型轮次（含继续后的原子调用）、retrieval step 的 embedding child、task-owned managed tool 的真实 metering。父机器人不新增费用。整体与各分组是同批 immutable usage 的不同投影，不把父总额再次加到子节点；与已交 Composition 关联共存。

知识库 base／version 归属来自唯一候选 FK 链；不相关或冲突时 null，不借资源标题、当前 active_version、模型金额或用户 tag 推断。无 usage 的 ambiguous dispatch 在资源筛选下保 association_unavailable，未知金额／未对账值保 null；不新增财务或模型写入。


## 2026-10-07：法律来源与文件来源块的统一公开定位（SOURCE）

`GET /api/sand-ai/v1/files/{fileId}/source-blocks` 与 `POST /api/sand-ai/v1/legal-sources/snapshots` 的来源块均使用同一 `FileSourceBlockLocator::project`，不再向法律快照直传存储raw locator。公开 `locator_type` 为 `line|page|paragraph|table_row|page_region|audio_time_range`：位置分别为 `{line,char_start?,char_end?}`、`{page}`、`{paragraph}`、`{row,table?,sheet?}`、`{page,x,y,width,height}`，音频沿既有 `{type:"audio_time_range",start_ms,end_ms,granularity?}`。既有可选 `part>=2` 保留；位置整数沿现契约，音频粒度仍为 engine_segment/input_range_only。

XLSX的真实工作表名是跨sheet定位所必需的位置字段，`table_row.sheet` 保留解析器实际保存的非空UTF-8原名，不改大小写/空白、不推工作表索引或页码；无sheet的其他表格仍保持原行/表定位。路径、标签和未知存储字段继续不返回。律序原样消费上述类型/位置用于选项、绑定、事实引用及来源读取，不造旧pdf_page_bbox/docx_paragraph等别名，也不从正文猜坐标。

文件SHA、parse/version、原文摘要、locator摘要与不可变来源映射继续完整比较。已封存位置如与当前真实投影不一致仍明确拒绝，不能通过去sheet、重写旧映射、绕hash或替换来源恢复。此次仅SOURCE契约增量，未执行查询、检查、测试、数据库、构建或业务验收。

### 长 TXT 分块、字符位置与保留历史恢复（原5.1 SOURCE）

新 `text/plain@2.0` 解析沿现 Neuron `TextFileReader` 读取文本，按真实原行经官方 `DelimiterTextSplitter` 分块，每块最多 4096 个 Unicode 码点（UTF-8 至多 16384 字节），不重叠。`locator_type:"line"` 的公开位置为 `{line,char_start?,char_end?,part?}`；`char_start/char_end` 须成对正整数、起点不大于终点，是该原行内从 1 开始、含两端的 Unicode 码点位置，包含实际前导空白对起点的影响。空白窗口不形成来源块，但推进实际字符偏移。旧 `{line,part?}` 保持原义，不按分片号、正文长度或新上限补算字符范围。

`FileParseGateway::sourceRows` 对新 TXT 2.0 来源保留原块正文与实际位置，不再次 trim 或截断。其他解析器的新输出超过通用字节上限时也复用同一私有 `NeuronSourceTextSplitter` 的官方分块，不手写按字节裁断字符；保留已有位置及 `part` 语义，不添加非 `line` 字符范围字段。其通用旧配置名 `max_source_block_characters` 保留，实际单位为 UTF-8 字节，默认 12000、有效范围 256–65536。已有默认分块不表示所有长 TXT 会超过索引限制，新字符分块也不证明模型 Token 上限或费用已通过。`FileSourceBlockLocator::project` 在文件来源块读取与法律快照中保留真实成对字符范围；知识库由 `KnowledgeBaseGateway::freezeSources` 冻结原解析实际位置，知识来源正文由 `KnowledgeSourceGateway::read` 返回该版本位置，不声称所有来源路径统一调用同一个投影。

旧块仅在已确认超过所选模型或索引输入上限时按既有入口恢复：保留原文件、已发布知识版本、原索引与请求号；重新上传原 TXT 创建新 `file_id`（正式上传不按 SHA 去重），在正式 `/sand-ai/knowledge` 管理入口选择资料所属环境，复用 `MaterialSampleFiles` 与 `RuntimeApiKeyField`，使用当前应用 SandIAM 短期运行身份上传并明确提交解析（后台登录不代替应用 caller）；结果未知时用原请求号只读找回，失败沿原任务规则处理。解析成功后点击“选用来源”，在原资料环境刷新 `eligible-files` 并按新文件号选择当前成功解析；未在当前可用列表找到时只提示刷新/加载更多核对，不凭文件号构造来源。身份或环境变化清除私有材料与选用状态；原草稿请求冻结时禁止更改来源，使用新的知识库代码建立草稿、发布，再在现索引入口选择新版本准备/处理/激活；应用开放关系按现发布入口明确配置，不自动迁移旧调用。停用不释放已发布来源引用；不以清理向量、解除引用、删除旧文件或覆盖旧解析作为保留历史恢复手段，未知调用不能通过新文件或新请求重发。原 `SAND_AI_PARSE_FAILED/EMPTY` 分类与各原请求恢复规则保持。知识查询引用表和原来源正文弹窗只显示实际有效的成对字符范围，旧行/分片明确显示字符范围未提供；本增量仅 SOURCE，未执行上传、解析、发布、索引、查询或业务验收。


### Skill 来源引用与固定知识版本读取（SOURCE 候选）

Skill 正式运行、草稿评测及来源提炼的公开 `citations[].knowledge_base_code` 表示该当前消费环境的真实已发布 `sand_ai_knowledge_publication.code`；它可能不同于 owner 知识库 code。输出前沿实际 SkillKnowledgeRef 或 suggestion 的持久 publication/version/snapshot 关系核当前发布和 owner/consumer 环境来源归属，仅将公开 DTO 中的 code 投影到正式消费 alias。既存证据、citations、prompt、请求及来源摘要不改写；版本不符或既存引用 code 不属于该真实 owner/publication 时拒绝返回。

沿现 `GET /api/sand-ai/v1/knowledge-bases/{code}/versions/{version}/sources/{sourceBlockId}` 读取。code 为上述真实消费 publication code；version 为引用固定知识版本号。该只读入口按当前 publication + 同知识库的精确已发布 version 读取，使用现 `publication:client:versionId` 的正式 IAM 资源事实和当前 `sand_ai.knowledge.query` 决定及复验。合法固定旧版本不会因知识库 active version 前移而被拒；当前发布撤销、知识库停用/删除、版本失效、当前 owner/consumer 环境变化或来源不可用仍拒绝。保留现完整成员快照、文件/parse/block、最新成功 parse、来源数量、定位和内容 SHA 的全部门槛，不提供任意旧版本或已成功运行后的永久权限。URL、query 和响应字段形状不变；GET 不执行模型或恢复写入。

### 消费 Skill 草稿当前 POST 的 schema 拒绝证明（SOURCE 候选）

`POST /api/sand-ai/v1/skills/drafts` 当前同步响应新增一个严格有条件的拒绝投影。只有当前可信消费工作负载、声明 scope/actor 和原外 Idempotency-Key 已精确派生核对内部 key，并持有现 `environment/create_draft/key` 事务锁、读取确认该 key 没有原已提交 operation，且当前原草稿在写入前确定抛出 `SAND_AI_SCHEMA_UNSUPPORTED` 时，才返回 HTTP **400**、外壳 `code=400`、`data.error_code=SAND_AI_SCHEMA_UNSUPPORTED`、`data.retryable=false` 与以下 `data.submission`：

```json
{
  "state": "not_submitted",
  "operation": "draft",
  "request_id": "原外 Idempotency-Key",
  "scope": {"type": "personal", "ref": "原消费声明范围"},
  "actor_ref": "原消费声明操作者",
  "target": {"code": "原显式 Skill code", "expected_draft_version": null},
  "proof": "fresh_locked_schema_validation"
}
```

`submission.request_id` 是本 POST 的原外请求号，外壳 `request_id` 仍是审计请求号。target.code 仅在原草稿省略 code 时为 null，不从当前选中对象猜；显式 code 保留其原值。target.expected_draft_version 仅在原规范 `replace_draft=true` 请求有有效版本时为该版本，其余为 null。Controller 在发出该证明前沿同原资源授权执行当前 revalidate。该拒绝不保存草稿正文、假操作或拒绝账本，不改变成功 operation 指纹，也不扩到 legacy 管理、来源采用或案例请求。

UI 只在当前 POST 返回正式上述 proof，且原请求号、当前 scope/actor 与原 target 逐项匹配时，解除该新鲜 schema 拒绝的 pending，允许纠正字段后另行明确提交。已有同原号 operation（包括排队等待的已提交写入）、普通错误、GET 缺记录、响应丢失及其它未知状态均无此证明，保留原号/原 pending；不能将 HTTP400、404 或 error code 单独解释为未提交。证明只断言本次原 POST 在写前被拒，不授予重发未知原请求或修改既存原 operation 的权限。

仅 SOURCE 交付；本批未运行测试、语法/构建检查、SQL、服务、HTTP 或业务验收。


### 正式Agent模型续行原号与真实尝试投影（5.3 / 5.4）

正式继续仍消费原父任务、当前可信续行授权、官方checkpoint及现失败/未知派发门。显式继续在原父任务锁内，依据同逻辑节点真实已知派发记录冻结模型准入：已明确failed的原调用才分配下一逻辑模型attempt及`_continuation_{attempt}`固定请求号；已成功调用保留原号，未知调用拒绝新准入。准入的`model_requests`与节点`model_request_identity`沿现私有input/context持久元数据保存，HTTP外发前固定；worker lease/reclaim的`task.attempt_count`只属工作恢复，不分配新模型号。原admission、节点context或legacy真实已存dispatch恢复只取原请求号，已完成调用沿ChatGateway原receipt重放，未明调用不重发；legacy缺确定证明不猜新号。协议门、持久stream及原号历史读取核对实际dispatch的task/step/node/逻辑attempt、请求摘要及不可变logical execution/snapshot。不接受任意后缀，不改变现IAM/来源授权、once-send、回执重放、未知不重派或费用去重。

现任务DTO的`agent_invocations`沿原四字段`request_id/state/latency_ms/error_code`保留全部实际round和summary尝试，含原失败及续行成功；3/4限制针对逻辑round，不丢弃失败实际调用或其既有计量。`summary_invocation`投影最新实际summary尝试，原覆盖元数据保持。每次续行流仅补齐自身实际invocation的已持久masked前缀，旧失败流历史保留。受管只读工具ad-hoc输入沿现输出选项校验，未指定时冻结512，上限仍512；已发布定义沿原固定配置。过期本地终结只在当前执行授权真正失效且完整派发清单已知时处理，验证历史明确failed/未发事实及每逻辑节点最新封存成功尝试；任何未知继续保留，不以最初授权过期提前终结仍有效续行，不重发、不改变原账本或费用。上述准入元数据不增加公开DTO字段，不新增表或迁移。

### 私有音频分片清理状态（原 6.3，同一源码候选）

沿用文件 GET、DELETE 和 task DTO 的现有 `cleanup`，不新增路由、权限、数据库结构或状态枚举。仅当同一当前授权文件/任务、环境的实际 `sand_ai_audio_chunk_preparation` ownership ledger 存在时，增加可选字段：

```text
cleanup.resources.audio_chunks?: retained | pending | failed | purged | unconfirmed
```

`audio_chunks` 涵盖该 preparation 的全部私有对象，包括 sequence 0 的标准化 PCM 对象与其余分片。来源需与原 task、文件引用和 SHA-256 绑定一致；不返回对象引用、key、Bucket、私有根目录、存储摘要或 URL。来源/数量/状态证据不完整时返回 `unconfirmed`；缺字段不表示分片已删除。

`purged` 必须由全部实际 preparation 均为 `cleaned`、对象数量符合该 preparation 的固定 sample_count 覆盖、全部对象 state 为 `deleted` 共同证明。`preparing`、`sealed`、`cleaning` 中仍保有对象时：已请求来源清理、任务内容清理或取消的场景为 `pending`，仍可使用的场景为 `retained`。清理对象的重试持续显示 `pending`；现有 ownership ledger 不持久保存删除失败结论，cleanup_attempt_count/last_attempt_time 仅证明尝试，不能据此生成 `failed`。原文件实际 `delete_failed` 继续沿原整体 `failed`。

原 `resources.file=purged` 仍只证明原私有文件已删除；`resources.results=purged` 仍只证明既有正文/回执清理完成。关联分片非 `purged` 时，整体 `cleanup.state` 不得为 `purged`：原整体已清理时投影为分片的 `pending` 或 `unconfirmed`；原整体 `failed` 保留。只有原整体清理证明与全部关联分片删除证明同时成立，整体才能为 `purged`。现 preparation/object ledger 没有实际删除完成时间，因此关联分片存在时整体 `completed_at=null`，不得把更早的 file.delete 时间当作整体完成时间。

文件生命周期 `state='deleted'` 不代表整体清理完成。页面保留原文件 ID，沿 `cleanup.state` 展示和刷新待清理/未确认状态；仅整体 `purged` 可显示清理完成。原文件 `delete_failed` 的原 DELETE 重试边界不改变，已删除原文件不再重复 DELETE。后台异步清理仍使用原恢复 Worker 与 ownership ledger，不新增执行入口，不重建上传，不重新派发转写。

本增量仅定义源码投影及对应页面消费。未执行数据库、对象清理、业务 API、测试或验收。


### SandAI 宿主基础依赖加载门（原 6.4 SOURCE）

Webman 在 `autoload.files` 前读取进程配置。SandAI `config/process.php` 先通过固定相对路径 `require_once` 复用自身 `app/functions.php` 命名空间注册，再执行 foundation 守卫；后续宿主 `include_once` 不重复注册。该现有 loader 只注册 `plugin\SandAi\` 文件映射，不初始化数据库、密钥、业务服务或其他插件，不跳过版本未知／不足的拒绝门。

SandAI 在本插件路由与进程登记前，通过 Composer `InstalledVersions::isInstalled()`／`getVersion()` 读取实际已安装 `supdger/sand-core` 与 `supdger/sand-package`。两者都须为稳定版本 `>=0.2.4`；无上界。缺失、版本未知、预发行或低于最低版本均不登记 SandAI 业务路由和 worker，不执行业务动作；此门仅限制 SandAI，不改变其他插件或宿主基础依赖，也不替代包内 `info.ini` 的宿主 support 判断。

拒绝时，原 `/api/sand-ai/**` 与 `/app/sand-ai/**` 入口返回原错误壳 HTTP／业务 `400`、`data.error_code=SAND_AI_HOST_FOUNDATION_INCOMPATIBLE`、`retryable=false` 和 `Cache-Control: no-store`，说明实际基础版本及管理员处理入口；进程配置同时写入不含凭据的兼容错误。错误入口仅返回兼容状态，不读取正文、返回历史成果、消费调用权限或触发外部动作。基础依赖补齐后，当前仍仅登记错误路由的进程返回 `SAND_AI_HOST_FOUNDATION_RELOAD_REQUIRED`，要求管理员通过宿主正式维护流程重载，不自动重载或改装依赖。

本门使用已安装 Composer 元数据，不新增 plugin dependency、嵌套 Composer require、manifest 字段、网络请求、安装步骤或数据库操作。本候选仅实现源码，未执行测试、检查、审计、编译、安装或加载验证。

### 入站SandAI MCP服务（0.1.21 SOURCE接入）

SandAI 的入站 MCP 服务与出站受管 MCP Client 是两个方向。入站入口为
`/api/sand-ai/mcp`，提供现有业务服务；出站仍通过 Neuron 4 官方 Client 与
SandAI transport 业务适配访问管理员已注册的只读工具。客户端不能指定远端
URL、请求头、厂商凭据或任意动作。

## 版本与入口

- 依赖最低版本 `mcp/sdk >=0.8.1`，不设置版本上限。此前接入依据为
  0.8.1 的 source reference `c5dbfb64e5a2a30872ec3927b4cf13a2eb05e10e`；
  该记录用于追溯当时源码，不限制后续安装版本。
- 使用官方 `Server`、显式 `Builder::addTool` 和
  `StreamableHttpTransport`；兼容 `2025-11-25` 的 `initialize` 握手与 `2026-07-28` 的逐请求现代协议。
  SDK 自行 classify 两代请求；JSON-RPC 编解码、初始化、工具发现和调用均由 SDK 处理，
  不在 classify 前安装仅握手版本的 ProtocolVersionMiddleware。
- Webman Request/Response 只在本进程与 PSR-7 转换，不通过 HTTP 回调自己的
  API。SDK 与正式业务 Controller 都按每次请求的 SandIAM 身份执行。
- 两代请求均支持 POST；现代 server/discover 和带 _meta 的调用无需 initialize，
  不创建持久协议会话。握手时代另支持 DELETE 结束协议会话。GET 持续事件流、Last-Event-ID
  回放、sampling、elicitation 和通用资源代理不在此服务范围。

## 身份与协议会话

Authorization 是面向 `sand_ai`/`sand-ai` 的 SandIAM workload context；
不新增 MCP 账户、凭据或独立授权体系。每次 HTTP 请求验证当前身份与动作，
工具目录按当前已授予动作筛选，每次回调再重验。资源、来源、数据外发、
预算与费用仍由正式 Controller/Gateway 校验；看到工具不表示获准访问所有资源。

协议会话只保存初始化标志、协议版本和空 client_capabilities，不保存业务请求、来源
正文、工具成果、Bearer、凭据、通知队列或旧 JSON-RPC 响应。同 session 的 HTTP
请求使用文件锁；目录私有，按组织/应用/环境/工作负载与当前凭据摘要隔离，
设 TTL，仅握手路径按需创建此存储。现代路径不读取或写入协议存储；SDK 缓存提示
  显式设为 CachePolicy::none()，不回放旧业务结果。多 worker 共享同一宿主 runtime；
  多主机部署需共享私有会话介质。
握手会话失效需重新 initialize；业务恢复仍使用原业务键，不借会话失效重发。

## 业务工具

| 工具 | 正式业务入口 | 业务原号 |
|---|---|---|
| `sand_ai_task_read` | TaskController.read | 原 task_id |
| `sand_ai_workflow_source` | StructuredWorkflowController.source | 原 run_id/source_block_id |
| `sand_ai_audio_review_read` | AudioSegmentReviewController.read | 原 task_id |
| `sand_ai_knowledge_query` | KnowledgeBaseController.query | 必填 idempotency_key |
| `sand_ai_knowledge_recover` | KnowledgeBaseController.queryByRequestKey | 原号及完整原 input_body；只读 |
| `sand_ai_skill_run` | SkillController.run | 固定 code/version，原号映射 request_id |
| `sand_ai_skill_recover` | SkillController.readRun | 固定 code/version 与原 request_id；只读 |
| `sand_ai_tool_run` | ToolController.run | 固定 registry code/version，原号与 business_scope/input |
| `sand_ai_tool_recover` | ToolController.readByKey | 原 request_key，核对原资源/身份；只读 |

参数 JSON Schema 显式注册；Skill/外部工具的业务 input 同时由所选固定发布
版本的正式 Schema validator 校验，不推测领域字段。材料来源返回原业务授权
投影与 locator，不返回私有对象 key、Bucket、永久 URL、凭据或服务器路径。
业务成功采用正式 API envelope 的安全 data；失败提供原 error_code、retryable
和 request_id，不转发异常、原始厂商响应或诊断路径。

可能计费的查询、Skill 和只读外部工具执行要求调用方先保存原业务键与完整
参数。MCP JSON-RPC id 不是业务幂等键。断线/未知结果用对应 recover 工具
沿原号读取；404 不证明未发送，不允许自动换号重发。MCP 协议本身不增加计费。

本契约与候选仅表示源码实现；未安装 SDK、运行服务、调用工具或进行验收。

## MCP 的 SandIAM 与协议会话边界

- MCP HTTP 入口消费真实工作负载的 `Authorization: Bearer <SandIAM signed context>`、连接来源 IP 和现有 `RequestAuditId`。后台管理员登录 JWT 不作为应用调用上下文。
- `McpInvocationContext::authenticate()` 消费服务端登记的 tool → action 映射；至少一项工具的全部 actions 当前可授权才接纳请求。身份过期、撤权、受众不符、来源网络拒绝或 IAM 不可用均返回现有身份拒绝，不降级为空成功列表。
- `allowed()` 对目录登记逐项验证当前服务动作；`requireTool()` 在业务 callback 前再次验证相同映射。实际资源、四级归属、`data_class`、预算和计量由已有公开 Controller → Gateway → SandIAM resource guard 执行。
- `sand_ai_tool_run` 对应 `sand_ai.tool.run`，调用已有 `ToolController::run()`；`sand_ai_tool_recover` 对应 `sand_ai.tool.read`，调用已有 `ToolController::readByKey()`。恢复仅用原 `request_key` 定位，当前资源授权通过后返回原状态，不重派、不续签。
- 协议会话的目录键是四级归属、受众与本次 Bearer 的 SHA256 组合摘要。更换调用上下文需重新 initialize；原业务编号仍走当前身份的业务恢复入口。目录键、路径和原 Bearer 不返回给客户端，不写日志。
- `McpSessionScope::lock(?string $sessionId)` 仅接受标准 UUID 会话编号，在同一 scope 内取得非阻塞全请求锁；HTTP handler 的 `finally` 调用 `release()`。无会话 header 的 initialize 由 SDK 生成新 UUID。
- `McpSessionScope::store()` 惰性返回 SDK `SessionStoreInterface`；仅 SDK 实际访问握手会话时初始化目录、TTL 和元数据存储。现代无会话请求由 SDK 自身处理，不提前创建会话介质。
- 会话只持久化 `initialized` 布尔值、SDK 已知握手协议版本和固定空 `client_capabilities`。`client_info`、日志元数据、`_mcp.*`、工具结果、通知、请求状态及业务 DTO 不持久化，也不从旧文件恢复。业务结果在当前授权调用内即时返回。 同一HTTP请求的 `McpSessionScope` 实例暂存完整SDK状态，供SDK重新读取并排空响应队列；每次读取仍先检查持久元数据及TTL。响应构造结束后的 `release()`、会话删除及过期清理会移除请求内状态，不跨HTTP请求恢复业务载荷。
- 私有 runtime 目录权限为 `0700`，会话、临时写入文件与锁文件为 `0600`。`sand_ai_mcp_server.session_ttl` 是服务端整数配置，范围 60–86400 秒，默认 1800 秒；多 worker 共享同一宿主 runtime。

| 错误码 | retryable | 调用者操作 |
| --- | --- | --- |
| `SAND_AI_MCP_SESSION_BUSY` | `true` | 等待同会话当前请求结束，再提交本次请求；业务调用继续保留原幂等键。 |
| `SAND_AI_MCP_SESSION_UNAVAILABLE` | `true` | 恢复协议存储可用性后重试；已有业务运行通过原 `request_key` 的当前授权查询恢复。 |
| `SAND_AI_CONFIGURATION_NOT_PUBLISHED` | `false` | 管理员修正正式服务动作映射或会话 TTL 配置后再次调用。 |

本片段供单一整合者并入现有 API 契约；源码候选未执行检查、测试、编译或运行验收。


### 已冻结请求帧与header（未运行示例）

两代均 `POST /api/sand-ai/mcp`，请求header为 `Authorization: Bearer <SandIAM工作负载调用上下文>`、`Content-Type: application/json`、`Accept: application/json, text/event-stream`。此处仅占位符，不提供或记录真实凭据。

现代请求必须带 `Mcp-Protocol-Version: 2026-07-28`，且header与正文协议版本一致；header单独不足以选择现代协议，每个body的params._meta还须声明 `io.modelcontextprotocol/protocolVersion` 和 `io.modelcontextprotocol/clientCapabilities`，不带会话。每次请求的 `Mcp-Method` 必须与正文method一致；下列发现请求带 `Mcp-Method: server/discover`。发现示例：

```json
{"jsonrpc":"2.0","id":1,"method":"server/discover","params":{"_meta":{"io.modelcontextprotocol/protocolVersion":"2026-07-28","io.modelcontextprotocol/clientCapabilities":{}}}}
```

列工具将正文method及 `Mcp-Method` 同时改为 `tools/list`，其余同。下列只读任务调用须带 `Mcp-Method: tools/call`、`Mcp-Name: sand_ai_task_read` 和 `Mcp-Protocol-Version: 2026-07-28`；`Mcp-Name` 与正文params.name一致（此工具名可直接作header值）。123必须替换为该caller已有权读取的真实任务整数ID，不是业务幂等键：

```json
{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"sand_ai_task_read","arguments":{"task_id":123},"_meta":{"io.modelcontextprotocol/protocolVersion":"2026-07-28","io.modelcontextprotocol/clientCapabilities":{}}}}
```

握手时代先initialize：

```json
{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"sandai-caller","version":"1.0.0"}}}
```

取得响应`Mcp-Session-Id`后，每次带原Bearer、该session及`Mcp-Protocol-Version: 2025-11-25`，发送`{"jsonrpc":"2.0","method":"notifications/initialized"}`，再用`{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}`发现工具；tools/call同上name/arguments，去掉_meta。可能计费工具须按固定schema传原业务request key，恢复工具只读；会话和JSON-RPC id不代替业务号。

动作映射：task_read→sand_ai.task.read；workflow_source→sand_ai.workflow.read；audio_review_read→sand_ai.audio.transcription＋sand_ai.audio.review；knowledge_query/recover→sand_ai.knowledge.query；skill_run/recover→sand_ai.skill.run；tool_run→sand_ai.tool.run、tool_recover→sand_ai.tool.read。表述中的工具均带`sand_ai_`前缀，每次执行仍校验实际资源授权。

### 4.5 会议转写完整 SOURCE 候选（2026-10-08）

沿用现有转写、任务读取、片段复核、音频来源和原键找回路线、SandIAM动作、任务Schema及幂等语义。

新转写片段 `confidence` 为规范四位小数字符串或 `null`；真实可用测量才提供数字，未知使用 `null` 且必须有 `confidence_unavailable` 或 `local_confidence_unavailable`。现有缺失置信度驱动统一写 `null`，不推导服务商未提供的字段。历史封存结果的 `0.0000` 与不可用警告保持原值及摘要，读取/界面按未知展示。原base为null时文字/匿名说话人复核仍保留null；旧string base沿原复核投影，不改既有封存字节。

`GET .../audio-transcriptions/{taskId}/reviews` 每段增只读 `original_speaker:string|null`、`confidence:string|null`、`warnings:string[]`。置信度来自不可变原转写，而非人工修改后的文字质量测量；警告来自当前复核版本，保留引擎限制且表示空白/人工复核状态。原始识别说话人与当前人工匿名编号分别可见。POST仍只允许文字和匿名speaker，不允许修改时间、来源、置信度或警告。

OpenAI外转写在prepare/send之前使用可信首音轨容器播放时钟探测并固定真实原音频时长，无预算策略同样适用；无法核实拒绝外发。响应时长只核对其片段有效性，不作为收费权威；按可信时长补齐未覆盖音频范围，越界片段拒绝，服务商时间仍只来自其现有分段字段。计量使用可信原时长，新回执须与冻结时长一致才能封存。已发送的历史请求仍走原回执恢复，不补写其冻结时长、改回执、重新发出或重新计费；既有封存与费用不重算。

迁移仅增量替换音频seal复核函数的NULL分支，历史098文件不改。仅内部派发快照增加可选 `duration_policy="verified_private_audio@1"`，由可信源码为首次新派发写入，纳入原请求指纹和加密回执关联；不接受caller/provider设置，不对外返回。旧派发缺字段时仍缺失并保留原指纹，新分片派发继承该标记。新回执与逐片聚合依此策略核对可信冻结时长，独立于置信度是否已知；原GLM可信时长要求继续适用。已发送旧请求保持原快照和计量语义，不靠补写策略或重发“升级”。

SOURCE尚未测试/静态检查/编译/执行迁移/安装/运行验收。

## 原 4.2 费用汇总：调用时冻结预估

原 GET `/app/sand-ai/admin/usage/summary` 与 GET `/app/sand-ai/admin/usage/summary/export.csv` 的原查询、权限和范围不变。`SandAiUsageSummaryRow` 在 overall、全部 by_* 维度及组合运行费用分解 rows 增加 `estimate`；新响应提供对象，旧响应缺字段视为未提供，不能当零或已完整覆盖。

```ts
estimate?: {
  basis: 'budget_reservation_upper_bound'
  known_events: number
  unknown_events: number
  held_events: number
  amount: string | null
  currency: string | null
  known_amounts: { currency: string; amount: string }[]
}
```

- 类别为“预估（调用时预算上界）”，并非预计实付、实际计量、已核账金额或节省。
- 每个原不可变用量事件仅使用其原 Invocation、dispatch、call-start price snapshot、原 reserve budget event 的一致冻结证明。复用 InvocationCostEstimatePolicy；同调用的多预算作用域只取一个原上界，父运行汇总不是新增收费事件；没有 Invocation 的工具事件不造预估。
- 预估与原金额共享 UsageEventQuery 的当前管理员允许范围、时间、筛选及原维度关联表达式；同一次汇总采用只读 repeatable-read 快照。已有 repeatable-read/serializable 事务复用其最初快照；已有 read-committed 事务不修改其设置，沿原 SAND_AI_RESOURCE_CONFLICT 只读刷新。
- known_events + unknown_events = total_events；held_events 是 unknown_events 子集，仅表示冻结证明冲突待核实。无原历史证明保持未知，不以当前价格或实际计量倒算。
- amount/currency 仅全部事件有证明且只有一种预估币种时非 null；金额为精确六位小数十进制字符串。存在未知或多个预估币种时完整金额为 null。known_amounts 保存已知部分并按币种分别累计；不混币种，不把已知小计当完整预估。
- 原 event.currency 未定价行仍可含不同预估币种，故 known_amounts 的币种来自原证明，独立于实际 event.currency。当前汇总不覆盖未产生用量事件的 dispatch；原 ambiguous 与单次原请求找回入口保持独立，不能当作免费或已知事件。
- 各维度原最多 100 行与 truncated 语义保留；预估仅对应已返回行。费用排名、失败浪费和计量差额继续使用原实际金额口径，不使用上界预测现金或节省。

原 CSV 列顺序保留，末尾追加：estimate_basis、estimated_budget_upper_bound、estimated_currency、known_estimate_events、unknown_estimate_events、held_estimate_events、known_estimated_budget_upper_bounds。最后一列为 known_amounts 的 JSON；完整金额未知/币种多种为空单元格，原响应无 estimate 时追加列均为空。metadata 行追加列为空。

页面“各币种总览”及“维度明细”并列显示预估上界、原计量、原已核对账单金额；覆盖显示已知/未知与其中待核实次数。部分已知按币种列出，完全已知多币种不合计，原响应缺字段明确未知。


### 异步任务明确使用当前授权继续（原2.3／4.5 SOURCE）

`POST /api/sand-ai/v1/tasks/{taskId}/retry` 新增 `{"mode":"authorization_continue"}`；body 只接受此 mode，不携 target，须独立稳定 `Idempotency-Key`。服务端用 `task_auth_continue` 域绑定当前可信作用域及原 task ID。原任务、原首次提交键、会议复核版本、音频哈希、材料文件及规划不变；agent_run 沿其已有 `/agents/tasks/{taskId}/continue`，不通过本 mode 更新子工具身份。

Task DTO 对支持的任务可附加 `authorization_continuation:{required:boolean,available:boolean,mode:"authorization_continue",reason:"authorization_required"|"lease_active"|"source_unavailable"|null}`。正式 `TaskGateway::summary` 同时供 task.read 及复用该投影的读取入口使用。`required` 覆盖持久授权暂停，以及尚未由 Worker 标记的 queued／retrying／recovery_pending 原封套到期；活动 Worker 显示 lease_active，不能被此操作抢占。普通业务失败不会仅因历史封套后来过期而重置失败额度。availability 仅说明结构条件，当前 caller 必须再次通过 `sand_ai.task.retry`、原业务 action、原组织／应用／环境／工作负载 scope 和真实私有来源授权；它不授予新权限。

Worker 在原认证过期、撤权或服务／范围／网络／数据类别授权拒绝时停止后续节点，将授权暂停记入父 TaskStep 的独立 context，保持已封存成功节点、原调用身份及已发生费用。队列身份仍遵原最长300秒边界，不由 Worker 续签。没有未结派发时任务为 failed；有未结派发或材料封存待恢复时为 recovery_pending。普通任务与会议／音频／材料回执领取均不自动领取 required 的暂停任务。

当前原调用方明确继续时，服务端正式取得并复验新的 SandIAM decision，仅更新 task 当前执行授权封套；新、旧 decision ID 与稳定继续意图单独记录在原 AuditLog，既有 invocation／dispatch／回执／usage 的旧授权证据不覆盖。新授权仍保留自身到期时间，不延长旧 exp；当前授权再次到期则再次暂停。操作不清空失败或回执次数，仅为这次显式同进度继续保留一个后续领取／原回执机会，已成功节点不重跑、不重复计费。未知派发仍 recovery_pending；先沿原 invocation／receipt 找回并封存，未结事实不允许替换请求号或派发后续节点。

同 task、scope 与继续键的 POST 重放核当前任务及原资源权限后，只读取已经原子写入的意图和安全 Task 摘要，不再次更新执行授权或入队。响应未知时保留原 task、同键，由用户明确找回；不能自动生成另一键。POST 不返回业务成果，收到同任务 DTO 后用原 task.read 读取成果及来源，读失败不推断继续失败。页面不向 API 提交 authorization_id、caller 或私有身份封套；DTO 不返回密文、decision ID、原始厂商响应或私有存储信息。

本段为源码操作合同，不表示已运行、测试、编译或验收。

短外部音频的原 `audio-task:{id}` 结果回执由 `TaskAudioReceiptContinuation` 在正式 Task worker 中领取，须是同任务、同冻结指纹且未销毁／未过期的 `result` 回执，复用 `PostgresExternalAudioResultRecoveryPort`、原 usage 与音频封存。只有执行租约字段可重新认领，原调用授权／fingerprint／receipt／usage 不替换；当前授权在原回执与费用锁前后重验。该消费面没有 adapter send 或 query；accepted／ambiguous 等没有已有结果回执的调用保持 recovery_pending，不把等待当新调用许可。长音频 clip 仍沿现 AudioChunkResultRecovery，会议／材料仍复用各自已封存节点及原 receipt 恢复。

### 任务取消／普通失败重试的确认代次（原 2.3／3.4 SOURCE）

现 `POST /api/sand-ai/v1/tasks/{taskId}/cancel` 和普通 `POST /api/sand-ai/v1/tasks/{taskId}/retry` 可同时携带 `expected_attempt_count` 与 `expected_state`，也可同时省略。前者对应正式 Task DTO 的 `attempt_count`，必须为 JSON 非负整数且不超过 9007199254740991（初次 queued 可为 0）；后者必须为现 Task 状态之一：authorization_pending、queued、running、retrying、recovery_pending、succeeded、failed、canceled。单字段、显式 null、字符串代次数或非法状态均返回现 SAND_AI_VALIDATION_ERROR。

普通材料重试还可携带对应目标的前置条件：target=failed_files 时 `expected_failed_files:[{file_id,attempt_count}]` 是非空、按真实 file_id 升序、无重复的全部已确认失败文件集合，file_id 为正安全整数、attempt_count 为非负安全整数；target=cross_file_review 时 `expected_review_attempt_count` 是原正式 Run DTO 的非负安全整数复核代次。目标字段必须同任务两字段且只用于对应普通 target，非法组合拒绝，不允许缺字段猜 0／空集合。旧调用者省略目标前置条件沿原行为。

服务在现任务行锁及真实文件／复核行锁内比较原任务 attempt_count/state 与目标集合／复核代次，发生变化返回现 SAND_AI_RESOURCE_CONFLICT，不取消／重试后来代次，不变更步骤、授权或派发。页面保留确认前原任务／业务资源／代次／状态，确认后只读原任务；材料另重读原运行及精确失败文件／复核代次。读取不一致只展示当前状态，须用户重新确认，不自动重弹或 POST。材料无可确认失败目标时不回退省略 target 的普通全量重试。

这些字段属于操作前置条件，不是新命令幂等键，不改原模型请求、收费指纹或已发生调用；旧客户端同时省略时沿既有行为。receipt_only 与 authorization_continue 保持各自原合同，不携本组字段。普通重试仍须现失败任务／真实 failed_files 或 cross_file_review 门、当前来源与授权，成功节点及费用保持，unknown 只沿原 journal／Task GET／receipt 恢复，不据失败或取消显示未派发。

## 原 5.5：双渠道凭据原保存结果只读恢复

保留原两个 POST 完整替换输入、Idempotency-Key 及原指纹语义，新增两个 GET：

- `/app/sand-ai/admin/channels/bindings/feishu-credentials/by-request-key`
- `/app/sand-ai/admin/channels/bindings/wecom-credentials/by-request-key`

GET 只接受以下五个 query 字段，无 body、无凭据字段：`environment_id` 正整数，`channel_binding_id` 正整数，`code` 原绑定代码，`request_key` 原非空请求键（不含控制字符，最多 128 字节，不 trim 替换），`input_sha256` 原 64 位小写十六进制输入摘要。沿 `sand_ai:channel:binding:manage`、正式当前管理员及现 SandIAM 环境事实验证；读前读后复验当前权限与真实 environment/binding/code 归属。只读路由不经过要求秘密 POST body 的敏感写入中间件，不挂 SystemLog；原 POST 敏感清除中间件保持。

响应 `Cache-Control: private, no-store`，data 固定为：

```ts
type CredentialSaveRecovery = {
  platform: 'feishu' | 'wecom'
  environment_id: number
  channel_binding_id: number
  code: string
  request_key: string
  input_sha256: string
} & (
  | { state: 'unknown'; actor_id: null; receipt: null }
  | { state: 'completed'; actor_id: number; receipt:
      | { channel_binding_id: number; code: string; feishu_configured: true }
      | { channel_binding_id: number; code: string; wecom_configured: true }
    }
)
```

receipt 必须只含对应平台的原三个非秘密字段；completed.actor_id 来自原 operation.created_by，并严格等于当前正式管理员，不从当前登录信息伪造历史 actor。缺原行、原 created_by 为空或属于其他管理员时返回 unknown/actor_id:null/receipt:null，不泄露他人操作。原 actor 已证明但指纹不同沿现 `SAND_AI_IDEMPOTENCY_CONFLICT` 拒绝；原快照或目标矛盾沿现 `SAND_AI_SCOPE_SNAPSHOT_INVALID` 拒绝。当前环境或绑定不可用、无权、404、网络错误不能证明原操作未执行，页面保留原记录且不得自动 POST 或换键。

新保存原操作在同凭据事务持久 created_by 真实管理员；POST replay 同样验证原 actor/target/指纹后只返回严格白名单收据。迁移 `202610090145_channel_credential_operation_actor.pgsql` 给两个原 credential_operation 表增加 nullable created_by；原无 actor 行保持 NULL，不回填推断。原表 primary key、原请求指纹和旧金融/身份字段不变；本轮仅编写迁移，未执行。

input_sha256 完全沿原 RequestPayloadFingerprint：对 `{channel_binding_id,...对应平台原五或六个秘密字段}` 按字段名排序，UTF-8 JSON，保留 Unicode 与 slash，U+2028/U+2029 采用原 PHP 转义，然后 SHA-256。environment_id、code、Idempotency-Key 不进入该摘要；它们分别由原操作环境、绑定原 code、键独立证明。字段原值不 trim。前端仅在原输入仍在本页面时计算一次摘要，跨会话只存 `{platform,user_id,environment_id,channel_binding_id,code,request_key,input_sha256}` 不含秘密的原引用；无原摘要历史不补造。

completed 仅证明原保存请求的已提交非秘密收据，不证明该凭据仍是当前版本、不证明外部渠道可用。页面完成后沿当前绑定列表/状态另行读取；未知记录只读找回，不通过重发 POST、放弃锁或新键替代证明。人工更换或过往来源不会作为此凭据恢复的旁证。

## Skill 管理原操作与来源采用只读回执（增量）

以下 GET 读取已提交的原操作，不重放 POST、创建模型调用、改变案例或发布状态，也不写费用：

- `GET /api/sand-ai/v1/skills/{code}/versions/{version}/operations/{operation}/by-request-key`，operation 仅 `publish|rollback`，version 必须是原发布或回滚版本。
- `GET /api/sand-ai/v1/skills/{code}/operations/revoke/by-request-key`。原撤销 POST 只指定 code，GET 从原 operation 返回实际撤销版本，不以页面选择版本或当前 active_version 推断原目标。
- `GET /api/sand-ai/v1/skills/{code}/versions/{version}/cases/{caseId}/operations/{operation}/by-request-key`，operation 仅 `review|retire`，code/version/caseId 均为原固定目标。
- `GET /api/sand-ai/v1/skills/source-suggestions/{suggestionId}/adoption/by-request-key`，suggestionId 为原采用目标。

四接口 query 仅 `request_id`（原 POST 原始 Idempotency-Key）、`scope_type`、`scope_ref`、`actor_ref`，以及可选 `input_sha256`；无请求正文，私有 note/reason/draft 不进 URL。当前已授权工作负载与原 consumer snapshot（组织、应用、环境、客户端、scope、actor）绑定内部原 key。publish/rollback/revoke 须当前 `sand_ai.skill.publish` 与 `sand_ai.skill.read`；review/retire/adoption 须当前 `sand_ai.skill.manage` 与 `sand_ai.skill.read`。固定 Skill 及当前来源继续正式 resource 授权并在返回前重验。撤销使用原 `sand_ai.skill_revoke` metadata resolver，不为确认撤销重新打开已撤来源；adoption 另重验原 suggestion、knowledge 与 chat invocation，并核同 key 的 create_draft 回执。

`input_sha256` 是规范化原上游 POST body 的 SHA256：对象键递归按字符串排序，数组保持顺序，UTF-8 与斜杠不转义；不是消费应用 BFF 自己的 body hash。publish/rollback/revoke body 为 `{scope,actor_ref}`；review 为 `{scope,actor_ref,skill_run_id,verdict,note}`（原 note 经 trim）；retire 为 `{scope,actor_ref,reason}`（原 reason 经 trim）；adoption 为 `{scope,actor_ref,selected,draft}`（selected 按正整数升序、draft 保原对象）。路径目标与 operation 由原记录另行精确核对。新持久意图已有上游 hash 时，消费端必须传入并核对；历史缺 hash 仅可依已存在的原 key/scope/目标/actor/不可变操作证明读取，不能把响应 hash 反填成遗失原意图的证据。

成功 data 统一附 `operation,request_id,input_sha256,skill_id,version_id,code,version,idempotent_replay:true,scope,scope_source:consumer_declared,actor_ref`。publish/rollback 再含原 `state:published`，revoke 含原 `state:revoked`；review 含原 `evaluation_id,case_id,skill_run_id,verdict`，不返回 note；retire 含 `case_id,state:retired`，不返回 reason；adoption 含原 `state:draft,source_suggestion_id`，不返回 draft/来源正文。transition 与 adoption 另附原服务端 opaque `request_fingerprint`。回执描述原提交结果，不代表当前 Skill、active_version 或最新案例判定仍处于该状态。

管理 transition 核原 fingerprint/consumer/result 与真实固定 Skill/version；review 核原 evaluation、原 reviewer 和同版本成功 test；retire 核原 retire_request_id/retired_by/reason 与结果。来源采用新增 JSON 内的 input_sha256，不改变旧 POST 指纹或表结构。历史采用须原 adoption 与同 key/consumer/result 的 create_draft 回执以及固定版本 provenance 同时成立；若旧 JSON 无 hash，仅从不可变版本重建的规范 draft（code 显式或原可选省略）确实匹配原 adoption fingerprint 时才可恢复；丢失的原空白或其他无法重建的输入不猜、不补写旧记录。

缺原记录为 `SAND_AI_RESOURCE_NOT_FOUND`；缺原 actor、指纹、子回执或可重建输入证明为 `SAND_AI_INVOCATION_FACTS_UNVERIFIED`；原 hash/目标不匹配为 `SAND_AI_IDEMPOTENCY_CONFLICT`；身份、来源、内容清理拒读沿现错误。404、拒绝、未知费用或网络失败不证明原操作未提交，不解除 unknown、不自动再 POST 或新 key。`consumer_declared` 仍是已授权消费应用声明，真实个人/团队成员由消费应用负责核实，SandAI 不建立平行 IAM。

本批仅 SOURCE，未调用 GET/POST、模型或 SDK，未执行数据库、检查、测试、审计、编译或安装。

### 后台管理员 Skill 原管理回执只读恢复（SOURCE）

后台 `SkillPanel` 与个人/团队/组织 `ConsumerSkillPanel` 是两种真实入口。下面仅消费当前后台登录管理员，继续 `CheckLogin/CheckAuth`；不接收自报 actor、工作负载或 consumer scope。各 GET 使用原写动作的现有 permission：草稿 `sand_ai:skill:create`，发布 `sand_ai:skill:publish`，撤销 `sand_ai:skill:revoke`，案例 `sand_ai:skill:case:manage`。环境继续官方 SandIAM 当前环境核验，资源限定 legacy Skill；不扩大为读取消费应用个人或团队的记录。

| GET 路径（`/app/sand-ai/admin/skills` 下） | 唯一 query | 原操作 |
| --- | --- | --- |
| `/drafts/by-request-key` | `environment_id,request_id` | `create_draft` |
| `/publish/by-request-key` | `environment_id,request_id,skill_id,version` | `publish`，固定原版本 |
| `/revoke/by-request-key` | `environment_id,request_id,skill_id` | `revoke`，不从当前活动版本猜原目标 |
| `/cases/create/by-request-key` | `environment_id,request_id,skill_version_id` | `create`，原固定版本 ID |
| `/cases/review/by-request-key` | `environment_id,request_id,case_id` | `review`，原判定，不是 latest evaluation |
| `/cases/retire/by-request-key` | `environment_id,request_id,case_id` | `retire` |

`request_id` 是原 POST 原始 `Idempotency-Key`，GET 无该 header、无正文及私有 note/reason/draft。后台页面只持久原管理员/环境、操作号、固定目标和原意图摘要。新案例保存版本 ID；新审核/废弃保存案例 ID。旧元信息未保存案例目标时，只允许人明确输入原版本 ID 或案例 ID，服务端仍严格核原记录；不拿当前草稿或当前选中案例代替原目标。

成功 data 含 `operation,request_id,environment_id,actor_ref,skill_id,version_id,code,version,idempotent_replay:true`。草稿/发布/撤销分别另含原 `state:draft|published|revoked`；案例创建含 `case_id,case_no`，审核含 `evaluation_id,case_id,skill_run_id,verdict`，废弃含 `case_id,state:retired`。不返回原私有输入或审核说明。`actor_ref` 仅来自后台真实认证 ID。

草稿与 transition 读取原 `sand_ai_skill_operation`、原 fingerprint/result、当前 legacy Skill/version，并要求同事务 audit 的原管理员、请求号、指纹及版本证明。新后台提交仅在既有 audit context 增加该关联，不改 POST 的旧 fingerprint、请求重放、表结构或消费 API。旧生命周期 audit 未绑定原 key 时，当前列表状态或同版本不能补造原 actor 证明，返回 `SAND_AI_INVOCATION_FACTS_UNVERIFIED` 并保留未知。案例直接核不可变 `create_request_id/created_by`、`evaluation.request_id/reviewer_ref` 与同版本成功 test，或 `retire_request_id/retired_by`；这些原字段可用于真实历史恢复。

只有完整匹配原管理员、环境、操作号及固定目标的正式 GET 回执才解除管理 pending，之后另读当前列表/版本/案例。404、权限拒绝、证明缺失或网络错误均保原号，不表示未提交，不重新 POST 或自动创建新号。原运行、评测和独立费用恢复保持既有正式入口；回执不表示当前活动版本或最新判定仍是原状态。后台的来源采用、回滚仍使用实际 `ConsumerSkillPanel` 和已授权应用/consumer 声明的既有 GET，不造管理员来源采用业务。

### 知识停用、完整向量清理、来源解除与共享操作原回执（SOURCE）

以下均为后台只读 GET，沿当前管理员 `CheckLogin/CheckAuth`、真实管理员 ID 与官方 SandIAM 当前环境消费，不接受自报 actor 或工作负载身份。GET 无正文、无 `Idempotency-Key` header，`request_id` 是原 POST 的原始键，响应及错误 `Cache-Control:no-store`。

| GET（前缀 `/app/sand-ai/admin/knowledge-bases`） | 唯一 query | 当前 permission | DTO operation |
| --- | --- | --- | --- |
| `/disable/by-request-key` | `environment_id,knowledge_base_id,request_id` | `sand_ai:knowledge:disable` | `disable` |
| `/embedding-index/purge/by-request-key` | 同上 | 同上 | `purge_vectors` |
| `/sources/purge/by-request-key` | 同上 | 同上 | `purge_sources` |
| `/publications/publish/by-request-key` | `owner_environment_id,knowledge_base_id,consumer_environment_id,code,request_id` | `sand_ai:knowledge:publication:publish` | `publish` |
| `/publications/revoke/by-request-key` | `owner_environment_id,publication_id,request_id` | `sand_ai:knowledge:publication:revoke` | `revoke` |

生命周期 data 为 `environment_id,knowledge_base_id,operation,request_id,actor_ref,state,idempotent_replay:true`，state 为原 `disabled` 或 `purged`。共享回执 data 为 `operation,request_id,actor_ref,owner_environment_id,publication_id,knowledge_base_id,consumer_environment_id,code,state,idempotent_replay:true`，state 为原 operation 封存的 `published|revoked`。返回的是原操作提交结果，页面再独立读当前列表，不把后来重新开放、清理或其他当前状态冒作原 key 证明。GET 不返回来源正文、对象 key、原始 provider 返回或秘密，不恢复来源访问权限。

原 `sand_ai_knowledge_operation` / `sand_ai_knowledge_publication_operation` 的 environment、目标、唯一 key、旧 fingerprint 与共享封存 result_state 必须匹配；还须同事务 audit 中的真实管理员、原 key、fingerprint、所属环境及资源关联。新提交只向既有 audit context 添加这些关联，旧 POST 指纹、重放与表结构不变。旧记录若无 exact key 到原管理员的证明，则返回 `SAND_AI_INVOCATION_FACTS_UNVERIFIED` 保未知，不能根据 current list、同目标 audit 或 GET404 反推未提交。共享发布读取同时核原 code/base/consumer 与当前真实环境归属，撤销读取原 publication ID；不要求新造来源摘要或以当前 active version 猜旧发布意图。

停用仍走原 pending conversation/未决 embedding guard；向量清理仍走现 `purgeDisabled`，在原事务中清理所有版本的 active/index/vector 派生数据及向量 outcome ciphertext，保 invocation/usage 原计量事实；来源解除仍须先停用、撤销所有应用发布并清理向量，原事务删除来源引用、知识会话/轮次及派生来源副本和 ciphertext。GET 只 SELECT 这些已提交原操作的元数据，不调用 purge、receipt consumer、模型或 accounting recovery。来源清理后的独立费用待恢复不由内容删除回执冒称已结算；原始文件仍由原所有者执行正式删除，不用来源解除代替原文件生命周期。

`KnowledgeBasePanel.continueLifecycle` 仅 GET 原回执。首发前可靠保存及读回原号；仅本页持有可证明原 POST 未开始的内存意图时，才提供“保存原号后提交原操作”，仍为原 key/action/target/current identity。发送开始即删除未发标识，任何异常随后仅 GET；刷新、离页、管理员/权限 token 或环境变化清内存标识，不从 persisted journal 或 404 推未发。只有正式匹配的原回执与原 journal 清除读回确认后解锁。`PublicationPanel` 的未知开放/撤销同样只 GET；原目标完整保留，响应缺失、拒绝、事实未核或网络失败均不重 POST、不换号。

### 3.5 价格写入原请求只读恢复

现有 POST `/app/sand-ai/admin/pricing/revision/save-draft` body 与 `/publish` body `{id:number}` 保持。新客户端传可选 `Idempotency-Key`（1–128位 `[A-Za-z0-9._:-]`）；省略仍保旧行为。新 key 的原 actor/kind/hash/目标/收据在价格写入同一事务封存。相同 actor/kind/key 的不同 body 为原 `SAND_AI_RESOURCE_CONFLICT`。无 key 的旧写入没有原收据，不伪补历史证明。发布已发布版本的收据只证明此原操作返回该版本，不能证明它首次发布或仍当前生效。

`GET /app/sand-ai/admin/pricing/revision/read-operation-by-request-key`，无 body、无写入。Query：
- `kind: 'save'|'publish'`
- `provider_id:number,model_id:number,capability_code:string`
- `target_id?:number`：原输入 id，新建 save 省略；publish 必须原 id。不是新建输出 id。
- `request_key:string,input_sha256:64位小写hex`

当前登录管理员须保价格查看及原操作 save/publish 权限；读取前后重验。同原 actor/key、全部原目标与 hash 才返回 completed。价格为全局 provider/model/capability 分区，不增加 environment。

DTO：`{kind,actor_id:number|null,provider_id:number,model_id:number,capability_code:string,target_id:number|null,request_key:string,input_sha256:string,state:'unknown'|'completed',receipt:PriceRevision|null}`。
- unknown：`actor_id=null,receipt=null`；未查到/目标或 hash 无证明，不证明未写入，不重 POST、不换 key。
- completed：actor_id 取真正原收据 actor；receipt 取原 `response_snapshot`（原 PriceRevision 包含原 rates/id/revision/state），不是重读当前版本。新建 save receipt.id 与输入 target_id=null 明确分开。
- 保存 hash：原 POST body 原值递归按对象键字母序排序；数组保持顺序；UTF-8 JSON Unicode/slash不转义，U+2028/U+2029转义；SHA-256。不对 UI 金额补零、当前价格倒算或重写原 price/refund 指纹。publish 同规范原 `{id:number}`。

专业重排价格页存非秘密原 actor/provider/model/driver/kind/key/hash/target 引用；身份/session epoch 变更同步清结果、作废旧响应。新原操作仅按 GET proof 解锁；旧无收据 marker 持续未知。confirmed receipt 后再读目录，明确原回执与当前目录状态。

### 3.5 退款响应未知的只读恢复

复用现 GET `/usage/adjustment/index` 原 environment/usage_event 分页和 `/read` 原 environment/id。恢复按钮只查询，不调用 refund POST；列表/详情按原管理员、环境、事件、idempotency_key 对齐，冻结输入仍在时再 `matchesRefund` 比对凭据ID/定位/引用/金额/币种/说明。未查到不等于未写入；原草稿丢失只展示原记录供核查，不能伪称完整输入已证明或另建请求。原账本、金额、币种和退款指纹不变。

### 原 3.1／3.2 Provider / Model 目录保存与原请求只读恢复

全局管理员目录，不添加 environment 或平行 IAM。现 POST `/app/sand-ai/admin/provider/save|update`、`/model/save|update` 保 body 及返回 shape（save `{id:number}`，update 原更新成功字符串）；新客户端只加可选 `Idempotency-Key`（1–128位 `[A-Za-z0-9._:-]`）。无 key 保既有 CRUD 路径，不补历史收据。Provider POST 保 OnboardingSensitiveRequestMiddleware 先抽取／抹除秘密，路由不加 SystemLog。

新 key：真实当前管理员、资源类型、save/update 动作及原 key 定域，按既有 PostgreSQL advisory lock 串行；复用现 DeploymentDraftSaveRequestPolicy 配置的 keyring HMAC 证明原有效输入（含 vault 恢复的 encrypted_config，仅内存），不生成新密钥。原秘密不写 audit／可逆输入／普通摘要，不让客户端计算或提供秘密 hash。同键同输入复用原 safe receipt，同键异输入为原 `SAND_AI_IDEMPOTENCY_CONFLICT`；缺历史 keyring proof 不补假成功。实际目录写与 AuditLog 新收据同一 transaction，callback 读取同一写事务内落库值后只持久公开白名单。

GET：`/app/sand-ai/admin/provider/read-operation-by-request-key`、`/app/sand-ai/admin/model/read-operation-by-request-key`。
- query：`operation:'save'|'update',request_key:string,code:string,target_id?:number`。save 省略 target_id（原输入没有 id），update 必须原目标 id。code 取原新建输入或原被编辑对象 code。无 body、environment、secret、HMAC 或客户端 hash。
- 当前认证 admin 保该目录 read 与原 save/update 权限；读取前后重验，超级管理员沿宿主原身份事实。原 actor/resource/action/key/code/target 对齐才 completed。
- DTO：`{resource_type:'provider'|'model',operation:'save'|'update',actor_id:number|null,request_key:string,code:string,target_id:number|null,state:'unknown'|'completed',receipt:ProviderSafe|ModelSafe|null}`。
- unknown：actor_id=null、receipt=null；旧无收据／未查到／原目标不符不能证明未写入，不换号、重 POST 或伪补 actor。
- completed：actor_id 取实际原 AuditLog.actor_ref；receipt 是不可变原公开快照，不重读 current directory 冒充原保存，不证明外部连接可用。save 输入 target_id=null 与输出 receipt.id 明确分开。
- ProviderSafe：`{id:number,code:string,name:string,adapter:string,timeout_ms:number,status:1|2,has_encrypted_config:boolean}`。
- ModelSafe：`{id:number,code:string,name:string,type:string,capabilities:string[],status:1|2}`。

API 前端以实际原 actor/action/code/id/key 保存非秘密引用；发送前清秘密表单，响应未知只 GET 原收据。completed 全部归属匹配后再读取当前目录／刷新列表；原回执与当前配置分别显示。请求旧 shape 本身不作完整 receipt proof。身份／token epoch 变化清归属结果并作废迟到响应；不继承旧秘密。

147 source-only 增量严格限 sand_ai_audit_log 内新 `catalog.provider.save|update`、`catalog.model.save|update` 且 actor_type=admin、context.version=catalog_operation@1 子集：partial UNIQUE 原 actor/action/lock digest，局部 UPDATE/DELETE guard（也禁止通过 UPDATE 伪补新收据），不改旧审计记录／策略、不新表。普通 AuditLogic 对这四 action 只输出 version/target/code/公开 receipt，屏蔽内部 HMAC／key id／锁 proof。新 GET 只返回上述安全 DTO。

### 渠道关闭原请求只读恢复

`GET /api/sand-ai/v1/channels/{code}/conversations/{conversationId}/close/by-request-key`

- 当前应用 Bearer；`Idempotency-Key` 为原关闭请求号。query：`sender_id`，可选 `delivery_platform=feishu|wecom`。不发送正文，不创建或重放关闭请求。
- 当前 `sand_ai.agent.submit` 与 `sand_ai.task.read` 均需有效且属于同一应用作用域；复用当前绑定、已发布 Agent 和真实平台配置所有权检查。平台不填沿原应用预览作用域，不能用声明的平台绕过管理配置。
- 原号沿既有 `channel-close` + 当前 IAM scope + `{code}:{conversation_scope}` 规则派生，不改变旧 POST 指纹或原记录。
- 仅按原 `environment_id/channel_binding_id/conversation_scope/owner_scope/request_id` 关闭记录读取，并核同一原事务 audit 的 workload `actor_ref`、`close_id` 和 `closed_after_task_id`。不得以当前关闭边界或列表状态认定原关闭完成。
- success data：`operation:"close"`、`request_key`、`environment_id`、`channel_binding_id`、`code`、`conversation_id`、`sender_id`、`delivery_platform:null|"feishu"|"wecom"`、`actor_ref`、`id`（正安全整数）、`closed_after_task_id`（非负安全整数）、`idempotent_replay:true`。
- 原记录不存在为 `SAND_AI_RESOURCE_NOT_FOUND`；原执行者证明缺失或不唯一为 `SAND_AI_INVOCATION_FACTS_UNVERIFIED`。拒绝、无记录、网络错误均不能证明未提交，客户端保留原号且不再次 POST。
- 页面仅可凭同一页尚未开始 POST 的内存标记，在原号存储读回后首次提交原关闭；标记在首次 POST 前消耗。刷新、身份/令牌/作用域变化、GET 404 不能重建标记。当前身份失效清私有内容，迟到响应不能清新作用域记录。

此增量仅 SOURCE，未执行接口、运行或验收。关闭与找回均不产生模型调用或新增费用；历史和费用仍分别经其正式当前授权读取。

### 账单管理凭据保存原号恢复（原 4.1）

当前本地后继候选增加 `openrouter_generation` 请求费用驱动，原 OpenAI 组织 Costs 仍为汇总驱动，不自动按请求核对。Provider 的实际 `openai_compatible` 配置指向官方 `https://openrouter.ai/api/v1` 才选择此驱动；不依赖本地 Provider code 或放宽其他厂商地址。`GET /app/sand-ai/admin/billing/credential/status?provider_id=` 在原 `{provider_id,configured}` 上增加 `driver:'openai_organization_costs'|'openrouter_generation'`，只读本地配置，不请求厂商、不返回密钥或账户。OpenRouter 使用原模型连接 Key，不接受该 Provider 的专用账单凭据保存；历史凭据保存原号 GET 与安全收据保持。

`POST /app/sand-ai/admin/billing/sync` 输入、权限、UTC 半开账期与原号恢复保持。OpenRouter 仅查账期内已经耐久观测的 `body.id/response_id`，拒绝用户指定任意 generation ID。每个 ID 必须唯一绑定原 Invocation/Dispatch/UsageEvent、完整 IAM 四级归属、模型/部署、attempt、原请求正文 SHA 和执行摘要；原不可变部署修订的执行配置 HMAC 与当前原连接一致，加密配置摘要在查询前、每次请求及提交前再次核对。连接或 Key 改变时拒绝，不用新 Key 猜旧调用。正式运行组合根选择真实 parser；手工导入仍不能自报内部身份或请求证明。

此驱动先以同一连接读取官方 `/api/v1/key`，明确 `organization_id` 非空时采用组织身份；仅明确为 null 才可采用非空 `creator_user_id` 的个人身份。缺字段拒绝，不用 label、Provider ID 或管理员输入证明账户。`workspace_id` 必须明确且与 generation 返回值相同。可信事实 namespace 为 `openrouter_generation_v1`，稳定键为唯一 `data.id`，账户范围为上述身份与 workspace 的摘要；可空 `request_id` 只是请求分组，不作稳定键。来源证明随 `external_line_id` 绑定，不受账单行排序影响。

当前只支持非 BYOK、已完成的纯文本 completions：`native_tokens_prompt/completion` 必须为非负整数，null 不补零，不用其他 Token 字段兜底，也不额外加 reasoning/cached 分项；媒体、音频、搜索或抓取计数缺失、非零或未知均拒绝。`data.model` 必须等于原远端模型。`total_cost` 沿官方协议采用 USD，不创造响应不存在的 currency 字段，不采用 upstream cost。JSON 数字按原字面值无损解析，超过账本六位有效小数精度拒绝，不能四舍五入造一致。官方记录创建时间须属于原查询账期。

唯一原事件仍须实际 measured/priced，原输入/输出 native 量、总量、币种及冻结金额全部一致才写 `reconciled`；不一致保留 `discrepancy`，未知计量/价格保留 `unmatched`。返回的账单 DTO 不暴露完整原始响应、Key、账户或内部请求证明。厂商费用元数据不是退款凭证：退款仍要求原 `reconciled_bill`、同 Provider 的私有凭证及管理员明确审核，`operator_reviewed` 不冒充厂商退款认证。受控 synthetic 传输可验证此正式 parser/domain 的行为，不能证明真实账单准确或实际现金退款；该本地候选尚未公开发布。

- 作用域仍是全局 `provider_id`，不是环境配置；现有官方账单 Provider 身份与服务地址限制、组织标识不可变规则保持。两端点均须当前正常管理员会话及 `sand_ai:billing:credential` 权限，不接受客户端 actor/env。
- `POST /app/sand-ai/admin/billing/credential/save` 的 body 仍仅 `{provider_id,billing_admin_key,billing_organization_id}`，新增可选 `Idempotency-Key` header（`[A-Za-z0-9._:-]{1,128}`）。旧无 key 请求仍可保存，但不生成可找回的原号证明。响应保留 `{provider_id:number,configured:true,updated_at:string}`；`updated_at` 格式为 `YYYY-MM-DD HH:mm:ss`。此旧响应 shape 本身不是完整的原请求证明。
- `GET /app/sand-ai/admin/billing/credential/by-request-key` query 仅 `{provider_id:正整数,request_key:原不透明请求号}`，不要求 header/body，不接受组织标识、凭据、环境、摘要或 HMAC。
- DTO：`{operation:'credential_save',actor_id:number|null,provider_id:number,request_key:string,state:'unknown'|'completed',credential:{provider_id:number,configured:true,updated_at:string}|null}`。
- `completed` 仅来自同管理员、同 Provider、同原 key/action 的不可变安全保存快照，`actor_id` 为原管理员、`credential.provider_id` 为原目标，时间与原 keyed POST 完成快照一致；它证明当时保存，不能代替另一次 `/credential/status` 对当前配置的读取。
- 没有原记录（含旧无 key 保存）、权限/身份变化、404、网络或读取失败均不能证明未保存。DTO `unknown` 时 `actor_id:null,credential:null`，保持原操作锁，只允许原请求号只读找回，不以当前 `configured:true` 推断原成功，不重新 POST、换号或放弃未知意图。
- 新 keyed 保存将加密 Provider 配置与安全原快照写入同一事务；同 actor/provider/key 的锁及内部既有 keyring HMAC 防止相同原号变更输入，碰撞返回 `SAND_AI_IDEMPOTENCY_CONFLICT`。旧 audit 不回填、不补历史 proof；缺签名材料/不完整 proof 返回 `SAND_AI_IDEMPOTENCY_KEY_UNAVAILABLE`，仍保未知。
- Key 与组织标识由现请求保护 middleware 在认证前移入请求生命周期内的私有存储，普通 post/raw body 不保留其原值，finally 清理；该路由不接 `SystemLog`。凭据只沿现 Cipher 加密保存，不新增密钥。原号 audit 仅保安全 snapshot 与内部证明；公共 audit DTO 过滤 HMAC/锁/签名元数据，不返回 Key、组织标识、原配置或 envelope。
- 前端持久 journal 仅 `version/admin_id/provider_id/request_key`。发送前清理秘密输入；正常旧 shape 响应后读取原回执，只有严格匹配的 completed 释放原锁。身份/token 变更清私有输入和迟到响应，真实 POST flight 到自身 finally 才释放，不删除未知 journal。

### 法律专业配置原操作回执（原 6.2）

- 现正式路径 `POST /app/sand-ai/admin/setup/legal-analysis/profiles/draft/save|publish|revoke` 的 body 保持不变，新增可选 `Idempotency-Key`（`[A-Za-z0-9._:-]{1,128}`）。旧无 key 调用仍兼容，不伪造历史原号证明；响应仍 `{profile:LegalProfile}`。
- 原保存为 `operation:'draft_save'`，原发布为 `'publish'`，原撤销为 `'revoke'`。`GET /app/sand-ai/admin/setup/legal-analysis/profiles/operations/by-request-key` query 仅 `operation,environment_id,code,version,request_key,expected_revision?`；不接受正文。save 的 `expected_revision` 沿原 CAS（创建 null 省略、编辑原修订），publish 必须为原发布修订，revoke 必须 null 省略。
- GET 直接 DTO（不包一层）：`{operation,actor_id:number|null,environment_id:number,code:LegalMode,version:string,request_key:string,expected_revision:number|null,state:'unknown'|'completed',receipt:LegalProfile|null}`。
- `completed` 仅原管理员、环境、动作、code/version、请求号、CAS 修订与内部原 body HMAC 关联的不可变原快照。draft_save 回执为 `draft/status2/revision=(expected_revision??0)+1` 并含正式 config；publish 为 `published/status1/revision=expected_revision`；revoke 为 `revoked/status2`、原发布版本修订。回执保安全字段 id/environment_id/code/version/name/revision/state/status，save 另有经过正式 Registry 的 config。无 Key、HMAC、原请求正文、私有厂商响应或 envelope。
- `unknown` 必须 `actor_id:null,receipt:null`，不证明未提交或失败。旧记录缺 marker/proof、404、拒读、网络与原目标不符均保原意图，不重 POST、换号或放弃。当前 profile/read/index 仅当前状态，不能冒原 receipt；原 completed 后列表另行读当前状态。
- 当前 `CheckLogin/CheckAuth`、正式管理员来源、环境引用保持；GET 需要 `sand_ai:setup:legal-profile:read`，并重验原动作对应 save/publish/revoke 当前权限。写与原安全快照同一事务、同环境锁、同 actor/env/action/key 锁；同原号不同目标/body 拒绝 `SAND_AI_IDEMPOTENCY_CONFLICT`。现 keyring HMAC 内部复用，不新增密钥；缺证明材料 `SAND_AI_IDEMPOTENCY_KEY_UNAVAILABLE`，不补历史。
- 新 audit marker `legal_profile_operation@1`，149 只为 admin/resource legal_analysis_profile/原 draft/publish/revoke actions 新 marker 子集加 partial unique 与 immutable guard；旧 audit 不回填、不拦。公共 Audit DTO 仅 code/version/revision 元数据，原安全快照仅正式原 GET 返回。
- 编辑器仅管理草稿；面板负责精确修订发布、已发布版本撤销及试用入口。journal 仅身份/原环境/模式/版本/原 CAS/id/opaque 请求号，不存正文或 token。身份/token/目标变化清私有 DTO 和迟到回复；实际 POST flight 不被 GET/epoch提前释放。存储失败且本页确证未发送的恢复只清本页未发送记录；该本页证明不跨刷新或身份继承。
- 法源私有快照、三模式/后继模式输出引用与人工复核、应用身份样本、全文和固定版本运行沿现 LegalAnalysisTrial/LegalSourceGateway/LegalAnalysisExecutor 链，本批不改其输入或 SDK。

### 预算固定政策发布（原4.3 SOURCE增量）

`POST /app/sand-ai/admin/budget/policy/publish` 保持原 `id`、可选 `reference_environment_id` 与旧请求body兼容；新增可选 `expected_policy`。省略时沿既有行为。

传入对象必须恰含以下11个现公开业务字段，不接受其它字段：

```typescript
{
  revision: number,
  scope_kind: BudgetScopeKind,
  scope_ref: string,
  environment_id: number | null,
  period_start: string,
  period_end: string,
  currency: string,
  limit_amount: string,
  enforcement: 'hard' | 'soft',
  unpriced_policy: 'block' | 'allow',
  unmeasured_policy: 'hold' | 'allow'
}
```

`scope_kind` 沿既有 `BudgetScopeKind`；金额按既有20,6十进制规范，时间按既有UTC `Y-m-d H:i:s`，nullable环境按真实scope规范化。服务在原政策锁内将完整对象与实际目标业务事实比较；不匹配沿已有 `SAND_AI_BUDGET_CONFIGURATION_CONFLICT`，非法shape沿已有 `SAND_AI_BUDGET_VALIDATION_ERROR`。

此增量不新增GET、表、回执或预算运行组。现有GET仅提供当前配置事实，不能作为原不可变操作收据。仅SOURCE合同，未执行检查、测试或运行。

### 管理端只读找回原知识草稿

`GET /app/sand-ai/admin/knowledge-bases/create-draft/by-request-key`

沿用当前后台管理员认证与 `sand_ai:knowledge:create` 权限。仅 query：
- `environment_id`：原环境正整数。
- `request_key`：原创建草稿 Idempotency-Key，不换号。
- `request_fingerprint`：原 POST 去除 environment_id 后，按 KnowledgeBasePolicy::normalizeDraft/fingerprint 得到的64位小写 SHA256；不是文件摘要或当前列表摘要。normalize 将 code 小写/trim、name/description trim（空 description 为 null）、files 按数值id升序；false replace_draft 不进入指纹，true 保留；递归键排序、JSON_UNESCAPED_UNICODE/JSON_UNESCAPED_SLASHES，U2028/U2029仍转义。

无请求正文，不在 URL 传草稿正文、来源正文或凭据；返回 Cache-Control: no-store。

成功 DTO：
```
{
  operation: 'create_draft', environment_id: number,
  request_key: string, request_fingerprint: string, actor_ref: string,
  knowledge_base_id: number, version_id: number, idempotent_replay: true,
  current: KnowledgePublishResult
}
```
顶层是原 operation 与固定 base/version 的关联证明：同环境、原号、原输入指纹，及原 knowledge_base.draft audit 中唯一同 base/version/source_count 的管理员；不从当前列表推原成功、不补历史 actor。current 是该固定版本当前安全元数据，沿既有 KnowledgePublishResult 字段（knowledge_base/id/code/name/state、version/id/version/state/snapshot_sha256/source_count、idempotent_replay:true），不是原响应不可变副本、不是当前活动版本。

读取前后重验环境/来源所有者和快照；原 version 已清理、无原记录/审计证明、原 actor 不匹配，SAND_AI_FACTS_UNVERIFIED 404。原指纹不符 SAND_AI_IDEMPOTENCY_CONFLICT；参数错误 SAND_AI_KNOWLEDGE_VALIDATION_ERROR；当前来源失效沿既有 SAND_AI_KNOWLEDGE_SOURCE_DRIFTED/来源范围错误。权限、来源拒绝、404、网络失败全部保留原号，不证明未发送，不再调用创建 POST。

只首次提交保持原 POST 契约及旧指纹；在本页明确尚未调用 POST 且原号保存读回失败时，允许保同原正文/号的仅内存未发送标记，用户明确继续并读回原 journal 后首次发送。刷新/身份环境变化销毁该标记，只读恢复。

知识草稿发布仍要求正式当前来源 ready 与固定快照；向量构建沿发布后的固定 KB version 配置，正式 embedding-index/by-request-key/status GET 找回原构建，只有 building 可推进。页面请求占用保持到原 POST 真正结束；身份/环境变化清私有 DTO 和原闭包并忽略旧响应，不提前解除网络提交占用。

## 发布能力档与默认聊天绑定的确认修订（3.1／3.2）

- `POST /app/sand-ai/admin/setup/capability/profile/publish` 正文仍为 `{environment_id,id}`，兼容新增可选 `expected_revision`；`POST /app/sand-ai/admin/setup/onboarding/bind-default-chat` 正文仍为 `{environment_id,deployment_id}`，兼容新增同名字段。提供时必须是正整数，不接受 null、字符串、布尔或零；未提供的旧调用保持既有语义。当前管理页面必须发送确认时读取的原能力档／部署修订。返回 DTO、权限、错误码与现 GET 路径不变。
- 能力档发布在现环境锁与 profile 行锁内核对真实 `profile.revision`；默认聊天绑定复用原 provider → model → deployment → revision 锁序，在锁内核对部署及原修订均为 `expected_revision`、当前确已 published 且启用。修订变化或已不再发布以既有 `SAND_AI_RESOURCE_CONFLICT` 拒绝，不发布或绑定另一新修订；不新增迁移、请求回执、价格或权限基础。
- 发布前展示固定部署／能力档 ID、修订、验证状态或草稿状态、模型／模板与实际配置、目标应用和环境。能力档表单存在未保存修改时先保存草稿。确认前持有真实操作锁；确认后以及后续只读准备完成后核对同一身份、环境与页面快照，变化则取消本次写入。默认聊天绑定还展示当前环境能力档及路线，并在确认后重新读取原部署和原环境能力档；不覆盖不同的已发布配置。
- 原安全意图仍在发送前持久保留，不存服务商 Key、Bearer 或原始响应。身份变化清当前私有 DTO 并隔离迟到响应，真实操作锁仅原所属 finally 释放。未知结果继续原 GET-only 恢复；固定修订／当前绑定匹配只证明当前配置事实，不是服务器原操作回执，404、拒读或网络失败不证明未提交，不换目标重发。


### 本地 OCR 正式部署、请求价格与预算（原 3.4／4.3／4.4 SOURCE 候选）

本地 OCR 部署 save、原保存键找回与指定修订 read 的 wire `deployment.config` 可为 `{}` 或 PHP 空数组投影 `[]`；OCR 专用解码器仅将合法空 `[]` 规范为无配置的 `{}`，拒绝非空数组及非空对象，其他身份、修订与验证归属约束不变，服务端不可变证据的原 `evidence_sha256` 保持原值，不由客户端重新计算。

沿现 `POST /app/sand-ai/admin/setup/deployment/save` 创建真实本地 OCR：`provider.adapter="local_ocr_runtime"`、不提交 connection；`model.type="image_ocr"`、`capabilities=["image_ocr"]`；`remote_model="local_paddle_ocr"`、`config={}`。复用既有 provider_id/model_id/deployment_id、原保存键、verify/publish 和修订恢复。连接仅由宿主既有 `sand_ai_ocr.local_paddle_ocr` 配置提供，不接收本机路径、远端连接或 Key。私有 HMAC 冻结已校验的 loopback 地址及发送边界配置；保存、验证、发布及正式发送时漂移均拒绝。verify 使用固定摘要的包内非敏感 PNG，真实 OCR 返回非空且定位合法的块才成为 tested；发送不明保持既有 verification_ambiguous，无自动 POST 重发。验证事实为平台管理员范围，不构造 workload 身份或现金对账。

既有 image_ocr parse_driver 路线允许 `driver_code="local_paddle_ocr"` 加 `deployment_id=<已发布OCR部署>`，config 保持空对象；不改变其他解析器。历史未绑定部署路线仅保持未计价链，任何适用硬预算仍拒绝。正式绑定通过当前模型、Provider、部署修订、处理策略及私有配置重验，不可借音频或云模型身份记 OCR 费用。

沿现价格草稿／发布入口，`capability_code="image_ocr"` 仅接受 `rates=[{meter_code:"external_request",unit_price:<非负decimal(20,8)字符串>,per_quantity:<正整数>}]`，绑定同一真实 OCR provider_id/model_id。人工明确发布零价只表示本地 sidecar 请求的服务费为零；不含 CPU、机器成本，不表示实际现金已对账。未配置有效价格保持 unpriced、金额/currency NULL；云 OCR 不继承本地零价。

FileParseGateway 和 OcrUsageLedger 在原 Curl 边界内再次锁定当前能力档、route、部署与私有配置、当前价格和适用预算／窗口，重验原排队 SandIAM 授权及 task/parse/attempt/lease。每次已授权发送尝试严格计 `external_request=1`，不按块数推导页数或 token。将不可变执行目标及 call-start 价格冻结于原 OCR dispatch，再绑定原 usage_event；按配置服务费计算的 metered_amount 与现金 reconciled_amount 分开，后者保持 NULL/unreconciled。预算沿原 reservation/window/event 增加 OCR owner，完整 HTTP 结果结算，超时、截断或不明结果保留 held；无结果的崩溃尝试保留预占，过期转 held，不释放或自动重发。零价仍有最小 0.000001 预占身份，完整结果按真实配置金额零结算。

增量迁移仅新增正式 OCR 目标、价格绑定及现预算账本 owner 约束，保留旧 migration 字节和历史未计价记录。缺所需 schema／trigger 返回既有 SAND_AI_SCHEMA_UNSUPPORTED；目标或价格漂移沿现 SAND_AI_CONFIGURATION_DRIFTED／SAND_AI_PRICING_SNAPSHOT_INVALID；预算与不明发送错误码保持。源码交付、迁移执行、正常配置和真实业务验收分别记录。


### 本地 OCR 首次配置候选目录（API 1.14 读取增量）

沿现 `GET /app/sand-ai/admin/setup/deployment/index` 与 `sand_ai:setup:deployment:read` 权限，新增可选查询字段 `selection_scope`。省略或显式 `published_routes` 保持原语义：环境查询仅列该环境当前已发布能力档已经绑定的部署；无原路线仍返回空分页。

唯一新增值 `ocr_route_configuration` 必须同时传 `model_type=image_ocr`、`capability_code=image_ocr`、有效 `environment_id`；其他值、空值、非字符串、缺少任一字段或其他能力/模型类型组合均返回 `SAND_AI_VALIDATION_ERROR`。`page,limit,name` 原规范、路径和分页 DTO 不变。

该范围仅为管理端配置 `image_ocr + parse_driver + local_paddle_ocr` 路线选择候选。先复用当前 SandIAM 环境引用验证和管理员目录权限，再要求同环境存在 active/published 的当前能力档及其 active/published 配置修订，使用该档已发布的 `data_egress_policy`。无可用环境能力档时返回空分页，不推定环境策略。候选不要求已经绑定 OCR 路线，必须为 active/未软删的真实 `local_ocr_runtime` Provider、`image_ocr` 且能力恰为 `["image_ocr"]` 的 Model、`local_paddle_ocr` 且空 config 的 Deployment；当前 ConfigRevision 必须 active/published，具有当前同部署/修订的成功验证、冻结处理策略和匹配的发布执行快照，且处理策略允许该环境外发策略。漂移、未验证、未发布、停用或不相容目标不进入候选。

返回原安全目录 DTO；候选 `capabilities=["image_ocr"]` 表示配置适配能力，不表示该环境已绑定或可运行。`source.environment="published_capability_profile_configuration"`，`egress_policy` 仍来自同环境当前已发布能力档，`source.deployment="published_configuration"`。不返回端点、密钥、本机路径、对象 key、配置 JSON 或原验证厂商响应。

前端仅本地 OCR 路线搜索传该范围，按业务名称选取返回的部署 ID，不手填 ID；其他能力与未指定范围的调用保持不变。目录读取不保存/发布/绑定，不产生 OCR 请求、预算、费用或审计写入，也不授权实际运行。后续能力档 save/publish 和实际 OCR 发送仍各自在原入口重新校验当前环境、身份/授权、路线、驱动、部署发布快照、外发策略及价格/预算。原部署、修订和价格不因候选读取改变。


### 本地 OCR 原任务发送前耗尽恢复（原 tasks/retry 增量）

`POST /api/sand-ai/v1/tasks/{taskId}/retry` 新增严格目标 `file_parse_before_dispatch`，body 恰为 `{target:"file_parse_before_dispatch",expected_attempt_count:<原 TaskSummary 非负整数>,expected_state:"failed"}`，必带独立稳定 `Idempotency-Key`。不接受 mode、其他字段或缺失/非法预期字段；返回 `SAND_AI_VALIDATION_ERROR`，缺少合法 key 返回 `SAND_AI_IDEMPOTENCY_KEY_REQUIRED`。key 沿既有派生方式绑定当前可信作用域及原 task ID，独立域为 `ocr_parse_retry`。普通 retry、其他 target、authorization_continue 和 receipt_only 不变。

仅原 `file_parse/file` 任务为 active、未软删、未清理的 failed 状态且 `attempt_count=max_attempts` 时可使用；原 file/parse 所有权与同环境、输入/任务/file_parse_id 对应关系一致，原 parse 仍是该文件最新 failed 记录，parser 固定 `local_paddle_ocr/http-sidecar`，私有原文件可用且未成为已发布知识库来源，不存在原来源块/成功成果。任务行锁及租约栅栏内确认同 task 或 parse 不存在任何不可变 OCR dispatch；任何已有 known、unknown、未完整记录结果的派发都拒绝该目标。无可证当前 OCR schema、来源、授权或控制元数据时拒绝，不把一般失败/取消或单次空查询推成未外发。

发送前证明沿正式 worker/file_parse 路径：任务 claim、解析准备各完成事务后才进入 driver；ledger.start 单独事务提交 dispatch/预算/usage 后返回，driver 才执行 Curl。SOURCE 同时强制该 start 的现 PDO 连接在事务前后均不处于其他事务；嵌套或无法证明提交时 `SAND_AI_OCR_USAGE_UNAVAILABLE`，拒绝外发。已有 dispatch 不因后续 outcome/来源持久化失败回滚，dispatch 历史不可删除。

沿现 `sand_ai.task.retry` 与 `sand_ai.document_parse` 双当前授权/可信 scope 核验，锁内再次核对新授权，不伪造或延长旧 TTL。成功恢复只将原任务/步骤/parse 入队，保留原 file、parse、task、首次解析 request key 和历史 attempt_count；`max_attempts` 仅一次由原值加 1（int32 溢出拒绝）。永久一次恢复收据保存于现 `TaskStep.context` 的独立控制字段，不修改原文件/解析目标、usage_attribution、来源快照、模型/价格冻结事实或 HMAC；重新排队仅沿原机制更新当前 `iam_authorization`。原 `task.retry_queued` 审计保留先前代次、机会与目标，另记录同收据非秘密指纹。再次使用不同 key 或 body 返回 `SAND_AI_IDEMPOTENCY_CONFLICT`；同 key/body 只回当前原 TaskSummary，不第二次入队、不增加机会，失败后也不能反复增加。前置条件、来源、已派发、已消费或状态不符使用 `SAND_AI_RESOURCE_CONFLICT`。

原 TaskSummary 新增 `ocr_before_dispatch_retry?: {target:"file_parse_before_dispatch",available:boolean,consumed:boolean,previous_attempt_count:number|null,retry_attempt_limit:number|null}|null`。只有完整服务端本地 OCR 条件可证且尚未使用时 available=true，previous_attempt_count 为原真实代次，retry_attempt_limit 为唯一下一上限；已接受收据 consumed=true/available=false；其余 null，不展示恢复操作。投影不返回 key、私有路径或授权材料。

原任务页仅该投影 available=true 时显示明确一次恢复操作，先读原任务、固定预期、保存原 task/parse/file/代次和新 key 的非秘密意图，再发送一次 POST。请求不明、刷新及离页仅 GET 原 task ID；服务端 consumed 收据和同原目标/代次用于确认是否接受，不自动重发 POST。服务器不是未派发或来源不可用时不能从页面恢复；普通任务重试入口不放宽。原首次 key GET 始终仍找回同 task/parse；原失败与审计证据保留。本增量 SOURCE 不宣称图片解析或真实恢复已通过。

### 会议单节点来源引用纠正（原任务续行）

`GET /api/sand-ai/v1/tasks/{id}` 的会议任务详情可返回 `meeting_output_correction`：

- `available: boolean`，`reason: duplicate_sources | missing_sources | not_available`。
- 可用时附安全字段路径 `reason_path`（例如 `unresolved[4].segment_ids`，索引从零开始，不包含观点正文）、`node_code`、`node_kind: chunk | merge`、`expected_node_attempt`、`expected_attempt_count`、`failure_code: SAND_AI_AUDIO_SUMMARY_RESULT_INVALID`。
- 服务端仅在原任务失败、未清理、原节点完整 JSON 合法，且仅存在受支持的来源错误：`duplicate_sources` 为属于原来源的重复引用；`missing_sources` 为观点对象只有合法 `text`、缺少必填 `segment_ids`，其它结构、五类冻结数量上限及所有现有引用必须合法且无重复。原已完成调用回执与计量仍可核验、原任务／节点尝试未耗尽时返回可用。不根据错误码或费用行猜测可用性。

用户明确选择纠正后，用正常 `Idempotency-Key` 向 `POST /api/sand-ai/v1/tasks/{id}/retry` 提交且仅提交：

```json
{
  "mode": "meeting_output_correction",
  "node_code": "chunk_00002",
  "expected_node_attempt": 1,
  "expected_attempt_count": 1,
  "expected_state": "failed"
}
```

沿用 `sand_ai.task.retry`、原 `sand_ai.chat.complete`、原音频资源授权及当前身份范围。服务端先验证原来源、保留回执和失败尝试，再原子受理纠正；同键同五字段重放读取已受理原任务，同键不同节点或尝试返回幂等冲突。响应未知时保留此五字段与原键，显式找回，不改用新键。

纠正保持同一任务、来源分区、配置、schema 和 4096 输出额度。只对当前首个失败节点产生独立新 attempt／invocation；原失败输出、调用及费用保留，已成功节点不重发，成功后继续原规划的其余节点及合并。纠正会产生模型费用，须遵守当前预算和授权；普通重试和回执恢复不隐式生成纠正。节点最多三次尝试且不扩大原任务 max_attempts。未知／进行中调用、已清理或过期回执、其他未支持的输出错误不可使用此入口；不会自动去重、补造来源、丢弃无来源观点或把原无效成果改成成功。缺来源诊断只指出安全字段路径，成果仍失败；新纠正输出仍须通过完整原冻结契约。

#### 已受理会议纠正意图的领取与恢复

Worker 对同一父步骤中已受理、尚无新 attempt 派发的纠正意图作一次原子领取；领取标记与父步骤、任务租约一起提交。领取继续核对当前 SandIAM、原来源、原失败回执内容摘要和首个未完成节点。任务输入及规划锚不变，不重置或扩大原 `max_attempts`、`local_recovery_count`、`local_recovery_max`。首次领取记录新的任务执行次数；已领取意图仅允许一次过期租约恢复，该恢复保留任务执行次数，只增加租约 fence 与意图恢复次数。节点独立新 attempt 仍使用正式调用身份、预算预占及计量。

崩溃发生在节点领取后、创建调用前时，恢复沿已领取节点 attempt 创建其唯一调用；已有 prepared 调用继续原身份与原预占，已发送或未知调用只能恢复原回执，不重新发送。此次意图恢复再次中断后停止自动领取并保留记录。原成功节点及原失败调用不变；纠正完成后沿原计划继续余下节点。使用既有五字段请求与同一幂等键找回受理结果，无新增前端操作或授权入口；服务端自动领取不把 `meeting_output_correction.available` 变为新一次纠正许可。



### 材料预算准入停止与原任务恢复（原 3.4／4.3 SOURCE）

材料分块或跨文件批次收到 `SAND_AI_BUDGET_EXCEEDED` 时，只有原持久 `PreDispatchRejection` 的预算准入阶段、可信调用范围、请求指纹、原 task／step／node／attempt 和失败 invocation 完全相符，且不存在任何 dispatch（包括 prepared）、预算预占、用量或结果回执，才记为未发送的预算失败。节点证据留在 TaskStep 私有 context；Task／Run／file DTO 不返回该证据、内部请求键或指纹。普通未分块文件沿原任务失败处理。

用户明确恢复原任务时，复用 `POST /api/sand-ai/v1/tasks/{taskId}/retry` 的 `target=cross_file_review` 或 `target=failed_files`；须携带原 `expected_attempt_count`、`expected_state` 和该目标原代次／精确文件集合。仅上述原证据及当前来源、授权均可重证时，旧误记 `recovery_pending` 的预算节点可在同一任务事务内修正为已确认失败并沿既有重试入队；未发送无法证明或代次已变则拒绝。已经正确记录的预算失败也须明确确认原代次。响应仍为原 TaskSummary，不新增恢复接口或 run。

原提交幂等键、task、run、已封存成功批、旧拒绝记录及收费事实保持；明确重试后只有失败节点的新 attempt 按现规则派生子调用键，旧被拒子键仍重放原拒绝。此操作不增加预算、不释放未知费用、不自动重发，不清空失败次数；每次派发仍经过正式预算硬门。任何 provider-visible、未知或未结 prepared 派发保留原请求及回执恢复边界，不能用补预算替代结果确认。原提交键重放只找回原运行，不能代替明确任务重试。

本段是候选源码操作合同，未运行、测试、编译或部署。


### 人工账单原件与跨批次重导入（本地后继候选）

`POST /app/sand-ai/admin/billing/import` 追加可选正整数 `evidence_id`。它只能引用同一服务商、当前 available 且实际私有正文 SHA-256 与字节数一致的原件。提供该字段时，每行必须提供 `evidence_locator`；未提供原件时不得发送该字段。定位是管理员声明的原件位置，例如“用量明细!第2行”，不是系统解析或厂商认证结果。定位须为有效 UTF-8，去除两端 ASCII 空白后为 1–256 个字符，不含 Unicode 控制字符；同批规范化定位不得重复。大小写、内部空白及 Unicode 字符保持原样，不猜测不同写法代表同一行。

服务器以 `manual_evidence_v1`、服务商、实际原件 SHA-256 和规范化定位构成持久来源身份。原件 ID 仅用于访问检查；同字节原件不会因不同上传记录产生新事实。来源按固定顺序加事务锁，唯一约束兜底。同来源的规范化模型、能力、计量类型、数量、金额、币种与 UTC 时间窗完全相同，复用原账单行及其原对账/人工审核，只增加本批关联；新批幂等键、reference、external_line_id 不改变来源内容身份。同来源内容不同返回 `SAND_AI_BILLING_FACT_CONFLICT`，整批回滚，不改写原行、原审核或历史费用。批次原请求回放与跨批事实复用分别处理。

没有原件绑定的旧 JSON 导入仍不具跨批事实去重依据。绑定原件的手工行仍为 `identity_unverified`、`unmatched/identity_unverified`，不得提升成厂商 verified、自动 reconciliation、现金已核或退款依据。人工 `request/aggregate` 审核仍沿原独立权限、粒度与账户/事件检查；汇总用量和 Tokens 包扣量不能拆成未经证明的单请求现金。

`billing/read` 每行追加 `source_evidence:null|{evidence_id:int,sha256:string,evidence_locator:string,locator_basis:'operator_declared'}`。重复时该对象来自原账单行的 canonical 来源绑定，evidence_id 可不同于本批输入。它不含存储位置、原件正文或厂商凭据。原件清除与新导入争用同一行锁；清除后新批拒绝，已完成原请求仍可只读恢复，不伪造原件可用。不可变来源关系由迁移 `202610120155_billing_line_evidence` 保存，旧事实不回填。

私有账单附件支持 CSV、PDF、非宏 XLSX，仍限 1 字节至 10 MiB。XLSX 保存用户提供的原始字节及摘要，规范 MIME 为 `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`；ZIP 结构、条目数、展开大小、路径、加密与宏限制不合要求时拒绝。`.xls`/`.xlsm` 不接受，不自动转换或解析账单，不执行公式、宏或外链。上传、未知结果恢复、下载和清除沿原 evidence 生命周期及权限；原文件名和工作表/行定位由管理员在实际原件上核查。源码和固定本地包交付不代表迁移、正常消费或真实账单对账验收通过。

XLSX原件的持久保存另包含迁移 `202610120156_billing_evidence_xlsx`：仅扩展原附件media_type长度并增加官方XLSX MIME，保留CSV/PDF白名单及旧行、不改附件生命周期或信任依据。096与155原迁移及摘要保持。


### 账单导入同键异内容的明确拒绝

正式 `POST /app/sand-ai/admin/billing/import` 对同一原幂等键的不同内容返回HTTP 409、响应 `code:409,data:{error_code:'SAND_AI_BILLING_IDEMPOTENCY_CONFLICT',retryable:false}`。这是既有domain明确拒绝的结构化投影，不是未知服务器错误，也不重新处理或改写原账单。事实来源冲突仍使用独立 `SAND_AI_BILLING_FACT_CONFLICT`；其他现有错误分类保持。消费端沿原409冲突处理与原请求恢复，保留原key/body及原账单/审核，不能据此替换原请求或自动重发。


### 专用调用方的共享材料模型次数窗口（157源码候选）

宿主操作员可为尚无模型调用记录的专用SandIAM workload client建立一次固定窗口。两端材料批次、逐文件、leaf/merge、crossreview和显式retry共用同池；同一InvocationDispatch的其他模型路径也计入。该门只覆盖统一模型派发账本，不替代IAM动作授权或金额预算，也不声明覆盖独立OCR、外部ASR及工具账本。本次真实窗口沿原专用caller及八项材料动作，最多6次，hard 1 CNY同时生效。

归属是SandAI派发治理，不是新的IAM授权。新增sand_ai_provider_call_limit_pool（id、不可变code/max_calls/start_time/expire_time、state）、sand_ai_provider_call_limit_binding（id、pool_id与可信organization_id/application_id/environment_id/workload_client_id）和sand_ai_provider_call_limit_claim（id、pool_id、全局唯一dispatch_id）。只新增迁移202610130157_provider_call_limit，沿正常安装/升级载荷；不新建HTTP路由、权限代码或客户端字段。

宿主CLI仅在明确授权后使用：

```text
php webman sand-ai:provider-call-limit create --code <本次不可复用窗口名> --organization-id <IAM组织ID> --application-id <IAM应用ID> --environment-id <IAM环境ID> --workload-client-id <专用调用方ID> --max-calls 6 --expires-at <两小时内的UTC截止时间>
php webman sand-ai:provider-call-limit status --code <原窗口名>
php webman sand-ai:provider-call-limit close --code <原窗口名>
```

截止格式为YYYY-MM-DDTHH:MM:SSZ；ID由操作员从当前正式IAM记录确认，客户端不能指定或覆盖。初始化不签发凭据、创建授权、修改模型或金额预算，应在首个模型请求及临时凭据交接前完成。status只读显示状态、已领取/上限及UTC截止；close永久关闭，保留绑定与计数，不恢复无限调用。create原参数重复只复用既有绑定，改参数、换池、续期、旧code或有模型调用历史的首次绑定均拒绝。此入口仅供专用短时窗口，未实现周期自动重置或重新分配既有caller。

领取与prepared到dispatching在同一PostgreSQL事务内；模型发送前核对事务已提交。按caller先找到绑定，再核四级归属，错误scope不会变成未绑定放行；锁定同池后按不可变claim总数计数，第7次在网络前拒绝。原号恢复沿既有回执/结果读取，不重复扣次，也不因找到claim获得二次发送资格。历史调用只因其真实claim归池，旧未知记录不会自动加入新池。

保守次数口径是“已提交领取数”：发送失败计数保留；明确not_sent/not_dispatched也不退已领取次数。未领取的前置拒绝不计次；事务回滚撤回同事务领取且不能发送。存在dispatching时拒绝新领取，恢复原请求后才能继续；ambiguous在同一终结事务将池永久置为blocked，后续结果修复不会自动解冻。金额未知保持由原预算链独立处理。剩余次数不承诺完整文件集一定能在6次内处理，不能删减来源或绕开chunk/merge以凑次数。

到期、关闭、冻结或池异常均失败关闭；数据库外键及不可改删绑定保留受限标记，禁止删除绑定、改池或将其改作未绑定caller。原未绑定caller沿原行为。错误码包括SAND_AI_PROVIDER_CALL_LIMIT_SCOPE_INVALID/CLOSED/ALREADY_CLAIMED/PENDING/EXHAUSTED，返回既有业务错误格式；次数拒绝按正式派发拒绝路径收束not_dispatched并释放未发送的金额预占，调用方应读取原任务/错误，不自动换号重发。

当前仅SOURCE候选：迁移、CLI初始化、宿主加载及并发/恢复均未运行，不能称次数门已生效。旧SOURCE或数据库不完整时不得打开真实模型窗口。安装和首次使用待获得完整宿主操作授权后，以0外联受控运输验证，再恢复真实调用。


### 已领取后发送边界拒绝的持久终态

迁移202610130158_claimed_unsent_terminal增量修正派发状态转换，旧026与157摘要不变。原同一活跃ProviderSendBoundary证明发送前拒绝时，正式终结事务可从dispatching收束not_dispatched、将invocation置failed并释放对应金额预占；已有领取次数不退。数据库同时拒绝存在用量或厂商响应标识的该转换。材料分块读取及异常收束只认同身份、尝试号、执行快照一致的已持久not_dispatched终态，节点记为failed并由既有失败文件重试入口显式创建下一尝试；已完成节点保持。dispatching/ambiguous、仅本地异常或测试观察都不构成未发送证明。旧进程丢失原活跃边界且未成功提交终态的请求不能通过此迁移补写为未发送，仍按原未决恢复限制处理。

### 管理端菜单节点与按钮权限补齐（160 源码候选）

正式管理权限按 141 同款精确归属安装/升级/卸载：新增四个页面节点——`SandAIOverview` 概览（组件 `/plugin/sand-ai/dashboard/index`，读权限 `sand_ai:overview:index`）、`SandAIModel` 模型（`/plugin/sand-ai/model/index`，`sand_ai:model:index/read/save/update/destroy`）、`SandAIInvocation` 调用记录（`/plugin/sand-ai/invocation/index`，`sand_ai:invocation:index`）、`SandAIAudit` 审计（`/plugin/sand-ai/audit/index`，`sand_ai:audit:index`）。既有四页补挂按钮：SandAIUsage 挂 `sand_ai:usage:cost/summary`、`sand_ai:billing:credential/sync`、`sand_ai:pricing:index/save/publish`；SandAICapability 挂 `sand_ai:setup:legal-profile:read/save/publish/revoke`、`sand_ai:setup:audio-transcription:read`；SandAIAgentConfiguration 挂 `sand_ai:agent:definition:manage`、`sand_ai:channel:binding:read/manage`、`sand_ai:tool:read/connection:create/create/mcp:discover/publish/bind/revoke`；SandAIKnowledgeConfiguration 挂 `sand_ai:knowledge:create/publish/disable/index:build/query:test/publication:index/publication:publish/publication:revoke`、`sand_ai:skill:read/create/test/publish/revoke/case:manage`。新增 `SandAIFileCenter` 文件中心（`/plugin/sand-ai/file/index`）沿用任务试用页纯导航设计：不挂按钮、拒绝角色链接、运行时仍需合法 SandIAM 工作负载身份，管理员登录态不能转换为应用身份。`sand_ai:budget:*` 保持平台超管专用不入种子；任务试用页原有仅超管判定不变。种子只使权限可授予，不自动分配任何角色；角色已持页面读权限时仅补导航链接。SOURCE 未执行迁移或运行验收。
