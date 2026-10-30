import request from '@/utils/http'
import { HttpError } from '@/utils/http/error'
import { ADMIN_API_PREFIX } from './config'
import onboarding, { type DraftSave, type Verification } from './onboarding'
export type RerankDriver = 'cohere_rerank' | 'jina_rerank'
export type RerankMeter = 'rerank_search_units' | 'rerank_tokens'
export interface RerankDeployment {
  provider: { code: string; name: string; adapter: RerankDriver; timeout_ms: number }
  model: { code: string; name: string; type: 'rerank'; capabilities: ['rerank'] }
  connection: { base_url: string; api_key: string }
  remote_model: string
  config: Record<string, never>
}
export type RerankDraft = DraftSave & { provider: DraftSave['provider'] & { adapter: RerankDriver } }
export type RerankVerification = Verification & { validation_evidence: (NonNullable<Verification['validation_evidence']> & { rerank_meter_code: RerankMeter; rerank_quantity: number | null }) | null }
const prefix = `${ADMIN_API_PREFIX}/pricing/revision`
export interface RerankPriceDraft {
  provider_id: number; model_id: number; capability_code: 'rerank'; name: string; currency: string
  effective_from: string; effective_until: string | null
  rates: [{ meter_code: RerankMeter; unit_price: string; per_quantity: number }]
}
export interface RerankPrice extends RerankPriceDraft { id: number; revision: number; state: 'draft' | 'published' }
export type PriceOperationReference = {
  kind: 'save' | 'publish'; provider_id: number; model_id: number; capability_code: 'rerank'
  target_id: number | null; request_key: string; input_sha256: string
}
export type PriceOperationRead = PriceOperationReference & {
  actor_id: number | null; state: 'unknown' | 'completed'; receipt: RerankPrice | null
}

