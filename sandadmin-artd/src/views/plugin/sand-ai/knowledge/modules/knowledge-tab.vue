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
        <ElOption label="能用了" value="usable" />
        <ElOption label="整理中" value="indexing" />
        <ElOption label="整理失败" value="failed" />
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
        <ElTableColumn label="状态" width="140">
          <template #default="{ row }">
            <ElTooltip
              v-if="rowStatus(row) === 'failed' && row.index_error"
              :content="row.index_error"
              placement="top"
            >
              <ElTag :type="statusTagType(row)" effect="plain" size="small">
                {{ statusLabel(row) }}
              </ElTag>
            </ElTooltip>
            <ElTooltip
              v-else-if="rowStatus(row) === 'indexing'"
              content="正在整理，一般几分钟"
              placement="top"
            >
              <ElTag :type="statusTagType(row)" effect="plain" size="small">
                {{ statusLabel(row) }}
              </ElTag>
            </ElTooltip>
            <ElTag v-else :type="statusTagType(row)" effect="plain" size="small">
              {{ statusLabel(row) }}
            </ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn label="最近更新" width="110" align="center">
          <template #default="{ row }">
            <span class="cell-muted">{{ formatUpdateTime(row.update_time) }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn label="操作" width="200" fixed="right">
          <template #default="{ row }">
            <ElSpace :size="4" wrap>
              <ElButton link type="primary" @click="openDetail(row)">打开</ElButton>
              <ElButton
                link
                type="primary"
                :disabled="rowStatus(row) !== 'usable'"
                :title="trialHint(row)"
                @click="openTrial(row)"
              >试一试</ElButton>
              <ElButton
                v-if="rowStatus(row) === 'failed'"
                link
                type="primary"
                :loading="retryingId === row.id"
                @click="retryIndex(row)"
              >重试</ElButton>
              <ElDropdown trigger="click" @command="(cmd: string) => onRowCommand(cmd, row)">
                <ElButton link type="primary">更多</ElButton>
                <template #dropdown>
                  <ElDropdownMenu>
                    <ElDropdownItem v-if="rowStatus(row) === 'failed'" command="retry">重试</ElDropdownItem>
                    <ElDropdownItem v-if="row.state === 'draft'" command="publish">发布</ElDropdownItem>
                    <ElDropdownItem v-if="row.state === 'published'" command="disable">停用</ElDropdownItem>
                    <ElDropdownItem
                      v-if="row.state === 'disabled'"
                      command="enable"
                      :disabled="!row.active_version_id"
                    >{{ row.active_version_id ? '启用' : '启用（没有已发布的版本）' }}</ElDropdownItem>
                    <ElDropdownItem
                      v-if="row.state === 'disabled'"
                      command="retire"
                      divided
                    >删除</ElDropdownItem>
                    <ElDropdownItem v-else disabled divided>删除（先停用才能删）</ElDropdownItem>
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

    <!-- 新建知识库：一个抽屉里选名选资料，一步建好 -->
    <ElDrawer
      v-model="createOpen"
      title="新建知识库"
      size="min(560px, 94vw)"
      :close-on-click-modal="false"
      :before-close="confirmCreateClose"
      destroy-on-close
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
        <ElFormItem label="资料" required>
          <div class="create-files">
            <p v-if="!filesLoading && eligibleFiles.length === 0" class="create-files-empty">
              还没有能用的文件，先去<RouterLink to="/sand-ai/file" class="create-files-link">「文件」</RouterLink>上传一份
            </p>
            <ElTable
              v-if="eligibleFiles.length > 0 || filesLoading"
              v-loading="filesLoading"
              :data="eligibleFiles"
              size="default"
              row-key="id"
              max-height="320"
              @selection-change="onCreateFileSelection"
            >
              <ElTableColumn type="selection" width="46" :selectable="() => createSelectedFiles.length < MAX_FILES_PER_BATCH" />
              <ElTableColumn prop="name" label="文件名" min-width="200" show-overflow-tooltip />
            </ElTable>
            <div v-if="eligibleFiles.length > 0" class="picker-foot">
              <span class="picker-count">已选 {{ createSelectedFiles.length }} / {{ MAX_FILES_PER_BATCH }} 份</span>
              <ElButton v-if="filesNextBefore !== null" link type="primary" :loading="filesLoading" @click="loadMoreFiles">
                加载更多文件
              </ElButton>
            </div>
            <p v-if="createSelectedFiles.length === 0" class="create-hint">至少选一份资料</p>
          </div>
        </ElFormItem>
      </ElForm>
      <template #footer>
        <ElButton @click="onCancelCreate">取消</ElButton>
        <ElButton
          type="primary"
          :disabled="createForm.name.trim() === '' || createSelectedFiles.length === 0"
          :loading="creating"
          @click="submitCreate"
        >创建</ElButton>
      </template>
    </ElDrawer>

    <!-- 详情抽屉 -->
    <ElDrawer
      v-model="detailOpen"
      size="min(560px, 94vw)"
      :title="detailTitle"
      destroy-on-close
    >
      <template v-if="detailRow">
        <div class="detail-head">
          <ElTag :type="statusTagType(detailRow)" effect="plain" size="small">
            {{ statusLabel(detailRow) }}
          </ElTag>
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
          :title="detailRow.index_error || '整理失败'"
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
          <template v-if="detailRow.draft_file_count !== null">
            <dt>待发布资料</dt>
            <dd>{{ detailRow.draft_file_count }} 份</dd>
          </template>
        </dl>

        <div class="detail-actions">
          <ElButton type="primary" :loading="isSavingName" :disabled="isSavingName" @click="saveDetailName">保存</ElButton>
          <ElButton
            v-if="detailRow.state === 'draft'"
            :loading="materializing"
            @click="openFilePicker"
          >加资料</ElButton>
          <ElButton
            v-if="rowStatus(detailRow) === 'usable'"
            @click="openTrial(detailRow)"
          >试一试</ElButton>
          <ElButton
            v-if="detailRow.state === 'draft'"
            :loading="publishingId === detailRow.id"
            @click="publishDraft(detailRow)"
          >发布</ElButton>
          <ElButton
            v-if="detailRow.state === 'published'"
            @click="confirmDisable(detailRow)"
          >停用</ElButton>
          <ElButton
            v-if="detailRow.state === 'disabled'"
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
        <p v-if="detailRow.state === 'draft'" class="detail-hint">还没发布，点「加资料」选好资料后会直接发布并开始整理。</p>
        <p v-else class="detail-hint">资料在建库时一次选定；需要更换资料请新建知识库。</p>
      </template>
    </ElDrawer>

    <!-- 加资料：从能用的文件里选，最多 20 份 -->
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
        title="选择能用的文件，一次最多 20 份。"
      />
      <ElTable
        v-loading="filesLoading"
        :data="eligibleFiles"
        size="default"
        row-key="id"
        max-height="360"
        empty-text="还没有能用的文件"
        @selection-change="onFileSelection"
      >
        <ElTableColumn type="selection" width="46" :selectable="() => selectedFiles.length < MAX_FILES_PER_BATCH" />
        <ElTableColumn prop="name" label="文件名" min-width="220" show-overflow-tooltip />
        <ElTableColumn label="状态" width="80" align="center">
          <template #default>
            <ElTag type="success" size="small" effect="plain">能用了</ElTag>
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

    <!-- 试一试：只有「可用」才进得来；走托管试用身份，不用填密钥 -->
    <ElDialog
      v-model="trialOpen"
      :title="trialKnowledgeBase ? `试一试「${trialKnowledgeBase.name}」` : '试一试'"
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
  import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue'
  import { ElMessage, ElMessageBox } from 'element-plus'
  import { errorDisplayText } from '../../api/errorText'
  import { confirmDeletion, DELETION_CANCEL_CLASS } from '../../composables/confirmDeletion'
  import { formatServerMinute } from '../../api/localTime'
  import knowledgeApi, {
    type KnowledgeBaseRow,
    type IndexRetryResult,
    type KnowledgeSourceFile
  } from '../../api/knowledgeBase'
  import { requestKnowledgeTrial, type KnowledgeTrialResult } from '../../api/adminTrial'
  import { useAdminReadIdentity } from '../../composables/useAdminReadIdentity'

  const props = defineProps<{ environmentId: number | null }>()

  const PAGE_SIZE = 20
  const POLL_MS = 4000
  const MAX_FILES_PER_BATCH = 20

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

  const createOpen = ref(false)
  const creating = ref(false)
  const createForm = reactive({ name: '', description: '' })
  const nameError = ref('')
  const createSelectedFiles = ref<KnowledgeSourceFile[]>([])

  const detailOpen = ref(false)
  const detailRow = ref<KnowledgeBaseRow | null>(null)
  const detailName = ref('')
  const isSavingName = ref(false)

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
      case 'usable': return '能用了'
      case 'indexing': return '整理中'
      case 'failed': return '整理失败'
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

  /** 「最近更新」一行显示到分钟；今年省略年份，统一换算到浏览器本地时间。 */
  function formatUpdateTime(value: string | null | undefined): string {
    return formatServerMinute(value)
  }

  /** 不可用行也保留「试一试」位置，悬停说明为什么不能点，保证每行操作列对齐。 */
  function trialHint(row: KnowledgeBaseRow): string {
    switch (rowStatus(row)) {
      case 'usable': return ''
      case 'indexing': return '整理好才能试'
      case 'failed': return '整理失败的不能试，先点「重试」'
      case 'draft': return '还没发布的不能试'
      default: return '已停用的不能试'
    }
  }

  /** 资料数：后端已按草稿优先、已发布兜底投影文件份数 material_file_count；无资料显示 —。 */
  function materialCount(row: KnowledgeBaseRow): string {
    return typeof row.material_file_count === 'number' && row.material_file_count > 0 ? `${row.material_file_count} 份` : '—'
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
    createSelectedFiles.value = []
    nameError.value = ''
    eligibleFiles.value = []
    filesNextBefore.value = null
    createOpen.value = true
    void loadFiles(null)
  }

  function nameTaken(name: string): boolean {
    const target = name.trim()
    if (target === '') return false
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

  /** 一步建库：code 在前端生成（小写 base36，后端会再小写规范化），幂等键绑定本次提交。 */
  async function submitCreate(): Promise<void> {
    const envId = props.environmentId
    if (envId === null || creating.value) return
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
    if (createSelectedFiles.value.length === 0) return
    creating.value = true
    const mark = identity.capture()
    const current = generation
    try {
      const result = await knowledgeApi.create(
        {
          environment_id: envId,
          code: `kb-${Date.now().toString(36)}${Math.random().toString(36).slice(2, 8)}`,
          name,
          description: createForm.description.trim(),
          files: createSelectedFiles.value.map((file) => ({ id: file.id, sha256: file.sha256 }))
        },
        crypto.randomUUID()
      )
      if (!alive || current !== generation || !identity.current(mark)) return
      createOpen.value = false
      createForm.name = ''
      createForm.description = ''
      createSelectedFiles.value = []
      ElMessage.success(`已新建知识库「${result.knowledge_base.name}」，正在整理`)
      await load()
    } catch (error) {
      if (!alive || current !== generation || !identity.current(mark)) return
      ElMessage.error(errorDisplayText(error, '建库没有成功，请稍后再试。'))
    } finally {
      if (alive && current === generation) creating.value = false
    }
  }

  /** 抽屉关闭前：有未提交的名称或已选资料先问一句，防止误关丢掉已填内容。 */
  function confirmCreateClose(done: () => void): void {
    if (createForm.name.trim() === '' && createSelectedFiles.value.length === 0) {
      done()
      return
    }
    void ElMessageBox.confirm('关掉后，刚才填的内容不会保留。', '还没创建，确定不要了？', {
      confirmButtonText: '不要了',
      confirmButtonClass: 'el-button--danger',
      cancelButtonText: '继续填',
      cancelButtonClass: DELETION_CANCEL_CLASS
    }).then(() => done()).catch(() => { /* 用户选择继续编辑 */ })
  }

  function onCancelCreate(): void {
    confirmCreateClose(() => { createOpen.value = false })
  }

  function onCreateFileSelection(items: KnowledgeSourceFile[]): void {
    createSelectedFiles.value = items.slice(0, MAX_FILES_PER_BATCH)
  }

  const detailTitle = computed(() => detailRow.value?.name ?? '知识库详情')

  function openDetail(row: KnowledgeBaseRow): void {
    detailRow.value = row
    detailName.value = row.name
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
      detailOpen.value = false
      return
    }
    isSavingName.value = true
    try {
      await knowledgeApi.rename(envId, row.id, name)
      ElMessage.success(`已保存「${name}」`)
      detailOpen.value = false
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
  }

  async function openFilePicker(): Promise<void> {
    if (props.environmentId === null || detailRow.value === null || detailRow.value.state !== 'draft') return
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

  /** 未发布的库在详情里补齐资料：沿用既有草稿+发布两步，发完即开始整理。 */
  async function confirmAddMaterials(): Promise<void> {
    const envId = props.environmentId
    const row = detailRow.value
    if (envId === null || row === null || row.state !== 'draft'
      || selectedFiles.value.length === 0 || materializing.value) return
    materializing.value = true
    const mark = identity.capture()
    const current = generation
    try {
      const draft = await knowledgeApi.createDraft(
        {
          environment_id: envId,
          code: row.code,
          name: row.name,
          description: row.description ?? '',
          files: selectedFiles.value.map((file) => ({ id: file.id, sha256: file.sha256 })),
          replace_draft: true
        },
        crypto.randomUUID(),
        row.id
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
      filePickerOpen.value = false
      selectedFiles.value = []
      ElMessage.success('已加好资料，正在整理')
      await load()
      const fresh = rows.value.find((item) => item.id === row.id)
      if (fresh !== undefined) detailRow.value = fresh
    } catch (error) {
      if (!alive || current !== generation || !identity.current(mark)) return
      ElMessage.error(errorDisplayText(error, '加资料没有成功，请稍后再试。'))
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

  // 后端中文原因原样展示；未翻译的错误码由 errorDisplayText 按系统故障带编号兜底。
  function knowledgeDeleteReason(error: unknown): string {
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
      await confirmDeletion('删除后不能恢复。用过的费用记录会保留。', `删除「${row.name}」？`)
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
    else if (command === 'retry') void retryIndex(row)
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
      detailOpen.value = false
      createOpen.value = false
      void load()
    },
    { immediate: true }
  )

  watch(needsPolling, () => schedulePoll())

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

  .create-files {
    width: 100%;
  }

  .create-files-empty {
    margin: 0;
    font-size: 13px;
    color: var(--el-text-color-secondary);
  }

  .create-files-link {
    color: var(--el-color-primary);
    text-decoration: none;
  }

  .create-hint {
    margin: 6px 0 0;
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
