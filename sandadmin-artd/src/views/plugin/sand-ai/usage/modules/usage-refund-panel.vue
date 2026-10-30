<template>
  <div ref="panelElement" tabindex="-1">
    <ElCard class="art-table-card">
      <template #header>真实退款记录</template>
      <ElAlert title="此处记录已发生的真实退款，不发起厂商退款。须由平台超级管理员人工核对原调用、已核账金额与同服务商私有退款凭证；记录不改写原用量或原账单。" type="info" :closable="false" class="mb-3" />
      <ElAlert v-if="recovery" :title="attempt ? '原退款记录请求尚待确认。输入与请求号已冻结；查询原环境、事件的分页记录，再核对原请求号与详情。未找到不代表未写入。' : '存在待确认的原退款请求，重载后原草稿已丢失。仅可查询原环境、事件及请求号对应的记录，不会自动发送或另换请求号。'" type="warning" :closable="false" class="mb-3" />
      <p v-if="recovery" class="mb-3 text-sm">原请求号：{{ recovery.idempotency_key }} · 管理员 {{ recovery.admin_id }} · 环境 {{ recovery.environment_id }} · 用量事件 {{ recovery.usage_event_id }}</p>
      <ElAlert v-if="error" :title="error" type="warning" :closable="false" class="mb-3" />
      <ElAlert v-if="message" :title="message" type="success" :closable="false" class="mb-3" />
      <ElButton v-if="storageBlocked" class="mb-3" :disabled="pending" @click="retryRecovery">重试读取原请求引用</ElButton>
      <ElForm label-position="top" :disabled="pending">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
          <ElFormItem label="环境 ID"><ElInput v-model="query.environment" /></ElFormItem>
          <ElFormItem label="用量事件 ID"><ElInput v-model="query.event" /></ElFormItem>
        </div>
      </ElForm>
      <ElButton v-if="hasAuth('sand_ai:usage:adjustment:index')" :disabled="writeBusy || listBusy || detailBusy" :loading="listBusy" @click="load(1)">{{ recovery ? '查询原请求记录' : '查询退款记录与原已核账金额' }}</ElButton>
      <p v-else class="mt-3 text-sm">查看退款记录需单独授予查看权限，并由后端核验平台超级管理员身份。</p>
      <template v-if="pageData">
        <ElDescriptions class="my-3" :column="1" border>
          <ElDescriptionsItem label="原已核账金额">{{ money(pageData.totals.gross_billed_amount, pageData.totals.currency) }}</ElDescriptionsItem>
          <ElDescriptionsItem label="已记录退款">{{ money(pageData.totals.refund_amount, pageData.totals.currency) }}</ElDescriptionsItem>
          <ElDescriptionsItem label="扣除已记录退款后的账单金额">{{ money(pageData.totals.net_billed_amount, pageData.totals.currency) }}</ElDescriptionsItem>
        </ElDescriptions>
        <p v-if="pageData.totals.state === 'bill_unavailable'" class="my-3 text-sm">原调用尚无可信已核账金额，退款上限无法确定，暂不可新增记录。</p>
        <ElTable :data="pageData.data">
          <ElTableColumn prop="id" label="记录 ID" width="100" />
          <ElTableColumn prop="idempotency_key" label="原请求号" min-width="280" />
          <ElTableColumn label="退款金额" min-width="150"><template #default="{ row }">{{ row.refund_amount }} {{ row.currency }}</template></ElTableColumn>
          <ElTableColumn prop="provider_refund_reference" label="服务商退款引用" min-width="160" />
          <ElTableColumn prop="create_time" label="记录时间 UTC" min-width="180" />
          <ElTableColumn label="操作" min-width="180"><template #default="{ row }"><ElButton link type="primary" :disabled="writeBusy || detailBusy" @click="read(row)">{{ recovery && row.idempotency_key === recovery.idempotency_key ? '读取原请求详情' : '查看详情' }}</ElButton></template></ElTableColumn>
        </ElTable>
        <ElPagination class="mt-3" layout="prev, pager, next, total" :total="pageData.total" :page-size="pageData.per_page" :current-page="pageData.current_page" :disabled="listBusy || detailBusy || writeBusy" @current-change="load" />
      </template>
      <template v-if="selectedRecord">
        <ElDivider>退款记录 {{ selectedRecord.id }}</ElDivider>
        <ElDescriptions :column="1" border>
          <ElDescriptionsItem label="环境 / 用量事件">{{ selectedRecord.environment_id }} / {{ selectedRecord.usage_event_id }}</ElDescriptionsItem>
          <ElDescriptionsItem label="原请求号">{{ selectedRecord.idempotency_key }}</ElDescriptionsItem>
          <ElDescriptionsItem label="原已核账金额">{{ selectedRecord.original_billed_amount }} {{ selectedRecord.currency }}</ElDescriptionsItem>
          <ElDescriptionsItem label="退款金额">{{ selectedRecord.refund_amount }} {{ selectedRecord.currency }}</ElDescriptionsItem>
          <ElDescriptionsItem label="服务商退款引用">{{ selectedRecord.provider_refund_reference }}</ElDescriptionsItem>
          <ElDescriptionsItem label="私有凭证 ID / 定位">{{ selectedRecord.evidence_id }} / {{ selectedRecord.evidence_locator }}</ElDescriptionsItem>
          <ElDescriptionsItem label="凭证状态">{{ selectedRecord.evidence_state === 'available' ? '私有原件可用' : '私有原件不可用，历史记录仍保留' }}</ElDescriptionsItem>
          <ElDescriptionsItem label="审核说明">{{ selectedRecord.note }}</ElDescriptionsItem>
          <ElDescriptionsItem label="审核管理员 / 时间 UTC">{{ selectedRecord.admin_id }} / {{ selectedRecord.create_time }}</ElDescriptionsItem>
          <ElDescriptionsItem label="事实依据">人工核对的退款凭证；金额依据为原已核账金额。</ElDescriptionsItem>
        </ElDescriptions>
      </template>
      <template v-if="hasAuth('sand_ai:usage:adjustment:refund')">
        <ElDivider>记录已发生的真实退款</ElDivider>
        <p class="mb-3 text-sm">先查询目标环境和用量事件。私有凭证 ID 来自账单原件上传入口，定位填写页码或行号；该原件必须属于原服务商且完整可读取。</p>
        <ElForm label-position="top" :disabled="pending || !canRecord">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <ElFormItem label="私有退款凭证 ID"><ElInput v-model="draft.evidence" /></ElFormItem>
            <ElFormItem label="凭证定位（页码或行号）"><ElInput v-model="draft.locator" maxlength="256" /></ElFormItem>
            <ElFormItem label="服务商实际退款引用"><ElInput v-model="draft.reference" maxlength="128" /></ElFormItem>
            <ElFormItem label="实际退款金额（正数，最多六位小数）"><ElInput v-model="draft.amount" /></ElFormItem>
          </div>
          <ElFormItem label="原已核账币种"><ElInput :model-value="pageData?.totals.currency ?? ''" disabled /></ElFormItem>
          <ElFormItem label="人工审核说明"><ElInput v-model="draft.note" type="textarea" maxlength="2000" :rows="3" /></ElFormItem>
          <ElCheckbox v-model="draft.confirmed" @change="confirmRefundDraft">我已人工核对实际退款凭证、原调用、币种及金额；私有原件与原服务商匹配。</ElCheckbox>
        </ElForm>
        <ElButton v-if="!recovery" class="mt-3" type="primary" :disabled="pending || !canRecord" :loading="writeBusy" @click="submit">记录已发生的真实退款</ElButton>
        <ElButton v-else-if="hasAuth('sand_ai:usage:adjustment:index')" class="mt-3" type="primary" :disabled="writeBusy || listBusy || detailBusy" :loading="listBusy" @click="load(1)">只读查询原请求记录</ElButton>
      </template>
    </ElCard>
  </div>
