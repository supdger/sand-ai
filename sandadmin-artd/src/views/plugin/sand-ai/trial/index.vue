<template>
  <SandAiErrorBoundary>
    <div class="sand-ai-page">
      <div class="trial-wrap">
        <h1 class="trial-title">试用</h1>
        <p class="trial-sub">用对话、文档、录音试一下 AI 好不好用</p>

        <!-- 应用列表加载中 -->
        <ElCard v-if="pageState === 'loading'" class="trial-card" shadow="never">
          <ElSkeleton :rows="5" animated />
        </ElCard>

        <!-- 应用列表读取失败 -->
        <ElCard v-else-if="pageState === 'apps-error'" class="trial-card" shadow="never">
          <ElAlert type="error" :closable="false" :title="appsError || '系统出了点问题，请稍后再试'" />
          <div class="trial-card__actions">
            <ElButton type="primary" @click="boot">重新加载</ElButton>
          </div>
        </ElCard>

        <!-- 没有可管理的应用 -->
        <ElCard v-else-if="pageState === 'no-apps'" class="trial-card trial-empty" shadow="never">
          <p class="trial-empty__text">还没有可用的应用。请先在「应用接入」创建应用，再回来试用。</p>
          <ElButton type="primary" @click="goApplications">去应用接入</ElButton>
        </ElCard>

        <template v-else>
          <!-- 应用上下文：多个可管理应用时提供选择，恰好一个自动使用 -->
          <div v-if="applications.length > 1" class="trial-target">
            <span class="trial-target__label">用哪个应用</span>
            <ElSelect v-model="applicationId" class="trial-target__select">
              <ElOption v-for="app in applications" :key="app.id" :value="app.id" :label="app.name" />
            </ElSelect>
          </div>

          <ElTabs v-model="activeTab" class="trial-tabs">
            <ElTabPane label="对话" name="chat">
          <!-- 试用选项加载中：骨架屏，不闪「读取中」字样 -->
          <ElCard v-if="optionsState === 'loading'" class="trial-card" shadow="never">
            <ElSkeleton :rows="5" animated />
          </ElCard>

          <!-- 试用选项读取失败 -->
          <ElCard v-else-if="optionsState === 'error'" class="trial-card" shadow="never">
            <ElAlert type="error" :closable="false" :title="optionsError || '系统出了点问题，请稍后再试'" />
            <div class="trial-card__actions">
              <ElButton type="primary" @click="reloadOptions">重新加载</ElButton>
            </div>
          </ElCard>

          <!-- 无可用服务商：不显示输入框，统一「去连接」引导 -->
          <ElCard v-else-if="providers.length === 0" class="trial-card trial-empty" shadow="never">
            <p class="trial-empty__text">还没有可用的 AI 服务商，请先去连接</p>
            <p class="trial-empty__sub">连好后回到这里，写一句话点“发送”就能试。</p>
            <ElButton type="primary" @click="goProvider">去连接</ElButton>
          </ElCard>

          <template v-else>
            <ElCard class="trial-card" shadow="never">
              <!-- 服务商区：1 个只显示灰字，多个出下拉（默认第一个可用，可清空后回到第一个） -->
              <div v-if="providers.length === 1" class="trial-target">
                <span class="trial-muted">使用：{{ providers[0]?.name }}</span>
              </div>
              <div v-else class="trial-target">
                <span class="trial-target__label">用哪个服务商</span>
                <ElSelect v-model="selectedProviderId" class="trial-target__select" clearable @change="onProviderPick">
                  <ElOption v-for="provider in providers" :key="provider.id" :value="provider.id" :label="provider.name" />
                </ElSelect>
              </div>

              <ElAlert
                v-if="!canChat"
                type="info"
                :closable="false"
                class="trial-readonly"
                title="你只能查看，不能修改。请找管理员开通试用对话权限。"
              />

              <ElInput
                v-model="prompt"
                class="trial-input"
                type="textarea"
                :autosize="{ minRows: 2, maxRows: 6 }"
                :maxlength="2000"
                show-word-limit
                :disabled="sending || !canChat"
                placeholder="写一句话，比如：帮我把这段话改得更礼貌"
                @keydown="onPromptKeydown"
              />

              <div class="trial-actions">
                <span class="trial-hint">Enter 发送，Shift+Enter 换行</span>
                <div class="trial-actions__buttons">
                  <ElButton v-if="answer" link type="primary" :disabled="sending" @click="copyAnswer">复制回复</ElButton>
                  <ElButton v-if="sending" link type="primary" @click="stopWaiting">停止</ElButton>
                  <ElButton
                    :type="primarySend ? 'primary' : 'default'"
                    :loading="sending"
                    :disabled="!canSend"
                    @click="send()"
                  >{{ sending ? '发送中' : '发送' }}</ElButton>
                </div>
              </div>

              <ElCollapse class="trial-advanced">
                <ElCollapseItem title="高级设置（一般不用改）" name="advanced">
                  <div class="trial-advanced__grid">
                    <div class="trial-field">
                      <span class="trial-field__label">模型</span>
                      <ElSelect
                        v-model="modelId"
                        class="trial-field__control"
                        clearable
                        placeholder="默认"
                        :disabled="!selectedProvider"
                      >
                        <ElOption
                          v-for="model in selectedProvider?.models ?? []"
                          :key="model.id"
                          :value="model.id"
                          :label="model.name"
                        />
                      </ElSelect>
                    </div>
                    <div class="trial-field">
                      <span class="trial-field__label">回复长度</span>
                      <ElInputNumber
                        v-model="maxTokens"
                        class="trial-field__control"
                        :min="1"
                        :max="131072"
                        :precision="0"
                        :controls="false"
                        placeholder="默认"
                      />
                    </div>
                  </div>
                </ElCollapseItem>
              </ElCollapse>
            </ElCard>

            <!-- 发送中：仅停止等待，不代表服务端已取消 -->
            <ElCard v-if="sending" class="trial-card" shadow="never">
              <div class="trial-wait">
                <ElIcon class="is-loading" :size="22"><Loading /></ElIcon>
                <span>正在等 AI 回复……</span>
              </div>
            </ElCard>

            <!-- 回复卡 -->
            <ElCard v-if="answer" class="trial-card" shadow="never">
              <div class="trial-answer">{{ answer.content }}</div>
              <div class="trial-reply__meta">
                由 {{ answer.providerName }} 回复<template v-if="answer.latencyText !== ''"> · 用时 {{ answer.latencyText }} 秒</template>
              </div>
            </ElCard>

            <!-- 服务商连不上：主按钮去模型服务商页，发送降为次级 -->
            <ElAlert v-if="outcome === 'fail'" type="warning" :closable="false" class="trial-notice">
              <div class="trial-notice__title">{{ outcomeMessage }}</div>
              <div class="trial-notice__actions">
                <ElButton type="primary" @click="goProvider">去模型服务商</ElButton>
              </div>
            </ElAlert>

            <!-- 服务商繁忙或本地等待超时：白话提示 + 同幂等键重试 -->
            <ElAlert v-else-if="outcome === 'busy' || outcome === 'neterr'" type="warning" :closable="false" class="trial-notice">
              <div class="trial-notice__title">{{ outcomeMessage }}</div>
              <div class="trial-notice__actions">
                <ElButton :loading="sending" @click="resend">重试</ElButton>
              </div>
            </ElAlert>

            <!-- 系统故障：固定文案 + 复制问题信息 + 重新发送（同幂等键同载荷） -->
            <ElAlert v-else-if="outcome === 'syserr'" type="error" :closable="false" class="trial-notice">
              <div class="trial-notice__title">系统出了点问题，请稍后再试</div>
              <div class="trial-notice__actions">
                <ElButton :loading="copying" @click="copyProblemDetails">复制问题信息</ElButton>
                <ElButton v-if="retryAttempt" link type="primary" @click="resend">重新发送</ElButton>
              </div>
            </ElAlert>

            <!-- 其它业务提示：后端白话原因原文，预算/配置类附带跳转按钮 -->
            <ElAlert
              v-else-if="outcome === 'business'"
              type="warning"
              :closable="false"
              class="trial-notice"
            >
              <div class="trial-notice__title">{{ outcomeMessage }}</div>
              <div v-if="noticeAction !== ''" class="trial-notice__actions">
                <ElButton v-if="noticeAction === 'provider'" @click="goProvider">去模型服务商</ElButton>
                <ElButton v-else @click="goUsage">去费用页</ElButton>
              </div>
            </ElAlert>

            <!-- 已停止等待：语义诚实，不宣称服务端已取消 -->
            <ElAlert
              v-else-if="outcome === 'stopped'"
              type="info"
              :closable="false"
              class="trial-notice"
              title="已停止等待；这次请求可能仍在后台完成，结果请在调用记录中查看。"
            />
          </template>
            </ElTabPane>
            <!-- 后端尚未就绪：页签禁用，内容只留占位说明 -->
            <ElTabPane label="文档整理（即将提供）" name="document" disabled>
              <p class="trial-muted">即将提供</p>
            </ElTabPane>
            <ElTabPane label="录音转写（即将提供）" name="speech" disabled>
              <p class="trial-muted">即将提供</p>
            </ElTabPane>
          </ElTabs>
        </template>
      </div>
    </div>
  </SandAiErrorBoundary>
