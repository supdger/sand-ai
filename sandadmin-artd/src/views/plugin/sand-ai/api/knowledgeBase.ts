import request from '@/utils/http'
import type { SandAiPage } from './types'

const base = '/app/sand-ai/admin/knowledge-bases'

export interface KnowledgeBaseRow {
  id: number
  code: string
  name: string
  description: string | null
  state: 'draft' | 'published' | 'disabled'
  active_version_id: number | null
  active_version_no: number | null
  draft_version_id: number | null
  draft_version_no: number | null
  draft_source_count: number | null
  draft_snapshot_sha256?: string | null
  source_state: 'ready' | 'source_changed' | 'unavailable' | 'purged'
  sources_purged: boolean
}

export interface KnowledgeSourcesPurge {
  knowledge_base_id: number
  state: 'purged'
  remaining_sources: 0
  remaining_conversations: 0
  idempotent_replay: boolean
}

export interface KnowledgeDisableResult {
  knowledge_base: { id: number; state: 'disabled' }
  idempotent_replay: boolean
}

export interface KnowledgeVectorsPurge {
  knowledge_base_id: number
  state: 'purged'
  idempotent_replay: boolean
}

function isKnowledgeDisableResult(value: unknown, knowledgeBaseId: number): value is KnowledgeDisableResult {
  if (typeof value !== 'object' || value === null || Array.isArray(value)
    || !('knowledge_base' in value) || !('idempotent_replay' in value)) return false
  const target = value.knowledge_base
  return typeof target === 'object' && target !== null && !Array.isArray(target)
    && 'id' in target && target.id === knowledgeBaseId && 'state' in target && target.state === 'disabled'
    && typeof value.idempotent_replay === 'boolean'
}

function isKnowledgeVectorsPurge(value: unknown, knowledgeBaseId: number): value is KnowledgeVectorsPurge {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
    && 'knowledge_base_id' in value && value.knowledge_base_id === knowledgeBaseId
    && 'state' in value && value.state === 'purged'
    && 'idempotent_replay' in value && typeof value.idempotent_replay === 'boolean'
}

function isKnowledgeSourcesPurge(value: unknown, knowledgeBaseId: number): value is KnowledgeSourcesPurge {
  if (typeof value !== 'object' || value === null || Array.isArray(value)) return false
  const data = value as Record<string, unknown>
  return data.knowledge_base_id === knowledgeBaseId && data.state === 'purged'
    && data.remaining_sources === 0 && data.remaining_conversations === 0
    && typeof data.idempotent_replay === 'boolean'
}

export interface KnowledgeLifecycleReceipt {
  environment_id: number
  knowledge_base_id: number
  operation: 'disable' | 'purge_vectors' | 'purge_sources'
  request_id: string
  actor_ref: string
  state: 'disabled' | 'purged'
  idempotent_replay: true
}

export interface KnowledgePublishResult {
  idempotent_replay: boolean
  knowledge_base: { id: number; code: string; name: string; state: string }
  version: { id: number; version: number; state: string; snapshot_sha256: string; source_count: number }
}

export interface KnowledgeDraftReceipt {
  operation: 'create_draft'
  environment_id: number
  request_key: string
  request_fingerprint: string
  actor_ref: string
  knowledge_base_id: number
  version_id: number
  idempotent_replay: true
  current: KnowledgePublishResult
}

// Match KnowledgeBasePolicy::normalizeDraft/fingerprint, excluding the environment.
async function draftFingerprint(input: KnowledgeDraftInput): Promise<string> {
  const trim = (value: string) => value.replace(/^[ \t\n\r\u0000\u000b]+|[ \t\n\r\u0000\u000b]+$/g, '')
  const value: Record<string, unknown> = {
    code: trim(input.code).toLowerCase(), description: trim(input.description) || null,
    files: [...input.files].sort((a, b) => a.id - b.id).map(file => ({ id: file.id, sha256: file.sha256 })),
    name: trim(input.name)
  }
  if (input.replace_draft) value.replace_draft = true
  const bytes = JSON.stringify(value).replace(/\u2028/g, '\\u2028').replace(/\u2029/g, '\\u2029')
  const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(bytes))
  return Array.from(new Uint8Array(digest), byte => byte.toString(16).padStart(2, '0')).join('')
}

