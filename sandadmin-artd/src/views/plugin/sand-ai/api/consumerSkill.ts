/** Existing §5.2.1 workload APIs. Admin login never supplies consumer identity. */
import { runtimeRequest } from './runtime'
import type { SandAiInvocationCost } from './types'
import type {
  SkillRow,
  SkillVersion,
  SkillSource,
  SkillOperation,
  SkillTestResult,
  SkillCaseRow,
  SkillCitation
} from './skill'

export interface SkillConsumerDeclaration {
  scope: { type: 'personal' | 'team' | 'organization'; ref: string }
  actor_ref: string
}
/** Verified workload references, separate from consumer-declared people and groups. */
export interface SkillWorkloadIdentity {
  organization_id: string
  application_id: string
  environment_id: string
  workload_client_id: string
}
export interface ConsumerSkillPage<T> {
  current_page: number
  per_page: number
  data: T[]
  /** Last authorized item only; a subsequent request can be empty. */
  next_cursor: number | null
}
export interface ConsumerSkillWorkspace extends ConsumerSkillPage<SkillRow> {
  workload_identity: SkillWorkloadIdentity
}
export interface SkillDraftReplacement {
  replace_draft: true
  expected_draft_version: number
}
export interface ConsumerSkillDetail {
  skill: Omit<SkillRow, 'active_version' | 'draft_version'> & {
    scope: SkillConsumerDeclaration['scope']
    scope_source: 'consumer_declared'
  }
  versions: SkillVersion[]
}
export interface ConsumerSkillDraft {
  code?: string
  名称: string
  描述: string
  指令: string
  输入结构: Record<string, unknown>
  输出结构: Record<string, unknown>
  复核策略: { review_required: boolean }
  知识库版本引用: {
    knowledge_base_version_id: number
    snapshot_sha256: string
  }
}
export interface CaseOperation {
  case_id: number
  idempotent_replay: boolean
}
export interface SkillSuggestionInput {
  knowledge_base_version_id: number
  snapshot_sha256: string
  query: string
  model: string
  reuse_declaration: string
}
export interface SkillMethodSuggestion {
  suggestion_no: number
  name: string
  method: string
  trigger: string
  prohibitions: string
  source_block_ids: number[]
}
export interface SkillSuggestionResult {
  source_suggestion_id: number
  state: 'generating' | 'pending_review' | 'adopted' | 'failed'
  methods: SkillMethodSuggestion[]
  citations: SkillCitation[]
  invocation_id: number
  usage: Record<string, unknown> | []
  error_code: string | null
  idempotent_replay: boolean
  review_required: true
}
export interface SkillSuggestionPurged {
  suggestion_id: number
  state: 'content_purged'
  content_purged_at: string
  idempotent_replay: boolean
  remaining_private_fields: number
  remaining_receipt_ciphertexts: number
  provenance_versions_purged: number
  receipt_ciphertexts_purged: number
  accounting_state: 'settled' | 'pending' | 'not_invoked' | 'unknown'
}
export type SkillSuggestionRead = SkillSuggestionResult | SkillSuggestionPurged

export type ConsumerSkillReceiptTarget =
  | { operation: 'publish' | 'rollback'; code: string; version: number }
  | { operation: 'revoke'; code: string }
  | { operation: 'review'; code: string; version: number; case_id: number; skill_run_id?: number; verdict?: 'pass' | 'fail' }
  | { operation: 'retire'; code: string; version: number; case_id: number }
  | { operation: 'source_adopt'; source_suggestion_id: number; code?: string }
