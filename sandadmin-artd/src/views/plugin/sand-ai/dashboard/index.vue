<template>
  <div class="art-full-height">
    <ElCard class="art-table-card" shadow="never">
      <div class="dash-toolbar">
        <div>
          <h2 class="dash-title">总览</h2>
          <p class="dash-desc">
            管理列表 `index.total` + 近期调用/审计；运行面入口覆盖契约 1.5–1.8（能力档/任务/检索/Agent）。不发明未冻结 stats 字段。
          </p>
        </div>
        <ElSpace wrap>
          <ElTag type="success" effect="plain">解耦 · 已冻结 API</ElTag>
          <ElButton :loading="loading" type="primary" @click="loadDashboard">刷新</ElButton>
        </ElSpace>
      </div>

      <div v-if="loading" class="dash-body">
        <ElSkeleton :rows="5" animated />
      </div>

      <ElResult
        v-else-if="errorMessage"
        icon="error"
        title="加载失败"
        :sub-title="errorMessage"
      >
        <template #extra>
          <ElButton type="primary" @click="loadDashboard">重试</ElButton>
        </template>
      </ElResult>

      <div v-else class="dash-body">
        <ElRow :gutter="16">
          <ElCol v-for="card in summaryCards" :key="card.key" :xs="24" :sm="12" :lg="6">
            <div class="dash-card" role="button" @click="go(card.path)">
              <div class="dash-card__label">{{ card.label }}</div>
              <div class="dash-card__value">{{ card.value }}</div>
              <div class="dash-card__hint">{{ card.hint }}</div>
            </div>
          </ElCol>
        </ElRow>

        <ElRow :gutter="16">
          <ElCol :xs="24" :lg="12">
            <h3 class="dash-section-title">近期调用</h3>
            <ElTable
              :data="recentInvocations"
              row-key="id"
              border
              stripe
              empty-text="暂无调用记录"
              class="dash-clickable-table"
              @row-click="goInvocation"
            >
              <ElTableColumn prop="id" label="ID" width="70" />
              <ElTableColumn prop="request_id" label="request_id" min-width="140" />
              <ElTableColumn prop="state" label="state" width="100" />
              <ElTableColumn prop="request_at" label="request_at" width="170" />
            </ElTable>
          </ElCol>
          <ElCol :xs="24" :lg="12">
            <h3 class="dash-section-title">近期审计</h3>
            <ElTable :data="recentAudits" row-key="id" border stripe empty-text="暂无审计记录">
              <ElTableColumn prop="id" label="ID" width="70" />
              <ElTableColumn prop="action" label="action" min-width="120" />
              <ElTableColumn prop="summary" label="summary" min-width="160" />
              <ElTableColumn prop="create_time" label="create_time" width="170" />
            </ElTable>
          </ElCol>
        </ElRow>

        <ElSpace wrap>
          <ElButton @click="go('/sand-ai/capability')">能力配置 1.5</ElButton>
          <ElButton @click="go('/sand-ai/file')">文件中心</ElButton>
          <ElButton @click="go('/sand-ai/task')">任务中心 1.6</ElButton>
          <ElButton @click="go('/sand-ai/knowledge')">检索 1.7</ElButton>
          <ElButton @click="go('/sand-ai/agent')">Agent 1.8</ElButton>
          <ElButton @click="go('/sand-iam/application')">应用管理（SandIAM）</ElButton>
        </ElSpace>

        <p class="dash-footnote">
          计数来自各资源 `index` 的 `total`；不是独立 stats 接口。API 1.5–1.8 已冻结可消费；演示站部署不是前端前置。
        </p>
      </div>
    </ElCard>
  </div>
</template>

