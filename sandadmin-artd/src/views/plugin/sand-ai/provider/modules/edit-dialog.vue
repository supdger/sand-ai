<template>
  <el-dialog
    v-model="visible"
    :title="dialogType === 'add' ? '新增服务商' : '编辑服务商'"
    width="680px"
    align-center
    :close-on-click-modal="false"
    @close="handleClose"
  >
    <ElAlert v-if="pending" type="warning" :closable="false"
      :title="`原${pending.operation === 'save' ? '新增' : '更新'}保存待确认：${pending.code}${pending.id !== null ? `（编号 ${pending.id}）` : ''}`"
      description="只查询原保存结果，不再次发送；原结果表示当时的保存情况，当前配置由列表另行读取。" />
    <el-input v-if="pending" :model-value="pending.key" readonly aria-label="原保存请求编号" />
    <ElButton v-if="pending || journalError" :loading="reading" :disabled="submitting || reading" @click="recoverOperation">查看原保存结果</ElButton>
    <ElButton v-if="canSendUnsubmitted" :disabled="submitting || reading" @click="handleSubmit">按原请求编号首次提交</ElButton>
    <ElAlert v-if="errorMessage" type="warning" :closable="false" :title="errorMessage" />
    <ElButton v-if="!pending && !journalError && !configurationLoaded" :loading="loadingExisting"
      :disabled="submitting || reading" @click="loadExisting">重新读取当前配置</ElButton>
    <el-form ref="formRef" :model="formData" :rules="rules" :disabled="formBlocked" label-width="120px">
      <el-form-item v-if="formData.code" label="编码">
        <span>编码：{{ formData.code }}（自动生成）</span>
      </el-form-item>
      <el-form-item v-if="dialogType === 'add' && presetGuides.length > 0" label="选择服务商">
        <div class="preset-grid" role="radiogroup" aria-label="服务商预设">
          <button
            v-for="guide in presetGuides"
            :key="guide.provider_code"
            type="button"
            class="preset-card"
            :class="{ 'preset-card--active': selectedPreset === guide.provider_code }"
            :aria-pressed="selectedPreset === guide.provider_code"
            @click="selectPreset(guide)"
          >
            <span class="preset-card__name">{{ guide.name }}</span>
            <span class="preset-card__desc">{{ guide.default_base_url ?? '自行填写接口地址' }}</span>
          </button>
        </div>
      </el-form-item>
      <el-form-item label="名称" prop="name">
        <el-input v-model="formData.name" />
      </el-form-item>
      <el-form-item label="服务商类型" prop="adapter">
        <el-select v-model="formData.adapter" style="width: 100%" :disabled="dialogType === 'edit' || presetLocked">
          <el-option label="OpenAI 兼容模型接口" value="openai_compatible" />
          <el-option label="Cohere 专业重排" value="cohere_rerank" />
          <el-option label="Jina 专业重排" value="jina_rerank" />
          <el-option label="本地运行" value="local_runtime" />
          <el-option label="本地图片文字识别" value="local_ocr_runtime" />
          <el-option v-if="formData.adapter === 'fake'" label="开发用模拟接口" value="fake" />
        </el-select>
      </el-form-item>
      <el-form-item label="超时（毫秒）" prop="timeout_ms">
        <el-input-number v-model="formData.timeout_ms" :min="formData.adapter === 'local_ocr_runtime' ? 1000 : 1" :max="formData.adapter === 'local_ocr_runtime' ? 120000 : undefined" :step="1000" />
      </el-form-item>
      <template v-if="needsSecretFields">
        <el-form-item label="接口地址">
          <el-input v-model="formData.configBaseUrl" placeholder="服务商提供的 HTTPS API 基础地址" />
        </el-form-item>
        <el-form-item label="API Key">
          <div class="api-key-field">
            <el-input
              v-model="formData.configApiKey"
              type="password"
              show-password
              autocomplete="off"
              placeholder="服务商提供的 API Key"
            />
            <div v-if="selectedGuide?.api_key_help || selectedGuide?.api_key_url" class="config-hint">
              {{ selectedGuide?.api_key_help }}
              <a
                v-if="selectedGuide?.api_key_url"
                :href="selectedGuide.api_key_url"
                target="_blank"
                rel="noopener noreferrer"
              >如何获取 API Key</a>
            </div>
          </div>
        </el-form-item>
        <el-form-item v-if="canTestConnection" label="连接">
          <div class="test-connection">
            <ElButton :loading="testing" :disabled="testing || submitting || reading" @click="testConnection">
              测试连接
            </ElButton>
            <ElAlert
              v-if="testResult"
              :type="testResult.ok ? 'success' : 'warning'"
              :closable="false"
              :title="testResult.message"
              class="mt-2"
            />
            <div v-if="testResult?.ok && testResult.models.length > 0" class="config-hint mt-2">
              可用模型：
              <ElTag
                v-for="model in testResult.models.slice(0, 12)"
                :key="model.remote_model"
                :type="isRecommendedModel(model.remote_model) ? 'success' : 'info'"
                effect="plain"
                class="model-tag"
              >{{ model.remote_model }}{{ isRecommendedModel(model.remote_model) ? '（推荐）' : '' }}</ElTag>
              <span v-if="testResult.models.length > 12">等 {{ testResult.models.length }} 个</span>
            </div>
          </div>
        </el-form-item>
      </template>
      <el-form-item v-if="formData.adapter !== 'local_ocr_runtime'" label="连接说明">
        <div>
          <div class="config-hint">{{ configHint }}</div>
          <div v-if="selectedGuide?.data_egress_notice" class="config-hint">{{ selectedGuide.data_egress_notice }}</div>
          <div class="config-hint">
            {{
              hasEncryptedConfig
                ? '当前已保存密钥，不会回显；填写上方任意一项将整体替换原连接配置，全部留空则不修改。'
                : '当前尚无连接配置。'
            }}
          </div>
        </div>
      </el-form-item>
      <el-form-item label="状态" prop="status">
        <el-radio-group v-model="formData.status" aria-label="服务商状态">
          <el-radio :value="1">启用</el-radio>
          <el-radio :value="2">停用</el-radio>
        </el-radio-group>
      </el-form-item>
    </el-form>
    <template #footer>
      <ElButton @click="handleClose">取消</ElButton>
      <ElButton type="primary" :loading="submitting" :disabled="formBlocked" @click="handleSubmit">确定</ElButton>
    </template>
  </el-dialog>
