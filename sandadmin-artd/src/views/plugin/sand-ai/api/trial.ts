/**
 * B2「试一试」管理端通道：GET trial/options 投影 + POST trial/chat 单条对话。
 *
 * - options 只读，按 application_id 返回当前管理员可管理应用下已发布 chat
 *   路线的服务商投影（0/1/多由页面渲染）；不做任何外部探测。
 * - chat 必须携带 Idempotency-Key：每次用户主动发送生成新键；同一请求的不
 *   确定重试只复用同键同载荷，禁止换键重发（后端按指纹判重/重放）。
 * - chat 走 ChatGateway 全链（预算/计费/幂等/receipt），单次回复可能超过
 *   宿主默认 15s 超时，因此单独放宽到 150s（略高于服务商默认 120s 超时）。
 */
import request from '@/utils/http'
import { ADMIN_API_PREFIX } from './config'

export interface TrialProviderModel {
  readonly id: number
  readonly name: string
}

export interface TrialProviderOption {
  readonly id: number
  readonly name: string
  readonly models: TrialProviderModel[]
  readonly default_model_id: number
}

export interface TrialOptions {
  readonly application: { readonly id: number; readonly name: string }
  readonly providers: TrialProviderOption[]
  readonly default_max_output_tokens: number
}

export interface TrialChatResult {
  readonly application: { readonly id: number; readonly name: string }
  readonly provider: { readonly id: number; readonly name: string }
  readonly model: { readonly id: number; readonly name: string }
  readonly content: string
  readonly latency_ms: number
  readonly usage: unknown
  readonly invocation_id: number | null
  readonly idempotent_replay: boolean
}

export interface TrialChatInput {
  readonly application_id: number
  readonly provider_id: number
  readonly message: string
  readonly model_id?: number
  readonly max_output_tokens?: number
}

export async function trialOptions(applicationId: number): Promise<TrialOptions> {
  return request.get<TrialOptions>({
    url: `${ADMIN_API_PREFIX}/trial/options`,
    params: { application_id: applicationId },
    showErrorMessage: false,
    headers: { 'Cache-Control': 'no-store' }
  })
}

export async function trialChat(
  input: TrialChatInput,
  idempotencyKey: string,
  signal?: AbortSignal
): Promise<TrialChatResult> {
  return request.post<TrialChatResult>({
    url: `${ADMIN_API_PREFIX}/trial/chat`,
    data: input,
    timeout: 150000,
    signal,
    headers: { 'Idempotency-Key': idempotencyKey, 'Cache-Control': 'no-store' },
    showErrorMessage: false
  })
}

export default { trialOptions, trialChat }