</template>
<script setup lang="ts">
  import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue'
  import { useAuth } from '@/hooks/core/useAuth'
  import { useUserStore } from '@/store/modules/user'
  import { HttpError } from '@/utils/http/error'
  import { billingError, positiveId, uncertainWrite } from '../../api/billing'
  import { matchesRefund, normalizedRefundAmount, usageAdjustmentApi } from '../../api/usage-adjustment'
  import type { UsageAdjustment, UsageAdjustmentPage, UsageRefundInput, UsageRefundSelection } from '../../api/usage-adjustment'
  const props = defineProps<{ selection?: Readonly<UsageRefundSelection> }>()
  const emit = defineEmits<{ pending: [value: boolean] }>()
  const { hasAuth } = useAuth()
  const userStore = useUserStore()
  const actor = computed(() => {
    const id = userStore.info.id
    return typeof id === 'number' && Number.isSafeInteger(id) && id > 0 ? id : null
  })
  type Recovery = UsageRefundSelection & { admin_id: number; idempotency_key: string }
  const panelElement = ref<HTMLElement | null>(null)
  defineExpose({ focus: () => { panelElement.value?.scrollIntoView({ block: 'start' }); panelElement.value?.focus() } })
  const query = reactive({ environment: '', event: '' })
  const draft = reactive({ evidence: '', locator: '', reference: '', amount: '', note: '', confirmed: false })
  const confirmation = ref<string | null>(null)
  const recovery = ref<Recovery | null>(null)
  const attempt = ref<UsageRefundInput | null>(null)
  const pageData = ref<UsageAdjustmentPage | null>(null)
  const loadedSelection = ref<UsageRefundSelection | null>(null)
  const selectedRecord = ref<UsageAdjustment | null>(null)
  const listBusy = ref(false)
  const detailBusy = ref(false)
  const currentWriteBusy = ref(false)
  const writeFlight = ref(false)
  const writeBusy = computed(() => currentWriteBusy.value || writeFlight.value)
  const conflict = ref(false)
  const error = ref('')
  const message = ref('')
  const storageBlocked = ref(false)
  let alive = true
  let identityEpoch = 0
  let listGeneration = 0
  let detailGeneration = 0
  const pending = computed(() => writeBusy.value || listBusy.value || detailBusy.value || recovery.value !== null)
  watch(pending, value => emit('pending', value), { immediate: true })
  function namespace(adminId: number): string { return `sand-ai:usage-refund:pending:admin:${adminId}` }
  function currentScope(): UsageRefundSelection | null {
    if (recovery.value) return { environment_id: recovery.value.environment_id, usage_event_id: recovery.value.usage_event_id }
    const environment = positiveId(query.environment)
    const event = positiveId(query.event)
    return environment && event ? { environment_id: environment, usage_event_id: event } : null
  }
  function sameScope(left: UsageRefundSelection | null, right: UsageRefundSelection | null): boolean {
    return left !== null && right !== null && left.environment_id === right.environment_id && left.usage_event_id === right.usage_event_id
  }
  const canRecord = computed(() => !storageBlocked.value && actor.value !== null && sameScope(currentScope(), loadedSelection.value) && pageData.value !== null && pageData.value.totals.state !== 'bill_unavailable' && pageData.value.totals.currency !== null)
  function money(amount: string | null, currency: string | null): string {
    return amount === null || currency === null ? '尚不可确定' : `${amount} ${currency}`
  }
  function confirmationSnapshot(): string | null {
    const selection = currentScope()
    const adminId = actor.value
    const bill = pageData.value?.totals
    if (!selection || adminId === null || !sameScope(selection, loadedSelection.value) || !bill
      || bill.state === 'bill_unavailable' || bill.gross_billed_amount === null || bill.currency === null) return null
    return JSON.stringify({
      admin_id: adminId, identity_epoch: identityEpoch,
      environment_id: selection.environment_id, usage_event_id: selection.usage_event_id,
      bill: { state: bill.state, gross_billed_amount: bill.gross_billed_amount, currency: bill.currency,
        refund_amount: bill.refund_amount, net_billed_amount: bill.net_billed_amount, adjustment_count: bill.adjustment_count },
      refund: { evidence: draft.evidence, locator: draft.locator, reference: draft.reference, amount: draft.amount, note: draft.note }
    })
  }
  function confirmRefundDraft(value: unknown): void {
    confirmation.value = value === true ? confirmationSnapshot() : null
    if (value === true && confirmation.value === null) {
      draft.confirmed = false
      error.value = '请先读取当前环境、用量事件及原已核账金额，再人工确认退款内容。'
    }
  }
  watch(confirmationSnapshot, value => {
    if (!draft.confirmed || value === confirmation.value) return
    draft.confirmed = false
    confirmation.value = null
    message.value = '退款内容或原账单事实已变化，请重新人工核对并确认；原待确认请求不会因此解除。'
  }, { flush: 'sync' })
  function clearDraft(): void {
    confirmation.value = null
    Object.assign(draft, { evidence: '', locator: '', reference: '', amount: '', note: '', confirmed: false })
  }
  function restore(adminId: number | null): void {
    recovery.value = null
    storageBlocked.value = false
    if (adminId === null) return
    try {
      const value = sessionStorage.getItem(namespace(adminId))
      if (value === null) return
      const row: unknown = JSON.parse(value)
      if (!row || typeof row !== 'object' || Array.isArray(row) || !('admin_id' in row) || row.admin_id !== adminId ||
          !('environment_id' in row) || typeof row.environment_id !== 'number' || !Number.isSafeInteger(row.environment_id) || row.environment_id < 1 ||
          !('usage_event_id' in row) || typeof row.usage_event_id !== 'number' || !Number.isSafeInteger(row.usage_event_id) || row.usage_event_id < 1 ||
          !('idempotency_key' in row) || typeof row.idempotency_key !== 'string' || !/^[a-zA-Z0-9-]{1,128}$/.test(row.idempotency_key))
        throw new Error('原请求引用无效')
      recovery.value = { admin_id: adminId, environment_id: row.environment_id, usage_event_id: row.usage_event_id, idempotency_key: row.idempotency_key }
      query.environment = String(row.environment_id)
      query.event = String(row.usage_event_id)
    } catch {
      storageBlocked.value = true
      error.value = '无法读取原退款请求引用，暂不可新增。仍可查询退款记录；请恢复浏览器会话存储后重试读取引用。'
    }
  }
  function retryRecovery(): void {
    if (pending.value) return
    error.value = ''
    restore(actor.value)
    if (!storageBlocked.value && !recovery.value) message.value = '原请求引用读取完成；当前没有待确认引用。'
  }
  function persist(reference: Recovery): void {
    const value = JSON.stringify(reference)
    sessionStorage.setItem(namespace(reference.admin_id), value)
    if (sessionStorage.getItem(namespace(reference.admin_id)) !== value) throw new Error('原请求引用保存失败')
  }
  function release(reference: Recovery): boolean {
    try {
      const current = sessionStorage.getItem(namespace(reference.admin_id))
      if (current !== JSON.stringify(reference)) throw new Error('原请求引用已改变')
      sessionStorage.removeItem(namespace(reference.admin_id))
      if (sessionStorage.getItem(namespace(reference.admin_id)) !== null) throw new Error('原请求引用未清除')
      recovery.value = null
      attempt.value = null
      conflict.value = false
      clearDraft()
      return true
    } catch {
      error.value = '服务器记录已核对，但原请求引用无法清除。请恢复浏览器会话存储后，再读取原请求详情；不会新增请求。'
      return false
    }
  }
  watch([actor, () => userStore.accessToken], ([adminId]) => {
    identityEpoch++; listGeneration++; detailGeneration++
    attempt.value = null
    pageData.value = null; loadedSelection.value = null; selectedRecord.value = null
    listBusy.value = false; detailBusy.value = false; currentWriteBusy.value = false
    conflict.value = false; error.value = ''; message.value = ''; clearDraft()
    query.environment = ''; query.event = ''
    restore(adminId)
    if (!recovery.value && !storageBlocked.value && props.selection) {
      query.environment = String(props.selection.environment_id)
      query.event = String(props.selection.usage_event_id)
    }
  }, { immediate: true, flush: 'sync' })
  watch(() => props.selection, selection => {
    if (pending.value || !selection) return
    query.environment = String(selection.environment_id)
    query.event = String(selection.usage_event_id)
  }, { deep: true })
  watch([() => query.environment, () => query.event], () => {
    listGeneration++; detailGeneration++
    if (!recovery.value) { pageData.value = null; loadedSelection.value = null; selectedRecord.value = null; clearDraft(); message.value = '' }
  })
  function current(epoch: number, adminId: number): boolean { return alive && epoch === identityEpoch && actor.value === adminId }
  async function load(page: number): Promise<void> {
    if (!hasAuth('sand_ai:usage:adjustment:index') || writeBusy.value || listBusy.value || detailBusy.value) return
    const selection = currentScope()
    const adminId = actor.value
    if (!selection || adminId === null) { error.value = '请填写合法环境 ID、用量事件 ID，并确认当前管理员身份。'; return }
    const generation = ++listGeneration
    const epoch = identityEpoch
    listBusy.value = true
    error.value = ''
    try {
      const response = await usageAdjustmentApi.index({ ...selection, page, limit: 20 })
      if (!current(epoch, adminId) || generation !== listGeneration) return
      pageData.value = response
      loadedSelection.value = selection
      if (recovery.value && !response.data.some(row => row.idempotency_key === recovery.value?.idempotency_key && row.admin_id === adminId))
        message.value = '本页未找到原请求记录，请继续翻页核查；这不能证明原请求未写入。'
    } catch (failure: unknown) { if (current(epoch, adminId) && generation === listGeneration) error.value = billingError(failure) }
    finally { if (current(epoch, adminId) && generation === listGeneration) listBusy.value = false }
  }
  async function read(row: UsageAdjustment): Promise<void> {
    if (!hasAuth('sand_ai:usage:adjustment:index') || detailBusy.value || writeBusy.value) return
    const adminId = actor.value
    if (adminId === null || !sameScope(row, loadedSelection.value)) return
    const original = recovery.value
    if (original && row.idempotency_key === original.idempotency_key &&
        row.admin_id === adminId && attempt.value && !matchesRefund(row, attempt.value)) {
      conflict.value = true
      error.value = '列表中的原请求记录与冻结内容不一致，请核查；不会更换请求号覆盖。'
      return
    }
    const epoch = identityEpoch
    const generation = ++detailGeneration
    detailBusy.value = true
    error.value = ''
    try {
      const response = await usageAdjustmentApi.read({ environment_id: row.environment_id, id: row.id })
      if (!current(epoch, adminId) || generation !== detailGeneration) return
      if (!sameScope(response.adjustment, loadedSelection.value)) throw new Error('退款详情与当前用量事件不一致')
      selectedRecord.value = response.adjustment
      const reference = recovery.value
      if (reference && row.idempotency_key === reference.idempotency_key && row.admin_id === adminId &&
          response.adjustment.idempotency_key === reference.idempotency_key && response.adjustment.admin_id === adminId && sameScope(response.adjustment, reference)) {
        if (!attempt.value) {
          message.value = '已找到原请求号对应的记录，详情仅供只读核查。原请求草稿已丢失，不能证明全部输入一致，不会另建请求。'
        } else if (matchesRefund(response.adjustment, attempt.value)) {
          if (release(reference)) message.value = `原请求已确认：退款记录 ${response.adjustment.id}。`
          if (pageData.value) pageData.value.totals = response.totals
        } else {
          conflict.value = true
          error.value = '原请求号对应记录与冻结内容不一致，请核查；不会更换请求号覆盖。'
        }
      }
    } catch (failure: unknown) { if (current(epoch, adminId) && generation === detailGeneration) error.value = billingError(failure) }
    finally { if (current(epoch, adminId) && generation === detailGeneration) detailBusy.value = false }
  }
  function validText(value: string, maximum: number): boolean {
    return value.trim().length > 0 && [...value].length <= maximum && !/[\u0000-\u001f\u007f-\u009f]/.test(value)
  }
  async function submit(): Promise<void> {
    if (!hasAuth('sand_ai:usage:adjustment:refund') || pending.value || !canRecord.value) return
    const selection = currentScope()
    const adminId = actor.value
    const evidenceId = positiveId(draft.evidence)
    const amount = normalizedRefundAmount(draft.amount)
    const currency = pageData.value?.totals.currency
    if (!draft.confirmed || confirmation.value === null || confirmation.value !== confirmationSnapshot()) {
      draft.confirmed = false
      confirmation.value = null
      error.value = '当前退款内容、原调用或已核账金额尚未人工确认，请重新核对后勾选确认。'
      return
    }
    if (!selection || adminId === null || !evidenceId || !amount || !currency || !draft.confirmed ||
        !validText(draft.locator, 256) || !validText(draft.reference, 128) || !validText(draft.note, 2000)) {
      error.value = '请填写完整私有凭证、定位、实际退款引用、正数金额和审核说明，并确认已人工核对。'
      return
    }
    const key = crypto.randomUUID()
    const reference: Recovery = { ...selection, admin_id: adminId, idempotency_key: key }
    const input: UsageRefundInput = { ...selection, evidence_id: evidenceId, evidence_locator: draft.locator.trim(), provider_refund_reference: draft.reference.trim(), refund_amount: amount, currency, provider_refund_confirmed: true, idempotency_key: key, note: draft.note.trim() }
    try {
      // Persist only opaque references before sending; never persist evidence contents or reviewer notes.
      if (sessionStorage.getItem(namespace(adminId)) !== null) throw new Error('已有原请求引用')
      persist(reference)
    } catch {
      error.value = '无法可靠保存原请求引用，尚未发送退款记录请求。请恢复浏览器会话存储后重试。'
      restore(adminId)
      return
    }
    recovery.value = reference
    attempt.value = input
    await send()
  }
  async function send(): Promise<void> {
    const input = attempt.value
    const reference = recovery.value
    const adminId = actor.value
    if (!hasAuth('sand_ai:usage:adjustment:refund') || !input || !reference || adminId === null || adminId !== reference.admin_id || writeBusy.value || listBusy.value || detailBusy.value || conflict.value) return
    const epoch = identityEpoch
    writeFlight.value = true; currentWriteBusy.value = true
    error.value = ''; message.value = ''
    try {
      // Only submit calls this write; uncertain results are recovered through index/read.
      if (sessionStorage.getItem(namespace(adminId)) !== JSON.stringify(reference)) throw new Error('原请求引用无法核对')
      const response = await usageAdjustmentApi.refund(input)
      if (!current(epoch, adminId)) return
      if (response.adjustment.admin_id !== adminId) throw new Error('退款记录审核身份与当前管理员不一致')
      selectedRecord.value = response.adjustment
      if (release(reference)) message.value = `真实退款已记录：${response.adjustment.id}${response.idempotent_replay ? '（原请求已处理）' : ''}。`
      if (pageData.value) pageData.value.totals = response.totals
    } catch (failure: unknown) {
      if (!current(epoch, adminId)) return
      error.value = billingError(failure)
      if (failure instanceof HttpError && failure.code === 409) conflict.value = true
      else if (!uncertainWrite(failure)) {
        if (release(reference)) error.value = '服务器明确拒绝此请求，未确认成功。请检查原已核账金额、私有凭证归属及实际退款内容。'
      }
    } finally {
      writeFlight.value = false
      if (current(epoch, adminId)) currentWriteBusy.value = false
    }
  }
  onBeforeUnmount(() => { alive = false; identityEpoch++; clearDraft(); attempt.value = null })
</script>
