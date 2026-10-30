import request from '@/utils/http'
import { ADMIN_API_PREFIX } from './config'

export type UsageRefundSelection = { environment_id: number; usage_event_id: number }
export type UsageRefundInput = UsageRefundSelection & {
  evidence_id: number
  evidence_locator: string
  provider_refund_reference: string
  refund_amount: string
  currency: string
  provider_refund_confirmed: true
  idempotency_key: string
  note: string
}
export type UsageAdjustment = UsageRefundSelection & {
  id: number
  reconciliation_id: number
  organization_id: number
  application_id: number
  workload_client_id: number
  provider_id: number
  evidence_id: number
  admin_id: number
  adjustment_kind: 'refund'
  amount_basis: 'reconciled_bill'
  trust_basis: 'operator_reviewed'
  currency: string
  evidence_sha256: string
  evidence_locator: string
  provider_refund_reference: string
  idempotency_key: string
  note: string
  create_time: string
  original_billed_amount: string
  refund_amount: string
  evidence_state: 'available' | 'evidence_unavailable'
}
export type UsageRefundTotals = {
  state: 'bill_unavailable' | 'no_recorded_refund' | 'recorded_refunds'
  amount_basis: 'reconciled_bill'
  trust_basis: 'operator_reviewed'
  adjustment_count: number
  gross_billed_amount: string | null
  refund_amount: string | null
  net_billed_amount: string | null
  currency: string | null
}
export type UsageAdjustmentPage = {
  total: number; per_page: number; current_page: number
  data: UsageAdjustment[]; totals: UsageRefundTotals
}
export type UsageAdjustmentRead = { adjustment: UsageAdjustment; totals: UsageRefundTotals }
function isRecord(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value)
}
function object(value: unknown): Record<string, unknown> {
  if (!isRecord(value)) throw new Error('退款调整回执不完整')
  return value
}
function text(value: unknown): string {
  if (typeof value !== 'string') throw new Error('退款文本回执不完整')
  return value
}
function integer(value: unknown, minimum = 1): number {
  if (typeof value !== 'number' || !Number.isSafeInteger(value) || value < minimum)
    throw new Error('退款标识回执不完整')
  return value
}
function choice<T extends string>(value: unknown, choices: readonly T[]): T {
  for (const item of choices) if (item === value) return item
  throw new Error('退款状态回执不完整')
}
function amount(value: unknown): string {
  const result = text(value)
  if (!/^(0|[1-9][0-9]{0,13})\.[0-9]{6}$/.test(result)) throw new Error('退款金额回执不完整')
  return result
}
function currency(value: unknown): string {
  const result = text(value)
  if (!/^[A-Z]{3}$/.test(result)) throw new Error('退款币种回执不完整')
  return result
}
function adjustment(value: unknown): UsageAdjustment {
  const row = object(value)
  const sha = text(row.evidence_sha256)
  if (!/^[a-f0-9]{64}$/.test(sha)) throw new Error('退款凭证指纹不完整')
  return {
    id: integer(row.id), usage_event_id: integer(row.usage_event_id),
    reconciliation_id: integer(row.reconciliation_id), organization_id: integer(row.organization_id),
    application_id: integer(row.application_id), environment_id: integer(row.environment_id),
    workload_client_id: integer(row.workload_client_id), provider_id: integer(row.provider_id),
    evidence_id: integer(row.evidence_id), admin_id: integer(row.admin_id),
    adjustment_kind: choice(row.adjustment_kind, ['refund']),
    amount_basis: choice(row.amount_basis, ['reconciled_bill']),
    trust_basis: choice(row.trust_basis, ['operator_reviewed']), currency: currency(row.currency),
    evidence_sha256: sha, evidence_locator: text(row.evidence_locator),
    provider_refund_reference: text(row.provider_refund_reference), idempotency_key: text(row.idempotency_key),
    note: text(row.note), create_time: text(row.create_time),
    original_billed_amount: amount(row.original_billed_amount), refund_amount: amount(row.refund_amount),
    evidence_state: choice(row.evidence_state, ['available', 'evidence_unavailable'])
  }
}
function totals(value: unknown): UsageRefundTotals {
  const row = object(value)
  const state = choice(row.state, ['bill_unavailable', 'no_recorded_refund', 'recorded_refunds'])
  const result: UsageRefundTotals = {
    state, amount_basis: choice(row.amount_basis, ['reconciled_bill']),
    trust_basis: choice(row.trust_basis, ['operator_reviewed']), adjustment_count: integer(row.adjustment_count, 0),
    gross_billed_amount: row.gross_billed_amount === null ? null : amount(row.gross_billed_amount),
    refund_amount: row.refund_amount === null ? null : amount(row.refund_amount),
    net_billed_amount: row.net_billed_amount === null ? null : amount(row.net_billed_amount),
    currency: row.currency === null ? null : currency(row.currency)
  }
  if (state === 'bill_unavailable'
    ? [result.gross_billed_amount, result.refund_amount, result.net_billed_amount, result.currency].some(value => value !== null)
    : [result.gross_billed_amount, result.refund_amount, result.net_billed_amount, result.currency].some(value => value === null))
    throw new Error('退款汇总状态与金额不一致')
  return result
}
function scope(row: UsageAdjustment, selection: UsageRefundSelection): void {
  if (row.environment_id !== selection.environment_id || row.usage_event_id !== selection.usage_event_id)
    throw new Error('退款回执与原环境、用量事件不一致')
}
const base = `${ADMIN_API_PREFIX}/usage/adjustment`
export const usageAdjustmentApi = {
  async index(input: UsageRefundSelection & { page: number; limit: number }): Promise<UsageAdjustmentPage> {
    const row = object(await request.get<unknown>({ url: `${base}/index`, params: input, showErrorMessage: false }))
    if (!Array.isArray(row.data)) throw new Error('退款记录列表不完整')
    const data = row.data.map(adjustment)
    data.forEach(item => scope(item, input))
    const current = integer(row.current_page)
    const perPage = integer(row.per_page)
    if (current !== input.page || perPage !== input.limit) throw new Error('退款记录分页回执不一致')
    return { total: integer(row.total, 0), per_page: perPage, current_page: current, data, totals: totals(row.totals) }
  },
  async read(input: { environment_id: number; id: number }): Promise<UsageAdjustmentRead> {
    const row = object(await request.get<unknown>({ url: `${base}/read`, params: input, showErrorMessage: false }))
    const item = adjustment(row.adjustment)
    if (item.id !== input.id || item.environment_id !== input.environment_id)
      throw new Error('退款详情回执与原查询不一致')
    return { adjustment: item, totals: totals(row.totals) }
  },
  async refund(input: UsageRefundInput): Promise<UsageAdjustmentRead & { idempotent_replay: boolean }> {
    const row = object(await request.post<unknown>({ url: `${base}/refund`, data: input, showErrorMessage: false }))
    if (typeof row.idempotent_replay !== 'boolean') throw new Error('退款保存回执不完整')
    const item = adjustment(row.adjustment)
    scope(item, input)
    if (!matchesRefund(item, input)) throw new Error('退款保存回执与原请求内容不一致')
    return { adjustment: item, totals: totals(row.totals), idempotent_replay: row.idempotent_replay }
  }
}
export function normalizedRefundAmount(value: string): string | null {
  if (!/^(0|[1-9][0-9]{0,13})(\.[0-9]{1,6})?$/.test(value) || !/[1-9]/.test(value)) return null
  const [whole, fraction = ''] = value.split('.')
  return `${whole}.${fraction.padEnd(6, '0')}`
}
export function matchesRefund(row: UsageAdjustment, input: UsageRefundInput): boolean {
  return row.environment_id === input.environment_id && row.usage_event_id === input.usage_event_id &&
    row.idempotency_key === input.idempotency_key && row.evidence_id === input.evidence_id &&
    row.evidence_locator === input.evidence_locator && row.provider_refund_reference === input.provider_refund_reference &&
    row.currency === input.currency && row.refund_amount === normalizedRefundAmount(input.refund_amount) && row.note === input.note
}
