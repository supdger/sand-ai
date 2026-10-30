<template>
  <ElDrawer v-model="open" title="账单历史与原调用费用" size="min(1200px, 96vw)" append-to-body destroy-on-close @closed="clearReadData">
    <p class="mb-3 text-sm">查询已有记录。当前导入、同步、审核或退款的输入及原请求记录会继续保留。所有时间为 UTC，未知金额不表示零。</p>
    <section v-if="canBilling" aria-label="已有账单批次">
      <ElDivider>账单批次</ElDivider>
      <ElForm label-position="top" @submit.prevent>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
          <ElFormItem label="服务商 ID（留空查看获权批次）"><ElInput v-model="providerText" /></ElFormItem>
          <ElFormItem label="已有批次 ID（可直接读取更早的批次）"><ElInput v-model="batchText" /></ElFormItem>
        </div>
        <div class="flex flex-wrap gap-3 mb-3">
          <ElButton :loading="listLoading" @click="loadBatches">查询最近 100 个批次</ElButton>
          <ElButton :loading="batchLoading" @click="readBatch()">按批次 ID 读取</ElButton>
        </div>
      </ElForm>
      <ElAlert v-if="listError" :title="listError" type="warning" :closable="false" />
      <ElEmpty v-else-if="listLoaded && batches.length === 0" description="暂无可读取批次" />
      <ElTable v-if="batches.length" :data="batches" v-loading="listLoading">
        <ElTableColumn prop="id" label="批次" width="90" />
        <ElTableColumn prop="provider_id" label="服务商 ID" width="110" />
        <ElTableColumn label="账期 UTC" min-width="300"><template #default="{ row }">{{ row.period_start }} 至 {{ row.period_end }}</template></ElTableColumn>
        <ElTableColumn prop="source_kind" label="来源" min-width="120" />
        <ElTableColumn prop="reference" label="参考号" min-width="140" />
        <ElTableColumn label="操作" width="100"><template #default="{ row }"><ElButton link type="primary" @click="readBatch(row.id)">读取明细</ElButton></template></ElTableColumn>
      </ElTable>
      <ElAlert v-if="batchError" :title="batchError" type="warning" :closable="false" class="mt-3" />
      <div v-if="batchLoading" v-loading="batchLoading" class="min-h-24" />
      <template v-else-if="selectedBatch">
        <ElDescriptions :column="1" border class="mt-3">
          <ElDescriptionsItem label="当前读取的批次">{{ selectedBatch.id }} · 服务商 {{ selectedBatch.provider_id }}</ElDescriptionsItem>
          <ElDescriptionsItem label="账期 UTC">{{ selectedBatch.period_start }} 至 {{ selectedBatch.period_end }}</ElDescriptionsItem>
          <ElDescriptionsItem label="来源 / 参考号">{{ selectedBatch.source_kind }} / {{ selectedBatch.reference }}</ElDescriptionsItem>
        </ElDescriptions>
        <ElTable :data="selectedBatch.lines" class="mt-3">
          <ElTableColumn prop="billing_line_id" label="行 ID" width="100" />
          <ElTableColumn prop="external_line_id" label="原行号" min-width="120" />
          <ElTableColumn label="模型 / 能力" min-width="170"><template #default="{ row }">{{ row.model_code }} / {{ row.capability_code }}</template></ElTableColumn>
          <ElTableColumn prop="meter_code" label="计量口径" min-width="140" />
          <ElTableColumn prop="quantity" label="数量" min-width="110" />
          <ElTableColumn label="原账单金额" min-width="140"><template #default="{ row }">{{ row.amount }} {{ row.currency }}</template></ElTableColumn>
          <ElTableColumn label="自动核对" min-width="170"><template #default="{ row }">{{ reconciliationLabel(row.reconciliation?.state) }}<br />{{ reconciliationReason(row.reconciliation?.reason) }}</template></ElTableColumn>
          <ElTableColumn label="核对差额" min-width="180"><template #default="{ row }">数量 {{ row.reconciliation?.quantity_difference ?? '未知' }} / 金额 {{ row.reconciliation?.amount_difference ?? '未知' }} {{ row.currency }}</template></ElTableColumn>
          <ElTableColumn label="历史 / 费用" min-width="210"><template #default="{ row }">
            <ElButton link type="primary" @click="selectLine(row)">查看原账单行</ElButton>
            <ElButton v-if="row.trace && canCost" link type="primary" @click="readTraceCost(row.trace)">读取原调用费用</ElButton>
          </template></ElTableColumn>
        </ElTable>
      </template>
      <ElDescriptions v-if="selectedLine" :column="1" border class="mt-3">
        <ElDescriptionsItem label="当前原账单行">{{ selectedLine.billing_line_id }} · {{ selectedLine.external_line_id }}</ElDescriptionsItem>
        <ElDescriptionsItem label="原账单金额">{{ selectedLine.amount }} {{ selectedLine.currency }}</ElDescriptionsItem>
        <ElDescriptionsItem label="数量 / 计量口径">{{ selectedLine.quantity }} / {{ selectedLine.meter_code }}</ElDescriptionsItem>
        <ElDescriptionsItem label="发生时段 UTC">{{ selectedLine.occurred_start }} 至 {{ selectedLine.occurred_end }}</ElDescriptionsItem>
        <ElDescriptionsItem label="来源身份">{{ selectedLine.identity_state === 'verified' ? '来源身份已核验' : '来源身份未核验' }}</ElDescriptionsItem>
      </ElDescriptions>
    </section>
    <p v-else class="mb-3 text-sm">需要账单查看权限才能读取批次和原账单金额。</p>
    <section v-if="canReview" aria-label="原账单行审核历史">
      <ElDivider>原账单行审核历史</ElDivider>
      <ElForm label-position="top" @submit.prevent>
        <ElFormItem label="已有账单行 ID"><ElInput v-model="lineText" /></ElFormItem>
        <ElButton :loading="historyLoading" @click="readHistory">查询原行审核历史</ElButton>
      </ElForm>
      <p v-if="historyTarget" class="my-3 text-sm">当前查询行 ID：{{ historyTarget }}。人工审核记录不替换厂商对账金额。</p>
      <ElAlert v-if="historyError" :title="historyError" type="warning" :closable="false" class="mt-3" />
      <ElEmpty v-else-if="historyLoaded && history.length === 0" description="此原行暂无人工审核记录" />
      <ElTable v-else-if="history.length" :data="history" v-loading="historyLoading" class="mt-3">
        <ElTableColumn prop="create_time" label="时间 UTC" min-width="170" />
        <ElTableColumn prop="admin_id" label="原操作员 ID" width="120" />
        <ElTableColumn label="人工结果" min-width="170"><template #default="{ row }">{{ reviewLabel(row.comparison_state) }}</template></ElTableColumn>
        <ElTableColumn label="差额" min-width="200"><template #default="{ row }">数量 {{ row.quantity_difference ?? '未比较' }} / 金额 {{ row.amount_difference ?? '未比较' }}<span v-if="selectedLine"> {{ selectedLine.currency }}</span></template></ElTableColumn>
        <ElTableColumn prop="reason" label="比较说明" min-width="170" />
        <ElTableColumn label="原件记录" min-width="220"><template #default="{ row }">ID {{ row.evidence_id }} · {{ row.evidence_state === 'available' ? '原件可用' : '原件不可用' }}<br />SHA {{ row.evidence_sha256 }}</template></ElTableColumn>
        <ElTableColumn prop="evidence_locator" label="原审核位置" min-width="160" />
        <ElTableColumn prop="note" label="原审核说明" min-width="180" />
        <ElTableColumn prop="idempotency_key" label="原请求号" min-width="220" />
        <ElTableColumn label="费用追溯" min-width="150"><template #default="{ row }"><ElButton v-if="row.trace && canCost" link type="primary" @click="readTraceCost(row.trace)">读取原调用费用</ElButton><span v-else>调用关联不可读取或证明不足</span></template></ElTableColumn>
      </ElTable>
    </section>
    <p v-else class="mt-3 text-sm">需要账单审核权限才能读取审核历史。</p>
    <section v-if="canCost" aria-label="原模型调用费用">
      <ElDivider>原模型调用费用</ElDivider>
      <ElForm label-position="top" @submit.prevent>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
          <ElFormItem label="已有调用的环境 ID"><ElInput v-model="environmentText" /></ElFormItem>
          <ElFormItem label="已有调用 ID"><ElInput v-model="invocationText" /></ElFormItem>
        </div>
        <ElButton :loading="costLoading" @click="readCost">读取原调用费用</ElButton>
      </ElForm>
      <p v-if="costTarget" class="my-3 text-sm">当前查询环境 {{ costTarget.environmentId }} · 调用 {{ costTarget.invocationId }}</p>
      <ElAlert v-if="costError" :title="costError" type="warning" :closable="false" class="mt-3" />
      <div v-if="costLoading" v-loading="costLoading" class="min-h-24" />
      <InvocationCostDetails v-else-if="cost" :cost="cost" class="mt-3" />
    </section>
    <p v-else class="mt-3 text-sm">需要单次费用查看权限才能读取原调用费用。</p>
  </ElDrawer>
