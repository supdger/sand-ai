<template>
  <ElCard shadow="never" class="speech-trial">
    <template #header><h4>语音合成试用</h4></template>
    <p class="muted">使用已获 SandIAM 语音合成及结果读取权限的应用凭证。按已发布能力配置选择模型，试用会产生实际费用。</p>
    <p class="muted">当前读取作用域：管理员 {{ owner || '待确认' }} · {{ scopeLabel }}。管理员或环境变化后，请重新提供凭证并找回该作用域的原请求。</p>
    <ElForm label-width="110px" @submit.prevent>
      <RuntimeApiKeyField v-model="apiKey" placeholder="需 sand_ai.audio.speech 和 sand_ai.audio.speech.read 授权" />
      <ElFormItem label="朗读文字">
        <ElInput v-model="text" type="textarea" :rows="3" :maxlength="4096" show-word-limit :disabled="busy || postFlight !== null || (hasRecovery && run?.state !== 'prepared')" placeholder="输入要合成语音的文字" />
      </ElFormItem>
      <ElFormItem label="音色标识">
        <ElInput v-model.trim="voice" maxlength="64" :disabled="busy || postFlight !== null || (hasRecovery && run?.state !== 'prepared')" placeholder="例如 alloy；以已发布服务商支持的标识为准" />
      </ElFormItem>
      <ElFormItem>
        <ElSpace wrap>
          <ElButton type="primary" :loading="busy" :disabled="!canSubmit" @click="submit">
            {{ hasRecovery ? '继续原请求（已确认准备中）' : '生成语音' }}
          </ElButton>
          <ElButton v-if="recoveryKey" :loading="busy" :disabled="busy || postFlight !== null || !apiKey.trim()" @click="recover">按原请求键只读找回</ElButton>
          <ElButton v-if="knownPublicId" :loading="busy" :disabled="busy || postFlight !== null || !apiKey.trim()" @click="refresh">读取原运行状态</ElButton>
          <ElButton v-if="run?.state === 'succeeded' && recoveryChecked && !busy && postFlight === null" @click="newTrial">开始新的试用</ElButton>
        </ElSpace>
      </ElFormItem>
    </ElForm>
    <p v-if="recoveryKey" class="muted">原请求键：{{ recoveryKey }}<template v-if="knownPublicId"> · 原运行号：{{ knownPublicId }}</template></p>
    <ElAlert v-if="hasRecovery && !run" type="warning" :closable="false" title="原请求结果尚未确认。使用当前有效凭证只读找回；未找到或拒绝读取不会解除原请求锁，也不会重新合成。" />
    <ElAlert v-if="changedPending && run?.state === 'prepared'" type="warning" :closable="false" title="请恢复原文字和音色才能继续原请求；修改输入不会换请求键。只读找回使用当前有效凭证。" />
    <p v-if="run?.state === 'prepared'" class="muted">仅在只读核对原运行为准备中后，才可明确继续同一个请求；文字和音色须与原请求一致。</p>
    <p v-if="run?.state === 'failed'" class="muted">原请求失败，原运行及费用仍保留。失败状态不证明未发生调用，请继续读取原状态核对；此处不会换键重新合成。</p>
    <ElAlert v-if="legacy || (hasRecovery && !recovery?.digest)" type="warning" :closable="false" title="旧记录归属或原输入摘要未确认，仅可用当前凭证按原号读取；不能继续发送原请求。" />
    <ElAlert v-if="storageError" type="error" :closable="false" :title="storageError" class="notice" />
    <ElAlert v-if="error" type="error" :closable="false" :title="error" class="notice" />
    <template v-if="run">
      <p>运行号：{{ run.public_id }} · {{ stateLabel(run.state) }} · {{ run.input_characters }} 字</p>
      <p v-if="run.cost">计量费用：{{ costLabel(run.cost) }}</p>
      <p v-if="run.state === 'ambiguous'" class="muted">服务商结果不明。不要使用新请求键重发同一内容。</p>
      <p v-if="run.state === 'awaiting_usage'" class="muted">音频已保存，费用正在入账；完成前不提供下载。</p>
      <ElButton v-if="run.state === 'succeeded' && recoveryChecked" :loading="loadingAudio" :disabled="loadingAudio || busy || postFlight !== null || !apiKey.trim()" @click="loadAudio">读取音频</ElButton>
      <p v-if="audioUrl" class="muted">本次音频实际格式：{{ audioFormat.toUpperCase() }}；下载名称：{{ audioFilename }}</p>
      <a v-if="audioUrl" :href="audioUrl" :download="audioFilename">下载本次音频</a>
      <audio v-if="audioUrl" :src="audioUrl" controls preload="none" class="player" />
    </template>
    <ElDivider />
    <h4>读取另一个已知语音请求</h4>
    <p class="muted">填写已保存的原请求键，可补充已知运行号核对结果。读取只查询已有运行与费用，不改变上方试用请求。</p>
    <ElForm label-width="110px" @submit.prevent="readKnownRequest">
      <ElFormItem label="原请求键">
        <ElInput v-model="readerKey" maxlength="512" placeholder="原请求的 Idempotency-Key" />
      </ElFormItem>
      <ElFormItem label="已知运行号">
        <ElInput v-model.trim="readerPublicId" maxlength="32" placeholder="可选；用于核对原运行号" />
      </ElFormItem>
      <ElFormItem>
        <ElSpace wrap>
          <ElButton native-type="submit" :loading="readerBusy" :disabled="!canReadKnown">只读找回已有请求</ElButton>
          <ElButton v-if="readerRun" :disabled="readerBusy || readerAudioBusy || !apiKey.trim()" @click="refreshKnownRequest">刷新该运行状态</ElButton>
        </ElSpace>
      </ElFormItem>
    </ElForm>
    <ElAlert v-if="readerError" type="error" :closable="false" :title="readerError" class="notice" />
    <template v-if="readerRun">
      <p>只读请求键：{{ readerTarget?.key }} · 运行号：{{ readerRun.public_id }}</p>
      <p>{{ stateLabel(readerRun.state) }} · {{ readerRun.input_characters }} 字 · 音色：{{ readerRun.voice }}</p>
      <p>计量费用：{{ readerRun.cost ? costLabel(readerRun.cost) : '尚无费用记录，金额未知' }}</p>
      <p v-if="readerRun.state === 'ambiguous'" class="muted">服务商结果不明，请保留原请求键继续读取状态。</p>
      <p v-if="readerRun.state === 'awaiting_usage'" class="muted">音频已保存，费用正在入账；完成前不提供下载。</p>
      <ElButton v-if="readerRun.state === 'succeeded'" :loading="readerAudioBusy" :disabled="readerBusy || readerAudioBusy || !apiKey.trim()" @click="loadKnownAudio">读取该运行音频</ElButton>
      <p v-if="readerAudioUrl" class="muted">该运行音频实际格式：{{ readerAudioFormat.toUpperCase() }}；下载名称：{{ readerAudioFilename }}</p>
      <a v-if="readerAudioUrl" :href="readerAudioUrl" :download="readerAudioFilename">下载该运行音频</a>
      <audio v-if="readerAudioUrl" :src="readerAudioUrl" controls preload="none" class="player" />
    </template>
  </ElCard>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useUserStore } from '@/store/modules/user'
