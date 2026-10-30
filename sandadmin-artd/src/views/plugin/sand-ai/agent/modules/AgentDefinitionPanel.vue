<template>
  <ElCard shadow="never">
    <template #header>
      <div class="panel-head">
        <div>
          <h3>智能体配置</h3>
          <p>选择资料和模型，说明回答要求，再保存并发布。应用和渠道使用同一份配置。</p>
        </div>
        <ElButton :disabled="!environmentId" :loading="loading" @click="load(1)">刷新</ElButton>
      </div>
    </template>
    <ElEmpty v-if="!environmentId" description="先选择应用环境" />
    <template v-else>
      <ElAlert v-if="error" type="error" :title="error" show-icon class="panel-alert" @close="error = ''" />
      <ElAlert v-if="!operationLockAvailable || operationJournalInvalid" type="warning" :closable="false" class="notice"
        title="暂不能安全保存智能体变更" description="浏览器缺少跨标签互斥能力，或原变更记录无法保存、读取。暂不能发起新变更；已有原请求仍可只读找回。记录异常不会按未提交处理。" />
      <ElAlert v-if="otherRecoveryCount" type="warning" :closable="false" class="notice"
        title="其他环境或身份存在未确认变更" description="请切回原应用环境，使用原管理员身份沿原请求号找回；页面刷新与身份切换不会删除原请求锁。" />
      <section v-if="currentRecoveries.length" class="notice" aria-label="智能体变更恢复">
        <ElAlert type="warning" :closable="false" title="智能体变更提交结果尚未确认"
          description="保存、发布、停用和回退使用同一智能体变更锁。只沿原请求号读取受理回执；未找到或无权读取时仍保留锁，不重新提交。当前配置以找回后重新读取的结果为准。" />
        <p v-for="item in currentRecoveries" :key="item.key">
          {{ item.code }} · {{ operationLabel(item.operation) }}{{ item.rollback_version ? ` · 第 ${item.rollback_version} 版` : '' }}
          <ElButton link type="primary" :loading="operationRecoveringKey === item.key" :disabled="writing || Boolean(operationRecoveringKey)" @click="recoverOperation(item)">沿原请求号找回</ElButton>
        </p>
        <ElAlert v-if="operationRecoveryError" type="error" :closable="false" :title="operationRecoveryError" />
      </section>
      <ElTable v-loading="loading" :data="rows" row-key="agent_definition_id" empty-text="还没有智能体配置">
        <ElTableColumn label="名称" prop="name" min-width="170" />
        <ElTableColumn label="代码" prop="code" min-width="150" />
        <ElTableColumn label="已发布版本" min-width="125">
          <template #default="{ row }">{{ row.active_version_no ? `第 ${row.active_version_no} 版` : '未发布' }}</template>
        </ElTableColumn>
        <ElTableColumn label="待发布草稿" min-width="125">
          <template #default="{ row }">{{ row.draft_version_no ? `第 ${row.draft_version_no} 版` : '—' }}</template>
        </ElTableColumn>
        <ElTableColumn label="状态" width="100">
          <template #default="{ row }"><ElTag :type="row.status === 1 ? 'success' : 'info'">{{ row.status === 1 ? '可用' : '已停用' }}</ElTag></template>
        </ElTableColumn>
        <ElTableColumn label="操作" min-width="210">
          <template #default="{ row }">
            <ElButton link type="primary" :disabled="writing" @click="edit(row)">编辑草稿</ElButton>
            <ElButton link type="primary" :disabled="writing" @click="openVersions(row)">版本与回退</ElButton>
            <ElButton v-if="row.draft_version_id" link type="success" :disabled="writing || Boolean(operationRecoveringKey) || operationBlocked(row.code)" @click="publish(row)">发布</ElButton>
            <ElButton v-if="row.status === 1 && row.active_version_id" link type="danger" :disabled="writing || Boolean(operationRecoveringKey) || operationBlocked(row.code)" @click="disable(row)">停用</ElButton>
          </template>
        </ElTableColumn>
      </ElTable>
      <ElPagination v-if="total > 20" class="pager" background layout="prev, pager, next, total"
        :current-page="page" :page-size="20" :total="total" @current-change="load" />
      <ElDivider />
      <ElSteps :active="guideStep" finish-status="success" simple class="guide">
        <ElStep title="选择资料与模型" /><ElStep title="配置回答要求" /><ElStep title="保存并发布" />
      </ElSteps>
      <ElAlert type="info" :closable="false" class="panel-alert" title="从一个用途开始"
        description="资料来自当前环境已发布的知识库，模型来自环境能力配置。保存草稿不会改变应用正在使用的版本；发布后到“发布版本试用”发送问题、查看来源和费用。" />
      <ElSpace wrap class="guide">
        <span>回答要求示例：</span>
        <ElButton :disabled="writing || editingLoading" @click="usePreset('answer')">资料问答</ElButton>
        <ElButton :disabled="writing || editingLoading" @click="usePreset('explain')">逐项查证</ElButton>
      </ElSpace>
      <div class="panel-head"><h3>{{ editingId ? '编辑草稿' : '新建智能体' }}</h3><ElButton :disabled="writing" @click="newDraft">新建</ElButton></div>
      <ElForm v-loading="editingLoading" :disabled="writing || editingLoading" label-width="105px" @submit.prevent>
        <ElFormItem label="名称"><ElInput v-model="form.name" maxlength="128" :disabled="writing || lockedName" placeholder="例如客服知识助手" /></ElFormItem>
        <ElFormItem label="代码"><ElInput v-model="form.code" maxlength="64" :disabled="writing || !!editingId" placeholder="例如 support" /></ElFormItem>
        <ElFormItem label="使用模型">
          <ElSelect v-model="form.model" filterable allow-create default-first-option :loading="modelsLoading"
            :disabled="writing" placeholder="选择当前环境已发布的聊天模型" style="width: 100%">
            <ElOption v-for="model in models" :key="model.code" :label="`${model.name} · ${model.code}`" :value="model.code" />
          </ElSelect>
          <div class="knowledge-actions">
            <ElButton link type="primary" :loading="modelsLoading" @click="loadModels">刷新模型</ElButton>
          </div>
          <p v-if="modelsError" class="knowledge-note">{{ modelsError }}。草稿仍可手动填写模型代码，发布需当前环境存在可用路由。</p>
        </ElFormItem>
        <ElFormItem label="参考知识库">
          <ElSelect v-model="form.knowledge_base_code" filterable allow-create default-first-option clearable
            :loading="knowledgeLoading" :disabled="writing" placeholder="选择当前环境已发布的知识别名" style="width: 100%">
            <ElOption v-for="item in knowledge" :key="item.id" :value="item.code"
              :label="`${item.owner_name} · ${item.code}（第 ${item.version_no} 版）`" />
          </ElSelect>
          <div class="knowledge-actions">
            <ElButton link type="primary" :loading="knowledgeLoading" @click="loadKnowledge(true)">刷新知识库</ElButton>
            <ElButton v-if="knowledgeMore" link type="primary" :loading="knowledgeLoading" @click="loadKnowledge(false)">更多</ElButton>
          </div>
          <p v-if="knowledgeError" class="knowledge-note">{{ knowledgeError }}。有权确认别名时可手动输入。</p>
          <p v-else class="knowledge-note">发布前须绑定当前环境已开放的知识库；发布时服务端会再次核验。</p>
        </ElFormItem>
        <ElFormItem label="回答要求"><ElInput v-model="form.instructions" type="textarea" :rows="5" maxlength="16000" show-word-limit :disabled="writing" placeholder="说明用途、回答边界和引用要求" /></ElFormItem>
        <ElFormItem label="使用工具">
          <ElCheckbox :model-value="form.tools.includes('retrieval.search')" disabled>私有资料检索</ElCheckbox>
          <span class="hint">发布智能体必须使用此工具，仅检索当前调用者获授权的资料</span>
        </ElFormItem>
        <ElAlert v-if="unsupportedTools.length" type="warning" :closable="false" class="panel-alert"
          title="此草稿包含当前页面不支持的工具" :description="`已保留 ${unsupportedTools.join('、')}，请核对运行版本后再编辑。`" />
        <ElFormItem label="只读外部工具"><ReadonlyToolSelector v-model="form.readonly_tools" :environment-id="environmentId" :disabled="writing || editingLoading" /></ElFormItem>
        <ElFormItem label="人工复核"><ElSwitch v-model="form.review_required" :disabled="writing" /></ElFormItem>
        <ElFormItem label="历史对话">
          <ElSelect v-model="form.context_turn_limit" :disabled="writing" aria-label="历史对话轮数">
            <ElOption v-for="turns in contextTurnOptions" :key="turns" :value="turns"
              :label="turns === 0 ? '0 轮（不带历史）' : `最近 ${turns} 轮`" />
          </ElSelect>
          <p class="knowledge-note">已发布智能体和渠道回答最多参考最近 0–4 轮对话，默认 2 轮；0 表示不带历史。已发布试用仅采用同一身份、同一发布版本与同一会话中已成功且无需复核的回答；渠道另按送达状态核对。</p>
          <p class="knowledge-note">轮数随智能体版本固定。修改后需保存草稿并发布新版本，才会影响后续新任务。</p>
        </ElFormItem>
        <ElFormItem label="输出上限"><ElInputNumber v-model="form.max_output_tokens" :min="1" :max="form.readonly_tools.length ? 512 : 2048" :disabled="writing" /><span class="hint">{{ form.readonly_tools.length ? '本组合每轮最多 512；留空为 512' : '留空使用能力默认值' }}</span></ElFormItem>
        <ElDescriptions :column="1" border class="preview">
          <ElDescriptionsItem label="专业组合预览">{{ selectedKnowledge }} → 私有资料检索 → {{ selectedModel }} → {{ form.review_required ? '人工复核后使用' : '由应用使用者确认来源' }}</ElDescriptionsItem>
          <ElDescriptionsItem label="回答要求">{{ form.instructions.trim() || '请填写回答边界和引用要求' }}</ElDescriptionsItem>
          <ElDescriptionsItem label="历史上下文">{{ form.context_turn_limit === 0 ? '不带历史对话' : `最多参考最近 ${form.context_turn_limit} 轮有效、无需复核的回答` }}</ElDescriptionsItem>
        </ElDescriptions>
        <p class="knowledge-note">以上组合随发布版本固定。只读工具的精确版本、业务范围和 Schema 会一起固定；模型、知识库、工具或回答要求变化后需保存并发布新版本。</p>
        <ElFormItem><ElButton type="primary" :loading="writing" :disabled="!canSave" @click="save">保存草稿</ElButton></ElFormItem>
      </ElForm>
      <ElDialog v-model="versionsVisible" title="配置版本与回退" width="760px">
        <ElAlert type="info" :closable="false" title="回退配置版本"
          description="回退恢复此智能体当时的定义配置。模型代码与知识库别名仍按执行时当前可用路由及发布内容解析，不还原历史知识库正文。旧任务保留原实际快照；版本改变后，旧版本任务可能因当前版本限制无法读取。" />
        <ElAlert v-if="versionError" type="error" :title="versionError" show-icon />
        <ElTable v-loading="versionsLoading" :data="versions" row-key="version_no">
          <ElTableColumn label="版本" width="90"><template #default="{ row }">第 {{ row.version_no }} 版</template></ElTableColumn>
          <ElTableColumn label="状态" width="110"><template #default="{ row }">{{ row.is_active ? '当前发布' : row.state === 'published' ? '已发布历史' : '草稿' }}</template></ElTableColumn>
          <ElTableColumn label="模型 / 知识库" min-width="190"><template #default="{ row }">{{ row.config.model }} / {{ row.config.knowledge_base_code || '未绑定' }}</template></ElTableColumn>
          <ElTableColumn label="只读工具" min-width="180"><template #default="{ row }">{{ toolVersionsLabel(row.config.readonly_tools) }}</template></ElTableColumn>
          <ElTableColumn label="操作" width="130"><template #default="{ row }"><ElButton link type="warning" :disabled="writing || Boolean(operationRecoveringKey) || operationBlocked(versionOwner?.code ?? '') || row.is_active || row.state !== 'published' || versionOwner?.status !== 1" @click="rollback(row)">回退到此配置</ElButton></template></ElTableColumn>
          <ElTableColumn type="expand"><template #default="{ row }"><p>快照：{{ row.snapshot_sha256 }} · 创建：{{ row.created_at }}</p><pre>{{ JSON.stringify(row.config, null, 2) }}</pre></template></ElTableColumn>
        </ElTable>
        <ElPagination v-if="versionsTotal > 20" :current-page="versionsPage" :page-size="20" :total="versionsTotal" layout="prev, pager, next" @current-change="loadVersions" />
      </ElDialog>
    </template>
  </ElCard>
