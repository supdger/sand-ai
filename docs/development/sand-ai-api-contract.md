# SandAI 首期 API 契约

## 长录音原分段结果回执恢复（4.5 源码候选）

已分段的原 `audio_transcription` 父任务处于 `recovery_pending` 时，Worker 仅在按原片段顺序最早未成功的子派发同为 `recovery_pending`，且其原 `result` 加密回执未清除、未销毁、未确认、未过期并仍在保留期内时领取恢复租约。领取沿原父任务和步骤恢复，不改变原请求键、输入、片段清单、模型尝试次数上限或已成功片段；只消耗既有 `local_recovery_count`／`local_recovery_max` 的有界本地恢复额度。已用尽模型尝试的恢复续跑仍须满足原实际失败次数与已消耗本地恢复次数的有界关系，不重置尝试历史。

恢复通过原当前 SandIAM、私有来源、租约栅栏、冻结请求和回执认证，再复用既有分段结果／唯一用量封存路径。该恢复片段不调用 Provider；封存成功后，未执行片段继续原固定清单及既有派发／费用规则。`accepted`、`ambiguous`、缺失／清除／销毁／确认／过期回执及耗尽恢复额度不自动领取，不重新发送未知请求。公开请求字段、路线与 Task DTO 沿现契约。此段仅冻结源码语义，未运行 Worker、数据库、模型、计量或真实会议验收。

正文随统一安装包单一维护，请阅读 [完整文档](../../plugin/sand-ai/docs/development/sand-ai-api-contract.md)。此路径保留为源码入口，不重复维护正文。


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


### 3.2 首次开通明确本地输入错误（后继28冻结）

首次开通的明确本地输入错误沿宿主响应封套返回 `code: 400`（HTTP 200）：`SAND_AI_VALIDATION_ERROR`、`SAND_AI_ONBOARDING_CONNECTION_INVALID`、`SAND_AI_ONBOARDING_HEADER_INVALID`、`SAND_AI_ONBOARDING_MODEL_INVALID`。空／全空格 Key、控制字符、缺失或错误类型的连接对象、非法模型标识及 Token／超时边界不是服务器故障，响应提供固定中文修正指引。只有敏感中间件尚未进入下游 handler，或草稿控制器在进入持久 save 服务前的纯请求策略预检拒绝，才返回 `data: { error_code, retryable: false, submission_state: "not_submitted" }`。SandAI 保存 API 通过宿主现成的每请求 `transformResponse` 保留这组有限元数据，响应封套原样交给原业务与登录拦截器，不改全局 HTTP 客户端或建立第二客户端。页面须同时核实 `code: 400`、上述有限输入码及结构化 `submission_state=not_submitted` 才解除尚未保存的输入锁，让管理员修正后重新保存，不依赖中文文案判定。同一保存意图若已有结果不明的较早尝试，即使随后获得本次未提交证明，也须保留原参数与幂等键，不能据此释放或重新建配置。其他阶段即使返回 400，也不提供该证明；已保存、部分失败、未知或服务商失败继续保留原冻结保存意图与幂等键。`not_submitted` 只证明该保存操作未进入持久保存／Provider 派发，不改变平台认证防重放 nonce，也不解锁原验证或未知回执。格式错误的 JSON、非对象请求和不支持的配置字段在上述中间件前置阶段拒绝，所有失败路径继续清除秘密。后台权限拒绝、未登录、服务商失败和未知系统错误保留各自原分类；不由 400 推断已验证能力、无费用或可以重发原未知验证。

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

### 原调用前预算上界投影

单次费用的 `price.estimated_amount` 为原调用前预算预占上界，不是实际 Token 预测、已计量费用或已付现金。新增可选字段 `estimate_basis:'budget_reservation_upper_bound'|null`、`estimate_state:'available'|'unavailable'|'held'`、`estimate_reason:'frozen_budget_reservation'|'no_frozen_quote'|'frozen_quote_unavailable'|'frozen_quote_conflict'`、`estimated_currency:string|null`、`estimated_price_revision_id:number|null`。这些字段同时用于原调用费用、无用量事件的派发费用及已授权任务的单次费用投影，不新增路由或权限。

只有原调用、request/fingerprint、四级归属、派发 body/route 摘要、调用时价格及原不可变 reserve 事件形成完整一致的证明链时，才返回 `available/frozen_budget_reservation`。读取原预算组的一个相同上界，不相加多个 scope 的重复预占；用原冻结费率和容量数量核对上界及最小正预占 `0.000001`。不查询当前价格、不生成新报价、不回填历史。缺原预占为 `unavailable/no_frozen_quote`；缺证明为 `unavailable/frozen_quote_unavailable`；现有事实冲突为 `held/frozen_quote_conflict`。不可用或冲突时上述金额、币种、报价版本与依据均为 `null`。

预估币种和报价版本只来自这条原冻结报价链，独立于计量的 `price.currency/revision_id`；未测量或未定价的实际用量仍保持其原未知字段，不能用预占报价填充计量事实。已有 priced 用量的价格/币种须与原调用价一致，否则暂停该预估投影。旧响应缺新增可选字段时界面保持未知，不假定 available；界面明确展示“调用前预算上界”和预算报价价格版本，并说明其预算预占用途及最小预占口径。

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

文件清理生命周期元数据和转写原请求键只读恢复的完整增量见插件内API契约；不放宽正文读取，不重新派发未知请求。

渠道重试原请求键只读恢复及账单关联成本trace增量见插件内完整API契约；不重新派发，不将trace作为授权或现金对账。

### 首次开通原验证请求找回与发布修订核对（源码）

`GET /app/sand-ai/admin/setup/deployment/verification/read?deployment_id=&revision=` 可携带可选 `Idempotency-Key`，按当前已认证管理员、精确配置修订和该原键的 HMAC 只读查找原验证尝试及其回执；无请求头保留原修订状态读取。原尝试没有回执时返回该尝试的 `verifying`、空验证证据与空失败反馈，找不到时返回既有 `SAND_AI_RESOURCE_NOT_FOUND`。不使用该修订的最新尝试冒充原请求，不返回原键、HMAC、连接秘密或执行快照；状态读取不重发模型验证。原处理位置仅从三个现有注册值与该尝试冻结的请求摘要唯一匹配复建，不借用后来的配置。

