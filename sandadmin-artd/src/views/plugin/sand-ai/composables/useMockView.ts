import { computed, onMounted, ref } from 'vue'
import type { MockViewState } from './useMockList'

export type UseMockViewOptions = {
  delayMs?: number
  initialState?: MockViewState
  errorMessage?: string
}

/**
 * 非列表页共用的加载/空/失败/成功状态机（纯前端 mock）。
 */
export function useMockView(options: UseMockViewOptions = {}) {
  const delayMs = options.delayMs ?? 450
  const viewState = ref<MockViewState>(options.initialState ?? 'loading')
  const errorMessage = ref(
    options.errorMessage ?? '模拟加载失败：后端契约尚未冻结，当前为前端 mock 错误态'
  )
  let timer: ReturnType<typeof setTimeout> | undefined

  const isLoading = computed(() => viewState.value === 'loading')
  const isEmpty = computed(() => viewState.value === 'empty')
  const isError = computed(() => viewState.value === 'error')
  const isSuccess = computed(() => viewState.value === 'success')

  function clearTimer(): void {
    if (timer !== undefined) {
      clearTimeout(timer)
      timer = undefined
    }
  }

  function applyState(next: MockViewState): void {
    clearTimer()
    viewState.value = 'loading'
    timer = setTimeout(() => {
      viewState.value = next
    }, delayMs)
  }

  function reload(): void {
    applyState('success')
  }

  function showLoading(): void {
    clearTimer()
    viewState.value = 'loading'
  }

  function showEmpty(): void {
    applyState('empty')
  }

  function showError(): void {
    applyState('error')
  }

  function showSuccess(): void {
    applyState('success')
  }

  onMounted(() => {
    applyState(
      options.initialState === 'loading' || options.initialState === undefined
        ? 'success'
        : options.initialState
    )
  })

  return {
    viewState,
    errorMessage,
    isLoading,
    isEmpty,
    isError,
    isSuccess,
    reload,
    showLoading,
    showEmpty,
    showError,
    showSuccess
  }
}
