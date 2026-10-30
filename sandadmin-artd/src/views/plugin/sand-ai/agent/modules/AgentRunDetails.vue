<template>
  <section aria-label="智能体运行详情">
    <ElDescriptions :column="2" border>
      <ElDescriptionsItem label="任务">#{{ task.id }}</ElDescriptionsItem>
      <ElDescriptionsItem v-if="task.agent_definition_version" label="实际定义版本">{{ task.agent_definition_version.code }} · 第 {{ task.agent_definition_version.version_no }} 版</ElDescriptionsItem>
      <ElDescriptionsItem label="状态">{{ stateLabel(task.state) }}</ElDescriptionsItem>
      <ElDescriptionsItem label="执行次数">{{ task.attempt_count }} / {{ task.max_attempts }}</ElDescriptionsItem>
      <ElDescriptionsItem label="结果复核">{{ task.result ? (task.result.review_required ? '需要人工复核' : '以回答中的来源为准') : '尚无完整回答' }}</ElDescriptionsItem>
    </ElDescriptions>
    <ElAlert v-if="task.error_code" class="notice" type="warning" :closable="false"
      :title="task.error_summary || '任务未完成，请查看节点状态'"
      :description="task.error_code" />
    <ElAlert v-if="task.state === 'failed' && task.embedding_invocation?.state === 'succeeded'" class="notice"
      type="warning" :closable="false" title="检索向量调用已完成，智能体任务仍未完成"
      description="已发生的子调用费用仍会保留。请查看失败原因，恢复原任务前确认其当前授权和派发状态。" />
    <ElAlert v-if="partialCompletion" class="notice" type="warning" :closable="false"
      title="任务未完成，已有节点结果仍需按原任务核对"
      :description="`已完成 ${completedNodeCount} 个节点。已完成的检索或模型调用不表示已取得完整回答；费用仍按各原请求号保留。按原请求号读取费用。服务端允许续跑时，已封存的成功工具结果按原执行读取复用，原费用保留；仅服务器确认从未派发的失败工具可在当前工具执行权限允许时执行。新增模型和工具调用按实际用量计费。未知或已派发的失败工具不重发，调用结果不明时只刷新原任务。`" />
    <ElAlert v-if="task.state === 'recovery_pending'" class="notice" type="warning" :closable="false"
      title="当前结果仍待确认" description="请刷新原任务，不要重复提交同一问题。回执恢复由服务端按原调用处理，未知金额不按零计。" />
    <ElAlert v-if="task.continuation" class="notice" type="info" :closable="false"
      :title="task.continuation.available ? '原任务支持续跑剩余节点' : '原任务暂不能续跑'"
      :description="task.continuation.available ? '续跑保留原费用与已封存的成功工具结果，成功工具只按原执行读取复用；仅服务端确认从未派发的失败工具可在当前工具执行权限允许时执行。未知或已派发的失败工具不会重发；新增模型和工具调用按实际用量计费，当前身份、来源与工具权限仍会复核。' : continuationReasonLabel(task.continuation.reason)" />
    <h3>处理节点</h3>
    <ElTable :data="task.steps ?? []" row-key="code" empty-text="尚未读取到节点状态">
      <ElTableColumn label="节点" min-width="160">
        <template #default="{ row }">{{ nodeLabel(row.code) }}</template>
      </ElTableColumn>
      <ElTableColumn label="状态" min-width="120">
        <template #default="{ row }">{{ stateLabel(row.state) }}</template>
      </ElTableColumn>
      <ElTableColumn label="尝试次数" prop="attempt_count" width="100" />
      <ElTableColumn label="开始 / 完成" min-width="200">
        <template #default="{ row }">{{ row.started_at || '尚未开始' }} / {{ row.completed_at || '未完成' }}</template>
      </ElTableColumn>
      <ElTableColumn label="失败原因" min-width="200">
        <template #default="{ row }">{{ row.error_summary || row.error_code || '—' }}</template>
      </ElTableColumn>
    </ElTable>
    <p v-if="task.embedding_invocation" class="hint">
      查询向量子调用：{{ stateLabel(task.embedding_invocation.state) }}
      <span v-if="task.embedding_invocation.error_code"> · {{ task.embedding_invocation.error_code }}</span>
    </p>
    <h3>回答</h3>
    <ElAlert v-if="task.result?.review_required" class="notice" type="warning" :closable="false"
      title="本次回答需要人工复核" description="复核后再用于业务处理；此标记不会创建审批或执行业务动作。" />
    <ElInput type="textarea" :rows="6" readonly :model-value="task.result?.output?.content ?? ''"
      placeholder="任务完成后显示回答；失败或取消不表示已获得完整结果" />
    <h3>工具选择与执行</h3>
    <p class="hint">模型只能调用本次任务冻结的工具。下表按实际执行记录展示；工具配置不表示已执行。私有资料来源与只读工具来源分别读取，每次读取都会核对当前权限。</p>
    <ElTable :data="task.result?.tool_calls ?? []" row-key="tool" empty-text="尚未返回工具执行结果">
      <ElTableColumn label="工具" min-width="180">
        <template #default="{ row }">{{ nodeLabel(row.tool) }}</template>
      </ElTableColumn>
      <ElTableColumn label="引用的来源块" min-width="200">
        <template #default="{ row }">{{ row.source_block_ids?.join('、') || '未命中来源' }}</template>
      </ElTableColumn>
    </ElTable>
    <ElTable :data="Array.from(task.readonly_tool_runs ?? [])" row-key="run_id" empty-text="本任务尚无所选只读工具执行记录">
      <ElTableColumn label="只读工具" min-width="180"><template #default="{ row }">{{ row.code }} · 第 {{ row.version }} 版</template></ElTableColumn>
      <ElTableColumn label="实际执行" min-width="130"><template #default="{ row }">#{{ row.run_id }} · {{ stateLabel(row.state) }}</template></ElTableColumn>
      <ElTableColumn label="外部请求计量" min-width="170"><template #default="{ row }">{{ row.metering ? `${row.metering.quantity} 次外部请求；金额未定价` : '用量与金额尚未确认' }}</template></ElTableColumn>
      <ElTableColumn label="结果与工具来源" width="170"><template #default="{ row }"><ElButton link type="primary" :disabled="!apiKey?.trim()" @click="readTool(row.run_id)">按原执行读取</ElButton></template></ElTableColumn>
    </ElTable>
    <p v-if="task.readonly_tool_runs?.length" class="hint">外部请求与模型 Token 分别计量，未定价不表示免费。这里只读取原工具执行，不再次调用工具。</p>
    <ElDialog v-model="toolVisible" title="只读工具的原执行结果与来源" width="min(720px, 92vw)" @closed="clearTool">
      <p v-if="toolLoading" role="status">正在核对任务与工具读取权限…</p>
      <ElAlert v-if="toolError" type="error" :title="toolError" :closable="false" />
      <template v-if="toolResult">
        <p class="hint">执行 #{{ toolResult.run_id }} · {{ stateLabel(toolResult.state) }} · {{ toolResult.review_required ? '需要人工复核' : '请按工具来源核对' }}</p>
        <h3>原执行结果</h3><ElInput type="textarea" readonly :rows="8" :model-value="publicJson(toolResult.result)" />
        <h3>独立工具来源引用</h3><ElInput type="textarea" readonly :rows="6" :model-value="publicJson(toolResult.citations)" />
      </template>
    </ElDialog>
    <ElAlert v-if="unavailableTaskId === task.id" class="notice" type="warning" :closable="false"
      title="来源当前不可读取，已清除关联回答及私有预览"
      description="保留原任务和费用请求号。刷新任务不会还原旧内容；来源重读成功时只展示本次授权读取的原文。" />
    <ElSpace v-if="unavailableTaskId === task.id" wrap>
      <ElButton v-for="id in retainedSourceIds" :key="id" :disabled="!apiKey?.trim()" @click="readSource(id)">重新读取来源块 {{ id }}</ElButton>
    </ElSpace>
    <h3>来源引用</h3>
    <ElTable :data="task.result?.source_references ?? []" row-key="source_block_id" empty-text="没有来源引用">
      <ElTableColumn label="文件编号" prop="file_id" width="110" />
      <ElTableColumn label="来源块编号" prop="source_block_id" width="120" />
      <ElTableColumn label="原文位置" min-width="200">
        <template #default="{ row }">{{ row.locator_type }} · {{ JSON.stringify(row.locator) }}</template>
      </ElTableColumn>
      <ElTableColumn label="原文" width="120">
        <template #default="{ row }"><ElButton link type="primary" :disabled="!apiKey?.trim()" @click="readSource(row.source_block_id)">查看原文</ElButton></template>
      </ElTableColumn>
    </ElTable>
    <ElDialog v-model="sourceVisible" title="回答引用的私有原文" width="min(720px, 92vw)" @closed="clearSource">
      <p class="hint">本次只读取回答引用的来源，不重新检索或调用模型。</p>
      <p v-if="sourceLoading" role="status">正在核对权限并读取原文…</p>
      <ElAlert v-if="sourceError" type="error" :title="sourceError" :closable="false" />
      <template v-if="source">
        <p class="hint">文件 {{ source.file_id }} · 来源块 {{ source.source_block_id }} · {{ source.locator_type }} · {{ JSON.stringify(source.locator) }}</p>
        <ElInput type="textarea" readonly :rows="10" :model-value="source.content" />
      </template>
      <template #footer><ElButton @click="sourceVisible = false">返回原消息</ElButton></template>
    </ElDialog>
    <div class="cost-head">
      <h3>费用明细</h3>
      <ElButton v-if="modelCalls.length || costNeedsRead" :loading="costLoading" @click="readCurrentCosts">读取本任务费用</ElButton>
    </div>
    <p v-if="costNeedsRead" class="hint">来源状态已变化，旧费用暂不展示；请按原请求号独立读取当前费用，不会产生新的模型调用。</p>
    <ElAlert v-if="costError" class="notice" type="warning" :title="costError" :closable="false" />
    <template v-if="!costNeedsRead">
    <p class="hint">回答生成、历史摘要和查询向量分别展示各自的计量金额。未知金额不按零处理，金额未经服务商账单核对。</p>
    <ElTable v-if="modelCalls.length" :data="modelCalls" row-key="request_id" empty-text="没有已登记的模型调用">
      <ElTableColumn label="用途" min-width="140" prop="purpose" />
      <ElTableColumn label="模型调用请求号" prop="request_id" min-width="200" />
      <ElTableColumn label="调用状态" min-width="120"><template #default="{ row }">{{ stateLabel(row.state) }}</template></ElTableColumn>
      <ElTableColumn label="计量金额" min-width="150"><template #default="{ row }">{{ callAmount(row.request_id) }}</template></ElTableColumn>
      <ElTableColumn label="失败原因" min-width="180" prop="error_code" />
    </ElTable>
    <ElTable v-if="task.embedding_invocations?.length" :data="Array.from(task.embedding_invocations ?? [])" row-key="request_id">
      <ElTableColumn label="查询向量请求号" prop="request_id" min-width="200" />
      <ElTableColumn label="调用状态" min-width="120"><template #default="{ row }">{{ row.invocation ? stateLabel(row.invocation.state) : '未确认' }}</template></ElTableColumn>
      <ElTableColumn label="计量金额" min-width="150"><template #default="{ row }">{{ row.usage_cost ? amountLabel(row.usage_cost.metered_amount, row.usage_cost.currency) : '未知' }}</template></ElTableColumn>
    </ElTable>
    <p v-if="modelCalls.length" class="hint">逐笔费用按原请求号读取；回答轮次、历史摘要与末轮回答使用同一去重清单，同一请求号不另计一笔。各币种独立显示，不合并未知金额。</p>
    <ElDescriptions v-if="!modelCalls.length || !task.embedding_invocations?.length" :column="2" border>
      <ElDescriptionsItem v-if="!modelCalls.length" label="回答计量状态">{{ cost ? stateLabel(cost.usage.state) : '尚未读取' }}</ElDescriptionsItem>
      <ElDescriptionsItem v-if="!modelCalls.length" label="回答计量金额">{{ cost ? amountLabel(cost.price.metered_amount, cost.price.currency) : '未知' }}</ElDescriptionsItem>
      <ElDescriptionsItem v-if="!modelCalls.length" label="回答输入 Token">{{ cost?.tokens.input_tokens ?? '未知' }}</ElDescriptionsItem>
      <ElDescriptionsItem v-if="!modelCalls.length" label="回答输出 Token">{{ cost?.tokens.output_tokens ?? '未知' }}</ElDescriptionsItem>
      <ElDescriptionsItem v-if="!task.embedding_invocations?.length" label="查询向量计量状态">
        {{ task.embedding_usage_cost ? stateLabel(task.embedding_usage_cost.usage_state) : '尚无计量记录' }}
      </ElDescriptionsItem>
      <ElDescriptionsItem v-if="!task.embedding_invocations?.length" label="查询向量计量金额">
        {{ task.embedding_usage_cost ? amountLabel(task.embedding_usage_cost.metered_amount, task.embedding_usage_cost.currency) : '未知' }}
      </ElDescriptionsItem>
      <ElDescriptionsItem v-if="!task.embedding_invocations?.length" label="查询向量 Token">{{ task.embedding_usage_cost?.total_tokens ?? '未知' }}</ElDescriptionsItem>
      <ElDescriptionsItem v-if="!task.embedding_invocations?.length" label="查询向量价格版本">{{ task.embedding_usage_cost?.price_revision_id ?? '未确定' }}</ElDescriptionsItem>
    </ElDescriptions>
    <p v-if="!task.embedding_invocation" class="hint">本任务尚未登记查询向量子调用；词法检索可能无需查询向量，未登记不能用于推断整次任务免费。</p>
    </template>
  </section>
