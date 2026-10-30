<template>
  <ElCard shadow="never" class="sample-runner">
    <template #header>
      <h4>{{ profileState === 'published' ? '试用已发布配置' : '运行当前草稿样本' }}</h4>
    </template>
    <p class="muted">
      使用该环境应用自己的 SandIAM 运行身份。可在本页用同一身份上传并解析私有材料；
      试用不会自动发布配置。
    </p>
    <ElAlert v-if="materialTaskActionInFlight()" type="info" :closable="false" class="notice"
      title="原任务或样本操作仍在提交，暂不能发送新样本。切换身份或配置不会取消已发出的请求。" />
    <ElAlert v-if="disabled" :title="disabledReason ?? '请先保存草稿，再运行样本'" type="warning" :closable="false" />
    <ElAlert v-if="error" :title="error" type="error" show-icon class="notice" @close="error = ''" />
    <ElAlert v-if="cancelError" :title="cancelError" type="error" show-icon class="notice" />
    <ElAlert v-if="cancelUnconfirmedTaskId !== null && submitted?.taskId === cancelUnconfirmedTaskId"
      title="取消结果尚未确认。请刷新原任务；页面不会再次发送取消请求。" type="warning" show-icon class="notice" />
    <ElAlert v-if="originalMaterialAction" type="warning" :closable="false" class="notice"
      title="原材料操作尚待确认；请刷新原任务，页面不会再次取消、重试或恢复回执。" />
    <ElAlert v-if="retryError" :title="retryError" type="error" show-icon class="notice" @close="retryError = ''" />
    <ElAlert v-if="recoveryStorageError" type="warning" :closable="false" class="notice"
      title="原样本记录未能确认，暂不发送新样本。请恢复会话存储后重新读取原请求。" />
    <ElSpace v-if="recoveryIntents.length" wrap class="notice">
      <ElSelect :model-value="recoveryKey" :disabled="busy || reading" placeholder="选择原样本请求" @update:model-value="selectOriginalRequest">
        <ElOption v-for="intent in recoveryIntents" :key="intent.key" :value="intent.key"
          :label="`${intent.profile_code} · ${intent.profile_version} · 修订 ${intent.profile_revision} · ${intent.key}`" />
      </ElSelect>
      <span v-if="recoveryIntent">原目标：{{ recoveryIntent.profile_code }} · {{ recoveryIntent.profile_version }} · 修订 {{ recoveryIntent.profile_revision }}。</span>
    </ElSpace>
    <ElButton v-if="recoveryStorageError" :disabled="busy || reading" @click="loadRecoveryKey(apiKey.trim(), generation)">重读原请求记录</ElButton>
    <ElForm label-width="110px" @submit.prevent>
      <RuntimeApiKeyField v-model="apiKey" placeholder="应用提供的 SandIAM Bearer；需 file.read、workflow.run/read；上传解析另需 file.write、document_parse" />
      <MaterialSampleFiles :bearer="apiKey" :environment-id="environmentId" :disabled="disabled || busy || !!submitted || !!recoveryKey"
        @use-file="addPreparedFile" @identity-denied="clearDeniedResult" />
      <ElFormItem label="文件 ID">
        <ElInput v-model.trim="fileIdsText" :disabled="busy || !!submitted || !!recoveryKey" placeholder="上方加入的文件会显示在这里，也可填写 1–100 个已解析文件 ID" />
      </ElFormItem>
      <ElFormItem label="业务范围">
        <ElInput v-model.trim="scopeRef" :disabled="busy || !!recoveryKey" placeholder="应用中的业务范围引用，例如试用项目代码" />
      </ElFormItem>
      <ElFormItem label="资料快照">
        <ElInput v-model.trim="snapshotRef" :disabled="busy || !!recoveryKey" placeholder="应用中的资料快照引用" />
      </ElFormItem>
      <ElFormItem label="快照版本">
        <ElInput v-model.trim="snapshotVersion" :disabled="busy || !!recoveryKey" placeholder="资料快照版本" />
      </ElFormItem>
      <ElFormItem>
        <ElSpace wrap>
          <ElButton type="primary" :loading="busy" :disabled="disabled || busy || !canSubmit || !!submitted || !!recoveryKey" @click="submit">
            {{
              profileState === 'published'
                  ? '运行已发布配置'
                  : '运行样本'
            }}
          </ElButton>
          <ElButton v-if="recoveryKey && !submitted" :loading="busy" :disabled="busy || !apiKey.trim()" @click="recover">找回上次样本</ElButton>
          <ElButton v-if="submitted && !busy && !restoringRun && !recoveryUnresolved && !recoveryStorageError && !originalMaterialAction" @click="startNew">运行新样本</ElButton>
        </ElSpace>
      </ElFormItem>
    </ElForm>
    <ElAlert v-if="recoveryKey && !submitted" type="warning" :closable="false"
      title="原样本结果尚未确认。请用当前已授权应用身份只读找回原请求；未找到或读取失败不能据此重新发送。" />
    <ElAlert v-if="submitted && restoringRun" type="warning" :closable="false"
      title="运行归属尚未确认" description="读取并核对原运行的配置版本后，才会显示该配置的成果与费用。" />
    <ElAlert v-if="submitted && !restoringRun" type="success" :closable="false"
      :title="`样本运行 #${submitted.runId} 已提交${submitted.replayed ? '（复用已有运行）' : ''}`"
      :description="
        profileState === 'published'
          ? '本页会自动刷新进度、成果与费用；本次试用使用已发布配置。'
          : '本页会自动刷新进度和成果；成功并完成计量后，可刷新成功样本并发布。'
      "
    />
    <ElButton v-if="submitted?.taskId" link type="primary" @click="goTask(submitted.taskId)">
      查看任务 #{{ submitted.taskId }}、成果与费用
    </ElButton>
    <ElButton v-if="submitted" :loading="reading" :disabled="reading || busy" @click="refreshRun">刷新样本结果</ElButton>
    <ElButton v-if="cancelTaskId !== null" type="danger" plain :loading="busy || cancelConfirming"
      :disabled="busy || reading || cancelConfirming || cancelUnconfirmedTaskId === cancelTaskId" @click="cancelMaterialRun">取消未完成的处理</ElButton>
    <template v-if="workflowRun">
      <ElAlert v-if="recoveryUnresolved" type="warning" :closable="false" show-icon class="notice"
        :title="providerOutcomeUnconfirmed ? '服务商调用结果待确认' : '当前结果仍待确认'"
        :description="`请刷新原任务查看实际状态，暂勿重新提交材料。${recoveryCostUnconfirmed ? '费用尚未确认。' : ''}`" />
      <p>运行状态：{{ stateLabel(workflowRun.state) }} ·
        费用状态：{{ costLabel(workflowRun.cost.state) }}
        <span v-if="workflowRun.cost.metered_amount !== null && workflowRun.cost.currency">
          · {{ workflowRun.cost.metered_amount }} {{ workflowRun.cost.currency }}
        </span>
        <span v-if="workflowRun.cost.unpriced_count"> · 未定价 {{ workflowRun.cost.unpriced_count }} 条</span>
      </p>
      <p v-if="workflowRun.cost.by_currency && Object.keys(workflowRun.cost.by_currency).length">
        已知分币种金额：
        <span v-for="(amount, currency) in workflowRun.cost.by_currency" :key="currency">
          {{ amount }} {{ currency }}
        </span>
      </p>
      <section v-if="workflowRun.file_states.length" aria-label="材料文件进度">
        <h4>材料文件进度</h4>
        <div v-for="file in workflowRun.file_states" :key="file.file_id" class="notice">
          <p>文件 #{{ file.file_id }} · {{ stateLabel(file.analysis_state) }}（尝试 {{ file.attempt_count }}）</p>
          <template v-if="file.chunk_progress">
            <p>{{ materialChunkStage(file.chunk_progress) }} ·
              分段整理 {{ file.chunk_progress.completed_leaf_count }} / {{ file.chunk_progress.leaf_count }} ·
              归并 {{ file.chunk_progress.completed_merge_count }} / {{ file.chunk_progress.merge_count }}</p>
            <p>来源 {{ file.chunk_progress.source_block_count }} 块 · {{ file.chunk_progress.source_character_count }} 字符 ·
              {{ materialCoverageLabel(file.chunk_progress.coverage_state) }}</p>
            <p v-if="file.chunk_progress.failed_node_count">确认失败 {{ file.chunk_progress.failed_node_count }} 处；沿原任务重试失败文件，已完成分段沿原记录恢复，不重复调用模型。</p>
            <p v-if="file.chunk_progress.unknown_node_count">原分块调用结果待确认 {{ file.chunk_progress.unknown_node_count }} 处；请只读刷新原任务找回，不重新提交或更换请求号。</p>
          </template>
          <p v-else class="muted">未记录分块覆盖；沿原文件成果与来源查看。</p>
        </div>
        <p class="muted">文件完成与成果以原文件状态和封存结果为准。</p>
      </section>
      <ElAlert v-if="materialChunkUnconfirmed" type="warning" :closable="false" class="notice"
        title="原分块调用结果待确认"
        description="请只读刷新原任务找回调用结果；不要重新提交材料或更换请求号。已完成分段保留，当前不能重试或恢复跨文件回执。">
        <ElButton :disabled="busy || reading" @click="readReceiptOutcome">只读刷新原任务</ElButton>
      </ElAlert>
      <p v-if="workflowRun.cross_file_review">跨文件核对：{{ stateLabel(workflowRun.cross_file_review.state) }}</p>
      <ElAlert
        v-if="workflowRun.state === 'failed'"
        type="error"
        :closable="false"
        show-icon
        :title="outputValidationFailure ? '模型返回的成果未通过校验' : '材料整理未完成'"
        :description="
          outputValidationFailure?.failure_summary || '请展开失败详情，核对原因后再处理失败部分。'
        "
      />
      <ElCollapse v-if="failureCodes.length">
        <ElCollapseItem title="查看失败详情" name="failure">
          <p v-for="code in failureCodes" :key="code">错误码：{{ code }}</p>
        </ElCollapseItem>
      </ElCollapse>
      <ElButton v-if="retryOption" type="warning" :loading="busy" :disabled="busy || reading"
        @click="retryFailedPart">{{ retryOption.label }}</ElButton>
      <p v-if="receiptAttemptKey && workflowRun.cross_file_review?.state === 'failed'">汇总失败，已完成文件成果保留；请展开失败详情。</p>
      <section v-if="receiptRecoveryTaskId !== null" class="notice">
        <p>不新增模型调用；仍会核对当前授权与来源。输出校验拒绝将显示汇总失败并保留文件成果。</p>
        <ElButton type="warning" :loading="busy || receiptConfirming"
          :disabled="busy || reading || receiptConfirming || Boolean(receiptAttemptKey) || !workflowRun.task?.receipt_only_recovery?.available"
          @click="recoverReviewReceipt">仅恢复已收到的复核结果</ElButton>
        <p v-if="workflowRun.task?.receipt_only_recovery">
          已使用 {{ workflowRun.task.receipt_only_recovery.attempt_count }} / {{ workflowRun.task.receipt_only_recovery.max_attempts }} 次恢复机会。
          <span v-if="!workflowRun.task.receipt_only_recovery.available">当前不可恢复；请刷新原任务查看实际状态。</span>
        </p>
        <p v-if="receiptAttemptKey">本次恢复已提交或结果未确认；请只刷新原任务，本页不会再次提交恢复。</p>
        <ElButton v-if="receiptAttemptKey" :disabled="busy || reading" @click="readReceiptOutcome">刷新原任务</ElButton>
      </section>
      <ElCollapse v-if="workflowRun.file_results?.length" v-model="materialResultFileId" accordion>
        <ElCollapseItem v-for="file in workflowRun.file_results" :key="file.file_id"
          :title="`文件 #${file.file_id} 的整理结果`" :name="file.file_id">
          <MaterialFileResultPages v-if="file.paged && materialResultFileId === file.file_id"
            :bearer="apiKey" :owner-id="userStore.info.id" :run-id="workflowRun.run_id"
            :task-id="workflowRun.task?.id ?? null" :file-result="file" :environment-id="environmentId"
            @open-source="openSource" @clear-source="clearSource" @access-denied="clearDeniedResult" />
          <MaterialResult v-else-if="!file.paged" :payload="file.result.payload"
            :citations="file.citations" @open-source="openSource" />
        </ElCollapseItem>
      </ElCollapse>
      <section v-if="workflowRun.result" class="sample-result">
        <h4>最终材料成果</h4>
        <ElAlert v-if="workflowRun.artifact?.review_required" type="warning" :closable="false"
          title="成果包含待核实项，请按来源复核后使用" />
        <MaterialResult :payload="workflowRun.result.payload" :citations="workflowRun.citations" @open-source="openSource" />
      </section>
      <p v-else-if="workflowRun.state === 'failed'" class="muted"
        >本次运行已结束，尚未生成最终成果；已完成的文件结果仍可查看。重试失败部分前，请先处理失败原因。</p
      >
      <p v-else-if="workflowRun.state === 'canceled'" class="muted"
        >本次运行已取消，不会继续自动处理；已完成的文件结果与已记录费用仍保留。</p
      >
      <p v-else-if="recoveryUnresolved" class="muted">最终成果尚未确认；请刷新原任务，并保留原运行号。</p>
      <p v-else class="muted">最终成果尚未生成；可稍后刷新进度。</p>
    </template>
    <ElDialog v-model="sourceOpen" title="材料来源" width="600px">
      <p v-if="sourceLoading">正在读取来源…</p>
      <ElAlert v-else-if="sourceError" type="error" :title="sourceError" :closable="false" />
      <template v-else-if="sourcePreview">
        <p>文件 #{{ sourcePreview.file_id }} · {{ sourcePreview.locator_type }}
          {{ JSON.stringify(sourcePreview.locator) }}</p>
        <MaterialSourceContent :source="sourcePreview" />
      </template>
    </ElDialog>
  </ElCard>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
  import { useRoute, useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { useUserStore } from '@/store/modules/user'
import fileApi from '../api/file'
import materialApi, { type MaterialSampleRequest } from '../api/materialProfile'
import { newRuntimeRequestId } from '../api/runtime'
import { readMaterialSampleJournal, retainMaterialSampleIntent, resolveMaterialSampleIntent, type MaterialSampleIntent } from '../api/materialSetupIntent'
import { saveRuntimeBearer } from '../api/runtimeBearerSession'
import { SandAiRuntimeError } from '../api/runtime'
import taskApi, { type WorkflowRunWithReceiptRecovery, type TaskActionExpectation, type MaterialRetryExpectation } from '../api/task'
import { beginMaterialTaskActionFlight, finishMaterialTaskActionFlight, materialTaskActionInFlight,
  readMaterialTaskAction, retainMaterialTaskAction, reconcileMaterialTaskAction } from '../api/materialTaskAction'
import type { SandAiMaterialChunkProgress, SandAiWorkflowRun, SandAiWorkflowSource } from '../api/types'
import RuntimeApiKeyField from './RuntimeApiKeyField.vue'
import MaterialResult from '../task/MaterialResult.vue'
import MaterialSourceContent from './MaterialSourceContent.vue'
import MaterialSampleFiles from './MaterialSampleFiles.vue'
import MaterialFileResultPages from './MaterialFileResultPages.vue'

const props = defineProps<{
  environmentId: number
  profileCode: string
  profileVersion: string
  profileRevision: number
    profileState: 'draft' | 'published'
    resumeRunId?: number
  disabled: boolean
  disabledReason?: string
}>()
  const route = useRoute()
const emit = defineEmits<{ 'refresh-samples': []; 'original-request-change': [] }>()
const router = useRouter()
const userStore = useUserStore()
const apiKey = ref('')
const originalMaterialAction = computed(() => workflowRun.value?.task
  ? readMaterialTaskAction(workflowRun.value.task, userStore.info.id) : undefined)
const fileIdsText = ref('')
const scopeRef = ref('')
const snapshotRef = ref('')
const snapshotVersion = ref('')
const busy = ref(false)
const error = ref('')
const retryError = ref('')
const receiptConfirming = ref(false)
const receiptAttemptKey = ref('')
const receiptAttempts = new Map<string, string>()
const cancelError = ref('')
const restoringRun = ref(false)
const cancelConfirming = ref(false)
const cancelUnconfirmedTaskId = ref<number | null>(null)
const submitted = ref<{ runId: number; taskId: number | null; replayed: boolean } | null>(null)
  const workflowRun = ref<WorkflowRunWithReceiptRecovery | null>(null)
const materialResultFileId = ref<string | number>('')
  const outputValidationFailure = computed(() =>
    workflowRun.value?.file_states.find(
      (file) => file.analysis_state === 'failed' && file.failure_stage === 'model_output_validation'
    )
  )
  const failureCodes = computed(() => {
    const run = workflowRun.value
    if (!run) return []
    const codes = run.file_states.flatMap((file) => (file.error_code ? [file.error_code] : []))
    if (run.cross_file_review?.error_code) codes.push(run.cross_file_review.error_code)
    return [...new Set(codes)]
  })
// Recovery state alone does not prove provider dispatch or the presence of a receipt.
const materialChunkUnconfirmed = computed(() => workflowRun.value?.file_states.some(file => file.chunk_progress
  && (file.chunk_progress.unknown_node_count > 0 || file.chunk_progress.state === 'recovery_pending')) === true)
const providerOutcomeUnconfirmed = computed(() => {
  const run = workflowRun.value
  return run?.task?.provider_outcome_state === 'unconfirmed'
    || run?.task?.error_code === 'SAND_AI_DISPATCH_AMBIGUOUS'
    || run?.cross_file_review?.error_code === 'SAND_AI_DISPATCH_AMBIGUOUS'
    || run?.file_states.some(file => file.error_code === 'SAND_AI_DISPATCH_AMBIGUOUS') === true
})
const recoveryUnresolved = computed(() => providerOutcomeUnconfirmed.value || materialChunkUnconfirmed.value
  || workflowRun.value?.state === 'recovery_pending'
  || workflowRun.value?.task?.state === 'recovery_pending')
const recoveryCostUnconfirmed = computed(() => {
  const state = workflowRun.value?.cost.state
  return state === 'unavailable' || state === 'pending'
})
const reading = ref(false)
const sourceOpen = ref(false)
const sourceLoading = ref(false)
const sourceError = ref('')
const sourcePreview = ref<SandAiWorkflowSource | null>(null)
let generation = 0
let sourceGeneration = 0
let readGeneration = 0
let pollTimer: ReturnType<typeof setTimeout> | null = null
let quotaPollingBlocked = false
let refreshedCandidateRunId = 0
let pending: { signature: string; body: MaterialSampleRequest; key: string } | null = null
const recoveryKey = ref('')
const recoveryChecked = ref(false)
const recoveryReady = ref(false)
const recoveryStorageError = ref(false)
const recoveryIntents = ref<MaterialSampleIntent[]>([])
const recoveryIntent = computed(() => recoveryIntents.value.find(item => item.key === recoveryKey.value) ?? null)

async function loadRecoveryKey(credential: string, current: number): Promise<void> {
  try {
    const journal = readMaterialSampleJournal(Number(userStore.info.id), props.environmentId)
    if (current !== generation || apiKey.value.trim() !== credential) return
    recoveryIntents.value = journal.intents
    recoveryKey.value = journal.active_key ?? ''
    recoveryStorageError.value = false
    recoveryReady.value = true
  } catch {
    if (current === generation) {
      recoveryStorageError.value = true
      recoveryReady.value = false
    }
  }
}

function saveRecoveryKey(key: string, runId?: number): void {
  try {
    if (key) {
      const original = recoveryIntent.value
      const intent: MaterialSampleIntent = original ?? {
        admin_id: Number(userStore.info.id), environment_id: props.environmentId,
        profile_code: props.profileCode, profile_version: props.profileVersion,
        profile_revision: props.profileRevision, profile_state: props.profileState, key
      }
      if (intent.key !== key || intent.admin_id !== Number(userStore.info.id) || intent.environment_id !== props.environmentId) {
        throw new Error('原样本目标已变化')
      }
      retainMaterialSampleIntent(runId === undefined ? intent : { ...intent, run_id: runId })
    } else if (recoveryIntent.value) {
      resolveMaterialSampleIntent(recoveryIntent.value)
    }
    const journal = readMaterialSampleJournal(Number(userStore.info.id), props.environmentId)
    recoveryIntents.value = journal.intents
    recoveryKey.value = journal.active_key ?? ''
    recoveryStorageError.value = false
    emit('original-request-change')
  } catch {
    recoveryStorageError.value = true
    recoveryReady.value = false
    throw new Error('无法保留或确认原样本请求记录，未继续发送新请求')
  }
}

function selectOriginalRequest(key: unknown): void {
  if (typeof key !== 'string' || busy.value || reading.value) return
  const intent = recoveryIntents.value.find(item => item.key === key)
  if (!intent || intent.admin_id !== Number(userStore.info.id) || intent.environment_id !== props.environmentId) return
  try {
    retainMaterialSampleIntent(intent)
    resetForIdentityChange()
    emit('original-request-change')
  } catch { recoveryStorageError.value = true }
}

const canSubmit = computed(() => Boolean(!materialTaskActionInFlight() && recoveryReady.value && !recoveryStorageError.value && !recoveryKey.value
  && apiKey.value.trim() && fileIdsText.value.trim() && scopeRef.value.trim() && snapshotRef.value.trim() && snapshotVersion.value.trim()))
const cancelTaskId = computed<number | null>(() => {
  if (originalMaterialAction.value) return null
  const run = workflowRun.value
  const task = run?.task
  if (!run || !task || !Number.isSafeInteger(task.id) || task.id < 1
    || run.run_id !== submitted.value?.runId || task.id !== submitted.value?.taskId
    || task.task_type !== 'structured_workflow' || task.resource_type !== 'workflow_run'
    || task.resource_id !== run.run_id || !apiKey.value.trim()
    || !['authorization_pending', 'queued', 'retrying', 'running', 'recovery_pending'].includes(task.state)) return null
  return task.id
})

function materialActionSnapshot(run: WorkflowRunWithReceiptRecovery): string {
  return JSON.stringify([run.run_id, run.state, run.task?.id, run.task?.task_type,
    run.task?.resource_type, run.task?.resource_id, run.task?.state, run.task?.attempt_count,
    run.task?.provider_outcome_state, run.analysis_profile,
    [...run.file_states].sort((left, right) => left.file_id - right.file_id).map(file => [
      file.file_id, file.analysis_state, file.attempt_count, file.error_code, file.chunk_progress
    ]), run.cross_file_review])
}

function materialTaskExpectation(run: WorkflowRunWithReceiptRecovery): TaskActionExpectation {
  const task = run.task
  if (!task || !Number.isSafeInteger(task.attempt_count) || task.attempt_count < 0) {
    throw new Error('原任务执行代次尚未确认，本次操作未发送；请只读刷新原任务')
  }
  return { expected_attempt_count: task.attempt_count, expected_state: task.state }
}

function materialRetryExpectation(run: WorkflowRunWithReceiptRecovery,
  target: 'failed_files' | 'cross_file_review'): MaterialRetryExpectation {
  const expectation = materialTaskExpectation(run)
  if (target === 'cross_file_review') {
    const attempt = run.cross_file_review?.attempt_count
    if (typeof attempt !== 'number' || !Number.isSafeInteger(attempt) || attempt < 0) {
      throw new Error('原跨文件复核代次尚未确认，本次重试未发送；请只读刷新原任务')
    }
    return { ...expectation, expected_review_attempt_count: attempt }
  }
  const files = run.file_states.filter(file => file.analysis_state === 'failed')
    .map(file => ({ file_id: file.file_id, attempt_count: file.attempt_count }))
    .sort((left, right) => left.file_id - right.file_id)
  if (!files.length || new Set(files.map(file => file.file_id)).size !== files.length
    || files.some(file => !Number.isSafeInteger(file.file_id) || file.file_id < 1
      || !Number.isSafeInteger(file.attempt_count) || file.attempt_count < 0)) {
    throw new Error('原失败文件集合或执行代次尚未确认，本次重试未发送；请只读刷新原任务')
  }
  return { ...expectation, expected_failed_files: files }
}

async function cancelMaterialRun(): Promise<void> {
  const taskId = cancelTaskId.value
  const runId = submitted.value?.runId
  const credential = apiKey.value.trim()
  if (taskId === null || !runId || materialTaskActionInFlight() || busy.value || reading.value || cancelConfirming.value
    || cancelUnconfirmedTaskId.value === taskId) return
  const run = workflowRun.value
  if (!run?.task) return
  const snapshot = materialActionSnapshot(run)
  let expectation: TaskActionExpectation
  try { expectation = materialTaskExpectation(run) } catch (failure) {
    cancelError.value = failure instanceof Error ? failure.message : '原任务执行代次尚未确认'
    return
  }
  const beforeConfirm = generation
  cancelConfirming.value = true
  try {
    await ElMessageBox.confirm(
      `确认取消任务 #${taskId}？已完成的文件成果和已记录费用会保留，已发出的调用仍可能产生费用。`,
      '取消材料处理', { type: 'warning', confirmButtonText: '确认取消', cancelButtonText: '继续处理' }
    )
  } catch (failure) {
    if (failure !== 'cancel' && failure !== 'close' && beforeConfirm === generation) {
      cancelError.value = failure instanceof Error ? failure.message : '取消确认未能打开'
    }
    return
  } finally {
    if (beforeConfirm === generation) cancelConfirming.value = false
  }
  if (materialTaskActionInFlight() || beforeConfirm !== generation || apiKey.value.trim() !== credential
    || submitted.value?.runId !== runId || cancelTaskId.value !== taskId) return
  const flight = beginMaterialTaskActionFlight()
  const current = ++generation
  invalidateRead()
  busy.value = true
  cancelError.value = ''
  // Until the original task read confirms a terminal state, an unknown POST
  // outcome is retained and this component must not issue a second cancel.
  try {
    const latest = await taskApi.readWorkflow(credential, runId, props.environmentId)
    if (current !== generation || apiKey.value.trim() !== credential
      || submitted.value?.runId !== runId || submitted.value.taskId !== taskId) return
    assertSelectedProfile(latest)
    if (materialActionSnapshot(latest) !== snapshot) {
      throw new Error('原任务状态已变化，本次取消未发送；请只读刷新原任务后重新确认')
    }
    cancelUnconfirmedTaskId.value = taskId
    retainMaterialTaskAction(run.task, userStore.info.id, { kind: 'cancel', attempt: expectation.expected_attempt_count, key: null })
    const task = await taskApi.cancel(credential, taskId, expectation)
    if (current !== generation || apiKey.value.trim() !== credential
      || submitted.value?.runId !== runId || submitted.value.taskId !== taskId) return
    if (task.id !== taskId || task.task_type !== 'structured_workflow'
      || task.resource_type !== 'workflow_run' || task.resource_id !== runId || task.state !== 'canceled') {
      throw new Error('取消响应与原材料任务不一致，请刷新原任务确认')
    }
    const run = workflowRun.value
    if (run?.run_id === runId) workflowRun.value = { ...run, state: task.state, task }
    reconcileMaterialTaskAction(task, userStore.info.id)
    cancelUnconfirmedTaskId.value = null
    ElMessage.success(`任务 #${taskId} 已取消；已完成文件成果和已有费用保留`)
  } catch (failure) {
    if (current !== generation || apiKey.value.trim() !== credential
      || submitted.value?.runId !== runId || submitted.value.taskId !== taskId) return
    if (clearDeniedResult(failure)) return
    if (failure instanceof SandAiRuntimeError && [400, 404, 409, 422].includes(failure.httpStatus)
      && failure.errorCode !== null) cancelUnconfirmedTaskId.value = null
    cancelError.value = failure instanceof Error ? failure.message : '取消结果未确认，请刷新原任务'
  } finally {
    finishMaterialTaskActionFlight(flight)
    if (current === generation && apiKey.value.trim() === credential && submitted.value?.runId === runId) {
      busy.value = false
      void refreshRun()
    }
  }
}

const receiptRecoveryTaskId = computed<number | null>(() => {
  if (originalMaterialAction.value || materialChunkUnconfirmed.value) return null
  const run = workflowRun.value
  const task = run?.task
  if (!run || !task || !apiKey.value.trim() || run.run_id !== submitted.value?.runId
    || task.id !== submitted.value?.taskId || task.state !== 'recovery_pending'
    || task.receipt_only_recovery?.target !== 'cross_file_review'
    || !(run.cross_file_review?.state === 'recovery_pending'
      || (run.cross_file_review?.state === 'succeeded' && task.receipt_only_recovery.available === true))
    || !Array.isArray(run.file_results)
    || run.file_states.length < 2 || run.file_results.length !== run.file_states.length) return null
  try { assertSelectedProfile(run) } catch { return null }
  const ids = run.file_states.map(file => file.file_id)
  if (ids.some(id => !Number.isSafeInteger(id) || id < 1) || new Set(ids).size !== ids.length
    || run.file_states.some(file => file.analysis_state !== 'succeeded')
    || new Set(run.file_results.map(file => file.file_id)).size !== ids.length
    || run.file_results.some(file => !ids.includes(file.file_id))) return null
  return task.id
})

// Public intent keys survive a page reload or a newly issued context; no Bearer/results are stored.
const receiptAttemptStorageKey = computed(() => {
  const run = workflowRun.value
  if (!run?.task || run.run_id !== submitted.value?.runId) return ''
  return `sand-ai-material-receipt-intent:${userStore.info.id}:${props.environmentId}:${run.run_id}:${run.task.id}`
})
watch(receiptAttemptStorageKey, key => {
  receiptAttemptKey.value = ''
  if (!key) return
  receiptAttemptKey.value = receiptAttempts.get(key) ?? ''
  try { receiptAttemptKey.value ||= sessionStorage.getItem(key) ?? '' } catch { /* Preserve the memory lock when storage is unavailable. */ }
  if (receiptAttemptKey.value) receiptAttempts.set(key, receiptAttemptKey.value)
}, { flush: 'sync' })

async function readReceiptOutcome(): Promise<void> {
  const run = workflowRun.value
  const taskId = run?.task?.id
  const credential = apiKey.value.trim()
  if (!run || !taskId || !credential || busy.value || reading.value) return
  const current = generation
  busy.value = true
  retryError.value = ''
  try {
    const task = await taskApi.read(credential, taskId)
    if (current !== generation || credential !== apiKey.value.trim() || submitted.value?.runId !== run.run_id) return
    if (task.id !== taskId || task.task_type !== 'structured_workflow'
      || task.resource_type !== 'workflow_run' || task.resource_id !== run.run_id) throw new Error('原任务读取结果与材料运行不一致')
    reconcileMaterialTaskAction(task, userStore.info.id)
    workflowRun.value = { ...run, state: task.state, task }
  } catch (failure) {
    if (current === generation && !clearDeniedResult(failure)) retryError.value = failure instanceof Error ? failure.message : '原任务读取失败'
  } finally {
    if (current === generation) { busy.value = false; void refreshRun() }
  }
}

async function recoverReviewReceipt(): Promise<void> {
  const run = workflowRun.value
  const taskId = receiptRecoveryTaskId.value
  const credential = apiKey.value.trim()
  const scope = receiptAttemptStorageKey.value
  if (!run || taskId === null || !run.task?.receipt_only_recovery?.available || !scope || materialTaskActionInFlight() || busy.value || reading.value || receiptConfirming.value || receiptAttemptKey.value) return
  const current = generation
  receiptConfirming.value = true
  try {
    await ElMessageBox.confirm('仅恢复已经收到的跨文件复核结果，不新增模型调用。服务端仍会核对当前授权、来源和原回执；输出校验拒绝时，汇总会失败，已完成文件成果保留。',
      '恢复原复核结果', { type: 'warning', confirmButtonText: '确认仅恢复', cancelButtonText: '取消' })
  } catch { return } finally { if (current === generation) receiptConfirming.value = false }
  if (materialTaskActionInFlight() || current !== generation || !workflowRun.value?.task?.receipt_only_recovery?.available
    || receiptRecoveryTaskId.value !== taskId || receiptAttemptStorageKey.value !== scope
    || credential !== apiKey.value.trim() || receiptAttemptKey.value) return
  const key = newRuntimeRequestId('material-receipt-recovery')
  receiptAttemptKey.value = key
  receiptAttempts.set(scope, key)
  try { sessionStorage.setItem(scope, key) } catch { /* Never repeat within this page if browser storage is unavailable. */ }
  const operation = ++generation
  invalidateRead()
  busy.value = true
  retryError.value = ''
  const flight = beginMaterialTaskActionFlight()
  try {
    if (run.task) retainMaterialTaskAction(run.task, userStore.info.id, { kind: 'receipt', attempt: run.task.attempt_count, key })
    const task = await taskApi.recoverReviewReceipt(credential, taskId, key)
    if (operation !== generation || credential !== apiKey.value.trim() || submitted.value?.runId !== run.run_id) return
    if (task.id !== taskId || task.task_type !== 'structured_workflow'
      || task.resource_type !== 'workflow_run' || task.resource_id !== run.run_id) throw new Error('恢复响应与原材料任务不一致，请只刷新原任务确认')
    reconcileMaterialTaskAction(task, userStore.info.id)
    workflowRun.value = { ...run, state: task.state, task }
    ElMessage.info(task.state === 'succeeded' ? '原任务已恢复，正在读取汇总结果'
      : task.state === 'failed' ? '汇总失败，已完成文件成果保留'
      : '恢复结果尚未确认，请只刷新原任务')
  } catch (failure) {
    if (operation === generation && !clearDeniedResult(failure)) retryError.value = failure instanceof Error
      ? failure.message : '恢复结果尚未确认，请只刷新原任务，不要再次提交'
  } finally {
    finishMaterialTaskActionFlight(flight)
    if (operation === generation) { busy.value = false; void refreshRun() }
  }
}

const retryOption = computed<{ target: 'failed_files' | 'cross_file_review'; label: string } | null>(() => {
  if (originalMaterialAction.value || materialChunkUnconfirmed.value) return null
  const run = workflowRun.value
  if (!run || run.run_id !== submitted.value?.runId || !Array.isArray(run.file_results)
    || run.task?.state !== 'failed' || run.task.task_type !== 'structured_workflow'
    || run.task.resource_type !== 'workflow_run' || run.task.resource_id !== run.run_id) return null
  const failed = run.file_states.filter(file => file.analysis_state === 'failed')
  if (failed.length) return { target: 'failed_files', label: `只重试 ${failed.length} 个失败文件` }
  if (run.file_states.length > 1 && run.file_states.every(file => file.analysis_state === 'succeeded')
    && ['pending', 'failed'].includes(run.cross_file_review?.state ?? '')) {
    return { target: 'cross_file_review', label: '只重试跨文件核对' }
  }
  return null
})

async function retryFailedPart(): Promise<void> {
  const run = workflowRun.value
  const option = retryOption.value
  const credential = apiKey.value.trim()
  if (!run?.task || !option || materialTaskActionInFlight() || busy.value || reading.value || !credential) return
  const taskId = run.task.id
  const snapshot = materialActionSnapshot(run)
  const current = ++generation
  invalidateRead()
  busy.value = true
  retryError.value = ''
  let flight: symbol | null = null
  try {
    const expectation = materialRetryExpectation(run, option.target)
    try {
      await ElMessageBox.confirm(`确认${option.label}？本次重试可能再次产生费用。`, '重试材料样本', {
        type: 'warning', confirmButtonText: '确认重试', cancelButtonText: '取消'
      })
    } catch (failure) {
      if (failure === 'cancel' || failure === 'close') return
      throw failure
    }
    if (materialTaskActionInFlight() || current !== generation || credential !== apiKey.value.trim()
      || submitted.value?.runId !== run.run_id || submitted.value.taskId !== taskId
      || retryOption.value?.target !== option.target) return
    flight = beginMaterialTaskActionFlight()
    const latest = await taskApi.readWorkflow(credential, run.run_id, props.environmentId)
    if (current !== generation || credential !== apiKey.value.trim()
      || submitted.value?.runId !== run.run_id || submitted.value.taskId !== taskId) return
    assertSelectedProfile(latest)
    if (materialActionSnapshot(latest) !== snapshot) {
      throw new Error('原失败文件、复核代次或任务状态已变化，本次重试未发送；请只读刷新原任务后重新确认')
    }
    retainMaterialTaskAction(run.task, userStore.info.id, { kind: 'retry', attempt: run.task.attempt_count, key: null })
    const task = await taskApi.retry(credential, taskId, option.target, expectation)
    if (current !== generation || credential !== apiKey.value.trim()) return
    if (task.id !== taskId || task.task_type !== 'structured_workflow'
      || task.resource_type !== 'workflow_run' || task.resource_id !== run.run_id) {
      throw new Error('重试返回的任务与材料样本不一致，请只读刷新原任务确认')
    }
    reconcileMaterialTaskAction(task, userStore.info.id)
    submitted.value = { runId: run.run_id, taskId, replayed: submitted.value?.replayed ?? false }
    workflowRun.value = null
    restoringRun.value = false
    refreshedCandidateRunId = 0
    ElMessage.success(`任务 #${taskId} 已重新入队`)
  } catch (failure) {
    if (current === generation && !clearDeniedResult(failure)) {
      retryError.value = failure instanceof Error ? failure.message : '材料样本重试结果未确认，请只读刷新原任务'
    }
  } finally {
    if (flight) finishMaterialTaskActionFlight(flight)
    if (current === generation) {
      busy.value = false
      void refreshRun()
    }
  }
}

function fileIds(): number[] {
  const parts = fileIdsText.value.split(/[,，\s]+/).filter(Boolean)
  if (parts.length < 1 || parts.length > 100 || parts.some(part => !/^[1-9][0-9]*$/.test(part))) {
    throw new Error('请输入 1–100 个正整数文件 ID')
  }
  const ids = parts.map(Number)
  if (ids.some(id => !Number.isSafeInteger(id)) || new Set(ids).size !== ids.length) {
    throw new Error('文件 ID 必须是不重复的正整数')
  }
  return ids
}

function addPreparedFile(fileId: number): void {
  if (busy.value || props.disabled || submitted.value || recoveryKey.value) return
  try {
    const ids = fileIdsText.value.trim() ? fileIds() : []
    if (ids.includes(fileId)) {
      ElMessage.info(`文件 #${fileId} 已在样本中`)
      return
    }
    if (ids.length >= 100) throw new Error('一个样本最多选择 100 个文件')
    fileIdsText.value = [...ids, fileId].join(', ')
    ElMessage.success(`文件 #${fileId} 已加入样本`)
  } catch (failure) {
    error.value = failure instanceof Error ? failure.message : '请先核对样本文件 ID'
  }
}

function signature(ids: number[]): string {
  return JSON.stringify({
    environmentId: props.environmentId, profileCode: props.profileCode,
    profileVersion: props.profileVersion, profileRevision: props.profileRevision, profileState: props.profileState,
    ids, scopeRef: scopeRef.value.trim(), snapshotRef: snapshotRef.value.trim(),
    snapshotVersion: snapshotVersion.value.trim()
  })
}

async function submit(): Promise<void> {
  if (busy.value || props.disabled || !canSubmit.value) return
  const credential = apiKey.value
  const environmentId = props.environmentId
  const profileCode = props.profileCode
  const profileVersion = props.profileVersion
  const profileRevision = props.profileRevision
  const profileState = props.profileState
  const inputFiles = fileIdsText.value
  const businessScope = {
    ref: scopeRef.value.trim(),
    snapshot_ref: snapshotRef.value.trim(),
    snapshot_version: snapshotVersion.value.trim()
  }
  const flight = beginMaterialTaskActionFlight()
  const current = ++generation
  invalidateRead()
  busy.value = true
  error.value = ''
  submitted.value = null
  try {
    const ids = fileIds()
    const currentSignature = signature(ids)
    if (pending?.signature !== currentSignature) {
      const files: MaterialSampleRequest['files'] = []
      for (let offset = 0; offset < ids.length; offset += 10) {
        const batch = await Promise.all(ids.slice(offset, offset + 10).map(id => fileApi.read(credential, id, environmentId)))
        if (current !== generation || apiKey.value !== credential || fileIdsText.value !== inputFiles
          || signature(ids) !== currentSignature) return
        for (const [index, file] of batch.entries()) {
          if (file.id !== ids[offset + index] || !Number.isSafeInteger(file.latest_parse?.id)) {
            throw new Error('读取的文件快照与所选文件 ID 不一致')
          }
          if (file.state !== 'parsed' || file.latest_parse?.state !== 'succeeded'
            || file.latest_parse.source_block_count < 1 || !/^[a-f0-9]{64}$/i.test(file.sha256)) {
            throw new Error(`文件 #${file.id} 尚未完成解析，不能作为材料样本`)
          }
          files.push({
            file_id: file.id, sha256: file.sha256.toLowerCase(),
            version_ref: `sand-ai-file:${file.id}:parse:${file.latest_parse.id}`
          })
        }
      }
      pending = {
        signature: currentSignature,
        key: recoveryKey.value || newRuntimeRequestId('material-sample'),
        body: {
          profile_revision: profileRevision,
          business_scope: businessScope,
          workflow: { code: 'material_analysis', version: '1.0.0' },
          files,
          output_schema: { code: 'sand_ai.material_analysis', version: '1.0.0' },
          analysis_profile: { code: profileCode, version: profileVersion },
          review_required: true
        }
      }
    }
    const request = pending
    if (current !== generation || apiKey.value !== credential || fileIdsText.value !== inputFiles
      || signature(ids) !== currentSignature) return
    saveRecoveryKey(request.key)
      const body = request.body
      const result =
        profileState === 'published'
          ? await materialApi.submitPublished(
              credential,
              {
                business_scope: body.business_scope,
                workflow: body.workflow,
                files: body.files,
                output_schema: body.output_schema,
                analysis_profile: body.analysis_profile,
                review_required: body.review_required
              },
              request.key,
              environmentId
            )
          : await materialApi.submitSample(credential, body, request.key, environmentId)
    if (current !== generation) return
    const runId = result.run?.run_id
    const taskId = result.run?.task?.id ?? null
    if (!Number.isSafeInteger(runId) || runId < 1 || (taskId !== null && (!Number.isSafeInteger(taskId) || taskId < 1))) {
      throw new Error('样本已提交但响应缺少有效运行标识，请用原请求重试')
    }
    submitted.value = { runId, taskId, replayed: result.idempotent_replay }
    saveRecoveryKey(request.key, runId)
    saveRuntimeBearer(credential)
    restoringRun.value = true
    pending = null
    recoveryChecked.value = true
    void refreshRun()
  } catch (failure) {
    if (current === generation && !clearDeniedResult(failure)) {
      error.value = failure instanceof SandAiRuntimeError && failure.errorCode === 'SAND_AI_SCHEMA_UNSUPPORTED'
        ? '样本配置未通过服务端校验。请返回上方核对材料分类（每项最多 2000 字）、实体类型（每项最多 64 字）、扩展字段代码及版本，明确修正空白、控制字符和换行；保存为新修订后再运行样本。页面不会自动改写旧配置。'
        : failure instanceof Error ? failure.message : '材料样本提交失败；结果未确认时请沿原请求键找回，不要重新创建付费请求。'
    }
  } finally {
    finishMaterialTaskActionFlight(flight)
    if (current === generation) busy.value = false
  }
}

function assertSelectedProfile(run: SandAiWorkflowRun): void {
  const profile = run.analysis_profile
  const original = recoveryIntent.value
  const target = original ? { code: original.profile_code, version: original.profile_version,
    revision: original.profile_revision, state: original.profile_state }
    : { code: props.profileCode, version: props.profileVersion, revision: props.profileRevision, state: props.profileState }
  if (!profile) throw new Error('原运行缺少可核验的冻结配置身份，不能在当前配置页恢复；请从任务页查看原运行。')
  if (run.workflow.code !== 'material_analysis' || profile.code !== target.code
    || profile.version !== target.version
    || (target.state === 'published'
      ? profile.origin !== 'published' || profile.revision !== null
      : profile.origin !== 'draft_sample' || !Number.isSafeInteger(profile.revision)
        || profile.revision < 1 || profile.revision !== target.revision)) {
    throw new Error('原运行的配置版本或草稿修订与当前选择不一致，请选择原配置后再恢复。')
  }
  if (!Number.isSafeInteger(run.run_id) || run.run_id < 1
    || (run.task !== null && (!Number.isSafeInteger(run.task.id) || run.task.id < 1
      || run.task.task_type !== 'structured_workflow' || run.task.resource_type !== 'workflow_run'
      || run.task.resource_id !== run.run_id))) {
    throw new Error('原运行与任务归属不一致')
  }
}

async function recover(): Promise<void> {
  if (busy.value || !recoveryKey.value || !apiKey.value.trim()) return
  const original = recoveryIntent.value
  if (!original || original.admin_id !== Number(userStore.info.id) || original.environment_id !== props.environmentId) return
  const current = ++generation
  invalidateRead()
  busy.value = true
  error.value = ''
  try {
      const run =
        original.profile_state === 'published'
          ? await materialApi.publishedByRequestKey(
              apiKey.value,
              recoveryKey.value,
              props.environmentId
            )
          : await materialApi.sampleByRequestKey(
              apiKey.value,
              recoveryKey.value,
              props.environmentId
            )
    if (current !== generation) return
    assertSelectedProfile(run)
    if (recoveryKey.value) saveRecoveryKey(recoveryKey.value, run.run_id)
    restoringRun.value = false
    submitted.value = { runId: run.run_id, taskId: run.task?.id ?? null, replayed: true }
    if (run.task) reconcileMaterialTaskAction(run.task, userStore.info.id)
    workflowRun.value = run
    if (run.task?.id === cancelUnconfirmedTaskId.value
      && run.task.task_type === 'structured_workflow' && run.task.resource_type === 'workflow_run'
      && run.task.resource_id === run.run_id && ['succeeded', 'failed', 'canceled'].includes(run.task.state)) {
      cancelUnconfirmedTaskId.value = null
    }
      submitted.value = {
        runId: run.run_id,
        taskId: run.task?.id ?? null,
        replayed: submitted.value?.replayed ?? true
      }
      rememberRun(run.run_id)
    refreshCandidate(run)
    schedulePoll(run)
    pending = null
    recoveryChecked.value = true
  } catch (failure) {
    if (current === generation) {
      if (!clearDeniedResult(failure)) {
        recoveryChecked.value = false
        error.value = failure instanceof Error ? failure.message : '找回原样本失败'
      }
    }
  } finally {
    if (current === generation) busy.value = false
  }
}

function startNew(): void {
  if (busy.value || reading.value || originalMaterialAction.value || recoveryUnresolved.value || restoringRun.value || recoveryStorageError.value || !workflowRun.value) return
  try { saveRecoveryKey('') } catch { return }
  invalidateRead()
  clearSource()
  pending = null
  submitted.value = null
  workflowRun.value = null
  restoringRun.value = false
  error.value = ''
  retryError.value = ''
  receiptConfirming.value = false
  cancelError.value = ''
  recoveryChecked.value = false
  refreshedCandidateRunId = 0
}

function stateLabel(state: string): string {
  return ({
    authorization_pending: '等待授权', queued: '排队中', running: '处理中', retrying: '重试中',
    recovery_pending: '等待确认', pending: '待处理', succeeded: '已完成',
    failed: '失败', canceled: '已取消', ambiguous: '结果待确认'
  } as Record<string, string>)[state] ?? state
}

function materialChunkStage(progress: SandAiMaterialChunkProgress): string {
  if (progress.unknown_node_count > 0 || progress.state === 'recovery_pending') return '原分块调用结果待确认'
  if (progress.state === 'failed') return '分段整理或归并失败'
  if (progress.state === 'canceled') return '分段处理已取消'
  if (progress.state === 'succeeded') return '分块结果已封存'
  return progress.completed_leaf_count < progress.leaf_count ? '正在分段整理' : '正在归并分段结果'
}

function materialCoverageLabel(state: SandAiMaterialChunkProgress['coverage_state']): string {
  return state === 'complete' ? '完整来源已覆盖'
    : state === 'incomplete' ? '未覆盖完整来源，文件尚未完成' : '完整来源覆盖尚未确认'
}

function costLabel(state: string): string {
  return ({
    priced: '已计价', unpriced: '未配置价格', unavailable: '暂不可确认',
    pending: '计量中', mixed_currency: '包含多种币种'
  } as Record<string, string>)[state] ?? state
}

function refreshCandidate(run: SandAiWorkflowRun): void {
    if (
      props.profileState === 'draft' &&
      run.state === 'succeeded' &&
      run.result &&
      refreshedCandidateRunId !== run.run_id
    ) {
    refreshedCandidateRunId = run.run_id
    emit('refresh-samples')
  }
}

function clearPoll(): void {
  if (pollTimer !== null) clearTimeout(pollTimer)
  pollTimer = null
}

function invalidateRead(): void {
  ++readGeneration
  clearPoll()
  reading.value = false
}

function clearSource(): void {
  ++sourceGeneration
  sourceOpen.value = false
  sourceLoading.value = false
  sourceError.value = ''
  sourcePreview.value = null
}

function clearDeniedResult(failure: unknown): boolean {
  if (!(failure instanceof SandAiRuntimeError)
    || (![401, 403].includes(failure.httpStatus) && failure.errorCode !== 'SAND_AI_ENVIRONMENT_MISMATCH')) return false
  ++generation
  invalidateRead()
  clearSource()
  submitted.value = null
  workflowRun.value = null
  restoringRun.value = false
  busy.value = false
  fileIdsText.value = ''
  scopeRef.value = ''
  snapshotRef.value = ''
  snapshotVersion.value = ''
  error.value = failure.message
  return true
}

function schedulePoll(run: SandAiWorkflowRun): void {
  clearPoll()
  // An explicit receipt attempt is followed only by deliberate reads of the original task/run.
  if (receiptAttemptKey.value || quotaPollingBlocked) return
  // Material receipt recovery requires an explicit action; cost settlement must not restart polling.
  if (run.state === 'recovery_pending' || run.task?.state === 'recovery_pending') return
  const active = ['authorization_pending', 'queued', 'running', 'retrying', 'pending'].includes(run.state)
  if (!submitted.value || submitted.value.runId !== run.run_id
    || document.visibilityState === 'hidden'
    || (!active && run.cost.state !== 'pending')) return
  pollTimer = setTimeout(() => { void refreshRun() }, 4000)
}

async function refreshRun(): Promise<void> {
  const runId = submitted.value?.runId
  if (reading.value || !runId || !apiKey.value.trim()) return
  clearPoll()
  const current = generation
  const currentRead = ++readGeneration
  reading.value = true
  try {
    const run = await taskApi.readWorkflow(apiKey.value, runId, props.environmentId)
    if (current !== generation || currentRead !== readGeneration || submitted.value?.runId !== runId) return
    if (run.run_id !== runId || run.workflow.code !== 'material_analysis') {
      throw new Error('读取的材料样本运行与提交结果不一致')
    }
    assertSelectedProfile(run)
    if (recoveryKey.value) saveRecoveryKey(recoveryKey.value, run.run_id)
    restoringRun.value = false
    quotaPollingBlocked = false
    error.value = ''
    if (run.task) reconcileMaterialTaskAction(run.task, userStore.info.id)
    workflowRun.value = run
    if (run.task?.id === cancelUnconfirmedTaskId.value
      && run.task.task_type === 'structured_workflow' && run.task.resource_type === 'workflow_run'
      && run.task.resource_id === run.run_id && ['succeeded', 'failed', 'canceled'].includes(run.task.state)) {
      cancelUnconfirmedTaskId.value = null
    }
      submitted.value = {
        runId: run.run_id,
        taskId: run.task?.id ?? null,
        replayed: submitted.value?.replayed ?? true
      }
      rememberRun(run.run_id)
    refreshCandidate(run)
    schedulePoll(run)
  } catch (failure) {
    if (current === generation && currentRead === readGeneration) {
      if (failure instanceof SandAiRuntimeError
        && (failure.httpStatus === 429 || failure.errorCode === 'SAND_AI_SERVICE_QUOTA_EXCEEDED')) {
        quotaPollingBlocked = true
      }
      clearDeniedResult(failure)
      error.value = failure instanceof Error ? failure.message : '读取材料样本结果失败'
    }
  } finally {
    if (current === generation && currentRead === readGeneration) reading.value = false
  }
}

async function openSource(sourceBlockId: number): Promise<void> {
  const runId = submitted.value?.runId
  if (!runId || !apiKey.value.trim()) return
  const current = ++sourceGeneration
  sourceOpen.value = true
  sourceLoading.value = true
  sourceError.value = ''
  sourcePreview.value = null
  try {
    const source = await taskApi.readWorkflowSource(apiKey.value, runId, sourceBlockId, props.environmentId)
    if (current === sourceGeneration && submitted.value?.runId === runId) sourcePreview.value = source
  } catch (failure) {
    if (current === sourceGeneration && !clearDeniedResult(failure)) {
      sourceError.value = failure instanceof Error ? failure.message : '读取来源失败'
    }
  } finally {
    if (current === sourceGeneration) sourceLoading.value = false
  }
}

  function rememberRun(runId: number): void {
    if (
      route.query.environment_id !== String(props.environmentId) ||
      route.query.material_profile_code !== props.profileCode ||
      route.query.material_profile_version !== props.profileVersion
    )
      return
    void router.replace({ query: { ...route.query, material_run_id: String(runId) } })
}

function goTask(taskId: number): void {
  void router.push({ path: '/sand-ai/task', query: { id: String(taskId) } })
}

function resetForIdentityChange(): void {
  fileIdsText.value = ''
  scopeRef.value = ''
  snapshotRef.value = ''
  snapshotVersion.value = ''
  quotaPollingBlocked = false
  ++generation
  invalidateRead()
  clearSource()
  pending = null
  submitted.value = null
  workflowRun.value = null
  restoringRun.value = false
  error.value = ''
  retryError.value = ''
  receiptConfirming.value = false
  cancelError.value = ''
  cancelConfirming.value = false
  cancelUnconfirmedTaskId.value = null
  busy.value = false
  refreshedCandidateRunId = 0
  recoveryKey.value = ''
  recoveryChecked.value = false
  recoveryReady.value = false
  recoveryStorageError.value = false
  recoveryIntents.value = []
  void loadRecoveryKey(apiKey.value.trim(), generation)
}

  watch(
    () => [
      props.environmentId,
      props.profileCode,
      props.profileVersion,
      props.profileRevision,
      props.profileState,
      userStore.info.id,
      apiKey.value
    ],
  resetForIdentityChange, { immediate: true, flush: 'sync' })

function requestedRunFromLocation(): number | undefined {
  const raw = route.query.material_run_id
  if (route.query.environment_id !== String(props.environmentId)
    || route.query.material_profile_code !== props.profileCode || route.query.material_profile_version !== props.profileVersion
    || typeof raw !== 'string' || !/^[1-9][0-9]*$/.test(raw)) return undefined
  const runId = Number(raw)
  return Number.isSafeInteger(runId) ? runId : undefined
}

watch(() => [route.query.environment_id, route.query.material_profile_code,
  route.query.material_profile_version, route.query.material_run_id], () => {
  if (submitted.value && submitted.value.runId === requestedRunFromLocation()) return
  // A new URL intent invalidates in-flight reads and old results. An unknown
  // cancellation stays blocked for its task within the unchanged identity.
  const unconfirmedCancel = cancelUnconfirmedTaskId.value
  resetForIdentityChange()
  fileIdsText.value = ''
  scopeRef.value = ''
  snapshotRef.value = ''
  snapshotVersion.value = ''
  cancelUnconfirmedTaskId.value = unconfirmedCancel
}, { flush: 'sync' })

  watch(
    () => [props.resumeRunId, apiKey.value.trim(), props.environmentId, props.profileCode, props.profileVersion, props.profileRevision, props.profileState, route.query.material_run_id],
    () => {
      const runId = props.resumeRunId
      if (
        !apiKey.value.trim() ||
        runId !== requestedRunFromLocation() ||
        busy.value ||
        pending !== null ||
        submitted.value?.runId === runId ||
        !runId ||
        !Number.isSafeInteger(runId) ||
        runId < 1
      )
        return
      if (submitted.value) resetForIdentityChange()
      restoringRun.value = true
      submitted.value = { runId, taskId: null, replayed: true }
      void refreshRun()
    },
    { immediate: true }
  )

watch(() => [props.environmentId, apiKey.value, props.profileCode, props.profileVersion], () => {
  fileIdsText.value = ''
  scopeRef.value = ''
  snapshotRef.value = ''
  snapshotVersion.value = ''
}, { flush: 'sync' })

watch(() => [userStore.info.id, userStore.accessToken], () => {
  if (apiKey.value) apiKey.value = ''
  else resetForIdentityChange()
}, { flush: 'sync' })

function onVisibilityChange(): void {
  if (document.visibilityState === 'hidden') clearPoll()
  else if (workflowRun.value) schedulePoll(workflowRun.value)
}

onMounted(() => document.addEventListener('visibilitychange', onVisibilityChange))
onUnmounted(() => {
  ++generation
  invalidateRead()
  clearSource()
  document.removeEventListener('visibilitychange', onVisibilityChange)
})
</script>

<style scoped>
.sample-runner { margin-top: 16px; }
.sample-runner h4 { margin: 0; }
.sample-runner .muted { color: var(--el-text-color-secondary); margin: 0 0 12px; }
.sample-runner .notice { margin-bottom: 12px; }
.sample-result { margin-top: 16px; }
</style>
