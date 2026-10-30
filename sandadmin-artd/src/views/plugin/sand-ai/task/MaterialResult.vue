<template>
  <div v-if="result" class="material-result">
    <ElAlert title="AI 整理结果，需由业务人员核对来源后确认" type="info" :closable="false" />
    <section>
      <h4>文档（{{ result.documents.length }}）</h4>
      <p v-if="!result.documents.length" class="muted">没有可确认的文档。</p>
      <div v-for="(document, index) in result.documents" :key="`${document.file_id}:${index}`" class="entry">
        <p class="entry-title">文件 #{{ document.file_id }}</p>
        <p>标题：{{ document.title.value ?? '待核实' }} <ElTag size="small" :type="evidenceType(document.title.evidence_state)">{{ evidenceLabel(document.title.evidence_state) }}</ElTag></p>
        <p>分类：{{ document.classification.value ?? '待核实' }} <ElTag size="small" :type="evidenceType(document.classification.evidence_state)">{{ evidenceLabel(document.classification.evidence_state) }}</ElTag></p>
        <p class="source-links">标题来源：
          <span v-if="!document.title.source_block_ids.length" class="muted">无可引用来源</span>
          <ElButton v-for="id in document.title.source_block_ids" :key="`title:${id}`" link type="primary" @click="openSource(id)">块 #{{ id }}</ElButton>
          · 分类来源：
          <span v-if="!document.classification.source_block_ids.length" class="muted">无可引用来源</span>
          <ElButton v-for="id in document.classification.source_block_ids" :key="`classification:${id}`" link type="primary" @click="openSource(id)">块 #{{ id }}</ElButton>
        </p>
      </div>
    </section>
    <section>
      <h4>实体（{{ result.entities.length }}）</h4>
      <p v-if="!result.entities.length" class="muted">没有可确认的实体。</p>
      <div v-for="(entity, index) in result.entities" :key="index" class="entry">
        <p>{{ entity.type }}：{{ entity.value ?? '待核实' }} <ElTag size="small" :type="evidenceType(entity.evidence_state)">{{ evidenceLabel(entity.evidence_state) }}</ElTag></p>
        <p class="source-links">
          <span v-if="!entity.source_block_ids.length" class="muted">无可引用来源</span>
          <ElButton v-for="id in entity.source_block_ids" :key="id" link type="primary" @click="openSource(id)">查看来源块 #{{ id }}</ElButton>
        </p>
      </div>
    </section>
    <section>
      <h4>事件时间线（{{ result.events.length }}）</h4>
      <p v-if="!result.events.length" class="muted">没有可确认的事件。</p>
      <div v-for="{ event, index } in datedEvents" :key="index" class="entry">
        <p>{{ event.occurred_on }} · {{ event.summary ?? '内容待核实' }} <ElTag size="small" :type="evidenceType(event.evidence_state)">{{ evidenceLabel(event.evidence_state) }}</ElTag></p>
        <p class="source-links">
          <span v-if="!event.source_block_ids.length" class="muted">无可引用来源</span>
          <ElButton v-for="id in event.source_block_ids" :key="id" link type="primary" @click="openSource(id)">查看来源块 #{{ id }}</ElButton>
        </p>
      </div>
    </section>
    <section v-if="undatedEvents.length">
      <h4>日期待核实（{{ undatedEvents.length }}）</h4>
      <div v-for="{ event, index } in undatedEvents" :key="index" class="entry">
        <p>{{ event.occurred_on ?? '日期待核实' }} · {{ event.summary ?? '内容待核实' }} <ElTag size="small" :type="evidenceType(event.evidence_state)">{{ evidenceLabel(event.evidence_state) }}</ElTag></p>
        <p class="source-links">
          <span v-if="!event.source_block_ids.length" class="muted">无可引用来源</span>
          <ElButton v-for="id in event.source_block_ids" :key="id" link type="primary" @click="openSource(id)">查看来源块 #{{ id }}</ElButton>
        </p>
      </div>
    </section>
    <section v-if="result.conflicts.length || result.items_to_verify.length">
      <h4>冲突与待核实</h4>
      <div v-for="(conflict, index) in result.conflicts" :key="`conflict:${index}`" class="entry">
        <p><ElTag size="small" type="warning">{{ conflict.field_location?.state === 'verified' ? '来源差异 · 待核实' : '字段定位待核实' }}</ElTag>
          {{ conflict.field_location?.state === 'verified' ? conflict.field_location.references[0]?.label : '原字段尚未确认' }} · {{ conflict.summary }}</p>
        <template v-if="conflict.field_location?.state === 'verified'">
          <div v-for="reference in conflict.field_location.references" :key="reference.file_id" class="entry">
            <p>文件 #{{ reference.file_id }} · {{ reference.label }}：{{ reference.value }}</p>
            <p class="source-links">
              <ElButton v-for="id in reference.source_block_ids" :key="id" link type="primary" @click="openSource(id)">查看该文件来源块 #{{ id }}</ElButton>
            </p>
          </div>
        </template>
        <p v-else class="source-links">
          <span v-if="!conflict.source_block_ids.length" class="muted">无可引用来源</span>
          <ElButton v-for="id in conflict.source_block_ids" :key="id" link type="primary" @click="openSource(id)">查看来源块 #{{ id }}</ElButton>
        </p>
      </div>
      <div v-for="(item, index) in result.items_to_verify" :key="`verify:${index}`" class="entry">
        <p><ElTag size="small" type="warning">待核实 · {{ reasonLabel(item.reason) }}</ElTag> {{ item.summary ?? '缺少来源，未保留候选结论' }}</p>
        <p class="source-links">
          <span v-if="!item.source_block_ids.length" class="muted">无可引用来源</span>
          <ElButton v-for="id in item.source_block_ids" :key="id" link type="primary" @click="openSource(id)">查看来源块 #{{ id }}</ElButton>
        </p>
      </div>
    </section>
    <section v-if="result.warnings.length">
      <h4>提示</h4>
      <div v-for="(warning, index) in result.warnings" :key="index" class="entry">
        <p>{{ warning }}</p>
        <p v-if="warningSources(index).length" class="source-links">
          <ElButton v-for="source in warningSources(index)" :key="source.source_block_id"
            link type="primary" @click="openSource(source.source_block_id)">
            查看文件 #{{ source.file_id }} 来源块 #{{ source.source_block_id }}
          </ElButton>
        </p>
      </div>
    </section>
    <section v-for="[field, values] in Object.entries(result.extensions)" :key="field">
      <h4>扩展字段 · {{ field }}（{{ values.length }}）</h4>
      <div v-for="(value, index) in values" :key="index" class="entry">
        <p>{{ value.value ?? '待核实' }} <ElTag size="small" :type="evidenceType(value.evidence_state)">{{ evidenceLabel(value.evidence_state) }}</ElTag></p>
        <p class="source-links">
          <span v-if="!value.source_block_ids.length" class="muted">无可引用来源</span>
          <ElButton v-for="id in value.source_block_ids" :key="id" link type="primary" @click="openSource(id)">查看来源块 #{{ id }}</ElButton>
        </p>
      </div>
    </section>
    <ElCollapse>
      <ElCollapseItem title="查看完整结构化数据" name="raw">
        <pre>{{ JSON.stringify(payload, null, 2) }}</pre>
      </ElCollapseItem>
    </ElCollapse>
  </div>
  <ElAlert v-else title="成果格式无法识别，请核对完整数据" type="warning" :closable="false">
    <pre>{{ JSON.stringify(payload, null, 2) }}</pre>
  </ElAlert>
