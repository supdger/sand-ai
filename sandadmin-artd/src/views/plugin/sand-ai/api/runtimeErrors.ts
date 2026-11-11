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
  /** 冻结错误码仅用于界面分支判断，不直接上屏。 */
  code?: string
}

/** 契约错误码 → 操作员可读说明；键必须与契约表逐字一致。 */
export const RUNTIME_ERROR_HINTS: Readonly<Record<string, string>> = {
  SAND_AI_AUTHENTICATION_FAILED: '身份上下文无效：缺少调用密钥、签名无效或载荷无法解析。',
  SAND_AI_IDENTITY_CONTEXT_EXPIRED: '身份上下文已过期，需要重新签发短期 workload context。',
  SAND_AI_CREDENTIAL_REVOKED: '签发该 context 的 credential、client 或 environment 已撤销/停用。',
  SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE:
    '宿主未安装可验证的 SandIAM context adapter，或 signer 不可用；不会回退 API Key。',
  SAND_AI_SERVICE_ACTION_FORBIDDEN: '暂无权限查看该内容：该记录可能属于其他调用身份或已过期的试用会话。文件解析结果请到文件页查看。',
  SAND_AI_ENVIRONMENT_MISMATCH: '应用运行身份与所选环境不一致，请使用该环境的 SandIAM 运行身份。',
  SAND_AI_OCR_UNAVAILABLE: 'OCR 尚未配置，图片不会被标成已解析。',
  SAND_AI_OCR_REQUIRED: '材料包含尚未解析的图片或嵌入对象，不能标为完整解析。PDF 需配置 OCR 后继续原解析任务；DOCX 需先在私有处理流程中导出保留视觉正文的 PDF，再走现有 OCR 解析流程。',
  SAND_AI_PARSE_UNSUPPORTED: '当前文件类型没有可用解析驱动。',
  SAND_AI_PARSE_EMPTY: '未提取到有效文本。',
  SAND_AI_PARSE_FAILED: '解析失败，请查看 error_summary。',
  SAND_AI_FILE_INVALID: '文件格式、大小或内容不合规。',
  SAND_AI_STORAGE_UNAVAILABLE: '私有存储不可用。',
  SAND_AI_RESOURCE_NOT_FOUND: '找不到这条记录，可能已被删除，请刷新列表。',
  SAND_AI_RESOURCE_CONFLICT: '这条刚被别人改过，刷新后再改。',
  SAND_AI_VALIDATION_ERROR: '填写的内容不符合要求，请检查后重试。',
  SAND_AI_CONFIGURATION_DRIFTED: '配置在保存期间被别人改过，请重新读取后再保存。',
  SAND_AI_REQUEST_UNMATCHED: '找不到原操作记录，请刷新后重试。',
  SAND_AI_REQUEST_TIMEOUT: '请求超时，请稍后再试。',
  SAND_AI_IDEMPOTENCY_KEY_REQUIRED: '系统出了点问题，请稍后再试。',
  SAND_AI_IDEMPOTENCY_CONFLICT: '同一个请求号和之前提交的内容不一致，请刷新后重新操作。',
  SAND_AI_ONBOARDING_CONNECTION_FORBIDDEN: '这个地址不允许访问（本机、内网或未批准端口），请换一个能打开的服务地址。',
  SAND_AI_ONBOARDING_CONNECTION_INVALID: '服务商地址格式不对，请检查后再试。',
  SAND_AI_ONBOARDING_MODEL_INVALID: '所选模型名不可用，请重新选择。',
  SAND_AI_ONBOARDING_DNS_UNAVAILABLE: '域名解析失败，请检查地址拼写和网络后重试。',
  SAND_AI_ONBOARDING_HEADER_INVALID: '服务商响应头不符合约定，请检查服务配置。',
  SAND_AI_ONBOARDING_CONNECTION_UNVERIFIABLE: '连不上，请检查地址和密钥。',
  SAND_AI_ONBOARDING_RESPONSE_TOO_LARGE: '服务商返回内容过大，请检查服务配置。',
  SAND_AI_BUDGET_PERIOD_CONFLICT: '预算周期对不上，请刷新后按当前月份重新填写。',
  SAND_AI_BUDGET_IMMUTABLE: '这条预算记录不能再改，请刷新后核对。',
  SAND_AI_BUDGET_VALIDATION_ERROR: '预算填写不符合要求，请检查金额和周期后再试。',
  SAND_AI_BUDGET_CONFIGURATION_CONFLICT: '预算配置刚被别人改过，请刷新后再试。',
  SAND_AI_BUDGET_CURRENCY_MISMATCH: '币种不一致，请按当前环境币种填写。',
  SAND_AI_BUDGET_POLICY_NOT_EXECUTABLE: '这条预算配置当前不能生效，请检查内容后再试。',
  SAND_AI_BUDGET_POLICY_NOT_FOUND: '找不到这条预算配置，可能已被删除，请刷新列表。',
  SAND_AI_KNOWLEDGE_CONFIGURATION_INVALID: '知识库配置不正确，请修正后再试。',
  SAND_AI_RUNTIME_BEARER_FORBIDDEN: '服务访问凭证不对，请检查后再试。',
  SAND_AI_FILE_UPLOAD_KEY_CONFLICT: '文件上传标识冲突，请重新上传。',
  SAND_AI_TASK_UNSUPPORTED: 'Worker 不支持该任务类型。',
  SAND_AI_TASK_INVALID: '任务输入无效。',
  SAND_AI_CAPABILITY_UNSUPPORTED: '已启用的能力配置没有包含该能力。请到「高级 → 能力配置」为当前环境添加该能力并启用配置后重试。',
  // 语义宽泛（多处「环境缺已发布配置」抛出），只给通用说明；页面级引导由各面板自行处理
  SAND_AI_CONFIGURATION_NOT_PUBLISHED: '当前环境还没有可用的已启用能力配置，请先到「能力配置」保存并启用。',
  SAND_AI_DATA_EGRESS_FORBIDDEN: '数据外发策略拒绝该驱动或 Provider。',
  SAND_AI_PROVIDER_CONFIG_INVALID: '服务商配置有问题，请到「模型服务商」页检查接口地址和密钥是否正确，保存后重试；仍失败请联系管理员核对服务配置。',
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
  const hint = RUNTIME_ERROR_HINTS[errorCode]
  if (hint) return hint
  // *_FORBIDDEN 未单独收录时只给非技术兜底；语义不准，不借「无权限」误导。
  if (/_FORBIDDEN$/.test(errorCode)) {
    return '这个操作被拒绝了，请核对身份和内容后再试。'
  }
  return undefined
}

/**
 * 把运行面异常收成 Alert 标题/描述：界面只给中文说明，error_code/request_id 只进 console。
 */
export function toRuntimeUiError(error: unknown): RuntimeUiError {
  if (error instanceof SandAiRuntimeError) {
    const hint = hintForRuntimeErrorCode(error.errorCode)
    // 冻结码优先给中文说明；无映射时仅回显中文原文，英文原文一律兜底、不进界面。
    const displayText = hint ?? displayableRuntimeMessage(error.message) ?? '请求未完成，请稍后重试。'
    // error_code / request_id 仅供排查，进 console，不上屏（§6.1）。
    if (error.errorCode || error.requestId) {
      console.error('[sand-ai] runtime error', { error_code: error.errorCode, request_id: error.requestId })
    }
    return {
      title: '请求未完成',
      description: displayText,
      ...(error.errorCode ? { code: error.errorCode } : {})
    }
  }
  return {
    title: '请求失败',
    description: error instanceof Error ? (displayableRuntimeMessage(error.message) ?? '请求未完成，请稍后重试。') : '未知错误'
  }
}
