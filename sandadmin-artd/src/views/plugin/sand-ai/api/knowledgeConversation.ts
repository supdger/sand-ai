import { runtimeRequest, SandAiRuntimeError } from './runtime'
import { RUNTIME_API_PREFIX } from './config'
import type { SandAiInvocationCost } from './types'

export interface KnowledgeConversationSummary {
  conversation_id: string
  knowledge_base_version_id: number
  state: 'active' | 'closed'
  create_time: string
  update_time: string
}

export interface KnowledgeAnswer {
  answer_state: 'answered' | 'needs_review' | 'no_sources' | 'cancelled' | 'failed'
  answer: string | null
  citations: Array<{
    source_block_id: number
    excerpt: string
    file_id: number
    locator_type: string
    locator: Record<string, unknown>
    // Current answers preserve these source fields; missing historical fields cannot be guessed.
    knowledge_base_code?: string
    knowledge_base_version?: number
    parse_id?: number
  }>
  review_required: boolean
  error_code?: string
  invocation: { request_id: string } | null
  rerank_state?: 'not_requested' | 'no_sources' | 'single_source' | 'reordered' | 'invalid_output'
  rerank_invocation?: { request_id: string } | null
  rerank_usage?: Record<string, unknown> | null
  summary_invocations?: Array<{ request_id: string }>
  summary_usage?: Array<Record<string, unknown>>
  idempotent_replay?: boolean
  context_coverage?: {
    earlier_question_sequences: number[]
    earlier_answer_excerpt_sequences?: number[]
    recent_answered_sequences: number[]
    has_omitted_earlier_answered: boolean
    omitted_answered_count?: number
    summary_text?: string | null
    summary_through_sequence_no?: number
    summary_answered_count?: number
    summary_state?: 'current' | 'partial' | 'invalid_output'
    summary_used_for_model?: boolean
    used_for_model: boolean
  }
}

export interface KnowledgeTurn {
  sequence_no: number
  query: string
  state: string
  execution_state: string
  stream_segments: boolean
  result: KnowledgeAnswer | null
}

export interface KnowledgeSegment {
  conversation_id: string
  sequence_no: number
  segment_no: number
  text: string
  source_block_ids: number[]
  provisional: true
}

export interface KnowledgeTurnLookup {
  sequence_no: number
  state: string
  stream_segments: boolean
}

export class KnowledgeStreamConnectionError extends Error {
  constructor() {
    super('段落连接已断开，可继续查看本轮已生成内容。')
    this.name = 'KnowledgeStreamConnectionError'
  }
}

export interface KnowledgeConversation extends KnowledgeConversationSummary {
  turns: KnowledgeTurn[]
  next_before_sequence: number | null
}

const path = (code: string) => `/knowledge-bases/${encodeURIComponent(code)}/conversations`
const conversationPath = (code: string, id: string) => `${path(code)}/${encodeURIComponent(id)}`

