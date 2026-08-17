<template>
  <div class="art-full-height">
    <ElCard class="art-table-card" shadow="never">
      <div class="cap-header">
        <div>
          <h2 class="cap-title">能力配置档</h2>
          <p class="cap-desc">
            消费契约 1.5：按环境选择模型部署、解析驱动与数据外发策略。不返回也不编辑任何密钥。
          </p>
        </div>
        <ElTag type="success" effect="plain">API 1.5</ElTag>
      </div>

      <ElForm label-width="120px" class="cap-form" @submit.prevent>
        <ElFormItem label="应用">
          <ElSelect
            v-model="applicationId"
            filterable
            clearable
            placeholder="选择应用"
            style="width: 360px"
            :loading="loadingApps"
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
            :disabled="!applicationId"
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

      <template v-if="environmentId">
        <ElForm label-width="120px" class="cap-form" @submit.prevent>
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
          <ElFormItem label="外发策略">
            <ElSelect v-model="form.data_egress_policy" style="width: 360px">
              <ElOption label="local_only" value="local_only" />
              <ElOption label="private_only" value="private_only" />
              <ElOption label="approved_cloud" value="approved_cloud" />
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
          <h3 class="cap-section-title">路由</h3>
          <ElButton size="small" @click="addRoute">添加路由</ElButton>
        </div>

        <ElTable :data="form.routes" border empty-text="暂无路由">
          <ElTableColumn label="capability" min-width="160">
            <template #default="{ row }">
              <ElSelect
                v-model="row.capability_code"
                filterable
                @change="() => syncRouteKind(row)"
              >
                <ElOption
                  v-for="code in capabilityCodes"
                  :key="code"
                  :label="code"
                  :value="code"
                />
              </ElSelect>
            </template>
          </ElTableColumn>
          <ElTableColumn prop="route_kind" label="route_kind" width="150" />
          <ElTableColumn label="deployment_id" width="140">
            <template #default="{ row }">
              <ElInputNumber
                v-if="row.route_kind === 'model_deployment'"
                v-model="row.deployment_id"
                :min="1"
                :controls="false"
                class="cap-number"
              />
              <span v-else class="cap-muted">—</span>
            </template>
          </ElTableColumn>
          <ElTableColumn label="driver_code" min-width="180">
            <template #default="{ row }">
              <ElSelect
                v-if="row.route_kind === 'parse_driver'"
                v-model="row.driver_code"
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
          <ElTableColumn label="priority" width="110">
            <template #default="{ row }">
              <ElInputNumber v-model="row.priority" :min="1" :controls="false" class="cap-number" />
            </template>
          </ElTableColumn>
          <ElTableColumn label="操作" width="90">
            <template #default="{ $index }">
              <ElButton link type="danger" @click="removeRoute($index)">删除</ElButton>
            </template>
          </ElTableColumn>
        </ElTable>

        <ElSpace wrap class="cap-actions">
          <ElButton
            v-permission="'sand_ai:setup:capability:save'"
            type="primary"
            :loading="busy === 'save'"
            @click="handleSave"
          >
            保存草稿
          </ElButton>
          <ElButton
            v-permission="'sand_ai:setup:capability:publish'"
            type="success"
            :disabled="!profile || profile.state === 'published'"
            :loading="busy === 'publish'"
            @click="handlePublish"
          >
            发布配置档
          </ElButton>
          <ElButton :loading="busy === 'load'" @click="reloadProfile">重新加载</ElButton>
        </ElSpace>
      </template>
    </ElCard>
  </div>
</template>

