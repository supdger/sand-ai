<template>
  <div class="usage-cost-panel">
    <BudgetPolicyPanel v-if="hasAuth('sand_ai:budget:index')" />
    <InvocationCostPanel v-if="hasAuth('sand_ai:usage:cost')" />
    <CostComparisonPanel v-if="canCost" ref="comparisonPanel" :selection="comparisonSelection" />
    <UsageRefundPanel v-if="canRefund" ref="refundPanel" :selection="refundSelection ?? undefined" @pending="refundPending = $event" />
    <sa-search-bar
      v-model="searchForm"
      label-width="88px"
      @reset="handleReset"
      @search="handleSearch"
    >
      <el-col :xs="24" :sm="12" :md="8" :lg="6">
        <el-form-item label="环境" prop="environment_id">
          <el-select v-model="searchForm.environment_id" clearable filterable class="!w-full"
            :loading="scopeLoading" placeholder="全部可读取环境">
            <el-option v-for="environment in allowedScope?.environments ?? []" :key="environment.id"
              :value="environment.id" :label="`${environment.name || environment.code}（${environment.id}）`" />
          </el-select>
        </el-form-item>
      </el-col>
      <el-col :xs="24" :sm="12" :md="8" :lg="6">
        <el-form-item label="应用 ID" prop="application_id">
          <el-input-number
            v-model="searchForm.application_id"
            :min="1"
            :controls="false"
            class="!w-full"
            placeholder="全部应用"
          />
        </el-form-item>
      </el-col>
      <el-col :xs="24" :sm="12" :md="8" :lg="6">
        <el-form-item label="服务动作" prop="service_action">
          <el-input
            v-model="searchForm.service_action"
            clearable
            placeholder="如 sand_ai.chat.complete"
          />
        </el-form-item>
      </el-col>
      <el-col :xs="24" :sm="12" :md="8" :lg="6">
        <el-form-item label="能力" prop="capability_code">
          <el-input v-model="searchForm.capability_code" clearable placeholder="如 chat" />
        </el-form-item>
      </el-col>
      <el-col :xs="24" :sm="12" :md="8" :lg="6">
        <el-form-item label="服务商 ID" prop="provider_id">
          <el-input-number
            v-model="searchForm.provider_id"
            :min="1"
            :controls="false"
            class="!w-full"
            placeholder="全部服务商"
          />
        </el-form-item>
      </el-col>
      <el-col :xs="24" :sm="12" :md="8" :lg="6">
        <el-form-item label="模型 ID" prop="model_id">
          <el-input-number
            v-model="searchForm.model_id"
            :min="1"
            :controls="false"
            class="!w-full"
            placeholder="全部模型"
          />
        </el-form-item>
      </el-col>
      <el-col :xs="24" :sm="12" :md="8" :lg="6">
        <el-form-item label="用量状态" prop="usage_state">
          <el-select v-model="searchForm.usage_state" clearable placeholder="全部">
            <el-option label="已测得" value="measured" />
            <el-option label="未测得" value="unmeasured" />
          </el-select>
        </el-form-item>
      </el-col>
      <el-col :xs="24" :sm="12" :md="8" :lg="6">
        <el-form-item label="价格状态" prop="pricing_state">
          <el-select v-model="searchForm.pricing_state" clearable placeholder="全部">
            <el-option label="已定价" value="priced" />
            <el-option label="未定价" value="unpriced" />
          </el-select>
        </el-form-item>
      </el-col>
    </sa-search-bar>

    <p class="mb-3 text-sm text-gray-500">服务：SandAI（sand_ai）。服务动作是该服务内的调用类型；环境留空时显示当前管理员正式可读取环境范围的小计，不代表组织或应用全部历史费用；停用环境按正式历史读取权限纳入。</p>
    <ElAlert v-if="scopeError" :title="scopeError" type="error" :closable="false" class="mb-3" />
    <ElButton v-if="scopeError" size="small" @click="loadScopes">重试读取允许环境</ElButton>
    <ElCard v-if="canEvents || canSummary" class="mb-3" shadow="never">
      <template #header>来源与运行筛选</template>
      <div class="summary-range">
        <ElFormItem v-for="field in associationFilterFields" :key="field.key" :label="field.label">
          <ElInputNumber v-model="searchForm[field.key]" :min="1" :controls="false" placeholder="全部" />
        </ElFormItem>
        <ElFormItem label="AI 工作流节点">
          <ElInput v-model="searchForm.composition_node_code" clearable
            :disabled="!searchForm.composition_run_id" placeholder="先指定运行 ID，如 lookup" />
        </ElFormItem>
        <ElFormItem label="渠道平台">
          <ElSelect v-model="searchForm.delivery_platform" clearable placeholder="全部平台">
            <ElOption label="飞书" value="feishu" />
            <ElOption label="企业微信" value="wecom" />
          </ElSelect>
        </ElFormItem>
        <ElFormItem label="来源类别">
          <ElSelect v-model="searchForm.source_kind" clearable filterable placeholder="全部来源">
            <ElOption v-for="(label, code) in sourceLabels" :key="code" :value="code" :label="label" />
          </ElSelect>
        </ElFormItem>
        <ElButton type="primary" @click="handleSearch">应用筛选</ElButton>
      </div>
    </ElCard>
    <ElCard v-if="canSummary" class="art-table-card" shadow="never">
      <h3 class="m-0 text-base">费用汇总</h3>
      <p class="mt-1 mb-0 text-sm text-gray-500">
        按上方筛选条件汇总不可变计量事件。资源、运行、版本和绑定按实际 ID 分组；缺少关联证据的事件显示为未归属。
        各维度是同批事件的不同投影，不将运行总额与其子调用相加。
      </p>
      <form class="summary-range mt-3" @submit.prevent="loadSummary">
        <fieldset class="summary-range-modes">
          <legend class="text-sm">汇总时间</legend>
          <label
            ><input v-model="rangeMode" type="radio" value="recent" :name="`${rangeId}-mode`" />近
            30 天</label
          >
          <label
            ><input
              v-model="rangeMode"
              type="radio"
              value="custom"
              :name="`${rangeId}-mode`"
            />指定时间</label
          >
        </fieldset>
        <div v-if="rangeMode === 'custom'" class="summary-range-fields">
          <div class="summary-range-field">
            <label :for="`${rangeId}-from`" class="text-sm">开始时间（UTC）</label>
            <ElInput
              :id="`${rangeId}-from`"
              ref="fromTimeInput"
              v-model="fromTime"
              placeholder="YYYY-MM-DD HH:mm:ss"
              :aria-invalid="Boolean(rangeErrors.from)"
              :aria-describedby="`${rangeId}-help ${rangeId}-from-error`"
            />
            <p :id="`${rangeId}-from-error`" class="summary-range-error" aria-live="polite">{{
              rangeErrors.from
            }}</p>
          </div>
          <div class="summary-range-field">
            <label :for="`${rangeId}-to`" class="text-sm">结束时间（UTC，不含）</label>
            <ElInput
              :id="`${rangeId}-to`"
              ref="toTimeInput"
              v-model="toTime"
              placeholder="YYYY-MM-DD HH:mm:ss"
              :aria-invalid="Boolean(rangeErrors.to)"
              :aria-describedby="`${rangeId}-help ${rangeId}-to-error`"
            />
            <p :id="`${rangeId}-to-error`" class="summary-range-error" aria-live="polite">{{
              rangeErrors.to
            }}</p>
          </div>
        </div>
        <p :id="`${rangeId}-help`" class="mt-2 mb-2 text-sm text-gray-500">
          按 UTC 输入，最长 93 天。开始时间包含，结束时间不包含。
        </p>
        <ElButton
          class="summary-range-submit"
          type="primary"
          native-type="submit"
          :loading="summaryLoading"
          :disabled="summaryLoading"
        >
          查看费用汇总
        </ElButton>
        <ElButton
          class="summary-range-submit"
          native-type="button"
          :loading="exportLoading"
          :disabled="!summary || summaryLoading || exportLoading"
          @click="exportSummary"
        >
          导出 CSV
        </ElButton>
      </form>
      <p class="mt-2 mb-0 text-sm text-gray-500">
        CSV 导出当前已显示汇总的环境、筛选和时间范围，包含各币种及全部维度；保留未知金额和截断标记。
      </p>
      <p v-if="exportStatus" class="mt-2 mb-0 text-sm" role="status">{{ exportStatus }}</p>
      <ElAlert
        v-if="exportError"
        :title="exportError"
        type="warning"
        :closable="false"
        class="mt-3"
      />
      <p v-if="summaryLoading || rangeChanged" class="mt-3 mb-0 text-sm" role="status">
        {{ summaryLoading ? '正在读取费用汇总' : '时间已修改，点击查看费用汇总' }}
      </p>
      <p v-if="summary" class="mt-3 mb-0 text-sm summary-applied-range">
        汇总范围（UTC）：{{ summary.filters.from_time }} 至
        {{ summary.filters.to_time }}（不含结束时间）；授权环境小计：{{ summary.scope.environment_ids.length ? summary.scope.environment_ids.join('、') : '当前无可读取环境' }}
      </p>
      <ElAlert
        v-if="summaryError"
        :title="summaryError"
        type="warning"
        :closable="false"
        class="mt-3"
      />
      <template v-if="summary">
        <ElAlert
          title="不同币种分别显示。预估采用这些用量事件在调用时冻结的预算上界，不是预计实付；没有冻结依据或仍待核实的部分保持未知，已知部分按币种列出。计量金额来自同批用量事件，已核对账单金额只覆盖已核对事件。调用失败费用仅指模型调用失败；业务失败关联费用可能包含可用的部分成果，二者不相加，也不等于全部损失。"
          type="info"
          :closable="false"
          class="mt-3"
        />
        <p class="mt-3 mb-2 text-sm text-gray-500">
          缓存／复用节省：未知。当前费用数据没有真实命中和对应基准证据，尚未具备计算依据。
          历史费用比较仅核对同条件两次调用的计量差额；计量差额或当前价格估算不表示缓存／复用节省或已实现现金节省。
        </p>
        <ElButton v-if="canCost" @click="focusComparison">比较已有费用</ElButton>
        <p class="mt-3 mb-2 text-sm">
          <template v-if="summary.ambiguous.scope_state === 'association_unavailable'">当前运行或知识库筛选无法确认未计量派发的归属；次数、Token 和金额均未知。</template>
          <template v-else>派发结果不确定且没有用量事件：{{ summary.ambiguous.count }} 次；不属于下方用量事件汇总，Token 和金额未知。请沿原请求在单次费用入口找回，不计入已知费用或损失。</template>
        </p>
        <h4 class="mt-4 mb-2 text-sm">各币种总览</h4>
        <ElTable :data="summary.overall.rows" border stripe empty-text="所选范围暂无计量事件">
          <ElTableColumn prop="currency" label="币种" width="100">
            <template #default="{ row }">{{ row.currency ?? '未定价' }}</template>
          </ElTableColumn>
          <ElTableColumn prop="total_events" label="事件数" width="100" />
          <ElTableColumn prop="unmeasured_events" label="未测得" width="100" />
          <ElTableColumn prop="unpriced_events" label="未定价" width="100" />
          <ElTableColumn label="预估（调用时预算上界）" min-width="245">
            <template #default="{ row }">
              <span>{{ estimateAmountLabel(row) }}</span>
              <template v-if="!estimateComplete(row)">
                <p v-for="known in row.estimate?.known_amounts ?? []" :key="known.currency" class="my-1 text-sm">
                  已知部分：{{ known.amount }} {{ known.currency }}
                </p>
              </template>
            </template>
          </ElTableColumn>
          <ElTableColumn label="预估依据覆盖" min-width="245">
            <template #default="{ row }">{{ estimateCoverageLabel(row) }}</template>
          </ElTableColumn>
          <ElTableColumn prop="metered_amount" label="计量金额" min-width="135">
            <template #default="{ row }">{{ amountLabel(row) }}</template>
          </ElTableColumn>
          <ElTableColumn label="失败调用计量费用" min-width="215">
            <template #default="{ row }">{{ failedAmount(row) }}</template>
          </ElTableColumn>
          <ElTableColumn prop="failed_unknown_amount_events" label="失败调用金额未知次数" min-width="180" />
          <ElTableColumn label="业务失败关联调用费用" min-width="235">
            <template #default="{ row }">{{ businessFailedAmount(row) }}</template>
          </ElTableColumn>
          <ElTableColumn prop="business_failed_unknown_amount_events" label="业务失败关联金额未知次数" min-width="195" />
          <ElTableColumn prop="reconciled_amount" label="已核对账单金额" min-width="155">
            <template #default="{ row }">{{ row.reconciled_amount ?? '未核对' }}</template>
          </ElTableColumn>
          <ElTableColumn prop="difference_amount" label="已记录差额" min-width="135">
            <template #default="{ row }">{{ row.difference_amount ?? '—' }}</template>
          </ElTableColumn>
          <ElTableColumn prop="discrepancy_events" label="差异事件" width="105" />
        </ElTable>
        <div class="mt-4 mb-2 flex flex-wrap items-center gap-3">
          <h4 class="m-0 text-sm">维度明细</h4>
          <ElSelect v-model="summaryDimension" class="w-44">
            <ElOption label="应用" value="by_application" />
            <ElOption label="服务" value="by_service" />
            <ElOption label="服务动作" value="by_service_action" />
            <ElOption label="能力" value="by_capability" />
            <ElOption label="模型" value="by_model" />
            <ElOption label="服务商" value="by_provider" />
            <ElOption label="Skill" value="by_skill" />
            <ElOption label="Skill 版本" value="by_skill_version" />
            <ElOption label="组织" value="by_organization" />
            <ElOption label="环境" value="by_environment" />
            <ElOption label="来源类别" value="by_source_kind" />
            <ElOption label="材料工作流运行" value="by_workflow_run" />
            <ElOption label="AI 工作流运行" value="by_composition_run" />
            <ElOption label="AI 工作流节点" value="by_composition_node" />
            <ElOption label="机器人运行" value="by_agent_run" />
            <ElOption label="知识库" value="by_knowledge_base" />
            <ElOption label="Skill 运行" value="by_skill_run" />
            <ElOption label="机器人定义" value="by_agent_definition" />
            <ElOption label="机器人版本" value="by_agent_version" />
            <ElOption label="渠道绑定" value="by_channel_binding" />
            <ElOption label="知识库版本" value="by_knowledge_base_version" />
            <ElOption label="知识查询" value="by_knowledge_query" />
            <ElOption label="知识索引" value="by_knowledge_index" />
            <ElOption label="渠道平台" value="by_delivery_platform" />
          </ElSelect>
        </div>
        <div class="mt-2 mb-3 flex flex-wrap items-center gap-3">
          <ElSelect v-model="rankOrder" aria-label="排行金额排序" class="w-44">
            <ElOption label="计量金额从高到低" value="descending" />
            <ElOption label="计量金额从低到高" value="ascending" />
          </ElSelect>
          <ElSelect v-model="rankCurrency" aria-label="排行币种" class="w-52">
            <ElOption label="全部币种（分别排名）" value="all" />
            <ElOption v-for="currency in rankCurrencies" :key="currency" :label="currency" :value="currency" />
          </ElSelect>
          <ElInput v-model="rankSearch" clearable aria-label="查找排行维度值" placeholder="查找维度值或编号" class="!w-52" />
        </div>
        <ElAlert
          v-if="summaryTruncated"
          title="此维度超过 100 行，当前结果不代表完整排行。请缩小上方筛选范围。"
          type="warning"
          :closable="false"
          class="mb-2"
        />
        <template v-else>
          <h4 class="mt-4 mb-2 text-sm">同币种计量金额排行</h4>
          <p class="mb-2 text-sm text-gray-500">
            仅对该维度值全部事件均已测得、已定价的项目排名；{{ summaryRankExcluded }}
            个金额不完整、未知或归属不确定的分组留在下方明细。此处为计量金额，账单金额见核对列。
          </p>
          <ElTable :data="visibleRankRows" border stripe empty-text="所选维度暂无金额完整的分组">
            <ElTableColumn prop="currency" label="币种" width="100" />
            <ElTableColumn prop="rank" label="币种内名次" width="110" />
            <ElTableColumn prop="value" label="维度值" min-width="150">
              <template #default="{ row }">{{ dimensionLabel(row.value) }}</template>
            </ElTableColumn>
            <ElTableColumn prop="metered_amount" label="计量金额" min-width="135" />
            <ElTableColumn prop="total_events" label="事件数" width="100" />
            <ElTableColumn v-if="canEvents" label="查看调用" min-width="150">
              <template #default="{ row }">
                <ElButton v-if="summaryRowFilter(row.value)" size="small" @click="filterSummaryEvents(row.value)">查看该组调用</ElButton>
                <span v-else>此维度不支持按事件筛选</span>
              </template>
            </ElTableColumn>
          </ElTable>
          <p class="mt-2 text-sm text-gray-500">币种和查找条件只筛选当前排行，保留完整范围内的名次；查看该组调用后可从事件进入单次费用。</p>
        </template>
        <ElTable :data="summaryRows" border stripe empty-text="所选维度暂无计量事件">
          <ElTableColumn prop="value" label="维度值" min-width="150">
            <template #default="{ row }">{{ dimensionLabel(row.value) }}</template>
          </ElTableColumn>
          <ElTableColumn prop="currency" label="币种" width="100">
            <template #default="{ row }">{{ row.currency ?? '未定价' }}</template>
          </ElTableColumn>
          <ElTableColumn prop="total_events" label="事件数" width="100" />
          <ElTableColumn prop="unmeasured_events" label="未测得" width="100" />
          <ElTableColumn prop="unpriced_events" label="未定价" width="100" />
          <ElTableColumn label="预估（调用时预算上界）" min-width="245">
            <template #default="{ row }">
              <span>{{ estimateAmountLabel(row) }}</span>
              <template v-if="!estimateComplete(row)">
                <p v-for="known in row.estimate?.known_amounts ?? []" :key="known.currency" class="my-1 text-sm">
                  已知部分：{{ known.amount }} {{ known.currency }}
                </p>
              </template>
            </template>
          </ElTableColumn>
          <ElTableColumn label="预估依据覆盖" min-width="245">
            <template #default="{ row }">{{ estimateCoverageLabel(row) }}</template>
          </ElTableColumn>
          <ElTableColumn prop="metered_amount" label="计量金额" min-width="135">
            <template #default="{ row }">{{ amountLabel(row) }}</template>
          </ElTableColumn>
          <ElTableColumn label="失败调用计量费用" min-width="215">
            <template #default="{ row }">{{ failedAmount(row) }}</template>
          </ElTableColumn>
          <ElTableColumn prop="failed_unknown_amount_events" label="失败调用金额未知次数" min-width="180" />
          <ElTableColumn label="业务失败关联调用费用" min-width="235">
            <template #default="{ row }">{{ businessFailedAmount(row) }}</template>
          </ElTableColumn>
          <ElTableColumn prop="business_failed_unknown_amount_events" label="业务失败关联金额未知次数" min-width="195" />
          <ElTableColumn prop="reconciled_amount" label="已核对账单金额" min-width="155">
            <template #default="{ row }">{{ row.reconciled_amount ?? '未核对' }}</template>
          </ElTableColumn>
          <ElTableColumn prop="difference_amount" label="已记录差额" min-width="135">
            <template #default="{ row }">{{ row.difference_amount ?? '—' }}</template>
          </ElTableColumn>
          <ElTableColumn v-if="canEvents" label="用量事件" min-width="150">
            <template #default="{ row }">
              <ElButton
                v-if="summaryRowFilter(row.value)"
                size="small"
                @click="filterSummaryEvents(row.value)"
                >按此维度查看事件</ElButton
              >
              <span v-else>此分组不支持事件筛选</span>
            </template>
          </ElTableColumn>
        </ElTable>
      </template>
    </ElCard>

    <ElCard v-if="canEvents" class="art-table-card" shadow="never">
      <p class="mb-3 text-sm text-gray-500">
        用量事件与汇总使用同一 UTC 时间筛选；未指定时为最近 30 天。按维度查看调用会沿用当前已显示汇总的时间和业务条件。
      </p>
      <ArtTableHeader v-model:columns="columnChecks" :loading="loading" @refresh="refreshData" />
      <ArtTable
        class="usage-events-table"
        height="auto"
        row-key="id"
        :loading="loading"
        :data="data"
        :columns="columns"
        empty-text="所选范围暂无用量事件"
        :pagination="pagination"
        @pagination:size-change="handleSizeChange"
        @pagination:current-change="handleCurrentChange"
      />
    </ElCard>
    <ElDrawer
      v-model="costOpen"
      title="单次调用费用"
      :size="narrowScreen ? '100%' : 'min(720px, 100vw)'"
      @close="closeCost"
      @closed="restoreCostFocus"
    >
      <template v-if="selectedEvent">
        <ElDescriptions :column="narrowScreen ? 1 : 2" border class="mb-3">
          <ElDescriptionsItem label="事件 ID">{{ selectedEvent.id }}</ElDescriptionsItem>
          <ElDescriptionsItem label="调用 ID">{{
            show(selectedEvent.invocation_id)
          }}</ElDescriptionsItem>
          <ElDescriptionsItem label="应用 ID">{{
            show(selectedEvent.application_id)
          }}</ElDescriptionsItem>
          <ElDescriptionsItem label="环境 ID">{{
            selectedEvent.environment_id
          }}</ElDescriptionsItem>
          <ElDescriptionsItem label="来源">{{
            sourceLabel(selectedEvent.source_kind)
          }}</ElDescriptionsItem>
        </ElDescriptions>
        <p class="text-sm text-gray-500">查询原调用的费用，不会重新发起调用。</p>
        <ElButton v-if="canCost && selectedEvent.invocation_id !== null" class="mb-3"
          @click="compareSelectedEvent">将本调用带入历史比较</ElButton>
        <ElAlert
          v-if="!canCost"
          title="无单次费用权限，请向管理员申请单次费用查看权限（sand_ai:usage:cost）。"
          type="warning"
          :closable="false"
        />
        <ElSkeleton v-else-if="costLoading" :rows="6" animated aria-label="正在读取单次费用" />
        <template v-else-if="costError">
          <ElAlert :title="costError" type="warning" :closable="false" class="mb-3" />
          <ElButton @click="loadEventCost">重新查询费用</ElButton>
        </template>
        <InvocationCostDetails v-else-if="cost" :cost="cost" :columns="narrowScreen ? 1 : 2" />
        <ElButton v-else @click="loadEventCost">查询单次费用</ElButton>
      </template>
    </ElDrawer>
  </div>
