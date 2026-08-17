<template>
  <div class="art-full-height">
    <ElCard class="art-table-card mock-search-card" shadow="never">
      <ElForm :inline="true" @submit.prevent>
        <ElFormItem :label="searchLabel">
          <ElInput
            v-model="keywordProxy"
            clearable
            :placeholder="searchPlaceholder"
            style="width: 240px"
            @keyup.enter="emitSearch"
          />
        </ElFormItem>
        <ElFormItem>
          <ElButton type="primary" @click="emitSearch">查询</ElButton>
          <ElButton @click="emitReset">重置</ElButton>
        </ElFormItem>
      </ElForm>
    </ElCard>

    <ElCard class="art-table-card" shadow="never">
      <div class="mock-toolbar">
        <div class="mock-toolbar__left">
          <h2 class="mock-toolbar__title">{{ title }}</h2>
          <p class="mock-toolbar__desc">{{ description }}</p>
        </div>
        <ElSpace wrap>
          <ElButton :loading="isLoading" @click="onReload">刷新</ElButton>
          <ElButton @click="onShowLoading">模拟加载</ElButton>
          <ElButton @click="onShowEmpty">模拟空数据</ElButton>
          <ElButton @click="onShowError">模拟失败</ElButton>
          <ElButton type="primary" @click="onShowSuccess">模拟有数据</ElButton>
        </ElSpace>
      </div>

      <div v-if="isLoading" class="mock-state">
        <ElSkeleton :rows="6" animated />
      </div>

      <ElResult
        v-else-if="isError"
        icon="error"
        title="加载失败"
        :sub-title="errorMessage"
      >
        <template #extra>
          <ElButton type="primary" @click="onShowSuccess">重试（切回 mock 成功态）</ElButton>
        </template>
      </ElResult>

      <ElEmpty v-else-if="isEmpty" description="暂无数据（mock 空状态）" />

      <ElTable v-else :data="rows" row-key="mockId" border stripe>
        <ElTableColumn prop="mockId" label="示意标识" min-width="160" show-overflow-tooltip />
        <ElTableColumn prop="mockTitle" :label="nameColumnLabel" min-width="180" show-overflow-tooltip />
        <ElTableColumn prop="mockStatus" label="状态示意" width="120" align="center" />
        <ElTableColumn prop="mockUpdatedAt" label="时间示意" width="180" />
        <ElTableColumn label="操作" width="160" fixed="right">
          <template #default>
            <ElButton link type="primary" disabled>编辑</ElButton>
            <ElButton link type="danger" disabled>删除</ElButton>
          </template>
        </ElTableColumn>
      </ElTable>

      <p class="mock-footnote">列与数据均为前端 mock 展示键，不代表已冻结 API 字段。</p>
    </ElCard>
  </div>
</template>

<script setup lang="ts">
  import { computed } from 'vue'
  import type { MockListItem } from '../composables/useMockList'

  const props = withDefaults(
    defineProps<{
      title: string
      description: string
      keyword: string
      rows: MockListItem[]
      isLoading: boolean
      isEmpty: boolean
      isError: boolean
      errorMessage: string
      searchLabel?: string
      searchPlaceholder?: string
      nameColumnLabel?: string
    }>(),
    {
      searchLabel: '关键词',
      searchPlaceholder: '按示意名称或标识过滤',
      nameColumnLabel: '名称示意'
    }
  )

  const emit = defineEmits<{
    'update:keyword': [value: string]
    search: []
    reset: []
    reload: []
    'show-loading': []
    'show-empty': []
    'show-error': []
    'show-success': []
  }>()

  const keywordProxy = computed({
    get: () => props.keyword,
    set: (value: string) => emit('update:keyword', value)
  })

  function emitSearch(): void {
    emit('search')
  }

  function emitReset(): void {
    emit('reset')
  }

  function onReload(): void {
    emit('reload')
  }

  function onShowLoading(): void {
    emit('show-loading')
  }

  function onShowEmpty(): void {
    emit('show-empty')
  }

  function onShowError(): void {
    emit('show-error')
  }

  function onShowSuccess(): void {
    emit('show-success')
  }
</script>

<style scoped lang="scss">
  .mock-search-card {
    margin-bottom: 12px;
  }

  .mock-toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 16px;
  }

  .mock-toolbar__title {
    margin: 0;
    font-size: 18px;
    font-weight: 600;
    line-height: 1.4;
    color: var(--el-text-color-primary);
  }

  .mock-toolbar__desc {
    margin: 4px 0 0;
    font-size: 13px;
    line-height: 1.5;
    color: var(--el-text-color-secondary);
  }

  .mock-state {
    padding: 8px 0 16px;
  }

  .mock-footnote {
    margin: 12px 0 0;
    font-size: 12px;
    color: var(--el-text-color-secondary);
  }
</style>
