import request from '@/utils/http'
import type { SandAiPage } from './types'
import type { SandAiChannelSource, SandAiInvocationCost, SandAiTask, SandAiTaskSubmitResult } from './types'
import { runtimeRequest } from './runtime'
import { RUNTIME_API_PREFIX } from './config'
import { AgentReplayError } from './agentEventReplay'

const base = '/app/sand-ai/admin/channels/bindings'

export interface ChannelBindingRow {
  readonly channel_binding_id: number
  readonly environment_id: number
  readonly code: string
  readonly name: string
  readonly agent_definition_id: number
  readonly agent_name: string
  readonly agent_code: string
  readonly application_id: string
  readonly workload_client_id: string
  readonly revision: number
  readonly status: 1 | 2
  readonly feishu_configured: boolean
  readonly wecom_configured: boolean
}

export type ChannelBindingSnapshot = Omit<ChannelBindingRow, 'agent_name' | 'agent_code'>
export type ChannelBindingOperationReference = Readonly<{
  environment_id: number; request_key: string; input_sha256: string
}> & (
  { readonly operation: 'save'; readonly code: string } |
  { readonly operation: 'disable'; readonly channel_binding_id: number }
)
export type ChannelBindingOperationRecovery = Readonly<{
  operation: 'save' | 'disable'; environment_id: number; request_key: string; input_sha256: string
  target: { readonly code: string } | { readonly channel_binding_id: number }
}> & (
  { readonly state: 'unknown'; readonly binding: null } |
  { readonly state: 'completed'; readonly binding: ChannelBindingSnapshot }
)
function bindingRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}
function positiveBindingInteger(value: unknown): value is number {
  return typeof value === 'number' && Number.isSafeInteger(value) && value > 0
}
export function isChannelBindingSnapshot(value: unknown): value is ChannelBindingSnapshot {
  if (!bindingRecord(value)) return false
  const fields = ['channel_binding_id', 'environment_id', 'code', 'name', 'agent_definition_id',
    'application_id', 'workload_client_id', 'revision', 'status', 'feishu_configured', 'wecom_configured']
  return Object.keys(value).every(key => fields.includes(key) || key === 'idempotent_replay') &&
    positiveBindingInteger(value.channel_binding_id) && positiveBindingInteger(value.environment_id) &&
    positiveBindingInteger(value.agent_definition_id) && positiveBindingInteger(value.revision) &&
    typeof value.code === 'string' && /^[a-z][a-z0-9_-]{0,63}$/.test(value.code) &&
    typeof value.name === 'string' && value.name.trim() !== '' &&
    typeof value.application_id === 'string' && /^[1-9][0-9]{0,18}$/.test(value.application_id) &&
    typeof value.workload_client_id === 'string' && /^[1-9][0-9]{0,18}$/.test(value.workload_client_id) &&
    (value.status === 1 || value.status === 2) && typeof value.feishu_configured === 'boolean' &&
    typeof value.wecom_configured === 'boolean' &&
    (!('idempotent_replay' in value) || typeof value.idempotent_replay === 'boolean')
}
export async function channelBindingInputSha256(value: ChannelBindingDraft | ChannelBindingSnapshot | number): Promise<string> {
  // Matches the binding policy configuration and RequestPayloadFingerprint key order; environment is excluded.
  const input = typeof value === 'number' ? String(value) : JSON.stringify({
    agent_definition_id: value.agent_definition_id, application_id: value.application_id.trim(),
    code: value.code, name: value.name.trim(), workload_client_id: value.workload_client_id.trim()
  }).replace(/\u2028/g, '\\u2028').replace(/\u2029/g, '\\u2029')
  const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(input))
  return Array.from(new Uint8Array(digest), byte => byte.toString(16).padStart(2, '0')).join('')
}
export async function provesChannelBindingSnapshot(value: unknown, original: ChannelBindingOperationReference): Promise<boolean> {
  if (!isChannelBindingSnapshot(value) || value.environment_id !== original.environment_id) return false
  if (original.operation === 'disable') return value.channel_binding_id === original.channel_binding_id && value.status === 2
  return value.code === original.code && value.status === 1 && await channelBindingInputSha256(value) === original.input_sha256
}
async function readBindingOperation(value: unknown, original: ChannelBindingOperationReference): Promise<ChannelBindingOperationRecovery> {
  if (!bindingRecord(value) || Object.keys(value).length !== 7 ||
    !['operation', 'environment_id', 'request_key', 'input_sha256', 'target', 'state', 'binding'].every(key => key in value) ||
    value.operation !== original.operation || value.environment_id !== original.environment_id ||
    value.request_key !== original.request_key || value.input_sha256 !== original.input_sha256 ||
    !bindingRecord(value.target) || Object.keys(value.target).length !== 1 ||
    (original.operation === 'save' ? value.target.code !== original.code : value.target.channel_binding_id !== original.channel_binding_id)) {
    throw new Error('原渠道操作回执与请求不一致，仍保留原请求')
  }
  const owner = { operation: original.operation, environment_id: original.environment_id, request_key: original.request_key,
    input_sha256: original.input_sha256, target: original.operation === 'save' ? { code: original.code } : { channel_binding_id: original.channel_binding_id } }
  if (value.state === 'unknown' && value.binding === null) return { ...owner, state: 'unknown', binding: null }
  if (value.state === 'completed' && isChannelBindingSnapshot(value.binding) && await provesChannelBindingSnapshot(value.binding, original)) {
    return { ...owner, state: 'completed', binding: value.binding }
  }
  throw new Error('原渠道操作结果尚未获得完整证明，仍保留原请求')
}

