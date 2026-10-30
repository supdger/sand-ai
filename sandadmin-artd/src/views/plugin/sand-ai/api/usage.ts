import request from '@/utils/http'
import { ADMIN_API_PREFIX, USE_SCHEMA_MOCK } from './config'
import type {
  SandAiUsageAllowedScope,
  SandAiPage,
  SandAiUsage,
  SandAiUsageQuery,
  SandAiUsageSummary,
  SandAiUsageSummaryQuery
} from './types'
import { assertMockHealthy, delay, paginateMock } from './mock/helpers'
import { mockUsages } from './mock/store'

function filterUsage(row: SandAiUsage, query: SandAiUsageQuery): boolean {
  return (
    (query.environment_id === undefined || row.environment_id === query.environment_id) &&
    (query.application_id === undefined || row.application_id === query.application_id) &&
    (query.provider_id === undefined || row.provider_id === query.provider_id) &&
    (query.model_id === undefined || row.model_id === query.model_id) &&
    (query.service_action === undefined || row.service_action === query.service_action) &&
    (query.capability_code === undefined || row.capability_code === query.capability_code) &&
    (query.usage_state === undefined || row.usage_state === query.usage_state) &&
    (query.pricing_state === undefined || row.pricing_state === query.pricing_state)
  )
}

function usageFilterError(query: SandAiUsageSummaryQuery): Error | null {
  const resourceIds = ['skill_run_id', 'agent_definition_id', 'agent_version_id', 'channel_binding_id',
    'knowledge_base_version_id', 'knowledge_query_id', 'knowledge_index_id'] as const
  for (const key of resourceIds) {
    const value = query[key]
    if (value !== undefined && (!Number.isSafeInteger(value) || value < 1)) return new Error('资源运行、版本及绑定 ID 须为正整数')
  }
  if (query.delivery_platform !== undefined && query.delivery_platform !== 'feishu' && query.delivery_platform !== 'wecom') {
    return new Error('渠道平台仅支持飞书或企业微信')
  }
  if (query.composition_run_id !== undefined && (!Number.isSafeInteger(query.composition_run_id) || query.composition_run_id < 1)) {
    return new Error('AI 工作流运行 ID 须为正整数')
  }
  if (query.composition_node_code !== undefined && (query.composition_run_id === undefined || !/^[a-z][a-z0-9_]{0,31}$/.test(query.composition_node_code))) {
    return new Error('先填写 AI 工作流运行 ID；节点标识须为小写字母、数字或下划线')
  }
  return null
}

export default {
  scopes() {
    if (USE_SCHEMA_MOCK) return Promise.reject(new Error('允许环境必须由真实后台核定'))
    return request.get<SandAiUsageAllowedScope>({
      url: `${ADMIN_API_PREFIX}/usage/scopes`,
      showErrorMessage: false
    })
  },
  list(params: SandAiUsageQuery) {
    const invalid = usageFilterError(params)
    if (invalid) return Promise.reject(invalid)
    if (USE_SCHEMA_MOCK) {
      if (params.organization_id !== undefined || params.skill_id !== undefined || params.skill_version_id !== undefined ||
          params.workflow_run_id !== undefined || params.composition_run_id !== undefined || params.composition_node_code !== undefined || params.agent_run_id !== undefined || params.knowledge_base_id !== undefined ||
          params.skill_run_id !== undefined || params.agent_definition_id !== undefined || params.agent_version_id !== undefined || params.channel_binding_id !== undefined || params.knowledge_base_version_id !== undefined || params.knowledge_query_id !== undefined || params.knowledge_index_id !== undefined || params.delivery_platform !== undefined || 
          params.source_kind !== undefined || params.service_code !== undefined ||
          params.from_time !== undefined || params.to_time !== undefined || params.reconciliation_state !== undefined) {
        return Promise.reject(new Error('来源与范围筛选需要正式后台的归属证据'))
      }
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
  },
  summary(params: SandAiUsageSummaryQuery) {
    const invalid = usageFilterError(params)
    if (invalid) return Promise.reject(invalid)
    if (USE_SCHEMA_MOCK) {
      return Promise.reject(new Error('费用汇总需要连接真实后台；模拟数据不能代表账单金额'))
    }
    return request.get<SandAiUsageSummary>({
      url: `${ADMIN_API_PREFIX}/usage/summary`,
      showErrorMessage: false,
      params
    })
  },
  async exportSummary(params: SandAiUsageSummaryQuery): Promise<Blob> {
    const invalid = usageFilterError(params)
    if (invalid) throw invalid
    if (USE_SCHEMA_MOCK) throw new Error('费用汇总导出需要连接真实后台')
    const value = await request.get<unknown>({
      url: `${ADMIN_API_PREFIX}/usage/summary/export.csv`,
      params,
      responseType: 'blob',
      showErrorMessage: false
    })
    if (
      !(value instanceof Blob) ||
      !['text/csv', 'application/csv'].includes(value.type.split(';')[0])
    )
      throw new Error('CSV 响应格式不正确，未生成下载文件')
    return value
  }
}
