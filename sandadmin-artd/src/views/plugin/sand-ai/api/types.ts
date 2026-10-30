import type { OperatorReviewCost } from './billing-types'

/**
 * SandAI 管理端类型（对齐 C-04 `sand-ai-api-contract.md`）。
 */

export type DataStatus = 1 | 2

export type InvocationState = 'queued' | 'running' | 'succeeded' | 'failed' | 'canceled'

export type ModelType = 'image_ocr' | 'chat' | 'embedding' | 'rerank' | 'audio_transcription' | 'audio_speech'

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

export type SandAiCostBreakdownItem = {
  meter_code: 'input_tokens' | 'output_tokens' | 'rerank_search_units' | 'rerank_tokens'
  quantity: string
  unit_price: string
  per_quantity: number
  metered_amount: string
}

export type SandAiRefundCostTotals = {
  state: 'bill_unavailable' | 'no_recorded_refund' | 'recorded_refunds'
  amount_basis: 'reconciled_bill'
  trust_basis: 'operator_reviewed'
  adjustment_count: number
  gross_billed_amount: string | null
  refund_amount: string | null
  net_billed_amount: string | null
  currency: string | null
}

export type SandAiProviderResponseIdentityItem = {
  kind: 'request_id' | 'response_id'
  source: 'header.x-request-id' | 'header.request-id' | 'body.id' | 'stream.id'
  value: string
  received_time: string
}

export type SandAiInvocationCost = {
  request_id: string
  provider_response_identity?: {
    state: 'not_observed' | 'observed' | 'conflicting'
    identities: SandAiProviderResponseIdentityItem[]
  }
  provider: string | null
  model: string | null
  deployment_id: number
  capability: string
  service_action: string | null
  source_kind: string | null
  usage: {
    state:
      | 'measured'
      | 'unmeasured'
      | 'not_dispatched'
      | 'failed'
      | 'ambiguous'
      | 'pending'
      | 'missing_event'
    // Optional only for compatibility with backends predating the breakdown DTO.
    metering_unit?: 'total_tokens' | 'rerank_search_units' | 'rerank_tokens' | null
    metering_quantity?: string | null
  }
  price: {
    revision_id: number | null
    estimated_amount: string | null
    // Optional for backends predating the frozen budget reservation projection.
    estimate_basis?: 'budget_reservation_upper_bound' | null
    estimate_state?: 'available' | 'unavailable' | 'held'
    estimate_reason?:
      | 'frozen_budget_reservation'
      | 'no_frozen_quote'
      | 'frozen_quote_unavailable'
      | 'frozen_quote_conflict'
    estimated_currency?: string | null
    estimated_price_revision_id?: number | null
    metered_amount: string | null
    pricing_state: 'priced' | 'unpriced'
    currency: string | null
    breakdown_state?: 'available' | 'unavailable' | 'held'
    breakdown?: SandAiCostBreakdownItem[] | null
  }
  tokens: {
    input_tokens: number | null
    output_tokens: number | null
    total_tokens: number | null
  }
  skill: { id: number; code: string; version_id: number; version: number; run_id: number } | null
  operator_review: OperatorReviewCost | null
  // Optional for backends predating the append-only refund projection.
  adjustments?: SandAiRefundCostTotals | null
  reconciliation: {
    state: 'not_applicable' | 'held' | 'pending' | 'unreconciled' | 'reconciled' | 'discrepancy'
    reason: string | null
    billed_amount: string | null
    reconciled_amount: string | null
    difference_amount: string | null
  }
}

export type SandAiUsageAllowedScope = {
  service_code: 'sand_ai'
  coverage: 'authorized_environment_subtotal'
  environments: Array<{ id: number; organization_id: number; application_id: number; name: string; code: string }>
}