</template>

<script setup lang="ts">
  import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
  import { useRouter } from 'vue-router'
  import { ElMessage } from 'element-plus'
  import { Loading } from '@element-plus/icons-vue'
  import { useAuth } from '@/hooks/core/useAuth'
  import { isHttpError } from '@/utils/http/error'
  import SandAiErrorBoundary from '../components/SandAiErrorBoundary.vue'
  import '../components/sandAiPage.css'
  import iamApi, { type SandIamApplicationReference } from '../api/iam'
  import trialApi, {
    type TrialChatInput,
    type TrialOptions,
    type TrialProviderOption
  } from '../api/trial'
  import { SAND_AI_PLUGIN_VERSION } from '../api/config'
  import { hintForRuntimeErrorCode } from '../api/runtimeErrors'
  import { describeBoundaryFault } from '../composables/errorBoundary'

  const router = useRouter()
  const { hasAuth } = useAuth()

  type PageState = 'loading' | 'apps-error' | 'no-apps' | 'ready'
  type OptionsState = 'loading' | 'ready' | 'error'
  type Outcome = 'idle' | 'stopped' | 'business' | 'fail' | 'busy' | 'neterr' | 'syserr'
  /** 业务提示可附带的跳转动作：费用页或模型服务商页。 */
  type NoticeAction = '' | 'provider' | 'usage'
  interface RetryAttempt {
    readonly key: string
    readonly payload: TrialChatInput
    readonly providerName: string
  }
  interface TrialAnswer {
    readonly content: string
    readonly providerName: string
    readonly latencyText: string
  }

  const pageState = ref<PageState>('loading')
  const appsError = ref('')
  const applications = ref<SandIamApplicationReference[]>([])
  const applicationId = ref<number | null>(null)

  const optionsState = ref<OptionsState>('loading')
  const optionsError = ref('')
  const options = ref<TrialOptions | null>(null)

  const activeTab = ref<string>('chat')
  const prompt = ref('')
  const selectedProviderId = ref<number | null>(null)
  const modelId = ref<number | null>(null)
  const maxTokens = ref<number | null>(null)

  const sending = ref(false)
  const answer = ref<TrialAnswer | null>(null)
  const outcome = ref<Outcome>('idle')
  const outcomeMessage = ref('')
  const noticeAction = ref<NoticeAction>('')
  const retryAttempt = ref<RetryAttempt | null>(null)
  const lastChatError = ref<unknown>(null)
  const copying = ref(false)

  let alive = true
  let optionsGeneration = 0
  let abortController: AbortController | null = null

  const canChat = computed(() => hasAuth('sand_ai:trial:chat'))
  const providers = computed<TrialProviderOption[]>(() => normalizeProviders(options.value?.providers ?? []))
  const selectedProvider = computed<TrialProviderOption | null>(
    () => providers.value.find((provider) => provider.id === selectedProviderId.value) ?? null
  )
  const canSend = computed(
    () =>
      canChat.value &&
      !sending.value &&
      optionsState.value === 'ready' &&
      selectedProvider.value !== null &&
      applicationId.value !== null
  )
  // fail/syserr 态主按钮让位给横幅内的去模型服务商/复制问题信息，发送降为次级
  const primarySend = computed(() => outcome.value !== 'fail' && outcome.value !== 'syserr')

  watch(applicationId, (id) => {
    if (pageState.value === 'ready' && id !== null) void loadOptions(id)
  })

  onMounted(() => {
    void boot()
  })

  onBeforeUnmount(() => {
    alive = false
    abortController?.abort()
  })

  // 应用与选项加载

  async function boot(): Promise<void> {
    pageState.value = 'loading'
    appsError.value = ''
    try {
      let apps = await iamApi.activeApplications()
      if (!alive) return
      if (apps.length === 0) {
        // 与首页一致：首次使用幂等补齐默认组织/应用/环境；失败不阻断引导态
        try {
          await iamApi.ensureDefaultAccess()
        } catch {
          // best-effort
        }
        apps = await iamApi.activeApplications()
        if (!alive) return
      }
      applications.value = apps
      if (apps.length === 0) {
        pageState.value = 'no-apps'
        return
      }
      pageState.value = 'ready'
      if (applicationId.value === null || !apps.some((app) => app.id === applicationId.value)) {
        applicationId.value = apps[0].id
      } else {
        void loadOptions(applicationId.value)
      }
    } catch (error) {
      if (!alive) return
      pageState.value = 'apps-error'
      appsError.value = chineseErrorText(error, '系统出了点问题，请稍后再试')
    }
  }

  async function loadOptions(appId: number): Promise<void> {
    const generation = ++optionsGeneration
    optionsState.value = 'loading'
    optionsError.value = ''
    options.value = null
    clearConversation()
    try {
      const result = await trialApi.trialOptions(appId)
      if (!alive || generation !== optionsGeneration) return
      options.value = result
      optionsState.value = 'ready'
      const fallback = Number.isSafeInteger(result.default_max_output_tokens) && result.default_max_output_tokens > 0
        ? result.default_max_output_tokens
        : null
      maxTokens.value = fallback
      const list = providers.value
      if (!list.some((provider) => provider.id === selectedProviderId.value)) {
        selectedProviderId.value = list[0]?.id ?? null
      }
      syncModelSelection()
    } catch (error) {
      if (!alive || generation !== optionsGeneration) return
      optionsState.value = 'error'
      optionsError.value = chineseErrorText(error, '系统出了点问题，请稍后再试')
    }
  }

  function reloadOptions(): void {
    if (applicationId.value !== null) void loadOptions(applicationId.value)
  }

  // 选项规整与选择

  function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value)
  }

  /** 后端投影可能混入空名称；前端只保留有中文展示名的服务商/模型，绝不回退代码。 */
  function normalizeProviders(raw: TrialProviderOption[]): TrialProviderOption[] {
    if (!Array.isArray(raw)) return []
    const normalized: TrialProviderOption[] = []
    for (const item of raw) {
      if (!isRecord(item)) continue
      const id = item.id
      const name = item.name
      if (typeof id !== 'number' || !Number.isSafeInteger(id) || id < 1) continue
      if (typeof name !== 'string' || name.trim() === '') continue
      const models = Array.isArray(item.models)
        ? item.models
            .filter(
              (model) =>
                isRecord(model) &&
                typeof model.id === 'number' &&
                Number.isSafeInteger(model.id) &&
                model.id > 0 &&
                typeof model.name === 'string' &&
                model.name.trim() !== ''
            )
            .map((model) => ({ id: model.id as number, name: (model.name as string).trim() }))
        : []
      normalized.push({
        id,
        name: name.trim(),
        models,
        default_model_id: typeof item.default_model_id === 'number' ? item.default_model_id : 0
      })
    }
    return normalized
  }

  function syncModelSelection(): void {
    const provider = selectedProvider.value
    if (provider === null) {
      modelId.value = null
      return
    }
    const ids = new Set(provider.models.map((model) => model.id))
    if (modelId.value === null || !ids.has(modelId.value)) {
      modelId.value =
        provider.default_model_id > 0 && ids.has(provider.default_model_id)
          ? provider.default_model_id
          : (provider.models[0]?.id ?? null)
    }
  }

  function onProviderPick(): void {
    // 服务商下拉可清空：清空瞬间回到第一个可用服务商，保证总有可发的选项
    if (typeof selectedProviderId.value !== 'number') {
      selectedProviderId.value = providers.value[0]?.id ?? null
    }
    clearConversation()
    syncModelSelection()
  }

  // 发送 / 停止 / 重发

  function onPromptKeydown(event: Event | KeyboardEvent): void {
    // ElInput 的 keydown 载荷类型为 Event | KeyboardEvent，先收窄再读键盘字段
    if (!(event instanceof KeyboardEvent)) return
    if (event.key !== 'Enter' || event.shiftKey) return
    // 中文输入法组词中的 Enter（确认候选词）不触发发送
    if (event.isComposing || event.keyCode === 229) return
    event.preventDefault()
    void send()
  }

  function buildPayload(provider: TrialProviderOption): TrialChatInput | null {
    const appId = applicationId.value
    const message = prompt.value.trim()
    if (appId === null || message === '') return null
    const payload: {
      application_id: number
      provider_id: number
      message: string
      model_id?: number
      max_output_tokens?: number
    } = { application_id: appId, provider_id: provider.id, message }
    if (modelId.value !== null && provider.models.some((model) => model.id === modelId.value)) {
      payload.model_id = modelId.value
    }
    if (maxTokens.value !== null && maxTokens.value > 0) {
      payload.max_output_tokens = maxTokens.value
    }
    return payload
  }

  async function send(override?: RetryAttempt): Promise<void> {
    const provider = selectedProvider.value
    if (sending.value || !canChat.value || provider === null || optionsState.value !== 'ready') return
    const payload = override?.payload ?? buildPayload(provider)
    if (payload === null) {
      // 空输入不静默吞掉：点发送/回车都明确提示先写一句话（override 必有载荷，不会走到这里）
      ElMessage.warning('请先写一句话再发送')
      return
    }
    // 每次用户主动发送生成新键；override 只来自失败态的「重试/重新发送」，
    // 复用同键同载荷做不确定重试，绝不换键重发。
    const key = override?.key ?? newIdempotencyKey()
    const attemptProviderName = override?.providerName ?? provider.name
    retryAttempt.value = null
    const controller = new AbortController()
    abortController = controller
    sending.value = true
    clearConversation()
    try {
      const result = await trialApi.trialChat(payload, key, controller.signal)
      if (!alive) return
      if (typeof result.content !== 'string' || result.content.trim() === '') {
        outcome.value = 'business'
        outcomeMessage.value = '这次试用没有返回可显示的回复，请稍后重试'
        return
      }
      answer.value = {
        content: result.content,
        providerName:
          typeof result.provider?.name === 'string' && result.provider.name !== ''
            ? result.provider.name
            : provider.name,
        latencyText: formatLatency(typeof result.latency_ms === 'number' ? result.latency_ms : 0)
      }
    } catch (error) {
      if (!alive) return
      lastChatError.value = error
      if (controller.signal.aborted) {
        outcome.value = 'stopped'
      } else {
        classifyChatError(error, key, payload, attemptProviderName)
      }
    } finally {
      sending.value = false
      if (abortController === controller) abortController = null
    }
  }

  function resend(): void {
    const attempt = retryAttempt.value
    if (attempt !== null) void send(attempt)
  }

  function stopWaiting(): void {
    // 只停止本地等待；不宣称服务端已取消或费用已退。
    abortController?.abort()
  }

  function clearConversation(): void {
    answer.value = null
    outcome.value = 'idle'
    outcomeMessage.value = ''
    noticeAction.value = ''
    retryAttempt.value = null
    lastChatError.value = null
  }

  // 错误分类与文案

  /**
   * 试用失败分类：优先读后端脱敏的 data.reason_code（业务信封 transport 为空时
   * error.data 即业务负载），再退回传输层特征，最后才是通用业务文案。
   * 界面只展示白话中文，错误码/请求号只进 console 与「复制问题信息」。
   */
  function classifyChatError(error: unknown, key: string, payload: TrialChatInput, providerName: string): void {
    const reason = trialReasonCode(error)
    if (reason === 'provider_unreachable' || (reason === '' && isProviderUnreachable(error))) {
      // 服务商连不上：后端文案已含「去模型服务商点测试连接」指引
      outcome.value = 'fail'
      outcomeMessage.value = chineseErrorText(error, '连不上模型服务商，请到「模型服务商」点「测试连接」看看。')
      return
    }
    if (reason === 'provider_busy' || (isHttpError(error) && error.transport === 'timeout')) {
      // 服务商繁忙或本地 150s 等待超时：结果不确定，同键同载荷可安全重试
      outcome.value = 'busy'
      outcomeMessage.value = reason === 'provider_busy'
        ? chineseErrorText(error, 'AI 服务商这会儿太忙，请过一会儿再试')
        : 'AI 服务商这会儿太忙，请过一会儿再试'
      retryAttempt.value = { key, payload, providerName }
      return
    }
    if (reason === 'budget_exhausted' || reason === 'budget_blocked') {
      outcome.value = 'business'
      outcomeMessage.value = chineseErrorText(error, '本月 AI 预算已经用完，请到费用页调高预算')
      noticeAction.value = 'usage'
      return
    }
    if (reason === 'provider_config_invalid') {
      outcome.value = 'business'
      outcomeMessage.value = chineseErrorText(error, '服务商配置有问题，请到「模型服务商」页检查密钥和地址后再试')
      noticeAction.value = 'provider'
      return
    }
    if (reason !== '') {
      // egress_blocked / routes_not_published / balance / unknown / idempotency_required 等：
      // 后端白话原文即最终文案，未知原因统一落到「这次试用没有成功，请稍后重试」。
      outcome.value = 'business'
      outcomeMessage.value = chineseErrorText(error, '这次试用没有成功，请稍后重试')
      return
    }
    if (isNetworkFailure(error)) {
      outcome.value = 'neterr'
      outcomeMessage.value = '网络好像断了，请检查网络后再试'
      retryAttempt.value = { key, payload, providerName }
      return
    }
    if (isSystemFailure(error)) {
      outcome.value = 'syserr'
      retryAttempt.value = { key, payload, providerName }
      return
    }
    outcome.value = 'business'
    outcomeMessage.value = chineseErrorText(error, '这次试用没有成功，请稍后重试')
  }

  function rawErrorMessage(error: unknown): string {
    if (error instanceof Error && typeof error.message === 'string') return error.message
    return ''
  }

  function stripCodePrefix(text: string): string {
    return text.replace(/^(?:SAND_AI|SAND_IAM)_[A-Z0-9_]+:\s*/, '').trim()
  }

  /** 取后端白话原因：优先信封 data.message（真实 HTTP 错误），否则 HttpError.message。 */
  function backendMessage(error: unknown): string {
    if (isHttpError(error) && error.transport === 'http' && isRecord(error.data)) {
      const message = error.data.message
      if (typeof message === 'string' && /[一-鿿]/.test(message)) return message.trim()
    }
    return rawErrorMessage(error)
  }

  /** 业务错误原样显示（去掉稳定码前缀）；非中文时回退冻结码中文映射，再兜底固定中文，不上英文代码。 */
  function chineseErrorText(error: unknown, fallback: string): string {
    const text = stripCodePrefix(backendMessage(error))
    if (/[一-鿿]/.test(text)) return text
    const code = /^(?:SAND_AI|SAND_IAM)_[A-Z0-9_]+/.exec(rawErrorMessage(error))?.[0]
    return hintForRuntimeErrorCode(code) ?? fallback
  }

  /** 业务信封（transport 为空）的 data 即后端负载；只认严格 snake_case 的 reason_code。 */
  function trialReasonCode(error: unknown): string {
    if (!isHttpError(error) || error.transport !== undefined || !isRecord(error.data)) return ''
    const code = error.data.reason_code
    return typeof code === 'string' && /^[a-z][a-z0-9_]{0,63}$/.test(code) ? code : ''
  }

  /** 断网类传输失败：请求可能根本没到服务端，同键重试安全。 */
  function isNetworkFailure(error: unknown): boolean {
    return isHttpError(error) && (error.transport === 'network' || error.transport === 'unknown')
  }

  /** trialProblem 把服务商不可达映射为业务码 502；仅业务信封（transport 空）可判，真实 HTTP/传输层 502 归系统故障。 */
  function isProviderUnreachable(error: unknown): boolean {
    if (!isHttpError(error) || error.transport !== undefined) return false
    if (error.code === 502) return true
    return rawErrorMessage(error).startsWith('连不上')
  }

  function isSystemFailure(error: unknown): boolean {
    if (!isHttpError(error)) return true
    if (error.transport !== undefined && error.transport !== 'http') return true
    return error.code >= 500
  }

  function formatLatency(ms: number): string {
    if (!Number.isFinite(ms) || ms <= 0) return ''
    const seconds = ms / 1000
    return seconds >= 10 ? String(Math.round(seconds)) : String(Math.round(seconds * 10) / 10)
  }

  function newIdempotencyKey(): string {
    const cryptoApi = globalThis.crypto as Crypto | undefined
    if (cryptoApi && typeof cryptoApi.randomUUID === 'function') return cryptoApi.randomUUID()
    return `trial-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}`
  }

  // 复制

  async function copyText(text: string, successMessage: string): Promise<void> {
    copying.value = true
    try {
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
      ElMessage.success(successMessage)
    } catch (error) {
      console.error('[sand-ai] trial copy failed', error)
      ElMessage.warning('复制未完成，请稍后重试')
    } finally {
      copying.value = false
    }
  }

  function copyAnswer(): void {
    if (answer.value === null) return
    void copyText(answer.value.content, '已复制')
  }

  /** 「复制问题信息」只含时间/页面/错误码/请求号（如可得），不上屏。 */
  function copyProblemDetails(): void {
    const error = lastChatError.value
    const fault = describeBoundaryFault(error)
    const lines = [
      'SandAI 管理页问题信息',
      '页面：/sand-ai/trial',
      `错误码：${fault.code}`,
      `时间：${fault.time}`,
      `版本：${SAND_AI_PLUGIN_VERSION}`
    ]
    if (fault.requestId !== undefined) lines.push(`请求编号：${fault.requestId}`)
    void copyText(lines.join('\n'), '已复制，可发给管理员')
  }

  // 跳转

  function goProvider(): void {
    void router.push('/sand-ai/provider')
  }

  function goUsage(): void {
    void router.push('/sand-ai/usage')
  }

  function goApplications(): void {
    void router.push('/sand-iam/application')
  }
