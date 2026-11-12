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
      <ElTableColumn label="调用次数" width="110" align="right">
        <template #default="{ row }">{{ row.callCount.toLocaleString() }}</template>
      </ElTableColumn>
      <ElTableColumn label="花费" width="170" align="right">
        <template #default="{ row }">
          <div v-for="(line, index) in spendLines(row)" :key="index">{{ line }}</div>
        </template>
      </ElTableColumn>
      <ElTableColumn label="占比" width="90" align="right">
        <template #default="{ row }">
          <div v-for="(line, index) in shareLines(row)" :key="index">{{ line }}</div>
        </template>
      </ElTableColumn>
      <ElTableColumn label="操作" width="110" align="center">
        <template #default="{ row }">
          <ElButton v-if="canViewCalls" link type="primary" @click="viewCalls(row)">查看调用</ElButton>
          <span v-else class="text-xs text-gray-400">无权限</span>
        </template>
      </ElTableColumn>
    </ElTable>
    <ElPagination v-if="groupedRows.length > pageSize" class="mt-3" background
      layout="prev, pager, next, total" :current-page="page" :page-size="pageSize"
      :total="groupedRows.length" @current-change="page = $event" />
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
   * 同一维度的多币种行并成一行，金额按币种分行；未计价调用只标次数。
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

  interface RankingRow {
    key: string
    name: string | null
    deleted: boolean
    application_id: number | null | undefined
    model_id: number | null | undefined
    callCount: number
    unpricedCount: number
    amounts: { currency: string; amount: number }[]
  }

  function topAmount(row: RankingRow): number {
    return row.amounts.reduce((max, item) => Math.max(max, item.amount), 0)
  }

  /** 按维度合并：每个应用/模型一行；金额按币种累加，未计价行的调用数单列。 */
  const groupedRows = computed<RankingRow[]>(() => {
    const map = new Map<string, RankingRow>()
    for (const row of props.rows) {
      const key = props.groupBy === 'application'
        ? `a-${row.application_id ?? row.name ?? ''}`
        : `m-${row.model_id ?? row.name ?? ''}`
      const entry = map.get(key) ?? {
        key,
        name: row.name,
        deleted: false,
        application_id: row.application_id,
        model_id: row.model_id,
        callCount: 0,
        unpricedCount: 0,
        amounts: []
      }
      entry.callCount += row.call_count
      entry.deleted = entry.deleted || row.deleted
      // 无币种行整行都是未计价调用；有币种的行按行内未计价数计
      entry.unpricedCount += row.currency === null ? row.call_count : row.unpriced_count
      if (row.currency !== null && row.amount !== null) {
        const value = Number(row.amount)
        const bucket = entry.amounts.find(item => item.currency === row.currency)
        if (bucket) bucket.amount += value
        else if (Number.isFinite(value)) entry.amounts.push({ currency: row.currency, amount: value })
      }
      map.set(key, entry)
    }
    return [...map.values()].sort((a, b) => topAmount(b) - topAmount(a))
  })

  watch(() => [props.rows, props.groupBy], () => { page.value = 1 })

  function onGroupByChange(value: string | number | boolean): void {
    if (value === 'application' || value === 'model') emit('update:groupBy', value)
  }

  function dimensionName(row: RankingRow): string {
    const name = row.name ?? '未知'
    return row.deleted ? `${name}（已删除）` : name
  }

  const pagedRows = computed(() => {
    const start = (page.value - 1) * pageSize
    return groupedRows.value.slice(start, start + pageSize)
  })

  /** 金额列：每个已计价币种一行，最后补一行未计价调用次数。 */
  function spendLines(row: RankingRow): string[] {
    const lines = row.amounts.map(item => formatMoney(String(item.amount), item.currency))
    if (row.unpricedCount > 0) lines.push(`另 ${row.unpricedCount.toLocaleString()} 次暂未计费`)
    return lines.length ? lines : ['—']
  }

  /** 占比与金额列同币种顺序对齐：该行金额 / 同币种全部行金额合计。 */
  function shareLines(row: RankingRow): string[] {
    if (!row.amounts.length) return ['—']
    return row.amounts.map(item => {
      const total = groupedRows.value.reduce((sum, group) => {
        const found = group.amounts.find(entry => entry.currency === item.currency)
        return sum + (found?.amount ?? 0)
      }, 0)
      if (!Number.isFinite(total) || total <= 0) return '—'
      const share = (item.amount / total) * 100
      return `${share >= 10 ? share.toFixed(0) : share.toFixed(1)}%`
    })
  }

  function viewCalls(row: RankingRow): void {
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
