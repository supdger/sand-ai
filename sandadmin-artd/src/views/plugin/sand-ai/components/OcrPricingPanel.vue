<template>
  <section class="ocr-price">
    <h4>此模型的图片文字识别服务费</h4>
    <p>计量单位：{{ unitLabel }}。填写 CNY 服务费与 UTC 生效时间；保存草稿不会发布价格。可填 0。此服务费不含 CPU 与机器费用，不代表现金账单核对；未计价时硬预算请求会拒绝。</p>
    <ElAlert v-if="notice" :title="notice" :closable="false" type="warning" />
    <ElButton v-permission="'sand_ai:pricing:index'" :loading="busy === 'read'" :disabled="!!busy" @click="read">读取价格目录 / 找回原操作</ElButton>
    <ElSelect v-model="selectedId" :disabled="!!busy || !!pending" clearable placeholder="选择已有价格版本" @change="selectPrice">
      <ElOption v-for="item in prices" :key="item.id" :value="item.id" :label="`${item.name} · 版本 ${item.revision} · ${item.state === 'published' ? '已发布' : '草稿'}`" />
    </ElSelect>
    <ElForm label-width="120px" :disabled="!!busy || !!pending || storageBlocked || !!selected" @submit.prevent>
      <ElFormItem label="计量单位"><ElSelect :model-value="meterFor()" disabled><ElOption :label="unitLabel" :value="meterFor()" /></ElSelect></ElFormItem>
      <ElFormItem label="价格名称"><ElInput v-model.trim="name" maxlength="128" /></ElFormItem>
      <ElFormItem label="币种"><span>CNY</span></ElFormItem>
      <ElFormItem label="单位价格"><ElInput v-model.trim="unitPrice" placeholder="非负金额，最多 8 位小数" /></ElFormItem>
      <ElFormItem :label="`每多少${unitLabel}`"><ElInputNumber v-model="perQuantity" :min="1" :max="2147483647" :precision="0" /></ElFormItem>
      <ElFormItem label="UTC 生效时间"><ElInput v-model.trim="effectiveFrom" placeholder="YYYY-MM-DD HH:mm:ss" /></ElFormItem>
      <ElFormItem label="UTC 失效时间"><ElInput v-model.trim="effectiveUntil" placeholder="可留空" /></ElFormItem>
    </ElForm>
    <ElSpace wrap>
      <ElButton v-permission="'sand_ai:pricing:save'" :loading="busy === 'save'" :disabled="!!busy || !!pending || storageBlocked || !!selected || !canSave" @click="save">保存新的价格草稿</ElButton>
      <ElButton v-permission="'sand_ai:pricing:publish'" :loading="busy === 'publish'" :disabled="!!busy || !!pending || storageBlocked || selected?.state !== 'draft'" @click="publish">发布所选价格版本</ElButton>
      <ElButton :disabled="!!busy || !!pending || !selected" @click="selectedId = undefined; selectPrice()">填写新的价格草稿</ElButton>
    </ElSpace>
    <p v-if="selected">版本 {{ selected.revision }} · {{ selected.currency }} {{ selected.rates[0].unit_price }} / {{ selected.rates[0].per_quantity }} {{ unitLabel }} · {{ selected.state === 'published' ? '已发布' : '尚未发布' }}</p>
    <p v-if="originalReceipt">原{{ originalKind === 'save' ? '保存' : '发布' }}回执：价格 {{ originalReceipt.id }} · 版本 {{ originalReceipt.revision }} · {{ originalReceipt.state === 'published' ? '已发布' : '草稿' }}。这是当时的回执，当前状态以目录读取为准。</p>
    <p v-if="mutationFlight">原价格请求仍在处理中，本页暂不能提交另一保存或发布操作。</p>
    <p v-if="pending">原操作结果尚未确认，只允许原请求只读找回；不会自动补存或重新发布。</p>
  </section>
