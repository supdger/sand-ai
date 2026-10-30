/**
 * SandAI 运行面 HTTP（Bearer workload context），与管理登录态隔离。
 * 契约：docs/development/sand-ai-api-contract.md §2.6 / §3 / §5.1（API 1.13）。
 */

import axios, { type AxiosResponse } from 'axios'
import type { BaseResponse } from '@/types'
import { ApiStatus } from '@/utils/http/status'
import { RUNTIME_API_PREFIX } from './config'
import {
  nextRuntimeIdentityRequestSequence,
  notifyRuntimeIdentityAccepted,
  notifyRuntimeIdentityRefusal
} from './runtimeIdentityNotice'

export type RuntimeDraftNotSubmitted = {
  state: 'not_submitted'
  operation: 'draft'
  request_id: string
  scope: { type: 'personal' | 'team' | 'organization'; ref: string }
  actor_ref: string
  target: { code: string | null; expected_draft_version: number | null }
  proof: 'fresh_locked_schema_validation'
}

export type RuntimeErrorPayload = {
  error_code?: string
  retryable?: boolean
  submission?: RuntimeDraftNotSubmitted
}

export class SandAiRuntimeError extends Error {
  readonly httpStatus: number
  readonly businessCode: number
  readonly errorCode: string | null
  readonly requestId: string | null
  readonly retryable: boolean
  readonly submission: RuntimeDraftNotSubmitted | null

  constructor(params: {
    message: string
    httpStatus: number
    businessCode: number
    errorCode: string | null
    requestId: string | null
    retryable: boolean
    submission?: RuntimeDraftNotSubmitted | null
  }) {
    super(params.message)
    this.name = 'SandAiRuntimeError'
    this.httpStatus = params.httpStatus
    this.businessCode = params.businessCode
    this.errorCode = params.errorCode
    this.requestId = params.requestId
    this.retryable = params.retryable
    this.submission = params.submission ?? null
  }
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null
}

function readDraftSubmission(value: unknown): RuntimeDraftNotSubmitted | undefined {
  if (!isRecord(value) || Array.isArray(value) || value.state !== 'not_submitted'
    || value.operation !== 'draft' || value.proof !== 'fresh_locked_schema_validation'
    || typeof value.request_id !== 'string' || !value.request_id
    || !isRecord(value.scope) || Array.isArray(value.scope)
    || !['personal', 'team', 'organization'].includes(String(value.scope.type))
    || typeof value.scope.ref !== 'string' || !value.scope.ref
    || typeof value.actor_ref !== 'string' || !value.actor_ref
    || !isRecord(value.target) || Array.isArray(value.target)
    || (value.target.code !== null && typeof value.target.code !== 'string')
    || (value.target.expected_draft_version !== null
      && (typeof value.target.expected_draft_version !== 'number'
        || !Number.isSafeInteger(value.target.expected_draft_version) || value.target.expected_draft_version < 1)))
    return undefined
  const scopeType = value.scope.type
  if (scopeType !== 'personal' && scopeType !== 'team' && scopeType !== 'organization') return undefined
  return {
    state: 'not_submitted', operation: 'draft', request_id: value.request_id,
    scope: { type: scopeType, ref: value.scope.ref }, actor_ref: value.actor_ref,
    target: { code: value.target.code, expected_draft_version: value.target.expected_draft_version },
    proof: 'fresh_locked_schema_validation'
  }
}

function readErrorPayload(data: unknown): RuntimeErrorPayload {
  if (!isRecord(data)) return {}
  const nested = data.data
  if (!isRecord(nested)) return {}
  return {
    error_code: typeof nested.error_code === 'string' ? nested.error_code : undefined,
    retryable: typeof nested.retryable === 'boolean' ? nested.retryable : undefined,
    submission: readDraftSubmission(nested.submission)
  }
}

function unwrapData<T>(response: AxiosResponse<BaseResponse<T>>): T {
  const body = response.data
  if (!isRecord(body)) {
    throw new SandAiRuntimeError({
      message: '运行面响应无效',
      httpStatus: response.status,
      businessCode: ApiStatus.error,
      errorCode: null,
      requestId: null,
      retryable: false
    })
  }
  const code = typeof body.code === 'number' ? body.code : ApiStatus.error
  const message = typeof body.message === 'string' ? body.message : '请求失败'
  const requestId = typeof body.request_id === 'string' ? body.request_id : null
  if (code === ApiStatus.success) {
    return body.data as T
  }
  const payload = readErrorPayload(body)
  throw new SandAiRuntimeError({
    message,
    httpStatus: response.status,
    businessCode: code,
    errorCode: payload.error_code ?? null,
    requestId,
    retryable: payload.retryable === true,
    submission: payload.submission ?? null
  })
}