</template>

<script setup lang="ts">
  import { useUserStore } from '@/store/modules/user'
  import { computed, onUnmounted, reactive, ref, watch } from 'vue'
  import { ElMessage, ElMessageBox } from 'element-plus'
  import agentDefinitionApi, { type AgentDefinitionOperation, type AgentDefinitionOperationReceipt, type AgentDefinitionRejectedPublishReceipt, type AgentDraft, type AgentAvailableModel, type AgentContextTurnLimit, type AgentDefinitionRow, type AgentReadonlyToolSelection, type AgentVersionHistory } from '../../api/agentDefinition'
  import knowledgePublicationApi, { type ConsumerKnowledgePublication } from '../../api/knowledgePublication'
  import ReadonlyToolSelector from './ReadonlyToolSelector.vue'
  import { newRuntimeRequestId } from '../../api/runtime'

  const versionsVisible = ref(false)
  const versions = ref<AgentVersionHistory[]>([])
  const versionsLoading = ref(false)
  const versionsTotal = ref(0)
  const versionsPage = ref(1)
  const versionError = ref('')
  const versionOwner = ref<AgentDefinitionRow | null>(null)
  let versionsEpoch = 0
  const props = defineProps<{ environmentId: number | null }>()
  const userStore = useUserStore()
  const operationJournalKey = 'sand-ai-agent-definition-operation-journal:v1'
  const operationLockAvailable = typeof navigator !== 'undefined' && !!navigator.locks
  type OperationReference = Readonly<{
    key: string; user_id: string; environment_id: number; code: string; operation: AgentDefinitionOperation
    definition_id: number | null; rollback_version: number | null; expected_publish_sha256: string | null
  }>
  const operationJournal = ref<OperationReference[]>([])
  const operationJournalInvalid = ref(false)
  const operationRecoveryError = ref('')
  const operationRecoveringKey = ref('')
  const currentAdminId = computed(() => userStore.isLogin && userStore.accessToken && userStore.info.id != null ? String(userStore.info.id) : '')
  const currentRecoveries = computed(() => operationJournal.value.filter(item => item.user_id === currentAdminId.value && item.environment_id === props.environmentId))
  const otherRecoveryCount = computed(() => operationJournal.value.length - currentRecoveries.value.length)
  let identityEpoch = 0
  const rows = ref<AgentDefinitionRow[]>([])
  const models = ref<AgentAvailableModel[]>([])
  const modelsLoading = ref(false)
  const modelsError = ref('')
  let modelEpoch = 0
  const knowledge = ref<ConsumerKnowledgePublication[]>([])
  const knowledgeLoading = ref(false)
  const knowledgeError = ref('')
  const knowledgeMore = ref(false)
  let knowledgePage = 0
  let knowledgeEpoch = 0
  const total = ref(0)
  const page = ref(1)
  const loading = ref(false)
  const managementFlight = ref<symbol | null>(null)
  const writing = computed(() => managementFlight.value !== null)
  const error = ref('')
  const editingId = ref<number | null>(null)
  const editingLoading = ref(false)
  const lockedName = ref(false)
  let epoch = 0
  let scopeEpoch = 0
  let formEpoch = 0
  const contextTurnOptions: readonly AgentContextTurnLimit[] = [0, 1, 2, 3, 4]
  const form = reactive<{
    code: string
    name: string
    model: string
    knowledge_base_code: string
    instructions: string
    readonly_tools: AgentReadonlyToolSelection[]
    tools: string[]
    review_required: boolean
    max_output_tokens: number | null
    context_turn_limit: AgentContextTurnLimit
  }>({ code: '', name: '', model: '', knowledge_base_code: '', instructions: '', readonly_tools: [], tools: ['retrieval.search'], review_required: true, max_output_tokens: null, context_turn_limit: 2 })
  const unsupportedTools = computed(() => form.tools.filter(tool => tool !== 'retrieval.search'))
  const canSave = computed(() => !operationBlocked(form.code.trim()) && !operationRecoveringKey.value && !!props.environmentId && /^[a-z][a-z0-9_-]{0,63}$/.test(form.code.trim())
    && !!form.name.trim() && !!form.model.trim() && !!form.instructions.trim() && !writing.value && !editingLoading.value
    && unsupportedTools.value.length === 0 && form.tools.includes('retrieval.search')
    && form.readonly_tools.length <= 2 && form.readonly_tools.every(tool => /^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/.test(tool.business_scope))
    && (!form.readonly_tools.length || form.max_output_tokens === null || form.max_output_tokens <= 512))
  const guideStep = computed(() => !form.model || !form.knowledge_base_code ? 0 : !form.instructions.trim() ? 1 : 2)
  const selectedModel = computed(() => models.value.find(item => item.code === form.model)?.name ?? (form.model || '待选模型'))
  const selectedKnowledge = computed(() => knowledge.value.find(item => item.code === form.knowledge_base_code)?.owner_name ?? (form.knowledge_base_code || '待选知识库'))
  async function usePreset(preset: 'answer' | 'explain'): Promise<void> {
    if (writing.value || editingLoading.value) return
    const current = scopeEpoch
    const currentForm = formEpoch
    if (form.instructions.trim()) {
      try { await ElMessageBox.confirm('此操作会替换当前草稿中的回答要求；其他设置保留。', '使用回答要求示例') }
      catch { return }
    }
    if (current !== scopeEpoch || currentForm !== formEpoch || writing.value || editingLoading.value) return
    form.instructions = preset === 'answer'
      ? '根据检索到的资料回答问题，逐项保留来源引用。资料不足或相互矛盾时说明缺口，不补写未查证事实；无法从资料确认的问题请明确告知。'
      : '逐项核对问题中的事实，仅以检索资料作为依据，为每项结论保留来源引用。区分有依据的结论、不同来源的分歧和待查证的问题，不将推测写成事实。'
  }

  function newDraft(): void {
    ++formEpoch
    editingLoading.value = false
    editingId.value = null
    lockedName.value = false
    Object.assign(form, { code: '', name: '', model: '', knowledge_base_code: '', instructions: '', readonly_tools: [], tools: ['retrieval.search'], review_required: true, max_output_tokens: null, context_turn_limit: 2 })
  }
  async function loadModels(): Promise<void> {
    const environmentId = props.environmentId
    if (!environmentId || !currentAdminId.value) return
    const current = ++modelEpoch
    modelsLoading.value = true
    modelsError.value = ''
    try {
      const result = await agentDefinitionApi.models(environmentId)
      if (current !== modelEpoch || props.environmentId !== environmentId) return
      models.value = result.data.filter(item => item.type === 'chat' && item.capabilities.includes('chat'))
    } catch (failure) {
      if (current === modelEpoch) {
        models.value = []
        modelsError.value = failure instanceof Error ? failure.message : '当前环境模型读取失败'
      }
    } finally {
      if (current === modelEpoch) modelsLoading.value = false
    }
  }
  async function loadKnowledge(reset: boolean): Promise<void> {
    const environmentId = props.environmentId
    if (!environmentId || !currentAdminId.value || (knowledgeLoading.value && !reset)) return
    const current = ++knowledgeEpoch
    const targetPage = reset ? 1 : knowledgePage + 1
    knowledgeLoading.value = true
    knowledgeError.value = ''
    try {
      const result = await knowledgePublicationApi.consumer(environmentId, targetPage)
      if (current !== knowledgeEpoch || props.environmentId !== environmentId) return
      const available = result.data.filter(item => item.owner_environment_id === environmentId)
      knowledge.value = reset ? available : [...knowledge.value, ...available]
      knowledgePage = targetPage
      knowledgeMore.value = targetPage * 100 < result.total
    } catch (failure) {
      if (current === knowledgeEpoch) {
        knowledgeError.value = failure instanceof Error ? failure.message : '可用知识库读取失败'
      }
    } finally {
      if (current === knowledgeEpoch) knowledgeLoading.value = false
    }
  }
  async function load(targetPage: number): Promise<void> {
    const environmentId = props.environmentId
    if (!environmentId || !currentAdminId.value) return
    const current = ++epoch
    loading.value = true
    error.value = ''
    try {
      const result = await agentDefinitionApi.list(environmentId, targetPage)
      if (current !== epoch) return
      rows.value = result.data
      total.value = result.total
      page.value = targetPage
    } catch (failure) {
      if (current === epoch) error.value = failure instanceof Error ? failure.message : '智能体列表读取失败'
    } finally {
      if (current === epoch) loading.value = false
    }
  }
  async function edit(row: AgentDefinitionRow): Promise<void> {
    const environmentId = props.environmentId
    if (!environmentId) return
    const current = ++formEpoch
    editingLoading.value = true
    error.value = ''
    try {
      const detail = await agentDefinitionApi.read(environmentId, row.code)
      if (current !== formEpoch || props.environmentId !== environmentId) return
      editingId.value = detail.agent_definition_id
      lockedName.value = detail.state === 'published'
      Object.assign(form, {
        code: detail.code, name: detail.name,
        model: detail.latest_version?.model ?? '',
        knowledge_base_code: detail.latest_version?.knowledge_base_code ?? '',
        instructions: detail.latest_version?.instructions ?? '',
        readonly_tools: (detail.latest_version?.readonly_tools ?? []).map(tool => ({ ...tool })),
        tools: [...(detail.latest_version?.tools ?? ['retrieval.search'])],
        review_required: detail.latest_version?.review_required ?? true,
        max_output_tokens: detail.latest_version?.max_output_tokens ?? null,
        context_turn_limit: detail.latest_version?.context_turn_limit ?? 2
      })
    } catch (failure) {
      if (current === formEpoch) error.value = failure instanceof Error ? failure.message : '智能体详情读取失败'
    } finally { if (current === formEpoch) editingLoading.value = false }
  }
  function operationLabel(operation: AgentDefinitionOperation): string {
    return { draft: '保存草稿', publish: '发布', disable: '停用', rollback: '回退配置' }[operation]
  }
  function validOperationReference(raw: unknown): raw is OperationReference {
    if (!raw || typeof raw !== 'object' || Array.isArray(raw)) return false
    const value = raw as Record<string, unknown>
    const positive = (id: unknown): id is number => typeof id === 'number' && Number.isSafeInteger(id) && id > 0
    return typeof value.key === 'string' && /^[\x21-\x7e]{1,128}$/.test(value.key)
      && typeof value.user_id === 'string' && /^[1-9]\d*$/.test(value.user_id)
      && positive(value.environment_id) && typeof value.code === 'string' && /^[a-z][a-z0-9_-]{0,63}$/.test(value.code)
      && ['draft', 'publish', 'disable', 'rollback'].includes(String(value.operation))
      && (value.definition_id === null || positive(value.definition_id))
      && (value.operation === 'draft' || positive(value.definition_id))
      && (value.operation === 'rollback' ? positive(value.rollback_version) : value.rollback_version === null)
      && (value.operation === 'publish' ? typeof value.expected_publish_sha256 === 'string' && /^[a-f0-9]{64}$/.test(value.expected_publish_sha256) : value.expected_publish_sha256 === null)
  }
  function readOperationJournal(): void {
    try {
      const value: unknown = JSON.parse(localStorage.getItem(operationJournalKey) || '[]')
      if (!Array.isArray(value) || value.length > 256 || !value.every(validOperationReference)) throw new Error('invalid journal')
      operationJournal.value = value
      operationJournalInvalid.value = false
    } catch { operationJournalInvalid.value = true }
  }
  function writeOperationJournal(entries: OperationReference[]): boolean {
    try {
      if (entries.length > 256) throw new Error('journal full')
      localStorage.setItem(operationJournalKey, JSON.stringify(entries))
      operationJournal.value = entries
      return true
    } catch {
      operationJournalInvalid.value = true
      operationRecoveryError.value = '无法保存原变更请求号，暂不能发起新变更。请保留本页并核对原智能体。'
      return false
    }
  }
  function operationBlocked(code: string): boolean {
    return !operationLockAvailable || operationJournalInvalid.value || !currentAdminId.value
      || operationJournal.value.some(item => item.environment_id === props.environmentId && item.code === code)
  }
  function originalScope(reference: OperationReference): boolean {
    return reference.environment_id === props.environmentId && reference.user_id === currentAdminId.value
  }
  function receiptMatches(receipt: AgentDefinitionOperationReceipt, reference: OperationReference): boolean {
    const positive = (id: number) => Number.isSafeInteger(id) && id > 0
    if (receipt.code !== reference.code || !positive(receipt.agent_definition_id) || !positive(receipt.agent_version_id)
      || (reference.definition_id !== null && receipt.agent_definition_id !== reference.definition_id)) return false
    if (reference.operation === 'disable') return receipt.state === 'disabled'
    if (receipt.state !== (reference.operation === 'draft' ? 'draft' : 'published')
      || !('version' in receipt) || !positive(receipt.version) || !/^[a-f0-9]{64}$/.test(receipt.snapshot_sha256)) return false
    return (reference.operation !== 'rollback' || receipt.version === reference.rollback_version)
      && (reference.operation !== 'publish' || receipt.snapshot_sha256 === reference.expected_publish_sha256)
  }
  function rejectedPublishMatches(receipt: AgentDefinitionRejectedPublishReceipt, reference: OperationReference): boolean {
    return reference.operation === 'publish' && reference.definition_id !== null && reference.expected_publish_sha256 !== null
      && receipt.operation_state === 'rejected' && receipt.reason === 'configuration_changed' && receipt.idempotent_replay === true
      && receipt.agent_definition_id === reference.definition_id && receipt.code === reference.code
      && receipt.expected_snapshot_sha256 === reference.expected_publish_sha256 && /^[a-f0-9]{64}$/.test(receipt.expected_snapshot_sha256)
  }
  async function startOperation(operation: AgentDefinitionOperation, code: string, definitionId: number | null,
    rollbackVersion: number | null, publishSha: string | null, stillCurrent: () => boolean): Promise<OperationReference | null> {
    if (!operationLockAvailable || !stillCurrent()) return null
    return await navigator.locks.request(operationJournalKey, { mode: 'exclusive' }, () => {
      const environment = props.environmentId
      if (!environment || !stillCurrent()) return null
      readOperationJournal()
      if (operationBlocked(code)) {
        error.value = '此智能体仍有未确认变更，请先沿原请求号找回，不能改用另一操作重新提交。'
        return null
      }
      const reference: OperationReference = { key: newRuntimeRequestId(`agent-${operation}`), user_id: currentAdminId.value,
        environment_id: environment, code, operation, definition_id: definitionId, rollback_version: rollbackVersion, expected_publish_sha256: publishSha }
      return validOperationReference(reference) && writeOperationJournal([...operationJournal.value, reference]) ? reference : null
    })
  }
  async function acknowledgeOperation(reference: OperationReference, stillCurrent: () => boolean): Promise<boolean> {
    const clearConfirmed = () => {
      if (!stillCurrent()) return false
      readOperationJournal()
      if (operationJournalInvalid.value) return false
      return writeOperationJournal(operationJournal.value.filter(item => !(item.key === reference.key && item.user_id === reference.user_id
        && item.environment_id === reference.environment_id && item.code === reference.code && item.operation === reference.operation
        && item.definition_id === reference.definition_id && item.rollback_version === reference.rollback_version && item.expected_publish_sha256 === reference.expected_publish_sha256)))
    }
    return operationLockAvailable ? await navigator.locks.request(operationJournalKey, { mode: 'exclusive' }, clearConfirmed) : clearConfirmed()
  }
  async function readBackOperation(reference: OperationReference, stillCurrent: () => boolean): Promise<void> {
    if (!stillCurrent()) return
    await load(page.value)
    if (!stillCurrent()) return
    // Read actual current configuration; the receipt is only an operation snapshot.
    const detail = await agentDefinitionApi.read(reference.environment_id, reference.code)
    if (!stillCurrent()) return
    if (detail.code !== reference.code) throw new Error('重新读取的智能体不一致，请刷新配置')
    if (form.code.trim() === reference.code) {
      newDraft()
      const row = rows.value.find(item => item.code === reference.code)
      if (row) await edit(row)
    }
    if (stillCurrent() && versionOwner.value?.code === reference.code) await loadVersions(versionsPage.value)
  }
  async function recoverOperation(reference: OperationReference): Promise<void> {
    if (!originalScope(reference) || writing.value || operationRecoveringKey.value) return
    const current = scopeEpoch
    const identity = identityEpoch
    const stillCurrent = () => current === scopeEpoch && identity === identityEpoch && originalScope(reference)
    operationRecoveringKey.value = reference.key
    operationRecoveryError.value = ''
    try {
      const result = await agentDefinitionApi.operationByRequestKey(reference.environment_id, reference.operation, reference.code, reference.key)
      if (!stillCurrent()) return
      if ('operation_state' in result) {
        if (!rejectedPublishMatches(result, reference)) throw new Error('发布拒绝回执与原智能体或已确认快照不一致，仍保留原请求锁')
        if (!await acknowledgeOperation(reference, stillCurrent) || !stillCurrent()) return
        error.value = '原草稿在确认后发生变化，本次没有发布。请重新读取当前配置，并确认新的草稿版本后再发布。'
        ElMessage.warning('原发布已明确拒绝，未发布；请重新确认当前草稿')
        await readBackOperation(reference, stillCurrent)
        if (stillCurrent()) error.value = '原草稿在确认后发生变化，本次没有发布。当前配置已重新读取，请重新确认草稿版本后再发布。'
        return
      }
      if (result.idempotent_replay !== true || !receiptMatches(result, reference)) throw new Error('原操作回执与原智能体、版本或发布快照不一致，仍保留原请求锁')
      if (!await acknowledgeOperation(reference, stillCurrent) || !stillCurrent()) return
      ElMessage.success('已找回原变更回执，当前配置正在重新读取')
      await readBackOperation(reference, stillCurrent)
    } catch (failure) {
      if (stillCurrent()) {
        const message = failure instanceof Error ? failure.message : '原变更回执未找回'
        if (operationJournal.value.some(item => item.key === reference.key && item.code === reference.code))
          operationRecoveryError.value = `${message}；请核对原请求，未确认时不能再次变更。`
        else error.value = `原变更回执已确认，但当前配置未能完整重读：${message}。请刷新并重新读取配置后再确认变更。`
      }
    } finally { if (stillCurrent()) operationRecoveringKey.value = '' }
  }
  async function completeOperation(reference: OperationReference, result: AgentDefinitionOperationReceipt, stillCurrent: () => boolean): Promise<void> {
    if (!stillCurrent()) return
    if (!receiptMatches(result, reference)) throw new Error('操作回执与原智能体、版本或发布快照不一致，请沿原请求号找回')
    if (!await acknowledgeOperation(reference, stillCurrent) || !stillCurrent()) return
    ElMessage.success(`${operationLabel(reference.operation)}已确认，当前配置正在重新读取`)
    await readBackOperation(reference, stillCurrent)
  }
  async function save(): Promise<void> {
    const environmentId = props.environmentId
    if (!environmentId || !canSave.value || writing.value) return
    const current = scopeEpoch
    const identity = identityEpoch
    const stillCurrent = () => current === scopeEpoch && identity === identityEpoch && props.environmentId === environmentId
    const draft: AgentDraft = { environment_id: environmentId, code: form.code.trim(), name: form.name.trim(),
      model: form.model.trim(), instructions: form.instructions.trim(), tools: [...form.tools], review_required: form.review_required,
      max_output_tokens: form.max_output_tokens, knowledge_base_code: form.knowledge_base_code.trim() || null,
      context_turn_limit: form.context_turn_limit, readonly_tools: form.readonly_tools.map(tool => ({ ...tool })) }
    const flight = Symbol('agent-management')
    managementFlight.value = flight
    error.value = ''
    try {
      const reference = await startOperation('draft', draft.code, editingId.value, null, null, stillCurrent)
      if (!reference || !stillCurrent()) return
      const result = await agentDefinitionApi.draft(draft, reference.key)
      await completeOperation(reference, result, stillCurrent)
    } catch (failure) { if (stillCurrent()) error.value = `${failure instanceof Error ? failure.message : '保存结果未确认'}；请沿原请求号找回，不要重新提交。` }
    finally { if (managementFlight.value === flight) managementFlight.value = null }
  }
  async function publish(row: AgentDefinitionRow): Promise<void> {
    const environmentId = props.environmentId
    if (!environmentId || writing.value || operationRecoveringKey.value || operationBlocked(row.code)) return
    const current = scopeEpoch
    const identity = identityEpoch
    const stillCurrent = () => current === scopeEpoch && identity === identityEpoch && props.environmentId === environmentId
    const flight = Symbol('agent-management')
    managementFlight.value = flight
    error.value = ''
    let dispatched = false
    try {
      const history = await agentDefinitionApi.versions(environmentId, row.code, 1)
      if (!stillCurrent()) return
      const drafts = history.data.filter(version => version.state === 'draft')
      const draft = drafts[0]
      if (drafts.length !== 1 || !draft || draft.version_no !== row.draft_version_no || !/^[a-f0-9]{64}$/.test(draft.snapshot_sha256)) {
        error.value = '未能确认唯一的当前草稿或发布快照，请刷新列表、重新读取配置后再发布。'
        return
      }
      const expectedSha = draft.snapshot_sha256
      try { await ElMessageBox.confirm(`发布「${row.name}」第 ${draft.version_no} 版草稿（模型 ${draft.config.model}，快照 ${expectedSha.slice(0, 12)}）。应用和渠道将使用此配置，旧版本结果将不可用。确认期间草稿变化会拒绝发布，请刷新并重新确认。`, '发布已确认的智能体草稿', { type: 'warning', confirmButtonText: '发布此版草稿' }) }
      catch { return }
      if (!stillCurrent()) return
      const reference = await startOperation('publish', row.code, row.agent_definition_id, null, expectedSha, stillCurrent)
      if (!reference || !stillCurrent()) return
      dispatched = true
      const result = await agentDefinitionApi.publish(environmentId, row.agent_definition_id, reference.key, expectedSha)
      await completeOperation(reference, result, stillCurrent)
    } catch (failure) { if (stillCurrent()) error.value = dispatched
      ? `${failure instanceof Error ? failure.message : '发布结果未确认'}；请沿原请求号找回。若草稿冲突，请重新读取当前配置并确认，不能自动改版发布。`
      : `${failure instanceof Error ? failure.message : '草稿读取失败'}；本次未提交发布，请刷新列表、重新读取配置后确认。` }
    finally { if (managementFlight.value === flight) managementFlight.value = null }
  }
  async function disable(row: AgentDefinitionRow): Promise<void> {
    const environmentId = props.environmentId
    if (!environmentId || writing.value || operationRecoveringKey.value || operationBlocked(row.code)) return
    const current = scopeEpoch
    const identity = identityEpoch
    const stillCurrent = () => current === scopeEpoch && identity === identityEpoch && props.environmentId === environmentId
    const flight = Symbol('agent-management')
    managementFlight.value = flight
    error.value = ''
    try {
      try { await ElMessageBox.confirm(`停用「${row.name}」后，新任务和旧版本结果都将不可用。`, '停用智能体', { type: 'warning' }) }
      catch { return }
      if (!stillCurrent()) return
      const reference = await startOperation('disable', row.code, row.agent_definition_id, null, null, stillCurrent)
      if (!reference || !stillCurrent()) return
      const result = await agentDefinitionApi.disable(environmentId, row.agent_definition_id, reference.key)
      await completeOperation(reference, result, stillCurrent)
    } catch (failure) { if (stillCurrent()) error.value = `${failure instanceof Error ? failure.message : '停用结果未确认'}；请沿原请求号找回，不要重新提交。` }
    finally { if (managementFlight.value === flight) managementFlight.value = null }
  }
  function toolVersionsLabel(tools: readonly AgentReadonlyToolSelection[] | undefined): string { return tools?.map(tool => `${tool.code} v${tool.version} · ${tool.business_scope}`).join('；') || '仅私有检索' }
  async function openVersions(row: AgentDefinitionRow): Promise<void> {
    if (writing.value) return
    versionOwner.value = row
    versionsVisible.value = true
    await loadVersions(1)
  }
  async function loadVersions(target: number): Promise<void> {
    const environment = props.environmentId
    const owner = versionOwner.value
    if (!environment || !owner) return
    const current = ++versionsEpoch
    versionsLoading.value = true
    versionError.value = ''
    try {
      const result = await agentDefinitionApi.versions(environment, owner.code, target)
      if (current !== versionsEpoch || environment !== props.environmentId) return
      versions.value = result.data
      versionsTotal.value = result.total
      versionsPage.value = target
    } catch (failure) {
      if (current === versionsEpoch) { versions.value = []; versionError.value = failure instanceof Error ? failure.message : '版本读取失败' }
    } finally { if (current === versionsEpoch) versionsLoading.value = false }
  }
  async function rollback(version: AgentVersionHistory): Promise<void> {
    const environment = props.environmentId
    const owner = versionOwner.value
    if (!environment || !owner || writing.value || operationRecoveringKey.value || operationBlocked(owner.code)
      || version.state !== 'published' || version.is_active || owner.status !== 1) return
    const current = scopeEpoch
    const identity = identityEpoch
    const stillCurrent = () => current === scopeEpoch && identity === identityEpoch && props.environmentId === environment
    const flight = Symbol('agent-management')
    managementFlight.value = flight
    versionError.value = ''
    try {
      try { await ElMessageBox.confirm(`将「${owner.name}」恢复为第 ${version.version_no} 版定义配置；知识库别名仍使用当前发布内容。`, '回退配置版本', { type: 'warning' }) }
      catch { return }
      if (!stillCurrent()) return
      const reference = await startOperation('rollback', owner.code, owner.agent_definition_id, version.version_no, null, stillCurrent)
      if (!reference || !stillCurrent()) return
      const result = await agentDefinitionApi.rollback(environment, owner.agent_definition_id, version.version_no, reference.key)
      await completeOperation(reference, result, stillCurrent)
    } catch (failure) { if (stillCurrent()) versionError.value = `${failure instanceof Error ? failure.message : '回退结果未确认'}；请沿原请求号只读找回，不再发送回退。` }
    finally { if (managementFlight.value === flight) managementFlight.value = null }
  }

  watch(() => props.environmentId, () => {
    ++epoch
    ++scopeEpoch
    operationRecoveringKey.value = ''
    operationRecoveryError.value = ''
    ++versionsEpoch
    versionsVisible.value = false
    versions.value = []
    versionOwner.value = null
    loading.value = false
    error.value = ''
    ++knowledgeEpoch
    ++modelEpoch
    rows.value = []
    total.value = 0
    models.value = []
    modelsLoading.value = false
    modelsError.value = ''
    knowledge.value = []
    knowledgePage = 0
    knowledgeMore.value = false
    knowledgeError.value = ''
    knowledgeLoading.value = false
    newDraft()
    void load(1)
    void loadModels()
    void loadKnowledge(true)
  }, { immediate: true, flush: 'sync' })
  function observeOperationJournal(event: StorageEvent): void {
    if (event.key === operationJournalKey || event.key === null) readOperationJournal()
  }
  watch([currentAdminId, () => userStore.accessToken], () => {
    ++identityEpoch; ++scopeEpoch; ++epoch; ++versionsEpoch; ++formEpoch; ++modelEpoch; ++knowledgeEpoch
    operationRecoveringKey.value = ''; operationRecoveryError.value = ''
    loading.value = false; editingLoading.value = false
    versionsVisible.value = false; versions.value = []; versionOwner.value = null; versionsLoading.value = false
    rows.value = []; total.value = 0; error.value = ''; versionError.value = ''
    models.value = []; modelsLoading.value = false; modelsError.value = ''
    knowledge.value = []; knowledgeLoading.value = false; knowledgeError.value = ''
    knowledgePage = 0; knowledgeMore.value = false
    newDraft()
    readOperationJournal()
    if (currentAdminId.value) {
      void load(1)
      void loadModels()
      void loadKnowledge(true)
    }
  }, { flush: 'sync' })
  readOperationJournal()
  window.addEventListener('storage', observeOperationJournal)
  onUnmounted(() => { window.removeEventListener('storage', observeOperationJournal); ++versionsEpoch; ++epoch; ++scopeEpoch; ++formEpoch; ++modelEpoch; ++knowledgeEpoch })
</script>

<style scoped lang="scss">
  .panel-head { display: flex; justify-content: space-between; align-items: center; gap: 12px; }
  .panel-head h3 { margin: 0; }
  .panel-head p, .hint { color: var(--el-text-color-secondary); font-size: 13px; }
  .hint { margin-left: 10px; }
  .guide, .preview { margin: 14px 0; }
  .panel-alert, .pager { margin: 14px 0; }
  .knowledge-actions { display: flex; gap: 8px; }
  .knowledge-note { margin: 2px 0 0; color: var(--el-text-color-secondary); font-size: 12px; }
</style>
