<template>
  <div class="platform-validation-panel">
    <ElCard shadow="never">
      <h3 class="m-0 text-base">模型验证费用</h3>
      <p class="mt-2 mb-3 text-sm text-gray-500">
        查看管理员验证模型时产生的产品计量。这里是平台验证费用，不计入应用或环境的业务费用。
        查询与查看明细不会再次验证模型。
      </p>
      <ElAlert
        title="历史未追踪的验证不补算费用，空列表不代表历史验证免费。计量金额不是服务商现金账单，现金账单尚未核对；没有同条件基准，不能计算节省。"
        type="info"
        :closable="false"
      />
      <ElForm label-position="top" class="mt-4" @submit.prevent="load()">
        <div class="validation-filters">
          <ElFormItem label="发生时间（UTC）">
            <ElSelect v-model="filters.range" aria-label="模型验证费用时间范围">
              <ElOption label="近 24 小时" value="recent" />
              <ElOption label="指定时间" value="custom" />
            </ElSelect>
          </ElFormItem>
          <ElFormItem label="服务商 ID">
            <ElInputNumber
              v-model="filters.provider_id"
              :min="1"
              :controls="false"
              placeholder="全部服务商"
            />
          </ElFormItem>
          <ElFormItem label="模型 ID">
            <ElInputNumber
              v-model="filters.model_id"
              :min="1"
              :controls="false"
              placeholder="全部模型"
            />
          </ElFormItem>
          <ElFormItem label="部署 ID">
            <ElInputNumber
              v-model="filters.deployment_id"
              :min="1"
              :controls="false"
              placeholder="全部部署"
            />
          </ElFormItem>
          <ElFormItem label="管理员 ID">
            <ElInputNumber
              v-model="filters.admin_actor_id"
              :min="1"
              :controls="false"
              placeholder="全部管理员"
            />
          </ElFormItem>
          <ElFormItem label="能力">
            <ElSelect v-model="filters.capability_code" clearable placeholder="全部能力">
              <ElOption label="聊天" value="chat" />
              <ElOption label="向量生成" value="embedding" />
              <ElOption label="音频转写" value="audio_transcription" />
              <ElOption label="语音合成" value="audio_speech" />
            </ElSelect>
          </ElFormItem>
          <ElFormItem label="派发与计量状态">
            <ElSelect v-model="filters.state" clearable placeholder="全部状态">
              <ElOption label="待确认" value="pending" />
              <ElOption label="未派发" value="not_dispatched" />
              <ElOption label="派发结果不确定" value="ambiguous" />
              <ElOption label="已有计量记录" value="recorded" />
            </ElSelect>
          </ElFormItem>
        </div>
        <div v-if="filters.range === 'custom'" class="validation-time-fields">
          <ElFormItem label="开始时间（UTC，包含）">
            <ElInput
              :id="`${formId}-from`"
              ref="fromInput"
              v-model="filters.from"
              placeholder="YYYY-MM-DD HH:mm:ss"
              :aria-describedby="`${formId}-time-help`"
            />
          </ElFormItem>
          <ElFormItem label="结束时间（UTC，不包含）">
            <ElInput
              :id="`${formId}-to`"
              ref="toInput"
              v-model="filters.to"
              placeholder="YYYY-MM-DD HH:mm:ss"
              :aria-describedby="`${formId}-time-help`"
            />
          </ElFormItem>
        </div>
        <p :id="`${formId}-time-help`" class="mt-0 mb-3 text-sm text-gray-500">
          时间使用 UTC，结束时间不包含，范围最多 366
          天。目标尚未登记的临时验证，其服务商、模型和部署 ID 保持未登记。
        </p>
        <ElButton
          type="primary"
          native-type="submit"
          :loading="loading"
          :disabled="!canIndex || loading"
        >
          查询模型验证费用
        </ElButton>
        <ElButton native-type="button" :disabled="loading" @click="resetFilters">重置</ElButton>
      </ElForm>
      <ElAlert
        v-if="validationError"
        :title="validationError"
        type="warning"
        :closable="false"
        class="mt-3"
      />
      <p v-if="filtersChanged" class="mt-3 mb-0 text-sm" role="status">
        筛选已修改，请重新查询；原结果已清除。
      </p>
      <p v-if="loading" class="mt-3 mb-0 text-sm" role="status">正在读取模型验证费用</p>
      <ElAlert
        v-if="!canIndex"
        title="需要用量查看权限（sand_ai:usage:index）才能读取模型验证费用。"
        type="warning"
        :closable="false"
        class="mt-3"
      />
    </ElCard>

    <ElCard v-if="canIndex" shadow="never">
      <h3 class="m-0 mb-3 text-base">平台验证汇总</h3>
      <p v-if="!canSummary" class="m-0 text-sm text-gray-500">
        当前账号可查看验证记录；费用汇总另需 sand_ai:usage:summary 权限。
      </p>
      <ElAlert v-if="summaryError" :title="summaryError" type="warning" :closable="false" />
      <template v-if="summary">
        <p class="mt-0 mb-3 text-sm">
          汇总范围（UTC）：{{ summary.from }} 至 {{ summary.to }}（不含结束时间）
        </p>
        <ElDescriptions :column="narrowScreen ? 1 : 3" border class="mb-3">
          <ElDescriptionsItem label="验证尝试">{{ summary.total_attempts }}</ElDescriptionsItem>
          <ElDescriptionsItem label="计量事件">{{ summary.total_events }}</ElDescriptionsItem>
          <ElDescriptionsItem label="已有计量记录">{{
            summary.states.recorded
          }}</ElDescriptionsItem>
          <ElDescriptionsItem label="待确认">{{ summary.states.pending }}</ElDescriptionsItem>
          <ElDescriptionsItem label="未派发">{{
            summary.states.not_dispatched
          }}</ElDescriptionsItem>
          <ElDescriptionsItem label="派发结果不确定">{{
            summary.states.ambiguous
          }}</ElDescriptionsItem>
        </ElDescriptions>
        <p class="mb-3 text-sm text-gray-500">
          同一验证尝试只计一次。待确认、未派发及派发结果不确定的尝试没有计量事件，
          不补造零费用；各币种分别展示，不与业务费用相加。
        </p>
        <ElTable :data="summary.overall" border stripe empty-text="所选范围暂无平台验证计量事件">
          <ElTableColumn label="币种" width="110">
            <template #default="{ row }">{{ row.currency ?? '未知' }}</template>
          </ElTableColumn>
          <ElTableColumn prop="total_events" label="计量事件" width="110" />
          <ElTableColumn prop="unmeasured_events" label="未测得用量" width="120" />
          <ElTableColumn prop="unpriced_events" label="未定价" width="100" />
          <ElTableColumn label="计量金额" min-width="190">
            <template #default="{ row }">{{ summaryAmount(row) }}</template>
          </ElTableColumn>
          <ElTableColumn label="已核对现金账单金额" min-width="175">
            <template #default="{ row }">{{ summaryBill(row) }}</template>
          </ElTableColumn>
        </ElTable>
      </template>
      <p v-else-if="canSummary && !loading && !summaryError" class="m-0 text-sm text-gray-500">
        查询后显示平台验证费用汇总。
      </p>
    </ElCard>

    <ElCard v-if="canIndex" shadow="never">
      <h3 class="m-0 mb-3 text-base">验证记录</h3>
      <ElAlert v-if="listError" :title="listError" type="warning" :closable="false" class="mb-3" />
      <ElTable
        v-if="pageData"
        :data="pageData.data"
        border
        stripe
        row-key="id"
        empty-text="所选范围暂无已纳入产品计量的模型验证尝试"
      >
        <ElTableColumn prop="id" label="尝试 ID" width="105" />
        <ElTableColumn prop="call_started_at" label="调用开始（UTC）" min-width="185" />
        <ElTableColumn prop="admin_actor_id" label="管理员 ID" width="120" />
        <ElTableColumn label="验证目标" min-width="150">
          <template #default="{ row }">{{ targetLabel(row) }}</template>
        </ElTableColumn>
        <ElTableColumn label="能力" min-width="130">
          <template #default="{ row }">{{ capabilityLabel(row.capability_code) }}</template>
        </ElTableColumn>
        <ElTableColumn label="派发与计量状态" min-width="160">
          <template #default="{ row }">{{ stateLabel(row.platform_usage.state) }}</template>
        </ElTableColumn>
        <ElTableColumn label="价格状态" width="115">
          <template #default="{ row }">{{ pricingLabel(row.platform_usage) }}</template>
        </ElTableColumn>
        <ElTableColumn label="计量金额" min-width="155">
          <template #default="{ row }">{{ meteredMoney(row.platform_usage) }}</template>
        </ElTableColumn>
        <ElTableColumn label="现金账单" width="110"
          ><template #default>未核对</template></ElTableColumn
        >
        <ElTableColumn label="明细" width="110" fixed="right">
          <template #default="{ row }">
            <ElButton size="small" @click="openDetails(row, $event)">查看明细</ElButton>
          </template>
        </ElTableColumn>
      </ElTable>
      <p v-else-if="!loading && !listError" class="m-0 text-sm text-gray-500"
        >查询后显示模型验证记录。</p
      >
      <ElPagination
        v-if="pageData && pageData.total > 0"
        class="mt-4 validation-pagination"
        :current-page="pageData.current_page"
        :page-size="pageSize"
        :total="pageData.total"
        :page-sizes="[10, 20, 50, 100]"
        :layout="narrowScreen ? 'prev, pager, next' : 'total, sizes, prev, pager, next'"
        :disabled="loading"
        @current-change="changePage"
        @size-change="changeSize"
      />
    </ElCard>

    <ElDrawer
      v-model="detailOpen"
      title="模型验证费用明细"
      :size="narrowScreen ? '100%' : 'min(720px, 100vw)'"
      @close="closeDetails"
      @closed="restoreFocus"
    >
      <template v-if="selected">
        <ElAlert
          :title="usageNotice(selected.platform_usage)"
          type="info"
          :closable="false"
          class="mb-3"
        />
        <ElDescriptions :column="narrowScreen ? 1 : 2" border>
          <ElDescriptionsItem label="费用范围">平台管理员验证</ElDescriptionsItem>
          <ElDescriptionsItem label="尝试 ID">{{ selected.id }}</ElDescriptionsItem>
          <ElDescriptionsItem label="管理员 ID">{{ selected.admin_actor_id }}</ElDescriptionsItem>
          <ElDescriptionsItem label="计量事件 ID">{{
            idLabel(selected.platform_usage.usage_event_id)
          }}</ElDescriptionsItem>
          <ElDescriptionsItem label="验证目标">{{ targetLabel(selected) }}</ElDescriptionsItem>
          <ElDescriptionsItem label="部署修订">{{
            idLabel(selected.deployment_revision)
          }}</ElDescriptionsItem>
          <ElDescriptionsItem label="服务商 ID">{{
            idLabel(selected.provider_id)
          }}</ElDescriptionsItem>
          <ElDescriptionsItem label="模型 ID">{{ idLabel(selected.model_id) }}</ElDescriptionsItem>
          <ElDescriptionsItem label="能力">{{
            capabilityLabel(selected.capability_code)
          }}</ElDescriptionsItem>
          <ElDescriptionsItem label="服务动作">{{ selected.service_action }}</ElDescriptionsItem>
          <ElDescriptionsItem label="调用开始（UTC）" :span="2">{{
            selected.call_started_at
          }}</ElDescriptionsItem>
          <ElDescriptionsItem label="派发与计量状态">{{
            stateLabels[selected.platform_usage.state]
          }}</ElDescriptionsItem>
          <ElDescriptionsItem label="用量状态">{{
            usageLabel(selected.platform_usage)
          }}</ElDescriptionsItem>
          <ElDescriptionsItem label="输入 Token">{{
            tokenLabel(selected.input_tokens, selected.platform_usage)
          }}</ElDescriptionsItem>
          <ElDescriptionsItem label="输出 Token">{{
            tokenLabel(selected.output_tokens, selected.platform_usage)
          }}</ElDescriptionsItem>
          <ElDescriptionsItem label="合计 Token">{{
            tokenLabel(selected.total_tokens, selected.platform_usage)
          }}</ElDescriptionsItem>
          <ElDescriptionsItem label="价格状态">{{
            pricingLabel(selected.platform_usage)
          }}</ElDescriptionsItem>
          <ElDescriptionsItem label="冻结价格版本">{{
            idLabel(selected.platform_usage.price_revision_id)
          }}</ElDescriptionsItem>
          <ElDescriptionsItem label="币种">{{
            selected.platform_usage.currency ?? '未知'
          }}</ElDescriptionsItem>
          <ElDescriptionsItem label="预估金额">尚未提供</ElDescriptionsItem>
          <ElDescriptionsItem label="计量金额">{{
            meteredMoney(selected.platform_usage)
          }}</ElDescriptionsItem>
          <ElDescriptionsItem label="已核对现金账单金额">尚未核对</ElDescriptionsItem>
          <ElDescriptionsItem label="节省金额">无同条件基准，无法计算</ElDescriptionsItem>
        </ElDescriptions>
        <p class="mt-3 text-sm text-gray-500">
          此处只读既有费用事实，不重新发起验证。管理员验证计量与应用环境业务计量分开；
          费用记录不证明模型验证或发布成功，计量金额也不证明现金已支付或已退款。
        </p>
      </template>
    </ElDrawer>
  </div>
