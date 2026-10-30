export interface MaterialSampleIntent {
  admin_id: number
  environment_id: number
  profile_code: string
  profile_version: string
  profile_revision: number
  profile_state: 'draft' | 'published'
  key: string
  run_id?: number
}

export interface MaterialPublicationIntent {
  admin_id: number
  environment_id: number
  code: string
  version: string
  revision: number
  sample_run_id: number
}

interface SampleJournal {
  version: 1
  active_key: string | null
  intents: MaterialSampleIntent[]
}

function record(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value)
}
function positive(value: unknown): value is number {
  return typeof value === 'number' && Number.isSafeInteger(value) && value > 0
}
function code(value: unknown): value is string {
  return typeof value === 'string' && /^[a-z][a-z0-9_]{0,63}$/.test(value)
}
function version(value: unknown): value is string {
  return typeof value === 'string' && value.length <= 32 && /^\d+\.\d+\.\d+$/.test(value)
}
function key(value: unknown): value is string {
  return typeof value === 'string' && /^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/.test(value)
}
function namespace(kind: 'sample' | 'publication' | 'save', adminId: number, environmentId: number): string {
  if (!positive(adminId) || !positive(environmentId)) throw new Error('当前管理员或环境无法确认')
  return `sand-ai:material-setup:${kind}:${adminId}:${environmentId}`
}
function sample(value: unknown, adminId: number, environmentId: number): value is MaterialSampleIntent {
  return record(value) && value.admin_id === adminId && value.environment_id === environmentId
    && code(value.profile_code) && version(value.profile_version) && positive(value.profile_revision)
    && (value.profile_state === 'draft' || value.profile_state === 'published') && key(value.key)
    && (value.run_id === undefined || positive(value.run_id))
    && Object.keys(value).every(field => ['admin_id', 'environment_id', 'profile_code', 'profile_version',
      'profile_revision', 'profile_state', 'key', 'run_id'].includes(field))
}
function write(storageKey: string, value: unknown): void {
  const raw = JSON.stringify(value)
  sessionStorage.setItem(storageKey, raw)
  if (sessionStorage.getItem(storageKey) !== raw) throw new Error('原请求记录未能持久保存')
}

/** Keep only the target and digest, never the editable labels or credentials. */
export async function materialDraftFingerprint(name: string, profile: MaterialProfileConfig): Promise<string> {
  const canonical = JSON.stringify({
    name: name.trim(),
    profile: {
      code: profile.code, version: profile.version, output_language: profile.output_language,
      document_classifications: profile.document_classifications,
      entity_types: profile.entity_types, extension_fields: profile.extension_fields,
      max_prompt_characters: profile.max_prompt_characters,
      max_output_characters: profile.max_output_characters,
      ...(profile.review_output_strategy === undefined ? {} : { review_output_strategy: profile.review_output_strategy })
    }
  })
  const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(canonical))
  return Array.from(new Uint8Array(digest), value => value.toString(16).padStart(2, '0')).join('')
}

export function readMaterialDraftSaveIntent(adminId: number, environmentId: number): MaterialDraftSaveIntent | null {
  const raw = sessionStorage.getItem(namespace('save', adminId, environmentId))
  if (raw === null) return null
  const value: unknown = JSON.parse(raw)
  if (!record(value) || value.version !== 1 || value.admin_id !== adminId || value.environment_id !== environmentId
    || !code(value.code) || !version(value.profile_version)
    || !(value.expected_revision === null || (positive(value.expected_revision) && value.expected_revision < Number.MAX_SAFE_INTEGER))
    || typeof value.fingerprint_sha256 !== 'string' || !/^[a-f0-9]{64}$/.test(value.fingerprint_sha256)
    || Object.keys(value).some(field => !['version', 'admin_id', 'environment_id', 'code',
      'profile_version', 'expected_revision', 'fingerprint_sha256'].includes(field))) {
    throw new Error('原草稿保存目标无法确认')
  }
  return { version: 1, admin_id: adminId, environment_id: environmentId, code: value.code,
    profile_version: value.profile_version, expected_revision: value.expected_revision, fingerprint_sha256: value.fingerprint_sha256 }
}

export function retainMaterialDraftSaveIntent(intent: MaterialDraftSaveIntent): void {
  if (readMaterialDraftSaveIntent(intent.admin_id, intent.environment_id) !== null) throw new Error('原草稿保存仍待确认')
  write(namespace('save', intent.admin_id, intent.environment_id), intent)
  if (JSON.stringify(readMaterialDraftSaveIntent(intent.admin_id, intent.environment_id)) !== JSON.stringify(intent)) {
    throw new Error('原草稿保存目标未能持久保存')
  }
}

export function resolveMaterialDraftSaveIntent(intent: MaterialDraftSaveIntent): void {
  if (JSON.stringify(readMaterialDraftSaveIntent(intent.admin_id, intent.environment_id)) !== JSON.stringify(intent)) {
    throw new Error('原草稿保存目标已变化')
  }
  const storageKey = namespace('save', intent.admin_id, intent.environment_id)
  sessionStorage.removeItem(storageKey)
  if (sessionStorage.getItem(storageKey) !== null) throw new Error('原草稿保存标记未能解除')
}

