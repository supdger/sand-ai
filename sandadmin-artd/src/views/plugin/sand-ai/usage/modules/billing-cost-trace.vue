<template>
  <span v-if="!trace" class="text-sm text-gray-500">调用关联不可读取或证明不足</span>
  <template v-else>
    <ElButton v-if="canCost" link type="primary" :disabled="disabled" @click="openCost">查看原调用费用</ElButton>
    <span v-else class="text-sm text-gray-500">需要单次费用查看权限</span>
  </template>
  <ElDrawer v-model="open" title="账单关联的原调用费用" size="min(760px, 95vw)" @closed="reset">
    <template v-if="trace"><p class="mb-3 text-sm">用量事件 {{ trace.usage_event_id }} · 环境 {{ trace.environment_id }} · 调用 {{ trace.invocation_id }}</p></template>
    <p class="mb-3 text-sm text-gray-500">此处读取原调用计量及现金核对状态；人工审核记录不替换厂商对账金额。</p>
    <div v-if="loading" v-loading="loading" class="min-h-24" />
    <ElAlert v-else-if="error" :title="error" type="warning" :closable="false" />
    <InvocationCostDetails v-else-if="cost" :cost="cost" />
    <ElButton v-if="error && canCost" class="mt-3" :disabled="disabled" @click="openCost">重新读取</ElButton>
  </ElDrawer>
</template>
<script setup lang="ts">
  import { computed, onBeforeUnmount, ref, watch } from 'vue'
  import { useAuth } from '@/hooks/core/useAuth'
  import { HttpError } from '@/utils/http/error'
  import invocationApi from '../../api/invocation'
  import type { BillingUsageTrace } from '../../api/billing-types'
  import type { SandAiInvocationCost } from '../../api/types'
  import InvocationCostDetails from './invocation-cost-details.vue'
  const props = defineProps<{ trace?: BillingUsageTrace | null; disabled?: boolean }>()
  const { hasAuth } = useAuth()
  const canCost = computed(() => hasAuth('sand_ai:usage:cost'))
  const open = ref(false)
  const loading = ref(false)
  const error = ref('')
  const cost = ref<SandAiInvocationCost | null>(null)
  let generation = 0
  function reset() { ++generation; loading.value = false; error.value = ''; cost.value = null }
  async function openCost() {
    const trace = props.trace
    if (!trace || !canCost.value || props.disabled) return
    const request = ++generation
    open.value = true
    loading.value = true
    error.value = ''
    cost.value = null
    try {
      const value = await invocationApi.costByInvocationId(trace.environment_id, trace.invocation_id)
      if (request === generation && canCost.value) cost.value = value
    } catch (cause) {
      if (request === generation) error.value = cause instanceof HttpError && cause.code === 403
        ? '此调用不在当前正式可读取范围，或费用归属证明不可用。'
        : '原调用费用读取失败，请重试；未知金额不会按零显示。'
    } finally { if (request === generation) loading.value = false }
  }
  watch(() => props.trace, () => { open.value = false; reset() }, { flush: 'sync' })
  watch(canCost, (allowed) => { if (!allowed) { open.value = false; reset() } }, { flush: 'sync' })
  onBeforeUnmount(reset)
</script>
