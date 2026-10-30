<template>
  <div v-if="recoveryMeta" class="my-3">
    <p>待确认原请求号：{{ recoveryMeta.request_key }}</p>
    <p class="text-sm">只保存请求摘要。恢复只查询原请求结果；结果未确认前不能再次提交或更换请求号。</p>
    <ElButton :loading="syncBusy" :disabled="syncFlight" @click="queryOriginal">查询原请求结果</ElButton>
    <p v-if="rejectionCode">正式拒绝业务码：{{ rejectionCode }}。此请求号不会再次执行。</p>
    <ElButton v-if="rejectionCode" :disabled="syncBusy || syncFlight" @click="acknowledgeRejected">确认拒绝并修正输入</ElButton>
  </div>

  <ElAlert title="按服务商已配置的账单能力同步，点击同步后会访问外部服务。账期使用 UTC，最长 31 天；期间汇总不能认定为单次调用金额，费用记录也不证明已退款。" type="info" :closable="false" class="mb-3" />
  <ElAlert v-if="syncError" :title="syncError" type="warning" :closable="false" class="mb-3" />
  <ElAlert v-if="syncUnknown" title="同步结果尚未确认。服务商、账期与请求号已冻结；请按原请求恢复，不要另建同步。" type="warning" :closable="false" class="mb-3" />
  <ElAlert v-if="syncMessage" :title="syncMessage" type="success" :closable="false" class="mb-3" />
  <ElForm label-position="top" :disabled="pending || credentialBlocked || syncAttempt !== null || recoveryMeta !== null">
    <ElFormItem label="服务商 ID"><ElInput v-model="draft.provider" placeholder="已配置官方账单能力的服务商 ID" /></ElFormItem>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
      <ElFormItem label="账期起 UTC"><ElDatePicker v-model="draft.period_start" type="datetime" value-format="YYYY-MM-DD HH:mm:ss" /></ElFormItem>
      <ElFormItem label="账期止 UTC（不含此时刻）"><ElDatePicker v-model="draft.period_end" type="datetime" value-format="YYYY-MM-DD HH:mm:ss" /></ElFormItem>
    </div>
  </ElForm>
  <div class="flex flex-wrap gap-3">
    <ElButton v-if="hasAuth('sand_ai:billing:sync') && !syncAttempt" type="primary" :disabled="pending || credentialBlocked || recoveryMeta !== null || !!rejectionCode" :loading="syncBusy" @click="startSync">同步官方账单</ElButton>
    <ElButton v-if="syncAttempt" type="primary" :disabled="syncBusy || syncFlight || credentialBusy || statusBusy" :loading="syncBusy" @click="queryOriginal">查询原同步结果</ElButton>
  </div>
  <p v-if="syncAttempt" class="mt-3 text-sm">原请求号：{{ syncAttempt.idempotency_key }}</p>
  <p v-if="!hasAuth('sand_ai:billing:sync')" class="mt-3 text-sm">同步权限需由管理员单独授予。</p>
  <template v-if="hasAuth('sand_ai:billing:credential')">
    <ElDivider>账单连接与凭据</ElDivider>
    <p v-if="billingDriver === 'openai_organization_costs'" class="mb-3 text-sm">使用服务商的专用账单管理员密钥与组织标识。已保存的密钥不会读回；同一服务商的账单组织不可更换。</p>
    <p v-else-if="billingDriver === 'openrouter_generation'" class="mb-3 text-sm">使用此服务商原模型连接读取已记录调用的费用，无需另存账单密钥。连接改变、调用来源或账户无法核实时会拒绝同步；仅支持非 BYOK 的已完成纯文本调用。</p>
    <p v-else class="mb-3 text-sm">先查询当前状态，确认此服务商使用的账单能力与凭据方式。</p>
    <ElButton :disabled="syncBusy || syncFlight || credentialFlight || credentialConfirming || credentialBusy || statusBusy || syncAttempt !== null" :loading="statusBusy" @click="checkStatus">查询连接方式与配置状态</ElButton>
    <p v-if="credentialStatusText" class="my-3 text-sm">{{ credentialStatusText }}</p>
    <ElAlert v-if="credentialError" :title="credentialError" type="warning" :closable="false" class="my-3" />
    <ElAlert v-if="credentialUnknown" title="原保存结果尚未确认，秘密输入已清除。只能查询原请求收据；当前配置状态不能证明本次保存成功。" type="warning" :closable="false" class="my-3" />
    <div v-if="credentialMeta" class="my-3">
      <p>凭据原请求：{{ credentialMeta.request_key }} · 服务商 {{ credentialMeta.provider_id }}</p>
      <ElButton :disabled="credentialFlight || credentialBusy || credentialConfirming" :loading="credentialBusy" @click="queryCredentialOriginal">查询原保存收据</ElButton>
    </div>
    <p v-if="credentialReceiptText" class="my-3 text-sm">{{ credentialReceiptText }}</p>
    <ElForm v-if="billingDriver === 'openai_organization_costs'" label-position="top" :disabled="pending || credentialBlocked || syncAttempt !== null || recoveryMeta !== null" autocomplete="off">
      <ElFormItem label="账单组织标识"><ElInput v-model="credentialDraft.organization" placeholder="org-…" maxlength="124" autocomplete="off" /></ElFormItem>
      <ElFormItem label="账单管理员密钥"><ElInput v-model="credentialDraft.key" type="password" maxlength="512" autocomplete="new-password" /></ElFormItem>
    </ElForm>
    <ElButton v-if="billingDriver === 'openai_organization_costs'" :disabled="pending || credentialBlocked || syncAttempt !== null || recoveryMeta !== null" :loading="credentialBusy" @click="saveCredential">保存账单凭据</ElButton>
  </template>
  <div class="mt-4"><ElButton :disabled="pending" @click="cancel">关闭</ElButton></div>
