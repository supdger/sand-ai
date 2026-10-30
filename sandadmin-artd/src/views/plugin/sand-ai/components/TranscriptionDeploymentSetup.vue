<template>
  <ElCard shadow="never" class="transcription-setup">
    <template #header><h4>录音转写模型部署</h4></template>
    <p v-if="isLocal" class="muted">录音在当前宿主处理，无需服务地址或 API Key。保存后请明确发起验证，使用包内英文短录音。通过只确认转写结果结构有效，中文识别和长会议仍需另行验证。</p>
    <p v-else class="muted">保存草稿后，请明确发起验证，服务端使用包内固定英文短录音验证一次转写。验证可能收费，费用按平台验证记录核对；通过仅证明返回结构有效，不代表会议识别质量。</p>
    <ElAlert v-if="message" :type="messageType" :title="message" :closable="false" class="notice" />
    <ElAlert v-if="journal" type="info" :closable="false" :title="`原保存请求 ${journal.save_key} · 原方式 ${journal.mode === 'local' ? '本机转写' : '外部服务'} · ${journal.deployment_id ? `部署 ${journal.deployment_id} / 修订 ${journal.revision}` : '保存结果待确认'} · ${phaseLabel}`" />
    <p v-if="journal?.verification_key" class="muted">原验证请求号：{{ journal.verification_key }}</p>
    <ElAlert v-if="pendingNotSubmitted" type="warning" :closable="false" title="本页确认原操作尚未发送。原配置仅留在内存中，恢复存储后可明确继续原操作；刷新或切换身份后只能按原号只读找回。" />
    <ElButton v-if="pendingNotSubmitted" v-permission="unsubmittedPermission" :disabled="Boolean(busy) || postFlight" @click="retryUnsubmitted">保存原记录并继续原{{ journal?.phase === 'save_pending' ? '保存' : journal?.phase === 'verification_pending' ? '验证（可能收费）' : '发布' }}</ElButton>
    <ElButton v-if="recoveryRequired || (journal && !draft)" v-permission="'sand_ai:setup:deployment:read'" :loading="busy === 'read'" :disabled="Boolean(busy) || postFlight || pendingNotSubmitted" @click="readState">只读找回原配置与状态</ElButton>
    <p class="muted">{{ usageNotice }}</p>
    <ElForm label-width="110px" @submit.prevent>
      <ElFormItem label="转写方式">
        <ElSelect v-model="transcriptionMode" :disabled="locked">
          <ElOption label="本机转写" value="local" />
          <ElOption label="外部服务" value="external" />
        </ElSelect>
      </ElFormItem>
      <template v-if="!draft">
      <ElFormItem :label="isLocal ? '部署名称' : '服务商名称'">
        <ElInput v-model.trim="providerName" maxlength="160" :disabled="locked" :placeholder="isLocal ? '例如：会议录音本机转写' : '例如：录音转写服务账号'" />
      </ElFormItem>
      <template v-if="isLocal">
        <ElFormItem label="本机转写引擎">
          <div>
            <p class="muted">{{ localRuntimeMessage }}</p>
            <ElButton v-permission="'sand_ai:setup:audio-transcription:read'" :loading="audioRuntimeBusy" :disabled="Boolean(busy) || audioRuntimeBusy" @click="emit('refresh-runtime')">检查本机引擎配置</ElButton>
          </div>
        </ElFormItem>
      </template>
      <template v-else>
        <ElFormItem label="服务地址">
          <ElInput v-model.trim="baseUrl" :disabled="locked" placeholder="https://服务商提供的 API 基础地址" />
        </ElFormItem>
        <ElFormItem label="API Key">
          <ElInput v-model="apiKey" type="password" show-password autocomplete="off" :disabled="locked" placeholder="仅用于保存服务商连接" />
        </ElFormItem>
        <ElFormItem label="远端模型">
          <ElSelect v-model="remoteModel" :disabled="locked" placeholder="选择受支持的转写模型">
            <ElOption label="Whisper 分段转写（whisper-1）" value="whisper-1" />
            <ElOption label="说话人分段转写（gpt-4o-transcribe-diarize）" value="gpt-4o-transcribe-diarize" />
            <ElOption label="30 秒内文字转写（glm-asr-2512）" value="glm-asr-2512" />
          </ElSelect>
        </ElFormItem>
        <ElAlert v-if="remoteModel === 'glm-asr-2512'" type="info" title="仅支持 30 秒以内、25,000,000 字节以内的 WAV 或 MP3。返回整段文字，没有逐句时间或说话人识别；需人工核对。" :closable="false" class="notice" />
      </template>
      </template>
      <ElFormItem v-if="!draft && !journal">
        <ElButton v-permission="'sand_ai:setup:deployment:save'" type="primary" :loading="busy === 'save'" :disabled="locked || !canSave" @click="save">
          保存转写部署草稿
        </ElButton>
      </ElFormItem>
      <p v-if="!draft && !isLocal" class="muted">服务地址须为 HTTPS；部署配置由服务端固定为空对象。验证样本不会作为业务录音保存。</p>
      <template v-if="draft">
        <p>部署 {{ draft.deployment_id }} · 修订 {{ draft.revision }} · 模型 {{ draft.deployment.remote_model }} · {{ published ? '已发布' : verification?.state ?? draft.state }}</p>
        <p v-if="verification?.failure" class="muted">{{ verification.failure.message }}</p>
        <ElFormItem label="处理位置">
          <span v-if="isLocal">本地运行</span>
          <ElSelect v-else v-model="processingLocation" :disabled="Boolean(busy) || postFlight || recoveryRequired || Boolean(journal?.verification_key)" placeholder="按服务商实际处理位置确认">
            <ElOption label="本地运行" value="local_runtime" />
            <ElOption label="私有网络" value="private_network" />
            <ElOption label="公共云" value="public_cloud" />
          </ElSelect>
        </ElFormItem>
        <ElFormItem>
          <ElSpace wrap>
            <ElButton v-permission="'sand_ai:setup:deployment:verify'" :loading="busy === 'verify'" :disabled="!canVerify" @click="verify">验证录音转写</ElButton>
            <ElButton v-permission="'sand_ai:setup:deployment:read'" :loading="busy === 'read'" :disabled="Boolean(busy) || postFlight || pendingNotSubmitted" @click="readState">读取修订状态</ElButton>
            <ElButton v-permission="'sand_ai:onboarding:publish'" type="primary" :loading="busy === 'publish'" :disabled="!canPublish" @click="publish">发布转写部署</ElButton>
          </ElSpace>
        </ElFormItem>
      </template>
    </ElForm>
    <p v-if="verification?.state === 'verification_ambiguous'" class="muted">原验证结果不确定。保留原方式、部署、修订和原验证号，只读核查；不会重发或另建部署绕过未知结果。</p>
    <ElButton v-if="canStartNew" :disabled="Boolean(busy) || postFlight" @click="startNewDraft">新建转写部署草稿</ElButton>
    <p v-if="published" class="muted">{{ isLocal ? '在目标环境新增录音转写路由，选择此部署与本机转写驱动，再保存并发布能力配置。SandIAM 客户端授权需单独配置。' : '部署已发布。请到能力配置选择此部署和录音转写驱动，再发布目标环境的能力档；SandIAM 客户端授权仍需单独配置。' }}</p>
    <p v-if="published && isLocal" class="muted">本机转写不会自动变更应用的数据发送许可。仅本地或仅私有的录音不能用于云端会议摘要；需要云端摘要时，调用方须明确允许云端处理文字。</p>
    <ElButton v-if="published" @click="router.push('/sand-ai/capability')">配置转写能力路由</ElButton>
  </ElCard>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { type AudioRuntimeMetadata, type ExternalTranscriptionDeployment, type TranscriptionDeployment } from '../api/onboarding'
