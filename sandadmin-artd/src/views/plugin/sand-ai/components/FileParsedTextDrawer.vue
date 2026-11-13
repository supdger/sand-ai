<template>
  <ElDrawer
    :model-value="open"
    title="读出的文字"
    :size="'min(720px, 94vw)'"
    destroy-on-close
    @closed="handleClosed"
  >
    <div class="parsed-text">
      <p class="parsed-text__note">
        以下是从「{{ displayName }}」里读出的原文，每段最多 500 个字，不是 AI 整理后的内容。
      </p>
      <ElAlert
        v-if="!hasTarget"
        type="info"
        :closable="false"
        title="该文件没有可读取的解析记录，请先提交解析。"
      />
      <ElAlert v-else-if="errorText" type="warning" :closable="false" :title="errorText" />
      <p v-else-if="loading && !blocks.length" class="parsed-text__note">正在读取解析内容…</p>
      <template v-else>
        <p v-if="loaded && !blocks.length" class="parsed-text__note">该文件没有可显示的解析文字。</p>
        <div class="parsed-text__body">
          <article v-for="(block, index) in blocks" :key="block.source_block_id" class="parsed-text__block">
            <h4 class="parsed-text__heading">
              第 {{ index + 1 }} 段<span v-if="blockLocation(block)"> · {{ blockLocation(block) }}</span>
            </h4>
            <p class="parsed-text__content">{{ block.preview }}</p>
          </article>
        </div>
        <ElButton
          v-if="nextAfterId !== null"
          :loading="loading"
          :disabled="loading"
          @click="readPage(nextAfterId)"
        >继续读取更多内容</ElButton>
      </template>
    </div>
  </ElDrawer>
</template>

<script setup lang="ts">
  import { computed, onBeforeUnmount, ref, watch } from 'vue'
  import fileApi, { type FileSourceBlock } from '../api/file'
  import { errorDisplayText } from '../api/errorText'

  const props = defineProps<{
    open: boolean
    bearer: string
    fileId: number | null
    parseId: number | null
    fileName?: string
  }>()
  const emit = defineEmits<{
    (event: 'close'): void
    (event: 'access-denied', error: unknown): void
  }>()

  const blocks = ref<FileSourceBlock[]>([])
  const nextAfterId = ref<number | null>(null)
  const loading = ref(false)
  const loaded = ref(false)
  const errorText = ref('')
  let generation = 0
  let fileSha: string | null = null

  const displayName = computed(() => props.fileName?.trim() || '该文件')
  const hasTarget = computed(() => typeof props.fileId === 'number' && Number.isSafeInteger(props.fileId) && props.fileId > 0
    && typeof props.parseId === 'number' && Number.isSafeInteger(props.parseId) && props.parseId > 0)

  function reset(): void {
    ++generation
    blocks.value = []
    nextAfterId.value = null
    loading.value = false
    loaded.value = false
    errorText.value = ''
    fileSha = null
  }

  function blockLocation(block: FileSourceBlock): string {
    const locator = block.locator
    switch (block.locator_type) {
      case 'line': return `第 ${locator.line} 行`
      case 'page': return `第 ${locator.page} 页`
      case 'page_region': return `第 ${locator.page} 页`
      case 'paragraph': return `第 ${locator.paragraph} 段`
      case 'table_row': return `表格第 ${locator.row} 行`
      case 'audio_time_range': return `录音片段`
      default: return ''
    }
  }

  async function readPage(afterId?: number): Promise<void> {
    const fileId = props.fileId
    const parseId = props.parseId
    if (loading.value || typeof fileId !== 'number' || !Number.isSafeInteger(fileId) || fileId < 1
      || typeof parseId !== 'number' || !Number.isSafeInteger(parseId) || parseId < 1) return
    if (afterId !== undefined && afterId !== nextAfterId.value) return
    if (!props.bearer.trim()) {
      errorText.value = '当前调用身份尚未就绪，请稍后重试。'
      return
    }
    const requestGeneration = ++generation
    const bearer = props.bearer
    loading.value = true
    errorText.value = ''
    try {
      const page = await fileApi.readSourceBlocks(bearer, fileId, parseId, afterId)
      if (requestGeneration !== generation) return
      if (page.file.id !== fileId || page.file.parse_id !== parseId
        || (fileSha !== null && page.file.sha256 !== fileSha)
        || (afterId !== undefined && page.blocks.some(block => block.source_block_id <= afterId))) {
        throw new Error('解析内容与当前文件不一致，请关闭后重新打开。')
      }
      fileSha = page.file.sha256
      blocks.value = afterId === undefined ? page.blocks : [...blocks.value, ...page.blocks]
      nextAfterId.value = page.next_after_id
      loaded.value = true
    } catch (cause: unknown) {
      if (requestGeneration !== generation) return
      errorText.value = errorDisplayText(cause, '暂时无法读取解析内容，请稍后重试。')
      emit('access-denied', cause)
    } finally {
      if (requestGeneration === generation) loading.value = false
    }
  }

  function handleClosed(): void {
    reset()
    emit('close')
  }

  watch(() => props.open, (open) => {
    if (open) {
      reset()
      void readPage()
    }
  }, { flush: 'sync' })
  watch(() => [props.bearer, props.fileId, props.parseId], () => {
    if (props.open) {
      reset()
      void readPage()
    }
  }, { flush: 'sync' })
  onBeforeUnmount(reset)
</script>

<style scoped>
  .parsed-text {
    display: grid;
    gap: 12px;
    min-width: 0;
  }

  .parsed-text__note {
    margin: 0;
    font-size: 13px;
    color: var(--el-text-color-secondary);
  }

  .parsed-text__body {
    display: grid;
    gap: 12px;
  }

  .parsed-text__block {
    padding: 12px;
    border: 1px solid var(--el-border-color);
    border-radius: 4px;
  }

  .parsed-text__heading {
    margin: 0 0 8px;
    font-size: 13px;
    color: var(--el-text-color-secondary);
  }

  .parsed-text__content {
    margin: 0;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
  }

  .parsed-text > .el-button {
    justify-self: start;
  }
</style>
