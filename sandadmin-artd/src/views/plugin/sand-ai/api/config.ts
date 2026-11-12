/**
 * C-04 已冻结：管理端仅走真实管理 API（不再保留前端 mock 通路）。
 */

/** 插件版本（与 info.ini 的 version 保持一致，仅用于「复制问题信息」脱敏字段） */
export const SAND_AI_PLUGIN_VERSION = '0.4.10'

/** 管理 API 前缀（C-04） */
export const ADMIN_API_PREFIX = '/app/sand-ai/admin'

/** 运行面 API 前缀（契约 §2.6）。浏览器里只出现一次 /api。 */
export const RUNTIME_API_PREFIX = '/api/sand-ai/v1'

/**
 * 运行面请求的完整地址。
 * 管理端 VITE_API_URL 已是 /api 时不再拼接，否则会变成 /api/api/sand-ai。
 */
export function runtimeAbsolutePath(path: string): string {
  const suffix = path.startsWith('/') ? path : `/${path}`
  const full = `${RUNTIME_API_PREFIX}${suffix}`
  const base = String(import.meta.env.VITE_API_URL || '').replace(/\/$/, '')
  if (base === '' || base === '/api') return full
  if (base.endsWith('/api')) return `${base.slice(0, -'/api'.length)}${full}`
  return `${base}${full}`
}

/** 文件中心会话内保存的环境凭证（仅本机 sessionStorage） */
export const FILE_API_KEY_STORAGE = 'sand_ai_file_runtime_key'
