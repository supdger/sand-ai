// 新首页「设为默认并启用」的一键编排意图 journal。
// 与 onboarding/index.vue 的 journey、onboardingBindingIntent 同一模式：
// 每次可能已到达服务器的写入前先在本机持久化不透明目标；结果未确认时
// 只读找回，绝不重发；会话存储损坏时锁定写操作。
export type HomeActivationPhase =
  | 'save_unknown'      // save 已发送（或可能已发送）但结果未确认
  | 'saved'             // 草稿已确认，可发送验证
  | 'verify_unknown'    // verify 已发送但结果未确认
  | 'tested'            // 验证通过，可发送发布
  | 'publish_unknown'   // publish 已发送但结果未确认
  | 'published'         // 已发布，可发送绑定
  | 'bind_unknown'      // bind 已发送但结果未确认

export interface HomeActivationIntent {
  readonly version: 1
  readonly admin_id: number
  readonly environment_id: number
  readonly save_key: string
  readonly verify_key: string | null
  readonly provider_code: string | null
  readonly model_code: string | null
  readonly provider_id: number | null
  readonly model_id: number | null
  readonly deployment_id: number | null
  readonly revision: number | null
  readonly phase: HomeActivationPhase
}

const phases: readonly HomeActivationPhase[] = [
  'save_unknown', 'saved', 'verify_unknown', 'tested', 'publish_unknown', 'published', 'bind_unknown'
]

function positiveId(value: unknown): value is number {
  return typeof value === 'number' && Number.isSafeInteger(value) && value > 0
}

function nullableId(value: unknown): value is number | null {
  return value === null || positiveId(value)
}

function isUuid(value: unknown): value is string {
  return typeof value === 'string'
    && /^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i.test(value)
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function namespace(adminId: number): string {
  if (!positiveId(adminId)) throw new Error('原开通管理员无法确认')
  return `sand-ai:home:activation:${adminId}:active`
}

function isPhase(value: unknown): value is HomeActivationPhase {
  return typeof value === 'string' && (phases as readonly string[]).includes(value)
}

function decode(value: unknown, adminId: number): HomeActivationIntent {
  if (!isRecord(value) || value.version !== 1 || value.admin_id !== adminId
    || !positiveId(value.environment_id) || !isUuid(value.save_key)
    || !(value.verify_key === null || isUuid(value.verify_key))
    || !(value.provider_code === null || (typeof value.provider_code === 'string' && /^onboard_[a-f0-9]{12}$/.test(value.provider_code)))
    || !(value.model_code === null || (typeof value.model_code === 'string' && /^chat_[a-f0-9]{12}$/.test(value.model_code)))
    || !nullableId(value.provider_id) || !nullableId(value.model_id)
    || !nullableId(value.deployment_id) || !nullableId(value.revision)
    || !isPhase(value.phase)) {
    throw new Error('原开通记录无法确认')
  }
  if (value.phase === 'save_unknown') {
    // 新建链路用 code 指纹，复用既有服务商/模型用 id 指纹；两者至少各有一侧。
    if (value.verify_key !== null
      || value.deployment_id !== null || value.revision !== null
      || (value.provider_code === null && value.provider_id === null)
      || (value.model_code === null && value.model_id === null)) {
      throw new Error('原开通记录无法确认')
    }
  } else if (!positiveId(value.deployment_id) || !positiveId(value.revision)) {
    throw new Error('原开通记录无法确认')
  }
  if (value.phase === 'verify_unknown' && !isUuid(value.verify_key)) {
    throw new Error('原开通记录无法确认')
  }
  return {
    version: 1,
    admin_id: adminId,
    environment_id: value.environment_id,
    save_key: value.save_key,
    verify_key: isUuid(value.verify_key) ? value.verify_key : null,
    provider_code: typeof value.provider_code === 'string' ? value.provider_code : null,
    model_code: typeof value.model_code === 'string' ? value.model_code : null,
    provider_id: positiveId(value.provider_id) ? value.provider_id : null,
    model_id: positiveId(value.model_id) ? value.model_id : null,
    deployment_id: positiveId(value.deployment_id) ? value.deployment_id : null,
    revision: positiveId(value.revision) ? value.revision : null,
    phase: value.phase
  }
}

export function readHomeActivationIntent(adminId: number): HomeActivationIntent | null {
  const raw = sessionStorage.getItem(namespace(adminId))
  if (raw === null) return null
  return decode(JSON.parse(raw), adminId)
}

/** 保留新的编排意图；已有不同的未确认意图时拒绝并锁定。 */
export function retainHomeActivationIntent(intent: HomeActivationIntent): void {
  const stored = readHomeActivationIntent(intent.admin_id)
  if (stored !== null) throw new Error('另一原开通结果尚未确认')
  const encoded = JSON.stringify(decode(intent, intent.admin_id))
  sessionStorage.setItem(namespace(intent.admin_id), encoded)
  const written = readHomeActivationIntent(intent.admin_id)
  if (!written || JSON.stringify(written) !== encoded) {
    throw new Error('原开通记录未保存')
  }
}

/** 推进同一意图的阶段；只允许同一 admin、环境与请求号的延续更新。 */
export function updateHomeActivationIntent(intent: HomeActivationIntent): void {
  const stored = readHomeActivationIntent(intent.admin_id)
  if (!stored || stored.environment_id !== intent.environment_id
    || stored.save_key !== intent.save_key) {
    throw new Error('原开通记录已经变化')
  }
  const encoded = JSON.stringify(decode(intent, intent.admin_id))
  sessionStorage.setItem(namespace(intent.admin_id), encoded)
  const written = readHomeActivationIntent(intent.admin_id)
  if (!written || JSON.stringify(written) !== encoded) {
    throw new Error('原开通记录未保存')
  }
}

/** 仅在读取确认与存储记录完全一致后解除原意图锁。 */
export function resolveHomeActivationIntent(intent: HomeActivationIntent): void {
  const stored = readHomeActivationIntent(intent.admin_id)
  if (!stored || JSON.stringify(stored) !== JSON.stringify(decode(intent, intent.admin_id))) {
    throw new Error('原开通记录已经变化')
  }
  sessionStorage.removeItem(namespace(intent.admin_id))
  if (readHomeActivationIntent(intent.admin_id) !== null) {
    throw new Error('原开通记录未解除')
  }
}
