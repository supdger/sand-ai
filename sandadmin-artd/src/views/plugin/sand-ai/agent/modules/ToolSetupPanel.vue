<template>
  <ElCard shadow="never">
    <template #header>
      <div class="panel-head">
        <h3>受管外部工具配置</h3>
        <p>依次创建连接、确认工具定义、发布并绑定环境。每一步都由管理员单独提交；试用入口只调用已绑定版本。</p>
      </div>
    </template>
    <ElEmpty v-if="!environmentId" description="先选择应用环境" />
    <template v-else>
      <ElAlert v-if="error" type="error" :closable="false" :title="error" class="notice" />
      <ElAlert v-if="pending" type="warning" :closable="false"
        :title="`${stepLabel(pending.step)}结果尚未确认。请读取原请求回执；未找回之前不会创建新请求。`"
        class="notice" />
      <ElForm v-if="pending" label-width="110px" class="notice" @submit.prevent>
        <ElFormItem label="原请求号">
          <ElInput :model-value="pending.key" readonly aria-label="可选中保留的原工具配置请求号" />
        </ElFormItem>
        <ElFormItem label="原操作范围">
          <span>{{ stepLabel(pending.step) }} · 环境 #{{ pending.environmentId }}</span>
        </ElFormItem>
        <ElFormItem label="固定目标摘要">
          <ElInput :model-value="pending.target_sha256" readonly aria-label="原工具配置目标 SHA256 摘要" />
        </ElFormItem>
        <p class="hint">读取原请求结果仅查询原回执，不重复提交配置或对外发现；请保留原请求号与固定目标摘要。</p>
      </ElForm>
      <p v-if="pending" class="hint">{{ notSubmittedHere ? '本页确认原请求尚未发送，原提交内容仅保留在当前页面内存；存储恢复后可按原号发送。' : '原提交内容与本页未发送证明不可恢复；仅能读取原回执。未找到回执不代表未发送，不创建替代请求。' }}</p>
      <ElSpace v-if="pending" class="notice">
        <ElButton :loading="busy" :disabled="busy || writeFlight" @click="recoverPending()">读取原请求结果</ElButton>
        <ElButton v-if="retryOriginal && !rejectedReceipt" :disabled="busy || writeFlight" @click="retryPending">按原号发送</ElButton>
      </ElSpace>
      <ElAlert v-if="rejectedReceipt" type="warning" :closable="false"
        :title="`原请求已被拒绝且未提交：${rejectedReceipt.result.error_code}`" class="notice" />
      <ElButton v-if="rejectedReceipt" :disabled="busy || writeFlight || journalBlocked" @click="acknowledgeRejected">
        确认原请求被拒绝，修改配置
      </ElButton>
      <p v-if="rejectionHistory" class="hint">{{ rejectionHistory }}</p>
      <ElAlert v-if="journalBlocked" type="error" :closable="false" title="原请求记录无法安全保存、读取或清除。恢复浏览器存储后可读取原请求结果；暂不发送新的配置请求。" class="notice" />

      <h4>1. 建立私有连接</h4>
      <ElForm label-width="110px" @submit.prevent>
        <ElFormItem label="已有连接">
          <ElSelect :model-value="connection?.connection_id ?? null" filterable clearable
            :disabled="busy || mutationLocked || !!pending || !!draft" :loading="connectionLoading"
            placeholder="选择已创建连接，或在下方创建新连接" @update:model-value="chooseConnection">
            <ElOption v-for="item in connections" :key="item.connection_id"
              :label="`${item.name} · ${item.code} · ${item.data_class}`" :value="item.connection_id" />
          </ElSelect>
          <ElButton v-if="connectionMore" :loading="connectionLoading" @click="loadConnections">加载更多</ElButton>
        </ElFormItem>
        <ElAlert v-if="connectionListError" type="info" :closable="false" :title="connectionListError" class="notice" />
        <p v-if="connection" class="hint">已有连接的地址、路径和凭证不回显；下面的输入仅用于创建新连接。</p>
        <ElFormItem label="连接代码"><ElInput v-model.trim="connectionForm.code" maxlength="64" :disabled="busy || mutationLocked || !!connection" placeholder="例如 knowledge_source" /></ElFormItem>
        <ElFormItem label="连接名称"><ElInput v-model.trim="connectionForm.name" maxlength="128" :disabled="busy || mutationLocked || !!connection" placeholder="仅管理员可见" /></ElFormItem>
        <ElFormItem label="HTTPS 来源"><ElInput v-model.trim="connectionForm.origin" :disabled="busy || mutationLocked || !!connection" placeholder="https://api.example.com" /></ElFormItem>
        <ElFormItem label="接口路径"><ElInput v-model.trim="connectionForm.path" :disabled="busy || mutationLocked || !!connection" placeholder="/mcp 或只读 HTTP 路径" /></ElFormItem>
        <ElFormItem label="来源分级"><ElInput v-model.trim="connectionForm.dataClass" maxlength="32" :disabled="busy || mutationLocked || !!connection" placeholder="按实际来源填写 data_class" /></ElFormItem>
        <ElFormItem label="连接认证">
          <ElRadioGroup v-model="authMode" :disabled="busy || mutationLocked || !!connection">
            <ElRadio value="none">无凭证</ElRadio>
            <ElRadio value="bearer">Bearer</ElRadio>
          </ElRadioGroup>
        </ElFormItem>
        <ElFormItem v-if="authMode === 'bearer'" label="Bearer 密钥">
          <ElInput v-model="credential" type="password" show-password :disabled="busy || mutationLocked || !!connection" autocomplete="off" placeholder="仅用于本次创建请求，发出时立即清空" />
        </ElFormItem>
        <ElFormItem>
          <ElButton type="primary" :loading="busy" :disabled="busy || mutationLocked || !!connection || !connectionReady" @click="createConnection">创建连接</ElButton>
          <span v-if="connection" class="step-result">已创建 {{ connection.name }} · 连接 #{{ connection.connection_id }} / 修订 #{{ connection.connection_revision_id }}</span>
        </ElFormItem>
      </ElForm>

      <h4>2. 建立只读工具版本</h4>
      <ElForm label-width="110px" @submit.prevent>
        <ElFormItem label="已有工具代码">
          <ElInput v-model.trim="workspaceCode" maxlength="64" :disabled="busy || mutationLocked || !!pending || !!draft"
            placeholder="刷新后可按原工具代码找回草稿、发布及绑定状态" />
          <ElButton :loading="busy" :disabled="busy || mutationLocked || !!pending || !connection || environmentMismatch || !workspaceCode.trim()"
            @click="loadWorkspace">读取服务端状态</ElButton>
        </ElFormItem>
        <ElAlert v-if="bindingConflict" type="warning" :closable="false"
          title="当前发布版本已绑定到本环境的另一连接，不能用所选连接再次绑定。" class="notice" />
        <ElFormItem label="工具类型">
          <ElRadioGroup v-model="adapter" :disabled="busy || mutationLocked || !!draft">
            <ElRadio value="mcp_streamable_http">受管 MCP</ElRadio>
            <ElRadio value="standard_readonly_http">标准只读 HTTP</ElRadio>
          </ElRadioGroup>
        </ElFormItem>
        <template v-if="adapter === 'mcp_streamable_http'">
          <ElFormItem>
            <ElButton :loading="busy" :disabled="busy || mutationLocked || !connection || environmentMismatch || !!draft || !!pending"
              @click="loadLatestMcp">读取已验证快照</ElButton>
            <ElButton :loading="busy" :disabled="busy || mutationLocked || !connection || environmentMismatch || !!draft" @click="discover">发现本地允许的 MCP 工具</ElButton>
            <span v-if="discovery" class="step-result">快照 #{{ discovery.snapshot_id }} · {{ discovery.tools.length }} 个允许的工具</span>
          </ElFormItem>
          <ElAlert v-if="pending?.step === 'discovery' && !rejectedReceipt" type="warning" :closable="false" class="notice"
            title="本次 MCP 发现结果与计量尚未确认"
            description="请读取原请求回执，不再次发起发现。下方若有快照，仅代表此前已确认的发现结果，不代表本次请求已完成。" />
          <section v-if="discovery" aria-label="已确认 MCP 发现摘要" class="notice">
            <p>已确认发现：快照 #{{ discovery.snapshot_id }} · 状态 {{ discovery.state }} · 连接 #{{ discovery.connection_id }} / 修订 #{{ discovery.connection_revision_id }} · {{ discovery.tools.length }} 个允许的工具</p>
            <p>服务端返回的实际发现计量：</p>
            <pre class="metering-summary" tabindex="0" aria-label="MCP 发现实际计量">{{ discoveryMetering(discovery.metering) }}</pre>
            <p>计量仅展示已确认快照的返回值；未提供的计量或金额保持待核实，不据工具数量推算。</p>
          </section>
          <ElFormItem label="发现的工具">
            <ElSelect v-model="snapshotItemId" filterable :disabled="busy || mutationLocked || !!draft" placeholder="选择本地只读注册表中的工具">
              <ElOption v-for="item in discovery?.tools ?? []" :key="item.snapshot_item_id"
                :label="`${item.name} · ${item.code}`" :value="item.snapshot_item_id" />
            </ElSelect>
          </ElFormItem>
          <p class="hint">工具代码和 Schema 由本地只读注册表与本次发现快照确定，远端声明不会自动获得只读权限。</p>
        </template>
        <template v-else>
          <ElFormItem label="工具代码"><ElInput v-model.trim="toolForm.code" maxlength="64" :disabled="busy || mutationLocked || !!draft" placeholder="例如 external_lookup" /></ElFormItem>
          <ElFormItem label="工具名称"><ElInput v-model.trim="toolForm.name" maxlength="128" :disabled="busy || mutationLocked || !!draft" /></ElFormItem>
          <ElFormItem label="用途说明"><ElInput v-model="toolForm.description" maxlength="1000" type="textarea" :rows="2" :disabled="busy || mutationLocked || !!draft" /></ElFormItem>
          <ElFormItem label="输入 Schema"><ElInput v-model="toolForm.inputSchema" type="textarea" :rows="5" :disabled="busy || mutationLocked || !!draft" /></ElFormItem>
          <ElFormItem label="输出 Schema"><ElInput v-model="toolForm.outputSchema" type="textarea" :rows="5" :disabled="busy || mutationLocked || !!draft" /></ElFormItem>
          <p class="hint">输入、输出 Schema 必须是关闭额外字段的 JSON 对象；发布后版本内容不原地修改。</p>
        </template>
        <ElFormItem label="结果复核"><ElSwitch v-model="reviewRequired" :disabled="busy || mutationLocked || !!draft" /></ElFormItem>
        <ElFormItem>
          <ElButton type="primary" :loading="busy" :disabled="busy || mutationLocked || !connection || environmentMismatch || !!draft || (adapter === 'mcp_streamable_http' && !snapshotItemId)" @click="createDraft">创建草稿</ElButton>
          <span v-if="draft" class="step-result">{{ draft.code }} v{{ draft.version }} · 工具 #{{ draft.tool_id }} / 版本 #{{ draft.tool_version_id }}</span>
        </ElFormItem>
      </ElForm>

      <h4>3. 发布并绑定</h4>
      <ElSpace wrap>
        <ElButton type="primary" :loading="busy" :disabled="busy || mutationLocked || environmentMismatch || !draft || !!published" @click="publish">发布固定版本</ElButton>
        <ElButton type="primary" :loading="busy" :disabled="busy || mutationLocked || environmentMismatch || bindingConflict || !connection || !published || !!bound" @click="bind">绑定到当前环境</ElButton>
        <ElTag v-if="bound" type="success">已绑定 · #{{ bound.binding_id }}</ElTag>
        <ElButton v-if="published && !revoked && hasAuth('sand_ai:tool:revoke')" type="danger"
          :disabled="busy || mutationLocked" @click="revoke">撤销此工具</ElButton>
        <ElTag v-if="revoked" type="info">{{ revoked.code }} 已撤销</ElTag>
        <ElButton v-if="(bound || revoked) && !pending && !busy && !writeFlight" @click="nextSetup">配置另一个工具</ElButton>
      </ElSpace>
      <p class="hint">发布和绑定后，到“外部工具试用”页签以工作负载 Bearer 验证实际权限、来源与费用。</p>
    </template>
  </ElCard>