</template>

<script setup lang="ts">
  import { computed, h, nextTick, onBeforeUnmount, ref, useId, watch } from 'vue'
  import { ElButton, type InputInstance } from 'element-plus'
  import { HttpError } from '@/utils/http/error'
  import { useWindowSize } from '@vueuse/core'
  import invocationApi from '../../api/invocation'
  import InvocationCostDetails from './invocation-cost-details.vue'
  import { useTable } from '@/hooks/core/useTable'
  import { useAuth } from '@/hooks/core/useAuth'
  import { useAdminReadIdentity } from '../../composables/useAdminReadIdentity'
  import usageApi from '../../api/usage'
  import type {
    SandAiUsageAllowedScope,
    SandAiInvocationCost,
    SandAiUsage,
    SandAiUsageQuery,
    SandAiUsageSummary,
    SandAiUsageSummaryQuery,
    SandAiUsageSummaryRow,
    SandAiPage
  } from '../../api/types'
  import { rankMeteredRows } from '../usage-ranking'
  import BudgetPolicyPanel from './budget-policy-panel.vue'
  import InvocationCostPanel from './invocation-cost-panel.vue'
  import CostComparisonPanel from './cost-comparison-panel.vue'
  import UsageRefundPanel from './usage-refund-panel.vue'
  import type { CostComparisonSelection } from '../../api/cost-comparison'

  const emit = defineEmits<{ pending: [value: boolean] }>()
  const { hasAuth } = useAuth()
  const identity = useAdminReadIdentity()
  const canRefund = computed(() => hasAuth('sand_ai:usage:adjustment:index') || hasAuth('sand_ai:usage:adjustment:refund'))
  const refundSelection = ref<{ environment_id: number; usage_event_id: number } | null>(null)
  const refundPanel = ref<InstanceType<typeof UsageRefundPanel>>()
  const refundPending = ref(false)
  watch(refundPending, (value) => emit('pending', value), { immediate: true })
  const canCost = computed(() => hasAuth('sand_ai:usage:cost'))
  const comparisonSelection = ref<CostComparisonSelection | null>(null)
  const comparisonPanel = ref<InstanceType<typeof CostComparisonPanel>>()
  const { width } = useWindowSize()
  const narrowScreen = computed(() => width.value < 640)
  const selectedEvent = ref<SandAiUsage | null>(null)
  const costOpen = ref(false)
  const cost = ref<SandAiInvocationCost | null>(null)
  const costLoading = ref(false)
  const costError = ref('')
  let costGeneration = 0
  let costTrigger: HTMLElement | null = null
  let comparisonAfterClose: { environment_id: number; current_invocation_id: number } | null = null
  const sourceLabels: Readonly<Record<string, string>> = {
    structured_workflow: '结构化工作流',
    chat: '聊天',
    skill: 'Skill 调用',
    skill_test: 'Skill 测试',
    knowledge_query: '知识库查询',
    knowledge_index: '知识库索引',
    agent: '机器人',
    ai_task: 'AI 任务',
    capability: '能力调用',
    workflow: '工作流',
    external_service: '外部服务',
    onboarding_verification: '开通验证',
    tool: '外部工具'
  }
  const sourceLabel = (source: string): string =>
    Object.hasOwn(sourceLabels, source) ? sourceLabels[source] : `来源类别：${source}`
  const canEvents = computed(() => hasAuth('sand_ai:usage:index'))
  const canSummary = computed(() => hasAuth('sand_ai:usage:summary'))
  const emptySearch = (): SandAiUsageQuery => ({})
  const searchForm = ref<SandAiUsageQuery>(emptySearch())
  const allowedScope = ref<SandAiUsageAllowedScope | null>(null)
  const scopeLoading = ref(false)
  const scopeError = ref('')
  let scopeGeneration = 0
  const associationFilterFields: ReadonlyArray<{ key: 'organization_id' | 'skill_id' | 'skill_version_id' | 'workflow_run_id' | 'composition_run_id' | 'agent_run_id' | 'knowledge_base_id' | 'skill_run_id' | 'agent_definition_id' | 'agent_version_id' | 'channel_binding_id' | 'knowledge_base_version_id' | 'knowledge_query_id' | 'knowledge_index_id'; label: string }> = [
    { key: 'organization_id', label: '组织 ID' },
    { key: 'skill_id', label: 'Skill ID' },
    { key: 'skill_version_id', label: 'Skill 版本 ID' },
    { key: 'workflow_run_id', label: '材料工作流运行 ID' },
    { key: 'composition_run_id', label: 'AI 工作流运行 ID' },
    { key: 'agent_run_id', label: '机器人运行 ID' },
    { key: 'knowledge_base_id', label: '知识库 ID' },
    { key: 'skill_run_id', label: 'Skill 运行 ID' },
    { key: 'agent_definition_id', label: '机器人定义 ID' },
    { key: 'agent_version_id', label: '机器人版本 ID' },
    { key: 'channel_binding_id', label: '渠道绑定 ID' },
    { key: 'knowledge_base_version_id', label: '知识库版本 ID' },
    { key: 'knowledge_query_id', label: '知识查询 ID' },
    { key: 'knowledge_index_id', label: '知识索引 ID' }
  ]
  async function loadScopes(): Promise<void> {
    const owner = identity.capture()
    if (!canSummary.value || owner === null) return
    const generation = ++scopeGeneration
    scopeLoading.value = true
    scopeError.value = ''
    try {
      const result = await usageApi.scopes()
      if (identity.current(owner) && generation === scopeGeneration && canSummary.value) allowedScope.value = result
    } catch {
      if (identity.current(owner) && generation === scopeGeneration && canSummary.value) {
        allowedScope.value = null
        scopeError.value = '允许环境读取失败，请重试；后台仍会核验每次查询的范围。'
      }
    } finally {
      if (identity.current(owner) && generation === scopeGeneration) scopeLoading.value = false
    }
  }
  const summary = ref<SandAiUsageSummary | null>(null)
  const summaryLoading = ref(false)
  const summaryError = ref('')
  const summaryExportQuery = ref<SandAiUsageSummaryQuery | null>(null)
  const exportLoading = ref(false)
  const exportError = ref('')
  const exportStatus = ref('')
  const rangeId = useId()
  const rangeMode = ref<'recent' | 'custom'>('recent')
  const fromTime = ref('')
  const toTime = ref('')
  const rangeErrors = ref({ from: '', to: '' })
  const rangeChanged = ref(false)
  const fromTimeInput = ref<InputInstance>()
  const toTimeInput = ref<InputInstance>()
  const summaryDimension = ref<SummaryDimension>('by_application')
  type SummaryDimension =
    | 'by_service'
    | 'by_application'
    | 'by_service_action'
    | 'by_capability'
    | 'by_model'
    | 'by_provider'
    | 'by_skill'
    | 'by_skill_version'
    | 'by_organization'
    | 'by_environment'
    | 'by_source_kind'
    | 'by_workflow_run'
    | 'by_composition_run'
    | 'by_composition_node'
    | 'by_agent_run'
    | 'by_knowledge_base'
    | 'by_skill_run'
    | 'by_agent_definition'
    | 'by_agent_version'
    | 'by_channel_binding'
    | 'by_knowledge_base_version'
    | 'by_knowledge_query'
    | 'by_knowledge_index'
    | 'by_delivery_platform'
  const summaryRows = computed(() => summary.value?.[summaryDimension.value].rows ?? [])
  const summaryTruncated = computed(
    () => summary.value?.[summaryDimension.value].truncated ?? false
  )
  const rankOrder = ref<'descending' | 'ascending'>('descending')
  const rankCurrency = ref('all')
  const rankSearch = ref('')
  const summaryRankRows = computed(() => rankMeteredRows(summaryRows.value, summaryTruncated.value, rankOrder.value))
  const rankCurrencies = computed(() => [...new Set(summaryRankRows.value.map((row) => row.currency))].sort())
  const visibleRankRows = computed(() => {
    const search = rankSearch.value.trim().toLocaleLowerCase()
    return summaryRankRows.value.filter((row) =>
      (rankCurrency.value === 'all' || row.currency === rankCurrency.value) &&
      (!search || dimensionLabel(row.value).toLocaleLowerCase().includes(search))
    )
  })
  watch(summaryDimension, () => { rankCurrency.value = 'all'; rankSearch.value = '' })
  watch(rankCurrencies, (currencies) => {
    if (rankCurrency.value !== 'all' && !currencies.includes(rankCurrency.value)) rankCurrency.value = 'all'
  })
  const summaryRankExcluded = computed(
    () => summaryRows.value.length - summaryRankRows.value.length
  )
  let summaryRequest = 0
  const show = (value: string | number | null | undefined): string => (value === null || value === undefined ? '未知' : String(value))
  const estimateComplete = (row: SandAiUsageSummaryRow): boolean => {
    const estimate = row.estimate
    return estimate?.basis === 'budget_reservation_upper_bound'
      && estimate.known_events === row.total_events
      && estimate.unknown_events === 0 && estimate.held_events === 0
      && estimate.amount !== null && estimate.currency !== null
      && estimate.known_amounts.length === 1
      && estimate.known_amounts[0]?.currency === estimate.currency
  }
  const estimateAmountLabel = (row: SandAiUsageSummaryRow): string => {
    const estimate = row.estimate
    if (!estimate) return '未知（当前响应未提供）'
    if (estimateComplete(row)) return `${estimate.amount} ${estimate.currency}`
    if (estimate.known_events === row.total_events && estimate.unknown_events === 0
      && estimate.held_events === 0 && estimate.known_amounts.length > 1) {
      return '按币种分别显示，不合计'
    }
    return '完整预估未知'
  }
  const estimateCoverageLabel = (row: SandAiUsageSummaryRow): string => {
    const estimate = row.estimate
    if (!estimate) return '未知（当前响应未提供冻结依据）'
    const held = estimate.held_events > 0 ? `，其中 ${estimate.held_events} 次待核实` : ''
    return `${estimate.known_events}/${row.total_events} 次有冻结依据；${estimate.unknown_events} 次未知${held}`
  }
  const amountLabel = (row: SandAiUsageSummaryRow): string => {
    if (row.metered_amount !== null) return row.metered_amount
    return row.known_metered_amount === null
      ? '未知'
      : `未知（已知部分 ${row.known_metered_amount}）`
  }
  const failedAmount = (row: SandAiUsageSummaryRow): string => {
    if (row.failed_unknown_amount_events === 0) {
      return `${row.failed_events} 次 · ${row.failed_metered_amount ?? '未知'}`
    }
    const unknown = `${row.failed_unknown_amount_events} 次金额未知`
    return row.failed_known_amount === null
      ? `${row.failed_events} 次 · ${unknown}`
      : `${row.failed_events} 次 · ${row.failed_known_amount}（已知部分；${unknown}）`
  }
  const businessFailedAmount = (row: SandAiUsageSummaryRow): string => {
    if (row.business_failed_unknown_amount_events === 0) {
      return `${row.business_failed_events} 次 · ${row.business_failed_metered_amount ?? '未知'}`
    }
    const unknown = `${row.business_failed_unknown_amount_events} 次金额未知`
    return row.business_failed_known_amount === null
      ? `${row.business_failed_events} 次 · ${unknown}`
      : `${row.business_failed_events} 次 · ${row.business_failed_known_amount}（已知部分；${unknown}）`
  }
  const quantity = (row: SandAiUsage): string => {
    if (row.metering_unit === 'audio_seconds') return `${show(row.metering_quantity)} 秒`
    if (row.metering_unit === 'external_request') return `${show(row.metering_quantity)} 次请求`
    if (row.metering_unit === 'rerank_search_units') return `${show(row.metering_quantity)} 重排搜索单位`
    if (row.metering_unit === 'rerank_tokens') return `${show(row.metering_quantity)} 重排 Token`
    return row.total_tokens === null ? '未测得' : `${row.total_tokens} Token`
  }
  async function readEvents(params: SandAiUsageQuery): Promise<SandAiPage<SandAiUsage>> {
    const owner = identity.capture()
    if (owner === null || !canEvents.value) throw new Error('请求已取消')
    try {
      const result = await usageApi.list(params)
      if (!identity.current(owner) || !canEvents.value) throw new Error('请求已取消')
      return result
    } catch (failure) {
      if (!identity.current(owner) || !canEvents.value) throw new Error('请求已取消')
      throw failure
    }
  }

  const {
    columns,
    columnChecks,
    data,
    loading,
    pagination,
    searchParams,
    getData,
    resetSearchParams,
    handleSizeChange,
    handleCurrentChange,
    refreshData,
    cancelRequest,
    clearData
  } = useTable({
    core: {
      apiFn: readEvents,
      immediate: canEvents.value && identity.actor.value !== null,
      apiParams: {},
      columnsFactory: () => [
        { prop: 'id', label: '事件 ID', width: 105 },
        { prop: 'create_time', label: '发生时间', width: 180 },
        {
          prop: 'application_id',
          label: '应用 ID',
          width: 100,
          formatter: (row: SandAiUsage) => show(row.application_id)
        },
        { prop: 'environment_id', label: '环境 ID', width: 100 },
        { prop: 'organization_id', label: '组织 ID', width: 100, formatter: (row: SandAiUsage) => show(row.organization_id) },
        { prop: 'service_code', label: '服务', width: 130, formatter: (row: SandAiUsage) => row.service_code === 'sand_ai' ? 'SandAI' : '未知' },
        { prop: 'skill_id', label: 'Skill ID', width: 100, formatter: (row: SandAiUsage) => show(row.skill_id) },
        { prop: 'skill_version_id', label: 'Skill 版本 ID', width: 130, formatter: (row: SandAiUsage) => show(row.skill_version_id) },
        { prop: 'workflow_run_id', label: '材料工作流运行 ID', width: 155, formatter: (row: SandAiUsage) => show(row.workflow_run_id) },
        { prop: 'composition_run_id', label: 'AI 工作流运行 ID', width: 155, formatter: (row: SandAiUsage) => show(row.composition_run_id) },
        { prop: 'composition_node_code', label: 'AI 工作流节点', width: 155, formatter: (row: SandAiUsage) => show(row.composition_node_code) },
        { prop: 'agent_run_id', label: '机器人运行 ID', width: 140, formatter: (row: SandAiUsage) => show(row.agent_run_id) },
        { prop: 'skill_run_id', label: 'Skill 运行 ID', width: 150, formatter: (row: SandAiUsage) => show(row.skill_run_id) },
        { prop: 'agent_definition_id', label: '机器人定义 ID', width: 150, formatter: (row: SandAiUsage) => show(row.agent_definition_id) },
        { prop: 'agent_version_id', label: '机器人版本 ID', width: 150, formatter: (row: SandAiUsage) => show(row.agent_version_id) },
        { prop: 'channel_binding_id', label: '渠道绑定 ID', width: 150, formatter: (row: SandAiUsage) => show(row.channel_binding_id) },
        { prop: 'knowledge_base_version_id', label: '知识库版本 ID', width: 150, formatter: (row: SandAiUsage) => show(row.knowledge_base_version_id) },
        { prop: 'knowledge_query_id', label: '知识查询 ID', width: 150, formatter: (row: SandAiUsage) => show(row.knowledge_query_id) },
        { prop: 'knowledge_index_id', label: '知识索引 ID', width: 150, formatter: (row: SandAiUsage) => show(row.knowledge_index_id) },
        { prop: 'delivery_platform', label: '渠道平台', width: 120, formatter: (row: SandAiUsage) => row.delivery_platform === null ? '未关联 / 未确认' : platformLabel(row.delivery_platform) },
        { prop: 'tool_run_id', label: '工具原运行 ID', width: 145, formatter: (row: SandAiUsage) => show(row.tool_run_id) },
        { prop: 'knowledge_base_id', label: '知识库 ID', width: 120, formatter: (row: SandAiUsage) => show(row.knowledge_base_id) },
        {
          prop: 'source_kind',
          label: '来源',
          minWidth: 160,
          formatter: (row: SandAiUsage) => sourceLabel(row.source_kind)
        },
        { prop: 'service_action', label: '服务动作', minWidth: 190 },
        { prop: 'capability_code', label: '能力', width: 130 },
        {
          prop: 'provider_id',
          label: '服务商 ID',
          width: 110,
          formatter: (row: SandAiUsage) => show(row.provider_id)
        },
        {
          prop: 'model_id',
          label: '模型 ID',
          width: 100,
          formatter: (row: SandAiUsage) => show(row.model_id)
        },
        {
          prop: 'usage_state',
          label: '用量状态',
          width: 105,
          formatter: (row: SandAiUsage) => (row.usage_state === 'measured' ? '已测得' : '未测得')
        },
        {
          prop: 'total_tokens',
          label: '计量用量',
          width: 125,
          formatter: quantity
        },
        {
          prop: 'metered_amount',
          label: '计量金额',
          width: 130,
          formatter: (row: SandAiUsage) => row.metered_amount ?? '未知'
        },
        {
          prop: 'currency',
          label: '币种',
          width: 85,
          formatter: (row: SandAiUsage) => show(row.currency)
        },
        {
          prop: 'pricing_state',
          label: '价格状态',
          width: 105,
          formatter: (row: SandAiUsage) => (row.pricing_state === 'priced' ? '已定价' : '未定价')
        },
        {
          prop: 'price_revision_id',
          label: '价格版本 ID',
          width: 125,
          formatter: (row: SandAiUsage) => show(row.price_revision_id)
        },
        {
          prop: 'invocation_id',
          label: '调用 ID',
          width: 105,
          formatter: (row: SandAiUsage) => show(row.invocation_id)
        },
        {
          prop: 'cost_action',
          label: '单次费用',
          minWidth: 350,
          fixed: 'right',
          formatter: (row: SandAiUsage) => {
            const refundActions = canRefund.value ? [h(ElButton, {
              size: 'small', disabled: refundPending.value,
              onClick: () => selectRefund(row)
            }, () => '退款记录')] : []
            if (!canCost.value || row.invocation_id === null) return h('div', { class: 'flex flex-wrap gap-2' }, [
              ...refundActions,
              h('span', {}, row.invocation_id === null
                ? row.tool_run_id === null ? '未关联单次调用' : `工具原运行 #${row.tool_run_id}；费用见事件计量金额`
                : '无单次费用查看权限')
            ])
            return h('div', { class: 'flex flex-wrap gap-2' }, [
              ...refundActions,
              h(ElButton, {
                size: 'small',
                disabled: costLoading.value && selectedEvent.value?.id === row.id,
                onClick: (event: MouseEvent) => showEventCost(row, event)
              }, () => '查看单次费用'),
              h(ElButton, { size: 'small', onClick: () => selectComparison(row, 'baseline') }, () =>
                comparisonSelection.value?.environment_id === row.environment_id &&
                comparisonSelection.value?.baseline_invocation_id === row.invocation_id ? '已选基线' : '设为费用基线'),
              h(ElButton, { size: 'small', onClick: () => selectComparison(row, 'current') }, () => '与基线比较')
            ])
          }
        }
      ]
    }
  })

  function selectRefund(row: SandAiUsage): void {
    if (!canRefund.value || refundPending.value || !Number.isSafeInteger(row.environment_id) || row.environment_id < 1 || !Number.isSafeInteger(row.id) || row.id < 1) return
    refundSelection.value = { environment_id: row.environment_id, usage_event_id: row.id }
    void nextTick(() => refundPanel.value?.focus())
  }

  function selectComparison(row: SandAiUsage, kind: 'current' | 'baseline', moveFocus = true): void {
    if (!canCost.value || row.invocation_id === null ||
      !Number.isSafeInteger(row.environment_id) || row.environment_id < 1 ||
      !Number.isSafeInteger(row.invocation_id) || row.invocation_id < 1) return
    const selected = comparisonSelection.value?.environment_id === row.environment_id
      ? comparisonSelection.value : { environment_id: row.environment_id }
    comparisonSelection.value = {
      ...selected,
      ...(kind === 'current' ? { current_invocation_id: row.invocation_id } : { baseline_invocation_id: row.invocation_id })
    }
    if (moveFocus) void nextTick(focusComparison)
  }

  function focusComparison(): void {
    if (canCost.value) comparisonPanel.value?.focus()
  }

  function compareSelectedEvent(): void {
    const row = selectedEvent.value
    if (!canCost.value || !row || row.invocation_id === null ||
      !Number.isSafeInteger(row.environment_id) || row.environment_id < 1 ||
      !Number.isSafeInteger(row.invocation_id) || row.invocation_id < 1) return
    selectComparison(row, 'current', false)
    comparisonAfterClose = { environment_id: row.environment_id, current_invocation_id: row.invocation_id }
    closeCost()
  }

  function invalidateCost(): void {
    ++costGeneration
    cost.value = null
    costError.value = ''
    costLoading.value = false
  }

  function closeCost(): void {
    invalidateCost()
    selectedEvent.value = null
    costOpen.value = false
  }

  function restoreCostFocus(): void {
    const selected = comparisonAfterClose
    comparisonAfterClose = null
    if (selected && canCost.value &&
      comparisonSelection.value?.environment_id === selected.environment_id &&
      comparisonSelection.value?.current_invocation_id === selected.current_invocation_id) {
      costTrigger = null
      focusComparison()
      return
    }
    if (costTrigger?.isConnected) costTrigger.focus()
    costTrigger = null
  }

  function showEventCost(row: SandAiUsage, event: MouseEvent): void {
    if (!canCost.value || row.invocation_id === null) return
    if (costLoading.value && selectedEvent.value?.id === row.id) return
    invalidateCost()
    costTrigger = event.currentTarget instanceof HTMLElement ? event.currentTarget : null
    selectedEvent.value = { ...row }
    costOpen.value = true
    void loadEventCost()
  }

  async function loadEventCost(): Promise<void> {
    const owner = identity.capture()
    if (costLoading.value || !costOpen.value || owner === null) return
    invalidateCost()
    const row = selectedEvent.value
    if (!canCost.value || !row || row.invocation_id === null) return
    if (
      !Number.isSafeInteger(row.environment_id) ||
      row.environment_id < 1 ||
      !Number.isSafeInteger(row.invocation_id) ||
      row.invocation_id < 1
    ) {
      costError.value = '此事件没有有效的环境或调用 ID，请联系管理员检查记录。'
      return
    }
    const current = ++costGeneration
    costLoading.value = true
    try {
      const result = await invocationApi.costByInvocationId(row.environment_id, row.invocation_id)
      if (identity.current(owner) && current === costGeneration && canCost.value && costOpen.value) cost.value = result
    } catch (failure) {
      if (identity.current(owner) && current === costGeneration && canCost.value && costOpen.value) {
        const reason = failure instanceof Error ? failure.message : '后台未返回费用'
        costError.value = `费用读取失败：${reason}。请核对调用归属与单次费用权限后重新查询。`
      }
    } finally {
      if (identity.current(owner) && current === costGeneration) costLoading.value = false
    }
  }

  watch(
    canCost,
    (allowed) => {
      if (!allowed) { closeCost(); comparisonSelection.value = null }
    },
    { flush: 'sync' }
  )
  watch(
    () => searchForm.value.environment_id,
    () => {
      closeCost()
      comparisonSelection.value = null
      cancelRequest()
      clearData()
    },
    { flush: 'sync' }
  )
  watch(
    canEvents,
    (allowed) => {
      if (!allowed) {
        cancelRequest()
        clearData()
        closeCost()
      }
    },
    { flush: 'sync' }
  )
  onBeforeUnmount(() => { comparisonAfterClose = null; closeCost(); emit('pending', false) })

  function dimensionLabel(value: string | null): string {
    if (value === null) return '未关联 / 归属不可确定'
    if (value === 'unattributed') return '未归属'
    const labels: Partial<Record<SummaryDimension, string>> = {
      by_application: '应用 ID',
      by_provider: '服务商 ID',
      by_model: '模型 ID',
      by_organization: '组织 ID',
      by_environment: '环境 ID',
      by_workflow_run: '材料工作流运行 ID',
      by_composition_run: 'AI 工作流运行 ID',
      by_composition_node: 'AI 工作流运行 ID / 节点',
      by_agent_run: '机器人运行 ID',
      by_knowledge_base: '知识库 ID',
      by_skill_run: 'Skill 运行 ID',
      by_agent_definition: '机器人定义 ID',
      by_agent_version: '机器人版本 ID',
      by_channel_binding: '渠道绑定 ID',
      by_knowledge_base_version: '知识库版本 ID',
      by_knowledge_query: '知识查询 ID',
      by_knowledge_index: '知识索引 ID'
    }
    if (summaryDimension.value === 'by_service') return value === 'sand_ai' ? 'SandAI（sand_ai）' : value
    if (summaryDimension.value === 'by_source_kind') return sourceLabel(value)
    if (summaryDimension.value === 'by_delivery_platform') return platformLabel(value)
    const label = labels[summaryDimension.value]
    return label ? `${label}：${value}` : value
  }

  function summaryRowFilter(value: string | null): SandAiUsageQuery | null {
    if (value === null || value === 'unattributed') return null
    const dimension = summaryDimension.value
    if (dimension === 'by_delivery_platform') return value === 'feishu' || value === 'wecom' ? { delivery_platform: value } : null
    if (dimension === 'by_service') return value === 'sand_ai' ? { service_code: 'sand_ai' } : null
    if (dimension === 'by_source_kind') return /^[a-z][a-z0-9_]{0,63}$/.test(value) ? { source_kind: value } : null
    if (dimension === 'by_service_action' || dimension === 'by_capability') {
      const maxLength = dimension === 'by_service_action' ? 96 : 64
      if (value.length > maxLength || !/^[a-z][a-z0-9_.-]+$/.test(value)) return null
      return dimension === 'by_service_action'
        ? { service_action: value }
        : { capability_code: value }
    }
    if (dimension === 'by_composition_node') {
      const parts = /^([1-9]\d*):([a-z][a-z0-9_]{0,31})$/.exec(value)
      if (!parts) return null
      const runId = Number(parts[1])
      return Number.isSafeInteger(runId) && runId > 0
        ? { composition_run_id: runId, composition_node_code: parts[2] } : null
    }
    if (!/^[1-9]\d*$/.test(value)) return null
    const id = Number(value)
    if (!Number.isSafeInteger(id) || id < 1) return null
    if (dimension === 'by_application') return { application_id: id }
    if (dimension === 'by_provider') return { provider_id: id }
    if (dimension === 'by_model') return { model_id: id }
    if (dimension === 'by_organization') return { organization_id: id }
    if (dimension === 'by_environment') return { environment_id: id }
    if (dimension === 'by_skill') return { skill_id: id }
    if (dimension === 'by_skill_version') return { skill_version_id: id }
    if (dimension === 'by_composition_run') return { composition_run_id: id, composition_node_code: undefined }
    if (dimension === 'by_workflow_run') return { workflow_run_id: id }
    if (dimension === 'by_agent_run') return { agent_run_id: id }
    if (dimension === 'by_knowledge_base') return { knowledge_base_id: id }
    if (dimension === 'by_skill_run') return { skill_run_id: id }
    if (dimension === 'by_agent_definition') return { agent_definition_id: id }
    if (dimension === 'by_agent_version') return { agent_version_id: id }
    if (dimension === 'by_channel_binding') return { channel_binding_id: id }
    if (dimension === 'by_knowledge_base_version') return { knowledge_base_version_id: id }
    if (dimension === 'by_knowledge_query') return { knowledge_query_id: id }
    if (dimension === 'by_knowledge_index') return { knowledge_index_id: id }
    return null
  }

  function filterSummaryEvents(value: string | null): void {
    if (!canEvents.value || !summary.value) return
    const filter = summaryRowFilter(value)
    if (!filter) return
    const shown = summary.value.filters
    const applied: SandAiUsageQuery = {
      environment_id: shown.environment_id ?? undefined,
      organization_id: shown.organization_id ?? undefined,
      application_id: shown.application_id ?? undefined,
      service_code: shown.service_code ?? undefined,
      service_action: shown.service_action ?? undefined,
      capability_code: shown.capability_code ?? undefined,
      provider_id: shown.provider_id ?? undefined,
      model_id: shown.model_id ?? undefined,
      usage_state: shown.usage_state ?? undefined,
      pricing_state: shown.pricing_state ?? undefined,
      reconciliation_state: shown.reconciliation_state ?? undefined,
      source_kind: shown.source_kind ?? undefined,
      skill_id: shown.skill_id ?? undefined,
      skill_version_id: shown.skill_version_id ?? undefined,
      workflow_run_id: shown.workflow_run_id ?? undefined,
      composition_run_id: shown.composition_run_id ?? undefined,
      composition_node_code: shown.composition_node_code ?? undefined,
      agent_run_id: shown.agent_run_id ?? undefined,
      knowledge_base_id: shown.knowledge_base_id ?? undefined,
      skill_run_id: shown.skill_run_id ?? undefined,
      agent_definition_id: shown.agent_definition_id ?? undefined,
      agent_version_id: shown.agent_version_id ?? undefined,
      channel_binding_id: shown.channel_binding_id ?? undefined,
      knowledge_base_version_id: shown.knowledge_base_version_id ?? undefined,
      knowledge_query_id: shown.knowledge_query_id ?? undefined,
      knowledge_index_id: shown.knowledge_index_id ?? undefined,
      delivery_platform: shown.delivery_platform ?? undefined,
      from_time: shown.from_time,
      to_time: shown.to_time
    }
    closeCost()
    searchForm.value = { ...applied, ...filter }
    rangeMode.value = 'custom'
    fromTime.value = shown.from_time
    toTime.value = shown.to_time
    handleSearch()
    void loadSummary()
  }

  const platformLabel = (value: string): string => value === 'feishu' ? '飞书' : value === 'wecom' ? '企业微信' : '未关联 / 未确认'
  function resourceQuery(): Pick<SandAiUsageSummaryQuery,
    | 'skill_run_id'
    | 'agent_definition_id'
    | 'agent_version_id'
    | 'channel_binding_id'
    | 'knowledge_base_version_id'
    | 'knowledge_query_id'
    | 'knowledge_index_id'
    | 'delivery_platform'> {
    return {
      skill_run_id: searchForm.value.skill_run_id ?? undefined,
      agent_definition_id: searchForm.value.agent_definition_id ?? undefined,
      agent_version_id: searchForm.value.agent_version_id ?? undefined,
      channel_binding_id: searchForm.value.channel_binding_id ?? undefined,
      knowledge_base_version_id: searchForm.value.knowledge_base_version_id ?? undefined,
      knowledge_query_id: searchForm.value.knowledge_query_id ?? undefined,
      knowledge_index_id: searchForm.value.knowledge_index_id ?? undefined,
      delivery_platform: searchForm.value.delivery_platform || undefined
    }
  }

  function validateCompositionFilters(): boolean {
    for (const field of associationFilterFields) {
      const value = searchForm.value[field.key]
      if (value !== undefined && value !== null && (!Number.isSafeInteger(value) || value < 1)) {
        summaryError.value = `${field.label}须为正整数`
        return false
      }
    }
    const id = searchForm.value.composition_run_id
    const code = searchForm.value.composition_node_code
    if (id !== undefined && id !== null && (!Number.isSafeInteger(id) || id < 1)) {
      summaryError.value = 'AI 工作流运行 ID 须为正整数'
      return false
    }
    if (code && (!id || !/^[a-z][a-z0-9_]{0,31}$/.test(code))) {
      summaryError.value = '先填写 AI 工作流运行 ID；节点标识须为小写字母、数字或下划线'
      return false
    }
    return true
  }

  function handleSearch(): void {
    invalidateSummary()
    if (!validateRange() || !validateCompositionFilters()) return
    for (const key of Object.keys(searchParams)) {
      if (key !== 'page' && key !== 'limit') Reflect.deleteProperty(searchParams, key)
    }
    Object.assign(searchParams, { ...searchForm.value, ...resourceQuery(),
      composition_run_id: searchForm.value.composition_run_id ?? undefined,
      composition_node_code: searchForm.value.composition_node_code || undefined },
      rangeMode.value === 'custom' ? { from_time: fromTime.value, to_time: toTime.value } : {})
    if (canEvents.value) void getData()
  }

  function handleReset(): void {
    invalidateSummary()
    searchForm.value = emptySearch()
    rangeMode.value = 'recent'
    fromTime.value = ''
    toTime.value = ''
    rangeChanged.value = false
    if (canEvents.value) resetSearchParams()
  }

  function invalidateSummary(): void {
    ++summaryRequest
    summary.value = null
    summaryExportQuery.value = null
    summaryLoading.value = false
    summaryError.value = ''
    exportLoading.value = false
    exportError.value = ''
    exportStatus.value = ''
    rangeErrors.value = { from: '', to: '' }
    rangeChanged.value = false
  }
  watch(identity.epoch, () => {
    closeCost()
    costTrigger = null
    comparisonAfterClose = null
    comparisonSelection.value = null
    refundSelection.value = null
    cancelRequest()
    clearData()
    pagination.current = 1
    pagination.total = 0
    for (const key of Object.keys(searchParams)) {
      if (key !== 'page' && key !== 'limit') Reflect.deleteProperty(searchParams, key)
    }
    searchForm.value = emptySearch()
    rangeMode.value = 'recent'
    fromTime.value = ''
    toTime.value = ''
    summaryDimension.value = 'by_application'
    rankOrder.value = 'descending'
    rankCurrency.value = 'all'
    rankSearch.value = ''
    invalidateSummary()
    ++scopeGeneration
    allowedScope.value = null
    scopeLoading.value = false
    scopeError.value = ''
    const owner = identity.capture()
    void nextTick(() => {
      if (!identity.current(owner)) return
      if (canSummary.value) void loadScopes()
      if (canEvents.value) void getData()
    })
  }, { flush: 'sync' })

  watch(searchForm, invalidateSummary, { deep: true, flush: 'sync' })
  watch(
    [rangeMode, fromTime, toTime],
    () => {
      invalidateSummary()
      rangeChanged.value = true
    },
    { flush: 'sync' }
  )
  watch(
    canSummary,
    (allowed) => {
      if (!allowed) { invalidateSummary(); ++scopeGeneration; allowedScope.value = null; scopeLoading.value = false }
      else void loadScopes()
    },
    { flush: 'sync', immediate: true }
  )
  onBeforeUnmount(() => { ++scopeGeneration; invalidateSummary() })

  // Construct and compare UTC parts explicitly; no local time parsing or normalization.
  function utcTimestamp(value: string): number | null {
    const match = /^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})$/.exec(value)
    if (value.length !== 19 || !match) return null
    const [, yearText, monthText, dayText, hourText, minuteText, secondText] = match
    const [year, month, day, hour, minute, second] = [
      yearText,
      monthText,
      dayText,
      hourText,
      minuteText,
      secondText
    ].map(Number)
    if (
      year < 1 ||
      month < 1 ||
      month > 12 ||
      day < 1 ||
      day > 31 ||
      hour > 23 ||
      minute > 59 ||
      second > 59
    )
      return null
    const date = new Date(0)
    date.setUTCFullYear(year, month - 1, day)
    date.setUTCHours(hour, minute, second, 0)
    if (
      date.getUTCFullYear() !== year ||
      date.getUTCMonth() !== month - 1 ||
      date.getUTCDate() !== day
    )
      return null
    return date.getTime()
  }

  function validateRange(): boolean {
    rangeErrors.value = { from: '', to: '' }
    if (rangeMode.value === 'recent') return true
    const from = utcTimestamp(fromTime.value)
    const to = utcTimestamp(toTime.value)
    if (from === null)
      rangeErrors.value.from = fromTime.value
        ? '请输入有效的 UTC 日期时间（YYYY-MM-DD HH:mm:ss）'
        : '请填写开始时间'
    if (to === null)
      rangeErrors.value.to = toTime.value
        ? '请输入有效的 UTC 日期时间（YYYY-MM-DD HH:mm:ss）'
        : '请填写结束时间'
    if (from !== null && to !== null) {
      if (to <= from) rangeErrors.value.to = '结束时间应晚于开始时间'
      else if (to - from > 93 * 24 * 60 * 60 * 1000) rangeErrors.value.to = '时间范围最多 93 天'
    }
    if (rangeErrors.value.from) fromTimeInput.value?.focus()
    else if (rangeErrors.value.to) toTimeInput.value?.focus()
    return !rangeErrors.value.from && !rangeErrors.value.to
  }

  async function loadSummary(): Promise<void> {
    const owner = identity.capture()
    if (!canSummary.value || summaryLoading.value || owner === null) return
    summaryError.value = ''
    if (!validateRange()) return
    if (!validateCompositionFilters()) return
    const filters = searchForm.value
    const environmentId = filters.environment_id
    if (environmentId !== undefined && environmentId !== null && (!Number.isSafeInteger(environmentId) || environmentId < 1)) {
      invalidateSummary()
      summaryError.value = '请选择有效环境，或留空汇总全部可读取环境'
      return
    }
    const requestId = ++summaryRequest
    summaryLoading.value = true
    summaryError.value = ''
    summary.value = null
    summaryExportQuery.value = null
    exportLoading.value = false
    exportError.value = ''
    exportStatus.value = ''
    const query: SandAiUsageSummaryQuery = {
      environment_id: environmentId ?? undefined,
      service_code: 'sand_ai',
      ...resourceQuery(),
      organization_id: filters.organization_id ?? undefined,
      skill_id: filters.skill_id ?? undefined,
      skill_version_id: filters.skill_version_id ?? undefined,
      workflow_run_id: filters.workflow_run_id ?? undefined,
      composition_run_id: filters.composition_run_id ?? undefined,
      composition_node_code: filters.composition_node_code || undefined,
      agent_run_id: filters.agent_run_id ?? undefined,
      knowledge_base_id: filters.knowledge_base_id ?? undefined,
      source_kind: filters.source_kind || undefined,
      ...(rangeMode.value === 'custom' ? { from_time: fromTime.value, to_time: toTime.value } : {}),
      application_id: filters.application_id ?? undefined,
      provider_id: filters.provider_id ?? undefined,
      model_id: filters.model_id ?? undefined,
      service_action: filters.service_action || undefined,
      capability_code: filters.capability_code || undefined,
      usage_state: filters.usage_state || undefined,
      pricing_state: filters.pricing_state || undefined,
      reconciliation_state: filters.reconciliation_state || undefined
    }
    rangeChanged.value = false
    try {
      const result = await usageApi.summary(query)
      if (identity.current(owner) && requestId === summaryRequest && canSummary.value) {
        summary.value = result
        summaryExportQuery.value = {
          ...query,
          from_time: result.filters.from_time,
          to_time: result.filters.to_time
        }
      }
    } catch (error) {
      if (identity.current(owner) && requestId === summaryRequest && canSummary.value) {
        summaryError.value =
          error instanceof HttpError && error.code === 403
            ? '无费用汇总权限，请向管理员申请'
            : error instanceof HttpError && error.code === 400
              ? '汇总条件未通过校验，请检查范围、筛选及 UTC 时间范围（最长 93 天）后重试'
              : '费用汇总读取失败，请重试'
      }
    } finally {
      if (identity.current(owner) && requestId === summaryRequest && canSummary.value) summaryLoading.value = false
    }
  }

  async function exportSummary(): Promise<void> {
    const owner = identity.capture()
    if (!canSummary.value || exportLoading.value || summaryLoading.value || owner === null) return
    const applied = summaryExportQuery.value
    if (!summary.value || !applied) return
    const requestId = summaryRequest
    const query = { ...applied }
    exportLoading.value = true
    exportError.value = ''
    exportStatus.value = '正在导出当前汇总'
    try {
      const blob = await usageApi.exportSummary(query)
      if (!identity.current(owner) || requestId !== summaryRequest || !canSummary.value || !summary.value) return
      const url = URL.createObjectURL(blob)
      const link = document.createElement('a')
      try {
        link.href = url
        link.download = `sand-ai-usage-summary-env-${query.environment_id ?? 'allowed'}.csv`
        document.body.append(link)
        link.click()
        exportStatus.value = 'CSV 下载已发起，请在浏览器下载记录中查看'
      } finally {
        link.remove()
        window.setTimeout(() => URL.revokeObjectURL(url), 1000)
      }
    } catch (error) {
      if (identity.current(owner) && requestId === summaryRequest && canSummary.value) {
        exportStatus.value = ''
        exportError.value =
          error instanceof HttpError && error.code === 403
            ? '无费用汇总导出权限，请向管理员申请'
            : error instanceof HttpError && error.code === 400
              ? '导出条件未通过校验，请重新查询有效环境和 UTC 时间范围（最长 93 天）后导出'
              : 'CSV 导出失败，未生成下载文件，请重试'
      }
    } finally {
      if (identity.current(owner) && requestId === summaryRequest) exportLoading.value = false
    }
  }
