<template>
  <ElCard shadow="never" class="rerank-setup">
    <template #header><h4>专业重排模型部署</h4></template>
    <p class="muted">用于知识检索候选排序。保存不会调用服务；验证会发送固定的非敏感示例并可能产生费用，记录在平台验证用量。软预算的未定费用调用遵循已配置策略，返回真实用量后结算；硬预算无法证明费用上限时会拒绝发送。</p>
    <ElAlert v-if="message" :title="message" :type="messageType" :closable="false" class="notice" />
    <ElAlert v-if="saveRecoveryKey" type="warning" :closable="false" :title="`原保存请求 ${saveRecoveryKey} 结果尚未确认。凭据已清除，请只读找回原保存结果；此处不会创建新草稿。`" />
    <p v-if="publicationIntent" class="muted">原发布目标：部署 {{ publicationIntent.deployment_id }} · 修订 {{ publicationIntent.revision }}</p>
    <p v-if="intent" class="muted">原验证请求号：{{ intent.key }} · 部署 {{ intent.deployment_id }} · 修订 {{ intent.revision }}</p>
    <ElAlert v-if="recoveryBlocked" type="warning" :closable="false" :title="publicationNeedsRead ? '原发布结果尚未确认。只读找回原部署与修订；不会重新发布。' : saveRecoveryKey ? '原保存结果尚未确认。凭据已清除，只读找回原请求；不会重新保存。' : '原验证结果尚未确认。保留原号，只读找回；不会重新发送验证。'" />
    <ElButton v-permission="'sand_ai:setup:deployment:read'" v-if="recoveryBlocked" :loading="busy === 'read'" :disabled="Boolean(busy)" @click="restore">{{ publicationNeedsRead ? '找回原发布状态' : saveRecoveryKey ? '找回原保存结果' : '找回原验证状态' }}</ElButton>
    <p class="muted">保存、验证与发布需对应后台权限；缺少操作按钮时，请联系管理员配置权限。此处不会自动开通服务或授权。</p>
    <ElForm label-width="110px" @submit.prevent>
      <ElFormItem label="专业重排服务"><ElSelect v-model="driver" :disabled="locked"><ElOption label="Cohere 专业重排" value="cohere_rerank" /><ElOption label="Jina 专业重排" value="jina_rerank" /></ElSelect></ElFormItem>
      <ElFormItem label="服务商名称"><ElInput v-model.trim="providerName" maxlength="160" :disabled="locked" /></ElFormItem>
      <ElFormItem label="服务地址"><ElInput v-model.trim="baseUrl" :disabled="locked" placeholder="https://服务商提供的 API 基础地址" /></ElFormItem>
      <ElFormItem label="API Key"><ElInput v-model="apiKey" type="password" show-password autocomplete="off" :disabled="locked" placeholder="仅用于保存服务商连接" /></ElFormItem>
      <ElFormItem label="远端模型"><ElInput v-model.trim="remoteModel" maxlength="128" :disabled="locked" placeholder="服务商提供的专业重排模型标识" /></ElFormItem>
      <ElFormItem v-if="!draft"><ElButton v-permission="'sand_ai:setup:deployment:save'" type="primary" :loading="busy === 'save'" :disabled="Boolean(busy) || recoveryBlocked || !canSave" @click="save">保存专业重排部署草稿</ElButton></ElFormItem>
      <template v-if="draft">
        <p>部署 {{ draft.deployment_id }} · 修订 {{ draft.revision }} · {{ stateLabel }}</p>
        <ElFormItem label="处理位置"><ElSelect v-model="processingLocation" :disabled="Boolean(busy) || attempted || recoveryBlocked" placeholder="按服务商实际处理位置确认"><ElOption label="本地运行" value="local_runtime" /><ElOption label="私有网络" value="private_network" /><ElOption label="公共云" value="public_cloud" /></ElSelect></ElFormItem>
        <p v-if="state?.failure" class="muted">{{ state.failure.message }}</p>
        <ElSpace wrap>
          <ElButton v-permission="'sand_ai:setup:deployment:verify'" :loading="busy === 'verify'" :disabled="Boolean(busy) || recoveryBlocked || attempted || !processingLocation || state?.state !== 'draft' || Boolean(state?.failure)" @click="verify">验证专业重排连接</ElButton>
          <ElButton v-permission="'sand_ai:setup:deployment:read'" :loading="busy === 'read'" :disabled="Boolean(busy)" @click="read">读取配置与修订状态</ElButton>
          <ElButton v-permission="'sand_ai:onboarding:publish'" type="primary" :loading="busy === 'publish'" :disabled="Boolean(busy) || recoveryBlocked || publicationNeedsRead || state?.state !== 'tested' || !state.validation_evidence" @click="publish">发布专业重排部署</ElButton>
        </ElSpace>
        <p v-if="state?.validation_evidence" class="muted">本次验证计量：{{ state.validation_evidence.rerank_quantity ?? '服务商未提供' }} {{ state.validation_evidence.rerank_meter_code === 'rerank_search_units' ? '搜索单位' : '重排 Token' }}；Token 拆分不适用于专业重排。</p>
        <p v-if="state?.state === 'tested'" class="muted">仅证明一次专业重排响应与来源映射有效；排序质量、实际业务与服务商账单仍需验收。</p>
        <p v-if="publicationNeedsRead" class="muted">发布结果未确认，请读取原部署状态；读取不会重新发布。</p>
        <template v-if="draft.state === 'published' && state?.state === 'published' && !recoveryBlocked">
          <p>此部署已发布。请在目标环境选择“专业重排”能力路由并发布能力档，再到应用知识检索显式选择专业重排。SandIAM 授权需单独配置。</p>
          <ElButton @click="router.push('/sand-ai/capability')">配置专业重排能力路由</ElButton>
          <ElButton @click="router.push('/sand-ai/knowledge')">进入知识管理</ElButton>
        </template>
        <ElButton v-if="!recoveryBlocked && attempted && (state?.state === 'published' || (state?.state === 'draft' && state.failure?.outcome_uncertain === false))" :disabled="Boolean(busy)" @click="newDraft">新建专业重排部署草稿</ElButton>
      </template>
    </ElForm>
    <RerankPricingPanel v-if="draft && !recoveryBlocked" :key="`${draft.provider.id}:${draft.model.id}`" :provider-id="draft.provider.id" :model-id="draft.model.id" :driver="draft.provider.adapter" />
  </ElCard>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useUserStore } from '@/store/modules/user'
