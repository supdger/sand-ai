<template>
  <ElDialog :model-value="modelValue" :title="`试一下 · ${agentName}`" width="min(680px, 94vw)"
    append-to-body destroy-on-close @update:model-value="emit('update:modelValue', $event)" @closed="reset">
    <div class="trial-dialog">
      <ElAlert v-if="intro" type="info" :closable="false" :title="intro" class="trial-intro" />
      <ElAlert v-if="error" type="error" :title="error" show-icon :closable="false" class="trial-error" />
      <div class="trial-messages" aria-live="polite">
        <ElEmpty v-if="turns.length === 0" :image-size="72"
          description="向这个智能体提个问题，看看它怎么回答" />
        <section v-for="(turn, index) in turns" :key="index" class="trial-turn">
          <p class="trial-query"><strong>你：</strong>{{ turn.query }}</p>
          <template v-if="turn.status === 'sending' || turn.status === 'thinking'">
            <p class="trial-thinking"><ElIcon class="is-loading"><Loading /></ElIcon>正在思考…</p>
          </template>
          <template v-else-if="turn.status === 'failed'">
            <p class="trial-answer trial-answer--error">{{ turn.errorText || '这次试用没有成功，请稍后重试' }}</p>
          </template>
          <template v-else>
            <p class="trial-answer">{{ turn.answer }}</p>
            <p v-if="turn.reviewRequired" class="trial-note">这条回答需要人工复核后再对外使用。</p>
            <template v-if="turn.sources?.length">
              <ElCollapse class="trial-sources">
                <ElCollapseItem :title="`查了 ${turn.sources.length} 段资料`">
                  <p v-for="(source, sourceIndex) in turn.sources" :key="sourceIndex" class="trial-source">
                    第 {{ sourceIndex + 1 }} 段 · {{ sourcePosition(source) }}
                  </p>
                </ElCollapseItem>
              </ElCollapse>
            </template>
          </template>
        </section>
      </div>
      <ElForm class="trial-form" @submit.prevent>
        <ElFormItem class="trial-input-item">
          <ElInput v-model="draft" type="textarea" :rows="3" maxlength="500" show-word-limit
            :disabled="sending" placeholder="输入想问的问题，最多 500 字" @keydown.ctrl.enter.prevent="send" @keydown.meta.enter.prevent="send" />
        </ElFormItem>
        <ElFormItem class="trial-actions">
          <ElButton type="primary" :loading="sending" :disabled="!canSend" @click="send">发送</ElButton>
          <span class="trial-hint">试用调用会记账并算进预算，在调用记录里标「试用」。</span>
        </ElFormItem>
      </ElForm>
    </div>
  </ElDialog>
</template>