</template>

<script setup lang="ts">
import { computed, onUnmounted, reactive, ref, watch } from 'vue'
import toolSetupApi, { type McpDiscovery, type ToolBound, type ToolConnectionRef, type ToolVersionCreated, type ToolOperationReceipt, type ToolOperation, type ToolOperationRejectedReceipt, type ConnectionWrite } from '../../api/toolSetup'
import { newRuntimeRequestId } from '../../api/runtime'
import { HttpError } from '@/utils/http/error'
import { useUserStore } from '@/store/modules/user'
import { useAuth } from '@/hooks/core/useAuth'
import { ElMessageBox } from 'element-plus'
import { parseToolSchema } from './toolSchema'

const props = defineProps<{ environmentId: number | null }>()
const userStore = useUserStore()
const { hasAuth } = useAuth()
const adminId = computed(() => {
  const id = userStore.info.id
  return typeof id === 'number' && Number.isSafeInteger(id) && id > 0 ? id : null
})
type Step = ToolOperation
type Target = Record<string, string | number>
type Pending = { version: 1; adminId: number; environmentId: number; step: Step;
  key: string; target: Target; target_sha256: string; hadUncertainOutcome: boolean }
const journalBlocked = ref(false)
const rejectedReceipt = ref<ToolOperationRejectedReceipt | null>(null)
const rejectionHistory = ref('')
const revoked = ref<ToolVersionCreated | null>(null)
const retryOriginal = ref<(() => Promise<void>) | null>(null)
const notSubmittedHere = ref(false)
const writeFlight = ref(false)
const connectionInputs = new Map<string, ConnectionWrite>()
const mutationLocked = computed(() => writeFlight.value || !!pending.value || journalBlocked.value || !!revoked.value)
const connectionForm = reactive({ code: '', name: '', origin: '', path: '', dataClass: '' })
const authMode = ref<'none' | 'bearer'>('none')
const credential = ref('')
const adapter = ref<'mcp_streamable_http' | 'standard_readonly_http'>('mcp_streamable_http')
const toolForm = reactive({
  code: '', name: '', description: '',
  inputSchema: '{"type":"object","additionalProperties":false}',
  outputSchema: '{"type":"object","additionalProperties":false}'
})
const reviewRequired = ref(false)
const snapshotItemId = ref<number | null>(null)
const connection = ref<ToolConnectionRef | null>(null)
const connectionEnvironmentId = ref<number | null>(null)
const connections = ref<ToolConnectionRef[]>([])
const connectionLoading = ref(false)
const connectionMore = ref(false)
const connectionListError = ref('')
let connectionPage = 0
let connectionEpoch = 0
const discovery = ref<McpDiscovery | null>(null)
const draft = ref<ToolVersionCreated | null>(null)
const published = ref<ToolVersionCreated | null>(null)
const bound = ref<ToolBound | null>(null)
const workspaceCode = ref('')
const bindingConflict = ref(false)
const pending = ref<Pending | null>(null)
const memoryIntents = new Map<string, Pending>()
const busy = ref(false)
const error = ref('')
let epoch = 0

