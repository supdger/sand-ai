import request from '@/utils/http'
import { ADMIN_API_PREFIX, USE_SCHEMA_MOCK } from './config'
import type {
  SandAiIdResult,
  SandAiModel,
  SandAiModelWrite,
  SandAiPage,
  SandAiPageQuery
} from './types'
import { assertMockHealthy, delay, nextId, nowText, paginateMock } from './mock/helpers'
import { mockModels } from './mock/store'

function filterModel(row: SandAiModel, query: SandAiPageQuery): boolean {
  if (query.code && !row.code.includes(String(query.code))) return false
  if (query.name && !row.name.includes(String(query.name))) return false
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
        return paginateMock(mockModels, params, filterModel)
      })()
    }
    return request.get<SandAiPage<SandAiModel>>({
      url: `${ADMIN_API_PREFIX}/model/index`,
      params
    })
  },

  async save(payload: SandAiModelWrite) {
    if (USE_SCHEMA_MOCK) {
      await delay()
      assertMockHealthy()
      const stamp = nowText()
      const row: SandAiModel = {
        id: nextId(),
        code: payload.code ?? '',
        name: payload.name,
        type: payload.type,
        capabilities: payload.capabilities,
        status: payload.status,
        create_time: stamp,
        update_time: stamp
      }
      mockModels.unshift(row)
      return { id: row.id }
    }
    return request.post<SandAiIdResult>({
      url: `${ADMIN_API_PREFIX}/model/save`,
      data: payload
    })
  },

  async update(payload: SandAiModelWrite & { id: number }) {
    if (USE_SCHEMA_MOCK) {
      await delay()
      assertMockHealthy()
      const index = mockModels.findIndex((item) => item.id === payload.id)
      if (index < 0 || !mockModels[index]) {
        throw new Error('模型不存在')
      }
      const current = mockModels[index]
      mockModels[index] = {
        ...current,
        name: payload.name,
        type: payload.type,
        capabilities: payload.capabilities,
        status: payload.status,
        update_time: nowText()
      }
      return true
    }
    return request.post<unknown>({
      url: `${ADMIN_API_PREFIX}/model/update`,
      data: payload
    })
  },

  async delete(params: { ids: number[] }) {
    if (USE_SCHEMA_MOCK) {
      await delay()
      assertMockHealthy()
      const idSet = new Set(params.ids)
      for (let i = mockModels.length - 1; i >= 0; i -= 1) {
        const row = mockModels[i]
        if (row && idSet.has(row.id)) {
          mockModels.splice(i, 1)
        }
      }
      return true
    }
    return request.post<unknown>({
      url: `${ADMIN_API_PREFIX}/model/destroy`,
      data: params
    })
  }
}