import { useAudioDeploymentRecovery } from '../composables/useAudioDeploymentRecovery'

const props = defineProps<{
  audioRuntime: AudioRuntimeMetadata | null
  audioRuntimeBusy: boolean
  audioRuntimeError: string
}>()
const emit = defineEmits<{ 'refresh-runtime': [] }>()
const router = useRouter()
const providerName = ref('')
const baseUrl = ref('')
const apiKey = ref('')
const remoteModel = ref<ExternalTranscriptionDeployment['remote_model']>('whisper-1')
const { mode: transcriptionMode, journal, draft, verification, processingLocation, busy, postFlight, locked, published,
  recoveryRequired, canVerify, canPublish, canStartNew, message, messageType, usageNotice, phaseLabel, save: saveDeployment,
  pendingNotSubmitted, retryUnsubmitted, verify, publish, readState, startNewDraft } = useAudioDeploymentRecovery('audio_transcription', () => {
    providerName.value = ''; baseUrl.value = ''; apiKey.value = ''; remoteModel.value = 'whisper-1'
  })
const unsubmittedPermission = computed(() => journal.value?.phase === 'save_pending' ? 'sand_ai:setup:deployment:save'
  : journal.value?.phase === 'verification_pending' ? 'sand_ai:setup:deployment:verify' : 'sand_ai:onboarding:publish')
