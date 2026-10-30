<template>
  <h3>更新已发布模型</h3>
  <p class="muted">调整输出上限、推理额度或方法包输出时，另存为后继部署。继续使用已保存的服务商连接和模型，无需再次填写 API Key。</p>
  <ElAlert v-if="postFlight" title="原部署操作仍在提交中。切换身份或环境不会取消已发出的请求；待本次提交结束后，只读找回原结果。" type="info" :closable="false" class="notice" />
  <ElAlert v-if="message" :title="message" :type="messageType" :closable="false" class="notice" />
  <p v-if="verificationIntent" class="muted">原后继验证请求号：{{ verificationIntent.key }} · 部署 {{ verificationIntent.deployment_id }} · 修订 {{ verificationIntent.revision }}。此号与费用明细中的尝试 ID 不同。</p>
  <p v-if="attempt" class="muted">原后继保存请求号：{{ attempt.key }} · 应用 {{ attempt.application_id }} / 环境 {{ attempt.environment_id }} · 原部署 {{ attempt.source_deployment_id }} / 修订 {{ attempt.source_revision }}。</p>
  <ElAlert v-if="savePending || saveStorageError" :closable="false" type="warning" title="原后继保存结果尚未确认或保留本地未提交证明。新保存、验证、另存和发布已锁定；原应用和环境不随当前选择改变。" />
  <ElButton v-if="attempt || saveStorageError" :disabled="Boolean(busy || postFlight)" :loading="busy === 'read'" @click="restoreSave">只读找回原保存</ElButton>
  <ElButton v-if="attempt?.phase === 'not_submitted'" :disabled="Boolean(busy || postFlight) || attempt.application_id !== applicationId || attempt.environment_id !== environmentId" @click="editUnsubmitted">按本地未提交证明修改输入</ElButton>
  <ElAlert v-if="verificationPending || intentStorageError" :closable="false" type="warning" title="原后继验证结果尚未确认。新保存、验证、另存和发布已锁定；只能用原号只读找回，不会换号重发。" />
  <ElButton v-if="verificationPending || intentStorageError" :disabled="Boolean(busy || postFlight)" :loading="busy === 'read'" @click="restoreIntent">找回原后继验证状态</ElButton>
  <div v-if="completedSave" class="muted">
    <p>上次已结束记录：应用 {{ completedSave.application_id }} / 环境 {{ completedSave.environment_id }} · 后继部署 {{ completedSave.deployment_id }} / 修订 {{ completedSave.revision }} · 原请求号 {{ completedSave.key }}。</p>
    <ElButton :disabled="Boolean(busy || postFlight)" :loading="busy === 'read'" @click="readCompletedUpdate">只读回看上次已结束记录</ElButton>
    <p v-if="completedDraft">已读回固定修订：{{ completedDraft.provider.name }} / {{ completedDraft.model.name }} · {{ labels[completedDraft.state] }}。此回看不会选择或提交新目标。</p>
  </div>
  <ElForm label-width="120px" @submit.prevent>
    <ElFormItem label="原模型部署">
      <ElSelect v-model="selectedId" filterable remote :remote-method="loadOptions" :loading="busy === 'list'"
        :disabled="Boolean(busy || postFlight || attempt || saved || recoveryBlocked)" placeholder="输入模型或服务商名称查找" @change="readSource">
        <ElOption v-for="item in options" :key="item.id" :value="item.id" :label="`${item.display_name} · 部署 ${item.id}`" />
      </ElSelect>
      <ElButton :disabled="Boolean(busy || postFlight || attempt || saved || recoveryBlocked)" @click="loadOptions('')">读取已发布模型</ElButton>
    </ElFormItem>
    <template v-if="source">
      <p class="muted">原部署 {{ source.deployment_id }} · 修订 {{ source.revision }}；{{ source.provider.name }} / {{ source.model.name }}</p>
      <p class="muted">原修订方法包输出：{{ sourceStrategyLabel }}。原保存值保持不变。</p>
      <ElFormItem label="方法包输出">
        <ElSelect v-model="skillOutputStrategy" :disabled="locked" aria-label="后继部署方法包输出">
          <ElOption label="标准输出（默认）" value="neuron_json_extract_v1" />
          <ElOption label="JSON 对象（需模型支持）" value="neuron_zai_json_object_v1" />
          <ElOption label="JSON Schema（需模型支持）" value="neuron_openai_json_schema_v1" />
        </ElSelect>
        <span class="muted">仅影响新方法包版本。选择 JSON 对象或 JSON Schema 前确认模型支持对应格式；已有方法包快照不改写。</span>
      </ElFormItem>
      <ElFormItem label="输出上限">
        <ElInputNumber v-model="maxOutputTokens" :min="1" :max="131072" :step="1" :precision="0" :disabled="locked" />
      </ElFormItem>
      <ElFormItem label="推理额度">
        <ElSelect v-model="reasoningEffort" :disabled="locked" aria-label="后继部署推理额度">
          <ElOption label="由模型服务决定（默认）" value="" />
          <ElOption label="低" value="low" /><ElOption label="高" value="high" /><ElOption label="最大" value="max" />
        </ElSelect>
        <span class="muted">仅选择该模型明确支持的额度；默认不指定。保存后配置锁定，须重新验证、发布。</span>
      </ElFormItem>
      <ElFormItem>
        <ElButton type="primary" :loading="busy === 'save'" :disabled="Boolean(busy || postFlight || saved || recoveryBlocked) || (!attempt && !validOutput) || !applicationId || !environmentId"
          @click="save">{{ attempt ? '只读找回原保存' : '另存后继部署' }}</ElButton>
        <span v-if="!applicationId || !environmentId" class="muted">先在上方选择应用和环境，原保存请求将固定到该工作目标。</span>
      </ElFormItem>
    </template>
    <template v-if="saved">
      <p>后继部署 {{ saved.deployment_id }} · 修订 {{ saved.revision }} · {{ stateLabel }}</p>
      <p class="muted">已读回此修订的输出上限 {{ saved.deployment.config.max_output_tokens }}，推理额度 {{ savedEffortLabel }}，方法包输出 {{ savedStrategyLabel }}。</p>
      <ElFormItem label="处理位置">
        <ElSelect v-model="processingLocation" :disabled="Boolean(busy || postFlight) || recoveryBlocked || state?.state !== 'draft'">
          <ElOption label="本地运行" value="local_runtime" /><ElOption label="私有网络" value="private_network" />
          <ElOption label="公共云" value="public_cloud" />
        </ElSelect>
      </ElFormItem>
      <p class="muted">请按服务商实际处理位置选择。验证会发送小样本，可能产生费用。</p>
      <ElAlert v-if="state?.failure" :title="state.failure.message" :closable="false" type="warning" />
      <ElAlert v-if="publicationConfirmationRequired" title="后继发布结果或当前修订尚未确认，请读取当前修订并核对后明确确认。读取不证明原发布未执行。" :closable="false" type="warning" />
      <ElButton v-if="publicationConfirmationRequired" :disabled="Boolean(busy || postFlight) || recoveryBlocked" :loading="busy === 'read'" @click="readCurrentRevision">读取并确认当前后继修订</ElButton>
      <div v-if="publicationPreview && publicationConfirmationRequired" class="muted">
        <p>部署 {{ publicationPreview.draft.deployment_id }} · 修订 {{ publicationPreview.draft.revision }} · {{ labels[publicationPreview.verification.state] }}；{{ publicationPreview.draft.provider.name }} / {{ publicationPreview.draft.model.name }}（{{ publicationPreview.draft.deployment.remote_model }}）。</p>
        <p>输出上限 {{ publicationPreview.draft.deployment.config.max_output_tokens }}；推理额度 {{ publicationPreview.draft.deployment.config.reasoning_effort || '由模型服务决定' }}；方法包输出 {{ publicationPreview.draft.deployment.config.skill_output_strategy ? strategyLabels[publicationPreview.draft.deployment.config.skill_output_strategy] : '未指定（历史修订保持原策略）' }}。</p>
        <ElButton :disabled="Boolean(busy || postFlight) || recoveryBlocked" @click="confirmCurrentRevision">确认使用此后继修订</ElButton>
      </div>
      <ElSpace wrap>
        <ElButton :loading="busy === 'verify'" :disabled="Boolean(busy || postFlight) || recoveryBlocked || verificationAttempted || !processingLocation || state?.state !== 'draft' || verificationRejected || Boolean(state?.failure)" @click="verify">验证后继部署</ElButton>
        <ElButton :loading="busy === 'read'" :disabled="Boolean(busy || postFlight)" @click="readState">读取后继配置与状态</ElButton>
        <ElButton type="primary" :loading="busy === 'publish'" :disabled="Boolean(busy || postFlight) || recoveryBlocked || publicationConfirmationRequired || state?.state !== 'tested' || !state.validation_evidence" @click="publish">发布后继部署</ElButton>
        <ElButton v-if="state?.state === 'draft' && verificationRejected && !recoveryBlocked" :disabled="Boolean(busy || postFlight)" @click="revise">调整配置并另存</ElButton>
      </ElSpace>
      <p v-if="state?.state === 'verification_ambiguous'" class="muted">验证结果未确认，可能已产生费用；请保留部署与修订号联系管理员，不会重新发送验证。</p>
      <template v-if="state?.state === 'published' && !publicationConfirmationRequired && !recoveryBlocked">
        <p>后继部署已发布。已有能力路线仍使用原部署，请进入能力配置选择后继部署 {{ saved.deployment_id }}，核对其他路线后保存并发布新配置。</p>
        <ElButton type="primary" :disabled="!applicationId || !environmentId || Boolean(attempt && (attempt.application_id !== applicationId || attempt.environment_id !== environmentId))" @click="openCapabilities">配置所选环境的能力路线</ElButton>
        <p v-if="!applicationId || !environmentId" class="muted">先在上方工作目标选择应用和环境。</p>
        <ElButton v-if="canFinishUpdate" :disabled="Boolean(busy || postFlight)" :loading="busy === 'read'" @click="finishUpdate">结束本次已完成记录并开始新的更新</ElButton>
        <p class="muted">结束记录前会重新核对当前已发布修订与验证证明；未知发布结果、不同修订或其他工作目标不能解除原记录。</p>
      </template>
    </template>
  </ElForm>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { ElMessageBox } from 'element-plus'
