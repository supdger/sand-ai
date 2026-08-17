import type { SandAiTaskSubmitResult } from './types'
import { runtimeRequest } from './runtime'

export type AgentSubmitPayload = {
  model: string
  instructions: string
  query: string
  tools?: string[]
  review_required?: boolean
}

export default {
  submit(apiKey: string, payload: AgentSubmitPayload, requestId: string) {
    return runtimeRequest<SandAiTaskSubmitResult>({
      method: 'POST',
      path: '/agents/runs',
      apiKey,
      data: payload,
      headers: { 'X-Request-Id': requestId }
    })
  }
}
