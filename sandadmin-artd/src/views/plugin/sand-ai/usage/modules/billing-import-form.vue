<template>
  <div v-if="recoveryMeta" class="my-3">
    <p>待确认原请求号：{{ recoveryMeta.request_key }}</p>
    <p class="text-sm">只保存请求摘要。恢复只查询原请求结果；结果未确认前不能再次提交或更换请求号。</p>
    <ElButton :loading="busy" :disabled="writeFlight" @click="queryOriginal">查询原请求结果</ElButton>
    <p v-if="rejectionCode">正式拒绝业务码：{{ rejectionCode }}。此请求号不会再次执行。</p>
    <ElButton v-if="rejectionCode" :disabled="busy || writeFlight" @click="acknowledgeRejected">确认拒绝并修正输入</ElButton>
  </div>

  <ElAlert
    title="导入只记录账单，不自动解析原件或核验厂商身份。绑定同一原件和相同位置后，跨批导入相同明细会复用原记录；内容不同会拒绝。所有时间为 UTC。"
    type="info"
    :closable="false"
    class="mb-3"
  />
  <ElAlert v-if="error" :title="error" type="warning" :closable="false" class="mb-3" />
  <ElAlert
    v-if="conflict"
    title="服务器拒绝此原请求（冲突或最终状态）。不会更换请求号覆盖；请核查原导入记录。"
    type="warning"
    :closable="false"
    class="mb-3"
  />
  <ElAlert
    v-if="unknownResult"
    title="导入结果尚未确认。输入和请求号已冻结，请按原请求恢复，不要另建导入。"
    type="warning"
    :closable="false"
    class="mb-3"
  />
  <ElForm label-position="top" :disabled="formLocked">
    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
      <ElFormItem label="服务商 ID"><ElInput v-model="draft.provider" /></ElFormItem>
      <ElFormItem label="来源代码（小写字母开头）"
        ><ElInput v-model="draft.source_kind"
      /></ElFormItem>
      <ElFormItem label="账期起 UTC"
        ><ElDatePicker
          v-model="draft.period_start"
          type="datetime"
          value-format="YYYY-MM-DD HH:mm:ss"
      /></ElFormItem>
      <ElFormItem label="账期止 UTC"
        ><ElDatePicker
          v-model="draft.period_end"
          type="datetime"
          value-format="YYYY-MM-DD HH:mm:ss"
      /></ElFormItem>
    </div>
    <ElFormItem label="参考号"><ElInput v-model="draft.reference" maxlength="256" /></ElFormItem>
    <ElCheckbox v-model="useEvidence">绑定原件，识别跨批重复明细</ElCheckbox>
    <template v-if="useEvidence">
      <p class="my-3 text-sm">上传本服务商的 CSV、PDF 或 Excel（.xlsx）原件，或填写已有原件 ID。文件以私有方式保存，摘要只证明内容一致；原件位置由你填写，不代表自动解析或厂商认证。期间汇总和未出账记录仍须按原粒度填写，不分摊到单次调用。</p>
      <div v-if="hasAuth('sand_ai:billing:review')" class="my-3 flex flex-wrap gap-3">
        <label>CSV / PDF / XLSX 原件（1 字节至 10 MiB）
          <input ref="fileInput" type="file" accept=".csv,.pdf,.xlsx,text/csv,application/pdf,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" :disabled="formLocked" @change="chooseFile" />
        </label>
        <ElButton :disabled="formLocked || !selectedFile" :loading="uploadBusy" @click="uploadEvidence">上传原件</ElButton>
      </div>
      <p v-else class="my-3 text-sm">上传原件需要账单审核权限；可由有权限的管理员上传后提供原件 ID。</p>
      <p v-if="selectedFile" class="my-3 text-sm">已选：{{ selectedFile.name }} · {{ selectedFile.size }} 字节。上传不会解析或导入明细。</p>
      <ElFormItem label="已有原件 ID（须属于本服务商）">
        <ElInput v-model="draft.evidenceId" placeholder="上传成功后自动填入，也可填写已有 ID" />
      </ElFormItem>
      <p v-if="uploadedEvidence && draft.evidenceId === String(uploadedEvidence.id)" class="my-3 text-sm">
        原件 {{ uploadedEvidence.id }}：{{ evidenceLabel(uploadedEvidence.state) }}；服务器 SHA-256：{{ uploadedEvidence.sha256 }}
      </p>
    </template>
    <p v-else class="my-3 text-sm">不绑定原件时，只按原请求号防止重复提交；不同请求号的重复明细不会自动复用。</p>
    <div v-for="(line, index) in draft.lines" :key="index" class="mb-3">
      <ElDivider>明细 {{ index + 1 }}</ElDivider>
      <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
        <ElFormItem label="外部行号"
          ><ElInput v-model="line.external_line_id" maxlength="128"
        /></ElFormItem>
        <ElFormItem v-if="useEvidence" label="原件位置（人工填写，每行唯一）">
          <ElInput v-model="line.evidence_locator" placeholder="例如：用量明细!第 2 行；PDF 第 3 页第 1 条" maxlength="256" />
          <p class="mt-1 text-sm">Excel 请填写原工作表名和行号；再次导入同一明细时，沿用完全相同的位置写法。</p>
        </ElFormItem>
        <ElFormItem label="模型代码"><ElInput v-model="line.model_code" /></ElFormItem>
        <ElFormItem label="能力代码"><ElInput v-model="line.capability_code" /></ElFormItem>
        <ElFormItem label="计量口径代码"><ElInput v-model="line.meter_code" /></ElFormItem>
        <ElFormItem label="数量（十进制文本）"><ElInput v-model="line.quantity" /></ElFormItem>
        <ElFormItem label="金额（十进制文本）"><ElInput v-model="line.amount" /></ElFormItem>
        <ElFormItem label="币种（三位大写）"
          ><ElInput v-model="line.currency" maxlength="3"
        /></ElFormItem>
        <ElFormItem label="发生起 UTC"
          ><ElDatePicker
            v-model="line.occurred_start"
            type="datetime"
            value-format="YYYY-MM-DD HH:mm:ss"
        /></ElFormItem>
        <ElFormItem label="发生止 UTC"
          ><ElDatePicker
            v-model="line.occurred_end"
            type="datetime"
            value-format="YYYY-MM-DD HH:mm:ss"
        /></ElFormItem>
      </div>
      <ElButton :disabled="draft.lines.length === 1" @click="draft.lines.splice(index, 1)"
        >删除此明细</ElButton
      >
    </div>
    <ElButton :disabled="draft.lines.length >= 500" @click="draft.lines.push(newLine())"
      >添加明细（最多 500 行）</ElButton
    >
  </ElForm>
  <ElAlert v-if="uploadError" :title="uploadError" type="warning" :closable="false" class="my-3" />
  <div v-if="uploadRecovery" class="my-3">
    <p>原件上传尚未完成或结果未确认。原服务商与文件已保留；请恢复同一文件，不要更换原件。</p>
    <ElButton :disabled="uploadBusy || busy || writeFlight || confirming" :loading="uploadBusy" @click="uploadEvidence">按原文件恢复上传</ElButton>
  </div>
  <div class="mt-3 flex gap-3">
    <ElButton v-if="!attempt" type="primary" :disabled="formLocked || !!rejectionCode" :loading="busy" @click="submit">确认导入</ElButton>
    <ElButton v-else type="primary" :disabled="busy || writeFlight" :loading="busy" @click="queryOriginal"
      >查询原导入结果</ElButton
    >
    <ElButton :disabled="busy || writeFlight || confirming || uploadBusy || uploadRecovery !== null" @click="emit('cancel')"
      >取消</ElButton
    >
  </div>
  <p v-if="attempt" class="mt-3 text-sm">原请求号：{{ attempt.idempotency_key }}</p>
