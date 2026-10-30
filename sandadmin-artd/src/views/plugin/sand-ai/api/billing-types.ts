export type BillingUsageTrace = { usage_event_id: number; environment_id: number; invocation_id: number }

export type BillingImportLineInput = {
  evidence_locator?: string
  external_line_id: string
  model_code: string
  capability_code: string
  meter_code: string
  quantity: string
  amount: string
  currency: string
  occurred_start: string
  occurred_end: string
}
export type BillingImportInput = {
  evidence_id?: number
  provider_id: number
  period_start: string
  period_end: string
  idempotency_key: string
  source_kind: string
  reference: string
  lines: BillingImportLineInput[]
}
export type BillingBatch = {
  id: number
  provider_id: number
  period_start: string
  period_end: string
  source_kind: string
  reference: string
  state: string
  create_time: string
}
export type BillingSourceEvidence = {
  evidence_id: number
  sha256: string
  evidence_locator: string
  locator_basis: 'operator_declared'
}
export type BillingLine = BillingImportLineInput & {
  source_evidence?: BillingSourceEvidence | null
  billing_line_id: number
  is_duplicate?: boolean
  operator_review_state?: BillingReview['comparison_state'] | null
  identity_state: string
  trace?: BillingUsageTrace | null
  reconciliation: null | {
    state: string
    usage_event_id: number | null
    reason: string
    quantity_difference: string | null
    amount_difference: string | null
    billed_amount: string
    currency: string
  }
}
export type BillingDetail = Omit<BillingBatch, 'create_time'> & { lines: BillingLine[] }
export type BillingEvidence = {
  id: number
  provider_id: number
  sha256: string
  byte_size: number
  media_type: 'application/pdf' | 'text/plain' | 'text/csv' | 'application/csv'
    | 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
  trust_basis: 'operator_supplied'
  state: 'available' | 'upload_pending' | 'clearing' | 'evidence_unavailable'
}
export type BillingReview = {
  trace?: BillingUsageTrace | null
  id: number
  billing_line_id: number
  evidence_id: number
  admin_id: number
  usage_event_id: number | null
  environment_id: number | null
  idempotency_key: string
  evidence_locator: string
  granularity: 'request' | 'aggregate'
  decision: 'compare' | 'reject' | 'unresolved'
  note: string
  trust_basis: 'operator_reviewed'
  locator_basis: 'operator_declared'
  comparison_state:
    | 'reviewed_match'
    | 'reviewed_discrepancy'
    | 'unmatched'
    | 'aggregate_only'
    | 'rejected'
    | 'unresolved'
  reason: string
  quantity_difference: string | null
  amount_difference: string | null
  create_time: string
  evidence_sha256: string
  evidence_state: 'available' | 'evidence_unavailable'
}
export type OperatorReviewCost = Pick<
  BillingReview,
  | 'trust_basis'
  | 'comparison_state'
  | 'reason'
  | 'quantity_difference'
  | 'amount_difference'
  | 'evidence_state'
>
export type BillingReviewInput = {
  billing_line_id: number
  evidence_id: number
  evidence_locator: string
  granularity: 'request' | 'aggregate'
  decision: 'compare' | 'reject' | 'unresolved'
  idempotency_key: string
  note: string
} & (
  | { environment_id: number; usage_event_id: number; request_evidence_confirmed: true }
  | {
      environment_id?: never
      usage_event_id?: never
      request_evidence_confirmed?: never
    }
)

