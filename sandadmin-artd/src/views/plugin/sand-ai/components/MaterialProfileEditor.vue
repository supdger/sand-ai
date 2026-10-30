<template>
  <section class="material-editor">
    <h3>材料整理配置</h3>
    <p class="muted">配置归属所选 SandIAM 环境。保存后仍需运行真实样本并发布，应用才能调用。</p>
    <ElAlert v-if="message" :title="message" :type="messageType" :closable="false" show-icon class="notice" />
    <ElAlert v-if="operationFlight" title="原配置操作仍在提交中，切换环境或登录身份不会取消已发出的请求。提交结束后按原目标只读找回。" type="info" :closable="false" class="notice" />
    <template v-if="environmentId">
      <section v-if="revocationIntent" class="notice">
        <p>原撤销目标：{{ revocationIntent.code }} · {{ revocationIntent.version }} · 修订 {{ revocationIntent.revision }}。</p>
        <ElButton :loading="revocationReading" :disabled="busy || operationFlight || revocationReading" @click="readOriginalRevocation">只读核对原撤销目标</ElButton>
        <p v-if="revocationObserved">该版本当前状态：{{ stateLabel(revocationObserved.state) }} · 修订 {{ revocationObserved.revision }}。</p>
        <ElButton v-if="originalRevocationConfirmed" :disabled="busy || operationFlight || revocationReading" @click="confirmOriginalRevocation">确认该版本当前已撤销</ElButton>
        <p class="muted">此读取只证明固定版本的当前状态，不是原操作回执。未找到、拒绝读取或状态不符时保留原目标，不再次发送撤销。</p>
      </section>
      <ElAlert v-if="intentStorageError" type="warning" :closable="false" class="notice"
        title="原配置操作记录无法确认，暂不保存、发布或撤销。请恢复会话存储后重读原目标。" />
      <section v-if="draftSaveIntent" class="notice">
        <p>原保存目标：{{ draftSaveIntent.code }} · {{ draftSaveIntent.profile_version }}；
          保存前修订 {{ draftSaveIntent.expected_revision ?? '无（新建）' }}。</p>
        <ElButton :loading="draftSaveReading" :disabled="busy || operationFlight || draftSaveReading"
          @click="readOriginalDraftSave">读取原保存目标</ElButton>
        <p v-if="draftSaveObserved">原版本当前状态：{{ stateLabel(draftSaveObserved.state) }} · 修订 {{ draftSaveObserved.revision }}。</p>
        <ElButton v-if="originalDraftSaveConfirmed" :disabled="busy || operationFlight || draftSaveReading"
          @click="confirmOriginalDraftSave">确认当前配置已保存</ElButton>
        <p class="muted">只核对原版本当前修订及内容摘要，不是原请求回执。未找到、内容不符或读取失败均保留原保存目标，不会再次保存。</p>
      </section>
      <section v-if="publicationIntent" class="notice">
        <p>原发布目标：{{ publicationIntent.code }} · {{ publicationIntent.version }} · 修订 {{ publicationIntent.revision }} · 样本 #{{ publicationIntent.sample_run_id }}。</p>
        <ElButton :loading="publicationReading" :disabled="publicationReading || busy || operationFlight" @click="readOriginalPublication">读取原发布目标</ElButton>
        <p v-if="publicationObserved">原版本当前状态：{{ stateLabel(publicationObserved.state) }} · 修订 {{ publicationObserved.revision }}。</p>
        <ElButton v-if="originalPublicationConfirmed" :disabled="publicationReading || busy || operationFlight" @click="confirmOriginalPublication">确认原配置已发布</ElButton>
        <p class="muted">仅核对原代码、版本、修订和样本；未找到、草稿或读取失败不证明原发布未发生，不会自动再次发布。</p>
      </section>
      <ElButton v-if="intentStorageError" :disabled="busy || operationFlight || publicationReading" @click="loadOriginalIntents">重读原操作记录</ElButton>
      <ElSpace wrap class="toolbar">
        <ElSelect v-model="selected" placeholder="选择已有配置" clearable style="width: 300px" :loading="busy"
          :disabled="!!revocationIntent || !!draftSaveIntent || operationFlight || draftSaveReading">
          <ElOption v-for="item in profiles" :key="`${item.code}@${item.version}`"
            :value="`${item.code}@${item.version}`" :label="`${item.name} · ${item.version} · ${stateLabel(item.state)}`" />
        </ElSelect>
        <ElButton :disabled="busy || operationFlight" @click="loadList">刷新列表</ElButton>
        <ElButton :disabled="busy || operationFlight || !!publicationIntent || !!revocationIntent || !!draftSaveIntent || intentStorageError" @click="newDraft(true)">新建草稿</ElButton>
        <ElButton v-if="revision !== null" v-permission="'sand_ai:setup:material-profile:save'"
          :disabled="busy || operationFlight || !!publicationIntent || !!revocationIntent || !!draftSaveIntent || intentStorageError" @click="createNextVersion">复制为新版本</ElButton>
      </ElSpace>
      <ElAlert v-if="versionOrigin" type="info" :closable="false"
        :title="`已复制 ${versionOrigin.code} · ${versionOrigin.version} 的配置，请核对新版本号并保存草稿。`"
        description="新版本需要自己的成功样本，保存后可在本页继续试用与发布。" />
      <ElAlert v-else-if="state !== 'draft'" type="info" :closable="false"
        :title="`${stateLabel(state)}版本已固定。需要调整时，选择「复制为新版本」。`" />
      <ElForm label-width="120px" class="form" @submit.prevent>
        <ElFormItem label="名称"><ElInput v-model.trim="name" maxlength="160" :disabled="locked" /></ElFormItem>
        <ElFormItem label="代码"><ElInput v-model.trim="form.code" :disabled="locked || revision !== null" /></ElFormItem>
        <ElFormItem label="版本"><ElInput v-model.trim="form.version" :disabled="locked || revision !== null" placeholder="1.0.0" /></ElFormItem>
        <ElFormItem label="输出语言">
          <ElSelect v-model="form.output_language" :disabled="locked">
            <ElOption label="简体中文" value="zh-CN" /><ElOption label="English" value="en" />
          </ElSelect>
        </ElFormItem>
        <ElFormItem label="材料分类"><ElInput :model-value="classifications" @update:model-value="editList('document_classifications', $event)" type="textarea" :rows="2" :disabled="locked" placeholder="每行一个中文分类，如合同、发票、情况说明；每项最多 2000 字" /></ElFormItem>
        <ElFormItem label="实体类型"><ElInput :model-value="entities" @update:model-value="editList('entity_types', $event)" type="textarea" :rows="2" :disabled="locked" placeholder="每行一个中文类型，如人物、机构、地点；每项最多 64 字" /></ElFormItem>
        <ElFormItem label="扩展字段"><ElInput :model-value="extensions" @update:model-value="editList('extension_fields', $event)" type="textarea" :rows="2" :disabled="locked" placeholder="每行一个英文字段代码，可留空" /></ElFormItem>
        <ElFormItem label="跨文件复核输出">
          <div>
            <ElSelect v-model="reviewOutputStrategy" :disabled="locked">
              <ElOption label="标准 JSON" value="neuron_json_extract_v1" />
              <ElOption label="JSON 对象" value="neuron_zai_json_object_v1" />
              <ElOption label="JSON Schema" value="neuron_openai_json_schema_v1" />
            </ElSelect>
            <p class="muted">JSON 对象或 JSON Schema 需模型支持对应协议。</p>
          </div>
        </ElFormItem>
        <ElFormItem label="提示词字符上限"><ElInputNumber v-model="form.max_prompt_characters" :min="1" :max="200000" :disabled="locked" /></ElFormItem>
        <ElFormItem label="输出字符上限"><ElInputNumber v-model="form.max_output_characters" :min="1" :max="500000" :disabled="locked" /></ElFormItem>
        <ElFormItem v-if="state === 'draft' && revision !== null" label="成功样本">
          <ElSpace wrap>
            <ElSelect v-model="sampleRunId" placeholder="选择当前修订的已完成样本" clearable
              :loading="samplesBusy" :disabled="busy || operationFlight" style="width: 300px">
              <ElOption v-for="sample in samples" :key="sample.sample_run_id"
                :value="sample.sample_run_id" :label="`运行 #${sample.sample_run_id} · ${sample.completed_at}`" />
            </ElSelect>
            <ElButton :disabled="busy || operationFlight || samplesBusy" @click="loadSamples">刷新样本</ElButton>
          </ElSpace>
          <span class="muted">由已授权应用运行当前草稿修订的样本。这里仅列出最近完成的候选，发布时仍会核对成果、授权归属和计量。</span>
          <span v-if="hasUnsavedChanges" class="muted">当前编辑内容尚未保存。保存后请重新运行样本。</span>
        </ElFormItem>
        <ElFormItem>
          <ElSpace wrap>
            <ElTag v-if="revision !== null">{{ stateLabel(state) }} · 修订 {{ revision }}</ElTag>
            <ElButton type="primary" v-permission="'sand_ai:setup:material-profile:save'" :loading="busy"
              :disabled="locked || !canSave" @click="save">保存草稿</ElButton>
            <ElButton v-if="state === 'draft' && revision !== null" v-permission="'sand_ai:setup:material-profile:publish'"
              type="success" :disabled="busy || operationFlight || !canPublish" @click="publish">核验样本并发布</ElButton>
            <ElButton v-if="state === 'published'" v-permission="'sand_ai:setup:material-profile:revoke'"
              type="danger" plain :disabled="busy || operationFlight || !!publicationIntent || !!revocationIntent || !!draftSaveIntent || intentStorageError" @click="revoke">撤销发布</ElButton>
          </ElSpace>
        </ElFormItem>
        <ElAlert v-if="validationMessage" :title="validationMessage"
          type="warning" :closable="false" />
      </ElForm>
      <p class="muted">整理成果包含文档分类与标题、实体、事件、冲突、待核实项及扩展字段；每项可回到材料来源。
        保存的代码、版本和修订决定本次样本使用哪份配置；已发布版本供应用按代码和版本调用。</p>
      <MaterialSampleRunner
        v-if="runnerTarget && typeof environmentId === 'number'"
        :environment-id="environmentId"
        :profile-code="runnerTarget.code"
        :profile-version="runnerTarget.version"
        :profile-revision="runnerTarget.revision"
        :profile-state="runnerTarget.state"
        :resume-run-id="resumeRunId"
        :disabled="busy || operationFlight || !!publicationIntent || !!revocationIntent || !!draftSaveIntent || intentStorageError || originalSampleIsDifferent || (state === 'draft' && (hasUnsavedChanges || !canSave))"
        :disabled-reason="draftSaveIntent ? '原草稿保存结果待确认，请先读取原保存目标' : validationMessage || (hasUnsavedChanges ? '草稿有未保存的修改，保存后再运行样本' : '正在保存或读取配置，请稍候')"
        @refresh-samples="loadSamples"
        @original-request-change="loadOriginalIntents"
      />
    </template>
    <p v-else class="muted">先选择应用和环境。</p>
  </section>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue'
  import { useRoute, useRouter } from 'vue-router'
