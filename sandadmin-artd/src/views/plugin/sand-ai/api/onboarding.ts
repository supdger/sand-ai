import request from '@/utils/http'
import { HttpError } from '@/utils/http/error'
import { ADMIN_API_PREFIX } from './config'
import type { SandAiPage } from './types'

const setup = `${ADMIN_API_PREFIX}/setup`

export interface SolutionGuide {
  display_name: string
  plain_summary: string
  when_to_use: string
  what_you_need: string[]
  outcome: string
  setup_steps: string[]
  module_explanation: string
  readiness_note: string
  technical: { code: string; version: string; capability_codes: string[]; module_codes: string[] }
}

export interface SolutionGuideCatalog {
  templates: SolutionGuide[]
  professional_mode_hint: string
}

export interface ServiceCatalogPreflight {
  port_available: boolean
  can_register: boolean
  service_status: 'missing' | 'active' | 'disabled' | null
  action_statuses: Record<string, 'missing' | 'active' | 'disabled'>
  service_code: string
  action_codes: string[]
}

export interface ServiceCatalogRegistration {
  registered: boolean
  service_code: string
  action_codes: string[]
}

export interface AudioRuntimeMetadata {
  runtime_state: 'route_available'
  readiness_note: string
  adapter_kinds: Array<{
    display_name: string
    runtime_state: 'configured' | 'not_configured'
    technical: { adapter_kind: 'local' | 'external' }
  }>
  technical: {
    workload_endpoint_installed: boolean
    catalog_version: string
  }
}

export interface ProviderGuide {
  adapter: string
  provider_code?: string
  name: string
  default_base_url?: string
  api_key_url?: string
  billing_help_url?: string
  api_key_help?: string
  data_egress_notice?: string
  discovery_notice?: string
  debug_only?: boolean
  recommended_models?: Array<{ remote_model: string; recommended: boolean }>
  fields?: Array<{ name: string; label: string; purpose: string }>
}

export interface DiscoveredModel {
  remote_model: string
  owner: string | null
}

export interface PublishedDeployment {
  id: number
  deployment_revision: number
  display_name: string
  model_type: string
  publication_status: 'published'
  compatible_data_egress_policies: string[]
}

export interface DraftSave {
  deployment_id: number
  revision: number
  state: VerificationState
  idempotent_replay: boolean
  provider: { id: number; code: string; name: string }
  model: { id: number; code: string; name: string; type: string; capabilities: string[] }
  deployment: {
    remote_model: string
    config: {
      max_output_tokens?: number
      input_token_ceiling?: number
      reasoning_effort?: ReasoningEffort
      skill_output_strategy?: SkillOutputStrategy
      verification_voice?: string
    }
  }
}

export type VerificationState = 'draft' | 'verifying' | 'tested' | 'verification_ambiguous' | 'published'
export type ReasoningEffort = 'low' | 'high' | 'max'
export type SkillOutputStrategy = 'neuron_json_extract_v1' | 'neuron_zai_json_object_v1' | 'neuron_openai_json_schema_v1'

export type VerificationFailureReason = 'invalid_api_key' | 'provider_access_denied'
  | 'model_or_endpoint_unavailable' | 'chat_endpoint_unsupported' | 'model_or_parameters_rejected'
  | 'provider_rejected' | 'provider_timeout' | 'provider_unavailable' | 'provider_rate_limited'
  | 'verification_ambiguous' | 'local_runtime_verification_failed'
export type VerificationRecoveryAction = 'reconfigure_connection' | 'check_provider_record'
  | 'configure_local_runtime_and_save_draft'
export interface VerificationFailure {
  error_code: 'SAND_AI_PROVIDER_REJECTED' | 'SAND_AI_PROVIDER_UNAVAILABLE'
    | 'SAND_AI_PROVIDER_RATE_LIMITED' | 'SAND_AI_DEPLOYMENT_VERIFICATION_AMBIGUOUS'
    | 'SAND_AI_OCR_UNAVAILABLE' | 'SAND_AI_PARSE_FAILED' | 'SAND_AI_PARSE_EMPTY'
    | 'SAND_AI_OCR_DISPATCH_AMBIGUOUS' | 'SAND_AI_CONFIGURATION_DRIFTED'
  reason: VerificationFailureReason
  message: string
  recovery_action: VerificationRecoveryAction
  outcome_uncertain: boolean
  failed_at: string | null
}

