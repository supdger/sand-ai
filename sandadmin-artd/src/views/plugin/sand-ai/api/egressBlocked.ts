/**
 * 服务商出站地址被安全拦截的结构化标记。
 *
 * 后端在业务 400 响应 `data` 中返回 `reason_code = 'resolved_address_blocked'`
 * 及脱敏字段（service_host、blocked_range），表示公网服务域被代理解析进
 * 受保护网段。`blocked_range` 是被拦截地址命中的保留网段标识（CIDR 或
 * 类别名），不含具体解析 IP；它只进入「复制问题信息」，不直接渲染在页面上。
 */

import { isHttpError } from '@/utils/http/error'
import { SAND_AI_PLUGIN_VERSION } from './config'

export interface EgressBlockedDetails {
  /** 管理员填写的公网服务域名（如 open.bigmodel.cn），可直接展示 */
  serviceHost: string
  /** 本次被拦截地址命中的保留网段标识，仅进「复制问题信息」，不展示在页面 */
  blockedRange: string
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

export function egressBlockedDetails(error: unknown): EgressBlockedDetails | null {
  if (!isHttpError(error) || error.code !== 400 || !isRecord(error.data)) return null
  if (error.data.reason_code !== 'resolved_address_blocked') return null
  const host = error.data.service_host
  if (typeof host !== 'string' || host === '' || host.length > 253) return null
  const range = error.data.blocked_range
  return {
    serviceHost: host,
    blockedRange: typeof range === 'string' && range.length <= 45 ? range : ''
  }
}

/**
 * 「复制问题信息」文本：只含错误码、时间、版本与服务地址等脱敏字段；
 * 不含路径、堆栈、解析出的具体内网地址或任何凭证内容。`blockedRange`
 * 允许进入此文本，供管理员填写放行名单时核对，但绝不上页面。
 */
export function egressProblemInfo(details: EgressBlockedDetails): string {
  const lines = [
    'SandAI 管理页问题信息',
    '错误码：SAND_AI_ONBOARDING_CONNECTION_FORBIDDEN',
    `时间：${new Date().toISOString()}`,
    `版本：${SAND_AI_PLUGIN_VERSION}`,
    `服务地址：${details.serviceHost}`
  ]
  if (details.blockedRange !== '') lines.push(`被拦截网段：${details.blockedRange}`)
  return lines.join('\n')
}