import { ElMessageBox } from 'element-plus'
import { useUserStore } from '@/store/modules/user'
import { readMaterialSampleJournal, readMaterialPublicationIntent, retainMaterialPublicationIntent, resolveMaterialPublicationIntent,
  materialDraftFingerprint, readMaterialDraftSaveIntent, retainMaterialDraftSaveIntent, resolveMaterialDraftSaveIntent,
  type MaterialSampleIntent, type MaterialPublicationIntent, type MaterialDraftSaveIntent } from '../api/materialSetupIntent'
import materialApi, { type MaterialProfile, type MaterialProfileConfig, type MaterialSampleCandidate } from '../api/materialProfile'
import MaterialSampleRunner from './MaterialSampleRunner.vue'

const props = defineProps<{ environmentId: number | '' | undefined }>()
const userStore = useUserStore()
  const route = useRoute()
  const router = useRouter()
  const resumeRunId = ref<number | undefined>()
  let restoreSelection = true
const profiles = ref<MaterialProfile[]>([])
const selected = ref('')
const revision = ref<number | null>(null)
const sampleRunId = ref<number | undefined>(undefined)
const samples = ref<MaterialSampleCandidate[]>([])
const samplesBusy = ref(false)
const savedDraftSnapshot = ref<string | null>(null)
const state = ref<MaterialProfile['state']>('draft')
const name = ref('材料整理')
const classifications = ref('通用材料')
const entities = ref('人物\n机构\n地点')
const extensions = ref('')
type ListField = 'document_classifications' | 'entity_types' | 'extension_fields'
const originalLists = ref<Pick<MaterialProfileConfig, ListField> | null>(null)
const editedLists = reactive<Record<ListField, boolean>>({
  document_classifications: false, entity_types: false, extension_fields: false
})
const busy = ref(false)
const message = ref('')
const messageType = ref<'error' | 'success' | 'warning'>('error')
const versionOrigin = ref<{ code: string; version: string } | null>(null)
const form = reactive<MaterialProfileConfig>({
  code: 'generic_material_analysis', version: '1.0.0', output_language: 'zh-CN',
  document_classifications: [], entity_types: [], extension_fields: [],
  max_prompt_characters: 20000, max_output_characters: 20000,
  review_output_strategy: 'neuron_json_extract_v1'
})
// Historical absent values display the default without changing the persisted config.
const reviewOutputStrategy = computed<NonNullable<MaterialProfileConfig['review_output_strategy']>>({
  get: () => form.review_output_strategy ?? 'neuron_json_extract_v1',
  set: value => { if (!locked.value) form.review_output_strategy = value }
})
let generation = 0
let sampleGeneration = 0
const publicationIntent = ref<MaterialPublicationIntent | null>(null)
const publicationReading = ref(false)
let originalIntentEpoch = 0
let publicationReadGeneration = 0
const publicationObserved = ref<Pick<MaterialProfile, 'state' | 'revision' | 'sample_run_id'> | null>(null)
const originalSampleIntent = ref<MaterialSampleIntent | null>(null)
const intentStorageError = ref(false)
const draftSaveIntent = ref<MaterialDraftSaveIntent | null>(null)
const draftSaveFlight = ref(false)
const lifecycleFlight = ref(false)
const operationFlight = computed(() => draftSaveFlight.value || lifecycleFlight.value)
type RevocationIntent = { admin_id: number; environment_id: number; code: string; version: string; revision: number }
const revocationIntent = ref<RevocationIntent | null>(null)
const revocationReading = ref(false)
const revocationObserved = ref<Pick<MaterialProfile, 'state' | 'status' | 'revision'> | null>(null)
let revocationReadGeneration = 0
const originalRevocationConfirmed = computed(() => Boolean(revocationIntent.value && revocationObserved.value
  && revocationObserved.value.state === 'revoked' && revocationObserved.value.status === 2
  && revocationObserved.value.revision === revocationIntent.value.revision))
