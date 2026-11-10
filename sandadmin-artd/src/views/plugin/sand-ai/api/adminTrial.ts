/**
 * 管理员试用通道：后台登录会话换取 SandIAM 短期运行身份。
 * 响应只含签名 context（约 5 分钟），永不含调用凭证明文。
 *
 * 0.5.0：智能体「试一下」走 /trial/agent 与 /trial/agent/task。
 * 后端用受管试用身份发起真实任务，计费、预算与引用口径和正式调用一致，
 * 调用记录标记「试用」；前端不接触任何调用密钥。
 */
import request from '@/utils/http'
import { ADMIN_API_PREFIX } from './config'
import type { SandAiSourceReference, SandAiTaskResult, SandAiTaskToolCall } from './types'

export interface AdminTrialContext {
  context: string
  expire_time: string
  actions: string[]
}

export async function requestAdminTrialContext(actions?: string[]): Promise<AdminTrialContext> {
  return request.post<AdminTrialContext>({
    url: `${ADMIN_API_PREFIX}/trial/context`,
    data: actions === undefined ? {} : { actions },
    showErrorMessage: false,
    headers: { 'Cache-Control': 'no-store' }
  })
}

/** 知识库试查命中的一条资料摘录；只保留界面需要的字段。 */
export interface KnowledgeTrialCitation {
  excerpt: string
  locator_type?: string
  locator?: Record<string, unknown>
}

export interface KnowledgeTrialResult {
  query: string
  citations: KnowledgeTrialCitation[]
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function isKnowledgeTrialResult(value: unknown): value is KnowledgeTrialResult {
  if (!isRecord(value) || typeof value.query !== 'string' || !Array.isArray(value.citations)) return false
  return value.citations.every((item) => isRecord(item) && typeof item.excerpt === 'string')
}

/**
 * 0.5.0：知识库「试一下」。走托管试用身份计费，前端只传知识库号、问题和返回条数，
 * 不接触任何调用凭证；每次调用带独立幂等键。
 */
export async function requestKnowledgeTrial(input: {
  knowledgeBaseId: number
  query: string
  limit?: number
}): Promise<KnowledgeTrialResult> {
  const result: unknown = await request.post<unknown>({
    url: `${ADMIN_API_PREFIX}/trial/knowledge`,
    data: {
      knowledge_base_id: input.knowledgeBaseId,
      query: input.query,
      ...(input.limit === undefined ? {} : { limit: input.limit })
    },
    showErrorMessage: false,
    headers: {
      'Cache-Control': 'no-store',
      'Idempotency-Key': `knowledge-trial-${crypto.randomUUID()}`
    }
  })
  if (!isKnowledgeTrialResult(result)) {
    throw new Error('试查结果格式不完整，请稍后重试')
  }
  return {
    query: result.query,
    citations: result.citations.map((item) => ({
      excerpt: item.excerpt,
      ...(typeof item.locator_type === 'string' ? { locator_type: item.locator_type } : {}),
      ...(isRecord(item.locator) ? { locator: item.locator } : {})
    }))
  }
}

/** 智能体试用投影：后端只回安全字段，output/source/invocation 均可为空。 */
export interface AgentTrialResult {
  readonly task_id: number
  readonly state: string
  readonly result: {
    readonly output?: { readonly content?: string } | null
    readonly source_references?: readonly SandAiSourceReference[] | null
    readonly tool_calls?: readonly SandAiTaskToolCall[] | null
    readonly invocation?: SandAiTaskResult['invocation'] | null
    readonly review_required?: boolean | null
  } | null
  readonly error_code?: string | null
}

export interface AgentTrialInput {
  readonly application_id: number
  readonly agent_id: number
  readonly query: string
  readonly conversation_id?: string
}

/** 终态与 TaskState::terminal 保持一致；其余状态由页面继续轮询。 */
export function isAgentTrialTerminal(state: string): boolean {
  return state === 'succeeded' || state === 'failed' || state.startsWith('cancel')
}

/**
 * 发起智能体试用。每次用户主动发送使用新 Idempotency-Key；
 * 同一请求的不确定重试必须复用同键同载荷，禁止换键重发。
 * 服务端最长原地等待约 25 秒，仍未完成时返回非终态供轮询。
 */
export async function requestAgentTrial(
  input: AgentTrialInput,
  idempotencyKey: string
): Promise<AgentTrialResult> {
  return request.post<AgentTrialResult>({
    url: `${ADMIN_API_PREFIX}/trial/agent`,
    data: {
      application_id: input.application_id,
      agent_id: input.agent_id,
      query: input.query,
      ...(input.conversation_id === undefined ? {} : { conversation_id: input.conversation_id })
    },
    timeout: 60000,
    headers: { 'Idempotency-Key': idempotencyKey, 'Cache-Control': 'no-store' },
    showErrorMessage: false
  })
}

/** 轮询试用任务投影；只读，不会重新发起调用。 */
export async function requestAgentTrialTask(taskId: number): Promise<AgentTrialResult> {
  return request.get<AgentTrialResult>({
    url: `${ADMIN_API_PREFIX}/trial/agent/task`,
    params: { task_id: taskId },
    headers: { 'Cache-Control': 'no-store' },
    showErrorMessage: false
  })
}

export default {
  requestAdminTrialContext,
  requestKnowledgeTrial,
  requestAgentTrial,
  requestAgentTrialTask
}