import { HttpError } from '@/utils/http/error'
import { useUserStore } from '@/store/modules/user'
import { useRouter } from 'vue-router'
import api, { type DraftSave, type PublishedDeployment, type ReasoningEffort, type SkillOutputStrategy, type SuccessorDeployment, type Verification } from '../api/onboarding'

const props = defineProps<{ applicationId: number | null; environmentId: number | null }>()
const router = useRouter()
const userStore = useUserStore()
const options = ref<PublishedDeployment[]>([])
const selectedId = ref<number | null>(null)
const source = ref<DraftSave | null>(null)
const saved = ref<DraftSave | null>(null)
const state = ref<Verification | null>(null)
type SaveProof = { error_code: string; submission_state: 'not_submitted'; retryable: false }
type SaveIntent = {
  version: 1; admin_id: number; application_id: number; environment_id: number
  source_deployment_id: number; source_revision: number; provider_id: number; model_id: number
  key: string; input_sha256: string; phase: 'pending' | 'saved' | 'not_submitted'
  deployment_id?: number; revision?: number; proof?: SaveProof
}
const attempt = ref<SaveIntent | null>(null)
const saveStorageError = ref(false)
const completedSave = ref<SaveIntent | null>(null)
const completedDraft = ref<DraftSave | null>(null)
const savePending = computed(() => Boolean(attempt.value && attempt.value.phase !== 'saved'))
const proofCodes = ['SAND_AI_VALIDATION_ERROR', 'SAND_AI_ONBOARDING_CONNECTION_INVALID', 'SAND_AI_ONBOARDING_HEADER_INVALID', 'SAND_AI_ONBOARDING_MODEL_INVALID']
function saveNamespace(): string {
  const admin = Number(userStore.info.id)
  if (!Number.isSafeInteger(admin) || admin < 1) throw new Error('Administrator unavailable')
  return `sand-ai:onboarding:successor-save:${admin}`
}
function positive(value: unknown): value is number { return typeof value === 'number' && Number.isSafeInteger(value) && value > 0 }
function validProof(value: unknown): value is SaveProof {
  return record(value) && typeof value.error_code === 'string' && proofCodes.includes(value.error_code)
    && value.submission_state === 'not_submitted' && value.retryable === false
}
function storedSave(namespace = saveNamespace()): SaveIntent | null {
  const raw = localStorage.getItem(namespace)
  if (raw === null) return null
  const value: unknown = JSON.parse(raw)
  if (!record(value) || value.version !== 1 || value.admin_id !== Number(userStore.info.id)
    || !positive(value.application_id) || !positive(value.environment_id)
    || !positive(value.source_deployment_id) || !positive(value.source_revision)
    || !positive(value.provider_id) || !positive(value.model_id)
    || typeof value.key !== 'string' || !/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i.test(value.key)
    || typeof value.input_sha256 !== 'string' || !/^[a-f0-9]{64}$/.test(value.input_sha256)
    || !['pending', 'saved', 'not_submitted'].includes(String(value.phase))
    || ((value.deployment_id !== undefined || value.revision !== undefined) && (!positive(value.deployment_id) || !positive(value.revision)))
    || (value.phase === 'saved' && (!positive(value.deployment_id) || !positive(value.revision)))
    || (value.phase === 'not_submitted' && !validProof(value.proof))) throw new Error('Original save record unavailable')
  // Rebuild the allowlisted opaque record; never carry arbitrary stored fields forward.
  return {
    version: 1, admin_id: Number(value.admin_id), application_id: Number(value.application_id), environment_id: Number(value.environment_id),
    source_deployment_id: Number(value.source_deployment_id), source_revision: Number(value.source_revision),
    provider_id: Number(value.provider_id), model_id: Number(value.model_id), key: value.key, input_sha256: value.input_sha256,
    phase: value.phase as SaveIntent['phase'],
    ...(positive(value.deployment_id) && positive(value.revision) ? { deployment_id: value.deployment_id, revision: value.revision } : {}),
    ...(value.phase === 'not_submitted' && validProof(value.proof) ? { proof: { error_code: value.proof.error_code, submission_state: 'not_submitted' as const, retryable: false as const } } : {})
  }
}
function persistSave(value: SaveIntent, replacing?: SaveIntent): void {
  if (value.admin_id !== Number(userStore.info.id)) throw new Error('Original administrator changed')
  const original = storedSave()
  if (replacing ? !original || original.key !== replacing.key || original.input_sha256 !== replacing.input_sha256 : original !== null) throw new Error('Original save changed')
  try {
    localStorage.setItem(saveNamespace(), JSON.stringify(value))
    if (JSON.stringify(storedSave()) !== JSON.stringify(value)) throw new Error('Save record unavailable')
  } catch { saveStorageError.value = true; throw new Error('Save record unavailable') }
  attempt.value = value
}
function clearSave(value: SaveIntent): void {
  const original = storedSave()
  if (value.admin_id !== Number(userStore.info.id) || !original || JSON.stringify(original) !== JSON.stringify(value)) throw new Error('Original save changed')
  localStorage.removeItem(saveNamespace())
  if (storedSave() !== null) throw new Error('Original save not cleared')
  attempt.value = null
}
async function inputDigest(value: SuccessorDeployment): Promise<string> {
  const config = value.config
  const canonical = JSON.stringify([value.provider_id, value.model_id, value.remote_model, config.max_output_tokens,
    config.input_token_ceiling ?? null, config.reasoning_effort ?? null, config.skill_output_strategy ?? null])
  const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(canonical))
  return Array.from(new Uint8Array(digest), byte => byte.toString(16).padStart(2, '0')).join('')
}
async function matchesSaveReceipt(result: DraftSave, pending: SaveIntent, current: number): Promise<boolean> {
  if (current !== generation || pending.admin_id !== Number(userStore.info.id)) return false
  assertChat(result)
  if (!positive(result.deployment_id) || !positive(result.revision) || result.deployment_id === pending.source_deployment_id
    || result.provider.id !== pending.provider_id || result.model.id !== pending.model_id
    || (pending.deployment_id !== undefined && (result.deployment_id !== pending.deployment_id || result.revision !== pending.revision))
    || typeof result.deployment.config.max_output_tokens !== 'number') throw new Error('Original successor differs')
  const digest = await inputDigest({ provider_id: result.provider.id, model_id: result.model.id, remote_model: result.deployment.remote_model,
    config: { ...result.deployment.config, max_output_tokens: result.deployment.config.max_output_tokens } })
  if (current !== generation || pending.admin_id !== Number(userStore.info.id)) return false
  if (digest !== pending.input_sha256) throw new Error('Original successor configuration differs')
  return true
}
async function acceptSaveReceipt(result: DraftSave, pending: SaveIntent, current: number): Promise<boolean> {
  if (!await matchesSaveReceipt(result, pending, current)) return false
  const resolved: SaveIntent = { ...pending, phase: 'saved', deployment_id: result.deployment_id, revision: result.revision }
  delete resolved.proof
  persistSave(resolved, pending)
  saveStorageError.value = false
  // A receipt proves saving only; the exact persisted revision must still be read.
  if (!await readExact(result, current)) return false
  return true
}
async function restoreSave(): Promise<void> {
  if (postFlight.value || busy.value) return
  const current = generation
  busy.value = 'read'
  try {
    const pending = storedSave()
    if (!pending) {
      if (attempt.value) throw new Error('Original save disappeared')
      saveStorageError.value = false
      return
    }
    attempt.value = pending; saveStorageError.value = false
    const result = await api.readSavedDraftByKey(pending.key)
    if (await acceptSaveReceipt(result, pending, current)) notice('原请求保存的后继修订已只读找回。未自动验证或发布。', 'success')
  } catch {
    if (current === generation) {
      if (!attempt.value) saveStorageError.value = true
      notice(attempt.value?.phase === 'not_submitted' && !saveStorageError.value
        ? '未读到服务器保存回执。本机仅保留该原请求真实返回的未提交证明；这不是服务器账本。请明确选择修改输入，才可建立新请求。'
        : '原后继保存未确认或记录不可读。保留原请求与目标；404、超时和普通错误均不证明未提交，只能继续只读找回。')
    }
  } finally { if (current === generation) busy.value = '' }
}
async function editUnsubmitted(): Promise<void> {
  const pending = attempt.value; const current = generation
  if (postFlight.value || postFlight.value || busy.value || !pending || pending.phase !== 'not_submitted' || !validProof(pending.proof)
    || pending.application_id !== props.applicationId || pending.environment_id !== props.environmentId) return
  try {
    await ElMessageBox.confirm('本机记录了该原请求 HTTP 400 的真实未提交证明，尚无服务器保存回执。确认解除此原请求锁并修改输入？', '修改未提交输入', { confirmButtonText: '修改输入', cancelButtonText: '保留原请求', type: 'warning' })
    if (current !== generation || attempt.value !== pending) return
    const original = storedSave()
    if (!original || original.key !== pending.key || original.phase !== 'not_submitted' || !validProof(original.proof)) throw new Error('Original proof changed')
    clearSave(pending)
    source.value = null; saved.value = null; state.value = null; selectedId.value = null
    notice('已按原请求的本地未提交证明解锁。请重新选择原模型并修改输入，再明确另存。')
  } catch { if (current === generation && attempt.value === pending) notice('原未提交记录保留；未创建新请求。') }
}
const maxOutputTokens = ref<number | undefined>(1024)
const reasoningEffort = ref<ReasoningEffort | ''>('')
const skillOutputStrategy = ref<SkillOutputStrategy>('neuron_json_extract_v1')
const strategyLabels: Record<SkillOutputStrategy, string> = {
  neuron_json_extract_v1: '标准输出',
  neuron_zai_json_object_v1: 'JSON 对象',
  neuron_openai_json_schema_v1: 'JSON Schema'
}
const sourceStrategyLabel = computed(() => {
  const strategy = source.value?.deployment.config.skill_output_strategy
  return strategy ? strategyLabels[strategy] : '未指定（历史修订保持原策略）'
})
const savedStrategyLabel = computed(() => {
  const strategy = saved.value?.deployment.config.skill_output_strategy
  return strategy ? strategyLabels[strategy] : '未指定（历史修订保持原策略）'
})
const processingLocation = ref('')
type VerificationIntent = { admin_id: number; deployment_id: number; revision: number; key: string }
const verificationIntent = ref<VerificationIntent | null>(null)
const verificationPending = ref(false)
const intentStorageError = ref(false)
const publicationConfirmationRequired = ref(false)
const publicationPreview = ref<{ draft: DraftSave; verification: Verification } | null>(null)
const verificationRejected = ref(false)
const verificationAttempted = ref(false)
const recoveryBlocked = computed(() => verificationPending.value || intentStorageError.value || savePending.value || saveStorageError.value)
function intentNamespace(): string | null {
  const id = Number(userStore.info.id)
  return Number.isSafeInteger(id) && id > 0 ? `sand-ai:onboarding:successor-verification:${id}` : null
}
function record(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}
function storedIntent(): VerificationIntent | null {
  const namespace = intentNamespace()
  if (!namespace) throw new Error('Administrator unavailable')
  const raw = sessionStorage.getItem(namespace)
  if (raw === null) return null
  const item: unknown = JSON.parse(raw)
  if (!record(item) || item.admin_id !== Number(userStore.info.id)
    || !Number.isSafeInteger(item.deployment_id) || Number(item.deployment_id) < 1
    || !Number.isSafeInteger(item.revision) || Number(item.revision) < 1
    || typeof item.key !== 'string' || !/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i.test(item.key)) throw new Error('Original intent unavailable')
  return { admin_id: Number(item.admin_id), deployment_id: Number(item.deployment_id), revision: Number(item.revision), key: item.key }
}
function resolveIntent(intent: VerificationIntent): void {
  const stored = storedIntent()
  if (!stored || stored.key !== intent.key || stored.deployment_id !== intent.deployment_id || stored.revision !== intent.revision) throw new Error('Original intent changed')
  const namespace = intentNamespace()
  if (!namespace) throw new Error('Administrator unavailable')
  sessionStorage.removeItem(namespace)
  verificationPending.value = false
}
function terminalOriginalAttempt(value: Verification): boolean {
  if (value.state === 'tested' && value.validation_evidence) return true
  const failure: unknown = value.failure
  if (value.state === 'draft' && record(failure) && failure.error_code === 'SAND_AI_PROVIDER_REJECTED'
    && failure.outcome_uncertain === false && failure.recovery_action === 'reconfigure_connection') return true
  const usage: unknown = Reflect.get(value, 'platform_usage')
  return value.state === 'draft' && record(usage) && usage.scope_kind === 'platform_admin'
    && Number.isSafeInteger(usage.attempt_id) && Number(usage.attempt_id) > 0 && usage.state === 'not_dispatched'
}