</template>

<script setup lang="ts">
import { computed } from 'vue'

type Candidate = {
  value: string | null
  evidence_state: string
  source_block_ids: number[]
}
type Document = { file_id: number; title: Candidate; classification: Candidate }
type Entity = Candidate & { type: string }
type Event = { summary: string | null; occurred_on: string | null; evidence_state: string; source_block_ids: number[] }
type FieldReference = { file_id: number; field_path: string; label: string; value: string; source_block_ids: number[] }
type FieldLocation = { state: 'verified'; scope: 'file_result'; references: FieldReference[] }
  | { state: 'unconfirmed'; scope: null; references: [] }
type Conflict = { field: string; summary: string; source_block_ids: number[]; field_location: FieldLocation | null }
type ItemToVerify = { reason: string; summary: string | null; source_block_ids: number[] }
type MaterialPayload = {
  documents: Document[]
  entities: Entity[]
  events: Event[]
  conflicts: Conflict[]
  items_to_verify: ItemToVerify[]
  warnings: string[]
  extensions: Record<string, Candidate[]>
}

type WarningSource = { field_path: string; file_id: number; source_block_id: number }
const props = defineProps<{ payload: unknown; citations?: readonly WarningSource[] }>()
const emit = defineEmits<{ (event: 'open-source', sourceBlockId: number): void }>()
const result = computed(() => asMaterialPayload(props.payload))
const indexedEvents = computed(() => (result.value?.events ?? []).map((event, index) => ({ event, index })))
const datedEvents = computed(() => indexedEvents.value
  .filter(({ event }) => isTimelineDate(event.occurred_on))
  .sort((left, right) => {
    const leftDate = left.event.occurred_on ?? ''
    const rightDate = right.event.occurred_on ?? ''
    return leftDate < rightDate ? -1 : leftDate > rightDate ? 1 : left.index - right.index
  }))
