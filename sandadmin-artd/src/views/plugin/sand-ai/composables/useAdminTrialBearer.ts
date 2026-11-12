import { computed, onBeforeUnmount, ref } from 'vue'
import { requestAdminTrialContext } from '../api/adminTrial'
import { errorDisplayText } from '../api/errorText'
import { serverTimePoint } from '../api/localTime'

export type AdminTrialBearerState = 'idle' | 'loading' | 'ready' | 'unavailable'

/** 到期前自动重取的提前量；签发失败时立即回落手填。 */
const REFRESH_MARGIN_MS = 60_000
const MIN_REFRESH_DELAY_MS = 5_000
/**
 * 服务端返回的 expire_time 是服务器墙钟；按浏览器本地时区解析会在
 * 非东八区把续期排到上下文寿命之外（试用上下文约 5 分钟即失效）。
 * 续期延迟一律钳制在安全上限内：宁可提前重取，不能让身份悄悄过期。
 */
const MAX_REFRESH_DELAY_MS = 4 * 60_000

/**
 * 服务端按部署时区（Asia/Shanghai）返回墙钟到期时间，不能交给 Date.parse
 * 当本地时间解析——非 +08 环境会把到期点看偏，导致续期排程错乱、Bearer
 * 提前过期，上传初始化反复 401。统一经 serverTimePoint 解释成时间点。
 */
function parseExpireTime(value: string): number {
  const point = serverTimePoint(value)
  return point === null ? 0 : point.getTime()
}

/** 服务端报错按 "CODE: 中文" 约定返回；界面只展示中文引导，英文原文不进界面。 */
function displayMessage(error: unknown): string {
  return errorDisplayText(error, '暂时无法签发试用身份，请稍后重试或手动填写调用密钥')
}

/**
 * 管理员试用 Bearer：自动经 POST /app/sand-ai/admin/trial/context 获取
 * 短期运行身份，到期前自动重取；不可用时给出中文引导，由调用方回退手填。
 */
export function useAdminTrialBearer(actions: () => string[]) {
  const state = ref<AdminTrialBearerState>('idle')
  const bearer = ref('')
  const expireTime = ref('')
  const failure = ref('')

  let timer: ReturnType<typeof setTimeout> | null = null
  let inFlight: Promise<boolean> | null = null
  let generation = 0

  const ready = computed(() => state.value === 'ready' && bearer.value !== '')

  function clearTimer(): void {
    if (timer !== null) {
      clearTimeout(timer)
      timer = null
    }
  }

  function schedule(expire: string): void {
    clearTimer()
    const at = parseExpireTime(expire) - REFRESH_MARGIN_MS
    const delay = Math.min(Math.max(MIN_REFRESH_DELAY_MS, at - Date.now()), MAX_REFRESH_DELAY_MS)
    timer = setTimeout(() => {
      void refresh()
    }, delay)
  }

  async function refresh(): Promise<boolean> {
    if (inFlight !== null) return inFlight
    const current = ++generation
    state.value = 'loading'
    inFlight = (async (): Promise<boolean> => {
      try {
        const result = await requestAdminTrialContext(actions())
        if (current !== generation) return false
        bearer.value = result.context
        expireTime.value = result.expire_time
        failure.value = ''
        state.value = 'ready'
        schedule(result.expire_time)
        return true
      } catch (error: unknown) {
        if (current !== generation) return false
        bearer.value = ''
        expireTime.value = ''
        failure.value = displayMessage(error)
        state.value = 'unavailable'
        return false
      } finally {
        if (current === generation) inFlight = null
      }
    })()
    return inFlight
  }

  /** 停止自动续期并清空已取得的身份（组件卸载或改用手动密钥时调用）。 */
  function stop(): void {
    ++generation
    clearTimer()
    bearer.value = ''
    expireTime.value = ''
  }

  onBeforeUnmount(stop)

  return { state, bearer, expireTime, failure, ready, refresh, stop }
}
