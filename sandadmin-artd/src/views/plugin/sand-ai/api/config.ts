/**
 * C-04 已冻结：默认走真实管理 API。
 * 仅本地无后端时可临时改为 true。
 */
export const USE_SCHEMA_MOCK = false

/** 管理 API 前缀（C-04） */
export const ADMIN_API_PREFIX = '/app/sand-ai/admin'

/** 运行面 API 前缀（契约 §2.6；开发代理见 vite `/api/sand-ai`） */
export const RUNTIME_API_PREFIX = '/api/sand-ai/v1'

/** 文件中心会话内保存的环境凭证（仅本机 sessionStorage） */
export const FILE_API_KEY_STORAGE = 'sand_ai_file_runtime_key'