const isLocal = computed(() => transcriptionMode.value === 'local')
const localConfigured = computed(() => !props.audioRuntimeBusy && !props.audioRuntimeError
  && props.audioRuntime?.adapter_kinds.some(item => item.technical.adapter_kind === 'local' && item.runtime_state === 'configured'))
const localRuntimeMessage = computed(() => {
  if (props.audioRuntimeBusy) return '正在检查本机引擎配置。'
  if (props.audioRuntimeError) return '无法读取引擎配置，请核对后台读取权限后重试。'
  if (!props.audioRuntime) return '请先检查本机引擎配置。'
  return localConfigured.value
    ? '本机转写引擎已配置。保存草稿后仍须实际验证。'
    : '本机转写尚未配置。请联系宿主运维配置转写引擎后，重新检查。'
})
const canSave = computed(() => Boolean(providerName.value && providerName.value.length <= 160
  && (isLocal.value ? localConfigured.value : validBaseUrl(baseUrl.value) && apiKey.value
    && ['whisper-1', 'gpt-4o-transcribe-diarize', 'glm-asr-2512'].includes(remoteModel.value))))
function validBaseUrl(value: string): boolean {
  try {
    const url = new URL(value)
    return url.protocol === 'https:' && Boolean(url.hostname) && !url.username && !url.password && !url.search && !url.hash
  } catch { return false }
}
async function save(): Promise<void> {
  if (locked.value || !canSave.value) return
  const suffix = crypto.randomUUID().replaceAll('-', '').slice(0, 12)
  const payload: TranscriptionDeployment = isLocal.value ? {
    provider: { code: `asr_${suffix}`, name: providerName.value, adapter: 'local_runtime', timeout_ms: 120000 },
    model: { code: `asr_${suffix}`, name: providerName.value, type: 'audio_transcription', capabilities: ['audio_transcription'] },
    remote_model: 'local_whisper_cpp', config: {}
  } : {
    provider: { code: `asr_${suffix}`, name: providerName.value, adapter: 'openai_compatible', timeout_ms: 30000 },
    model: { code: `asr_${suffix}`, name: remoteModel.value, type: 'audio_transcription', capabilities: ['audio_transcription'] },
    connection: { base_url: baseUrl.value, api_key: apiKey.value }, remote_model: remoteModel.value, config: {}
  }
  await saveDeployment(payload)
}
</script>

<style scoped>
.transcription-setup { margin-top: 16px; }
.transcription-setup h4 { margin: 0; }
.transcription-setup .muted { color: var(--el-text-color-secondary); }
.transcription-setup .notice { margin-bottom: 16px; }
</style>
