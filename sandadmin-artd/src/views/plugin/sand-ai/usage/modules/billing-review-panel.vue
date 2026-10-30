<template>
  <ElCard class="art-table-card">
    <template #header
      ><div class="flex flex-wrap items-center justify-between gap-3"
        ><span>账单核对</span
        ><div
          ><ElButton
            v-if="hasAuth('sand_ai:billing:import')"
            :disabled="pending || recoveryBusy"
            @click="recoveryKey = ''; journal.reload(); importOpen = true"
            >导入账单</ElButton
          ><ElButton v-if="hasAuth('sand_ai:billing:sync') || hasAuth('sand_ai:billing:credential')"
            :disabled="pending || recoveryBusy" @click="recoveryKey = ''; journal.reload(); syncOpen = true">官方账单同步与凭证</ElButton
          ><ElButton v-if="canReadHistory" @click="historyOpen = true">查看账单历史与原调用费用</ElButton
          ><ElButton :disabled="pending || recoveryBusy" :loading="loading" @click="load">刷新</ElButton></div
        ></div
      ></template
    >
    <div class="flex flex-wrap items-center gap-3 mb-3"
      ><label>服务商 ID</label
      ><ElInput
        v-model="providerText"
        :disabled="scopeLocked"
        class="max-w-xs"
        placeholder="留空查看全部获权批次"
      /><ElButton :disabled="pending || recoveryBusy" @click="load">筛选</ElButton
      ><span class="text-sm">最近 100 个导入批次，时间为 UTC</span></div
    >
    <div class="mb-3">
      <ElButton :disabled="pending || recoveryBusy" @click="journal.reload">查看本管理员原请求记录</ElButton>
      <ElAlert v-if="journal.storageError.value" :title="journal.storageError.value" type="warning" :closable="false" />
      <p v-for="entry in journal.entries.value" :key="entry.request_key" class="my-2">
        {{ entry.operation === 'import' ? '账单导入' : entry.operation === 'sync' ? '官方同步' : '人工审核' }} · 服务商 {{ entry.provider_id }}<span v-if="entry.billing_line_id"> · 行 {{ entry.billing_line_id }}</span> · 原请求号 {{ entry.request_key }}
        <ElButton :disabled="pending || recoveryBusy" @click="openOriginal(entry)">查询并打开原操作</ElButton>
        <ElButton v-if="rejectedEntry?.request_key === entry.request_key" :disabled="pending || recoveryBusy" @click="confirmOriginalRejection(entry)">确认正式拒绝并结束原记录</ElButton>
      </p>
      <ElAlert v-if="recoveryNotice" :title="recoveryNotice" type="info" :closable="false" />
    </div>
    <ElAlert v-if="resultNotice" :title="resultNotice" type="info" :closable="false" class="mb-3" />
    <ElAlert v-if="error" :title="error" type="warning" :closable="false" />
    <ElEmpty v-else-if="!loading && batches.length === 0" description="暂无账单导入记录" />
    <ElTable v-else :data="batches" v-loading="loading">
      <ElTableColumn prop="id" label="批次" width="90" /><ElTableColumn
        prop="provider_id"
        label="服务商 ID"
        width="110"
      />
      <ElTableColumn label="账期 UTC" min-width="300"
        ><template #default="{ row }"
          >{{ row.period_start }} 至 {{ row.period_end }}</template
        ></ElTableColumn
      >
      <ElTableColumn prop="source_kind" label="来源" min-width="140" /><ElTableColumn
        prop="reference"
        label="参考号"
        min-width="140"
      /><ElTableColumn prop="create_time" label="导入时间 UTC" min-width="170" />
      <ElTableColumn label="操作" width="120"
        ><template #default="{ row }"
          ><ElButton link type="primary" :disabled="pending || recoveryBusy" @click="openBatch(row.id)"
            >查看明细</ElButton
          ></template
        ></ElTableColumn
      >
    </ElTable>
    <p v-if="!hasAuth('sand_ai:billing:import')" class="mt-3 text-sm"
      >导入权限需由管理员单独授予。</p
    >
  </ElCard>
  <ElDialog
    v-model="importOpen"
    title="导入账单"
    width="min(1100px, 95vw)"
    :before-close="closeImport"
    :close-on-click-modal="!pending"
    :close-on-press-escape="!pending"
    destroy-on-close
  >
    <template #header>
      <div class="flex flex-wrap items-center gap-3">
        <span>导入账单</span>
        <ElButton v-if="canReadHistory" @click.stop="historyOpen = true">查看账单历史与原调用费用</ElButton>
      </div>
    </template>
    <BillingImportForm
      :recovery-key="recoveryKey" :provider-id="filterProviderId ?? undefined"
      @pending="importPending = $event"
      @cancel="importOpen = false"
      @saved="importSaved"
    />
  </ElDialog>
  <ElDialog v-model="syncOpen" title="官方账单同步与凭证" width="min(900px, 95vw)"
    :before-close="closeSync" :close-on-click-modal="!pending" :close-on-press-escape="!pending" destroy-on-close>
    <template #header>
      <div class="flex flex-wrap items-center gap-3">
        <span>官方账单同步与凭证</span>
        <ElButton v-if="canReadHistory" @click.stop="historyOpen = true">查看账单历史与原调用费用</ElButton>
      </div>
    </template>
    <BillingSyncForm :recovery-key="recoveryKey" :provider-id="filterProviderId ?? undefined" @pending="syncPending = $event"
      @cancel="syncOpen = false" @saved="syncSaved" @no-data="syncNoData" />
  </ElDialog>
  <ElDialog
    v-model="detailOpen"
    :title="detail ? `账单明细 · 批次 ${detail.id}` : '账单明细'"
    width="min(1200px, 95vw)"
    :before-close="closeDetail"
    :close-on-click-modal="!pending"
    :close-on-press-escape="!pending"
    destroy-on-close
  >
    <template #header>
      <div class="flex flex-wrap items-center gap-3">
        <span>{{ detail ? `账单明细 · 批次 ${detail.id}` : '账单明细' }}</span>
        <ElButton v-if="canReadHistory" @click.stop="historyOpen = true">查看账单历史与原调用费用</ElButton>
      </div>
    </template>
    <div v-if="detailLoading" v-loading="detailLoading" class="min-h-24" />
    <ElAlert v-else-if="detailError" :title="detailError" type="warning" :closable="false" />
    <template v-else-if="detail">
      <ElDescriptions :column="1" border
        ><ElDescriptionsItem label="服务商 ID">{{ detail.provider_id }}</ElDescriptionsItem
        ><ElDescriptionsItem label="账期 UTC"
          >{{ detail.period_start }} 至 {{ detail.period_end }}</ElDescriptionsItem
        ><ElDescriptionsItem label="来源 / 参考号"
          >{{ detail.source_kind }} / {{ detail.reference }}</ElDescriptionsItem
        ></ElDescriptions
      >
      <p class="mt-3 mb-2 text-sm">
        本批 {{ detail.lines.length }} 行：{{ pendingLineCount }} 行待人工处理，
        {{ processedLineCount }} 行已核对或已人工处理，{{ unknownReviewCount }} 行人工处理状态未提供。
        重复事实引用 {{ duplicateLineCount }} 行，引用状态未提供 {{ unknownDuplicateCount }} 行。
      </p>
      <p class="mb-2 text-sm text-gray-500">
        人工处理状态来自原行审核记录，不能代替自动核对结果或准确费用。
        重复引用沿用同一账单事实；本批多行引用同一事实时均标为重复，不能再将这些金额相加。
      </p>
      <ElSelect v-model="lineFilter" aria-label="筛选本批核账项" class="w-56">
        <ElOption label="本批全部行" value="all" />
        <ElOption label="待人工处理" value="pending" />
        <ElOption label="自动已核对" value="reconciled" />
        <ElOption label="未匹配或有歧义" value="unmatched" />
        <ElOption label="自动或人工有差异" value="discrepancy" />
        <ElOption label="重复事实引用" value="duplicate" />
        <ElOption label="已核对或已人工处理" value="processed" />
        <ElOption label="处理或引用状态未提供" value="unknown" />
      </ElSelect>
      <ElTable :data="visibleLines" class="mt-3" empty-text="当前筛选没有账单行">
        <ElTableColumn prop="external_line_id" label="行号" min-width="120" /><ElTableColumn
          label="模型 / 能力"
          min-width="160"
          ><template #default="{ row }"
            >{{ row.model_code }} / {{ row.capability_code }}</template
          ></ElTableColumn
        >
        <ElTableColumn prop="meter_code" label="计量口径" min-width="130" /><ElTableColumn
          prop="quantity"
          label="数量"
          min-width="120"
        />
        <ElTableColumn label="事实引用" min-width="170">
          <template #default="{ row }">
            {{ row.is_duplicate === undefined ? '引用状态未提供' : row.is_duplicate ? '重复事实引用' : '本批新事实' }}
            · 原行 {{ row.billing_line_id }}
          </template>
        </ElTableColumn>
        <ElTableColumn label="账单金额" min-width="150"
          ><template #default="{ row }"
            >{{ row.amount }} {{ row.currency }}</template
          ></ElTableColumn
        >
        <ElTableColumn label="来源身份" min-width="140"
          ><template #default="{ row }">{{
            row.identity_state === 'verified' ? '来源身份已核验' : '来源身份未核验'
          }}</template></ElTableColumn
        >
        <ElTableColumn label="自动核对" min-width="140"
          ><template #default="{ row }">{{
            reconciliationLabel(row.reconciliation?.state)
          }}</template></ElTableColumn
        >
        <ElTableColumn label="核对差额 / 说明" min-width="270">
          <template #default="{ row }">
            数量 {{ row.reconciliation?.quantity_difference ?? '未知' }} / 金额 {{ row.reconciliation?.amount_difference ?? '未知' }} {{ row.currency }}<br />
            {{ reconciliationReason(row.reconciliation?.reason) }}
          </template>
        </ElTableColumn>
        <ElTableColumn label="调用追溯" min-width="200">
          <template #default="{ row }"><BillingCostTrace :trace="row.trace" :disabled="pending || recoveryBusy" /></template>
        </ElTableColumn>
        <ElTableColumn label="人工审核" min-width="170"
          ><template #default="{ row }">{{
            operatorReviewLabel(row)
          }}</template></ElTableColumn
        >
        <ElTableColumn label="操作" width="100"
          ><template #default="{ row }"
            ><ElButton link type="primary" :disabled="pending || recoveryBusy" @click="selectLine(row)"
              >查看行</ElButton
            ></template
          ></ElTableColumn
        >
      </ElTable>
      <BillingReviewForm
        v-if="selectedLine"
        :key="selectedLine.billing_line_id"
        :recovery-key="recoveryKey" :line="selectedLine"
        :provider-id="detail.provider_id"
        @pending="reviewPending = $event"
        @reviewed="onReviewed"
      />
    </template>
  </ElDialog>
  <BillingHistoryBrowser :key="journal.epoch.value" v-model="historyOpen" />
