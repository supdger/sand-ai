/**
 * C-04 已冻结：管理端仅走真实管理 API（不再保留前端 mock 通路）。
 */

/** 插件版本（与 info.ini 的 version 保持一致，仅用于「复制问题信息」脱敏字段） */
export const SAND_AI_PLUGIN_VERSION = '0.4.9'

/** 管理 API 前缀（C-04） */
export const ADMIN_API_PREFIX = '/app/sand-ai/admin'

/** 运行面 API 前缀（契约 §2.6；开发代理见 vite `/api/sand-ai`） */
export const RUNTIME_API_PREFIX = '/api/sand-ai/v1'

/** 文件中心会话内保存的环境凭证（仅本机 sessionStorage） */
export const FILE_API_KEY_STORAGE = 'sand_ai_file_runtime_key'
