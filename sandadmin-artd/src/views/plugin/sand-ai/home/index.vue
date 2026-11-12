<template>
  <SandAiErrorBoundary>
    <div class="sand-ai-page">
      <ElCard class="art-table-card sand-ai-page-card" shadow="never">
        <div class="home-head">
          <div>
            <h2 class="home-title">AI 首页</h2>
            <p class="home-desc">看开通进度，并马上试用 AI</p>
          </div>
          <ElButton :loading="loading" @click="loadAll">刷新</ElButton>
        </div>

        <p v-if="bootstrapNotice" class="home-bootstrap">
          {{ bootstrapNotice.message }}
          <ElButton v-if="bootstrapNotice.retryable" link type="primary" :loading="bootstrapBusy" @click="retryBootstrap">重试</ElButton>
        </p>

        <div v-if="loading" class="home-body">
          <ElSkeleton :rows="6" animated />
        </div>

        <ElResult v-else-if="loadError" icon="error" title="AI 首页没能加载出来"
          :sub-title="loadError || '系统出了点问题，请稍后再试'">
          <template #extra>
            <ElSpace wrap>
              <ElButton type="primary" @click="loadAll">重新加载</ElButton>
              <ElButton :loading="copyingProblem" @click="copyProblemDetails">复制问题信息</ElButton>
            </ElSpace>
          </template>
        </ElResult>

        <template v-else>
        <!-- 已完成：状态面板 -->
        <div v-if="completed" class="home-body">
          <ElAlert type="success" :closable="false" title="AI 对话已经可以使用。" />
          <div class="home-cards">
            <div v-for="card in statusCards" :key="card.key" class="home-card">
              <template v-if="panelStates[card.key] === 'loading'">
                <ElSkeleton :rows="2" animated />
              </template>
              <template v-else-if="panelStates[card.key] === 'error'">
                <div class="home-card__label">{{ card.label }}</div>
                <div class="home-card__error">
                  没取到
                  <ElButton link type="primary" @click="retryPanelField(card.key)">重试</ElButton>
                </div>
              </template>
              <template v-else>
                <div class="home-card__label">{{ card.label }}</div>
                <div class="home-card__value">{{ card.value }}</div>
                <ElButton link type="primary" @click="card.open">{{ card.action }}</ElButton>
              </template>
            </div>
          </div>
          <ElSpace wrap>
            <ElButton type="primary" @click="go('/sand-ai/trial')">去试用</ElButton>
          </ElSpace>
        </div>

        <!-- 未完成：开通向导 -->
        <div v-else class="home-body">
          <ol class="home-steps">
            <li v-for="(step, index) in steps" :key="step.key" class="home-step" :class="{ 'is-done': step.done }">
              <span class="home-step__mark">{{ step.done ? '✓' : index }}</span>
              <div>
                <div class="home-step__title">{{ step.title }}</div>
                <div class="home-step__text">{{ step.text }}</div>
              </div>
            </li>
          </ol>

          <ElAlert v-if="intent" type="warning" :closable="false" title="原开通操作的结果还没有确认"
            description="为避免重复发送，页面已锁定写入；请先找回原开通状态。" />
          <ElButton v-if="intent" :loading="busy === 'recover'" :disabled="Boolean(busy)" @click="recoverActivation">
            找回原开通状态
          </ElButton>
          <ElButton v-if="intent" :disabled="Boolean(busy)" @click="abandonActivation">放弃此次变更</ElButton>
          <ElAlert v-if="intentStorageError" type="error" :closable="false"
            title="本机的原开通记录无法确认，已锁定写入。请恢复浏览器会话存储后找回原状态。" />
          <ElAlert v-if="ambiguous" type="warning" :closable="false"
            title="模型测试结果无法确定，可能已产生服务商费用。请到服务商控制台核查调用记录；本页不会重新发送测试。" />
          <ElAlert v-if="conflict" type="warning" :closable="false" :title="conflict" />
          <ElAlert v-if="message" :type="messageType" :closable="false" :title="message" />

          <!-- 只读找回已确认可续办的原开通目标：从原部署/修订继续，绝不重发已确认的请求 -->
          <section v-if="intentContinuable" class="home-section">
            <h3>继续开通</h3>
            <p class="home-muted">原开通进度已确认，继续完成启用与绑定；已发送的请求不会重复。</p>
            <ElButton v-permission="'sand_ai:onboarding:publish'" type="primary" :loading="busy === 'activate'"
              :disabled="!canActivate" @click="activate">{{ activateLabel }}</ElButton>
            <span v-if="progress" class="home-muted">{{ progress }}</span>
          </section>

          <div v-if="!intent && applications.length" class="home-target">
            <span class="home-muted">绑定目标：</span>
            <ElSelect v-if="applications.length > 1" v-model="applicationId" class="home-target__select" @change="onApplicationChange">
              <ElOption v-for="app in applications" :key="app.id" :value="app.id" :label="app.name" />
            </ElSelect>
            <ElSelect v-if="environments.length > 1" v-model="environmentId" class="home-target__select" @change="onEnvironmentChange">
              <ElOption v-for="env in environments" :key="env.id" :value="env.id" :label="env.name" />
            </ElSelect>
            <span v-if="environments.length <= 1" class="home-muted">{{ environmentLabel }}</span>
          </div>
          <p v-if="!intent && applications.length && !environmentId && environmentLoadFailed" class="home-muted">
            应用环境列表读取失败，请点击「刷新」重试；读取失败不代表没有环境。
            <ElButton link type="primary" :loading="loading" @click="loadAll">重试</ElButton>
          </p>
          <p v-else-if="!intent && applications.length && !environmentId" class="home-muted">
            还没有可用的应用环境。请先在「应用接入」创建应用与环境，再回来完成绑定。
            <ElButton link type="primary" @click="go('/sand-iam/application')">去应用接入</ElButton>
          </p>
          <p v-if="!intent && !applications.length && iamChecked" class="home-muted">
            还没有可用的应用。请先在「应用接入」创建应用与环境，再回来完成开通。
            <ElButton link type="primary" @click="go('/sand-iam/application')">去应用接入</ElButton>
          </p>

          <!-- 第 1 步：连接服务商 -->
          <section v-if="!flags.provider && !intent" class="home-section">
            <h3>1. 连接服务商</h3>
            <p class="home-muted">选择一家，粘贴 API Key。Key 加密保存，之后不会再显示。</p>
            <ElRadioGroup v-model="guideCode" class="home-guides">
              <ElRadio v-for="item in guides" :key="guideKey(item)" :value="guideKey(item)" border>
                {{ item.name }}
              </ElRadio>
            </ElRadioGroup>
            <ElForm label-width="90px" class="home-form" @submit.prevent>
              <ElFormItem label="API Key">
                <ElInput v-model="apiKey" type="password" show-password autocomplete="off" placeholder="只在本次连接中使用" />
                <div v-if="guide?.api_key_help" class="home-muted">{{ guide.api_key_help }}</div>
                <a v-if="apiKeyUrl" :href="apiKeyUrl" target="_blank" rel="noopener noreferrer" class="home-link">如何获取 API Key？</a>
              </ElFormItem>
              <ElFormItem label="接口地址">
                <ElInput v-model="baseUrl" placeholder="https://服务商提供的 API 基础地址" />
              </ElFormItem>
              <ElFormItem>
                <ElButton :loading="busy === 'test'" :disabled="Boolean(busy) || !canTest" @click="testConnection">测试连接</ElButton>
                <span v-if="connectionOk" class="home-ok">连接成功{{ discoveredModels.length ? `，返回 ${discoveredModels.length} 个可用模型` : '' }}</span>
              </ElFormItem>
            </ElForm>
          </section>

          <!-- 第 2 步：选择默认对话模型 -->
          <section v-if="showModelStep" class="home-section">
            <h3>2. 选择默认对话模型</h3>
            <p class="home-muted">选一个作为默认对话模型，一键完成测试、启用并绑定到应用。</p>
            <ElButton v-if="flags.provider && !discoveredModels.length" :loading="busy === 'models'"
              :disabled="Boolean(busy)" @click="loadProviderModels">读取可用模型</ElButton>
            <ElRadioGroup v-if="modelOptions.length" v-model="remoteModel" class="home-models">
              <ElRadio v-for="item in modelOptions" :key="item.value" :value="item.value">{{ item.label }}</ElRadio>
            </ElRadioGroup>
            <ElForm label-width="90px" class="home-form" @submit.prevent>
              <ElFormItem v-if="!modelOptions.length || manualModel" label="模型标识">
                <ElInput v-model="remoteModelManual" placeholder="服务商控制台给出的聊天模型标识" />
              </ElFormItem>
              <ElFormItem v-if="modelOptions.length">
                <ElCheckbox v-model="manualModel">手动填写其他模型标识</ElCheckbox>
              </ElFormItem>
              <ElFormItem>
                <ElButton v-permission="'sand_ai:onboarding:publish'" type="primary" :loading="busy === 'activate'"
                  :disabled="!canActivate" @click="activate">{{ activateLabel }}</ElButton>
                <span v-if="progress" class="home-muted">{{ progress }}</span>
              </ElFormItem>
            </ElForm>
          </section>

          <!-- 存在本页之外创建的未完成配置 -->
          <section v-if="foreignPending && !intent" class="home-section">
            <h3>继续完成启用</h3>
            <p class="home-muted">检测到尚未启用的模型配置。请到「首次开通」核对并完成剩余步骤。</p>
            <ElButton type="primary" @click="go('/sand-ai/onboarding')">去首次开通页继续</ElButton>
          </section>

          <!-- 第 3 步：已启用、待绑定 -->
          <section v-if="flags.published && !flags.bound && !intent" class="home-section">
            <h3>3. 绑定到应用</h3>
            <p class="home-muted">模型已启用，绑定到目标环境后应用即可调用。</p>
            <ElButton v-permission="'sand_ai:onboarding:publish'" type="primary" :loading="busy === 'bind'"
              :disabled="Boolean(busy) || !environmentId" @click="bindPublished">绑定到当前环境</ElButton>
          </section>

          <section class="home-section">
            <h3>让应用使用 AI</h3>
            <p class="home-muted">在「应用接入」为应用开通 AI 调用权限。</p>
            <ElButton @click="go('/sand-iam/application')">去应用接入</ElButton>
          </section>

          <details v-if="intent" class="home-tech">
            <summary>技术详情</summary>
            <p>部署 {{ intent.deployment_id ?? '未确认' }} · 修订 {{ intent.revision ?? '未确认' }} · 请求号 {{ intent.save_key }}</p>
          </details>
        </div>

        <ExpertSelectionHelp title="不知道该用哪个？" class="home-help" />
        </template>
      </ElCard>
      <EgressBlockedHint
        v-if="egressHint"
        :model-value="true"
        :service-name="guide?.name || '该服务'"
        :details="egressHint"
        @update:model-value="(value: boolean) => { if (!value) egressHint = null }"
        @retry="() => { egressHint = null; egressHintRetry?.() }"
      />
    </div>
  </SandAiErrorBoundary>