const connectionReady = computed(() => Boolean(props.environmentId && connectionForm.code && connectionForm.name
  && connectionForm.origin && connectionForm.path && connectionForm.dataClass
  && (authMode.value === 'none' || credential.value.trim())))
const environmentMismatch = computed(() => connectionEnvironmentId.value !== null
  && props.environmentId !== connectionEnvironmentId.value)

function savedConnectionId(environmentId: number): number | null {
  try {
    const value = Number(sessionStorage.getItem(`sand-ai-tool-setup-connection:${adminId.value}:${environmentId}`))
    return Number.isInteger(value) && value > 0 ? value : null
  } catch { return null }
}

function saveConnectionId(environmentId: number, id: number | null): void {
  try {
    const key = `sand-ai-tool-setup-connection:${adminId.value}:${environmentId}`
    if (id === null) sessionStorage.removeItem(key)
    else sessionStorage.setItem(key, String(id))
  } catch { /* Browser storage is optional. */ }
}

function savedToolCode(environmentId: number, connectionId: number): string {
  try { return sessionStorage.getItem(`sand-ai-tool-setup-code:${adminId.value}:${environmentId}:${connectionId}`) ?? '' }
  catch { return '' }
}

function saveToolCode(environmentId: number, connectionId: number, code: string): void {
  try {
    const key = `sand-ai-tool-setup-code:${adminId.value}:${environmentId}:${connectionId}`
    if (code) sessionStorage.setItem(key, code)
    else sessionStorage.removeItem(key)
  } catch { /* Browser storage is optional. */ }
}

function journalKey(owner: number, environmentId: number): string {
  return `sand-ai-tool-setup-intent:v1:${owner}:${environmentId}`
}
function sameIntent(left: Pending, right: Pending): boolean {
  const target = (value: Target) => JSON.stringify(Object.keys(value).sort().map(key => [key, value[key]]))
  return left.version === right.version && left.adminId === right.adminId && left.environmentId === right.environmentId
    && left.step === right.step && left.key === right.key && left.target_sha256 === right.target_sha256
    && target(left.target) === target(right.target)
}
function persistIntent(intent: Pending): void {
  try {
    const key = journalKey(intent.adminId, intent.environmentId)
    const existing = readIntent(intent.adminId, intent.environmentId)
    if (existing && (!sameIntent(existing, intent) || (existing.hadUncertainOutcome && !intent.hadUncertainOutcome)))
      throw new Error('原请求记录不匹配，不能覆盖或回退未知状态')
    const encoded = JSON.stringify(intent)
    sessionStorage.setItem(key, encoded)
    if (sessionStorage.getItem(key) !== encoded) throw new Error('原请求记录保存未确认')
    journalBlocked.value = false
  } catch (cause) {
    journalBlocked.value = true
    throw cause
  }
}
function clearIntent(intent: Pending): void {
  try {
    const key = journalKey(intent.adminId, intent.environmentId)
    if (sessionStorage.getItem(key) !== JSON.stringify(intent)) throw new Error('原请求记录缺失或变化，不能解除锁定')
    sessionStorage.removeItem(key)
    if (sessionStorage.getItem(key) !== null) throw new Error('原请求记录清除未确认')
    memoryIntents.delete(key)
    journalBlocked.value = false
  } catch (cause) {
    journalBlocked.value = true
    throw cause
  }
}
function readIntent(owner: number, environmentId: number): Pending | null {
  const raw = sessionStorage.getItem(journalKey(owner, environmentId))
  if (raw === null) {
    if (sessionStorage.getItem(`sand-ai-tool-setup-pending-connection:${environmentId}`)) {
      throw new Error('旧请求记录缺少原管理员与目标回执，不能作为未发送证明')
    }
    return null
  }
  const value: unknown = JSON.parse(raw)
  if (typeof value !== 'object' || value === null || Array.isArray(value)) throw new Error('无效请求记录')
  const item = value as Record<string, unknown>
  const steps: Step[] = ['connection', 'draft', 'publish', 'bind', 'revoke', 'discovery']
  if (Object.keys(item).some(field => !['version', 'adminId', 'environmentId', 'step', 'key', 'target', 'target_sha256', 'hadUncertainOutcome'].includes(field))
    || item.version !== 1 || item.adminId !== owner || item.environmentId !== environmentId
    || typeof item.step !== 'string' || !steps.some(step => step === item.step)
    || typeof item.key !== 'string' || !item.key || typeof item.target_sha256 !== 'string'
    || !/^[a-f0-9]{64}$/.test(item.target_sha256) || typeof item.hadUncertainOutcome !== 'boolean'
    || typeof item.target !== 'object' || item.target === null || Array.isArray(item.target)
    || Object.values(item.target).some(part => typeof part !== 'string' && typeof part !== 'number')) {
    throw new Error('无效请求记录')
  }
  // Narrowed journal contains only local target identifiers, never a submitted DTO.
  return { version: 1, adminId: owner, environmentId, step: item.step as Step,
    key: item.key, target: item.target as Target, target_sha256: item.target_sha256,
    hadUncertainOutcome: item.hadUncertainOutcome }
}
async function targetHash(target: Target): Promise<string> {
  const sorted = Object.fromEntries(Object.keys(target).sort().map(key => [key, target[key]]))
  const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(JSON.stringify(sorted)))
  return Array.from(new Uint8Array(digest), byte => byte.toString(16).padStart(2, '0')).join('')
}

