<template>
  <section class="parse-sources" aria-label="文件解析来源">
    <h3>文件解析来源</h3>
    <p>文件 #{{ fileId }} · 解析 #{{ parseId ?? '未提供' }} · 原任务 #{{ taskId }}</p>
    <p class="muted">以下是解析原文的来源摘录，不是 AI 整理成果；每块最多显示 500 个字符。</p>
    <ElAlert v-if="!sourceAvailable" type="warning" :closable="false" title="当前任务的来源内容不可读取" />
    <ElAlert v-else-if="parseId === null" type="warning" :closable="false" title="原任务未提供解析编号，请先读取原任务确认" />
    <ElButton :loading="loading" :disabled="!canRead || loading" @click="readPage()">读取解析来源</ElButton>
    <ElAlert v-if="error" type="error" :closable="false" :title="error.title" :description="error.description" />
    <template v-if="snapshot">
      <p class="muted">已读取 {{ blocks.length }} 个来源块{{ nextAfterId === null ? '，当前解析无下一页' : '，仍有下一页' }}。摘录不代表已查看完整原文。</p>
      <p v-if="blocks.length === 0" class="muted">当前解析没有可读取的来源块。</p>
      <article v-for="block in blocks" :key="block.source_block_id" class="source-block">
        <div class="source-block__header">
          <strong>来源块 #{{ block.source_block_id }}</strong>
          <span>{{ sourceLocation(block) }}</span>
          <ElButton link type="primary" @click="selected = block">查看摘录与定位</ElButton>
        </div>
        <p class="source-excerpt">{{ block.preview }}</p>
      </article>
      <ElButton v-if="nextAfterId !== null" :loading="loading" :disabled="loading || !canRead" @click="readPage(nextAfterId)">读取下一页来源</ElButton>
    </template>
    <ElDialog v-model="sourceOpen" title="解析来源摘录" width="min(720px, 94vw)" destroy-on-close>
      <template v-if="selected && snapshot && sourceContent">
        <p>文件 #{{ snapshot.id }} · 解析 #{{ snapshot.parse_id }} · 来源块 #{{ selected.source_block_id }}</p>
        <p>{{ sourceLocation(selected) }}</p>
        <p class="muted">来源摘录（最多 500 个字符），不是完整原文或已确认的 AI 成果。</p>
        <MaterialSourceContent :source="sourceContent" />
        <p class="source-fact">原始定位：{{ selected.locator_type }} {{ JSON.stringify(selected.locator) }}</p>
        <p class="source-fact">来源块 SHA256：{{ selected.content_sha256 }}</p>
        <p class="source-fact">文件 SHA256：{{ snapshot.sha256 }}</p>
      </template>
    </ElDialog>
  </section>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import fileApi, { type FileSourceBlock, type FileSourceBlocksPage } from '../api/file'
import { toRuntimeUiError, type RuntimeUiError } from '../api/runtimeErrors'
import MaterialSourceContent from './MaterialSourceContent.vue'

const props = defineProps<{
  bearer: string
  ownerId: number | undefined
  taskId: number
  fileId: number
  parseId: number | null
  sourceAvailable: boolean
}>()
const emit = defineEmits<{ (event: 'access-denied', error: unknown): void }>()
const blocks = ref<FileSourceBlock[]>([])
const snapshot = ref<FileSourceBlocksPage['file'] | null>(null)
const nextAfterId = ref<number | null>(null)
const selected = ref<FileSourceBlock | null>(null)
const loading = ref(false)
const error = ref<RuntimeUiError | null>(null)
let generation = 0

const canRead = computed(() => Boolean(props.ownerId) && Boolean(props.bearer.trim()) && props.sourceAvailable
  && Number.isSafeInteger(props.fileId) && props.fileId > 0
  && props.parseId !== null && Number.isSafeInteger(props.parseId) && props.parseId > 0)
const sourceOpen = computed({
  get: () => selected.value !== null,
  set: (open: boolean) => { if (!open) selected.value = null }
})
const sourceContent = computed(() => selected.value === null ? null : {
  content: selected.value.preview,
  locator_type: selected.value.locator_type,
  locator: selected.value.locator
})

function clear(): void {
  ++generation
  blocks.value = []
  snapshot.value = null
  nextAfterId.value = null
  selected.value = null
  loading.value = false
  error.value = null
}

function sourceLocation(block: FileSourceBlock): string {
  const locator = block.locator
  switch (block.locator_type) {
    case 'line': return `第 ${locator.line} 行`
    case 'page': return `第 ${locator.page} 页`
    case 'page_region': return `第 ${locator.page} 页 · 页内区域`
    case 'paragraph': return `第 ${locator.paragraph} 段`
    case 'table_row': return `${locator.table === undefined ? '表格' : `表格 ${locator.table}`} · 第 ${locator.row} 行`
    case 'audio_time_range': return `${locator.start_ms}–${locator.end_ms} 毫秒`
    default: return block.locator_type
  }
}

async function readPage(afterId?: number): Promise<void> {
  if (loading.value || !canRead.value || props.parseId === null) return
  if (afterId === undefined) clear()
  else if (snapshot.value === null || afterId !== nextAfterId.value) return
  const requestGeneration = ++generation
  const fileId = props.fileId
  const parseId = props.parseId
  const bearer = props.bearer
  const previous = snapshot.value
  loading.value = true
  error.value = null
  selected.value = null
  try {
    const page = await fileApi.readSourceBlocks(bearer, fileId, parseId, afterId)
    if (requestGeneration !== generation) return
    if (page.file.id !== fileId || page.file.parse_id !== parseId
      || (previous !== null && page.file.sha256 !== previous.sha256)
      || (afterId !== undefined && page.blocks.some(block => block.source_block_id <= afterId))) {
      throw new Error('来源与原文件或解析快照不一致，请读取原任务确认后重新读取来源')
    }
    snapshot.value = page.file
    blocks.value = afterId === undefined ? page.blocks : [...blocks.value, ...page.blocks]
    nextAfterId.value = page.next_after_id
  } catch (cause: unknown) {
    if (requestGeneration !== generation) return
    clear()
    error.value = toRuntimeUiError(cause)
    emit('access-denied', cause)
  } finally {
    if (requestGeneration === generation) loading.value = false
  }
}

watch([() => props.bearer, () => props.ownerId, () => props.taskId, () => props.fileId,
  () => props.parseId, () => props.sourceAvailable], clear, { flush: 'sync' })
onBeforeUnmount(clear)
</script>

<style scoped>
.parse-sources { display: grid; gap: 12px; min-width: 0; margin-top: 16px; }
.parse-sources h3, .parse-sources p { margin: 0; }
.parse-sources > .el-button { justify-self: start; }
.muted { color: var(--el-text-color-secondary); font-size: 13px; }
.source-block { display: grid; gap: 8px; padding: 12px; border: 1px solid var(--el-border-color); border-radius: 4px; }
.source-block__header { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 12px; }
.source-excerpt { white-space: pre-wrap; overflow-wrap: anywhere; }
.source-fact { margin-top: 12px; overflow-wrap: anywhere; }
</style>
