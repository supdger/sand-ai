import { runtimeRequest } from './runtime'
import type { SandAiInvocationCost } from './types'

export type ChatModel = {
  id: number
  code: string
  name: string
  type: string
  capabilities: string[]
}

export type ChatInvocation = {
  request_id: string
  state: string
  latency_ms: number | null
  error_code: string | null
}

export type ChatCompletion = {
  invocation: ChatInvocation
  invocation_id: number
  content: string
  usage: Record<string, unknown>
  idempotent_replay: boolean
  selected_model?: string
}

export type ChatPayload = {
  model: string
  messages: { role: 'user'; content: string }[]
  max_output_tokens: number
}

function record(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null
}
function nullableString(value: unknown): value is string | null {
  return value === null || typeof value === 'string'
}
function isInvocation(value: unknown): value is ChatInvocation {
  return record(value) && typeof value.request_id === 'string' && value.request_id.length > 0
    && typeof value.state === 'string' && value.state.length > 0
    && (value.latency_ms === null || (typeof value.latency_ms === 'number' && Number.isFinite(value.latency_ms)))
    && nullableString(value.error_code)
}

export default {
  async models(apiKey: string): Promise<{ data: ChatModel[] }> {
    const value = await runtimeRequest<unknown>({ method: 'GET', path: '/models', apiKey })
    if (!record(value) || !Array.isArray(value.data)) throw new Error('模型目录响应无效')
    const data: ChatModel[] = []
    for (const model of value.data) {
      if (!record(model) || typeof model.id !== 'number' || !Number.isSafeInteger(model.id)
        || model.id < 1 || typeof model.code !== 'string' || !model.code
        || typeof model.name !== 'string' || typeof model.type !== 'string'
        || !Array.isArray(model.capabilities) || !model.capabilities.every((item: unknown) => typeof item === 'string'))
        throw new Error('模型目录字段无效')
      data.push({ id: model.id, code: model.code, name: model.name, type: model.type, capabilities: model.capabilities })
    }
    return { data }
  },
  async complete(apiKey: string, requestKey: string, data: ChatPayload): Promise<ChatCompletion> {
    const value = await runtimeRequest<unknown>({
      method: 'POST', path: '/chat/completions', apiKey, data,
      headers: { 'Idempotency-Key': requestKey }
    })
    if (!record(value) || !isInvocation(value.invocation)
      || typeof value.invocation_id !== 'number' || !Number.isSafeInteger(value.invocation_id) || value.invocation_id < 1
      || typeof value.content !== 'string' || !record(value.usage) || typeof value.idempotent_replay !== 'boolean'
      || (value.selected_model !== undefined && typeof value.selected_model !== 'string'))
      throw new Error('聊天结果字段无效，需只读找回原请求')
    return {
      invocation: value.invocation, invocation_id: value.invocation_id, content: value.content,
      usage: value.usage, idempotent_replay: value.idempotent_replay,
      selected_model: value.selected_model
    }
  },
  async findByRequestKey(apiKey: string, requestKey: string): Promise<ChatInvocation> {
    const value = await runtimeRequest<unknown>({
      method: 'GET', path: '/chat/completions/by-request-key', apiKey,
      params: { request_key: requestKey }
    })
    if (!isInvocation(value)) throw new Error('调用摘要字段无效，原请求仍未确认')
    return value
  },
  async cost(apiKey: string, requestId: string): Promise<SandAiInvocationCost> {
    const value = await runtimeRequest<SandAiInvocationCost>({
      method: 'GET', path: `/invocations/${encodeURIComponent(requestId)}/cost`, apiKey
    })
    if (!record(value) || value.request_id !== requestId || !nullableString(value.model)
      || !record(value.price) || !record(value.usage) || typeof value.usage.state !== 'string'
      || !nullableString(value.price.estimated_amount) || !nullableString(value.price.metered_amount)
      || !nullableString(value.price.currency)
      || (value.price.revision_id !== null && (typeof value.price.revision_id !== 'number' || !Number.isSafeInteger(value.price.revision_id)))
      || !['priced', 'unpriced'].includes(value.price.pricing_state))
      throw new Error('费用记录与当前请求不一致或字段无效')
    return value
  }
}
