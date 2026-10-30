<template>
  <ElCard shadow="never" class="knowledge-base-panel">
    <template #header>
      <div class="heading">
        <div>
          <h2>知识库管理</h2>
          <p>选择已解析的私有文件建立草稿，确认来源就绪后发布。应用访问权限在下方单独开放。</p>
        </div>
        <ElButton :loading="loadingEnvironments" @click="loadEnvironments(true)">刷新环境</ElButton>
      </div>
    </template>
    <details class="notice">
      <summary>旧 TXT 来源块过大时，保留历史并建立新知识库</summary>
      <p>先选择资料所属环境，在下方“准备知识来源”重新上传原 TXT，提交新文件的解析；结果不明时按原请求找回，不重复解析。成功后点击“选用来源”，按新文件号核对来源选择，并使用新的知识库代码建立草稿、发布，再在下方为新版本准备索引。</p>
      <p>旧发布版本仍引用原文件和原解析。不要用旧文件重新解析、替换旧知识库或解除旧来源引用来恢复大块；停用旧知识库也不会释放已发布来源。新来源和索引不会自动迁移应用开放关系。</p>
    </details>
    <ElForm label-width="110px" @submit.prevent>
      <ElFormItem label="资料所属环境">
        <ElSelect v-model="environmentId" filterable clearable :disabled="lifecycleBusy" placeholder="选择 SandIAM 环境" style="width: 420px">
          <ElOption v-for="item in environments" :key="item.id"
            :label="`${item.name} (${item.code})`" :value="item.id" />
        </ElSelect>
        <ElButton v-if="environments.length < environmentTotal" text
          :loading="loadingEnvironments" @click="loadEnvironments(false)">更多环境</ElButton>
      </ElFormItem>
    </ElForm>
    <ElForm v-if="environmentId !== null" label-width="110px" :disabled="sourcePreparationLocked" @submit.prevent>
      <RuntimeApiKeyField v-model="sourceBearer" :persist-in-session="false"
        placeholder="当前应用提供的 SandIAM 短期运行身份" />
      <MaterialSampleFiles :bearer="sourceBearer" :environment-id="environmentId"
        :disabled="sourcePreparationLocked" purpose="knowledge"
        @use-file="selectPreparedSource" @identity-denied="clearDeniedSources" />
    </ElForm>
    <ElForm label-width="110px" @submit.prevent>
      <ElFormItem label="知识库代码">
        <ElInput v-model="code" :disabled="replacingId !== null || draftLocked" maxlength="64"
          placeholder="英文小写字母开头，例如 case_materials" style="width: 420px" />
      </ElFormItem>
      <ElFormItem label="名称">
        <ElInput v-model="name" :disabled="draftLocked || writing" maxlength="128" style="width: 420px" />
      </ElFormItem>
      <ElFormItem label="用途">
        <ElInput v-model="description" :disabled="draftLocked || writing" maxlength="500" style="width: 420px" />
      </ElFormItem>
      <ElFormItem label="来源文件">
        <ElSelect v-model="selectedFileIds" multiple filterable :multiple-limit="100"
          :disabled="environmentId === null || sourcePreparationLocked" placeholder="选择已解析且归属有效的文件"
          style="width: 600px">
          <ElOption v-for="file in files" :key="file.id"
            :label="`${file.name} · 文件 #${file.id} · ${file.source_block_count} 块${file.data_class ? ` · ${file.data_class}` : ''}`"
            :value="file.id" />
        </ElSelect>
        <ElButton :disabled="environmentId === null" :loading="loadingFiles" @click="loadFiles()">刷新文件</ElButton>
      </ElFormItem>
      <ElFormItem v-if="nextFileBefore !== null">
        <ElButton text :loading="loadingFiles" @click="loadFiles(nextFileBefore)">更多文件</ElButton>
      </ElFormItem>
      <ElFormItem>
        <ElButton type="primary" :loading="writing" :disabled="!canSave" @click="saveDraft">
          {{ replacingId === null ? '建立草稿' : '替换草稿' }}
        </ElButton>
        <ElButton v-if="replacingId !== null" :disabled="writing || lifecycleBusy || draftLocked" @click="resetForm">取消替换</ElButton>
        <span v-if="mixedClasses" class="hint">所选文件的数据分级不同，请分别建立知识库。</span>
      </ElFormItem>
    </ElForm>
    <ElAlert v-if="draftLocked" type="warning" title="原草稿请求尚未确认"
      description="原管理员、环境、来源选择与请求号已冻结。只读找回原记录与固定版本；无记录、旧证明缺失或当前来源不可读时保留原号，不重新创建草稿。"
      :closable="false" class="notice" />
    <ElForm v-if="pendingDraft" label-width="110px" @submit.prevent>
      <ElFormItem label="原草稿请求号"><ElInput :model-value="pendingDraft.key" readonly /></ElFormItem>
      <ElFormItem label="原知识库">{{ pendingDraft.body.name }}（{{ pendingDraft.body.code }}）</ElFormItem>
      <ElFormItem label="原来源文件">{{ pendingDraft.body.files.map(file => `#${file.id}`).join('、') }}</ElFormItem>
      <ElFormItem>
        <ElButton :loading="writing" :disabled="writing || lifecycleBusy || !!pendingPublish"
          @click="continueDraft">只读找回原草稿</ElButton>
        <ElButton v-if="canSendOriginalDraft()" :disabled="writing || lifecycleBusy || !!pendingPublish" @click="sendOriginalDraft">保存原号后提交本页未发送草稿</ElButton>
      </ElFormItem>
    </ElForm>
    <ElAlert v-if="pendingPublish" type="warning" :title="rejectedPublish ? '本次已确认未发布' : '原知识发布结果尚未确认'"
      :description="rejectedPublish ? '草稿快照已变化，正式原请求拒绝回执已找回。请刷新草稿并重新确认。' : '原请求号和快照保留。未找到或无权限不证明原请求没有生效；先只读找回，不再次发布。'" :closable="false" class="notice" />
    <ElForm v-if="pendingPublish" label-width="110px" @submit.prevent>
      <ElFormItem label="原知识库号">{{ pendingPublish.knowledgeBaseId }}</ElFormItem>
      <ElFormItem label="原发布请求号"><ElInput :model-value="pendingPublish.key" readonly /></ElFormItem>
      <ElFormItem label="原草稿快照"><ElInput :model-value="pendingPublish.snapshot" readonly /></ElFormItem>
      <ElFormItem><ElSpace wrap>
        <ElButton :disabled="writing || lifecycleBusy" @click="recoverPublish">只读找回原发布结果</ElButton>
        <ElButton v-if="canSendOriginalPublish()" :disabled="writing || lifecycleBusy" @click="sendOriginalPublish">保存原号后提交本页未发送发布</ElButton>
        <ElButton v-if="rejectedPublish" :disabled="writing || lifecycleBusy" @click="refreshRejectedDraft">刷新草稿并重新确认</ElButton>
      </ElSpace></ElFormItem>
    </ElForm>
    <ElAlert v-if="pendingLifecycle.length || lifecycleRecoveryBlocked" type="warning"
      title="原停用或清理请求尚待确认"
      description="列表状态变化不能确认原请求。下方只读核对原知识库、操作和请求号，不重新执行清理；无记录、拒绝或证明不足时保留未知。"
      :closable="false" class="notice" />
    <ElForm v-for="original in pendingLifecycle" :key="`${original.action}:${original.knowledgeBaseId}`"
      label-width="110px" @submit.prevent>
      <ElFormItem label="原知识库号">#{{ original.knowledgeBaseId }}</ElFormItem>
      <ElFormItem label="原操作">{{ lifecycleLabel(original.action) }}</ElFormItem>
      <ElFormItem label="原请求号"><ElInput :model-value="original.key" readonly /></ElFormItem>
      <ElFormItem>
        <ElButton :loading="lifecycleBusy" :disabled="writing || lifecycleBusy || !hasDisablePermission"
          @click="continueLifecycle(original)">只读找回原{{ lifecycleLabel(original.action) }}结果</ElButton>
        <template v-if="isNotSubmitted(original)">
          <p>本页已确认原 POST 尚未开始。保存原号读回成功后，可明确提交同一操作；刷新后只按原号读取，不据 404 判断未发。</p>
          <ElButton :disabled="writing || lifecycleBusy || !hasDisablePermission" @click="sendNotSubmittedLifecycle(original)">保存原号后提交原操作</ElButton>
        </template>
      </ElFormItem>
    </ElForm>
    <ElAlert v-if="error" type="error" :title="error" :closable="false" class="notice" />
    <ElAlert v-if="notice" :type="rejectedPublish ? 'warning' : 'success'" :title="notice" :closable="false" class="notice" />
    <div class="heading">
      <h3>当前知识库</h3>
      <ElButton :disabled="environmentId === null" :loading="loadingBases" @click="loadBases(1)">刷新</ElButton>
    </div>
    <ElTable :data="bases" row-key="id" empty-text="选择资料所属环境后查看">
      <ElTableColumn label="名称" prop="name" min-width="150" />
      <ElTableColumn label="代码" prop="code" min-width="140" />
      <ElTableColumn label="状态" width="100">
        <template #default="{ row }">
          <ElTag :type="row.state === 'disabled' ? 'info' : row.state === 'published' ? 'success' : 'warning'">
            {{ row.state === 'disabled' ? '已停用' : row.state === 'published' ? '已发布' : '草稿' }}
          </ElTag>
        </template>
      </ElTableColumn>
      <ElTableColumn label="版本" width="140">
        <template #default="{ row }">
          <span v-if="row.draft_version_no">草稿 {{ row.draft_version_no }}</span>
          <span v-else-if="row.active_version_no">已发布 {{ row.active_version_no }}</span>
          <span v-else>未发布</span>
        </template>
      </ElTableColumn>
      <ElTableColumn label="来源状态" width="130">
        <template #default="{ row }">
          <ElTag :type="row.source_state === 'ready' ? 'success' : row.source_state === 'purged' ? 'info' : 'warning'">
            {{ row.source_state === 'ready' ? '就绪' : row.source_state === 'source_changed' ? '来源已变化' : row.source_state === 'purged' ? '已解除引用' : '无可用来源' }}
          </ElTag>
        </template>
      </ElTableColumn>
      <ElTableColumn label="操作" width="400">
        <template #default="{ row }">
          <ElButton v-if="row.state !== 'disabled' && row.draft_version_id && row.source_state === 'ready'" link type="primary"
            :disabled="writing || lifecycleBusy || draftLocked || !!pendingPublish || !row.draft_snapshot_sha256" @click="publish(row)">发布草稿</ElButton>
          <ElButton v-if="row.state !== 'disabled' && row.draft_version_id" link
            :disabled="writing || lifecycleBusy || draftLocked || !!pendingPublish" @click="replace(row)">重建草稿</ElButton>
          <ElButton v-if="hasDisablePermission && row.state !== 'disabled'" link type="danger"
            :disabled="writing || lifecycleBusy || draftLocked || !!pendingPublish || lifecyclePending(row.id)" @click="changeLifecycle(row, 'disable')">停用</ElButton>
          <ElButton v-if="hasDisablePermission && row.state === 'disabled'" link type="danger"
            :disabled="writing || lifecycleBusy || draftLocked || !!pendingPublish || lifecyclePending(row.id)" @click="changeLifecycle(row, 'purge')">清理向量</ElButton>
          <ElButton v-if="hasDisablePermission && row.state === 'disabled' && row.sources_purged === false" link type="danger"
            :disabled="writing || lifecycleBusy || draftLocked || !!pendingPublish || lifecyclePending(row.id)" @click="changeLifecycle(row, 'purge-sources')">解除来源引用</ElButton>
        </template>
      </ElTableColumn>
    </ElTable>
    <ElButton v-if="bases.length < baseTotal" :disabled="loadingBases" @click="loadBases(basePage + 1)">
      加载更多
    </ElButton>
    <EmbeddingIndexPanel :environment-id="environmentId" :bases="bases" />
  </ElCard>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { ElMessageBox } from 'element-plus'
