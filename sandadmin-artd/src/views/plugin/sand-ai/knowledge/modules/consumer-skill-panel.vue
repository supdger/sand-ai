<template>
  <ElCard class="consumer-skill-card" shadow="never">
    <template #header>
      <h2>应用方法包 · 消费范围</h2>
      <p>使用消费后端取得的工作负载凭证，查看和管理该消费范围的方法包。后台登录不提供消费身份。</p>
    </template>
    <ElAlert
      type="info"
      :closable="false"
      title="范围与操作者是消费应用声明（consumer_declared），不代表平台已验证个人或团队成员。真实人员及成员关系须由已授权消费应用核实并传入，手填引用不能证明认证；组织引用由服务端核对。"
    />
    <ElAlert v-if="error" type="error" :title="error" closable @close="error = ''" />
    <ElForm label-width="110px" @submit.prevent>
      <RuntimeApiKeyField
        v-model="bearer"
        :persist-in-session="false"
        placeholder="由消费后端取得的 sand-ai 工作负载 Bearer，仅保留在本页"
      />
      <ElFormItem label="消费范围">
        <ElSelect v-model="scopeType" placeholder="选择消费后端声明的范围">
          <ElOption label="个人" value="personal" /><ElOption label="团队" value="team" />
          <ElOption label="组织" value="organization" />
        </ElSelect>
      </ElFormItem>
      <ElFormItem label="范围引用"
        ><ElInput v-model="scopeRef" placeholder="消费后端提供的 scope.ref"
      /></ElFormItem>
      <ElFormItem label="操作者引用"
        ><ElInput v-model="actorRef" placeholder="消费后端提供的 actor_ref"
      /></ElFormItem>
      <ElFormItem
        ><ElButton :disabled="!ready || busy" :loading="busy" @click="loadWorkspace(1)"
          >读取消费范围</ElButton
        ></ElFormItem
      >
    </ElForm>
    <template v-if="loaded">
      <p v-if="managementReceipt" role="status">
        原{{ managementLabel(managementReceipt.operation) }}回执已读取：{{ managementReceipt.code }} · 固定版本 {{ managementReceipt.version }}
        <span v-if="'case_id' in managementReceipt"> · 案例 #{{ managementReceipt.case_id }}</span>
        <span v-if="'verdict' in managementReceipt"> · 原判定 {{ managementReceipt.verdict === 'pass' ? '通过' : '未通过' }}</span>
        · 原请求 {{ managementReceipt.request_id }}。当前列表和判定仍以重新读取为准。
      </p>
      <ElAlert
        v-if="managementPending"
        type="warning"
        :closable="false"
        title="有一项管理请求尚未确认。请按原请求号只读找回；当前范围的其他写操作暂不可发送。"
      />
      <ElForm v-if="managementRecoverable" label-width="110px" @submit.prevent>
        <ElFormItem label="原管理操作">{{ managementLabel(managementPending?.operation) }}</ElFormItem>
        <ElFormItem label="原请求号"><ElInput :model-value="managementPending?.key" readonly /></ElFormItem>
        <template v-if="managementPending?.recovery_target">
          <ElFormItem v-if="managementPending.recovery_target.operation === 'source_adopt'" label="原建议号">#{{ managementPending.recovery_target.source_suggestion_id }}</ElFormItem>
          <template v-else>
            <ElFormItem label="原方法包">{{ managementPending.recovery_target.code }}</ElFormItem>
            <ElFormItem v-if="'version' in managementPending.recovery_target" label="原固定版本">{{ managementPending.recovery_target.version }}</ElFormItem>
            <ElFormItem v-if="'case_id' in managementPending.recovery_target" label="原案例">#{{ managementPending.recovery_target.case_id }}</ElFormItem>
          </template>
        </template>
        <template v-else-if="managementPending?.lifecycle">
          <ElFormItem v-if="managementPending.lifecycle.operation === 'purge-suggestion'" label="原建议号">#{{ managementPending.lifecycle.suggestion_id }}</ElFormItem>
          <ElFormItem v-else label="原方法包">{{ managementPending.lifecycle.code }}<span v-if="managementPending.lifecycle.operation === 'publish'"> · 固定版本 {{ managementPending.lifecycle.version }}</span></ElFormItem>
        </template>
        <template v-else>
          <p>旧记录未保存完整目标，请填写原目标，只读核对服务端原记录。不会以当前列表状态或返回摘要补造遗失的原意图。</p>
          <ElFormItem v-if="managementPending?.operation === 'adopt'" label="原建议号"><ElInputNumber v-model="legacyManagementSuggestionId" :min="1" :disabled="busy" /></ElFormItem>
          <template v-else>
            <ElFormItem label="原方法包代码"><ElInput v-model="legacyManagementCode" :disabled="busy" /></ElFormItem>
            <ElFormItem v-if="managementPending?.operation !== 'revoke'" label="原固定版本"><ElInputNumber v-model="legacyManagementVersion" :min="1" :disabled="busy" /></ElFormItem>
            <ElFormItem v-if="managementPending?.operation === 'review' || managementPending?.operation === 'retire'" label="原案例号"><ElInputNumber v-model="legacyManagementCaseId" :min="1" :disabled="busy" /></ElFormItem>
          </template>
        </template>
        <p>这里只读取原操作回执，不再发布、回退、撤销、评审、停用或采用。不存在、拒绝或网络错误均保留原号；原回执不表示当前活动版本或案例判定仍未变化。</p>
        <ElButton :disabled="busy || !ready || !workloadIdentity" @click="resumeManagement">只读找回原管理回执</ElButton>
      </ElForm>
      <ElAlert v-if="managementPending?.operation === 'purge-suggestion'" type="warning" :closable="false"
        title="原清理请求结果仍未确认" description="当前没有该清理操作的原回执读取接口，保留原请求锁。建议状态或内容已清理不等于原清理回执，不重新 DELETE 或换号。" />
      <ElForm v-if="managementSavePending" label-width="110px" @submit.prevent>
        <ElFormItem label="原保存操作">{{ managementPending?.operation === 'draft' ? '保存草稿' : '保存案例' }}</ElFormItem>
        <ElFormItem label="原请求号"><ElInput :model-value="managementPending?.key" readonly /></ElFormItem>
        <ElFormItem v-if="managementPending?.receipt?.operation === 'case'" label="原固定目标">
          {{ managementPending.receipt.code }} · 版本 {{ managementPending.receipt.version }}
        </ElFormItem>
        <ElFormItem v-else-if="managementPending?.draft_code" label="原方法包">{{ managementPending.draft_code }}</ElFormItem>
        <p v-if="managementPending?.operation === 'draft' || managementPending?.receipt">读取原保存结果不会再次发送保存请求。未找到、无权、状态变化或网络错误时仍保留原请求号。</p>
        <p v-else>旧案例记录缺少固定目标，暂不能只读确认。请保留原请求记录，不重填私有内容再次 POST 或换号。</p>
        <ElButton :disabled="busy || !ready || !workloadIdentity || (managementPending?.operation !== 'draft' && !managementPending?.receipt)" @click="recoverSavedManagement">找回原保存结果</ElButton>
      </ElForm>
      <ElTable :data="rows" row-key="id" :empty-text="page > 1 ? '本页没有更多方法包，可返回上一页' : '这个消费范围尚无方法包'">
        <ElTableColumn label="方法包" prop="name" /><ElTableColumn label="代码" prop="code" />
        <ElTableColumn label="状态"
          ><template #default="{ row }">{{ stateLabel(row.state) }}</template></ElTableColumn
        >
        <ElTableColumn label="操作"
          ><template #default="{ row }">
            <ElButton link :disabled="busy" @click="readDetail(row.code)">查看版本与案例</ElButton>
            <ElButton
              v-if="row.state !== 'revoked'"
              link
              type="danger"
              :disabled="busy"
              @click="revokeCode(row.code)"
              >撤销</ElButton
            >
          </template></ElTableColumn
        >
      </ElTable>
      <ElSpace v-if="page > 1 || nextCursor !== null">
        <ElButton :disabled="busy || page <= 1" @click="loadWorkspace(page - 1)">上一页</ElButton>
        <span>第 {{ page }} 页</span>
        <ElButton :disabled="busy || nextCursor === null" @click="loadWorkspace(page + 1)">查看下一页</ElButton>
      </ElSpace>
      <SkillSourceSuggestions v-model:recovery-key="suggestionKey" :sources="sources"
        :ready="ready && Boolean(workloadIdentity)" :busy="busy" :blocked="Boolean(managementPending)"
        :result="suggestion" :reset-epoch="sourceResetEpoch" @generate="generateSuggestion"
        @recover="recoverSuggestion" @prepare="prepareSuggestedDraft" @adopt="adoptSuggestion"
        @purge="purgeSuggestion" @cost="readSuggestionCost" />
      <h3>保存新草稿版本</h3>
      <p>
        已保存版本不可原地改写。修改已有草稿会保存为下一版，旧版和评测记录保留只读；
        新版需要重新建立案例、测试和判定。保存不会测试或发布。
      </p>
      <ElAlert
        v-if="draftTargetBlocked"
        type="info"
        :closable="false"
        title="这个代码已有草稿或已撤销。已有草稿请先查看版本，选择“修改此草稿并保存为新版本”；已撤销方法包不能修改。"
      />
      <p v-if="draftReplacement">
        修改版本
        {{ draftReplacement.expected_draft_version }}，保存后旧草稿将只读。
      </p>
      <ElForm label-width="110px" @submit.prevent>
        <ElFormItem label="名称"><ElInput v-model="draft.name" maxlength="100" /></ElFormItem>
        <ElFormItem label="代码"
          ><ElInput v-model="draft.code" placeholder="新方法包可留空；创建下一版使用已有代码"
        /></ElFormItem>
        <ElFormItem label="用途"
          ><ElInput v-model="draft.description" maxlength="1000"
        /></ElFormItem>
        <ElFormItem label="知识版本">
          <ElSelect v-model="draft.sourceId" placeholder="选择本消费环境已开放版本">
            <ElOption
              v-for="source in sources"
              :key="source.knowledge_base_version_id"
              :value="source.knowledge_base_version_id"
              :label="`${source.name} · 版本 ${source.version}`"
            />
          </ElSelect>
          <ElButton v-if="sourceCursor !== null" :disabled="busy" text @click="moreSources"
            >加载更多资料</ElButton
          >
        </ElFormItem>
        <ElFormItem label="指令"
          ><ElInput v-model="draft.instruction" type="textarea" :rows="4"
        /></ElFormItem>
        <ElFormItem label="输入结构 JSON"
          ><ElInput v-model="draft.inputSchema" type="textarea" :rows="3"
        /></ElFormItem>
        <ElFormItem label="输出结构 JSON"
          ><ElInput v-model="draft.outputSchema" type="textarea" :rows="3"
        /></ElFormItem>
        <ElFormItem label="需要复核"><ElSwitch v-model="draft.reviewRequired" /></ElFormItem>
        <ElFormItem
          ><ElButton :disabled="busy || !ready || draftTargetBlocked" @click="saveDraft">{{
            managementPending?.operation === 'draft'
              ? '恢复原草稿管理请求'
              : draftReplacement
                ? '保存修改为下一版'
                : '保存草稿'
          }}</ElButton></ElFormItem
        >
      </ElForm>
    </template>
    <template v-if="detail">
      <h3>
        {{ detail.skill.name }} · {{ detail.skill.code }} ·
        {{ stateLabel(detail.skill.state) }}
      </h3>
      <p>
        归属：{{ detail.skill.scope.type }} /
        {{ detail.skill.scope.ref }}（消费应用声明）。版本内容只读。
      </p>
      <ElSelect v-model="selectedVersion" :disabled="busy" @change="loadCases">
        <ElOption
          v-for="item in detail.versions"
          :key="item.id"
          :value="item.version"
          :label="`版本 ${item.version} · ${stateLabel(item.state)}`"
        />
      </ElSelect>
      <template v-if="version">
        <ElDescriptions :column="1" border>
          <ElDescriptionsItem label="固定知识版本">{{
            version.knowledge_base_version_id
          }}</ElDescriptionsItem>
          <ElDescriptionsItem label="指令">{{ version.instruction }}</ElDescriptionsItem>
          <ElDescriptionsItem label="输入结构">
            <pre>{{ JSON.stringify(version.input_schema, null, 2) }}</pre>
          </ElDescriptionsItem>
          <ElDescriptionsItem label="输出结构">
            <pre>{{ JSON.stringify(version.output_schema, null, 2) }}</pre>
          </ElDescriptionsItem>
          <ElDescriptionsItem label="复核要求">{{
            version.review_policy.review_required ? '需要复核' : '按业务规则核对'
          }}</ElDescriptionsItem>
        </ElDescriptions>
        <ElButton
          v-if="detail.skill.state !== 'revoked' && version.state !== 'abandoned'"
          :disabled="
            busy ||
            (version.state !== 'draft' && detail.versions.some((item) => item.state === 'draft'))
          "
          @click="copyDraft"
          >{{
            version.state === 'draft' ? '修改此草稿并保存为新版本' : '以此版本准备下一版草稿'
          }}</ElButton
        >
        <p v-if="version.state === 'abandoned'">
          这个草稿已被新版本替换。内容、案例与既有评测保留只读，不用于测试或发布。
        </p>
        <h3>版本 {{ version.version }} 案例与最新判定</h3>
        <ElButton :disabled="busy" text @click="loadCases">刷新本版本案例</ElButton>
        <ElTable :data="cases" row-key="id" empty-text="本版本尚无案例">
          <ElTableColumn label="案例" prop="name" /><ElTableColumn
            label="判定依据"
            prop="expected_criteria"
          />
          <ElTableColumn label="最新判定"
            ><template #default="{ row }">
              {{
                row.state === 'retired'
                  ? '已废弃'
                  : row.latest_evaluation?.verdict === 'pass'
                    ? '通过'
                    : row.latest_evaluation?.verdict === 'fail'
                      ? '未通过'
                      : '尚未判定'
              }}
            </template></ElTableColumn
          >
          <ElTableColumn label="判定说明"
            ><template #default="{ row }">{{
              row.latest_evaluation?.note ?? row.retire_reason ?? '—'
            }}</template></ElTableColumn
          >
          <ElTableColumn label="操作"
            ><template #default="{ row }">
              <ElButton link :disabled="busy" @click="selectCase(row)">查看输入</ElButton>
              <ElButton
                v-if="editable && row.state === 'active'"
                link
                :disabled="busy"
                @click="retire(row)"
                >废弃</ElButton
              >
            </template></ElTableColumn
          >
        </ElTable>
        <ElForm v-if="editable" label-width="110px" @submit.prevent>
          <ElFormItem label="新案例名称"><ElInput v-model="caseName" maxlength="100" /></ElFormItem>
          <ElFormItem label="案例输入 JSON"
            ><ElInput v-model="caseInput" type="textarea" :rows="3"
          /></ElFormItem>
          <ElFormItem label="判定依据"
            ><ElInput v-model="criteria" type="textarea" :rows="2" maxlength="1000"
          /></ElFormItem>
          <ElFormItem
            ><ElButton :disabled="busy" @click="createCase">加入案例</ElButton></ElFormItem
          >
        </ElForm>
        <h3>
          {{ activeCaseId ? `案例 #${activeCaseId} 的输入` : '本次输入' }}
        </h3>
        <ElInput v-model="input" type="textarea" :rows="3" :readonly="activeCaseId !== null" />
        <ElButton v-if="activeCaseId" :disabled="busy" text @click="useSingleInput"
          >改用单次输入</ElButton
        >
        <p>测试和固定版本运行都会调用模型并单独计量；结果不替代案例判定或业务复核。</p>
        <ElSpace wrap>
          <ElButton
            v-if="editable"
            :disabled="busy || Boolean(pending) || Boolean(managementPending)"
            @click="send('test')"
            >测试草稿（计费）</ElButton
          >
          <ElButton
            v-if="runnable"
            :disabled="busy || Boolean(pending) || Boolean(managementPending)"
            @click="send('run')"
            >运行固定版本 {{ version.version }}（计费）</ElButton
          >
          <ElButton v-if="editable" :disabled="busy || !publishable" @click="publish"
            >明确发布本版本</ElButton
          >
          <ElButton v-if="runnable" :disabled="busy" @click="rollback">设为活动版本</ElButton>
          <ElButton
            v-if="detail.skill.state !== 'revoked'"
            type="danger"
            :disabled="busy"
            @click="revoke"
            >撤销方法包</ElButton
          >
        </ElSpace>
        <p v-if="editable && !publishable">
          发布需要至少一个有效案例，全部有效案例的最新判定通过；最终仍由服务端检查。
        </p>
      </template>
    </template>
    <h3>找回原测试或运行</h3>
    <p>使用原请求键、原固定版本及当前正式上下文。可独立读取其他历史调用；找回只读，不重发模型请求。</p>
    <ElForm label-width="110px" @submit.prevent>
      <ElFormItem label="代码"
        ><ElInput v-model="recovery.code" :disabled="runReadBusy"
      /></ElFormItem>
      <ElFormItem label="固定版本"
        ><ElInputNumber v-model="recovery.version" :min="1" :disabled="runReadBusy"
      /></ElFormItem>
      <ElFormItem label="原调用类型"
        ><ElSelect v-model="recovery.kind" :disabled="runReadBusy">
          <ElOption value="test" label="草稿测试" /><ElOption
            value="run"
            label="固定版本运行"
          /> </ElSelect
      ></ElFormItem>
      <ElFormItem label="原请求键"
        ><ElInput v-model="recovery.key" :disabled="runReadBusy"
      /></ElFormItem>
      <ElFormItem
        ><ElButton :disabled="runReadBusy || !readerReady" :loading="runReadBusy" @click="recover">只读找回原调用</ElButton></ElFormItem
      >
    </ElForm>
    <ElAlert
      v-if="pending"
      type="warning"
      :closable="false"
      title="本次意图尚未确认：保留原键，先找回；不要换键重复发送。"
    />
    <template v-if="pending">
      <p>
        待确认：{{ pending.code }} · 固定版本 {{ pending.version }} ·
        {{ pending.kind === 'test' ? '草稿测试' : '固定版本运行' }} · 原请求键 {{ pending.key }}。
        读取其他历史调用不会解除这次意图的锁定。
      </p>
      <ElButton :disabled="runReadBusy || !readerReady" :loading="runReadBusy" @click="recoverPendingRun">只读找回这次待确认调用</ElButton>
    </template>
    <ElButton
      v-if="
        pending?.input_hash &&
        sameLocator(visibleResultLocator, pending) &&
        visibleResult?.state === 'failed' &&
        visibleResult.error_code !== 'SAND_AI_DISPATCH_AMBIGUOUS'
      "
      :disabled="busy || !canUseVisibleResult || Boolean(managementPending)"
      @click="retryFailed"
      >以原键和原输入恢复失败意图</ElButton
    >
    <p v-if="pending && pending.input_hash === null">
      本页没有原输入的保存指纹，仅提供只读找回。显式恢复执行须由持有原输入与原键的消费后端完成。
    </p>
    <ElAlert v-if="runReadError" type="warning" :title="runReadError" :closable="false" />
    <p v-if="runReadBusy">正在按原固定目标读取调用结果；不会再次发送模型请求。</p>
    <template v-if="visibleResult">
      <p v-if="visibleResultLocator">
        当前结果：{{ visibleResultLocator.code }} · 固定版本 {{ visibleResultLocator.version }} ·
        {{ visibleResultLocator.kind === 'test' ? '草稿测试' : '固定版本运行' }} ·
        原请求键 {{ visibleResultLocator.key }}
      </p>
      <p>
        运行 #{{ visibleResult.run_id }} · {{ visibleResult.state }} ·
        {{ visibleResult.review_required ? '结果需要复核' : '请按业务规则核对' }}
      </p>
      <ElAlert
        v-if="visibleResult.error_code"
        type="warning"
        :title="visibleResult.error_code"
        :closable="false"
      />
      <pre>{{ JSON.stringify(visibleResult.output, null, 2) }}</pre>
      <ElTable :data="visibleResult.citations" empty-text="本次没有可读来源">
        <ElTableColumn label="来源块" prop="source_block_id" />
        <ElTableColumn label="定位"
          ><template #default="{ row }"
            >{{ row.locator_type }} {{ JSON.stringify(row.locator) }}</template
          ></ElTableColumn
        >
        <ElTableColumn label="来源摘录" prop="excerpt" />
      </ElTable>
      <ElForm v-if="canReview" label-width="110px" @submit.prevent>
        <ElFormItem label="逐项判定说明"
          ><ElInput v-model="reviewNote" type="textarea" :rows="2" maxlength="1000"
        /></ElFormItem>
        <ElFormItem
          ><ElSpace>
            <ElButton :disabled="busy || !reviewNote.trim()" @click="review('pass')"
              >明确判定通过</ElButton
            >
            <ElButton :disabled="busy || !reviewNote.trim()" @click="review('fail')"
              >明确判定未通过</ElButton
            >
          </ElSpace></ElFormItem
        >
      </ElForm>
    </template>
    <h3>原调用费用</h3>
    <p>
      用原请求键读取当前范围内的测试或运行费用，无需先找回成果。
      发布、版本废弃或撤销后仍可读取；需要独立费用权限，未知金额不代表免费。
    </p>
    <ElForm label-width="110px" @submit.prevent>
      <ElFormItem label="原 Skill 代码"><ElInput v-model="costLocator.code" /></ElFormItem>
      <ElFormItem label="原固定版本"><ElInputNumber v-model="costLocator.version" :min="1" /></ElFormItem>
      <ElFormItem label="原调用类型"><ElSelect v-model="costLocator.kind">
        <ElOption label="草稿测试" value="test" /><ElOption label="固定版本运行" value="run" />
      </ElSelect></ElFormItem>
      <ElFormItem label="原请求键"><ElInput v-model="costLocator.key" placeholder="原 Idempotency-Key / request_id" /></ElFormItem>
      <ElFormItem><ElButton :disabled="costReadBusy || !readerReady || !costLocator.code.trim() || !costLocator.key.trim()" :loading="costReadBusy" @click="readCost">
        只读原调用费用
      </ElButton></ElFormItem>
    </ElForm>
    <ElAlert v-if="costError" type="warning" :title="costError" :closable="false" />
    <InvocationCostDetails v-if="cost" :cost="cost" />
  </ElCard>
