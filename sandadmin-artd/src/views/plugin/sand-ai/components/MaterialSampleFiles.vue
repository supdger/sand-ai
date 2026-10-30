<template>
  <section class="sample-files">
    <h4>{{ purpose === 'knowledge' ? '准备知识来源' : '准备样本材料' }}</h4>
    <p class="muted">使用上方同一应用的运行身份上传私有材料。后台登录不代替应用授权；
      上传需 file.write，解析需 document_parse，读取需 file.read。{{ purpose === 'knowledge' ? '解析完成后，选用来源建立知识库草稿。' : '解析完成后，再将文件加入样本。' }}</p>
    <ElSpace wrap>
      <PrivateFileUpload :bearer="bearer" :environment-id="environmentId"
        :disabled="!canWrite || files.length >= 100" @file="upsert"
        @busy="uploadBusy" @identity-denied="emit('identity-denied', $event)" />
      <ElInputNumber v-model="lookupId" :min="1" :controls="false" placeholder="已有文件 ID" />
      <ElButton :disabled="!canRead || !lookupId || files.length >= 100" :loading="operation === 'read'" @click="lookup">读取已有文件</ElButton>
      <ElButton v-if="files.length" :disabled="!canRead" :loading="operation === 'refresh'" @click="refreshFiles()">刷新解析进度</ElButton>
    </ElSpace>
    <ElAlert v-if="recoveryStorageError" class="notice" type="warning" :closable="false"
      title="原解析请求记录暂不可确认，当前不能提交新解析；请重新读取原记录。">
      <ElButton :disabled="!canRead" @click="restoreRequests">重读原解析记录</ElButton>
    </ElAlert>
    <ElSpace v-if="pendingRequests.length" wrap class="notice">
      <span>原解析结果尚待确认：</span>
      <ElButton v-for="pending in pendingRequests" :key="pending.fileId" :disabled="!canRead"
        @click="recoverParse(pending.fileId)">只读找回文件 #{{ pending.fileId }} 的原解析</ElButton>
    </ElSpace>
    <ElAlert v-if="error" class="notice" type="error" :closable="false" :title="error" />
    <p v-if="!bearer.trim()" class="muted">先配置应用提供的 SandIAM 运行身份，再上传或读取材料。</p>
    <p v-else-if="!files.length" class="muted">{{ purpose === 'knowledge' ? '上传新资料，或按文件号读取已有资料；恢复旧 TXT 大块时重新上传原文件。' : '可上传一份 TXT 开始试用；扫描 PDF 和图片还需已配置的 OCR 能力。' }}</p>
    <ElTable v-if="files.length" :data="files" row-key="id" class="file-table">
      <ElTableColumn prop="original_name" label="材料" min-width="160" />
      <ElTableColumn prop="id" label="文件 ID" width="100" />
      <ElTableColumn label="解析进度" min-width="180">
        <template #default="{ row }">
          <p>{{ parseLabel(row) }}</p>
          <p v-if="row.latest_parse?.error_code" class="muted">
            {{ parseError(row.latest_parse.error_code) }}
          </p>
        </template>
      </ElTableColumn>
      <ElTableColumn label="操作" min-width="220">
        <template #default="{ row }">
          <ElSpace wrap>
            <ElButton v-if="canStartParse(row)" :disabled="!canWrite" @click="parse(row)">提交解析</ElButton>
            <ElButton v-if="parseRequests[row.id]" :disabled="!canRead" @click="recoverParse(row.id)">只读确认原解析</ElButton>
            <ElButton v-if="originalTask(row)" :disabled="!canRead" @click="goTask(row)">
              {{ taskActionLabel(row) }}
            </ElButton>
            <ElButton v-if="originalTask(row)?.state === 'canceled'" :disabled="!canWrite" @click="restartCanceledParse(row)">核对取消后重新解析</ElButton>
            <ElButton type="primary" :disabled="!canRead || disabled || !ready(row) || !!parseRequests[row.id]"
              @click="useFile(row)">{{ purpose === 'knowledge' ? '选用来源' : '加入样本' }}</ElButton>
          </ElSpace>
        </template>
      </ElTableColumn>
    </ElTable>
    <p v-if="files.length" class="muted">解析可能产生费用；完成后的材料仍保留在应用的私有文件中。
      本页最多准备 100 份，不自动删除文件。失败只继续原任务；取消后的新解析须先核实原任务未派发 OCR。
      任务入口可查看进度、取消未完成处理、重试失败任务及核对费用。</p>
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessageBox } from 'element-plus'
import PrivateFileUpload from './PrivateFileUpload.vue'
import { useUserStore } from '@/store/modules/user'
import fileApi from '../api/file'
import taskApi from '../api/task'
import { saveRuntimeBearer } from '../api/runtimeBearerSession'
import { newRuntimeRequestId, SandAiRuntimeError } from '../api/runtime'
import { hintForRuntimeErrorCode } from '../api/runtimeErrors'
import type { SandAiFile, SandAiTask } from '../api/types'

