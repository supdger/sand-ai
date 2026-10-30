<template>
  <div class="conversation-panel">
    <h3>应用问答试用</h3>
    <p class="hint">使用已获 SandIAM 知识查询和聊天权限的应用凭证。会话及费用属于该应用，不属于管理员登录态。</p>
    <ElForm label-width="110px" @submit.prevent>
      <RuntimeApiKeyField v-model="apiKey" />
      <ElFormItem label="开放关系">
        <ElSelect v-model="code" placeholder="选择已开放知识库" filterable style="width: 360px">
          <ElOption v-for="item in available" :key="item.id" :label="`${item.code} · ${item.consumer_environment_id}`" :value="item.code" />
        </ElSelect>
      </ElFormItem>
      <ElFormItem label="聊天模型"><ElInput v-model="model" maxlength="128" placeholder="填写已发布的聊天模型 code" style="width: 360px" /></ElFormItem>
      <ElFormItem>
        <ElButton :disabled="!ready || busy" :loading="busy" @click="loadList()">找回会话</ElButton>
        <ElButton type="primary" :disabled="!ready || busy || storageBlocked || legacyCreate || !!createKey" :loading="busy" @click="create">
          {{ createKey ? '原创建待只读确认' : '新建会话' }}
        </ElButton>
      </ElFormItem>
      <ElFormItem v-if="createKey" label="未确认创建">
        <ElButton :disabled="!ready || busy" :loading="busy" @click="recoverCreated">找回原会话</ElButton>
        <span class="hint">无记录或拒绝不表示未创建，原号保留，不重发或更换请求号。</span>
        <template v-if="notSubmittedCreate === createKey">
          <p class="hint">本页确认原创建 POST 尚未开始。存储恢复后可明确提交同一原号；刷新后只读找回。</p>
          <ElButton :disabled="!ready || busy" @click="submitUnsentCreation">保存原号后创建原会话</ElButton>
        </template>
      </ElFormItem>
      <ElFormItem v-if="conversations.length" label="已有会话">
        <ElSelect v-model="conversationId" placeholder="选择会话" style="width: 360px" @change="selectConversation()">
          <ElOption v-for="item in conversations" :key="item.conversation_id" :label="`${item.create_time} · ${item.state}`" :value="item.conversation_id" />
        </ElSelect>
        <ElButton v-if="nextBefore" :disabled="busy" @click="loadList(nextBefore)">更早会话</ElButton>
      </ElFormItem>
      <ElFormItem v-if="conversationId" label="提问">
        <ElInput v-model="query" maxlength="500" placeholder="输入问题" style="width: 500px" @keyup.enter="answer" />
        <ElButton type="primary" :disabled="sourceUnavailable || !query.trim() || !model.trim() || busy || !conversation || !!pendingKey || targetLocked || conversation?.turns.some(turn => turn.state === 'pending') || conversation?.state === 'closed'" :loading="busy" @click="answer">发送</ElButton>
      </ElFormItem>
      <ElFormItem v-if="conversationId" label="来源重排">
        <ElSwitch v-model="rerank" :disabled="busy" />
        <span class="hint">启用后按问题相关性重排已命中的来源，会增加一次独立计费的模型调用。</span>
      </ElFormItem>
      <ElFormItem v-if="conversationId" label="段落预览">
        <ElSwitch v-model="streamSegments" :disabled="busy || !!pendingKey" />
        <span class="hint">默认关闭；启用后仅预览服务端已验证的完整段落，最终回答以本轮结果为准。</span>
      </ElFormItem>
      <ElFormItem v-if="conversationId">
        <ElButton :disabled="busy" @click="read">刷新会话</ElButton>
        <ElButton :disabled="busy || targetLocked || !conversation || conversation?.state === 'closed'" @click="close">关闭会话</ElButton>
      </ElFormItem>
    </ElForm>
    <ElAlert v-if="error" type="error" :title="error" :closable="false" class="notice" />
    <p v-if="conversation?.turns.some(turn => turn.state === 'pending')" class="hint">待处理轮次每隔数秒自动更新状态；结果不明时不会自动重发模型请求。</p>
    <ElAlert v-if="pendingKey" type="warning" title="上次提交结果不明。点击“找回原请求”只读取原轮次；未找到前不会重新发送问题。" :closable="false" class="notice" />
    <ElButton v-if="pendingKey" :loading="busy" :disabled="!ready || busy" @click="recoverPendingTurn">找回原请求</ElButton>
    <ElAlert v-if="storageBlocked" type="error" title="原目标存储不可用，已停止新的发送、取消和关闭；请保留此页面并恢复浏览器会话存储。" :closable="false" class="notice" />
    <ElCard v-if="originalActions.length" shadow="never" class="notice">
      <strong>原目标找回</strong>
      <p class="hint">先选择记录对应的环境和开放关系，再填写当前有效凭据。找回只读取原目标并重验当前授权，不重新发送。</p>
      <div v-for="item in originalActions" :key="actionIdentity(item)" class="notice">
        <p>环境 {{ item.environment }} · {{ item.code }} · 会话 {{ item.conversation }} · {{ actionLabel(item) }}<span v-if="item.sequence">第 {{ item.sequence }} 轮</span></p>
        <p class="hint" v-if="item.admin === null">旧记录尚未绑定管理员；仅在显式读取原目标成功后归入当前管理员。</p>
        <p class="hint" v-if="item.phase === 'unknown'">结果尚未知，原目标的冲突操作已锁定。</p>
        <p class="hint" v-else-if="item.action === 'send'">原请求已定位；本轮执行与费用以原会话当前结果为准。</p>
        <p class="hint" v-else>原目标已达{{ item.action === 'close' ? '关闭' : '取消' }}状态；这不能证明上次动作是否执行。</p>
        <ElButton :disabled="!ready || busy || storageBlocked || item.environment !== props.environmentId || item.code !== code" :loading="busy" @click="recoverOriginalAction(item)">读取原目标</ElButton>
      </div>
    </ElCard>
    <ElAlert v-if="sourceUnavailable" type="warning" title="当前会话来源已不可读，回答、引用、摘要和段落预览已清除。原轮次与费用仍可只读找回；新的问题请使用当前有效开放关系新建会话。" :closable="false" class="notice" />
    <template v-if="conversation">
      <p class="hint">会话 {{ conversation.conversation_id }} · {{ conversation.state === 'closed' ? '已关闭' : '进行中' }}</p>
      <ElCard v-for="turn in conversation.turns" :key="turn.sequence_no" shadow="never" class="turn">
        <strong>第 {{ turn.sequence_no }} 轮：{{ turn.query }}</strong>
        <p class="hint">原轮次状态：{{ executionStateLabel(turn.execution_state) }}</p>
        <template v-if="turn.state === 'pending'">
          <p role="status" class="hint">实时连接：{{ streamTransportLabel }}；连接变化不会改变原轮次状态。</p>
          <ElButton v-if="['connecting', 'reading'].includes(streamTransport)" :disabled="busy" @click="pauseStreamReading">暂停实时读取</ElButton>
          <ElButton v-else-if="!['outcome_unknown', 'recovery_required'].includes(turn.execution_state)"
            :disabled="sourceUnavailable || busy || !ready" @click="continuePreview(turn.sequence_no)">原轮次只读重连</ElButton>
          <ElButton :disabled="busy || !ready" @click="read">读取本会话已保存历史</ElButton>
          <p class="hint">重连只读取原轮次事件，沿已读段落游标续读，不重新发送问题或调用模型。</p>
        </template>
        <ElButton v-if="turn.state === 'pending' && ['recovery_required', 'outcome_unknown'].includes(turn.execution_state)"
          :disabled="busy || !ready || targetLocked" :loading="busy" @click="resumeTurn(turn.sequence_no)">
          按原请求恢复本轮
        </ElButton>
        <ElButton v-if="turn.state === 'pending' && turn.execution_state === 'preparing'"
          :disabled="busy || !ready || targetLocked" :loading="busy" @click="cancelTurn(turn.sequence_no)">
          取消未开始的调用
        </ElButton>
        <p v-if="turn.result?.answer">{{ turn.result.answer }}</p>
        <div v-else-if="turn.state === 'pending' && !['outcome_unknown', 'recovery_required'].includes(turn.execution_state) && turn.stream_segments && preview?.sequenceNo === turn.sequence_no" class="segment-preview">
          <p class="hint">进行中预览；来源编号仅供核对，最终内容等待本轮结果。</p>
          <p v-for="segment in preview.segments" :key="segment.segment_no">
            {{ segment.text }}
            <span class="hint">来源块编号：{{ segment.source_block_ids.length ? segment.source_block_ids.join('、') : '无' }}</span>
          </p>
          <p v-if="!preview.segments.length" class="hint">正在等待已验证段落…</p>
        </div>
        <p v-else-if="turn.result?.answer_state === 'failed'">本轮已封存为失败，原错误码：{{ turn.result.error_code }}。原请求不会重新发送，已发生费用可从本轮费用入口核对。</p>
        <p v-else-if="turn.result?.answer_state === 'cancelled'">本轮已取消，未开始服务调用。</p>
        <p v-else-if="turn.result?.answer_state === 'no_sources'">没有可引用的来源，本轮未调用模型。</p>
        <p v-else-if="turn.result?.answer_state === 'needs_review'">模型回答或引用未通过核验，请人工复核。</p>
        <p v-if="turn.result?.context_coverage" class="hint">
          本轮{{ turn.result.context_coverage.used_for_model ? '已调用模型' : '未调用模型' }}；
          近期已回答轮次：{{ sequenceLabel(turn.result.context_coverage.recent_answered_sequences) }}；
          更早问题线索：{{ sequenceLabel(turn.result.context_coverage.earlier_question_sequences) }}；
          更早回答摘录：{{ sequenceLabel(turn.result.context_coverage.earlier_answer_excerpt_sequences ?? []) }}。
          <span v-if="turn.result.context_coverage.summary_answered_count">
            摘要已覆盖 {{ turn.result.context_coverage.summary_answered_count }} 个已回答轮次，
            截至第 {{ turn.result.context_coverage.summary_through_sequence_no }} 轮。
          </span>
          <span v-if="turn.result.context_coverage.has_omitted_earlier_answered">
            更早的 {{ turn.result.context_coverage.omitted_answered_count ?? '若干' }} 个已回答轮次未带入，本轮不代表覆盖全部会话历史。
          </span>
        </p>
        <details v-if="turn.result?.context_coverage?.summary_text">
          <summary>查看本轮使用的历史摘要</summary>
          <p class="summary-text">{{ turn.result.context_coverage.summary_text }}</p>
        </details>
        <p v-if="turn.result?.context_coverage?.summary_state === 'invalid_output'" class="hint">
          本次新摘要未通过核验；此前摘要调用仍可能产生费用，本轮仅使用已保存的覆盖范围。
        </p>
        <p v-if="turn.result?.context_coverage?.summary_state === 'partial'" class="hint">
          本轮已达到摘要调用上限，后续提问会继续处理尚未覆盖的历史。
        </p>
        <ElTable v-if="turn.result?.citations?.length" :data="turn.result.citations" size="small">
          <ElTableColumn label="来源块" prop="source_block_id" width="100" />
          <ElTableColumn label="摘录" prop="excerpt" min-width="250" />
          <ElTableColumn label="位置" min-width="180"><template #default="{ row }">{{ row.locator_type }} · {{ JSON.stringify(row.locator) }}</template></ElTableColumn>
          <ElTableColumn label="来源正文" width="180">
            <template #default="{ row }">
              <ElButton v-if="knowledgeSourceReference(row)?.knowledge_base_code === code" link type="primary"
                :disabled="!ready || busy"
                :loading="sourceBusy && sourceTarget?.source_block_id === row.source_block_id"
                @click="openSource(row)">查看来源</ElButton>
              <span v-else class="hint">{{ knowledgeSourceReference(row) ? '引用不属于当前开放关系' : '引用版本信息缺失' }}</span>
            </template>
          </ElTableColumn>
        </ElTable>
        <ElButton v-if="turn.result?.invocation?.request_id" link type="primary" @click="loadCost(turn.result.invocation.request_id)">查看本轮费用</ElButton>
        <ElButton v-if="turn.result?.rerank_invocation?.request_id" link type="primary"
          @click="loadCost(turn.result.rerank_invocation.request_id)">查看来源重排费用</ElButton>
        <ElButton v-for="(invocation, index) in turn.result?.summary_invocations ?? []" :key="invocation.request_id"
          link type="primary" @click="loadCost(invocation.request_id)">查看摘要费用 {{ index + 1 }}</ElButton>
        <p v-if="turn.result?.rerank_state === 'invalid_output'" class="hint">重排输出未通过核验，本轮沿用原候选顺序；重排调用仍可能产生费用。</p>
      </ElCard>
      <ElButton v-if="conversation.next_before_sequence" :disabled="busy" @click="loadEarlier">更早轮次</ElButton>
      <pre v-if="cost" class="cost">{{ JSON.stringify(cost, null, 2) }}</pre>
    </template>
    <ElDialog :model-value="sourceVisible" title="来源正文" width="min(760px, 94vw)"
      destroy-on-close @update:model-value="setSourceVisible">
      <p v-if="sourceTarget" class="hint">
        知识库 {{ sourceTarget.knowledge_base_code }} · 版本 {{ sourceTarget.knowledge_base_version }}
        · 来源块 {{ sourceTarget.source_block_id }}
      </p>
      <p v-if="sourceBusy" role="status">正在读取当前授权来源…</p>
      <ElAlert v-if="sourceError" type="error" :title="sourceError" :closable="false" />
      <template v-if="sourceBody">
        <p>文件 {{ sourceBody.file_id }} · 解析 {{ sourceBody.parse_id }}</p>
        <p class="hint">位置：{{ sourceBody.locator_type }} · {{ JSON.stringify(sourceBody.locator) }}</p>
        <pre class="source-text" tabindex="0" aria-label="来源正文">{{ sourceBody.content }}</pre>
      </template>
      <template #footer><ElButton @click="clearSource">关闭</ElButton></template>
    </ElDialog>
  </div>