`POST /app/sand-ai/admin/setup/onboarding/publish-deployment` 兼容可选正整数 `revision`，含义为用户确认的部署修订号。提供时在原发布锁中同时核对当前部署和配置修订号，变化返回 `SAND_AI_CONFIGURATION_DRIFTED`；省略时保留旧调用行为。首次开通页面发布必传原保存的修订号，并只读确认该精确修订已发布。

页面在验证发送前仅于当前管理员命名空间的 `sessionStorage` 保留管理员 ID、部署号、修订号与原验证请求号；不保存服务商 Key、应用 Bearer、配置或结果。刷新或返回页面仅用原号找回，缺失、读取失败、未决或不确定状态保持新保存、验证和重新配置锁定；只有该原尝试明确终态才能解除。原请求号与验证费用尝试 ID 不同，不把未知费用显示为零。

智能体定义四操作原键纯GET恢复、已确认draft摘要发布与可信拒绝回执增量见插件内完整API契约。

文件解析原X-Request-Id纯GET恢复与未知请求锁见插件内完整API契约。

### 发布修订冲突的当前目标恢复（源码）

`GET /app/sand-ai/admin/setup/deployment/read?deployment_id=` 可省略 `revision`，此时沿原管理读取权限和 Provider → Model → Deployment → ConfigRevision 锁顺序，在锁定部署后读取其当前 `revision` 对应的配置修订，返回原安全 `DraftSave`；显式修订读取保持原行为。仅提供当前修订与安全模型配置，不回显连接秘密。

首次开通页发布未确认或修订冲突后，锁定继续发布，并提供“读取并确认当前修订”：纯读取当前部署修订及该精确修订的验证状态，展示模型、输出上限、推理额度、方法包输出及真实状态。用户明确“确认使用此修订”后才更新页面目标；读取失败或 404 保持确认要求。当前修订已发布时按该真实状态继续绑定，未发布时按其真实状态验证或发布，不自动执行。此恢复不证明原发布未执行，不创建拒绝回执，也不解除原验证的任何未决请求锁。

知识发布固定snapshot、原键纯GET与可信未发布拒绝回执／显式重新确认增量见插件内完整API契约。

独立已发布知识检索入口、消费环境提示重验及查询 Embedding 费用读取见插件内完整 API 契约。

任务取消时明确未派发的预占释放与准备竞态收口见插件内完整 API 契约。

智能体工具明确未派发时的原节点续跑与后继回执关联见插件内完整 API 契约。

公开最低成本聊天的实际冻结路由／请求绑定及漂移提示见插件内完整 API 契约。

渠道流式第4轮显示、正式拒读清私密与同运行断线恢复见插件内完整 API 契约。

材料成果／来源末端授权重验与已取消解析的正式安全操作见插件内完整 API 契约。

预算草稿原请求保存回执、只读找回及可靠未保存证明见插件内完整 API 契约。

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

### PDF 官方 SDK 逐页正文适配（原3.4，SOURCE）

PDF 正文读取源码候选复用 Neuron AI 4.0.2 `PdfReader` 的正式 `setOptions()` 页范围（`-f/-l`）与 UTF-8 编码逐页 `read()`，不依据整份文本的分隔符推断页号。Smalot 仅提供原页列表、页数和 XObject 图像／Form 事实，不再调用其正文抽取方法；来源块仍为 `page`／`locator.page`，非空块顺序号连续，空白页不压缩原页号。新成功解析的 `parser_code` 为 `pdf/neuron`、`parser_version` 为 `4.0.2`；能力档的 `driver_code=native_document_parse` 保持，既有发布路由无需更名。实际正文引擎改变可能影响新解析文本及摘要，历史封存来源不回填。

每个原页均检查 XObject，不以已有原生文字、文字长度或比例推断图片正文已完整提取。页上含 `Image` 时，即使还能读取页码、页眉或其他文字，也触发既有私有 PDF OCR；`Form` 可能包装未确认图像，因此同样保守触发。整份 OCR 结果继续由 `PdfOcrCoveragePolicy` 核对：所有含原生文字或上述图像／Form 的原页均须有非空来源块，页码不可越界。OCR 未配置时返回既有 `SAND_AI_OCR_REQUIRED`，缺页或越界时返回 `SAND_AI_PARSE_FAILED`；原生片段不会写为这份文件的成功解析，也不能进入材料分析。纯空白且没有上述 XObject 的页可跳过。此保护不证明 OCR 文字识别准确或表格结构已还原；取消及原任务重试接口保持。

运行前置是 `neuron-core/neuron-ai >=4.0.2`、`symfony/process:>=6.4`、允许本地进程执行的 PHP，以及配置的 Poppler `pdftotext` 可执行文件。管理员通过宿主环境设置 `SAND_AI_PDFTOTEXT_PATH`，并可设置 `SAND_AI_PDF_READ_TIMEOUT_SECONDS`（默认每页 60 秒，1–300 秒）；本方案沿 Smalot 页事实，不调用 SDK `getPageCount()`，因此不要求 `pdfinfo` 路径配置。未配置、不可执行或依赖缺失返回既有 `SAND_AI_CAPABILITY_UNSUPPORTED` 与具体管理员处理提示，不自动重试、不暴露私有路径／原生工具输出、不切换正文引擎。依赖补齐后可按原权限及剩余尝试次数，沿既有失败任务重试入口再次提交。工具实际读取失败沿既有 `SAND_AI_PARSE_FAILED` 处理；原 PDF 空内容与 OCR 错误码保持。本候选未执行依赖安装、PDF／OCR 业务验证或 TypePHP。

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


## 2026-10-06 可配置官方专业重排 SOURCE

知识查询显式 `rerank_mode=professional`，沿原body／Idempotency-Key及当前知识发布授权；provider.adapter为 `cohere_rerank|jina_rerank`，model.type为rerank，deployment.config={}。使用冻结Neuron4.0.2专业节点，RRF仍为SandAI适配；旧无marker chat重排兼容，不自动降级。

