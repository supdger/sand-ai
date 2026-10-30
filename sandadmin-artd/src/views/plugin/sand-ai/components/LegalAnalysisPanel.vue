<template>
  <section class="legal-panel">
    <h3>法律分析</h3>
    <p>为当前环境配置法律研究、诉讼准备、裁判分析与民事起诉状草稿。起诉状草稿须沿实际案件消费入口提交已确认事实与模板；其他模式可在此使用应用身份试运行。</p>
    <ElEmpty v-if="!environmentId" description="先选择应用和环境" :image-size="64" />
    <ElAlert v-else-if="!hasAuth('sand_ai:setup:legal-profile:read')" title="需要法律分析配置读取权限，请联系管理员" type="info" :closable="false" />
    <template v-else>
      <ElAlert v-if="error" :title="error" type="error" :closable="false" />
      <ElAlert v-if="notice" :title="notice" type="info" :closable="false" />
      <section v-if="pending" aria-labelledby="legal-operation-heading">
        <h4 id="legal-operation-heading">原{{ pending.operation === 'publish' ? '发布' : '撤销' }}操作待确认</h4>
        <p>{{ modeLabel(pending.code) }} · 版本 {{ pending.version }} · 原修订 {{ pending.observed_revision }} · 原请求号 {{ pending.request_key }}</p>
        <p>保留原号，仅查询这次操作。查询不到记录、读取失败或结果未明时，不重复发布或撤销。</p>
        <ElButton :loading="requests > 0" :disabled="busy" @click="recoverOriginal">只读查询原操作</ElButton>
      </section>
      <section v-if="completed" aria-labelledby="legal-operation-receipt-heading">
        <h4 id="legal-operation-receipt-heading">原操作已确认</h4>
        <p>{{ completed.operation === 'publish' ? '发布' : '撤销' }} · {{ modeLabel(completed.profile.code) }} · {{ completed.profile.version }} · 原修订 {{ completed.profile.revision }} · {{ stateLabel(completed.profile.state) }}</p>
        <p>原请求号 {{ completed.requestKey }}。下方列表单独显示当前状态。</p>
      </section>
      <ElSpace wrap class="toolbar">
        <ElButton :loading="busy" :disabled="busy" @click="load">刷新配置</ElButton>
        <ElButton v-for="template in templates" :key="template.code" v-permission="'sand_ai:setup:legal-profile:save'" :disabled="locked" @click="editor = { template }">新建{{ legalModes[template.code] }}版本</ElButton>
      </ElSpace>
      <ElTable :data="profiles" border empty-text="该环境尚无法律分析配置；请从上方模板创建草稿">
        <ElTableColumn prop="name" label="名称" min-width="150" />
        <ElTableColumn label="模式" min-width="110"><template #default="{ row }">{{ modeLabel(row.code) }}</template></ElTableColumn>
        <ElTableColumn prop="version" label="版本" width="100" />
        <ElTableColumn prop="revision" label="修订" width="70" />
        <ElTableColumn label="状态" width="90"><template #default="{ row }">{{ stateLabel(row.state) }}</template></ElTableColumn>
        <ElTableColumn label="操作" min-width="280"><template #default="{ row }">
          <ElButton v-if="row.state === 'draft'" v-permission="'sand_ai:setup:legal-profile:save'" link :disabled="locked" @click="edit(row)">编辑</ElButton>
          <ElButton v-if="row.state !== 'draft'" v-permission="'sand_ai:setup:legal-profile:save'" link :disabled="locked" @click="edit(row, true)">另建版本</ElButton>
          <ElButton v-if="row.state === 'draft'" v-permission="'sand_ai:setup:legal-profile:publish'" link type="primary" :disabled="locked" @click="transition(row, 'publish')">发布</ElButton>
          <ElButton v-if="row.state === 'published'" link type="primary" :disabled="locked" @click="trial = row">试运行</ElButton>
          <ElButton v-if="row.state === 'published'" v-permission="'sand_ai:setup:legal-profile:revoke'" link type="danger" :disabled="locked" @click="transition(row, 'revoke')">撤销</ElButton>
        </template></ElTableColumn>
      </ElTable>
      <LegalProfileEditor v-if="editor" :key="`${environmentId}:${editor.profile?.id ?? editor.template.code}`" :environment-id="environmentId" :template="editor.template" :profile="editor.profile" @close="editor = null" @saved="saved" />
      <LegalAnalysisTrial v-if="trial" :key="`${environmentId}:${trial.id}`" :environment-id="environmentId" :profile="trial" :hold-flight="holdAnalysisFlight" />
    </template>
  </section>