const props = withDefaults(defineProps<{ bearer: string; environmentId: number; disabled: boolean; purpose?: 'material' | 'knowledge' }>(), {
  purpose: 'material'
})
const router = useRouter()
const userStore = useUserStore()
const emit = defineEmits<{
  'use-file': [fileId: number]
  'identity-denied': [failure: SandAiRuntimeError]
}>()
const files = ref<SandAiFile[]>([])
const lookupId = ref<number | undefined>()
const operation = ref<'upload' | 'read' | 'parse' | 'refresh' | ''>('')
const error = ref('')
type ParseRequest = { fileId: number; key: string; taskId: number | null }
const parseRequests = ref<Record<number, ParseRequest>>({})
const parseTasks = ref<Record<number, SandAiTask>>({})
const recoveryStorageError = ref(false)
const pendingRequests = computed(() => Object.values(parseRequests.value))
const canRead = computed(() => Boolean(props.bearer.trim()) && operation.value === '')
const canWrite = computed(() => canRead.value && !props.disabled && !recoveryStorageError.value
  && typeof userStore.info.id === 'number' && Number.isSafeInteger(userStore.info.id) && userStore.info.id > 0)
let generation = 0
let timer: ReturnType<typeof setTimeout> | null = null

function ready(file: SandAiFile): boolean {
  return file.state === 'parsed' && file.latest_parse?.state === 'succeeded'
    && Number.isSafeInteger(file.latest_parse.id) && file.latest_parse.id > 0
    && file.latest_parse.source_block_count > 0 && /^[a-f0-9]{64}$/i.test(file.sha256)
}

function originalTask(file: SandAiFile): SandAiTask | null {
  return parseTasks.value[file.id] ?? file.latest_parse?.task ?? null
}

function canStartParse(file: SandAiFile): boolean {
  return !parseRequests.value[file.id] && !file.latest_parse && !originalTask(file)
    && ['uploaded', 'available'].includes(file.state)
}

function taskActionLabel(file: SandAiFile): string {
  const task = originalTask(file)
  if (task?.state === 'failed') return '继续原失败任务'
  if (task?.state === 'canceled') return '查看原取消任务与费用'
  if (task?.state === 'succeeded') return '查看原解析任务与费用'
  return '查看原进度并取消'
}

function assertTask(task: SandAiTask, fileId: number, taskId: number | null): void {
  if (!Number.isSafeInteger(task.id) || task.id < 1 || task.task_type !== 'file_parse'
    || task.resource_type !== 'file' || task.resource_id !== fileId
    || (taskId !== null && task.id !== taskId)) throw new Error('解析响应与原任务不一致')
}

function requestStorageKey(): string {
  if (typeof userStore.info.id !== 'number' || !Number.isSafeInteger(userStore.info.id) || userStore.info.id < 1
    || !Number.isSafeInteger(props.environmentId) || props.environmentId < 1) {
    throw new Error('当前身份与环境尚未确认')
  }
  return `sand-ai-material-file-parses:${userStore.info.id}:${props.environmentId}`
}

