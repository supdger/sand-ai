<template>
  <ElCard shadow="never" class="trial">
    <template #header><h4>{{ legalModes[profile.code] }}试运行 · {{ profile.name }} {{ profile.version }}</h4></template>
    <p>使用当前环境的 SandIAM 应用凭证，需 file.read、workflow.run、workflow.read 授权。文件先在文件中心上传、解析，再从解析结果选择来源块。运行会调用模型并可能产生费用。</p>
    <ElAlert v-if="error" :title="error" type="error" :closable="false" class="notice" />
    <ElAlert v-if="requestKey && !runId" title="存在尚未确认的请求。请先找回原运行；不要更换请求号重复提交。" type="warning" :closable="false" class="notice" />
    <ElForm label-position="top" @submit.prevent>
      <ElFormItem label="应用 Bearer"><ElInput v-model="apiKey" type="password" show-password autocomplete="off" :disabled="busy" placeholder="仅用于当前页面，不保存凭证" /></ElFormItem>
      <template v-if="!requestKey && !runId">
        <ElFormItem label="原请求号（找回已有分析）"><ElInput v-model="recoveryKey" :disabled="busy || reading" placeholder="粘贴原提交时保存的请求号" /></ElFormItem>
        <ElSpace wrap class="actions"><ElButton :disabled="busy || reading || !apiKey.trim() || !recoveryKey.trim()" :loading="busy" @click="recoverKnownRequest">只读找回原分析</ElButton><span>需当前应用凭证及 workflow.read 授权；只读取原运行，不提交新的分析。</span></ElSpace>
      </template>
      <ElSpace v-if="requestKey" wrap class="actions"><span>请求号：{{ requestKey }}</span><ElButton :disabled="busy || !apiKey.trim()" :loading="busy" @click="recover">找回原运行</ElButton></ElSpace>
      <ElAlert v-if="profile.code === 'complaint_drafting'" title="起诉状草稿须沿实际案件消费入口填写当事人、请求、证据、模板与来源并明确确认；此处仅供原号只读查询。" type="info" :closable="false" />
      <template v-if="!runId && profile.code !== 'complaint_drafting'">
        <fieldset :disabled="locked">
          <legend>研究问题与业务范围</legend>
          <ElFormItem label="研究问题（每行一个，最多 5 个，每项 500 字）"><ElInput v-model="issuesText" type="textarea" :rows="3" :disabled="locked" placeholder="填写本次需要回答的具体问题" /></ElFormItem>
          <div class="fields"><ElFormItem label="法域"><ElInput v-model.trim="jurisdiction" maxlength="128" :disabled="locked" placeholder="由业务方确认适用法域" /></ElFormItem><ElFormItem label="案件类型"><ElInput v-model.trim="caseType" maxlength="128" :disabled="locked" /></ElFormItem><ElFormItem label="研究时点"><ElDatePicker v-model="asOfDate" type="date" value-format="YYYY-MM-DD" :disabled="locked" /></ElFormItem></div>
          <div class="fields"><ElFormItem label="业务范围引用"><ElInput v-model.trim="scope.ref" maxlength="512" :disabled="locked" placeholder="业务系统的案件或研究项目引用" /></ElFormItem><ElFormItem label="资料快照引用"><ElInput v-model.trim="scope.snapshot_ref" maxlength="512" :disabled="locked" placeholder="业务系统本次确认的资料集合" /></ElFormItem><ElFormItem label="资料快照版本"><ElInput v-model.trim="scope.snapshot_version" maxlength="512" :disabled="locked" /></ElFormItem></div>
        </fieldset>
        <h4>法律来源</h4><p>效力状态全部为消费者声明，包括“声明有效”也不代表官方核验。每次最多 10 个来源、40 个来源块。</p>
        <article v-for="(source, index) in sources" :key="source.key" class="source-form">
          <div class="fields"><ElFormItem label="文件 ID"><ElInputNumber v-model="source.fileId" :min="1" :precision="0" :disabled="locked" /></ElFormItem><ElFormItem label="法源引用"><ElInput v-model.trim="source.sourceRef" maxlength="512" :disabled="locked" placeholder="法条、司法解释或裁判文书引用" /></ElFormItem><ElFormItem label="来源版本"><ElInput v-model.trim="source.sourceVersion" maxlength="512" :disabled="locked" placeholder="业务方确认的来源版本" /></ElFormItem></div>
          <div class="fields"><ElFormItem label="文件版本引用"><ElInput v-model.trim="source.versionRef" maxlength="512" :disabled="locked" placeholder="业务系统固定的文件版本引用" /></ElFormItem><ElFormItem label="来源块 ID"><ElInput v-model.trim="source.blockIds" :disabled="locked" placeholder="从文件解析结果获取，逗号分隔" /></ElFormItem><ElFormItem label="声明的效力"><ElSelect v-model="source.effect" :disabled="locked"><ElOption v-for="(label, value) in effectLabels" :key="value" :label="label" :value="value" /></ElSelect></ElFormItem></div>
          <ElButton type="danger" link :disabled="locked || sources.length === 1" @click="sources.splice(index, 1)">移除此来源</ElButton>
        </article>
        <ElSpace wrap class="actions"><ElButton :disabled="locked || sources.length >= 10" @click="addSource">添加来源</ElButton><ElButton :disabled="locked || !apiKey.trim()" :loading="busy" @click="previewSources">读取并核对来源快照</ElButton><ElButton link type="primary" @click="router.push('/sand-ai/file')">打开文件中心</ElButton></ElSpace>
        <ElCollapse v-if="snapshots.length">
          <ElCollapseItem v-for="source in snapshots" :key="source.source_ref" :title="`${source.source_ref} · ${source.source_version} · ${effectLabels[source.declared_effect_status]}`" :name="source.source_ref">
            <p>文件 #{{ source.file_id }} · 解析 #{{ source.parse_id }} · 消费者声明</p>
            <article v-for="block in source.blocks" :key="block.source_block_id"><strong>来源块 #{{ block.source_block_id }}</strong><p>{{ block.locator_type }} · {{ JSON.stringify(block.locator) }}</p><p class="source-content">{{ block.content }}</p></article>
          </ElCollapseItem>
        </ElCollapse>
        <h4>已确认事实（可选）</h4><p>确认责任在业务方。每项事实引用 1–8 个本次已核对的来源块，最多 30 项。</p>
        <article v-for="(fact, index) in facts" :key="fact.key" class="source-form"><div class="fields"><ElFormItem label="事实引用"><ElInput v-model.trim="fact.fact_ref" :disabled="locked" maxlength="512" /></ElFormItem><ElFormItem label="事实版本"><ElInput v-model.trim="fact.fact_version" :disabled="locked" maxlength="512" /></ElFormItem></div><ElFormItem label="已确认事实"><ElInput v-model.trim="fact.summary" type="textarea" :disabled="locked" maxlength="2000" /></ElFormItem><ElFormItem label="来源块"><ElSelect v-model="fact.source_block_ids" multiple :multiple-limit="8" :disabled="locked" placeholder="先读取法律来源快照"><ElOption v-for="block in availableBlocks" :key="block.id" :value="block.id" :label="block.label" /></ElSelect></ElFormItem><ElButton link type="danger" :disabled="locked" @click="facts.splice(index, 1)">移除此事实</ElButton></article>
        <ElButton :disabled="locked || facts.length >= 30" @click="addFact">添加已确认事实</ElButton>
        <p><ElCheckbox v-model="confirmed" :disabled="locked">已核对来源与事实；理解成果需要人工复核{{ profile.code === 'litigation_preparation' ? '，本次只生成诉讼提纲' : '' }}</ElCheckbox></p>
        <ElSpace wrap><ElButton type="primary" :loading="busy" :disabled="busy || journalError || !!requestKey || !!recoveryKey.trim() || !apiKey.trim() || !confirmed || !preparedSources.length" @click="submit">{{ requestKey ? '原请求仅可查询' : '提交分析' }}</ElButton></ElSpace>
      </template>
    </ElForm>
    <template v-if="runId">
      <ElSpace wrap class="actions"><strong>运行 #{{ runId }} · {{ run ? stateLabel(run.state) : '待读取' }}</strong><ElButton :loading="reading" :disabled="reading || !apiKey.trim()" @click="refreshRun">刷新结果</ElButton><ElButton v-if="canReplayAuthorization" type="primary" :loading="busy" :disabled="busy || reading || !apiKey.trim()" @click="submit">原请求重新授权</ElButton><ElButton :disabled="busy || reading" @click="startNew">新建一次试运行</ElButton></ElSpace>
      <template v-if="run"><p>计量状态：{{ stateLabel(run.usage.state) }} · 输入 Token：{{ run.usage.input_tokens ?? '未知' }} · 输出 Token：{{ run.usage.output_tokens ?? '未知' }}</p><p>费用状态：{{ stateLabel(run.cost.state) }} · {{ run.cost.metered_amount ?? '未知' }} {{ run.cost.currency ?? '' }}</p><p v-if="run.task?.error_code">任务错误：{{ run.task.error_code }}</p><LegalAnalysisResult v-if="run.result" :payload="run.result.payload" @open-source="openSource" /><ElEmpty v-else description="成果尚未生成，可稍后刷新运行状态" :image-size="64" /></template>
    </template>
    <section class="history-read" :aria-labelledby="historyHeadingId" :aria-busy="historyReading">
      <h4 :id="historyHeadingId">独立只读查看原分析</h4>
      <p>当前分析正在提交或尚未确认时，也可用原请求号查看历史成果、原运行计量与费用、私有来源。此处只查询，不改变当前请求、输入、确认或草稿，不提交或重新授权。</p>
      <label :for="historyHeadingId + '-key'">历史原请求号</label><ElInput :id="historyHeadingId + '-key'" v-model="historyKey" autocomplete="off" placeholder="填写要查询的原分析请求号" />
      <ElSpace wrap class="actions"><ElButton :loading="historyReading" :disabled="historyReading || !apiKey.trim() || !historyKey.trim()" @click="readHistory">只读查询／刷新历史原分析</ElButton><span>使用本页当前应用凭证与 workflow.read 授权；重复刷新也只执行 GET。</span></ElSpace>
      <ElAlert v-if="historyError" :title="historyError" type="error" :closable="false" />
      <template v-if="historyRun">
        <p>原请求号 {{ historyResolvedKey }} · 原运行 #{{ historyRun.run_id }} · {{ stateLabel(historyRun.state) }}</p>
        <p>原分析配置：{{ historyRun.analysis_profile?.code ?? '未返回配置' }} · {{ historyRun.analysis_profile?.version ?? '未返回版本' }}</p>
        <p>原运行计量状态：{{ stateLabel(historyRun.usage.state) }} · 输入 Token：{{ historyRun.usage.input_tokens ?? '未知' }} · 输出 Token：{{ historyRun.usage.output_tokens ?? '未知' }}</p>
        <p>原运行费用状态：{{ stateLabel(historyRun.cost.state) }} · {{ historyRun.cost.metered_amount ?? '未知' }} {{ historyRun.cost.currency ?? '' }}</p>
        <p>费用与计量来自此原运行返回的实际投影；未返回金额时显示未知。原请求号和运行号不能用作模型调用费用标识。</p>
        <p v-if="historyRun.task?.error_code">原任务错误：{{ historyRun.task.error_code }}</p>
        <LegalAnalysisResult v-if="historyRun.result" :payload="historyRun.result.payload" @open-source="openHistorySource" />
        <ElEmpty v-else description="此原运行尚未返回成果，可只读刷新状态" :image-size="64" />
      </template>
    </section>
    <ElDialog v-model="historySourceOpen" title="历史原分析的私有来源" width="min(720px, 94vw)">
      <p v-if="historySourceBusy">正在重新核验原运行来源权限…</p><ElAlert v-else-if="historySourceError" :title="historySourceError" type="error" :closable="false" />
      <template v-else-if="historySourcePreview"><p>原运行 #{{ historyRun?.run_id }} · 文件 #{{ historySourcePreview.file_id }} · 来源块 #{{ historySourcePreview.source_block_id }}</p><MaterialSourceContent :source="historySourcePreview" /></template>
    </ElDialog>
    <ElDialog v-model="sourceOpen" title="成果来源" width="min(720px, 94vw)"><p v-if="sourceBusy">正在重新核验权限并读取来源…</p><ElAlert v-else-if="sourceError" :title="sourceError" type="error" :closable="false" /><template v-else-if="sourcePreview"><p>文件 #{{ sourcePreview.file_id }} · 来源块 #{{ sourcePreview.source_block_id }}</p><MaterialSourceContent :source="sourcePreview" /></template></ElDialog>
  </ElCard>
