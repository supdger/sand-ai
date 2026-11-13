import type { SandAiFileState } from './types'

/** 文件页产品面三态：上传后处理交给后端任务，界面只露「处理中/能用了/处理失败」。 */
export type FileStatusKind = 'processing' | 'usable' | 'failed'

const FAILED_STATES: readonly string[] = ['parse_failed', 'parse_canceled', 'failed', 'delete_failed']

export function fileStatusView(state: SandAiFileState): FileStatusKind {
  if (state === 'parsed') return 'usable'
  if (FAILED_STATES.includes(state)) return 'failed'
  return 'processing'
}

export function fileStatusText(state: SandAiFileState): string {
  const view = fileStatusView(state)
  return view === 'usable' ? '能用了' : view === 'failed' ? '处理失败' : '处理中'
}