</template>
<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { ElMessageBox } from 'element-plus'
import { useAuth } from '@/hooks/core/useAuth'
import { useUserStore } from '@/store/modules/user'
import legalApi, { legalModes, type LegalConfig, type LegalMode, type LegalOperationReference, type LegalOperationReceipt, type LegalProfile } from '../api/legalAnalysis'
import LegalProfileEditor from './LegalProfileEditor.vue'
import LegalAnalysisTrial from './LegalAnalysisTrial.vue'
const props = defineProps<{ environmentId?: number }>()
const { hasAuth } = useAuth()
const userStore = useUserStore()
type TransitionJournal = LegalOperationReference & { journal_version: 1; admin_id: number; operation: 'publish' | 'revoke'; profile_id: number; observed_revision: number }
interface Scope { adminId: number; environmentId: number; token: string; epoch: number }
const profiles = ref<LegalProfile[]>([])
const templates = ref<LegalConfig[]>([])
const editor = ref<{ template: LegalConfig; profile?: LegalProfile } | null>(null)
const trial = ref<LegalProfile | null>(null)
const pending = ref<TransitionJournal | null>(null)
const completed = ref<{ operation: 'publish' | 'revoke'; requestKey: string; profile: LegalProfile } | null>(null)
const requests = ref(0), confirming = ref(false), journalError = ref(false)
const busy = computed(() => requests.value > 0 || confirming.value)
const locked = computed(() => busy.value || !!pending.value || journalError.value)
const error = ref(''), notice = ref('')
let epoch = 0, active = true, reloadWanted = false
function holdAnalysisFlight(): () => void {
  requests.value++
  let released = false
  return () => { if (!released) { released = true; requests.value-- } }
}
function modeLabel(code: string): string { return Object.entries(legalModes).find(([key]) => key === code)?.[1] ?? code }
function stateLabel(state: string): string { return ({ draft: '草稿', published: '已发布', revoked: '已撤销' } as Record<string, string>)[state] ?? state }
function scope(): Scope | null {
  const adminId = userStore.info.id, environmentId = props.environmentId
  return typeof adminId === 'number' && Number.isSafeInteger(adminId) && adminId > 0 && typeof environmentId === 'number' && Number.isSafeInteger(environmentId) && environmentId > 0 && userStore.accessToken ? { adminId, environmentId, token: userStore.accessToken, epoch } : null
}
function sameScope(original: Scope): boolean { return active && original.epoch === epoch && original.adminId === userStore.info.id && original.environmentId === props.environmentId && original.token === userStore.accessToken }
function storageKey(adminId: number, environmentId: number): string { return `sand-ai-legal-transition:1:${adminId}:${environmentId}` }
function isMode(value: unknown): value is LegalMode { return typeof value === 'string' && Object.prototype.hasOwnProperty.call(legalModes,value) }
function readJournal(adminId: number, environmentId: number): TransitionJournal | null {
  const saved = localStorage.getItem(storageKey(adminId,environmentId))
  if (!saved) return null
  const value: unknown = JSON.parse(saved)
  if (!value || typeof value !== 'object' || Array.isArray(value)) throw new Error('原操作记录不完整，请保留原请求号，不要重新提交')
  const item = value as Record<string, unknown>
  if (item.journal_version !== 1 || item.admin_id !== adminId || item.environment_id !== environmentId || (item.operation !== 'publish' && item.operation !== 'revoke') || !isMode(item.code) || typeof item.version !== 'string' || !/^\d+\.\d+\.\d+$/.test(item.version) || item.version.length > 32 || typeof item.request_key !== 'string' || !item.request_key || /[\s\u0000-\u001f\u007f]/u.test(item.request_key) || new TextEncoder().encode(item.request_key).length > 128 || typeof item.profile_id !== 'number' || !Number.isSafeInteger(item.profile_id) || item.profile_id < 1 || typeof item.observed_revision !== 'number' || !Number.isSafeInteger(item.observed_revision) || item.observed_revision < 1 || (item.operation === 'publish' ? item.expected_revision !== item.observed_revision : item.expected_revision !== null)) throw new Error('原操作记录归属或目标修订不完整，请保留原请求号，不要重新提交')
  return { journal_version: 1, admin_id: adminId, operation: item.operation, environment_id: environmentId, code: item.code, version: item.version, request_key: item.request_key, expected_revision: item.operation === 'publish' ? item.observed_revision : null, profile_id: item.profile_id, observed_revision: item.observed_revision }
}
function sameJournal(left: TransitionJournal | null, right: TransitionJournal): boolean {
  return !!left && left.admin_id === right.admin_id && left.operation === right.operation && left.environment_id === right.environment_id && left.code === right.code && left.version === right.version && left.request_key === right.request_key && left.expected_revision === right.expected_revision && left.profile_id === right.profile_id && left.observed_revision === right.observed_revision
}
function reference(original: TransitionJournal): LegalOperationReference {
  return { operation: original.operation, environment_id: original.environment_id, code: original.code, version: original.version, request_key: original.request_key, expected_revision: original.expected_revision }
}
function acceptOperation(answer: LegalOperationReceipt, original: TransitionJournal, current: Scope): void {
  if (!sameScope(current)) return
  if (answer.operation !== original.operation || answer.environment_id !== original.environment_id || answer.code !== original.code || answer.version !== original.version || answer.request_key !== original.request_key || answer.expected_revision !== original.expected_revision) throw new Error('回执与原人员环境、请求号或版本修订不一致，请保留原号')
  if (answer.state === 'unknown') {
    if (answer.actor_id !== null || answer.receipt !== null) throw new Error('未确认操作回执不完整，请保留原号')
    notice.value = '原操作尚未确认，请保留原号继续只读查询，不重复发布或撤销。'
    return
  }
  const receipt = answer.receipt
  if (answer.state !== 'completed' || answer.actor_id !== original.admin_id || !receipt || receipt.id !== original.profile_id || receipt.environment_id !== original.environment_id || receipt.code !== original.code || receipt.version !== original.version || receipt.revision !== original.observed_revision || (original.operation === 'publish' ? receipt.state !== 'published' || receipt.status !== 1 : receipt.state !== 'revoked' || receipt.status !== 2)) throw new Error('原操作回执与冻结的目标或修订不一致，请保留原号')
  const stored = readJournal(original.admin_id,original.environment_id)
  if (!sameJournal(stored,original) || !sameJournal(pending.value,original)) throw new Error('原操作记录已改变，请保留原号，不清除其他操作')
  localStorage.removeItem(storageKey(original.admin_id,original.environment_id))
  pending.value = null
  completed.value = { operation: original.operation, requestKey: original.request_key, profile: receipt }
  notice.value = '原操作已确认；正在单独刷新当前配置状态。'
  reloadWanted = true
}
async function readOriginal(original: TransitionJournal, current: Scope): Promise<void> {
  const result = await legalApi.readOperation(reference(original),original.admin_id)
  if (sameScope(current)) acceptOperation(result,original,current)
}
async function load(): Promise<void> {
  if (!active) return
  if (busy.value) { reloadWanted = true; return }
  const current = scope()
  if (!current || !hasAuth('sand_ai:setup:legal-profile:read')) return
  reloadWanted = false; requests.value++; if (!journalError.value) error.value = ''
  try {
    const [list, defaults] = await Promise.all([legalApi.list(current.environmentId),legalApi.templates()])
    if (!sameScope(current)) return
    if (list.profiles.some(row => row.environment_id !== current.environmentId)) throw new Error('配置列表与当前环境不一致，请刷新')
    profiles.value = list.profiles; templates.value = defaults.profiles
    if (trial.value && !profiles.value.some(item => item.id === trial.value?.id && item.state === 'published')) trial.value = null
    const original = pending.value
    if (original) await readOriginal(original,current)
  } catch (failure) { if (sameScope(current)) error.value = failure instanceof Error ? failure.message : '读取配置或原操作失败，请保留原请求号' }
  finally { requests.value-- }
}
async function recoverOriginal(): Promise<void> {
  const current = scope(), original = pending.value
  if (!current || !original || busy.value || !hasAuth('sand_ai:setup:legal-profile:read')) return
  requests.value++; error.value = ''
  try { await readOriginal(original,current) }
  catch (failure) { if (sameScope(current)) error.value = failure instanceof Error ? failure.message : '原操作尚未确认，请保留原号继续只读查询' }
  finally { requests.value-- }
}
async function edit(row: LegalProfile, copy = false): Promise<void> {
  const current = scope()
  if (!current || locked.value || row.environment_id !== current.environmentId) return
  requests.value++; error.value = ''
  try {
    const result = await legalApi.read(current.environmentId,row.code,row.version)
    if (!sameScope(current)) return
    if (result.profile.id !== row.id || result.profile.environment_id !== current.environmentId || result.profile.code !== row.code || result.profile.version !== row.version || !result.profile.config) throw new Error('配置目标或正文不一致，请刷新重试')
    editor.value = { template: result.profile.config, ...(copy ? {} : { profile: result.profile }) }
  } catch (failure) { if (sameScope(current)) error.value = failure instanceof Error ? failure.message : '读取版本失败' }
  finally { requests.value-- }
}
async function transition(row: LegalProfile, operation: 'publish' | 'revoke'): Promise<void> {
  const current = scope()
  if (!current || locked.value || row.environment_id !== current.environmentId || !hasAuth(`sand_ai:setup:legal-profile:${operation}`) || (operation === 'publish' ? row.state !== 'draft' : row.state !== 'published')) return
  const target: LegalProfile = { id: row.id, environment_id: row.environment_id, code: row.code, version: row.version, name: row.name, revision: row.revision, state: row.state, status: row.status }
  confirming.value = true; error.value = ''; notice.value = ''
  try {
    try { await ElMessageBox.confirm(operation === 'publish' ? `发布「${target.name}」${target.version} 修订 ${target.revision}？发布后不能修改本版本。` : `撤销「${target.name}」${target.version} 修订 ${target.revision}？使用该版本的新运行和尚未外发的任务将被拒绝。`,operation === 'publish' ? '发布配置' : '撤销配置',{ type: 'warning', confirmButtonText: operation === 'publish' ? '发布' : '撤销', cancelButtonText: '取消' }) }
    catch (reason) { if (reason === 'cancel' || reason === 'close') return; throw reason }
    if (!sameScope(current) || !hasAuth(`sand_ai:setup:legal-profile:${operation}`)) return
    const previous = readJournal(current.adminId,current.environmentId)
    if (previous) { pending.value = previous; throw new Error('已有原操作待确认，请先沿原号只读查询') }
    const original: TransitionJournal = { journal_version: 1, admin_id: current.adminId, operation, environment_id: current.environmentId, code: target.code, version: target.version, request_key: crypto.randomUUID(), expected_revision: operation === 'publish' ? target.revision : null, profile_id: target.id, observed_revision: target.revision }
    localStorage.setItem(storageKey(current.adminId,current.environmentId),JSON.stringify(original))
    pending.value = original; completed.value = null; editor.value = null; trial.value = null
    requests.value++
    try {
      await legalApi[operation](current.environmentId,target,original.request_key)
      if (sameScope(current)) await readOriginal(original,current)
    } finally { requests.value-- }
  } catch (failure) { if (sameScope(current)) error.value = failure instanceof Error ? failure.message : '操作尚未确认，请保留原号，只读查询，不要重复提交' }
  finally { confirming.value = false; if (reloadWanted && requests.value === 0 && active) void load() }
}
function saved(): void { editor.value = null; void load() }
watch([() => props.environmentId, () => userStore.info.id, () => userStore.accessToken, () => hasAuth('sand_ai:setup:legal-profile:read')], () => {
  epoch++; profiles.value = []; templates.value = []; editor.value = null; trial.value = null; pending.value = null; completed.value = null; error.value = ''; notice.value = ''; journalError.value = false
  const current = scope()
  if (current) {
    try { pending.value = readJournal(current.adminId,current.environmentId) }
    catch { journalError.value = true; error.value = '原操作记录不能读取，请保留原请求号，不要重新发布或撤销' }
  }
  reloadWanted = true; void load()
}, { immediate: true, flush: 'sync' })
watch(requests, count => { if (count === 0 && reloadWanted && !confirming.value && active) void load() }, { flush: 'sync' })
onBeforeUnmount(() => { active = false; epoch++; profiles.value = []; templates.value = []; editor.value = null; trial.value = null; pending.value = null; completed.value = null; error.value = ''; notice.value = '' })
</script>
<style scoped>
.legal-panel { margin-top: 24px; border-top: 1px solid var(--el-border-color); padding-top: 16px; }
.legal-panel p { color: var(--el-text-color-secondary); }
.toolbar { margin: 12px 0; }
</style>
