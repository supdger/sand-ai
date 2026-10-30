import request from '@/utils/http'
import type { SandAiPage } from './types'

const base = '/app/sand-ai/admin/knowledge-bases/publications'

export interface PublishableKnowledgeBase {
  readonly id: number
  readonly code: string
  readonly name: string
  readonly environment_id: number
  readonly version_id: number
  readonly version_no: number
  readonly data_class: string | null
}

export interface KnowledgePublication {
  readonly id: number
  readonly knowledge_base_id: number
  readonly owner_environment_id: number
  readonly consumer_organization_id: number
  readonly consumer_application_id: number
  readonly consumer_environment_id: number
  readonly code: string
  readonly state: 'published' | 'revoked'
  readonly create_time: string
  readonly update_time: string
}

export interface ConsumerKnowledgePublication {
  readonly id: number
  readonly code: string
  readonly knowledge_base_id: number
  readonly owner_environment_id: number
  readonly consumer_environment_id: number
  readonly owner_code: string
  readonly owner_name: string
  readonly version_id: number
  readonly version_no: number
  readonly data_class: string | null
}

export interface KnowledgePublicationResult {
  readonly publication_id: number
  readonly state: 'published' | 'revoked'
  readonly idempotent_replay: boolean
}

export interface KnowledgePublicationDraft {
  readonly owner_environment_id: number
  readonly knowledge_base_id: number
  readonly consumer_environment_id: number
  readonly code: string
}

export interface KnowledgePublicationOperationReceipt {
  readonly operation: 'publish' | 'revoke'
  readonly request_id: string
  readonly actor_ref: string
  readonly owner_environment_id: number
  readonly publication_id: number
  readonly knowledge_base_id: number
  readonly consumer_environment_id: number
  readonly code: string
  readonly state: 'published' | 'revoked'
  readonly idempotent_replay: true
}

export interface KnowledgeTrialMatch {
  readonly file_id: number
  readonly source_block_id: number
  readonly locator_type: string
  readonly locator: Record<string, unknown>
  readonly excerpt: string
}

export interface KnowledgeTrialResult {
  readonly knowledge_base_id: number
  readonly knowledge_base_version_id: number
  readonly query: string
  readonly matches: KnowledgeTrialMatch[]
}

export default {
  trial(ownerEnvironmentId: number, versionId: number, query: string, limit: number) {
    return request.post<KnowledgeTrialResult>({
      url: '/app/sand-ai/admin/knowledge-bases/trial',
      data: { owner_environment_id: ownerEnvironmentId, knowledge_base_version_id: versionId, query, limit },
      showErrorMessage: false
    })
  },
  publishable(ownerEnvironmentId: number, page = 1, limit = 100) {
    return request.get<SandAiPage<PublishableKnowledgeBase>>({
      url: `${base}/publishable`,
      params: { owner_environment_id: ownerEnvironmentId, page, limit },
      showErrorMessage: false
    })
  },
  consumer(consumerEnvironmentId: number, page = 1, limit = 100) {
    return request.get<SandAiPage<ConsumerKnowledgePublication>>({
      url: `${base}/consumer`,
      params: { consumer_environment_id: consumerEnvironmentId, page, limit },
      showErrorMessage: false
    })
  },
  list(ownerEnvironmentId: number, knowledgeBaseId: number, page = 1, limit = 20) {
    return request.get<SandAiPage<KnowledgePublication>>({
      url: `${base}/index`,
      params: { owner_environment_id: ownerEnvironmentId, knowledge_base_id: knowledgeBaseId, page, limit },
      showErrorMessage: false
    })
  },
  publish(data: KnowledgePublicationDraft, key: string) {
    return request.post<KnowledgePublicationResult>({
      url: `${base}/publish`,
      data,
      headers: { 'Idempotency-Key': key },
      showErrorMessage: false
    })
  },
  readPublishOperation(target: KnowledgePublicationDraft, requestId: string) {
    return request.get<KnowledgePublicationOperationReceipt>({
      url: `${base}/publish/by-request-key`,
      params: { ...target, request_id: requestId },
      showErrorMessage: false
    })
  },
  readRevokeOperation(ownerEnvironmentId: number, publicationId: number, requestId: string) {
    return request.get<KnowledgePublicationOperationReceipt>({
      url: `${base}/revoke/by-request-key`,
      params: { owner_environment_id: ownerEnvironmentId, publication_id: publicationId, request_id: requestId },
      showErrorMessage: false
    })
  },
  revoke(ownerEnvironmentId: number, publicationId: number, key: string) {
    return request.post<KnowledgePublicationResult>({
      url: `${base}/revoke`,
      data: { owner_environment_id: ownerEnvironmentId, publication_id: publicationId },
      headers: { 'Idempotency-Key': key },
      showErrorMessage: false
    })
  }
}