async function loadConnections(): Promise<void> {
  if (!props.environmentId || connectionLoading.value || (connectionPage > 0 && !connectionMore.value)) return
  const environmentId = props.environmentId
  const current = connectionEpoch
  connectionLoading.value = true
  connectionListError.value = ''
  try {
    const result = await toolSetupApi.connections(environmentId, connectionPage + 1)
    if (current !== connectionEpoch) return
    connections.value.push(...result.data)
    connectionPage += 1
    connectionMore.value = connectionPage * 100 < result.total
  } catch {
    if (current === connectionEpoch) connectionListError.value = '无法读取已有连接；仍可创建新连接'
  } finally {
    if (current === connectionEpoch) connectionLoading.value = false
  }
}

async function restoreSavedConnection(): Promise<void> {
  if (!props.environmentId) return
  const environmentId = props.environmentId
  const saved = savedConnectionId(environmentId)
  if (saved === null || pending.value || journalBlocked.value) return
  const current = connectionEpoch
  try {
    const result = await toolSetupApi.readConnection(environmentId, saved)
    if (current !== connectionEpoch || connection.value) return
    if (!connections.value.some(item => item.connection_id === result.connection_id)) {
      connections.value.unshift(result)
    }
    selectConnection(result)
  } catch {
    if (current !== connectionEpoch) return
    saveConnectionId(environmentId, null)
    connectionListError.value = '上次连接已不可用；请选择当前有效连接或创建新连接'
  }
}

function selectConnection(value: ToolConnectionRef): void {
  if (!props.environmentId || pending.value || draft.value) return
  connection.value = value
  connectionEnvironmentId.value = props.environmentId
  connectionForm.code = value.code
  connectionForm.name = value.name
  connectionForm.dataClass = value.data_class
  connectionForm.origin = ''
  connectionForm.path = ''
  credential.value = ''
  discovery.value = null
  snapshotItemId.value = null
  workspaceCode.value = savedToolCode(props.environmentId, value.connection_id)
  bindingConflict.value = false
  saveConnectionId(props.environmentId, value.connection_id)
  if (workspaceCode.value) void loadWorkspace()
}

function chooseConnection(value: number | null): void {
  if (busy.value || pending.value || draft.value || !props.environmentId) return
  if (value === null || value === 0) {
    connection.value = null
    connectionEnvironmentId.value = null
    discovery.value = null
    snapshotItemId.value = null
    workspaceCode.value = ''
    bindingConflict.value = false
    saveConnectionId(props.environmentId, null)
    return
  }
  const found = connections.value.find(item => item.connection_id === value)
  if (found) selectConnection(found)
}

async function loadWorkspace(): Promise<void> {
  if (!props.environmentId || !connection.value || environmentMismatch.value || pending.value
    || busy.value || !workspaceCode.value.trim()) return
  const current = epoch
  const code = workspaceCode.value.trim()
  busy.value = true
  error.value = ''
  try {
    const result = await toolSetupApi.workspace(props.environmentId, connection.value.connection_id, code)
    if (current !== epoch || connection.value === null || code !== workspaceCode.value.trim()) return
    adapter.value = result.tool.adapter === 'mcp_streamable_http' ? 'mcp_streamable_http' : 'standard_readonly_http'
    toolForm.code = result.tool.code
    toolForm.name = result.tool.name
    draft.value = result.draft ?? result.published
    published.value = result.draft ? null : result.published
    bindingConflict.value = result.draft === null && result.binding !== null
      && (result.binding.connection_id !== connection.value.connection_id
        || result.binding.connection_revision_id !== connection.value.connection_revision_id)
    bound.value = result.draft !== null || bindingConflict.value ? null : result.binding
    saveToolCode(props.environmentId, connection.value.connection_id, code)
  } catch (cause) {
    if (current === epoch) error.value = cause instanceof Error ? cause.message : '工具工作区读取失败'
  } finally {
    if (current === epoch) busy.value = false
  }
}

async function loadLatestMcp(): Promise<void> {
  if (!props.environmentId || !connection.value || environmentMismatch.value || pending.value || busy.value) return
  const current = epoch
  busy.value = true
  error.value = ''
  try {
    const result = await toolSetupApi.latestMcp(props.environmentId, connection.value.connection_id,
      connection.value.connection_revision_id)
    if (current !== epoch) return
    discovery.value = result
    snapshotItemId.value = null
  } catch (cause) {
    if (current === epoch) error.value = cause instanceof Error ? cause.message : '已验证快照读取失败'
  } finally {
    if (current === epoch) busy.value = false
  }
}