export interface WecomCredentialDraft {
  readonly environment_id: number
  readonly channel_binding_id: number
  readonly token: string
  readonly encoding_aes_key: string
  readonly corp_id: string
  readonly agent_id: string
  readonly corp_secret: string
  readonly workload_credential: string
}

export interface FeishuCredentialDraft {
  readonly environment_id: number
  readonly channel_binding_id: number
  readonly encrypt_key: string
  readonly verification_token: string
  readonly workload_credential: string
  readonly app_id: string
  readonly app_secret: string
}

export type ChannelCredentialPlatform = 'feishu' | 'wecom'
export type ChannelCredentialSaveReference = Readonly<{
  platform: ChannelCredentialPlatform
  environment_id: number
  channel_binding_id: number
  code: string
  request_key: string
  input_sha256: string
}>
type FeishuCredentialSaveReceipt = Readonly<{ channel_binding_id: number; code: string; feishu_configured: true }>
type WecomCredentialSaveReceipt = Readonly<{ channel_binding_id: number; code: string; wecom_configured: true }>
export type ChannelCredentialSaveReceipt = FeishuCredentialSaveReceipt | WecomCredentialSaveReceipt
export type ChannelCredentialOperationRecovery = ChannelCredentialSaveReference & (
  { readonly state: 'unknown'; readonly actor_id: null; readonly receipt: null } |
  { readonly state: 'completed'; readonly actor_id: number; readonly receipt: ChannelCredentialSaveReceipt }
)
function credentialReceipt(value: unknown, platform: ChannelCredentialPlatform): ChannelCredentialSaveReceipt {
  const configured = platform === 'feishu' ? 'feishu_configured' : 'wecom_configured'
  if (!bindingRecord(value) || !positiveBindingInteger(value.channel_binding_id) ||
    typeof value.code !== 'string' || !/^[a-z][a-z0-9_-]{0,63}$/.test(value.code) ||
    value[configured] !== true || !Object.keys(value).every(key => ['channel_binding_id', 'code', configured, 'idempotent_replay'].includes(key)) ||
    ('idempotent_replay' in value && typeof value.idempotent_replay !== 'boolean'))
    throw new Error('渠道配置保存回执无法确认，请保留原请求')
  return platform === 'feishu'
    ? { channel_binding_id: value.channel_binding_id, code: value.code, feishu_configured: true }
    : { channel_binding_id: value.channel_binding_id, code: value.code, wecom_configured: true }
}
function credentialDigestHex(digest: ArrayBuffer): string {
  return Array.from(new Uint8Array(digest), item => item.toString(16).padStart(2, '0')).join('')
}
function feishuCredentialReceipt(value: unknown): FeishuCredentialSaveReceipt {
  const receipt = credentialReceipt(value, 'feishu')
  if ('feishu_configured' in receipt) return receipt
  throw new Error('飞书配置保存回执无法确认')
}
function wecomCredentialReceipt(value: unknown): WecomCredentialSaveReceipt {
  const receipt = credentialReceipt(value, 'wecom')
  if ('wecom_configured' in receipt) return receipt
  throw new Error('企业微信配置保存回执无法确认')
}
export function channelCredentialInputSha256(value: FeishuCredentialDraft | WecomCredentialDraft): Promise<string> {
  // The existing credential fingerprint uses original values and excludes environment/key.
  const input: Record<string, string | number> = 'encrypt_key' in value
    ? { app_id: value.app_id, app_secret: value.app_secret, channel_binding_id: value.channel_binding_id,
      encrypt_key: value.encrypt_key, verification_token: value.verification_token, workload_credential: value.workload_credential }
    : { agent_id: value.agent_id, channel_binding_id: value.channel_binding_id, corp_id: value.corp_id,
      corp_secret: value.corp_secret, encoding_aes_key: value.encoding_aes_key, token: value.token, workload_credential: value.workload_credential }
  const json = JSON.stringify(input).replace(/\u2028/g, '\\u2028').replace(/\u2029/g, '\\u2029')
  return crypto.subtle.digest('SHA-256', new TextEncoder().encode(json)).then(credentialDigestHex)
}