import { useAuth } from '@/hooks/core/useAuth'
import { useUserStore } from '@/store/modules/user'
import iamApi, { type SandIamEnvironmentReference } from '../../api/iam'
import knowledgeBaseApi, { type KnowledgeBaseRow, type KnowledgeSourceFile, type KnowledgeDraftInput, type KnowledgePublishRecoveryResult, type KnowledgePublishRejected } from '../../api/knowledgeBase'
import { newRuntimeRequestId } from '../../api/runtime'
import MaterialSampleFiles from '../../components/MaterialSampleFiles.vue'
import RuntimeApiKeyField from '../../components/RuntimeApiKeyField.vue'
import EmbeddingIndexPanel from './embedding-index-panel.vue'

const environments = ref<SandIamEnvironmentReference[]>([])
const environmentTotal = ref(0)
const environmentPage = ref(0)
const environmentId = ref<number | null>(null)
const sourceBearer = ref('')
const selectingSource = ref(false)
const loadingEnvironments = ref(false)
const loadingFiles = ref(false)
const loadingBases = ref(false)
const writingFlag = ref(false)
const writeFlight = ref<symbol | null>(null)
const writing = computed(() => writingFlag.value || writeFlight.value !== null)
const files = ref<KnowledgeSourceFile[]>([])
const nextFileBefore = ref<number | null>(null)
const bases = ref<KnowledgeBaseRow[]>([])
const baseTotal = ref(0)
const basePage = ref(0)
const code = ref('')
const name = ref('')
const description = ref('')
const selectedFileIds = ref<number[]>([])
const replacingId = ref<number | null>(null)
const error = ref('')
const notice = ref('')
const lifecycleFlag = ref(false)
const lifecycleFlight = ref<symbol | null>(null)
const lifecycleBusy = computed(() => lifecycleFlag.value || lifecycleFlight.value !== null)
type LifecycleAction = 'disable' | 'purge' | 'purge-sources'
type LifecycleRecovery = { action: LifecycleAction; environmentId: number; knowledgeBaseId: number; key: string }
const lifecycleActions: LifecycleAction[] = ['disable', 'purge', 'purge-sources']
const pendingLifecycle = ref<LifecycleRecovery[]>([])
const lifecycleRecoveryBlocked = ref(false)
const notSubmittedLifecycle = ref(new Map<string, { original: LifecycleRecovery; epoch: number; actor: number; credential: string }>())
type PublishRecovery = { environmentId: number; knowledgeBaseId: number; key: string; snapshot: string }
const pendingPublish = ref<PublishRecovery | null>(null)
const rejectedPublish = ref<PublishRecovery | null>(null)
const notSubmittedPublish = ref<{ key: string; epoch: number; credential: string } | null>(null)
const { hasAuth } = useAuth()
const userStore = useUserStore()
const hasDisablePermission = computed(() => hasAuth('sand_ai:knowledge:disable'))
let environmentEpoch = 0
let contentEpoch = 0
let fileEpoch = 0
let baseEpoch = 0
let live = true
type DraftRecovery = { key: string; body: KnowledgeDraftInput; knowledgeBaseId: number | null }
const pendingDraft = ref<DraftRecovery | null>(null)
const notSubmittedDraft = ref<{ key: string; epoch: number; credential: string } | null>(null)
const draftRecoveryBlocked = ref(false)
const draftLocked = computed(() => pendingDraft.value !== null || draftRecoveryBlocked.value)
const sourcePreparationLocked = computed(() => writing.value || lifecycleBusy.value || draftLocked.value
  || pendingPublish.value !== null || selectingSource.value)

