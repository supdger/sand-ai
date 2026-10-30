<template>
  <div class="readonly-tools">
    <ElSelect :model-value="selectedKeys" multiple :multiple-limit="2" filterable :disabled="disabled || !environmentId || !currentAdminId"
      :loading="loading" placeholder="可选：最多两个已发布的只读工具版本" style="width: 100%" @update:model-value="select">
      <ElOption v-for="tool in catalog" :key="key(tool)" :value="key(tool)"
        :label="`${tool.name} · ${tool.code} 第 ${tool.version} 版`" />
      <ElOption v-for="tool in unavailable" :key="key(tool)" :value="key(tool)"
        :label="`${tool.code} 第 ${tool.version} 版（当前列表不可用，请核对）`" />
    </ElSelect>
    <ElButton link type="primary" :disabled="disabled || !environmentId || !currentAdminId" :loading="loading" @click="load(true)">刷新工具</ElButton>
    <ElButton v-if="more" link type="primary" :disabled="disabled || !currentAdminId" :loading="loading" @click="load(false)">更多工具</ElButton>
    <p v-if="error" class="note">{{ error }}。已选版本保留，请核对后保存。</p>
    <p class="note">列表来自选定环境。选择工具不会授予执行权限；运行时仍须凭证获准的 tool.run。工具版本、业务范围和输入 Schema 随版本或本次请求固定。</p>
    <div v-for="(tool, index) in modelValue" :key="key(tool)" class="selected-tool">
      <strong>{{ tool.code }} · 第 {{ tool.version }} 版</strong>
      <ElInput :model-value="tool.business_scope" :disabled="disabled" maxlength="128"
        placeholder="填写已获授权的业务范围，例如 public_docs" @update:model-value="scope(index, $event)" />
      <ElCollapse>
        <ElCollapseItem title="查看已发布输入 Schema" :name="key(tool)">
          <pre>{{ schema(tool) }}</pre>
        </ElCollapseItem>
      </ElCollapse>
    </div>
    <p v-if="modelValue.length" class="note">新组合最多执行一次私有检索及每项只读工具一次，最多四轮模型回答，每轮最多 512 Token。工具请求按实际次数计量；未定价不表示免费。待确认任务应刷新原任务，不重新提交。</p>
  </div>
</template>

<script setup lang="ts">
  import { computed, onUnmounted, ref, watch } from 'vue'
  import { useUserStore } from '@/store/modules/user'
  import type { AgentReadonlyToolSelection } from '../../api/agentDefinition'
  import toolApi, { type ToolAvailable } from '../../api/toolTrial'

  const props = defineProps<{ environmentId: number | null; modelValue: readonly AgentReadonlyToolSelection[]; disabled?: boolean }>()
  const userStore = useUserStore()
  const currentAdminId = computed(() => userStore.isLogin && userStore.accessToken && userStore.info.id != null ? String(userStore.info.id) : '')
  const emit = defineEmits<{ 'update:modelValue': [value: AgentReadonlyToolSelection[]] }>()
  const catalog = ref<ToolAvailable[]>([])
  const loading = ref(false)
  const error = ref('')
  const more = ref(false)
  let page = 0
  let epoch = 0
  const key = (tool: { code: string; version: number }): string => `${tool.code}:${tool.version}`
  const selectedKeys = computed(() => props.modelValue.map(key))
  const unavailable = computed(() => props.modelValue.filter(tool => !catalog.value.some(item => key(item) === key(tool))))
  function schema(tool: AgentReadonlyToolSelection): string {
    const item = catalog.value.find(candidate => key(candidate) === key(tool))
    return item ? JSON.stringify(item.input_schema, null, 2) : '当前目录未返回此版本的 Schema；服务端会在保存及执行前再次核验。'
  }
  function select(value: unknown): void {
    if (props.disabled || !currentAdminId.value || !Array.isArray(value) || value.length > 2 || !value.every(item => typeof item === 'string')) return
    const next: AgentReadonlyToolSelection[] = []
    for (const selected of value) {
      const existing = props.modelValue.find(tool => key(tool) === selected)
      const tool = catalog.value.find(item => key(item) === selected)
      if (existing) next.push({ ...existing })
      else if (tool) next.push({ code: tool.code, version: tool.version, business_scope: '' })
    }
    emit('update:modelValue', next)
  }
  function scope(index: number, value: string): void {
    if (props.disabled || !currentAdminId.value) return
    emit('update:modelValue', props.modelValue.map((tool, current) => current === index ? { ...tool, business_scope: value } : { ...tool }))
  }
  async function load(reset: boolean): Promise<void> {
    const environment = props.environmentId
    if (!environment || !currentAdminId.value || (loading.value && !reset)) return
    const current = ++epoch
    const target = reset ? 1 : page + 1
    loading.value = true
    error.value = ''
    try {
      const result = await toolApi.available(environment, target)
      if (current !== epoch || environment !== props.environmentId) return
      const entries = reset ? result.data : [...catalog.value, ...result.data]
      catalog.value = [...new Map(entries.map(tool => [key(tool), tool])).values()]
      page = target
      more.value = page * 100 < result.total
    } catch (failure) {
      if (current === epoch) error.value = failure instanceof Error ? failure.message : '只读工具目录读取失败'
    } finally { if (current === epoch) loading.value = false }
  }
  watch([() => props.environmentId, currentAdminId, () => userStore.accessToken], () => {
    ++epoch
    catalog.value = []
    page = 0
    more.value = false
    error.value = ''
    loading.value = false
    void load(true)
  }, { immediate: true, flush: 'sync' })
  onUnmounted(() => { ++epoch })
</script>

<style scoped>
  .readonly-tools { width: 100%; }
  .note { color: var(--el-text-color-secondary); font-size: 13px; line-height: 1.7; }
  .selected-tool { margin-top: 12px; }
  .selected-tool strong { display: block; margin-bottom: 6px; }
  pre { white-space: pre-wrap; overflow-wrap: anywhere; margin: 0; }
</style>
