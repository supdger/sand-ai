<template>
  <ElCard v-if="authenticated" shadow="never" class="speech-setup">
    <template #header><h4>语音合成模型部署</h4></template>
    <p class="muted">配置服务商后先保存草稿，再按选定格式用短文本实际合成一次进行验证。验证可能收费，费用按平台验证记录核对。</p>
    <ElAlert v-if="message" :type="messageType" :title="message" :closable="false" class="notice" />
    <ElAlert v-if="journal" type="info" :closable="false" :title="`原保存请求 ${journal.save_key} · ${journal.deployment_id ? `部署 ${journal.deployment_id} / 修订 ${journal.revision}` : '保存结果待确认'} · ${phaseLabel}`" />
    <p v-if="journal?.verification_key" class="muted">原验证请求号：{{ journal.verification_key }}</p>
    <ElAlert v-if="pendingNotSubmitted" type="warning" :closable="false" title="本页确认原操作尚未发送。原配置仅留在内存中，恢复存储后可明确继续原操作；刷新或切换身份后只能按原号只读找回。" />
    <ElButton v-if="pendingNotSubmitted" v-permission="unsubmittedPermission" :disabled="Boolean(busy) || postFlight" @click="retryUnsubmitted">保存原记录并继续原{{ journal?.phase === 'save_pending' ? '保存' : journal?.phase === 'verification_pending' ? '验证（可能收费）' : '发布' }}</ElButton>
    <ElButton v-if="recoveryRequired || (journal && !draft)" v-permission="'sand_ai:setup:deployment:read'" :loading="busy === 'read'" :disabled="Boolean(busy) || postFlight || pendingNotSubmitted" @click="readState">只读找回原配置与状态</ElButton>
    <p class="muted">{{ usageNotice }}</p>
    <p class="muted">保存、验证、读取和发布需对应后台权限；缺少操作按钮时，请联系管理员配置权限。</p>
    <ElForm label-width="110px" @submit.prevent>
      <template v-if="!draft">
      <ElFormItem label="服务商名称">
        <ElInput v-model.trim="providerName" maxlength="160" :disabled="locked" placeholder="例如：语音服务账号" />
      </ElFormItem>
      <ElFormItem label="服务地址">
        <ElInput v-model.trim="baseUrl" :disabled="locked" placeholder="https://服务商提供的 API 基础地址" />
      </ElFormItem>
      <ElFormItem label="API Key">
        <ElInput v-model="apiKey" type="password" show-password autocomplete="off" :disabled="locked" placeholder="仅用于保存服务商连接" />
      </ElFormItem>
      <ElFormItem label="远端模型">
        <ElInput v-model.trim="remoteModel" maxlength="128" :disabled="locked" placeholder="服务商提供的语音合成模型标识" />
      </ElFormItem>
      <ElFormItem label="验证音色">
        <ElInput v-model.trim="voice" maxlength="64" :disabled="locked" placeholder="例如 alloy；以服务商支持的标识为准" />
      </ElFormItem>
      <ElFormItem label="音频格式">
        <ElSelect v-model="responseFormat" :disabled="locked" aria-label="语音合成输出格式">
          <ElOption label="MP3（默认）" value="mp3" />
          <ElOption label="WAV（PCM 容器）" value="wav" />
        </ElSelect>
        <p class="muted">按服务商支持的格式选择。保存后格式随此修订固定，验证会检查相应音频结构。</p>
      </ElFormItem>
      </template>
      <ElFormItem v-if="!draft && !journal">
        <ElButton v-permission="'sand_ai:setup:deployment:save'" type="primary" :loading="busy === 'save'" :disabled="locked || !canSave" @click="save">
          保存语音部署草稿
        </ElButton>
      </ElFormItem>
      <p v-if="!draft" class="muted">服务地址须为 HTTPS；模型标识只能包含英文字母、数字、点、下划线、冒号和连字符。</p>
      <template v-if="draft">
        <p>部署 {{ draft.deployment_id }} · 修订 {{ draft.revision }} · 模型 {{ draft.deployment.remote_model }} · {{ verification?.state ?? draft.state }}</p>
        <p v-if="verification?.failure" class="muted">{{ verification.failure.message }}</p>
        <ElFormItem label="处理位置">
          <ElSelect v-model="processingLocation" :disabled="Boolean(busy) || postFlight || recoveryRequired || Boolean(journal?.verification_key)" placeholder="按服务商实际处理位置确认">
            <ElOption label="本地运行" value="local_runtime" />
            <ElOption label="私有网络" value="private_network" />
            <ElOption label="公共云" value="public_cloud" />
          </ElSelect>
        </ElFormItem>
        <ElFormItem>
          <ElSpace wrap>
            <ElButton v-permission="'sand_ai:setup:deployment:verify'" :loading="busy === 'verify'" :disabled="!canVerify" @click="verify">验证语音合成</ElButton>
            <ElButton v-permission="'sand_ai:setup:deployment:read'" :loading="busy === 'read'" :disabled="Boolean(busy) || postFlight || pendingNotSubmitted" @click="readState">读取修订状态</ElButton>
            <ElButton v-permission="'sand_ai:onboarding:publish'" type="primary" :loading="busy === 'publish'" :disabled="!canPublish" @click="publish">发布语音部署</ElButton>
          </ElSpace>
        </ElFormItem>
      </template>
    </ElForm>
    <p v-if="verification?.state === 'verification_ambiguous'" class="muted">原验证结果不确定。保留此部署、修订和原验证号，只读核查；不会重发或另建部署绕过未知结果。</p>
    <ElButton v-if="canStartNew" :disabled="Boolean(busy) || postFlight" @click="startNewDraft">新建语音部署草稿</ElButton>
    <p v-if="published" class="muted">部署已发布。请到能力配置选择此部署和语音合成驱动，再发布目标环境的能力档；SandIAM 客户端授权仍需单独配置。</p>
    <ElButton v-if="published" @click="router.push('/sand-ai/capability')">配置语音能力路由</ElButton>
  </ElCard>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useUserStore } from '@/store/modules/user'