</template>

<script setup lang="ts">
import { computed, onUnmounted, ref, watch } from 'vue'
import { isAxiosError } from 'axios'
import { ElMessageBox } from 'element-plus'
import { useUserStore } from '@/store/modules/user'
import RuntimeApiKeyField from '../../components/RuntimeApiKeyField.vue'
import conversationApi, { KnowledgeStreamConnectionError, type KnowledgeConversation, type KnowledgeConversationSummary, type KnowledgeSegment, type KnowledgeTurn } from '../../api/knowledgeConversation'
import { newRuntimeRequestId, SandAiRuntimeError } from '../../api/runtime'
import type { KnowledgePublication } from '../../api/knowledgePublication'
import type { SandAiInvocationCost } from '../../api/types'
import sourceApi, { knowledgeSourceReference, type KnowledgeSourceBody, type KnowledgeSourceReference } from '../../api/knowledgeSource'

const props = defineProps<{ publications: KnowledgePublication[]; environmentId: number | null }>()
const available = computed(() => props.publications.filter(item =>
  item.state === 'published' && item.consumer_environment_id === props.environmentId
))
const userStore = useUserStore()
const adminId = computed(() => {
  const id = userStore.info.id
  return typeof id === 'number' && Number.isSafeInteger(id) && id > 0 ? id : null
})
const apiKey = ref('')
const code = ref('')
const model = ref('')
const query = ref('')
const rerank = ref(false)
const streamSegments = ref(false)
const conversationId = ref('')
const conversations = ref<KnowledgeConversationSummary[]>([])
const conversation = ref<KnowledgeConversation | null>(null)
const nextBefore = ref<string | null>(null)
const cost = ref<SandAiInvocationCost | null>(null)
const error = ref('')
const busyState = ref(false)
const writeFlight = ref<symbol | null>(null)
const busy = computed(() => busyState.value || writeFlight.value !== null)
const sourceVisible = ref(false)
const sourceBusy = ref(false)
const sourceTarget = ref<KnowledgeSourceReference | null>(null)
const sourceBody = ref<KnowledgeSourceBody | null>(null)
const sourceError = ref('')
let sourceEpoch = 0
const unreadableConversations = ref(new Set<string>())
const sourceUnavailable = computed(() => unreadableConversations.value.has(conversationId.value))
const ready = computed(() => adminId.value !== null && !!apiKey.value.trim() && !!code.value && !!props.environmentId)
let epoch = 0
const pendingKey = ref<string | null>(null)
let draftRevision = 0
let costEpoch = 0
let readEpoch = 0
const preview = ref<{ sequenceNo: number; segments: KnowledgeSegment[] } | null>(null)
const streamCursors = new Map<number, number>()
const createKey = ref<string | null>(null)
const notSubmittedCreate = ref<string | null>(null)
let creationIntent: { key: string; epoch: number; storage: string; credential: string; code: string } | null = null
let refreshTimer: ReturnType<typeof setTimeout> | null = null
let lookupTimer: ReturnType<typeof setTimeout> | null = null
let lookupKey: string | null = null
let lookupAttempts = 0
let refreshFailures = 0
let streamController: AbortController | null = null
let streamSequence: number | null = null
let streamRetryAfter = 0
let streamFailures = 0
const streamPaused = ref(false)
const manualStreamPause = ref(false)
const streamTransport = ref<'idle' | 'connecting' | 'reading' | 'disconnected' | 'paused' | 'closed' | 'failed'>('idle')
const streamTransportLabel = computed(() => ({ idle: '尚未连接', connecting: '正在连接原轮次',
  reading: '正在读取原轮次', disconnected: '已断线，原轮次状态尚待读取', paused: '已暂停读取',
  closed: '原轮次读取已结束', failed: '读取连接失败，原轮次未判失败' })[streamTransport.value])