function object(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}
function positiveInteger(value: unknown): value is number {
  return typeof value === 'number' && Number.isSafeInteger(value) && value > 0
}
function isInvocation(value: unknown): value is { request_id: string } {
  return object(value) && typeof value.request_id === 'string' && value.request_id.length > 0
}
function sequences(value: unknown): value is number[] {
  return Array.isArray(value) && value.every(positiveInteger)
}
function isCoverage(value: unknown): boolean {
  return object(value) && sequences(value.earlier_question_sequences) && sequences(value.recent_answered_sequences)
    && (value.earlier_answer_excerpt_sequences === undefined || sequences(value.earlier_answer_excerpt_sequences))
    && typeof value.has_omitted_earlier_answered === 'boolean' && typeof value.used_for_model === 'boolean'
    && (value.summary_text === undefined || value.summary_text === null || typeof value.summary_text === 'string')
    && (value.summary_state === undefined || ['current', 'partial', 'invalid_output'].includes(String(value.summary_state)))
    && ['omitted_answered_count', 'summary_through_sequence_no', 'summary_answered_count'].every(key =>
      value[key] === undefined || (typeof value[key] === 'number' && Number.isSafeInteger(value[key]) && value[key] >= 0))
    && (value.summary_used_for_model === undefined || typeof value.summary_used_for_model === 'boolean')
}
function isAnswer(value: unknown): value is KnowledgeAnswer {
  return object(value) && ['answered', 'needs_review', 'no_sources', 'cancelled', 'failed'].includes(String(value.answer_state))
    && (value.answer === null || typeof value.answer === 'string')
    && typeof value.review_required === 'boolean'
    && (value.error_code === undefined || (typeof value.error_code === 'string' && value.error_code.length > 0))
    && (value.answer_state !== 'failed' || (typeof value.error_code === 'string' && value.error_code.length > 0))
    && (value.invocation === null || isInvocation(value.invocation))
    && (value.rerank_invocation === undefined || value.rerank_invocation === null || isInvocation(value.rerank_invocation))
    && (value.summary_invocations === undefined || (Array.isArray(value.summary_invocations) && value.summary_invocations.every(isInvocation)))
    && (value.context_coverage === undefined || isCoverage(value.context_coverage))
    && Array.isArray(value.citations) && value.citations.every(citation => object(citation)
      && positiveInteger(citation.source_block_id) && positiveInteger(citation.file_id)
      && typeof citation.excerpt === 'string' && typeof citation.locator_type === 'string' && object(citation.locator)
      && (citation.knowledge_base_code === undefined || typeof citation.knowledge_base_code === 'string')
      && (citation.knowledge_base_version === undefined || positiveInteger(citation.knowledge_base_version))
      && (citation.parse_id === undefined || positiveInteger(citation.parse_id)))
}
function isTurn(value: unknown): value is KnowledgeTurn {
  return object(value) && positiveInteger(value.sequence_no) && typeof value.query === 'string'
    && typeof value.state === 'string' && typeof value.execution_state === 'string'
    && typeof value.stream_segments === 'boolean' && (value.result === null || isAnswer(value.result))
}
function isSummary(value: unknown): value is KnowledgeConversationSummary {
  return object(value) && typeof value.conversation_id === 'string' && value.conversation_id.length > 0
    && positiveInteger(value.knowledge_base_version_id) && ['active', 'closed'].includes(String(value.state))
    && typeof value.create_time === 'string' && typeof value.update_time === 'string'
}
function requireSummary(value: unknown, id?: string): KnowledgeConversationSummary {
  if (!isSummary(value) || (id !== undefined && value.conversation_id !== id)) throw new Error('原会话响应目标或形状无效')
  return value
}
function requireConversation(value: unknown, id: string): KnowledgeConversation {
  if (!isSummary(value) || value.conversation_id !== id || !object(value)
    || !Array.isArray(value.turns) || !value.turns.every(isTurn)
    || (value.next_before_sequence !== null && !positiveInteger(value.next_before_sequence))) {
    throw new Error('原会话响应目标或形状无效')
  }
  const sequences = value.turns.map(turn => turn.sequence_no)
  if (new Set(sequences).size !== sequences.length
    || sequences.some((sequence, index) => index > 0 && sequence <= sequences[index - 1])) throw new Error('原会话轮次无效')
  return { ...requireSummary(value, id), turns: value.turns, next_before_sequence: value.next_before_sequence }
}
function isTurnEvent(value: unknown, conversationId: string, sequenceNo: number): value is { conversation_id: string; turn: KnowledgeTurn } {
  return object(value) && value.conversation_id === conversationId && isTurn(value.turn) && value.turn.sequence_no === sequenceNo
}

