import { runtimeBlobRequest, runtimeRequest } from './runtime'
import type { SandAiAudioSource } from './types'

export type AudioReviewSegment = {
  segment_index: number
  start_ms: number
  end_ms: number
  speaker: string | null
  original_speaker: string | null
  confidence: string | null
  warnings: string[]
  original_text: string
  text: string
  review_revision: number | null
  source?: SandAiAudioSource
}

export type AudioReviewSnapshot = {
  revision: number
  segments: AudioReviewSegment[]
}

export type AudioReviewReceipt = {
  audio_task_id: number
  expected_revision: number
  segment_index: number
  revision: number
  request_key: string
}

export default {
  async source(apiKey: string, taskId: number): Promise<Blob> {
    const audio = await runtimeBlobRequest(apiKey, `/audio-transcriptions/${taskId}/source`)
    const contentType = audio.type.split(';')[0]
    if (
      !['audio/mpeg', 'audio/mp4', 'audio/ogg', 'audio/wav', 'audio/webm', 'audio/flac'].includes(
        contentType
      )
    ) {
      throw new Error('音频响应类型无效')
    }
    return audio
  },

  read(apiKey: string, taskId: number) {
    return runtimeRequest<AudioReviewSnapshot>({
      method: 'GET',
      path: `/audio-transcriptions/${taskId}/reviews`,
      apiKey
    })
  },

  readByRequestKey(apiKey: string, taskId: number, key: string) {
    return runtimeRequest<AudioReviewReceipt>({
      method: 'GET',
      path: `/audio-transcriptions/${taskId}/reviews/by-request-key`,
      apiKey,
      params: { request_key: key }
    })
  },

  save(
    apiKey: string,
    taskId: number,
    expectedRevision: number,
    segmentIndex: number,
    text: string,
    idempotencyKey: string,
    speaker?: string | null
  ) {
    return runtimeRequest<{
      revision: number
      segment_index: number
      text: string
      idempotent_replay: boolean
    }>({
      method: 'POST',
      path: `/audio-transcriptions/${taskId}/reviews`,
      apiKey,
      data: {
        expected_revision: expectedRevision,
        segment_index: segmentIndex,
        text,
        ...(speaker === undefined ? {} : { speaker })
      },
      headers: { 'Idempotency-Key': idempotencyKey }
    })
  }
}
