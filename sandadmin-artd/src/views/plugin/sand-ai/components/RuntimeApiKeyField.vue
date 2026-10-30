<template>
  <ElFormItem label="运行面 Bearer">
    <div class="runtime-key">
      <ElInput
        :model-value="modelValue"
        type="password"
        show-password
        clearable
        :placeholder="placeholder"
        class="runtime-key__input"
        @update:model-value="onInput"
        @change="persist"
      />
      <ElSpace wrap>
        <ElTag :type="identityRefusal ? 'danger' : configured ? 'info' : 'warning'" effect="plain">
          {{ identityRefusal?.status ?? (configured ? (persistInSession ? '已填写（本机会话，调用时验证）' : '已填写（仅本页面，调用时验证）') : '未填写') }}
        </ElTag>
        <ElButton :disabled="!configured" @click="clearKey">{{ identityRefusal?.requiresReplacement ? '清除并更换' : '清除' }}</ElButton>
        <ElButton link type="primary" @click="goSandIamApplication">去 SandIAM 应用</ElButton>
        <ElButton link type="primary" @click="goWorkloadClient">工作负载客户端</ElButton>
      </ElSpace>
      <ElAlert
        v-if="identityRefusal"
        type="error"
        :title="identityRefusal.status"
        :description="identityRefusal.description"
        :closable="false"
        show-icon
      />
      <p class="runtime-key__hint">
        请填写 SandIAM 签发的短期运行身份，不含 Bearer 前缀；受众为 sand-ai。填写后仍由每次调用验证身份与服务授权。
      </p>
    </div>
  </ElFormItem>
</template>

<script setup lang="ts">
  import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
  import { useRouter } from 'vue-router'
  import { ElMessage } from 'element-plus'
  import { useUserStore } from '@/store/modules/user'
  import { clearRuntimeBearer, readRuntimeBearer, saveRuntimeBearer } from '../api/runtimeBearerSession'
  import { subscribeRuntimeIdentityRefusal, type RuntimeIdentityRefusal } from '../api/runtimeIdentityNotice'

  const props = withDefaults(
    defineProps<{
      modelValue: string
      placeholder?: string
      persistInSession?: boolean
    }>(),
    {
      placeholder: 'Bearer workload context（仅保存在本机 sessionStorage）',
      persistInSession: true
    }
  )

  const emit = defineEmits<{
    'update:modelValue': [value: string]
  }>()

  const router = useRouter()
  const userStore = useUserStore()

  const configured = computed(() => props.modelValue.trim().length > 0)
  const identityRefusal = ref<RuntimeIdentityRefusal | null>(null)
  let unsubscribeIdentityRefusal: (() => void) | null = null
  let latestIdentityRequestSequence = 0

  watch(() => props.modelValue.trim(), () => {
    identityRefusal.value = null
    latestIdentityRequestSequence = 0
  }, { flush: 'sync' })

  function onInput(value: string): void {
    emit('update:modelValue', value)
  }

  function persist(): void {
    if (props.persistInSession) saveRuntimeBearer(props.modelValue)
  }

  function clearKey(): void {
    identityRefusal.value = null
    emit('update:modelValue', '')
    if (props.persistInSession) clearRuntimeBearer()
    ElMessage.success(props.persistInSession ? '已清除本机会话中的 Bearer' : '已清除本页面的 Bearer')
  }

  /** SAND-113E 已移除 /sand-ai/setup；身份与凭证入口在 SandIAM。 */
  function goSandIamApplication(): void {
    void router.push('/sand-iam/application')
  }

  function goWorkloadClient(): void {
    void router.push('/sand-iam/workload-client')
  }

  onMounted(() => {
    unsubscribeIdentityRefusal = subscribeRuntimeIdentityRefusal((bearer, refusal, requestSequence) => {
      // A late rejection for an old credential must not invalidate its replacement.
      // Earlier successful requests must not hide a newer identity refusal either.
      if (props.modelValue.trim() !== bearer || requestSequence < latestIdentityRequestSequence) return
      latestIdentityRequestSequence = requestSequence
      identityRefusal.value = refusal
    })
    if (props.persistInSession && !props.modelValue) {
      const stored = readRuntimeBearer()
      if (stored) {
        emit('update:modelValue', stored)
      }
    }
  })
  onBeforeUnmount(() => { unsubscribeIdentityRefusal?.() })
  watch(() => userStore.info.id, () => {
    identityRefusal.value = null
    latestIdentityRequestSequence = 0
    if (!props.persistInSession) {
      emit('update:modelValue', '')
      return
    }
    const stored = readRuntimeBearer()
    if (props.modelValue !== stored) emit('update:modelValue', stored)
  }, { flush: 'sync' })
</script>

<style scoped lang="scss">
  .runtime-key {
    display: flex;
    flex-direction: column;
    gap: 8px;
    width: 100%;
    max-width: 640px;
  }

  .runtime-key__input {
    max-width: 520px;
  }

  .runtime-key__hint {
    margin: 0;
    font-size: 12px;
    line-height: 1.5;
    color: var(--el-text-color-secondary);
  }
</style>