</template>

<script setup lang="ts">
  import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue'
  import { ElMessageBox } from 'element-plus'
  import { useUserStore } from '@/store/modules/user'
  import api from '../../api/consumerSkill'
  import { SandAiRuntimeError } from '../../api/runtime'
  import type {
    SkillConsumerDeclaration,
    ConsumerSkillDetail,
    ConsumerSkillDraft,
    SkillWorkloadIdentity,
    SkillDraftReplacement,
    SkillSuggestionInput,
    SkillSuggestionRead,
    SkillMethodSuggestion,
    ConsumerSkillReceiptTarget,
    ConsumerSkillOperationReceipt
  } from '../../api/consumerSkill'
  import type { SkillRow, SkillSource, SkillCaseRow, SkillTestResult } from '../../api/skill'
  import type { SandAiInvocationCost } from '../../api/types'
  import SkillSourceSuggestions from './skill-source-suggestions.vue'
  import RuntimeApiKeyField from '../../components/RuntimeApiKeyField.vue'
  import InvocationCostDetails from '../../usage/modules/invocation-cost-details.vue'

  const userStore = useUserStore()
  const bearer = ref('')
  const scopeType = ref<SkillConsumerDeclaration['scope']['type'] | ''>('')
  const scopeRef = ref('')
  const actorRef = ref('')
  const busyState = ref(false)
  const operationFlight = ref<symbol | null>(null)
  const busy = computed(() => busyState.value || operationFlight.value !== null)
  const error = ref('')
  const loaded = ref(false)
  const rows = ref<SkillRow[]>([])
  const nextCursor = ref<number | null>(null)
  const pageCursors = ref<Array<number | undefined>>([undefined])
  const page = ref(1)
  const sources = ref<SkillSource[]>([])
  const sourcePage = ref(1)
  const sourceCursor = ref<number | null>(null)
  const detail = ref<ConsumerSkillDetail | null>(null)
  const workloadIdentity = ref<SkillWorkloadIdentity | null>(null)
  const selectedVersion = ref(1)
  const version = computed(() =>
    detail.value?.versions.find((item) => item.version === selectedVersion.value)
  )
  const editable = computed(
    () => version.value?.state === 'draft' && detail.value?.skill.state !== 'revoked'
  )
  const runnable = computed(
    () => version.value?.state === 'published' && detail.value?.skill.state !== 'revoked'
  )
  const cases = ref<SkillCaseRow[]>([])
  const activeCaseId = ref<number | null>(null)
  const caseName = ref('')
  const caseInput = ref('{}')
  const criteria = ref('')
  const input = ref('{}')
  const reviewNote = ref('')
  type VersionForm = {
    caseName: string
    caseInput: string
    criteria: string
    input: string
  }
  // Form bodies stay in memory and belong to one fixed version within this scope.
  const versionForms = new Map<string, VersionForm>()
  let formTarget = ''
  const result = ref<SkillTestResult | null>(null)
  const cost = ref<SandAiInvocationCost | null>(null)
  const costError = ref('')
  const runReadBusy = ref(false)
  const costReadBusy = ref(false)
  const runReadError = ref('')
  const runReadResult = ref<SkillTestResult | null>(null)
  const runReadLocator = ref<RunLocator | null>(null)
  const runReadSelected = ref(false)
  const visibleResult = computed(() => runReadSelected.value ? runReadResult.value : result.value)
  const visibleResultLocator = computed(() => runReadSelected.value ? runReadLocator.value : resultLocator.value)
  let runReadSequence = 0
  let costReadSequence = 0
  let readerContextEpoch = 0
  let writerSequence = 0
  let writerCompletionSequence = 0
  let runReadWriterSequence = -1
  let runReadStartedIdle = false
  let readersLive = true
  const canUseVisibleResult = computed(() => !runReadSelected.value ||
    (!busyState.value && !runReadBusy.value && runReadStartedIdle && runReadWriterSequence === writerSequence))
  const readerReady = computed(() => ready.value && workloadIdentity.value !== null)

  const suggestionKey = ref('')
  const suggestion = ref<SkillSuggestionRead | null>(null)
  const sourceResetEpoch = ref(0)
  const publishable = computed(() => {
    const active = cases.value.filter((item) => item.state === 'active')
    return editable.value && active.length > 0 && active.every((item) =>
      item.skill_version_id === version.value?.id && item.latest_evaluation?.verdict === 'pass')
  })
  const canReview = computed(
    () =>
      editable.value && canUseVisibleResult.value &&
      activeCaseId.value !== null &&
      visibleResult.value?.state === 'succeeded' &&
      visibleResultLocator.value?.kind === 'test' &&
      visibleResultLocator.value.code === detail.value?.skill.code &&
      visibleResultLocator.value.version === selectedVersion.value
  )
  const defaultObjectSchema = JSON.stringify({ type: 'object', additionalProperties: true }, null, 2)
  const draft = reactive({
    name: '',
    code: '',
    description: '',
    instruction: '',
    sourceId: undefined as number | undefined,
    inputSchema: defaultObjectSchema,
    outputSchema: defaultObjectSchema,
    reviewRequired: true
  })
  const revisionTarget = ref<{ code: string; version: number } | null>(null)
  const draftReplacement = computed<SkillDraftReplacement | undefined>(() => {
    const target = revisionTarget.value
    return target && target.code === draft.code.trim()
      ? { replace_draft: true, expected_draft_version: target.version }
      : undefined
  })
  const draftTargetBlocked = computed(() => {
    const code = draft.code.trim()
    if (!code) return false
    if (
      (detail.value?.skill.code === code && detail.value.skill.state === 'revoked') ||
      rows.value.some((item) => item.code === code && item.state === 'revoked')
    )
      return true
    // The persisted intent hash still must match; this only permits its explicit ledger readback.
    if (managementPending.value?.operation === 'draft') return false
    if (detail.value?.skill.code === code) {
      return (
        detail.value.skill.state === 'revoked' ||
        (detail.value.versions.some((item) => item.state === 'draft') && !draftReplacement.value)
      )
    }
    return rows.value.some(
      (item) =>
        item.code === code &&
        (item.state === 'revoked' || (item.draft_version !== null && !draftReplacement.value))
    )
  })
  type RunLocator = {
    code: string
    version: number
    kind: 'test' | 'run'
    key: string
  }
  type Pending = RunLocator & { input_hash: string | null }
  const resultLocator = ref<RunLocator | null>(null)
  type ManagementLifecycle = { operation: 'publish'; code: string; version: number }
    | { operation: 'revoke'; code: string }
    | { operation: 'purge-suggestion'; suggestion_id: number }
  type ManagementReceipt = { operation: 'draft' }
    | { operation: 'case'; code: string; version: number }
  type ManagementPending = {
    operation: string
    intent_hash: string
    key: string
    draft_code?: string
    draft_base_version?: number | null
    lifecycle?: ManagementLifecycle
    receipt?: ManagementReceipt
    recovery_target?: ConsumerSkillReceiptTarget
    input_sha256?: string
  }
  const managementPending = ref<ManagementPending | null>(null)
  const managementReceipt = ref<ConsumerSkillOperationReceipt | null>(null)
  const managementRecoverable = computed(() => !!managementPending.value
    && ['publish', 'rollback', 'revoke', 'review', 'retire', 'adopt'].includes(managementPending.value.operation))
  const managementSavePending = computed(() => !!managementPending.value
    && ['draft', 'case'].includes(managementPending.value.operation))
  const legacyManagementCaseId = ref<number | undefined>()
  const legacyManagementCode = ref('')
  const legacyManagementVersion = ref<number>()
  const legacyManagementSuggestionId = ref<number>()
  const recovery = reactive<RunLocator>({
    code: '',
    version: 1,
    kind: 'run',
    key: ''
  })
  const costLocator = reactive<RunLocator>({ code: '', version: 1, kind: 'run', key: '' })
  const pending = ref<Pending | null>(null)
  const ready = computed(() =>
    Boolean(
      bearer.value.trim() && scopeType.value && scopeRef.value.trim() && actorRef.value.trim()
    )
  )
  let generation = 0
  let storageScope = ''
  let managementScope = ''

  function stateLabel(state: string): string {
    return (
      (
        {
          draft: '草稿',
          published: '已发布',
          revoked: '已撤销',
          abandoned: '已被替换'
        } as Record<string, string>
      )[state] ?? state
    )
  }
  function object(text: string): Record<string, unknown> {
    const value: unknown = JSON.parse(text)
    if (typeof value !== 'object' || value === null || Array.isArray(value))
      throw new Error('请输入 JSON 对象')
    return value as Record<string, unknown>
  }
  function context(): {
    token: string
    declaration: SkillConsumerDeclaration
    identity: SkillWorkloadIdentity | null
    epoch: number
    operationKeys: string[]
    freshOperationKeys: string[]
  } {
    if (!ready.value || scopeType.value === '')
      throw new Error('请填写消费后端提供的凭证、范围和操作者声明')
    for (const value of [scopeRef.value, actorRef.value]) {
      if (new TextEncoder().encode(value).length > 128 || /[\u0000-\u001f\u007f]/.test(value))
        throw new Error('声明引用须为不含控制字符的 1–128 字节文本')
    }
    return {
      token: bearer.value.trim(),
      declaration: {
        scope: { type: scopeType.value, ref: scopeRef.value },
        actor_ref: actorRef.value
      },
      identity: workloadIdentity.value ? { ...workloadIdentity.value } : null,
      epoch: generation,
      operationKeys: [],
      freshOperationKeys: []
    }
  }
  function assertCurrent(c: ReturnType<typeof context>): void {
    if (c.epoch !== generation) throw new Error('消费上下文或目标已改变')
  }
  function clearRunReader(): void {
    ++runReadSequence
    runReadBusy.value = false
    runReadError.value = ''
    runReadResult.value = null
    runReadLocator.value = null
    runReadSelected.value = false
    runReadStartedIdle = false
    runReadWriterSequence = -1
  }
  function clearCostReader(): void {
    ++costReadSequence
    costReadBusy.value = false
    cost.value = null
    costError.value = ''
  }
  function readerContext() {
    const c = context()
    if (!c.identity) throw new Error('请先读取当前消费工作区，确认工作负载范围')
    const actor = adminIdentity()
    const adminCredential = userStore.accessToken
    const ownerEpoch = readerContextEpoch
    const scope = storageScope
    const isCurrent = () => readersLive && ownerEpoch === readerContextEpoch
      && c.epoch === generation && c.token === bearer.value.trim()
      && scope === storageScope && String(userStore.info.id) === actor
      && userStore.accessToken === adminCredential
      && scopeType.value === c.declaration.scope.type && scopeRef.value === c.declaration.scope.ref
      && actorRef.value === c.declaration.actor_ref
      && canonical(workloadIdentity.value) === canonical(c.identity)
    return { c, scope, isCurrent }
  }
  async function digest(value: string): Promise<string> {
    const hash = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(value))
    return Array.from(new Uint8Array(hash), (byte) => byte.toString(16).padStart(2, '0')).join('')
  }
  function adminIdentity(): string {
    const value = userStore.info.id
    if (
      (typeof value !== 'number' || !Number.isSafeInteger(value) || value < 1) &&
      (typeof value !== 'string' || !/^[1-9][0-9]*$/.test(value))
    )
      throw new Error('请先以当前正式管理账号登录')
    return String(value)
  }
  function verifiedWorkload(value: unknown): SkillWorkloadIdentity {
    if (
      typeof value !== 'object' ||
      value === null ||
      Array.isArray(value) ||
      Object.keys(value).length !== 4 ||
      !('organization_id' in value) ||
      !('application_id' in value) ||
      !('environment_id' in value) ||
      !('workload_client_id' in value) ||
      typeof value.organization_id !== 'string' ||
      typeof value.application_id !== 'string' ||
      typeof value.environment_id !== 'string' ||
      typeof value.workload_client_id !== 'string' ||
      ![
        value.organization_id,
        value.application_id,
        value.environment_id,
        value.workload_client_id
      ].every((id) => /^[1-9][0-9]*$/.test(id))
    )
      throw new Error('消费工作区未返回有效的当前工作负载身份，请重新读取')
    return {
      organization_id: value.organization_id,
      application_id: value.application_id,
      environment_id: value.environment_id,
      workload_client_id: value.workload_client_id
    }
  }
  function savedPending(scope: string): Pending | null {
    const saved = sessionStorage.getItem(scope)
    if (!saved) return null
    const value: unknown = JSON.parse(saved)
    if (
      typeof value !== 'object' ||
      value === null ||
      !('code' in value) ||
      !('version' in value) ||
      !('kind' in value) ||
      !('key' in value) ||
      !('input_hash' in value) ||
      typeof value.code !== 'string' ||
      typeof value.version !== 'number' ||
      !Number.isSafeInteger(value.version) ||
      value.version < 1 ||
      (value.kind !== 'test' && value.kind !== 'run') ||
      typeof value.key !== 'string' ||
      (value.input_hash !== null &&
        (typeof value.input_hash !== 'string' || !/^[a-f0-9]{64}$/.test(value.input_hash)))
    )
      throw new Error('原请求键记录无效')
    return {
      code: value.code,
      version: value.version,
      kind: value.kind,
      key: value.key,
      input_hash: value.input_hash
    }
  }
  function lifecycleMetadata(value: unknown, operation: string): ManagementLifecycle {
    if (typeof value !== 'object' || value === null || Array.isArray(value)
      || !('operation' in value) || value.operation !== operation) throw new Error('原管理目标记录无效')
    if (value.operation === 'purge-suggestion' && 'suggestion_id' in value
      && typeof value.suggestion_id === 'number' && Number.isSafeInteger(value.suggestion_id) && value.suggestion_id > 0)
      return { operation: 'purge-suggestion', suggestion_id: value.suggestion_id }
    if (!('code' in value) || typeof value.code !== 'string' || !/^[a-z0-9][a-z0-9_-]{0,63}$/.test(value.code))
      throw new Error('原方法包目标记录无效')
    if (value.operation === 'revoke') return { operation: 'revoke', code: value.code }
    if (value.operation === 'publish' && 'version' in value && typeof value.version === 'number'
      && Number.isSafeInteger(value.version) && value.version > 0)
      return { operation: 'publish', code: value.code, version: value.version }
    throw new Error('原固定版本目标记录无效')
  }
  function receiptMetadata(value: unknown, operation: string): ManagementReceipt {
    if (typeof value !== 'object' || value === null || Array.isArray(value)
      || !('operation' in value) || value.operation !== operation)
      throw new Error('原保存目标记录无效')
    if (value.operation === 'draft') return { operation: 'draft' }
    if (value.operation === 'case' && 'code' in value && typeof value.code === 'string'
      && /^[a-z0-9][a-z0-9_-]{1,63}$/.test(value.code) && 'version' in value
      && typeof value.version === 'number' && Number.isSafeInteger(value.version) && value.version > 0)
      return { operation: 'case', code: value.code, version: value.version }
    throw new Error('原保存固定目标记录无效')
  }
  function operationTarget(value: unknown, operation: string): ConsumerSkillReceiptTarget {
    if (typeof value !== 'object' || value === null || Array.isArray(value)) throw new Error('原操作目标记录无效')
    const target = value as Record<string, unknown>
    const expected = operation === 'adopt' ? 'source_adopt' : operation
    if (target.operation !== expected) throw new Error('原操作类型与目标记录不一致')
    const positive = (item: unknown): item is number => typeof item === 'number' && Number.isSafeInteger(item) && item > 0
    if (target.operation === 'source_adopt' && positive(target.source_suggestion_id)) {
      if (target.code !== undefined && (typeof target.code !== 'string' || !/^[a-z0-9][a-z0-9_-]{1,63}$/.test(target.code)))
        throw new Error('原采用方法包代码无效')
      return { operation: 'source_adopt', source_suggestion_id: target.source_suggestion_id,
        ...(typeof target.code === 'string' ? { code: target.code } : {}) }
    }
    if (typeof target.code !== 'string' || !/^[a-z0-9][a-z0-9_-]{1,63}$/.test(target.code)) throw new Error('原方法包代码无效')
    if (target.operation === 'revoke') return { operation: 'revoke', code: target.code }
    if (!positive(target.version)) throw new Error('原固定版本无效')
    if (target.operation === 'publish' || target.operation === 'rollback')
      return { operation: target.operation, code: target.code, version: target.version }
    if (!positive(target.case_id)) throw new Error('原案例编号无效')
    if (target.operation === 'retire') return { operation: 'retire', code: target.code, version: target.version, case_id: target.case_id }
    if (target.operation === 'review') {
      if ((target.skill_run_id !== undefined && !positive(target.skill_run_id)) ||
        (target.verdict !== undefined && target.verdict !== 'pass' && target.verdict !== 'fail')) throw new Error('原评审目标无效')
      return { operation: 'review', code: target.code, version: target.version, case_id: target.case_id,
        ...(typeof target.skill_run_id === 'number' ? { skill_run_id: target.skill_run_id } : {}),
        ...(target.verdict === 'pass' || target.verdict === 'fail' ? { verdict: target.verdict } : {}) }
    }
    throw new Error('原操作目标记录无效')
  }
  function managementLabel(operation?: string): string {
    return ({ publish: '发布固定版本', rollback: '回退活动版本', revoke: '撤销方法包', review: '评审原案例',
      retire: '停用原案例', adopt: '采用来源建议', source_adopt: '采用来源建议', 'purge-suggestion': '清理提炼内容' } as Record<string, string>)[operation ?? ''] ?? '原管理操作'
  }
  function storedInputHash(value: unknown): string {
    if (typeof value !== 'string' || !/^[a-f0-9]{64}$/.test(value)) throw new Error('原上游输入摘要无效')
    return value
  }
  function savedManagement(scope: string): ManagementPending | null {
    const raw = sessionStorage.getItem(scope)
    if (!raw) return null
    const value: unknown = JSON.parse(raw)
    if (
      typeof value !== 'object' ||
      value === null ||
      !('operation' in value) ||
      !('intent_hash' in value) ||
      !('key' in value) ||
      typeof value.operation !== 'string' ||
      typeof value.intent_hash !== 'string' ||
      typeof value.key !== 'string' ||
      !/^[a-f0-9]{64}$/.test(value.intent_hash) ||
      !/^[a-f0-9-]{36}$/i.test(value.key) ||
      !['draft', 'case', 'review', 'retire', 'publish', 'revoke', 'rollback', 'suggest', 'adopt', 'purge-suggestion'].includes(
        value.operation
      ) ||
      (value.operation === 'draft' &&
        (!('draft_code' in value) ||
          typeof value.draft_code !== 'string' ||
          !('draft_base_version' in value) ||
          (value.draft_base_version !== null &&
            (typeof value.draft_base_version !== 'number' ||
              !Number.isSafeInteger(value.draft_base_version) ||
              value.draft_base_version < 1))))
    )
      throw new Error('原管理请求记录无效')
    return {
      operation: value.operation,
      intent_hash: value.intent_hash,
      key: value.key,
      ...('recovery_target' in value ? { recovery_target: operationTarget(value.recovery_target, value.operation) } : {}),
      ...('input_sha256' in value ? { input_sha256: storedInputHash(value.input_sha256) } : {}),
      ...('lifecycle' in value ? { lifecycle: lifecycleMetadata(value.lifecycle, value.operation) } : {}),
      ...('receipt' in value ? { receipt: receiptMetadata(value.receipt, value.operation) } : {}),
      ...('draft_code' in value &&
      typeof value.draft_code === 'string' &&
      'draft_base_version' in value &&
      (typeof value.draft_base_version === 'number' || value.draft_base_version === null)
        ? {
            draft_code: value.draft_code,
            draft_base_version: value.draft_base_version
          }
        : {})
    }
  }
  async function act<T>(
    call: (c: ReturnType<typeof context>) => Promise<T>,
    apply: (value: T) => void,
    heldFlight?: symbol
  ): Promise<void> {
    if (heldFlight !== undefined && operationFlight.value !== heldFlight) return
    if (busy.value && heldFlight === undefined) return
    const flight = heldFlight ?? Symbol('consumer-skill-operation')
    operationFlight.value = flight
    const current = generation
    ++writerSequence
    clearRunReader()
    busyState.value = true
    error.value = ''
    try {
      const c = context()
      const value = await call(c)
      assertCurrent(c)
      for (const name of c.operationKeys) sessionStorage.removeItem(name)
      if (current === generation && c.operationKeys.includes(managementScope))
        managementPending.value = null
      if (current === generation) {
        ++writerCompletionSequence
        clearRunReader()
        if (operationFlight.value === flight) operationFlight.value = null
        apply(value)
      }
    } catch (cause) {
      if (current === generation) {
        error.value = cause instanceof Error ? cause.message : '操作失败'
        if (
          cause instanceof SandAiRuntimeError &&
          (cause.httpStatus === 401 ||
            cause.httpStatus === 403 ||
            /AUTH|FORBIDDEN|SCOPE|REVOK|SOURCE_DRIFT|SOURCE_UNAVAILABLE|FACTS_UNVERIFIED|RESOURCE_NOT_FOUND/.test(
              cause.errorCode ?? ''
            ))
        ) {
          clearRunReader()
          clearCostReader()
          managementReceipt.value = null
          detail.value = null
          cases.value = []
          result.value = null
          resultLocator.value = null
          cost.value = null
          suggestion.value = null
        }
      }
    } finally {
      if (operationFlight.value === flight) operationFlight.value = null
      if (current === generation) busyState.value = false
    }
  }
  async function keyFor(
    c: ReturnType<typeof context>,
    operation: string,
    body: unknown,
    draftLocator?: { draft_code: string; draft_base_version: number | null },
    target?: { code: string; version?: number; allVersions?: boolean },
    lifecycle?: ManagementLifecycle,
    receipt?: ManagementReceipt,
    recoveryTarget?: ConsumerSkillReceiptTarget,
    upstreamBody?: Record<string, unknown>,
    confirmIntent?: () => void
  ): Promise<string> {
    assertCurrent(c)
    if (managementPending.value) throw new Error('原管理请求尚未确认，请只读找回原回执；不能再 POST 或换号')
    if (managementScope) {
      const durablePending = savedManagement(managementScope)
      if (durablePending) {
        managementPending.value = durablePending
        throw new Error('发现原管理请求记录，请先只读找回，不能再次发送')
      }
    }
    const frozenTarget = recoveryTarget ? Object.freeze(operationTarget(recoveryTarget, operation)) : undefined
    const upstream = frozenTarget ? canonical({ ...c.declaration, ...upstreamBody }).replace(/\u2028/g, '\\u2028').replace(/\u2029/g, '\\u2029') : undefined
    const conflictTarget = target ?? (draftLocator?.draft_base_version ? { code: draftLocator.draft_code, version: draftLocator.draft_base_version } : undefined)
    if (pending.value && conflictTarget?.code === pending.value.code &&
      (conflictTarget.allVersions || conflictTarget.version === pending.value.version))
      throw new Error('此操作影响原未确认调用的代码或版本，请先用原请求键只读找回')
    if (!c.identity) throw new Error('请先读取当前消费工作区，确认工作负载范围')
    const current = generation
    const freshKey = crypto.randomUUID()
    const tag = await digest(
      canonical([adminIdentity(), c.identity, c.declaration, operation, body])
    )
    const inputSha256 = upstream === undefined ? undefined : await digest(upstream)
    if (current !== generation) throw new Error('消费上下文已改变，请重新读取')
    confirmIntent?.()
    if (!managementScope) throw new Error('请先读取当前消费工作区')
    if (managementPending.value) throw new Error('原管理请求尚未确认，请只读找回，不能再次发送')
    const name = `sand-ai:consumer-skill:operation:v2:${tag}`
    const storedKey = sessionStorage.getItem(name)
    if (storedKey !== null && !/^[a-f0-9-]{36}$/i.test(storedKey)) throw new Error('原请求号记录无效，暂不能发送新操作')
    const key = storedKey || freshKey
    if (storedKey === null) c.freshOperationKeys.push(name)
    sessionStorage.setItem(name, key)
    const savedReceipt = receipt ?? (operation === 'draft'
      ? { operation: 'draft' as const } : undefined)
    assertCurrent(c)
    const managementIntent: ManagementPending = { operation, intent_hash: tag, key, ...draftLocator,
      ...(frozenTarget ? { recovery_target: frozenTarget } : {}),
      ...(inputSha256 && storedKey === null ? { input_sha256: inputSha256 } : {}),
      ...(lifecycle ? { lifecycle } : {}),
      ...(savedReceipt ? { receipt: savedReceipt } : {}) }
    const journal = JSON.stringify(managementIntent)
    sessionStorage.setItem(managementScope, journal)
    if (sessionStorage.getItem(managementScope) !== journal || sessionStorage.getItem(name) !== key)
      throw new Error('原请求记录未能完整保存，本次操作尚未发送')
    managementPending.value = managementIntent
    c.operationKeys.push(name, managementScope)
    if (storedKey !== null) throw new Error('发现原请求号，只能先只读找回原回执；不重发旧操作')
    return key
  }
  async function performManagementOperation(c: ReturnType<typeof context>, operation: string, intent: unknown,
    target: ConsumerSkillReceiptTarget, upstreamBody: Record<string, unknown>, send: (key: string) => Promise<unknown>,
    conflictTarget?: { code: string; version?: number; allVersions?: boolean }, lifecycle?: ManagementLifecycle,
    confirmIntent?: () => void): Promise<ConsumerSkillOperationReceipt> {
    const key = await keyFor(c, operation, intent, undefined, conflictTarget, lifecycle, undefined, target, upstreamBody, confirmIntent)
    const original = managementPending.value
    if (!original?.recovery_target || original.key !== key || !original.input_sha256) throw new Error('原操作恢复记录尚未完整保存')
    assertCurrent(c)
    confirmIntent?.()
    await send(key)
    assertCurrent(c)
    const receipt = await api.readOperationReceipt(c.token, c.declaration, original.recovery_target, original.key, original.input_sha256)
    assertCurrent(c)
    if (managementPending.value !== original) throw new Error('原管理请求或消费范围已改变')
    managementReceipt.value = receipt
    return receipt
  }
  function loadWorkspace(nextPage = 1): void {
    const cursor = nextPage === 1 ? undefined : pageCursors.value[nextPage - 1]
    if (nextPage > 1 && cursor === undefined) return
    void act(
      async (c) => {
        const [list, source] = await Promise.all([
          api.list(c.token, c.declaration, nextPage, cursor),
          api.sources(c.token, c.declaration)
        ])
        assertCurrent(c)
        const identity = verifiedWorkload(list.workload_identity)
        const hash = await digest(canonical([adminIdentity(), identity, c.declaration]))
        assertCurrent(c)
        const scope = `sand-ai:consumer-skill:pending:v2:${hash}`
        const management = `sand-ai:consumer-skill:management:v2:${hash}`
        return {
          list,
          source,
          identity,
          scope,
          restored: savedPending(scope),
          management,
          restoredManagement: savedManagement(management)
        }
      },
      (value) => {
        rows.value = value.list.data
        nextCursor.value = value.list.next_cursor
        pageCursors.value = nextPage === 1 ? [undefined] : pageCursors.value.slice(0, nextPage)
        if (value.list.next_cursor !== null) pageCursors.value.push(value.list.next_cursor)
        page.value = nextPage
        sources.value = value.source.data
        sourceCursor.value = value.source.next_cursor
        sourcePage.value = 1
        loaded.value = true
        workloadIdentity.value = value.identity
        storageScope = value.scope
        managementScope = value.management
        pending.value = value.restored
        managementPending.value = value.restoredManagement
        if (value.restored) Object.assign(recovery, value.restored)
        suggestionKey.value = sessionStorage.getItem(`${value.scope}:source-suggestion`) ?? '' 
      }
    )
  }
  function moreSources(): void {
    const next = sourcePage.value + 1
    const cursor = sourceCursor.value
    if (cursor === null) return
    void act(
      (c) => api.sources(c.token, c.declaration, next, cursor),
      (value) => {
        sources.value = [...sources.value, ...value.data]
        sourceCursor.value = value.next_cursor
        sourcePage.value = next
      }
    )
  }
  function readDetail(code: string, preferred?: number): void {
    ++generation
    changeVersionForm('')
    detail.value = null
    cases.value = []
    result.value = null
    cost.value = null
    costError.value = ''
    activeCaseId.value = null
    void act(
      async (c) => {
        const value = await api.read(c.token, c.declaration, code)
        assertCurrent(c)
        const selected =
          preferred ??
          value.versions.find((item) => item.id === value.skill.active_version_id)?.version ??
          value.versions[0]?.version
        if (!selected) throw new Error('方法包没有可读取版本')
        const history = await api.cases(c.token, c.declaration, code, selected)
        return { value, selected, history }
      },
      (value) => {
        detail.value = value.value
        selectedVersion.value = value.selected
        changeVersionForm(`${value.value.skill.code}:${value.selected}`)
        cases.value = value.history.cases
      }
    )
  }
  function loadCases(): void {
    if (!detail.value) return
    ++generation
    const code = detail.value.skill.code
    const selected = selectedVersion.value
    changeVersionForm(`${code}:${selected}`)
    // A failed read must not leave another version's passing cases on this target.
    cases.value = []
    result.value = null
    cost.value = null
    costError.value = ''
    activeCaseId.value = null
    reviewNote.value = ''
    void act(
      (c) => api.cases(c.token, c.declaration, code, selected),
      (value) => {
        cases.value = value.cases
      }
    )
  }
  function changeVersionForm(target: string): void {
    if (target === formTarget) return
    if (formTarget) {
      versionForms.set(formTarget, {
        caseName: caseName.value,
        caseInput: caseInput.value,
        criteria: criteria.value,
        input: input.value
      })
    }
    formTarget = target
    const saved = versionForms.get(target)
    caseName.value = saved?.caseName ?? ''
    caseInput.value = saved?.caseInput ?? '{}'
    criteria.value = saved?.criteria ?? ''
    input.value = saved?.input ?? '{}'
    activeCaseId.value = null
    reviewNote.value = ''
  }
  function copyDraft(): void {
    if (
      !detail.value ||
      !version.value ||
      detail.value.skill.state === 'revoked' ||
      version.value.state === 'abandoned' ||
      (version.value.state !== 'draft' &&
        detail.value.versions.some((item) => item.state === 'draft'))
    )
      return
    const v = version.value
    const original = managementPending.value
    revisionTarget.value =
      original?.operation === 'draft' && original.draft_code === detail.value.skill.code
        ? original.draft_base_version
          ? {
              code: detail.value.skill.code,
              version: original.draft_base_version
            }
          : null
        : v.state === 'draft'
          ? { code: detail.value.skill.code, version: v.version }
          : null
    Object.assign(draft, {
      code: detail.value.skill.code,
      name: v.name,
      description: v.description,
      instruction: v.instruction,
      sourceId: v.knowledge_base_version_id,
      inputSchema: JSON.stringify(v.input_schema, null, 2),
      outputSchema: JSON.stringify(v.output_schema, null, 2),
      reviewRequired: v.review_policy.review_required
    })
  }
  function draftSchema(text: string, label: string): Record<string, unknown> {
    const schema = object(text)
    if (schema.type !== 'object')
      throw new Error(`${label}须为根类型 type 为 object 的 JSON Schema，不能使用空对象或其他根类型`)
    return schema
  }
  function draftPayload(): ConsumerSkillDraft {
    const source = sources.value.find((item) => item.knowledge_base_version_id === draft.sourceId)
    if (!source || !draft.name.trim() || !draft.instruction.trim())
      throw new Error('请填写名称、指令并选择已开放知识版本')
    return {
      ...(draft.code.trim() ? { code: draft.code.trim() } : {}),
      名称: draft.name.trim(),
      描述: draft.description,
      指令: draft.instruction,
      输入结构: draftSchema(draft.inputSchema, '输入结构'),
      输出结构: draftSchema(draft.outputSchema, '输出结构'),
      复核策略: { review_required: draft.reviewRequired },
      知识库版本引用: {
        knowledge_base_version_id: source.knowledge_base_version_id,
        snapshot_sha256: source.snapshot_sha256
      }
    }
  }
  async function generateSuggestion(source: SkillSuggestionInput): Promise<void> {
    if (busy.value) return
    const flight = Symbol('consumer-skill-intent')
    operationFlight.value = flight
    try {
      if (!(await confirm('按所选固定知识版本提炼方法建议？本次会独立调用模型并计费；生成后须人工采用，不自动发布。'))) return
      await act(async (c) => {
        const key = await keyFor(c, 'suggest', source)
        assertCurrent(c)
        suggestionKey.value = key
        sessionStorage.setItem(`${storageScope}:source-suggestion`, key)
        return api.suggest(c.token, c.declaration, source, key)
      }, value => { suggestion.value = value }, flight)
    } finally {
      if (operationFlight.value === flight) operationFlight.value = null
    }
  }
  function recoverSuggestion(): void {
    const key = suggestionKey.value.trim()
    void act(async (c) => {
      if (!c.identity) throw new Error('请先读取当前消费范围')
      const value = await api.readSuggestion(c.token, c.declaration, key)
      assertCurrent(c)
      if (managementPending.value?.operation === 'suggest' && managementPending.value.key === key)
        c.operationKeys.push(managementScope, `sand-ai:consumer-skill:operation:v2:${managementPending.value.intent_hash}`)
      // A generation GET tombstone cannot confirm the original DELETE receipt.
      return value
    }, value => { suggestion.value = value })
  }
  function prepareSuggestedDraft(methods: SkillMethodSuggestion[], sourceId: number | undefined): void {
    if (busy.value || managementPending.value || methods.length === 0) return
    revisionTarget.value = null
    draft.code = ''
    draft.name = methods.map(item => item.name).join('、').slice(0, 100)
    draft.instruction = methods.map(item => `${item.name}\n${item.method}\n适用条件：${item.trigger}\n禁止事项：${item.prohibitions}`).join('\n\n')
    draft.sourceId = sourceId
    draft.reviewRequired = true
  }
  async function adoptSuggestion(selected: number[]): Promise<void> {
    if (busy.value) return
    const flight = Symbol('consumer-skill-intent')
    operationFlight.value = flight
    try {
      const value = suggestion.value
      if (!value || value.state !== 'pending_review' || draftReplacement.value || draftTargetBlocked.value) {
        error.value = '请先选择待审建议，并填写一个新的可评测草稿；不能直接替换旧版本'
        return
      }
      let body: ConsumerSkillDraft
      try { body = draftPayload() } catch (cause) {
        error.value = cause instanceof Error ? cause.message : '草稿无效'
        return
      }
      const id = value.source_suggestion_id
      const selectedIds = [...selected].sort((a, b) => a - b)
      if (!selectedIds.length || selectedIds.some(id => !Number.isSafeInteger(id) || id < 1) || new Set(selectedIds).size !== selectedIds.length) {
        error.value = '请选用有效且不重复的原建议编号'; return
      }
      if (!(await confirm('确认已人工审阅所选建议及下方草稿？采用仅保存草稿，仍需案例测试、明确判定及发布。'))) return
      await act((c) => performManagementOperation(c, 'adopt', { id, selected: selectedIds, draft: body },
        { operation: 'source_adopt', source_suggestion_id: id, ...(body.code ? { code: body.code } : {}) },
        { selected: selectedIds, draft: body }, key => api.adoptSuggestion(c.token, c.declaration, id, selectedIds, body, key)), saved => {
          suggestion.value = null
          busyState.value = false
          readDetail(saved.code, saved.version)
        }, flight)
    } finally {
      if (operationFlight.value === flight) operationFlight.value = null
    }
  }
  async function purgeSuggestion(): Promise<void> {
    if (busy.value) return
    const flight = Symbol('consumer-skill-intent')
    operationFlight.value = flight
    try {
      const value = suggestion.value
      if (!value || value.state === 'content_purged') return
      const id = value.source_suggestion_id
      if (!(await confirm('清理此建议的来源内容、建议及采用版本的来源副本？操作不可恢复；人工草稿、独立成果、调用费用及审计保留。'))) return
      await act(async (c) => api.purgeSuggestion(c.token, c.declaration, id,
        await keyFor(c, 'purge-suggestion', { id }, undefined, undefined, { operation: 'purge-suggestion', suggestion_id: id })), saved => { suggestion.value = saved }, flight)
    } finally {
      if (operationFlight.value === flight) operationFlight.value = null
    }
  }
  async function readSuggestionCost(): Promise<void> {
    const source = suggestion.value
    if (!source || source.state === 'content_purged' || source.invocation_id < 1 || !workloadIdentity.value || busy.value) return
    const current = generation
    const requestId = `skill-source:v1:${workloadIdentity.value.environment_id}:${source.source_suggestion_id}`
    busyState.value = true
    cost.value = null
    costError.value = ''
    try {
      const c = context()
      const value = await api.suggestionCost(c.token, requestId)
      assertCurrent(c)
      if (value.request_id !== requestId) throw new Error('费用不属于原来源建议')
      if (suggestion.value === source) cost.value = value
    } catch (cause) {
      if (current === generation && suggestion.value === source)
        costError.value = cause instanceof Error ? cause.message : '费用读取失败'
    } finally {
      if (current === generation) busyState.value = false
    }
  }
  async function saveDraft(): Promise<void> {
    if (busy.value) return
    const flight = Symbol('consumer-skill-intent')
    operationFlight.value = flight
    try {
      if (draftTargetBlocked.value) {
        error.value = '这个代码已有草稿或已撤销，不能保存下一版'
        return
      }
      let body: ConsumerSkillDraft
      try {
        body = draftPayload()
      } catch (cause) {
        error.value = cause instanceof Error ? cause.message : '草稿无效'
        return
      }
      const replacement = draftReplacement.value
      if (
        replacement &&
        !(await confirm(
          `将版本 ${replacement.expected_draft_version} 的修改保存为下一版？旧草稿、案例和评测保留只读，新版必须重新评测；未决测试会阻止替换。`
        ))
      )
        return
      await act(
        async (c) => {
          const hadPending = managementPending.value !== null
          const actor = adminIdentity()
          const adminCredential = userStore.accessToken
          const ownerEpoch = readerContextEpoch
          const writing = writerSequence
          const key = await keyFor(c, 'draft', { draft: body, ...replacement }, {
            draft_code: body.code ?? '',
            draft_base_version: replacement?.expected_draft_version ?? null
          })
          assertCurrent(c)
          const original = managementPending.value
          const scope = managementScope
          try {
            return await api.draft(c.token, c.declaration, body, key, replacement)
          } catch (cause) {
            const proof = cause instanceof SandAiRuntimeError ? cause.submission : null
            // Only the actual fresh draft POST's locked validation proof can
            // release its own local journal. Old keys and read failures stay locked.
            if (!hadPending && cause instanceof SandAiRuntimeError && cause.httpStatus === 400 && cause.businessCode === 400
              && cause.errorCode === 'SAND_AI_SCHEMA_UNSUPPORTED' && proof
              && proof.state === 'not_submitted' && proof.operation === 'draft'
              && proof.proof === 'fresh_locked_schema_validation' && proof.request_id === key
              && proof.scope.type === c.declaration.scope.type && proof.scope.ref === c.declaration.scope.ref
              && proof.actor_ref === c.declaration.actor_ref
              && proof.target.code === (body.code ?? null)
              && proof.target.expected_draft_version === (replacement?.expected_draft_version ?? null)
              && busyState.value && writing === writerSequence && c.epoch === generation
              && ownerEpoch === readerContextEpoch && String(userStore.info.id) === actor
              && userStore.accessToken === adminCredential && c.token === bearer.value.trim()
              && canonical(workloadIdentity.value) === canonical(c.identity)
              && scope === managementScope && original !== null && managementPending.value === original
              && original.operation === 'draft' && original.key === key
              && original.draft_code === (body.code ?? '')
              && original.draft_base_version === (replacement?.expected_draft_version ?? null)
              && sessionStorage.getItem(scope) === JSON.stringify(original)) {
              const operationKey = `sand-ai:consumer-skill:operation:v2:${original.intent_hash}`
              if (c.operationKeys.includes(scope) && c.operationKeys.includes(operationKey)
                && c.freshOperationKeys.includes(operationKey) && sessionStorage.getItem(operationKey) === key) {
                sessionStorage.removeItem(operationKey)
                sessionStorage.removeItem(scope)
                managementPending.value = null
              }
            }
            throw cause
          }
        },
        (value) => {
          busyState.value = false
          revisionTarget.value = null
          readDetail(value.code, value.version)
        },
        flight
      )
    } finally {
      if (operationFlight.value === flight) operationFlight.value = null
    }
  }
  function selectCase(row: SkillCaseRow): void {
    if (busy.value || !cases.value.some((item) => item.id === row.id)) return
    ++generation
    clearRunReader()
    activeCaseId.value = row.id
    input.value = JSON.stringify(row.input, null, 2)
    result.value = null
    cost.value = null
    reviewNote.value = ''
  }
  function useSingleInput(): void {
    if (busy.value) return
    ++generation
    clearRunReader()
    activeCaseId.value = null
    result.value = null
    cost.value = null
    costError.value = ''
    reviewNote.value = ''
  }
  function createCase(): void {
    if (!detail.value || !version.value) return
    const code = detail.value.skill.code
    const v = version.value.version
    let body: Record<string, unknown>
    try {
      body = object(caseInput.value)
    } catch {
      error.value = '案例输入必须是 JSON 对象'
      return
    }
    const name = caseName.value
    const expectedCriteria = criteria.value
    void act(
      async (c) =>
        api.createCase(
          c.token,
          c.declaration,
          code,
          v,
          name,
          body,
          expectedCriteria,
          await keyFor(c, 'case', [code, v, name, body, expectedCriteria], undefined, { code, version: v }, undefined,
            { operation: 'case', code, version: v })
        ),
      () => {
        busyState.value = false
        loadCases()
      }
    )
  }
  async function confirm(message: string): Promise<boolean> {
    const current = generation
    try {
      await ElMessageBox.confirm(message, '确认操作')
      return current === generation
    } catch {
      return false
    }
  }
  async function send(kind: 'test' | 'run'): Promise<void> {
    if (busy.value) return
    const flight = Symbol('consumer-skill-intent')
    operationFlight.value = flight
    try {
      if (busyState.value || pending.value || managementPending.value || !detail.value || !version.value)
        return
      let body: Record<string, unknown>
      try {
        body = object(input.value)
      } catch {
        error.value = '运行输入必须是 JSON 对象'
        return
      }
      const target: Pending = {
        code: detail.value.skill.code,
        version: version.value.version,
        kind,
        key: crypto.randomUUID(),
        input_hash: ''
      }
      if (
        !(await confirm(
          `将${kind === 'test' ? '测试草稿' : '运行固定版本'} ${target.version}，调用模型并计费。是否发送这一次新意图？`
        ))
      )
        return
      result.value = null
      cost.value = null
      costError.value = ''
      await act(async (c) => {
        if (!storageScope) throw new Error('浏览器尚未准备好保存原请求键')
        target.input_hash = await digest(canonical(body))
        assertCurrent(c)
        sessionStorage.setItem(storageScope, JSON.stringify(target))
        pending.value = target
        Object.assign(recovery, target)
        return kind === 'test'
          ? api.test(c.token, c.declaration, target.code, target.version, body, target.key)
          : api.run(c.token, c.declaration, target.code, target.version, body, target.key)
      }, (value) => acceptResult(value, target), flight)
    } finally {
      if (operationFlight.value === flight) operationFlight.value = null
    }
  }
  function sameLocator(left: RunLocator | null, right: RunLocator | null): boolean {
    return Boolean(left && right &&
      left.code === right.code && left.version === right.version &&
      left.kind === right.kind && left.key === right.key)
  }
  function acceptResult(value: SkillTestResult, target: RunLocator): void {
    result.value = value
    resultLocator.value = { ...target }
    cost.value = null
    costError.value = ''
    Object.assign(costLocator, target)
    if (value.state === 'succeeded' && sameLocator(pending.value, target)) {
      if (storageScope) sessionStorage.removeItem(storageScope)
      pending.value = null
    }
  }
  function recover(): void {
    readOriginalCall({ ...recovery })
  }
  function recoverPendingRun(): void {
    if (pending.value) readOriginalCall({ ...pending.value })
  }
  async function readOriginalCall(target: RunLocator): Promise<void> {
    if (runReadBusy.value) return
    if (!target.key.trim() || !target.code.trim() ||
      !Number.isSafeInteger(target.version) || target.version < 1) {
      runReadError.value = '请提供原代码、固定版本和请求键'
      return
    }
    const locator = { ...target }
    const sequence = ++runReadSequence
    const writing = writerSequence
    const completed = writerCompletionSequence
    const wasIdle = !busy.value
    const originalPending = pending.value
    runReadSelected.value = true
    runReadLocator.value = locator
    runReadResult.value = null
    runReadError.value = ''
    runReadStartedIdle = wasIdle
    runReadWriterSequence = writing
    runReadBusy.value = true
    let owner: ReturnType<typeof readerContext> | null = null
    const isCurrent = () => sequence === runReadSequence && readersLive
      && (owner === null || owner.isCurrent()) && writing === writerSequence
      && completed === writerCompletionSequence
    try {
      owner = readerContext()
      const { c } = owner
      const value = await api.recover(c.token, c.declaration, locator.code, locator.version, locator.kind, locator.key)
      if (!isCurrent()) return
      assertCurrent(c)
      runReadResult.value = value
      // Only an idle, unchanged writer may settle the same original journal.
      // A historical read never changes the new-run form or another pending key.
      if (wasIdle && !busy.value && value.state === 'succeeded'
        && pending.value === originalPending && sameLocator(originalPending, locator)
        && owner.scope && owner.scope === storageScope) {
        sessionStorage.removeItem(owner.scope)
        pending.value = null
      }
    } catch (cause) {
      if (isCurrent()) {
        runReadResult.value = null
        if (wasIdle && !busy.value && sameLocator(resultLocator.value, locator)
          && cause instanceof SandAiRuntimeError && (cause.httpStatus === 401 || cause.httpStatus === 403
            || /AUTH|FORBIDDEN|SCOPE|REVOK|SOURCE_DRIFT|SOURCE_UNAVAILABLE|FACTS_UNVERIFIED|RESOURCE_NOT_FOUND/.test(cause.errorCode ?? ''))) {
          result.value = null
          resultLocator.value = null
        }
        runReadError.value = cause instanceof Error ? cause.message : '原调用读取失败；原请求键仍保留'
      }
    } finally {
      if (readersLive && sequence === runReadSequence) runReadBusy.value = false
    }
  }
  function canonical(value: unknown): string {
    if (Array.isArray(value)) return `[${value.map(canonical).join(',')}]`
    if (typeof value === 'object' && value !== null) {
      return `{${Object.keys(value)
        .sort()
        .map(
          (key) => `${JSON.stringify(key)}:${canonical((value as Record<string, unknown>)[key])}`
        )
        .join(',')}}`
    }
    return JSON.stringify(value) ?? 'null'
  }
  async function retryFailed(): Promise<void> {
    if (busy.value) return
    const flight = Symbol('consumer-skill-intent')
    operationFlight.value = flight
    try {
      const target = pending.value
      if (
        !target?.input_hash ||
        !sameLocator(visibleResultLocator.value, target) ||
        busyState.value || !canUseVisibleResult.value ||
        managementPending.value ||
        visibleResult.value?.state !== 'failed' ||
        visibleResult.value.error_code === 'SAND_AI_DISPATCH_AMBIGUOUS'
      )
        return
      if (detail.value?.skill.code !== target.code || selectedVersion.value !== target.version) {
        error.value = '请先选择原方法包及原固定版本，再以原键和原输入恢复失败意图'
        return
      }
      let body: Record<string, unknown>
      const current = generation
      try {
        body = object(input.value)
        if ((await digest(canonical(body))) !== target.input_hash)
          throw new Error('请提供与原意图完全相同的输入；改变输入不能使用原键')
      } catch (cause) {
        error.value = cause instanceof Error ? cause.message : '原输入无效'
        return
      }
      if (
        current !== generation ||
        !(await confirm(
          '沿原键与原输入显式恢复失败意图；服务端核对未派发或已有调用事实。不会创建新的逻辑意图，未派发调用仍可能产生费用。'
        ))
      )
        return
      if (current !== generation || busyState.value || !canUseVisibleResult.value || managementPending.value ||
        !sameLocator(pending.value, target) || !sameLocator(visibleResultLocator.value, target) ||
        visibleResult.value?.state !== 'failed' || visibleResult.value.error_code === 'SAND_AI_DISPATCH_AMBIGUOUS')
        return
      await act(
        (c) =>
          target.kind === 'test'
            ? api.test(c.token, c.declaration, target.code, target.version, body, target.key)
            : api.run(c.token, c.declaration, target.code, target.version, body, target.key),
        (value) => acceptResult(value, target),
        flight
      )
    } finally {
      if (operationFlight.value === flight) operationFlight.value = null
    }
  }
  function review(verdict: 'pass' | 'fail'): void {
    if (!canReview.value || !detail.value || !visibleResult.value || activeCaseId.value === null) return
    const code = detail.value.skill.code
    const v = selectedVersion.value
    const id = activeCaseId.value
    const runId = visibleResult.value.run_id
    const note = reviewNote.value.trim()
    void act(
      (c) => performManagementOperation(c, 'review', [code, v, id, runId, verdict, note],
        { operation: 'review', code, version: v, case_id: id, skill_run_id: runId, verdict },
        { skill_run_id: runId, verdict, note }, key => api.review(c.token, c.declaration, code, v, id, runId, verdict, note, key),
        { code, version: v }),
      () => { reviewNote.value = ''; busyState.value = false; loadCases() }
    )
  }
  async function retire(row: SkillCaseRow): Promise<void> {
    if (busy.value) return
    const flight = Symbol('consumer-skill-intent')
    operationFlight.value = flight
    try {
      if (!detail.value || !editable.value) return
      let reason: string
      const current = generation
      try {
        reason = (await ElMessageBox.prompt('填写废弃理由；原案例和评测仍保留', '废弃案例')).value
      } catch {
        return
      }
      if (current !== generation || !reason.trim()) return
      const code = detail.value.skill.code
      const v = selectedVersion.value
      const caseId = row.id
      const note = reason.trim()
      await act(
        (c) => performManagementOperation(c, 'retire', [code, v, caseId, note],
          { operation: 'retire', code, version: v, case_id: caseId }, { reason: note },
          key => api.retire(c.token, c.declaration, code, v, caseId, note, key), { code, version: v }),
        () => { busyState.value = false; loadCases() },
        flight
      )
    } finally {
      if (operationFlight.value === flight) operationFlight.value = null
    }
  }
  function recoverSavedManagement(): void {
    const original = managementPending.value
    const locator = original?.receipt ?? (original?.operation === 'draft' ? { operation: 'draft' as const } : undefined)
    if (!original || !locator || busy.value) return
    void act(async (c) => {
      if (managementPending.value !== original) throw new Error('原保存请求或消费范围已改变')
      const value = locator.operation === 'draft'
        ? await api.readDraftReceipt(c.token, c.declaration, original.key, original.draft_code || undefined)
        : await api.readCreatedCase(c.token, c.declaration, locator.code, locator.version, original.key)
      assertCurrent(c)
      if (managementPending.value !== original) throw new Error('原保存请求或消费范围已改变')
      c.operationKeys.push(`sand-ai:consumer-skill:operation:v2:${original.intent_hash}`, managementScope)
      return value
    }, (value) => {
      busyState.value = false
      if (locator.operation === 'draft') revisionTarget.value = null
      readDetail(value.code, value.version)
    })
  }
  function legacyOperationTarget(original: ManagementPending): ConsumerSkillReceiptTarget {
    if (original.recovery_target) return original.recovery_target
    if (original.lifecycle?.operation === 'publish' || original.lifecycle?.operation === 'revoke')
      return operationTarget(original.lifecycle, original.operation)
    if (original.operation === 'adopt') return operationTarget({ operation: 'source_adopt',
      source_suggestion_id: legacyManagementSuggestionId.value }, original.operation)
    return operationTarget({ operation: original.operation, code: legacyManagementCode.value,
      version: legacyManagementVersion.value, case_id: legacyManagementCaseId.value }, original.operation)
  }
  async function resumeManagement(): Promise<void> {
    const original = managementPending.value
    if (!original || !managementRecoverable.value || busy.value) return
    let target: ConsumerSkillReceiptTarget
    try { target = legacyOperationTarget(original) }
    catch (cause) { error.value = cause instanceof Error ? cause.message : '请填写原操作目标'; return }
    await act(async (c) => {
      if (managementPending.value !== original) throw new Error('原管理请求或消费范围已改变')
      const receipt = await api.readOperationReceipt(c.token, c.declaration, target, original.key, original.input_sha256)
      assertCurrent(c)
      if (managementPending.value !== original) throw new Error('原管理请求或消费范围已改变')
      // A legacy read uses server-held immutable proof; returned hash is never saved as a missing original hash.
      c.operationKeys.push(`sand-ai:consumer-skill:operation:v2:${original.intent_hash}`, managementScope)
      return receipt
    }, (receipt) => {
      managementReceipt.value = receipt
      if (receipt.operation === 'source_adopt') suggestion.value = null
      reviewNote.value = ''
      clearRunReader()
      result.value = null; resultLocator.value = null; detail.value = null; cases.value = []
      busyState.value = false
      // An old receipt proves its original operation, not today's active version or case evaluation.
      ++generation
      loadWorkspace(1)
    })
  }
  function publicationIntent(): string {
    const skill = detail.value?.skill
    const fixed = version.value
    return canonical({
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
  function revocationIntent(code: string): string {
    const skill = detail.value?.skill.code === code ? detail.value.skill : rows.value.find(item => item.code === code)
    if (!skill) throw new Error('请先读取原撤销目标')
    return canonical({ id: skill.id, code: skill.code, state: skill.state, status: skill.status,
      active_version_id: skill.active_version_id })
  }
  async function publish(): Promise<void> {
    if (busy.value) return
    const flight = Symbol('consumer-skill-intent')
    operationFlight.value = flight
    try {
      if (!detail.value || !editable.value || !publishable.value || busyState.value) return
      const code = detail.value.skill.code
      const v = selectedVersion.value
      const originalContext = context()
      const originalIntent = publicationIntent()
      const confirmIntent = () => {
        assertCurrent(originalContext)
        if (!publishable.value || detail.value?.skill.code !== code || selectedVersion.value !== v
          || publicationIntent() !== originalIntent)
          throw new Error('原发布版本或案例判定已变化，请重新读取并明确确认；本次不发送')
      }
      if (!(await confirm(`明确发布 ${code} 固定版本 ${v}？案例判定不会替代业务复核。`))) return
      confirmIntent()
      await act(
        async (c) =>
          performManagementOperation(c, 'publish', [code, v], { operation: 'publish', code, version: v }, {},
            key => api.publish(c.token, c.declaration, code, v, key), { code, version: v }, { operation: 'publish', code, version: v }, confirmIntent),
        () => {
          busyState.value = false
          readDetail(code, v)
        },
        flight
      )
    } catch (cause) {
      error.value = cause instanceof Error ? cause.message : '无法确认原发布目标'
    } finally {
      if (operationFlight.value === flight) operationFlight.value = null
    }
  }
  async function revoke(): Promise<void> {
    if (!detail.value || busy.value) return
    await revokeCode(detail.value.skill.code)
  }
  async function revokeCode(code: string): Promise<void> {
    if (busy.value) return
    const flight = Symbol('consumer-skill-intent')
    operationFlight.value = flight
    try {
      if (busyState.value) return
      const originalContext = context()
      const originalIntent = revocationIntent(code)
      const confirmIntent = () => {
        assertCurrent(originalContext)
        if (revocationIntent(code) !== originalIntent)
          throw new Error('原撤销目标已变化，请重新读取并明确确认；本次不发送')
      }
      if (!(await confirm(`撤销 ${code} 后禁止后续调用和成果重放，历史费用保留。`))) return
      confirmIntent()
      await act(
        async (c) => performManagementOperation(c, 'revoke', code, { operation: 'revoke', code }, {},
          key => api.revoke(c.token, c.declaration, code, key), { code, allVersions: true }, { operation: 'revoke', code }, confirmIntent),
        () => {
          result.value = null
          cost.value = null
          rows.value = rows.value.map((row) =>
            row.code === code ? { ...row, state: 'revoked' } : row
          )
          if (detail.value?.skill.code === code) {
            detail.value = null
            cases.value = []
          }
        },
        flight
      )
    } catch (cause) {
      error.value = cause instanceof Error ? cause.message : '无法确认原撤销目标'
    } finally {
      if (operationFlight.value === flight) operationFlight.value = null
    }
  }
  async function rollback(): Promise<void> {
    if (busy.value) return
    const flight = Symbol('consumer-skill-intent')
    operationFlight.value = flight
    try {
      if (!detail.value || !runnable.value || busyState.value) return
      const code = detail.value.skill.code
      const v = selectedVersion.value
      if (!(await confirm(`将已发布固定版本 ${v} 设为活动版本？其他固定版本保持原生命周期。`))) return
      await act(
        async (c) =>
          performManagementOperation(c, 'rollback', [code, v], { operation: 'rollback', code, version: v }, {},
            key => api.rollback(c.token, c.declaration, code, v, key), { code, version: v }),
        () => {
          busyState.value = false
          readDetail(code, v)
        },
        flight
      )
    } finally {
      if (operationFlight.value === flight) operationFlight.value = null
    }
  }
  async function readCost(): Promise<void> {
    const target = { ...costLocator }
    if (costReadBusy.value || !target.code.trim() || !target.key.trim()
      || !Number.isSafeInteger(target.version) || target.version < 1) return
    const sequence = ++costReadSequence
    const sameTarget = () => sameLocator(costLocator, target)
    costReadBusy.value = true
    cost.value = null
    costError.value = ''
    let owner: ReturnType<typeof readerContext> | null = null
    const isCurrent = () => readersLive && sequence === costReadSequence && sameTarget()
      && (owner === null || owner.isCurrent())
    try {
      owner = readerContext()
      const { c } = owner
      const value = await api.historicalCost(c.token, c.declaration, target.code, target.version, target.kind, target.key)
      if (!isCurrent()) return
      assertCurrent(c)
      // Ownership and original-key linkage are proven by the scoped server endpoint.
      cost.value = value
    } catch (cause) {
      if (isCurrent()) costError.value = cause instanceof Error ? cause.message : '费用读取失败，原请求键仍保留'
    } finally {
      if (readersLive && sequence === costReadSequence) costReadBusy.value = false
    }
  }
  watch(
    () => [costLocator.code, costLocator.version, costLocator.kind, costLocator.key],
    () => { clearCostReader() },
    { flush: 'sync' }
  )
  watch(
    [bearer, scopeType, scopeRef, actorRef, () => userStore.info.id, () => userStore.accessToken],
    () => {
      ++generation
      ++sourceResetEpoch.value
      suggestion.value = null
      suggestionKey.value = ''
      busyState.value = false
      loaded.value = false
      workloadIdentity.value = null
      rows.value = []
      nextCursor.value = null
      pageCursors.value = [undefined]
      page.value = 1
      sources.value = []
      sourceCursor.value = null
      sourcePage.value = 1
      detail.value = null
      cases.value = []
      result.value = null
      resultLocator.value = null
      cost.value = null
      error.value = ''
      costError.value = ''
      pending.value = null
      managementPending.value = null
      managementReceipt.value = null
      activeCaseId.value = null
      reviewNote.value = ''
      versionForms.clear()
      formTarget = ''
      storageScope = ''
      managementScope = ''
      revisionTarget.value = null
      Object.assign(costLocator, { code: '', version: 1, kind: 'run', key: '' })
      input.value = '{}'
      caseName.value = ''
      caseInput.value = '{}'
      criteria.value = ''
      Object.assign(draft, {
        name: '',
        code: '',
        description: '',
        instruction: '',
        sourceId: undefined,
        inputSchema: defaultObjectSchema,
        outputSchema: defaultObjectSchema,
        reviewRequired: true
      })
      Object.assign(recovery, { code: '', version: 1, kind: 'run', key: '' })
      // Storage remains intact; a new successful workspace read selects only the same verified identity.
    },
    { flush: 'sync' }
  )
  watch(
    [bearer, scopeType, scopeRef, actorRef, () => userStore.info.id, () => userStore.accessToken, workloadIdentity],
    () => {
      ++readerContextEpoch
      clearRunReader()
      clearCostReader()
    },
    { flush: 'sync' }
  )
  onBeforeUnmount(() => {
    readersLive = false
    ++readerContextEpoch
    clearRunReader()
    clearCostReader()
    suggestion.value = null
    versionForms.clear()
    formTarget = ''
    ++generation
    bearer.value = ''
    result.value = null
    resultLocator.value = null
    cost.value = null
    managementReceipt.value = null
  })
  watch([bearer, scopeType, scopeRef, actorRef, () => userStore.info.id, () => userStore.accessToken], () => {
    legacyManagementCode.value = ''; legacyManagementVersion.value = undefined; legacyManagementSuggestionId.value = undefined; legacyManagementCaseId.value = undefined
  }, { flush: 'sync' })
</script>

<style scoped>
  .consumer-skill-card {
    margin-bottom: 16px;
  }
  h2 {
    margin: 0;
  }
  p {
    color: var(--el-text-color-secondary);
    line-height: 1.6;
  }
  pre {
    white-space: pre-wrap;
    overflow-wrap: anywhere;
  }
  .el-input,
  .el-select {
    max-width: 720px;
  }
  .el-alert,
  .el-table,
  .el-descriptions,
  .el-form,
  .el-space {
    margin: 12px 0;
  }
</style>