export interface Verification {
  deployment_id: number
  revision: number
  state: VerificationState
  failure?: VerificationFailure | null
  validation_evidence: { usage_measured: boolean; input_tokens: number | null; output_tokens: number | null; total_tokens: number | null } | null
}

export type ProviderConnectionHeader = 'OpenAI-Organization' | 'OpenAI-Project' | 'X-Api-Key' | 'X-Organization' | 'X-Project'

export interface NewDeployment {
  provider: { code: string; name: string; adapter: 'openai_compatible'; timeout_ms: number }
  model: { code: string; name: string; type: 'chat'; capabilities: ['chat'] | ['chat', 'structured_extraction'] }
  connection: { base_url: string; api_key: string; extra_headers?: Partial<Record<ProviderConnectionHeader, string>> }
  remote_model: string
  config: { max_output_tokens: number; reasoning_effort?: ReasoningEffort; skill_output_strategy?: SkillOutputStrategy }
}

export interface SuccessorDeployment {
  provider_id: number
  model_id: number
  remote_model: string
  config: NewDeployment['config'] & { input_token_ceiling?: number }
}

export interface ProviderModelDeployment {
  provider_id: number
  model: { code: string; name: string; type: 'chat'; capabilities: ['chat'] | ['chat', 'structured_extraction'] }
  remote_model: string
  config: NewDeployment['config'] & { input_token_ceiling?: number }
}

export type OnboardingStageCode = 'connect_provider' | 'discover_model' | 'verify_model'
  | 'publish_configuration' | 'bind_environment' | 'configuration_published'

export interface OnboardingStatus {
  provider: { configured: boolean }
  model: { configured: boolean }
  deployment: { configured: boolean }
  tested_configuration: { configured: boolean }
  published_deployment: { configured: boolean }
  environment_binding: { environment_id: number; configured: boolean }
  published_configuration: { configured: boolean }
  stage: { code: OnboardingStageCode; name: string; next_step: string }
  internal_security?: {
    ready: boolean
    missing_groups: string[]
    invalid_groups: string[]
    recovery_action: 'none' | 'initialize_or_restore' | 'restore_original_material'
  }
}

export interface EmbeddingDeployment {
  provider: NewDeployment['provider']
  model: { code: string; name: string; type: 'embedding'; capabilities: ['embedding'] }
  connection: NewDeployment['connection']
  remote_model: string
  config: Record<string, never>
}

export interface SpeechDeployment {
  provider: { code: string; name: string; adapter: 'openai_compatible'; timeout_ms: number }
  model: { code: string; name: string; type: 'audio_speech'; capabilities: ['audio_speech'] }
  connection: { base_url: string; api_key: string }
  remote_model: string
  config: { verification_voice: string; speech_response_format?: 'mp3' | 'wav' }
}

export interface ExternalTranscriptionDeployment {
  provider: { code: string; name: string; adapter: 'openai_compatible'; timeout_ms: number }
  model: { code: string; name: string; type: 'audio_transcription'; capabilities: ['audio_transcription'] }
  connection: { base_url: string; api_key: string }
  remote_model: 'whisper-1' | 'gpt-4o-transcribe-diarize' | 'glm-asr-2512'
  config: Record<string, never>
}

export interface LocalTranscriptionDeployment {
  provider: { code: string; name: string; adapter: 'local_runtime'; timeout_ms: number }
  model: { code: string; name: string; type: 'audio_transcription'; capabilities: ['audio_transcription'] }
  connection?: never
  remote_model: 'local_whisper_cpp'
  config: Record<string, never>
}

export interface LocalOcrDeployment {
  provider: { code: string; name: string; adapter: 'local_ocr_runtime'; timeout_ms: number }
  model: { code: string; name: string; type: 'image_ocr'; capabilities: ['image_ocr'] }
  connection?: never
  remote_model: 'local_paddle_ocr'
  config: Record<string, never>
}

export interface LocalOcrSuccessorDeployment {
  provider_id: number
  model_id: number
  deployment_id?: number
  remote_model: 'local_paddle_ocr'
  config: Record<string, never>
  connection?: never
}

export type TranscriptionDeployment = ExternalTranscriptionDeployment | LocalTranscriptionDeployment

const localInputErrorCodes = [
  'SAND_AI_VALIDATION_ERROR', 'SAND_AI_ONBOARDING_CONNECTION_INVALID',
  'SAND_AI_ONBOARDING_HEADER_INVALID', 'SAND_AI_ONBOARDING_MODEL_INVALID'
] as const

type UnsubmittedInputProof = {
  error_code: typeof localInputErrorCodes[number]
  submission_state: 'not_submitted'
  retryable: false
}

