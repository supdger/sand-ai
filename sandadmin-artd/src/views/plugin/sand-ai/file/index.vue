<template>
  <div class="art-full-height">
    <ElCard class="art-table-card" shadow="never">
      <div class="file-header">
        <div>
          <h2 class="file-title">文件中心</h2>
          <p class="file-desc">
            消费运行面契约 1.4：上传 / 查询 / 异步提交解析 / 受控删除。解析后轮询任务状态。不展示
            bucket、object key 或永久 URL。
          </p>
        </div>
        <ElTag type="success" effect="plain">API 1.4 · runtime</ElTag>
      </div>

      <ElForm label-width="110px" class="file-cred" @submit.prevent>
        <RuntimeApiKeyField
          v-model="apiKey"
          placeholder="Bearer 环境凭证（需 files:read / files:write）"
        />
        <ElFormItem label="按 id 查询">
          <ElSpace wrap>
            <ElInputNumber v-model="lookupId" :min="1" :controls="false" placeholder="file id" />
            <ElButton :loading="busy === 'read'" @click="handleLookup">查询</ElButton>
          </ElSpace>
        </ElFormItem>
        <ElFormItem label="上传文件">
          <ElUpload
            :auto-upload="false"
            :show-file-list="false"
            :disabled="busy !== ''"
            accept=".pdf,.docx,.txt,.png,.jpg,.jpeg,.webp"
            @change="handleUploadChange"
          >
            <ElButton type="primary" :loading="busy === 'upload'">选择并上传</ElButton>
          </ElUpload>
        </ElFormItem>
      </ElForm>

      <ElAlert
        v-if="lastError"
        class="file-alert"
        type="error"
        :closable="true"
        :title="lastError.title"
        :description="lastError.description"
        @close="lastError = null"
      />

      <ArtTable row-key="id" :loading="busy !== ''" :data="recentFiles" :columns="columns" />
    </ElCard>
  </div>
</template>

