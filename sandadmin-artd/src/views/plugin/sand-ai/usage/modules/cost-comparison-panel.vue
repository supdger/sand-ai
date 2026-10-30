<template>
  <section ref="panel" tabindex="-1" aria-label="历史费用比较与当前价格估算">
    <ElCard class="art-table-card" shadow="never">
      <h3 class="m-0 mb-2 text-base">历史费用比较与当前价格估算</h3>
      <p class="mb-3 text-sm text-gray-500">从下方用量事件选择基线和当前调用，或填写调用 ID。后台核对输入及执行条件后比较计量金额；当前已发布价格只按本次实际 Token 数量估算。查询不会重新调用模型。缓存／复用节省未知：当前费用数据没有真实命中和对应基准证据，尚未具备计算依据。</p>
      <ElForm label-width="110px" @submit.prevent="loadComparison">
        <ElFormItem label="环境 ID"><ElInputNumber v-model="environmentId" :min="1" :controls="false" /></ElFormItem>
        <ElFormItem label="当前调用 ID"><ElInputNumber v-model="currentId" :min="1" :controls="false" /></ElFormItem>
        <ElFormItem label="基线调用 ID"><ElInputNumber v-model="baselineId" :min="1" :controls="false" /></ElFormItem>
        <ElFormItem label="比较价格版本">
          <ElInputNumber v-model="priceId" :min="1" :controls="false" placeholder="当前有效已发布价格" />
        </ElFormItem>
        <p class="mb-3 text-sm text-gray-500">留空使用当前调用同服务商、模型与能力的当前有效价格；填写版本时仍由后台核对适用范围和有效期。各币种分开，不换汇。</p>
        <ElButton type="primary" native-type="submit" :disabled="loading || !allowed" :loading="loading">比较已有费用</ElButton>
      </ElForm>
      <ElAlert v-if="error" :title="error" type="warning" :closable="false" class="mt-3" />
      <template v-if="result">
        <ElAlert :title="result.comparison.comparable ? '两次历史调用的输入与执行条件已核对；以下仅为计量差额，不代表缓存／复用节省或现金节省。' : reasonLabel(result.comparison.reason)" :type="result.comparison.comparable ? 'info' : 'warning'" :closable="false" class="mt-3" />
        <p class="mt-3 text-sm">当前调用 {{ result.current.id }} · {{ result.current.cost.capability }}；基线调用 {{ result.baseline.id }} · {{ result.baseline.cost.capability }}</p>
        <ElTable :data="result.comparison.currencies" border stripe>
          <ElTableColumn label="币种"><template #default="{ row }">{{ row.currency ?? '未知' }}</template></ElTableColumn>
          <ElTableColumn label="基线计量金额"><template #default="{ row }">{{ row.baseline_metered_amount ?? '未知' }}</template></ElTableColumn>
          <ElTableColumn label="当前计量金额"><template #default="{ row }">{{ row.current_metered_amount ?? '未知' }}</template></ElTableColumn>
          <ElTableColumn label="基线减当前"><template #default="{ row }">{{ row.difference_amount ?? '不可比较' }}</template></ElTableColumn>
        </ElTable>
        <p class="mt-2 text-sm text-gray-500">正值表示当前计量金额较少，负值表示较多；条件未核对或币种不同不计算差额。现金账单与退款仍以实际对账记录为准。</p>
        <h4 class="mt-4 mb-2 text-sm">按当前已发布价格估算同一用量</h4>
        <ElAlert v-if="result.scenario.state === 'unavailable'" :title="reasonLabel(result.scenario.reason)" type="warning" :closable="false" />
        <ElDescriptions v-else :column="1" border>
          <ElDescriptionsItem label="能力范围">{{ result.current.cost.capability }}</ElDescriptionsItem>
          <ElDescriptionsItem label="冻结价格版本">{{ result.current.cost.price.revision_id ?? '未知' }}</ElDescriptionsItem>
          <ElDescriptionsItem label="当前价格版本">{{ result.scenario.price_revision_id ?? '未知' }}</ElDescriptionsItem>
          <ElDescriptionsItem label="输入 / 输出 Token">{{ result.scenario.input_tokens ?? '未知' }} / {{ result.scenario.output_tokens ?? '未知' }}</ElDescriptionsItem>
          <ElDescriptionsItem label="原冻结计量金额">{{ money(result.scenario.frozen_amount, result.scenario.currency) }}</ElDescriptionsItem>
          <ElDescriptionsItem label="按当前价格估算">{{ money(result.scenario.published_amount, result.scenario.currency) }}</ElDescriptionsItem>
          <ElDescriptionsItem label="冻结减当前估算">{{ money(result.scenario.difference_amount, result.scenario.currency) }}</ElDescriptionsItem>
        </ElDescriptions>
        <p class="mt-2 text-sm text-gray-500">价格场景保留原调用实际用量，不推断新模型的输出或质量；估算不会改写历史费用、现金账单或预算，不表示已实现节省。</p>
      </template>
    </ElCard>
  </section>