export interface ChannelBindingDraft {
  readonly environment_id: number
  readonly code: string
  readonly name: string
  readonly agent_definition_id: number
  readonly application_id: string
  readonly workload_client_id: string
}

export interface ChannelDeliveryRow {
  readonly task_id: number
  readonly channel_revision: number
  readonly state: 'pending' | 'sending' | 'sent' | 'failed' | 'uncertain'
  readonly task_state: string
  readonly attempt_count: number
  readonly part_count?: number | null
  readonly confirmed_parts?: number | null
  readonly error_code: string | null
  readonly create_time: string
  readonly update_time: string
}

export interface ChannelDeliveryRetryReceipt {
  readonly task_id: number
  /** Acceptance snapshot; read the delivery list for current sending state. */
  readonly state: 'pending'
}

export interface WecomDeliveryRetryReceipt extends ChannelDeliveryRetryReceipt {
  readonly confirmed_parts: number
}

export type ChannelDeliveryRetryRecovery = ChannelDeliveryRetryReceipt & { readonly idempotent_replay: true }
export type WecomDeliveryRetryRecovery = WecomDeliveryRetryReceipt & { readonly idempotent_replay: true }

export interface FeishuChannelStatus {
  readonly channel_binding_id: number
  readonly last_verified_callback_at: string | null
  readonly last_accepted_message_at: string | null
  readonly last_callback_error_code: string | null
  readonly last_callback_error_at: string | null
  readonly last_delivery_state: ChannelDeliveryRow['state'] | null
  readonly last_delivery_at: string | null
  readonly last_delivery_error_code: string | null
  readonly last_delivery_error_at: string | null
}

export type WecomChannelStatus = Omit<FeishuChannelStatus, 'last_delivery_state'> & {
  readonly last_delivery_state: ChannelDeliveryRow['state'] | null
}

export type ChannelDeliveryPlatform = 'feishu' | 'wecom'

export interface ChannelConversationCloseReceipt {
  readonly id: number
  readonly closed_after_task_id: number
  readonly idempotent_replay: boolean
}
export interface ChannelConversationCloseRecovery extends ChannelConversationCloseReceipt {
  readonly operation: 'close'
  readonly request_key: string
  readonly environment_id: number
  readonly channel_binding_id: number
  readonly code: string
  readonly conversation_id: string
  readonly sender_id: string
  readonly delivery_platform: ChannelDeliveryPlatform | null
  readonly actor_ref: string
  readonly idempotent_replay: true
}
function isConversationCloseReceipt(value: unknown): value is ChannelConversationCloseReceipt {
  return bindingRecord(value) && positiveBindingInteger(value.id) &&
    typeof value.closed_after_task_id === 'number' && Number.isSafeInteger(value.closed_after_task_id) && value.closed_after_task_id >= 0 &&
    typeof value.idempotent_replay === 'boolean'
}