</template>
<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import legalApi, { effectLabels, legalModes, type EffectStatus, type LegalFact, type LegalProfile, type LegalRunInput, type LegalSnapshot, type LegalSource } from '../api/legalAnalysis'
import fileApi from '../api/file'
import taskApi from '../api/task'
import { newRuntimeRequestId } from '../api/runtime'
import { useUserStore } from '@/store/modules/user'
import type { SandAiWorkflowRun, SandAiWorkflowSource } from '../api/types'
import LegalAnalysisResult from './LegalAnalysisResult.vue'
import MaterialSourceContent from './MaterialSourceContent.vue'
const props = defineProps<{ environmentId: number; profile: LegalProfile; holdFlight?: () => () => void }>()
const router = useRouter()
const userStore = useUserStore()
const apiKey = ref('')
const issuesText = ref('')
const jurisdiction = ref('')
const caseType = ref('')
const asOfDate = ref('')
const scope = reactive({ ref: '', snapshot_ref: '', snapshot_version: '' })
let rowKey = 0
const sources = ref<Array<{ key: number; fileId: number | undefined; sourceRef: string; sourceVersion: string; versionRef: string; blockIds: string; effect: EffectStatus }>>([])
const facts = ref<Array<LegalFact & { key: number }>>([])
const preparedSources = ref<LegalSource[]>([])
const snapshots = ref<LegalSnapshot[]>([])
const confirmed = ref(false)
const operationFlights = ref(0)
const busy = computed(() => operationFlights.value > 0)
const reading = ref(false)
const error = ref('')
const requestKey = ref('')
const recoveryKey = ref('')
const runId = ref<number | null>(null)
const run = ref<SandAiWorkflowRun | null>(null)
const pending = ref<LegalRunInput | null>(null)
interface RunJournal { journal_version: 1; admin_id: number; environment_id: number; profile_id: number; code: LegalProfile['code']; version: string; revision: number; request_key: string; input_sha256: string }
const journal = ref<RunJournal | null>(null), journalError = ref(false)
const sourceOpen = ref(false)
const sourceBusy = ref(false)
const sourceError = ref('')
const sourcePreview = ref<SandAiWorkflowSource | null>(null)
const historyHeadingId = computed(() => `sand-ai-legal-history:${props.environmentId}:${props.profile.id}`)
const historyKey = ref('')
const historyResolvedKey = ref('')
const historyRun = ref<SandAiWorkflowRun | null>(null)
const historyReading = ref(false)
const historyError = ref('')
const historySourceOpen = ref(false)
const historySourceBusy = ref(false)
const historySourceError = ref('')
const historySourcePreview = ref<SandAiWorkflowSource | null>(null)
let historyGeneration = 0
let historySourceGeneration = 0
interface ReadContext { credential: string; adminId: number | undefined; adminToken: string; environmentId: number; profileId: number; profileCode: LegalProfile['code']; profileVersion: string; profileRevision: number }
function captureReadContext(): ReadContext { return { credential: apiKey.value, adminId: userStore.info.id, adminToken: userStore.accessToken, environmentId: props.environmentId, profileId: props.profile.id, profileCode: props.profile.code, profileVersion: props.profile.version, profileRevision: props.profile.revision } }
function sameReadContext(context: ReadContext): boolean { return active && context.adminId === userStore.info.id && context.adminToken === userStore.accessToken && context.credential === apiKey.value && context.environmentId === props.environmentId && context.profileId === props.profile.id && context.profileCode === props.profile.code && context.profileVersion === props.profile.version && context.profileRevision === props.profile.revision }
let active = true
let generation = 0
let sourceGeneration = 0
let timer: ReturnType<typeof setTimeout> | null = null
const storageKey = computed(() => `sand-ai-legal-run:${userStore.info.id ?? 'unavailable'}:${props.environmentId}:${props.profile.code}:${props.profile.version}`)
function beginFlight(): () => void {
  operationFlights.value++
  const releaseParent = props.holdFlight?.()
  let released = false
  return () => { if (!released) { released = true; operationFlights.value--; releaseParent?.() } }
}
function restoreKey(): void {
  requestKey.value = ''; journal.value = null; journalError.value = false
  const adminId = userStore.info.id
  if (typeof adminId !== 'number' || !Number.isSafeInteger(adminId) || adminId < 1) return
  try {
    const raw = sessionStorage.getItem(storageKey.value + ':intent')
    if (!raw) {
      requestKey.value = sessionStorage.getItem(storageKey.value) ?? sessionStorage.getItem(`sand-ai-legal-run:${props.environmentId}:${props.profile.code}:${props.profile.version}`) ?? ''
      return
    }
    const value: unknown = JSON.parse(raw)
    if (!value || typeof value !== 'object' || Array.isArray(value)) throw new Error('原分析记录不完整')
    const item = value as Record<string, unknown>
    if (item.journal_version !== 1 || item.admin_id !== userStore.info.id || item.environment_id !== props.environmentId || item.profile_id !== props.profile.id || item.code !== props.profile.code || item.version !== props.profile.version || typeof item.revision !== 'number' || !Number.isSafeInteger(item.revision) || item.revision < 1 || typeof item.request_key !== 'string' || !item.request_key || /[\s\u0000-\u001f\u007f]/u.test(item.request_key) || new TextEncoder().encode(item.request_key).length > 128 || typeof item.input_sha256 !== 'string' || !/^sha256:[a-f0-9]{64}$/.test(item.input_sha256)) throw new Error('原分析记录与当前人员、环境或固定版本不一致')
    journal.value = { journal_version: 1, admin_id: adminId, environment_id: props.environmentId, profile_id: props.profile.id, code: props.profile.code, version: props.profile.version, revision: item.revision, request_key: item.request_key, input_sha256: item.input_sha256 }
    requestKey.value = item.request_key
  } catch { journalError.value = true; error.value = '原分析记录不能读取，请保留原请求号，不要提交新分析' }
}
restoreKey()
const locked = computed(() => props.profile.code === 'complaint_drafting' || busy.value || journalError.value || !!requestKey.value || !!recoveryKey.value.trim())
const canReplayAuthorization = computed(() => !!pending.value && !!journal.value && journal.value.request_key === requestKey.value && run.value?.analysis_profile?.code === journal.value.code && run.value?.analysis_profile?.version === journal.value.version && !!run.value?.task
  && (run.value.task.state === 'authorization_pending'
    || (run.value.task.state === 'failed' && run.value.task.attempt_count === 0)))
