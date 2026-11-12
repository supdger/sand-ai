<template>
  <SandAiErrorBoundary>
    <div class="sand-ai-page usage-page">
      <div class="page-head">
        <div>
          <h1 class="page-title">费用</h1>
          <p class="page-desc">看本月花了多少，花在哪里</p>
        </div>
        <div class="page-head-actions">
          <ElButton v-if="canBudgetWrite" type="primary" @click="openBudget">设预算</ElButton>
          <ElButton v-if="canReview && devSurfaceOpen" text @click="goReview()">费用核对</ElButton>
          <ElButton v-if="canView" :loading="exporting" @click="handleExport">导出</ElButton>
        </div>
      </div>

      <template v-if="canView">
        <div class="filter-bar">
          <ElDatePicker v-model="monthPicker" type="month" value-format="YYYY-MM" format="YYYY-MM"
            placeholder="选择月份" clearable :disabled-date="disableFutureMonth" @change="onMonthPicked" />
          <ElButton text @click="moreOpen = !moreOpen">{{ moreOpen ? '收起条件' : '更多条件' }}</ElButton>
          <div v-if="moreOpen" class="more-filters">
            <ElSelect v-model="filterEnvironmentId" clearable filterable placeholder="全部环境" @change="reloadOverview">
              <ElOption v-for="item in environments" :key="item.id" :label="item.name" :value="item.id" />
            </ElSelect>
            <ElSelect v-model="filterApplicationId" clearable filterable placeholder="全部应用" @change="reloadOverview">
              <ElOption v-for="item in applications" :key="item.id" :label="item.name" :value="item.id" />
            </ElSelect>
          </div>
        </div>

        <div v-if="overviewError" class="state-block">
          <p class="state-text">暂时读不到，再试一次</p>
          <ElButton @click="reloadAll">重试</ElButton>
        </div>

        <template v-else>
          <div class="stat-grid">
            <UsageStatCard label="本月花费" :lines="spendLines" :hint="spendHint" :loading="loading" :clickable="devSurfaceOpen" @open="goReview()" />
            <UsageStatCard label="预算余量" :lines="budgetLines" :hint="budgetHint" :tone="budgetTone"
              :loading="budgetLoading" />
            <UsageStatCard label="调用次数" :lines="callLines" :hint="callHint" :loading="loading" :clickable="devSurfaceOpen" @open="goReview()" />
            <UsageStatCard label="花得最多的" :lines="topLines" :hint="topHint" :loading="loading" :clickable="devSurfaceOpen" @open="goTopReview" />
          </div>

          <ElCard shadow="never" class="chart-card sand-ai-page-card">
            <template #header>
              <h2 class="chart-title">每天花费</h2>
            </template>
            <div v-if="(chartEmpty || chartSeries.length === 0) && !loading" class="chart-empty">本月还没有花费</div>
            <ArtLineChart v-else :data="chartSeries" :x-axis-data="chartLabels" height="260px"
              :show-legend="chartSeries.length > 1" :loading="loading" :smooth="false" symbol="circle"
              :animated="false" />
          </ElCard>

          <SpendRanking :rows="rankingRows" :group-by="groupBy" :loading="loading"
            :truncated="rankingTruncated" :month-from="monthBounds?.from ?? ''" :month-to="monthBounds?.to ?? ''"
            @update:group-by="groupBy = $event" />
        </template>
      </template>
      <ElAlert v-else type="info" :closable="false"
        title="你还没有查看费用的权限；如需查看，请联系管理员开通。" class="sand-ai-page-card" />

      <MonthlyBudgetDialog v-if="canBudgetWrite" v-model="budgetOpen" :timezone="timezone"
        :month="selectedMonth" :spend-by-currency="pricedSpendTotals"
        :budget-scope="displayedBudgetScope" :budget-scope-label="budgetScopeText"
        @saved="loadBudget" />
    </div>
  </SandAiErrorBoundary>
</template>