const selectedFiles = computed(() => files.value.filter(file => selectedFileIds.value.includes(file.id)))
const mixedClasses = computed(() => new Set(selectedFiles.value.map(file => file.data_class ?? '')).size > 1)
const canSave = computed(() =>
  environmentId.value !== null && /^[a-z][a-z0-9_-]{0,63}$/.test(code.value.trim())
  && !!name.value.trim() && selectedFiles.value.length > 0
  && selectedFiles.value.length <= 100
  && selectedFiles.value.length === selectedFileIds.value.length && !mixedClasses.value && !writing.value
  && !lifecycleBusy.value && !pendingPublish.value && !draftLocked.value && !selectingSource.value
)

function message(cause: unknown): string {
  return cause instanceof Error ? cause.message : '知识库操作失败，请稍后重试'
}
function resetForm(): void {
  code.value = ''; name.value = ''; description.value = ''
  selectedFileIds.value = []; replacingId.value = null
}
async function loadEnvironments(reset: boolean): Promise<void> {
  const current = ++environmentEpoch
  const page = reset ? 1 : environmentPage.value + 1
  loadingEnvironments.value = true
  try {
    const result = await iamApi.listEnvironments({ page, limit: 100 })
    if (!live || current !== environmentEpoch) return
    environments.value = reset ? result.data.filter(item => item.status === 1)
      : [...environments.value, ...result.data.filter(item => item.status === 1)]
    environmentPage.value = page
    environmentTotal.value = result.total
  } catch (cause) {
    if (live && current === environmentEpoch) error.value = message(cause)
  } finally {
    if (live && current === environmentEpoch) loadingEnvironments.value = false
  }
}
async function loadFiles(before?: number): Promise<boolean> {
  if (environmentId.value === null) return false
  const current = ++fileEpoch
  const scope = contentEpoch
  const owner = environmentId.value
  loadingFiles.value = true
  try {
    const result = await knowledgeBaseApi.eligibleFiles(owner, before)
    if (live && scope === contentEpoch && current === fileEpoch && environmentId.value === owner) {
      files.value = before === undefined ? result.files : [...files.value, ...result.files]
      nextFileBefore.value = result.next_before
      return true
    }
    return false
  } catch (cause) {
    if (live && scope === contentEpoch && current === fileEpoch) error.value = message(cause)
    return false
  } finally {
    if (live && scope === contentEpoch && current === fileEpoch) loadingFiles.value = false
  }
}
async function selectPreparedSource(fileId: number): Promise<void> {
  if (environmentId.value === null || sourcePreparationLocked.value) return
  const owner = environmentId.value
  const actor = userStore.info.id
  const credential = sourceBearer.value
  const scope = contentEpoch
  const isCurrent = () => live && scope === contentEpoch && owner === environmentId.value
    && actor === userStore.info.id && credential === sourceBearer.value
  selectingSource.value = true
  error.value = ''; notice.value = ''
  try {
    if (!await loadFiles()) return
    if (!isCurrent() || draftLocked.value || writing.value || lifecycleBusy.value || pendingPublish.value) return
    if (!files.value.some(file => file.id === fileId)) {
      error.value = `文件 #${fileId} 未在当前环境的可用来源列表中，请刷新或加载更多文件核对后选择。`
      return
    }
    if (!selectedFileIds.value.includes(fileId)) {
      if (selectedFileIds.value.length >= 100) {
        error.value = '每个草稿最多选择 100 份来源，请先调整来源选择。'
        return
      }
      selectedFileIds.value = [...selectedFileIds.value, fileId]
    }
    notice.value = `已选用文件 #${fileId}，请核对名称、来源文件和知识库代码后建立草稿。`
  } finally {
    if (isCurrent()) selectingSource.value = false
  }
}
function clearDeniedSources(): void {
  sourceBearer.value = ''
  ++fileEpoch
  loadingFiles.value = false
  files.value = []; nextFileBefore.value = null; selectedFileIds.value = []
  error.value = '应用运行身份或当前环境授权未通过，请更换有效身份后重新读取资料。原解析请求号仍保留。'
}
async function loadBases(page: number): Promise<void> {
  if (environmentId.value === null) return
  const current = ++baseEpoch
  const scope = contentEpoch
  const owner = environmentId.value
  loadingBases.value = true
  try {
    const result = await knowledgeBaseApi.list(owner, page)
    if (!live || scope !== contentEpoch || current !== baseEpoch || environmentId.value !== owner) return
    bases.value = page === 1 ? result.data : [...bases.value, ...result.data]
    basePage.value = page
    baseTotal.value = result.total
  } catch (cause) {
    if (live && scope === contentEpoch && current === baseEpoch) error.value = message(cause)
  } finally {
    if (live && scope === contentEpoch && current === baseEpoch) loadingBases.value = false
  }
}
function replace(row: KnowledgeBaseRow): void {
  if (writing.value || lifecycleBusy.value || draftLocked.value || pendingPublish.value) return
  code.value = row.code
  name.value = row.name
  description.value = row.description ?? ''
  selectedFileIds.value = []
  replacingId.value = row.id
}
async function saveDraft(): Promise<void> {
  if (!canSave.value || environmentId.value === null) return
  const current = contentEpoch
  const actor = userStore.info.id
  const baseId = replacingId.value
  const body: KnowledgeDraftInput = {
    environment_id: environmentId.value, code: code.value.trim(), name: name.value.trim(),
    description: description.value.trim(),
    files: selectedFiles.value.map(file => ({ id: file.id, sha256: file.sha256 })),
    replace_draft: replacingId.value !== null
  }
  if (body.replace_draft) {
    try {
      await ElMessageBox.confirm('旧草稿将停止使用，新草稿只有在来源检查通过后才会建立。', '重建草稿')
    } catch { return }
  }
  if (!live || current !== contentEpoch || actor !== userStore.info.id
    || environmentId.value !== body.environment_id || !canSave.value
    || replacingId.value !== baseId || code.value.trim() !== body.code
    || name.value.trim() !== body.name || description.value.trim() !== body.description
    || JSON.stringify(selectedFiles.value.map(file => ({ id: file.id, sha256: file.sha256 }))) !== JSON.stringify(body.files)) return
  const storage = draftStorageKey()
  if (!storage) { error.value = '无法确认当前管理员或环境，尚未发送草稿请求。'; return }
  const original: DraftRecovery = { key: newRuntimeRequestId('knowledge-draft'), body, knowledgeBaseId: baseId }
  pendingDraft.value = original
  notSubmittedDraft.value = { key: original.key, epoch: contentEpoch, credential: userStore.accessToken }
  try {
    // Persist only draft metadata and file references, never file content or credentials.
    const bytes = JSON.stringify(original)
    sessionStorage.setItem(storage, bytes)
    if (sessionStorage.getItem(storage) !== bytes) throw new Error('原草稿记录保存未确认')
    pendingDraft.value = original
  } catch (cause) {
    error.value = '原草稿尚未发送，请允许会话存储后明确继续本页原请求；刷新后仅能只读找回，不推断未提交。'
    return
  }
  await finishDraft(original)
}

