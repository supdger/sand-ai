<template>
  <section ref="trialPanel" class="chat-trial">
    <h3>聊天路线试用</h3>
    <p class="chat-trial__hint">
      使用 SandIAM 工作负载 Bearer 调用，环境由该凭证决定。上方管理端环境选择不会授予调用权限。
      需要 sand_ai.model.read、sand_ai.chat.complete；结果找回和费用读取分别需要
      sand_ai.invocation.read、sand_ai.usage.read。
    </p>
    <ElForm label-width="120px" @submit.prevent="submit">
      <RuntimeApiKeyField v-model="bearer" :persist-in-session="false" placeholder="填写 SandIAM 签发的 Bearer（仅本页面）" />
      <ElFormItem label="已发布模型">
        <ElButton :disabled="!bearer.trim() || Boolean(busy)" :loading="busy === 'models'" @click="loadModels">
          读取当前凭证的模型
        </ElButton>
      </ElFormItem>
      <ElFormItem label="选择方式">
        <ElRadioGroup v-model="mode" :disabled="locked">
          <ElRadio value="fixed">固定模型</ElRadio>
          <ElRadio value="lowest">按最低成本</ElRadio>
        </ElRadioGroup>
      </ElFormItem>
      <ElFormItem v-if="mode === 'fixed'" label="模型">
        <ElSelect v-model="modelCode" :disabled="locked" placeholder="先读取模型，再选择" class="chat-trial__select">
          <ElOption v-for="model in models" :key="model.id" :value="model.code" :label="`${model.name} (${model.code})`" />
        </ElSelect>
      </ElFormItem>
      <ElAlert v-if="mode === 'lowest'" type="info" :closable="false"
        title="仅比较当前凭证环境已发布的聊天路线。服务端核对容量、有效价格与同币种预算；币种不同或没有合资格路线时拒绝调用。目录没有价格字段，发送前本页无法取得预算上界；调用后可读取本次冻结的预算上界记录。" />
      <ElFormItem label="最大输出 token">
        <ElInputNumber v-model="maxOutputTokens" :min="1" :max="131072" :precision="0" :disabled="locked" />
      </ElFormItem>
      <ElFormItem label="消息">
        <ElInput v-model="prompt" type="textarea" :rows="4" :disabled="locked" placeholder="填写本次聊天消息" />
      </ElFormItem>
      <ElFormItem>
        <ElButton type="primary" native-type="submit" :disabled="!ready" :loading="busy === 'send'">
          发送一次聊天（可能产生费用）
        </ElButton>
      </ElFormItem>
    </ElForm>
    <ElAlert v-if="error" type="error" :title="error" :closable="false" />
    <div v-if="failureGuidance" class="chat-trial__recovery">
      <p v-if="!error">{{ failureGuidance.message }}</p>
      <ElButton v-if="(failureGuidance.target === 'budget' || failureGuidance.target === 'route-and-budget') && canManageBudget"
        :disabled="Boolean(busy)" @click="router.push('/sand-ai/usage')">
        打开费用页核对调用预算
      </ElButton>
      <ElButton v-if="failureGuidance.target === 'route' || failureGuidance.target === 'route-and-budget'"
        :disabled="Boolean(busy)" @click="inspectRouteConfiguration">
        核对本页能力路线
      </ElButton>
      <p v-if="failureGuidance.target === 'route' || failureGuidance.target === 'route-and-budget'" class="chat-trial__hint">
        请核对原调用凭证所属应用和环境的已发布路线、模型容量与有效价格；
        当前管理端环境选择不会改变该凭证的调用范围。
      </p>
      <p v-if="(failureGuidance.target === 'budget' || failureGuidance.target === 'route-and-budget') && !canManageBudget" class="chat-trial__hint">
        请有预算管理权限的管理员在“调用费用 → 调用预算”核对匹配范围、额度和币种。
      </p>
    </div>
    <div v-if="pendingKey" class="chat-trial__recovery">
      <p>原请求键：<code>{{ pendingKey }}</code></p>
      <p>请求结果尚未确认。仅查询原请求，不会重发；请使用原调用身份的 Bearer。404 或无权限不能证明原请求未执行。</p>
      <p v-if="invocation?.state === 'failed'">调用失败不代表未发送或无费用。原请求保持锁定，请只读核对调用与费用记录。</p>
      <ElButton :disabled="!bearer.trim() || Boolean(busy)" :loading="busy === 'recover'" @click="recover">
        只读找回原请求
      </ElButton>
    </div>
    <ElDescriptions v-if="invocation" title="调用状态" :column="1" border>
      <ElDescriptionsItem label="请求 ID">{{ invocation.request_id }}</ElDescriptionsItem>
      <ElDescriptionsItem label="状态">{{ invocation.state }}</ElDescriptionsItem>
      <ElDescriptionsItem label="耗时">{{ invocation.latency_ms === null ? '未知' : `${invocation.latency_ms} ms` }}</ElDescriptionsItem>
      <ElDescriptionsItem label="错误码">{{ invocation.error_code ?? '无' }}</ElDescriptionsItem>
      <ElDescriptionsItem v-if="selectedModel" label="本次选中模型">{{ selectedModel }}</ElDescriptionsItem>
    </ElDescriptions>
    <div v-if="answer !== null" class="chat-trial__answer">
      <h4>回答</h4>
      <pre>{{ answer }}</pre>
    </div>
    <p v-if="recovered">只读找回提供调用摘要；该入口不返回原回答。实际模型可在有权限时读取费用记录确认。</p>
    <ElButton v-if="invocation" :disabled="!bearer.trim() || Boolean(busy)" :loading="busy === 'cost'" @click="loadCost">
      读取本次费用
    </ElButton>
    <ElAlert v-if="costError" type="warning" :title="costError" :closable="false" />
    <ElDescriptions v-if="cost" title="费用记录" :column="1" border>
      <ElDescriptionsItem label="模型">{{ cost.model ?? '未知' }}</ElDescriptionsItem>
      <ElDescriptionsItem label="价格修订">{{ cost.price.revision_id ?? '未知' }}</ElDescriptionsItem>
      <ElDescriptionsItem label="计量状态">{{ cost.usage.state }}</ElDescriptionsItem>
      <ElDescriptionsItem label="价格状态">{{ cost.price.pricing_state }}</ElDescriptionsItem>
      <ElDescriptionsItem label="调用前预算上界">{{ estimateView.amount }}</ElDescriptionsItem>
      <ElDescriptionsItem label="预算报价价格版本">{{ estimateView.revision }}</ElDescriptionsItem>
      <ElDescriptionsItem label="计量费用">{{ amount(cost.price.metered_amount, cost.price.currency) }}</ElDescriptionsItem>
    </ElDescriptions>
    <p v-if="cost" class="chat-trial__hint">
      调用前预算上界仅作预算预占，不代表实际 Token 预测、计量费用或已付现金；
      采用最小预占时金额可能为 0.000001。已核对账单须另行查看，未知金额不按零处理。
    </p>
    <ElButton v-if="terminal" :disabled="Boolean(busy)" @click="newCall">
      准备新的调用（再次发送可能产生费用）
    </ElButton>
  </section>
