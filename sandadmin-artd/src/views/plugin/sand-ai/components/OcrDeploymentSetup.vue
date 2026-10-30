<template>
  <ElCard shadow="never" class="ocr-setup">
    <template #header><h4>本地图片文字识别部署</h4></template>
    <p class="muted">图片在当前宿主处理，无需服务地址、API Key 或机器路径。宿主运维须预先配置 OCR 引擎；保存草稿后明确验证，通过后发布、配置服务费，再绑定目标环境的能力路由。</p>
    <PublishedOcrDeploymentPrice @selection="selectPublishedPrice" />
    <template v-if="priceTarget">
      <p>当前价格上下文：{{ priceContext === 'existing' ? '所选已发布部署' : '本次发布部署' }} {{ priceTarget.deployment_id }} · 修订 {{ priceTarget.revision }} · 模型 {{ priceTarget.model.name }}。</p>
      <p class="muted">价格归属服务商 {{ priceTarget.provider.name }} 与模型 {{ priceTarget.model.name }}，不表示目标环境已绑定该部署；目录读取也不替代原保存或发布收据。</p>
      <OcrPricingPanel :key="`${priceTarget.provider.id}:${priceTarget.model.id}`" :provider-id="priceTarget.provider.id" :model-id="priceTarget.model.id" />
    </template>
    <ElAlert v-if="message" :type="messageType" :title="message" :closable="false" />
    <ElAlert v-if="journal" type="info" :closable="false" :title="`原保存请求 ${journal.save_key} · ${journal.deployment_id ? `部署 ${journal.deployment_id} / 修订 ${journal.revision ?? '待确认'}` : '保存结果待确认'} · ${phaseLabel}`" />
    <p v-if="journal?.verification_key" class="muted">原验证请求号：{{ journal.verification_key }}</p>
    <ElAlert v-if="pendingNotSubmitted" type="warning" :closable="false" title="本页确认原操作尚未发送。原配置仅留在内存中，恢复存储后可明确继续原操作；刷新或切换身份后只能按原号只读找回。" />
    <ElButton v-if="pendingNotSubmitted" v-permission="unsubmittedPermission" :disabled="Boolean(busy) || postFlight" @click="retryUnsubmitted">保留原号并继续原操作</ElButton>
    <ElButton v-if="recoveryRequired || (journal && !draft)" v-permission="'sand_ai:setup:deployment:read'" :loading="busy === 'read'" :disabled="Boolean(busy) || postFlight || pendingNotSubmitted" @click="readState">只读找回原配置与状态</ElButton>
    <ElForm label-width="110px" @submit.prevent>
      <template v-if="!draft">
        <ElFormItem label="部署名称"><ElInput v-model.trim="name" maxlength="160" :disabled="locked" placeholder="例如：材料图片本地识别" /></ElFormItem>
        <ElFormItem label="超时(ms)"><ElInputNumber v-model="timeoutMs" :min="1000" :max="120000" :precision="0" :step="1000" :disabled="locked" /></ElFormItem>
        <ElFormItem v-if="!journal"><ElButton v-permission="'sand_ai:setup:deployment:save'" type="primary" :loading="busy === 'save'" :disabled="locked || !canSave" @click="save">保存 OCR 部署草稿</ElButton></ElFormItem>
      </template>
      <template v-if="draft">
        <p>部署 {{ draft.deployment_id }} · 修订 {{ draft.revision }} · {{ draft.model.name }} · {{ published ? '已发布' : verification?.state ?? draft.state }}</p>
        <ElFormItem label="处理位置"><span>本地运行</span></ElFormItem>
        <p class="muted">{{ usageNotice }}</p>
        <p v-if="verification?.failure" class="muted">{{ verification.failure.message }}</p>
        <ElSpace wrap>
          <ElButton v-permission="'sand_ai:setup:deployment:verify'" :loading="busy === 'verify'" :disabled="!canVerify" @click="verify">验证本地 OCR</ElButton>
          <ElButton v-permission="'sand_ai:setup:deployment:read'" :loading="busy === 'read'" :disabled="Boolean(busy) || postFlight || pendingNotSubmitted" @click="readState">读取修订状态</ElButton>
          <ElButton v-permission="'sand_ai:onboarding:publish'" type="primary" :loading="busy === 'publish'" :disabled="!canPublish" @click="publish">发布此 OCR 修订</ElButton>
        </ElSpace>
      </template>
    </ElForm>
    <p v-if="verification?.state === 'verification_ambiguous'" class="muted">原验证结果不确定，保留同一部署、修订和验证号，只读找回；不会重发验证或另建部署绕过未知结果。</p>
    <p v-if="journal?.phase === 'failed'" class="muted">请宿主运维修正本地引擎配置；原失败草稿保留。确认修正后，沿同一服务商、模型和部署保存新修订，再明确验证。</p>
    <ElButton v-if="canStartNew" v-permission="'sand_ai:setup:deployment:save'" :disabled="Boolean(busy) || postFlight" @click="saveSuccessor">{{ published ? '沿原服务商和模型保存后继部署草稿' : '沿原部署保存新修订草稿' }}</ElButton>
    <ElButton v-if="published && draft" @click="priceContext = 'current'">查看本次发布部署的价格</ElButton>
    <p v-if="published" class="muted">发布价格后，到能力配置选择图片文字识别、本地 OCR 驱动和此部署，再保存并发布环境能力档。未绑定或未计价时，硬预算请求会被拒绝；SandIAM 服务授权仍需单独配置。</p>
    <ElButton v-if="published" @click="router.push('/sand-ai/capability')">配置图片识别能力路由</ElButton>
  </ElCard>
