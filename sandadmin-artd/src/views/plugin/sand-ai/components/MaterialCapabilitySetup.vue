<template>
  <div class="material-capability">
    <h4>准备材料处理能力</h4>
    <p v-if="!environmentId">先在工作目标中选择应用和环境。</p>
    <template v-if="environmentId || pending || journalError">
      <p>目标：{{ applicationName }} / {{ environmentName }}</p>
      <p
        >材料解析：本地解析 TXT、文本型 PDF、DOCX 等。扫描 PDF 和图片需要另行配置
        OCR；首次样本请使用文本材料。</p
      >
      <p>结构化抽取：{{ extractionLabel }}</p>
      <p v-if="profile"
        >当前能力档：{{ profile.name }} · 修订 {{ profile.revision }} ·
        {{ profile.state === 'published' ? '已发布' : '尚未发布' }}</p
      >
      <p v-if="missingPermissions.length"
        >缺少{{ missingPermissions.join('、') }}材料处理能力权限，请联系平台管理员。</p
      >
      <ElAlert v-if="notice" :title="notice" :type="noticeType" :closable="false" show-icon />
      <ElAlert
        v-if="missingRouteConfig"
        title="既有路线未提供可安全保留的空配置，无法在此补齐材料能力。请联系管理员核查读取接口或历史配置。"
        type="warning"
        :closable="false"
      />
      <ElAlert v-if="conflict" :title="conflict" type="warning" :closable="false" />
      <ElAlert
        v-if="saveConflict"
        title="能力档已变化。你的材料发送位置选择仍保留；重新读取后核对最新路线，再选择采用最新状态。"
        type="warning"
        :closable="false"
      />
      <ElAlert v-if="pending" type="warning" :closable="false"
        :title="`原${pending.action === 'save' ? '保存' : '发布'}结果待确认：环境 ${pending.environment_id}。只能读取原目标；完整配置匹配仅说明当前状态达到预期，不是原请求收据。`" />
      <ElAlert v-if="journalError" :title="journalError" type="warning" :closable="false" />
      <ElForm label-width="150px" @submit.prevent>
        <ElFormItem label="材料内容发送位置">
          <ElSelect
            v-model="policy"
            :disabled="Boolean(profile) || Boolean(busy) || writeFlight || !loaded || Boolean(pending) || Boolean(journalError)"
          >
            <ElOption label="仅本地：材料留在本机" value="local_only" />
            <ElOption label="仅私有网络：用于内部服务" value="private_only" />
            <ElOption label="已审核公共云：允许发送材料至已审核服务商" value="approved_cloud" />
          </ElSelect>
          <span v-if="profile">沿用已有发送策略。需要更改时，请到专业配置明确选择。</span>
        </ElFormItem>
      </ElForm>
      <p v-if="!ready"
        >所选模型与发送位置的相容性将在保存和发布时由服务端核验。模型的聊天验证不证明材料抽取质量。</p
      >
      <ElSpace wrap>
        <ElButton :disabled="!canSave" :loading="busy === 'save'" @click="save"
          >保存能力草稿</ElButton
        >
        <ElButton
          type="primary"
          :disabled="!canPublish"
          :loading="busy === 'publish'"
          @click="publish"
          >发布材料处理能力</ElButton
        >
        <ElButton :disabled="Boolean(busy) || writeFlight || !canRead" :loading="busy === 'read'" @click="reload"
          >{{ pending ? '读取原操作状态' : '重新读取状态' }}</ElButton
        >
        <ElButton v-if="saveConflict && loaded" :disabled="Boolean(busy) || writeFlight || Boolean(pending) || Boolean(journalError)" @click="adoptLatest"
          >采用最新状态并放弃本地选择</ElButton
        >
        <ElButton @click="emit('professional')">查看专业能力配置</ElButton>
      </ElSpace>
      <p v-if="ready"
        >材料处理能力已发布，可以运行真实样本。样本仍需要该环境的 SandIAM
        工作负载身份、已解析私有文件和真实材料快照。</p
      >
      <p v-else>先保存并发布材料处理能力，再继续下方材料配置和真实样本。</p>
    </template>
  </div>
</template>

