<template>
  <div v-if="recoveryMeta" class="my-3">
    <p>待确认原请求号：{{ recoveryMeta.request_key }}</p>
    <p class="text-sm">只保存请求摘要。恢复只查询原请求结果；结果未确认前不能再次提交或更换请求号。</p>
    <ElButton :loading="busy" :disabled="writeFlight" @click="queryOriginal">查询原请求结果</ElButton>
    <p v-if="rejectionCode">正式拒绝业务码：{{ rejectionCode }}。此请求号不会再次执行。</p>
    <ElButton v-if="rejectionCode" :disabled="busy || writeFlight" @click="acknowledgeRejected">确认拒绝并修正输入</ElButton>
  </div>

  <ElDivider>原件与人工核对 · {{ line.external_line_id }}</ElDivider>
  <ElDescriptions :column="1" border>
    <ElDescriptionsItem label="原账单金额"
      >{{ line.amount }} {{ line.currency }}</ElDescriptionsItem
    >
    <ElDescriptionsItem label="数量 / 计量口径"
      >{{ line.quantity }} / {{ line.meter_code }}</ElDescriptionsItem
    >
    <ElDescriptionsItem label="发生时段 UTC"
      >{{ line.occurred_start }} 至 {{ line.occurred_end }}</ElDescriptionsItem
    >
    <ElDescriptionsItem label="来源身份">{{
      line.identity_state === 'verified' ? '来源身份已核验' : '来源身份未核验'
    }}</ElDescriptionsItem>
  </ElDescriptions>
  <ElAlert
    v-if="!canReview"
    title="需要账单审核权限才能处理原件或查看审核历史。"
    type="info"
    :closable="false"
    class="mt-3"
  />
  <template v-else>
    <ElAlert v-if="error" :title="error" type="warning" :closable="false" class="mt-3" />
    <ElAlert
      v-if="uploadUnknown || clearUnknown || reviewUnknown"
      title="操作结果尚未确认。保留当前行与原请求，只能查询记录或显式恢复原操作。"
      type="warning"
      :closable="false"
      class="mt-3"
    />
    <ElAlert
      v-if="uploadPending || clearPending"
      :title="evidenceState ? evidenceLabel(evidenceState) : '原件尚未可用'"
      type="warning"
      :closable="false"
      class="mt-3"
    />
    <ElAlert
      v-if="conflict"
      title="服务器拒绝此请求，可能存在最终审核或请求冲突。请查询历史检查；不会换键覆盖原记录。"
      type="warning"
      :closable="false"
      class="mt-3"
    />
    <div class="mt-3 flex flex-wrap gap-3">
      <label
        >CSV / PDF / XLSX 原件（1 字节至 10 MiB）<input
          type="file"
          accept=".csv,.pdf,.xlsx,text/csv,application/pdf,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
          :disabled="locked"
          @change="chooseFile"
      /></label>
      <ElButton :disabled="!file || locked" @click="upload">上传原件</ElButton>
      <ElButton v-if="canResumeUpload" :loading="busy" @click="upload">按原文件恢复上传</ElButton>
    </div>
    <p v-if="file" class="mt-3 text-sm"
      >已选择：{{ file.name }} · {{ file.size }} 字节；原件以私有方式保存，上传不会解析、认证厂商身份或创建账单行。</p
    >
    <ElForm class="mt-3" label-position="top" :disabled="locked">
      <ElFormItem label="已有原件 ID（须属于本服务商）"
        ><ElInput v-model="evidenceIdText"
      /></ElFormItem>
    </ElForm>
    <p v-if="evidence" class="text-sm"
      >服务器 SHA：{{ evidence.sha256 }} · {{ evidenceLabel(evidence.state) }}</p
    >
    <div class="mt-3 flex flex-wrap gap-3">
      <ElButton
        :disabled="busy || locked || !selectedEvidenceId || !canUseEvidence"
        @click="download()"
        >下载原件</ElButton
      >
      <ElButton
        :disabled="
          busy ||
          writeFlight ||
          recoveryMeta !== null ||
          confirming ||
          uploadUnknown ||
          attempt !== null ||
          !selectedEvidenceId ||
          evidenceState === 'clearing'
        "
        @click="clearEvidence"
        >清除原件</ElButton
      >
      <ElButton
        v-if="clearUnknown || evidenceState === 'clearing'"
        :loading="busy"
        @click="clearEvidence"
        >按原 ID 继续清除</ElButton
      >
    </div>
    <ElForm
      class="mt-3"
      label-position="top"
      :disabled="
        locked ||
        !!rejectionCode ||
        (finalReview && !recoveryMeta) ||
        !canUseEvidence ||
        !historyLoaded ||
        historyLoading ||
        !!historyError
      "
    >
      <ElFormItem label="原件位置（人工填写，最多 256 字，不含换行）"
        ><ElInput v-model="locator" maxlength="256" placeholder="例如：用量明细!第 2 行；PDF 第 3 页第 1 条"
      /></ElFormItem>
      <ElFormItem label="审核说明（必填，最多 2000 字，不含换行或控制字符）"
        ><ElInput v-model="note" maxlength="2000"
      /></ElFormItem>
      <ElFormItem v-if="line.meter_code === 'total_tokens'" label="审核粒度">
        <ElRadioGroup v-model="granularity"
          ><ElRadio value="aggregate">期间记录</ElRadio
          ><ElRadio value="request">核对逐请求明细</ElRadio></ElRadioGroup
        >
      </ElFormItem>
      <ElAlert
        v-else
        title="期间汇总账单仅记录期间核对，不分摊到单次调用。"
        type="info"
        :closable="false"
        class="mb-3"
      />
      <template v-if="granularity === 'request' && line.meter_code === 'total_tokens'">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
          <ElFormItem label="环境 ID"><ElInput v-model="environmentId" /></ElFormItem>
          <ElFormItem label="用量事件 ID"><ElInput v-model="usageEventId" /></ElFormItem>
        </div>
        <ElCheckbox v-model="requestConfirmed"
          >已人工核对原件账户、逐请求粒度与关联依据；此声明不是厂商认证。</ElCheckbox
        >
      </template>
      <div class="mt-3 flex flex-wrap gap-3">
        <ElButton type="primary" @click="submit('compare')">{{
          granularity === 'request' ? '提交人工核对' : '记录期间核对'
        }}</ElButton>
        <ElButton @click="submit('reject')">标为拒绝</ElButton>
        <ElButton @click="submit('unresolved')">记为待核查</ElButton>
      </div>
    </ElForm>
    <ElAlert
      v-if="finalReview"
      title="此行已有最终决定；首版不支持修订或覆盖。清除原件不改变账单金额与审核历史。"
      type="info"
      :closable="false"
      class="mt-3"
    />
    <div v-if="attempt" class="mt-3">
      <p class="text-sm">冻结请求号：{{ attempt.idempotency_key }}</p>
      <ElButton :loading="busy" :disabled="busy || writeFlight" @click="queryOriginal">查询原审核结果</ElButton>
    </div>
    <ElDivider>审核历史（最近 100 条，时间为 UTC）</ElDivider>
    <ElButton :loading="historyLoading" :disabled="busy" @click="loadHistory"
      >查询审核记录</ElButton
    >
    <ElAlert
      v-if="historyError"
      :title="historyError"
      type="warning"
      :closable="false"
      class="mt-3"
    />
    <ElEmpty v-else-if="historyLoaded && history.length === 0" description="暂无人工审核记录" />
    <ElTable :data="history" v-loading="historyLoading" class="mt-3">
      <ElTableColumn prop="create_time" label="时间 UTC" min-width="170" />
      <ElTableColumn prop="admin_id" label="操作员 ID" width="100" />
      <ElTableColumn label="人工结果" min-width="170"
        ><template #default="{ row }">{{
          reviewLabel(row.comparison_state)
        }}</template></ElTableColumn
      >
      <ElTableColumn label="差额" min-width="160"
        ><template #default="{ row }"
          >数量 {{ row.quantity_difference ?? '未比较' }} / 金额
          {{ row.amount_difference ?? '未比较' }} {{ line.currency }}</template
        ></ElTableColumn
      >
      <ElTableColumn label="证据" min-width="210"
        ><template #default="{ row }"
          >ID {{ row.evidence_id }} ·
          {{ row.evidence_state === 'available' ? '原件可用' : '原件不可用' }}<br />SHA
          {{ row.evidence_sha256 }}</template
        ></ElTableColumn
      >
      <ElTableColumn label="追溯" min-width="220"><template #default="{ row }">
        <BillingCostTrace :trace="row.trace" :disabled="locked" />
        <ElButton v-if="row.evidence_state === 'available'" link type="primary" :disabled="locked" @click="download(row.evidence_id)">下载该审核原件</ElButton>
      </template></ElTableColumn>
      <ElTableColumn prop="evidence_locator" label="人工填写位置" min-width="150" />
      <ElTableColumn prop="note" label="说明" min-width="180" />
      <ElTableColumn prop="reason" label="比较说明" min-width="180" />
      <ElTableColumn prop="idempotency_key" label="原请求号" min-width="220" />
    </ElTable>
  </template>
