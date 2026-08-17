import request from '@/utils/http'
import { ADMIN_API_PREFIX, USE_SCHEMA_MOCK } from './config'
import type { SandAiInvocation, SandAiPage, SandAiPageQuery } from './types'
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
  }
}
