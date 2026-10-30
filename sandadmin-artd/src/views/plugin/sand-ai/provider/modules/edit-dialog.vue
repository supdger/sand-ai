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
      :title="`原${pending.operation === 'save' ? '新增' : '更新'}保存待确认：${pending.code}${pending.id !== null ? ' · ID ' + pending.id : ''}`"
      description="只读取原保存回执，不再次发送；原回执表示当时保存结果，当前配置由列表另行读取。" />
    <el-input v-if="pending" :model-value="pending.key" readonly aria-label="原保存请求号" />
    <ElButton v-if="pending || journalError" :loading="reading" :disabled="submitting || reading" @click="recoverOperation">读取原保存回执</ElButton>
    <ElButton v-if="canSendUnsubmitted" :disabled="submitting || reading" @click="handleSubmit">保留原号首次提交</ElButton>
    <ElAlert v-if="errorMessage" type="warning" :closable="false" :title="errorMessage" />
    <ElButton v-if="!pending && !journalError && !configurationLoaded" :loading="loadingExisting"
      :disabled="submitting || reading" @click="loadExisting">重新读取当前配置</ElButton>
    <el-form ref="formRef" :model="formData" :rules="rules" :disabled="formBlocked" label-width="120px">
      <el-form-item label="编码" prop="code">
        <el-input
          v-model="formData.code"
          placeholder="小写字母/数字/_/-"
          :disabled="dialogType === 'edit'"
        />
      </el-form-item>
      <el-form-item label="名称" prop="name">
        <el-input v-model="formData.name" />
      </el-form-item>
      <el-form-item label="适配器" prop="adapter">
        <el-select v-model="formData.adapter" style="width: 100%" :disabled="dialogType === 'edit'">
          <el-option label="OpenAI 兼容模型接口" value="openai_compatible" />
          <el-option label="Cohere 专业重排" value="cohere_rerank" />
          <el-option label="Jina 专业重排" value="jina_rerank" />
          <el-option label="本地运行" value="local_runtime" />
          <el-option label="本地图片文字识别" value="local_ocr_runtime" />
          <el-option v-if="formData.adapter === 'fake'" label="开发用模拟接口" value="fake" />
        </el-select>
      </el-form-item>
      <el-form-item label="超时(ms)" prop="timeout_ms">
        <el-input-number v-model="formData.timeout_ms" :min="formData.adapter === 'local_ocr_runtime' ? 1000 : 1" :max="formData.adapter === 'local_ocr_runtime' ? 120000 : undefined" :step="1000" />
      </el-form-item>
      <el-form-item v-if="formData.adapter !== 'local_ocr_runtime'" label="密钥配置">
        <el-input
          v-model="formData.encryptedConfigText"
          type="textarea"
          :rows="4"
          :placeholder="configPlaceholder"
        />
        <div class="config-hint">{{ configHint }}</div>
        <div class="config-hint">
          {{
            hasEncryptedConfig
              ? '当前已有密钥配置（has_encrypted_config=true），不会回显密文。'
              : '当前尚无密钥配置。'
          }}
        </div>
      </el-form-item>
      <el-form-item label="状态" prop="status">
        <el-radio-group v-model="formData.status">
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
  import type { DataStatus, SandAiProvider, SandAiProviderWrite } from '../../api/types'

  type FormModel = {
    id?: number
    code: string
    name: string
    adapter: string
    timeout_ms: number
    encryptedConfigText: string
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
    encryptedConfigText: '',
    status: 1
  })

  const configPlaceholder = computed(() => {
    switch (formData.adapter) {
      case 'openai_compatible':
        return '仅写入：JSON 对象，例如 {"base_url":"<OpenAI 兼容服务的 HTTPS API 基础地址>","api_key":"<该服务的 API Key>"}。留空表示不修改。'
      case 'cohere_rerank':
        return '仅写入：JSON 对象，例如 {"base_url":"<Cohere 重排服务的 HTTPS API 基础地址>","api_key":"<该服务的 API Key>"}。留空表示不修改。'
      case 'jina_rerank':
        return '仅写入：JSON 对象，例如 {"base_url":"<Jina 重排服务的 HTTPS API 基础地址>","api_key":"<该服务的 API Key>"}。留空表示不修改。'
      default:
        return '仅写入：JSON 对象。留空表示不修改。'
    }
  })
  const configHint = computed(() => {
    switch (formData.adapter) {
      case 'openai_compatible':
        return '将示例中的占位文字替换为所选 OpenAI 兼容服务提供的基础地址与 API Key；请勿填写其他协议的接口地址。保存不会调用服务。'
      case 'cohere_rerank':
        return '将占位文字替换为 Cohere 重排服务提供的 HTTPS API 基础地址与 API Key；基础地址不含 /rerank。模型标识在部署草稿中指定，保存不会调用服务。'
      case 'jina_rerank':
        return '将占位文字替换为 Jina 重排服务提供的 HTTPS API 基础地址与 API Key；基础地址不含 /rerank。模型标识在部署草稿中指定，保存不会调用服务。'
      case 'local_runtime':
        return '本地运行不使用远程 API 地址或 API Key，无需填写密钥配置。'
      case 'fake':
        return '开发用模拟接口不连接真实模型，无需填写真实 API Key。'
      default:
        return '请按当前适配器已声明的配置填写，不使用其他适配器的示例。'
    }
  })

  const rules: FormRules<FormModel> = {
    code: [
      { required: true, message: '请输入编码', trigger: 'blur' },
      {
        pattern: /^[a-z0-9][a-z0-9_-]{1,63}$/,
        message: '编码需为小写字母数字开头，仅含 a-z 0-9 _ -',
        trigger: 'blur'
      }
    ],
    name: [{ required: true, message: '请输入名称', trigger: 'blur' }],
    adapter: [{ required: true, message: '请输入适配器', trigger: 'blur' }],
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
    formData.encryptedConfigText = ''; formData.status = 1
    hasEncryptedConfig.value = false
    configurationLoaded.value = false
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
    if (props.dialogType === 'add') { configurationLoaded.value = true; return }
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
    if (formData.adapter === 'local_ocr_runtime') return undefined
    const text = formData.encryptedConfigText.trim()
    if (text === '') return undefined
    const parsed: unknown = JSON.parse(text)
    if (typeof parsed !== 'object' || parsed === null || Array.isArray(parsed))
      throw new Error('密钥配置须为 JSON 对象')
    return parsed as Record<string, unknown>
  }

  async function confirmOriginal(original: CatalogConfigurationIntent, current: number, credential: string): Promise<void> {
    const ownsRead = () => live && current === epoch && credential === userStore.accessToken
      && original.admin_id === adminId.value && visible.value
      && JSON.stringify(pending.value) === JSON.stringify(original)
    const receipt = await providerApi.readOperation(original)
    if (!ownsRead()) return
    if (!matchesCatalogConfigurationReceipt(original, receipt))
      throw new Error('尚未取得匹配原管理员、动作、编码、目标和请求号的保存回执')
    resolveCatalogConfigurationIntent(original)
    pending.value = null; unsubmitted.value = null; journalError.value = false
    ElMessage.success('原服务商保存回执已确认；列表将重新读取当前配置')
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
        errorMessage.value = '原保存回执仍未确认。未找到、无权读取或网络异常不能视为未提交；保留原请求号，仅只读找回，不再次发送。'
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
      formData.encryptedConfigText = ''
      unsubmitted.value = null
      sent = true
      if (original.operation === 'save') await providerApi.save({ ...payload, code: original.code }, original.key)
      else if (original.id !== null) await providerApi.update({ ...payload, id: original.id }, original.key)
      if (!ownsWrite()) return
      await confirmOriginal(original, current, credential)
    } catch {
      if (!ownsWrite()) return
      if (sent) {
        errorMessage.value = '保存结果尚未确认。原目标和请求号已保留，请读取原保存回执；不会重新发送或创建替代请求。'
      } else {
        restoreIntent()
        errorMessage.value = pending.value || journalError.value
          ? '原请求记录无法安全确认，本次未发送。请保留此页；修复会话存储后，可保留原号首次提交；离页或身份改变后仅只读找回。'
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
</style>
