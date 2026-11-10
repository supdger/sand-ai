import request from '@/utils/http'
import { ADMIN_API_PREFIX } from './config'
import type {
  SandAiIdResult,
  SandAiPage,
  SandAiPageQuery,
  SandAiProvider,
  SandAiProviderWrite
} from './types'
import type { CatalogConfigurationIntent, CatalogConfigurationReceipt } from './catalogConfigurationIntent'
import type { DiscoveredModel, ProviderGuide } from './onboarding'

export interface ProviderTestConnectionResult {
  ok: boolean
  models: DiscoveredModel[]
}

export default {
  read(id: number) {
    return request.get<SandAiProvider>({
      url: `${ADMIN_API_PREFIX}/provider/read`,
      params: { id },
      showErrorMessage: false
    })
  },

  list(params: SandAiPageQuery) {
    return request.get<SandAiPage<SandAiProvider>>({
      url: `${ADMIN_API_PREFIX}/provider/index`,
      params
    })
  },

  async save(payload: SandAiProviderWrite, key?: string) {
    return request.post<SandAiIdResult>({
      url: `${ADMIN_API_PREFIX}/provider/save`,
      data: payload,
      headers: key ? { 'Idempotency-Key': key } : undefined
    })
  },

  async update(payload: SandAiProviderWrite & { id: number }, key?: string) {
    return request.post<unknown>({
      url: `${ADMIN_API_PREFIX}/provider/update`,
      data: payload,
      headers: key ? { 'Idempotency-Key': key } : undefined
    })
  },

  guides() {
    return request.get<{ guides: ProviderGuide[] }>({
      url: `${ADMIN_API_PREFIX}/provider/guides`,
      showErrorMessage: false
    })
  },

  testConnection(payload: { connection: { base_url: string; api_key: string } } | { provider_id: number }) {
    return request.post<ProviderTestConnectionResult>({
      url: `${ADMIN_API_PREFIX}/provider/test-connection`,
      data: payload,
      timeout: 15000,
      showErrorMessage: false
    })
  },

  readOperation(intent: CatalogConfigurationIntent) {
    if (intent.kind !== 'provider') throw new Error('原请求不是服务商配置操作')
    return request.get<CatalogConfigurationReceipt>({
      url: `${ADMIN_API_PREFIX}/provider/read-operation-by-request-key`,
      params: { operation: intent.operation, request_key: intent.key, code: intent.code,
        ...(intent.id !== null ? { target_id: intent.id } : {}) },
      showErrorMessage: false
    })
  },

  async delete(params: { ids: number[] }) {
    return request.post<unknown>({
      url: `${ADMIN_API_PREFIX}/provider/destroy`,
      data: params
    })
  }
}
