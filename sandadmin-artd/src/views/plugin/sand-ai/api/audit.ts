import request from '@/utils/http'
import { ADMIN_API_PREFIX } from './config'
import type { SandAiAuditLog, SandAiPage, SandAiPageQuery } from './types'

export default {
  list(params: SandAiPageQuery) {
    return request.get<SandAiPage<SandAiAuditLog>>({
      url: `${ADMIN_API_PREFIX}/audit/index`,
      params
    })
  }
}