</template>
<script setup lang="ts">
  import { computed, defineAsyncComponent, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
  import { recoverBillingRequest, useBillingJournal } from '../../api/billing-recovery'
  import type { BillingRequestMeta } from '../../api/billing-recovery'
  import { ElMessageBox } from 'element-plus'
  import { useAuth } from '@/hooks/core/useAuth'
  import { billingApi, billingError, positiveId } from '../../api/billing'
  import { reconciliationLabel, reconciliationReason, reviewLabel } from '../../api/billing-types'
  import type {
    BillingBatch,
    BillingDetail,
    BillingLine,
    BillingReview
  } from '../../api/billing-types'
  const emit = defineEmits<{ pending: [value: boolean] }>()
  const { hasAuth } = useAuth()
  const historyOpen = ref(false)
  const canReadHistory = computed(() => hasAuth('sand_ai:billing:index') || hasAuth('sand_ai:billing:review') || hasAuth('sand_ai:usage:cost'))
  const BillingHistoryBrowser = defineAsyncComponent(() => import('./billing-history-browser.vue'))
  const journal = useBillingJournal()
  const recoveryKey = ref('')
  const recoveryBusy = ref(false)
  const recoveryNotice = ref('')
  const rejectedEntry = ref<BillingRequestMeta | null>(null)
  const rejectedCode = ref('')
  let panelAlive = true
  let recoveryGeneration = 0
  async function openOriginal(entry: BillingRequestMeta) {
    const actor = journal.owner.value
    if (!actor || pending.value || recoveryBusy.value) return
    const generation = ++recoveryGeneration
    const current = () => panelAlive && journal.current(actor) && generation === recoveryGeneration
    recoveryBusy.value = true
    recoveryNotice.value = ''
    importOpen.value = false
    syncOpen.value = false
    detailOpen.value = false
    selectedLine.value = null
    recoveryKey.value = entry.request_key
    try {
      const response = await recoverBillingRequest(entry, actor)
      if (!current()) return
      if (response.state === 'completed') {
        journal.remove(entry.request_key, actor)
        recoveryNotice.value = response.sync_status === 'no_data' ? '原同步请求已完成：此账期没有官方账单数据。' : '已查询确认原请求完成。'
        if (response.billing_import) { await openBatch(response.billing_import.id); return }
        if (response.review) onReviewed(response.review)
      } else if (response.state === 'rejected') {
        rejectedEntry.value = entry
        rejectedCode.value = response.rejection.error_code
        recoveryNotice.value = `原请求已正式拒绝（${response.rejection.error_code}）。明确确认拒绝后再修正输入；原请求号仍保留。`
      }
      else recoveryNotice.value = '原请求结果仍未知，不代表尚未提交。请保留原请求号，只读查询原请求结果；结果确认前不能重新填写输入、再次导入或更换请求号。'
      if (entry.operation === 'import') { importOpen.value = true; return }
      if (entry.operation === 'sync') { syncOpen.value = true; return }
      const providerBatches = await billingApi.index(entry.provider_id)
      if (!current()) return
      for (const batch of providerBatches) {
        const original = await billingApi.read(batch.id)
        if (!current()) return
        const line = original.lines.find(row => row.billing_line_id === entry.billing_line_id)
        if (line) {
          detail.value = original
          detailOpen.value = true
          selectedLine.value = line
          return
        }
      }
      recoveryNotice.value += ` 未在最近批次中找到原账单行 ${entry.billing_line_id}，请求号仍保留在此入口。`
    } catch (cause) { if (current()) recoveryNotice.value = billingError(cause) }
    finally { if (current()) recoveryBusy.value = false }
  }

  async function confirmOriginalRejection(entry: BillingRequestMeta) {
    const actor = journal.owner.value
    if (!actor || pending.value || recoveryBusy.value || rejectedEntry.value?.request_key !== entry.request_key) return
    const generation = ++recoveryGeneration
    const current = () => panelAlive && journal.current(actor) && generation === recoveryGeneration
    const code = rejectedCode.value
    recoveryBusy.value = true
    try {
      await ElMessageBox.confirm(`原请求 ${entry.request_key} 已正式拒绝。确认后结束本机原记录，修正输入后仍需再次主动提交。是否确认？`, '确认原请求拒绝', { type: 'warning' })
      if (!current()) return
      const proof = await recoverBillingRequest(entry, actor)
      if (!current()) return
      if (proof.state !== 'rejected' || proof.rejection.error_code !== code)
        throw new Error('未重新取得一致的正式拒绝凭据，原请求记录继续保留。')
      journal.remove(entry.request_key, actor)
      rejectedEntry.value = null
      rejectedCode.value = ''
      importOpen.value = false
      syncOpen.value = false
      detailOpen.value = false
      selectedLine.value = null
      recoveryKey.value = ''
      recoveryNotice.value = '已确认原请求正式拒绝。请重新打开需要的操作，修正输入后主动提交。'
    } catch (cause) {
      if (cause !== 'cancel' && cause !== 'close' && current()) recoveryNotice.value = billingError(cause)
    } finally { if (current()) recoveryBusy.value = false }
  }
  const BillingImportForm = defineAsyncComponent(() => import('./billing-import-form.vue'))
  const BillingReviewForm = defineAsyncComponent(() => import('./billing-review-form.vue'))
  const BillingSyncForm = defineAsyncComponent(() => import('./billing-sync-form.vue'))
  const BillingCostTrace = defineAsyncComponent(() => import('./billing-cost-trace.vue'))
  const resultNotice = ref('')
  const syncOpen = ref(false)
  const syncPending = ref(false)
  const batches = ref<BillingBatch[]>([])
  const providerText = ref('')
  const filterProviderId = ref<number | null>(null)
  const loading = ref(false)
  const error = ref('')
  const importOpen = ref(false)
  const importPending = ref(false)
  const detailOpen = ref(false)
  const detailLoading = ref(false)
  const detailError = ref('')
  const detail = ref<BillingDetail | null>(null)
  const selectedLine = ref<BillingLine | null>(null)
  const reviewPending = ref(false)
  const reviewStates = ref<Record<number, BillingReview['comparison_state']>>({})
  const lineFilter = ref<'all' | 'pending' | 'reconciled' | 'unmatched' | 'discrepancy' | 'duplicate' | 'processed' | 'unknown'>('all')
  function operatorReviewState(line: BillingLine): BillingReview['comparison_state'] | null | undefined {
    return reviewStates.value[line.billing_line_id] ?? line.operator_review_state
  }
  function operatorReviewLabel(line: BillingLine): string {
    const state = operatorReviewState(line)
    if (state === undefined) return '人工处理状态未提供'
    return state === null ? '尚无人工审核' : reviewLabel(state)
  }
  function needsReview(line: BillingLine): boolean {
    const state = operatorReviewState(line)
    return (state === null || state === 'unresolved') && line.reconciliation?.state !== 'reconciled'
  }
  function processedLine(line: BillingLine): boolean {
    const state = operatorReviewState(line)
    return state !== undefined &&
      (line.reconciliation?.state === 'reconciled' || (state !== null && state !== 'unresolved'))
  }
  const pendingLineCount = computed(() => detail.value?.lines.filter(needsReview).length ?? 0)
  const processedLineCount = computed(() => detail.value?.lines.filter(processedLine).length ?? 0)
  const unknownReviewCount = computed(() => detail.value?.lines.filter(line => operatorReviewState(line) === undefined).length ?? 0)
  const duplicateLineCount = computed(() => detail.value?.lines.filter(line => line.is_duplicate === true).length ?? 0)
  const unknownDuplicateCount = computed(() => detail.value?.lines.filter(line => line.is_duplicate === undefined).length ?? 0)
  const visibleLines = computed(() => (detail.value?.lines ?? []).filter(line => {
    const state = operatorReviewState(line)
    switch (lineFilter.value) {
      case 'pending': return needsReview(line)
      case 'reconciled': return line.reconciliation?.state === 'reconciled'
      case 'unmatched': return line.reconciliation?.state === 'unmatched' || line.reconciliation?.state === 'ambiguous' || state === 'unmatched'
      case 'discrepancy': return line.reconciliation?.state === 'discrepancy' || state === 'reviewed_discrepancy'
      case 'duplicate': return line.is_duplicate === true
      case 'processed': return processedLine(line)
      case 'unknown': return state === undefined || line.is_duplicate === undefined
      default: return true
    }
  }))
  const pending = computed(() => importPending.value || reviewPending.value || syncPending.value)
  // Keep form drafts and uncertain writes attached to their original provider/batch.
  const scopeLocked = computed(() => pending.value || recoveryBusy.value || importOpen.value || syncOpen.value || detailOpen.value)
  let indexGeneration = 0
  let detailGeneration = 0
  watch(pending, (value) => emit('pending', value), { immediate: true })
  function invalidateIndex() {
    ++indexGeneration
    batches.value = []
    loading.value = false
    error.value = ''
    filterProviderId.value = null
  }
  watch(providerText, () => { invalidateIndex(); resultNotice.value = '' }, { flush: 'sync' })
  async function load() {
    if (!hasAuth('sand_ai:billing:index') || pending.value || recoveryBusy.value) return
    invalidateIndex()
    const id = providerText.value === '' ? undefined : positiveId(providerText.value)
    if (id === null) {
      error.value = '服务商 ID 必须是正整数。'
      return
    }
    const generation = ++indexGeneration
    loading.value = true
    error.value = ''
    filterProviderId.value = id ?? null
    try {
      const rows = await billingApi.index(id)
      if (generation === indexGeneration && hasAuth('sand_ai:billing:index')) batches.value = rows
    } catch (cause) {
      if (generation === indexGeneration) error.value = billingError(cause)
    } finally {
      if (generation === indexGeneration) loading.value = false
    }
  }
  async function openBatch(id: number) {
    if (!hasAuth('sand_ai:billing:index') || pending.value) return
    const generation = ++detailGeneration
    detailOpen.value = true
    detailLoading.value = true
    detailError.value = ''
    detail.value = null
    selectedLine.value = null
    reviewStates.value = {}
    lineFilter.value = 'all'
    try {
      const row = await billingApi.read(id)
      if (generation === detailGeneration && hasAuth('sand_ai:billing:index')) detail.value = row
    } catch (cause) {
      if (generation === detailGeneration) detailError.value = billingError(cause)
    } finally {
      if (generation === detailGeneration) detailLoading.value = false
    }
  }
  function selectLine(line: BillingLine) {
    if (!pending.value && !recoveryBusy.value) { recoveryKey.value = ''; selectedLine.value = line }
  }
  function closeImport(done: () => void) {
    if (!pending.value) {
      importPending.value = false
      journal.reload()
      done()
    }
  }
  function closeDetail(done: () => void) {
    if (!pending.value) {
      ++detailGeneration
      selectedLine.value = null
      detail.value = null
      reviewPending.value = false
      done()
    }
  }
  function closeSync(done: () => void) { if (!pending.value) { journal.reload(); done() } }
  function syncNoData() {
    syncPending.value = false
    syncOpen.value = false
    resultNotice.value = '所选官方账期没有返回账单行；这不表示现金支出为零。'
  }
  async function syncSaved(batch: BillingDetail, replay: boolean) {
    syncPending.value = false
    syncOpen.value = false
    resultNotice.value = replay ? '已恢复原同步批次，未重复导入。' : '官方账单已记录；核对结果与人工审核分别显示。'
    await load()
    await openBatch(batch.id)
  }
  async function importSaved(batch: BillingDetail, replay: boolean) {
    importPending.value = false
    importOpen.value = false
    resultNotice.value = replay ? '已恢复原导入批次，未重复导入。' : '账单明细已记录，来源身份与核对状态仍以逐行结果为准。' 
    await load()
    await openBatch(batch.id)
  }
  function onReviewed(review: BillingReview) {
    if (detail.value?.lines.some((line) => line.billing_line_id === review.billing_line_id))
      reviewStates.value[review.billing_line_id] = review.comparison_state
  }
  watch([journal.owner, journal.epoch], () => {
    ++recoveryGeneration
    ++indexGeneration
    ++detailGeneration
    recoveryBusy.value = false
    recoveryKey.value = ''
    recoveryNotice.value = ''
    rejectedEntry.value = null
    rejectedCode.value = ''
    resultNotice.value = ''
    historyOpen.value = false
    lineFilter.value = 'all'
    loading.value = false
    detailLoading.value = false
    error.value = ''
    detailError.value = ''
    providerText.value = ''
    filterProviderId.value = null
    batches.value = []
    detail.value = null
    selectedLine.value = null
    reviewStates.value = {}
    importOpen.value = false
    syncOpen.value = false
    detailOpen.value = false
    importPending.value = false
    reviewPending.value = false
    syncPending.value = false
    const actor = journal.owner.value
    const generation = recoveryGeneration
    void nextTick(() => {
      if (actor !== null && panelAlive && journal.current(actor) && generation === recoveryGeneration)
        void load()
    })
  }, { flush: 'sync' })
  onMounted(load)
  onBeforeUnmount(() => {
    panelAlive = false
    ++recoveryGeneration
    ++indexGeneration
    ++detailGeneration
  })
</script>