export interface ChannelReceiptRecoveryProjection {
  readonly mode: 'receipt_only'
  readonly available: boolean
  readonly reason: string | null
  readonly provider_reissued: false
  readonly expected_task_version: number
  readonly expected_lease_fence: number
  readonly accepted: {
    readonly intent_key_sha256: string
    readonly previous_task_version: number
    readonly previous_lease_fence: number
  } | null
}
export type ChannelReceiptRecoveryTask = SandAiTask & { readonly channel_receipt_recovery?: ChannelReceiptRecoveryProjection | null }
export interface ChannelReceiptRecoveryInput {
  readonly conversation_id: string
  readonly sender_id: string
  readonly expected_task_version: number
  readonly expected_lease_fence: number
  readonly delivery_platform?: ChannelDeliveryPlatform
}
export interface ChannelReceiptRecoveryReceipt {
  readonly mode: 'receipt_only'
  readonly accepted: true
  readonly intent_key_sha256: string
  readonly previous_task_version: number
  readonly previous_lease_fence: number
}
export function isChannelReceiptRecoveryProjection(value: unknown): value is ChannelReceiptRecoveryProjection {
  const fence = (item: unknown): item is number => typeof item === 'number' && Number.isSafeInteger(item) && item >= 0
  const accepted = (item: unknown): boolean => bindingRecord(item) &&
    typeof item.intent_key_sha256 === 'string' && /^[a-f0-9]{64}$/.test(item.intent_key_sha256) &&
    positiveBindingInteger(item.previous_task_version) && fence(item.previous_lease_fence)
  return bindingRecord(value) && value.mode === 'receipt_only' && typeof value.available === 'boolean' &&
    (value.reason === null || typeof value.reason === 'string') && value.provider_reissued === false &&
    positiveBindingInteger(value.expected_task_version) && fence(value.expected_lease_fence) &&
    (value.accepted === null || accepted(value.accepted))
}
export interface ChannelMessage {
  query: string | null
  task: ChannelReceiptRecoveryTask
}