function discoveryMetering(value: unknown): string {
  if (value === null || value === undefined) return '未提供计量，待核实。'
  try {
    return JSON.stringify(value, null, 2) ?? '未提供计量，待核实。'
  } catch {
    return '当前计量无法展示，请读取原请求回执核对；不推算用量或金额。'
  }
}

function stepLabel(step: Step): string {
  return ({ connection: '连接创建', discovery: 'MCP 发现', draft: '工具草稿', publish: '工具发布', bind: '环境绑定', revoke: '工具撤销' })[step]
}

async function perform<T>(step: Step, payload: object, call: (key: string) => Promise<T>): Promise<T | null> {
  if (busy.value || mutationLocked.value || !adminId.value || !props.environmentId) return null
  const current = epoch
  const owner = adminId.value
  const environmentId = props.environmentId
  busy.value = true
  writeFlight.value = true
  error.value = ''
  let intent: Pending | null = null
  try {
    const values = payload as Record<string, unknown>
    let target: Target
    if (step === 'connection') target = { code: String(values.code) }
    else if (step === 'draft') target = adapter.value === 'mcp_streamable_http'
      ? { adapter: adapter.value, mcp_snapshot_item_id: Number(values.mcp_snapshot_item_id) }
      : { adapter: adapter.value, code: String(values.code) }
    else if (step === 'publish' || step === 'revoke') target = { tool_id: Number(values.tool_id) }
    else if (step === 'discovery') target = {
      connection_id: Number(values.connection_id), connection_revision_id: Number(values.connection_revision_id) }
    else target = { tool_id: Number(values.tool_id), tool_version_id: Number(values.tool_version_id),
      connection_id: Number(values.connection_id), connection_revision_id: Number(values.connection_revision_id) }
    const hash = await targetHash(target)
    if (current !== epoch) return null
    intent = { version: 1, adminId: owner, environmentId, step, key: newRuntimeRequestId(`tool-${step}`),
      target, target_sha256: hash, hadUncertainOutcome: false }
    pending.value = intent
    memoryIntents.set(journalKey(owner, environmentId), intent)
    const original = intent
    const isCurrent = () => current === epoch && owner === adminId.value && environmentId === props.environmentId
      && pending.value !== null && sameIntent(pending.value, original)
    notSubmittedHere.value = true
    // This closure owns the original payload only until its first actual dispatch.
    const dispatch = async (): Promise<T> => {
      if (!isCurrent() || !notSubmittedHere.value) throw new Error('原提交内容已失效，仅能读取原回执')
      if (await targetHash(original.target) !== original.target_sha256) throw new Error('原请求目标不匹配')
      if (!isCurrent() || !notSubmittedHere.value) throw new Error('原操作范围已变化')
      persistIntent(original)
      original.hadUncertainOutcome = true
      persistIntent(original)
      // Persisting an uncertain marker is not dispatch proof. Consume the local
      // not-sent permission only immediately before invoking the original call.
      notSubmittedHere.value = false
      retryOriginal.value = null
      return call(original.key)
    }
    retryOriginal.value = async () => {
      if (busy.value || writeFlight.value || !isCurrent() || !notSubmittedHere.value) return
      busy.value = true
      writeFlight.value = true
      error.value = ''
      try {
        await dispatch()
        if (isCurrent()) await recoverPending(true)
      } catch {
        if (isCurrent()) error.value = notSubmittedHere.value
          ? '原请求尚未发送，保存原记录未确认；恢复存储后可按原号发送。'
          : '原请求结果未确认，仅可继续读取原回执。'
      } finally {
        writeFlight.value = false
        if (current === epoch) busy.value = false
      }
    }
    const result = await dispatch()
    if (current !== epoch) return null
    const receipt = await toolSetupApi.operationReceipt(original.step, original.environmentId, original.key, original.target_sha256)
    if (!isCurrent()) return null
    if (!receiptMatches(receipt, original)) throw new Error('原请求回执不匹配')
    if (isRejectedReceipt(receipt)) {
      rejectedReceipt.value = receipt
      retryOriginal.value = null
      notSubmittedHere.value = false
      return null
    }
    if (!responseMatchesReceipt(receipt, result)) throw new Error('原请求回执与提交结果不一致')
    clearIntent(intent)
    pending.value = null
    retryOriginal.value = null
    notSubmittedHere.value = false
    return result
  } catch (cause) {
    if (current !== epoch) return null
    // A server refusal is not proof that an original operation was never submitted.
    const conflict = knownConflict(cause)
    if (!intent || pending.value === null) {
      journalBlocked.value = true
      error.value = '无法持久保存原请求记录，未发送配置请求。'
    } else {
      if (step === 'connection') credential.value = ''
      error.value = notSubmittedHere.value
        ? '本页确认原请求尚未发送。原内容仅在内存保留；恢复浏览器存储后按原号发送，离开页面后仅能读取原回执。'
        : conflict
        ? `${conflict} 原请求结果仍需读取确认，当前请求键保持保留。`
        : '结果尚未确认。请读取原请求结果；刷新后无需重新输入密钥或 Schema。'
    }
    return null
  } finally {
    writeFlight.value = false
    if (current === epoch) busy.value = false
  }
}
function isRejectedReceipt(receipt: ToolOperationReceipt): receipt is ToolOperationRejectedReceipt {
  return 'state' in receipt.result && receipt.result.state === 'rejected' && 'submission_state' in receipt.result
    && receipt.result.submission_state === 'not_submitted' && 'error_code' in receipt.result
    && typeof receipt.result.error_code === 'string' && receipt.result.error_code.length > 0
}
function positiveToolId(value: unknown): value is number {
  return typeof value === 'number' && Number.isSafeInteger(value) && value > 0
}
function completeReceiptResult(receipt: ToolOperationReceipt): boolean {
  if (isRejectedReceipt(receipt)) return true
  if (receipt.operation === 'create_connection')
    return positiveToolId(receipt.result.connection_id) && positiveToolId(receipt.result.connection_revision_id) &&
      typeof receipt.result.has_credential === 'boolean'
  if (receipt.operation === 'mcp_discovery')
    return positiveToolId(receipt.result.snapshot_id) && positiveToolId(receipt.result.connection_id) &&
      positiveToolId(receipt.result.connection_revision_id) && typeof receipt.result.state === 'string' &&
      receipt.result.state.length > 0 && Array.isArray(receipt.result.tools) && receipt.result.tools.every(item =>
        typeof item === 'object' && item !== null && positiveToolId(item.snapshot_item_id) &&
        typeof item.code === 'string' && typeof item.name === 'string' && typeof item.remote_name === 'string')
  if (receipt.operation === 'bind_environment')
    return positiveToolId(receipt.result.binding_id) && positiveToolId(receipt.result.environment_id) &&
      positiveToolId(receipt.result.tool_id) && positiveToolId(receipt.result.tool_version_id) &&
      positiveToolId(receipt.result.connection_id) && positiveToolId(receipt.result.connection_revision_id) &&
      typeof receipt.result.state === 'string' && receipt.result.state.length > 0
  return positiveToolId(receipt.result.tool_id) && positiveToolId(receipt.result.tool_version_id) &&
    positiveToolId(receipt.result.version) && typeof receipt.result.code === 'string' && receipt.result.code.length > 0 &&
    typeof receipt.result.adapter === 'string' && receipt.result.adapter.length > 0 &&
    typeof receipt.result.state === 'string' && receipt.result.state.length > 0
}
function receiptMatches(receipt: ToolOperationReceipt, intent: Pending): boolean {
  const names = { connection: 'create_connection', draft: 'create_draft', publish: 'publish',
    bind: 'bind_environment', revoke: 'revoke', discovery: 'mcp_discovery' }
  if (receipt.environment_id !== intent.environmentId || receipt.request_key !== intent.key
    || receipt.target_sha256 !== intent.target_sha256 || receipt.operation !== names[intent.step]) return false
  if (!completeReceiptResult(receipt)) return false
  if (isRejectedReceipt(receipt)) return true
  if (receipt.operation === 'create_connection') return receipt.result.code === intent.target.code
  if (receipt.operation === 'mcp_discovery') return receipt.result.connection_id === intent.target.connection_id
    && receipt.result.connection_revision_id === intent.target.connection_revision_id
  if (receipt.operation === 'bind_environment') return receipt.result.environment_id === intent.environmentId
    && receipt.result.tool_id === intent.target.tool_id && receipt.result.tool_version_id === intent.target.tool_version_id
    && receipt.result.connection_id === intent.target.connection_id
    && receipt.result.connection_revision_id === intent.target.connection_revision_id
  if (receipt.operation === 'publish' || receipt.operation === 'revoke') return receipt.result.tool_id === intent.target.tool_id
  if (receipt.operation === 'create_draft') return receipt.result.adapter === intent.target.adapter
    && (intent.target.adapter === 'mcp_streamable_http' || receipt.result.code === intent.target.code)
  return false
}
function responseMatchesReceipt(receipt: ToolOperationReceipt, response: unknown): boolean {
  if (isRejectedReceipt(receipt) || typeof response !== 'object' || response === null || Array.isArray(response)) return false
  const row = response as Record<string, unknown>
  if (receipt.operation === 'create_connection')
    return row.connection_id === receipt.result.connection_id && row.connection_revision_id === receipt.result.connection_revision_id &&
      row.code === receipt.result.code && row.has_credential === receipt.result.has_credential
  if (receipt.operation === 'mcp_discovery')
    return row.snapshot_id === receipt.result.snapshot_id && row.state === receipt.result.state &&
      row.connection_id === receipt.result.connection_id && row.connection_revision_id === receipt.result.connection_revision_id
  if (receipt.operation === 'bind_environment')
    return row.binding_id === receipt.result.binding_id && row.state === receipt.result.state && row.environment_id === receipt.result.environment_id &&
      row.tool_id === receipt.result.tool_id && row.tool_version_id === receipt.result.tool_version_id &&
      row.connection_id === receipt.result.connection_id && row.connection_revision_id === receipt.result.connection_revision_id
  return row.tool_id === receipt.result.tool_id && row.tool_version_id === receipt.result.tool_version_id && row.code === receipt.result.code &&
    row.version === receipt.result.version && row.adapter === receipt.result.adapter && row.state === receipt.result.state
}
async function recoverPending(alreadyBusy = false): Promise<void> {
  const intent = pending.value
  if (!intent || (!alreadyBusy && (busy.value || writeFlight.value)) || intent.adminId !== adminId.value
    || intent.environmentId !== props.environmentId) return
  const current = epoch
  busy.value = true
  error.value = ''
  try {
    // Reconfirm the same opaque journal after storage recovery before reading the original receipt.
    persistIntent(intent)
    if (await targetHash(intent.target) !== intent.target_sha256) throw new Error('原请求目标不匹配')
    if (current !== epoch || pending.value !== intent) return
    const receipt = await toolSetupApi.operationReceipt(intent.step, intent.environmentId, intent.key, intent.target_sha256)
    if (current !== epoch || pending.value !== intent) return
    if (!receiptMatches(receipt, intent)) throw new Error('原请求回执不匹配')
    if (isRejectedReceipt(receipt)) {
      rejectedReceipt.value = receipt
      retryOriginal.value = null
      notSubmittedHere.value = false
      credential.value = ''
      error.value = ''
      // Keep the original opaque intent until the administrator explicitly acknowledges the refusal.
      return
    }
    // Rehydrate the current permitted connection without guessing or reposting an original DTO.
    if (receipt.operation !== 'create_connection' && receipt.operation !== 'revoke' && !connection.value) {
      const connectionId = typeof intent.target.connection_id === 'number'
        ? intent.target.connection_id : savedConnectionId(intent.environmentId)
      if (connectionId === null) throw new Error('尚不能恢复原连接')
      const restored = await toolSetupApi.readConnection(intent.environmentId, connectionId)
      if (current !== epoch || pending.value !== intent) return
      if (typeof intent.target.connection_revision_id === 'number'
        && restored.connection_revision_id !== intent.target.connection_revision_id) throw new Error('原连接修订已变化')
      connection.value = restored
      connectionEnvironmentId.value = intent.environmentId
      connectionForm.code = restored.code
      connectionForm.name = restored.name
      connectionForm.dataClass = restored.data_class
    }
    clearIntent(intent)
    pending.value = null
    retryOriginal.value = null
    notSubmittedHere.value = false
    if (receipt.operation === 'create_connection') {
      connection.value = receipt.result
      connectionEnvironmentId.value = intent.environmentId
      saveConnectionId(intent.environmentId, receipt.result.connection_id)
      if (!connections.value.some(item => item.connection_id === receipt.result.connection_id)) connections.value.unshift(receipt.result)
      credential.value = ''
    } else if (receipt.operation === 'mcp_discovery') discovery.value = receipt.result
    else if (receipt.operation === 'create_draft') {
      draft.value = receipt.result
      adapter.value = receipt.result.adapter === 'mcp_streamable_http' ? 'mcp_streamable_http' : 'standard_readonly_http'
      workspaceCode.value = receipt.result.code
      if (connection.value) saveToolCode(intent.environmentId, connection.value.connection_id, receipt.result.code)
    }
    else if (receipt.operation === 'publish') {
      draft.value = receipt.result; published.value = receipt.result
      workspaceCode.value = receipt.result.code
      if (connection.value) saveToolCode(intent.environmentId, connection.value.connection_id, receipt.result.code)
    }
    else if (receipt.operation === 'bind_environment') {
      bound.value = receipt.result
      workspaceCode.value = connection.value ? savedToolCode(intent.environmentId, connection.value.connection_id) : ''
    }
    else if (receipt.operation === 'revoke') revoked.value = receipt.result
    error.value = ''
  } catch { if (current === epoch) error.value = '尚未找回原请求成功回执。未找到、无权限或处理中都不能证明未执行，请保留原请求继续恢复。' }
  finally { if (current === epoch && !alreadyBusy) busy.value = false }
}
function acknowledgeRejected(): void {
  const intent = pending.value
  const receipt = rejectedReceipt.value
  if (busy.value || writeFlight.value || journalBlocked.value || !intent || !receipt
    || adminId.value !== intent.adminId || props.environmentId !== intent.environmentId
    || !receiptMatches(receipt, intent)) return
  try { clearIntent(intent) }
  catch { journalBlocked.value = true; error.value = '无法保存原拒绝确认，当前请求记录继续保留。'; return }
  pending.value = null
  retryOriginal.value = null
  notSubmittedHere.value = false
  rejectedReceipt.value = null
  epoch++
  resetForms()
  busy.value = false
  rejectionHistory.value = `已确认原请求 ${intent.key} 被拒绝（${receipt.result.error_code}）；可修改配置后自行提交。`
}
function retryPending(): void { if (retryOriginal.value) void retryOriginal.value() }

