<template>
  <ElCard shadow="never">
    <template #header><div class="panel-head"><h3>按次组合试用</h3><p>临时选择模型和回答要求，使用当前应用有权访问的私有资料。本次运行独立处理，可沿原游标恢复实时读取。常用组合可在“智能体配置”保存发布。</p></div></template>
    <ElAlert v-if="error" type="error" :title="error" class="notice" show-icon @close="error = ''" />
    <ElAlert v-if="continuationNotice" type="warning" :closable="false" class="notice" title="原任务续跑" :description="continuationNotice" />
    <ElForm label-width="105px" @submit.prevent>
      <RuntimeApiKeyField v-model="apiKey" :persist-in-session="false" placeholder="输入 SandIAM 授权的应用调用凭证" />
      <ElFormItem label="使用模型">
        <ElSelect v-model="form.model" :loading="modelsLoading" :disabled="submitting || taskBusy || !!pendingRequestKey || !canStartNew" filterable
          placeholder="先读取此凭证可用的聊天模型" style="width: 100%">
          <ElOption v-for="model in models" :key="model.code" :label="`${model.name} · ${model.code}`" :value="model.code" />
        </ElSelect>
        <ElButton link type="primary" :loading="modelsLoading" :disabled="!apiKey.trim() || submitting || taskBusy || !!pendingRequestKey || !canStartNew" @click="loadModels">读取可用模型</ElButton>
        <p v-if="modelsError" class="hint">{{ modelsError }}</p>
        <p v-else-if="modelsLoaded && models.length === 0" class="hint">此凭证暂无可用聊天模型。请先发布环境能力配置，并在 SandIAM 检查服务授权。</p>
      </ElFormItem>
      <ElFormItem label="回答要求"><ElInput v-model="form.instructions" type="textarea" :rows="4" maxlength="16000" show-word-limit
        :disabled="submitting || taskBusy || !!pendingRequestKey || !canStartNew" placeholder="例如：依据检索资料回答；逐项保留来源，资料不足时明确说明。" /></ElFormItem>
      <ElFormItem label="问题"><ElInput v-model="form.query" maxlength="500" show-word-limit :disabled="submitting || taskBusy || !!pendingRequestKey || !canStartNew" placeholder="输入要从资料中查证的问题" /></ElFormItem>
      <ElFormItem label="使用工具"><ElCheckbox :model-value="true" disabled>私有资料检索</ElCheckbox><p class="hint">仅检索当前调用身份有权访问的私有资料。</p></ElFormItem>
      <ElFormItem label="只读外部工具"><ReadonlyToolSelector v-model="readonlyTools" :environment-id="props.environmentId ?? null" :disabled="submitting || taskBusy || !!pendingRequestKey || !canStartNew" /></ElFormItem>
      <ElFormItem label="人工复核"><ElSwitch v-model="form.review_required" :disabled="submitting || taskBusy || !!pendingRequestKey || !canStartNew" /><p class="hint">开启后由业务使用者确认回答，再用于后续处理。</p></ElFormItem>
      <ElFormItem><ElButton type="primary" :loading="submitting" :disabled="!canSubmit || taskBusy" @click="submit">提交试用</ElButton></ElFormItem>
    </ElForm>
    <div v-if="pendingRequestKey" class="pending-actions">
      <ElAlert type="warning" :closable="false" title="上次提交结果尚未确认"
        description="请用当前有效凭证沿原请求号只读找回。结果未确认前不重新提交、不生成新请求号；页面刷新仅保留原请求号，不保存凭证或问题正文。" />
      <ElButton :loading="recovering" :disabled="!canReadOriginal || submitting || taskBusy || !apiKey.trim()" @click="recoverPending">沿原请求号找回</ElButton>
    </div>
    <ElAlert v-if="originalTaskId && !task" type="warning" :closable="false" class="notice"
      title="原任务尚未重新读取" description="当前回答和费用已清除，请用有效授权刷新原任务确认状态。未确认前不能开始另一任务。" />
    <ElButton v-if="originalTaskId && !task" :loading="taskBusy" :disabled="!canReadOriginal || !apiKey.trim() || submitting" @click="readOriginalTask">刷新原任务 #{{ originalTaskId }}</ElButton>
    <ElAlert v-if="!canReadOriginal" type="warning" :closable="false" class="notice" title="原请求属于另一环境" description="请切回原环境，并使用原调用身份读取；原请求号和任务编号保留，不在当前环境重新提交。" />
    <ElButton v-if="task && canStartNew && !pendingRequestKey" :disabled="submitting || taskBusy" @click="startNew">明确开始另一任务</ElButton>
    <template v-if="task">
      <ElSpace wrap class="task-actions">
        <ElButton :loading="taskBusy" :disabled="submitting" @click="refresh">刷新原任务</ElButton>
        <ElButton v-if="canCancel" :disabled="submitting || taskBusy" @click="changeTask('cancel')">取消任务</ElButton>
        <ElButton v-if="canContinue" type="warning" :disabled="submitting || taskBusy" @click="continueTask">续跑剩余节点</ElButton>
        <ElButton v-if="canRetry" type="warning" :disabled="submitting || taskBusy" @click="changeTask('retry')">重试失败任务</ElButton>
        <ElButton v-if="polling" @click="stopPolling">暂停自动刷新</ElButton>
        <ElTag v-if="polling" effect="plain">自动刷新中</ElTag>
      </ElSpace>
      <ElAlert v-if="pollingNotice" type="info" :title="pollingNotice" :closable="false" class="notice" />
      <ElAlert v-if="task.state === 'failed' && !canRetry && !task.continuation" type="warning" :closable="false" class="notice"
        title="当前任务不能直接重试" :description="retryBlockedReason" />
      <section class="notice" aria-label="智能体实时预览">
        <ElAlert type="info" :closable="false" title="实时文本仅供预览，终态以原任务结果为准" />
        <p v-if="stream.notice.value" role="status">{{ stream.notice.value }}</p>
        <ElInput v-if="stream.preview.value" type="textarea" :rows="5" readonly :model-value="stream.preview.value" aria-label="安全实时文本预览" />
        <ElSpace>
          <ElButton v-if="stream.connected.value" @click="stream.pause">暂停实时读取</ElButton>
          <ElButton v-else-if="!['succeeded', 'failed', 'canceled', 'recovery_pending'].includes(task.state)" @click="stream.resume">沿原游标只读重连</ElButton>
          <span class="hint">已读事件 {{ stream.cursor.value }} · 不重新提交问题</span>
        </ElSpace>
      </section>
      <AgentRunDetails :api-key="apiKey" :task="task" :cost="cost" :cost-records="costRecords" :cost-loading="costBusy" :cost-error="costError" @read-cost="loadCost" @denied="sourceDenied" />
    </template>
  </ElCard>