/** Only opaque original targets and keys are stored. A journal never proves runtime ownership. */
export function readMaterialSampleJournal(adminId: number, environmentId: number): SampleJournal {
  const storageKey = namespace('sample', adminId, environmentId)
  const raw = sessionStorage.getItem(storageKey)
  if (raw !== null) {
    const value: unknown = JSON.parse(raw)
    if (!record(value) || value.version !== 1 || !Array.isArray(value.intents)
      || !value.intents.every(item => sample(item, adminId, environmentId))
      || (value.active_key !== null && !key(value.active_key))
      || (value.active_key !== null && !value.intents.some(item => item.key === value.active_key))) {
      throw new Error('原样本请求记录无法确认')
    }
    return { version: 1, active_key: value.active_key, intents: value.intents }
  }
  // Preserve old opaque intents across credential renewal. The official GET
  // still verifies the current SandIAM runtime owner before returning any result.
  const prefix = `sand-ai-material-sample:${adminId}:${environmentId}:`
  const intents: MaterialSampleIntent[] = []
  for (let index = 0; index < sessionStorage.length; index++) {
    const legacyKey = sessionStorage.key(index)
    if (!legacyKey?.startsWith(prefix)) continue
    const fields = legacyKey.slice(prefix.length).split(':')
    const requestKey = sessionStorage.getItem(legacyKey)
    const [profileCode, profileVersion, revision, state, digest] = fields
    if (fields.length !== 5 || !code(profileCode) || !version(profileVersion)
      || !/^[1-9][0-9]*$/.test(revision) || !positive(Number(revision))
      || (state !== 'draft' && state !== 'published') || !/^[a-f0-9]{64}$/.test(digest)
      || !key(requestKey)) throw new Error('旧原样本请求缺少可确认的目标')
    if (!intents.some(item => item.key === requestKey)) intents.push({
      admin_id: adminId, environment_id: environmentId,
      profile_code: profileCode, profile_version: profileVersion,
      profile_revision: Number(revision), profile_state: state, key: requestKey
    })
  }
  const journal: SampleJournal = { version: 1, active_key: intents[0]?.key ?? null, intents }
  if (intents.length) write(storageKey, journal)
  return journal
}

export function retainMaterialSampleIntent(intent: MaterialSampleIntent): void {
  if (!sample(intent, intent.admin_id, intent.environment_id)) throw new Error('原样本目标无效')
  const journal = readMaterialSampleJournal(intent.admin_id, intent.environment_id)
  const old = journal.intents.find(item => item.key === intent.key)
  if (old && (old.profile_code !== intent.profile_code || old.profile_version !== intent.profile_version
    || old.profile_revision !== intent.profile_revision || old.profile_state !== intent.profile_state
    || (old.run_id !== undefined && old.run_id !== intent.run_id))) throw new Error('原样本请求目标已变化')
  write(namespace('sample', intent.admin_id, intent.environment_id), {
    version: 1, active_key: intent.key,
    intents: [...journal.intents.filter(item => item.key !== intent.key), intent]
  })
}

export function resolveMaterialSampleIntent(intent: MaterialSampleIntent): void {
  const journal = readMaterialSampleJournal(intent.admin_id, intent.environment_id)
  const old = journal.intents.find(item => item.key === intent.key)
  if (!old?.run_id || old.run_id !== intent.run_id) throw new Error('原样本尚无匹配的正式运行')
  const intents = journal.intents.filter(item => item.key !== intent.key)
  write(namespace('sample', intent.admin_id, intent.environment_id), {
    version: 1, active_key: intents[0]?.key ?? null, intents
  })
  const prefix = `sand-ai-material-sample:${intent.admin_id}:${intent.environment_id}:`
  const legacyKeys: string[] = []
  for (let index = 0; index < sessionStorage.length; index++) {
    const storageKey = sessionStorage.key(index)
    if (storageKey?.startsWith(prefix) && sessionStorage.getItem(storageKey) === intent.key) legacyKeys.push(storageKey)
  }
  for (const storageKey of legacyKeys) sessionStorage.removeItem(storageKey)
}

export function readMaterialPublicationIntent(adminId: number, environmentId: number): MaterialPublicationIntent | null {
  const raw = sessionStorage.getItem(namespace('publication', adminId, environmentId))
  if (raw === null) return null
  const value: unknown = JSON.parse(raw)
  if (!record(value) || value.admin_id !== adminId || value.environment_id !== environmentId
    || !code(value.code) || !version(value.version) || !positive(value.revision) || !positive(value.sample_run_id)
    || Object.keys(value).some(field => !['admin_id', 'environment_id', 'code', 'version', 'revision', 'sample_run_id'].includes(field))) {
    throw new Error('原发布目标无法确认')
  }
  return {
    admin_id: adminId, environment_id: environmentId, code: value.code,
    version: value.version, revision: value.revision, sample_run_id: value.sample_run_id
  }
}
export function retainMaterialPublicationIntent(intent: MaterialPublicationIntent): void {
  const old = readMaterialPublicationIntent(intent.admin_id, intent.environment_id)
  if (old && JSON.stringify(old) !== JSON.stringify(intent)) throw new Error('原发布目标仍待确认')
  write(namespace('publication', intent.admin_id, intent.environment_id), intent)
}
export function resolveMaterialPublicationIntent(intent: MaterialPublicationIntent): void {
  const old = readMaterialPublicationIntent(intent.admin_id, intent.environment_id)
  if (!old || JSON.stringify(old) !== JSON.stringify(intent)) throw new Error('原发布目标已变化')
  const storageKey = namespace('publication', intent.admin_id, intent.environment_id)
  sessionStorage.removeItem(storageKey)
  if (sessionStorage.getItem(storageKey) !== null) throw new Error('原发布标记未能解除')
}
import type { MaterialProfileConfig } from './materialProfile'

export interface MaterialDraftSaveIntent {
  version: 1
  admin_id: number
  environment_id: number
  code: string
  profile_version: string
  expected_revision: number | null
  fingerprint_sha256: string
}
