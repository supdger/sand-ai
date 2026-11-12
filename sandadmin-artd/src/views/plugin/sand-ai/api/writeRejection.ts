/**
 * 写操作「明确拒绝」统一判定。
 *
 * 回执端点查到拒绝，或本文件判定为明确拒绝，就结清本地记录并显示原因。
 * 断网、超时、取消时保留记录，页面另给「放弃此次变更」。
 * 只能用在写请求本身的异常上，回执查询失败不算写被拒绝。
 */

import { isHttpError } from '@/utils/http/error'
import { SandAiRuntimeError } from './runtime'
import { errorDisplayText } from './errorText'

const STABLE_CODE = /\b(SAND_AI_[A-Z0-9_]+|SAND_IAM_[A-Z0-9_]+)\b/

/** 结果不明的码不能算明确拒绝：服务端自己也说不清这次写有没有提交。 */
const UNCERTAIN_OUTCOME = /_AMBIGUOUS$|_OUTCOME_UNKNOWN$|^SAND_AI_RECEIPT_(UNAVAILABLE|INVALID)$/

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

/** 响应负载显式带 rejected:true 即明确拒绝（后端拒绝留回执契约）。 */
export function writeRejectedFlag(payload: unknown): boolean {
  return isRecord(payload) && payload.rejected === true
}

/** 提取异常携带的稳定错误码（SAND_AI_* / SAND_IAM_*），没有返回 null。 */
export function writeErrorCode(error: unknown): string | null {
  if (error instanceof SandAiRuntimeError) return error.errorCode
  if (isHttpError(error) && isRecord(error.data) && typeof error.data.error_code === 'string') {
    return error.data.error_code
  }
  if (error instanceof Error) return STABLE_CODE.exec(error.message)?.[0] ?? null
  return null
}

/**
 * 明确拒绝：服务端已响应且确定不提交这次写。
 * HTTP/业务码 4xx、负载 rejected 为真、或带稳定错误码都算（校验拒绝常以业务码 500 返回）。
 * 断网、超时、取消、无业务码的 5xx、结果不明的码都不算。
 */
export function isWriteRejected(error: unknown): boolean {
  const code = writeErrorCode(error)
  if (code && UNCERTAIN_OUTCOME.test(code)) return false
  if (error instanceof SandAiRuntimeError) {
    if (error.httpStatus >= 400 && error.httpStatus < 500) return true
    if (error.businessCode >= 400 && error.businessCode < 500) return true
    return code !== null
  }
  if (isHttpError(error)) {
    if (error.transport === 'network' || error.transport === 'timeout'
      || error.transport === 'cancelled' || error.transport === 'unknown') return false
    if (writeRejectedFlag(error.data)) return true
    if (error.code >= 400 && error.code < 500) return true
    return code !== null
  }
  return error instanceof Error && code !== null && !UNCERTAIN_OUTCOME.test(code)
}

/** 写被拒后的界面原因：优先后端白话 message，走统一错误文案映射。 */
export function writeRejectionText(error: unknown, fallback = '这次操作被拒绝了。'): string {
  return errorDisplayText(error, fallback)
}