</template>

<script setup lang="ts">
  import { computed, onBeforeUnmount, ref, watch } from 'vue'
  import { useRouter } from 'vue-router'
  import { useAuth } from '@/hooks/core/useAuth'
  import chatApi, { type ChatInvocation, type ChatModel, type ChatPayload } from '../api/chat'
  import type { SandAiInvocationCost } from '../api/types'
  import { newRuntimeRequestId, SandAiRuntimeError } from '../api/runtime'
  import RuntimeApiKeyField from './RuntimeApiKeyField.vue'

  const props = defineProps<{ environmentId?: number }>()
  const router = useRouter()
  const { hasAuth } = useAuth()
  const trialPanel = ref<HTMLElement | null>(null)
  const canManageBudget = computed(() => hasAuth('sand_ai:budget:index'))
  // Only the non-secret caller key survives reload; Bearer and messages never enter storage.
  const storageKey = 'sand-ai:chat-trial:pending-request-key'
  function storedKey(): string {
    try { return sessionStorage.getItem(storageKey) ?? '' } catch { return '' }
  }
  const bearer = ref('')
  const pendingKey = ref(storedKey())
  const mode = ref<'fixed' | 'lowest'>('fixed')
  const models = ref<ChatModel[]>([])
  const modelCode = ref('')
  const maxOutputTokens = ref<number | undefined>(1024)
  const prompt = ref('')
  const busy = ref<'' | 'models' | 'send' | 'recover' | 'cost'>('')
  const error = ref('')
  const failureCode = ref<string | null>(null)
  const costError = ref('')
  const invocation = ref<ChatInvocation | null>(null)
  const answer = ref<string | null>(null)
  const selectedModel = ref<string | null>(null)
  const cost = ref<SandAiInvocationCost | null>(null)
  const recovered = ref(false)
  const terminal = ref(false)
  let generation = 0
  type FailureGuidance = { message: string; target: 'budget' | 'route' | 'route-and-budget' | 'record' }
  const failureGuidanceByCode: Readonly<Record<string, FailureGuidance>> = {
    SAND_AI_BUDGET_EXCEEDED: { message: '硬预算可用额度不足。请管理员核对全部匹配预算的额度和占用；软预算允许超额，但其他匹配硬预算仍会限制调用。', target: 'budget' },
    SAND_AI_BUDGET_UNPRICED: { message: '本次没有可验证的冻结价格上界；软预算和硬预算都不能放行未定价调用。请核对发布模型的有效价格。', target: 'route' },
    SAND_AI_BUDGET_CURRENCY_MISMATCH: { message: '调用价格与匹配预算的币种不一致，不能相加或自动换算。请管理员核对预算币种。', target: 'budget' },
    SAND_AI_LOW_COST_CURRENCY_MISMATCH: { message: '合资格最低成本候选使用不同币种，服务端无法直接比较。请管理员核对已发布路线的价格币种。', target: 'route' },
    SAND_AI_LOW_COST_ROUTE_UNAVAILABLE: { message: '没有同时满足发布状态、容量、有效价格和当前预算的聊天路线。请管理员逐项核对；最低成本选择不会绕过硬预算。', target: 'route-and-budget' },
    SAND_AI_LOW_COST_ROUTE_CHANGED: { message: '所选最低成本路线、冻结请求或价格版本在准备期间发生变化。请管理员核对发布路线和原调用记录；保留原请求键，只读找回，不能更换模型或改键重发。', target: 'route' },
    SAND_AI_CONFIGURATION_NOT_PUBLISHED: { message: '当前凭证环境没有可用的已发布能力配置。保存草稿后还需正式发布才能用于调用。', target: 'route' },
    SAND_AI_BUDGET_OUTPUT_BOUND_REQUIRED: { message: '预算或最低成本选择需要明确的最大输出上限。请核对原请求的最大输出 token 设置。', target: 'record' },
    SAND_AI_BUDGET_INPUT_PROOF_REQUIRED: { message: '模型输入上界缺少可验证依据。请管理员核对发布模型的容量和输入计量配置。', target: 'route' },
    SAND_AI_BUDGET_INPUT_BOUND_EXCEEDED: { message: '原请求输入超过所选路线的已验证容量。请管理员核对请求与模型容量，不能靠少算输入放行。', target: 'route' },
    SAND_AI_BUDGET_CAPACITY_UNVERIFIED: { message: '已发布路线的模型容量或实际请求未能通过预算核验。请管理员核对发布版本、容量依据和请求上限。', target: 'route' },
    SAND_AI_BUDGET_PRICE_REVISION_MISMATCH: { message: '预算报价与本次冻结的价格版本不一致。请管理员核对原调用和发布价格，不能用当前价格替换历史报价。', target: 'route' },
    SAND_AI_BUDGET_POLICY_NOT_EXECUTABLE: { message: '匹配预算配置不能执行。请管理员核对软／硬限制、未定价阻止及未测得用量保留占额设置。', target: 'budget' },
    SAND_AI_BUDGET_WINDOW_MISSING: { message: '匹配预算缺少执行额度记录。请管理员核对该已发布预算版本。', target: 'budget' },
    SAND_AI_BUDGET_CONFIGURATION_CONFLICT: { message: '同一范围存在冲突的生效预算版本。请管理员核对预算时段和版本，避免重复生效。', target: 'budget' },
    SAND_AI_BUDGET_RESERVATION_EXPIRED: { message: '原请求的预占已到期，需要核对原调用和占额。到期不证明未发送或无费用，不能自动释放或重发。', target: 'record' },
    SAND_AI_BUDGET_PREPARED_REVALIDATION_REQUIRED: { message: '原请求在外发前尚未通过当前预算复核。请管理员核对原调用和冻结预占，保留原请求键。', target: 'record' },
    SAND_AI_BUDGET_SCOPE_UNVERIFIED: { message: '调用的组织、应用、环境或能力归属未能核验。请管理员检查原调用身份与预算范围，不能改用后台身份代发。', target: 'record' },
    SAND_AI_BUDGET_SNAPSHOT_INCONSISTENT: { message: '预算占额记录未能通过一致性核验。请管理员核对原调用、预占和结算记录。', target: 'record' },
    SAND_AI_BUDGET_STATE_INVALID: { message: '原预算占额状态不允许本次处理。请管理员核对历史记录，不能把未知结果当作可释放额度。', target: 'record' },
    SAND_AI_BUDGET_RESERVATION_CHANGED: { message: '原请求的预占记录已变化。请只读刷新原调用并由管理员核对占额。', target: 'record' },
    SAND_AI_BUDGET_WINDOW_CHANGED: { message: '预算额度记录已变化。请管理员核对当前额度和原请求记录。', target: 'budget' },
    SAND_AI_BUDGET_RESERVATION_MISSING: { message: '原调用缺少匹配的预占记录。请管理员核对原请求，不补造预占或重新发送。', target: 'record' },
    SAND_AI_BUDGET_IDEMPOTENCY_CONFLICT: { message: '本次结算与原预占记录不一致。请管理员按原调用核对金额与币种，不重复结算。', target: 'record' },
    SAND_AI_SCOPE_SNAPSHOT_INVALID: { message: '冻结的最低成本路线与原调用记录不一致。请管理员核对原路线，不能更换模型重发原请求。', target: 'record' },
    SAND_AI_IDEMPOTENCY_CONFLICT: { message: '原请求键已绑定另一份输入。请使用原调用身份只读找回，不能覆盖原键或改键重发。', target: 'record' }
  }
  function guidanceFor(code: string | null): FailureGuidance | null {
    if (!code || !Object.prototype.hasOwnProperty.call(failureGuidanceByCode, code)) return null
    return failureGuidanceByCode[code] ?? null
  }
  const failureGuidance = computed(() => guidanceFor(failureCode.value ?? invocation.value?.error_code ?? null))
  function inspectRouteConfiguration(): void {
    trialPanel.value?.closest('.art-table-card')?.scrollIntoView({ block: 'start', behavior: 'smooth' })
  }
  const locked = computed(() => Boolean(pendingKey.value) || terminal.value || Boolean(busy.value))
  const ready = computed(() => !locked.value && Boolean(bearer.value.trim()) && Boolean(prompt.value.trim())
    && Number.isInteger(maxOutputTokens.value) && (maxOutputTokens.value ?? 0) >= 1
    && (maxOutputTokens.value ?? 0) <= 131072
    && (mode.value === 'lowest' || (modelCode.value !== '@lowest-cost'
      && models.value.some(model => model.code === modelCode.value))))

  const estimateView = computed<{ amount: string; revision: string }>(() => {
    const price = cost.value?.price
    const unavailable = (amount: string) => ({ amount, revision: '未确认' })
    if (!price || price.estimate_state === undefined)
      return unavailable('当前响应未提供调用前预算依据')
    if (price.estimate_state === 'held')
      return unavailable(price.estimate_reason === 'frozen_quote_conflict'
        ? '冻结预算报价存在冲突，待核验' : '冻结预算依据待核验')
    if (price.estimate_state === 'unavailable') {
      if (price.estimate_reason === 'no_frozen_quote')
        return unavailable('本次未冻结调用前预算报价')
      if (price.estimate_reason === 'frozen_quote_unavailable')
        return unavailable('冻结预算报价不可用')
      return unavailable('调用前预算依据未确认')
    }
    const revision = price.estimated_price_revision_id
    if (price.estimate_state !== 'available'
      || price.estimate_basis !== 'budget_reservation_upper_bound'
      || price.estimate_reason !== 'frozen_budget_reservation'
      || typeof price.estimated_amount !== 'string'
      || !/^(0|[1-9][0-9]{0,13})(?:\.[0-9]{1,6})?$/.test(price.estimated_amount)
      || typeof price.estimated_currency !== 'string'
      || !/^[A-Z]{3}$/.test(price.estimated_currency)
      || typeof revision !== 'number'
      || !Number.isSafeInteger(revision)
      || revision <= 0)
      return unavailable('调用前预算依据不完整，暂不显示金额')
    return {
      amount: `${price.estimated_amount} ${price.estimated_currency}`,
      revision: String(revision)
    }
  })

  function amount(value: string | null, currency: string | null): string {
    return value === null ? '未知' : `${value} ${currency ?? '币种未知'}`
  }
  function clearPrivateResults(): void {
    invocation.value = null; answer.value = null; selectedModel.value = null
    cost.value = null; costError.value = ''; recovered.value = false
  }
  function acceptInvocation(value: ChatInvocation): void {
    invocation.value = value
    // A failed invocation can still hold an ambiguous provider dispatch and incur cost.
    terminal.value = value.state === 'succeeded'
    if (terminal.value) {
      pendingKey.value = ''
      try { sessionStorage.removeItem(storageKey) } catch { /* A stale stored key will only trigger read-only recovery after reload. */ }
    }
  }
  function message(value: unknown): string {
    if (value instanceof SandAiRuntimeError) {
      const guidance = guidanceFor(value.errorCode)
      if (guidance) return `${guidance.message}（${value.errorCode}）`
      return value.errorCode ? `请求失败，原因尚未确认（${value.errorCode}）` : '请求失败，原因尚未确认'
    }
    return value instanceof Error ? value.message : '请求失败'
  }
  async function loadModels(): Promise<void> {
    if (busy.value || !bearer.value.trim()) return
    const current = ++generation
    const identity = bearer.value
    busy.value = 'models'; error.value = ''; failureCode.value = null
    try {
      const result = await chatApi.models(identity)
      if (current !== generation) return
      models.value = result.data.filter(model => model.code !== '@lowest-cost')
      if (!models.value.some(model => model.code === modelCode.value)) modelCode.value = ''
      if (!models.value.length) error.value = '当前凭证环境没有可用的已发布聊天模型。'
    } catch (failure) {
      if (current === generation) {
        models.value = []; modelCode.value = ''; error.value = message(failure)
        failureCode.value = failure instanceof SandAiRuntimeError ? failure.errorCode : null
      }
    } finally { if (current === generation) busy.value = '' }
  }
  async function submit(): Promise<void> {
    if (!ready.value || maxOutputTokens.value === undefined) return
    const identity = bearer.value
    const key = newRuntimeRequestId('chat-trial')
    const payload: ChatPayload = {
      model: mode.value === 'lowest' ? '@lowest-cost' : modelCode.value,
      messages: [{ role: 'user', content: prompt.value }],
      max_output_tokens: maxOutputTokens.value
    }
    try { sessionStorage.setItem(storageKey, key) }
    catch { error.value = '无法保存原请求键。为保证断线后可找回，本次未发送。'; return }
    pendingKey.value = key
    const current = ++generation
    busy.value = 'send'; error.value = ''; failureCode.value = null; clearPrivateResults()
    try {
      const result = await chatApi.complete(identity, key, payload)
      if (current !== generation) return
      acceptInvocation(result.invocation)
      answer.value = result.content
      selectedModel.value = result.selected_model ?? null
    } catch (failure) {
      if (current === generation) {
        error.value = `${message(failure)}。请只读找回原请求；不会重发。`
        failureCode.value = failure instanceof SandAiRuntimeError ? failure.errorCode : null
      }
    } finally { if (current === generation) busy.value = '' }
  }
  async function recover(): Promise<void> {
    if (!pendingKey.value || !bearer.value.trim() || busy.value) return
    const current = ++generation
    const key = pendingKey.value
    busy.value = 'recover'; error.value = ''; failureCode.value = null
    try {
      const result = await chatApi.findByRequestKey(bearer.value, key)
      if (current !== generation) return
      acceptInvocation(result); recovered.value = true
    } catch (failure) {
      if (current === generation) {
        error.value = `${message(failure)}。原请求仍未确认，请核对原调用身份后继续只读查询。`
        failureCode.value = failure instanceof SandAiRuntimeError ? failure.errorCode : null
      }
    } finally { if (current === generation) busy.value = '' }
  }
  async function loadCost(): Promise<void> {
    if (!invocation.value || !bearer.value.trim() || busy.value) return
    const current = ++generation
    const requestId = invocation.value.request_id
    busy.value = 'cost'; costError.value = ''
    try {
      const result = await chatApi.cost(bearer.value, requestId)
      if (current === generation) cost.value = result
    } catch (failure) {
      if (current === generation) { cost.value = null; costError.value = message(failure) }
    } finally { if (current === generation) busy.value = '' }
  }
  function newCall(): void {
    if (!terminal.value || pendingKey.value || busy.value) return
    terminal.value = false; clearPrivateResults(); error.value = ''; failureCode.value = null; prompt.value = ''
  }
  watch([bearer, () => props.environmentId], () => {
    generation++; busy.value = ''; clearPrivateResults()
    models.value = []; modelCode.value = ''; prompt.value = ''
    failureCode.value = null
    error.value = pendingKey.value ? '身份或页面环境已变化，原请求仍待确认。请使用原调用身份只读找回。' : ''
  }, { flush: 'sync' })
  onBeforeUnmount(() => { generation++ })
</script>

<style scoped>
  .chat-trial { margin-top: 24px; }
  .chat-trial__hint { color: var(--el-text-color-secondary); line-height: 1.6; }
  .chat-trial__select { width: 100%; max-width: 440px; }
  .chat-trial__recovery { margin: 16px 0; }
  .chat-trial__answer pre { white-space: pre-wrap; overflow-wrap: anywhere; }
</style>