</template>
<script setup lang="ts">
  import { computed, defineAsyncComponent, onBeforeUnmount, onMounted, ref, watch } from 'vue'
  import { ElMessageBox } from 'element-plus'
  import { HttpError } from '@/utils/http/error'
  import { billingInputSha, recoverBillingRequest, useBillingJournal } from '../../api/billing-recovery'
  import type { BillingRequestMeta } from '../../api/billing-recovery'
  import { useAuth } from '@/hooks/core/useAuth'
  import { billingApi, billingError, positiveId } from '../../api/billing'
  import { evidenceLabel, reviewLabel } from '../../api/billing-types'
  import type {
    BillingEvidence,
    BillingLine,
    BillingReview,
    BillingReviewInput
  } from '../../api/billing-types'
  const BillingCostTrace = defineAsyncComponent(() => import('./billing-cost-trace.vue'))
  const props = defineProps<{ line: BillingLine; providerId: number; recoveryKey?: string }>()
  const emit = defineEmits<{ pending: [value: boolean]; reviewed: [review: BillingReview] }>()
  const { hasAuth } = useAuth()
  const journal = useBillingJournal()
  const recoveryMeta = ref<BillingRequestMeta | null>(null)
  const rejectionCode = ref('')
  let recoveryGeneration = 0
  let componentAlive = true
  let ownerEpoch = 0
  const currentOwner = (actor: number | null, epoch: number = ownerEpoch) => componentAlive && epoch === ownerEpoch && journal.current(actor)
  function restoreMeta() {
    journal.reload()
    rejectionCode.value = ''
    recoveryMeta.value = journal.entries.value.find(row => row.operation === 'review' && row.billing_line_id === props.line.billing_line_id && (!props.recoveryKey || row.request_key === props.recoveryKey)) ?? null
    if (journal.storageError.value) error.value = journal.storageError.value
  }
  async function queryOriginal() {
    const entry = recoveryMeta.value
    const actor = journal.owner.value
    const actorEpoch = ownerEpoch
    if (!entry || !actor || busy.value || writeFlight.value) return
    const generation = ++recoveryGeneration
    busy.value = true
    try {
      const response = await recoverBillingRequest(entry, actor)
      if (!currentOwner(actor, actorEpoch) || generation !== recoveryGeneration) return
      if (response.state === 'rejected') {
        rejectionCode.value = response.rejection.error_code
        conflict.value = true
        reviewUnknown.value = false
        error.value = '服务器已正式拒绝此原请求；确认拒绝后才能修正输入并提交下一次操作。'
        return
      }
      rejectionCode.value = ''
      if (response.state === 'unknown') {
        reviewUnknown.value = true
        error.value = '原请求结果仍未知。这不表示尚未提交。请保留请求号并继续查询，不要重新提交或更换请求号。'
        return
      }
      journal.remove(entry.request_key, actor)
      recoveryMeta.value = null
      attempt.value = null
      reviewUnknown.value = false
      conflict.value = false
      error.value = ''
      if (response.review) { emit('reviewed', response.review); await loadHistory() }
    } catch (cause) { if (currentOwner(actor, actorEpoch) && generation === recoveryGeneration) error.value = billingError(cause) }
    finally { if (currentOwner(actor, actorEpoch) && generation === recoveryGeneration) busy.value = false }
  }
  async function remember(input: { idempotency_key: string }, providerId: number, lineId?: number, environment?: number) {
    if (rejectionCode.value) throw new Error('请先确认原请求正式拒绝，再修正输入。')
    const actor = journal.owner.value
    const actorEpoch = ownerEpoch
    const sha = await billingInputSha(input)
    if (!currentOwner(actor, actorEpoch)) throw new Error('管理员已改变，原请求未发送')
    if (recoveryMeta.value && recoveryMeta.value.input_sha256 !== sha) throw new Error('输入与原请求摘要不一致，未发送；请查询原请求结果。')
    const row = { operation: 'review' as const, request_key: input.idempotency_key, input_sha256: sha, provider_id: providerId,
      ...(lineId ? { billing_line_id: lineId } : {}), ...(environment ? { environment_id: environment } : {}) }
    return { actor, row }
  }
  watch([journal.owner, journal.epoch], () => {
    ++ownerEpoch
    rejectionCode.value = ''
    error.value = ''
    ++recoveryGeneration
    attempt.value = null
    recoveryMeta.value = null
    busy.value = false
    reviewUnknown.value = false
    confirmingReset()
    restoreMeta()
  }, { flush: 'sync' })
  onMounted(() => { restoreMeta(); void queryOriginal() })
  onBeforeUnmount(() => { componentAlive = false; ++ownerEpoch; ++recoveryGeneration })
  function confirmingReset() { confirming.value = false; conflict.value = false; ++historyGeneration; file.value = null; evidence.value = null; evidenceIdText.value = ''; locator.value = ''; note.value = ''; environmentId.value = ''; usageEventId.value = ''; history.value = []; historyLoaded.value = false; historyLoading.value = false; historyError.value = ''; uploadUnknown.value = false; clearUnknown.value = false; clearId.value = null; requestConfirmed.value = false; granularity.value = 'aggregate' }

  const canReview = computed(() => hasAuth('sand_ai:billing:review'))
  const confirming = ref(false)
  const busy = ref(false)
  const writeFlight = ref(false)
  const error = ref('')
  const file = ref<File | null>(null)
  const evidence = ref<BillingEvidence | null>(null)
  const evidenceIdText = ref('')
  const selectedEvidenceId = computed(() => positiveId(evidenceIdText.value))
  const evidenceState = computed(
    () =>
      evidence.value?.state ??
      history.value.find((row) => row.evidence_id === selectedEvidenceId.value)?.evidence_state ??
      null
  )
  const canUseEvidence = computed(
    () => evidenceState.value === null || evidenceState.value === 'available'
  )
  const uploadUnknown = ref(false)
  const canResumeUpload = computed(
    () =>
      file.value !== null &&
      evidenceState.value !== 'clearing' &&
      (uploadUnknown.value || evidenceState.value === 'upload_pending')
  )
  const uploadPending = computed(() => evidenceState.value === 'upload_pending')
  const clearPending = computed(() => evidenceState.value === 'clearing')
  const clearUnknown = ref(false)
  const clearId = ref<number | null>(null)
  const reviewUnknown = ref(false)
  const conflict = ref(false)
  const attempt = ref<BillingReviewInput | null>(null)
  const locked = computed(
    () =>
      busy.value ||
      writeFlight.value ||
      confirming.value ||
      uploadUnknown.value ||
      uploadPending.value ||
      clearPending.value ||
      clearUnknown.value ||
      attempt.value !== null ||
      recoveryMeta.value !== null
  )
  const locator = ref('')
  const note = ref('')
  const granularity = ref<'aggregate' | 'request'>('aggregate')
  const environmentId = ref('')
  const usageEventId = ref('')
  const requestConfirmed = ref(false)
  const history = ref<BillingReview[]>([])
  const historyLoading = ref(false)
  const historyLoaded = ref(false)
  const historyError = ref('')
  const finalReview = computed(() =>
    history.value.some((row) => row.decision === 'compare' || row.decision === 'reject')
  )
  let historyGeneration = 0
  watch(
    [busy, writeFlight, confirming, uploadUnknown, uploadPending, clearPending, clearUnknown, reviewUnknown],
    () =>
      emit(
        'pending',
        busy.value ||
          writeFlight.value ||
          confirming.value ||
          uploadUnknown.value ||
          uploadPending.value ||
          clearPending.value ||
          clearUnknown.value
      ),
    { immediate: true }
  )
  watch(evidenceIdText, (value) => {
    if (evidence.value && String(evidence.value.id) !== value) evidence.value = null
  })
  function chooseFile(event: Event) {
    if (!locked.value && event.target instanceof HTMLInputElement) {
      file.value = event.target.files?.item(0) ?? null
      evidence.value = null
      evidenceIdText.value = ''
    }
  }
  function uploadRejected(cause: unknown): boolean {
    if (!(cause instanceof HttpError) || ![400, 403, 404, 413, 415, 422].includes(cause.code)) return false
    // Business failures in a successful HTTP envelope have no transport marker.
    if (cause.transport === undefined) return true
    if (cause.transport !== 'http') return false
    const response = cause.data
    return response !== null && typeof response === 'object' && !Array.isArray(response) &&
      'code' in response && response.code === cause.code &&
      'message' in response && typeof response.message === 'string'
  }
  async function upload() {
    if (
      !canReview.value ||
      busy.value ||
      writeFlight.value ||
      confirming.value ||
      !file.value ||
      clearUnknown.value ||
      clearPending.value ||
      attempt.value
    )
      return
    if (
      file.value.size < 1 ||
      file.value.size > 10 * 1024 * 1024 ||
      !/\.(csv|pdf|xlsx)$/i.test(file.value.name)
    ) {
      error.value = '请选择 1 字节至 10 MiB 的 CSV、PDF 或 XLSX 原件；不支持 XLS 和 XLSM。'
      return
    }
    busy.value = true
    error.value = ''
    const operationOwner = journal.owner.value
    const operationOwnerEpoch = ownerEpoch
    const originalFile = file.value
    const providerId = props.providerId
    let dispatched = false
    try {
      const digest = await crypto.subtle.digest('SHA-256', await originalFile.arrayBuffer())
      if (!currentOwner(operationOwner, operationOwnerEpoch)) return
      const sha = Array.from(new Uint8Array(digest), value => value.toString(16).padStart(2, '0')).join('')
      dispatched = true
      const result = await billingApi.upload(providerId, originalFile)
      if (!currentOwner(operationOwner, operationOwnerEpoch)) return
      if (result.value.id < 1 || result.value.provider_id !== providerId ||
          result.value.sha256 !== sha || result.value.byte_size !== originalFile.size)
        throw new Error('原件回执与原文件不一致，请按原文件恢复。')
      evidence.value = result.value
      evidenceIdText.value = String(result.value.id)
      uploadUnknown.value = false
      if (result.upload_complete) file.value = null
      else
        error.value =
          result.value.state === 'upload_pending'
            ? '原件尚未上传完成。请按原文件恢复，或清除这个原件 ID。'
            : result.value.state === 'clearing'
              ? '原件正在清除，只能按原 ID 继续清除。'
              : '该原件不可用，同一文件不会恢复已清除原件。'
    } catch (cause) {
      if (!currentOwner(operationOwner, operationOwnerEpoch)) return
      error.value = dispatched && uploadRejected(cause)
        ? '上传被拒绝，请检查原件和服务商；可修正或取消。'
        : billingError(cause)
      if (dispatched) uploadUnknown.value = !uploadRejected(cause)
    } finally {
      if (currentOwner(operationOwner, operationOwnerEpoch)) busy.value = false
    }
  }
  async function download(id: number | null = selectedEvidenceId.value) {
    if (!canReview.value || locked.value || !id || !Number.isSafeInteger(id) || id < 1) return
    if (id === selectedEvidenceId.value && !canUseEvidence.value) return
    busy.value = true
    error.value = ''
    const operationOwner = journal.owner.value
    const operationOwnerEpoch = ownerEpoch
    try {
      const blob = await billingApi.download(id)
      if (!currentOwner(operationOwner, operationOwnerEpoch)) return
      const url = URL.createObjectURL(blob)
      try {
        const link = document.createElement('a')
        link.href = url
        const mediaType = blob.type.split(';')[0]
        const extension = mediaType === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
          ? 'xlsx' : mediaType === 'application/pdf' ? 'pdf' : 'csv'
        link.download = `billing-evidence.${extension}`
        link.click()
      } finally {
        URL.revokeObjectURL(url)
      }
    } catch (cause) {
      if (!currentOwner(operationOwner, operationOwnerEpoch)) return
      error.value = billingError(cause)
    } finally {
      if (currentOwner(operationOwner, operationOwnerEpoch)) busy.value = false
    }
  }
  async function clearEvidence() {
    if (!canReview.value || busy.value || confirming.value || uploadUnknown.value || attempt.value) return
    const clearOwner = journal.owner.value
    const clearEpoch = ownerEpoch
    const id = clearUnknown.value ? clearId.value : selectedEvidenceId.value
    if (!id) return
    if (!clearUnknown.value && !clearPending.value) {
      confirming.value = true
      try {
        await ElMessageBox.confirm(
          '仅清除原件正文，保留 SHA、账单金额与审核历史。此操作不可恢复原件，是否继续？',
          '清除原件',
          { type: 'warning' }
        )
      } catch {
        return
      } finally {
        confirming.value = false
      }
      if (!currentOwner(clearOwner, clearEpoch)) return
      clearId.value = id
    }
    clearId.value = id
    busy.value = true
    error.value = ''
    const operationOwner = journal.owner.value
    const operationOwnerEpoch = ownerEpoch
    try {
      const result = await billingApi.clear(id)
      if (!currentOwner(operationOwner, operationOwnerEpoch)) return
      evidence.value = result.evidence
      evidenceIdText.value = String(result.evidence.id)
      clearUnknown.value = result.evidence.state !== 'evidence_unavailable'
      if (!clearUnknown.value) file.value = null
      await loadHistory()
    } catch (cause) {
      if (!currentOwner(operationOwner, operationOwnerEpoch)) return
      error.value = billingError(cause)
      clearUnknown.value = true
    } finally {
      if (currentOwner(operationOwner, operationOwnerEpoch)) busy.value = false
    }
  }
  async function loadHistory() {
    if (!canReview.value) return
    const generation = ++historyGeneration
    historyLoading.value = true
    historyError.value = ''
    const operationOwner = journal.owner.value
    const operationOwnerEpoch = ownerEpoch
    try {
      const rows = await billingApi.history(props.line.billing_line_id)
      if (!currentOwner(operationOwner, operationOwnerEpoch) || generation !== historyGeneration) return
      history.value = rows
      historyLoaded.value = true
    } catch (cause) {
      if (currentOwner(operationOwner, operationOwnerEpoch) && generation === historyGeneration) historyError.value = billingError(cause)
    } finally {
      if (currentOwner(operationOwner, operationOwnerEpoch) && generation === historyGeneration) historyLoading.value = false
    }
  }
  async function submit(decision: BillingReview['decision']) {
    if (
      !canReview.value ||
      locked.value ||
      (finalReview.value && !recoveryMeta.value) ||
      !canUseEvidence.value ||
      !historyLoaded.value ||
      historyError.value
    )
      return
    const submittingOwner = journal.owner.value
    const submittingOwnerEpoch = ownerEpoch
    error.value = ''
    const evidenceId = selectedEvidenceId.value
    if (
      !evidenceId ||
      !locator.value.trim() ||
      !note.value.trim() ||
      /[\x00-\x1f\x7f]/.test(locator.value + note.value) ||
      [...locator.value].length > 256 ||
      [...note.value].length > 2000
    ) {
      error.value = '原件 ID、位置和说明为必填，且不能含换行或控制字符。'
      return
    }
    const common = {
      billing_line_id: props.line.billing_line_id,
      evidence_id: evidenceId,
      evidence_locator: locator.value,
      note: note.value,
      granularity: granularity.value,
      decision,
      idempotency_key: crypto.randomUUID()
    }
    let input: BillingReviewInput = common
    if (decision === 'compare' && granularity.value === 'request') {
      const env = positiveId(environmentId.value)
      const event = positiveId(usageEventId.value)
      if (props.line.meter_code !== 'total_tokens' || !env || !event || !requestConfirmed.value) {
        error.value = '逐请求核对必须填写真实环境和事件 ID，并明确确认原件依据。'
        return
      }
      input = {
        ...common,
        environment_id: env,
        usage_event_id: event,
        request_evidence_confirmed: true
      }
    }
    confirming.value = true
    try {
      await ElMessageBox.confirm(
        decision === 'unresolved'
          ? '记录待核查，允许后续补审核。是否提交？'
          : '此决定为最终决定，包括无法比较的结果；首版不支持改判。是否提交？',
        '人工核对',
        { type: 'warning' }
      )
    } catch {
      return
    } finally {
      if (currentOwner(submittingOwner, submittingOwnerEpoch)) confirming.value = false
    }
    if (!currentOwner(submittingOwner, submittingOwnerEpoch)) return
    attempt.value = input
    conflict.value = false
    await sendReview()
  }
  async function sendReview() {
    const input = attempt.value
    if (!canReview.value || busy.value || writeFlight.value || !input || conflict.value) return
    busy.value = true
    error.value = ''
    let persisted = false
    const actor = journal.owner.value
    const actorEpoch = ownerEpoch
    if (actor === null) { busy.value = false; return }
    writeFlight.value = true
    try {
      const prepared = await remember(input, props.providerId, props.line.billing_line_id, input.environment_id)
      if (!currentOwner(actor, actorEpoch) || prepared.actor !== actor) return
      journal.save(prepared.row, actor)
      persisted = true
      recoveryMeta.value = prepared.row
      const response = await billingApi.review(input)
      if (!currentOwner(actor, actorEpoch)) return
      if (response.value.billing_line_id !== input.billing_line_id || response.value.admin_id !== actor ||
          response.value.idempotency_key !== input.idempotency_key || response.value.evidence_id !== input.evidence_id ||
          (input.environment_id !== undefined && response.value.environment_id !== input.environment_id))
        throw new Error('审核回执与原请求目标不一致，保留原请求等待查询')
      const acknowledged = await recoverBillingRequest(prepared.row, actor)
      if (!currentOwner(actor, actorEpoch)) return
      if (acknowledged.state === 'rejected') {
        rejectionCode.value = acknowledged.rejection.error_code
        conflict.value = true
        throw new Error('服务器已正式拒绝此原请求；请查询拒绝凭据并明确确认后修正输入。')
      }
      if (acknowledged.state !== 'completed') throw new Error('服务器已响应，但原请求完成凭据仍未知，请查询原请求，不要另建操作。')
      if (!acknowledged.review || acknowledged.review.id !== response.value.id)
        throw new Error('原请求查询审核与保存回执不一致')
      journal.remove(prepared.row.request_key, actor)
      recoveryMeta.value = null
      history.value = [
        response.value,
        ...history.value.filter((row) => row.id !== response.value.id)
      ]
      historyLoaded.value = true
      attempt.value = null
      reviewUnknown.value = false
      conflict.value = false
      emit('reviewed', response.value)
      await loadHistory()
    } catch (cause) {
      if (!currentOwner(actor, actorEpoch)) return
      error.value = billingError(cause)
      reviewUnknown.value = !rejectionCode.value && (persisted || recoveryMeta.value !== null)
      if (!persisted) attempt.value = null
      if (cause instanceof HttpError && cause.code === 409) {
        conflict.value = true
        await loadHistory()
      }
    } finally {
      writeFlight.value = false
      if (currentOwner(actor, actorEpoch)) busy.value = false
    }
  }
  onMounted(loadHistory)
  onBeforeUnmount(() => {
    ++historyGeneration
  })

  async function acknowledgeRejected() {
    const entry = recoveryMeta.value
    const actor = journal.owner.value
    const actorEpoch = ownerEpoch
    if (!entry || !actor || !rejectionCode.value || busy.value || writeFlight.value) return
    busy.value = true
    try {
      await ElMessageBox.confirm(`原请求 ${entry.request_key} 已正式拒绝。确认后清除本机原请求记录；修正输入后需再次主动提交，才会创建新请求号。是否确认？`, '确认原请求拒绝', { type: 'warning' })
      if (!currentOwner(actor, actorEpoch)) return
      const proof = await recoverBillingRequest(entry, actor)
      if (!currentOwner(actor, actorEpoch)) return
      if (proof.state !== 'rejected' || proof.rejection.error_code !== rejectionCode.value)
        throw new Error('未重新取得一致的正式拒绝凭据，原请求记录继续保留。')
      journal.remove(entry.request_key, actor)
      recoveryMeta.value = null
      attempt.value = null
      rejectionCode.value = ''
      conflict.value = false
      reviewUnknown.value = false
      error.value = '已确认原请求被拒绝。请修正输入，再主动提交下一次操作。'
    } catch (cause) {
      if (cause !== 'cancel' && cause !== 'close' && currentOwner(actor, actorEpoch)) error.value = billingError(cause)
    } finally { if (currentOwner(actor, actorEpoch)) busy.value = false }
  }
</script>
