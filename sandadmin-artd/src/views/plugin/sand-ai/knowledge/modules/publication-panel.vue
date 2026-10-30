<template>
  <ElCard class="publication-card" shadow="never">
    <template #header>
      <div class="panel-header">
        <div>
          <h2>知识库共享</h2>
          <p>将已发布的知识库开放给指定应用环境。应用还需在 SandIAM 获得知识库查询权限。</p>
        </div>
        <ElButton :loading="environmentLoading" @click="loadEnvironments(true)">刷新环境</ElButton>
      </div>
    </template>

    <ElAlert
      v-if="environmentError"
      type="error"
      show-icon
      :closable="false"
      title="无法读取 SandIAM 应用环境"
      description="请检查 SandIAM 环境列表权限，或稍后重试。"
      class="panel-alert"
    />
    <ElAlert v-if="journalBlocked" type="warning" :closable="false" class="panel-alert"
      title="原共享操作记录无法确认" description="暂不能创建新的开放或撤销请求。请保留当前页面和原记录，恢复浏览器会话存储后读取原操作回执；记录异常不能视为未提交。" />
    <ElForm v-if="pendingOperation" label-width="110px" @submit.prevent>
      <ElFormItem label="原操作">{{ pendingOperation.operation === 'publish' ? '开放知识库' : '撤销共享' }}</ElFormItem>
      <ElFormItem label="原请求号"><ElInput :model-value="pendingOperation.key" readonly /></ElFormItem>
      <ElFormItem label="固定目标">
        <span v-if="pendingOperation.operation === 'publish'">所属环境 #{{ pendingOperation.target.owner_environment_id }} · 知识库 #{{ pendingOperation.target.knowledge_base_id }} · 消费环境 #{{ pendingOperation.target.consumer_environment_id }} · 查询名称 {{ pendingOperation.target.code }}</span>
        <span v-else>所属环境 #{{ pendingOperation.target.owner_environment_id }} · 开放关系 #{{ pendingOperation.target.publication_id }}</span>
      </ElFormItem>
      <ElFormItem>
        <ElButton :loading="operationReading" :disabled="writing || operationReading" @click="recoverOperation">读取原操作回执</ElButton>
        <ElButton v-if="canSendUnsubmittedOperation" :disabled="writing || operationReading" @click="sendUnsubmittedOperation">保留原号首次提交</ElButton>
        <span class="form-hint">只读核对原管理员、环境、目标和请求号的操作回执；未找到或无法核实仍保留原号，不重新发送。</span>
      </ElFormItem>
    </ElForm>
    <ElAlert v-if="operationNotice" type="warning" :closable="false" :title="operationNotice" class="panel-alert" />
    <ElForm label-width="110px" @submit.prevent>
      <ElFormItem label="资料所属环境">
        <ElSelect v-model="ownerEnvironmentId" filterable clearable :disabled="writing" placeholder="选择知识库所属环境">
          <ElOption
            v-for="environment in environments"
            :key="environment.id"
            :label="environmentLabel(environment)"
            :value="environment.id"
          />
        </ElSelect>
      </ElFormItem>
      <ElFormItem v-if="hasMoreEnvironments">
        <ElButton text :loading="environmentLoading" @click="loadEnvironments()">加载更多环境</ElButton>
      </ElFormItem>
      <ElFormItem label="知识库">
        <ElSelect
          v-model="knowledgeBaseId"
          filterable
          clearable
          :loading="baseLoading"
          :disabled="typeof ownerEnvironmentId !== 'number' || writing"
          placeholder="选择已发布且来源已核实的知识库"
        >
          <ElOption
            v-for="base in publishableBases"
            :key="base.id"
            :label="`${base.name} · ${base.code} · 第 ${base.version_no} 版`"
            :value="base.id"
          />
        </ElSelect>
      </ElFormItem>
      <ElFormItem v-if="hasMoreBases">
        <ElButton text :loading="baseLoading" @click="loadBases()">加载更多知识库</ElButton>
      </ElFormItem>
      <ElFormItem label="开放给">
        <ElSelect
          v-model="consumerEnvironmentId"
          filterable
          clearable
          :disabled="writing"
          placeholder="选择消费应用环境"
        >
          <ElOption
            v-for="environment in environments"
            :key="environment.id"
            :label="environmentLabel(environment)"
            :value="environment.id"
          />
        </ElSelect>
      </ElFormItem>
      <ElFormItem label="查询名称">
        <ElInput
          v-model="aliasCode"
          maxlength="64"
          show-word-limit
          :disabled="writing"
          placeholder="例如 legal_reference"
        />
        <span class="form-hint">同一消费环境内唯一；发布后不能改指向其他知识库。</span>
      </ElFormItem>
      <ElFormItem>
        <ElButton type="primary" :loading="writing" :disabled="!canPublish" @click="publish">
          开放知识库
        </ElButton>
        <span class="form-hint">这一步开放资料范围；服务调用权限由 SandIAM 管理。</span>
      </ElFormItem>
    </ElForm>

    <div class="list-header">
      <div>
        <h3>检索试用</h3>
        <p class="form-hint">直接检查这个已发布版本的来源摘录；应用正式调用仍需 SandIAM 授权。</p>
      </div>
    </div>
    <ElForm label-width="110px" @submit.prevent>
      <ElFormItem label="试用查询">
        <ElInput v-model="trialQuery" :disabled="!selectedBase" maxlength="500"
          placeholder="输入要查找的内容" style="max-width: 500px" @keyup.enter="runTrial" />
        <ElButton type="primary" :loading="trialLoading"
          :disabled="!selectedBase || !trialQuery.trim()" @click="runTrial">检索试用</ElButton>
      </ElFormItem>
    </ElForm>
    <ElAlert v-if="trialError" type="error" :title="trialError" :closable="false" class="panel-alert" />
    <ElTable v-if="trialResult" :data="trialResult.matches" row-key="source_block_id"
      :empty-text="trialResult.matches.length === 0 ? '没有命中来源块' : ''">
      <ElTableColumn label="文件 ID" prop="file_id" width="110" />
      <ElTableColumn label="定位" min-width="180">
        <template #default="{ row }">{{ row.locator_type }} · {{ JSON.stringify(row.locator) }}</template>
      </ElTableColumn>
      <ElTableColumn label="来源摘录" prop="excerpt" min-width="360" />
    </ElTable>

    <ElAlert
      v-if="panelError"
      type="error"
      show-icon
      closable
      :title="panelError.title"
      :description="panelError.description"
      class="panel-alert"
      @close="panelError = null"
    />

    <div class="list-header">
      <h3>当前开放关系</h3>
      <ElButton :disabled="typeof knowledgeBaseId !== 'number'" :loading="publicationLoading" @click="loadPublications(1)">
        刷新
      </ElButton>
    </div>
    <ElTable v-loading="publicationLoading" :data="publications" row-key="id" empty-text="选择知识库后查看开放关系">
      <ElTableColumn label="查询名称" prop="code" min-width="160" />
      <ElTableColumn label="消费环境" min-width="220">
        <template #default="{ row }">{{ environmentName(row.consumer_environment_id) }}</template>
      </ElTableColumn>
      <ElTableColumn label="状态" width="110">
        <template #default="{ row }">
          <ElTag :type="row.state === 'published' ? 'success' : 'info'">
            {{ row.state === 'published' ? '已开放' : '已撤销' }}
          </ElTag>
        </template>
      </ElTableColumn>
      <ElTableColumn label="操作" width="120">
        <template #default="{ row }">
          <ElButton
            v-if="row.state === 'published'"
            type="danger"
            link
            :loading="revokingId === row.id"
            :disabled="writing || mutationLocked"
            @click="revoke(row)"
          >
            撤销
          </ElButton>
        </template>
      </ElTableColumn>
    </ElTable>
    <ElPagination
      v-if="publicationTotal > 20"
      class="publication-pagination"
      background
      layout="prev, pager, next, total"
      :current-page="publicationPage"
      :page-size="20"
      :total="publicationTotal"
      @current-change="changePublicationPage"
    />
    <KnowledgeQueryPanel :publications="publications" :environment-id="consumerEnvironmentId" />
    <ConversationPanel :publications="publications" :environment-id="consumerEnvironmentId" />
  </ElCard>