</template>
<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import type { LocalOcrDeployment } from '../api/onboarding'
import type { OcrDraft } from '../api/ocrConfiguration'
import { useOcrDeploymentRecovery } from '../composables/useOcrDeploymentRecovery'
import OcrPricingPanel from './OcrPricingPanel.vue'
import PublishedOcrDeploymentPrice from './PublishedOcrDeploymentPrice.vue'
const router = useRouter()
const name = ref('')
const timeoutMs = ref(120000)
const priceContext = ref<'current' | 'existing'>('current')
const selectedPublishedPrice = ref<OcrDraft | null>(null)
const { journal, draft, verification, busy, postFlight, locked, published, recoveryRequired, canVerify, canPublish,
  canStartNew, message, messageType, usageNotice, phaseLabel, save: saveDeployment, pendingNotSubmitted,
  retryUnsubmitted, verify, publish, readState, saveSuccessor } = useOcrDeploymentRecovery(() => {
    name.value = ''; timeoutMs.value = 120000
  })
const priceTarget = computed(() => priceContext.value === 'existing' ? selectedPublishedPrice.value : published.value ? draft.value : null)
function selectPublishedPrice(value: OcrDraft | null): void {
  selectedPublishedPrice.value = value; priceContext.value = 'existing'
}
const unsubmittedPermission = computed(() => journal.value?.phase === 'save_pending' ? 'sand_ai:setup:deployment:save'
  : journal.value?.phase === 'verification_pending' ? 'sand_ai:setup:deployment:verify' : 'sand_ai:onboarding:publish')
const canSave = computed(() => Boolean(name.value && name.value.length <= 160 && Number.isSafeInteger(timeoutMs.value)
  && timeoutMs.value >= 1000 && timeoutMs.value <= 120000))
async function save(): Promise<void> {
  if (locked.value || !canSave.value) return
  const suffix = crypto.randomUUID().replaceAll('-', '').slice(0, 12)
  const payload: LocalOcrDeployment = {
    provider: { code: `ocr_${suffix}`, name: name.value, adapter: 'local_ocr_runtime', timeout_ms: timeoutMs.value },
    model: { code: `ocr_${suffix}`, name: name.value, type: 'image_ocr', capabilities: ['image_ocr'] },
    remote_model: 'local_paddle_ocr', config: {}
  }
  await saveDeployment(payload)
}
</script>
<style scoped>
.ocr-setup { margin-top: 16px; }
.ocr-setup h4 { margin: 0; }
.ocr-setup .muted { color: var(--el-text-color-secondary); }
</style>
