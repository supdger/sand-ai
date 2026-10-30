import request from '@/utils/http'
import type { SandAiPage } from './types'

const base = '/app/sand-ai/admin/tools'

export interface ToolConnectionRef {
  connection_id: number
  connection_revision_id: number
  code: string
  name: string
  data_class: string
}

export interface ToolConnectionCreated extends ToolConnectionRef {
  has_credential: boolean
}

export interface McpDiscoveredTool {
  snapshot_item_id: number
  code: string
  name: string
  remote_name: string
}

export interface McpDiscovery {
  snapshot_id: number
  state: string
  connection_id: number
  connection_revision_id: number
  tools: McpDiscoveredTool[]
  metering: unknown
}

export interface ToolVersionCreated {
  tool_id: number
  tool_version_id: number
  code: string
  version: number
  adapter: string
  state: string
}

export interface ToolBound {
  binding_id: number
  environment_id: number
  tool_id: number
  tool_version_id: number
  connection_id: number
  connection_revision_id: number
  state: string
}

export interface ToolWorkspace {
  tool: { tool_id: number; code: string; name: string; adapter: string; state: string }
  draft: ToolVersionCreated | null
  published: ToolVersionCreated | null
  binding: ToolBound | null
}

export type ConnectionWrite = {
  environment_id: number
  code: string
  name: string
  origin: string
  path: string
  data_class: string
  authorization: { mode: 'none' } | { mode: 'bearer'; credential: string }
}

export type ToolDraftWrite = { environment_id: number } & (
  | { adapter: 'mcp_streamable_http'; mcp_snapshot_item_id: number; review_policy: { review_required: boolean } }
  | { adapter: 'standard_readonly_http'; code: string; name: string; description: string;
      input_schema: Record<string, unknown>; output_schema: Record<string, unknown>;
      review_policy: { review_required: boolean } })

export type ToolOperation = 'connection' | 'draft' | 'publish' | 'bind' | 'revoke' | 'discovery'
export type ToolOperationRejected = {
  state: 'rejected'; submission_state: 'not_submitted'; error_code: string
}
export type ToolOperationSuccessReceipt = {
  environment_id: number; request_key: string; target_sha256: string
} & (
  | { operation: 'create_connection'; result: ToolConnectionCreated }
  | { operation: 'create_draft' | 'publish' | 'revoke'; result: ToolVersionCreated }
  | { operation: 'bind_environment'; result: ToolBound }
  | { operation: 'mcp_discovery'; result: McpDiscovery }
)
export type ToolOperationRejectedReceipt = {
  environment_id: number; request_key: string; target_sha256: string
  operation: ToolOperationSuccessReceipt['operation']; result: ToolOperationRejected
}
export type ToolOperationReceipt = ToolOperationSuccessReceipt | ToolOperationRejectedReceipt

function post<T>(path: string, data: object, key: string) {
  return request.post<T>({
    url: `${base}${path}`, data, headers: { 'Idempotency-Key': key }, showErrorMessage: false
  })
}

export default {
  operationReceipt(operation: ToolOperation, environmentId: number, requestKey: string, targetSha256: string) {
    return request.get<ToolOperationReceipt>({
      url: `${base}/operations/${operation}`,
      params: { environment_id: environmentId, request_key: requestKey, target_sha256: targetSha256 },
      showErrorMessage: false
    })
  },
  connections(environmentId: number, page = 1, limit = 100) {
    return request.get<SandAiPage<ToolConnectionRef>>({
      url: `${base}/connections/index`,
      params: { environment_id: environmentId, page, limit }, showErrorMessage: false
    })
  },
  readConnection(environmentId: number, connectionId: number) {
    return request.get<ToolConnectionRef>({
      url: `${base}/connections/read`,
      params: { environment_id: environmentId, connection_id: connectionId },
      showErrorMessage: false
    })
  },
  connectionByCode(environmentId: number, code: string) {
    return request.get<ToolConnectionRef>({
      url: `${base}/connections/by-code`,
      params: { environment_id: environmentId, code },
      showErrorMessage: false
    })
  },
  latestMcp(environmentId: number, connectionId: number, connectionRevisionId: number) {
    return request.get<McpDiscovery>({
      url: `${base}/mcp/latest`,
      params: { environment_id: environmentId, connection_id: connectionId,
        connection_revision_id: connectionRevisionId }, showErrorMessage: false
    })
  },
  workspace(environmentId: number, connectionId: number, code: string) {
    return request.get<ToolWorkspace>({
      url: `${base}/workspace`,
      params: { environment_id: environmentId, connection_id: connectionId, code },
      showErrorMessage: false
    })
  },
  createConnection(data: ConnectionWrite, key: string) {
    return post<ToolConnectionCreated>('/connections/create', data, key)
  },
  discover(environmentId: number, connectionId: number, connectionRevisionId: number, key: string) {
    return post<McpDiscovery>('/mcp/discover', {
      environment_id: environmentId, connection_id: connectionId,
      connection_revision_id: connectionRevisionId
    }, key)
  },
  createDraft(data: ToolDraftWrite, key: string) {
    return post<ToolVersionCreated>('/create-draft', data, key)
  },
  publish(environmentId: number, toolId: number, key: string) {
    return post<ToolVersionCreated>('/publish', { environment_id: environmentId, tool_id: toolId }, key)
  },
  bind(environmentId: number, toolId: number, toolVersionId: number,
    connectionId: number, connectionRevisionId: number, key: string) {
    return post<ToolBound>('/bind-environment', {
      environment_id: environmentId, tool_id: toolId, tool_version_id: toolVersionId,
      connection_id: connectionId, connection_revision_id: connectionRevisionId
    }, key)
  },
  revoke(environmentId: number, toolId: number, key: string) {
    return post<ToolVersionCreated>('/revoke', { environment_id: environmentId, tool_id: toolId }, key)
  }
}
