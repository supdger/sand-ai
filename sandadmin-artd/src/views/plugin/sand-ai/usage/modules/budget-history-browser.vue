<template>
  <ElDrawer v-model="open" title="读取已有预算" size="min(1050px, 96vw)" append-to-body destroy-on-close>
    <p class="mb-3 text-sm">独立读取已登记范围的预算版本。当前草稿、原保存请求及发布／停用操作继续保留。各维度金额不能相加，未知金额不表示零。</p>
    <template v-if="canRead">
      <ElForm label-position="top" @submit.prevent>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
          <ElFormItem label="读取的预算范围">
            <ElSelect v-model="scopeKind"><ElOption v-for="item in scopeKinds" :key="item.value" :value="item.value" :label="item.label" /></ElSelect>
          </ElFormItem>
          <ElFormItem label="已登记对象引用">
            <ElInput v-model="scopeRef" :disabled="scopeKind === 'service'" :placeholder="scopeKind === 'capability' ? '真实能力代码，例如 chat' : '已登记对象的正整数 ID'" />
          </ElFormItem>
        </div>
        <p class="mb-3 text-sm">服务固定为 sand_ai；能力填写已登记代码，其他范围填写已登记 ID。是否可读取由正式后台核验。</p>
        <div class="flex flex-wrap gap-3 mb-3">
          <ElButton :loading="listLoading" @click="readList(1)">读取预算版本</ElButton>
          <ElButton :loading="activeLoading" @click="readActive">读取该范围当前额度与提醒</ElButton>
        </div>
      </ElForm>
      <ElAlert v-if="activeError" :title="activeError" type="warning" :closable="false" class="mb-3" />
      <ElAlert v-else-if="activeLoaded && !activeSummary" title="正式后台确认：此范围当前没有生效预算版本。未显示额度不表示额度为零。" type="info" :closable="false" class="mb-3" />
      <section v-if="activeSummary" aria-label="当前生效预算">
        <h3 class="mb-2 text-base">所选范围当前生效预算 · #{{ activeSummary.policy.id }} / 版本 {{ activeSummary.policy.revision }}</h3>
        <p class="mb-3 text-sm">{{ scopeLabel(activeSummary.policy.scope_kind) }} / {{ activeSummary.policy.scope_ref }} · {{ activeSummary.policy.period_start }} 至 {{ activeSummary.policy.period_end }} UTC。此范围额度不代表一次调用匹配的全部预算。</p>
        <ElAlert :title="alertText(activeSummary)" :type="activeSummary.alert.level === 'normal' ? 'info' : 'warning'" :closable="false" class="mb-3" />
        <ElDescriptions :column="2" border>
          <ElDescriptionsItem label="币种">{{ activeSummary.window.currency }}</ElDescriptionsItem>
          <ElDescriptionsItem label="限额">{{ activeSummary.window.limit_amount }}</ElDescriptionsItem>
          <ElDescriptionsItem label="当前可用">{{ activeSummary.window.available_amount }}</ElDescriptionsItem>
          <ElDescriptionsItem label="已结算">{{ activeSummary.window.settled_amount }}</ElDescriptionsItem>
          <ElDescriptionsItem label="已预占">{{ activeSummary.window.reserved_amount }}</ElDescriptionsItem>
          <ElDescriptionsItem label="待核实占用">{{ activeSummary.window.held_amount }}</ElDescriptionsItem>
        </ElDescriptions>
        <p class="my-3 text-sm">按本次正式读取显示；需更新时主动重新读取，不在此抽屉自动刷新或发送提醒。</p>
      </section>
      <ElAlert v-if="listError" :title="listError" type="warning" :closable="false" class="mb-3" />
      <ElEmpty v-else-if="listLoaded && policies.length === 0" description="此范围暂无预算版本" />
      <ElTable v-if="policies.length" :data="policies" v-loading="listLoading" row-key="id">
        <ElTableColumn prop="id" label="原版本 ID" width="110" />
        <ElTableColumn prop="revision" label="版本" width="80" />
        <ElTableColumn label="时段 UTC" min-width="300"><template #default="{ row }">{{ row.period_start }} 至 {{ row.period_end }}</template></ElTableColumn>
        <ElTableColumn label="限额" min-width="140"><template #default="{ row }">{{ row.limit_amount }} {{ row.currency }}</template></ElTableColumn>
        <ElTableColumn label="状态" width="110"><template #default="{ row }">{{ stateLabel(row.state) }}</template></ElTableColumn>
        <ElTableColumn label="读取" min-width="150"><template #default="{ row }"><ElButton link type="primary" @click="readPolicy(row.id)">读取原版本详情</ElButton></template></ElTableColumn>
      </ElTable>
      <ElPagination v-if="listLoaded && total > 20" :current-page="page" :page-size="20" :total="total" layout="prev, pager, next, total" class="my-3" @current-change="readList" />
      <ElDivider>直接读取原预算版本</ElDivider>
      <ElForm label-position="top" @submit.prevent>
        <ElFormItem label="原预算版本 ID（必须归属上方独立读取范围）"><ElInput v-model="policyText" /></ElFormItem>
        <ElButton :loading="policyLoading" @click="readPolicy()">按原 ID 读取详情</ElButton>
      </ElForm>
      <ElAlert v-if="policyError" :title="policyError" type="warning" :closable="false" class="mt-3" />
      <div v-if="policyLoading" v-loading="policyLoading" class="min-h-24" />
      <template v-else-if="selectedPolicy">
        <ElDescriptions :column="2" border class="mt-3">
          <ElDescriptionsItem label="原版本 ID / 版本">{{ selectedPolicy.id }} / {{ selectedPolicy.revision }}</ElDescriptionsItem>
          <ElDescriptionsItem label="正式读取状态">{{ stateLabel(selectedPolicy.state) }}</ElDescriptionsItem>
          <ElDescriptionsItem label="范围 / 对象">{{ scopeLabel(selectedPolicy.scope_kind) }} / {{ selectedPolicy.scope_ref }}</ElDescriptionsItem>
          <ElDescriptionsItem label="环境归属">{{ selectedPolicy.environment_id ?? '不限定单个环境' }}</ElDescriptionsItem>
          <ElDescriptionsItem label="时段 UTC" :span="2">{{ selectedPolicy.period_start }} 至 {{ selectedPolicy.period_end }}</ElDescriptionsItem>
          <ElDescriptionsItem label="配置限额">{{ selectedPolicy.limit_amount }} {{ selectedPolicy.currency }}</ElDescriptionsItem>
          <ElDescriptionsItem label="超额方式">{{ selectedPolicy.enforcement === 'hard' ? '硬限制' : '软限制' }}</ElDescriptionsItem>
          <ElDescriptionsItem label="未定价策略">{{ selectedPolicy.unpriced_policy === 'block' ? '阻止调用' : '允许调用' }}</ElDescriptionsItem>
          <ElDescriptionsItem label="未测得用量策略">{{ selectedPolicy.unmeasured_policy === 'hold' ? '保留占额' : '允许调用' }}</ElDescriptionsItem>
          <ElDescriptionsItem label="发布时间 UTC">{{ selectedPolicy.published_time ?? '未发布' }}</ElDescriptionsItem>
          <ElDescriptionsItem label="创建时间 UTC">{{ selectedPolicy.create_time }}</ElDescriptionsItem>
          <ElDescriptionsItem label="更新时间 UTC">{{ selectedPolicy.update_time }}</ElDescriptionsItem>
        </ElDescriptions>
        <ElAlert v-if="selectedPolicy.state === 'draft'" title="此版本仍为草稿，没有正式生效窗口；配置限额不代表当前可用额度，不按零展示汇总。" type="info" :closable="false" class="mt-3" />
        <ElButton v-else class="mt-3" :loading="summaryLoading" @click="readSummary">读取此原版本窗口</ElButton>
      </template>
      <ElAlert v-if="summaryError" :title="summaryError" type="warning" :closable="false" class="mt-3" />
      <section v-if="historicalSummary" aria-label="原预算版本窗口" class="mt-3">
        <h3 class="mb-2 text-base">原版本窗口 · #{{ historicalSummary.policy.id }} / 版本 {{ historicalSummary.policy.revision }}</h3>
        <p class="mb-3 text-sm">{{ stateLabel(historicalSummary.policy.state) }} · {{ scopeLabel(historicalSummary.policy.scope_kind) }} / {{ historicalSummary.policy.scope_ref }} · {{ historicalSummary.policy.period_start }} 至 {{ historicalSummary.policy.period_end }} UTC。此窗口为原版本记录，不代表该范围当前可用额度。</p>
        <ElAlert :title="alertText(historicalSummary)" :type="historicalSummary.alert.level === 'normal' ? 'info' : 'warning'" :closable="false" class="mb-3" />
        <ElDescriptions :column="2" border>
          <ElDescriptionsItem label="币种">{{ historicalSummary.window.currency }}</ElDescriptionsItem>
          <ElDescriptionsItem label="版本限额">{{ historicalSummary.window.limit_amount }}</ElDescriptionsItem>
          <ElDescriptionsItem label="版本窗口可用">{{ historicalSummary.window.available_amount }}</ElDescriptionsItem>
          <ElDescriptionsItem label="版本已结算">{{ historicalSummary.window.settled_amount }}</ElDescriptionsItem>
          <ElDescriptionsItem label="版本已预占">{{ historicalSummary.window.reserved_amount }}</ElDescriptionsItem>
          <ElDescriptionsItem label="版本待核实占用">{{ historicalSummary.window.held_amount }}</ElDescriptionsItem>
        </ElDescriptions>
      </section>
    </template>
    <ElAlert v-else title="需要预算查看权限才能读取已有预算。" type="info" :closable="false" />
  </ElDrawer>
