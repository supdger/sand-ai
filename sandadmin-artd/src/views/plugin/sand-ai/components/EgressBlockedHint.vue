<template>
  <ElDialog
    :model-value="modelValue"
    :title="`连不上${serviceName}`"
    width="560px"
    align-center
    append-to-body
    @update:model-value="emit('update:modelValue', $event)"
  >
    <p class="egress-hint__text">你电脑上开着的代理（翻墙/加速软件）把这个服务的地址改掉了，系统为了安全把它拦了下来。</p>
    <p class="egress-hint__text">可以这样解决：</p>
    <ol class="egress-hint__steps">
      <li>在代理软件里把 {{ details.serviceHost }} 设为「直连」，或者先暂时关掉代理，再点〔重新测试〕；</li>
      <li>如果你是管理员，也可以把这个地址加入放行名单。</li>
    </ol>
    <template #footer>
      <ElButton type="primary" @click="emit('retry')">重新测试</ElButton>
      <ElButton :loading="copying" @click="copyProblemInfo">复制问题信息</ElButton>
      <ElButton v-permission="'sand_ai:provider:save'" @click="allowlistOpen = true">加入放行名单</ElButton>
    </template>
  </ElDialog>
  <ElDialog v-model="allowlistOpen" title="放行名单设置" width="560px" align-center append-to-body>
    <ElAlert type="warning" :closable="false" title="此设置会降低安全防护，只放行你信任的服务地址。" class="egress-hint__alert" />
    <p class="egress-hint__text">放行名单由宿主服务器管理员在运行环境配置 <code>SAND_AI_EGRESS_ALLOW_CIDRS</code>（逗号分隔的网段），保存后按宿主正常流程重新加载服务。</p>
    <p class="egress-hint__text">请只列入确有证据的最小网段；让代理软件返回真实地址、或把该服务设为「直连」，永远是首选路径，放行名单只是受控例外。</p>
    <p class="egress-hint__text">点〔复制问题信息〕把本次被拦截的网段交给宿主管理员，即可确定需要列入的放行名单。</p>
    <template #footer>
      <ElButton @click="allowlistOpen = false">知道了</ElButton>
    </template>
  </ElDialog>
</template>

<script setup lang="ts">
  import { ref } from 'vue'
  import { egressProblemInfo, type EgressBlockedDetails } from '../api/egressBlocked'

  const props = defineProps<{
    modelValue: boolean
    /** 服务商显示名，拼进标题「连不上{服务商名}」 */
    serviceName: string
    /** 后端返回的脱敏字段：serviceHost 可展示，blockedRange 仅进复制信息 */
    details: EgressBlockedDetails
  }>()
  const emit = defineEmits<{
    'update:modelValue': [value: boolean]
    retry: []
  }>()

  const copying = ref(false)
  const allowlistOpen = ref(false)

  async function copyProblemInfo(): Promise<void> {
    copying.value = true
    try {
      await navigator.clipboard?.writeText(egressProblemInfo(props.details))
    } catch (error) {
      console.error('[sand-ai] copy problem info failed', error)
    } finally {
      copying.value = false
    }
  }
</script>

<style scoped>
  .egress-hint__text {
    margin: 0 0 8px;
    line-height: 1.7;
  }

  .egress-hint__steps {
    margin: 0;
    padding-left: 20px;
    line-height: 1.8;
  }

  .egress-hint__alert {
    margin-bottom: 12px;
  }
</style>