const availableBlocks = computed(() => snapshots.value.flatMap(source => source.blocks.map(block => ({ id: block.source_block_id, label: `#${block.source_block_id} · ${source.source_ref}` }))))
function addSource(): void { sources.value.push({ key: ++rowKey, fileId: undefined, sourceRef: '', sourceVersion: '', versionRef: '', blockIds: '', effect: 'unknown' }) }
function addFact(): void { facts.value.push({ key: ++rowKey, fact_ref: '', fact_version: '', summary: '', source_block_ids: [] }) }
addSource()
function keepKey(key: string): void { requestKey.value = key; try { if (key) sessionStorage.setItem(storageKey.value, key); else { sessionStorage.removeItem(storageKey.value); sessionStorage.removeItem(storageKey.value + ':intent'); journal.value = null } } catch { /* The visible key and in-memory request remain available. */ } }
async function inputDigest(input: LegalRunInput): Promise<string> {
  const digest = await crypto.subtle.digest('SHA-256',new TextEncoder().encode(JSON.stringify(input)))
  return 'sha256:' + [...new Uint8Array(digest)].map(byte => byte.toString(16).padStart(2,'0')).join('')
}
function clearTimer(): void { if (timer) clearTimeout(timer); timer = null }
function clearSource(): void {
  ++sourceGeneration
  sourceOpen.value = false; sourceBusy.value = false; sourceError.value = ''; sourcePreview.value = null
}
function clearReadableContent(): void { run.value = null; clearSource() }
function resetReading(): void {
  ++generation; clearTimer(); clearReadableContent()
  runId.value = null; reading.value = false; pending.value = null
  error.value = ''; preparedSources.value = []; snapshots.value = []; confirmed.value = false
}
function clearPrivateInput(): void {
  issuesText.value = ''; jurisdiction.value = ''; caseType.value = ''; asOfDate.value = ''
  scope.ref = ''; scope.snapshot_ref = ''; scope.snapshot_version = ''
  sources.value = []; facts.value = []; addSource()
}
function parseIds(text: string): number[] {
  const tokens = text.split(/[,，\s]+/).filter(Boolean)
  const ids = tokens.map(Number)
  if (!tokens.length || tokens.some(token => !/^[1-9]\d*$/.test(token)) || ids.some(id => !Number.isSafeInteger(id)) || new Set(ids).size !== ids.length) throw new Error('来源块 ID 必须为不重复的正整数')
  return ids
}
async function previewSources(): Promise<void> {
  if (busy.value || locked.value || !apiKey.value.trim()) return
  const current = ++generation, context = captureReadContext(), release = beginFlight()
  error.value = ''; preparedSources.value = []; snapshots.value = []; confirmed.value = false
  try {
    const selected = sources.value.map(source => {
      if (!Number.isSafeInteger(source.fileId) || !source.fileId || source.fileId < 1 || !source.sourceRef || !source.sourceVersion || !source.versionRef) throw new Error('请填写每项来源的文件 ID、法源引用、来源版本和文件版本引用')
      return { ...source, fileId: source.fileId, ids: parseIds(source.blockIds) }
    })
    const ids = selected.flatMap(source => source.ids)
    if (ids.length > 40 || new Set(ids).size !== ids.length || new Set(selected.map(source => source.sourceRef)).size !== selected.length) throw new Error('法源引用与来源块不得重复，来源块总数不得超过 40')
    const fileResults = await Promise.allSettled([...new Set(selected.map(source => source.fileId))].map(id => fileApi.read(context.credential, id)))
    if (current !== generation || !sameReadContext(context)) return
    const files = fileResults.map(result => { if (result.status === 'rejected') throw result.reason; return result.value })
    const fixed: LegalSource[] = selected.map(source => {
      const file = files.find(file => file.id === source.fileId)
      if (!file || file.state !== 'parsed' || file.latest_parse?.state !== 'succeeded' || !/^[a-f0-9]{64}$/.test(file.sha256)) throw new Error(`文件 #${source.fileId} 尚未成功解析或快照无效`)
      return { source_ref: source.sourceRef, source_version: source.sourceVersion, declared_effect_status: source.effect, file_id: file.id, file_sha256: file.sha256, parse_id: file.latest_parse.id, version_ref: source.versionRef, source_block_ids: source.ids }
    })
    for (const source of fixed) if (fixed.some(other => other.file_id === source.file_id && other.version_ref !== source.version_ref)) throw new Error('同一文件的版本引用必须一致')
    const result = await legalApi.snapshot(context.credential, fixed)
    if (current !== generation || !sameReadContext(context)) return
    preparedSources.value = fixed; snapshots.value = result.sources
  } catch (failure) { if (current === generation && sameReadContext(context)) error.value = failure instanceof Error ? failure.message : '读取来源失败' }
  finally { release() }
}
function buildInput(): LegalRunInput {
  const issues = issuesText.value.split('\n').map(issue => issue.trim()).filter(Boolean)
  if (!issues.length || issues.length > 5 || issues.some(issue => [...issue].length > 500) || !jurisdiction.value || !caseType.value || !asOfDate.value || !scope.ref || !scope.snapshot_ref || !scope.snapshot_version) throw new Error('请完整填写研究问题、法域、案件类型、研究时点和业务快照')
  const allowed = new Set(preparedSources.value.flatMap(source => source.source_block_ids))
  const fixedFacts = facts.value.map(({ fact_ref, fact_version, summary, source_block_ids }) => {
    if (!fact_ref || !fact_version || !summary || source_block_ids.length < 1 || source_block_ids.length > 8 || source_block_ids.some(id => !allowed.has(id))) throw new Error('每项事实须有引用、版本、内容及本次来源块；来源修改后请重新核对事实引用')
    return { fact_ref, fact_version, summary, source_block_ids: [...source_block_ids] }
  })
  if (new Set(fixedFacts.map(fact => fact.fact_ref)).size !== fixedFacts.length) throw new Error('事实引用不能重复')
  if (!confirmed.value || !preparedSources.value.length) throw new Error('请先读取来源快照并确认复核要求')
  return { business_scope: { ...scope }, workflow: { code: 'legal_analysis', version: '1.0.0' }, output_schema: { code: 'sand_ai.legal_analysis', version: '1.0.0' }, files: [...new Map(preparedSources.value.map(source => [source.file_id, { file_id: source.file_id, sha256: source.file_sha256, version_ref: source.version_ref }])).values()], legal_profile: { code: props.profile.code, version: props.profile.version }, legal_context: { issues, jurisdiction: jurisdiction.value, case_type: caseType.value, as_of_date: asOfDate.value }, legal_sources: preparedSources.value.map(source => ({ ...source, source_block_ids: [...source.source_block_ids] })), confirmed_facts: fixedFacts, review_required: true }
}
async function submit(): Promise<void> {
  if (props.profile.code === 'complaint_drafting') { error.value = '请沿实际案件消费入口提交完整民事起诉状起草输入'; return }
  const adminId = userStore.info.id
  if (busy.value || reading.value || journalError.value || !apiKey.value.trim() || typeof adminId !== 'number' || !Number.isSafeInteger(adminId) || adminId < 1 || !userStore.accessToken || !!recoveryKey.value.trim() || (requestKey.value && !canReplayAuthorization.value)) return
  error.value = ''
  try { if (!pending.value) pending.value = buildInput() } catch (failure) { error.value = failure instanceof Error ? failure.message : '请检查输入'; return }
  const input = pending.value
  if (!input) return
  const current = ++generation, context = captureReadContext(), release = beginFlight(), keyStore = storageKey.value
  try {
    const digest = await inputDigest(input)
    if (current !== generation || !sameReadContext(context)) return
    let original = journal.value
    if (requestKey.value) {
      if (!original || original.request_key !== requestKey.value || original.admin_id !== adminId || original.environment_id !== context.environmentId || original.profile_id !== context.profileId || original.code !== context.profileCode || original.version !== context.profileVersion || original.revision !== context.profileRevision || original.input_sha256 !== digest) throw new Error('缺少原输入与固定版本的采用记录，请仅沿原号查询，不重新授权')
    } else {
      original = { journal_version: 1, admin_id: adminId, environment_id: context.environmentId, profile_id: context.profileId, code: context.profileCode, version: context.profileVersion, revision: context.profileRevision, request_key: newRuntimeRequestId('legal-analysis'), input_sha256: digest }
      sessionStorage.setItem(keyStore + ':intent',JSON.stringify(original))
      journal.value = original; original = journal.value; keepKey(original.request_key)
    }
    const result = await legalApi.submit(context.credential,input,original.request_key)
    if (current !== generation || !sameReadContext(context) || requestKey.value !== original.request_key || journal.value !== original) return
    if (!Number.isSafeInteger(result.run?.run_id) || result.run.run_id < 1) throw new Error('响应缺少运行标识，请用原请求号找回')
    runId.value = result.run.run_id
    void refreshRun()
  } catch (failure) { if (current === generation && sameReadContext(context)) error.value = failure instanceof Error ? failure.message : '提交结果未确认，请找回原运行' }
  finally { release() }
}
function acceptRun(value: SandAiWorkflowRun): void {
  if (!Number.isSafeInteger(value.run_id) || value.run_id < 1 || value.workflow.code !== 'legal_analysis' || value.workflow.version !== '1.0.0' || (value.result && value.result.schema.code !== 'sand_ai.legal_analysis')) throw new Error('返回的运行与法律分析契约不符')
  runId.value = value.run_id; run.value = value
}
async function recoverKnownRequest(): Promise<void> {
  if (busy.value || reading.value || requestKey.value || runId.value || pending.value || !apiKey.value.trim()) return
  const key = recoveryKey.value.trim()
  if (!key || [...key].length > 128) { error.value = '原请求号须为 1–128 个字符'; return }
  keepKey(key)
  recoveryKey.value = ''
  preparedSources.value = []; snapshots.value = []; confirmed.value = false
  await recover()
}
async function recover(): Promise<void> {
  if (busy.value || !requestKey.value || !apiKey.value.trim()) return
  const current = ++generation, context = captureReadContext(), key = requestKey.value, release = beginFlight()
  clearTimer(); clearReadableContent(); reading.value = false; error.value = ''
  try { const result = await legalApi.recover(context.credential,key); if (current === generation && sameReadContext(context) && requestKey.value === key) { acceptRun(result); schedule() } }
  catch (failure) { if (current === generation && sameReadContext(context) && requestKey.value === key) error.value = (failure instanceof Error ? failure.message : '找回失败') + '。原请求仍未确认，请保留原号继续只读查询，不重复提交。' }
  finally { release() }
}
function schedule(): void { clearTimer(); if (run.value && document.visibilityState !== 'hidden' && (['authorization_pending', 'pending', 'queued', 'running', 'retrying', 'recovery_pending'].includes(run.value.state) || run.value.cost.state === 'pending')) timer = setTimeout(() => { void refreshRun() }, 4000) }
async function refreshRun(): Promise<void> {
  if (reading.value || !runId.value || !apiKey.value.trim()) return
  const current = generation; const id = runId.value
  clearTimer(); clearReadableContent(); reading.value = true
  try { const result = await taskApi.readWorkflow(apiKey.value, id); if (current !== generation) return; if (result.run_id !== id) throw new Error('运行标识不一致'); acceptRun(result); error.value = ''; schedule() }
  catch (failure) { if (current === generation) { run.value = null; error.value = failure instanceof Error ? failure.message : '读取运行失败' } }
  finally { if (current === generation) reading.value = false }
}
async function openSource(id: number): Promise<void> {
  if (!runId.value || !run.value?.result || reading.value || !apiKey.value.trim() || !Number.isSafeInteger(id) || id < 1) return
  const current = ++sourceGeneration
  const context = generation; const originalRunId = runId.value; const originalRun = run.value; const readContext = captureReadContext()
  const stillCurrent = () => current === sourceGeneration && context === generation && sameReadContext(readContext) && runId.value === originalRunId && run.value === originalRun
  sourceOpen.value = true; sourceBusy.value = true; sourceError.value = ''; sourcePreview.value = null
  try { const result = await taskApi.readWorkflowSource(readContext.credential, originalRunId, id, readContext.environmentId); if (stillCurrent() && run.value?.result) { if (result.source_block_id !== id) throw new Error('来源块标识不一致'); sourcePreview.value = result } }
  catch (failure) { if (stillCurrent()) { run.value = null; sourcePreview.value = null; sourceError.value = failure instanceof Error ? failure.message : '读取来源失败' } }
  finally { if (current === sourceGeneration && context === generation && sameReadContext(readContext) && runId.value === originalRunId) sourceBusy.value = false }
}
function clearHistorySource(): void {
  ++historySourceGeneration
  historySourceOpen.value = false; historySourceBusy.value = false; historySourceError.value = ''; historySourcePreview.value = null
}
function clearHistory(): void {
  ++historyGeneration; clearHistorySource()
  historyRun.value = null; historyResolvedKey.value = ''; historyReading.value = false; historyError.value = ''
}
function validateHistoryRun(value: SandAiWorkflowRun): void {
  if (!Number.isSafeInteger(value.run_id) || value.run_id < 1 || value.workflow.code !== 'legal_analysis' || value.workflow.version !== '1.0.0' || value.result && value.result.schema.code !== 'sand_ai.legal_analysis') throw new Error('原运行不符合现法律分析成果契约')
}
async function readHistory(): Promise<void> {
  if (historyReading.value || !apiKey.value.trim()) return
  const key = historyKey.value.trim()
  clearHistory()
  if (!key || [...key].length > 128) { historyError.value = '历史原请求号须为 1–128 个字符'; return }
  const current = historyGeneration, context = captureReadContext()
  const stillCurrent = () => current === historyGeneration && sameReadContext(context) && historyKey.value.trim() === key
  historyReading.value = true
  try {
    const result = await legalApi.recover(context.credential, key)
    if (!stillCurrent()) return
    validateHistoryRun(result)
    historyResolvedKey.value = key; historyRun.value = result
  } catch (failure) {
    if (stillCurrent()) { historyRun.value = null; historyResolvedKey.value = ''; clearHistorySource(); historyError.value = failure instanceof Error ? failure.message : '历史原分析读取失败，未重新提交' }
  } finally { if (stillCurrent()) historyReading.value = false }
}
async function openHistorySource(id: number): Promise<void> {
  const originalRun = historyRun.value, key = historyResolvedKey.value
  if (historyReading.value || !originalRun?.result || !key || !apiKey.value.trim() || !Number.isSafeInteger(id) || id < 1) return
  const current = ++historySourceGeneration, epoch = historyGeneration, context = captureReadContext(), originalRunId = originalRun.run_id
  const stillCurrent = () => current === historySourceGeneration && epoch === historyGeneration && sameReadContext(context) && historyKey.value.trim() === key && historyResolvedKey.value === key && historyRun.value === originalRun && historyRun.value?.run_id === originalRunId
  historySourceOpen.value = true; historySourceBusy.value = true; historySourceError.value = ''; historySourcePreview.value = null
  try {
    const result = await taskApi.readWorkflowSource(context.credential, originalRunId, id, context.environmentId)
    if (!stillCurrent()) return
    if (result.source_block_id !== id) throw new Error('历史来源块与所选引用不一致')
    historySourcePreview.value = result
  } catch (failure) {
    if (stillCurrent()) { historyRun.value = null; historySourcePreview.value = null; historySourceError.value = failure instanceof Error ? failure.message : '历史私有来源无法读取；已清除原成果与来源正文' }
  } finally { if (current === historySourceGeneration && epoch === historyGeneration && sameReadContext(context) && historyResolvedKey.value === key) historySourceBusy.value = false }
}

