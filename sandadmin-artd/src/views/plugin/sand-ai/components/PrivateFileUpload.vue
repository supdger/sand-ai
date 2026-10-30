<template>
  <div>
    <ElUpload :auto-upload="false" :show-file-list="false" :accept="accept" :disabled="disabled || busy || !scope || storageFailed || (!!pending && !retryAllowed)" @change="select">
      <ElButton type="primary" plain :loading="busy" :disabled="disabled || busy || !scope || storageFailed || (!!pending && !retryAllowed)">{{ pending ? '选择同一文件继续原上传' : '选择并上传材料' }}</ElButton>
    </ElUpload>
    <ElAlert v-if="pending && pending.scope === scope" type="warning" :closable="false" title="有一份上传尚待确认，请先找回原结果。">
      <p>{{ pending.name }} · {{ pending.size }} 字节</p>
      <ElButton :disabled="busy || !scope" @click="recover">读取原上传结果</ElButton>
      <p v-if="retryAllowed">原上传尚未完成。可重新选择相同文件继续，不会创建另一份材料。</p>
    </ElAlert>
    <ElAlert v-if="message" :type="problem ? 'warning' : 'info'" :closable="false" :title="message" />
    <ElButton v-if="!scope || storageFailed" :disabled="busy || !bearer.trim()" @click="initialize">重新确认上传身份与原记录</ElButton>
  </div>
</template>
<script setup lang="ts">
import { ref, watch, onUnmounted } from 'vue'
import type { UploadFile } from 'element-plus'
import { useUserStore } from '@/store/modules/user'
import fileApi, { type UploadReceipt } from '../api/file'
import { newRuntimeRequestId, SandAiRuntimeError } from '../api/runtime'
import type { SandAiFile } from '../api/types'
const props = withDefaults(defineProps<{ bearer: string; environmentId?: number; disabled: boolean; accept?: string }>(), { accept: '.pdf,.docx,.txt,.csv,.xlsx,.png,.jpg,.jpeg,.webp' })
const emit = defineEmits<{ file: [file: SandAiFile]; busy: [busy: boolean]; 'identity-denied': [failure: SandAiRuntimeError] }>()
const user = useUserStore()
type Pending = { key: string; name: string; size: number; sha256: string; scope: string; environmentId: number; fileId: number | null }
const pending = ref<Pending | null>(null)
const scope = ref(''); const busy = ref(false); const message = ref(''); const problem = ref(false)
const storageFailed = ref(false); const retryAllowed = ref(false)
let generation = 0; let environment = 0; let storageKey = ''
function setBusy(value: boolean): void { busy.value = value; emit('busy', value) }
function valid(value: unknown): value is Pending {
  if (typeof value !== 'object' || value === null || Array.isArray(value)) return false
  const v = value as Record<string, unknown>
  return typeof v.key === 'string' && /^[A-Za-z0-9][A-Za-z0-9_.:-]{7,95}$/.test(v.key)
    && typeof v.name === 'string' && v.name.length > 0 && v.name.length <= 512
    && typeof v.size === 'number' && Number.isSafeInteger(v.size) && v.size > 0
    && typeof v.sha256 === 'string' && /^[a-f0-9]{64}$/.test(v.sha256)
    && typeof v.scope === 'string' && /^[a-f0-9]{64}$/.test(v.scope)
    && typeof v.environmentId === 'number' && Number.isSafeInteger(v.environmentId) && v.environmentId > 0
    && (v.fileId === null || (typeof v.fileId === 'number' && Number.isSafeInteger(v.fileId) && v.fileId > 0))
}
function load(): Pending | null {
  const raw = sessionStorage.getItem(storageKey)
  if (raw === null) return null
  const value: unknown = JSON.parse(raw)
  if (!valid(value) || value.scope !== scope.value || value.environmentId !== environment) throw new Error('原记录不可确认')
  return value
}
function retain(value: Pending): void {
  pending.value = value
  try {
    const encoded = JSON.stringify(value); sessionStorage.setItem(storageKey, encoded)
    if (sessionStorage.getItem(storageKey) !== encoded) throw new Error('原记录未保存')
  } catch { storageFailed.value = true; throw new Error('原上传记录无法保存，请勿重新上传') }
}
function failure(error: unknown, text: string): void {
  problem.value = true; message.value = text
  if (error instanceof SandAiRuntimeError && ([401, 403].includes(error.httpStatus) || error.errorCode === 'SAND_AI_ENVIRONMENT_MISMATCH')) emit('identity-denied', error)
}
async function initialize(): Promise<void> {
  const current = ++generation
  scope.value = ''; retryAllowed.value = false; setBusy(false); message.value = ''; problem.value = false
  if (!props.bearer.trim()) return
  const credential = props.bearer; const admin = user.info.id
  if (typeof admin !== 'number' || !Number.isSafeInteger(admin) || admin < 1) return
  setBusy(true)
  try {
    const context = await fileApi.uploadContext(credential, props.environmentId)
    if (current !== generation) return
    scope.value = context.scope; environment = context.environment_id
    const nextStorageKey = `sand-ai-upload-v1:${admin}:${scope.value}`
    if (storageKey !== nextStorageKey) pending.value = null
    storageKey = nextStorageKey
    const stored = load()
    if (pending.value?.scope === scope.value && (!stored || stored.key !== pending.value.key)) throw new Error('原记录发生变化')
    pending.value = stored; storageFailed.value = false
  } catch (error) {
    if (current === generation) { storageFailed.value = true; failure(error, '上传身份或原记录暂不可确认，请先恢复读取，不创建新上传。') }
  } finally { if (current === generation) setBusy(false) }
}
async function acceptReceipt(receipt: UploadReceipt, original: Pending, credential: string, current: number): Promise<void> {
  if (receipt.sha256 !== original.sha256 || receipt.size_bytes !== original.size || (original.fileId !== null && receipt.id !== original.fileId)) throw new Error('原上传内容不一致')
  retain({ ...original, fileId: receipt.id })
  message.value = `已确认文件 #${receipt.id}，正在读取当前材料状态。`
  const file = await fileApi.read(credential, receipt.id, original.environmentId)
  if (current !== generation) return
  if (file.id !== receipt.id || file.sha256 !== original.sha256) throw new Error('材料读取不一致')
  const stored = load()
  if (!stored || stored.key !== original.key) throw new Error('原记录发生变化')
  sessionStorage.removeItem(storageKey)
  if (sessionStorage.getItem(storageKey) !== null) throw new Error('原记录仍待确认')
  pending.value = null; retryAllowed.value = false; problem.value = false
  message.value = file.state === 'deleted' ? '原文件已删除，不会重新上传。' : `已找回文件 #${file.id}。`
  emit('file', file)
}
/** Only this new POST's exact pre-intent rejection can release its local slot. */
function discardRejectedNewUpload(original: Pending, error: unknown): boolean {
  if (!(error instanceof SandAiRuntimeError) || error.httpStatus !== 400 || error.businessCode !== 400
    || error.errorCode !== 'SAND_AI_FILE_INVALID' || error.requestId !== original.key) return false
  const stored = load()
  if (!stored || stored.key !== original.key || stored.fileId !== null
    || pending.value?.key !== original.key) throw new Error('原记录发生变化')
  try {
    sessionStorage.removeItem(storageKey)
    if (sessionStorage.getItem(storageKey) !== null) throw new Error('原记录未移除')
  } catch { storageFailed.value = true; throw new Error('原记录清理尚待确认') }
  pending.value = null; retryAllowed.value = false
  problem.value = true; message.value = '文件未通过上传检查，尚未创建上传记录。请检查文件格式、内容和大小后重新选择。'
  return true
}