function draftStorageKey(): string | null {
  const actor = userStore.info.id
  return actor === undefined || actor === null || environmentId.value === null ? null
    : `sand-ai-knowledge-draft:${actor}:${environmentId.value}`
}
function record(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}
function readSavedDraft(value: unknown, owner: number): DraftRecovery | null {
  if (!record(value) || Object.keys(value).some(field => !['key', 'body', 'knowledgeBaseId'].includes(field))
    || typeof value.key !== 'string' || !/^[A-Za-z0-9][A-Za-z0-9_.:-]{7,95}$/.test(value.key)
    || (value.knowledgeBaseId !== null && (typeof value.knowledgeBaseId !== 'number'
      || !Number.isSafeInteger(value.knowledgeBaseId) || value.knowledgeBaseId < 1))
    || !record(value.body)) return null
  const body = value.body
  if (Object.keys(body).some(field => !['environment_id', 'code', 'name', 'description', 'files', 'replace_draft'].includes(field))
    || body.environment_id !== owner || typeof body.code !== 'string'
    || !/^[a-z][a-z0-9_-]{0,63}$/.test(body.code)
    || typeof body.name !== 'string' || !body.name.trim() || [...body.name].length > 128
    || typeof body.description !== 'string' || [...body.description].length > 500
    || typeof body.replace_draft !== 'boolean'
    || body.replace_draft !== (value.knowledgeBaseId !== null)
    || !Array.isArray(body.files) || body.files.length < 1 || body.files.length > 100) return null
  const files: KnowledgeDraftInput['files'] = []
  for (const item of body.files) {
    if (!record(item) || Object.keys(item).some(field => !['id', 'sha256'].includes(field))
      || typeof item.id !== 'number' || !Number.isSafeInteger(item.id) || item.id < 1
      || typeof item.sha256 !== 'string' || !/^[a-f0-9]{64}$/.test(item.sha256)
      || files.some(file => file.id === item.id)) return null
    files.push({ id: item.id, sha256: item.sha256 })
  }
  return {
    key: value.key, knowledgeBaseId: value.knowledgeBaseId,
    body: { environment_id: owner, code: body.code, name: body.name,
      description: body.description, files, replace_draft: body.replace_draft }
  }
}
function restoreDraft(): void {
  pendingDraft.value = null
  draftRecoveryBlocked.value = false
  if (environmentId.value === null) return
  const storage = draftStorageKey()
  if (!storage) {
    draftRecoveryBlocked.value = true
    error.value = '无法确认当前管理员，暂不开放草稿写入，请刷新后重试。'
    return
  }
  try {
    const raw = sessionStorage.getItem(storage)
    if (raw === null) return
    const original = readSavedDraft(JSON.parse(raw), environmentId.value)
    if (!original) throw new Error('原草稿请求记录不完整')
    pendingDraft.value = original
    code.value = original.body.code; name.value = original.body.name
    description.value = original.body.description
    selectedFileIds.value = original.body.files.map(file => file.id)
    replacingId.value = original.knowledgeBaseId
  } catch {
    draftRecoveryBlocked.value = true
    error.value = '原草稿请求记录无法读取，已保留记录并停止新草稿或发布；请核对原请求，不要更换请求号。'
  }
}
function canSendOriginalDraft(): boolean {
  const marker = notSubmittedDraft.value
  return marker !== null && marker.key === pendingDraft.value?.key
    && marker.epoch === contentEpoch && marker.credential === userStore.accessToken
}
async function sendOriginalDraft(): Promise<void> {
  const original = pendingDraft.value
  const storage = draftStorageKey()
  if (!original || !storage || !canSendOriginalDraft() || writing.value || lifecycleBusy.value) return
  try {
    const bytes = JSON.stringify(original)
    sessionStorage.setItem(storage, bytes)
    if (sessionStorage.getItem(storage) !== bytes) throw new Error('原草稿记录保存未确认')
  } catch { error.value = '原草稿仍未发送，保存原号读回失败；请允许会话存储后重试。'; return }
  await finishDraft(original)
}
async function continueDraft(): Promise<void> {
  const original = pendingDraft.value
  if (!original || writing.value || lifecycleBusy.value || pendingPublish.value) return
  await finishDraft(original, true)
}
async function finishDraft(original: DraftRecovery, readOnly = false): Promise<void> {
  const current = contentEpoch
  const actor = userStore.info.id
  const storage = draftStorageKey()
  const isCurrent = () => live && current === contentEpoch && actor === userStore.info.id
    && environmentId.value === original.body.environment_id && pendingDraft.value?.key === original.key
  if (!storage || !isCurrent() || writing.value || lifecycleBusy.value || pendingPublish.value
    || (!readOnly && !canSendOriginalDraft())) return
  const flight = readOnly ? null : Symbol('draft-write')
  if (flight !== null) writeFlight.value = flight
  writingFlag.value = true; error.value = ''; notice.value = ''
  try {
    if (!readOnly) notSubmittedDraft.value = null
    const result = readOnly
      ? await knowledgeBaseApi.draftByRequestKey(original.body, original.key, String(actor), original.knowledgeBaseId)
      : await knowledgeBaseApi.createDraft(original.body, original.key, original.knowledgeBaseId)
    if (!isCurrent()) return
    notice.value = `原草稿请求已确认：知识库 #${result.knowledge_base.id} · 版本 ${result.version.version} · ${result.version.source_count} 个来源块（当前状态：${result.version.state}）。原关联与当前状态已核对，请刷新当前列表核对来源及活动版本后再发布。`
    try {
      const raw = sessionStorage.getItem(storage)
      const saved = raw === null ? null : readSavedDraft(JSON.parse(raw), original.body.environment_id)
      const expected = readSavedDraft(original, original.body.environment_id)
      if (saved === null || expected === null || JSON.stringify(saved) !== JSON.stringify(expected))
        throw new Error('原草稿记录已变化')
      sessionStorage.removeItem(storage)
      if (sessionStorage.getItem(storage) !== null) throw new Error('原草稿记录尚未清除')
    } catch {
      error.value = '已找回原草稿，但请求记录尚未清除；保留原号，只读找回会核对同一版本。'
      return
    }
    pendingDraft.value = null
    notSubmittedDraft.value = null
    resetForm()
    await loadBases(1)
  } catch (cause) {
    if (isCurrent()) error.value = `${message(cause)}。原正文与请求号保留，请只读找回原草稿；无记录或来源已变化不证明未提交，不新建替代请求。`
  } finally {
    if (flight !== null && writeFlight.value === flight) writeFlight.value = null
    if (live && current === contentEpoch && actor === userStore.info.id) writingFlag.value = false
  }
}
function publishStorageKey(): string | null {
  const actor = userStore.info.id
  return actor === undefined || environmentId.value === null ? null
    : `sand-ai-knowledge-publish:${actor}:${environmentId.value}`
}
function restorePublish(): void {
  pendingPublish.value = null
  rejectedPublish.value = null
  const key = publishStorageKey()
  if (!key) return
  try {
    const value: unknown = JSON.parse(sessionStorage.getItem(key) ?? 'null')
    if (typeof value !== 'object' || value === null || Array.isArray(value)) return
    const item = value as Record<string, unknown>
    if (item.environmentId !== environmentId.value || typeof item.knowledgeBaseId !== 'number'
      || !Number.isSafeInteger(item.knowledgeBaseId) || item.knowledgeBaseId < 1
      || typeof item.key !== 'string' || !/^[A-Za-z0-9][A-Za-z0-9_.:-]{7,95}$/.test(item.key)
      || typeof item.snapshot !== 'string' || !/^[a-f0-9]{64}$/.test(item.snapshot)) return
    pendingPublish.value = { environmentId: item.environmentId as number, knowledgeBaseId: item.knowledgeBaseId, key: item.key, snapshot: item.snapshot }
  } catch { error.value = '无法读取原发布请求，请允许会话存储后刷新，不要重新发布。' }
}
async function recoverPublish(): Promise<void> {
  const original = pendingPublish.value
  if (!original || writing.value || lifecycleBusy.value) return
  await finishPublish(original, true)
}
async function refreshRejectedDraft(): Promise<void> {
  const rejected = rejectedPublish.value
  const original = pendingPublish.value
  if (!rejected || !original || writing.value || lifecycleBusy.value
    || rejected.environmentId !== original.environmentId || rejected.knowledgeBaseId !== original.knowledgeBaseId
    || rejected.key !== original.key || rejected.snapshot !== original.snapshot) return
  const current = contentEpoch
  const actor = userStore.info.id
  const storage = publishStorageKey()
  const isCurrent = () => live && current === contentEpoch && actor === userStore.info.id && original.environmentId === environmentId.value
  if (!storage) return
  writingFlag.value = true; error.value = ''
  const epoch = ++baseEpoch
  try {
    const result = await knowledgeBaseApi.list(original.environmentId, 1)
    if (!isCurrent() || epoch !== baseEpoch) return
    // Only an explicit user action after an authenticated rejection allows a fresh intent.
    sessionStorage.removeItem(storage)
    pendingPublish.value = null; rejectedPublish.value = null
    bases.value = result.data; basePage.value = 1; baseTotal.value = result.total
    notice.value = '原请求已确认未发布。当前草稿已重新读取，请检查版本及来源状态，再明确点击发布草稿。'
  } catch (cause) {
    if (isCurrent()) error.value = `${message(cause)}。原拒绝回执仍保留，尚未开始新发布。`
  } finally {
    if (isCurrent()) writingFlag.value = false
  }
}