</template>

<script setup lang="ts">
  import { computed, onUnmounted, reactive, ref, watch } from 'vue'
  import type { FormInstance, FormRules } from 'element-plus'
  import { ElMessage } from 'element-plus'
  import { useUserStore } from '@/store/modules/user'
  import {
    readCatalogConfigurationIntent, rememberedCatalogConfigurationIntent, retainCatalogConfigurationIntent,
    resolveCatalogConfigurationIntent, matchesCatalogConfigurationReceipt, type CatalogConfigurationIntent
  } from '../../api/catalogConfigurationIntent'
  import providerApi from '../../api/provider'
  import { generateCatalogCode } from '../../api/catalogCode'
  import { HttpError } from '@/utils/http/error'
  import type { DiscoveredModel, ProviderGuide } from '../../api/onboarding'
  import type { DataStatus, SandAiProvider, SandAiProviderWrite } from '../../api/types'

  type FormModel = {
    id?: number
    code: string
    name: string
    adapter: string
    timeout_ms: number
    configBaseUrl: string
    configApiKey: string
    status: DataStatus
  }

  const props = defineProps<{
    modelValue: boolean
    dialogType: 'add' | 'edit'
    data: Partial<SandAiProvider>
  }>()

  const emit = defineEmits<{
    'update:modelValue': [value: boolean]
    success: []
  }>()

  const visible = computed({
    get: () => props.modelValue,
    set: (value: boolean) => emit('update:modelValue', value)
  })

  const formRef = ref<FormInstance>()
  const submitting = ref(false)
  const reading = ref(false)
  const loadingExisting = ref(false)
  const configurationLoaded = ref(false)
  const errorMessage = ref('')
  const pending = ref<CatalogConfigurationIntent | null>(null)
  const journalError = ref(false)
  const unsubmitted = ref<CatalogConfigurationIntent | null>(null)
  const canSendUnsubmitted = computed(() => unsubmitted.value !== null
    && JSON.stringify(unsubmitted.value) === JSON.stringify(pending.value))
  const userStore = useUserStore()
  const adminId = computed(() => {
    const id = Number(userStore.info.id)
    return userStore.accessToken && Number.isSafeInteger(id) && id > 0 ? id : 0
  })
  let epoch = 0
  let live = true
  const formBlocked = computed(() => !adminId.value || submitting.value || reading.value
    || loadingExisting.value || !configurationLoaded.value || journalError.value || pending.value !== null)
  const hasEncryptedConfig = ref(false)
  const formData = reactive<FormModel>({
    id: undefined,
    code: '',
    name: '',
    adapter: 'openai_compatible',
    timeout_ms: 30000,
    configBaseUrl: '',
    configApiKey: '',
    status: 1
  })

  const presetGuides = ref<ProviderGuide[]>([])
  const selectedPreset = ref('')
  const testing = ref(false)
  const testResult = ref<{ ok: boolean; message: string; models: DiscoveredModel[] } | null>(null)
  const selectedGuide = computed(() => presetGuides.value.find((guide) => guide.provider_code === selectedPreset.value))
  const presetLocked = computed(() => props.dialogType === 'add' && selectedPreset.value !== ''
    && selectedPreset.value !== 'custom_openai_compatible')

  const needsSecretFields = computed(() => !['local_ocr_runtime', 'local_runtime', 'fake'].includes(formData.adapter))
  const configHint = computed(() => {
    switch (formData.adapter) {
      case 'openai_compatible':
        return '填写所选 OpenAI 兼容服务提供的接口地址与 API Key；请勿填写其他协议的接口地址。保存不会调用服务。'
      case 'cohere_rerank':
        return '填写 Cohere 重排服务提供的 HTTPS API 基础地址与 API Key；基础地址不含 /rerank。模型标识在部署草稿中指定，保存不会调用服务。'
      case 'jina_rerank':
        return '填写 Jina 重排服务提供的 HTTPS API 基础地址与 API Key；基础地址不含 /rerank。模型标识在部署草稿中指定，保存不会调用服务。'
      case 'local_runtime':
        return '本地运行不使用远程接口地址或 API Key，无需填写连接配置。'
      case 'fake':
        return '开发用模拟接口不连接真实模型，无需填写真实 API Key。'
      default:
        return '请按当前服务商类型已声明的连接方式填写接口地址与 API Key。'
    }
  })

  const canTestConnection = computed(() => {
    if (!needsSecretFields.value) return false
    if (formData.configBaseUrl.trim() !== '' || formData.configApiKey.trim() !== '') return true
    return props.dialogType === 'edit' && hasEncryptedConfig.value && typeof formData.id === 'number'
  })

  function isRecommendedModel(remoteModel: string): boolean {
    return (selectedGuide.value?.recommended_models ?? [])
      .some((item) => item.recommended && item.remote_model === remoteModel)
  }

  function selectPreset(guide: ProviderGuide): void {
    selectedPreset.value = guide.provider_code ?? ''
    if (guide.adapter) formData.adapter = guide.adapter
    if (guide.default_base_url) formData.configBaseUrl = guide.default_base_url
    if (!formData.name || presetGuides.value.some((item) => item.name === formData.name)) {
      formData.name = guide.name
    }
  }

  async function loadGuides(): Promise<void> {
    try {
      const result = await providerApi.guides()
      presetGuides.value = result.guides.filter((guide) => guide.provider_code && !guide.debug_only)
    } catch {
      presetGuides.value = []
    }
  }

  async function testConnection(): Promise<void> {
    if (testing.value || submitting.value || reading.value) return
    const baseUrl = formData.configBaseUrl.trim()
    const apiKey = formData.configApiKey.trim()
    let payload: { connection: { base_url: string; api_key: string } } | { provider_id: number }
    if (baseUrl !== '' || apiKey !== '') {
      if (baseUrl === '' || apiKey === '') {
        testResult.value = { ok: false, message: '请先填写接口地址和 API Key', models: [] }
        return
      }
      payload = { connection: { base_url: baseUrl, api_key: apiKey } }
    } else if (props.dialogType === 'edit' && hasEncryptedConfig.value && typeof formData.id === 'number') {
      payload = { provider_id: formData.id }
    } else {
      testResult.value = { ok: false, message: '请先填写接口地址和 API Key', models: [] }
      return
    }
    testing.value = true
    testResult.value = null
    try {
      const result = await providerApi.testConnection(payload)
      testResult.value = {
        ok: true,
        message: `连接成功，发现 ${result.models.length} 个可用模型`,
        models: Array.isArray(result.models) ? result.models : []
      }
    } catch (error) {
      let message = '连接失败，请检查接口地址和 API Key 后重试'
      if (error instanceof HttpError) {
        if (error.transport === 'timeout') message = '连接超时，请检查网络后重试'
        else if (error.transport === 'network') message = '网络不通，无法连接到服务器，请检查后重试'
        else if (error.message) message = error.message.replace(/^[A-Z0-9_]+:\s*/, '')
      }
      testResult.value = { ok: false, message, models: [] }
    } finally {
      testing.value = false
    }
  }

  const rules: FormRules<FormModel> = {
    name: [{ required: true, message: '请输入名称', trigger: 'blur' }],
    adapter: [{ required: true, message: '请选择服务商类型', trigger: 'blur' }],
    timeout_ms: [{ required: true, message: '请输入超时', trigger: 'change' }, {
      validator: (_rule, value: unknown, callback) => {
        if (formData.adapter === 'local_ocr_runtime' && (typeof value !== 'number' || !Number.isSafeInteger(value) || value < 1000 || value > 120000)) {
          callback(new Error('本地 OCR 超时须为 1000 至 120000 毫秒的整数')); return
        }
        callback()
      }, trigger: 'change'
    }]
  }

  function restoreIntent(): void {
    pending.value = null
    journalError.value = false
    if (!adminId.value) { journalError.value = true; return }
    try { pending.value = readCatalogConfigurationIntent(adminId.value, 'provider') ?? unsubmitted.value }
    catch {
      pending.value = rememberedCatalogConfigurationIntent(adminId.value, 'provider') ?? unsubmitted.value
      journalError.value = true
      errorMessage.value = '原保存记录无法确认，请保留原记录；暂不提交新的配置。'
    }
  }

  function clearPrivateForm(): void {
    unsubmitted.value = null
    formData.id = undefined; formData.code = ''; formData.name = ''
    formData.adapter = 'openai_compatible'; formData.timeout_ms = 30000
    formData.configBaseUrl = ''; formData.configApiKey = ''; formData.status = 1
    hasEncryptedConfig.value = false
    configurationLoaded.value = false
    selectedPreset.value = ''
    testing.value = false
    testResult.value = null
    formRef.value?.clearValidate()
  }

  async function loadExisting(): Promise<void> {
    const current = ++epoch
    const owner = adminId.value
    const credential = userStore.accessToken
    clearPrivateForm()
    errorMessage.value = ''
    loadingExisting.value = false
    restoreIntent()
    if (!visible.value || !owner || pending.value || journalError.value) return
    if (props.dialogType === 'add') {
      if (!formData.code) formData.code = generateCatalogCode('provider_')
      void loadGuides()
      configurationLoaded.value = true
      return
    }
    const id = props.data.id
    if (typeof id !== 'number' || !Number.isSafeInteger(id) || id < 1) {
      errorMessage.value = '原编辑目标无法确认，请重新从列表选择'
      return
    }
    loadingExisting.value = true
    const ownsRead = () => live && current === epoch && owner === adminId.value
      && credential === userStore.accessToken && visible.value && props.dialogType === 'edit' && props.data.id === id
    try {
      const row = await providerApi.read(id)
      if (!ownsRead()) return
      if (row.id !== id || typeof row.code !== 'string' || typeof row.name !== 'string'
        || typeof row.adapter !== 'string' || !Number.isSafeInteger(row.timeout_ms) || row.timeout_ms < 1
        || typeof row.has_encrypted_config !== 'boolean' || (row.status !== 1 && row.status !== 2))
        throw new Error('无法读取完整服务商配置，暂不能编辑')
      formData.id = row.id; formData.code = row.code; formData.name = row.name
      formData.adapter = row.adapter; formData.timeout_ms = row.timeout_ms; formData.status = row.status
      hasEncryptedConfig.value = row.has_encrypted_config
      configurationLoaded.value = true
    } catch {
      if (ownsRead()) errorMessage.value = '无法读取当前目标配置。请检查读取权限或连接后重新读取；不会提交占位配置。'
    } finally {
      if (ownsRead()) loadingExisting.value = false
    }
  }

  function handleClose(): void {
    ++epoch
    reading.value = false; loadingExisting.value = false
    clearPrivateForm()
    visible.value = false
  }

  function parseEncryptedConfig(): Record<string, unknown> | undefined {
    if (!needsSecretFields.value) return undefined
    const baseUrl = formData.configBaseUrl.trim()
    const apiKey = formData.configApiKey.trim()
    if (baseUrl === '' && apiKey === '') return undefined
    const parsed: Record<string, unknown> = {}
    if (baseUrl !== '') parsed.base_url = baseUrl
    if (apiKey !== '') parsed.api_key = apiKey
    return parsed
  }

  async function confirmOriginal(original: CatalogConfigurationIntent, current: number, credential: string): Promise<void> {
    const ownsRead = () => live && current === epoch && credential === userStore.accessToken
      && original.admin_id === adminId.value && visible.value
      && JSON.stringify(pending.value) === JSON.stringify(original)
    const receipt = await providerApi.readOperation(original)
    if (!ownsRead()) return
    if (!matchesCatalogConfigurationReceipt(original, receipt))
      throw new Error('尚未取得匹配原管理员、动作、编码、目标和请求号的保存结果')
    resolveCatalogConfigurationIntent(original)
    pending.value = null; unsubmitted.value = null; journalError.value = false
    ElMessage.success('已保存服务商，列表已刷新')
    emit('success')
    handleClose()
  }

  async function recoverOperation(): Promise<void> {
    if (submitting.value || reading.value || !visible.value) return
    restoreIntent()
    const original = pending.value
    if (!original || original.admin_id !== adminId.value) return
    const current = ++epoch
    const credential = userStore.accessToken
    reading.value = true
    errorMessage.value = ''
    try { await confirmOriginal(original, current, credential) }
    catch {
      if (live && current === epoch && credential === userStore.accessToken && original.admin_id === adminId.value)
        errorMessage.value = '原保存结果仍未确认。未找到、无权读取或网络异常不能视为未提交；保留原请求编号，仅可找回，不再次发送。'
    } finally { if (live && current === epoch) reading.value = false }
  }

  async function handleSubmit(): Promise<void> {
    const unsent = canSendUnsubmitted.value ? unsubmitted.value : null
    if ((!unsent && formBlocked.value) || submitting.value || reading.value) return
    const current = epoch
    const owner = adminId.value
    const credential = userStore.accessToken
    const operation = unsent?.operation ?? (props.dialogType === 'add' ? 'save' : 'update')
    const targetId = unsent ? unsent.id : operation === 'update' ? formData.id : null
    const code = unsent?.code ?? formData.code
    const ownsWrite = () => live && current === epoch && owner === adminId.value
      && credential === userStore.accessToken && visible.value
    submitting.value = true
    let sent = false
    try {
      const valid = await formRef.value?.validate().catch(() => false)
      if (!valid || !ownsWrite()) return
      if (!unsent) {
        restoreIntent()
        if (pending.value || journalError.value) return
      }
      if (operation === 'update' && (typeof targetId !== 'number' || !Number.isSafeInteger(targetId) || targetId < 1))
        throw new Error('原编辑目标无法确认')
      const encryptedConfig = parseEncryptedConfig()
      const payload: SandAiProviderWrite = { name: formData.name, adapter: formData.adapter,
        timeout_ms: formData.timeout_ms, status: formData.status }
      if (encryptedConfig !== undefined) payload.encrypted_config = encryptedConfig
      const original: CatalogConfigurationIntent = unsent ?? { version: 1, admin_id: owner, kind: 'provider', operation,
        code, id: typeof targetId === 'number' ? targetId : null, key: crypto.randomUUID() }
      pending.value = original
      unsubmitted.value = original
      retainCatalogConfigurationIntent(original, unsent)
      pending.value = readCatalogConfigurationIntent(owner, 'provider')
      if (!pending.value) throw new Error('原请求标识未安全保存，本次不发送')
      errorMessage.value = ''
      formData.configBaseUrl = ''
      formData.configApiKey = ''
      unsubmitted.value = null
      sent = true
      if (original.operation === 'save') await providerApi.save({ ...payload, code: original.code }, original.key)
      else if (original.id !== null) await providerApi.update({ ...payload, id: original.id }, original.key)
      if (!ownsWrite()) return
      await confirmOriginal(original, current, credential)
    } catch {
      if (!ownsWrite()) return
      if (sent) {
        errorMessage.value = '保存结果尚未确认。原目标和请求编号已保留，请查看原保存结果；不会重新发送或创建替代请求。'
      } else {
        restoreIntent()
        errorMessage.value = pending.value || journalError.value
          ? '原请求记录无法安全确认，本次未发送。请保留此页；修复会话存储后，可按原请求编号首次提交；离页或身份改变后仅可找回。'
          : '本次未发送，请检查表单格式与会话存储后再保存。'
      }
    } finally { submitting.value = false }
  }

  watch([() => props.modelValue, () => props.dialogType, () => props.data.id], () => {
    ++epoch
    reading.value = false; loadingExisting.value = false
    clearPrivateForm()
    if (visible.value) void loadExisting()
  }, { immediate: true, flush: 'sync' })
  watch([adminId, () => userStore.accessToken], () => {
    ++epoch
    reading.value = false; loadingExisting.value = false
    clearPrivateForm()
    errorMessage.value = ''
    restoreIntent()
    if (visible.value) void loadExisting()
  }, { flush: 'sync' })
  onUnmounted(() => { live = false; ++epoch; clearPrivateForm() })
</script>

<style scoped lang="scss">
  .config-hint {
    margin-top: 6px;
    font-size: 12px;
    line-height: 1.4;
    color: var(--el-text-color-secondary);
  }

  .preset-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    width: 100%;
  }

  .preset-card {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 128px;
    padding: 8px 10px;
    font-size: 13px;
    line-height: 1.4;
    text-align: left;
    cursor: pointer;
    background: var(--el-fill-color-blank);
    border: 1px solid var(--el-border-color);
    border-radius: 6px;
    transition: border-color 0.15s ease;
  }

  .preset-card:hover {
    border-color: var(--el-color-primary);
  }

  .preset-card--active {
    border-color: var(--el-color-primary);
    box-shadow: 0 0 0 1px var(--el-color-primary) inset;
  }

  .preset-card__name {
    font-weight: 600;
    color: var(--el-text-color-primary);
  }

  .preset-card__desc {
    font-size: 12px;
    color: var(--el-text-color-secondary);
    word-break: break-all;
  }

  .api-key-field,
  .test-connection {
    width: 100%;
  }

  .model-tag {
    margin: 2px 4px 2px 0;
  }
</style>
