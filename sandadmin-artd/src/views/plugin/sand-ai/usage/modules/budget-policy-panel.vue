<template>
  <ElCard class="art-table-card" shadow="never">
    <template #header>
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 class="m-0 text-base">调用预算</h2>
          <p class="mt-1 mb-0 text-sm text-gray-500">按 UTC 时段限制所选范围的调用额度；全部匹配预算共同生效，各维度金额不能相加。硬预算额度不足时阻止下一次外发，软预算允许超额并记录。未定价和未知用量按版本内策略处理，未知费用不代表零。</p>
        </div>
        <div class="flex flex-wrap gap-2">
          <ElButton v-if="hasAuth('sand_ai:budget:index')" @click="budgetHistoryOpen = true">读取已有预算</ElButton>
          <ElButton :loading="targetLoading" @click="loadTargets(true)">刷新对象</ElButton>
        </div>
      </div>
    </template>

    <ElAlert v-if="targetError" type="error" :closable="false" :title="targetError" class="mb-3" />
    <ElAlert v-if="saveRecoveryError" type="warning" :closable="false" :title="saveRecoveryError" class="mb-3" />
    <section v-if="pendingChanges.length" class="mb-3" aria-label="预算发布停用结果恢复">
      <h3 class="mb-2 text-sm">待确认的发布或停用</h3>
      <p class="text-sm text-gray-500">保留原预算版本，只读核对当前状态，不自动重发。未确认前暂停该版本的发布或停用。</p>
      <div v-for="operation in pendingChanges" :key="operation.id" class="mb-2">
        <span>{{ scopeKinds.find(item => item.value === operation.scope_kind)?.label }} / {{ operation.scope_ref }} ·
          版本 #{{ operation.id }} / {{ operation.revision }} · {{ operation.action === 'publish' ? '发布' : '停用' }}</span>
        <ElButton text :loading="recoveringChangeId === operation.id" :disabled="writing || Boolean(recoveringChangeId)"
          @click="recoverPolicyChange(operation)">只读核对原版本</ElButton>
      </div>
    </section>
    <ElAlert v-if="changeRecoveryError" type="warning" :closable="false" :title="changeRecoveryError" class="mb-3" />
    <ElAlert v-if="confirmedCurrentPolicy && sameScope(confirmedCurrentPolicy)" type="info" :closable="false" class="mb-3"
      :title="`保存结果已确认；最近读取的原版本 #${confirmedCurrentPolicy.id} / ${confirmedCurrentPolicy.revision} 当前为${confirmedCurrentPolicy.state === 'draft' ? '草稿' : confirmedCurrentPolicy.state === 'published' ? '已发布' : '已归档'}，额度 ${confirmedCurrentPolicy.limit_amount} ${confirmedCurrentPolicy.currency}。保存收据保留当时结果，当前版本按此正式读取显示。`" />
    <section v-if="pendingSaves.length" class="mb-3" aria-label="预算草稿保存结果恢复">
      <h3 class="mb-2 text-sm">待确认的草稿保存</h3>
      <p class="text-sm text-gray-500">以下保存保留原请求号，只读找回，不会自动重发。仅对应草稿暂时停止再次保存；其他预算仍可编辑。</p>
      <div v-for="operation in pendingSaves" :key="operation.requestKey" class="mb-2">
        <span>{{ scopeKinds.find(item => item.value === operation.payload.scope_kind)?.label }} / {{ operation.payload.scope_ref }} ·
          {{ operation.payload.id ? `草稿 #${operation.payload.id}` : '新建草稿' }} · {{ operation.requestKey }}</span>
        <ElButton text :loading="recoveringSaveKey === operation.requestKey" :disabled="Boolean(recoveringSaveKey)"
          @click="recoverDraftSave(operation)">只读找回保存结果</ElButton>
      </div>
    </section>
    <div v-for="rejected in notSavedDrafts" :key="rejected.operation.requestKey" class="mb-3">
      <ElAlert type="warning" :closable="false" :title="rejected.reason" />
      <ElButton text :disabled="writing || dialogOpen" @click="openNotSavedDraft(rejected.operation)">打开原草稿修改</ElButton>
    </div>
    <ElAlert type="info" :closable="false" title="预算管理仅限平台超级管理员；组织和应用使用 SandIAM 已登记范围。已开始任务的后续模型节点、工具和重试仍独立检查当前预算；已发送调用按冻结事实结算。断线、取消或结果不明时按原记录核对，不自动重新发送。" class="mb-3" />
    <ElAlert v-if="environmentError && needsWitness" type="error" :closable="false" :title="environmentError" class="mb-3" />
    <ElForm label-width="105px" @submit.prevent>
      <ElFormItem label="预算范围">
        <ElSelect v-model="scopeKind" :disabled="writing || dialogOpen">
          <ElOption v-for="item in scopeKinds" :key="item.value" :label="item.label" :value="item.value" />
        </ElSelect>
      </ElFormItem>
      <ElFormItem label="预算对象">
        <ElSelect v-model="scopeRef" filterable clearable :disabled="writing || dialogOpen" placeholder="选择已登记对象" style="max-width: 420px">
          <ElOption v-for="item in targets" :key="item.ref" :label="item.name" :value="item.ref" />
        </ElSelect>
        <ElButton v-if="hasMoreTargets" text :loading="targetLoading" @click="loadTargets()">加载更多</ElButton>
      </ElFormItem>
      <ElFormItem v-if="scopeKind === 'organization' || scopeKind === 'application'" label="验证环境">
        <ElSelect v-model="referenceEnvironmentId" filterable clearable :disabled="writing || dialogOpen" placeholder="选择归属该范围的环境，用于官方核验">
          <ElOption v-for="item in environments" :key="item.id" :label="`${item.name} (${item.code})`" :value="item.id" />
        </ElSelect>
        <ElButton v-if="hasMoreEnvironments" text :loading="environmentLoading" @click="loadEnvironments()">加载更多环境</ElButton>
        <p class="text-sm text-gray-500">验证环境仅用于核验归属；预算覆盖整个组织或应用。</p>
      </ElFormItem>
    </ElForm>

    <template v-if="scopeRef">
      <div class="mb-3 flex flex-wrap items-center gap-3">
        <ElAlert v-if="activeError" type="warning" :closable="false" :title="activeError" class="flex-1" />
        <ElAlert v-else-if="activeLoading" type="info" :closable="false" title="正在读取所选范围当前额度；生效状态尚未确认" class="flex-1" />
        <ElAlert v-else-if="activeSummary" :type="activeSummary.alert.level === 'normal' ? 'info' : 'warning'"
          :closable="false" :title="activeAlertTitle" class="flex-1">
          版本 {{ activeSummary.policy.revision }} · 可用 {{ activeSummary.window.available_amount }}
          / {{ activeSummary.window.limit_amount }} {{ activeSummary.window.currency }}；
          已结算 {{ activeSummary.window.settled_amount }}，已预占 {{ activeSummary.window.reserved_amount }}，待核实占用 {{ activeSummary.window.held_amount }}。
        </ElAlert>
        <ElAlert v-else-if="!activeLoading" type="info" :closable="false"
          title="所选范围没有正在生效的预算版本" class="flex-1" />
        <ElButton :loading="activeLoading" @click="loadActive(true)">刷新当前额度</ElButton>
      </div>
      <p class="mt-0 mb-3 text-sm text-gray-500">80% / 90% / 100% 提醒按已结算、已预占和待核实占用合计计算。专业重排尚未确认的实际费用没有金额可计入，原调用另有费用待核实提示。当前额度每分钟刷新，仅在本页提醒，不发送站外通知；所选范围的额度不代表一次调用匹配的全部预算。</p>
      <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
        <h3 class="m-0 text-sm">预算版本</h3>
        <div class="flex gap-2">
          <ElButton :loading="listLoading" @click="loadPolicies(1)">刷新</ElButton>
          <ElButton type="primary" :disabled="writing || hasPendingDraft({ scope_kind: scopeKind, scope_ref: scopeRef })" @click="newDraft">{{ retainedDrafts[draftKey(emptyDraft())] ? '继续新建草稿' : '新建草稿' }}</ElButton>
        </div>
      </div>
      <ElAlert v-if="listError" type="error" :closable="false" :title="listError" class="mb-3" />
      <ElTable v-loading="listLoading" :data="policies" row-key="id" empty-text="所选范围暂无预算版本">
        <ElTableColumn label="范围 / 对象" min-width="170">
          <template #default="{ row }">
            {{ scopeKinds.find(item => item.value === row.scope_kind)?.label }} /
            {{ targets.find(item => item.ref === row.scope_ref)?.name ?? row.scope_ref }}
          </template>
        </ElTableColumn>
        <ElTableColumn prop="revision" label="版本" width="75" />
        <ElTableColumn label="时段（UTC）" min-width="305">
          <template #default="{ row }">{{ row.period_start }} 至 {{ row.period_end }}</template>
        </ElTableColumn>
        <ElTableColumn label="限额" min-width="145">
          <template #default="{ row }">{{ row.limit_amount }} {{ row.currency }}</template>
        </ElTableColumn>
        <ElTableColumn label="方式" width="90">
          <template #default="{ row }">{{ row.enforcement === 'hard' ? '硬限制' : '软限制' }}</template>
        </ElTableColumn>
        <ElTableColumn label="未定价 / 未知用量" min-width="200">
          <template #default="{ row }">
            {{ row.unpriced_policy === 'block' ? '未定价阻止' : '未定价允许' }} /
            {{ row.unmeasured_policy === 'hold' ? '未知用量保留待核实' : '业务可继续，费用待核实' }}
          </template>
        </ElTableColumn>
        <ElTableColumn label="状态" width="110">
          <template #default="{ row }">
            <ElTag :type="row.state === 'published' ? 'success' : 'info'">
              {{ row.state === 'published' ? '已发布' : row.state === 'draft' ? '草稿' : '已归档' }}
            </ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn label="操作" min-width="180">
          <template #default="{ row }">
            <ElButton v-if="row.state === 'draft' && supportedMissingUsagePolicy(row)"
              link type="primary" :disabled="writing || hasPendingDraft(row) || hasPendingChange(row)" @click="editDraft(row)">编辑</ElButton>
            <ElButton v-if="row.state === 'draft' && supportedMissingUsagePolicy(row)"
              link type="primary"
              :loading="publishingId === row.id" :disabled="writing || dialogOpen || hasPendingDraft(row) || hasPendingChange(row)" @click="publish(row)">发布</ElButton>
            <span v-if="row.state === 'draft' && !supportedMissingUsagePolicy(row)">
              当前执行链暂不支持该策略
            </span>
            <ElButton v-if="row.state !== 'draft'" link type="primary"
              :loading="summaryLoadingId === row.id" @click="loadSummary(row)">查看额度</ElButton>
            <ElButton v-if="row.state === 'published' && hasAuth('sand_ai:budget:publish')" link type="warning"
              :loading="archivingId === row.id" :disabled="writing || dialogOpen || hasPendingChange(row)" @click="archive(row)">停用</ElButton>
          </template>
        </ElTableColumn>
      </ElTable>
      <ElPagination v-if="total > 20" class="mt-3" background layout="prev, pager, next, total"
        :current-page="page" :page-size="20" :total="total" @current-change="loadPolicies" />

      <ElAlert v-if="summaryError" type="error" :closable="false" :title="summaryError" class="mt-3" />
      <div v-if="summary" class="mt-3">
        <h3 class="mb-2 text-sm">版本 {{ summary.policy.revision }} 的额度（{{ summary.window.currency }}）</h3>
        <ElAlert :type="summary.policy.state === 'published' && summary.window.available_amount.startsWith('-') ? 'warning' : 'info'"
          :closable="false" :title="versionStatusTitle" class="mb-3" />
        <ElDescriptions border :column="3">
          <ElDescriptionsItem label="限额">{{ summary.window.limit_amount }}</ElDescriptionsItem>
          <ElDescriptionsItem label="可用">{{ summary.window.available_amount }}</ElDescriptionsItem>
          <ElDescriptionsItem label="已结算">{{ summary.window.settled_amount }}</ElDescriptionsItem>
          <ElDescriptionsItem label="已预占">{{ summary.window.reserved_amount }}</ElDescriptionsItem>
          <ElDescriptionsItem label="待核实占用">{{ summary.window.held_amount }}</ElDescriptionsItem>
        </ElDescriptions>
      </div>
    </template>

    <ElDialog v-model="dialogOpen" :title="draft.id ? '编辑预算草稿' : '新建预算草稿'" width="540px"
      :close-on-click-modal="!writing" :close-on-press-escape="!writing" :show-close="!writing">
      <template #header>
        <div class="flex flex-wrap items-center gap-3">
          <span>{{ draft.id ? '编辑预算草稿' : '新建预算草稿' }}</span>
          <ElButton v-if="hasAuth('sand_ai:budget:index')" @click.stop="budgetHistoryOpen = true">读取已有预算</ElButton>
        </div>
      </template>
      <ElForm label-width="115px" @submit.prevent>
        <ElFormItem v-if="needsWitness" label="验证环境">
          <ElSelect v-model="referenceEnvironmentId" filterable clearable :disabled="writing || Boolean(currentPendingSave)" placeholder="选择归属该范围的环境">
            <ElOption v-for="item in environments" :key="item.id" :label="`${item.name} (${item.code})`" :value="item.id" />
          </ElSelect>
          <ElButton v-if="hasMoreEnvironments" text :loading="environmentLoading" @click="loadEnvironments()">加载更多环境</ElButton>
          <ElAlert v-if="environmentError" type="error" :closable="false" :title="environmentError" />
          <ElButton v-if="environmentError" text :loading="environmentLoading" @click="loadEnvironments(true)">重读验证环境</ElButton>
        </ElFormItem>
        <ElFormItem label="预算对象">
          {{ scopeKinds.find(item => item.value === draft.scope_kind)?.label }} · {{ targets.find(item => item.ref === draft.scope_ref)?.name ?? draft.scope_ref }}
        </ElFormItem>
        <ElFormItem label="开始时间 UTC">
          <ElInput v-model="draft.period_start" :disabled="writing || Boolean(currentPendingSave)" placeholder="YYYY-MM-DD HH:mm:ss" />
        </ElFormItem>
        <ElFormItem label="结束时间 UTC">
          <ElInput v-model="draft.period_end" :disabled="writing || Boolean(currentPendingSave)" placeholder="YYYY-MM-DD HH:mm:ss" />
        </ElFormItem>
        <ElFormItem label="币种">
          <ElInput v-model="draft.currency" :disabled="writing || Boolean(currentPendingSave)" maxlength="3" placeholder="例如 CNY" />
        </ElFormItem>
        <ElFormItem label="额度">
          <ElInput v-model="draft.limit_amount" :disabled="writing || Boolean(currentPendingSave)" placeholder="正数，最多 6 位小数" />
        </ElFormItem>
        <ElFormItem label="超额方式">
          <ElRadioGroup v-model="draft.enforcement" :disabled="writing || Boolean(currentPendingSave)">
            <ElRadio value="hard">硬限制：阻止调用</ElRadio>
            <ElRadio value="soft">软限制：允许并记录</ElRadio>
          </ElRadioGroup>
        </ElFormItem>
        <template v-if="draft.enforcement === 'soft'">
          <ElFormItem label="未定价调用">
            <ElRadioGroup v-model="draft.unpriced_policy" :disabled="writing || Boolean(currentPendingSave)">
              <ElRadio value="block">阻止</ElRadio>
              <ElRadio value="allow">专业重排允许，费用待核实</ElRadio>
            </ElRadioGroup>
          </ElFormItem>
          <ElFormItem label="未知用量">
            <ElRadioGroup v-model="draft.unmeasured_policy" :disabled="writing || Boolean(currentPendingSave)">
              <ElRadio value="hold">保留待核实</ElRadio>
              <ElRadio value="allow">专业重排业务可继续，费用待核实</ElRadio>
            </ElRadioGroup>
          </ElFormItem>
          <ElAlert type="warning" :closable="false"
            title="允许策略由现有专业重排执行链支持；其他能力仍要求未定价阻止、未知用量保留待核实，遇到不支持的策略会在外发前拒绝。可选择专业重排能力或模型范围，避免影响其他调用。允许未知费用不表示免费或结算完成，也不会借用之后发布的价格。" class="mb-3" />
        </template>
        <ElAlert type="info" :closable="false"
          :title="draft.enforcement === 'hard'
            ? '硬预算要求调用前可冻结价格与可靠用量上界，未定价阻止、未知用量保留待核实。无法证明上界的专业重排在发送前拒绝。时段最长 366 天，发布后不可编辑。'
            : '软预算允许超额并记录。专业重排可以保留没有可靠上界的未知费用义务，真实量按调用前冻结价格结算；缺量或缺价格仍待核实。其他能力沿原可靠上界要求。时段最长 366 天，发布后不可编辑。'" />
      </ElForm>
      <ElAlert v-if="formError" type="error" :closable="false" :title="formError" class="mt-3" />
      <template #footer>
        <ElButton :disabled="writing" @click="closeSavedDraft">放弃本页编辑</ElButton>
        <ElButton :disabled="writing" @click="dialogOpen = false">关闭并保留编辑</ElButton>
        <ElButton v-if="currentPendingSave" :loading="recoveringSaveKey === currentPendingSave.requestKey"
          :disabled="writing || Boolean(recoveringSaveKey)" @click="recoverDraftSave(currentPendingSave)">只读找回保存结果</ElButton>
        <ElButton type="primary" :loading="writing" :disabled="!validDraft" @click="saveDraft">保存草稿</ElButton>
      </template>
    </ElDialog>
    <BudgetHistoryBrowser :key="historyIdentityEpoch" v-model="budgetHistoryOpen" />
  </ElCard>
