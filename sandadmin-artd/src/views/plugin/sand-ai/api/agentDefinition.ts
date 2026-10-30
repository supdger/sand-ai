import request from '@/utils/http'
import type { SandAiPage } from './types'

const base = '/app/sand-ai/admin/agents/definitions'

export type AgentContextTurnLimit = 0 | 1 | 2 | 3 | 4

export interface AgentReadonlyToolSelection {
  readonly code: string
  readonly version: number
  readonly business_scope: string
}

export interface AgentDefinitionRow {
  readonly agent_definition_id: number
  readonly code: string
  readonly name: string
  readonly state: 'draft' | 'published'
  readonly status: 1 | 2
  readonly active_version_id: number | null
  readonly active_version_no: number | null
  readonly draft_version_id: number | null
  readonly draft_version_no: number | null
}

export interface AgentVersionDetail {
  readonly id: number
  readonly version: number
  readonly state: 'draft' | 'published'
  readonly model: string
  readonly instructions: string
  readonly tools: string[]
  readonly review_required: boolean
  readonly max_output_tokens: number | null
  readonly knowledge_base_code: string | null
  readonly readonly_tools?: readonly AgentReadonlyToolSelection[]
  readonly context_turn_limit: AgentContextTurnLimit
}

export interface AgentDefinitionDetail {
  readonly agent_definition_id: number
  readonly code: string
  readonly name: string
  readonly state: 'draft' | 'published'
  readonly status: 1 | 2
  readonly active_version_id: number | null
  readonly latest_version: AgentVersionDetail | null
}

export interface AgentAvailableModel {
  readonly id: number
  readonly code: string
  readonly name: string
  readonly type: string
  readonly capabilities: string[]
}

export interface AgentDraft {
  readonly environment_id: number
  readonly code: string
  readonly name: string
  readonly model: string
  readonly instructions: string
  readonly tools: string[]
  readonly review_required: boolean
  readonly max_output_tokens: number | null
  readonly knowledge_base_code: string | null
  readonly readonly_tools?: readonly AgentReadonlyToolSelection[]
  readonly context_turn_limit: AgentContextTurnLimit
}

export interface AgentVersionHistory {
  readonly version_no: number
  readonly state: 'draft' | 'published'
  readonly snapshot_sha256: string
  readonly created_at: string
  readonly is_active: boolean
  readonly config: Omit<AgentVersionDetail, 'id' | 'version' | 'state'>
}

export type AgentDefinitionOperation = 'draft' | 'publish' | 'disable' | 'rollback'

export interface AgentDefinitionVersionReceipt {
  readonly agent_definition_id: number
  readonly agent_version_id: number
  readonly code: string
  readonly version: number
  readonly state: 'draft' | 'published'
  readonly snapshot_sha256: string
}

export interface AgentDefinitionDisableReceipt {
  readonly agent_definition_id: number
  readonly agent_version_id: number
  readonly code: string
  readonly state: 'disabled'
}

export type AgentDefinitionOperationReceipt = AgentDefinitionVersionReceipt | AgentDefinitionDisableReceipt
export interface AgentDefinitionRejectedPublishReceipt {
  readonly operation_state: 'rejected'
  readonly reason: 'configuration_changed'
  readonly agent_definition_id: number
  readonly code: string
  readonly expected_snapshot_sha256: string
  readonly idempotent_replay: true
}

export type AgentDefinitionOperationRecovery = (AgentDefinitionOperationReceipt & { readonly idempotent_replay: true })
  | AgentDefinitionRejectedPublishReceipt

export default {
  operationByRequestKey(environmentId: number, operation: AgentDefinitionOperation, code: string, key: string) {
    return request.get<AgentDefinitionOperationRecovery>({
      url: `${base}/operations/by-request-key`,
      params: { environment_id: environmentId, operation, code, request_key: key },
      headers: { 'Cache-Control': 'no-store' }, showErrorMessage: false
    })
  },
  versions(environmentId: number, code: string, page = 1) {
    return request.get<SandAiPage<AgentVersionHistory>>({ url: `${base}/versions`, params: { environment_id: environmentId, code, page, limit: 20 }, showErrorMessage: false })
  },
  rollback(environmentId: number, definitionId: number, versionNo: number, key: string) {
    return request.post<AgentDefinitionVersionReceipt>({
      url: `${base}/rollback`, data: { environment_id: environmentId, agent_definition_id: definitionId, version_no: versionNo },
      headers: { 'Idempotency-Key': key }, showErrorMessage: false
    })
  },
  list(environmentId: number, page = 1, limit = 20) {
    return request.get<SandAiPage<AgentDefinitionRow>>({
      url: `${base}/index`, params: { environment_id: environmentId, page, limit }, showErrorMessage: false
    })
  },
  models(environmentId: number) {
    return request.get<{ data: AgentAvailableModel[] }>({
      url: `${base}/models`, params: { environment_id: environmentId }, showErrorMessage: false
    })
  },
  read(environmentId: number, code: string) {
    return request.get<AgentDefinitionDetail>({
      url: `${base}/read`, params: { environment_id: environmentId, code }, showErrorMessage: false
    })
  },
  draft(data: AgentDraft, key: string) {
    return request.post<AgentDefinitionVersionReceipt>({
      url: `${base}/draft`, data, headers: { 'Idempotency-Key': key }, showErrorMessage: false
    })
  },
  publish(environmentId: number, definitionId: number, key: string, expectedSnapshotSha256?: string) {
    return request.post<AgentDefinitionVersionReceipt>({
      url: `${base}/publish`, data: { environment_id: environmentId, agent_definition_id: definitionId,
        ...(expectedSnapshotSha256 === undefined ? {} : { expected_snapshot_sha256: expectedSnapshotSha256 }) },
      headers: { 'Idempotency-Key': key }, showErrorMessage: false
    })
  },
  disable(environmentId: number, definitionId: number, key: string) {
    return request.post<AgentDefinitionDisableReceipt>({
      url: `${base}/disable`, data: { environment_id: environmentId, agent_definition_id: definitionId },
      headers: { 'Idempotency-Key': key }, showErrorMessage: false
    })
  }
}
