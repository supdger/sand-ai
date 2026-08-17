<template>
  <div class="art-full-height">
    <ElCard class="art-table-card" shadow="never">
      <div class="agent-header">
        <div>
          <h2 class="agent-title">Agent 运行</h2>
          <p class="agent-desc">
            消费契约 1.8：异步提交受控 Agent；首期工具仅 `retrieval.search`。`review_required`
            只是结果复核标记，不创建业务审批。
          </p>
        </div>
        <ElTag type="success" effect="plain">API 1.8 · agents</ElTag>
      </div>

      <ElForm label-width="120px" @submit.prevent>
        <RuntimeApiKeyField
          v-model="apiKey"
          placeholder="需 agents:write（及检索工具所需 scopes）"
        />
        <ElFormItem label="model">
          <ElInput v-model="form.model" placeholder="远程/逻辑模型名" style="width: 360px" />
        </ElFormItem>
        <ElFormItem label="instructions">
          <ElInput
            v-model="form.instructions"
            type="textarea"
            :rows="3"
            placeholder="Agent 指令（提交后不会在任务结果中回显）"
            style="max-width: 640px"
          />
        </ElFormItem>
        <ElFormItem label="query">
          <ElInput v-model="form.query" placeholder="用户查询，≤500 字" style="width: 520px" />
        </ElFormItem>
        <ElFormItem label="tools">
          <ElCheckboxGroup v-model="form.tools">
            <ElCheckbox value="retrieval.search" label="retrieval.search" />
          </ElCheckboxGroup>
        </ElFormItem>
        <ElFormItem label="review_required">
          <ElSwitch v-model="form.review_required" />
        </ElFormItem>
        <ElFormItem>
          <ElSpace wrap>
            <ElButton type="primary" :loading="busy === 'submit'" @click="handleSubmit">
              提交 Agent
            </ElButton>
            <ElButton
              :disabled="!activeTask"
              :loading="busy === 'read'"
              @click="activeTask && refreshOne(activeTask.id)"
            >
              刷新任务
            </ElButton>
            <ElButton :disabled="!polling" @click="stopPolling">停止轮询</ElButton>
            <ElTag v-if="polling" type="warning" effect="plain">轮询中…</ElTag>
            <ElButton link type="primary" @click="goTask">打开任务中心</ElButton>
          </ElSpace>
        </ElFormItem>
      </ElForm>

      <ElAlert
        v-if="lastError"
        class="agent-alert"
        type="error"
        :closable="true"
        :title="lastError.title"
        :description="lastError.description"
        @close="lastError = null"
      />

      <template v-if="activeTask">
        <ElDescriptions :column="2" border class="agent-meta">
          <ElDescriptionsItem label="task id">{{ activeTask.id }}</ElDescriptionsItem>
          <ElDescriptionsItem label="state">{{ activeTask.state }}</ElDescriptionsItem>
          <ElDescriptionsItem label="task_type">{{ activeTask.task_type }}</ElDescriptionsItem>
          <ElDescriptionsItem label="attempts">
            {{ activeTask.attempt_count }}/{{ activeTask.max_attempts }}
          </ElDescriptionsItem>
          <ElDescriptionsItem label="error_code">
            {{ activeTask.error_code || '—' }}
          </ElDescriptionsItem>
          <ElDescriptionsItem label="review_required">
            {{ activeTask.result?.review_required === true ? 'true' : 'false' }}
          </ElDescriptionsItem>
        </ElDescriptions>

        <h3 class="agent-section">生成内容</h3>
        <ElInput
          type="textarea"
          :rows="6"
          readonly
          :model-value="activeTask.result?.output?.content ?? ''"
          placeholder="任务完成后显示（不回显 instructions/query）"
        />

        <h3 class="agent-section">工具调用</h3>
        <ArtTable
          row-key="tool"
          :data="activeTask.result?.tool_calls ?? []"
          :columns="toolColumns"
          empty-text="暂无工具调用"
        />

        <h3 class="agent-section">来源引用</h3>
        <ArtTable
          row-key="source_block_id"
          :data="activeTask.result?.source_references ?? []"
          :columns="refColumns"
          empty-text="暂无来源引用"
        />
      </template>
    </ElCard>
  </div>
