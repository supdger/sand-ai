<template>
  <ElDrawer v-model="open" title="每一次签发" direction="rtl" size="420px">
    <p class="lead">{{ sentence }}</p>
    <ul class="times">
      <li v-for="(entry, index) in entries" :key="`${entry.create_time}-${index}`">
        {{ formatLocalTime(entry.create_time) }}
      </li>
    </ul>
  </ElDrawer>
</template>

<script setup lang="ts">
  import { computed } from 'vue'
  import { formatLocalTime } from '../api/localTime'

  const props = defineProps<{
    modelValue: boolean
    sentence: string
    entries: Array<{ create_time: string }>
  }>()

  const emit = defineEmits<{
    'update:modelValue': [open: boolean]
  }>()

  const open = computed({
    get: () => props.modelValue,
    set: (value: boolean) => emit('update:modelValue', value)
  })
</script>

<style scoped>
  .lead {
    margin: 0 0 12px;
    color: var(--el-text-color-primary);
  }

  .times {
    margin: 0;
    padding: 0;
    list-style: none;
  }

  .times li {
    padding: 8px 0;
    border-bottom: 1px solid var(--el-border-color-lighter);
    color: var(--el-text-color-regular);
  }
</style>
