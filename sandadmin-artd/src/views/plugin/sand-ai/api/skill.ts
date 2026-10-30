import request from '@/utils/http'
import { ADMIN_API_PREFIX } from './config'
import type { SandAiPage } from './types'
import type { SandAiInvocationCost } from './types'
import { runtimeRequest } from './runtime'

const base = `${ADMIN_API_PREFIX}/skills`

export interface SkillRow {
  id: number
  code: string
  name: string
  state: 'draft' | 'published' | 'revoked'
  status: number
  active_version_id: number | null
  active_version: number | null
  draft_version: number | null
}

export interface SkillVersion {
  id: number
  version: number
  state: 'draft' | 'published' | 'revoked' | 'abandoned'
  name: string
  description: string
  instruction: string
  input_schema: Record<string, unknown>
  output_schema: Record<string, unknown>
  review_policy: { review_required: boolean }
  knowledge_base_version_id: number
  snapshot_sha256: string
  tested: boolean
}

export interface SkillDetail {
  skill: SkillRow
  versions: SkillVersion[]
}

export interface SkillSource {
  publication_id: number
  code: string
  name: string
  knowledge_base_version_id: number
  version: number
  snapshot_sha256: string
  data_class: string | null
}

export interface SkillOperation {
  idempotent_replay: boolean
  skill_id: number
  version_id: number
  code: string
  version: number
  state: string
}

export interface AdminSkillReceipt {
  operation: 'create_draft' | 'publish' | 'revoke' | 'create' | 'review' | 'retire'
  request_id: string
  environment_id: number
  actor_ref: string
  idempotent_replay: true
  skill_id: number
  version_id: number
  code: string
  version: number
  state?: 'draft' | 'published' | 'revoked' | 'retired'
  case_id?: number
  case_no?: number
  evaluation_id?: number
  skill_run_id?: number
  verdict?: 'pass' | 'fail'
}

export interface SkillTestResult {
  idempotent_replay: boolean
  run_id: number
  invocation_id: number | null
  invocation_request_id: string | null
  state: string
  output: Record<string, unknown> | null
  citations: SkillCitation[]
  review_required: boolean
  error_code: string | null
}

export interface SkillCitation {
  knowledge_base_code: string
  knowledge_base_version: number
  file_id: number
  parse_id: number
  source_block_id: number
  locator_type: string
  locator: Record<string, unknown>
  excerpt: string
}

export interface SkillCaseRow {
  id: number
  skill_version_id: number
  case_no: number
  name: string
  input: Record<string, unknown>
  expected_criteria: string
  state: 'active' | 'retired'
  retire_reason: string | null
  latest_evaluation: {
    id: number
    skill_run_id: number
    verdict: 'pass' | 'fail'
    note: string
    create_time: string
  } | null
}

