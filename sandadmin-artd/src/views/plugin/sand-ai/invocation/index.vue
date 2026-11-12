<template>
  <SandAiErrorBoundary>
    <div class="sand-ai-page record-page">
      <header class="page-head">
        <div>
          <h1 class="page-title">调用记录</h1>
          <p class="page-desc">每次调用的时间、模型和花费</p>
        </div>
      </header>

      <div class="filter-row">
        <ElDatePicker
          v-model="dateRange"
          type="daterange"
          value-format="YYYY-MM-DD"
          start-placeholder="开始日期"
          end-placeholder="结束日期"
          range-separator="至"
          @change="applyFilter"
        />
        <ElSelect v-model="searchForm.state" clearable placeholder="状态" class="filter-select" @change="applyFilter">
          <ElOption label="排队中" value="queued" />
          <ElOption label="进行中" value="running" />
          <ElOption label="成功" value="succeeded" />
          <ElOption label="失败" value="failed" />
        </ElSelect>
        <ElSelect v-model="searchForm.purpose" clearable placeholder="用途" class="filter-select" @change="applyFilter">
          <ElOption label="查资料" value="knowledge" />
          <ElOption label="整理资料" value="embed" />
          <ElOption label="对话" value="chat" />
        </ElSelect>
        <ElButton text @click="clearFilter">清空筛选</ElButton>
      </div>

      <ElCard class="art-table-card sand-ai-page-card" shadow="never">
        <ArtTable
          row-key="id"
          :loading="loading"
          :data="data"
          :columns="columns"
          :empty-text="emptyText"
          :pagination="pagination"
          @pagination:size-change="handleSizeChange"
          @pagination:current-change="handleCurrentChange"
        />
      </ElCard>

      <InvocationDetailDrawer
        v-model="detailOpen"
        :detail="detail"
        :cost="cost"
        :dev-error="devError"
        @closed="closeDetail"
      />
    </div>
  </SandAiErrorBoundary>
</template>