const scopeLossCodes = new Set([
  'SAND_AI_AUTHENTICATION_FAILED', 'SAND_AI_IDENTITY_CONTEXT_EXPIRED',
  'SAND_AI_CREDENTIAL_REVOKED', 'SAND_AI_SERVICE_ACTION_FORBIDDEN',
  'SAND_AI_INVOCATION_SCOPE_FORBIDDEN', 'SAND_AI_INVOCATION_FACTS_UNVERIFIED',
  'SAND_AI_SERVICE_NETWORK_FORBIDDEN', 'SAND_AI_DATA_CLASS_FORBIDDEN',
  'SAND_AI_KNOWLEDGE_SOURCE_INVALID', 'SAND_AI_KNOWLEDGE_SOURCE_DRIFTED'
])

function clearSourceContent(): void {
  if (!conversationId.value) return
  unreadableConversations.value = new Set([...unreadableConversations.value, conversationId.value])
  ++readEpoch
  clearSource()
  stopPendingStream()
  preview.value = null
  query.value = ''
  const currentConversation = conversation.value
  if (currentConversation) {
    conversation.value = { ...currentConversation,
      turns: currentConversation.turns.map(turn => readableTurn(turn, currentConversation.conversation_id)) }
  }
  schedulePendingRefresh()
}
function readableTurn(turn: KnowledgeTurn, id: string): KnowledgeTurn {
  if (!unreadableConversations.value.has(id)) return turn
  const result = turn.result
  return { ...turn, query: '', result: result ? {
    answer_state: result.answer_state, answer: null, citations: [], review_required: result.review_required,
    error_code: result.error_code,
    invocation: result.invocation, rerank_invocation: result.rerank_invocation,
    summary_invocations: result.summary_invocations
  } : null }
}

function clearProtectedResult(cause: unknown, resourceNotFound = false): boolean {
  if (cause instanceof SandAiRuntimeError && conversationId.value
    && ['SAND_AI_KNOWLEDGE_SOURCE_INVALID', 'SAND_AI_KNOWLEDGE_SOURCE_DRIFTED'].includes(cause.errorCode ?? '')) {
    clearSourceContent()
    error.value = message(cause)
    return true
  }
  if (!(cause instanceof SandAiRuntimeError)
    || (cause.httpStatus !== 401 && cause.httpStatus !== 403
      && !(resourceNotFound && (cause.httpStatus === 404 || cause.errorCode === 'SAND_AI_RESOURCE_NOT_FOUND'))
      && !scopeLossCodes.has(cause.errorCode ?? ''))) return false
  ++epoch
  clearSource()
  stopPendingRefresh()
  stopTurnLookup()
  stopPendingStream()
  preview.value = null
  conversation.value = null
  conversationId.value = ''
  conversations.value = []
  nextBefore.value = null
  cost.value = null
  query.value = ''
  ++costEpoch
  ++readEpoch
  pendingKey.value = null
  createKey.value = null
  creationIntent = null
  notSubmittedCreate.value = null
  busyState.value = false
  refreshFailures = 0
  streamFailures = 0
  streamPaused.value = false
  manualStreamPause.value = false
  streamRetryAfter = 0
  error.value = message(cause)
  return true
}

