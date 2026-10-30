import type { SandAiCleanupStatus, SandAiFile, SandAiParseSubmitResult, SandAiTask } from './types'
import { runtimeRequest } from './runtime'

export type FileSourceBlock = {
  source_block_id: number
  content_sha256: string
  locator_type: string
  locator: Record<string, unknown>
  preview: string
}

export type FileSourceBlocksPage = {
  file: { id: number; sha256: string; parse_id: number }
  blocks: FileSourceBlock[]
  next_after_id: number | null
}

function record(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function positiveId(value: unknown): value is number {
  return typeof value === 'number' && Number.isSafeInteger(value) && value > 0
}

function sha256(value: unknown): value is string {
  return typeof value === 'string' && /^[a-f0-9]{64}$/.test(value)
}

function sourceBlock(value: unknown): value is FileSourceBlock {
  return record(value) && positiveId(value.source_block_id) && sha256(value.content_sha256)
    && typeof value.locator_type === 'string' && value.locator_type.length > 0
    && record(value.locator) && typeof value.preview === 'string'
}

function sourceBlocksPage(value: unknown): FileSourceBlocksPage {
  if (!record(value) || !record(value.file) || !positiveId(value.file.id)
    || !positiveId(value.file.parse_id) || !sha256(value.file.sha256)
    || !Array.isArray(value.blocks) || value.blocks.length > 40
    || !value.blocks.every(sourceBlock)
    || (value.next_after_id !== null && !positiveId(value.next_after_id))) {
    throw new Error('解析来源响应格式不完整，请读取原任务确认')
  }
  const blocks = value.blocks
  if (blocks.some((block, index) => index > 0 && block.source_block_id <= blocks[index - 1].source_block_id)
    || (value.next_after_id !== null && value.next_after_id !== blocks[blocks.length - 1]?.source_block_id)) {
    throw new Error('解析来源游标与来源块不一致，请重新读取')
  }
  return {
    file: { id: value.file.id, sha256: value.file.sha256, parse_id: value.file.parse_id },
    blocks,
    next_after_id: value.next_after_id
  }
}

/** file.write never implies access to parsed data or complete file.read DTOs. */
export type UploadReceipt = {
  id: number; file_ref?: string; original_name: string; extension: string
  media_type: string; size_bytes: number; sha256: string; state: string
}
export type UploadLookup = { state: 'pending'; file: null } | { state: 'resolved'; file: UploadReceipt }
function uploadReceipt(value: unknown): UploadReceipt {
  if (!record(value) || !positiveId(value.id) || !sha256(value.sha256)
    || typeof value.original_name !== 'string' || typeof value.extension !== 'string'
    || typeof value.media_type !== 'string' || typeof value.state !== 'string'
    || typeof value.size_bytes !== 'number' || !Number.isSafeInteger(value.size_bytes) || value.size_bytes < 1
    || (value.file_ref !== undefined && typeof value.file_ref !== 'string')) {
    throw new Error('上传回执暂不可确认，请读取原上传结果')
  }
  return { id: value.id, original_name: value.original_name, extension: value.extension,
    media_type: value.media_type, state: value.state, size_bytes: value.size_bytes,
    sha256: value.sha256, ...(value.file_ref === undefined ? {} : { file_ref: value.file_ref }) }
}

export default {
  async uploadContext(apiKey: string, environmentId?: number): Promise<{ scope: string; environment_id: number }> {
    const result = await runtimeRequest<unknown>({ method: 'GET', path: '/files/upload-context', apiKey,
      headers: { 'Cache-Control': 'no-store', ...(environmentId === undefined ? {} : { 'X-Sand-AI-Environment-ID': String(environmentId) }) } })
    if (!record(result) || !sha256(result.scope) || !positiveId(result.environment_id)
      || (environmentId !== undefined && result.environment_id !== environmentId)) throw new Error('当前上传身份尚未确认')
    return { scope: result.scope, environment_id: result.environment_id }
  },

  async upload(apiKey: string, file: File, requestKey: string, environmentId: number): Promise<UploadReceipt> {
    if (!/^[A-Za-z0-9][A-Za-z0-9_.:-]{7,95}$/.test(requestKey)) throw new Error('请先保留本次上传记录')
    const form = new FormData()
    form.append('file', file)
    return uploadReceipt(await runtimeRequest<unknown>({ method: 'POST', path: '/files', apiKey, data: form,
      headers: { 'X-Request-Id': requestKey, 'X-Sand-AI-Environment-ID': String(environmentId) } }))
  },

  async readUpload(apiKey: string, requestKey: string, environmentId: number): Promise<UploadLookup> {
    const value = await runtimeRequest<unknown>({ method: 'GET', path: '/files/by-request-key', apiKey,
      params: { request_key: requestKey }, headers: { 'Cache-Control': 'no-store', 'X-Sand-AI-Environment-ID': String(environmentId) } })
    if (record(value) && value.state === 'pending' && value.file === null) return { state: 'pending', file: null }
    if (record(value) && value.state === 'resolved') return { state: 'resolved', file: uploadReceipt(value.file) }
    throw new Error('原上传结果暂不可确认')
  },

  read(apiKey: string, fileId: number, environmentId?: number) {
    return runtimeRequest<SandAiFile>({
      method: 'GET',
      path: `/files/${fileId}`,
      apiKey,
      headers: environmentId === undefined ? undefined : { 'X-Sand-AI-Environment-ID': String(environmentId) }
    })
  },

  async readSourceBlocks(apiKey: string, fileId: number, parseId: number, afterId?: number): Promise<FileSourceBlocksPage> {
    const response = await runtimeRequest<unknown>({
      method: 'GET',
      path: `/files/${fileId}/source-blocks`,
      apiKey,
      params: {
        parse_id: parseId,
        limit: 40,
        ...(afterId === undefined ? {} : { after_id: afterId })
      },
      headers: { 'Cache-Control': 'no-store' }
    })
    return sourceBlocksPage(response)
  },

  /** API 1.4：异步提交解析任务，立即返回 task */
  parse(apiKey: string, fileId: number, requestId?: string, environmentId?: number) {
    return runtimeRequest<SandAiParseSubmitResult>({
      method: 'POST',
      path: `/files/${fileId}/parse`,
      apiKey,
      headers: {
        ...(requestId ? { 'X-Request-Id': requestId } : {}),
        ...(environmentId === undefined ? {} : { 'X-Sand-AI-Environment-ID': String(environmentId) })
      }
    })
  },

  readParseByRequestKey(apiKey: string, fileId: number, requestKey: string, environmentId?: number) {
    return runtimeRequest<SandAiTask>({
      method: 'GET', path: `/files/${fileId}/parse/by-request-key`,
      apiKey, params: { request_key: requestKey }, headers: {
        'Cache-Control': 'no-store',
        ...(environmentId === undefined ? {} : { 'X-Sand-AI-Environment-ID': String(environmentId) })
      }
    })
  },

  destroy(apiKey: string, fileId: number) {
    return runtimeRequest<{ readonly id: number; readonly state: 'deleted'; readonly cleanup: SandAiCleanupStatus }>({
      method: 'DELETE',
      path: `/files/${fileId}`,
      apiKey
    })
  }
}