const draftSaveReading = ref(false)
const draftSaveObserved = ref<MaterialProfile | null>(null)
const draftSaveFingerprint = ref<string | null>(null)
let draftSaveReadGeneration = 0
const runnerTarget = computed(() => originalSampleIntent.value ? {
  code: originalSampleIntent.value.profile_code, version: originalSampleIntent.value.profile_version,
  revision: originalSampleIntent.value.profile_revision, state: originalSampleIntent.value.profile_state
} : revision.value !== null && (state.value === 'draft' || state.value === 'published') ? {
  code: form.code, version: form.version, revision: revision.value, state: state.value
} : null)
const originalSampleIsDifferent = computed(() => Boolean(originalSampleIntent.value && (
  originalSampleIntent.value.profile_code !== form.code || originalSampleIntent.value.profile_version !== form.version
  || originalSampleIntent.value.profile_revision !== revision.value || originalSampleIntent.value.profile_state !== state.value)))
const originalPublicationConfirmed = computed(() => Boolean(publicationIntent.value && publicationObserved.value
  && publicationObserved.value.state === 'published' && publicationObserved.value.revision === publicationIntent.value.revision
  && publicationObserved.value.sample_run_id === publicationIntent.value.sample_run_id))
const originalDraftSaveConfirmed = computed(() => Boolean(draftSaveIntent.value && draftSaveObserved.value
  && draftSaveObserved.value.revision === (draftSaveIntent.value.expected_revision ?? 0) + 1
  && draftSaveFingerprint.value === draftSaveIntent.value.fingerprint_sha256))
const locked = computed(() => busy.value || operationFlight.value || draftSaveReading.value || Boolean(draftSaveIntent.value)
  || state.value !== 'draft' || Boolean(revocationIntent.value) || Boolean(publicationIntent.value) || intentStorageError.value)

function loadOriginalIntents(): void {
  revocationReadGeneration++
  revocationReading.value = false
  revocationObserved.value = null
  publicationReadGeneration++
  publicationReading.value = false
  publicationObserved.value = null
  draftSaveReadGeneration++
  draftSaveReading.value = false
  draftSaveObserved.value = null
  draftSaveFingerprint.value = null
  try {
    if (!props.environmentId) return
    const adminId = Number(userStore.info.id)
    draftSaveIntent.value = readMaterialDraftSaveIntent(adminId, props.environmentId)
    publicationIntent.value = readMaterialPublicationIntent(adminId, props.environmentId)
    revocationIntent.value = storedRevocation(adminId, props.environmentId)
    const journal = readMaterialSampleJournal(adminId, props.environmentId)
    originalSampleIntent.value = journal.intents.find(item => item.key === journal.active_key) ?? null
    intentStorageError.value = false
  } catch { intentStorageError.value = true }
}

async function savedDraftFingerprint(profile: MaterialProfile, intent: MaterialDraftSaveIntent): Promise<string> {
  if (profile.environment_id !== intent.environment_id || profile.code !== intent.code || profile.version !== intent.profile_version
    || !Number.isSafeInteger(profile.id) || profile.id < 1 || !Number.isSafeInteger(profile.revision) || profile.revision < 1
    || !((profile.state === 'draft' && profile.status === 2) || (profile.state === 'published' && profile.status === 1))
    || !profile.config || profile.config.code !== intent.code || profile.config.version !== intent.profile_version) {
    throw new Error('原草稿保存目标无法确认')
  }
  return materialDraftFingerprint(profile.name, profile.config)
}

function applySavedDraft(profile: MaterialProfile): void {
  if (!profile.config) throw new Error('原配置内容缺失')
  name.value = profile.name
  delete form.review_output_strategy
  Object.assign(form, profile.config)
  retainOriginalLists(profile.config)
  classifications.value = profile.config.document_classifications.join('\n')
  entities.value = profile.config.entity_types.join('\n')
  extensions.value = profile.config.extension_fields.join('\n')
  revision.value = profile.revision
  state.value = profile.state
  sampleRunId.value = undefined
  samples.value = []
  versionOrigin.value = null
  savedDraftSnapshot.value = currentDraftSnapshot()
  rememberProfile()
}

