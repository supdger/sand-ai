<template>
  <div class="art-full-height">
    <ExpertSelectionHelp />
    <ElCard shadow="never" class="environment-card">
      <div class="environment-row">
        <div><h2>AI 工作流</h2><p>选择应用环境，将已发布服务组合为多个节点，保存草稿并发布，再使用应用凭证试用固定版本。</p></div>
        <ElSelect v-model="environmentId" filterable clearable placeholder="选择应用环境" style="width: 300px">
          <ElOption v-for="environment in environments" :key="environment.id"
            :label="`${environment.name} · ${environment.code}`" :value="environment.id" />
        </ElSelect>
        <ElButton :loading="environmentLoading" @click="loadEnvironments(true)">刷新环境</ElButton>
        <ElButton v-if="environmentMore" :loading="environmentLoading" @click="loadEnvironments(false)">更多环境</ElButton>
      </div>
      <ElAlert v-if="environmentError" type="error" title="无法读取 SandIAM 应用环境，请检查列表权限" :closable="false" />
    </ElCard>
    <CompositionWorkflowPanel :key="userStore.info.id" :environment-id="environmentId" />
  </div>
</template>

<script setup lang="ts">
  import ExpertSelectionHelp from '../components/ExpertSelectionHelp.vue'
  import { onMounted, onUnmounted, ref, watch } from 'vue'
  import { useUserStore } from '@/store/modules/user'
  import CompositionWorkflowPanel from '../agent/modules/CompositionWorkflowPanel.vue'
  import iamApi, { type SandIamEnvironmentReference } from '../api/iam'

  const userStore = useUserStore()
  const environments = ref<SandIamEnvironmentReference[]>([])
  const environmentId = ref<number | null>(null)
  const environmentLoading = ref(false)
  const environmentError = ref(false)
  const environmentMore = ref(false)
  let environmentPage = 0
  let environmentEpoch = 0
  async function loadEnvironments(reset: boolean): Promise<void> {
    if (environmentLoading.value && !reset) return
    const current = ++environmentEpoch
    const targetPage = reset ? 1 : environmentPage + 1
    environmentLoading.value = true
    environmentError.value = false
    try {
      const result = await iamApi.listEnvironments({ page: targetPage, limit: 100 })
      if (current !== environmentEpoch) return
      const active = result.data.filter(item => item.status === 1)
      environments.value = reset ? active : [...environments.value, ...active]
      environmentPage = targetPage
      environmentMore.value = targetPage * 100 < result.total
      if (environmentId.value && !environments.value.some(item => item.id === environmentId.value)) environmentId.value = null
    } catch {
      if (current === environmentEpoch) environmentError.value = true
    } finally {
      if (current === environmentEpoch) environmentLoading.value = false
    }
  }
  onMounted(() => { void loadEnvironments(true) })
  watch([() => userStore.info.id, () => userStore.accessToken], () => {
    ++environmentEpoch
    environments.value = []
    environmentId.value = null
    environmentPage = 0
    environmentMore.value = false
    void loadEnvironments(true)
  }, { flush: 'sync' })
  onUnmounted(() => { ++environmentEpoch })
</script>

<style scoped lang="scss">
  .environment-card { margin-bottom: 16px; }
  .environment-row { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; }
  .environment-row h2 { margin: 0; font-size: 18px; }
  .environment-row p { margin: 4px 0 0; color: var(--el-text-color-secondary); font-size: 13px; }
  .agent-tabs { margin-top: 16px; }
</style>