const runtimeClient = axios.create({
  timeout: 60000,
  baseURL: import.meta.env.VITE_API_URL || '',
  validateStatus: () => true
})

export function newRuntimeRequestId(prefix = 'ui'): string {
  return `${prefix}-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`
}

export async function runtimeRequest<T>(options: {
  method: 'GET' | 'POST' | 'DELETE'
  path: string
  apiKey: string
  data?: FormData | Record<string, unknown>
  params?: Record<string, string | number>
  headers?: Record<string, string>
}): Promise<T> {
  const key = options.apiKey.trim()
  if (!key) {
    throw new SandAiRuntimeError({
      message: '缺少运行面 Bearer',
      httpStatus: 0,
      businessCode: ApiStatus.error,
      errorCode: 'SAND_AI_AUTHENTICATION_FAILED',
      requestId: null,
      retryable: false
    })
  }

  const identityRequestSequence = nextRuntimeIdentityRequestSequence()
  const response = await runtimeClient.request<BaseResponse<T>>({
    method: options.method,
    url: `${RUNTIME_API_PREFIX}${options.path}`,
    params: options.params,
    data: options.data,
    headers: {
      Authorization: `Bearer ${key}`,
      ...(options.headers ?? {})
    }
  })

  if (response.status >= 200 && response.status < 300) {
    try {
      const result = unwrapData(response)
      notifyRuntimeIdentityAccepted(key, identityRequestSequence)
      return result
    } catch (error: unknown) {
      if (error instanceof SandAiRuntimeError) {
        notifyRuntimeIdentityRefusal(key, error.errorCode, identityRequestSequence)
      }
      throw error
    }
  }

  const payload = readErrorPayload(response.data)
  const message =
    isRecord(response.data) && typeof response.data.message === 'string'
      ? response.data.message
      : `HTTP ${response.status}`
  const requestId =
    isRecord(response.data) && typeof response.data.request_id === 'string'
      ? response.data.request_id
      : null
  const businessCode =
    isRecord(response.data) && typeof response.data.code === 'number'
      ? response.data.code
      : response.status

  const error = new SandAiRuntimeError({
    message,
    httpStatus: response.status,
    businessCode,
    errorCode: payload.error_code ?? null,
    requestId,
    retryable: payload.retryable === true,
    submission: payload.submission ?? null
  })
  notifyRuntimeIdentityRefusal(key, error.errorCode, identityRequestSequence)
  throw error
}

/** Private media uses the same transport base and workload identity as JSON APIs. */
export async function runtimeBlobRequest(apiKey: string, path: string): Promise<Blob> {
  const key = apiKey.trim()
  if (!key) {
    throw new SandAiRuntimeError({
      message: '缺少运行面 Bearer',
      httpStatus: 0,
      businessCode: ApiStatus.error,
      errorCode: 'SAND_AI_AUTHENTICATION_FAILED',
      requestId: null,
      retryable: false
    })
  }
  const identityRequestSequence = nextRuntimeIdentityRequestSequence()
  const response = await runtimeClient.get<Blob>(`${RUNTIME_API_PREFIX}${path}`, {
    responseType: 'blob',
    headers: { Authorization: `Bearer ${key}`, 'Cache-Control': 'no-store' }
  })
  if (response.status >= 200 && response.status < 300) {
    notifyRuntimeIdentityAccepted(key, identityRequestSequence)
    return response.data
  }

  let body: unknown = null
  try {
    body = JSON.parse(await response.data.text())
  } catch {
    // An empty/non-JSON denial still retains its HTTP status for access cleanup.
  }
  const payload = readErrorPayload(body)
  const error = new SandAiRuntimeError({
    message:
      isRecord(body) && typeof body.message === 'string' ? body.message : `HTTP ${response.status}`,
    httpStatus: response.status,
    businessCode: isRecord(body) && typeof body.code === 'number' ? body.code : response.status,
    errorCode: payload.error_code ?? null,
    requestId: isRecord(body) && typeof body.request_id === 'string' ? body.request_id : null,
    retryable: payload.retryable === true,
    submission: payload.submission ?? null
  })
  notifyRuntimeIdentityRefusal(key, error.errorCode, identityRequestSequence)
  throw error
}
