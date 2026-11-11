<template>
  <SandAiErrorBoundary>
    <div class="sand-ai-page">
      <ElCard class="art-table-card sand-ai-page-card" shadow="never">
        <div class="file-header">
          <div>
            <h2 class="file-title">文件中心</h2>
            <p class="file-desc">
              上传文档或录音后，可提交解析或转写任务。录音结果和费用在任务中心查看。
              清理进行中请刷新状态；清理失败可在原文件上重试，无需重新上传。
            </p>
          </div>
        </div>

        <ElForm label-width="110px" class="file-cred" @submit.prevent>
          <RuntimeApiKeyField
            v-model="apiKey"
            :trial-actions="['sand_ai.file.read', 'sand_ai.file.write', 'sand_ai.document_parse', 'sand_ai.task.read', 'sand_ai.task.retry', 'sand_ai.audio.transcription']"
            placeholder="当前应用的调用密钥（需允许使用文件读写）"
          />
          <ElFormItem label="上传文件">
            <PrivateFileUpload :bearer="apiKey" :disabled="busy !== ''"
              accept=".pdf,.docx,.txt,.csv,.xlsx,.png,.jpg,.jpeg,.webp,.mp3,.m4a,.ogg,.wav,.webm,.flac"
              @file="upsertFile" @busy="uploadBusy" @identity-denied="clearOnAccessDenied" />
          </ElFormItem>
        </ElForm>

        <p v-if="pendingCleanupIds.length" class="file-desc">
          有文件的清理结果尚未确认或被占用阻止；请在下方「高级：按编号查询与找回恢复」中按原文件号核对。
        </p>
        <p v-if="parseRecoveryLocked" class="file-desc">
          有一份解析提交的结果尚未确认；请在「高级：按编号查询与找回恢复」中按原请求编号只读找回。
        </p>
        <p v-if="audioRecoveryLocked" class="file-desc">
          有一份转写提交的结果尚未确认；请在「高级：按编号查询与找回恢复」中按原请求编号只读找回。
        </p>

        <details class="file-alert file-advanced">
          <summary>高级：按编号查询与找回恢复（一般不用）</summary>
          <ElDivider content-position="left">按编号查询已有文件</ElDivider>
          <ElForm label-width="110px" class="file-cred" @submit.prevent>
            <ElFormItem label="文件编号">
              <ElSpace wrap>
                <ElInputNumber v-model="lookupId" :min="1" :controls="false" placeholder="文件编号" />
                <ElButton :loading="busy === 'read'" @click="handleLookup">查询</ElButton>
              </ElSpace>
            </ElFormItem>
          </ElForm>
          <details class="file-alert file-recover">
            <summary>长 TXT 来源与旧解析恢复</summary>
            <p class="file-desc">新 TXT 解析将长行分成来源块，保留原行及实际字符范围；更新不会改写旧解析或已启用知识版本。</p>
            <p class="file-desc">若已确认旧来源块超过模型或索引输入上限，请重新选择原 TXT 上传，取得新的文件号，再提交解析。解析结果不明时沿请求编号找回，失败只处理原任务；完成后到知识库配置选择新文件，使用新的知识库代码建立草稿、启用并准备索引。</p>
            <p class="file-desc">保留旧文件、已启用知识库及原索引记录。停用不会解除已启用来源引用；清理向量或解除来源引用不能用于重新解析并保留历史。</p>
            <ElButton :disabled="Boolean(busy)" @click="router.push('/sand-ai/knowledge')">继续建立新知识库</ElButton>
          </details>

          <details v-for="fileId in pendingCleanupIds" :key="fileId" class="file-alert file-recover">
            <summary>{{ cleanupConflicts.has(fileId) ? `原文件 #${fileId} 的删除被占用阻止` : `原文件 #${fileId} 的清理结果尚未确认` }}（查看详情）</summary>
            <ElAlert type="warning" :closable="false"
              :title="cleanupConflicts.has(fileId) ? `原文件 #${fileId} 的删除被资源占用阻止` : `原文件 #${fileId} 的清理结果尚未确认`" class="file-alert">
              <p>{{ cleanupConflicts.has(fileId) ? '请先处理占用并读取原文件状态。确认原文件尚未进入清理后，可重新确认删除。' : '请读取原文件状态。找不到或无权读取不表示清理完成；明确清理失败后才可在原文件上重试。' }}</p>
              <ElButton :disabled="Boolean(busy) || !apiKey.trim()" @click="refreshOne(fileId)">读取原文件清理状态</ElButton>
              <ElButton :disabled="Boolean(busy)" @click="abandonCleanup(fileId)">放弃此次变更</ElButton>
            </ElAlert>
          </details>

          <ElDivider content-position="left">找回文件解析</ElDivider>
          <details v-if="parseRecoveryLocked" class="file-alert file-recover">
            <summary>原解析尚未确认成功（查看详情）</summary>
            <ElAlert class="file-alert" type="warning" :closable="false"
              title="原解析尚未确认成功。请按请求编号只读找回；失败只重试原任务，取消后须核实未派发证据才能重新解析。" />
          </details>
          <ElForm label-width="110px" class="file-cred" @submit.prevent>
            <ElFormItem label="原文件编号">
              <ElInputNumber v-model="parseLookupFileId" :min="1" :controls="false"
                :disabled="Boolean(busy) || !!pendingParseSubmit" />
            </ElFormItem>
            <ElFormItem label="原解析请求编号">
              <ElInput v-model.trim="parseLookupKey" maxlength="96"
                :disabled="Boolean(busy) || !!pendingParseSubmit" placeholder="原提交解析时的请求编号" />
            </ElFormItem>
            <ElFormItem>
              <ElSpace wrap>
                <ElButton :disabled="Boolean(busy) || !apiKey.trim() || !parseLookupFileId || !parseLookupKey"
                  :loading="busy === 'parse-recovery'" @click="readOriginalParse(false)">按原请求只读找回</ElButton>
                <ElButton v-if="pendingParseSubmit?.taskId" :disabled="Boolean(busy) || !apiKey.trim()"
                  @click="readOriginalParse(true)">刷新原解析任务</ElButton>
                <ElButton v-if="parseRecoveredTask" :disabled="Boolean(busy)" @click="viewRecoveredParse">{{ recoveredParseTextTarget() ? '查看解析出的文字' : '查看原任务与来源' }}</ElButton>
                <ElButton v-if="parseRecoveredTask?.state === 'failed'"
                  :disabled="Boolean(busy) || !apiKey.trim() || !canRetryOriginalParse(parseRecoveredTask)" @click="retryOriginalParse">重试原解析任务</ElButton>
                <ElButton v-if="canRestartCanceledParse(parseRecoveredTask)"
                  :disabled="Boolean(busy) || !apiKey.trim()" @click="restartCanceledParse()">重新解析原文件</ElButton>
                <ElTag v-if="parseRecoveredTask">原任务 #{{ parseRecoveredTask.id }} · {{ runStateLabel(parseRecoveredTask.state) }}</ElTag>
              </ElSpace>
            </ElFormItem>
            <p v-if="parseRecoveredTask?.state === 'canceled'" class="file-desc">已取消不代表没有费用。有已发送或结果不明的 OCR 请求时，保留原任务核对，不重新解析。</p>
            <p v-if="parseRecoveredTask?.state === 'failed' && !canRetryOriginalParse(parseRecoveredTask)" class="file-desc">原解析的重试资格尚未确认、次数已耗尽或需要当前授权。请到原任务查看正式恢复入口；不重新上传或创建解析。</p>
            <p class="file-desc">请求号在发送前保存。未找到、无权限或网络断开时保留原号；重试资格和外部派发由原任务规则确认。</p>
          </ElForm>

          <ElDivider content-position="left">只读查询已有解析</ElDivider>
          <p class="file-desc">填写已知的原文件编号和原解析请求编号。查询另一项解析不会替换本页待确认的请求，也不会重新解析文件。</p>
          <ElForm label-width="110px" class="file-cred" @submit.prevent>
            <ElFormItem label="原文件编号">
              <ElInputNumber v-model="parseReaderFileId" :min="1" :precision="0" :controls="false" :disabled="parseReaderBusy" />
            </ElFormItem>
            <ElFormItem label="原解析请求编号">
              <ElInput v-model.trim="parseReaderKey" maxlength="96" :disabled="parseReaderBusy"
                placeholder="原解析的请求编号" />
            </ElFormItem>
            <ElFormItem>
              <ElButton :loading="parseReaderBusy"
                :disabled="parseReaderBusy || !apiKey.trim() || !parseReaderFileId || !parseReaderKey"
                @click="readKnownParse">只读查询原解析</ElButton>
            </ElFormItem>
          </ElForm>
          <ElAlert v-if="parseReaderError" type="warning" :closable="false" class="file-alert" :title="parseReaderError" />
          <section v-if="parseReaderTarget && parseReaderResult" class="file-alert">
            <p>原文件 #{{ parseReaderTarget.fileId }} · 原任务 #{{ parseReaderResult.id }} · {{ runStateLabel(parseReaderResult.state) }}。</p>
            <p v-if="parseReaderResult.file_parse_id">解析记录 #{{ parseReaderResult.file_parse_id }}。</p>
            <template v-if="parseReaderResult.error_code">
              <p>错误：{{ hintForRuntimeErrorCode(parseReaderResult.error_code) ?? '未能识别的错误，请展开技术详情查看错误码' }}</p>
              <details class="file-recover"><summary>技术详情</summary><p class="file-desc">{{ parseReaderResult.error_code }}</p></details>
            </template>
            <p class="file-desc">此结果未提供费用金额。请按原任务号查看成果、来源与实际费用记录；解析请求号不是费用记录号。</p>
            <ElSpace wrap>
              <ElButton v-if="knownParseTextTarget()" :disabled="!apiKey.trim()" @click="viewKnownParseText">查看解析出的文字</ElButton>
              <ElButton link type="primary" @click="viewKnownParse">查看原任务、成果与费用</ElButton>
            </ElSpace>
          </section>

          <ElDivider content-position="left">找回录音转写</ElDivider>
          <ElAlert v-if="audioRecoveryStorageError" class="file-alert" type="error" :closable="false"
            :title="audioRecoveryStorageError">
            <ElButton :disabled="Boolean(busy)" @click="restoreAudioRecovery">重新读取本地原号</ElButton>
          </ElAlert>
          <details v-if="audioRecoveryLocked" class="file-alert file-recover">
            <summary>原转写请求尚未确认完成（查看详情）</summary>
            <ElAlert class="file-alert" type="warning" :closable="false"
              title="原转写请求尚未确认完成，请保留请求编号只读找回。修改参数、文件或凭证不会重新提交。" />
          </details>
          <ElForm label-width="110px" class="file-cred" @submit.prevent>
            <ElFormItem label="原录音文件编号">
              <ElInputNumber v-model="audioLookupFileId" :min="1" :controls="false"
                :disabled="Boolean(busy) || !!pendingAudioSubmit" />
            </ElFormItem>
            <ElFormItem label="原转写请求编号">
              <ElInput v-model.trim="audioLookupKey" maxlength="512"
                :disabled="Boolean(busy) || !!pendingAudioSubmit"
                placeholder="原提交转写时的请求编号" />
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
                <ElTag v-if="audioRecoveredTask">原任务 #{{ audioRecoveredTask.id }} · {{ runStateLabel(audioRecoveredTask.state) }}</ElTag>
              </ElSpace>
            </ElFormItem>
            <p v-if="audioRecoveredTask?.state === 'succeeded'" class="file-desc">
              原转写已完成，原任务号和请求键仍保留。可以选择录音明确提交另一项转写。
            </p>
            <p v-else-if="audioRecoveredTask && isTerminal(audioRecoveredTask.state)" class="file-desc">
              原任务已结束；失败或取消不证明没有外部调用。请在原任务中查看费用和可用恢复操作。
            </p>
          </ElForm>
        </details>

        <ElAlert
          v-if="lastError"
          class="file-alert"
          type="error"
          :closable="true"
          :title="lastError.title"
          :description="lastError.description"
          @close="lastError = null"
        >
          <ElButton
            v-if="lastError.code === 'SAND_AI_CAPABILITY_UNSUPPORTED'"
            link
            type="primary"
            @click="router.push('/sand-ai/capability')"
          >去能力配置补齐该能力</ElButton>
        </ElAlert>

        <ArtTable row-key="id" :loading="busy !== ''" :data="recentFiles" :columns="columns" />
      </ElCard>

      <ElDialog v-model="detailDialogOpen" title="文件详情" width="640px">
        <template v-if="detailFile">
          <ElDescriptions :column="1" border>
            <ElDescriptionsItem label="编号">#{{ detailFile.id }}</ElDescriptionsItem>
            <ElDescriptionsItem label="文件名">{{ detailFile.original_name }}</ElDescriptionsItem>
            <ElDescriptionsItem label="扩展名">{{ detailFile.extension || '—' }}</ElDescriptionsItem>
            <ElDescriptionsItem label="文件格式">{{ detailFile.media_type }}</ElDescriptionsItem>
            <ElDescriptionsItem label="大小">{{ formatFileSize(detailFile.size_bytes) }}</ElDescriptionsItem>
            <ElDescriptionsItem label="文件状态">{{ fileStateLabel(detailFile.state) }}</ElDescriptionsItem>
            <ElDescriptionsItem label="上传时间">{{ detailFile.create_time }}</ElDescriptionsItem>
            <ElDescriptionsItem label="更新时间">{{ detailFile.update_time }}</ElDescriptionsItem>
            <ElDescriptionsItem label="清理状态">
              <ElButton link type="primary" :disabled="Boolean(busy)" @click="showCleanup(detailFile.id)">
                {{ detailFile.cleanup ? cleanupStateLabel(detailFile.cleanup.state) : '查看清理状态' }}
              </ElButton>
            </ElDescriptionsItem>
            <ElDescriptionsItem label="解析摘要">{{ formatParse(detailFile) }}</ElDescriptionsItem>
            <ElDescriptionsItem label="任务">
              <template v-if="detailTask">
                #{{ detailTask.id }} · {{ runStateLabel(detailTask.state) }}
                <ElButton link type="primary" @click="goTask(detailFile.id)">查看任务</ElButton>
              </template>
              <template v-else>—</template>
            </ElDescriptionsItem>
          </ElDescriptions>
          <ElSpace wrap class="file-detail-actions">
            <ElButton v-if="detailParseTextTarget" :disabled="!apiKey.trim()" @click="openParsedText(detailParseTextTarget)">查看解析出的文字</ElButton>
            <ElButton v-if="detailFile && !parsedTextTargetForRow(detailFile) && (lastTaskByFile[detailFile.id] ?? detailFile.latest_parse?.task)"
              :disabled="pendingCleanup.has(detailFile.id) || isCleanupState(detailFile.state)"
              @click="goTask(detailFile.id)">看任务</ElButton>
            <ElButton :disabled="busy !== '' || pendingCleanup.has(detailFile.id) || parseRecoveryLocked
              || (!!parseLookupKey.trim() && !pendingParseSubmit) || audioExtensions.has(detailFile.extension) || isCleanupState(detailFile.state)"
              :loading="busy === 'parse'" @click="handleParse(detailFile.id)">
              {{ detailFile.state === 'parse_canceled' ? '核对取消后重新解析' : detailFile.state === 'parse_failed' ? '继续原解析任务' : '提交解析' }}
            </ElButton>
            <ElButton v-if="audioExtensions.has(detailFile.extension)"
              :disabled="busy !== '' || pendingCleanup.has(detailFile.id) || detailFile.state !== 'uploaded' || !detailFile.file_ref"
              :loading="busy === 'audio'" @click="openAudioDialog(detailFile)">转写录音</ElButton>
            <ElButton :loading="busy === 'read'" @click="refreshOne(detailFile.id)">刷新</ElButton>
          </ElSpace>
        </template>
      </ElDialog>

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

      <FileParsedTextDrawer
        :open="parsedTextTarget !== null"
        :bearer="apiKey"
        :file-id="parsedTextTarget?.fileId ?? null"
        :parse-id="parsedTextTarget?.parseId ?? null"
        :file-name="parsedTextTarget?.fileName"
        @close="parsedTextTarget = null"
        @access-denied="clearOnAccessDenied"
      />
    </div>
  </SandAiErrorBoundary>
