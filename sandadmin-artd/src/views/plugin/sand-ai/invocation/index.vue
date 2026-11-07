<template>
  <SandAiErrorBoundary>
    <div class="sand-ai-page">
      <sa-search-bar
        v-model="searchForm"
        label-width="90px"
        :show-expand="false"
        @reset="handleReset"
        @search="handleSearch"
      >
        <el-col :xs="24" :sm="12" :md="8" :lg="6">
          <el-form-item label="请求编号" prop="request_id">
            <el-input v-model="searchForm.request_id" clearable placeholder="按请求编号" />
          </el-form-item>
        </el-col>
        <el-col :xs="24" :sm="12" :md="8" :lg="6">
          <el-form-item label="状态" prop="state">
            <el-select v-model="searchForm.state" clearable placeholder="全部">
              <el-option label="排队中" value="queued" />
              <el-option label="进行中" value="running" />
              <el-option label="成功" value="succeeded" />
              <el-option label="失败" value="failed" />
              <el-option label="已取消" value="canceled" />
            </el-select>
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
          empty-text="这里显示每次 AI 调用及其费用；产生调用后可在此追踪"
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
  </SandAiErrorBoundary>
</template>

<script setup lang="ts">
  import SandAiErrorBoundary from '../components/SandAiErrorBoundary.vue'
  import '../components/sandAiPage.css'
  import InvocationCostDetails from '../usage/modules/invocation-cost-details.vue'
  import { useAuth } from '@/hooks/core/useAuth'
  import { h, onBeforeUnmount, onMounted, ref } from 'vue'
  import { ElButton } from 'element-plus'
  import { useRoute } from 'vue-router'
  import { useTable } from '@/hooks/core/useTable'
  import invocationApi from '../api/invocation'
  import { errorDisplayText } from '../api/errorText'
  import { runStateLabel } from '../api/displayLabels'
  import { hintForRuntimeErrorCode } from '../api/runtimeErrors'
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
        { prop: 'id', label: '编号', width: 70, align: 'center' },
        { prop: 'request_id', label: '请求编号', minWidth: 140, showOverflowTooltip: true },
        { prop: 'environment_id', label: '环境', width: 110 },
        { prop: 'model_id', label: '模型', width: 90 },
        {
          prop: 'state',
          label: '状态',
          width: 100,
          formatter: (row: SandAiInvocation) => runStateLabel(row.state)
        },
        { prop: 'latency_ms', label: '耗时（毫秒）', width: 100 },
        { prop: 'error_code', label: '错误', minWidth: 110, showOverflowTooltip: true,
          formatter: (row: SandAiInvocation) => row.error_code ? (hintForRuntimeErrorCode(row.error_code) ?? row.error_code) : '—' },
        { prop: 'request_at', label: '请求时间', width: 170 },
        {
          prop: 'cost_action',
          label: '费用',
          width: 100,
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
        const reason = errorDisplayText(error, '后台未返回费用')
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