async function readOriginalDraftSave(): Promise<void> {
  const intent = draftSaveIntent.value
  if (!intent || intent.admin_id !== Number(userStore.info.id) || intent.environment_id !== props.environmentId
    || busy.value || operationFlight.value || draftSaveReading.value) return
  const current = ++draftSaveReadGeneration
  const identity = originalIntentEpoch
  draftSaveReading.value = true
  draftSaveObserved.value = null
  draftSaveFingerprint.value = null
  try {
    const result = await materialApi.read(intent.environment_id, intent.code, intent.profile_version)
    const fingerprint = await savedDraftFingerprint(result.profile, intent)
    if (current !== draftSaveReadGeneration || identity !== originalIntentEpoch || draftSaveIntent.value !== intent) return
    draftSaveObserved.value = result.profile
    draftSaveFingerprint.value = fingerprint
    message.value = originalDraftSaveConfirmed.value
      ? '原目标当前修订与保存内容摘要一致。请明确确认当前配置已保存；这不是服务器原请求回执。'
      : '原版本当前修订或内容与保存意图不一致。原保存结果仍未知，不会重新发送保存。'
    messageType.value = 'warning'
  } catch {
    if (current === draftSaveReadGeneration && identity === originalIntentEpoch && draftSaveIntent.value === intent) {
      message.value = '原草稿保存无法确认。未找到、无权或读取失败均保留原目标，请恢复读取条件后继续只读核对。'
      messageType.value = 'warning'
    }
  } finally {
    if (current === draftSaveReadGeneration && identity === originalIntentEpoch) draftSaveReading.value = false
  }
}

async function confirmOriginalDraftSave(): Promise<void> {
  const intent = draftSaveIntent.value
  const observed = draftSaveObserved.value
  if (!intent || !observed || !originalDraftSaveConfirmed.value || busy.value || operationFlight.value || draftSaveReading.value) return
  const identity = originalIntentEpoch
  const current = generation
  const readGeneration = draftSaveReadGeneration
  try {
    await ElMessageBox.confirm(`原环境 ${intent.environment_id} 的 ${intent.code} · ${intent.profile_version} 当前修订及内容与保存意图一致。确认使用这份已保存配置继续？`,
      '确认当前已保存配置', { confirmButtonText: '确认并继续', cancelButtonText: '保留原目标', type: 'warning' })
  } catch { return }
  if (identity !== originalIntentEpoch || current !== generation || readGeneration !== draftSaveReadGeneration
    || draftSaveIntent.value !== intent || !originalDraftSaveConfirmed.value || operationFlight.value
    || intent.admin_id !== Number(userStore.info.id) || intent.environment_id !== props.environmentId) return
  try {
    resolveMaterialDraftSaveIntent(intent)
    draftSaveIntent.value = null
    draftSaveObserved.value = null
    draftSaveFingerprint.value = null
    intentStorageError.value = false
    applySavedDraft(observed)
    message.value = '已确认原目标当前配置已保存；草稿仍需运行该修订的样本后发布。'
    messageType.value = 'success'
    await loadList()
    if (state.value === 'draft') await loadSamples()
  } catch { intentStorageError.value = true }
}

async function readOriginalPublication(): Promise<void> {
  const intent = publicationIntent.value
  if (!intent || intent.admin_id !== Number(userStore.info.id) || intent.environment_id !== props.environmentId || busy.value || publicationReading.value) return
  const current = ++publicationReadGeneration
  const identity = originalIntentEpoch
  publicationReading.value = true
  publicationObserved.value = null
  try {
    const result = await materialApi.read(intent.environment_id, intent.code, intent.version)
    if (current !== publicationReadGeneration || identity !== originalIntentEpoch || publicationIntent.value !== intent) return
    const profile = result.profile
    if (profile.environment_id !== intent.environment_id || profile.code !== intent.code || profile.version !== intent.version
      || !Number.isSafeInteger(profile.id) || profile.id < 1 || !Number.isSafeInteger(profile.revision) || profile.revision < 1) throw new Error('原配置身份未能确认')
    publicationObserved.value = { state: profile.state, revision: profile.revision, sample_run_id: profile.sample_run_id }
    message.value = originalPublicationConfirmed.value
      ? '原修订及原样本对应的配置当前已发布，请明确确认后继续。'
      : '已读取原版本的当前状态；与原修订或原样本不一致时保留原发布目标，不会重复发布。'
    messageType.value = 'warning'
  } catch {
    if (current === publicationReadGeneration && identity === originalIntentEpoch && publicationIntent.value === intent) {
      message.value = '无法确认原发布目标。保留原环境、代码、版本、修订和样本，读取失败不会释放原操作。'
      messageType.value = 'warning'
    }
  } finally { if (current === publicationReadGeneration && identity === originalIntentEpoch) publicationReading.value = false }
}

async function confirmOriginalPublication(): Promise<void> {
  const intent = publicationIntent.value
  if (!intent || !originalPublicationConfirmed.value || busy.value || publicationReading.value) return
  const current = generation
  try {
    await ElMessageBox.confirm(`确认原环境 ${intent.environment_id} 中 ${intent.code} · ${intent.version} 的修订 ${intent.revision}，样本 #${intent.sample_run_id} 当前已经发布？`,
      '确认原发布目标', { confirmButtonText: '确认已发布', cancelButtonText: '保留原目标', type: 'warning' })
  } catch { return }
  if (current !== generation || publicationIntent.value !== intent || !originalPublicationConfirmed.value
    || intent.admin_id !== Number(userStore.info.id) || intent.environment_id !== props.environmentId) return
  try {
    resolveMaterialPublicationIntent(intent)
    publicationIntent.value = null
    publicationObserved.value = null
    intentStorageError.value = false
    selected.value = `${intent.code}@${intent.version}`
    message.value = '已确认原配置当前已发布；可继续读取该版本或选择其他配置。'
    messageType.value = 'success'
    await loadList()
  } catch { intentStorageError.value = true }
}