import { type SpeechDeployment } from '../api/onboarding'
import { useAudioDeploymentRecovery } from '../composables/useAudioDeploymentRecovery'

const router = useRouter()
const userStore = useUserStore()
const authenticated = computed(() => Boolean(userStore.accessToken) && Number.isSafeInteger(Number(userStore.info.id)) && Number(userStore.info.id) > 0)
const providerName = ref('')
const baseUrl = ref('')
const apiKey = ref('')
const remoteModel = ref('')
const voice = ref('alloy')
const responseFormat = ref<'mp3' | 'wav'>('mp3')
const { journal, draft, verification, processingLocation, busy, postFlight, locked, published, recoveryRequired,
  canVerify, canPublish, canStartNew, message, messageType, usageNotice, phaseLabel, save: saveDeployment,
  pendingNotSubmitted, retryUnsubmitted, verify, publish, readState, startNewDraft } = useAudioDeploymentRecovery('audio_speech', () => {
    providerName.value = ''; baseUrl.value = ''; apiKey.value = ''; remoteModel.value = ''
    voice.value = 'alloy'; responseFormat.value = 'mp3'
  })
const unsubmittedPermission = computed(() => journal.value?.phase === 'save_pending' ? 'sand_ai:setup:deployment:save'
  : journal.value?.phase === 'verification_pending' ? 'sand_ai:setup:deployment:verify' : 'sand_ai:onboarding:publish')
const canSave = computed(() => Boolean(providerName.value && validBaseUrl(baseUrl.value) && apiKey.value
  && /^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}$/.test(remoteModel.value)
  && /^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/.test(voice.value)))
function validBaseUrl(value: string): boolean {
  try {
    const url = new URL(value)
    return url.protocol === 'https:' && Boolean(url.hostname) && !url.username && !url.password && !url.search && !url.hash
  } catch { return false }
}
async function save(): Promise<void> {
  if (!authenticated.value || locked.value || !canSave.value) return
  const suffix = crypto.randomUUID().replaceAll('-', '').slice(0, 12)
  let payload: SpeechDeployment | undefined = {
    provider: { code: `speech_${suffix}`, name: providerName.value, adapter: 'openai_compatible', timeout_ms: 30000 },
    model: { code: `speech_${suffix}`, name: remoteModel.value, type: 'audio_speech', capabilities: ['audio_speech'] },
    connection: { base_url: baseUrl.value, api_key: apiKey.value },
    remote_model: remoteModel.value,
    config: { verification_voice: voice.value, speech_response_format: responseFormat.value }
  }
  const submission = saveDeployment(payload)
  payload = undefined
  apiKey.value = ''
  await submission
}
</script>

<style scoped>
.speech-setup { margin-top: 16px; }
.speech-setup h4 { margin: 0; }
.speech-setup .muted { color: var(--el-text-color-secondary); }
.speech-setup .notice { margin-bottom: 16px; }
</style>
