/**
 * SandAI 管理端页面级错误兜底（H1）。
 *
 * 覆盖三类来源：
 * - Vue 渲染/生命周期异常（SandAiErrorBoundary 的 onErrorCaptured，等同页面级 errorHandler）；
 * - window `error` 事件（含脚本/分包等资源加载失败）；
 * - `unhandledrejection`（含动态 import、vite import-analysis/chunk load 类失败，以及未设
 *   router.onError 时冒泡出来的路由导航异常）。
 *
 * 界面与「复制问题信息」只含脱敏字段：错误码、时间、版本、请求编号（如可得）。
 * 绝不回显绝对路径、堆栈或任何密钥/凭证内容。
 */

import { isHttpError } from '@/utils/http/error'
import { SAND_AI_PLUGIN_VERSION } from '../api/config'
import { SandAiRuntimeError } from '../api/runtime'

export interface BoundaryFault {
  /** 脱敏后的稳定错误码（仅用于界面分支与复制信息） */
  code: string
  /** 事件发生时间（ISO 8601） */
  time: string
  /** 后端请求编号（仅当错误对象自带且字符安全） */
  requestId?: string
  /** 是否资源/分包加载失败（仅此类型允许有界自动重载） */
  resource: boolean
}

/** 契约内稳定码：仅 SAND_AI_ / SAND_IAM_ 前缀可进入复制信息。 */
const STABLE_CODE = /\b(SAND_AI_[A-Z0-9_]+|SAND_IAM_[A-Z0-9_]+)\b/

/** 请求编号仅接受字母数字/中划线/下划线，避免把异常文本带进复制信息。 */
const SAFE_REQUEST_ID = /^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/

/** 动态 import / vite import-analysis / 分包加载失败特征。 */
const RESOURCE_FAILURE =
  /(dynamically imported module|error loading dynamically|failed to resolve import|import-analysis|chunkloaderror|loading chunk [\w-]+ failed|module script)/i

function rawMessage(cause: unknown): string {
  if (cause instanceof Error) return `${cause.name}: ${cause.message}`
  if (typeof cause === 'string') return cause
  return ''
}

export function isResourceLoadFailure(cause: unknown): boolean {
  // window error 事件的资源加载失败（script/link/img 等）：无 error 但带 target
  if (typeof cause === 'object' && cause !== null) {
    const event = cause as ErrorEvent & { target?: EventTarget | null }
    if ('target' in event && event.target != null && !(event as ErrorEvent).error) {
      return true
    }
  }
  return RESOURCE_FAILURE.test(rawMessage(cause))
}

function faultCode(cause: unknown): string {
  if (cause instanceof SandAiRuntimeError && cause.errorCode) return cause.errorCode
  if (cause instanceof Error && isHttpError(cause)) return `HTTP_${cause.code}`
  const stable = STABLE_CODE.exec(rawMessage(cause))?.[0]
  if (stable) return stable
  if (isResourceLoadFailure(cause)) return 'RESOURCE_LOAD_FAILED'
  return 'UI_ERROR'
}

function extractRequestId(cause: unknown): string | undefined {
  if (typeof cause !== 'object' || cause === null) return undefined
  const record = cause as { requestId?: unknown; request_id?: unknown }
  for (const value of [record.requestId, record.request_id]) {
    if (typeof value === 'string' && SAFE_REQUEST_ID.test(value)) return value
    if (typeof value === 'number' && Number.isSafeInteger(value)) return String(value)
  }
  return undefined
}

export function describeBoundaryFault(cause: unknown): BoundaryFault {
  const fault: BoundaryFault = {
    code: faultCode(cause),
    time: new Date().toISOString(),
    resource: isResourceLoadFailure(cause)
  }
  const requestId = extractRequestId(cause)
  if (requestId) fault.requestId = requestId
  return fault
}

/**
 * 「复制问题信息」文本：只含错误码、时间、版本、请求编号（如可得）。
 * 不含路径、堆栈、URL 参数或任何凭证字段。
 */
export function problemInfoText(fault: BoundaryFault): string {
  const lines = [
    'SandAI 管理页问题信息',
    `错误码：${fault.code}`,
    `时间：${fault.time}`,
    `版本：${SAND_AI_PLUGIN_VERSION}`
  ]
  if (fault.requestId) lines.push(`请求编号：${fault.requestId}`)
  return lines.join('\n')
}

/* ---------- 全局陷阱：window error / unhandledrejection → 当前页边界 ---------- */

type FaultHandler = (cause: unknown) => void

const handlers: FaultHandler[] = []
let trapInstalled = false

function dispatch(cause: unknown): void {
  const handler = handlers[handlers.length - 1]
  if (handler) {
    handler(cause)
  } else {
    // 无边界的页面（如尚未进入 SandAI 页）保持原样输出，不吞错误
    console.error('[sand-ai] unhandled error', cause)
  }
}

function installGlobalTrap(): void {
  if (trapInstalled || typeof window === 'undefined') return
  trapInstalled = true
  window.addEventListener('error', (event) => {
    // 资源加载失败时 event.error 为空，ErrorEvent 本身即线索
    dispatch(event.error ?? event)
  })
  window.addEventListener('unhandledrejection', (event) => {
    dispatch(event.reason)
    // 已由页面边界兜底，阻止默认输出重复原文
    event.preventDefault()
  })
}

/**
 * 页面边界挂载时注册，卸载时移除；栈顶（最新挂载页）接收全局错误。
 * 返回解绑函数。
 */
export function registerFaultHandler(handler: FaultHandler): () => void {
  installGlobalTrap()
  handlers.push(handler)
  return () => {
    const index = handlers.indexOf(handler)
    if (index >= 0) handlers.splice(index, 1)
  }
}

/* ---------- 有界自动重载：30 秒内最多 1 次，防 reload 循环 ---------- */

const RELOAD_KEY = 'sand_ai_error_boundary_reload'
const RELOAD_WINDOW_MS = 30_000
const RELOAD_MAX = 1

/** 返回 true 表示允许本次自动重载；超过窗口次数或存储不可用返回 false。 */
export function requestBoundedReload(): boolean {
  try {
    const now = Date.now()
    const raw = sessionStorage.getItem(RELOAD_KEY)
    const record = raw ? (JSON.parse(raw) as { ts?: number; count?: number }) : null
    const withinWindow =
      record !== null && typeof record.ts === 'number' && now - record.ts < RELOAD_WINDOW_MS
    const count = withinWindow ? (record?.count ?? 0) : 0
    if (count >= RELOAD_MAX) return false
    sessionStorage.setItem(RELOAD_KEY, JSON.stringify({ ts: now, count: count + 1 }))
    return true
  } catch {
    return false
  }
}
