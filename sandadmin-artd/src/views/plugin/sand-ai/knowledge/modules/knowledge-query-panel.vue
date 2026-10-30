<template>
  <section class="knowledge-query">
    <h3>应用知识检索</h3>
    <p class="hint">使用消费环境的应用凭证查询已开放资料。有已激活向量索引时执行关键词与向量混合检索，查询 Embedding 按实际调用计费；开启重排会另产生所选服务的调用费用。</p>
    <ElForm label-width="110px" @submit.prevent>
      <RuntimeApiKeyField v-model="apiKey" :persist-in-session="false" placeholder="填写所选消费环境的运行面 Bearer（仅本页面）" />
      <ElFormItem label="开放关系">
        <ElSelect v-model="code" filterable :disabled="busy || !!pending" placeholder="选择当前消费环境的知识库" class="query-input">
          <ElOption v-for="item in available" :key="item.id" :label="publicationLabel(item)" :value="item.code" />
        </ElSelect>
        <ElButton :loading="versionsBusy" :disabled="busy || props.environmentId === null" @click="loadVersions">刷新当前发布版本</ElButton>
        <span v-if="!available.length" class="hint">请先选择消费环境并开放知识库。</span>
      </ElFormItem>
      <ElAlert v-if="versionsError" type="error" :title="versionsError" :closable="false" class="notice" />
      <p v-if="selectedVersion" class="hint">当前发布预览：{{ selectedVersion.owner_name }} · 第 {{ selectedVersion.version_no }} 版。实际查询版本由服务端受理时固定，按原结果读取；预览不锁定该版本。刷新版本会清除已显示的来源和费用，原请求仍保留。</p>
      <ElFormItem label="查询操作">
        <ElSelect v-model="operationMode" :disabled="busy || !!pending" class="query-input">
          <ElOption label="新检索" value="new" />
          <ElOption label="只读找回原查询" value="recover" />
        </ElSelect>
      </ElFormItem>
      <template v-if="operationMode === 'recover'">
        <p class="hint">请使用此前保留的原请求号、原查询内容、返回数量、重排方式和原模型代码。参数必须与原请求一致；不要猜测。找回只读取原结果，不重新检索或调用模型。</p>
        <ElFormItem label="原请求号">
          <ElInput v-model="recoveryKey" :disabled="busy || !!pending" maxlength="512" class="query-input" placeholder="粘贴此前保留的完整请求号" />
        </ElFormItem>
        <p v-if="recoveryKey && !validRecoveryKey" class="hint">原请求号须为 1–512 个可见 ASCII 字符，不能包含空格或换行。</p>
      </template>
      <ElFormItem v-if="currentRequestKey" :label="pending ? '当前原请求号' : '已确认请求号'">
        <ElInput :model-value="currentRequestKey" readonly class="query-input" aria-label="可选中保留的原查询请求号" />
        <span class="hint">仅在本页展示，请自行保留；页面不会持久保存原查询或凭证。</span>
      </ElFormItem>
      <ElFormItem label="检索内容">
        <ElInput v-model="query" maxlength="500" show-word-limit :disabled="parametersLocked" placeholder="输入要查找的内容" class="query-input" @keyup.enter="search" />
      </ElFormItem>
      <ElFormItem label="最多返回">
        <ElInputNumber v-model="limit" :min="1" :max="rerankMode === 'off' ? 20 : 10" :precision="0" :disabled="parametersLocked" />
      </ElFormItem>
      <ElFormItem label="重排方式">
        <ElSelect v-model="rerankMode" :disabled="parametersLocked" class="query-input">
          <ElOption label="关闭重排" value="off" />
          <ElOption label="聊天模型调整顺序" value="chat" />
          <ElOption label="专业相关性重排" value="professional" />
        </ElSelect>
      </ElFormItem>
      <template v-if="rerankMode !== 'off'">
        <ElFormItem label="重排模型">
          <ElInput v-if="operationMode === 'recover'" v-model="rerankModel" :disabled="parametersLocked" class="query-input" placeholder="填写原请求使用的完整模型代码，无需当前目录仍包含该模型" />
          <ElSelect v-else v-model="rerankModel" filterable :disabled="busy || !!pending || modelsBusy" :placeholder="rerankMode === 'professional' ? '读取并选择已授权的专业重排模型' : '读取并选择已授权的聊天模型'" class="query-input">
            <ElOption v-for="item in models" :key="item.code" :label="`${item.name} (${item.code})`" :value="item.code" />
          </ElSelect>
          <ElButton v-if="operationMode === 'new'" :loading="modelsBusy" :disabled="!ready || busy || !!pending" @click="loadModels">读取可用模型</ElButton>
        </ElFormItem>
        <p v-if="operationMode === 'new'" class="hint">重排会将来源摘录发送至所选服务，可能产生费用，最多返回 10 条。少于 2 条来源时不调用重排服务。{{ rerankMode === 'professional' ? '专业目录按当前知识库的 knowledge.query 授权读取。' : '聊天模型目录需 model.read 权限。' }}</p>
        <p v-if="operationMode === 'new' && rerankMode === 'professional'" class="hint">软预算的未定费用调用遵循已配置策略，返回真实用量后结算；硬预算无法证明费用上限时会拒绝发送。</p>
        <ElAlert v-if="operationMode === 'new' && modelsError" type="error" :title="modelsError" :closable="false" class="notice" />
        <p v-if="operationMode === 'new' && modelsLoaded && !models.length" class="hint">当前应用没有可用的{{ rerankMode === 'professional' ? '专业重排' : '聊天' }}模型，请在模型与能力配置中发布对应服务，并核对消费环境授权。</p>
        <p v-if="typeof limit === 'number' && limit > 10" class="hint">请将最多返回数量改为 10 或更少后检索。</p>
      </template>
      <ElFormItem>
        <ElButton type="primary" :loading="busy" :disabled="!canSearch" @click="search">{{ pending || operationMode === 'recover' ? '只读找回原查询' : '检索知识库' }}</ElButton>
      </ElFormItem>
    </ElForm>
    <ElAlert v-if="pending" type="warning" :closable="false" title="本次结果尚未确认，原查询、重排设置和请求标识已保留。" description="请保持原消费环境、开放关系和凭证，只读找回原结果。找回不会继续模型调用或重新检索派发；离开页面会丢失本页恢复信息；请先自行保留原请求号与完整原参数，之后可选择只读找回原查询。" class="notice" />
    <p v-if="pending" class="hint">{{ recoveryState }}</p>
    <p v-if="pending" class="hint">原请求设置：{{ originalRerankLabel(pending.options) }} · 最多返回 {{ pending.limit }} 条</p>
    <ElButton v-if="canCorrectRecovery && !editingRecoveryParameters" :disabled="busy" @click="editRecoveryParameters">编辑原参数后按同号找回</ElButton>
    <p v-if="editingRecoveryParameters" class="hint">正在修正手工找回的原参数。原请求号、知识库、消费环境和凭证保持固定；再次点击“只读找回原查询”只读取同号结果。</p>
    <ElAlert v-if="error" type="error" :title="error" :closable="false" class="notice" />
    <template v-if="result">
      <p>知识库 {{ result.knowledge_base.code }} · 第 {{ result.knowledge_base.version }} 版 · 查询：{{ result.query }}</p>
      <p class="hint">{{ result.embedding_invocation ? '关键词与向量混合检索；分数为融合排序分数，不是置信度。' : '本次仅关键词检索；没有已激活的向量索引，未产生查询 Embedding 调用。分数为关键词相关性分数。' }}</p>
      <p class="hint">{{ rerankLabel(result.rerank_state, result.rerank_mode) }}</p>
      <ElAlert v-if="result.rerank_mode === 'professional' && result.budget"
        :type="result.budget.state === 'settled' ? 'success' : result.budget.state === 'released' ? 'info' : 'warning'"
        :title="budgetLabel(result.budget.state)" :closable="false" class="notice" />
      <p v-if="result.rerank_mode === 'professional' && result.budget?.warnings.length" class="hint">预算提醒：{{ result.budget.warnings.map(item => item.code).join('、') }}</p>
      <ElTable :data="result.citations" row-key="source_block_id" empty-text="没有命中来源块">
        <ElTableColumn type="index" label="排名" width="70" />
        <ElTableColumn prop="score" label="原检索分数" width="130" />
        <ElTableColumn v-if="result.rerank_mode === 'professional'" label="专业相关性分数" width="160"><template #default="{ row }">{{ relevanceScore(row) }}</template></ElTableColumn>
        <ElTableColumn label="文件 / 来源块" width="150"><template #default="{ row }">{{ row.file_id }} / {{ row.source_block_id }}</template></ElTableColumn>
        <ElTableColumn prop="excerpt" label="来源摘录" min-width="260" />
        <ElTableColumn label="定位" min-width="240"><template #default="{ row }">{{ sourceLocation(row) }}</template></ElTableColumn>
        <ElTableColumn label="来源正文" width="110"><template #default="{ row }"><ElButton link type="primary" :disabled="busy" @click="openSource(row)">查看来源</ElButton></template></ElTableColumn>
      </ElTable>
    </template>
    <template v-if="queryUsage">
      <div v-if="queryUsage.embedding_usage" class="query-cost">
        <p>查询 Embedding 调用 {{ queryUsage.embedding_invocation?.id }}</p>
        <ElButton :loading="costBusy" :disabled="busy" @click="loadCost('embedding')">查看 / 刷新本次费用</ElButton>
        <p class="hint">费用读取需该应用具备 usage.read 权限；未知金额保留为待核实。</p>
        <ElAlert v-if="costError" type="error" :title="costError" :closable="false" class="notice" />
        <template v-if="cost">
          <p>本次计量金额：{{ money(cost.price.metered_amount, cost.price.currency) }} · 计量：{{ usageLabel(cost.usage.state) }}</p>
          <p class="hint">总 Token：{{ cost.tokens.total_tokens ?? '未提供' }} · 对账：{{ reconciliationLabel(cost.reconciliation.state) }}</p>
          <p class="hint">请求标识：{{ cost.request_id }}</p>
        </template>
      </div>
      <div v-if="queryUsage.rerank_usage" class="query-cost">
        <p>重排服务调用 {{ queryUsage.rerank_usage.request_id }}</p>
        <ElButton :loading="rerankCostBusy" :disabled="busy" @click="loadCost('rerank')">查看 / 刷新重排费用</ElButton>
        <p class="hint">费用读取需 usage.read 权限；金额以真实调用计量为准，未知金额保留为待核实。</p>
        <ElAlert v-if="rerankCostError" type="error" :title="rerankCostError" :closable="false" class="notice" />
        <template v-if="rerankCost">
          <p>重排计量金额：{{ money(rerankCost.price.metered_amount, rerankCost.price.currency) }} · 计量：{{ usageLabel(rerankCost.usage.state) }}</p>
          <p v-if="queryUsage.rerank_mode === 'professional'" class="hint">专业计量：{{ professionalMeteringLabel }} · 对账：{{ reconciliationLabel(rerankCost.reconciliation.state) }}</p>
          <p v-else class="hint">总 Token：{{ rerankCost.tokens.total_tokens ?? '未提供' }} · 对账：{{ reconciliationLabel(rerankCost.reconciliation.state) }}</p>
          <p class="hint">请求标识：{{ rerankCost.request_id }}</p>
        </template>
      </div>
    </template>
    <ElDialog :model-value="sourceVisible" title="知识来源正文" width="min(760px, 94vw)" destroy-on-close @update:model-value="setSourceVisible">
      <p v-if="sourceTarget">{{ sourceTarget.knowledge_base_code }} · 第 {{ sourceTarget.knowledge_base_version }} 版 · 文件 {{ sourceTarget.file_id }} · 解析 {{ sourceTarget.parse_id }} · 来源块 {{ sourceTarget.source_block_id }}</p>
      <p v-if="sourceBusy" role="status">正在核对当前授权并读取来源…</p>
      <ElAlert v-if="sourceError" type="error" :title="sourceError" :closable="false" />
      <template v-if="sourceBody">
        <p class="hint">来源位置：{{ sourceLocation(sourceBody) }}</p>
        <pre class="source-text" tabindex="0" aria-label="知识来源正文">{{ sourceBody.content }}</pre>
      </template>
      <template #footer><ElButton @click="clearSource">关闭</ElButton></template>
    </ElDialog>
  </section>
