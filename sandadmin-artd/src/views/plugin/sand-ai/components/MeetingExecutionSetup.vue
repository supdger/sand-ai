<template>
  <div class="meeting-execution">
    <h3>会议执行设置</h3>
    <p>
      为目标环境核定会议摘要模型的真实容量，再明确启用。保存设置不会调用模型、分词服务或访问依据网址。
    </p>
    <p v-if="!environmentId">先在上方工作目标中选择应用和环境。</p>
    <template v-else>
      <p>目标：{{ applicationName }} / {{ environmentName }}</p>
      <ElAlert
        v-if="!canRead"
        title="没有读取会议执行设置的权限，请联系平台管理员。"
        type="warning"
        :closable="false"
        show-icon
      />
      <template v-else>
        <ElAlert v-if="notice" :title="notice" :type="noticeType" :closable="false" show-icon />
        <ElSkeleton v-if="busy === 'read'" :rows="3" animated />
        <ElSpace wrap class="actions">
          <ElButton :loading="busy === 'read'" :disabled="Boolean(busy)" @click="refresh"
            >重新读取设置</ElButton
          >
          <ElButton v-if="loaded && !routes.length" @click="emit('configure')"
            >配置并发布模型与能力路由</ElButton
          >
        </ElSpace>
        <p v-if="loaded && !routes.length">
          此环境还没有已发布的聊天能力路由。先发布模型连接，并在能力配置中发布目标环境的聊天路由，再回来读取。
        </p>
        <ElForm v-if="loaded && routes.length" label-width="180px" @submit.prevent>
          <ElFormItem label="已发布模型路线">
            <ElSelect
              v-model="model"
              :disabled="Boolean(busy)"
              placeholder="选择本环境当前已发布的模型"
              @change="selectModel"
            >
              <ElOption
                v-for="route in routes"
                :key="route.model"
                :label="route.display_name"
                :value="route.model"
              />
            </ElSelect>
          </ElFormItem>
          <template v-if="selection">
            <p>
              当前设置：{{ stateLabels[selection.state] }} · 模型部署修订
              {{ selection.route.deployment_revision }} · 能力档修订
              {{ selection.route.profile_revision }}
            </p>
            <p v-if="selection.registration"> 设置修订：{{ selection.registration.revision }} </p>
            <ElAlert
              :title="selection.next_action"
              :type="selection.state === 'enabled' ? 'success' : 'info'"
              :closable="false"
              show-icon
            />
            <ElAlert
              v-if="selection.state === 'stale'"
              title="模型或能力路由已变化。旧容量仅供核对，请重新核定、保存，再明确启用新版本。"
              type="warning"
              :closable="false"
            />
            <ElCollapse v-if="selection.state === 'stale' && selection.registration">
              <ElCollapseItem title="查看旧版本的核定记录" name="previous">
                <p>
                  上下文容量：{{
                    selection.registration.capacity.context_window_tokens
                  }}
                  Token；输入上限：{{
                    selection.registration.capacity.input_token_limit
                  }}
                  Token；分词请求上限：{{ selection.registration.capacity.max_tokenizer_requests }}
                  次。<span v-if="selection.registration.capacity.planning_driver">原设置使用本地 SDK 规划，不实测 Token。</span>
                </p>
                <p> 依据网址：{{ selection.registration.capacity.capacity_source_url }} </p>
                <p class="digest">
                  依据内容摘要：{{ selection.registration.capacity.capacity_source_sha256 }}
                </p>
              </ElCollapseItem>
            </ElCollapse>
            <p v-if="!hasExecutionDriver">
              该路线没有可执行的分词或本地规划驱动，无法设置或启用。请选择受支持的已发布路线，或联系管理员核查模型能力。
            </p>
            <ElSpace
              v-if="!hasExecutionDriver && selection.registration"
              wrap
              class="actions"
            >
              <ElButton
                :disabled="!canRevoke"
                :loading="busy === 'revoke'"
                @click="changeState(false)"
                >撤销会议执行</ElButton
              >
              <span v-if="!canPublish">没有撤销权限，请联系具有模型发布权限的管理员。</span>
            </ElSpace>
            <template v-if="hasExecutionDriver">
              <p>
                请依据所用服务商、模型版本和实际套餐核定容量；尚不清楚时，先向服务商取得正式说明。
              </p>
              <ElFormItem label="规划方式">
                <ElSelect v-model="draft.planning_mode" :disabled="formLocked" @change="changePlanningMode">
                  <ElOption v-if="selection.route.tokenizer_drivers.length" label="远程分词计数" value="remote" />
                  <ElOption v-if="planningDrivers.length" label="本地 SDK 文本规划（不实测 Token）" value="local" />
                </ElSelect>
              </ElFormItem>
              <ElAlert v-if="isLocal" type="info" :closable="false" title="本地规划不实测 Token，不向分词服务发送请求；这不是免费声明。模型生成仍按实际调用核对容量和费用。" />
              <p v-if="isLocal && planningDriver">SDK：{{ planningDriver.sdk_package }} {{ planningDriver.sdk_version }}；文本分区上限 {{ planningDriver.max_length }}，重叠 {{ planningDriver.word_overlap }}；Token 未测量。</p>
              <ElFormItem v-if="isLocal" label="本地规划驱动">
                <ElSelect v-model="draft.planning_driver" :disabled="formLocked" placeholder="明确选择本地驱动">
                  <ElOption v-for="item in planningDrivers" :key="item.code" :label="item.code" :value="item.code" />
                </ElSelect>
              </ElFormItem>
              <ElFormItem v-else label="分词计数驱动">
                <ElSelect
                  v-model="draft.tokenizer_driver"
                  :disabled="formLocked"
                  placeholder="选择此路线实际支持的驱动"
                >
                  <ElOption
                    v-for="driver in selection.route.tokenizer_drivers"
                    :key="driver.code"
                    :label="driver.display_name"
                    :value="driver.code"
                  />
                </ElSelect>
              </ElFormItem>
              <ElFormItem label="上下文容量（Token）">
                <ElInputNumber
                  v-model="draft.context_window_tokens"
                  :disabled="formLocked"
                  :min="2"
                  :precision="0"
                  :max="isLocal ? 1048576 : driver?.context_window_ceiling"
                  :value-on-clear="null"
                  placeholder="填写核定值"
                />
                <span v-if="driver">当前驱动安全上限：{{ driver.context_window_ceiling }}。</span>
              </ElFormItem>
              <ElFormItem label="输入上限（Token）">
                <ElInputNumber
                  v-model="draft.input_token_limit"
                  :disabled="formLocked"
                  :min="1"
                  :precision="0"
                  :value-on-clear="null"
                  placeholder="填写核定值"
                />
                <span
                  >每次输出预留 {{ selection.route.max_output_tokens }} Token。{{
                    inputCeiling === null
                      ? '请先填写上下文容量。'
                      : `输入最多 ${Math.max(0, inputCeiling)} Token。`
                  }}</span
                >
              </ElFormItem>
              <p v-if="isLocal">分词服务请求：0 次；Token 计数未测量，不作为财务计量依据。</p>
              <ElFormItem v-else label="分词请求上限（次）">
                <ElInputNumber
                  v-model="draft.max_tokenizer_requests"
                  :disabled="formLocked"
                  :min="driver?.request_limit_min"
                  :max="driver?.request_limit_max"
                  :precision="0"
                  :value-on-clear="null"
                  placeholder="填写核定值"
                />
                <span v-if="driver"
                  >允许范围：{{ driver.request_limit_min }}–{{
                    driver.request_limit_max
                  }}；失败或结果未知的发送意图也计入上限。</span
                >
              </ElFormItem>
              <ElFormItem label="官方能力依据网址">
                <ElInput
                  v-model.trim="draft.capacity_source_url"
                  :disabled="formLocked"
                  placeholder="服务商官方能力说明的 HTTPS 网址"
                />
              </ElFormItem>
              <ElFormItem label="能力依据内容的 SHA-256">
                <ElInput
                  v-model.trim="draft.capacity_source_sha256"
                  :disabled="formLocked"
                  maxlength="64"
                  placeholder="64 位小写十六进制内容摘要"
                />
              </ElFormItem>
              <p class="muted">
                保存从官方网址取得的原始文档内容，用 SHA-256
                工具计算该文件的摘要，再填写上方字段。这里登记的是文档内容摘要，不是网址的摘要；登记不代表系统已验证文档或模型容量。
              </p>
              <ElCheckbox v-model="confirmed" :disabled="formLocked"
                >我已核对当前模型版本、真实容量、所选驱动及官方依据内容。</ElCheckbox
              >
              <p v-if="validation && dirty" class="muted">{{ validation }}</p>
              <p v-if="!canSavePermission" class="muted">
                没有保存设置的权限，请联系平台管理员。
              </p>
              <ElSpace wrap class="actions">
                <ElButton :disabled="!canSave" :loading="busy === 'save'" @click="save"
                  >保存为停用设置</ElButton
                >
                <ElButton
                  type="primary"
                  :disabled="!canEnable"
                  :loading="busy === 'enable'"
                  @click="changeState(true)"
                  >启用会议执行</ElButton
                >
                <ElButton
                  v-if="selection.registration"
                  :disabled="!canRevoke"
                  :loading="busy === 'revoke'"
                  @click="changeState(false)"
                  >撤销会议执行</ElButton
                >
              </ElSpace>
              <p v-if="!canPublish" class="muted">
                没有启用或撤销权限，请联系具有模型发布权限的管理员。
              </p>
              <p v-else-if="dirty || confirmed" class="muted">
                表单有待保存的核定内容。先保存停用设置，再启用；不会直接启用未保存的内容。
              </p>
              <p v-else-if="!selection.can_enable && selection.state !== 'enabled'">
                当前尚不能启用：{{ selection.next_action }}
              </p>
              <p v-if="needsRead" class="muted"> 上次操作的结果尚未确认，请重新读取设置后继续。 </p>
              <p>
                撤销后停止接受新会议任务；已冻结任务仍遵循原容量、来源与恢复规则。会议调用还需要独立的
                SandIAM 工作负载授权。
              </p>
            </template>
          </template>
        </ElForm>
      </template>
    </template>
  </div>
