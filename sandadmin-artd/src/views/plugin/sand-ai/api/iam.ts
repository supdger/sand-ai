import request from '@/utils/http'
import type { DataStatus, SandAiPage } from './types'

const SAND_IAM_ADMIN_PREFIX = '/app/sand-iam/admin'

export interface SandIamApplicationReference {
  readonly id: number
  readonly organization_id: number
  readonly code: string
  readonly name: string
  readonly status: DataStatus
}

export interface SandIamEnvironmentReference {
  readonly id: number
  readonly application_id: number
  readonly code: string
  readonly name: string
  readonly status: DataStatus
}

export default {
  listApplications(params: { readonly page: number; readonly limit: number }) {
    return request.get<SandAiPage<SandIamApplicationReference>>({
      url: `${SAND_IAM_ADMIN_PREFIX}/application/index`,
      params
    })
  },

  listEnvironments(params: { readonly page: number; readonly limit: number }) {
    return request.get<SandAiPage<SandIamEnvironmentReference>>({
      url: `${SAND_IAM_ADMIN_PREFIX}/environment/index`,
      params
    })
  }
}
