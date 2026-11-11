<template>
  <ElCard shadow="never" class="usage-stat-card" :class="{ 'is-clickable': clickable, [`tone-${tone}`]: tone !== 'default' }"
    @click="clickable && emit('open')">
    <div v-if="loading" class="py-2">
      <ElSkeleton :rows="2" animated />
    </div>
    <template v-else>
      <div class="stat-label">{{ label }}</div>
      <div class="stat-value">
        <template v-for="(line, index) in lines" :key="index">
          <div class="stat-line" :class="{ 'stat-line-sub': index > 0 }">{{ line }}</div>
        </template>
      </div>
      <div v-if="hint" class="stat-hint" :class="{ 'hint-warn': tone === 'warning', 'hint-danger': tone === 'danger' }">{{ hint }}</div>
      <div v-if="actionText" class="stat-action">{{ actionText }}</div>
    </template>
  </ElCard>
</template>

<script setup lang="ts">
  /**
   * 费用页数字卡：只展示一行主数字（多币种时逐行排列）和一行灰字说明。
   * 点击行为由父级决定（emit open）。
   */
  defineProps<{
    label: string
    lines: string[]
    hint?: string
    actionText?: string
    tone?: 'default' | 'warning' | 'danger'
    loading?: boolean
    clickable?: boolean
  }>()

  const emit = defineEmits<{ open: [] }>()
</script>

<style scoped>
  .usage-stat-card {
    height: 100%;
  }

  .usage-stat-card.is-clickable {
    cursor: pointer;
  }

  .usage-stat-card.is-clickable:hover {
    border-color: var(--el-color-primary-light-5);
  }

  .stat-label {
    font-size: 13px;
    color: var(--el-text-color-secondary);
    margin-bottom: 8px;
  }

  .stat-value {
    min-height: 44px;
  }

  .stat-line {
    font-size: 22px;
    font-weight: 600;
    line-height: 1.35;
    color: var(--el-text-color-primary);
    font-variant-numeric: tabular-nums;
  }

  .stat-line-sub {
    font-size: 17px;
    font-weight: 500;
  }

  .stat-hint {
    margin-top: 8px;
    font-size: 12px;
    line-height: 1.5;
    color: var(--el-text-color-secondary);
  }

  .hint-warn {
    color: var(--el-color-warning);
  }

  .hint-danger {
    color: var(--el-color-danger);
  }

  .stat-action {
    margin-top: 6px;
    font-size: 12px;
    color: var(--el-color-primary);
  }
</style>