function isRecord(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value)
}
function object(value: unknown): Record<string, unknown> {
  if (!isRecord(value)) throw new Error('账单响应格式不完整')
  return value
}
function string(value: unknown): string {
  if (typeof value !== 'string') throw new Error('账单文本字段不完整')
  return value
}
function integer(value: unknown): number {
  if (typeof value !== 'number' || !Number.isSafeInteger(value) || value < 0)
    throw new Error('账单标识字段不完整')
  return value
}
function nullableString(value: unknown): string | null {
  return value === null ? null : string(value)
}
function nullableInteger(value: unknown): number | null {
  return value === null ? null : integer(value)
}
function optionalBoolean(value: unknown): boolean | undefined {
  if (value === undefined) return undefined
  if (typeof value !== 'boolean') throw new Error('账单引用状态不完整')
  return value
}
function choice<T extends string>(value: unknown, choices: readonly T[]): T {
  for (const item of choices) if (value === item) return item
  throw new Error('账单状态字段不完整')
}
export function list<T>(value: unknown, parse: (row: unknown) => T): T[] {
  if (!Array.isArray(value)) throw new Error('账单列表格式不完整')
  return value.map(parse)
}
function usageTrace(value: unknown): BillingUsageTrace | null | undefined {
  if (value === undefined) return undefined
  if (value === null) return null
  const v = object(value)
  const trace = { usage_event_id: integer(v.usage_event_id), environment_id: integer(v.environment_id), invocation_id: integer(v.invocation_id) }
  if (Object.values(trace).some((id) => id < 1)) throw new Error('账单调用追溯标识不完整')
  return trace
}

function header(value: unknown): Omit<BillingBatch, 'create_time'> {
  const v = object(value)
  return {
    id: integer(v.id),
    provider_id: integer(v.provider_id),
    period_start: string(v.period_start),
    period_end: string(v.period_end),
    source_kind: string(v.source_kind),
    reference: string(v.reference),
    state: string(v.state)
  }
}
export function batch(value: unknown): BillingBatch {
  const v = object(value)
  return { ...header(v), create_time: string(v.create_time) }
}
export function detail(value: unknown): BillingDetail {
  const v = object(value)
  return {
    ...header(v),
    lines: list(v.lines, (item) => {
      const row = object(item)
      const r = row.reconciliation === null ? null : object(row.reconciliation)
      return {
        source_evidence: sourceEvidence(row.source_evidence),
        trace: usageTrace(row.trace),
        billing_line_id: integer(row.billing_line_id),
        is_duplicate: optionalBoolean(row.is_duplicate),
        operator_review_state: row.operator_review_state === undefined
          ? undefined
          : row.operator_review_state === null
            ? null
            : choice(row.operator_review_state, [
                'reviewed_match', 'reviewed_discrepancy', 'unmatched',
                'aggregate_only', 'rejected', 'unresolved'
              ] as const),
        external_line_id: string(row.external_line_id),
        identity_state: string(row.identity_state),
        model_code: string(row.model_code),
        capability_code: string(row.capability_code),
        meter_code: string(row.meter_code),
        quantity: string(row.quantity),
        amount: string(row.amount),
        currency: string(row.currency),
        occurred_start: string(row.occurred_start),
        occurred_end: string(row.occurred_end),
        reconciliation:
          r === null
            ? null
            : {
                state: string(r.state),
                usage_event_id: nullableInteger(r.usage_event_id),
                reason: string(r.reason),
                quantity_difference: nullableString(r.quantity_difference),
                amount_difference: nullableString(r.amount_difference),
                billed_amount: string(r.billed_amount),
                currency: string(r.currency)
              }
      }
    })
  }
}
function sourceEvidence(value: unknown): BillingSourceEvidence | null | undefined {
  if (value === undefined || value === null) return value
  const row = object(value)
  const id = integer(row.evidence_id)
  const sha = string(row.sha256)
  const locator = string(row.evidence_locator)
  if (id < 1 || !/^[a-f0-9]{64}$/.test(sha) || !locator || [...locator].length > 256 ||
      /[\x00-\x1f\x7f-\x9f]/.test(locator))
    throw new Error('账单原件关联不完整')
  return { evidence_id: id, sha256: sha, evidence_locator: locator,
    locator_basis: choice(row.locator_basis, ['operator_declared']) }
}
export function evidence(value: unknown): BillingEvidence {
  const v = object(value)
  return {
    id: integer(v.id),
    provider_id: integer(v.provider_id),
    sha256: string(v.sha256),
    byte_size: integer(v.byte_size),
    media_type: choice(v.media_type, [
      'application/pdf',
      'text/plain',
      'text/csv',
      'application/csv',
      'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
    ]),
    trust_basis: choice(v.trust_basis, ['operator_supplied']),
    state: choice(v.state, ['available', 'upload_pending', 'clearing', 'evidence_unavailable'])
  }
}
export function review(value: unknown): BillingReview {
  const v = object(value)
  return {
    trace: usageTrace(v.trace),
    id: integer(v.id),
    billing_line_id: integer(v.billing_line_id),
    evidence_id: integer(v.evidence_id),
    admin_id: integer(v.admin_id),
    usage_event_id: nullableInteger(v.usage_event_id),
    environment_id: nullableInteger(v.environment_id),
    idempotency_key: string(v.idempotency_key),
    evidence_locator: string(v.evidence_locator),
    granularity: choice(v.granularity, ['request', 'aggregate']),
    decision: choice(v.decision, ['compare', 'reject', 'unresolved']),
    note: string(v.note),
    trust_basis: choice(v.trust_basis, ['operator_reviewed']),
    locator_basis: choice(v.locator_basis, ['operator_declared']),
    comparison_state: choice(v.comparison_state, [
      'reviewed_match',
      'reviewed_discrepancy',
      'unmatched',
      'aggregate_only',
      'rejected',
      'unresolved'
    ]),
    reason: string(v.reason),
    quantity_difference: nullableString(v.quantity_difference),
    amount_difference: nullableString(v.amount_difference),
    create_time: string(v.create_time),
    evidence_sha256: string(v.evidence_sha256),
    evidence_state: choice(v.evidence_state, ['available', 'evidence_unavailable'])
  }
}
export function result<T>(
  value: unknown,
  field: string,
  parse: (row: unknown) => T
): { idempotent_replay: boolean; value: T } {
  const v = object(value)
  if (typeof v.idempotent_replay !== 'boolean') throw new Error('账单保存回执不完整')
  return { idempotent_replay: v.idempotent_replay, value: parse(v[field]) }
}
export function clearResult(value: unknown): { cleared: boolean; evidence: BillingEvidence } {
  const v = object(value)
  if (typeof v.cleared !== 'boolean') throw new Error('原件清除回执不完整')
  return { cleared: v.cleared, evidence: evidence(v.evidence) }
}
export function reviewLabel(state: BillingReview['comparison_state']): string {
  const labels: Record<BillingReview['comparison_state'], string> = {
    reviewed_match: '人工核对一致',
    reviewed_discrepancy: '人工核对有差异',
    unmatched: '用量或价格尚不完整，未能比较',
    aggregate_only: '仅期间记录',
    rejected: '已拒绝',
    unresolved: '待核查'
  }
  return labels[state]
}