async function select(upload: UploadFile): Promise<void> {
  if (busy.value || props.disabled || !scope.value || storageFailed.value || !upload.raw) return
  const current = generation; const credential = props.bearer; const raw = upload.raw
  if (!Number.isSafeInteger(raw.size) || raw.size < 1 || !raw.name || raw.name.length > 512) {
    problem.value = true; message.value = '请选择内容非空、文件名有效的材料；本次尚未发送。'; return
  }
  setBusy(true); message.value = ''; problem.value = false
  try {
    const hash = Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256', await raw.arrayBuffer())), b => b.toString(16).padStart(2, '0')).join('')
    if (current !== generation) return
    const existing = load()
    if (pending.value?.scope === scope.value && (!existing || existing.key !== pending.value.key)) {
      storageFailed.value = true; throw new Error('原记录发生变化，不能创建新上传')
    }
    if (existing && (!retryAllowed.value || existing.name !== raw.name || existing.size !== raw.size || existing.sha256 !== hash)) {
      pending.value = existing; throw new Error('先确认原文件')
    }
    const original: Pending = existing ?? { key: newRuntimeRequestId('file-upload'), name: raw.name, size: raw.size, sha256: hash, scope: scope.value, environmentId: environment, fileId: null }
    if (!valid(original)) throw new Error('本地上传记录无效，本次尚未发送')
    retain(original)
    retryAllowed.value = false
    let receipt: UploadReceipt
    try {
      receipt = await fileApi.upload(credential, raw, original.key, original.environmentId)
    } catch (error) {
      if (current !== generation) return
      // Never discard a retained unknown/pending retry or a later file.read error.
      if (existing === null && discardRejectedNewUpload(original, error)) return
      throw error
    }
    if (current !== generation) return
    await acceptReceipt(receipt, original, credential, current)
  } catch (error) {
    if (current === generation) failure(error, '上传或读取结果尚待确认。请读取原上传结果，不要换请求重新上传；继续原上传时须选择同一文件。')
  } finally { if (current === generation) setBusy(false) }
}
async function recover(): Promise<void> {
  if (busy.value || !scope.value || !pending.value) return
  const current = generation; const credential = props.bearer; const original = pending.value
  if (original.scope !== scope.value || original.environmentId !== environment) return
  setBusy(true); retryAllowed.value = false; message.value = ''; problem.value = false
  try {
    const result = await fileApi.readUpload(credential, original.key, original.environmentId)
    if (current !== generation) return
    if (result.state === 'pending') { retryAllowed.value = true; message.value = '原上传还未完成，可选择同一文件继续原上传。'; return }
    await acceptReceipt(result.file, original, credential, current)
  } catch (error) {
    if (current === generation) failure(error, '原上传暂不可读。未找到记录不代表原请求未发送；请保留原记录并核对当前应用的读取授权。')
  } finally { if (current === generation) setBusy(false) }
}
watch(() => [props.bearer, props.environmentId, user.info.id, user.accessToken], () => { void initialize() }, { immediate: true, flush: 'sync' })
onUnmounted(() => { generation++; emit('busy', false) })
</script>