<script setup lang="ts">
  import { computed, onBeforeUnmount, ref, toRaw, watch } from 'vue'
  import { useAuth } from '@/hooks/core/useAuth'
  import { HttpError } from '@/utils/http/error'
  import { useAdminReadIdentity } from '../composables/useAdminReadIdentity'
  import capabilityApi, {
    type CapabilitySavePayload,
    type SandAiCapabilityMetadata,
    type SandAiCapabilityProfile,
    type SandAiCapabilityRoute
  } from '../api/capability'

  const props = defineProps<{
    environmentId: number | ''
    applicationName: string
    environmentName: string
    deploymentId: number | null
    deploymentName: string
    extractionDeclared: boolean
  }>()
  const emit = defineEmits<{
    (event: 'status', environmentId: number, ready: boolean): void
    (event: 'professional'): void
  }>()
  const { hasAuth } = useAuth()
  const identity = useAdminReadIdentity()
  const canRead = computed(
    () => hasAuth('sand_ai:setup:capability:read') && hasAuth('sand_ai:setup:capability:metadata')
  )
  const missingPermissions = computed(() =>
    (['metadata', 'read', 'save', 'publish'] as const)
      .filter((action) => !hasAuth(`sand_ai:setup:capability:${action}`))
      .map(
        (action) =>
          ({ metadata: '读取能力目录', read: '读取', save: '保存', publish: '发布' })[action]
      )
  )
  const profile = ref<SandAiCapabilityProfile | null>(null)
  const metadata = ref<SandAiCapabilityMetadata | null>(null)
  const policy = ref('')
  const saveConflict = ref(false)
  const loaded = ref(false)
  const busy = ref<'read' | 'save' | 'publish' | ''>('')
  const notice = ref('')
  const noticeType = ref<'info' | 'warning' | 'success'>('info')
  type CapabilityIntent = {
    version: 1
    admin_id: number
    environment_id: number
    action: 'save' | 'publish'
    payload: CapabilitySavePayload
    id?: number
    revision?: number
    previousRevision: number
  }
  const pending = ref<CapabilityIntent | null>(null)
  const journalError = ref('')
  const writeFlight = ref(false)
  let alive = true
  let generation = 0
  const routes = computed(() => profile.value?.routes ?? [])
  const parseRoutes = computed(() =>
    routes.value.filter((route) => route.capability_code === 'document_parse')
  )
  const extractionRoutes = computed(() =>
    routes.value.filter((route) => route.capability_code === 'structured_extraction')
  )
  const extractionLabel = computed(() =>
    extractionRoutes.value.length
      ? extractionRoutes.value
          .map((route) => `已有模型部署 ${route.deployment_id ?? '未设置'}`)
          .join('、')
      : props.deploymentId && props.extractionDeclared
        ? props.deploymentName
        : '请先发布声明支持材料抽取的模型，或到专业配置核对历史模型。'
  )
  const registered = computed(() => {
    const parse = metadata.value?.capabilities.document_parse
    const extraction = metadata.value?.capabilities.structured_extraction
    const drivers = parse?.drivers
    return (
      parse?.route_kind === 'parse_driver' &&
      extraction?.route_kind === 'model_deployment' &&
      typeof drivers === 'object' &&
      drivers !== null &&
      'native_document_parse' in drivers
    )
  })
  const conflict = computed(() => {
    if (routes.value.some((route) => route.status !== 1))
      return '能力档含停用路线；简化准备不能保留其停用状态，请在专业配置核对。'
    if (
      parseRoutes.value.some(
        (route) =>
          route.route_kind !== 'parse_driver' ||
          route.driver_code !== 'native_document_parse' ||
          route.status !== 1
      )
    ) {
      return '此环境已有其他或停用的材料解析路线。请在专业配置核对；这里不会替换。'
    }
    if (
      extractionRoutes.value.some(
        (route) =>
          route.route_kind !== 'model_deployment' ||
          !route.deployment_id ||
          route.status !== 1 ||
          (props.deploymentId !== null && route.deployment_id !== props.deploymentId)
      )
    ) {
      return '此环境已有不同或停用的材料抽取路线。请在专业配置明确选择；这里不会替换。'
    }
    if (loaded.value && !registered.value)
      return '能力目录未提供本地文档解析或材料抽取能力，请联系管理员检查安装。'
    return ''
  })
  const complete = computed(
    () => parseRoutes.value.length > 0 && extractionRoutes.value.length > 0 && !conflict.value
  )
  const ready = computed(
    () =>
      loaded.value &&
      !saveConflict.value &&
      profile.value?.state === 'published' &&
      profile.value.status === 1 &&
      complete.value &&
      !pending.value &&
      !journalError.value &&
      !writeFlight.value
  )
  function isEmptyConfig(config: unknown): config is Record<string, never> {
    return (
      typeof config === 'object' &&
      config !== null &&
      !Array.isArray(config) &&
      Object.keys(config).length === 0
    )
  }
  const missingRouteConfig = computed(() =>
    routes.value.some((route) => !isEmptyConfig(route.config))
  )
  const canSave = computed(
    () =>
      !missingRouteConfig.value &&
      !saveConflict.value &&
      loaded.value &&
      hasAuth('sand_ai:setup:capability:save') &&
      !busy.value &&
      !pending.value &&
      !journalError.value &&
      !writeFlight.value &&
      positiveId(identity.actor.value) &&
      !conflict.value &&
      Boolean(policy.value) &&
      (!parseRoutes.value.length || !extractionRoutes.value.length) &&
      (extractionRoutes.value.length > 0 || Boolean(props.deploymentId && props.extractionDeclared))
  )
  const canPublish = computed(
    () =>
      !missingRouteConfig.value &&
      !saveConflict.value &&
      loaded.value &&
      hasAuth('sand_ai:setup:capability:publish') &&
      !busy.value &&
      !pending.value &&
      !journalError.value &&
      !writeFlight.value &&
      positiveId(identity.actor.value) &&
      profile.value?.state === 'draft' &&
      complete.value
  )

  function isProfileRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value)
  }
  function positiveId(value: unknown): value is number {
    return typeof value === 'number' && Number.isSafeInteger(value) && value > 0
  }
  function isProfileRoute(value: unknown): value is SandAiCapabilityRoute {
    return (
      isProfileRecord(value) &&
      typeof value.capability_code === 'string' &&
      value.capability_code.length > 0 &&
      ['model_deployment', 'parse_driver', 'audio_driver'].includes(String(value.route_kind)) &&
      positiveId(value.priority) &&
      (value.status === 1 || value.status === 2) &&
      (value.deployment_id === undefined ||
        value.deployment_id === null ||
        positiveId(value.deployment_id)) &&
      (value.driver_code === undefined ||
        value.driver_code === null ||
        typeof value.driver_code === 'string') &&
      (value.config === undefined || isProfileRecord(value.config))
    )
  }
  function isProfile(value: unknown, environmentId: number): value is SandAiCapabilityProfile {
    return (
      isProfileRecord(value) &&
      positiveId(value.id) &&
      value.environment_id === environmentId &&
      positiveId(value.revision) &&
      typeof value.name === 'string' &&
      value.name.length > 0 &&
      (value.template_code === null || typeof value.template_code === 'string') &&
      typeof value.data_egress_policy === 'string' &&
      ['local_only', 'private_only', 'approved_cloud'].includes(value.data_egress_policy) &&
      typeof value.state === 'string' &&
      ['draft', 'published', 'archived'].includes(value.state) &&
      (value.status === 1 || value.status === 2) &&
      Array.isArray(value.routes) &&
      value.routes.every(isProfileRoute)
    )
  }
  function readProfileResponse(
    response: unknown,
    environmentId: number
  ): SandAiCapabilityProfile | null {
    if (!isProfileRecord(response) || !Object.prototype.hasOwnProperty.call(response, 'profile'))
      throw new Error('Incomplete capability response')
    if (response.profile === null) return null
    if (!isProfile(response.profile, environmentId)) throw new Error('Invalid capability profile')
    return response.profile
  }
  function writtenProfileResponse(
    response: unknown,
    environmentId: number
  ): SandAiCapabilityProfile {
    const value = readProfileResponse(response, environmentId)
    if (value === null) throw new Error('Missing written capability profile')
    return value
  }

  function fingerprint(value: SandAiCapabilityProfile | null): string {
    return JSON.stringify(value)
  }
  function payloadFor(value: SandAiCapabilityProfile): CapabilitySavePayload {
    return {
      environment_id: value.environment_id,
      expected_revision: value.revision,
      name: value.name,
      template_code: value.template_code,
      data_egress_policy: value.data_egress_policy,
      routes: (value.routes ?? []).map((route) => ({
        capability_code: route.capability_code,
        route_kind: route.route_kind,
        deployment_id: route.deployment_id ?? null,
        driver_code: route.driver_code ?? null,
        priority: route.priority,
        config: route.config ? structuredClone(toRaw(route.config)) : {}
      }))
    }
  }
  function samePayload(value: SandAiCapabilityProfile, payload: CapabilitySavePayload): boolean {
    if (
      !(
        (value.state === 'draft' && value.status === 2) ||
        (value.state === 'published' && value.status === 1)
      )
    )
      return false
    if ((value.routes ?? []).some((route) => route.status !== 1 || !isEmptyConfig(route.config)))
      return false
    const strip = (route: SandAiCapabilityRoute) => ({
      capability_code: route.capability_code,
      route_kind: route.route_kind,
      deployment_id: route.deployment_id ?? null,
      driver_code: route.driver_code ?? null,
      priority: route.priority,
      config: route.config ?? {}
    })
    return (
      value.environment_id === payload.environment_id &&
      value.name === payload.name &&
      value.template_code === (payload.template_code ?? null) &&
      value.data_egress_policy === payload.data_egress_policy &&
      JSON.stringify((value.routes ?? []).map((route) => JSON.stringify(strip(route))).sort()) ===
        JSON.stringify(payload.routes.map((route) => JSON.stringify(strip(route))).sort())
    )
  }
  function requireActor(): number {
    const actor = identity.actor.value
    if (!positiveId(actor)) throw new Error('Administrator unavailable')
    return actor
  }
  const journalKey = (actor: number) => `sand-ai:material-capability:${actor}:active`
  function decodeIntent(value: unknown, actor: number): CapabilityIntent {
    if (!isProfileRecord(value) || value.version !== 1 || value.admin_id !== actor || !positiveId(value.environment_id) ||
        (value.action !== 'save' && value.action !== 'publish') || !isProfileRecord(value.payload) ||
        typeof value.previousRevision !== 'number' || !Number.isSafeInteger(value.previousRevision) ||
        value.previousRevision < 0 || value.previousRevision >= Number.MAX_SAFE_INTEGER ||
        (value.id !== undefined && !positiveId(value.id)) || (value.revision !== undefined && !positiveId(value.revision)))
      throw new Error('Original capability intent unavailable')
    const payload = value.payload
    if (payload.environment_id !== value.environment_id || payload.expected_revision !== (value.previousRevision || null) ||
        typeof payload.name !== 'string' || !payload.name.trim() ||
        !(payload.template_code === null || typeof payload.template_code === 'string') ||
        !['local_only', 'private_only', 'approved_cloud'].includes(String(payload.data_egress_policy)) ||
        !Array.isArray(payload.routes) || !payload.routes.length)
      throw new Error('Original capability payload unavailable')
    const safeRoutes = payload.routes.map<CapabilitySavePayload['routes'][number]>((route: unknown) => {
      if (!isProfileRecord(route) || typeof route.capability_code !== 'string' || !route.capability_code ||
          (route.route_kind !== 'model_deployment' && route.route_kind !== 'parse_driver' && route.route_kind !== 'audio_driver') ||
          !positiveId(route.priority) || !(route.deployment_id === null || positiveId(route.deployment_id)) ||
          !(route.driver_code === null || typeof route.driver_code === 'string') || !isEmptyConfig(route.config))
        throw new Error('Original capability route unavailable')
      return { capability_code: route.capability_code, route_kind: route.route_kind, deployment_id: route.deployment_id,
        driver_code: route.driver_code, priority: route.priority, config: {} }
    })
    if (value.action === 'publish' && (!positiveId(value.id) || value.revision !== value.previousRevision))
      throw new Error('Original publication target unavailable')
    if (value.action === 'save' && ((value.id === undefined) !== (value.revision === undefined) ||
        (value.revision !== undefined && value.revision !== value.previousRevision + 1)))
      throw new Error('Original save revision unavailable')
    return { version: 1, admin_id: actor, environment_id: value.environment_id, action: value.action,
      previousRevision: value.previousRevision, ...(positiveId(value.id) ? { id: value.id } : {}),
      ...(positiveId(value.revision) ? { revision: value.revision } : {}),
      payload: { environment_id: value.environment_id, expected_revision: value.previousRevision || null,
        name: payload.name, template_code: payload.template_code, data_egress_policy: String(payload.data_egress_policy), routes: safeRoutes } }
  }
  function storedIntent(actor: number): CapabilityIntent | null {
    const raw = sessionStorage.getItem(journalKey(actor))
    return raw === null ? null : decodeIntent(JSON.parse(raw), actor)
  }
  function restoreIntent(): void {
    pending.value = null
    journalError.value = ''
    if (!positiveId(identity.actor.value)) return
    try { pending.value = storedIntent(identity.actor.value) }
    catch { journalError.value = '无法读取原材料能力操作记录，写入已锁定；请保留记录并联系管理员。' }
  }
  function persistIntent(operation: CapabilityIntent, previous: CapabilityIntent | null = null): void {
    if (operation.admin_id !== requireActor()) throw new Error('Original administrator differs')
    const safe = decodeIntent(operation, operation.admin_id)
    if (JSON.stringify(storedIntent(operation.admin_id)) !== JSON.stringify(previous)) throw new Error('Original capability intent changed')
    sessionStorage.setItem(journalKey(operation.admin_id), JSON.stringify(safe))
    if (JSON.stringify(storedIntent(operation.admin_id)) !== JSON.stringify(safe)) throw new Error('Original intent not persisted')
    pending.value = safe
  }
  function clearIntent(operation: CapabilityIntent): void {
    if (operation.admin_id !== requireActor() || JSON.stringify(storedIntent(operation.admin_id)) !== JSON.stringify(operation))
      throw new Error('Original capability intent changed')
    sessionStorage.removeItem(journalKey(operation.admin_id))
    if (storedIntent(operation.admin_id) !== null) throw new Error('Original intent retained')
    pending.value = null
  }
  function applyRead(value: SandAiCapabilityProfile | null, environmentId: number): void {
    const operation = pending.value
    if (operation && value && operation.admin_id === identity.actor.value && operation.environment_id === environmentId &&
        samePayload(value, operation.payload) &&
        ((operation.action === 'save' && value.revision === (operation.previousRevision ?? 0) + 1 &&
          (!operation.id || (value.id === operation.id && value.revision === operation.revision))) ||
         (operation.action === 'publish' && value.id === operation.id && value.revision === operation.revision &&
          value.state === 'published' && value.status === 1)))
      clearIntent(operation)
    if (environmentId !== props.environmentId) return
    profile.value = value
    if (!saveConflict.value) policy.value = value?.data_egress_policy ?? policy.value
    loaded.value = true
    emit('status', environmentId, ready.value)
  }
  function adoptLatest(): void {
    if (!saveConflict.value || !loaded.value || pending.value || writeFlight.value || journalError.value) return
    saveConflict.value = false
    policy.value = profile.value?.data_egress_policy ?? ''
    noticeType.value = 'info'
    notice.value = '已采用最新能力档。请核对材料路线和发送位置后再保存。'
    if (props.environmentId) emit('status', props.environmentId, ready.value)
  }
  function rejected(error: unknown): boolean {
    return error instanceof HttpError && [400, 401, 403, 422].includes(error.code)
  }
  function revisionConflict(error: unknown): boolean {
    return error instanceof HttpError && isProfileRecord(error.data) && error.data.error_code === 'SAND_AI_RESOURCE_CONFLICT'
  }
  function failure(error: unknown, action: 'read' | 'save' | 'publish'): string {
    if (action === 'read')
      return error instanceof HttpError && (error.code === 401 || error.code === 403)
        ? '缺少读取材料处理能力权限，请联系平台管理员。'
        : '无法读取完整能力状态，写操作保持锁定。请检查连接或权限后重新读取。'
    if (error instanceof HttpError && (error.code === 401 || error.code === 403))
      return '缺少读取、保存或发布材料处理能力权限，请联系平台管理员。'
    if (revisionConflict(error))
      return '能力档已被其他管理员修改。本地选择仍保留；请重新读取并核对最新路线后再保存。'
    if (rejected(error))
      return '模型能力或发送策略不相容，请核对支持材料抽取的已发布模型及允许的发送位置。已有配置与本地选择仍保留。'
    return '结果尚未确认，请重新读取状态后再继续。若仍无法确认，请在专业配置核查；不要重复保存或发布。'
  }
  async function reload(): Promise<void> {
    if (busy.value || writeFlight.value || !canRead.value) return
    restoreIntent()
    const environmentId = pending.value?.environment_id ?? props.environmentId
    if (!environmentId || journalError.value) return
    const current = generation
    busy.value = 'read'
    loaded.value = false
    emit('status', environmentId, false)
    notice.value = ''
    try {
      const [directory, result] = await Promise.all([
        capabilityApi.metadata(),
        capabilityApi.readProfile(environmentId)
      ])
      if (!alive || current !== generation) return
      if (
        !isProfileRecord(directory) ||
        !isProfileRecord(directory.capabilities) ||
        !isProfileRecord(directory.templates)
      )
        throw new Error('Invalid capability metadata')
      metadata.value = directory
      applyRead(readProfileResponse(result, environmentId), environmentId)
      noticeType.value = pending.value || saveConflict.value ? 'warning' : ready.value ? 'success' : 'info'
      notice.value = saveConflict.value
        ? '已读取最新能力状态，本地发送位置选择仍保留。请比较路线后明确采用最新状态。'
        : pending.value
        ? '原操作仍未确认。保留原环境与完整配置，仅可继续读取；不会重新保存或发布。'
        : ready.value
          ? '已从服务端读取当前材料能力已发布；这是当前配置事实，不是原操作收据。'
          : profile.value
            ? '已读取当前能力档；请核对材料模型和发送位置，再保存或发布。'
            : props.deploymentId && props.extractionDeclared
              ? '当前环境尚无材料能力配置。请选择材料内容发送位置，再保存能力草稿。'
              : '当前环境尚无材料能力配置。请先在上方发布声明支持材料抽取的模型；已有模型可通过“查看专业能力配置”核对。'
    } catch (error) {
      if (!alive || current !== generation) return
      noticeType.value = 'warning'
      notice.value = failure(error, 'read')
    } finally {
      if (alive && current === generation) busy.value = ''
    }
    if (alive && current === generation && !pending.value && environmentId !== props.environmentId) void reload()
  }
  async function save(): Promise<void> {
    const environmentId = props.environmentId
    if (!environmentId || !canSave.value) return
    const current = generation
    const baseline = fingerprint(profile.value)
    const payload: CapabilitySavePayload = profile.value
      ? payloadFor(profile.value)
      : {
          environment_id: environmentId,
          expected_revision: null,
          name: '材料处理能力',
          template_code: null,
          data_egress_policy: policy.value,
          routes: []
        }
    // Copy every existing route including its config; only append absent capabilities.
    if (!parseRoutes.value.length)
      payload.routes.push({
        capability_code: 'document_parse',
        route_kind: 'parse_driver',
        driver_code: 'native_document_parse',
        deployment_id: null,
        priority: 100,
        config: {}
      })
    if (!extractionRoutes.value.length)
      payload.routes.push({
        capability_code: 'structured_extraction',
        route_kind: 'model_deployment',
        deployment_id: props.deploymentId,
        driver_code: null,
        priority: 100,
        config: {}
      })
    busy.value = 'save'
    writeFlight.value = true
    notice.value = ''
    let phase: 'preflight' | 'write' | 'confirm' = 'preflight'
    loaded.value = false
    emit('status', environmentId, false)
    try {
      const latest = await capabilityApi.readProfile(environmentId)
      if (!alive || current !== generation) return
      const latestProfile = readProfileResponse(latest, environmentId)
      if (fingerprint(latestProfile) !== baseline) {
        saveConflict.value = true
        profile.value = latestProfile
        noticeType.value = 'warning'
        notice.value = '能力档已发生变化，本地选择仍保留。请重新读取并核对最新路线后再保存。'
        return
      }
      persistIntent({ version: 1, admin_id: requireActor(), environment_id: environmentId, action: 'save', payload, previousRevision: profile.value?.revision ?? 0 })
      phase = 'write'
      const saved = await capabilityApi.saveProfile(payload)
      if (!alive || current !== generation) return
      phase = 'confirm'
      const savedProfile = writtenProfileResponse(saved, environmentId)
      if (
        !samePayload(savedProfile, payload) ||
        savedProfile.revision !== (pending.value?.previousRevision ?? 0) + 1
      )
        throw new Error('Unexpected saved capability profile')
      const original = pending.value
      if (!original) throw new Error('Original capability operation unavailable')
      persistIntent({ ...original, id: savedProfile.id, revision: savedProfile.revision }, original)
      const result = await capabilityApi.readProfile(environmentId)
      if (!alive || current !== generation) return
      applyRead(readProfileResponse(result, environmentId), environmentId)
      noticeType.value = pending.value ? 'warning' : 'success'
      notice.value = pending.value
        ? '保存结果尚未确认，请重新读取状态；不会自动重发。'
        : ready.value
          ? '已重新读取并确认材料处理能力已发布，可以运行真实样本。'
          : '当前能力草稿与本次完整配置及下一修订一致，尚未发布；该读取不是原请求收据。'
    } catch (error) {
      if (!alive || current !== generation) return
      if (phase === 'write' && revisionConflict(error) && pending.value) {
        try { clearIntent(pending.value) } catch { journalError.value = '原冲突操作记录无法安全解除，请保留记录并联系管理员。' }
      }
      if (phase === 'write' && revisionConflict(error)) saveConflict.value = true
      loaded.value = false
      noticeType.value = 'warning'
      notice.value = failure(error, phase === 'preflight' ? 'read' : 'save')
    } finally {
      writeFlight.value = false
      if (alive && current === generation) busy.value = ''
    }
  }
  async function publish(): Promise<void> {
    const environmentId = props.environmentId
    const target = profile.value
    if (!environmentId || !target || !canPublish.value) return
    const current = generation
    busy.value = 'publish'
    writeFlight.value = true
    notice.value = ''
    let phase: 'preflight' | 'write' | 'confirm' = 'preflight'
    loaded.value = false
    emit('status', environmentId, false)
    try {
      const latest = await capabilityApi.readProfile(environmentId)
      if (!alive || current !== generation) return
      const latestProfile = readProfileResponse(latest, environmentId)
      if (fingerprint(latestProfile) !== fingerprint(target)) {
        applyRead(latestProfile, environmentId)
        noticeType.value = 'warning'
        notice.value = '能力档已发生变化，已重新读取。请核对后再发布。'
        return
      }
      persistIntent({
        version: 1, admin_id: requireActor(), environment_id: environmentId,
        action: 'publish',
        payload: payloadFor(target),
        id: target.id,
        revision: target.revision,
        previousRevision: target.revision
      })
      phase = 'write'
      const published = await capabilityApi.publishProfile({
        environment_id: environmentId,
        id: target.id
      })
      if (!alive || current !== generation) return
      phase = 'confirm'
      const publishedProfile = writtenProfileResponse(published, environmentId)
      if (
        publishedProfile.id !== target.id ||
        publishedProfile.revision !== target.revision ||
        publishedProfile.state !== 'published' ||
        publishedProfile.status !== 1 ||
        !samePayload(publishedProfile, payloadFor(target))
      )
        throw new Error('Unexpected publication response')
      const result = await capabilityApi.readProfile(environmentId)
      if (!alive || current !== generation) return
      applyRead(readProfileResponse(result, environmentId), environmentId)
      noticeType.value = ready.value ? 'success' : 'warning'
      notice.value = ready.value
        ? '当前原目标修订已发布且完整配置一致，可以运行真实样本；此为当前配置事实。'
        : '发布结果尚未确认，请重新读取状态；不会自动重发。'
    } catch (error) {
      if (!alive || current !== generation) return
      if (phase === 'write' && revisionConflict(error) && pending.value) {
        try { clearIntent(pending.value) } catch { journalError.value = '原冲突操作记录无法安全解除，请保留记录并联系管理员。' }
      }
      loaded.value = false
      noticeType.value = 'warning'
      notice.value = failure(error, phase === 'preflight' ? 'read' : 'publish')
    } finally {
      writeFlight.value = false
      if (alive && current === generation) busy.value = ''
    }
  }
  watch(
    () => [props.environmentId, props.deploymentId, props.extractionDeclared, identity.actor.value, identity.epoch.value],
    () => {
      generation++
      profile.value = null
      metadata.value = null
      policy.value = ''
      saveConflict.value = false
      pending.value = null
      journalError.value = ''
      loaded.value = false
      busy.value = ''
      notice.value = ''
      noticeType.value = 'info'
      if (props.environmentId) emit('status', props.environmentId, false)
      restoreIntent()
      void reload()
    },
    { immediate: true, flush: 'sync' }
  )
  onBeforeUnmount(() => {
    alive = false
    generation++
  })
</script>

<style scoped>
  .material-capability {
    margin: 16px 0;
  }
  .material-capability :deep(.el-alert) {
    margin: 12px 0;
  }
  .material-capability :deep(.el-select) {
    width: 100%;
    max-width: 560px;
  }
</style>
