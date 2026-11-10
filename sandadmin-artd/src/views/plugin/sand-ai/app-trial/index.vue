<template>
  <SandAiErrorBoundary>
    <div class="sand-ai-page app-trial-page">
      <div class="page-head">
        <div class="page-head-text">
          <h2 class="page-title">应用试用</h2>
          <p class="page-desc">用应用身份试查知识库、问答和技能模板</p>
        </div>
      </div>

      <ElAlert
        v-if="environmentsError"
        type="error"
        :closable="false"
        class="page-alert"
        title="应用环境列表读取失败，请检查权限后重试。"
      >
        <template #default>
          <ElButton link type="primary" :loading="environmentsLoading" @click="loadEnvironments(true)">重试</ElButton>
        </template>
      </ElAlert>
      <ElAlert
        v-else-if="environmentsLoaded && environments.length === 0"
        type="info"
        :closable="false"
        class="page-alert"
        title="还没有可用的应用环境，请先在应用里开通环境。"
      />

      <ElForm label-width="110px" class="credential-form" @submit.prevent>
        <RuntimeApiKeyField
          v-model="apiKey"
          :persist-in-session="false"
          placeholder="粘贴应用后端签发的调用密钥，仅本页面使用"
        />
      </ElForm>

      <div v-if="needsEnvironment" class="env-bar">
        <ElSelect
          v-model="environmentIdModel"
          clearable
          filterable
          :loading="environmentsLoading"
          placeholder="选择应用环境"
          class="env-select"
        >
          <ElOption
            v-for="item in environments"
            :key="item.id"
            :label="item.name"
            :value="item.id"
          />
        </ElSelect>
        <ElButton link type="primary" :loading="environmentsLoading" @click="loadEnvironments(true)">刷新环境</ElButton>
      </div>

      <ElAlert
        v-if="publicationsError"
        type="error"
        :closable="false"
        class="page-alert"
        title="这个应用环境已开放的知识库列表读取失败。"
      >
        <template #default>
          <ElButton link type="primary" :loading="publicationsLoading" @click="loadPublications">重试</ElButton>
        </template>
      </ElAlert>

      <ElTabs v-model="activeTab" class="page-tabs">
        <ElTabPane label="知识检索" name="query">
          <ElSkeleton v-if="publicationsBusy" :rows="5" animated class="panel-skeleton" />
          <div v-show="!publicationsBusy" class="panel-mount">
            <KnowledgeQueryPanel
              :key="`query:${userStore.info.id}`"
              v-model:api-key="apiKey"
              :publications="publications"
              :environment-id="environmentId"
              @refresh="loadPublications"
            />
          </div>
        </ElTabPane>
        <ElTabPane label="应用问答" name="chat">
          <ElSkeleton v-if="publicationsBusy" :rows="5" animated class="panel-skeleton" />
          <div v-show="!publicationsBusy" class="panel-mount">
            <ConversationPanel
              :key="`chat:${userStore.info.id}`"
              v-model:api-key="apiKey"
              :publications="publications"
              :environment-id="environmentId"
              :environments="environments"
            />
          </div>
        </ElTabPane>
        <ElTabPane label="技能模板" name="skill">
          <ConsumerSkillPanel
            :key="`skill:${userStore.info.id}`"
            v-model:api-key="apiKey"
          />
        </ElTabPane>
      </ElTabs>
    </div>
  </SandAiErrorBoundary>
</template>

