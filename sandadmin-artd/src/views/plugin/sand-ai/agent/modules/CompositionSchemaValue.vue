<template>
  <div>
    <p v-if="label">{{ label }}</p>
    <p v-if="description">{{ description }}</p>
    <ElCheckbox v-if="nullable && !enumValues" :model-value="draft === 'null'" :disabled="disabled" @change="toggleNull">
      使用空值（null）
    </ElCheckbox>
    <template v-if="draft !== 'null' || !nullable || enumValues">
      <template v-if="rawMode || kind === 'json'">
        <p>此字段使用原 JSON 输入，请按下方实际字段定义填写；现有值完整保留。</p>
        <ElInput :model-value="draft" type="textarea" :disabled="disabled" :aria-label="label || '原 JSON 值'" @update:model-value="updateRaw" />
        <ElButton v-if="kind !== 'json' && rawMode" :disabled="disabled || !canUseForm" @click="useForm">按当前值使用表单</ElButton>
      </template>
      <ElSelect v-else-if="enumValues" :model-value="enumSelection" :disabled="disabled" placeholder="请选择原定义中的值" :aria-label="label || '选项值'" @update:model-value="updateEnum">
        <ElOption v-for="option in enumValues" :key="option.value" :value="option.value" :label="option.label" />
      </ElSelect>
      <template v-else-if="kind === 'string'">
        <ElInput :model-value="stringValue" :disabled="disabled" :aria-label="label || '文字'" @update:model-value="updateString" />
        <p v-if="schema.format === 'date'">按原字段定义填写日期（YYYY-MM-DD）。</p>
        <p v-else-if="schema.format === 'date-time'">按原字段定义填写日期时间，保留原时区；不会自动补时区或转换时间。</p>
      </template>
      <ElSelect v-else-if="kind === 'boolean'" :model-value="booleanSelection" :disabled="disabled" placeholder="请选择是或否" :aria-label="label || '是否'" @update:model-value="updateBoolean">
        <ElOption label="是（true）" value="true" />
        <ElOption label="否（false）" value="false" />
      </ElSelect>
      <template v-else-if="kind === 'number' || kind === 'integer'">
        <ElInput :model-value="draft" :disabled="disabled" :aria-label="label || (kind === 'integer' ? '整数' : '数字')" :placeholder="kind === 'integer' ? '填写整数' : '填写数字'" @update:model-value="updateRaw" />
        <p v-if="numberIncomplete">数字尚未填写完整，请完成后再提交。</p>
      </template>
      <template v-else-if="kind === 'object'">
        <section v-for="field in objectFields" :key="field.name">
          <p>{{ field.label }}{{ field.required ? '（必填）' : '' }}</p>
          <ElCheckbox v-if="!field.required" :model-value="field.enabled" :disabled="disabled" @change="value => toggleField(field.name, value)">填写此字段</ElCheckbox>
          <CompositionSchemaValue v-if="field.enabled" :schema="field.schema" :model-value="field.draft" :disabled="disabled" @update:model-value="value => updateField(field.name, value)" />
        </section>
        <section v-for="field in extraFields" :key="field.name">
          <p>原已保存字段：{{ field.name }}（当前定义未声明，保留原 JSON 值）</p>
          <ElInput :model-value="field.draft" type="textarea" :disabled="disabled" :aria-label="`原已保存字段 ${field.name}`" @update:model-value="value => updateExtra(field.name, value)" />
        </section>
      </template>
      <template v-else-if="kind === 'array'">
        <p v-if="minItems !== null">至少 {{ minItems }} 项。</p>
        <p v-if="maxItems !== null">最多 {{ maxItems }} 项。</p>
        <section v-for="(item, index) in arrayItems" :key="item.key">
          <CompositionSchemaValue :schema="itemSchema" :model-value="item.draft" :disabled="disabled" :label="`第 ${index + 1} 项`" @update:model-value="value => updateItem(item.key, value)" />
          <ElButton :disabled="disabled || (minItems !== null && arrayItems.length <= minItems)" @click="removeItem(item.key)">移除此项</ElButton>
        </section>
        <ElButton :disabled="disabled || (maxItems !== null && arrayItems.length >= maxItems)" @click="addItem">增加一项</ElButton>
      </template>
    </template>
    <details>
      <summary>查看实际字段定义与限制</summary>
      <pre>{{ JSON.stringify(schema, null, 2) }}</pre>
    </details>
  </div>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'

