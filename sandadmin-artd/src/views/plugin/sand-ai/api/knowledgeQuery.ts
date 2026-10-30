import { runtimeRequest } from './runtime'
import { knowledgeSourceReference, type KnowledgeSourceReference } from './knowledgeSource'
import type { ChatInvocation } from './chat'
import type { SandAiInvocationCost } from './types'

export interface KnowledgeQueryCitation extends KnowledgeSourceReference {
  locator_type: string
  locator: Record<string, unknown>
  excerpt: string
  score: number
  rerank_relevance_score?: number
}

export type KnowledgeQueryRerank = { rerank: false } | { rerank: true; model: string; rerank_mode?: never } | { rerank: true; model: string; rerank_mode: 'professional' }
export type KnowledgeQueryRerankState = 'not_requested' | 'no_sources' | 'single_source' | 'reordered' | 'invalid_output'

export interface ProfessionalRerankModel { model: string; display_name: string; rerank_mode: 'professional' }

export interface KnowledgeQueryBudget {
  state: 'soft_cost_pending' | 'held' | 'settled' | 'released'
  warnings: { code: 'SAND_AI_BUDGET_COST_PENDING' }[]
}

export interface KnowledgeQueryResult {
  knowledge_base: { code: string; version: number }
  query: string
  citations: KnowledgeQueryCitation[]
  embedding_invocation: { id: number; state: 'succeeded' } | null
  embedding_usage: SandAiInvocationCost | null
  rerank_state: KnowledgeQueryRerankState
  rerank_mode?: 'professional'
  budget?: KnowledgeQueryBudget | null
  rerank_invocation: ChatInvocation | null
  rerank_usage: SandAiInvocationCost | null
}