const undatedEvents = computed(() => indexedEvents.value
  .filter(({ event }) => !isTimelineDate(event.occurred_on)))

function isTimelineDate(value: string | null): boolean {
  if (value === null || !/^\d{4}-\d{2}-\d{2}$/.test(value)) return false
  const date = new Date(`${value}T00:00:00Z`)
  return Number.isFinite(date.getTime()) && date.toISOString().slice(0, 10) === value
}

function record(value: unknown): Record<string, unknown> | null {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
    ? value as Record<string, unknown> : null
}

function sourceIds(value: unknown): number[] | null {
  return Array.isArray(value) && value.every(id => Number.isInteger(id) && id > 0) ? value as number[] : null
}

function fieldReference(value: unknown): FieldReference | null {
  const item = record(value)
  const fileId = item?.file_id
  const ids = sourceIds(item?.source_block_ids)
  if (!item || typeof fileId !== 'number' || !Number.isInteger(fileId) || fileId < 1
    || typeof item.field_path !== 'string' || !item.field_path.startsWith('/')
    || typeof item.label !== 'string' || !item.label.trim()
    || typeof item.value !== 'string' || !item.value.trim()
    || !ids?.length || new Set(ids).size !== ids.length) return null
  return { file_id: fileId, field_path: item.field_path, label: item.label,
    value: item.value, source_block_ids: ids }
}

function fieldLocation(value: unknown, citedIds: number[]): FieldLocation | null {
  const item = record(value)
  if (!item || item.version !== 'file_result_fields@1') return null
  if (item.state === 'unconfirmed' && item.scope === null
    && Array.isArray(item.references) && item.references.length === 0) {
    return { state: 'unconfirmed', scope: null, references: [] }
  }
  if (item.state !== 'verified' || item.scope !== 'file_result') return null
  const references = parseList(item.references, fieldReference)
  if (!references || references.length < 2
    || new Set(references.map(reference => reference.file_id)).size !== references.length
    || new Set(references.map(reference => reference.label)).size !== 1
    || new Set(references.map(reference => reference.value)).size < 2) return null
  const sourceUnion = references.flatMap(reference => reference.source_block_ids)
  if (new Set(sourceUnion).size !== sourceUnion.length
    || sourceUnion.length !== new Set(citedIds).size
    || sourceUnion.some(id => !citedIds.includes(id))) return null
  return { state: 'verified', scope: 'file_result', references }
}

function candidate(value: unknown): Candidate | null {
  const item = record(value)
  const ids = sourceIds(item?.source_block_ids)
  if (!item || (typeof item.value !== 'string' && item.value !== null)
    || typeof item.evidence_state !== 'string' || ids === null) return null
  return { value: item.value, evidence_state: item.evidence_state, source_block_ids: ids }
}

function parseList<T>(value: unknown, parse: (item: unknown) => T | null): T[] | null {
  if (!Array.isArray(value)) return null
  const items = value.map(parse)
  return items.every((item): item is T => item !== null) ? items : null
}

