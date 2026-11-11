<template>
  <div class="skill-tab">
    <ElSkeleton v-if="firstLoading" :rows="6" animated />

    <ElAlert
      v-else-if="loadError"
      type="error"
      :closable="false"
      title="技能模板没能加载出来"
      :description="loadError"
    >
      <template #default>
        <ElButton size="small" type="primary" @click="load">重新加载</ElButton>
      </template>
    </ElAlert>

    <ElEmpty v-else-if="environmentId === null" description="请先在上方选择环境" />

    <ElEmpty v-else-if="rows.length === 0 && page === 1" description="还没有技能模板">
      <template #description>
        <div class="empty-wrap">
          <p class="empty-title">还没有技能模板</p>
          <p class="empty-desc">技能模板是一套写好的提问方法，比如「起草通知」</p>
        </div>
      </template>
      <ElButton type="primary" @click="openCreate">新建技能模板</ElButton>
    </ElEmpty>

    <template v-else>
      <ElTable
        v-loading="refreshing"
        :data="rows"
        size="default"
        row-key="id"
        class="skill-table"
        empty-text="没有找到技能模板"
      >
        <ElTableColumn prop="name" label="名称" min-width="180" show-overflow-tooltip />
        <ElTableColumn label="用在哪里" min-width="160" show-overflow-tooltip>
          <template #default="{ row }">{{ usedByLabel(row) }}</template>
        </ElTableColumn>
        <ElTableColumn label="状态" width="110">
          <template #default="{ row }">
            <ElTag :type="skillTagType(row)" effect="plain" size="small">
              {{ skillStatusLabel(row) }}
            </ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn label="操作" width="180" fixed="right">
          <template #default="{ row }">
            <ElSpace :size="4">
              <ElButton link type="primary" @click="openEdit(row)">编辑</ElButton>
              <ElDropdown v-if="row.state === 'published' && row.status === 1" trigger="click"
                @command="(cmd: unknown) => onRowCommand(cmd, row)">
                <ElButton link type="primary">更多</ElButton>
                <template #dropdown>
                  <ElDropdownMenu>
                    <ElDropdownItem command="disable">停用</ElDropdownItem>
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
          :page-size="PAGE_SIZE"
          :total="total"
          layout="total, prev, pager, next"
          background
        />
      </div>
    </template>

    <!-- 新建 / 编辑技能模板抽屉：对外只露 3 个字段，创建时多一个绑定资料 -->
    <ElDrawer
      v-model="drawerOpen"
      size="min(520px, 94vw)"
      :title="editingSkill === null ? '新建技能模板' : `编辑「${editingSkill.name}」`"
      destroy-on-close
    >
      <ElForm label-width="110px" @submit.prevent>
        <ElFormItem label="名称" required :error="formError.name">
          <ElInput v-model="form.name" maxlength="30" show-word-limit placeholder="例如：起草通知" />
        </ElFormItem>
        <ElFormItem v-if="editingSkill === null" label="绑定资料" required :error="formError.source">
          <ElSelect
            v-model="form.sourceKey"
            filterable
            placeholder="选择一份已发布的资料"
            class="source-select"
            :loading="sourcesLoading"
          >
            <ElOption
              v-for="item in sources"
              :key="`${item.knowledge_base_version_id}:${item.snapshot_sha256}`"
              :label="`${item.name} · 第 ${item.version} 版`"
              :value="`${item.knowledge_base_version_id}:${item.snapshot_sha256}`"
            />
          </ElSelect>
          <div class="field-hint">技能模板回答问题时会查这份资料；先在「知识库」页准备好并发布。</div>
        </ElFormItem>
        <ElFormItem label="什么时候用" required :error="formError.description">
          <ElInput
            v-model="form.description"
            maxlength="100"
            show-word-limit
            placeholder="一句话说明它适合回答什么问题"
          />
        </ElFormItem>
        <ElFormItem label="提问写法" required :error="formError.instruction">
          <ElInput
            v-model="form.instruction"
            type="textarea"
            :rows="6"
            maxlength="4000"
            show-word-limit
            placeholder="写清楚 AI 收到问题后怎么组织回答"
          />
          <div class="field-hint">
            可以插入
            <ElButton link type="primary" size="small" @click="insertPlaceholder">{用户的问题}</ElButton>
            占位，回答时会替换成实际问题。示例：「请根据资料回答{用户的问题}，引用原文出处，不确定就直说不知道。」
          </div>
        </ElFormItem>
      </ElForm>

      <ElAlert v-if="formError.general" type="error" :closable="false" :title="formError.general" class="form-alert" />

      <template #footer>
        <ElButton @click="drawerOpen = false">取消</ElButton>
        <ElButton type="primary" :loading="saving" @click="submit">保存并启用</ElButton>
      </template>
    </ElDrawer>
  </div>