export type SetupBootstrapFailureReason =
  | 'sandiam_unavailable'
  | 'sandiam_disabled'
  | 'catalog_error'
  | 'security_init_failed'

export interface SetupBootstrapReady {
  ready: true
  service_code: string
  groups: { base: number; skill: number; audio: number }
}

export interface SetupBootstrapFailure {
  ready: false
  reason: SetupBootstrapFailureReason
  retryable: boolean
  message: string
}

const bootstrapFailureReasons: readonly SetupBootstrapFailureReason[] = [
  'sandiam_unavailable', 'sandiam_disabled', 'catalog_error', 'security_init_failed'
]

function bootstrapFailureProof(value: unknown): SetupBootstrapFailure | null {
  if (value === null || typeof value !== 'object' || Array.isArray(value)) return null
  if (Reflect.get(value, 'code') !== 400) return null
  const data: unknown = Reflect.get(value, 'data')
  if (data === null || typeof data !== 'object' || Array.isArray(data)) return null
  if (Reflect.get(data, 'ready') !== false) return null
  const reason: unknown = Reflect.get(data, 'reason')
  const knownReason = bootstrapFailureReasons.find(item => item === reason)
  if (!knownReason) return null
  const message: unknown = Reflect.get(value, 'message')
  return {
    ready: false,
    reason: knownReason,
    retryable: Reflect.get(data, 'retryable') === true,
    message: typeof message === 'string' && message ? message : '系统准备未完成'
  }
}

function unsubmittedInputProof(value: unknown): UnsubmittedInputProof | null {
  if (value === null || typeof value !== 'object' || Array.isArray(value)) return null
  const code = Reflect.get(value, 'code')
  const data: unknown = Reflect.get(value, 'data')
  if (code !== 400 || data === null || typeof data !== 'object' || Array.isArray(data)) return null
  const errorCode: unknown = Reflect.get(data, 'error_code')
  if (Reflect.get(data, 'submission_state') !== 'not_submitted' || Reflect.get(data, 'retryable') !== false) return null
  const knownCode = localInputErrorCodes.find(item => item === errorCode)
  return knownCode ? { error_code: knownCode, submission_state: 'not_submitted', retryable: false } : null
}

