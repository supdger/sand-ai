import { ref } from 'vue'
import { HttpError } from '@/utils/http/error'
import onboardingApi, { type SetupBootstrapFailure } from '../api/onboarding'

export interface SetupBootstrapNotice {
  message: string
  retryable: boolean
}

/**
 * P4 惰性引导：首次进入概览/首次开通时调用一次 POST /setup/bootstrap。
 * 模块级标记保证同一会话内只自动调用一次；成功后不再调用。
 * 失败不阻塞页面：catalog_error 显示"系统准备未完成 [重试]"，
 * sandiam_unavailable / sandiam_disabled 只按契约提示，不自动重试。
 */
let attempted = false
let ready = false
let inFlight: Promise<void> | null = null

const notice = ref<SetupBootstrapNotice | null>(null)
const busy = ref(false)

function isSetupBootstrapFailure(value: unknown): value is SetupBootstrapFailure {
  return (
    value !== null &&
    typeof value === 'object' &&
    !Array.isArray(value) &&
    Reflect.get(value, 'ready') === false
  )
}

function noticeFor(failure: SetupBootstrapFailure): SetupBootstrapNotice {
  switch (failure.reason) {
    case 'sandiam_unavailable':
      return { message: '需要先安装身份与授权插件（SandIAM）', retryable: false }
    case 'sandiam_disabled':
      return { message: failure.message, retryable: false }
    case 'security_init_failed':
      // 服务端已给出中文提示；是否可重试由原因决定（BUSY/异常可重试，需恢复材料则否）。
      return { message: failure.message, retryable: failure.retryable === true }
    case 'catalog_error':
    default:
      return { message: '系统准备未完成', retryable: true }
  }
}

async function callBootstrap(): Promise<void> {
  busy.value = true
  try {
    const result = await onboardingApi.bootstrap()
    if (result.ready === true) {
      ready = true
      notice.value = null
    }
  } catch (error) {
    const proof = error instanceof HttpError ? error.data : null
    if (isSetupBootstrapFailure(proof)) {
      notice.value = noticeFor(proof)
    } else if (error instanceof HttpError && (error.code === 401 || error.code === 403)) {
      // 无权限调用准备接口的管理员不进入可重试失败态；已尝试标记阻止同会话重复调用。
      notice.value = { message: '权限不足，跳过系统准备检查', retryable: false }
    } else {
      notice.value = { message: '系统准备未完成', retryable: true }
    }
  } finally {
    busy.value = false
    inFlight = null
  }
}

export function useSetupBootstrap() {
  /** 每次挂载至多触发一次真实调用；已就绪或已有在途请求时直接返回。 */
  function ensure(): void {
    if (ready || inFlight) return
    if (attempted) return
    attempted = true
    inFlight = callBootstrap()
  }

  /** 手动重试：catalog_error 等可重试失败时由管理员点击触发一次。 */
  function retry(): void {
    if (inFlight || busy.value || ready) return
    inFlight = callBootstrap()
  }

  return { notice, busy, ensure, retry }
}
