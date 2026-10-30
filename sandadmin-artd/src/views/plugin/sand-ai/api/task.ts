import type { SandAiMaterialFileResultPage, SandAiMaterialFileResultPageQuery, SandAiTask, SandAiTaskSubmitResult, SandAiWorkflowRun, SandAiWorkflowSource } from './types'
import { runtimeRequest } from './runtime'

export interface ReceiptOnlyRecovery {
  target: 'cross_file_review'
  available: boolean
  attempt_count: number
  max_attempts: number
}

export type MeetingOutputCorrectionExpectation = {
  mode: 'meeting_output_correction'
  node_code: string
  expected_node_attempt: number
  expected_attempt_count: number
  expected_state: 'failed'
}

export type MeetingOutputCorrection = {
  available: boolean
  reason: 'duplicate_sources' | 'missing_sources' | 'not_available'
  reason_path?: string
  node_code?: string
  node_kind?: 'chunk' | 'merge'
  expected_node_attempt?: number
  expected_attempt_count?: number
  failure_code?: 'SAND_AI_AUDIO_SUMMARY_RESULT_INVALID'
}

export type TaskSummaryWithReceiptRecovery = SandAiTask & {
  receipt_only_recovery?: ReceiptOnlyRecovery
  meeting_output_correction?: MeetingOutputCorrection
}
export type WorkflowRunWithReceiptRecovery = Omit<SandAiWorkflowRun, 'task'> & {
  task: TaskSummaryWithReceiptRecovery | null
}

export type TaskActionExpectation = {
  expected_attempt_count: number
  expected_state: SandAiTask['state']
}

export type MaterialRetryExpectation = TaskActionExpectation & {
  expected_failed_files?: { file_id: number; attempt_count: number }[]
  expected_review_attempt_count?: number
}

export interface RuntimeChatModel {
  id: number
  code: string
  name: string
  type: string
  capabilities: string[]
}

export default {
  models(apiKey: string) {
    return runtimeRequest<{ data: RuntimeChatModel[] }>({
      method: 'GET',
      path: '/models',
      apiKey
    })
  },

  read(apiKey: string, taskId: number) {
    return runtimeRequest<TaskSummaryWithReceiptRecovery>({
      method: 'GET',
      path: `/tasks/${taskId}`,
      apiKey
    })
  },

  cancel(apiKey: string, taskId: number, expectation?: TaskActionExpectation) {
    return runtimeRequest<TaskSummaryWithReceiptRecovery>({
      method: 'POST',
      path: `/tasks/${taskId}/cancel`,
      apiKey,
      ...(expectation ? { data: expectation } : {})
    })
  },

  retry(apiKey: string, taskId: number, target?: 'failed_files' | 'cross_file_review', expectation?: MaterialRetryExpectation) {
    return runtimeRequest<TaskSummaryWithReceiptRecovery>({
      method: 'POST',
      path: `/tasks/${taskId}/retry`,
      apiKey,
      ...(target || expectation ? { data: { ...expectation, ...(target ? { target } : {}) } } : {})
    })
  },

  retryFileParseBeforeDispatch(apiKey: string, taskId: number, expectedAttemptCount: number, requestKey: string) {
    return runtimeRequest<TaskSummaryWithReceiptRecovery>({
      method: 'POST', path: `/tasks/${taskId}/retry`, apiKey,
      data: { target: 'file_parse_before_dispatch', expected_attempt_count: expectedAttemptCount, expected_state: 'failed' },
      headers: { 'Idempotency-Key': requestKey }
    })
  },

  correctMeetingOutput(apiKey: string, taskId: number, expectation: MeetingOutputCorrectionExpectation, requestKey: string) {
    return runtimeRequest<TaskSummaryWithReceiptRecovery>({
      method: 'POST', path: `/tasks/${taskId}/retry`, apiKey,
      data: expectation, headers: { 'Idempotency-Key': requestKey }
    })
  },

  continueAgent(apiKey: string, taskId: number, requestKey: string) {
    return runtimeRequest<SandAiTask>({
      method: 'POST',
      path: `/agents/tasks/${taskId}/continue`,
      apiKey,
      headers: { 'Idempotency-Key': requestKey }
    })
  },

  continueAuthorization(apiKey: string, taskId: number, requestKey: string) {
    return runtimeRequest<TaskSummaryWithReceiptRecovery>({
      method: 'POST',
      path: `/tasks/${taskId}/retry`,
      apiKey,
      data: { mode: 'authorization_continue' },
      headers: { 'Idempotency-Key': requestKey }
    })
  },

  recoverReviewReceipt(apiKey: string, taskId: number, requestKey: string) {
    return runtimeRequest<TaskSummaryWithReceiptRecovery>({
      method: 'POST',
      path: `/tasks/${taskId}/retry`,
      apiKey,
      data: { target: 'cross_file_review', mode: 'receipt_only' },
      headers: { 'Idempotency-Key': requestKey }
    })
  },

  readWorkflow(apiKey: string, runId: number, environmentId?: number) {
    return runtimeRequest<WorkflowRunWithReceiptRecovery>({
      method: 'GET',
      path: `/structured-workflows/runs/${runId}`,
      apiKey,
      headers: environmentId === undefined ? undefined : { 'X-Sand-AI-Environment-ID': String(environmentId) }
    })
  },

  readWorkflowSource(apiKey: string, runId: number, sourceBlockId: number, environmentId?: number) {
    return runtimeRequest<SandAiWorkflowSource>({
      method: 'GET',
      path: `/structured-workflows/runs/${runId}/sources/${sourceBlockId}`,
      apiKey,
      headers: environmentId === undefined ? undefined : { 'X-Sand-AI-Environment-ID': String(environmentId) }
    })
  },

  readMaterialFileResultPage(apiKey: string, runId: number, fileId: number, query: SandAiMaterialFileResultPageQuery, environmentId?: number) {
    return runtimeRequest<SandAiMaterialFileResultPage>({
      method: 'GET',
      path: `/structured-workflows/runs/${runId}/files/${fileId}/result`,
      apiKey,
      params: query,
      headers: environmentId === undefined ? undefined : { 'X-Sand-AI-Environment-ID': String(environmentId) }
    })
  },

  readMeetingSummaryByKey(apiKey: string, audioTaskId: number, requestKey: string) {
    return runtimeRequest<TaskSummaryWithReceiptRecovery>({
      method: 'GET', path: `/audio-transcriptions/${audioTaskId}/summary/by-request-key`,
      apiKey, params: { request_key: requestKey }
    })
  },

  submitMeetingSummary(apiKey: string, audioTaskId: number, model: string, reviewRevision: number, idempotencyKey: string) {
    return runtimeRequest<SandAiTaskSubmitResult>({
      method: 'POST',
      path: `/audio-transcriptions/${audioTaskId}/summary`,
      apiKey,
      data: { model, review_revision: reviewRevision },
      headers: { 'Idempotency-Key': idempotencyKey }
    })
  }
}