计量仅采真实Cohere search_units或Jina total_tokens，缺量为null，专业token三字段null。两driver无已证明收费上界，hard或含hard的混合预算保原key未发送拒绝 `SAND_AI_BUDGET_CAPACITY_UNVERIFIED`；soft预算沿原未定价策略允许未知费用待结并提醒，按原冻结价格与真实量结算，已发缺量保hold。无预算按真实量计价，只读原号不重发或结算。目录、部署、pricing和返回DTO完整说明见既有plugin及工作区API正式契约；137及生命周期入口仅SOURCE未执行，不称已开通或验收。

## 2026-10-06 专业重排软预算 SOURCE 契约

专业查询及原请求只读找回返回 `budget=null|{state:soft_cost_pending|held|settled|released,warnings:[{code:SAND_AI_BUDGET_COST_PENDING}]|[]}`；pending/held仅一项费用待确认提醒，settled/released无该提醒。旧chat不增字段，原usage不变，未知费用不等于免费或已超额；原号读取不派发、不结算。

soft未定价block仍拒绝未发布价格，allow保原未定价快照；未知量不伪造估值、预占或零费。共同预算selector与claim在原scope及相交账期锁内拒绝当前hard忽略prepared/held的NULL费用义务；released/settled及不相交账期不阻。完整DTO与账期规则见既有plugin/工作区契约。138与正式安装/升级/卸载入口仅SOURCE，未执行迁移、外发、测试或验收。

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

## 默认聊天环境绑定原目标恢复（3.2 源码候选）

首次开通页在 `POST /app/sand-ai/admin/setup/onboarding/bind-default-chat` 前，只保存当前后台管理员、SandIAM application/environment、deployment ID 和原 revision 的会话操作记录；不保存 Bearer、服务商 Key、连接配置或原始响应。请求进行时保留发送锁，不能被并发只读查询提前解除。返回结果未知、字段不符或存储无法确认时，不显示“绑定未完成”，不允许切换目标再次绑定；账户变化清除当前页面旧目标，并只读取新管理员自己的记录。

“读取原绑定目标”复用现有 `GET /app/sand-ai/admin/setup/deployment/read?deployment_id={原部署}`（省略 revision 读取当前 head）及 `GET /app/sand-ai/admin/setup/capability/profile/read?environment_id={原环境}`。先要求部署返回的 `deployment_id`、`revision` 与原记录一致且 `state=published`；再要求能力档 `environment_id` 相同、`state=published`、`status=1`，按 priority 排序的首条 active chat 路线为 `model_deployment` 且 `deployment_id` 相同；最后再读当前部署 head，仍须匹配原 revision 与 published 状态，才解除本地待确认记录。读取权限分别沿用 `sand_ai:setup:deployment:read` 与 `sand_ai:setup:capability:read`。

这是原目标当前配置的只读核对，不是服务器原操作回执，不能证明历史请求是否发生。无配置、较新草稿、不同路线、部署修订变化、权限/网络/存储错误都保留原目标待确认，不证明“未提交”，不重新 POST。恢复仅影响开通页的本地操作记录，不修改能力档、部署或 IAM 授权；接口字段和服务器写入契约不变。实际页面、并发与网络中断行为留待功能全部实现后的运行阶段。

### SandAI自域宿主foundation加载兼容错误（源码实现）

本插件路由／worker登记前通过Composer InstalledVersions读取实际Core／Package稳定版本，均须>=0.2.4；不足、缺失或未知仅拒绝SandAI域，不改变其他插件。`SAND_AI_HOST_FOUNDATION_INCOMPATIBLE`沿原错误壳HTTP／业务400、retryable=false、Cache-Control:no-store；依赖补齐但尚未重新登记业务路由返回`SAND_AI_HOST_FOUNDATION_RELOAD_REQUIRED`，要求管理员按宿主正式维护流程重载，不自动改依赖或重载。完整语义见包内[API契约](../../plugin/sand-ai/docs/development/sand-ai-api-contract.md)。仅SOURCE实现，未执行加载或业务验证。

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

### SandAI 进程配置早加载（原6.4 SOURCE）

