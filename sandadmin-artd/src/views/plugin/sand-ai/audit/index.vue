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
        <el-form-item label="action" prop="action">
          <el-input v-model="searchForm.action" clearable placeholder="按 action" />
        </el-form-item>
      </el-col>
      <el-col :xs="24" :sm="12" :md="8" :lg="6">
        <el-form-item label="摘要" prop="name">
          <el-input v-model="searchForm.name" clearable placeholder="summary / actor_ref" />
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
  import auditApi from '../api/audit'
  import type { SandAiPageQuery } from '../api/types'

  const emptySearch = (): SandAiPageQuery => ({
    action: undefined,
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
      apiFn: auditApi.list,
      apiParams: {},
      columnsFactory: () => [
        { prop: 'id', label: 'ID', width: 80, align: 'center' },
        { prop: 'actor_type', label: 'actor_type', width: 110 },
        { prop: 'actor_ref', label: 'actor_ref', minWidth: 120 },
        { prop: 'action', label: 'action', minWidth: 160 },
        { prop: 'resource_type', label: 'resource_type', width: 130 },
        { prop: 'resource_id', label: 'resource_id', width: 110 },
        { prop: 'summary', label: 'summary', minWidth: 200, showOverflowTooltip: true },
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
