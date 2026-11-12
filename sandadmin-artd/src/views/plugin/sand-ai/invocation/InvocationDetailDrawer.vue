<template>
  <ElDrawer v-model="open" title="详情" direction="rtl" size="420px" @closed="emit('closed')">
    <dl v-if="detail" class="detail-list">
      <div>
        <dt>时间</dt>
        <dd>{{ formatLocalTime(detail.request_at) }}</dd>
      </div>
      <div>
        <dt>谁调用的</dt>
        <dd>{{ detail.caller_name }}</dd>
      </div>
      <div>
        <dt>用途</dt>
        <dd>{{ detail.purpose }}</dd>
      </div>
      <div>
        <dt>模型名</dt>
        <dd>{{ detail.model_name }}</dd>
      </div>
      <div>
        <dt>用时</dt>
        <dd>{{ formatLatency(detail.latency_ms) }}</dd>
      </div>
      <div>
        <dt>用了多少</dt>
        <dd>{{ formatQuantity(detail.input_tokens, detail.output_tokens) }}</dd>
      </div>
      <div>
        <dt>花费</dt>
        <dd>{{ formatSpend(detail.spend_amount) }}</dd>
      </div>
      <div>
        <dt>请求编号</dt>
        <dd class="request-line">
          <span>{{ detail.request_id }}</span>
          <ElButton text type="primary" @click="copyRequestId">复制</ElButton>
        </dd>
      </div>
    </dl>
    <div v-if="isDevLogin()" class="dev-block">
      <p class="dev-title">开发者字段</p>
      <p v-if="devError" class="dev-error">{{ devError }}</p>
      <InvocationCostDetails v-else-if="cost" :cost="cost" />
      <p v-else class="dev-error">开发者字段暂时读不到</p>
    </div>
  </ElDrawer>
</template>

<script setup lang="ts">
  import { computed } from 'vue'
  import { ElMessage } from 'element-plus'
  import InvocationCostDetails from '../usage/modules/invocation-cost-details.vue'
  import {
    formatLatency,
    formatLocalTime,
    formatQuantity,
    formatSpend,
    isDevLogin
  } from '../api/localTime'
  import type { SandAiInvocationCost } from '../api/types'

  export type InvocationDetail = {
    request_at: string
    caller_name: string
    purpose: string
    model_name: string
    latency_ms: number | null
    spend_amount: string | null
    request_id: string
    input_tokens: number | null
    output_tokens: number | null
  }

  const props = defineProps<{
    modelValue: boolean
    detail: InvocationDetail | null
    cost: SandAiInvocationCost | null
    devError: string
  }>()

  const emit = defineEmits<{
    'update:modelValue': [open: boolean]
    closed: []
  }>()

  const open = computed({
    get: () => props.modelValue,
    set: (value: boolean) => emit('update:modelValue', value)
  })

  async function copyRequestId(): Promise<void> {
    if (!props.detail) return
    await navigator.clipboard.writeText(props.detail.request_id)
    ElMessage.success('已复制请求编号')
  }
</script>

<style scoped>
  .detail-list {
    display: flex;
    flex-direction: column;
    gap: 14px;
    margin: 0;
  }

  .detail-list dt {
    margin: 0;
    font-size: 12px;
    color: var(--el-text-color-secondary);
  }

  .detail-list dd {
    margin: 4px 0 0;
    font-size: 14px;
    color: var(--el-text-color-primary);
    word-break: break-all;
  }

  .request-line {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 8px;
  }

  .dev-block {
    margin-top: 24px;
    padding-top: 16px;
    border-top: 1px solid var(--el-border-color-lighter);
  }

  .dev-title,
  .dev-error {
    margin: 0 0 8px;
    font-size: 13px;
    color: var(--el-text-color-secondary);
  }
</style>
