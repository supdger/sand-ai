<template>
  <ElCard shadow="never" class="sand-ai-page-card">
    <template #header>
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 class="m-0 text-base">花费排行</h2>
          <p class="mt-1 mb-0 text-sm text-gray-500">按{{ groupBy === 'application' ? '应用' : '模型' }}看本月谁花得最多。</p>
        </div>
        <ElRadioGroup :model-value="groupBy" size="small" @update:model-value="onGroupByChange">
          <ElRadioButton value="application">按应用</ElRadioButton>
          <ElRadioButton value="model">按模型</ElRadioButton>
        </ElRadioGroup>
      </div>
    </template>
    <ElAlert v-if="truncated" type="info" :closable="false" class="mb-3"
      title="本月数据较多，只显示花费最高的一部分。" />
    <ElTable v-loading="loading" :data="pagedRows" size="default" empty-text="本月还没有花费">
      <ElTableColumn :label="groupBy === 'application' ? '应用' : '模型'" min-width="160" show-overflow-tooltip>
        <template #default="{ row }">{{ dimensionName(row) }}</template>
      </ElTableColumn>
      <ElTableColumn label="币种" width="80">
        <template #default="{ row }">{{ row.currency ?? '—' }}</template>
      </ElTableColumn>
      <ElTableColumn label="调用次数" width="110" align="right">
        <template #default="{ row }">{{ row.call_count.toLocaleString() }}</template>
      </ElTableColumn>
      <ElTableColumn label="花费" width="130" align="right">
        <template #default="{ row }">{{ formatMoney(row.amount, row.currency) }}</template>
      </ElTableColumn>
      <ElTableColumn label="占比" width="90" align="right">
        <template #default="{ row }">{{ shareOf(row) }}</template>
      </ElTableColumn>
      <ElTableColumn label="操作" width="110" align="center">
        <template #default="{ row }">
          <ElButton v-if="canViewCalls" link type="primary" @click="viewCalls(row)">查看调用</ElButton>
          <span v-else class="text-xs text-gray-400">无权限</span>
        </template>
      </ElTableColumn>
    </ElTable>
    <ElPagination v-if="sortedRows.length > pageSize" class="mt-3" background
      layout="prev, pager, next, total" :current-page="page" :page-size="pageSize"
      :total="sortedRows.length" @current-change="page = $event" />
  </ElCard>
</template>

<script setup lang="ts">
  import { computed, ref, watch } from 'vue'
  import { useRouter } from 'vue-router'
  import { useAuth } from '@/hooks/core/useAuth'
  import type { UsageMonthlyRow, UsageGroupBy } from '../../api/usage-period'
  import { formatMoney } from './usagePeriod'

  /**
   * 「花费排行」：本月按应用或模型聚合的花费表。
   * 「查看调用」跳转费用核对页并带上月份与维度筛选。
   */
  const props = defineProps<{
    rows: UsageMonthlyRow[]
    groupBy: UsageGroupBy
    loading: boolean
    truncated: boolean
    /** 本月 UTC 起止（'YYYY-MM-DD HH:mm:ss'），用于跳转核对页 */
    monthFrom: string
    monthTo: string
  }>()
  const emit = defineEmits<{ 'update:groupBy': [value: UsageGroupBy] }>()

  const router = useRouter()
  const { hasAuth } = useAuth()
  const canViewCalls = computed(() => hasAuth('sand_ai:usage:index'))
  const pageSize = 20
  const page = ref(1)

  watch(() => [props.rows, props.groupBy], () => { page.value = 1 })

  function onGroupByChange(value: string | number | boolean): void {
    if (value === 'application' || value === 'model') emit('update:groupBy', value)
  }

  function dimensionName(row: UsageMonthlyRow): string {
    const name = row.name ?? '未知'
    return row.deleted ? `${name}（已删除）` : name
  }

  /** 同币种内按金额降序；币种之间按币种码排序分组。 */
  const sortedRows = computed(() => {
    return [...props.rows].sort((a, b) => {
      const currencyOrder = (a.currency ?? '').localeCompare(b.currency ?? '')
      if (currencyOrder !== 0) return currencyOrder
      return Number(b.amount ?? 0) - Number(a.amount ?? 0)
    })
  })

  const pagedRows = computed(() => {
    const start = (page.value - 1) * pageSize
    return sortedRows.value.slice(start, start + pageSize)
  })

  /** 占比 = 该行金额 / 同币种全部行金额合计；未定价行显示「—」。 */
  function shareOf(row: UsageMonthlyRow): string {
    if (row.amount === null || row.currency === null) return '—'
    const total = props.rows
      .filter(item => item.currency === row.currency && item.amount !== null)
      .reduce((sum, item) => sum + Number(item.amount ?? 0), 0)
    if (!Number.isFinite(total) || total <= 0) return '—'
    const share = (Number(row.amount) / total) * 100
    return `${share >= 10 ? share.toFixed(0) : share.toFixed(1)}%`
  }

  function viewCalls(row: UsageMonthlyRow): void {
    const query: Record<string, string> = {
      tab: 'usage',
      from_time: props.monthFrom,
      to_time: props.monthTo
    }
    if (props.groupBy === 'application' && typeof row.application_id === 'number') {
      query.application_id = String(row.application_id)
    }
    if (props.groupBy === 'model' && typeof row.model_id === 'number') {
      query.model_id = String(row.model_id)
    }
    void router.push({ path: '/sand-ai/usage-review', query })
  }
</script>
