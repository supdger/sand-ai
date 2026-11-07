<template>
  <SandAiErrorBoundary>
    <div class="sand-ai-page">
      <sa-search-bar
        v-model="searchForm"
        label-width="80px"
        :show-expand="false"
        @reset="handleReset"
        @search="handleSearch"
      >
        <el-col :xs="24" :sm="12" :md="8" :lg="6">
          <el-form-item label="操作" prop="action">
            <el-input v-model="searchForm.action" clearable placeholder="按操作筛选" />
          </el-form-item>
        </el-col>
        <el-col :xs="24" :sm="12" :md="8" :lg="6">
          <el-form-item label="摘要" prop="name">
            <el-input v-model="searchForm.name" clearable placeholder="按摘要或操作者筛选" />
          </el-form-item>
        </el-col>
      </sa-search-bar>

      <ElCard class="art-table-card sand-ai-page-card" shadow="never">
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
  </SandAiErrorBoundary>
</template>

<script setup lang="ts">
  import SandAiErrorBoundary from '../components/SandAiErrorBoundary.vue'
  import '../components/sandAiPage.css'
  import { ref } from 'vue'
  import { useTable } from '@/hooks/core/useTable'
  import auditApi from '../api/audit'
  import { serviceActionLabel } from '../api/displayLabels'
  import type { SandAiAuditLog, SandAiPageQuery } from '../api/types'

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
        { prop: 'id', label: '编号', width: 70, align: 'center' },
        { prop: 'actor_type', label: '操作者类型', width: 100 },
        { prop: 'actor_ref', label: '操作者编号', minWidth: 100, showOverflowTooltip: true },
        {
          prop: 'action',
          label: '操作',
          minWidth: 140,
          formatter: (row: SandAiAuditLog) => serviceActionLabel(row.action)
        },
        { prop: 'resource_type', label: '对象类型', width: 110 },
        { prop: 'resource_id', label: '对象编号', width: 90 },
        { prop: 'summary', label: '摘要', minWidth: 160, showOverflowTooltip: true },
        { prop: 'create_time', label: '时间', width: 170 }
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
