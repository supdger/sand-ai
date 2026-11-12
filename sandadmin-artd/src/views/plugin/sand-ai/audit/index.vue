<template>
  <SandAiErrorBoundary>
    <div class="sand-ai-page record-page">
      <header class="page-head">
        <div>
          <h1 class="page-title">审计</h1>
          <p class="page-desc">谁在什么时候做了什么</p>
        </div>
      </header>

      <div class="filter-row">
        <ElInput
          v-model="keyword"
          clearable
          placeholder="搜索谁，或做了什么"
          class="filter-input"
          @change="applyFilter"
          @clear="applyFilter"
        />
        <ElButton text @click="clearFilter">清空筛选</ElButton>
      </div>

      <ElCard class="art-table-card sand-ai-page-card" shadow="never">
        <ArtTable
          row-key="id"
          :loading="loading"
          :data="data"
          :columns="columns"
          :empty-text="emptyText"
          :pagination="pagination"
          @pagination:size-change="handleSizeChange"
          @pagination:current-change="handleCurrentChange"
        />
      </ElCard>

      <AuditIssueDrawer v-model="issueOpen" :sentence="issueSentence" :entries="issueEntries" />
    </div>
  </SandAiErrorBoundary>
</template>

<script setup lang="ts">
  import SandAiErrorBoundary from '../components/SandAiErrorBoundary.vue'
  import '../components/sandAiPage.css'
  import AuditIssueDrawer from './AuditIssueDrawer.vue'
  import { h, ref } from 'vue'
  import { ElButton } from 'element-plus'
  import { useTable } from '@/hooks/core/useTable'
  import auditApi from '../api/audit'
  import { browserOffsetMinutes, formatLocalTime } from '../api/localTime'
  import type { SandAiPageQuery } from '../api/types'

  type AuditEntry = { create_time: string }

  type AuditRow = {
    id: number
    create_time: string
    actor_name: string
    sentence: string
    object_name: string
    result: string
    issue_count: number
    entries: AuditEntry[]
  }

  const keyword = ref('')
  const emptyText = ref('还没有审计记录')
  const issueOpen = ref(false)
  const issueSentence = ref('')
  const issueEntries = ref<AuditEntry[]>([])

  const {
    columns,
    data,
    loading,
    pagination,
    searchParams,
    getDataByPage,
    handleSizeChange,
    handleCurrentChange
  } = useTable({
    core: {
      apiFn: (params: SandAiPageQuery) => auditApi.list(params),
      apiParams: {
        offset_minutes: browserOffsetMinutes()
      },
      columnsFactory: () => [
        {
          prop: 'create_time',
          label: '时间',
          width: 130,
          formatter: (row: AuditRow) => formatLocalTime(row.create_time)
        },
        { prop: 'actor_name', label: '谁', minWidth: 100, showOverflowTooltip: true },
        { prop: 'sentence', label: '做了什么', minWidth: 220, showOverflowTooltip: true },
        { prop: 'object_name', label: '对象', minWidth: 120, showOverflowTooltip: true },
        { prop: 'result', label: '结果', width: 80 },
        {
          prop: 'detail',
          label: '详情',
          width: 90,
          formatter: (row: AuditRow) =>
            row.issue_count > 1
              ? h(
                  ElButton,
                  { text: true, type: 'primary', onClick: () => openIssues(row) },
                  () => '每一次'
                )
              : ''
        }
      ]
    }
  })

  function applyFilter(): void {
    const text = keyword.value.trim()
    const params = searchParams as Record<string, unknown>
    params.offset_minutes = browserOffsetMinutes()
    if (text === '') {
      delete params.name
    } else {
      params.name = text
    }
    emptyText.value = text === '' ? '还没有审计记录' : '没有找到符合条件的记录'
    void getDataByPage()
  }

  function clearFilter(): void {
    keyword.value = ''
    applyFilter()
  }

  function openIssues(row: AuditRow): void {
    issueSentence.value = row.sentence
    issueEntries.value = row.entries
    issueOpen.value = true
  }
</script>

<style scoped lang="scss">
  .record-page {
    display: flex;
    flex-direction: column;
    gap: 16px;
  }

  .page-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
  }

  .page-title {
    margin: 0;
    font-size: 20px;
    font-weight: 600;
    color: var(--el-text-color-primary);
  }

  .page-desc {
    margin: 4px 0 0;
    font-size: 13px;
    color: var(--el-text-color-secondary);
  }

  .filter-row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 12px;
  }

  .filter-input {
    width: 280px;
  }
</style>
