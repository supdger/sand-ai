import request from '@/utils/http'
import { HttpError } from '@/utils/http/error'
import { ADMIN_API_PREFIX } from './config'
import onboarding, { type DraftSave, type Verification } from './onboarding'
import type { LocalOcrDeployment, LocalOcrSuccessorDeployment } from './onboarding'
export type OcrDraft = DraftSave & { provider: DraftSave['provider'] & {
  adapter: 'local_ocr_runtime'; timeout_ms: number; has_encrypted_config: false
} }
const prefix = `${ADMIN_API_PREFIX}/pricing/revision`
export type OcrMeter = 'external_request'
export interface OcrPriceDraft {
  provider_id: number; model_id: number; capability_code: 'image_ocr'; name: string; currency: string
  effective_from: string; effective_until: string | null
  rates: [{ meter_code: OcrMeter; unit_price: string; per_quantity: number }]
}
export interface OcrPrice extends OcrPriceDraft { id: number; revision: number; state: 'draft' | 'published' }
export type PriceOperationReference = {
  kind: 'save' | 'publish'; provider_id: number; model_id: number; capability_code: 'image_ocr'
  target_id: number | null; request_key: string; input_sha256: string
}
export type PriceOperationRead = PriceOperationReference & {
  actor_id: number | null; state: 'unknown' | 'completed'; receipt: OcrPrice | null
}

