import { computed, onUnmounted, ref, watch, type Ref } from 'vue'
import { ElMessageBox } from 'element-plus'
import agentApi from '../../api/agent'
import taskApi from '../../api/task'
import { SandAiRuntimeError, newRuntimeRequestId } from '../../api/runtime'
import { isAgentReadDenied } from '../../api/agentEventReplay'
import type { SandAiInvocationCost, SandAiTask } from '../../api/types'

interface AgentTaskAccess {
  readTask: (credential: string, taskId: number) => Promise<SandAiTask>
  beforeContinue: (credential: string, task: SandAiTask) => Promise<void>
}

export function useAgentTaskRun(apiKey: Ref<string>, onReadDenied?: () => void, continuationScope?: Ref<string>, access?: AgentTaskAccess) {
  const task = ref<SandAiTask | null>(null)
  const taskReference = ref<number | null>(null)
  const cost = ref<SandAiInvocationCost | null>(null)
  const costRecords = ref<Record<string, SandAiInvocationCost>>({})
  const costBusy = ref(false)
  const costError = ref('')
  const taskBusy = ref(false)
  const error = ref('')
  const polling = ref(false)
  const pollingNotice = ref('')
  // Only opaque task/request references survive reload; no credentials or private content.
  const continuationRequestKey = ref('')
  const continuationAttempt = ref<number | null>(null)
  const continuationPendingTask = ref<number | null>(null)
  const continuationSnapshot = ref<string | null>(null)
  const continuationStorageError = ref(false)
  const continuationPending = computed(() => !!continuationRequestKey.value || continuationStorageError.value)
  const canContinue = computed(() => task.value?.state === 'failed' && task.value.continuation?.available === true
    && !unresolvedInvocation.value && !continuationPending.value)
  const continuationNotice = computed(() => continuationPending.value
    ? '续跑提交结果仍待确认。请刷新原任务；不会重新发送续跑请求或生成新的请求键。'
    : task.value?.continuation?.available ? '续跑保留原费用。已封存的成功工具结果按原执行读取复用，不重发；只有服务端确认从未派发的失败工具，才在当前工具执行权限允许时执行。未知或已派发的失败工具不会重发；新增模型和工具调用按实际用量计费，身份、来源及工具权限仍由服务端复核。'
      : continuationReasonLabel(task.value?.continuation?.reason))
  function saveContinuation(): boolean {
    try {
      if (!continuationScope?.value) throw new Error('缺少续跑恢复范围')
      if (continuationRequestKey.value) sessionStorage.setItem(continuationScope.value, JSON.stringify({
        key: continuationRequestKey.value, task: continuationPendingTask.value, attempt: continuationAttempt.value, snapshot: continuationSnapshot.value
      }))
      else sessionStorage.removeItem(continuationScope.value)
      return true
    } catch {
      error.value = '无法保存续跑请求号，本次未发送。请保持本页并核对原任务。'
      return false
    }
  }
  function restoreContinuation(): void {
    continuationRequestKey.value = ''
    continuationAttempt.value = null
    continuationPendingTask.value = null
    continuationSnapshot.value = null
    continuationStorageError.value = false
    if (!continuationScope?.value) return
    try {
      const raw: unknown = JSON.parse(sessionStorage.getItem(continuationScope.value) || 'null')
      if (raw === null) return
      if (typeof raw !== 'object' || Array.isArray(raw)) throw new Error('invalid reference')
      const saved = raw as Record<string, unknown>
      if (typeof saved.key !== 'string' || !/^[\x21-\x7e]{1,512}$/.test(saved.key)
        || typeof saved.task !== 'number' || !Number.isSafeInteger(saved.task) || saved.task <= 0
        || typeof saved.attempt !== 'number' || !Number.isSafeInteger(saved.attempt) || saved.attempt < 0) throw new Error('invalid reference')
      continuationRequestKey.value = saved.key
      continuationAttempt.value = saved.attempt
      if (saved.snapshot !== null && (typeof saved.snapshot !== 'string' || !/^[a-f0-9]{64}$/i.test(saved.snapshot))) throw new Error('invalid snapshot')
      continuationSnapshot.value = typeof saved.snapshot === 'string' ? saved.snapshot : null
      continuationPendingTask.value = saved.task
      taskReference.value = saved.task
    } catch {
      continuationStorageError.value = true
      error.value = '续跑恢复记录无法读取，请核对原任务，暂勿重新提交。'
    }
  }
  const canCancel = computed(() => !!task.value && ['queued', 'running', 'retrying', 'authorization_pending'].includes(task.value.state))
  const unresolvedInvocation = computed(() => {
    const current = task.value
    if (!current) return false
    const invocations = [
      ...(current.agent_invocations ?? []),
      ...(current.summary_invocation ? [current.summary_invocation] : []),
      ...(current.embedding_invocations ?? []).flatMap(item => item.invocation ? [item.invocation] : []),
      ...(current.embedding_invocation ? [current.embedding_invocation] : [])
    ]
    const unresolvedCodes = ['SAND_AI_DISPATCH_AMBIGUOUS', 'SAND_AI_TOOL_DISPATCH_AMBIGUOUS', 'SAND_AI_TOOL_FINALIZATION_PENDING']
    return unresolvedCodes.includes(current.error_code ?? '')
      || (current.readonly_tool_runs ?? []).some(run => ['prepared', 'queued', 'running', 'dispatching', 'ambiguous', 'unknown', 'unconfirmed', 'recovery_pending'].includes(run.state))
      || invocations.some(item =>
      ['prepared', 'running', 'dispatching', 'ambiguous', 'unknown', 'unconfirmed'].includes(item.state)
      || unresolvedCodes.includes(item.error_code ?? ''))
  })
  const canRetry = computed(() => !!task.value && isAgentTask(task.value) && task.value.state === 'failed'
    && !task.value.continuation && task.value.authorization_continuation?.required !== true
    && Number.isSafeInteger(task.value.attempt_count) && task.value.attempt_count >= 0
    && Number.isSafeInteger(task.value.max_attempts) && task.value.attempt_count < task.value.max_attempts
    && !unresolvedInvocation.value && !continuationPending.value && !(task.value.readonly_tool_runs?.length))
  const canStartNew = computed(() => !continuationPending.value && (taskReference.value === null || (!!task.value
    && task.value.id === taskReference.value
    && ['succeeded', 'failed', 'canceled'].includes(task.value.state) && !unresolvedInvocation.value)))
  const retryBlockedReason = computed(() => {
    if (!task.value || unresolvedInvocation.value || task.value.state === 'recovery_pending')
      return '调用结果仍待确认，请刷新原任务；暂勿重新提交，费用尚未确认。'
    if (task.value.authorization_continuation?.required === true)
      return '原任务需要当前授权，普通重试不会续权。请在原任务确认正式恢复入口；不重新提交问题。'
    if (task.value.readonly_tool_runs?.length)
      return '原工具调用上下文不能重新绑定。请保留原任务，刷新节点、原工具结果和费用；仅使用原任务提供的正式续跑入口。'
    if (task.value.attempt_count >= task.value.max_attempts) return '原任务已达到尝试次数上限。请保留原任务，核对原结果、来源和费用；普通重试不能增加次数。'
    return '当前状态不支持重试，请读取原任务确认状态。'
  })
  function startNewTask(): boolean {
    if (!canStartNew.value) return false
    reset()
    taskReference.value = null
    return true
  }
  let generation = 0
  let timer: ReturnType<typeof setTimeout> | null = null
  let polls = 0
  let costGeneration = 0

  function stopPolling(): void {
    if (timer !== null) clearTimeout(timer)
    timer = null
    polling.value = false
  }
  function reset(): void {
    ++generation
    ++costGeneration
    stopPolling()
    task.value = null
    cost.value = null
    costRecords.value = {}
    costBusy.value = false
    costError.value = ''
    taskBusy.value = false
    error.value = ''
    pollingNotice.value = ''
    polls = 0
  }
  function schedule(): void {
    stopPolling()
    if (!task.value || ['succeeded', 'failed', 'canceled'].includes(task.value.state)) return
    if (task.value.state === 'recovery_pending') {
      pollingNotice.value = '任务结果仍待恢复，已停止自动刷新。请手动读取原任务；不会重新发送模型请求，未知费用仍待确认。'
      return
    }
    if (polls >= 60) {
      pollingNotice.value = '已暂停自动刷新。任务仍在服务端处理，可手动刷新原任务。'
      return
    }
    const current = generation
    polling.value = true
    timer = setTimeout(() => {
      if (current !== generation) return
      polls += 1
      void refresh()
    }, 2000)
  }
  function acceptTask(result: SandAiTask, restartPolling = true): void {
    if (continuationPendingTask.value === result.id && continuationAttempt.value !== null
      && (continuationSnapshot.value === null || result.agent_definition_version?.snapshot_sha256 === continuationSnapshot.value)
      && (result.attempt_count > continuationAttempt.value || ['succeeded', 'canceled'].includes(result.state))) {
      continuationRequestKey.value = ''
      continuationAttempt.value = null
      continuationPendingTask.value = null
      saveContinuation()
    }
    const taskChanged = task.value?.id !== result.id
    const invocationChanged = taskChanged
      || task.value?.result?.invocation?.request_id !== result.result?.invocation?.request_id
    if (invocationChanged) {
      ++costGeneration
      cost.value = null
      costError.value = ''
      costBusy.value = false
    }
    if (taskChanged) costRecords.value = {}
    taskReference.value = result.id
    task.value = result
    if (restartPolling) {
      polls = 0
      pollingNotice.value = ''
    }
    schedule()
  }
  async function readTask(taskId: number): Promise<boolean> {
    if (!apiKey.value.trim() || taskBusy.value) return false
    if (continuationPendingTask.value !== null && continuationPendingTask.value !== taskId) {
      error.value = '续跑结果仍未确认，请先读取同一原任务。'
      return false
    }
    if (taskReference.value !== null && taskReference.value !== taskId && !canStartNew.value) {
      error.value = '请先确认原任务终态，不能用另一任务替换未决任务。'
      return false
    }
    // Changing the target invalidates the previous task body and every in-flight cost/read response.
    // A failed read of B must never leave A's terminal state as proof that B is resolved.
    if (task.value?.id !== taskId) reset()
    taskReference.value = taskId
    const current = generation
    stopPolling()
    taskBusy.value = true
    error.value = ''
    try {
      const result = await (access ? access.readTask(apiKey.value, taskId) : taskApi.read(apiKey.value, taskId))
      if (current !== generation) return false
      acceptTask(result, false)
      return true
    } catch (failure) {
      if (current === generation) {
        error.value = failure instanceof Error ? failure.message : '任务读取失败，请刷新原任务'
        if (failure instanceof SandAiRuntimeError && isAgentReadDenied(failure.errorCode, failure.httpStatus)) {
          ++costGeneration
          task.value = null
          cost.value = null
          costRecords.value = {}
          costBusy.value = false
          costError.value = ''
          pollingNotice.value = '当前身份或来源已无法读取原任务，已清除旧回答。请确认当前授权后重新读取原任务。'
          onReadDenied?.()
        }
      }
      return false
    } finally {
      if (current === generation) taskBusy.value = false
    }
  }
  async function refresh(): Promise<void> {
    const original = continuationPendingTask.value ?? taskReference.value
    if (original !== null) await readTask(original)
  }
  function requestIds(current: SandAiTask | null): string[] {
    return Array.from(new Set([
      ...(current?.agent_invocations ?? []).map(item => item.request_id),
      ...(current?.summary_invocation?.request_id ? [current.summary_invocation.request_id] : []),
      ...(current?.result?.invocation?.request_id ? [current.result.invocation.request_id] : [])
    ]))
  }
  async function loadCost(): Promise<void> {
    const ids = requestIds(task.value)
    const taskId = task.value?.id
    const credential = apiKey.value
    if (!ids.length || !credential.trim() || costBusy.value) return
    const current = generation
    const request = ++costGeneration
    const fingerprint = ids.join('\n')
    costBusy.value = true
    costError.value = ''
    let failed = 0
    const stillCurrent = () => current === generation && request === costGeneration
      && task.value?.id === taskId && apiKey.value === credential && requestIds(task.value).join('\n') === fingerprint
    try {
      for (const requestId of ids) {
        if (!stillCurrent()) return
        try {
          const result = await agentApi.cost(credential, requestId)
          if (!stillCurrent()) return
          if (result.request_id !== requestId) throw new Error('费用与当前调用不一致')
          costRecords.value = { ...costRecords.value, [requestId]: result }
          if (task.value?.result?.invocation?.request_id === requestId) cost.value = result
        } catch (failure) {
          if (!stillCurrent()) return
          if (failure instanceof SandAiRuntimeError && isAgentReadDenied(failure.errorCode, failure.httpStatus)) {
            reset()
            onReadDenied?.()
            error.value = failure.message || '当前身份或来源已无法读取原任务，已清除旧回答和费用。'
            return
          }
          failed += 1
          const records = { ...costRecords.value }
          delete records[requestId]
          costRecords.value = records
          if (task.value?.result?.invocation?.request_id === requestId) cost.value = null
        }
      }
      if (stillCurrent() && failed) costError.value = `${failed} 笔调用费用未能读取，请确认当前费用权限后重读。未知金额不按零处理。`
    } finally {
      if (current === generation && request === costGeneration) costBusy.value = false
    }
  }
  function isAgentTask(current: SandAiTask): boolean {
    return Number.isSafeInteger(current.id) && current.id > 0 && current.task_type === 'agent_run'
      && current.resource_type === 'agent_run' && current.resource_id === current.id
  }
  function assertOriginalTask(current: SandAiTask, original: SandAiTask): void {
    if (!isAgentTask(current) || current.id !== original.id || current.task_type !== original.task_type
      || current.resource_type !== original.resource_type || current.resource_id !== original.resource_id
      || current.agent_definition_version?.code !== original.agent_definition_version?.code
      || current.agent_definition_version?.version_no !== original.agent_definition_version?.version_no
      || current.agent_definition_version?.snapshot_sha256 !== original.agent_definition_version?.snapshot_sha256) {
      throw new Error('读取结果与原智能体运行不一致，请只读刷新原任务；本次未继续执行。')
    }
  }
  function taskActionSnapshot(current: SandAiTask): string {
    return JSON.stringify([
      current.id, current.task_type, current.resource_type, current.resource_id,
      current.agent_definition_version ?? null, current.state, current.attempt_count, current.max_attempts,
      current.started_at, current.completed_at, current.error_code,
      current.authorization_continuation ?? null, current.continuation ?? null,
      (current.steps ?? []).map(step => [step.code, step.state, step.attempt_count, step.started_at, step.completed_at, step.error_code]),
      (current.agent_invocations ?? []).map(call => [call.request_id, call.state, call.error_code]),
      (current.readonly_tool_runs ?? []).map(run => [run.run_id, run.code, run.version, run.state])
    ])
  }
  async function retryTask(): Promise<void> {
    const original = task.value
    if (!original || !isAgentTask(original) || !apiKey.value.trim() || taskBusy.value
      || !canRetry.value) return
    const taskId = original.id
    const snapshot = taskActionSnapshot(original)
    const current = generation
    const credential = apiKey.value
    const stillCurrent = (): boolean => current === generation && credential === apiKey.value
      && taskReference.value === taskId && task.value?.id === taskId
    stopPolling()
    taskBusy.value = true
    error.value = ''
    try {
      try {
        await ElMessageBox.confirm(
          `确认重试原智能体任务 #${taskId}（执行次数 ${original.attempt_count}）？原任务和费用保留；再次调用可能产生费用。确认后会重读原任务，调用结果不明时不能重发。`,
          '重试失败任务', { type: 'warning' })
      } catch (failure) {
        if (failure === 'cancel' || failure === 'close') return
        throw failure
      }
      if (!stillCurrent()) return
      const latest = await (access ? access.readTask(credential, taskId) : taskApi.read(credential, taskId))
      if (!stillCurrent()) return
      assertOriginalTask(latest, original)
      acceptTask(latest, false)
      if (taskActionSnapshot(latest) !== snapshot || !canRetry.value) {
        pollingNotice.value = '原任务状态、节点或执行代次已变化，本次操作未发送；请按当前原任务重新确认。'
        return
      }
      const expectation = { expected_attempt_count: original.attempt_count, expected_state: original.state }
      const result = await taskApi.retry(credential, taskId, undefined, expectation)
      if (!stillCurrent()) return
      assertOriginalTask(result, original)
      acceptTask(result)
    } catch (failure) {
      if (!stillCurrent()) return
      if (failure instanceof SandAiRuntimeError && isAgentReadDenied(failure.errorCode, failure.httpStatus)) {
        reset()
        onReadDenied?.()
      }
      error.value = failure instanceof Error ? failure.message : '操作结果未确认，请刷新原任务'
      pollingNotice.value = '请只读刷新原任务确认实际状态；原任务号和已发生费用保留，不自动重复提交。'
    } finally {
      if (current === generation) taskBusy.value = false
    }
  }
  async function changeTask(action: 'cancel' | 'retry'): Promise<void> {
    if (action === 'retry') { await retryTask(); return }
    if (!task.value || !apiKey.value.trim() || taskBusy.value || !canCancel.value) return
    const taskId = task.value.id
    const current = generation
    const credential = apiKey.value
    try {
      await ElMessageBox.confirm('取消原任务后，已发生的调用和费用仍保留。',
        '取消智能体任务', { type: 'warning' })
    } catch { return }
    if (current !== generation || task.value?.id !== taskId || credential !== apiKey.value || taskBusy.value) return
    stopPolling()
    taskBusy.value = true
    error.value = ''
    try {
      const result = await taskApi.cancel(credential, taskId)
      if (current !== generation) return
      cost.value = null
      acceptTask(result)
    } catch (failure) {
      if (current === generation) {
        error.value = failure instanceof Error ? failure.message : '操作结果未确认，请刷新原任务'
      }
    } finally {
      if (current === generation) taskBusy.value = false
    }
  }
  async function continueTask(): Promise<void> {
    if (!task.value || !apiKey.value.trim() || taskBusy.value || !canContinue.value) return
    const original = task.value
    if (!isAgentTask(original)) return
    const taskId = original.id
    const attempt = original.attempt_count
    const snapshot = taskActionSnapshot(original)
    const current = generation
    const credential = apiKey.value
    try {
      await ElMessageBox.confirm('保留已有费用，按原任务续跑剩余节点。已封存的成功工具结果只读取复用；服务端确认从未派发的失败工具，须通过当前工具执行权限校验后才执行。未知或已派发的失败工具不重发。新增模型和工具调用按实际用量计费，当前身份、来源及工具读取权限仍会重新校验。',
        '续跑剩余节点', { type: 'warning', confirmButtonText: '续跑剩余节点', cancelButtonText: '暂不续跑' })
    } catch { return }
    if (current !== generation || credential !== apiKey.value || task.value?.id !== taskId || !canContinue.value || taskBusy.value) return
    taskBusy.value = true
    stopPolling()
    try {
      const latest = await (access ? access.readTask(credential, taskId) : taskApi.read(credential, taskId))
      if (current !== generation || credential !== apiKey.value || taskReference.value !== taskId || task.value?.id !== taskId) return
      assertOriginalTask(latest, original)
      acceptTask(latest, false)
      if (taskActionSnapshot(latest) !== snapshot || !canContinue.value) {
        pollingNotice.value = '原任务、节点或失败代次已变化，本次续跑未发送；请按当前原任务重新确认。'
        return
      }
      if (access) await access.beforeContinue(credential, latest)
    } catch (failure) {
      if (current !== generation || credential !== apiKey.value) return
      if (failure instanceof SandAiRuntimeError && isAgentReadDenied(failure.errorCode, failure.httpStatus)) {
        reset()
        onReadDenied?.()
      }
      error.value = failure instanceof Error ? failure.message : '原消息归属尚未确认，本次未发送续跑。'
      return
    } finally {
      if (current === generation) taskBusy.value = false
    }
    if (current !== generation || credential !== apiKey.value || task.value?.id !== taskId
      || !canContinue.value || taskActionSnapshot(task.value) !== snapshot) return
    continuationRequestKey.value = newRuntimeRequestId('agent-continue')
    continuationPendingTask.value = taskId
    continuationAttempt.value = attempt
    continuationSnapshot.value = task.value.agent_definition_version?.snapshot_sha256 ?? null
    if (!saveContinuation()) {
      continuationRequestKey.value = ''
      continuationPendingTask.value = null
      continuationAttempt.value = null
      return
    }
    stopPolling()
    ++costGeneration
    cost.value = null
    costRecords.value = {}
    costBusy.value = false
    costError.value = ''
    taskBusy.value = true
    error.value = ''
    try {
      const result = await taskApi.continueAgent(credential, taskId, continuationRequestKey.value)
      if (current !== generation || credential !== apiKey.value) return
      assertOriginalTask(result, original)
      continuationRequestKey.value = ''
      continuationPendingTask.value = null
      continuationAttempt.value = null
      saveContinuation()
      acceptTask(result)
    } catch (failure) {
      if (current !== generation || credential !== apiKey.value) return
      if (failure instanceof SandAiRuntimeError && isAgentReadDenied(failure.errorCode, failure.httpStatus)) {
        reset()
        onReadDenied?.()
      }
      error.value = failure instanceof Error ? failure.message : '续跑结果未确认'
      pollingNotice.value = '请刷新原任务确认续跑结果；本页保留原续跑请求号，不重复发送。'
    } finally {
      if (current === generation) taskBusy.value = false
    }
  }
  if (continuationScope) watch(continuationScope, () => { reset(); taskReference.value = null; restoreContinuation() }, { flush: 'sync', immediate: true })
  watch(apiKey, reset, { flush: 'sync' })
  onUnmounted(reset)
  return { canContinue, continuationPending, continuationNotice, continueTask, task, taskReference, unresolvedInvocation, canStartNew, retryBlockedReason, startNewTask, cost, costRecords, costBusy, costError, taskBusy, error, polling, pollingNotice, canCancel, canRetry,
    reset, stopPolling, acceptTask, readTask, refresh, loadCost, changeTask }
}

export function continuationReasonLabel(reason: import('../../api/types').SandAiContinuationReason | null | undefined): string {
  if (!reason) return ''
  const labels: Record<import('../../api/types').SandAiContinuationReason, string> = {
    checkpoint_missing: '原任务没有可用检查点，无法续跑剩余节点。',
    task_not_failed: '当前任务不是失败状态，请刷新原任务确认结果。',
    lease_active: '原任务仍在处理，请刷新原任务，暂不能续跑。',
    dispatch_unknown: '已有调用结果仍待确认，只能刷新原任务，不能续跑。',
    attempts_exhausted: '原任务已达到尝试次数上限，无法继续执行。',
    source_unavailable: '原来源已不可用，请核对原资料和当前读取权限。',
    configuration_changed: '原模型或工具配置已变化，无法沿用原检查点。',
    authorization_required: '当前身份缺少续跑所需权限，请确认任务、智能体、来源和工具授权后刷新原任务。'
  }
  return labels[reason]
}
