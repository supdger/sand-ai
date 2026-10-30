<template>
  <ElCard shadow="never">
    <template #header>
      <div class="panel-head">
        <h3>外部资料工具试用</h3>
        <p>使用已发布并绑定到当前 Bearer 所属环境的只读工具。MCP 工具也走这一受控运行入口；试用会记录实际外部请求次数。</p>
      </div>
    </template>
    <ElAlert v-if="recoveryNotice" type="warning" :closable="false" :title="recoveryNotice" class="notice" />
    <ElAlert v-if="catalogError" type="info" :closable="false" :title="catalogError" class="notice" />
    <ElForm label-width="110px" @submit.prevent>
      <RuntimeApiKeyField v-model="apiKey" placeholder="需 sand_ai.tool.run 和 sand_ai.tool.read 授权" />
      <ElFormItem v-if="environmentId" label="已绑定工具">
        <ElSelect :model-value="selectedTool" filterable clearable placeholder="选择工具，或在下方手动填写" :loading="catalogBusy" :disabled="draftLocked" @update:model-value="selectTool">
          <ElOption v-for="item in availableTools" :key="`${item.code}@${item.version}`"
            :label="`${item.name} · ${item.code} v${item.version} · ${item.adapter === 'mcp_streamable_http' ? 'MCP' : '只读 HTTP'}`"
            :value="`${item.code}@${item.version}`" />
        </ElSelect>
        <ElButton v-if="catalogMore" :loading="catalogBusy" @click="loadAvailable">加载更多</ElButton>
        <ElButton :disabled="draftLocked" :loading="catalogBusy" @click="reloadAvailable">刷新工具目录</ElButton>
      </ElFormItem>
      <ElFormItem label="工具代码">
        <ElInput v-model.trim="code" maxlength="64" :disabled="draftLocked" placeholder="已发布工具代码" />
      </ElFormItem>
      <ElFormItem label="固定版本">
        <ElInputNumber v-model="version" :min="1" :max="2147483647" :step="1" :disabled="draftLocked" />
      </ElFormItem>
      <ElFormItem label="业务范围">
        <ElInput v-model.trim="businessScope" maxlength="128" :disabled="draftLocked" placeholder="例如试用项目代码" />
      </ElFormItem>
      <ToolSchemaFields v-if="schemaState.schema" v-model="inputText" :schema="schemaState.schema" :disabled="draftLocked" />
      <ElAlert v-if="code.trim() && !selectedSchema" type="info" :closable="false"
        title="尚未读到此固定版本的输入 Schema。请从已绑定工具目录选择或加载更多；原运行找回不需要重新配置工具。" class="notice" />
      <ElAlert v-if="schemaState.error" type="warning" :closable="false" :title="schemaState.error" class="notice" />
      <ElAlert v-if="inputIssue" type="warning" :closable="false" :title="inputIssue" class="notice" />
      <ElFormItem :label="schemaState.schema ? '完整 JSON' : '工具输入'">
        <ElInput v-model="inputText" type="textarea" :rows="6" :disabled="draftLocked" placeholder="输入符合工具版本 Schema 的 JSON 对象，例如 {}" />
      </ElFormItem>
      <ElFormItem v-if="selectedSchema" label="输入 Schema">
        <ElInput type="textarea" :rows="4" readonly :model-value="format(selectedSchema)" />
      </ElFormItem>
      <ElFormItem>
        <ElSpace wrap>
          <ElButton type="primary" :loading="busy" :disabled="draftLocked || !ready || !schemaState.schema || !!pending || !!lookupKey.trim() || !!run || lookupRunId !== null || !!inputIssue" @click="submit">
            运行试用
          </ElButton>
          <ElButton v-if="run" :disabled="busy" @click="refresh">刷新运行</ElButton>
          <ElButton v-if="run && terminal && !busy" @click="newTrial">开始新试用</ElButton>
        </ElSpace>
      </ElFormItem>
    </ElForm>
    <ElAlert v-if="pending || lookupKey.trim()" type="warning" :closable="false"
      :title="pending && !pending.key ? '原运行尚未结束。使用当前 Bearer 读取原运行；未确认前不能提交新试用。' : '原请求尚待核对。使用当前 Bearer 按原请求键只读找回；找回不会重新调用工具。未确认前不能提交新试用。'" class="notice" />
    <ElForm label-width="110px" @submit.prevent>
      <ElFormItem label="原请求键">
        <ElInput v-model.trim="lookupKey" :disabled="busy || !!pending || !!run || lookupRunId !== null" maxlength="128" placeholder="粘贴原试用的 Idempotency-Key" />
        <ElButton :disabled="recoveryMalformed || busy || !apiKey.trim() || !lookupKey.trim()" :loading="busy" @click="recoverByKey">按原请求找回</ElButton>
      </ElFormItem>
      <ElFormItem label="已有运行号">
        <ElInputNumber v-model="lookupRunId" :min="1" :max="2147483647" :precision="0" :disabled="busy || !!pending || !!lookupKey.trim() || !!run" />
        <ElButton :disabled="recoveryMalformed || busy || !apiKey.trim() || !lookupRunId || !!pending?.key || !!lookupKey.trim()" @click="recoverById">读取原运行</ElButton>
      </ElFormItem>
    </ElForm>
    <ElAlert v-if="lookupRunId !== null && !run && !pending && !lookupKey.trim()" type="info" :closable="false"
      title="已保留原运行号。请使用当前 Bearer 读取原运行，确认结束后再开始新试用。" class="notice" />
    <ElAlert v-if="run?.state === 'ambiguous'" type="warning" :closable="false" title="外部结果不明。请先核对运行与费用，避免用新请求键重复调用。" class="notice" />
    <ElAlert v-if="error" type="error" :closable="false" :title="error" class="notice" />
    <template v-if="run">
      <ElAlert type="info" :closable="false"
        title="下方结果属于所示原运行号。读取原运行不会提交页面草稿；确认运行结束后，可点击“开始新试用”编辑并发起独立调用。" class="notice" />
      <ElAlert v-if="runFailure" :type="run.state === 'ambiguous' ? 'warning' : 'error'"
        :closable="false" :title="run.state === 'ambiguous' ? '原工具运行结果不明' : '原工具运行失败'"
        :description="runFailure" class="notice" />
      <ElDescriptions :column="2" border class="result">
        <ElDescriptionsItem label="运行号">{{ run.run_id }}</ElDescriptionsItem>
        <ElDescriptionsItem label="状态">{{ stateLabel(run.state) }}</ElDescriptionsItem>
        <ElDescriptionsItem label="工具">{{ run.tool || '—' }} · v{{ run.version ?? '—' }}</ElDescriptionsItem>
        <ElDescriptionsItem label="人工复核">{{ run.review_required ? '需要' : '不需要' }}</ElDescriptionsItem>
      </ElDescriptions>
      <h4>受控结果</h4>
      <ElInput type="textarea" :rows="7" readonly :model-value="format(run.result)" placeholder="运行完成后显示" />
      <h4>来源引用</h4>
      <ElInput type="textarea" :rows="4" readonly :model-value="format(run.citations)" placeholder="没有来源引用" />
      <h4>外部请求计量</h4>
      <p>{{ meteringLabel(run.metering) }}</p>
      <ElInput type="textarea" :rows="3" readonly :model-value="format(run.metering)" placeholder="尚无计量摘要" />
    </template>
    <h4>只读查询其他原运行</h4>
    <ElAlert type="info" :closable="false"
      title="填写原工具代码、固定版本及原请求键或运行号。只读查询可在当前试用运行期间使用；查询结果不会替换当前试用及其恢复记录。" class="notice" />
    <ElForm label-width="110px" @submit.prevent>
      <ElFormItem label="原工具代码">
        <ElInput v-model.trim="readerCode" :disabled="readerBusy" maxlength="64" placeholder="原运行使用的工具代码" />
      </ElFormItem>
      <ElFormItem label="原固定版本">
        <ElInputNumber v-model="readerVersion" :disabled="readerBusy" :min="1" :max="2147483647" :precision="0" />
      </ElFormItem>
      <ElFormItem label="原请求键">
        <ElInput v-model.trim="readerKey" :disabled="readerBusy" maxlength="128" placeholder="原 Idempotency-Key；已有运行号时可留空" />
      </ElFormItem>
      <ElFormItem label="原运行号">
        <ElInputNumber v-model="readerRunId" :disabled="readerBusy" :min="1" :max="2147483647" :precision="0" />
      </ElFormItem>
      <ElFormItem>
        <ElSpace wrap>
          <ElButton :loading="readerBusy" :disabled="readerBusy || !readerReady" @click="readIndependent">只读查询原运行</ElButton>
          <ElButton @click="clearReader">清除查询</ElButton>
        </ElSpace>
      </ElFormItem>
    </ElForm>
    <ElAlert v-if="readerError" type="error" :closable="false" :title="readerError" class="notice" />
    <template v-if="readerResult">
      <ElAlert v-if="readerFailure" :type="readerResult.state === 'ambiguous' ? 'warning' : 'error'"
        :closable="false" :title="readerResult.state === 'ambiguous' ? '查询的原运行结果不明' : '查询的原运行失败'"
        :description="readerFailure" class="notice" />
      <ElDescriptions :column="2" border class="result">
        <ElDescriptionsItem label="查询运行号">{{ readerResult.run_id }}</ElDescriptionsItem>
        <ElDescriptionsItem label="状态">{{ stateLabel(readerResult.state) }}</ElDescriptionsItem>
        <ElDescriptionsItem label="原工具">{{ readerResult.tool }} · v{{ readerResult.version }}</ElDescriptionsItem>
        <ElDescriptionsItem label="人工复核">{{ readerResult.review_required ? '需要' : '不需要' }}</ElDescriptionsItem>
      </ElDescriptions>
      <h4>查询的受控结果</h4>
      <ElInput type="textarea" :rows="7" readonly :model-value="format(readerResult.result)" placeholder="原运行尚无结果" />
      <h4>查询的来源引用</h4>
      <ElInput type="textarea" :rows="4" readonly :model-value="format(readerResult.citations)" placeholder="没有来源引用" />
      <h4>查询的外部请求计量</h4>
      <p>{{ meteringLabel(readerResult.metering) }}</p>
      <ElInput type="textarea" :rows="3" readonly :model-value="format(readerResult.metering)" placeholder="尚无计量摘要" />
    </template>
    <ElDialog :model-value="confirmation !== null" title="确认外部工具试用" width="640px" :close-on-click-modal="false" @close="finishConfirmation(false)">
      <template v-if="confirmation">
        <ElDescriptions :column="1" border>
          <ElDescriptionsItem label="固定工具">{{ confirmation.toolCode }} · v{{ confirmation.toolVersion }}</ElDescriptionsItem>
          <ElDescriptionsItem label="环境 / 业务范围">{{ confirmation.environmentId }} / {{ confirmation.scope }}</ElDescriptionsItem>
          <ElDescriptionsItem label="只读发布快照">{{ confirmation.snapshot }}</ElDescriptionsItem>
          <ElDescriptionsItem label="原请求键">{{ confirmation.key }}</ElDescriptionsItem>
        </ElDescriptions>
        <p>下方输入已固定。将调用外部工具并计量实际外部请求次数；MCP 可能产生多次协议请求。未计价、未核账保留未知，不代表零。当前工作负载仍须具有运行权限。</p>
        <ElInput type="textarea" :rows="7" readonly :model-value="confirmation.input" />
      </template>
      <template #footer>
        <ElButton @click="finishConfirmation(false)">取消</ElButton>
        <ElButton type="primary" @click="finishConfirmation(true)">确认此输入并运行</ElButton>
      </template>
    </ElDialog>
  </ElCard>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useUserStore } from '@/store/modules/user'
