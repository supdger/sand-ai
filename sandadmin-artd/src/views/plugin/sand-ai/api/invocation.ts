import request from '@/utils/http'
import { ADMIN_API_PREFIX, USE_SCHEMA_MOCK } from './config'
import type { SandAiInvocation, SandAiInvocationCost, SandAiPage, SandAiPageQuery } from './types'
import { assertMockHealthy, delay, paginateMock } from './mock/helpers'
import { mockInvocations } from './mock/store'

function filterInvocation(row: SandAiInvocation, query: SandAiPageQuery): boolean {
  if (query.request_id && !row.request_id.includes(String(query.request_id))) return false
  if (query.state && row.state !== query.state) return false
  if (query.status !== undefined && query.status !== '' && row.status !== Number(query.status)) {
    return false
  }
  return true
}

export default {
  list(params: SandAiPageQuery) {
    if (USE_SCHEMA_MOCK) {
      return (async () => {
        await delay()
        assertMockHealthy()
        return paginateMock(mockInvocations, params, filterInvocation)
      })()
    }
    return request.get<SandAiPage<SandAiInvocation>>({
      url: `${ADMIN_API_PREFIX}/invocation/index`,
      params
    })
  },
  costByInvocationId(environmentId: number, invocationId: number) {
    if (USE_SCHEMA_MOCK) {
      return Promise.reject(new Error('单次费用需要连接真实后台；模拟数据不能代表实际计量'))
    }
    return request.get<SandAiInvocationCost>({
      url: `${ADMIN_API_PREFIX}/usage/cost`,
      params: { environment_id: environmentId, invocation_id: invocationId }
    })
  },
  cost(environmentId: number, requestId: string) {
    if (USE_SCHEMA_MOCK) {
      return Promise.reject(new Error('单次费用需要连接真实后台；模拟数据不能代表实际计量'))
    }
    return request.get<SandAiInvocationCost>({
      url: `${ADMIN_API_PREFIX}/usage/cost`,
      params: { environment_id: environmentId, request_id: requestId }
    })
  }
}
