import { RUNTIME_API_PREFIX } from './config'
import { runtimeRequest } from './runtime'

export type SpeechState = 'prepared' | 'dispatching' | 'awaiting_usage' | 'succeeded' | 'failed' | 'ambiguous'

export interface SpeechRun {
  public_id: string
  state: SpeechState
  input_characters: number
  voice: string
  audio_bytes: number | null
  error_code: string | null
  create_time: string
  complete_time: string | null
  cost: {
    pricing_state: 'priced' | 'unpriced'
    price_revision_id: number | null
    metered_amount: string | null
    currency: string | null
  } | null
}

export type SpeechAudio = { blob: Blob; format: 'mp3' | 'wav'; mime: 'audio/mpeg' | 'audio/wav'; filename: string }

function environmentHeaders(environmentId?: number): Record<string, string> {
  return environmentId === undefined ? {} : { 'X-Sand-AI-Environment-ID': String(environmentId) }
}

export default {
  submit(apiKey: string, text: string, voice: string, idempotencyKey: string, environmentId?: number) {
    return runtimeRequest<SpeechRun>({
      method: 'POST', path: '/speech-syntheses', apiKey,
      data: { text, voice }, headers: { ...environmentHeaders(environmentId), 'Idempotency-Key': idempotencyKey }
    })
  },
  read(apiKey: string, publicId: string, environmentId?: number) {
    return runtimeRequest<SpeechRun>({
      method: 'GET', path: `/speech-syntheses/${encodeURIComponent(publicId)}`, apiKey, headers: environmentHeaders(environmentId)
    })
  },
  readByRequestKey(apiKey: string, idempotencyKey: string, environmentId?: number) {
    return runtimeRequest<SpeechRun>({
      method: 'GET', path: '/speech-syntheses/by-request-key', apiKey,
      headers: { ...environmentHeaders(environmentId), 'Idempotency-Key': idempotencyKey }
    })
  },
  async audio(apiKey: string, publicId: string, environmentId?: number): Promise<SpeechAudio> {
    const response = await fetch(`${import.meta.env.VITE_API_URL || ''}${RUNTIME_API_PREFIX}/speech-syntheses/${encodeURIComponent(publicId)}/audio`, {
      headers: { Authorization: `Bearer ${apiKey.trim()}`, Accept: 'audio/mpeg, audio/wav', ...environmentHeaders(environmentId) },
      cache: 'no-store', credentials: 'omit'
    })
    if (!response.ok) throw new Error(`语音文件读取失败（HTTP ${response.status}）`)
    const mime = response.headers.get('content-type')?.split(';', 1)[0].trim().toLowerCase()
    if (mime !== 'audio/mpeg' && mime !== 'audio/wav') throw new Error('语音文件格式无效，仅支持 MP3 或 WAV')
    const format = mime === 'audio/wav' ? 'wav' : 'mp3'
    const filename = `speech.${format}`
    const disposition = response.headers.get('content-disposition')
    // The private endpoint uses a controlled name; never trust an arbitrary download path.
    if (disposition !== null && disposition !== `attachment; filename="${filename}"`) {
      throw new Error('语音文件名与实际格式不一致')
    }
    const blob = await response.blob()
    if (blob.size < (format === 'wav' ? 44 : 32) || blob.size > 8388608) throw new Error('语音文件大小无效')
    return { blob, format, mime, filename }
  }
}
