<template>
  <section class="material-pages" aria-label="文件成果分页">
    <ElAlert type="info" :closable="false"
      title="完整文件成果按分类分页读取，请逐页核对来源"
      description="分类总数来自封存成果；当前只展示已读取的这一页。翻页只读原成果，不新增模型调用或费用。" />
    <ElSpace wrap>
      <ElSelect :model-value="section" aria-label="成果分类" @update:model-value="chooseSection">
        <ElOption v-for="option in sections" :key="option.value" :value="option.value"
          :label="`${option.label}（${sectionCount(option.value)}）`"
          :disabled="option.value === 'extensions' && extensionFields.length === 0" />
      </ElSelect>
      <ElSelect v-if="section === 'extensions'" :model-value="extensionField" aria-label="扩展字段"
        @update:model-value="chooseExtension">
        <ElOption v-for="field in extensionFields" :key="field" :value="field"
          :label="`${field}（${fileResult.collection_counts?.extensions[field] ?? '数量未确认'}）`" />
      </ElSelect>
      <ElButton :loading="loading" :disabled="loading || !bearer.trim()" @click="loadPage(offset)">重新读取当前页</ElButton>
    </ElSpace>
    <p class="muted">{{ fileResult.result.schema.code }} · {{ fileResult.result.schema.version }}</p>
    <p v-if="loading" role="status">正在读取本页成果与当前可用来源…</p>
    <ElAlert v-else-if="error" :title="error" type="warning" :closable="false"
      description="本页未能确认，不表示该分类没有事实。可重新读取当前页，或返回原任务核对。" />
    <template v-else-if="page">
      <p>{{ selectionLabel }}共 {{ page.total }} 条；本页
        {{ page.items.length ? `${page.offset + 1}–${page.offset + page.items.length}` : '未返回条目' }}。</p>
      <p v-if="page.total === 0" class="muted">封存成果中该分类没有条目。</p>
      <p v-else-if="!page.items.length" class="muted">当前页没有条目，请返回上一页或重新读取；其他页尚不能据此判断。</p>
      <article v-for="item in displayItems" :key="item.index" class="entry">
        <p class="entry-title">{{ selectionLabel }} · 第 {{ item.index + 1 }} 条</p>
        <p v-for="(line, index) in item.lines" :key="index">{{ line }}</p>
        <p class="source-links">
          <span v-if="!item.citations.length" class="muted">本条目无可引用来源。</span>
          <ElButton v-for="citation in item.citations" :key="`${citation.field_path}:${citation.source_block_id}`"
            link type="primary" @click="emit('open-source', citation.source_block_id)">
            {{ citation.field_path }} · 文件 #{{ citation.file_id }} 来源块 #{{ citation.source_block_id }}
          </ElButton>
        </p>
      </article>
    </template>
    <ElSpace wrap>
      <ElButton :disabled="loading || offset === 0 || !bearer.trim()" @click="loadPage(Math.max(0, offset - pageSize))">上一页</ElButton>
      <ElButton :disabled="loading || !page || page.offset + page.items.length >= page.total || !bearer.trim()"
        @click="loadPage(offset + pageSize)">下一页</ElButton>
      <span class="muted">每页最多 {{ pageSize }} 条；未读取的页不表示已核对。</span>
    </ElSpace>
  </section>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import taskApi from '../api/task'
import { SandAiRuntimeError } from '../api/runtime'
import type { SandAiMaterialFileResultPage, SandAiMaterialFileResultPageQuery, SandAiMaterialResultSection, SandAiWorkflowFileResult } from '../api/types'

