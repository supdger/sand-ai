import { computed, onUnmounted, ref, watch, type Ref } from 'vue'
import { AgentReplayError, isAgentReadDenied, readAgentEvents } from '../../api/agentEventReplay'
import type { SandAiTask } from '../../api/types'

export type AgentReplayTransportState = 'idle' | 'connecting' | 'reading' | 'disconnected' | 'reconnecting' | 'paused' | 'ended' | 'unavailable' | 'denied'

export function useAgentEventReplay(apiKey: Ref<string>, task: Ref<SandAiTask | null>, code: Ref<string | null>,
  refresh: () => Promise<void>, clearDenied: () => void) {
  const preview = ref('')
  const cursor = ref(0)
  const connected = ref(false)
  const transport = ref<AgentReplayTransportState>('idle')
  const transportLabel = computed(() => ({ idle: '尚未连接', connecting: '正在连接原运行', reading: '正在读取原运行',
    disconnected: '连接已断开', reconnecting: '正在沿原游标重连', paused: '已暂停实时读取',
    ended: '实时连接已结束', unavailable: '实时读取不可用', denied: '当前身份不可读取' })[transport.value])
  const notice = ref('')
  const stopped = ref(false)
  let epoch = 0
  let controller: AbortController | null = null
  let reconnect: ReturnType<typeof setTimeout> | null = null
  let attempts = 0
  const terminal = computed(() => !!task.value && ['succeeded', 'failed', 'canceled', 'recovery_pending'].includes(task.value.state))

  function stop(): void {
    ++epoch
    controller?.abort()
    controller = null
    if (reconnect !== null) clearTimeout(reconnect)
    reconnect = null
    connected.value = false
  }
  function reset(): void {
    stop()
    preview.value = ''
    cursor.value = 0
    notice.value = ''
    transport.value = 'idle'
    stopped.value = false
    attempts = 0
  }
  async function connect(): Promise<void> {
    if (!task.value || !apiKey.value.trim() || terminal.value || connected.value) return
    if (preview.value.length >= 65536) {
      transport.value = 'unavailable'
      notice.value = '实时预览已达显示上限。请刷新原任务读取完整结果。'
      return
    }
    stop()
    stopped.value = false
    const current = epoch
    const taskId = task.value.id
    const credential = apiKey.value, agentCode = code.value
    controller = new AbortController()
    connected.value = true
    transport.value = cursor.value || attempts > 0 ? 'reconnecting' : 'connecting'
    notice.value = cursor.value ? '正在从原游标恢复已保存的文本，不会重新调用模型。' : ''
    let retryable = true
    let sawTerminal = false
    try {
      await readAgentEvents(credential, taskId, agentCode, cursor.value, controller.signal, event => {
        if (current !== epoch || task.value?.id !== taskId || credential !== apiKey.value || agentCode !== code.value) return
        transport.value = 'reading'
        if (event.kind === 'token') {
          if (event.token.task_id !== taskId || event.token.event_no !== cursor.value + 1) {
            throw new AgentReplayError('SAND_AI_AGENT_PROTOCOL_INVALID')
          }
          cursor.value = event.token.event_no
          if (!terminal.value && !sawTerminal) preview.value += event.token.text
          if (preview.value.length >= 65536) {
            retryable = false
            transport.value = 'unavailable'
            notice.value = '实时预览已达显示上限。请刷新原任务读取完整结果。'
            controller?.abort()
          }
        } else if (event.kind === 'task') {
          if (event.taskId !== taskId) throw new AgentReplayError('SAND_AI_AGENT_PROTOCOL_INVALID')
          if (['succeeded', 'failed', 'canceled', 'recovery_pending'].includes(event.state)) { sawTerminal = true; preview.value = '' }
          // The canonical Task API owns final results, costs and node states.
          void refresh()
        }
      })
      if (current === epoch) {
        transport.value = sawTerminal || terminal.value ? 'ended' : 'disconnected'
        notice.value = sawTerminal || terminal.value
          ? '实时连接已结束，请读取原任务确认结果、失败或取消状态与费用。'
          : '连接已结束，原任务状态尚未改变；将沿原游标只读读取后续状态。'
      }
    } catch (failure) {
      if (current !== epoch) return
      const denied = failure instanceof AgentReplayError && isAgentReadDenied(failure.code, failure.status)
      if (denied) {
        retryable = false
        reset()
        clearDenied()
        transport.value = 'denied'
        notice.value = '当前身份或来源已无法读取原任务，已清除旧回答和实时预览。'
      } else if (failure instanceof AgentReplayError && ['SAND_AI_CAPABILITY_UNSUPPORTED', 'SAND_AI_AGENT_PROTOCOL_INVALID'].includes(failure.code)) {
        retryable = false
        preview.value = ''
        transport.value = 'unavailable'
        notice.value = '此任务的实时事件不可用。请刷新原任务读取已验证的结果。'
      } else if (retryable) {
        transport.value = 'disconnected'
        notice.value = '连接中断，已保留原游标；只读重连不会重新提交问题。'
      }
    } finally {
      if (current === epoch) {
        connected.value = false
        if (retryable && !sawTerminal && !terminal.value && task.value?.id === taskId && !stopped.value && attempts < 5) {
          attempts += 1
          reconnect = setTimeout(() => { void connect() }, Math.min(attempts * 1000, 5000))
        } else if (retryable && !sawTerminal && !terminal.value && attempts >= 5) {
          transport.value = 'disconnected'
          notice.value = '自动重连已暂停。可沿原游标重连或读取原任务；断线不代表原任务失败。'
        }
      }
    }
  }
  function pause(): void {
    stop()
    stopped.value = true
    transport.value = 'paused'
    notice.value = '已暂停实时读取；服务端原任务仍继续处理。'
  }
  function resume(): void { attempts = 0; void connect() }
  watch([apiKey, code, () => task.value?.id], (current, prior) => {
    reset()
    // A new credential clears the private cursor; require an explicit read/reconnect under it.
    if (task.value && current[0] === prior[0] && current[1] === prior[1]) void connect()
  }, { flush: 'sync' })
  watch(() => task.value?.state, (state, priorState) => {
    if (terminal.value) {
      stop()
      preview.value = ''
      transport.value = 'ended'
      notice.value = '实时预览已结束，请以原任务的结果和费用为准。'
    } else if ((priorState === 'recovery_pending' || priorState === 'failed') && state && !stopped.value) {
      // A canonical read may confirm that the same task resumed. Keep its cursor;
      // reconnecting is a GET and never changes the original execution context.
      attempts = 0
      void connect()
    }
  }, { flush: 'sync' })
  onUnmounted(reset)
  return { preview, cursor, connected, transport, transportLabel, notice, pause, resume, reset }
}
