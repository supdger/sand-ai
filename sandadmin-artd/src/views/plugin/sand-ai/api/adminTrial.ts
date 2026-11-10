/**
 * 管理员试用通道：后台登录会话换取 SandIAM 短期运行身份。
 * 响应只含签名 context（约 5 分钟），永不含调用凭证明文。
 */
import request from '@/utils/http'
import { ADMIN_API_PREFIX } from './config'

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

export default { requestAdminTrialContext, requestKnowledgeTrial }