<script setup lang="ts">
  import { computed, onMounted, reactive, ref } from 'vue'
  import { ElMessage } from 'element-plus'
  import iamApi, {
    type SandIamApplicationReference,
    type SandIamEnvironmentReference
  } from '../api/iam'
  import capabilityApi, {
    type SandAiCapabilityMetadata,
    type SandAiCapabilityProfile,
    type SandAiCapabilityRoute,
    type SandAiDataEgressPolicy
  } from '../api/capability'

  type RouteDraft = {
    capability_code: string
    route_kind: string
    deployment_id: number | undefined
    driver_code: string | null
    priority: number
  }

  const loadingApps = ref(false)
  const loadingEnvs = ref(false)
  const busy = ref<'load' | 'save' | 'publish' | ''>('')
  const errorMessage = ref('')
  const applications = ref<SandIamApplicationReference[]>([])
  const environments = ref<SandIamEnvironmentReference[]>([])
  const metadata = ref<SandAiCapabilityMetadata | null>(null)
  const profile = ref<SandAiCapabilityProfile | null>(null)
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

  function routeKindOf(capabilityCode: string): string {
    const meta = metadata.value?.capabilities[capabilityCode]
    if (!isRecord(meta) || typeof meta.route_kind !== 'string') return 'parse_driver'
    return meta.route_kind
  }

  function driverCodes(capabilityCode: string): string[] {
    const meta = metadata.value?.capabilities[capabilityCode]
    if (!isRecord(meta) || !isRecord(meta.drivers)) return []
    return Object.keys(meta.drivers)
  }

  function syncRouteKind(row: RouteDraft): void {
    row.route_kind = routeKindOf(row.capability_code)
    if (row.route_kind === 'model_deployment') {
      row.driver_code = null
    } else {
      row.deployment_id = undefined
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

  function removeRoute(index: number): void {
    form.routes.splice(index, 1)
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
    loadingApps.value = true
    try {
      const page = await iamApi.listApplications({ page: 1, limit: 100 })
      applications.value = page.data.filter((application) => application.status === 1)
    } catch (error) {
      errorMessage.value = error instanceof Error ? error.message : '加载应用失败'
    } finally {
      loadingApps.value = false
    }
  }

  async function loadMetadata(): Promise<void> {
    try {
      metadata.value = await capabilityApi.metadata()
    } catch (error) {
      errorMessage.value = error instanceof Error ? error.message : '加载能力元数据失败'
    }
  }

  async function onApplicationChange(): Promise<void> {
    environmentId.value = undefined
    environments.value = []
    profile.value = null
    form.routes = []
    if (!applicationId.value) return
    loadingEnvs.value = true
    try {
      const page = await iamApi.listEnvironments({ page: 1, limit: 100 })
      environments.value = page.data.filter(
        (environment) => environment.application_id === applicationId.value && environment.status === 1
      )
    } catch (error) {
      errorMessage.value = error instanceof Error ? error.message : '加载环境失败'
    } finally {
      loadingEnvs.value = false
    }
  }

  async function reloadProfile(): Promise<void> {
    if (!environmentId.value) return
    busy.value = 'load'
    errorMessage.value = ''
    try {
      const result = await capabilityApi.readProfile(environmentId.value)
      profile.value = result.profile
      if (result.profile) {
        form.name = result.profile.name
        form.template_code = result.profile.template_code ?? ''
        form.data_egress_policy = result.profile.data_egress_policy
        form.routes = toDraftRoutes(result.profile.routes)
      } else {
        form.name = 'default-capability'
        form.template_code = 'local_development'
        applyTemplate('local_development')
      }
    } catch (error) {
      errorMessage.value = error instanceof Error ? error.message : '读取配置档失败'
    } finally {
      busy.value = ''
    }
  }

  async function onEnvironmentChange(): Promise<void> {
    await reloadProfile()
  }

  async function handleSave(): Promise<void> {
    if (!environmentId.value) {
      ElMessage.warning('请先选择环境')
      return
    }
    if (!form.name.trim() || form.routes.length === 0) {
      ElMessage.warning('请填写名称并至少配置一条路由')
      return
    }
    busy.value = 'save'
    errorMessage.value = ''
    try {
      const result = await capabilityApi.saveProfile({
        environment_id: environmentId.value,
        name: form.name.trim(),
        template_code: form.template_code || null,
        data_egress_policy: form.data_egress_policy,
        routes: form.routes.map((route) => ({
          capability_code: route.capability_code,
          route_kind: route.route_kind,
          deployment_id: route.route_kind === 'model_deployment' ? route.deployment_id ?? null : null,
          driver_code: route.route_kind === 'parse_driver' ? route.driver_code : null,
          priority: route.priority,
          config: {}
        }))
      })
      profile.value = result.profile
      form.routes = toDraftRoutes(result.profile.routes)
      ElMessage.success(`草稿已保存 revision=${result.profile.revision}`)
    } catch (error) {
      errorMessage.value = error instanceof Error ? error.message : '保存失败'
    } finally {
      busy.value = ''
    }
  }

  async function handlePublish(): Promise<void> {
    if (!environmentId.value || !profile.value) {
      ElMessage.warning('请先保存草稿')
      return
    }
    busy.value = 'publish'
    errorMessage.value = ''
    try {
      const result = await capabilityApi.publishProfile({
        environment_id: environmentId.value,
        id: profile.value.id
      })
      profile.value = result.profile
      ElMessage.success('能力配置档已发布')
    } catch (error) {
      errorMessage.value = error instanceof Error ? error.message : '发布失败'
    } finally {
      busy.value = ''
    }
  }

  onMounted(() => {
    void loadApps()
    void loadMetadata()
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
    align-items: center;
    justify-content: space-between;
    margin: 12px 0;
  }

  .cap-section-title {
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
</style>
