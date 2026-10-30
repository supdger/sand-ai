<template>
  <section class="preview-note" aria-label="原消息工具结果与失败恢复">
    <p role="status">原消息任务 #{{ task.id }}：{{ taskStateLabel }} · 原消息读取：{{ readStateLabel }}</p>
    <ElAlert v-if="run.error.value" type="warning" :title="run.error.value" :closable="false" />
    <ElAlert v-if="run.continuationNotice.value" type="info" title="原消息续跑"
      :description="run.continuationNotice.value" :closable="false" />
    <ElSpace wrap>
      <ElButton :loading="run.taskBusy.value" :disabled="!apiKey.trim() || run.taskBusy.value"
        @click="recover">只读找回这条原消息</ElButton>
      <ElButton v-if="run.canContinue.value" type="warning"
        :disabled="disabled || run.taskBusy.value" @click="run.continueTask">
        续跑这条消息的剩余节点
      </ElButton>
      <ElButton v-if="run.polling.value" @click="run.stopPolling">暂停自动刷新</ElButton>
    </ElSpace>
    <p v-if="run.pollingNotice.value" role="status">{{ run.pollingNotice.value }}</p>
    <p>工具执行结果未明时只读找回，不重新发送消息或工具。续跑资格由原任务服务确认，新增调用按实际用量计费。</p>
    <details v-if="run.task.value">
      <summary>查看原任务节点、工具来源与费用</summary>
      <AgentRunDetails :api-key="apiKey" :task="run.task.value" :cost="run.cost.value"
        :cost-records="run.costRecords.value" :cost-loading="run.costBusy.value"
        :cost-error="run.costError.value" :before-read="authorizeRead"
        @read-cost="readCosts" @denied="deny" @content-invalidated="contentInvalidated" />
    </details>
  </section>
</template>