function record(value: unknown): value is Record<string, unknown> { return typeof value === 'object' && value !== null && !Array.isArray(value) }
function id(value: unknown): value is number { return typeof value === 'number' && Number.isSafeInteger(value) && value > 0 }
export function meterFor(): OcrMeter { return 'external_request' }
function verificationState(value: unknown): value is Verification['state'] {
  return value === 'draft' || value === 'verifying' || value === 'tested' || value === 'verification_ambiguous' || value === 'published'
}
function meter(value: unknown): value is OcrMeter { return value === 'external_request' }
function emptyOcrConfig(value: unknown): boolean {
  return Array.isArray(value) ? value.length === 0 : record(value) && Object.keys(value).length === 0
}
function draft(value: unknown): OcrDraft {
  if (!record(value) || !id(value.deployment_id) || !id(value.revision) || typeof value.idempotent_replay !== 'boolean'
    || !verificationState(value.state)
    || !record(value.provider) || !id(value.provider.id) || value.provider.adapter !== 'local_ocr_runtime' || value.provider.has_encrypted_config !== false
    || typeof value.provider.timeout_ms !== 'number' || !Number.isSafeInteger(value.provider.timeout_ms) || value.provider.timeout_ms < 1000 || value.provider.timeout_ms > 120000
    || typeof value.provider.code !== 'string' || typeof value.provider.name !== 'string'
    || !record(value.model) || !id(value.model.id) || typeof value.model.code !== 'string' || typeof value.model.name !== 'string'
    || value.model.type !== 'image_ocr' || !Array.isArray(value.model.capabilities) || value.model.capabilities.length !== 1 || value.model.capabilities[0] !== 'image_ocr'
    || !record(value.deployment) || value.deployment.remote_model !== 'local_paddle_ocr'
    || !emptyOcrConfig(value.deployment.config)) throw new Error('本地 OCR 部署响应不完整，请保留原号只读找回。')
  return {
    deployment_id: value.deployment_id,
    revision: value.revision,
    state: value.state,
    idempotent_replay: value.idempotent_replay,
    provider: { id: value.provider.id, code: value.provider.code, name: value.provider.name, adapter: value.provider.adapter, timeout_ms: value.provider.timeout_ms, has_encrypted_config: false },
    model: { id: value.model.id, code: value.model.code, name: value.model.name, type: 'image_ocr', capabilities: ['image_ocr'] },
    deployment: { remote_model: value.deployment.remote_model, config: {} }
  }
}
function price(value: unknown, providerId: number, modelId: number): OcrPrice {
  if (!record(value) || !id(value.id) || !id(value.revision) || !id(value.provider_id) || !id(value.model_id) || value.provider_id !== providerId || value.model_id !== modelId
    || value.capability_code !== 'image_ocr' || (value.state !== 'draft' && value.state !== 'published')
    || typeof value.name !== 'string' || typeof value.currency !== 'string' || !/^[A-Z]{3}$/.test(value.currency)
    || typeof value.effective_from !== 'string' || !(value.effective_until === null || typeof value.effective_until === 'string')
    || !Array.isArray(value.rates) || value.rates.length !== 1 || !record(value.rates[0])
    || !meter(value.rates[0].meter_code) || value.rates[0].meter_code !== meterFor() || typeof value.rates[0].unit_price !== 'string'
    || !/^(0|[1-9][0-9]{0,11})(\.[0-9]{1,8})?$/.test(value.rates[0].unit_price) || !id(value.rates[0].per_quantity) || value.rates[0].per_quantity > 2147483647) throw new Error('OCR 价格版本或请求单位未确认，请重新读取。')
  return {
    id: value.id,
    revision: value.revision,
    state: value.state,
    provider_id: value.provider_id,
    model_id: value.model_id,
    capability_code: 'image_ocr',
    name: value.name,
    currency: value.currency,
    effective_from: value.effective_from,
    effective_until: value.effective_until,
    rates: [{ meter_code: value.rates[0].meter_code, unit_price: value.rates[0].unit_price, per_quantity: value.rates[0].per_quantity }]
  }
}
export const pricing = {
  async readOperation(reference: PriceOperationReference, adminId: number): Promise<PriceOperationRead> {
    const { target_id, ...rest } = reference
    const value = await request.get<unknown>({ url: `${prefix}/read-operation-by-request-key`, params: { ...rest, ...(target_id === null ? {} : { target_id }) }, headers: { 'Cache-Control': 'no-store' }, showErrorMessage: false })
    if (!record(value) || value.kind !== reference.kind || value.provider_id !== reference.provider_id || value.model_id !== reference.model_id
      || value.capability_code !== reference.capability_code || value.target_id !== reference.target_id || value.request_key !== reference.request_key
      || value.input_sha256 !== reference.input_sha256) throw new Error('原价格操作归属未确认。')
    if (value.state === 'unknown' && value.actor_id === null && value.receipt === null) return { ...reference, actor_id: null, state: 'unknown', receipt: null }
    if (value.state !== 'completed' || value.actor_id !== adminId) throw new Error('原价格操作审核身份未确认。')
    const receipt = price(value.receipt, reference.provider_id, reference.model_id)
    if ((target_id !== null && receipt.id !== target_id) || (reference.kind === 'publish' && receipt.state !== 'published')) throw new Error('原价格回执与原目标不一致。')
    return { ...reference, actor_id: adminId, state: 'completed', receipt }
  },
  async list(providerId: number, modelId: number): Promise<OcrPrice[]> {
    const result = await request.get<unknown>({ url: `${prefix}/index`, params: { provider_id: providerId, model_id: modelId, capability_code: 'image_ocr', page: 1, limit: 100 }, showErrorMessage: false })
    if (!record(result) || !Array.isArray(result.data)) throw new Error('价格目录未确认。')
    // Directory summaries omit rates; obtain each selected version through read instead.
    return Promise.all(result.data.map(async item => {
      if (!record(item) || !id(item.id)) throw new Error('价格目录未确认。')
      return price(await request.get<unknown>({ url: `${prefix}/read`, params: { id: item.id }, showErrorMessage: false }), providerId, modelId)
    }))
  },
  async save(value: OcrPriceDraft, key: string): Promise<OcrPrice> {
    return price(await request.post<unknown>({ url: `${prefix}/save-draft`, data: value, headers: { 'Idempotency-Key': key }, showErrorMessage: false }), value.provider_id, value.model_id)
  },
  async publish(value: OcrPrice, key: string): Promise<OcrPrice> {
    return price(await request.post<unknown>({ url: `${prefix}/publish`, data: { id: value.id }, headers: { 'Idempotency-Key': key }, showErrorMessage: false }), value.provider_id, value.model_id)
  }
}
export default {
  async save(payload: LocalOcrDeployment | LocalOcrSuccessorDeployment, key: string): Promise<OcrDraft> {
    let proof: { error_code: string; submission_state: 'not_submitted'; retryable: false } | null = null
    try {
      return draft(await request.post<unknown>({ url: `${ADMIN_API_PREFIX}/setup/deployment/save`, data: payload, headers: { 'Idempotency-Key': key }, showErrorMessage: false,
        transformResponse: [(value: unknown): unknown => {
          let parsed: unknown = value
          if (typeof value === 'string') { try { parsed = JSON.parse(value) } catch { return value } }
          if (record(parsed) && parsed.code === 400 && record(parsed.data) && parsed.data.submission_state === 'not_submitted' && parsed.data.retryable === false
            && typeof parsed.data.error_code === 'string' && ['SAND_AI_VALIDATION_ERROR', 'SAND_AI_ONBOARDING_CONNECTION_INVALID', 'SAND_AI_ONBOARDING_HEADER_INVALID', 'SAND_AI_ONBOARDING_MODEL_INVALID'].includes(parsed.data.error_code)) proof = { error_code: parsed.data.error_code, submission_state: 'not_submitted', retryable: false }
          return parsed
        }]
      }))
    } catch (error) {
      if (error instanceof HttpError && error.code === 400 && proof !== null) throw new HttpError(error.message, error.code, { data: proof, url: error.url, method: error.method })
      throw error
    }
  },
  async readSavedDraftByKey(key: string): Promise<OcrDraft> { return draft(await onboarding.readSavedDraftByKey(key)) },
  async readDraft(deploymentId: number, revision?: number): Promise<OcrDraft> { return draft(await onboarding.readDraft(deploymentId, revision)) },
  async verify(deploymentId: number, revision: number, location: string, key: string, timeoutMs?: number): Promise<Verification> {
    return onboarding.verify(deploymentId, revision, location, key, timeoutMs)
  },
  async readVerification(deploymentId: number, revision: number, key?: string): Promise<Verification> {
    return onboarding.readVerification(deploymentId, revision, key)
  },
  publish: onboarding.publish
}
