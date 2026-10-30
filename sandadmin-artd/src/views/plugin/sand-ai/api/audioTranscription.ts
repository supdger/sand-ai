import type { SandAiTask, SandAiTaskSubmitResult } from './types'
import { runtimeRequest } from './runtime'

export type AudioTranscriptionRequest = {
  schema: { code: 'sand_ai.audio_transcription_request'; version: '1.0.0' }
  file_ref: string
  language: string
  speaker_diarization: boolean
  glossary: string[]
  data_egress_policy: 'local_only' | 'approved_cloud'
}

export default {
  readByRequestKey(apiKey: string, idempotencyKey: string) {
    return runtimeRequest<SandAiTask>({
      method: 'GET',
      path: '/audio-transcriptions/by-request-key',
      apiKey,
      params: { request_key: idempotencyKey }
    })
  },
  submit(apiKey: string, request: AudioTranscriptionRequest, idempotencyKey: string) {
    return runtimeRequest<SandAiTaskSubmitResult>({
      method: 'POST',
      path: '/audio-transcriptions',
      apiKey,
      data: request,
      headers: { 'Idempotency-Key': idempotencyKey }
    })
  }
}