function knownConflict(cause: unknown): string | null {
  if (!(cause instanceof HttpError)) return null
  return ({
    'Managed connection code already exists in this environment': '此环境已有相同连接代码。请从“已有连接”选择它，或修改连接代码后创建。',
    'Revoked tool code cannot be reused': '此工具代码已撤销，不能复用。请填写新的工具代码。',
    'Tool already has a draft version': '此工具代码已有草稿。请在“已有工具代码”读取服务端状态后继续配置。',
    'Tool code is already assigned to another adapter': '此工具代码已用于另一种连接类型。请选择对应工具，或使用新的工具代码。',
    'Tool version is already bound differently in this environment': '此工具版本已绑定到本环境的另一连接。请读取原工具状态，核对连接后继续。'
  } as Record<string, string>)[cause.message] ?? null
}

function objectSchema(value: string, label: string): Record<string, unknown> | null {
  try {
    const parsed: unknown = JSON.parse(value)
    if (typeof parsed === 'object' && parsed !== null && !Array.isArray(parsed)) {
      parseToolSchema(parsed)
      return parsed as Record<string, unknown>
    }
  } catch (cause) {
    error.value = `${label}：${cause instanceof Error ? cause.message : 'Schema 无效'}`
    return null
  }
  error.value = `${label}必须是 JSON 对象`
  return null
}

