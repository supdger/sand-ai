<template>
  <ElCard shadow="never">
    <template #header><h3>AI 工作流</h3><p>把已发布服务组成多个节点，设置依赖和参数来源。保存草稿后明确发布，应用使用固定版本运行。</p></template>
    <ElEmpty v-if="!environmentId" description="先选择应用环境" />
    <template v-else>
      <ElAlert v-if="error" :title="error" type="warning" :closable="false" />
      <ElSpace wrap>
        <ElSelect v-model="selectedCode" placeholder="读取已有工作流" :disabled="busy || Boolean(adminPending)"><ElOption v-for="item in definitions" :key="item.code" :label="item.name" :value="item.code" /></ElSelect>
        <ElButton :disabled="busy || Boolean(adminPending)" @click="loadDefinition">读取版本</ElButton>
        <ElButton :disabled="busy || Boolean(adminPending)" @click="newDefinition">新建工作流</ElButton>
        <ElButton :disabled="busy" @click="loadCatalog">刷新可组合服务</ElButton>
      </ElSpace>
      <ElSpace v-if="pendingAdminCodes.length" wrap>
        <span>本身份与环境留存的原管理操作：</span>
        <ElButton v-for="target in pendingAdminCodes" :key="target" :disabled="busy || Boolean(adminPending)" @click="openAdminRecovery(target)">找回 {{ target }}</ElButton>
      </ElSpace>
      <ElAlert v-if="adminPending" type="warning" :closable="false" :title="adminSent ? '管理操作结果尚未确认，请沿原请求号找回' : '本页尚未发送：请保存原请求号后明确提交原配置'" />
      <p v-if="adminPending">原操作：{{ adminPending === 'draft' ? '保存草稿' : adminPending === 'publish' ? '发布草稿' : '停用工作流' }} · {{ adminTarget?.code ?? code }} · 原请求号 {{ adminKey }}。恢复时不更改节点、依赖或服务版本。</p>
      <ElButton v-if="adminPending && adminSent" :disabled="busy" @click="recoverAdmin">只读找回管理操作</ElButton>
      <ElButton v-if="adminPending && !adminSent" :disabled="busy" @click="sendAdminOriginal()">保存原请求号并提交原配置</ElButton>
      <ElButton v-if="adminStorageError" :disabled="busy" @click="restoreAdmin">重新读取原操作记录</ElButton>
      <ElForm label-width="110px" :disabled="busy || Boolean(adminPending)">
        <ElFormItem label="工作流标识"><ElInput v-model="code" :disabled="Boolean(detail)" placeholder="例如 customer_reply" /></ElFormItem>
        <ElFormItem label="工作流名称"><ElInput v-model="name" placeholder="例如 客服资料查询与答复" /></ElFormItem>
        <ElFormItem label="读取版本"><ElSelect v-model="versionId" @change="selectVersion"><ElOption v-for="item in versions" :key="item.id" :value="item.id" :label="`第 ${item.version_no} 版 · ${item.state === 'draft' ? '草稿' : '已发布'}${detail?.definition.active_version_id === item.id ? ' · 当前版本' : ''}`" /></ElSelect></ElFormItem>
        <p>编辑已发布版本并保存会产生新草稿，原发布版本保留。当前草稿修订号：{{ revision }}。</p>
        <ElAlert v-if="!catalog.length" type="info" :closable="false" title="本环境尚无可组合的已发布服务，请先发布服务再配置节点" />
        <section v-for="(node, position) in nodes" :key="node.key" class="node">
          <h4>节点 {{ position + 1 }}</h4>
          <ElFormItem label="节点标识"><ElInput v-model="node.id" :disabled="nodeIdReferenced(node)" placeholder="例如 lookup（小写字母、数字、下划线）" /><p v-if="nodeIdReferenced(node)">此标识已被依赖、参数或成果节点引用；先移除这些引用后再修改。</p></ElFormItem>
          <ElFormItem label="中文名称"><ElInput v-model="node.name" placeholder="例如 查询退款规则" /></ElFormItem>
          <ElFormItem label="已发布服务"><ElSelect v-model="node.serviceKey" filterable @change="selectService(node)"><ElOption v-for="service in catalog" :key="serviceKey(service)" :value="serviceKey(service)" :label="`${kindLabel(service.kind)} · ${service.name} · 第 ${service.version} 版`" /></ElSelect><p v-if="node.serviceKey && !nodeService(node)">原服务版本不在当前目录中，保留绑定；重新发布前需核对服务。</p></ElFormItem>
          <ElFormItem label="依赖节点"><ElSelect v-model="node.depends" multiple placeholder="这些节点成功后才执行"><ElOption v-for="other in nodes.filter(item => item.key !== node.key && item.id)" :key="other.key" :value="other.id" :label="`${other.name || other.id} · ${other.id}`" /></ElSelect></ElFormItem>
          <div v-for="binding in node.bindings" :key="binding.field">
            <ElCheckbox v-if="!binding.required" v-model="binding.enabled">配置 {{ binding.label }}（可选）</ElCheckbox>
            <template v-if="binding.enabled">
            <ElFormItem :label="binding.label"><ElSelect v-model="binding.source"><ElOption label="本次输入" value="input" /><ElOption label="上游结果" value="node" /><ElOption label="固定值" value="literal" /></ElSelect><p v-if="node.service && fieldHelp(node.service, binding.field)">{{ fieldHelp(node.service, binding.field) }}</p></ElFormItem>
            <ElFormItem v-if="binding.source === 'node'" label="上游节点"><ElSelect v-model="binding.nodeId"><ElOption v-for="id in node.depends" :key="id" :label="id" :value="id" /></ElSelect></ElFormItem>
            <ElFormItem v-if="binding.source === 'input'" label="本次输入路径"><ElInput v-model="binding.path" placeholder="例如 /question（JSON Pointer）" /></ElFormItem>
            <ElFormItem v-else-if="binding.source === 'node'" label="上游成果字段">
              <ElSelect v-model="binding.path" filterable allow-create placeholder="选择已声明字段，或填写 JSON Pointer">
                <ElOption v-for="option in upstreamPaths(binding)" :key="option.path" :value="option.path" :label="option.label" />
              </ElSelect>
              <p>选项来自上游已发布版本的成果定义；手写路径须由服务端确认，未声明路径不能通过发布。</p>
              <details><summary>查看上游实际成果定义</summary><pre>{{ JSON.stringify(upstreamSchema(binding), null, 2) }}</pre></details>
            </ElFormItem>
            <ElFormItem v-else label="固定值"><CompositionSchemaValue v-model="binding.literal" :schema="binding.schema" :disabled="busy || Boolean(adminPending)" /></ElFormItem>
            <details><summary>查看 {{ binding.label }} 的实际输入定义</summary><pre>{{ JSON.stringify(binding.schema, null, 2) }}</pre></details>
            </template>
          </div>
          <ElButton type="danger" plain @click="removeNode(node.key)">移除此节点</ElButton>
        </section>
        <ElButton :disabled="nodes.length >= 12" @click="addNode">增加节点</ElButton>
        <ElFormItem label="节点失败后"><ElSelect v-model="failurePolicy"><ElOption label="停止后续节点" value="stop" /><ElOption label="继续无依赖的其他节点" value="continue_independent" /></ElSelect></ElFormItem>
        <ElFormItem label="成果节点"><ElSelect v-model="outputs" multiple><ElOption v-for="node in nodes.filter(item => item.id)" :key="node.key" :value="node.id" :label="node.name || node.id" /></ElSelect></ElFormItem>
        <ElSpace><ElButton type="primary" @click="mutate('draft')">保存草稿</ElButton><ElButton :disabled="!draftVersion || versionId !== draftVersion.id || dirty" @click="mutate('publish')">发布已保存草稿</ElButton><ElButton type="danger" :disabled="!detail?.definition.active_version_id" @click="mutate('disable')">停用工作流</ElButton></ElSpace>
      </ElForm>
      <ElDivider />
      <h3>应用版本试用</h3><p>使用应用凭证，逐节点查看原子运行。父工作流不额外计费，费用以各子运行记录为准。</p>
      <RuntimeApiKeyField v-model="apiKey" :persist-in-session="false" />
      <h4>按已有运行号只读查看</h4>
      <p>填写提交回执中的运行号；不需要本浏览器的原请求记录，也不需要先选择工作流版本。使用该运行所属应用的当前凭证读取，后台登录身份不能代替应用授权。</p>
      <ElForm label-width="130px" @submit.prevent="readExistingRun">
        <ElFormItem label="已有运行号"><ElInput v-model="existingRunNumber" inputmode="numeric" placeholder="例如 3（原提交回执中的编号）" :disabled="busy || runtimeReadBusy || Boolean(runtimePending) || Boolean(purgePending)" /></ElFormItem>
        <ElSpace>
          <ElButton native-type="submit" :loading="runtimeReadBusy" :disabled="busy || runtimeReadBusy || !apiKey.trim() || !existingRunNumber.trim() || Boolean(runtimePending) || Boolean(purgePending)">只读查看已有运行</ElButton>
          <ElButton v-if="existingRunView" :disabled="busy" @click="closeExistingRun">返回本页原运行</ElButton>
        </ElSpace>
      </ElForm>
      <p v-if="runtimePending || purgePending">先找回本页未确认的原操作，再查看其他已有运行；不会覆盖原请求记录。</p>
      <ElAlert v-if="existingRunView" type="info" :closable="false" title="当前仅查看已有运行，不续行、不重试、不清理输入，也不改变本页原请求记录。" />
      <ElSelect v-model="runtimeVersion" :disabled="runtimeLocked" placeholder="选择已发布版本"><ElOption v-for="item in versions.filter(item => item.state === 'published')" :key="item.id" :value="item.version_no" :label="`第 ${item.version_no} 版`" /></ElSelect>
      <ElForm label-width="130px" :disabled="runtimeLocked">
        <ElFormItem v-for="path in inputPaths" :key="path" :label="inputLabel(path)"><CompositionSchemaValue :model-value="inputValues[path] ?? ''" :schema="inputSchema(path)" :disabled="runtimeLocked" @update:model-value="value => inputValues[path] = value" /></ElFormItem>
        <ElButton type="primary" :disabled="!apiKey.trim() || !runtimeVersion || !code || runtimeLocked" @click="submitRun">提交此版本</ElButton>
      </ElForm>
      <ElAlert v-if="purgePending" type="warning" :closable="false" title="清理结果尚未确认，请按原清理请求号只读找回；当前不会重复发送清理。" />
      <ElButton v-if="purgePending" :loading="runtimeReadBusy" :disabled="runtimeReadBusy || !apiKey.trim()" @click="recoverPurge">找回原清理操作</ElButton>
      <ElAlert v-if="runtimePending" type="warning" :closable="false" title="原操作结果尚未确认，先只读找回；不要换号重复发送" />
      <template v-if="runtimeUnsent"><p>本页确认原运行尚未提交。恢复存储后可明确提交同一版本、原输入与原号；刷新或更换身份后只能只读找回。</p><ElButton :loading="busy" :disabled="busy || runtimeReadBusy || !apiKey.trim()" @click="sendOriginalRun">保存原号后提交原运行</ElButton></template>
      <ElSpace><ElButton v-if="!existingRunView && (runtimePending || runId)" :loading="runtimeReadBusy" :disabled="runtimeReadBusy || !apiKey.trim()" @click="readRun">只读找回原运行</ElButton><ElButton v-if="!existingRunView && run && !runtimePending && !purgePending" :disabled="busy || run.input_state === 'purged'" @click="runAction('advance')">续行下一节点</ElButton><ElButton v-if="!existingRunView && run && !runtimePending && !purgePending" :disabled="busy || ['succeeded', 'cancelled'].includes(run.state)" @click="runAction('cancel')">停止后续节点</ElButton><ElButton v-if="!existingRunView && run && !runtimePending && !purgePending && (['succeeded', 'cancelled'].includes(run.state) || (run.state === 'partial_failed' && run.input_state === 'purged'))" :disabled="busy" @click="newRun">开始新的试用</ElButton></ElSpace>
      <template v-if="displayedRun"><p>原运行 #{{ displayedRun.id }} · {{ displayedRun.code }} · 第 {{ displayedRun.version }} 版 · {{ stateLabel(displayedRun.state) }}</p>
        <p>父输入：{{ displayedRun.input_state === 'purged' ? '已清理' : '留存中' }} · 留存截止：{{ displayedRun.retain_until }}<template v-if="displayedRun.content_purged_time"> · 清理时间：{{ displayedRun.content_purged_time }}</template></p>
        <p v-if="displayedRun.input_state === 'purged'">父输入与父成果正文已清理，当前原运行不能续行或重试。节点状态与费用保留；子服务成果按当前授权从原服务读取。</p>
        <ElButton v-if="!existingRunView" :disabled="busy || Boolean(runtimePending) || Boolean(purgePending) || displayedRun.input_state === 'purged' || !apiKey.trim()" @click="purgeInput">清理此运行私有父输入</ElButton><ElTable :data="displayedRun.nodes" row-key="id"><ElTableColumn prop="name" label="节点" /><ElTableColumn label="状态"><template #default="{ row }">{{ stateLabel(row.state) }}</template></ElTableColumn><ElTableColumn prop="error_code" label="失败原因" /><ElTableColumn label="原子运行"><template #default="{ row }"><pre>{{ display(row.child) }}</pre></template></ElTableColumn><ElTableColumn label="费用与来源"><template #default="{ row }"><ElButton :disabled="inspectionBusy || !apiKey.trim()" @click="inspectNode(row.id)">读取原运行费用与来源</ElButton></template></ElTableColumn><ElTableColumn label="操作"><template #default="{ row }"><ElButton v-if="!existingRunView && row.state === 'failed'" :disabled="busy || Boolean(runtimePending) || Boolean(purgePending) || displayedRun.input_state === 'purged'" @click="runAction('retry', row.id)">确认重试失败节点</ElButton><span v-if="row.state === 'unknown'">派发未明，只读找回</span></template></ElTableColumn></ElTable><template v-if="displayedRun.input_state !== 'purged'"><h4>当前获授权成果</h4><pre>{{ display(displayedRun.output) }}</pre></template></template>
      <section v-if="displayedRunId" aria-label="AI 工作流运行费用">
        <h4>运行费用 · #{{ displayedRunId }}</h4>
        <ElButton :loading="runCostLoading" :disabled="runCostLoading || !apiKey.trim()" @click="readRunCost">读取运行费用</ElButton>
        <p v-if="runCostLoading" role="status">正在读取当前凭证允许的子调用费用</p>
        <ElAlert v-if="runCostError" :title="runCostError" type="warning" :closable="false" />
        <template v-if="runCost">
          <p>父工作流不额外计费。预估金额未提供；请按节点原调用读取冻结预算上界。不同币种分别显示，未知金额不作零。</p>
          <ElAlert v-if="runCost.coverage === 'pending'" type="warning" :closable="false"
            title="费用覆盖尚未确认：以下仅为已记录子事件小计，不是完整运行总额；仍可能有未决调用或未确认的关联。请稍后重新读取。" />
          <p v-else>已确认无未决子调用且关联证据可用；计量或账单金额仍可能未知，按各列显示。</p>
          <ElAlert v-if="runCost.overall.truncated" type="warning" :closable="false" title="运行费用明细已截断，当前显示不包含全部币种行。" />
          <h5>{{ runCost.coverage === 'pending' ? '已记录子事件小计' : '运行子事件费用' }}</h5>
          <ElTable :data="runCost.overall.rows" border empty-text="暂无已记录子用量事件；金额未提供">
            <ElTableColumn label="币种" width="100"><template #default="{ row }">{{ row.currency ?? '未定价' }}</template></ElTableColumn>
            <ElTableColumn prop="total_events" label="事件数" width="100" />
            <ElTableColumn label="预估金额" min-width="140"><template #default>未提供</template></ElTableColumn>
            <ElTableColumn :label="runCost.coverage === 'pending' ? '已记录计量小计' : '计量金额'" min-width="180"><template #default="{ row }">{{ runMeteredAmount(row) }}</template></ElTableColumn>
            <ElTableColumn label="已核对账单金额" min-width="160"><template #default="{ row }">{{ row.reconciled_amount ?? '未核对' }}</template></ElTableColumn>
            <ElTableColumn label="已记录差额" min-width="140"><template #default="{ row }">{{ row.difference_amount ?? '—' }}</template></ElTableColumn>
          </ElTable>
          <h5>节点费用明细</h5>
          <section v-for="node in runCost.nodes" :key="node.node_code">
            <h5>{{ node.node_code }} · {{ stateLabel(node.state) }}</h5>
            <p v-if="node.coverage === 'complete' && node.rows.length === 0 && node.charge?.state === 'not_billable' && node.charge.basis === 'server_keyword_execution' && node.charge.metered_amount === '0' && node.charge.currency === null">仅关键词检索，不产生模型费用。服务端已核对原执行证明；此状态不代表供应商账单或基础设施成本。</p>
            <template v-else>
            <p>{{ node.coverage === 'pending' ? '节点费用覆盖尚未确认，以下仅为已记录子事件小计。' : '已确认此节点无未决子调用且关联证据可用。' }}</p>
            <ElTable :data="node.rows" border empty-text="暂无已记录子用量事件；金额未提供">
              <ElTableColumn label="币种" width="100"><template #default="{ row }">{{ row.currency ?? '未定价' }}</template></ElTableColumn>
              <ElTableColumn prop="total_events" label="事件数" width="100" />
              <ElTableColumn label="预估金额" min-width="140"><template #default>未提供</template></ElTableColumn>
              <ElTableColumn :label="node.coverage === 'pending' ? '已记录计量小计' : '计量金额'" min-width="180"><template #default="{ row }">{{ runMeteredAmount(row) }}</template></ElTableColumn>
              <ElTableColumn label="已核对账单金额" min-width="160"><template #default="{ row }">{{ row.reconciled_amount ?? '未核对' }}</template></ElTableColumn>
              <ElTableColumn label="已记录差额" min-width="140"><template #default="{ row }">{{ row.difference_amount ?? '—' }}</template></ElTableColumn>
            </ElTable>
            </template>
          </section>
        </template>
      </section>
      <section v-if="inspectedNode" aria-label="节点原运行费用与来源">
        <h4>{{ inspectedNode.name }} · 原运行费用与来源</h4>
        <p>子运行定位：{{ display(inspectedNode.child) }}。费用按原调用逐项读取；未知金额不作零，不跨币种相加。</p>
        <ElAlert v-if="inspectionError" :title="inspectionError" type="warning" :closable="false" />
        <p v-if="inspectionBusy" role="status">正在读取当前凭证允许的原运行信息</p>
        <ElSpace wrap><ElButton v-for="requestId in invocationRequests" :key="requestId" :disabled="inspectionBusy" @click="readNodeCost(requestId)">读取调用费用 · {{ requestId }}</ElButton></ElSpace>
        <p v-if="!inspectionBusy && !invocationRequests.length">原运行尚未提供可读取的调用请求号，费用仍未知。原服务计量投影如下；不会重新派发。</p>
        <InvocationCostDetails v-if="nodeCost" :cost="nodeCost" />
        <ElDescriptions v-if="inspectedNode.child?.kind === 'tool'" title="原工具调用费用" :column="2" border>
          <ElDescriptionsItem label="计量单位">{{ toolMetering?.unit === 'external_request' ? '外部请求' : '未确认' }}</ElDescriptionsItem>
          <ElDescriptionsItem label="请求次数">{{ toolMetering?.quantity ?? '未知' }}</ElDescriptionsItem>
          <ElDescriptionsItem label="定价状态">{{ toolMetering?.pricingState === 'priced' ? '已定价' : toolMetering?.pricingState === 'unpriced' ? '未计价' : '未知' }}</ElDescriptionsItem>
          <ElDescriptionsItem label="币种">{{ toolMetering?.currency ?? '未确认' }}</ElDescriptionsItem>
          <ElDescriptionsItem label="计量金额">{{ toolMoney(toolMetering?.meteredAmount, toolMetering?.pricingState === 'unpriced' ? '未计价' : '未知') }}</ElDescriptionsItem>
          <ElDescriptionsItem label="已核对账单金额">{{ toolMoney(toolMetering?.reconciledAmount, '未对账') }}</ElDescriptionsItem>
        </ElDescriptions>
        <p v-if="inspectedNode.child?.kind === 'tool'">以上来自原工具运行的计量记录。未知或未计价金额不代表零费用；不同币种保持各自金额。</p>
        <details v-if="originalChild !== null"><summary>查看原子服务状态、用量与成果</summary><pre>{{ publicDisplay(originalChild) }}</pre></details>
        <ElSpace wrap><ElButton v-for="source in nodeSources" :key="source.key" :disabled="inspectionBusy" @click="readNodeSource(source)">读取来源块 #{{ source.blockId }}</ElButton></ElSpace>
        <p v-if="!inspectionBusy && !nodeSources.length">此原运行尚未返回可读取的来源块；工具来源可在上方原服务成果中查看。</p>
        <pre v-if="sourceText !== null">{{ sourceText }}</pre>
      </section>
    </template>
  </ElCard>