Webman 在 `autoload.files` 前读取进程配置。SandAI `config/process.php` 先通过固定相对路径 `require_once` 复用自身 `app/functions.php` 命名空间注册，再执行 foundation 守卫；后续宿主 `include_once` 不重复注册。该现有 loader 只注册 `plugin\SandAi\` 文件映射，不初始化数据库、密钥、业务服务或其他插件，不跳过版本未知／不足的拒绝门。

### 4.1／4.2 账单批次逐行处理状态与重复引用（SOURCE）

原 BillingDetail.lines 只读 DTO 新增：

- `is_duplicate: boolean`，非 nullable。仅按已有 `sand_ai_billing_import_line` 与 canonical `sand_ai_billing_line` 关联判断：原事实所属批次不同于当前批次，或当前批次多行引用同一 canonical 事实。后一情况的全部相关引用均为 true；该标记不是额外账单金额、另一笔费用或另一次核对。同一行的 canonical 原批次 provider_id 必须与当前批次 provider_id 精确一致，否则 `SAND_AI_BILLING_FACT_CONFLICT` 拒读。不输出另批 ID、私有正文、凭证或来源身份。
- `operator_review_state: 'reviewed_match'|'reviewed_discrepancy'|'unmatched'|'aggregate_only'|'rejected'|'unresolved'|null`。从该 canonical 行已有 `sand_ai_billing_review` 按 id 降序的最新记录读取，null 仅表示没有人工审核记录。它是独立人工处理摘要，不更新 `reconciliation`、原计量／账单金额或费用可信度。

页面重开或刷新通过原 GET billing import read 重新读取上述持久状态，人工处理成功后同步当前行显示。提供本批全部行、待人工处理、自动已核对、未匹配或有歧义、自动或人工差异、重复事实引用、已核对或已人工处理、状态未提供筛选。待人工处理仅包括已知人工状态 null／unresolved 且未自动 reconciled 的行；人工已处理不等于账单已核对。旧响应缺新增字段显示未知，不能计入确定待处理或已处理数量，另有未知筛选入口。

原导入／同步／审核 journal、原请求号 GET 恢复及导入事务保持；未知响应不创建新请求或误再导入。没有新表、迁移、执行 SQL 或金融账本变更。本批仅 SOURCE，未运行测试、静态检查、编译或业务验收。

### 5.5 真实平台会话只读恢复

渠道会话 history、messages/by-request-key、messages/{taskId}/sources/{sourceBlockId}、messages/{taskId}/events 的 query 可带 `delivery_platform=feishu|wecom`；conversations/{conversationId}/close 的正文可带相同可选字段。省略保持原应用试聊作用域。服务端先沿当前 SandIAM 上下文核验启用绑定和发布 Agent，再沿正式平台凭据配置读取口确认当前 code 的平台及托管环境/绑定归属；不接受其他值，不新建 grant，不返回凭据。平台参数参与与已验签回调完全相同的会话/消息 hash，并核对原任务冻结的 delivery_platform；历史、来源、费用、事件仍各自当前授权，不能借另一平台或应用试聊身份读原任务。

真实平台 `messages/by-request-key` 必须使用原平台消息 ID 同时作为 message_id 与 Idempotency-Key，携原 conversation_id、sender_id。只读缺失返回原 RESOURCE_NOT_FOUND，不推断尚未发送，不转 POST，不重新生成回复；旧未冻结 delivery_platform 的记录不自动改作平台记录。事件断线续读保留原 taskId/event cursor，不派模型。平台恢复页面仅提供历史、原消息、来源、费用、已允许的原节点续跑和明确关闭，不开放 submitMessage；人工再次发送须回飞书或企业微信客户端产生真实新消息，复用当前已发布 Agent 并按新消息独立计量。回信 uncertain 保持原 outbox 待核对，不因读取 task 自动再次外发。



## material_chunks@1 (2026-10-08 SOURCE)

## 原3.4材料大文本分块（SOURCE候选冻结片段）

现有文件运行／任务／来源 API、Schema 和 failed_files、cross_file_review、receipt_only 参数保持。
`workflow.file_states[]` 追加可选 `chunk_progress`；历史未分块文件缺省该字段，不解释为完整。

```ts
type MaterialChunkProgress = {
  version: 'material_chunk_progress@1'
  state: 'running' | 'failed' | 'recovery_pending' | 'succeeded' | 'canceled'
  source_block_count: number
  source_character_count: number
  leaf_count: number
  merge_count: number
  completed_leaf_count: number
  completed_merge_count: number
  failed_node_count: number
  unknown_node_count: number
  coverage_state: 'pending' | 'complete' | 'incomplete'
}
```

计数均为非负安全整数；来源字符以原冻结内容的Unicode码点计数，叶计划按原来源块顺序、连续半开字符范围完整覆盖，不能用最终模型输出长度冒覆盖。plan与各节点checkpoint只存现TaskStep.context，不暴露私有源正文、对象路径／存储keys、模型prompt或原始响应。

`state=succeeded`且`coverage_state=complete`仅在所有计划叶/归并节点有已校验封存结果、完整来源覆盖并沿现WorkflowFileResult封存后返回；否则文件仍失败／未完成／待确认，不生成伪完整成果。各实际模型调用独立计量；旧成功子节点恢复不重发、不重计费。confirmed失败只沿原failed_files恢复；unknown不能新发，保原invocation请求身份与结果只读恢复。

UI在文件状态／成果旁展示完成叶数、归并数、来源块和字符覆盖状态。unknown_node_count>0明确“原分块调用结果待确认；只读刷新原任务，不重新提交或更换请求号”。没有chunk_progress的历史结果沿原文件Schema／来源读取，不宣称本新覆盖policy已执行。

完整分块成果的 `file_results[]` 可带 `paged:true`、`collection_counts`（documents/entities/events/conflicts/items_to_verify/warnings各真实总数，extensions为field->总数）；此时 `result.payload:null`、`citations:[]` 明确需要分页，不能视为空成果。历史非分块结果保持原全量形式。只读GET `/api/sand-ai/v1/structured-workflows/runs/{runId}/files/{fileId}/result`，query section、extension_field（extensions必填）、offset>=0、limit1..100默认50；返回file_id/schema/section/extension_field/offset/limit/total/items/citations。citation.field_path保原全量索引，每页当前来源授权重验。原sources端点仍按完整sealed引用授权，不由当前页决定。

新增 MCP 工具 `sand_ai_material_file_result_page` 复用现 `sand_ai.workflow.read`；必填run_id/file_id/section，section同分页GET七集合；extensions必填extension_field，其他禁止；offset默认0且非负安全整数，limit默认50且1..100。工具沿正式fileResult Controller逐页authorize/revalidate，不读私有存储或发模型，不建立新grant。

新受理材料运行在原input_snapshot与execution_snapshot固定 `material_chunk_policy:"material_chunks@1"`。旧运行无此策略继续原单prompt/原请求/旧receipt。原解析与profile/context上限不变；超过单prompt的大材料以官方Splitter的边界加真实Unicode来源范围适配完整规划叶，现Neuron三节点逐叶读取；所有叶覆盖每块原范围不重不漏。每个归并保全完整业务集合，跨子候选有界分片官方复核，模型每次prompt/output仍受原上限，最终业务成果完整持久化不强求全部事实再次塞单次模型输出。

现TaskStep私有context持久固定plan与逐叶/逐复核节点原attempt/request身份、源快照、验证成果摘要；只有明确失败文件可用failed_files，已成功子节点保原invocation/usage不重调用。未知节点保原请求，原任务读取只恢复原receipt，不能用新request替代；取消保原未知费用。只有所有叶完整覆盖、所有复核节点实际成功并验证source、完整file结果封存才显示coverage_state=complete。chunk最终file封存attempt和root实际调用attempt可不同，调用身份/费用取原root调用attempt，来源封存代次取当前fileattempt，不回写历史。删除原内容同时清理TaskStep内派生结果正文，费用身份和不可变usage保留。

最终run.result沿现完整artifact（包括跨文件待核实与withheld结果）保持全量读取，不以file分页替代最终结论；file_results[].paged仅用于完整单文件候选集合。SOURCE候选未执行测试/审计/语法静态/编译或运行验证。

多文件运行的跨文件核对同样按固定完整候选对规划有界官方复核，不重新把大文件全部来源正文装进一个prompt。每批沿原`cross_file_review`服务动作与授权，固定独立batch身份；末批保原复核终态身份。中间成功批的真实usage均计入原运行，unknown仅原回执；全部批成功后才汇集所有待核实项并按原sealed set封存。原provider prompt/output上限逐批保留，最后完整业务集合与分页均不截断原事实。

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

当前原调用方明确继续时，服务端正式取得并复验新的 SandIAM decision，仅更新 task 当前执行授权封套；新、旧 decision ID 与稳定继续意图单独记录在原 AuditLog，既有 invocation／dispatch／回执／usage 的旧授权证据不覆盖。新授权仍保留自身到期时间，不延长旧 exp；当前授权再次到期则再次暂停。操作不清空失败或回执次数，已成功节点不重跑、不重复计费。持久会议的正式继续保留任务及父步骤 attempt_count、max_attempts、local_recovery_count、local_recovery_max，原子保存同 task、原 request、同 caller／scope 的续行意图，不增加失败或回执额度。未知派发仍 recovery_pending；先沿原 invocation／receipt 找回并封存，未结事实不允许替换请求号或派发后续节点。

同 task、scope 与继续键的 POST 重放核当前任务及原资源权限后，只读取已经原子写入的意图和安全 Task 摘要，不再次更新执行授权或入队。响应未知时保留原 task、同键，由用户明确找回；不能自动生成另一键。POST 不返回业务成果，收到同任务 DTO 后用原 task.read 读取成果及来源，读失败不推断继续失败。页面不向 API 提交 authorization_id、caller 或私有身份封套；DTO 不返回密文、decision ID、原始厂商响应或私有存储信息。

持久会议 Worker 领取该正式意图时，在原任务锁内重验当前任务／音频授权和原来源、当前 checkpoint 与首个未完成节点，原子消费一次阶段领取许可并绑定当前 lease fence；required 的暂停任务不能领取。只有本次阶段已提交的 checkpoint 链头或首个未完成节点真实递进，成功释放租约时才恢复同意图的下一阶段领取许可，任务及父步骤次数保持原值。已领取但尚无 dispatch 的节点复用原 node attempt；唯一 prepared／no-start 调用经现有正式预算和租约重绑继续原 invocation、请求号及预占，已经发送或未知调用只恢复原回执。阶段失败、未知回执、租约过期或再次授权拒绝不续开许可；保留原节点与 prepared 预占，正式当前 caller 再次明确继续后才恢复原进度。原已受理继续键重放只读取该意图，不授予第二次领取或刷新次数；领取前新授权失效也须回到正式暂停，不忽略过期封套。会议纠正同键刷新两个授权字段不清除 required 的授权暂停标记。

本段为源码操作合同，不表示已运行、测试、编译或验收。

短外部音频的原 `audio-task:{id}` 结果回执由 `TaskAudioReceiptContinuation` 在正式 Task worker 中领取，须是同任务、同冻结指纹且未销毁／未过期的 `result` 回执，复用 `PostgresExternalAudioResultRecoveryPort`、原 usage 与音频封存。只有执行租约字段可重新认领，原调用授权／fingerprint／receipt／usage 不替换；当前授权在原回执与费用锁前后重验。该消费面没有 adapter send 或 query；accepted／ambiguous 等没有已有结果回执的调用保持 recovery_pending，不把等待当新调用许可。长音频 clip 仍沿现 AudioChunkResultRecovery，会议／材料仍复用各自已封存节点及原 receipt 恢复。

### 任务取消／普通失败重试的确认代次（原 2.3／3.4 SOURCE）

现 `POST /api/sand-ai/v1/tasks/{taskId}/cancel` 和普通 `POST /api/sand-ai/v1/tasks/{taskId}/retry` 可同时携带 `expected_attempt_count` 与 `expected_state`，也可同时省略。前者对应正式 Task DTO 的 `attempt_count`，必须为 JSON 非负整数且不超过 9007199254740991（初次 queued 可为 0）；后者必须为现 Task 状态之一：authorization_pending、queued、running、retrying、recovery_pending、succeeded、failed、canceled。单字段、显式 null、字符串代次数或非法状态均返回现 SAND_AI_VALIDATION_ERROR。

普通材料重试还可携带对应目标的前置条件：target=failed_files 时 `expected_failed_files:[{file_id,attempt_count}]` 是非空、按真实 file_id 升序、无重复的全部已确认失败文件集合，file_id 为正安全整数、attempt_count 为非负安全整数；target=cross_file_review 时 `expected_review_attempt_count` 是原正式 Run DTO 的非负安全整数复核代次。目标字段必须同任务两字段且只用于对应普通 target，非法组合拒绝，不允许缺字段猜 0／空集合。旧调用者省略目标前置条件沿原行为。

服务在现任务行锁及真实文件／复核行锁内比较原任务 attempt_count/state 与目标集合／复核代次，发生变化返回现 SAND_AI_RESOURCE_CONFLICT，不取消／重试后来代次，不变更步骤、授权或派发。页面保留确认前原任务／业务资源／代次／状态，确认后只读原任务；材料另重读原运行及精确失败文件／复核代次。读取不一致只展示当前状态，须用户重新确认，不自动重弹或 POST。材料无可确认失败目标时不回退省略 target 的普通全量重试。

这些字段属于操作前置条件，不是新命令幂等键，不改原模型请求、收费指纹或已发生调用；旧客户端同时省略时沿既有行为。receipt_only 与 authorization_continue 保持各自原合同，不携本组字段。普通重试仍须现失败任务／真实 failed_files 或 cross_file_review 门、当前来源与授权，成功节点及费用保持，unknown 只沿原 journal／Task GET／receipt 恢复，不据失败或取消显示未派发。

### 材料预算准入停止与原任务恢复（原 3.4／4.3 SOURCE）

材料分块或跨文件批次收到 `SAND_AI_BUDGET_EXCEEDED` 时，只有原持久 `PreDispatchRejection` 的预算准入阶段、可信调用范围、请求指纹、原 task／step／node／attempt 和失败 invocation 完全相符，且不存在任何 dispatch（包括 prepared）、预算预占、用量或结果回执，才记为未发送的预算失败。节点证据留在 TaskStep 私有 context；Task／Run／file DTO 不返回该证据、内部请求键或指纹。普通未分块文件沿原任务失败处理。

用户明确恢复原任务时，复用 `POST /api/sand-ai/v1/tasks/{taskId}/retry` 的 `target=cross_file_review` 或 `target=failed_files`；须携带原 `expected_attempt_count`、`expected_state` 和该目标原代次／精确文件集合。仅上述原证据及当前来源、授权均可重证时，旧误记 `recovery_pending` 的预算节点可在同一任务事务内修正为已确认失败并沿既有重试入队；未发送无法证明或代次已变则拒绝。已经正确记录的预算失败也须明确确认原代次。响应仍为原 TaskSummary，不新增恢复接口或 run。

原提交幂等键、task、run、已封存成功批、旧拒绝记录及收费事实保持；明确重试后只有失败节点的新 attempt 按现规则派生子调用键，旧被拒子键仍重放原拒绝。此操作不增加预算、不释放未知费用、不自动重发，不清空失败次数；每次派发仍经过正式预算硬门。任何 provider-visible、未知或未结 prepared 派发保留原请求及回执恢复边界，不能用补预算替代结果确认。原提交键重放只找回原运行，不能代替明确任务重试。

本段是候选源码操作合同，未运行、测试、编译或部署。

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


### Consumer Skill 已确认失败的显式独立轮次恢复（5.2/5.6 SOURCE候选，2026-10-10）

使用者为已经确认本人原输入与固定 Skill 版本的消费应用用户；成功结果是原 logical key 下得到单独计量的新轮次，原失败、原模型回执与原费用继续可读。界面须在恢复确认中说明会再次调用并计费，最多包含初次在内三轮；未知响应只 GET 原号，不自动 POST 恢复。

- `POST /api/sand-ai/v1/skills/{code}/versions/{version}/tests` 沿用 `Idempotency-Key`；`POST .../runs` 沿用 body 的 `request_id`。两者原 `scope`、`actor_ref`、`input`、code 和 version 不变，新增可选 `resume_failed:boolean` 默认 false。true 必须携 `failed_run_id:positive integer` 指向用户已观察的失败轮次；false 或省略时禁止 `failed_run_id`，不以 null、字符串或数字替代 boolean。
- 恢复只开放 `SAND_AI_SCHEMA_UNSUPPORTED`／`SAND_AI_SKILL_OUTPUT_INVALID`，且须证明关联 invocation 与 dispatch 均已 succeeded、已有原 usage event 且环境／Skill／版本／run 精确相符。provider 未知、ambiguous、inflight、成果已成功或记录删除均不得新轮次。原服务身份、当前 Skill 资源及知识 publication 授权在新 claim 时重验，模型派发继续使用 ChatGateway 正式预算、计量、来源及 Neuron 官方 structured 输出；不得修改固定输出 schema、过滤非法字段或将原失败改为成功。
- 每个 `failed_run_id` 最多一个子轮次，短事务锁原 Skill／版本／原 run 并依 unique predecessor 和 `(original_run_id,attempt_no)` 约束申领。重复同父恢复只命中该子轮次；child 正在运行返回 `SAND_AI_SKILL_RUN_IN_PROGRESS` 并要求 GET 原号，child 终态正常回放。再次恢复 child 失败须重新显式指定 child 的 run_id；原输入摘要与业务 fingerprint 保持，内部 child request_id 不作为新的公开 logical key。
- 两个 `GET .../tests/by-request-key`／`GET .../runs/by-request-key` 默认读取原 logical key 下最新轮次。可选 query `attempt_no:1..3` 读取指定历史轮次，原 scope／actor／版本与授权核验保持。summary 在原字段上增加 `original_run_id:integer`（初轮 id）、`previous_run_id:integer|null`、`attempt_no:integer`、`max_attempts:3`；`run_id` 与 invocation 定位始终为本轮，不覆盖原失败。
- 两个现有费用 GET 增加可选 `attempt_no:1..3`；省略固定读取初轮费用，保持旧客户端原费用含义。消费页须显式传当前结果 attempt_no，并提供初轮历史费用入口；无真实账单金额仍为 unknown，不补零。费用定位仍以原 code/version/kind/key 和当前 scope/actor 证明对应轮次，独立当前 usage 授权，成果撤销不抹除原账单。
- 新错误 `SAND_AI_SKILL_ATTEMPT_CONFLICT` 表示目标不是当前失败且没有该父的既有 child，`SAND_AI_SKILL_ATTEMPTS_EXHAUSTED` 表示已达三轮，`SAND_AI_SKILL_RETRY_UNAVAILABLE` 表示已确认模型完成或 schema 失败证明缺失；三者均不换号重试。严格 DTO 对非法字段仍返回现有 validation error。

存储归 SandAI：`sand_ai_skill_run` 新增 `original_run_id`、`previous_run_id`、`attempt_no` 和仅内部使用的 `retry_feedback`；迁移 `202610100154_skill_failed_attempt.pgsql` 保留所有既有行，初轮默认 1，不创建 IAM 记录或授权体系。新 child 保存相同原 input、固定 version/snapshot、consumer context 与原来源 citations；纠正提示只含固定 schema 的字段路径、类型、required 与 additionalProperties 约束，不复述原厂商输出。执行迁移与宿主安装另按运行授权，本候选记录不表示已安装或运行通过。

### Consumer Skill 原号读取的当前无受理记录投影

现 `GET /api/sand-ai/v1/skills/{code}/versions/{version}/tests|runs/by-request-key?request_id=原logicalkey` 在消费范围声明存在时，只有真实同键 Run 无行且含软删的同键查找也无行、当前合法 consumer 与固定版本归属一致、资源及固定知识来源正式授权成功，才可成功返回安全对象：`state:"not_admitted",original_lookup_state:"absent",operation:"test|run",request_id:原logicalkey,code,version,skill_id,version_id,scope,scope_source:"consumer_declared",actor_ref`。API ID为正整数；消费BFF Presenter可投影十进制字符串。无input、摘要、模型输出、来源正文或费用结论。test要求version draft且Skill draft|published；run要求Skill/version published；均status=1。消费版本创建/发布绑定与当前consumer快照一致才开放。

不存在Skill/版本、软删Run墓碑、现有Run坏kind/input/fingerprint/consumer绑定、撤销/停用、来源或授权失败，均不返回该无行标识，仍遵循现错误契约。普通RESOURCE_NOT_FOUND不等价于未受理。本对象仅描述读取时当前同主体固定资源原键无Run，不能排除随后并发受理，也不证明本地消费意图或现金费用；消费端必须核自己必先永久意图和原owner/输入绑定，再显式确认同原键请求。原正常Skill claim的事务锁、唯一键、指纹及dispatch链继续负责并发幂等，不新增endpoint、授权或跨库读取。


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

### 费用汇总中的 Skill 业务失败关联（原4.2 SOURCE候选，2026-10-10）

费用看板业务失败关联同样包括归属可证的 Skill run 最终 `failed`：复用 `skill_run_id` 分组与筛选的同一正式 Invocation、Dispatch、不可变事件及 Skill／版本／运行关系证明。模型调用成功而 Skill 输出契约失败时，原 `failed_*` 仍为零，相关事件进入 `business_failed_*`；同一事件即使也关联失败组合运行仍只计一次。`pending,running,recovery_pending,canceled` 及归属不可证的 Skill 关系不纳入；计量、定价和对账未知仍保持原 null 语义，不改写或重计原费用。

#### 会议纠正同键授权刷新及 prepared 租约重绑

同键同五字段重放还会按当前同一 caller／scope 正式重验原任务和原音频资源，将新 SandIAM／音频授权原子保存到原任务的两个允许更新字段；保持业务输入、规划锚、原纠正标记、任务及节点次数、重试上限、恢复计数、租约和所有 dispatch／费用记录。过期旧授权不在 worker 中被忽略，也不借 `authorization_continue` 增加额度。节点已有唯一 prepared 且未开始派发时，恢复继续其原 attempt、invocation、请求号及预占，通过正式租约重绑后派发；节点已领取但尚无 dispatch 时才沿原 attempt 创建唯一调用。同键刷新本身不创建模型调用或新纠正意图。

后续明确受理的新会议纠正具有独立的正式授权与付费意图，终止旧续行许可并保留其原 context／AuditLog 历史；required 的暂停不能由纠正入口清除。

正式续行许可消费后，首次派发前的预算／路由拒绝及普通异常同样转入 required 的正式暂停，不自动重开领取许可、不把原待完成或已领取节点改为 failed；原任务、父步骤和节点次数、prepared／no-start 调用及预占保持原值。原调用方以新的明确继续意图取得并重验当前授权后，才继续原未完成节点；原继续键重放不清除这次暂停。永久会议输出拒绝仍使用原独立纠正入口，未知或已发送调用仍只能恢复原回执。


### Composition keyword node non-billable proof

The existing cost GET adds optional nodes[].charge. Only the actual successful server keyword execution with no active embedding index, rerank=false and no professional mode can emit an internal completion witness. The node stores it with the original input/output digests. Cost reads verify the original identity, node key, frozen reference/version and query intent before returning charge={state:"not_billable",basis:"server_keyword_execution",metered_amount:"0",currency:null} and node coverage=complete. Zero describes no Provider invocation, not a supplier bill, refund or infrastructure cost.

Other nodes expose charge={state:"usage_events"|"pending"}; older servers may omit it. rows contains only real immutable usage events. No synthetic zero event or parent charge is added. Overall aggregates remain real child events; verified non-billable nodes may satisfy coverage. Existing events take precedence. Missing proof, unknown execution, missing receipt, vector, rerank, tool and Agent branches cannot become non-billable. Historical GET never creates proof; old parent3 remains pending without its original execution witness.

The UI shows the non-billable message only when coverage=complete, rows is empty and the full state/basis/amount/currency tuple matches. Internal proof is omitted from public child locators and is never accepted as a client field.

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


### Original upload request recovery (0.2.41 source contract)

SandAI owns the upload association; SandIAM continues to own authorization. No new service action is introduced. New uploads require a client-retained valid `X-Request-Id` (8–96 audit-key characters). `POST /api/sand-ai/v1/files` accepts exactly one multipart `file` and no other form fields. Multipart boundaries and client-declared MIME are not identity facts: the server binds the inspected normalized filename, extension, detected media type, byte length and SHA256 to the canonical trusted organization/application/environment/workload identity (including opaque references). Raw keys are not persisted, only their SHA256. Credentials, storage locators and key digests are never returned.

The first request commits a `sand_ai_file` intent in `upload_pending` before storage. A PostgreSQL advisory transaction lock and a unique `(environment_id,owner_scope,upload_request_key_sha256)` index serialize identity creation. A file row lock serializes identical writes and normal deletion; lock wait is bounded to 5 seconds. The supported OSS upload has connection/transfer timeouts of 5/30 seconds and SDK retries disabled using setMaxTries(-1): the installed SDK auth() HTTP500 branch compares redirects <= maxRetries with initial redirects 0, and its setter directly stores the threshold. Zero would still resend once. No SDK code or compatibility upper bound is changed. Local storage atomically renames a bounded uploaded file; local filesystem blocking cannot be given a PHP wall-clock guarantee, but contenders have a finite database lock wait. No object is deleted on ambiguous storage/commit errors. A crash leaves the original pending intent/object locator; an explicit same-key, exact-facts POST may replace that same private object and complete the row, never allocate a second logical file. A process crash or transaction rollback never establishes success. No automatic background retry is added.

POST with the same scope/key/facts returns only the original upload receipt: ID/ref, normalized filename, extension, detected MIME, size, SHA256 and current lifecycle state. It never returns latest_parse, task, results or cleanup/read projections under file.write. Deleted receipts omit file_ref and are not resurrected; complete metadata is available only through the existing file.read-authorized GET. A same-key fact mismatch is HTTP/business 409 `SAND_AI_FILE_UPLOAD_KEY_CONFLICT`. `SAND_AI_FILE_UPLOAD_PENDING` signals an uncommitted storage outcome; other interrupted/5xx outcomes require original-key lookup too. Keep the local source bytes and original key until resolved. A fresh key is a new upload, not recovery.

`GET /api/sand-ai/v1/files/by-request-key?request_key=<original X-Request-Id>` has no request body and consumes existing `sand_ai.file.read`. Authentication/expected environment/trusted ownership and current SandIAM authorization are checked and revalidated before returning. Resolved records use the existing file-metadata fact resolver (including classification); pending records use the caller fact resolver and expose no file contents or locator. Success envelope `data` is either `{state: "pending", file: null}` or `{state: "resolved", file: <normal file metadata>}`. Pending means a committed association exists but upload completion is unproved; GET performs no write, object access, parse or retry. A missing association returns existing `SAND_AI_RESOURCE_NOT_FOUND`; it does not prove an older HTTP request never wrote a file. Wrong scope is indistinguishable from missing. A resolved deleted/deleting record is not usable input; clients must inspect file state.

Consumer SDK sequence: persist original key + inspected local input reference before sending; send that key as X-Request-Id; on response-loss/cleanup exception call this GET using a fresh trace header and the retained original key query. Persist the returned original file ID/ref only when its state is usable; pending permits an explicit same-byte/same-name POST with the original key. Never turn a transport-finally failure into a fresh-key retry. Close a PSR-7 stream with the stream's `close()` method, and do not let cleanup exceptions discard a successfully decoded response. This is a consumer integration contract, not a claim that an external SDK has been patched.

Migration159 only adds one nullable request digest column, a check, unique partial index and immutable association/input guard to the existing file table. Legacy rows retain NULL; inserting a key into an existing row is prohibited. Historical PDF28 has no association and cannot be recovered by this new route from its old key or matching hash. Pending files cannot parse/read sources; normal authorized deletion retains the association tombstone. Fresh install/update/uninstall carry the exact migration checksum; rollback never drops populated associations or rewrites old rows.


#### Existing upload UI integration (SOURCE v3)

The file center and MaterialSampleFiles both use `PrivateFileUpload.vue`, with an independent `UploadReceipt` TS type. Neither fabricates `SandAiFile.latest_parse` or timestamps from POST. After receiving the narrow receipt, the component uses the normal file.read metadata GET before adding a row to the materials list. A read denial retains the original upload receipt/key and does not send another POST.

`GET /api/sand-ai/v1/files/upload-context`, no query/body, uses the existing file.write action and current workload resolver with authorization revalidation. It returns only `{scope: <canonical trusted-identity SHA256>, environment_id: <trusted ID>}` with no-store. The scope includes the server's trusted org/app/env/workload canonical values and references, never user-supplied owner IDs or unverified JWT decoding. It remains stable across credential renewal for the same identity. This new read-only helper consumes ordinary IAM authorization/quota; it is not a new service action or a bypass.

The browser partitions one unresolved upload by current administrator ID and this authenticated opaque scope. SessionStorage contains only original request key, filename, byte length, SHA256, scope, environment and resolved file ID if known; no Bearer, File object, file body, private locator or secret is persisted. Original File objects exist only during the selected-file operation. Browser filename/size/digest are local replay guards; authoritative normalization/MIME/identity checks remain on the server. Missing or damaged browser records cannot prove an old request never ran; unknown records are not silently cleared. After reload the original bytes must be reselected.

The two pages provide Chinese original-upload recovery controls. Any unknown result disables a new upload in that scope. The control first GETs the retained key; only a positive pending response permits explicit re-selection of the same name/size/digest and POST with the original key. GET missing/refused/failed leaves the record and no new POST. A resolved deleted original is reported as deleted, never resurrected. Stale responses after identity/environment/admin changes are ignored; a new scope cannot read the old pending slot, while same-canonical renewed identity restores it normally. Resolving the upload into the list requires the existing read authorization; no ninth permission. Source integration is not a claim of executed UI acceptance.


#### Definitive new-upload validation refusal (SOURCE v4)

The shared UI rejects empty/invalid local file metadata before retaining anything or POSTing, then validates the entire frozen metadata record after hashing and before durable storage. Storage failure means no POST. A retained in-memory unknown whose storage record vanished or changed locks new submission rather than being replaced. No migration discards old damaged browser records because their send status is unproved.

Only a freshly generated key's first POST may clear its own still-matching unresolved browser record after a `SandAiRuntimeError` with HTTP400, business400, exact `SAND_AI_FILE_INVALID`, and response `request_id` exactly equal to that original POST's X-Request-Id. The API transport takes request_id from the actual server error envelope; it does not invent/copy it from the request on missing responses. The exception is handled immediately around that POST, not around subsequent file.read. Existing unknown/pending retries never enter this correction branch. Other 400/401/403, mismatched keys, network/timeout/5xx/cleanup or read errors preserve the original record. GET missing is never not-sent proof.

Source basis: FileController upload only raises FILE_INVALID for malformed multipart before FileGateway; FileUploadPolicy inspect (including FragmentedM4aAudioInspector) raises FILE_INVALID for type/name/size/MIME/hash before FileGateway's first intent transaction. The post-intent storage/transaction/read/revalidation path does not emit this code. Clearing the one freshly rejected record is a confirmed pre-intent correction, not a generic forget-unknown action. If removing/readback of that local record fails, the control retains in-memory uncertainty and blocks new submission.