const busy = ref<'' | 'list' | 'source' | 'save' | 'read' | 'verify' | 'publish'>('')
const message = ref('')
const messageType = ref<'error' | 'warning' | 'success'>('warning')
let generation = 0
let disposed = false
// An identity or environment change invalidates the view, not the actual POST.
const postFlight = ref(false)
function finishPost(current: number): void {
  postFlight.value = false
  if (current === generation) busy.value = ''
  else if (!disposed) void restoreOriginalRecords()
}
const locked = computed(() => Boolean(busy.value || postFlight.value || attempt.value || saved.value || recoveryBlocked.value))
const validOutput = computed(() => typeof maxOutputTokens.value === 'number' && Number.isInteger(maxOutputTokens.value)
  && maxOutputTokens.value >= 1 && maxOutputTokens.value <= 131072)
const labels = { draft: '待验证', verifying: '验证中', tested: '已验证，待发布', verification_ambiguous: '验证结果未确认', published: '已发布' }
const stateLabel = computed(() => state.value ? labels[state.value.state] : '未读回')
const savedEffortLabel = computed(() => {
  const effort = saved.value?.deployment.config.reasoning_effort
  return effort ? { low: '低', high: '高', max: '最大' }[effort] : '由模型服务决定（默认）'
})
function notice(text: string, type: 'error' | 'warning' | 'success' = 'warning'): void {
  message.value = text; messageType.value = type
}
function assertChat(value: DraftSave): void {
  const config = value.deployment.config
  if (value.model.type !== 'chat' || !value.model.capabilities.includes('chat')
    || typeof config.max_output_tokens !== 'number' || !Number.isInteger(config.max_output_tokens)
    || config.max_output_tokens < 1 || config.max_output_tokens > 131072
    || (config.skill_output_strategy !== undefined && !Object.hasOwn(strategyLabels, config.skill_output_strategy))
    || (config.reasoning_effort !== undefined && !['low', 'high', 'max'].includes(config.reasoning_effort))) {
    throw new Error('聊天部署配置无效')
  }
}
async function loadOptions(query: string): Promise<void> {
  if (postFlight.value || postFlight.value || busy.value || recoveryBlocked.value || attempt.value || saved.value) return
  busy.value = 'list'; const current = ++generation
  try {
    const result = await api.publishedDeployments('chat', query.trim())
    if (current !== generation) return
    options.value = result.data
    if (result.total > result.per_page) notice('仅显示前 100 项，请输入模型或服务商名称缩小范围。')
  } catch { if (current === generation) notice('已发布模型读取失败，请检查权限并重试。', 'error') }
  finally { if (current === generation) busy.value = '' }
}
async function readSource(): Promise<void> {
  if (postFlight.value || postFlight.value || busy.value || recoveryBlocked.value || attempt.value || saved.value) return
  const selected = options.value.find(item => item.id === selectedId.value)
  source.value = null
  if (!selected) return
  busy.value = 'source'; const current = ++generation
  try {
    const result = await api.readDraft(selected.id, selected.deployment_revision)
    if (current !== generation) return
    if (result.deployment_id !== selected.id || result.revision !== selected.deployment_revision || result.state !== 'published') throw new Error('原部署已变化')
    assertChat(result)
    source.value = result; maxOutputTokens.value = result.deployment.config.max_output_tokens
    reasoningEffort.value = result.deployment.config.reasoning_effort ?? ''
    skillOutputStrategy.value = result.deployment.config.skill_output_strategy ?? 'neuron_json_extract_v1'
    notice('已读取原部署配置。保存将创建独立后继部署，继续使用服务器内的原连接。', 'success')
  } catch { if (current === generation) notice('无法读取原部署的已发布修订；请重新读取模型列表并选择。', 'error') }
  finally { if (current === generation) busy.value = '' }
}
async function readExact(target: Pick<DraftSave, 'deployment_id' | 'revision'>, current: number): Promise<boolean> {
  const intent = verificationPending.value ? verificationIntent.value : null
  if (intent && (intent.admin_id !== Number(userStore.info.id) || intent.deployment_id !== target.deployment_id || intent.revision !== target.revision)) throw new Error('Original verification target differs')
  const [detail, verification] = await Promise.all([
    api.readDraft(target.deployment_id, target.revision), api.readVerification(target.deployment_id, target.revision, intent?.key)
  ])
  if (current !== generation) return false
  if (detail.deployment_id !== target.deployment_id || detail.revision !== target.revision
    || verification.deployment_id !== target.deployment_id || verification.revision !== target.revision
    || !Object.hasOwn(labels, verification.state)) throw new Error('后继修订不一致')
  assertChat(detail)
  saved.value = detail; state.value = verification
  if (intent && terminalOriginalAttempt(verification)) {
    resolveIntent(intent)
    intentStorageError.value = false
    verificationRejected.value = verification.state === 'draft'
    if (verification.state === 'tested' && detail.state === 'published' && verification.validation_evidence) state.value = { ...verification, state: 'published' }
  }
  return true
}
async function restoreIntent(): Promise<void> {
  if (postFlight.value || busy.value) return
  try {
    const stored = storedIntent()
    if (!stored) {
      if (verificationPending.value) throw new Error('Original verification intent disappeared')
      intentStorageError.value = false
      return
    }
    verificationIntent.value = stored
    verificationPending.value = true
    verificationAttempted.value = true
    intentStorageError.value = false
    busy.value = 'read'
    const current = generation
    try {
      if (await readExact(stored, current)) notice(verificationPending.value ? '原后继验证仍未确认，请用原号继续只读核查。' : `原后继验证已读回：${stateLabel.value}`)
    } catch { if (current === generation) notice('原后继尝试未找到或读取失败。不能据此认定未发送；保留原号，不会另存或重新验证。') }
    finally { if (current === generation) busy.value = '' }
  } catch {
    intentStorageError.value = true
    notice('无法读取原后继验证记录。请保留原号并联系管理员核查，不会生成新请求。')
  }
}

