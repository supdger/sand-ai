<template>
  <div class="art-full-height usage-page">
    <div v-if="canReadHistory" class="mb-3">
      <ElButton @click="historyOpen = true">查看账单历史与原调用费用</ElButton>
    </div>
    <BillingHistoryBrowser :key="actorEpoch" v-model="historyOpen" />
    <ElTabs v-model="activeTab" class="usage-tabs">
      <ElTabPane label="调用费用" name="usage" :disabled="billingPending">
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
</template>

<script setup lang="ts">
  import { computed, defineAsyncComponent, onBeforeUnmount, onMounted, ref, watch } from 'vue'
  import { onBeforeRouteLeave } from 'vue-router'
  import { ElMessageBox } from 'element-plus'
  import { useAuth } from '@/hooks/core/useAuth'
  import { useAdminReadIdentity } from '../composables/useAdminReadIdentity'

  const { hasAuth } = useAuth()
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
  const BillingHistoryBrowser = defineAsyncComponent(() => import('./modules/billing-history-browser.vue'))
  const canBilling = computed(() => hasAuth('sand_ai:billing:index'))
  const canBudget = computed(() => hasAuth('sand_ai:budget:index'))
  const canUsageEntry = computed(() => canUsage.value || canBudget.value)
  const canPlatformValidation = computed(() => hasAuth('sand_ai:usage:index'))
  const billingPending = ref(false)
  const refundPending = ref(false)
  const financialPending = computed(() => billingPending.value || refundPending.value)
  const activeTab = ref(canUsageEntry.value ? 'usage' : 'billing')
  const UsageCostPanel = defineAsyncComponent(() => import('./modules/usage-cost-panel.vue'))
  const BudgetPolicyPanel = defineAsyncComponent(
    () => import('./modules/budget-policy-panel.vue')
  )
  const BillingReviewPanel = defineAsyncComponent(
    () => import('./modules/billing-review-panel.vue')
  )
  const PlatformValidationPanel = defineAsyncComponent(
    () => import('./modules/platform-validation-panel.vue')
  )
  watch(identity.actor, () => {
    ++actorEpoch.value
    historyOpen.value = false
    billingPending.value = false
    refundPending.value = false
    activeTab.value = canUsageEntry.value ? 'usage' : 'billing'
  }, { flush: 'sync' })
  watch(canPlatformValidation, (allowed) => {
    if (!allowed && activeTab.value === 'platform-validation')
      activeTab.value = canUsageEntry.value ? 'usage' : 'billing'
  })
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
  .usage-page {
    min-height: 0;
  }

  .usage-tabs {
    display: flex;
    flex: 1;
    flex-direction: column;
    min-height: 0;
  }

  .usage-tabs :deep(.el-tabs__header) {
    flex-shrink: 0;
  }

  .usage-tabs :deep(.el-tabs__content) {
    flex: 1;
    min-height: 0;
    overflow-y: auto;
  }
</style>
