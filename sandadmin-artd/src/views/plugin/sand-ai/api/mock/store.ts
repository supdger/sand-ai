import type {
  SandAiAuditLog,
  SandAiInvocation,
  SandAiModel,
  SandAiProvider,
  SandAiUsage
} from '../types'
import { nextId, nowText } from './helpers'

const stamp = nowText()

export const mockProviders: SandAiProvider[] = [
  {
    id: nextId(),
    code: 'openai-compatible',
    name: 'OpenAI 兼容示意',
    adapter: 'openai_compatible',
    timeout_ms: 30000,
    has_encrypted_config: true,
    status: 1,
    create_time: stamp,
    update_time: stamp
  },
  {
    id: nextId(),
    code: 'private-llm',
    name: '私有化推理示意',
    adapter: 'openai_compatible',
    timeout_ms: 60000,
    has_encrypted_config: false,
    status: 1,
    create_time: stamp,
    update_time: stamp
  }
]

export const mockModels: SandAiModel[] = [
  {
    id: nextId(),
    code: 'chat-general',
    name: '通用对话模型',
    type: 'chat',
    capabilities: ['chat', 'tool_call'],
    status: 1,
    create_time: stamp,
    update_time: stamp
  },
  {
    id: nextId(),
    code: 'embed-default',
    name: '默认嵌入模型',
    type: 'embedding',
    capabilities: ['embedding'],
    status: 1,
    create_time: stamp,
    update_time: stamp
  },
  {
    id: nextId(),
    code: 'chat-long',
    name: '长文本模型',
    type: 'chat',
    capabilities: ['chat'],
    status: 2,
    create_time: stamp,
    update_time: stamp
  }
]

export const mockInvocations: SandAiInvocation[] = [
  {
    id: nextId(),
    request_id: 'req_demo_001',
    environment_id: 1,
    model_id: 1,
    deployment_id: 1,
    state: 'succeeded',
    latency_ms: 820,
    error_code: null,
    error_summary: null,
    request_at: stamp,
    completed_at: stamp,
    status: 1,
    create_time: stamp,
    update_time: stamp
  },
  {
    id: nextId(),
    request_id: 'req_demo_002',
    environment_id: 1,
    model_id: 1,
    deployment_id: 1,
    state: 'failed',
    latency_ms: 120,
    error_code: 'PROVIDER_TIMEOUT',
    error_summary: '上游超时示意',
    request_at: stamp,
    completed_at: stamp,
    status: 1,
    create_time: stamp,
    update_time: stamp
  },
  {
    id: nextId(),
    request_id: 'req_demo_003',
    environment_id: 2,
    model_id: 2,
    deployment_id: null,
    state: 'running',
    latency_ms: null,
    error_code: null,
    error_summary: null,
    request_at: stamp,
    completed_at: null,
    status: 1,
    create_time: stamp,
    update_time: stamp
  }
]

export const mockUsages: SandAiUsage[] = [
  {
    id: nextId(),
    invocation_id: mockInvocations[0]?.id ?? 1,
    input_tokens: 120,
    output_tokens: 340,
    total_tokens: 460,
    cost_amount: '0.012000',
    currency: 'CNY',
    source: 'chat.completions',
    status: 1,
    create_time: stamp,
    update_time: stamp
  },
  {
    id: nextId(),
    invocation_id: mockInvocations[1]?.id ?? 2,
    input_tokens: 80,
    output_tokens: 0,
    total_tokens: 80,
    cost_amount: '0.001000',
    currency: 'CNY',
    source: 'chat.completions',
    status: 1,
    create_time: stamp,
    update_time: stamp
  }
]

export const mockAudits: SandAiAuditLog[] = [
  {
    id: nextId(),
    actor_type: 'admin',
    actor_ref: 'admin',
    action: 'application.create',
    resource_type: 'application',
    resource_id: 1,
    summary: '创建应用 律序办案助手',
    status: 1,
    create_time: stamp,
    update_time: stamp
  },
  {
    id: nextId(),
    actor_type: 'admin',
    actor_ref: 'admin',
    action: 'provider.update',
    resource_type: 'provider',
    resource_id: 1,
    summary: '更新服务商超时配置',
    status: 1,
    create_time: stamp,
    update_time: stamp
  },
  {
    id: nextId(),
    actor_type: 'system',
    actor_ref: 'scheduler',
    action: 'model.publish',
    resource_type: 'model',
    resource_id: 1,
    summary: '发布模型通用对话模型',
    status: 1,
    create_time: stamp,
    update_time: stamp
  }
]
