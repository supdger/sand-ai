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
