<template>
  <SandAiErrorBoundary>
    <div class="sand-ai-page usage-review-page">
      <div class="page-head">
        <div>
          <h1 class="page-title">费用核对</h1>
          <p class="page-desc">核对每一笔调用的费用、退款和账单</p>
        </div>
        <div class="page-head-actions">
          <ElButton v-if="canReadHistory" @click="historyOpen = true">账单历史</ElButton>
          <ElButton text @click="goUsage()">返回费用</ElButton>
        </div>
      </div>
      <BillingHistoryBrowser :key="actorEpoch" v-model="historyOpen" />
      <ElTabs v-model="activeTab" class="usage-review-tabs">
        <ElTabPane label="调用与费用" name="usage" :disabled="billingPending">
          <KeepAlive :key="`usage:${actorEpoch}`">
            <UsageCostPanel v-if="identity.actor.value !== null && activeTab === 'usage' && canUsage" @pending="refundPending = $event" />
          </KeepAlive>
          <KeepAlive :key="`budget:${actorEpoch}`">
            <BudgetPolicyPanel v-if="identity.actor.value !== null && activeTab === 'usage' && canBudget && !canUsage" />
          </KeepAlive>
          <ElAlert
            v-if="!canUsage && !canBudget"
            type="info"
            :closable="false"
            title="需要预算、用量、费用或退款记录权限才能查看对应操作；账单核对权限独立。"
          />
        </ElTabPane>
        <ElTabPane
          v-if="canPlatformValidation"
          label="模型验证费用"
          name="platform-validation"
          :disabled="financialPending"
        >
          <KeepAlive :key="`validation:${actorEpoch}`">
            <PlatformValidationPanel v-if="identity.actor.value !== null && activeTab === 'platform-validation'" />
          </KeepAlive>
        </ElTabPane>
        <ElTabPane label="账单核对" name="billing" :disabled="refundPending">
          <KeepAlive :key="`billing:${actorEpoch}`">
            <BillingReviewPanel
              v-if="identity.actor.value !== null && activeTab === 'billing' && canBilling"
              @pending="billingPending = $event"
            />
          </KeepAlive>
          <ElAlert
            v-if="!canBilling"
            type="info"
            :closable="false"
            title="需要账单查看权限才能查看导入批次。仅有审核或导入权限不会自动获得查看权限。"
          />
        </ElTabPane>
      </ElTabs>
    </div>
  </SandAiErrorBoundary>
</template>

<script setup lang="ts">
  import SandAiErrorBoundary from '../components/SandAiErrorBoundary.vue'
  import '../components/sandAiPage.css'
  import { computed, defineAsyncComponent, onBeforeUnmount, onMounted, ref, watch } from 'vue'
  import { onBeforeRouteLeave, useRoute, useRouter } from 'vue-router'
  import { ElMessageBox } from 'element-plus'
  import { useAuth } from '@/hooks/core/useAuth'
  import { useAdminReadIdentity } from '../composables/useAdminReadIdentity'

  type ReviewTab = 'usage' | 'platform-validation' | 'billing'

  const { hasAuth } = useAuth()
  const route = useRoute()
  const router = useRouter()
  const identity = useAdminReadIdentity()
  const actorEpoch = ref(0)
  const canUsage = computed(
    () =>
      hasAuth('sand_ai:usage:index') ||
      hasAuth('sand_ai:usage:summary') ||
      hasAuth('sand_ai:usage:cost') ||
      hasAuth('sand_ai:usage:adjustment:index') ||
      hasAuth('sand_ai:usage:adjustment:refund')
  )
  const historyOpen = ref(false)
  const canReadHistory = computed(() => hasAuth('sand_ai:billing:index') || hasAuth('sand_ai:billing:review') || hasAuth('sand_ai:usage:cost'))
  const BillingHistoryBrowser = defineAsyncComponent(() => import('../usage/modules/billing-history-browser.vue'))
  const canBilling = computed(() => hasAuth('sand_ai:billing:index'))
  const canBudget = computed(() => hasAuth('sand_ai:budget:index'))
  const canUsageEntry = computed(() => canUsage.value || canBudget.value)
  const canPlatformValidation = computed(() => hasAuth('sand_ai:usage:index'))
  const billingPending = ref(false)
  const refundPending = ref(false)
  const financialPending = computed(() => billingPending.value || refundPending.value)

  function tabFromQuery(value: unknown): ReviewTab {
    return value === 'platform-validation' || value === 'billing' ? value : 'usage'
  }
  function initialTab(): string {
    const tab = tabFromQuery(route.query.tab)
    if (tab === 'usage' && !canUsageEntry.value) return 'billing'
    if (tab === 'platform-validation' && !canPlatformValidation.value) return canUsageEntry.value ? 'usage' : 'billing'
    return tab
  }
  const activeTab = ref<string>(initialTab())
  watch(() => route.query.tab, value => {
    const tab = tabFromQuery(value)
    if (tab !== activeTab.value) activeTab.value = tab
  })
  watch(canPlatformValidation, (allowed) => {
    if (!allowed && activeTab.value === 'platform-validation')
      activeTab.value = canUsageEntry.value ? 'usage' : 'billing'
  })
  const UsageCostPanel = defineAsyncComponent(() => import('../usage/modules/usage-cost-panel.vue'))
  const BudgetPolicyPanel = defineAsyncComponent(
    () => import('../usage/modules/budget-policy-panel.vue')
  )
  const BillingReviewPanel = defineAsyncComponent(
    () => import('../usage/modules/billing-review-panel.vue')
  )
  const PlatformValidationPanel = defineAsyncComponent(
    () => import('../usage/modules/platform-validation-panel.vue')
  )
  function goUsage(): void {
    void router.push('/sand-ai/usage')
  }
  watch(identity.actor, () => {
    ++actorEpoch.value
    historyOpen.value = false
    billingPending.value = false
    refundPending.value = false
    activeTab.value = initialTab()
  }, { flush: 'sync' })
  function warnBeforeUnload(event: BeforeUnloadEvent) {
    if (financialPending.value) {
      event.preventDefault()
      event.returnValue = ''
    }
  }
  onMounted(() => window.addEventListener('beforeunload', warnBeforeUnload))
  onBeforeUnmount(() => window.removeEventListener('beforeunload', warnBeforeUnload))
  onBeforeRouteLeave(async () => {
    if (!financialPending.value) return true
    try {
      await ElMessageBox.confirm(
        '账单或退款记录操作仍在执行或结果尚未确认。离开将丢失本页冻结输入；请保留原请求号并查询原记录，勿换键重复提交。是否离开？',
        '结果尚未确认',
        { type: 'warning' }
      )
      return true
    } catch {
      return false
    }
  })
</script>

<style scoped>
  .usage-review-page {
    min-height: 0;
  }

  .page-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 16px;
  }

  .page-title {
    margin: 0;
    font-size: 20px;
    font-weight: 600;
  }

  .page-desc {
    margin: 6px 0 0;
    font-size: 13px;
    color: var(--el-text-color-secondary);
  }

  .page-head-actions {
    display: flex;
    gap: 8px;
    flex-shrink: 0;
  }

  .usage-review-tabs {
    display: flex;
    flex: 1;
    flex-direction: column;
    min-height: 0;
  }

  .usage-review-tabs :deep(.el-tabs__header) {
    flex-shrink: 0;
  }

  .usage-review-tabs :deep(.el-tabs__content) {
    flex: 1;
    min-height: 0;
  }
</style>
