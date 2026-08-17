import type { SandAiTask } from './types'
import { runtimeRequest } from './runtime'

export default {
  read(apiKey: string, taskId: number) {
    return runtimeRequest<SandAiTask>({
      method: 'GET',
      path: `/tasks/${taskId}`,
      apiKey
    })
  },

  cancel(apiKey: string, taskId: number) {
    return runtimeRequest<SandAiTask>({
      method: 'POST',
      path: `/tasks/${taskId}/cancel`,
      apiKey
    })
  },

  retry(apiKey: string, taskId: number) {
    return runtimeRequest<SandAiTask>({
      method: 'POST',
      path: `/tasks/${taskId}/retry`,
      apiKey
    })
  }
}