function isRejectedPublish(result: KnowledgePublishRecoveryResult): result is KnowledgePublishRejected {
  return 'state' in result && result.state === 'rejected'
}

function canSendOriginalPublish(): boolean {
  const marker = notSubmittedPublish.value
  return marker !== null && marker.key === pendingPublish.value?.key
    && marker.epoch === contentEpoch && marker.credential === userStore.accessToken
}
async function sendOriginalPublish(): Promise<void> {
  const original = pendingPublish.value
  const storage = publishStorageKey()
  if (!original || !storage || !canSendOriginalPublish() || writing.value || lifecycleBusy.value) return
  try {
    const bytes = JSON.stringify(original)
    sessionStorage.setItem(storage, bytes)
    if (sessionStorage.getItem(storage) !== bytes) throw new Error('原发布记录保存未确认')
  } catch { error.value = '原发布仍未发送，保存原号读回失败；请允许会话存储后重试。'; return }
  await finishPublish(original, false)
}
async function finishPublish(original: PublishRecovery, readOnly: boolean): Promise<void> {
  const current = contentEpoch
  const actor = userStore.info.id
  const storage = publishStorageKey()
  const isCurrent = () => live && current === contentEpoch && actor === userStore.info.id && original.environmentId === environmentId.value
  if (!storage) { error.value = '无法确认当前管理员或环境，请刷新后重试。'; return }
  if (!isCurrent() || writing.value || lifecycleBusy.value || (!readOnly && !canSendOriginalPublish())) return
  const flight = readOnly ? null : Symbol('publish-write')
  if (flight !== null) writeFlight.value = flight
  writingFlag.value = true; error.value = ''; notice.value = ''
  try {
    if (!readOnly) notSubmittedPublish.value = null
    const result = readOnly
      ? await knowledgeBaseApi.publishByRequestKey(original.environmentId, original.knowledgeBaseId, original.key, original.snapshot)
      : await knowledgeBaseApi.publish(original.environmentId, original.knowledgeBaseId, original.key, original.snapshot)
    if (!isCurrent()) return
    if (isRejectedPublish(result)) {
      rejectedPublish.value = original
      notice.value = '本次未发布：原请求的草稿快照已变化。原请求号和拒绝回执保留，请明确刷新草稿并重新确认。'
      return
    }
    // An already archived version still proves the original publication; it is not the current active version.
    notice.value = `原发布结果已确认：知识库 #${result.knowledge_base.id} · 版本 ${result.version.version} · ${result.version.source_count} 个来源块（${result.version.state === 'published' ? '已发布' : '当前状态：' + result.version.state}）。应用访问与索引状态请在下方继续配置。`
    try {
      if (sessionStorage.getItem(storage) !== JSON.stringify(original)) throw new Error('原发布记录已变化')
      sessionStorage.removeItem(storage)
      if (sessionStorage.getItem(storage) !== null) throw new Error('原发布记录尚未清除')
      pendingPublish.value = null; notSubmittedPublish.value = null
    } catch { error.value = '原发布已确认，但记录清除未确认，原号保留以便只读找回。'; return }
    await loadBases(1)
  } catch (cause) {
    if (isCurrent()) error.value = `${message(cause)}。原发布请求号与快照已保留，请只读找回。`
  } finally {
    if (flight !== null && writeFlight.value === flight) writeFlight.value = null
    if (isCurrent()) writingFlag.value = false
  }
}
async function publish(row: KnowledgeBaseRow): Promise<void> {
  if (environmentId.value === null || writing.value || lifecycleBusy.value || draftLocked.value || pendingPublish.value) return
  const storage = publishStorageKey()
  const snapshot = row.draft_snapshot_sha256
  if (!storage || typeof snapshot !== 'string' || !/^[a-f0-9]{64}$/.test(snapshot)) {
    error.value = '当前草稿快照尚未核实，请刷新知识库后再发布。'
    return
  }
  const original: PublishRecovery = { environmentId: environmentId.value, knowledgeBaseId: row.id, key: newRuntimeRequestId('knowledge-publish'), snapshot }
  pendingPublish.value = original
  notSubmittedPublish.value = { key: original.key, epoch: contentEpoch, credential: userStore.accessToken }
  try {
    const bytes = JSON.stringify(original)
    sessionStorage.setItem(storage, bytes)
    if (sessionStorage.getItem(storage) !== bytes) throw new Error('原发布记录保存未确认')
    pendingPublish.value = original
    rejectedPublish.value = null
  } catch { error.value = '无法保存原发布请求号，未发送发布请求；请允许会话存储后重试。'; return }
  await finishPublish(original, false)
}
function lifecycleLabel(action: LifecycleAction): string {
  return action === 'disable' ? '停用' : action === 'purge' ? '清理向量' : '解除来源引用'
}
function lifecyclePending(knowledgeBaseId: number): boolean {
  return lifecycleRecoveryBlocked.value || pendingLifecycle.value.some(item => item.knowledgeBaseId === knowledgeBaseId)
}
function lifecycleStorageKey(original: LifecycleRecovery): string | null {
  const actor = userStore.info.id
  return actor === undefined ? null
    : `sand-ai-knowledge-${original.action}:${actor}:${original.environmentId}:${original.knowledgeBaseId}`
}
function restoreLifecycle(): void {
  pendingLifecycle.value = []
  lifecycleRecoveryBlocked.value = false
  const actor = userStore.info.id
  const owner = environmentId.value
  if (actor === undefined || owner === null) return
  const restored: LifecycleRecovery[] = []
  try {
    for (let index = 0; index < sessionStorage.length; index++) {
      const storage = sessionStorage.key(index)
      if (!storage) continue
      for (const action of lifecycleActions) {
        const prefix = `sand-ai-knowledge-${action}:${actor}:${owner}:`
        if (!storage.startsWith(prefix)) continue
        const idText = storage.slice(prefix.length)
        const knowledgeBaseId = Number(idText)
        const key = sessionStorage.getItem(storage)
        if (!Number.isSafeInteger(knowledgeBaseId) || knowledgeBaseId < 1 || String(knowledgeBaseId) !== idText
          || key === null || key.trim() === '' || key.length > 128 || /[\u0000-\u001f\u007f]/.test(key)) {
          throw new Error('原生命周期请求记录无效')
        }
        restored.push({ action, environmentId: owner, knowledgeBaseId, key })
      }
    }
    pendingLifecycle.value = restored
  } catch {
    pendingLifecycle.value = restored
    lifecycleRecoveryBlocked.value = true
    error.value = '无法完整读取原停用或清理请求。原记录保留，已停止新的生命周期操作；请核对原请求，不要更换请求号。'
  }
}
function isNotSubmitted(original: LifecycleRecovery): boolean {
  const saved = notSubmittedLifecycle.value.get(original.key)
  return Boolean(saved && live && saved.epoch === contentEpoch && saved.actor === userStore.info.id
    && saved.credential === userStore.accessToken && original.environmentId === environmentId.value
    && saved.original.action === original.action && saved.original.knowledgeBaseId === original.knowledgeBaseId
    && saved.original.environmentId === original.environmentId)
}
async function sendNotSubmittedLifecycle(original: LifecycleRecovery): Promise<void> {
  if (!isNotSubmitted(original) || writing.value || lifecycleBusy.value || !hasDisablePermission.value) return
  const storage = lifecycleStorageKey(original)
  if (!storage) return
  try {
    const previous = sessionStorage.getItem(storage)
    if (previous !== null && previous !== original.key) throw new Error('原请求记录已变化')
    sessionStorage.setItem(storage, original.key)
    if (sessionStorage.getItem(storage) !== original.key) throw new Error('原号尚未可靠保存')
  } catch { error.value = '本页原 POST 尚未开始；原号保存仍未确认，未发送。'; return }
  if (isNotSubmitted(original)) await finishLifecycle(original)
}
async function continueLifecycle(original: LifecycleRecovery): Promise<void> {
  await finishLifecycle(original, true)
}
async function finishLifecycle(original: LifecycleRecovery, readOnly = false, heldOwner?: symbol): Promise<void> {
  const owner = environmentId.value
  const actor = userStore.info.id
  const current = contentEpoch
  const credential = userStore.accessToken
  const storage = lifecycleStorageKey(original)
  const isCurrent = () => live && current === contentEpoch && owner === environmentId.value
    && actor === userStore.info.id && credential === userStore.accessToken && owner === original.environmentId
    && pendingLifecycle.value.some(item => item.action === original.action
      && item.knowledgeBaseId === original.knowledgeBaseId && item.key === original.key)
  if (!storage || !isCurrent() || writing.value
    || (lifecycleBusy.value && (!heldOwner || lifecycleFlight.value !== heldOwner)) || !hasDisablePermission.value
    || (!readOnly && !isNotSubmitted(original))) return
  const flight = readOnly ? null : heldOwner ?? Symbol('lifecycle-write')
  if (flight !== null) lifecycleFlight.value = flight
  lifecycleFlag.value = true
  notice.value = ''; error.value = ''
  try {
    if (!readOnly) notSubmittedLifecycle.value.delete(original.key)
    if (readOnly) {
      await knowledgeBaseApi.lifecycleByRequestKey(original.environmentId, original.knowledgeBaseId, original.action, original.key, String(actor))
    } else if (original.action === 'disable') {
      await knowledgeBaseApi.disable(original.environmentId, original.knowledgeBaseId, original.key)
    } else if (original.action === 'purge') {
      await knowledgeBaseApi.purgeEmbeddingIndex(original.environmentId, original.knowledgeBaseId, original.key)
    } else {
      await knowledgeBaseApi.purgeSources(original.environmentId, original.knowledgeBaseId, original.key)
    }
    if (!isCurrent()) return
    notSubmittedLifecycle.value.delete(original.key)
    // Only the authenticated, target-checked operation response clears this
    // request. A refreshed list, a 404 or an error can never release its key.
    try {
      if (sessionStorage.getItem(storage) !== original.key) throw new Error('原请求记录已变化')
      sessionStorage.removeItem(storage)
      if (sessionStorage.getItem(storage) !== null) throw new Error('原请求记录尚未清除')
    } catch {
      error.value = '原操作已确认，但请求记录未能清除；原号保留，只读找回会再次核对同一操作。'
      return
    }
    pendingLifecycle.value = pendingLifecycle.value.filter(item => item.action !== original.action
      || item.knowledgeBaseId !== original.knowledgeBaseId || item.key !== original.key)
    notice.value = original.action === 'disable' ? '原停用请求已确认，知识库已停止提供检索，向量仍保留。'
      : original.action === 'purge' ? '原清理请求已确认，向量索引已清理。'
        : '原解除请求已确认，来源引用、知识问答会话及轮次已清理；原始文件由原所有者另行删除。'
    if (replacingId.value === original.knowledgeBaseId) resetForm()
    await loadBases(1)
  } catch (cause) {
    if (isCurrent()) {
      await loadBases(1)
      if (isCurrent()) error.value = `${message(cause)}。原${lifecycleLabel(original.action)}是否完成尚未确认；原知识库、操作和请求号保留，请在上方只读找回原请求；不会重新执行操作。`
    }
  } finally {
    if (flight !== null && lifecycleFlight.value === flight) lifecycleFlight.value = null
    if (live && current === contentEpoch && actor === userStore.info.id) lifecycleFlag.value = false
  }
}
async function changeLifecycle(row: KnowledgeBaseRow, action: LifecycleAction): Promise<void> {
  if (environmentId.value === null || writing.value || lifecycleBusy.value || draftLocked.value
    || pendingPublish.value || !hasDisablePermission.value || lifecyclePending(row.id)) return
  if (action !== 'disable' && row.state !== 'disabled') return
  if (action === 'purge-sources' && row.sources_purged !== false) return
  const owner = environmentId.value
  const actor = userStore.info.id
  const current = contentEpoch
  const credential = userStore.accessToken
  const target = Object.freeze({ id: row.id, name: row.name, code: row.code, state: row.state,
    active_version_id: row.active_version_id, draft_version_id: row.draft_version_id,
    draft_snapshot_sha256: row.draft_snapshot_sha256, source_state: row.source_state, sources_purged: row.sources_purged })
  const isCurrent = () => live && current === contentEpoch && owner === environmentId.value
    && actor === userStore.info.id && credential === userStore.accessToken
  const flight = Symbol('lifecycle-write')
  lifecycleFlight.value = flight
  lifecycleFlag.value = true
  notice.value = ''; error.value = ''
  try {
    try {
      await ElMessageBox.confirm(
        action === 'disable'
          ? `停用“${target.name}”后将停止提供检索服务，已有向量会保留。是否停用？`
          : action === 'purge'
            ? `将永久清理“${target.name}”的向量索引数据，此操作不可撤销。若仍有构建或检索正在执行，清理会被阻止。是否继续？`
            : `解除“${target.name}”的来源引用前，须先撤销所有应用发布并清理向量。操作会删除该知识库的知识问答会话及轮次，并释放来源引用；原始文件须由原所有者另行删除才能清除正文。此操作不可撤销。是否继续？`,
        action === 'disable' ? '停用知识库' : action === 'purge' ? '清理向量索引' : '解除来源引用',
        { type: 'warning', confirmButtonText: action === 'disable' ? '确认停用' : action === 'purge' ? '确认清理' : '确认解除', cancelButtonText: '取消' }
      )
    } catch { return }
    if (!isCurrent() || !hasDisablePermission.value) return
    const currentBase = bases.value.find(item => item.id === target.id)
    if (!currentBase || currentBase.code !== target.code || currentBase.state !== target.state
      || currentBase.active_version_id !== target.active_version_id || currentBase.draft_version_id !== target.draft_version_id
      || currentBase.draft_snapshot_sha256 !== target.draft_snapshot_sha256
      || currentBase.source_state !== target.source_state || currentBase.sources_purged !== target.sources_purged) {
      error.value = '知识库版本或来源状态已变化，请刷新后重新确认原操作。'
      return
    }
    if (actor === undefined) { error.value = '无法确认当前管理员，请刷新页面后重试。'; return }
    const original: LifecycleRecovery = {
      action, environmentId: owner, knowledgeBaseId: target.id, key: newRuntimeRequestId(`knowledge-${action}`)
    }
    const storage = lifecycleStorageKey(original)
    if (!storage) return
    try {
      // Retain an earlier intent if it appeared while the confirmation was open.
      const savedKey = sessionStorage.getItem(storage)
      if (savedKey !== null) {
        restoreLifecycle()
        error.value = '此知识库已有原请求待确认，请使用上方原请求继续。'
        return
      }
      notSubmittedLifecycle.value.set(original.key, { original, epoch: current, actor, credential })
      pendingLifecycle.value = [...pendingLifecycle.value, original]
      sessionStorage.setItem(storage, original.key)
      if (sessionStorage.getItem(storage) !== original.key) throw new Error('原请求号未保存')
    } catch {
      error.value = '无法保存本次原请求标识，未发送操作；请允许会话存储后重试。'
      return
    }
    await finishLifecycle(original, false, flight)
  } finally {
    if (lifecycleFlight.value === flight) lifecycleFlight.value = null
    if (live && current === contentEpoch && actor === userStore.info.id) lifecycleFlag.value = false
  }
}
watch(environmentId, value => {
  sourceBearer.value = ''
  selectingSource.value = false
  ++contentEpoch
  notSubmittedLifecycle.value.clear()
  notSubmittedDraft.value = null
  notSubmittedPublish.value = null
  ++fileEpoch
  ++baseEpoch
  loadingFiles.value = false
  loadingBases.value = false
  files.value = []; nextFileBefore.value = null; bases.value = []; baseTotal.value = 0; basePage.value = 0
  resetForm(); error.value = ''; notice.value = ''
  writingFlag.value = false
  lifecycleFlag.value = false
  restoreLifecycle()
  restoreDraft()
  restorePublish()
  if (value !== null) { void loadFiles(); void loadBases(1) }
})
watch(() => [userStore.info.id, userStore.accessToken], () => {
  sourceBearer.value = ''
  selectingSource.value = false
  ++contentEpoch
  notSubmittedLifecycle.value.clear()
  notSubmittedDraft.value = null
  notSubmittedPublish.value = null
  writingFlag.value = false; notice.value = ''; error.value = ''
  bases.value = []; files.value = []; resetForm()
  lifecycleFlag.value = false
  restoreLifecycle()
  restoreDraft()
  restorePublish()
  if (environmentId.value !== null) { void loadBases(1); void loadFiles() }
}, { flush: 'sync' })
watch(sourceBearer, () => {
  ++fileEpoch
  loadingFiles.value = false
  selectingSource.value = false
  files.value = []; nextFileBefore.value = null; selectedFileIds.value = []
  notice.value = ''
}, { flush: 'sync' })
onMounted(() => { void loadEnvironments(true) })
onUnmounted(() => { live = false; notSubmittedLifecycle.value.clear(); ++contentEpoch; ++environmentEpoch })
</script>

<style scoped>
.knowledge-base-panel { margin-bottom: 16px; }
.heading { display: flex; align-items: center; justify-content: space-between; gap: 16px; }
.heading h2, .heading h3 { margin: 0; }
.heading p, .hint { color: var(--el-text-color-secondary); }
.heading p { margin: 6px 0 0; }
.notice { margin: 12px 0; }
</style>
