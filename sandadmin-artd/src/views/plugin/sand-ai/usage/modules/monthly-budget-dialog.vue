<template>
  <ElDialog :model-value="modelValue" title="设预算" width="480px"
    :close-on-click-modal="!saving" :close-on-press-escape="!saving" :show-close="!saving"
    @update:model-value="emit('update:modelValue', $event)">
    <p class="mb-3 text-xs text-gray-400">调整范围：{{ scopeLabel }}</p>
    <ElForm label-width="110px" @submit.prevent>
      <ElFormItem label="每月预算">
        <ElInputNumber v-model="limit" :min="0" :precision="2" :controls="false" placeholder="不填表示不设上限"
          :disabled="saving" style="width: 180px" />
        <span class="ml-2 text-sm text-gray-500">{{ currency }}</span>
      </ElFormItem>
      <p v-if="limit === undefined || limit === null" class="-mt-2 mb-3 ml-[110px] text-xs text-gray-400">不设上限，花多少算多少</p>
      <ElFormItem label="用到多少时提醒">
        <ElSelect v-model="reminder" clearable placeholder="不提醒" :disabled="saving" style="width: 180px">
          <ElOption v-for="item in reminderOptions" :key="item.value" :label="item.label" :value="item.value" />
        </ElSelect>
      </ElFormItem>
      <ElFormItem label="用完后">
        <ElRadioGroup v-model="enforcement" :disabled="saving">
          <ElRadio value="hard">暂停 AI 调用</ElRadio>
          <ElRadio value="soft">照常用，只提醒</ElRadio>
        </ElRadioGroup>
      </ElFormItem>
      <p class="text-xs text-gray-400">正在进行的调用会做完，实际费用可能略超上限。</p>
      <ElAlert v-if="formError" type="error" :closable="false" :title="formError" class="mt-3" />
    </ElForm>
    <template #footer>
      <ElButton :disabled="saving" @click="emit('update:modelValue', false)">取消</ElButton>
      <ElButton type="primary" :loading="saving || activeLoading" @click="save">保存</ElButton>
    </template>
  </ElDialog>
</template>

