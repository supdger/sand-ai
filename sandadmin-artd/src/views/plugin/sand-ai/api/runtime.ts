/**
 * SandAI 运行面 HTTP（Bearer workload context），与管理登录态隔离。
 * 契约：docs/development/sand-ai-api-contract.md §2.6 / §3 / §5.1（API 1.13）。
 */

import axios, { type AxiosResponse } from 'axios'
import type { BaseResponse } from '@/types'
import { ApiStatus } from '@/utils/http/status'
import { RUNTIME_API_PREFIX } from './config'

export type RuntimeErrorPayload = {
  error_code?: string
  retryable?: boolean
}

export class SandAiRuntimeError extends Error {
  readonly httpStatus: number
  readonly businessCode: number
  readonly errorCode: string | null
  readonly requestId: string | null
  readonly retryable: boolean

  constructor(params: {
    message: string
    httpStatus: number
    businessCode: number
    errorCode: string | null
    requestId: string | null
    retryable: boolean
  }) {
    super(params.message)
    this.name = 'SandAiRuntimeError'
    this.httpStatus = params.httpStatus
    this.businessCode = params.businessCode
    this.errorCode = params.errorCode
    this.requestId = params.requestId
    this.retryable = params.retryable
  }
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null
}

function readErrorPayload(data: unknown): RuntimeErrorPayload {
  if (!isRecord(data)) return {}
  const nested = data.data
  if (!isRecord(nested)) return {}
  return {
    error_code: typeof nested.error_code === 'string' ? nested.error_code : undefined,
    retryable: typeof nested.retryable === 'boolean' ? nested.retryable : undefined
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
    retryable: payload.retryable === true
  })
}

const runtimeClient = axios.create({
  timeout: 60000,
  baseURL: '',
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
    return unwrapData(response)
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

  throw new SandAiRuntimeError({
    message,
    httpStatus: response.status,
    businessCode,
    errorCode: payload.error_code ?? null,
    requestId,
    retryable: payload.retryable === true
  })
}
