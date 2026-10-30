<template>
  <ElCard shadow="never">
    <template #header><div class="panel-head"><h3>试用已发布智能体</h3><p>选择智能体并提问。模型、资料、工具和回答要求均使用当前发布版本。</p></div></template>
    <ElEmpty v-if="!environmentId" description="先选择应用环境" />
    <template v-else>
      <ElAlert v-if="error" type="error" :title="error" show-icon class="notice" @close="error = ''" />
      <ElAlert v-if="continuationNotice" type="warning" :closable="false" class="notice" title="原任务续跑" :description="continuationNotice" />
      <div v-if="recoveryStorageError" class="notice">
        <ElAlert type="warning" :closable="false" title="任务恢复记录尚未确认"
          description="浏览器无法完整保存或读取原任务记录，不能视为没有未决请求。已知原任务与原请求仍保留，暂不能提交新问题或切换会话。请恢复浏览器会话存储后，重新读取恢复记录或找回原任务。" />
        <ElButton :disabled="busy || taskBusy" @click="restoreRunScope">重新读取恢复记录</ElButton>
      </div>
      <ElForm label-width="105px" @submit.prevent>
        <RuntimeApiKeyField v-model="apiKey" :persist-in-session="false" placeholder="输入拥有智能体调用权限的应用凭证" />
        <ElFormItem label="智能体">
          <ElSelect v-model="code" filterable :loading="agentsLoading" :disabled="sessionLocked" placeholder="选择已发布智能体" style="width: 100%">
            <ElOption v-for="agent in agents" :key="agent.code" :label="`${agent.name} · 第 ${agent.active_version_no} 版`" :value="agent.code" />
          </ElSelect>
          <ElButton v-if="more" link :loading="agentsLoading" @click="loadAgents">加载更多</ElButton>
          <p v-if="!agentsLoading && agents.length === 0" class="hint">先在“智能体配置”保存草稿并发布，再返回这里试用。</p>
        </ElFormItem>
        <ElFormItem label="会话">
          <ElInput v-model="conversationId" maxlength="96" :disabled="sessionLocked" placeholder="同一会话继续提问；新会话使用新编号" />
          <ElButton link :disabled="sessionLocked" @click="startConversation">开始新会话</ElButton>
          <p class="hint">会话只使用当前应用身份和当前发布版本允许的历史；不会跨渠道或其他调用者读取。</p>
        </ElFormItem>
        <ElFormItem label="问题"><ElInput v-model="query" :disabled="sessionLocked" maxlength="500" show-word-limit placeholder="例如：资料中对退款期限有什么说明？" /></ElFormItem>
        <ElFormItem>
          <ElButton type="primary" :loading="busy" :disabled="!canSubmit" @click="submit">
            提交问题
          </ElButton>
          <ElButton v-if="taskReference && !task" :disabled="busy || taskBusy || !apiKey.trim()" @click="refresh">刷新原任务</ElButton>
          <ElButton v-else-if="lastTaskUnread" :disabled="busy || taskBusy || !apiKey.trim()" @click="readLastTask">读取上次任务</ElButton>
        </ElFormItem>
      </ElForm>
      <div v-if="pendingRequestKey" class="pending-actions">
        <ElAlert type="warning" :closable="false" title="上次提交结果尚未确认"
          :description="pendingBody ? '原问题和会话已在本页固定。请按原请求号只读找回任务；结果未知期间不重新提交，也不换新请求号。' : '本页已刷新，原输入未保存在浏览器中。只能按原请求号找回任务，不能猜测输入或换新请求号。'" />
        <ElButton :disabled="busy || taskBusy || !apiKey.trim()" :loading="busy" @click="recover">找回原任务</ElButton>
      </div>
      <ElAlert v-else-if="sessionLocked && !busy && !taskBusy" type="info" :closable="false" class="notice"
        :title="lastTaskUnread ? '先读取上次任务，确认原调用状态后再继续会话。' : '原任务尚未完成或调用结果仍待确认。请刷新原任务，暂不切换会话或提交新问题。'" />
      <ElSpace class="task-actions">
        <ElButton :loading="historyBusy" :disabled="!code || !apiKey.trim() || !conversationId.trim()" @click="loadHistory(false)">读取本会话历史</ElButton>
        <ElButton v-if="nextHistory" :loading="historyBusy" @click="loadHistory(true)">读取更早任务</ElButton>
      </ElSpace>
      <ElAlert v-if="historyError" type="warning" :title="historyError" :closable="false" class="notice" />
      <ElTable v-if="history.length" :data="history" row-key="id" empty-text="此会话尚无任务">
        <ElTableColumn label="任务" prop="id" width="100" />
        <ElTableColumn label="状态" min-width="150"><template #default="{ row }">{{ historyStateLabel(row.state) }}</template></ElTableColumn>
        <ElTableColumn label="回答" min-width="240"><template #default="{ row }">{{ row.result?.output?.content || '尚无完整回答' }}</template></ElTableColumn>
        <ElTableColumn label="本次采用的历史" min-width="220"><template #default="{ row }">
          <span v-if="row.conversation_context">最近最多 {{ row.conversation_context.context_turn_limit }} 轮；采用任务 {{ row.conversation_context.history_task_ids.join('、') || '无' }}；更早未纳入 {{ row.conversation_context.omitted_prior_turn_count }} 轮</span>
          <span v-else>未附会话覆盖信息</span>
        </template></ElTableColumn>
        <ElTableColumn label="摘要覆盖" min-width="200"><template #default="{ row }">
          <span v-if="row.summary_coverage">完整覆盖 {{ row.summary_coverage.covered_task_ids.join('、') || '无' }}；部分覆盖 {{ row.summary_coverage.partially_covered_task_ids.join('、') || '无' }}；保留原文 {{ row.summary_coverage.retained_task_ids.join('、') || '无' }}</span>
          <span v-else>本次没有历史摘要</span>
        </template></ElTableColumn>
        <ElTableColumn label="操作" width="130"><template #default="{ row }"><ElButton link :disabled="!canReadHistoryTask(row.id)" @click="readHistoryTask(row.id)">读取原任务</ElButton></template></ElTableColumn>
      </ElTable>
      <template v-if="task">
        <p role="status">原任务 #{{ task.id }}：{{ historyStateLabel(task.state) }}。连接变化不会改变此任务状态。</p>
        <ElSpace wrap class="task-actions">
          <ElButton :loading="taskBusy" :disabled="busy" @click="refresh">刷新原任务</ElButton>
          <ElButton v-if="canCancel" :disabled="busy || taskBusy" @click="changeTask('cancel')">取消任务</ElButton>
          <ElButton v-if="canContinue" type="warning" :disabled="busy || taskBusy" @click="continueTask">续跑剩余节点</ElButton>
        <ElButton v-if="canRetry" type="warning" :disabled="busy || taskBusy" @click="changeTask('retry')">重试失败任务</ElButton>
          <ElButton v-if="polling" @click="stopPolling">暂停自动刷新</ElButton>
          <ElTag v-if="polling" effect="plain">自动刷新中</ElTag>
        </ElSpace>
        <ElAlert v-if="pollingNotice" type="info" :title="pollingNotice" :closable="false" class="notice" />
        <ElAlert v-if="task.state === 'failed' && !canRetry && !task.continuation" type="warning" :closable="false" class="notice"
          title="当前任务不能直接重试" :description="retryBlockedReason" />
        <section class="notice" aria-label="智能体实时预览">
        <ElAlert type="info" :closable="false" title="实时文本仅供预览，终态以原任务结果为准" />
        <p role="status">实时连接：{{ stream.transportLabel.value }} · 原运行 #{{ task.id }}</p>
        <p v-if="stream.preview.value && stream.transport.value === 'reading'">正在接收生成文本，回复尚未确认完成。</p>
        <p v-if="stream.notice.value" role="status">{{ stream.notice.value }}</p>
        <ElInput v-if="stream.preview.value" type="textarea" :rows="5" readonly :model-value="stream.preview.value" aria-label="安全实时文本预览" />
        <ElSpace>
          <ElButton v-if="stream.connected.value" @click="stream.pause">暂停实时读取</ElButton>
          <ElButton v-else-if="!['succeeded', 'failed', 'canceled', 'recovery_pending'].includes(task.state)" :disabled="unavailableTasks.has(task.id)" @click="stream.resume">沿原游标只读重连</ElButton>
          <ElButton :disabled="busy || taskBusy || !apiKey.trim()" @click="refresh">读取这次原运行的已保存结果</ElButton>
          <span class="hint">已读事件 {{ stream.cursor.value }} · 不重新提交问题</span>
        </ElSpace>
      </section>
      <AgentRunDetails :api-key="apiKey" :task="task" :cost="cost" :cost-records="costRecords" :cost-loading="costBusy" :cost-error="costError" @read-cost="loadCost" @denied="sourceDenied" @content-invalidated="contentInvalidated" />
      </template>
    </template>
  </ElCard>
