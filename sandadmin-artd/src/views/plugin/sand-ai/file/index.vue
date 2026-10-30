<template>
  <div class="art-full-height">
    <ElCard class="art-table-card" shadow="never">
      <div class="file-header">
        <div>
          <h2 class="file-title">文件中心</h2>
          <p class="file-desc">
            上传文档或录音后，可提交解析或转写任务。录音结果和费用在任务中心查看。
            清理进行中请刷新状态；清理失败可在原文件上重试，无需重新上传。
          </p>
        </div>
        <ElTag type="success" effect="plain">API 1.4 · runtime</ElTag>
      </div>

      <ElAlert v-for="fileId in pendingCleanupIds" :key="fileId" type="warning" :closable="false"
        :title="cleanupConflicts.has(fileId) ? `原文件 #${fileId} 的删除被资源占用阻止` : `原文件 #${fileId} 的清理结果尚未确认`" class="file-alert">
        <p>{{ cleanupConflicts.has(fileId) ? '请先处理占用并读取原文件状态。确认原文件尚未进入清理后，可重新确认删除。' : '请读取原文件状态。找不到或无权读取不表示清理完成；明确清理失败后才可在原文件上重试。' }}</p>
        <ElButton :disabled="Boolean(busy) || !apiKey.trim()" @click="refreshOne(fileId)">读取原文件清理状态</ElButton>
      </ElAlert>
      <ElForm label-width="110px" class="file-cred" @submit.prevent>
        <RuntimeApiKeyField
          v-model="apiKey"
          placeholder="Bearer 环境凭证（需 files:read / files:write）"
        />
        <ElFormItem label="按 id 查询">
          <ElSpace wrap>
            <ElInputNumber v-model="lookupId" :min="1" :controls="false" placeholder="file id" />
            <ElButton :loading="busy === 'read'" @click="handleLookup">查询</ElButton>
          </ElSpace>
        </ElFormItem>
        <ElFormItem label="上传文件">
          <PrivateFileUpload :bearer="apiKey" :disabled="busy !== ''"
            accept=".pdf,.docx,.txt,.csv,.xlsx,.png,.jpg,.jpeg,.webp,.mp3,.m4a,.ogg,.wav,.webm,.flac"
            @file="upsertFile" @busy="uploadBusy" @identity-denied="clearOnAccessDenied" />
        </ElFormItem>
      </ElForm>

      <details class="file-alert">
        <summary>长 TXT 来源与旧解析恢复</summary>
        <p class="file-desc">新 TXT 解析将长行分成来源块，保留原行及实际字符范围；更新不会改写旧解析或已发布知识版本。</p>
        <p class="file-desc">若已确认旧来源块超过模型或索引输入上限，请重新选择原 TXT 上传，取得新的文件号，再提交解析。解析结果不明时沿原请求号找回，失败只处理原任务；完成后到知识库配置选择新文件，使用新的知识库代码建立草稿、发布并准备索引。</p>
        <p class="file-desc">保留旧文件、已发布知识库及原索引记录。停用不会解除已发布来源引用；清理向量或解除来源引用不能用于重新解析并保留历史。</p>
        <ElButton :disabled="Boolean(busy)" @click="router.push('/sand-ai/knowledge')">继续建立新知识库</ElButton>
      </details>

      <ElDivider content-position="left">找回文件解析</ElDivider>
      <ElAlert v-if="parseRecoveryLocked" class="file-alert" type="warning" :closable="false"
        title="原解析尚未确认成功。请按原请求号只读找回；失败只重试原任务，取消后须核实未派发证据才能重新解析。" />
      <ElAlert v-for="fileId in pendingCleanupIds" :key="fileId" type="warning" :closable="false"
        :title="cleanupConflicts.has(fileId) ? `原文件 #${fileId} 的删除被资源占用阻止` : `原文件 #${fileId} 的清理结果尚未确认`" class="file-alert">
        <p>{{ cleanupConflicts.has(fileId) ? '请先处理占用并读取原文件状态。确认原文件尚未进入清理后，可重新确认删除。' : '请读取原文件状态。找不到或无权读取不表示清理完成；明确清理失败后才可在原文件上重试。' }}</p>
        <ElButton :disabled="Boolean(busy) || !apiKey.trim()" @click="refreshOne(fileId)">读取原文件清理状态</ElButton>
      </ElAlert>
      <ElForm label-width="110px" class="file-cred" @submit.prevent>
        <ElFormItem label="原文件号">
          <ElInputNumber v-model="parseLookupFileId" :min="1" :controls="false"
            :disabled="Boolean(busy) || !!pendingParseSubmit" />
        </ElFormItem>
        <ElFormItem label="原解析请求号">
          <ElInput v-model.trim="parseLookupKey" maxlength="96"
            :disabled="Boolean(busy) || !!pendingParseSubmit" placeholder="原提交解析时的 X-Request-Id" />
        </ElFormItem>
        <ElFormItem>
          <ElSpace wrap>
            <ElButton :disabled="Boolean(busy) || !apiKey.trim() || !parseLookupFileId || !parseLookupKey"
              :loading="busy === 'parse-recovery'" @click="readOriginalParse(false)">按原请求只读找回</ElButton>
            <ElButton v-if="pendingParseSubmit?.taskId" :disabled="Boolean(busy) || !apiKey.trim()"
              @click="readOriginalParse(true)">刷新原解析任务</ElButton>
            <ElButton v-if="parseRecoveredTask" :disabled="Boolean(busy)" @click="viewRecoveredParse">查看原任务与来源</ElButton>
            <ElButton v-if="parseRecoveredTask?.state === 'failed'"
              :disabled="Boolean(busy) || !apiKey.trim() || !canRetryOriginalParse(parseRecoveredTask)" @click="retryOriginalParse">重试原解析任务</ElButton>
            <ElButton v-if="canRestartCanceledParse(parseRecoveredTask)"
              :disabled="Boolean(busy) || !apiKey.trim()" @click="restartCanceledParse()">重新解析原文件</ElButton>
            <ElTag v-if="parseRecoveredTask">原任务 #{{ parseRecoveredTask.id }} · {{ parseRecoveredTask.state }}</ElTag>
          </ElSpace>
        </ElFormItem>
        <p v-if="parseRecoveredTask?.state === 'canceled'" class="file-desc">已取消不代表没有费用。有已发送或结果不明的 OCR 请求时，保留原任务核对，不重新解析。</p>
        <p v-if="parseRecoveredTask?.state === 'failed' && !canRetryOriginalParse(parseRecoveredTask)" class="file-desc">原解析的重试资格尚未确认、次数已耗尽或需要当前授权。请到原任务查看正式恢复入口；不重新上传或创建解析。</p>
        <p class="file-desc">请求号在发送前保存。未找到、无权限或网络断开时保留原号；重试资格和外部派发由原任务规则确认。</p>
      </ElForm>

      <ElDivider content-position="left">只读查询已有解析</ElDivider>
      <p class="file-desc">填写已知的原文件号和原解析请求号。查询另一项解析不会替换本页待确认的请求，也不会重新解析文件。</p>
      <ElForm label-width="110px" class="file-cred" @submit.prevent>
        <ElFormItem label="原文件号">
          <ElInputNumber v-model="parseReaderFileId" :min="1" :precision="0" :controls="false" :disabled="parseReaderBusy" />
        </ElFormItem>
        <ElFormItem label="原解析请求号">
          <ElInput v-model.trim="parseReaderKey" maxlength="96" :disabled="parseReaderBusy"
            placeholder="原解析的 X-Request-Id" />
        </ElFormItem>
        <ElFormItem>
          <ElButton :loading="parseReaderBusy"
            :disabled="parseReaderBusy || !apiKey.trim() || !parseReaderFileId || !parseReaderKey"
            @click="readKnownParse">只读查询原解析</ElButton>
        </ElFormItem>
      </ElForm>
      <ElAlert v-if="parseReaderError" type="warning" :closable="false" class="file-alert" :title="parseReaderError" />
      <section v-if="parseReaderTarget && parseReaderResult" class="file-alert">
        <p>原文件 #{{ parseReaderTarget.fileId }} · 原任务 #{{ parseReaderResult.id }} · {{ parseReaderResult.state }}。</p>
        <p v-if="parseReaderResult.file_parse_id">解析记录 #{{ parseReaderResult.file_parse_id }}。</p>
        <p v-if="parseReaderResult.error_code">错误码：{{ parseReaderResult.error_code }}。</p>
        <p class="file-desc">此结果未提供费用金额。请按原任务号查看成果、来源与实际费用记录；解析请求号不是费用记录号。</p>
        <ElButton link type="primary" @click="viewKnownParse">查看原任务、成果与费用</ElButton>
      </section>

      <ElDivider content-position="left">找回录音转写</ElDivider>
      <ElAlert v-if="audioRecoveryStorageError" class="file-alert" type="error" :closable="false"
        :title="audioRecoveryStorageError">
        <ElButton :disabled="Boolean(busy)" @click="restoreAudioRecovery">重新读取本地原号</ElButton>
      </ElAlert>
      <ElAlert v-if="audioRecoveryLocked" class="file-alert" type="warning" :closable="false"
        title="原转写请求尚未确认完成，请保留原请求键只读找回。修改参数、文件或凭证不会重新提交。" />
      <ElAlert v-for="fileId in pendingCleanupIds" :key="fileId" type="warning" :closable="false"
        :title="cleanupConflicts.has(fileId) ? `原文件 #${fileId} 的删除被资源占用阻止` : `原文件 #${fileId} 的清理结果尚未确认`" class="file-alert">
        <p>{{ cleanupConflicts.has(fileId) ? '请先处理占用并读取原文件状态。确认原文件尚未进入清理后，可重新确认删除。' : '请读取原文件状态。找不到或无权读取不表示清理完成；明确清理失败后才可在原文件上重试。' }}</p>
        <ElButton :disabled="Boolean(busy) || !apiKey.trim()" @click="refreshOne(fileId)">读取原文件清理状态</ElButton>
      </ElAlert>
      <ElForm label-width="110px" class="file-cred" @submit.prevent>
        <ElFormItem label="原录音文件号">
          <ElInputNumber v-model="audioLookupFileId" :min="1" :controls="false"
            :disabled="Boolean(busy) || !!pendingAudioSubmit" />
        </ElFormItem>
        <ElFormItem label="原转写请求键">
          <ElInput v-model.trim="audioLookupKey" maxlength="512"
            :disabled="Boolean(busy) || !!pendingAudioSubmit"
            placeholder="原提交转写时的 Idempotency-Key" />
        </ElFormItem>
        <ElFormItem>
          <ElSpace wrap>
            <ElButton :loading="busy === 'audio-recovery'"
              :disabled="Boolean(busy) || !apiKey.trim() || !audioLookupFileId || !audioLookupKey.trim()"
              @click="recoverAudio">按原请求只读找回</ElButton>
            <ElButton v-if="pendingAudioSubmit?.taskId" :disabled="Boolean(busy) || !apiKey.trim()"
              @click="recoverKnownAudio">读取原转写任务</ElButton>
            <ElButton v-if="audioRecoveredTask" :disabled="Boolean(busy)" @click="viewRecoveredAudio">
              查看原任务与恢复操作
            </ElButton>
            <ElTag v-if="audioRecoveredTask">原任务 #{{ audioRecoveredTask.id }} · {{ audioRecoveredTask.state }}</ElTag>
          </ElSpace>
        </ElFormItem>
        <p v-if="audioRecoveredTask?.state === 'succeeded'" class="file-desc">
          原转写已完成，原任务号和请求键仍保留。可以选择录音明确提交另一项转写。
        </p>
        <p v-else-if="audioRecoveredTask && isTerminal(audioRecoveredTask.state)" class="file-desc">
          原任务已结束；失败或取消不证明没有外部调用。请在原任务中查看费用和可用恢复操作。
        </p>
      </ElForm>

      <ElAlert
        v-if="lastError"
        class="file-alert"
        type="error"
        :closable="true"
        :title="lastError.title"
        :description="lastError.description"
        @close="lastError = null"
      />

      <ArtTable row-key="id" :loading="busy !== ''" :data="recentFiles" :columns="columns" />
    </ElCard>

    <ElDialog v-model="cleanupDialogOpen" title="文件清理状态" width="640px">
      <template v-if="cleanupFile">
        <p>原文件 #{{ cleanupFile.id }} · {{ fileStateLabel(cleanupFile.state) }}</p>
        <ElButton :disabled="Boolean(busy) || !apiKey.trim()" @click="refreshOne(cleanupFile.id)">刷新原文件清理状态</ElButton>
        <ElDescriptions :column="1" border>
          <ElDescriptionsItem label="清理状态">{{ cleanupStateLabel(cleanupFile.cleanup?.state) }}</ElDescriptionsItem>
          <ElDescriptionsItem label="完成时间">{{ cleanupFile.cleanup?.completed_at ?? '未确认' }}</ElDescriptionsItem>
          <ElDescriptionsItem label="来源内容">{{ cleanupFile.cleanup ? (cleanupFile.cleanup.source_available ? '清理未阻断，仍需来源授权与解析' : '此视图不可用') : '未确认' }}</ElDescriptionsItem>
          <ElDescriptionsItem v-for="resource in visibleCleanupResourceLabels" :key="resource.key" :label="resource.label">{{ resource.key === 'audio_chunks' && cleanupFile.cleanup?.resources.audio_chunks === 'pending' ? '待清理' : cleanupStateLabel(cleanupFile.cleanup?.resources[resource.key]) }}</ElDescriptionsItem>
          <ElDescriptionsItem label="已清理来源块数">{{ cleanupFile.cleanup?.source_block_count ?? '未确认' }}</ElDescriptionsItem>
          <ElDescriptionsItem label="已清理解析记录数">{{ cleanupFile.cleanup?.parse_record_count ?? '未确认' }}</ElDescriptionsItem>
        </ElDescriptions>
        <p class="file-desc">这里只读取安全元数据。文件删除不负责知识库向量清理；向量按独立知识库状态显示，未确认不表示已清理。费用与最小历史保留不表示已计价或零费用。</p>
      </template>
      <p v-else class="file-desc">原文件元数据暂不可读；找不到或无权读取不表示清理完成。请保留原文件号只读找回。</p>
    </ElDialog>

    <ElDialog v-model="audioDialogVisible" title="提交录音转写" width="520px">
      <ElForm label-width="100px" @submit.prevent>
        <ElFormItem label="录音文件">
          <span>{{ selectedAudioFile?.original_name }}</span>
        </ElFormItem>
        <ElFormItem label="识别语言">
          <ElSelect v-model="audioLanguage" :disabled="Boolean(busy) || audioRecoveryLocked">
            <ElOption label="自动识别" value="auto" />
            <ElOption label="中文" value="zh" />
            <ElOption label="英语" value="en" />
          </ElSelect>
        </ElFormItem>
        <ElFormItem label="区分说话人">
          <ElSwitch v-model="speakerDiarization" :disabled="Boolean(busy) || audioRecoveryLocked" />
        </ElFormItem>
        <p class="file-desc">
          自动区分说话人需要已配置的识别模型支持；本地 Whisper 不支持此选项。
          关闭后仍可在转写结果中回听、修订文字并人工标注说话人。
        </p>
        <ElFormItem label="专有词汇">
          <ElInput v-model="audioGlossary" type="textarea" :rows="3" :disabled="Boolean(busy) || audioRecoveryLocked" placeholder="每行一个词，最多 100 个" />
        </ElFormItem>
        <ElFormItem label="数据处理">
          <ElSelect v-model="audioEgress" :disabled="Boolean(busy) || audioRecoveryLocked">
            <ElOption label="仅本地处理" value="local_only" />
            <ElOption label="允许已批准的云端处理" value="approved_cloud" />
          </ElSelect>
        </ElFormItem>
        <ElAlert
          :title="audioEgress === 'approved_cloud'
            ? '允许已批准的云端能力处理，也可由已配置的本地能力转写。完成复核后可生成会议摘要。'
            : '录音仅在本地处理；当前会议摘要功能需要选择已批准的云端处理。'"
          type="info"
          :closable="false"
        />
      </ElForm>
      <template #footer>
        <ElButton @click="audioDialogVisible = false">取消</ElButton>
        <ElButton type="primary" :loading="busy === 'audio'" :disabled="Boolean(busy) || audioRecoveryLocked || (!pendingAudioSubmit && !!audioLookupKey.trim())" @click="handleAudioSubmit">提交转写</ElButton>
      </template>
    </ElDialog>
  </div>