function freezeConnectionInput(inputId: string, environmentId: number): void {
  connectionInputs.set(inputId, {
    environment_id: environmentId, code: connectionForm.code, name: connectionForm.name,
    origin: connectionForm.origin, path: connectionForm.path, data_class: connectionForm.dataClass,
    authorization: authMode.value === 'bearer'
      ? { mode: 'bearer', credential: credential.value.trim() } : { mode: 'none' }
  })
  credential.value = ''
  connectionForm.origin = ''
  connectionForm.path = ''
}
function dispatchConnectionInput(inputId: string, key: string) {
  const input = connectionInputs.get(inputId)
  connectionInputs.delete(inputId)
  if (!input) throw new Error('原私有输入已清除，不能重新发送；仅能读取原回执')
  return toolSetupApi.createConnection(input, key)
}
function createConnection(): Promise<void> | undefined {
  if (!props.environmentId || busy.value || mutationLocked.value || !connectionReady.value || connection.value) return
  const environmentId = props.environmentId
  const owner = adminId.value
  const current = epoch
  const inputId = newRuntimeRequestId('tool-connection-input')
  const code = connectionForm.code
  freezeConnectionInput(inputId, environmentId)
  return perform('connection', { code }, key => dispatchConnectionInput(inputId, key)).then(result => {
    if (!result || current !== epoch || owner !== adminId.value || environmentId !== props.environmentId) return
    connection.value = result
    connectionEnvironmentId.value = environmentId
    workspaceCode.value = ''
    bindingConflict.value = false
    saveConnectionId(environmentId, result.connection_id)
    connections.value.unshift(result)
  }).finally(() => {
    // Only the existing, positively unsent first-dispatch path may retain input.
    if (current !== epoch || !notSubmittedHere.value) connectionInputs.delete(inputId)
  })
}

async function discover(): Promise<void> {
  if (!props.environmentId || props.environmentId !== connectionEnvironmentId.value || !connection.value || draft.value) return
  const payload = {
    environment_id: props.environmentId, connection_id: connection.value.connection_id,
    connection_revision_id: connection.value.connection_revision_id
  }
  const result = await perform('discovery', payload, key => toolSetupApi.discover(
    payload.environment_id, payload.connection_id, payload.connection_revision_id, key))
  if (!result) return
  discovery.value = result
  snapshotItemId.value = null
}

