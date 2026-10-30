<template>
  <ElDialog :model-value="true" :title="profile ? '编辑法律分析草稿' : '创建法律分析版本'" width="min(600px, 94vw)" :close-on-click-modal="!locked" :close-on-press-escape="!locked" :show-close="!locked" @close="emit('close')">
    <ElAlert v-if="error" :title="error" type="error" :closable="false" />
    <ElAlert v-if="pending" title="原保存结果尚未确认。原模式、版本与请求号已保留，只读找回原回执；不会再次保存。" type="warning" :closable="false" />
    <ElButton v-if="pending || storageError" v-permission="'sand_ai:setup:legal-profile:read'" :loading="reading" :disabled="busy" @click="recover">只读找回原保存回执</ElButton>
    <ElForm v-if="ready && !pending" label-position="top" @submit.prevent="save">
      <ElFormItem label="分析模式"><ElInput :model-value="legalModes[draft.code]" disabled /></ElFormItem>
      <ElFormItem label="名称"><ElInput v-model.trim="draft.name" :disabled="busy" maxlength="160" /></ElFormItem>
      <ElFormItem label="版本"><ElInput v-model.trim="draft.version" :disabled="busy || !!profile" maxlength="32" placeholder="例如 1.0.1；已发布版本须另建新版本" /></ElFormItem>
      <ElFormItem label="输出语言"><ElSelect v-model="draft.output_language" :disabled="busy"><ElOption label="简体中文" value="zh-CN" /><ElOption label="English" value="en" /></ElSelect></ElFormItem>
      <ElFormItem label="输入字符上限"><ElInputNumber v-model="draft.max_prompt_characters" :disabled="busy" :min="1000" :max="200000" :precision="0" /></ElFormItem>
      <ElFormItem label="输出字符上限"><ElInputNumber v-model="draft.max_output_characters" :disabled="busy" :min="1000" :max="500000" :precision="0" /></ElFormItem>
      <p>所有成果均须人工复核。研究争点与检索式根据已确认事实生成待人工确认的研究争点和法律、案例检索式；诉讼准备生成提纲；起诉状草稿首包仅支持民事起诉状，须由消费应用提交已确认事实、当事人、诉求、证据和实际私有模板来源，不自动定稿或提交法院。</p>
    </ElForm>
    <template #footer><ElButton :disabled="locked" @click="emit('close')">取消</ElButton><ElButton v-permission="'sand_ai:setup:legal-profile:save'" type="primary" :loading="postFlight !== null" :disabled="busy || Boolean(pending) || storageError || !ready" @click="save">保存草稿</ElButton></template>
  </ElDialog>
