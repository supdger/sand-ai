import request from '@/utils/http'
import { ADMIN_API_PREFIX } from './config'
import type { SandAiInvocation, SandAiInvocationCost, SandAiPage, SandAiPageQuery } from './types'

export default {
  list(params: SandAiPageQuery) {
    return request.get<SandAiPage<SandAiInvocation>>({
      url: `${ADMIN_API_PREFIX}/invocation/index`,
      params
    })
  },
  costByInvocationId(environmentId: number, invocationId: number) {
    return request.get<SandAiInvocationCost>({
      url: `${ADMIN_API_PREFIX}/usage/cost`,
      params: { environment_id: environmentId, invocation_id: invocationId }
    })
  },
  cost(environmentId: number, requestId: string) {
    return request.get<SandAiInvocationCost>({
      url: `${ADMIN_API_PREFIX}/usage/cost`,
      params: { environment_id: environmentId, request_id: requestId }
    })
  }
}
