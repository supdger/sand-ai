<template>
  <div class="art-full-height">
    <ElCard class="art-table-card" shadow="never">
      <div class="cap-header">
        <div>
          <h2 class="cap-title">{{ materialConfigurationPage ? '材料整理配置' : '能力配置' }}</h2>
          <p class="cap-desc">
            为指定应用环境选择解析工具、模型和材料处理位置。仅配置本地文本解析时无需 API Key；AI 抽取仍需已发布模型。
          </p>
        </div>
        
      </div>

      <ElAlert v-if="navigationNotice" :title="navigationNotice" type="warning" :closable="false" class="cap-alert" />
      <ElButton v-if="route.query.configuration_origin === 'material_guide'"
        :disabled="Boolean(busy) || writeInFlight || restoringEntry" @click="returnToMaterialGuide">返回原材料整理引导</ElButton>
      <ElForm label-width="120px" class="cap-form" @submit.prevent>
        <ElFormItem label="应用">
          <ElSelect
            v-model="applicationId"
            filterable
            clearable
            placeholder="选择应用"
            style="width: 360px"
            :loading="loadingApps"
            :disabled="busy === 'save' || busy === 'publish' || Boolean(pending) || writeInFlight || journalError"
            @change="onApplicationChange"
          >
            <ElOption
              v-for="item in applications"
              :key="item.id"
              :label="`${item.name} (${item.code})`"
              :value="item.id"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem label="环境">
          <ElSelect
            v-model="environmentId"
            filterable
            clearable
            placeholder="选择环境"
            style="width: 360px"
            :loading="loadingEnvs"
            :disabled="!applicationId || busy === 'save' || busy === 'publish' || Boolean(pending) || writeInFlight || journalError"
            @change="onEnvironmentChange"
          >
            <ElOption
              v-for="item in environments"
              :key="item.id"
              :label="`${item.name} (${item.code})`"
              :value="item.id"
            />
          </ElSelect>
        </ElFormItem>
      </ElForm>

      <ElAlert
        v-if="errorMessage"
        type="error"
        :closable="true"
        :title="errorMessage"
        class="cap-alert"
        @close="errorMessage = ''"
      />
      <ElButton v-if="iamListError === 'applications'" :loading="loadingApps" @click="loadApps">重新读取应用</ElButton>
      <ElButton v-if="iamListError === 'environments'" :loading="loadingEnvs" @click="onApplicationChange">重新读取环境</ElButton>

      <ElAlert v-if="pending" type="warning" :closable="false"
        :title="`原${pending.action === 'save' ? '保存' : '发布'}结果待确认：应用 ${pending.application_id}／环境 ${pending.environment_id}。仅重新读取原目标，不会重发；当前配置匹配也不代表取得原请求回执。`" />
      <ElButton v-if="pending || journalError" :disabled="Boolean(busy) || writeInFlight" @click="restoreIntent(); loadEntryContext()">读取原操作状态</ElButton>
      <MaterialProfileEditor v-if="materialConfigurationPage" :environment-id="environmentId" />
      <template v-if="environmentId">
        <ElAlert v-if="revisionConflict" type="warning" :closable="false"
          title="配置已被其他管理员修改。本地表单仍在下方。重新加载以查看最新版本；核对后可选择采用最新配置，再重新填写自己的改动。" />
        <ElButton v-if="revisionConflict && profileLoaded" :disabled="Boolean(busy)" @click="adoptLatestProfile">
          采用最新配置并放弃本地修改
        </ElButton>
        <p v-if="revisionConflict && profileLoaded" class="cap-muted">
          服务端修订：{{ profile?.revision ?? '尚无配置' }}；
          名称：{{ profile?.name ?? '尚无配置' }}；
          路线：{{ profile?.routes?.map(route => `${route.capability_code} / ${route.route_kind} / ${route.deployment_id ?? route.driver_code ?? '未设置'}`).join('、') || '无' }}。
        </p>
        <ElAlert v-if="unsafeExistingConfig" type="warning" :closable="false"
          title="既有路线未提供可安全保留的空配置，或含停用路线。当前配置只读，请联系管理员核查读取接口或历史配置；此页不会覆盖保存。" />
        <ElForm :disabled="editBlocked" label-width="120px" class="cap-form" @submit.prevent>
          <ElFormItem label="配置名称">
            <ElInput v-model="form.name" style="width: 360px" placeholder="如 default-capability" />
          </ElFormItem>
          <ElFormItem label="模板">
            <ElSelect
              v-model="form.template_code"
              clearable
              placeholder="可选模板起点"
              style="width: 360px"
              @change="applyTemplate"
            >
              <ElOption
                v-for="(tpl, code) in metadata?.templates ?? {}"
                :key="code"
                :label="`${tpl.name} (${code})`"
                :value="code"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem label="材料发送位置">
            <ElSelect v-model="form.data_egress_policy" style="width: 360px">
              <ElOption label="仅本地：材料留在本机" value="local_only" />
              <ElOption label="仅私有网络：用于内部服务" value="private_only" />
              <ElOption label="已审核公共云：允许发送至已审核服务商" value="approved_cloud" />
            </ElSelect>
          </ElFormItem>
          <ElFormItem v-if="profile" label="当前版本">
            <ElSpace wrap>
              <ElTag>revision={{ profile.revision }}</ElTag>
              <ElTag :type="profile.state === 'published' ? 'success' : 'warning'">
                {{ profile.state }}
              </ElTag>
              <span class="cap-muted">id={{ profile.id }}</span>
            </ElSpace>
          </ElFormItem>
        </ElForm>

        <div class="cap-routes-toolbar">
          <h3 class="cap-section-title">能力与处理工具</h3>
          <ElSpace wrap class="cap-route-actions">
            <ElButton size="small" :disabled="editBlocked || Boolean(busy)" @click="prepareLocalTextRoute">添加本地文本解析</ElButton>
            <ElButton size="small" :disabled="editBlocked || Boolean(busy)" @click="prepareMaterialRoutes">补齐材料整理路由</ElButton>
            <ElButton size="small" :disabled="editBlocked || Boolean(busy)" @click="addRoute">添加路由</ElButton>
          </ElSpace>
        </div>

        <p class="cap-muted">首次只需本地文本解析时，点击“添加本地文本解析”，保存并发布即可。该工具支持 TXT、文本型 PDF、DOCX 等；扫描件 OCR 和材料 AI 抽取需另行配置。</p>
        <ElTable :data="form.routes" border empty-text="暂无路由">
          <ElTableColumn label="能力" min-width="160">
            <template #default="{ row }">
              <ElSelect
                v-model="row.capability_code"
                :disabled="editBlocked"
                filterable
                @change="() => syncRouteKind(row)"
              >
                <ElOption
                  v-for="code in capabilityCodes"
                  :key="code"
                  :label="code === 'rerank' ? '专业重排' : code"
                  :value="code"
                />
              </ElSelect>
            </template>
          </ElTableColumn>
          <ElTableColumn prop="route_kind" label="处理方式" width="150" />
          <ElTableColumn label="模型部署" min-width="300">
            <template #default="{ row }">
              <ElSelect v-if="usesDeployment(row)" v-model="row.deployment_id"
                :disabled="editBlocked" filterable remote clearable :remote-method="(query: string) => searchDeployments(query, row)"
                :loading="deploymentsLoading" placeholder="选择已发布部署" class="cap-deployment"
                @visible-change="(visible: boolean) => visible && searchDeployments('', row)">
                <ElOption v-for="item in deploymentOptions" :key="item.id"
                  :label="item.display_name" :value="item.id"
                  :disabled="!item.compatible_data_egress_policies.includes(form.data_egress_policy)" />
                <ElOption v-if="row.deployment_id && !deploymentOptions.some(item => item.id === row.deployment_id)"
                  :label="`现有部署 #${row.deployment_id}（请核对）`" :value="row.deployment_id" />
              </ElSelect>
              <span v-else class="cap-muted">—</span>
              <p v-if="isLocalOcr(row) && !row.deployment_id" class="cap-muted">未绑定部署时费用保持未计价，硬预算请求会被拒绝。可先保存，配置并发布部署价格后再绑定。</p>
            </template>
          </ElTableColumn>
          <ElTableColumn label="解析工具" min-width="180">
            <template #default="{ row }">
              <ElSelect
                v-if="row.route_kind === 'parse_driver' || row.route_kind === 'audio_driver'"
                v-model="row.driver_code"
                @change="!usesDeployment(row) && (row.deployment_id = undefined)"
                :disabled="editBlocked"
                clearable
                filterable
              >
                <ElOption
                  v-for="code in driverCodes(row.capability_code)"
                  :key="code"
                  :label="code"
                  :value="code"
                />
              </ElSelect>
              <span v-else class="cap-muted">—</span>
            </template>
          </ElTableColumn>
          <ElTableColumn label="优先顺序" width="110">
            <template #default="{ row }">
              <ElInputNumber :disabled="editBlocked" v-model="row.priority" :min="1" :controls="false" class="cap-number" />
            </template>
          </ElTableColumn>
          <ElTableColumn label="操作" width="90">
            <template #default="{ $index }">
              <ElButton :disabled="editBlocked" link type="danger" @click="removeRoute($index)">删除</ElButton>
            </template>
          </ElTableColumn>
        </ElTable>

        <ElSpace wrap class="cap-actions">
          <ElButton
            v-permission="'sand_ai:setup:capability:save'"
            type="primary"
            :loading="busy === 'save'"
            :disabled="editBlocked || Boolean(busy)"
            @click="handleSave"
          >
            保存草稿
          </ElButton>
          <ElButton
            v-permission="'sand_ai:setup:capability:publish'"
            type="success"
            :disabled="editBlocked || Boolean(busy) || !profile || profile.state === 'published'"
            :loading="busy === 'publish'"
            @click="handlePublish"
          >
            发布配置档
          </ElButton>
          <ElButton :disabled="Boolean(busy)" :loading="busy === 'load'" @click="reloadProfile">重新加载</ElButton>
        </ElSpace>
      </template>
      <MaterialProfileEditor v-if="!materialConfigurationPage" :environment-id="environmentId" />
      <LegalAnalysisPanel :environment-id="environmentId" />
      <section id="chat-route-trial" ref="chatTrialSection" tabindex="-1" aria-label="聊天路线试用">
        <ChatRouteTrialPanel :environment-id="environmentId" />
      </section>
    </ElCard>
  </div>
