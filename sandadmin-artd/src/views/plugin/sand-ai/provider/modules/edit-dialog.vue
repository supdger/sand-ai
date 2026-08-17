<template>
  <el-dialog
    v-model="visible"
    :title="dialogType === 'add' ? '新增服务商' : '编辑服务商'"
    width="680px"
    align-center
    :close-on-click-modal="false"
    @close="handleClose"
  >
    <el-form ref="formRef" :model="formData" :rules="rules" label-width="120px">
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
        <el-input v-model="formData.adapter" placeholder="如 openai_compatible" />
      </el-form-item>
      <el-form-item label="超时(ms)" prop="timeout_ms">
        <el-input-number v-model="formData.timeout_ms" :min="1" :step="1000" />
      </el-form-item>
      <el-form-item label="密钥配置">
        <el-input
          v-model="formData.encryptedConfigText"
          type="textarea"
          :rows="4"
          placeholder='仅写入：JSON 对象，例如 {"api_key":"..."}。留空表示不修改。'
        />
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
      <ElButton type="primary" :loading="submitting" @click="handleSubmit">确定</ElButton>
    </template>
  </el-dialog>
</template>

<script setup lang="ts">
  import { computed, reactive, ref, watch } from 'vue'
  import type { FormInstance, FormRules } from 'element-plus'
  import { ElMessage } from 'element-plus'
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
    timeout_ms: [{ required: true, message: '请输入超时', trigger: 'change' }]
  }

  watch(
    () => props.modelValue,
    (open) => {
      if (!open) return
      formData.id = props.data.id
      formData.code = props.data.code ?? ''
      formData.name = props.data.name ?? ''
      formData.adapter = props.data.adapter ?? 'openai_compatible'
      formData.timeout_ms = props.data.timeout_ms ?? 30000
      formData.encryptedConfigText = ''
      formData.status = (props.data.status as DataStatus) ?? 1
      hasEncryptedConfig.value = Boolean(props.data.has_encrypted_config)
    }
  )

  function handleClose(): void {
    visible.value = false
    formRef.value?.resetFields()
  }

  function parseEncryptedConfig(): Record<string, unknown> | undefined {
    const text = formData.encryptedConfigText.trim()
    if (text === '') {
      return undefined
    }
    const parsed: unknown = JSON.parse(text)
    if (typeof parsed !== 'object' || parsed === null || Array.isArray(parsed)) {
      throw new Error('encrypted_config 必须是 JSON 对象')
    }
    return parsed as Record<string, unknown>
  }

  async function handleSubmit(): Promise<void> {
    const valid = await formRef.value?.validate().catch(() => false)
    if (!valid) return
    submitting.value = true
    try {
      const encryptedConfig = parseEncryptedConfig()
      const payload: SandAiProviderWrite = {
        name: formData.name,
        adapter: formData.adapter,
        timeout_ms: formData.timeout_ms,
        status: formData.status
      }
      if (encryptedConfig !== undefined) {
        payload.encrypted_config = encryptedConfig
      }
      if (props.dialogType === 'add') {
        await providerApi.save({ ...payload, code: formData.code })
      } else if (formData.id !== undefined) {
        await providerApi.update({ ...payload, id: formData.id })
      }
      ElMessage.success('保存成功')
      emit('success')
      handleClose()
    } catch (error) {
      ElMessage.error(error instanceof Error ? error.message : '保存失败')
    } finally {
      submitting.value = false
    }
  }
</script>

<style scoped lang="scss">
  .config-hint {
    margin-top: 6px;
    font-size: 12px;
    line-height: 1.4;
    color: var(--el-text-color-secondary);
  }
</style>
