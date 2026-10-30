import request from '@/utils/http'
import { ADMIN_API_PREFIX } from './config'
import { detail, result } from './billing-types'
import type { BillingDetail } from './billing-types'

export type BillingSyncInput = {
  provider_id: number
  period_start: string
  period_end: string
  idempotency_key: string
}
export type BillingCredentialInput = {
  provider_id: number
  billing_admin_key: string
  billing_organization_id: string
}
export type BillingDriver = 'openai_organization_costs' | 'openrouter_generation'
export type BillingCredentialStatus = { provider_id: number; configured: boolean; driver: BillingDriver }
export type BillingCredentialReceipt = { provider_id: number; configured: true; updated_at: string }
export type BillingCredentialRecovery = {
  operation: 'credential_save'
  provider_id: number
  request_key: string
} & (
  | { state: 'unknown'; actor_id: null; credential: null }
  | { state: 'completed'; actor_id: number; credential: BillingCredentialReceipt }
)
export type BillingSyncResult =
  | { status: 'no_data' }
  | { status: 'imported'; idempotent_replay: boolean; billing_import: BillingDetail }
function isRecord(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value)
}
function record(value: unknown): Record<string, unknown> {
  if (!isRecord(value))
    throw new Error('账单操作回执不完整')
  return value
}
function credentialStatus(value: unknown, providerId: number): BillingCredentialStatus {
  const row = record(value)
  if (row.provider_id !== providerId || typeof row.configured !== 'boolean')
    throw new Error('账单凭据回执不完整')
  if (row.driver !== 'openai_organization_costs' && row.driver !== 'openrouter_generation')
    throw new Error('账单能力回执不完整')
  return { provider_id: providerId, configured: row.configured, driver: row.driver }
}
const base = `${ADMIN_API_PREFIX}/billing`
export const billingSyncApi = {
  async sync(input: BillingSyncInput): Promise<BillingSyncResult> {
    const value = await request.post<unknown>({
      url: `${base}/sync`, data: input, showErrorMessage: false
    })
    const row = record(value)
    if (row.status === 'no_data') return { status: 'no_data' }
    const parsed = result(value, 'billing_import', detail)
    if (parsed.value.provider_id !== input.provider_id ||
        parsed.value.period_start !== input.period_start || parsed.value.period_end !== input.period_end)
      throw new Error('账单同步回执与原请求不一致')
    return { status: 'imported', idempotent_replay: parsed.idempotent_replay, billing_import: parsed.value }
  },
  async status(providerId: number): Promise<BillingCredentialStatus> {
    return credentialStatus(await request.get<unknown>({
      url: `${base}/credential/status`, params: { provider_id: providerId }, showErrorMessage: false
    }), providerId)
  },
  save(input: BillingCredentialInput, requestKey?: string): Promise<BillingCredentialReceipt> {
    const providerId = input.provider_id
    if (requestKey !== undefined && !/^[A-Za-z0-9._:-]{1,128}$/.test(requestKey))
      throw new Error('账单凭据原请求号不完整')
    return request.post<unknown>({
      url: `${base}/credential/save`, data: input,
      ...(requestKey === undefined ? {} : { headers: { 'Idempotency-Key': requestKey } }),
      showErrorMessage: false
    }).then(value => credentialReceipt(value, providerId))
  },
  async credentialByRequestKey(providerId: number, requestKey: string, actor: number): Promise<BillingCredentialRecovery> {
    if (!Number.isSafeInteger(providerId) || providerId <= 0 || !Number.isSafeInteger(actor) || actor <= 0 ||
        !/^[A-Za-z0-9._:-]{1,128}$/.test(requestKey))
      throw new Error('账单凭据原请求记录不完整')
    const row = record(await request.get<unknown>({
      url: `${base}/credential/by-request-key`,
      params: { provider_id: providerId, request_key: requestKey }, showErrorMessage: false
    }))
    if (row.operation !== 'credential_save' || row.provider_id !== providerId || row.request_key !== requestKey)
      throw new Error('账单凭据原回执目标不一致')
    if (row.state === 'unknown' && row.actor_id === null && row.credential === null)
      return { operation: 'credential_save', provider_id: providerId, request_key: requestKey,
        state: 'unknown', actor_id: null, credential: null }
    if (row.state !== 'completed' || row.actor_id !== actor)
      throw new Error('账单凭据原回执归属不一致')
    return { operation: 'credential_save', provider_id: providerId, request_key: requestKey,
      state: 'completed', actor_id: actor, credential: credentialReceipt(row.credential, providerId) }
  }
}
function credentialReceipt(value: unknown, providerId: number): BillingCredentialReceipt {
  const row = record(value)
  if (row.provider_id !== providerId || row.configured !== true ||
      typeof row.updated_at !== 'string' || !/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(row.updated_at))
    throw new Error('账单凭据保存回执不完整')
  return { provider_id: providerId, configured: true, updated_at: row.updated_at }
}
