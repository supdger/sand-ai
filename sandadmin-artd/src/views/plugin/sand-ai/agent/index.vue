<template>
  <SandAiErrorBoundary>
    <div class="sand-ai-page">
      <div class="page-head">
        <div class="page-head-text">
          <h2>智能体与渠道</h2>
          <p>做一个 AI 助手，接到聊天渠道上用</p>
        </div>
        <ElButton v-if="tab === 'agent'" type="primary" :disabled="!environmentId" @click="createAgent">新建智能体</ElButton>
        <ElButton v-else type="primary" :disabled="!environmentId" @click="createChannel">新建渠道</ElButton>
      </div>
      <ElCard v-if="showEnvironmentPicker" shadow="never" class="environment-card">
        <div class="environment-row">
          <ElSelect v-model="environmentId" filterable clearable :loading="environmentLoading"
            placeholder="选择应用环境" style="width: 280px" aria-label="选择应用环境">
            <ElOption v-for="environment in environments" :key="environment.id"
              :label="environment.name" :value="environment.id" />
          </ElSelect>
          <ElButton :loading="environmentLoading" @click="loadEnvironments(true)">刷新</ElButton>
          <ElButton v-if="environmentMore" :loading="environmentLoading" @click="loadEnvironments(false)">更多环境</ElButton>
        </div>
        <ElAlert v-if="environmentError" type="error" :closable="false" class="environment-alert"
          title="应用环境没能读出来" description="请检查网络后重试；仍失败请联系管理员。" />
      </ElCard>
      <ElSkeleton v-if="initialEnvironmentLoading" :rows="4" animated class="environment-skeleton" />
      <ElTabs v-model="tab" class="agent-tabs">
        <ElTabPane label="智能体" name="agent">
          <AgentDefinitionPanel ref="agentPanel" :environment-id="environmentId" :environment="selectedEnvironment" />
        </ElTabPane>
        <ElTabPane label="渠道" name="channel">
          <ChannelBindingPanel ref="channelPanel" :environment="selectedEnvironment" />
        </ElTabPane>
      </ElTabs>
    </div>
  </SandAiErrorBoundary>
</template>

<script setup lang="ts">
  import SandAiErrorBoundary from '../components/SandAiErrorBoundary.vue'
  import '../components/sandAiPage.css'
  import { computed, nextTick, onMounted, onUnmounted, provide, ref, watch } from 'vue'
  import { useUserStore } from '@/store/modules/user'
  import AgentDefinitionPanel from './modules/AgentDefinitionPanel.vue'
  import ChannelBindingPanel from './modules/ChannelBindingPanel.vue'
  import iamApi, { type SandIamEnvironmentReference } from '../api/iam'
  import { AGENT_PAGE_BRIDGE } from './pageBridge'

  const userStore = useUserStore()
  const tab = ref<'agent' | 'channel'>('agent')
  const environments = ref<SandIamEnvironmentReference[]>([])
  const environmentId = ref<number | null>(null)
  const selectedEnvironment = computed(() => environments.value.find(item => item.id === environmentId.value) ?? null)
  const environmentLoading = ref(false)
  const environmentError = ref(false)
  const environmentMore = ref(false)
  /** 首次进入且环境列表未返回时显示骨架屏；已有数据或出错时不再遮挡。 */
  const initialEnvironmentLoading = computed(() => environmentLoading.value && environments.value.length === 0 && !environmentError.value)
  /** 单环境自动选中后隐藏选择器；多环境或读取失败时才露出。 */
  const showEnvironmentPicker = computed(() => environments.value.length > 1 || environmentError.value)
  let environmentPage = 0
  let environmentEpoch = 0

  const agentPanel = ref<InstanceType<typeof AgentDefinitionPanel> | null>(null)
  const channelPanel = ref<InstanceType<typeof ChannelBindingPanel> | null>(null)

  function createAgent(): void {
    agentPanel.value?.openCreate()
  }
  function createChannel(): void {
    channelPanel.value?.openCreate()
  }

  provide(AGENT_PAGE_BRIDGE, {
    requestEnableAgent: (agentCode: string) => {
      tab.value = 'agent'
      void nextTick(() => agentPanel.value?.requestEnable(agentCode))
    }
  })

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
      if (!environmentId.value && environments.value.length === 1 && !environmentMore.value) environmentId.value = environments.value[0].id
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
  .page-head { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 12px; }
  .page-head-text h2 { margin: 0; font-size: 20px; }
  .page-head-text p { margin: 4px 0 0; color: var(--el-text-color-secondary); font-size: 13px; }
  .environment-card { margin-bottom: 12px; }
  .environment-row { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; }
  .environment-alert { margin-top: 10px; }
  .environment-skeleton { margin-bottom: 12px; }
  .agent-tabs { margin-top: 4px; }
</style>
