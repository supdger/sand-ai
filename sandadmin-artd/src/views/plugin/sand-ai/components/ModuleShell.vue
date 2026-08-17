<template>
  <div class="art-full-height">
    <ElCard class="art-table-card" shadow="never">
      <div class="module-shell">
        <div class="module-shell__header">
          <h2 class="module-shell__title">{{ title }}</h2>
          <p v-if="description" class="module-shell__desc">{{ description }}</p>
        </div>
        <slot>
          <ElEmpty :description="emptyText" />
        </slot>
      </div>
    </ElCard>
  </div>
</template>

<script setup lang="ts">
  defineProps<{
    title: string
    description?: string
    emptyText?: string
  }>()
</script>

<style scoped lang="scss">
  .module-shell {
    display: flex;
    flex-direction: column;
    gap: 16px;
    min-height: 320px;
  }

  .module-shell__header {
    display: flex;
    flex-direction: column;
    gap: 4px;
  }

  .module-shell__title {
    margin: 0;
    font-size: 18px;
    font-weight: 600;
    line-height: 1.4;
    color: var(--el-text-color-primary);
  }

  .module-shell__desc {
    margin: 0;
    font-size: 13px;
    line-height: 1.5;
    color: var(--el-text-color-secondary);
  }
</style>
