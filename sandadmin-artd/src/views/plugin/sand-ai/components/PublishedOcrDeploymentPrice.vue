<template>
  <section class="published-ocr" aria-label="已发布 OCR 部署与价格">
    <h4>已发布 OCR 部署与价格</h4>
    <p class="muted">已有部署可在这里读取价格。选择部署只用于查看和管理其服务费；应用与环境的能力绑定仍需在能力配置中明确保存、发布。</p>
    <ElAlert v-if="message" type="warning" :title="message" :closable="false" />
    <ElForm label-width="110px" @submit.prevent>
      <ElFormItem label="部署名称"><ElInput v-model.trim="search" maxlength="160" placeholder="可留空，或输入模型、服务商名称" /></ElFormItem>
      <ElFormItem>
        <ElButton v-permission="'sand_ai:setup:deployment:read'" :loading="busy === 'list'" :disabled="Boolean(busy)" @click="readCatalog">读取已发布 OCR 部署</ElButton>
      </ElFormItem>
      <ElFormItem label="选择已有部署">
        <ElSelect v-permission="'sand_ai:setup:deployment:read'" v-model="selectedId" clearable :disabled="busy === 'list' || !options.length" placeholder="按名称选择已发布 OCR 部署" @change="readSelected">
          <ElOption v-for="item in options" :key="item.id" :value="item.id" :label="`${item.display_name} · 部署 ${item.id} / 修订 ${item.deployment_revision}`" />
        </ElSelect>
      </ElFormItem>
      <ElFormItem v-if="selectedId">
        <ElButton v-permission="'sand_ai:setup:deployment:read'" :loading="busy === 'detail'" :disabled="Boolean(busy)" @click="readSelected">重新读取所选部署</ElButton>
      </ElFormItem>
    </ElForm>
    <p v-if="loaded && !options.length" class="muted">{{ search ? '没有匹配名称的已发布 OCR 部署，请调整名称后重新读取。' : '尚无已发布 OCR 部署，可在下方新建、验证并发布。' }}</p>
    <p v-if="hasMore" class="muted">已发布部署超过 100 项，请输入名称缩小范围后重新读取。</p>
    <p v-if="busy === 'detail'" class="muted">正在核对所选固定修订与当前发布修订。</p>
    <template v-if="detail">
      <p>部署 {{ detail.deployment_id }} · 修订 {{ detail.revision }} · 模型 {{ detail.model.name }} · 本地运行 · 已发布</p>
      <ElButton @click="router.push('/sand-ai/capability')">配置图片识别能力路由</ElButton>
    </template>
  </section>
</template>
<script setup lang="ts">
import { onBeforeUnmount, ref, shallowRef, watch } from 'vue'
import { useRouter } from 'vue-router'
import onboardingApi, { type PublishedDeployment } from '../api/onboarding'
import ocrApi, { type OcrDraft } from '../api/ocrConfiguration'
import { useAdminReadIdentity } from '../composables/useAdminReadIdentity'
const emit = defineEmits<{ selection: [value: OcrDraft | null] }>()
const router = useRouter()
const identity = useAdminReadIdentity()
const search = ref('')
const options = shallowRef<PublishedDeployment[]>([])
const selectedId = ref<number>()
const detail = shallowRef<OcrDraft | null>(null)
const busy = ref<'' | 'list' | 'detail'>('')
const message = ref('')
const loaded = ref(false)
const hasMore = ref(false)
let generation = 0
function positive(value: unknown): value is number { return typeof value === 'number' && Number.isSafeInteger(value) && value > 0 }
function validOption(value: PublishedDeployment): boolean {
  return positive(value.id) && positive(value.deployment_revision) && typeof value.display_name === 'string'
    && value.model_type === 'image_ocr' && value.publication_status === 'published'
    && Array.isArray(value.compatible_data_egress_policies) && value.compatible_data_egress_policies.every(policy => typeof policy === 'string')
}
function clearRead(): void {
  ++generation; options.value = []; selectedId.value = undefined; detail.value = null; emit('selection', null)
  busy.value = ''; message.value = ''; loaded.value = false; hasMore.value = false
}
async function readCatalog(): Promise<void> {
  if (busy.value) return
  const owner = identity.capture()
  if (!owner) { clearRead(); message.value = '当前管理员身份未确认，请登录后重新读取。'; return }
  const current = ++generation, name = search.value
  detail.value = null; emit('selection', null); selectedId.value = undefined; options.value = []; loaded.value = false; hasMore.value = false
  busy.value = 'list'; message.value = ''
  try {
    const result = await onboardingApi.publishedDeployments('image_ocr', name)
    if (current !== generation || !identity.current(owner) || name !== search.value) return
    if (!Array.isArray(result.data) || !result.data.every(validOption) || new Set(result.data.map(item => item.id)).size !== result.data.length
      || !Number.isSafeInteger(result.total) || result.total < 0 || !positive(result.per_page)) throw new Error('Published OCR catalog unconfirmed')
    options.value = result.data; loaded.value = true; hasMore.value = result.total > result.per_page
  } catch {
    if (current === generation && identity.current(owner)) { detail.value = null; options.value = []; message.value = '已发布 OCR 目录未读回，请核对读取权限或连接后重读；不会使用旧目录或创建替代部署。' }
  } finally { if (current === generation && identity.current(owner)) busy.value = '' }
}
async function readSelected(): Promise<void> {
  const current = ++generation, owner = identity.capture(), id = selectedId.value
  detail.value = null; emit('selection', null); busy.value = ''; message.value = ''
  if (!owner || !positive(id)) return
  const target = options.value.find(item => item.id === id)
  if (!target || !validOption(target)) { message.value = '原选择未在当前已发布目录中，请重新读取目录。'; return }
  const revision = target.deployment_revision
  busy.value = 'detail'
  try {
    const [fixed, latest] = await Promise.all([ocrApi.readDraft(id, revision), ocrApi.readDraft(id)])
    if (current !== generation || !identity.current(owner) || selectedId.value !== id) return
    if (fixed.deployment_id !== id || latest.deployment_id !== id || fixed.revision !== revision || latest.revision !== revision
      || fixed.state !== 'published' || latest.state !== 'published' || fixed.provider.id !== latest.provider.id || fixed.model.id !== latest.model.id
      || fixed.provider.code !== latest.provider.code || fixed.model.code !== latest.model.code) throw new Error('Selected OCR published revision changed')
    detail.value = fixed; emit('selection', fixed)
  } catch {
    if (current === generation && identity.current(owner)) { detail.value = null; message.value = '所选部署或当前发布修订未确认，价格区域已隐藏。请重新读取；不会把旧选择当作原操作恢复或切换至其他修订。' }
  } finally { if (current === generation && identity.current(owner)) busy.value = '' }
}
watch(search, clearRead, { flush: 'sync' })
watch(() => identity.epoch.value, () => { search.value = ''; clearRead() }, { flush: 'sync' })
onBeforeUnmount(clearRead)
</script>
<style scoped>
.published-ocr { margin-bottom: 16px; }
.published-ocr h4 { margin: 0; }
.published-ocr .muted { color: var(--el-text-color-secondary); }
</style>
