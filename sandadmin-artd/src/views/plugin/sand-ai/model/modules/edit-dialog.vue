<template>
  <el-dialog
    v-model="visible"
    :title="dialogType === 'add' ? '新增模型' : '编辑模型'"
    width="640px"
    align-center
    :close-on-click-modal="false"
    @close="handleClose"
  >
    <el-form ref="formRef" :model="formData" :rules="rules" label-width="110px">
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
      <el-form-item label="类型" prop="type">
        <el-select v-model="formData.type" style="width: 100%">
          <el-option label="chat" value="chat" />
          <el-option label="embedding" value="embedding" />
          <el-option label="rerank" value="rerank" />
        </el-select>
      </el-form-item>
      <el-form-item label="能力" prop="capabilitiesText">
        <el-input v-model="formData.capabilitiesText" placeholder="逗号分隔，如 chat,tool_call" />
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
  import modelApi from '../../api/model'
  import type { DataStatus, ModelType, SandAiModel } from '../../api/types'

  type FormModel = {
    id?: number
    code: string
    name: string
    type: ModelType
    capabilitiesText: string
    status: DataStatus
  }

  const props = defineProps<{
    modelValue: boolean
    dialogType: 'add' | 'edit'
    data: Partial<SandAiModel>
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
  const formData = reactive<FormModel>({
    id: undefined,
    code: '',
    name: '',
    type: 'chat',
    capabilitiesText: '',
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
    type: [{ required: true, message: '请选择类型', trigger: 'change' }]
  }

  watch(
    () => props.modelValue,
    (open) => {
      if (!open) return
      formData.id = props.data.id
      formData.code = props.data.code ?? ''
      formData.name = props.data.name ?? ''
      formData.type = (props.data.type as ModelType) ?? 'chat'
      formData.capabilitiesText = (props.data.capabilities ?? []).join(',')
      formData.status = (props.data.status as DataStatus) ?? 1
    }
  )

  function handleClose(): void {
    visible.value = false
    formRef.value?.resetFields()
  }

  async function handleSubmit(): Promise<void> {
    const valid = await formRef.value?.validate().catch(() => false)
    if (!valid) return
    submitting.value = true
    try {
      const capabilities = formData.capabilitiesText
        .split(',')
        .map((item) => item.trim())
        .filter(Boolean)
      const payload = {
        name: formData.name,
        type: formData.type,
        capabilities,
        status: formData.status
      }
      if (props.dialogType === 'add') {
        await modelApi.save({ ...payload, code: formData.code })
      } else if (formData.id !== undefined) {
        await modelApi.update({ ...payload, id: formData.id })
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