export default {
  runPublished(apiKey: string, code: string, version: number, input: Record<string, unknown>, requestId: string) {
    return runtimeRequest<SkillTestResult>({
      method: 'POST',
      path: `/skills/${encodeURIComponent(code)}/versions/${version}/runs`,
      apiKey,
      data: { input, request_id: requestId }
    })
  },
  readPublishedRun(apiKey: string, code: string, version: number, requestId: string) {
    return runtimeRequest<SkillTestResult>({
      method: 'GET',
      path: `/skills/${encodeURIComponent(code)}/versions/${version}/runs/by-request-key`,
      apiKey,
      params: { request_id: requestId }
    })
  },
  runtimeCost(apiKey: string, requestId: string) {
    return runtimeRequest<SandAiInvocationCost>({
      method: 'GET',
      path: `/invocations/${encodeURIComponent(requestId)}/cost`,
      apiKey
    })
  },
  cases(environmentId: number, skillVersionId: number) {
    return request.get<{ skill_version_id: number; cases: SkillCaseRow[] }>({
      url: `${base}/cases`,
      params: { environment_id: environmentId, skill_version_id: skillVersionId },
      showErrorMessage: false
    })
  },
  createCase(environmentId: number, skillId: number, skillVersionId: number, name: string, input: Record<string, unknown>, expectedCriteria: string, key: string) {
    return request.post<{ case_id: number; case_no: number; idempotent_replay: boolean }>({
      url: `${base}/cases/create`,
      data: { environment_id: environmentId, skill_id: skillId, skill_version_id: skillVersionId, name, input, expected_criteria: expectedCriteria },
      headers: { 'Idempotency-Key': key }, showErrorMessage: false
    })
  },
  reviewCase(environmentId: number, caseId: number, runId: number, verdict: 'pass' | 'fail', note: string, key: string) {
    return request.post<{ evaluation_id: number; case_id: number; verdict: string; idempotent_replay: boolean }>({
      url: `${base}/cases/review`,
      data: { environment_id: environmentId, case_id: caseId, skill_run_id: runId, verdict, note },
      headers: { 'Idempotency-Key': key }, showErrorMessage: false
    })
  },
  retireCase(environmentId: number, caseId: number, reason: string, key: string) {
    return request.post<{ case_id: number; state: string; idempotent_replay: boolean }>({
      url: `${base}/cases/retire`,
      data: { environment_id: environmentId, case_id: caseId, reason },
      headers: { 'Idempotency-Key': key }, showErrorMessage: false
    })
  },
  list(environmentId: number, page = 1) {
    return request.get<SandAiPage<SkillRow>>({
      url: `${base}/index`, params: { environment_id: environmentId, page, limit: 20 }, showErrorMessage: false
    })
  },
  read(environmentId: number, skillId: number) {
    return request.get<SkillDetail>({
      url: `${base}/read`, params: { environment_id: environmentId, skill_id: skillId }, showErrorMessage: false
    })
  },
  sources(environmentId: number, page = 1) {
    return request.get<SandAiPage<SkillSource>>({
      url: `${base}/sources`, params: { environment_id: environmentId, page, limit: 100 }, showErrorMessage: false
    })
  },
  createDraft(environmentId: number, body: Record<string, unknown>, key: string) {
    return request.post<SkillOperation>({
      url: `${base}/create-draft`, data: { environment_id: environmentId, ...body },
      headers: { 'Idempotency-Key': key }, showErrorMessage: false
    })
  },
  test(environmentId: number, code: string, version: number, input: Record<string, unknown>, key: string) {
    return request.post<SkillTestResult>({
      url: `${base}/test-run`, data: { environment_id: environmentId, code, version, input },
      headers: { 'Idempotency-Key': key }, showErrorMessage: false
    })
  },
  readTestRun(environmentId: number, code: string, version: number, requestId: string) {
    return request.get<SkillTestResult>({
      url: `${base}/tests/by-request-key`,
      params: { environment_id: environmentId, code, version, request_id: requestId },
      showErrorMessage: false
    })
  },
  readAdminReceipt(environmentId: number, operation: 'create' | 'case-create' | 'case-review' | 'case-retire' | 'publish' | 'revoke', requestId: string,
    target: { skillId?: number; version?: number; versionId?: number; caseId?: number }) {
    const paths = { create: 'drafts', 'case-create': 'cases/create', 'case-review': 'cases/review',
      'case-retire': 'cases/retire', publish: 'publish', revoke: 'revoke' }
    return request.get<AdminSkillReceipt>({
      url: `${base}/${paths[operation]}/by-request-key`,
      params: { environment_id: environmentId, request_id: requestId,
        ...(operation === 'publish' ? { skill_id: target.skillId, version: target.version } : {}),
        ...(operation === 'revoke' ? { skill_id: target.skillId } : {}),
        ...(operation === 'case-create' ? { skill_version_id: target.versionId } : {}),
        ...(['case-review', 'case-retire'].includes(operation) ? { case_id: target.caseId } : {}) },
      showErrorMessage: false
    })
  },
  publish(environmentId: number, skillId: number, key: string, version?: number) {
    return request.post<SkillOperation>({
      url: `${base}/publish`, data: { environment_id: environmentId, skill_id: skillId, ...(version !== undefined ? { version } : {}) },
      headers: { 'Idempotency-Key': key }, showErrorMessage: false
    })
  },
  revoke(environmentId: number, skillId: number, key: string) {
    return request.post<SkillOperation>({
      url: `${base}/revoke`, data: { environment_id: environmentId, skill_id: skillId },
      headers: { 'Idempotency-Key': key }, showErrorMessage: false
    })
  }
}