</template>

<script setup lang="ts">
import { ElMessageBox } from 'element-plus'
import { computed, nextTick, onUnmounted, ref, watch } from 'vue'
import RuntimeApiKeyField from '../../components/RuntimeApiKeyField.vue'
import queryApi, { professionalRerankMetering, type KnowledgeQueryResult, type KnowledgeQueryRerank, type KnowledgeQueryRerankState, type KnowledgeQueryBudget } from '../../api/knowledgeQuery'
import sourceApi, { knowledgeSourceReference, type KnowledgeSourceReference, type KnowledgeSourceBody } from '../../api/knowledgeSource'
import chatApi from '../../api/chat'
import conversationApi from '../../api/knowledgeConversation'
import { newRuntimeRequestId, SandAiRuntimeError } from '../../api/runtime'
import publicationApi, { type KnowledgePublication, type ConsumerKnowledgePublication } from '../../api/knowledgePublication'
import type { SandAiInvocationCost } from '../../api/types'

const props = defineProps<{ publications: KnowledgePublication[]; environmentId: number | null }>()
function sourceLocation(source: Pick<KnowledgeSourceBody, 'locator_type' | 'locator'>): string {
  if (source.locator_type !== 'line') return `${source.locator_type} · ${JSON.stringify(source.locator)}`
  const { line, char_start: start, char_end: end, part } = source.locator
  if (typeof line !== 'number' || !Number.isSafeInteger(line) || line < 1) return '原行位置无法确认'
  const positions = [`第 ${line} 行`]
  if (typeof start === 'number' && Number.isSafeInteger(start) && start >= 1
    && typeof end === 'number' && Number.isSafeInteger(end) && end >= start) {
    positions.push(`字符 ${start}–${end}`)
  } else {
    positions.push('字符范围未提供或无法确认')
  }
  if (typeof part === 'number' && Number.isSafeInteger(part) && part >= 2) positions.push(`原分片 ${part}`)
  return positions.join(' · ')
}
const available = computed(() => props.publications.filter(item => item.state === 'published' && item.consumer_environment_id === props.environmentId))
const versions = ref<ConsumerKnowledgePublication[]>([])
const versionsBusy = ref(false)
const versionsError = ref('')
let versionsEpoch = 0
const selectedVersion = computed(() => versions.value.find(item => item.code === code.value && available.value.some(publication => publication.id === item.id && publication.knowledge_base_id === item.knowledge_base_id)))
const apiKey = ref('')
const code = ref('')
const query = ref('')
const operationMode = ref<'new' | 'recover'>('new')
const recoveryKey = ref('')
const confirmedRequestKey = ref('')
const validRecoveryKey = computed(() => /^[\x21-\x7e]{1,512}$/.test(recoveryKey.value))
const limit = ref<number | undefined>(10)
const rerankMode = ref<'off' | 'chat' | 'professional'>('off')
const rerankModel = ref('')
const models = ref<{ code: string; name: string }[]>([])
const modelsBusy = ref(false)
const modelsLoaded = ref(false)
const modelsError = ref('')
let modelsEpoch = 0
const currentBusy = ref(false)
const queryFlight = ref<symbol | null>(null)
const busy = computed(() => currentBusy.value || queryFlight.value !== null)
const error = ref('')
const result = ref<KnowledgeQueryResult | null>(null)
// Original body/key remain only in this page's memory; no private query or credential is persisted.
type OriginalQuery = { environmentId: number; code: string; query: string; limit: number; key: string; options: KnowledgeQueryRerank; origin: 'new_query' | 'manual_recovery' }
const pending = ref<OriginalQuery | null>(null)
const recoveryParameterConflict = ref(false)
const editingRecoveryParameters = ref(false)
const canCorrectRecovery = computed(() => pending.value?.origin === 'manual_recovery' && recoveryParameterConflict.value)
const parametersLocked = computed(() => busy.value || !!pending.value && !editingRecoveryParameters.value)
const currentRequestKey = computed(() => pending.value?.key ?? confirmedRequestKey.value)
const recoveryState = ref('原查询是否完成尚未确认，保留原请求只读找回。')
let pendingBearer = ''
let epoch = 0
const ready = computed(() => !!apiKey.value.trim() && props.environmentId !== null && !versionsBusy.value && !!selectedVersion.value)
const validParameters = computed(() => query.value.trim().length >= 1 && query.value.trim().length <= 500 && typeof limit.value === 'number' && Number.isInteger(limit.value) && limit.value >= 1 && limit.value <= (rerankMode.value !== 'off' ? 10 : 20)
    && (operationMode.value !== 'recover' || validRecoveryKey.value)
    && (rerankMode.value === 'off' || (operationMode.value === 'recover'
      ? !!rerankModel.value.trim() : models.value.some(model => model.code === rerankModel.value))))