export interface KnowledgePublishRejected {
  state: 'rejected'
  submitted: false
  reason: 'draft_snapshot_changed'
  environment_id: number
  knowledge_base_id: number
  request_key: string
  expected_snapshot_sha256: string
  idempotent_replay: boolean
}
export type KnowledgePublishRecoveryResult = KnowledgePublishResult | KnowledgePublishRejected

function publishRecoveryResult(value: unknown, environmentId: number, baseId: number, key: string, snapshot: string): KnowledgePublishRecoveryResult {
  if (typeof value === 'object' && value !== null && !Array.isArray(value)) {
    const record = value as Record<string, unknown>
    if (record.state === 'rejected') {
      if (record.submitted !== false || record.reason !== 'draft_snapshot_changed'
        || record.environment_id !== environmentId || record.knowledge_base_id !== baseId
        || record.request_key !== key || record.expected_snapshot_sha256 !== snapshot
        || typeof record.idempotent_replay !== 'boolean'
        || Object.keys(record).some(field => !['state', 'submitted', 'reason', 'environment_id', 'knowledge_base_id', 'request_key', 'expected_snapshot_sha256', 'idempotent_replay'].includes(field))) {
        throw new Error('发布拒绝回执与原请求不一致，请保留原号核对')
      }
      return record as unknown as KnowledgePublishRejected
    }
  }
  return publishResult(value, baseId, snapshot)
}

function publishResult(value: unknown, baseId: number, snapshot: string): KnowledgePublishResult {
  if (typeof value !== 'object' || value === null || Array.isArray(value)) throw new Error('知识发布响应无效，请保留原请求号只读找回')
  const result = value as Partial<KnowledgePublishResult>
  const base = result.knowledge_base
  const version = result.version
  if (typeof result.idempotent_replay !== 'boolean' || !base || base.id !== baseId
    || typeof base.code !== 'string' || typeof base.name !== 'string' || typeof base.state !== 'string'
    || !version || !Number.isSafeInteger(version.id) || version.id < 1
    || !Number.isSafeInteger(version.version) || version.version < 1 || typeof version.state !== 'string'
    || version.snapshot_sha256 !== snapshot || !Number.isSafeInteger(version.source_count) || version.source_count < 1) {
    throw new Error('知识发布响应与原请求快照不一致，请保留原号核对')
  }
  return result as KnowledgePublishResult
}

export interface KnowledgeSourceFile {
  id: number
  name: string
  sha256: string
  source_block_count: number
  data_class: string | null
}

export interface KnowledgeDraftInput {
  environment_id: number
  code: string
  name: string
  description: string
  files: Array<{ id: number; sha256: string }>
  replace_draft: boolean
}

function draftResult(value: unknown, code: string, expectedBaseId: number | null): KnowledgePublishResult {
  if (typeof value !== 'object' || value === null || Array.isArray(value)) {
    throw new Error('草稿响应无效，请保留原请求号继续原请求')
  }
  const result = value as Partial<KnowledgePublishResult>
  const base = result.knowledge_base
  const version = result.version
  if (typeof result.idempotent_replay !== 'boolean' || !base
    || !Number.isSafeInteger(base.id) || base.id < 1
    || (expectedBaseId !== null && base.id !== expectedBaseId)
    || base.code !== code || typeof base.name !== 'string'
    || !['draft', 'published', 'disabled'].includes(base.state)
    || !version || !Number.isSafeInteger(version.id) || version.id < 1
    || !Number.isSafeInteger(version.version) || version.version < 1
    || !['draft', 'published'].includes(version.state)
    || typeof version.snapshot_sha256 !== 'string' || !/^[a-f0-9]{64}$/.test(version.snapshot_sha256)
    || !Number.isSafeInteger(version.source_count) || version.source_count < 1) {
    throw new Error('草稿响应与原请求不一致，请保留原请求号继续原请求')
  }
  return result as KnowledgePublishResult
}

export interface KnowledgeEmbeddingIndexStart {
  id: number
  state: string
  source_count: number
  dimensions: number | null
  idempotent_replay: boolean
}

export interface KnowledgeEmbeddingIndexStatus {
  id: number
  state: string
  source_count: number
  indexed_count: number
  dimensions: number | null
}