async function createDraft(): Promise<void> {
  if (!props.environmentId || mutationLocked.value || !connection.value || environmentMismatch.value || draft.value) return
  if (adapter.value === 'mcp_streamable_http') {
    if (!snapshotItemId.value || !discovery.value) return
    const selected = discovery.value.tools.find(item => item.snapshot_item_id === snapshotItemId.value)
    if (!selected) return
    workspaceCode.value = selected.code
    if (props.environmentId) saveToolCode(props.environmentId, connection.value.connection_id, selected.code)
    const payload = { environment_id: props.environmentId, adapter: 'mcp_streamable_http' as const, mcp_snapshot_item_id: snapshotItemId.value,
      review_policy: { review_required: reviewRequired.value } }
    const result = await perform('draft', payload, key => toolSetupApi.createDraft(payload, key))
    if (result) {
      draft.value = result
      workspaceCode.value = selected.code
      if (props.environmentId) saveToolCode(props.environmentId, connection.value.connection_id, selected.code)
    }
    return
  }
  const inputSchema = objectSchema(toolForm.inputSchema, '输入 Schema')
  const outputSchema = objectSchema(toolForm.outputSchema, '输出 Schema')
  if (!inputSchema || !outputSchema) return
  const payload = {
    environment_id: props.environmentId, adapter: 'standard_readonly_http' as const, code: toolForm.code.trim(), name: toolForm.name.trim(),
    description: toolForm.description.trim(), input_schema: inputSchema, output_schema: outputSchema,
    review_policy: { review_required: reviewRequired.value }
  }
  workspaceCode.value = payload.code
  if (props.environmentId) saveToolCode(props.environmentId, connection.value.connection_id, payload.code)
  const result = await perform('draft', payload, key => toolSetupApi.createDraft(payload, key))
  if (result) {
    draft.value = result
    workspaceCode.value = result.code
    if (props.environmentId) saveToolCode(props.environmentId, connection.value.connection_id, result.code)
  }
}

async function publish(): Promise<void> {
  if (!props.environmentId || !draft.value || environmentMismatch.value || published.value) return
  const toolId = draft.value.tool_id
  const environmentId = props.environmentId
  const result = await perform('publish', { environment_id: props.environmentId, tool_id: toolId }, key => toolSetupApi.publish(environmentId, toolId, key))
  if (result) published.value = result
}

async function bind(): Promise<void> {
  if (!props.environmentId || props.environmentId !== connectionEnvironmentId.value
    || !connection.value || !published.value || bound.value) return
  const payload = {
    environment_id: props.environmentId, tool_id: published.value.tool_id,
    tool_version_id: published.value.tool_version_id,
    connection_id: connection.value.connection_id,
    connection_revision_id: connection.value.connection_revision_id
  }
  const result = await perform('bind', payload, key => toolSetupApi.bind(
    payload.environment_id, payload.tool_id, payload.tool_version_id,
    payload.connection_id, payload.connection_revision_id, key))
  if (result) bound.value = result
}

async function revoke(): Promise<void> {
  if (!props.environmentId || !published.value || busy.value || mutationLocked.value || !hasAuth('sand_ai:tool:revoke')) return
  const current = epoch
  const toolId = published.value.tool_id
  const environmentId = props.environmentId
  try { await ElMessageBox.confirm(`确认撤销工具 ${published.value.code}？撤销后此工具代码不可复用。`, '撤销已发布工具',
    { confirmButtonText: '确认撤销', cancelButtonText: '取消', type: 'warning' }) }
  catch { return }
  if (current !== epoch) return
  const result = await perform('revoke', { environment_id: environmentId, tool_id: toolId },
    key => toolSetupApi.revoke(environmentId, toolId, key))
  if (result) revoked.value = result
}

function nextSetup(): void {
  if (pending.value || busy.value || writeFlight.value || journalBlocked.value) return
  epoch++
  resetForms()
}

function resetForms(): void {
  revoked.value = null
  if (connectionEnvironmentId.value && connection.value) {
    saveConnectionId(connectionEnvironmentId.value, null)
    saveToolCode(connectionEnvironmentId.value, connection.value.connection_id, '')
  }
  connectionForm.code = ''
  connectionForm.name = ''
  connectionForm.origin = ''
  connectionForm.path = ''
  connectionForm.dataClass = ''
  authMode.value = 'none'
  adapter.value = 'mcp_streamable_http'
  toolForm.code = ''
  toolForm.name = ''
  toolForm.description = ''
  toolForm.inputSchema = '{"type":"object","additionalProperties":false}'
  toolForm.outputSchema = '{"type":"object","additionalProperties":false}'
  reviewRequired.value = false
  connection.value = null
  connectionEnvironmentId.value = null
  discovery.value = null
  snapshotItemId.value = null
  draft.value = null
  published.value = null
  bound.value = null
  workspaceCode.value = ''
  bindingConflict.value = false
  credential.value = ''
  error.value = ''
}

watch([() => props.environmentId, adminId, () => userStore.accessToken], () => {
  epoch++
  connectionEpoch++
  retryOriginal.value = null
  notSubmittedHere.value = false
  connectionInputs.clear()
  pending.value = null
  rejectedReceipt.value = null
  rejectionHistory.value = ''
  journalBlocked.value = false
  // Scope changes erase all private drafts/results, while each original scope keeps its opaque journal.
  connection.value = null
  resetForms()
  busy.value = false
  connectionPage = 0
  connections.value = []
  connectionMore.value = false
  connectionLoading.value = false
  connectionListError.value = ''
  if (!props.environmentId || !adminId.value) return
  const key = journalKey(adminId.value, props.environmentId)
  pending.value = memoryIntents.get(key) ?? null
  try {
    const saved = readIntent(adminId.value, props.environmentId)
    if (saved) {
      if (pending.value && !sameIntent(pending.value, saved)) throw new Error('原请求记录与内存原意图不一致')
      if (pending.value) pending.value.hadUncertainOutcome ||= saved.hadUncertainOutcome
      else pending.value = saved
      memoryIntents.set(key, pending.value)
    } else if (pending.value) journalBlocked.value = true
  } catch { journalBlocked.value = true; return }
  void loadConnections()
  if (pending.value) void recoverPending()
  else void restoreSavedConnection()
}, { immediate: true, flush: 'sync' })
onUnmounted(() => { ++epoch; retryOriginal.value = null; notSubmittedHere.value = false; connectionInputs.clear(); credential.value = '' })
</script>

<style scoped>
.panel-head h3 { margin: 0; }
.panel-head p, .hint { color: var(--el-text-color-secondary); }
.notice { margin-bottom: 12px; }
.step-result { margin-left: 12px; color: var(--el-text-color-secondary); }
.metering-summary { white-space: pre-wrap; overflow-wrap: anywhere; max-height: 240px; overflow: auto; }
h4 { margin: 20px 0 12px; }
.hint { margin: 4px 0 12px 110px; font-size: 13px; }
</style>
