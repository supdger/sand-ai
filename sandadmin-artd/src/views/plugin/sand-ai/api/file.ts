import type { SandAiFile, SandAiParseSubmitResult } from './types'
import { runtimeRequest } from './runtime'

export default {
  upload(apiKey: string, file: File) {
    const form = new FormData()
    form.append('file', file)
    return runtimeRequest<SandAiFile>({
      method: 'POST',
      path: '/files',
      apiKey,
      data: form
    })
  },

  read(apiKey: string, fileId: number) {
    return runtimeRequest<SandAiFile>({
      method: 'GET',
      path: `/files/${fileId}`,
      apiKey
    })
  },

  /** API 1.4：异步提交解析任务，立即返回 task */
  parse(apiKey: string, fileId: number, requestId?: string) {
    return runtimeRequest<SandAiParseSubmitResult>({
      method: 'POST',
      path: `/files/${fileId}/parse`,
      apiKey,
      headers: requestId ? { 'X-Request-Id': requestId } : undefined
    })
  },

  destroy(apiKey: string, fileId: number) {
    return runtimeRequest<{ id: number; state: string }>({
      method: 'DELETE',
      path: `/files/${fileId}`,
      apiKey
    })
  }
}
