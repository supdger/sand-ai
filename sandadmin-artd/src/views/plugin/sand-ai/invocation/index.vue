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
    <ElDialog v-model="costOpen" title="单次费用" width="680px" @close="closeCost">
      <div v-if="costLoading" v-loading="costLoading" class="min-h-24" />
      <ElAlert v-else-if="costError" :title="costError" type="warning" :closable="false" />
      <InvocationCostDetails v-else-if="cost" :cost="cost" />
    </ElDialog>
  </div>
</template>

<script setup lang="ts">
  import InvocationCostDetails from '../usage/modules/invocation-cost-details.vue'
  import { useAuth } from '@/hooks/core/useAuth'
  import { h, onBeforeUnmount, onMounted, ref } from 'vue'
  import { ElButton } from 'element-plus'
  import { useRoute } from 'vue-router'
  import { useTable } from '@/hooks/core/useTable'
  import invocationApi from '../api/invocation'
  import type { SandAiInvocation, SandAiInvocationCost, SandAiPageQuery } from '../api/types'

  const route = useRoute()
  const { hasAuth } = useAuth()

  const emptySearch = (): SandAiPageQuery => ({
    request_id: undefined,
    state: ''
  })
  const searchForm = ref<SandAiPageQuery>(emptySearch())
  const costOpen = ref(false)
  const costLoading = ref(false)
  const costError = ref('')
  const cost = ref<SandAiInvocationCost | null>(null)
  let costRequest = 0

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
        { prop: 'request_at', label: 'request_at', width: 180 },
        {
          prop: 'cost_action',
          label: '费用',
          width: 115,
          fixed: 'right',
          formatter: (row: SandAiInvocation) =>
            hasAuth('sand_ai:usage:cost')
              ? h(ElButton, { size: 'small', onClick: () => void showCost(row) }, () => '查看费用')
              : '无费用查看权限'
        }
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

  async function showCost(row: SandAiInvocation): Promise<void> {
    if (!hasAuth('sand_ai:usage:cost')) return
    const requestId = ++costRequest
    costOpen.value = true
    costLoading.value = true
    costError.value = ''
    cost.value = null
    try {
      const result = await invocationApi.cost(row.environment_id, row.request_id)
      if (requestId === costRequest) cost.value = result
    } catch (error) {
      if (requestId === costRequest) {
        const reason = error instanceof Error ? error.message : '后台未返回费用'
        costError.value = `费用查询失败：${reason}。请核对调用归属及费用查看权限，关闭后按原调用重试；不会重新发起调用。`
      }
    } finally {
      if (requestId === costRequest) costLoading.value = false
    }
  }

  function closeCost(): void {
    ++costRequest
    cost.value = null
    costError.value = ''
    costLoading.value = false
  }

  onBeforeUnmount(closeCost)

  onMounted(() => {
    applyRequestIdFromQuery()
  })
</script>