<script setup lang="ts">
  import { computed, onMounted, ref } from 'vue'
  import { useRouter } from 'vue-router'
  import iamApi from '../api/iam'
  import providerApi from '../api/provider'
  import modelApi from '../api/model'
  import invocationApi from '../api/invocation'
  import usageApi from '../api/usage'
  import auditApi from '../api/audit'
  import type { SandAiAuditLog, SandAiInvocation } from '../api/types'

  type SummaryCard = {
    key: string
    label: string
    value: string
    hint: string
    path: string
  }

  const router = useRouter()
  const loading = ref(false)
  const errorMessage = ref('')
  const totals = ref({
    applications: 0,
    providers: 0,
    models: 0,
    invocations: 0,
    usages: 0,
    audits: 0
  })
  const recentInvocations = ref<SandAiInvocation[]>([])
  const recentAudits = ref<SandAiAuditLog[]>([])

  const summaryCards = computed<SummaryCard[]>(() => [
    {
      key: 'apps',
      label: '应用',
      value: String(totals.value.applications),
      hint: 'SandIAM application/index.total',
      path: '/sand-iam/application'
    },
    {
      key: 'providers',
      label: '服务商',
      value: String(totals.value.providers),
      hint: 'provider/index.total',
      path: '/sand-ai/provider'
    },
    {
      key: 'models',
      label: '模型',
      value: String(totals.value.models),
      hint: 'model/index.total',
      path: '/sand-ai/model'
    },
    {
      key: 'calls',
      label: '调用记录',
      value: String(totals.value.invocations),
      hint: 'invocation/index.total',
      path: '/sand-ai/invocation'
    },
    {
      key: 'usage',
      label: '用量记录',
      value: String(totals.value.usages),
      hint: 'usage/index.total',
      path: '/sand-ai/usage'
    },
    {
      key: 'audit',
      label: '审计记录',
      value: String(totals.value.audits),
      hint: 'audit/index.total',
      path: '/sand-ai/audit'
    }
  ])

  function go(path: string): void {
    void router.push(path)
  }

  function goInvocation(row: SandAiInvocation): void {
    void router.push({
      path: '/sand-ai/invocation',
      query: row.request_id ? { request_id: row.request_id } : undefined
    })
  }

  async function loadDashboard(): Promise<void> {
    loading.value = true
    errorMessage.value = ''
    try {
      const page = { page: 1, limit: 5 }
      const [apps, providers, models, invocations, usages, audits] = await Promise.all([
        iamApi.listApplications(page),
        providerApi.list(page),
        modelApi.list(page),
        invocationApi.list(page),
        usageApi.list(page),
        auditApi.list(page)
      ])
      totals.value = {
        applications: apps.total,
        providers: providers.total,
        models: models.total,
        invocations: invocations.total,
        usages: usages.total,
        audits: audits.total
      }
      recentInvocations.value = invocations.data
      recentAudits.value = audits.data
    } catch (error) {
      errorMessage.value = error instanceof Error ? error.message : '加载总览失败'
    } finally {
      loading.value = false
    }
  }

  onMounted(() => {
    void loadDashboard()
  })
</script>

<style scoped lang="scss">
  .dash-toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 16px;
  }

  .dash-title {
    margin: 0;
    font-size: 18px;
    font-weight: 600;
    line-height: 1.4;
    color: var(--el-text-color-primary);
  }

  .dash-desc {
    margin: 4px 0 0;
    font-size: 13px;
    line-height: 1.5;
    color: var(--el-text-color-secondary);
    max-width: 720px;
  }

  .dash-body {
    display: flex;
    flex-direction: column;
    gap: 16px;
  }

  .dash-card {
    margin-bottom: 16px;
    padding: 16px;
    border: 1px solid var(--el-border-color-lighter);
    border-radius: 8px;
    background: var(--el-fill-color-blank);
    cursor: pointer;
    transition: border-color 0.15s ease;
  }

  .dash-card:hover {
    border-color: var(--el-color-primary-light-5);
  }

  .dash-card__label {
    font-size: 13px;
    color: var(--el-text-color-secondary);
  }

  .dash-card__value {
    margin-top: 8px;
    font-size: 28px;
    font-weight: 600;
    line-height: 1.2;
    color: var(--el-text-color-primary);
  }

  .dash-card__hint {
    margin-top: 6px;
    font-size: 12px;
    color: var(--el-text-color-secondary);
  }

  .dash-section-title {
    margin: 0 0 10px;
    font-size: 15px;
    font-weight: 600;
  }

  .dash-footnote {
    margin: 0;
    font-size: 12px;
    color: var(--el-text-color-secondary);
  }

  .dash-clickable-table {
    cursor: pointer;
  }
</style>
