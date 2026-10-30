import type { CapabilitySavePayload } from './capability'

export interface CapabilityProfileIntent {
  version: 1
  admin_id: number
  application_id: number
  environment_id: number
  action: 'save' | 'publish'
  payload: CapabilitySavePayload
  previousRevision: number
  id?: number
  revision?: number
}
function record(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}
function positive(value: unknown): value is number {
  return typeof value === 'number' && Number.isSafeInteger(value) && value > 0
}
function key(adminId: number): string {
  if (!positive(adminId)) throw new Error('请先确认当前管理员身份')
  return `sand-ai:capability-profile:${adminId}:active`
}
function decode(value: unknown, adminId: number): CapabilityProfileIntent {
  if (!record(value) || value.version !== 1 || value.admin_id !== adminId
    || !positive(value.application_id) || !positive(value.environment_id)
    || !['save', 'publish'].includes(String(value.action)) || !record(value.payload)
    || typeof value.previousRevision !== 'number' || !Number.isSafeInteger(value.previousRevision) || value.previousRevision < 0
    || (value.id !== undefined && !positive(value.id)) || (value.revision !== undefined && !positive(value.revision))) {
    throw new Error('原能力配置操作记录无法确认，请保留记录并联系管理员')
  }
  const payload = value.payload
  if (payload.environment_id !== value.environment_id || typeof payload.name !== 'string' || !payload.name.trim()
    || !(payload.expected_revision === null || positive(payload.expected_revision))
    || payload.expected_revision !== (value.previousRevision || null)
    || !(payload.template_code === null || typeof payload.template_code === 'string')
    || !['local_only', 'private_only', 'approved_cloud'].includes(String(payload.data_egress_policy))
    || !Array.isArray(payload.routes) || payload.routes.length === 0) throw new Error('原能力配置内容无法确认')
  const routes = payload.routes.map<CapabilitySavePayload['routes'][number]>((route: unknown) => {
    if (!record(route) || typeof route.capability_code !== 'string' || !route.capability_code
      || !['model_deployment', 'parse_driver', 'audio_driver'].includes(String(route.route_kind))
      || !positive(route.priority) || !(route.deployment_id === null || positive(route.deployment_id))
      || !(route.driver_code === null || typeof route.driver_code === 'string')
      || !record(route.config) || Object.keys(route.config).length !== 0) throw new Error('原能力路线内容无法确认')
    const routeKind = route.route_kind
    if (routeKind !== 'model_deployment' && routeKind !== 'parse_driver' && routeKind !== 'audio_driver') throw new Error('原路线类型无法确认')
    return { capability_code: route.capability_code, route_kind: routeKind,
      deployment_id: route.deployment_id, driver_code: route.driver_code, priority: route.priority, config: {} }
  })
  if (value.action !== 'save' && value.action !== 'publish') throw new Error('原操作类型无法确认')
  if (value.action === 'publish' && (!positive(value.id) || value.revision !== value.previousRevision)) throw new Error('原发布目标无法确认')
  return { version: 1, admin_id: adminId, application_id: value.application_id, environment_id: value.environment_id,
    action: value.action, previousRevision: value.previousRevision, id: positive(value.id) ? value.id : undefined, revision: positive(value.revision) ? value.revision : undefined,
    payload: { environment_id: value.environment_id, expected_revision: payload.expected_revision,
      name: payload.name, template_code: payload.template_code, data_egress_policy: String(payload.data_egress_policy), routes } }
}
export function readCapabilityProfileIntent(adminId: number): CapabilityProfileIntent | null {
  const raw = sessionStorage.getItem(key(adminId))
  return raw === null ? null : decode(JSON.parse(raw), adminId)
}
export function retainCapabilityProfileIntent(intent: CapabilityProfileIntent, previous: CapabilityProfileIntent | null = null): void {
  const safe = decode(intent, intent.admin_id)
  const stored = readCapabilityProfileIntent(intent.admin_id)
  if (JSON.stringify(stored) !== JSON.stringify(previous)) throw new Error('原配置操作记录已变化，请重新读取')
  sessionStorage.setItem(key(intent.admin_id), JSON.stringify(safe))
  if (JSON.stringify(readCapabilityProfileIntent(intent.admin_id)) !== JSON.stringify(safe)) throw new Error('原操作记录未保存，写操作未发送')
}
export function resolveCapabilityProfileIntent(intent: CapabilityProfileIntent): void {
  if (JSON.stringify(readCapabilityProfileIntent(intent.admin_id)) !== JSON.stringify(intent)) throw new Error('原操作记录已变化，请保留未知结果')
  sessionStorage.removeItem(key(intent.admin_id))
  if (readCapabilityProfileIntent(intent.admin_id) !== null) throw new Error('原操作记录未解除')
}