type OriginalAction = {
  admin: number | null; environment: number; code: string; conversation: string
  action: 'send' | 'cancel' | 'close'; key: string | null; sequence: number | null
  phase: 'unknown' | 'target_confirmed'
}
const originalActions = ref<OriginalAction[]>([])
const storageBlocked = ref(false)
const legacyCreate = ref(false)
const targetLocked = computed(() => storageBlocked.value || originalActions.value.some(item =>
  matchesTarget(item) && item.phase === 'unknown'
))
function matchesTarget(item: OriginalAction): boolean {
  return item.environment === props.environmentId && item.code === code.value && item.conversation === conversationId.value
}
function journalKey(): string | null {
  return adminId.value === null ? null : `sand-ai-knowledge-actions:v1:${adminId.value}`
}
function isOriginalAction(value: unknown, admin: number): value is OriginalAction {
  if (typeof value !== 'object' || value === null) return false
  return 'admin' in value && value.admin === admin
    && 'environment' in value && typeof value.environment === 'number' && Number.isSafeInteger(value.environment) && value.environment > 0
    && 'code' in value && typeof value.code === 'string' && value.code.length > 0
    && 'conversation' in value && typeof value.conversation === 'string' && value.conversation.length > 0
    && 'action' in value && ['send', 'cancel', 'close'].includes(String(value.action))
    && 'key' in value && (value.action === 'send' ? typeof value.key === 'string' && value.key.length > 0 : value.key === null)
    && 'sequence' in value && (value.sequence === null ? value.action !== 'cancel'
      : typeof value.sequence === 'number' && Number.isSafeInteger(value.sequence) && value.sequence > 0)
    && 'phase' in value && ['unknown', 'target_confirmed'].includes(String(value.phase))
}
function actionIdentity(item: OriginalAction): string {
  return JSON.stringify([item.admin, item.environment, item.code, item.conversation, item.action, item.key, item.sequence])
}
function readOriginalActions(): void {
  originalActions.value = []
  storageBlocked.value = false
  const storageKey = journalKey()
  if (!storageKey || adminId.value === null) return
  try {
    const raw = sessionStorage.getItem(storageKey)
    const values: unknown = raw === null ? [] : JSON.parse(raw)
    const owner = adminId.value
    if (!Array.isArray(values) || !values.every(value => isOriginalAction(value, owner))) throw new Error('原目标记录格式无效')
    originalActions.value = values
    // Historical records have no administrator owner. Display them as unbound;
    // only an explicit original GET with the current credential can bind ownership.
    if (props.environmentId && code.value) {
      const prefix = `sand-ai-knowledge-turn:${props.environmentId}:${encodeURIComponent(code.value)}:`
      for (let index = 0; index < sessionStorage.length; index++) {
        const key = sessionStorage.key(index)
        if (!key?.startsWith(prefix)) continue
        const requestKey = sessionStorage.getItem(key)
        const id = decodeURIComponent(key.slice(prefix.length))
        if (!requestKey || !id) throw new Error('旧原目标记录无效')
        if (!originalActions.value.some(item => item.environment === props.environmentId && item.code === code.value
          && item.conversation === id && item.key === requestKey)) {
          originalActions.value.push({ admin: null, environment: props.environmentId, code: code.value,
            conversation: id, action: 'send', key: requestKey, sequence: null, phase: 'unknown' })
        }
      }
    }
  } catch (cause) { storageBlocked.value = true; error.value = `无法读取原目标记录，已停止新操作：${message(cause)}` }
}
function saveOriginalActions(values: OriginalAction[]): void {
  const key = journalKey()
  if (!key || adminId.value === null || storageBlocked.value) throw new Error('当前管理员或原目标存储不可用')
  const owned = values.filter(item => item.admin === adminId.value)
  const serialized = JSON.stringify(owned)
  try {
    sessionStorage.setItem(key, serialized)
    if (sessionStorage.getItem(key) !== serialized) throw new Error('原目标记录读回不一致')
  } catch (cause) { storageBlocked.value = true; throw cause }
  originalActions.value = values
}
function newOriginalAction(action: OriginalAction['action'], key: string | null, sequence: number | null): OriginalAction {
  if (adminId.value === null || !props.environmentId || !code.value || !conversationId.value) throw new Error('原目标上下文缺失')
  return { admin: adminId.value, environment: props.environmentId, code: code.value,
    conversation: conversationId.value, action, key, sequence, phase: 'unknown' }
}
function rememberPendingKey(key: string): void {
  const entry = newOriginalAction('send', key, null)
  saveOriginalActions([...originalActions.value, entry])
  pendingKey.value = key
}
function forgetPendingKey(key: string): void {
  // A POST response alone cannot unlock the original request. Require its original
  // request-key GET and exact same-conversation sequence GET before confirming it.
  if (pendingKey.value === key && !originalActions.value.some(item => matchesTarget(item) && item.key === key && item.phase === 'unknown')) {
    pendingKey.value = null
  }
}
function restorePendingKey(): void {
  pendingKey.value = originalActions.value.find(item => matchesTarget(item) && item.action === 'send' && item.phase === 'unknown')?.key ?? null
}
function actionLabel(item: OriginalAction): string {
  return item.action === 'send' ? '发送' : item.action === 'cancel' ? '取消' : '关闭'
}
async function recoverOriginalAction(entry: OriginalAction, heldFlight?: symbol): Promise<void> {
  if (heldFlight !== undefined && writeFlight.value !== heldFlight) return
  if (!ready.value || (busy.value && heldFlight === undefined) || storageBlocked.value || entry.environment !== props.environmentId || entry.code !== code.value
    || (entry.admin !== null && entry.admin !== adminId.value)) return
  const flight = heldFlight ?? Symbol('knowledge-conversation-recovery')
  writeFlight.value = flight
  try {
    const credential = apiKey.value
    // Selecting an original target invalidates all private results from the previous target.
    conversationId.value = entry.conversation
    selectConversation(false)
    const current = epoch
    busyState.value = true
    error.value = ''
    try {
      let sequence = entry.sequence
      let expectedStreamSegments: boolean | null = null
      if (entry.action === 'send') {
        if (!entry.key) throw new Error('原请求号缺失')
        const located = await conversationApi.findTurnByRequestKey(credential, entry.code, entry.conversation, entry.key)
        if (current !== epoch) return
        if (sequence !== null && sequence !== located.sequence_no) throw new Error('原请求定位轮次发生变化')
        sequence = located.sequence_no
        expectedStreamSegments = located.stream_segments
        if (entry.admin !== null) {
          const identity = actionIdentity(entry)
          const locatedEntry = { ...entry, sequence }
          saveOriginalActions(originalActions.value.map(item => actionIdentity(item) === identity ? locatedEntry : item))
          entry = locatedEntry
        }
      }
      if (sequence !== null) await readLocatedTurn(current, sequence)
      else await readCurrent(current)
      if (current !== epoch || conversation.value?.conversation_id !== entry.conversation) return
      const turn = sequence === null ? null : conversation.value.turns.find(item => item.sequence_no === sequence)
      if (sequence !== null && (!turn || (expectedStreamSegments !== null && turn.stream_segments !== expectedStreamSegments))) throw new Error('原轮次尚未读取或定位不匹配，结果仍未知')
      const confirmed = entry.action === 'send' ? !!turn : entry.action === 'close'
        ? conversation.value.state === 'closed' : turn?.state === 'cancelled' && turn.result?.answer_state === 'cancelled'
      const replacement: OriginalAction = { ...entry, admin: adminId.value, sequence, phase: confirmed ? 'target_confirmed' : 'unknown' }
      // Bind legacy ownership only after the original authorized GET has proved the target.
      const identity = actionIdentity(entry)
      saveOriginalActions(originalActions.value.map(item => actionIdentity(item) === identity ? replacement : item))
      if (entry.admin === null && entry.key) {
        sessionStorage.removeItem(`sand-ai-knowledge-turn:${entry.environment}:${encodeURIComponent(entry.code)}:${encodeURIComponent(entry.conversation)}`)
      }
      restorePendingKey()
      error.value = confirmed ? '' : '原目标已读取，但原动作结果仍未知；当前状态不能证明动作是否执行，不会自动重发。'
    } catch (cause) {
      if (current !== epoch) return
      clearProtectedResult(cause, true)
      error.value = `原目标结果仍未知，保留原号且不会重新提交：${message(cause)}`
    } finally { if (current === epoch) busyState.value = false }
  } finally {
    if (writeFlight.value === flight) writeFlight.value = null
  }
}
async function readLocatedTurn(current: number, sequenceNo: number): Promise<void> {
  await readCurrent(current)
  if (current !== epoch) return
  if (conversation.value?.turns.some(turn => turn.sequence_no === sequenceNo)) return
  const result = await conversationApi.read(apiKey.value, code.value, conversationId.value, sequenceNo + 1)
  if (current !== epoch || !conversation.value) return
  const turns = new Map(conversation.value.turns.map(turn => [turn.sequence_no, turn]))
  for (const turn of result.turns) if (!turns.has(turn.sequence_no)) turns.set(turn.sequence_no, readableTurn(turn, result.conversation_id))
  conversation.value = { ...conversation.value, turns: [...turns.values()].sort((a, b) => a.sequence_no - b.sequence_no),
    next_before_sequence: result.next_before_sequence }
  if (!conversation.value.turns.some(turn => turn.sequence_no === sequenceNo)) throw new Error('原会话未返回指定轮次，结果仍未知')
}
async function recoverPendingTurn(): Promise<void> {
  const entry = originalActions.value.find(item => matchesTarget(item) && item.action === 'send' && item.phase === 'unknown')
  if (entry) await recoverOriginalAction(entry)
}