</template>

<script setup lang="ts">
  import { computed, onUnmounted, ref, watch } from 'vue'
  import { ElMessage, ElMessageBox } from 'element-plus'
  import { useUserStore } from '@/store/modules/user'
  import iamApi, {
    type SandIamApplicationReference,
    type SandIamEnvironmentReference
  } from '../../api/iam'
  import knowledgeApi, {
    type KnowledgePublication,
    type PublishableKnowledgeBase,
    type KnowledgeTrialResult,
    type KnowledgePublicationDraft,
    type KnowledgePublicationOperationReceipt
  } from '../../api/knowledgePublication'
  import { toRuntimeUiError, type RuntimeUiError } from '../../api/runtimeErrors'
  import ConversationPanel from './conversation-panel.vue'
  import KnowledgeQueryPanel from './knowledge-query-panel.vue'

  const environments = ref<SandIamEnvironmentReference[]>([])
  const applications = ref<SandIamApplicationReference[]>([])
  const environmentTotal = ref(0)
  const environmentReadCount = ref(0)
  const environmentPage = ref(0)
  const environmentLoading = ref(false)
  const environmentError = ref(false)
  const ownerEnvironmentId = ref<number | null>(null)
  const consumerEnvironmentId = ref<number | null>(null)
  const publishableBases = ref<PublishableKnowledgeBase[]>([])
  const basePage = ref(0)
  const baseTotal = ref(0)
  const baseLoading = ref(false)
  const knowledgeBaseId = ref<number | null>(null)
  const selectedBase = computed(() => publishableBases.value.find(item => item.id === knowledgeBaseId.value))
  const trialQuery = ref('')
  const trialResult = ref<KnowledgeTrialResult | null>(null)
  const trialError = ref('')
  const trialLoading = ref(false)
  let trialEpoch = 0
  const aliasCode = ref('')
  const publications = ref<KnowledgePublication[]>([])
  const publicationPage = ref(1)
  const publicationTotal = ref(0)
  const publicationLoading = ref(false)
  const panelError = ref<RuntimeUiError | null>(null)
  const writeFlight = ref<symbol | null>(null)
  const writing = computed(() => writeFlight.value !== null)
  const revokingId = ref<number | null>(null)
  const userStore = useUserStore()
  const currentAdminId = computed(() => {
    const actor = String(userStore.info.id ?? '')
    return userStore.accessToken && /^[1-9][0-9]*$/.test(actor) ? actor : ''
  })
  type SharedOperation = { version: 1; actor: string; key: string } & (
    { operation: 'publish'; target: KnowledgePublicationDraft } |
    { operation: 'revoke'; target: { owner_environment_id: number; publication_id: number } }
  )
  const pendingOperation = ref<SharedOperation | null>(null)
  // Only this page can prove that an original intent has not reached the POST call.
  const unsubmittedOperation = ref<string | null>(null)
  const operationReading = ref(false)
  let operationReadEpoch = 0
  let operationScopeEpoch = 0
  const memoryOperations = new Map<string, SharedOperation>()
  const journalBlocked = ref(false)
  const operationNotice = ref('')
  const mutationLocked = computed(() => !currentAdminId.value || writing.value || operationReading.value
    || journalBlocked.value || pendingOperation.value !== null)
  const canSendUnsubmittedOperation = computed(() => pendingOperation.value !== null
    && unsubmittedOperation.value === JSON.stringify(pendingOperation.value))
  let identityEpoch = 0
  function operationStorageKey(actor: string): string { return `sand-ai-knowledge-sharing-operation:v1:${actor}` }
  function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value)
  }
  function positiveId(value: unknown): value is number {
    return typeof value === 'number' && Number.isSafeInteger(value) && value > 0
  }
  function readOperation(): void {
    journalBlocked.value = false
    const actor = currentAdminId.value
    if (!actor) { journalBlocked.value = true; return }
    try {
      const raw = sessionStorage.getItem(operationStorageKey(actor))
      if (raw === null) {
        // A missing persisted record cannot release a known in-memory operation.
        if (pendingOperation.value) journalBlocked.value = true
        return
      }
      const item: unknown = JSON.parse(raw)
      if (!isRecord(item) || item.version !== 1 || item.actor !== actor
        || typeof item.key !== 'string' || !/^[\x21-\x7e]{1,128}$/.test(item.key)
        || !isRecord(item.target) || !positiveId(item.target.owner_environment_id)
        || Object.keys(item).some(field => !['version', 'actor', 'key', 'operation', 'target'].includes(field)))
        throw new Error('Invalid original sharing operation')
      let operation: SharedOperation
      if (item.operation === 'publish' && positiveId(item.target.knowledge_base_id)
        && positiveId(item.target.consumer_environment_id) && typeof item.target.code === 'string'
        && /^[a-z][a-z0-9_-]{0,63}$/.test(item.target.code)
        && Object.keys(item.target).every(field => ['owner_environment_id', 'knowledge_base_id', 'consumer_environment_id', 'code'].includes(field))) {
        operation = { version: 1, actor, key: item.key, operation: 'publish', target: {
          owner_environment_id: item.target.owner_environment_id, knowledge_base_id: item.target.knowledge_base_id,
          consumer_environment_id: item.target.consumer_environment_id, code: item.target.code } }
      } else if (item.operation === 'revoke' && positiveId(item.target.publication_id)
        && Object.keys(item.target).every(field => ['owner_environment_id', 'publication_id'].includes(field))) {
        operation = { version: 1, actor, key: item.key, operation: 'revoke', target: {
          owner_environment_id: item.target.owner_environment_id, publication_id: item.target.publication_id } }
      } else throw new Error('Invalid original sharing target')
      if (pendingOperation.value && JSON.stringify(pendingOperation.value) !== JSON.stringify(operation))
        throw new Error('Original sharing record changed')
      pendingOperation.value = operation
      memoryOperations.set(actor, operation)
    } catch {
      journalBlocked.value = true
      operationNotice.value = '无法完整读取原共享请求，原记录保留；暂不创建新的开放或撤销请求。'
    }
  }
  function retainOperation(original: SharedOperation): boolean {
    const encoded = JSON.stringify(original)
    try {
      const storage = operationStorageKey(original.actor)
      const existing = sessionStorage.getItem(storage)
      if (existing !== null && existing !== encoded) throw new Error('Original sharing record changed')
      sessionStorage.setItem(storage, encoded)
      if (sessionStorage.getItem(storage) !== encoded) throw new Error('Original sharing record was not retained')
      journalBlocked.value = false
      return true
    } catch {
      journalBlocked.value = true
      operationNotice.value = '原请求标识未能安全保存，本次未发送；原目标和原号仍保留。请保留此页面，恢复会话存储后选择“保留原号首次提交”。'
      return false
    }
  }
  let live = true
  let environmentEpoch = 0
  let baseEpoch = 0
  let publicationEpoch = 0

  const hasMoreEnvironments = computed(() => environmentReadCount.value < environmentTotal.value)
  const hasMoreBases = computed(() => publishableBases.value.length < baseTotal.value)
  const canPublish = computed(() =>
    typeof ownerEnvironmentId.value === 'number' &&
    typeof knowledgeBaseId.value === 'number' &&
    typeof consumerEnvironmentId.value === 'number' &&
    /^[a-z][a-z0-9_-]{0,63}$/.test(aliasCode.value.trim()) &&
    !writing.value && !mutationLocked.value
  )

  async function runTrial(): Promise<void> {
    const ownerId = ownerEnvironmentId.value
    const base = selectedBase.value
    const query = trialQuery.value.trim()
    if (!ownerId || !base || !query || trialLoading.value) return
    const epoch = ++trialEpoch
    trialLoading.value = true
    trialError.value = ''
    trialResult.value = null
    try {
      const result = await knowledgeApi.trial(ownerId, base.version_id, query, 10)
      if (result.knowledge_base_id !== base.id || result.knowledge_base_version_id !== base.version_id
        || result.query !== query) throw new Error('检索结果与所选知识版本不一致')
      if (live && epoch === trialEpoch && ownerEnvironmentId.value === ownerId
        && selectedBase.value?.version_id === base.version_id) trialResult.value = result
    } catch (cause) {
      if (live && epoch === trialEpoch) {
        trialError.value = cause instanceof Error ? cause.message : '检索试用失败'
      }
    } finally {
      if (live && epoch === trialEpoch) trialLoading.value = false
    }
  }

  function clearTrial(): void {
    ++trialEpoch
    trialLoading.value = false
    trialResult.value = null
    trialError.value = ''
  }

  function clearOperation(original: SharedOperation): void {
    const storage = operationStorageKey(original.actor)
    if (sessionStorage.getItem(storage) !== JSON.stringify(original))
      throw new Error('原请求记录已变化，不能解除原操作锁。')
    sessionStorage.removeItem(storage)
    if (sessionStorage.getItem(storage) !== null)
      throw new Error('原请求记录尚未清除，继续保留原操作锁。')
    memoryOperations.delete(original.actor)
    pendingOperation.value = null
    unsubmittedOperation.value = null
    journalBlocked.value = false
  }

  function matchesOperationReceipt(original: SharedOperation, result: KnowledgePublicationOperationReceipt): boolean {
    return result.operation === original.operation && result.request_id === original.key
      && result.actor_ref === original.actor
      && result.owner_environment_id === original.target.owner_environment_id
      && positiveId(result.publication_id) && positiveId(result.knowledge_base_id)
      && positiveId(result.consumer_environment_id) && typeof result.code === 'string'
      && /^[a-z][a-z0-9_-]{0,63}$/.test(result.code) && result.idempotent_replay === true
      && result.state === (original.operation === 'publish' ? 'published' : 'revoked')
      && (original.operation === 'publish'
        ? result.knowledge_base_id === original.target.knowledge_base_id
          && result.consumer_environment_id === original.target.consumer_environment_id
          && result.code === original.target.code
        : result.publication_id === original.target.publication_id)
  }

  function environmentLabel(environment: SandIamEnvironmentReference): string {
    const app = applications.value.find(item => item.id === environment.application_id)
    return `${app?.name ?? `应用 ${environment.application_id}`} · ${environment.name} (${environment.code})`
  }

  function environmentName(id: number): string {
    const environment = environments.value.find(item => item.id === id)
    return environment ? environmentLabel(environment) : `环境 ${id}`
  }

  async function loadEnvironments(reset = false): Promise<void> {
    if (environmentLoading.value && !reset) return
    const epoch = ++environmentEpoch
    const page = reset ? 1 : environmentPage.value + 1
    environmentLoading.value = true
    environmentError.value = false
    try {
      const result = await iamApi.listEnvironments({ page, limit: 100 })
      if (!live || epoch !== environmentEpoch) return
      const available = result.data.filter(item => item.status === 1)
      environments.value = reset ? available : [...environments.value, ...available]
      environmentPage.value = page
      environmentTotal.value = result.total
      environmentReadCount.value = reset ? result.data.length : environmentReadCount.value + result.data.length
    } catch {
      if (live && epoch === environmentEpoch) environmentError.value = true
    } finally {
      if (live && epoch === environmentEpoch) environmentLoading.value = false
    }
  }

  async function loadApplications(): Promise<void> {
    const identity = identityEpoch
    try {
      const result = await iamApi.listApplications({ page: 1, limit: 100 })
      if (live && identity === identityEpoch) applications.value = result.data.filter(item => item.status === 1)
    } catch {
      // Environment selection remains usable when application names are unavailable.
    }
  }

  async function loadBases(reset = false): Promise<void> {
    if (typeof ownerEnvironmentId.value !== 'number' || (baseLoading.value && !reset)) return
    const epoch = ++baseEpoch
    const selectedOwner = ownerEnvironmentId.value
    const page = reset ? 1 : basePage.value + 1
    baseLoading.value = true
    panelError.value = null
    try {
      const result = await knowledgeApi.publishable(selectedOwner, page)
      if (!live || epoch !== baseEpoch || selectedOwner !== ownerEnvironmentId.value) return
      publishableBases.value = reset ? result.data : [...publishableBases.value, ...result.data]
      basePage.value = page
      baseTotal.value = result.total
    } catch (error) {
      if (live && epoch === baseEpoch) panelError.value = toRuntimeUiError(error)
    } finally {
      if (live && epoch === baseEpoch) baseLoading.value = false
    }
  }

  async function loadPublications(page = 1): Promise<void> {
    if (typeof ownerEnvironmentId.value !== 'number' || typeof knowledgeBaseId.value !== 'number') return
    const epoch = ++publicationEpoch
    const ownerId = ownerEnvironmentId.value
    const baseId = knowledgeBaseId.value
    publicationLoading.value = true
    panelError.value = null
    try {
      const result = await knowledgeApi.list(ownerId, baseId, page)
      if (!live || epoch !== publicationEpoch || ownerId !== ownerEnvironmentId.value ||
          baseId !== knowledgeBaseId.value) return
      publications.value = result.data
      publicationTotal.value = result.total
      publicationPage.value = page
    } catch (error) {
      if (live && epoch === publicationEpoch) panelError.value = toRuntimeUiError(error)
    } finally {
      if (live && epoch === publicationEpoch) publicationLoading.value = false
    }
  }

  function changePublicationPage(page: number): void {
    void loadPublications(page)
  }

  async function executeOperation(original: SharedOperation, heldOwner?: symbol): Promise<void> {
    const encoded = JSON.stringify(original)
    if ((writing.value && (!heldOwner || writeFlight.value !== heldOwner)) || operationReading.value || original.actor !== currentAdminId.value
      || unsubmittedOperation.value !== encoded || JSON.stringify(pendingOperation.value) !== encoded) return
    const identity = identityEpoch
    const scope = operationScopeEpoch
    const credential = userStore.accessToken
    const isCurrent = () => live && identity === identityEpoch && original.actor === currentAdminId.value
      && scope === operationScopeEpoch && credential === userStore.accessToken
      && JSON.stringify(pendingOperation.value) === encoded
    if (!retainOperation(original)) return
    const flight = heldOwner ?? Symbol('knowledge-publication-write')
    writeFlight.value = flight
    unsubmittedOperation.value = null
    revokingId.value = original.operation === 'revoke' ? original.target.publication_id : null
    operationNotice.value = ''
    panelError.value = null
    try {
      const result = original.operation === 'publish'
        ? await knowledgeApi.publish(original.target, original.key)
        : await knowledgeApi.revoke(original.target.owner_environment_id, original.target.publication_id, original.key)
      if (!isCurrent()) return
      if (!positiveId(result.publication_id) || result.state !== (original.operation === 'publish' ? 'published' : 'revoked')
        || typeof result.idempotent_replay !== 'boolean'
        || (original.operation === 'revoke' && result.publication_id !== original.target.publication_id))
        throw new Error('响应与原共享操作不一致，请保留原目标和请求号恢复。')
      clearOperation(original)
      ElMessage.success(original.operation === 'publish' ? '原开放请求已确认。应用查询权限仍由 SandIAM 管理。' : '原撤销请求已确认。')
      await loadPublications(publicationPage.value)
    } catch (error) {
      if (isCurrent()) {
        panelError.value = toRuntimeUiError(error)
        operationNotice.value = '原操作结果或记录清理尚未完整确认；原目标和请求号继续保留，请按原号恢复，不创建替代操作。'
      }
    } finally {
      if (writeFlight.value === flight) writeFlight.value = null
      if (live && identity === identityEpoch && credential === userStore.accessToken) revokingId.value = null
    }
  }
  async function recoverOperation(): Promise<void> {
    const original = pendingOperation.value
    if (!original || writing.value || operationReading.value || original.actor !== currentAdminId.value) return
    const encoded = JSON.stringify(original)
    const identity = identityEpoch
    const scope = operationScopeEpoch
    const credential = userStore.accessToken
    const epoch = ++operationReadEpoch
    const isCurrent = () => live && epoch === operationReadEpoch && identity === identityEpoch
      && scope === operationScopeEpoch && credential === userStore.accessToken
      && original.actor === currentAdminId.value && JSON.stringify(pendingOperation.value) === encoded
    operationReading.value = true
    operationNotice.value = ''
    panelError.value = null
    try {
      const result = original.operation === 'publish'
        ? await knowledgeApi.readPublishOperation(original.target, original.key)
        : await knowledgeApi.readRevokeOperation(original.target.owner_environment_id, original.target.publication_id, original.key)
      if (!isCurrent()) return
      if (!matchesOperationReceipt(original, result))
        throw new Error('读取结果无法证明原管理员、环境、目标和请求号的操作，原操作锁保留。')
      clearOperation(original)
      ElMessage.success(original.operation === 'publish' ? '原开放操作回执已确认。应用查询权限仍由 SandIAM 管理。' : '原撤销操作回执已确认。')
      await loadPublications(publicationPage.value)
    } catch (error) {
      if (isCurrent()) {
        panelError.value = toRuntimeUiError(error)
        operationNotice.value = '原操作回执尚未完整确认；未找到、无权读取或网络异常不能视为未提交。原目标和请求号继续保留，仅可读取原操作回执。'
      }
    } finally {
      if (live && epoch === operationReadEpoch) operationReading.value = false
    }
  }
  async function sendUnsubmittedOperation(): Promise<void> {
    const original = pendingOperation.value
    if (original && canSendUnsubmittedOperation.value) await executeOperation(original)
  }
  async function publish(): Promise<void> {
    if (!canPublish.value) return
    readOperation()
    if (mutationLocked.value) return
    const ownerId = ownerEnvironmentId.value
    const baseId = knowledgeBaseId.value
    const consumerId = consumerEnvironmentId.value
    if (!positiveId(ownerId) || !positiveId(baseId) || !positiveId(consumerId)) return
    const original: SharedOperation = { version: 1, actor: currentAdminId.value, key: crypto.randomUUID(),
      operation: 'publish', target: { owner_environment_id: ownerId, knowledge_base_id: baseId,
        consumer_environment_id: consumerId, code: aliasCode.value.trim() } }
    memoryOperations.set(original.actor, original)
    pendingOperation.value = original
    unsubmittedOperation.value = JSON.stringify(original)
    await executeOperation(original)
  }
  async function revoke(row: KnowledgePublication): Promise<void> {
    if (writing.value || mutationLocked.value || typeof ownerEnvironmentId.value !== 'number'
      || row.knowledge_base_id !== knowledgeBaseId.value) return
    const identity = identityEpoch
    const scope = operationScopeEpoch
    const actor = currentAdminId.value
    const credential = userStore.accessToken
    const ownerId = ownerEnvironmentId.value
    const baseId = knowledgeBaseId.value
    const target = Object.freeze({ id: row.id, code: row.code, state: row.state, update_time: row.update_time,
      owner_environment_id: row.owner_environment_id, knowledge_base_id: row.knowledge_base_id,
      consumer_environment_id: row.consumer_environment_id })
    const flight = Symbol('knowledge-publication-revoke')
    writeFlight.value = flight
    try {
      try {
        await ElMessageBox.confirm(`撤销“${target.code}”后，该消费环境不能继续查询此知识库。`, '撤销知识库共享',
          { type: 'warning', confirmButtonText: '撤销', cancelButtonText: '取消' })
      } catch { return }
      if (!live || writeFlight.value !== flight || identity !== identityEpoch || scope !== operationScopeEpoch
        || actor !== currentAdminId.value || credential !== userStore.accessToken
        || ownerId !== ownerEnvironmentId.value || baseId !== knowledgeBaseId.value) return
      const current = publications.value.find(item => item.id === target.id)
      if (!current || current.owner_environment_id !== target.owner_environment_id
        || current.knowledge_base_id !== target.knowledge_base_id || current.consumer_environment_id !== target.consumer_environment_id
        || current.code !== target.code || current.state !== target.state || current.update_time !== target.update_time) {
        operationNotice.value = '原共享记录已变化，请刷新后重新确认撤销。'
        return
      }
      readOperation()
      if (operationReading.value || journalBlocked.value || pendingOperation.value !== null) return
      const original: SharedOperation = { version: 1, actor, key: crypto.randomUUID(),
        operation: 'revoke', target: { owner_environment_id: ownerId, publication_id: target.id } }
      memoryOperations.set(original.actor, original)
      pendingOperation.value = original
      unsubmittedOperation.value = JSON.stringify(original)
      await executeOperation(original, flight)
    } finally {
      if (writeFlight.value === flight) writeFlight.value = null
    }
  }

  watch(ownerEnvironmentId, () => {
    clearTrial()
    trialQuery.value = ''
    ++baseEpoch
    ++publicationEpoch
    baseLoading.value = false
    publicationLoading.value = false
    publishableBases.value = []
    basePage.value = 0
    baseTotal.value = 0
    knowledgeBaseId.value = null
    publications.value = []
    publicationTotal.value = 0
    aliasCode.value = ''
    if (typeof ownerEnvironmentId.value === 'number') void loadBases(true)
  }, { flush: 'sync' })

  watch(knowledgeBaseId, () => {
    clearTrial()
    ++publicationEpoch
    publications.value = []
    publicationPage.value = 1
    publicationTotal.value = 0
    const selected = publishableBases.value.find(item => item.id === knowledgeBaseId.value)
    aliasCode.value = selected?.code ?? ''
    if (typeof knowledgeBaseId.value === 'number') void loadPublications(1)
  })

  watch([ownerEnvironmentId, consumerEnvironmentId, knowledgeBaseId], () => {
    ++operationScopeEpoch
    ++operationReadEpoch
    operationReading.value = false
    unsubmittedOperation.value = null
    operationNotice.value = ''
    panelError.value = null
    clearTrial()
    trialQuery.value = ''
  }, { flush: 'sync' })

  watch([currentAdminId, () => userStore.accessToken], () => {
    ++identityEpoch
    ++operationScopeEpoch
    ++operationReadEpoch
    operationReading.value = false
    unsubmittedOperation.value = null
    ++environmentEpoch; ++baseEpoch; ++publicationEpoch
    clearTrial()
    trialQuery.value = ''
    aliasCode.value = ''
    pendingOperation.value = memoryOperations.get(currentAdminId.value) ?? null
    journalBlocked.value = false
    operationNotice.value = ''
    revokingId.value = null
    panelError.value = null
    environments.value = []; applications.value = []
    environmentLoading.value = false; baseLoading.value = false; publicationLoading.value = false
    environmentReadCount.value = 0; environmentTotal.value = 0; environmentPage.value = 0
    ownerEnvironmentId.value = null; consumerEnvironmentId.value = null; knowledgeBaseId.value = null
    publishableBases.value = []; publications.value = []; publicationTotal.value = 0
    readOperation()
    if (currentAdminId.value) { void loadApplications(); void loadEnvironments(true) }
  }, { immediate: true, flush: 'sync' })
  onUnmounted(() => {
    live = false
    ++identityEpoch
    ++operationScopeEpoch
    ++operationReadEpoch
    unsubmittedOperation.value = null
    trialQuery.value = ''; aliasCode.value = ''
    ++trialEpoch
    ++environmentEpoch
    ++baseEpoch
    ++publicationEpoch
  })
</script>

<style scoped lang="scss">
  .publication-card {
    margin-bottom: 16px;
  }
  .panel-header,
  .list-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
  }
  .panel-header h2,
  .list-header h3 {
    margin: 0;
  }
  .panel-header p,
  .form-hint {
    color: var(--el-text-color-secondary);
    font-size: 13px;
  }
  .panel-header p {
    margin: 6px 0 0;
  }
  .form-hint {
    margin-left: 12px;
  }
  .panel-alert,
  .list-header {
    margin-bottom: 16px;
  }
  .publication-pagination {
    margin-top: 16px;
    justify-content: flex-end;
  }
</style>