</template>

<script setup lang="ts">
  import { onMounted, onUnmounted, reactive, ref } from 'vue'
  import { useRouter } from 'vue-router'
  import { ElMessage } from 'element-plus'
  import type { ColumnOption } from '@/types'
  import agentApi from '../api/agent'
  import taskApi from '../api/task'
  import { FILE_API_KEY_STORAGE } from '../api/config'
  import { newRuntimeRequestId } from '../api/runtime'
  import { toRuntimeUiError } from '../api/runtimeErrors'
  import type { RuntimeUiError } from '../api/runtimeErrors'
  import type { SandAiSourceReference, SandAiTask, SandAiTaskToolCall } from '../api/types'
  import RuntimeApiKeyField from '../components/RuntimeApiKeyField.vue'

  const TERMINAL = new Set(['succeeded', 'failed', 'canceled'])
  const POLL_MS = 2000
  const MAX_POLLS = 60

  const router = useRouter()
  const apiKey = ref('')
  const busy = ref<'submit' | 'read' | ''>('')
  const activeTask = ref<SandAiTask | null>(null)
  const lastError = ref<RuntimeUiError | null>(null)
  const polling = ref(false)
  let pollTimer: ReturnType<typeof setTimeout> | null = null
  let pollCount = 0

  const form = reactive({
    model: 'fake-chat',
    instructions: '仅使用检索工具回答，并保留来源引用。',
    query: '',
    tools: ['retrieval.search'] as string[],
    review_required: false
  })

  const toolColumns: ColumnOption<SandAiTaskToolCall>[] = [
    { prop: 'tool', label: 'tool', minWidth: 160 },
    {
      prop: 'source_block_ids',
      label: 'source_block_ids',
      minWidth: 200,
      formatter: (row) => (row.source_block_ids ?? []).join(', ') || '—'
    }
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

  function toUiError(error: unknown): RuntimeUiError {
    return toRuntimeUiError(error)
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

  async function refreshOne(taskId: number, fromPoll = false): Promise<void> {
    if (!fromPoll) {
      busy.value = 'read'
    }
    lastError.value = null
    try {
      const task = await taskApi.read(apiKey.value, taskId)
      activeTask.value = task
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

  function startPolling(taskId: number): void {
    stopPolling()
    polling.value = true
    pollCount = 0
    schedulePoll(taskId)
  }

  async function handleSubmit(): Promise<void> {
    if (!form.model.trim() || !form.instructions.trim() || !form.query.trim()) {
      ElMessage.warning('请填写 model / instructions / query')
      return
    }
    if (form.tools.length === 0) {
      ElMessage.warning('至少选择一个允许的工具')
      return
    }
    busy.value = 'submit'
    lastError.value = null
    stopPolling()
    try {
      const submitted = await agentApi.submit(
        apiKey.value,
        {
          model: form.model.trim(),
          instructions: form.instructions.trim(),
          query: form.query.trim(),
          tools: form.tools,
          review_required: form.review_required
        },
        newRuntimeRequestId('agent')
      )
      activeTask.value = submitted.task
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

  function goTask(): void {
    if (activeTask.value) {
      void router.push({ path: '/sand-ai/task', query: { id: String(activeTask.value.id) } })
      return
    }
    void router.push('/sand-ai/task')
  }

  onMounted(() => {
    apiKey.value = sessionStorage.getItem(FILE_API_KEY_STORAGE) ?? ''
  })

  onUnmounted(() => {
    stopPolling()
  })
</script>

<style scoped lang="scss">
  .agent-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 16px;
  }

  .agent-title {
    margin: 0;
    font-size: 18px;
    font-weight: 600;
  }

  .agent-desc {
    margin: 4px 0 0;
    font-size: 13px;
    color: var(--el-text-color-secondary);
    max-width: 760px;
  }

  .agent-alert {
    margin-bottom: 16px;
  }

  .agent-meta {
    margin-bottom: 16px;
  }

  .agent-section {
    margin: 16px 0 8px;
    font-size: 15px;
    font-weight: 600;
  }
</style>