function stopTurnLookup(): void {
  if (lookupTimer !== null) clearTimeout(lookupTimer)
  lookupTimer = null
  lookupKey = null
  lookupAttempts = 0
}
function locateSubmittedTurn(key: string, current: number, id: string): void {
  stopTurnLookup()
  lookupKey = key
  const look = async (): Promise<void> => {
    lookupTimer = null
    if (current !== epoch || lookupKey !== key || conversationId.value !== id) return
    if (++lookupAttempts > 60) {
      stopTurnLookup()
      error.value = '尚未定位到原轮次，请刷新会话或按原请求恢复。'
      return
    }
    try {
      const found = await conversationApi.findTurnByRequestKey(apiKey.value, code.value, id, key)
      if (current !== epoch || lookupKey !== key || conversationId.value !== id) return
      if (!Number.isSafeInteger(found.sequence_no) || found.sequence_no < 1
        || typeof found.stream_segments !== 'boolean' || typeof found.state !== 'string') {
        throw new Error('原轮次定位响应无效')
      }
      if (!sourceUnavailable.value && found.state === 'pending' && found.stream_segments) {
        preview.value = { sequenceNo: found.sequence_no, segments: [] }
      }
      const pendingEntry = originalActions.value.find(item => matchesTarget(item) && item.action === 'send' && item.key === key)
      if (!pendingEntry || (pendingEntry.sequence !== null && pendingEntry.sequence !== found.sequence_no)) throw new Error('原目标记录与定位不一致')
      saveOriginalActions(originalActions.value.map(item => item === pendingEntry ? { ...item, sequence: found.sequence_no } : item))
      await readLocatedTurn(current, found.sequence_no)
      if (current !== epoch) return
      const actual = conversation.value?.conversation_id === id
        ? conversation.value.turns.find(turn => turn.sequence_no === found.sequence_no) : null
      if (!actual || actual.stream_segments !== found.stream_segments) throw new Error('原会话尚未确认指定轮次')
      const entry = originalActions.value.find(item => matchesTarget(item) && item.action === 'send' && item.key === key)
      if (!entry || (entry.sequence !== null && entry.sequence !== found.sequence_no)) throw new Error('原目标记录与定位不一致')
      saveOriginalActions(originalActions.value.map(item => item === entry
        ? { ...item, sequence: found.sequence_no, phase: 'target_confirmed' } : item))
      forgetPendingKey(key)
      stopTurnLookup()
    } catch (cause) {
      if (current !== epoch || lookupKey !== key) return
      if (cause instanceof SandAiRuntimeError && cause.errorCode === 'SAND_AI_RESOURCE_NOT_FOUND') {
        try {
          await readCurrent(current)
        } catch (readFailure) {
          if (current === epoch) {
            stopTurnLookup()
            error.value = message(readFailure)
          }
          return
        }
        if (current !== epoch || lookupKey !== key) return
        lookupTimer = setTimeout(() => { void look() }, 1000)
        return
      }
      if (clearProtectedResult(cause)) return
      stopTurnLookup()
      preview.value = null
      error.value = message(cause)
    }
  }
  void look()
}

function stopPendingRefresh(): void {
  if (refreshTimer !== null) clearTimeout(refreshTimer)
  refreshTimer = null
}
function stopPendingStream(): void {
  streamController?.abort()
  streamController = null
  streamSequence = null
  streamTransport.value = 'idle'
}
function pauseStreamReading(): void {
  stopPendingStream()
  streamFailures = 3
  manualStreamPause.value = true
  streamPaused.value = true
  streamTransport.value = 'paused'
  schedulePendingRefresh()
}
function resetStreamFailures(): void {
  streamFailures = 0
  streamRetryAfter = 0
  streamPaused.value = manualStreamPause.value
}
function isConnectionFailure(cause: unknown): boolean {
  return cause instanceof KnowledgeStreamConnectionError || cause instanceof TypeError
    || (isAxiosError(cause) && cause.response === undefined)
}
function startPendingStream(sequenceNo: number): void {
  if (sourceUnavailable.value) return
  if (streamController && streamSequence === sequenceNo) return
  stopPendingStream()
  const controller = new AbortController()
  let streamError = false
  const current = epoch
  const currentConversationId = conversationId.value
  streamController = controller
  streamSequence = sequenceNo
  streamTransport.value = 'connecting'
  if (preview.value?.sequenceNo !== sequenceNo) preview.value = { sequenceNo, segments: [] }
  const lastSegmentNo = preview.value.segments.at(-1)?.segment_no ?? streamCursors.get(sequenceNo) ?? 0
  void conversationApi.streamTurn(
    apiKey.value, code.value, currentConversationId, sequenceNo, controller.signal,
    turn => {
      if (current !== epoch || controller.signal.aborted || conversation.value?.conversation_id !== currentConversationId) return
      streamTransport.value = 'reading'
      const index = conversation.value.turns.findIndex(item => item.sequence_no === sequenceNo)
      if (index < 0) return
      ++readEpoch
      const previous = conversation.value.turns[index]
      if (previous.state !== turn.state || previous.execution_state !== turn.execution_state) resetStreamFailures()
      const turns = [...conversation.value.turns]
      turns[index] = readableTurn(turn, currentConversationId)
      conversation.value = { ...conversation.value, turns }
      if (turn.state !== 'pending' || ['outcome_unknown', 'recovery_required'].includes(turn.execution_state)) preview.value = null
    },
    segment => {
      if (current !== epoch || controller.signal.aborted || sourceUnavailable.value || conversationId.value !== currentConversationId
        || preview.value?.sequenceNo !== sequenceNo) return
      streamTransport.value = 'reading'
      const previous = preview.value.segments.at(-1)?.segment_no ?? streamCursors.get(sequenceNo) ?? 0
      if (segment.segment_no === previous + 1) {
        streamCursors.set(sequenceNo, segment.segment_no)
        preview.value = { sequenceNo, segments: [...preview.value.segments, segment] }
        resetStreamFailures()
      }
    },
    lastSegmentNo
  ).catch(cause => {
    if (current !== epoch || controller.signal.aborted) return
    if (clearProtectedResult(cause, true)) return
    streamError = true
    streamTransport.value = isConnectionFailure(cause) ? 'disconnected' : 'failed'
    if (!isConnectionFailure(cause)) preview.value = null
    error.value = message(cause)
  }).finally(() => {
    if (streamController !== controller) return
    streamController = null
    streamSequence = null
    if (current === epoch) {
      if (!streamError) streamTransport.value = 'closed'
      if (conversation.value?.turns.some(turn => turn.sequence_no === sequenceNo && turn.state === 'pending')) {
        if (streamError) streamFailures++
        else resetStreamFailures()
        streamPaused.value = manualStreamPause.value || streamFailures >= 3
        streamRetryAfter = Date.now() + (streamError ? streamFailures * 3000 : 1000)
      }
      schedulePendingRefresh()
    }
  })
}
function schedulePendingRefresh(): void {
  stopPendingRefresh()
  const pending = conversation.value?.turns.find(turn => turn.state === 'pending')
  if (!ready.value || !conversationId.value || !pending) {
    stopPendingStream()
    preview.value = null
    if (conversation.value) streamTransport.value = 'closed'
    return
  }
  const previewInterrupted = ['outcome_unknown', 'recovery_required'].includes(pending.execution_state)
  if (previewInterrupted) {
    stopPendingStream()
    preview.value = null
  }
  if (!sourceUnavailable.value && !previewInterrupted && !manualStreamPause.value && streamFailures < 3 && Date.now() >= streamRetryAfter) {
    startPendingStream(pending.sequence_no)
    return
  }
  refreshTimer = setTimeout(() => { void refreshPending() }, document.hidden ? 10000 : 3000)
}
async function refreshPending(): Promise<void> {
  refreshTimer = null
  if (!ready.value || !conversationId.value || !conversation.value?.turns.some(turn => turn.state === 'pending')) return
  if (busy.value || document.hidden) { schedulePendingRefresh(); return }
  const current = epoch
  try {
    await readCurrent(current)
    if (current === epoch) refreshFailures = 0
  } catch {
    if (current !== epoch) return
    refreshFailures++
    if (refreshFailures >= 3) {
      error.value = '会话状态自动更新已暂停，请手动刷新后继续。'
      return
    }
    schedulePendingRefresh()
  }
}

