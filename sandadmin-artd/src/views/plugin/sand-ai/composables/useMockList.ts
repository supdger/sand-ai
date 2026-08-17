import { computed, onMounted, ref } from 'vue'

/**
 * 前端 mock 列表状态机。
 * 字段仅为页面骨架展示键，不代表已冻结的 API DTO。
 */
export type MockListItem = {
  mockId: string
  mockTitle: string
  mockStatus: string
  mockUpdatedAt: string
}

export type MockViewState = 'loading' | 'empty' | 'error' | 'success'

export type UseMockListOptions = {
  resourceLabel: string
  sampleTitles: string[]
  delayMs?: number
  initialState?: MockViewState
}

function buildSamples(resourceLabel: string, titles: string[]): MockListItem[] {
  return titles.map((title, index) => ({
    mockId: `mock-${resourceLabel}-${index + 1}`,
    mockTitle: title,
    mockStatus: index % 3 === 0 ? '示意-启用' : index % 3 === 1 ? '示意-停用' : '示意-草稿',
    mockUpdatedAt: `2026-07-2${index} 10:0${index}:00`
  }))
}

export function useMockList(options: UseMockListOptions) {
  const delayMs = options.delayMs ?? 450
  const viewState = ref<MockViewState>(options.initialState ?? 'loading')
  const keyword = ref('')
  const errorMessage = ref('模拟加载失败：后端契约尚未冻结，当前为前端 mock 错误态')
  const allRows = ref<MockListItem[]>(buildSamples(options.resourceLabel, options.sampleTitles))
  let timer: ReturnType<typeof setTimeout> | undefined

  const rows = computed(() => {
    if (viewState.value !== 'success') {
      return [] as MockListItem[]
    }
    const q = keyword.value.trim()
    if (!q) {
      return allRows.value
    }
    return allRows.value.filter((item) => item.mockTitle.includes(q) || item.mockId.includes(q))
  })

  const isLoading = computed(() => viewState.value === 'loading')
  const isEmpty = computed(() => viewState.value === 'empty' || (viewState.value === 'success' && rows.value.length === 0))
  const isError = computed(() => viewState.value === 'error')

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
    applyState(options.initialState === 'loading' || options.initialState === undefined ? 'success' : options.initialState)
  })

  return {
    viewState,
    keyword,
    errorMessage,
    rows,
    isLoading,
    isEmpty,
    isError,
    reload,
    showLoading,
    showEmpty,
    showError,
    showSuccess
  }
}
