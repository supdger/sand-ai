<template>
  <div>
    <ExpertSelectionHelp />
    <KnowledgeBasePanel :key="userStore.info.id" />
    <PublicationPanel :key="userStore.info.id" />
    <ConsumerSkillPanel :key="userStore.info.id" />
    <ElAlert type="info" :closable="false"
      title="下方为旧环境 Skill 配置。个人、团队或组织消费范围的方法包，请使用上方“应用方法包”入口。" />
    <SkillPanel :key="userStore.info.id" />
    <ElCard class="art-table-card" shadow="never">
      <div class="know-header">
        <div>
          <h2 class="know-title">检索 / 知识</h2>
          <p class="know-desc">
            消费契约 1.7：仅检索当前环境已解析私有文件的来源块。返回定位与短摘录，不暴露 bucket/object key。
          </p>
        </div>
        <ElTag type="success" effect="plain">API 1.7 · retrieval</ElTag>
      </div>

      <ElForm label-width="110px" @submit.prevent>
        <RuntimeApiKeyField
          v-model="apiKey"
          placeholder="当前应用的 SandIAM 短期运行身份"
        />
        <p class="know-footnote">需 sand_ai.retrieval.search 服务授权；来源文件仍按当前应用的访问权限核验。</p>
        <ElFormItem label="查询">
          <ElSpace wrap>
            <ElInput
              v-model="query"
              clearable
              placeholder="关键词，≤500 字"
              style="width: 420px"
              @keyup.enter="handleSearch"
            />
            <ElInputNumber v-model="limit" :min="1" :max="20" :controls="false" />
            <ElButton type="primary" :loading="busy" @click="handleSearch">检索</ElButton>
          </ElSpace>
        </ElFormItem>
        <ElFormItem>
          <ElSpace wrap>
            <ElButton
              type="primary"
              plain
              :disabled="selectedIds.length === 0"
              @click="() => goAiTask()"
            >
              用选中来源提交 AI（{{ selectedIds.length }}）
            </ElButton>
            <ElButton :disabled="selectedIds.length === 0" @click="selectedIds = []">清空选择</ElButton>
          </ElSpace>
        </ElFormItem>
      </ElForm>

      <ElAlert
        v-if="lastError"
        class="know-alert"
        type="error"
        :closable="true"
        :title="lastError.title"
        :description="lastError.description"
        @close="lastError = null"
      />

      <ArtTable
        row-key="source_block_id"
        :loading="busy"
        :data="matches"
        :columns="columns"
        @selection-change="onSelectionChange"
      />
      <p v-if="searchedQuery" class="know-footnote">
        query={{ searchedQuery }} · hits={{ matches.length }}
      </p>
    </ElCard>
  </div>
</template>

<script setup lang="ts">
  import ExpertSelectionHelp from '../components/ExpertSelectionHelp.vue'
  import { h, onMounted, ref, watch } from 'vue'
  import { useRouter } from 'vue-router'
  import { useUserStore } from '@/store/modules/user'
  import { ElButton, ElMessage } from 'element-plus'
  import type { ColumnOption } from '@/types'
  import { readRuntimeBearer } from '../api/runtimeBearerSession'
  import retrievalApi from '../api/retrieval'
  import { toRuntimeUiError } from '../api/runtimeErrors'
  import type { RuntimeUiError } from '../api/runtimeErrors'
  import type { SandAiRetrievalMatch } from '../api/types'
  import RuntimeApiKeyField from '../components/RuntimeApiKeyField.vue'
  import PublicationPanel from './modules/publication-panel.vue'
  import KnowledgeBasePanel from './modules/knowledge-base-panel.vue'
  import SkillPanel from './modules/skill-panel.vue'
  import ConsumerSkillPanel from './modules/consumer-skill-panel.vue'

  const router = useRouter()
  const userStore = useUserStore()
  let viewGeneration = 0
  const apiKey = ref('')
  const query = ref('')
  const limit = ref(10)
  const busy = ref(false)
  const matches = ref<SandAiRetrievalMatch[]>([])
  const searchedQuery = ref('')
  const lastError = ref<RuntimeUiError | null>(null)
  const selectedIds = ref<number[]>([])

  const columns: ColumnOption<SandAiRetrievalMatch>[] = [
    { type: 'selection', width: 48 },
    { prop: 'source_block_id', label: 'block', width: 90, align: 'center' },
    { prop: 'file_id', label: 'file', width: 90 },
    { prop: 'locator_type', label: 'locator_type', width: 120 },
    {
      prop: 'locator',
      label: 'locator',
      minWidth: 160,
      formatter: (row) => JSON.stringify(row.locator)
    },
    { prop: 'score', label: 'score', width: 100 },
    { prop: 'excerpt', label: 'excerpt', minWidth: 280 },
    {
      prop: 'source_block_id',
      label: '操作',
      width: 110,
      fixed: 'right',
      formatter: (row) =>
        h(
          ElButton,
          {
            size: 'small',
            link: true,
            type: 'primary',
            onClick: () => goAiTask([row.source_block_id])
          },
          () => '去 AI'
        )
    }
  ]

  function onSelectionChange(rows: SandAiRetrievalMatch[]): void {
    selectedIds.value = rows.map((row) => row.source_block_id)
  }

  function goAiTask(ids?: number[]): void {
    const sourceIds = ids && ids.length > 0 ? ids : selectedIds.value
    if (sourceIds.length === 0) {
      ElMessage.warning('请先选择来源块')
      return
    }
    void router.push({
      path: '/sand-ai/task',
      query: {
        source_block_ids: sourceIds.join(','),
        q: searchedQuery.value || query.value.trim() || undefined
      }
    })
  }

  function toUiError(error: unknown): RuntimeUiError {
    return toRuntimeUiError(error)
  }

  async function handleSearch(): Promise<void> {
    if (!query.value.trim()) {
      ElMessage.warning('请输入查询词')
      return
    }
    busy.value = true
    lastError.value = null
    const current = viewGeneration
    try {
      const result = await retrievalApi.search(apiKey.value, query.value.trim(), limit.value)
      if (current !== viewGeneration) return
      matches.value = result.matches
      searchedQuery.value = result.query
    } catch (error) {
      if (current !== viewGeneration) return
      lastError.value = toUiError(error)
      matches.value = []
    } finally {
      if (current === viewGeneration) busy.value = false
    }
  }

  watch(apiKey, () => {
    ++viewGeneration
    matches.value = []
    selectedIds.value = []
    searchedQuery.value = ''
    lastError.value = null
    busy.value = false
  }, { flush: 'sync' })

  onMounted(() => {
    apiKey.value = readRuntimeBearer()
  })
</script>

<style scoped lang="scss">
  .know-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 16px;
  }

  .know-title {
    margin: 0;
    font-size: 18px;
    font-weight: 600;
  }

  .know-desc {
    margin: 4px 0 0;
    font-size: 13px;
    color: var(--el-text-color-secondary);
    max-width: 760px;
  }

  .know-alert {
    margin-bottom: 16px;
  }

  .know-footnote {
    margin: 12px 0 0;
    font-size: 12px;
    color: var(--el-text-color-secondary);
  }
</style>
