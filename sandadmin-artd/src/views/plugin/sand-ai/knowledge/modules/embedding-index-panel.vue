<template>
  <div class="embedding-index">
    <div class="heading">
      <div>
        <h3>知识向量索引</h3>
        <p>每次点击只处理一个来源块；全部写入后，再点击一次激活索引。</p>
      </div>
    </div>
    <ElAlert v-if="recoveryBlocked" type="warning" :closable="false"
      title="原索引会话记录无法确认，已停止新准备；请保留原记录与请求号。" />
    <ElForm v-for="original in savedBuilds" :key="`${original.versionId}:${original.key}`" label-width="110px" @submit.prevent>
      <ElFormItem label="原知识版本">#{{ original.versionId }}<span v-if="original.indexId !== null"> · 索引 #{{ original.indexId }}</span></ElFormItem>
      <ElFormItem label="原准备请求号"><ElInput :model-value="original.key" readonly /></ElFormItem>
      <p>只读找回原准备结果。活动版本或来源变化时保留原号；不存在或无权不表示原请求未发生，不能换号重新准备。</p>
      <ElButton :disabled="readerBusy || !hasBuildPermission || environmentId === null" @click="recoverOriginal(original)">只读查询原索引</ElButton>
    </ElForm>
    <ElAlert v-if="legacyBlocked" type="warning" :closable="false"
      title="这个版本有旧会话索引记录，不能重新准备。读取须用当前索引查看权限；旧记录不证明原管理员身份。" />
    <ElButton v-if="legacyBlocked" :disabled="readerBusy || !hasBuildPermission" @click="recoverLegacy">只读找回旧会话原索引</ElButton>
    <div v-if="readerTarget" class="notice">
      <h4>原索引查询结果</h4>
      <p>知识版本 #{{ readerTarget.versionId }} · 原准备请求号 {{ readerTarget.key }}<template v-if="readerTarget.indexId !== null"> · 原索引 #{{ readerTarget.indexId }}</template></p>
      <p v-if="readerBusy">正在按当前账号权限查询原索引。</p>
      <p v-else-if="readerResult">
        索引 #{{ readerResult.id }} · {{ indexStateLabel(readerResult.state) }} · 已写入
        {{ readerResult.indexed_count }} / {{ readerResult.source_count }} 块
        <template v-if="readerResult.dimensions !== null"> · {{ readerResult.dimensions }} 维</template>
      </p>
      <ElAlert v-if="readerError" type="warning" :title="readerError" :closable="false" />
      <p class="hint">查询按当前账号和当前来源权限核实。历史版本可按原号查询；无权、来源变化或记录不存在时保留原号，不能据此重新准备。</p>
      <ElButton :disabled="!canRestoreReader" @click="restoreReader">恢复当前原索引状态</ElButton>
      <p class="hint">只有与当前构建目标一致且查询期间没有写入时才能恢复；查询其它历史版本不会切换构建目标。</p>
    </div>
    <ElSelect
      v-model="versionId"
      :disabled="environmentId === null || busy"
      clearable
      placeholder="选择已发布的知识库版本"
      style="width: 420px"
    >
      <ElOption
        v-for="base in publishedBases"
        :key="base.active_version_id!"
        :label="`${base.name} · 已发布版本 ${base.active_version_no ?? base.active_version_id}`"
        :value="base.active_version_id!"
      />
    </ElSelect>
    <div class="actions">
      <ElButton
        type="primary"
        :loading="busy"
        :disabled="!canBuild || indexId !== null"
        @click="start"
        >{{ startKey ? '找回原准备结果' : '准备索引' }}</ElButton
      >
      <ElButton :loading="readerBusy" :disabled="readerBusy || !hasBuildPermission || !startKey || versionId === null || environmentId === null" @click="refreshStatus"
        >只读查询当前索引</ElButton
      >
      <ElButton
        type="primary"
        :loading="busy"
        :disabled="
          !canBuild ||
          indexId === null ||
          state !== 'building' ||
          needsStatus ||
          (uncertain && !statusChecked)
        "
        @click="advance"
      >
        {{ uncertain ? '沿原索引找回当前块' : indexedCount === sourceCount ? '激活索引' : '处理下一块' }}
      </ElButton>
      <ElButton
        v-if="state === 'ready' || restartAllowed"
        :disabled="!canBuild || busy || uncertain || (restartAllowed && !statusChecked)"
        @click="resetBuild"
      >
        {{ restartAllowed ? '重新准备索引' : '准备新索引' }}
      </ElButton>
    </div>
    <p v-if="!hasBuildPermission" class="hint">当前账号没有构建知识向量索引的权限。</p>
    <p v-if="indexId !== null">
      索引 {{ indexId }} · {{ needsStatus || currentReadFailed() ? '待核验' : indexStateLabel(state) }} · 已写入
      {{ indexedCount ?? '待查询' }} / {{ sourceCount ?? '待查询' }} 块
      <template v-if="dimensions !== null"> · {{ dimensions }} 维</template>
    </p>
    <p class="hint">准备和来源块处理属于新索引构建，全部就绪后才激活。新构建失败不表示旧发布版本或先前激活索引失败；检索以服务端当前有效的发布版本与激活索引为准，来源撤销后须重新核对。</p>
    <p v-if="stage" role="status">当前操作阶段：{{ stage }}</p>
    <p v-if="pendingBlock" class="hint">原来源块 #{{ pendingBlock.id }} · {{ pendingBlock.state === 'in_progress' ? '原调用仍在处理' : '原调用结果待确认' }}。先只读核对索引，再沿原索引找回，不能换号重发。</p>
    <p v-if="state === 'failed' && !needsStatus" class="hint">原索引已确认失败，不能继续写入或激活；可明确重新准备新索引，旧请求与索引仍可只读查询。</p>
    <details class="notice">
      <summary>原 TXT 来源块超过输入上限时如何恢复</summary>
      <p class="hint">仅重新准备索引不会改变旧来源块。确认失败原因确为原块过大后，保留原请求与索引，在上方“准备知识来源”重新上传原 TXT 并解析，再选用来源；用新文件号和新的知识库代码建立、发布知识库，再在此选择新发布版本准备索引。</p>
      <p class="hint">当前默认解析已有按字节分块，长 TXT 并不必然无法索引。新 TXT 解析保留原行的实际字符范围；模型的 Token 或输入限制仍须按所选能力核对。结果不明时先只读查询原索引，不能借新文件、新知识库或新请求重发未确认的调用。</p>
    </details>
    <ElAlert
      v-if="uncertain"
      type="warning"
      :closable="false"
      class="notice"
      title="上次处理结果尚未确认"
      :description="
        statusChecked
          ? '只读状态已查询。若仍在构建，下一次点击会沿用同一个索引；结果不明时服务端不会更换来源块请求号。'
          : '请先刷新状态。构建中本身不能证明上次处理已经完成。'
      "
    />
    <p v-if="lastIndexed" class="hint">
      最近写入来源块 {{ lastIndexed.source_block_id }} · 调用 {{ lastIndexed.invocation_id }}
      <template v-if="lastIndexed.recovered"> · 已恢复原调用结果</template>
      · 本次费用 {{ showMoney(lastIndexed.usage.metered_amount, lastIndexed.usage.currency) }}
    </p>
    <ElAlert v-if="error" type="error" :title="error" :closable="false" class="notice" />
  </div>
