import request from '@/utils/http'
import { ADMIN_API_PREFIX } from './config'

export type SandAiDataEgressPolicy = 'local_only' | 'private_only' | 'approved_cloud'

export type SandAiCapabilityRoute = {
  id?: number
  capability_code: string
  route_kind: 'model_deployment' | 'parse_driver' | string
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

export type CapabilitySavePayload = {
  environment_id: number
  name: string
  template_code?: string | null
  data_egress_policy?: SandAiDataEgressPolicy | string
  routes: SandAiCapabilityRoute[]
}

export default {
  metadata() {
    return request.get<SandAiCapabilityMetadata>({
      url: `${ADMIN_API_PREFIX}/setup/capability/metadata`
    })
  },

  readProfile(environmentId: number) {
    return request.get<{ profile: SandAiCapabilityProfile | null }>({
      url: `${ADMIN_API_PREFIX}/setup/capability/profile/read`,
      params: { environment_id: environmentId }
    })
  },

  saveProfile(payload: CapabilitySavePayload) {
    return request.post<{ profile: SandAiCapabilityProfile }>({
      url: `${ADMIN_API_PREFIX}/setup/capability/profile/save`,
      data: payload
    })
  },

  publishProfile(payload: { environment_id: number; id: number }) {
    return request.post<{ profile: SandAiCapabilityProfile }>({
      url: `${ADMIN_API_PREFIX}/setup/capability/profile/publish`,
      data: payload
    })
  }
}
