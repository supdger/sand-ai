import request from '@/utils/http'
import { ADMIN_API_PREFIX, USE_SCHEMA_MOCK } from './config'
import type { SandAiAuditLog, SandAiPage, SandAiPageQuery } from './types'
import { assertMockHealthy, delay, paginateMock } from './mock/helpers'
import { mockAudits } from './mock/store'

function filterAudit(row: SandAiAuditLog, query: SandAiPageQuery): boolean {
  if (query.action && !row.action.includes(String(query.action))) return false
  if (
    query.name &&
    !row.summary.includes(String(query.name)) &&
    !row.actor_ref.includes(String(query.name))
  ) {
    return false
  }
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
        return paginateMock(mockAudits, params, filterAudit)
      })()
    }
    return request.get<SandAiPage<SandAiAuditLog>>({
      url: `${ADMIN_API_PREFIX}/audit/index`,
      params
    })
  }
}