export default {
  recoverReceipt(apiKey: string, code: string, taskId: number, input: ChannelReceiptRecoveryInput, key: string) {
    return runtimeRequest<ChannelReceiptRecoveryTask>({ method: 'POST',
      path: `/channels/${encodeURIComponent(code)}/messages/${taskId}/receipt-recovery`, apiKey,
      data: { ...input }, headers: { 'Idempotency-Key': key } })
  },
  async receiptRecoveryByRequestKey(apiKey: string, code: string, taskId: number, input: ChannelReceiptRecoveryInput, key: string): Promise<ChannelReceiptRecoveryReceipt> {
    const value: unknown = await runtimeRequest<unknown>({ method: 'GET',
      path: `/channels/${encodeURIComponent(code)}/messages/${taskId}/receipt-recovery/by-request-key`, apiKey,
      params: { conversation_id: input.conversation_id, sender_id: input.sender_id, request_key: key,
        ...(input.delivery_platform ? { delivery_platform: input.delivery_platform } : {}) } })
    if (!bindingRecord(value) || !bindingRecord(value.task) || value.task.id !== taskId || !bindingRecord(value.receipt_recovery))
      throw new Error('原结果恢复收据尚未确认，保留原授权请求号')
    const receipt = value.receipt_recovery
    if (receipt.mode !== 'receipt_only' || receipt.accepted !== true ||
      typeof receipt.intent_key_sha256 !== 'string' || !/^[a-f0-9]{64}$/.test(receipt.intent_key_sha256) ||
      receipt.previous_task_version !== input.expected_task_version || receipt.previous_lease_fence !== input.expected_lease_fence)
      throw new Error('恢复收据与原授权请求不一致，保留原号')
    // The dedicated read proves a durable intent; read the conversation separately for the current task view.
    return {
      mode: 'receipt_only', accepted: true, intent_key_sha256: receipt.intent_key_sha256,
      previous_task_version: input.expected_task_version, previous_lease_fence: input.expected_lease_fence }
  },
  submitMessage(apiKey: string, code: string, message: {
    message_id: string
    conversation_id: string
    sender_id: string
    query: string
  }, key: string) {
    return runtimeRequest<SandAiTaskSubmitResult>({
      method: 'POST',
      path: `/channels/${encodeURIComponent(code)}/messages`,
      apiKey,
      data: message,
      headers: { 'Idempotency-Key': key }
    })
  },
  messageByRequestKey(
    apiKey: string,
    code: string,
    messageId: string,
    conversationId: string,
    senderId: string,
    key: string,
    deliveryPlatform?: ChannelDeliveryPlatform
  ) {
    return runtimeRequest<ChannelMessage>({
      method: 'GET',
      path: `/channels/${encodeURIComponent(code)}/messages/by-request-key`,
      apiKey,
      params: { message_id: messageId, conversation_id: conversationId, sender_id: senderId, ...(deliveryPlatform ? { delivery_platform: deliveryPlatform } : {}) },
      headers: { 'Idempotency-Key': key }
    })
  },
  source(apiKey: string, code: string, taskId: number, sourceBlockId: number, conversationId: string, senderId: string, eventNo?: number, deliveryPlatform?: ChannelDeliveryPlatform) {
    return runtimeRequest<SandAiChannelSource>({
      method: 'GET',
      path: `/channels/${encodeURIComponent(code)}/messages/${taskId}/sources/${sourceBlockId}`,
      apiKey,
      params: { conversation_id: conversationId, sender_id: senderId, ...(eventNo === undefined ? {} : { event_no: eventNo }), ...(deliveryPlatform ? { delivery_platform: deliveryPlatform } : {}) }
    })
  },
  conversation(apiKey: string, code: string, conversationId: string, senderId: string, beforeTaskId?: number, deliveryPlatform?: ChannelDeliveryPlatform) {
    return runtimeRequest<{ messages: ChannelMessage[]; next_before_task_id: number | null; closed_after_task_id: number }>({
      method: 'GET',
      path: `/channels/${encodeURIComponent(code)}/conversations/${encodeURIComponent(conversationId)}/messages`,
      apiKey,
      params: { sender_id: senderId, limit: 20, ...(beforeTaskId ? { before_task_id: beforeTaskId } : {}), ...(deliveryPlatform ? { delivery_platform: deliveryPlatform } : {}) }
    })
  },
  async closeConversation(apiKey: string, code: string, conversationId: string, senderId: string, key: string, deliveryPlatform?: ChannelDeliveryPlatform): Promise<ChannelConversationCloseReceipt> {
    const value: unknown = await runtimeRequest<unknown>({
      method: 'POST',
      path: `/channels/${encodeURIComponent(code)}/conversations/${encodeURIComponent(conversationId)}/close`,
      apiKey,
      data: { sender_id: senderId, ...(deliveryPlatform ? { delivery_platform: deliveryPlatform } : {}) },
      headers: { 'Idempotency-Key': key }
    })
    if (!isConversationCloseReceipt(value)) throw new Error('原关闭回执无法确认，保留原号')
    return { id: value.id, closed_after_task_id: value.closed_after_task_id, idempotent_replay: value.idempotent_replay }
  },
  async readConversationClose(apiKey: string, binding: ChannelBindingRow, conversationId: string, senderId: string, key: string, deliveryPlatform?: ChannelDeliveryPlatform): Promise<ChannelConversationCloseRecovery> {
    const value: unknown = await runtimeRequest<unknown>({
      method: 'GET',
      path: `/channels/${encodeURIComponent(binding.code)}/conversations/${encodeURIComponent(conversationId)}/close/by-request-key`,
      apiKey,
      params: { sender_id: senderId, ...(deliveryPlatform ? { delivery_platform: deliveryPlatform } : {}) },
      headers: { 'Idempotency-Key': key }
    })
    if (!bindingRecord(value) || !isConversationCloseReceipt(value) || value.idempotent_replay !== true ||
      value.operation !== 'close' || value.request_key !== key || value.environment_id !== binding.environment_id ||
      value.channel_binding_id !== binding.channel_binding_id || value.code !== binding.code ||
      value.conversation_id !== conversationId || value.sender_id !== senderId ||
      value.delivery_platform !== (deliveryPlatform ?? null) || typeof value.actor_ref !== 'string' || !value.actor_ref ||
      !Object.keys(value).every(field => ['id', 'closed_after_task_id', 'idempotent_replay', 'operation', 'request_key',
        'environment_id', 'channel_binding_id', 'code', 'conversation_id', 'sender_id', 'delivery_platform', 'actor_ref'].includes(field))) {
      throw new Error('原关闭回执与原目标不符，保留原号')
    }
    return { id: value.id, closed_after_task_id: value.closed_after_task_id, idempotent_replay: true,
      operation: 'close', request_key: key, environment_id: binding.environment_id, channel_binding_id: binding.channel_binding_id,
      code: binding.code, conversation_id: conversationId, sender_id: senderId,
      delivery_platform: deliveryPlatform ?? null, actor_ref: value.actor_ref }
  },
  messageCost(apiKey: string, requestId: string) {
    return runtimeRequest<SandAiInvocationCost>({
      method: 'GET',
      path: `/invocations/${encodeURIComponent(requestId)}/cost`,
      apiKey
    })
  },
  list(environmentId: number, page = 1, limit = 20) {
    return request.get<SandAiPage<ChannelBindingRow>>({
      url: `${base}/index`, params: { environment_id: environmentId, page, limit }, showErrorMessage: false
    })
  },
  async save(data: ChannelBindingDraft, key: string): Promise<ChannelBindingSnapshot> {
    const result = await request.post<unknown>({
      url: `${base}/save`, data, headers: { 'Idempotency-Key': key }, showErrorMessage: false
    })
    if (!isChannelBindingSnapshot(result)) throw new Error('渠道保存回执无法确认')
    return result
  },
  saveFeishuCredentials(data: FeishuCredentialDraft, key: string): Promise<FeishuCredentialSaveReceipt> {
    return request.post<unknown>({
      url: `${base}/feishu-credentials/save`, data, headers: { 'Idempotency-Key': key }, showErrorMessage: false
    }).then(feishuCredentialReceipt)
  },
  saveWecomCredentials(data: WecomCredentialDraft, key: string): Promise<WecomCredentialSaveReceipt> {
    return request.post<unknown>({
      url: `${base}/wecom-credentials/save`, data, headers: { 'Idempotency-Key': key }, showErrorMessage: false
    }).then(wecomCredentialReceipt)
  },
  async readCredentialOperation(original: ChannelCredentialSaveReference, actorId: string): Promise<ChannelCredentialOperationRecovery> {
    const owner: ChannelCredentialSaveReference = { platform: original.platform, environment_id: original.environment_id,
      channel_binding_id: original.channel_binding_id, code: original.code, request_key: original.request_key, input_sha256: original.input_sha256 }
    const value = await request.get<unknown>({
      url: `${base}/${original.platform}-credentials/by-request-key`,
      params: { environment_id: original.environment_id, channel_binding_id: original.channel_binding_id,
        code: original.code, request_key: original.request_key, input_sha256: original.input_sha256 },
      showErrorMessage: false
    })
    if (!bindingRecord(value) || Object.keys(value).length !== 9 ||
      !Object.keys(value).every(key => ['platform', 'actor_id', 'environment_id', 'channel_binding_id', 'code',
        'request_key', 'input_sha256', 'state', 'receipt'].includes(key)) ||
      value.platform !== original.platform || value.environment_id !== original.environment_id ||
      value.channel_binding_id !== original.channel_binding_id || value.code !== original.code ||
      value.request_key !== original.request_key || value.input_sha256 !== original.input_sha256)
      throw new Error('原渠道配置回执与原请求不一致，仍保留原请求')
    if (value.state === 'unknown' && value.actor_id === null && value.receipt === null)
      return { ...owner, state: 'unknown', actor_id: null, receipt: null }
    if (value.state !== 'completed' || !positiveBindingInteger(value.actor_id) || String(value.actor_id) !== actorId)
      throw new Error('原渠道配置保存者尚未获得证明，仍保留原请求')
    const receipt = credentialReceipt(value.receipt, original.platform)
    if (!bindingRecord(value.receipt) || Object.keys(value.receipt).length !== 3 ||
      receipt.channel_binding_id !== original.channel_binding_id || receipt.code !== original.code)
      throw new Error('原渠道配置回执与原目标不一致，仍保留原请求')
    return { ...owner, state: 'completed', actor_id: value.actor_id, receipt }
  },
  async disable(environmentId: number, bindingId: number, key: string): Promise<ChannelBindingSnapshot> {
    const result = await request.post<unknown>({
      url: `${base}/disable`, data: { environment_id: environmentId, channel_binding_id: bindingId },
      headers: { 'Idempotency-Key': key }, showErrorMessage: false
    })
    if (!isChannelBindingSnapshot(result)) throw new Error('渠道停用回执无法确认')
    return result
  },
  async operationByRequestKey(original: ChannelBindingOperationReference): Promise<ChannelBindingOperationRecovery> {
    const result = await request.get<unknown>({
      url: `${base}/operations/by-request-key`, params: { ...original },
      headers: { 'Cache-Control': 'no-store' }, showErrorMessage: false
    })
    return readBindingOperation(result, original)
  },
  deliveries(environmentId: number, bindingId: number, page = 1, limit = 20) {
    return request.get<SandAiPage<ChannelDeliveryRow>>({
      url: `${base}/deliveries`,
      params: { environment_id: environmentId, channel_binding_id: bindingId, page, limit },
      showErrorMessage: false
    })
  },
  wecomDeliveries(environmentId: number, bindingId: number, page = 1, limit = 20) {
    return request.get<SandAiPage<ChannelDeliveryRow>>({
      url: `${base}/wecom-deliveries`,
      params: { environment_id: environmentId, channel_binding_id: bindingId, page, limit },
      showErrorMessage: false
    })
  },
  feishuStatus(environmentId: number, bindingId: number) {
    return request.get<FeishuChannelStatus>({
      url: `${base}/feishu-status`,
      params: { environment_id: environmentId, channel_binding_id: bindingId },
      showErrorMessage: false
    })
  },
  wecomStatus(environmentId: number, bindingId: number) {
    return request.get<WecomChannelStatus>({
      url: `${base}/wecom-status`,
      params: { environment_id: environmentId, channel_binding_id: bindingId },
      showErrorMessage: false
    })
  },
  retryDeliveryByRequestKey(environmentId: number, bindingId: number, taskId: number, key: string) {
    return request.get<ChannelDeliveryRetryRecovery>({
      url: `${base}/deliveries/retry/by-request-key`,
      params: { environment_id: environmentId, channel_binding_id: bindingId, task_id: taskId, request_key: key },
      showErrorMessage: false
    })
  },
  retryWecomDeliveryByRequestKey(environmentId: number, bindingId: number, taskId: number, key: string) {
    return request.get<WecomDeliveryRetryRecovery>({
      url: `${base}/wecom-deliveries/retry/by-request-key`,
      params: { environment_id: environmentId, channel_binding_id: bindingId, task_id: taskId, request_key: key },
      showErrorMessage: false
    })
  },
  retryDelivery(environmentId: number, bindingId: number, taskId: number, key: string) {
    return request.post<ChannelDeliveryRetryReceipt>({
      url: `${base}/deliveries/retry`,
      data: { environment_id: environmentId, channel_binding_id: bindingId, task_id: taskId },
      headers: { 'Idempotency-Key': key },
      showErrorMessage: false
    })
  },
  retryWecomDelivery(environmentId: number, bindingId: number, taskId: number, key: string) {
    return request.post<WecomDeliveryRetryReceipt>({
      url: `${base}/wecom-deliveries/retry`,
      data: { environment_id: environmentId, channel_binding_id: bindingId, task_id: taskId },
      headers: { 'Idempotency-Key': key },
      showErrorMessage: false
    })
  }
}