export type SandAiUsage = {
  id: number
  invocation_id: number | null
  organization_id: number | null
  application_id: number | null
  environment_id: number
  provider_id: number | null
  model_id: number | null
  // Older installed payloads may omit association projections; absence remains unknown.
  service_code?: 'sand_ai'
  skill_id?: number | null
  skill_version_id?: number | null
  workflow_run_id?: number | null
  composition_run_id: number | null
  composition_node_code: string | null
  skill_run_id: number | null
  agent_definition_id: number | null
  agent_version_id: number | null
  channel_binding_id: number | null
  knowledge_base_version_id: number | null
  knowledge_query_id: number | null
  knowledge_index_id: number | null
  delivery_platform: 'feishu' | 'wecom' | null
  tool_run_id: number | null
  agent_run_id?: number | null
  knowledge_base_id?: number | null
  service_action: string
  source_kind: string
  capability_code: string
  metering_unit: string | null
  metering_quantity: string | null
  input_tokens: number | null
  output_tokens: number | null
  total_tokens: number | null
  usage_state: 'measured' | 'unmeasured'
  price_revision_id: number | null
  metered_amount: string | null
  currency: string | null
  pricing_state: 'priced' | 'unpriced'
  create_time: string
}

export type SandAiUsageQuery = {
  page?: number
  limit?: number
  from_time?: string
  to_time?: string
  reconciliation_state?: 'unreconciled' | 'reconciled' | 'discrepancy'
  environment_id?: number
  organization_id?: number
  workflow_run_id?: number
  composition_run_id?: number
  composition_node_code?: string
  skill_run_id?: number
  agent_definition_id?: number
  agent_version_id?: number
  channel_binding_id?: number
  knowledge_base_version_id?: number
  knowledge_query_id?: number
  knowledge_index_id?: number
  delivery_platform?: 'feishu' | 'wecom'
  agent_run_id?: number
  knowledge_base_id?: number
  service_code?: 'sand_ai'
  application_id?: number
  provider_id?: number
  model_id?: number
  skill_id?: number
  skill_version_id?: number
  source_kind?: string
  service_action?: string
  capability_code?: string
  usage_state?: 'measured' | 'unmeasured'
  pricing_state?: 'priced' | 'unpriced'
}

export type SandAiUsageSummaryQuery = {
  environment_id?: number
  from_time?: string
  to_time?: string
  organization_id?: number
  workflow_run_id?: number
  composition_run_id?: number
  composition_node_code?: string
  skill_run_id?: number
  agent_definition_id?: number
  agent_version_id?: number
  channel_binding_id?: number
  knowledge_base_version_id?: number
  knowledge_query_id?: number
  knowledge_index_id?: number
  delivery_platform?: 'feishu' | 'wecom'
  agent_run_id?: number
  knowledge_base_id?: number
  service_code?: 'sand_ai'
  application_id?: number
  provider_id?: number
  model_id?: number
  skill_id?: number
  skill_version_id?: number
  service_action?: string
  source_kind?: string
  capability_code?: string
  pricing_state?: 'priced' | 'unpriced'
  usage_state?: 'measured' | 'unmeasured'
  reconciliation_state?: 'unreconciled' | 'reconciled' | 'discrepancy'
}

export type SandAiUsageSummaryRow = {
  estimate?: {
    basis: 'budget_reservation_upper_bound'
    known_events: number
    unknown_events: number
    held_events: number
    amount: string | null
    currency: string | null
    known_amounts: { currency: string; amount: string }[]
  }
  dimension: string
  value: string | null
  currency: string | null
  total_events: number
  measured_events: number
  priced_events: number
  unmeasured_events: number
  unpriced_events: number
  known_metered_amount: string | null
  metered_amount: string | null
  business_failed_events: number
  business_failed_unknown_amount_events: number
  business_failed_known_amount: string | null
  business_failed_metered_amount: string | null
  failed_events: number
  failed_unknown_amount_events: number
  failed_known_amount: string | null
  failed_metered_amount: string | null
  reconciled_events: number
  discrepancy_events: number
  unreconciled_events: number
  reconciled_amount: string | null
  difference_amount: string | null
}

export type SandAiUsageSummaryGroup = {
  rows: SandAiUsageSummaryRow[]
  truncated: boolean
}