</template>

<script setup lang="ts">
  import { onBeforeUnmount, reactive, ref, watch } from 'vue'
  import { ElMessage, ElMessageBox } from 'element-plus'
  import { errorDisplayText } from '../../api/errorText'
  import skillApi, {
    type SkillDetail,
    type SkillOperation,
    type SkillRow,
    type SkillSource,
    type SkillVersion
  } from '../../api/skill'
  import knowledgeApi from '../../api/knowledgeBase'
  import { useAdminReadIdentity } from '../../composables/useAdminReadIdentity'

  const props = defineProps<{ environmentId: number | null }>()

  const PAGE_SIZE = 20

  const DEFAULT_INPUT_SCHEMA: Record<string, unknown> = {
    type: 'object',
    properties: { query: { type: 'string' } },
    required: ['query'],
    additionalProperties: false
  }
  const DEFAULT_OUTPUT_SCHEMA: Record<string, unknown> = {
    type: 'object',
    properties: { answer: { type: 'string' } },
    required: ['answer'],
    additionalProperties: false
  }

  const identity = useAdminReadIdentity()

  const rows = ref<SkillRow[]>([])
  const total = ref(0)
  const page = ref(1)
  const firstLoading = ref(false)
  const refreshing = ref(false)
  const loadError = ref('')

  // 「用在哪里」：技能绑定的知识库版本 → 知识库名。按当前页逐个读详情，失败显示 —。
  const boundVersions = ref<Map<number, number>>(new Map())
  const versionKnowledgeNames = ref<Map<number, string>>(new Map())

  const drawerOpen = ref(false)
  const editingSkill = ref<SkillRow | null>(null)
  const editingVersion = ref<SkillVersion | null>(null)
  const saving = ref(false)
  const form = reactive({ name: '', description: '', instruction: '', sourceKey: '' })
  const formError = reactive({ name: '', description: '', instruction: '', source: '', general: '' })

  const sources = ref<SkillSource[]>([])
  const sourcesLoading = ref(false)

  const revokingId = ref<number | null>(null)

  let alive = true
  let generation = 0

  function skillStatusLabel(row: SkillRow): string {
    if (row.status !== 1 || row.state === 'revoked') return '已停用'
    if (row.state === 'draft') return '未发布'
    return '已启用'
  }

  function skillTagType(row: SkillRow): 'success' | 'info' {
    if (row.status !== 1 || row.state === 'revoked') return 'info'
    if (row.state === 'draft') return 'info'
    return 'success'
  }

  function usedByLabel(row: SkillRow): string {
    const versionId = boundVersions.value.get(row.id)
    if (versionId === undefined) return '—'
    return versionKnowledgeNames.value.get(versionId) ?? '—'
  }

  async function load(): Promise<void> {
    const envId = props.environmentId
    if (envId === null) {
      rows.value = []
      total.value = 0
      return
    }
    const mark = identity.capture()
    const current = ++generation
    firstLoading.value = rows.value.length === 0
    refreshing.value = !firstLoading.value
    loadError.value = ''
    try {
      const result = await skillApi.list(envId, page.value)
      if (!alive || current !== generation || !identity.current(mark)) return
      rows.value = result.data
      total.value = result.total
      void resolveBindings(envId, result.data, mark, current)
    } catch (error) {
      if (!alive || current !== generation || !identity.current(mark)) return
      loadError.value = errorDisplayText(error, '技能模板没能加载出来，请稍后再试。')
      rows.value = []
      total.value = 0
    } finally {
      if (alive && current === generation) {
        firstLoading.value = false
        refreshing.value = false
      }
    }
  }

  /** 解析每个技能当前生效版本绑定的资料名；全部失败也不影响列表。 */
  async function resolveBindings(
    envId: number,
    list: SkillRow[],
    mark: ReturnType<typeof identity.capture>,
    current: number
  ): Promise<void> {
    try {
      // 知识库版本号 → 名称
      const nameMap = new Map<number, string>()
      for (let knowledgePage = 1; knowledgePage <= 10; knowledgePage++) {
        const result = await knowledgeApi.list(envId, knowledgePage, 100)
        for (const knowledgeBase of result.data) {
          if (knowledgeBase.active_version_id !== null) nameMap.set(knowledgeBase.active_version_id, knowledgeBase.name)
        }
        if (result.data.length === 0 || knowledgePage * 100 >= result.total) break
      }
      const versionMap = new Map<number, number>()
      await Promise.allSettled(
        list.map(async (skill) => {
          const detail = await skillApi.read(envId, skill.id)
          const version = pickActiveVersion(detail)
          if (version !== null) versionMap.set(skill.id, version.knowledge_base_version_id)
        })
      )
      if (!alive || current !== generation || !identity.current(mark)) return
      versionKnowledgeNames.value = nameMap
      boundVersions.value = versionMap
    } catch {
      // 解析失败保持 —，不打扰列表
    }
  }

  function pickActiveVersion(detail: SkillDetail): SkillVersion | null {
    const published = detail.versions.filter((item) => item.state === 'published')
    if (published.length > 0) {
      return published.reduce((a, b) => (a.version >= b.version ? a : b))
    }
    if (detail.versions.length === 0) return null
    return detail.versions.reduce((a, b) => (a.version >= b.version ? a : b))
  }

  function openCreate(): void {
    editingSkill.value = null
    editingVersion.value = null
    form.name = ''
    form.description = ''
    form.instruction = ''
    form.sourceKey = ''
    clearFormError()
    drawerOpen.value = true
    void loadSources()
  }

  async function openEdit(row: SkillRow): Promise<void> {
    const envId = props.environmentId
    if (envId === null) return
    const mark = identity.capture()
    const current = generation
    try {
      const detail = await skillApi.read(envId, row.id)
      if (!alive || current !== generation || !identity.current(mark)) return
      const version = pickActiveVersion(detail)
      if (version === null) {
        ElMessage.warning('这个技能模板还没有可编辑的版本')
        return
      }
      editingSkill.value = row
      editingVersion.value = version
      form.name = version.name
      form.description = version.description
      form.instruction = version.instruction
      form.sourceKey = ''
      clearFormError()
      drawerOpen.value = true
    } catch (error) {
      if (!alive || current !== generation || !identity.current(mark)) return
      ElMessage.error(errorDisplayText(error, '技能模板详情没能加载出来，请稍后再试。'))
    }
  }

  async function loadSources(): Promise<void> {
    const envId = props.environmentId
    if (envId === null) return
    sourcesLoading.value = true
    try {
      const result = await skillApi.sources(envId, 1)
      if (!alive) return
      sources.value = result.data
    } catch {
      sources.value = []
    } finally {
      if (alive) sourcesLoading.value = false
    }
  }

  function insertPlaceholder(): void {
    form.instruction = `${form.instruction}{用户的问题}`
  }

  function clearFormError(): void {
    formError.name = ''
    formError.description = ''
    formError.instruction = ''
    formError.source = ''
    formError.general = ''
  }

  function validateForm(): boolean {
    clearFormError()
    let ok = true
    if (form.name.trim() === '') {
      formError.name = '请填写名称'
      ok = false
    } else if (form.name.trim().length > 30) {
      formError.name = '名称最多 30 个字'
      ok = false
    }
    if (form.description.trim() === '') {
      formError.description = '请填写什么时候用'
      ok = false
    }
    if (form.instruction.trim() === '') {
      formError.instruction = '请填写提问写法'
      ok = false
    }
    if (editingSkill.value === null && form.sourceKey === '') {
      formError.source = '请选择绑定资料'
      ok = false
    }
    return ok
  }

  /** 组装后端需要的完整草稿体：编辑沿用原版本的结构与绑定，新建用默认结构。 */
  function buildDraftBody(): Record<string, unknown> | null {
    const version = editingVersion.value
    let reference: { knowledge_base_version_id: number; snapshot_sha256: string } | null = null
    let inputSchema: Record<string, unknown> = DEFAULT_INPUT_SCHEMA
    let outputSchema: Record<string, unknown> = DEFAULT_OUTPUT_SCHEMA
    let reviewPolicy: Record<string, unknown> = { review_required: false }

    if (editingSkill.value !== null && version !== null) {
      reference = {
        knowledge_base_version_id: version.knowledge_base_version_id,
        snapshot_sha256: version.snapshot_sha256
      }
      inputSchema = version.input_schema
      outputSchema = version.output_schema
      reviewPolicy = { review_required: version.review_policy.review_required }
    } else {
      const source = sources.value.find(
        (item) => `${item.knowledge_base_version_id}:${item.snapshot_sha256}` === form.sourceKey
      )
      if (source === undefined) {
        formError.source = '请选择绑定资料'
        return null
      }
      reference = {
        knowledge_base_version_id: source.knowledge_base_version_id,
        snapshot_sha256: source.snapshot_sha256
      }
    }

    const body: Record<string, unknown> = {
      ...(editingSkill.value !== null ? { code: editingSkill.value.code } : {}),
      名称: form.name.trim(),
      描述: form.description.trim(),
      指令: form.instruction.trim(),
      输入结构: inputSchema,
      输出结构: outputSchema,
      复核策略: reviewPolicy,
      知识库版本引用: reference
    }
    return body
  }

  async function submit(): Promise<void> {
    const envId = props.environmentId
    if (envId === null || saving.value) return
    if (!validateForm()) return
    const body = buildDraftBody()
    if (body === null) return
    saving.value = true
    formError.general = ''
    const mark = identity.capture()
    const current = generation
    try {
      const draft: SkillOperation = await skillApi.createDraft(envId, body, crypto.randomUUID())
      if (!alive || current !== generation || !identity.current(mark)) return
      // 草稿建好后直接发布，让模板马上能用
      await skillApi.publish(envId, draft.skill_id, crypto.randomUUID())
      if (!alive || current !== generation || !identity.current(mark)) return
      drawerOpen.value = false
      ElMessage.success('已保存并启用')
      await load()
    } catch (error) {
      if (!alive || current !== generation || !identity.current(mark)) return
      formError.general = errorDisplayText(error, '保存没有成功，请稍后再试。')
    } finally {
      if (alive && current === generation) saving.value = false
    }
  }

  async function confirmDisable(row: SkillRow): Promise<void> {
    const envId = props.environmentId
    if (envId === null || revokingId.value !== null) return
    try {
      await ElMessageBox.confirm(
        '停用后暂时不能被使用，资料和提问写法都保留。',
        `停用「${row.name}」？`,
        { confirmButtonText: '停用', cancelButtonText: '再想想', type: 'warning' }
      )
    } catch {
      return
    }
    revokingId.value = row.id
    const mark = identity.capture()
    const current = generation
    try {
      await skillApi.revoke(envId, row.id, crypto.randomUUID())
      if (!alive || current !== generation || !identity.current(mark)) return
      ElMessage.success('已停用')
      await load()
    } catch (error) {
      if (!alive || current !== generation || !identity.current(mark)) return
      ElMessage.error(errorDisplayText(error, '停用没有成功，请稍后再试。'))
    } finally {
      if (alive && current === generation) revokingId.value = null
    }
  }

  function onRowCommand(command: unknown, row: SkillRow): void {
    if (command === 'disable') void confirmDisable(row)
  }

  watch(
    () => props.environmentId,
    () => {
      ++generation
      rows.value = []
      total.value = 0
      page.value = 1
      boundVersions.value = new Map()
      versionKnowledgeNames.value = new Map()
      void load()
    },
    { immediate: true }
  )

  onBeforeUnmount(() => {
    alive = false
    ++generation
  })

  defineExpose({ openCreate })
</script>

<style scoped lang="scss">
  .skill-tab {
    display: flex;
    flex-direction: column;
    gap: 16px;
  }

  .skill-table {
    width: 100%;
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

  .source-select {
    width: 100%;
  }

  .field-hint {
    margin-top: 4px;
    font-size: 12px;
    color: var(--el-text-color-secondary);
    line-height: 1.6;
  }

  .form-alert {
    margin-top: 8px;
  }
</style>