import RuntimeApiKeyField from '../../components/RuntimeApiKeyField.vue'
import toolTrialApi, { type ToolAvailable, type ToolRunSummary } from '../../api/toolTrial'
import { newRuntimeRequestId, SandAiRuntimeError } from '../../api/runtime'
import { hintForRuntimeErrorCode } from '../../api/runtimeErrors'
import ToolSchemaFields from './ToolSchemaFields.vue'
import { parseToolSchema, toolInputIssues } from './toolSchema'

const props = defineProps<{ environmentId: number | null }>()
type Pending = { key: string }
type Recovery = {
  key: string; runId: number | null; toolCode: string | null; toolVersion: number | null
  inputHash: string | null; unresolved: boolean
}
const userStore = useUserStore()
const owner = computed(() => {
  const id: unknown = userStore.info.id
  return (typeof id === 'number' && Number.isSafeInteger(id) && id > 0)
    || (typeof id === 'string' && id.trim()) ? String(id) : null
})
const storageName = ref('')
const storageBlocked = ref(true)
const recoveryMalformed = ref(false)
const recovery = ref<Recovery | null>(null)
const legacyName = ref('')
const legacyBlocked = ref(false)
const recoveryNotice = ref('')
const apiKey = ref('')
const code = ref('')
const version = ref(1)
const businessScope = ref('trial')
const inputText = ref('{}')
const run = ref<ToolRunSummary | null>(null)
const pending = ref<Pending | null>(null)
const busy = ref(false)
const error = ref('')
type Confirmation = { toolCode: string; toolVersion: number; scope: string; environmentId: number; snapshot: string; key: string; input: string }
const confirmation = ref<Confirmation | null>(null)
let confirmResult: ((accepted: boolean) => void) | null = null
function finishConfirmation(accepted: boolean): void {
  const resolve = confirmResult
  confirmResult = null
  confirmation.value = null
  resolve?.(accepted)
}
function confirmIntent(value: Confirmation): Promise<boolean> {
  return new Promise(resolve => { confirmResult = resolve; confirmation.value = value })
}
const lookupKey = ref('')
const lookupRunId = ref<number | null>(null)
const readerCode = ref('')
const readerVersion = ref<number | undefined>(1)
const readerKey = ref('')
const readerRunId = ref<number | undefined>(undefined)
const readerResult = ref<ToolRunSummary | null>(null)
const readerBusy = ref(false)
const readerError = ref('')
let readerEpoch = 0
const readerReady = computed(() => owner.value !== null && !!apiKey.value.trim()
  && !!readerCode.value.trim() && Number.isSafeInteger(readerVersion.value)
  && (readerVersion.value ?? 0) > 0
  && (!!readerKey.value.trim() || Number.isSafeInteger(readerRunId.value) && (readerRunId.value ?? 0) > 0))
