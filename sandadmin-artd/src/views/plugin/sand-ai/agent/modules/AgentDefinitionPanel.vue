<template>
  <ElCard shadow="never">
    <template #header>
      <div class="panel-head">
        <div>
          <h3>智能体</h3>
          <p>给 AI 助手起名字、选模型和知识库、写好回答要求，启用后接到渠道上用。</p>
        </div>
        <ElButton :disabled="!environmentId" :loading="loading" @click="load()">刷新</ElButton>
      </div>
    </template>
    <ElEmpty v-if="!environmentId" description="先在上方选择应用环境" />
    <template v-else>
      <ElAlert v-if="error" type="error" :title="error" show-icon class="panel-alert" @close="error = ''" />
      <ElAlert v-if="!operationLockAvailable || operationJournalInvalid" type="warning" :closable="false" class="notice"
        title="暂不能安全保存智能体变更" description="浏览器缺少跨标签互斥能力，或原变更记录无法保存、读取。暂不能发起新变更；已有原请求仍可只读找回。记录异常不会按未提交处理。" />
      <ElAlert v-if="otherRecoveryCount" type="warning" :closable="false" class="notice"
        title="其他环境或身份存在未确认变更" description="请切回原应用环境，使用原管理员身份查看原请求结果；页面刷新与身份切换不会删除原请求锁。" />
      <section v-if="currentRecoveries.length" class="notice" aria-label="智能体变更恢复">
        <ElAlert type="warning" :closable="false" title="上次变更的结果还没确认"
          description="保存、启用、停用和回退共用同一把变更锁。这里只读取原请求的受理回执；读不到或无权读取时仍保留锁，不会重新提交。当前配置以找回后重新读取的结果为准。" />
        <p v-for="item in currentRecoveries" :key="item.key">
          {{ item.code }} · {{ operationLabel(item.operation) }}{{ item.rollback_version ? ` · 第 ${item.rollback_version} 版` : '' }}
          <ElButton link type="primary" :loading="operationRecoveringKey === item.key" :disabled="writing || Boolean(operationRecoveringKey)" @click="recoverOperation(item)">查看结果</ElButton>
          <ElButton link type="warning" :disabled="writing || Boolean(operationRecoveringKey)" @click="abandonOperation(item)">放弃此次变更</ElButton>
        </p>
        <ElAlert v-if="operationRecoveryError" type="error" :closable="false" :title="operationRecoveryError" />
      </section>

      <div class="filter-row">
        <ElInput v-model="keyword" clearable placeholder="搜索智能体名称" style="width: 220px" aria-label="搜索智能体名称" />
        <ElSelect v-model="statusFilter" clearable placeholder="全部状态" style="width: 140px" aria-label="按状态筛选">
          <ElOption label="已启用" value="enabled" />
          <ElOption label="已停用" value="disabled" />
          <ElOption label="还没启用" value="draft" />
        </ElSelect>
      </div>

      <ElTable v-loading="loading" :data="pagedRows" row-key="agent_definition_id"
        :empty-text="rows.length === 0 ? '还没有智能体' : '没有找到符合条件的智能体'">
        <template #empty>
          <div v-if="rows.length === 0" class="empty-state">
            <p class="empty-title">还没有智能体</p>
            <p class="empty-desc">做一个 AI 助手，接到聊天渠道上让同事用</p>
            <ElButton type="primary" :disabled="!environmentId" @click="openCreate">新建智能体</ElButton>
          </div>
          <div v-else class="empty-state">
            <p class="empty-title">没有找到符合条件的智能体</p>
            <ElButton link type="primary" @click="clearFilters">清空筛选条件</ElButton>
          </div>
        </template>
        <ElTableColumn label="名称" prop="name" min-width="160" />
        <ElTableColumn label="接到哪些渠道" min-width="150">
          <template #default="{ row }">
            <span v-if="channelNamesOf(row.agent_definition_id)" class="channel-names">{{ channelNamesOf(row.agent_definition_id) }}</span>
            <span v-else class="channel-none">未接渠道</span>
          </template>
        </ElTableColumn>
        <ElTableColumn label="状态" width="110">
          <template #default="{ row }">
            <ElTag :type="agentStatus(row).type" size="small">{{ agentStatus(row).label }}</ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn label="操作" min-width="230">
          <template #default="{ row }">
            <ElButton link type="primary" :disabled="writing || editingLoading" @click="edit(row)">编辑</ElButton>
            <ElButton link type="primary" :disabled="!canTrial(row) || trialOpening" @click="openTrial(row)">试一试</ElButton>
            <ElDropdown trigger="click" :disabled="writing || Boolean(operationRecoveringKey)">
              <ElButton link type="primary">更多</ElButton>
              <template #dropdown>
                <ElDropdownMenu>
                  <ElDropdownItem v-if="row.draft_version_id" :disabled="operationBlocked(row.code)" @click="publish(row)">启用</ElDropdownItem>
                  <ElDropdownItem @click="openVersions(row)">版本与回退</ElDropdownItem>
                  <ElDropdownItem v-if="row.status === 1 && row.active_version_id" :disabled="operationBlocked(row.code)" divided @click="disable(row)">停用</ElDropdownItem>
                  <ElDropdownItem v-if="row.status !== 1" :disabled="channelCount(row) > 0" divided @click="retireAgent(row)">{{ channelCount(row) > 0 ? `删除（还有 ${channelCount(row)} 个渠道在用）` : '删除' }}</ElDropdownItem>
                  <ElDropdownItem v-else disabled divided>删除（先停用才能删）</ElDropdownItem>
                </ElDropdownMenu>
              </template>
            </ElDropdown>
          </template>
        </ElTableColumn>
      </ElTable>
      <ElPagination v-if="filteredTotal > pageSize" class="pager" background layout="prev, pager, next, total"
        :current-page="currentPage" :page-size="pageSize" :total="filteredTotal" @current-change="currentPage = $event" />

      <ElDrawer v-model="drawerVisible" :title="editingId ? '编辑智能体' : '新建智能体'"
        size="min(560px, 94vw)" destroy-on-close>
        <ElForm v-loading="editingLoading" :disabled="writing || editingLoading" label-position="top" @submit.prevent>
          <ElFormItem label="名称" required>
            <ElInput v-model="form.name" maxlength="20" placeholder="例如客服知识助手" />
            <div v-if="fieldErrors.name" class="field-error">{{ fieldErrors.name }}</div>
          </ElFormItem>
          <ElFormItem label="用哪个模型" required>
            <ElSelect v-model="form.model" filterable clearable allow-create default-first-option :loading="modelsLoading"
              placeholder="选择当前环境已启用的聊天模型" style="width: 100%" aria-label="用哪个模型"
              @change="modelTouched = true">
              <ElOption v-for="model in models" :key="model.code" :label="`${model.name} · ${model.code}`" :value="model.code" />
            </ElSelect>
            <div class="knowledge-actions">
              <ElButton link type="primary" :loading="modelsLoading" @click="loadModels">刷新模型</ElButton>
            </div>
            <div v-if="modelsNotPublished" class="knowledge-note knowledge-note--action">
              这个环境还没有启用的能力配置，请先去『能力配置』保存并启用。
              <ElButton link type="primary" @click="router.push('/sand-ai/capability')">去能力配置</ElButton>
            </div>
            <p v-else-if="modelsError" class="knowledge-note">{{ modelsError }}。草稿仍可手动填写模型代码；启用前需要当前环境有可用的模型。</p>
            <div v-if="fieldErrors.model" class="field-error">{{ fieldErrors.model }}</div>
          </ElFormItem>
          <ElFormItem label="参考哪个知识库">
            <ElSelect v-model="form.knowledge_base_code" filterable clearable allow-create default-first-option :loading="knowledgeLoading"
              placeholder="不选就只靠模型自己回答" style="width: 100%" aria-label="参考哪个知识库">
              <ElOption v-for="item in knowledge" :key="item.id" :value="item.code" :label="knowledgeLabel(item)" />
            </ElSelect>
            <div class="knowledge-actions">
              <ElButton link type="primary" :loading="knowledgeLoading" @click="loadKnowledge(true)">刷新知识库</ElButton>
              <ElButton v-if="knowledgeMore" link type="primary" :loading="knowledgeLoading" @click="loadKnowledge(false)">更多</ElButton>
            </div>
            <p v-if="knowledgeError" class="knowledge-note">{{ knowledgeError }}。有权确认别名时可手动输入。</p>
            <p v-else class="knowledge-note">不选也能用，只靠模型自己回答。</p>
          </ElFormItem>
          <ElFormItem label="它该怎么回答" required>
            <ElInput v-model="form.instructions" type="textarea" :rows="4" maxlength="2000" show-word-limit
              placeholder="用大白话说明它该怎么回答，比如依据什么资料、怎么称呼对方" />
            <div class="example-row">
              <span class="example-label">点一个试试：</span>
              <ElButton link type="primary" size="small" @click="usePreset('service')">客服问答</ElButton>
              <ElButton link type="primary" size="small" @click="usePreset('notice')">写通知</ElButton>
            </div>
            <div v-if="fieldErrors.instructions" class="field-error">{{ fieldErrors.instructions }}</div>
          </ElFormItem>
          <ElCollapse class="more-settings">
            <ElCollapseItem title="更多设置" name="more">
              <ElAlert v-if="unsupportedTools.length" type="warning" :closable="false" class="panel-alert"
                title="此草稿包含当前页面不支持的工具" :description="`已保留 ${unsupportedTools.join('、')}，请核对运行版本后再编辑。`" />
              <ElFormItem label="只读外部工具">
                <ReadonlyToolSelector v-model="form.readonly_tools" :environment-id="environmentId" :disabled="writing || editingLoading" />
              </ElFormItem>
              <ElFormItem label="人工复核">
                <ElSwitch v-model="form.review_required" :disabled="writing" />
                <span class="hint">打开后，回答要先经人工确认才能对外使用</span>
              </ElFormItem>
              <ElFormItem label="历史对话轮数">
                <ElSelect v-model="form.context_turn_limit" :disabled="writing" aria-label="历史对话轮数" style="width: 200px">
                  <ElOption v-for="turns in contextTurnOptions" :key="turns" :value="turns"
                    :label="turns === 0 ? '0 轮（不带历史）' : `最近 ${turns} 轮`" />
                </ElSelect>
                <p class="knowledge-note">已启用智能体和渠道回答最多参考最近 0–4 轮对话，默认 2 轮；0 表示不带历史。轮数随智能体版本固定，修改后需保存草稿并启用新版本。</p>
              </ElFormItem>
              <ElFormItem label="输出上限">
                <ElInputNumber v-model="form.max_output_tokens" :min="1" :max="form.readonly_tools.length ? 512 : 2048" :disabled="writing" />
                <span class="hint">{{ form.readonly_tools.length ? '本组合每轮最多 512；留空为 512' : '留空使用能力默认值' }}</span>
              </ElFormItem>
              <ElFormItem v-if="editingId" label="已接渠道">
                <span class="readonly-text">{{ channelNamesOf(editingId) || '还没有渠道接入' }}</span>
              </ElFormItem>
            </ElCollapseItem>
          </ElCollapse>
          <p class="combo-preview">{{ comboPreview }}</p>
          <div class="drawer-actions">
            <ElButton :disabled="writing" @click="drawerVisible = false">取消</ElButton>
            <ElButton v-if="editingEnabled" type="primary" :loading="writing" :disabled="!canSave" @click="saveAndEnable">保存</ElButton>
            <template v-else>
              <ElButton :loading="writing" :disabled="!canSave" @click="saveDraft">保存</ElButton>
              <ElButton type="primary" :loading="writing" :disabled="!canSave" @click="saveAndEnable">保存并启用</ElButton>
            </template>
          </div>
        </ElForm>
      </ElDrawer>

      <ElDialog v-model="versionsVisible" title="配置版本与回退" width="min(760px, 94vw)">
        <ElAlert type="info" :closable="false" title="回退配置版本"
          description="回退恢复此智能体当时的定义配置。模型代码与知识库别名仍按执行时当前可用模型及启用内容解析，不还原历史知识库正文。旧任务保留原实际快照；版本改变后，旧版本任务可能因当前版本限制无法读取。" />
        <ElAlert v-if="versionError" type="error" :title="versionError" show-icon />
        <ElTable v-loading="versionsLoading" :data="versions" row-key="version_no">
          <ElTableColumn label="版本" width="90"><template #default="{ row }">第 {{ row.version_no }} 版</template></ElTableColumn>
          <ElTableColumn label="状态" width="110"><template #default="{ row }">{{ row.is_active ? '当前启用' : row.state === 'published' ? '已启用历史' : '草稿' }}</template></ElTableColumn>
          <ElTableColumn label="模型 / 知识库" min-width="190"><template #default="{ row }">{{ row.config.model }} / {{ row.config.knowledge_base_code || '未绑定' }}</template></ElTableColumn>
          <ElTableColumn label="只读工具" min-width="180"><template #default="{ row }">{{ toolVersionsLabel(row.config.readonly_tools) }}</template></ElTableColumn>
          <ElTableColumn label="操作" width="130"><template #default="{ row }"><ElButton link type="warning" :disabled="writing || Boolean(operationRecoveringKey) || operationBlocked(versionOwner?.code ?? '') || row.is_active || row.state !== 'published' || versionOwner?.status !== 1" @click="rollback(row)">回退到此配置</ElButton></template></ElTableColumn>
          <ElTableColumn type="expand"><template #default="{ row }"><p class="tech-detail">技术详情：快照 {{ row.snapshot_sha256 }} · 创建 {{ row.created_at }}</p><pre class="tech-detail">{{ JSON.stringify(row.config, null, 2) }}</pre></template></ElTableColumn>
        </ElTable>
        <ElPagination v-if="versionsTotal > 20" :current-page="versionsPage" :page-size="20" :total="versionsTotal" layout="prev, pager, next" @current-change="loadVersions" />
      </ElDialog>

      <AgentTrialDialog v-model="trialVisible" :application-id="trialApplicationId" :agent-id="trialTarget?.agentId ?? 0"
        :agent-name="trialTarget?.name ?? ''" :knowledge-name="trialTarget?.knowledgeName" />
    </template>
  </ElCard>