export function uploadResult(value: unknown): {
  idempotent_replay: boolean
  upload_complete: boolean
  value: BillingEvidence
} {
  const v = object(value)
  const parsed = result(value, 'evidence', evidence)
  if (
    typeof v.upload_complete !== 'boolean' ||
    (v.upload_complete && parsed.value.state !== 'available')
  )
    throw new Error('原件上传回执不完整')
  return { ...parsed, upload_complete: v.upload_complete }
}
export function evidenceLabel(state: BillingEvidence['state']): string {
  const labels: Record<BillingEvidence['state'], string> = {
    available: '人工提供原件可用',
    upload_pending: '上传尚未完成',
    clearing: '原件清除尚未完成',
    evidence_unavailable: '原件不可用'
  }
  return labels[state]
}

export function reconciliationLabel(state: string | null | undefined): string {
  const labels: Readonly<Record<string, string>> = { reconciled: '自动核对一致', discrepancy: '自动核对有差异', ambiguous: '多个候选，无法唯一关联', unmatched: '尚未匹配' }
  return state ? labels[state] ?? `核对状态：${state}` : '未核对'
}
export function reconciliationReason(reason: string | null | undefined): string {
  const labels: Readonly<Record<string, string>> = {
    exact_match: '数量与计量金额一致', meter_or_amount_difference: '计量口径、数量或金额存在差异',
    no_candidate: '没有满足服务商、模型、能力、币种和时间条件的用量', multiple_candidates: '存在多个候选，未摊分账单',
    candidate_limit_exceeded: '候选过多，需要缩小账期', usage_unmeasured: '用量尚未测得，差额未知',
    usage_unpriced: '计量价格或金额未知，差额未知', identity_unverified: '来源身份未核验，不自动对账',
    unsupported_meter: '期间聚合或此计量口径不支持单次对账'
  }
  return reason ? labels[reason] ?? `核对说明：${reason}` : '尚无核对说明'
}