</template>

<script setup lang="ts">
import { computed, onUnmounted, ref, shallowRef, watch } from 'vue'
import { ElMessageBox } from 'element-plus'
import { useUserStore } from '@/store/modules/user'
import CompositionSchemaValue from './CompositionSchemaValue.vue'
import RuntimeApiKeyField from '../../components/RuntimeApiKeyField.vue'
import api, { type CompositionCatalogItem, type CompositionDefinition, type CompositionDetail, type CompositionGraph, type CompositionNode, type CompositionOperation, type CompositionReceipt, type CompositionRun, type CompositionRunCost, type CompositionService, type JsonValue, type ServiceKind } from '../../api/composition'
import InvocationCostDetails from '../../usage/modules/invocation-cost-details.vue'
import { isAgentReadDenied } from '../../api/agentEventReplay'
import agentApi from '../../api/agent'
import taskApi from '../../api/task'
import knowledgeSourceApi, { knowledgeSourceReference, type KnowledgeSourceReference } from '../../api/knowledgeSource'
import type { SandAiInvocationCost, SandAiUsageSummaryRow } from '../../api/types'
import { newRuntimeRequestId, SandAiRuntimeError } from '../../api/runtime'
const props = defineProps<{ environmentId: number | null }>()
const user = useUserStore()
const adminBusy = ref(false)
const adminFlight = ref<symbol | null>(null)
const runtimeFlight = ref<symbol | null>(null)
const busy = computed(() => adminBusy.value || adminFlight.value !== null || runtimeFlight.value !== null)
const runtimeReadBusy = ref(false)
let runtimeReadEpoch = 0
let actionExpectation: { scope: string; runId: number; key: string; operation: string } | null = null
function invalidateRuntimeRead(): void { ++runtimeReadEpoch; runtimeReadBusy.value = false }
function beginRuntimeFlight(): symbol {
  const token = Symbol('composition-runtime-write')
  runtimeFlight.value = token
  invalidateRuntimeRead()
  clearInspection()
  return token
}
function endRuntimeFlight(token: symbol): void {
  if (runtimeFlight.value === token) runtimeFlight.value = null
}
const error = ref(''), selectedCode = ref(''), code = ref(''), name = ref('')
const definitions = ref<CompositionDefinition[]>([]), catalog = ref<CompositionCatalogItem[]>([]), detail = ref<CompositionDetail | null>(null)
const versions = computed(() => detail.value?.versions ?? [])
const versionId = ref<number | null>(null), revision = ref(0), outputs = ref<string[]>([])
const failurePolicy = ref<CompositionGraph['failure_policy']>('stop')
interface BindingEditor { field: string; label: string; schema: Record<string, unknown>; required: boolean; enabled: boolean; source: 'input' | 'node' | 'literal'; path: string; nodeId: string; literal: string }
interface NodeEditor { key: string; id: string; name: string; serviceKey: string; depends: string[]; bindings: BindingEditor[]; service: CompositionService | null }
const nodes = ref<NodeEditor[]>([])
const draftVersion = computed(() => versions.value.find(item => item.state === 'draft'))
const savedEditor = ref('')
const editorSnapshot = () => JSON.stringify({ code: code.value, name: name.value, nodes: nodes.value, outputs: outputs.value, failure: failurePolicy.value })
const dirty = computed(() => editorSnapshot() !== savedEditor.value)
const adminPending = ref<CompositionOperation | null>(null), adminKey = ref('')
interface AdminTarget {
  scope: string; key: string; operation: CompositionOperation; code: string
  definitionId: number | null; versionId: number | null; versionNo: number | null; revision: number | null
}
const adminTarget = shallowRef<AdminTarget | null>(null)
const adminSent = ref(true), adminStorageError = ref(false)
const pendingAdminCodes = ref<string[]>([])
let adminOriginal: (() => Promise<CompositionReceipt>) | null = null
const apiKey = ref(''), runtimeVersion = ref<number | null>(null), inputValues = ref<Record<string, string>>({})
const actionPending = ref(false)
const purgePending = ref('')
const runtimePending = ref(''), runId = ref<number | null>(null), run = shallowRef<CompositionRun | null>(null)
const existingRunNumber = ref(''), existingRunView = ref(false)
const existingRun = shallowRef<CompositionRun | null>(null)
const displayedRun = computed(() => existingRunView.value ? existingRun.value : run.value)
const displayedRunId = computed(() => existingRunView.value ? existingRun.value?.id ?? null : runId.value)
const runtimeUnsent = ref(false)
let runtimeSubmission: { scope: string; epoch: number; credential: string; code: string; version: number; input: Record<string, JsonValue>; key: string } | null = null
function clearRuntimeSubmission(): void { runtimeSubmission = null; runtimeUnsent.value = false }
let epoch = 0, runtimeEpoch = 0
const runCost = ref<CompositionRunCost | null>(null)
const runCostLoading = ref(false), runCostError = ref('')
let runCostEpoch = 0
function clearRunCost(): void {
  ++runCostEpoch; runCost.value = null; runCostLoading.value = false; runCostError.value = ''
}
function runMeteredAmount(row: SandAiUsageSummaryRow): string {
  if (row.metered_amount !== null) return row.metered_amount
  return row.known_metered_amount === null ? '未知' : `未知（已知部分 ${row.known_metered_amount}）`
}
async function readRunCost(): Promise<void> {
  const id = displayedRunId.value, key = apiKey.value.trim()
  if (!id || !key || runCostLoading.value) return
  clearRunCost()
  const current = runCostEpoch, scope = runtimeEpoch
  runCostLoading.value = true
  try {
    const result = await api.cost(key, id)
    if (current !== runCostEpoch || scope !== runtimeEpoch || id !== displayedRunId.value) return
    if (result.run_id !== id) throw new Error('费用响应与当前运行不一致，请重新读取')
    runCost.value = result
  } catch (cause) {
    if (current !== runCostEpoch || scope !== runtimeEpoch) return
    runCost.value = null
    runCostError.value = message(cause)
    if (cause instanceof SandAiRuntimeError && isAgentReadDenied(cause.errorCode, cause.httpStatus)) runtimeFailure(cause)
  } finally {
    if (current === runCostEpoch && scope === runtimeEpoch) runCostLoading.value = false
  }
}
let inspectionEpoch = 0
const inspectedNode = ref<CompositionRun['nodes'][number] | null>(null), originalChild = ref<unknown>(null)
const inspectionBusy = ref(false), inspectionError = ref(''), nodeCost = ref<SandAiInvocationCost | null>(null), sourceText = ref<string | null>(null)
interface NodeSource { key: string; blockId: number; kind: 'agent' | 'material' | 'knowledge'; originalId: number; knowledge: KnowledgeSourceReference | null }
const invocationRequests = ref<string[]>([]), nodeSources = ref<NodeSource[]>([])
const publicDisplay = (value: unknown) => JSON.stringify(value, null, 2)
function object(value: unknown): value is Record<string, unknown> { return value !== null && typeof value === 'object' && !Array.isArray(value) }
function positive(value: unknown): value is number { return typeof value === 'number' && Number.isSafeInteger(value) && value > 0 }
const toolMetering = computed(() => {
  if (!object(originalChild.value) || !object(originalChild.value.metering)) return null
  const value = originalChild.value.metering
  return {
    unit: value.unit === 'external_request' ? value.unit : null,
    quantity: typeof value.quantity === 'number' && Number.isFinite(value.quantity) && value.quantity >= 0
      ? value.quantity : typeof value.quantity === 'string' && /^[0-9]+(?:\.[0-9]+)?$/.test(value.quantity) ? value.quantity : null,
    pricingState: value.pricing_state === 'priced' || value.pricing_state === 'unpriced' ? value.pricing_state : null,
    meteredAmount: typeof value.metered_amount === 'string' ? value.metered_amount : null,
    reconciledAmount: typeof value.reconciled_amount === 'string' ? value.reconciled_amount : null,
    currency: typeof value.currency === 'string' && value.currency.trim() ? value.currency : null
  }
})
function toolMoney(amount: string | null | undefined, absent: string): string {
  if (amount === null || amount === undefined) return absent
  return `${amount} ${toolMetering.value?.currency ?? '币种未确认'}`
}
function clearInspection(): void {
  ++inspectionEpoch; inspectedNode.value = null; originalChild.value = null; inspectionBusy.value = false
  inspectionError.value = ''; nodeCost.value = null; sourceText.value = null; invocationRequests.value = []; nodeSources.value = []
}
function collectInvocationRequests(value: unknown): string[] {
  const ids = new Set<string>()
  const visit = (part: unknown, depth: number): void => {
    if (depth > 8) return
    if (Array.isArray(part)) { part.forEach(item => visit(item, depth + 1)); return }
    if (!object(part)) return
    for (const field of ['invocation_request_id', 'request_id']) {
      const candidate = part[field]
      if (typeof candidate === 'string' && candidate.length > 0 && candidate.length <= 128) ids.add(candidate)
    }
    for (const field of ['embedding_invocation', 'rerank_invocation', 'agent_invocations', 'summary_invocation', 'invocation', 'embedding_invocations', 'embedding_usage', 'rerank_usage', 'usage_cost', 'task', 'result', 'file_results', 'calls']) {
      if (part[field] !== undefined) visit(part[field], depth + 1)
    }
  }
  visit(value, 0); return [...ids]
}
function collectSources(value: unknown, child: Record<string, JsonValue>): NodeSource[] {
  const found = new Map<string, NodeSource>()
  const visit = (part: unknown, depth: number): void => {
    if (depth > 10) return
    if (Array.isArray(part)) { part.forEach(item => visit(item, depth + 1)); return }
    if (!object(part)) return
    const knowledge = knowledgeSourceReference(part)
    if (knowledge && (child.kind === 'knowledge' || child.kind === 'skill')) {
      const key = `knowledge:${knowledge.knowledge_base_code}:${knowledge.knowledge_base_version}:${knowledge.source_block_id}`
      found.set(key, { key, blockId: knowledge.source_block_id, kind: 'knowledge', originalId: 0, knowledge })
    } else if (positive(part.source_block_id) && child.kind === 'agent' && positive(child.task_id)) {
      const key = `agent:${child.task_id}:${part.source_block_id}`
      found.set(key, { key, blockId: part.source_block_id, kind: 'agent', originalId: child.task_id, knowledge: null })
    } else if (positive(part.source_block_id) && child.kind === 'material' && positive(child.run_id)) {
      const key = `material:${child.run_id}:${part.source_block_id}`
      found.set(key, { key, blockId: part.source_block_id, kind: 'material', originalId: child.run_id, knowledge: null })
    }
    for (const field of ['sources', 'source_references', 'citations', 'task', 'result', 'output', 'file_results', 'review', 'references']) {
      if (part[field] !== undefined) visit(part[field], depth + 1)
    }
  }
  visit(value, 0); return [...found.values()]
}
function inspectionFailure(cause: unknown): void {
  inspectionError.value = message(cause)
  if (cause instanceof SandAiRuntimeError && isAgentReadDenied(cause.errorCode, cause.httpStatus)) {
    clearInspection(); runtimeFailure(cause)
  }
}
async function inspectNode(nodeId: string): Promise<void> {
  if (!displayedRunId.value || !apiKey.value.trim() || inspectionBusy.value) return
  clearInspection()
  const current = inspectionEpoch, scope = runtimeEpoch, parentId = displayedRunId.value, credential = apiKey.value
  const targetCode = displayedRun.value?.code, targetVersion = displayedRun.value?.version
  inspectionBusy.value = true
  try {
    const authorized = await api.run(credential, parentId)
    if (current !== inspectionEpoch || scope !== runtimeEpoch) return
    if (authorized.id !== parentId || authorized.code !== targetCode || authorized.version !== targetVersion) throw new Error('原节点所属运行不一致，请重新找回原运行')
    const node = authorized.nodes.find(item => item.id === nodeId)
    if (!node?.child) throw new Error('原子运行定位尚未确认，请先找回原工作流')
    inspectedNode.value = node
    let original: unknown = node.result
    if (node.child.kind !== 'knowledge') original = await api.readChild(credential, node.child)
    if (current !== inspectionEpoch || scope !== runtimeEpoch) return
    originalChild.value = original
    invocationRequests.value = [...new Set([...collectInvocationRequests(node.child), ...collectInvocationRequests(original)])]
    nodeSources.value = collectSources(original, node.child)
  } catch (cause) {
    if (current === inspectionEpoch && scope === runtimeEpoch) inspectionFailure(cause)
  } finally { if (current === inspectionEpoch) inspectionBusy.value = false }
}
async function revalidateInspectedNode(): Promise<void> {
  if (!displayedRunId.value || !inspectedNode.value) throw new Error('先读取节点原运行')
  const expected = inspectedNode.value, id = displayedRunId.value
  const parent = await api.run(apiKey.value, id)
  const current = parent.nodes.find(node => node.id === expected.id)
  if (!current?.child || JSON.stringify(current.child) !== JSON.stringify(expected.child)) {
    throw new Error('节点原运行已变化，请重新读取节点')
  }
}
async function readNodeCost(requestId: string): Promise<void> {
  if (inspectionBusy.value || !invocationRequests.value.includes(requestId) || !apiKey.value.trim()) return
  const current = inspectionEpoch, scope = runtimeEpoch
  inspectionBusy.value = true; nodeCost.value = null; inspectionError.value = ''
  try {
    await revalidateInspectedNode()
    if (current !== inspectionEpoch || scope !== runtimeEpoch) return
    const result = await agentApi.cost(apiKey.value, requestId)
    if (current === inspectionEpoch && scope === runtimeEpoch && result.request_id === requestId) nodeCost.value = result
  } catch (cause) {
    if (current === inspectionEpoch && scope === runtimeEpoch) inspectionFailure(cause)
  } finally { if (current === inspectionEpoch) inspectionBusy.value = false }
}
async function readNodeSource(source: NodeSource): Promise<void> {
  if (inspectionBusy.value || !nodeSources.value.some(item => item.key === source.key) || !apiKey.value.trim()) return
  const current = inspectionEpoch, scope = runtimeEpoch
  inspectionBusy.value = true; sourceText.value = null; inspectionError.value = ''
  try {
    await revalidateInspectedNode()
    if (current !== inspectionEpoch || scope !== runtimeEpoch) return
    const result = source.kind === 'agent'
      ? await agentApi.source(apiKey.value, source.originalId, source.blockId)
      : source.kind === 'material'
        ? await taskApi.readWorkflowSource(apiKey.value, source.originalId, source.blockId, props.environmentId ?? undefined)
        : source.knowledge
          ? await knowledgeSourceApi.read(apiKey.value, source.knowledge, props.environmentId ?? undefined) : null
    if (current === inspectionEpoch && scope === runtimeEpoch && result) sourceText.value = result.content
  } catch (cause) {
    if (current === inspectionEpoch && scope === runtimeEpoch) inspectionFailure(cause)
  } finally { if (current === inspectionEpoch) inspectionBusy.value = false }
}
const runtimeLocked = computed(() => existingRunView.value || busy.value || Boolean(runtimePending.value) || Boolean(purgePending.value) || Boolean(runId.value))
const runtimeGraph = computed(() => versions.value.find(item => item.version_no === runtimeVersion.value)?.graph)
const inputPaths = computed(() => [...new Set(runtimeGraph.value?.nodes.flatMap(node => Object.values(node.inputs).filter(binding => binding.source === 'input').map(binding => binding.path)) ?? [])])
const serviceKey = (item: CompositionService) => `${item.kind}:${item.code}:${item.version}`
const kindLabel = (kind: ServiceKind) => ({ agent: '智能体', skill: 'Skill', material: '材料配置', knowledge: '知识库', tool: '只读工具' })[kind]
const nodeService = (node: NodeEditor) => catalog.value.find(item => serviceKey(item) === node.serviceKey)
const stateNames: Record<string, string> = { pending: '待执行', running: '执行中', waiting: '等待子运行', partial_failed: '部分失败', succeeded: '成功', cancelled: '已停止', submitting: '提交中', failed: '失败', unknown: '派发未明', blocked: '依赖未成功' }
const stateLabel = (state: string) => stateNames[state] ?? state
function schemaRecord(value: unknown): Record<string, unknown> | null {
  return typeof value === 'object' && value !== null && !Array.isArray(value) ? value as Record<string, unknown> : null
}
function inputBindings(path: string) {
  return runtimeGraph.value?.nodes.flatMap(node => Object.entries(node.inputs)
    .filter(([, binding]) => binding.source === 'input' && binding.path === path)
    .map(([field]) => ({ field, schema: catalog.value.find(service => serviceKey(service) === serviceKey(node.service))?.input_fields.find(item => item.name === field)?.schema ?? {} }))) ?? []
}
function inputSchema(path: string): Record<string, unknown> {
  const schemas = inputBindings(path).map(item => item.schema)
  return schemas.length === 1 ? schemas[0] : schemas.length ? { allOf: schemas } : {}
}
function schemaTypes(schema: Record<string, unknown>): string[] {
  return typeof schema.type === 'string' ? [schema.type]
    : Array.isArray(schema.type) ? schema.type.filter((value): value is string => typeof value === 'string') : []
}
function upstreamSchema(binding: BindingEditor): Record<string, unknown> {
  const upstream = nodes.value.find(node => node.id === binding.nodeId)
  return upstream ? nodeService(upstream)?.output_schema ?? {} : {}
}
function upstreamPaths(binding: BindingEditor): { path: string; label: string }[] {
  const options: { path: string; label: string }[] = []
  const target = schemaTypes(binding.schema)
  function visit(schema: Record<string, unknown>, path: string, label: string): void {
    const types = schemaTypes(schema)
    if (types.length && target.length && types.every(type => target.includes(type) || (type === 'integer' && target.includes('number')))) options.push({ path, label: `${label} · ${path || '整个成果'} · ${types.join(' / ')}` })
    const properties = schemaRecord(schema.properties)
    if (types.includes('object') && properties) for (const [name, definition] of Object.entries(properties)) {
      const child = schemaRecord(definition)
      if (child) visit(child, `${path}/${name.replace(/~/g, '~0').replace(/\//g, '~1')}`, typeof child.title === 'string' ? child.title : name)
    }
  }
  visit(upstreamSchema(binding), '', '整个成果')
  return options
}
function nodeIdReferenced(node: NodeEditor): boolean {
  return Boolean(node.id) && (outputs.value.includes(node.id) || nodes.value.some(other => other.key !== node.key
    && (other.depends.includes(node.id) || other.bindings.some(binding => binding.enabled && binding.source === 'node' && binding.nodeId === node.id))))
}
const display = (value: JsonValue | Record<string, JsonValue> | null) => JSON.stringify(value, null, 2)
const message = (cause: unknown) => cause instanceof Error ? cause.message : '请求失败'
function prefix(): string { return `sand-ai-composition:${user.info.id}:${props.environmentId ?? 'none'}:${code.value}` }
function runtimePrefix(): string { return `${prefix()}:version:${runtimeVersion.value ?? 'none'}` }
const builtinInputFields: Partial<Record<ServiceKind, Record<string, { label: string; help: string }>>> = {
  agent: {
    query: { label: '本次问题', help: '填写要交给智能体处理的问题，可来自本次输入或上游文字成果。' },
    conversation_id: { label: '会话标识', help: '需要接续同一会话时提供原会话标识；留空则不指定已有会话。' }
  },
  material: {
    business_scope: { label: '业务资料范围', help: '提供消费应用的业务引用、快照引用与快照版本，不使用平台配置代替业务来源。' },
    files: { label: '待处理文件', help: '选择本环境获授权的私有文件，提供文件编号、SHA256 摘要与版本引用。' }
  },
  knowledge: {
    query: { label: '检索问题', help: '填写需要从已发布知识库查找依据的问题，可接收上游文字成果。' },
    limit: { label: '最多返回条数', help: '填写本次最多返回的检索结果数量。' },
    rerank: { label: '使用重排', help: '是否对检索结果重新排序；启用时仍须具备当前环境的重排能力和授权。' },
    model: { label: '检索模型', help: '需要指定检索模型时填写当前环境可用的模型标识。' },
    rerank_mode: { label: '重排模式', help: '按已发布服务提供的选项选择模式，实际选项值保持原定义。' }
  },
  tool: {
    business_scope: { label: '业务范围标识', help: '填写消费应用本次工具调用所属的业务范围标识。' },
    input: { label: '工具输入', help: '按此只读工具已发布的实际字段定义提供输入。' }
  }
}
function fieldLabel(service: CompositionService, field: string, title?: string): string {
  if (title?.trim() && title !== field) return title
  return builtinInputFields[service.kind]?.[field]?.label ?? (title?.trim() ? title : field)
}
function fieldHelp(service: CompositionService, field: string): string {
  return builtinInputFields[service.kind]?.[field]?.help ?? ''
}
function inputLabel(path: string): string {
  for (const node of runtimeGraph.value?.nodes ?? []) {
    const field = Object.entries(node.inputs).find(([, binding]) => binding.source === 'input' && binding.path === path)?.[0]
    if (field) {
      const definition = catalog.value.find(item => serviceKey(item) === serviceKey(node.service))?.input_fields.find(item => item.name === field)
      return fieldLabel(node.service, field, definition?.label)
    }
  }
  return path
}
function addNode(): void { if (nodes.value.length < 12) nodes.value.push({ key: newRuntimeRequestId('node'), id: `node_${nodes.value.length + 1}`, name: '', serviceKey: '', service: null, depends: [], bindings: [] }) }
function removeNode(key: string): void { const target = nodes.value.find(item => item.key === key); nodes.value = nodes.value.filter(item => item.key !== key); if (target) { nodes.value.forEach(item => { item.depends = item.depends.filter(id => id !== target.id) }); outputs.value = outputs.value.filter(id => id !== target.id) } }
function selectService(node: NodeEditor): void {
  const selected = nodeService(node)
  if (!selected) return
  node.service = { kind: selected.kind, code: selected.code, version: selected.version }
  node.bindings = selected.input_fields.map(field => ({ field: field.name, label: `${fieldLabel(selected, field.name, field.label)}${field.required ? '（必填）' : ''}`, schema: field.schema, required: field.required, enabled: field.required,
    source: 'input', path: `/${field.name.replace(/~/g, '~0').replace(/\//g, '~1')}`, nodeId: '', literal: '' }))
}
function selectVersion(): void {
  const version = versions.value.find(item => item.id === versionId.value)
  if (!version) return
  revision.value = draftVersion.value?.revision ?? 0
  outputs.value = [...version.graph.output_nodes]
  failurePolicy.value = version.graph.failure_policy
  nodes.value = version.graph.nodes.map(node => {
    const fields = catalog.value.find(item => serviceKey(item) === serviceKey(node.service))?.input_fields ?? []
    const bindings: BindingEditor[] = Object.entries(node.inputs).map(([field, binding]) => {
      const definition = fields.find(item => item.name === field)
      return { field, label: fieldLabel(node.service, field, definition?.label), schema: definition?.schema ?? {}, required: definition?.required ?? false, enabled: true, source: binding.source,
        path: binding.source === 'literal' ? '' : binding.path, nodeId: binding.source === 'node' ? binding.node_id : '',
        literal: binding.source === 'literal' ? JSON.stringify(binding.value) ?? 'null' : '' }
    })
    for (const field of fields) if (!bindings.some(binding => binding.field === field.name)) bindings.push({ field: field.name, label: fieldLabel(node.service, field.name, field.label),
      schema: field.schema, required: field.required, enabled: field.required, source: 'input', path: `/${field.name.replace(/~/g, '~0').replace(/\//g, '~1')}`, nodeId: '', literal: '' })
    return { key: newRuntimeRequestId('node'), id: node.id, name: node.name, serviceKey: serviceKey(node.service), service: { ...node.service }, depends: [...node.depends_on], bindings }
  })
  savedEditor.value = editorSnapshot()
}
function literalValue(binding: BindingEditor): JsonValue {
  try { return JSON.parse(binding.literal) as JsonValue } catch { throw new Error(`${binding.label} 的固定值尚未填写完整，请核对字段表单或原 JSON`) }
}
function graph(): CompositionGraph { if (!nodes.value.length || !outputs.value.length) throw new Error('至少配置一个节点，并选择成果节点'); const ids = nodes.value.map(node => node.id); if (new Set(ids).size !== ids.length || ids.some(id => !/^[a-z][a-z0-9_]{0,31}$/.test(id))) throw new Error('节点标识须唯一且使用小写字母、数字或下划线'); const result: CompositionNode[] = nodes.value.map(node => { if (!node.service || !node.name.trim()) throw new Error('每个节点需填写中文名称并选择服务版本'); const inputs: CompositionNode['inputs'] = {}; for (const binding of node.bindings) { if (!binding.enabled && !binding.required) continue; if (binding.source !== 'literal' && !/^(?:\/(?:[^~]|~[01])*)*$/.test(binding.path)) throw new Error('字段路径需为合法 JSON Pointer，例如 /question'); if (binding.source === 'node' && !node.depends.includes(binding.nodeId)) throw new Error('上游参数必须选自该节点的依赖'); inputs[binding.field] = binding.source === 'literal' ? { source: 'literal', value: literalValue(binding) } : binding.source === 'node' ? { source: 'node', node_id: binding.nodeId, path: binding.path } : { source: 'input', path: binding.path } } return { id: node.id, name: node.name.trim(), service: { ...node.service }, depends_on: [...node.depends], inputs } }); return { nodes: result, output_nodes: [...outputs.value], failure_policy: failurePolicy.value } }
async function loadCatalog(): Promise<void> { const env = props.environmentId; if (!env || busy.value) return; const current = epoch; adminBusy.value = true; error.value = ''; try { const result = await api.catalog(env); if (current === epoch) catalog.value = result.items } catch (cause) { if (current === epoch) error.value = message(cause) } finally { if (current === epoch) adminBusy.value = false } }
async function readDefinition(current: number, env: number, target: string): Promise<void> { const result = await api.read(env, target); if (current !== epoch) return; detail.value = result; code.value = result.definition.code; name.value = result.definition.name; selectedCode.value = code.value; versionId.value = result.versions.find(item => item.state === 'draft')?.id ?? result.definition.active_version_id ?? result.versions[0]?.id ?? null; selectVersion() }
async function loadDefinition(): Promise<void> { const env = props.environmentId; if (!env || !selectedCode.value || busy.value || adminPending.value) return; const current = ++epoch; adminBusy.value = true; error.value = ''; try { await readDefinition(current, env, selectedCode.value) } catch (cause) { if (current === epoch) error.value = message(cause) } finally { if (current === epoch) adminBusy.value = false } }
function newDefinition(): void { if (busy.value || adminPending.value) return; ++epoch; detail.value = null; code.value = ''; name.value = ''; versionId.value = null; revision.value = 0; nodes.value = []; outputs.value = []; addNode(); savedEditor.value = '' }
function resetAdminMemory(): void {
  adminPending.value = null; adminKey.value = ''; adminTarget.value = null
  adminOriginal = null; adminSent.value = true
}
function readAdminRecoveryCodes(): void {
  const namespace = `sand-ai-composition:${user.info.id}:${props.environmentId ?? 'none'}:`
  const codes = new Set<string>()
  pendingAdminCodes.value = []
  try {
    for (let index = 0; index < sessionStorage.length; index++) {
      const path = sessionStorage.key(index)
      if (!path?.startsWith(namespace)) continue
      const match = /^([a-z][a-z0-9_-]{0,63}):admin(?:-target)?:(?:draft|publish|disable)$/.exec(path.slice(namespace.length))
      if (match && sessionStorage.getItem(path) !== null) codes.add(match[1])
    }
    pendingAdminCodes.value = [...codes]
  } catch (cause) {
    adminStorageError.value = true
    error.value = `原管理操作记录读取失败，当前不会创建新请求号：${message(cause)}`
  }
}
function openAdminRecovery(targetCode: string): void {
  if (busy.value || adminPending.value || !pendingAdminCodes.value.includes(targetCode)) return
  newDefinition()
  selectedCode.value = targetCode
  code.value = targetCode
}
function restoreAdmin(): void {
  const previous = adminTarget.value?.scope === prefix() ? adminTarget.value : null
  resetAdminMemory()
  adminStorageError.value = false
  readAdminRecoveryCodes()
  if (adminStorageError.value) {
    if (previous) { adminTarget.value = previous; adminPending.value = previous.operation; adminKey.value = previous.key }
    return
  }
  try {
    for (const operation of ['draft', 'publish', 'disable'] as const) {
      const storedKey = sessionStorage.getItem(`${prefix()}:admin:${operation}`)
      const raw = sessionStorage.getItem(`${prefix()}:admin-target:${operation}`)
      if (storedKey === null && raw === null) continue
      const saved: unknown = raw === null ? null : JSON.parse(raw)
      const key = storedKey ?? (object(saved) && typeof saved.key === 'string' ? saved.key : null)
      if (!key || key.length > 128 || /[\u0000-\u001f\u007f]/.test(key)) throw new Error('原管理请求号记录无效，当前不会创建新请求号')
      adminPending.value = operation; adminKey.value = key
      // A restored record cannot prove that the original POST was never sent.
      const fallback: AdminTarget = { scope: prefix(), key, operation, code: code.value,
        definitionId: null, versionId: null, versionNo: null, revision: null }
      adminTarget.value = fallback
      if (raw !== null) {
        const nullableId = (value: unknown): value is number | null => value === null || positive(value)
        if (!object(saved) || saved.scope !== prefix() || saved.key !== key || saved.operation !== operation
          || saved.code !== code.value || !nullableId(saved.definitionId) || !nullableId(saved.versionId)
          || !nullableId(saved.versionNo) || !nullableId(saved.revision)) {
          throw new Error('原管理操作目标记录不一致，已保留原号；请恢复原记录后再读取')
        }
        adminTarget.value = { ...fallback, definitionId: saved.definitionId,
          versionId: saved.versionId, versionNo: saved.versionNo, revision: saved.revision }
      }
      break
    }
  } catch (cause) {
    if (!adminTarget.value && previous) { adminTarget.value = previous; adminPending.value = previous.operation; adminKey.value = previous.key }
    adminStorageError.value = true
    error.value = `原操作记录读取失败：${message(cause)}`
  }
}
function persistAdmin(target: AdminTarget): void {
  const keyPath = `${target.scope}:admin:${target.operation}`
  const targetPath = `${target.scope}:admin-target:${target.operation}`
  const originalKey = sessionStorage.getItem(keyPath)
  const metadata = JSON.stringify(target)
  if (originalKey !== null && originalKey !== target.key) throw new Error('此目标已有另一个原请求号，请先找回原操作')
  const originalTarget = sessionStorage.getItem(targetPath)
  if (originalTarget !== null && originalTarget !== metadata) throw new Error('此目标已有另一个原操作记录，请先找回原操作')
  sessionStorage.setItem(targetPath, metadata)
  sessionStorage.setItem(keyPath, target.key)
  if (sessionStorage.getItem(keyPath) !== target.key || sessionStorage.getItem(targetPath) !== metadata) {
    throw new Error('原请求号与目标记录保存后无法读回')
  }
}
function clearAdmin(target: AdminTarget): void {
  const keyPath = `${target.scope}:admin:${target.operation}`
  const targetPath = `${target.scope}:admin-target:${target.operation}`
  const storedKey = sessionStorage.getItem(keyPath)
  if (storedKey !== null && storedKey !== target.key) throw new Error('原请求号记录已变化，当前不会解除原操作锁')
  const metadata = sessionStorage.getItem(targetPath)
  if (metadata !== null && metadata !== JSON.stringify(target)) throw new Error('原目标记录已变化，当前不会解除原操作锁')
  sessionStorage.removeItem(targetPath)
  sessionStorage.removeItem(keyPath)
  if (sessionStorage.getItem(keyPath) !== null || sessionStorage.getItem(targetPath) !== null) {
    throw new Error('已确认原操作，但原记录清除尚未完成，请继续只读找回')
  }
  resetAdminMemory()
  readAdminRecoveryCodes()
}
function matchesAdminReceipt(result: CompositionReceipt, target: AdminTarget): boolean {
  return result.code === target.code && positive(result.definition_id) && positive(result.version_id)
    && positive(result.version_no) && positive(result.revision)
    && result.state === (target.operation === 'draft' ? 'draft' : target.operation === 'publish' ? 'published' : 'disabled')
    && typeof result.snapshot_sha256 === 'string' && /^[a-f0-9]{64}$/.test(result.snapshot_sha256)
    && (target.definitionId === null || result.definition_id === target.definitionId)
    && (target.versionId === null || result.version_id === target.versionId)
    && (target.versionNo === null || result.version_no === target.versionNo)
    && (target.revision === null || result.revision === target.revision)
}
async function finishAdmin(result: CompositionReceipt, target: AdminTarget, current: number, env: number): Promise<void> {
  if (current !== epoch || adminTarget.value !== target || target.scope !== prefix()) return
  if (!matchesAdminReceipt(result, target)) throw new Error('原操作回执与提交目标或修订号不一致，已保留原号')
  await readDefinition(current, env, target.code)
  if (current !== epoch || adminTarget.value !== target || target.scope !== prefix()) return
  clearAdmin(target)
}
async function sendAdminOriginal(heldFlight?: symbol): Promise<void> {
  const target = adminTarget.value, send = adminOriginal, env = props.environmentId
  const ownsHeldFlight = heldFlight !== undefined && adminFlight.value === heldFlight
  if (!env || !target || !send || adminSent.value || (busy.value && !ownsHeldFlight) || target.scope !== prefix()) return
  const flight = heldFlight ?? Symbol('workflow-management')
  if (!ownsHeldFlight) adminFlight.value = flight
  const current = epoch
  adminBusy.value = true; error.value = ''
  try {
    try {
      persistAdmin(target)
    } catch (cause) {
      if (current === epoch) error.value = `本页未发送，原配置仍在内存中：${message(cause)}`
      return
    }
    if (current !== epoch || target.scope !== prefix() || adminTarget.value !== target) return
    // Only this page retains the original body. Reload always uses read-only recovery.
    adminSent.value = true
    if (target.operation === 'disable') invalidatePrivateRun()
    const result = await send()
    await finishAdmin(result, target, current, env)
  } catch (cause) {
    if (current === epoch) error.value = `操作未确认，请只读找回原号：${message(cause)}`
  } finally {
    if (!ownsHeldFlight && adminFlight.value === flight) adminFlight.value = null
    if (current === epoch) adminBusy.value = false
  }
}
async function mutate(operation: CompositionOperation): Promise<void> {
  const env = props.environmentId
  if (!env || busy.value || adminPending.value || adminStorageError.value) return
  const current = epoch, targetCode = code.value, targetName = name.value.trim()
  const expectedRevision = revision.value, draft = draftVersion.value
  let frozenGraph: CompositionGraph | null = null
  try {
    if (!/^[a-z][a-z0-9_-]{0,63}$/.test(targetCode) || !targetName) throw new Error('填写工作流名称和合法标识')
    if (operation === 'draft') frozenGraph = graph()
    if (operation === 'publish' && (!draft || versionId.value !== draft.id || dirty.value)) {
      throw new Error('先保存当前节点、依赖及服务版本，再发布这个明确草稿')
    }
    if (operation === 'disable' && !detail.value?.definition.active_version_id) throw new Error('当前没有可停用的已发布版本')
  } catch (cause) { error.value = message(cause); return }
  const key = newRuntimeRequestId('workflow-admin')
  const version = operation === 'disable' ? null : draft
  const target: AdminTarget = { scope: prefix(), key, operation, code: targetCode,
    definitionId: detail.value?.definition.id ?? null, versionId: version?.id ?? null,
    versionNo: version?.version_no ?? null,
    revision: operation === 'draft' ? (draft ? expectedRevision + 1 : null) : version?.revision ?? null }
  const body = frozenGraph ? { environment_id: env, code: targetCode, name: targetName, expected_revision: expectedRevision, graph: frozenGraph,
    ...(draft ? { version_id: draft.id } : {}) } : null
  const flight = Symbol('workflow-management')
  adminFlight.value = flight
  adminBusy.value = true
  try {
    if (operation !== 'draft') {
      try {
        await ElMessageBox.confirm(operation === 'publish'
          ? `发布第 ${version?.version_no} 版、修订 ${expectedRevision} 的已保存草稿，应用可按此固定版本运行。继续？`
          : '停用后将拒绝新运行及续行。继续？', operation === 'publish' ? '发布工作流' : '停用工作流')
      } catch { return }
    }
    if (env !== props.environmentId || current !== epoch || target.scope !== prefix() || adminPending.value) return
    adminTarget.value = target; adminKey.value = key; adminPending.value = operation; adminSent.value = false
    adminOriginal = operation === 'draft' && body ? () => api.save(body, key)
      : operation === 'publish' ? () => api.publish(env, targetCode, expectedRevision, key, draft?.id) : () => api.disable(env, targetCode, key)
    await sendAdminOriginal(flight)
  } finally {
    if (adminFlight.value === flight) adminFlight.value = null
    if (current === epoch) adminBusy.value = false
  }
}
async function recoverAdmin(): Promise<void> {
  const env = props.environmentId, operation = adminPending.value, target = adminTarget.value
  if (!env || !operation || !target || !adminKey.value || !adminSent.value || busy.value || adminStorageError.value) return
  const current = epoch
  adminBusy.value = true; error.value = ''
  try {
    const result = await api.recoverOperation(env, operation, target.key)
    await finishAdmin(result, target, current, env)
  } catch (cause) { if (current === epoch) error.value = `尚未确认原管理操作：${message(cause)}` }
  finally { if (current === epoch) adminBusy.value = false }
}
function runtimeFailure(cause: unknown): void {
  clearRunCost()
  error.value = message(cause)
  if (cause instanceof SandAiRuntimeError && cause.errorCode !== null && ['SAND_AI_CONTENT_PURGED', 'SAND_AI_COMPOSITION_REFERENCE_CHANGED'].includes(cause.errorCode)) {
    invalidatePrivateRun()
  } else if (cause instanceof SandAiRuntimeError && isAgentReadDenied(cause.errorCode, cause.httpStatus)) {
    invalidatePrivateRun()
    apiKey.value = ''
  }
}
function acceptRun(result: CompositionRun, confirmed = true): void {
  const storage = runtimePrefix()
  const key = runtimePending.value
  if (confirmed) {
    const savedKey = sessionStorage.getItem(`${storage}:pending`)
    const savedAction = sessionStorage.getItem(`${storage}:action-pending`)
    const rawTarget = sessionStorage.getItem(`${storage}:action-target`)
    if ((savedKey !== null && savedKey !== key) || (savedAction !== null && savedAction !== key))
      throw new Error('原运行记录已变化，保留原号，不清除其他请求。')
    if (rawTarget !== null) {
      const target: unknown = JSON.parse(rawTarget)
      if (!object(target) || target.request_key !== key || target.run_id !== result.id)
        throw new Error('原动作目标记录已变化，保留原号。')
    }
  }
  sessionStorage.setItem(`${storage}:run`, String(result.id))
  if (sessionStorage.getItem(`${storage}:run`) !== String(result.id)) throw new Error('原运行号保存未确认，请只读找回。')
  if (confirmed) {
    sessionStorage.removeItem(`${storage}:pending`)
    sessionStorage.removeItem(`${storage}:action-pending`)
    sessionStorage.removeItem(`${storage}:action-target`)
    if (sessionStorage.getItem(`${storage}:pending`) !== null || sessionStorage.getItem(`${storage}:action-pending`) !== null
      || sessionStorage.getItem(`${storage}:action-target`) !== null) throw new Error('原号记录清除未确认，请继续只读找回。')
    clearRuntimeSubmission()
    actionExpectation = null; runtimePending.value = ''; actionPending.value = false
  }
  clearRunCost(); clearInspection()
  runId.value = result.id
  run.value = result.input_state === 'purged'
    ? { ...result, output: null, nodes: result.nodes.map(node => ({ ...node, result: null })) } : result
}
function makeInput(): Record<string, JsonValue> {
  const result: Record<string, JsonValue> = {}
  for (const path of inputPaths.value) {
    const segments = path.slice(1).split('/').map(part => part.replace(/~1/g, '/').replace(/~0/g, '~'))
    if (!path || segments.some(part => ['__proto__', 'prototype', 'constructor'].includes(part))) throw new Error('本次输入需要有效对象字段路径')
    let target = result
    for (const part of segments.slice(0, -1)) {
      const existing = target[part]
      if (existing !== undefined && (existing === null || typeof existing !== 'object' || Array.isArray(existing))) throw new Error('输入路径相互冲突，请调整草稿')
      if (existing === undefined) target[part] = {}
      target = target[part] as Record<string, JsonValue>
    }
    const last = segments[segments.length - 1]
    if (Object.prototype.hasOwnProperty.call(target, last)) throw new Error('输入路径相互冲突')
    let value: JsonValue
    try { value = JSON.parse(inputValues.value[path] ?? '') as JsonValue } catch { throw new Error(`${inputLabel(path)} 尚未填写完整，请核对字段表单或原 JSON`) }
    if (typeof value === 'number' && !Number.isFinite(value)) throw new Error(`${inputLabel(path)} 需要有限数字`)
    for (const item of inputBindings(path)) {
      const types = schemaTypes(item.schema)
      if (!types.length) continue
      const actual = value === null ? 'null' : Array.isArray(value) ? 'array' : typeof value
      const allowed = types.includes(actual) || (actual === 'number' && types.includes('integer') && typeof value === 'number' && Number.isInteger(value))
        || (value === null && item.schema.nullable === true)
      if (!allowed) throw new Error(`${inputLabel(path)} 需要符合实际字段定义的 ${types.join(' / ')} 值`)
    }
    target[last] = value
  }
  return result
}
async function submitRun(): Promise<void> {
  if (runtimeLocked.value || !runtimeVersion.value || !apiKey.value.trim()) return
  let input: Record<string, JsonValue>
  try { input = makeInput() } catch (cause) { error.value = message(cause); return }
  const original = { scope: runtimePrefix(), epoch: runtimeEpoch, credential: apiKey.value,
    code: code.value, version: runtimeVersion.value, input, key: newRuntimeRequestId('workflow-run') }
  try {
    const pending = sessionStorage.getItem(`${original.scope}:pending`)
    if (pending !== null) { runtimePending.value = pending; throw new Error('原运行记录尚未确认，请只读找回，不覆盖原号。') }
  } catch (cause) { error.value = message(cause); return }
  runtimeSubmission = original
  runtimeUnsent.value = true
  runtimePending.value = original.key
  await sendOriginalRun()
}
async function sendOriginalRun(): Promise<void> {
  const original = runtimeSubmission
  if (!original || !runtimeUnsent.value || busy.value || runtimeReadBusy.value || original.epoch !== runtimeEpoch
    || original.scope !== runtimePrefix() || original.key !== runtimePending.value || original.credential !== apiKey.value
    || original.code !== code.value || original.version !== runtimeVersion.value) return
  try {
    const storage = `${original.scope}:pending`
    const pending = sessionStorage.getItem(storage)
    if (pending !== null && pending !== original.key) throw new Error('原运行记录已变化，不覆盖或发送')
    sessionStorage.setItem(storage, original.key)
    if (sessionStorage.getItem(storage) !== original.key) throw new Error('原请求号保存读回失败')
  } catch (cause) { error.value = `本页确认尚未发送，保留原输入与原号：${message(cause)}`; return }
  if (original.epoch !== runtimeEpoch || original.scope !== runtimePrefix() || original.credential !== apiKey.value) return
  const flight = beginRuntimeFlight()
  error.value = ''
  // Consume only this page's proof before its first POST. A missing receipt cannot recreate it.
  clearRuntimeSubmission()
  try {
    const result = await api.submit(original.credential, original.code, original.version, original.input, original.key)
    if (original.epoch !== runtimeEpoch || original.scope !== runtimePrefix() || original.credential !== apiKey.value
      || runtimePending.value !== original.key) return
    if (!Number.isSafeInteger(result.id) || result.id < 1 || result.code !== original.code || result.version !== original.version)
      throw new Error('原运行返回目标不一致，已保留原号；请只读找回，不重新提交。')
    invalidateRuntimeRead(); acceptRun(result); inputValues.value = {}
  } catch (cause) { if (original.epoch === runtimeEpoch) runtimeFailure(cause) }
  finally { endRuntimeFlight(flight) }
}
function expectedActionOperation(key: string, targetId: number): string | null {
  if (actionExpectation?.scope === runtimePrefix() && actionExpectation.key === key && actionExpectation.runId === targetId) return actionExpectation.operation
  try {
    const raw = sessionStorage.getItem(`${runtimePrefix()}:action-target`)
    if (raw !== null) {
      const saved: unknown = JSON.parse(raw)
      if (!object(saved) || saved.request_key !== key || saved.run_id !== targetId
        || !['advance', 'cancel', 'retry'].includes(String(saved.action))) return null
      if (saved.action === 'retry') {
        if (typeof saved.node_id !== 'string' || !/^[a-z][a-z0-9_]{0,31}$/.test(saved.node_id)) return null
        return `retry:${saved.node_id}`
      }
      if (saved.node_id !== null) return null
      return saved.action === 'advance' ? 'advance' : 'cancel'
    }
    // Legacy keys identify advance/cancel; legacy retry keys lack their original
    // node, so those records remain unknown instead of borrowing a receipt's node.
    if (/^workflow-advance-[0-9]+-[a-z0-9]{1,8}$/.test(key)) return 'advance'
    if (/^workflow-cancel-[0-9]+-[a-z0-9]{1,8}$/.test(key)) return 'cancel'
  } catch { error.value = '原动作目标记录无法读取，已保留原号，仅可只读查看。' }
  return null
}
function closeExistingRun(): void {
  invalidateRuntimeRead(); clearRunCost(); clearInspection()
  existingRunView.value = false; existingRun.value = null; error.value = ''
}
async function readExistingRun(): Promise<void> {
  if (busy.value || runtimeReadBusy.value || runtimePending.value || purgePending.value || !apiKey.value.trim()) return
  const value = existingRunNumber.value.trim()
  const id = Number(value)
  if (!/^[1-9][0-9]*$/.test(value) || !Number.isSafeInteger(id)) {
    clearRunCost(); clearInspection(); existingRun.value = null; existingRunView.value = true
    error.value = '填写提交回执中的正整数运行号，不能填写工作流版本号或请求号。'
    return
  }
  clearRunCost(); clearInspection(); existingRun.value = null; existingRunView.value = true
  const current = runtimeEpoch, serial = ++runtimeReadEpoch, credential = apiKey.value
  const stillCurrent = () => current === runtimeEpoch && serial === runtimeReadEpoch
    && credential === apiKey.value && existingRunView.value && value === existingRunNumber.value.trim()
  runtimeReadBusy.value = true; error.value = ''
  try {
    const result = await api.run(credential, id)
    if (!stillCurrent()) return
    if (result.id !== id) throw new Error('返回运行号与填写的编号不一致，请核对原回执。')
    existingRun.value = result.input_state === 'purged'
      ? { ...result, output: null, nodes: result.nodes.map(node => ({ ...node, result: null })) } : result
  } catch (cause) {
    if (!stillCurrent()) return
    runtimeFailure(cause)
    if (cause instanceof SandAiRuntimeError && isAgentReadDenied(cause.errorCode, cause.httpStatus)) {
      error.value = '当前应用凭证没有读取权限或已失效。旧结果已清除，请取得该运行所属应用的有效凭证后按原运行号重读。'
    } else if (cause instanceof SandAiRuntimeError && cause.errorCode === 'SAND_AI_RESOURCE_NOT_FOUND') {
      error.value = '当前应用范围内未找到此运行。请核对原提交回执和应用身份；不会创建新运行或重发原请求。'
    }
  } finally { if (serial === runtimeReadEpoch) runtimeReadBusy.value = false }
}
async function readRun(): Promise<void> {
  if (existingRunView.value || runtimeReadBusy.value || !apiKey.value.trim() || !runtimeVersion.value || (!runId.value && !runtimePending.value)) return
  clearRunCost()
  const current = runtimeEpoch, serial = ++runtimeReadEpoch
  const targetId = runId.value, key = runtimePending.value, operation = actionPending.value
  const credential = apiKey.value, targetCode = code.value, targetVersion = runtimeVersion.value
  const stillCurrent = () => current === runtimeEpoch && serial === runtimeReadEpoch && credential === apiKey.value
    && key === runtimePending.value && targetId === runId.value
  runtimeReadBusy.value = true
  error.value = ''
  try {
    let confirmed = false
    if (operation && targetId && key) {
      const expected = expectedActionOperation(key, targetId)
      const receipt = await api.operation(credential, targetId, key)
      if (!stillCurrent()) return
      confirmed = expected !== null && receipt.state === 'finished' && receipt.run_id === targetId
        && receipt.request_key === key && receipt.operation === expected
      if (!confirmed) error.value = '原操作尚未按原目标与动作确认，已保留原号；可继续只读查看。'
    }
    const result = targetId ? await api.run(credential, targetId) : await api.recover(credential, targetCode, targetVersion, key)
    if (!stillCurrent()) return
    if ((targetId !== null && result.id !== targetId) || result.code !== targetCode || result.version !== targetVersion) throw new Error('原运行返回目标不一致，已保留原号')
    // Creation's by-key GET proves that same original creation; an ordinary parent
    // GET never proves a separate advance/retry/cancel or purge operation.
    if (targetId === null && !operation && key) confirmed = true
    acceptRun(result, confirmed)
  } catch (cause) { if (stillCurrent()) runtimeFailure(cause) }
  finally { if (serial === runtimeReadEpoch) runtimeReadBusy.value = false }
}
async function runAction(action: 'advance' | 'cancel' | 'retry', nodeId?: string): Promise<void> {
  if (existingRunView.value || !runId.value || runtimePending.value || purgePending.value || busy.value || !apiKey.value.trim()
    || (run.value?.input_state === 'purged' && action !== 'cancel')) return
  const current = runtimeEpoch, targetId = runId.value
  const credential = apiKey.value, targetCode = code.value, targetVersion = runtimeVersion.value, scope = runtimePrefix()
  if (action === 'retry' && (!nodeId || !run.value?.nodes.some(node => node.id === nodeId && node.state === 'failed'))) return
  if (action !== 'advance') {
    try { await ElMessageBox.confirm(action === 'retry' ? '仅对已证明失败的原子运行申请安全重试，可能产生新的子调用费用；服务端决定资格。继续？' : '停止后续节点，保留已发生的子运行和费用。继续？', '确认操作') } catch { return }
  }
  if (current !== runtimeEpoch || targetId !== runId.value || credential !== apiKey.value || scope !== runtimePrefix()
    || existingRunView.value || busy.value || runtimePending.value || purgePending.value) return
  const key = newRuntimeRequestId(`workflow-${action}`)
  try {
    const storage = runtimePrefix()
    const metadata = JSON.stringify({ request_key: key, run_id: targetId, action, node_id: action === 'retry' ? nodeId : null })
    sessionStorage.setItem(`${storage}:pending`, key)
    sessionStorage.setItem(`${storage}:action-pending`, key)
    sessionStorage.setItem(`${storage}:action-target`, metadata)
    if (sessionStorage.getItem(`${storage}:pending`) !== key || sessionStorage.getItem(`${storage}:action-pending`) !== key
      || sessionStorage.getItem(`${storage}:action-target`) !== metadata) throw new Error('原动作目标记录读回失败')
  }
  catch (cause) { error.value = `原请求号保存失败，本次未发送：${message(cause)}`; return }
  clearRunCost()
  actionExpectation = { scope: runtimePrefix(), runId: targetId, key, operation: action === 'retry' ? `retry:${nodeId ?? ''}` : action }
  actionPending.value = true
  runtimePending.value = key
  const flight = beginRuntimeFlight()
  error.value = ''
  try {
    const result = await api.action(credential, targetId, action, key, nodeId)
    if (current !== runtimeEpoch || scope !== runtimePrefix() || credential !== apiKey.value || runtimePending.value !== key) return
    if (result.id !== targetId || result.code !== targetCode || result.version !== targetVersion)
      throw new Error('原节点操作返回目标不一致，已保留原动作号；请只读找回。')
    invalidateRuntimeRead(); acceptRun(result)
  } catch (cause) { if (current === runtimeEpoch) runtimeFailure(cause) }
  finally { endRuntimeFlight(flight) }
}
function invalidatePrivateRun(): void {
  invalidateRuntimeRead()
  ++runtimeEpoch
  clearInspection()
  clearRunCost()
  run.value = null
  existingRun.value = null
  inputValues.value = {}
}
function clearPurgePending(): void {
  sessionStorage.removeItem(`${runtimePrefix()}:purge-pending`)
  purgePending.value = ''
}
async function purgeInput(): Promise<void> {
  const targetId = runId.value
  const scope = runtimeEpoch
  if (existingRunView.value || !targetId || !run.value || run.value.input_state === 'purged' || busy.value || runtimePending.value || purgePending.value || !apiKey.value.trim()) return
  try { await ElMessageBox.confirm('清理此运行的私有父输入与父成果正文后，不能继续或重试当前运行。节点状态、费用与子服务成果保留，子服务成果仍按当前授权读取。确认清理？', '清理私有父输入') } catch { return }
  if (existingRunView.value || scope !== runtimeEpoch || runId.value !== targetId || busy.value || runtimePending.value || purgePending.value) return
  const key = newRuntimeRequestId('workflow-purge-input')
  try { sessionStorage.setItem(`${runtimePrefix()}:purge-pending`, key) }
  catch (cause) { error.value = `原清理请求号保存失败，本次未发送：${message(cause)}`; return }
  invalidatePrivateRun()
  const current = runtimeEpoch
  purgePending.value = key
  const flight = beginRuntimeFlight()
  error.value = ''
  try {
    const result = await api.purgeInput(apiKey.value, targetId, key)
    if (current === runtimeEpoch) { invalidateRuntimeRead(); clearPurgePending(); acceptRun(result, false) }
  } catch (cause) { if (current === runtimeEpoch) runtimeFailure(cause) }
  finally { endRuntimeFlight(flight) }
}
async function recoverPurge(): Promise<void> {
  const targetId = runId.value
  const key = purgePending.value
  if (!targetId || !key || runtimeReadBusy.value || !apiKey.value.trim()) return
  const current = runtimeEpoch, serial = ++runtimeReadEpoch
  const credential = apiKey.value
  const stillCurrent = () => current === runtimeEpoch && serial === runtimeReadEpoch && targetId === runId.value
    && key === purgePending.value && credential === apiKey.value
  runtimeReadBusy.value = true
  error.value = ''
  try {
    const receipt = await api.operation(credential, targetId, key)
    if (!stillCurrent()) return
    if (receipt.state !== 'finished' || receipt.run_id !== targetId || receipt.request_key !== key || receipt.operation !== 'purge_input') {
      error.value = '原清理操作尚未确认，已保留原清理号；请稍后再次只读找回。'
      return
    }
    if (receipt.admission === 'rejected' && receipt.error_code === 'SAND_AI_COMPOSITION_RUN_BUSY') {
      sessionStorage.setItem(`${runtimePrefix()}:purge-rejected:${key}`, JSON.stringify({
        run_id: targetId, request_key: key, admission: receipt.admission, error_code: receipt.error_code,
      }))
      clearPurgePending()
      error.value = '本次清理未受理，可稍后明确重新申请。'
      return
    }
    if (receipt.admission !== 'admitted') {
      error.value = '原清理操作尚未确认，已保留原清理号；请稍后再次只读找回。'
      return
    }
    const result = await api.run(credential, targetId)
    if (!stillCurrent()) return
    if (result.id !== targetId || result.code !== code.value || result.version !== runtimeVersion.value || result.input_state !== 'purged') {
      error.value = '原清理回执已受理，但父输入清理状态尚未确认；已保留原清理号，请稍后再次只读找回。'
      return
    }
    clearPurgePending()
    acceptRun(result, false)
  } catch (cause) { if (stillCurrent()) runtimeFailure(cause) }
  finally { if (serial === runtimeReadEpoch) runtimeReadBusy.value = false }
}
function newRun(): void { if (existingRunView.value || runtimePending.value || purgePending.value || busy.value) return; clearRunCost(); sessionStorage.removeItem(`${runtimePrefix()}:run`); runId.value = null; run.value = null; inputValues.value = {} }
watch([code, runtimeVersion], () => { existingRunView.value = false; existingRun.value = null; clearRuntimeSubmission(); invalidateRuntimeRead(); clearRunCost(); clearInspection(); ++runtimeEpoch; run.value = null; inputValues.value = {}; adminBusy.value = false; purgePending.value = sessionStorage.getItem(`${runtimePrefix()}:purge-pending`) ?? ''; runtimePending.value = sessionStorage.getItem(`${runtimePrefix()}:pending`) ?? ''; actionPending.value = Boolean(sessionStorage.getItem(`${runtimePrefix()}:action-pending`)); const id = Number(sessionStorage.getItem(`${runtimePrefix()}:run`)); runId.value = Number.isSafeInteger(id) && id > 0 ? id : null }, { flush: 'sync' })
watch(apiKey, () => { existingRun.value = null; clearRuntimeSubmission(); invalidateRuntimeRead(); clearRunCost(); clearInspection(); ++runtimeEpoch; run.value = null; inputValues.value = {}; adminBusy.value = false }, { flush: 'sync' })
watch(existingRunNumber, () => {
  invalidateRuntimeRead(); clearRunCost(); clearInspection(); existingRun.value = null
  if (existingRunView.value) error.value = ''
}, { flush: 'sync' })
watch(code, restoreAdmin, { flush: 'sync' })
watch([() => props.environmentId, () => user.info.id, () => user.accessToken], async () => { existingRunNumber.value = ''; existingRunView.value = false; existingRun.value = null; clearRuntimeSubmission(); invalidateRuntimeRead(); clearRunCost(); clearInspection(); const current = ++epoch; ++runtimeEpoch; adminBusy.value = false; error.value = ''; definitions.value = []; catalog.value = []; detail.value = null; selectedCode.value = ''; code.value = ''; name.value = ''; nodes.value = []; outputs.value = []; versionId.value = null; runtimeVersion.value = null; apiKey.value = ''; inputValues.value = {}; run.value = null; runId.value = null; purgePending.value = ''; runtimePending.value = ''; resetAdminMemory(); adminStorageError.value = false; pendingAdminCodes.value = []; const env = props.environmentId; if (!env) return; readAdminRecoveryCodes(); adminBusy.value = true; try { const [list, services] = await Promise.all([api.list(env), api.catalog(env)]); if (current === epoch) { definitions.value = list.items; catalog.value = services.items } } catch (cause) { if (current === epoch) error.value = message(cause) } finally { if (current === epoch) adminBusy.value = false } }, { immediate: true, flush: 'sync' })
onUnmounted(() => { existingRun.value = null; clearRuntimeSubmission(); invalidateRuntimeRead(); clearRunCost(); clearInspection(); ++epoch; ++runtimeEpoch; resetAdminMemory(); apiKey.value = ''; inputValues.value = {}; run.value = null })
</script>

<style scoped>
.node { margin: 16px 0; padding: 16px; border: 1px solid var(--el-border-color); border-radius: var(--el-border-radius-base); }
pre { white-space: pre-wrap; overflow-wrap: anywhere; }
p { color: var(--el-text-color-secondary); }
</style>