const props = defineProps<{
  bearer: string
  ownerId: string | number
  runId: number
  taskId: number | null
  fileResult: SandAiWorkflowFileResult
  environmentId?: number
}>()
const emit = defineEmits<{
  'open-source': [sourceBlockId: number]
  'clear-source': []
  'access-denied': [failure: unknown]
}>()
const sections: readonly { value: SandAiMaterialResultSection; label: string }[] = [
  { value: 'documents', label: '文档' }, { value: 'entities', label: '实体' },
  { value: 'events', label: '事件' }, { value: 'conflicts', label: '冲突' },
  { value: 'items_to_verify', label: '待核实项' }, { value: 'warnings', label: '提示' },
  { value: 'extensions', label: '扩展字段' }
]
const pageSize = 50
const section = ref<SandAiMaterialResultSection>('documents')
const extensionField = ref('')
const offset = ref(0)
const page = ref<SandAiMaterialFileResultPage | null>(null)
const error = ref('')
const loading = ref(false)
let requestSequence = 0
const extensionFields = computed(() => Object.keys(props.fileResult.collection_counts?.extensions ?? {}))
const selectionLabel = computed(() => section.value === 'extensions' ? `扩展字段 · ${extensionField.value}`
  : sections.find(option => option.value === section.value)?.label ?? '成果')
const displayItems = computed(() => {
  const current = page.value
  if (!current) return []
  return current.items.map((item, localIndex) => {
    const index = current.offset + localIndex
    const prefix = current.section === 'extensions'
      ? `/extensions/${escapePointer(current.extension_field ?? '')}/${index}` : `/${current.section}/${index}`
    return {
      index,
      lines: itemLines(current.section, item),
      citations: current.citations.filter(citation => citation.field_path === prefix || citation.field_path.startsWith(`${prefix}/`))
    }
  })
})

function sectionCount(value: SandAiMaterialResultSection): string | number {
  const counts = props.fileResult.collection_counts
  if (!counts) return '数量未确认'
  return value === 'extensions' ? `${extensionFields.value.length} 个字段` : counts[value]
}

function chooseSection(value: unknown): void {
  const selected = sections.find(option => option.value === value)
  if (!selected || (selected.value === 'extensions' && !extensionFields.value.length)) return
  section.value = selected.value
  extensionField.value = selected.value === 'extensions' ? extensionFields.value[0] ?? '' : ''
  emit('clear-source')
  void loadPage(0)
}

function chooseExtension(value: unknown): void {
  if (typeof value !== 'string' || !extensionFields.value.includes(value)) return
  extensionField.value = value
  emit('clear-source')
  void loadPage(0)
}

async function loadPage(nextOffset: number): Promise<void> {
  const request = ++requestSequence
  page.value = null
  error.value = ''
  loading.value = false
  offset.value = nextOffset
  const credential = props.bearer.trim()
  if (!credential) {
    error.value = '当前运行身份未提供，不能读取私有成果'
    return
  }
  if (section.value === 'extensions' && !extensionField.value) {
    error.value = '请先选择封存成果中的扩展字段'
    return
  }
  const query: SandAiMaterialFileResultPageQuery = section.value === 'extensions'
    ? { section: 'extensions', extension_field: extensionField.value, offset: nextOffset, limit: pageSize }
    : { section: section.value, offset: nextOffset, limit: pageSize }
  const schema = props.fileResult.result.schema
  const fileId = props.fileResult.file_id
  loading.value = true
  try {
    const result = await taskApi.readMaterialFileResultPage(credential, props.runId, fileId, query, props.environmentId)
    if (request !== requestSequence) return
    const field = query.section === 'extensions' ? query.extension_field : null
    if (result.file_id !== fileId || result.section !== query.section || result.extension_field !== field
      || result.offset !== query.offset || result.limit !== query.limit
      || result.schema.code !== schema.code || result.schema.version !== schema.version
      || !Number.isSafeInteger(result.total) || result.total < 0
      || !Array.isArray(result.items)
      || result.items.length !== Math.min(result.limit, Math.max(0, result.total - result.offset))
      || !Array.isArray(result.citations)) {
      throw new Error('返回的成果页与当前文件或分类不一致，请重新读取原成果')
    }
    page.value = result
  } catch (failure) {
    if (request !== requestSequence) return
    error.value = failure instanceof Error ? failure.message : '本页成果暂不可读'
    if (failure instanceof SandAiRuntimeError
      && ([401, 403].includes(failure.httpStatus)
        || failure.errorCode === 'SAND_AI_CONTENT_PURGED'
        || failure.errorCode === 'SAND_AI_ENVIRONMENT_MISMATCH')) emit('access-denied', failure)
  } finally {
    if (request === requestSequence) loading.value = false
  }
}

