<template>
  <ElCard class="skill-card" shadow="never">
    <template #header>
      <div class="skill-header">
        <div>
          <h2>环境 Skill · 管理员配置</h2>
          <p>选定已开放资料和聊天能力，创建固定版本；测试成功后由管理员手动发布。</p>
        </div>
        <ElButton :loading="environmentLoading" @click="loadEnvironments(true)">刷新环境</ElButton>
      </div>
    </template>

    <p class="skill-hint">个人或团队的方法包使用“应用方法包 · 消费范围”入口及授权消费应用身份。本面板管理环境配置，不代表已认证的个人归属。</p>
    <ElAlert v-if="error" type="error" :title="error" show-icon closable @close="error = ''" />
    <ElAlert v-if="pending || managementRecoveryBlocked" type="warning" :closable="false"
      title="原管理请求尚待确认，不能更换内容、操作或请求号。只读找回原回执，不重新提交或更换请求号。">
      <p v-if="pending">原操作 {{ pending.operation }} · 原请求号 {{ pending.key }}<span v-if="pending.version"> · 固定版本 {{ pending.version }}</span></p>
      <ElButton v-if="managementRecoveryBlocked" :disabled="Boolean(busy)" @click="restoreManagement">重读原管理记录</ElButton>
      <ElButton v-if="pending?.operation === 'test'" :disabled="Boolean(busy)" @click="recoverTest">只读找回原测试</ElButton>
      <ElButton v-if="pending?.operation === 'test' && confirmedFailure(testResult)"
        :disabled="Boolean(busy)" @click="prepareNewTest">另起一次新的付费评测</ElButton>
      <template v-if="pending && pending.operation !== 'test'">
        <p v-if="pending.operation === 'case-create' && !pending.versionId">旧记录未保存固定版本 ID，请填写原创建案例所用的版本 ID；不能以当前草稿猜测。</p>
        <ElInputNumber v-if="pending.operation === 'case-create' && !pending.versionId" v-model="legacyReceiptVersionId" :min="1" :max="Number.MAX_SAFE_INTEGER" :disabled="Boolean(busy)" aria-label="原案例版本 ID" />
        <p v-if="['case-review', 'case-retire'].includes(pending.operation) && !pending.caseId">旧记录未保存原案例 ID，请填写原审核或废弃的案例 ID；服务端仍须核原管理员与原请求。</p>
        <ElInputNumber v-if="['case-review', 'case-retire'].includes(pending.operation) && !pending.caseId" v-model="legacyReceiptCaseId" :min="1" :max="Number.MAX_SAFE_INTEGER" :disabled="Boolean(busy)" aria-label="原案例 ID" />
        <ElButton :disabled="Boolean(busy) || managementRecoveryBlocked" @click="recoverManagement">只读找回原管理回执</ElButton>
        <p>无记录、权限拒绝或原证明不足都不表示未提交；保留原号，不重发。</p>
      </template>
    </ElAlert>
    <section v-if="testResult && !draftVersion">
      <p>原测试运行 #{{ testResult.run_id }} · {{ testResult.state }}，原版本成果只读。</p>
      <pre v-if="testResult.output" class="skill-result">{{ JSON.stringify(testResult.output, null, 2) }}</pre>
      <ElButton v-if="testResult.invocation_request_id" :loading="testCostLoading" @click="refreshTestCost">读取原测试费用</ElButton>
      <p v-if="testCost">计量费用：{{ showMoney(testCost.price.metered_amount, testCost.price.currency) }}</p>
      <p v-if="testCostError">{{ testCostError }}</p>
    </section>
    <ElForm label-width="100px" @submit.prevent>
      <ElFormItem label="消费环境">
        <ElSelect v-model="environmentId" filterable clearable placeholder="选择 Skill 所属环境"
          :disabled="Boolean(busy)" style="width: 380px" @change="changeEnvironment">
          <ElOption v-for="environment in environments" :key="environment.id"
            :value="environment.id" :label="`${environment.name} (${environment.code})`" />
        </ElSelect>
        <ElButton v-if="environments.length < environmentTotal" text :loading="environmentLoading"
          @click="loadEnvironments()">加载更多环境</ElButton>
      </ElFormItem>
    </ElForm>

    <template v-if="environmentId">
      <div class="skill-section-title">
        <h3>已有 Skill</h3>
        <ElButton :loading="busy === 'list'" :disabled="Boolean(busy)" @click="loadSkills(1)">刷新</ElButton>
      </div>
      <ElTable :data="skills" row-key="id" empty-text="当前环境尚无 Skill" v-loading="busy === 'list'">
        <ElTableColumn label="名称" prop="name" min-width="150" />
        <ElTableColumn label="代码" prop="code" min-width="150" />
        <ElTableColumn label="状态" width="120">
          <template #default="{ row }">{{ stateLabel(row.state) }}</template>
        </ElTableColumn>
        <ElTableColumn label="版本" width="160">
          <template #default="{ row }">
            草稿 {{ row.draft_version ?? '—' }} / 已发布 {{ row.active_version ?? '—' }}
          </template>
        </ElTableColumn>
        <ElTableColumn label="操作" width="100">
          <template #default="{ row }">
            <ElButton link type="primary" :disabled="Boolean(busy)" @click="readSkill(row.id)">查看</ElButton>
          </template>
        </ElTableColumn>
      </ElTable>
      <ElPagination v-if="skillTotal > 20" class="skill-pagination" background
        layout="prev, pager, next, total" :current-page="skillPage" :page-size="20"
        :total="skillTotal" @current-change="loadSkills" />

      <div class="skill-section-title"><h3>创建草稿</h3></div>
      <ElAlert v-if="sources.length === 0" type="info" :closable="false"
        title="请先在上方“知识库共享”中，将已发布资料开放给这个消费环境。" />
      <ElForm label-width="110px" @submit.prevent>
        <ElFormItem label="名称">
          <ElInput v-model.trim="form.name" maxlength="100" style="max-width: 420px" placeholder="例如：个人法律资料问答" />
        </ElFormItem>
        <ElFormItem label="代码">
          <ElInput v-model.trim="form.code" maxlength="64" style="max-width: 420px"
            placeholder="留空由系统生成；已有代码可创建下一版" />
        </ElFormItem>
        <ElFormItem label="用途">
          <ElInput v-model="form.description" maxlength="1000" style="max-width: 620px" />
        </ElFormItem>
        <ElFormItem label="资料版本">
          <ElSelect v-model="form.sourceVersionId" filterable placeholder="选择已开放的知识版本" style="width: 520px">
            <ElOption v-for="source in sources" :key="source.knowledge_base_version_id"
              :value="source.knowledge_base_version_id"
              :label="`${source.name} · ${source.code} · 第 ${source.version} 版`" />
          </ElSelect>
          <ElButton v-if="sources.length < sourceTotal" text :loading="sourceLoading"
            @click="loadSources(sourcePage + 1)">加载更多资料</ElButton>
        </ElFormItem>
        <ElFormItem label="指令">
          <ElInput v-model="form.instruction" type="textarea" :rows="4" maxlength="16000"
            style="max-width: 720px" placeholder="说明任务、回答边界和引用要求" />
        </ElFormItem>
        <ElFormItem label="输入结构">
          <ElInput v-model="form.inputSchema" type="textarea" :rows="3" style="max-width: 720px" />
        </ElFormItem>
        <ElFormItem label="输出结构">
          <ElInput v-model="form.outputSchema" type="textarea" :rows="3" style="max-width: 720px" />
        </ElFormItem>
        <ElFormItem label="需要复核"><ElSwitch v-model="form.reviewRequired" /></ElFormItem>
        <ElFormItem>
          <ElButton type="primary" :loading="busy === 'create'" :disabled="Boolean(busy) || !canCreate"
            @click="createDraft">保存草稿版本</ElButton>
          <span class="skill-hint">草稿固定资料、Schema 和模型路由；修改请创建新版本。</span>
        </ElFormItem>
      </ElForm>

      <template v-if="detail">
        <div class="skill-section-title">
          <h3>{{ detail.skill.name }} · {{ detail.skill.code }}</h3>
          <ElTag :type="detail.skill.state === 'published' ? 'success' : 'info'">
            {{ stateLabel(detail.skill.state) }}
          </ElTag>
        </div>
        <ElTable :data="detail.versions" row-key="id">
          <ElTableColumn label="版本" prop="version" width="85" />
          <ElTableColumn label="状态" width="110">
            <template #default="{ row }">{{ stateLabel(row.state) }}</template>
          </ElTableColumn>
          <ElTableColumn label="成功测试" width="110">
            <template #default="{ row }">{{ row.tested ? '已完成' : '尚无' }}</template>
          </ElTableColumn>
          <ElTableColumn label="知识版本" prop="knowledge_base_version_id" width="110" />
          <ElTableColumn label="指令" prop="instruction" min-width="260" show-overflow-tooltip />
        </ElTable>
        <template v-if="draftVersion">
          <div class="skill-section-title"><h3>第 {{ draftVersion.version }} 版案例评测</h3></div>
          <ElAlert v-if="!canPublishCases" type="info" :closable="false"
            title="发布前需要至少一个有效案例，且每个案例的最新人工判定均为通过。" />
          <ElTable :data="cases" row-key="id" empty-text="尚无案例">
            <ElTableColumn label="序号" prop="case_no" width="75" />
            <ElTableColumn label="案例" prop="name" min-width="150" />
            <ElTableColumn label="判定依据" prop="expected_criteria" min-width="210" show-overflow-tooltip />
            <ElTableColumn label="状态" width="120">
              <template #default="{ row }">{{ row.state === 'retired' ? '已废弃' : row.latest_evaluation?.verdict === 'pass' ? '最新通过' : row.latest_evaluation?.verdict === 'fail' ? '最新未通过' : '待测试' }}</template>
            </ElTableColumn>
            <ElTableColumn label="操作" width="160">
              <template #default="{ row }">
                <ElButton v-if="row.state === 'active'" link type="primary" :disabled="Boolean(busy)"
                  @click="selectCase(row)">测试</ElButton>
                <ElButton v-if="row.state === 'active'" link type="danger" :disabled="Boolean(busy)"
                  @click="retireCase(row)">废弃</ElButton>
              </template>
            </ElTableColumn>
          </ElTable>
          <ElForm label-width="110px" class="skill-case-form" @submit.prevent>
            <ElFormItem label="案例名称"><ElInput v-model.trim="caseForm.name" maxlength="100" style="max-width: 420px" /></ElFormItem>
            <ElFormItem label="测试输入">
              <ElInput v-model="caseForm.input" type="textarea" :rows="3" style="max-width: 720px" />
            </ElFormItem>
            <ElFormItem label="判定依据">
              <ElInput v-model="caseForm.criteria" type="textarea" :rows="2" maxlength="1000"
                style="max-width: 720px" placeholder="管理员判断结果是否可接受的具体条件" />
            </ElFormItem>
            <ElFormItem>
              <ElButton :loading="busy === 'case-create'" :disabled="Boolean(busy) || !caseForm.name || !caseForm.criteria"
                @click="createCase">加入案例</ElButton>
            </ElFormItem>
          </ElForm>
          <div class="skill-section-title">
            <h3>{{ activeCaseId === null ? '单次试用' : `测试案例 #${activeCaseId}` }}</h3>
            <ElButton v-if="activeCaseId !== null" text @click="activeCaseId = null">改为单次试用</ElButton>
          </div>
          <ElInput v-model="testInput" type="textarea" :rows="3" :readonly="activeCaseId !== null"
            style="max-width: 720px" />
          <div class="skill-actions">
            <ElButton type="primary" :loading="busy === 'test'" :disabled="Boolean(busy)" @click="testDraft">
              运行测试（单独计量）
            </ElButton>
            <ElButton type="success" :loading="busy === 'publish'"
              :disabled="Boolean(busy) || !canPublishCases" @click="publish">
              手动发布此版本
            </ElButton>
          </div>
          <pre v-if="testResult" class="skill-result">{{ JSON.stringify(testResult, null, 2) }}</pre>
          <div v-if="testResult" class="skill-cost">
            <div class="skill-section-title">
              <h3>本次测试费用</h3>
              <ElButton v-if="testResult.invocation_request_id" text :loading="testCostLoading"
                @click="refreshTestCost">刷新费用</ElButton>
            </div>
            <p v-if="testCostLoading">正在读取计量记录…</p>
            <ElAlert v-else-if="testCostError" type="warning" :title="testCostError" :closable="false" />
            <ElDescriptions v-else-if="testCost" :column="2" border>
              <ElDescriptionsItem label="计量状态">{{ usageStateLabel(testCost.usage.state) }}</ElDescriptionsItem>
              <ElDescriptionsItem label="计量金额">
                {{ showMoney(testCost.price.metered_amount, testCost.price.currency) }}
              </ElDescriptionsItem>
              <ElDescriptionsItem label="输入 / 输出 Token">
                {{ testCost.tokens.input_tokens ?? '未知' }} / {{ testCost.tokens.output_tokens ?? '未知' }}
              </ElDescriptionsItem>
              <ElDescriptionsItem label="账单金额">
                {{ showMoney(testCost.reconciliation.billed_amount, testCost.price.currency) }}
              </ElDescriptionsItem>
            </ElDescriptions>
            <p v-else class="skill-hint">本次测试没有可读取的调用费用。</p>
          </div>
          <ElForm v-if="canReviewTest"
            label-width="110px" class="skill-case-form" @submit.prevent>
            <ElFormItem label="判定说明">
              <ElInput v-model="reviewNote" type="textarea" :rows="2" maxlength="1000" style="max-width: 720px" />
            </ElFormItem>
            <ElFormItem>
              <ElButton type="success" :disabled="Boolean(busy)" :loading="busy === 'case-review'"
                @click="reviewCase('pass')">判定通过</ElButton>
              <ElButton type="warning" :disabled="Boolean(busy)" :loading="busy === 'case-review'"
                @click="reviewCase('fail')">判定未通过</ElButton>
            </ElFormItem>
          </ElForm>
        </template>
        <section v-if="detail.skill.state === 'published' && detail.skill.active_version" class="skill-runtime">
          <div class="skill-section-title">
            <h3>按应用身份运行已发布版本 {{ detail.skill.active_version }}</h3>
          </div>
          <p class="skill-hint">
            使用 SandIAM 签发的应用凭证，实际调用已发布版本；需要 Skill 运行与所引用知识的服务授权。
            运行会调用模型并独立计费。
          </p>
          <ElForm label-width="110px" @submit.prevent>
            <RuntimeApiKeyField v-model="runtimeApiKey" />
            <ElFormItem label="运行输入">
              <ElInput v-model="runtimeInput" type="textarea" :rows="3"
                :readonly="runtimeBusy || Boolean(pendingRuntimeRequestId)" style="max-width: 720px" />
            </ElFormItem>
            <ElFormItem>
              <ElButton type="primary" :loading="runtimeBusy"
                :disabled="runtimeBusy || runtimeRecoveryBlocked || !runtimeApiKey.trim() || !runtimeStorageScope || Boolean(pendingRuntimeRequestId)"
                @click="runPublished">运行已发布版本</ElButton>
            </ElFormItem>
          </ElForm>
          <ElButton v-if="runtimeRecoveryBlocked" :disabled="runtimeBusy" @click="restorePendingRun">重读原调用记录</ElButton>
          <div v-if="pendingRuntimeRequestId" class="skill-actions">
            <span class="skill-hint">原固定版本 {{ pendingRuntimeTarget?.version }} · 调用 {{ pendingRuntimeRequestId }} 尚未确认。只读找回原调用，不能改号重发。</span>
            <ElButton :loading="runtimeBusy" :disabled="runtimeBusy || !runtimeApiKey.trim()"
              @click="recoverPublishedRun">找回上次调用</ElButton>
            <ElButton v-if="confirmedFailure(runtimeResult)" :disabled="runtimeBusy"
              @click="prepareNewRuntime">另起一次新的付费调用</ElButton>
          </div>
          <ElAlert v-if="runtimeError" type="error" :title="runtimeError" :closable="false" />
          <template v-if="runtimeResult">
            <p class="skill-hint">
              运行 #{{ runtimeResult.run_id }} · {{ runtimeResult.state }}
              · {{ runtimeResult.review_required ? '结果需要人工复核' : '结果仍请按业务规则核对' }}
            </p>
            <ElAlert v-if="runtimeResult.state === 'failed'" type="error"
              :title="runtimeResult.error_code ?? 'Skill 运行失败'" :closable="false" />
            <pre v-if="runtimeResult.output" class="skill-result">{{ JSON.stringify(runtimeResult.output, null, 2) }}</pre>
            <ElTable :data="runtimeResult.citations" empty-text="本次运行没有来源摘录">
              <ElTableColumn label="知识版本" min-width="130">
                <template #default="{ row }">{{ row.knowledge_base_code }} · 第 {{ row.knowledge_base_version }} 版</template>
              </ElTableColumn>
              <ElTableColumn label="文件 / 来源块" min-width="130">
                <template #default="{ row }">#{{ row.file_id }} / #{{ row.source_block_id }}</template>
              </ElTableColumn>
              <ElTableColumn label="来源摘录" prop="excerpt" min-width="320" />
            </ElTable>
            <div class="skill-section-title">
              <h3>本次应用调用费用</h3>
              <ElButton v-if="runtimeResult.invocation_request_id" text :loading="runtimeCostLoading"
                @click="refreshRuntimeCost">刷新费用</ElButton>
            </div>
            <p v-if="runtimeCostLoading" class="skill-hint">正在读取计量记录…</p>
            <ElAlert v-else-if="runtimeCostError" type="warning" :title="runtimeCostError" :closable="false" />
            <ElDescriptions v-else-if="runtimeCost" :column="2" border>
              <ElDescriptionsItem label="计量状态">{{ usageStateLabel(runtimeCost.usage.state) }}</ElDescriptionsItem>
              <ElDescriptionsItem label="计量金额">
                {{ showMoney(runtimeCost.price.metered_amount, runtimeCost.price.currency) }}
              </ElDescriptionsItem>
              <ElDescriptionsItem label="输入 / 输出 Token">
                {{ runtimeCost.tokens.input_tokens ?? '未知' }} / {{ runtimeCost.tokens.output_tokens ?? '未知' }}
              </ElDescriptionsItem>
              <ElDescriptionsItem label="账单金额">
                {{ showMoney(runtimeCost.reconciliation.billed_amount, runtimeCost.price.currency) }}
              </ElDescriptionsItem>
            </ElDescriptions>
            <p v-else class="skill-hint">本次调用没有可读取的计量记录。</p>
          </template>
        </section>
        <div v-if="detail.skill.state === 'published'" class="skill-actions">
          <ElButton type="danger" :loading="busy === 'revoke'" :disabled="Boolean(busy)" @click="revoke">
            撤销公开运行
          </ElButton>
        </div>
      </template>
    </template>
  </ElCard>