</template>

<script setup lang="ts">
  import SandAiErrorBoundary from '../components/SandAiErrorBoundary.vue'
  import '../components/sandAiPage.css'
  import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
  import { useRouter } from 'vue-router'
  import { HttpError } from '@/utils/http/error'
  import { useUserStore } from '@/store/modules/user'
  import iamApi, { type SandIamApplicationReference, type SandIamEnvironmentReference } from '../api/iam'
  import onboardingApi, {
    type DiscoveredModel,
    type DraftSave,
    type NewDeployment,
    type OnboardingStatus,
    type ProviderGuide,
    type ProviderModelDeployment,
    type PublishedDeployment,
    type SuccessorDeployment,
    type Verification,
    type VerificationFailure,
    type VerificationFailureReason
  } from '../api/onboarding'
  import providerApi from '../api/provider'
  import { egressBlockedDetails, type EgressBlockedDetails } from '../api/egressBlocked'
  import EgressBlockedHint from '../components/EgressBlockedHint.vue'
  import modelApi from '../api/model'
  import capabilityApi from '../api/capability'
  import usageApi from '../api/usage'
  import type { SandAiModel, SandAiProvider, SandAiUsageSummary } from '../api/types'
  import { formatMoney } from '../usage/modules/usagePeriod'
  import {
    abandonHomeActivationIntent,
    readHomeActivationIntent,
    retainHomeActivationIntent,
    resolveHomeActivationIntent,
    updateHomeActivationIntent,
    type HomeActivationIntent
  } from '../api/homeActivationIntent'
  import { isWriteRejected, writeRejectionText } from '../api/writeRejection'
  import { confirmAbandonPendingWrite } from '../composables/pendingWrite'
  import ExpertSelectionHelp from '../components/ExpertSelectionHelp.vue'
  import { useSetupBootstrap } from '../composables/useSetupBootstrap'
  import { describeBoundaryFault, problemInfoText } from '../composables/errorBoundary'
  import { ElMessage } from 'element-plus'

  type HomeFlags = {
    provider: boolean
    model: boolean
    deployment: boolean
    tested: boolean
    published: boolean
    bound: boolean
  }

  /** 已开通状态卡：四个字段各自独立读取，单项失败只影响该卡。 */
  type PanelKey = 'model' | 'cost' | 'calls' | 'apps'
  type PanelFieldState = 'loading' | 'ready' | 'error'

  const router = useRouter()
  const userStore = useUserStore()
  const { notice: bootstrapNotice, busy: bootstrapBusy, ensure: ensureBootstrap, retry: retryBootstrap } = useSetupBootstrap()

  const loading = ref(true)
  const loadError = ref('')
  const statusValue = ref<OnboardingStatus | null>(null)
  const degradedFlags = ref<HomeFlags | null>(null)
  const applications = ref<SandIamApplicationReference[]>([])
  const environments = ref<SandIamEnvironmentReference[]>([])
  const applicationId = ref<number | ''>('')
  const environmentId = ref<number | ''>('')
  const iamChecked = ref(false)
  // 读接口失败与「确认为空」分开：失败时给重试，不误报「没有应用环境」。
  const environmentLoadFailed = ref(false)

  const guides = ref<ProviderGuide[]>([])
  const guideCode = ref('')
  const apiKey = ref('')
  const baseUrl = ref('')
  const connectionOk = ref(false)
  const discoveredModels = ref<string[]>([])
  const egressHint = ref<EgressBlockedDetails | null>(null)
  const egressHintRetry = ref<(() => void) | null>(null)
  function openEgressHint(blocked: EgressBlockedDetails, retry: () => void): void {
    egressHint.value = blocked
    egressHintRetry.value = retry
  }
  const remoteModel = ref('')
  const remoteModelManual = ref('')
  const manualModel = ref(false)
  const reusableProvider = ref<SandAiProvider | null>(null)
  const reusableModel = ref<SandAiModel | null>(null)

  const intent = ref<HomeActivationIntent | null>(null)
  const intentStorageError = ref(false)
  const busy = ref<'' | 'test' | 'models' | 'activate' | 'recover' | 'bind'>('')
  const progress = ref('')
  const message = ref('')
  const messageType = ref<'error' | 'warning' | 'success'>('error')
  const ambiguous = ref(false)
  const conflict = ref('')

  const panelValues = ref<Record<PanelKey, string>>({ model: '', cost: '', calls: '', apps: '' })
  const panelStates = ref<Record<PanelKey, PanelFieldState>>({
    model: 'loading', cost: 'loading', calls: 'loading', apps: 'loading'
  })
  const loadFailure = ref<unknown>(null)
  const copyingProblem = ref(false)
  let live = true
  let loadGeneration = 0

  const guide = computed(() => guides.value.find(item => guideKey(item) === guideCode.value) ?? null)
  const apiKeyUrl = computed(() => {
    const value = guide.value?.api_key_url
    if (!value) return undefined
    try {
      const url = new URL(value)
      return url.protocol === 'https:' ? url.toString() : undefined
    } catch {
      return undefined
    }
  })

  const flags = computed<HomeFlags>(() => {
    const status = statusValue.value
    if (status) {
      return {
        provider: status.provider.configured,
        model: status.model.configured,
        deployment: status.deployment.configured,
        tested: status.tested_configuration.configured,
        published: status.published_deployment.configured,
        bound: status.environment_binding.configured && status.published_configuration.configured
      }
    }
    return degradedFlags.value ?? { provider: false, model: false, deployment: false, tested: false, published: false, bound: false }
  })

  const completed = computed(() => statusValue.value?.published_configuration.configured === true)
  // 服务端存在部署但未发布，且不是本页编排产生的记录时，无法在首页安全定位目标，
  // 引导到「首次开通」完成，避免重复建链。
  const foreignPending = computed(() => flags.value.deployment && !flags.value.published)
  const showModelStep = computed(() => !completed.value && !intent.value && !flags.value.tested && !foreignPending.value)

  // 降密度规格：未完成态只摆三步——连服务商、选模型、试对话；系统准备是自动检查，不占步骤位。
  const steps = computed(() => [
    { key: 'provider', title: '连接服务商', done: flags.value.provider, text: flags.value.provider ? '已连接' : '选择一家服务商并粘贴 API Key' },
    { key: 'model', title: '选默认模型', done: flags.value.tested, text: flags.value.tested ? '已选定并通过测试' : '选一个模型，一键设为默认' },
    { key: 'trial', title: '试一句对话', done: completed.value, text: completed.value ? '可以直接对话' : '绑定并开通完成后即可试用' }
  ])

  const environmentLabel = computed(() => {
    const env = environments.value.find(item => item.id === environmentId.value)
    const app = applications.value.find(item => item.id === applicationId.value)
    if (!env) return ''
    return app ? `${app.name} / ${env.name}` : env.name
  })

  /** 已开通后的四张状态卡：值来自各自字段读取，失败卡只显示「暂时读不到」+重试。 */
  const statusCards = computed<Array<{
    key: PanelKey
    label: string
    value: string
    action: string
    open: () => void
  }>>(() => [
    {
      key: 'model',
      label: '默认模型',
      value: panelValues.value.model || '未设置',
      action: '更换',
      open: () => go('/sand-ai/onboarding')
    },
    {
      key: 'cost',
      label: '本月花费',
      value: panelValues.value.cost,
      action: '查看费用',
      open: () => go('/sand-ai/usage')
    },
    {
      key: 'calls',
      label: '今日调用',
      value: panelValues.value.calls,
      action: '查看调用记录',
      open: () => go('/sand-ai/invocation')
    },
    {
      key: 'apps',
      label: '使用中的应用',
      value: panelValues.value.apps,
      action: '管理',
      open: () => go('/sand-iam/application')
    }
  ])

  const modelOptions = computed<Array<{ value: string; label: string }>>(() => {
    const list: Array<{ value: string; label: string }> = discoveredModels.value.map(value => ({ value, label: value }))
    for (const item of guide.value?.recommended_models ?? []) {
      const found = list.find(option => option.value === item.remote_model)
      if (found) {
        if (item.recommended) found.label = `${item.remote_model}（推荐）`
      } else {
        list.push({ value: item.remote_model, label: item.recommended ? `${item.remote_model}（推荐）` : item.remote_model })
      }
    }
    return list
  })

  const chosenModel = computed(() => (manualModel.value || !modelOptions.value.length ? remoteModelManual.value.trim() : remoteModel.value))

  const connectionInputError = computed(() => /\p{Cc}/u.test(baseUrl.value) || /\p{Cc}/u.test(apiKey.value)
    ? '接口地址和 API Key 不能包含换行、制表符或其他控制字符，请按服务商提供的原值填写。' : '')

  const canTest = computed(() => Boolean(baseUrl.value.trim() && apiKey.value.trim()) && !connectionInputError.value)

  // 只读找回已确认事实后才可续办的阶段；*_unknown 阶段仍只允许找回，绝不重发已发送请求。
  const intentContinuable = computed(() => intent.value !== null
    && ['saved', 'tested', 'published'].includes(intent.value.phase))

  const canActivate = computed(() => {
    if (busy.value || intentStorageError.value || ambiguous.value || conflict.value) return false
    if (intent.value) return intentContinuable.value
    if (!environmentId.value || !chosenModel.value) return false
    if (!flags.value.provider) return Boolean(canTest.value)
    return true
  })

  const activateLabel = computed(() => (intent.value ? '继续开通' : '设为默认并启用'))

  function guideKey(item: ProviderGuide): string {
    return item.provider_code || item.adapter
  }

  function go(path: string): void {
    void router.push(path)
  }

  function positiveId(value: unknown): value is number {
    return typeof value === 'number' && Number.isSafeInteger(value) && value > 0
  }

  function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value)
  }

  function notice(text: string, type: 'error' | 'warning' | 'success' = 'error'): void {
    message.value = text
    messageType.value = type
  }

  function adminOwner(): number {
    const id = Number(userStore.info.id)
    if (!userStore.accessToken || !positiveId(id)) throw new Error('当前管理员身份无法确认')
    return id
  }

  function problemCode(error: unknown): string {
    if (!(error instanceof HttpError)) return ''
    return /^SAND_AI_[A-Z_]+(?=:)/.exec(error.message)?.[0] ?? ''
  }

  function accessNotice(error: unknown): string | null {
    if (!(error instanceof HttpError)) return null
    if (error.code === 403) return '当前账号没有执行此操作的权限，请联系管理员授权。'
    if (error.code === 401) return '登录已失效，请重新登录。'
    return null
  }

  /** 保存端点在写入前显式拒绝时返回的未提交证明；只有它能解除 save_unknown 意图锁。 */
  function notSubmittedProof(error: unknown): boolean {
    return error instanceof HttpError && error.code === 400 && isRecord(error.data)
      && error.data.submission_state === 'not_submitted' && error.data.retryable === false
  }

  /** 服务端确认原请求号没有对应落库记录：原保存未提交，等同未提交证明，可解除 save_unknown 锁。 */
  function saveNotSubmittedProof(error: unknown): boolean {
    return error instanceof HttpError && problemCode(error) === 'SAND_AI_RESOURCE_NOT_FOUND'
  }

  function conflictError(error: unknown): boolean {
    return ['SAND_AI_RESOURCE_CONFLICT', 'SAND_AI_CONFIGURATION_DRIFTED', 'SAND_AI_IDEMPOTENCY_CONFLICT'].includes(problemCode(error))
  }

  const probeNotices: Readonly<Record<string, string>> = {
    SAND_AI_ONBOARDING_CONNECTION_INVALID: '接口地址或 API Key 不符合要求，请按服务商提供的原值填写。',
    SAND_AI_ONBOARDING_CONNECTION_FORBIDDEN: '接口地址指向了内网或保留地址，无法连接；请联系系统维护人员检查网络。',
    SAND_AI_ONBOARDING_DNS_UNAVAILABLE: '无法解析接口地址，请检查地址是否正确。',
    SAND_AI_ONBOARDING_CONNECTION_UNVERIFIABLE: '暂时无法安全连接服务商，请联系系统维护人员检查连接能力。',
    SAND_AI_ONBOARDING_RESPONSE_TOO_LARGE: '服务商返回的模型列表过大，请手动填写模型标识。',
    SAND_AI_PROVIDER_REJECTED: '服务商未接受 API Key 或权限不足，请核对 Key、所属服务地址和接口权限。',
    SAND_AI_PROVIDER_UNAVAILABLE: '连接服务商超时或没有响应，请检查网络和服务地址后重试。',
    SAND_AI_PROVIDER_RATE_LIMITED: '服务商暂时限流，请稍后重试。',
    SAND_AI_VALIDATION_ERROR: '输入不符合要求，请核对后重试。'
  }

  function probeFailureText(error: unknown, fallback: string): string {
    const access = accessNotice(error)
    if (access) return access
    return probeNotices[problemCode(error)] ?? fallback
  }

  const failureNotices: Readonly<Record<Exclude<VerificationFailureReason, 'local_runtime_verification_failed'>, readonly [VerificationFailure['error_code'], string]>> = {
    invalid_api_key: ['SAND_AI_PROVIDER_REJECTED', '服务商未接受 API Key，请核对有效性及所属服务地址'],
    provider_access_denied: ['SAND_AI_PROVIDER_REJECTED', '服务商拒绝了账号权限，请核对项目、模型和聊天接口权限'],
    model_or_endpoint_unavailable: ['SAND_AI_PROVIDER_REJECTED', '服务商没有提供请求的聊天模型或接口，请核对模型标识与接口地址'],
    chat_endpoint_unsupported: ['SAND_AI_PROVIDER_REJECTED', '服务商未接受聊天接口，请确认模型支持聊天以及接口地址正确'],
    model_or_parameters_rejected: ['SAND_AI_PROVIDER_REJECTED', '服务商拒绝了模型或参数，请核对聊天能力与模型标识'],
    provider_rejected: ['SAND_AI_PROVIDER_REJECTED', '服务商拒绝了测试请求，请核对 Key、权限、模型和参数'],
    provider_timeout: ['SAND_AI_PROVIDER_UNAVAILABLE', '模型测试超时，可能已发送并产生费用，请核对服务商调用记录'],
    provider_unavailable: ['SAND_AI_PROVIDER_UNAVAILABLE', '模型测试连接或响应未确认，请核对服务商调用记录和当前修订状态'],
    provider_rate_limited: ['SAND_AI_PROVIDER_RATE_LIMITED', '服务商当前限流，请核对当前修订状态和服务商调用记录后决定后续操作'],
    verification_ambiguous: ['SAND_AI_DEPLOYMENT_VERIFICATION_AMBIGUOUS', '模型测试结果无法确定，可能已产生费用；请核查服务商调用记录']
  }

  function isFailureReason(value: unknown): value is keyof typeof failureNotices {
    return typeof value === 'string' && Object.hasOwn(failureNotices, value)
  }

  function safeVerificationFailure(state: Verification | null): VerificationFailure | null {
    const failure: unknown = state?.failure
    if (!state || !['draft', 'verification_ambiguous'].includes(state.state) || !isRecord(failure)
      || !isFailureReason(failure.reason)) return null
    const [code, text] = failureNotices[failure.reason]
    const uncertain = state.state === 'verification_ambiguous'
    const expectedMessage = text + (code === 'SAND_AI_PROVIDER_REJECTED'
      ? uncertain ? '；当前修订结果仍无法确定，请先核查服务商调用记录，不要重复验证此修订' : '，修正后可重新配置连接'
      : '')
    if (failure.error_code !== code || failure.message !== expectedMessage
      || failure.outcome_uncertain !== uncertain
      || failure.recovery_action !== (uncertain ? 'check_provider_record' : 'reconfigure_connection')
      || (!uncertain && code !== 'SAND_AI_PROVIDER_REJECTED')
      || (failure.failed_at !== null && (typeof failure.failed_at !== 'string'
        || !/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(failure.failed_at)))) return null
    return { error_code: code, reason: failure.reason, message: expectedMessage,
      recovery_action: uncertain ? 'check_provider_record' : 'reconfigure_connection',
      outcome_uncertain: uncertain, failed_at: failure.failed_at }
  }

  function persistIntent(next: HomeActivationIntent): HomeActivationIntent {
    updateHomeActivationIntent(next)
    intent.value = next
    intentStorageError.value = false
    return next
  }

  function assertDraftMatchesIntent(saved: DraftSave, target: HomeActivationIntent): void {
    if (saved.model.type !== 'chat' || !positiveId(saved.deployment_id) || !positiveId(saved.revision)
      || !positiveId(saved.provider.id) || !positiveId(saved.model.id)
      || !['draft', 'verifying', 'tested', 'published', 'verification_ambiguous'].includes(saved.state)
      || (target.provider_code !== null && saved.provider.code !== target.provider_code)
      || (target.model_code !== null && saved.model.code !== target.model_code)
      || (target.provider_id !== null && saved.provider.id !== target.provider_id)
      || (target.model_id !== null && saved.model.id !== target.model_id)
      || (target.deployment_id !== null && (saved.deployment_id !== target.deployment_id || saved.revision !== target.revision))) {
      throw new Error('原开通目标不一致')
    }
  }

  function baseIntent(env: number, suffix: string): HomeActivationIntent {
    return {
      version: 1,
      admin_id: adminOwner(),
      environment_id: env,
      save_key: crypto.randomUUID(),
      verify_key: null,
      provider_code: `onboard_${suffix}`,
      model_code: `chat_${suffix}`,
      provider_id: null,
      model_id: null,
      deployment_id: null,
      revision: null,
      phase: 'save_unknown'
    }
  }

  async function resolveReusableProvider(): Promise<SandAiProvider | null> {
    if (reusableProvider.value) return reusableProvider.value
    const page = await providerApi.list({ page: 1, limit: 100, status: 1 })
    const candidates = page.data.filter(item => item.status === 1 && item.adapter === 'openai_compatible')
    if (candidates.length !== 1 || !candidates[0]) return null
    reusableProvider.value = candidates[0]
    return candidates[0]
  }

  async function resolveReusableModel(): Promise<SandAiModel | null> {
    if (reusableModel.value) return reusableModel.value
    const page = await modelApi.list({ page: 1, limit: 100, status: 1 })
    const candidates = page.data.filter(item => item.status === 1 && item.type === 'chat')
    if (candidates.length !== 1 || !candidates[0]) return null
    reusableModel.value = candidates[0]
    return candidates[0]
  }

  async function buildActivation(): Promise<{ intent: HomeActivationIntent; payload: NewDeployment | ProviderModelDeployment | SuccessorDeployment } | null> {
    const env = environmentId.value
    if (!env) {
      notice('请先选择目标环境。', 'warning')
      return null
    }
    const modelName = chosenModel.value
    if (!modelName) {
      notice('请先选择或填写默认对话模型。', 'warning')
      return null
    }
    const suffix = crypto.randomUUID().replaceAll('-', '').slice(0, 12)
    const displayName = modelName.length > 150 ? `${modelName.slice(0, 150)}...` : modelName
    if (!flags.value.provider) {
      const payload: NewDeployment = {
        provider: { code: `onboard_${suffix}`, name: guide.value?.name || '模型服务商', adapter: 'openai_compatible', timeout_ms: 120000 },
        model: { code: `chat_${suffix}`, name: displayName, type: 'chat', capabilities: ['chat'] },
        connection: { base_url: baseUrl.value.trim(), api_key: apiKey.value },
        remote_model: modelName,
        config: { max_output_tokens: 1024 }
      }
      return { intent: baseIntent(env, suffix), payload }
    }
    const provider = await resolveReusableProvider()
    if (!provider) {
      notice('无法确定要复用的服务商，请到「首次开通」继续。', 'warning')
      return null
    }
    if (!flags.value.model) {
      const payload: ProviderModelDeployment = {
        provider_id: provider.id,
        model: { code: `chat_${suffix}`, name: displayName, type: 'chat', capabilities: ['chat'] },
        remote_model: modelName,
        config: { max_output_tokens: 1024 }
      }
      return { intent: { ...baseIntent(env, suffix), provider_code: null, provider_id: provider.id }, payload }
    }
    const model = await resolveReusableModel()
    if (!model) {
      notice('无法确定要复用的模型，请到「首次开通」继续。', 'warning')
      return null
    }
    const payload: SuccessorDeployment = {
      provider_id: provider.id,
      model_id: model.id,
      remote_model: modelName,
      config: { max_output_tokens: 1024 }
    }
    return { intent: { ...baseIntent(env, suffix), provider_code: null, model_code: null, provider_id: provider.id, model_id: model.id }, payload }
  }

  async function activate(): Promise<void> {
    if (busy.value || intentStorageError.value) return
    let current = intent.value
    let payload: NewDeployment | ProviderModelDeployment | SuccessorDeployment | null = null
    if (!current) {
      try {
        const built = await buildActivation()
        if (!built) return
        payload = built.payload
        retainHomeActivationIntent(built.intent)
        intent.value = built.intent
        intentStorageError.value = false
        current = built.intent
      } catch {
        intentStorageError.value = true
        notice('无法可靠保留原请求记录，本次尚未发送。', 'warning')
        return
      }
    }
    if (['verify_unknown', 'publish_unknown', 'bind_unknown'].includes(current.phase)
      || (current.phase === 'save_unknown' && payload === null)) {
      notice('原操作结果尚未确认，请先点击「找回原开通状态」。', 'warning')
      return
    }
    busy.value = 'activate'
    ambiguous.value = false
    conflict.value = ''
    try {
      // 1. 保存草稿：仅当意图处于 save_unknown 且本页保留了原始载荷时发送；结果不明只读找回。
      if (current.phase === 'save_unknown') {
        if (!payload) {
          notice('请先点击「找回原开通状态」确认原保存结果。', 'warning')
          return
        }
        progress.value = '正在保存模型配置…'
        try {
          const saved = await onboardingApi.save(payload, current.save_key)
          assertDraftMatchesIntent(saved, current)
          current = persistIntent({
            ...current,
            phase: 'saved',
            deployment_id: saved.deployment_id,
            revision: saved.revision,
            provider_id: saved.provider.id,
            model_id: saved.model.id
          })
        } catch (error) {
          apiKey.value = ''
          if (notSubmittedProof(error)) {
            try {
              resolveHomeActivationIntent(current)
              intent.value = null
            } catch {
              intentStorageError.value = true
            }
            notice('输入未接受，本次没有发送。请核对后重试。', 'warning')
          } else {
            notice(accessNotice(error) ?? '保存结果尚未确认。请点击「找回原开通状态」，不会重新保存。', 'warning')
          }
          return
        }
        apiKey.value = ''
      }
      // 2. 测试模型：发送前先把请求号与 verify_unknown 阶段落盘。
      if (current.phase === 'saved') {
        const verifyKey = crypto.randomUUID()
        current = persistIntent({ ...current, phase: 'verify_unknown', verify_key: verifyKey })
        const deploymentId = current.deployment_id
        const revision = current.revision
        if (!deploymentId || !revision) return
        progress.value = '正在测试模型…'
        try {
          const result = await onboardingApi.verify(deploymentId, revision, 'public_cloud', verifyKey, 150000)
          if (result.deployment_id !== deploymentId || result.revision !== revision) {
            throw new Error('测试结果与目标不一致')
          }
          if (result.state === 'tested' && result.validation_evidence) {
            current = persistIntent({ ...current, phase: 'tested' })
          } else if (result.state === 'verification_ambiguous') {
            ambiguous.value = true
            return
          } else {
            const failure = safeVerificationFailure(result)
            if (result.state === 'draft' && failure && !failure.outcome_uncertain) {
              try {
                resolveHomeActivationIntent(current)
                intent.value = null
              } catch {
                intentStorageError.value = true
              }
              notice(`${failure.message}。请核对连接与模型信息后重新开通。`, 'warning')
            } else {
              notice('测试结果尚未确认。请点击「找回原开通状态」，不会重复测试。', 'warning')
            }
            return
          }
        } catch (error) {
          notice(accessNotice(error) ?? '测试结果尚未确认。请点击「找回原开通状态」，不会重复测试。', 'warning')
          return
        }
      }
      // 3. 启用：发送前记录 publish_unknown。
      if (current.phase === 'tested') {
        current = persistIntent({ ...current, phase: 'publish_unknown' })
        const deploymentId = current.deployment_id
        const revision = current.revision
        if (!deploymentId || !revision) return
        progress.value = '正在启用模型…'
        try {
          const result = await onboardingApi.publish(deploymentId, revision)
          if (result.deployment_id !== deploymentId || result.state !== 'published') {
            throw new Error('启用结果与目标不一致')
          }
          current = persistIntent({ ...current, phase: 'published' })
        } catch (error) {
          if (isWriteRejected(error)) {
            abandonHomeActivationIntent(current.admin_id)
            intent.value = null
            notice(writeRejectionText(error, '这次启用被拒绝了。'), 'error')
            return
          }
          if (conflictError(error)) {
            conflict.value = '模型修订在启用前已变化，请找回原开通状态核对当前修订。'
          }
          notice(accessNotice(error) ?? '启用结果尚未确认。请点击「找回原开通状态」，不会重复启用。', 'warning')
          return
        }
      }
      // 4. 绑定：发送前记录 bind_unknown。
      if (current.phase === 'published') {
        current = persistIntent({ ...current, phase: 'bind_unknown' })
        progress.value = '正在绑定到应用…'
        current = await runBind(current)
      }
    } catch {
      intentStorageError.value = true
      notice('本机原开通记录无法可靠保存，已停止写入。请找回原开通状态。', 'warning')
    } finally {
      if (busy.value === 'activate') {
        busy.value = ''
        progress.value = ''
      }
    }
  }

  async function runBind(current: HomeActivationIntent): Promise<HomeActivationIntent> {
    const deploymentId = current.deployment_id
    const revision = current.revision
    if (!deploymentId || !revision) return current
    try {
      const result = await onboardingApi.bindDefaultChat(current.environment_id, deploymentId, revision)
      if (!positiveId(result.profile.id) || result.profile.environment_id !== current.environment_id
        || result.profile.state !== 'published') {
        throw new Error('绑定结果与目标不一致')
      }
      resolveHomeActivationIntent(current)
      intent.value = null
      notice('默认对话模型已启用，可以开始试用。', 'success')
      await refreshStatus()
    } catch (error) {
      if (isWriteRejected(error)) {
        abandonHomeActivationIntent(current.admin_id)
        intent.value = null
        notice(writeRejectionText(error, '这次绑定被拒绝了。'), 'error')
      } else if (conflictError(error)) {
        conflict.value = '目标环境已有其他已启用配置，请到「能力配置」核对后再绑定。'
        notice('目标环境已有其他已启用配置，请先核对；不会覆盖现有配置。', 'warning')
      } else {
        notice(accessNotice(error) ?? '绑定结果尚未确认。请点击「找回原开通状态」，不会重复绑定。', 'warning')
      }
    }
    return current
  }

  function abandonActivation(): void {
    const adminId = Number(userStore.info.id)
    if (!Number.isSafeInteger(adminId) || adminId < 1) return
    confirmAbandonPendingWrite(() => {
      abandonHomeActivationIntent(adminId)
      intent.value = null
      conflict.value = ''
      ambiguous.value = false
    })
  }

  /** 结果未确认时只读找回：按意图阶段逐步确认事实，绝不重发已发送的写请求。 */
  async function recoverActivation(): Promise<void> {
    if (busy.value) return
    busy.value = 'recover'
    ambiguous.value = false
    conflict.value = ''
    try {
      const stored = readHomeActivationIntent(adminOwner())
      intent.value = stored
      if (!stored) {
        intentStorageError.value = false
        await refreshStatus()
        return
      }
      let current = stored
      if (current.phase === 'save_unknown') {
        let saved: DraftSave
        try {
          saved = await onboardingApi.readSavedDraftByKey(current.save_key)
        } catch (error) {
          // 服务端确认该请求号没有落库记录 = 原保存未提交，解锁后重新开通；
          // 其余错误（漂移、冲突、不可读）保持锁定，只允许继续找回。
          if (!saveNotSubmittedProof(error)) throw error
          try {
            resolveHomeActivationIntent(current)
            intent.value = null
          } catch {
            intentStorageError.value = true
          }
          notice('未找到原保存记录，原请求没有发送。请重新填写后开通。', 'warning')
          await refreshStatus()
          return
        }
        assertDraftMatchesIntent(saved, current)
        current = persistIntent({
          ...current,
          phase: 'saved',
          deployment_id: saved.deployment_id,
          revision: saved.revision,
          provider_id: saved.provider.id,
          model_id: saved.model.id
        })
      }
      if (current.phase === 'saved' || current.phase === 'verify_unknown') {
        const deploymentId = current.deployment_id
        const revision = current.revision
        if (!deploymentId || !revision) throw new Error('原开通目标无法确认')
        const state = await onboardingApi.readVerification(deploymentId, revision, current.verify_key ?? undefined)
        if (state.deployment_id !== deploymentId || state.revision !== revision) {
          throw new Error('原验证目标不一致')
        }
        if (state.state === 'tested' && state.validation_evidence) {
          current = persistIntent({ ...current, phase: 'tested' })
        } else if (state.state === 'published') {
          current = persistIntent({ ...current, phase: 'published' })
        } else if (state.state === 'verification_ambiguous') {
          ambiguous.value = true
          notice('模型测试结果无法确定，可能已产生服务商费用。请到服务商控制台核查调用记录；本页不会重新发送测试。', 'warning')
        } else {
          const failure = safeVerificationFailure(state)
          if (state.state === 'draft' && failure && !failure.outcome_uncertain) {
            try {
              resolveHomeActivationIntent(current)
              intent.value = null
              await refreshStatus()
            } catch {
              intentStorageError.value = true
            }
            notice(`${failure.message}。请核对连接与模型信息后重新开通。`, 'warning')
            return
          }
          notice(current.phase === 'saved'
            ? '已确认草稿尚未测试，请点击「继续开通」完成后续步骤。'
            : '原测试尚无明确结果，请稍后再次找回；不会换号测试或重新配置。', 'warning')
          return
        }
      }
      if (current.phase === 'tested' || current.phase === 'publish_unknown') {
        const deploymentId = current.deployment_id
        const revision = current.revision
        if (!deploymentId || !revision) throw new Error('原开通目标无法确认')
        const [saved, state] = await Promise.all([
          onboardingApi.readDraft(deploymentId, revision),
          onboardingApi.readVerification(deploymentId, revision)
        ])
        assertDraftMatchesIntent(saved, current)
        if (saved.state === 'published' || state.state === 'published') {
          current = persistIntent({ ...current, phase: 'published' })
        } else if (state.state === 'tested' && state.validation_evidence) {
          current = persistIntent({ ...current, phase: 'tested' })
          notice('原启用尚未完成，请点击「继续开通」完成启用与绑定。', 'warning')
          return
        } else {
          notice('原启用结果仍未确认，请稍后再次找回；未确认前不会重新启用。', 'warning')
          return
        }
      }
      if (current.phase === 'published' || current.phase === 'bind_unknown') {
        const deploymentId = current.deployment_id
        if (!deploymentId) throw new Error('原开通目标无法确认')
        const result = await capabilityApi.readProfile(current.environment_id)
        const profile = result.profile
        const routes = Array.isArray(profile?.routes) ? [...profile.routes] : []
        routes.sort((left, right) => left.priority - right.priority)
        const defaultChat = routes.find(item => item.capability_code === 'chat' && item.status === 1)
        if (profile && profile.environment_id === current.environment_id && profile.state === 'published'
          && profile.status === 1 && defaultChat?.route_kind === 'model_deployment'
          && defaultChat.deployment_id === deploymentId) {
          resolveHomeActivationIntent(current)
          intent.value = null
          notice('已确认绑定完成。', 'success')
          await refreshStatus()
          return
        }
        current = persistIntent({ ...current, phase: 'published' })
        notice('尚未完成绑定，请点击「继续开通」完成最后一步。', 'warning')
      }
    } catch (error) {
      if (!(error instanceof HttpError)) intentStorageError.value = true
      notice(accessNotice(error) ?? '原开通记录无法读取或确认，请保留原目标并稍后重试找回。', 'warning')
    } finally {
      busy.value = ''
    }
  }

  async function bindPublished(): Promise<void> {
    if (busy.value || intent.value || intentStorageError.value) return
    const env = environmentId.value
    if (!env) {
      notice('请先选择目标环境。', 'warning')
      return
    }
    busy.value = 'bind'
    try {
      const page = await onboardingApi.publishedDeployments('chat', undefined, 'chat', env)
      const target = page.data.length === 1 ? page.data[0] : undefined
      if (!target || !positiveId(target.id) || !positiveId(target.deployment_revision)) {
        notice('无法唯一确定要绑定的模型，请到「能力配置」选择后再绑定。', 'warning')
        return
      }
      const targetIntent: HomeActivationIntent = {
        version: 1,
        admin_id: adminOwner(),
        environment_id: env,
        save_key: crypto.randomUUID(),
        verify_key: null,
        provider_code: null,
        model_code: null,
        provider_id: null,
        model_id: null,
        deployment_id: target.id,
        revision: target.deployment_revision,
        phase: 'bind_unknown'
      }
      try {
        retainHomeActivationIntent(targetIntent)
        intent.value = targetIntent
        intentStorageError.value = false
      } catch {
        intentStorageError.value = true
        notice('无法可靠保留原请求记录，本次尚未发送。', 'warning')
        return
      }
      await runBind(targetIntent)
    } finally {
      busy.value = ''
    }
  }

  async function testConnection(): Promise<void> {
    if (busy.value || !canTest.value) return
    busy.value = 'test'
    connectionOk.value = false
    try {
      const result = await providerApi.testConnection({
        connection: { base_url: baseUrl.value.trim(), api_key: apiKey.value }
      })
      connectionOk.value = result.ok === true
      discoveredModels.value = result.models.map(item => item.remote_model).filter(Boolean)
      notice(result.models.length ? '连接成功，已找到可用模型。' : '连接成功；服务商未返回模型列表，请手动填写模型标识。', 'success')
    } catch (error) {
      const blocked = egressBlockedDetails(error)
      if (blocked !== null) {
        openEgressHint(blocked, () => void testConnection())
      } else {
        notice(probeFailureText(error, '连接测试未完成，请检查接口地址和 API Key。'), 'warning')
      }
    } finally {
      busy.value = ''
    }
  }

  async function loadProviderModels(): Promise<void> {
    if (busy.value) return
    busy.value = 'models'
    try {
      const provider = await resolveReusableProvider()
      if (!provider) {
        notice('无法确定要复用的服务商，请到「首次开通」继续。', 'warning')
        return
      }
      const result = await providerApi.testConnection({ provider_id: provider.id })
      discoveredModels.value = result.models.map((item: DiscoveredModel) => item.remote_model).filter(Boolean)
      if (!result.models.length) {
        notice('服务商未返回模型列表，请手动填写模型标识。', 'warning')
      }
    } catch (error) {
      const blocked = egressBlockedDetails(error)
      if (blocked !== null) {
        openEgressHint(blocked, () => void loadProviderModels())
      } else {
        notice(probeFailureText(error, '未能读取可用模型，请稍后重试或手动填写模型标识。'), 'warning')
      }
    } finally {
      busy.value = ''
    }
  }

  async function loadDegraded(): Promise<void> {
    // 无应用环境时无法调用步骤判定端点；用只读列表补齐前两步的勾选，
    // 其余状态按「未完成」处理，绑定动作仍需先创建环境。
    const next: HomeFlags = { provider: false, model: false, deployment: false, tested: false, published: false, bound: false }
    try {
      const page = { page: 1, limit: 1, status: 1 }
      const [providers, models, published] = await Promise.all([
        providerApi.list(page),
        modelApi.list(page),
        onboardingApi.publishedDeployments('chat', undefined, 'chat')
      ])
      next.provider = providers.total > 0
      next.model = models.total > 0
      next.deployment = published.data.length > 0
      next.tested = published.data.length > 0
      next.published = published.data.length > 0
    } catch {
      // 列表读取失败不代表没有配置；保持全部未完成并展示重试入口。
    }
    degradedFlags.value = next
  }

  async function refreshStatus(): Promise<void> {
    if (!environmentId.value) {
      statusValue.value = null
      await loadDegraded()
      return
    }
    const generation = loadGeneration
    try {
      const status = await onboardingApi.status(environmentId.value)
      if (!live || generation !== loadGeneration) return
      statusValue.value = status
    } catch (error) {
      if (!live || generation !== loadGeneration) return
      statusValue.value = null
      notice(probeFailureText(error, '开通状态读取失败，请点击「刷新」重试。'), 'warning')
      await loadDegraded()
      return
    }
    if (completed.value) void loadPanel()
  }

  async function loadAll(): Promise<void> {
    const generation = ++loadGeneration
    loading.value = true
    loadError.value = ''
    loadFailure.value = null
    iamChecked.value = false
    try {
      let apps = await iamApi.activeApplications()
      if (!live || generation !== loadGeneration) return
      if (apps.length === 0) {
        // Zero-step first use: a fresh install materializes the default
        // organization/application/environment instead of sending the user
        // through a manual registration detour. Idempotent; a provisioning
        // failure simply leaves the guided empty state — but the follow-up
        // re-read is a real read: its failure must surface as 加载失败,
        // not be mistaken for「没有应用」。
        try {
          await iamApi.ensureDefaultAccess()
        } catch {
          // provisioning is best-effort; the re-read below decides the real state
        }
        apps = await iamApi.activeApplications()
        if (!live || generation !== loadGeneration) return
      }
      applications.value = apps
      if (!applicationId.value || !apps.some(item => item.id === applicationId.value)) {
        applicationId.value = apps[0]?.id ?? ''
      }
      if (applicationId.value) {
        try {
          const envs = await iamApi.activeEnvironments(applicationId.value)
          if (!live || generation !== loadGeneration) return
          environments.value = envs
          environmentLoadFailed.value = false
          if (!environmentId.value || !envs.some(item => item.id === environmentId.value)) {
            environmentId.value = envs[0]?.id ?? ''
          }
        } catch {
          environments.value = []
          environmentId.value = ''
          environmentLoadFailed.value = true
        }
      } else {
        environments.value = []
        environmentId.value = ''
        environmentLoadFailed.value = false
      }
      iamChecked.value = true
      await refreshStatus()
      void loadGuides()
    } catch (error) {
      if (live && generation === loadGeneration) {
        loadFailure.value = error
        loadError.value = probeFailureText(error, '加载失败，请点击「重新加载」重试。')
      }
    } finally {
      if (live && generation === loadGeneration) loading.value = false
    }
  }

  async function loadGuides(): Promise<void> {
    try {
      const result = await providerApi.guides()
      guides.value = result.guides.filter(item => item.adapter === 'openai_compatible' && item.debug_only !== true)
      if (!guideCode.value && guides.value[0]) {
        guideCode.value = guideKey(guides.value[0])
        baseUrl.value = guides.value[0].default_base_url ?? ''
      }
    } catch {
      // 预设列表不可用时仍可手动填写连接信息。
    }
  }

  async function onApplicationChange(): Promise<void> {
    const selected = applicationId.value
    environmentId.value = ''
    environments.value = []
    if (selected) {
      try {
        const envs = await iamApi.activeEnvironments(selected)
        environments.value = envs
        environmentLoadFailed.value = false
        environmentId.value = envs[0]?.id ?? ''
      } catch {
        environments.value = []
        environmentLoadFailed.value = true
      }
    }
    statusValue.value = null
    await refreshStatus()
  }

  async function onEnvironmentChange(): Promise<void> {
    statusValue.value = null
    await refreshStatus()
  }

  /** 首页花费：按币种分别合计后用费用页同一格式（¥/$）展示，跨币种不相加。 */
  function summarizeAmount(summary: SandAiUsageSummary): string {
    const rows = summary.overall?.rows ?? []
    if (!rows.length) return '暂无计量'
    const totals = new Map<string, number>()
    for (const row of rows) {
      if (row.metered_amount === null) continue
      const currency = row.currency ?? ''
      totals.set(currency, (totals.get(currency) ?? 0) + (Number(row.metered_amount) || 0))
    }
    if (totals.size === 0) return '暂未计费'
    return [...totals.entries()]
      .map(([currency, total]) => formatMoney(String(total), currency === '' ? null : currency))
      .join('；')
  }

  function summarizeEvents(summary: SandAiUsageSummary): { events: number; failed: number } {
    let events = 0
    let failed = 0
    for (const row of summary.overall?.rows ?? []) {
      events += row.total_events
      failed += row.failed_events
    }
    return { events, failed }
  }

  /** 已启用部署的展示名：模型名 · 服务商名，不落模型代码；模型名缺失才回退 display_name。 */
  function deploymentLabel(item: PublishedDeployment): string {
    if (!item.model_name) return item.display_name
    return item.provider_name ? `${item.model_name} · ${item.provider_name}` : item.model_name
  }

  /** 能力档里 chat 默认模型绑定的部署；与能力配置页「默认模型」取同一语义（非停用、优先顺序最小）。 */
  async function boundChatDeploymentId(env: number | undefined): Promise<number | null> {
    const result = await capabilityApi.readProfile({ environment_id: env })
    const routes = (result.profile?.routes ?? [])
      .filter(route => route.capability_code === 'chat' && route.status !== 2)
      .sort((left, right) => left.priority - right.priority)
    const primary = routes.find(route => route.route_kind === 'model_deployment')
    return primary?.deployment_id ?? null
  }

  /** 四张状态卡各自的取值函数；任一失败只把该卡标为 error。 */
  function panelFetchers(env: number | undefined, monthStart: string, todayStart: string): Record<PanelKey, () => Promise<string>> {
    return {
      model: async () => {
        const deploymentId = await boundChatDeploymentId(env)
        if (deploymentId === null) return ''
        const page = await onboardingApi.publishedDeployments('chat', undefined, 'chat', env)
        const target = page.data.find(item => item.id === deploymentId)
        return target ? deploymentLabel(target) : ''
      },
      cost: async () => summarizeAmount(await usageApi.summary({ from_time: monthStart })),
      calls: async () => {
        const { events, failed } = summarizeEvents(await usageApi.summary({ from_time: todayStart }))
        return failed ? `${events} 次，失败 ${failed} 次` : `${events} 次`
      },
      apps: async () => {
        const apps = await iamApi.listApplications({ page: 1, limit: 3 })
        const names = apps.data.map(item => item.name).filter(Boolean).join('、')
        return names ? (apps.total > apps.data.length ? `${names} 等 ${apps.total} 个` : names) : '暂无'
      }
    }
  }

  /** 单字段读取：重试与整页刷新共用同一入口。 */
  async function settlePanelField(key: PanelKey, generation: number): Promise<void> {
    panelStates.value[key] = 'loading'
    const now = new Date()
    const pad = (value: number): string => String(value).padStart(2, '0')
    const monthStart = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-01 00:00:00`
    const todayStart = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())} 00:00:00`
    const fetchers = panelFetchers(environmentId.value || undefined, monthStart, todayStart)
    try {
      const value = await Promise.race([
        fetchers[key](),
        new Promise<string>((_resolve, reject) => {
          setTimeout(() => reject(new Error('panel timeout')), 10000)
        })
      ])
      if (!live || generation !== loadGeneration) return
      panelValues.value[key] = value
      panelStates.value[key] = 'ready'
    } catch {
      if (!live || generation !== loadGeneration) return
      panelStates.value[key] = 'error'
    }
  }

  async function loadPanel(): Promise<void> {
    const generation = loadGeneration
    await Promise.all((Object.keys(panelStates.value) as PanelKey[]).map(key => settlePanelField(key, generation)))
  }

  function retryPanelField(key: PanelKey): void {
    void settlePanelField(key, loadGeneration)
  }

  /** 「复制问题信息」：只含脱敏错误码、时间、版本与请求编号，不含堆栈或密钥。 */
  async function copyProblemDetails(): Promise<void> {
    if (loadFailure.value === null) return
    copyingProblem.value = true
    try {
      const text = problemInfoText(describeBoundaryFault(loadFailure.value))
      if (navigator.clipboard?.writeText) {
        await navigator.clipboard.writeText(text)
      } else {
        const area = document.createElement('textarea')
        area.value = text
        area.style.position = 'fixed'
        area.style.opacity = '0'
        document.body.appendChild(area)
        area.select()
        document.execCommand('copy')
        area.remove()
      }
      ElMessage.success('已复制问题信息')
    } catch {
      ElMessage.warning('复制未完成，请稍后再试')
    } finally {
      copyingProblem.value = false
    }
  }

  function loadIntent(): void {
    try {
      intent.value = readHomeActivationIntent(adminOwner())
      intentStorageError.value = false
    } catch {
      intentStorageError.value = true
    }
  }

  watch(guideCode, () => {
    const selected = guide.value
    if (!selected) return
    baseUrl.value = selected.default_base_url ?? ''
    connectionOk.value = false
  })

  onMounted(() => {
    ensureBootstrap()
    loadIntent()
    void loadAll()
    if (intent.value) void recoverActivation()
  })
  onBeforeUnmount(() => {
    live = false
    loadGeneration++
    apiKey.value = ''
  })
