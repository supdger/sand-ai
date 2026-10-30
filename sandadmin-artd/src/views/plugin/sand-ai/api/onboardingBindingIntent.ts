export interface OnboardingBindingIntent {
  version: 1
  admin_id: number
  application_id: number
  environment_id: number
  deployment_id: number
  revision: number
}

function positiveId(value: unknown): value is number {
  return typeof value === 'number' && Number.isSafeInteger(value) && value > 0
}

function namespace(adminId: number): string {
  if (!positiveId(adminId)) throw new Error('原绑定管理员无法确认')
  return `sand-ai:onboarding:binding:${adminId}:active`
}

export function readOnboardingBindingIntent(adminId: number): OnboardingBindingIntent | null {
  const raw = sessionStorage.getItem(namespace(adminId))
  if (raw === null) return null
  const value: unknown = JSON.parse(raw)
  if (value === null || typeof value !== 'object' || Array.isArray(value)) {
    throw new Error('原绑定记录无法确认')
  }
  const applicationId: unknown = Reflect.get(value, 'application_id')
  const environmentId: unknown = Reflect.get(value, 'environment_id')
  const deploymentId: unknown = Reflect.get(value, 'deployment_id')
  const revision: unknown = Reflect.get(value, 'revision')
  if (Reflect.get(value, 'version') !== 1 || Reflect.get(value, 'admin_id') !== adminId
    || !positiveId(applicationId) || !positiveId(environmentId)
    || !positiveId(deploymentId) || !positiveId(revision)) {
    throw new Error('原绑定记录无法确认')
  }
  return {
    version: 1, admin_id: adminId,
    application_id: applicationId, environment_id: environmentId,
    deployment_id: deploymentId, revision
  }
}

export function retainOnboardingBindingIntent(intent: OnboardingBindingIntent): void {
  const old = readOnboardingBindingIntent(intent.admin_id)
  if (old !== null && JSON.stringify(old) !== JSON.stringify(intent)) {
    throw new Error('另一原绑定结果尚未确认')
  }
  sessionStorage.setItem(namespace(intent.admin_id), JSON.stringify(intent))
  const stored = readOnboardingBindingIntent(intent.admin_id)
  if (!stored || JSON.stringify(stored) !== JSON.stringify(intent)) {
    throw new Error('原绑定记录未保存')
  }
}

export function resolveOnboardingBindingIntent(intent: OnboardingBindingIntent): void {
  const stored = readOnboardingBindingIntent(intent.admin_id)
  if (!stored || JSON.stringify(stored) !== JSON.stringify(intent)) {
    throw new Error('原绑定记录已经变化')
  }
  sessionStorage.removeItem(namespace(intent.admin_id))
  if (readOnboardingBindingIntent(intent.admin_id) !== null) {
    throw new Error('原绑定记录未解除')
  }
}
