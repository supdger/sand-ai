<template>
  <ElSpace v-if="useMock" wrap>
    <ElButton :loading="loading" @click="emit('reload')">刷新</ElButton>
    <ElButton :type="forceError ? 'danger' : 'default'" @click="toggleError">
      {{ forceError ? '关闭模拟失败' : '模拟接口失败' }}
    </ElButton>
    <ElTag type="warning" effect="plain">schema mock</ElTag>
  </ElSpace>
  <ElTag v-else type="success" effect="plain">C-04 真实 API</ElTag>
</template>

<script setup lang="ts">
  import { ref } from 'vue'
  import { getMockForceError, setMockForceError, USE_SCHEMA_MOCK } from '../api'

  defineProps<{
    loading?: boolean
  }>()

  const emit = defineEmits<{
    reload: []
    changed: [forceError: boolean]
  }>()

  const useMock = USE_SCHEMA_MOCK
  const forceError = ref(getMockForceError())

  function toggleError(): void {
    forceError.value = !forceError.value
    setMockForceError(forceError.value)
    emit('changed', forceError.value)
    emit('reload')
  }
</script>