<script setup lang="ts">
  import { computed, ref, watch } from 'vue'
  import { ElMessage, ElMessageBox } from 'element-plus'
  import { errorDisplayText } from '../../api/errorText'
  import budgetApi, { type BudgetPolicyExpectation, type BudgetScope, type BudgetSummary } from '../../api/budget'
  import { monthBoundsUtc, formatMoney } from './usagePeriod'

  /**
   * 「设预算」弹窗：把当前展示预算范围（缺省 service/sand_ai）的按月预算翻译成草稿+启用两步调用。
   * 同范围同月已有生效预算时先停用旧版本再启用新版本；覆盖本月但周期不同的
   * 预算不在此弹窗处理，引导去费用核对。组织与应用范围的写入需要验证环境见证，
   * 本弹窗不收集，提示去「费用核对」调整。
   */
  const props = defineProps<{
    modelValue: boolean
    /** 宿主时区名（来自 /usage/daily 响应） */
    timezone: string
    /** 当前选择的月份 'YYYY-MM' */
    month: string
    /** 本月已花金额，按币种分列（只含已定价部分） */
    spendByCurrency: { currency: string; amount: string }[]
    /** 费用页当前展示的已发布预算范围；缺省回到 service/sand_ai */
    budgetScope?: BudgetScope | null
    /** 该范围的人话标签，只读展示 */
    budgetScopeLabel?: string
  }>()
  const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [] }>()

  const limit = ref<number | undefined>(undefined)
  // ElSelect 清空时会写入空串，统一按「不提醒」处理
  const reminder = ref<number | '' | undefined>(undefined)
  const enforcement = ref<'hard' | 'soft'>('hard')
  const saving = ref(false)
  const formError = ref('')
  const activeSummary = ref<BudgetSummary | null>(null)
  const activeLoading = ref(false)
  const activeFailed = ref(false)
  let requestKey = ''

  const targetScope = computed<BudgetScope>(() =>
    props.budgetScope ?? { scope_kind: 'service', scope_ref: 'sand_ai' })
  const scopeLabel = computed(() => props.budgetScopeLabel || '服务·SandAI')
  const scopeNeedsWitness = computed(() =>
    targetScope.value.scope_kind === 'organization' || targetScope.value.scope_kind === 'application')

  const currency = computed(() => activeSummary.value?.window.currency ?? pickSpendCurrency() ?? 'CNY')

  function pickSpendCurrency(): string | null {
    // 优先人民币，其次按金额从大到小取最大的一笔币种
    const entries = [...props.spendByCurrency].sort((a, b) => Number(b.amount) - Number(a.amount))
    return entries.find(item => item.currency === 'CNY')?.currency ?? entries[0]?.currency ?? null
  }

  const reminderOptions = computed(() => {
    const base = [50, 80, 90]
    const current = reminder.value
    if (typeof current === 'number' && !base.includes(current)) {
      return [{ value: current, label: `${current}%` }, ...base.map(value => ({ value, label: `${value}%` }))]
    }
    return base.map(value => ({ value, label: `${value}%` }))
  })

  function reminderToPayload(value: number | '' | undefined): number {
    // 清空表示不提醒；后端最小提醒档为 100%，达到上限时才提示
    return typeof value === 'number' && Number.isFinite(value) ? value : 100
  }

  function policyToReminder(policy: BudgetSummary['policy']): number | undefined {
    return [50, 80, 90].includes(policy.reminder_percent) ? policy.reminder_percent : undefined
  }

  async function openDialog(): Promise<void> {
    formError.value = ''
    requestKey = `budget-monthly-${crypto.randomUUID()}`
    activeSummary.value = null
    activeFailed.value = false
    activeLoading.value = true
    try {
      const result = await budgetApi.active(targetScope.value)
      activeSummary.value = result.active ? result.summary : null
    } catch (cause) {
      activeFailed.value = true
      formError.value = errorDisplayText(cause, '读取当前预算失败')
    } finally {
      activeLoading.value = false
    }
    const policy = activeSummary.value?.policy
    if (policy) {
      limit.value = policy.limit_amount === null ? undefined : Number(policy.limit_amount)
      reminder.value = policyToReminder(policy)
      enforcement.value = policy.enforcement
    } else {
      limit.value = 100
      reminder.value = 80
      enforcement.value = 'hard'
    }
  }

  watch(() => props.modelValue, open => {
    if (open) void openDialog()
  })

  async function save(): Promise<void> {
    if (saving.value || activeLoading.value) return
    if (activeFailed.value) {
      formError.value = '无法确认当前预算状态，请关闭后重新打开再试'
      return
    }
    if (limit.value !== undefined && (!Number.isFinite(limit.value) || limit.value < 0)) {
      formError.value = '请填 0 或更大的数'
      return
    }
    if (scopeNeedsWitness.value) {
      formError.value = '组织或应用范围的预算需要验证环境，请到「费用核对」调整'
      return
    }
    const bounds = monthBoundsUtc(props.month, props.timezone)
    if (!bounds) {
      formError.value = '月份或时区无法识别，请重新打开页面再试'
      return
    }
    const activePolicy = activeSummary.value?.policy ?? null
    if (activePolicy) {
      const samePeriod = Date.parse(activePolicy.period_start.replace(' ', 'T') + 'Z') === Date.parse(bounds.from.replace(' ', 'T') + 'Z')
        && Date.parse(activePolicy.period_end.replace(' ', 'T') + 'Z') === Date.parse(bounds.to.replace(' ', 'T') + 'Z')
      if (!samePeriod) {
        formError.value = '当前已有一份覆盖本月的预算（版本 ' + activePolicy.revision + '），周期与按月重设不同；请到「费用核对」里调整。'
        return
      }
    }
    const limitValue = limit.value
    if (limitValue !== undefined && enforcement.value === 'hard') {
      const spend = props.spendByCurrency.find(item => item.currency === currency.value)
      const spent = spend ? Number(spend.amount) : 0
      if (Number.isFinite(spent) && spent > limitValue) {
        try {
          await ElMessageBox.confirm(
            `本月已经花了 ${formatMoney(spend?.amount ?? null, currency.value)}，比新预算多，保存后 AI 会马上暂停。`,
            '预算低于本月已花',
            { type: 'warning', confirmButtonText: '仍然保存', cancelButtonText: '取消' }
          )
        } catch {
          return
        }
      }
    }
    saving.value = true
    formError.value = ''
    try {
      const payload = {
        scope_kind: targetScope.value.scope_kind,
        scope_ref: targetScope.value.scope_ref,
        period_start: bounds.from,
        period_end: bounds.to,
        currency: currency.value,
        limit_amount: limitValue === undefined ? '' : String(limitValue),
        reminder_percent: reminderToPayload(reminder.value),
        enforcement: enforcement.value,
        unpriced_policy: 'block' as const,
        unmeasured_policy: 'hold' as const
      }
      const saved = await budgetApi.saveDraft(payload, requestKey)
      if (saved.state === 'not_saved') throw new Error('服务端确认本次未保存；请核对后重试')
      if (saved.scope_kind !== targetScope.value.scope_kind || saved.scope_ref !== targetScope.value.scope_ref
        || !Number.isSafeInteger(saved.id))
        throw new Error('保存收据与本次预算不一致；请到「费用核对」核对后再试')
      const expectedPolicy: BudgetPolicyExpectation = {
        revision: saved.revision,
        scope_kind: saved.scope_kind,
        scope_ref: saved.scope_ref,
        environment_id: saved.environment_id ?? null,
        period_start: saved.period_start,
        period_end: saved.period_end,
        currency: saved.currency,
        limit_amount: saved.limit_amount,
        reminder_percent: saved.reminder_percent,
        enforcement: saved.enforcement,
        unpriced_policy: saved.unpriced_policy,
        unmeasured_policy: saved.unmeasured_policy
      }
      if (activePolicy) {
        await budgetApi.archive(activePolicy.id)
        try {
          await budgetApi.publish(saved.id, undefined, expectedPolicy)
        } catch (cause) {
          throw new Error(`${errorDisplayText(cause, '新预算启用失败')}；旧预算已停用，请到「费用核对」启用草稿版本 ${saved.revision}`)
        }
      } else {
        await budgetApi.publish(saved.id, undefined, expectedPolicy)
      }
      ElMessage.success(limitValue === undefined
        ? '已保存，本月预算不设上限'
        : `已保存，本月预算 ${formatMoney(String(limitValue), currency.value)}`)
      emit('saved')
      emit('update:modelValue', false)
    } catch (cause) {
      formError.value = errorDisplayText(cause, '保存失败，请稍后重试')
    } finally {
      saving.value = false
    }
  }
</script>
