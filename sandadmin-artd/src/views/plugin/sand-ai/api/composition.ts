import request from '@/utils/http'
import { runtimeRequest } from './runtime'
import type { SandAiUsageSummaryGroup, SandAiUsageSummaryRow } from './types'

export type JsonValue = null | boolean | number | string | JsonValue[] | { [key: string]: JsonValue }
export type ServiceKind = 'agent' | 'skill' | 'material' | 'knowledge' | 'tool'
export interface CompositionService { kind: ServiceKind; code: string; version: number | string }
export interface CompositionCatalogItem extends CompositionService {
  name: string; reference_sha256: string; output_schema: Record<string, unknown>; input_fields: { name: string; label: string; required: boolean; schema: Record<string, unknown> }[]
}
export type CompositionBinding = { source: 'input'; path: string } | { source: 'node'; node_id: string; path: string } | { source: 'literal'; value: JsonValue }
export interface CompositionNode { id: string; name: string; service: CompositionService; depends_on: string[]; inputs: Record<string, CompositionBinding> }
export interface CompositionGraph { nodes: CompositionNode[]; output_nodes: string[]; failure_policy: 'stop' | 'continue_independent' }
export interface CompositionDefinition { id: number; environment_id: number; code: string; name: string; state: string; active_version_id: number | null }
export interface CompositionVersion { id: number; version_no: number; state: string; revision: number; snapshot_sha256: string; graph: CompositionGraph }
export interface CompositionDetail { definition: CompositionDefinition; versions: CompositionVersion[] }
export interface CompositionRun {
  id: number; code: string; version: number; state: 'pending' | 'running' | 'waiting' | 'partial_failed' | 'succeeded' | 'cancelled'; snapshot_sha256: string
  nodes: { id: string; name: string; state: 'pending' | 'submitting' | 'waiting' | 'succeeded' | 'failed' | 'unknown' | 'blocked'; request_key: string; error_code: string | null; child: Record<string, JsonValue> | null; result: JsonValue }[]
  input_state: 'retained' | 'purged'; retain_until: string; content_purged_time: string | null
  output: JsonValue; usage: { basis: 'child_runs'; parent_charge: false }
}
export type CompositionNodeCharge =
  | { state: 'not_billable'; basis: 'server_keyword_execution'; metered_amount: '0'; currency: null }
  | { state: 'usage_events' | 'pending' }
export interface CompositionRunCost {
  run_id: number
  basis: 'immutable_child_usage_events'
  parent_charge: false
  coverage: 'complete' | 'pending'
  overall: SandAiUsageSummaryGroup
  nodes: { node_code: string; state: string; coverage: 'complete' | 'pending'; rows: SandAiUsageSummaryRow[]; charge?: CompositionNodeCharge }[]
}
export interface CompositionReceipt { definition_id: number; version_id: number | null; code: string; version_no: number | null; revision: number; state: string; snapshot_sha256: string | null }
export type CompositionOperation = 'draft' | 'publish' | 'disable'
const base = '/app/sand-ai/admin/ai-workflows'
const headers = (key: string) => ({ 'Idempotency-Key': key })
export default {
  list(environmentId: number) { return request.get<{ items: CompositionDefinition[] }>({ url: `${base}/index`, params: { environment_id: environmentId }, showErrorMessage: false }) },
  catalog(environmentId: number) { return request.get<{ items: CompositionCatalogItem[] }>({ url: `${base}/catalog`, params: { environment_id: environmentId }, showErrorMessage: false }) },
  read(environmentId: number, code: string) { return request.get<CompositionDetail>({ url: `${base}/read`, params: { environment_id: environmentId, code }, showErrorMessage: false }) },
  recoverOperation(environmentId: number, operation: CompositionOperation, key: string) { return request.get<CompositionReceipt>({ url: `${base}/by-request-key`, params: { environment_id: environmentId, operation, request_key: key }, showErrorMessage: false }) },
  save(data: { environment_id: number; code: string; name: string; expected_revision: number; graph: CompositionGraph; version_id?: number }, key: string) { return request.post<CompositionReceipt>({ url: `${base}/save-draft`, data, headers: headers(key), showErrorMessage: false }) },
  publish(environmentId: number, code: string, revision: number, key: string, versionId?: number) { return request.post<CompositionReceipt>({ url: `${base}/publish`, data: { environment_id: environmentId, code, expected_revision: revision, ...(versionId === undefined ? {} : { version_id: versionId }) }, headers: headers(key), showErrorMessage: false }) },
  disable(environmentId: number, code: string, key: string) { return request.post<CompositionReceipt>({ url: `${base}/disable`, data: { environment_id: environmentId, code }, headers: headers(key), showErrorMessage: false }) },
  submit(apiKey: string, code: string, version: number, input: Record<string, JsonValue>, key: string) { return runtimeRequest<CompositionRun>({ method: 'POST', path: `/ai-workflows/${encodeURIComponent(code)}/versions/${version}/runs`, apiKey, data: { input }, headers: headers(key) }) },
  recover(apiKey: string, code: string, version: number, key: string) { return runtimeRequest<CompositionRun>({ method: 'GET', path: '/ai-workflow-runs/by-request-key', apiKey, params: { code, version, request_key: key } }) },
  run(apiKey: string, id: number) { return runtimeRequest<CompositionRun>({ method: 'GET', path: `/ai-workflow-runs/${id}`, apiKey }) },
  purgeInput(apiKey: string, id: number, key: string) { return runtimeRequest<CompositionRun>({ method: 'DELETE', path: `/ai-workflow-runs/${id}/input`, apiKey, data: {}, headers: headers(key) }) },
  cost(apiKey: string, id: number) { return runtimeRequest<CompositionRunCost>({ method: 'GET', path: `/ai-workflow-runs/${id}/cost`, apiKey }) },
  readChild(apiKey: string, child: Record<string, JsonValue>) {
    const positive = (value: JsonValue | undefined) => typeof value === 'number' && Number.isSafeInteger(value) && value > 0
    let path: string
    let params: Record<string, string | number> | undefined
    if (child.kind === 'agent' && positive(child.task_id)) path = `/tasks/${child.task_id}`
    else if (child.kind === 'material' && positive(child.run_id)) path = `/structured-workflows/runs/${child.run_id}`
    else if (child.kind === 'tool' && positive(child.run_id)) path = `/tool-runs/${child.run_id}`
    else if (child.kind === 'skill' && typeof child.code === 'string' && typeof child.version === 'number' && typeof child.request_key === 'string') {
      path = `/skills/${encodeURIComponent(child.code)}/versions/${child.version}/runs/by-request-key`
      params = { request_id: child.request_key }
    } else return Promise.reject(new Error('原子运行定位尚未确认，请先只读找回工作流。'))
    return runtimeRequest<unknown>({ method: 'GET', path, apiKey, params })
  },
  operation(apiKey: string, id: number, key: string) { return runtimeRequest<{ operation: string; request_key: string; state: 'started' | 'finished'; run_id: number; admission: 'admitted' | 'rejected'; error_code: string | null }>({ method: 'GET', path: `/ai-workflow-runs/${id}/operations/by-request-key`, apiKey, params: { request_key: key } }) },
  action(apiKey: string, id: number, action: 'advance' | 'cancel' | 'retry', key: string, nodeId?: string) { return runtimeRequest<CompositionRun>({ method: 'POST', path: `/ai-workflow-runs/${id}/${action === 'retry' ? `nodes/${encodeURIComponent(nodeId ?? '')}/retry` : action}`, apiKey, data: {}, headers: headers(key) }) }
}