function record(value: unknown): Record<string, unknown> | null {
  return typeof value === 'object' && value !== null && !Array.isArray(value) ? value as Record<string, unknown> : null
}

function text(value: unknown, fallback = '待核实'): string {
  return typeof value === 'string' ? value : fallback
}

function evidence(value: unknown): string {
  const state = text(value, '')
  return ({ confirmed: '双来源支持', uncertain: '待复核', missing_source: '缺少来源', conflicted: '存在冲突' } as Record<string, string>)[state] ?? state
}

function candidateLine(label: string, value: unknown): string {
  const candidate = record(value)
  return `${label}：${text(candidate?.value)}${candidate?.evidence_state ? ` · ${evidence(candidate.evidence_state)}` : ''}`
}

function itemLines(value: SandAiMaterialResultSection, item: unknown): string[] {
  if (value === 'warnings') return [text(item, '提示内容格式暂不可识别')]
  const detail = record(item)
  if (!detail) return ['该条目的展示格式暂不可识别，请重新读取原成果']
  if (value === 'documents') return [candidateLine('标题', detail.title), candidateLine('分类', detail.classification)]
  if (value === 'entities') return [`${text(detail.type, '实体')}：${text(detail.value)} · ${evidence(detail.evidence_state)}`]
  if (value === 'events') return [`${text(detail.occurred_on, '日期待核实')} · ${text(detail.summary, '内容待核实')} · ${evidence(detail.evidence_state)}`]
  if (value === 'conflicts') return [`待核实字段：${text(detail.field)}`, text(detail.summary)]
  if (value === 'items_to_verify') {
    const reason = text(detail.reason)
    const label = ({ missing_source: '缺少来源', single_source: '单一来源', conflict: '来源冲突', ambiguous: '表述不明确' } as Record<string, string>)[reason] ?? reason
    return [`待核实 · ${label}`, text(detail.summary, '缺少来源，未保留候选结论')]
  }
  return [candidateLine('内容', detail)]
}

function escapePointer(value: string): string {
  return value.replace(/~/g, '~0').replace(/\//g, '~1')
}

watch(() => [props.bearer, props.ownerId, props.runId, props.taskId, props.environmentId,
  props.fileResult.file_id, props.fileResult.attempt_count], () => {
  ++requestSequence
  page.value = null
  error.value = ''
  loading.value = false
  offset.value = 0
  emit('clear-source')
  section.value = sections.find(option => {
    if (option.value === 'extensions') return false
    const count = props.fileResult.collection_counts?.[option.value]
    return typeof count === 'number' && count > 0
  })?.value ?? 'documents'
  extensionField.value = ''
  void loadPage(0)
}, { immediate: true, flush: 'sync' })

onBeforeUnmount(() => {
  ++requestSequence
  page.value = null
  emit('clear-source')
})
</script>

<style scoped>
.material-pages { display: grid; gap: 16px; }
.material-pages p { margin: 0; }
.entry { padding: 10px 12px; border: 1px solid var(--el-border-color-lighter); border-radius: var(--el-border-radius-base); }
.entry p + p { margin-top: 6px; }
.entry-title { font-weight: 600; }
.source-links { display: flex; flex-wrap: wrap; align-items: center; gap: 4px; }
.muted { color: var(--el-text-color-secondary); }
</style>