let epoch = 0
let scopeEpoch = 0
let catalogEpoch = 0
let catalogPage = 0
const availableTools = ref<ToolAvailable[]>([])
const catalogBusy = ref(false)
const catalogMore = ref(false)
const catalogError = ref('')

const ready = computed(() => Boolean(apiKey.value.trim() && code.value.trim()
  && Number.isInteger(version.value) && version.value > 0 && businessScope.value.trim() && inputText.value.trim()))
const selectedTool = computed(() => availableTools.value.some(item => item.code === code.value && item.version === version.value)
  ? `${code.value}@${version.value}` : '')
const selectedSchema = computed(() => availableTools.value.find(item => item.code === code.value && item.version === version.value)?.input_schema ?? null)
const draftLocked = computed(() => storageBlocked.value || legacyBlocked.value || busy.value || !!pending.value || !!lookupKey.value.trim()
  || lookupRunId.value !== null || !!run.value)
const terminal = computed(() => run.value?.state === 'succeeded' || run.value?.state === 'failed')
// Translate only published stable codes; never display upstream detail or arbitrary error objects.
const toolFailureHints: Readonly<Record<string, string>> = {
  SAND_AI_BUDGET_UNPRICED: '本次外部请求的价格尚未冻结。请管理员核对工具价格及预算配置。',
  SAND_AI_BUDGET_SCOPE_UNVERIFIED: '无法核实预算所属身份。请核对当前工作负载凭据与环境。',
  SAND_AI_INVOCATION_SCOPE_FORBIDDEN: '当前工作负载无权访问此工具运行。请核对原运行所属应用、环境与业务范围。',
  SAND_AI_INVOCATION_FACTS_UNVERIFIED: '无法核实原工具运行的归属。请保留原运行号，请管理员核对来源与授权。',
  SAND_AI_DATA_CLASS_FORBIDDEN: '当前授权不允许工具来源的数据分级。请管理员核对连接分级与工作负载授权。',
  SAND_AI_TOOL_RUN_IN_PROGRESS: '原运行仍在处理。请使用“刷新运行”或“按原请求找回”读取原状态。',
  SAND_AI_TOOL_RUN_STATE_INVALID: '原运行状态无法核实。请保留原运行号，请管理员核对持久状态。',
  SAND_AI_TOOL_FINALIZATION_PENDING: '原结果正在等待本地恢复。请读取原运行，不要重新调用工具。',
  SAND_AI_TOOL_DISPATCH_AMBIGUOUS: '外部请求是否完成尚未确认。请读取原运行并核对计量，不要用新请求键重复调用。',
  SAND_AI_TOOL_TIMEOUT: '外部工具请求超时。请先核对原运行和计量，再请管理员检查连接超时配置。',
  SAND_AI_TOOL_RATE_LIMITED: '外部工具拒绝了本次请求，原因是服务限流。请先核对原运行和计量，再请管理员检查服务限额。',
  SAND_AI_TOOL_UPSTREAM_UNAVAILABLE: '外部工具服务不可用。请管理员检查所绑定的连接与服务状态。',
  SAND_AI_TOOL_UPSTREAM_REJECTED: '外部工具拒绝本次请求。请管理员核对连接认证与已发布工具配置。',
  SAND_AI_TOOL_RESPONSE_TOO_LARGE: '外部工具结果超过已发布大小限制。请管理员核对工具结果上限与服务配置。',
  SAND_AI_TOOL_RESPONSE_INVALID: '外部工具未返回合法的标准 JSON。请管理员核对所绑定接口的结果格式。',
  SAND_AI_TOOL_RESPONSE_SCHEMA_INVALID: '外部工具结果不符合已发布输出 Schema。请管理员核对服务与固定工具版本。',
  SAND_AI_MCP_PROTOCOL_MISMATCH: 'MCP 服务未完成要求的稳定协议。请管理员在“外部工具配置”核对连接并重新发现工具。',
  SAND_AI_MCP_SESSION_INVALID: 'MCP 服务返回的会话标识无效。请管理员检查所绑定 MCP 服务的协议配置。',
  SAND_AI_MCP_SSE_UNSUPPORTED: 'MCP 服务返回了当前不支持的流式响应。请管理员核对服务的响应模式。',
  SAND_AI_MCP_RESPONSE_INVALID: 'MCP 服务返回的协议响应无效。请管理员检查所绑定服务。',
  SAND_AI_MCP_DUPLICATE_JSON_MEMBER: 'MCP 响应包含重复 JSON 字段。请管理员核对服务输出格式。',
  SAND_AI_MCP_RESPONSE_LIMIT_EXCEEDED: 'MCP 响应超过受管安全限制。请管理员核对服务输出与工具限制。',
  SAND_AI_MCP_SERVER_MESSAGE_REJECTED: 'MCP 服务发送了不支持的主动消息。请管理员核对协议配置。',
  SAND_AI_MCP_CURSOR_INVALID: 'MCP 工具目录的分页标识无效。请管理员核对服务目录接口。',
  SAND_AI_MCP_CATALOG_LIMIT_EXCEEDED: 'MCP 工具目录超过受管安全限制。请管理员核对允许的工具范围。',
  SAND_AI_MCP_SCHEMA_DRIFTED: 'MCP 工具定义在发布后发生变化。请在“外部工具配置”重新发现、核对并发布绑定新版本。',
  SAND_AI_MCP_REGISTRY_DRIFTED: '本地只读注册定义在发布后发生变化。请管理员核对注册表并重新发布绑定固定版本。',
  SAND_AI_MCP_STRUCTURED_RESULT_REQUIRED: 'MCP 工具没有返回要求的结构化结果。请管理员核对工具输出 Schema 与服务实现。',
  SAND_AI_MCP_TOOL_NOT_DISPATCHED: '协议恢复未能确认业务工具派发。请保留原运行号并核对计量，不自行重新发送。',
  SAND_AI_MCP_UPSTREAM_REJECTED: 'MCP 服务拒绝了协议请求。请管理员核对连接认证与服务协议。'
}
function failureHint(code: string | null): string {
  const hint = code ? toolFailureHints[code] ?? hintForRuntimeErrorCode(code) : undefined
  return hint ?? '未能确认具体原因。请保留原请求键与运行号，使用当前工作负载 Bearer 读取原状态；请管理员核对连接、权限及计量。'
}
const runFailure = computed(() => {
  const value = run.value
  if (!value || !['failed', 'ambiguous'].includes(value.state)) return ''
  const detail = value.error
  const code = typeof detail === 'object' && detail !== null && !Array.isArray(detail)
    && 'code' in detail && typeof detail.code === 'string' ? detail.code : null
  return `${failureHint(code)} 原运行的“刷新运行”只读取状态；新试用会产生独立调用，结果不明时不能开始新试用。`
})
function requestFailure(cause: unknown): string {
  return `${failureHint(cause instanceof SandAiRuntimeError ? cause.errorCode : null)} 本次请求失败不证明原调用未发生，请按原请求找回或读取原运行。`
}