<script setup lang="ts">
  import { h, onMounted, ref } from 'vue'
  import { useRouter } from 'vue-router'
  import { ElButton, ElMessage, ElMessageBox, ElSpace, type UploadFile } from 'element-plus'
  import type { ColumnOption } from '@/types'
  import fileApi from '../api/file'
  import taskApi from '../api/task'
  import { FILE_API_KEY_STORAGE } from '../api/config'
  import { SandAiRuntimeError } from '../api/runtime'
  import { hintForRuntimeErrorCode, toRuntimeUiError } from '../api/runtimeErrors'
  import type { RuntimeUiError } from '../api/runtimeErrors'
  import type { SandAiFile, SandAiTask } from '../api/types'
  import RuntimeApiKeyField from '../components/RuntimeApiKeyField.vue'

  const router = useRouter()
  const apiKey = ref('')
  const lookupId = ref<number | undefined>(undefined)
  const recentFiles = ref<SandAiFile[]>([])
  const lastTaskByFile = ref<Record<number, SandAiTask>>({})
  const busy = ref<'upload' | 'read' | 'parse' | 'delete' | ''>('')
  const lastError = ref<RuntimeUiError | null>(null)

  const columns: ColumnOption<SandAiFile>[] = [
    { prop: 'id', label: 'ID', width: 80, align: 'center' },
    { prop: 'original_name', label: '文件名', minWidth: 160 },
    { prop: 'extension', label: '扩展名', width: 90 },
    { prop: 'media_type', label: 'MIME', minWidth: 140 },
    { prop: 'size_bytes', label: '大小', width: 100 },
    { prop: 'state', label: '文件状态', width: 110 },
    {
      prop: 'latest_parse',
      label: '解析摘要',
      minWidth: 220,
      formatter: (row: SandAiFile) => formatParse(row)
    },
    {
      prop: 'task',
      label: '任务',
      width: 140,
      formatter: (row: SandAiFile) => {
        const task = lastTaskByFile.value[row.id] ?? row.latest_parse?.task
        if (!task) return '—'
        return `#${task.id} · ${task.state}`
      }
    },
    {
      prop: 'actions',
      label: '操作',
      width: 280,
      fixed: 'right',
      formatter: (row: SandAiFile) =>
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
              type: 'primary',
              loading: busy.value === 'parse',
              onClick: () => void handleParse(row.id)
            },
            () => '提交解析'
          ),
          h(
            ElButton,
            {
              size: 'small',
              disabled: !(lastTaskByFile.value[row.id] ?? row.latest_parse?.task),
              onClick: () => goTask(row.id)
            },
            () => '看任务'
          ),
          h(
            ElButton,
            {
              size: 'small',
              type: 'danger',
              loading: busy.value === 'delete',
              onClick: () => void handleDelete(row.id)
            },
            () => '删除'
          )
        ])
    }
  ]

  function upsertFile(file: SandAiFile): void {
    const next = recentFiles.value.filter((item) => item.id !== file.id)
    next.unshift(file)
    recentFiles.value = next
  }

  function formatParse(row: SandAiFile): string {
    const parse = row.latest_parse
    if (!parse) return '—'
    const err = parse.error_code ? ` · ${parse.error_code}` : ''
    return `${parse.state} · blocks=${parse.source_block_count}${err}`
  }

  function toUiError(error: unknown): RuntimeUiError {
    return toRuntimeUiError(error)
  }

  async function handleUploadChange(uploadFile: UploadFile): Promise<void> {
    const raw = uploadFile.raw
    if (!raw) return
    busy.value = 'upload'
    lastError.value = null
    try {
      const file = await fileApi.upload(apiKey.value, raw)
      upsertFile(file)
      ElMessage.success(`已上传 #${file.id}`)
    } catch (error) {
      lastError.value = toUiError(error)
    } finally {
      busy.value = ''
    }
  }

  async function handleLookup(): Promise<void> {
    if (!lookupId.value) {
      ElMessage.warning('请输入 file id')
      return
    }
    await refreshOne(lookupId.value)
  }

  async function refreshOne(fileId: number): Promise<void> {
    busy.value = 'read'
    lastError.value = null
    try {
      const file = await fileApi.read(apiKey.value, fileId)
      upsertFile(file)
    } catch (error) {
      lastError.value = toUiError(error)
    } finally {
      busy.value = ''
    }
  }

  function goTask(fileId: number): void {
    const task = lastTaskByFile.value[fileId]
    if (!task) return
    void router.push({ path: '/sand-ai/task', query: { id: String(task.id) } })
  }

  function isTerminal(state: string): boolean {
    return state === 'succeeded' || state === 'failed' || state === 'canceled'
  }

  async function pollTask(taskId: number, fileId: number): Promise<SandAiTask> {
    let latest = await taskApi.read(apiKey.value, taskId)
    lastTaskByFile.value = { ...lastTaskByFile.value, [fileId]: latest }
    for (let i = 0; i < 20 && !isTerminal(latest.state); i += 1) {
      await new Promise((resolve) => setTimeout(resolve, 1000))
      latest = await taskApi.read(apiKey.value, taskId)
      lastTaskByFile.value = { ...lastTaskByFile.value, [fileId]: latest }
    }
    return latest
  }

  async function handleParse(fileId: number): Promise<void> {
    busy.value = 'parse'
    lastError.value = null
    try {
      const requestId =
        typeof crypto !== 'undefined' && 'randomUUID' in crypto
          ? crypto.randomUUID()
          : `sand-ai-parse-${fileId}-${Date.now()}`
      const submitted = await fileApi.parse(apiKey.value, fileId, requestId)
      lastTaskByFile.value = { ...lastTaskByFile.value, [fileId]: submitted.task }
      const replay = submitted.idempotent_replay ? '（幂等重放）' : ''
      ElMessage.success(`已提交解析任务 #${submitted.task.id}${replay}`)

      const task = await pollTask(submitted.task.id, fileId)
      const file = await fileApi.read(apiKey.value, fileId)
      upsertFile(file)

      const code = task.error_code ?? file.latest_parse?.error_code
      const hint = hintForRuntimeErrorCode(code)
      if (code && hint) {
        lastError.value = { title: code, description: hint }
        ElMessage.warning(hint)
      } else if (task.state === 'succeeded') {
        ElMessage.success(`解析任务 #${task.id} 已完成`)
      } else if (!isTerminal(task.state)) {
        ElMessage.info(`任务 #${task.id} 仍为 ${task.state}，可在任务中心继续查看`)
      } else {
        ElMessage.warning(`任务 #${task.id} 结束：${task.state}`)
      }
    } catch (error) {
      lastError.value = toUiError(error)
      if (error instanceof SandAiRuntimeError && error.errorCode) {
        ElMessage.warning(hintForRuntimeErrorCode(error.errorCode) ?? error.message)
      }
    } finally {
      busy.value = ''
    }
  }

  async function handleDelete(fileId: number): Promise<void> {
    try {
      await ElMessageBox.confirm(`确认受控删除文件 #${fileId}？不可恢复。`, '删除确认', {
        type: 'warning'
      })
    } catch {
      return
    }
    busy.value = 'delete'
    lastError.value = null
    try {
      await fileApi.destroy(apiKey.value, fileId)
      recentFiles.value = recentFiles.value.filter((item) => item.id !== fileId)
      ElMessage.success(`已删除 #${fileId}`)
    } catch (error) {
      lastError.value = toUiError(error)
    } finally {
      busy.value = ''
    }
  }

  onMounted(() => {
    apiKey.value = sessionStorage.getItem(FILE_API_KEY_STORAGE) ?? ''
  })
</script>

<style scoped lang="scss">
  .file-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 16px;
  }

  .file-title {
    margin: 0;
    font-size: 18px;
    font-weight: 600;
    color: var(--el-text-color-primary);
  }

  .file-desc {
    margin: 4px 0 0;
    font-size: 13px;
    color: var(--el-text-color-secondary);
    max-width: 720px;
  }

  .file-cred {
    margin-bottom: 8px;
  }

  .file-alert {
    margin-bottom: 16px;
  }
</style>
