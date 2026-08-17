/**
 * SandAI 管理端类型（对齐 C-04 `sand-ai-api-contract.md`）。
 */

export type DataStatus = 1 | 2

export type InvocationState = 'queued' | 'running' | 'succeeded' | 'failed' | 'canceled'

export type ModelType = 'chat' | 'embedding' | 'rerank'

export type SandAiProvider = {
  id: number
  code: string
  name: string
  adapter: string
  timeout_ms: number
  has_encrypted_config: boolean
  status: DataStatus
  create_time: string
  update_time: string
}

export type SandAiProviderWrite = {
  id?: number
  code?: string
  name: string
  adapter: string
  encrypted_config?: Record<string, unknown>
  timeout_ms: number
  status: DataStatus
}

export type SandAiModel = {
  id: number
  code: string
  name: string
  type: ModelType | string
  capabilities: string[]
  status: DataStatus
  create_time: string
  update_time: string
}

export type SandAiModelWrite = {
  id?: number
  code?: string
  name: string
  type: ModelType
  capabilities: string[]
  status: DataStatus
}

export type SandAiInvocation = {
  id: number
  request_id: string
  environment_id: number
  model_id: number | null
  deployment_id: number | null
  state: InvocationState
  latency_ms: number | null
  error_code: string | null
  error_summary: string | null
  request_at: string
  completed_at: string | null
  status: DataStatus
  create_time: string
  update_time: string
}

export type SandAiUsage = {
  id: number
  invocation_id: number
  input_tokens: number
  output_tokens: number
  total_tokens: number
  cost_amount: string
  currency: string
  source: string
  status: DataStatus
  create_time: string
  update_time: string
}

export type SandAiAuditLog = {
  id: number
  actor_type: string
  actor_ref: string
  action: string
  resource_type: string
  resource_id: number | null
  summary: string
  status: DataStatus
  create_time: string
  update_time: string
}

export type SandAiPageQuery = {
  page?: number
  limit?: number
  code?: string
  name?: string
  status?: DataStatus | '' | number
  state?: InvocationState | ''
  action?: string
  request_id?: string
}

export type SandAiPage<T> = {
  current_page: number
  data: T[]
  per_page: number
  total: number
}

export type SandAiIdResult = {
  id: number
}

/** 文件/任务运行 API DTO（契约 1.4 §2.6） */

export type SandAiTaskState =
  | 'queued'
  | 'running'
  | 'retrying'
  | 'succeeded'
  | 'failed'
  | 'canceled'
  | string

export type SandAiTaskStep = {
  code: string
  state: SandAiTaskState
  attempt_count: number
  started_at: string | null
  completed_at: string | null
  error_code: string | null
  error_summary: string | null
}

export type SandAiSourceReference = {
  source_block_id: number
  file_id: number
  locator_type: string
  locator: Record<string, unknown>
}

export type SandAiTaskToolCall = {
  tool: string
  source_block_ids?: number[]
}

export type SandAiTaskResult = {
  output?: { content: string }
  invocation?: {
    request_id?: string
    state?: string
    latency_ms?: number | null
    error_code?: string | null
  }
  source_references?: SandAiSourceReference[]
  tool_calls?: SandAiTaskToolCall[]
  review_required?: boolean
}

export type SandAiTask = {
  id: number
  task_type: string
  resource_type: string
  resource_id: number
  file_parse_id: number | null
  state: SandAiTaskState
  attempt_count: number
  max_attempts: number
  available_at: string | null
  started_at: string | null
  completed_at: string | null
  canceled_at: string | null
  error_code: string | null
  error_summary: string | null
  steps?: SandAiTaskStep[]
  result?: SandAiTaskResult | null
}

export type SandAiTaskSubmitResult = {
  idempotent_replay: boolean
  task: SandAiTask
}

export type SandAiRetrievalMatch = {
  source_block_id: number
  file_id: number
  locator_type: string
  locator: Record<string, unknown>
  score: number
  excerpt: string
}

export type SandAiRetrievalResult = {
  query: string
  matches: SandAiRetrievalMatch[]
}

export type SandAiChatMessage = {
  role: 'system' | 'user' | 'assistant'
  content: string
}

export type SandAiFileParseState =
  | 'queued'
  | 'running'
  | 'succeeded'
  | 'failed'
  | 'canceled'
  | string

export type SandAiFileState =
  | 'uploaded'
  | 'available'
  | 'parsing'
  | 'parsed'
  | 'failed'
  | 'deleted'
  | string

export type SandAiFileParseSummary = {
  id: number
  parser_code: string
  parser_version: string
  state: SandAiFileParseState
  source_block_count: number
  error_code: string | null
  error_summary: string | null
  started_at: string | null
  completed_at: string | null
  task?: SandAiTask | null
}

export type SandAiFile = {
  id: number
  original_name: string
  extension: string
  media_type: string
  size_bytes: number
  sha256: string
  state: SandAiFileState
  latest_parse: SandAiFileParseSummary | null
  create_time: string
  update_time: string
}

export type SandAiParseSubmitResult = {
  idempotent_replay: boolean
  file_id: number
  parse: SandAiFileParseSummary
  task: SandAiTask
}