const readerFailure = computed(() => {
  const value = readerResult.value
  if (!value || !['failed', 'ambiguous'].includes(value.state)) return ''
  const detail = value.error
  const failureCode = typeof detail === 'object' && detail !== null && !Array.isArray(detail)
    && 'code' in detail && typeof detail.code === 'string' ? detail.code : null
  return failureHint(failureCode)
})
function invalidateReader(): void {
  readerEpoch++
  readerResult.value = null
  readerBusy.value = false
  readerError.value = ''
}
function clearReader(): void {
  invalidateReader()
  readerCode.value = ''
  readerVersion.value = 1
  readerKey.value = ''
  readerRunId.value = undefined
}
async function readIndependent(): Promise<void> {
  if (readerBusy.value || !readerReady.value) return
  const key = readerKey.value.trim()
  const runId = readerRunId.value
  const toolCode = readerCode.value.trim()
  const toolVersion = readerVersion.value
  if (toolVersion === undefined || !Number.isSafeInteger(toolVersion) || toolVersion < 1
    || toolVersion > 2147483647 || runId !== undefined && (!Number.isSafeInteger(runId) || runId < 1 || runId > 2147483647)
    || new TextEncoder().encode(key).length > 128 || /[\r\n]/.test(key)) {
    readerError.value = '请核对原固定版本、运行号及原请求键格式。'
    return
  }
  if (!key && runId === undefined) return
  const current = ++readerEpoch
  const bearer = apiKey.value
  const environmentId = props.environmentId
  readerBusy.value = true
  readerResult.value = null
  readerError.value = ''
  try {
    // The authorized by-key endpoint establishes the request-key relationship.
    // This reader never updates the write draft, recovery journal or unresolved request.
    const result = key
      ? await toolTrialApi.readByKey(bearer, key, environmentId)
      : await toolTrialApi.read(bearer, runId as number, environmentId)
    if (current !== readerEpoch) return
    if (!Number.isSafeInteger(result.run_id) || result.run_id <= 0
      || runId !== undefined && result.run_id !== runId
      || result.tool !== toolCode || result.version !== toolVersion) {
      readerError.value = '返回的原运行号或工具固定版本与查询条件不一致，已隐藏结果。请核对原请求信息。'
      return
    }
    readerResult.value = result
  } catch (cause) {
    if (current === readerEpoch) {
      readerError.value = `${failureHint(cause instanceof SandAiRuntimeError ? cause.errorCode : null)} 此查询只读取原运行，请核对查询条件与当前授权后再次读取。`
    }
  } finally { if (current === readerEpoch) readerBusy.value = false }
}
watch([readerCode, readerVersion, readerKey, readerRunId], invalidateReader, { flush: 'sync' })

