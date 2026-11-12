/**
 * 业务时间字段展示统一换算到浏览器本地时区。
 * 常规业务表（文件、知识库、调用、审计等）按部署时区 Asia/Shanghai 存墙钟
 * 'YYYY-MM-DD HH:mm:ss'；用量事件 create_time 按 UTC 存墙钟。两者都需要
 * 先解释成正确的时间点，再按浏览器本地时区格式化。
 */

const SERVER_OFFSET = '+08:00'
const UTC_OFFSET = '+00:00'

function pad(value: number): string {
  return String(value).padStart(2, '0')
}

/** 墙钟字符串 → 时间点；无法解释时返回 null。 */
function parseWallClock(value: string, offset: string): Date | null {
  const match = /^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})(?::(\d{2}))?(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?$/.exec(value.trim())
  if (match === null) return null
  const [, year, month, day, hour, minute, second] = match
  const date = new Date(`${year}-${month}-${day}T${hour}:${minute}:${second ?? '00'}${offset}`)
  return Number.isNaN(date.getTime()) ? null : date
}

/** 部署时区墙钟 → 时间点。 */
export function serverTimePoint(value: string | null | undefined): Date | null {
  if (typeof value !== 'string' || value.trim() === '') return null
  return parseWallClock(value, SERVER_OFFSET)
}

/** UTC 墙钟 → 时间点。 */
export function utcTimePoint(value: string | null | undefined): Date | null {
  if (typeof value !== 'string' || value.trim() === '') return null
  return parseWallClock(value, UTC_OFFSET)
}

function formatPoint(date: Date, withSeconds: boolean): string {
  const base = `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ` +
    `${pad(date.getHours())}:${pad(date.getMinutes())}`
  return withSeconds ? `${base}:${pad(date.getSeconds())}` : base
}

/** 部署时区墙钟 → 浏览器本地 'YYYY-MM-DD HH:mm:ss'；空值或无法解释回退 —。 */
export function formatServerDateTime(value: string | null | undefined): string {
  const point = serverTimePoint(value)
  return point === null ? '—' : formatPoint(point, true)
}

/**
 * 部署时区墙钟 → 浏览器本地分钟显示；与今天同年时省略年份
 * （'MM-DD HH:mm'），跨年保留完整年份。空值或无法解释回退 —。
 */
export function formatServerMinute(value: string | null | undefined): string {
  const point = serverTimePoint(value)
  if (point === null) return '—'
  const now = new Date()
  const minute = `${pad(point.getMonth() + 1)}-${pad(point.getDate())} ${pad(point.getHours())}:${pad(point.getMinutes())}`
  return point.getFullYear() === now.getFullYear()
    ? minute
    : `${point.getFullYear()}-${minute}`
}

/** UTC 墙钟 → 浏览器本地 'YYYY-MM-DD HH:mm:ss'；空值或无法解释回退 —。 */
export function formatUtcDateTime(value: string | null | undefined): string {
  const point = utcTimePoint(value)
  return point === null ? '—' : formatPoint(point, true)
}
