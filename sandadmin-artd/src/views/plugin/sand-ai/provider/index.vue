<template>
  <SandAiErrorBoundary>
    <div class="sand-ai-page">
      <header class="provider-head">
        <div>
          <h1 class="provider-title">模型服务商</h1>
          <p class="provider-desc">连上一个 AI 服务商，AI 功能才能用</p>
        </div>
        <ElButton v-permission="'sand_ai:provider:save'" type="primary" @click="openAdd">新增服务商</ElButton>
      </header>
      <div class="provider-filter">
        <ElInput v-model="searchForm.name" clearable placeholder="搜索名称" @input="handleSearch(searchForm)" />
        <ElSelect v-model="searchForm.status" clearable placeholder="状态" @change="handleSearch(searchForm)">
          <ElOption label="已启用" :value="1" />
          <ElOption label="已停用" :value="2" />
        </ElSelect>
        <ElButton link @click="handleReset">清空筛选</ElButton>
      </div>

      <ElCard class="art-table-card sand-ai-page-card" shadow="never">

        <ArtTable
          row-key="id"
          :loading="loading"
          :data="data"
          :columns="columns"
          :pagination="pagination"
          @pagination:size-change="handleSizeChange"
          @pagination:current-change="handleCurrentChange"
        >
          <template #has_encrypted_config="{ row }">
            {{ row.has_encrypted_config ? '已填' : '没填' }}
          </template>
          <template #status="{ row }">
            <StatusTag :status="row.status" />
          </template>
          <template #operation="{ row }">
            <div class="flex gap-2">
              <ElButton
                v-permission="'sand_ai:provider:update'"
                link
                type="primary"
                @click="openEdit(row)"
              >
                编辑
              </ElButton>
              <ElButton
                v-permission="'sand_ai:provider:destroy'"
                link
                type="danger"
                @click="handleDelete(row)"
              >
                删除
              </ElButton>
            </div>
          </template>
        </ArtTable>
      </ElCard>

      <EditDialog
        v-model="dialogVisible"
        :dialog-type="dialogType"
        :data="dialogData"
        @success="refreshData"
      />
    </div>
  </SandAiErrorBoundary>
</template>

<script setup lang="ts">
  import SandAiErrorBoundary from '../components/SandAiErrorBoundary.vue'
  import '../components/sandAiPage.css'
  import { ref } from 'vue'
  import { ElMessage, ElMessageBox } from 'element-plus'
  import { useTable } from '@/hooks/core/useTable'
  import providerApi from '../api/provider'
  import { formatLocalTime } from '../api/localTime'
  import { providerAdapterLabel } from '../api/displayLabels'
  import type { SandAiPageQuery, SandAiProvider } from '../api/types'
  import CodeNameStatusSearch from '../components/CodeNameStatusSearch.vue'
  import StatusTag from '../components/StatusTag.vue'
  import EditDialog from './modules/edit-dialog.vue'

  const emptySearch = (): SandAiPageQuery => ({
    code: undefined,
    name: undefined,
    status: ''
  })
  const searchForm = ref<SandAiPageQuery>(emptySearch())

  const dialogVisible = ref(false)
  const dialogType = ref<'add' | 'edit'>('add')
  const dialogData = ref<Partial<SandAiProvider>>({})

  const {
    columns,
    columnChecks,
    data,
    loading,
    pagination,
    searchParams,
    getData,
    resetSearchParams,
    handleSizeChange,
    handleCurrentChange,
    refreshData
  } = useTable({
    core: {
      apiFn: providerApi.list,
      apiParams: {},
      columnsFactory: () => [
        { prop: 'name', label: '名称', minWidth: 140, showOverflowTooltip: true },
        {
          prop: 'adapter',
          label: '类型',
          minWidth: 130,
          formatter: (row: SandAiProvider) => providerAdapterLabel(row.adapter)
        },
        { prop: 'has_encrypted_config', label: '密钥', width: 90, useSlot: true },
        { prop: 'status', label: '状态', width: 100, useSlot: true },
        {
          prop: 'update_time',
          label: '更新时间',
          width: 140,
          formatter: (row: SandAiProvider) => formatLocalTime(String(row.update_time ?? ''))
        },
        { prop: 'operation', label: '操作', width: 140, fixed: 'right', useSlot: true }
      ]
    }
  })

  function handleSearch(params: SandAiPageQuery): void {
    searchForm.value = { ...params }
    Object.assign(searchParams, params)
    getData()
  }

  function handleReset(): void {
    searchForm.value = emptySearch()
    resetSearchParams()
  }

  function openAdd(): void {
    dialogType.value = 'add'
    dialogData.value = { status: 1, adapter: 'openai_compatible', timeout_ms: 30000 }
    dialogVisible.value = true
  }

  function openEdit(row: SandAiProvider): void {
    dialogType.value = 'edit'
    dialogData.value = { ...row }
    dialogVisible.value = true
  }

  async function handleDelete(row: SandAiProvider): Promise<void> {
    try {
      await ElMessageBox.confirm('删除后，用这个服务商的模型都不能用了，删了不能恢复。', `删除「${row.name}」？`, {
        type: 'warning',
        confirmButtonText: '删除',
        cancelButtonText: '再想想',
        confirmButtonClass: 'el-button--danger'
      })
      await providerApi.delete({ ids: [row.id] })
      ElMessage.success('删除成功')
      refreshData()
    } catch {
      // ignore cancel
    }
  }
</script>

<style scoped>
  .provider-head, .provider-filter {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 12px;
  }
  .provider-title { margin: 0; font-size: 20px; }
  .provider-desc { margin: 4px 0 0; color: var(--el-text-color-secondary); font-size: 13px; }
  .provider-filter { justify-content: flex-start; }
  .provider-filter .el-input { width: 240px; }
</style>