</template>

<script setup lang="ts">
  import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
  import { ElMessage, ElMessageBox } from 'element-plus'
  import { useUserStore } from '@/store/modules/user'
  import iamApi, { type SandIamEnvironmentReference } from '../../api/iam'
  import invocationApi from '../../api/invocation'
  import type { SandAiInvocationCost } from '../../api/types'
  import { readRuntimeBearer } from '../../api/runtimeBearerSession'
  import { newRuntimeRequestId } from '../../api/runtime'
  import RuntimeApiKeyField from '../../components/RuntimeApiKeyField.vue'
  import skillApi, {
    type SkillCaseRow, type SkillDetail, type SkillRow, type SkillSource, type SkillTestResult
  } from '../../api/skill'

  const userStore = useUserStore()
  const environments = ref<SandIamEnvironmentReference[]>([])
  const environmentId = ref<number | null>(null)
  const environmentPage = ref(0)
  const environmentTotal = ref(0)
  const environmentLoading = ref(false)
  const skills = ref<SkillRow[]>([])
  const skillPage = ref(1)
  const skillTotal = ref(0)
  const sources = ref<SkillSource[]>([])
  const sourcePage = ref(0)
  const sourceTotal = ref(0)
  const sourceLoading = ref(false)
  const detail = ref<SkillDetail | null>(null)
  const cases = ref<SkillCaseRow[]>([])
  const activeCaseId = ref<number | null>(null)
  const reviewNote = ref('')
  const testResult = ref<SkillTestResult | null>(null)
  const testTarget = ref<{ versionId: number; caseId: number | null; runId: number } | null>(null)
  const testCost = ref<SandAiInvocationCost | null>(null)
  const testCostLoading = ref(false)
  const testCostError = ref('')
  let costRequest = 0
  const testInput = ref('{"query":"请结合资料回答"}')
  const runtimeApiKey = ref('')
  const runtimeStorageScope = ref('')
  let runtimeScopeGeneration = 0
  const runtimeInput = ref('{"query":"请结合资料回答"}')
  const runtimeResult = ref<SkillTestResult | null>(null)
  const runtimeCost = ref<SandAiInvocationCost | null>(null)
  const runtimeBusyState = ref(false)
  const runtimeBusy = computed(() => runtimeBusyState.value || operationFlight.value !== null)
  const runtimeCostLoading = ref(false)
  const runtimeError = ref('')
  const runtimeCostError = ref('')
  let runtimeGeneration = 0
  let runtimeCostGeneration = 0
  let pendingRuntime: { fingerprint: string; requestId: string } | null = null
  const pendingRuntimeRequestId = ref('')
  const pendingRuntimeTarget = ref<{ code: string; version: number } | null>(null)
  const runtimeRecoveryBlocked = ref(false)
  const busyState = ref('')
  const operationFlight = ref<{ owner: symbol; operation: string } | null>(null)
  const busy = computed(() => operationFlight.value?.operation ?? busyState.value)
  const error = ref('')
  let generation = 0
  let environmentRequest = 0
  let live = true
  type AdminPending = { operation: string; fingerprint: string; key: string; code?: string; version?: number; caseId?: number | null; skillId?: number; versionId?: number }
  const pending = ref<AdminPending | null>(null)
  const managementRecoveryBlocked = ref(false)
  const legacyReceiptVersionId = ref<number>()
  const legacyReceiptCaseId = ref<number>()

  const form = reactive({
    code: '', name: '', description: '', sourceVersionId: null as number | null,
    instruction: '', reviewRequired: true,
    inputSchema: '{"type":"object","properties":{"query":{"type":"string"}},"required":["query"],"additionalProperties":false}',
    outputSchema: '{"type":"object","properties":{"answer":{"type":"string"}},"required":["answer"],"additionalProperties":false}'
  })
  const caseForm = reactive({ name: '', input: '{"query":"请结合资料回答"}', criteria: '' })
  const draftVersion = computed(() => detail.value?.versions.find((version) => version.state === 'draft'))
  const canPublishCases = computed(() => {
    const active = cases.value.filter((item) => item.state === 'active')
    return detail.value?.skill.state !== 'revoked' && draftVersion.value?.tested === true
      && active.length > 0 && active.every((item) =>
        item.skill_version_id === draftVersion.value?.id && item.latest_evaluation?.verdict === 'pass')
  })
  const canReviewTest = computed(() => {
    const target = testTarget.value
    const result = testResult.value
    return target !== null && result?.state === 'succeeded' && target.runId === result.run_id
      && target.versionId === draftVersion.value?.id && target.caseId !== null
      && target.caseId === activeCaseId.value && cases.value.some(item =>
        item.id === target.caseId && item.state === 'active' && item.skill_version_id === target.versionId)
  })
  const canCreate = computed(() =>
    Boolean(form.name.trim() && form.instruction.trim() && form.sourceVersionId && sources.value.length)
  )

  function stateLabel(state: string): string {
    return ({ draft: '草稿', published: '已发布', revoked: '已撤销' } as Record<string, string>)[state] ?? state
  }

  function showMoney(amount: string | null, currency: string | null): string {
    if (amount === null) return '未知'
    return currency === null ? `${amount}（币种未确认）` : `${amount} ${currency}`
  }

  function usageStateLabel(state: SandAiInvocationCost['usage']['state']): string {
    const labels: Record<SandAiInvocationCost['usage']['state'], string> = {
      measured: '已测得', unmeasured: '未测得', not_dispatched: '未派发',
      failed: '派发失败', ambiguous: '派发结果不确定', pending: '派发中',
      missing_event: '缺少计量记录'
    }
    return labels[state]
  }

  function clearTestCost(): void {
    ++costRequest
    testCost.value = null
    testCostLoading.value = false
    testCostError.value = ''
  }

  function clearRuntime(): void {
    ++runtimeGeneration
    ++runtimeCostGeneration
    runtimeResult.value = null
    runtimeCost.value = null
    runtimeBusyState.value = false
    runtimeCostLoading.value = false
    runtimeError.value = ''
    runtimeCostError.value = ''
    pendingRuntime = null
    pendingRuntimeRequestId.value = ''
    pendingRuntimeTarget.value = null
    runtimeRecoveryBlocked.value = false
  }

  function runStorageKey(actor: number, environment: number, code: string, version: number, scope: string): string {
    return `sand-ai-skill-run:${actor}:${environment}:${code}:${version}:${scope}`
  }

  function currentRunStorageKey(): string | null {
    const skill = detail.value?.skill
    const actor = userStore.info.id
    const target = pendingRuntimeTarget.value
    const version = target !== null && target.code === skill?.code ? target.version : skill?.active_version
    return typeof actor === 'number' && Number.isSafeInteger(actor) && actor > 0
      && environmentId.value && skill && version
      && runtimeStorageScope.value
      ? runStorageKey(actor, environmentId.value, skill.code, version, runtimeStorageScope.value) : null
  }

  function restorePendingRun(): void {
    const actor = userStore.info.id
    const skill = detail.value?.skill
    if (typeof actor !== 'number' || !environmentId.value || !skill || !runtimeStorageScope.value) return
    try {
      runtimeRecoveryBlocked.value = false
      for (const version of detail.value?.versions ?? []) {
        const key = runStorageKey(actor, environmentId.value, skill.code, version.version, runtimeStorageScope.value)
        const requestId = sessionStorage.getItem(key)
        if (!requestId) continue
        pendingRuntimeTarget.value = { code: skill.code, version: version.version }
        pendingRuntimeRequestId.value = requestId
        return
      }
      pendingRuntimeTarget.value = null
      pendingRuntimeRequestId.value = ''
    } catch {
      runtimeRecoveryBlocked.value = true
      runtimeError.value = '原调用记录暂不可读取，未解除原请求；重读确认前不能发送新调用。'
    }
  }

  async function loadRuntimeStorageScope(apiKey: string, current: number): Promise<void> {
    if (!apiKey) return
    try {
      const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(apiKey))
      if (current !== runtimeScopeGeneration || runtimeApiKey.value.trim() !== apiKey) return
      runtimeStorageScope.value = Array.from(new Uint8Array(digest), byte => byte.toString(16).padStart(2, '0')).join('')
      restorePendingRun()
    } catch {
      if (current === runtimeScopeGeneration) runtimeError.value = '当前浏览器无法安全保存调用恢复键'
    }
  }

  async function recoverPublishedRun(): Promise<void> {
    const skill = detail.value?.skill
    const target = pendingRuntimeTarget.value
    const version = target?.version
    const apiKey = runtimeApiKey.value.trim()
    const requestId = pendingRuntimeRequestId.value
    const storageKey = currentRunStorageKey()
    if (!skill || target?.code !== skill.code || !version || !apiKey || !requestId || !storageKey || runtimeBusy.value) return
    const current = ++runtimeGeneration
    runtimeBusyState.value = true
    runtimeError.value = ''
    try {
      const result = await skillApi.readPublishedRun(apiKey, skill.code, version, requestId)
      if (current !== runtimeGeneration || currentRunStorageKey() !== storageKey) return
      runtimeResult.value = result
      if (result.state === 'succeeded') {
        sessionStorage.setItem(`${storageKey}:history:${requestId}`, requestId)
        sessionStorage.removeItem(storageKey)
        pendingRuntimeRequestId.value = ''
        pendingRuntime = null
        pendingRuntimeTarget.value = null
      }
      if (result.invocation_request_id) void loadRuntimeCost(result, apiKey)
    } catch (cause) {
      if (current === runtimeGeneration) {
        runtimeError.value = cause instanceof Error ? cause.message : '找回调用失败'
      }
    } finally {
      if (current === runtimeGeneration) runtimeBusyState.value = false
    }
  }

  async function loadRuntimeCost(run: SkillTestResult, apiKey: string): Promise<void> {
    const requestId = run.invocation_request_id
    if (!requestId) return
    const current = ++runtimeCostGeneration
    runtimeCostLoading.value = true
    runtimeCostError.value = ''
    runtimeCost.value = null
    try {
      const cost = await skillApi.runtimeCost(apiKey, requestId)
      if (cost.request_id !== requestId || (cost.skill !== null && cost.skill.run_id !== run.run_id)) {
        throw new Error('费用记录与本次应用调用不一致')
      }
      if (current === runtimeCostGeneration && runtimeResult.value?.run_id === run.run_id
        && runtimeApiKey.value.trim() === apiKey) runtimeCost.value = cost
    } catch (cause) {
      if (current === runtimeCostGeneration && runtimeResult.value?.run_id === run.run_id) {
        runtimeCostError.value = cause instanceof Error ? cause.message : '应用调用费用读取失败'
      }
    } finally {
      if (current === runtimeCostGeneration) runtimeCostLoading.value = false
    }
  }

  function refreshRuntimeCost(): void {
    if (runtimeResult.value) void loadRuntimeCost(runtimeResult.value, runtimeApiKey.value.trim())
  }

  async function runPublished(): Promise<void> {
    const skill = detail.value?.skill
    const version = skill?.active_version
    const apiKey = runtimeApiKey.value.trim()
    if (!skill || skill.state !== 'published' || !version || !apiKey || runtimeBusy.value) return
    const flight = Symbol('skill-runtime')
    operationFlight.value = { owner: flight, operation: 'runtime' }
    try {
      let input: Record<string, unknown>
      try { input = jsonObject(runtimeInput.value, '运行输入') } catch (cause) {
        runtimeError.value = cause instanceof Error ? cause.message : '运行输入无效'
        return
      }
      const fingerprint = JSON.stringify({ code: skill.code, version, input, apiKey })
      const storageKey = currentRunStorageKey()
      if (!storageKey || pendingRuntimeRequestId.value || runtimeRecoveryBlocked.value) return
      if (pendingRuntime?.fingerprint !== fingerprint) {
        pendingRuntime = { fingerprint, requestId: newRuntimeRequestId('skill') }
      }
      const requestId = pendingRuntime.requestId
      try {
        sessionStorage.setItem(storageKey, requestId)
        if (sessionStorage.getItem(storageKey) !== requestId) throw new Error('原调用号未保存')
      } catch {
        runtimeRecoveryBlocked.value = true
        runtimeError.value = '原调用号无法可靠保存，尚未发送；请重读原调用记录。'
        return
      }
      pendingRuntimeTarget.value = { code: skill.code, version }
      pendingRuntimeRequestId.value = requestId
      const current = ++runtimeGeneration
      ++runtimeCostGeneration
      runtimeBusyState.value = true
      runtimeResult.value = null
      runtimeCost.value = null
      runtimeCostLoading.value = false
      runtimeError.value = ''
      runtimeCostError.value = ''
      try {
        const result = await skillApi.runPublished(apiKey, skill.code, version, input, requestId)
        if (current !== runtimeGeneration || detail.value?.skill.id !== skill.id
          || detail.value.skill.active_version !== version) return
        runtimeResult.value = result
        if (result.state === 'succeeded') {
          sessionStorage.setItem(`${storageKey}:history:${requestId}`, requestId)
          sessionStorage.removeItem(storageKey)
          pendingRuntimeRequestId.value = ''
          pendingRuntime = null
          pendingRuntimeTarget.value = null
        }
        if (result.invocation_request_id) void loadRuntimeCost(result, apiKey)
      } catch (cause) {
        if (current === runtimeGeneration) {
          runtimeError.value = cause instanceof Error ? cause.message : '应用调用失败'
        }
      } finally {
        if (current === runtimeGeneration) runtimeBusyState.value = false
      }
    } finally {
      if (operationFlight.value?.owner === flight) operationFlight.value = null
    }
  }

  async function loadTestCost(run: SkillTestResult, environment: number): Promise<void> {
    const requestId = run.invocation_request_id
    if (!requestId || !environment) return
    const current = ++costRequest
    testCost.value = null
    testCostError.value = ''
    testCostLoading.value = true
    try {
      const result = await invocationApi.cost(environment, requestId)
      if (result.request_id !== requestId || (result.skill !== null && result.skill.run_id !== run.run_id)) {
        throw new Error('费用记录与本次 Skill 测试不一致')
      }
      if (current === costRequest && testResult.value?.run_id === run.run_id
        && testResult.value.invocation_request_id === requestId && environmentId.value === environment) {
        testCost.value = result
      }
    } catch (cause) {
      if (current === costRequest && testResult.value?.run_id === run.run_id
        && testResult.value.invocation_request_id === requestId && environmentId.value === environment) {
        testCostError.value = cause instanceof Error ? cause.message : '单次费用读取失败'
      }
    } finally {
      if (current === costRequest) testCostLoading.value = false
    }
  }

  function refreshTestCost(): void {
    if (testResult.value && environmentId.value) {
      void loadTestCost(testResult.value, environmentId.value)
    }
  }

  function jsonObject(text: string, label: string): Record<string, unknown> {
    let value: unknown
    try { value = JSON.parse(text) } catch { throw new Error(`${label}不是有效 JSON`) }
    if (typeof value !== 'object' || value === null || Array.isArray(value)) {
      throw new Error(`${label}必须是 JSON 对象`)
    }
    return value as Record<string, unknown>
  }

  function managementStorageKey(): string {
    const actor = userStore.info.id
    if (typeof actor !== 'number' || !Number.isSafeInteger(actor) || actor < 1 || !environmentId.value)
      throw new Error('当前管理员与消费环境尚未确认')
    return `sand-ai:admin-skill:pending:${actor}:${environmentId.value}`
  }

  function restoreManagement(): void {
    pending.value = null
    legacyReceiptVersionId.value = undefined
    legacyReceiptCaseId.value = undefined
    managementRecoveryBlocked.value = false
    if (!environmentId.value) return
    try {
      const raw: unknown = JSON.parse(sessionStorage.getItem(managementStorageKey()) ?? 'null')
      if (raw === null) return
      if (typeof raw !== 'object' || Array.isArray(raw)) throw new Error('原管理记录无效')
      const value = raw as Record<string, unknown>
      if (typeof value.operation !== 'string'
        || !['create', 'case-create', 'case-review', 'case-retire', 'test', 'publish', 'revoke'].includes(value.operation)
        || typeof value.fingerprint !== 'string' || !/^[a-f0-9]{64}$/.test(value.fingerprint)
        || typeof value.key !== 'string' || !/^[A-Za-z0-9][A-Za-z0-9_.:-]{7,95}$/.test(value.key))
        throw new Error('原管理记录无效')
      const saved: AdminPending = { operation: value.operation, fingerprint: value.fingerprint, key: value.key }
      if (value.operation === 'publish' || value.operation === 'revoke') {
        if (typeof value.skillId !== 'number' || !Number.isSafeInteger(value.skillId) || value.skillId < 1
          || (value.operation === 'publish' && (typeof value.version !== 'number' || !Number.isSafeInteger(value.version) || value.version < 1)))
          throw new Error('原发布或撤销目标无效')
        saved.skillId = value.skillId
        if (typeof value.version === 'number') saved.version = value.version
      }
      if (value.operation === 'test') {
        if (typeof value.code !== 'string' || !value.code
          || typeof value.version !== 'number' || !Number.isSafeInteger(value.version) || value.version < 1
          || (value.caseId !== null && (typeof value.caseId !== 'number' || !Number.isSafeInteger(value.caseId) || value.caseId < 1)))
          throw new Error('原测试目标无效')
        saved.code = value.code; saved.version = value.version; saved.caseId = value.caseId as number | null
        if (typeof value.skillId === 'number' && Number.isSafeInteger(value.skillId) && value.skillId > 0) saved.skillId = value.skillId
      }
      for (const field of ['skillId', 'versionId', 'caseId'] as const) {
        if (value[field] === undefined || value[field] === null) continue
        if (typeof value[field] !== 'number' || !Number.isSafeInteger(value[field]) || value[field] < 1)
          throw new Error('原管理目标无效')
        saved[field] = value[field]
      }
      pending.value = saved
    } catch {
      managementRecoveryBlocked.value = true
      error.value = '原管理记录暂不可确认，不能发送新操作；请重读原记录。'
    }
  }

  async function keyFor(operation: string, body: unknown, target?: { code?: string; version?: number; caseId?: number | null; skillId?: number; versionId?: number }, confirmIntent?: () => void): Promise<string> {
    if (managementRecoveryBlocked.value) throw new Error('请先重读原管理记录')
    const current = generation
    const actor = userStore.info.id
    const storage = managementStorageKey()
    const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(JSON.stringify(body)))
    if (current !== generation || actor !== userStore.info.id || storage !== managementStorageKey())
      throw new Error('管理员或消费环境已变化，未发送原操作')
    confirmIntent?.()
    const fingerprint = Array.from(new Uint8Array(digest), byte => byte.toString(16).padStart(2, '0')).join('')
    if (pending.value) {
      if (pending.value.operation !== operation || pending.value.fingerprint !== fingerprint)
        throw new Error('原管理请求尚未确认，请恢复同一操作和原内容，不能换请求号。')
      throw new Error('原管理请求尚未确认，请只读找回原回执；不会重新提交。')
    }
    const original: AdminPending = { operation, fingerprint, key: crypto.randomUUID(), ...target }
    const encoded = JSON.stringify(original)
    sessionStorage.setItem(storage, encoded)
    if (sessionStorage.getItem(storage) !== encoded) {
      managementRecoveryBlocked.value = true
      throw new Error('原请求号无法可靠保存，尚未发送。')
    }
    pending.value = original
    return original.key
  }

  function settlePending(): void {
    if (pending.value) sessionStorage.setItem(`${managementStorageKey()}:history:${pending.value.key}`, JSON.stringify(pending.value))
    sessionStorage.removeItem(managementStorageKey())
    pending.value = null
  }

  function showError(cause: unknown): void {
    error.value = cause instanceof Error ? cause.message : '请求失败，请稍后重试'
  }

  async function loadEnvironments(reset = false): Promise<void> {
    if (environmentLoading.value) return
    const page = reset ? 1 : environmentPage.value + 1
    const actor = userStore.info.id
    const current = ++environmentRequest
    environmentLoading.value = true
    try {
      const result = await iamApi.listEnvironments({ page, limit: 100 })
      if (!live || current !== environmentRequest || actor !== userStore.info.id) return
      environments.value = reset ? result.data : [...environments.value, ...result.data]
      environmentPage.value = page
      environmentTotal.value = result.total
    } catch (cause) {
      if (live && current === environmentRequest && actor === userStore.info.id) showError(cause)
    } finally {
      if (current === environmentRequest) environmentLoading.value = false
    }
  }

  function changeEnvironment(): void {
    ++generation
    clearRuntime()
    runtimeApiKey.value = ''
    busyState.value = ''
    sourceLoading.value = false
    skills.value = []
    sources.value = []
    detail.value = null
    cases.value = []
    activeCaseId.value = null
    testResult.value = null
    clearTestCost()
    skillTotal.value = 0
    sourceTotal.value = 0
    sourcePage.value = 0
    testTarget.value = null
    reviewNote.value = ''
    testInput.value = '{"query":"请结合资料回答"}'
    runtimeInput.value = '{"query":"请结合资料回答"}'
    Object.assign(caseForm, { name: '', input: '{"query":"请结合资料回答"}', criteria: '' })
    Object.assign(form, {
      code: '', name: '', description: '', sourceVersionId: null, instruction: '', reviewRequired: true,
      inputSchema: '{"type":"object","properties":{"query":{"type":"string"}},"required":["query"],"additionalProperties":false}',
      outputSchema: '{"type":"object","properties":{"answer":{"type":"string"}},"required":["answer"],"additionalProperties":false}'
    })
    error.value = ''
    restoreManagement()
    if (environmentId.value) {
      void loadSkills(1)
      void loadSources(1)
    }
  }

  function changeAdministrator(): void {
    ++environmentRequest
    environmentLoading.value = false
    environments.value = []
    environmentPage.value = 0
    environmentTotal.value = 0
    environmentId.value = null
    ++runtimeScopeGeneration
    runtimeStorageScope.value = ''
    runtimeApiKey.value = ''
    changeEnvironment()
    pending.value = null
    error.value = ''
    testInput.value = '{"query":"请结合资料回答"}'
    runtimeInput.value = '{"query":"请结合资料回答"}'
    reviewNote.value = ''
    caseForm.name = ''
    caseForm.input = '{"query":"请结合资料回答"}'
    caseForm.criteria = ''
    Object.assign(form, {
      code: '', name: '', description: '', sourceVersionId: null, instruction: '', reviewRequired: true,
      inputSchema: '{"type":"object","properties":{"query":{"type":"string"}},"required":["query"],"additionalProperties":false}',
      outputSchema: '{"type":"object","properties":{"answer":{"type":"string"}},"required":["answer"],"additionalProperties":false}'
    })
    const actor = userStore.info.id
    if (typeof actor === 'number' && Number.isSafeInteger(actor) && actor > 0) void loadEnvironments(true)
  }

  async function loadSkills(page: number, flight?: symbol): Promise<void> {
    const environment = environmentId.value
    if (!environment || busyState.value || (operationFlight.value !== null && operationFlight.value.owner !== flight)) return
    const current = generation
    busyState.value = 'list'
    error.value = ''
    try {
      const result = await skillApi.list(environment, page)
      if (current !== generation || environmentId.value !== environment) return
      skills.value = result.data
      skillPage.value = page
      skillTotal.value = result.total
    } catch (cause) {
      if (current === generation) showError(cause)
    } finally {
      if (current === generation) busyState.value = ''
    }
  }

  async function loadSources(page: number): Promise<void> {
    const environment = environmentId.value
    if (!environment || sourceLoading.value) return
    const current = generation
    sourceLoading.value = true
    try {
      const result = await skillApi.sources(environment, page)
      if (current !== generation || environmentId.value !== environment) return
      sources.value = page === 1 ? result.data : [...sources.value, ...result.data]
      sourcePage.value = page
      sourceTotal.value = result.total
    } catch (cause) {
      if (current === generation) showError(cause)
    } finally {
      if (current === generation) sourceLoading.value = false
    }
  }

  async function readSkill(skillId: number, flight?: symbol): Promise<void> {
    const environment = environmentId.value
    if (!environment || busyState.value || (operationFlight.value !== null && operationFlight.value.owner !== flight)) return
    if (detail.value?.skill.id !== skillId) clearRuntime()
    const current = generation
    busyState.value = 'read'
    error.value = ''
    try {
      const result = await skillApi.read(environment, skillId)
      if (current !== generation || environmentId.value !== environment) return
      if (detail.value?.skill.active_version !== result.skill.active_version
        || detail.value?.skill.state !== result.skill.state) clearRuntime()
      detail.value = result
      restorePendingRun()
      testResult.value = null
      clearTestCost()
      cases.value = []
      activeCaseId.value = null
      const draft = result.versions.find((version) => version.state === 'draft')
      if (draft) await loadCases(environment, draft.id, current)
    } catch (cause) {
      if (current === generation) showError(cause)
    } finally {
      if (current === generation) busyState.value = ''
    }
  }

  async function loadCases(environment: number, versionId: number, current: number): Promise<void> {
    try {
      const result = await skillApi.cases(environment, versionId)
      if (current === generation && environmentId.value === environment
        && draftVersion.value?.id === versionId) cases.value = result.cases
    } catch (cause) {
      if (current === generation) showError(cause)
    }
  }

  function selectCase(item: SkillCaseRow): void {
    activeCaseId.value = item.id
    testInput.value = JSON.stringify(item.input, null, 2)
    testResult.value = null
    testTarget.value = null
    clearTestCost()
    reviewNote.value = ''
  }

  async function createCase(): Promise<void> {
    const environment = environmentId.value
    const skill = detail.value?.skill
    const version = draftVersion.value
    if (!environment || !skill || !version || busy.value) return
    const flight = Symbol('skill-case-create')
    operationFlight.value = { owner: flight, operation: 'case-create' }
    try {
      let input: Record<string, unknown>
      try { input = jsonObject(caseForm.input, '案例输入') } catch (cause) { showError(cause); return }
      const name = caseForm.name.trim()
      const criteria = caseForm.criteria.trim()
      if (!name || !criteria) return
      const current = generation
      busyState.value = 'case-create'
      error.value = ''
      try {
        await skillApi.createCase(
          environment, skill.id, version.id, name, input, criteria,
          await keyFor('case-create', { environment, skillId: skill.id, versionId: version.id, name, input, criteria },
            { skillId: skill.id, versionId: version.id, version: version.version })
        )
        if (current !== generation) return
        settlePending()
        ElMessage.success('案例已加入固定草稿版本')
        caseForm.name = ''
        caseForm.criteria = ''
        await loadCases(environment, version.id, current)
      } catch (cause) {
        if (current === generation) showError(cause)
      } finally {
        if (current === generation) busyState.value = ''
      }
    } finally {
      if (operationFlight.value?.owner === flight) operationFlight.value = null
    }
  }

  async function reviewCase(verdict: 'pass' | 'fail'): Promise<void> {
    const environment = environmentId.value
    const version = draftVersion.value
    const caseId = activeCaseId.value
    const run = testResult.value
    if (!environment || !version || !caseId || !run || !canReviewTest.value || busy.value) return
    const flight = Symbol('skill-case-review')
    operationFlight.value = { owner: flight, operation: 'case-review' }
    try {
      const note = reviewNote.value.trim()
      const current = generation
      busyState.value = 'case-review'
      error.value = ''
      try {
        await skillApi.reviewCase(
          environment, caseId, run.run_id, verdict, note,
          await keyFor('case-review', { environment, caseId, runId: run.run_id, verdict, note },
            { skillId: detail.value?.skill.id, versionId: version.id, version: version.version, caseId })
        )
        if (current !== generation) return
        settlePending()
        ElMessage.success(verdict === 'pass' ? '案例已判定通过' : '案例已判定未通过')
        await loadCases(environment, version.id, current)
      } catch (cause) {
        if (current === generation) showError(cause)
      } finally {
        if (current === generation) busyState.value = ''
      }
    } finally {
      if (operationFlight.value?.owner === flight) operationFlight.value = null
    }
  }

  async function retireCase(item: SkillCaseRow): Promise<void> {
    const environment = environmentId.value
    const version = draftVersion.value
    if (!environment || !version || busy.value) return
    const flight = Symbol('skill-case-retire')
    operationFlight.value = { owner: flight, operation: 'case-retire' }
    try {
      const current = generation
      let reason: string
      try {
        const result = await ElMessageBox.prompt(`说明废弃“${item.name}”的原因`, '废弃案例', {
          inputValidator: (value) => value.trim().length > 0 || '请填写原因'
        })
        reason = result.value.trim()
      } catch { return }
      if (current !== generation || environmentId.value !== environment) return
      busyState.value = 'case-retire'
      error.value = ''
      try {
        await skillApi.retireCase(environment, item.id, reason, await keyFor('case-retire', { environment, caseId: item.id, reason },
          { skillId: detail.value?.skill.id, versionId: version.id, version: version.version, caseId: item.id }))
        if (current !== generation) return
        settlePending()
        ElMessage.success('案例已废弃，历史判定仍保留')
        if (activeCaseId.value === item.id) {
          activeCaseId.value = null
          testResult.value = null
          clearTestCost()
        }
        await loadCases(environment, version.id, current)
      } catch (cause) {
        if (current === generation) showError(cause)
      } finally {
        if (current === generation) busyState.value = ''
      }
    } finally {
      if (operationFlight.value?.owner === flight) operationFlight.value = null
    }
  }

  async function createDraft(): Promise<void> {
    const environment = environmentId.value
    const source = sources.value.find((item) => item.knowledge_base_version_id === form.sourceVersionId)
    if (!environment || !source || busy.value) return
    const flight = Symbol('skill-create')
    operationFlight.value = { owner: flight, operation: 'create' }
    try {
      let body: Record<string, unknown>
      try {
        body = {
          ...(form.code.trim() ? { code: form.code.trim() } : {}),
          名称: form.name.trim(), 描述: form.description.trim(), 指令: form.instruction.trim(),
          输入结构: jsonObject(form.inputSchema, '输入结构'),
          输出结构: jsonObject(form.outputSchema, '输出结构'),
          复核策略: { review_required: form.reviewRequired },
          知识库版本引用: {
            knowledge_base_version_id: source.knowledge_base_version_id,
            snapshot_sha256: source.snapshot_sha256
          }
        }
      } catch (cause) { showError(cause); return }
      const current = generation
      busyState.value = 'create'
      error.value = ''
      try {
        const result = await skillApi.createDraft(environment, body, await keyFor('create', { environment, body }))
        if (current !== generation) return
        settlePending()
        ElMessage.success(`已保存 ${result.code} 第 ${result.version} 版草稿`)
        busyState.value = ''
        await loadSkills(1, flight)
        if (current !== generation) return
        await readSkill(result.skill_id, flight)
      } catch (cause) {
        if (current === generation) showError(cause)
      } finally {
        if (current === generation) busyState.value = ''
      }
    } finally {
      if (operationFlight.value?.owner === flight) operationFlight.value = null
    }
  }

  async function testDraft(): Promise<void> {
    const environment = environmentId.value
    const skill = detail.value?.skill
    const version = draftVersion.value
    if (!environment || !skill || !version || busy.value || managementRecoveryBlocked.value) return
    if (pending.value?.operation === 'test') { await recoverTest(); return }
    const flight = Symbol('skill-test')
    operationFlight.value = { owner: flight, operation: 'test' }
    try {
      let input: Record<string, unknown>
      const selectedCase = cases.value.find((item) => item.id === activeCaseId.value && item.state === 'active')
      try {
        input = selectedCase ? selectedCase.input : jsonObject(testInput.value, '测试输入')
      } catch (cause) { showError(cause); return }
      const current = generation
      busyState.value = 'test'
      error.value = ''
      testResult.value = null
      clearTestCost()
      try {
        const result = await skillApi.test(
          environment, skill.code, version.version, input,
          await keyFor('test', { environment, code: skill.code, version: version.version, caseId: selectedCase?.id ?? null, input },
            { code: skill.code, version: version.version, caseId: selectedCase?.id ?? null, skillId: skill.id })
        )
        if (current !== generation) return
        if (result.state === 'succeeded') settlePending()
        testResult.value = result
        clearTestCost()
        ElMessage.info(`测试运行 #${result.run_id} · ${result.state === 'running' ? '尚待确认，请只读找回原测试' : result.state === 'succeeded' ? '已成功' : '未成功，请核对原结果和费用'}`)
        busyState.value = ''
        await readSkill(skill.id, flight)
        if (current === generation && detail.value?.skill.id === skill.id) {
          testResult.value = result
          testTarget.value = { versionId: version.id, caseId: selectedCase?.id ?? null, runId: result.run_id }
          activeCaseId.value = selectedCase?.id ?? null
          if (result.invocation_request_id) void loadTestCost(result, environment)
        }
      } catch (cause) {
        if (current === generation) showError(cause)
      } finally {
        if (current === generation) busyState.value = ''
      }
    } finally {
      if (operationFlight.value?.owner === flight) operationFlight.value = null
    }
  }

  async function recoverTest(): Promise<void> {
    const original = pending.value
    const environment = environmentId.value
    if (!environment || !original || original.operation !== 'test'
      || !original.code || !original.version || busy.value) return
    const current = generation
    busyState.value = 'test-read'
    error.value = ''
    try {
      const result = await skillApi.readTestRun(environment, original.code, original.version, original.key)
      if (current !== generation || environmentId.value !== environment || pending.value !== original) return
      if (original.skillId && detail.value?.skill.id !== original.skillId) {
        busyState.value = ''
        await readSkill(original.skillId)
        if (current !== generation || pending.value !== original || environmentId.value !== environment) return
      }
      testResult.value = result
      const target = detail.value?.skill.code === original.code
        ? detail.value.versions.find(version => version.version === original.version) : undefined
      testTarget.value = target ? { versionId: target.id, caseId: original.caseId ?? null, runId: result.run_id } : null
      activeCaseId.value = original.caseId ?? null
      if (result.state === 'succeeded') settlePending()
      if (result.invocation_request_id) void loadTestCost(result, environment)
    } catch (cause) {
      if (current === generation) showError(cause)
    } finally {
      if (current === generation) busyState.value = ''
    }
  }

  const uncertainReceiptCodes = new Set([
    'SAND_AI_USAGE_PERSISTENCE_FAILED', 'SAND_AI_SKILL_RUN_IN_PROGRESS',
    'SAND_AI_RECEIPT_INVALID', 'SAND_AI_DISPATCH_AMBIGUOUS'
  ])
  function confirmedFailure(result: SkillTestResult | null): boolean {
    return result?.state === 'failed' && typeof result.error_code === 'string'
      && result.error_code.length > 0 && !uncertainReceiptCodes.has(result.error_code)
  }
  async function prepareNewTest(): Promise<void> {
    const original = pending.value
    const environment = environmentId.value
    if (!environment || !original || original.operation !== 'test' || busy.value || !confirmedFailure(testResult.value)) return
    const flight = Symbol('skill-test-read')
    operationFlight.value = { owner: flight, operation: 'test-read' }
    try {
      const current = generation
      try {
        await ElMessageBox.confirm('原测试已显示失败，但可能已派发并收费。准备新的评测会使用新的请求号，可能再次产生费用；原测试号和费用保留。', '另起一次新的付费评测')
      } catch { return }
      if (current !== generation || pending.value !== original || busyState.value || !original.code || !original.version) return
      busyState.value = 'test-read'
      try {
        const result = await skillApi.readTestRun(environment, original.code, original.version, original.key)
        if (current !== generation || pending.value !== original) return
        testResult.value = result
        if (!confirmedFailure(result)) throw new Error('原失败终态无法确认，保留原号；未发送新评测。')
        settlePending()
        error.value = ''
      } catch (cause) { if (current === generation) showError(cause) }
      finally { if (current === generation) busyState.value = '' }
    } finally {
      if (operationFlight.value?.owner === flight) operationFlight.value = null
    }
  }
  async function prepareNewRuntime(): Promise<void> {
    const target = pendingRuntimeTarget.value
    const requestId = pendingRuntimeRequestId.value
    const storage = currentRunStorageKey()
    const credential = runtimeApiKey.value.trim()
    if (!target || !requestId || !storage || !credential || runtimeBusy.value || !confirmedFailure(runtimeResult.value)) return
    const flight = Symbol('skill-runtime-read')
    operationFlight.value = { owner: flight, operation: 'runtime-read' }
    try {
      const current = runtimeGeneration
      try {
        await ElMessageBox.confirm('原调用已显示失败，但可能已派发并收费。准备新的调用可能再次产生费用；原请求号和费用保留，不会立即发送。', '另起一次新的付费调用')
      } catch { return }
      if (current !== runtimeGeneration || storage !== currentRunStorageKey() || runtimeBusyState.value) return
      runtimeBusyState.value = true
      try {
        const result = await skillApi.readPublishedRun(credential, target.code, target.version, requestId)
        if (current !== runtimeGeneration || storage !== currentRunStorageKey()) return
        runtimeResult.value = result
        if (!confirmedFailure(result)) throw new Error('原失败终态无法确认，保留原号；未发送新调用。')
        sessionStorage.setItem(`${storage}:history:${requestId}`, requestId)
        sessionStorage.removeItem(storage)
        pendingRuntimeRequestId.value = ''
        pendingRuntimeTarget.value = null
        pendingRuntime = null
      } catch (cause) { if (current === runtimeGeneration) runtimeError.value = cause instanceof Error ? cause.message : '原失败读取未确认' }
      finally { if (current === runtimeGeneration) runtimeBusyState.value = false }
    } finally {
      if (operationFlight.value?.owner === flight) operationFlight.value = null
    }
  }

  async function recoverManagement(): Promise<void> {
    const original = pending.value
    const environment = environmentId.value
    if (!environment || !original || busy.value || managementRecoveryBlocked.value) return
    const operation = original.operation
    if (operation !== 'create' && operation !== 'case-create' && operation !== 'case-review'
      && operation !== 'case-retire' && operation !== 'publish' && operation !== 'revoke') return
    const versionId = original.versionId ?? legacyReceiptVersionId.value
    const caseId = original.caseId ?? legacyReceiptCaseId.value
    if ((operation === 'case-create' && (!versionId || !Number.isSafeInteger(versionId) || versionId < 1))
      || (['case-review', 'case-retire'].includes(operation) && (!caseId || !Number.isSafeInteger(caseId) || caseId < 1))) {
      error.value = '请明确填写原案例目标；未读取或重新提交任何管理操作。'
      return
    }
    const current = generation
    const actor = String(userStore.info.id)
    busyState.value = 'management-read'
    error.value = ''
    try {
      const result = await skillApi.readAdminReceipt(environment, operation, original.key,
        { skillId: original.skillId, version: original.version, versionId, caseId: caseId ?? undefined })
      if (current !== generation || environmentId.value !== environment || pending.value !== original) return
      const expected = { create: 'create_draft', 'case-create': 'create', 'case-review': 'review',
        'case-retire': 'retire', publish: 'publish', revoke: 'revoke' }[operation]
      if (result.operation !== expected || result.request_id !== original.key || result.environment_id !== environment
        || result.actor_ref !== actor || result.idempotent_replay !== true
        || ![result.skill_id, result.version_id, result.version].every(id => Number.isSafeInteger(id) && id > 0)
        || typeof result.code !== 'string' || !/^[a-z0-9][a-z0-9_-]{1,63}$/.test(result.code)
        || (original.skillId !== undefined && result.skill_id !== original.skillId)
        || (original.version !== undefined && result.version !== original.version)
        || (versionId !== undefined && result.version_id !== versionId)
        || (caseId !== undefined && result.case_id !== caseId)
        || (operation === 'create' && result.state !== 'draft')
        || (operation === 'publish' && result.state !== 'published')
        || (operation === 'revoke' && result.state !== 'revoked')
        || (operation === 'case-create' && (!Number.isSafeInteger(result.case_id) || !Number.isSafeInteger(result.case_no)))
        || (operation === 'case-review' && (!Number.isSafeInteger(result.evaluation_id) || !Number.isSafeInteger(result.skill_run_id)
          || !['pass', 'fail'].includes(result.verdict ?? '')))
        || (operation === 'case-retire' && result.state !== 'retired'))
        throw new Error('原回执与管理员、请求号或固定目标不一致，原号仍保留。')
      settlePending()
      ElMessage.success(`已找回 ${result.code} 第 ${result.version} 版原管理回执；当前状态重新读取。`)
      busyState.value = ''
      await loadSkills(1)
      if (current === generation) await readSkill(result.skill_id)
    } catch (cause) {
      if (current === generation) showError(cause)
    } finally {
      if (current === generation) busyState.value = ''
    }
  }

  function publicationIntent(): string {
    const skill = detail.value?.skill
    const fixed = draftVersion.value
    return JSON.stringify({
      skill: skill ? { id: skill.id, code: skill.code, state: skill.state, status: skill.status,
        active_version_id: skill.active_version_id } : null,
      version: fixed ? { id: fixed.id, version: fixed.version, state: fixed.state, tested: fixed.tested,
        knowledge_base_version_id: fixed.knowledge_base_version_id, snapshot_sha256: fixed.snapshot_sha256 } : null,
      cases: cases.value.filter(item => item.state === 'active').map(item => ({
        id: item.id, skill_version_id: item.skill_version_id, state: item.state,
        evaluation: item.latest_evaluation ? { id: item.latest_evaluation.id,
          skill_run_id: item.latest_evaluation.skill_run_id, verdict: item.latest_evaluation.verdict } : null
      })).sort((left, right) => left.id - right.id)
    })
  }
  function revocationIntent(): string {
    const skill = detail.value?.skill
    return JSON.stringify(skill ? { id: skill.id, code: skill.code, state: skill.state,
      status: skill.status, active_version_id: skill.active_version_id } : null)
  }
  async function publish(): Promise<void> {
    const environment = environmentId.value
    const skill = detail.value?.skill
    const version = draftVersion.value
    if (!environment || !skill || !version || !canPublishCases.value || busy.value) return
    const flight = Symbol('skill-publish')
    operationFlight.value = { owner: flight, operation: 'publish' }
    try {
      const current = generation
      const actor = userStore.info.id
      const originalIntent = publicationIntent()
      const confirmIntent = () => {
        if (current !== generation || actor !== userStore.info.id || environmentId.value !== environment
          || detail.value?.skill.id !== skill.id || draftVersion.value?.id !== version.id
          || !canPublishCases.value || publicationIntent() !== originalIntent)
          throw new Error('原发布版本或案例判定已变化，请重新读取并明确确认；本次不发送')
      }
      try { await ElMessageBox.confirm(`发布 ${skill.code} 第 ${version.version} 版；具备 SandIAM 授权的应用才可运行此固定版本。`, '发布 Skill') }
      catch { return }
      if (current !== generation || environmentId.value !== environment || detail.value?.skill.id !== skill.id
        || draftVersion.value?.id !== version.id || !canPublishCases.value || busyState.value) return
      confirmIntent()
      busyState.value = 'publish'
      error.value = ''
      try {
        const result = await skillApi.publish(environment, skill.id,
          await keyFor('publish', { environment, actor, skillId: skill.id, version: version.version, confirmation: originalIntent },
            { skillId: skill.id, code: skill.code, version: version.version, versionId: version.id }, confirmIntent), version.version)
        if (current !== generation) return
        if (result.skill_id !== skill.id || result.code !== skill.code || result.version_id !== version.id
          || result.version !== version.version || result.state !== 'published')
          throw new Error('发布结果与原固定版本不一致，原请求号仍保留')
        settlePending()
        ElMessage.success(`第 ${result.version} 版已发布`)
        busyState.value = ''
        await loadSkills(1, flight)
        if (current !== generation) return
        await readSkill(skill.id, flight)
      } catch (cause) {
        if (current === generation) showError(cause)
      } finally {
        if (current === generation) busyState.value = ''
      }
    } catch (cause) {
      showError(cause)
    } finally {
      if (operationFlight.value?.owner === flight) operationFlight.value = null
    }
  }

  async function revoke(): Promise<void> {
    const environment = environmentId.value
    const skill = detail.value?.skill
    if (!environment || !skill || busy.value) return
    const flight = Symbol('skill-revoke')
    operationFlight.value = { owner: flight, operation: 'revoke' }
    try {
      const current = generation
      const actor = userStore.info.id
      const originalIntent = revocationIntent()
      const confirmIntent = () => {
        if (current !== generation || actor !== userStore.info.id || environmentId.value !== environment
          || revocationIntent() !== originalIntent)
          throw new Error('原撤销目标已变化，请重新读取并明确确认；本次不发送')
      }
      try { await ElMessageBox.confirm('撤销后新调用将无法运行此 Skill。', '撤销 Skill') }
      catch { return }
      if (current !== generation || environmentId.value !== environment) return
      confirmIntent()
      busyState.value = 'revoke'
      error.value = ''
      try {
        const result = await skillApi.revoke(environment, skill.id,
          await keyFor('revoke', { environment, actor, skillId: skill.id, confirmation: originalIntent },
            { skillId: skill.id, code: skill.code }, confirmIntent))
        if (current !== generation) return
        if (result.skill_id !== skill.id || result.code !== skill.code || result.state !== 'revoked')
          throw new Error('撤销结果与原目标不一致，原请求号仍保留')
        settlePending()
        ElMessage.success('Skill 已撤销')
        busyState.value = ''
        await loadSkills(1, flight)
        if (current !== generation) return
        await readSkill(skill.id, flight)
      } catch (cause) {
        if (current === generation) showError(cause)
      } finally {
        if (current === generation) busyState.value = ''
      }
    } catch (cause) {
      showError(cause)
    } finally {
      if (operationFlight.value?.owner === flight) operationFlight.value = null
    }
  }

  onMounted(() => {
    runtimeApiKey.value = readRuntimeBearer()
    const actor = userStore.info.id
    if (typeof actor === 'number' && Number.isSafeInteger(actor) && actor > 0) void loadEnvironments(true)
  })
  onUnmounted(() => { live = false; ++generation; clearTestCost(); clearRuntime() })
  watch(runtimeApiKey, () => {
    clearRuntime()
    ++runtimeScopeGeneration
    runtimeStorageScope.value = ''
    void loadRuntimeStorageScope(runtimeApiKey.value.trim(), runtimeScopeGeneration)
  }, { flush: 'sync' })
  watch(() => [userStore.info.id, userStore.accessToken], changeAdministrator, { flush: 'sync' })
</script>

<style scoped lang="scss">
  .skill-card { margin-bottom: 16px; }
  .skill-header, .skill-section-title { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
  .skill-header h2, .skill-section-title h3 { margin: 0; }
  .skill-header p, .skill-hint { color: var(--el-text-color-secondary); font-size: 13px; }
  .skill-section-title { margin: 24px 0 12px; }
  .skill-pagination, .skill-actions { margin-top: 16px; }
  .skill-result { max-height: 360px; overflow: auto; padding: 12px; background: var(--el-fill-color-light); }
</style>
