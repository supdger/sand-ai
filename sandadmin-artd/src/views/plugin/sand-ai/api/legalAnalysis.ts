import request from '@/utils/http'
import { ADMIN_API_PREFIX } from './config'
import { runtimeRequest } from './runtime'
import type { SandAiTask, SandAiWorkflowRun } from './types'

const profiles = `${ADMIN_API_PREFIX}/setup/legal-analysis/profiles`
export const legalModes = {
  legal_research_planning: '研究争点与检索式',
  judgment_remedy_planning: '裁判救济检索计划',
  judgment_remedy_analysis: '裁判救济研究结果',
  judgment_remedy_drafting: '裁判救济文书草稿',
  legal_research: '法律研究',
  litigation_preparation: '诉讼准备',
  judgment_analysis: '裁判分析',
  complaint_drafting: '生成起诉状草稿'
} as const
export const effectLabels = { effective: '声明有效', amended: '已修订，需核对', repealed: '声明已废止', unknown: '效力未核实' } as const
export type LegalMode = keyof typeof legalModes
export type EffectStatus = keyof typeof effectLabels
export interface LegalConfig {
  code: LegalMode
  version: string
  draft_kind: 'research_memo' | 'litigation_outline' | 'judgment_analysis_memo' | 'complaint_draft' | 'research_plan' | 'judgment_remedy_memo' | 'remedy_document_draft'
  output_language: 'zh-CN' | 'en'
  max_prompt_characters: number
  max_output_characters: number
}
export interface LegalProfile {
  id: number
  environment_id: number
  code: LegalMode
  version: string
  name: string
  revision: number
  state: 'draft' | 'published' | 'revoked'
  status: number
  config?: LegalConfig
}
export interface LegalOperationReference {
  operation: 'draft_save' | 'publish' | 'revoke'
  environment_id: number
  code: LegalMode
  version: string
  request_key: string
  expected_revision: number | null
}
export type LegalOperationReceipt = LegalOperationReference & {
  actor_id: number | null
  state: 'unknown' | 'completed'
  receipt: LegalProfile | null
}
function legalRecord(value: unknown): value is Record<string, unknown> { return typeof value === 'object' && value !== null && !Array.isArray(value) }
function legalId(value: unknown): value is number { return typeof value === 'number' && Number.isSafeInteger(value) && value > 0 }
function originalProfile(value: unknown, original: LegalOperationReference): LegalProfile {
  const state = original.operation === 'draft_save' ? 'draft' : original.operation === 'publish' ? 'published' : 'revoked'
  if (!legalRecord(value) || !legalId(value.id) || value.environment_id !== original.environment_id || value.code !== original.code
    || value.version !== original.version || typeof value.name !== 'string' || !value.name || !legalId(value.revision)
    || value.state !== state || value.status !== (original.operation === 'publish' ? 1 : 2)
    || (original.operation === 'draft_save' && value.revision !== (original.expected_revision ?? 0) + 1)
    || (original.operation === 'publish' && value.revision !== original.expected_revision)) throw new Error('原法律配置回执与目标不一致，请保留原请求号。')
  const profile: LegalProfile = { id: value.id, environment_id: original.environment_id, code: original.code, version: original.version,
    name: value.name, revision: value.revision, state, status: original.operation === 'publish' ? 1 : 2 }
  if (original.operation === 'draft_save') {
    const config = value.config
    if (!legalRecord(config) || config.code !== original.code || config.version !== original.version
      || !['research_memo','litigation_outline','judgment_analysis_memo','complaint_draft','research_plan','judgment_remedy_memo','remedy_document_draft'].includes(String(config.draft_kind))
      || (config.output_language !== 'zh-CN' && config.output_language !== 'en')
      || typeof config.max_prompt_characters !== 'number' || !Number.isSafeInteger(config.max_prompt_characters) || config.max_prompt_characters < 1000 || config.max_prompt_characters > 200000
      || typeof config.max_output_characters !== 'number' || !Number.isSafeInteger(config.max_output_characters) || config.max_output_characters < 1000 || config.max_output_characters > 500000) throw new Error('原法律配置内容未确认，请保留原请求号。')
    profile.config = { code: original.code, version: original.version, draft_kind: config.draft_kind as LegalConfig['draft_kind'],
      output_language: config.output_language, max_prompt_characters: config.max_prompt_characters, max_output_characters: config.max_output_characters }
  }
  return profile
}
export type LegalDraft = Omit<LegalConfig, 'draft_kind'> & { name: string }
export interface LegalSource {
  source_ref: string
  source_version: string
  declared_effect_status: EffectStatus
  file_id: number
  file_sha256: string
  parse_id: number
  version_ref: string
  source_block_ids: number[]
}
export interface LegalSnapshot extends Omit<LegalSource, 'source_block_ids'> {
  authority_state: 'consumer_declared'
  blocks: Array<{ source_block_id: number; content: string; content_sha256: string; locator_type: string; locator: Record<string, unknown> }>
}
export interface LegalFact {
  fact_ref: string
  fact_version: string
  summary: string
  source_block_ids: number[]
}
export interface ComplaintDraftInput {
  document_type: 'civil_complaint'
  input_version: string
  party_version: string
  claims_version: string
  parties: Array<{ party_ref: string; role: 'plaintiff' | 'defendant' | 'third_party'; name: string; identity_summary: string; source_block_ids: number[] }>
  claims: Array<{ claim_ref: string; content: string; source_block_ids: number[] }>
  evidence: Array<{ evidence_ref: string; description: string; source_block_ids: number[] }>
  template: { template_ref: string; template_version: string; source_ref: string; document_type: 'civil_complaint'; sections: Array<{ key: 'parties' | 'claims' | 'facts_reasons' | 'legal_basis' | 'evidence' | 'closing'; title: string; source_block_ids: number[] }> }
  source_roles: Array<{ source_ref: string; role: 'case_material' | 'legal_authority' | 'firm_template'; authority_type: null | 'statute' | 'judicial_interpretation' | 'case' | 'guidance' | 'other' }>
  consumer_confirmed: true
}
export interface RemedyAnalysisPackage {
  schema: 'judgment-analysis-package.v1'
  stage_snapshot: RemedyInput['stage']
  document_type: string
  input_version: string
  document_version_refs: RemedyInput['document_version_refs']
  case_version_refs: RemedyInput['case_version_refs']
  evidence_version_refs: RemedyInput['evidence_version_refs']
  analysis_snapshot: null
  judgment_findings: Array<{ kind: 'disposition' | 'facts' | 'reasoning' | 'cited_authority' | 'cost_allocation'; description: string; source_block_ids: number[] }>
  claim_comparison: Array<{ description: string; status: 'accepted' | 'not_accepted' | 'unanswered' | 'requires_review'; prior_source_block_ids: number[]; judgment_source_block_ids: number[] }>
  remedy_research_questions: Array<{ description: string; supporting_source_block_ids: number[]; contrary_source_block_ids: number[] }>
  procedural_risks: Array<{ description: string; source_block_ids: number[] }>
  missing_information: Array<{ description: string; source_block_ids: number[] }>
  outline: []
  citations: number[]
  legal_basis: Array<{ source_ref: string; description: string; source_block_ids: number[] }>
  case_authorities: Array<{ source_ref: string; description: string; source_block_ids: number[] }>
  research_plan: null
}
export interface RemedyInput {
  stage: {
    stage_id: string
    stage_version: string
    judgment_material_version_id: string
    stage_type: string
    judgment_document_type: string
    research_remedy_type: 'appeal' | 'retrial' | 'procuratorial_protest' | 'petition'
    research_goal: string
    research_as_of_date: string
    service_time: string | null
    effective_state: string
    effective_time: string | null
    limitation_start_fact: string | null
    confirmed_by: string | null
    confirmed_time: string | null
    snapshot_sha256: string
  }
  document_type: string
  input_version: string
  document_version_refs: Array<{ document_ref: string; document_version: string; source_ref: string }>
  case_version_refs: Array<{ case_ref: string; case_version: string }>
  evidence_version_refs: Array<{ evidence_ref: string; evidence_version: string; source_ref: string }>
  source_roles: Array<{ source_ref: string; role: 'judgment' | 'prior_record' | 'evidence' | 'case_material' | 'legal_authority' | 'firm_template'; authority_type: null | 'statute' | 'judicial_interpretation' | 'case' | 'guidance' | 'other' }>
  applicable_authority_refs: string[]
  template: null | { template_ref: string; template_version: string; source_ref: string; document_type: string; sections: Array<{ key: string; title: string; source_block_ids: number[] }> }
  analysis_snapshot: null | { analysis_request_ref: string; run_id: string; task_id: string; payload_sha256: string }
  analysis_package: RemedyAnalysisPackage | null
  analysis_package_sha256: string | null
  consumer_confirmed: true
}
export interface LegalRunInput {
  remedy_input?: RemedyInput
  complaint_input?: ComplaintDraftInput
  business_scope: { ref: string; snapshot_ref: string; snapshot_version: string }
  workflow: { code: 'legal_analysis'; version: '1.0.0' }
  output_schema: { code: 'sand_ai.legal_analysis'; version: '1.0.0' }
  files: Array<{ file_id: number; sha256: string; version_ref: string }>
  legal_profile: { code: LegalMode; version: string }
  legal_context: { issues: string[]; jurisdiction: string; case_type: string; as_of_date: string }
  legal_sources: LegalSource[]
  confirmed_facts: LegalFact[]
  review_required: true
}
export default {
  templates() { return request.get<{ profiles: LegalConfig[] }>({ url: `${profiles}/templates`, showErrorMessage: false }) },
  list(environmentId: number) { return request.get<{ profiles: LegalProfile[] }>({ url: `${profiles}/index`, params: { environment_id: environmentId }, showErrorMessage: false }) },
  read(environmentId: number, code: LegalMode, version: string) { return request.get<{ profile: LegalProfile }>({ url: `${profiles}/read`, params: { environment_id: environmentId, code, version }, showErrorMessage: false }) },
  save(environmentId: number, expectedRevision: number | null, profile: LegalDraft, key?: string) { return request.post<{ profile: LegalProfile }>({ url: `${profiles}/draft/save`, data: { environment_id: environmentId, expected_revision: expectedRevision, profile }, ...(key ? { headers: { 'Idempotency-Key': key } } : {}), showErrorMessage: false }) },
  publish(environmentId: number, profile: LegalProfile, key?: string) { return request.post<{ profile: LegalProfile }>({ url: `${profiles}/publish`, data: { environment_id: environmentId, code: profile.code, version: profile.version, revision: profile.revision }, ...(key ? { headers: { 'Idempotency-Key': key } } : {}), showErrorMessage: false }) },
  revoke(environmentId: number, profile: LegalProfile, key?: string) { return request.post<{ profile: LegalProfile }>({ url: `${profiles}/revoke`, data: { environment_id: environmentId, code: profile.code, version: profile.version }, ...(key ? { headers: { 'Idempotency-Key': key } } : {}), showErrorMessage: false }) },
  async readOperation(original: LegalOperationReference, adminId: number): Promise<LegalOperationReceipt> {
    const { expected_revision, ...reference } = original
    const value = await request.get<unknown>({ url: `${profiles}/operations/by-request-key`, params: { ...reference, ...(expected_revision === null ? {} : { expected_revision }) }, headers: { 'Cache-Control': 'no-store' }, showErrorMessage: false })
    if (!legalRecord(value) || value.operation !== original.operation || value.environment_id !== original.environment_id
      || value.code !== original.code || value.version !== original.version || value.request_key !== original.request_key
      || value.expected_revision !== expected_revision) throw new Error('原法律配置操作归属未确认。')
    if (value.state === 'unknown' && value.actor_id === null && value.receipt === null) return { ...original, actor_id: null, state: 'unknown', receipt: null }
    if (value.state !== 'completed' || value.actor_id !== adminId || !legalId(adminId)) throw new Error('原法律配置操作身份未确认。')
    return { ...original, actor_id: adminId, state: 'completed', receipt: originalProfile(value.receipt, original) }
  },
  snapshot(apiKey: string, sources: LegalSource[]) { return runtimeRequest<{ sources: LegalSnapshot[] }>({ method: 'POST', path: '/legal-sources/snapshots', apiKey, data: { sources } }) },
  submit(apiKey: string, body: LegalRunInput, key: string) { return runtimeRequest<{ idempotent_replay: boolean; run: { run_id: number; task: SandAiTask | null } }>({ method: 'POST', path: '/structured-workflows/runs', apiKey, data: { ...body }, headers: { 'Idempotency-Key': key } }) },
  recover(apiKey: string, key: string) { return runtimeRequest<SandAiWorkflowRun>({ method: 'GET', path: '/structured-workflows/runs/by-request-key', apiKey, params: { request_key: key } }) }
}