import { HttpError } from '@/utils/http/error'
import api, { type RerankDraft as DraftSave, type RerankDeployment, type RerankVerification as Verification } from '../api/rerankConfiguration'
import RerankPricingPanel from './RerankPricingPanel.vue'
const router = useRouter()
const userStore = useUserStore()
const driver = ref<'cohere_rerank' | 'jina_rerank'>('cohere_rerank')
const providerName = ref('')
const baseUrl = ref('')
const apiKey = ref('')
const remoteModel = ref('')
const processingLocation = ref('')
const draft = ref<DraftSave | null>(null)
const state = ref<Verification | null>(null)
const saveAttempt = ref<{ key: string } | null>(null)
const saveFlight = ref<number | null>(null)
const savePayloads = new Map<number, RerankDeployment>()
let saveSequence = 0
type PublicationIntent = { admin_id: number; deployment_id: number; revision: number }
const publicationIntent = ref<PublicationIntent | null>(null)
// This lock belongs to the dispatched POST, independent of identity epochs and GETs.
const publicationFlight = ref(false)
const verifyFlight = ref<symbol | null>(null)
type Intent = { admin_id: number; deployment_id: number; revision: number; key: string }
const intent = ref<Intent | null>(null)
const pending = ref(false)
const saveRecoveryKey = ref('')
const expectedSave = ref<{ driver: 'cohere_rerank' | 'jina_rerank'; remote_model_sha256: string; config_sha256: string } | null>(null)
const storageError = ref(false)
const attempted = ref(false)
const publicationNeedsRead = ref(false)
const busy = ref<'' | 'save' | 'verify' | 'read' | 'publish'>('')
const message = ref('')
const messageType = ref<'success' | 'warning' | 'error'>('warning')
let generation = 0
let active = true
const labels = { draft: '草稿', verifying: '验证中', tested: '已验证', verification_ambiguous: '验证结果未确认', published: '已发布' }
const stateLabel = computed(() => state.value ? labels[state.value.state] : '草稿')
const recoveryBlocked = computed(() => pending.value || storageError.value || Boolean(saveRecoveryKey.value) || publicationNeedsRead.value || publicationFlight.value || verifyFlight.value !== null || saveFlight.value !== null)
const locked = computed(() => Boolean(busy.value || saveAttempt.value || draft.value || recoveryBlocked.value))
const canSave = computed(() => Boolean(providerName.value && apiKey.value && validUrl(baseUrl.value) && /^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}$/.test(remoteModel.value)))
function validUrl(value: string): boolean {
  try { const url = new URL(value); return url.protocol === 'https:' && Boolean(url.hostname) && !url.username && !url.password && !url.search && !url.hash } catch { return false }
}
function notice(text: string, type: 'success' | 'warning' | 'error' = 'warning'): void { message.value = text; messageType.value = type }
function clearDenied(error: unknown): void {
  if (!(error instanceof HttpError) || ![401, 403].includes(error.code)) return
  if (saveAttempt.value) saveRecoveryKey.value = saveAttempt.value.key
  apiKey.value = ''; providerName.value = ''; baseUrl.value = ''; remoteModel.value = ''; saveAttempt.value = null; draft.value = null; state.value = null
}
function unsubmittedInput(error: unknown): boolean {
  if (!(error instanceof HttpError) || error.code !== 400) return false
  const value: unknown = error.data
  if (typeof value !== 'object' || value === null || Array.isArray(value)) return false
  return Reflect.get(value, 'submission_state') === 'not_submitted' && Reflect.get(value, 'retryable') === false
    && ['SAND_AI_VALIDATION_ERROR', 'SAND_AI_ONBOARDING_CONNECTION_INVALID', 'SAND_AI_ONBOARDING_HEADER_INVALID', 'SAND_AI_ONBOARDING_MODEL_INVALID'].includes(String(Reflect.get(value, 'error_code')))
}
function namespace(): string {
  const id = Number(userStore.info.id)
  if (!userStore.accessToken || !Number.isSafeInteger(id) || id < 1) throw new Error('Administrator unavailable')
  return `sand-ai:onboarding:rerank-verification:${id}`
}
function publicationNamespace(): string { return `${namespace()}:publication` }
function storedPublication(): PublicationIntent | null {
  const raw = sessionStorage.getItem(publicationNamespace())
  if (!raw) return null
  const value: unknown = JSON.parse(raw)
  if (typeof value !== 'object' || value === null || Array.isArray(value)) throw new Error('Original publication unavailable')
  const item = value as Record<string, unknown>
  if (item.admin_id !== Number(userStore.info.id) || !Number.isSafeInteger(item.deployment_id) || Number(item.deployment_id) < 1 || !Number.isSafeInteger(item.revision) || Number(item.revision) < 1) throw new Error('Original publication unavailable')
  return { admin_id: Number(item.admin_id), deployment_id: Number(item.deployment_id), revision: Number(item.revision) }
}
function samePublication(left: PublicationIntent, right: PublicationIntent): boolean {
  return left.admin_id === right.admin_id && left.deployment_id === right.deployment_id && left.revision === right.revision
}
function resolvePublication(original: PublicationIntent): void {
  const saved = storedPublication()
  if (!saved || !samePublication(saved, original)) throw new Error('Original publication changed')
  sessionStorage.removeItem(publicationNamespace())
  publicationNeedsRead.value = false; publicationIntent.value = null
}
function saveNamespace(): string { return `${namespace()}:save` }
async function digest(value: unknown): Promise<string> {
  const bytes = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(JSON.stringify(value)))
  return Array.from(new Uint8Array(bytes), byte => byte.toString(16).padStart(2, '0')).join('')
}
function restoreSaveMarker(): void {
  const raw = sessionStorage.getItem(saveNamespace())
  if (!raw) return
  const value: unknown = JSON.parse(raw)
  if (typeof value !== 'object' || value === null || Array.isArray(value) || Reflect.get(value, 'admin_id') !== Number(userStore.info.id) || typeof Reflect.get(value, 'key') !== 'string' || !/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i.test(String(Reflect.get(value, 'key')))) throw new Error('Original save unavailable')
  const marker = value as Record<string, unknown>
  if ((marker.driver !== 'cohere_rerank' && marker.driver !== 'jina_rerank') || typeof marker.remote_model_sha256 !== 'string' || !/^[a-f0-9]{64}$/.test(marker.remote_model_sha256) || typeof marker.config_sha256 !== 'string' || !/^[a-f0-9]{64}$/.test(marker.config_sha256)) throw new Error('Original save fingerprint unavailable')
  expectedSave.value = { driver: marker.driver, remote_model_sha256: marker.remote_model_sha256, config_sha256: marker.config_sha256 }
  saveRecoveryKey.value = String(marker.key)
}
function stored(): Intent | null {
  const raw = sessionStorage.getItem(namespace())
  if (!raw) return null
  const value: unknown = JSON.parse(raw)
  if (typeof value !== 'object' || value === null || Array.isArray(value)) throw new Error('Original intent unavailable')
  const item = value as Record<string, unknown>
  if (item.admin_id !== Number(userStore.info.id) || !Number.isSafeInteger(item.deployment_id) || Number(item.deployment_id) < 1 || !Number.isSafeInteger(item.revision) || Number(item.revision) < 1 || typeof item.key !== 'string' || !/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i.test(item.key)) throw new Error('Original intent unavailable')
  return { admin_id: Number(item.admin_id), deployment_id: Number(item.deployment_id), revision: Number(item.revision), key: item.key }
}
function resolve(original: Intent): void {
  const saved = stored()
  if (!saved || saved.key !== original.key || saved.deployment_id !== original.deployment_id || saved.revision !== original.revision) throw new Error('Original intent changed')
  sessionStorage.removeItem(namespace()); pending.value = false
}
function terminalOriginal(value: Verification): boolean {
  if (value.state === 'tested' && value.validation_evidence) return true
  if (value.state === 'draft' && value.failure?.error_code === 'SAND_AI_PROVIDER_REJECTED' && value.failure.outcome_uncertain === false && value.failure.recovery_action === 'reconfigure_connection') return true
  const usage: unknown = Reflect.get(value, 'platform_usage')
  return value.state === 'draft' && typeof usage === 'object' && usage !== null && !Array.isArray(usage)
    && Reflect.get(usage, 'scope_kind') === 'platform_admin' && Number.isSafeInteger(Reflect.get(usage, 'attempt_id')) && Number(Reflect.get(usage, 'attempt_id')) > 0 && Reflect.get(usage, 'state') === 'not_dispatched'
}
async function exact(target: Pick<DraftSave, 'deployment_id' | 'revision'>, current: number): Promise<{ detail: DraftSave; verification: Verification } | undefined> {
  if (!active || current !== generation || !userStore.accessToken) return
  const original = pending.value ? intent.value : null
  const publication = publicationIntent.value
  if (publication && (publication.admin_id !== Number(userStore.info.id) || publication.deployment_id !== target.deployment_id || publication.revision !== target.revision)) throw new Error('Original publication target differs')
  if (original && (original.admin_id !== Number(userStore.info.id) || original.deployment_id !== target.deployment_id || original.revision !== target.revision)) throw new Error('Original target differs')
  const [detail, verification] = await Promise.all([api.readDraft(target.deployment_id, target.revision), api.readVerification(target.deployment_id, target.revision, original?.key)])
  if (current !== generation) return
  if (detail.deployment_id !== target.deployment_id || detail.revision !== target.revision || verification.deployment_id !== target.deployment_id || verification.revision !== target.revision || !['cohere_rerank', 'jina_rerank'].includes(detail.provider.adapter) || detail.model.type !== 'rerank' || !detail.model.capabilities.includes('rerank') || !Object.hasOwn(labels, verification.state)) throw new Error('Original revision differs')
  if (verification.validation_evidence && verification.validation_evidence.rerank_meter_code !== (detail.provider.adapter === 'cohere_rerank' ? 'rerank_search_units' : 'rerank_tokens')) throw new Error('Original meter differs')
  let confirmedVerification: Verification = verification
  draft.value = detail; state.value = verification
  if (original && terminalOriginal(verification)) {
    resolve(original); storageError.value = false
    if (detail.state === 'published' && verification.state === 'tested' && verification.validation_evidence) {
      confirmedVerification = { ...verification, state: 'published' }; state.value = confirmedVerification
    }
  }
  if (publication && detail.state === 'published' && verification.validation_evidence && ['tested', 'published'].includes(verification.state)) {
    confirmedVerification = { ...verification, state: 'published' }
    state.value = confirmedVerification
    resolvePublication(publication); storageError.value = false
  }
  // Draft, tested, missing and denied reads never authorize a second publication POST.
  return { detail, verification: confirmedVerification }
}
async function restore(): Promise<void> {
  if (!active || !userStore.accessToken || busy.value || publicationFlight.value || verifyFlight.value !== null || saveFlight.value !== null) return
  const current = generation
  try {
    const publication = storedPublication()
    if (publication) {
      publicationIntent.value = publication; publicationNeedsRead.value = true; attempted.value = true; busy.value = 'read'
      await exact(publication, current)
      if (current === generation) notice(publicationNeedsRead.value ? '原发布结果尚未确认，请继续只读找回原部署与修订；不会重新发布。' : '原部署与修订已确认发布，请继续配置目标环境的能力路由。', publicationNeedsRead.value ? 'warning' : 'success')
      return
    }
    if (publicationNeedsRead.value) throw new Error('Original publication disappeared')
    restoreSaveMarker()
    if (saveRecoveryKey.value) {
      const key = saveRecoveryKey.value; busy.value = 'read'
      const saved = await api.readSavedDraftByKey(key)
      if (current !== generation || saveRecoveryKey.value !== key) return
      if (!Number.isSafeInteger(saved.deployment_id) || saved.deployment_id < 1 || !Number.isSafeInteger(saved.revision) || saved.revision < 1 || saved.idempotent_replay !== true || saved.model.type !== 'rerank' || !saved.model.capabilities.includes('rerank') || typeof saved.deployment.config !== 'object' || saved.deployment.config === null || Array.isArray(saved.deployment.config) || Object.keys(saved.deployment.config).length !== 0 || !Object.hasOwn(labels, saved.state)) throw new Error('Original save differs')
      const expected = expectedSave.value
      if (!expected || saved.provider.adapter !== expected.driver || await digest(saved.deployment.remote_model) !== expected.remote_model_sha256) throw new Error('Original model or driver differs')
      if (current !== generation || saveRecoveryKey.value !== key) return
      await exact(saved, current)
      if (current !== generation || saveRecoveryKey.value !== key) return
      sessionStorage.removeItem(saveNamespace()); saveRecoveryKey.value = ''; expectedSave.value = null; saveAttempt.value = null; storageError.value = false
      attempted.value = state.value?.state !== 'draft' || Boolean(state.value?.failure)
      notice('原请求的已提交保存记录与固定修订已找回；当前验证或发布状态另行读取。请明确选择后续操作，不会自动调用。', 'success')
      return
    }
    const original = stored()
    if (!original) { if (pending.value) throw new Error('Original intent disappeared'); storageError.value = false; return }
    intent.value = original; pending.value = true; attempted.value = true; storageError.value = false; busy.value = 'read'
    await exact(original, current)
    if (current === generation) notice(pending.value ? '原验证仍未确认，请保留原号继续只读核查。' : '原验证已读回，可按当前修订状态继续。', pending.value ? 'warning' : 'success')
  } catch (error) { if (current === generation) { clearDenied(error); storageError.value = true; notice(publicationNeedsRead.value ? '原发布记录读取失败或尚无回执。保留原部署与修订，只读找回，不会重新发布。' : '原记录读取失败或尚无回执。保留原号，不会换号发送。') } }
  finally { if (current === generation) busy.value = '' }
}
async function save(): Promise<void> {
  if (!active || !userStore.accessToken || busy.value || saveFlight.value !== null || draft.value || recoveryBlocked.value || !canSave.value) return
  try {
    restoreSaveMarker()
    if (saveRecoveryKey.value) { await restore(); return }
  } catch { storageError.value = true; notice('原保存记录无法确认，请先只读找回；不会发送新请求。'); return }
  const current = generation; const owner = Number(userStore.info.id); const storeKey = saveNamespace()
  const key = crypto.randomUUID(); const flight = ++saveSequence
  const suffix = crypto.randomUUID().replaceAll('-', '').slice(0, 12)
  saveAttempt.value = { key }; saveFlight.value = flight; busy.value = 'save'
  let dispatched = false
  try {
    // This request-local map is cleared on identity changes and before transport handoff.
    savePayloads.set(flight, { provider: { code: `rerank_${suffix}`, name: providerName.value, adapter: driver.value, timeout_ms: 30000 }, model: { code: `rerank_${suffix}`, name: remoteModel.value, type: 'rerank', capabilities: ['rerank'] }, connection: { base_url: baseUrl.value, api_key: apiKey.value }, remote_model: remoteModel.value, config: {} })
    const originalDriver = driver.value
    const [remoteHash, configHash] = await Promise.all([
      digest(remoteModel.value),
      digest({ provider: savePayloads.get(flight)?.provider, base_url: baseUrl.value, remote_model: remoteModel.value, config: {} })
    ])
    if (!active || current !== generation || saveFlight.value !== flight || saveAttempt.value?.key !== key) return
    if (sessionStorage.getItem(storeKey)) throw new Error('Original save already exists')
    sessionStorage.setItem(storeKey, JSON.stringify({ admin_id: owner, key, driver: originalDriver, remote_model_sha256: remoteHash, config_sha256: configHash }))
    // The durable lock contains no credential or request body. Unknown outcomes only read this key.
    expectedSave.value = { driver: originalDriver, remote_model_sha256: remoteHash, config_sha256: configHash }
    saveRecoveryKey.value = key
    let payload = savePayloads.get(flight)
    savePayloads.delete(flight)
    if (!payload) throw new Error('Original connection unavailable')
    apiKey.value = ''; saveAttempt.value = null
    dispatched = true
    const response = api.save(payload, key)
    payload = undefined
    const saved = await response
    if (!active || current !== generation || saveRecoveryKey.value !== key) return
    if (saved.model.type !== 'rerank' || !saved.model.capabilities.includes('rerank') || saved.provider.adapter !== originalDriver || await digest(saved.deployment.remote_model) !== remoteHash) throw new Error('Deployment differs')
    if (!active || current !== generation || saveRecoveryKey.value !== key) return
    sessionStorage.removeItem(storeKey); saveRecoveryKey.value = ''; expectedSave.value = null
    draft.value = saved; state.value = { deployment_id: saved.deployment_id, revision: saved.revision, state: saved.state, validation_evidence: null }
    notice('专业重排部署草稿已保存，请确认处理位置后验证。', 'success')
  } catch (error) { if (active && current === generation) {
    apiKey.value = ''; saveAttempt.value = null
    clearDenied(error)
    if (dispatched && unsubmittedInput(error)) {
      sessionStorage.removeItem(storeKey); saveRecoveryKey.value = ''; expectedSave.value = null
      notice('输入已被明确拒绝且尚未提交。请修正服务地址、模型或连接信息后保存。', 'error')
    } else {
      if (dispatched) saveRecoveryKey.value = key
      else storageError.value = true
      notice(dispatched ? '原保存结果未确认，凭据已清除。请保留原请求号只读找回，不会重新保存。' : '无法保留原保存意图，本次尚未发送。请先恢复会话记录再填写连接信息。')
    }
  } }
  finally {
    savePayloads.delete(flight)
    if (saveFlight.value === flight) {
      saveFlight.value = null
      if (current === generation) { busy.value = ''; saveAttempt.value = null }
      else { busy.value = publicationFlight.value ? 'publish' : verifyFlight.value !== null ? 'verify' : ''; if (active) void restore() }
    }
  }
}
async function read(): Promise<void> {
  if (recoveryBlocked.value) { await restore(); return }
  if (!draft.value || busy.value) return
  const target = draft.value; const current = generation; busy.value = 'read'
  try { await exact(target, current); if (current === generation) notice('原配置与修订状态已读回。', 'success') }
  catch (error) { if (current === generation) { clearDenied(error); notice('配置与状态读取未确认，请保留原部署和修订号。') } }
  finally { if (current === generation) busy.value = '' }
}
async function verify(): Promise<void> {
  if (!draft.value || busy.value || recoveryBlocked.value || attempted.value || !processingLocation.value || state.value?.state !== 'draft' || state.value.failure) return
  const target = draft.value; const current = generation
  try {
    if (stored()) { await restore(); return }
  } catch { storageError.value = true; notice('原验证记录无法读取，请保留原号只读找回；不会重新发送。'); return }
  const flight = Symbol('rerank-verification')
  verifyFlight.value = flight
  let original: Intent
  try {
    original = { admin_id: Number(userStore.info.id), deployment_id: target.deployment_id, revision: target.revision, key: crypto.randomUUID() }
    sessionStorage.setItem(namespace(), JSON.stringify(original)); const saved = stored()
    if (!saved || saved.key !== original.key) throw new Error('Original intent unavailable')
    intent.value = original; pending.value = true
  } catch {
    if (verifyFlight.value === flight) verifyFlight.value = null
    storageError.value = true; notice('无法保留验证请求号，本次尚未发送。恢复会话记录后再继续。'); return
  }
  attempted.value = true; busy.value = 'verify'
  try {
    const result = await api.verify(target.deployment_id, target.revision, processingLocation.value, original.key, 150000)
    if (current !== generation) return
    if (result.deployment_id !== target.deployment_id || result.revision !== target.revision || result.state !== 'tested' || !result.validation_evidence) throw new Error('Original result differs')
    state.value = result; resolve(original); await exact(target, current)
    if (current !== generation) return
    notice('固定样本的专业重排向量结构验证通过，可发布此修订。', 'success')
  } catch (error) { if (current === generation) { clearDenied(error); if (error instanceof HttpError && [401, 403].includes(error.code)) { notice('当前权限拒绝读取，旧结果和凭据已清除；原请求号保留，不会重发。'); return } try { await exact(target, current) } catch (readError) { clearDenied(readError) /* Keep original intent locked. */ } notice(pending.value ? '原验证结果未确认，请用原号只读找回；不会重发。' : '原状态已读回，请按当前修订状态继续。') } }
  finally {
    if (verifyFlight.value === flight) {
      verifyFlight.value = null
      if (current === generation) busy.value = ''
      else { busy.value = saveFlight.value !== null ? 'save' : publicationFlight.value ? 'publish' : ''; if (active) void restore() }
    }
  }
}
async function publish(): Promise<void> {
  if (!draft.value || busy.value || recoveryBlocked.value || publicationFlight.value || publicationNeedsRead.value || state.value?.state !== 'tested' || !state.value.validation_evidence) return
  const target = draft.value; const current = generation
  const original: PublicationIntent = { admin_id: Number(userStore.info.id), deployment_id: target.deployment_id, revision: target.revision }
  try {
    if (storedPublication()) { await restore(); return }
    // The journal contains only identity and the exact publication target, never credentials or request bodies.
    sessionStorage.setItem(publicationNamespace(), JSON.stringify(original))
    const saved = storedPublication()
    if (!saved || !samePublication(saved, original)) throw new Error('Original publication unavailable')
    publicationIntent.value = original; publicationNeedsRead.value = true
  } catch {
    storageError.value = true; notice('无法保留原发布目标，本次尚未发送。恢复会话记录后再继续。'); return
  }
  busy.value = 'publish'; publicationFlight.value = true
  try {
    const result = await api.publish(target.deployment_id, target.revision)
    if (current !== generation) return
    if (result.deployment_id !== target.deployment_id || result.state !== 'published') throw new Error('Publication differs')
    const confirmed = await exact(target, current)
    if (current !== generation) return
    if (!confirmed || publicationNeedsRead.value || confirmed.detail.state !== 'published' || confirmed.verification.state !== 'published' || !confirmed.verification.validation_evidence) throw new Error('Publication unconfirmed')
    notice('专业重排部署已发布，请继续配置目标环境的能力路由。', 'success')
  } catch (error) { if (current === generation) { clearDenied(error); publicationNeedsRead.value = true; notice('发布结果未确认，请只读核对原部署与修订状态；不会重新发布。') } }
  finally {
    publicationFlight.value = false
    if (current === generation) busy.value = ''
    else { busy.value = ''; if (active) void restore() }
  }
}
function newDraft(): void {
  if (busy.value || recoveryBlocked.value || !attempted.value || !(state.value?.state === 'published' || (state.value?.state === 'draft' && state.value.failure?.outcome_uncertain === false))) return
  generation += 1; apiKey.value = ''; saveAttempt.value = null; draft.value = null; state.value = null; intent.value = null; attempted.value = false; publicationNeedsRead.value = false; publicationIntent.value = null; processingLocation.value = ''
  notice('已开始新草稿。原部署与验证记录保留在服务端，不会重发原验证。')
}
watch([() => userStore.info.id, () => userStore.accessToken], () => { generation += 1; savePayloads.clear(); apiKey.value = ''; providerName.value = ''; baseUrl.value = ''; remoteModel.value = ''; processingLocation.value = ''; saveAttempt.value = null; draft.value = null; state.value = null; intent.value = null; saveRecoveryKey.value = ''; expectedSave.value = null; pending.value = false; storageError.value = false; attempted.value = false; publicationNeedsRead.value = false; publicationIntent.value = null; message.value = ''; busy.value = saveFlight.value !== null ? 'save' : publicationFlight.value ? 'publish' : verifyFlight.value !== null ? 'verify' : ''; void restore() }, { flush: 'sync' })
onMounted(restore)
onBeforeUnmount(() => { active = false; generation += 1; savePayloads.clear(); apiKey.value = ''; providerName.value = ''; baseUrl.value = ''; remoteModel.value = ''; processingLocation.value = ''; saveAttempt.value = null })
</script>
<style scoped>
.rerank-setup { margin-top: 16px; }
.rerank-setup h4 { margin: 0; }
.rerank-setup .muted { color: var(--el-text-color-secondary); }
.rerank-setup .notice { margin-bottom: 16px; }
</style>