</template>

<script setup lang="ts">
  import SandAiErrorBoundary from '../components/SandAiErrorBoundary.vue'
  import '../components/sandAiPage.css'
  import { computed, h, onMounted, onUnmounted, reactive, ref, watch, type VNode } from 'vue'
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
  import { errorDisplayText } from '../api/errorText'
  import { isWriteRejected, writeRejectionText } from '../api/writeRejection'
  import { confirmAbandonPendingWrite } from '../composables/pendingWrite'
  import type { RuntimeUiError } from '../api/runtimeErrors'
  import type { SandAiCleanupState, SandAiCleanupStatus, SandAiFile, SandAiTask } from '../api/types'
  import { runStateLabel } from '../api/displayLabels'
  import RuntimeApiKeyField from '../components/RuntimeApiKeyField.vue'
  import PrivateFileUpload from '../components/PrivateFileUpload.vue'
  import FileParsedTextDrawer from '../components/FileParsedTextDrawer.vue'

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
  const parsedTextTarget = ref<{ fileId: number; parseId: number; fileName?: string } | null>(null)
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

  /** 「放弃此次变更」：无条件移除该文件的本地清理记录并解锁。 */
  function abandonCleanup(fileId: number): void {
    confirmAbandonPendingWrite(() => {
      try { sessionStorage.removeItem(cleanupStorageKey(fileId)) } catch { /* 内存锁照常清 */ }
      pendingCleanup.delete(fileId)
      cleanupConflicts.delete(fileId)
      ElMessage.success('已放弃未确认的本地记录')
    })
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
    if (parsedTextTarget.value?.fileId === fileId) parsedTextTarget.value = null
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
    parsedTextTarget.value = null
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

  const detailFileId = ref<number | null>(null)
  const detailFile = computed(() => recentFiles.value.find(file => file.id === detailFileId.value) ?? null)
  const detailTask = computed<SandAiTask | null>(() => {
    const file = detailFile.value
    if (!file) return null
    return lastTaskByFile.value[file.id] ?? file.latest_parse?.task ?? null
  })
  const detailParseTextTarget = computed(() => (detailFile.value ? parsedTextTargetForRow(detailFile.value) : null))
  const detailDialogOpen = computed({
    get: () => detailFileId.value !== null,
    set: (value: boolean) => { if (!value) detailFileId.value = null }
  })

  function formatFileSize(bytes: number): string {
    if (!Number.isFinite(bytes) || bytes < 0) return '—'
    if (bytes < 1024) return `${bytes} B`
    const units = ['KB', 'MB', 'GB', 'TB']
    let value = bytes / 1024
    let unit = 0
    while (value >= 1024 && unit < units.length - 1) {
      value /= 1024
      unit += 1
    }
    return `${value >= 100 ? Math.round(value) : value.toFixed(1)} ${units[unit]}`
  }

  /** 主列表只保留「文件名 / 处理进度 / 查看结果」；解析与任务细节进详情。 */
  function fileProgressText(row: SandAiFile): string {
    if (pendingCleanup.has(row.id)) return '清理结果尚未确认'
    if (row.state === 'deleting') return '清理进行中'
    if (row.state === 'delete_failed') return '清理失败，可重试'
    if (row.state === 'deleted') return '已删除'
    const parts = [fileStateLabel(row.state)]
    const parse = row.latest_parse
    if (parse && parse.state !== 'succeeded') parts.push(`解析${runStateLabel(parse.state)}`)
    const task = lastTaskByFile.value[row.id] ?? parse?.task
    if (task && !isTerminal(task.state)) parts.push(`任务${runStateLabel(task.state)}`)
    else if (task && task.state === 'failed') parts.push('任务失败')
    return parts.join(' · ')
  }

  function parseActionDisabled(row: SandAiFile): boolean {
    return busy.value !== '' || pendingCleanup.has(row.id) || parseRecoveryLocked.value
      || (!!parseLookupKey.value.trim() && !pendingParseSubmit.value) || audioExtensions.has(row.extension) || isCleanupState(row.state)
  }

  function parseActionLabel(row: SandAiFile): string {
    return row.state === 'parse_canceled' ? '核对取消后重新解析' : row.state === 'parse_failed' ? '继续原解析任务' : '提交解析'
  }

  function rowMainAction(row: SandAiFile): { label: string; disabled: boolean; loading: boolean; run: () => void } | null {
    const cleanupBusy = pendingCleanup.has(row.id) || isCleanupState(row.state)
    if (cleanupBusy || row.state === 'deleted') return null
    const target = parsedTextTargetForRow(row)
    if (target) return { label: '查看结果', disabled: false, loading: false, run: () => openParsedText(target) }
    if (audioExtensions.has(row.extension)) {
      if (row.state === 'uploaded' && row.file_ref) {
        return { label: '转写录音', disabled: busy.value !== '', loading: busy.value === 'audio', run: () => openAudioDialog(row) }
      }
      const task = lastTaskByFile.value[row.id] ?? row.latest_parse?.task
      if (task) return { label: '看任务', disabled: false, loading: false, run: () => goTask(row.id) }
      return null
    }
    if (row.state === 'parse_failed' || row.state === 'parse_canceled') {
      return { label: parseActionLabel(row), disabled: parseActionDisabled(row), loading: busy.value === 'parse', run: () => void handleParse(row.id) }
    }
    if (row.state === 'uploaded' || row.state === 'available' || row.state === 'failed') {
      return { label: '提交解析', disabled: parseActionDisabled(row), loading: busy.value === 'parse', run: () => void handleParse(row.id) }
    }
    if (row.state === 'parsing' || row.state === 'parsed') {
      const task = lastTaskByFile.value[row.id] ?? row.latest_parse?.task
      if (task) return { label: '看任务', disabled: false, loading: false, run: () => goTask(row.id) }
    }
    return null
  }

  function fileRowButtons(row: SandAiFile): VNode[] {
    const nodes: VNode[] = []
    const main = rowMainAction(row)
    if (main) {
      nodes.push(h(ElButton, {
        size: 'small',
        disabled: main.disabled,
        loading: main.loading,
        onClick: main.run
      }, () => main.label))
    }
    nodes.push(h(ElButton, { size: 'small', onClick: () => { detailFileId.value = row.id } }, () => '详情'))
    nodes.push(h(
      ElButton,
      { size: 'small', loading: busy.value === 'read', onClick: () => void refreshOne(row.id) },
      () => '刷新'
    ))
    nodes.push(h(
      ElButton,
      {
        size: 'small',
        type: 'danger',
        loading: busy.value === 'delete',
        disabled: busy.value !== '' || pendingCleanup.has(row.id) || cleanupFlights.has(row.id) || row.state === 'deleting' || row.state === 'deleted',
        onClick: () => void handleDelete(row.id)
      },
      () => row.state === 'deleted' ? '已删除' : pendingCleanup.has(row.id) ? '清理尚待确认' : row.state === 'delete_failed' ? '重试清理' : row.state === 'deleting' ? '清理进行中' : '删除'
    ))
    return nodes
  }

  const columns: ColumnOption<SandAiFile>[] = [
    { prop: 'original_name', label: '文件', minWidth: 180, showOverflowTooltip: true },
    { prop: 'size_bytes', label: '大小', width: 90, align: 'right', formatter: (row: SandAiFile) => formatFileSize(row.size_bytes) },
    { prop: 'progress', label: '处理进度', minWidth: 150, formatter: (row: SandAiFile) => fileProgressText(row) },
    { prop: 'create_time', label: '上传时间', width: 165 },
    {
      prop: 'actions',
      label: '操作',
      width: 260,
      fixed: 'right',
      formatter: (row: SandAiFile) => h(ElSpace, { wrap: true }, () => fileRowButtons(row))
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
    rememberRecent()
  }

  const recentStorageKey = 'sand-ai-file-recent:v1'

  function rememberRecent(): void {
    const ids = recentFiles.value.map((file) => file.id).slice(0, 50)
    try { sessionStorage.setItem(recentStorageKey, JSON.stringify(ids)) } catch { /* 刷新前列表仍在内存里 */ }
  }

  function storedRecentIds(): number[] {
    try {
      const raw: unknown = JSON.parse(sessionStorage.getItem(recentStorageKey) || '[]')
      if (!Array.isArray(raw)) return []
      return raw.filter((id): id is number => typeof id === 'number' && Number.isSafeInteger(id) && id > 0).slice(0, 50)
    } catch {
      return []
    }
  }

  async function reloadRecent(): Promise<void> {
    const credential = apiKey.value.trim()
    if (credential === '') return
    const current = viewGeneration
    for (const fileId of storedRecentIds()) {
      if (current !== viewGeneration) return
      try {
        const file = await fileApi.read(credential, fileId)
        if (current !== viewGeneration) return
        upsertFile(file)
      } catch { /* 读不到的跳过，不把已有行清掉 */ }
    }
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
    const err = parse.error_code ? ` · ${hintForRuntimeErrorCode(parse.error_code) ?? '错误详情见原任务'}` : ''
    return `${runStateLabel(parse.state)} · 来源块 ${parse.source_block_count}${err}`
  }

  /** 文件行已成功解析时给出「查看解析出的文字」目标；与凭证隔离的任务列表无关，同一文件即达。 */
  function parsedTextTargetForRow(row: SandAiFile): { fileId: number; parseId: number; fileName?: string } | null {
    if (pendingCleanup.has(row.id) || isCleanupState(row.state)) return null
    const parse = row.latest_parse
    if (parse?.state !== 'succeeded' || !Number.isSafeInteger(parse.id) || parse.id < 1) return null
    return { fileId: row.id, parseId: parse.id, fileName: row.original_name }
  }

  function openParsedText(target: { fileId: number; parseId: number; fileName?: string } | null): void {
    if (!target || !Number.isSafeInteger(target.fileId) || target.fileId < 1
      || !Number.isSafeInteger(target.parseId) || target.parseId < 1) {
      ElMessage.info('该文件没有可读取的解析内容。')
      return
    }
    parsedTextTarget.value = { fileId: target.fileId, parseId: target.parseId, fileName: target.fileName }
  }

  /** 找回的原解析任务已成功时，改为直接读取该文件的解析文字，不再进凭证隔离下可能为空的任务页。 */
  function recoveredParseTextTarget(): { fileId: number; parseId: number } | null {
    const task = parseRecoveredTask.value
    if (task?.state !== 'succeeded' || task.task_type !== 'file_parse' || task.resource_type !== 'file'
      || !Number.isSafeInteger(task.resource_id) || task.resource_id < 1
      || typeof task.file_parse_id !== 'number' || !Number.isSafeInteger(task.file_parse_id) || task.file_parse_id < 1) return null
    return { fileId: task.resource_id, parseId: task.file_parse_id }
  }

  function knownParseTextTarget(): { fileId: number; parseId: number } | null {
    const task = parseReaderResult.value
    const target = parseReaderTarget.value
    if (task?.state !== 'succeeded' || task.task_type !== 'file_parse' || task.resource_type !== 'file'
      || !target || task.resource_id !== target.fileId
      || typeof task.file_parse_id !== 'number' || !Number.isSafeInteger(task.file_parse_id) || task.file_parse_id < 1) return null
    return { fileId: target.fileId, parseId: task.file_parse_id }
  }

  function viewKnownParseText(): void {
    const target = knownParseTextTarget()
    if (!target) return
    const file = recentFiles.value.find(item => item.id === target.fileId)
    openParsedText({ fileId: target.fileId, parseId: target.parseId, fileName: file?.original_name })
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
        throw new Error('转写提交未返回有效任务号，请保留请求编号只读找回')
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
      audioRecoveryStorageError.value = '本地原转写记录无法读取或与当前原号不符，已阻止新提交。请保留原文件号和请求编号，只读找回原任务。'
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
        throw new Error('找回的转写任务与原记录不一致，请保留请求编号核对')
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
      throw new Error('解析任务与原文件或任务号不一致，请保留请求编号核对')
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
    const target = recoveredParseTextTarget()
    if (target) {
      const file = recentFiles.value.find(item => item.id === target.fileId)
      openParsedText({ fileId: target.fileId, parseId: target.parseId, fileName: file?.original_name })
      return
    }
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
        lastError.value = { title: '解析未成功', description: hint, code }
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
        ElMessage.warning(hintForRuntimeErrorCode(error.errorCode) ?? errorDisplayText(error))
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
          ? `确认继续清理文件 #${fileId}？删除后不能恢复，用过的费用记录会保留。`
          : `确认删除文件 #${fileId}？删除后不能恢复，用过的费用记录会保留。`,
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
      if (isWriteRejected(error)) {
        // 明确拒绝：清掉本地锁、按钮恢复，显示后端白话原因
        retainCleanup(fileId, false)
        cleanupConflicts.delete(fileId)
        lastError.value = { title: '删除没有成功', description: writeRejectionText(error, '这次删除被拒绝了，请核对后再试。') }
      } else {
        const detail = toUiError(error)
        lastError.value = {
          ...detail,
          description: `${detail.description || detail.title}；清理未确认完成，原文件标识已保留。请只读刷新；只有明确清理失败后才能在原文件上重试。`
        }
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
  watch(apiKey, () => {
    clearProtectedFileView()
    void reloadRecent()
  }, { flush: 'sync' })
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

  .file-recover > summary {
    cursor: pointer;
    font-size: 13px;
    color: var(--el-color-warning-dark-2);
    list-style: none;
  }

  .file-recover > summary::before {
    content: '▸ ';
  }

  .file-recover[open] > summary::before {
    content: '▾ ';
  }

  .file-advanced {
    margin-bottom: 16px;
    border: 1px solid var(--el-border-color-lighter);
    border-radius: 8px;
    padding: 4px 16px 12px;
  }

  .file-advanced > summary {
    cursor: pointer;
    padding: 10px 0;
    font-size: 14px;
    font-weight: 600;
    color: var(--el-text-color-primary);
    list-style: none;
  }

  .file-advanced > summary::before {
    content: '▸ ';
  }

  .file-advanced[open] > summary::before {
    content: '▾ ';
  }

  .file-detail-actions {
    margin-top: 16px;
    width: 100%;
    justify-content: flex-end;
  }
</style>
