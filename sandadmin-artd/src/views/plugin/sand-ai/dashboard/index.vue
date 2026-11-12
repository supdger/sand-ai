<template>
  <SandAiErrorBoundary>
    <div class="sand-ai-page">
      <ExpertSelectionHelp />
      <ElCard class="art-table-card sand-ai-page-card" shadow="never">
        <div class="dash-toolbar">
          <div>
            <h2 class="dash-title">概览</h2>
            <p class="dash-desc">
              查看应用、服务商、模型与调用的整体情况，并进入各功能页。
            </p>
          </div>
          <ElSpace wrap>
            <ElButton type="primary" @click="go('/sand-ai/onboarding')">开始使用</ElButton>
            <ElButton :loading="loading" type="primary" @click="loadDashboard">刷新</ElButton>
          </ElSpace>
        </div>

        <p v-if="bootstrapNotice" class="dash-bootstrap">
          {{ bootstrapNotice.message }}
          <ElButton v-if="bootstrapNotice.retryable" link type="primary" :loading="bootstrapBusy" @click="retryBootstrap">重试</ElButton>
        </p>

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
                <ElTableColumn prop="id" label="编号" width="70" />
                <ElTableColumn label="请求编号" min-width="140">
                  <template #default="{ row }">{{ row.request_id }}</template>
                </ElTableColumn>
                <ElTableColumn label="状态" width="100">
                  <template #default="{ row }">{{ runStateLabel(row.state) }}</template>
                </ElTableColumn>
                <ElTableColumn label="请求时间" width="170"><template #default="{ row }">{{ formatServerMinute(row.request_at) }}</template></ElTableColumn>
              </ElTable>
            </ElCol>
            <ElCol :xs="24" :lg="12">
              <h3 class="dash-section-title">近期审计</h3>
              <ElTable :data="recentAudits" row-key="id" border stripe empty-text="暂无审计记录">
                <ElTableColumn prop="id" label="编号" width="70" />
                <ElTableColumn label="操作" min-width="120">
                  <template #default="{ row }">{{ serviceActionLabel(row.action) }}</template>
                </ElTableColumn>
                <ElTableColumn prop="summary" label="摘要" min-width="160" />
                <ElTableColumn label="时间" width="170"><template #default="{ row }">{{ formatServerMinute(row.create_time) }}</template></ElTableColumn>
              </ElTable>
            </ElCol>
          </ElRow>

          <ElSpace wrap>
            <ElButton @click="go('/sand-ai/capability')">能力配置</ElButton>
            <ElButton @click="go('/sand-ai/file')">文件中心</ElButton>
            <ElButton @click="go('/sand-ai/task')">任务与会议试用</ElButton>
            <ElButton @click="go('/sand-ai/knowledge')">知识库配置</ElButton>
            <ElButton @click="go('/sand-ai/agent')">智能体配置</ElButton>
            <ElButton @click="go('/sand-iam/application')">应用管理（SandIAM）</ElButton>
          </ElSpace>
        </div>
      </ElCard>
    </div>
  </SandAiErrorBoundary>
</template>

<script setup lang="ts">
  import SandAiErrorBoundary from '../components/SandAiErrorBoundary.vue'
  import '../components/sandAiPage.css'
  import ExpertSelectionHelp from '../components/ExpertSelectionHelp.vue'
  import { computed, onMounted, ref } from 'vue'
  import { useRouter } from 'vue-router'
  import iamApi from '../api/iam'
  import providerApi from '../api/provider'
  import modelApi from '../api/model'
  import invocationApi from '../api/invocation'
  import usageApi from '../api/usage'
  import auditApi from '../api/audit'
  import type { SandAiAuditLog, SandAiInvocation } from '../api/types'
  import { runStateLabel, serviceActionLabel } from '../api/displayLabels'
  import { errorDisplayText } from '../api/errorText'
  import { formatServerMinute } from '../api/localTime'
  import { useSetupBootstrap } from '../composables/useSetupBootstrap'

  type SummaryCard = {
    key: string
    label: string
    value: string
    hint: string
    path: string
  }

  const router = useRouter()
  const { notice: bootstrapNotice, busy: bootstrapBusy, ensure: ensureBootstrap, retry: retryBootstrap } = useSetupBootstrap()
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
      hint: '已接入的应用',
      path: '/sand-iam/application'
    },
    {
      key: 'providers',
      label: '服务商',
      value: String(totals.value.providers),
      hint: '已配置的模型服务商',
      path: '/sand-ai/provider'
    },
    {
      key: 'models',
      label: '模型',
      value: String(totals.value.models),
      hint: '已登记的模型',
      path: '/sand-ai/model'
    },
    {
      key: 'calls',
      label: '调用记录',
      value: String(totals.value.invocations),
      hint: '最近的模型调用',
      path: '/sand-ai/invocation'
    },
    {
      key: 'usage',
      label: '用量记录',
      value: String(totals.value.usages),
      hint: '已记录的用量',
      path: '/sand-ai/usage'
    },
    {
      key: 'audit',
      label: '审计记录',
      value: String(totals.value.audits),
      hint: '后台操作记录',
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
      errorMessage.value = errorDisplayText(error, '加载总览失败')
    } finally {
      loading.value = false
    }
  }

  onMounted(() => {
    ensureBootstrap()
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

  .dash-bootstrap {
    margin: 0 0 12px;
    font-size: 13px;
    color: var(--el-color-warning);
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
