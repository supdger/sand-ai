import { runtimeRequest } from './runtime'

export interface KnowledgeSourceReference {
  knowledge_base_code: string
  knowledge_base_version: number
  file_id: number
  parse_id: number
  source_block_id: number
}

export interface KnowledgeSourceBody extends KnowledgeSourceReference {
  locator_type: string
  locator: Record<string, unknown>
  content_sha256: string
  content: string
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function positiveId(value: unknown): value is number {
  return typeof value === 'number' && Number.isSafeInteger(value) && value > 0
}

/** Historical citations without these persisted fields cannot identify a public source. */
export function knowledgeSourceReference(value: unknown): KnowledgeSourceReference | null {
  if (!isRecord(value)
    || typeof value.knowledge_base_code !== 'string'
    || !/^[a-z][a-z0-9_-]{0,63}$/.test(value.knowledge_base_code)
    || !positiveId(value.knowledge_base_version)
    || !positiveId(value.file_id) || !positiveId(value.parse_id)
    || !positiveId(value.source_block_id)) return null
  return {
    knowledge_base_code: value.knowledge_base_code,
    knowledge_base_version: value.knowledge_base_version,
    file_id: value.file_id,
    parse_id: value.parse_id,
    source_block_id: value.source_block_id
  }
}

function isSourceBody(value: unknown, expected: KnowledgeSourceReference): value is KnowledgeSourceBody {
  const reference = knowledgeSourceReference(value)
  return isRecord(value) && reference !== null
    && reference.knowledge_base_code === expected.knowledge_base_code
    && reference.knowledge_base_version === expected.knowledge_base_version
    && reference.file_id === expected.file_id && reference.parse_id === expected.parse_id
    && reference.source_block_id === expected.source_block_id
    && typeof value.locator_type === 'string' && value.locator_type.length > 0
    && isRecord(value.locator)
    && typeof value.content_sha256 === 'string' && /^[a-f0-9]{64}$/.test(value.content_sha256)
    && typeof value.content === 'string'
    && Object.keys(value).every(key => [
      'knowledge_base_code', 'knowledge_base_version', 'file_id', 'parse_id',
      'source_block_id', 'locator_type', 'locator', 'content_sha256', 'content'
    ].includes(key))
}

export default {
  async read(apiKey: string, reference: KnowledgeSourceReference, environmentId?: number): Promise<KnowledgeSourceBody> {
    const target = knowledgeSourceReference(reference)
    if (target === null) throw new Error('引用缺少知识版本或解析信息，无法读取来源正文。')
    const result = await runtimeRequest<unknown>({
      method: 'GET',
      path: `/knowledge-bases/${encodeURIComponent(target.knowledge_base_code)}/versions/${target.knowledge_base_version}/sources/${target.source_block_id}`,
      apiKey, headers: {
        'Cache-Control': 'no-store',
        ...(environmentId === undefined ? {} : { 'X-Sand-AI-Environment-ID': String(environmentId) })
      }
    })
    if (!isSourceBody(result, target)) throw new Error('来源响应与引用不一致，无法展示正文。')
    return result
  }
}
