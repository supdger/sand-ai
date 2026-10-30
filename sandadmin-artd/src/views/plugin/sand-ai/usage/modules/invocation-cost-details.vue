<template>
  <div class="invocation-cost-details">
    <ElAlert
      title="调用前预算上界用于预算预占，实际用量以计量记录为准，已核对账单金额来自账单核对。未知金额不代表零费用。"
      type="info"
      :closable="false"
      class="mb-3"
    />
    <ElDescriptions :column="effectiveColumns" border>
      <ElDescriptionsItem label="请求号" :span="2">{{ cost.request_id }}</ElDescriptionsItem>
      <ElDescriptionsItem label="服务动作" :span="2">{{
        cost.service_action ?? '未确认'
      }}</ElDescriptionsItem>
      <ElDescriptionsItem label="能力">{{ cost.capability }}</ElDescriptionsItem>
      <ElDescriptionsItem label="来源">{{ cost.source_kind ?? '未确认' }}</ElDescriptionsItem>
      <ElDescriptionsItem label="服务商">{{ cost.provider ?? '未确认' }}</ElDescriptionsItem>
      <ElDescriptionsItem label="模型">{{ cost.model ?? '未确认' }}</ElDescriptionsItem>
      <ElDescriptionsItem label="用量状态">{{ usageLabels[cost.usage.state] }}</ElDescriptionsItem>
      <ElDescriptionsItem label="价格状态">{{
        cost.price.pricing_state === 'priced' ? '已定价' : '未定价'
      }}</ElDescriptionsItem>
      <ElDescriptionsItem label="计量单位">{{ meteringUnit }}</ElDescriptionsItem>
      <ElDescriptionsItem label="计量数量">{{ meteringQuantity }}</ElDescriptionsItem>
      <ElDescriptionsItem label="输入 Token">{{
        tokenValue(cost.tokens.input_tokens)
      }}</ElDescriptionsItem>
      <ElDescriptionsItem label="输出 Token">{{
        tokenValue(cost.tokens.output_tokens)
      }}</ElDescriptionsItem>
      <ElDescriptionsItem label="合计 Token">{{
        tokenValue(cost.tokens.total_tokens)
      }}</ElDescriptionsItem>
      <ElDescriptionsItem label="冻结价格版本">{{
        value(cost.price.revision_id)
      }}</ElDescriptionsItem>
      <ElDescriptionsItem label="调用前预算上界">{{ estimateView.amount }}</ElDescriptionsItem>
      <ElDescriptionsItem label="预算报价价格版本">{{
        estimateView.revision
      }}</ElDescriptionsItem>
      <ElDescriptionsItem label="计量金额">{{
        money(
          cost.price.metered_amount,
          cost.price.pricing_state === 'unpriced' ? '未定价' : '未知'
        )
      }}</ElDescriptionsItem>
      <ElDescriptionsItem label="已核对账单金额">{{
        reconciliationKnown && cost.reconciliation.state === 'reconciled'
          ? money(cost.reconciliation.reconciled_amount, '未核对')
          : '未核对'
      }}</ElDescriptionsItem>
      <ElDescriptionsItem label="对账状态">{{
        reconciliationKnown ? reconciliationLabels[cost.reconciliation.state] : '核对状态未确认'
      }}</ElDescriptionsItem>
      <ElDescriptionsItem label="匹配账单金额">{{
        reconciliationKnown ? money(cost.reconciliation.billed_amount, '未知') : '未知'
      }}</ElDescriptionsItem>
      <ElDescriptionsItem label="账单与计量差额">{{
        reconciliationKnown ? money(cost.reconciliation.difference_amount, '未比较') : '未比较'
      }}</ElDescriptionsItem>
      <ElDescriptionsItem label="缓存／复用节省" :span="2"
        >未知：当前费用数据没有真实命中和对应基准证据，尚未具备计算依据。历史费用比较仅核对同条件两次调用的计量差额；计量差额、账单差额和当前价格估算均不表示缓存／复用节省或已实现现金节省。</ElDescriptionsItem
      >
    </ElDescriptions>
    <ElAlert
      v-if="pricingNotice"
      :title="pricingNotice"
      type="info"
      :closable="false"
      class="mt-3"
    />
    <ElAlert
      v-if="reconciliationNotice"
      :title="reconciliationNotice"
      :type="cost.reconciliation.state === 'discrepancy' ? 'warning' : 'info'"
      :closable="false"
      class="mt-3"
    />
    <p class="mt-2 mb-3 text-sm text-gray-500">
      预算上界来自调用前冻结的报价，仅作预算预占，不代表实际 Token 预测、计量金额或已付现金。
      采用最小预占时金额可能为 0.000001。
    </p>
    <p class="mt-2 mb-3 text-sm text-gray-500">
      计量数量来自本次调用的真实用量；专业重排按服务商确认的搜索单位或重排 Token 展示，
      不换算为输入／输出 Token。未确认的计量不展示数量。
    </p>
    <section class="mt-3" aria-label="服务商调用标识">
      <h3 class="mb-2 text-base">服务商调用标识</h3>
      <ElAlert
        :title="providerIdentityView.message"
        :type="providerIdentityView.state === 'conflicting' ? 'warning' : 'info'"
        :closable="false"
        class="mb-3"
      />
      <ElDescriptions
        v-for="identity in providerIdentityView.items"
        :key="`${identity.source}:${identity.value}`"
        :column="effectiveColumns"
        border
        class="mb-3"
      >
        <ElDescriptionsItem label="标识类型">{{
          identity.kind === 'request_id' ? '服务商请求 ID' : '服务商响应 ID'
        }}</ElDescriptionsItem>
        <ElDescriptionsItem label="接收来源">{{
          providerIdentitySourceLabels[identity.source]
        }}</ElDescriptionsItem>
        <ElDescriptionsItem label="标识值" :span="2">{{ identity.value }}</ElDescriptionsItem>
        <ElDescriptionsItem label="接收时间（UTC）" :span="2">{{
          identity.received_time
        }}</ElDescriptionsItem>
      </ElDescriptions>
      <p class="mb-3 text-sm text-gray-500">
        标识供核查原调用；响应 ID 不作为服务商账单请求号。接收时间为服务器收到标识的时间，
        不代表服务商完成时间。已收到标识不证明结果完整、用量已测得或已付款，也不能据此释放预算或重发调用。
      </p>
    </section>
    <section class="cost-breakdown mt-3" aria-label="调用时费用分项">
      <h3 class="mb-2 text-base">调用时费用分项</h3>
      <p class="mb-3 text-sm text-gray-500">
        按本次调用的冻结价格版本展示；分项计量金额不等于已核对账单金额。
      </p>
      <ElDescriptions :column="effectiveColumns" border class="mb-3">
        <ElDescriptionsItem label="冻结价格版本">{{
          value(cost.price.revision_id)
        }}</ElDescriptionsItem>
        <ElDescriptionsItem label="币种">{{ cost.price.currency ?? '未确认' }}</ElDescriptionsItem>
      </ElDescriptions>
      <template v-if="breakdownView.state === 'available'">
        <ElDescriptions
          v-for="item in breakdownView.items"
          :key="item.meter_code"
          :title="meterLabels[item.meter_code]"
          :column="effectiveColumns"
          border
          class="mb-3"
        >
          <ElDescriptionsItem label="数量">{{ item.quantity }}</ElDescriptionsItem>
          <ElDescriptionsItem label="冻结费率">
            {{ item.unit_price }} {{ cost.price.currency }} / {{ item.per_quantity }}
            {{ meterUnitLabels[item.meter_code] }}
          </ElDescriptionsItem>
          <ElDescriptionsItem label="计量金额"
            >{{ item.metered_amount }} {{ cost.price.currency }}</ElDescriptionsItem
          >
        </ElDescriptions>
        <p class="mb-3 text-sm"
          >本次计量金额：{{ cost.price.metered_amount }} {{ cost.price.currency }}</p
        >
      </template>
      <ElAlert
        v-else
        :title="breakdownView.message"
        :type="
          breakdownView.state === 'held' || breakdownView.state === 'invalid' ? 'warning' : 'info'
        "
        :closable="false"
        class="mb-3"
      />
    </section>
    <section v-if="cost.adjustments" class="mt-3" aria-label="账单与退款">
      <h3 class="mb-2 text-base">账单与退款</h3>
      <p class="mb-3 text-sm text-gray-500">
        以已核对账单为依据，退款来自人工审核记录。退款不改写原计量金额或已核对账单金额；
        预算预占释放不代表退款。
      </p>
      <ElDescriptions :column="effectiveColumns" border>
        <ElDescriptionsItem label="退款记录状态">{{
          refundLabels[cost.adjustments.state]
        }}</ElDescriptionsItem>
        <ElDescriptionsItem label="审核来源">人工审核</ElDescriptionsItem>
        <ElDescriptionsItem label="退款前账单金额">{{
          refundMoney(cost.adjustments.gross_billed_amount)
        }}</ElDescriptionsItem>
        <ElDescriptionsItem label="已记录退款金额">{{
          refundMoney(cost.adjustments.refund_amount)
        }}</ElDescriptionsItem>
        <ElDescriptionsItem label="退款后账单净额">{{
          refundMoney(cost.adjustments.net_billed_amount)
        }}</ElDescriptionsItem>
        <ElDescriptionsItem label="退款记录数">{{
          cost.adjustments.adjustment_count
        }}</ElDescriptionsItem>
      </ElDescriptions>
      <ElAlert
        v-if="cost.adjustments.state === 'bill_unavailable'"
        title="尚无可用的已核对账单，退款前金额、退款金额和账单净额均未确认；未知金额不代表零。"
        type="info"
        :closable="false"
        class="mt-3"
      />
    </section>
    <ElDescriptions
      v-if="cost.skill || cost.operator_review || cost.reconciliation.reason"
      :column="effectiveColumns"
      border
      class="mt-3"
    >
      <ElDescriptionsItem v-if="cost.skill" label="Skill 运行" :span="2"
        >{{ cost.skill.code }} · 版本 {{ cost.skill.version }} · 运行
        {{ cost.skill.run_id }}</ElDescriptionsItem
      >
      <ElDescriptionsItem v-if="cost.operator_review" label="人工审核（独立记录）" :span="2">
        {{ reviewLabel(cost.operator_review.comparison_state) }} · {{ cost.operator_review.reason
        }}<br />
        数量差 {{ cost.operator_review.quantity_difference ?? '未比较' }} / 金额差
        {{ cost.operator_review.amount_difference ?? '未比较' }}
        {{ cost.price.currency ?? '' }}<br />
        {{
          cost.operator_review.evidence_state === 'available' ? '人工提供原件可用' : '原件不可用'
        }}；人工审核不改变厂商费用事实。
      </ElDescriptionsItem>
      <ElDescriptionsItem v-if="cost.reconciliation.reason" label="对账说明" :span="2">{{
        cost.reconciliation.reason
      }}</ElDescriptionsItem>
    </ElDescriptions>
    <ElAlert
      v-if="usageNotice"
      :title="usageNotice"
      type="warning"
      :closable="false"
      class="mt-3"
    />
  </div>