import RuntimeApiKeyField from './RuntimeApiKeyField.vue'
import speechApi, { type SpeechRun } from '../api/speech'
import { newRuntimeRequestId } from '../api/runtime'

const props = defineProps<{ environmentId?: number | null }>()
type Recovery = { key: string; publicId: string | null; owner?: string; environment?: number | null; digest?: string; characters?: number; voiceDigest?: string }
const userStore = useUserStore()
const owner = computed(() => {
  if (!userStore.accessToken) return ''
  const id: unknown = userStore.info.id
  return (typeof id === 'number' && Number.isSafeInteger(id) && id > 0) || (typeof id === 'string' && /^[1-9]\d*$/.test(id)) ? String(id) : ''
})
const environment = computed(() => props.environmentId ?? null)
const environmentHeader = computed(() => environment.value ?? undefined)
const scope = computed(() => owner.value ? `sand-ai-speech-trial-recovery:v2:${owner.value}:${environment.value ?? 'credential'}` : '')
const scopeLabel = computed(() => environment.value === null ? '未指定环境：由当前应用凭证按服务现有规则选择' : `环境 ${environment.value}`)
const apiKey = ref('')
const text = ref('')
const voice = ref('alloy')
const busy = ref(false)
// This lock belongs to the actual POST, not to the current identity or GET epoch.
const postFlight = ref<number | null>(null)
let postSequence = 0
const loadingAudio = ref(false)
const error = ref('')
const storageError = ref('')
const run = ref<SpeechRun | null>(null)
const audioUrl = ref('')
const audioFilename = ref('')
const audioFormat = ref('')
const recovery = ref<Recovery | null>(null)
const legacy = ref(false)
const recoveryKey = computed(() => recovery.value?.key ?? '')
const knownPublicId = computed(() => recovery.value?.publicId ?? null)
const recoveryChecked = ref(false)
const draftDigest = ref('')
const draftVoiceDigest = ref('')
let epoch = 0
let draftEpoch = 0
const oldStorageKeys = ['sand-ai-speech-trial-recovery', 'sand-ai-speech-trial-request-key']
function parseRecovery(raw: string): Recovery {
  const value: unknown = JSON.parse(raw)
  if (typeof value !== 'object' || value === null || Array.isArray(value)) throw new Error('原语音恢复记录无法识别，请保留记录核对')
  const item = value as Record<string, unknown>
  if (typeof item.key !== 'string' || !/^[\x21-\x7e]{1,512}$/.test(item.key)
    || !(item.publicId === null || (typeof item.publicId === 'string' && /^[a-f0-9]{32}$/.test(item.publicId)))) throw new Error('原语音恢复记录无法识别，请保留记录核对')
  const record: Recovery = { key: item.key, publicId: item.publicId }
  if (typeof item.owner === 'string') record.owner = item.owner
  if (item.environment === null || (typeof item.environment === 'number' && Number.isSafeInteger(item.environment) && item.environment > 0)) record.environment = item.environment
  if (typeof item.digest === 'string' && /^[a-f0-9]{64}$/.test(item.digest)) record.digest = item.digest
  if (typeof item.voiceDigest === 'string' && /^[a-f0-9]{64}$/.test(item.voiceDigest)) record.voiceDigest = item.voiceDigest
  if (typeof item.characters === 'number' && Number.isSafeInteger(item.characters) && item.characters > 0) record.characters = item.characters
  return record
}
function restoreScope(): void {
  recovery.value = null
  legacy.value = false
  storageError.value = ''
  if (!scope.value) { storageError.value = '管理员身份尚未确认，不能发送语音请求'; return }
  try {
    const scoped = sessionStorage.getItem(scope.value)
    if (scoped !== null) {
      const saved = parseRecovery(scoped)
      if (saved.owner !== owner.value || saved.environment !== environment.value) throw new Error('原语音记录作用域不一致，请保留记录核对')
      recovery.value = saved
      return
    }
    const old = sessionStorage.getItem(oldStorageKeys[0])
    if (old !== null) { recovery.value = parseRecovery(old); legacy.value = true; return }
    const key = sessionStorage.getItem(oldStorageKeys[1])
    if (key !== null) {
      if (!/^[\x21-\x7e]{1,512}$/.test(key)) throw new Error('旧语音请求键无法识别，请保留记录核对')
      recovery.value = { key, publicId: null }; legacy.value = true
    }
  } catch (cause) { storageError.value = cause instanceof Error ? cause.message : '无法读取原语音恢复记录，发送已停止' }
}
async function digest(value: string): Promise<string> {
  const bytes = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(value))
  return Array.from(new Uint8Array(bytes), byte => byte.toString(16).padStart(2, '0')).join('')
}
watch([text, voice], async () => {
  const current = ++draftEpoch
  draftDigest.value = ''; draftVoiceDigest.value = ''
  try {
    const [inputHash, voiceHash] = await Promise.all([digest(JSON.stringify([text.value, voice.value.trim()])), digest(voice.value.trim())])
    if (current !== draftEpoch) return
    draftDigest.value = inputHash; draftVoiceDigest.value = voiceHash
  } catch { if (current === draftEpoch) error.value = '无法计算原输入摘要，发送已停止' }
}, { immediate: true })
const ready = computed(() => Boolean(apiKey.value.trim() && text.value.trim() && voice.value.trim() && draftDigest.value && draftVoiceDigest.value))
const hasRecovery = computed(() => Boolean(recovery.value))
const changedPending = computed(() => hasRecovery.value && (!recovery.value?.digest || !recovery.value.voiceDigest || recovery.value.characters === undefined || draftDigest.value !== recovery.value.digest))
const canSubmit = computed(() => !busy.value && postFlight.value === null && !storageError.value && Boolean(scope.value) && ready.value
  && (!hasRecovery.value || (!legacy.value && !changedPending.value && recoveryChecked.value && run.value?.state === 'prepared')))
