<template>
  <div class="art-full-height">
    <sa-search-bar
      v-model="searchForm"
      label-width="90px"
      :show-expand="false"
      @reset="handleReset"
      @search="handleSearch"
    >
      <el-col :xs="24" :sm="12" :md="8" :lg="6">
        <el-form-item label="request_id" prop="request_id">
          <el-input v-model="searchForm.request_id" clearable placeholder="按 request_id" />
        </el-form-item>
      </el-col>
      <el-col :xs="24" :sm="12" :md="8" :lg="6">
        <el-form-item label="state" prop="state">
          <el-select v-model="searchForm.state" clearable placeholder="全部">
            <el-option label="queued" value="queued" />
            <el-option label="running" value="running" />
            <el-option label="succeeded" value="succeeded" />
            <el-option label="failed" value="failed" />
            <el-option label="canceled" value="canceled" />
          </el-select>
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
  import { onMounted, ref } from 'vue'
  import { useRoute } from 'vue-router'
  import { useTable } from '@/hooks/core/useTable'
  import invocationApi from '../api/invocation'
  import type { SandAiPageQuery } from '../api/types'

  const route = useRoute()

  const emptySearch = (): SandAiPageQuery => ({
    request_id: undefined,
    state: ''
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
      apiFn: invocationApi.list,
      apiParams: {},
      columnsFactory: () => [
        { prop: 'id', label: 'ID', width: 80, align: 'center' },
        { prop: 'request_id', label: 'request_id', minWidth: 160 },
        { prop: 'environment_id', label: 'environment_id', width: 130 },
        { prop: 'model_id', label: 'model_id', width: 100 },
        { prop: 'state', label: 'state', width: 110 },
        { prop: 'latency_ms', label: 'latency_ms', width: 110 },
        { prop: 'error_code', label: 'error_code', minWidth: 140 },
        { prop: 'request_at', label: 'request_at', width: 180 }
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

  function applyRequestIdFromQuery(): void {
    const raw = route.query.request_id
    const value = typeof raw === 'string' ? raw : Array.isArray(raw) ? raw[0] : undefined
    if (typeof value !== 'string' || !value.trim()) return
    searchForm.value.request_id = value.trim()
    handleSearch()
  }

  onMounted(() => {
    applyRequestIdFromQuery()
  })
</script>