</template>

<script setup lang="ts">
  import { computed } from 'vue'
  import { useMounted, useWindowSize } from '@vueuse/core'
  import type {
    SandAiCostBreakdownItem,
    SandAiInvocationCost,
    SandAiProviderResponseIdentityItem,
    SandAiRefundCostTotals
  } from '../../api/types'
  import { reviewLabel } from '../../api/billing-types'

  const props = defineProps<{ cost: SandAiInvocationCost; columns?: 1 | 2 }>()
  const { width } = useWindowSize()
  const mounted = useMounted()
  // Keep the same fallback during SSR/hydration; follow viewport updates after mounting.
  const effectiveColumns = computed<1 | 2>(
    () => props.columns ?? (mounted.value && width.value < 640 ? 1 : 2)
  )
  const providerIdentitySourceLabels: Record<SandAiProviderResponseIdentityItem['source'], string> = {
    'header.x-request-id': '响应头 x-request-id',
    'header.request-id': '响应头 request-id',
    'body.id': 'JSON 响应 id',
    'stream.id': '流事件 id'
  }
  function isProviderIdentityItem(input: unknown): input is SandAiProviderResponseIdentityItem {
    if (typeof input !== 'object' || input === null) return false
    return (
      'kind' in input &&
      'source' in input &&
      ((input.kind === 'request_id' &&
        (input.source === 'header.x-request-id' || input.source === 'header.request-id')) ||
        (input.kind === 'response_id' &&
          (input.source === 'body.id' || input.source === 'stream.id'))) &&
      'value' in input &&
      typeof input.value === 'string' &&
      input.value === input.value.trim() &&
      /^[A-Za-z0-9_.:-]{1,128}$/.test(input.value) &&
      'received_time' in input &&
      typeof input.received_time === 'string' &&
      input.received_time.length === 19 &&
      /^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/.test(input.received_time)
    )
  }
  const providerIdentityView = computed<{
    state: 'not_observed' | 'observed' | 'conflicting'
    items: SandAiProviderResponseIdentityItem[]
    message: string
  }>(() => {
    const unknown = {
      state: 'not_observed' as const,
      items: [],
      message: '未知／未保存：没有持久保存的服务商标识证据，不能据此判断调用未外发。历史调用不补造标识。'
    }
    const identity: unknown = props.cost.provider_response_identity
    if (typeof identity !== 'object' || identity === null) return unknown
    if (
      !('state' in identity) ||
      (identity.state !== 'observed' && identity.state !== 'conflicting') ||
      !('identities' in identity) ||
      !Array.isArray(identity.identities) ||
      identity.identities.length === 0 ||
      identity.identities.length > 8 ||
      !identity.identities.every(isProviderIdentityItem)
    ) return unknown
    const items = identity.identities
    for (const source of Object.keys(providerIdentitySourceLabels)) {
      const values = items.filter((item) => item.source === source).map((item) => item.value)
      if (values.length > 2 || new Set(values).size !== values.length) return unknown
      if (identity.state === 'observed' && values.length > 1) return unknown
    }
    if (identity.state === 'observed') {
      for (const kind of ['request_id', 'response_id']) {
        const values = items.filter((item) => item.kind === kind).map((item) => item.value)
        if (new Set(values).size > 1) return unknown
      }
    }
    return {
      state: identity.state,
      items,
      message: identity.state === 'conflicting'
        ? '标识有冲突：同种标识收到不同值（可能来自不同来源），已保留收到的标识，不能自动选择其一或据此匹配账单。请结合原调用和账单人工核查。'
        : '已收到并保存服务商标识，可用于核查原调用；账单匹配与费用状态仍按各自记录判断。'
    }
  })
  const usageLabels: Record<SandAiInvocationCost['usage']['state'], string> = {
    measured: '已测得',
    unmeasured: '未测得',
    not_dispatched: '未派发',
    failed: '派发失败',
    ambiguous: '派发结果不确定',
    pending: '派发中',
    missing_event: '缺少计量事件'
  }
  const reconciliationLabels: Record<SandAiInvocationCost['reconciliation']['state'], string> = {
    not_applicable: '不适用',
    held: '待处理',
    pending: '等待处理',
    unreconciled: '未核对',
    reconciled: '已核对',
    discrepancy: '有差异'
  }
  const reconciliationKnown = computed(() =>
    Object.prototype.hasOwnProperty.call(reconciliationLabels, props.cost.reconciliation.state)
  )
  const pricingNotice = computed(() =>
    props.cost.usage.state === 'measured' && props.cost.price.pricing_state === 'unpriced'
      ? '本次用量已测得，但原事件尚未定价，计量金额仍未知。当前响应未提供具体缺价原因；请核对调用时的冻结价格版本和有效期，不能用当前价格重算历史费用。'
      : ''
  )
  const reconciliationNotice = computed(() => {
    if (props.cost.reconciliation.reason) return ''
    switch (props.cost.reconciliation.state) {
      case 'unreconciled':
        return '本次用量尚无已核对账单结果。请在账单导入与对账中核对原调用的匹配及差异；未核对不代表无费用。'
      case 'pending':
        return '账单核对等待处理，已核对账单金额尚未确认。请查看原账单处理状态，稍后只读刷新本次费用。'
      case 'held':
        return '账单核对待处理，当前响应未提供具体原因。请在原账单中核对匹配和差异；人工审核记录不能代替已核对账单金额。'
      case 'discrepancy':
        return '账单核对已记录差异，当前响应未提供具体原因。请结合匹配账单金额、计量金额和差额核对原账单，不能将匹配金额视为已核对金额。'
      case 'not_applicable':
        return '本次响应没有适用的账单核对结果，账单金额未确认。请先按原请求号核对派发及计量状态，不能为查询费用重新发起调用。'
      default:
        return ''
    }
  })
  const refundLabels: Record<SandAiRefundCostTotals['state'], string> = {
    bill_unavailable: '账单尚不可用',
    no_recorded_refund: '暂无已记录退款',
    recorded_refunds: '已有退款记录'
  }
  function refundMoney(amount: string | null): string {
    if (amount === null) return '未知'
    const currency = props.cost.adjustments?.currency
    return currency ? `${amount} ${currency}` : `${amount}（币种未确认）`
  }
  const usageNotice = computed(() => {
    switch (props.cost.usage.state) {
      case 'ambiguous':
        return '派发结果尚不确定，费用未知。请按原请求号重新查询，勿为查询费用重新发起调用。'
      case 'missing_event':
        return '调用已派发但缺少计量事件。请联系管理员检查记录；当前不能判断费用或节省。'
      case 'pending':
        return '调用尚在派发中。稍后按原请求号重新查询费用。'
      case 'unmeasured':
        return '服务商未提供有效用量，计量数量与金额未知。请查看后续账单核对结果。'
      case 'not_dispatched':
      case 'failed':
        return '当前没有计量事件，费用未确认；不能按零费用计入排名或节省。'
      default:
        return ''
    }
  })
  // Decimal values stay strings. This checks the frozen DTO shape, never calculates fees.
  const decimalUnitPrice = (input: unknown): input is string =>
    typeof input === 'string' &&
    input === input.trim() &&
    /^(0|[1-9][0-9]{0,11})(?:\.[0-9]{1,8})?$/.test(input)
  const decimalMoneyAmount = (input: unknown): input is string =>
    typeof input === 'string' &&
    input === input.trim() &&
    /^(0|[1-9][0-9]{0,13})(?:\.[0-9]{1,6})?$/.test(input)
  const estimateView = computed<{ amount: string; revision: string }>(() => {
    const price = props.cost.price
    const unavailable = (amount: string) => ({ amount, revision: '未确认' })
    if (price.estimate_state === undefined)
      return unavailable('当前响应未提供调用前预算依据')
    if (price.estimate_state === 'held')
      return unavailable(
        price.estimate_reason === 'frozen_quote_conflict'
          ? '冻结预算报价存在冲突，待核验'
          : '冻结预算依据待核验'
      )
    if (price.estimate_state === 'unavailable') {
      if (price.estimate_reason === 'no_frozen_quote')
        return unavailable('本次未冻结调用前预算报价')
      if (price.estimate_reason === 'frozen_quote_unavailable')
        return unavailable('冻结预算报价不可用')
      return unavailable('调用前预算依据未确认')
    }
    const revision = price.estimated_price_revision_id
    if (
      price.estimate_state !== 'available' ||
      price.estimate_basis !== 'budget_reservation_upper_bound' ||
      price.estimate_reason !== 'frozen_budget_reservation' ||
      !decimalMoneyAmount(price.estimated_amount) ||
      typeof price.estimated_currency !== 'string' ||
      !/^[A-Z]{3}$/.test(price.estimated_currency) ||
      typeof revision !== 'number' ||
      !Number.isSafeInteger(revision) ||
      revision <= 0
    )
      return unavailable('调用前预算依据不完整，暂不显示金额')
    return {
      amount: `${price.estimated_amount} ${price.estimated_currency}`,
      revision: String(revision)
    }
  })
  const tokenQuantity = (input: unknown): input is string =>
    typeof input === 'string' && input === input.trim() && /^(0|[1-9][0-9]*)$/.test(input)
  const meterLabels: Record<SandAiCostBreakdownItem['meter_code'], string> = {
    input_tokens: '输入 Token',
    output_tokens: '输出 Token',
    rerank_search_units: '重排搜索单位',
    rerank_tokens: '重排 Token'
  }
  const meterUnitLabels: Record<SandAiCostBreakdownItem['meter_code'], string> = {
    input_tokens: 'Token',
    output_tokens: 'Token',
    rerank_search_units: '搜索单位',
    rerank_tokens: '重排 Token'
  }
  function isBreakdownItem(input: unknown): input is SandAiCostBreakdownItem {
    if (typeof input !== 'object' || input === null) return false
    return (
      'meter_code' in input &&
      (input.meter_code === 'input_tokens' || input.meter_code === 'output_tokens' ||
        input.meter_code === 'rerank_search_units' || input.meter_code === 'rerank_tokens') &&
      'quantity' in input &&
      tokenQuantity(input.quantity) &&
      'unit_price' in input &&
      decimalUnitPrice(input.unit_price) &&
      'metered_amount' in input &&
      decimalMoneyAmount(input.metered_amount) &&
      'per_quantity' in input &&
      typeof input.per_quantity === 'number' &&
      Number.isSafeInteger(input.per_quantity) &&
      input.per_quantity > 0
    )
  }
  type BreakdownView =
    | { state: 'available'; items: SandAiCostBreakdownItem[] }
    | { state: 'unavailable' | 'held' | 'legacy' | 'invalid'; message: string }
  const breakdownView = computed<BreakdownView>(() => {
    const price = props.cost.price
    const state: unknown = price.breakdown_state
    if (state === undefined)
      return {
        state: 'legacy',
        message: '当前响应未提供费用分项。原计量金额和账单核对结果仍可查看。'
      }
    if (state === 'unavailable')
      return {
        state,
        message: '费用分项不可用：缺少完整的计量或冻结价格事实。已提供的总金额仍按原记录展示。'
      }
    if (state === 'held')
      return {
        state,
        message:
          '费用分项待核验：计量与冻结价格事实存在不一致，暂不展示分项。请联系管理员检查本次请求记录。'
      }
    const items: unknown = price.breakdown
    const usage = props.cost.usage
    if (
      state === 'available' &&
      price.pricing_state === 'priced' &&
      usage.state === 'measured' &&
      tokenQuantity(usage.metering_quantity) &&
      typeof price.currency === 'string' &&
      price.currency.length === 3 &&
      /^[A-Z]{3}$/.test(price.currency) &&
      typeof price.revision_id === 'number' &&
      Number.isSafeInteger(price.revision_id) &&
      price.revision_id > 0 &&
      decimalMoneyAmount(price.metered_amount) &&
      Array.isArray(items)
    ) {
      if (
        usage.metering_unit === 'total_tokens' &&
        validTokenCount(props.cost.tokens.input_tokens) &&
        validTokenCount(props.cost.tokens.output_tokens) &&
        validTokenCount(props.cost.tokens.total_tokens) &&
        items.length === 2 &&
        isBreakdownItem(items[0]) &&
        items[0].meter_code === 'input_tokens' &&
        isBreakdownItem(items[1]) &&
        items[1].meter_code === 'output_tokens'
      ) return { state: 'available', items: [items[0], items[1]] }
      if (
        props.cost.capability === 'rerank' &&
        (usage.metering_unit === 'rerank_search_units' || usage.metering_unit === 'rerank_tokens') &&
        props.cost.tokens.input_tokens === null &&
        props.cost.tokens.output_tokens === null &&
        props.cost.tokens.total_tokens === null &&
        items.length === 1 &&
        isBreakdownItem(items[0]) &&
        items[0].meter_code === usage.metering_unit &&
        items[0].quantity === usage.metering_quantity &&
        items[0].metered_amount === price.metered_amount
      ) return { state: 'available', items: [items[0]] }
    }
    return { state: 'invalid', message: '费用分项响应不完整，暂不能核对。请重新查询或联系管理员。' }
  })
  const meteringUnit = computed(() => {
    if (props.cost.usage.state !== 'measured') return '未提供'
    switch (props.cost.usage.metering_unit) {
      case 'total_tokens': return 'Token（合计）'
      case 'rerank_search_units': return '重排搜索单位'
      case 'rerank_tokens': return '重排 Token'
      default: return '未提供'
    }
  })
  const meteringQuantity = computed(() =>
    props.cost.usage.state === 'measured' &&
    (props.cost.usage.metering_unit === 'total_tokens' ||
      props.cost.usage.metering_unit === 'rerank_search_units' ||
      props.cost.usage.metering_unit === 'rerank_tokens') &&
    tokenQuantity(props.cost.usage.metering_quantity)
      ? props.cost.usage.metering_quantity
      : '未提供'
  )
  const validTokenCount = (input: unknown): input is number =>
    typeof input === 'number' && Number.isSafeInteger(input) && input >= 0
  function tokenValue(input: number | null): string {
    const usage = props.cost.usage
    // Older measured responses may omit the new unit; an explicit null means untrusted/non-Token.
    if (
      usage.state !== 'measured' ||
      (usage.metering_unit !== undefined && usage.metering_unit !== 'total_tokens')
    )
      return '未知'
    return validTokenCount(input) ? String(input) : '未知'
  }
  function value(input: number | null | undefined): string {
    return input === null || input === undefined ? '未知' : String(input)
  }
  function money(amount: string | null | undefined, unavailable: string): string {
    if (amount === null || amount === undefined || amount === '') return unavailable
    return props.cost.price.currency === null || props.cost.price.currency === undefined
      ? `${amount}（币种未确认）`
      : `${amount} ${props.cost.price.currency}`
  }
</script>

<style scoped>
  .invocation-cost-details {
    min-width: 0;
    overflow-wrap: anywhere;
  }

  .invocation-cost-details :deep(.el-descriptions__table) {
    width: 100%;
    table-layout: fixed;
  }

  .invocation-cost-details :deep(.el-descriptions__cell) {
    overflow-wrap: anywhere;
  }
</style>