const canSearch = computed(() => !busy.value && ready.value && (pending.value
  ? pending.value.environmentId === props.environmentId && pending.value.code === code.value && apiKey.value.trim() === pendingBearer
    && (!editingRecoveryParameters.value || canCorrectRecovery.value && validParameters.value)
  : validParameters.value))
// Usage references are independent of private source content and remain readable under usage.read.
const queryUsage = ref<Pick<KnowledgeQueryResult, 'embedding_invocation' | 'embedding_usage' | 'rerank_usage' | 'rerank_mode'> | null>(null)
const cost = ref<SandAiInvocationCost | null>(null)
const costBusy = ref(false)
const costError = ref('')
const rerankCost = ref<SandAiInvocationCost | null>(null)
const rerankCostBusy = ref(false)
const rerankCostError = ref('')
const professionalMeteringLabel = computed(() => {
  const metering = professionalRerankMetering(rerankCost.value)
  if (!metering) return '待结算（计量单位或计量量尚未确认）'
  const unit = metering.unit === 'rerank_search_units' ? '检索计费单位' : '重排 Token'
  return `${metering.quantity ?? '待结算'} ${unit}`
})
let rerankCostEpoch = 0
const sourceVisible = ref(false)
const sourceBusy = ref(false)
const sourceTarget = ref<KnowledgeSourceReference | null>(null)
const sourceBody = ref<KnowledgeSourceBody | null>(null)
const sourceError = ref('')
let costEpoch = 0
let sourceEpoch = 0