export type SandAiUsageSummary = {
  filters: { [Key in Exclude<keyof SandAiUsageSummaryQuery, 'from_time' | 'to_time'>]: Exclude<SandAiUsageSummaryQuery[Key], undefined> | null } & { from_time: string; to_time: string }
  scope: { service_code: 'sand_ai'; coverage: 'authorized_environment_subtotal'; environment_ids: number[] }
  by_service: SandAiUsageSummaryGroup
  overall: SandAiUsageSummaryGroup
  by_application: SandAiUsageSummaryGroup
  by_service_action: SandAiUsageSummaryGroup
  by_capability: SandAiUsageSummaryGroup
  by_skill: SandAiUsageSummaryGroup
  by_skill_version: SandAiUsageSummaryGroup
  by_provider: SandAiUsageSummaryGroup
  by_model: SandAiUsageSummaryGroup
  by_organization: SandAiUsageSummaryGroup
  by_environment: SandAiUsageSummaryGroup
  by_source_kind: SandAiUsageSummaryGroup
  by_workflow_run: SandAiUsageSummaryGroup
  by_composition_run: SandAiUsageSummaryGroup
  by_composition_node: SandAiUsageSummaryGroup
  by_skill_run: SandAiUsageSummaryGroup
  by_agent_definition: SandAiUsageSummaryGroup
  by_agent_version: SandAiUsageSummaryGroup
  by_channel_binding: SandAiUsageSummaryGroup
  by_delivery_platform: SandAiUsageSummaryGroup
  by_knowledge_base_version: SandAiUsageSummaryGroup
  by_knowledge_query: SandAiUsageSummaryGroup
  by_knowledge_index: SandAiUsageSummaryGroup
  by_agent_run: SandAiUsageSummaryGroup
  by_knowledge_base: SandAiUsageSummaryGroup
  ambiguous: { count: number | null; scope_state: 'verified' | 'association_unavailable'; held_amount: null; tokens: null; actual_amount: null }
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

export type SandAiChannelSource = SandAiSourceReference & {
  task_id: number
  content: string
  reference_kind: 'result' | 'stream_preview'
  event_no: number | null
}

export type SandAiTaskToolCall = {
  tool: string
  source_block_ids?: number[]
}

export type SandAiAudioLocator = {
  type: 'audio_time_range'
  start_ms: number
  end_ms: number
  granularity?: 'engine_segment' | 'input_range_only'
}

export type SandAiAudioSource = {
  locator: SandAiAudioLocator
}

export type SandAiAudioSegment = {
  start_ms: number
  end_ms: number
  speaker: string | null
  text: string
  confidence: string | null
  warnings: string[]
  source?: SandAiAudioSource
}

export type SandAiMeetingSummaryClaim = {
  text: string
  references: {
    segment_id: string
    source: SandAiAudioSource
  }[]
}

export type SandAiMeetingSummary = {
  schema: { code: 'sand_ai.meeting_summary'; version: '1.0.0' | '1.1.0' }
  summary: SandAiMeetingSummaryClaim[]
  key_points: SandAiMeetingSummaryClaim[]
  decisions: SandAiMeetingSummaryClaim[]
  action_items: SandAiMeetingSummaryClaim[]
  unresolved: SandAiMeetingSummaryClaim[]
  review_required: true
}

export type SandAiMeetingSummaryProgress = {
  planned_nodes: number
  completed_nodes: number
  failed_nodes: number
  active_node_code: string | null
  covered_segments: number
  total_segments: number
}

export type SandAiMeetingSummaryCost = {
  calls: {
    node_code: string
    invocation_id: number
    input_tokens: number | null
    output_tokens: number | null
    total_tokens: number | null
    price_revision_id: number | null
    metered_amount: string | null
    currency: string | null
    pricing_state: 'priced' | 'unpriced'
  }[]
  totals: {
    currency: string | null
    input_tokens: number | null
    output_tokens: number | null
    total_tokens: number | null
    metered_amount: string | null
  }[]
}

export type SandAiLegacyMeetingSummaryCost = {
  usage_state: string
  input_tokens: number | null
  output_tokens: number | null
  total_tokens: number | null
  pricing_state: string
  metered_amount: string | null
  currency: string | null
  price_revision_id: number | null
}

export type SandAiTaskResult = {
  schema?: { code: string; version: string }
  segments?: SandAiAudioSegment[]
  warnings?: string[]
  usage?: { audio_seconds: string }
  output?: { content: string }
  audio_task_id?: number
  audio_review_revision?: number
  meeting_summary?: SandAiMeetingSummary | null
  meeting_summary_error?: string | null
  unrecognized_audio_ranges?: { start_ms: number; end_ms: number }[]
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

export type SandAiContinuationReason = 'checkpoint_missing' | 'task_not_failed' | 'lease_active' | 'dispatch_unknown'
  | 'attempts_exhausted' | 'source_unavailable' | 'configuration_changed' | 'authorization_required'

export type SandAiCleanupState = 'retained' | 'pending' | 'failed' | 'purged' | 'unconfirmed'
export type SandAiCleanupStatus = {
  readonly state: SandAiCleanupState
  readonly completed_at: string | null
  readonly source_available: boolean
  readonly resources: {
    readonly file: SandAiCleanupState
    readonly derived_text: SandAiCleanupState
    readonly vectors: SandAiCleanupState | 'not_applicable'
    readonly audio_chunks?: SandAiCleanupState
    readonly results: SandAiCleanupState
    readonly usage_history: SandAiCleanupState
    readonly audit_history: SandAiCleanupState
  }
  readonly source_block_count: number | null
  readonly parse_record_count: number | null
}

export type SandAiTask = {
  readonly ocr_before_dispatch_retry?: {
    readonly target: 'file_parse_before_dispatch'
    readonly available: boolean
    readonly consumed: boolean
    readonly previous_attempt_count: number | null
    readonly retry_attempt_limit: number | null
  } | null
  readonly cleanup?: SandAiCleanupStatus
  readonly parse_restart?: { readonly allowed: true; readonly file_id: number; readonly task_id: number } | null
  readonly continuation?: { readonly available: boolean; readonly reason: SandAiContinuationReason | null }
  readonly authorization_continuation?: {
    readonly required: boolean
    readonly available: boolean
    readonly mode: 'authorization_continue'
    readonly reason: 'authorization_required' | 'lease_active' | 'source_unavailable' | null
  }
  readonly agent_definition_version?: { readonly code: string; readonly version_no: number; readonly snapshot_sha256: string }
  readonly readonly_tool_runs?: readonly { readonly run_id: number; readonly code: string; readonly version: number; readonly state: string; readonly review_required: boolean; readonly metering: { readonly unit: 'external_request'; readonly quantity: number; readonly pricing_state: 'unpriced'; readonly metered_amount: null; readonly reconciled_amount: null; readonly currency: null } | null }[]
  readonly provider_outcome_state?: null | 'unconfirmed'
  readonly streaming_enabled?: boolean
  readonly summary_invocation?: {
    readonly request_id: string
    readonly state: string
    readonly latency_ms: number | null
    readonly error_code: string | null
  } | null
  readonly summary_coverage?: {
    readonly version: 1
    readonly state: string
    readonly context_turn_limit: number
    readonly history_task_ids: readonly number[]
    readonly covered_task_ids: readonly number[]
    readonly partially_covered_task_ids: readonly number[]
    readonly retained_task_ids: readonly number[]
    readonly excluded_task_ids: readonly number[]
    readonly omitted_prior_turn_count: number | null
    readonly closed_after_task_id: number
  } | null
  readonly embedding_invocations?: readonly {
    readonly request_id: string
    readonly invocation: SandAiTask['embedding_invocation']
    readonly usage_cost: SandAiTask['embedding_usage_cost']
  }[]
  readonly agent_invocations?: readonly {
    readonly request_id: string
    readonly state: string
    readonly latency_ms: number | null
    readonly error_code: string | null
  }[]
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
  embedding_invocation?: {
    state: 'prepared' | 'dispatching' | 'ambiguous' | 'not_dispatched' | 'failed' | 'succeeded'
    error_code: string | null
  } | null
  embedding_usage_cost?: {
    usage_state: 'measured' | 'unmeasured'
    input_tokens: number | null
    output_tokens: number | null
    total_tokens: number | null
    pricing_state: 'priced' | 'unpriced'
    metered_amount: string | null
    currency: string | null
    price_revision_id: number | null
  } | null
  summary_progress?: SandAiMeetingSummaryProgress
  meeting_summary_cost?: SandAiMeetingSummaryCost | SandAiLegacyMeetingSummaryCost | null
  audio_usage_cost?: {
    state: 'priced' | 'unpriced' | 'unavailable'
    audio_seconds: string
    metered_amount: string | null
    currency: string | null
    price_revision_id: number | null
  }
}

export type SandAiTaskSubmitResult = {
  idempotent_replay: boolean
  task: SandAiTask
}

export type SandAiMaterialChunkProgress = {
  version: 'material_chunk_progress@1'
  state: 'running' | 'failed' | 'recovery_pending' | 'succeeded' | 'canceled'
  source_block_count: number
  source_character_count: number
  leaf_count: number
  merge_count: number
  completed_leaf_count: number
  completed_merge_count: number
  failed_node_count: number
  unknown_node_count: number
  coverage_state: 'pending' | 'complete' | 'incomplete'
}

export type SandAiWorkflowFileState = {
  file_id: number
  analysis_state: string
  attempt_count: number
  error_code: string | null
  failure_stage?: 'model_output_validation'
  failure_summary?: string
  chunk_progress?: SandAiMaterialChunkProgress
}

export type SandAiWorkflowFileResult = {
  file_id: number
  attempt_count: number
  paged?: true
  collection_counts?: SandAiMaterialCollectionCounts
  result: {
    schema: { code: string; version: string }
    payload: unknown
  }
  citations: Array<SandAiSourceReference & { field_path: string }>
}

export type SandAiMaterialResultSection =
  | 'documents'
  | 'entities'
  | 'events'
  | 'conflicts'
  | 'items_to_verify'
  | 'warnings'
  | 'extensions'

export type SandAiMaterialCollectionCounts = Record<Exclude<SandAiMaterialResultSection, 'extensions'>, number> & {
  extensions: Record<string, number>
}

export type SandAiMaterialFileResultPageQuery =
  | { section: Exclude<SandAiMaterialResultSection, 'extensions'>; offset: number; limit: number }
  | { section: 'extensions'; extension_field: string; offset: number; limit: number }

export type SandAiMaterialFileResultPage = {
  file_id: number
  schema: { code: string; version: string }
  section: SandAiMaterialResultSection
  extension_field: string | null
  offset: number
  limit: number
  total: number
  items: unknown[]
  citations: Array<SandAiSourceReference & { field_path: string }>
}

export type SandAiWorkflowSource = {
  source_block_id: number
  file_id: number
  locator_type: string
  locator: Record<string, unknown>
  content: string
  field_paths: string[]
}

export type SandAiMaterialProfileIdentity =
  | { code: string; version: string; origin: 'published'; revision: null }
  | { code: string; version: string; origin: 'draft_sample'; revision: number }

export type SandAiWorkflowRun = {
  analysis_profile: SandAiMaterialProfileIdentity | null
  run_id: number
  task: SandAiTask | null
  workflow: { code: string; version: string }
  state: string
  review_required: boolean
  artifact?: {
    review_required: boolean
  } | null
  result: {
    schema: { code: string; version: string }
    payload: unknown
  } | null
  citations: Array<SandAiSourceReference & { field_path: string }>
  usage: {
    state: string
    input_tokens: number | null
    output_tokens: number | null
    total_tokens: number | null
    attempt_count?: number
    event_count?: number
    missing_event_count?: number
    unmeasured_count?: number
  }
  cost: {
    state: string
    metered_amount: string | null
    currency: string | null
    unpriced_count?: number
    by_currency?: Record<string, string>
  }
  file_states: SandAiWorkflowFileState[]
  file_results?: SandAiWorkflowFileResult[]
  cross_file_review?: {
    state: string
    attempt_count: number
    error_code: string | null
  }
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
  | 'deleting'
  | 'delete_failed'
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
  readonly cleanup?: SandAiCleanupStatus
  id: number
  file_ref?: string
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