type Parsed = { ok: true; value: unknown } | { ok: false }
interface ObjectField { name: string; label: string; schema: Record<string, unknown>; required: boolean; enabled: boolean; draft: string }
interface ExtraField { name: string; draft: string }
interface ArrayItem { key: number; draft: string }
const props = defineProps<{ schema: Record<string, unknown>; modelValue: string; disabled: boolean; label?: string }>()
const emit = defineEmits<{ (event: 'update:modelValue', value: string): void }>()
const draft = ref(props.modelValue)
const rawMode = ref(false)
const objectFields = ref<ObjectField[]>([])
const extraFields = ref<ExtraField[]>([])
const arrayItems = ref<ArrayItem[]>([])
let nextItemKey = 0
let previousNonNull = ''
let lastEmitted: string | undefined

function record(value: unknown): Record<string, unknown> | null {
  return typeof value === 'object' && value !== null && !Array.isArray(value) ? value as Record<string, unknown> : null
}
function parse(text: string): Parsed {
  try { const value: unknown = JSON.parse(text); return { ok: true, value } } catch { return { ok: false } }
}
function encode(value: unknown): string {
  return JSON.stringify(value) ?? ''
}
function hasOwn(value: Record<string, unknown>, key: string): boolean {
  return Object.prototype.hasOwnProperty.call(value, key)
}
const typeNames = computed(() => typeof props.schema.type === 'string'
  ? [props.schema.type]
  : Array.isArray(props.schema.type) && props.schema.type.every(value => typeof value === 'string')
    ? props.schema.type as string[] : [])
const nullable = computed(() => typeNames.value.includes('null') || props.schema.nullable === true
  || (Array.isArray(props.schema.enum) && props.schema.enum.includes(null)))
const enumValues = computed(() => Array.isArray(props.schema.enum)
  ? props.schema.enum.map(value => ({ value: encode(value), label: typeof value === 'string' ? value : encode(value) })) : null)
const description = computed(() => typeof props.schema.description === 'string' ? props.schema.description : '')
const kind = computed(() => {
  if (['anyOf', 'oneOf', 'allOf', '$ref', 'not', 'if', 'then', 'else'].some(key => hasOwn(props.schema, key))) return 'json'
  const types = typeNames.value.filter(type => type !== 'null')
  if (types.length !== 1) return 'json'
  const type = types[0]
  if (type === 'object') {
    const properties = record(props.schema.properties)
    if (!properties || Object.values(properties).some(value => !record(value))) return 'json'
    if (Array.isArray(props.schema.required) && props.schema.required.some(name => typeof name !== 'string' || !hasOwn(properties, name))) return 'json'
  }
  if (type === 'array' && !record(props.schema.items)) return 'json'
  return ['string', 'boolean', 'number', 'integer', 'object', 'array'].includes(type) ? type : 'json'
})
const itemSchema = computed(() => record(props.schema.items) ?? {})
const minItems = computed(() => itemLimit(props.schema.minItems))
const maxItems = computed(() => itemLimit(props.schema.maxItems))
function itemLimit(value: unknown): number | null {
  return typeof value === 'number' && Number.isInteger(value) && value >= 0 ? value : null
}
const stringValue = computed(() => {
  const parsed = parse(draft.value)
  return parsed.ok && typeof parsed.value === 'string' ? parsed.value : ''
})
const enumSelection = computed(() => {
  const parsed = parse(draft.value)
  const value = parsed.ok ? encode(parsed.value) : undefined
  return enumValues.value?.some(option => option.value === value) ? value : undefined
})
const booleanSelection = computed(() => ['true', 'false'].includes(draft.value.trim()) ? draft.value.trim() : undefined)
const numberIncomplete = computed(() => {
  const parsed = parse(draft.value)
  return !parsed.ok || typeof parsed.value !== 'number' || !Number.isFinite(parsed.value)
    || (kind.value === 'integer' && !Number.isInteger(parsed.value))
})
function fitsForm(text: string): boolean {
  if (text === '') return true
  const parsed = parse(text)
  if (!parsed.ok) return kind.value === 'number' || kind.value === 'integer'
  if (parsed.value === null) return nullable.value
  if (enumValues.value) return enumValues.value.some(option => option.value === encode(parsed.value))
  switch (kind.value) {
    case 'object': return record(parsed.value) !== null
    case 'array': return Array.isArray(parsed.value)
    case 'string': return typeof parsed.value === 'string'
    case 'boolean': return typeof parsed.value === 'boolean'
    case 'number':
    case 'integer': return typeof parsed.value === 'number'
    default: return false
  }
}
const canUseForm = computed(() => fitsForm(draft.value))