</template>
<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useAdminReadIdentity } from '../composables/useAdminReadIdentity'
import { pricing, meterFor, type PriceOperationReference, type OcrPrice, type OcrPriceDraft } from '../api/ocrConfiguration'
const props = defineProps<{ providerId: number; modelId: number }>()
const identity = useAdminReadIdentity()
const prices = ref<OcrPrice[]>([])
const selectedId = ref<number>()
const selected = computed(() => prices.value.find(item => item.id === selectedId.value))
const name = ref(''); const currency = ref('CNY'); const unitPrice = ref(''); const perQuantity = ref(1)
const effectiveFrom = ref(''); const effectiveUntil = ref(''); const notice = ref('')
const currentBusy = ref<'' | 'read' | 'save' | 'publish'>('')
const mutationFlight = ref<'' | 'save' | 'publish'>('')
const busy = computed(() => mutationFlight.value || currentBusy.value)
type Pending = { admin_id: number; provider_id: number; model_id: number; capability_code: 'image_ocr'; kind: 'save' | 'publish'; fingerprint: string; before_id: number; publish_id: number | null; request_key?: string; target_id?: number | null }
const pending = ref<Pending | null>(null)
const originalReceipt = ref<OcrPrice | null>(null)
const originalKind = ref<'save' | 'publish'>('save')
const storageBlocked = ref(false)
let generation = 0
const unitLabel = '次 OCR 请求'
const canSave = computed(() => !!name.value && currency.value === 'CNY' && /^(0|[1-9][0-9]{0,11})(\.[0-9]{1,8})?$/.test(unitPrice.value) && Number.isSafeInteger(perQuantity.value) && perQuantity.value > 0 && perQuantity.value <= 2147483647 && utc(effectiveFrom.value) && (!effectiveUntil.value || (utc(effectiveUntil.value) && effectiveUntil.value > effectiveFrom.value)))
function utc(value: string): boolean {
  if (!/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(value)) return false
  const date = new Date(`${value.replace(' ', 'T')}Z`)
  return Number.isFinite(date.getTime()) && date.toISOString().slice(0, 19).replace('T', ' ') === value
}
function namespace(): string {
  const admin = identity.actor.value
  if (admin === null) throw new Error('当前管理员未确认')
  return `sand-ai:ocr:pricing:v1:${admin}:${props.providerId}:${props.modelId}`
}
function dto(): OcrPriceDraft { return { provider_id: props.providerId, model_id: props.modelId, capability_code: 'image_ocr', name: name.value, currency: currency.value, effective_from: effectiveFrom.value, effective_until: effectiveUntil.value || null, rates: [{ meter_code: meterFor(), unit_price: unitPrice.value, per_quantity: perQuantity.value }] } }
function canonical(value: unknown): unknown {
  if (Array.isArray(value)) return value.map(canonical)
  if (typeof value !== 'object' || value === null) return value
  const record = value as Record<string, unknown>
  return Object.fromEntries(Object.keys(record).sort().map(key => [key, canonical(record[key])]))
}
async function fingerprint(value: OcrPriceDraft | { id: number }): Promise<string> {
  const json = JSON.stringify(canonical(value)).replace(/\u2028/g, '\\u2028').replace(/\u2029/g, '\\u2029')
  const bytes = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(json))
  return Array.from(new Uint8Array(bytes), byte => byte.toString(16).padStart(2, '0')).join('')
}
function selectPrice(): void {
  if (!selected.value) { name.value = ''; currency.value = 'CNY'; unitPrice.value = ''; perQuantity.value = 1; effectiveFrom.value = ''; effectiveUntil.value = ''; return }
  const value = selected.value; name.value = value.name; currency.value = value.currency; unitPrice.value = value.rates[0].unit_price; perQuantity.value = value.rates[0].per_quantity; effectiveFrom.value = value.effective_from; effectiveUntil.value = value.effective_until ?? ''
}
function restoreMarker(): void {
  const raw = sessionStorage.getItem(namespace()); if (!raw) return
  const value: unknown = JSON.parse(raw)
  if (typeof value !== 'object' || value === null || Array.isArray(value)) throw new Error('原价格操作未确认')
  const item = value as Record<string, unknown>
  if (Object.keys(item).some(field => !['admin_id', 'provider_id', 'model_id', 'capability_code', 'kind', 'fingerprint', 'before_id', 'publish_id', 'request_key', 'target_id'].includes(field))) throw new Error('原价格操作记录包含未知字段')
  if (item.admin_id !== identity.actor.value || item.provider_id !== props.providerId || item.model_id !== props.modelId || item.capability_code !== 'image_ocr' || !['save', 'publish'].includes(String(item.kind)) || typeof item.fingerprint !== 'string' || !/^[a-f0-9]{64}$/.test(item.fingerprint) || typeof item.before_id !== 'number' || !Number.isSafeInteger(item.before_id) || item.before_id < 0 || !(item.publish_id === null || (typeof item.publish_id === 'number' && Number.isSafeInteger(item.publish_id) && item.publish_id > 0))) throw new Error('原价格操作归属未确认')
  if (item.request_key !== undefined && (typeof item.request_key !== 'string' || !/^[A-Za-z0-9._:-]{1,128}$/.test(item.request_key) || !(item.target_id === null || (typeof item.target_id === 'number' && Number.isSafeInteger(item.target_id) && item.target_id > 0)) || (item.kind === 'publish' && item.target_id !== item.publish_id))) throw new Error('原价格操作证明未确认')
  pending.value = item as Pending
}
async function read(): Promise<void> {
  if (busy.value) return
  const owner = identity.capture(); if (!owner) return
  const current = generation; currentBusy.value = 'read'
  try {
    restoreMarker()
    const original = pending.value
    if (original) {
      if (!original.request_key || original.target_id === undefined) { notice.value = '旧操作没有原请求收据证明。目录仅供核查，不能证明原保存或发布已完成；写操作保持锁定。'; return }
      const reference: PriceOperationReference = { kind: original.kind, provider_id: original.provider_id, model_id: original.model_id, capability_code: 'image_ocr', target_id: original.target_id, request_key: original.request_key, input_sha256: original.fingerprint }
      const result = await pricing.readOperation(reference, owner.actor)
      if (current !== generation || !identity.current(owner)) return
      if (result.state !== 'completed' || !result.receipt) { notice.value = '原价格操作结果仍未知，未找到不能证明未写入。保留原请求标识，不会重发。'; return }
      if (sessionStorage.getItem(namespace()) !== JSON.stringify(original)) throw new Error('原操作引用发生变化')
      originalReceipt.value = result.receipt; originalKind.value = original.kind
      sessionStorage.removeItem(namespace()); pending.value = null
    }
    const result = await pricing.list(props.providerId, props.modelId)
    if (current !== generation || !identity.current(owner)) return
    prices.value = result; storageBlocked.value = false
    notice.value = originalReceipt.value ? '原操作收据已确认；已另行读取当前价格目录，旧调用仍沿调用时冻结版本计价。' : '已读取此服务商及模型的价格版本；不会自动发布。'
  } catch { if (current === generation && identity.current(owner)) notice.value = '价格目录或原操作未确认，请继续只读找回。' }
  finally { if (current === generation && identity.current(owner)) currentBusy.value = '' }
}
type PriceMutation = { kind: 'save'; value: OcrPriceDraft } | { kind: 'publish'; value: OcrPrice }
async function mutate(operation: PriceMutation): Promise<void> {
  if (busy.value || pending.value || storageBlocked.value) return
  const owner = identity.capture(); if (!owner) return
  const current = generation; const provider = props.providerId; const model = props.modelId
  const input = operation.kind === 'save' ? operation.value : { id: operation.value.id }
  const target = operation.kind === 'publish' ? operation.value.id : null
  mutationFlight.value = operation.kind; currentBusy.value = operation.kind
  try {
    restoreMarker(); if (pending.value) throw new Error('原操作未确认')
    const sha = await fingerprint(input)
    if (current !== generation || !identity.current(owner)) return
    const key = crypto.randomUUID()
    const marker: Pending = { admin_id: owner.actor, provider_id: provider, model_id: model, capability_code: 'image_ocr', kind: operation.kind, fingerprint: sha, before_id: 0, publish_id: target, target_id: target, request_key: key }
    sessionStorage.setItem(namespace(), JSON.stringify(marker)); pending.value = marker
    if (sessionStorage.getItem(namespace()) !== JSON.stringify(marker)) throw new Error('原价格操作记录未安全保留')
    if (operation.kind === 'save') await pricing.save(operation.value, key)
    else await pricing.publish(operation.value, key)
    if (current === generation && identity.current(owner)) notice.value = '操作已返回，请按原请求只读找回确认当时收据。'
  } catch { if (current === generation && identity.current(owner)) { storageBlocked.value = true; notice.value = '原操作尚未确认，保留原号只读找回；不会重发。' } }
  finally {
    mutationFlight.value = ''
    if (current === generation && identity.current(owner)) currentBusy.value = ''
  }
}
async function save(): Promise<void> {
  if (!canSave.value || selected.value) return
  await mutate({ kind: 'save', value: dto() })
}
async function publish(): Promise<void> {
  const value = selected.value
  if (!value || value.state !== 'draft') return
  await mutate({ kind: 'publish', value: { ...value, rates: [{ ...value.rates[0] }] } })
}
function reset(): void { generation++; prices.value = []; selectedId.value = undefined; pending.value = null; originalReceipt.value = null; currentBusy.value = ''; notice.value = ''; storageBlocked.value = false; selectPrice(); try { restoreMarker() } catch { notice.value = '会话记录未确认，写操作锁定。'; storageBlocked.value = true } }
watch(() => identity.epoch.value, reset, { flush: 'sync' })
watch(() => [props.providerId, props.modelId], reset, { flush: 'sync' })
onMounted(reset)
onBeforeUnmount(() => { generation++; prices.value = []; pending.value = null; originalReceipt.value = null })
</script>
<style scoped>.ocr-price { margin-top: 16px; }.ocr-price p { color: var(--el-text-color-secondary); }</style>