const schemaState = computed(() => {
  if (!selectedSchema.value) return { schema: null, error: '' }
  try { return { schema: parseToolSchema(selectedSchema.value), error: '' } }
  catch (cause) { return { schema: null, error: cause instanceof Error ? cause.message : '工具 Schema 无效' } }
})
const inputIssue = computed(() => {
  if (schemaState.value.error) return schemaState.value.error
  try {
    const input = parseInput()
    return schemaState.value.schema ? toolInputIssues(schemaState.value.schema, input).join('；') : ''
  } catch (cause) { return cause instanceof Error ? cause.message : '工具输入无效' }
})

async function digest(value: string): Promise<string> {
  const hash = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(value))
  return Array.from(new Uint8Array(hash), byte => byte.toString(16).padStart(2, '0')).join('')
}
function validRecovery(value: unknown, legacy = false): Recovery | null {
  if (typeof value !== 'object' || value === null || Array.isArray(value)) return null
  const item = value as Record<string, unknown>
  if (typeof item.key !== 'string' || new TextEncoder().encode(item.key).length > 128
    || item.key !== item.key.trim() || /[\r\n]/.test(item.key)) return null
  if (item.runId !== null && !(typeof item.runId === 'number' && Number.isSafeInteger(item.runId) && item.runId > 0)) return null
  if (!item.key && item.runId === null || typeof item.unresolved !== 'boolean') return null
  if (!legacy && ((item.toolCode !== null && (typeof item.toolCode !== 'string' || !item.toolCode.trim()))
    || (item.toolVersion !== null && !(typeof item.toolVersion === 'number' && Number.isSafeInteger(item.toolVersion) && item.toolVersion > 0))
    || (item.inputHash !== null && (typeof item.inputHash !== 'string' || !/^[a-f0-9]{64}$/.test(item.inputHash))))) return null
  return { key: item.key, runId: item.runId as number | null,
    toolCode: legacy ? null : item.toolCode as string | null,
    toolVersion: legacy ? null : item.toolVersion as number | null,
    inputHash: legacy ? null : item.inputHash as string | null, unresolved: item.unresolved }
}
function lockRecovery(item: Recovery): void {
  recovery.value = item
  lookupKey.value = item.key
  lookupRunId.value = item.runId
  pending.value = item.unresolved ? { key: item.key } : null
  if (item.toolCode !== null) code.value = item.toolCode
  if (item.toolVersion !== null) version.value = item.toolVersion
}
function remember(item: Recovery): boolean {
  if (!storageName.value) return false
  try {
    const raw = JSON.stringify(item)
    sessionStorage.setItem(storageName.value, raw)
    if (sessionStorage.getItem(storageName.value) !== raw) throw new Error('recovery readback')
    storageBlocked.value = false
    return true
  } catch {
    storageBlocked.value = true
    recoveryNotice.value = '无法可靠保存原请求。已阻止新的工具调用；请保留原请求键或运行号，只读找回原运行。'
    return false
  }
}
async function restoreRecovery(current: number): Promise<void> {
  if (owner.value === null) { recoveryNotice.value = '无法核实当前管理员身份，已阻止工具试用。'; return }
  try {
    const name = `sand-ai-tool-trial-recovery:v2:${await digest(JSON.stringify([owner.value, props.environmentId]))}`
    if (current !== scopeEpoch) return
    storageName.value = name
    const raw = sessionStorage.getItem(name)
    const oldName = props.environmentId === null ? '' : `sand-ai-tool-trial-recovery:${props.environmentId}`
    const oldRaw = oldName ? sessionStorage.getItem(oldName) : null
    // Old records have no trustworthy actor or input hash. Only an authorized GET may claim them.
    if (raw !== null) {
      const item = validRecovery(JSON.parse(raw))
      if (!item) throw new Error('invalid recovery')
      lockRecovery(item)
    }
    if (oldRaw !== null) {
      legacyName.value = oldName
      legacyBlocked.value = true
      if (raw === null) {
        const item = validRecovery(JSON.parse(oldRaw), true)
        if (!item) throw new Error('invalid legacy recovery')
        lockRecovery(item)
        recoveryNotice.value = '发现旧版原请求，原管理员及输入摘要未知。请使用当前 Bearer 只读找回；授权读取成功后才归入当前管理员与环境。'
      } else recoveryNotice.value = '另有旧版原请求需要保留。当前原运行结束并明确开始新试用后，将提供旧请求的只读恢复入口。'
    }
    storageBlocked.value = false
  } catch {
    if (current !== scopeEpoch) return
    recoveryMalformed.value = true
    storageBlocked.value = true
    recoveryNotice.value = '恢复记录无法读取或格式无效。已保留原记录并锁定新试用；请保留原请求信息交由管理员核对。'
  }
}
function acceptRun(result: ToolRunSummary, expected: Recovery, byKey: boolean): void {
  if (!Number.isSafeInteger(result.run_id) || result.run_id <= 0
    || expected.runId !== null && result.run_id !== expected.runId
    || expected.toolCode !== null && result.tool !== expected.toolCode
    || expected.toolVersion !== null && result.version !== expected.toolVersion) {
    throw new Error('原运行标识与工具固定版本不一致')
  }
  // by-key GET itself establishes the opaque request-key relation; DTO has no hash/environment.
  if (!byKey && expected.runId === null) throw new Error('原运行号无法核对')
  const item: Recovery = { ...expected, runId: result.run_id,
    toolCode: expected.toolCode ?? result.tool, toolVersion: expected.toolVersion ?? result.version,
    unresolved: result.state !== 'succeeded' && result.state !== 'failed' }
  lockRecovery(item)
  run.value = result
  if (remember(item) && legacyName.value && legacyBlocked.value) {
    // Retain the legacy source until the user explicitly clears this terminal run.
    recoveryNotice.value = '原运行已通过当前授权读取。原请求记录将保留至确认结束并点击“开始新试用”。'
  }
}
function originalForLookup(): Recovery {
  return recovery.value ?? { key: lookupKey.value.trim(), runId: lookupRunId.value,
    toolCode: null, toolVersion: null, inputHash: null, unresolved: true }
}
async function recoverByKey(): Promise<void> {
  if (recoveryMalformed.value || busy.value || !apiKey.value.trim() || !lookupKey.value.trim()) return
  const expected = originalForLookup()
  if (new TextEncoder().encode(expected.key).length > 128 || /[\r\n]/.test(expected.key)) {
    error.value = '原请求键格式无效或超过 128 字节'; return
  }
  const current = ++epoch
  busy.value = true
  error.value = ''
  lockRecovery(expected)
  // Failed local persistence never triggers a POST. GET can still recover the original.
  if (!legacyName.value) remember(expected)
  try {
    const result = await toolTrialApi.readByKey(apiKey.value, expected.key, props.environmentId)
    if (current !== epoch) return
    acceptRun(result, expected, true)
  } catch (cause) {
    if (current === epoch) error.value = requestFailure(cause)
  } finally { if (current === epoch) busy.value = false }
}
async function recoverById(): Promise<void> {
  if (recoveryMalformed.value || busy.value || pending.value?.key || lookupKey.value.trim() || !apiKey.value.trim() || !lookupRunId.value) return
  const expected = originalForLookup()
  if (expected.runId === null) return
  const current = ++epoch
  busy.value = true
  error.value = ''
  lockRecovery(expected)
  if (!legacyName.value) remember(expected)
  try {
    const result = await toolTrialApi.read(apiKey.value, expected.runId, props.environmentId)
    if (current !== epoch) return
    acceptRun(result, expected, false)
  } catch (cause) {
    if (current === epoch) error.value = requestFailure(cause)
  } finally { if (current === epoch) busy.value = false }
}

