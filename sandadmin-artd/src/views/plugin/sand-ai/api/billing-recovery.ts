import { ref, watch } from 'vue'
import { useAdminReadIdentity } from '../composables/useAdminReadIdentity'
import request from '@/utils/http'
import { ADMIN_API_PREFIX } from './config'
import { detail, review } from './billing-types'
import type { BillingDetail, BillingReview } from './billing-types'

export type BillingRequestMeta = {
  operation: 'import' | 'sync' | 'review'
  request_key: string
  input_sha256: string
  provider_id: number
  billing_line_id?: number
  environment_id?: number
}
export type BillingRecovery = {
  billing_import: BillingDetail | null
  review: BillingReview | null
  sync_status: null | 'imported' | 'no_data'
} & (
  | { state: 'unknown' | 'completed'; rejection: null }
  | { state: 'rejected'; rejection: { error_code: string } }
)
function record(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}
function id(value: unknown): value is number {
  return typeof value === 'number' && Number.isSafeInteger(value) && value > 0
}
function meta(value: unknown): BillingRequestMeta {
  if (!record(value) || !['import', 'sync', 'review'].includes(String(value.operation)) ||
      typeof value.request_key !== 'string' || !value.request_key || value.request_key.length > 128 ||
      /[\x00-\x1f\x7f]/.test(value.request_key) || typeof value.input_sha256 !== 'string' ||
      !/^[a-f0-9]{64}$/.test(value.input_sha256) || !id(value.provider_id) ||
      (value.operation === 'review' && !id(value.billing_line_id)) ||
      (value.environment_id !== undefined && !id(value.environment_id)))
    throw new Error('原请求记录不完整，请保留当前页面并联系管理员。')
  const operation = value.operation
  if (operation !== 'import' && operation !== 'sync' && operation !== 'review') throw new Error('原请求操作不完整')
  return { operation, request_key: value.request_key, input_sha256: value.input_sha256,
    provider_id: value.provider_id, ...(id(value.billing_line_id) ? { billing_line_id: value.billing_line_id } : {}),
    ...(id(value.environment_id) ? { environment_id: value.environment_id } : {}) }
}
function canonical(value: unknown): string {
  if (value === null || typeof value === 'string' || typeof value === 'boolean') return JSON.stringify(value)
  if (typeof value === 'number' && Number.isFinite(value)) return JSON.stringify(value)
  if (Array.isArray(value)) return `[${value.map(canonical).join(',')}]`
  if (record(value)) return `{${Object.keys(value).sort().map(key => `${JSON.stringify(key)}:${canonical(value[key])}`).join(',')}}`
  throw new Error('原请求包含无法生成摘要的字段')
}
export async function billingInputSha(input: unknown): Promise<string> {
  const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(canonical(input)))
  return Array.from(new Uint8Array(digest), byte => byte.toString(16).padStart(2, '0')).join('')
}
export function useBillingJournal() {
  const identity = useAdminReadIdentity()
  const owner = identity.actor
  const entries = ref<BillingRequestMeta[]>([])
  const storageError = ref('')
  const storageKey = (actor: number) => `sand-ai:billing:requests:${actor}`
  function reload() {
    entries.value = []
    storageError.value = ''
    if (!owner.value) return
    try {
      const raw = sessionStorage.getItem(storageKey(owner.value))
      const rows: unknown = raw === null ? [] : JSON.parse(raw)
      if (!Array.isArray(rows)) throw new Error('原请求日志不完整')
      entries.value = rows.map(meta)
    } catch (cause) { storageError.value = cause instanceof Error ? cause.message : '无法读取原请求日志' }
  }
  watch([owner, identity.epoch], reload, { immediate: true, flush: 'sync' })
  function current(actor: number | null) { return actor !== null && actor === owner.value }
  function save(value: BillingRequestMeta, actor: number | null) {
    if (actor === null || !current(actor) || storageError.value) throw new Error(storageError.value || '当前管理员已改变，请重新打开账单页面')
    reload()
    if (storageError.value) throw new Error(storageError.value)
    const entry = meta(value)
    const next = [...entries.value.filter(row => row.request_key !== entry.request_key), entry]
    sessionStorage.setItem(storageKey(actor), JSON.stringify(next))
    entries.value = next
  }
  function remove(key: string, actor: number | null) {
    if (actor === null || !current(actor)) return
    reload()
    if (storageError.value) throw new Error(storageError.value)
    const next = entries.value.filter(row => row.request_key !== key)
    sessionStorage.setItem(storageKey(actor), JSON.stringify(next))
    entries.value = next
  }
  return { owner, epoch: identity.epoch, entries, storageError, reload, current, save, remove }
}
export async function recoverBillingRequest(entry: BillingRequestMeta, actor: number): Promise<BillingRecovery> {
  const path = entry.operation === 'import' ? 'import-by-request-key' : entry.operation === 'sync' ? 'sync/by-request-key' : 'reviews/by-request-key'
  const value = await request.get<unknown>({ url: `${ADMIN_API_PREFIX}/billing/${path}`,
    params: { ...(entry.operation === 'review' ? { billing_line_id: entry.billing_line_id } : { provider_id: entry.provider_id }),
      request_key: entry.request_key, input_sha256: entry.input_sha256 }, showErrorMessage: false })
  if (!record(value) || value.actor_id !== actor || value.operation !== entry.operation ||
      value.request_key !== entry.request_key || value.input_sha256 !== entry.input_sha256 ||
      (value.state !== 'unknown' && value.state !== 'completed' && value.state !== 'rejected')) throw new Error('原请求查询回执与当前管理员、目标或摘要不一致')
  if (value.state === 'rejected') {
    if (value.billing_import !== null || value.review !== null || value.sync_status !== null ||
        !record(value.rejection) || typeof value.rejection.error_code !== 'string' ||
        !/^SAND_AI_[A-Z0-9_]{1,120}$/.test(value.rejection.error_code) ||
        Object.keys(value.rejection).some(key => key !== 'error_code'))
      throw new Error('原请求拒绝凭据不完整，保留原请求记录')
    return { state: 'rejected', rejection: { error_code: value.rejection.error_code },
      billing_import: null, review: null, sync_status: null }
  }
  if (value.rejection !== null) throw new Error('原请求查询回执包含无法确认的拒绝信息')
  if (value.state === 'unknown') {
    if (value.billing_import !== null || value.review !== null || value.sync_status !== null) throw new Error('未确认回执包含无法确认的结果')
    return { state: 'unknown', rejection: null, billing_import: null, review: null, sync_status: null }
  }
  if (entry.operation === 'review') {
    const result = review(value.review)
    if (result.billing_line_id !== entry.billing_line_id || result.admin_id !== actor || result.idempotency_key !== entry.request_key ||
        (entry.environment_id !== undefined && result.environment_id !== entry.environment_id) || value.billing_import !== null || value.sync_status !== null)
      throw new Error('审核查询回执与原请求目标不一致')
    return { state: 'completed', rejection: null, billing_import: null, review: result, sync_status: null }
  }
  if (value.review !== null) throw new Error('账单查询回执类型不一致')
  if (entry.operation === 'sync' && value.sync_status === 'no_data' && value.billing_import === null)
    return { state: 'completed', rejection: null, billing_import: null, review: null, sync_status: 'no_data' }
  const batch = detail(value.billing_import)
  if (batch.provider_id !== entry.provider_id || (entry.operation === 'import' ? value.sync_status !== null : value.sync_status !== 'imported'))
    throw new Error('账单查询回执与原服务商不一致')
  return { state: 'completed', rejection: null, billing_import: batch, review: null, sync_status: entry.operation === 'sync' ? 'imported' : null }
}