function reset(): void {
  unreadableConversations.value = new Set()
  streamCursors.clear()
  ++epoch
  ++costEpoch
  ++readEpoch
  clearSource()
  stopPendingRefresh()
  stopTurnLookup()
  stopPendingStream()
  refreshFailures = 0
  streamRetryAfter = 0
  streamFailures = 0
  streamPaused.value = false
  manualStreamPause.value = false
  preview.value = null
  busyState.value = false
  conversationId.value = ''
  conversations.value = []
  conversation.value = null
  nextBefore.value = null
  cost.value = null
  error.value = ''
  pendingKey.value = null
  query.value = ''
  readOriginalActions()
  legacyCreate.value = false
  createKey.value = null
  creationIntent = null
  notSubmittedCreate.value = null
  try {
    const key = createStorageKey()
    createKey.value = key ? sessionStorage.getItem(key) : null
    if (!createKey.value && props.environmentId && code.value) {
      createKey.value = sessionStorage.getItem(`sand-ai-knowledge-conversation-create:${props.environmentId}:${code.value}`)
      legacyCreate.value = createKey.value !== null
    }
  } catch (cause) { storageBlocked.value = true; error.value = message(cause) }
}
function createStorageKey(): string | null {
  return adminId.value !== null && props.environmentId && code.value
    ? `sand-ai-knowledge-conversation-create:v1:${adminId.value}:${props.environmentId}:${code.value}` : null
}
function clearCreateKey(): void {
  const key = createStorageKey()
  if (key && !legacyCreate.value) {
    const saved = sessionStorage.getItem(key)
    if (saved !== null && saved !== createKey.value) throw new Error('原创建记录已变化，原号保留')
    sessionStorage.removeItem(key)
    if (sessionStorage.getItem(key) !== null) throw new Error('原创建记录未清除，原号保留')
  }
  if (legacyCreate.value && props.environmentId && code.value) {
    const old = `sand-ai-knowledge-conversation-create:${props.environmentId}:${code.value}`
    const saved = sessionStorage.getItem(old)
    if (saved !== null && saved !== createKey.value) throw new Error('旧原创建记录已变化，原号保留')
    sessionStorage.removeItem(old)
    if (sessionStorage.getItem(old) !== null) throw new Error('旧原创建记录未清除，原号保留')
  }
  legacyCreate.value = false
  createKey.value = null
  creationIntent = null
  notSubmittedCreate.value = null
}
async function recoverCreated(): Promise<void> {
  const key = createKey.value
  if (!ready.value || !key || busy.value) return
  const flight = Symbol('knowledge-conversation-operation')
  writeFlight.value = flight
  try {
    const originalContext = { credential: apiKey.value, code: code.value }
    const legacy = legacyCreate.value
    let confirmedCreated = false
    const current = epoch; busyState.value = true; error.value = ''
    try {
      const created = await conversationApi.readCreated(originalContext.credential, originalContext.code, key)
      if (current !== epoch || createKey.value !== key) return
      confirmedCreated = true
      creationIntent = null
      notSubmittedCreate.value = null
      clearCreateKey()
      conversationId.value = created.conversation_id
      await readCurrent(current)
      await loadListCurrent(current)
    } catch (cause) {
      if (current === epoch) {
        if (clearProtectedResult(cause, true)) {
          // A denied/absent original GET clears private content, never the original creation intent.
          if (!confirmedCreated) {
            createKey.value = key
            legacyCreate.value = legacy
          }
        }
        error.value = confirmedCreated
          ? `${message(cause)}。原创建已确认，历史或原号存储尚未读完；请只读找回，不再次创建。`
          : `${message(cause)}。原创建结果未确认，保留原号，不重新提交。`
      }
    } finally { if (current === epoch) busyState.value = false }
  } finally {
    if (writeFlight.value === flight) writeFlight.value = null
  }
}
function message(cause: unknown): string { return cause instanceof Error ? cause.message : '请求失败' }
function sequenceLabel(sequences: number[]): string {
  return sequences.length ? sequences.map(sequence => `第 ${sequence} 轮`).join('、') : '无'
}
function executionStateLabel(state: string): string {
  const labels: Record<string, string> = {
    preparing: '准备本轮请求',
    provider_pending: '模型处理中，等待结果',
    outcome_unknown: '模型调用结果尚不确定，请核查原轮次',
    finalizing: '正在封存结果和费用',
    recovery_required: '需要按原请求恢复',
    completed: '本轮已完成',
    cancelled: '本轮已取消',
    failed: '本轮失败'
  }
  return labels[state] ?? '状态待确认'
}
async function loadList(before?: string): Promise<void> {
  if (!ready.value || busy.value) return
  const current = epoch; busyState.value = true; error.value = ''
  try {
    const result = await conversationApi.list(apiKey.value, code.value, before)
    if (current !== epoch) return
    conversations.value = before ? [...conversations.value, ...result.conversations] : result.conversations
    nextBefore.value = result.next_before
  } catch (cause) { if (current === epoch && !clearProtectedResult(cause, true)) error.value = message(cause) }
  finally { if (current === epoch) busyState.value = false }
}
async function create(): Promise<void> {
  if (!ready.value || busy.value || storageBlocked.value || legacyCreate.value) return
  if (createKey.value) { await recoverCreated(); return }
  const storage = createStorageKey()
  if (!storage) return
  try {
    const existing = sessionStorage.getItem(storage)
    if (existing !== null) { createKey.value = existing; await recoverCreated(); return }
  } catch (cause) { storageBlocked.value = true; error.value = message(cause); return }
  const key = newRuntimeRequestId('knowledge-conversation')
  createKey.value = key
  notSubmittedCreate.value = key
  creationIntent = { key, epoch, storage, credential: apiKey.value, code: code.value }
  await submitUnsentCreation()
}
async function submitUnsentCreation(): Promise<void> {
  const original = creationIntent
  if (!original || !ready.value || busy.value || original.epoch !== epoch
    || original.key !== createKey.value || notSubmittedCreate.value !== original.key
    || original.storage !== createStorageKey() || original.credential !== apiKey.value || original.code !== code.value) return
  const flight = Symbol('knowledge-conversation-operation')
  writeFlight.value = flight
  try {
    readOriginalActions()
    if (storageBlocked.value) return
    const current = epoch
    busyState.value = true; error.value = ''
    try {
      const existing = sessionStorage.getItem(original.storage)
      if (existing !== null && existing !== original.key) throw new Error('原创建记录已变化')
      sessionStorage.setItem(original.storage, original.key)
      if (sessionStorage.getItem(original.storage) !== original.key) throw new Error('创建原号读回失败')
    } catch (cause) {
      storageBlocked.value = true; busyState.value = false
      error.value = `无法保存原创建号，本页 POST 尚未开始：${message(cause)}`
      return
    }
    if (current !== epoch || original.credential !== apiKey.value || original.code !== code.value) return
    // This proof only describes this page before its first POST; neither a reload nor a 404 recreates it.
    let confirmedCreated = false
    creationIntent = null
    notSubmittedCreate.value = null
    try {
      const created = await conversationApi.create(original.credential, original.code, original.key)
      if (current !== epoch || createKey.value !== original.key) return
      confirmedCreated = true
      clearCreateKey()
      conversationId.value = created.conversation_id
      await readCurrent(current)
      await loadListCurrent(current)
    } catch (cause) {
      if (current === epoch) {
        if (clearProtectedResult(cause, true) && !confirmedCreated) createKey.value = original.key
        error.value = confirmedCreated
          ? `${message(cause)}。原创建已确认，历史或原号存储尚未读完；请只读找回，不再次创建。`
          : `${message(cause)}。只读找回原创建结果，不再次发送。`
      }
    } finally { if (current === epoch) busyState.value = false }
  } finally {
    if (writeFlight.value === flight) writeFlight.value = null
  }
}
async function loadListCurrent(current: number): Promise<void> {
  if (current !== epoch) return
  const credential = apiKey.value; const originalCode = code.value
  const result = await conversationApi.list(credential, originalCode)
  if (current === epoch) { conversations.value = result.conversations; nextBefore.value = result.next_before }
}
async function readCurrent(current: number): Promise<void> {
  if (current !== epoch) return
  const original = { credential: apiKey.value, code: code.value, conversation: conversationId.value }
  const serial = ++readEpoch
  let result: KnowledgeConversation
  try {
    result = await conversationApi.read(original.credential, original.code, original.conversation)
  } catch (cause) {
    if (current !== epoch || serial !== readEpoch) return
    if (current === epoch) {
      if (clearProtectedResult(cause, true)) throw cause
      stopPendingStream()
      streamTransport.value = isConnectionFailure(cause) ? 'disconnected' : 'failed'
      if (!isConnectionFailure(cause)) preview.value = null
    }
    throw cause
  }
  if (current === epoch && serial === readEpoch) {
    const earlier = conversation.value?.conversation_id === result.conversation_id
      ? conversation.value.turns.filter(turn => result.turns.length && turn.sequence_no < result.turns[0].sequence_no) : []
    const turns = result.turns.map(turn => readableTurn(turn, result.conversation_id))
    conversation.value = earlier.length
      ? { ...result, turns: [...earlier, ...turns], next_before_sequence: conversation.value?.next_before_sequence ?? null }
      : { ...result, turns }
    if (preview.value && !result.turns.some(turn =>
      turn.sequence_no === preview.value?.sequenceNo && turn.state === 'pending' && turn.stream_segments
      && !['outcome_unknown', 'recovery_required'].includes(turn.execution_state)
    )) preview.value = null
    refreshFailures = 0
    schedulePendingRefresh()
  }
}
async function read(): Promise<void> {
  if (!ready.value || !conversationId.value || busy.value) return
  const current = epoch; busyState.value = true; error.value = ''
  try { await readCurrent(current) }
  catch (cause) { if (current === epoch && !clearProtectedResult(cause, true)) error.value = message(cause) }
  finally { if (current === epoch) busyState.value = false }
}
async function continuePreview(sequenceNo: number): Promise<void> {
  if (sourceUnavailable.value || !ready.value || !conversationId.value || busy.value || ['connecting', 'reading'].includes(streamTransport.value)
    || !conversation.value?.turns.some(turn => turn.sequence_no === sequenceNo
      && turn.state === 'pending'
      && !['outcome_unknown', 'recovery_required'].includes(turn.execution_state))) return
  const current = epoch
  busyState.value = true
  error.value = ''
  stopPendingRefresh()
  try {
    await readCurrent(current)
    if (current !== epoch) return
    const turn = conversation.value?.turns.find(item => item.sequence_no === sequenceNo)
    if (!turn || turn.state !== 'pending'
      || ['outcome_unknown', 'recovery_required'].includes(turn.execution_state)) return
    manualStreamPause.value = false
    resetStreamFailures()
    stopPendingRefresh()
    startPendingStream(sequenceNo)
  } catch (cause) {
    if (current === epoch && !clearProtectedResult(cause, true)) error.value = message(cause)
  } finally {
    if (current === epoch) busyState.value = false
  }
}
function selectConversation(load = true): void {
  streamCursors.clear()
  clearSource()
  stopPendingRefresh()
  stopTurnLookup()
  stopPendingStream()
  refreshFailures = 0
  streamRetryAfter = 0
  streamFailures = 0
  streamPaused.value = false
  manualStreamPause.value = false
  preview.value = null
  ++epoch; ++costEpoch; busyState.value = false; conversation.value = null; cost.value = null; error.value = ''
  query.value = ''
  restorePendingKey()
  if (load) void read()
}
async function answer(): Promise<void> {
  if (sourceUnavailable.value || !ready.value || !conversationId.value || !conversation.value || busy.value || pendingKey.value || targetLocked.value
    || conversation.value?.state === 'closed' || conversation.value?.turns.some(turn => turn.state === 'pending')
    || !query.value.trim() || !model.value.trim()) return
  const flight = Symbol('knowledge-conversation-operation')
  writeFlight.value = flight
  try {
    const originalContext = { credential: apiKey.value, code: code.value, conversation: conversationId.value }
    const current = epoch
    const sentDraftRevision = draftRevision
    const question = query.value.trim()
    const chosenModel = model.value.trim()
    const selectedRerank = rerank.value
    const selectedStreamSegments = streamSegments.value
    const key = newRuntimeRequestId('knowledge-turn')
    // Persist before POST; failed or missing responses cannot unlock a new request key.
    try { rememberPendingKey(key) }
    catch (cause) { error.value = `无法保存原请求号，本次未发送：${message(cause)}`; return }
    busyState.value = true
    error.value = ''
    cost.value = null
    ++costEpoch
    streamFailures = 0
    streamPaused.value = false
    manualStreamPause.value = false
    streamRetryAfter = 0
    // Execution-state streaming is available even when paragraph previews are disabled.
    locateSubmittedTurn(key, current, originalContext.conversation)
    try {
      await conversationApi.answer(originalContext.credential, originalContext.code, originalContext.conversation, question, chosenModel,
        selectedRerank, selectedStreamSegments, key)
      if (current !== epoch) return
      if (draftRevision === sentDraftRevision) query.value = ''
      await readCurrent(current)
    } catch (cause) {
      if (current !== epoch || clearProtectedResult(cause, true)) return
      if (cause instanceof SandAiRuntimeError) {
        stopTurnLookup()
        stopPendingStream()
        preview.value = null
      }
      error.value = message(cause)
      try { await readCurrent(current) } catch { /* Preserve the submission error. */ }
    } finally { if (current === epoch) busyState.value = false }
  } finally {
    if (writeFlight.value === flight) writeFlight.value = null
  }
}
async function resumeTurn(sequenceNo: number): Promise<void> {
  if (!ready.value || !conversationId.value || busy.value || targetLocked.value || !Number.isInteger(sequenceNo) || sequenceNo < 1) return
  const flight = Symbol('knowledge-conversation-operation')
  writeFlight.value = flight
  try {
    const originalContext = { credential: apiKey.value, code: code.value, conversation: conversationId.value }
    const current = epoch; busyState.value = true; error.value = ''
    try {
      await conversationApi.resume(originalContext.credential, originalContext.code, originalContext.conversation, sequenceNo)
      if (current === epoch) await readCurrent(current)
    } catch (cause) {
      if (current === epoch) {
        if (clearProtectedResult(cause, true)) return
        error.value = message(cause)
        try { await readCurrent(current) } catch { /* Keep the original recovery error visible. */ }
      }
    } finally { if (current === epoch) busyState.value = false }
  } finally {
    if (writeFlight.value === flight) writeFlight.value = null
  }
}
async function cancelTurn(sequenceNo: number): Promise<void> {
  if (!ready.value || !conversationId.value || busy.value || targetLocked.value || !Number.isSafeInteger(sequenceNo) || sequenceNo < 1
    || !conversation.value?.turns.some(turn => turn.sequence_no === sequenceNo && turn.state === 'pending' && turn.execution_state === 'preparing')) return
  const flight = Symbol('knowledge-conversation-operation')
  writeFlight.value = flight
  try {
    const credential = apiKey.value
    let entry: OriginalAction
    try {
      entry = newOriginalAction('cancel', null, sequenceNo)
      saveOriginalActions([...originalActions.value, entry])
    } catch (cause) { error.value = `无法保存原取消目标，本次未发送：${message(cause)}`; return }
    const current = epoch; busyState.value = true; error.value = ''
    try {
      await conversationApi.cancel(credential, entry.code, entry.conversation, sequenceNo)
    } catch (cause) { if (current === epoch) error.value = `取消结果仍未知：${message(cause)}` }
    finally { if (current === epoch) busyState.value = false }
    if (current === epoch) await recoverOriginalAction(entry, flight)
  } finally {
    if (writeFlight.value === flight) writeFlight.value = null
  }
}
async function close(): Promise<void> {
  if (!ready.value || !conversationId.value || busy.value || targetLocked.value || !conversation.value || conversation.value.state === 'closed') return
  const flight = Symbol('knowledge-conversation-operation')
  writeFlight.value = flight
  try {
    const credential = apiKey.value
    let entry: OriginalAction
    try {
      entry = newOriginalAction('close', null, null)
      saveOriginalActions([...originalActions.value, entry])
    } catch (cause) { error.value = `无法保存原关闭目标，本次未发送：${message(cause)}`; return }
    const current = epoch; busyState.value = true; error.value = ''
    try { await conversationApi.close(credential, entry.code, entry.conversation) }
    catch (cause) { if (current === epoch) error.value = `关闭结果仍未知：${message(cause)}` }
    finally { if (current === epoch) busyState.value = false }
    if (current === epoch) await recoverOriginalAction(entry, flight)
  } finally {
    if (writeFlight.value === flight) writeFlight.value = null
  }
}
async function loadEarlier(): Promise<void> {
  if (!conversation.value?.next_before_sequence || busy.value) return
  const current = epoch; busyState.value = true; error.value = ''
  try {
    const older = await conversationApi.read(apiKey.value, code.value, conversationId.value, conversation.value.next_before_sequence)
    if (current !== epoch || !conversation.value) return
    const turns: KnowledgeTurn[] = [...older.turns.map(turn => readableTurn(turn, older.conversation_id)), ...conversation.value.turns]
    conversation.value = { ...conversation.value, turns, next_before_sequence: older.next_before_sequence }
  } catch (cause) { if (current === epoch && !clearProtectedResult(cause, true)) error.value = message(cause) }
  finally { if (current === epoch) busyState.value = false }
}
async function loadCost(requestId: string): Promise<void> {
  const current = epoch; const serial = ++costEpoch; error.value = ''; cost.value = null
  try { const result = await conversationApi.cost(apiKey.value, requestId); if (current === epoch && serial === costEpoch) cost.value = result }
  catch (cause) { if (current === epoch && serial === costEpoch && !clearProtectedResult(cause)) error.value = message(cause) }
}
function clearSource(): void {
  ++sourceEpoch
  sourceVisible.value = false
  sourceBusy.value = false
  sourceTarget.value = null
  sourceBody.value = null
  sourceError.value = ''
}
function setSourceVisible(visible: boolean): void {
  if (!visible) clearSource()
}
async function openSource(citation: unknown): Promise<void> {
  const reference = knowledgeSourceReference(citation)
  if (!reference || !ready.value || busy.value || reference.knowledge_base_code !== code.value) return
  const current = epoch
  const serial = ++sourceEpoch
  const token = apiKey.value
  const selectedConversation = conversationId.value
  const environment = props.environmentId
  const isCurrent = (): boolean => current === epoch && serial === sourceEpoch
    && sourceVisible.value && token === apiKey.value && code.value === reference.knowledge_base_code
    && selectedConversation === conversationId.value && environment === props.environmentId
  sourceBody.value = null
  sourceError.value = ''
  sourceTarget.value = reference
  sourceVisible.value = true
  sourceBusy.value = true
  try {
    const body = await sourceApi.read(token, reference)
    if (isCurrent()) sourceBody.value = body
  } catch (cause) {
    if (!isCurrent()) return
    if (cause instanceof SandAiRuntimeError && (cause.httpStatus === 401 || cause.httpStatus === 403
      || cause.httpStatus === 404 || cause.errorCode === 'SAND_AI_RESOURCE_NOT_FOUND'
      || scopeLossCodes.has(cause.errorCode ?? ''))) {
      clearSourceContent()
      error.value = '当前会话来源已不可读，私有内容已清除；原目标和费用记录保留，不会重新发送。'
      return
    }
    sourceBody.value = null
    sourceError.value = message(cause)
  } finally {
    if (isCurrent()) sourceBusy.value = false
  }
}
watch([query, model, rerank, streamSegments], () => { ++draftRevision }, { flush: 'sync' })
watch([apiKey, () => props.publications], reset, { flush: 'sync' })
watch([code, adminId, () => userStore.isLogin, () => userStore.accessToken, () => props.environmentId], () => {
  apiKey.value = ''
  reset()
}, { flush: 'sync', immediate: true })
onUnmounted(() => { ++epoch; creationIntent = null; notSubmittedCreate.value = null; clearSource(); stopPendingRefresh(); stopTurnLookup(); stopPendingStream(); preview.value = null })
</script>

<style scoped>
.conversation-panel { margin-top: 24px; }
.hint { color: var(--el-text-color-secondary); font-size: 13px; }
.notice, .turn { margin: 12px 0; }
.cost { white-space: pre-wrap; word-break: break-all; }
.summary-text { white-space: pre-wrap; }
.segment-preview { white-space: pre-wrap; }
.source-text { white-space: pre-wrap; overflow-wrap: anywhere; max-height: 55vh; overflow: auto; }
</style>
