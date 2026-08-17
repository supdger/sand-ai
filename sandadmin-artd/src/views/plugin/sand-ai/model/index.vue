<template>
  <div class="art-full-height">
    <CodeNameStatusSearch v-model="searchForm" @search="handleSearch" @reset="handleReset" />

    <ElCard class="art-table-card" shadow="never">
      <ArtTableHeader v-model:columns="columnChecks" :loading="loading" @refresh="refreshData">
        <template #left>
          <ElSpace wrap>
            <ElButton v-permission="'sand_ai:model:save'" type="primary" @click="openAdd">
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
        <template #capabilities="{ row }">
          <span>{{ (row.capabilities ?? []).join(', ') }}</span>
        </template>
        <template #status="{ row }">
          <StatusTag :status="row.status" />
        </template>
        <template #operation="{ row }">
          <div class="flex gap-2">
            <ElButton
              v-permission="'sand_ai:model:update'"
              link
              type="primary"
              @click="openEdit(row)"
            >
              编辑
            </ElButton>
            <ElButton
              v-permission="'sand_ai:model:destroy'"
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
</template>

<script setup lang="ts">
  import { ref } from 'vue'
  import { ElMessage, ElMessageBox } from 'element-plus'
  import { useTable } from '@/hooks/core/useTable'
  import modelApi from '../api/model'
  import type { SandAiModel, SandAiPageQuery } from '../api/types'
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
  const dialogData = ref<Partial<SandAiModel>>({})

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
      apiFn: modelApi.list,
      apiParams: {},
      columnsFactory: () => [
        { prop: 'id', label: 'ID', width: 80, align: 'center' },
        { prop: 'code', label: '编码', minWidth: 140 },
        { prop: 'name', label: '名称', minWidth: 160 },
        { prop: 'type', label: '类型', width: 110 },
        { prop: 'capabilities', label: '能力', minWidth: 160, useSlot: true },
        { prop: 'status', label: '状态', width: 100, useSlot: true },
        { prop: 'update_time', label: '更新时间', width: 180 },
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
    dialogData.value = { status: 1, type: 'chat', capabilities: [] }
    dialogVisible.value = true
  }

  function openEdit(row: SandAiModel): void {
    dialogType.value = 'edit'
    dialogData.value = { ...row }
    dialogVisible.value = true
  }

  async function handleDelete(row: SandAiModel): Promise<void> {
    try {
      await ElMessageBox.confirm(`确认删除模型「${row.name}」？`, '删除确认', {
        type: 'warning'
      })
      await modelApi.delete({ ids: [row.id] })
      ElMessage.success('删除成功')
      refreshData()
    } catch {
      // ignore cancel
    }
  }
</script>