<script setup lang="ts">
  import SandAiErrorBoundary from '../components/SandAiErrorBoundary.vue'
  import '../components/sandAiPage.css'
  import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
  import { useUserStore } from '@/store/modules/user'
  import iamApi, { type SandIamEnvironmentReference } from '../api/iam'
  import publicationApi, { type ConsumerKnowledgePublication } from '../api/knowledgePublication'
  import { errorDisplayText } from '../api/errorText'
  import RuntimeApiKeyField from '../components/RuntimeApiKeyField.vue'
  import KnowledgeQueryPanel from '../knowledge/modules/knowledge-query-panel.vue'
  import ConversationPanel from '../knowledge/modules/conversation-panel.vue'
  import ConsumerSkillPanel from '../knowledge/modules/consumer-skill-panel.vue'

  const userStore = useUserStore()

  const activeTab = ref<'query' | 'chat' | 'skill'>('query')
  const needsEnvironment = computed(() => activeTab.value !== 'skill')
  const apiKey = ref('')

  const environments = ref<SandIamEnvironmentReference[]>([])
  const environmentId = ref<number | null>(null)
  // 清除时组件会写入空串，这里统一收敛回 null，避免下游把空串当环境号。
  const environmentIdModel = computed<number | null>({
    get: () => environmentId.value,
    set: (value) => {
      environmentId.value = typeof value === 'number' ? value : null
    }
  })
  const environmentsLoaded = ref(false)
  const environmentsLoading = ref(false)
  const environmentsError = ref(false)

  const publications = ref<ConsumerKnowledgePublication[]>([])
  const publicationsLoading = ref(false)
  const publicationsError = ref(false)
  // 只要当前选中环境正在拉取开放知识库，两个知识页签都先显示骨架屏，面板保持挂载不丢状态。
  const publicationsBusy = computed(() => needsEnvironment.value && environmentId.value !== null && publicationsLoading.value)

  let alive = true
  let environmentEpoch = 0
  let publicationEpoch = 0

  async function loadEnvironments(reset: boolean): Promise<void> {
    if (environmentsLoading.value && !reset) return
    const current = ++environmentEpoch
    environmentsLoading.value = true
    environmentsError.value = false
    try {
      const result = await iamApi.listEnvironments({ page: 1, limit: 100 })
      if (!alive || current !== environmentEpoch) return
      environments.value = result.data.filter((item) => item.status === 1)
      environmentsLoaded.value = true
      if (environmentId.value !== null && !environments.value.some((item) => item.id === environmentId.value)) {
        environmentId.value = null
      }
    } catch {
      if (!alive || current !== environmentEpoch) return
      environmentsLoaded.value = true
      environmentsError.value = true
    } finally {
      if (alive && current === environmentEpoch) environmentsLoading.value = false
    }
  }

  async function loadPublications(): Promise<void> {
    const environment = environmentId.value
    if (environment === null) {
      publications.value = []
      publicationsError.value = false
      return
    }
    const current = ++publicationEpoch
    publicationsLoading.value = true
    publicationsError.value = false
    try {
      const items: ConsumerKnowledgePublication[] = []
      for (let page = 1; ; ++page) {
        const result = await publicationApi.consumer(environment, page, 100)
        if (!alive || current !== publicationEpoch || environmentId.value !== environment) return
        items.push(...result.data)
        if (items.length >= result.total) break
        if (!result.data.length) throw new Error('开放知识库列表不完整，请重新读取。')
      }
      publications.value = items
    } catch (cause) {
      if (!alive || current !== publicationEpoch) return
      publications.value = []
      publicationsError.value = true
      // 失败原因只用于控制台排查，不直接把接口报错抛到页面。
      console.warn('[sand-ai] app-trial publications load failed:', errorDisplayText(cause, '读取失败'))
    } finally {
      if (alive && current === publicationEpoch) publicationsLoading.value = false
    }
  }

  watch(environmentId, () => { void loadPublications() }, { flush: 'sync' })

  // 管理员切换后清空页级凭证并重载环境，三个面板通过 key 重建。
  watch([() => userStore.info.id, () => userStore.accessToken], () => {
    ++environmentEpoch
    ++publicationEpoch
    apiKey.value = ''
    environments.value = []
    environmentsLoaded.value = false
    environmentId.value = null
    publications.value = []
    publicationsLoading.value = false
    publicationsError.value = false
    void loadEnvironments(true)
  }, { flush: 'sync' })

  onMounted(() => {
    void loadEnvironments(true)
  })

  onBeforeUnmount(() => {
    alive = false
    ++environmentEpoch
    ++publicationEpoch
    apiKey.value = ''
  })
</script>

<style scoped lang="scss">
  .app-trial-page {
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

  .credential-form {
    margin-bottom: 0;
  }

  .env-bar {
    display: flex;
    align-items: center;
    gap: 8px;
  }

  .env-select {
    width: 280px;
  }

  .page-tabs {
    flex: 1;
  }

  .panel-skeleton {
    padding: 16px 0;
  }

  .panel-mount {
    min-height: 120px;
  }
</style>
