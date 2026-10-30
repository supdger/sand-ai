import type { SandAiInvocationCost, SandAiTask, SandAiTaskSubmitResult } from './types'
import type { AgentReadonlyToolSelection } from './agentDefinition'
import { runtimeRequest } from './runtime'

export type AgentSubmitPayload = {
  model: string
  instructions: string
  query: string
  readonly_tools?: readonly AgentReadonlyToolSelection[]
  max_output_tokens?: number
  tools?: string[]
  review_required?: boolean
}

export type AgentSourceContent = {
  task_id: number
  source_block_id: number
  file_id: number
  locator_type: string
  locator: Record<string, unknown>
  content: string
  reference_kind: 'result' | 'stream_preview'
  event_no: number | null
}

export type AgentConversationTask = SandAiTask & {
  readonly conversation_context?: {
    readonly conversation_id: string
    readonly history_task_ids: readonly number[]
    readonly omitted_prior_turn_count: number
    readonly context_turn_limit: number
  }
}

export type AgentConversationPage = {
  conversation_id: string
  tasks: AgentConversationTask[]
  next_before_task_id: number | null
}

export default {
  conversation(apiKey: string, code: string, conversationId: string, before?: number) {
    return runtimeRequest<AgentConversationPage>({
      method: 'GET', path: `/agents/${encodeURIComponent(code)}/conversations/${encodeURIComponent(conversationId)}/tasks`,
      apiKey, params: { limit: 20, ...(before ? { before_task_id: before } : {}) }
    })
  },
  source(apiKey: string, taskId: number, blockId: number) {
    return runtimeRequest<AgentSourceContent>({
      method: 'GET', path: `/agents/tasks/${taskId}/sources/${blockId}`, apiKey
    })
  },
  submitPublished(apiKey: string, code: string, query: string, requestId: string, conversationId?: string) {
    return runtimeRequest<SandAiTaskSubmitResult>({
      method: 'POST', path: `/agents/${encodeURIComponent(code)}/runs`, apiKey,
      data: { query, ...(conversationId ? { conversation_id: conversationId } : {}) }, headers: { 'X-Request-Id': requestId, 'Idempotency-Key': requestId }
    })
  },
  readAdHocByRequestKey(apiKey: string, requestId: string) {
    return runtimeRequest<SandAiTask>({
      method: 'GET', path: '/agents/runs/by-request-key', apiKey, headers: { 'Idempotency-Key': requestId }
    })
  },
  readPublishedByRequestKey(apiKey: string, code: string, requestId: string) {
    return runtimeRequest<SandAiTask>({
      method: 'GET', path: `/agents/${encodeURIComponent(code)}/runs/by-request-key`, apiKey,
      headers: { 'Idempotency-Key': requestId }
    })
  },
  cost(apiKey: string, requestId: string) {
    return runtimeRequest<SandAiInvocationCost>({
      method: 'GET', path: `/invocations/${encodeURIComponent(requestId)}/cost`, apiKey
    })
  },
  submit(apiKey: string, payload: AgentSubmitPayload, requestId: string) {
    return runtimeRequest<SandAiTaskSubmitResult>({
      method: 'POST',
      path: '/agents/runs',
      apiKey,
      data: payload,
      headers: { 'X-Request-Id': requestId, 'Idempotency-Key': requestId }
    })
  }
}
