import request from '@/utils/http'
import { ADMIN_API_PREFIX } from './config'

/**
 * 费用周期汇总接口（0.5.0）：月度分组排行与按天花费序列。
 * 月份与日期边界由宿主时区决定，时区名随响应返回；金额按币种分行，
 * 不同币种不能相加。
 */

export type UsageGroupBy = 'application' | 'model'

export interface UsagePeriodFilters {
  organization_id: number | null
  environment_id: number | null
  application_id: number | null
  provider_id: number | null
  model_id: number | null
  from_time: string
  to_time: string
}

export interface UsageMonthlyQuery {
  group_by?: UsageGroupBy
  month?: string
  month_from?: string
  month_to?: string
  organization_id?: number
  environment_id?: number
  application_id?: number
  provider_id?: number
  model_id?: number
}

export interface UsageDailyQuery {
  month?: string
  date_from?: string
  date_to?: string
  organization_id?: number
  environment_id?: number
  application_id?: number
  provider_id?: number
  model_id?: number
}

/** 月度行：group_by 决定 application_id 或 model_id 之一有值；name/deleted 为中文目录名。 */
export interface UsageMonthlyRow {
  month: string
  application_id?: number | null
  model_id?: number | null
  name: string | null
  deleted: boolean
  currency: string | null
  amount: string | null
  call_count: number
  failed_count: number
  unpriced_count: number
  unmeasured_count: number
}

export interface UsageMonthlyResult {
  group_by: UsageGroupBy
  timezone: string
  month_from: string
  month_to: string
  filters: UsagePeriodFilters
  rows: UsageMonthlyRow[]
  truncated: boolean
}

export interface UsageDailyRow {
  date: string
  currency: string | null
  amount: string | null
  call_count: number
  failed_count: number
  unpriced_count: number
  unmeasured_count: number
}

export interface UsageDailyResult {
  timezone: string
  date_from: string
  date_to: string
  filters: UsagePeriodFilters
  rows: UsageDailyRow[]
  truncated: boolean
}

const prefix = `${ADMIN_API_PREFIX}/usage`

export default {
  monthly(params: UsageMonthlyQuery = {}) {
    return request.get<UsageMonthlyResult>({
      url: `${prefix}/monthly`,
      params,
      showErrorMessage: false
    })
  },
  daily(params: UsageDailyQuery = {}) {
    return request.get<UsageDailyResult>({
      url: `${prefix}/daily`,
      params,
      showErrorMessage: false
    })
  }
}
