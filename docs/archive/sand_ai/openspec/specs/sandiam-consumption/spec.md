## Purpose

保证 SandAI 作为 SandIAM 的真实消费者能够使用其已发布身份和服务授权能力，清晰区分身份允许、执行授权及业务副作用。通过固定版本契约、负向行为与运行证据发现兼容性和易用性问题，反馈给 SandIAM 维护方。

## Requirements

### Requirement: Verified service context
SandAI MUST 固定 service_code=sand_ai 和 audience=sand-ai，使用当前动作、可信连接来源 IP 及审计请求号验证上下文。过期时间读取正式 exp；缺失字段、错误服务/受众/动作、过期或不可用均失败关闭。

#### Scenario: Current claims
- **WHEN** SandIAM 返回有效 exp 和匹配服务/受众/动作的身份
- **THEN** SandAI 接受正式响应，不要求提供不存在的 expires_at。

#### Scenario: Untrusted request
- **WHEN** 请求试图覆盖服务/受众，或提供伪造的转发 IP
- **THEN** 这些值不能改变服务端校验条件，无法验证时无业务副作用。

### Requirement: Live authorization and safe retries
SandAI MUST 在实际调用前使用 SandIAM 正式授权端口和服务端真实资源事实；撤权、网络、数据分级、配额及幂等冲突保持稳定错误语义。operation_id 标识业务尝试，request_id 关联审计。

SandAI入站MCP工具入口 MUST 复用现app service，并消费同一正式SandIAM身份与执行授权链；传输协议不得绕过当前动作、资源事实、四级归属及原请求授权重验，不建立平行IAM。服务端SDK按实际协议覆盖选择官方mcp/sdk0.8.1，同一入口支持handshake及2026 modern；具体合同、fields／action映射及正式IAM接线已随固定完整MCP SOURCE19/19交付，依据`tmp/sandai-mcp-server-source-20261008/integration/manifest.json`与同目录`SOURCE-DELIVERY-RESULT.json`；仅源码已交，不代表SDK安装、当前授权运行或业务通过。出站Neuron版本不作为入站服务端协议约束。

#### Scenario: Revoked idempotent replay
- **WHEN** 已成功请求在凭证或授权撤销后使用旧幂等键回放
- **THEN** 先拒绝当前访问，不返回旧业务结果或再次调用模型。

### Requirement: Queued authorization expiry
SandAI MUST 对异步原上下文加密保管，并在执行前以原授权记录和原上下文重验。超过有效期不得自行续签替换上下文或仅凭已保存身份 ID 放行。

#### Scenario: Expired queued context
- **WHEN** 队列任务开始时原 context 已过期
- **THEN** 返回明确失效状态且无 Provider 调用，重新发起任务须有新的合法业务意图。

### Requirement: Provider registration boundary
SandAI SHALL 声明自己的服务动作目录，管理者可预检和显式应用。不得自动创建组织、应用、客户端、凭证或服务授权；不得重新启用已停用动作。

#### Scenario: Disabled action during setup
- **WHEN** 开通向导遇到已停用的 SandAI 动作
- **THEN** 显示冲突及处理入口，不通过重新登记绕过管理员停用决定。