function indexStartResult(value: unknown): KnowledgeEmbeddingIndexStart {
  if (typeof value !== 'object' || value === null || Array.isArray(value)) throw new Error('索引准备回执无效，请保留原请求号并只读找回')
  const result = value as Record<string, unknown>
  if (typeof result.id !== 'number' || !Number.isSafeInteger(result.id) || result.id < 1
    || !['building', 'ready', 'failed', 'outcome_unknown'].includes(String(result.state))
    || typeof result.source_count !== 'number' || !Number.isSafeInteger(result.source_count) || result.source_count < 1
    || typeof result.idempotent_replay !== 'boolean'
    || (result.dimensions !== null && (typeof result.dimensions !== 'number' || !Number.isSafeInteger(result.dimensions) || result.dimensions < 1 || result.dimensions > 4096))
    || (result.state === 'ready' && result.dimensions === null))
    throw new Error('索引准备回执不完整，请保留原请求号并只读找回')
  return result as unknown as KnowledgeEmbeddingIndexStart
}
function indexStatusResult(value: unknown, indexId: number): KnowledgeEmbeddingIndexStatus {
  if (typeof value !== 'object' || value === null || Array.isArray(value)) throw new Error('索引状态回执无效，请保留原索引与请求号')
  const result = value as Record<string, unknown>
  if (result.id !== indexId || !['building', 'ready', 'failed', 'outcome_unknown'].includes(String(result.state))
    || typeof result.source_count !== 'number' || !Number.isSafeInteger(result.source_count) || result.source_count < 1
    || typeof result.indexed_count !== 'number' || !Number.isSafeInteger(result.indexed_count)
    || result.indexed_count < 0 || result.indexed_count > result.source_count
    || (result.dimensions !== null && (typeof result.dimensions !== 'number' || !Number.isSafeInteger(result.dimensions) || result.dimensions < 1 || result.dimensions > 4096))
    || (result.state === 'ready' && (result.dimensions === null || result.indexed_count !== result.source_count)))
    throw new Error('状态回执与原索引不一致，请保留原索引与请求号')
  return result as unknown as KnowledgeEmbeddingIndexStatus
}

export interface KnowledgeEmbeddingRecovery extends KnowledgeEmbeddingIndexStatus {
  owner_environment_id: number
  knowledge_base_version_id: number
  request_id: string
  idempotent_replay: true
}
function indexRecoveryResult(value: unknown, environmentId: number, versionId: number, key: string, indexId?: number | null): KnowledgeEmbeddingRecovery {
  if (typeof value !== 'object' || value === null || Array.isArray(value)) throw new Error('原索引回执无效，请保留原请求号')
  const result = value as Record<string, unknown>
  if (result.owner_environment_id !== environmentId || result.knowledge_base_version_id !== versionId
    || result.request_id !== key || result.idempotent_replay !== true
    || typeof result.id !== 'number' || !Number.isSafeInteger(result.id) || result.id < 1
    || (indexId != null && result.id !== indexId)
    || !['building', 'ready', 'failed', 'outcome_unknown'].includes(String(result.state))
    || typeof result.source_count !== 'number' || !Number.isSafeInteger(result.source_count) || result.source_count < 1
    || typeof result.indexed_count !== 'number' || !Number.isSafeInteger(result.indexed_count)
    || result.indexed_count < 0 || result.indexed_count > result.source_count
    || (result.dimensions !== null && (typeof result.dimensions !== 'number' || !Number.isSafeInteger(result.dimensions) || result.dimensions < 1 || result.dimensions > 4096))
    || (result.state === 'ready' && (result.dimensions === null || result.indexed_count !== result.source_count)))
    throw new Error('索引回执与原目标或请求号不一致，请保留原记录')
  return result as unknown as KnowledgeEmbeddingRecovery
}

export type KnowledgeEmbeddingAdvance =
  | {
      state: 'indexed'
      index_id: number
      source_block_id: number
      invocation_id: number
      recovered: boolean
      usage: { metered_amount: string | null; currency: string | null }
    }
  | { state: 'in_progress' | 'outcome_unknown'; index_id: number; source_block_id: number }
  | { state: 'ready'; index: KnowledgeEmbeddingIndexStart }