</script>

<style scoped>
  .home-head {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 16px;
  }

  .home-title {
    margin: 0;
    font-size: 18px;
    font-weight: 600;
    line-height: 1.4;
    color: var(--el-text-color-primary);
  }

  .home-desc {
    margin: 4px 0 0;
    font-size: 13px;
    line-height: 1.5;
    color: var(--el-text-color-secondary);
    max-width: 720px;
  }

  .home-bootstrap {
    margin: 0 0 12px;
    font-size: 13px;
    color: var(--el-color-warning);
  }

  .home-body {
    display: flex;
    flex-direction: column;
    gap: 16px;
  }

  .home-steps {
    display: flex;
    flex-wrap: wrap;
    gap: 12px 24px;
    margin: 0;
    padding: 0;
    list-style: none;
  }

  .home-step {
    display: flex;
    gap: 8px;
    align-items: flex-start;
    min-width: 150px;
    color: var(--el-text-color-secondary);
  }

  .home-step__mark {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 22px;
    height: 22px;
    border-radius: 50%;
    border: 1px solid var(--el-border-color);
    font-size: 12px;
    line-height: 1;
    flex: none;
    margin-top: 2px;
  }

  .home-step.is-done {
    color: var(--el-text-color-primary);
  }

  .home-step.is-done .home-step__mark {
    border-color: var(--el-color-success);
    background: var(--el-color-success);
    color: #fff;
  }

  .home-step__title {
    font-size: 14px;
    font-weight: 600;
    color: var(--el-text-color-primary);
  }

  .home-step__text {
    margin-top: 2px;
    font-size: 12px;
    line-height: 1.5;
  }

  .home-section {
    border-top: 1px solid var(--el-border-color-light);
    padding-top: 16px;
  }

  .home-section h3 {
    margin: 0 0 8px;
    font-size: 15px;
    font-weight: 600;
  }

  .home-muted {
    margin: 0 0 8px;
    font-size: 13px;
    color: var(--el-text-color-secondary);
  }

  .home-guides {
    margin-bottom: 8px;
  }

  .home-models {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 4px;
    margin-bottom: 8px;
  }

  .home-form {
    max-width: 640px;
  }

  .home-target {
    display: flex;
    align-items: center;
    gap: 8px;
  }

  .home-target__select {
    width: 180px;
  }

  .home-ok {
    margin-left: 8px;
    font-size: 13px;
    color: var(--el-color-success);
  }

  .home-link {
    font-size: 13px;
  }

  .home-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 12px;
  }

  .home-card {
    border: 1px solid var(--el-border-color-light);
    border-radius: 8px;
    padding: 12px 14px;
    display: flex;
    flex-direction: column;
    gap: 6px;
    min-height: 84px;
  }

  .home-card__label {
    font-size: 13px;
    color: var(--el-text-color-secondary);
  }

  .home-card__value {
    font-size: 16px;
    font-weight: 600;
    color: var(--el-text-color-primary);
    overflow-wrap: anywhere;
  }

  .home-card__error {
    font-size: 13px;
    color: var(--el-text-color-secondary);
  }

  .home-help {
    margin-top: 4px;
  }

  .home-tech {
    font-size: 12px;
    color: var(--el-text-color-secondary);
  }
</style>
