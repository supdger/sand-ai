import request from '@/utils/http'
import { ADMIN_API_PREFIX, USE_SCHEMA_MOCK } from './config'
import type { SandAiUsageSummaryRow } from './types'

export type PlatformValidationState =
  | 'untracked'
  | 'pending'
  | 'not_dispatched'
  | 'ambiguous'
  | 'recorded'
export type PlatformValidationCapability =
  | 'chat'
  | 'embedding'
  | 'audio_transcription'
  | 'audio_speech'
export type PlatformValidationQuery = {
  from?: string
  to?: string
  provider_id?: number
  model_id?: number
  deployment_id?: number
  admin_actor_id?: number
  capability_code?: PlatformValidationCapability
  state?: Exclude<PlatformValidationState, 'untracked'>
}
export type PlatformValidationPageQuery = PlatformValidationQuery & {
  page?: number
  limit?: number
}
export type PlatformValidationUsage = {
  scope_kind: 'platform_admin'
  attempt_id: number | null
  usage_event_id: number | null
  state: PlatformValidationState
  usage_state: 'measured' | 'unmeasured' | null
  pricing_state: 'priced' | 'unpriced' | null
  price_revision_id: number | null
  metered_amount: string | null
  currency: string | null
  reconciliation_state: 'unreconciled'
  cash_billed_amount: null
}
export type PlatformValidationRow = {
  id: number
  source_kind: 'admin_verify'
  target_kind: 'deployment' | 'temporary_connection'
  admin_actor_id: number
  provider_id: number | null
  model_id: number | null
  deployment_id: number | null
  deployment_revision: number | null
  capability_code: string
  service_action: string
  call_started_at: string
  create_time: string
  input_tokens: number | null
  output_tokens: number | null
  total_tokens: number | null
  platform_usage: PlatformValidationUsage
}
export type PlatformValidationPage = {
  scope_kind: 'platform_admin'
  total: number
  per_page: number
  current_page: number
  last_page: number
  data: PlatformValidationRow[]
}
export type PlatformValidationSummary = {
  scope_kind: 'platform_admin'
  from: string
  to: string
  total_attempts: number
  total_events: number
  states: {
    pending: number
    not_dispatched: number
    ambiguous: number
    recorded: number
  }
  overall: SandAiUsageSummaryRow[]
}

const record = (value: unknown): value is Record<string, unknown> =>
  typeof value === 'object' && value !== null && !Array.isArray(value)
const count = (value: unknown): value is number =>
  typeof value === 'number' && Number.isSafeInteger(value) && value >= 0
const id = (value: unknown): value is number => count(value) && value > 0
const optionalId = (value: unknown): value is number | null => value === null || id(value)
const optionalCount = (value: unknown): value is number | null => value === null || count(value)
const money = (value: unknown): value is string | null =>
  value === null || (typeof value === 'string' && /^(0|[1-9]\d*)(?:\.\d{1,6})?$/.test(value))
const currency = (value: unknown): value is string | null =>
  value === null || (typeof value === 'string' && /^[A-Z]{3}$/.test(value))
const utc = (value: unknown): value is string =>
  typeof value === 'string' && /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(value)
const states: readonly string[] = [
  'untracked',
  'pending',
  'not_dispatched',
  'ambiguous',
  'recorded'
]

function usage(value: unknown): value is PlatformValidationUsage {
  return (
    record(value) &&
    value.scope_kind === 'platform_admin' &&
    optionalId(value.attempt_id) &&
    optionalId(value.usage_event_id) &&
    typeof value.state === 'string' &&
    states.includes(value.state) &&
    (value.usage_state === null ||
      value.usage_state === 'measured' ||
      value.usage_state === 'unmeasured') &&
    (value.pricing_state === null ||
      value.pricing_state === 'priced' ||
      value.pricing_state === 'unpriced') &&
    optionalId(value.price_revision_id) &&
    money(value.metered_amount) &&
    currency(value.currency) &&
    value.reconciliation_state === 'unreconciled' &&
    value.cash_billed_amount === null
  )
}