export default {
  async bootstrap(): Promise<SetupBootstrapReady> {
    let failure: SetupBootstrapFailure | null = null
    try {
      return await request.post<SetupBootstrapReady>({
        url: `${setup}/bootstrap`,
        data: {},
        showErrorMessage: false,
        // The native interceptor drops the business `data` payload on code=400;
        // retain the frozen failure proof so the page can distinguish reasons.
        transformResponse: [(value: unknown): unknown => {
          let parsed: unknown = value
          if (typeof value === 'string') {
            try { parsed = JSON.parse(value) } catch { return value }
          }
          failure = bootstrapFailureProof(parsed)
          return parsed
        }]
      })
    } catch (error) {
      if (error instanceof HttpError && error.code === 400 && failure !== null) {
        throw new HttpError(error.message, error.code, {
          data: failure, url: error.url, method: error.method
        })
      }
      throw error
    }
  },
  serviceCatalogPreflight() {
    return request.get<ServiceCatalogPreflight>({
      url: `${setup}/service-catalog/preflight`,
      showErrorMessage: false
    })
  },
  registerServiceCatalog() {
    return request.post<ServiceCatalogRegistration>({
      url: `${setup}/service-catalog/register`,
      data: {},
      showErrorMessage: false
    })
  },
  skillServiceCatalogPreflight() {
    return request.get<ServiceCatalogPreflight>({
      url: `${setup}/service-catalog/skill/preflight`,
      showErrorMessage: false
    })
  },
  registerSkillServiceCatalog() {
    return request.post<ServiceCatalogRegistration>({
      url: `${setup}/service-catalog/skill/register`,
      data: {},
      showErrorMessage: false
    })
  },
  audioServiceCatalogPreflight() {
    return request.get<ServiceCatalogPreflight>({
      url: `${setup}/service-catalog/audio/preflight`,
      showErrorMessage: false
    })
  },
  registerAudioServiceCatalog() {
    return request.post<ServiceCatalogRegistration>({
      url: `${setup}/service-catalog/audio/register`,
      data: {},
      showErrorMessage: false
    })
  },
  audioRuntimeMetadata() {
    return request.get<AudioRuntimeMetadata>({
      url: `${setup}/audio-transcription/metadata`,
      showErrorMessage: false
    })
  },
  guides() {
    return request.get<SolutionGuideCatalog>({ url: `${setup}/onboarding/solution-guide/index` })
  },
  providerGuides() {
    return request.get<{ guides: ProviderGuide[] }>({ url: `${setup}/onboarding/provider-guides` })
  },
  status(environmentId: number) {
    return request.get<OnboardingStatus>({
      url: `${setup}/onboarding/status`,
      params: { environment_id: environmentId },
      showErrorMessage: false
    })
  },
  publishedDeployments(modelType: string, name?: string, capabilityCode?: string, environmentId?: number, selectionScope?: 'published_routes' | 'ocr_route_configuration') {
    return request.get<SandAiPage<PublishedDeployment>>({
      url: `${setup}/deployment/index`,
      params: { page: 1, limit: 100, model_type: modelType, name: name || undefined, capability_code: capabilityCode, environment_id: environmentId, ...(selectionScope !== undefined ? { selection_scope: selectionScope } : {}) },
      showErrorMessage: false
    })
  },
  discover(connection: NewDeployment['connection']) {
    return request.post<{ models: DiscoveredModel[]; state: 'discovered' }>({
      url: `${setup}/onboarding/discover-models`,
      data: { connection },
      showErrorMessage: false
    })
  },
  async save(payload: NewDeployment | SuccessorDeployment | ProviderModelDeployment | SpeechDeployment | TranscriptionDeployment | EmbeddingDeployment | LocalOcrDeployment | LocalOcrSuccessorDeployment, key: string): Promise<DraftSave> {
    let proof: UnsubmittedInputProof | null = null
    try {
      return await request.post<DraftSave>({
        url: `${setup}/deployment/save`,
        data: payload,
        headers: { 'Idempotency-Key': key },
        showErrorMessage: false,
        // Keep the native business/auth interceptor behavior. The supported per-request
        // transform only retains this finite safe metadata that the host otherwise drops.
        transformResponse: [(value: unknown): unknown => {
          let parsed: unknown = value
          if (typeof value === 'string') {
            try { parsed = JSON.parse(value) } catch { return value }
          }
          proof = unsubmittedInputProof(parsed)
          return parsed
        }]
      })
    } catch (error) {
      if (error instanceof HttpError && error.code === 400 && proof !== null) {
        throw new HttpError(error.message, error.code, {
          data: proof, url: error.url, method: error.method
        })
      }
      throw error
    }
  },
  readSavedDraftByKey(requestKey: string) {
    return request.get<DraftSave>({
      url: `${setup}/deployment/by-request-key`,
      params: { request_key: requestKey },
      showErrorMessage: false
    })
  },
  readDraft(deploymentId: number, revision?: number) {
    return request.get<DraftSave>({
      url: `${setup}/deployment/read`,
      params: { deployment_id: deploymentId, ...(revision !== undefined ? { revision } : {}) },
      showErrorMessage: false
    })
  },
  verify(deploymentId: number, revision: number, processingLocation: string, key: string, timeoutMs?: number) {
    return request.post<Verification>({
      url: `${setup}/deployment/verify`,
      ...(timeoutMs !== undefined ? { timeout: timeoutMs } : {}),
      data: { deployment_id: deploymentId, revision, processing_location: processingLocation },
      headers: { 'Idempotency-Key': key },
      showErrorMessage: false
    })
  },
  readVerification(deploymentId: number, revision: number, key?: string) {
    return request.get<Verification>({
      url: `${setup}/deployment/verification/read`,
      params: { deployment_id: deploymentId, revision },
      ...(key !== undefined ? { headers: { 'Idempotency-Key': key } } : {}),
      showErrorMessage: false
    })
  },
  publish(deploymentId: number, revision?: number) {
    return request.post<{ deployment_id: number; state: 'published' }>({
      url: `${setup}/onboarding/publish-deployment`,
      data: { deployment_id: deploymentId, ...(revision !== undefined ? { revision } : {}) },
      showErrorMessage: false
    })
  },
  bindDefaultChat(environmentId: number, deploymentId: number, expectedRevision?: number) {
    return request.post<{ profile: { id: number; environment_id: number; state: 'published' }; idempotent_replay: boolean }>({
      url: `${setup}/onboarding/bind-default-chat`,
      data: { environment_id: environmentId, deployment_id: deploymentId, ...(expectedRevision === undefined ? {} : { expected_revision: expectedRevision }) },
      showErrorMessage: false
    })
  }
}
