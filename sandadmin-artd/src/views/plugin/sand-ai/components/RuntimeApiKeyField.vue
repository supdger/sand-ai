<template>
  <ElFormItem label="上传身份">
    <div class="runtime-key">
      <template v-if="mode === 'auto'">
        <ElSpace wrap>
          <ElTag :type="trial.state.value === 'ready' ? 'success' : 'info'" effect="plain">
            {{ trial.state.value === 'ready' ? '已用当前管理员身份' : '正在确认身份…' }}
          </ElTag>
        </ElSpace>
        <ElAlert
          v-if="identityRefusal"
          type="error"
          :title="identityRefusal.status"
          :description="identityRefusal.description"
          :closable="false"
          show-icon
        />
        <details class="runtime-key__advanced">
          <summary>高级</summary>
          <div class="runtime-key__manual">
            <ElInput
              v-model="manualValue"
              type="password"
              show-password
              clearable
              :placeholder="placeholder"
              class="runtime-key__input"
            />
            <ElSpace wrap>
              <ElButton :disabled="!manualValue.trim()" @click="useManual">使用此密钥</ElButton>
              <ElButton link type="primary" @click="goSandIamApplication">去 SandIAM 应用</ElButton>
              <ElButton link type="primary" @click="goWorkloadClient">调用身份</ElButton>
            </ElSpace>
            <p class="runtime-key__hint">
              其他应用的调用密钥可在身份与授权插件（SandIAM）中生成；粘贴密钥本身即可，无需加前缀。
            </p>
          </div>
        </details>
      </template>
      <template v-else>
        <ElInput
          :model-value="modelValue"
          type="password"
          show-password
          clearable
          :placeholder="placeholder"
          class="runtime-key__input"
          @update:model-value="onInput"
          @change="persist"
        />
        <ElSpace wrap>
          <ElTag :type="identityRefusal ? 'danger' : configured ? 'info' : 'warning'" effect="plain">
            {{ identityRefusal?.status ?? (configured ? (persistInSession ? '已填写（本机会话，调用时验证）' : '已填写（仅本页面，调用时验证）') : '未填写') }}
          </ElTag>
          <ElButton :disabled="!configured" @click="clearKey">{{ identityRefusal?.requiresReplacement ? '清除并更换' : '清除' }}</ElButton>
          <ElButton v-if="trialEnabled" type="primary" plain :loading="trial.state.value === 'loading'" @click="retryTrial">
            {{ trialAvailable === false ? '重试自动获取' : '使用管理员试用身份' }}
          </ElButton>
          <ElButton link type="primary" @click="goSandIamApplication">去 SandIAM 应用</ElButton>
          <ElButton link type="primary" @click="goWorkloadClient">调用身份</ElButton>
        </ElSpace>
        <ElAlert
          v-if="trialEnabled && trial.failure.value"
          type="info"
          :title="trial.failure.value"
          :closable="false"
          show-icon
        />
        <ElAlert
          v-if="identityRefusal"
          type="error"
          :title="identityRefusal.status"
          :description="identityRefusal.description"
          :closable="false"
          show-icon
        />
        <p class="runtime-key__hint">
          请填写管理员在身份与授权插件（SandIAM）中生成的调用密钥，粘贴密钥本身即可，无需加前缀。填写后仍由每次调用验证身份与允许使用。
        </p>
      </template>
    </div>
  </ElFormItem>
</template>

