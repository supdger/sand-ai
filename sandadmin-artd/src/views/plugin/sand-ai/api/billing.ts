import request from '@/utils/http'
import { HttpError } from '@/utils/http/error'
import { ADMIN_API_PREFIX } from './config'
import { batch, clearResult, detail, list, result, review, uploadResult } from './billing-types'
import type { BillingImportInput, BillingReviewInput } from './billing-types'

const base = `${ADMIN_API_PREFIX}/billing`
export function billingError(error: unknown): string {
  if (error instanceof HttpError && error.code === 403) return '没有此账单操作权限，请联系管理员。'
  return error instanceof Error ? error.message : '账单操作失败'
}
export function uncertainWrite(error: unknown): boolean {
  // 只有明确的客户端拒绝可解除冻结；网络、服务器错误和不完整成功回执都可能已写入。
  return !(error instanceof HttpError && error.code >= 400 && error.code < 500)
}
export function positiveId(text: string): number | null {
  if (!/^[1-9][0-9]*$/.test(text)) return null
  const value = Number(text)
  return Number.isSafeInteger(value) ? value : null
}
export const billingApi = {
  async index(providerId?: number) {
    return list(
      await request.get<unknown>({
        url: `${base}/index`,
        params: providerId ? { provider_id: providerId } : {},
        showErrorMessage: false
      }),
      batch
    )
  },
  async read(id: number) {
    return detail(
      await request.get<unknown>({ url: `${base}/read`, params: { id }, showErrorMessage: false })
    )
  },
  async import(input: BillingImportInput) {
    return result(
      await request.post<unknown>({ url: `${base}/import`, data: input, showErrorMessage: false }),
      'billing_import',
      detail
    )
  },
  async upload(providerId: number, file: File) {
    const data = new FormData()
    data.append('provider_id', String(providerId))
    data.append('file', file)
    return uploadResult(
      await request.post<unknown>({ url: `${base}/evidence`, data, showErrorMessage: false })
    )
  },
  async download(id: number): Promise<Blob> {
    const value = await request.get<unknown>({
      url: `${base}/evidence`,
      params: { id },
      responseType: 'blob',
      showErrorMessage: false
    })
    if (!(value instanceof Blob)) throw new Error('原件下载响应不完整')
    if (/json/i.test(value.type)) {
      const body: unknown = JSON.parse(await value.text())
      const message =
        body !== null &&
        typeof body === 'object' &&
        'message' in body &&
        typeof body.message === 'string'
          ? body.message
          : '原件不可下载'
      throw new Error(message)
    }
    if (
      !['application/pdf', 'text/plain', 'text/csv', 'application/csv',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'].includes(
        value.type.split(';')[0]
      )
    )
      throw new Error('原件响应类型不受支持')
    return value
  },
  async clear(id: number) {
    return clearResult(
      await request.del<unknown>({
        url: `${base}/evidence`,
        params: { id },
        showErrorMessage: false
      })
    )
  },
  async review(input: BillingReviewInput) {
    return result(
      await request.post<unknown>({ url: `${base}/reviews`, data: input, showErrorMessage: false }),
      'review',
      review
    )
  },
  async history(lineId: number) {
    return list(
      await request.get<unknown>({
        url: `${base}/reviews`,
        params: { billing_line_id: lineId },
        showErrorMessage: false
      }),
      review
    )
  }
}
