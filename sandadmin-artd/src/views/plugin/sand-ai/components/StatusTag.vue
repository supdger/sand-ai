<template>
  <ElTag :type="status === 1 ? 'success' : 'info'" effect="light">
    {{ status === 1 ? '已启用' : '已停用' }}
  </ElTag>
</template>

<script setup lang="ts">
  defineProps<{
    status: 1 | 2 | number
  }>()
</script>