</template>

<script setup lang="ts">
  import { computed, onUnmounted, ref, watch } from 'vue'
  import agentApi, { type AgentSourceContent } from '../../api/agent'
  import taskApi from '../../api/task'
  import toolTrial, { type ToolRunSummary } from '../../api/toolTrial'
  import { SandAiRuntimeError } from '../../api/runtime'
  import { isAgentReadDenied } from '../../api/agentEventReplay'
  import { continuationReasonLabel } from './useAgentTaskRun'
  import type { SandAiInvocationCost, SandAiTask } from '../../api/types'

  const props = defineProps<{ apiKey?: string; task: SandAiTask; cost: SandAiInvocationCost | null; costRecords: Record<string, SandAiInvocationCost>; costLoading: boolean; costError: string; beforeRead?: () => Promise<void> }>()
  const emit = defineEmits<{ readCost: []; denied: []; contentInvalidated: [task: SandAiTask] }>()
  const unavailableTaskId = ref<number | null>(null)
  const retainedSourceIds = ref<number[]>([])

  const completedNodeCount = computed(() => props.task.steps?.filter(step => step.state === 'succeeded').length ?? 0)
  const partialCompletion = computed(() => ['failed', 'canceled', 'recovery_pending'].includes(props.task.state) && completedNodeCount.value > 0)
  const modelCalls = computed(() => {
    const rows = new Map<string, { request_id: string; state: string; error_code: string | null; purpose: string }>()
    for (const call of props.task.agent_invocations ?? []) rows.set(call.request_id, { ...call, purpose: '回答生成' })
    const summary = props.task.summary_invocation
    if (summary) rows.set(summary.request_id, { ...summary, purpose: '历史对话摘要' })
    const final = props.task.result?.invocation
    if (final?.request_id && !rows.has(final.request_id)) rows.set(final.request_id, { request_id: final.request_id, state: final.state ?? 'unknown', error_code: final.error_code ?? null, purpose: '回答生成' })
    return [...rows.values()]
  })
  const sourceVisible = ref(false)
  const sourceLoading = ref(false)
  const sourceError = ref('')
  const source = ref<AgentSourceContent | null>(null)
  const costNeedsRead = ref(false)
  let costReadRequested = false
  let sourceEpoch = 0
  const toolVisible = ref(false)
  const toolLoading = ref(false)
  const toolError = ref('')
  const toolResult = ref<ToolRunSummary | null>(null)
  let toolEpoch = 0
  function clearTool(): void {
    ++toolEpoch
    toolLoading.value = false
    toolError.value = ''
    toolResult.value = null
  }
  function denyRead(failure: unknown): boolean {
    if (!(failure instanceof SandAiRuntimeError) || !isAgentReadDenied(failure.errorCode, failure.httpStatus)) return false
    clearDeniedRead()
    return true
  }
  function clearDeniedRead(): void {
    sourceVisible.value = false
    toolVisible.value = false
    clearSource()
    clearTool()
    emit('denied')
  }
  function publicJson(value: unknown): string {
    return value === null || value === undefined ? '尚无完整结果' : JSON.stringify(value, null, 2)
  }
  async function readTool(runId: number): Promise<void> {
    const credential = props.apiKey?.trim()
    if (!credential || !props.task.readonly_tool_runs?.some(run => run.run_id === runId)) return
    clearTool()
    toolVisible.value = true
    toolLoading.value = true
    const current = toolEpoch
    try {
      await props.beforeRead?.()
      if (current !== toolEpoch || !toolVisible.value || credential !== props.apiKey?.trim()) return
      const result = await toolTrial.read(credential, runId)
      if (current === toolEpoch && toolVisible.value && result.run_id === runId) toolResult.value = result
    } catch (failure) {
      if (current === toolEpoch && !denyRead(failure)) toolError.value = failure instanceof Error ? failure.message : '工具结果读取失败，请刷新原任务'
    } finally { if (current === toolEpoch) toolLoading.value = false }
  }

  function clearSource(): void {
    ++sourceEpoch
    sourceLoading.value = false
    sourceError.value = ''
    source.value = null
  }
  async function readSource(blockId: number): Promise<void> {
    const apiKey = props.apiKey?.trim()
    if (!apiKey) return
    clearSource()
    sourceVisible.value = true
    sourceLoading.value = true
    const current = sourceEpoch
    const taskId = props.task.id
    let sourceRequested = false
    try {
      await props.beforeRead?.()
      if (current !== sourceEpoch || !sourceVisible.value || apiKey !== props.apiKey?.trim()) return
      sourceRequested = true
      const result = await agentApi.source(apiKey, taskId, blockId)
      if (current === sourceEpoch && sourceVisible.value && taskId === props.task.id && apiKey === props.apiKey?.trim()
        && result.task_id === taskId && result.source_block_id === blockId) source.value = result
    } catch (failure) {
      if (current !== sourceEpoch || apiKey !== props.apiKey?.trim() || taskId !== props.task.id) return
      if (!sourceRequested) { clearDeniedRead(); return }
      if (failure instanceof SandAiRuntimeError && (failure.httpStatus === 404
        || ['SAND_AI_RESOURCE_NOT_FOUND', 'SAND_AI_KNOWLEDGE_SOURCE_INVALID', 'SAND_AI_KNOWLEDGE_SOURCE_DRIFTED', 'SAND_AI_ARTIFACT_PROVENANCE_INVALID'].includes(failure.errorCode ?? ''))) {
        invalidateContent()
        return
      }
      if (failure instanceof SandAiRuntimeError && (failure.httpStatus === 401 || failure.httpStatus === 403)) {
        clearDeniedRead()
        return
      }
      source.value = null
      costNeedsRead.value = true
      costReadRequested = false
      try {
        if (props.beforeRead) await props.beforeRead()
        else {
          const original = await taskApi.read(apiKey, props.task.id)
          if (original.id !== props.task.id) throw new Error('原任务读取不一致')
        }
        if (current !== sourceEpoch || apiKey !== props.apiKey?.trim()) return
        sourceError.value = '该来源当前不可读取，可能已撤销、清理或不在当前读取权限内。原消息仍可读取，可返回查看；费用须独立按原请求号读取，不需要重新发送消息。'
      } catch {
        if (current === sourceEpoch && apiKey === props.apiKey?.trim()) clearDeniedRead()
      }
    } finally { if (current === sourceEpoch) sourceLoading.value = false }
  }
  function invalidateContent(): void {
    const original = props.task
    const calls = [...(original.agent_invocations ?? [])]
    const final = original.result?.invocation
    if (final?.request_id && !calls.some(call => call.request_id === final.request_id)) calls.push({
      request_id: final.request_id, state: final.state ?? 'unknown', latency_ms: final.latency_ms ?? null, error_code: final.error_code ?? null
    })
    retainedSourceIds.value = [...new Set([...retainedSourceIds.value, ...(original.result?.source_references ?? []).map(item => item.source_block_id)])]
    unavailableTaskId.value = original.id
    clearSource()
    clearTool()
    toolVisible.value = false
    costNeedsRead.value = true
    costReadRequested = false
    emit('contentInvalidated', { ...original, result: null, error_summary: null,
      steps: original.steps?.map(step => ({ ...step, error_summary: null })), agent_invocations: calls,
      embedding_usage_cost: null, embedding_invocations: original.embedding_invocations?.map(call => ({ ...call, usage_cost: null })) })
    sourceVisible.value = true
    sourceError.value = '该来源当前不可读取，关联回答、引用和工具预览已清除。可返回原消息，独立读取原费用，或明确重新读取来源。'
  }
  watch(() => [props.task.id, props.apiKey], () => {
    unavailableTaskId.value = null
    retainedSourceIds.value = []
    costNeedsRead.value = false
  }, { flush: 'sync' })
  function readCurrentCosts(): void {
    if (props.costLoading) return
    costReadRequested = true
    emit('readCost')
  }
  watch(() => props.costLoading, (loading, wasLoading) => {
    if (!loading && wasLoading && costReadRequested && !props.costError) {
      costNeedsRead.value = false
      costReadRequested = false
    }
  }, { flush: 'sync' })
  watch(() => [props.task.id, props.task.state, props.apiKey], () => {
    costReadRequested = false
    sourceVisible.value = false
    clearSource()
    toolVisible.value = false
    clearTool()
  }, { flush: 'sync' })
  onUnmounted(() => { clearSource(); clearTool() })

  function callAmount(requestId: string): string {
    const record = props.costRecords[requestId]
    return record ? amountLabel(record.price.metered_amount, record.price.currency) : '未知'
  }
  function stateLabel(state: string): string {
    const labels: Record<string, string> = {
      queued: '等待处理', pending: '等待处理', authorization_pending: '等待授权', running: '处理中',
      retrying: '等待重试', succeeded: '已完成', failed: '失败', canceled: '已取消',
      unknown: '未确认', prepared: '等待派发', dispatching: '正在调用', ambiguous: '调用结果不明',
      not_dispatched: '未派发', measured: '已计量', unmeasured: '用量未知',
      missing_event: '尚无计量记录', recovery_pending: '等待恢复'
    }
    return labels[state] ?? state
  }
  function nodeLabel(code: string): string {
    const labels: Record<string, string> = {
      agent_run: '智能体处理', 'retrieval.search': '私有资料检索', ai_completion: '模型生成'
    }
    const round = /^agent_round_([1-4])$/.exec(code)
    if (round) return `第 ${round[1]} 轮模型调用`
    const retrieval = /^agent_retrieval_([1-2])$/.exec(code)
    if (retrieval) return `第 ${retrieval[1]} 次私有资料检索`
    if (/^agent_tool_continue_[a-f0-9]{32}$/.test(code)) return '只读工具续跑'
    const managed = /^agent_managed_tool_([1-2])$/.exec(code)
    if (managed) return `第 ${managed[1]} 项所选只读工具`
    if (code === 'agent_summary_1') return '历史对话摘要'
    return labels[code] ?? code
  }
  function amountLabel(amount: string | null, currency: string | null): string {
    return amount === null || currency === null ? '未知' : `${amount} ${currency}`
  }
</script>

<style scoped lang="scss">
  h3 { margin: 18px 0 10px; font-size: 15px; }
  .notice { margin: 12px 0; }
  .hint { color: var(--el-text-color-secondary); font-size: 13px; line-height: 1.7; }
  .cost-head { display: flex; align-items: center; flex-wrap: wrap; gap: 12px; }
</style>