function isSegmentEvent(value: unknown, conversationId: string, sequenceNo: number, eventId: string): value is KnowledgeSegment {
  if (typeof value !== 'object' || value === null) return false
  const segmentNo = 'segment_no' in value ? value.segment_no : null
  if (!('conversation_id' in value) || value.conversation_id !== conversationId
    || !('sequence_no' in value) || value.sequence_no !== sequenceNo
    || typeof segmentNo !== 'number' || !Number.isSafeInteger(segmentNo) || segmentNo < 1
    || eventId !== String(segmentNo)
    || !('text' in value) || typeof value.text !== 'string' || !value.text || Array.from(value.text).length > 1000
    || !/[。？！.?!]$/.test(value.text)
    || !('source_block_ids' in value) || !Array.isArray(value.source_block_ids)
    || !value.source_block_ids.every(id => Number.isSafeInteger(id) && id > 0)
    || !('provisional' in value) || value.provisional !== true) return false
  return true
}

async function streamTurn(
  apiKey: string, code: string, id: string, sequenceNo: number,
  signal: AbortSignal, onTurn: (turn: KnowledgeTurn) => void,
  onSegment: (segment: KnowledgeSegment) => void, lastSegmentNo = 0
): Promise<void> {
  if (!Number.isSafeInteger(lastSegmentNo) || lastSegmentNo < 0) throw new Error('段落续传游标无效')
  const response = await fetch(
    `${RUNTIME_API_PREFIX}${conversationPath(code, id)}/turns/${sequenceNo}/events`,
    {
      headers: {
        Authorization: `Bearer ${apiKey.trim()}`, Accept: 'text/event-stream',
        'Last-Event-ID': String(lastSegmentNo)
      },
      signal, cache: 'no-store'
    }
  )
  if (!response.ok) {
    throw new SandAiRuntimeError({
      message: `会话状态订阅失败（HTTP ${response.status}）`,
      httpStatus: response.status, businessCode: response.status,
      errorCode: null, requestId: null, retryable: false
    })
  }
  if (!response.headers.get('content-type')?.startsWith('text/event-stream') || !response.body) {
    throw new Error('会话状态订阅响应无效')
  }
  const reader = response.body.getReader()
  const decoder = new TextDecoder()
  let buffer = ''
  try {
    while (true) {
      const { value, done } = await reader.read()
      if (done) throw new KnowledgeStreamConnectionError()
      buffer = (buffer + decoder.decode(value, { stream: true })).replace(/\r\n/g, '\n')
      if (buffer.length > 262144) throw new Error('会话状态事件过大')
      let boundary = buffer.indexOf('\n\n')
      while (boundary >= 0) {
        const frame = buffer.slice(0, boundary)
        buffer = buffer.slice(boundary + 2)
        let event = ''
        let eventId = ''
        const data: string[] = []
        for (const line of frame.split('\n')) {
          if (line.startsWith('event: ')) event = line.slice(7)
          if (line.startsWith('id: ')) eventId = line.slice(4)
          if (line.startsWith('data: ')) data.push(line.slice(6))
        }
        if (event === 'turn') {
          const payload: unknown = JSON.parse(data.join('\n'))
          if (!isTurnEvent(payload, id, sequenceNo)) throw new Error('会话状态事件无效')
          onTurn(payload.turn)
          if (payload.turn.state !== 'pending'
            || ['outcome_unknown', 'recovery_required'].includes(payload.turn.execution_state)) return
        } else if (event === 'segment') {
          const payload: unknown = JSON.parse(data.join('\n'))
          if (!isSegmentEvent(payload, id, sequenceNo, eventId)) throw new Error('会话段落事件无效')
          if (payload.segment_no > lastSegmentNo) {
            if (payload.segment_no !== lastSegmentNo + 1) throw new Error('会话段落序号不连续')
            lastSegmentNo = payload.segment_no
            onSegment(payload)
          }
        } else if (event === 'error') {
          const payload: unknown = JSON.parse(data.join('\n'))
          const code = typeof payload === 'object' && payload !== null && 'error_code' in payload
            && typeof payload.error_code === 'string' ? payload.error_code : 'SAND_AI_CONVERSATION_UNAVAILABLE'
          throw new SandAiRuntimeError({
            message: `会话状态订阅中止：${code}`,
            httpStatus: 0, businessCode: 0, errorCode: code,
            requestId: null, retryable: false
          })
        } else if (event === 'reconnect') {
          return
        }
        boundary = buffer.indexOf('\n\n')
      }
    }
  } finally {
    await reader.cancel().catch(() => undefined)
  }
}

