<template>
  <SandAiErrorBoundary>
    <div class="sand-ai-page knowledge-page">
      <div class="page-head">
        <div class="page-head-text">
          <h2 class="page-title">知识库</h2>
          <p class="page-desc">放资料给 AI 查，让回答更准</p>
        </div>
        <ElButton type="primary" :disabled="environmentId === null" @click="openCreate">
          {{ activeTab === 'skill' ? '新建技能模板' : '新建知识库' }}
        </ElButton>
      </div>

      <ElAlert
        v-if="providerMissing"
        type="warning"
        :closable="false"
        class="page-alert"
        title="还没有连接 AI 服务商，资料能加进来，但 AI 暂时不能查"
      >
        <template #default>
          <ElButton link type="primary" @click="goProvider">去连接</ElButton>
        </template>
      </ElAlert>

      <div v-if="environments.length > 1" class="env-bar">
        <ElSelect
          v-model="environmentIdModel"
          clearable
          filterable
          placeholder="选择环境"
          class="env-select"
        >
          <ElOption
            v-for="item in environments"
            :key="item.id"
            :label="item.name"
            :value="item.id"
          />
        </ElSelect>
      </div>
      <ElAlert
        v-else-if="environmentsLoaded && environments.length === 0"
        type="info"
        :closable="false"
        class="page-alert"
        title="还没有可用环境，请先在应用里开通环境后再管理知识库。"
      />

      <ElTabs v-model="activeTab" class="page-tabs">
        <ElTabPane label="知识库" name="knowledge">
          <KnowledgeTab
            v-if="activeTab === 'knowledge'"
            ref="knowledgeTab"
            :key="`knowledge:${userStore.info.id}`"
            :environment-id="environmentId"
          />
        </ElTabPane>
        <ElTabPane label="技能模板" name="skill">
          <SkillTemplateTab
            v-if="activeTab === 'skill'"
            ref="skillTab"
            :key="`skill:${userStore.info.id}`"
            :environment-id="environmentId"
          />
        </ElTabPane>
      </ElTabs>
    </div>
  </SandAiErrorBoundary>
</template>

<script setup lang="ts">
  import SandAiErrorBoundary from '../components/SandAiErrorBoundary.vue'
  import '../components/sandAiPage.css'
  import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
  import { useRouter } from 'vue-router'
  import { useUserStore } from '@/store/modules/user'
  import iamApi, { type SandIamEnvironmentReference } from '../api/iam'
  import providerApi from '../api/provider'
  import KnowledgeTab from './modules/knowledge-tab.vue'
  import SkillTemplateTab from './modules/skill-template-tab.vue'

  const router = useRouter()
  const userStore = useUserStore()

  const activeTab = ref<'knowledge' | 'skill'>('knowledge')
  const environmentId = ref<number | null>(null)
  // 清除时组件会写入空串，这里统一收敛回 null，避免下游把空串当环境号。
  const environmentIdModel = computed<number | null>({
    get: () => environmentId.value,
    set: (value) => {
      environmentId.value = typeof value === 'number' ? value : null
    }
  })
  const environments = ref<SandIamEnvironmentReference[]>([])
  const environmentsLoaded = ref(false)
  const providerMissing = ref(false)
  const knowledgeTab = ref<InstanceType<typeof KnowledgeTab> | null>(null)
  const skillTab = ref<InstanceType<typeof SkillTemplateTab> | null>(null)

  let alive = true
  let loadGeneration = 0

  async function loadEnvironments(): Promise<void> {
    const generation = ++loadGeneration
    try {
      const result = await iamApi.listEnvironments({ page: 1, limit: 100 })
      if (!alive || generation !== loadGeneration) return
      environments.value = result.data.filter((item) => item.status === 1)
      environmentsLoaded.value = true
      if (environmentId.value === null && environments.value.length > 0) {
        environmentId.value = environments.value[0].id
      }
    } catch {
      if (!alive || generation !== loadGeneration) return
      environmentsLoaded.value = true
    }
  }

  async function checkProvider(): Promise<void> {
    try {
      const result = await providerApi.list({ page: 1, limit: 1 })
      if (!alive) return
      providerMissing.value = result.total === 0
    } catch {
      // 权限或网络异常时不挡页面，只在能确认「一个都没有」时提示。
      providerMissing.value = false
    }
  }

  function openCreate(): void {
    if (activeTab.value === 'skill') {
      void skillTab.value?.openCreate()
      return
    }
    void knowledgeTab.value?.openCreate()
  }

  function goProvider(): void {
    void router.push('/sand-ai/provider')
  }

  onMounted(() => {
    void loadEnvironments()
    void checkProvider()
  })

  onBeforeUnmount(() => {
    alive = false
    ++loadGeneration
  })
</script>

<style scoped lang="scss">
  .knowledge-page {
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
  }

  .page-desc {
    margin: 4px 0 0;
    font-size: 13px;
    color: var(--el-text-color-secondary);
  }

  .page-alert {
    margin-bottom: 0;
  }

  .env-bar {
    display: flex;
    justify-content: flex-end;
  }

  .env-select {
    width: 280px;
  }

  .page-tabs {
    flex: 1;
  }
</style>
