<template>
  <div class="art-full-height">
    <ElCard class="art-table-card" shadow="never">
      <div class="task-header">
        <div>
          <h2 class="task-title">任务中心</h2>
          <p class="task-desc">
            消费契约 1.4–1.6：查询/取消/重试；可提交异步 `ai_completion`；结果含受控输出与来源引用。
          </p>
        </div>
        <ElTag type="success" effect="plain">API 1.6 · tasks</ElTag>
      </div>

      <ElForm label-width="110px" class="task-cred" @submit.prevent>
        <RuntimeApiKeyField v-model="apiKey" placeholder="需 tasks:read / tasks:write" />
        <ElFormItem label="按 id 查询">
          <ElSpace wrap>
            <ElInputNumber v-model="lookupId" :min="1" :controls="false" placeholder="task id" />
            <ElButton :loading="busy === 'read'" type="primary" @click="handleLookup">查询</ElButton>
          </ElSpace>
        </ElFormItem>
      </ElForm>

      <ElDivider content-position="left">提交 AI 任务</ElDivider>
      <ElForm label-width="110px" class="task-ai" @submit.prevent>
        <ElFormItem label="model">
          <ElInput v-model="aiForm.model" placeholder="模型名" style="width: 280px" />
        </ElFormItem>
        <ElFormItem label="user message">
          <ElInput
            v-model="aiForm.content"
            type="textarea"
            :rows="3"
            placeholder="用户消息"
            style="max-width: 640px"
          />
        </ElFormItem>
        <ElFormItem label="source_block_ids">
          <ElInput
            v-model="aiForm.sourceBlockIdsText"
            placeholder="可选，逗号分隔，如 1,2,3"
            style="width: 360px"
          />
        </ElFormItem>
        <ElFormItem>
          <ElSpace wrap>
            <ElButton type="primary" :loading="busy === 'submit'" @click="handleAiSubmit">
              提交 ai_completion
            </ElButton>
            <ElButton :disabled="!polling" @click="stopPolling">停止轮询</ElButton>
            <ElTag v-if="polling" type="warning" effect="plain">轮询中…</ElTag>
          </ElSpace>
        </ElFormItem>
      </ElForm>

      <ElAlert
        v-if="lastError"
        class="task-alert"
        type="error"
        :closable="true"
        :title="lastError.title"
        :description="lastError.description"
        @close="lastError = null"
      />

      <ArtTable
        row-key="id"
        :loading="busy !== '' && !polling"
        :data="recentTasks"
        :columns="columns"
      />

      <div v-if="activeTask" class="task-detail">
        <h3 class="task-detail__title">任务 #{{ activeTask.id }} 步骤</h3>
        <ArtTable
          row-key="code"
          :data="activeTask.steps ?? []"
          :columns="stepColumns"
          empty-text="暂无步骤（查询详情后显示）"
        />

        <h3 class="task-detail__title">结果</h3>
        <ElInput
          type="textarea"
          :rows="5"
          readonly
          :model-value="activeTask.result?.output?.content ?? ''"
          placeholder="完成后显示生成内容（不回显 prompt）"
        />
        <p v-if="activeTask.result?.review_required !== undefined" class="task-footnote">
          review_required={{ activeTask.result.review_required }}
        </p>

        <h3 class="task-detail__title">来源引用</h3>
        <ArtTable
          row-key="source_block_id"
          :data="activeTask.result?.source_references ?? []"
          :columns="refColumns"
          empty-text="暂无来源引用"
        />
      </div>
    </ElCard>
  </div>
</template>

