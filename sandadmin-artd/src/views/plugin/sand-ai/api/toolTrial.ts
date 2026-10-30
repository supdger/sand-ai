import { runtimeRequest } from './runtime'
import request from '@/utils/http'
import type { SandAiPage } from './types'

export interface ToolAvailable {
  code: string
  name: string
  version: number
  adapter: 'standard_readonly_http' | 'mcp_streamable_http'
  input_schema: Record<string, unknown>
}

export interface ToolPublishedVersion {
  code: string
  version: number
  environment_id: number
  state: 'published'
  effect_class: 'read_only'
  input_schema: Record<string, unknown>
  output_schema: Record<string, unknown>
  snapshot_sha256: string
}
function object(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

export interface ToolRunSummary {
  run_id: number
  tool: string | null
  version: number | null
  state: string
  result: unknown
  citations: unknown[]
  review_required: boolean
  metering: unknown
  audit_ref: string | null
  error: unknown
  idempotent_replay: boolean
}

export default {
  async readVersion(apiKey: string, code: string, version: number, environmentId: number | null): Promise<ToolPublishedVersion> {
    const result = await runtimeRequest<unknown>({ method: 'GET', path: `/tools/${encodeURIComponent(code)}/versions/${version}`, apiKey,
      headers: environmentId === null ? undefined : { 'X-Sand-AI-Environment-ID': String(environmentId) } })
    if (!object(result) || result.code !== code || result.version !== version
      || typeof result.environment_id !== 'number' || !Number.isSafeInteger(result.environment_id) || result.environment_id < 1
      || environmentId !== null && result.environment_id !== environmentId
      || result.state !== 'published' || result.effect_class !== 'read_only'
      || !object(result.input_schema) || !object(result.output_schema)
      || typeof result.snapshot_sha256 !== 'string' || !/^[a-f0-9]{64}$/.test(result.snapshot_sha256)) {
      throw new Error('当前授权下的工具固定版本、环境或 Schema 未确认；尚未提交试用。')
    }
    return { code, version, environment_id: result.environment_id, state: 'published', effect_class: 'read_only',
      input_schema: result.input_schema, output_schema: result.output_schema, snapshot_sha256: result.snapshot_sha256 }
  },
  available(environmentId: number, page = 1, limit = 100) {
    return request.get<SandAiPage<ToolAvailable>>({
      url: '/app/sand-ai/admin/tools/available',
      params: { environment_id: environmentId, page, limit },
      showErrorMessage: false
    })
  },
  run(apiKey: string, code: string, version: number, businessScope: string,
    input: Record<string, unknown>, idempotencyKey: string, environmentId: number | null = null) {
    return runtimeRequest<ToolRunSummary>({
      method: 'POST',
      path: `/tools/${encodeURIComponent(code)}/versions/${version}/runs`,
      apiKey,
      data: { business_scope: businessScope, input },
      headers: { 'Idempotency-Key': idempotencyKey, ...(environmentId === null ? {} : { 'X-Sand-AI-Environment-ID': String(environmentId) }) }
    })
  },
  readByKey(apiKey: string, key: string, environmentId: number | null = null) {
    return runtimeRequest<ToolRunSummary>({
      method: 'GET', path: '/tool-runs/by-key', apiKey, params: { request_key: key },
      headers: environmentId === null ? undefined : { 'X-Sand-AI-Environment-ID': String(environmentId) }
    })
  },
  read(apiKey: string, runId: number, environmentId: number | null = null) {
    return runtimeRequest<ToolRunSummary>({
      method: 'GET', path: `/tool-runs/${runId}`, apiKey,
      headers: environmentId === null ? undefined : { 'X-Sand-AI-Environment-ID': String(environmentId) }
    })
  }
}
