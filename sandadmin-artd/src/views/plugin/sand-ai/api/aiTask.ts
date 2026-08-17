import type { SandAiChatMessage, SandAiTaskSubmitResult } from './types'
import { runtimeRequest } from './runtime'

export type AiTaskSubmitPayload = {
  model: string
  messages: SandAiChatMessage[]
  source_block_ids?: number[]
}

export default {
  submit(apiKey: string, payload: AiTaskSubmitPayload, requestId: string) {
    return runtimeRequest<SandAiTaskSubmitResult>({
      method: 'POST',
      path: '/ai-tasks',
      apiKey,
      data: payload,
      headers: { 'X-Request-Id': requestId }
    })
  }
}