</template>

<script setup lang="ts">
  import {
    computed,
    onActivated,
    onBeforeUnmount,
    onDeactivated,
    reactive,
    ref,
    useId,
    watch
  } from 'vue'
  import type { InputInstance } from 'element-plus'
  import { useWindowSize } from '@vueuse/core'
  import { useAuth } from '@/hooks/core/useAuth'
  import { useAdminReadIdentity } from '../../composables/useAdminReadIdentity'
  import { HttpError } from '@/utils/http/error'
  import platformValidationApi, {
    type PlatformValidationCapability,
    type PlatformValidationPage,
    type PlatformValidationQuery,
    type PlatformValidationRow,
    type PlatformValidationState,
    type PlatformValidationSummary,
    type PlatformValidationUsage
  } from '../../api/platform-validation'
  import type { SandAiUsageSummaryRow } from '../../api/types'

  type Filters = {
    range: 'recent' | 'custom'
    from: string
    to: string
    provider_id?: number | null
    model_id?: number | null
    deployment_id?: number | null
    admin_actor_id?: number | null
    capability_code: PlatformValidationCapability | ''
    state: Exclude<PlatformValidationState, 'untracked'> | ''
  }
  const emptyFilters = (): Filters => ({
    range: 'recent',
    from: '',
    to: '',
    provider_id: undefined,
    model_id: undefined,
    deployment_id: undefined,
    admin_actor_id: undefined,
    capability_code: '',
    state: ''
  })
  const filters = reactive<Filters>(emptyFilters())
  const { hasAuth } = useAuth()
  const identity = useAdminReadIdentity()
  const canIndex = computed(() => hasAuth('sand_ai:usage:index'))
  const canSummary = computed(() => hasAuth('sand_ai:usage:summary'))
  const { width } = useWindowSize()
  const narrowScreen = computed(() => width.value < 640)
  const formId = useId()
  const fromInput = ref<InputInstance>()
  const toInput = ref<InputInstance>()
  const pageData = ref<PlatformValidationPage | null>(null)
  const summary = ref<PlatformValidationSummary | null>(null)
  const appliedQuery = ref<PlatformValidationQuery | null>(null)
  const pageSize = ref(10)
  const loading = ref(false)
  const listError = ref('')
  const summaryError = ref('')
  const validationError = ref('')
  const filtersChanged = ref(false)
  const detailOpen = ref(false)
  const selected = ref<PlatformValidationRow | null>(null)
  let trigger: HTMLElement | null = null
  let generation = 0
  const stateLabels: Record<PlatformValidationState, string> = {
    untracked: '未纳入产品计量',
    pending: '待确认',
    not_dispatched: '未派发',
    ambiguous: '派发结果不确定',
    recorded: '已有计量记录'
  }
  const stateLabel = (state: PlatformValidationState): string => stateLabels[state]
  const capabilityLabels: Readonly<Record<string, string>> = {
    chat: '聊天',
    embedding: '向量生成',
    audio_transcription: '音频转写',
    audio_speech: '语音合成'
  }
  const capabilityLabel = (code: string): string => capabilityLabels[code] ?? code
  const idLabel = (id: number | null): string => (id === null ? '未登记 / 未提供' : String(id))
  const targetLabel = (row: PlatformValidationRow): string =>
    row.target_kind === 'temporary_connection'
      ? '临时连接验证'
      : `部署 ${idLabel(row.deployment_id)}`
  const usageLabel = (usage: PlatformValidationUsage): string =>
    usage.usage_state === 'measured'
      ? '已测得'
      : usage.usage_state === 'unmeasured'
        ? '未测得'
        : '未确认'
  const pricingLabel = (usage: PlatformValidationUsage): string =>
    usage.pricing_state === 'priced'
      ? '已定价'
      : usage.pricing_state === 'unpriced'
        ? '未定价'
        : '未确认'
  const tokenLabel = (value: number | null, usage: PlatformValidationUsage): string =>
    usage.usage_state === 'measured' && value !== null ? String(value) : '未知'
  function meteredMoney(usage: PlatformValidationUsage): string {
    if (usage.metered_amount === null) return '未知'
    return usage.currency
      ? `${usage.metered_amount} ${usage.currency}`
      : `${usage.metered_amount}（币种未确认）`
  }
  function summaryAmount(row: SandAiUsageSummaryRow): string {
    if (row.metered_amount !== null) return row.metered_amount
    return row.known_metered_amount === null
      ? '未知'
      : `未知（已知部分 ${row.known_metered_amount}）`
  }
  function summaryBill(row: SandAiUsageSummaryRow): string {
    return row.reconciled_amount === null ? '尚未核对' : row.reconciled_amount
  }
  function usageNotice(usage: PlatformValidationUsage): string {
    if (usage.state === 'untracked')
      return '本次验证没有纳入产品计量，金额未知；不能补造历史费用或视为免费。'
    if (usage.state === 'ambiguous')
      return '派发结果尚不确定，金额未知。只能查询原验证记录；不要为查询费用重新验证模型。'
    if (usage.state === 'pending')
      return '本次验证的计量结果待确认，没有计量事件不代表零费用。可稍后刷新原记录。'
    if (usage.state === 'not_dispatched')
      return '本次尝试未派发，没有计量事件；现金账单未核对，不补造零费用。'
    if (usage.usage_state === 'unmeasured')
      return '已记录验证结果，但未测得有效用量，数量和计量金额未知。'
    if (usage.pricing_state !== 'priced')
      return '已测得用量但尚未定价，计量金额与币种未知；不能把本地执行或临时验证视为免费。'
    return '计量金额使用该次验证的冻结价格；现金账单尚未核对，不代表实际已支付费用。'
  }
  function closeDetails(): void {
    detailOpen.value = false
    selected.value = null
  }
  function restoreFocus(): void {
    if (trigger?.isConnected) trigger.focus()
    trigger = null
  }
  function openDetails(row: PlatformValidationRow, event: MouseEvent): void {
    if (!canIndex.value) return
    trigger = event.currentTarget instanceof HTMLElement ? event.currentTarget : null
    selected.value = { ...row, platform_usage: { ...row.platform_usage } }
    detailOpen.value = true
  }
  function invalidate(): void {
    ++generation
    loading.value = false
    pageData.value = null
    summary.value = null
    appliedQuery.value = null
    listError.value = ''
    summaryError.value = ''
    validationError.value = ''
    closeDetails()
  }
  watch(
    filters,
    () => {
      invalidate()
      filtersChanged.value = true
    },
    { deep: true, flush: 'sync' }
  )
  watch([canIndex, canSummary], invalidate, { flush: 'sync' })
  watch(identity.epoch, () => {
    invalidate()
    Object.assign(filters, emptyFilters())
    filtersChanged.value = false
    pageSize.value = 10
    trigger = null
  }, { flush: 'sync' })
  onBeforeUnmount(invalidate)
  onDeactivated(invalidate)
  onActivated(() => {
    void load()
  })

  function utcTimestamp(value: string): number | null {
    const match = /^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})$/.exec(value)
    if (!match) return null
    const [year, month, day, hour, minute, second] = match.slice(1).map(Number)
    if (
      year < 1 ||
      month < 1 ||
      month > 12 ||
      day < 1 ||
      day > 31 ||
      hour > 23 ||
      minute > 59 ||
      second > 59
    )
      return null
    const date = new Date(0)
    date.setUTCFullYear(year, month - 1, day)
    date.setUTCHours(hour, minute, second, 0)
    return date.getUTCFullYear() === year &&
      date.getUTCMonth() === month - 1 &&
      date.getUTCDate() === day
      ? date.getTime()
      : null
  }
  const utcText = (date: Date): string => date.toISOString().slice(0, 19).replace('T', ' ')
  function buildQuery(): PlatformValidationQuery | null {
    let from: string
    let to: string
    if (filters.range === 'custom') {
      const start = utcTimestamp(filters.from)
      const end = utcTimestamp(filters.to)
      if (start === null || end === null) {
        validationError.value = '请填写有效的 UTC 开始和结束时间（YYYY-MM-DD HH:mm:ss）。'
        if (start === null) fromInput.value?.focus()
        else toInput.value?.focus()
        return null
      }
      if (end <= start || end - start > 366 * 24 * 60 * 60 * 1000) {
        validationError.value = end <= start ? '结束时间应晚于开始时间。' : '时间范围最多 366 天。'
        toInput.value?.focus()
        return null
      }
      from = filters.from
      to = filters.to
    } else {
      const end = new Date()
      to = utcText(end)
      from = utcText(new Date(end.getTime() - 24 * 60 * 60 * 1000))
    }
    const ids = [
      filters.provider_id,
      filters.model_id,
      filters.deployment_id,
      filters.admin_actor_id
    ]
    if (
      ids.some((id) => id !== undefined && id !== null && (!Number.isSafeInteger(id) || id < 1))
    ) {
      validationError.value = '筛选 ID 应为有效的正整数；留空表示全部。'
      return null
    }
    return {
      from,
      to,
      provider_id: filters.provider_id ?? undefined,
      model_id: filters.model_id ?? undefined,
      deployment_id: filters.deployment_id ?? undefined,
      admin_actor_id: filters.admin_actor_id ?? undefined,
      capability_code: filters.capability_code || undefined,
      state: filters.state || undefined
    }
  }
  function errorMessage(failure: unknown, purpose: 'list' | 'summary'): string {
    if (failure instanceof HttpError) {
      if (failure.code === 401) return '管理员登录已失效，请正常登录后重新查询。'
      if (failure.code === 403)
        return purpose === 'list'
          ? '没有模型验证费用查看权限，请申请 sand_ai:usage:index。'
          : '没有模型验证费用汇总权限，请申请 sand_ai:usage:summary。'
      if (failure.code === 400)
        return '筛选未通过校验，请检查正整数 ID、UTC 时间和最长 366 天范围。'
      if (failure.code === 409)
        return '费用事实尚无法核验，暂不能展示；请联系管理员检查原记录，不要重新验证模型。'
      if (failure.code === 404) return '后台尚未提供模型验证费用接口，请联系管理员核对安装版本。'
    }
    return '模型验证费用读取失败，未取得结果；请重新查询或联系管理员检查。'
  }
  async function load(page = 1, reuseRange = false): Promise<void> {
    const owner = identity.capture()
    if (loading.value || !canIndex.value || owner === null) return
    const previous = reuseRange && appliedQuery.value ? { ...appliedQuery.value } : null
    invalidate()
    const query = previous ?? buildQuery()
    if (!query) return
    const current = generation
    const readSummary = canSummary.value
    loading.value = true
    filtersChanged.value = false
    const results = await Promise.allSettled([
      platformValidationApi.list({ ...query, page, limit: pageSize.value }),
      readSummary ? platformValidationApi.summary(query) : Promise.resolve(null)
    ] as const)
    if (!identity.current(owner) || current !== generation || !canIndex.value) return
    const [listResult, summaryResult] = results
    appliedQuery.value = { ...query }
    if (listResult.status === 'fulfilled') pageData.value = listResult.value
    else listError.value = errorMessage(listResult.reason, 'list')
    if (readSummary && canSummary.value) {
      if (summaryResult.status === 'fulfilled') summary.value = summaryResult.value
      else summaryError.value = errorMessage(summaryResult.reason, 'summary')
    }
    loading.value = false
  }
  function changePage(page: number): void {
    void load(page, true)
  }
  function changeSize(size: number): void {
    pageSize.value = size
    void load(1, true)
  }
  function resetFilters(): void {
    Object.assign(filters, emptyFilters())
    filtersChanged.value = false
    void load()
  }
</script>

<style scoped>
  .platform-validation-panel {
    display: flex;
    flex-direction: column;
    gap: 16px;
    min-width: 0;
  }
  .platform-validation-panel > * {
    flex-shrink: 0;
  }
  .validation-filters {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 0 16px;
  }
  .validation-filters :deep(.el-input-number),
  .validation-filters :deep(.el-select) {
    width: 100%;
  }
  .validation-time-fields {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 0 16px;
  }
  .validation-pagination {
    overflow-x: auto;
  }
</style>