async function loadAvailable(): Promise<void> {
  if (!props.environmentId || catalogBusy.value || !catalogMore.value && catalogPage > 0) return
  const current = catalogEpoch
  const environmentId = props.environmentId
  catalogBusy.value = true
  catalogError.value = ''
  try {
    const result = await toolTrialApi.available(environmentId, catalogPage + 1)
    if (current !== catalogEpoch) return
    availableTools.value.push(...result.data)
    catalogPage += 1
    catalogMore.value = catalogPage * 100 < result.total
  } catch {
    if (current === catalogEpoch) catalogError.value = '无法读取工具目录；请刷新目录后再开始试用。原请求和运行号的只读找回仍可使用。'
  } finally {
    if (current === catalogEpoch) catalogBusy.value = false
  }
}

async function reloadAvailable(): Promise<void> {
  if (busy.value || pending.value || catalogBusy.value) return
  catalogEpoch++
  catalogPage = 0
  availableTools.value = []
  catalogMore.value = false
  await loadAvailable()
}

function selectTool(value: string): void {
  if (!value) {
    code.value = ''
    version.value = 1
    return
  }
  const item = availableTools.value.find(candidate => `${candidate.code}@${candidate.version}` === value)
  if (!item) return
  code.value = item.code
  version.value = item.version
}