function stateLabel(state: SpeechRun['state']): string {
  return ({ prepared: '准备中', dispatching: '合成中', awaiting_usage: '费用入账中', succeeded: '已完成', failed: '失败', ambiguous: '结果不明' })[state]
}
function costLabel(cost: NonNullable<SpeechRun['cost']>): string {
  return cost.pricing_state === 'priced' && cost.metered_amount && cost.currency ? `${cost.metered_amount} ${cost.currency}（计量金额）` : '未计价，金额未知'
}
function releaseAudio(): void {
  if (audioUrl.value) URL.revokeObjectURL(audioUrl.value)
  audioUrl.value = ''; audioFilename.value = ''; audioFormat.value = ''
}
function saveRecovery(value: Recovery): void {
  recovery.value = value // Keep the original key visible even if persistence fails.
  if (!scope.value) throw new Error('管理员身份尚未确认，发送已停止')
  try {
    const raw = JSON.stringify(value)
    sessionStorage.setItem(scope.value, raw)
    if (sessionStorage.getItem(scope.value) !== raw) throw new Error('恢复记录写入后读取不一致')
    storageError.value = ''
  } catch {
    storageError.value = '无法保存并确认原请求恢复记录，发送已停止；请保留原请求键'
    throw new Error(storageError.value)
  }
}
async function acceptRun(result: SpeechRun, original: Recovery): Promise<void> {
  if (!/^[a-f0-9]{32}$/.test(result.public_id)
    || !['prepared', 'dispatching', 'awaiting_usage', 'succeeded', 'failed', 'ambiguous'].includes(result.state)
    || (original.publicId !== null && original.publicId !== result.public_id)
    || typeof result.voice !== 'string' || !Number.isSafeInteger(result.input_characters) || result.input_characters < 1
    || (original.characters !== undefined && original.characters !== result.input_characters)
    || (original.voiceDigest !== undefined && original.voiceDigest !== await digest(result.voice))) {
    throw new Error('读取的语音运行与原记录不一致，请保留原请求键核对')
  }
}
async function submit(): Promise<void> {
  if (!canSubmit.value) return
  const current = ++epoch; const flight = ++postSequence
  postFlight.value = flight
  const input = text.value; const selectedVoice = voice.value.trim(); const credential = apiKey.value; const selectedEnvironment = environmentHeader.value
  recoveryChecked.value = false; loadingAudio.value = false; busy.value = true; error.value = ''; releaseAudio()
  try {
    const original: Recovery = recovery.value ?? { key: newRuntimeRequestId('speech'), publicId: null, owner: owner.value, environment: environment.value, digest: draftDigest.value, voiceDigest: draftVoiceDigest.value, characters: Array.from(input).length }
    saveRecovery(original)
    const result = await speechApi.submit(credential, input, selectedVoice, original.key, selectedEnvironment)
    if (current !== epoch) return
    await acceptRun(result, original)
    if (current !== epoch) return
    saveRecovery({ ...original, publicId: result.public_id }); run.value = result
  } catch (cause) { if (current === epoch) error.value = cause instanceof Error ? cause.message : '语音请求失败' }
  finally {
    if (postFlight.value === flight) {
      postFlight.value = null
      if (current === epoch) busy.value = false
    }
  }
}
async function readOriginal(byKey: boolean): Promise<void> {
  const original = recovery.value
  if (busy.value || postFlight.value !== null || !apiKey.value.trim() || !owner.value || !original || (!byKey && !original.publicId)) return
  const current = ++epoch
  const credential = apiKey.value; const selectedEnvironment = environmentHeader.value
  busy.value = true; loadingAudio.value = false; error.value = ''; recoveryChecked.value = false; run.value = null; releaseAudio()
  try {
    const result = byKey ? await speechApi.readByRequestKey(credential, original.key, selectedEnvironment) : await speechApi.read(credential, original.publicId!, selectedEnvironment)
    if (current !== epoch) return
    await acceptRun(result, original)
    if (current !== epoch) return
    // Legacy records acquire a scope only after the current authorized original GET succeeds.
    saveRecovery({ ...original, publicId: result.public_id, owner: owner.value, environment: environment.value })
    if (legacy.value) {
      for (const key of oldStorageKeys) sessionStorage.removeItem(key)
      legacy.value = false
    }
    run.value = result; recoveryChecked.value = true
  } catch (cause) { if (current === epoch) error.value = cause instanceof Error ? cause.message : '原语音请求读取失败' }
  finally { if (current === epoch) busy.value = false }
}
async function recover(): Promise<void> { await readOriginal(true) }
async function refresh(): Promise<void> { await readOriginal(false) }
async function loadAudio(): Promise<void> {
  if (loadingAudio.value || busy.value || postFlight.value !== null || !apiKey.value.trim() || run.value?.state !== 'succeeded' || !recoveryChecked.value) return
  const current = ++epoch
  const publicId = run.value.public_id
  loadingAudio.value = true; error.value = ''; releaseAudio()
  try {
    const audio = await speechApi.audio(apiKey.value, publicId, environmentHeader.value)
    if (current !== epoch) return
    audioUrl.value = URL.createObjectURL(audio.blob); audioFilename.value = audio.filename; audioFormat.value = audio.format
  } catch (cause) { if (current === epoch) error.value = cause instanceof Error ? cause.message : '音频读取失败' }
  finally { if (current === epoch) loadingAudio.value = false }
}
function newTrial(): void {
  if (busy.value || postFlight.value !== null || run.value?.state !== 'succeeded' || !recoveryChecked.value || legacy.value) return
  try {
    sessionStorage.removeItem(scope.value)
    if (sessionStorage.getItem(scope.value) !== null) throw new Error('原恢复记录仍存在')
  } catch { storageError.value = '无法结束原恢复记录，请保留原请求键'; return }
  epoch++; recovery.value = null; loadingAudio.value = false; run.value = null; recoveryChecked.value = false; releaseAudio()
}
watch(apiKey, () => {
  epoch++; draftEpoch++; busy.value = false; loadingAudio.value = false; run.value = null; error.value = ''; recoveryChecked.value = false
  text.value = ''; voice.value = 'alloy'; draftDigest.value = ''; draftVoiceDigest.value = ''; releaseAudio()
}, { flush: 'sync' })
watch([scope, () => userStore.accessToken], () => {
  epoch++; draftEpoch++; busy.value = false; loadingAudio.value = false; run.value = null; error.value = ''; recoveryChecked.value = false
  apiKey.value = ''; text.value = ''; voice.value = 'alloy'; draftDigest.value = ''; draftVoiceDigest.value = ''; releaseAudio(); restoreScope()
}, { immediate: true, flush: 'sync' })
onBeforeUnmount(() => { epoch++; draftEpoch++; apiKey.value = ''; text.value = ''; voice.value = 'alloy'; releaseAudio() })

