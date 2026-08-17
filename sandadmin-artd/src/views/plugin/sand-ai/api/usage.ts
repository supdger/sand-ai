import request from '@/utils/http'
import { ADMIN_API_PREFIX, USE_SCHEMA_MOCK } from './config'
import type { SandAiPage, SandAiPageQuery, SandAiUsage } from './types'
import { assertMockHealthy, delay, paginateMock } from './mock/helpers'
import { mockUsages } from './mock/store'

function filterUsage(row: SandAiUsage, query: SandAiPageQuery): boolean {
  if (query.name && !row.source.includes(String(query.name))) return false
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
        return paginateMock(mockUsages, params, filterUsage)
      })()
    }
    return request.get<SandAiPage<SandAiUsage>>({
      url: `${ADMIN_API_PREFIX}/usage/index`,
      params
    })
  }
}