const validationMessage = computed(() => {
  if (!name.value) return '请填写配置名称。'
  if (!/^[a-z][a-z0-9_]{0,63}$/.test(form.code) || /\s/.test(form.code)) return '代码须以小写字母开头，只包含小写字母、数字和下划线，最多 64 个字符。'
  if (!/^\d+\.\d+\.\d+$/.test(form.version) || /\s/.test(form.version) || form.version.length > 32) return '版本号请填写三个数字段，例如 1.0.1，最多 32 个字符。'
  if (revision.value === null && profiles.value.some(item => item.code === form.code && item.version === form.version)) {
    return '此代码和版本已经存在。编辑已有草稿请从列表打开；创建新版本请更换版本号。'
  }
  for (const [label, field] of [['材料分类', 'document_classifications'], ['实体类型', 'entity_types'],
    ['扩展字段', 'extension_fields']] as const) {
    const items = listValues(field)
    if (label !== '扩展字段' && items.length === 0) return `请至少填写一个${label}。`
    if (new Set(items).size !== items.length) return `${label}不能重复。`
    const maximum = label === '材料分类' ? 2000 : 64
    const invalidIndex = items.findIndex(item => item === '' || [...item].length > maximum
      || /[\p{Cc}\p{Cs}\p{Zl}\p{Zp}]/u.test(item) || item !== item.trim())
    if (invalidIndex !== -1) {
      const original = originalLists.value !== null && !editedLists[field]
      const recovery = state.value === 'draft'
        ? '请明确编辑此字段，去除首尾空白、控制字符和空行；若原项内有换行，请核对后拆分为独立项。修正后保存草稿，再运行新修订的样本。'
        : '请复制为新版本，明确修正此字段，再保存草稿并重新运行样本；原版本不会自动修改。'
      return `${original ? '已保存的配置中，' : ''}${label}第 ${invalidIndex + 1} 项无效：每项须为 1–${maximum} 个有效字符，不含控制字符、换行或首尾空白。${recovery}`
    }
    if (label === '扩展字段' && items.some(item => !/^[a-z][a-z0-9_]{0,63}$/.test(item))) {
      return '扩展字段须使用最多 64 个字符的小写英文字段代码，可包含数字和下划线。'
    }
  }
  if (!Number.isInteger(form.max_prompt_characters) || form.max_prompt_characters < 1 || form.max_prompt_characters > 200000) {
    return '提示词字符上限须为 1–200000 的整数。'
  }
  if (!Number.isInteger(form.max_output_characters) || form.max_output_characters < 1 || form.max_output_characters > 500000) {
    return '输出字符上限须为 1–500000 的整数。'
  }
  return ''
})
const canSave = computed(() => validationMessage.value === '')
const hasUnsavedChanges = computed(() => savedDraftSnapshot.value !== null && savedDraftSnapshot.value !== currentDraftSnapshot())
const canPublish = computed(() => Boolean(!publicationIntent.value && !revocationIntent.value && !draftSaveIntent.value && !operationFlight.value
  && !draftSaveReading.value && !intentStorageError.value && canSave.value && sampleRunId.value
  && savedDraftSnapshot.value !== null && !hasUnsavedChanges.value))

function lines(value: string): string[] {
  return value === '' ? [] : value.split(/\r?\n/)
}

function listValues(field: ListField): string[] {
  if (originalLists.value !== null && !editedLists[field]) return [...originalLists.value[field]]
  const text = {
    document_classifications: classifications.value, entity_types: entities.value,
    extension_fields: extensions.value
  }
  return lines(text[field])
}

function editList(field: ListField, value: string): void {
  editedLists[field] = true
  if (field === 'document_classifications') classifications.value = value
  else if (field === 'entity_types') entities.value = value
  else extensions.value = value
}

function retainOriginalLists(config: MaterialProfileConfig | null): void {
  originalLists.value = config === null ? null : {
    document_classifications: [...config.document_classifications],
    entity_types: [...config.entity_types], extension_fields: [...config.extension_fields]
  }
  editedLists.document_classifications = false
  editedLists.entity_types = false
  editedLists.extension_fields = false
}

function currentDraftSnapshot(): string {
  return JSON.stringify({
    name: name.value,
    profile: {
      ...form,
      document_classifications: listValues('document_classifications'),
      entity_types: listValues('entity_types'),
      extension_fields: listValues('extension_fields')
    }
  })
}

  function rememberProfile(clear = false): void {
    const query = { ...route.query }
    if (clear) {
      delete query.material_profile_code
      delete query.material_profile_version
      delete query.material_run_id
    } else {
      query.material_profile_code = form.code
      query.material_profile_version = form.version
      if (
        query.material_profile_code !== route.query.material_profile_code ||
        query.material_profile_version !== route.query.material_profile_version
      )
        delete query.material_run_id
    }
    void router.replace({ query })
  }

  function newDraft(clearLocation = false): void {
    if (clearLocation && (publicationIntent.value || revocationIntent.value || draftSaveIntent.value || operationFlight.value || intentStorageError.value)) return
    resumeRunId.value = undefined
    if (clearLocation) rememberProfile(true)
  generation++
  sampleGeneration++
  selected.value = ''
  revision.value = null
  sampleRunId.value = undefined
  samples.value = []
  samplesBusy.value = false
  savedDraftSnapshot.value = null
  state.value = 'draft'
  versionOrigin.value = null
  retainOriginalLists(null)
  name.value = '材料整理'
  Object.assign(form, {
    code: 'generic_material_analysis', version: '1.0.0', output_language: 'zh-CN',
    document_classifications: [], entity_types: [], extension_fields: [],
    max_prompt_characters: 20000, max_output_characters: 20000,
  review_output_strategy: 'neuron_json_extract_v1'
  })
  classifications.value = '通用材料'
  entities.value = '人物\n机构\n地点'
  extensions.value = ''
  message.value = ''
}

function stateLabel(value: MaterialProfile['state']): string {
  return { draft: '草稿', tested: '已试用', published: '已发布', revoked: '已撤销' }[value]
}

function createNextVersion(): void {
  if (busy.value || publicationIntent.value || revocationIntent.value || draftSaveIntent.value || operationFlight.value || draftSaveReading.value || intentStorageError.value || revision.value === null) return
    rememberProfile(true)
    resumeRunId.value = undefined
  const origin = { code: form.code, version: form.version }
  const parts = form.version.split('.')
  let nextVersion = ''
  if (parts.length === 3 && parts.every(part => /^\d+$/.test(part))) {
    let patch = BigInt(parts[2]) + 1n
    while (profiles.value.some(item => item.code === form.code && item.version === `${parts[0]}.${parts[1]}.${patch}`)) patch++
    nextVersion = `${parts[0]}.${parts[1]}.${patch}`
  }
  generation++
  sampleGeneration++
  selected.value = ''
  revision.value = null
  sampleRunId.value = undefined
  samples.value = []
  samplesBusy.value = false
  savedDraftSnapshot.value = null
  state.value = 'draft'
  versionOrigin.value = origin
  form.version = nextVersion
  message.value = ''
}

function requestedRunFromLocation(): number | undefined {
  const raw = route.query.material_run_id
  if (route.query.environment_id !== String(props.environmentId)
    || route.query.material_profile_code !== form.code || route.query.material_profile_version !== form.version
    || typeof raw !== 'string' || !/^[1-9][0-9]*$/.test(raw)) return undefined
  const runId = Number(raw)
  return Number.isSafeInteger(runId) ? runId : undefined
}

function syncResumeFromLocation(): void {
  const requestedRun = requestedRunFromLocation()
  resumeRunId.value = state.value === 'published'
    ? requestedRun
    : samples.value.some(sample => sample.sample_run_id === requestedRun) ? requestedRun : undefined
}