</template>
<script setup lang="ts">
  import { computed, onMounted, onBeforeUnmount, reactive, ref, watch } from 'vue'
  import { billingInputSha, recoverBillingRequest, useBillingJournal } from '../../api/billing-recovery'
  import type { BillingRequestMeta } from '../../api/billing-recovery'
  import { ElMessageBox } from 'element-plus'
  import { useAuth } from '@/hooks/core/useAuth'
  import { HttpError } from '@/utils/http/error'
  import { billingError, positiveId } from '../../api/billing'
  import { billingSyncApi } from '../../api/billing-sync'
  import type { BillingCredentialInput, BillingCredentialReceipt, BillingSyncInput, BillingDriver } from '../../api/billing-sync'
  import type { BillingDetail } from '../../api/billing-types'
  const props = defineProps<{ providerId?: number; recoveryKey?: string }>()
  const emit = defineEmits<{ saved: [batch: BillingDetail, idempotentReplay: boolean]; noData: []; cancel: []; pending: [value: boolean] }>()
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
    recoveryMeta.value = journal.entries.value.find(row => row.operation === 'sync' && (!props.recoveryKey || row.request_key === props.recoveryKey)) ?? null
    if (journal.storageError.value) syncError.value = journal.storageError.value
  }
  async function queryOriginal() {
    const entry = recoveryMeta.value
    const actor = journal.owner.value
    const actorEpoch = ownerEpoch
    if (!entry || !actor || syncBusy.value || syncFlight.value) return
    const generation = ++recoveryGeneration
    syncBusy.value = true
    try {
      const response = await recoverBillingRequest(entry, actor)
      if (!currentOwner(actor, actorEpoch) || generation !== recoveryGeneration) return
      if (response.state === 'rejected') {
        rejectionCode.value = response.rejection.error_code
        syncConflict.value = true
        syncUnknown.value = false
        syncError.value = '服务器已正式拒绝此原请求；确认拒绝后才能修正输入并提交下一次操作。'
        return
      }
      rejectionCode.value = ''
      if (response.state === 'unknown') {
        syncUnknown.value = true
        syncError.value = '原请求结果仍未知。这不表示尚未提交。请保留请求号并继续查询，不要重新提交或更换请求号。'
        return
      }
      journal.remove(entry.request_key, actor)
      recoveryMeta.value = null
      syncAttempt.value = null
      syncUnknown.value = false
      syncConflict.value = false
      syncError.value = ''
      if (response.billing_import) emit('saved', response.billing_import, true)
      else if (response.sync_status === 'no_data') emit('noData')
    } catch (cause) { if (currentOwner(actor, actorEpoch) && generation === recoveryGeneration) syncError.value = billingError(cause) }
    finally { if (currentOwner(actor, actorEpoch) && generation === recoveryGeneration) syncBusy.value = false }
  }
  async function remember(input: { idempotency_key: string }, providerId: number, lineId?: number, environment?: number) {
    if (rejectionCode.value) throw new Error('请先确认原请求正式拒绝，再修正输入。')
    const actor = journal.owner.value
    const actorEpoch = ownerEpoch
    const sha = await billingInputSha(input)
    if (!currentOwner(actor, actorEpoch)) throw new Error('管理员已改变，原请求未发送')
    if (recoveryMeta.value && recoveryMeta.value.input_sha256 !== sha) throw new Error('输入与原请求摘要不一致，未发送；请查询原请求结果。')
    const row = { operation: 'sync' as const, request_key: input.idempotency_key, input_sha256: sha, provider_id: providerId,
      ...(lineId ? { billing_line_id: lineId } : {}), ...(environment ? { environment_id: environment } : {}) }
    return { actor, row }
  }
  watch([journal.owner, journal.epoch], () => {
    ++ownerEpoch
    rejectionCode.value = ''
    syncError.value = ''
    credentialError.value = ''
    syncMessage.value = ''
    credentialStatusText.value = ''
    billingDriver.value = null
    ++recoveryGeneration
    syncAttempt.value = null
    recoveryMeta.value = null
    syncBusy.value = false
    syncUnknown.value = false
    confirmingReset()
    restoreMeta()
    restoreCredentialMeta()
  }, { flush: 'sync' })
  onMounted(() => { restoreMeta(); restoreCredentialMeta(); void queryOriginal(); void queryCredentialOriginal() })
  onBeforeUnmount(() => { componentAlive = false; ++ownerEpoch; ++recoveryGeneration })
  function confirmingReset() { syncConfirming.value = false; credentialBusy.value = false; statusBusy.value = false; credentialUnknown.value = false; credentialInputs.clear(); credentialMeta.value = null; credentialStorageError.value = ''; credentialConfirming.value = false; credentialReceiptText.value = ''; credentialDraft.key = ''; credentialDraft.organization = ''; draft.provider = ''; draft.period_start = ''; draft.period_end = ''; syncConflict.value = false; ++statusGeneration }

  const draft = reactive({ provider: props.providerId ? String(props.providerId) : '', period_start: '', period_end: '' })
  const credentialDraft = reactive({ key: '', organization: '' })
  const syncAttempt = ref<BillingSyncInput | null>(null)
  type CredentialMeta = { version: 1; admin_id: number; provider_id: number; request_key: string }
  const credentialMeta = ref<CredentialMeta | null>(null)
  const credentialInputs = new Map<string, BillingCredentialInput>()
  const credentialStorageError = ref('')
  const credentialReceiptText = ref('')
  const credentialConfirming = ref(false)
  const credentialFlight = ref(false)
  const credentialBlocked = computed(() => credentialMeta.value !== null || !!credentialStorageError.value)
  const syncBusy = ref(false)
  const syncFlight = ref(false)
  const syncConfirming = ref(false)
  const credentialBusy = ref(false)
  const statusBusy = ref(false)
  const syncUnknown = ref(false)
  const credentialUnknown = ref(false)
  const syncConflict = ref(false)
  const syncError = ref('')
  const credentialError = ref('')
  const syncMessage = ref('')
  const credentialStatusText = ref('')
  const billingDriver = ref<BillingDriver | null>(null)
  const pending = computed(() => syncFlight.value || syncBusy.value || syncConfirming.value || credentialFlight.value || credentialConfirming.value || credentialBusy.value || statusBusy.value)
  let alive = true
  let statusGeneration = 0
  watch(pending, value => emit('pending', value), { immediate: true })
  watch(() => draft.provider, () => {
    statusGeneration++
    statusBusy.value = false
    credentialStatusText.value = ''
    billingDriver.value = null
    credentialError.value = ''
    syncMessage.value = ''
    credentialDraft.key = ''
    credentialDraft.organization = ''
  })
  function utc(value: string): number | null {
    if (!/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(value)) return null
    const parsed = Date.parse(`${value.replace(' ', 'T')}Z`)
    if (!Number.isFinite(parsed) || new Date(parsed).toISOString().replace('T', ' ').slice(0, 19) !== value) return null
    return parsed
  }
  async function startSync() {
    if (!hasAuth('sand_ai:billing:sync') || pending.value || credentialBlocked.value || syncAttempt.value || recoveryMeta.value) return
    syncError.value = ''
    syncMessage.value = ''
    const providerId = positiveId(draft.provider)
    const start = utc(draft.period_start)
    const end = utc(draft.period_end)
    if (!providerId || start === null || end === null || end <= start || end - start > 31 * 86400000) {
      syncError.value = '请填写合法服务商 ID 和正向 UTC 账期，跨度最长 31 天。'
      return
    }
    const input: BillingSyncInput = {
      provider_id: providerId,
      period_start: draft.period_start,
      period_end: draft.period_end,
      idempotency_key: crypto.randomUUID()
    }
    syncConfirming.value = true
    const submittingOwner = journal.owner.value
    const submittingOwnerEpoch = ownerEpoch
    try {
      await ElMessageBox.confirm(
        `将访问服务商官方账单接口：服务商 ${input.provider_id}，账期 ${input.period_start} 至 ${input.period_end} UTC。是否同步？`,
        '确认同步',
        { type: 'warning' }
      )
    } catch { return }
    finally { if (currentOwner(submittingOwner, submittingOwnerEpoch)) syncConfirming.value = false }
    if (!currentOwner(submittingOwner, submittingOwnerEpoch) || syncBusy.value || syncFlight.value || syncAttempt.value || recoveryMeta.value) return
    syncAttempt.value = input
    await sendSync()
  }
  async function sendSync() {
    const input = syncAttempt.value
    if (!hasAuth('sand_ai:billing:sync') || !input || syncBusy.value || syncFlight.value || credentialBusy.value || statusBusy.value || syncConflict.value) return
    syncBusy.value = true
    syncError.value = ''
    let persisted = false
    const actor = journal.owner.value
    const actorEpoch = ownerEpoch
    if (actor === null) { syncBusy.value = false; return }
    syncFlight.value = true
    try {
      const prepared = await remember(input, input.provider_id)
      if (!currentOwner(actor, actorEpoch) || prepared.actor !== actor) return
      journal.save(prepared.row, actor)
      persisted = true
      recoveryMeta.value = prepared.row
      const response = await billingSyncApi.sync(input)
      if (!currentOwner(actor, actorEpoch)) return
      const acknowledged = await recoverBillingRequest(prepared.row, actor)
      if (!currentOwner(actor, actorEpoch)) return
      if (acknowledged.state === 'rejected') {
        rejectionCode.value = acknowledged.rejection.error_code
        syncConflict.value = true
        throw new Error('服务器已正式拒绝此原请求；请查询拒绝凭据并明确确认后修正输入。')
      }
      if (acknowledged.state !== 'completed') throw new Error('服务器已响应，但原请求完成凭据仍未知，请查询原请求，不要另建操作。')
      if (acknowledged.sync_status !== response.status ||
          (response.status === 'imported' && acknowledged.billing_import?.id !== response.billing_import.id))
        throw new Error('原请求查询结果与同步回执不一致')
      journal.remove(prepared.row.request_key, actor)
      recoveryMeta.value = null
      if (!alive) return
      syncUnknown.value = false
      syncAttempt.value = null
      syncMessage.value = response.status === 'no_data' ? '同步完成：此账期未取得可同步的账单记录。' : `同步完成：批次 ${response.billing_import.id}${response.idempotent_replay ? '（原请求已处理）' : ''}。`
      if (response.status === 'imported') emit('saved', response.billing_import, response.idempotent_replay)
      else emit('noData')
    } catch (error: unknown) {
      if (!currentOwner(actor, actorEpoch)) return
      if (!alive) return
      syncError.value = billingError(error)
      syncUnknown.value = !rejectionCode.value && (persisted || recoveryMeta.value !== null)
      if (!persisted) syncAttempt.value = null
      syncConflict.value = !!rejectionCode.value || (error instanceof HttpError && error.code === 409)
      
      if (syncConflict.value) syncError.value = '服务器报告账单事实冲突，请核查已有批次；不会换请求号覆盖。'
    } finally {
      syncFlight.value = false
      if (alive && currentOwner(actor, actorEpoch)) syncBusy.value = false
    }
  }
  async function checkStatus() {
    if (!hasAuth('sand_ai:billing:credential') || syncBusy.value || syncFlight.value || credentialFlight.value || credentialConfirming.value || credentialBusy.value || statusBusy.value || syncAttempt.value) return
    const providerId = credentialMeta.value?.provider_id ?? positiveId(draft.provider)
    if (!providerId) { credentialError.value = '请填写合法服务商 ID。'; return }
    const generation = ++statusGeneration
    statusBusy.value = true
    billingDriver.value = null
    credentialStatusText.value = ''
    credentialError.value = ''
    const operationOwner = journal.owner.value
    const operationOwnerEpoch = ownerEpoch
    try {
      const response = await billingSyncApi.status(providerId)
      if (!currentOwner(operationOwner, operationOwnerEpoch) || !alive || generation !== statusGeneration) return
      billingDriver.value = response.driver
      const configuration = response.driver === 'openrouter_generation'
        ? (response.configured ? '原模型连接已配置，无需另存账单密钥' : '原模型连接尚未配置')
        : (response.configured ? '专用账单凭据已配置' : '尚未配置专用账单凭据')
      credentialStatusText.value = `服务商 ${providerId}：${configuration}。此查询仅确认本地配置，未访问服务商。${credentialUnknown.value ? '本次保存结果仍未确认。' : ''}`
    } catch (error: unknown) {
      if (!currentOwner(operationOwner, operationOwnerEpoch) || !alive || generation !== statusGeneration) return
      credentialError.value = error instanceof HttpError && error.code === 403 ? '没有账单凭据权限。' : '无法查询凭据状态，请稍后重试。'
    } finally { if (currentOwner(operationOwner, operationOwnerEpoch) && alive && generation === statusGeneration) statusBusy.value = false }
  }
  const credentialStorageKey = (actor: number) => `sand-ai:billing:credential-requests:${actor}`
  function restoreCredentialMeta() {
    credentialMeta.value = null
    credentialStorageError.value = ''
    const actor = journal.owner.value
    if (actor === null) return
    try {
      const stored = sessionStorage.getItem(credentialStorageKey(actor))
      if (stored === null) return
      const value: unknown = JSON.parse(stored)
      if (typeof value !== 'object' || value === null || Array.isArray(value)) throw new Error('invalid')
      const row = value as Record<string, unknown>
      if (row.version !== 1 || row.admin_id !== actor || typeof row.provider_id !== 'number' ||
          !Number.isSafeInteger(row.provider_id) || row.provider_id <= 0 || typeof row.request_key !== 'string' ||
          !/^[A-Za-z0-9._:-]{1,128}$/.test(row.request_key) ||
          Object.keys(row).some(key => !['version', 'admin_id', 'provider_id', 'request_key'].includes(key)))
        throw new Error('invalid')
      credentialMeta.value = { version: 1, admin_id: actor, provider_id: row.provider_id, request_key: row.request_key }
      credentialUnknown.value = true
    } catch {
      credentialStorageError.value = '无法读取凭据原请求记录，请保留本机记录并联系管理员；不会重新保存。'
      credentialError.value = credentialStorageError.value
    }
  }
  function freezeCredential(actor: number): CredentialMeta {
    const providerId = positiveId(draft.provider)
    const key = credentialDraft.key.trim()
    if (!providerId || !/^org-[A-Za-z0-9_-]{1,120}$/.test(credentialDraft.organization) ||
        /[\x00-\x1f\x7f]/.test(credentialDraft.organization) || new TextEncoder().encode(key).length < 8 ||
        new TextEncoder().encode(key).length > 512 || /[\x00-\x1f\x7f]/.test(credentialDraft.key))
      throw new Error('请填写合法服务商 ID、org- 开头的组织标识和 8 至 512 字符的专用密钥。')
    const operation: CredentialMeta = { version: 1, admin_id: actor, provider_id: providerId, request_key: crypto.randomUUID() }
    credentialInputs.set(operation.request_key, { provider_id: providerId, billing_admin_key: key,
      billing_organization_id: credentialDraft.organization })
    credentialDraft.key = ''
    credentialDraft.organization = ''
    return operation
  }
  function dispatchCredential(operation: CredentialMeta): Promise<BillingCredentialReceipt> {
    const input = credentialInputs.get(operation.request_key)
    credentialInputs.delete(operation.request_key)
    if (!input) throw new Error('原输入已清除，尚未发送。请重新填写并主动保存。')
    return billingSyncApi.save(input, operation.request_key)
  }
  function releaseCredential(operation: CredentialMeta) {
    if (credentialMeta.value?.request_key !== operation.request_key || !journal.current(operation.admin_id))
      throw new Error('原请求归属已改变')
    sessionStorage.removeItem(credentialStorageKey(operation.admin_id))
    credentialMeta.value = null
    credentialUnknown.value = false
  }
  async function queryCredentialOriginal() {
    const operation = credentialMeta.value
    const operationOwnerEpoch = ownerEpoch
    if (!operation || !hasAuth('sand_ai:billing:credential') || credentialBusy.value || credentialFlight.value || credentialConfirming.value ||
        !currentOwner(operation.admin_id, operationOwnerEpoch)) return
    credentialBusy.value = true
    credentialError.value = ''
    try {
      const proof = await billingSyncApi.credentialByRequestKey(operation.provider_id, operation.request_key, operation.admin_id)
      if (!currentOwner(operation.admin_id, operationOwnerEpoch)) return
      if (proof.state === 'unknown') {
        credentialUnknown.value = true
        credentialError.value = '原保存结果仍未知。历史记录缺少原请求证明时也保持未知；不会重新保存或更换请求号。'
        return
      }
      releaseCredential(operation)
      credentialReceiptText.value = `原保存收据：服务商 ${proof.credential.provider_id}，保存时间 ${proof.credential.updated_at}。此收据只证明当时保存，不证明当前凭据仍可用。`
    } catch {
      if (!currentOwner(operation.admin_id, operationOwnerEpoch)) return
      credentialUnknown.value = true
      credentialError.value = '未取得一致的原保存收据，可能无权限或查询失败。保留原请求，仅可继续查询。'
    } finally {
      if (currentOwner(operation.admin_id, operationOwnerEpoch)) credentialBusy.value = false
    }
  }
  async function saveCredential() {
    if (!hasAuth('sand_ai:billing:credential') || billingDriver.value !== 'openai_organization_costs' || pending.value || credentialBlocked.value || syncAttempt.value || recoveryMeta.value) return
    const operationOwner = journal.owner.value
    const operationOwnerEpoch = ownerEpoch
    if (operationOwner === null) return
    credentialError.value = ''
    credentialReceiptText.value = ''
    let operation: CredentialMeta
    try { operation = freezeCredential(operationOwner) }
    catch {
      credentialError.value = '请填写合法服务商 ID、org- 开头的组织标识和 8 至 512 字符的专用密钥。'
      return
    }
    credentialConfirming.value = true
    try {
      await ElMessageBox.confirm(`将为服务商 ${operation.provider_id} 保存本次填写的官方账单凭据。组织和密钥已冻结，发送后不保留秘密输入；结果未知时仅查询原请求。是否保存？`, '确认保存账单凭据', { type: 'warning' })
    } catch {
      credentialInputs.delete(operation.request_key)
      return
    } finally {
      if (currentOwner(operationOwner, operationOwnerEpoch)) credentialConfirming.value = false
    }
    if (!currentOwner(operationOwner, operationOwnerEpoch) || credentialFlight.value || credentialBlocked.value ||
        !hasAuth('sand_ai:billing:credential') || !credentialInputs.has(operation.request_key)) {
      credentialInputs.delete(operation.request_key)
      return
    }
    credentialFlight.value = true
    credentialBusy.value = true
    let persisted = false
    try {
      sessionStorage.setItem(credentialStorageKey(operation.admin_id), JSON.stringify(operation))
      credentialMeta.value = operation
      persisted = true
      const response = await dispatchCredential(operation)
      if (!currentOwner(operationOwner, operationOwnerEpoch)) return
      const proof = await billingSyncApi.credentialByRequestKey(operation.provider_id, operation.request_key, operation.admin_id)
      if (!currentOwner(operationOwner, operationOwnerEpoch)) return
      if (proof.state !== 'completed' || proof.credential.updated_at !== response.updated_at)
        throw new Error('原保存收据尚未确认')
      releaseCredential(operation)
      credentialReceiptText.value = `原保存收据：服务商 ${proof.credential.provider_id}，保存时间 ${proof.credential.updated_at}。请另行查询当前凭据状态；该收据不证明当前凭据仍可用。`
    } catch {
      if (!currentOwner(operationOwner, operationOwnerEpoch)) return
      credentialUnknown.value = persisted
      credentialError.value = persisted
        ? '原保存结果未确认，秘密输入已清除。保留原请求，只能查询收据，不会重新保存。'
        : '未能保存原请求摘要，本次未发送。请修复本机存储后重新填写并主动保存。'
    } finally {
      credentialInputs.delete(operation.request_key)
      credentialFlight.value = false
      if (currentOwner(operationOwner, operationOwnerEpoch)) credentialBusy.value = false
    }
  }
  function cancel() {
    if (pending.value) return
    credentialDraft.key = ''
    credentialDraft.organization = ''
    credentialInputs.clear()
    emit('cancel')
  }
  onBeforeUnmount(() => {
    alive = false
    statusGeneration++
    credentialDraft.key = ''
    credentialDraft.organization = ''
    credentialInputs.clear()
  })

  async function acknowledgeRejected() {
    const entry = recoveryMeta.value
    const actor = journal.owner.value
    const actorEpoch = ownerEpoch
    if (!entry || !actor || !rejectionCode.value || syncBusy.value || syncFlight.value) return
    syncBusy.value = true
    try {
      await ElMessageBox.confirm(`原请求 ${entry.request_key} 已正式拒绝。确认后清除本机原请求记录；修正输入后需再次主动提交，才会创建新请求号。是否确认？`, '确认原请求拒绝', { type: 'warning' })
      if (!currentOwner(actor, actorEpoch)) return
      const proof = await recoverBillingRequest(entry, actor)
      if (!currentOwner(actor, actorEpoch)) return
      if (proof.state !== 'rejected' || proof.rejection.error_code !== rejectionCode.value)
        throw new Error('未重新取得一致的正式拒绝凭据，原请求记录继续保留。')
      journal.remove(entry.request_key, actor)
      recoveryMeta.value = null
      syncAttempt.value = null
      rejectionCode.value = ''
      syncConflict.value = false
      syncUnknown.value = false
      syncError.value = '已确认原请求被拒绝。请修正输入，再主动提交下一次操作。'
    } catch (cause) {
      if (cause !== 'cancel' && cause !== 'close' && currentOwner(actor, actorEpoch)) syncError.value = billingError(cause)
    } finally { if (currentOwner(actor, actorEpoch)) syncBusy.value = false }
  }
</script>