function startNew(): void { if (busy.value || reading.value || (requestKey.value && !runId.value)) return; resetReading(); keepKey(''); recoveryKey.value = '' }
function stateLabel(state: string): string { return ({ authorization_pending: '等待授权', queued: '排队中', running: '处理中', pending: '待处理', succeeded: '已完成', failed: '失败', canceled: '已取消', retrying: '重试中', recovery_pending: '恢复中', priced: '已计价', unpriced: '未配置价格', measured: '已计量', unavailable: '未知', mixed_currency: '多币种' } as Record<string, string>)[state] ?? state }
watch(sources, resetReading, { deep: true, flush: 'sync' })
watch([issuesText, jurisdiction, caseType, asOfDate, facts], () => { confirmed.value = false }, { deep: true, flush: 'sync' })
watch(scope, resetReading, { deep: true, flush: 'sync' })
watch(apiKey, () => { resetReading(); clearPrivateInput(); clearHistory() }, { flush: 'sync' })
watch(storageKey, () => {
  resetReading(); recoveryKey.value = ''; apiKey.value = ''; historyKey.value = ''
  clearPrivateInput(); restoreKey()
}, { flush: 'sync' })
watch([() => userStore.accessToken, () => props.profile.id, () => props.profile.revision], () => {
  resetReading(); apiKey.value = ''; recoveryKey.value = ''; historyKey.value = ''
  clearPrivateInput(); clearHistory(); restoreKey()
}, { flush: 'sync' })
watch(historyKey, clearHistory, { flush: 'sync' })
watch([apiKey, () => props.environmentId, () => props.profile], clearHistory, { deep: true, flush: 'sync' })
watch(() => props.profile, clearSource, { deep: true, flush: 'sync' })
watch(historySourceOpen, open => { if (!open) clearHistorySource() }, { flush: 'sync' })
watch(sourceOpen, open => { if (!open) clearSource() }, { flush: 'sync' })
function visibility(): void { if (document.visibilityState === 'hidden') clearTimer(); else schedule() }
onMounted(() => document.addEventListener('visibilitychange', visibility))
onBeforeUnmount(() => { active = false; clearHistory(); ++generation; ++sourceGeneration; clearTimer(); apiKey.value = ''; resetReading(); clearPrivateInput(); document.removeEventListener('visibilitychange', visibility) })
</script>
<style scoped>
.trial { margin-top: 20px; }
.trial h4 { margin: 12px 0; }
.trial p { color: var(--el-text-color-secondary); overflow-wrap: anywhere; }
.notice, .actions { margin-bottom: 12px; }
.fields { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; }
.fields :deep(.el-date-editor), .fields :deep(.el-input-number), .fields :deep(.el-select) { width: 100%; }
.history-read { margin-top: 24px; padding-top: 16px; border-top: 1px solid var(--el-border-color); }
.source-form, fieldset { margin: 12px 0; border: 1px solid var(--el-border-color); border-radius: 4px; padding: 16px; min-width: 0; }
.source-content { white-space: pre-wrap; max-height: 280px; overflow: auto; }
</style>
