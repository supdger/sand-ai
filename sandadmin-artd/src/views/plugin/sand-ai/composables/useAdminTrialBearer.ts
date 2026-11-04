import { computed, onBeforeUnmount, ref } from 'vue'
import { requestAdminTrialContext } from '../api/adminTrial'

export type AdminTrialBearerState = 'idle' | 'loading' | 'ready' | 'unavailable'

/** 到期前自动重取的提前量；签发失败时立即回落手填。 */
const REFRESH_MARGIN_MS = 60_000
const MIN_REFRESH_DELAY_MS = 5_000

function parseExpireTime(value: string): number {
  const parsed = Date.parse(value.replace(' ', 'T'))
  return Number.isNaN(parsed) ? 0 : parsed
}

/** 服务端报错按 "CODE: 中文" 约定返回；界面只展示中文引导。 */
function displayMessage(error: unknown): string {
  const message = error instanceof Error ? error.message : '暂时无法签发试用身份'
  return message.replace(/^[A-Z][A-Z0-9_]*:\s*/, '')
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
    timer = setTimeout(() => {
      void refresh()
    }, Math.max(MIN_REFRESH_DELAY_MS, at - Date.now()))
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
