/**
 * 文件大小统一写法（与 sandadmin#77 口径一致）：1024 进位，
 * B 取整不带小数，KB 及以上保留一位小数，不再用中文单位。
 */

const FILE_SIZE_UNITS = ['B', 'KB', 'MB', 'GB', 'TB'] as const

/** 字节数 → 「70 B」「1.5 KB」「2.3 MB」；无法解释时返回 —。 */
export function formatFileSize(bytes: number | null | undefined): string {
  if (bytes === null || bytes === undefined || !Number.isFinite(bytes) || bytes < 0) return '—'
  if (bytes < 1024) return `${Math.trunc(bytes)} ${FILE_SIZE_UNITS[0]}`
  let value = bytes
  let unitIndex = 0
  while (value >= 1024 && unitIndex < FILE_SIZE_UNITS.length - 1) {
    value /= 1024
    unitIndex += 1
  }
  return `${value.toFixed(1)} ${FILE_SIZE_UNITS[unitIndex]}`
}
