import request from '@/utils/http'
import { ADMIN_API_PREFIX } from './config'

export type SandAiDataEgressPolicy = 'local_only' | 'private_only' | 'approved_cloud'

export type SandAiCapabilityRoute = {
  id?: number
  capability_code: string
  route_kind: 'model_deployment' | 'parse_driver' | 'audio_driver'
  deployment_id?: number | null
  driver_code?: string | null
  priority: number
  config?: Record<string, unknown>
  status?: number
}

export type SandAiCapabilityProfile = {
  id: number
  environment_id: number
  name: string
  template_code: string | null
  data_egress_policy: SandAiDataEgressPolicy | string
  revision: number
  state: string
  status: number
  routes?: SandAiCapabilityRoute[]
}

export type SandAiCapabilityTemplate = {
  name: string
  data_egress_policy: SandAiDataEgressPolicy | string
  default_routes: SandAiCapabilityRoute[]
}

export type SandAiCapabilityMetadata = {
  capabilities: Record<string, Record<string, unknown>>
  templates: Record<string, SandAiCapabilityTemplate>
}

/** 后端解析出的应用/环境目标；application_id 在无 SandIAM 时可为 null。 */
export type CapabilityTarget = {
  application_id: number | null
  environment_id: number
}

/** 读取/写入可定向参数：字段缺省时由后端在唯一候选时自动解析。 */
export type CapabilityTargetParams = {
  application_id?: number
  environment_id?: number
}

export type CapabilitySavePayload = {
  application_id?: number
  environment_id: number
  expected_revision: number | null
  name: string
  template_code?: string | null
  data_egress_policy?: SandAiDataEgressPolicy | string
  routes: SandAiCapabilityRoute[]
}

export type CapabilityPublishPayload = {
  application_id?: number
  environment_id: number
  id: number
  expected_revision?: number
}

export default {
  metadata() {
    return request.get<SandAiCapabilityMetadata>({
      url: `${ADMIN_API_PREFIX}/setup/capability/metadata`,
      showErrorMessage: false
    })
  },

  readProfile(target?: number | CapabilityTargetParams) {
    const params: CapabilityTargetParams = typeof target === 'number'
      ? { environment_id: target }
      : (target ?? {})
    return request.get<{ profile: SandAiCapabilityProfile | null; target?: CapabilityTarget }>({
      url: `${ADMIN_API_PREFIX}/setup/capability/profile/read`,
      params,
      showErrorMessage: false
    })
  },

  saveProfile(payload: CapabilitySavePayload) {
    return request.post<{ profile: SandAiCapabilityProfile; target?: CapabilityTarget }>({
      url: `${ADMIN_API_PREFIX}/setup/capability/profile/save`,
      data: payload,
      showErrorMessage: false
    })
  },

  publishProfile(payload: CapabilityPublishPayload) {
    return request.post<{ profile: SandAiCapabilityProfile; target?: CapabilityTarget }>({
      url: `${ADMIN_API_PREFIX}/setup/capability/profile/publish`,
      data: payload,
      showErrorMessage: false
    })
  }
}
