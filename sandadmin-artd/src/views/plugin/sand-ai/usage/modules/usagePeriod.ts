/**
 * 费用页周期工具：月份边界按宿主时区换算成世界标准时间（UTC）字符串，
 * 与后端 UsagePeriodPolicy 的口径一致（period_end 为不含端点的下界）。
 */

const MONTH_PATTERN = /^\d{4}-\d{2}$/

export function isMonth(value: string): boolean {
  return MONTH_PATTERN.test(value)
}

/** 'YYYY-MM' 加减月份，返回 'YYYY-MM'。 */
export function shiftMonth(month: string, delta: number): string {
  const [year, mon] = month.split('-').map(Number)
  const date = new Date(Date.UTC(year, mon - 1 + delta, 1))
  return `${date.getUTCFullYear()}-${String(date.getUTCMonth() + 1).padStart(2, '0')}`
}

interface ZonedParts {
  year: number
  month: number
  day: number
  hour: number
  minute: number
  second: number
}

function zonedParts(date: Date, timeZone: string): ZonedParts {
  const formatter = new Intl.DateTimeFormat('en-US', {
    timeZone,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hour12: false
  })
  const parts: Record<string, number> = {}
  for (const part of formatter.formatToParts(date)) {
    if (part.type !== 'literal') parts[part.type] = Number(part.value)
  }
  // hour 可能为 24（午夜），归一化为 0
  const hour = parts.hour === 24 ? 0 : parts.hour
  return { year: parts.year, month: parts.month, day: parts.day, hour, minute: parts.minute, second: parts.second }
}

/**
 * 把「某时区的本地时间」换算成 UTC 的 'YYYY-MM-DD HH:mm:ss'。
 * 用两轮偏移修正逼近，覆盖常规时区（含半小时时区）。
 */
export function zonedLocalToUtc(local: ZonedParts, timeZone: string): string | null {
  try {
    const desired = Date.UTC(local.year, local.month - 1, local.day, local.hour, local.minute, local.second)
    let utc = desired
    for (let round = 0; round < 3; round += 1) {
      const zoned = zonedParts(new Date(utc), timeZone)
      const zonedAsUtc = Date.UTC(zoned.year, zoned.month - 1, zoned.day, zoned.hour, zoned.minute, zoned.second)
      const diff = desired - zonedAsUtc
      if (diff === 0) break
      utc += diff
    }
    const check = zonedParts(new Date(utc), timeZone)
    const checkAsUtc = Date.UTC(check.year, check.month - 1, check.day, check.hour, check.minute, check.second)
    if (checkAsUtc !== desired) return null
    const result = new Date(utc)
    const pad = (value: number) => String(value).padStart(2, '0')
    return `${result.getUTCFullYear()}-${pad(result.getUTCMonth() + 1)}-${pad(result.getUTCDate())} ` +
      `${pad(result.getUTCHours())}:${pad(result.getUTCMinutes())}:${pad(result.getUTCSeconds())}`
  } catch {
    return null
  }
}

/**
 * 某月（宿主时区）的起止边界，返回 UTC 'YYYY-MM-DD HH:mm:ss'。
 * to 为下月 1 日 0 点（不含端点），与后端 month 口径一致。
 */
export function monthBoundsUtc(month: string, timeZone: string): { from: string; to: string } | null {
  if (!isMonth(month)) return null
  const [year, mon] = month.split('-').map(Number)
  const from = zonedLocalToUtc({ year, month: mon, day: 1, hour: 0, minute: 0, second: 0 }, timeZone)
  const next = shiftMonth(month, 1)
  const [ny, nm] = next.split('-').map(Number)
  const to = zonedLocalToUtc({ year: ny, month: nm, day: 1, hour: 0, minute: 0, second: 0 }, timeZone)
  return from !== null && to !== null ? { from, to } : null
}

const CURRENCY_SYMBOLS: Record<string, string> = { CNY: '¥', USD: '$', EUR: '€', JPY: '¥' }

/** 金额展示：null 表示未定价，返回「未定价」；否则保留两位小数。 */
export function formatMoney(amount: string | null, currency: string | null): string {
  if (amount === null || amount === '') return '未定价'
  const value = Number(amount)
  if (!Number.isFinite(value)) return '未定价'
  const code = currency ?? ''
  const symbol = CURRENCY_SYMBOLS[code] ?? (code ? `${code} ` : '')
  return `${symbol}${value.toFixed(2)}`
}

/** 统计行金额求和：只累加已定价行；返回 null 表示该币种没有任何已定价金额。 */
export function sumPricedAmounts(amounts: (string | null)[]): string | null {
  let total = 0
  let seen = false
  for (const amount of amounts) {
    if (amount === null || amount === '') continue
    const value = Number(amount)
    if (!Number.isFinite(value)) continue
    total += value
    seen = true
  }
  return seen ? total.toFixed(6) : null
}