async function save(): Promise<void> {
  if (postFlight.value || busy.value) return
  if (attempt.value || saveStorageError.value) { await restoreSave(); return }
  if (recoveryBlocked.value || saved.value || !source.value || !validOutput.value
    || !positive(props.applicationId) || !positive(props.environmentId) || typeof maxOutputTokens.value !== 'number') return
  const original = source.value; const current = generation
  const admin = Number(userStore.info.id)
  const application = props.applicationId; const environment = props.environmentId
  const payload: SuccessorDeployment = {
    provider_id: original.provider.id, model_id: original.model.id, remote_model: original.deployment.remote_model,
    config: { max_output_tokens: maxOutputTokens.value, skill_output_strategy: skillOutputStrategy.value,
      ...(original.deployment.config.input_token_ceiling !== undefined ? { input_token_ceiling: original.deployment.config.input_token_ceiling } : {}),
      ...(reasoningEffort.value ? { reasoning_effort: reasoningEffort.value } : {}) }
  }
  busy.value = 'save'; postFlight.value = true
  let pending: SaveIntent | null = null
  let dispatched = false
  let receiptReturned = false
  try {
    const existing = storedSave()
    if (existing) { attempt.value = existing; notice('已有原保存记录，请只读找回。'); return }
    const digest = await inputDigest(payload)
    if (current !== generation || admin !== Number(userStore.info.id)) return
    pending = { version: 1, admin_id: admin, application_id: application, environment_id: environment,
      source_deployment_id: original.deployment_id, source_revision: original.revision,
      provider_id: original.provider.id, model_id: original.model.id, key: crypto.randomUUID(), input_sha256: digest, phase: 'pending' }
    persistSave(pending)
    // Only this invocation's fresh, never-dispatched request may POST once,
    // after its opaque journal has been persisted and read back successfully.
    // Every restored journal uses GET only; no response status grants POST permission.
    if (current !== generation || admin !== Number(userStore.info.id)) return
    const frozen = storedSave()
    if (!frozen || frozen.key !== pending.key || frozen.input_sha256 !== pending.input_sha256 || frozen.phase !== 'pending') throw new Error('Original save changed')
    dispatched = true
    const result = await api.save(payload, pending.key)
    receiptReturned = true
    if (await acceptSaveReceipt(result, pending, current)) notice('后继部署已保存并读回，请确认处理位置后验证。', 'success')
  } catch (error) {
    if (current !== generation || admin !== Number(userStore.info.id)) return
    if (pending && dispatched && !receiptReturned && error instanceof HttpError && error.code === 400 && validProof(error.data)) {
      const proof: SaveProof = { error_code: error.data.error_code, submission_state: 'not_submitted', retryable: false }
      try {
        persistSave({ ...pending, phase: 'not_submitted', proof }, pending)
        notice('原请求返回真实未提交证明，已仅在本机按原号保存。请明确选择修改输入；不会自动换号或重发。')
      } catch { saveStorageError.value = true; notice('未提交证明无法可靠保留。原请求继续锁定，请只读找回。') }
    } else {
      if (!pending) saveStorageError.value = true
      notice('后继保存结果未确认。请只读找回原保存，不会因超时、404 或普通错误再次提交。')
    }
  } finally { finishPost(current) }
}
async function readState(): Promise<void> {
  if (postFlight.value || postFlight.value || busy.value || !saved.value) return
  busy.value = 'read'; const current = generation
  try { if (await readExact(saved.value, current)) notice(`后继部署：${stateLabel.value}`, 'success') }
  catch { if (current === generation) notice('配置与状态读取未确认，请稍后重试。') }
  finally { if (current === generation) busy.value = '' }
}
async function verify(): Promise<void> {
  if (postFlight.value || postFlight.value || busy.value || recoveryBlocked.value || verificationAttempted.value || !saved.value || !processingLocation.value
    || state.value?.state !== 'draft' || verificationRejected.value || state.value.failure) return
  const target = saved.value; const current = generation
  let intent: VerificationIntent
  try {
    const stored = storedIntent()
    if (stored) { await restoreIntent(); return }
    const namespace = intentNamespace()
    if (!namespace) throw new Error('Administrator unavailable')
    intent = { admin_id: Number(userStore.info.id), deployment_id: target.deployment_id, revision: target.revision, key: crypto.randomUUID() }
    sessionStorage.setItem(namespace, JSON.stringify(intent))
    const frozen = storedIntent()
    if (!frozen || frozen.key !== intent.key || frozen.deployment_id !== intent.deployment_id || frozen.revision !== intent.revision) throw new Error('Verification intent changed')
    verificationIntent.value = intent
    verificationPending.value = true
  } catch {
    intentStorageError.value = true
    notice('无法保留原后继验证请求号，本次尚未发送。请恢复会话记录后只读找回。')
    return
  }
  busy.value = 'verify'; postFlight.value = true; verificationAttempted.value = true
  state.value = { deployment_id: target.deployment_id, revision: target.revision, state: 'verifying', validation_evidence: null }
  try {
    const result = await api.verify(target.deployment_id, target.revision, processingLocation.value, intent.key, 150000)
    if (current !== generation) return
    if (result.deployment_id !== target.deployment_id || result.revision !== target.revision || result.state !== 'tested' || !result.validation_evidence) throw new Error('Original verification result differs')
    state.value = result
    resolveIntent(intent)
    if (await readExact(target, current)) notice('原后继验证状态已读回；通过后可以发布固定修订。', 'success')
  } catch {
    if (current !== generation) return
    try { await readExact(target, current) } catch { /* No receipt or missing original attempt leaves its opaque intent locked. */ }
    notice(verificationPending.value ? '原后继验证未确认。请用原号只读找回；不会据空草稿、错误码或 404 换号重发。' : state.value?.failure?.message || '原后继状态已读回，请按真实状态继续。')
  } finally { finishPost(current) }
}

