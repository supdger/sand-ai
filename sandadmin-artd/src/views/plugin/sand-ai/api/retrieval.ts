import type { SandAiRetrievalResult } from './types'
import { runtimeRequest } from './runtime'

export default {
  search(apiKey: string, query: string, limit = 10) {
    return runtimeRequest<SandAiRetrievalResult>({
      method: 'POST',
      path: '/retrieval/search',
      apiKey,
      data: { query, limit }
    })
  }
}