</script>

<style scoped>
  .trial-wrap {
    max-width: 760px;
    margin: 0 auto;
  }

  .trial-title {
    margin: 0 0 4px;
    font-size: 22px;
    line-height: 1.4;
    color: var(--el-text-color-primary);
  }

  .trial-sub {
    margin: 0 0 20px;
    font-size: 14px;
    color: var(--el-text-color-secondary);
  }

  .trial-card {
    margin-bottom: 16px;
  }

  .trial-card__actions {
    margin-top: 12px;
  }

  .trial-target {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 14px;
    font-size: 14px;
    color: var(--el-text-color-primary);
  }

  .trial-target__label {
    color: var(--el-text-color-secondary);
    white-space: nowrap;
  }

  .trial-target__select {
    flex: 1;
    min-width: 0;
    max-width: 320px;
  }

  .trial-muted {
    font-size: 14px;
    color: var(--el-text-color-secondary);
  }

  .trial-readonly {
    margin-bottom: 12px;
  }

  .trial-input {
    width: 100%;
  }

  .trial-actions {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    margin-top: 12px;
  }

  .trial-hint {
    font-size: 13px;
    color: var(--el-text-color-secondary);
  }

  .trial-actions__buttons {
    display: flex;
    align-items: center;
    gap: 8px;
  }

  .trial-advanced {
    margin-top: 12px;
    border-top: 1px solid var(--el-border-color-lighter);
  }

  .trial-advanced__grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px 24px;
    padding-top: 12px;
  }

  .trial-field {
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 0;
  }

  .trial-field__label {
    font-size: 14px;
    color: var(--el-text-color-secondary);
    white-space: nowrap;
  }

  .trial-field__control {
    flex: 1;
    min-width: 0;
    max-width: 260px;
  }

  .trial-wait {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
    padding: 28px 0;
    font-size: 14px;
    color: var(--el-text-color-secondary);
  }

  .trial-answer {
    font-size: 14px;
    line-height: 1.7;
    color: var(--el-text-color-primary);
    white-space: pre-wrap;
    overflow-wrap: anywhere;
  }

  .trial-reply__meta {
    margin-top: 10px;
    font-size: 13px;
    color: var(--el-text-color-secondary);
  }

  .trial-notice__title {
    font-size: 14px;
    font-weight: 600;
  }

  .trial-notice__text {
    margin-top: 4px;
    font-size: 14px;
    line-height: 1.7;
    overflow-wrap: anywhere;
  }

  .trial-notice__actions {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
    margin-top: 12px;
  }

  .trial-empty {
    padding: 40px 24px;
    text-align: center;
  }

  .trial-empty__text {
    margin: 0 0 6px;
    font-size: 15px;
    color: var(--el-text-color-primary);
  }

  .trial-empty__sub {
    margin: 0 0 20px;
    font-size: 13px;
    color: var(--el-text-color-secondary);
  }

  @media (max-width: 560px) {
    .trial-advanced__grid {
      grid-template-columns: 1fr;
    }
  }
</style>
