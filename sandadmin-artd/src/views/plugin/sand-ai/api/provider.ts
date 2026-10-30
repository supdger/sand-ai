import request from '@/utils/http'
import { ADMIN_API_PREFIX, USE_SCHEMA_MOCK } from './config'
import type {
  SandAiIdResult,
  SandAiPage,
  SandAiPageQuery,
  SandAiProvider,
  SandAiProviderWrite
} from './types'
import { assertMockHealthy, delay, nextId, nowText, paginateMock } from './mock/helpers'
import { mockProviders } from './mock/store'
import type { CatalogConfigurationIntent, CatalogConfigurationReceipt } from './catalogConfigurationIntent'

function filterProvider(row: SandAiProvider, query: SandAiPageQuery): boolean {
  if (query.code && !row.code.includes(String(query.code))) return false
  if (query.name && !row.name.includes(String(query.name))) return false
  if (query.status !== undefined && query.status !== '' && row.status !== Number(query.status)) {
    return false
  }
  return true
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
    if (USE_SCHEMA_MOCK) {
      return (async () => {
        await delay()
        assertMockHealthy()
        return paginateMock(mockProviders, params, filterProvider)
      })()
    }
    return request.get<SandAiPage<SandAiProvider>>({
      url: `${ADMIN_API_PREFIX}/provider/index`,
      params
    })
  },

  async save(payload: SandAiProviderWrite, key?: string) {
    if (USE_SCHEMA_MOCK) {
      await delay()
      assertMockHealthy()
      const stamp = nowText()
      const row: SandAiProvider = {
        id: nextId(),
        code: payload.code ?? '',
        name: payload.name,
        adapter: payload.adapter,
        timeout_ms: payload.timeout_ms,
        has_encrypted_config: payload.encrypted_config !== undefined,
        status: payload.status,
        create_time: stamp,
        update_time: stamp
      }
      mockProviders.unshift(row)
      return { id: row.id }
    }
    return request.post<SandAiIdResult>({
      url: `${ADMIN_API_PREFIX}/provider/save`,
      data: payload,
      headers: key ? { 'Idempotency-Key': key } : undefined
    })
  },

  async update(payload: SandAiProviderWrite & { id: number }, key?: string) {
    if (USE_SCHEMA_MOCK) {
      await delay()
      assertMockHealthy()
      const index = mockProviders.findIndex((item) => item.id === payload.id)
      if (index < 0 || !mockProviders[index]) {
        throw new Error('服务商不存在')
      }
      const current = mockProviders[index]
      mockProviders[index] = {
        ...current,
        name: payload.name,
        adapter: payload.adapter,
        timeout_ms: payload.timeout_ms,
        has_encrypted_config:
          payload.encrypted_config !== undefined ? true : current.has_encrypted_config,
        status: payload.status,
        update_time: nowText()
      }
      return true
    }
    return request.post<unknown>({
      url: `${ADMIN_API_PREFIX}/provider/update`,
      data: payload,
      headers: key ? { 'Idempotency-Key': key } : undefined
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
    if (USE_SCHEMA_MOCK) {
      await delay()
      assertMockHealthy()
      const idSet = new Set(params.ids)
      for (let i = mockProviders.length - 1; i >= 0; i -= 1) {
        const row = mockProviders[i]
        if (row && idSet.has(row.id)) {
          mockProviders.splice(i, 1)
        }
      }
      return true
    }
    return request.post<unknown>({
      url: `${ADMIN_API_PREFIX}/provider/destroy`,
      data: params
    })
  }
}