</template>
<script setup lang="ts">
  import { computed, onBeforeUnmount, ref, watch } from 'vue'
  import { useAuth } from '@/hooks/core/useAuth'
  import { useAdminReadIdentity } from '../../composables/useAdminReadIdentity'
  import comparisonApi from '../../api/cost-comparison'
  import type { CostComparison, CostComparisonSelection, ComparisonReason, ScenarioReason } from '../../api/cost-comparison'
  const props = defineProps<{ selection: CostComparisonSelection | null }>()
  const { hasAuth } = useAuth()
  const identity = useAdminReadIdentity()
  const allowed = computed(() => hasAuth('sand_ai:usage:cost'))
  const panel = ref<HTMLElement>()
  const environmentId = ref<number>()
  const currentId = ref<number>()
  const baselineId = ref<number>()
  const priceId = ref<number>()
  const result = ref<CostComparison | null>(null)
  const loading = ref(false)
  const error = ref('')
  let generation = 0
  const reasons: Record<ComparisonReason | ScenarioReason, string> = {
    same_invocation: '请选另一历史调用作基线；同一调用不能作为两次费用差额的比较依据。',
    ownership_differs: '两次调用的可信归属不同，不能作同条件比较。',
    execution_context_differs: '能力、服务动作或来源不同，不能作同条件比较。',
    input_context_unverified: '缺少完整输入及执行上下文证明，不能确认同条件。',
    input_context_differs: '两次调用的输入、模型或执行上下文不同，不能作同条件比较。',
    usage_unavailable: '实际用量未完整测得，不能用零或估计值补算。',
    quantity_differs: '两次调用的实际输入或输出用量不同，不能作同用量比较。',
    pricing_unverified: '历史价格或计量金额证据不完整，不能计算差额。',
    currency_differs: '币种不同，分别展示金额，不换汇或计算差额。',
    frozen_price_unverified: '原调用冻结价格及计量金额证据不完整，无法估算差额。',
    published_price_unavailable: '同服务商、模型与能力没有唯一的当前有效已发布价格。',
    published_price_unverified: '所选价格的发布状态、有效期、能力或计量单位不适用。',
    amount_out_of_range: '估算金额超出支持范围，未生成比较结果。'
  }
  const reasonLabel = (reason: ComparisonReason | ScenarioReason | null): string => reason ? reasons[reason] ?? '后台无法证明可比条件，请核对原调用。' : '后台未提供可比条件说明。'
  const money = (amount: string | null, currency: string | null): string => amount === null ? '未知' : `${amount}${currency ? ` ${currency}` : '（币种未知）'}`
  function invalidate(): void { ++generation; result.value = null; loading.value = false; error.value = '' }
  watch([environmentId, currentId, baselineId, priceId, allowed], invalidate, { flush: 'sync' })
  watch(identity.epoch, () => {
    invalidate()
    environmentId.value = undefined
    currentId.value = undefined
    baselineId.value = undefined
    priceId.value = undefined
  }, { flush: 'sync' })
  watch(() => props.selection, (selection) => {
    invalidate()
    environmentId.value = selection?.environment_id
    currentId.value = selection?.current_invocation_id
    baselineId.value = selection?.baseline_invocation_id
    priceId.value = undefined
  }, { immediate: true })
  onBeforeUnmount(invalidate)
  defineExpose({ focus: () => { panel.value?.scrollIntoView({ block: 'start' }); panel.value?.focus({ preventScroll: true }) } })
  const validId = (value: number | undefined): value is number => value !== undefined && Number.isSafeInteger(value) && value > 0
  async function loadComparison(): Promise<void> {
    const owner = identity.capture()
    if (!allowed.value || loading.value || owner === null) return
    invalidate()
    const environment = environmentId.value; const current = currentId.value; const baseline = baselineId.value; const price = priceId.value ?? undefined
    if (!validId(environment) || !validId(current) || !validId(baseline) || (price !== undefined && !validId(price))) { error.value = '请填写有效环境、当前调用和基线调用 ID；价格版本可留空。'; return }
    const requestId = ++generation
    loading.value = true
    try {
      const value = await comparisonApi.read({ environment_id: environment, current_invocation_id: current, baseline_invocation_id: baseline, ...(price === undefined ? {} : { current_price_revision_id: price }) })
      if (identity.current(owner) && requestId === generation && allowed.value) result.value = value
    } catch (failure) {
      if (identity.current(owner) && requestId === generation && allowed.value) error.value = `费用比较读取失败：${failure instanceof Error ? failure.message : '后台未返回结果'}。请核对调用环境、归属及费用权限后重试。`
    } finally { if (identity.current(owner) && requestId === generation) loading.value = false }
  }
</script>