interface ConsumerSkillReceiptBase {
  request_id: string
  input_sha256: string
  skill_id: number
  version_id: number
  code: string
  version: number
  idempotent_replay: true
  scope: SkillConsumerDeclaration['scope']
  scope_source: 'consumer_declared'
  actor_ref: string
}
export type ConsumerSkillOperationReceipt = ConsumerSkillReceiptBase & (
  | { operation: 'publish' | 'rollback'; state: 'published'; request_fingerprint: string }
  | { operation: 'revoke'; state: 'revoked'; request_fingerprint: string }
  | { operation: 'review'; evaluation_id: number; case_id: number; skill_run_id: number; verdict: 'pass' | 'fail' }
  | { operation: 'retire'; case_id: number; state: 'retired' }
  | { operation: 'source_adopt'; state: 'draft'; source_suggestion_id: number; request_fingerprint: string }
)
function positiveReceiptId(value: unknown): value is number {
  return typeof value === 'number' && Number.isSafeInteger(value) && value > 0
}
function operationReceipt(value: unknown, declaration: SkillConsumerDeclaration, target: ConsumerSkillReceiptTarget,
  key: string, inputSha256?: string): ConsumerSkillOperationReceipt {
  if (typeof value !== 'object' || value === null || Array.isArray(value)) throw new Error('原操作回执无法确认，请保留原请求')
  const row = value as Record<string, unknown>
  const scope = row.scope
  if (row.operation !== target.operation || row.request_id !== key || row.idempotent_replay !== true ||
    row.scope_source !== 'consumer_declared' || row.actor_ref !== declaration.actor_ref ||
    typeof scope !== 'object' || scope === null || Array.isArray(scope) || !('type' in scope) || !('ref' in scope) ||
    scope.type !== declaration.scope.type || scope.ref !== declaration.scope.ref ||
    !positiveReceiptId(row.skill_id) || !positiveReceiptId(row.version_id) || !positiveReceiptId(row.version) ||
    typeof row.code !== 'string' || !/^[a-z0-9][a-z0-9_-]{1,63}$/.test(row.code) ||
    typeof row.input_sha256 !== 'string' || !/^[a-f0-9]{64}$/.test(row.input_sha256) ||
    (inputSha256 !== undefined && row.input_sha256 !== inputSha256) ||
    ('code' in target && target.code !== undefined && row.code !== target.code) ||
    ('version' in target && row.version !== target.version))
    throw new Error('原操作回执与原作用域、目标或输入摘要不一致，请保留原请求')
  const base: ConsumerSkillReceiptBase = { request_id: key, input_sha256: row.input_sha256, skill_id: row.skill_id,
    version_id: row.version_id, code: row.code, version: row.version, idempotent_replay: true,
    scope: { ...declaration.scope }, scope_source: 'consumer_declared', actor_ref: declaration.actor_ref }
  if (target.operation === 'review') {
    if (row.case_id !== target.case_id || !positiveReceiptId(row.evaluation_id) || !positiveReceiptId(row.skill_run_id) ||
      (row.verdict !== 'pass' && row.verdict !== 'fail') ||
      (target.skill_run_id !== undefined && row.skill_run_id !== target.skill_run_id) ||
      (target.verdict !== undefined && row.verdict !== target.verdict))
      throw new Error('原评审回执与原案例或评测不一致，请保留原请求')
    return { ...base, operation: 'review', evaluation_id: row.evaluation_id, case_id: target.case_id,
      skill_run_id: row.skill_run_id, verdict: row.verdict }
  }
  if (target.operation === 'retire') {
    if (row.case_id !== target.case_id || row.state !== 'retired') throw new Error('原停用案例回执尚未确认，请保留原请求')
    return { ...base, operation: 'retire', case_id: target.case_id, state: 'retired' }
  }
  if (typeof row.request_fingerprint !== 'string' || !/^[a-f0-9]{64}$/.test(row.request_fingerprint))
    throw new Error('原操作不可变证明尚未确认，请保留原请求')
  if (target.operation === 'source_adopt') {
    if (row.source_suggestion_id !== target.source_suggestion_id || row.state !== 'draft')
      throw new Error('原来源采用回执尚未确认，请保留原请求')
    return { ...base, operation: 'source_adopt', state: 'draft', source_suggestion_id: target.source_suggestion_id,
      request_fingerprint: row.request_fingerprint }
  }
  if (target.operation === 'revoke') {
    if (row.state !== 'revoked') throw new Error('原撤销回执尚未确认，请保留原请求')
    return { ...base, operation: 'revoke', state: 'revoked', request_fingerprint: row.request_fingerprint }
  }
  if (row.state !== 'published') throw new Error('原发布或回退回执尚未确认，请保留原请求')
  return { ...base, operation: target.operation, state: 'published', request_fingerprint: row.request_fingerprint }
}

function query(declaration: SkillConsumerDeclaration): Record<string, string> {
  return {
    scope_type: declaration.scope.type,
    scope_ref: declaration.scope.ref,
    actor_ref: declaration.actor_ref
  }
}
function versionPath(code: string, version: number): string {
  return `/skills/${encodeURIComponent(code)}/versions/${version}`
}
function get<T>(
  apiKey: string,
  declaration: SkillConsumerDeclaration,
  path: string,
  params: Record<string, string | number> = {}
): Promise<T> {
  return runtimeRequest<T>({
    method: 'GET',
    path,
    apiKey,
    params: { ...query(declaration), ...params }
  })
}
function post<T>(
  apiKey: string,
  declaration: SkillConsumerDeclaration,
  path: string,
  body: Record<string, unknown>,
  key: string
): Promise<T> {
  return runtimeRequest<T>({
    method: 'POST',
    path,
    apiKey,
    data: { ...declaration, ...body },
    headers: { 'Idempotency-Key': key }
  })
}