watch(() => [route.query.environment_id, route.query.material_profile_code,
  route.query.material_profile_version, route.query.material_run_id, state.value, revision.value], (location, previous) => {
  const code = route.query.material_profile_code
  const version = route.query.material_profile_version
  if (location.slice(0, 3).some((value, index) => value !== previous?.[index])
    && route.query.environment_id === String(props.environmentId)
    && typeof code === 'string' && typeof version === 'string'
    && profiles.value.some(profile => profile.code === code && profile.version === version)) {
    selected.value = `${code}@${version}`
  }
  syncResumeFromLocation()
  if (!busy.value && state.value === 'draft' && requestedRunFromLocation() !== undefined) void loadSamples()
})

async function loadSamples(): Promise<void> {
  const environmentId = props.environmentId
  const profileRevision = revision.value
  if (!environmentId || profileRevision === null || state.value !== 'draft') return
  const code = form.code
  const version = form.version
  const current = ++sampleGeneration
  samplesBusy.value = true
  try {
    const result = await materialApi.samples(environmentId, code, version, profileRevision)
    if (current !== sampleGeneration || props.environmentId !== environmentId
      || form.code !== code || form.version !== version || revision.value !== profileRevision || state.value !== 'draft') return
    samples.value = result.samples
    syncResumeFromLocation()
    if (!samples.value.some(item => item.sample_run_id === sampleRunId.value)) sampleRunId.value = undefined
  } catch {
    if (current === sampleGeneration) {
      samples.value = []
      sampleRunId.value = undefined
      message.value = '读取当前修订的样本失败，请检查后台权限并重试。'
      messageType.value = 'error'
    }
  } finally {
    if (current === sampleGeneration) samplesBusy.value = false
  }
}

watch(sampleRunId, async (value) => {
  if (
    state.value !== 'draft' ||
    busy.value ||
    samplesBusy.value ||
    !props.environmentId ||
    revision.value === null ||
    !value ||
    !Number.isSafeInteger(value) ||
    value < 1 ||
    !samples.value.some(item => item.sample_run_id === value)
  ) return
  const current = generation
  const environmentId = props.environmentId
  const code = form.code
  const version = form.version
  const profileRevision = revision.value
  await router.replace({ query: {
    ...route.query,
    environment_id: String(environmentId),
    material_profile_code: code,
    material_profile_version: version,
    material_run_id: String(value),
  } })
  if (current !== generation || props.environmentId !== environmentId
    || form.code !== code || form.version !== version || revision.value !== profileRevision
    || state.value !== 'draft' || requestedRunFromLocation() !== value
    || !samples.value.some(item => item.sample_run_id === value)) return
  resumeRunId.value = value
})

async function loadList(): Promise<void> {
  const environmentId = props.environmentId
  if (!environmentId || busy.value) return
  const current = ++generation
  busy.value = true
  try {
    const result = await materialApi.list(environmentId)
    if (current === generation) profiles.value = result.profiles
  } catch {
    if (current === generation) {
      message.value = '读取配置列表失败，请检查后台权限和 SandIAM 环境状态。'
      messageType.value = 'error'
    }
  } finally {
      if (current === generation) {
        busy.value = false
        if (restoreSelection) {
          restoreSelection = false
          const code = publicationIntent.value?.code ?? originalSampleIntent.value?.profile_code ?? route.query.material_profile_code
          const version = publicationIntent.value?.version ?? originalSampleIntent.value?.profile_version ?? route.query.material_profile_version
          if (
            (Boolean(publicationIntent.value || originalSampleIntent.value) || route.query.environment_id === String(environmentId)) &&
            typeof code === 'string' &&
            typeof version === 'string' &&
            profiles.value.some((item) => item.code === code && item.version === version)
          )
            selected.value = `${code}@${version}`
        }
      }
  }
}

watch(() => [props.environmentId, userStore.info.id, userStore.accessToken], () => {
  originalIntentEpoch++
  publicationReadGeneration++
  generation++
  sampleGeneration++
  busy.value = false
  samplesBusy.value = false
  samples.value = []
  profiles.value = []
      restoreSelection = true
  publicationIntent.value = null
  publicationObserved.value = null
  revocationIntent.value = null
  revocationObserved.value = null
  revocationReading.value = false
  revocationReadGeneration++
  originalSampleIntent.value = null
  publicationReading.value = false
  draftSaveReadGeneration++
  draftSaveReading.value = false
  draftSaveIntent.value = null
  draftSaveObserved.value = null
  draftSaveFingerprint.value = null
  intentStorageError.value = false
  newDraft()
  loadOriginalIntents()
  void loadList()
}, { immediate: true, flush: 'sync' })

onBeforeUnmount(() => {
  revocationReadGeneration++
  originalIntentEpoch++
  generation++
  sampleGeneration++
  publicationReadGeneration++
  draftSaveReadGeneration++
  draftSaveObserved.value = null
  draftSaveFingerprint.value = null
})

watch(selected, async value => {
  const environmentId = props.environmentId
  if (!value || !environmentId) return
  const item = profiles.value.find(profile => `${profile.code}@${profile.version}` === value)
  if (!item) return
    resumeRunId.value = undefined
  sampleGeneration++
  const current = ++generation
  busy.value = true
  try {
    const result = await materialApi.read(environmentId, item.code, item.version)
    if (current !== generation) return
    const profile = result.profile
      if (
        profile.code !== item.code ||
        profile.version !== item.version ||
        profile.environment_id !== environmentId
      )
        throw new Error('配置归属不一致')
    if (!profile.config) throw new Error('配置内容缺失')
    name.value = profile.name
    delete form.review_output_strategy
    Object.assign(form, profile.config)
    retainOriginalLists(profile.config)
    classifications.value = profile.config.document_classifications.join('\n')
    entities.value = profile.config.entity_types.join('\n')
    extensions.value = profile.config.extension_fields.join('\n')
    revision.value = profile.revision
    sampleRunId.value = undefined
    samples.value = []
    state.value = profile.state
    syncResumeFromLocation()
    versionOrigin.value = null
    savedDraftSnapshot.value = currentDraftSnapshot()
    message.value = ''
      rememberProfile()
  } catch {
    if (current === generation) {
      message.value = '读取配置失败，请刷新列表后重试。'
      messageType.value = 'error'
    }
  } finally {
    if (current === generation) {
      busy.value = false
      if (state.value === 'draft') void loadSamples()
    }
  }
})

