<template>
  <ElCard class="art-table-card" shadow="never">
    <h3 class="m-0 mb-2 text-base">单次调用成本分解</h3>
    <p class="mb-3 text-sm text-gray-500">
      从调用记录取得环境 ID
      和请求号，查看该次调用的用量、冻结价格及账单核对结果。查询不会重新发起调用。
    </p>
    <ElForm label-width="90px" @submit.prevent="loadCost">
      <ElFormItem label="环境 ID">
        <ElInputNumber
          v-model="environmentId"
          :min="1"
          :controls="false"
          placeholder="调用所属环境"
        />
      </ElFormItem>
      <ElFormItem label="请求号">
        <ElInput v-model="requestId" :maxlength="128" placeholder="调用记录中的请求号" clearable />
      </ElFormItem>
      <ElButton type="primary" native-type="submit" :disabled="!allowed || identity.actor.value === null" :loading="loading">查询单次费用</ElButton>
    </ElForm>
    <ElAlert v-if="error" :title="error" type="warning" :closable="false" class="mt-3" />
    <div v-if="cost" class="mt-3"><InvocationCostDetails :cost="cost" /></div>
  </ElCard>
</template>

<script setup lang="ts">
  import { computed, onBeforeUnmount, ref, watch } from 'vue'
  import { useAuth } from '@/hooks/core/useAuth'
  import { useAdminReadIdentity } from '../../composables/useAdminReadIdentity'
  import invocationApi from '../../api/invocation'
  import type { SandAiInvocationCost } from '../../api/types'
  import InvocationCostDetails from './invocation-cost-details.vue'

  const environmentId = ref<number>()
  const identity = useAdminReadIdentity()
  const { hasAuth } = useAuth()
  const allowed = computed(() => hasAuth('sand_ai:usage:cost'))
  const requestId = ref('')
  const cost = ref<SandAiInvocationCost | null>(null)
  const error = ref('')
  const loading = ref(false)
  let generation = 0

  function invalidate(): void {
    ++generation
    cost.value = null
    error.value = ''
    loading.value = false
  }
  watch([environmentId, requestId], invalidate, { flush: 'sync' })
  watch([identity.epoch, allowed], () => {
    invalidate()
    environmentId.value = undefined
    requestId.value = ''
  }, { flush: 'sync' })
  onBeforeUnmount(invalidate)

  async function loadCost(): Promise<void> {
    const owner = identity.capture()
    if (loading.value || !allowed.value || owner === null) return
    const selectedEnvironment = environmentId.value
    const selectedRequest = requestId.value
    invalidate()
    if (
      !selectedEnvironment ||
      !Number.isSafeInteger(selectedEnvironment) ||
      selectedEnvironment < 1 ||
      !selectedRequest.trim() ||
      new TextEncoder().encode(selectedRequest).length > 128 ||
      [...selectedRequest].some(
        (character) => character.charCodeAt(0) < 32 || character.charCodeAt(0) === 127
      )
    ) {
      error.value = '请填写有效的环境 ID 和请求号；可在调用记录中取得这两项。'
      return
    }
    const current = ++generation
    loading.value = true
    try {
      const result = await invocationApi.cost(selectedEnvironment, selectedRequest)
      if (identity.current(owner) && allowed.value && current === generation) cost.value = result
    } catch (failure) {
      if (identity.current(owner) && allowed.value && current === generation) {
        const reason = failure instanceof Error ? failure.message : '后台未返回费用'
        error.value = `费用查询失败：${reason}。请核对环境、请求号及费用查看权限后重试；不会重新发起调用。`
      }
    } finally {
      if (identity.current(owner) && current === generation) loading.value = false
    }
  }
</script>
