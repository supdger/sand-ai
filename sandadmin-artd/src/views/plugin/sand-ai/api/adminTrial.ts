/**
 * 管理员试用通道：后台登录会话换取 SandIAM 短期运行身份。
 * 响应只含签名 context（约 5 分钟），永不含调用凭证明文。
 */
import request from '@/utils/http'
import { ADMIN_API_PREFIX } from './config'

export interface AdminTrialContext {
  context: string
  expire_time: string
  actions: string[]
}

export async function requestAdminTrialContext(actions?: string[]): Promise<AdminTrialContext> {
  return request.post<AdminTrialContext>({
    url: `${ADMIN_API_PREFIX}/trial/context`,
    data: actions === undefined ? {} : { actions },
    showErrorMessage: false,
    headers: { 'Cache-Control': 'no-store' }
  })
}

export default { requestAdminTrialContext }
