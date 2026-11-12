<template>
  <ElCard shadow="never" class="ocr-setup">
    <template #header><h4>图片里的文字</h4></template>
    <p class="muted">图片在这台服务器上识别，不用填写网址和密钥。识别程序需要事先装好。保存后先检查能不能用，再用到能力配置里。</p>
    <PublishedOcrDeploymentPrice @selection="selectPublishedPrice" />
    <template v-if="priceTarget">
      <p>正在看价格：{{ priceTarget.model.name }}（{{ priceContext === 'existing' ? '已启用的识别程序' : '这次启用的识别程序' }}，编号 {{ priceTarget.deployment_id }}）。</p>
      <p class="muted">价格属于服务商「{{ priceTarget.provider.name }}」的这个模型。</p>
      <OcrPricingPanel :key="`${priceTarget.provider.id}:${priceTarget.model.id}`" :provider-id="priceTarget.provider.id" :model-id="priceTarget.model.id" />
    </template>
    <ElAlert v-if="message" :type="messageType" :title="message" :closable="false" />
    <ElAlert v-if="journal" :type="journal.phase === 'published' ? 'success' : 'warning'" :closable="false" :title="journalTitle" />
    <ElButton v-if="journal" @click="copyProblem">复制问题信息</ElButton>
    <ElAlert v-if="pendingNotSubmitted" type="warning" :closable="false" title="上次填写还没有发出去。可以点下面的按钮继续，不会重复建；刷新或换人登录后只能在「高级」里查看。" />
    <ElButton v-if="pendingNotSubmitted" v-permission="unsubmittedPermission" :disabled="Boolean(busy) || postFlight" @click="retryUnsubmitted">继续上次保存</ElButton>
    <ElButton v-if="recoveryRequired || (journal && !draft)" type="primary" v-permission="'sand_ai:setup:deployment:save'" :loading="busy === 'save' || busy === 'read'" :disabled="Boolean(busy) || postFlight || pendingNotSubmitted || !canSave" @click="save">重新保存</ElButton>
    <ElButton v-if="recoveryRequired || pendingNotSubmitted || journal" :disabled="Boolean(busy) || postFlight" @click="abandonLocalJournal">放弃这次改动</ElButton>
    <details v-if="journal">
      <summary>高级</summary>
      <ElButton v-permission="'sand_ai:setup:deployment:read'" :loading="busy === 'read'" :disabled="Boolean(busy) || postFlight" @click="readState">查看上次结果</ElButton>
    </details>
    <ElForm label-width="110px" @submit.prevent>
      <template v-if="!draft">
        <ElFormItem label="名称"><ElInput v-model.trim="name" maxlength="160" :disabled="locked" placeholder="例如：材料图片本地识别" /></ElFormItem>
        <ElFormItem label="等待时间（秒）"><ElInputNumber :model-value="Math.round(timeoutMs / 1000)" :min="1" :max="120" :precision="0" :step="1" :disabled="locked" @update:model-value="(value: number | undefined) => { timeoutMs = (value ?? 1) * 1000 }" /></ElFormItem>
        <ElFormItem v-if="!journal"><ElButton v-permission="'sand_ai:setup:deployment:save'" type="primary" :loading="busy === 'save'" :disabled="locked || !canSave" @click="save">保存</ElButton></ElFormItem>
      </template>
      <template v-if="draft">
        <p>{{ draft.model.name }} · {{ published ? '已启用' : '还没有启用' }}</p>
        <ElFormItem label="处理位置"><span>这台服务器</span></ElFormItem>
        <p class="muted">{{ usageNotice }}</p>
        <p v-if="verification?.failure" class="muted">{{ verification.failure.message }}</p>
        <ElSpace wrap>
          <ElButton v-permission="'sand_ai:setup:deployment:verify'" :loading="busy === 'verify'" :disabled="!canVerify" @click="verify">检查能不能用</ElButton>
          <ElButton v-permission="'sand_ai:setup:deployment:read'" :loading="busy === 'read'" :disabled="Boolean(busy) || postFlight || pendingNotSubmitted" @click="readState">查看当前状态</ElButton>
          <ElButton v-permission="'sand_ai:onboarding:publish'" type="primary" :loading="busy === 'publish'" :disabled="!canPublish" @click="publish">启用</ElButton>
        </ElSpace>
      </template>
    </ElForm>
    <p v-if="verification?.state === 'verification_ambiguous'" class="muted">上次检查的结果不确定。请点「查看当前状态」确认后再继续，不会重复执行。</p>
    <p v-if="journal?.phase === 'failed'" class="muted">识别程序还没配好。请让技术同事改好后再保存一次。</p>
    <ElButton v-if="canStartNew" v-permission="'sand_ai:setup:deployment:save'" :disabled="Boolean(busy) || postFlight" @click="saveSuccessor">{{ published ? '沿用原设置再保存一个' : '在原基础上再保存一个' }}</ElButton>
    <ElButton v-if="published && draft" @click="priceContext = 'current'">查看价格</ElButton>
    <p v-if="published" class="muted">价格启用后，到「能力配置」把「图片里的文字」指到这一项就能用了。</p>
    <ElButton v-if="published" @click="router.push('/sand-ai/capability')">去能力配置</ElButton>
  </ElCard>
</template>
<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
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
  retryUnsubmitted, abandonLocalJournal, verify, publish, readState, saveSuccessor } = useOcrDeploymentRecovery(() => {
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
const journalTitle = computed(() => {
  if (!journal.value) return ''
  if (journal.value.phase === 'failed') return '这次保存没有成功'
  if (journal.value.phase === 'published') return '已经启用，可以继续用了'
  return '这次保存的结果还没确认'
})
async function copyProblem(): Promise<void> {
  const current = journal.value
  if (!current) return
  const lines = [`保存号 ${current.save_key}`, current.verification_key ? `验证号 ${current.verification_key}` : '', `阶段 ${phaseLabel.value}`].filter((line) => line !== '')
  try {
    await navigator.clipboard.writeText(lines.join('\n'))
    ElMessage.success('已复制问题信息')
  } catch {
    ElMessage.error('复制失败，请稍后再试')
  }
}
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