</script>

<style scoped>
  .usage-cost-panel {
    display: flex;
    flex-direction: column;
    min-width: 0;
  }

  .usage-cost-panel > * {
    flex-shrink: 0;
  }

  .usage-cost-panel > .art-table-card {
    flex: none;
  }

  .usage-cost-panel > .art-table-card :deep(.el-card__body) {
    height: auto;
    overflow: visible;
  }

  /* This table lives in scrolling content, rather than a fixed-height table card. */
  .usage-events-table {
    height: auto !important;
  }

  .summary-range-modes {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    min-width: 0;
    padding: 0;
    border: 0;
  }

  .summary-range-modes label {
    display: inline-flex;
    align-items: center;
    gap: 6px;
  }

  .summary-range-fields {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    margin-top: 12px;
  }

  .summary-range-field {
    flex: 1 1 240px;
    min-width: 0;
    max-width: 360px;
  }

  .summary-range-field label {
    display: block;
    margin-bottom: 6px;
  }

  .summary-range-error {
    margin: 4px 0 0;
    font-size: 14px;
    color: var(--el-color-danger);
  }

  .summary-applied-range {
    overflow-wrap: anywhere;
  }

  @media (max-width: 639px) {
    .summary-range-modes,
    .summary-range-fields {
      flex-direction: column;
    }

    .summary-range-field {
      flex: none;
      width: 100%;
      max-width: none;
    }

    .summary-range-submit {
      width: 100%;
    }

    .summary-range-submit + .summary-range-submit {
      margin-top: 8px;
      margin-left: 0;
    }
  }
</style>
