import request from '@/utils/http'
import { ADMIN_API_PREFIX, USE_SCHEMA_MOCK } from './config'
import type { SandAiInvocationCost } from './types'

export type CostComparisonQuery = {
  environment_id: number
  current_invocation_id: number
  baseline_invocation_id: number
  current_price_revision_id?: number
}
export type ComparisonReason =
  | 'same_invocation' | 'ownership_differs' | 'execution_context_differs'
  | 'input_context_unverified' | 'input_context_differs' | 'usage_unavailable'
  | 'quantity_differs' | 'pricing_unverified' | 'currency_differs'
export type ScenarioReason =
  | 'usage_unavailable' | 'frozen_price_unverified' | 'published_price_unavailable'
  | 'published_price_unverified' | 'currency_differs' | 'amount_out_of_range'
export type CostComparison = {
  current: { id: number; cost: SandAiInvocationCost }
  baseline: { id: number; cost: SandAiInvocationCost }
  comparison: {
    comparable: boolean
    reason: ComparisonReason | null
    kind: 'metered_not_cash'
    currencies: Array<{
      currency: string | null
      current_metered_amount: string | null
      baseline_metered_amount: string | null
      difference_amount: string | null
    }>
  }
  scenario: {
    state: 'estimated' | 'unavailable'
    reason: ScenarioReason | null
    kind: 'same_tokens_published_price_estimate'
    price_revision_id: number | null
    currency: string | null
    input_tokens: number | null
    output_tokens: number | null
    frozen_amount: string | null
    published_amount: string | null
    difference_amount: string | null
  }
}
export type CostComparisonSelection = {
  environment_id: number
  current_invocation_id?: number
  baseline_invocation_id?: number
}
export default {
  read(params: CostComparisonQuery): Promise<CostComparison> {
    if (USE_SCHEMA_MOCK) return Promise.reject(new Error('费用比较需要连接真实后台，模拟数据不能证明可比条件'))
    return request.get<CostComparison>({
      url: `${ADMIN_API_PREFIX}/usage/comparison`, params, showErrorMessage: false
    })
  }
}
