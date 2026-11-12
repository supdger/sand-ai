/** 库存时间没有时区，按协调世界时解析，再显示成浏览器当地时间。 */

export function browserOffsetMinutes(now = new Date()): number {
  return -now.getTimezoneOffset()
}

export function formatLocalTime(raw: string, now = new Date()): string {
  const parsed = parseUtc(raw)
  if (parsed === null) {
    return raw
  }
  const clock = `${parsed.getMonth() + 1}-${parsed.getDate()} ${pad(parsed.getHours())}:${pad(parsed.getMinutes())}`
  if (parsed.getFullYear() === now.getFullYear()) {
    return clock
  }
  return `${parsed.getFullYear()}-${clock}`
}

export function formatLatency(ms: number | null): string {
  if (ms === null || Number.isNaN(ms)) {
    return '—'
  }
  const seconds = ms / 1000
  if (seconds < 10) {
    return `${seconds.toFixed(1)} 秒`
  }
  return `${Math.round(seconds)} 秒`
}

export function formatSpend(amount: string | null): string {
  if (amount === null || amount.trim() === '') {
    return '还没设价格'
  }
  const value = Number(amount)
  if (!Number.isFinite(value)) {
    return '还没设价格'
  }
  return `¥${value.toFixed(2)}`
}

export function formatQuantity(input: number | null, output: number | null): string {
  if (input === null && output === null) {
    return '没有用量记录'
  }
  return `读入 ${input ?? 0} 字，写出 ${output ?? 0} 字`
}

export function isDevLogin(): boolean {
  return import.meta.env.VITE_SAND_DEV_LOGIN === '1'
}

function parseUtc(raw: string): Date | null {
  const text = raw.trim()
  if (text === '') {
    return null
  }
  const iso = text.includes('T') ? text : text.replace(' ', 'T')
  const withZone = /[zZ]|[+-]\d{2}:?\d{2}$/.test(iso) ? iso : `${iso}Z`
  const date = new Date(withZone)
  if (Number.isNaN(date.getTime())) {
    return null
  }
  return date
}

function pad(value: number): string {
  return String(value).padStart(2, '0')
}