export default {
  streamTurn,
  list(apiKey: string, code: string, before?: string) {
    return runtimeRequest<{ conversations: KnowledgeConversationSummary[]; next_before: string | null }>({
      method: 'GET', path: path(code), apiKey, params: before ? { before } : undefined
    })
  },
  create(apiKey: string, code: string, key: string) {
    return runtimeRequest<KnowledgeConversationSummary>({
      method: 'POST', path: path(code), apiKey, data: {}, headers: { 'Idempotency-Key': key }
    })
  },
  async readCreated(apiKey: string, code: string, key: string) {
    return requireSummary(await runtimeRequest<unknown>({
      method: 'GET', path: `${path(code)}/by-request-key`, apiKey,
      headers: { 'Idempotency-Key': key }
    }))
  },
  async read(apiKey: string, code: string, id: string, before?: number) {
    const result = requireConversation(await runtimeRequest<unknown>({
      method: 'GET', path: conversationPath(code, id), apiKey,
      params: before ? { before } : undefined
    }), id)
    if (before !== undefined && result.turns.some(turn => turn.sequence_no >= before)) throw new Error('原轮次分页响应无效')
    return result
  },
  async findTurnByRequestKey(apiKey: string, code: string, id: string, key: string) {
    // The real lookup DTO has no conversation/key echo. The GET route fixes those targets;
    // recovery also requires the exact returned sequence to exist in the same conversation GET.
    const value = await runtimeRequest<unknown>({
      method: 'GET', path: `${conversationPath(code, id)}/turns/by-request-key`, apiKey,
      headers: { 'Idempotency-Key': key }
    })
    if (!object(value) || !positiveInteger(value.sequence_no) || typeof value.state !== 'string'
      || typeof value.stream_segments !== 'boolean') throw new Error('原轮次定位响应无效')
    return { sequence_no: value.sequence_no, state: value.state, stream_segments: value.stream_segments }
  },
  answer(apiKey: string, code: string, id: string, query: string, model: string, rerank: boolean, streamSegments: boolean, key: string) {
    return runtimeRequest<KnowledgeAnswer>({
      method: 'POST', path: `${conversationPath(code, id)}/answer`, apiKey,
      data: { query, model, rerank, stream_segments: streamSegments }, headers: { 'Idempotency-Key': key }
    })
  },
  resume(apiKey: string, code: string, id: string, sequenceNo: number) {
    return runtimeRequest<KnowledgeAnswer>({
      method: 'POST', path: `${conversationPath(code, id)}/turns/${sequenceNo}/resume`,
      apiKey, data: {}
    })
  },
  cancel(apiKey: string, code: string, id: string, sequenceNo: number) {
    return runtimeRequest<{ sequence_no: number; answer_state: 'cancelled'; idempotent_replay: boolean }>({
      method: 'POST', path: `${conversationPath(code, id)}/turns/${sequenceNo}/cancel`,
      apiKey, data: {}
    })
  },
  close(apiKey: string, code: string, id: string) {
    return runtimeRequest<KnowledgeConversationSummary>({
      method: 'POST', path: `${conversationPath(code, id)}/close`, apiKey, data: {}
    })
  },
  cost(apiKey: string, requestId: string) {
    return runtimeRequest<SandAiInvocationCost>({
      method: 'GET', path: `/invocations/${encodeURIComponent(requestId)}/cost`, apiKey
    })
  }
}