function hydrate(text: string): void {
  draft.value = text
  const parsed = parse(text)
  rawMode.value = !fitsForm(text)
  if (kind.value === 'object') {
    const saved = parsed.ok ? record(parsed.value) : null
    const properties = record(props.schema.properties) ?? {}
    const required = Array.isArray(props.schema.required) ? props.schema.required : []
    objectFields.value = Object.entries(properties).map(([name, schema]) => {
      const definition = record(schema) ?? {}
      const present = saved !== null && hasOwn(saved, name)
      return { name, label: typeof definition.title === 'string' ? definition.title : name, schema: definition,
        required: required.includes(name), enabled: required.includes(name) || present, draft: present ? encode(saved[name]) : '' }
    })
    extraFields.value = saved ? Object.entries(saved).filter(([name]) => !hasOwn(properties, name))
      .map(([name, value]) => ({ name, draft: encode(value) })) : []
  }
  if (kind.value === 'array') {
    arrayItems.value = parsed.ok && Array.isArray(parsed.value)
      ? parsed.value.map(value => ({ key: ++nextItemKey, draft: encode(value) })) : []
  }
}
function publish(text: string): void {
  if (props.disabled) return
  draft.value = text
  lastEmitted = text
  emit('update:modelValue', text)
}
function updateRaw(value: string): void { publish(value) }
function updateString(value: string): void { publish(JSON.stringify(value)) }
function updateEnum(value: unknown): void {
  if (typeof value === 'string' && enumValues.value?.some(option => option.value === value)) publish(value)
}
function updateBoolean(value: unknown): void {
  if (value === 'true' || value === 'false') publish(value)
}
function toggleNull(value: unknown): void {
  if (props.disabled || typeof value !== 'boolean') return
  if (value) { previousNonNull = draft.value; publish('null') }
  else { const previous = previousNonNull; hydrate(previous); publish(previous) }
}
function useForm(): void {
  if (!props.disabled && canUseForm.value) hydrate(draft.value)
}
function publishObject(): void {
  const entries = [...objectFields.value.filter(field => field.enabled), ...extraFields.value]
  // Keep unfinished child JSON verbatim so the caller's parser refuses submission.
  publish(`{${entries.map(field => `${JSON.stringify(field.name)}:${childDraft(field.draft)}`).join(',')}}`)
}
function childDraft(value: string): string { return value.trim() ? value : 'undefined' }
function updateField(name: string, value: string): void {
  if (props.disabled) return
  const field = objectFields.value.find(item => item.name === name)
  if (field) { field.draft = value; publishObject() }
}
function toggleField(name: string, value: unknown): void {
  if (props.disabled || typeof value !== 'boolean') return
  const field = objectFields.value.find(item => item.name === name)
  if (field && !field.required) { field.enabled = value; publishObject() }
}
function updateExtra(name: string, value: string): void {
  if (props.disabled) return
  const field = extraFields.value.find(item => item.name === name)
  if (field) { field.draft = value; publishObject() }
}
function publishArray(): void { publish(`[${arrayItems.value.map(item => childDraft(item.draft)).join(',')}]`) }
function updateItem(key: number, value: string): void {
  if (props.disabled) return
  const item = arrayItems.value.find(entry => entry.key === key)
  if (item) { item.draft = value; publishArray() }
}
function addItem(): void {
  if (props.disabled || (maxItems.value !== null && arrayItems.value.length >= maxItems.value)) return
  arrayItems.value.push({ key: ++nextItemKey, draft: '' })
  publishArray()
}
function removeItem(key: number): void {
  if (props.disabled || (minItems.value !== null && arrayItems.value.length <= minItems.value)) return
  arrayItems.value = arrayItems.value.filter(item => item.key !== key)
  publishArray()
}
watch(() => props.modelValue, value => {
  if (value === lastEmitted) { lastEmitted = undefined; return }
  previousNonNull = ''
  hydrate(value)
}, { immediate: true })
watch(() => props.schema, () => {
  lastEmitted = undefined
  previousNonNull = ''
  hydrate(props.modelValue)
}, { deep: true })
</script>