function message(cause: unknown): string {
  if (cause instanceof SandAiRuntimeError && cause.errorCode) return `${cause.message}（${cause.errorCode}）`
  return cause instanceof Error ? cause.message : '请求失败，请稍后重试。'
}
function recoveryLabel(cause: unknown): string {
  if (!(cause instanceof SandAiRuntimeError)) return '原结果尚未确认；只读找回不会重新派发。'
  if (cause.errorCode === 'SAND_AI_DISPATCH_AMBIGUOUS') return '原查询仍在处理或派发结果不明；保留原请求，稍后只读找回。'
  if (cause.errorCode === 'SAND_AI_RESOURCE_NOT_FOUND') return '未找到当前凭证范围内可恢复的原意图或调用；原请求保留，不另建请求。'
  if (['SAND_AI_RECEIPT_INVALID', 'SAND_AI_RECEIPT_UNAVAILABLE'].includes(cause.errorCode ?? '')) return '原结果回执无法读取；原请求保留，不重新调用模型。'
  if (cause.errorCode === 'SAND_AI_INVOCATION_NOT_SUCCEEDED') return '原调用未成功；保留原请求，找回不会续发或重算。'
  if (cause.errorCode === 'SAND_AI_IDEMPOTENCY_CONFLICT') return '原请求号与查询参数不匹配，请核对此前保留的完整原参数；原请求保持锁定，不生成新请求号。'
  if (['SAND_AI_KNOWLEDGE_SOURCE_DRIFTED', 'SAND_AI_KNOWLEDGE_INDEX_UNAVAILABLE'].includes(cause.errorCode ?? '')) return '当前版本、索引、来源或原意图已不一致，已清除展示；原请求保持锁定。'
  return '原查询被拒绝或尚未恢复；请核对当前授权及错误信息，原请求保持锁定。'
}
function clearSource(): void {
  ++sourceEpoch; sourceVisible.value = false; sourceBusy.value = false
  sourceTarget.value = null; sourceBody.value = null; sourceError.value = ''
}
function setSourceVisible(visible: boolean): void { if (!visible) clearSource() }
function clearKnowledgeResult(): void {
  ++epoch; currentBusy.value = false; result.value = null; clearSource()
}
function clearResults(): void {
  clearKnowledgeResult(); ++costEpoch; queryUsage.value = null; cost.value = null
  costBusy.value = false; costError.value = ''
  ++rerankCostEpoch; rerankCost.value = null; rerankCostBusy.value = false; rerankCostError.value = ''
}
function invalidateDenied(cause: unknown, sourceRead = false): void {
  if (!(cause instanceof SandAiRuntimeError)) return
  if (cause.httpStatus === 401 || cause.errorCode === 'SAND_AI_ENVIRONMENT_MISMATCH') {
    clearResults()
  } else if (cause.httpStatus === 403 || sourceRead && cause.httpStatus === 404
    || ['SAND_AI_KNOWLEDGE_SOURCE_INVALID', 'SAND_AI_KNOWLEDGE_SOURCE_DRIFTED', 'SAND_AI_KNOWLEDGE_INDEX_UNAVAILABLE'].includes(cause.errorCode ?? '')) {
    clearKnowledgeResult()
  }
}
async function search(): Promise<void> {
  if (!canSearch.value || props.environmentId === null || typeof limit.value !== 'number') return
  const recovering = pending.value !== null || operationMode.value === 'recover'
  const retained = pending.value
  const options: KnowledgeQueryRerank = rerankMode.value === 'professional'
    ? { rerank: true, rerank_mode: 'professional', model: rerankModel.value }
    : rerankMode.value === 'chat' ? { rerank: true, model: rerankModel.value } : { rerank: false }
  const original: OriginalQuery = retained
    ? editingRecoveryParameters.value && canCorrectRecovery.value
      ? { ...retained, query: query.value.trim(), limit: limit.value, options } : retained
    : { environmentId: props.environmentId, code: code.value, query: query.value.trim(), limit: limit.value,
      key: operationMode.value === 'recover' ? recoveryKey.value : newRuntimeRequestId('knowledge-query'),
      origin: operationMode.value === 'recover' ? 'manual_recovery' : 'new_query', options }
  const previewVersion = selectedVersion.value?.version_no
  const flight = Symbol('knowledge-query')
  queryFlight.value = flight
  clearResults()
  const scope = epoch
  const current = () => scope === epoch && original.environmentId === props.environmentId && original.code === code.value
  currentBusy.value = true; error.value = ''
  try {
    if (!recovering) {
      try {
        await ElMessageBox.confirm(
          `在消费环境 #${original.environmentId} 检索 ${original.code}，最多返回 ${original.limit} 条；${originalRerankLabel(original.options)}。` +
          `当前发布预览为第 ${previewVersion ?? '未确认'} 版，实际版本由服务端受理时固定，按原结果读取。` +
          '存在活动向量索引时会调用查询 Embedding；开启重排且至少命中两条来源时会向所选服务发送摘录，可能产生费用。授权、预算和实际费用由正式调用记录确认，未知金额不代表零。' +
          `确认后只发送这份查询与设置；原请求号 ${original.key}，结果不明时仅按原号只读找回。`,
          '确认知识检索', { type: 'warning', confirmButtonText: '确认检索', cancelButtonText: '取消' }
        )
      } catch { return }
    }
    if (!current()) return
    // A canceled, unsent confirmation never installs a pending request.
    pending.value = original
    const installed = pending.value
    if (!retained) pendingBearer = apiKey.value.trim()
    confirmedRequestKey.value = ''
    recoveryParameterConflict.value = false
    editingRecoveryParameters.value = false
    recoveryState.value = '原查询是否完成尚未确认，保留原请求只读找回。'
    // Render the original key before dispatch; recovery uses the existing read-only endpoint.
    await nextTick()
    if (!current() || pending.value !== installed) return
    const read = recovering ? queryApi.recover : queryApi.query
    const response = await read(pendingBearer, original.environmentId, original.code, original.query, original.limit, original.key, original.options)
    if (!current() || pending.value !== installed) return
    if (response.knowledge_base.code !== original.code || !Number.isSafeInteger(response.knowledge_base.version)
      || response.knowledge_base.version < 1) throw new Error('原结果的知识库或固定实际版本无法确认。')
    result.value = response
    queryUsage.value = { embedding_invocation: response.embedding_invocation, embedding_usage: response.embedding_usage,
      rerank_usage: response.rerank_usage, rerank_mode: response.rerank_mode }
    confirmedRequestKey.value = original.key; pending.value = null; pendingBearer = ''
  } catch (cause) {
    if (current()) {
      recoveryParameterConflict.value = original.origin === 'manual_recovery'
        && cause instanceof SandAiRuntimeError && cause.errorCode === 'SAND_AI_IDEMPOTENCY_CONFLICT'
      recoveryState.value = recoveryParameterConflict.value
        ? '手工找回的参数与原请求不匹配。可点击“编辑原参数后按同号找回”修正完整原参数；不会生成新号或发起新检索。'
        : recoveryLabel(cause)
      invalidateDenied(cause); error.value = `${message(cause)}；原查询标识保留，请只读找回原结果。`
    }
  } finally {
    if (queryFlight.value === flight) queryFlight.value = null
    if (scope === epoch) currentBusy.value = false
  }
}