<script setup lang="ts">
  import SandAiErrorBoundary from '../components/SandAiErrorBoundary.vue'
  import '../components/sandAiPage.css'
  import InvocationDetailDrawer from './InvocationDetailDrawer.vue'
  import type { InvocationDetail } from './InvocationDetailDrawer.vue'
  import { h, onBeforeUnmount, onMounted, ref } from 'vue'
  import { ElButton } from 'element-plus'
  import { useRoute } from 'vue-router'
  import { useTable } from '@/hooks/core/useTable'
  import invocationApi from '../api/invocation'
  import { errorDisplayText } from '../api/errorText'
  import { runStateLabel } from '../api/displayLabels'
  import {
    browserOffsetMinutes,
    formatLatency,
    formatLocalTime,
    formatSpend,
    isDevLogin
  } from '../api/localTime'
  import type { SandAiInvocationCost, SandAiPageQuery } from '../api/types'

  type InvocationRow = {
    id: number
    request_id: string
    environment_id: number
    state: string
    latency_ms: number | null
    request_at: string
    model_name: string
    caller_name: string
    purpose: string
    spend_amount: string | null
    input_tokens: number | null
    output_tokens: number | null
  }

  type InvocationFilter = SandAiPageQuery & {
    purpose: string
    request_from?: string
    request_to?: string
    offset_minutes: number
  }

  const route = useRoute()
  const dateRange = ref<[string, string] | null>(null)
  const detailOpen = ref(false)
  const detail = ref<InvocationDetail | null>(null)
  const cost = ref<SandAiInvocationCost | null>(null)
  const devError = ref('')
  let detailRequest = 0

  function emptyFilter(): InvocationFilter {
    return {
      state: '',
      purpose: '',
      request_id: undefined,
      request_from: undefined,
      request_to: undefined,
      offset_minutes: browserOffsetMinutes()
    }
  }

  const searchForm = ref<InvocationFilter>(emptyFilter())

  const {
    columns,
    data,
    loading,
    pagination,
    searchParams,
    getData,
    handleSizeChange,
    handleCurrentChange
  } = useTable({
    core: {
      apiFn: (params: InvocationFilter) => invocationApi.list(params),
      apiParams: {
        offset_minutes: browserOffsetMinutes()
      },
      columnsFactory: () => [
        {
          prop: 'request_at',
          label: '时间',
          width: 130,
          formatter: (row: InvocationRow) => formatLocalTime(row.request_at)
        },
        { prop: 'caller_name', label: '谁调用的', minWidth: 120, showOverflowTooltip: true },
        { prop: 'purpose', label: '用途', width: 100 },
        { prop: 'model_name', label: '模型名', minWidth: 140, showOverflowTooltip: true },
        {
          prop: 'state',
          label: '状态',
          width: 90,
          formatter: (row: InvocationRow) => runStateLabel(row.state)
        },
        {
          prop: 'latency_ms',
          label: '用时',
          width: 90,
          formatter: (row: InvocationRow) => formatLatency(row.latency_ms)
        },
        {
          prop: 'spend_amount',
          label: '花费',
          width: 110,
          formatter: (row: InvocationRow) => formatSpend(row.spend_amount)
        },
        {
          prop: 'detail',
          label: '详情',
          width: 80,
          formatter: (row: InvocationRow) =>
            h(ElButton, { text: true, type: 'primary', onClick: () => void openDetail(row) }, () => '详情')
        }
      ]
    }
  })

  const emptyText = ref('还没有调用')

  function applyFilter(): void {
    const range = dateRange.value
    searchForm.value.request_from = range?.[0]
    searchForm.value.request_to = range?.[1]
    searchForm.value.offset_minutes = browserOffsetMinutes()
    Object.assign(searchParams, searchForm.value)
    const params = searchParams as Record<string, unknown>
    if (!searchForm.value.request_from) delete params.request_from
    if (!searchForm.value.request_to) delete params.request_to
    if (!searchForm.value.state) delete params.state
    if (!searchForm.value.purpose) delete params.purpose
    if (!searchForm.value.request_id) delete params.request_id
    const filtered = Boolean(
      searchForm.value.request_from ||
        searchForm.value.state ||
        searchForm.value.purpose ||
        searchForm.value.request_id
    )
    emptyText.value = filtered ? '没有找到符合条件的调用' : '还没有调用'
    void getData()
  }

  function clearFilter(): void {
    const keptRequestId = searchForm.value.request_id
    dateRange.value = null
    searchForm.value = emptyFilter()
    searchForm.value.request_id = keptRequestId
    applyFilter()
  }

  function keepRouteRequest(): void {
    const raw = route.query.request_id
    const value = typeof raw === 'string' ? raw : Array.isArray(raw) ? raw[0] : undefined
    if (typeof value !== 'string' || !value.trim()) return
    searchForm.value.request_id = value.trim()
    applyFilter()
  }

  async function openDetail(row: InvocationRow): Promise<void> {
    const requestId = ++detailRequest
    detail.value = {
      request_at: row.request_at,
      caller_name: row.caller_name,
      purpose: row.purpose,
      model_name: row.model_name,
      latency_ms: row.latency_ms,
      spend_amount: row.spend_amount,
      request_id: row.request_id,
      input_tokens: row.input_tokens,
      output_tokens: row.output_tokens
    }
    cost.value = null
    devError.value = ''
    detailOpen.value = true
    if (!isDevLogin()) return
    try {
      const result = await invocationApi.cost(row.environment_id, row.request_id)
      if (requestId === detailRequest) cost.value = result
    } catch (error) {
      if (requestId === detailRequest) {
        devError.value = errorDisplayText(error, '开发者字段暂时读不到')
      }
    }
  }

  function closeDetail(): void {
    ++detailRequest
    detail.value = null
    cost.value = null
    devError.value = ''
  }

  onBeforeUnmount(closeDetail)
  onMounted(keepRouteRequest)
</script>

<style scoped lang="scss">
  .record-page {
    display: flex;
    flex-direction: column;
    gap: 16px;
  }

  .page-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
  }

  .page-title {
    margin: 0;
    font-size: 20px;
    font-weight: 600;
    color: var(--el-text-color-primary);
  }

  .page-desc {
    margin: 4px 0 0;
    font-size: 13px;
    color: var(--el-text-color-secondary);
  }

  .filter-row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 12px;
  }

  .filter-select {
    width: 140px;
  }
</style>