</template>

<script setup lang="ts">
  import { useUserStore } from '@/store/modules/user'
  import { useAuth } from '@/hooks/core/useAuth'
  import { computed, onUnmounted, reactive, ref, watch } from 'vue'
  import { useRouter } from 'vue-router'
  import { ElMessage, ElMessageBox } from 'element-plus'
  import agentDefinitionApi, { type AgentDefinitionOperation, type AgentDefinitionOperationReceipt, type AgentDefinitionRejectedPublishReceipt, type AgentDraft, type AgentAvailableModel, type AgentContextTurnLimit, type AgentDefinitionRow, type AgentReadonlyToolSelection, type AgentVersionHistory } from '../../api/agentDefinition'
  import type { AgentVersionDetail } from '../../api/agentDefinition'
  import knowledgePublicationApi, { type ConsumerKnowledgePublication } from '../../api/knowledgePublication'
  import channelBindingApi from '../../api/channelBinding'
  import type { SandIamEnvironmentReference } from '../../api/iam'
  import ReadonlyToolSelector from './ReadonlyToolSelector.vue'
  import AgentTrialDialog from './AgentTrialDialog.vue'
  import { newRuntimeRequestId } from '../../api/runtime'
  import { errorDisplayText } from '../../api/errorText'
  import { isWriteRejected, writeRejectionText } from '../../api/writeRejection'
  import { confirmAbandonPendingWrite } from '../../composables/pendingWrite'

  const versionsVisible = ref(false)
  const versions = ref<AgentVersionHistory[]>([])
  const versionsLoading = ref(false)
  const versionsTotal = ref(0)
  const versionsPage = ref(1)
  const versionError = ref('')
  const versionOwner = ref<AgentDefinitionRow | null>(null)
  let versionsEpoch = 0
  const props = defineProps<{ environmentId: number | null; environment: SandIamEnvironmentReference | null }>()
  const router = useRouter()
  const userStore = useUserStore()
  const { hasAuth } = useAuth()
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
  /** 渠道绑定按 agent_definition_id 反查，用于「接到哪些渠道」列与抽屉展示。 */
  const channelRows = ref<{ agent_definition_id: number; name: string }[]>([])
  const models = ref<AgentAvailableModel[]>([])
  const modelsLoading = ref(false)
  const modelsError = ref('')
  // SAND_AI_CONFIGURATION_NOT_PUBLISHED：环境缺已发布能力配置，走专属引导而非错误文案直出
  const modelsNotPublished = ref(false)
  let modelEpoch = 0
  const knowledge = ref<ConsumerKnowledgePublication[]>([])
  const knowledgeLoading = ref(false)
  const knowledgeError = ref('')
  const knowledgeMore = ref(false)
  let knowledgePage = 0
  let knowledgeEpoch = 0
  const loading = ref(false)
  const managementFlight = ref<symbol | null>(null)
  const writing = computed(() => managementFlight.value !== null)
  const error = ref('')
  const editingId = ref<number | null>(null)
  const editingLoading = ref(false)
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
  /** 代码对管理员不可见：新建时按规则自动生成，编辑时保留原值。 */
  function generateAgentCode(): string {
    return `agent-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 8)}`
  }
  // ElSelect clearable 清空时把 v-model 写成 undefined；取值处一律先判 string 再 trim。
  const canSave = computed(() => !operationBlocked(form.code.trim()) && !operationRecoveringKey.value && !!props.environmentId
    && !!form.name.trim() && form.name.trim().length <= 20 && typeof form.model === 'string' && !!form.model.trim()
    && !!form.instructions.trim() && form.instructions.trim().length <= 2000
    && !writing.value && !editingLoading.value
    && unsupportedTools.value.length === 0 && form.tools.includes('retrieval.search')
    && form.readonly_tools.length <= 2 && form.readonly_tools.every(tool => /^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/.test(tool.business_scope))
    && (!form.readonly_tools.length || form.max_output_tokens === null || form.max_output_tokens <= 512))
  const submitAttempted = ref(false)
  /** 编辑中条目打开时是否已启用：已启用智能体抽屉只放「取消」「保存」，保存即直接生效。 */
  const editingEnabled = ref(false)
  /** 用户亲手改过模型下拉后不再自动预选，避免覆盖明确选择。 */
  const modelTouched = ref(false)
  // ElSelect clearable 清空时把 v-model 写成 undefined，拉回 '' 防止后续 .trim() 崩溃。
  watch(() => form.model as unknown, value => { if (value === undefined || value === null) form.model = '' })
  watch(() => form.knowledge_base_code as unknown, value => { if (value === undefined || value === null) form.knowledge_base_code = '' })
  /** 可用模型目录来自已发布聊天能力档，第一项即环境默认对话模型。 */
  const defaultModelCode = computed(() => models.value[0]?.code ?? '')
  const fieldErrors = computed(() => ({
    name: submitAttempted.value && !form.name.trim() ? '请填写名称' : '',
    instructions: submitAttempted.value && !form.instructions.trim() ? '请写几句话告诉它该怎么回答' : '',
    model: submitAttempted.value && !form.model.trim() ? '请选择用哪个模型' : ''
  }))
  const selectedModel = computed(() => models.value.find(item => item.code === form.model)?.name ?? (form.model || ''))
  const selectedKnowledge = computed(() => {
    const item = knowledge.value.find(entry => entry.code === form.knowledge_base_code)
    return item?.owner_name ?? form.knowledge_base_code ?? ''
  })
  /** 一行灰字说明组合顺序；未选模型/知识库时给出当前状态。 */
  const comboPreview = computed(() => {
    const modelName = selectedModel.value || '所选模型'
    if (!(typeof form.model === 'string' && form.model.trim())) return '先选「用哪个模型」，这里会说明回答怎么组合'
    if (!form.knowledge_base_code) return `会直接用「${modelName}」回答，不查知识库`
    return `会先查「${selectedKnowledge.value || '所选知识库'}」，再用「${modelName}」回答`
  })
  function knowledgeLabel(item: ConsumerKnowledgePublication): string {
    return `${item.owner_name} · ${item.code}`
  }
  function knowledgeNameOf(code: string | null | undefined): string | undefined {
    if (!code) return undefined
    return knowledge.value.find(item => item.code === code)?.owner_name
  }

  // 列表由后端按环境返回全量，筛选与分页在客户端完成
  const keyword = ref('')
  const statusFilter = ref<string>('')
  const currentPage = ref(1)
  const pageSize = 20
  function agentEnabled(row: AgentDefinitionRow): boolean {
    return row.state === 'published' && row.status === 1 && row.active_version_id !== null && row.active_version_no !== null
  }
  function agentStatus(row: AgentDefinitionRow): { label: string; type: 'success' | 'info' | 'warning' } {
    if (row.status === 2) return { label: '已停用', type: 'info' }
    if (agentEnabled(row)) return { label: '已启用', type: 'success' }
    return { label: '还没启用', type: 'warning' }
  }
  const filteredRows = computed(() => {
    const text = keyword.value.trim()
    return rows.value.filter(row => {
      if (text && !row.name.includes(text)) return false
      if (statusFilter.value === 'enabled' && !agentEnabled(row)) return false
      if (statusFilter.value === 'disabled' && row.status !== 2) return false
      if (statusFilter.value === 'draft' && !(row.status === 1 && !agentEnabled(row))) return false
      return true
    })
  })
  const filteredTotal = computed(() => filteredRows.value.length)
  const pagedRows = computed(() => {
    const start = (currentPage.value - 1) * pageSize
    return filteredRows.value.slice(start, start + pageSize)
  })
  function clearFilters(): void {
    keyword.value = ''
    statusFilter.value = ''
    currentPage.value = 1
  }
  watch([keyword, statusFilter], () => { currentPage.value = 1 })
  watch(filteredTotal, total => { if (currentPage.value > Math.max(1, Math.ceil(total / pageSize))) currentPage.value = Math.max(1, Math.ceil(total / pageSize)) })

  function channelNamesOf(agentDefinitionId: number): string {
    return channelRows.value.filter(item => item.agent_definition_id === agentDefinitionId).map(item => item.name).join('、')
  }

  const trialVisible = ref(false)
  const trialTarget = ref<{ agentId: number; name: string; knowledgeName?: string } | null>(null)
  const trialOpening = ref(false)
  const trialApplicationId = computed(() => props.environment?.application_id ?? 0)
  function canTrial(row: AgentDefinitionRow): boolean {
    return agentEnabled(row) && hasAuth('sand_ai:trial:agent')
  }
  async function openTrial(row: AgentDefinitionRow): Promise<void> {
    if (!canTrial(row) || !props.environmentId || trialOpening.value) return
    trialOpening.value = true
    try {
      // 知识库名用于试用区顶部说明；读不到详情时仍可打开，说明退回通用文案。
      let knowledgeName: string | undefined
      try {
        const detail = await agentDefinitionApi.read(props.environmentId, row.code)
        knowledgeName = knowledgeNameOf(detail.latest_version?.knowledge_base_code ?? null)
      } catch { /* 详情读取失败不阻断试用 */ }
      trialTarget.value = { agentId: row.agent_definition_id, name: row.name, knowledgeName }
      trialVisible.value = true
    } finally {
      trialOpening.value = false
    }
  }

  const drawerVisible = ref(false)
  function openCreate(): void {
    if (!props.environmentId || writing.value) return
    newDraft()
    drawerVisible.value = true
  }
  const pendingEnableCode = ref('')
  /** 渠道页「去启用」入口：已有草稿走启用确认，否则打开抽屉让用户先保存草稿。 */
  function requestEnable(code: string): void {
    pendingEnableCode.value = code
    void tryEnablePending()
  }
  async function tryEnablePending(): Promise<void> {
    const code = pendingEnableCode.value
    if (!code || !props.environmentId || rows.value.length === 0) return
    const row = rows.value.find(item => item.code === code)
    if (!row) { pendingEnableCode.value = ''; return }
    pendingEnableCode.value = ''
    if (row.draft_version_id) {
      await publish(row)
    } else {
      await edit(row)
      drawerVisible.value = true
      ElMessage.info('请先在抽屉里保存草稿，再从「更多」里启用')
    }
  }
  watch(rows, () => { void tryEnablePending() })

  async function usePreset(preset: 'service' | 'notice'): Promise<void> {
    if (writing.value || editingLoading.value) return
    const current = scopeEpoch
    const currentForm = formEpoch
    if (form.instructions.trim()) {
      try { await ElMessageBox.confirm('此操作会替换当前草稿中的回答要求；其他设置保留。', '使用回答要求示例') }
      catch { return }
    }
    if (current !== scopeEpoch || currentForm !== formEpoch || writing.value || editingLoading.value) return
    form.instructions = preset === 'service'
      ? '你是客服助手。先查知识库里和客户问题相关的资料，再用简洁、礼貌的中文回答；资料里没有的内容不要编，礼貌说明需要人工确认。'
      : '你是内部通知助手。根据给出的要点写一条简短通知，说明时间、事项和需要大家做什么；语气正式清楚，不添加资料里没有的信息。'
  }

  function newDraft(): void {
    ++formEpoch
    editingLoading.value = false
    editingId.value = null
    editingEnabled.value = false
    submitAttempted.value = false
    modelTouched.value = false
    Object.assign(form, { code: generateAgentCode(), name: '', model: '', knowledge_base_code: '', instructions: '', readonly_tools: [], tools: ['retrieval.search'], review_required: true, max_output_tokens: null, context_turn_limit: 2 })
    applyDefaultModel()
  }
  /** 新建草稿时预选环境默认模型；编辑中、已手选或目录未就绪时不覆盖。 */
  function applyDefaultModel(): void {
    const currentModel = typeof form.model === 'string' ? form.model : ''
    if (modelTouched.value || editingId.value !== null || currentModel.trim() || !defaultModelCode.value) return
    form.model = defaultModelCode.value
  }
  async function loadModels(): Promise<void> {
    const environmentId = props.environmentId
    if (!environmentId || !currentAdminId.value) return
    const current = ++modelEpoch
    modelsLoading.value = true
    modelsError.value = ''
    modelsNotPublished.value = false
    try {
      const result = await agentDefinitionApi.models(environmentId)
      if (current !== modelEpoch || props.environmentId !== environmentId) return
      models.value = result.data.filter(item => item.type === 'chat' && item.capabilities.includes('chat'))
      applyDefaultModel()
    } catch (failure) {
      if (current === modelEpoch) {
        models.value = []
        const rawMessage = failure instanceof Error ? failure.message : ''
        if (/\bSAND_AI_CONFIGURATION_NOT_PUBLISHED\b/.test(rawMessage)) {
          // 错误码与英文原因不上屏；原始信息只进 console 供排查
          modelsNotPublished.value = true
          console.error('[sand-ai] agent models unavailable: configuration not published', failure)
        } else {
          modelsError.value = errorDisplayText(failure, '当前环境模型读取失败')
        }
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
        knowledgeError.value = errorDisplayText(failure, '可用知识库读取失败')
      }
    } finally {
      if (current === knowledgeEpoch) knowledgeLoading.value = false
    }
  }
  async function loadChannels(): Promise<void> {
    const environmentId = props.environmentId
    if (!environmentId) return
    try {
      const result = await channelBindingApi.list(environmentId, 1, 100)
      channelRows.value = result.data.map(item => ({ agent_definition_id: item.agent_definition_id, name: item.name }))
    } catch {
      // 渠道名只是辅助展示，读取失败不阻断智能体列表。
      channelRows.value = []
    }
  }
  /** 后端按环境返回全量分页；这里循环取完，筛选与分页都在前端完成。 */
  async function load(): Promise<void> {
    const environmentId = props.environmentId
    if (!environmentId || !currentAdminId.value) return
    const current = ++epoch
    loading.value = true
    error.value = ''
    try {
      const collected: AgentDefinitionRow[] = []
      for (let targetPage = 1; ; targetPage++) {
        const result = await agentDefinitionApi.list(environmentId, targetPage, 100)
        if (current !== epoch) return
        collected.push(...result.data)
        if (targetPage * 100 >= result.total || result.data.length === 0) break
      }
      rows.value = collected
      currentPage.value = 1
    } catch (failure) {
      if (current === epoch) error.value = errorDisplayText(failure, '智能体列表读取失败')
    } finally {
      if (current === epoch) loading.value = false
    }
    void loadChannels()
  }
  /** 在版本历史里找当前启用版本；逐页向后找，找不到返回 null。 */
  async function findActiveVersion(environmentId: number, code: string): Promise<AgentVersionHistory | null> {
    for (let page = 1; page <= 20; page++) {
      const result = await agentDefinitionApi.versions(environmentId, code, page)
      const active = result.data.find(version => version.is_active)
      if (active) return active
      if (page * 20 >= result.total || result.data.length === 0) return null
    }
    return null
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
      // 表单按当前启用版本装配，与生效配置一致；没有启用版本时才用最新版本（含未启用草稿）。
      let source: Omit<AgentVersionDetail, 'id' | 'version' | 'state'> | null = detail.latest_version
      if (detail.active_version_id !== null) {
        const active = await findActiveVersion(environmentId, row.code)
        if (current !== formEpoch || props.environmentId !== environmentId) return
        if (active) source = active.config
      }
      editingId.value = detail.agent_definition_id
      editingEnabled.value = agentEnabled(row)
      submitAttempted.value = false
      modelTouched.value = false
      Object.assign(form, {
        code: detail.code, name: detail.name,
        model: source?.model ?? '',
        knowledge_base_code: source?.knowledge_base_code ?? '',
        instructions: source?.instructions ?? '',
        readonly_tools: (source?.readonly_tools ?? []).map(tool => ({ ...tool })),
        tools: [...(source?.tools ?? ['retrieval.search'])],
        review_required: source?.review_required ?? true,
        max_output_tokens: source?.max_output_tokens ?? null,
        context_turn_limit: source?.context_turn_limit ?? 2
      })
      drawerVisible.value = true
    } catch (failure) {
      if (current === formEpoch) error.value = errorDisplayText(failure, '智能体详情读取失败')
    } finally { if (current === formEpoch) editingLoading.value = false }
  }
  function operationLabel(operation: AgentDefinitionOperation): string {
    return { draft: '保存草稿', publish: '启用', disable: '停用', rollback: '回退配置' }[operation]
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
      operationRecoveryError.value = '无法保存原变更记录，暂不能发起新变更。请保留本页并核对原智能体。'
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
        error.value = '此智能体仍有未确认变更，请先查看原请求结果，不能改用另一操作重新提交。'
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
    await load()
    if (!stillCurrent()) return
    // Read actual current configuration; the receipt is only an operation snapshot.
    const detail = await agentDefinitionApi.read(reference.environment_id, reference.code)
    if (!stillCurrent()) return
    if (detail.code !== reference.code) throw new Error('重新读取的智能体不一致，请刷新配置')
    if (form.code.trim() === reference.code && drawerVisible.value) {
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
        if (!rejectedPublishMatches(result, reference)) throw new Error('启用拒绝回执与原智能体或已确认快照不一致，仍保留原请求锁')
        if (!await acknowledgeOperation(reference, stillCurrent) || !stillCurrent()) return
        error.value = '原草稿在确认后发生变化，本次没有启用。请重新读取当前配置，并确认新的草稿版本后再启用。'
        ElMessage.warning('原启用已明确拒绝，未启用；请重新确认当前草稿')
        await readBackOperation(reference, stillCurrent)
        if (stillCurrent()) error.value = '原草稿在确认后发生变化，本次没有启用。当前配置已重新读取，请重新确认草稿版本后再启用。'
        return
      }
      if (result.idempotent_replay !== true || !receiptMatches(result, reference)) throw new Error('原操作回执与原智能体、版本或启用快照不一致，仍保留原请求锁')
      if (!await acknowledgeOperation(reference, stillCurrent) || !stillCurrent()) return
      ElMessage.success('已找回原变更回执，当前配置正在重新读取')
      await readBackOperation(reference, stillCurrent)
    } catch (failure) {
      if (stillCurrent()) {
        const message = errorDisplayText(failure, '原变更回执未找回')
        if (operationJournal.value.some(item => item.key === reference.key && item.code === reference.code))
          operationRecoveryError.value = `${message}；请核对原请求，未确认时不能再次变更。`
        else error.value = `原变更回执已确认，但当前配置未能完整重读：${message}。请刷新并重新读取配置后再确认变更。`
      }
    } finally { if (stillCurrent()) operationRecoveringKey.value = '' }
  }
  /** 「放弃此次变更」：用户确认后清掉本地变更记录解锁；记录损坏读不出时按 key 强制移除。 */
  function abandonOperation(reference: OperationReference): void {
    confirmAbandonPendingWrite(async () => {
      const cleared = await acknowledgeOperation(reference, () => originalScope(reference))
      if (cleared) {
        operationRecoveryError.value = ''
        return
      }
      try {
        const raw: unknown = JSON.parse(localStorage.getItem(operationJournalKey) || '[]')
        const entries = Array.isArray(raw)
          ? raw.filter(item => !(item !== null && typeof item === 'object' && !Array.isArray(item)
              && (item as { key?: unknown }).key === reference.key))
          : []
        localStorage.setItem(operationJournalKey, JSON.stringify(entries))
      } catch {
        localStorage.removeItem(operationJournalKey)
      }
      readOperationJournal()
    })
  }
  /** 写操作被明确拒绝：结清本地变更记录并给白话原因；记录解除失败时引导「放弃此次变更」。 */
  async function settleRejectedOperation(reference: OperationReference, failure: unknown, stillCurrent: () => boolean,
    rejectedFallback: string): Promise<void> {
    const cleared = await acknowledgeOperation(reference, stillCurrent)
    error.value = cleared
      ? `${writeRejectionText(failure, rejectedFallback)}`
      : `${writeRejectionText(failure, rejectedFallback)}原操作记录没能解除，请点「放弃此次变更」解锁后再继续。`
  }
  async function completeOperation(reference: OperationReference, result: AgentDefinitionOperationReceipt, stillCurrent: () => boolean, quiet = false): Promise<void> {
    if (!stillCurrent()) return
    if (!receiptMatches(result, reference)) throw new Error('操作回执与原智能体、版本或启用快照不一致，请查看原请求结果')
    if (!await acknowledgeOperation(reference, stillCurrent) || !stillCurrent()) return
    if (!quiet) ElMessage.success(`${operationLabel(reference.operation)}已确认，当前配置正在重新读取`)
    await readBackOperation(reference, stillCurrent)
  }
  /** 保存草稿；返回是否已确认完成，供「保存并启用」继续启用。 */
  async function saveDraft(): Promise<boolean> {
    const environmentId = props.environmentId
    submitAttempted.value = true
    if (!environmentId || !canSave.value || writing.value) return false
    const current = scopeEpoch
    const identity = identityEpoch
    const stillCurrent = () => current === scopeEpoch && identity === identityEpoch && props.environmentId === environmentId
    const draft: AgentDraft = { environment_id: environmentId, code: form.code.trim(), name: form.name.trim(),
      model: typeof form.model === 'string' ? form.model.trim() : '',
      instructions: form.instructions.trim(), tools: [...form.tools], review_required: form.review_required,
      max_output_tokens: form.max_output_tokens,
      knowledge_base_code: (typeof form.knowledge_base_code === 'string' ? form.knowledge_base_code.trim() : '') || null,
      context_turn_limit: form.context_turn_limit, readonly_tools: form.readonly_tools.map(tool => ({ ...tool })) }
    const flight = Symbol('agent-management')
    managementFlight.value = flight
    error.value = ''
    let reference: OperationReference | null = null
    let dispatched = false
    let confirmed = false
    try {
      reference = await startOperation('draft', draft.code, editingId.value, null, null, stillCurrent)
      if (!reference || !stillCurrent()) return false
      dispatched = true
      const result = await agentDefinitionApi.draft(draft, reference.key)
      await completeOperation(reference, result, stillCurrent, true)
      confirmed = stillCurrent()
    } catch (failure) {
      if (stillCurrent()) {
        if (dispatched && reference && isWriteRejected(failure)) await settleRejectedOperation(reference, failure, stillCurrent, '这次保存被拒绝了。')
        else error.value = `${errorDisplayText(failure, '保存结果未确认')}；请查看原请求结果，不要重新提交。`
      }
    } finally { if (managementFlight.value === flight) managementFlight.value = null }
    return confirmed
  }
  /** 「保存」按钮（未启用智能体）：只存草稿，成功后提示并关闭抽屉。 */
  async function saveDraftOnly(): Promise<void> {
    if (await saveDraft()) {
      ElMessage.success(`已保存「${form.name.trim()}」`)
      drawerVisible.value = false
    }
  }
  /** 保存草稿 → 启用最新草稿一步完成；不选知识库也可以启用，运行时只靠模型回答。 */
  async function saveAndEnable(): Promise<void> {
    if (writing.value) return
    const name = form.name.trim()
    const code = form.code.trim()
    const saved = await saveDraft()
    if (!saved) return
    const row = rows.value.find(item => item.code === code)
    if (!row) {
      error.value = '已保存，但暂时读不到这个智能体，请刷新后再试。'
      return
    }
    if (await publish(row, true)) {
      ElMessage.success(editingEnabled.value ? `已保存「${name}」` : `已保存并启用「${name}」`)
      drawerVisible.value = false
    }
  }
  /** 启用；返回是否已确认完成。skipConfirm 用于「保存并启用」一步流程，不再弹确认框。 */
  async function publish(row: AgentDefinitionRow, skipConfirm = false): Promise<boolean> {
    const environmentId = props.environmentId
    if (!environmentId || writing.value || operationRecoveringKey.value || operationBlocked(row.code)) return false
    const current = scopeEpoch
    const identity = identityEpoch
    const stillCurrent = () => current === scopeEpoch && identity === identityEpoch && props.environmentId === environmentId
    const flight = Symbol('agent-management')
    managementFlight.value = flight
    error.value = ''
    let reference: OperationReference | null = null
    let dispatched = false
    let confirmed = false
    try {
      const history = await agentDefinitionApi.versions(environmentId, row.code, 1)
      if (!stillCurrent()) return false
      const drafts = history.data.filter(version => version.state === 'draft')
      const draft = drafts[0]
      if (drafts.length !== 1 || !draft || draft.version_no !== row.draft_version_no || !/^[a-f0-9]{64}$/.test(draft.snapshot_sha256)) {
        error.value = '未能确认唯一的当前草稿或启用快照，请刷新列表、重新读取配置后再启用。'
        return false
      }
      const expectedSha = draft.snapshot_sha256
      const modelName = draft.config.model
      const knowledgeName = draft.config.knowledge_base_code ? knowledgeNameOf(draft.config.knowledge_base_code) ?? draft.config.knowledge_base_code : '未绑定知识库'
      if (!skipConfirm) {
        try { await ElMessageBox.confirm(`启用「${row.name}」第 ${draft.version_no} 版草稿（模型 ${modelName}，知识库 ${knowledgeName}）。应用和渠道将使用此配置，旧版本结果将不可用。确认期间草稿变化会拒绝启用，请刷新并重新确认。`, '启用已确认的智能体草稿', { type: 'warning', confirmButtonText: '启用此版草稿' }) }
        catch { return false }
      }
      if (!stillCurrent()) return false
      reference = await startOperation('publish', row.code, row.agent_definition_id, null, expectedSha, stillCurrent)
      if (!reference || !stillCurrent()) return false
      dispatched = true
      const result = await agentDefinitionApi.publish(environmentId, row.agent_definition_id, reference.key, expectedSha)
      await completeOperation(reference, result, stillCurrent, skipConfirm)
      confirmed = stillCurrent()
    } catch (failure) {
      if (stillCurrent()) {
        if (dispatched && reference && isWriteRejected(failure)) await settleRejectedOperation(reference, failure, stillCurrent, '这次启用被拒绝了。')
        else error.value = dispatched
          ? `${errorDisplayText(failure, '启用结果未确认')}；请查看原请求结果。若草稿冲突，请重新读取当前配置并确认，不能自动改版启用。`
          : `${errorDisplayText(failure, '草稿读取失败')}；本次未提交启用，请刷新列表、重新读取配置后确认。`
      }
    } finally { if (managementFlight.value === flight) managementFlight.value = null }
    return confirmed
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
    let reference: OperationReference | null = null
    let dispatched = false
    try {
      const boundCount = channelRows.value.filter(item => item.agent_definition_id === row.agent_definition_id).length
      try { await ElMessageBox.confirm(`停用「${row.name}」后${boundCount > 0 ? `接到的 ${boundCount} 个渠道里的人问了不会再回答` : '新任务和旧版本结果都将不可用'}，随时可以再启用。`, '停用智能体', { type: 'warning', confirmButtonText: '停用' }) }
      catch { return }
      if (!stillCurrent()) return
      reference = await startOperation('disable', row.code, row.agent_definition_id, null, null, stillCurrent)
      if (!reference || !stillCurrent()) return
      dispatched = true
      const result = await agentDefinitionApi.disable(environmentId, row.agent_definition_id, reference.key)
      await completeOperation(reference, result, stillCurrent)
    } catch (failure) {
      if (stillCurrent()) {
        if (dispatched && reference && isWriteRejected(failure)) await settleRejectedOperation(reference, failure, stillCurrent, '这次停用被拒绝了。')
        else error.value = `${errorDisplayText(failure, '停用结果未确认')}；请查看原请求结果，不要重新提交。`
      }
    } finally { if (managementFlight.value === flight) managementFlight.value = null }
  }
  function channelCount(row: AgentDefinitionRow): number {
    return channelRows.value.filter(item => item.agent_definition_id === row.agent_definition_id).length
  }
  async function retireAgent(row: AgentDefinitionRow): Promise<void> {
    const environmentId = props.environmentId
    if (!environmentId || writing.value || row.status === 1 || channelCount(row) > 0) return
    try {
      await ElMessageBox.confirm(`删除后「${row.name}」不能恢复。`, `删除「${row.name}」？`, { type: 'warning', confirmButtonText: '删除', cancelButtonText: '再想想' })
    } catch { return }
    const flight = Symbol('agent-management')
    managementFlight.value = flight
    error.value = ''
    try {
      const result = await agentDefinitionApi.retire(environmentId, row.agent_definition_id)
      if (result.state !== 'deleted' || result.agent_definition_id !== row.agent_definition_id) {
        error.value = '删除响应与这条智能体不一致，请刷新后再看'
        return
      }
      ElMessage.success(`已删除「${row.name}」`)
      await load()
    } catch (failure) {
      error.value = errorDisplayText(failure, '删除没有成功，请稍后再试。')
    } finally { if (managementFlight.value === flight) managementFlight.value = null }
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
      if (current === versionsEpoch) { versions.value = []; versionError.value = errorDisplayText(failure, '版本读取失败') }
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
    let reference: OperationReference | null = null
    let dispatched = false
    try {
      try { await ElMessageBox.confirm(`将「${owner.name}」恢复为第 ${version.version_no} 版定义配置；知识库别名仍使用当前启用内容。`, '回退配置版本', { type: 'warning' }) }
      catch { return }
      if (!stillCurrent()) return
      reference = await startOperation('rollback', owner.code, owner.agent_definition_id, version.version_no, null, stillCurrent)
      if (!reference || !stillCurrent()) return
      dispatched = true
      const result = await agentDefinitionApi.rollback(environment, owner.agent_definition_id, version.version_no, reference.key)
      await completeOperation(reference, result, stillCurrent)
    } catch (failure) {
      if (stillCurrent()) {
        if (dispatched && reference && isWriteRejected(failure)) {
          const cleared = await acknowledgeOperation(reference, stillCurrent)
          versionError.value = cleared
            ? writeRejectionText(failure, '这次回退被拒绝了。')
            : `${writeRejectionText(failure, '这次回退被拒绝了。')}原操作记录没能解除，请点「放弃此次变更」解锁后再继续。`
        } else {
          versionError.value = `${errorDisplayText(failure, '回退结果未确认')}；请查看原请求结果，不再发送回退。`
        }
      }
    } finally { if (managementFlight.value === flight) managementFlight.value = null }
  }
  defineExpose({ openCreate, requestEnable })

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
    channelRows.value = []
    models.value = []
    modelsLoading.value = false
    modelsError.value = ''
    modelsNotPublished.value = false
    knowledge.value = []
    knowledgePage = 0
    knowledgeMore.value = false
    knowledgeError.value = ''
    knowledgeLoading.value = false
    keyword.value = ''
    statusFilter.value = ''
    currentPage.value = 1
    drawerVisible.value = false
    trialVisible.value = false
    trialTarget.value = null
    pendingEnableCode.value = ''
    newDraft()
    void load()
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
    rows.value = []; channelRows.value = []; models.value = []; modelsLoading.value = false; modelsError.value = ''; modelsNotPublished.value = false
    knowledge.value = []; knowledgeLoading.value = false; knowledgeError.value = ''
    knowledgePage = 0; knowledgeMore.value = false
    drawerVisible.value = false; trialVisible.value = false; trialTarget.value = null; pendingEnableCode.value = ''
    newDraft()
    readOperationJournal()
    if (currentAdminId.value && props.environmentId) {
      void load()
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
  .panel-head p, .hint, .knowledge-note, .readonly-text { color: var(--el-text-color-secondary); font-size: 13px; }
  .hint { margin-left: 10px; }
  .filter-row { display: flex; gap: 10px; margin: 12px 0; }
  .panel-alert, .pager, .notice { margin: 14px 0; }
  .knowledge-actions { display: flex; gap: 8px; }
  .knowledge-note { margin: 2px 0 0; }
  .knowledge-note--action { color: var(--el-color-primary); }
  .field-error { color: var(--el-color-danger); font-size: 12px; margin-top: 4px; }
  .example-row { display: flex; align-items: center; gap: 8px; margin-top: 6px; }
  .example-label { color: var(--el-text-color-secondary); font-size: 12px; }
  .more-settings { margin: 8px 0; }
  .combo-preview { margin: 14px 0 4px; color: var(--el-text-color-secondary); font-size: 12px; }
  .drawer-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 18px; }
  .empty-state { padding: 28px 0; text-align: center; }
  .empty-title { margin: 0 0 6px; font-size: 15px; color: var(--el-text-color-primary); }
  .empty-desc { margin: 0 0 14px; color: var(--el-text-color-secondary); font-size: 13px; }
  .channel-names { color: var(--el-text-color-regular); }
  .channel-none { color: var(--el-text-color-secondary); }
  .tech-detail { color: var(--el-text-color-secondary); font-size: 12px; white-space: pre-wrap; overflow-wrap: anywhere; }
</style>
