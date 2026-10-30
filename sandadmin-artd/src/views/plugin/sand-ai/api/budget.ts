import request from '@/utils/http'
import { ADMIN_API_PREFIX } from './config'
import type { SandAiPage } from './types'

export type BudgetScopeKind = 'organization' | 'application' | 'environment' | 'service' | 'capability' | 'model'
export interface BudgetScope { scope_kind: BudgetScopeKind; scope_ref: string }
export interface BudgetReference { id: number; code: string; name: string }

export interface BudgetPolicy extends BudgetScope {
  id: number
  revision: number
  environment_id: number | null
  period_start: string
  period_end: string
  currency: string
  limit_amount: string
  enforcement: 'hard' | 'soft'
  unpriced_policy: 'block' | 'allow'
  unmeasured_policy: 'hold' | 'allow'
  state: 'draft' | 'published' | 'archived'
  published_time: string | null
  create_time: string
  update_time: string
}

export type BudgetPolicyExpectation = Pick<BudgetPolicy,
  'revision' | 'scope_kind' | 'scope_ref' | 'environment_id' | 'period_start' | 'period_end' |
  'currency' | 'limit_amount' | 'enforcement' | 'unpriced_policy' | 'unmeasured_policy'>

export interface BudgetDraft extends BudgetScope {
  id?: number
  environment_id?: number
  reference_environment_id?: number
  period_start: string
  period_end: string
  currency: string
  limit_amount: string
  enforcement: 'hard' | 'soft'
  unpriced_policy: 'block' | 'allow'
  unmeasured_policy: 'hold' | 'allow'
}

export interface BudgetWindow {
  currency: string
  limit_amount: string
  reserved_amount: string
  settled_amount: string
  held_amount: string
  available_amount: string
}

export interface BudgetSummary {
  policy: BudgetPolicy
  window: BudgetWindow
  alert: { level: 'normal' | 'notice' | 'warning' | 'exhausted'; threshold_percent: number | null }
}

export interface BudgetDraftNotSaved {
  state: 'not_saved'
  error_code: string
  message: string
  idempotent_replay: true
}
export type BudgetDraftSaveResult = (BudgetPolicy & { idempotent_replay?: true }) | BudgetDraftNotSaved

const prefix = `${ADMIN_API_PREFIX}/budget`

export default {
  references(page: number) {
    return request.get<{ models: SandAiPage<BudgetReference>; capabilities: { code: string; name: string }[] }>({ url: `${prefix}/references`, params: { page } })
  },
  organizations(page: number) {
    return request.get<SandAiPage<BudgetReference>>({ url: '/app/sand-iam/admin/organization/index', params: { page, limit: 100 } })
  },
  list(scope: BudgetScope, page: number) {
    return request.get<SandAiPage<BudgetPolicy>>({
      url: `${prefix}/policy/index`,
      params: { ...scope, page, limit: 20 }
    })
  },
  readPolicy(id: number) {
    return request.get<BudgetPolicy>({
      url: `${prefix}/policy/read`, params: { id }, headers: { 'Cache-Control': 'no-store' }, showErrorMessage: false
    })
  },
  saveDraft(data: BudgetDraft, requestKey: string) {
    return request.post<BudgetDraftSaveResult>({
      url: `${prefix}/policy/save-draft`, data, headers: { 'Idempotency-Key': requestKey }, showErrorMessage: false
    })
  },
  draftSaveByRequestKey(scope: BudgetScope, requestKey: string) {
    return request.get<BudgetDraftSaveResult>({
      url: `${prefix}/policy/read-draft-by-request-key`,
      params: { scope_kind: scope.scope_kind, scope_ref: scope.scope_ref, request_key: requestKey },
      headers: { 'Cache-Control': 'no-store' }, showErrorMessage: false
    })
  },
  publish(id: number, referenceEnvironmentId?: number, expectedPolicy?: BudgetPolicyExpectation) {
    return request.post<BudgetPolicy>({ url: `${prefix}/policy/publish`,
      data: { id, reference_environment_id: referenceEnvironmentId, expected_policy: expectedPolicy } })
  },
  archive(id: number, referenceEnvironmentId?: number) {
    return request.post<BudgetPolicy>({ url: `${prefix}/policy/archive`, data: { id, reference_environment_id: referenceEnvironmentId } })
  },
  summary(policyId: number) {
    return request.get<BudgetSummary>({
      url: `${prefix}/summary`,
      params: { policy_id: policyId }
    })
  },
  active(scope: BudgetScope) {
    return request.get<{ active: boolean; summary: BudgetSummary | null }>({
      url: `${prefix}/active`,
      params: scope
    })
  }
}
