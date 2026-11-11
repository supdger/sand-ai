/**
 * 管理端错误文案统一入口：界面只展示中文说明，原始英文/服务端细节进 console。
 *
 * 规则（按序）：
 * 1. SandAiRuntimeError / 消息内嵌稳定码 → 复用 runtimeErrors 的冻结码中文映射；
 * 2. HttpError 401/403/5xx → 通用中文提示；
 * 3. 消息本身含中文（服务端按「CODE: 中文」约定返回）→ 去码前缀后回显；
 * 4. 其余（裸英文 message、异常结构）→ 通用中文兜底，原文只进 console。
 */

import { isHttpError } from '@/utils/http/error'
import { SandAiRuntimeError } from './runtime'
import { displayableRuntimeMessage, hintForRuntimeErrorCode } from './runtimeErrors'

const STABLE_CODE = /\b(SAND_AI_[A-Z0-9_]+|SAND_IAM_[A-Z0-9_]+)\b/

/** 提取异常里的稳定错误码（运行面 errorCode 或消息内嵌的 CODE: 前缀）。 */
export function stableErrorCode(error: unknown): string | null {
  if (error instanceof SandAiRuntimeError) return error.errorCode
  if (error instanceof Error) return STABLE_CODE.exec(error.message)?.[0] ?? null
  return null
}

/**
 * 服务商「测连接」失败口径：密钥不对、地址打不开、其余一律检查地址和密钥。
 * 网络/超时这类本机链路问题单独说明，不归到地址和密钥。
 */
export function connectionTestFailureText(error: unknown): string {
  const code = stableErrorCode(error) ?? ''
  if (isHttpError(error)) {
    if (error.transport === 'timeout') return '连不上：请求超时，请检查网络后再试'
    if (error.transport === 'network') return '连不上：地址打不开'
  }
  if (/AUTHENTICATION_FAILED|CREDENTIAL_REVOKED|PROVIDER_REJECTED/.test(code)) {
    return '连不上：密钥不对'
  }
  if (/CONNECTION_FORBIDDEN|CONNECTION_INVALID|DNS_UNAVAILABLE/.test(code)) {
    return '连不上：地址打不开'
  }
  return '连不上，请检查地址和密钥'
}

/** 原始错误只进 console，不上面界面文案。 */
function reportRaw(error: unknown): void {
  console.error('[sand-ai] request failed', error)
}

/**
 * 把任意异常收成一句中文界面文案；识别不到的一律走 fallback 并保留 console 详情。
 */
export function errorDisplayText(error: unknown, fallback = '操作未完成，请稍后重试。'): string {
  if (error instanceof SandAiRuntimeError) {
    const hint = hintForRuntimeErrorCode(error.errorCode)
    if (hint) return hint
    const text = displayableRuntimeMessage(error.message)
    if (text) return text
    reportRaw(error)
    return fallback
  }
  if (error instanceof Error) {
    const code = STABLE_CODE.exec(error.message)?.[0]
    if (code) {
      const hint = hintForRuntimeErrorCode(code)
      if (hint) return hint
    }
    if (isHttpError(error)) {
      if (error.code === 401) return '登录已失效，请重新登录。'
      if (error.code === 403) return '当前账号没有执行此操作的权限，请联系管理员授权。'
      if (error.code >= 500) {
        reportRaw(error)
        return '服务暂时异常，请稍后重试；持续失败请联系系统维护人员。'
      }
    }
    const text = displayableRuntimeMessage(error.message)
    if (text) return text
    reportRaw(error)
    return fallback
  }
  reportRaw(error)
  return fallback
}
