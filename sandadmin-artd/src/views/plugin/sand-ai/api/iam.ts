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

export interface SandIamWorkloadClientReference {
  readonly id: number
  readonly environment_id: number
  readonly code: string
  readonly name: string
  readonly status: DataStatus
}

async function readAllActive<T extends { readonly id: number; readonly status: DataStatus; readonly application_id?: number }>(
  path: string,
  filters: { readonly status: 1; readonly application_id?: number }
): Promise<T[]> {
  const rows: T[] = []
  const seen = new Set<number>()
  for (let page = 1; ; page++) {
    const result = await request.get<SandAiPage<T>>({
      url: `${SAND_IAM_ADMIN_PREFIX}/${path}/index`,
      params: { ...filters, page, limit: 100 }
    })
    if (result.current_page !== page || !Number.isSafeInteger(result.total) || result.total < 0
      || !Array.isArray(result.data) || result.data.length > 100) {
      throw new Error('SandIAM 列表返回不完整，请重新读取')
    }
    const previousCount = rows.length
    for (const row of result.data) {
      if (!Number.isSafeInteger(row.id) || row.id < 1 || row.status !== 1
        || (filters.application_id !== undefined && row.application_id !== filters.application_id)) {
        throw new Error('SandIAM 列表与所选应用不一致，请重新读取')
      }
      if (!seen.has(row.id)) {
        seen.add(row.id)
        rows.push(row)
      }
    }
    if (rows.length >= result.total) return rows
    if (result.data.length < 100 || rows.length === previousCount) {
      throw new Error('SandIAM 列表尚未读取完整，请重新读取')
    }
  }
}

export default {
  activeApplications() {
    return readAllActive<SandIamApplicationReference>('application', { status: 1 })
  },

  activeEnvironments(applicationId: number) {
    return readAllActive<SandIamEnvironmentReference>('environment', {
      status: 1, application_id: applicationId
    })
  },

  listApplications(params: { readonly page: number; readonly limit: number }) {
    return request.get<SandAiPage<SandIamApplicationReference>>({
      url: `${SAND_IAM_ADMIN_PREFIX}/application/index`,
      params
    })
  },

  listClients(params: { readonly application_id: number; readonly page: number; readonly limit: number }) {
    return request.get<SandAiPage<SandIamWorkloadClientReference>>({
      url: `${SAND_IAM_ADMIN_PREFIX}/client/index`, params
    })
  },

  listEnvironments(params: { readonly page: number; readonly limit: number }) {
    return request.get<SandAiPage<SandIamEnvironmentReference>>({
      url: `${SAND_IAM_ADMIN_PREFIX}/environment/index`,
      params
    })
  }
}