export interface ChannelTokenEvent {
  readonly task_id: number
  readonly event_no: number
  readonly round: number
  readonly text: string
  readonly provisional: true
  readonly source_block_ids: readonly number[]
}

/** Reads a native-token ledger; reconnecting never submits a task or restarts inference. */
export async function readChannelTokenStream(options: {
  apiKey: string
  code: string
  taskId: number
  conversationId: string
  senderId: string
  deliveryPlatform?: ChannelDeliveryPlatform
  afterEventId: number
  signal: AbortSignal
  onToken: (event: ChannelTokenEvent) => void
  onTaskState: (state: string) => void
}): Promise<number> {
  const record = (value: unknown): value is Record<string, unknown> => typeof value === 'object' && value !== null && !Array.isArray(value)
  const params = new URLSearchParams({ conversation_id: options.conversationId, sender_id: options.senderId, after_event_id: String(options.afterEventId) })
  if (options.deliveryPlatform) params.set('delivery_platform', options.deliveryPlatform)
  const base = (import.meta.env.VITE_API_URL || '').replace(/\/$/, '')
  const response = await fetch(`${base}${RUNTIME_API_PREFIX}/channels/${encodeURIComponent(options.code)}/messages/${options.taskId}/events?${params}`, {
    headers: { Authorization: `Bearer ${options.apiKey.trim()}`, Accept: 'text/event-stream', 'Cache-Control': 'no-store' },
    signal: options.signal,
    cache: 'no-store'
  })
  if (!response.ok || !response.headers.get('content-type')?.startsWith('text/event-stream')) {
    let code = 'SAND_AI_AGENT_STREAM_UNAVAILABLE'
    try {
      const body: unknown = await response.json()
      if (record(body) && record(body.data) && typeof body.data.error_code === 'string') code = body.data.error_code
    } catch { /* Error bodies are never displayed as private/provider text. */ }
    throw new AgentReplayError(code, response.status)
  }
  const reader = response.body?.getReader()
  if (!reader) throw new Error('浏览器无法读取回复流，请使用原任务找回完整结果。')
  const decoder = new TextDecoder('utf-8', { fatal: true })
  let buffer = ''
  let lastEvent = options.afterEventId
  function consume(frame: string): void {
    let kind = ''
    let id = ''
    const data: string[] = []
    for (const line of frame.split('\n')) {
      if (line.startsWith('event:')) kind = line.slice(6).trim()
      if (line.startsWith('id:')) id = line.slice(3).trim()
      if (line.startsWith('data:')) data.push(line.slice(5).trimStart())
    }
    if (!data.length) return
    const value: unknown = JSON.parse(data.join('\n'))
    if (!record(value)) throw new Error('回复流格式无效；请沿原任务读取结果。')
    if (kind === 'error') {
      throw new AgentReplayError(typeof value.error_code === 'string' ? value.error_code : 'SAND_AI_AGENT_STREAM_UNAVAILABLE')
    }
    if (kind === 'task') {
      if (!record(value.task) || value.task.id !== options.taskId || typeof value.task.state !== 'string') throw new Error('回复流任务标识无效。')
      options.onTaskState(value.task.state)
    }
    if (kind !== 'token') return
    if (value.task_id !== options.taskId || typeof value.event_no !== 'number' || !Number.isSafeInteger(value.event_no)
      || value.event_no < 1 || value.event_no > 4096 || id !== String(value.event_no)
      || typeof value.round !== 'number' || !Number.isInteger(value.round) || value.round < 1 || value.round > 4
      || typeof value.text !== 'string' || value.text.length === 0 || value.text.length > 8192
      || value.provisional !== true || !Array.isArray(value.source_block_ids)
      || value.source_block_ids.length > 64 || !value.source_block_ids.every((item: unknown) => typeof item === 'number' && Number.isSafeInteger(item) && item > 0)) {
      throw new Error('回复流内容格式无效，请沿原任务读取结果。')
    }
    if (value.event_no <= lastEvent) return
    if (value.event_no !== lastEvent + 1) throw new Error('回复流序号不连续，请沿原任务续接。')
    options.onToken({ task_id: options.taskId, event_no: value.event_no, round: value.round, text: value.text, provisional: true,
      source_block_ids: value.source_block_ids })
    lastEvent = value.event_no
  }
  try {
    while (true) {
      const { done, value } = await reader.read()
      buffer += decoder.decode(value, { stream: !done })
      buffer = buffer.replace(/\r\n/g, '\n')
      let end = buffer.indexOf('\n\n')
      while (end >= 0) {
        consume(buffer.slice(0, end))
        buffer = buffer.slice(end + 2)
        end = buffer.indexOf('\n\n')
      }
      if (buffer.length > 131072) throw new Error('回复流超出浏览器读取上限；请沿原任务读取结果。')
      if (done) break
    }
    if (buffer.trim()) throw new Error('回复流中断，请沿原任务续接。')
    return lastEvent
  } finally {
    await reader.cancel().catch(() => undefined)
    reader.releaseLock()
  }
}
