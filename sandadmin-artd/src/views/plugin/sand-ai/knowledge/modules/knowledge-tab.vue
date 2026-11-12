<template>
  <div class="knowledge-tab">
    <!-- 筛选区：只露搜索和状态，全部可清空 -->
    <div class="filter-bar">
      <ElInput
        v-model="keyword"
        clearable
        placeholder="搜索知识库名称或说明"
        class="filter-search"
        @keyup.enter="page = 1"
        @clear="page = 1"
      />
      <ElSelect
        v-model="statusFilter"
        clearable
        placeholder="全部状态"
        class="filter-status"
        @change="page = 1"
      >
        <ElOption label="可用" value="usable" />
        <ElOption label="索引中" value="indexing" />
        <ElOption label="索引失败（可重试）" value="failed" />
        <ElOption label="未发布" value="draft" />
        <ElOption label="已停用" value="disabled" />
      </ElSelect>
    </div>

    <ElSkeleton v-if="firstLoading" :rows="6" animated />

    <ElAlert
      v-else-if="loadError"
      type="error"
      :closable="false"
      title="知识库没能加载出来"
      :description="loadError"
    >
      <template #default>
        <ElButton size="small" type="primary" @click="load">重新加载</ElButton>
      </template>
    </ElAlert>

    <ElEmpty
      v-else-if="environmentId === null"
      description="请先在上方选择环境"
    />

    <ElEmpty
      v-else-if="rows.length === 0"
      description="还没有知识库"
    >
      <template #description>
        <div class="empty-wrap">
          <p class="empty-title">还没有知识库</p>
          <p class="empty-desc">把常用资料放进来，AI 回答会更准</p>
        </div>
      </template>
      <ElButton type="primary" @click="openCreate">新建知识库</ElButton>
    </ElEmpty>

    <template v-else>
      <ElTable
        v-loading="refreshing"
        :data="pagedRows"
        size="default"
        row-key="id"
        class="kb-table"
        empty-text="没有找到符合条件的知识库"
      >
        <ElTableColumn label="名称" min-width="200">
          <template #default="{ row }">
            <div class="cell-name">{{ row.name }}</div>
            <div v-if="row.description" class="cell-desc">{{ row.description }}</div>
          </template>
        </ElTableColumn>
        <ElTableColumn label="资料数" width="90" align="center">
          <template #default="{ row }">{{ materialCount(row) }}</template>
        </ElTableColumn>
        <ElTableColumn label="状态" width="170">
          <template #default="{ row }">
            <ElTag :type="statusTagType(row)" effect="plain" size="small">
              {{ statusLabel(row) }}
            </ElTag>
            <div v-if="rowStatus(row) === 'indexing'" class="cell-sub">正在整理，一般几分钟</div>
            <div v-else-if="rowStatus(row) === 'failed' && row.index_error" class="cell-sub cell-error">
              {{ row.index_error }}
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn label="最近更新" width="120" align="center">
          <template #default="{ row }">
            <span class="cell-muted">{{ formatUpdateTime(row.update_time) }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn label="操作" width="280" fixed="right">
          <template #default="{ row }">
            <ElSpace :size="4" wrap>
              <ElButton link type="primary" @click="openDetail(row)">打开</ElButton>
              <ElButton
                v-if="rowStatus(row) === 'usable'"
                link
                type="primary"
                @click="openTrial(row)"
              >试一下</ElButton>
              <ElButton
                v-if="rowStatus(row) === 'failed'"
                link
                type="warning"
                :loading="retryingId === row.id"
                @click="retryIndex(row)"
              >重试</ElButton>
              <ElButton
                v-if="row.state === 'disabled'"
                link
                type="primary"
                :disabled="!row.active_version_id || disablingId === row.id"
                :title="row.active_version_id ? '' : '没有已发布的版本，不能启用'"
                @click="confirmEnable(row)"
              >启用</ElButton>
              <ElButton
                v-if="row.state === 'disabled'"
                link
                type="danger"
                :disabled="disablingId === row.id"
                @click="confirmRetire(row)"
              >删除</ElButton>
              <ElDropdown v-if="row.state !== 'disabled'" trigger="click" @command="(cmd: string) => onRowCommand(cmd, row)">
                <ElButton link type="primary">更多</ElButton>
                <template #dropdown>
                  <ElDropdownMenu>
                    <ElDropdownItem v-if="row.state === 'draft'" command="publish">发布</ElDropdownItem>
                    <ElDropdownItem v-if="row.state === 'published'" command="disable" divided>停用</ElDropdownItem>
                    <ElDropdownItem disabled divided>删除（先停用才能删）</ElDropdownItem>
                  </ElDropdownMenu>
                </template>
              </ElDropdown>
            </ElSpace>
          </template>
        </ElTableColumn>
      </ElTable>

      <div class="pager">
        <ElPagination
          v-model:current-page="page"
          v-model:page-size="pageSize"
          :total="filteredRows.length"
          :page-sizes="[20]"
          layout="total, prev, pager, next"
          background
        />
      </div>
    </template>

    <!-- 新建知识库：只有 3 个字段 -->
    <ElDialog
      v-model="createOpen"
      title="新建知识库"
      width="min(520px, 94vw)"
      :close-on-click-modal="false"
      @closed="resetCreateForm"
    >
      <ElForm label-width="90px" @submit.prevent>
        <ElFormItem label="名称" required :error="nameError">
          <ElInput
            v-model="createForm.name"
            maxlength="30"
            show-word-limit
            placeholder="例如：公司制度"
            @blur="validateName"
          />
        </ElFormItem>
        <ElFormItem label="说明">
          <ElInput
            v-model="createForm.description"
            type="textarea"
            :rows="3"
            maxlength="200"
            show-word-limit
            placeholder="一句话说明这是什么资料（选填）"
          />
        </ElFormItem>
        <ElFormItem label="给谁用">
          <ElSelect
            v-model="createForm.audiences"
            multiple
            clearable
            collapse-tags
            collapse-tags-tooltip
            placeholder="暂时谁都不用"
            class="audience-select"
          >
            <ElOption label="试用" value="__trial__" />
            <ElOption
              v-for="agent in agentOptions"
              :key="agent"
              :label="agent"
              :value="agent"
            />
          </ElSelect>
          <div class="field-hint">不选表示暂时谁都不用；建好后也可以随时调整。</div>
        </ElFormItem>
      </ElForm>
      <template #footer>
        <ElButton @click="createOpen = false">取消</ElButton>
        <ElButton type="primary" :loading="creating" @click="submitCreate">创建</ElButton>
      </template>
    </ElDialog>

    <!-- 详情抽屉 -->
    <ElDrawer
      v-model="detailOpen"
      size="min(560px, 94vw)"
      :title="detailTitle"
      destroy-on-close
      @close="onDetailClosed"
    >
      <template v-if="pendingDetail">
        <ElAlert
          type="success"
          :closable="false"
          class="detail-alert"
          title="还没建好，先选几份资料"
        />
        <ElForm label-width="72px" @submit.prevent>
          <ElFormItem label="名称">
            <ElInput v-model="pendingDetail.name" maxlength="128" placeholder="知识库名称" />
          </ElFormItem>
        </ElForm>
        <dl class="detail-meta">
          <dt>说明</dt>
          <dd>{{ pendingDetail.description || '—' }}</dd>
        </dl>
        <ElButton type="primary" class="detail-add" @click="openFilePicker">加资料</ElButton>
        <p class="detail-hint">从已上传并解析完成的文件里选择，一次最多 20 份。还没有合适的文件？先去「文件」上传。</p>
      </template>

      <template v-else-if="detailRow">
        <div class="detail-head">
          <ElTag :type="statusTagType(detailRow)" effect="plain" size="small">
            {{ statusLabel(detailRow) }}
          </ElTag>
          <span v-if="detailRow.active_version_no" class="detail-version">第 {{ detailRow.active_version_no }} 版</span>
        </div>
        <ElForm label-width="72px" @submit.prevent>
          <ElFormItem label="名称">
            <ElInput v-model="detailName" maxlength="128" :disabled="isSavingName" placeholder="知识库名称" />
          </ElFormItem>
        </ElForm>
        <p v-if="detailRow.description" class="detail-desc">{{ detailRow.description }}</p>

        <ElAlert
          v-if="rowStatus(detailRow) === 'indexing'"
          type="info"
          :closable="false"
          class="detail-alert"
          title="正在整理资料，一般几分钟，整理好后就能试查了"
        />
        <ElAlert
          v-else-if="rowStatus(detailRow) === 'failed'"
          type="error"
          :closable="false"
          class="detail-alert"
          :title="detailRow.index_error || '索引没有成功'"
          description="可以点「重试」再试一次；一直失败请检查 AI 服务商和预算。"
        >
          <template #default>
            <ElButton size="small" type="primary" :loading="retryingId === detailRow.id" @click="retryIndex(detailRow)">
              重试
            </ElButton>
          </template>
        </ElAlert>

        <dl class="detail-meta">
          <dt>资料状态</dt>
          <dd>{{ sourceStateLabel(detailRow) }}</dd>
          <template v-if="detailRow.draft_source_count !== null">
            <dt>待发布资料</dt>
            <dd>{{ detailRow.draft_source_count }} 段</dd>
          </template>
        </dl>

        <div class="detail-actions">
          <ElButton type="primary" :loading="isSavingName" :disabled="isSavingName" @click="saveDetailName">保存</ElButton>
          <ElButton
            v-if="rowStatus(detailRow) === 'usable'"
            @click="openTrial(detailRow)"
          >试一下</ElButton>
          <ElButton
            v-if="detailRow.state === 'draft'"
            type="primary"
            :loading="publishingId === detailRow.id"
            @click="publishDraft(detailRow)"
          >发布</ElButton>
          <ElButton
            v-if="detailRow.state === 'published'"
            type="danger"
            plain
            @click="confirmDisable(detailRow)"
          >停用</ElButton>
          <ElButton
            v-if="detailRow.state === 'disabled'"
            type="primary"
            :disabled="!detailRow.active_version_id || disablingId === detailRow.id"
            :loading="disablingId === detailRow.id && Boolean(detailRow.active_version_id)"
            :title="detailRow.active_version_id ? '' : '没有已发布的版本，不能启用'"
            @click="confirmEnable(detailRow)"
          >启用</ElButton>
          <ElButton
            v-if="detailRow.state === 'disabled'"
            type="danger"
            plain
            :disabled="disablingId === detailRow.id"
            @click="confirmRetire(detailRow)"
          >删除</ElButton>
        </div>
        <p v-if="detailRow.state === 'disabled' && !detailRow.active_version_id" class="detail-hint">没有已发布的版本，不能启用。</p>
        <p class="detail-hint">资料在建库时一次选定；需要更换资料请新建知识库。</p>
      </template>
    </ElDrawer>

    <!-- 加资料：从已解析文件里选，最多 20 份 -->
    <ElDialog
      v-model="filePickerOpen"
      title="加资料"
      width="min(640px, 94vw)"
      :close-on-click-modal="false"
    >
      <ElAlert
        type="info"
        :closable="false"
        class="picker-alert"
        title="选择已上传并解析完成的文件，一次最多 20 份。"
      />
      <ElTable
        v-loading="filesLoading"
        :data="eligibleFiles"
        size="default"
        row-key="id"
        max-height="360"
        @selection-change="onFileSelection"
      >
        <ElTableColumn type="selection" width="46" :selectable="() => selectedFiles.length < MAX_FILES_PER_BATCH" />
        <ElTableColumn prop="name" label="文件名" min-width="220" show-overflow-tooltip />
        <ElTableColumn label="状态" width="80" align="center">
          <template #default>
            <ElTag type="success" size="small" effect="plain">已解析</ElTag>
          </template>
        </ElTableColumn>
      </ElTable>
      <div class="picker-foot">
        <span class="picker-count">已选 {{ selectedFiles.length }} / 20 份</span>
        <ElButton v-if="filesNextBefore !== null" link type="primary" :loading="filesLoading" @click="loadMoreFiles">
          加载更多文件
        </ElButton>
      </div>
      <template #footer>
        <ElButton @click="filePickerOpen = false">取消</ElButton>
        <ElButton
          type="primary"
          :disabled="selectedFiles.length === 0"
          :loading="materializing"
          @click="confirmAddMaterials"
        >确定</ElButton>
      </template>
    </ElDialog>

    <!-- 试一下：只有「可用」才进得来；走托管试用身份，不用填密钥 -->
    <ElDialog
      v-model="trialOpen"
      :title="trialKnowledgeBase ? `试一下「${trialKnowledgeBase.name}」` : '试一下'"
      width="min(640px, 94vw)"
      :close-on-click-modal="false"
    >
      <ElForm @submit.prevent>
        <ElFormItem label="想查什么">
          <ElInput
            v-model="trialQuery"
            type="textarea"
            :rows="3"
            maxlength="500"
            show-word-limit
            placeholder="输入想让 AI 查找的问题"
            @keyup.ctrl.enter="submitTrial"
            @keyup.meta.enter="submitTrial"
          />
        </ElFormItem>
      </ElForm>
      <p class="trial-hint">试查会产生少量用量，计入预算；结果里的摘录来自资料原文。</p>

      <ElAlert v-if="trialError" type="error" :closable="false" :title="trialError" class="trial-alert" />

      <div v-if="trialBusy" class="trial-loading">
        <ElSkeleton :rows="3" animated />
      </div>

      <template v-else-if="trialResult">
        <p v-if="trialResult.citations.length === 0" class="trial-empty">没有找到相关内容，可以换个说法再试。</p>
        <ol v-else class="trial-citations">
          <li v-for="(item, index) in trialResult.citations" :key="index" class="trial-citation">
            <p class="trial-excerpt">{{ item.excerpt }}</p>
          </li>
        </ol>
      </template>

      <template #footer>
        <ElButton @click="trialOpen = false">关闭</ElButton>
        <ElButton type="primary" :loading="trialBusy" :disabled="!trialQuery.trim()" @click="submitTrial">
          查一下
        </ElButton>
      </template>
    </ElDialog>
  </div>
</template>

<script setup lang="ts">
  import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
  import { ElMessage, ElMessageBox } from 'element-plus'
  import { errorDisplayText } from '../../api/errorText'
  import knowledgeApi, {
    type KnowledgeBaseRow,
    type IndexRetryResult,
    type KnowledgeSourceFile
  } from '../../api/knowledgeBase'
  import { requestKnowledgeTrial, type KnowledgeTrialResult } from '../../api/adminTrial'
  import agentDefinitionApi from '../../api/agentDefinition'
  import { useAdminReadIdentity } from '../../composables/useAdminReadIdentity'

  const props = defineProps<{ environmentId: number | null }>()

  const PAGE_SIZE = 20
  const POLL_MS = 4000
  const MAX_FILES_PER_BATCH = 20

  interface PendingKnowledgeBase {
    name: string
    description: string
    audiences: string[]
  }

  type RowStatus = 'usable' | 'indexing' | 'failed' | 'draft' | 'disabled'

  const identity = useAdminReadIdentity()

  const rows = ref<KnowledgeBaseRow[]>([])
  const firstLoading = ref(false)
  const refreshing = ref(false)
  const loadError = ref('')
  const keyword = ref('')
  const statusFilter = ref<RowStatus | '' | null>('')
  const page = ref(1)
  const pageSize = ref(PAGE_SIZE)

  const pendingKnowledgeBase = ref<PendingKnowledgeBase | null>(null)

  const createOpen = ref(false)
  const creating = ref(false)
  const createForm = reactive({ name: '', description: '', audiences: [] as string[] })
  const nameError = ref('')
  const agentOptions = ref<string[]>([])

  const detailOpen = ref(false)
  const detailRow = ref<KnowledgeBaseRow | null>(null)
  const detailName = ref('')
  const isSavingName = ref(false)
  const pendingDetail = ref<PendingKnowledgeBase | null>(null)

  const filePickerOpen = ref(false)
  const filesLoading = ref(false)
  const eligibleFiles = ref<KnowledgeSourceFile[]>([])
  const filesNextBefore = ref<number | null>(null)
  const selectedFiles = ref<KnowledgeSourceFile[]>([])
  const materializing = ref(false)

  const trialOpen = ref(false)
  const trialKnowledgeBase = ref<KnowledgeBaseRow | null>(null)
  const trialQuery = ref('')
  const trialBusy = ref(false)
  const trialError = ref('')
  const trialResult = ref<KnowledgeTrialResult | null>(null)

  const retryingId = ref<number | null>(null)
  const publishingId = ref<number | null>(null)
  const disablingId = ref<number | null>(null)

  let alive = true
  let generation = 0
  let pollTimer: ReturnType<typeof setTimeout> | null = null

  function rowStatus(row: KnowledgeBaseRow): RowStatus {
    if (row.state === 'disabled') return 'disabled'
    if (row.state === 'draft') return 'draft'
    if (row.index_state === 'ready') return 'usable'
    if (row.index_state === 'failed') return 'failed'
    return 'indexing'
  }

  function statusLabel(row: KnowledgeBaseRow): string {
    switch (rowStatus(row)) {
      case 'usable': return '可用'
      case 'indexing': return '索引中'
      case 'failed': return '索引失败（可重试）'
      case 'draft': return '未发布'
      case 'disabled': return '已停用'
    }
  }

  function statusTagType(row: KnowledgeBaseRow): 'success' | 'warning' | 'danger' | 'info' {
    switch (rowStatus(row)) {
      case 'usable': return 'success'
      case 'indexing': return 'warning'
      case 'failed': return 'danger'
      default: return 'info'
    }
  }

  function sourceStateLabel(row: KnowledgeBaseRow): string {
    switch (row.source_state) {
      case 'ready': return '资料正常'
      case 'source_changed': return '资料内容有变化，需要重新发布'
      case 'purged': return '资料已清理'
      default: return '资料暂不可用'
    }
  }

  /** 「最近更新」展示到分钟；后端返回 YYYY-MM-DD HH:MM:SS，空值回退 —。 */
  function formatUpdateTime(value: string | null | undefined): string {
    if (!value) return '—'
    return value.slice(0, 16)
  }

  /** 资料数：后端已按草稿优先、已发布兜底投影 material_count；无资料显示 —。 */
  function materialCount(row: KnowledgeBaseRow): string {
    return typeof row.material_count === 'number' && row.material_count > 0 ? `${row.material_count} 段` : '—'
  }

  const filteredRows = computed<KnowledgeBaseRow[]>(() => {
    const word = keyword.value.trim().toLowerCase()
    return rows.value.filter((row) => {
      if (statusFilter.value && rowStatus(row) !== statusFilter.value) return false
      if (word !== '') {
        const haystack = `${row.name}\n${row.description ?? ''}`.toLowerCase()
        if (!haystack.includes(word)) return false
      }
      return true
    })
  })

  const pagedRows = computed<KnowledgeBaseRow[]>(() => {
    const start = (page.value - 1) * pageSize.value
    return filteredRows.value.slice(start, start + pageSize.value)
  })

  const needsPolling = computed(() => rows.value.some((row) => rowStatus(row) === 'indexing'))

  async function fetchAllRows(envId: number): Promise<KnowledgeBaseRow[]> {
    const all: KnowledgeBaseRow[] = []
    for (let current = 1; current <= 10; current++) {
      const result = await knowledgeApi.list(envId, current, 100)
      all.push(...result.data)
      if (all.length >= result.total || result.data.length === 0) break
    }
    return all
  }

  async function load(): Promise<void> {
    const envId = props.environmentId
    if (envId === null) {
      rows.value = []
      return
    }
    const mark = identity.capture()
    const current = ++generation
    firstLoading.value = rows.value.length === 0
    refreshing.value = !firstLoading.value
    loadError.value = ''
    try {
      const all = await fetchAllRows(envId)
      if (!alive || current !== generation || !identity.current(mark)) return
      rows.value = all
      page.value = Math.min(page.value, Math.max(1, Math.ceil(filteredRows.value.length / pageSize.value) || 1))
    } catch (error) {
      if (!alive || current !== generation || !identity.current(mark)) return
      loadError.value = errorDisplayText(error, '知识库没能加载出来，请稍后再试。')
      rows.value = []
    } finally {
      if (alive && current === generation) {
        firstLoading.value = false
        refreshing.value = false
      }
    }
    schedulePoll()
  }

  /** 索引中的行静默刷新；失败不打扰用户，下一轮再试。 */
  async function reloadSilent(): Promise<void> {
    const envId = props.environmentId
    if (envId === null || !needsPolling.value) return
    const mark = identity.capture()
    const current = generation
    try {
      const all = await fetchAllRows(envId)
      if (!alive || current !== generation || !identity.current(mark)) return
      rows.value = all
    } catch {
      // 轮询失败静默，保留现有列表
    }
    schedulePoll()
  }

  function schedulePoll(): void {
    clearPoll()
    if (!alive || !needsPolling.value) return
    pollTimer = setTimeout(() => {
      pollTimer = null
      void reloadSilent()
    }, POLL_MS)
  }

  function clearPoll(): void {
    if (pollTimer !== null) {
      clearTimeout(pollTimer)
      pollTimer = null
    }
  }

  function openCreate(): void {
    if (props.environmentId === null) {
      ElMessage.warning('请先在上方选择环境')
      return
    }
    createForm.name = ''
    createForm.description = ''
    createForm.audiences = []
    nameError.value = ''
    createOpen.value = true
    void loadAgentOptions()
  }

  async function loadAgentOptions(): Promise<void> {
    const envId = props.environmentId
    if (envId === null) return
    try {
      const result = await agentDefinitionApi.list(envId, 1)
      if (!alive) return
      agentOptions.value = result.data
        .filter((item) => item.state === 'published' && item.status === 1)
        .map((item) => item.name)
    } catch {
      agentOptions.value = []
    }
  }

  function nameTaken(name: string): boolean {
    const target = name.trim()
    if (target === '') return false
    if (pendingKnowledgeBase.value !== null && pendingKnowledgeBase.value.name.trim() === target) return true
    return rows.value.some((row) => row.name.trim() === target)
  }

  function validateName(): void {
    const name = createForm.name.trim()
    if (name === '') {
      nameError.value = ''
      return
    }
    if (nameTaken(name)) {
      nameError.value = `已经有叫「${name}」的知识库了，换个名字`
      return
    }
    nameError.value = ''
  }

  function submitCreate(): void {
    const name = createForm.name.trim()
    if (name === '') {
      nameError.value = '请填写名称'
      return
    }
    if (name.length > 30) {
      nameError.value = '名称最多 30 个字'
      return
    }
    if (nameTaken(name)) {
      nameError.value = `已经有叫「${name}」的知识库了，换个名字`
      return
    }
    // 后端建库必须同时带资料，这里先记下名称进详情，点「加资料」时再一次性建库+发布。
    pendingKnowledgeBase.value = {
      name,
      description: createForm.description.trim(),
      audiences: [...createForm.audiences]
    }
    createOpen.value = false
    detailRow.value = null
    pendingDetail.value = pendingKnowledgeBase.value
    detailOpen.value = true
  }

  function resetCreateForm(): void {
    nameError.value = ''
  }

  const detailTitle = computed(() => {
    if (pendingDetail.value !== null) return pendingDetail.value.name
    return detailRow.value?.name ?? '知识库详情'
  })

  function openDetail(row: KnowledgeBaseRow): void {
    detailRow.value = row
    detailName.value = row.name
    pendingDetail.value = null
    detailOpen.value = true
  }

  async function saveDetailName(): Promise<void> {
    const row = detailRow.value
    const envId = props.environmentId
    const name = detailName.value.trim()
    if (!row || envId === null || isSavingName.value) return
    if (name === '') {
      ElMessage.warning('请填写名称')
      return
    }
    if ([...name].length > 128) {
      ElMessage.warning('名称最多 128 个字')
      return
    }
    if (name === row.name) {
      ElMessage.success(`已保存「${name}」`)
      return
    }
    isSavingName.value = true
    try {
      await knowledgeApi.rename(envId, row.id, name)
      ElMessage.success(`已保存「${name}」`)
      await load()
      const fresh = rows.value.find((item) => item.id === row.id)
      if (fresh) {
        detailRow.value = fresh
        detailName.value = fresh.name
      }
    } catch (error) {
      ElMessage.error(errorDisplayText(error, '改名没有成功，请稍后再试。'))
    } finally {
      isSavingName.value = false
    }
  }

  function closeDetail(): void {
    detailOpen.value = false
    detailRow.value = null
    pendingDetail.value = null
  }

  /** 待建库抽屉关掉即放弃暂存名称；它只在「加资料」流程里有用，不留不可见的重名占用。 */
  function onDetailClosed(): void {
    if (pendingDetail.value === null) return
    pendingDetail.value = null
    pendingKnowledgeBase.value = null
  }

  async function openFilePicker(): Promise<void> {
    if (props.environmentId === null) return
    filePickerOpen.value = true
    selectedFiles.value = []
    eligibleFiles.value = []
    filesNextBefore.value = null
    await loadFiles(null)
  }

  async function loadFiles(before: number | null): Promise<void> {
    const envId = props.environmentId
    if (envId === null) return
    const mark = identity.capture()
    const current = generation
    filesLoading.value = true
    try {
      const result = await knowledgeApi.eligibleFiles(envId, before ?? undefined)
      if (!alive || current !== generation || !identity.current(mark)) return
      eligibleFiles.value = before === null ? result.files : [...eligibleFiles.value, ...result.files]
      filesNextBefore.value = result.next_before
    } catch (error) {
      if (!alive || current !== generation || !identity.current(mark)) return
      ElMessage.error(errorDisplayText(error, '资料列表没能加载出来，请稍后再试。'))
    } finally {
      if (alive && current === generation) filesLoading.value = false
    }
  }

  function loadMoreFiles(): void {
    if (filesNextBefore.value !== null) void loadFiles(filesNextBefore.value)
  }

  function onFileSelection(items: KnowledgeSourceFile[]): void {
    selectedFiles.value = items.slice(0, MAX_FILES_PER_BATCH)
  }

  /** 待建库一次性建库+发布；后端随即自动开始索引。 */
  async function confirmAddMaterials(): Promise<void> {
    const envId = props.environmentId
    const pending = pendingKnowledgeBase.value
    if (envId === null || pending === null || selectedFiles.value.length === 0 || materializing.value) return
    materializing.value = true
    const mark = identity.capture()
    const current = generation
    try {
      const code = `kb-${Date.now().toString(36)}${Math.random().toString(36).slice(2, 8)}`
      const draft = await knowledgeApi.createDraft(
        {
          environment_id: envId,
          code,
          name: pending.name,
          description: pending.description,
          files: selectedFiles.value.map((file) => ({ id: file.id, sha256: file.sha256 })),
          replace_draft: false
        },
        crypto.randomUUID()
      )
      const published = await knowledgeApi.publish(
        envId,
        draft.knowledge_base.id,
        crypto.randomUUID(),
        draft.version.snapshot_sha256
      )
      if (!alive || current !== generation || !identity.current(mark)) return
      if ('state' in published && published.state === 'rejected') {
        ElMessage.warning('资料在整理期间有变化，请重新选择后再试')
        return
      }
      pendingKnowledgeBase.value = null
      filePickerOpen.value = false
      closeDetail()
      ElMessage.success(`已新建知识库「${pending.name}」`)
      await load()
      const created = rows.value.find((row) => row.id === draft.knowledge_base.id)
      if (created !== undefined) openDetail(created)
    } catch (error) {
      if (!alive || current !== generation || !identity.current(mark)) return
      ElMessage.error(errorDisplayText(error, '建库没有成功，请稍后再试。'))
    } finally {
      if (alive && current === generation) materializing.value = false
    }
  }

  async function retryIndex(row: KnowledgeBaseRow): Promise<void> {
    const envId = props.environmentId
    if (envId === null || retryingId.value !== null) return
    retryingId.value = row.id
    const mark = identity.capture()
    const current = generation
    try {
      const result: IndexRetryResult = await knowledgeApi.retryIndex(envId, row.id)
      if (!alive || current !== generation || !identity.current(mark)) return
      if (result.index_state === 'failed') {
        ElMessage.warning(result.index_error || '还是没有成功，可以再试一次')
      } else {
        ElMessage.success('已重新排队整理')
      }
      await load()
    } catch (error) {
      if (!alive || current !== generation || !identity.current(mark)) return
      ElMessage.error(errorDisplayText(error, '重试没有成功，请稍后再试。'))
    } finally {
      if (alive && current === generation) retryingId.value = null
    }
  }

  async function publishDraft(row: KnowledgeBaseRow): Promise<void> {
    const envId = props.environmentId
    const snapshot = row.draft_snapshot_sha256
    if (envId === null || typeof snapshot !== 'string' || snapshot === '' || publishingId.value !== null) return
    publishingId.value = row.id
    const mark = identity.capture()
    const current = generation
    try {
      const result = await knowledgeApi.publish(envId, row.id, crypto.randomUUID(), snapshot)
      if (!alive || current !== generation || !identity.current(mark)) return
      if ('state' in result && result.state === 'rejected') {
        ElMessage.warning('资料在整理期间有变化，请刷新后再试')
        await load()
        return
      }
      ElMessage.success('已发布，正在整理资料')
      await load()
    } catch (error) {
      if (!alive || current !== generation || !identity.current(mark)) return
      ElMessage.error(errorDisplayText(error, '发布没有成功，请稍后再试。'))
    } finally {
      if (alive && current === generation) publishingId.value = null
    }
  }

  async function confirmDisable(row: KnowledgeBaseRow): Promise<void> {
    const envId = props.environmentId
    if (envId === null || disablingId.value !== null) return
    try {
      await ElMessageBox.confirm(
        '停用后 AI 暂时不查它，资料保留。',
        `停用「${row.name}」？`,
        { confirmButtonText: '停用', cancelButtonText: '再想想', type: 'warning' }
      )
    } catch {
      return
    }
    disablingId.value = row.id
    const mark = identity.capture()
    const current = generation
    try {
      await knowledgeApi.disable(envId, row.id, crypto.randomUUID())
      if (!alive || current !== generation || !identity.current(mark)) return
      ElMessage.success('已停用')
      await load()
      closeDetail()
    } catch (error) {
      if (!alive || current !== generation || !identity.current(mark)) return
      ElMessage.error(errorDisplayText(error, '停用没有成功，请稍后再试。'))
    } finally {
      if (alive && current === generation) disablingId.value = null
    }
  }

  function knowledgeDeleteReason(error: unknown): string {
    const raw = error instanceof Error ? error.message : ''
    if (raw.includes('publication')) return '还有应用在用这个知识库，先解除后再删'
    if (raw.includes('vector') || raw.includes('sources')) return '资料引用还在，请稍后再试'
    return errorDisplayText(error, '删除没有成功，请稍后再试。')
  }

  async function confirmEnable(row: KnowledgeBaseRow): Promise<void> {
    const envId = props.environmentId
    if (envId === null || disablingId.value !== null || !row.active_version_id) return
    disablingId.value = row.id
    const mark = identity.capture()
    const current = generation
    try {
      await knowledgeApi.enable(envId, row.id, crypto.randomUUID())
      if (!alive || current !== generation || !identity.current(mark)) return
      ElMessage.success(`已启用「${row.name}」`)
      await load()
    } catch (error) {
      if (!alive || current !== generation || !identity.current(mark)) return
      ElMessage.error(errorDisplayText(error, '启用没有成功，请稍后再试。'))
    } finally {
      if (alive && current === generation) disablingId.value = null
    }
  }

  async function confirmRetire(row: KnowledgeBaseRow): Promise<void> {
    const envId = props.environmentId
    if (envId === null || row.state !== 'disabled' || disablingId.value !== null) return
    try {
      await ElMessageBox.confirm(
        '删除后不能恢复。用过的费用记录会保留。',
        `删除「${row.name}」？`,
        { confirmButtonText: '删除', cancelButtonText: '再想想', type: 'warning' }
      )
    } catch {
      return
    }
    disablingId.value = row.id
    const mark = identity.capture()
    const current = generation
    try {
      await knowledgeApi.purgeEmbeddingIndex(envId, row.id, crypto.randomUUID())
      await knowledgeApi.purgeSources(envId, row.id, crypto.randomUUID())
      await knowledgeApi.retire(envId, row.id, crypto.randomUUID())
      if (!alive || current !== generation || !identity.current(mark)) return
      ElMessage.success(`已删除「${row.name}」`)
      await load()
      closeDetail()
    } catch (error) {
      if (!alive || current !== generation || !identity.current(mark)) return
      ElMessage.error(knowledgeDeleteReason(error))
    } finally {
      if (alive && current === generation) disablingId.value = null
    }
  }

  function onRowCommand(command: unknown, row: KnowledgeBaseRow): void {
    if (command === 'publish') void publishDraft(row)
    else if (command === 'disable') void confirmDisable(row)
    else if (command === 'enable') void confirmEnable(row)
    else if (command === 'retire') void confirmRetire(row)
  }

  function openTrial(row: KnowledgeBaseRow): void {
    trialKnowledgeBase.value = row
    trialQuery.value = ''
    trialError.value = ''
    trialResult.value = null
    trialOpen.value = true
  }

  async function submitTrial(): Promise<void> {
    const knowledgeBase = trialKnowledgeBase.value
    const query = trialQuery.value.trim()
    if (knowledgeBase === null || query === '' || trialBusy.value) return
    trialBusy.value = true
    trialError.value = ''
    trialResult.value = null
    const mark = identity.capture()
    const current = generation
    try {
      const result = await requestKnowledgeTrial({ knowledgeBaseId: knowledgeBase.id, query, limit: 5 })
      if (!alive || current !== generation || !identity.current(mark)) return
      trialResult.value = result
    } catch (error) {
      if (!alive || current !== generation || !identity.current(mark)) return
      trialError.value = errorDisplayText(error, '试查没有成功，请稍后再试。')
    } finally {
      if (alive && current === generation) trialBusy.value = false
    }
  }

  watch(
    () => props.environmentId,
    () => {
      ++generation
      clearPoll()
      rows.value = []
      page.value = 1
      keyword.value = ''
      statusFilter.value = ''
      detailRow.value = null
      pendingDetail.value = null
      detailOpen.value = false
      pendingKnowledgeBase.value = null
      void load()
    },
    { immediate: true }
  )

  watch(needsPolling, () => schedulePoll())

  onMounted(() => {
    void loadAgentOptions()
  })

  onBeforeUnmount(() => {
    alive = false
    ++generation
    clearPoll()
  })

  defineExpose({ openCreate })
</script>

<style scoped lang="scss">
  .knowledge-tab {
    display: flex;
    flex-direction: column;
    gap: 16px;
  }

  .filter-bar {
    display: flex;
    gap: 12px;
    align-items: center;
  }

  .filter-search {
    width: 280px;
  }

  .filter-status {
    width: 200px;
  }

  .kb-table {
    width: 100%;
  }

  .cell-name {
    font-weight: 500;
    line-height: 1.4;
  }

  .cell-desc {
    margin-top: 2px;
    font-size: 12px;
    color: var(--el-text-color-secondary);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    max-width: 320px;
  }

  .cell-sub {
    margin-top: 2px;
    font-size: 12px;
    color: var(--el-text-color-secondary);
  }

  .cell-error {
    color: var(--el-color-danger);
  }

  .cell-muted {
    color: var(--el-text-color-secondary);
  }

  .pager {
    display: flex;
    justify-content: flex-end;
    margin-top: 8px;
  }

  .empty-wrap {
    text-align: center;
  }

  .empty-title {
    margin: 0;
    font-size: 15px;
    font-weight: 600;
  }

  .empty-desc {
    margin: 4px 0 12px;
    font-size: 13px;
    color: var(--el-text-color-secondary);
  }

  .audience-select {
    width: 100%;
  }

  .field-hint {
    margin-top: 4px;
    font-size: 12px;
    color: var(--el-text-color-secondary);
  }

  .detail-head {
    display: flex;
    align-items: center;
    gap: 8px;
  }

  .detail-version {
    font-size: 12px;
    color: var(--el-text-color-secondary);
  }

  .detail-desc {
    margin: 8px 0 0;
    font-size: 13px;
    color: var(--el-text-color-secondary);
  }

  .detail-alert {
    margin-top: 16px;
  }

  .detail-meta {
    margin: 16px 0 0;
    display: grid;
    grid-template-columns: 80px 1fr;
    row-gap: 8px;
    font-size: 13px;
  }

  .detail-meta dt {
    color: var(--el-text-color-secondary);
  }

  .detail-meta dd {
    margin: 0;
  }

  .detail-add {
    margin-top: 20px;
    width: 100%;
  }

  .detail-hint {
    margin-top: 12px;
    font-size: 12px;
    color: var(--el-text-color-secondary);
  }

  .detail-actions {
    margin-top: 20px;
    display: flex;
    gap: 8px;
  }

  .picker-alert {
    margin-bottom: 12px;
  }

  .picker-foot {
    margin-top: 8px;
    display: flex;
    justify-content: space-between;
    align-items: center;
  }

  .picker-count {
    font-size: 12px;
    color: var(--el-text-color-secondary);
  }

  .trial-hint {
    margin: 0 0 12px;
    font-size: 12px;
    color: var(--el-text-color-secondary);
  }

  .trial-alert {
    margin-bottom: 12px;
  }

  .trial-loading {
    padding: 8px 0;
  }

  .trial-empty {
    color: var(--el-text-color-secondary);
    font-size: 13px;
  }

  .trial-citations {
    margin: 0;
    padding-left: 20px;
    display: flex;
    flex-direction: column;
    gap: 10px;
  }

  .trial-excerpt {
    margin: 0;
    font-size: 13px;
    line-height: 1.6;
    white-space: pre-wrap;
  }
</style>
