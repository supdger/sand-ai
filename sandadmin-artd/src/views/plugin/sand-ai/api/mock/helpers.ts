import type { SandAiPage, SandAiPageQuery } from '../types'

export type MockFilter<T> = (row: T, query: SandAiPageQuery) => boolean

let forceError = false

export function setMockForceError(next: boolean): void {
  forceError = next
}

export function getMockForceError(): boolean {
  return forceError
}

export function assertMockHealthy(): void {
  if (forceError) {
    throw new Error('模拟接口失败：可用于验证错误态（schema mock）')
  }
}

export async function delay(ms = 280): Promise<void> {
  await new Promise((resolve) => setTimeout(resolve, ms))
}

export function paginateMock<T>(
  rows: T[],
  query: SandAiPageQuery,
  filter: MockFilter<T>
): SandAiPage<T> {
  const page = Math.max(1, Number(query.page ?? 1))
  const limit = Math.max(1, Number(query.limit ?? 10))
  const filtered = rows.filter((row) => filter(row, query))
  const start = (page - 1) * limit
  return {
    current_page: page,
    per_page: limit,
    total: filtered.length,
    data: filtered.slice(start, start + limit)
  }
}

export function nowText(): string {
  const d = new Date()
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`
}

let seq = 1000

export function nextId(): number {
  seq += 1
  return seq
}
