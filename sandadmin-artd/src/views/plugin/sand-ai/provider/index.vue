<template>
  <SandAiErrorBoundary>
    <div class="sand-ai-page">
      <CodeNameStatusSearch v-model="searchForm" @search="handleSearch" @reset="handleReset" />

      <ElCard class="art-table-card sand-ai-page-card" shadow="never">
        <ArtTableHeader v-model:columns="columnChecks" :loading="loading" @refresh="refreshData">
          <template #left>
            <ElSpace wrap>
              <ElButton v-permission="'sand_ai:provider:save'" type="primary" @click="openAdd">
                <template #icon>
                  <ArtSvgIcon icon="ri:add-fill" />
                </template>
                新增
              </ElButton>
            </ElSpace>
          </template>
        </ArtTableHeader>

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
            <ElTag :type="row.has_encrypted_config ? 'success' : 'info'" effect="plain">
              {{ row.has_encrypted_config ? '已配置' : '未配置' }}
            </ElTag>
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
  import { ElMessage } from 'element-plus'
  import { confirmDeletion } from '../composables/confirmDeletion'
  import { useTable } from '@/hooks/core/useTable'
  import providerApi from '../api/provider'
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
        { prop: 'id', label: '编号', width: 70, align: 'center' },
        { prop: 'name', label: '名称', minWidth: 140, showOverflowTooltip: true },
        {
          prop: 'adapter',
          label: '服务商类型',
          minWidth: 130,
          formatter: (row: SandAiProvider) => providerAdapterLabel(row.adapter)
        },
        { prop: 'timeout_ms', label: '超时（毫秒）', width: 100, align: 'center' },
        { prop: 'has_encrypted_config', label: '密钥', width: 90, useSlot: true },
        { prop: 'status', label: '状态', width: 90, useSlot: true },
        { prop: 'update_time', label: '更新时间', width: 170 },
        { prop: 'operation', label: '操作', width: 120, fixed: 'right', useSlot: true }
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
      await confirmDeletion('删除后不能恢复。用过的费用记录会保留。', `删除「${row.name}」？`)
      await providerApi.delete({ ids: [row.id] })
      ElMessage.success('删除成功')
      refreshData()
    } catch {
      // ignore cancel
    }
  }
</script>