<script setup lang="ts">
  import { computed, onUnmounted, reactive, ref, watch } from 'vue'
  import { ElMessage } from 'element-plus'
  import { Loading } from '@element-plus/icons-vue'
  import {
    isAgentTrialTerminal,
    requestAgentTrial,
    requestAgentTrialTask,
    type AgentTrialResult
  } from '../../api/adminTrial'
  import { newRuntimeRequestId } from '../../api/runtime'
  import { errorDisplayText } from '../../api/errorText'
  import { displayableRuntimeMessage, hintForRuntimeErrorCode } from '../../api/runtimeErrors'
  import type { SandAiSourceReference } from '../../api/types'

  interface TrialTurn {
    readonly query: string
    status: 'sending' | 'thinking' | 'done' | 'failed'
    answer: string
    sources: SandAiSourceReference[]
    reviewRequired: boolean
    errorText: string
  }

  const props = defineProps<{
    modelValue: boolean
    applicationId: number
    agentId: number
    agentName: string
    /** 当前智能体绑定的知识库名；没有绑定时为 undefined。 */
    knowledgeName?: string
  }>()
  const emit = defineEmits<{ 'update:modelValue': [value: boolean] }>()

  const draft = ref('')
  const sending = ref(false)
  const error = ref('')
  const turns = ref<TrialTurn[]>([])
  /** 每次打开抽屉生成一个会话标识，让追问能带上前文；关闭后重新生成。 */
  let conversationId = ''
  let pollTimer: ReturnType<typeof setTimeout> | null = null
  let pollEpoch = 0
  /** 轮询总时长上限，避免任务长时间不终态时界面一直转圈。 */
  const POLL_INTERVAL_MS = 2000
  const POLL_TIMEOUT_MS = 120000

  const canSend = computed(() => !sending.value && draft.value.trim() !== '')
  const intro = computed(() => {
    if (!props.knowledgeName) return '试用调用会真实调用模型并记账，结果和正式调用口径一致。'
    return `会先查「${props.knowledgeName}」，再用配置的模型回答。试用调用会记账并算进预算。`
  })

  /** 返回响应式 turn：applyResult/schedulePoll 经同一引用写字段，裸对象写入不会触发渲染。 */
  function createTurn(query: string): TrialTurn {
    return reactive<TrialTurn>({ query, status: 'sending', answer: '', sources: [], reviewRequired: false, errorText: '' })
  }

  function sourcePosition(source: SandAiSourceReference): string {
    const locator = source.locator
    const positive = (value: unknown): value is number => typeof value === 'number' && Number.isSafeInteger(value) && value > 0
    if (source.locator_type === 'line' && positive(locator.line)) return `第 ${locator.line} 行`
    if (positive(locator.page)) return `第 ${locator.page} 页`
    if (source.locator_type === 'table_row' && positive(locator.row)) {
      return `${typeof locator.sheet === 'string' ? `工作表「${locator.sheet}」· ` : ''}第 ${locator.row} 行`
    }
    return '来源位置尚无可展示说明'
  }

  /** 试用失败优先回显后端白话原因（含 502/503 业务码），再退到冻结码映射与兜底。 */
  function trialErrorText(error: unknown): string {
    if (error instanceof Error) {
      const text = displayableRuntimeMessage(error.message)
      if (text) return text
      const code = /\b(?:SAND_AI|SAND_IAM)_[A-Z0-9_]+\b/.exec(error.message)?.[0]
      const hint = hintForRuntimeErrorCode(code ?? null)
      if (hint) return hint
    }
    return errorDisplayText(error, '这次试用没有成功，请稍后重试')
  }

  function applyResult(turn: TrialTurn, result: AgentTrialResult): void {
    if (result.state === 'succeeded') {
      turn.status = 'done'
      turn.answer = typeof result.result?.output?.content === 'string' ? result.result.output.content : ''
      turn.sources = Array.isArray(result.result?.source_references) ? [...result.result.source_references] : []
      turn.reviewRequired = result.result?.review_required === true
      if (!turn.answer) turn.errorText = ''
      return
    }
    if (isAgentTrialTerminal(result.state)) {
      turn.status = 'failed'
      const code = typeof result.error_code === 'string' ? result.error_code : ''
      turn.errorText = (code ? hintForRuntimeErrorCode(code) : undefined)
        ?? '这次试用没有成功，请稍后重试；持续失败请检查模型服务商连接。'
      return
    }
    turn.status = 'thinking'
  }

  function stopPolling(): void {
    ++pollEpoch
    if (pollTimer !== null) {
      clearTimeout(pollTimer)
      pollTimer = null
    }
  }

  /** 非终态时按固定间隔轮询任务投影；只读，不会重新发起调用。 */
  function schedulePoll(turn: TrialTurn, taskId: number, waitedMs: number): void {
    stopPolling()
    if (waitedMs >= POLL_TIMEOUT_MS) {
      turn.status = 'failed'
      turn.errorText = '这次试用还没有完成，请稍后在调用记录里查看结果。'
      sending.value = false
      return
    }
    const epoch = pollEpoch
    pollTimer = setTimeout(async () => {
      if (epoch !== pollEpoch) return
      try {
        const result = await requestAgentTrialTask(taskId)
        if (epoch !== pollEpoch) return
        applyResult(turn, result)
        if (turn.status === 'thinking') schedulePoll(turn, taskId, waitedMs + POLL_INTERVAL_MS)
        else sending.value = false
      } catch (failure) {
        if (epoch !== pollEpoch) return
        // 轮询失败不判死任务：任务可能仍在跑，提示后停止自动轮询。
        turn.status = 'failed'
        turn.errorText = `${trialErrorText(failure)}任务可能仍在后台执行，请稍后再试。`
        sending.value = false
      }
    }, POLL_INTERVAL_MS)
  }

  async function send(): Promise<void> {
    const query = draft.value.trim()
    if (!canSend.value || !props.applicationId || !props.agentId) return
    const turn = createTurn(query)
    turns.value = [...turns.value, turn]
    draft.value = ''
    sending.value = true
    error.value = ''
    // 每次用户主动发送生成新幂等键；同一 turn 的轮询只读，不重发。
    const idempotencyKey = newRuntimeRequestId('agent-trial')
    try {
      const result = await requestAgentTrial(
        { application_id: props.applicationId, agent_id: props.agentId, query, conversation_id: conversationId || undefined },
        idempotencyKey
      )
      applyResult(turn, result)
      if (turn.status === 'thinking' && typeof result.task_id === 'number') {
        schedulePoll(turn, result.task_id, 0)
      } else {
        sending.value = false
      }
    } catch (failure) {
      turn.status = 'failed'
      turn.errorText = trialErrorText(failure)
      sending.value = false
    }
  }

  function reset(): void {
    stopPolling()
    draft.value = ''
    error.value = ''
    turns.value = []
    sending.value = false
    conversationId = `admin-trial-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`
  }

  watch(() => props.modelValue, (open) => {
    if (open) reset()
  }, { immediate: true })

  onUnmounted(() => { stopPolling() })
</script>

<style scoped lang="scss">
  .trial-dialog { display: flex; flex-direction: column; gap: 10px; }
  .trial-intro, .trial-error { margin: 0; }
  .trial-messages { max-height: 46vh; overflow-y: auto; display: flex; flex-direction: column; gap: 12px; padding: 4px 2px; }
  .trial-turn { border-bottom: 1px solid var(--el-border-color-lighter); padding-bottom: 10px; }
  .trial-query { margin: 0 0 6px; }
  .trial-answer { margin: 4px 0; white-space: pre-wrap; overflow-wrap: anywhere; }
  .trial-answer--error { color: var(--el-color-danger); }
  .trial-thinking { display: flex; align-items: center; gap: 6px; color: var(--el-text-color-secondary); margin: 4px 0; }
  .trial-note, .trial-hint { color: var(--el-text-color-secondary); font-size: 12px; }
  .trial-sources { margin-top: 6px; }
  .trial-source { margin: 2px 0; color: var(--el-text-color-secondary); font-size: 12px; }
  .trial-form { margin-top: 4px; }
  .trial-input-item { margin-bottom: 8px; }
  .trial-actions { display: flex; align-items: center; gap: 10px; margin-bottom: 0; }
</style>