export default {
  list(environmentId: number, page = 1, limit = 20) {
    return request.get<SandAiPage<KnowledgeBaseRow>>({
      url: `${base}/index`,
      params: { environment_id: environmentId, page, limit },
      showErrorMessage: false
    })
  },
  eligibleFiles(environmentId: number, before?: number) {
    return request.get<{ files: KnowledgeSourceFile[]; next_before: number | null }>({
      url: `${base}/eligible-files`,
      params: { environment_id: environmentId, before },
      showErrorMessage: false
    })
  },
  async createDraft(input: KnowledgeDraftInput, key: string, expectedBaseId: number | null = null): Promise<KnowledgePublishResult> {
    const result: unknown = await request.post<unknown>({
      url: `${base}/create-draft`, data: input, headers: { 'Idempotency-Key': key },
      showErrorMessage: false
    })
    return draftResult(result, input.code, expectedBaseId)
  },
  async draftByRequestKey(input: KnowledgeDraftInput, key: string, actor: string, expectedBaseId: number | null): Promise<KnowledgePublishResult> {
    const fingerprint = await draftFingerprint(input)
    const value: unknown = await request.get<unknown>({
      url: `${base}/create-draft/by-request-key`,
      params: { environment_id: input.environment_id, request_key: key, request_fingerprint: fingerprint },
      headers: { 'Cache-Control': 'no-store' }, showErrorMessage: false
    })
    if (typeof value !== 'object' || value === null || Array.isArray(value)) throw new Error('原草稿证明无效，请保留原号')
    const receipt = value as Partial<KnowledgeDraftReceipt>
    if (receipt.operation !== 'create_draft' || receipt.environment_id !== input.environment_id
      || receipt.request_key !== key || receipt.request_fingerprint !== fingerprint || receipt.actor_ref !== actor
      || receipt.idempotent_replay !== true || typeof receipt.knowledge_base_id !== 'number'
      || !Number.isSafeInteger(receipt.knowledge_base_id) || receipt.knowledge_base_id < 1
      || typeof receipt.version_id !== 'number' || !Number.isSafeInteger(receipt.version_id) || receipt.version_id < 1
      || (expectedBaseId !== null && receipt.knowledge_base_id !== expectedBaseId)) {
      throw new Error('原草稿证明与管理员、输入或原号不一致，请保留原号')
    }
    const current = draftResult(receipt.current, input.code, receipt.knowledge_base_id)
    if (current.version.id !== receipt.version_id || current.idempotent_replay !== true) {
      throw new Error('当前版本与原草稿关联不一致，请保留原号')
    }
    return current
  },
  async publish(environmentId: number, knowledgeBaseId: number, key: string, snapshot: string): Promise<KnowledgePublishRecoveryResult> {
    const result: unknown = await request.post<unknown>({
      url: `${base}/publish`,
      data: { environment_id: environmentId, knowledge_base_id: knowledgeBaseId, expected_snapshot_sha256: snapshot },
      headers: { 'Idempotency-Key': key }, showErrorMessage: false
    })
    return publishRecoveryResult(result, environmentId, knowledgeBaseId, key, snapshot)
  },
  async publishByRequestKey(environmentId: number, knowledgeBaseId: number, key: string, snapshot: string): Promise<KnowledgePublishRecoveryResult> {
    const result: unknown = await request.get<unknown>({
      url: `${base}/publish/by-request-key`,
      params: { environment_id: environmentId, knowledge_base_id: knowledgeBaseId, request_key: key, expected_snapshot_sha256: snapshot },
      headers: { 'Cache-Control': 'no-store' }, showErrorMessage: false
    })
    return publishRecoveryResult(result, environmentId, knowledgeBaseId, key, snapshot)
  },
  async disable(environmentId: number, knowledgeBaseId: number, key: string): Promise<KnowledgeDisableResult> {
    const result: unknown = await request.post<unknown>({
      url: `${base}/disable`,
      data: { environment_id: environmentId, knowledge_base_id: knowledgeBaseId },
      headers: { 'Idempotency-Key': key },
      showErrorMessage: false
    })
    if (!isKnowledgeDisableResult(result, knowledgeBaseId)) {
      throw new Error('停用响应与原知识库不一致，请保留原请求号继续确认')
    }
    return result
  },
  async purgeEmbeddingIndex(environmentId: number, knowledgeBaseId: number, key: string): Promise<KnowledgeVectorsPurge> {
    const result: unknown = await request.post<unknown>({
      url: `${base}/embedding-index/purge`,
      data: { environment_id: environmentId, knowledge_base_id: knowledgeBaseId },
      headers: { 'Idempotency-Key': key },
      showErrorMessage: false
    })
    if (!isKnowledgeVectorsPurge(result, knowledgeBaseId)) {
      throw new Error('向量清理响应与原知识库不一致，请保留原请求号继续确认')
    }
    return result
  },
  async purgeSources(environmentId: number, knowledgeBaseId: number, key: string): Promise<KnowledgeSourcesPurge> {
    const result: unknown = await request.post<unknown>({
      url: `${base}/sources/purge`,
      data: { environment_id: environmentId, knowledge_base_id: knowledgeBaseId },
      headers: { 'Idempotency-Key': key },
      showErrorMessage: false
    })
    if (!isKnowledgeSourcesPurge(result, knowledgeBaseId)) {
      throw new Error('解除来源引用的响应无效，请刷新后核对状态')
    }
    return result
  },
  async lifecycleByRequestKey(environmentId: number, knowledgeBaseId: number, action: 'disable' | 'purge' | 'purge-sources', key: string, actor: string): Promise<KnowledgeLifecycleReceipt> {
    const paths = { disable: 'disable', purge: 'embedding-index/purge', 'purge-sources': 'sources/purge' }
    const operations = { disable: 'disable', purge: 'purge_vectors', 'purge-sources': 'purge_sources' }
    const value: unknown = await request.get<unknown>({
      url: `${base}/${paths[action]}/by-request-key`,
      params: { environment_id: environmentId, knowledge_base_id: knowledgeBaseId, request_id: key },
      headers: { 'Cache-Control': 'no-store' }, showErrorMessage: false
    })
    if (typeof value !== 'object' || value === null || Array.isArray(value)) throw new Error('原知识操作回执无效，保留原号')
    const result = value as Record<string, unknown>
    if (result.environment_id !== environmentId || result.knowledge_base_id !== knowledgeBaseId
      || result.operation !== operations[action] || result.request_id !== key || result.actor_ref !== actor
      || result.state !== (action === 'disable' ? 'disabled' : 'purged') || result.idempotent_replay !== true)
      throw new Error('原知识操作回执与管理员、目标或请求号不一致，保留原号')
    return result as unknown as KnowledgeLifecycleReceipt
  },
  async startEmbeddingIndex(environmentId: number, versionId: number, key: string): Promise<KnowledgeEmbeddingIndexStart> {
    const result: unknown = await request.post<unknown>({
      url: `${base}/embedding-index/start`,
      data: { environment_id: environmentId, knowledge_base_version_id: versionId },
      headers: { 'Idempotency-Key': key },
      showErrorMessage: false
    })
    return indexStartResult(result)
  },
  async embeddingIndexByRequestKey(environmentId: number, versionId: number, key: string, indexId?: number | null): Promise<KnowledgeEmbeddingRecovery> {
    const result: unknown = await request.get<unknown>({
      url: `${base}/embedding-index/by-request-key`,
      params: { environment_id: environmentId, knowledge_base_version_id: versionId, request_id: key },
      headers: { 'Cache-Control': 'no-store' }, showErrorMessage: false
    })
    return indexRecoveryResult(result, environmentId, versionId, key, indexId)
  },
  advanceEmbeddingIndex(environmentId: number, indexId: number) {
    return request.post<KnowledgeEmbeddingAdvance>({
      url: `${base}/embedding-index/advance`,
      data: { environment_id: environmentId, index_id: indexId },
      showErrorMessage: false
    })
  },
  async embeddingIndexStatus(environmentId: number, indexId: number): Promise<KnowledgeEmbeddingIndexStatus> {
    const result: unknown = await request.get<unknown>({
      url: `${base}/embedding-index/status`,
      params: { environment_id: environmentId, index_id: indexId },
      showErrorMessage: false
    })
    return indexStatusResult(result, indexId)
  }
}
