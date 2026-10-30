# SandAI MCP 服务契约

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


### 已冻结请求帧与header（未运行示例）

两代均 `POST /api/sand-ai/mcp`，请求header为 `Authorization: Bearer <SandIAM工作负载调用上下文>`、`Content-Type: application/json`、`Accept: application/json, text/event-stream`。此处仅占位符，不提供或记录真实凭据。

现代请求可带 `Mcp-Protocol-Version: 2026-07-28`，但header单独不足以选择现代协议；每个body的params._meta必须声明 `io.modelcontextprotocol/protocolVersion` 和 `io.modelcontextprotocol/clientCapabilities`，不带会话。发现示例：

```json
{"jsonrpc":"2.0","id":1,"method":"server/discover","params":{"_meta":{"io.modelcontextprotocol/protocolVersion":"2026-07-28","io.modelcontextprotocol/clientCapabilities":{}}}}
```

列工具将method改为`tools/list`，其余同。只读任务调用示例（123必须替换为该caller已有权读取的真实任务整数ID，不是业务幂等键）：

```json
{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"sand_ai_task_read","arguments":{"task_id":123},"_meta":{"io.modelcontextprotocol/protocolVersion":"2026-07-28","io.modelcontextprotocol/clientCapabilities":{}}}}
```

握手时代先initialize：

```json
{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"sandai-caller","version":"1.0.0"}}}
```

取得响应`Mcp-Session-Id`后，每次带原Bearer、该session及`Mcp-Protocol-Version: 2025-11-25`，发送`{"jsonrpc":"2.0","method":"notifications/initialized"}`，再用`{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}`发现工具；tools/call同上name/arguments，去掉_meta。可能计费工具须按固定schema传原业务request key，恢复工具只读；会话和JSON-RPC id不代替业务号。

动作映射：task_read→sand_ai.task.read；workflow_source→sand_ai.workflow.read；audio_review_read→sand_ai.audio.transcription＋sand_ai.audio.review；knowledge_query/recover→sand_ai.knowledge.query；skill_run/recover→sand_ai.skill.run；tool_run→sand_ai.tool.run、tool_recover→sand_ai.tool.read。表述中的工具均带`sand_ai_`前缀，每次执行仍校验实际资源授权。

## 工具参数的 JSON 对象形状

`tools/call` 的 `arguments` 与已声明为 object 的业务输入必须是 JSON 对象；
空对象 `{}` 合法，列表 `[]`（含空列表）不作为对象接受。入站适配器只在当前
HTTP 请求内按原 method、JSON-RPC id、工具名及原参数匹配并保留对象形状，
不会替换身份、session 或 `_meta`。SDK 的正式 `CallToolHandler` 与
`SchemaValidator` 仍在业务回调前校验完整固定 Schema；已发布工具的业务
input_schema、当前资源授权和费用规则仍由原 Controller/Gateway 执行。
形状信息不进入协议 session、日志或持久化；握手 batch 仅沿 SDK 原支持范围
逐项匹配，现代 batch 的拒绝规则不变。
