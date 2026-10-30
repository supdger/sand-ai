<template>
  <ElAlert v-if="!fields.length" type="info" :closable="false" title="此工具无需参数，将提交空对象 {}。" />
  <ElFormItem v-for="field in fields" :key="field.name" :label="field.name" :required="field.required">
    <div class="parameter">
      <ElCheckbox v-if="!field.required" :model-value="included[field.name] === true" :disabled="disabled"
        @update:model-value="toggle(field.name, $event === true)">填写此可选参数</ElCheckbox>
      <template v-if="field.required || included[field.name]">
        <ElSelect v-if="field.schema.enum" :model-value="raw[field.name]" :disabled="disabled"
          placeholder="选择允许的值" @update:model-value="set(field.name, $event)">
          <ElOption v-for="(value, index) in field.schema.enum" :key="index"
            :label="display(value)" :value="JSON.stringify(value)" />
        </ElSelect>
        <ElSelect v-else-if="field.schema.type === 'boolean'" :model-value="raw[field.name]" :disabled="disabled"
          placeholder="选择 true 或 false" @update:model-value="set(field.name, $event)">
          <ElOption label="true（是）" value="true" /><ElOption label="false（否）" value="false" />
        </ElSelect>
        <ElInput v-else :model-value="displayDraft(field.name, field.schema)" :disabled="disabled"
          :type="field.schema.type === 'array' || field.schema.type === 'object' ? 'textarea' : 'text'"
          :rows="3" :placeholder="placeholder(field.schema)" @update:model-value="edit(field.name, field.schema, $event)" />
        <div class="hint">{{ constraint(field.schema) }}</div>
        <ElInput v-if="field.schema.type === 'object' || field.schema.type === 'array'" type="textarea"
          :rows="3" readonly :model-value="JSON.stringify(field.schema, null, 2)" aria-label="嵌套参数约束" />
      </template>
    </div>
  </ElFormItem>
</template>

<script setup lang="ts">
import { computed, reactive, watch } from 'vue'
import { record, typeLabel, type ToolSchema } from './toolSchema'

const props = defineProps<{ schema: ToolSchema; modelValue: string; disabled: boolean }>()
const emit = defineEmits<{ 'update:modelValue': [value: string] }>()
const raw = reactive<Record<string, string>>({})
const included = reactive<Record<string, boolean>>({})
const extra = reactive<Record<string, string>>({})
let emitted = ''
const fields = computed(() => Object.entries(props.schema.properties ?? {}).map(([name, schema]) => ({
  name, schema, required: props.schema.required?.includes(name) ?? false
})))

function synchronize(): void {
  let value: unknown
  try { value = JSON.parse(props.modelValue) } catch { return }
  if (!record(value)) return
  for (const name of Object.keys(extra)) delete extra[name]
  for (const [name, item] of Object.entries(value)) {
    if (!Object.hasOwn(props.schema.properties ?? {}, name)) extra[name] = JSON.stringify(item)
  }
  for (const field of fields.value) {
    included[field.name] = Object.hasOwn(value, field.name)
    raw[field.name] = included[field.name] ? JSON.stringify(value[field.name]) : field.schema.type === 'string' ? '""' : ''
  }
}
watch(() => props.schema, () => { emitted = ''; synchronize() }, { immediate: true })
watch(() => props.modelValue, value => { if (value !== emitted) synchronize() })

function update(): void {
  // Preserve incomplete JSON drafts so the parent's signature and validation reflect every edit.
  const members = fields.value.filter(field => field.required || included[field.name])
    .map(field => `${JSON.stringify(field.name)}:${raw[field.name] ?? ''}`)
  // Unknown fields from the JSON editor remain visible to validation until explicitly removed.
  members.push(...Object.entries(extra).map(([name, value]) => `${JSON.stringify(name)}:${value}`))
  emitted = `{${members.join(',')}}`
  emit('update:modelValue', emitted)
}
function set(name: string, value: string): void { raw[name] = value; update() }
function toggle(name: string, value: boolean): void { included[name] = value; update() }
function edit(name: string, schema: ToolSchema, value: string): void {
  set(name, schema.type === 'string' ? JSON.stringify(value) : value)
}
function displayDraft(name: string, schema: ToolSchema): string {
  if (schema.type !== 'string') return raw[name] ?? ''
  try { const value: unknown = JSON.parse(raw[name] ?? '""'); return typeof value === 'string' ? value : '' }
  catch { return '' }
}
function display(value: unknown): string { return typeof value === 'string' ? value : JSON.stringify(value) }
function placeholder(schema: ToolSchema): string {
  if (schema.format === 'date') return '日期，例如 2026-10-09（YYYY-MM-DD）'
  if (schema.format === 'date-time') return '带时区的日期时间，例如 2026-10-09T09:30:00+08:00'
  return schema.type === 'array' ? '填写 JSON 数组，例如 []'
    : schema.type === 'object' ? '填写符合下方约束的 JSON 对象'
      : schema.type === 'integer' ? '填写整数' : schema.type === 'number' ? '填写数值' : '填写文字'
}
function constraint(schema: ToolSchema): string {
  const parts = [typeLabel(schema.type)]
  if (schema.format === 'date') parts.push('有效日期 YYYY-MM-DD')
  if (schema.format === 'date-time') parts.push('RFC3339 日期时间，必须带时区')
  if (schema.minLength !== undefined) parts.push(`至少 ${schema.minLength} 字`)
  if (schema.maxLength !== undefined) parts.push(`最多 ${schema.maxLength} 字`)
  if (schema.minimum !== undefined) parts.push(`最小 ${schema.minimum}`)
  if (schema.maximum !== undefined) parts.push(`最大 ${schema.maximum}`)
  if (schema.maxItems !== undefined) parts.push(`最多 ${schema.maxItems} 项`)
  return parts.join(' · ')
}
</script>

<style scoped>
.parameter { width: 100%; }
.hint { color: var(--el-text-color-secondary); font-size: 13px; margin-top: 4px; }
</style>