<script setup lang="ts">
  import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
  import { useRouter } from 'vue-router'
  import { ElMessage } from 'element-plus'
  import { useUserStore } from '@/store/modules/user'
  import { clearRuntimeBearer, readRuntimeBearer, saveRuntimeBearer } from '../api/runtimeBearerSession'
  import { subscribeRuntimeIdentityRefusal, type RuntimeIdentityRefusal } from '../api/runtimeIdentityNotice'
  import { useAdminTrialBearer } from '../composables/useAdminTrialBearer'

  const props = withDefaults(
    defineProps<{
      modelValue: string
      placeholder?: string
      persistInSession?: boolean
      /** 提供时默认自动获取管理员试用身份；缺省保持手动填写。 */
      trialActions?: string[]
    }>(),
    {
      placeholder: '应用的调用密钥（仅保存在本机会话中）',
      persistInSession: true,
      trialActions: undefined
    }
  )

  const emit = defineEmits<{
    'update:modelValue': [value: string]
  }>()

  const router = useRouter()
  const userStore = useUserStore()

  const trialEnabled = computed(() => Array.isArray(props.trialActions) && props.trialActions.length > 0)
  const trial = useAdminTrialBearer(() => props.trialActions ?? [])
  const trialAvailable = ref<boolean | null>(null)
  const mode = ref<'auto' | 'manual'>(trialEnabled.value ? 'auto' : 'manual')
  const manualValue = ref('')

  const configured = computed(() => props.modelValue.trim().length > 0)
  const identityRefusal = ref<RuntimeIdentityRefusal | null>(null)
  let unsubscribeIdentityRefusal: (() => void) | null = null
  let latestIdentityRequestSequence = 0

  watch(() => props.modelValue.trim(), () => {
    identityRefusal.value = null
    latestIdentityRequestSequence = 0
  }, { flush: 'sync' })

  // The composable clears its bearer on failure/stop; in auto mode that must
  // also clear the field so callers cannot fire with a dead context.
  watch(trial.bearer, (value) => {
    if (mode.value === 'auto') emit('update:modelValue', value)
  })

  function onInput(value: string): void {
    emit('update:modelValue', value)
  }

  function persist(): void {
    if (props.persistInSession) saveRuntimeBearer(props.modelValue)
  }

  function useManual(): void {
    trial.stop()
    mode.value = 'manual'
    const value = manualValue.value.trim()
    manualValue.value = ''
    emit('update:modelValue', value)
    if (props.persistInSession) saveRuntimeBearer(value)
  }

  async function retryTrial(): Promise<void> {
    mode.value = 'auto'
    manualValue.value = ''
    const ok = await trial.refresh()
    trialAvailable.value = ok
    if (!ok) mode.value = 'manual'
  }

  function clearKey(): void {
    identityRefusal.value = null
    emit('update:modelValue', '')
    if (props.persistInSession) clearRuntimeBearer()
    ElMessage.success(props.persistInSession ? '已清除本机会话中的调用密钥' : '已清除本页面的调用密钥')
  }

  /** SAND-113E 已移除 /sand-ai/setup；身份与凭证入口在 SandIAM。 */
  function goSandIamApplication(): void {
    void router.push('/sand-iam/application')
  }

  function goWorkloadClient(): void {
    void router.push('/sand-iam/workload-client')
  }

  onMounted(() => {
    unsubscribeIdentityRefusal = subscribeRuntimeIdentityRefusal((bearer, refusal, requestSequence) => {
      // A late rejection for an old credential must not invalidate its replacement.
      // Earlier successful requests must not hide a newer identity refusal either.
      if (props.modelValue.trim() !== bearer || requestSequence < latestIdentityRequestSequence) return
      latestIdentityRequestSequence = requestSequence
      identityRefusal.value = refusal
    })
    if (trialEnabled.value) {
      void retryTrial()
      return
    }
    if (props.persistInSession && !props.modelValue) {
      const stored = readRuntimeBearer()
      if (stored) {
        emit('update:modelValue', stored)
      }
    }
  })
  onBeforeUnmount(() => { unsubscribeIdentityRefusal?.() })
  watch(() => userStore.info.id, () => {
    identityRefusal.value = null
    latestIdentityRequestSequence = 0
    if (trialEnabled.value) {
      // A different administrator must not inherit the previous admin's trial identity.
      trial.stop()
      if (mode.value === 'auto') void retryTrial()
      else emit('update:modelValue', '')
      return
    }
    if (!props.persistInSession) {
      emit('update:modelValue', '')
      return
    }
    const stored = readRuntimeBearer()
    if (props.modelValue !== stored) emit('update:modelValue', stored)
  }, { flush: 'sync' })
</script>

<style scoped lang="scss">
  .runtime-key {
    display: flex;
    flex-direction: column;
    gap: 8px;
    width: 100%;
    max-width: 640px;
  }

  .runtime-key__input {
    max-width: 520px;
  }

  .runtime-key__advanced {
    font-size: 13px;

    summary {
      cursor: pointer;
      color: var(--el-text-color-secondary);
    }
  }

  .runtime-key__manual {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin-top: 8px;
  }

  .runtime-key__hint {
    margin: 0;
    font-size: 12px;
    line-height: 1.5;
    color: var(--el-text-color-secondary);
  }
</style>