</template>

<script setup lang="ts">
  import { computed, defineAsyncComponent, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
  import { ElMessage, ElMessageBox } from 'element-plus'
  import { useAuth } from '@/hooks/core/useAuth'
  import { useUserStore } from '@/store/modules/user'
  import budgetApi, { type BudgetDraft, type BudgetDraftNotSaved, type BudgetPolicy, type BudgetPolicyExpectation, type BudgetScope, type BudgetScopeKind, type BudgetSummary } from '../../api/budget'
  import iamApi, { type SandIamEnvironmentReference } from '../../api/iam'

  const { hasAuth } = useAuth()
  const budgetHistoryOpen = ref(false)
  const historyIdentityEpoch = ref(0)
  const BudgetHistoryBrowser = defineAsyncComponent(() => import('./budget-history-browser.vue'))
  const userStore = useUserStore()
  const scopeKinds: { value: BudgetScopeKind; label: string }[] = [
    { value: 'organization', label: '组织' }, { value: 'application', label: '应用' },
    { value: 'environment', label: '环境' }, { value: 'service', label: '服务' },
    { value: 'capability', label: '能力' }, { value: 'model', label: '模型' }
  ]
  const scopeKind = ref<BudgetScopeKind>('environment')
  const scopeRef = ref<string>()
  const referenceEnvironmentId = ref<number>()
  const targets = ref<{ ref: string; name: string }[]>([])
  const targetLoading = ref(false)
  const targetError = ref('')
  const targetPage = ref(0)
  const targetCount = ref(0)
  const targetTotal = ref(0)
  const hasMoreTargets = computed(() => targetCount.value < targetTotal.value)
  const environments = ref<SandIamEnvironmentReference[]>([])
  const environmentLoading = ref(false)
  const environmentError = ref('')
  const environmentPage = ref(0)
  const environmentCount = ref(0)
  const environmentTotal = ref(0)
  const hasMoreEnvironments = computed(() => environmentCount.value < environmentTotal.value)
  const policies = ref<BudgetPolicy[]>([])
  const page = ref(1)
  const total = ref(0)
  const listLoading = ref(false)
  const listError = ref('')
  const dialogOpen = ref(false)
  const currentWrite = ref(false)
  const writeFlight = ref(false)
  const writing = computed(() => currentWrite.value || writeFlight.value)
  const publishingId = ref<number | null>(null)
  const archivingId = ref<number | null>(null)
  const formError = ref('')
  type PendingDraftSave = { requestKey: string; payload: BudgetDraft }
  type PendingPolicyChange = BudgetScope & { id: number; revision: number; action: 'publish' | 'archive'; expectedPolicy?: BudgetPolicyExpectation }
  const pendingChanges = ref<PendingPolicyChange[]>([])
  const recoveringChangeId = ref<number | null>(null)
  const changeRecoveryError = ref('')
  const retainedDrafts = ref<Record<string, BudgetDraft>>({})
  let savedDraftClosing = false
  let draftBaseline = ''
  const pendingSaves = ref<PendingDraftSave[]>([])
  const notSavedDrafts = ref<{ operation: PendingDraftSave; reason: string }[]>([])
  const confirmedCurrentPolicy = ref<BudgetPolicy | null>(null)
  const recoveringSaveKey = ref<string | null>(null)
  const saveRecoveryError = ref('')
  const saveStorageReady = ref(false)
  const adminId = computed(() => {
    const id = userStore.info.id
    return typeof id === 'number' && Number.isSafeInteger(id) && id > 0 ? id : null
  })
  const summary = ref<BudgetSummary | null>(null)
  const summaryError = ref('')
  const summaryLoadingId = ref<number | null>(null)
  const activeSummary = ref<BudgetSummary | null>(null)
  const activeLoading = ref(false)
  const activeError = ref('')
  const selectedScope = (): BudgetScope | null => scopeRef.value ? { scope_kind: scopeKind.value, scope_ref: scopeRef.value } : null
  const sameScope = (value: BudgetScope): boolean => value.scope_kind === scopeKind.value && value.scope_ref === scopeRef.value
  const emptyDraft = (): BudgetDraft => ({ scope_kind: scopeKind.value, scope_ref: scopeRef.value ?? '',
    period_start: '', period_end: '', currency: '', limit_amount: '', enforcement: 'hard', unpriced_policy: 'block', unmeasured_policy: 'hold' })
  const draft = ref<BudgetDraft>(emptyDraft())
  function supportedMissingUsagePolicy(policy: Pick<BudgetPolicy, 'enforcement' | 'unpriced_policy' | 'unmeasured_policy'>): boolean {
    return policy.enforcement === 'hard'
      ? policy.unpriced_policy === 'block' && policy.unmeasured_policy === 'hold'
      : policy.enforcement === 'soft' &&
        (policy.unpriced_policy === 'block' || policy.unpriced_policy === 'allow') &&
        (policy.unmeasured_policy === 'hold' || policy.unmeasured_policy === 'allow')
  }
  watch(() => draft.value.enforcement, enforcement => {
    if (enforcement === 'hard') {
      draft.value.unpriced_policy = 'block'
      draft.value.unmeasured_policy = 'hold'
    }
  }, { flush: 'sync' })
  const currentPendingSave = computed(() => pendingSaves.value.find(item => item.payload.scope_kind === draft.value.scope_kind
    && item.payload.scope_ref === draft.value.scope_ref && item.payload.id === draft.value.id) ?? null)
  const needsWitness = computed(() => scopeKind.value === 'organization' || scopeKind.value === 'application')
  const validDraft = computed(() => sameScope(draft.value) && (!needsWitness.value || !!referenceEnvironmentId.value) &&
    /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(draft.value.period_start) &&
    /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(draft.value.period_end) && draft.value.period_end > draft.value.period_start &&
    /^[A-Za-z]{3}$/.test(draft.value.currency.trim()) && /^\d{1,14}(?:\.\d{1,6})?$/.test(draft.value.limit_amount.trim()) &&
    /[1-9]/.test(draft.value.limit_amount) && supportedMissingUsagePolicy(draft.value) &&
    !writing.value && !hasPendingDraft(draft.value) && !hasPendingChange(draft.value))
  let live = true
  let actorEpoch = 0
  let targetEpoch = 0
  let environmentEpoch = 0
  let listEpoch = 0
  let summaryEpoch = 0
  let activeEpoch = 0
  let activeTimer: ReturnType<typeof setInterval> | null = null
  const activeAlertTitle = computed(() => {
    const level = activeSummary.value?.alert.level
    if (level === 'exhausted') return activeSummary.value?.policy.enforcement === 'hard' ? '当前预算额度占用已达 100%；下一次外发额度不足时将被拒绝' : '当前预算额度占用已达 100%；软限制仍允许超额并记录'
    if (level === 'warning') return '当前预算额度占用已达至少 90%'
    if (level === 'notice') return '当前预算额度占用已达至少 80%'
    return '当前预算额度正常'
  })
  const versionStatusTitle = computed(() => {
    const version = summary.value
    if (!version) return ''
    if (version.policy.state === 'archived')
      return '该版本已停用，不再限制新的预占；以下为保留的历史额度。已预占、待核实占用和已发生费用不会因停用释放，其他当前匹配预算仍可能限制调用。'
    if (activeLoading.value || activeError.value)
      return '该版本当前是否生效尚未确认，请刷新当前额度。以下为该版本记录，不能据此判断新调用是否可用。'
    const current = activeSummary.value
    if (!current || current.policy.id !== version.policy.id)
      return '最近刷新未显示该版本正在生效；只有处于 UTC 生效时段的已发布预算才限制新预占。历史占额保留，新调用仍检查全部当前匹配预算。'
    if (current.window.available_amount.startsWith('-'))
      return version.policy.enforcement === 'hard'
        ? '最近刷新时该硬预算正在生效且占用已超额，会阻止额度不足的新预占；已发送调用仍按冻结事实结算。'
        : '最近刷新时该软预算正在生效且占用已超额，允许超额并记录；其他匹配硬预算仍可能阻止新预占。'
    return '最近刷新时该版本正在生效；此处仅显示本版本额度，新调用仍检查全部匹配预算。已发送调用按冻结事实结算。'
  })
  function hasPendingDraft(target: BudgetScope & { id?: number }): boolean {
    return pendingSaves.value.some(item => item.payload.scope_kind === target.scope_kind
      && item.payload.scope_ref === target.scope_ref && item.payload.id === target.id)
  }
  function draftKey(value: BudgetScope & { id?: number }): string {
    return JSON.stringify([value.scope_kind, value.scope_ref, value.id ?? null])
  }
  function hasPendingChange(policy: { id?: number }): boolean {
    return pendingChanges.value.some(item => item.id === policy.id)
  }
  function closeSavedDraft(): void {
    delete retainedDrafts.value[draftKey(draft.value)]
    savedDraftClosing = true
    dialogOpen.value = false
  }
  function invalidatePolicyViews(): void {
    ++listEpoch; ++summaryEpoch; ++activeEpoch
    policies.value = []; total.value = 0; listLoading.value = false; listError.value = ''
    confirmedCurrentPolicy.value = null
    summary.value = null; summaryError.value = ''; summaryLoadingId.value = null
    activeSummary.value = null; activeError.value = ''; activeLoading.value = false
  }
  function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value)
  }
  function isScopeKind(value: unknown): value is BudgetScopeKind {
    return scopeKinds.some(item => item.value === value)
  }
  function readStoredDraft(value: unknown): BudgetDraft | null {
    if (!isRecord(value) || !isScopeKind(value.scope_kind) || typeof value.scope_ref !== 'string' || !value.scope_ref
      || typeof value.period_start !== 'string' || typeof value.period_end !== 'string'
      || typeof value.currency !== 'string' || !/^[A-Z]{3}$/.test(value.currency)
      || typeof value.limit_amount !== 'string' || !/^\d{1,14}(?:\.\d{1,6})?$/.test(value.limit_amount)
      || (value.enforcement !== 'hard' && value.enforcement !== 'soft')
      || (value.unpriced_policy !== 'block' && value.unpriced_policy !== 'allow')
      || (value.unmeasured_policy !== 'hold' && value.unmeasured_policy !== 'allow')
      || (value.enforcement === 'hard' && (value.unpriced_policy !== 'block' || value.unmeasured_policy !== 'hold'))
      || (value.id !== undefined && (typeof value.id !== 'number' || !Number.isSafeInteger(value.id) || value.id < 1))
      || (value.reference_environment_id !== undefined && (typeof value.reference_environment_id !== 'number'
        || !Number.isSafeInteger(value.reference_environment_id) || value.reference_environment_id < 1))) return null
    return {
      scope_kind: value.scope_kind, scope_ref: value.scope_ref, period_start: value.period_start,
      period_end: value.period_end, currency: value.currency, limit_amount: value.limit_amount,
      enforcement: value.enforcement, unpriced_policy: value.unpriced_policy, unmeasured_policy: value.unmeasured_policy,
      ...(typeof value.id === 'number' ? { id: value.id } : {}),
      ...(typeof value.reference_environment_id === 'number' ? { reference_environment_id: value.reference_environment_id } : {})
    }
  }
  function freezePolicy(policy: BudgetPolicy): BudgetPolicyExpectation {
    return { revision: policy.revision, scope_kind: policy.scope_kind, scope_ref: policy.scope_ref,
      environment_id: policy.environment_id, period_start: policy.period_start, period_end: policy.period_end,
      currency: policy.currency, limit_amount: policy.limit_amount, enforcement: policy.enforcement,
      unpriced_policy: policy.unpriced_policy, unmeasured_policy: policy.unmeasured_policy }
  }
  function readStoredExpectation(value: unknown): BudgetPolicyExpectation | null {
    const fields = ['revision', 'scope_kind', 'scope_ref', 'environment_id', 'period_start', 'period_end',
      'currency', 'limit_amount', 'enforcement', 'unpriced_policy', 'unmeasured_policy']
    if (!isRecord(value) || Object.keys(value).length !== fields.length || Object.keys(value).some(key => !fields.includes(key))
      || typeof value.revision !== 'number' || !Number.isSafeInteger(value.revision) || value.revision < 1
      || (value.environment_id !== null && (typeof value.environment_id !== 'number'
        || !Number.isSafeInteger(value.environment_id) || value.environment_id < 1))) return null
    const stored = readStoredDraft(value)
    if (!stored || !/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(stored.period_start)
      || !/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(stored.period_end)
      || stored.period_end <= stored.period_start
      || (stored.scope_kind === 'environment' ? String(value.environment_id) !== stored.scope_ref : value.environment_id !== null)) return null
    return { revision: value.revision, scope_kind: stored.scope_kind, scope_ref: stored.scope_ref,
      environment_id: value.environment_id, period_start: stored.period_start, period_end: stored.period_end,
      currency: stored.currency, limit_amount: stored.limit_amount, enforcement: stored.enforcement,
      unpriced_policy: stored.unpriced_policy, unmeasured_policy: stored.unmeasured_policy }
  }
  function matchesExpectation(policy: BudgetPolicy, expected: BudgetPolicyExpectation): boolean {
    const amount = decimalSix(policy.limit_amount)
    return amount !== null && amount === decimalSix(expected.limit_amount) && policy.revision === expected.revision
      && policy.scope_kind === expected.scope_kind && policy.scope_ref === expected.scope_ref
      && policy.environment_id === expected.environment_id && policy.period_start === expected.period_start
      && policy.period_end === expected.period_end && policy.currency === expected.currency
      && policy.enforcement === expected.enforcement && policy.unpriced_policy === expected.unpriced_policy
      && policy.unmeasured_policy === expected.unmeasured_policy
  }
  function saveStorageKey(actorId: number): string { return `sand-ai:budget-draft-save:${actorId}` }
  function restorePendingSaves(): void {
    pendingSaves.value = []; pendingChanges.value = []; retainedDrafts.value = {}; notSavedDrafts.value = []
    recoveringSaveKey.value = null; recoveringChangeId.value = null; changeRecoveryError.value = ''; confirmedCurrentPolicy.value = null
    saveStorageReady.value = false; saveRecoveryError.value = ''
    const actorId = adminId.value
    if (!actorId) return
    try {
      const raw = sessionStorage.getItem(saveStorageKey(actorId))
      if (raw !== null) {
        const value: unknown = JSON.parse(raw)
        if (!isRecord(value) || value.version !== 1 || value.actorId !== actorId || !Array.isArray(value.operations))
          throw new Error('本地原请求记录格式不一致，不能覆盖或重新发送')
        const operations: PendingDraftSave[] = []
        const keys = new Set<string>()
        for (const item of value.operations) {
          if (!isRecord(item) || typeof item.requestKey !== 'string' || !/^[\x21-\x7e]{1,128}$/.test(item.requestKey)
            || keys.has(item.requestKey)) throw new Error('本地原请求记录无效，不能覆盖或重新发送')
          const payload = readStoredDraft(item.payload)
          if (!payload) throw new Error('本地原草稿记录无效，不能覆盖或重新发送')
          keys.add(item.requestKey); operations.push({ requestKey: item.requestKey, payload })
        }
        const changes: PendingPolicyChange[] = []
        if (value.changes !== undefined) {
          if (!Array.isArray(value.changes)) throw new Error('本地发布或停用记录格式不一致，不能重新发送')
          for (const item of value.changes) {
            if (!isRecord(item) || !isScopeKind(item.scope_kind) || typeof item.scope_ref !== 'string' || !item.scope_ref
              || typeof item.id !== 'number' || !Number.isSafeInteger(item.id) || item.id < 1
              || typeof item.revision !== 'number' || !Number.isSafeInteger(item.revision) || item.revision < 1
              || (item.action !== 'publish' && item.action !== 'archive') || changes.some(change => change.id === item.id))
              throw new Error('本地发布或停用记录无效，不能重新发送')
            const expectedPolicy = item.expectedPolicy === undefined ? undefined : readStoredExpectation(item.expectedPolicy)
            if (expectedPolicy === null || (expectedPolicy && (item.action !== 'publish'
              || expectedPolicy.revision !== item.revision || expectedPolicy.scope_kind !== item.scope_kind
              || expectedPolicy.scope_ref !== item.scope_ref))) throw new Error('本地确认策略与原发布目标不一致，不能重新发送')
            changes.push({ id: item.id, revision: item.revision, scope_kind: item.scope_kind, scope_ref: item.scope_ref,
              action: item.action, ...(expectedPolicy ? { expectedPolicy } : {}) })
          }
        }
        pendingSaves.value = operations; pendingChanges.value = changes
      }
      saveStorageReady.value = true
    } catch (cause) {
      saveRecoveryError.value = cause instanceof Error ? cause.message : '无法读取原保存请求记录；本次不会发送'
    }
  }
  function persistPendingSaves(operations: PendingDraftSave[], actorId: number): void {
    sessionStorage.setItem(saveStorageKey(actorId), JSON.stringify({ version: 1, actorId, operations, changes: pendingChanges.value }))
    pendingSaves.value = operations
  }
  function persistPolicyChanges(changes: PendingPolicyChange[], actorId: number): void {
    sessionStorage.setItem(saveStorageKey(actorId), JSON.stringify({ version: 1, actorId, operations: pendingSaves.value, changes }))
    pendingChanges.value = changes
  }
  async function recoverPolicyChange(operation: PendingPolicyChange): Promise<void> {
    const actorId = adminId.value
    const generation = actorEpoch
    if (!actorId || writing.value || recoveringChangeId.value || !saveStorageReady.value) return
    recoveringChangeId.value = operation.id; changeRecoveryError.value = ''
    try {
      const current = await budgetApi.readPolicy(operation.id)
      if (!live || adminId.value !== actorId || generation !== actorEpoch) return
      if (current.id !== operation.id || current.revision !== operation.revision || current.scope_kind !== operation.scope_kind
        || current.scope_ref !== operation.scope_ref || !['draft', 'published', 'archived'].includes(current.state))
        throw new Error('当前版本与原操作归属不一致')
      if (operation.expectedPolicy && !matchesExpectation(current, operation.expectedPolicy))
        throw new Error('当前配置与确认时的策略不同，不能确认原发布结果')
      const confirmed = operation.action === 'publish' ? current.state !== 'draft' : current.state === 'archived'
      if (confirmed) persistPolicyChanges(pendingChanges.value.filter(item => item.id !== operation.id), actorId)
      changeRecoveryError.value = confirmed
        ? `原版本 #${current.id} 当前为${current.state === 'archived' ? '已归档' : '已发布'}；本次仅确认当前配置事实，不能证明原操作由谁完成；未重新发送。`
        : `原版本 #${current.id} 当前仍为${current.state === 'draft' ? '草稿' : '已发布'}，原${operation.action === 'publish' ? '发布' : '停用'}是否结束尚未确认；保留原记录，稍后只读核对，不重新发送。`
      if (sameScope(operation)) { invalidatePolicyViews(); await loadPolicies(page.value); await loadActive(true) }
    } catch (cause) {
      if (live && adminId.value === actorId && generation === actorEpoch) changeRecoveryError.value = `${cause instanceof Error ? cause.message : '无法读取原版本'}；保留原操作记录，不重新发送。`
    } finally { if (live && adminId.value === actorId && generation === actorEpoch && recoveringChangeId.value === operation.id) recoveringChangeId.value = null }
  }
  function decimalSix(value: string): string | null {
    if (!/^\d{1,14}(?:\.\d{1,6})?$/.test(value)) return null
    const [integer, fraction = ''] = value.split('.')
    return `${integer.replace(/^0+(?=\d)/, '')}.${fraction.padEnd(6, '0')}`
  }
  function assertSavedDraft(saved: BudgetPolicy, operation: PendingDraftSave): void {
    const original = operation.payload
    if (!Number.isSafeInteger(saved.id) || saved.id < 1 || !Number.isSafeInteger(saved.revision) || saved.revision < 1
      || saved.state !== 'draft' || saved.scope_kind !== original.scope_kind || saved.scope_ref !== original.scope_ref
      || (original.id !== undefined && saved.id !== original.id)
      || saved.period_start !== original.period_start || saved.period_end !== original.period_end
      || saved.currency !== original.currency || saved.enforcement !== original.enforcement
      || saved.unpriced_policy !== original.unpriced_policy || saved.unmeasured_policy !== original.unmeasured_policy
      || typeof saved.limit_amount !== 'string' || decimalSix(saved.limit_amount) !== decimalSix(original.limit_amount))
      throw new Error('保存收据与原草稿不一致；保留原请求号，请只读核对')
  }
  function notSavedReason(result: BudgetDraftNotSaved): string {
    if (result.idempotent_replay !== true || typeof result.error_code !== 'string'
      || !['SAND_AI_BUDGET_VALIDATION_ERROR', 'SAND_AI_BUDGET_IMMUTABLE', 'SAND_AI_RESOURCE_NOT_FOUND'].includes(result.error_code)
      || typeof result.message !== 'string')
      throw new Error('未保存收据无效；仍保留原请求号，只读核对')
    return `服务端原请求收据已确认未保存。请核对时段、额度及草稿状态，修改后手动保存（${result.error_code}）。`
  }
  async function refreshSavedPolicy(saved: BudgetPolicy, actorId: number): Promise<void> {
    if (!sameScope(saved)) return
    const generation = actorEpoch
    try {
      const current = await budgetApi.readPolicy(saved.id)
      if (!live || adminId.value !== actorId || generation !== actorEpoch || !sameScope(saved)) return
      if (current.id !== saved.id || current.revision !== saved.revision || current.scope_kind !== saved.scope_kind
        || current.scope_ref !== saved.scope_ref || !['draft', 'published', 'archived'].includes(current.state)
        || typeof current.limit_amount !== 'string' || typeof current.currency !== 'string')
        throw new Error('当前版本读取与原保存版本不一致')
      confirmedCurrentPolicy.value = current
      if (sameScope(saved)) await loadPolicies(1)
    } catch {
      if (live && adminId.value === actorId && generation === actorEpoch && sameScope(saved))
        saveRecoveryError.value = '原保存结果已经确认，但当前版本未能读取。请刷新预算版本核对；不会重新保存或假定当前状态。'
    }
  }
  async function openNotSavedDraft(operation: PendingDraftSave): Promise<void> {
    const generation = actorEpoch
    if (writing.value || dialogOpen.value) return
    scopeKind.value = operation.payload.scope_kind
    await nextTick()
    scopeRef.value = operation.payload.scope_ref
    await nextTick()
    if (!live || generation !== actorEpoch || !sameScope(operation.payload)) return
    referenceEnvironmentId.value = operation.payload.reference_environment_id
    draft.value = { ...operation.payload }; draftBaseline = ''; formError.value = ''; dialogOpen.value = true
  }
  async function recoverDraftSave(operation: PendingDraftSave): Promise<void> {
    const actorId = adminId.value
    const generation = actorEpoch
    if (!actorId || recoveringSaveKey.value || writing.value || !saveStorageReady.value) return
    recoveringSaveKey.value = operation.requestKey; saveRecoveryError.value = ''
    try {
      const result = await budgetApi.draftSaveByRequestKey(operation.payload, operation.requestKey)
      if (!live || adminId.value !== actorId || generation !== actorEpoch) return
      if (result.idempotent_replay !== true) throw new Error('原请求读取缺少保存收据；继续保留原号')
      if (result.state === 'not_saved') {
        const reason = notSavedReason(result)
        persistPendingSaves(pendingSaves.value.filter(item => item.requestKey !== operation.requestKey), actorId)
        notSavedDrafts.value.push({ operation, reason })
        if (sameScope(operation.payload) && draft.value.id === operation.payload.id) formError.value = reason
        ElMessage.warning('已确认该原请求未保存；可修改对应草稿后手动保存')
      } else {
        assertSavedDraft(result, operation)
        persistPendingSaves(pendingSaves.value.filter(item => item.requestKey !== operation.requestKey), actorId)
        delete retainedDrafts.value[draftKey(operation.payload)]
        if (sameScope(operation.payload)) {
          if (draft.value.id === operation.payload.id) closeSavedDraft()
        }
        await refreshSavedPolicy(result, actorId)
        ElMessage.success('原草稿保存结果已确认；请按最新版本查看')
      }
    } catch (cause) {
      if (live && adminId.value === actorId && generation === actorEpoch)
        saveRecoveryError.value = `${cause instanceof Error ? cause.message : '无法读取原保存收据'}；结果仍未确认，保留原请求号，不重新发送。`
    } finally {
      if (live && adminId.value === actorId && generation === actorEpoch && recoveringSaveKey.value === operation.requestKey) recoveringSaveKey.value = null
    }
  }

  async function loadTargets(reset = false): Promise<void> {
    if (targetLoading.value && !reset) return
    const epoch = ++targetEpoch
    const kind = scopeKind.value
    const nextPage = reset ? 1 : targetPage.value + 1
    targetLoading.value = true
    targetError.value = ''
    try {
      let rows: { ref: string; name: string }[]
      let count: number
      if (kind === 'service') { rows = [{ ref: 'sand_ai', name: 'SandAI 服务' }]; count = 1 }
      else if (kind === 'capability' || kind === 'model') {
        const result = await budgetApi.references(nextPage)
        rows = kind === 'capability' ? result.capabilities.map(item => ({ ref: item.code, name: item.name }))
          : result.models.data.map(item => ({ ref: String(item.id), name: `${item.name} (${item.code})` }))
        count = kind === 'capability' ? rows.length : result.models.total
      } else if (kind === 'organization') {
        const result = await budgetApi.organizations(nextPage)
        rows = result.data.map(item => ({ ref: String(item.id), name: `${item.name} (${item.code})` })); count = result.total
      } else if (kind === 'application') {
        const result = await iamApi.listApplications({ page: nextPage, limit: 100 })
        rows = result.data.map(item => ({ ref: String(item.id), name: `${item.name} (${item.code})` })); count = result.total
      } else {
        const result = await iamApi.listEnvironments({ page: nextPage, limit: 100 })
        rows = result.data.map(item => ({ ref: String(item.id), name: `${item.name} (${item.code})` })); count = result.total
      }
      if (!live || epoch !== targetEpoch || kind !== scopeKind.value) return
      targets.value = reset ? rows : [...targets.value, ...rows]
      targetPage.value = nextPage
      targetCount.value = reset ? rows.length : targetCount.value + rows.length
      targetTotal.value = count
    } catch (cause) {
      if (live && epoch === targetEpoch) targetError.value = cause instanceof Error ? cause.message : '读取对象失败，请检查平台管理和 SandIAM 目录权限后重试'
    } finally { if (live && epoch === targetEpoch) targetLoading.value = false }
  }

  async function loadEnvironments(reset = false): Promise<void> {
    if (environmentLoading.value && !reset) return
    const epoch = ++environmentEpoch
    const nextPage = reset ? 1 : environmentPage.value + 1
    const kind = scopeKind.value
    environmentLoading.value = true; environmentError.value = ''
    try {
      const result = await iamApi.listEnvironments({ page: nextPage, limit: 100 })
      if (!live || epoch !== environmentEpoch || kind !== scopeKind.value) return
      environments.value = reset ? result.data : [...environments.value, ...result.data]
      environmentPage.value = nextPage
      environmentCount.value = reset ? result.data.length : environmentCount.value + result.data.length
      environmentTotal.value = result.total
    } catch (cause) {
      if (live && epoch === environmentEpoch && kind === scopeKind.value) environmentError.value = cause instanceof Error ? cause.message : '读取验证环境失败，请检查 SandIAM 环境权限'
    } finally { if (live && epoch === environmentEpoch) environmentLoading.value = false }
  }

  async function loadActive(force = false): Promise<void> {
    const scope = selectedScope()
    if (!scope || (activeLoading.value && !force)) return
    const epoch = ++activeEpoch
    activeLoading.value = true
    activeSummary.value = null
    activeError.value = ''
    try {
      const result = await budgetApi.active(scope)
      if (!live || epoch !== activeEpoch || !sameScope(scope)) return
      if (typeof result.active !== 'boolean' || (result.active && (!result.summary || !sameScope(result.summary.policy)))
        || (!result.active && result.summary !== null)) throw new Error('当前预算状态未确认或与所选范围不一致')
      activeSummary.value = result.active ? result.summary : null
    } catch (cause) {
      if (live && epoch === activeEpoch) activeError.value = cause instanceof Error ? cause.message : '读取当前预算失败'
    } finally { if (live && epoch === activeEpoch) activeLoading.value = false }
  }

  async function loadPolicies(nextPage = 1): Promise<void> {
    const scope = selectedScope()
    if (!scope) return
    const epoch = ++listEpoch
    listLoading.value = true
    listError.value = ''
    try {
      const result = await budgetApi.list(scope, nextPage)
      if (!live || epoch !== listEpoch || !sameScope(scope)) return
      policies.value = result.data; page.value = nextPage; total.value = result.total
    } catch (cause) {
      if (live && epoch === listEpoch) listError.value = cause instanceof Error ? cause.message : '读取预算版本失败'
    } finally { if (live && epoch === listEpoch) listLoading.value = false }
  }

  function newDraft(): void {
    if (writing.value || !selectedScope() || hasPendingDraft(emptyDraft())) return
    const initial = emptyDraft()
    draftBaseline = JSON.stringify(initial)
    const retained = retainedDrafts.value[draftKey(initial)]
    if (retained?.reference_environment_id) referenceEnvironmentId.value = retained.reference_environment_id
    draft.value = { ...(retainedDrafts.value[draftKey(initial)] ?? initial) }; formError.value = ''; dialogOpen.value = true
  }
  function editDraft(policy: BudgetPolicy): void {
    if (writing.value || policy.state !== 'draft' || !sameScope(policy) || hasPendingDraft(policy) || hasPendingChange(policy)
      || !supportedMissingUsagePolicy(policy)) return
    draft.value = { id: policy.id, scope_kind: policy.scope_kind, scope_ref: policy.scope_ref, period_start: policy.period_start,
      period_end: policy.period_end, currency: policy.currency, limit_amount: policy.limit_amount, enforcement: policy.enforcement,
      unpriced_policy: policy.unpriced_policy, unmeasured_policy: policy.unmeasured_policy }
    draftBaseline = JSON.stringify(draft.value)
    const retained = retainedDrafts.value[draftKey(policy)]
    if (retained) { draft.value = { ...retained }; referenceEnvironmentId.value = retained.reference_environment_id }
    formError.value = ''; dialogOpen.value = true
  }
  async function saveDraft(): Promise<void> {
    if (!validDraft.value || writing.value) return
    const actorId = adminId.value
    const generation = actorEpoch
    if (!actorId || !saveStorageReady.value) { formError.value = '无法保留当前管理员的原保存请求记录，本次未发送'; return }
    const payload: BudgetDraft = {
      ...draft.value, reference_environment_id: needsWitness.value ? referenceEnvironmentId.value : undefined,
      currency: draft.value.currency.trim().toUpperCase(), limit_amount: draft.value.limit_amount.trim()
    }
    let operation: PendingDraftSave
    try {
      operation = { requestKey: `budget-draft-${crypto.randomUUID()}`, payload }
      persistPendingSaves([...pendingSaves.value, operation], actorId)
    } catch { formError.value = '无法保存原请求号，本次未发送。请检查浏览器恢复记录是否可用。'; return }
    writeFlight.value = true; currentWrite.value = true; formError.value = ''
    try {
      const saved = await budgetApi.saveDraft(payload, operation.requestKey)
      if (!live || adminId.value !== actorId || generation !== actorEpoch) return
      if (saved.state === 'not_saved') {
        formError.value = '本次保存有未保存收据，请只读找回原请求确认后再修改；不会自动重发。'
        return
      }
      assertSavedDraft(saved, operation)
      const receipt = await budgetApi.draftSaveByRequestKey(payload, operation.requestKey)
      if (!live || adminId.value !== actorId || generation !== actorEpoch) return
      if (receipt.state === 'not_saved' || receipt.idempotent_replay !== true)
        throw new Error('原请求保存收据尚未确认')
      assertSavedDraft(receipt, operation)
      if (receipt.id !== saved.id || receipt.revision !== saved.revision) throw new Error('原请求收据与保存响应目标不一致')
      persistPendingSaves(pendingSaves.value.filter(item => item.requestKey !== operation.requestKey), actorId)
      if (!sameScope(payload)) return
      closeSavedDraft(); ElMessage.success('原请求已确认保存预算草稿')
      await refreshSavedPolicy(receipt, actorId)
    } catch (cause) {
      if (live && adminId.value === actorId && generation === actorEpoch)
        formError.value = `${cause instanceof Error ? cause.message : '保存结果未确认'}；保留原请求号，只读找回，不重复保存。`
    }
    finally {
      writeFlight.value = false
      if (live && adminId.value === actorId && generation === actorEpoch) currentWrite.value = false
    }
  }
  async function changePolicy(policy: BudgetPolicy, action: 'publish' | 'archive'): Promise<void> {
    if (writing.value || dialogOpen.value || !sameScope(policy) || hasPendingChange(policy)
      || (action === 'publish' && (policy.state !== 'draft' || hasPendingDraft(policy) || !supportedMissingUsagePolicy(policy)))
      || (action === 'archive' && (!hasAuth('sand_ai:budget:publish') || policy.state !== 'published'))) return
    if (action === 'publish' && retainedDrafts.value[draftKey(policy)]) {
      ElMessage.warning('该草稿有保留的编辑，请先打开编辑并保存，再发布'); return
    }
    const actorId = adminId.value
    const generation = actorEpoch
    if (!actorId || !saveStorageReady.value) { ElMessage.warning('无法保留原操作记录，本次未发送'); return }
    if (needsWitness.value && !referenceEnvironmentId.value) { ElMessage.warning('请先选择归属该组织或应用的验证环境'); return }
    policy = { ...policy }
    const expectedPolicy = action === 'publish' ? freezePolicy(policy) : undefined
    const witness = needsWitness.value ? referenceEnvironmentId.value : undefined
    const operation: PendingPolicyChange = { id: policy.id, revision: policy.revision, scope_kind: policy.scope_kind, scope_ref: policy.scope_ref, action, expectedPolicy }
    writeFlight.value = true; currentWrite.value = true
    if (action === 'publish') publishingId.value = policy.id
    else archivingId.value = policy.id
    let recorded = false
    try {
      try {
        const message = action === 'publish'
          ? `版本 ${policy.revision} 在 ${policy.period_start} 至 ${policy.period_end}（UTC）限制所选范围：${policy.limit_amount} ${policy.currency}。${policy.enforcement === 'hard' ? '超额阻止新调用' : '超额允许并记录'}；${policy.unpriced_policy === 'block' ? '未定价阻止' : '未定价专业重排允许，费用待核实'}；${policy.unmeasured_policy === 'hold' ? '未知用量保留待核实' : '专业重排业务可继续，费用仍待核实'}。允许策略仅由专业重排执行链支持，其他能力遇到不支持的策略会在外发前拒绝；全部匹配预算共同生效。发布后不能编辑。`
          : `停用版本 ${policy.revision} 后，版本将归档，此预算不再限制新的调用；没有其他匹配预算时，新调用将没有该预算限额。历史额度、已预占及待核实占用保留，已发送调用继续按冻结事实结算，不释放历史占额或减少已发生费用。可新建并发布替代预算。`
        await ElMessageBox.confirm(message, action === 'publish' ? '发布预算' : '停用预算', {
          type: 'warning', confirmButtonText: action === 'publish' ? '确认发布' : '确认停用', cancelButtonText: '取消'
        })
      } catch { return }
      if (!live || adminId.value !== actorId || generation !== actorEpoch || !sameScope(policy)) return
      persistPolicyChanges([...pendingChanges.value, operation], actorId); recorded = true
      invalidatePolicyViews()
      activeError.value = '预算状态变更结果待确认，请只读核对原版本；当前生效额度尚未确认。'
      const saved = action === 'publish' ? await budgetApi.publish(policy.id, witness, expectedPolicy) : await budgetApi.archive(policy.id, witness)
      if (!live || adminId.value !== actorId || generation !== actorEpoch) return
      if (saved.id !== policy.id || saved.revision !== policy.revision || saved.scope_kind !== policy.scope_kind
        || saved.scope_ref !== policy.scope_ref || saved.state !== (action === 'publish' ? 'published' : 'archived'))
        throw new Error('响应与原预算版本或目标状态不一致')
      if (expectedPolicy && !matchesExpectation(saved, expectedPolicy)) throw new Error('发布响应与确认策略不一致')
      persistPolicyChanges(pendingChanges.value.filter(item => item.id !== policy.id), actorId)
      ElMessage.success(action === 'publish' ? '预算已发布' : '预算已停用并归档；历史额度、占额和已发生费用保留')
      if (sameScope(policy)) { await loadPolicies(page.value); await loadActive(true) }
    } catch (cause) {
      if (live && adminId.value === actorId && generation === actorEpoch) {
        changeRecoveryError.value = recorded
          ? `${cause instanceof Error ? cause.message : '操作结果未确认'}；保留原版本，请只读核对，不重新发送。`
          : '无法保存原操作记录，本次未发送。请检查浏览器恢复记录是否可用。'
      }
    } finally {
      writeFlight.value = false
      if (live && adminId.value === actorId && generation === actorEpoch) { currentWrite.value = false; publishingId.value = null; archivingId.value = null }
    }
  }
  async function publish(policy: BudgetPolicy): Promise<void> { await changePolicy(policy, 'publish') }
  async function archive(policy: BudgetPolicy): Promise<void> { await changePolicy(policy, 'archive') }
  async function loadSummary(policy: BudgetPolicy): Promise<void> {
    if (policy.state === 'draft' || !sameScope(policy)) return
    const epoch = ++summaryEpoch
    summary.value = null; summaryError.value = ''; summaryLoadingId.value = policy.id
    try {
      const result = await budgetApi.summary(policy.id)
      if (!live || epoch !== summaryEpoch || !sameScope(policy)) return
      if (result.policy.id !== policy.id || !sameScope(result.policy)) throw new Error('额度与所选预算版本不一致')
      summary.value = result
    } catch (cause) { if (live && epoch === summaryEpoch) summaryError.value = cause instanceof Error ? cause.message : '读取预算额度失败' }
    finally { if (live && epoch === summaryEpoch) summaryLoadingId.value = null }
  }

  watch(scopeKind, () => {
    scopeRef.value = undefined; referenceEnvironmentId.value = undefined
    ++environmentEpoch; environmentLoading.value = false; environmentError.value = ''; environments.value = []
    environmentPage.value = 0; environmentCount.value = 0; environmentTotal.value = 0
    targets.value = []; targetCount.value = 0; targetTotal.value = 0
    void loadTargets(true)
    if (needsWitness.value) void loadEnvironments(true)
  })
  watch(dialogOpen, (open, wasOpen) => {
    if (!open && wasOpen) {
      if (!savedDraftClosing && draft.value.scope_ref && JSON.stringify(draft.value) !== draftBaseline) retainedDrafts.value[draftKey(draft.value)] = { ...draft.value,
        reference_environment_id: needsWitness.value ? referenceEnvironmentId.value : undefined }
      savedDraftClosing = false
    }
  }, { flush: 'sync' })
  watch([adminId, () => userStore.accessToken], () => {
    ++actorEpoch; ++historyIdentityEpoch.value
    dialogOpen.value = false
    budgetHistoryOpen.value = false
    draft.value = emptyDraft(); draftBaseline = ''; formError.value = ''
    currentWrite.value = false; publishingId.value = null; archivingId.value = null
    ++targetEpoch; ++environmentEpoch
    targetLoading.value = false; environmentLoading.value = false
    targets.value = []; targetPage.value = 0; targetCount.value = 0; targetTotal.value = 0
    environments.value = []; environmentPage.value = 0; environmentCount.value = 0; environmentTotal.value = 0; environmentError.value = ''
    scopeRef.value = undefined; referenceEnvironmentId.value = undefined
    invalidatePolicyViews()
    restorePendingSaves()
    if (adminId.value) { void loadTargets(true); if (needsWitness.value) void loadEnvironments(true) }
  }, { immediate: true, flush: 'sync' })
  watch([scopeKind, scopeRef], ([, nextRef], [, previousRef]) => {
    if (nextRef !== previousRef) referenceEnvironmentId.value = undefined
    ++listEpoch; ++summaryEpoch; ++activeEpoch
    policies.value = []; total.value = 0; listLoading.value = false; listError.value = ''
    summary.value = null; summaryError.value = ''; summaryLoadingId.value = null
    activeSummary.value = null; activeError.value = ''; activeLoading.value = false
    confirmedCurrentPolicy.value = null; notSavedDrafts.value = []
    dialogOpen.value = false; draft.value = emptyDraft(); draftBaseline = ''; formError.value = ''
    if (selectedScope()) { void loadPolicies(1); void loadActive() }
  })
  onMounted(() => {
    activeTimer = setInterval(() => { if (selectedScope()) void loadActive() }, 60000)
  })
  onUnmounted(() => {
    live = false; ++targetEpoch; ++environmentEpoch; ++listEpoch; ++summaryEpoch; ++activeEpoch
    if (activeTimer) clearInterval(activeTimer)
  })
</script>