function record(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function professionalBudget(value: unknown): value is KnowledgeQueryBudget | null {
  if (value === null) return true
  if (!record(value) || typeof value.state !== 'string' || !['soft_cost_pending', 'held', 'settled', 'released'].includes(value.state)
    || !Array.isArray(value.warnings)
    || !value.warnings.every((warning: unknown) => record(warning) && warning.code === 'SAND_AI_BUDGET_COST_PENDING')) return false
  const pending = value.state === 'soft_cost_pending' || value.state === 'held'
  return pending ? value.warnings.length > 0 : value.warnings.length === 0
}

export interface ProfessionalRerankMetering {
  unit: 'rerank_search_units' | 'rerank_tokens'
  quantity: string | null
}

export function professionalRerankMetering(value: unknown): ProfessionalRerankMetering | null {
  if (!record(value) || !record(value.usage)) return null
  const unit = value.usage.metering_unit
  const quantity = value.usage.metering_quantity
  if (unit !== 'rerank_search_units' && unit !== 'rerank_tokens') return null
  // Missing or invalid quantities remain unsettled; never infer a zero quantity.
  if (quantity === null || quantity === undefined) return { unit, quantity: null }
  if (typeof quantity !== 'string' || !/^[0-9]+(?:\.[0-9]+)?$/.test(quantity)) return null
  return { unit, quantity }
}

function queryResult(value: unknown, code: string, query: string, limit: number, options: KnowledgeQueryRerank): value is KnowledgeQueryResult {
  if (!record(value) || !record(value.knowledge_base)
    || value.knowledge_base.code !== code || value.query !== query.trim()
    || typeof value.knowledge_base.version !== 'number'
    || !Number.isSafeInteger(value.knowledge_base.version) || value.knowledge_base.version < 1
    || !Array.isArray(value.citations) || value.citations.length > limit) return false
  const professional = options.rerank && options.rerank_mode === 'professional'
  if (professional ? value.rerank_mode !== 'professional' || !professionalBudget(value.budget)
    : value.rerank_mode !== undefined || value.budget !== undefined) return false
  const version = value.knowledge_base.version
  const ids = new Set<number>()
  for (const citation of value.citations) {
    const reference = knowledgeSourceReference(citation)
    if (!record(citation) || !reference || reference.knowledge_base_code !== code
      || reference.knowledge_base_version !== version || ids.has(reference.source_block_id)
      || typeof citation.locator_type !== 'string' || !record(citation.locator)
      || typeof citation.excerpt !== 'string' || typeof citation.score !== 'number'
      || !Number.isFinite(citation.score)
      || (citation.rerank_relevance_score !== undefined && (!professional || typeof citation.rerank_relevance_score !== 'number' || !Number.isFinite(citation.rerank_relevance_score)))) return false
    ids.add(reference.source_block_id)
  }
  if (!['not_requested', 'no_sources', 'single_source', 'reordered', 'invalid_output'].includes(String(value.rerank_state))
    || (!options.rerank && value.rerank_state !== 'not_requested')
    || (options.rerank && value.rerank_state === 'not_requested')) return false
  if ((value.rerank_state === 'no_sources' && value.citations.length !== 0)
    || (value.rerank_state === 'single_source' && value.citations.length !== 1)) return false
  const invoked = value.rerank_state === 'reordered' || value.rerank_state === 'invalid_output'
  if (invoked) {
    if (value.citations.length < 2 || value.citations.length > 10) return false
    if (!record(value.rerank_invocation) || typeof value.rerank_invocation.request_id !== 'string'
      || !value.rerank_invocation.request_id || value.rerank_invocation.state !== 'succeeded'
      || !(value.rerank_invocation.latency_ms === null || (typeof value.rerank_invocation.latency_ms === 'number' && Number.isFinite(value.rerank_invocation.latency_ms)))
      || !(value.rerank_invocation.error_code === null || typeof value.rerank_invocation.error_code === 'string')
      || !record(value.rerank_usage)
      || value.rerank_usage.request_id !== value.rerank_invocation.request_id) return false
  } else if (value.rerank_invocation !== null || value.rerank_usage !== null) return false
  if (value.embedding_invocation === null) return value.embedding_usage === null
  return record(value.embedding_invocation)
    && typeof value.embedding_invocation.id === 'number'
    && Number.isSafeInteger(value.embedding_invocation.id) && value.embedding_invocation.id > 0
    && value.embedding_invocation.state === 'succeeded'
    && record(value.embedding_usage) && typeof value.embedding_usage.request_id === 'string'
    && value.embedding_usage.request_id.length > 0
}

async function readQuery(recover: boolean, apiKey: string, environmentId: number, code: string, query: string, limit: number, key: string, options: KnowledgeQueryRerank = { rerank: false }): Promise<KnowledgeQueryResult> {
    if (!Number.isSafeInteger(environmentId) || environmentId < 1
      || !/^[a-z][a-z0-9_-]{0,63}$/.test(code) || !Number.isInteger(limit) || limit < 1 || limit > 20) {
      throw new Error('查询环境、开放关系或返回数量无效。')
    }
    if (options.rerank && (limit > 10 || !options.model.trim())) throw new Error('重排需选择模型，最多返回 10 条。')
    if (options.rerank && options.rerank_mode !== undefined && options.rerank_mode !== 'professional') throw new Error('重排方式无效。')
    const result = await runtimeRequest<unknown>({
      method: 'POST', path: `/knowledge-bases/${encodeURIComponent(code)}/query${recover ? '/by-request-key' : ''}`, apiKey,
      data: { query, limit, ...options }, headers: {
        'Idempotency-Key': key,
        'X-Sand-AI-Environment-ID': String(environmentId),
        'Cache-Control': 'no-store'
      }
    })
    if (!queryResult(result, code, query, limit, options)) throw new Error('查询响应与原请求或来源版本不一致，结果尚未确认。')
    return result
}

export default {
  async professionalModels(apiKey: string, environmentId: number, code: string): Promise<ProfessionalRerankModel[]> {
    if (!Number.isSafeInteger(environmentId) || environmentId < 1 || !/^[a-z][a-z0-9_-]{0,63}$/.test(code)) throw new Error('请选择当前消费环境的知识库。')
    const value = await runtimeRequest<unknown>({
      method: 'GET', path: `/knowledge-bases/${encodeURIComponent(code)}/rerank-models`, apiKey,
      headers: { 'X-Sand-AI-Environment-ID': String(environmentId), 'Cache-Control': 'no-store' }
    })
    if (!record(value) || !Array.isArray(value.data)) throw new Error('专业重排目录响应无效。')
    const models: ProfessionalRerankModel[] = []
    const codes = new Set<string>()
    for (const item of value.data) {
      if (!record(item) || typeof item.model !== 'string' || !item.model || typeof item.display_name !== 'string'
        || !item.display_name || item.rerank_mode !== 'professional' || codes.has(item.model)) throw new Error('专业重排目录字段无效。')
      codes.add(item.model)
      models.push({ model: item.model, display_name: item.display_name, rerank_mode: 'professional' })
    }
    return models
  },
  query(apiKey: string, environmentId: number, code: string, query: string, limit: number, key: string, options: KnowledgeQueryRerank = { rerank: false }): Promise<KnowledgeQueryResult> {
    return readQuery(false, apiKey, environmentId, code, query, limit, key, options)
  },
  recover(apiKey: string, environmentId: number, code: string, query: string, limit: number, key: string, options: KnowledgeQueryRerank = { rerank: false }): Promise<KnowledgeQueryResult> {
    return readQuery(true, apiKey, environmentId, code, query, limit, key, options)
  }
}