function restoreRequests(): void {
  recoveryStorageError.value = false
  try {
    const raw: unknown = JSON.parse(sessionStorage.getItem(requestStorageKey()) ?? '[]')
    if (!Array.isArray(raw) || raw.length > 100) throw new Error('原解析记录无效')
    const restored: Record<number, ParseRequest> = {}
    for (const value of raw) {
      if (typeof value !== 'object' || value === null) throw new Error('原解析记录无效')
      const entry = value as Record<string, unknown>
      if (typeof entry.fileId !== 'number' || !Number.isSafeInteger(entry.fileId) || entry.fileId < 1
        || typeof entry.key !== 'string' || !/^[A-Za-z0-9][A-Za-z0-9_.:-]{7,95}$/.test(entry.key)
        || (entry.taskId !== null && (typeof entry.taskId !== 'number' || !Number.isSafeInteger(entry.taskId) || entry.taskId < 1))
        || restored[entry.fileId]) throw new Error('原解析记录无效')
      restored[entry.fileId] = { fileId: entry.fileId, key: entry.key, taskId: entry.taskId }
    }
    // Reload cannot discard an in-memory unresolved request.
    for (const [fileId, current] of Object.entries(parseRequests.value)) {
      const persisted = restored[Number(fileId)]
      if (!persisted || persisted.key !== current.key
        || (current.taskId !== null && persisted.taskId !== current.taskId)) {
        throw new Error('原解析记录发生变化')
      }
    }
    parseRequests.value = restored
  } catch {
    recoveryStorageError.value = true
  }
}

function retainRequest(request: ParseRequest): boolean {
  try {
    const next = { ...parseRequests.value, [request.fileId]: request }
    if (Object.keys(next).length > 100) throw new Error('原解析记录过多')
    const encoded = JSON.stringify(Object.values(next))
    sessionStorage.setItem(requestStorageKey(), encoded)
    if (sessionStorage.getItem(requestStorageKey()) !== encoded) throw new Error('原解析记录未保存')
    parseRequests.value = next
    return true
  } catch {
    recoveryStorageError.value = true
    return false
  }
}

function resolveRequest(fileId: number, task: SandAiTask): void {
  const request = parseRequests.value[fileId]
  if (!request || task.state !== 'succeeded') return
  assertTask(task, fileId, request.taskId)
  const next = { ...parseRequests.value }
  delete next[fileId]
  try {
    const encoded = JSON.stringify(Object.values(next))
    sessionStorage.setItem(requestStorageKey(), encoded)
    if (sessionStorage.getItem(requestStorageKey()) !== encoded) throw new Error('原解析记录未保存')
    parseRequests.value = next
  } catch {
    recoveryStorageError.value = true
  }
}

function goTask(file: SandAiFile): void {
  const task = originalTask(file)
  if (!canRead.value || !task) return
  assertTask(task, file.id, null)
  saveRuntimeBearer(props.bearer)
  void router.push({ path: '/sand-ai/task', query: { id: String(task.id) } })
}

function parseLabel(file: SandAiFile): string {
  if (ready(file)) return `已解析 · ${file.latest_parse?.source_block_count} 个来源块`
  if (file.latest_parse?.state === 'succeeded') return props.purpose === 'knowledge' ? '未获得可用来源，不能选用' : '未获得可用来源，不能加入样本'
  return ({
    uploaded: '已上传，待提交解析', available: '待提交解析', parsing: '解析中',
    parse_failed: '解析失败', parse_canceled: '解析已取消', deleted: '文件已删除',
    deleting: '正在删除', delete_failed: '文件不可用'
  } as Record<string, string>)[file.state] ?? '请刷新确认文件状态'
}

function parseError(code: string): string {
  return hintForRuntimeErrorCode(code) ?? `解析未完成（${code}），请在任务中心核对。`
}

function upsert(file: SandAiFile, expectedId?: number): void {
  if (!Number.isSafeInteger(file.id) || file.id < 1 || (expectedId !== undefined && file.id !== expectedId)) {
    throw new Error('文件响应与本次读取不一致')
  }
  files.value = [file, ...files.value.filter(item => item.id !== file.id)]
}

