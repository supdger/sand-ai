<template>
  <sa-search-bar
    ref="searchBarRef"
    v-model="formData"
    label-width="80px"
    :show-expand="false"
    @reset="handleReset"
    @search="handleSearch"
  >
    <el-col :xs="24" :sm="12" :md="8" :lg="6">
      <el-form-item label="编码" prop="code">
        <el-input v-model="formData.code" placeholder="按 code 过滤" clearable />
      </el-form-item>
    </el-col>
    <el-col :xs="24" :sm="12" :md="8" :lg="6">
      <el-form-item label="名称" prop="name">
        <el-input v-model="formData.name" placeholder="按 name 过滤" clearable />
      </el-form-item>
    </el-col>
    <el-col :xs="24" :sm="12" :md="8" :lg="6">
      <el-form-item label="状态" prop="status">
        <el-select v-model="formData.status" clearable placeholder="全部">
          <el-option label="启用" :value="1" />
          <el-option label="停用" :value="2" />
        </el-select>
      </el-form-item>
    </el-col>
  </sa-search-bar>
</template>

<script setup lang="ts">
  import { computed, ref } from 'vue'
  import type { SandAiPageQuery } from '../api/types'

  const props = defineProps<{
    modelValue: SandAiPageQuery
  }>()

  const emit = defineEmits<{
    'update:modelValue': [value: SandAiPageQuery]
    search: [params: SandAiPageQuery]
    reset: []
  }>()

  const searchBarRef = ref<{ ref?: { resetFields: () => void } }>()

  const formData = computed({
    get: () => props.modelValue,
    set: (value: SandAiPageQuery) => emit('update:modelValue', value)
  })

  function handleReset(): void {
    searchBarRef.value?.ref?.resetFields()
    emit('reset')
  }

  function handleSearch(): void {
    emit('search', formData.value)
  }
</script>