async function save(): Promise<void> {
  const environmentId = props.environmentId
  const adminId = Number(userStore.info.id)
  if (!environmentId || !Number.isSafeInteger(adminId) || adminId < 1 || locked.value || !canSave.value) return
  const profile: MaterialProfileConfig = {
    ...form,
    document_classifications: [...listValues('document_classifications')],
    entity_types: [...listValues('entity_types')],
    extension_fields: [...listValues('extension_fields')]
  }
  const expectedRevision = revision.value
  const saveName = name.value.trim()
  const identity = originalIntentEpoch
  sampleGeneration++
  const current = ++generation
  draftSaveFlight.value = true
  busy.value = true
  let saved = false
  try {
    const fingerprint = await materialDraftFingerprint(saveName, profile)
    if (current !== generation || identity !== originalIntentEpoch || props.environmentId !== environmentId
      || Number(userStore.info.id) !== adminId) return
    try {
      retainMaterialDraftSaveIntent({ version: 1, admin_id: adminId, environment_id: environmentId,
        code: profile.code, profile_version: profile.version, expected_revision: expectedRevision, fingerprint_sha256: fingerprint })
      draftSaveIntent.value = readMaterialDraftSaveIntent(adminId, environmentId)
      if (!draftSaveIntent.value) throw new Error('原保存目标缺失')
    } catch {
      intentStorageError.value = true
      message.value = '原草稿保存目标未能可靠保留，本次尚未发送。请恢复会话存储并重读原操作记录。'
      messageType.value = 'warning'
      return
    }
    // Capture the stable ref value: Vue may wrap the stored metadata in a proxy.
    const intent = draftSaveIntent.value
    const result = await materialApi.save(environmentId, expectedRevision, saveName, profile)
    const observedFingerprint = await savedDraftFingerprint(result.profile, intent)
    if (current !== generation || identity !== originalIntentEpoch || draftSaveIntent.value !== intent
      || props.environmentId !== environmentId || Number(userStore.info.id) !== adminId) return
    if (result.profile.revision !== (expectedRevision ?? 0) + 1 || observedFingerprint !== intent.fingerprint_sha256) {
      throw new Error('保存响应与原目标不一致')
    }
    resolveMaterialDraftSaveIntent(intent)
    draftSaveIntent.value = null
    draftSaveObserved.value = null
    draftSaveFingerprint.value = null
    applySavedDraft(result.profile)
    saved = true
    message.value = '草稿已保存。请在下方用应用身份运行样本，完成后刷新成功样本并人工发布。'
    messageType.value = 'success'
  } catch {
    if (current === generation && identity === originalIntentEpoch) {
      if (!draftSaveIntent.value) intentStorageError.value = true
      message.value = '草稿保存结果未确认。原版本、保存前修订和内容摘要已保留；请读取原保存目标，不会按当前表单再次保存。'
      messageType.value = 'warning'
    }
  } finally {
    // A scope change or concurrent GET must not unlock an in-flight POST.
    draftSaveFlight.value = false
    if (current === generation && identity === originalIntentEpoch) busy.value = false
  }
  if (saved && current === generation && identity === originalIntentEpoch) {
    await loadList()
    await loadSamples()
  }
}

async function publish(): Promise<void> {
  const environmentId = props.environmentId
  const profileRevision = revision.value
  const runId = sampleRunId.value
  if (!environmentId || state.value !== 'draft' || busy.value || profileRevision === null || !runId || !canPublish.value) return
  const code = form.code
  const version = form.version
  const beforeConfirmation = generation
  try {
    await ElMessageBox.confirm(
      `确认用样本运行 #${runId} 核验并发布「${name.value} · ${version}」？`,
      '发布材料整理配置',
      { type: 'warning', confirmButtonText: '核验并发布', cancelButtonText: '取消' }
    )
  } catch {
    return
  }
  if (beforeConfirmation !== generation || props.environmentId !== environmentId || state.value !== 'draft'
    || revision.value !== profileRevision || sampleRunId.value !== runId || !canPublish.value) return
  const intent: MaterialPublicationIntent = {
    admin_id: Number(userStore.info.id), environment_id: environmentId,
    code, version, revision: profileRevision, sample_run_id: runId
  }
  try {
    retainMaterialPublicationIntent(intent)
    publicationIntent.value = intent
    publicationObserved.value = null
  } catch {
    intentStorageError.value = true
    message.value = '原发布目标无法持久保存，本次尚未发送发布。'
    messageType.value = 'warning'
    return
  }
  const current = ++generation
  busy.value = true
  lifecycleFlight.value = true
  try {
    const result = await materialApi.publish(environmentId, code, version, profileRevision, runId)
    if (result.profile.environment_id !== environmentId || result.profile.code !== code || result.profile.version !== version
      || result.profile.revision !== profileRevision || result.profile.sample_run_id !== runId || result.profile.state !== 'published') {
      throw new Error('原发布响应与冻结目标不一致')
    }
    if (current !== generation) return
    resolveMaterialPublicationIntent(intent)
    publicationIntent.value = null
    publicationObserved.value = null
    state.value = result.profile.state
    message.value = '样本成果与计量已核验，配置已发布。'
    messageType.value = 'success'
  } catch {
    if (current === generation) {
      message.value = '原发布结果未确认。请读取上方冻结的原版本、修订与样本；不会以当前草稿状态判断未发布或自动再次提交。'
      messageType.value = 'error'
    }
  } finally {
    lifecycleFlight.value = false
    if (current === generation) busy.value = false
  }
  if (current === generation) await loadList()
}