function format(value: unknown): string {
  if (value === null || value === undefined) return ''
  try { return JSON.stringify(value, null, 2) } catch { return '结果格式无效' }
}

function meteringLabel(value: unknown): string {
  if (typeof value !== 'object' || value === null || Array.isArray(value)) return '外部请求次数尚未确认；费用未知。'
  const metering = value as Record<string, unknown>
  if (metering.unit !== 'external_request' || typeof metering.quantity !== 'number'
    || !Number.isSafeInteger(metering.quantity) || metering.quantity < 0) return '当前计量单位或次数尚未确认；不按一次运行推算请求量或金额。'
  return `已记录 ${metering.quantity} 次外部请求（external_request）；计量金额与已核账金额以原摘要为准，未计价或未核对仍未知。`
}

function stateLabel(state: string): string {
  return ({ running: '运行中', succeeded: '已完成', failed: '失败', ambiguous: '结果不明' } as Record<string, string>)[state] ?? state
}

function parseInput(): Record<string, unknown> {
  let value: unknown
  try { value = JSON.parse(inputText.value) } catch { throw new Error('工具输入必须是有效的 JSON 对象') }
  if (typeof value !== 'object' || value === null || Array.isArray(value)) {
    throw new Error('工具输入必须是 JSON 对象')
  }
  return value as Record<string, unknown>
}