function fail(failure: unknown, fallback: string): void {
  if (failure instanceof SandAiRuntimeError) {
    if ([401, 403].includes(failure.httpStatus) || failure.errorCode === 'SAND_AI_ENVIRONMENT_MISMATCH') {
      generation++
      stopPoll()
      files.value = []
      parseTasks.value = {}
      operation.value = ''
      emit('identity-denied', failure)
    }
    error.value = hintForRuntimeErrorCode(failure.errorCode)
      ?? (failure.errorCode ? `${fallback}（${failure.errorCode}）。` : fallback)
    return
  }
  // Transport errors may carry request configuration; keep credentials out of UI and logs.
  error.value = fallback
}

function uploadBusy(value: boolean): void {
  if (value) { stopPoll(); operation.value = 'upload' }
  else if (operation.value === 'upload') { operation.value = ''; schedulePoll() }
}

async function lookup(): Promise<void> {
  if (!canRead.value || !Number.isSafeInteger(lookupId.value) || Number(lookupId.value) < 1 || files.value.length >= 100) return
  const id = Number(lookupId.value)
  const current = generation
  stopPoll()
  operation.value = 'read'
  error.value = ''
  try {
    const file = await fileApi.read(props.bearer, id, props.environmentId)
    if (current === generation) upsert(file, id)
  } catch (failure) {
    if (current === generation) fail(failure, '读取材料失败，请核对文件 ID 和当前应用的读取授权。')
  } finally {
    if (current === generation) {
      operation.value = ''
      schedulePoll()
    }
  }
}

async function parse(file: SandAiFile): Promise<void> {
  if (!canWrite.value || !canStartParse(file)) return
  await submitParse(file.id)
}

async function submitParse(id: number): Promise<void> {
  const current = generation
  const credential = props.bearer
  const environmentId = props.environmentId
  stopPoll()
  operation.value = 'parse'
  error.value = ''
  const key = newRuntimeRequestId('material-parse')
  if (!retainRequest({ fileId: id, key, taskId: null })) {
    operation.value = ''
    return
  }
  try {
    const result = await fileApi.parse(credential, id, key, environmentId)
    if (current !== generation) return
    if (result.file_id !== id) throw new Error('解析响应与文件不一致')
    assertTask(result.task, id, null)
    parseTasks.value = { ...parseTasks.value, [id]: result.task }
    retainRequest({ fileId: id, key, taskId: result.task.id })
    const latest = await fileApi.read(credential, id, environmentId)
    if (current === generation) {
      upsert(latest, id)
      resolveRequest(id, result.task)
    }
  } catch (failure) {
    if (current === generation) fail(failure, '解析结果未确认，请只读找回原解析；未找到或读取失败不能重新发送。')
  } finally {
    if (current === generation) {
      operation.value = ''
      schedulePoll()
    }
  }
}

async function recoverParse(fileId: number): Promise<void> {
  const request = parseRequests.value[fileId]
  if (!canRead.value || !request) return
  const current = generation
  const credential = props.bearer
  const environmentId = props.environmentId
  stopPoll()
  operation.value = 'read'
  error.value = ''
  try {
    const task = request.taskId === null
      ? await fileApi.readParseByRequestKey(credential, fileId, request.key, environmentId)
      : await taskApi.read(credential, request.taskId)
    if (current !== generation) return
    assertTask(task, fileId, request.taskId)
    parseTasks.value = { ...parseTasks.value, [fileId]: task }
    retainRequest({ ...request, taskId: task.id })
    const file = await fileApi.read(credential, fileId, environmentId)
    if (current !== generation) return
    upsert(file, fileId)
    resolveRequest(fileId, task)
  } catch (failure) {
    if (current === generation) fail(failure, '原解析暂不可读。原请求号保留，请核对身份后继续只读找回。')
  } finally {
    if (current === generation) {
      operation.value = ''
      schedulePoll()
    }
  }
}