</template>

<script setup lang="ts">
  import { computed, onUnmounted, ref, watch } from 'vue'
  import { ElMessageBox } from 'element-plus'
  import { useAuth } from '@/hooks/core/useAuth'
  import { useUserStore } from '@/store/modules/user'
  import knowledgeBaseApi, {
    type KnowledgeBaseRow,
    type KnowledgeEmbeddingAdvance,
    type KnowledgeEmbeddingRecovery
  } from '../../api/knowledgeBase'
  import { newRuntimeRequestId } from '../../api/runtime'

  const props = defineProps<{ environmentId: number | null; bases: KnowledgeBaseRow[] }>()
  const { hasAuth } = useAuth()
  const userStore = useUserStore()
  const hasBuildPermission = computed(() => hasAuth('sand_ai:knowledge:index:build'))
  const publishedBases = computed(() =>
    props.bases.filter((base) => base.state === 'published' && base.active_version_id !== null && base.source_state === 'ready')
  )
  const versionId = ref<number | null>(null)
  const selectedVersionPublished = computed(() =>
    publishedBases.value.some((base) => base.active_version_id === versionId.value)
  )
  const recoveryBlocked = ref(false)
  const legacyBlocked = ref(false)
  const savedBuilds = ref<Array<SavedBuild & { versionId: number }>>([])
  const startKey = ref('')
  const indexId = ref<number | null>(null)
  const state = ref('')
  const sourceCount = ref<number | null>(null)
  const indexedCount = ref<number | null>(null)
  const dimensions = ref<number | null>(null)
  const uncertain = ref(false)
  const statusChecked = ref(false)
  const needsStatus = ref(false)
  const restartAllowed = ref(false)
  const lastIndexed = ref<Extract<KnowledgeEmbeddingAdvance, { state: 'indexed' }> | null>(null)
  const writeFlight = ref<symbol | null>(null)
  const busy = computed(() => writeFlight.value !== null)
  const error = ref('')
  const stage = ref('')
  const pendingBlock = ref<{ id: number; state: 'in_progress' | 'outcome_unknown' } | null>(null)
  const canBuild = computed(
    () =>
      hasBuildPermission.value && !recoveryBlocked.value && !legacyBlocked.value && !currentReadFailed() &&
      props.environmentId !== null &&
      selectedVersionPublished.value &&
      Number.isSafeInteger(versionId.value) &&
      versionId.value! > 0 &&
      !busy.value
  )
  const readerBusy = ref(false)
  const readerTarget = ref<(SavedBuild & { versionId: number }) | null>(null)
  const readerResult = ref<KnowledgeEmbeddingRecovery | null>(null)
  const readerError = ref('')
  let readerSequence = 0
  let writerSequence = 0
  let readerWriterSequence = -1
  let readerWasWriting = false
  let readerLegacy = false
  let epoch = 0
  let live = true

  interface SavedBuild {
    key: string
    indexId: number | null
    uncertain: boolean
    indexedCount: number | null
    restartAllowed: boolean
  }
  function adminIdentity(): string | null {
    const actor = userStore.info.id
    return (typeof actor === 'number' && Number.isSafeInteger(actor) && actor > 0)
      || (typeof actor === 'string' && /^[1-9][0-9]*$/.test(actor)) ? String(actor) : null
  }
  function storagePrefix(): string | null {
    const actor = adminIdentity()
    return actor !== null && props.environmentId !== null
      ? `sand-ai-embedding-index:v2:${actor}:${props.environmentId}:` : null
  }
  function storageKey(): string | null {
    const prefix = storagePrefix()
    return prefix && Number.isSafeInteger(versionId.value) && versionId.value! > 0
      ? `${prefix}${versionId.value}` : null
  }
  function readSaved(value: unknown): SavedBuild {
    if (typeof value !== 'object' || value === null || Array.isArray(value)
      || !('key' in value) || typeof value.key !== 'string' || !value.key.trim()
      || value.key.length > 160 || /[\u0000-\u001f\u007f]/.test(value.key)
      || !('indexId' in value) || (value.indexId !== null && (typeof value.indexId !== 'number' || !Number.isSafeInteger(value.indexId) || value.indexId < 1))
      || !('uncertain' in value) || typeof value.uncertain !== 'boolean'
      || !('restartAllowed' in value) || typeof value.restartAllowed !== 'boolean'
      || !('indexedCount' in value) || (value.indexedCount !== null && (typeof value.indexedCount !== 'number' || !Number.isSafeInteger(value.indexedCount) || value.indexedCount < 0)))
      throw new Error('原索引记录不完整，请保留原记录与请求号')
    return { key: value.key, indexId: value.indexId as number | null, uncertain: value.uncertain,
      indexedCount: value.indexedCount as number | null, restartAllowed: value.restartAllowed }
  }
  function refreshSavedBuilds(): void {
    savedBuilds.value = []
    recoveryBlocked.value = false
    const prefix = storagePrefix()
    if (!prefix) { recoveryBlocked.value = props.environmentId !== null; return }
    try {
      for (let i = 0; i < sessionStorage.length; i++) {
        const name = sessionStorage.key(i)
        if (!name?.startsWith(prefix)) continue
        const suffix = name.slice(prefix.length)
        const history = suffix.startsWith('history:') ? suffix.split(':') : null
        if (history && (history.length !== 3 || !/^[1-9][0-9]*$/.test(history[1] ?? '')
          || !/^[1-9][0-9]*$/.test(history[2] ?? ''))) throw new Error('历史索引记录无效')
        const originalVersion = Number(history ? history[1] : suffix)
        if (!Number.isSafeInteger(originalVersion) || originalVersion < 1) throw new Error('原固定版本记录无效')
        const original = readSaved(JSON.parse(sessionStorage.getItem(name) ?? 'null'))
        if (history && original.indexId !== Number(history[2])) throw new Error('历史索引定位不一致')
        if (!savedBuilds.value.some(item => item.versionId === originalVersion && item.key === original.key))
          savedBuilds.value.push({ ...original, versionId: originalVersion })
      }
    } catch {
      recoveryBlocked.value = true
      error.value = '原索引记录无法完整读取，保留原号并停止新准备。'
    }
  }
  function persist(): boolean {
    const key = storageKey()
    if (!key) return false
    try {
      const saved: SavedBuild = {
        key: startKey.value,
        indexId: indexId.value,
        uncertain: uncertain.value,
        indexedCount: indexedCount.value,
        restartAllowed: restartAllowed.value
      }
      sessionStorage.setItem(key, JSON.stringify(saved))
      refreshSavedBuilds()
      return !recoveryBlocked.value
    } catch {
      recoveryBlocked.value = true
      error.value = '无法保存本次请求标识，请允许会话存储后重试。'
      return false
    }
  }
  function restore(): void {
    startKey.value = ''
    indexId.value = null
    state.value = ''
    sourceCount.value = null
    indexedCount.value = null
    dimensions.value = null
    uncertain.value = false
    statusChecked.value = false
    needsStatus.value = false
    restartAllowed.value = false
    lastIndexed.value = null
    error.value = ''
    stage.value = ''
    pendingBlock.value = null
    legacyBlocked.value = false
    const key = storageKey()
    if (!key) return
    try {
      const raw = sessionStorage.getItem(key)
      if (raw === null) {
        // Old keys have no administrator namespace. Do not attach their key
        // or status to this identity without an explicit authorized read.
        legacyBlocked.value = sessionStorage.getItem(`sand-ai-embedding-index:${props.environmentId}:${versionId.value}`) !== null
        return
      }
      const saved = readSaved(JSON.parse(raw))
      startKey.value = saved.key
      indexId.value = saved.indexId
      uncertain.value = saved.uncertain
      restartAllowed.value = saved.restartAllowed
      indexedCount.value = saved.indexedCount
      needsStatus.value = true
      if (indexId.value !== null) state.value = 'building'
    } catch {
      recoveryBlocked.value = true
      error.value = '原索引记录无法读取，已保留原号并停止新准备。'
    }
  }
  function currentReadFailed(): boolean {
    const target = readerTarget.value
    return !!readerError.value && !readerWasWriting && readerWriterSequence === writerSequence
      && target !== null && versionId.value === target.versionId
      && startKey.value === target.key && indexId.value === target.indexId
  }
  function clearReader(): void {
    ++readerSequence
    readerBusy.value = false
    readerTarget.value = null
    readerResult.value = null
    readerError.value = ''
    readerWriterSequence = -1
    readerLegacy = false
  }
  const canRestoreReader = computed(() => {
    const target = readerTarget.value
    const result = readerResult.value
    return hasBuildPermission.value && !busy.value && !readerBusy.value && !recoveryBlocked.value
      && !readerWasWriting && readerWriterSequence === writerSequence && target !== null && result !== null
      && versionId.value === target.versionId
      && ((startKey.value === target.key && indexId.value === target.indexId)
        || (readerLegacy && legacyBlocked.value && !startKey.value && indexId.value === null))
  })
  function restoreReader(): void {
    if (!canRestoreReader.value || !readerTarget.value || !readerResult.value) return
    const original = readerTarget.value
    const result = readerResult.value
    const progressed = original.indexedCount !== null && result.indexed_count > original.indexedCount
    startKey.value = original.key
    indexId.value = result.id
    state.value = result.state
    sourceCount.value = result.source_count
    dimensions.value = result.dimensions
    indexedCount.value = result.indexed_count
    uncertain.value = result.state === 'outcome_unknown'
      || (original.uncertain && result.state === 'building' && !progressed)
    restartAllowed.value = result.state === 'ready' ? false : result.state === 'failed' || original.restartAllowed
    statusChecked.value = true
    needsStatus.value = false
    legacyBlocked.value = false
    persist()
    ++writerSequence
  }
  function indexStateLabel(value: string): string {
    return value === 'ready' ? '已激活' : value === 'building' ? '构建中'
      : value === 'failed' ? '失败' : '结果待确认'
  }
  async function recoverLegacy(): Promise<void> {
    const version = versionId.value
    if (readerBusy.value || !hasBuildPermission.value || props.environmentId === null || version === null) return
    try {
      const saved = readSaved(JSON.parse(sessionStorage.getItem(`sand-ai-embedding-index:${props.environmentId}:${version}`) ?? 'null'))
      await recoverOriginal({ ...saved, versionId: version }, true)
    } catch (cause) { readerError.value = message(cause) }
  }
  async function recoverOriginal(original: SavedBuild & { versionId: number }, legacy = false): Promise<void> {
    const environment = props.environmentId
    const actor = adminIdentity()
    const credential = userStore.accessToken
    const prefix = storagePrefix()
    if (readerBusy.value || !hasBuildPermission.value || environment === null || actor === null || prefix === null) return
    const sequence = ++readerSequence
    const isCurrent = () => live && sequence === readerSequence && props.environmentId === environment
      && adminIdentity() === actor && userStore.accessToken === credential && hasBuildPermission.value
    readerTarget.value = { ...original }
    readerResult.value = null
    readerError.value = ''
    readerWasWriting = busy.value
    readerWriterSequence = writerSequence
    readerLegacy = legacy
    readerBusy.value = true
    try {
      const result = await knowledgeBaseApi.embeddingIndexByRequestKey(environment, original.versionId, original.key, original.indexId)
      if (!isCurrent()) return
      readerResult.value = result
      // An authorized GET may attach this one legacy record to the current
      // administrator. It does not prove the original request's actor.
      if (legacy) {
        const name = `${prefix}${original.versionId}`
        const existing = sessionStorage.getItem(name)
        if (existing !== null) {
          const saved = readSaved(JSON.parse(existing))
          if (saved.key !== original.key || saved.indexId !== original.indexId)
            throw new Error('当前账号已有不同原索引记录，请保留两份原号分别核对')
        } else {
          const saved: SavedBuild = { key: original.key, indexId: original.indexId,
            uncertain: original.uncertain, indexedCount: original.indexedCount,
            restartAllowed: original.restartAllowed }
          sessionStorage.setItem(name, JSON.stringify(saved))
          refreshSavedBuilds()
        }
      }
    } catch (cause) {
      if (isCurrent()) {
        readerResult.value = null
        readerError.value = `${message(cause)}；状态待核验，原目标、索引与请求号仍保留，不重新准备。`
      }
    } finally { if (live && sequence === readerSequence) readerBusy.value = false }
  }
  function message(cause: unknown): string {
    return cause instanceof Error ? cause.message : '索引操作失败，请稍后重试'
  }
  function showMoney(amount: string | null, currency: string | null): string {
    return amount === null
      ? '未知'
      : currency === null
        ? `${amount}（币种未确认）`
        : `${amount} ${currency}`
  }
  function same(scope: number, environment: number, version: number): boolean {
    return (
      live && scope === epoch && props.environmentId === environment && versionId.value === version
      && selectedVersionPublished.value
    )
  }
  async function start(): Promise<void> {
    if (!canBuild.value || indexId.value !== null) return
    if (startKey.value) {
      const original = savedBuilds.value.find(item => item.versionId === versionId.value && item.key === startKey.value)
      if (original) await recoverOriginal(original)
      return
    }
    const environment = props.environmentId!
    const version = versionId.value!
    const scope = epoch
    ++writerSequence
    startKey.value = newRuntimeRequestId('knowledge-embedding')
    uncertain.value = true
    needsStatus.value = true
    if (!persist()) return
    const flight = Symbol('index-write')
    writeFlight.value = flight
    error.value = ''
    stage.value = '准备索引'
    try {
      const result = await knowledgeBaseApi.startEmbeddingIndex(
        environment,
        version,
        startKey.value
      )
      if (!same(scope, environment, version)) return
      indexId.value = result.id
      state.value = result.state
      sourceCount.value = result.source_count
      dimensions.value = result.dimensions
      indexedCount.value = 0
      uncertain.value = false
      restartAllowed.value = false
      needsStatus.value = false
      persist()
    } catch (cause) {
      if (same(scope, environment, version))
        error.value = `准备索引未确认：${message(cause)}；请用原号只读找回准备结果，不重新发送准备请求。`
    } finally {
      if (writeFlight.value === flight) writeFlight.value = null
    }
  }
  async function refreshStatus(): Promise<void> {
    if (versionId.value === null || !startKey.value) return
    await recoverOriginal({ versionId: versionId.value, key: startKey.value, indexId: indexId.value,
      uncertain: uncertain.value, indexedCount: indexedCount.value, restartAllowed: restartAllowed.value })
  }
  async function advance(): Promise<void> {
    if (
      !canBuild.value ||
      indexId.value === null ||
      state.value !== 'building' ||
      needsStatus.value ||
      (uncertain.value && !statusChecked.value)
    )
      return
    const environment = props.environmentId!
    const version = versionId.value!
    const index = indexId.value
    const scope = epoch
    ++writerSequence
    const flight = Symbol('index-write')
    writeFlight.value = flight
    error.value = ''
    stage.value = indexedCount.value === sourceCount.value ? '激活索引' : '来源块向量处理'
    statusChecked.value = false
    uncertain.value = true
    restartAllowed.value = false
    if (!persist()) {
      if (writeFlight.value === flight) writeFlight.value = null
      return
    }
    try {
      const result = await knowledgeBaseApi.advanceEmbeddingIndex(environment, index)
      if (!same(scope, environment, version) || indexId.value !== index) return
      if (result.state === 'indexed') {
        pendingBlock.value = null
        lastIndexed.value = result
        indexedCount.value = indexedCount.value === null ? null : indexedCount.value + 1
        uncertain.value = false
        restartAllowed.value = false
      } else if (result.state === 'ready') {
        pendingBlock.value = null
        state.value = 'ready'
        indexedCount.value = sourceCount.value
        uncertain.value = false
        restartAllowed.value = false
      } else {
        pendingBlock.value = { id: result.source_block_id, state: result.state }
      }
      persist()
    } catch (cause) {
      if (same(scope, environment, version)) {
        if (
          cause instanceof Error &&
          cause.message.startsWith('SAND_AI_CONFIGURATION_NOT_PUBLISHED:')
        ) {
          uncertain.value = false
          restartAllowed.value = true
          needsStatus.value = true
          persist()
          error.value = `${stage.value}失败：${message(cause)}；刷新状态后可重新准备索引。`
        } else {
          error.value = `${stage.value}未确认：${message(cause)}；请先只读刷新状态，再沿用当前索引。`
        }
      }
    } finally {
      if (writeFlight.value === flight) writeFlight.value = null
    }
  }
  async function resetBuild(): Promise<void> {
    if (
      !canBuild.value ||
      uncertain.value ||
      (state.value !== 'ready' && !(restartAllowed.value && statusChecked.value))
    )
      return
    const environment = props.environmentId!
    const version = versionId.value!
    const scope = epoch
    const index = indexId.value
    const restarting = restartAllowed.value
    if (restarting) {
      try {
        await ElMessageBox.confirm(
          `${state.value === 'failed' ? '原索引已确认失败。' : '当前索引的模型配置已变化。'}重新准备会建立新索引，已成功写入的来源块可能再次产生费用。原索引与请求号仍保留供只读查询。是否继续？`,
          '重新准备知识向量索引'
        )
      } catch {
        return
      }
    }
    if (
      !canBuild.value ||
      !same(scope, environment, version) ||
      indexId.value !== index ||
      uncertain.value ||
      (restarting ? !restartAllowed.value || !statusChecked.value : state.value !== 'ready')
    )
      return
    ++writerSequence
    const key = storageKey()
    try {
      const prefix = storagePrefix()
      if (!key || !prefix || index === null) throw new Error('原索引记录定位不可用')
      const saved: SavedBuild = { key: startKey.value, indexId: index, uncertain: false,
        indexedCount: indexedCount.value, restartAllowed: restartAllowed.value }
      const historyKey = `${prefix}history:${version}:${index}`
      const existing = sessionStorage.getItem(historyKey)
      if (existing !== null) {
        const original = readSaved(JSON.parse(existing))
        if (original.key !== saved.key || original.indexId !== saved.indexId) throw new Error('历史索引记录不一致')
      }
      const bytes = JSON.stringify(saved)
      sessionStorage.setItem(historyKey, bytes)
      if (sessionStorage.getItem(historyKey) !== bytes) throw new Error('原索引记录保存未确认')
      sessionStorage.removeItem(key)
      if (sessionStorage.getItem(key) !== null) throw new Error('当前原索引记录清除未确认')
      refreshSavedBuilds()
    } catch {
      error.value = '无法清除本次索引记录，请允许会话存储后重试。'
      return
    }
    restore()
  }
  watch(
    [() => props.environmentId, () => userStore.info.id, () => userStore.accessToken],
    () => {
      ++epoch
      ++writerSequence
      clearReader()
      versionId.value = null
      restore()
      refreshSavedBuilds()
    },
    { immediate: true, flush: 'sync' }
  )
  watch(versionId, () => {
    ++writerSequence
    ++epoch
    restore()
  }, { flush: 'sync' })
  watch(
    publishedBases,
    () => {
      if (versionId.value === null || selectedVersionPublished.value) return
      ++epoch
      versionId.value = null
      restore()
    },
    { flush: 'sync' }
  )
  watch(hasBuildPermission, () => { clearReader() }, { flush: 'sync' })
  onUnmounted(() => {
    live = false
    clearReader()
    ++epoch
  })
</script>

<style scoped>
  .embedding-index {
    margin-top: 20px;
    border-top: 1px solid var(--el-border-color);
    padding-top: 16px;
  }
  .heading h3 {
    margin: 0;
  }
  .heading p,
  .hint {
    color: var(--el-text-color-secondary);
  }
  .heading p {
    margin: 6px 0 12px;
  }
  .actions {
    display: flex;
    gap: 8px;
    margin-top: 12px;
  }
  .notice {
    margin: 12px 0;
  }
</style>
