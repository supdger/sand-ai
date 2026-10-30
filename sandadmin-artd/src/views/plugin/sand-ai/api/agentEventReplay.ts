import { RUNTIME_API_PREFIX } from './config'

export type AgentPreviewToken = {
  task_id: number; event_no: number; round: number; text: string
  provisional: true; source_block_ids: number[]
}
export type AgentReplayEvent =
  | { kind: 'token'; token: AgentPreviewToken }
  | { kind: 'task'; taskId: number; state: string }
  | { kind: 'reconnect' }

export class AgentReplayError extends Error {
  constructor(readonly code: string, readonly status = 0) {
    super('智能体实时状态读取失败')
  }
}
// Exact read-denial codes from HostSandIamErrorMapper and the current task/source guards.
const readDenialCodes = new Set([
  'SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE', 'SAND_AI_IDENTITY_CONTEXT_EXPIRED',
  'SAND_AI_CREDENTIAL_REVOKED', 'SAND_AI_AUTHENTICATION_FAILED',
  'SAND_AI_SERVICE_ACTION_FORBIDDEN', 'SAND_AI_INVOCATION_SCOPE_FORBIDDEN',
  'SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'SAND_AI_SERVICE_NETWORK_FORBIDDEN',
  'SAND_AI_DATA_CLASS_FORBIDDEN', 'SAND_AI_SERVICE_QUOTA_EXCEEDED',
  'SAND_AI_SERVICE_GRANT_CONSTRAINT_INVALID', 'SAND_AI_RESOURCE_NOT_FOUND',
  'SAND_AI_ENVIRONMENT_MISMATCH', 'SAND_AI_SCOPE_SNAPSHOT_INVALID',
  'SAND_AI_ARTIFACT_PROVENANCE_INVALID'
])
export function isAgentReadDenied(code: string | null, status: number): boolean {
  return [401, 403, 404].includes(status) || (code !== null && readDenialCodes.has(code))
}
function record(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}
function positive(value: unknown): value is number {
  return typeof value === 'number' && Number.isSafeInteger(value) && value > 0
}
function decodeEvent(frame: string): AgentReplayEvent | null {
  if (new TextEncoder().encode(frame).length > 32768) throw new AgentReplayError('SAND_AI_AGENT_PROTOCOL_INVALID')
  let kind = 'message'
  let eventId = ''
  const lines: string[] = []
  for (const line of frame.split(/\r?\n/)) {
    if (line.startsWith('event:')) kind = line.slice(6).trim()
    else if (line.startsWith('id:')) eventId = line.slice(3).trim()
    else if (line.startsWith('data:')) lines.push(line.slice(5).replace(/^ /, ''))
  }
  if (lines.length === 0) return null
  let body: unknown
  try { body = JSON.parse(lines.join('\n')) } catch { throw new AgentReplayError('SAND_AI_AGENT_PROTOCOL_INVALID') }
  if (!record(body)) throw new AgentReplayError('SAND_AI_AGENT_PROTOCOL_INVALID')
  if (kind === 'error') {
    throw new AgentReplayError(typeof body.error_code === 'string' ? body.error_code : 'SAND_AI_AGENT_STREAM_UNAVAILABLE')
  }
  if (kind === 'reconnect') return { kind }
  if (kind === 'task' && record(body.task) && positive(body.task.id) && typeof body.task.state === 'string') {
    return { kind, taskId: body.task.id, state: body.task.state }
  }
  if (kind === 'token' && positive(body.task_id) && positive(body.event_no) && body.event_no <= 4096
    && eventId === String(body.event_no) && positive(body.round) && body.round <= 4
    && typeof body.text === 'string' && new TextEncoder().encode(body.text).length <= 8192
    && body.provisional === true && Array.isArray(body.source_block_ids) && body.source_block_ids.length <= 128 && body.source_block_ids.every(positive)) {
    return { kind, token: {
      task_id: body.task_id, event_no: body.event_no, round: body.round, text: body.text,
      provisional: true, source_block_ids: body.source_block_ids
    } }
  }
  throw new AgentReplayError('SAND_AI_AGENT_PROTOCOL_INVALID')
}

/** Reads only the official persisted, server-masked event projection. Never dispatches inference. */
export async function readAgentEvents(apiKey: string, taskId: number, code: string | null, cursor: number,
  signal: AbortSignal, onEvent: (event: AgentReplayEvent) => void): Promise<void> {
  const path = code === null ? `/agents/tasks/${taskId}/events`
    : `/agents/${encodeURIComponent(code)}/tasks/${taskId}/events`
  const response = await fetch(`${import.meta.env.VITE_API_URL || ''}${RUNTIME_API_PREFIX}${path}?after_event_id=${cursor}`, {
    method: 'GET', headers: { Authorization: `Bearer ${apiKey.trim()}`, Accept: 'text/event-stream' },
    cache: 'no-store', credentials: 'omit', signal
  })
  if (!response.ok || !response.headers.get('content-type')?.includes('text/event-stream')) {
    let code = 'SAND_AI_AGENT_STREAM_UNAVAILABLE'
    try {
      const body: unknown = await response.json()
      if (record(body) && record(body.data) && typeof body.data.error_code === 'string') code = body.data.error_code
    } catch { /* No provider or internal exception text is shown. */ }
    throw new AgentReplayError(code, response.status)
  }
  if (!response.body) throw new AgentReplayError('SAND_AI_AGENT_STREAM_UNAVAILABLE')
  const reader = response.body.getReader()
  const decoder = new TextDecoder('utf-8', { fatal: true })
  let pending = ''
  try {
    while (!signal.aborted) {
      const next = await reader.read()
      if (next.done) break
      pending += decoder.decode(next.value, { stream: true })
      let boundary = /\r?\n\r?\n/.exec(pending)
      while (boundary) {
        const frame = pending.slice(0, boundary.index)
        pending = pending.slice(boundary.index + boundary[0].length)
        const event = decodeEvent(frame)
        if (event) onEvent(event)
        boundary = /\r?\n\r?\n/.exec(pending)
      }
      if (new TextEncoder().encode(pending).length > 32768) throw new AgentReplayError('SAND_AI_AGENT_PROTOCOL_INVALID')
    }
  } finally {
    await reader.cancel().catch(() => undefined)
    reader.releaseLock()
  }
}