function record(value: unknown): value is Record<string, unknown> { return typeof value === 'object' && value !== null && !Array.isArray(value) }
function id(value: unknown): value is number { return typeof value === 'number' && Number.isSafeInteger(value) && value > 0 }
export function meterFor(driver: RerankDriver): RerankMeter { return driver === 'cohere_rerank' ? 'rerank_search_units' : 'rerank_tokens' }
function verificationState(value: unknown): value is Verification['state'] {
  return value === 'draft' || value === 'verifying' || value === 'tested' || value === 'verification_ambiguous' || value === 'published'
}
function driver(value: unknown): value is RerankDriver { return value === 'cohere_rerank' || value === 'jina_rerank' }
function meter(value: unknown): value is RerankMeter { return value === 'rerank_search_units' || value === 'rerank_tokens' }
function draft(value: unknown): RerankDraft {
  if (!record(value) || !id(value.deployment_id) || !id(value.revision) || typeof value.idempotent_replay !== 'boolean'
    || !verificationState(value.state)
    || !record(value.provider) || !id(value.provider.id) || !driver(value.provider.adapter)
    || typeof value.provider.code !== 'string' || typeof value.provider.name !== 'string'
    || !record(value.model) || !id(value.model.id) || typeof value.model.code !== 'string' || typeof value.model.name !== 'string'
    || value.model.type !== 'rerank' || !Array.isArray(value.model.capabilities) || value.model.capabilities.length !== 1 || value.model.capabilities[0] !== 'rerank'
    || !record(value.deployment) || typeof value.deployment.remote_model !== 'string' || !value.deployment.remote_model
    || !record(value.deployment.config) || Object.keys(value.deployment.config).length !== 0) throw new Error('专业重排部署响应不完整，请保留原号只读找回。')
  return {
    deployment_id: value.deployment_id,
    revision: value.revision,
    state: value.state,
    idempotent_replay: value.idempotent_replay,
    provider: { id: value.provider.id, code: value.provider.code, name: value.provider.name, adapter: value.provider.adapter },
    model: { id: value.model.id, code: value.model.code, name: value.model.name, type: 'rerank', capabilities: ['rerank'] },
    deployment: { remote_model: value.deployment.remote_model, config: {} }
  }
}
function price(value: unknown, providerId: number, modelId: number, driver: RerankDriver): RerankPrice {
  if (!record(value) || !id(value.id) || !id(value.revision) || !id(value.provider_id) || !id(value.model_id) || value.provider_id !== providerId || value.model_id !== modelId
    || value.capability_code !== 'rerank' || (value.state !== 'draft' && value.state !== 'published')
    || typeof value.name !== 'string' || typeof value.currency !== 'string' || !/^[A-Z]{3}$/.test(value.currency)
    || typeof value.effective_from !== 'string' || !(value.effective_until === null || typeof value.effective_until === 'string')
    || !Array.isArray(value.rates) || value.rates.length !== 1 || !record(value.rates[0])
    || !meter(value.rates[0].meter_code) || value.rates[0].meter_code !== meterFor(driver) || typeof value.rates[0].unit_price !== 'string'
    || !/^(0|[1-9][0-9]{0,11})(\.[0-9]{1,8})?$/.test(value.rates[0].unit_price) || !id(value.rates[0].per_quantity)) throw new Error('价格版本或服务商单位未确认，请重新读取。')
  return {
    id: value.id,
    revision: value.revision,
    state: value.state,
    provider_id: value.provider_id,
    model_id: value.model_id,
    capability_code: 'rerank',
    name: value.name,
    currency: value.currency,
    effective_from: value.effective_from,
    effective_until: value.effective_until,
    rates: [{ meter_code: value.rates[0].meter_code, unit_price: value.rates[0].unit_price, per_quantity: value.rates[0].per_quantity }]
  }
}
export const pricing = {
  async readOperation(reference: PriceOperationReference, driver: RerankDriver, adminId: number): Promise<PriceOperationRead> {
    const { target_id, ...rest } = reference
    const value = await request.get<unknown>({ url: `${prefix}/read-operation-by-request-key`, params: { ...rest, ...(target_id === null ? {} : { target_id }) }, headers: { 'Cache-Control': 'no-store' }, showErrorMessage: false })
    if (!record(value) || value.kind !== reference.kind || value.provider_id !== reference.provider_id || value.model_id !== reference.model_id
      || value.capability_code !== reference.capability_code || value.target_id !== reference.target_id || value.request_key !== reference.request_key
      || value.input_sha256 !== reference.input_sha256) throw new Error('原价格操作归属未确认。')
    if (value.state === 'unknown' && value.actor_id === null && value.receipt === null) return { ...reference, actor_id: null, state: 'unknown', receipt: null }
    if (value.state !== 'completed' || value.actor_id !== adminId) throw new Error('原价格操作审核身份未确认。')
    const receipt = price(value.receipt, reference.provider_id, reference.model_id, driver)
    if ((target_id !== null && receipt.id !== target_id) || (reference.kind === 'publish' && receipt.state !== 'published')) throw new Error('原价格回执与原目标不一致。')
    return { ...reference, actor_id: adminId, state: 'completed', receipt }
  },
  async list(providerId: number, modelId: number, driver: RerankDriver): Promise<RerankPrice[]> {
    const result = await request.get<unknown>({ url: `${prefix}/index`, params: { provider_id: providerId, model_id: modelId, capability_code: 'rerank', page: 1, limit: 100 }, showErrorMessage: false })
    if (!record(result) || !Array.isArray(result.data)) throw new Error('价格目录未确认。')
    // Directory summaries omit rates; obtain each selected version through read instead.
    return Promise.all(result.data.map(async item => {
      if (!record(item) || !id(item.id)) throw new Error('价格目录未确认。')
      return price(await request.get<unknown>({ url: `${prefix}/read`, params: { id: item.id }, showErrorMessage: false }), providerId, modelId, driver)
    }))
  },
  async save(value: RerankPriceDraft, driver: RerankDriver, key: string): Promise<RerankPrice> {
    return price(await request.post<unknown>({ url: `${prefix}/save-draft`, data: value, headers: { 'Idempotency-Key': key }, showErrorMessage: false }), value.provider_id, value.model_id, driver)
  },
  async publish(value: RerankPrice, driver: RerankDriver, key: string): Promise<RerankPrice> {
    return price(await request.post<unknown>({ url: `${prefix}/publish`, data: { id: value.id }, headers: { 'Idempotency-Key': key }, showErrorMessage: false }), value.provider_id, value.model_id, driver)
  }
}
function verification(value: Verification): RerankVerification {
  const evidence: unknown = value.validation_evidence
  if (evidence === null) return { ...value, validation_evidence: null }
  if (!record(evidence) || !meter(evidence.rerank_meter_code)
    || !(evidence.rerank_quantity === null || (typeof evidence.rerank_quantity === 'number' && Number.isSafeInteger(evidence.rerank_quantity) && evidence.rerank_quantity >= 0))
    || typeof evidence.usage_measured !== 'boolean' || evidence.usage_measured !== (evidence.rerank_quantity !== null)
    || evidence.input_tokens !== null || evidence.output_tokens !== null || evidence.total_tokens !== null) throw new Error('专业重排验证计量未确认，请保留原号。')
  return { ...value, validation_evidence: {
    usage_measured: evidence.usage_measured,
    input_tokens: evidence.input_tokens,
    output_tokens: evidence.output_tokens,
    total_tokens: evidence.total_tokens,
    rerank_meter_code: evidence.rerank_meter_code,
    rerank_quantity: evidence.rerank_quantity
  } }
}
export default {
  async save(payload: RerankDeployment, key: string): Promise<RerankDraft> {
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
  async readSavedDraftByKey(key: string): Promise<RerankDraft> { return draft(await onboarding.readSavedDraftByKey(key)) },
  async readDraft(deploymentId: number, revision?: number): Promise<RerankDraft> { return draft(await onboarding.readDraft(deploymentId, revision)) },
  async verify(deploymentId: number, revision: number, location: string, key: string, timeoutMs?: number): Promise<RerankVerification> {
    return verification(await onboarding.verify(deploymentId, revision, location, key, timeoutMs))
  },
  async readVerification(deploymentId: number, revision: number, key?: string): Promise<RerankVerification> {
    return verification(await onboarding.readVerification(deploymentId, revision, key))
  },
  publish: onboarding.publish
}