</template>

<script setup lang="ts">
  import { computed, onUnmounted, reactive, ref, watch } from 'vue'
  import { ElMessageBox } from 'element-plus'
  import { useUserStore } from '@/store/modules/user'
  import ReadonlyToolSelector from './ReadonlyToolSelector.vue'
  import type { AgentReadonlyToolSelection } from '../../api/agentDefinition'
  import type { AgentSubmitPayload } from '../../api/agent'
  import agentApi from '../../api/agent'
  import taskApi, { type RuntimeChatModel } from '../../api/task'
  import { newRuntimeRequestId } from '../../api/runtime'
  import RuntimeApiKeyField from '../../components/RuntimeApiKeyField.vue'
  import AgentRunDetails from './AgentRunDetails.vue'
  import { useAgentTaskRun } from './useAgentTaskRun'
  import { useAgentEventReplay } from './useAgentEventReplay'

  const props = defineProps<{ environmentId?: number | null }>()
  const userStore = useUserStore()
  const recovering = ref(false)
  const recoveryRecordInvalid = ref(false)
  const originalEnvironment = ref<number | null>(props.environmentId ?? null)
  const originalTaskId = ref<number | null>(null)
  const readonlyTools = ref<AgentReadonlyToolSelection[]>([])
  let pendingPayload: AgentSubmitPayload | null = null
  const apiKey = ref('')
  const streamCode = ref<string | null>(null)
  const models = ref<RuntimeChatModel[]>([])
  const modelsLoading = ref(false)
  const modelsLoaded = ref(false)
  const modelsError = ref('')
  const submitting = ref(false)
  const pendingRequestKey = ref('')
  const form = reactive({ model: '', instructions: '依据检索资料回答，逐项保留来源；资料不足时明确说明，不补写未查证事实。', query: '', review_required: true })
  const { canContinue, continuationNotice, continueTask, task, taskReference, canStartNew, retryBlockedReason, startNewTask,
    cost, costRecords, costBusy, costError, taskBusy, error, polling, pollingNotice, canCancel, canRetry,
    reset, stopPolling, acceptTask, readTask, refresh, loadCost, changeTask } = useAgentTaskRun(apiKey, undefined, computed(() => `sand-ai-agent-continuation:${userStore.info.id}:${props.environmentId ?? 'none'}:adhoc`))
  const canSubmit = computed(() => !recoveryRecordInvalid.value && !pendingRequestKey.value && canStartNew.value && !originalTaskId.value && !taskReference.value
    && !!apiKey.value.trim() && !!form.instructions.trim() && !!form.query.trim()
    && models.value.some(model => model.code === form.model)
    && readonlyTools.value.every(tool => /^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/.test(tool.business_scope)))
  // Only opaque recovery references survive reload. Credentials and request bodies remain in memory.
  const storageKey = computed(() => `sand-ai-agent-adhoc-reference:${userStore.info.id}`)
  const canReadOriginal = computed(() => (!pendingRequestKey.value && originalTaskId.value === null)
    || originalEnvironment.value === (props.environmentId ?? null))
  function persistReference(): boolean {
    try {
      if (!pendingRequestKey.value && originalTaskId.value === null) sessionStorage.removeItem(storageKey.value)
      else sessionStorage.setItem(storageKey.value, JSON.stringify({ request_key: pendingRequestKey.value,
        task_id: originalTaskId.value, environment_id: originalEnvironment.value }))
      return true
    } catch { error.value = '浏览器未能保存原请求号，请保持本页并确认原任务；本次不会发送新请求。'; return false }
  }
  function restoreReference(): void {
    recoveryRecordInvalid.value = false
    pendingRequestKey.value = ''
    originalTaskId.value = null
    originalEnvironment.value = props.environmentId ?? null
    try {
      const raw: unknown = JSON.parse(sessionStorage.getItem(storageKey.value) || 'null')
      if (raw === null) {
        originalTaskId.value = taskReference.value
        return
      }
      if (typeof raw !== 'object' || Array.isArray(raw)) throw new Error('invalid reference')
      const saved = raw as Record<string, unknown>
      if (typeof saved.request_key !== 'string' || (saved.request_key !== '' && !/^[\x21-\x7e]{1,512}$/.test(saved.request_key))
        || (saved.task_id !== null && (typeof saved.task_id !== 'number' || !Number.isSafeInteger(saved.task_id) || saved.task_id <= 0))
        || (saved.environment_id !== null && (typeof saved.environment_id !== 'number' || !Number.isSafeInteger(saved.environment_id) || saved.environment_id <= 0)))
        throw new Error('invalid reference')
      pendingRequestKey.value = saved.request_key
      originalTaskId.value = typeof saved.task_id === 'number' ? saved.task_id : null
      originalEnvironment.value = typeof saved.environment_id === 'number' ? saved.environment_id : null
      if (canReadOriginal.value && originalTaskId.value !== null) taskReference.value = originalTaskId.value
    } catch { recoveryRecordInvalid.value = true; error.value = '原请求记录无法读取，请保留本页信息并核查原任务，暂勿重新提交。' }
  }
  restoreReference()
  // A scope reset clears the displayed task, never the durable locator for an unresolved original run.
  const stream = useAgentEventReplay(apiKey, task, streamCode, refresh, () => { reset(); error.value = '当前身份或来源已无法读取原任务，已清除旧回答和实时预览。' })
  let epoch = 0
  let modelsEpoch = 0
  async function loadModels(): Promise<void> {
    if (!apiKey.value.trim() || modelsLoading.value || pendingRequestKey.value || !canStartNew.value) return
    const current = ++modelsEpoch
    modelsLoading.value = true
    modelsError.value = ''
    try {
      const result = await taskApi.models(apiKey.value)
      if (current !== modelsEpoch) return
      models.value = result.data.filter(model => model.type === 'chat' && model.capabilities.includes('chat'))
      modelsLoaded.value = true
      if (!pendingRequestKey.value && !models.value.some(model => model.code === form.model)) form.model = ''
    } catch (failure) {
      if (current === modelsEpoch) {
        models.value = []
        modelsError.value = failure instanceof Error ? failure.message : '模型读取失败，请检查应用凭证和授权后重试'
      }
    } finally { if (current === modelsEpoch) modelsLoading.value = false }
  }
  async function submit(): Promise<void> {
    if (!canSubmit.value || submitting.value || recovering.value || taskBusy.value) return
    const current = epoch
    submitting.value = true
    error.value = ''
    stopPolling()
    originalEnvironment.value = props.environmentId ?? null
    pendingRequestKey.value ||= newRuntimeRequestId('agent')
    try {
      pendingPayload ??= {
        model: form.model, instructions: form.instructions.trim(), query: form.query.trim(),
        tools: ['retrieval.search'], review_required: form.review_required,
        ...(readonlyTools.value.length ? { readonly_tools: readonlyTools.value.map(tool => ({ ...tool })), max_output_tokens: 512 } : {})
      }
      if (!persistReference()) { pendingRequestKey.value = ''; pendingPayload = null; return }
      const result = await agentApi.submit(apiKey.value, pendingPayload, pendingRequestKey.value)
      if (current !== epoch) return
      originalTaskId.value = result.task.id
      acceptTask(result.task)
      pendingRequestKey.value = ''
      pendingPayload = null
      persistReference()
      cost.value = null
      await refresh()
    } catch (failure) {
      if (current === epoch) error.value = failure instanceof Error ? failure.message : '提交结果未确认，请沿原请求号只读找回，暂勿重新提交'
    } finally { if (current === epoch) submitting.value = false }
  }
  function sourceDenied(): void { reset(); error.value = '当前工具或来源读取授权已失效，旧回答、来源和费用已清除。' }
  watch(() => props.environmentId, () => {
    ++epoch
    ++modelsEpoch
    reset()
    apiKey.value = ''
    // The invalidated request cannot release these flags from its fenced finally block.
    submitting.value = false
    recovering.value = false
    modelsLoading.value = false
    modelsLoaded.value = false
    models.value = []
    modelsError.value = ''
    form.model = ''; form.query = ''; form.instructions = ''; readonlyTools.value = []
    pendingPayload = null
    // Only opaque original references survive the environment change.
    if (canReadOriginal.value && originalTaskId.value !== null) taskReference.value = originalTaskId.value
  }, { flush: 'sync' })

  async function recoverPending(): Promise<void> {
    if (!canReadOriginal.value || !pendingRequestKey.value || !apiKey.value.trim() || recovering.value || submitting.value || taskBusy.value) return
    const current = epoch
    const originalKey = pendingRequestKey.value
    recovering.value = true
    error.value = ''
    try {
      const result = await agentApi.readAdHocByRequestKey(apiKey.value, originalKey)
      if (current !== epoch || pendingRequestKey.value !== originalKey) return
      originalTaskId.value = result.id
      acceptTask(result)
      pendingRequestKey.value = ''
      pendingPayload = null
      persistReference()
      await readTask(result.id)
    } catch (failure) {
      if (current === epoch) error.value = failure instanceof Error ? failure.message : '原请求尚未找回，请继续读取原请求，暂勿重新提交'
    } finally { if (current === epoch) recovering.value = false }
  }
  async function readOriginalTask(): Promise<void> {
    if (canReadOriginal.value && originalTaskId.value !== null && !submitting.value && !recovering.value) await readTask(originalTaskId.value)
  }
  async function startNew(): Promise<void> {
    if (!canStartNew.value || pendingRequestKey.value || submitting.value || recovering.value || taskBusy.value) return
    const current = epoch
    try { await ElMessageBox.confirm('原任务和子调用已确认终态。另一任务将使用新的请求号和当前配置，重新校验授权，并可能产生新的费用；原结果和费用保留。', '开始另一任务') }
    catch { return }
    if (current === epoch && !submitting.value && !taskBusy.value && !pendingRequestKey.value && startNewTask()) {
      pendingPayload = null
      originalTaskId.value = null
      persistReference()
      originalEnvironment.value = props.environmentId ?? null
    }
  }
  watch(apiKey, () => {
    ++epoch
    ++modelsEpoch
    reset()
    submitting.value = false
    recovering.value = false
    models.value = []
    modelsLoading.value = false
    modelsLoaded.value = false
    modelsError.value = ''
    form.model = ''
    form.query = ''
    form.instructions = ''
    readonlyTools.value = []
    pendingPayload = null
  }, { flush: 'sync' })
  watch([() => userStore.info.id, () => userStore.accessToken], () => {
    ++epoch; ++modelsEpoch
    reset(); taskReference.value = null
    apiKey.value = ''
    submitting.value = false; recovering.value = false
    models.value = []; modelsLoading.value = false; modelsLoaded.value = false; modelsError.value = ''
    form.model = ''; form.query = ''; form.instructions = ''; readonlyTools.value = []; pendingPayload = null
    restoreReference()
  }, { flush: 'sync' })
  onUnmounted(() => { ++epoch; ++modelsEpoch })
</script>

<style scoped lang="scss">
  .panel-head h3 { margin: 0; }
  .panel-head p, .hint { color: var(--el-text-color-secondary); font-size: 13px; line-height: 1.7; }
  .notice, .task-actions { margin: 14px 0; }
  .pending-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin: 12px 0; }
  .pending-actions .el-alert { flex: 1 1 260px; }
</style>
