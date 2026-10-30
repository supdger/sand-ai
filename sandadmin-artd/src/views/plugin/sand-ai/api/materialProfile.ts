import request from '@/utils/http'
import { ADMIN_API_PREFIX } from './config'
import { runtimeRequest } from './runtime'
import type { SandAiTask, SandAiWorkflowRun } from './types'

const profiles = `${ADMIN_API_PREFIX}/setup/material-analysis/profiles`

export interface MaterialProfileConfig {
  code: string
  version: string
  output_language: 'zh-CN' | 'en'
  review_output_strategy?: 'neuron_json_extract_v1' | 'neuron_zai_json_object_v1' | 'neuron_openai_json_schema_v1'
  document_classifications: string[]
  entity_types: string[]
  extension_fields: string[]
  max_prompt_characters: number
  max_output_characters: number
}

export interface MaterialProfile {
  id: number
  environment_id: number
  code: string
  version: string
  name: string
  revision: number
  state: 'draft' | 'tested' | 'published' | 'revoked'
  status: number
  sample_run_id?: number | null
  config?: MaterialProfileConfig
}

export interface MaterialSampleCandidate {
  sample_run_id: number
  completed_at: string
}

export interface MaterialSampleRequest {
  profile_revision: number
  business_scope: { ref: string; snapshot_ref: string; snapshot_version: string }
  workflow: { code: 'material_analysis'; version: '1.0.0' }
  files: Array<{ file_id: number; sha256: string; version_ref: string }>
  output_schema: { code: 'sand_ai.material_analysis'; version: '1.0.0' }
  analysis_profile: { code: string; version: string }
  review_required: true
}

export type MaterialPublishedRequest = Omit<MaterialSampleRequest, 'profile_revision'>

export interface MaterialSampleSubmitResult {
  idempotent_replay: boolean
  run: { run_id: number; state: string; task: SandAiTask | null }
}

export default {
  publishedByRequestKey(apiKey: string, requestKey: string, environmentId?: number) {
    return runtimeRequest<SandAiWorkflowRun>({
      method: 'GET',
      path: '/structured-workflows/runs/by-request-key',
      apiKey,
      params: { request_key: requestKey },
      headers:
        environmentId === undefined
          ? undefined
          : { 'X-Sand-AI-Environment-ID': String(environmentId) }
    })
  },
  submitPublished(
    apiKey: string,
    body: MaterialPublishedRequest,
    idempotencyKey: string,
    environmentId?: number
  ) {
    return runtimeRequest<MaterialSampleSubmitResult>({
      method: 'POST',
      path: '/structured-workflows/runs',
      apiKey,
      data: { ...body },
      headers: {
        'Idempotency-Key': idempotencyKey,
        ...(environmentId === undefined
          ? {}
          : { 'X-Sand-AI-Environment-ID': String(environmentId) })
      }
    })
  },
  sampleByRequestKey(apiKey: string, requestKey: string, environmentId?: number) {
    return runtimeRequest<SandAiWorkflowRun>({
      method: 'GET',
      path: '/material-analysis/samples/by-request-key',
      apiKey,
      params: { request_key: requestKey },
      headers: environmentId === undefined ? undefined : { 'X-Sand-AI-Environment-ID': String(environmentId) }
    })
  },
  submitSample(apiKey: string, body: MaterialSampleRequest, idempotencyKey: string, environmentId?: number) {
    return runtimeRequest<MaterialSampleSubmitResult>({
      method: 'POST',
      path: '/material-analysis/samples',
      apiKey,
      data: { ...body },
      headers: {
        'Idempotency-Key': idempotencyKey,
        ...(environmentId === undefined ? {} : { 'X-Sand-AI-Environment-ID': String(environmentId) })
      }
    })
  },
  list(environmentId: number) {
    return request.get<{ profiles: MaterialProfile[] }>({
      url: `${profiles}/index`,
      params: { environment_id: environmentId },
      showErrorMessage: false
    })
  },
  read(environmentId: number, code: string, version: string) {
    return request.get<{ profile: MaterialProfile }>({
      url: `${profiles}/read`,
      params: { environment_id: environmentId, code, version },
      showErrorMessage: false
    })
  },
  samples(environmentId: number, code: string, version: string, revision: number) {
    return request.get<{ samples: MaterialSampleCandidate[] }>({
      url: `${profiles}/samples`,
      params: { environment_id: environmentId, code, version, revision },
      showErrorMessage: false
    })
  },
  save(environmentId: number, expectedRevision: number | null, name: string, profile: MaterialProfileConfig) {
    return request.post<{ profile: MaterialProfile }>({
      url: `${profiles}/draft/save`,
      data: { environment_id: environmentId, expected_revision: expectedRevision, profile: { ...profile, name } },
      showErrorMessage: false
    })
  },
  revoke(environmentId: number, code: string, version: string) {
    return request.post<{ profile: MaterialProfile }>({
      url: `${profiles}/revoke`,
      data: { environment_id: environmentId, code, version },
      showErrorMessage: false
    })
  },
  publish(environmentId: number, code: string, version: string, revision: number, sampleRunId: number) {
    return request.post<{ profile: MaterialProfile }>({
      url: `${profiles}/publish`,
      data: { environment_id: environmentId, code, version, revision, sample_run_id: sampleRunId },
      showErrorMessage: false
    })
  }
}
