<template>
  <div class="art-full-height">
    <sa-search-bar
      v-model="searchForm"
      label-width="80px"
      :show-expand="false"
      @reset="handleReset"
      @search="handleSearch"
    >
      <el-col :xs="24" :sm="12" :md="8" :lg="6">
        <el-form-item label="关键词" prop="name">
          <el-input v-model="searchForm.name" clearable placeholder="source 模糊匹配" />
        </el-form-item>
      </el-col>
    </sa-search-bar>

    <ElCard class="art-table-card" shadow="never">
      <ArtTableHeader v-model:columns="columnChecks" :loading="loading" @refresh="refreshData" />

      <ArtTable
        row-key="id"
        :loading="loading"
        :data="data"
        :columns="columns"
        :pagination="pagination"
        @pagination:size-change="handleSizeChange"
        @pagination:current-change="handleCurrentChange"
      />
    </ElCard>
  </div>
</template>

<script setup lang="ts">
  import { ref } from 'vue'
  import { useTable } from '@/hooks/core/useTable'
  import usageApi from '../api/usage'
  import type { SandAiPageQuery } from '../api/types'

  const emptySearch = (): SandAiPageQuery => ({
    name: undefined
  })
  const searchForm = ref<SandAiPageQuery>(emptySearch())

  const {
    columns,
    columnChecks,
    data,
    loading,
    pagination,
    searchParams,
    getData,
    resetSearchParams,
    handleSizeChange,
    handleCurrentChange,
    refreshData
  } = useTable({
    core: {
      apiFn: usageApi.list,
      apiParams: {},
      columnsFactory: () => [
        { prop: 'id', label: 'ID', width: 80, align: 'center' },
        { prop: 'invocation_id', label: 'invocation_id', width: 130 },
        { prop: 'input_tokens', label: 'input_tokens', width: 120 },
        { prop: 'output_tokens', label: 'output_tokens', width: 130 },
        { prop: 'total_tokens', label: 'total_tokens', width: 120 },
        { prop: 'cost_amount', label: 'cost_amount', width: 120 },
        { prop: 'currency', label: 'currency', width: 100 },
        { prop: 'source', label: 'source', minWidth: 140 },
        { prop: 'create_time', label: 'create_time', width: 180 }
      ]
    }
  })

  function handleSearch(): void {
    Object.assign(searchParams, searchForm.value)
    getData()
  }

  function handleReset(): void {
    searchForm.value = emptySearch()
    resetSearchParams()
  }
</script>