function managementResult(value: unknown, code: string, state: 'published' | 'revoked', version?: number): SkillOperation {
  if (typeof value !== 'object' || value === null || Array.isArray(value)) throw new Error('原管理响应无效，请保留原请求号')
  const result = value as Record<string, unknown>
  if (result.code !== code || result.state !== state || typeof result.idempotent_replay !== 'boolean'
    || typeof result.skill_id !== 'number' || !Number.isSafeInteger(result.skill_id) || result.skill_id < 1
    || typeof result.version_id !== 'number' || !Number.isSafeInteger(result.version_id) || result.version_id < 1
    || typeof result.version !== 'number' || !Number.isSafeInteger(result.version) || result.version < 1
    || (version !== undefined && result.version !== version)) throw new Error('管理响应与原方法包及版本不一致，请保留原请求号')
  return { code, state, idempotent_replay: result.idempotent_replay,
    skill_id: result.skill_id, version_id: result.version_id, version: result.version }
}
export interface SavedCaseReceipt extends CaseOperation {
  case_no: number
  skill_id: number
  version_id: number
  code: string
  version: number
  request_fingerprint: string
}
function savedReceipt(value: unknown, key: string, code?: string, version?: number): Record<string, unknown> {
  if (typeof value !== 'object' || value === null || Array.isArray(value)) throw new Error('原保存回执无效，请保留原请求号')
  const result = value as Record<string, unknown>
  if (result.idempotent_replay !== true || result.request_id !== key
    || typeof result.request_fingerprint !== 'string' || !/^[a-f0-9]{64}$/.test(result.request_fingerprint)
    || typeof result.code !== 'string' || !/^[a-z0-9][a-z0-9_-]{1,63}$/.test(result.code)
    || (code !== undefined && result.code !== code) || (version !== undefined && result.version !== version)
    || ![result.skill_id, result.version_id, result.version].every(item => typeof item === 'number' && Number.isSafeInteger(item) && item > 0))
    throw new Error('保存回执与原请求或固定目标不一致，请保留原请求号')
  return result
}
function savedDraftReceipt(value: unknown, key: string, code?: string): SkillOperation {
  const result = savedReceipt(value, key, code)
  if (result.state !== 'draft') throw new Error('原草稿保存回执未确认，请保留原请求号')
  return { code: result.code as string, state: 'draft', idempotent_replay: true,
    skill_id: result.skill_id as number, version_id: result.version_id as number, version: result.version as number }
}
function savedCaseReceipt(value: unknown, key: string, code: string, version: number): SavedCaseReceipt {
  const result = savedReceipt(value, key, code, version)
  if (![result.case_id, result.case_no].every(item => typeof item === 'number' && Number.isSafeInteger(item) && item > 0))
    throw new Error('原案例保存回执未确认，请保留原请求号')
  return { code, version, idempotent_replay: true, request_fingerprint: result.request_fingerprint as string,
    skill_id: result.skill_id as number, version_id: result.version_id as number,
    case_id: result.case_id as number, case_no: result.case_no as number }
}

function suggestionPurgeResult(value: unknown, suggestionId: number): SkillSuggestionPurged {
  if (typeof value !== 'object' || value === null || Array.isArray(value)) throw new Error('原清理响应无效，请保留原请求号')
  const result = value as Record<string, unknown>
  if (result.suggestion_id !== suggestionId || result.state !== 'content_purged'
    || typeof result.content_purged_at !== 'string' || result.content_purged_at === ''
    || typeof result.idempotent_replay !== 'boolean' || result.remaining_private_fields !== 0 || result.remaining_receipt_ciphertexts !== 0
    || typeof result.provenance_versions_purged !== 'number' || !Number.isSafeInteger(result.provenance_versions_purged) || result.provenance_versions_purged < 0
    || typeof result.receipt_ciphertexts_purged !== 'number' || !Number.isSafeInteger(result.receipt_ciphertexts_purged) || result.receipt_ciphertexts_purged < 0
    || !['settled', 'pending', 'not_invoked', 'unknown'].includes(String(result.accounting_state)))
    throw new Error('原建议清理尚未完全确认，请保留原请求号')
  return { suggestion_id: suggestionId, state: 'content_purged', content_purged_at: result.content_purged_at,
    idempotent_replay: result.idempotent_replay, remaining_private_fields: 0, remaining_receipt_ciphertexts: 0,
    provenance_versions_purged: result.provenance_versions_purged, receipt_ciphertexts_purged: result.receipt_ciphertexts_purged,
    accounting_state: result.accounting_state as SkillSuggestionPurged['accounting_state'] }
}

