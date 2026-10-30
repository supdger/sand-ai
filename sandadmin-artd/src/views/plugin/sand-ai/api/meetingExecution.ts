import request from '@/utils/http'
import { ADMIN_API_PREFIX } from './config'

export type MeetingExecutionState =
  | 'unconfigured'
  | 'disabled'
  | 'enabled'
  | 'revoked'
  | 'stale'
  | 'unsupported'
  | 'worker_unavailable'

export interface MeetingExecutionOwner {
  organization_id: number
  application_id: number
  environment_id: number
}

export interface MeetingTokenizerDriver {
  code: string
  display_name: string
  context_window_ceiling: number
  request_limit_min: number
  request_limit_max: number
}

export interface MeetingPlanningDriver {
  code: 'neuron_delimiter_splitter@1'
  driver: 'neuron_delimiter_splitter@1'
  sdk_package: 'neuron-core/neuron-ai'
  sdk_version: '4.0.2'
  max_length: 12000
  word_overlap: 0
  min_length: 0
  token_count: null
  token_count_state: 'not_measured'
  counter_request_count: 0
}

export interface MeetingPublishedRoute {
  model: string
  display_name: string
  deployment_id: number
  deployment_revision: number
  profile_id: number
  profile_revision: number
  route_sha256: string
  max_output_tokens: number
  input_token_ceiling: number | null
  tokenizer_drivers: MeetingTokenizerDriver[]
  planning_drivers?: MeetingPlanningDriver[]
}

interface MeetingCapacityBase {
  context_window_tokens: number
  input_token_limit: number
  capacity_source_url: string
  capacity_source_sha256: string
}

export type MeetingExecutionCapacity = MeetingCapacityBase & (
  | { tokenizer_driver: string; planning_driver?: never; max_tokenizer_requests: number }
  | { planning_driver: 'neuron_delimiter_splitter@1'; tokenizer_driver?: never; max_tokenizer_requests: 0 }
)

export interface MeetingExecutionRegistration {
  id: number
  revision: number
  state: 'disabled' | 'enabled' | 'revoked'
  route_sha256: string
  deployment_revision: number
  profile_revision: number
  capacity: MeetingExecutionCapacity
}

export interface MeetingExecutionSelection {
  route: MeetingPublishedRoute
  registration: MeetingExecutionRegistration | null
  state: MeetingExecutionState
  can_enable: boolean
  readiness: { ready: boolean; reason_codes: string[] }
  next_action: string
}

export interface MeetingExecutionRead {
  owner: MeetingExecutionOwner
  routes: MeetingPublishedRoute[]
  selection: MeetingExecutionSelection | null
}

export interface MeetingExecutionExpectedRoute {
  environment_id: number
  model: string
  expected_route_sha256: string
  expected_deployment_revision: number
  expected_profile_revision: number
  expected_revision: number
}

export interface MeetingExecutionSave extends MeetingExecutionExpectedRoute {
  capacity: MeetingExecutionCapacity
}

export interface MeetingExecutionStateChange extends MeetingExecutionExpectedRoute {
  enabled: boolean
}

const endpoint = `${ADMIN_API_PREFIX}/setup/meeting-execution`

export default {
  read(environmentId: number, model?: string) {
    return request.get<MeetingExecutionRead>({
      url: `${endpoint}/read`,
      params: { environment_id: environmentId, ...(model ? { model } : {}) },
      showErrorMessage: false
    })
  },
  save(data: MeetingExecutionSave) {
    return request.post<{ selection: MeetingExecutionSelection }>({
      url: `${endpoint}/register`,
      data,
      showErrorMessage: false
    })
  },
  state(data: MeetingExecutionStateChange) {
    return request.post<{ selection: MeetingExecutionSelection }>({
      url: `${endpoint}/state`,
      data,
      showErrorMessage: false
    })
  }
}