</template>

<script setup lang="ts">
  import { computed, onBeforeUnmount, ref, watch } from 'vue'
  import { useAuth } from '@/hooks/core/useAuth'
  import { HttpError } from '@/utils/http/error'
  import { useUserStore } from '@/store/modules/user'
  import budgetApi from '../../api/budget'
  import type { BudgetPolicy, BudgetScope, BudgetScopeKind, BudgetSummary } from '../../api/budget'

  const props = defineProps<{ modelValue: boolean }>()
  const emit = defineEmits<{ 'update:modelValue': [value: boolean] }>()
  const open = computed({ get: () => props.modelValue, set: value => emit('update:modelValue', value) })
  const { hasAuth } = useAuth()
  const userStore = useUserStore()
  const adminId = computed(() => {
    const id = userStore.info.id
    return typeof id === 'number' && Number.isSafeInteger(id) && id > 0 ? id : null
  })
  const canRead = computed(() => hasAuth('sand_ai:budget:index'))
  const scopeKinds: { value: BudgetScopeKind; label: string }[] = [
    { value: 'organization', label: '组织' }, { value: 'application', label: '应用' },
    { value: 'environment', label: '环境' }, { value: 'service', label: '服务' },
    { value: 'capability', label: '能力' }, { value: 'model', label: '模型' }
  ]
  const scopeKind = ref<BudgetScopeKind>('environment')
  const scopeRef = ref('')
  const policyText = ref('')
  const policies = ref<BudgetPolicy[]>([])
  const selectedPolicy = ref<BudgetPolicy | null>(null)
  const activeSummary = ref<BudgetSummary | null>(null)
  const historicalSummary = ref<BudgetSummary | null>(null)
  const page = ref(1)
  const total = ref(0)
  const listLoaded = ref(false)
  const activeLoaded = ref(false)
  const listLoading = ref(false)
  const activeLoading = ref(false)
  const policyLoading = ref(false)
  const summaryLoading = ref(false)
  const listError = ref('')
  const activeError = ref('')
  const policyError = ref('')
  const summaryError = ref('')
  let alive = true
  let actorEpoch = 0
  let listGeneration = 0
  let activeGeneration = 0
  let policyGeneration = 0
  let summaryGeneration = 0
  const scopeLabel = (kind: BudgetScopeKind) => scopeKinds.find(item => item.value === kind)?.label ?? kind
  const stateLabel = (state: BudgetPolicy['state']) => ({ draft: '草稿', published: '已发布', archived: '已归档' })[state]
  function alertText(value: BudgetSummary): string {
    const labels = { normal: '正常', notice: '额度提醒', warning: '额度警告', exhausted: '额度耗尽' }
    return `正式窗口提醒：${labels[value.alert.level]}；阈值 ${value.alert.threshold_percent === null ? '未提供' : `${value.alert.threshold_percent}%`}。`
  }
  function positiveId(value: string): number | null {
    if (!/^[1-9][0-9]*$/.test(value)) return null
    const id = Number(value)
    return Number.isSafeInteger(id) ? id : null
  }
  function scopeTarget(): BudgetScope {
    const reference = scopeKind.value === 'service' ? 'sand_ai' : scopeRef.value
    if (!reference || (scopeKind.value === 'capability' ? !/^[a-z][a-z0-9_.-]{0,95}$/.test(reference) : scopeKind.value !== 'service' && !positiveId(reference)))
      throw new Error('请填写已登记的范围引用：能力使用真实代码，其他对象使用正整数 ID。')
    return { scope_kind: scopeKind.value, scope_ref: reference }
  }
  function sameScope(value: BudgetScope, scope: BudgetScope): boolean {
    return value.scope_kind === scope.scope_kind && value.scope_ref === scope.scope_ref
  }
  function assertPolicy(policy: BudgetPolicy, scope: BudgetScope, expectedId?: number): void {
    if (!policy || !Number.isSafeInteger(policy.id) || policy.id < 1 ||
        (expectedId !== undefined && policy.id !== expectedId) || !sameScope(policy, scope) ||
        !Number.isSafeInteger(policy.revision) || policy.revision < 1 ||
        !['draft', 'published', 'archived'].includes(policy.state) ||
        !['hard', 'soft'].includes(policy.enforcement) || !['block', 'allow'].includes(policy.unpriced_policy) ||
        !['hold', 'allow'].includes(policy.unmeasured_policy) ||
        typeof policy.currency !== 'string' || !/^[A-Z]{3}$/.test(policy.currency) ||
        typeof policy.limit_amount !== 'string' || !/^[0-9]+(?:\.[0-9]+)?$/.test(policy.limit_amount) ||
        typeof policy.period_start !== 'string' || typeof policy.period_end !== 'string' ||
        typeof policy.create_time !== 'string' || typeof policy.update_time !== 'string' ||
        !(policy.environment_id === null || (Number.isSafeInteger(policy.environment_id) && policy.environment_id > 0)) ||
        !(policy.published_time === null || typeof policy.published_time === 'string'))
      throw new Error('预算详情与原 ID、范围或版本事实不一致，暂不显示。')
  }
  function assertSummary(value: BudgetSummary, scope: BudgetScope, expectedId?: number): void {
    if (!value || !value.policy || !value.window || !value.alert) throw new Error('预算窗口响应不完整')
    assertPolicy(value.policy, scope, expectedId)
    const window = value.window
    if (value.policy.state === 'draft' || window.currency !== value.policy.currency || window.limit_amount !== value.policy.limit_amount ||
        ![window.limit_amount, window.reserved_amount, window.settled_amount, window.held_amount].every(amount => typeof amount === 'string' && /^[0-9]+(?:\.[0-9]+)?$/.test(amount)) ||
        typeof window.available_amount !== 'string' || !/^-?[0-9]+(?:\.[0-9]+)?$/.test(window.available_amount) ||
        !['normal', 'notice', 'warning', 'exhausted'].includes(value.alert.level) ||
        !(value.alert.threshold_percent === null || (typeof value.alert.threshold_percent === 'number' && Number.isFinite(value.alert.threshold_percent))))
      throw new Error('预算窗口与原版本金额、币种或提醒事实不一致，暂不显示。')
  }
  function context() { return { adminId: adminId.value, epoch: actorEpoch } }
  function current(start: ReturnType<typeof context>): boolean {
    return alive && open.value && canRead.value && start.adminId !== null && start.adminId === adminId.value && start.epoch === actorEpoch
  }
  function clearSummary() {
    ++summaryGeneration
    historicalSummary.value = null
    summaryLoading.value = false
    summaryError.value = ''
  }
  function clearPolicy() {
    ++policyGeneration
    selectedPolicy.value = null
    policyLoading.value = false
    policyError.value = ''
    clearSummary()
  }
  function clearScopeReads() {
    ++listGeneration
    ++activeGeneration
    policies.value = []
    page.value = 1
    total.value = 0
    listLoaded.value = false
    activeLoaded.value = false
    listLoading.value = false
    activeLoading.value = false
    listError.value = ''
    activeError.value = ''
    activeSummary.value = null
    clearPolicy()
  }
  function clearReadData() { ++actorEpoch; clearScopeReads() }
  watch(scopeKind, kind => { scopeRef.value = kind === 'service' ? 'sand_ai' : ''; clearScopeReads() }, { flush: 'sync' })
  watch(scopeRef, clearScopeReads, { flush: 'sync' })
  watch(policyText, clearPolicy, { flush: 'sync' })
  watch([adminId, canRead], () => {
    clearReadData()
    scopeKind.value = 'environment'
    scopeRef.value = ''
    policyText.value = ''
    open.value = false
  }, { flush: 'sync' })
  watch(open, value => { if (!value) clearReadData() }, { flush: 'sync' })
  onBeforeUnmount(() => { alive = false; clearReadData() })

  async function readList(nextPage = 1) {
    const start = context()
    if (!current(start)) return
    const generation = ++listGeneration
    listLoading.value = true
    listLoaded.value = false
    listError.value = ''
    policies.value = []
    try {
      const scope = scopeTarget()
      if (!Number.isSafeInteger(nextPage) || nextPage < 1) throw new Error('预算列表页码无效')
      const result = await budgetApi.list(scope, nextPage)
      if (!current(start) || generation !== listGeneration) return
      if (!result || !Array.isArray(result.data) || !Number.isSafeInteger(result.total) || result.total < 0) throw new Error('预算版本列表响应不完整')
      result.data.forEach(policy => assertPolicy(policy, scope))
      policies.value = result.data
      total.value = result.total
      page.value = nextPage
      listLoaded.value = true
    } catch (cause) {
      if (current(start) && generation === listGeneration) {
        if (cause instanceof HttpError && (cause.code === 401 || cause.code === 403)) {
          clearReadData()
          listError.value = '当前管理员无法读取此预算目标，已清除本抽屉旧数据。原草稿及未决操作继续保留。'
        } else listError.value = cause instanceof Error ? cause.message : '读取预算版本失败'
      }
    }
    finally { if (current(start) && generation === listGeneration) listLoading.value = false }
  }
  async function readActive() {
    const start = context()
    if (!current(start)) return
    const generation = ++activeGeneration
    activeLoading.value = true
    activeLoaded.value = false
    activeSummary.value = null
    activeError.value = ''
    try {
      const scope = scopeTarget()
      const result = await budgetApi.active(scope)
      if (!current(start) || generation !== activeGeneration) return
      if (!result || typeof result.active !== 'boolean' || (!result.active && result.summary !== null)) throw new Error('当前预算生效状态响应不完整')
      if (result.active) {
        if (!result.summary) throw new Error('当前预算窗口事实不完整')
        assertSummary(result.summary, scope)
        if (result.summary.policy.state !== 'published') throw new Error('当前预算未提供已发布版本事实')
        activeSummary.value = result.summary
      }
      activeLoaded.value = true
    } catch (cause) {
      if (current(start) && generation === activeGeneration) {
        if (cause instanceof HttpError && (cause.code === 401 || cause.code === 403)) {
          clearReadData()
          activeError.value = '当前管理员无法读取此预算目标，已清除本抽屉旧数据。原草稿及未决操作继续保留。'
        } else activeError.value = cause instanceof Error ? cause.message : '读取当前预算失败'
      }
    }
    finally { if (current(start) && generation === activeGeneration) activeLoading.value = false }
  }
  async function readPolicy(id?: number) {
    const start = context()
    if (!current(start)) return
    if (id !== undefined) policyText.value = String(id)
    clearPolicy()
    const generation = ++policyGeneration
    policyLoading.value = true
    try {
      const scope = scopeTarget()
      const target = positiveId(policyText.value)
      if (!target) throw new Error('请填写原预算版本的正整数 ID')
      const policy = await budgetApi.readPolicy(target)
      if (!current(start) || generation !== policyGeneration) return
      assertPolicy(policy, scope, target)
      selectedPolicy.value = policy
    } catch (cause) {
      if (current(start) && generation === policyGeneration) {
        if (cause instanceof HttpError && (cause.code === 401 || cause.code === 403)) {
          clearReadData()
          policyError.value = '当前管理员无法读取此预算目标，已清除本抽屉旧数据。原草稿及未决操作继续保留。'
        } else policyError.value = cause instanceof Error ? cause.message : '读取原预算版本失败'
      }
    }
    finally { if (current(start) && generation === policyGeneration) policyLoading.value = false }
  }
  async function readSummary() {
    const start = context()
    const policy = selectedPolicy.value
    if (!current(start) || !policy || policy.state === 'draft') return
    clearSummary()
    const generation = ++summaryGeneration
    const target = { ...policy }
    summaryLoading.value = true
    try {
      const scope = scopeTarget()
      const value = await budgetApi.summary(target.id)
      if (!current(start) || generation !== summaryGeneration || selectedPolicy.value?.id !== target.id) return
      assertSummary(value, scope, target.id)
      if (value.policy.revision !== target.revision) throw new Error('预算窗口与原版本号不一致')
      historicalSummary.value = value
    } catch (cause) {
      if (current(start) && generation === summaryGeneration) {
        if (cause instanceof HttpError && (cause.code === 401 || cause.code === 403)) {
          clearReadData()
          summaryError.value = '当前管理员无法读取此预算目标，已清除本抽屉旧数据。原草稿及未决操作继续保留。'
        } else summaryError.value = cause instanceof Error ? cause.message : '读取原版本窗口失败'
      }
    }
    finally { if (current(start) && generation === summaryGeneration) summaryLoading.value = false }
  }
</script>