</template>

<script setup lang="ts">
  import { computed, h, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
  import { useRouter } from 'vue-router'
  import { ElButton, ElMessage, ElMessageBox, ElSpace } from 'element-plus'
  import type { ColumnOption } from '@/types'
  import { useUserStore } from '@/store/modules/user'
  import fileApi from '../api/file'
  import audioTranscriptionApi, { type AudioTranscriptionRequest } from '../api/audioTranscription'
  import taskApi from '../api/task'
  import { readRuntimeBearer } from '../api/runtimeBearerSession'
  import { SandAiRuntimeError, newRuntimeRequestId } from '../api/runtime'
  import { hintForRuntimeErrorCode, toRuntimeUiError } from '../api/runtimeErrors'
  import type { RuntimeUiError } from '../api/runtimeErrors'
  import type { SandAiCleanupState, SandAiCleanupStatus, SandAiFile, SandAiTask } from '../api/types'
  import RuntimeApiKeyField from '../components/RuntimeApiKeyField.vue'
  import PrivateFileUpload from '../components/PrivateFileUpload.vue'

  const router = useRouter()
  const userStore = useUserStore()
  const apiKey = ref('')
  let viewGeneration = 0
  const lookupId = ref<number | undefined>(undefined)
  const recentFiles = ref<SandAiFile[]>([])
  const cleanupFileId = ref<number | null>(null)
  const cleanupFile = computed(() => recentFiles.value.find(file => file.id === cleanupFileId.value))
  const cleanupDialogOpen = computed({ get: () => cleanupFileId.value !== null, set: (value: boolean) => { if (!value) cleanupFileId.value = null } })
  const lastTaskByFile = ref<Record<number, SandAiTask>>({})
  const busy = ref<'upload' | 'read' | 'parse' | 'audio' | 'audio-recovery' | 'parse-recovery' | 'delete' | ''>('')
  const lastError = ref<RuntimeUiError | null>(null)
  const audioDialogVisible = ref(false)
  const selectedAudioFile = ref<SandAiFile | null>(null)
  const audioLanguage = ref('auto')
  const speakerDiarization = ref(false)
  const audioGlossary = ref('')
  const audioEgress = ref<'local_only' | 'approved_cloud'>('local_only')
  type AudioRecovery = { fileId: number; key: string; taskId: number | null }
  const pendingAudioSubmit = ref<AudioRecovery | null>(null)
  const audioLookupFileId = ref<number | null>(null)
  const audioLookupKey = ref('')
  const audioRecoveredTask = ref<SandAiTask | null>(null)
  const audioRecoveryStorageError = ref('')
  const audioRecoveryLocked = computed(() =>
    Boolean(audioRecoveryStorageError.value)
      || (pendingAudioSubmit.value !== null && audioRecoveredTask.value?.state !== 'succeeded')
  )
  const AUDIO_RECOVERY_STORAGE = 'sand-ai-audio-transcription-recovery'
  type ParseRecovery = { fileId: number; key: string; taskId: number | null }
  const pendingParseSubmit = ref<ParseRecovery | null>(null)
  const parseLookupFileId = ref<number | null>(null)
  const parseLookupKey = ref('')
  const parseRecoveredTask = ref<SandAiTask | null>(null)
  const parseRecoveryLocked = computed(() => pendingParseSubmit.value !== null && parseRecoveredTask.value?.state !== 'succeeded')
  const PARSE_RECOVERY_STORAGE = 'sand-ai-file-parse-recovery'
  const parseReaderFileId = ref<number | undefined>(undefined)
  const parseReaderKey = ref('')
  const parseReaderBusy = ref(false)
  const parseReaderError = ref('')
  const parseReaderTarget = ref<{ fileId: number; key: string } | null>(null)
  const parseReaderResult = ref<SandAiTask | null>(null)
  let parseReaderGeneration = 0
  const pendingCleanup = reactive(new Set<number>())
  const cleanupFlights = reactive(new Set<number>())
  const cleanupConflicts = reactive(new Map<number, string>())
  const pendingCleanupIds = computed(() => [...pendingCleanup])
  const fileEpochs = new Map<number, number>()
  let cleanupActorId = userStore.info.id

  function cleanupStorageKey(fileId: number): string {
    return `sand-ai-file-cleanup-pending:${userStore.info.id}:${fileId}`
  }

  function restoreCleanup(fileId: number): void {
    try {
      const saved = sessionStorage.getItem(cleanupStorageKey(fileId))
      if (saved) pendingCleanup.add(fileId)
      const conflict = saved?.match(/^conflict:([a-f0-9]{64})$/)
      if (conflict) cleanupConflicts.set(fileId, conflict[1])
    } catch { /* Existing memory locks remain when storage is unavailable. */ }
  }

  function restoreCleanupRecords(): void {
    try {
      const prefix = `sand-ai-file-cleanup-pending:${userStore.info.id}:`
      for (let index = 0; index < sessionStorage.length; index++) {
        const key = sessionStorage.key(index)
        if (!key?.startsWith(prefix)) continue
        const raw = key.slice(prefix.length)
        const id = /^[1-9][0-9]*$/.test(raw) ? Number(raw) : 0
        if (Number.isSafeInteger(id) && id > 0) restoreCleanup(id)
      }
    } catch { /* Original identifiers entered by the user still restore their memory lock. */ }
  }

  function retainCleanup(fileId: number, pending: boolean): boolean {
    try {
      // Persist only the opaque original file identifier, never a credential or file content.
      if (pending) {
        sessionStorage.setItem(cleanupStorageKey(fileId), 'pending')
        pendingCleanup.add(fileId)
        cleanupConflicts.delete(fileId)
      } else {
        sessionStorage.removeItem(cleanupStorageKey(fileId))
        pendingCleanup.delete(fileId)
        cleanupConflicts.delete(fileId)
      }
      return true
    } catch {
      if (!pending) pendingCleanup.add(fileId)
      ElMessage.warning('清理原号记录无法更新；已存在的原锁保留，请恢复会话存储后只读刷新')
      return false
    }
  }

  function retainCleanupConflict(fileId: number, sha256: string): void {
    if (!/^[a-f0-9]{64}$/.test(sha256)) return
    try {
      // A definite conflict is not completion. Retain the original identity and
      // content digest until an authorized GET confirms it never entered cleanup.
      sessionStorage.setItem(cleanupStorageKey(fileId), `conflict:${sha256}`)
      cleanupConflicts.set(fileId, sha256)
    } catch {
      // Keep the unknown-result lock if refusal evidence cannot be retained.
      ElMessage.warning('资源冲突记录无法保存；原清理锁保留，请恢复会话存储后读取原文件状态')
    }
  }

  function nextFileEpoch(fileId: number): number {
    const epoch = (fileEpochs.get(fileId) ?? 0) + 1
    fileEpochs.set(fileId, epoch)
    return epoch
  }

  function clearFilePrivateState(fileId: number): void {
    recentFiles.value = recentFiles.value.map((file) => file.id === fileId ? { ...file, latest_parse: null } : file)
    delete lastTaskByFile.value[fileId]
    if (selectedAudioFile.value?.id === fileId) {
      selectedAudioFile.value = null
      audioDialogVisible.value = false
      audioGlossary.value = ''
    }
    if (audioRecoveredTask.value?.resource_id === fileId) audioRecoveredTask.value = null
    if (parseRecoveredTask.value?.resource_id === fileId) parseRecoveredTask.value = null
    if (parseReaderTarget.value?.fileId === fileId) {
      ++parseReaderGeneration
      parseReaderBusy.value = false
      parseReaderResult.value = null
    }
  }

  function clearProtectedFileView(): void {
    const retainedIds = cleanupActorId === userStore.info.id ? [...pendingCleanup] : []
    const retainedConflicts = cleanupActorId === userStore.info.id ? [...cleanupConflicts] : []
    cleanupActorId = userStore.info.id
    ++viewGeneration
    ++parseReaderGeneration
    parseReaderBusy.value = false
    parseReaderTarget.value = null
    parseReaderResult.value = null
    parseReaderError.value = ''
    parseReaderFileId.value = undefined
    parseReaderKey.value = ''
    fileEpochs.clear()
    recentFiles.value = []
    cleanupFileId.value = null
    lastTaskByFile.value = {}
    pendingCleanup.clear()
    cleanupConflicts.clear()
    selectedAudioFile.value = null
    audioRecoveredTask.value = null
    parseRecoveredTask.value = null
    audioDialogVisible.value = false
    audioGlossary.value = ''
    audioLanguage.value = 'auto'
    speakerDiarization.value = false
    busy.value = ''
    lastError.value = null
    retainedIds.forEach(fileId => pendingCleanup.add(fileId))
    retainedConflicts.forEach(([fileId, sha256]) => cleanupConflicts.set(fileId, sha256))
    restoreCleanupRecords()
  }

  function clearOnAccessDenied(error: unknown): boolean {
    if (!(error instanceof SandAiRuntimeError) || ![401, 403].includes(error.httpStatus)) return false
    const originalIds = [...pendingCleanup]
    clearProtectedFileView()
    originalIds.forEach((fileId) => pendingCleanup.add(fileId))
    lastError.value = toUiError(error)
    return true
  }

  const audioExtensions = new Set(['mp3', 'm4a', 'ogg', 'wav', 'webm', 'flac'])

  const cleanupResourceLabels: readonly { key: keyof SandAiCleanupStatus['resources']; label: string }[] = [
    { key: 'file', label: '原文件' }, { key: 'derived_text', label: '派生文本' },
    { key: 'vectors', label: '知识库向量' }, { key: 'audio_chunks', label: '私有音频分片' }, { key: 'results', label: '成果内容' },
    { key: 'usage_history', label: '用量与费用历史' }, { key: 'audit_history', label: '最小审计历史' }
  ]
  const visibleCleanupResourceLabels = computed(() => cleanupResourceLabels.filter(resource => resource.key !== 'audio_chunks'
    || cleanupFile.value?.cleanup?.resources.audio_chunks !== undefined))
  function cleanupStateLabel(state: SandAiCleanupState | 'not_applicable' | undefined): string {
    return state === 'retained' ? '保留' : state === 'pending' ? '清理中' : state === 'failed' ? '清理失败'
      : state === 'purged' ? '已清理' : state === 'not_applicable' ? '无向量资源需清理' : '未确认'
  }
  function showCleanup(fileId: number): void {
    cleanupFileId.value = fileId
    void refreshOne(fileId)
  }

  const columns: ColumnOption<SandAiFile>[] = [
    { prop: 'id', label: 'ID', width: 80, align: 'center' },
    { prop: 'original_name', label: '文件名', minWidth: 160 },
    { prop: 'extension', label: '扩展名', width: 90 },
    { prop: 'media_type', label: 'MIME', minWidth: 140 },
    { prop: 'size_bytes', label: '大小', width: 100 },
    { prop: 'state', label: '文件状态', width: 110, formatter: (row: SandAiFile) => fileStateLabel(row.state) },
    { prop: 'cleanup', label: '清理状态', width: 120, formatter: (row: SandAiFile) => h(ElButton, { size: 'small', link: true, disabled: Boolean(busy.value), onClick: () => showCleanup(row.id) }, () => row.cleanup ? cleanupStateLabel(row.cleanup.state) : '查看清理状态') },
    {
      prop: 'latest_parse',
      label: '解析摘要',
      minWidth: 220,
      formatter: (row: SandAiFile) => formatParse(row)
    },
    {
      prop: 'task',
      label: '任务',
      width: 140,
      formatter: (row: SandAiFile) => {
        const task = lastTaskByFile.value[row.id] ?? row.latest_parse?.task
        if (!task || pendingCleanup.has(row.id) || isCleanupState(row.state)) return '—'
        return `#${task.id} · ${task.state}`
      }
    },
    {
      prop: 'actions',
      label: '操作',
      width: 340,
      fixed: 'right',
      formatter: (row: SandAiFile) =>
        h(ElSpace, { wrap: true }, () => [
          h(
            ElButton,
            { size: 'small', disabled: Boolean(busy.value), loading: busy.value === 'read', onClick: () => void refreshOne(row.id) },
            () => '刷新'
          ),
          h(
            ElButton,
            {
              size: 'small',
              type: 'primary',
              disabled: busy.value !== '' || pendingCleanup.has(row.id) || parseRecoveryLocked.value || (!!parseLookupKey.value.trim() && !pendingParseSubmit.value) || audioExtensions.has(row.extension) || isCleanupState(row.state),
              loading: busy.value === 'parse',
              onClick: () => void handleParse(row.id)
            },
            () => row.state === 'parse_canceled' ? '核对取消后重新解析' : row.state === 'parse_failed' ? '继续原解析任务' : '提交解析'
          ),
          ...(audioExtensions.has(row.extension)
            ? [h(
                ElButton,
                {
                  size: 'small',
                  type: 'primary',
                  disabled: busy.value !== '' || pendingCleanup.has(row.id) || row.state !== 'uploaded' || !row.file_ref,
                  loading: busy.value === 'audio',
                  onClick: () => openAudioDialog(row)
                },
                () => '转写录音'
              )]
            : []),
          h(
            ElButton,
            {
              size: 'small',
              disabled: pendingCleanup.has(row.id) || isCleanupState(row.state) || !(lastTaskByFile.value[row.id] ?? row.latest_parse?.task),
              onClick: () => goTask(row.id)
            },
            () => '看任务'
          ),
          h(
            ElButton,
            {
              size: 'small',
              type: 'danger',
              loading: busy.value === 'delete',
              disabled: busy.value !== '' || pendingCleanup.has(row.id) || cleanupFlights.has(row.id) || row.state === 'deleting' || row.state === 'deleted',
              onClick: () => void handleDelete(row.id)
            },
            () => row.state === 'deleted' ? '已删除' : pendingCleanup.has(row.id) ? '清理尚待确认' : row.state === 'delete_failed' ? '重试清理' : row.state === 'deleting' ? '清理进行中' : '删除'
          )
        ])
    }
  ]

  function upsertFile(file: SandAiFile): void {
    restoreCleanup(file.id)
    if (isCleanupState(file.state)) clearFilePrivateState(file.id)
    // Only an authorized explicit failed-cleanup state permits the original-file retry.
    if (file.state === 'delete_failed' || file.cleanup?.state === 'purged') retainCleanup(file.id, false)
    else if (file.state === 'deleted') { pendingCleanup.add(file.id); retainCleanup(file.id, true) }
    const next = recentFiles.value.filter((item) => item.id !== file.id)
    if (file.state === 'deleted') {
      const { file_ref: discardedFileRef, ...metadata } = file
      void discardedFileRef
      next.unshift({ ...metadata, latest_parse: null })
    } else next.unshift(file)
    recentFiles.value = next
  }

  function isCleanupState(state: SandAiFile['state']): boolean {
    return state === 'deleting' || state === 'delete_failed' || state === 'deleted'
  }

  function fileStateLabel(state: SandAiFile['state']): string {
    const labels: Record<string, string> = {
      uploaded: '已上传', available: '可用', parsing: '解析中', parsed: '已解析',
      parse_failed: '解析失败', parse_canceled: '解析已取消', failed: '失败',
      deleting: '清理进行中', delete_failed: '清理失败', deleted: '已删除'
    }
    return labels[state] ?? state
  }

  function formatParse(row: SandAiFile): string {
    if (pendingCleanup.has(row.id)) return '清理尚待确认，仅读取原文件状态'
    if (row.state === 'deleting') return '正在清理，刷新查看进度'
    if (row.state === 'delete_failed') return '清理未完成，可重试清理'
    const parse = row.latest_parse
    if (!parse) return '—'
    const err = parse.error_code ? ` · ${parse.error_code}` : ''
    return `${parse.state} · ${parse.parser_code}@${parse.parser_version} · 来源块 ${parse.source_block_count}${err}`
  }

  function toUiError(error: unknown): RuntimeUiError {
    return toRuntimeUiError(error)
  }

  function uploadBusy(value: boolean): void {
    if (value) busy.value = 'upload'
    else if (busy.value === 'upload') busy.value = ''
  }

  async function handleLookup(): Promise<void> {
    if (!lookupId.value) {
      ElMessage.warning('请输入 file id')
      return
    }
    await refreshOne(lookupId.value)
  }

  async function refreshOne(fileId: number): Promise<void> {
    if (busy.value) return
    restoreCleanup(fileId)
    const epoch = nextFileEpoch(fileId)
    const current = viewGeneration
    busy.value = 'read'
    lastError.value = null
    try {
      const file = await fileApi.read(apiKey.value, fileId)
      if (current !== viewGeneration || fileEpochs.get(fileId) !== epoch) return
      if (file.id !== fileId) throw new Error('读取结果与原文件标识不一致')
      const refusedBeforeCleanup = cleanupConflicts.get(fileId) === file.sha256
        && ['uploaded', 'parsing', 'parsed', 'parse_failed', 'parse_canceled'].includes(file.state)
      if (refusedBeforeCleanup && retainCleanup(fileId, false)) {
        ElMessage.info('已确认原文件尚未进入清理；处理资源占用后，可在原文件上重新确认删除')
      }
      upsertFile(file)
      if (pendingCleanup.has(fileId)) {
        ElMessage.info(file.cleanup?.state === 'pending' ? '原文件相关资源仍待清理，请继续只读刷新原文件' : file.cleanup?.state === 'purged' ? '原文件清理已完成，原号会话记录仍待同步，请只读刷新' : '原整体清理结果尚未确认，请保留原文件标识')
      }
    } catch (error) {
      if (current !== viewGeneration || fileEpochs.get(fileId) !== epoch || clearOnAccessDenied(error)) return
      if (error instanceof SandAiRuntimeError && error.httpStatus === 404) {
        // Not found may also be a denied or changed source; it never proves DELETE completion.
        recentFiles.value = recentFiles.value.filter((item) => item.id !== fileId)
        clearFilePrivateState(fileId)
      }
      const detail = toUiError(error)
      lastError.value = pendingCleanup.has(fileId) ? {
        ...detail, description: `${detail.description || detail.title}。原文件 #${fileId} 清理结果仍未确认；不可读不表示清理已完成，请保留原文件标识。`
      } : detail
    } finally {
      if (current === viewGeneration && fileEpochs.get(fileId) === epoch) busy.value = ''
    }
  }

  function goTask(fileId: number): void {
    const row = recentFiles.value.find((file) => file.id === fileId)
    const task = lastTaskByFile.value[fileId] ?? row?.latest_parse?.task
    if (!task) return
    void router.push({ path: '/sand-ai/task', query: { id: String(task.id) } })
  }

  function openAudioDialog(file: SandAiFile): void {
    if (busy.value || audioRecoveryLocked.value || pendingCleanup.has(file.id) || isCleanupState(file.state) || file.cleanup?.source_available === false) {
      ElMessage.warning('请先只读找回原转写请求；未确认前不能新建转写')
      return
    }
    if (selectedAudioFile.value?.id !== file.id) {
      audioLanguage.value = 'auto'
      speakerDiarization.value = false
      audioGlossary.value = ''
      audioEgress.value = 'local_only'
    }
    selectedAudioFile.value = file
    audioDialogVisible.value = true
  }

  async function handleAudioSubmit(): Promise<void> {
    if (busy.value || audioRecoveryLocked.value || (!pendingAudioSubmit.value && audioLookupKey.value.trim())) {
      ElMessage.warning('请先只读找回原转写请求；未确认前不能新建转写')
      return
    }
    const file = selectedAudioFile.value
    if (!file?.file_ref || isCleanupState(file.state) || file.cleanup?.source_available === false) return
    const glossary = audioGlossary.value.split(/\r?\n/).map((term) => term.trim()).filter(Boolean)
    if (glossary.length > 100 || new Set(glossary).size !== glossary.length || glossary.some((term) => [...term].length > 80)) {
      ElMessage.warning('专有词汇不能重复，最多 100 个，每个不超过 80 个字符')
      return
    }
    const request: AudioTranscriptionRequest = {
      schema: { code: 'sand_ai.audio_transcription_request', version: '1.0.0' },
      file_ref: file.file_ref,
      language: audioLanguage.value,
      speaker_diarization: speakerDiarization.value,
      glossary,
      data_egress_policy: audioEgress.value
    }
    const key = newRuntimeRequestId('audio')
    // A verified durable original key is required before sending the first request.
    if (!retainAudioRecovery({ fileId: file.id, key, taskId: null }, true)) {
      ElMessage.error('原转写请求号未能可靠保存，本次尚未发送。请恢复浏览器存储后重新读取本地原号。')
      return
    }
    audioRecoveredTask.value = null
    const current = viewGeneration
    busy.value = 'audio'
    lastError.value = null
    try {
      const submitted = await audioTranscriptionApi.submit(apiKey.value, request, key)
      if (current !== viewGeneration) return
      if (submitted.task.task_type !== 'audio_transcription'
        || submitted.task.resource_type !== 'audio_transcription'
        || submitted.task.resource_id !== submitted.task.id
        || !Number.isSafeInteger(submitted.task.id) || submitted.task.id < 1) {
        throw new Error('转写提交未返回有效任务号，请保留原请求键只读找回')
      }
      retainAudioRecovery({ fileId: file.id, key, taskId: submitted.task.id })
      audioRecoveredTask.value = submitted.task
      lastTaskByFile.value = { ...lastTaskByFile.value, [file.id]: submitted.task }
      audioDialogVisible.value = false
      ElMessage.success(`已提交转写任务 #${submitted.task.id}`)
      goTask(file.id)
    } catch (error) {
      if (current === viewGeneration && !clearOnAccessDenied(error)) {
        lastError.value = toUiError(error)
        ElMessage.error(lastError.value.description || lastError.value.title)
      }
    } finally {
      if (current === viewGeneration) busy.value = ''
    }
  }

  function isTerminal(state: string): boolean {
    return state === 'succeeded' || state === 'failed' || state === 'canceled'
  }

  function retainAudioRecovery(value: AudioRecovery, beforeSend = false): boolean {
    let persisted = false
    try {
      // Only opaque recovery identifiers persist. Never a Bearer, payload or transcript.
      const encoded = JSON.stringify(value)
      sessionStorage.setItem(AUDIO_RECOVERY_STORAGE, encoded)
      if (sessionStorage.getItem(AUDIO_RECOVERY_STORAGE) !== encoded) throw new Error('Recovery storage did not retain the original key')
      persisted = true
      audioRecoveryStorageError.value = ''
    } catch {
      audioRecoveryStorageError.value = beforeSend
        ? '原转写请求号保存失败，本次没有发送。恢复存储后请重新读取本地原号。'
        : '原转写请求号保存失败，请保留页面上的原号，只读找回；未恢复持久保存前不能新建转写。'
    }
    // A failed first save has not dispatched anything; retain any previous original record.
    if (!beforeSend || persisted) {
      pendingAudioSubmit.value = value
      audioLookupFileId.value = value.fileId
      audioLookupKey.value = value.key
    }
    return persisted
  }

  function restoreAudioRecovery(): void {
    try {
      const raw = sessionStorage.getItem(AUDIO_RECOVERY_STORAGE)
      if (raw === null) {
        // Reading empty storage never discards an original key still held in this page.
        if (pendingAudioSubmit.value) retainAudioRecovery(pendingAudioSubmit.value)
        else audioRecoveryStorageError.value = ''
        return
      }
      const value: unknown = JSON.parse(raw)
      if (typeof value !== 'object' || value === null || Array.isArray(value)) throw new Error('Invalid recovery record')
      const item = value as Record<string, unknown>
      if (typeof item.fileId !== 'number' || !Number.isSafeInteger(item.fileId) || item.fileId < 1
        || typeof item.key !== 'string' || !/^[\x21-\x7e]{1,512}$/.test(item.key)
        || (item.taskId !== null && (typeof item.taskId !== 'number'
          || !Number.isSafeInteger(item.taskId) || item.taskId < 1))) throw new Error('Invalid recovery identifiers')
      const current = pendingAudioSubmit.value
      if (current && (current.key !== item.key || current.fileId !== item.fileId
        || (current.taskId !== null && current.taskId !== item.taskId))) {
        throw new Error('Stored original request differs from the current request')
      }
      retainAudioRecovery({ fileId: item.fileId, key: item.key, taskId: item.taskId })
    } catch {
      audioRecoveryStorageError.value = '本地原转写记录无法读取或与当前原号不符，已阻止新提交。请保留原文件号和原请求键，只读找回原任务。'
    }
  }

  async function recoverAudio(): Promise<void> {
    await readOriginalAudio(false)
  }

  async function recoverKnownAudio(): Promise<void> {
    await readOriginalAudio(true)
  }

  async function readOriginalAudio(knownId: boolean): Promise<void> {
    if (busy.value || !apiKey.value.trim()) return
    const fileId = pendingAudioSubmit.value?.fileId ?? audioLookupFileId.value
    const key = pendingAudioSubmit.value?.key ?? audioLookupKey.value.trim()
    const taskId = pendingAudioSubmit.value?.taskId ?? null
    if (!fileId || !Number.isSafeInteger(fileId) || fileId < 1 || !/^[\x21-\x7e]{1,512}$/.test(key)) {
      ElMessage.warning('请填写原录音文件号和最多 512 字节的可见 ASCII 请求键')
      return
    }
    if (knownId && !taskId) return
    retainAudioRecovery({ fileId, key, taskId })
    const current = ++viewGeneration
    audioRecoveredTask.value = null
    busy.value = 'audio-recovery'
    lastError.value = null
    try {
      const task = knownId && taskId
        ? await taskApi.read(apiKey.value, taskId)
        : await audioTranscriptionApi.readByRequestKey(apiKey.value, key)
      if (current !== viewGeneration) return
      if (task.task_type !== 'audio_transcription' || task.resource_type !== 'audio_transcription'
        || task.resource_id !== task.id || !Number.isSafeInteger(task.id) || task.id < 1
        || (taskId !== null && task.id !== taskId)) {
        throw new Error('找回的转写任务与原记录不一致，请保留原请求键核对')
      }
      retainAudioRecovery({ fileId, key, taskId: task.id })
      audioRecoveredTask.value = task
      lastTaskByFile.value = { ...lastTaskByFile.value, [fileId]: task }
      ElMessage.success(`已只读找回原转写任务 #${task.id}`)
    } catch (error) {
      // A denial, 404 or transport error never proves the original request was not sent.
      if (current === viewGeneration && !clearOnAccessDenied(error)) lastError.value = toUiError(error)
    } finally {
      if (current === viewGeneration) busy.value = ''
    }
  }

  function viewRecoveredAudio(): void {
    if (!audioRecoveredTask.value) return
    void router.push({ path: '/sand-ai/task', query: { id: String(audioRecoveredTask.value.id) } })
  }

  async function pollTask(taskId: number, fileId: number, generation: number): Promise<SandAiTask> {
    let latest = await taskApi.read(apiKey.value, taskId)
    if (generation !== viewGeneration) throw new Error('File task view changed')
    lastTaskByFile.value = { ...lastTaskByFile.value, [fileId]: latest }
    for (let i = 0; i < 20 && !isTerminal(latest.state); i += 1) {
      await new Promise((resolve) => setTimeout(resolve, 1000))
      if (generation !== viewGeneration) throw new Error('File task view changed')
      latest = await taskApi.read(apiKey.value, taskId)
      if (generation !== viewGeneration) throw new Error('File task view changed')
      lastTaskByFile.value = { ...lastTaskByFile.value, [fileId]: latest }
    }
    return latest
  }

  function retainParseRecovery(value: ParseRecovery): void {
    // Persist identifiers before the first POST; never a credential, file body or result.
    sessionStorage.setItem(PARSE_RECOVERY_STORAGE, JSON.stringify(value))
    pendingParseSubmit.value = value
    parseLookupFileId.value = value.fileId
    parseLookupKey.value = value.key
  }

  function restoreParseRecovery(): void {
    try {
      const value: unknown = JSON.parse(sessionStorage.getItem(PARSE_RECOVERY_STORAGE) ?? 'null')
      if (typeof value !== 'object' || value === null || Array.isArray(value)) return
      const item = value as Record<string, unknown>
      if (typeof item.fileId !== 'number' || !Number.isSafeInteger(item.fileId) || item.fileId < 1
        || typeof item.key !== 'string' || !/^[A-Za-z0-9][A-Za-z0-9_.:-]{7,95}$/.test(item.key)
        || (item.taskId !== null && (typeof item.taskId !== 'number' || !Number.isSafeInteger(item.taskId) || item.taskId < 1))) return
      pendingParseSubmit.value = { fileId: item.fileId, key: item.key, taskId: item.taskId }
      parseLookupFileId.value = item.fileId
      parseLookupKey.value = item.key
    } catch { /* Invalid local identifiers do not authorize a request. */ }
  }

  function assertOriginalParse(task: SandAiTask, fileId: number, taskId: number | null): void {
    if (task.task_type !== 'file_parse' || task.resource_type !== 'file' || task.resource_id !== fileId
      || !Number.isSafeInteger(task.id) || task.id < 1 || (taskId !== null && task.id !== taskId)) {
      throw new Error('解析任务与原文件或任务号不一致，请保留原请求号核对')
    }
  }

  async function readKnownParse(): Promise<void> {
    const fileId = parseReaderFileId.value
    const key = parseReaderKey.value.trim()
    const credential = apiKey.value
    const actor = userStore.info.id
    if (parseReaderBusy.value || !credential.trim()) return
    if (!Number.isSafeInteger(Number(actor)) || Number(actor) < 1) {
      parseReaderError.value = '当前管理员身份尚未确认，请恢复登录后读取原解析'
      return
    }
    if (!fileId || !Number.isSafeInteger(fileId) || fileId < 1
      || !/^[A-Za-z0-9][A-Za-z0-9_.:-]{7,95}$/.test(key)) {
      parseReaderError.value = '请填写有效原文件号及 8–96 个字符的原解析请求号'
      return
    }
    const original = { fileId, key }
    const sequence = ++parseReaderGeneration
    const current = viewGeneration
    const fileEpoch = fileEpochs.get(fileId)
    const ownsRead = (): boolean => sequence === parseReaderGeneration && current === viewGeneration
      && apiKey.value === credential && userStore.info.id === actor
      && parseReaderTarget.value === original && fileEpochs.get(fileId) === fileEpoch
    parseReaderTarget.value = original
    parseReaderResult.value = null
    parseReaderError.value = ''
    parseReaderBusy.value = true
    try {
      const task = await fileApi.readParseByRequestKey(credential, original.fileId, original.key)
      if (!ownsRead()) return
      assertOriginalParse(task, original.fileId, null)
      // This reader never updates recovery keys, current tasks, forms or write locks.
      parseReaderResult.value = task
    } catch (failure) {
      if (ownsRead() && !clearOnAccessDenied(failure)) {
        const detail = toUiError(failure)
        parseReaderError.value = `${detail.description || detail.title}。原读取目标保留；未找到或读取失败不表示请求未发送。`
      }
    } finally {
      if (sequence === parseReaderGeneration) parseReaderBusy.value = false
    }
  }

  function viewKnownParse(): void {
    const task = parseReaderResult.value
    const target = parseReaderTarget.value
    if (!task || !target || task.task_type !== 'file_parse' || task.resource_type !== 'file'
      || task.resource_id !== target.fileId) return
    void router.push({ path: '/sand-ai/task', query: { id: String(task.id) } })
  }

  async function readOriginalParse(knownId: boolean): Promise<void> {
    if (busy.value || !apiKey.value.trim()) return
    const fileId = pendingParseSubmit.value?.fileId ?? parseLookupFileId.value
    const key = pendingParseSubmit.value?.key ?? parseLookupKey.value.trim()
    const taskId = pendingParseSubmit.value?.taskId ?? null
    if (!fileId || !Number.isSafeInteger(fileId) || fileId < 1
      || !/^[A-Za-z0-9][A-Za-z0-9_.:-]{7,95}$/.test(key) || (knownId && !taskId)) {
      ElMessage.warning('请填写原文件号和有效的原 X-Request-Id（8–96 个字符）')
      return
    }
    const current = ++viewGeneration
    parseRecoveredTask.value = null
    busy.value = 'parse-recovery'; lastError.value = null
    try {
      retainParseRecovery({ fileId, key, taskId })
      const task = knownId && taskId
        ? await taskApi.read(apiKey.value, taskId)
        : await fileApi.readParseByRequestKey(apiKey.value, fileId, key)
      if (current !== viewGeneration) return
      assertOriginalParse(task, fileId, taskId)
      retainParseRecovery({ fileId, key, taskId: task.id })
      parseRecoveredTask.value = task
      lastTaskByFile.value = { ...lastTaskByFile.value, [fileId]: task }
      ElMessage.success(`已只读找回原解析任务 #${task.id}`)
    } catch (error) {
      if (current === viewGeneration && !clearOnAccessDenied(error)) lastError.value = toUiError(error)
    } finally {
      if (current === viewGeneration) busy.value = ''
    }
  }

  function viewRecoveredParse(): void {
    if (parseRecoveredTask.value) void router.push({ path: '/sand-ai/task', query: { id: String(parseRecoveredTask.value.id) } })
  }

  function canRetryOriginalParse(task: SandAiTask | null | undefined): boolean {
    return task?.state === 'failed' && task.task_type === 'file_parse' && task.resource_type === 'file'
      && Number.isSafeInteger(task.file_parse_id) && Number(task.file_parse_id) > 0
      && Number.isSafeInteger(task.attempt_count) && task.attempt_count >= 0
      && Number.isSafeInteger(task.max_attempts) && task.max_attempts > task.attempt_count
      && task.authorization_continuation?.required !== true
  }

  function parseRetrySnapshot(task: SandAiTask): string {
    return JSON.stringify([
      task.id, task.task_type, task.resource_type, task.resource_id, task.file_parse_id,
      task.state, task.attempt_count, task.max_attempts, task.started_at, task.completed_at,
      task.error_code, task.authorization_continuation?.required ?? false
    ])
  }

  async function retryOriginalParse(): Promise<void> {
    const original = pendingParseSubmit.value
    const task = parseRecoveredTask.value
    if (busy.value || !apiKey.value.trim() || !original?.taskId || pendingCleanup.has(original.fileId)
      || isCleanupState(recentFiles.value.find(file => file.id === original.fileId)?.state ?? '')
      || !task || !canRetryOriginalParse(task)) return
    const current = viewGeneration
    const credential = apiKey.value
    const actor = userStore.info.id
    const snapshot = parseRetrySnapshot(task)
    const ownsRetry = (): boolean => current === viewGeneration && credential === apiKey.value
      && actor === userStore.info.id && pendingParseSubmit.value?.fileId === original.fileId
      && pendingParseSubmit.value.taskId === original.taskId && pendingParseSubmit.value.key === original.key
    busy.value = 'parse'; lastError.value = null
    try {
      assertOriginalParse(task, original.fileId, original.taskId)
      try {
        await ElMessageBox.confirm(
          `确认重试原解析任务 #${task.id}（当前执行次数 ${task.attempt_count}）？仍处理原文件和解析记录；若再次调用 OCR 将产生费用，已有费用保留。`,
          '重试原解析确认', { type: 'warning', confirmButtonText: '确认重试原任务', cancelButtonText: '保留原任务' }
        )
      } catch (error) {
        if (error === 'cancel' || error === 'close') return
        throw error
      }
      if (!ownsRetry()) return
      const latest = await taskApi.read(credential, original.taskId)
      if (!ownsRetry()) return
      assertOriginalParse(latest, original.fileId, original.taskId)
      parseRecoveredTask.value = latest
      lastTaskByFile.value = { ...lastTaskByFile.value, [original.fileId]: latest }
      if (!canRetryOriginalParse(latest) || parseRetrySnapshot(latest) !== snapshot) {
        ElMessage.info('原解析状态或执行代次已变化，本次重试未发送；请按当前任务重新确认')
        return
      }
      const file = await fileApi.read(credential, original.fileId)
      if (!ownsRetry()) return
      if (file.id !== original.fileId) throw new Error('原文件读取目标不一致，本次重试未发送')
      upsertFile(file)
      const fileTask = file.latest_parse?.task
      if (file.state !== 'parse_failed' || file.latest_parse?.id !== task.file_parse_id
        || !fileTask || parseRetrySnapshot(fileTask) !== snapshot) {
        ElMessage.info('原文件、解析记录或执行代次已变化，本次重试未发送；请只读刷新原任务')
        return
      }
      // Keep the original key and task locked if the retry response is lost.
      parseRecoveredTask.value = null
      const result = await taskApi.retry(credential, original.taskId, undefined, {
        expected_attempt_count: task.attempt_count, expected_state: task.state
      })
      if (!ownsRetry()) return
      assertOriginalParse(result, original.fileId, original.taskId)
      if (result.file_parse_id !== task.file_parse_id) throw new Error('重试响应与原解析记录不一致，请只读原任务核对')
      parseRecoveredTask.value = result
      lastTaskByFile.value = { ...lastTaskByFile.value, [original.fileId]: result }
      ElMessage.success(`已继续原解析任务 #${result.id}，请刷新原任务查看进度`)
    } catch (error) {
      if (ownsRetry() && !clearOnAccessDenied(error)) lastError.value = toUiError(error)
    } finally {
      if (ownsRetry()) busy.value = ''
    }
  }

  function canRestartCanceledParse(task: SandAiTask | null | undefined): boolean {
    const proof = task?.parse_restart
    if (task && isCleanupState(recentFiles.value.find(file => file.id === task.resource_id)?.state ?? '')) return false
    return task?.state === 'canceled' && task.task_type === 'file_parse' && task.resource_type === 'file'
      && Number.isSafeInteger(task.id) && task.id > 0 && Number.isSafeInteger(task.resource_id) && task.resource_id > 0
      && proof?.allowed === true && proof.task_id === task.id && proof.file_id === task.resource_id
      && (!pendingParseSubmit.value || (pendingParseSubmit.value.fileId === task.resource_id && pendingParseSubmit.value.taskId === task.id))
  }

  async function restartCanceledParse(target?: SandAiTask): Promise<void> {
    const original = target ?? parseRecoveredTask.value
    if (busy.value || !apiKey.value.trim() || !original || !canRestartCanceledParse(original)) return
    const generation = viewGeneration
    busy.value = 'parse-recovery'; lastError.value = null
    try {
      // A cached lifecycle hint cannot authorize a new request. Read the original
      // task under the current caller before asking the user to start a new parse.
      const current = await taskApi.read(apiKey.value, original.id)
      if (generation !== viewGeneration) return
      assertOriginalParse(current, original.resource_id, original.id)
      parseRecoveredTask.value = current
      lastTaskByFile.value = { ...lastTaskByFile.value, [original.resource_id]: current }
      if (!canRestartCanceledParse(current)) {
        throw new Error('当前证据不能确认原取消任务未发送 OCR 请求，请保留原任务核对')
      }
      try {
        await ElMessageBox.confirm(
          `原任务 #${current.id} 已确认取消且未发送 OCR 请求。重新解析文件 #${current.resource_id} 会创建新任务，并使用当前解析配置；若调用 OCR 将产生独立计量。是否重新解析？`,
          '重新解析确认', { type: 'warning', confirmButtonText: '重新解析原文件', cancelButtonText: '保留原任务' }
        )
      } catch { return }
      if (generation !== viewGeneration || !canRestartCanceledParse(current)) return
      const requestId = newRuntimeRequestId('file-parse')
      // Persist the new opaque recovery key before sending. A lost new POST
      // stays locked to this key; it does not unlock the old unknown operation.
      retainParseRecovery({ fileId: current.resource_id, key: requestId, taskId: null })
      parseRecoveredTask.value = null
      busy.value = 'parse'
      const submitted = await fileApi.parse(apiKey.value, current.resource_id, requestId)
      if (generation !== viewGeneration) return
      assertOriginalParse(submitted.task, current.resource_id, null)
      retainParseRecovery({ fileId: current.resource_id, key: requestId, taskId: submitted.task.id })
      parseRecoveredTask.value = submitted.task
      lastTaskByFile.value = { ...lastTaskByFile.value, [current.resource_id]: submitted.task }
      ElMessage.success(`已创建新的解析任务 #${submitted.task.id}；请刷新原任务查看进度`)
    } catch (error) {
      if (generation === viewGeneration && !clearOnAccessDenied(error)) lastError.value = toUiError(error)
    } finally {
      if (generation === viewGeneration) busy.value = ''
    }
  }

  async function handleParse(fileId: number): Promise<void> {
    const file = recentFiles.value.find((item) => item.id === fileId)
    if (busy.value !== '' || pendingCleanup.has(fileId) || parseRecoveryLocked.value || (!pendingParseSubmit.value && parseLookupKey.value.trim()) || (file && isCleanupState(file.state))) return
    const existing = lastTaskByFile.value[fileId] ?? file?.latest_parse?.task
    if ((existing && !isTerminal(existing.state)) || (file && ['parse_failed', 'parse_canceled'].includes(file.state))) {
      if (canRestartCanceledParse(existing)) await restartCanceledParse(existing)
      else if (existing) goTask(fileId)
      else ElMessage.warning('原解析任务号尚未找到，请按原解析请求号只读找回后继续')
      return
    }
    const current = viewGeneration
    busy.value = 'parse'
    lastError.value = null
    try {
      const requestId = newRuntimeRequestId('file-parse')
      parseRecoveredTask.value = null
      retainParseRecovery({ fileId, key: requestId, taskId: null })
      const submitted = await fileApi.parse(apiKey.value, fileId, requestId)
      if (current !== viewGeneration) return
      assertOriginalParse(submitted.task, fileId, null)
      retainParseRecovery({ fileId, key: requestId, taskId: submitted.task.id })
      parseRecoveredTask.value = submitted.task
      lastTaskByFile.value = { ...lastTaskByFile.value, [fileId]: submitted.task }
      const replay = submitted.idempotent_replay ? '（幂等重放）' : ''
      ElMessage.success(`已提交解析任务 #${submitted.task.id}${replay}`)

      const task = await pollTask(submitted.task.id, fileId, current)
      if (current !== viewGeneration) return
      assertOriginalParse(task, fileId, submitted.task.id)
      parseRecoveredTask.value = task
      const file = await fileApi.read(apiKey.value, fileId)
      if (current !== viewGeneration) return
      upsertFile(file)

      const code = task.error_code ?? file.latest_parse?.error_code
      const hint = hintForRuntimeErrorCode(code)
      if (code && hint) {
        lastError.value = { title: code, description: hint }
        ElMessage.warning(hint)
      } else if (task.state === 'succeeded') {
        ElMessage.success(`解析任务 #${task.id} 已完成`)
      } else if (!isTerminal(task.state)) {
        ElMessage.info(`任务 #${task.id} 仍为 ${task.state}，可在任务中心继续查看`)
      } else {
        ElMessage.warning(`任务 #${task.id} 结束：${task.state}`)
      }
    } catch (error) {
      if (current !== viewGeneration) return
      lastError.value = toUiError(error)
      if (error instanceof SandAiRuntimeError && error.errorCode) {
        ElMessage.warning(hintForRuntimeErrorCode(error.errorCode) ?? error.message)
      }
    } finally {
      if (current === viewGeneration) busy.value = ''
    }
  }

  async function handleDelete(fileId: number): Promise<void> {
    restoreCleanup(fileId)
    const file = recentFiles.value.find((item) => item.id === fileId)
    if (busy.value || !file || cleanupFlights.has(fileId) || file.state === 'deleting' || file.state === 'deleted' || pendingCleanup.has(fileId)) return
    const retrying = file.state === 'delete_failed'
    const current = viewGeneration
    try {
      await ElMessageBox.confirm(
        retrying
          ? `确认继续清理原文件 #${fileId} 及其派生文本和成果？来源内容将不可用，不可恢复。费用和最小历史保留；知识库向量由独立知识库负责，当前不保证其已清理。`
          : `确认受控删除文件 #${fileId} 及其派生文本和成果？来源内容将不可用，不可恢复。费用和最小历史保留；知识库向量由独立知识库负责，当前不保证其已清理。`,
        retrying ? '重试清理' : '删除确认', { type: 'warning' })
    } catch { return }
    if (current !== viewGeneration || busy.value) return
    const confirmed = recentFiles.value.find((item) => item.id === fileId)
    if (!confirmed || confirmed.sha256 !== file.sha256 || confirmed.file_ref !== file.file_ref
      || confirmed.state !== file.state || pendingCleanup.has(fileId)) {
      ElMessage.warning('原文件状态已变化，请读取当前状态后重新确认清理')
      return
    }
    const epoch = nextFileEpoch(fileId)
    if (!retainCleanup(fileId, true)) { ElMessage.warning('原文件清理记录保存失败，本次未发送删除请求'); return }
    clearFilePrivateState(fileId)
    cleanupFlights.add(fileId)
    busy.value = 'delete'
    lastError.value = null
    try {
      const result = await fileApi.destroy(apiKey.value, fileId)
      if (current !== viewGeneration || fileEpochs.get(fileId) !== epoch) return
      if (result.id !== fileId || result.state !== 'deleted') throw new Error('文件清理结果尚未确认')
      clearFilePrivateState(fileId)
      upsertFile({ id: file.id, original_name: file.original_name, extension: file.extension, media_type: file.media_type, size_bytes: file.size_bytes, sha256: file.sha256, state: 'deleted', latest_parse: null, create_time: file.create_time, update_time: file.update_time, cleanup: result.cleanup })
      cleanupFileId.value = fileId
      if (result.cleanup.state === 'purged') ElMessage.success(`文件 #${fileId} 清理已完成，来源内容不可用；费用与最小历史保留，知识库向量按独立状态核对`)
      else ElMessage.info(`原文件 #${fileId} 已删除，整体清理${result.cleanup.state === 'pending' ? '仍待完成' : '尚未确认完成'}；请保留原文件号只读刷新分类状态`)
    } catch (error) {
      if (current !== viewGeneration || fileEpochs.get(fileId) !== epoch || clearOnAccessDenied(error)) return
      const detail = toUiError(error)
      const conflict = error instanceof SandAiRuntimeError && error.errorCode === 'SAND_AI_RESOURCE_CONFLICT'
      if (conflict) retainCleanupConflict(fileId, file.sha256)
      lastError.value = {
        ...detail,
        description: conflict
          ? `${detail.description || detail.title}。请处理资源占用并读取原文件状态；确认原文件尚未进入清理后，可重新确认删除。原文件标识已保留。`
          : `${detail.description || detail.title}。清理未确认完成，原文件标识已保留。请只读刷新；只有明确清理失败后才能在原文件上重试。`
      }
    } finally {
      cleanupFlights.delete(fileId)
      if (current === viewGeneration && fileEpochs.get(fileId) === epoch) busy.value = ''
    }
  }

  onMounted(() => {
    restoreAudioRecovery()
    restoreParseRecovery()
    apiKey.value = readRuntimeBearer()
    restoreCleanupRecords()
  })
  watch(apiKey, clearProtectedFileView, { flush: 'sync' })
  watch(() => [userStore.info.id, userStore.accessToken], () => {
    if (apiKey.value) apiKey.value = ''
    else clearProtectedFileView()
  }, { flush: 'sync' })
  onUnmounted(() => { ++viewGeneration; ++parseReaderGeneration; fileEpochs.clear() })

</script>

<style scoped lang="scss">
  .file-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 16px;
  }

  .file-title {
    margin: 0;
    font-size: 18px;
    font-weight: 600;
    color: var(--el-text-color-primary);
  }

  .file-desc {
    margin: 4px 0 0;
    font-size: 13px;
    color: var(--el-text-color-secondary);
    max-width: 720px;
  }

  .file-cred {
    margin-bottom: 8px;
  }

  .file-alert {
    margin-bottom: 16px;
  }
</style>