<script setup lang="ts">
  import SandAiErrorBoundary from '../components/SandAiErrorBoundary.vue'
  import '../components/sandAiPage.css'
  import { computed, onMounted, ref, watch } from 'vue'
  import { useRoute, useRouter } from 'vue-router'
  import { ElMessage } from 'element-plus'
  import { useAuth } from '@/hooks/core/useAuth'
  import { useAdminReadIdentity } from '../composables/useAdminReadIdentity'
  import { errorDisplayText } from '../api/errorText'
  import usageApi from '../api/usage'
  import usagePeriodApi from '../api/usage-period'
  import budgetApi, { type BudgetPolicy, type BudgetReference, type BudgetScope, type BudgetScopeKind, type BudgetSummary } from '../api/budget'
  import iamApi from '../api/iam'
  import type { SandIamApplicationReference, SandIamEnvironmentReference } from '../api/iam'
  import type { SandAiUsageSummaryQuery } from '../api/types'
  import type { UsageDailyResult, UsageMonthlyRow, UsageMonthlyResult, UsageGroupBy } from '../api/usage-period'
  import type { LineDataItem } from '@/types/component/chart'
  import UsageStatCard from './modules/usage-stat-card.vue'
  import SpendRanking from './modules/spend-ranking.vue'
  import MonthlyBudgetDialog from './modules/monthly-budget-dialog.vue'
  import { formatMoney, isMonth, monthBoundsUtc, shiftMonth, sumPricedAmounts } from './modules/usagePeriod'

  const { hasAuth } = useAuth()
  const route = useRoute()
  const router = useRouter()
  const devSurfaceOpen = computed(() => route.query.dev === '1')
  const identity = useAdminReadIdentity()

  const canView = computed(() => hasAuth('sand_ai:usage:summary'))
  const canReview = computed(() =>
    hasAuth('sand_ai:usage:index') || hasAuth('sand_ai:usage:summary') ||
    hasAuth('sand_ai:budget:index') || hasAuth('sand_ai:billing:index'))
  const canBudgetRead = computed(() => hasAuth('sand_ai:budget:index'))
  const canBudgetWrite = computed(() => hasAuth('sand_ai:budget:save') && hasAuth('sand_ai:budget:publish'))

  const timezone = ref('UTC')
  const currentMonth = ref('')
  const monthPicker = ref('')
  const selectedMonth = computed(() => (isMonth(monthPicker.value) ? monthPicker.value : currentMonth.value))
  const groupBy = ref<UsageGroupBy>('application')
  const moreOpen = ref(false)
  // ElSelect 清空时会写入空串，查询参数处统一归一化为 undefined
  const filterEnvironmentId = ref<number | ''>()
  const filterApplicationId = ref<number | ''>()
  const environments = ref<SandIamEnvironmentReference[]>([])
  const applications = ref<SandIamApplicationReference[]>([])

  const monthlyApp = ref<UsageMonthlyResult | null>(null)
  const monthlyModel = ref<UsageMonthlyResult | null>(null)
  const dailyResult = ref<UsageDailyResult | null>(null)
  const budgetSummary = ref<BudgetSummary | null>(null)
  // 覆盖当期的已发布预算候选；卡片只展示其中一份并标明范围
  const budgetCandidates = ref<BudgetPolicy[]>([])
  const budgetError = ref('')
  const budgetRefs = ref<{
    capabilities: { code: string; name: string }[]
    models: BudgetReference[]
    organizations: BudgetReference[]
  } | null>(null)
  const loading = ref(false)
  const overviewError = ref('')
  const budgetLoading = ref(false)
  const exporting = ref(false)
  const budgetOpen = ref(false)

  const monthBounds = computed(() =>
    selectedMonth.value ? monthBoundsUtc(selectedMonth.value, timezone.value) : null)

  function filterRows(rows: UsageMonthlyRow[], month: string): UsageMonthlyRow[] {
    return rows.filter(row => row.month === month)
  }

  const currentRows = computed(() =>
    filterRows(monthlyApp.value?.rows ?? [], selectedMonth.value))
  const prevRows = computed(() =>
    filterRows(monthlyApp.value?.rows ?? [], shiftMonth(selectedMonth.value, -1)))
  const rankingSource = computed(() =>
    (groupBy.value === 'application' ? monthlyApp.value : monthlyModel.value)?.rows ?? [])
  const rankingRows = computed(() => filterRows(rankingSource.value, selectedMonth.value))
  const rankingTruncated = computed(() =>
    (groupBy.value === 'application' ? monthlyApp.value : monthlyModel.value)?.truncated ?? false)

  interface CurrencyTotal {
    currency: string | null
    amount: string | null
    call_count: number
    failed_count: number
  }

  function totalsByCurrency(rows: UsageMonthlyRow[]): CurrencyTotal[] {
    const map = new Map<string, { amounts: (string | null)[]; calls: number; failed: number }>()
    for (const row of rows) {
      const key = row.currency ?? ''
      const entry = map.get(key) ?? { amounts: [], calls: 0, failed: 0 }
      entry.amounts.push(row.amount)
      entry.calls += row.call_count
      entry.failed += row.failed_count
      map.set(key, entry)
    }
    return [...map.entries()]
      .map(([currency, entry]) => ({
        currency: currency === '' ? null : currency,
        amount: sumPricedAmounts(entry.amounts),
        call_count: entry.calls,
        failed_count: entry.failed
      }))
      .sort((a, b) => (a.currency ?? '').localeCompare(b.currency ?? ''))
  }

  const spendByCurrency = computed(() => totalsByCurrency(currentRows.value))
  const prevCurrencyTotals = computed(() => totalsByCurrency(prevRows.value))
  // 预算弹窗只需要币种与已定价金额齐全的行
  const pricedSpendTotals = computed(() =>
    spendByCurrency.value.flatMap(item =>
      item.currency !== null && item.amount !== null ? [{ currency: item.currency, amount: item.amount }] : []))

  const spendLines = computed(() => {
    const totals = spendByCurrency.value
    const calls = totals.reduce((sum, item) => sum + item.call_count, 0)
    if (calls === 0) return ['¥0']
    if (totals.every(item => item.amount === null)) return ['暂未计费']
    return totals.filter(item => item.amount !== null).map(item => formatMoney(item.amount, item.currency))
  })

  const spendHint = computed(() => {
    const totals = spendByCurrency.value
    const calls = totals.reduce((sum, item) => sum + item.call_count, 0)
    if (calls === 0) return '本月还没有花费'
    if (totals.every(item => item.amount === null)) return '本月有调用，费用还没定价'
    const prevCalls = prevCurrencyTotals.value.reduce((sum, item) => sum + item.call_count, 0)
    if (prevCalls === 0) return '上月没有花费'
    const diffs = totals
      .filter(item => item.amount !== null)
      .map(item => {
        const prev = prevCurrencyTotals.value.find(entry => entry.currency === item.currency)
        const prevAmount = prev?.amount === null || prev?.amount === undefined ? 0 : Number(prev.amount)
        const diff = Number(item.amount) - prevAmount
        const money = formatMoney(String(Math.abs(diff)), item.currency)
        if (Math.abs(diff) < 0.000001) return `比上月持平`
        return diff > 0 ? `比上月多 ${money}` : `比上月少 ${money}`
      })
    return diffs.length ? diffs.join('；') : ''
  })

  const BUDGET_SCOPE_KIND_LABELS: Record<BudgetScopeKind, string> = {
    organization: '组织', application: '应用', environment: '环境',
    service: '服务', capability: '能力', model: '模型'
  }

  /** 展示预算的人话范围标签；名称查不到时回退范围引用本身。 */
  const budgetScopeText = computed(() => {
    const policy = budgetSummary.value?.policy
    if (!policy) return '服务·SandAI'
    const kindLabel = BUDGET_SCOPE_KIND_LABELS[policy.scope_kind] ?? policy.scope_kind
    let name = ''
    if (policy.scope_kind === 'service') {
      name = 'SandAI'
    } else if (policy.scope_kind === 'capability') {
      name = budgetRefs.value?.capabilities.find(item => item.code === policy.scope_ref)?.name ?? ''
    } else if (policy.scope_kind === 'model') {
      name = budgetRefs.value?.models.find(item => String(item.id) === policy.scope_ref)?.name ?? ''
    } else if (policy.scope_kind === 'environment') {
      name = environments.value.find(item => String(item.id) === policy.scope_ref)?.name ?? ''
    } else if (policy.scope_kind === 'application') {
      name = applications.value.find(item => String(item.id) === policy.scope_ref)?.name ?? ''
    } else if (policy.scope_kind === 'organization') {
      name = budgetRefs.value?.organizations.find(item => String(item.id) === policy.scope_ref)?.name ?? ''
    }
    return `${kindLabel}·${name || policy.scope_ref}`
  })

  /** 「设预算」弹窗编辑的范围：跟随卡片展示的预算，缺省回到 service/sand_ai。 */
  const displayedBudgetScope = computed<BudgetScope | null>(() => {
    const policy = budgetSummary.value?.policy
    return policy ? { scope_kind: policy.scope_kind, scope_ref: policy.scope_ref } : null
  })

  /** 当期存在别的有上限预算时，所选预算无上限不能写成「没有设上限」。 */
  const hasLimitedCandidate = computed(() =>
    budgetCandidates.value.some(policy => policy.limit_amount !== null && policy.id !== budgetSummary.value?.policy.id))

  function subtractMoney(limit: string, spent: string): string {
    const scale = 1000000
    const units = (value: string): number => {
      const parsed = Number(value)
      return Number.isFinite(parsed) ? Math.round(parsed * scale) : 0
    }
    const remain = units(limit) - units(spent)
    const sign = remain < 0 ? '-' : ''
    const abs = Math.abs(remain)
    const whole = Math.trunc(abs / scale)
    const frac = String(abs % scale).padStart(6, '0').replace(/0+$/, '')
    return frac === '' ? `${sign}${whole}` : `${sign}${whole}.${frac}`
  }

  const budgetLines = computed(() => {
    if (!canBudgetRead.value) return ['没有预算查看权限']
    const summary = budgetSummary.value
    if (!summary) return ['没有设上限']
    if (summary.window.limit_amount === null) {
      return [hasLimitedCandidate.value ? '只给部分范围设了预算上限' : '没有设上限']
    }
    const spent = pricedSpendTotals.value.find(item => item.currency === summary.window.currency)?.amount
    const remain = spent === undefined
      ? summary.window.available_amount
      : subtractMoney(summary.window.limit_amount, spent)
    return [`还剩 ${formatMoney(remain, summary.window.currency)}`]
  })

  const budgetTone = computed<'default' | 'warning' | 'danger'>(() => {
    const level = budgetSummary.value?.alert.level
    if (level === 'exhausted') return 'danger'
    if (level === 'warning' || level === 'notice') return 'warning'
    return 'default'
  })

  const budgetHint = computed(() => {
    if (budgetError.value) return '预算暂时读不到，请稍后重试'
    if (!canBudgetRead.value) return '需要预算查看权限'
    const summary = budgetSummary.value
    if (!summary) return '还没有设预算，点右上角「设预算」'
    const money = (value: string | null) => formatMoney(value, summary.window.currency)
    if (summary.window.limit_amount === null) {
      const limitText = hasLimitedCandidate.value ? '这份不设上限，其他范围有上限' : '不设上限'
      return `已花 ${money(summary.window.settled_amount)}，${limitText}`
    }
    const limitText = `本月预算 ${money(summary.window.limit_amount)}`
    const level = summary.alert.level
    if (level === 'exhausted') {
      return summary.policy.enforcement === 'hard'
        ? `${limitText} · 已用完，AI 暂停使用`
        : `${limitText} · 已用完`
    }
    if (level === 'warning' || level === 'notice') return `${limitText} · 快用完了`
    return limitText
  })

  const callTotals = computed(() => totalsByCurrency(currentRows.value)
    .reduce((sum, item) => ({ calls: sum.calls + item.call_count, failed: sum.failed + item.failed_count }),
      { calls: 0, failed: 0 }))

  const callLines = computed(() => [`${callTotals.value.calls.toLocaleString()} 次`])
  const callHint = computed(() => {
    if (callTotals.value.calls === 0) return '本月还没有调用'
    return callTotals.value.failed > 0
      ? `其中没成功 ${callTotals.value.failed.toLocaleString()} 次`
      : '本月调用都成功了'
  })

  /** 「花得最多的」：取当前花费最大的币种，在该币种内找金额最高的一行。 */
  const topSpend = computed(() => {
    const totals = spendByCurrency.value.filter(item => item.amount !== null)
    if (!totals.length) return null
    const primary = [...totals].sort((a, b) => Number(b.amount) - Number(a.amount))[0]
    const rows = rankingRows.value.filter(row => row.currency === primary.currency && row.amount !== null)
    if (!rows.length) return null
    return [...rows].sort((a, b) => Number(b.amount) - Number(a.amount))[0]
  })

  const topLines = computed(() => {
    const top = topSpend.value
    if (!top) return callTotals.value.calls === 0 ? ['还没有花费'] : ['暂未计费']
    const name = top.name ?? '未知'
    return [top.deleted ? `${name}（已删除）` : name, formatMoney(top.amount, top.currency)]
  })

  const topHint = computed(() => topSpend.value ? '花得最多的一笔用途' : '')

  function currencyLabel(currency: string): string {
    if (currency === '') return '暂未计费'
    if (currency === 'CNY') return '元'
    if (currency === 'USD') return '美元'
    if (currency === 'EUR') return '欧元'
    if (currency === 'JPY') return '日元'
    return currency
  }

  function chartToday(): string {
    const now = new Date()
    const pad = (value: number): string => String(value).padStart(2, '0')
    return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`
  }

  const chartDates = computed(() => {
    const today = chartToday()
    return [...new Set((dailyResult.value?.rows ?? []).map(row => row.date))]
      .filter(date => date <= today)
      .sort()
  })

  const chartLabels = computed(() => chartDates.value.map(date => {
    const [, month, day] = date.split('-')
    return `${Number(month)}月${Number(day)}日`
  }))

  const chartSeries = computed<LineDataItem[]>(() => {
    const rows = dailyResult.value?.rows ?? []
    const dates = chartDates.value
    const currencies = [...new Set(rows.map(row => row.currency ?? ''))].sort()
    return currencies.map(currency => ({
      name: currencyLabel(currency),
      data: dates.map(date => {
        const row = rows.find(item => item.date === date && (item.currency ?? '') === currency)
        return row?.amount === null || row?.amount === undefined ? 0 : Number(row.amount)
      })
    })).filter(series => series.data.some(value => value !== 0))
  })

  const chartEmpty = computed(() =>
    (dailyResult.value?.rows ?? []).every(row => row.call_count === 0))

  async function loadOverview(owner: ReturnType<typeof identity.capture>): Promise<void> {
    if (owner === null || !canView.value || !selectedMonth.value) return
    loading.value = true
    overviewError.value = ''
    try {
      const query = {
        month_from: shiftMonth(selectedMonth.value, -1),
        month_to: selectedMonth.value,
        environment_id: filterEnvironmentId.value || undefined,
        application_id: filterApplicationId.value || undefined
      }
      const [app, model, daily] = await Promise.all([
        usagePeriodApi.monthly({ ...query, group_by: 'application' }),
        usagePeriodApi.monthly({ ...query, group_by: 'model' }),
        usagePeriodApi.daily({
          month: selectedMonth.value,
          environment_id: filterEnvironmentId.value || undefined,
          application_id: filterApplicationId.value || undefined
        })
      ])
      if (!identity.current(owner)) return
      monthlyApp.value = app
      monthlyModel.value = model
      dailyResult.value = daily
    } catch (cause) {
      if (identity.current(owner)) {
        overviewError.value = errorDisplayText(cause, '费用暂时读不到')
      }
    } finally {
      if (identity.current(owner)) loading.value = false
    }
  }

  async function discover(): Promise<void> {
    const owner = identity.capture()
    if (owner === null || !canView.value) return
    try {
      const result = await usagePeriodApi.daily({})
      if (!identity.current(owner)) return
      timezone.value = result.timezone || 'UTC'
      const month = result.date_from.slice(0, 7)
      if (isMonth(month)) currentMonth.value = month
      dailyResult.value = result
      await loadOverview(owner)
    } catch (cause) {
      if (identity.current(owner)) {
        overviewError.value = errorDisplayText(cause, '费用暂时读不到')
      }
    }
  }

  function reloadOverview(): void {
    const owner = identity.capture()
    if (owner !== null) void loadOverview(owner)
  }

  function reloadAll(): void {
    monthlyApp.value = null
    monthlyModel.value = null
    dailyResult.value = null
    void discover()
  }

  /** 能力/模型/组织的中文名只在卡片展示这类范围时才需要，懒加载一次。 */
  let budgetRefsLoading = false
  async function ensureBudgetRefs(): Promise<void> {
    if (budgetRefs.value !== null || budgetRefsLoading) return
    budgetRefsLoading = true
    try {
      const [refs, orgs] = await Promise.all([budgetApi.references(1), budgetApi.organizations(1)])
      budgetRefs.value = {
        capabilities: refs.capabilities,
        models: refs.models.data,
        organizations: orgs.data
      }
    } catch {
      budgetRefs.value = { capabilities: [], models: [], organizations: [] }
    } finally {
      budgetRefsLoading = false
    }
  }

  /**
   * 预算范围是否覆盖当前环境/应用筛选。service、capability、model 全局生效；
   * 组织与应用范围经 SandIAM 目录核对归属，目录查不到时保留展示，不误藏生效预算。
   */
  function budgetScopeApplies(policy: BudgetPolicy): boolean {
    const envFilter = filterEnvironmentId.value ? String(filterEnvironmentId.value) : ''
    const appFilter = filterApplicationId.value ? String(filterApplicationId.value) : ''
    if (envFilter === '' && appFilter === '') return true
    if (policy.environment_id !== null || policy.scope_kind === 'environment') {
      const target = policy.environment_id !== null ? String(policy.environment_id) : policy.scope_ref
      if (envFilter !== '') return target === envFilter
      if (appFilter === '') return true
      const env = environments.value.find(item => String(item.id) === target)
      return env === undefined || String(env.application_id) === appFilter
    }
    if (policy.scope_kind === 'service' || policy.scope_kind === 'capability' || policy.scope_kind === 'model') return true
    if (envFilter !== '') {
      const env = environments.value.find(item => String(item.id) === envFilter)
      if (env === undefined) return true
      if (policy.scope_kind === 'application') return String(env.application_id) === policy.scope_ref
      const app = applications.value.find(item => item.id === env.application_id)
      return app === undefined || String(app.organization_id) === policy.scope_ref
    }
    if (policy.scope_kind === 'application') return policy.scope_ref === appFilter
    const app = applications.value.find(item => String(item.id) === appFilter)
    return app === undefined || String(app.organization_id) === policy.scope_ref
  }

  async function loadBudget(): Promise<void> {
    if (!canBudgetRead.value) return
    const owner = identity.capture()
    if (owner === null) return
    budgetLoading.value = true
    budgetError.value = ''
    try {
      const policies: BudgetPolicy[] = []
      for (let page = 1; page <= 10; page++) {
        const result = await budgetApi.listPublished(page)
        if (!identity.current(owner)) return
        policies.push(...result.data)
        if (policies.length >= result.total || result.data.length === 0) break
      }
      // 时段按 UTC 字符串比较，与后端 period_start/period_end 同格式
      const now = new Date().toISOString().slice(0, 19).replace('T', ' ')
      const candidates = policies.filter(policy =>
        policy.state === 'published'
        && policy.period_start <= now && policy.period_end > now
        && budgetScopeApplies(policy))
      budgetCandidates.value = candidates
      // 优先展示有上限的生效预算（全服务范围最能代表整体约束）；
      // 都没有上限时回到全服务预算，再没有取接口序第一份，范围标签如实标明
      const isServiceScope = (policy: BudgetPolicy): boolean =>
        policy.scope_kind === 'service' && policy.scope_ref === 'sand_ai'
      const picked = candidates.find(policy => isServiceScope(policy) && policy.limit_amount !== null)
        ?? candidates.find(policy => policy.limit_amount !== null)
        ?? candidates.find(isServiceScope)
        ?? candidates[0] ?? null
      if (picked === null) {
        budgetSummary.value = null
        return
      }
      if (picked.scope_kind === 'capability' || picked.scope_kind === 'model' || picked.scope_kind === 'organization') {
        void ensureBudgetRefs()
      }
      const summary = await budgetApi.summary(picked.id)
      if (!identity.current(owner)) return
      if (summary.policy.id !== picked.id || summary.policy.state !== 'published') {
        throw new Error('预算窗口与所选版本不一致')
      }
      budgetSummary.value = summary
    } catch {
      if (identity.current(owner)) {
        budgetSummary.value = null
        budgetCandidates.value = []
        budgetError.value = '预算暂时读不到'
      }
    } finally {
      if (identity.current(owner)) budgetLoading.value = false
    }
  }

  async function loadFilterOptions(): Promise<void> {
    const owner = identity.capture()
    if (owner === null || !canView.value) return
    try {
      const [scope, apps] = await Promise.all([
        usageApi.scopes(),
        iamApi.listApplications({ page: 1, limit: 100 })
      ])
      if (!identity.current(owner)) return
      environments.value = scope.environments
      applications.value = apps.data
    } catch {
      // 筛选项读取失败不阻塞主内容，条件下拉保持为空
    }
  }

  function monthBoundsQuery(): { from_time: string; to_time: string } | null {
    const bounds = monthBounds.value
    return bounds ? { from_time: bounds.from, to_time: bounds.to } : null
  }

  function goReview(extra: Record<string, string> = {}): void {
    const range = monthBoundsQuery()
    void router.push({
      path: '/sand-ai/usage-review',
      query: { tab: 'usage', ...(range ?? {}), ...extra }
    })
  }

  function goTopReview(): void {
    const top = topSpend.value
    if (!top) return
    const key = groupBy.value === 'application' ? 'application_id' : 'model_id'
    const id = groupBy.value === 'application' ? top.application_id : top.model_id
    if (typeof id !== 'number') { goReview(); return }
    goReview({ [key]: String(id) })
  }

  function openBudget(): void {
    if (canBudgetWrite.value) budgetOpen.value = true
  }

  function onMonthPicked(): void {
    reloadOverview()
  }

  function disableFutureMonth(date: Date): boolean {
    if (!currentMonth.value) return false
    const month = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`
    return month > currentMonth.value
  }

  async function handleExport(): Promise<void> {
    if (exporting.value || !canView.value) return
    const owner = identity.capture()
    const range = monthBoundsQuery()
    if (owner === null || !range) return
    const query: SandAiUsageSummaryQuery = {
      service_code: 'sand_ai',
      from_time: range.from_time,
      to_time: range.to_time,
      environment_id: filterEnvironmentId.value || undefined,
      application_id: filterApplicationId.value || undefined
    }
    exporting.value = true
    try {
      const blob = await usageApi.exportSummary(query)
      if (!identity.current(owner)) return
      const url = URL.createObjectURL(blob)
      const link = document.createElement('a')
      try {
        link.href = url
        link.download = `sand-ai-usage-${selectedMonth.value}.csv`
        document.body.append(link)
        link.click()
      } finally {
        link.remove()
        window.setTimeout(() => URL.revokeObjectURL(url), 1000)
      }
    } catch (cause) {
      if (identity.current(owner)) ElMessage.error(errorDisplayText(cause, '导出失败，请重试'))
    } finally {
      if (identity.current(owner)) exporting.value = false
    }
  }

  watch(identity.epoch, () => {
    monthlyApp.value = null
    monthlyModel.value = null
    dailyResult.value = null
    budgetSummary.value = null
    budgetCandidates.value = []
    budgetError.value = ''
    budgetRefs.value = null
    monthPicker.value = ''
    currentMonth.value = ''
    timezone.value = 'UTC'
    overviewError.value = ''
    budgetOpen.value = false
    void discover()
    void loadBudget()
  }, { flush: 'sync' })

  // 环境/应用筛选会改变「当期生效预算」的候选范围，卡片跟随刷新
  watch([filterEnvironmentId, filterApplicationId], () => { void loadBudget() })

  onMounted(() => {
    if (canView.value) {
      void discover()
      void loadFilterOptions()
    }
    void loadBudget()
  })
</script>

<style scoped>
  .usage-page {
    min-height: 0;
  }

  .page-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 20px;
  }

  .page-title {
    margin: 0;
    font-size: 20px;
    font-weight: 600;
  }

  .page-desc {
    margin: 6px 0 0;
    font-size: 13px;
    color: var(--el-text-color-secondary);
  }

  .page-head-actions {
    display: flex;
    gap: 8px;
    flex-shrink: 0;
  }

  .filter-bar {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 20px;
    flex-wrap: wrap;
  }

  .more-filters {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
  }

  .more-filters .el-select {
    width: 200px;
  }

  .stat-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 16px;
    margin-bottom: 20px;
  }

  @media (max-width: 1100px) {
    .stat-grid {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }
  }

  .chart-card {
    margin-bottom: 20px;
  }

  .chart-title {
    margin: 0;
    font-size: 15px;
    font-weight: 600;
  }

  .chart-empty {
    height: 260px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--el-text-color-secondary);
    font-size: 13px;
  }

  .state-block {
    padding: 48px 0;
    text-align: center;
  }

  .state-text {
    margin: 0 0 12px;
    color: var(--el-text-color-secondary);
  }
</style>