</template>
<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, shallowRef, watch } from 'vue'
import { useUserStore } from '@/store/modules/user'
import legalApi, { legalModes, type LegalConfig, type LegalDraft, type LegalProfile, type LegalOperationReference } from '../api/legalAnalysis'
const props = defineProps<{ environmentId: number; template: LegalConfig; profile?: LegalProfile }>()
const emit = defineEmits<{ close: []; saved: [] }>()
const user = useUserStore()
const draft = reactive<LegalDraft>({ code: props.template.code, version: props.profile?.version ?? '', name: props.profile?.name ?? legalModes[props.template.code], output_language: props.template.output_language, max_prompt_characters: props.template.max_prompt_characters, max_output_characters: props.template.max_output_characters })
type SaveIntent = LegalOperationReference & { journal_version: 1; admin_id: number; target_id: number | null }
const pending = shallowRef<SaveIntent | null>(null)
const reading = ref(false)
const notSubmitted = shallowRef<SaveIntent | null>(null)
const postFlight = ref<number | null>(null)
const storageError = ref(false)
const ready = ref(Boolean(user.accessToken) && Number.isSafeInteger(Number(user.info.id)) && Number(user.info.id) > 0)
const busy = computed(() => reading.value || postFlight.value !== null)
const locked = computed(() => busy.value || Boolean(pending.value))
const error = ref('')
let epoch = 0
let flightSequence = 0
let active = true
function namespace(): string {
  const admin = Number(user.info.id)
  if (!user.accessToken || !Number.isSafeInteger(admin) || admin < 1 || !Number.isSafeInteger(props.environmentId) || props.environmentId < 1) throw new Error('当前管理员或环境不可用')
  return `sand-ai:legal-profile-save:v1:${admin}:${props.environmentId}`
}
function stored(): SaveIntent | null {
  const raw = localStorage.getItem(namespace())
  if (!raw) return null
  const value: unknown = JSON.parse(raw)
  if (typeof value !== 'object' || value === null || Array.isArray(value)) throw new Error('原保存记录不可用')
  const item = value as Record<string, unknown>
  if (Object.keys(item).some(key => !['journal_version','admin_id','operation','environment_id','code','version','request_key','expected_revision','target_id'].includes(key))
    || item.journal_version !== 1 || item.admin_id !== Number(user.info.id) || item.environment_id !== props.environmentId || item.operation !== 'draft_save'
    || typeof item.code !== 'string' || !Object.hasOwn(legalModes, item.code) || typeof item.version !== 'string' || item.version.length > 32 || !/^\d+\.\d+\.\d+$/.test(item.version)
    || typeof item.request_key !== 'string' || !/^[A-Za-z0-9._:-]{1,128}$/.test(item.request_key)
    || !(item.expected_revision === null || (typeof item.expected_revision === 'number' && Number.isSafeInteger(item.expected_revision) && item.expected_revision > 0))
    || !(item.target_id === null || (typeof item.target_id === 'number' && Number.isSafeInteger(item.target_id) && item.target_id > 0))
    || (item.target_id === null) !== (item.expected_revision === null)) throw new Error('原保存目标与身份未确认')
  return { journal_version: 1, admin_id: Number(item.admin_id), operation: 'draft_save', environment_id: props.environmentId,
    code: item.code as LegalConfig['code'], version: item.version, request_key: item.request_key,
    expected_revision: item.expected_revision, target_id: item.target_id }
}
function reference(original: SaveIntent): LegalOperationReference {
  return { operation: original.operation, environment_id: original.environment_id, code: original.code, version: original.version,
    request_key: original.request_key, expected_revision: original.expected_revision }
}
function current(original: SaveIntent, generation: number): boolean {
  return active && generation === epoch && Boolean(user.accessToken) && Number(user.info.id) === original.admin_id
    && props.environmentId === original.environment_id && pending.value?.request_key === original.request_key
}
function clearPrivate(): void {
  draft.name = ''; draft.version = ''; draft.output_language = 'zh-CN'
  draft.max_prompt_characters = 1000; draft.max_output_characters = 1000
}
function loadIntent(): void {
  pending.value = null; storageError.value = false
  if (!user.accessToken) return
  try { pending.value = stored(); if (pending.value) clearPrivate() }
  catch { storageError.value = true; error.value = '原保存记录无法读取，请恢复当前会话存储后只读找回；不会发送新请求。' }
}
async function readOriginal(original: SaveIntent, generation: number): Promise<void> {
  const result = await legalApi.readOperation(reference(original), original.admin_id)
  if (!current(original, generation)) return
  if (result.state !== 'completed' || !result.receipt) { error.value = '原保存暂无可证实回执。保留原号，只读找回；不能据此判断未保存。'; return }
  if (original.target_id !== null && result.receipt.id !== original.target_id) throw new Error('原保存回执对应另一配置，请保留原请求号')
  const marker = stored()
  if (!marker || marker.request_key !== original.request_key) throw new Error('原保存记录已变化，请保留原请求号')
  localStorage.removeItem(namespace())
  if (stored()) throw new Error('原保存锁未能解除')
  pending.value = null; storageError.value = false
  error.value = '原保存回执已确认；列表将另外读取当前版本状态。'
  emit('saved')
}
async function recover(): Promise<void> {
  if (!active || busy.value || !user.accessToken) return
  const generation = epoch
  reading.value = true
  try {
    const unsent = notSubmitted.value
    if (unsent && unsent.admin_id === Number(user.info.id) && unsent.environment_id === props.environmentId) {
      const marker = stored()
      if (marker && marker.request_key !== unsent.request_key) throw new Error('原记录已变化')
      if (marker) localStorage.removeItem(namespace())
      if (stored()) throw new Error('未发送记录无法解除')
      notSubmitted.value = null; pending.value = null; storageError.value = false; ready.value = false
      error.value = '本页确证原保存未发送，原记录已解除。请关闭后重新打开配置并确认输入。'
      return
    }
    loadIntent()
    const original = pending.value
    if (!original) { if (!storageError.value) error.value = '当前范围没有待找回的保存记录，请关闭后重新打开配置。'; return }
    await readOriginal(original, generation)
  } catch { if (active && generation === epoch) { clearPrivate(); error.value = '原保存回执未读回或当前权限拒绝。保留原目标和原号，只读恢复，不会重新保存。' } }
  finally { if (generation === epoch) reading.value = false }
}
async function save(): Promise<void> {
  if (!active || busy.value || pending.value || storageError.value || !ready.value || !user.accessToken) return
  if (!draft.name.trim() || draft.name.trim().length > 160 || !/^\d+\.\d+\.\d+$/.test(draft.version) || draft.version.length > 32
    || !Number.isInteger(draft.max_prompt_characters) || draft.max_prompt_characters < 1000 || draft.max_prompt_characters > 200000
    || !Number.isInteger(draft.max_output_characters) || draft.max_output_characters < 1000 || draft.max_output_characters > 500000) { error.value = '请填写名称、三段数字版本号及范围内的整数字符上限'; return }
  const generation = epoch; const flight = ++flightSequence
  const original: SaveIntent = { journal_version: 1, admin_id: Number(user.info.id), operation: 'draft_save', environment_id: props.environmentId,
    code: draft.code, version: draft.version, request_key: crypto.randomUUID(), expected_revision: props.profile?.revision ?? null, target_id: props.profile?.id ?? null }
  postFlight.value = flight; error.value = ''
  let payload: LegalDraft | undefined = { ...draft, name: draft.name.trim() }
  let dispatched = false
  try {
    if (stored()) { loadIntent(); error.value = '已有原保存意图，请先只读找回；不会再次发送。'; return }
    // Persist only metadata before dispatch; the raw configuration stays out of recovery storage.
    localStorage.setItem(namespace(), JSON.stringify(original))
    const marker = stored()
    if (!marker || marker.request_key !== original.request_key) throw new Error('无法保留原保存意图')
    pending.value = original
    clearPrivate()
    dispatched = true
    const response = legalApi.save(original.environment_id, original.expected_revision, payload, original.request_key)
    payload = undefined
    await response
    if (current(original, generation)) await readOriginal(original, generation)
  } catch { if (active && generation === epoch) {
    clearPrivate()
    if (dispatched) { pending.value = original; error.value = '原保存结果未确认。保留原版本与请求号，只读找回；不会再次保存。' }
    else { notSubmitted.value = original; storageError.value = true; loadIntent(); storageError.value = true; error.value = '原保存意图未能保留，本页确证尚未发送。恢复会话存储后点击找回，解除本页未发送记录并重新打开配置。' }
  } }
  finally {
    payload = undefined
    if (postFlight.value === flight) postFlight.value = null
  }
}
watch([() => user.info.id, () => user.accessToken, () => props.environmentId, () => props.profile?.id, () => props.profile?.revision, () => props.template.code, () => props.template.version], () => {
  epoch += 1; clearPrivate(); pending.value = null; notSubmitted.value = null; reading.value = false; storageError.value = false; ready.value = false
  error.value = user.accessToken ? '身份或目标版本已变化，请只读找回原范围记录，或关闭后重新打开配置。' : ''
  loadIntent()
}, { flush: 'sync' })
onMounted(loadIntent)
onBeforeUnmount(() => { active = false; epoch += 1; clearPrivate(); pending.value = null; notSubmitted.value = null })
</script>