async function submit(): Promise<void> {
  if (draftLocked.value || !ready.value || !schemaState.value.schema || inputIssue.value || owner.value === null) return
  let input: Record<string, unknown>
  try { input = parseInput() } catch (cause) {
    error.value = cause instanceof Error ? cause.message : '工具输入无效'
    return
  }
  const current = ++epoch
  const originalOwner = owner.value
  const originalToken = userStore.accessToken
  const intent = { toolCode: code.value.trim(), toolVersion: version.value, scope: businessScope.value.trim(),
    input, bearer: apiKey.value, environmentId: props.environmentId, key: newRuntimeRequestId('tool-trial') }
  const currentIntent = () => current === epoch && owner.value === originalOwner && userStore.accessToken === originalToken
    && props.environmentId === intent.environmentId && apiKey.value === intent.bearer
  busy.value = true
  error.value = ''
  let confirmed = false
  try {
    // Directory metadata helps editing; the current workload-authorized fixed version is the send authority.
    const published = await toolTrialApi.readVersion(intent.bearer, intent.toolCode, intent.toolVersion, intent.environmentId)
    if (!currentIntent()) return
    const schema = parseToolSchema(published.input_schema)
    const issues = toolInputIssues(schema, intent.input)
    if (issues.length) { error.value = `当前固定版本的输入 Schema 校验未通过：${issues.join('；')}。尚未提交试用。`; return }
    const inputHash = await digest(JSON.stringify({ business_scope: intent.scope, input: intent.input }))
    if (!currentIntent()) return
    const accepted = await confirmIntent({ toolCode: intent.toolCode, toolVersion: intent.toolVersion, scope: intent.scope,
      environmentId: published.environment_id, snapshot: published.snapshot_sha256, key: intent.key, input: JSON.stringify(intent.input, null, 2) })
    if (!accepted) return
    if (!currentIntent()) return
    confirmed = true
    const expected: Recovery = { key: intent.key, runId: null, toolCode: intent.toolCode, toolVersion: intent.toolVersion, inputHash, unresolved: true }
    lockRecovery(expected)
    if (!remember(expected)) return
    const result = await toolTrialApi.run(intent.bearer, intent.toolCode, intent.toolVersion, intent.scope, intent.input, intent.key, intent.environmentId)
    if (!currentIntent()) return
    acceptRun(result, expected, true)
  } catch (cause) {
    if (currentIntent()) error.value = confirmed ? requestFailure(cause)
      : '当前授权下的固定工具版本或 Schema 未确认，尚未提交试用。请核对工具权限、发布版本和环境后重试。'
  } finally { if (currentIntent()) busy.value = false }
}
async function refresh(): Promise<void> {
  if (busy.value || !run.value || !apiKey.value.trim() || !recovery.value) return
  const expected = recovery.value
  const current = ++epoch
  busy.value = true
  error.value = ''
  try {
    const result = expected.key
      ? await toolTrialApi.readByKey(apiKey.value, expected.key, props.environmentId)
      : await toolTrialApi.read(apiKey.value, run.value.run_id, props.environmentId)
    if (current !== epoch) return
    acceptRun(result, expected, !!expected.key)
  } catch (cause) {
    if (current === epoch) error.value = requestFailure(cause)
  } finally { if (current === epoch) busy.value = false }
}
function newTrial(): void {
  if (!terminal.value || busy.value || !storageName.value) return
  try {
    sessionStorage.removeItem(storageName.value)
    if (sessionStorage.getItem(storageName.value) !== null) throw new Error('recovery removal')
    // Only remove an old record that this authorized GET actually recovered.
    if (legacyName.value && legacyBlocked.value) {
      const raw = sessionStorage.getItem(legacyName.value)
      const old = raw === null ? null : validRecovery(JSON.parse(raw), true)
      if (old && old.key === recovery.value?.key && (old.runId === null || old.runId === recovery.value?.runId)) {
        sessionStorage.removeItem(legacyName.value)
        if (sessionStorage.getItem(legacyName.value) !== null) throw new Error('legacy removal')
      }
    }
  } catch {
    storageBlocked.value = true
    recoveryNotice.value = '无法清除已结束的原请求记录，已保留当前运行并锁定新试用。请恢复浏览器会话存储后再次点击“开始新试用”。'
    return
  }
  resetScope()
}
watch(apiKey, () => {
  finishConfirmation(false)
  clearReader()
  epoch++
  run.value = null
  busy.value = false
  error.value = ''
  if (recovery.value) pending.value = { key: recovery.value.key }
}, { flush: 'sync' })
watch([code, version], () => {
  // Each fixed tool version owns a separate input draft, even when its field names match.
  inputText.value = '{}'
}, { flush: 'sync' })
watch([code, version, businessScope, inputText], () => {
  if (pending.value || run.value || lookupRunId.value !== null) return
  epoch++
  run.value = null
  busy.value = false
  error.value = ''
})
function resetScope(): void {
  finishConfirmation(false)
  clearReader()
  epoch++
  scopeEpoch++
  pending.value = null
  recovery.value = null
  storageName.value = ''
  storageBlocked.value = true
  recoveryMalformed.value = false
  legacyName.value = ''
  legacyBlocked.value = false
  recoveryNotice.value = ''
  lookupKey.value = ''
  lookupRunId.value = null
  run.value = null
  busy.value = false
  error.value = ''
  apiKey.value = ''
  code.value = ''
  version.value = 1
  businessScope.value = 'trial'
  inputText.value = '{}'
  // Scope restore survives a Bearer renewal, which only invalidates runtime replies.
  void restoreRecovery(scopeEpoch)
  catalogEpoch++
  catalogPage = 0
  availableTools.value = []
  catalogMore.value = false
  catalogBusy.value = false
  catalogError.value = ''
  if (props.environmentId) void loadAvailable()
}
watch([owner, () => userStore.accessToken, () => props.environmentId], resetScope, { immediate: true, flush: 'sync' })
onBeforeUnmount(() => { finishConfirmation(false); epoch++; scopeEpoch++; catalogEpoch++; clearReader(); apiKey.value = ''; inputText.value = '{}'; run.value = null })
</script>

<style scoped>
.panel-head h3 { margin: 0; }
.panel-head p { margin: 6px 0 0; color: var(--el-text-color-secondary); }
.notice, .result { margin-top: 12px; }
h4 { margin: 16px 0 8px; }
</style>
