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
        <ElTag :type="configured ? 'success' : 'info'" effect="plain">
          {{ configured ? '已配置（本机会话）' : '未配置' }}
        </ElTag>
        <ElButton :disabled="!configured" @click="clearKey">清除</ElButton>
        <ElButton link type="primary" @click="goSandIamApplication">去 SandIAM 应用</ElButton>
        <ElButton link type="primary" @click="goWorkloadClient">工作负载客户端</ElButton>
      </ElSpace>
      <p class="runtime-key__hint">
        audience 固定 `sand-ai`。凭证由 SandIAM 签发，不在已删除的 SandAI setup 页签发。
      </p>
    </div>
  </ElFormItem>
</template>

<script setup lang="ts">
  import { computed, onMounted } from 'vue'
  import { useRouter } from 'vue-router'
  import { ElMessage } from 'element-plus'
  import { FILE_API_KEY_STORAGE } from '../api/config'

  const props = withDefaults(
    defineProps<{
      modelValue: string
      placeholder?: string
    }>(),
    {
      placeholder: 'Bearer workload context（仅保存在本机 sessionStorage）'
    }
  )

  const emit = defineEmits<{
    'update:modelValue': [value: string]
  }>()

  const router = useRouter()

  const configured = computed(() => props.modelValue.trim().length > 0)

  function onInput(value: string): void {
    emit('update:modelValue', value)
  }

  function persist(): void {
    const key = props.modelValue.trim()
    if (key) {
      sessionStorage.setItem(FILE_API_KEY_STORAGE, key)
    } else {
      sessionStorage.removeItem(FILE_API_KEY_STORAGE)
    }
  }

  function clearKey(): void {
    emit('update:modelValue', '')
    sessionStorage.removeItem(FILE_API_KEY_STORAGE)
    ElMessage.success('已清除本机会话中的 Bearer')
  }

  /** SAND-113E 已移除 /sand-ai/setup；身份与凭证入口在 SandIAM。 */
  function goSandIamApplication(): void {
    void router.push('/sand-iam/application')
  }

  function goWorkloadClient(): void {
    void router.push('/sand-iam/workload-client')
  }

  onMounted(() => {
    if (!props.modelValue) {
      const stored = sessionStorage.getItem(FILE_API_KEY_STORAGE) ?? ''
      if (stored) {
        emit('update:modelValue', stored)
      }
    }
  })
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