</template>

<script setup lang="ts">
  import { computed, onUnmounted, ref, watch } from 'vue'
  import { useUserStore } from '@/store/modules/user'
  import { SandAiRuntimeError } from '../../api/runtime'
  import { isAgentReadDenied } from '../../api/agentEventReplay'
  import agentApi, { type AgentConversationTask } from '../../api/agent'
  import agentDefinitionApi, { type AgentDefinitionRow } from '../../api/agentDefinition'
  import type { SandAiTask } from '../../api/types'
  import { newRuntimeRequestId } from '../../api/runtime'
  import RuntimeApiKeyField from '../../components/RuntimeApiKeyField.vue'
  import AgentRunDetails from './AgentRunDetails.vue'
  import { useAgentTaskRun } from './useAgentTaskRun'
  import { useAgentEventReplay } from './useAgentEventReplay'

  const props = defineProps<{ environmentId: number | null }>()
  const userStore = useUserStore()
  const agents = ref<AgentDefinitionRow[]>([])
  const agentsLoading = ref(false)
  const code = ref('')
  const query = ref('')
  const apiKey = ref('')
  const pendingRequestKey = ref('')
  const recoveryStorageError = ref(false)
  const unavailableTasks = new Map<number, SandAiTask>()
  const lastTaskId = ref<number | null>(null)
  const busy = ref(false)
  const more = ref(false)
  const { canContinue, continuationNotice, continueTask, task, taskReference, cost, costRecords, costBusy, costError, taskBusy, error, polling, pollingNotice, canCancel, canRetry, canStartNew, retryBlockedReason,
    reset, startNewTask, stopPolling, acceptTask, readTask, refresh, loadCost, changeTask } = useAgentTaskRun(apiKey, () => {
    ++historyEpoch
    history.value = []
    nextHistory.value = null
    historyBusy.value = false
    clearPrivateInput()
    historyError.value = '当前权限已拒绝读取，已清除旧会话内容。'
  }, computed(() => `sand-ai-agent-continuation:${userStore.info.id}:${props.environmentId ?? 'none'}:${code.value}`))
  const streamCode = computed(() => code.value || null)
  const stream = useAgentEventReplay(apiKey, task, streamCode, refresh, () => { ++historyEpoch; reset(); clearPrivateInput(); history.value = []; nextHistory.value = null; historyBusy.value = false; error.value = '当前身份或来源已无法读取原任务，已清除旧回答和实时预览。' })
  const conversationId = ref(newRuntimeRequestId('conversation'))
  const history = ref<AgentConversationTask[]>([])
  const historyBusy = ref(false)
  const historyError = ref('')
  const nextHistory = ref<number | null>(null)
  let historyEpoch = 0
  type HistoryScope = Readonly<{
    adminId: typeof userStore.info.id; adminSession: string; environmentId: number | null
    code: string; conversationId: string; credential: string; generation: number
  }>
  let historyScope: HistoryScope | null = null
  let page = 0
  let epoch = 0
  let listEpoch = 0
  type PendingBody = Readonly<{ code: string; query: string; conversationId: string }>
  const pendingBody = ref<PendingBody | null>(null)
  const verifiedLastTaskId = ref<number | null>(null)
  let pendingConversationId: string | undefined
  let recoveryScope: string | null = null
  let restoringRunScope = false
  const lastTaskUnread = computed(() => !!lastTaskId.value && verifiedLastTaskId.value !== lastTaskId.value)
  const sessionLocked = computed(() => recoveryStorageError.value || busy.value || taskBusy.value || !!pendingRequestKey.value || lastTaskUnread.value || !canStartNew.value)
  const canSubmit = computed(() => !!props.environmentId && !!code.value && !!apiKey.value.trim() && !busy.value && !taskBusy.value
    && !recoveryStorageError.value && !pendingRequestKey.value && !lastTaskUnread.value && canStartNew.value && !!query.value.trim())

  function clearPrivateInput(): void {
    query.value = ''
    pendingBody.value = null
  }
  function storageKey(kind: string): string | null {
    return props.environmentId && code.value ? `sand-ai-agent-${kind}:${userStore.info.id}:${props.environmentId}:${code.value}` : null
  }
  function clearPending(): boolean {
    const key = storageKey('pending')
    try {
      if (!key) throw new Error('缺少原请求恢复范围')
      sessionStorage.removeItem(key)
      if (sessionStorage.getItem(key) !== null) throw new Error('原请求记录仍待确认')
      sessionStorage.removeItem(`${key}:conversation`)
    } catch {
      recoveryStorageError.value = true
      error.value = '原任务已保留，但原请求记录未能完整更新。请保持原请求，只读找回，暂勿提交新问题。'
      return false
    }
    pendingRequestKey.value = ''
    pendingBody.value = null
    pendingConversationId = undefined
    recoveryStorageError.value = false
    return true
  }
  function rememberTask(id: number): boolean {
    lastTaskId.value = id
    verifiedLastTaskId.value = id
    const key = storageKey('last-task')
    try {
      if (!key) throw new Error('缺少原任务恢复范围')
      sessionStorage.setItem(key, String(id))
      if (sessionStorage.getItem(key) !== String(id)) throw new Error('原任务记录未保存')
      return true
    } catch {
      recoveryStorageError.value = true
      error.value = '原任务已在本页保留，但任务编号未能保存。原请求仍保留，暂勿提交新问题；请恢复会话存储后找回原任务。'
      return false
    }
  }
  function acceptOriginalTask(result: SandAiTask): void {
    acceptTask(result)
    if (rememberTask(result.id)) clearPending()
  }
  function startConversation(): void {
    if (!sessionLocked.value && startNewTask()) conversationId.value = newRuntimeRequestId('conversation')
  }
  async function readLastTask(): Promise<void> {
    if (lastTaskId.value && !busy.value && !taskBusy.value) await readTask(lastTaskId.value)
  }
  function historyStateLabel(state: string): string {
    const labels: Record<string, string> = {
      authorization_pending: '等待授权，尚未完成',
      queued: '等待执行',
      running: '执行中',
      retrying: '重试中',
      recovery_pending: '结果待恢复，尚未确认',
      succeeded: '已完成',
      failed: '已失败',
      canceled: '已取消',
      unknown: '结果未知，尚未确认'
    }
    return Object.prototype.hasOwnProperty.call(labels, state) ? labels[state] : '状态未知，尚未确认'
  }
  function isCurrentHistoryScope(scope: HistoryScope | null): scope is HistoryScope {
    return scope !== null && scope.generation === historyEpoch
      && scope.adminId === userStore.info.id && scope.adminSession === userStore.accessToken
      && scope.environmentId === props.environmentId && scope.code === code.value
      && scope.conversationId === conversationId.value && scope.credential === apiKey.value
  }
  function canReadHistoryTask(id: number): boolean {
    if (busy.value || taskBusy.value || historyBusy.value || !apiKey.value.trim()
      || !props.environmentId || !code.value || !Number.isSafeInteger(id) || id <= 0
      || !isCurrentHistoryScope(historyScope) || !history.value.some(item => item.id === id)) return false
    // Reading is a GET under the current credential. While locked, retain only the
    // known original target (including the continuation target in taskReference).
    return !sessionLocked.value || id === taskReference.value || id === lastTaskId.value
  }
  async function readHistoryTask(id: number): Promise<void> {
    const member = history.value.find(item => item.id === id)
    const scope = historyScope
    if (!member || !canReadHistoryTask(id) || !isCurrentHistoryScope(scope)) return
    // readTask retains its original-task/continuation checks and generation guard.
    // It revalidates backend read permission; this path never clears a pending send key.
    await readTask(member.id)
  }
  async function loadAgents(): Promise<void> {
    const environmentId = props.environmentId
    if (!environmentId || agentsLoading.value) return
    const current = ++listEpoch
    const targetPage = page + 1
    agentsLoading.value = true
    try {
      const result = await agentDefinitionApi.list(environmentId, targetPage, 100)
      if (current !== listEpoch || environmentId !== props.environmentId) return
      agents.value.push(...result.data.filter(item => item.state === 'published' && item.status === 1))
      page = targetPage
      more.value = page * 100 < result.total
    } catch (failure) {
      if (current === listEpoch) error.value = failure instanceof Error ? failure.message : '智能体读取失败'
    } finally { if (current === listEpoch) agentsLoading.value = false }
  }
  async function loadHistory(older: boolean): Promise<void> {
    if (!code.value || !apiKey.value.trim() || historyBusy.value || !conversationId.value.trim()) return
    const current = ++historyEpoch
    const scope: HistoryScope = {
      adminId: userStore.info.id, adminSession: userStore.accessToken,
      environmentId: props.environmentId, code: code.value, conversationId: conversationId.value,
      credential: apiKey.value, generation: current
    }
    historyBusy.value = true
    historyError.value = ''
    try {
      const result = await agentApi.conversation(scope.credential, scope.code, scope.conversationId, older ? nextHistory.value ?? undefined : undefined)
      if (!isCurrentHistoryScope(scope)) return
      if (result.conversation_id !== scope.conversationId) throw new Error('会话返回范围不一致')
      const safeTasks = result.tasks.map(item => withoutPrivateContent(item))
      history.value = older ? [...history.value, ...safeTasks.filter(item => !history.value.some(prior => prior.id === item.id))] : safeTasks
      historyScope = scope
      nextHistory.value = result.next_before_task_id
      if (result.tasks.length === 0 && !older) historyError.value = '此会话尚无当前身份可读取的任务。'
    } catch (failure) {
      if (current !== historyEpoch) return
      historyError.value = failure instanceof Error ? failure.message : '会话历史读取失败'
      if (failure instanceof SandAiRuntimeError && isAgentReadDenied(failure.errorCode, failure.httpStatus)) {
        history.value = []
        nextHistory.value = null
        reset()
        clearPrivateInput()
      }
    } finally { if (current === historyEpoch) historyBusy.value = false }
  }
  watch([code, apiKey, conversationId, () => props.environmentId, () => userStore.info.id, () => userStore.accessToken], () => {
    historyScope = null
    ++historyEpoch
    history.value = []
    nextHistory.value = null
    historyBusy.value = false
    historyError.value = ''
  }, { flush: 'sync' })
  watch(task, (current, prior) => {
    if (current) {
      const safe = withoutPrivateContent(current)
      if (safe !== current) { task.value = safe; return }
      if (unavailableTasks.has(current.id)) { stream.pause(); stream.preview.value = '' }
    }
    if (current && current.id === lastTaskId.value) verifiedLastTaskId.value = current.id
    if (!current && prior) { ++historyEpoch; history.value = []; nextHistory.value = null; historyBusy.value = false }
    if (!current) verifiedLastTaskId.value = null
  }, { flush: 'sync' })
  watch(conversationId, () => {
    if (restoringRunScope) return
    ++epoch
    if (canStartNew.value) startNewTask()
    else reset()
    lastTaskId.value = null; verifiedLastTaskId.value = null
    const key = storageKey('conversation')
    try {
      if (key) sessionStorage.setItem(key, conversationId.value)
    } catch {
      recoveryStorageError.value = true
      error.value = '会话编号未能保存，暂不能提交新问题。请恢复会话存储后重新读取恢复记录。'
    }
  }, { flush: 'sync' })
  async function recover(): Promise<void> {
    if (!pendingRequestKey.value || !code.value || !apiKey.value.trim() || busy.value || taskBusy.value) return
    const current = epoch
    busy.value = true
    error.value = ''
    stopPolling()
    try {
      const result = await agentApi.readPublishedByRequestKey(apiKey.value, code.value, pendingRequestKey.value)
      if (current !== epoch) return
      acceptOriginalTask(result)
    } catch (failure) {
      if (current === epoch) error.value = failure instanceof Error ? failure.message : '原任务找回失败'
    } finally { if (current === epoch) busy.value = false }
  }
  function withoutPrivateContent(current: SandAiTask): SandAiTask {
    const retained = unavailableTasks.get(current.id)
    if (!retained) return current
    const calls = new Map((retained.agent_invocations ?? []).map(call => [call.request_id, call]))
    for (const call of current.agent_invocations ?? []) calls.set(call.request_id, call)
    const final = current.result?.invocation
    if (final?.request_id) calls.set(final.request_id, { request_id: final.request_id,
      state: final.state ?? 'unknown', latency_ms: final.latency_ms ?? null, error_code: final.error_code ?? null })
    if (!current.result && !current.error_summary && !current.steps?.some(step => step.error_summary)
      && !current.embedding_usage_cost && !current.embedding_invocations?.some(call => call.usage_cost)
      && calls.size === (current.agent_invocations?.length ?? 0)) return current
    return { ...current, result: null, error_summary: null,
      steps: current.steps?.map(step => ({ ...step, error_summary: null })), agent_invocations: [...calls.values()],
      embedding_usage_cost: null, embedding_invocations: current.embedding_invocations?.map(call => ({ ...call, usage_cost: null })) }
  }
  function contentInvalidated(safe: SandAiTask): void {
    if (task.value?.id !== safe.id) return
    unavailableTasks.set(safe.id, safe)
    ++historyEpoch
    history.value = []
    historyScope = null
    nextHistory.value = null
    historyBusy.value = false
    stopPolling()
    stream.pause()
    stream.preview.value = ''
    clearPrivateInput()
    cost.value = null
    costRecords.value = {}
    task.value = withoutPrivateContent(safe)
    error.value = '来源当前不可读取，关联回答与私有预览已清除。原任务、游标及费用请求号保留；费用需独立读取。'
  }
  watch(stream.preview, text => {
    if (text && task.value && unavailableTasks.has(task.value.id)) { stream.pause(); stream.preview.value = '' }
  }, { flush: 'sync' })
  watch([code, apiKey, conversationId, () => props.environmentId, () => userStore.info.id, () => userStore.accessToken], () => {
    unavailableTasks.clear()
  }, { flush: 'sync' })
  function sourceDenied(): void { ++historyEpoch; reset(); clearPrivateInput(); history.value = []; nextHistory.value = null; historyBusy.value = false; error.value = '当前工具或来源读取授权已失效，旧回答、历史及费用已清除。' }

  async function submit(): Promise<void> {
    if (!/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,95}$/.test(conversationId.value)) { error.value = '会话编号需为 1–96 位字母、数字或 _ . : -'; return }
    if (!canSubmit.value) { error.value = '先找回或刷新原任务，确认状态后再继续。'; return }
    const current = epoch
    if (!startNewTask()) return
    const body: PendingBody = Object.freeze({ code: code.value, query: query.value.trim(), conversationId: conversationId.value })
    const key = storageKey('pending')
    if (!key) return
    stopPolling()
    busy.value = true
    error.value = ''
    const requestKey = pendingRequestKey.value || newRuntimeRequestId('agent-published')
    let requestStarted = false
    try {
      // Persist the recovery journal before sending or locking this request in memory.
      // A failed write must not erase an existing journal or imply that a POST ran.
      if (!pendingRequestKey.value) sessionStorage.setItem(`${key}:conversation`, body.conversationId)
      sessionStorage.setItem(key, requestKey)
      if (sessionStorage.getItem(key) !== requestKey || sessionStorage.getItem(`${key}:conversation`) !== body.conversationId)
        throw new Error('原请求恢复记录未保存')
      pendingRequestKey.value = requestKey
      pendingConversationId = body.conversationId
      pendingBody.value = body
      requestStarted = true
      const result = await agentApi.submitPublished(apiKey.value, body.code, body.query, requestKey, body.conversationId)
      if (current !== epoch) return
      cost.value = null
      acceptOriginalTask(result.task)
      if (!recoveryStorageError.value) await refresh()
    } catch (failure) {
      if (current === epoch && !requestStarted) recoveryStorageError.value = true
      if (current === epoch) error.value = requestStarted
        ? failure instanceof Error ? failure.message : '提交失败，请先找回原任务'
        : '本次问题尚未发送：浏览器无法保存任务恢复记录。请释放浏览器存储空间或允许会话存储后，再手动提交。'
    } finally { if (current === epoch) busy.value = false }
  }
  watch(() => props.environmentId, () => {
    ++epoch
    ++listEpoch
    reset()
    busy.value = false
    agentsLoading.value = false
    agents.value = []
    apiKey.value = ''
    code.value = ''
    query.value = ''
    pendingRequestKey.value = ''
    recoveryStorageError.value = false
    lastTaskId.value = null
    verifiedLastTaskId.value = null
    pendingBody.value = null
    page = 0
    more.value = false
    void loadAgents()
  }, { immediate: true, flush: 'sync' })
  function restoreRunScope(): void {
    ++epoch
    clearPrivateInput()
    if (canStartNew.value && !pendingRequestKey.value && !recoveryStorageError.value) startNewTask()
    else reset()
    busy.value = false
    pendingBody.value = null
    verifiedLastTaskId.value = null
    recoveryStorageError.value = false
    const session = storageKey('conversation')
    const pending = storageKey('pending')
    const last = storageKey('last-task')
    if (pending !== recoveryScope) {
      pendingRequestKey.value = ''
      pendingConversationId = undefined
      lastTaskId.value = null
    }
    recoveryScope = pending
    try {
      const savedConversation = session ? sessionStorage.getItem(session) : null
      const savedPending = pending ? sessionStorage.getItem(pending) : null
      const savedPendingConversation = pending && savedPending ? sessionStorage.getItem(`${pending}:conversation`) : null
      const savedLast = last ? sessionStorage.getItem(last) : null
      const savedId = savedLast === null ? null : Number(savedLast)
      if (savedLast !== null && (!Number.isSafeInteger(savedId) || savedId === null || savedId <= 0))
        throw new Error('原任务编号无法读取')
      if (savedPending !== null && (!savedPending || !/^[\x21-\x7e]{1,512}$/.test(savedPending)))
        throw new Error('原请求号无法读取')
      restoringRunScope = true
      conversationId.value = savedPendingConversation || savedConversation || newRuntimeRequestId('conversation')
      pendingRequestKey.value = savedPending ?? ''
      pendingConversationId = savedPendingConversation ?? undefined
      lastTaskId.value = savedId
    } catch {
      recoveryStorageError.value = true
      error.value = '任务恢复记录无法完整读取，不能确认是否存在未决请求。请恢复会话存储后重新读取，暂勿提交新问题。'
    } finally { restoringRunScope = false }
  }
  watch([code, apiKey], restoreRunScope, { flush: 'sync' })
  watch([() => userStore.info.id, () => userStore.accessToken], () => {
    ++epoch; ++listEpoch; ++historyEpoch
    reset(); taskReference.value = null
    apiKey.value = ''; code.value = ''
    clearPrivateInput()
    pendingRequestKey.value = ''; pendingConversationId = undefined
    recoveryStorageError.value = false
    lastTaskId.value = null; verifiedLastTaskId.value = null
    history.value = []; nextHistory.value = null; historyBusy.value = false; historyError.value = ''
    busy.value = false; agentsLoading.value = false; agents.value = []; page = 0; more.value = false
    void loadAgents()
  }, { flush: 'sync' })
  onUnmounted(() => { ++epoch; ++listEpoch; ++historyEpoch; historyScope = null })
</script>

<style scoped lang="scss">
  .panel-head h3 { margin: 0; }
  .panel-head p, .hint { color: var(--el-text-color-secondary); font-size: 13px; line-height: 1.7; }
  .notice, .task-actions { margin: 14px 0; }
  .pending-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin: 12px 0; }
  .pending-actions .el-alert { flex: 1 1 260px; }
</style>