<script setup lang="ts">
  import { computed, onUnmounted, ref, watch } from 'vue'
  import channelApi, { type ChannelDeliveryPlatform } from '../../api/channelBinding'
  import { SandAiRuntimeError } from '../../api/runtime'
  import { isAgentReadDenied } from '../../api/agentEventReplay'
  import type { SandAiTask } from '../../api/types'
  import AgentRunDetails from './AgentRunDetails.vue'
  import { useAgentTaskRun } from './useAgentTaskRun'

  const props = defineProps<{
    apiKey: string; task: SandAiTask; channelCode: string; conversationId: string
    senderId: string; deliveryPlatform?: ChannelDeliveryPlatform; scope: string; disabled: boolean
  }>()
  const emit = defineEmits<{ updated: [task: SandAiTask]; denied: [] }>()
  const credential = computed(() => props.apiKey)
  const continuationScope = computed(() => `sand-ai-channel-agent-continuation:${props.scope}:${props.task.id}`)
  const unavailableTasks = new Map<number, SandAiTask>()
  let live = true
  let generation = 0
  const readState = ref<'idle' | 'reading' | 'read' | 'disconnected' | 'denied'>('idle')
  const readStateLabel = computed(() => ({ idle: '尚未刷新', reading: '正在读取原记录', read: '已读取原记录',
    disconnected: '读取中断，原任务状态未改变', denied: '当前身份不可读取' })[readState.value])
  const taskStateLabel = computed(() => {
    const states: Record<string, string> = { queued: '等待执行', authorization_pending: '等待授权', running: '正在处理',
      retrying: '重试中', recovery_pending: '结果待恢复，尚未确认', succeeded: '已完成', failed: '已失败', canceled: '已取消' }
    return states[props.task.state] ?? '状态未知，需读取原记录'
  })
  const run = useAgentTaskRun(credential, deny, continuationScope, {
    readTask: async (token, taskId) => (await readOwnedTask(token, taskId)).task,
    beforeContinue: async (token, expected) => {
      if (props.disabled) throw new Error('当前会话有待确认操作，请先沿原号找回。')
      const original = await readOwnedTask(token, expected.id)
      if (props.disabled) throw new Error('当前会话状态已变化，暂不能续跑。')
      if (expected.id <= original.closedAfter) throw new Error('这条消息所属上下文已关闭，仅可查阅原记录。')
      if (original.task.attempt_count !== expected.attempt_count
        || original.task.agent_definition_version?.snapshot_sha256 !== expected.agent_definition_version?.snapshot_sha256
        || original.task.state !== 'failed' || original.task.continuation?.available !== true) {
        throw new Error('原任务状态或续跑资格已变化，请先只读找回这条消息。')
      }
    }
  })

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
    if (!live || run.task.value?.id !== safe.id || props.task.id !== safe.id) return
    unavailableTasks.set(safe.id, safe)
    run.stopPolling()
    run.cost.value = null
    run.costRecords.value = {}
    run.acceptTask(withoutPrivateContent(safe), false)
    run.error.value = '来源当前不可读取，关联回答及工具预览已清除。原消息任务与费用请求号保留，可独立读取费用。'
  }
  function deny(): void {
    ++generation
    run.reset()
    readState.value = 'denied'
    emit('denied')
  }
  async function readOwnedTask(token: string, taskId: number): Promise<{ task: SandAiTask; closedAfter: number }> {
    if (!live || !token.trim() || token !== props.apiKey || taskId !== props.task.id
      || !Number.isSafeInteger(taskId) || taskId <= 0 || taskId >= Number.MAX_SAFE_INTEGER) {
      throw new Error('原消息定位不可用，不能读取其他任务。')
    }
    const owner = props.scope, current = generation
    const code = props.channelCode, conversation = props.conversationId, sender = props.senderId, platform = props.deliveryPlatform
    // The existing paginated channel endpoint proves current ownership of this exact message.
    const page = await channelApi.conversation(token, code, conversation, sender, taskId + 1, platform)
    if (!live || current !== generation || token !== props.apiKey || owner !== props.scope
      || code !== props.channelCode || conversation !== props.conversationId || sender !== props.senderId
      || platform !== props.deliveryPlatform || taskId !== props.task.id) throw new Error('会话身份已变化，旧读取结果未采用。')
    const message = page.messages.find(item => item.task.id === taskId)
    if (!message) {
      deny()
      throw new Error('当前渠道会话已无法读取这条原消息。')
    }
    return { task: withoutPrivateContent(message.task), closedAfter: page.closed_after_task_id }
  }
  async function authorizeRead(): Promise<void> {
    const original = await readOwnedTask(props.apiKey, props.task.id)
    run.acceptTask(original.task, false)
  }
  async function recover(): Promise<void> {
    const current = generation
    readState.value = 'reading'
    const read = await run.readTask(props.task.id)
    if (!live || current !== generation) return
    readState.value = read ? 'read' : 'disconnected'
  }
  async function readCosts(): Promise<void> {
    const current = generation, owner = props.scope, token = props.apiKey
    try {
      await authorizeRead()
      if (!live || current !== generation || owner !== props.scope || token !== props.apiKey) return
      await run.loadCost()
    } catch (failure) {
      if (!live || current !== generation || owner !== props.scope || token !== props.apiKey) return
      if (failure instanceof SandAiRuntimeError && isAgentReadDenied(failure.errorCode, failure.httpStatus)) deny()
      else run.costError.value = failure instanceof Error ? failure.message : '原消息费用读取失败。'
    }
  }
  watch(() => [props.scope, props.apiKey], () => { ++generation; unavailableTasks.clear(); run.reset(); readState.value = 'idle' }, { flush: 'sync' })
  watch(() => props.task, task => {
    const safe = withoutPrivateContent(task)
    if (run.task.value !== safe) run.acceptTask(safe, false)
  }, { immediate: true, flush: 'sync' })
  watch(run.task, task => {
    if (task) {
      const safe = withoutPrivateContent(task)
      if (safe !== task) { run.task.value = safe; return }
    }
    if (live && task && task.id === props.task.id && task !== props.task) emit('updated', task)
  }, { flush: 'sync' })
  onUnmounted(() => { live = false; ++generation; run.reset() })
</script>