function publishedExactRevision(target: Pick<DraftSave, 'deployment_id' | 'revision'>): boolean {
  const detail = saved.value; const verification = state.value
  return detail?.deployment_id === target.deployment_id && detail.revision === target.revision && detail.state === 'published'
    && verification?.deployment_id === target.deployment_id && verification.revision === target.revision && verification.state === 'published'
}
const canFinishUpdate = computed(() => {
  const pending = attempt.value; const detail = saved.value
  return Boolean(pending?.phase === 'saved' && detail && !recoveryBlocked.value && !publicationConfirmationRequired.value
    && pending.admin_id === Number(userStore.info.id) && pending.application_id === props.applicationId && pending.environment_id === props.environmentId
    && pending.deployment_id === detail.deployment_id && pending.revision === detail.revision
    && publishedExactRevision(detail) && state.value?.validation_evidence)
})
async function finishUpdate(): Promise<void> {
  const pending = attempt.value; const current = generation
  if (postFlight.value || postFlight.value || busy.value || !pending || !canFinishUpdate.value) return
  try {
    await ElMessageBox.confirm(`结束部署 ${pending.deployment_id} 修订 ${pending.revision} 的本次更新记录？确认后将重新读取发布修订与验证证明，保留原号只读回看入口，并允许选择下一模型；不会自动保存、验证或发布。`, '结束本次已完成记录', { confirmButtonText: '结束并开始新的更新', cancelButtonText: '保留本次记录', type: 'warning' })
  } catch { return }
  if (current !== generation || attempt.value !== pending || postFlight.value || busy.value || !canFinishUpdate.value
    || !positive(pending.deployment_id) || !positive(pending.revision)) return
  busy.value = 'read'
  try {
    const frozen = storedSave()
    if (!frozen || JSON.stringify(frozen) !== JSON.stringify(pending) || storedIntent()) throw new Error('Original completed record changed')
    const receipt = await api.readSavedDraftByKey(pending.key)
    if (!await matchesSaveReceipt(receipt, pending, current)) return
    const [detail, verification] = await Promise.all([
      api.readDraft(pending.deployment_id), api.readVerification(pending.deployment_id, pending.revision)
    ])
    if (!await matchesSaveReceipt(detail, pending, current)) return
    if (detail.state !== 'published' || verification.deployment_id !== pending.deployment_id
      || verification.revision !== pending.revision || verification.state !== 'published' || !verification.validation_evidence) throw new Error('Original publication not confirmed')
    if (current !== generation || attempt.value !== pending || !canFinishUpdate.value) return
    // Keep one opaque read-only reference; it is never interpreted as an active request.
    const namespace = `${saveNamespace()}:completed`
    localStorage.setItem(namespace, JSON.stringify(pending))
    if (JSON.stringify(storedSave(namespace)) !== JSON.stringify(pending)) throw new Error('Completed reference unavailable')
    clearSave(pending)
    completedSave.value = pending; completedDraft.value = null
    options.value = []; selectedId.value = null; source.value = null; saved.value = null; state.value = null
    maxOutputTokens.value = 1024; reasoningEffort.value = ''; skillOutputStrategy.value = 'neuron_json_extract_v1'; processingLocation.value = ''
    verificationIntent.value = null; verificationPending.value = false; intentStorageError.value = false
    verificationAttempted.value = false; verificationRejected.value = false
    publicationConfirmationRequired.value = false; publicationPreview.value = null; saveStorageError.value = false
    notice('本次已完成记录已结束，原号可只读回看。请读取已发布模型并选择下一次更新；尚未创建或发送新请求。', 'success')
  } catch {
    if (current === generation) {
      // If clearing cannot be confirmed, keep the original active target locked.
      saveStorageError.value = true
      notice('当前发布修订、验证证明或记录解除未确认。本次原请求继续保留并锁定；请只读找回，不会开始新的更新。')
    }
  } finally { if (current === generation) busy.value = '' }
}
async function readCompletedUpdate(): Promise<void> {
  const target = completedSave.value; const current = generation
  if (postFlight.value || postFlight.value || busy.value || !target || target.admin_id !== Number(userStore.info.id)
    || !positive(target.deployment_id) || !positive(target.revision)) return
  busy.value = 'read'; completedDraft.value = null
  try {
    const receipt = await api.readSavedDraftByKey(target.key)
    if (!await matchesSaveReceipt(receipt, target, current)) return
    const detail = await api.readDraft(target.deployment_id, target.revision)
    if (!await matchesSaveReceipt(detail, target, current)) return
    completedDraft.value = detail
    notice('已按原号只读回看已结束记录的固定修订。该引用不会恢复活动请求，也不会自动保存、验证或发布。', 'success')
  } catch { if (current === generation) notice('已结束记录的固定修订回看未确认；保留只读引用，未改变当前更新目标。') }
  finally { if (current === generation) busy.value = '' }
}
async function publish(): Promise<void> {
  if (postFlight.value || postFlight.value || busy.value || recoveryBlocked.value || publicationConfirmationRequired.value || !saved.value || state.value?.state !== 'tested' || !state.value.validation_evidence) return
  const target = saved.value; const current = generation; busy.value = 'publish'; postFlight.value = true
  try {
    const result = await api.publish(target.deployment_id, target.revision)
    if (current !== generation) return
    if (result.deployment_id !== target.deployment_id || result.state !== 'published') throw new Error('发布对象不一致')
    if (!await readExact(target, current) || !publishedExactRevision(target)) throw new Error('发布修订未确认')
    publicationConfirmationRequired.value = false
    publicationPreview.value = null
    notice('固定后继修订已发布并读回。请显式更新所选环境的能力路线。', 'success')
  } catch {
    if (current === generation) {
      publicationConfirmationRequired.value = true
      publicationPreview.value = null
      notice('后继发布结果未确认或修订已变化。请读取当前后继修订、核对并明确确认；不会重复发布旧目标。')
    }
  } finally { finishPost(current) }
}
async function readCurrentRevision(): Promise<void> {
  if (postFlight.value || postFlight.value || busy.value || recoveryBlocked.value || !publicationConfirmationRequired.value || !saved.value) return
  const target = saved.value; const current = generation; busy.value = 'read'; publicationPreview.value = null
  try {
    const detail = await api.readDraft(target.deployment_id)
    if (current !== generation) return
    if (detail.deployment_id !== target.deployment_id || !Number.isSafeInteger(detail.revision) || detail.revision < 1) throw new Error('当前修订不一致')
    assertChat(detail)
    const verification = await api.readVerification(detail.deployment_id, detail.revision)
    if (current !== generation) return
    if (verification.deployment_id !== detail.deployment_id || verification.revision !== detail.revision || verification.state !== detail.state || !Object.hasOwn(labels, verification.state)) throw new Error('当前状态不一致')
    publicationPreview.value = { draft: detail, verification }
    notice('已读取当前后继修订。请核对配置与状态后明确确认；不会自动验证或发布。')
  } catch { if (current === generation) notice('当前后继修订读取失败。原目标与发布确认要求保持保留，404 不证明未执行。') }
  finally { if (current === generation) busy.value = '' }
}
async function confirmCurrentRevision(): Promise<void> {
  const preview = publicationPreview.value; const current = generation
  if (!preview || postFlight.value || busy.value || recoveryBlocked.value) return
  try {
    await ElMessageBox.confirm(`确认使用部署 ${preview.draft.deployment_id} 的修订 ${preview.draft.revision}，模型 ${preview.draft.model.name}（${preview.draft.deployment.remote_model}）？只更新本页目标，不会验证或发布。`, '确认当前后继修订', { type: 'warning', confirmButtonText: '确认此修订', cancelButtonText: '保留原目标' })
  } catch { return }
  if (current !== generation || publicationPreview.value !== preview || postFlight.value || busy.value || recoveryBlocked.value) return
  saved.value = preview.draft; state.value = preview.verification
  verificationIntent.value = null
  verificationRejected.value = state.value.state === 'draft' && Boolean(state.value.failure)
  verificationAttempted.value = state.value.state !== 'draft' || Boolean(state.value.failure)
  processingLocation.value = ''
  publicationConfirmationRequired.value = false; publicationPreview.value = null
  notice(state.value.state === 'published' ? '已确认当前后继已发布。请显式更新环境能力路线。' : '已确认当前后继修订。请按实际状态选择验证、发布或调整，不会自动执行。', 'success')
}