</template>
<script setup lang="ts">
  import { computed, onMounted, onBeforeUnmount, reactive, ref, watch } from 'vue'
  import { ElMessageBox } from 'element-plus'
  import { HttpError } from '@/utils/http/error'
  import { billingInputSha, recoverBillingRequest, useBillingJournal } from '../../api/billing-recovery'
  import type { BillingRequestMeta } from '../../api/billing-recovery'
  import { useAuth } from '@/hooks/core/useAuth'
  import { billingApi, billingError, positiveId } from '../../api/billing'
  import { evidenceLabel } from '../../api/billing-types'
  import type {
    BillingEvidence,
    BillingDetail,
    BillingImportInput,
    BillingImportLineInput
  } from '../../api/billing-types'
  const props = defineProps<{ providerId?: number; recoveryKey?: string }>()
  const emit = defineEmits<{
    saved: [batch: BillingDetail, idempotentReplay: boolean]
    cancel: []
    pending: [value: boolean]
  }>()
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
    recoveryMeta.value = journal.entries.value.find(row => row.operation === 'import' && (!props.recoveryKey || row.request_key === props.recoveryKey)) ?? null
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
        unknownResult.value = false
        error.value = '服务器已正式拒绝此原请求；确认拒绝后才能修正输入并提交下一次操作。'
        return
      }
      rejectionCode.value = ''
      if (response.state === 'unknown') {
        unknownResult.value = true
        error.value = '原请求结果仍未知。这不表示尚未提交。请保留请求号并继续查询，不要重新提交或更换请求号。'
        return
      }
      journal.remove(entry.request_key, actor)
      recoveryMeta.value = null
      attempt.value = null
      unknownResult.value = false
      conflict.value = false
      error.value = ''
      if (response.billing_import) emit('saved', response.billing_import, true)
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
    const row = { operation: 'import' as const, request_key: input.idempotency_key, input_sha256: sha, provider_id: providerId,
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
    unknownResult.value = false
    confirmingReset()
    restoreMeta()
  }, { flush: 'sync' })
  onMounted(() => { restoreMeta(); void queryOriginal() })
  onBeforeUnmount(() => { componentAlive = false; ++ownerEpoch; ++recoveryGeneration })
  function confirmingReset() { confirming.value = false; conflict.value = false; resetEvidence(); useEvidence.value = false; Object.assign(draft, { provider: '', period_start: '', period_end: '', source_kind: '', reference: '', evidenceId: '', lines: [newLine()] }) }

  function newLine(): BillingImportLineInput & { evidence_locator: string } {
    return {
      evidence_locator: '',
      external_line_id: '',
      model_code: '',
      capability_code: '',
      meter_code: '',
      quantity: '',
      amount: '',
      currency: '',
      occurred_start: '',
      occurred_end: ''
    }
  }
  const draft = reactive({
    provider: props.providerId ? String(props.providerId) : '',
    period_start: '',
    period_end: '',
    source_kind: '',
    reference: '',
    evidenceId: '',
    lines: [newLine()]
  })
  const useEvidence = ref(false)
  const fileInput = ref<HTMLInputElement | null>(null)
  const selectedFile = ref<File | null>(null)
  const uploadedEvidence = ref<BillingEvidence | null>(null)
  const uploadBusy = ref(false)
  const uploadError = ref('')
  type UploadAttempt = { providerId: number; file: File; sha256: string }
  const uploadRecovery = ref<UploadAttempt | null>(null)
  function resetEvidence() {
    if (fileInput.value) fileInput.value.value = ''
    selectedFile.value = null
    uploadedEvidence.value = null
    uploadRecovery.value = null
    uploadError.value = ''
    draft.evidenceId = ''
    for (const line of draft.lines) line.evidence_locator = ''
  }
  watch(() => draft.provider, resetEvidence, { flush: 'sync' })
  const confirming = ref(false)
  const conflict = ref(false)
  const busy = ref(false)
  const writeFlight = ref(false)
  const unknownResult = ref(false)
  const error = ref('')
  const attempt = ref<BillingImportInput | null>(null)
  const formLocked = computed(() => busy.value || writeFlight.value || confirming.value ||
    uploadBusy.value || uploadRecovery.value !== null || attempt.value !== null || recoveryMeta.value !== null)
  watch(
    [busy, writeFlight, unknownResult, confirming, uploadBusy, uploadRecovery],
    () => emit('pending', busy.value || writeFlight.value || confirming.value || uploadBusy.value || uploadRecovery.value !== null),
    { immediate: true }
  )
  const code = /^[a-z][a-z0-9_.-]{1,95}$/
  const decimal = /^(0|[1-9][0-9]{0,13})(\.[0-9]{1,6})?$/
  const locatorText = (value: string) => value.replace(/^[ \t\n\r\v\0]+|[ \t\n\r\v\0]+$/g, '')
  function chooseFile(event: Event) {
    if (formLocked.value || !(event.target instanceof HTMLInputElement)) return
    const file = event.target.files?.[0] ?? null
    uploadError.value = ''
    uploadedEvidence.value = null
    draft.evidenceId = ''
    if (file && (!/\.(csv|pdf|xlsx)$/i.test(file.name) || file.size < 1 || file.size > 10485760)) {
      selectedFile.value = null
      event.target.value = ''
      uploadError.value = '请选择 1 字节至 10 MiB 的 CSV、PDF 或 XLSX 原件；不支持 XLS 和 XLSM。'
      return
    }
    selectedFile.value = file
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
  async function uploadEvidence() {
    if (!hasAuth('sand_ai:billing:review') || uploadBusy.value || busy.value || writeFlight.value ||
        confirming.value || attempt.value || recoveryMeta.value) return
    const actor = journal.owner.value
    const actorEpoch = ownerEpoch
    if (actor === null) return
    uploadBusy.value = true
    uploadError.value = ''
    let dispatched = false
    try {
      let original = uploadRecovery.value
      if (original === null) {
        const providerId = positiveId(draft.provider)
        const file = selectedFile.value
        if (!providerId || !file) throw new Error('请先填写服务商 ID 并选择原件。')
        const digest = await crypto.subtle.digest('SHA-256', await file.arrayBuffer())
        if (!currentOwner(actor, actorEpoch)) return
        original = { providerId, file, sha256: Array.from(new Uint8Array(digest), value => value.toString(16).padStart(2, '0')).join('') }
        uploadRecovery.value = original
      }
      if (!currentOwner(actor, actorEpoch) || positiveId(draft.provider) !== original.providerId)
        throw new Error('原件上传对象已改变，本次未发送。')
      dispatched = true
      const response = await billingApi.upload(original.providerId, original.file)
      if (!currentOwner(actor, actorEpoch)) return
      if (response.value.id < 1 || response.value.provider_id !== original.providerId || response.value.sha256 !== original.sha256 ||
          response.value.byte_size !== original.file.size)
        throw new Error('原件回执与原文件不一致，请按原文件恢复。')
      uploadedEvidence.value = response.value
      draft.evidenceId = String(response.value.id)
      if (response.upload_complete && response.value.state === 'available') {
        uploadRecovery.value = null
      } else if (response.value.state === 'upload_pending') {
        uploadError.value = '原件上传尚未完成，请按原文件显式恢复。'
      } else {
        uploadRecovery.value = null
        uploadError.value = '此原件已清除或不可用，不能用于导入。请核查原件记录。'
      }
    } catch (cause) {
      if (!currentOwner(actor, actorEpoch)) return
      const rejected = dispatched && uploadRejected(cause)
      if (!dispatched || rejected) uploadRecovery.value = null
      if (rejected) {
        uploadedEvidence.value = null
        draft.evidenceId = ''
      }
      uploadError.value = rejected
        ? '上传被拒绝，请检查原件和服务商；可修正或取消。'
        : dispatched
        ? `未确认原件上传完成，已保留原文件。${billingError(cause)}`
        : billingError(cause)
    } finally {
      uploadBusy.value = false
    }
  }
  function validTime(value: string): boolean {
    return (
      /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(value) &&
      new Date(`${value.replace(' ', 'T')}Z`).toISOString().replace('T', ' ').slice(0, 19) === value
    )
  }
  async function submit() {
    if (!hasAuth('sand_ai:billing:import') || formLocked.value)
      return
    const submittingOwner = journal.owner.value
    const submittingOwnerEpoch = ownerEpoch
    error.value = ''
    try {
      const providerId = positiveId(draft.provider)
      const evidenceId = useEvidence.value ? positiveId(draft.evidenceId) : null
      if (useEvidence.value && (!evidenceId ||
          (uploadedEvidence.value?.id === evidenceId && uploadedEvidence.value.state !== 'available')))
        throw new Error('请先上传可用原件，或填写属于本服务商的可用原件 ID。')
      if (
        !providerId ||
        !code.test(draft.source_kind) ||
        !draft.reference.trim() ||
        !validTime(draft.period_start) ||
        !validTime(draft.period_end) ||
        draft.period_end <= draft.period_start
      )
        throw new Error('请填写服务商、合法来源代码、参考号与完整 UTC 账期。')
      const ids = new Set<string>()
      const locators = new Set<string>()
      for (const line of draft.lines) {
        if (useEvidence.value) {
          const locator = locatorText(line.evidence_locator)
          if (!locator || [...locator].length > 256 || /[\x00-\x1f\x7f-\x9f]/.test(locator) || locators.has(locator))
            throw new Error('请为每行填写唯一的原件位置，最多 256 字，不含换行或控制字符。')
          locators.add(locator)
        }
        if (
          !line.external_line_id.trim() ||
          ids.has(line.external_line_id.trim()) ||
          ![line.model_code, line.capability_code, line.meter_code].every((value) =>
            code.test(value)
          ) ||
          !decimal.test(line.quantity) ||
          !decimal.test(line.amount) ||
          !/^[A-Z]{3}$/.test(line.currency) ||
          !validTime(line.occurred_start) ||
          !validTime(line.occurred_end) ||
          line.occurred_end <= line.occurred_start ||
          line.occurred_start < draft.period_start ||
          line.occurred_end > draft.period_end
        )
          throw new Error(
            '请检查行号唯一性、代码、非负十进制（最多 14 位整数及 6 位小数）、币种和账期内发生时段。'
          )
        ids.add(line.external_line_id.trim())
      }
      const input: BillingImportInput = {
        provider_id: providerId,
        period_start: draft.period_start,
        period_end: draft.period_end,
        source_kind: draft.source_kind,
        reference: draft.reference,
        ...(evidenceId === null ? {} : { evidence_id: evidenceId }),
        lines: draft.lines.map(({ evidence_locator, ...line }) => ({
          ...line, ...(evidenceId === null ? {} : { evidence_locator: locatorText(evidence_locator) })
        })),
        idempotency_key: crypto.randomUUID()
      }
      confirming.value = true
      await ElMessageBox.confirm(
        `服务商 ${input.provider_id}，${input.lines.length} 行，账期 ${input.period_start} 至 ${input.period_end} UTC。${evidenceId === null ? '未绑定原件，不做跨批明细去重。' : `绑定原件 ${evidenceId}，按人工填写的位置识别同一明细；不代表厂商已核验。`}导入金额保持原文；是否记录？`,
        '确认导入',
        { type: 'warning' }
      )
      if (!currentOwner(submittingOwner, submittingOwnerEpoch)) return
      attempt.value = input
      await send()
    } catch (cause) {
      if (!currentOwner(submittingOwner, submittingOwnerEpoch)) return
      if (cause !== 'cancel' && cause !== 'close') error.value = billingError(cause)
    } finally {
      if (currentOwner(submittingOwner, submittingOwnerEpoch)) confirming.value = false
    }
  }
  async function send() {
    const input = attempt.value
    if (!hasAuth('sand_ai:billing:import') || busy.value || writeFlight.value || conflict.value || !input) return
    busy.value = true
    error.value = ''
    let persisted = false
    const actor = journal.owner.value
    const actorEpoch = ownerEpoch
    if (actor === null) { busy.value = false; return }
    writeFlight.value = true
    try {
      const prepared = await remember(input, input.provider_id)
      if (!currentOwner(actor, actorEpoch) || prepared.actor !== actor) return
      journal.save(prepared.row, actor)
      persisted = true
      recoveryMeta.value = prepared.row
      const result = await billingApi.import(input)
      if (!currentOwner(actor, actorEpoch)) return
      if (result.value.provider_id !== input.provider_id || result.value.period_start !== input.period_start ||
          result.value.period_end !== input.period_end || result.value.source_kind !== input.source_kind || result.value.reference !== input.reference)
        throw new Error('导入回执与原请求目标不一致，保留原请求等待查询')
      const acknowledged = await recoverBillingRequest(prepared.row, actor)
      if (!currentOwner(actor, actorEpoch)) return
      if (acknowledged.state === 'rejected') {
        rejectionCode.value = acknowledged.rejection.error_code
        conflict.value = true
        throw new Error('服务器已正式拒绝此原请求；请查询拒绝凭据并明确确认后修正输入。')
      }
      if (acknowledged.state !== 'completed') throw new Error('服务器已响应，但原请求完成凭据仍未知，请查询原请求，不要另建操作。')
      if (!acknowledged.billing_import || acknowledged.billing_import.id !== result.value.id)
        throw new Error('原请求查询批次与导入回执不一致')
      journal.remove(prepared.row.request_key, actor)
      recoveryMeta.value = null
      unknownResult.value = false
      attempt.value = null
      emit('saved', result.value, result.idempotent_replay)
    } catch (cause) {
      if (!currentOwner(actor, actorEpoch)) return
      error.value = billingError(cause)
      unknownResult.value = !rejectionCode.value && (persisted || recoveryMeta.value !== null)
      if (!persisted) attempt.value = null
      conflict.value = !!rejectionCode.value || (cause instanceof HttpError && cause.code === 409)
      
    } finally {
      writeFlight.value = false
      if (currentOwner(actor, actorEpoch)) busy.value = false
    }
  }

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
      unknownResult.value = false
      error.value = '已确认原请求被拒绝。请修正输入，再主动提交下一次操作。'
    } catch (cause) {
      if (cause !== 'cancel' && cause !== 'close' && currentOwner(actor, actorEpoch)) error.value = billingError(cause)
    } finally { if (currentOwner(actor, actorEpoch)) busy.value = false }
  }
</script>
