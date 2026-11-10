/**
 * SandAI 管理端 API 入口（C-04 契约）。
 *
 * - 仅请求真实后台 `/app/sand-ai/admin/...`
 */

export { ADMIN_API_PREFIX, RUNTIME_API_PREFIX, SAND_AI_PLUGIN_VERSION } from './config'
export type * from './types'
export { SandAiRuntimeError } from './runtime'
export {
  RUNTIME_ERROR_HINTS,
  hintForRuntimeErrorCode,
  toRuntimeUiError
} from './runtimeErrors'
export type { RuntimeUiError } from './runtimeErrors'

export { default as iamApi } from './iam'
export { default as providerApi } from './provider'
export { default as modelApi } from './model'
export { default as invocationApi } from './invocation'
export { default as usageApi } from './usage'
export { default as auditApi } from './audit'
export { default as fileApi } from './file'
export { default as taskApi } from './task'
