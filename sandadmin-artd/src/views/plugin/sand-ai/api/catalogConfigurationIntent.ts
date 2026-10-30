import type { SandAiModel, SandAiProvider } from './types'

export interface CatalogConfigurationIntent {
  readonly version: 1
  readonly admin_id: number
  readonly kind: 'provider' | 'model'
  readonly operation: 'save' | 'update'
  readonly code: string
  readonly id: number | null
  readonly key: string
}

type SafeProvider = Pick<SandAiProvider, 'id' | 'code' | 'name' | 'adapter' | 'timeout_ms' | 'status' | 'has_encrypted_config'>
type SafeModel = Pick<SandAiModel, 'id' | 'code' | 'name' | 'type' | 'capabilities' | 'status'>
type ReceiptMetadata = {
  readonly operation: 'save' | 'update'
  readonly actor_id: number | null
  readonly request_key: string
  readonly code: string
  readonly target_id: number | null
  readonly state: 'unknown' | 'completed'
}
export type CatalogConfigurationReceipt = ReceiptMetadata & (
  { readonly resource_type: 'provider'; readonly receipt: SafeProvider | null } |
  { readonly resource_type: 'model'; readonly receipt: SafeModel | null }
)

const memoryIntents = new Map<string, CatalogConfigurationIntent>()
function storageKey(adminId: number, kind: CatalogConfigurationIntent['kind']): string {
  if (!positiveId(adminId)) throw new Error('请先确认当前管理员身份')
  return `sand-ai:catalog-configuration:v1:${adminId}:${kind}`
}
function positiveId(value: unknown): value is number {
  return typeof value === 'number' && Number.isSafeInteger(value) && value > 0
}
function record(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}
function decode(value: unknown, adminId: number, kind: CatalogConfigurationIntent['kind']): CatalogConfigurationIntent {
  if (!record(value) || value.version !== 1 || value.admin_id !== adminId || value.kind !== kind
    || !['save', 'update'].includes(String(value.operation))
    || typeof value.code !== 'string' || !/^[a-z0-9][a-z0-9_-]{1,63}$/.test(value.code)
    || typeof value.key !== 'string' || !/^[\x21-\x7e]{1,128}$/.test(value.key)
    || (value.operation === 'save' ? value.id !== null : !positiveId(value.id))
    || Object.keys(value).some(field => !['version', 'admin_id', 'kind', 'operation', 'code', 'id', 'key'].includes(field))) {
    throw new Error('原配置保存记录无法确认，请保留原记录；暂不提交新的配置')
  }
  if (value.operation !== 'save' && value.operation !== 'update') throw new Error('原操作无法确认')
  return { version: 1, admin_id: adminId, kind, operation: value.operation, code: value.code,
    id: positiveId(value.id) ? value.id : null, key: value.key }
}
export function readCatalogConfigurationIntent(adminId: number, kind: CatalogConfigurationIntent['kind']): CatalogConfigurationIntent | null {
  const storage = storageKey(adminId, kind)
  const remembered = memoryIntents.get(storage)
  const raw = sessionStorage.getItem(storage)
  if (raw === null) {
    if (remembered) throw new Error('原配置保存记录已丢失，不能视为未提交；请保留当前会话与原请求号')
    return null
  }
  const intent = decode(JSON.parse(raw), adminId, kind)
  if (remembered && JSON.stringify(remembered) !== JSON.stringify(intent)) throw new Error('原配置保存记录已变化，写操作保持锁定')
  memoryIntents.set(storage, intent)
  return intent
}
export function rememberedCatalogConfigurationIntent(adminId: number, kind: CatalogConfigurationIntent['kind']): CatalogConfigurationIntent | null {
  return memoryIntents.get(storageKey(adminId, kind)) ?? null
}
export function retainCatalogConfigurationIntent(intent: CatalogConfigurationIntent, unsubmitted: CatalogConfigurationIntent | null = null): void {
  const safe = decode(intent, intent.admin_id, intent.kind)
  const storage = storageKey(safe.admin_id, safe.kind)
  const encoded = JSON.stringify(safe)
  if (unsubmitted) {
    const existing = sessionStorage.getItem(storage)
    const remembered = memoryIntents.get(storage)
    if (JSON.stringify(unsubmitted) !== encoded || (remembered && JSON.stringify(remembered) !== encoded)
      || (existing !== null && existing !== encoded)) throw new Error('原未发送目标记录已变化，暂不发送')
  } else if (readCatalogConfigurationIntent(safe.admin_id, safe.kind) !== null) {
    throw new Error('请先只读确认原保存请求')
  }
  memoryIntents.set(storage, safe)
  sessionStorage.setItem(storage, encoded)
  if (sessionStorage.getItem(storage) !== encoded) throw new Error('原请求标识未安全保存，本次不发送')
}
export function resolveCatalogConfigurationIntent(intent: CatalogConfigurationIntent): void {
  const storage = storageKey(intent.admin_id, intent.kind)
  if (JSON.stringify(readCatalogConfigurationIntent(intent.admin_id, intent.kind)) !== JSON.stringify(intent)) {
    throw new Error('原配置保存记录已变化，不能解除原操作锁')
  }
  sessionStorage.removeItem(storage)
  if (sessionStorage.getItem(storage) !== null) throw new Error('原请求记录尚未清除，继续保留原操作锁')
  memoryIntents.delete(storage)
}
export function matchesCatalogConfigurationReceipt(intent: CatalogConfigurationIntent, value: unknown): value is CatalogConfigurationReceipt {
  if (!record(value) || value.resource_type !== intent.kind || value.operation !== intent.operation
    || value.actor_id !== intent.admin_id || value.request_key !== intent.key || value.code !== intent.code
    || value.target_id !== intent.id || value.state !== 'completed' || !record(value.receipt)) return false
  const receipt = value.receipt
  if (!positiveId(receipt.id) || receipt.code !== intent.code || typeof receipt.name !== 'string'
    || (receipt.status !== 1 && receipt.status !== 2)
    || (intent.id !== null && receipt.id !== intent.id)) return false
  return intent.kind === 'provider'
    ? typeof receipt.adapter === 'string' && positiveId(receipt.timeout_ms) && typeof receipt.has_encrypted_config === 'boolean'
    : typeof receipt.type === 'string' && Array.isArray(receipt.capabilities) && receipt.capabilities.every(item => typeof item === 'string')
}
