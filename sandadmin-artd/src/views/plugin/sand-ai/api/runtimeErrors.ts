/**
 * 运行面已冻结 error_code 的管理端展示文案。
 *
 * 只收录 API 契约错误码表（含 1.11 / 1.13 身份码）。收到未知码时只回显原码，不猜测语义。
 */

import { SandAiRuntimeError } from './runtime'

export type RuntimeUiError = {
  title: string
  description: string
}

/** 契约错误码 → 操作员可读说明；键必须与契约表逐字一致。 */
export const RUNTIME_ERROR_HINTS: Readonly<Record<string, string>> = {
  SAND_AI_AUTHENTICATION_FAILED: '身份上下文无效：缺少 Bearer、签名无效或载荷无法解析。',
  SAND_AI_IDENTITY_CONTEXT_EXPIRED: '身份上下文已过期，需要重新签发短期 workload context。',
  SAND_AI_CREDENTIAL_REVOKED: '签发该 context 的 credential、client 或 environment 已撤销/停用。',
  SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE:
    '宿主未安装可验证的 SandIAM context adapter，或 signer 不可用；不会回退 API Key。',
  SAND_AI_SERVICE_ACTION_FORBIDDEN: '已识别调用方，但未授予当前服务动作，或 audience 不是 sand-ai。',
  SAND_AI_OCR_UNAVAILABLE: 'OCR 尚未配置，图片不会被标成已解析。',
  SAND_AI_OCR_REQUIRED: '扫描 PDF 需要 OCR，当前不会伪造成功。',
  SAND_AI_PARSE_UNSUPPORTED: '当前文件类型没有可用解析驱动。',
  SAND_AI_PARSE_EMPTY: '未提取到有效文本。',
  SAND_AI_PARSE_FAILED: '解析失败，请查看 error_summary。',
  SAND_AI_FILE_INVALID: '文件格式、大小或内容不合规。',
  SAND_AI_STORAGE_UNAVAILABLE: '私有存储不可用。',
  SAND_AI_RESOURCE_NOT_FOUND: '当前环境凭证看不到该资源。',
  SAND_AI_RESOURCE_CONFLICT: '资源冲突（重复提交或状态不允许该操作）。',
  SAND_AI_TASK_UNSUPPORTED: 'Worker 不支持该任务类型。',
  SAND_AI_TASK_INVALID: '任务输入无效。',
  SAND_AI_CAPABILITY_UNSUPPORTED: '已发布能力档未配置该能力/驱动。',
  SAND_AI_DATA_EGRESS_FORBIDDEN: '数据外发策略拒绝该驱动或 Provider。',
  SAND_AI_PROVIDER_CONFIG_INVALID: '服务商配置不可用（不回显密钥）。',
  SAND_AI_PROVIDER_UNAVAILABLE: '服务商暂不可用，可按 retryable 重试。',
  SAND_AI_PROVIDER_RATE_LIMITED: '服务商限流，可按 retryable 重试。',
  SAND_AI_PROVIDER_REJECTED: '服务商拒绝请求，不回显厂商原文。'
}

/**
 * 按冻结码返回展示文案；未知码返回 undefined，由调用方只展示原码。
 */
export function hintForRuntimeErrorCode(errorCode: string | null | undefined): string | undefined {
  if (!errorCode) {
    return undefined
  }
  return RUNTIME_ERROR_HINTS[errorCode]
}

/**
 * 把运行面异常收成 Alert 标题/描述：始终带 error_code，身份码带契约说明。
 */
export function toRuntimeUiError(error: unknown): RuntimeUiError {
  if (error instanceof SandAiRuntimeError) {
    const hint = hintForRuntimeErrorCode(error.errorCode)
    const parts = [
      error.message,
      error.errorCode ? `error_code=${error.errorCode}` : null,
      error.requestId ? `request_id=${error.requestId}` : null,
      hint
    ].filter((part): part is string => typeof part === 'string' && part.length > 0)
    return {
      title: error.errorCode ?? '运行面错误',
      description: parts.join('；')
    }
  }
  return {
    title: '请求失败',
    description: error instanceof Error ? error.message : '未知错误'
  }
}
