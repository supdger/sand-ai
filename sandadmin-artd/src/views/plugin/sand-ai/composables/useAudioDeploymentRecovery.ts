import { computed, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue'
import { useUserStore } from '@/store/modules/user'
import { HttpError } from '@/utils/http/error'
import api, { type DraftSave, type SpeechDeployment, type TranscriptionDeployment, type Verification } from '../api/onboarding'

type Kind = 'audio_speech' | 'audio_transcription'
type Mode = 'local' | 'external'
type Phase = 'save_pending' | 'saved' | 'verification_pending' | 'verification_resolved' | 'publish_pending' | 'published' | 'failed'
interface Journal {
  admin_id: number
  kind: Kind
  mode: Mode
  phase: Phase
  save_key: string
  deployment_id: number | null
  revision: number | null
  verification_key: string | null
}
const phases: Phase[] = ['save_pending', 'saved', 'verification_pending', 'verification_resolved', 'publish_pending', 'published', 'failed']
const states = ['draft', 'verifying', 'tested', 'verification_ambiguous', 'published']
const positive = (value: unknown): value is number => typeof value === 'number' && Number.isSafeInteger(value) && value > 0
const key = (value: unknown): value is string => typeof value === 'string' && /^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i.test(value)
const object = (value: unknown): value is Record<string, unknown> => typeof value === 'object' && value !== null && !Array.isArray(value)

/** A metadata-only recovery lock. No connection, form payload, output or raw response is stored. */
export function useAudioDeploymentRecovery(kind: Kind, clearSensitive: () => void) {
  const user = useUserStore()
  const mode = ref<Mode>('external')
  const journal = shallowRef<Journal | null>(null)
  const draft = shallowRef<DraftSave | null>(null)
  const verification = shallowRef<Verification | null>(null)
  const processingLocation = ref('')
  const storageError = ref(false)
  const busy = ref<'' | 'save' | 'read' | 'verify' | 'publish'>('')
  // This lock survives identity changes until the actual POST settles.
  const postFlight = ref(false)
  const pendingNotSubmitted = ref(false)
  let retryOriginal: (() => Promise<void>) | null = null
  const message = ref('')
  const messageType = ref<'success' | 'warning' | 'error'>('warning')
  let epoch = 0
  const recoveryRequired = computed(() => storageError.value || Boolean(journal.value && (!draft.value
    || ['save_pending', 'verification_pending', 'publish_pending'].includes(journal.value.phase))))
  const locked = computed(() => Boolean(busy.value || postFlight.value || journal.value || storageError.value))
  const phaseLabel = computed(() => journal.value ? ({ save_pending: '保存结果待确认', saved: '草稿已保存', verification_pending: '原验证待确认', verification_resolved: '原验证已确认', publish_pending: '原发布待确认', published: '已发布', failed: '原验证已明确失败' })[journal.value.phase] : '')
  const published = computed(() => journal.value?.phase === 'published' && draft.value?.state === 'published'
    && verification.value?.state === 'published')
  const canVerify = computed(() => !busy.value && !postFlight.value && !storageError.value
    && journal.value?.phase === 'saved' && !journal.value.verification_key && draft.value?.state === 'draft'
    && verification.value?.state === 'draft' && !verification.value.failure && Boolean(processingLocation.value))
  const canPublish = computed(() => !busy.value && !postFlight.value && !storageError.value
    && journal.value?.phase === 'verification_resolved' && draft.value?.state === 'tested'
    && verification.value?.state === 'tested' && evidenceMatches(verification.value, journal.value))
  const canStartNew = computed(() => !busy.value && !postFlight.value && !storageError.value
    && Boolean(draft.value && verification.value && journal.value && ['published', 'failed'].includes(journal.value.phase)))
  const usageNotice = computed(() => {
    const usage: unknown = verification.value ? Reflect.get(verification.value, 'platform_usage') : null
    if (object(usage) && usage.scope_kind === 'platform_admin' && positive(usage.attempt_id)) {
      if (usage.state === 'not_dispatched') return '原平台验证记录确认未派发。新草稿仍需明确保存和验证。'
      if (usage.state === 'recorded') return '本次验证已记录为平台管理员调用；用量、计价及账单金额按服务端记录核对，未确定金额不视为零费用。'
      return '原平台验证记录仍待核对，可能产生费用；保留原号，不重复验证。'
    }
    return '验证可能产生费用。计量与计价以服务端平台验证记录为准。'
  })
  function notice(text: string, type: 'success' | 'warning' | 'error' = 'warning'): void {
    message.value = text; messageType.value = type
  }
  function admin(): number {
    const value = Number(user.info.id)
    if (!positive(value)) throw new Error('Administrator unavailable')
    return value
  }
  function namespace(id: number): string { return `sand-ai:audio-deployment-recovery:v1:${id}:${kind}` }
  function stored(id: number): Journal | null {
    const raw = sessionStorage.getItem(namespace(id))
    if (!raw) return null
    const value: unknown = JSON.parse(raw)
    if (!object(value) || Object.keys(value).some(field => !['admin_id', 'kind', 'mode', 'phase', 'save_key', 'deployment_id', 'revision', 'verification_key'].includes(field))
      || value.admin_id !== id || value.kind !== kind || !['local', 'external'].includes(String(value.mode))
      || (kind === 'audio_speech' && value.mode !== 'external') || !phases.includes(value.phase as Phase) || !key(value.save_key)
      || !(value.verification_key === null || key(value.verification_key))) throw new Error('Original metadata unavailable')
    if (value.phase === 'save_pending') {
      if (value.deployment_id !== null || value.revision !== null || value.verification_key !== null) throw new Error('Original save differs')
    } else if (!positive(value.deployment_id) || !positive(value.revision)
      || (value.phase !== 'saved' && !key(value.verification_key))
      || (value.phase === 'saved' && value.verification_key !== null)) throw new Error('Original target unavailable')
    return { admin_id: id, kind, mode: value.mode as Mode, phase: value.phase as Phase, save_key: value.save_key,
      deployment_id: value.deployment_id as number | null, revision: value.revision as number | null,
      verification_key: value.verification_key as string | null }
  }
  function same(a: Journal | null, b: Journal): boolean {
    return Boolean(a && a.admin_id === b.admin_id && a.kind === b.kind && a.mode === b.mode && a.phase === b.phase
      && a.save_key === b.save_key && a.deployment_id === b.deployment_id && a.revision === b.revision && a.verification_key === b.verification_key)
  }
  function persist(next: Journal, previous: Journal | null): void {
    if (admin() !== next.admin_id || (previous ? !same(stored(next.admin_id), previous) : stored(next.admin_id) !== null)) throw new Error('Original journal changed')
    // Explicit projection keeps secrets and future DTO additions out of browser storage.
    sessionStorage.setItem(namespace(next.admin_id), JSON.stringify({ admin_id: next.admin_id, kind: next.kind, mode: next.mode,
      phase: next.phase, save_key: next.save_key, deployment_id: next.deployment_id, revision: next.revision, verification_key: next.verification_key }))
    if (!same(stored(next.admin_id), next)) throw new Error('Original journal not retained')
    journal.value = next; storageError.value = false
  }
  function remove(original: Journal): void {
    if (admin() !== original.admin_id || !same(stored(original.admin_id), original)) throw new Error('Original journal changed')
    sessionStorage.removeItem(namespace(original.admin_id))
    if (stored(original.admin_id)) throw new Error('Original journal retained')
    journal.value = null
  }
  function current(original: Journal, generation: number): boolean {
    return generation === epoch && Number(user.info.id) === original.admin_id && same(journal.value, original)
  }
  function prepareSubmission(next: Journal, previous: Journal | null, generation: number, retry: () => Promise<void>): boolean {
    if (generation !== epoch || busy.value || postFlight.value || Number(user.info.id) !== next.admin_id) return false
    try {
      // A prior write may have reached storage before its readback failed.
      if (same(stored(next.admin_id), next)) {
        journal.value = next; storageError.value = false
      } else persist(next, previous)
    } catch {
      journal.value = next
      storageError.value = true
      pendingNotSubmitted.value = true
      retryOriginal = retry
      notice('本页确认尚未发送，原请求号与原配置保留在内存中。恢复会话存储后，明确继续原操作；刷新后只能只读找回。')
      return false
    }
    pendingNotSubmitted.value = false
    retryOriginal = null
    return true
  }
  async function retryUnsubmitted(): Promise<void> {
    const original = journal.value, retry = retryOriginal
    if (!original || !retry || !pendingNotSubmitted.value || busy.value || postFlight.value
      || Number(user.info.id) !== original.admin_id) return
    await retry()
  }
  function evidenceMatches(result: Verification, original: Journal): boolean {
    const evidence: unknown = result.validation_evidence
    return object(evidence) && evidence.deployment_id === original.deployment_id && evidence.deployment_revision === original.revision
      && evidence.validated_by === String(original.admin_id) && evidence.outcome === 'passed'
      && typeof evidence.evidence_sha256 === 'string' && /^[a-f0-9]{64}$/.test(evidence.evidence_sha256)
  }
  function assertDraft(value: DraftSave, original: Journal): void {
    if (!positive(value.deployment_id) || !positive(value.revision) || !states.includes(value.state)
      || (original.deployment_id !== null && (value.deployment_id !== original.deployment_id || value.revision !== original.revision))
      || value.model.type !== kind || !value.model.capabilities.includes(kind)) throw new Error('Original deployment differs')
    const adapter: unknown = Reflect.get(value.provider, 'adapter')
    if (adapter !== (original.mode === 'local' ? 'local_runtime' : 'openai_compatible')) throw new Error('Original processing mode differs')
    if (kind === 'audio_transcription') {
      if (!(original.mode === 'local' ? value.deployment.remote_model === 'local_whisper_cpp'
        : ['whisper-1', 'gpt-4o-transcribe-diarize', 'glm-asr-2512'].includes(value.deployment.remote_model))
        || Object.keys(value.deployment.config).length !== 0) throw new Error('Original transcription configuration differs')
    } else {
      const format: unknown = Reflect.get(value.deployment.config, 'speech_response_format')
      if (!(format === undefined || format === 'mp3' || format === 'wav')
        || typeof value.deployment.config.verification_voice !== 'string') throw new Error('Original speech configuration differs')
    }
  }
  function definiteFailure(result: Verification): boolean {
    const usage: unknown = Reflect.get(result, 'platform_usage')
    if (result.state === 'draft' && object(usage) && usage.scope_kind === 'platform_admin'
      && positive(usage.attempt_id) && usage.state === 'not_dispatched') return true
    return result.state === 'draft' && result.failure?.outcome_uncertain === false
      && (result.failure.reason === 'local_runtime_verification_failed'
        ? result.failure.recovery_action === 'configure_local_runtime_and_save_draft'
        : result.failure.error_code === 'SAND_AI_PROVIDER_REJECTED' && result.failure.recovery_action === 'reconfigure_connection')
  }
  function unsubmitted(error: unknown): boolean {
    if (!(error instanceof HttpError) || error.code !== 400 || !object(error.data)) return false
    return error.data.submission_state === 'not_submitted' && error.data.retryable === false
      && ['SAND_AI_VALIDATION_ERROR', 'SAND_AI_ONBOARDING_CONNECTION_INVALID', 'SAND_AI_ONBOARDING_HEADER_INVALID', 'SAND_AI_ONBOARDING_MODEL_INVALID'].includes(String(error.data.error_code))
  }
  function denied(error: unknown): void {
    if (error instanceof HttpError && [401, 403].includes(error.code)) {
      clearSensitive(); draft.value = null; verification.value = null; processingLocation.value = ''
    }
  }
  function load(): void {
    try {
      journal.value = stored(admin()); storageError.value = false
      if (journal.value) {
        mode.value = journal.value.mode
        notice('已保留原部署操作。先只读找回原配置与状态，再决定下一步；不会自动发送验证或发布。')
      }
    } catch { storageError.value = true; notice('无法读取原操作记录，本次不会发送。请恢复当前会话的存储或登录后再找回。') }
  }
  async function exact(original: Journal, generation: number): Promise<void> {
    if (!positive(original.deployment_id) || !positive(original.revision)) throw new Error('Original target unavailable')
    const [detail, result] = await Promise.all([api.readDraft(original.deployment_id, original.revision),
      api.readVerification(original.deployment_id, original.revision, original.verification_key ?? undefined)])
    if (!current(original, generation)) return
    assertDraft(detail, original)
    if (result.deployment_id !== original.deployment_id || result.revision !== original.revision || !states.includes(result.state)
      || (original.verification_key && Reflect.get(result, 'idempotent_replay') !== true)) throw new Error('Original verification differs')
    let phase = original.phase
    let confirmed = result
    if (original.verification_key) {
      if (result.state === 'tested' && evidenceMatches(result, original)) {
        if (detail.state === 'published') { phase = 'published'; confirmed = { ...result, state: 'published' } }
        else if (detail.state === 'tested') phase = original.phase === 'publish_pending' ? 'publish_pending' : 'verification_resolved'
        else throw new Error('Original evidence and revision differ')
      } else if (definiteFailure(result) && original.phase !== 'publish_pending') phase = 'failed'
      else if (original.phase === 'publish_pending') throw new Error('Original publication unconfirmed')
      else phase = 'verification_pending'
    } else if (detail.state === 'draft' && result.state === 'draft' && !result.failure) phase = 'saved'
    else throw new Error('Unowned verification state')
    persist({ ...original, phase }, original)
    draft.value = detail; verification.value = confirmed
    const location: unknown = Reflect.get(result, 'processing_location')
    processingLocation.value = original.mode === 'local' ? 'local_runtime'
      : typeof location === 'string' && ['local_runtime', 'private_network', 'public_cloud'].includes(location) ? location : ''
  }
  async function readState(): Promise<void> {
    if (busy.value || postFlight.value) return
    if (pendingNotSubmitted.value) {
      notice('本页确认原操作尚未发送，请先保存原记录并明确继续；只读找回不能代替这次提交。')
      return
    }
    const generation = epoch; busy.value = 'read'
    try {
      const original = stored(admin())
      if (!original) throw new Error('Original journal unavailable')
      journal.value = original; mode.value = original.mode
      if (original.phase === 'save_pending') {
        const saved = await api.readSavedDraftByKey(original.save_key)
        if (!current(original, generation)) return
        assertDraft(saved, original)
        if (saved.idempotent_replay !== true) throw new Error('Original save not confirmed')
        const next: Journal = { ...original, phase: 'saved', deployment_id: saved.deployment_id, revision: saved.revision }
        persist(next, original)
        await exact(next, generation)
      } else await exact(original, generation)
      if (generation === epoch) notice(recoveryRequired.value
        ? '原操作仍未确认，请保留原号继续只读核查。发布与验证均不会重发。'
        : '原配置与状态已找回；请按当前固定修订明确选择下一步。', recoveryRequired.value ? 'warning' : 'success')
    } catch (error) {
      if (generation === epoch) { denied(error); storageError.value = true; notice('原结果未读回或记录不一致。保留原目标与请求号，不换号重发；恢复读取权限后继续找回。') }
    } finally { if (generation === epoch) busy.value = '' }
  }
  async function save(payload: SpeechDeployment | TranscriptionDeployment): Promise<void> {
    if (busy.value || postFlight.value || journal.value || storageError.value) return
    const generation = epoch
    const original: Journal = { admin_id: admin(), kind, mode: mode.value, phase: 'save_pending', save_key: crypto.randomUUID(),
      deployment_id: null, revision: null, verification_key: null }
    await saveOriginal(structuredClone(payload), original, generation)
  }
  async function saveOriginal(payload: SpeechDeployment | TranscriptionDeployment, original: Journal, generation: number): Promise<void> {
    if (!prepareSubmission(original, null, generation, () => saveOriginal(payload, original, generation))) return
    busy.value = 'save'; postFlight.value = true
    try {
      const saved = await api.save(payload, original.save_key)
      if (!current(original, generation)) return
      assertDraft(saved, original)
      const next: Journal = { ...original, phase: 'saved', deployment_id: saved.deployment_id, revision: saved.revision }
      persist(next, original)
      draft.value = saved
      verification.value = { deployment_id: saved.deployment_id, revision: saved.revision, state: saved.state, validation_evidence: null }
      processingLocation.value = original.mode === 'local' ? 'local_runtime' : ''
      notice('部署草稿已保存；请确认处理位置后明确验证。', 'success')
    } catch (error) {
      if (current(original, generation)) {
        denied(error)
        if (unsubmitted(error)) {
          try { remove(original); notice('输入明确未提交。请修正并重新输入连接信息后保存。', 'error') }
          catch { storageError.value = true; notice('未提交证明已返回，但原会话记录未能解除；本次不会再次发送。') }
        } else notice('保存结果未确认，连接秘密已清除。请按原请求号只读找回；不会重发保存。')
      }
    } finally { postFlight.value = false; if (generation === epoch) { clearSensitive(); busy.value = '' } }
  }
  async function verify(): Promise<void> {
    const original = journal.value
    if (!original || !canVerify.value || !positive(original.deployment_id) || !positive(original.revision)) return
    const verificationKey = crypto.randomUUID()
    const next: Journal = { ...original, phase: 'verification_pending', verification_key: verificationKey }
    const generation = epoch
    await verifyOriginal(original, next, generation, processingLocation.value)
  }
  async function verifyOriginal(original: Journal, next: Journal, generation: number, location: string): Promise<void> {
    if (!positive(original.deployment_id) || !positive(original.revision) || !key(next.verification_key)) return
    if (!prepareSubmission(next, original, generation, () => verifyOriginal(original, next, generation, location))) return
    busy.value = 'verify'; postFlight.value = true
    try {
      await api.verify(original.deployment_id, original.revision, location, next.verification_key, 150000)
      if (!current(next, generation)) return
      // The exact original-key GET proves ownership and immutable outcome before publishing.
      await exact(next, generation)
      if (generation === epoch) notice(journal.value?.phase === 'failed' ? '原验证已明确失败，可修正后新建草稿。'
        : journal.value?.phase === 'verification_resolved' ? '固定样本结构验证通过，可明确发布此修订。' : '原验证仍未确认，请保留原号只读找回。',
      journal.value?.phase === 'verification_resolved' ? 'success' : 'warning')
    } catch (error) {
      if (generation === epoch) { denied(error); notice('验证结果未确认。请只读找回同一管理员、部署修订和原验证号，不直接重发。') }
    } finally { postFlight.value = false; if (generation === epoch) busy.value = '' }
  }
  async function publish(): Promise<void> {
    const original = journal.value
    if (!original || !canPublish.value || !positive(original.deployment_id) || !positive(original.revision)) return
    const next: Journal = { ...original, phase: 'publish_pending' }
    const generation = epoch
    await publishOriginal(original, next, generation)
  }
  async function publishOriginal(original: Journal, next: Journal, generation: number): Promise<void> {
    if (!positive(original.deployment_id) || !positive(original.revision)) return
    if (!prepareSubmission(next, original, generation, () => publishOriginal(original, next, generation))) return
    busy.value = 'publish'; postFlight.value = true
    try {
      const result = await api.publish(original.deployment_id, original.revision)
      if (!current(next, generation)) return
      if (result.deployment_id !== original.deployment_id || result.state !== 'published') throw new Error('Publication differs')
      await exact(next, generation)
      if (generation === epoch) notice(published.value ? '原部署修订已发布，请继续配置目标环境的能力路由。'
        : '发布结果未确认，请只读找回固定修订；不会再次发布。', published.value ? 'success' : 'warning')
    } catch (error) {
      if (generation === epoch) { denied(error); notice('发布结果未确认。保留原部署与修订，仅用读取状态找回；不会切换目标或重发。') }
    } finally { postFlight.value = false; if (generation === epoch) busy.value = '' }
  }
  function startNewDraft(): void {
    const original = journal.value
    if (!original || !canStartNew.value) return
    try { remove(original) }
    catch { storageError.value = true; notice('无法解除已确认的原操作记录，本次不会创建新草稿。'); return }
    epoch += 1; clearSensitive(); draft.value = null; verification.value = null
    processingLocation.value = mode.value === 'local' ? 'local_runtime' : ''
    notice('已开始新草稿。请明确保存与验证；原记录仍保留在服务端，不会重发原操作。')
  }
  watch(mode, (value) => {
    if (journal.value && value !== journal.value.mode) { mode.value = journal.value.mode; return }
    if (!journal.value) { clearSensitive(); processingLocation.value = value === 'local' ? 'local_runtime' : '' }
  }, { flush: 'sync' })
  watch([() => user.info.id, () => user.accessToken], () => {
    epoch += 1; clearSensitive(); draft.value = null; verification.value = null; journal.value = null
    pendingNotSubmitted.value = false; retryOriginal = null
    processingLocation.value = ''; busy.value = ''; message.value = ''; storageError.value = false
    load()
  }, { flush: 'sync' })
  onMounted(load)
  onBeforeUnmount(() => { epoch += 1; pendingNotSubmitted.value = false; retryOriginal = null; clearSensitive() })
  return { mode, journal, draft, verification, processingLocation, busy, postFlight, locked, published,
    recoveryRequired, canVerify, canPublish, canStartNew, pendingNotSubmitted, retryUnsubmitted,
    message, messageType, usageNotice, phaseLabel, save, verify, publish, readState, startNewDraft }
}