// This reader owns its GET state and never updates the current trial journal or POST state.
type ReaderTarget = { key: string; publicId: string | null }
const readerKey = ref('')
const readerPublicId = ref('')
const readerTarget = ref<ReaderTarget | null>(null)
const readerRun = ref<SpeechRun | null>(null)
const readerBusy = ref(false)
const readerAudioBusy = ref(false)
const readerError = ref('')
const readerAudioUrl = ref('')
const readerAudioFilename = ref('')
const readerAudioFormat = ref('')
let readerEpoch = 0
const canReadKnown = computed(() => Boolean(owner.value && apiKey.value.trim()) && !readerBusy.value && !readerAudioBusy.value
  && /^[\x21-\x7e]{1,512}$/.test(readerKey.value)
  && (!readerPublicId.value || /^[a-f0-9]{32}$/.test(readerPublicId.value)))
function releaseReaderAudio(): void {
  if (readerAudioUrl.value) URL.revokeObjectURL(readerAudioUrl.value)
  readerAudioUrl.value = ''; readerAudioFilename.value = ''; readerAudioFormat.value = ''
}
function clearReader(): void {
  readerEpoch++; readerBusy.value = false; readerAudioBusy.value = false
  readerRun.value = null; readerTarget.value = null; readerError.value = ''; releaseReaderAudio()
}
function validateReaderCost(cost: SpeechRun['cost']): void {
  if (cost === null) return
  if (typeof cost !== 'object' || !['priced', 'unpriced'].includes(cost.pricing_state)
    || !(cost.price_revision_id === null || (Number.isSafeInteger(cost.price_revision_id) && cost.price_revision_id > 0))
    || !(cost.metered_amount === null || (typeof cost.metered_amount === 'string' && /^\d+(?:\.\d+)?$/.test(cost.metered_amount)))
    || !(cost.currency === null || (typeof cost.currency === 'string' && /^[A-Z]{3}$/.test(cost.currency)))) {
    throw new Error('该运行的费用记录无法识别，请保留原请求键核对')
  }
}
async function readKnown(byPublicId: boolean): Promise<void> {
  const target: ReaderTarget | null = byPublicId
    ? (readerTarget.value && readerRun.value ? { key: readerTarget.value.key, publicId: readerRun.value.public_id } : null)
    : { key: readerKey.value, publicId: readerPublicId.value || null }
  if (!target || !canReadKnown.value || (byPublicId && !target.publicId)) return
  const current = ++readerEpoch
  const credential = apiKey.value; const selectedScope = scope.value; const selectedEnvironment = environmentHeader.value
  readerBusy.value = true; readerError.value = ''; readerRun.value = null; readerTarget.value = null; releaseReaderAudio()
  const isCurrent = () => current === readerEpoch && selectedScope === scope.value && credential === apiKey.value && selectedEnvironment === environmentHeader.value
  try {
    const result = byPublicId
      ? await speechApi.read(credential, target.publicId!, selectedEnvironment)
      : await speechApi.readByRequestKey(credential, target.key, selectedEnvironment)
    if (!isCurrent()) return
    await acceptRun(result, target)
    validateReaderCost(result.cost)
    if (!isCurrent()) return
    readerTarget.value = { key: target.key, publicId: result.public_id }; readerRun.value = result
  } catch (cause) {
    if (isCurrent()) readerError.value = cause instanceof Error ? cause.message : '已有语音请求读取失败；请保留原请求键'
  } finally { if (isCurrent()) readerBusy.value = false }
}
async function readKnownRequest(): Promise<void> { await readKnown(false) }
async function refreshKnownRequest(): Promise<void> { await readKnown(true) }
async function loadKnownAudio(): Promise<void> {
  if (readerBusy.value || readerAudioBusy.value || !apiKey.value.trim() || !owner.value || readerRun.value?.state !== 'succeeded') return
  const current = readerEpoch
  const publicId = readerRun.value.public_id
  const credential = apiKey.value; const selectedScope = scope.value; const selectedEnvironment = environmentHeader.value
  const isCurrent = () => current === readerEpoch && selectedScope === scope.value && credential === apiKey.value && selectedEnvironment === environmentHeader.value
  readerAudioBusy.value = true; readerError.value = ''; releaseReaderAudio()
  try {
    const audio = await speechApi.audio(credential, publicId, selectedEnvironment)
    if (!isCurrent()) return
    readerAudioUrl.value = URL.createObjectURL(audio.blob); readerAudioFilename.value = audio.filename; readerAudioFormat.value = audio.format
  } catch (cause) { if (isCurrent()) readerError.value = cause instanceof Error ? cause.message : '该运行音频读取失败' }
  finally { if (isCurrent()) readerAudioBusy.value = false }
}
watch([readerKey, readerPublicId], clearReader, { flush: 'sync' })
watch([scope, apiKey, () => userStore.accessToken], () => {
  clearReader()
  // Identifiers are opaque, but the new administrator/environment must enter their own target.
  readerKey.value = ''; readerPublicId.value = ''
}, { flush: 'sync' })
onBeforeUnmount(clearReader)

</script>

<style scoped>
.speech-trial { margin-top: 16px; }
.speech-trial h4 { margin: 0; }
.muted { color: var(--el-text-color-secondary); }
.notice { margin: 12px 0; }
.player { display: block; max-width: 100%; margin-top: 12px; }
</style>