</template>

<script setup lang="ts">
  import { computed, onBeforeUnmount, ref, watch } from 'vue'
  import { useAuth } from '@/hooks/core/useAuth'
  import { useUserStore } from '@/store/modules/user'
  import { HttpError } from '@/utils/http/error'
  import { billingApi, billingError, positiveId } from '../../api/billing'
  import { reconciliationLabel, reconciliationReason, reviewLabel } from '../../api/billing-types'
  import type { BillingBatch, BillingDetail, BillingLine, BillingReview, BillingUsageTrace } from '../../api/billing-types'
  import invocationApi from '../../api/invocation'
  import type { SandAiInvocationCost } from '../../api/types'
  import InvocationCostDetails from './invocation-cost-details.vue'

  const props = defineProps<{ modelValue: boolean }>()
  const emit = defineEmits<{ 'update:modelValue': [value: boolean] }>()
  const open = computed({ get: () => props.modelValue, set: value => emit('update:modelValue', value) })
  const { hasAuth } = useAuth()
  const userStore = useUserStore()
  const owner = computed(() => userStore.info.id)
  const canBilling = computed(() => hasAuth('sand_ai:billing:index'))
  const canReview = computed(() => hasAuth('sand_ai:billing:review'))
  const canCost = computed(() => hasAuth('sand_ai:usage:cost'))
  const providerText = ref('')
  const batchText = ref('')
  const lineText = ref('')
  const environmentText = ref('')
  const invocationText = ref('')
  const batches = ref<BillingBatch[]>([])
  const selectedBatch = ref<BillingDetail | null>(null)
  const selectedLine = ref<BillingLine | null>(null)
  const history = ref<BillingReview[]>([])
  const historyTarget = ref<number | null>(null)
  const cost = ref<SandAiInvocationCost | null>(null)
  const costTarget = ref<{ environmentId: number; invocationId: number } | null>(null)
  const listLoading = ref(false)
  const batchLoading = ref(false)
  const historyLoading = ref(false)
  const costLoading = ref(false)
  const listLoaded = ref(false)
  const historyLoaded = ref(false)
  const listError = ref('')
  const batchError = ref('')
  const historyError = ref('')
  const costError = ref('')
  let alive = true
  let epoch = 0
  let listGeneration = 0
  let batchGeneration = 0
  let historyGeneration = 0
  let costGeneration = 0
  function context() { return { owner: owner.value, epoch } }
  function current(start: ReturnType<typeof context>): boolean {
    return alive && open.value && start.epoch === epoch && start.owner === owner.value &&
      typeof owner.value === 'number' && Number.isSafeInteger(owner.value) && owner.value > 0
  }
  function clearList() {
    ++listGeneration
    listLoading.value = false
    listLoaded.value = false
    listError.value = ''
    batches.value = []
  }
  function clearHistory() {
    ++historyGeneration
    historyLoading.value = false
    historyLoaded.value = false
    historyError.value = ''
    history.value = []
    historyTarget.value = null
  }
  function clearBatch() {
    ++batchGeneration
    batchLoading.value = false
    batchError.value = ''
    selectedBatch.value = null
    selectedLine.value = null
    lineText.value = ''
    clearHistory()
  }
  function clearCost() {
    ++costGeneration
    costLoading.value = false
    costError.value = ''
    cost.value = null
    costTarget.value = null
  }
  function clearReadData() {
    ++epoch
    clearList()
    clearBatch()
    clearCost()
  }
  watch(providerText, clearList, { flush: 'sync' })
  watch(batchText, clearBatch, { flush: 'sync' })
  watch(lineText, () => {
    clearHistory()
    if (selectedLine.value && String(selectedLine.value.billing_line_id) !== lineText.value) selectedLine.value = null
  }, { flush: 'sync' })
  watch([environmentText, invocationText], clearCost, { flush: 'sync' })
  watch([owner, canBilling, canReview, canCost], () => {
    clearReadData()
    providerText.value = ''
    batchText.value = ''
    environmentText.value = ''
    invocationText.value = ''
    open.value = false
  }, { flush: 'sync' })
  watch(open, value => { if (!value) clearReadData() }, { flush: 'sync' })
  onBeforeUnmount(() => { alive = false; clearReadData() })

  async function loadBatches() {
    const start = context()
    if (!canBilling.value || !current(start)) return
    clearList()
    const providerId = providerText.value === '' ? undefined : positiveId(providerText.value)
    if (providerId === null) { listError.value = '服务商 ID 必须是正整数，或留空。'; return }
    const generation = ++listGeneration
    listLoading.value = true
    try {
      const rows = await billingApi.index(providerId)
      if (!current(start) || generation !== listGeneration || !canBilling.value) return
      if (providerId !== undefined && rows.some(row => row.provider_id !== providerId)) throw new Error('账单批次响应与查询服务商不一致')
      batches.value = rows
      listLoaded.value = true
    } catch (cause) {
      if (current(start) && generation === listGeneration && canBilling.value) listError.value = billingError(cause)
    } finally { if (current(start) && generation === listGeneration) listLoading.value = false }
  }
  async function readBatch(id?: number) {
    const start = context()
    if (!canBilling.value || !current(start)) return
    if (id !== undefined) batchText.value = String(id)
    clearBatch()
    const target = positiveId(batchText.value)
    if (!target) { batchError.value = '请填写已有账单批次的正整数 ID。'; return }
    const generation = ++batchGeneration
    batchLoading.value = true
    try {
      const detail = await billingApi.read(target)
      if (!current(start) || generation !== batchGeneration || !canBilling.value) return
      if (detail.id !== target) throw new Error('账单明细响应与原批次 ID 不一致')
      selectedBatch.value = detail
    } catch (cause) {
      if (current(start) && generation === batchGeneration && canBilling.value) batchError.value = billingError(cause)
    } finally { if (current(start) && generation === batchGeneration) batchLoading.value = false }
  }
  function selectLine(line: BillingLine) {
    if (!canBilling.value || !current(context()) || !selectedBatch.value?.lines.some(row => row.billing_line_id === line.billing_line_id)) return
    lineText.value = String(line.billing_line_id)
    selectedLine.value = line
    if (canReview.value) void readHistory()
  }
  async function readHistory() {
    const start = context()
    if (!canReview.value || !current(start)) return
    clearHistory()
    const target = positiveId(lineText.value)
    if (!target) { historyError.value = '请填写已有账单行的正整数 ID。'; return }
    const generation = ++historyGeneration
    historyTarget.value = target
    historyLoading.value = true
    try {
      const rows = await billingApi.history(target)
      if (!current(start) || generation !== historyGeneration || !canReview.value) return
      if (rows.some(row => row.billing_line_id !== target)) throw new Error('审核历史响应与原账单行 ID 不一致')
      history.value = rows
      historyLoaded.value = true
    } catch (cause) {
      if (current(start) && generation === historyGeneration && canReview.value) historyError.value = billingError(cause)
    } finally { if (current(start) && generation === historyGeneration) historyLoading.value = false }
  }
  function readTraceCost(trace: BillingUsageTrace) {
    if (!canCost.value || !current(context())) return
    environmentText.value = String(trace.environment_id)
    invocationText.value = String(trace.invocation_id)
    void readCost()
  }
  async function readCost() {
    const start = context()
    if (!canCost.value || !current(start)) return
    clearCost()
    const environmentId = positiveId(environmentText.value)
    const invocationId = positiveId(invocationText.value)
    if (!environmentId || !invocationId) { costError.value = '请填写已有调用的正整数环境 ID 和调用 ID。'; return }
    const generation = ++costGeneration
    costTarget.value = { environmentId, invocationId }
    costLoading.value = true
    try {
      const value = await invocationApi.costByInvocationId(environmentId, invocationId)
      if (!current(start) || generation !== costGeneration || !canCost.value) return
      cost.value = value
    } catch (cause) {
      if (current(start) && generation === costGeneration && canCost.value)
        costError.value = cause instanceof HttpError && cause.code === 403
          ? '此调用不在当前正式可读取范围，或费用归属证明不可用。'
          : '原调用费用读取失败，请重新查询；未知金额不会按零显示。'
    } finally { if (current(start) && generation === costGeneration) costLoading.value = false }
  }
</script>