function asMaterialPayload(value: unknown): MaterialPayload | null {
  const data = record(value)
  if (!data) return null
  const documents = parseList(data.documents, value => {
    const item = record(value)
    const title = candidate(item?.title)
    const classification = candidate(item?.classification)
    return item && Number.isInteger(item.file_id) && Number(item.file_id) > 0 && title && classification
      ? { file_id: Number(item.file_id), title, classification } : null
  })
  const entities = parseList(data.entities, value => {
    const item = record(value)
    const detail = candidate(item)
    return item && typeof item.type === 'string' && detail ? { type: item.type, ...detail } : null
  })
  const events = parseList(data.events, value => {
    const item = record(value)
    const ids = sourceIds(item?.source_block_ids)
    return item && (typeof item.summary === 'string' || item.summary === null)
      && (typeof item.occurred_on === 'string' || item.occurred_on === null)
      && typeof item.evidence_state === 'string' && ids
      ? { summary: item.summary, occurred_on: item.occurred_on, evidence_state: item.evidence_state, source_block_ids: ids } as Event : null
  })
  const conflicts = parseList(data.conflicts, value => {
    const item = record(value)
    const ids = sourceIds(item?.source_block_ids)
    return item && typeof item.field === 'string' && typeof item.summary === 'string' && ids
      ? { field: item.field, summary: item.summary, source_block_ids: ids,
        field_location: fieldLocation(item.field_location, ids) } : null
  })
  const itemsToVerify = parseList(data.items_to_verify, value => {
    const item = record(value)
    const ids = sourceIds(item?.source_block_ids)
    return item && typeof item.reason === 'string' && (typeof item.summary === 'string' || item.summary === null) && ids
      ? { reason: item.reason, summary: item.summary, source_block_ids: ids } : null
  })
  const warnings = parseList(data.warnings, value => typeof value === 'string' ? value : null)
  const rawExtensions = record(data.extensions)
  const extensions: Record<string, Candidate[]> = {}
  if (!rawExtensions) return null
  for (const [field, values] of Object.entries(rawExtensions)) {
    const parsed = parseList(values, candidate)
    if (parsed === null) return null
    extensions[field] = parsed
  }
  return documents && entities && events && conflicts && itemsToVerify && warnings
    ? { documents, entities, events, conflicts, items_to_verify: itemsToVerify, warnings, extensions }
    : null
}

function openSource(sourceBlockId: number): void {
  emit('open-source', sourceBlockId)
}

function warningSources(index: number): WarningSource[] {
  const material = result.value
  if (!material || !material.warnings[index]?.startsWith('重复材料（完整文件字节相同）：')) return []
  // Text from a model never creates a clickable relationship. Both links must
  // come from this warning's persisted, authorized source references.
  const sources = (props.citations ?? []).filter(source => source.field_path === `/warnings/${index}`)
  if (sources.length !== 2
    || sources.some(source => !Number.isSafeInteger(source.file_id) || source.file_id < 1
      || !Number.isSafeInteger(source.source_block_id) || source.source_block_id < 1
      || !material.documents.some(document => document.file_id === source.file_id))
    || sources[0].file_id === sources[1].file_id
    || sources[0].source_block_id === sources[1].source_block_id) return []
  return sources
}

function evidenceLabel(state: string): string {
  return { confirmed: '双来源支持', uncertain: '待复核', missing_source: '缺少来源', conflicted: '存在冲突' }[state] ?? state
}

function evidenceType(state: string): 'success' | 'warning' | 'danger' {
  return state === 'confirmed' ? 'success' : state === 'conflicted' ? 'danger' : 'warning'
}

function reasonLabel(reason: string): string {
  return { missing_source: '缺少来源', single_source: '单一来源', conflict: '来源冲突', ambiguous: '表述不明确' }[reason] ?? reason
}
</script>

<style scoped>
.material-result { display: grid; gap: 16px; }
.material-result section { display: grid; gap: 8px; }
.material-result h4 { margin: 0; }
.material-result p { margin: 0; }
.entry { padding: 10px 12px; border: 1px solid var(--el-border-color-lighter); border-radius: var(--el-border-radius-base); }
.entry p + p { margin-top: 6px; }
.entry-title { font-weight: 600; }
.source-links { display: flex; flex-wrap: wrap; align-items: center; gap: 4px; }
.muted { color: var(--el-text-color-secondary); }
pre { white-space: pre-wrap; overflow-wrap: anywhere; }
</style>