export default {
  async readOperationReceipt(apiKey: string, declaration: SkillConsumerDeclaration, target: ConsumerSkillReceiptTarget,
    key: string, inputSha256?: string): Promise<ConsumerSkillOperationReceipt> {
    const path = target.operation === 'source_adopt'
      ? `/skills/source-suggestions/${target.source_suggestion_id}/adoption/by-request-key`
      : target.operation === 'revoke'
        ? `/skills/${encodeURIComponent(target.code)}/operations/revoke/by-request-key`
        : target.operation === 'review' || target.operation === 'retire'
          ? `${versionPath(target.code, target.version)}/cases/${target.case_id}/operations/${target.operation}/by-request-key`
          : `${versionPath(target.code, target.version)}/operations/${target.operation}/by-request-key`
    const value: unknown = await get<unknown>(apiKey, declaration, path,
      { request_id: key, ...(inputSha256 === undefined ? {} : { input_sha256: inputSha256 }) })
    return operationReceipt(value, declaration, target, key, inputSha256)
  },
  suggest(
    apiKey: string,
    declaration: SkillConsumerDeclaration,
    source: SkillSuggestionInput,
    key: string
  ) {
    return post<SkillSuggestionRead>(apiKey, declaration, '/skills/source-suggestions', { source }, key)
  },
  readSuggestion(apiKey: string, declaration: SkillConsumerDeclaration, key: string) {
    return get<SkillSuggestionRead>(apiKey, declaration, '/skills/source-suggestions/by-request-key', {
      request_id: key
    })
  },
  adoptSuggestion(
    apiKey: string,
    declaration: SkillConsumerDeclaration,
    suggestionId: number,
    selected: number[],
    draft: ConsumerSkillDraft,
    key: string
  ) {
    return post<SkillOperation>(apiKey, declaration, `/skills/source-suggestions/${suggestionId}/adopt`,
      { selected, draft }, key)
  },
  async purgeSuggestion(apiKey: string, declaration: SkillConsumerDeclaration, suggestionId: number, key: string): Promise<SkillSuggestionPurged> {
    const result: unknown = await runtimeRequest<unknown>({
      method: 'DELETE',
      path: `/skills/source-suggestions/${suggestionId}/content`,
      apiKey,
      data: { ...declaration },
      headers: { 'Idempotency-Key': key }
    })
    return suggestionPurgeResult(result, suggestionId)
  },
  list(apiKey: string, declaration: SkillConsumerDeclaration, page = 1, beforeId?: number) {
    return get<ConsumerSkillWorkspace>(apiKey, declaration, '/skills', {
      page,
      limit: 20,
      ...(beforeId === undefined ? {} : { before_id: beforeId })
    })
  },
  read(apiKey: string, declaration: SkillConsumerDeclaration, code: string) {
    return get<ConsumerSkillDetail>(apiKey, declaration, `/skills/${encodeURIComponent(code)}`)
  },
  sources(apiKey: string, declaration: SkillConsumerDeclaration, page = 1, beforeId?: number) {
    return get<ConsumerSkillPage<SkillSource>>(apiKey, declaration, '/skills/sources', {
      page,
      limit: 100,
      ...(beforeId === undefined ? {} : { before_id: beforeId })
    })
  },
  draft(
    apiKey: string,
    declaration: SkillConsumerDeclaration,
    draft: ConsumerSkillDraft,
    key: string,
    replacement?: SkillDraftReplacement
  ) {
    return post<SkillOperation>(
      apiKey,
      declaration,
      '/skills/drafts',
      { draft, ...replacement },
      key
    )
  },
  async readDraftReceipt(apiKey: string, declaration: SkillConsumerDeclaration, key: string, code?: string): Promise<SkillOperation> {
    const result: unknown = await get<unknown>(apiKey, declaration, '/skills/drafts/by-request-key',
      { request_id: key })
    return savedDraftReceipt(result, key, code)
  },
  async readCreatedCase(apiKey: string, declaration: SkillConsumerDeclaration, code: string, version: number, key: string): Promise<SavedCaseReceipt> {
    const result: unknown = await get<unknown>(apiKey, declaration, `${versionPath(code, version)}/cases/by-request-key`,
      { request_id: key })
    return savedCaseReceipt(result, key, code, version)
  },
  cases(apiKey: string, declaration: SkillConsumerDeclaration, code: string, version: number) {
    return get<{ skill_version_id: number; cases: SkillCaseRow[] }>(
      apiKey,
      declaration,
      `${versionPath(code, version)}/cases`
    )
  },
  createCase(
    apiKey: string,
    declaration: SkillConsumerDeclaration,
    code: string,
    version: number,
    name: string,
    input: Record<string, unknown>,
    expectedCriteria: string,
    key: string
  ) {
    return post<CaseOperation>(
      apiKey,
      declaration,
      `${versionPath(code, version)}/cases`,
      { name, input, expected_criteria: expectedCriteria },
      key
    )
  },
  test(
    apiKey: string,
    declaration: SkillConsumerDeclaration,
    code: string,
    version: number,
    input: Record<string, unknown>,
    key: string
  ) {
    return post<SkillTestResult>(
      apiKey,
      declaration,
      `${versionPath(code, version)}/tests`,
      { input },
      key
    )
  },
  recover(
    apiKey: string,
    declaration: SkillConsumerDeclaration,
    code: string,
    version: number,
    kind: 'test' | 'run',
    key: string
  ) {
    return get<SkillTestResult>(
      apiKey,
      declaration,
      `${versionPath(code, version)}/${kind === 'test' ? 'tests' : 'runs'}/by-request-key`,
      { request_id: key }
    )
  },
  run(
    apiKey: string,
    declaration: SkillConsumerDeclaration,
    code: string,
    version: number,
    input: Record<string, unknown>,
    key: string
  ) {
    return post<SkillTestResult>(
      apiKey,
      declaration,
      `${versionPath(code, version)}/runs`,
      { input, request_id: key },
      key
    )
  },
  review(
    apiKey: string,
    declaration: SkillConsumerDeclaration,
    code: string,
    version: number,
    caseId: number,
    runId: number,
    verdict: 'pass' | 'fail',
    note: string,
    key: string
  ) {
    return post<CaseOperation>(
      apiKey,
      declaration,
      `${versionPath(code, version)}/cases/${caseId}/review`,
      { skill_run_id: runId, verdict, note },
      key
    )
  },
  retire(
    apiKey: string,
    declaration: SkillConsumerDeclaration,
    code: string,
    version: number,
    caseId: number,
    reason: string,
    key: string
  ) {
    return post<CaseOperation>(
      apiKey,
      declaration,
      `${versionPath(code, version)}/cases/${caseId}/retire`,
      { reason },
      key
    )
  },
  async publish(
    apiKey: string,
    declaration: SkillConsumerDeclaration,
    code: string,
    version: number,
    key: string
  ) {
    const result: unknown = await post<unknown>(
      apiKey, declaration, `${versionPath(code, version)}/publish`, {}, key
    )
    return managementResult(result, code, 'published', version)
  },
  async revoke(apiKey: string, declaration: SkillConsumerDeclaration, code: string, key: string): Promise<SkillOperation> {
    const result: unknown = await post<unknown>(
      apiKey, declaration, `/skills/${encodeURIComponent(code)}/revoke`, {}, key
    )
    return managementResult(result, code, 'revoked')
  },
  rollback(
    apiKey: string,
    declaration: SkillConsumerDeclaration,
    code: string,
    version: number,
    key: string
  ) {
    return post<SkillOperation>(
      apiKey,
      declaration,
      `${versionPath(code, version)}/rollback`,
      {},
      key
    )
  },
  historicalCost(
    apiKey: string,
    declaration: SkillConsumerDeclaration,
    code: string,
    version: number,
    kind: 'test' | 'run',
    originalKey: string
  ) {
    return get<SandAiInvocationCost>(
      apiKey,
      declaration,
      `${versionPath(code, version)}/${kind === 'test' ? 'tests' : 'runs'}/by-request-key/cost`,
      { request_id: originalKey }
    )
  },
  suggestionCost(apiKey: string, requestId: string) {
    return runtimeRequest<SandAiInvocationCost>({
      method: 'GET',
      path: `/invocations/${encodeURIComponent(requestId).replace(/%3A/gi, ':')}/cost`,
      apiKey
    })
  }
}
