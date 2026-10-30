export type RuntimeIdentityRefusal = {
  status: string
  description: string
  requiresReplacement: boolean
}

type IdentityRefusalListener = (bearer: string, refusal: RuntimeIdentityRefusal | null, requestSequence: number) => void

const listeners = new Set<IdentityRefusalListener>()
let sequence = 0

export function nextRuntimeIdentityRequestSequence(): number {
  return ++sequence
}

/** UI guidance only; SandIAM remains the authority for identity and service grants. */
export function runtimeIdentityRefusalForCode(code: string | null): RuntimeIdentityRefusal | null {
  switch (code) {
    case 'SAND_AI_IDENTITY_CONTEXT_EXPIRED':
      return {
        status: '运行身份已过期',
        description: '请到 SandIAM 工作负载客户端取得新的短期运行身份，再替换此处 Bearer。更换后先按原请求号读取结果，再决定是否重试；排队任务仍使用提交时的原身份，不会自动续期。',
        requiresReplacement: true
      }
    case 'SAND_AI_CREDENTIAL_REVOKED':
      return {
        status: '运行身份已撤销或停用',
        description: '请由 SandIAM 管理员核查该应用、环境和工作负载客户端的停用决定。获准恢复后取得新的运行身份并替换此处 Bearer；更换身份不会恢复原排队任务，也不会自动重发请求。',
        requiresReplacement: true
      }
    case 'SAND_AI_AUTHENTICATION_FAILED':
      return {
        status: '运行身份未通过验证',
        description: '请确认填写的是 SandIAM 签发的短期运行身份，不包含 Bearer 前缀。可清除后重新填写；更换后先按原请求号读取结果，再决定是否重试。',
        requiresReplacement: true
      }
    case 'SAND_AI_SERVICE_ACTION_FORBIDDEN':
      return {
        status: '当前服务动作未获授权',
        description: '请由 SandIAM 管理员核对当前应用、环境和工作负载客户端的 SandAI 服务授权，受众须为 sand-ai。调整授权后重新取得运行身份；保留原请求号，不直接重发。',
        requiresReplacement: false
      }
    case 'SAND_AI_ENVIRONMENT_MISMATCH':
      return {
        status: '运行身份与所选环境不一致',
        description: '请核对所选环境，或到 SandIAM 取得该环境的运行身份。先确认原请求所属环境，再读取原结果；不要在其他环境重新提交。',
        requiresReplacement: false
      }
    case 'SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE':
      return {
        status: '运行身份验证暂不可用',
        description: '请由管理员核查宿主的 SandIAM 安装、签发和验证配置。原 Bearer 与请求号已保留，配置恢复后先读取原结果；更换身份不能解决宿主验证不可用。',
        requiresReplacement: false
      }
    default:
      return null
  }
}

export function subscribeRuntimeIdentityRefusal(listener: IdentityRefusalListener): () => void {
  listeners.add(listener)
  return () => { listeners.delete(listener) }
}

/** The credential is passed synchronously, never retained, persisted or logged by this notice. */
export function notifyRuntimeIdentityRefusal(bearer: string, code: string | null, requestSequence: number): void {
  const refusal = runtimeIdentityRefusalForCode(code)
  if (!bearer || refusal === null) return
  notify(bearer, refusal, requestSequence)
}

export function notifyRuntimeIdentityAccepted(bearer: string, requestSequence: number): void {
  if (!bearer) return
  notify(bearer, null, requestSequence)
}

function notify(bearer: string, refusal: RuntimeIdentityRefusal | null, requestSequence: number): void {
  for (const listener of listeners) {
    try {
      listener(bearer, refusal, requestSequence)
    } catch {
      // A UI subscriber must not change the request's original rejection.
    }
  }
}