function revise(): void {
  if (postFlight.value || postFlight.value || busy.value || recoveryBlocked.value || publicationConfirmationRequired.value || state.value?.state !== 'draft' || !verificationRejected.value) return
  if (attempt.value) {
    try { clearSave(attempt.value) } catch { saveStorageError.value = true; notice('原保存记录无法解除，继续保留原请求。'); return }
  }
  saved.value = null; state.value = null; verificationRejected.value = false; verificationAttempted.value = false; verificationIntent.value = null; processingLocation.value = ''
  notice('可调整配置并另存新的后继部署。先前草稿仍保留在服务器。')
}
function openCapabilities(): void {
  if (postFlight.value || recoveryBlocked.value || (attempt.value && (attempt.value.application_id !== props.applicationId || attempt.value.environment_id !== props.environmentId)) || publicationConfirmationRequired.value || !props.applicationId || !props.environmentId || state.value?.state !== 'published') return
  void router.push({ path: '/sand-ai/capability', query: {
    application_id: String(props.applicationId), environment_id: String(props.environmentId)
  } })
}
watch([() => userStore.info.id, () => userStore.accessToken], () => {
  generation++
  options.value = []; selectedId.value = null; source.value = null; saved.value = null; state.value = null; attempt.value = null
  maxOutputTokens.value = 1024; reasoningEffort.value = ''; skillOutputStrategy.value = 'neuron_json_extract_v1'; processingLocation.value = ''; message.value = ''; saveStorageError.value = false
  completedSave.value = null; completedDraft.value = null
  verificationIntent.value = null; verificationPending.value = false; intentStorageError.value = false
  verificationAttempted.value = false; verificationRejected.value = false
  publicationConfirmationRequired.value = false; publicationPreview.value = null; busy.value = ''
  if (intentNamespace()) void restoreOriginalRecords()
}, { flush: 'sync' })
async function restoreOriginalRecords(): Promise<void> {
  const current = generation
  completedSave.value = null; completedDraft.value = null
  try {
    const completed = storedSave(`${saveNamespace()}:completed`)
    if (completed && completed.phase !== 'saved') throw new Error('Completed reference invalid')
    completedSave.value = completed
  } catch { notice('上次已结束记录的只读引用不可读；未将该引用作为活动请求。') }
  // Load verification intent first so readExact uses its original request key.
  try {
    const intent = storedIntent()
    verificationIntent.value = intent; verificationPending.value = Boolean(intent)
    verificationAttempted.value = Boolean(intent)
  } catch { intentStorageError.value = true }
  await restoreSave()
  if (current === generation && (verificationPending.value || intentStorageError.value)) await restoreIntent()
}
watch(() => [props.applicationId, props.environmentId], () => {
  generation++
  options.value = []; selectedId.value = null; source.value = null; saved.value = null; state.value = null
  maxOutputTokens.value = 1024; reasoningEffort.value = ''; skillOutputStrategy.value = 'neuron_json_extract_v1'; processingLocation.value = ''
  publicationConfirmationRequired.value = false; publicationPreview.value = null; busy.value = ''
  verificationRejected.value = false; verificationAttempted.value = false
  // Never delete another target's opaque record or reinterpret its request under the new selector.
  void restoreOriginalRecords()
}, { flush: 'sync' })
onMounted(() => { void restoreOriginalRecords() })
onBeforeUnmount(() => { disposed = true; generation++ })
</script>

<style scoped>
.muted { color: var(--el-text-color-secondary); }
.notice { margin-bottom: 16px; }
</style>