</template>

<script setup lang="ts">
  import { computed, nextTick, onActivated, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
  import { useUserStore } from '@/store/modules/user'
  import { readCapabilityProfileIntent, retainCapabilityProfileIntent, resolveCapabilityProfileIntent, type CapabilityProfileIntent } from '../api/capabilityProfileIntent'
  import { HttpError } from '@/utils/http/error'
  import { ElMessage, ElMessageBox } from 'element-plus'
  import { useRoute, useRouter } from 'vue-router'
  import iamApi, {
    type SandIamApplicationReference,
    type SandIamEnvironmentReference
  } from '../api/iam'
  import capabilityApi, {
    type CapabilitySavePayload,
    type SandAiCapabilityMetadata,
    type SandAiCapabilityProfile,
    type SandAiCapabilityRoute,
    type SandAiDataEgressPolicy
  } from '../api/capability'
  import onboardingApi, { type PublishedDeployment } from '../api/onboarding'
  import MaterialProfileEditor from '../components/MaterialProfileEditor.vue'
  import LegalAnalysisPanel from '../components/LegalAnalysisPanel.vue'
  import ChatRouteTrialPanel from '../components/ChatRouteTrialPanel.vue'

  type RouteDraft = {
    capability_code: string
    route_kind: SandAiCapabilityRoute['route_kind']
    deployment_id: number | undefined
    driver_code: string | null
    priority: number
  }

  const route = useRoute()
  const router = useRouter()
  let restoringEntry = true
  let entryInitialized = false
  const chatTrialSection = ref<HTMLElement | null>(null)
  const navigationNotice = ref('')
  const materialConfigurationPage = computed(() => route.path.endsWith('/material-config'))
  const loadingApps = ref(false)
  const loadingEnvs = ref(false)
  const iamListError = ref<'applications' | 'environments' | ''>('')
  const busy = ref<'load' | 'save' | 'publish' | ''>('')
  const errorMessage = ref('')
  const applications = ref<SandIamApplicationReference[]>([])
  const environments = ref<SandIamEnvironmentReference[]>([])
  const metadata = ref<SandAiCapabilityMetadata | null>(null)
  const deploymentOptions = ref<PublishedDeployment[]>([])
  const deploymentsLoading = ref(false)
  let deploymentGeneration = 0
  const profile = ref<SandAiCapabilityProfile | null>(null)
  const profileLoaded = ref(false)
  const revisionConflict = ref(false)
  let profileGeneration = 0
  let applicationGeneration = 0
  let environmentGeneration = 0
  const userStore = useUserStore()
  const adminId = computed(() => {
    const id = Number(userStore.info.id)
    return userStore.accessToken && Number.isSafeInteger(id) && id > 0 ? id : 0
  })
  let identityEpoch = 0
  const writeInFlight = ref(false)
  let writeFlightSerial = 0
  const journalError = ref(false)
  const pending = ref<CapabilityProfileIntent | null>(null)
  function restoreIntent(): void {
    pending.value = null
    journalError.value = false
    try { pending.value = readCapabilityProfileIntent(adminId.value) }
    catch (error) { journalError.value = true; errorMessage.value = error instanceof Error ? error.message : '无法读取原操作记录，写操作已锁定' }
  }
  function persistIntent(operation: CapabilityProfileIntent, previous: CapabilityProfileIntent | null = null): void {
    retainCapabilityProfileIntent(operation, previous)
    // Use the normalized persisted value for exact compare-and-remove later.
    pending.value = readCapabilityProfileIntent(operation.admin_id)
    if (!pending.value) throw new Error('原操作记录未保存')
  }
  function clearIntent(): void {
    if (!pending.value) return
    resolveCapabilityProfileIntent(pending.value)
    pending.value = null
  }
  const unsafeExistingConfig = computed(() => Boolean(profile.value && (!Array.isArray(profile.value.routes)
    || profile.value.routes.some(route => route.status !== 1 || typeof route.config !== 'object'
      || route.config === null || Array.isArray(route.config) || Object.keys(route.config).length !== 0))))
  const editBlocked = computed(() => revisionConflict.value || writeInFlight.value || journalError.value || Boolean(pending.value) || !profileLoaded.value || unsafeExistingConfig.value
    || Boolean(profile.value && profile.value.environment_id !== environmentId.value))
  const applicationId = ref<number | undefined>(undefined)
  const environmentId = ref<number | undefined>(undefined)

  const form = reactive({
    name: 'default-capability',
    template_code: '' as string,
    data_egress_policy: 'local_only' as SandAiDataEgressPolicy | string,
    routes: [] as RouteDraft[]
  })

  const capabilityCodes = computed(() => Object.keys(metadata.value?.capabilities ?? {}))

  function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null
  }

  function isProfileRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value)
  }
  function positiveId(value: unknown): value is number {
    return typeof value === 'number' && Number.isSafeInteger(value) && value > 0
  }
  function isProfileRoute(value: unknown): value is SandAiCapabilityRoute {
    return isProfileRecord(value) && typeof value.capability_code === 'string' && value.capability_code.length > 0
      && ['model_deployment', 'parse_driver', 'audio_driver'].includes(String(value.route_kind))
      && positiveId(value.priority) && (value.status === 1 || value.status === 2)
      && (value.deployment_id === undefined || value.deployment_id === null || positiveId(value.deployment_id))
      && (value.driver_code === undefined || value.driver_code === null || typeof value.driver_code === 'string')
      && (value.config === undefined || isProfileRecord(value.config))
  }
  function isProfile(value: unknown, environmentId: number): value is SandAiCapabilityProfile {
    return isProfileRecord(value) && positiveId(value.id) && value.environment_id === environmentId
      && positiveId(value.revision) && typeof value.name === 'string' && value.name.length > 0
      && (value.template_code === null || typeof value.template_code === 'string')
      && typeof value.data_egress_policy === 'string'
      && ['local_only', 'private_only', 'approved_cloud'].includes(value.data_egress_policy)
      && typeof value.state === 'string' && ['draft', 'published', 'archived'].includes(value.state)
      && (value.status === 1 || value.status === 2) && Array.isArray(value.routes) && value.routes.every(isProfileRoute)
  }
  function readProfileResponse(response: unknown, environmentId: number): SandAiCapabilityProfile | null {
    if (!isProfileRecord(response) || !Object.prototype.hasOwnProperty.call(response, 'profile')) throw new Error('Incomplete capability response')
    if (response.profile === null) return null
    if (!isProfile(response.profile, environmentId)) throw new Error('Invalid capability profile')
    return response.profile
  }
  function writtenProfileResponse(response: unknown, environmentId: number): SandAiCapabilityProfile {
    const value = readProfileResponse(response, environmentId)
    if (value === null) throw new Error('Missing written capability profile')
    return value
  }
  function sameConfiguration(value: SandAiCapabilityProfile, payload: CapabilitySavePayload): boolean {
    if (!((value.state === 'draft' && value.status === 2) || (value.state === 'published' && value.status === 1))) return false
    if (!value.routes || value.routes.some(route => !isProfileRecord(route.config) || Object.keys(route.config).length !== 0 || route.status !== 1)) return false
    const canonical = (routes: SandAiCapabilityRoute[]) => JSON.stringify(routes.map(route => JSON.stringify({
      capability_code: route.capability_code, route_kind: route.route_kind,
      deployment_id: route.deployment_id ?? null, driver_code: route.driver_code ?? null,
      priority: route.priority, config: route.config
    })).sort())
    return value.environment_id === payload.environment_id && value.name === payload.name
      && value.template_code === (payload.template_code ?? null) && value.data_egress_policy === payload.data_egress_policy
      && canonical(value.routes) === canonical(payload.routes)
  }
  function profilePayload(value: SandAiCapabilityProfile): CapabilitySavePayload {
    return { environment_id: value.environment_id, expected_revision: value.revision,
      name: value.name, template_code: value.template_code,
      data_egress_policy: value.data_egress_policy, routes: (value.routes ?? []).map(route => ({
        capability_code: route.capability_code, route_kind: route.route_kind, deployment_id: route.deployment_id,
        driver_code: route.driver_code, priority: route.priority, config: {}
      })) }
  }
  function confirmPending(value: SandAiCapabilityProfile | null, ownWriteRead = false): boolean {
    const operation = pending.value
    if (!operation || !value || (writeInFlight.value && !ownWriteRead) || operation.admin_id !== adminId.value
      || operation.application_id !== applicationId.value || operation.environment_id !== environmentId.value
      || !sameConfiguration(value, operation.payload)) return !operation
    const confirmed = operation.action === 'publish'
      ? value.id === operation.id && value.revision === operation.revision && value.state === 'published' && value.status === 1
      : value.revision === operation.previousRevision + 1 && (value.state === 'draft' || value.state === 'published')
        && (!operation.id || (value.id === operation.id && value.revision === operation.revision))
    if (confirmed) clearIntent()
    return confirmed
  }
  function applyProfileRead(value: SandAiCapabilityProfile | null, ownWriteRead = false): void {
    const confirmed = confirmPending(value, ownWriteRead)
    profile.value = value
    profileLoaded.value = true
    if (!confirmed) return // Keep the attempted draft while its write outcome remains unconfirmed.
    if (value) {
      form.name = value.name
      form.template_code = value.template_code ?? ''
      form.data_egress_policy = value.data_egress_policy
      form.routes = toDraftRoutes(value.routes)
    } else {
      form.name = 'default-capability'
      form.template_code = 'local_development'
      applyTemplate('local_development')
    }
  }
  function adoptLatestProfile(): void {
    if (!revisionConflict.value || !profileLoaded.value || pending.value) return
    revisionConflict.value = false
    applyProfileRead(profile.value)
    errorMessage.value = ''
  }
  function definitelyRejected(error: unknown): boolean {
    // Only the frozen transactional resource-conflict protocol proves this write did not commit.
    return isRevisionConflict(error)
  }
  function isRevisionConflict(error: unknown): boolean {
    return error instanceof HttpError && /^SAND_AI_RESOURCE_CONFLICT(?=:)/.test(error.message)
  }
  function writeFailure(error: unknown, phase: 'preflight' | 'write' | 'confirm'): string {
    if (phase === 'preflight') return '无法确认当前完整配置，写操作已锁定。请成功重新读取状态后再继续。'
    if (phase === 'write' && isRevisionConflict(error))
      return '配置已被其他管理员修改。本地表单仍保留；请重新加载并比较最新版本。'
    if (phase === 'write' && definitelyRejected(error)) return '操作被拒绝，请核对权限、模型能力和发送策略，再重新读取状态。'
    return '结果尚未确认。请重新读取状态核对同一配置；不会自动重发，请不要重复保存或发布。'
  }
  function routeKindOf(capabilityCode: string): SandAiCapabilityRoute['route_kind'] {
    const meta = metadata.value?.capabilities[capabilityCode]
    const kind = isRecord(meta) ? meta.route_kind : undefined
    return kind === 'model_deployment' || kind === 'audio_driver' ? kind : 'parse_driver'
  }

  function driverCodes(capabilityCode: string): string[] {
    const meta = metadata.value?.capabilities[capabilityCode]
    if (!isRecord(meta) || !isRecord(meta.drivers)) return []
    return Object.keys(meta.drivers)
  }

  function isLocalOcr(row: RouteDraft): boolean {
    return row.route_kind === 'parse_driver' && row.capability_code === 'image_ocr' && row.driver_code === 'local_paddle_ocr'
  }
  function usesDeployment(row: RouteDraft): boolean {
    return row.route_kind === 'model_deployment' || row.route_kind === 'audio_driver' || isLocalOcr(row)
  }

  async function searchDeployments(query: string, row: RouteDraft): Promise<void> {
    const capability = metadata.value?.capabilities[row.capability_code]
    const types = isRecord(capability) ? capability.model_types : undefined
    const modelType = isLocalOcr(row) ? 'image_ocr' : Array.isArray(types) && typeof types[0] === 'string' ? types[0] : null
    if (!modelType || !usesDeployment(row) || (isLocalOcr(row) && !environmentId.value)) return
    const current = ++deploymentGeneration
    const epoch = identityEpoch
    deploymentsLoading.value = true
    try {
      const page = await onboardingApi.publishedDeployments(modelType, query.trim(), row.capability_code, environmentId.value,
        isLocalOcr(row) ? 'ocr_route_configuration' : undefined)
      if (current !== deploymentGeneration || epoch !== identityEpoch) return
      deploymentOptions.value = page.data.filter(item => item.model_type === modelType && item.publication_status === 'published')
      if (page.total > page.per_page && !query.trim()) {
        ElMessage.info('已发布部署超过 100 项，请输入模型或服务商名称搜索')
      }
    } catch {
      if (current === deploymentGeneration && epoch === identityEpoch) errorMessage.value = '读取已发布部署失败，请检查后台权限'
    } finally {
      if (current === deploymentGeneration && epoch === identityEpoch) deploymentsLoading.value = false
    }
  }

  function syncRouteKind(row: RouteDraft): void {
    row.route_kind = routeKindOf(row.capability_code)
    row.deployment_id = undefined
    if (row.route_kind === 'model_deployment') {
      row.driver_code = null
    } else if (row.route_kind === 'audio_driver') {
      const drivers = driverCodes(row.capability_code)
      row.driver_code = drivers[0] ?? null
    } else {
      const drivers = driverCodes(row.capability_code)
      row.driver_code = drivers[0] ?? null
    }
  }

  function toDraftRoutes(routes: SandAiCapabilityRoute[] | undefined): RouteDraft[] {
    if (!routes) return []
    return routes.map((route) => ({
      capability_code: route.capability_code,
      route_kind: route.route_kind,
      deployment_id: route.deployment_id ?? undefined,
      driver_code: route.driver_code ?? null,
      priority: route.priority
    }))
  }

  function addRoute(): void {
    const code = capabilityCodes.value[0] ?? 'document_parse'
    const draft: RouteDraft = {
      capability_code: code,
      route_kind: routeKindOf(code),
      deployment_id: undefined,
      driver_code: null,
      priority: 100 + form.routes.length
    }
    syncRouteKind(draft)
    form.routes.push(draft)
  }

  function prepareLocalTextRoute(): void {
    if (form.routes.some(route => route.capability_code === 'document_parse')) {
      ElMessage.info('已配置文档解析，请核对该行的解析工具后保存')
      return
    }
    if (!driverCodes('document_parse').includes('native_document_parse')) {
      ElMessage.warning('本地文档解析工具暂不可用，请重新读取能力目录')
      return
    }
    form.routes.push({ capability_code: 'document_parse', route_kind: 'parse_driver',
      deployment_id: undefined, driver_code: 'native_document_parse', priority: 100 })
    ElMessage.info('已添加本地文本解析；请保存并发布能力配置')
  }

  function removeRoute(index: number): void {
    form.routes.splice(index, 1)
  }

  function prepareMaterialRoutes(): void {
    const chat = form.routes.find(route => route.capability_code === 'chat'
      && route.route_kind === 'model_deployment' && Number.isInteger(route.deployment_id)
      && (route.deployment_id ?? 0) > 0)
    if (!chat?.deployment_id) {
      ElMessage.warning('请先给 chat 路由选择已发布的聊天模型部署')
      return
    }
    if (!form.routes.some(route => route.capability_code === 'structured_extraction')) {
      form.routes.push({
        capability_code: 'structured_extraction',
        route_kind: 'model_deployment',
        deployment_id: chat.deployment_id,
        driver_code: null,
        priority: 100
      })
    }
    if (!form.routes.some(route => route.capability_code === 'document_parse')) {
      form.routes.push({
        capability_code: 'document_parse',
        route_kind: 'parse_driver',
        deployment_id: undefined,
        driver_code: 'native_document_parse',
        priority: 100
      })
    }
    ElMessage.info('已补齐本地文档解析和结构化抽取路由；请核对外发策略，再保存并发布配置档')
  }

  function applyTemplate(code: string | number | boolean | undefined): void {
    if (typeof code !== 'string' || !code || !metadata.value) return
    const tpl = metadata.value.templates[code]
    if (!tpl) return
    form.data_egress_policy = tpl.data_egress_policy
    form.routes = toDraftRoutes(tpl.default_routes)
    if (form.routes.length === 0) {
      addRoute()
    }
  }

  async function loadApps(): Promise<void> {
    const current = ++applicationGeneration
    loadingApps.value = true
    if (iamListError.value === 'applications') errorMessage.value = ''
    iamListError.value = ''
    try {
      const rows = await iamApi.activeApplications()
      if (current !== applicationGeneration) return
      applications.value = rows
    } catch (error) {
      if (current === applicationGeneration) {
        applications.value = []
        iamListError.value = 'applications'
        errorMessage.value = error instanceof Error ? error.message : '读取应用失败，请重试'
      }
    } finally {
      if (current === applicationGeneration) loadingApps.value = false
    }
  }

  async function loadMetadata(): Promise<void> {
    const epoch = identityEpoch
    try {
      const result = await capabilityApi.metadata()
      if (epoch === identityEpoch) metadata.value = result
    } catch (error) {
      if (epoch !== identityEpoch) return
      errorMessage.value = error instanceof Error ? error.message : '加载能力元数据失败'
    }
  }

  async function onApplicationChange(): Promise<void> {
    const current = ++environmentGeneration
    environmentId.value = undefined
    environments.value = []
    loadingEnvs.value = false
    if (iamListError.value === 'environments') errorMessage.value = ''
    iamListError.value = ''
    profileGeneration++
    revisionConflict.value = false
    profileLoaded.value = false
    profile.value = null
    form.routes = []
    if (!applicationId.value) return
    const selectedApplication = applicationId.value
    loadingEnvs.value = true
    try {
      const rows = await iamApi.activeEnvironments(selectedApplication)
      if (current !== environmentGeneration || selectedApplication !== applicationId.value) return
      environments.value = rows
    } catch (error) {
      if (current === environmentGeneration && selectedApplication === applicationId.value) {
        iamListError.value = 'environments'
        errorMessage.value = error instanceof Error ? error.message : '读取环境失败，请重试'
      }
    } finally {
      if (current === environmentGeneration) loadingEnvs.value = false
    }
  }

  async function reloadProfile(): Promise<void> {
    if (!environmentId.value || busy.value || writeInFlight.value || journalError.value) return
    if (pending.value && (pending.value.admin_id !== adminId.value || pending.value.application_id !== applicationId.value
      || pending.value.environment_id !== environmentId.value)) { errorMessage.value = '请回到原操作应用和环境读取状态'; return }
    const targetEnvironment = environmentId.value
    const current = ++profileGeneration
    const epoch = identityEpoch
    profileLoaded.value = false
    busy.value = 'load'
    errorMessage.value = ''
    try {
      const response = await capabilityApi.readProfile(targetEnvironment)
      if (current !== profileGeneration || epoch !== identityEpoch || targetEnvironment !== environmentId.value) return
      const latest = readProfileResponse(response, targetEnvironment)
      if (revisionConflict.value) {
        profile.value = latest
        profileLoaded.value = true
      } else {
        applyProfileRead(latest)
      }
      if (pending.value) errorMessage.value = '先前操作仍未确认。请核查同一环境的配置；不会重新发送写请求。'
    } catch {
      if (current === profileGeneration && epoch === identityEpoch && targetEnvironment === environmentId.value) errorMessage.value = '无法读取完整配置，写操作保持锁定。请检查权限或连接后重新加载。'
    } finally {
      if (current === profileGeneration && epoch === identityEpoch && targetEnvironment === environmentId.value) busy.value = ''
    }
  }

  async function onEnvironmentChange(): Promise<void> {
    await reloadProfile()
  }

  async function handleSave(): Promise<void> {
    if (editBlocked.value || busy.value) { ElMessage.warning('请先读取可安全编辑的完整配置。'); return }
    if (!applicationId.value || !environmentId.value) {
      ElMessage.warning('请先选择环境')
      return
    }
    if (!form.name.trim() || form.routes.length === 0) {
      ElMessage.warning('请填写名称并至少配置一条路由')
      return
    }
    const incomplete = form.routes.find(route =>
      ((route.route_kind === 'model_deployment' || route.route_kind === 'audio_driver')
        && (!Number.isInteger(route.deployment_id) || (route.deployment_id ?? 0) <= 0))
      || ((route.route_kind === 'parse_driver' || route.route_kind === 'audio_driver')
        && !route.driver_code)
    )
    if (incomplete) {
      ElMessage.warning(`请补全 ${incomplete.capability_code} 的部署 ID 与驱动`)
      return
    }
    const targetEnvironment = environmentId.value
    const baseline = JSON.stringify(profile.value)
    const previousRevision = profile.value?.revision ?? 0
    const payload: CapabilitySavePayload = {
      environment_id: targetEnvironment, expected_revision: profile.value?.revision ?? null,
      name: form.name.trim(), template_code: form.template_code || null,
      data_egress_policy: form.data_egress_policy,
      routes: form.routes.map(route => ({ capability_code: route.capability_code, route_kind: route.route_kind,
        deployment_id: usesDeployment(route) ? route.deployment_id ?? null : null,
        driver_code: route.route_kind === 'parse_driver' || route.route_kind === 'audio_driver' ? route.driver_code : null,
        priority: route.priority, config: {} }))
    }
    const current = ++profileGeneration
    const epoch = identityEpoch
    const owner = adminId.value
    const targetApplication = applicationId.value
    if (!targetApplication) return
    const ownsOperation = () => current === profileGeneration && epoch === identityEpoch && owner === adminId.value && targetApplication === applicationId.value && targetEnvironment === environmentId.value
    const flight = ++writeFlightSerial
    writeInFlight.value = true
    busy.value = 'save'
    profileLoaded.value = false
    errorMessage.value = ''
    let phase: 'preflight' | 'write' | 'confirm' = 'preflight'
    try {
      const preflight = await capabilityApi.readProfile(targetEnvironment)
      if (!ownsOperation()) return
      const latest = readProfileResponse(preflight, targetEnvironment)
      if (JSON.stringify(latest) !== baseline) {
        profile.value = latest
        profileLoaded.value = true
        revisionConflict.value = true
        errorMessage.value = '配置已经变化，本地表单仍保留。请比较最新版本后再保存。'
        return
      }
      persistIntent({ version: 1, admin_id: owner, application_id: targetApplication, environment_id: targetEnvironment, action: 'save', payload, previousRevision })
      phase = 'write'
      const response = await capabilityApi.saveProfile(payload)
      if (!ownsOperation()) return
      phase = 'confirm'
      const saved = writtenProfileResponse(response, targetEnvironment)
      if (!sameConfiguration(saved, payload) || saved.revision !== previousRevision + 1) throw new Error('Unexpected save response')
      const operation = pending.value
      if (!operation) throw new Error('原保存记录缺失')
      persistIntent({ ...operation, id: saved.id, revision: saved.revision }, operation)
      const reread = await capabilityApi.readProfile(targetEnvironment)
      if (!ownsOperation()) return
      applyProfileRead(readProfileResponse(reread, targetEnvironment), true)
      if (pending.value) errorMessage.value = '保存结果尚未确认，请重新读取状态；不会重发。'
      else ElMessage.success(`已重新读取并确认配置 revision=${profile.value?.revision}`)
    } catch (error) {
      if (!ownsOperation()) return
      if (phase === 'write' && definitelyRejected(error)) {
        try { clearIntent() } catch { errorMessage.value = '原记录未解除，结果保持待确认；请重新读取原目标'; return }
      }
      if (phase === 'write' && isRevisionConflict(error)) revisionConflict.value = true
      profileLoaded.value = false
      errorMessage.value = writeFailure(error, phase)
    } finally {
      if (flight === writeFlightSerial) writeInFlight.value = false
      if (ownsOperation()) busy.value = ''
    }
  }

  async function handlePublish(): Promise<void> {
    if (editBlocked.value || busy.value || !applicationId.value || !environmentId.value || !profile.value || profile.value.state !== 'draft') return
    const targetEnvironment = environmentId.value
    const target = profile.value
    const baseline = JSON.stringify(target)
    const payload = profilePayload(target)
    const formSnapshot = JSON.stringify(form)
    const displayedPayload: CapabilitySavePayload = {
      ...payload, name: form.name.trim(), template_code: form.template_code || null,
      data_egress_policy: form.data_egress_policy,
      routes: form.routes.map(route => ({
        capability_code: route.capability_code, route_kind: route.route_kind,
        deployment_id: usesDeployment(route) ? route.deployment_id ?? null : null,
        driver_code: route.route_kind === 'parse_driver' || route.route_kind === 'audio_driver' ? route.driver_code : null,
        priority: route.priority, config: {}
      }))
    }
    if (!sameConfiguration(target, displayedPayload)) {
      ElMessage.warning('表单有未保存的修改。请先保存草稿，再核对已保存修订后发布。')
      return
    }
    const current = ++profileGeneration
    const epoch = identityEpoch
    const owner = adminId.value
    const targetApplication = applicationId.value
    if (!targetApplication) return
    const ownsOperation = () => current === profileGeneration && epoch === identityEpoch && owner === adminId.value && targetApplication === applicationId.value && targetEnvironment === environmentId.value
    const flight = ++writeFlightSerial
    writeInFlight.value = true
    busy.value = 'publish'
    profileLoaded.value = false
    errorMessage.value = ''
    let phase: 'preflight' | 'write' | 'confirm' = 'preflight'
    try {
      try {
        await ElMessageBox.confirm(
          `确认发布应用 ${targetApplication}、环境 ${targetEnvironment} 的能力档 ${target.id} · 修订 ${target.revision}（草稿）？名称：${payload.name}；模板：${payload.template_code || '未指定'}；材料发送策略：${payload.data_egress_policy}；路线：${payload.routes.map(route => `${route.capability_code} / ${route.route_kind} / ${route.deployment_id ?? route.driver_code ?? '未设置'} / 优先顺序 ${route.priority}`).join('、')}。发布仅使用这份已保存配置。`,
          '确认发布能力配置', { type: 'warning', confirmButtonText: '发布此修订', cancelButtonText: '取消' }
        )
      } catch {
        if (ownsOperation()) profileLoaded.value = true
        return
      }
      if (!ownsOperation()) return
      if (JSON.stringify(form) !== formSnapshot || JSON.stringify(profile.value) !== baseline) {
        profileLoaded.value = true
        errorMessage.value = '确认期间配置或表单已变化，尚未发布。请重新核对已保存修订。'
        return
      }
      const preflight = await capabilityApi.readProfile(targetEnvironment)
      if (!ownsOperation()) return
      const latest = readProfileResponse(preflight, targetEnvironment)
      if (JSON.stringify(form) !== formSnapshot || JSON.stringify(profile.value) !== baseline || JSON.stringify(latest) !== baseline) {
        applyProfileRead(latest)
        errorMessage.value = '配置已经变化，已重新读取。请核对后再发布。'
        return
      }
      persistIntent({ version: 1, admin_id: owner, application_id: targetApplication, environment_id: targetEnvironment, action: 'publish', payload, previousRevision: target.revision, id: target.id, revision: target.revision })
      phase = 'write'
      const response = await capabilityApi.publishProfile({ environment_id: targetEnvironment, id: target.id, expected_revision: target.revision })
      if (!ownsOperation()) return
      phase = 'confirm'
      const published = writtenProfileResponse(response, targetEnvironment)
      if (published.id !== target.id || published.revision !== target.revision || published.state !== 'published' || published.status !== 1 || !sameConfiguration(published, payload)) throw new Error('Unexpected publish response')
      const reread = await capabilityApi.readProfile(targetEnvironment)
      if (!ownsOperation()) return
      applyProfileRead(readProfileResponse(reread, targetEnvironment), true)
      if (pending.value) errorMessage.value = '发布结果尚未确认，请重新读取状态；不会重发。'
      else ElMessage.success('已重新读取并确认能力配置档发布')
    } catch (error) {
      if (!ownsOperation()) return
      if (phase === 'write' && definitelyRejected(error)) {
        try { clearIntent() } catch { errorMessage.value = '原记录未解除，结果保持待确认；请重新读取原目标'; return }
      }
      profileLoaded.value = false
      errorMessage.value = writeFailure(error, phase)
    } finally {
      if (flight === writeFlightSerial) writeInFlight.value = false
      if (ownsOperation()) busy.value = ''
    }
  }

  function returnToMaterialGuide(): void {
    if (busy.value || writeInFlight.value || restoringEntry) return
    const application = route.query.guided_application_id
    const environment = route.query.guided_environment_id
    const code = route.query.guided_profile_code
    const version = route.query.guided_profile_version
    const positive = (value: unknown): value is string => typeof value === 'string'
      && /^[1-9][0-9]*$/.test(value) && Number.isSafeInteger(Number(value))
    if (route.query.configuration_origin !== 'material_guide' || !positive(application) || !positive(environment)
      || ((code !== undefined || version !== undefined) && (typeof code !== 'string'
        || !/^[a-z][a-z0-9_]{0,63}$/.test(code) || typeof version !== 'string'
        || version.length > 32 || !/^\d+\.\d+\.\d+$/.test(version)))) {
      navigationNotice.value = '原引导配置标识不完整，请从材料整理引导重新选择原应用、环境和配置。'
      return
    }
    const query: Record<string, string> = { configuration_return: 'material_guide', application_id: application, environment_id: environment }
    if (typeof code === 'string' && typeof version === 'string') {
      query.material_profile_code = code; query.material_profile_version = version
      const run = route.query.guided_run_id
      if (run !== undefined && (!positive(run))) {
        navigationNotice.value = '原样本标识无效，请从原材料配置或运行历史找回。'
        return
      }
      if (typeof run === 'string') query.material_run_id = run
    }
    // The fixed destination reloads current IAM references and the exact profile.
    void router.push({ path: '/sand-ai/onboarding', query })
  }

  async function loadEntryContext(): Promise<void> {
    const epoch = identityEpoch
    await loadApps()
    if (epoch !== identityEpoch || iamListError.value) return
    if (journalError.value) return
    if (!pending.value && (typeof route.query.application_id !== 'string' || typeof route.query.environment_id !== 'string')) return
    const application = pending.value?.application_id ?? Number(route.query.application_id)
    const environment = pending.value?.environment_id ?? Number(route.query.environment_id)
    if (!Number.isSafeInteger(application) || !Number.isSafeInteger(environment) || application < 1 || environment < 1) return
    if (!applications.value.some(item => item.id === application)) {
      errorMessage.value = '入口应用已不可用，请重新选择应用和环境。'
      return
    }
    applicationId.value = application
    await onApplicationChange()
    if (epoch !== identityEpoch || iamListError.value) return
    // Recheck IAM references; a query string does not confer environment access.
    if (applicationId.value !== application || !environments.value.some(item => item.id === environment)) {
      errorMessage.value = '入口环境已不可用或不属于该应用，请重新选择。'
      return
    }
    environmentId.value = environment
    const operation = pending.value
    if (operation) {
      form.name = operation.payload.name
      form.template_code = operation.payload.template_code ?? ''
      form.data_egress_policy = operation.payload.data_egress_policy ?? 'local_only'
      form.routes = toDraftRoutes(operation.payload.routes)
    }
    await reloadProfile()
  }

  watch([applicationId, environmentId], () => {
    profileGeneration++
    profileLoaded.value = false
    profile.value = null
    revisionConflict.value = false
    busy.value = ''
  }, { flush: 'sync' })
  watch([applicationId, environmentId], () => {
    if (
      restoringEntry ||
      !applicationId.value ||
      !environmentId.value ||
      !applications.value.some((item) => item.id === applicationId.value) ||
      !environments.value.some((item) => item.id === environmentId.value)
    )
      return
    const query = { ...route.query }
    if (
      query.application_id !== String(applicationId.value) ||
      query.environment_id !== String(environmentId.value)
    ) {
      delete query.material_profile_code
      delete query.material_profile_version
      delete query.material_run_id
    }
    query.application_id = String(applicationId.value)
    query.environment_id = String(environmentId.value)
    void router.replace({ query })
  })
  async function focusChatTrial(): Promise<void> {
    if (restoringEntry || route.path !== '/sand-ai/capability' || route.hash !== '#chat-route-trial'
      || iamListError.value || !applicationId.value || !environmentId.value
      || route.query.application_id !== String(applicationId.value)
      || route.query.environment_id !== String(environmentId.value)
      || !applications.value.some(item => item.id === applicationId.value)
      || !environments.value.some(item => item.id === environmentId.value)) return
    const application = applicationId.value
    const environment = environmentId.value
    await nextTick()
    if (restoringEntry || iamListError.value || applicationId.value !== application || environmentId.value !== environment
      || route.path !== '/sand-ai/capability' || route.hash !== '#chat-route-trial'
      || route.query.application_id !== String(application) || route.query.environment_id !== String(environment)) return
    chatTrialSection.value?.focus({ preventScroll: true })
    chatTrialSection.value?.scrollIntoView({ block: 'start' })
  }

  watch(() => route.hash, () => { void focusChatTrial() }, { flush: 'post' })
  onBeforeUnmount(() => {
    identityEpoch++; profileGeneration++; applicationGeneration++; environmentGeneration++; deploymentGeneration++
    form.name = 'default-capability'; form.template_code = ''; form.data_egress_policy = 'local_only'; form.routes = []
    profile.value = null; deploymentOptions.value = []
  })

  watch([adminId, () => userStore.accessToken], () => {
    identityEpoch++; profileGeneration++; applicationGeneration++; environmentGeneration++; deploymentGeneration++
    busy.value = ''; applicationId.value = undefined; environmentId.value = undefined
    applications.value = []; environments.value = []; profile.value = null; profileLoaded.value = false
    metadata.value = null; deploymentOptions.value = []; deploymentsLoading.value = false
    loadingApps.value = false; loadingEnvs.value = false; iamListError.value = ''; revisionConflict.value = false
    form.name = 'default-capability'; form.template_code = ''; form.data_egress_policy = 'local_only'; form.routes = []
    errorMessage.value = ''
    restoreIntent()
    void loadMetadata()
    void loadEntryContext()
  }, { flush: 'sync' })
  onMounted(() => {
    restoreIntent()
    void loadEntryContext().finally(() => {
      restoringEntry = false
      entryInitialized = true
      void focusChatTrial()
    })
    void loadMetadata()
  })
  onActivated(() => {
    if (!entryInitialized || route.path !== '/sand-ai/capability' || writeInFlight.value
      || (route.hash !== '#chat-route-trial' && route.query.configuration_origin !== 'material_guide')) return
    restoringEntry = true
    void loadEntryContext().finally(() => {
      restoringEntry = false
      void focusChatTrial()
    })
  })
</script>

<style scoped lang="scss">
  .cap-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 16px;
  }

  .cap-title {
    margin: 0;
    font-size: 18px;
    font-weight: 600;
  }

  .cap-desc {
    margin: 4px 0 0;
    font-size: 13px;
    color: var(--el-text-color-secondary);
    max-width: 760px;
  }

  .cap-form {
    margin-bottom: 8px;
  }

  .cap-alert {
    margin-bottom: 12px;
  }

  .cap-routes-toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin: 12px 0;
  }

  .cap-route-actions {
    min-width: 0;
    max-width: 100%;
  }

  .cap-section-title {
    flex-shrink: 0;
    margin: 0;
    font-size: 15px;
    font-weight: 600;
  }

  .cap-actions {
    margin-top: 16px;
  }

  .cap-muted {
    color: var(--el-text-color-secondary);
    font-size: 13px;
  }

  .cap-number {
    width: 100%;
  }

  .cap-deployment {
    width: 100%;
  }
</style>
