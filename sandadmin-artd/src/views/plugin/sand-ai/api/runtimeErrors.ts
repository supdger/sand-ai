/**
 * 运行面已冻结 error_code 的管理端展示文案。
 *
 * 只收录 API 契约错误码表（含 1.11 / 1.13 身份码）。收到未知码时只回显原码，不猜测语义。
 */

import { SandAiRuntimeError } from './runtime'
import { runtimeIdentityRefusalForCode } from './runtimeIdentityNotice'

export type RuntimeUiError = {
  title: string
  description: string
}

/** 契约错误码 → 操作员可读说明；键必须与契约表逐字一致。 */
export const RUNTIME_ERROR_HINTS: Readonly<Record<string, string>> = {
  SAND_AI_AUTHENTICATION_FAILED: '身份上下文无效：缺少调用密钥、签名无效或载荷无法解析。',
  SAND_AI_IDENTITY_CONTEXT_EXPIRED: '身份上下文已过期，需要重新签发短期 workload context。',
  SAND_AI_CREDENTIAL_REVOKED: '签发该 context 的 credential、client 或 environment 已撤销/停用。',
  SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE:
    '宿主未安装可验证的 SandIAM context adapter，或 signer 不可用；不会回退 API Key。',
  SAND_AI_SERVICE_ACTION_FORBIDDEN: '已识别调用方，但未授予当前服务动作，或 audience 不是 sand-ai。',
  SAND_AI_ENVIRONMENT_MISMATCH: '应用运行身份与所选环境不一致，请使用该环境的 SandIAM 运行身份。',
  SAND_AI_OCR_UNAVAILABLE: 'OCR 尚未配置，图片不会被标成已解析。',
  SAND_AI_OCR_REQUIRED: '材料包含尚未解析的图片或嵌入对象，不能标为完整解析。PDF 需配置 OCR 后继续原解析任务；DOCX 需先在私有处理流程中导出保留视觉正文的 PDF，再走现有 OCR 解析流程。',
  SAND_AI_PARSE_UNSUPPORTED: '当前文件类型没有可用解析驱动。',
  SAND_AI_PARSE_EMPTY: '未提取到有效文本。',
  SAND_AI_PARSE_FAILED: '解析失败，请查看 error_summary。',
  SAND_AI_FILE_INVALID: '文件格式、大小或内容不合规。',
  SAND_AI_STORAGE_UNAVAILABLE: '私有存储不可用。',
  SAND_AI_RESOURCE_NOT_FOUND: '当前环境凭证看不到该资源。',
  SAND_AI_RESOURCE_CONFLICT: '资源冲突（重复提交或状态不允许该操作）。',
  SAND_AI_TASK_UNSUPPORTED: 'Worker 不支持该任务类型。',
  SAND_AI_TASK_INVALID: '任务输入无效。',
  SAND_AI_CAPABILITY_UNSUPPORTED: '已启用的能力配置没有包含该能力。请到「高级 → 能力配置」为当前环境添加该能力并启用配置后重试。',
  SAND_AI_DATA_EGRESS_FORBIDDEN: '数据外发策略拒绝该驱动或 Provider。',
  SAND_AI_PROVIDER_CONFIG_INVALID: '服务商配置不可用（不回显密钥）。',
  SAND_AI_PROVIDER_UNAVAILABLE: '服务商暂不可用，可按 retryable 重试。',
  SAND_AI_PROVIDER_RATE_LIMITED: '服务商限流，可按 retryable 重试。',
  SAND_AI_PROVIDER_REJECTED: '服务商拒绝请求，不回显厂商原文。'
}

/** 服务端消息含中文才可回显（去稳定码前缀）；纯英文/机读串不上面界面文案。 */
export function displayableRuntimeMessage(message: string | null | undefined): string | undefined {
  if (!message) return undefined
  const text = message.replace(/^[A-Z][A-Z0-9_]*:\s*/, '').trim()
  return /[㐀-鿿豈-﫿]/.test(text) ? text : undefined
}

/**
 * 按冻结码返回展示文案；未知码返回 undefined，由调用方只展示原码。
 */
export function hintForRuntimeErrorCode(errorCode: string | null | undefined): string | undefined {
  if (!errorCode) {
    return undefined
  }
  const identityRefusal = runtimeIdentityRefusalForCode(errorCode)
  if (identityRefusal) return identityRefusal.description
  return RUNTIME_ERROR_HINTS[errorCode]
}

/**
 * 把运行面异常收成 Alert 标题/描述：始终带 error_code，身份码带契约说明。
 */
export function toRuntimeUiError(error: unknown): RuntimeUiError {
  if (error instanceof SandAiRuntimeError) {
    const hint = hintForRuntimeErrorCode(error.errorCode)
    // 冻结码优先给中文说明；无映射时仅回显中文原文，英文原文一律兜底、不进界面。
    const displayText = hint ?? displayableRuntimeMessage(error.message) ?? '请求未完成，请稍后重试。'
    const parts = [
      displayText,
      error.errorCode ? `error_code=${error.errorCode}` : null,
      error.requestId ? `request_id=${error.requestId}` : null
    ].filter((part): part is string => typeof part === 'string' && part.length > 0)
    return {
      title: error.errorCode ?? '运行面错误',
      description: parts.join('；')
    }
  }
  return {
    title: '请求失败',
    description: error instanceof Error ? (displayableRuntimeMessage(error.message) ?? '请求未完成，请稍后重试。') : '未知错误'
  }
}