function revocationNamespace(adminId: number, environmentId: number): string {
  if (!Number.isSafeInteger(adminId) || adminId < 1 || !Number.isSafeInteger(environmentId) || environmentId < 1) throw new Error('原撤销归属无法确认')
  return `sand-ai:material-revocation:v1:${adminId}:${environmentId}`
}
function storedRevocation(adminId: number, environmentId: number): RevocationIntent | null {
  const raw = sessionStorage.getItem(revocationNamespace(adminId, environmentId))
  if (raw === null) return null
  const value: unknown = JSON.parse(raw)
  if (typeof value !== 'object' || value === null || Array.isArray(value)) throw new Error('原撤销记录无效')
  const item = value as Record<string, unknown>
  if (Object.keys(item).some(key => !['admin_id', 'environment_id', 'code', 'version', 'revision'].includes(key))
    || item.admin_id !== adminId || item.environment_id !== environmentId
    || typeof item.code !== 'string' || !/^[a-z][a-z0-9_]{0,63}$/.test(item.code)
    || typeof item.version !== 'string' || item.version.length > 32 || !/^\d+\.\d+\.\d+$/.test(item.version)
    || typeof item.revision !== 'number' || !Number.isSafeInteger(item.revision) || item.revision < 1) throw new Error('原撤销目标无法确认')
  return { admin_id: adminId, environment_id: environmentId, code: item.code, version: item.version, revision: item.revision }
}
function sameRevocation(a: RevocationIntent | null, b: RevocationIntent): boolean {
  return Boolean(a && a.admin_id === b.admin_id && a.environment_id === b.environment_id
    && a.code === b.code && a.version === b.version && a.revision === b.revision)
}
function retainRevocation(intent: RevocationIntent): void {
  if (Number(userStore.info.id) !== intent.admin_id || props.environmentId !== intent.environment_id
    || storedRevocation(intent.admin_id, intent.environment_id) !== null) throw new Error('原撤销目标已改变')
  sessionStorage.setItem(revocationNamespace(intent.admin_id, intent.environment_id), JSON.stringify(intent))
  if (!sameRevocation(storedRevocation(intent.admin_id, intent.environment_id), intent)) throw new Error('原撤销记录未保留')
}
function resolveRevocation(intent: RevocationIntent): void {
  if (Number(userStore.info.id) !== intent.admin_id || props.environmentId !== intent.environment_id
    || !sameRevocation(storedRevocation(intent.admin_id, intent.environment_id), intent)) throw new Error('原撤销记录已改变')
  sessionStorage.removeItem(revocationNamespace(intent.admin_id, intent.environment_id))
  if (storedRevocation(intent.admin_id, intent.environment_id) !== null) throw new Error('原撤销记录未解除')
  revocationIntent.value = null; revocationObserved.value = null
}
async function readOriginalRevocation(): Promise<void> {
  const intent = revocationIntent.value
  if (!intent || busy.value || operationFlight.value || revocationReading.value
    || intent.admin_id !== Number(userStore.info.id) || intent.environment_id !== props.environmentId) return
  const identity = originalIntentEpoch; const current = ++revocationReadGeneration
  revocationReading.value = true; revocationObserved.value = null
  try {
    const result = await materialApi.read(intent.environment_id, intent.code, intent.version)
    if (identity !== originalIntentEpoch || current !== revocationReadGeneration || revocationIntent.value !== intent) return
    const profile = result.profile
    if (profile.environment_id !== intent.environment_id || profile.code !== intent.code || profile.version !== intent.version
      || !Number.isSafeInteger(profile.revision) || profile.revision < 1) throw new Error('原撤销目标不一致')
    revocationObserved.value = { state: profile.state, status: profile.status, revision: profile.revision }
    message.value = originalRevocationConfirmed.value ? '固定版本当前已撤销，请明确确认后继续。此状态不是原操作回执。'
      : '原版本当前状态与撤销目标不符，保留原目标；不会再次发送撤销。'
    messageType.value = 'warning'
  } catch {
    if (identity === originalIntentEpoch && current === revocationReadGeneration) {
      message.value = '原撤销目标读取未确认。保留原版本与修订；404、拒绝读取或超时不证明撤销未执行。'
      messageType.value = 'warning'
    }
  } finally { if (identity === originalIntentEpoch && current === revocationReadGeneration) revocationReading.value = false }
}
async function confirmOriginalRevocation(): Promise<void> {
  const intent = revocationIntent.value; const identity = originalIntentEpoch
  if (!intent || busy.value || operationFlight.value || revocationReading.value || !originalRevocationConfirmed.value) return
  try { await ElMessageBox.confirm(`确认环境 ${intent.environment_id} 的 ${intent.code} · ${intent.version}，修订 ${intent.revision} 当前已撤销？此确认不会再次发送撤销。`,
    '确认原版本当前状态', { type: 'warning', confirmButtonText: '确认当前已撤销', cancelButtonText: '保留原目标' }) } catch { return }
  if (identity !== originalIntentEpoch || revocationIntent.value !== intent || busy.value || operationFlight.value
    || !originalRevocationConfirmed.value) return
  try {
    resolveRevocation(intent)
    intentStorageError.value = false
    message.value = '已确认固定版本当前已撤销，可读取其他配置；原历史结果仍保留。'; messageType.value = 'success'
    await loadList()
  } catch { intentStorageError.value = true }
}
async function revoke(): Promise<void> {
  const environmentId = props.environmentId; const profileRevision = revision.value
  if (!environmentId || state.value !== 'published' || busy.value || operationFlight.value || publicationIntent.value
    || revocationIntent.value || draftSaveIntent.value || intentStorageError.value || profileRevision === null) return
  const code = form.code; const version = form.version; const adminId = Number(userStore.info.id)
  const beforeConfirmation = generation; const identity = originalIntentEpoch
  try {
    await ElMessageBox.confirm(`确认撤销「${name.value} · ${version}」，修订 ${profileRevision}？撤销后应用不能再发起新的材料整理任务。`,
      '撤销材料整理配置', { type: 'warning', confirmButtonText: '确认撤销', cancelButtonText: '取消' })
  } catch { return }
  if (beforeConfirmation !== generation || identity !== originalIntentEpoch || props.environmentId !== environmentId
    || state.value !== 'published' || revision.value !== profileRevision || form.code !== code || form.version !== version
    || busy.value || operationFlight.value || publicationIntent.value || revocationIntent.value || draftSaveIntent.value || intentStorageError.value) return
  const intent: RevocationIntent = { admin_id: adminId, environment_id: environmentId, code, version, revision: profileRevision }
  revocationIntent.value = intent; revocationObserved.value = null
  try { retainRevocation(intent) } catch {
    intentStorageError.value = true; message.value = '原撤销目标无法可靠保留，本次尚未发送；请恢复会话存储并重读原目标。'; messageType.value = 'warning'; return
  }
  const current = ++generation
  busy.value = true; lifecycleFlight.value = true
  try {
    const result = await materialApi.revoke(environmentId, code, version)
    if (current !== generation || identity !== originalIntentEpoch) return
    if (result.profile.environment_id !== environmentId || result.profile.code !== code || result.profile.version !== version
      || result.profile.revision !== profileRevision || result.profile.state !== 'revoked' || result.profile.status !== 2) throw new Error('撤销响应与原目标不一致')
    resolveRevocation(intent)
    state.value = 'revoked'; message.value = '固定版本已撤销，新的材料整理任务无法使用此版本。'; messageType.value = 'success'
  } catch {
    if (current === generation && identity === originalIntentEpoch) {
      message.value = '原撤销结果未确认，原环境、版本和修订保留；只能只读核对原目标，不再次发送撤销。'; messageType.value = 'warning'
    }
  } finally {
    lifecycleFlight.value = false
    if (current === generation && identity === originalIntentEpoch) busy.value = false
  }
  if (current === generation && identity === originalIntentEpoch) await loadList()
}
</script>