<script setup lang="ts">
  import { h, onMounted, onUnmounted, reactive, ref } from 'vue'
  import { useRoute } from 'vue-router'
  import { ElButton, ElMessage, ElMessageBox, ElSpace } from 'element-plus'
  import type { ColumnOption } from '@/types'
  import aiTaskApi from '../api/aiTask'
  import taskApi from '../api/task'
  import { FILE_API_KEY_STORAGE } from '../api/config'
  import { newRuntimeRequestId } from '../api/runtime'
  import { toRuntimeUiError } from '../api/runtimeErrors'
  import type { RuntimeUiError } from '../api/runtimeErrors'
  import type {
    SandAiSourceReference,
    SandAiTask,
    SandAiTaskStep
  } from '../api/types'
  import RuntimeApiKeyField from '../components/RuntimeApiKeyField.vue'

  const TERMINAL = new Set(['succeeded', 'failed', 'canceled'])
  const POLL_MS = 2000
  const MAX_POLLS = 60

  const apiKey = ref('')
  const lookupId = ref<number | undefined>(undefined)
  const recentTasks = ref<SandAiTask[]>([])
  const activeTask = ref<SandAiTask | null>(null)
  const busy = ref<'read' | 'cancel' | 'retry' | 'submit' | ''>('')
  const lastError = ref<RuntimeUiError | null>(null)
  const polling = ref(false)
  let pollTimer: ReturnType<typeof setTimeout> | null = null
  let pollCount = 0

  const aiForm = reactive({
    model: 'fake-chat',
    content: '',
    sourceBlockIdsText: ''
  })

  const columns: ColumnOption<SandAiTask>[] = [
    { prop: 'id', label: 'ID', width: 80, align: 'center' },
    { prop: 'task_type', label: '类型', minWidth: 120 },
    { prop: 'resource_type', label: '资源', width: 110 },
    { prop: 'resource_id', label: '资源 ID', width: 100 },
    { prop: 'state', label: '状态', width: 110 },
    {
      prop: 'attempt_count',
      label: '尝试',
      width: 100,
      formatter: (row) => `${row.attempt_count}/${row.max_attempts}`
    },
    { prop: 'error_code', label: 'error_code', minWidth: 140 },
    {
      prop: 'id',
      label: '操作',
      width: 260,
      fixed: 'right',
      formatter: (row) =>
        h(ElSpace, { wrap: true }, () => [
          h(
            ElButton,
            { size: 'small', loading: busy.value === 'read', onClick: () => void refreshOne(row.id) },
            () => '刷新'
          ),
          h(
            ElButton,
            {
              size: 'small',
              type: 'warning',
              disabled: row.state !== 'failed',
              loading: busy.value === 'retry',
              onClick: () => void handleRetry(row.id)
            },
            () => '重试'
          ),
          h(
            ElButton,
            {
              size: 'small',
              type: 'danger',
              disabled: TERMINAL.has(row.state),
              loading: busy.value === 'cancel',
              onClick: () => void handleCancel(row.id)
            },
            () => '取消'
          )
        ])
    }
  ]

  const stepColumns: ColumnOption<SandAiTaskStep>[] = [
    { prop: 'code', label: '步骤', minWidth: 140 },
    { prop: 'state', label: '状态', width: 110 },
    { prop: 'attempt_count', label: '尝试', width: 90 },
    { prop: 'error_code', label: 'error_code', minWidth: 140 },
    { prop: 'error_summary', label: '摘要', minWidth: 180 },
    { prop: 'started_at', label: '开始', width: 170 },
    { prop: 'completed_at', label: '完成', width: 170 }
  ]

  const refColumns: ColumnOption<SandAiSourceReference>[] = [
    { prop: 'source_block_id', label: 'block', width: 90 },
    { prop: 'file_id', label: 'file', width: 90 },
    { prop: 'locator_type', label: 'locator_type', width: 120 },
    {
      prop: 'locator',
      label: 'locator',
      minWidth: 180,
      formatter: (row) => JSON.stringify(row.locator)
    }
  ]

  function upsertTask(task: SandAiTask): void {
    const next = recentTasks.value.filter((item) => item.id !== task.id)
    next.unshift(task)
    recentTasks.value = next
    activeTask.value = task
  }

  function toUiError(error: unknown): RuntimeUiError {
    return toRuntimeUiError(error)
  }

  function parseSourceBlockIds(text: string): number[] {
    if (!text.trim()) return []
    return text
      .split(',')
      .map((part) => part.trim())
      .filter((part) => /^\d+$/.test(part))
      .map((part) => Number(part))
  }

  function stopPolling(): void {
    polling.value = false
    pollCount = 0
    if (pollTimer !== null) {
      clearTimeout(pollTimer)
      pollTimer = null
    }
  }

  function schedulePoll(taskId: number): void {
    if (!polling.value) return
    if (pollCount >= MAX_POLLS) {
      stopPolling()
      ElMessage.warning(`已轮询 ${MAX_POLLS} 次仍未终态，已停止`)
      return
    }
    pollTimer = setTimeout(() => {
      void refreshOne(taskId, true)
    }, POLL_MS)
  }

  function startPolling(taskId: number): void {
    stopPolling()
    polling.value = true
    pollCount = 0
    schedulePoll(taskId)
  }

  async function handleLookup(): Promise<void> {
    if (!lookupId.value) {
      ElMessage.warning('请输入 task id')
      return
    }
    stopPolling()
    await refreshOne(lookupId.value)
  }

  async function refreshOne(taskId: number, fromPoll = false): Promise<void> {
    if (!fromPoll) {
      busy.value = 'read'
    }
    lastError.value = null
    try {
      const task = await taskApi.read(apiKey.value, taskId)
      upsertTask(task)
      if (TERMINAL.has(task.state)) {
        if (polling.value) {
          stopPolling()
          ElMessage.success(`任务 #${taskId} 已终态：${task.state}`)
        }
        return
      }
      if (polling.value) {
        pollCount += 1
        schedulePoll(taskId)
      }
    } catch (error) {
      lastError.value = toUiError(error)
      if (fromPoll) {
        stopPolling()
      }
    } finally {
      if (!fromPoll) {
        busy.value = ''
      }
    }
  }

  async function handleCancel(taskId: number): Promise<void> {
    try {
      await ElMessageBox.confirm(`确认取消任务 #${taskId}？`, '取消确认', { type: 'warning' })
    } catch {
      return
    }
    busy.value = 'cancel'
    lastError.value = null
    try {
      const task = await taskApi.cancel(apiKey.value, taskId)
      upsertTask(task)
      ElMessage.success(`任务 #${taskId} 已请求取消`)
      if (!TERMINAL.has(task.state)) {
        startPolling(taskId)
      } else {
        stopPolling()
      }
    } catch (error) {
      lastError.value = toUiError(error)
    } finally {
      busy.value = ''
    }
  }

  async function handleRetry(taskId: number): Promise<void> {
    try {
      await ElMessageBox.confirm(`确认重试失败任务 #${taskId}？`, '重试确认', { type: 'warning' })
    } catch {
      return
    }
    busy.value = 'retry'
    lastError.value = null
    try {
      const task = await taskApi.retry(apiKey.value, taskId)
      upsertTask(task)
      ElMessage.success(`任务 #${taskId} 已重新入队`)
      if (!TERMINAL.has(task.state)) {
        startPolling(taskId)
      }
    } catch (error) {
      lastError.value = toUiError(error)
    } finally {
      busy.value = ''
    }
  }

  async function handleAiSubmit(): Promise<void> {
    if (!aiForm.model.trim() || !aiForm.content.trim()) {
      ElMessage.warning('请填写 model 与 user message')
      return
    }
    busy.value = 'submit'
    lastError.value = null
    stopPolling()
    try {
      const submitted = await aiTaskApi.submit(
        apiKey.value,
        {
          model: aiForm.model.trim(),
          messages: [{ role: 'user', content: aiForm.content.trim() }],
          source_block_ids: parseSourceBlockIds(aiForm.sourceBlockIdsText)
        },
        newRuntimeRequestId('ai')
      )
      upsertTask(submitted.task)
      lookupId.value = submitted.task.id
      ElMessage.success(
        submitted.idempotent_replay
          ? `幂等重放 task #${submitted.task.id}`
          : `已提交 task #${submitted.task.id}`
      )
      await refreshOne(submitted.task.id)
      if (activeTask.value && !TERMINAL.has(activeTask.value.state)) {
        startPolling(submitted.task.id)
      }
    } catch (error) {
      lastError.value = toUiError(error)
    } finally {
      busy.value = ''
    }
  }

  const route = useRoute()

  onMounted(() => {
    apiKey.value = sessionStorage.getItem(FILE_API_KEY_STORAGE) ?? ''
    const raw = route.query.id
    const fromQuery = typeof raw === 'string' ? raw : Array.isArray(raw) ? raw[0] : undefined
    if (typeof fromQuery === 'string' && /^\d+$/.test(fromQuery)) {
      lookupId.value = Number(fromQuery)
      void refreshOne(lookupId.value)
    }

    const blocksRaw = route.query.source_block_ids
    const blocks =
      typeof blocksRaw === 'string' ? blocksRaw : Array.isArray(blocksRaw) ? blocksRaw[0] : undefined
    if (typeof blocks === 'string' && blocks.trim()) {
      aiForm.sourceBlockIdsText = blocks.trim()
    }
    const qRaw = route.query.q
    const q = typeof qRaw === 'string' ? qRaw : Array.isArray(qRaw) ? qRaw[0] : undefined
    if (typeof q === 'string' && q.trim() && !aiForm.content.trim()) {
      aiForm.content = `请结合给定来源回答：${q.trim()}`
    }
  })

  onUnmounted(() => {
    stopPolling()
  })
</script>

<style scoped lang="scss">
  .task-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 16px;
  }

  .task-title {
    margin: 0;
    font-size: 18px;
    font-weight: 600;
  }

  .task-desc {
    margin: 4px 0 0;
    font-size: 13px;
    color: var(--el-text-color-secondary);
    max-width: 720px;
  }

  .task-cred,
  .task-ai {
    margin-bottom: 8px;
  }

  .task-alert {
    margin-bottom: 16px;
  }

  .task-detail {
    margin-top: 20px;
  }

  .task-detail__title {
    margin: 16px 0 12px;
    font-size: 15px;
    font-weight: 600;
  }

  .task-footnote {
    margin: 8px 0 0;
    font-size: 12px;
    color: var(--el-text-color-secondary);
  }
</style>
