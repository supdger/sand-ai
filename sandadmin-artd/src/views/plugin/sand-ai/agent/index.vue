<template>
  <div class="art-full-height">
    <ExpertSelectionHelp />
    <ElCard shadow="never" class="environment-card">
      <div class="environment-row">
        <div><h2>智能体与渠道</h2><p>先选择应用环境，保存并发布智能体，再试用原版本、查看工具节点与费用。需要临时组合时使用“按次组合试用”。</p></div>
        <ElSelect v-model="environmentId" filterable clearable placeholder="选择应用环境" style="width: 300px">
          <ElOption v-for="environment in environments" :key="environment.id"
            :label="`${environment.name} · ${environment.code}`" :value="environment.id" />
        </ElSelect>
        <ElButton :loading="environmentLoading" @click="loadEnvironments(true)">刷新环境</ElButton>
        <ElButton v-if="environmentMore" :loading="environmentLoading" @click="loadEnvironments(false)">更多环境</ElButton>
      </div>
      <ElAlert v-if="environmentError" type="error" title="无法读取 SandIAM 应用环境，请检查列表权限" :closable="false" />
    </ElCard>
    <ElAlert type="info" :closable="false" title="按用途进入"
      description="常用助手：配置模型、已发布知识库与回答要求 → 保存草稿并发布 → 发布版本试用。临时组合：读取凭证可用模型 → 按次组合试用。需要外部资料时可选择已发布的只读工具；工具执行仍需当前凭证获准。业务审批由消费应用处理。" />
    <ElTabs v-model="tab" class="agent-tabs">
      <ElTabPane label="AI 工作流" name="workflow"><CompositionWorkflowPanel :key="userStore.info.id" :environment-id="environmentId" /></ElTabPane>
      <ElTabPane label="智能体配置" name="definition"><AgentDefinitionPanel :key="userStore.info.id" :environment-id="environmentId" /></ElTabPane>
      <ElTabPane label="渠道绑定" name="channel"><ChannelBindingPanel :key="userStore.info.id" :environment="selectedEnvironment" /></ElTabPane>
      <ElTabPane label="外部工具配置" name="toolSetup"><ToolSetupPanel :key="userStore.info.id" :environment-id="environmentId" /></ElTabPane>
      <ElTabPane label="发布版本试用" name="published"><PublishedAgentRunPanel :key="userStore.info.id" :environment-id="environmentId" /></ElTabPane>
      <ElTabPane label="外部工具试用" name="tool"><ToolTrialPanel :key="userStore.info.id" :environment-id="environmentId" /></ElTabPane>
      <ElTabPane label="按次组合试用" name="run"><AdHocAgentRunPanel :key="userStore.info.id" :environment-id="environmentId" /></ElTabPane>
    </ElTabs>
  </div>
</template>

<script setup lang="ts">
  import ExpertSelectionHelp from '../components/ExpertSelectionHelp.vue'
  import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
  import { useUserStore } from '@/store/modules/user'
  import CompositionWorkflowPanel from './modules/CompositionWorkflowPanel.vue'
  import AgentDefinitionPanel from './modules/AgentDefinitionPanel.vue'
  import ChannelBindingPanel from './modules/ChannelBindingPanel.vue'
  import PublishedAgentRunPanel from './modules/PublishedAgentRunPanel.vue'
  import AdHocAgentRunPanel from './modules/AdHocAgentRunPanel.vue'
  import ToolSetupPanel from './modules/ToolSetupPanel.vue'
  import ToolTrialPanel from './modules/ToolTrialPanel.vue'
  import iamApi, { type SandIamEnvironmentReference } from '../api/iam'

  const userStore = useUserStore()
  const tab = ref<'workflow' | 'definition' | 'channel' | 'toolSetup' | 'published' | 'tool' | 'run'>('definition')
  const environments = ref<SandIamEnvironmentReference[]>([])
  const environmentId = ref<number | null>(null)
  const selectedEnvironment = computed(() => environments.value.find(item => item.id === environmentId.value) ?? null)
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