function editRecoveryParameters(): void {
  if (busy.value || !canCorrectRecovery.value || !pending.value
    || pending.value.environmentId !== props.environmentId || pending.value.code !== code.value
    || apiKey.value.trim() !== pendingBearer) return
  editingRecoveryParameters.value = true
  error.value = ''
}
async function openSource(value: unknown): Promise<void> {
  const target = knowledgeSourceReference(value)
  const current = result.value
  if (!target || !current || props.environmentId === null || busy.value
    || target.knowledge_base_code !== current.knowledge_base.code || target.knowledge_base_version !== current.knowledge_base.version
    || !current.citations.some(item => item.source_block_id === target.source_block_id && item.file_id === target.file_id && item.parse_id === target.parse_id)) return
  clearSource(); sourceTarget.value = target; sourceVisible.value = true; sourceBusy.value = true
  const scope = sourceEpoch; const identity = epoch
  try {
    const body = await sourceApi.read(apiKey.value, target, props.environmentId)
    if (scope === sourceEpoch && identity === epoch) sourceBody.value = body
  } catch (cause) {
    if (scope === sourceEpoch && identity === epoch) {
      invalidateDenied(cause, true)
      if (identity === epoch) sourceError.value = message(cause)
      else error.value = message(cause)
    }
  } finally { if (scope === sourceEpoch) sourceBusy.value = false }
}
async function loadCost(kind: 'embedding' | 'rerank'): Promise<void> {
  const requestId = kind === 'embedding' ? queryUsage.value?.embedding_usage?.request_id : queryUsage.value?.rerank_usage?.request_id
  const selectedCost = kind === 'embedding' ? cost : rerankCost
  const selectedBusy = kind === 'embedding' ? costBusy : rerankCostBusy
  const selectedError = kind === 'embedding' ? costError : rerankCostError
  if (typeof requestId !== 'string' || !requestId || selectedBusy.value || busy.value) return
  const scope = kind === 'embedding' ? ++costEpoch : ++rerankCostEpoch
  const current = () => scope === (kind === 'embedding' ? costEpoch : rerankCostEpoch)
  selectedCost.value = null; selectedBusy.value = true; selectedError.value = ''
  try {
    const body = await conversationApi.cost(apiKey.value, requestId)
    if (!current()) return
    if (body.request_id !== requestId) throw new Error('费用响应不属于本次查询。')
    selectedCost.value = body
  } catch (cause) {
    if (current()) {
      // A usage.read denial does not invalidate the independently authorized knowledge result.
      if (cause instanceof SandAiRuntimeError && cause.httpStatus === 401) { clearResults(); error.value = message(cause) }
      else selectedError.value = message(cause)
    }
  } finally { if (current()) selectedBusy.value = false }
}
async function loadModels(): Promise<void> {
  if (!ready.value || props.environmentId === null || rerankMode.value === 'off' || modelsBusy.value || busy.value || pending.value || operationMode.value === 'recover') return
  const scope = ++modelsEpoch
  modelsBusy.value = true; modelsError.value = ''; modelsLoaded.value = false
  models.value = []; rerankModel.value = ''
  try {
    if (rerankMode.value === 'professional') {
      const response = await queryApi.professionalModels(apiKey.value, props.environmentId, code.value)
      if (scope !== modelsEpoch) return
      models.value = response.map(model => ({ code: model.model, name: model.display_name }))
    } else {
      const response = await chatApi.models(apiKey.value)
      if (scope !== modelsEpoch) return
      models.value = response.data.filter(model => model.type === 'chat' && model.capabilities.includes('chat'))
        .map(model => ({ code: model.code, name: model.name }))
    }
    modelsLoaded.value = true
  } catch (cause) {
    if (scope === modelsEpoch) modelsError.value = message(cause)
  } finally { if (scope === modelsEpoch) modelsBusy.value = false }
}
function publicationLabel(item: KnowledgePublication): string {
  const version = versions.value.find(value => value.id === item.id && value.code === item.code && value.knowledge_base_id === item.knowledge_base_id)
  return version ? `${item.code} · 第 ${version.version_no} 版` : `${item.code} · 当前版本待核对`
}
async function loadVersions(): Promise<void> {
  const scope = ++versionsEpoch
  const environmentId = props.environmentId
  clearResults(); clearModels(); versions.value = []; versionsError.value = ''; versionsBusy.value = false
  if (environmentId === null) return
  versionsBusy.value = true
  const current = () => scope === versionsEpoch && environmentId === props.environmentId
  try {
    const items: ConsumerKnowledgePublication[] = []
    for (let page = 1; ; ++page) {
      const response = await publicationApi.consumer(environmentId, page, 100)
      if (!current()) return
      items.push(...response.data)
      if (items.length >= response.total) break
      if (!response.data.length) throw new Error('当前发布版本列表不完整，请重新读取。')
    }
    if (current()) versions.value = items
  } catch (cause) {
    if (current()) versionsError.value = message(cause)
  } finally { if (current()) versionsBusy.value = false }
}
function clearModels(): void {
  ++modelsEpoch; models.value = []; if (!pending.value) rerankModel.value = ''; modelsBusy.value = false
  modelsLoaded.value = false; modelsError.value = ''
}
function originalRerankLabel(options: KnowledgeQueryRerank): string {
  if (!options.rerank) return '关闭重排'
  return `${options.rerank_mode === 'professional' ? '专业相关性重排' : '聊天模型调整顺序'} · 模型 ${options.model}`
}
function relevanceScore(value: unknown): number | string {
  if (typeof value !== 'object' || value === null || !('rerank_relevance_score' in value)) return '未提供'
  const score = value.rerank_relevance_score
  return typeof score === 'number' && Number.isFinite(score) ? score : '未提供'
}
function rerankLabel(state: KnowledgeQueryRerankState, mode?: 'professional'): string {
  if (mode === 'professional' && state === 'reordered') return '已按专业重排服务返回的相关性分数调整顺序；分数不是置信度，原检索分数保留。' 
  return { not_requested: '本次未开启模型重排。', no_sources: '没有命中来源，未调用重排模型。',
    single_source: '仅命中一条来源，未调用重排模型。', reordered: '已按所选模型重排；原排序分数不变，不表示模型置信度。',
    invalid_output: '重排输出未通过来源核对，保留原检索顺序；模型已调用，可查看真实费用。' }[state]
}
function budgetLabel(state: KnowledgeQueryBudget['state']): string {
  return { soft_cost_pending: '费用待结算（软预算提醒）', held: '费用待结算，预算保留中。',
    settled: '已按实际费用结算。', released: '未发送／已释放。' }[state]
}
function money(amount: string | null, currency: string | null): string { return amount !== null && currency ? `${amount} ${currency}` : '待核实' }
function usageLabel(state: SandAiInvocationCost['usage']['state']): string {
  return { measured: '已计量', unmeasured: '未计量', not_dispatched: '未派发', failed: '失败', ambiguous: '结果不明', pending: '处理中', missing_event: '待补计量记录' }[state]
}
function reconciliationLabel(state: SandAiInvocationCost['reconciliation']['state']): string {
  return { not_applicable: '无需对账', held: '暂挂', pending: '待处理', unreconciled: '未对账', reconciled: '已对账', discrepancy: '有差异' }[state]
}
watch([apiKey, () => props.environmentId], () => {
  clearResults(); query.value = ''; pending.value = null; pendingBearer = ''; error.value = ''
  recoveryKey.value = ''; confirmedRequestKey.value = ''
  recoveryParameterConflict.value = false; editingRecoveryParameters.value = false
  recoveryState.value = '原查询是否完成尚未确认，保留原请求只读找回。'
}, { flush: 'sync' })
watch(code, () => { clearResults(); confirmedRequestKey.value = ''; error.value = '' }, { flush: 'sync' })
watch(available, values => {
  clearResults(); clearModels()
  void loadVersions()
  if (code.value && !values.some(item => item.code === code.value)) { clearResults(); if (!pending.value) code.value = '' }
}, { flush: 'sync', immediate: true })
watch(operationMode, () => { clearResults(); confirmedRequestKey.value = ''; error.value = ''; clearModels() }, { flush: 'sync' })
watch([apiKey, code, () => props.environmentId, rerankMode], clearModels, { flush: 'sync' })
onUnmounted(() => { ++versionsEpoch; clearResults(); clearModels() })
</script>

<style scoped>
.knowledge-query { margin-top: 24px; }
.hint { color: var(--el-text-color-secondary); font-size: 13px; }
.query-input { width: min(500px, 100%); }
.notice, .query-cost { margin-top: 12px; }
.source-text { white-space: pre-wrap; overflow-wrap: anywhere; max-height: 55vh; overflow: auto; }
</style>