</template>

<script setup lang="ts">
  import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue'
  import { ElMessageBox } from 'element-plus'
  import { useAuth } from '@/hooks/core/useAuth'
  import { useUserStore } from '@/store/modules/user'
  import { HttpError } from '@/utils/http/error'
  import meetingExecutionApi, {
    type MeetingExecutionCapacity,
    type MeetingExecutionExpectedRoute,
    type MeetingExecutionSelection,
    type MeetingExecutionState,
    type MeetingPublishedRoute
  } from '../api/meetingExecution'

  const props = defineProps<{
    environmentId: number | ''
    applicationName: string
    environmentName: string
  }>()
  const emit = defineEmits<{ (event: 'configure'): void }>()
  const { hasAuth } = useAuth()
  const userStore = useUserStore()
  const canRead = computed(() => hasAuth('sand_ai:setup:deployment:read'))
  const canSavePermission = computed(() => hasAuth('sand_ai:setup:deployment:save'))
  const canPublish = computed(() => hasAuth('sand_ai:onboarding:publish'))
  const stateLabels: Record<MeetingExecutionState, string> = {
    unconfigured: '未核定',
    disabled: '已保存，未启用',
    enabled: '已启用',
    revoked: '已撤销',
    stale: '需要重新核定',
    unsupported: '路线暂不支持',
    worker_unavailable: '会议执行环境未就绪'
  }
  const routes = ref<MeetingPublishedRoute[]>([])
  const selection = ref<MeetingExecutionSelection | null>(null)
  const model = ref('')
  const loaded = ref(false)
  const needsRead = ref(false)
  const busy = ref<'read' | 'save' | 'enable' | 'revoke' | 'confirm' | ''>('')
  const notice = ref('')
  const noticeType = ref<'info' | 'success' | 'warning' | 'error'>('info')
  const confirmed = ref(false)
  const emptyDraft = () => ({
    planning_mode: '' as '' | 'local' | 'remote',
    planning_driver: '',
    tokenizer_driver: '',
    context_window_tokens: null as number | null,
    input_token_limit: null as number | null,
    max_tokenizer_requests: null as number | null,
    capacity_source_url: '',
    capacity_source_sha256: ''
  })
  const draft = reactive(emptyDraft())
  const baseline = ref(JSON.stringify(emptyDraft()))
  const dirty = computed(() => JSON.stringify(draft) !== baseline.value)
  const driver = computed(() =>
    selection.value?.route.tokenizer_drivers.find((item) => item.code === draft.tokenizer_driver)
  )
  const planningDrivers = computed(() => selection.value?.route.planning_drivers ?? [])
  const isLocal = computed(() => draft.planning_mode === 'local')
  const planningDriver = computed(() => planningDrivers.value.find(item => item.code === draft.planning_driver))
  const hasExecutionDriver = computed(() => Boolean(selection.value?.route.tokenizer_drivers.length || planningDrivers.value.length))
  function changePlanningMode(): void {
    draft.tokenizer_driver = ''
    draft.planning_driver = ''
    draft.max_tokenizer_requests = isLocal.value ? 0 : null
    confirmed.value = false
  }
  const inputCeiling = computed(() => {
    if (!selection.value || draft.context_window_tokens === null) return null
    const available = draft.context_window_tokens - selection.value.route.max_output_tokens
    return Math.min(available, selection.value.route.input_token_ceiling ?? available)
  })
  const formLocked = computed(
    () => Boolean(busy.value) || needsRead.value || !canSavePermission.value
  )
  const validation = computed(() => {
    const selectedDriver = driver.value
    if (!draft.planning_mode) return '请明确选择本地规划或远程分词计数。'
    if (isLocal.value ? !planningDriver.value : !selectedDriver) return '请选择当前路线支持的规划或计数驱动。'
    const context = draft.context_window_tokens
    if (
      context === null ||
      !Number.isInteger(context) ||
      context < 2 ||
      context > (isLocal.value ? 1048576 : selectedDriver!.context_window_ceiling)
    )
      return '请填写官方依据确认的上下文容量，并保持在驱动安全范围内。'
    const input = draft.input_token_limit
    if (
      input === null ||
      !Number.isInteger(input) ||
      input < 1 ||
      inputCeiling.value === null ||
      input > inputCeiling.value
    )
      return '输入上限须预留完整输出容量，并遵守路线输入上限。'
    const requests = draft.max_tokenizer_requests
    if (
      requests === null ||
      !Number.isInteger(requests) ||
      (isLocal.value ? requests !== 0 : (requests < selectedDriver!.request_limit_min || requests > selectedDriver!.request_limit_max))
    )
      return '请填写当前驱动允许范围内的分词请求上限。'
    try {
      const url = new URL(draft.capacity_source_url)
      if (url.protocol !== 'https:' || !url.hostname || url.username || url.password || url.hash)
        return '请填写无用户名或密码的官方 HTTPS 依据网址。'
    } catch {
      return '请填写有效的官方 HTTPS 依据网址。'
    }
    if (!/^[a-f0-9]{64}$/.test(draft.capacity_source_sha256))
      return '请填写官方依据原始内容的 64 位小写 SHA-256 摘要。'
    if (!confirmed.value) return '核对实际容量、驱动及依据后，请勾选核定确认。'
    return ''
  })
  const canSave = computed(() => Boolean(selection.value) && !formLocked.value && !validation.value)
  const canEnable = computed(
    () =>
      Boolean(selection.value?.can_enable) &&
      selection.value?.state !== 'enabled' &&
      canPublish.value &&
      !busy.value &&
      !needsRead.value &&
      !dirty.value &&
      !confirmed.value
  )
  const canRevoke = computed(
    () =>
      Boolean(selection.value?.registration) &&
      selection.value?.state !== 'revoked' &&
      canPublish.value &&
      !busy.value &&
      !needsRead.value
  )
  let generation = 0

  function show(text: string, type: typeof noticeType.value): void {
    notice.value = text
    noticeType.value = type
  }
  function adopt(result: MeetingExecutionSelection | null): void {
    selection.value = result
    const capacity = result?.registration && result.state !== 'stale' ? result.registration.capacity : null
    Object.assign(draft, emptyDraft(), capacity ?? {})
    if (capacity) draft.planning_mode = capacity.planning_driver ? 'local' : 'remote' 
    baseline.value = JSON.stringify(draft)
    confirmed.value = false
  }
  function expected(): MeetingExecutionExpectedRoute | null {
    const current = selection.value
    if (!props.environmentId || !current || current.route.model !== model.value) return null
    return {
      environment_id: props.environmentId,
      model: current.route.model,
      expected_route_sha256: current.route.route_sha256,
      expected_deployment_revision: current.route.deployment_revision,
      expected_profile_revision: current.route.profile_revision,
      expected_revision: current.registration?.revision ?? 0
    }
  }
  function failure(error: unknown, action: 'read' | 'write'): void {
    const code =
      error instanceof HttpError ? error.message.match(/^SAND_AI_[A-Z_]+/)?.[0] : undefined
    const messages: Record<string, string> = {
      SAND_AI_MEETING_EXECUTION_CONFLICT: '设置或模型路由已变化，请重新读取并核对最新版本。',
      SAND_AI_CONFIGURATION_NOT_PUBLISHED:
        '当前环境没有可用的已发布路线，请先完成模型与能力路由发布。',
      SAND_AI_MEETING_TOKENIZER_UNSUPPORTED:
        '此路线没有可执行的分词计数驱动，请重新读取后选择受支持的路线。',
      SAND_AI_MEETING_WORKER_UNAVAILABLE:
        '会议执行环境尚未就绪，请联系管理员完成正常安装或升级，再读取设置。',
      SAND_AI_SCHEMA_UNSUPPORTED:
        '设置未被接受，请核对容量范围、官方依据网址及内容摘要，然后重新读取设置。'
    }
    show(
      (code && messages[code]) ||
        (action === 'read'
          ? '无法读取会议执行设置，请核对登录与读取权限后重试。'
          : '操作结果尚未确认，请重新读取设置，核对实际状态后再继续。'),
      'warning'
    )
    if (action === 'write') needsRead.value = true
  }
  async function read(): Promise<void> {
    if (!canRead.value || !props.environmentId || busy.value) return
    const target = props.environmentId
    const selectedModel = model.value
    const current = ++generation
    busy.value = 'read'
    selection.value = null
    try {
      const result = await meetingExecutionApi.read(target, selectedModel || undefined)
      if (current !== generation) return
      if (
        result.owner.environment_id !== target ||
        (selectedModel && result.selection?.route.model !== selectedModel)
      )
        throw new Error('Unexpected selection')
      routes.value = result.routes
      adopt(result.selection)
      loaded.value = true
      needsRead.value = false
      notice.value = ''
    } catch (error) {
      if (current === generation) {
        loaded.value = false
        failure(error, 'read')
      }
    } finally {
      if (current === generation) busy.value = ''
    }
  }
  async function refresh(): Promise<void> {
    if (busy.value) return
    if (dirty.value || confirmed.value) {
      const current = generation
      busy.value = 'confirm'
      try {
        await ElMessageBox.confirm(
          '重新读取将放弃尚未保存的容量核定内容。是否继续？',
          '重新读取设置',
          {
            confirmButtonText: '放弃草稿并读取',
            cancelButtonText: '保留草稿',
            type: 'warning'
          }
        )
      } catch {
        return
      } finally {
        if (current === generation) busy.value = ''
      }
      if (current !== generation) return
    }
    await read()
  }
  async function selectModel(): Promise<void> {
    adopt(null)
    await read()
  }
  async function save(): Promise<void> {
    const target = expected()
    if (
      !target ||
      !canSave.value ||
      draft.context_window_tokens === null ||
      draft.input_token_limit === null ||
      draft.max_tokenizer_requests === null
    )
      return
    const common = {
      context_window_tokens: draft.context_window_tokens,
      input_token_limit: draft.input_token_limit,
      capacity_source_url: draft.capacity_source_url,
      capacity_source_sha256: draft.capacity_source_sha256
    }
    const capacity: MeetingExecutionCapacity = isLocal.value
      ? { ...common, planning_driver: 'neuron_delimiter_splitter@1', max_tokenizer_requests: 0 }
      : { ...common, tokenizer_driver: draft.tokenizer_driver, max_tokenizer_requests: draft.max_tokenizer_requests }
    const current = generation
    busy.value = 'save'
    try {
      const result = await meetingExecutionApi.save({ ...target, capacity })
      if (current !== generation) return
      adopt(result.selection)
      show('容量核定已保存，会议执行保持停用。确认执行环境就绪后，点击启用会议执行。', 'success')
    } catch (error) {
      if (current === generation) failure(error, 'write')
    } finally {
      if (current === generation) busy.value = ''
    }
  }
  async function changeState(enabled: boolean): Promise<void> {
    const target = expected()
    if (!target || !(enabled ? canEnable.value : canRevoke.value)) return
    const current = generation
    busy.value = 'confirm'
    try {
      await ElMessageBox.confirm(
        enabled
          ? (isLocal.value ? '确认启用已保存的本地规划设置？本地不实测 Token，模型生成仍需 SandIAM 授权并可能产生费用。' : '确认启用当前模型版本及已保存容量的会议执行？实际调用仍需 SandIAM 授权，可能产生模型与分词费用。')
          : '确认撤销此路线的会议执行？新会议任务将被拒绝，已冻结任务保留原恢复规则。',
        enabled ? '启用会议执行' : '撤销会议执行',
        {
          confirmButtonText: enabled ? '确认启用' : '确认撤销',
          cancelButtonText: '取消',
          type: 'warning'
        }
      )
    } catch {
      if (current === generation) busy.value = ''
      return
    }
    if (current !== generation) return
    busy.value = enabled ? 'enable' : 'revoke'
    try {
      const result = await meetingExecutionApi.state({ ...target, enabled })
      if (current !== generation) return
      adopt(result.selection)
      show(enabled ? '会议执行已启用。' : '会议执行已撤销，停止接受新任务。', 'success')
    } catch (error) {
      if (current === generation) failure(error, 'write')
    } finally {
      if (current === generation) busy.value = ''
    }
  }
  watch(
    () =>
      [
        props.environmentId,
        userStore.info.id,
        canRead.value,
        canSavePermission.value,
        canPublish.value
      ] as const,
    () => {
      generation += 1
      busy.value = ''
      routes.value = []
      model.value = ''
      loaded.value = false
      needsRead.value = false
      notice.value = ''
      adopt(null)
      void read()
    },
    { immediate: true, flush: 'sync' }
  )
  watch(
    draft,
    () => {
      confirmed.value = false
    },
    { flush: 'sync' }
  )
  onBeforeUnmount(() => {
    generation += 1
  })
</script>

<style scoped>
  .meeting-execution .muted {
    color: var(--el-text-color-secondary);
  }
  .meeting-execution .actions {
    margin-block: 16px;
  }
  .meeting-execution .el-alert {
    margin-block: 12px;
  }
  .meeting-execution .el-select,
  .meeting-execution .el-input {
    width: min(100%, 560px);
  }
  .meeting-execution .el-form-item :deep(.el-form-item__content) {
    gap: 8px;
  }
  .meeting-execution .digest {
    overflow-wrap: anywhere;
  }
  @media (max-width: 600px) {
    .meeting-execution :deep(.el-form-item) {
      display: block;
    }
    .meeting-execution :deep(.el-form-item__label) {
      justify-content: flex-start;
    }
    .meeting-execution :deep(.el-form-item__content) {
      margin-left: 0 !important;
    }
  }
</style>