function row(value: unknown): value is PlatformValidationRow {
  return (
    record(value) &&
    id(value.id) &&
    value.source_kind === 'admin_verify' &&
    (value.target_kind === 'deployment' || value.target_kind === 'temporary_connection') &&
    id(value.admin_actor_id) &&
    optionalId(value.provider_id) &&
    optionalId(value.model_id) &&
    optionalId(value.deployment_id) &&
    optionalId(value.deployment_revision) &&
    typeof value.capability_code === 'string' &&
    typeof value.service_action === 'string' &&
    utc(value.call_started_at) &&
    utc(value.create_time) &&
    optionalCount(value.input_tokens) &&
    optionalCount(value.output_tokens) &&
    optionalCount(value.total_tokens) &&
    usage(value.platform_usage)
  )
}

function summaryRow(value: unknown): value is SandAiUsageSummaryRow {
  if (!record(value) || value.dimension !== 'overall' || value.value !== null) return false
  const counts = [
    'total_events',
    'measured_events',
    'priced_events',
    'unmeasured_events',
    'unpriced_events',
    'failed_events',
    'failed_unknown_amount_events',
    'business_failed_events',
    'business_failed_unknown_amount_events',
    'reconciled_events',
    'discrepancy_events',
    'unreconciled_events'
  ]
  const amounts = [
    'known_metered_amount',
    'metered_amount',
    'failed_known_amount',
    'failed_metered_amount',
    'business_failed_known_amount',
    'business_failed_metered_amount',
    'reconciled_amount'
  ]
  return (
    currency(value.currency) &&
    counts.every((key) => count(value[key])) &&
    amounts.every((key) => money(value[key])) &&
    (value.difference_amount === null ||
      (typeof value.difference_amount === 'string' &&
        /^-?(0|[1-9]\d*)(?:\.\d{1,6})?$/.test(value.difference_amount)))
  )
}

function page(value: unknown): value is PlatformValidationPage {
  return (
    record(value) &&
    value.scope_kind === 'platform_admin' &&
    count(value.total) &&
    id(value.per_page) &&
    value.per_page <= 100 &&
    id(value.current_page) &&
    count(value.last_page) &&
    Array.isArray(value.data) &&
    value.data.every(row)
  )
}

function summary(value: unknown): value is PlatformValidationSummary {
  return (
    record(value) &&
    value.scope_kind === 'platform_admin' &&
    utc(value.from) &&
    utc(value.to) &&
    count(value.total_attempts) &&
    count(value.total_events) &&
    record(value.states) &&
    count(value.states.pending) &&
    count(value.states.not_dispatched) &&
    count(value.states.ambiguous) &&
    count(value.states.recorded) &&
    Array.isArray(value.overall) &&
    value.overall.every(summaryRow)
  )
}

export default {
  async list(params: PlatformValidationPageQuery): Promise<PlatformValidationPage> {
    if (USE_SCHEMA_MOCK) throw new Error('模型验证费用需要连接真实后台，不能使用模拟账单')
    const value = await request.get<unknown>({
      url: `${ADMIN_API_PREFIX}/usage/platform-validation/index`,
      params,
      showErrorMessage: false
    })
    if (!page(value)) throw new Error('模型验证费用列表响应不完整，暂不能展示费用')
    return value
  },
  async summary(params: PlatformValidationQuery): Promise<PlatformValidationSummary> {
    if (USE_SCHEMA_MOCK) throw new Error('模型验证费用汇总需要连接真实后台')
    const value = await request.get<unknown>({
      url: `${ADMIN_API_PREFIX}/usage/platform-validation/summary`,
      params,
      showErrorMessage: false
    })
    if (!summary(value)) throw new Error('模型验证费用汇总响应不完整，暂不能展示费用')
    return value
  }
}
