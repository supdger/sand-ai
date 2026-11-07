import request from '@/utils/http'
import { ADMIN_API_PREFIX } from './config'
import type {
  SandAiIdResult,
  SandAiModel,
  SandAiModelWrite,
  SandAiPage,
  SandAiPageQuery
} from './types'
import type { CatalogConfigurationIntent, CatalogConfigurationReceipt } from './catalogConfigurationIntent'

export default {
  read(id: number) {
    return request.get<SandAiModel>({
      url: `${ADMIN_API_PREFIX}/model/read`,
      params: { id },
      showErrorMessage: false
    })
  },

  list(params: SandAiPageQuery) {
    return request.get<SandAiPage<SandAiModel>>({
      url: `${ADMIN_API_PREFIX}/model/index`,
      params
    })
  },

  async save(payload: SandAiModelWrite, key?: string) {
    return request.post<SandAiIdResult>({
      url: `${ADMIN_API_PREFIX}/model/save`,
      data: payload,
      headers: key ? { 'Idempotency-Key': key } : undefined
    })
  },

  async update(payload: SandAiModelWrite & { id: number }, key?: string) {
    return request.post<unknown>({
      url: `${ADMIN_API_PREFIX}/model/update`,
      data: payload,
      headers: key ? { 'Idempotency-Key': key } : undefined
    })
  },

  readOperation(intent: CatalogConfigurationIntent) {
    if (intent.kind !== 'model') throw new Error('原请求不是模型配置操作')
    return request.get<CatalogConfigurationReceipt>({
      url: `${ADMIN_API_PREFIX}/model/read-operation-by-request-key`,
      params: { operation: intent.operation, request_key: intent.key, code: intent.code,
        ...(intent.id !== null ? { target_id: intent.id } : {}) },
      showErrorMessage: false
    })
  },

  async delete(params: { ids: number[] }) {
    return request.post<unknown>({
      url: `${ADMIN_API_PREFIX}/model/destroy`,
      data: params
    })
  }
}
