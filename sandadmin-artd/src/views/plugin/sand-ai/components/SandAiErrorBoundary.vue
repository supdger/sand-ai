<template>
  <div v-if="fault" class="sand-ai-page sand-ai-boundary">
    <ElResult icon="error" title="系统出了点问题，请稍后再试。">
      <template #extra>
        <ElSpace direction="vertical" :size="12">
          <ElSpace wrap>
            <ElButton type="primary" @click="manualReload">重新加载</ElButton>
            <ElButton :loading="copying" @click="copyProblemInfo">复制问题信息</ElButton>
          </ElSpace>
          <p v-if="copyState === 'done'" class="sand-ai-boundary__note">已复制问题信息。</p>
          <p v-else-if="copyState === 'failed'" class="sand-ai-boundary__note">复制未完成，请点「重新加载」后再试。</p>
        </ElSpace>
      </template>
    </ElResult>
  </div>
  <slot v-else />
</template>

<script setup lang="ts">
  import { onBeforeUnmount, onErrorCaptured, ref } from 'vue'
  import {
    describeBoundaryFault,
    problemInfoText,
    registerFaultHandler,
    requestBoundedReload,
    type BoundaryFault
  } from '../composables/errorBoundary'

  const fault = ref<BoundaryFault | null>(null)
  const copyState = ref<'idle' | 'done' | 'failed'>('idle')
  const copying = ref(false)

  function raise(cause: unknown): void {
    if (fault.value) return
    const next = describeBoundaryFault(cause)
    fault.value = next
    // 资源/分包加载失败多为版本更新后的旧缓存，给一次有界自动重载；
    // 其余错误与超限情况只展示本页，由用户手动点「重新加载」。
    if (next.resource && requestBoundedReload()) {
      window.location.reload()
    }
  }

  // 页面级 errorHandler：捕获子树渲染/生命周期异常
  onErrorCaptured((cause) => {
    raise(cause)
    return false
  })

  // 全局陷阱：window error / unhandledrejection（含动态 import 与路由导航失败）
  const unregister = registerFaultHandler(raise)
  onBeforeUnmount(unregister)

  function manualReload(): void {
    window.location.reload()
  }

  async function copyProblemInfo(): Promise<void> {
    if (!fault.value) return
    copying.value = true
    try {
      const text = problemInfoText(fault.value)
      if (navigator.clipboard?.writeText) {
        await navigator.clipboard.writeText(text)
      } else {
        // 非安全上下文（http）退到选区复制
        const area = document.createElement('textarea')
        area.value = text
        area.style.position = 'fixed'
        area.style.opacity = '0'
        document.body.appendChild(area)
        area.select()
        document.execCommand('copy')
        area.remove()
      }
      copyState.value = 'done'
    } catch {
      copyState.value = 'failed'
    } finally {
      copying.value = false
    }
  }
</script>

<style scoped>
  .sand-ai-boundary {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 320px;
  }

  .sand-ai-boundary__note {
    margin: 0;
    font-size: 12px;
    color: var(--el-text-color-secondary, #909399);
  }
</style>