async function restartCanceledParse(file: SandAiFile): Promise<void> {
  const original = originalTask(file)
  if (!canWrite.value || original?.state !== 'canceled') return
  const pending = parseRequests.value[file.id]
  if (pending && pending.taskId !== original.id) {
    error.value = '本文件仍有尚未确认的原解析请求，请先只读找回，不创建新解析。'
    return
  }
  const current = generation
  const credential = props.bearer
  const environmentId = props.environmentId
  stopPoll()
  operation.value = 'read'
  error.value = ''
  try {
    const task = await taskApi.read(credential, original.id)
    if (current !== generation) return
    assertTask(task, file.id, original.id)
    parseTasks.value = { ...parseTasks.value, [file.id]: task }
    const proof = task.parse_restart
    if (task.state !== 'canceled' || proof?.allowed !== true
      || proof.task_id !== task.id || proof.file_id !== file.id) {
      error.value = '原任务没有完整的未派发 OCR 证明，请查看原任务核对，不创建新解析。'
      return
    }
    const latest = await fileApi.read(credential, file.id, environmentId)
    if (current !== generation) return
    upsert(latest, file.id)
    if (latest.state !== 'parse_canceled' || latest.latest_parse?.task?.id !== task.id) {
      error.value = '文件或原解析状态已变化，请刷新后核对。'
      return
    }
    try {
      await ElMessageBox.confirm('已核实原解析未派发 OCR。重新解析将创建新任务，使用当前配置并单独计量。', '重新解析原文件', {
        confirmButtonText: '创建新解析任务', cancelButtonText: '保留原任务', type: 'warning'
      })
    } catch {
      return
    }
    if (current !== generation || props.disabled || recoveryStorageError.value) return
    await submitParse(file.id)
  } catch (failure) {
    if (current === generation) fail(failure, '原取消任务暂不可核实，请保留原任务，不创建新解析。')
  } finally {
    if (current === generation) {
      operation.value = ''
      schedulePoll()
    }
  }
}

async function refreshFiles(onlyActive = false): Promise<void> {
  if (!canRead.value || !files.value.length) return
  const current = generation
  const ids = files.value.filter(file => !onlyActive || file.state === 'parsing').map(file => file.id)
  stopPoll()
  operation.value = 'refresh'
  error.value = ''
  try {
    for (let offset = 0; offset < ids.length; offset += 10) {
      const batchIds = ids.slice(offset, offset + 10)
      const rows = await Promise.all(batchIds.map(id => fileApi.read(props.bearer, id, props.environmentId)))
      if (current !== generation) return
      rows.forEach((file, index) => {
        upsert(file, batchIds[index])
        const task = file.latest_parse?.task
        if (task) {
          assertTask(task, file.id, parseRequests.value[file.id]?.taskId ?? null)
          parseTasks.value = { ...parseTasks.value, [file.id]: task }
          resolveRequest(file.id, task)
        }
      })
    }
  } catch (failure) {
    if (current === generation) fail(failure, '读取解析进度失败，请核对当前应用身份后手动刷新。')
  } finally {
    if (current === generation) {
      operation.value = ''
      if (!error.value) schedulePoll()
    }
  }
}

function useFile(file: SandAiFile): void {
  if (ready(file) && canRead.value && !props.disabled && !parseRequests.value[file.id]) emit('use-file', file.id)
}

function stopPoll(): void {
  if (timer !== null) clearTimeout(timer)
  timer = null
}

function schedulePoll(): void {
  stopPoll()
  if (document.visibilityState === 'hidden' || !props.bearer.trim() || error.value
    || !files.value.some(file => file.state === 'parsing')) return
  timer = setTimeout(() => { void refreshFiles(true) }, 4000)
}

function visibilityChanged(): void {
  if (document.visibilityState === 'hidden') stopPoll()
  else schedulePoll()
}

watch(() => [props.bearer, props.environmentId, userStore.info.id, userStore.accessToken], () => {
  generation++
  stopPoll()
  files.value = []
  parseRequests.value = {}
  parseTasks.value = {}
  lookupId.value = undefined
  operation.value = ''
  error.value = ''
  restoreRequests()
}, { flush: 'sync', immediate: true })
onMounted(() => document.addEventListener('visibilitychange', visibilityChanged))
onUnmounted(() => {
  generation++
  stopPoll()
  document.removeEventListener('visibilitychange', visibilityChanged)
})
</script>

<style scoped>
.sample-files { margin-bottom: 16px; }
.muted { color: var(--el-text-color-secondary); }
.notice, .file-table { margin-top: 12px; }
</style>
