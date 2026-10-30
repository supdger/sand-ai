<template>
  <div class="art-full-height">
    <ElCard class="art-table-card" shadow="never">
      <div class="task-header">
        <div>
          <h2 class="task-title">任务中心</h2>
          <p class="task-desc">
            查询、取消和重试任务；查看材料的逐文件进度、最终成果、来源与计量。
          </p>
        </div>
        <ElTag type="success" effect="plain">任务与材料运行</ElTag>
      </div>

      <ElForm label-width="110px" class="task-cred" @submit.prevent>
        <RuntimeApiKeyField v-model="apiKey" placeholder="当前应用的 SandIAM 短期运行身份" />
        <p class="task-footnote">查询、取消、重试分别需要 sand_ai.task.read、sand_ai.task.cancel、sand_ai.task.retry 服务授权；读取或重试还会核验原任务对应的服务权限。</p>
        <ElFormItem label="按 id 查询">
          <ElSpace wrap>
            <ElInputNumber v-model="lookupId" :min="1" :controls="false" placeholder="task id" />
            <ElButton :loading="busy === 'read'" type="primary" @click="handleLookup">查询</ElButton>
          </ElSpace>
        </ElFormItem>
      </ElForm>

      <ElDivider content-position="left">找回会议摘要</ElDivider>
      <p v-if="pendingSummary" class="task-footnote">原摘要音频 #{{ pendingSummary.audioTaskId }} · 冻结复核版本 {{ pendingSummary.reviewRevision ?? '未记录（仅按原号找回）' }}</p>
      <ElAlert v-if="pendingSummary" type="warning" :closable="false" class="task-alert"
        :title="pendingSummary.summaryTaskId ? `原摘要任务 #${pendingSummary.summaryTaskId} 尚待核对。读取原任务，不会新建摘要或重复调用。` : '上次摘要提交结果尚未确认。请保留原请求键只读找回；未确认前不能新建摘要。'" />
      <ElForm label-width="110px" class="task-cred" @submit.prevent>
        <ElFormItem label="原音频任务号">
          <ElInputNumber v-model="summaryLookupAudioId" :min="1" :controls="false" :disabled="Boolean(busy) || !!pendingSummary" />
        </ElFormItem>
        <ElFormItem label="原摘要请求键">
          <ElInput v-model.trim="summaryLookupKey" :disabled="Boolean(busy) || !!pendingSummary" maxlength="512"
            placeholder="原生成摘要时的 Idempotency-Key；找回使用当前 Bearer" />
        </ElFormItem>
        <ElFormItem>
          <ElSpace wrap>
            <ElButton :loading="busy === 'summary-recovery'" :disabled="Boolean(busy) || !apiKey.trim() || !summaryLookupAudioId || !summaryLookupKey.trim()"
              @click="recoverMeetingSummary">按原请求只读找回</ElButton>
            <ElButton v-if="pendingSummary?.summaryTaskId" :disabled="Boolean(busy) || !apiKey.trim()"
              @click="refreshOne(pendingSummary.summaryTaskId)">读取原摘要任务</ElButton>
          </ElSpace>
        </ElFormItem>
      </ElForm>

      <ElDivider content-position="left">提交 AI 任务</ElDivider>
      <ElForm label-width="110px" class="task-ai" @submit.prevent>
        <ElFormItem label="聊天模型">
          <ElSelect v-model="aiForm.model" filterable allow-create default-first-option clearable
            :loading="summaryModelsLoading" placeholder="选择已发布聊天模型" style="width: 280px">
            <ElOption v-for="model in summaryModels" :key="model.id" :label="`${model.name} (${model.code})`"
              :value="model.code" />
          </ElSelect>
          <ElButton :loading="summaryModelsLoading" @click="loadSummaryModels">刷新模型</ElButton>
        </ElFormItem>
        <p v-if="summaryModelsError" class="task-footnote">
          模型目录暂不可读：{{ summaryModelsError }}。可输入已发布模型代码；查看目录需单独授予 sand_ai.model.read。
        </p>
        <ElFormItem label="user message">
          <ElInput
            v-model="aiForm.content"
            type="textarea"
            :rows="3"
            placeholder="用户消息"
            style="max-width: 640px"
          />
        </ElFormItem>
        <ElFormItem label="source_block_ids">
          <ElInput
            v-model="aiForm.sourceBlockIdsText"
            placeholder="可选，逗号分隔，如 1,2,3"
            style="width: 360px"
          />
        </ElFormItem>
        <ElFormItem>
          <ElSpace wrap>
            <ElButton type="primary" :disabled="Boolean(busy) || newCallsBlocked" :loading="busy === 'submit'" @click="handleAiSubmit">
              提交 ai_completion
            </ElButton>
            <ElButton :disabled="!polling" @click="stopPolling">停止轮询</ElButton>
            <ElTag v-if="polling" type="warning" effect="plain">轮询中…</ElTag>
          </ElSpace>
        </ElFormItem>
      </ElForm>

      <ElAlert
        v-if="lastError"
        class="task-alert"
        type="error"
        :closable="true"
        :title="lastError.title"
        :description="lastError.description"
        @close="lastError = null"
      />

      <ElAlert v-if="mutationInFlight || newCallsBlocked" type="warning" :closable="false" class="task-alert"
        title="原操作尚待确认；可继续只读历史、成果、来源和费用，当前不能新建调用">
        <p v-if="mutationInFlight">原任务 #{{ mutationInFlight.taskId }} · {{ materialActionLabel(mutationInFlight.kind) }}进行中。只读请求完成不会解除原操作锁。</p>
        <ElSpace wrap>
          <ElButton v-for="id in pendingMaterialTaskIds" :key="id" :disabled="busy === 'read'" @click="refreshOne(id)">只读原任务 #{{ id }}</ElButton>
          <ElButton v-if="mutationInFlight && !pendingMaterialTaskIds.includes(mutationInFlight.taskId)" :disabled="busy === 'read'" @click="refreshOne(mutationInFlight.taskId)">只读原任务 #{{ mutationInFlight.taskId }}</ElButton>
        </ElSpace>
      </ElAlert>

      <ArtTable
        row-key="id"
        :loading="busy === 'read' && !polling"
        :data="recentTasks"
        :columns="columns"
      />

      <section v-if="authorizationIntents.length || authorizationStorageBlocked" class="task-alert" aria-label="原任务授权续接恢复">
        <ElAlert type="warning" :closable="false" title="原授权续接请求尚待确认"
          description="原任务和请求号已保留，不自动更新授权或再次派发。请使用原任务所属应用的当前有效凭证先读取原任务；读取状态变化不会确认此请求。按原号继续时，已应用的意图只返回原任务，未应用的意图仍会重新核验当前授权。" />
        <p v-for="intent in authorizationIntents" :key="intent.key">
          原任务 #{{ intent.taskId }} · 原续接请求 {{ intent.key }}
          <ElButton :disabled="Boolean(busy) || !apiKey.trim()" @click="refreshOne(intent.taskId)">只读原任务</ElButton>
          <ElButton :loading="busy === 'authorization-continue' && mutationInFlight?.taskId === intent.taskId"
            :disabled="Boolean(busy) || mutationInFlight !== null || materialStorageUnconfirmed || pendingMaterialTaskIds.length > 0 || authorizationStorageBlocked || !apiKey.trim()"
            @click="continueTaskAuthorization(intent.taskId, true)">按原号确认并继续</ElButton>
        </p>
        <p v-if="authorizationStorageBlocked" class="task-footnote">原续接记录无法安全读取或保存，暂不发送续接。请保留当前会话；恢复会话存储后重新读取原记录。</p>
        <ElButton v-if="authorizationStorageBlocked" :disabled="Boolean(busy)" @click="restoreAuthorizationIntents">重新读取原续接记录</ElButton>
      </section>

      <section v-if="meetingCorrectionIntents.length || meetingCorrectionStorageBlocked" class="task-alert" aria-label="原会议节点纠正请求">
        <ElAlert type="warning" :closable="false" title="原会议节点纠正请求尚待确认"
          description="保留原任务、失败节点和请求号。只读任务不会解除此记录；按原号继续时，已受理的请求只找回原任务，未受理的请求可能调用模型并计费。" />
        <p v-for="intent in meetingCorrectionIntents" :key="intent.key">
          原任务 #{{ intent.taskId }} · 节点 {{ intent.body.node_code }} · 请求 {{ intent.key }}
          <ElButton :disabled="Boolean(busy) || !apiKey.trim()" @click="refreshOne(intent.taskId)">只读原任务</ElButton>
          <ElButton :disabled="Boolean(busy) || mutationInFlight !== null || meetingCorrectionStorageBlocked || ocrRetryStorageBlocked || ocrRetryPending.length > 0 || materialStorageUnconfirmed || pendingMaterialTaskIds.length > 0 || authorizationStorageBlocked || authorizationIntents.length > 0 || !apiKey.trim()"
            @click="correctMeetingOutput(intent.taskId, intent.key)">按原号确认并继续</ElButton>
        </p>
        <p v-if="meetingCorrectionStorageBlocked">原纠正记录无法安全确认，暂不发送。请保留原会话和请求号。</p>
        <ElButton v-if="meetingCorrectionStorageBlocked" :disabled="Boolean(busy)" @click="restoreMeetingCorrectionIntents">重新读取原纠正记录</ElButton>
      </section>

      <section v-if="ocrRetryPending.length || ocrRetryStorageBlocked" class="task-alert" aria-label="原 OCR 恢复请求">
        <ElAlert type="warning" :closable="false" title="原 OCR 恢复请求待确认" description="保留原任务和请求号，只读找回；不会重发恢复或重新解析文件。" />
        <ElButton v-for="intent in ocrRetryPending" :key="`read:${intent.task_id}`" :disabled="Boolean(busy) || Boolean(ocrRetryFlight) || !apiKey.trim()" @click="refreshOne(intent.task_id)">只读找回原任务 #{{ intent.task_id }}</ElButton>
        <template v-for="intent in ocrRetryPending" :key="`release:${intent.task_id}`">
          <ElButton v-if="canReleaseOcrRetry(intent)" :disabled="Boolean(busy) || Boolean(ocrRetryFlight)" @click="releaseOcrRetryIntent(intent)">{{ intent.phase === 'rejected' || rejectedOcrRetryKeys.has(intent.key) ? '解除已明确拒绝的恢复意图' : '解除确认未发送的恢复意图' }}</ElButton>
        </template>
        <p v-if="ocrRetryNotice" class="task-footnote">{{ ocrRetryNotice }}</p>
      </section>
      <div v-if="activeTask" class="task-detail">
        <section v-if="activeTask.authorization_continuation?.required" class="task-alert" aria-label="原任务授权暂停">
          <ElAlert type="warning" :closable="false" title="原任务执行授权需要明确续接"
            description="原任务授权已暂停或过期。使用当前有效应用凭证明确继续时，服务端重新核验同一任务、来源和调用范围；页面不会自动续签。已完成节点、原来源版本和费用保留，结果待确认的节点仍只能找回原回执。" />
          <p class="task-footnote">{{ authorizationReason(activeTask) }}</p>
          <ElButton type="primary" :disabled="Boolean(busy) || newCallsBlocked || !apiKey.trim() || !activeTask.authorization_continuation.available"
            :loading="busy === 'authorization-continue' && mutationInFlight?.taskId === activeTask.id"
            @click="continueTaskAuthorization(activeTask.id)">使用当前授权继续原任务</ElButton>
          <ElButton :disabled="Boolean(busy) || !apiKey.trim()" @click="refreshOne(activeTask.id)">只读刷新原任务</ElButton>
        </section>
        <section v-if="activeTask.task_type === 'file_parse' && activeTask.ocr_before_dispatch_retry" class="task-alert" aria-label="OCR 发送前失败恢复">
          <p v-if="activeTask.ocr_before_dispatch_retry.consumed">原任务的一次 OCR 恢复机会已使用；后续状态请读取原任务确认。</p>
          <template v-else-if="canRetryOcrBeforeDispatch(activeTask)">
            <p>服务端确认原任务在发送 OCR 前耗尽尝试次数。可明确使用一次恢复机会，将同一文件、解析记录和任务重新入队；本次不创建新解析任务，之后实际 OCR 调用仍按当前授权与费用规则处理。</p>
            <ElButton type="primary" :loading="Boolean(ocrRetryFlight)" :disabled="Boolean(busy) || newCallsBlocked || !apiKey.trim()" @click="retryOcrBeforeDispatch(activeTask.id)">使用一次机会恢复原 OCR 任务</ElButton>
          </template>
          <p v-if="ocrRetryNotice" class="task-footnote">{{ ocrRetryNotice }}</p>
        </section>
        <section aria-label="任务清理状态">
          <h3 class="task-detail__title">任务清理状态</h3>
          <ElButton :disabled="Boolean(busy) || !apiKey.trim()" @click="refreshOne(activeTask.id)">刷新原任务清理状态</ElButton>
          <ElDescriptions :column="2" border>
            <ElDescriptionsItem label="清理状态">{{ cleanupStateLabel(activeTask.cleanup?.state) }}</ElDescriptionsItem>
            <ElDescriptionsItem label="完成时间">{{ activeTask.cleanup?.completed_at ?? '未确认' }}</ElDescriptionsItem>
            <ElDescriptionsItem label="来源内容">{{ activeTask.cleanup ? (activeTask.cleanup.source_available ? '清理未阻断，仍需来源授权与解析' : '此视图不可用') : '未确认' }}</ElDescriptionsItem>
            <ElDescriptionsItem v-for="resource in cleanupResourceLabels" :key="resource.key" :label="resource.label">{{ cleanupStateLabel(activeTask.cleanup?.resources[resource.key]) }}</ElDescriptionsItem>
            <ElDescriptionsItem label="现存来源块数">{{ activeTask.cleanup?.source_block_count ?? '未确认' }}</ElDescriptionsItem>
            <ElDescriptionsItem label="现存解析记录数">{{ activeTask.cleanup?.parse_record_count ?? '未确认' }}</ElDescriptionsItem>
          </ElDescriptions>
          <p class="task-footnote">以上只表示原任务记录提供的清理证据，未确认不表示已清理。任务成果已清理不证明原文件、派生文本或知识库向量全部清理。费用与最小历史保留不表示已计价或零费用，金额按原费用记录查看。</p>
        </section>

        <ElAlert v-if="activeTask.task_type === 'file_parse' && activeTask.state === 'canceled'" type="info" :closable="false"
          title="原解析已取消，不能作为失败任务重试；取消不代表没有费用。"
          :description="activeTask.parse_restart?.allowed === true
            ? '当前证据确认原任务未发送 OCR 请求，可在文件中心核对该文件后显式重新解析；新任务使用当前配置并单独计量。'
            : '当前没有完整的未派发证明。请保留原任务核对；已有或结果不明的 OCR 请求不能通过新解析绕过。'" class="task-alert" />
        <h3 class="task-detail__title">任务 #{{ activeTask.id }} 步骤</h3>
        <ArtTable
          row-key="code"
          :data="activeTask.steps ?? []"
          :columns="stepColumns"
          empty-text="暂无步骤（查询详情后显示）"
        />

        <template v-if="activeTask.task_type === 'structured_workflow'">
          <ElAlert v-if="materialActions.has(activeTask.id)" type="warning" :closable="false"
            title="原材料任务操作尚待确认" class="task-alert">
            <p>原任务 #{{ activeTask.id }} · {{ materialActionLabel(materialActions.get(activeTask.id)?.kind) }}结果未确认。
              请只读原任务；本任务暂不能再次取消、重试或恢复回执。</p>
            <ElButton :disabled="Boolean(busy)" @click="refreshOne(activeTask.id)">读取原任务结果</ElButton>
          </ElAlert>
          <ElAlert v-if="activeTask.state === 'canceled'" type="info" :closable="false"
            title="材料处理已取消；已完成文件成果和已发生费用保留"
            description="取消后的原任务不能作为失败任务重试。原调用结果未知时，请继续读取原任务核对。" class="task-alert" />
          <section v-if="activeTask.receipt_only_recovery?.target === 'cross_file_review'" class="task-review">
            <p>原跨文件复核可用恢复机会：{{ activeTask.receipt_only_recovery.attempt_count }} /
              {{ activeTask.receipt_only_recovery.max_attempts }} 已使用。恢复只使用已有回执，不发起新模型调用。</p>
            <ElButton :loading="mutationInFlight?.kind === 'receipt' && mutationInFlight.taskId === activeTask.id"
              :disabled="Boolean(busy) || newCallsBlocked || materialChunkUnconfirmed || !activeTask.receipt_only_recovery.available || materialActions.has(activeTask.id)"
              @click="recoverMaterialReceipt(activeTask.id)">仅恢复原跨文件复核结果</ElButton>
            <p v-if="!activeTask.receipt_only_recovery.available" class="task-footnote">
              当前回执不可恢复。请读取原任务核对，不要重新提交材料或更换恢复请求。
            </p>
          </section>
          <ElAlert
            v-if="workflowError"
            type="warning"
            :closable="false"
            title="材料运行详情暂不可读"
            :description="workflowError.description"
            class="task-alert"
          />
          <template v-if="workflowRun?.workflow.code === 'material_analysis'">
            <h3 class="task-detail__title">材料文件进度</h3>
            <ElAlert v-if="materialChunkUnconfirmed" type="warning" :closable="false" class="task-alert"
              title="原分块调用结果待确认"
              description="请只读刷新原任务找回调用结果；不要重新提交材料或更换请求号。已完成分段保留，当前不能重试或恢复跨文件回执。">
              <ElButton :disabled="Boolean(busy)" @click="refreshOne(activeTask.id)">只读刷新原任务</ElButton>
            </ElAlert>
            <ArtTable
              row-key="file_id"
              :data="workflowRun.file_states"
              :columns="fileColumns"
              empty-text="暂无固定文件"
            />
            <p v-if="workflowRun.file_states.some(file => file.chunk_progress?.state === 'failed')"
              class="task-footnote">确认失败后可沿原任务重试失败文件；已完成分段沿原记录恢复，不重复调用模型。文件完成与成果仍以原文件状态和封存结果为准。</p>
            <p v-if="workflowRun.cross_file_review" class="task-footnote">
              跨文件核对：{{ workflowRun.cross_file_review.state }} ·
              尝试 {{ workflowRun.cross_file_review.attempt_count }}
              <span v-if="workflowRun.cross_file_review.error_code">
                · {{ workflowRun.cross_file_review.error_code }}
              </span>
            </p>
            <template v-if="workflowRun.file_results?.length">
              <h3 class="task-detail__title">已完成文件成果</h3>
              <ElCollapse v-model="materialResultFileId" accordion>
                <ElCollapseItem
                  v-for="fileResult in workflowRun.file_results"
                  :key="fileResult.file_id"
                  :title="`文件 #${fileResult.file_id} · 尝试 ${fileResult.attempt_count} · ${fileResult.citations.length} 个来源`"
                  :name="fileResult.file_id"
                >
                  <MaterialFileResultPages v-if="fileResult.paged && materialResultFileId === fileResult.file_id"
                    :bearer="apiKey" :owner-id="userStore.info.id" :run-id="workflowRun.run_id"
                    :task-id="activeTask.id" :file-result="fileResult"
                    @open-source="openWorkflowSource" @clear-source="clearWorkflowSource" @access-denied="clearOnAccessDenied" />
                  <MaterialResult v-else-if="!fileResult.paged" :payload="fileResult.result.payload"
                    :citations="fileResult.citations" @open-source="openWorkflowSource" />
                  <p
                    v-for="citation in fileResult.citations"
                    :key="`${citation.field_path}:${citation.source_block_id}`"
                    class="task-footnote"
                  >
                    {{ citation.field_path }} · 来源块 #{{ citation.source_block_id }} ·
                    {{ citation.locator_type }} {{ JSON.stringify(citation.locator) }}
                    <ElButton link type="primary" @click="openWorkflowSource(citation.source_block_id)">查看文字</ElButton>
                  </p>
                </ElCollapseItem>
              </ElCollapse>
            </template>
            <h3 class="task-detail__title">最终材料成果</h3>
            <template v-if="workflowRun.result">
              <ElAlert
                v-if="workflowRun.artifact?.review_required"
                title="成果包含待核实项，请按来源复核后使用"
                type="warning"
                :closable="false"
              />
              <p class="task-footnote">
                {{ workflowRun.result.schema.code }} · {{ workflowRun.result.schema.version }}
              </p>
              <MaterialResult :payload="workflowRun.result.payload" :citations="workflowRun.citations" @open-source="openWorkflowSource" />
              <h3 class="task-detail__title">最终成果来源</h3>
              <ArtTable
                :row-key="workflowCitationKey"
                :data="workflowRun.citations"
                :columns="workflowCitationColumns"
                empty-text="最终成果无字段来源"
              />
            </template>
            <p v-else class="task-footnote">最终成果尚未生成；已封存文件可在上方查看。</p>
            <h3 class="task-detail__title">本次材料运行计量</h3>
            <p class="task-footnote">
              用量状态：{{ usageStateLabel(workflowRun.usage.state) }}
              <span v-if="workflowRun.usage.total_tokens !== null">
                · {{ workflowRun.usage.total_tokens }} Token
              </span>
              <span v-if="workflowRun.usage.attempt_count !== undefined">
                · 调用尝试 {{ workflowRun.usage.attempt_count }} 次
                · 计量事件 {{ workflowRun.usage.event_count }} 条
                · 缺失 {{ workflowRun.usage.missing_event_count }} 条
              </span>
            </p>
            <p class="task-footnote">
              费用状态：{{ costStateLabel(workflowRun.cost.state) }}
              <span v-if="workflowRun.cost.metered_amount !== null && workflowRun.cost.currency">
                · {{ workflowRun.cost.metered_amount }} {{ workflowRun.cost.currency }}
              </span>
              <span v-if="workflowRun.cost.unpriced_count">
                · 未定价 {{ workflowRun.cost.unpriced_count }} 条
              </span>
            </p>
            <p v-if="workflowRun.cost.by_currency && Object.keys(workflowRun.cost.by_currency).length"
               class="task-footnote">
              已知分币种金额：
              <span v-for="(amount, currency) in workflowRun.cost.by_currency" :key="currency">
                {{ amount }} {{ currency }}
              </span>
            </p>
          </template>
        </template>

        <template v-if="activeTask.task_type === 'audio_transcription'">
          <h3 class="task-detail__title">音频转写</h3>
          <template v-if="activeTask.result?.schema?.code === 'sand_ai.audio_transcription'">
            <ElAlert
              v-if="activeTask.result.review_required"
              title="转写内容需人工复核后使用"
              type="warning"
              :closable="false"
            />
            <p class="task-footnote">
              音频时长：{{ activeTask.result.usage?.audio_seconds ?? '未知' }} 秒
            </p>
            <ElAlert
              v-if="hasInputRangeTranscript"
              title="这是整段音频的转写，时间仅表示输入范围。引擎未提供句子时间或说话人区分；请从整段音频回听核对。"
              type="info"
              :closable="false"
            />
            <p class="task-footnote">
              转写计量金额：
              <template v-if="activeTask.audio_usage_cost?.state === 'priced'">
                {{ activeTask.audio_usage_cost.metered_amount }}
                {{ activeTask.audio_usage_cost.currency }}
                · 价格版本 #{{ activeTask.audio_usage_cost.price_revision_id }}
              </template>
              <template v-else-if="activeTask.audio_usage_cost?.state === 'unpriced'">未配置价格</template>
              <template v-else>暂不可确认</template>
            </p>
            <ArtTable
              row-key="start_ms"
              :data="activeTask.result.segments ?? []"
              :columns="audioSegmentColumns"
              empty-text="暂无可读转写段落"
            />
            <p v-if="activeTask.result.warnings?.length" class="task-footnote">
              转写提示：{{ activeTask.result.warnings.map(audioWarningLabel).join('、') }}
            </p>
            <ElButton v-if="activeTask.state === 'succeeded'" :loading="busy === 'review-read'"
              :disabled="Boolean(busy)" @click="loadAudioReviews(activeTask.id)">读取片段复核</ElButton>
              <ElAlert v-if="reviewRecoveryBlocked.has(activeTask.id)" type="warning" :closable="false" title="原复核元数据暂不可确认，当前不能新建复核或摘要；请恢复浏览器会话存储后重新读取片段复核。" />
              <ElAlert v-if="activePendingReview" type="warning" :closable="false"
                title="原复核保存结果尚未确认">
                <p>原片段 #{{ activePendingReview.index }}，原版本 {{ activePendingReview.revision }}。
                  请按原请求号只读找回；无需原正文，本任务暂不能提交另一份复核。</p>
                <ElButton :loading="busy === 'review-recovery'" :disabled="Boolean(busy) || materialTaskActionInFlight() || !apiKey.trim()"
                  @click="recoverAudioReview(activeTask.id)">只读找回本次复核</ElButton>
              </ElAlert>
            <section v-if="audioReview?.taskId === activeTask.id" class="task-review">
              <p class="task-footnote">
                当前复核版本 {{ audioReview.revision }}。修订会保留原始转写和音频时间来源；
                操作者归属记录为当前应用的工作负载。原始说话人、识别置信度与引擎提示保留供回听核对，
                不因保存文字而成为已验证事实。
              </p>
              <p v-if="hasInputRangeReview" class="task-footnote">
                整段转写只能修订文字和一个说话人编号。保存后保留原输入范围和来源引用，不能拆分或修改时间；
                多位说话人的对话请在文字中人工注明，不能据此确定逐句时间。
              </p>
              <ElSpace wrap>
                <ElButton :loading="busy === 'audio-source'" :disabled="Boolean(busy)"
                  @click="loadAudioSource(activeTask.id)">加载私有音频</ElButton>
                <ElButton :disabled="reviewSegmentIndex === null || Boolean(busy)"
                  @click="playReviewSegment(activeTask.id)">从所选片段回听</ElButton>
              </ElSpace>
              <audio v-if="audioSource?.taskId === activeTask.id" ref="audioPlayer"
                class="task-review__player" controls :src="audioSource.url" preload="metadata" />
              <ElForm inline @submit.prevent>
                <ElFormItem label="片段">
                  <ElSelectV2 v-model="reviewSegmentIndex" :options="reviewSegmentOptions" filterable
                    placeholder="搜索片段文字、时间或说话人" style="width: 420px"
                    @change="selectReviewSegment" />
                </ElFormItem>
                <ElFormItem label="复核文字">
                  <ElInput v-model="reviewText" type="textarea" :rows="2" maxlength="10000"
                    :disabled="Boolean(busy) || selectedReviewPending" @input="retainReviewDraft"
                    placeholder="按音频回听修订；留空表示该段仍无可用文字" style="width: 420px" />
                </ElFormItem>
                <ElFormItem label="说话人编号">
                  <ElInput v-model="reviewSpeaker" maxlength="16" placeholder="speaker_1；留空为未知"
                    :disabled="Boolean(busy) || selectedReviewPending" @input="retainReviewDraft"
                    style="width: 200px" />
                </ElFormItem>
                <p v-if="selectedReviewLocator?.granularity === 'input_range_only'" class="task-footnote">
                  所选范围是整段输入；填写说话人编号表示人工标注整段，不代表引擎已识别说话人。
                </p>
                <ElFormItem>
                  <ElButton type="primary" :loading="mutationInFlight?.kind === 'review_save' && mutationInFlight.taskId === activeTask.id"
                    :disabled="Boolean(busy) || newCallsBlocked || reviewSegmentIndex === null || !!activePendingReview || reviewNeedsRefresh.has(activeTask.id) || reviewRecoveryBlocked.has(activeTask.id)"
                    @click="saveAudioReview(activeTask.id)">保存复核</ElButton>
                </ElFormItem>
              </ElForm>
              <p v-if="selectedReviewDraft" class="task-footnote">
                此片段有未保存草稿（原复核版本 {{ selectedReviewDraft.revision }}，当前版本 {{ audioReview.revision }}）。
                重新读取和切换任务后会在本页面恢复；请按当前音频和版本确认后保存。离开页面或切换身份会清除草稿。
              </p>
              <p v-if="reviewNeedsRefresh.has(activeTask.id)" class="task-footnote">
                本次复核未保存，草稿已保留。请点击“读取片段复核”取得当前版本，再确认草稿并保存。
              </p>
              <h4>当前复核转写</h4>
              <ElSpace wrap>
                <ElInput v-model="reviewSearch" clearable placeholder="搜索复核文字或说话人"
                  aria-label="搜索复核片段" style="width: 300px" />
                <ElCheckbox v-model="reviewOnlyUnresolved">仅看无可用文字、说话人未知或有引擎提示的片段</ElCheckbox>
              </ElSpace>
              <p class="task-footnote">
                共 {{ audioReview.segments.length }} 个片段，符合筛选 {{ filteredReviewSegments.length }} 个。
                筛选只改变显示范围，不删除片段，也不改变摘要使用的完整复核版本。
              </p>
              <ElTable :data="visibleReviewSegments" size="small" empty-text="没有符合筛选的片段">
                <ElTableColumn label="音频范围" min-width="230">
                  <template #default="{ row }">{{ audioRangeLabel(row.start_ms, row.end_ms, reviewLocator(row.segment_index)) }}</template>
                </ElTableColumn>
                <ElTableColumn label="说话人" min-width="130">
                  <template #default="{ row }">{{ row.speaker || '未知' }}</template>
                </ElTableColumn>
                <ElTableColumn label="原始说话人" min-width="130">
                  <template #default="{ row }">{{ row.original_speaker || '未提供' }}</template>
                </ElTableColumn>
                <ElTableColumn label="原始识别置信度" min-width="150">
                  <template #default="{ row }">{{ audioConfidenceLabel(row.confidence, row.warnings) }}</template>
                </ElTableColumn>
                <ElTableColumn label="复核提示" min-width="220">
                  <template #default="{ row }">{{ row.warnings.map(audioWarningLabel).join('、') || '—' }}</template>
                </ElTableColumn>
                <ElTableColumn label="原始转写" prop="original_text" min-width="260" />
                <ElTableColumn label="复核文字" prop="text" min-width="300" />
                <ElTableColumn label="操作" width="110">
                  <template #default="{ row }">
                    <ElButton :disabled="Boolean(busy)" @click="editReviewSegment(row.segment_index)">复核此段</ElButton>
                  </template>
                </ElTableColumn>
              </ElTable>
              <ElPagination v-model:current-page="reviewPage" :page-size="REVIEW_PAGE_SIZE"
                :total="filteredReviewSegments.length" layout="total, prev, pager, next, jumper"
                aria-label="复核片段分页" />
            </section>
            <ElForm v-if="activeTask.state === 'succeeded'" inline @submit.prevent>
              <ElFormItem label="摘要模型">
                <ElSelect v-model="summaryModel" :disabled="Boolean(busy) || !!pendingSummary" filterable allow-create default-first-option clearable
                  :loading="summaryModelsLoading" placeholder="选择已发布聊天模型" style="width: 280px">
                  <ElOption v-for="model in summaryModels" :key="model.id" :label="`${model.name} (${model.code})`"
                    :value="model.code" />
                </ElSelect>
                <ElButton :loading="summaryModelsLoading" @click="loadSummaryModels">刷新模型</ElButton>
              </ElFormItem>
              <ElFormItem>
                <ElButton type="primary" :loading="mutationInFlight?.kind === 'summary_submit' && mutationInFlight.taskId === activeTask.id" :disabled="Boolean(busy) || newCallsBlocked || !!pendingSummary || !!summaryLookupKey.trim() || summaryStorageBlocked || Boolean(summaryReviewBlock(activeTask.id)) || !canSummarize(activeTask)"
                  @click="handleMeetingSummary(activeTask.id)">生成会议摘要</ElButton>
              </ElFormItem>
            </ElForm>
            <p v-if="activeTask.state === 'succeeded' && summaryReviewBlock(activeTask.id)" class="task-footnote">{{ summaryReviewBlock(activeTask.id) }}</p>
            <p v-if="summaryStorageBlocked" class="task-footnote">摘要原号的会话存储暂不可确认，当前不能新建摘要；请恢复会话存储后重新进入页面。</p>
            <p v-if="activeTask.state === 'succeeded'" class="task-footnote">
              摘要使用{{ audioReview?.taskId === activeTask.id ? `复核版本 ${audioReview.revision}` : '原始转写' }}，
              并产生独立计费任务；原音频须允许经批准的云端处理，当前身份还须具备聊天与音频服务授权。
            </p>
            <p v-if="activeTask.state === 'succeeded' && summaryModelsError" class="task-footnote">
              模型目录读取失败：{{ summaryModelsError }}。可继续手动输入已发布模型代码，或为该应用配置 sand_ai.model.read 授权后刷新。
            </p>
            <p v-if="activeTask.state === 'succeeded' && !canSummarize(activeTask)" class="task-footnote">
              这份音频没有可用的转写文字，请先按时间回听。
            </p>
          </template>
          <p v-else class="task-footnote">转写结果尚未生成或暂不可读。</p>
        </template>

        <section v-if="activeTask.summary_progress">
          <h3 class="task-detail__title">会议摘要进度</h3>
          <p class="task-footnote">
            节点 {{ activeTask.summary_progress.completed_nodes }}/{{ activeTask.summary_progress.planned_nodes }} 已完成
            · 失败 {{ activeTask.summary_progress.failed_nodes }}
            · 来源片段 {{ activeTask.summary_progress.covered_segments }}/{{ activeTask.summary_progress.total_segments }} 已纳入
            <span v-if="activeTask.summary_progress.active_node_code">
              · 当前节点 {{ activeTask.summary_progress.active_node_code }}
            </span>
          </p>
        </section>
        <section v-if="activeTask.meeting_output_correction" class="task-alert" aria-label="会议失败节点纠正">
          <p v-if="meetingCorrectionExpectation(activeTask)">节点 {{ activeTask.meeting_output_correction.node_code }} 的结果{{ activeTask.meeting_output_correction.reason === 'missing_sources' ? '缺少必填来源引用' : '包含重复来源引用' }}，未被接受。问题位置：{{ meetingCorrectionLocation(activeTask.meeting_output_correction.reason_path) }}。可明确纠正该失败节点并继续原摘要；这会新建一次付费尝试，已成功节点、旧结果和费用保留。</p>
          <p v-else>当前没有可明确纠正的失败节点。结果未知、执行中或其他失败只读原任务，不重新调用。</p>
          <ElButton v-if="meetingCorrectionExpectation(activeTask)" type="primary"
            :disabled="Boolean(busy) || newCallsBlocked || !apiKey.trim()"
            :loading="busy === 'meeting-correction'" @click="correctMeetingOutput(activeTask.id)">纠正失败节点并继续</ElButton>
        </section>
        <section v-if="meetingNodeCost">
          <h3 class="task-detail__title">会议摘要费用</h3>
          <p v-if="meetingNodeCost.calls.length === 0" class="task-footnote">尚无已记录调用，费用待确认。</p>
          <template v-else>
            <p v-for="total in meetingNodeCost.totals" :key="total.currency ?? '_unknown_'" class="task-footnote">
              {{ total.currency ?? '币种未确认' }}：
              <template v-if="total.metered_amount !== null && total.currency">
                累计已确认 {{ total.metered_amount }} {{ total.currency }}
              </template>
              <template v-else>累计金额未知或待结算</template>
              · Token {{ total.total_tokens ?? '未测得' }}
            </p>
            <details>
              <summary>查看 {{ meetingNodeCost.calls.length }} 次节点调用费用</summary>
              <p v-for="call in meetingNodeCost.calls" :key="call.invocation_id" class="task-footnote">
                {{ call.node_code }} · 调用 #{{ call.invocation_id }}：
                <template v-if="call.metered_amount !== null && call.currency">
                  {{ call.metered_amount }} {{ call.currency }}
                </template>
                <template v-else-if="call.pricing_state === 'unpriced'">未计价</template>
                <template v-else>金额未知或待结算</template>
                · Token {{ call.total_tokens ?? '未测得' }}
                · 价格版本 #{{ call.price_revision_id ?? '未知' }}
              </p>
            </details>
          </template>
        </section>
        <p v-else-if="legacyMeetingCost" class="task-footnote">
          旧摘要单次调用费用：
          <template v-if="legacyMeetingCost.metered_amount !== null && legacyMeetingCost.currency">
            {{ legacyMeetingCost.metered_amount }} {{ legacyMeetingCost.currency }}
            · 价格版本 #{{ legacyMeetingCost.price_revision_id ?? '未知' }}
          </template>
          <template v-else>金额未知或待结算</template>
          · Token {{ legacyMeetingCost.total_tokens ?? '未测得' }}
        </p>
        <template v-if="activeTask.result?.meeting_summary_error">
          <h3 class="task-detail__title">会议摘要</h3>
          <ElAlert title="模型本次未生成可引用的摘要；本次调用可能已产生费用。请核对转写后发起新请求。" type="warning" :closable="false" />
        </template>
        <template v-else-if="displayedMeetingResult?.meeting_summary">
          <h3 class="task-detail__title">会议摘要 #{{ displayedMeetingTask?.id }}</h3>
          <ElAlert title="摘要和转写均需人工复核后使用" type="warning" :closable="false" />
          <p class="task-footnote">
            来源：音频任务 #{{ displayedMeetingResult.audio_task_id }}
            · 复核版本 {{ displayedMeetingResult.audio_review_revision ?? '未提供' }}
          </p>
          <p class="task-footnote">{{ meetingReviewVersionNotice }}</p>
          <ElButton v-if="activeTask.id !== displayedMeetingResult.audio_task_id"
            :loading="busy === 'summary-audio-review'" :disabled="Boolean(busy) || !apiKey.trim()"
            @click="openMeetingAudioReview">读取当前复核并打开原音频任务</ElButton>
          <p v-else class="task-footnote">
            上方可复核原音频并生成当前版本摘要；下方保留这份旧摘要的文字和原版本来源，不随复核修改。
          </p>
          <audio v-if="audioSource?.taskId === displayedMeetingResult.audio_task_id" ref="audioPlayer"
            class="task-review__player" controls :src="audioSource?.url" preload="metadata" />
          <section v-if="displayedMeetingResult.unrecognized_audio_ranges?.length">
            <h4>待回听音频</h4>
            <p class="task-footnote">以下时段没有识别出文字，未作为摘要事实来源。请结合原音频复核。</p>
            <p v-for="range in displayedMeetingResult.unrecognized_audio_ranges" :key="`${range.start_ms}-${range.end_ms}`">
              {{ audioTime(range.start_ms) }}–{{ audioTime(range.end_ms) }}
              <ElButton link type="primary" :disabled="Boolean(busy) || !displayedMeetingResult.audio_task_id"
                @click="playMeetingSource(displayedMeetingResult.audio_task_id, range.start_ms)">回听</ElButton>
            </p>
          </section>
          <section v-for="section in meetingSummarySections" :key="section.key">
            <h4>{{ section.label }}</h4>
            <p v-if="!displayedMeetingResult.meeting_summary[section.key].length" class="task-footnote">暂无可确认内容</p>
            <div v-for="(claim, index) in displayedMeetingResult.meeting_summary[section.key]" :key="`${section.key}-${index}`">
              <p>{{ claim.text }}</p>
              <p class="task-footnote">
                来源：
                <span v-for="reference in claim.references" :key="reference.segment_id">
                  {{ reference.segment_id }}
                  {{ audioRangeLabel(reference.source.locator.start_ms, reference.source.locator.end_ms, reference.source.locator) }}
                  <span v-if="reference.source.locator.granularity === 'input_range_only'">
                    （仅能定位整段输入，无法确定这项内容的具体发生时间）
                  </span>
                  <ElButton link type="primary" :disabled="Boolean(busy) || !displayedMeetingResult.audio_task_id"
                    @click="playMeetingSource(displayedMeetingResult.audio_task_id, reference.source.locator.start_ms)">
                    回听来源
                  </ElButton>
                </span>
              </p>
            </div>
          </section>
        </template>
        <template v-else-if="activeTask.task_type === 'file_parse'">
          <FileParseSources v-if="activeTask.state === 'succeeded' && activeTask.resource_type === 'file'"
            :bearer="apiKey" :owner-id="userStore.info.id" :task-id="activeTask.id"
            :file-id="activeTask.resource_id" :parse-id="activeTask.file_parse_id"
            :source-available="activeTask.cleanup?.source_available !== false"
            @access-denied="clearOnAccessDenied" />
          <p v-else class="task-footnote">文件解析尚未成功，不能作为可读取的解析来源；请核对原任务状态。</p>
        </template>
        <template v-else-if="activeTask.task_type !== 'audio_transcription' && activeTask.task_type !== 'structured_workflow'">
        <h3 class="task-detail__title">结果</h3>
        <ElInput
          type="textarea"
          :rows="5"
          readonly
          :model-value="activeTask.result?.output?.content ?? ''"
          placeholder="完成后显示生成内容（不回显 prompt）"
        />
        <p v-if="activeTask.result?.review_required !== undefined" class="task-footnote">
          review_required={{ activeTask.result.review_required }}
        </p>

        <h3 class="task-detail__title">来源引用</h3>
        <ArtTable
          row-key="source_block_id"
          :data="activeTask.result?.source_references ?? []"
          :columns="refColumns"
          empty-text="暂无来源引用"
        />
        </template>
      </div>
    </ElCard>
    <ElDialog v-model="sourceOpen" title="材料来源文字" width="640px" destroy-on-close @closed="clearWorkflowSource">
      <p v-if="sourceLoading" class="task-footnote">正在核验并读取来源…</p>
      <ElAlert v-else-if="sourceError" type="error" :title="sourceError" :closable="false" />
      <template v-else-if="sourcePreview">
        <p class="task-footnote">
          文件 #{{ sourcePreview.file_id }} · 来源块 #{{ sourcePreview.source_block_id }} ·
          {{ sourcePreview.locator_type }} {{ JSON.stringify(sourcePreview.locator) }}
        </p>
        <p class="task-footnote">引用字段：{{ sourcePreview.field_paths.join('、') }}</p>
        <MaterialSourceContent :source="sourcePreview" />
      </template>
    </ElDialog>
  </div>
</template>

<script setup lang="ts">
  import { computed, h, nextTick, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
  import { useRoute } from 'vue-router'
  import { ElButton, ElMessage, ElMessageBox, ElSpace, ElSelectV2, ElPagination, ElCheckbox } from 'element-plus'
  import type { ColumnOption } from '@/types'
  import { useUserStore } from '@/store/modules/user'
  import aiTaskApi from '../api/aiTask'
  import audioReviewApi, { type AudioReviewSnapshot } from '../api/audioReview'
  import type { RuntimeChatModel, TaskSummaryWithReceiptRecovery, TaskActionExpectation, MaterialRetryExpectation, MeetingOutputCorrectionExpectation } from '../api/task'
  import taskApi from '../api/task'
  import { beginMaterialTaskActionFlight, finishMaterialTaskActionFlight, materialTaskActionInFlight, pendingMaterialTaskActionIds, readMaterialTaskAction, retainMaterialTaskAction, reconcileMaterialTaskAction, type MaterialTaskAction } from '../api/materialTaskAction'
  import { readRuntimeBearer } from '../api/runtimeBearerSession'
  import { newRuntimeRequestId, SandAiRuntimeError } from '../api/runtime'
  import { toRuntimeUiError } from '../api/runtimeErrors'
  import type { RuntimeUiError } from '../api/runtimeErrors'
  import type {
    SandAiCleanupState,
    SandAiCleanupStatus,
    SandAiSourceReference,
    SandAiTask,
    SandAiTaskStep,
    SandAiAudioSegment,
    SandAiAudioLocator,
    SandAiMeetingSummaryCost,
    SandAiLegacyMeetingSummaryCost,
    SandAiMaterialChunkProgress,
    SandAiWorkflowFileState,
    SandAiWorkflowRun,
    SandAiWorkflowSource
  } from '../api/types'
  import RuntimeApiKeyField from '../components/RuntimeApiKeyField.vue'
  import MaterialResult from './MaterialResult.vue'
  import MaterialSourceContent from '../components/MaterialSourceContent.vue'
  import FileParseSources from '../components/FileParseSources.vue'
  import MaterialFileResultPages from '../components/MaterialFileResultPages.vue'

  const cleanupResourceLabels: readonly { key: keyof SandAiCleanupStatus['resources']; label: string }[] = [
    { key: 'file', label: '原文件' }, { key: 'derived_text', label: '派生文本' },
    { key: 'vectors', label: '知识库向量' }, { key: 'results', label: '成果内容' },
    { key: 'usage_history', label: '用量与费用历史' }, { key: 'audit_history', label: '最小审计历史' }
  ]
  function cleanupStateLabel(state: SandAiCleanupState | 'not_applicable' | undefined): string {
    return state === 'retained' ? '保留' : state === 'pending' ? '清理中' : state === 'failed' ? '清理失败'
      : state === 'purged' ? '已清理' : state === 'not_applicable' ? '无向量资源需清理' : '未确认'
  }

  const TERMINAL = new Set(['succeeded', 'failed', 'canceled'])
  const meetingSummarySections = [
    { key: 'summary', label: '摘要' },
    { key: 'key_points', label: '要点' },
    { key: 'decisions', label: '决定' },
    { key: 'action_items', label: '行动项' },
    { key: 'unresolved', label: '待确认' }
  ] as const
  const POLL_MS = 2000
  const MAX_POLLS = 60

  const userStore = useUserStore()
  const apiKey = ref('')
  const lookupId = ref<number | undefined>(undefined)
  const recentTasks = ref<SandAiTask[]>([])
  const activeTask = ref<TaskSummaryWithReceiptRecovery | null>(null)
  const retainedMeetingTask = ref<TaskSummaryWithReceiptRecovery | null>(null)
  const displayedMeetingTask = computed(() => activeTask.value?.result?.meeting_summary
    ? activeTask.value
    : activeTask.value?.task_type === 'audio_transcription'
      && retainedMeetingTask.value?.result?.audio_task_id === activeTask.value.id
      ? retainedMeetingTask.value : null)
  const displayedMeetingResult = computed(() => displayedMeetingTask.value?.result)
  const meetingReviewVersionNotice = computed(() => {
    const result = displayedMeetingResult.value
    const frozen = result?.audio_review_revision
    if (typeof frozen !== 'number' || !Number.isSafeInteger(frozen) || frozen < 0) {
      return '这份摘要未提供可确认的复核版本；当前复核是否更新尚未判定。'
    }
    const snapshot = audioReview.value
    const current = snapshot && snapshot.taskId === result?.audio_task_id ? snapshot.revision : null
    if (current === null) return `这份摘要固定使用复核版本 ${frozen}；当前复核版本尚未读取，是否更新尚未判定。`
    if (current !== frozen) return `当前复核版本 ${current} 与这份摘要的固定版本 ${frozen} 不同；这份摘要保留原版本来源，需要重新生成才能使用当前复核。`
    return `当前复核版本 ${current} 与这份摘要一致；保存新的复核后，可提交新版本摘要。`
  })
  const meetingNodeCost = computed<SandAiMeetingSummaryCost | null>(() => {
    const cost = activeTask.value?.meeting_summary_cost
    return cost && 'calls' in cost ? cost : null
  })
  const legacyMeetingCost = computed<SandAiLegacyMeetingSummaryCost | null>(() => {
    const cost = activeTask.value?.meeting_summary_cost
    return cost && 'usage_state' in cost ? cost : null
  })
  const workflowRun = ref<SandAiWorkflowRun | null>(null)
  const materialResultFileId = ref<string | number>('')
  const workflowError = ref<RuntimeUiError | null>(null)
  const sourceOpen = ref(false)
  const sourceLoading = ref(false)
  const sourcePreview = ref<SandAiWorkflowSource | null>(null)
  const sourceError = ref('')
  let sourceGeneration = 0
  const busy = ref<'read' | 'cancel' | 'retry' | 'authorization-continue' | 'receipt-recovery' | 'submit' | 'summary' | 'summary-recovery' | 'review-read' | 'review-save' | 'review-recovery' | 'audio-source' | 'meeting-correction' | ''>('')
  const lastError = ref<RuntimeUiError | null>(null)
  const polling = ref(false)
  let pollTimer: ReturnType<typeof setTimeout> | null = null
  let pollCount = 0
  let viewGeneration = 0
  let credentialEpoch = 0
  type MutationFlight = { token: symbol; taskId: number; kind: 'cancel' | 'retry' | 'authorization_continue' | 'receipt' | 'review_save' | 'summary_submit' | 'meeting_correction'; owner: number | undefined; credential: string; epoch: number }
  const mutationInFlight = ref<MutationFlight | null>(null)
  const storedMaterialTaskIds = ref<number[]>([])
  const materialStorageUnconfirmed = ref(false)
  const pendingMaterialTaskIds = computed(() => [...new Set([...storedMaterialTaskIds.value, ...materialActions.value.keys()])])
  type AuthorizationIntent = { taskId: number; taskType: string; resourceType: string; resourceId: number; key: string }
  const authorizationIntents = ref<AuthorizationIntent[]>([])
  const authorizationStorageBlocked = ref(false)
  let authorizationIntentOwner: number | undefined
  const authorizationStorageKey = () => `sand-ai-task-authorization-continuation:${userStore.info.id}`
  type OcrRetryIntent = { version: 1; admin_id: number; task_id: number; file_id: number; parse_id: number; expected_attempt_count: number; retry_attempt_limit: number; key: string; phase?: 'prepared' | 'sent' | 'rejected'; rejection_code?: string | null }
  const ocrRetryPending = ref<OcrRetryIntent[]>([])
  const ocrRetryStorageBlocked = ref(false)
  const ocrRetryFlight = ref<symbol | null>(null)
  const ocrRetryNotice = ref('')
  const unsentOcrRetryKeys = new Set<string>()
  const rejectedOcrRetryKeys = new Map<string, string>()
  const ocrRetryRejectionCodes = ['SAND_AI_VALIDATION_ERROR', 'SAND_AI_IDEMPOTENCY_KEY_REQUIRED']
  const newCallsBlocked = computed(() => ocrRetryFlight.value !== null || ocrRetryStorageBlocked.value || ocrRetryPending.value.length > 0 || mutationInFlight.value !== null || materialTaskActionInFlight() || materialStorageUnconfirmed.value || pendingMaterialTaskIds.value.length > 0
    || authorizationStorageBlocked.value || authorizationIntents.value.length > 0 || meetingCorrectionStorageBlocked.value || meetingCorrectionIntents.value.length > 0)

  function ocrRetryStorageKey(): string { return `sand-ai-task-ocr-before-dispatch:v1:${userStore.info.id}` }
  function positiveOcrId(value: unknown): value is number { return typeof value === 'number' && Number.isSafeInteger(value) && value > 0 }
  function ocrRetryIntent(value: unknown): value is OcrRetryIntent {
    if (typeof value !== 'object' || value === null || Array.isArray(value)) return false
    const item = value as Record<string, unknown>
    return !Object.keys(item).some(field => !['version', 'admin_id', 'task_id', 'file_id', 'parse_id', 'expected_attempt_count', 'retry_attempt_limit', 'key', 'phase', 'rejection_code'].includes(field))
      && (item.phase === undefined || item.phase === 'prepared' || item.phase === 'sent' || item.phase === 'rejected')
      && (item.phase === 'rejected' ? typeof item.rejection_code === 'string' && ocrRetryRejectionCodes.includes(item.rejection_code) : item.rejection_code === undefined || item.rejection_code === null)
      && item.version === 1 && item.admin_id === userStore.info.id && positiveOcrId(item.admin_id)
      && positiveOcrId(item.task_id) && positiveOcrId(item.file_id) && positiveOcrId(item.parse_id)
      && typeof item.expected_attempt_count === 'number' && Number.isSafeInteger(item.expected_attempt_count) && item.expected_attempt_count >= 0
      && typeof item.retry_attempt_limit === 'number' && Number.isSafeInteger(item.retry_attempt_limit)
      && item.retry_attempt_limit === item.expected_attempt_count + 1 && item.retry_attempt_limit <= 2147483647
      && typeof item.key === 'string' && /^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i.test(item.key)
  }
  function restoreOcrRetryIntents(): void {
    unsentOcrRetryKeys.clear(); rejectedOcrRetryKeys.clear()
    ocrRetryPending.value = []; ocrRetryStorageBlocked.value = false; ocrRetryNotice.value = ''
    try {
      if (!positiveOcrId(userStore.info.id)) throw new Error('Current administrator unavailable')
      const raw = sessionStorage.getItem(ocrRetryStorageKey())
      if (raw === null) return
      const value: unknown = JSON.parse(raw)
      if (!Array.isArray(value) || !value.every(ocrRetryIntent) || new Set(value.map(item => item.task_id)).size !== value.length) throw new Error('Original OCR recovery metadata differs')
      ocrRetryPending.value = value
    } catch { ocrRetryStorageBlocked.value = true }
  }
  function persistOcrRetryIntents(next: OcrRetryIntent[]): void {
    const previous = JSON.stringify(ocrRetryPending.value)
    const raw = sessionStorage.getItem(ocrRetryStorageKey())
    if (!(raw === previous || (raw === null && ocrRetryPending.value.length === 0))) throw new Error('Original OCR recovery metadata changed')
    const body = JSON.stringify(next)
    sessionStorage.setItem(ocrRetryStorageKey(), body)
    if (sessionStorage.getItem(ocrRetryStorageKey()) !== body) throw new Error('Original OCR recovery metadata not retained')
    ocrRetryPending.value = next; ocrRetryStorageBlocked.value = false
  }
  function sameOcrRetryIntent(a: OcrRetryIntent, b: OcrRetryIntent): boolean {
    return a.version === b.version && a.admin_id === b.admin_id && a.task_id === b.task_id && a.file_id === b.file_id
      && a.parse_id === b.parse_id && a.expected_attempt_count === b.expected_attempt_count && a.retry_attempt_limit === b.retry_attempt_limit && a.key === b.key
  }
  function canReleaseOcrRetry(intent: OcrRetryIntent): boolean {
    return intent.admin_id === userStore.info.id && (intent.phase === 'prepared' || intent.phase === 'rejected'
      || unsentOcrRetryKeys.has(intent.key) || rejectedOcrRetryKeys.has(intent.key))
  }
  function releaseOcrRetryIntent(original: OcrRetryIntent): void {
    if (busy.value || ocrRetryFlight.value || !canReleaseOcrRetry(original)) return
    try {
      const remembered = ocrRetryPending.value.find(item => item.key === original.key)
      if (!remembered || !sameOcrRetryIntent(remembered, original)) throw new Error('Original OCR intent changed')
      const raw = sessionStorage.getItem(ocrRetryStorageKey())
      const value: unknown = raw === null ? [] : JSON.parse(raw)
      if (!Array.isArray(value) || !value.every(ocrRetryIntent) || new Set(value.map(item => item.task_id)).size !== value.length) throw new Error('Original OCR metadata unconfirmed')
      const stored = value.find(item => item.task_id === original.task_id)
      if (stored && (!sameOcrRetryIntent(stored, original)
        || (!(stored.phase === 'prepared' || stored.phase === 'rejected') && !unsentOcrRetryKeys.has(original.key) && !rejectedOcrRetryKeys.has(original.key)))) throw new Error('Original OCR intent differs')
      if (!stored && !unsentOcrRetryKeys.has(original.key)) throw new Error('Original OCR intent missing')
      const next = value.filter(item => item.task_id !== original.task_id)
      const body = JSON.stringify(next)
      if (sessionStorage.getItem(ocrRetryStorageKey()) !== raw) throw new Error('Original OCR intent changed')
      sessionStorage.setItem(ocrRetryStorageKey(), body)
      if (sessionStorage.getItem(ocrRetryStorageKey()) !== body) throw new Error('Original OCR intent not released')
      ocrRetryPending.value = next; ocrRetryStorageBlocked.value = false
      unsentOcrRetryKeys.delete(original.key); rejectedOcrRetryKeys.delete(original.key)
      ocrRetryNotice.value = '明确未发送或未受理的本地意图已解除。请先只读原任务，再明确决定是否恢复；不会自动提交。'
    } catch { ocrRetryStorageBlocked.value = true; ocrRetryNotice.value = '原意图或存储未能精确确认，暂不解除；请保留原任务和请求号。' }
  }
  function definiteOcrRetryRejection(error: unknown): error is SandAiRuntimeError {
    return error instanceof SandAiRuntimeError && error.httpStatus === 400 && error.businessCode === 400
      && error.errorCode !== null && ocrRetryRejectionCodes.includes(error.errorCode)
  }
  function retainOcrRetryRejection(original: OcrRetryIntent, error: SandAiRuntimeError): void {
    if (error.errorCode === null) return
    rejectedOcrRetryKeys.set(original.key, error.errorCode)
    const rejected: OcrRetryIntent = { ...original, phase: 'rejected', rejection_code: error.errorCode }
    try { persistOcrRetryIntents(ocrRetryPending.value.map(item => item.key === original.key ? rejected : item)) }
    catch { ocrRetryStorageBlocked.value = true }
    ocrRetryNotice.value = '服务端已明确拒绝且未受理本次恢复。可解除此意图后只读原任务；不会自动重发。'
  }
  function canRetryOcrBeforeDispatch(task: SandAiTask): boolean {
    const recovery = task.ocr_before_dispatch_retry
    return task.task_type === 'file_parse' && task.resource_type === 'file' && task.state === 'failed'
      && positiveOcrId(task.id) && positiveOcrId(task.resource_id) && positiveOcrId(task.file_parse_id)
      && Number.isSafeInteger(task.attempt_count) && task.attempt_count >= 0 && task.attempt_count === task.max_attempts
      && recovery?.target === 'file_parse_before_dispatch' && recovery.available === true && recovery.consumed === false
      && recovery.previous_attempt_count === task.attempt_count && recovery.retry_attempt_limit === task.attempt_count + 1
      && recovery.retry_attempt_limit <= 2147483647
  }
  function matchesOcrRetryTarget(task: SandAiTask, intent: OcrRetryIntent): boolean {
    return task.id === intent.task_id && task.task_type === 'file_parse' && task.resource_type === 'file'
      && task.resource_id === intent.file_id && task.file_parse_id === intent.parse_id
  }
  function reconcileOcrRetry(task: SandAiTask): void {
    const original = ocrRetryPending.value.find(item => item.task_id === task.id)
    if (!original) return
    const recovery = task.ocr_before_dispatch_retry
    if (!matchesOcrRetryTarget(task, original) || recovery?.target !== 'file_parse_before_dispatch'
      || recovery.consumed !== true || recovery.available !== false || recovery.previous_attempt_count !== original.expected_attempt_count
      || recovery.retry_attempt_limit !== original.retry_attempt_limit || task.max_attempts !== original.retry_attempt_limit
      || !Number.isSafeInteger(task.attempt_count) || task.attempt_count < original.expected_attempt_count || task.attempt_count > original.retry_attempt_limit) {
      ocrRetryNotice.value = '原恢复结果仍未确认。请继续只读同一任务，不再次发送。'; return
    }
    try {
      persistOcrRetryIntents(ocrRetryPending.value.filter(item => item.task_id !== original.task_id))
      ocrRetryNotice.value = '服务端确认原任务的一次恢复机会已接受；当前任务状态以原任务读取为准。'
    } catch { ocrRetryStorageBlocked.value = true; ocrRetryNotice.value = '原恢复已被服务端接受，但本页记录未能安全解除；请保留原任务只读核对。' }
  }
  async function retryOcrBeforeDispatch(taskId: number): Promise<void> {
    if (busy.value || newCallsBlocked.value || !apiKey.value.trim()) return
    const source = activeTask.value
    if (!source || source.id !== taskId || !canRetryOcrBeforeDispatch(source) || !positiveOcrId(userStore.info.id)) return
    const owner = userStore.info.id, credential = apiKey.value, epoch = credentialEpoch, generation = viewGeneration
    const token = beginMaterialTaskActionFlight(); ocrRetryFlight.value = token
    const current = () => ocrRetryFlight.value === token && userStore.info.id === owner && credentialEpoch === epoch && apiKey.value === credential
    let submitted = false
    try {
      try { await ElMessageBox.confirm(`确认使用一次机会恢复原 OCR 任务 #${taskId}？仍处理同一文件与解析记录，之后真实调用可能产生服务费。`, '恢复原 OCR 任务', { type: 'warning', confirmButtonText: '确认恢复一次', cancelButtonText: '取消' }) } catch { return }
      if (!current() || generation !== viewGeneration || activeTask.value?.id !== taskId) return
      const fresh = await taskApi.read(credential, taskId)
      if (!current() || generation !== viewGeneration || activeTask.value?.id !== taskId) return
      if (fresh.id !== taskId || !canRetryOcrBeforeDispatch(fresh) || fresh.resource_id !== source.resource_id || fresh.file_parse_id !== source.file_parse_id || fresh.attempt_count !== source.attempt_count) {
        upsertTask(fresh); ocrRetryNotice.value = '原状态或代次已变化，本次未发送恢复；请按原任务读回结果继续。'; return
      }
      const projection = fresh.ocr_before_dispatch_retry
      if (!projection || projection.retry_attempt_limit === null || !positiveOcrId(fresh.file_parse_id)) return
      const original: OcrRetryIntent = { version: 1, admin_id: owner, task_id: taskId, file_id: fresh.resource_id, parse_id: fresh.file_parse_id,
        expected_attempt_count: fresh.attempt_count, retry_attempt_limit: projection.retry_attempt_limit, key: crypto.randomUUID(), phase: 'prepared', rejection_code: null }
      unsentOcrRetryKeys.add(original.key)
      try { persistOcrRetryIntents([...ocrRetryPending.value, original]) }
      catch { ocrRetryPending.value = [...ocrRetryPending.value.filter(item => item.task_id !== taskId), original]; ocrRetryStorageBlocked.value = true; ocrRetryNotice.value = '原请求号未能安全保存，本次未发送。恢复存储后可明确解除未发送意图，再只读原任务；不会自动提交。'; return }
      if (!current() || generation !== viewGeneration) return
      const sent: OcrRetryIntent = { ...original, phase: 'sent' }
      try { persistOcrRetryIntents(ocrRetryPending.value.map(item => item.key === original.key ? sent : item)) }
      catch { ocrRetryStorageBlocked.value = true; ocrRetryNotice.value = '发送标记未能确认，本次尚未调用恢复接口。仅本页的未发送证明允许解除；刷新后按持久记录保守找回。'; return }
      stopPolling()
      unsentOcrRetryKeys.delete(original.key)
      submitted = true
      try { await taskApi.retryFileParseBeforeDispatch(credential, taskId, original.expected_attempt_count, original.key) }
      catch (error) {
        if (current() && definiteOcrRetryRejection(error)) { retainOcrRetryRejection(sent, error); return }
        throw error
      }
      if (!current()) return
      const result = await taskApi.read(credential, taskId)
      if (!current()) return
      reconcileOcrRetry(result)
      if (generation === viewGeneration && activeTask.value?.id === taskId && matchesOcrRetryTarget(result, original)) upsertTask(result)
    } catch (error) {
      if (current()) { ocrRetryNotice.value = submitted ? '原恢复结果未确认，请只读原任务找回；不会重新发送或创建新解析。' : '本次恢复未发送，请先读取原任务确认身份、来源与恢复条件。'; if (!clearOnAccessDenied(error)) lastError.value = toUiError(error) }
    } finally {
      finishMaterialTaskActionFlight(token)
      if (ocrRetryFlight.value === token) ocrRetryFlight.value = null
    }
  }

  function isAuthorizationIntent(value: unknown): value is AuthorizationIntent {
    if (typeof value !== 'object' || value === null || Array.isArray(value)
      || !('taskId' in value) || !('taskType' in value) || !('resourceType' in value)
      || !('resourceId' in value) || !('key' in value)) return false
    return typeof value.taskId === 'number' && Number.isSafeInteger(value.taskId) && value.taskId > 0
      && typeof value.resourceId === 'number' && Number.isSafeInteger(value.resourceId) && value.resourceId > 0
      && typeof value.taskType === 'string' && ['ai_completion', 'audio_transcription', 'file_parse', 'structured_workflow'].includes(value.taskType)
      && typeof value.resourceType === 'string' && value.resourceType.length > 0
      && typeof value.key === 'string' && /^[A-Za-z0-9_.:-]{1,512}$/.test(value.key)
  }
  function restoreAuthorizationIntents(): void {
    if (authorizationIntentOwner !== userStore.info.id) authorizationIntents.value = []
    authorizationIntentOwner = userStore.info.id
    authorizationStorageBlocked.value = false
    try {
      if (typeof authorizationIntentOwner !== 'number' || !Number.isSafeInteger(authorizationIntentOwner) || authorizationIntentOwner < 1) throw new Error('原管理员身份尚未确认')
      const raw = sessionStorage.getItem(authorizationStorageKey())
      if (raw === null) {
        if (authorizationIntents.value.length) throw new Error('原续接记录尚未确认')
        return
      }
      const value: unknown = JSON.parse(raw)
      if (!Array.isArray(value) || !value.every(isAuthorizationIntent)
        || new Set(value.map(item => item.taskId)).size !== value.length) throw new Error('原续接记录无法确认')
      authorizationIntents.value = value
    } catch { authorizationStorageBlocked.value = true }
  }
  function saveAuthorizationIntents(intents: AuthorizationIntent[]): boolean {
    try {
      if (typeof userStore.info.id !== 'number' || !Number.isSafeInteger(userStore.info.id) || userStore.info.id < 1) throw new Error('原管理员身份尚未确认')
      const body = JSON.stringify(intents)
      const key = authorizationStorageKey()
      sessionStorage.setItem(key, body)
      if (sessionStorage.getItem(key) !== body) throw new Error('原续接记录未保存')
      authorizationIntents.value = intents
      authorizationIntentOwner = userStore.info.id
      authorizationStorageBlocked.value = false
      return true
    } catch {
      authorizationStorageBlocked.value = true
      ElMessage.warning('续接请求记录未能确认，暂不发送新的续接；请保留原任务和请求号')
      return false
    }
  }
  function matchesAuthorizationIntent(task: SandAiTask, intent: AuthorizationIntent): boolean {
    return task.id === intent.taskId && task.task_type === intent.taskType
      && task.resource_type === intent.resourceType && task.resource_id === intent.resourceId
  }
  function authorizationReason(task: SandAiTask): string {
    const reason = task.authorization_continuation?.reason
    if (reason === 'lease_active') return '原执行租约尚未结束，请只读刷新原任务；当前不能发起新的续接。'
    if (reason === 'source_unavailable') return '原来源或任务状态暂不支持续接，请核对原任务并保留已有成果与费用。'
    return '可继续仅表示任务结构允许此操作，不代表当前身份已获准；继续时仍需重新核验 SandIAM 授权，剩余调用可能产生费用。'
  }

  function refreshMaterialPending(): void {
    const ids = new Set(pendingMaterialTaskActionIds(userStore.info.id))
    let unconfirmed = typeof userStore.info.id !== 'number' || !Number.isSafeInteger(userStore.info.id) || userStore.info.id < 1
    try {
      const prefix = `sand-ai-task-material-action:${userStore.info.id}:`
      const legacyPrefix = `sand-ai-material-receipt-intent:${userStore.info.id}:`
      for (let index = 0; index < sessionStorage.length; index++) {
        const storageKey = sessionStorage.key(index)
        if (!storageKey) continue
        if (storageKey.startsWith(prefix)) {
          const raw = sessionStorage.getItem(storageKey)
          if (!raw) continue
          const value: unknown = JSON.parse(raw)
          if (typeof value === 'object' && value !== null && !Array.isArray(value)
            && (value as Record<string, unknown>).confirmed === true) continue
          const id = Number(storageKey.slice(prefix.length).split(':')[0])
          if (Number.isSafeInteger(id) && id > 0) ids.add(id)
          else unconfirmed = true
        } else if (storageKey.startsWith(legacyPrefix) && sessionStorage.getItem(storageKey)) {
          const parts = storageKey.split(':')
          const id = Number(parts[parts.length - 1])
          const resourceId = parts[parts.length - 2]
          const markerRaw = sessionStorage.getItem(`${prefix}${id}:${resourceId}`)
          const marker: unknown = markerRaw ? JSON.parse(markerRaw) : null
          if (typeof marker === 'object' && marker !== null && !Array.isArray(marker)
            && (marker as Record<string, unknown>).confirmed === true) continue
          if (Number.isSafeInteger(id) && id > 0) ids.add(id)
          else unconfirmed = true
        }
      }
    } catch { unconfirmed = true }
    storedMaterialTaskIds.value = [...ids]
    materialStorageUnconfirmed.value = unconfirmed
  }
  function beginMutation(taskId: number, kind: MutationFlight['kind']): MutationFlight {
    const token = kind === 'cancel' || kind === 'retry' || kind === 'review_save'
      ? beginMaterialTaskActionFlight() : Symbol(kind)
    const flight: MutationFlight = { token, taskId, kind, owner: userStore.info.id, credential: apiKey.value, epoch: credentialEpoch }
    mutationInFlight.value = flight
    return flight
  }
  function currentMutation(flight: MutationFlight): boolean {
    return mutationInFlight.value?.token === flight.token && credentialEpoch === flight.epoch
      && userStore.info.id === flight.owner && apiKey.value === flight.credential
  }
  function finishMutation(flight: MutationFlight): void {
    if (flight.kind === 'cancel' || flight.kind === 'retry' || flight.kind === 'review_save') finishMaterialTaskActionFlight(flight.token)
    if (currentMutation(flight)) { mutationInFlight.value = null; refreshMaterialPending() }
  }


  type MeetingCorrectionIntent = { version: 1; adminId: number; taskId: number; resourceType: string; resourceId: number; key: string; body: MeetingOutputCorrectionExpectation }
  const meetingCorrectionIntents = ref<MeetingCorrectionIntent[]>([])
  const meetingCorrectionStorageBlocked = ref(false)
  let meetingCorrectionOwner: number | undefined
  function meetingCorrectionStorageKey(): string { return `sand-ai-meeting-output-correction:v1:${userStore.info.id}` }
  function meetingCorrectionIntent(value: unknown): value is MeetingCorrectionIntent {
    if (typeof value !== 'object' || value === null || Array.isArray(value)) return false
    const item = value as Record<string, unknown>
    if (Object.keys(item).sort().join(',') !== 'adminId,body,key,resourceId,resourceType,taskId,version') return false
    if (typeof item.body !== 'object' || item.body === null || Array.isArray(item.body)) return false
    const body = item.body as Record<string, unknown>
    return item.version === 1 && item.adminId === userStore.info.id && positiveOcrId(item.adminId)
      && positiveOcrId(item.taskId) && positiveOcrId(item.resourceId) && typeof item.resourceType === 'string' && item.resourceType.length > 0
      && typeof item.key === 'string' && /^[A-Za-z0-9_.:-]{1,512}$/.test(item.key)
      && Object.keys(body).sort().join(',') === 'expected_attempt_count,expected_node_attempt,expected_state,mode,node_code'
      && body.mode === 'meeting_output_correction' && body.expected_state === 'failed'
      && typeof body.node_code === 'string' && /^(?:chunk_[0-9]{5}|merge_[0-9]{2}_[0-9]{5})$/.test(body.node_code)
      && positiveOcrId(body.expected_node_attempt) && positiveOcrId(body.expected_attempt_count)
  }
  function restoreMeetingCorrectionIntents(): void {
    if (meetingCorrectionOwner !== userStore.info.id) meetingCorrectionIntents.value = []
    meetingCorrectionOwner = userStore.info.id
    try {
      if (!positiveOcrId(meetingCorrectionOwner)) throw new Error('当前管理员未确认')
      const raw = sessionStorage.getItem(meetingCorrectionStorageKey())
      if (raw === null) {
        if (meetingCorrectionIntents.value.length) throw new Error('原纠正记录丢失')
      } else {
        const value: unknown = JSON.parse(raw)
        if (!Array.isArray(value) || !value.every(meetingCorrectionIntent) || new Set(value.map(item => item.taskId)).size !== value.length) throw new Error('原纠正记录不一致')
        meetingCorrectionIntents.value = value
      }
      meetingCorrectionStorageBlocked.value = false
    } catch { meetingCorrectionStorageBlocked.value = true }
  }
  function saveMeetingCorrectionIntents(next: MeetingCorrectionIntent[]): void {
    if (!next.every(meetingCorrectionIntent)) throw new Error('原纠正记录无法确认')
    const key = meetingCorrectionStorageKey()
    const raw = sessionStorage.getItem(key)
    if (!(raw === JSON.stringify(meetingCorrectionIntents.value) || (raw === null && meetingCorrectionIntents.value.length === 0))) throw new Error('原纠正记录已变化')
    const body = JSON.stringify(next)
    sessionStorage.setItem(key, body)
    if (sessionStorage.getItem(key) !== body) throw new Error('原请求号未保存')
    meetingCorrectionIntents.value = next
  }
  function meetingCorrectionLocation(path: string | undefined): string {
    const match = /^(summary|key_points|decisions|action_items|unresolved)\[(0|[1-9][0-9]{0,4})\]\.segment_ids$/.exec(path ?? '')
    if (!match) return '原节点来源引用'
    const labels: Record<string, string> = { summary: '摘要', key_points: '要点', decisions: '决定', action_items: '待办', unresolved: '待确认事项' }
    const label = labels[match[1] ?? '']
    const index = Number(match[2])
    if (!label || !Number.isSafeInteger(index) || index < 0 || index >= 20000) return '原节点来源引用'
    return `${label}第 ${index + 1} 项的来源引用`
  }
  function meetingCorrectionExpectation(task: TaskSummaryWithReceiptRecovery): MeetingOutputCorrectionExpectation | null {
    const correction = task.meeting_output_correction
    if (task.task_type !== 'ai_completion' || task.resource_type !== 'ai_task' || task.resource_id !== task.id
      || task.state !== 'failed' || task.authorization_continuation?.required || !positiveOcrId(task.id) || !positiveOcrId(task.resource_id)
      || correction?.available !== true || !['duplicate_sources', 'missing_sources'].includes(correction.reason)
      || correction.failure_code !== 'SAND_AI_AUDIO_SUMMARY_RESULT_INVALID'
      || !['chunk', 'merge'].includes(correction.node_kind ?? '') || typeof correction.node_code !== 'string'
      || !/^(?:chunk_[0-9]{5}|merge_[0-9]{2}_[0-9]{5})$/.test(correction.node_code) || !correction.node_code.startsWith(`${correction.node_kind}_`)
      || !positiveOcrId(correction.expected_node_attempt) || !positiveOcrId(correction.expected_attempt_count)
      || correction.expected_attempt_count !== task.attempt_count) return null
    return { mode: 'meeting_output_correction', node_code: correction.node_code, expected_node_attempt: correction.expected_node_attempt,
      expected_attempt_count: correction.expected_attempt_count, expected_state: 'failed' }
  }
  async function correctMeetingOutput(taskId: number, originalKey?: string): Promise<void> {
    restoreMeetingCorrectionIntents()
    if (busy.value || mutationInFlight.value || meetingCorrectionStorageBlocked.value || !apiKey.value.trim()
      || ocrRetryFlight.value !== null || ocrRetryStorageBlocked.value || ocrRetryPending.value.length > 0
      || materialTaskActionInFlight() || materialStorageUnconfirmed.value || pendingMaterialTaskIds.value.length > 0
      || authorizationStorageBlocked.value || authorizationIntents.value.length > 0) return
    let intent = meetingCorrectionIntents.value.find(item => item.taskId === taskId)
    if (originalKey ? !intent || intent.key !== originalKey : newCallsBlocked.value) return
    const flight = beginMutation(taskId, 'meeting_correction')
    const generation = ++viewGeneration
    busy.value = 'meeting-correction'; stopPolling(); lastError.value = null
    try {
      const source = await taskApi.read(flight.credential, taskId)
      if (!currentMutation(flight) || generation !== viewGeneration || source.id !== taskId) return
      if (source.task_type !== 'ai_completion' || source.resource_type !== 'ai_task' || source.resource_id !== taskId) throw new Error('原会议摘要任务未确认，保留原请求号')
      upsertTask(source)
      if (intent && (source.resource_type !== intent.resourceType || source.resource_id !== intent.resourceId)) throw new Error('原任务来源不一致，保留原请求号')
      const expectation = intent?.body ?? meetingCorrectionExpectation(source)
      if (!expectation) return
      try {
        await ElMessageBox.confirm(`继续原任务 #${taskId} 的节点 ${expectation.node_code}？只纠正已明确失败的节点，随后继续原摘要计划；成功节点、旧结果和已计量费用均保留。此操作可能再次调用模型并产生费用，不退款、不从头生成。${intent ? '使用原请求号；已受理时只找回原任务，不重复派发。' : ''}`,
          '纠正失败节点并继续', { type: 'warning', confirmButtonText: intent ? '按原号确认并继续' : '确认纠正并继续', cancelButtonText: '取消' })
      } catch { return }
      if (!currentMutation(flight) || generation !== viewGeneration || activeTask.value?.id !== taskId) return
      if (!intent) {
        if (!positiveOcrId(flight.owner)) return
        const fresh = await taskApi.read(flight.credential, taskId)
        if (!currentMutation(flight) || generation !== viewGeneration || activeTask.value?.id !== taskId) return
        if (fresh.id !== taskId || fresh.resource_type !== source.resource_type || fresh.resource_id !== source.resource_id
          || JSON.stringify(meetingCorrectionExpectation(fresh)) !== JSON.stringify(expectation)) throw new Error('失败节点或任务状态已变化，请只读原任务后重新决定')
        intent = { version: 1, adminId: flight.owner, taskId, resourceType: source.resource_type, resourceId: source.resource_id,
          key: newRuntimeRequestId('meeting-output-correction'), body: expectation }
        saveMeetingCorrectionIntents([...meetingCorrectionIntents.value, intent])
      }
      const continued = await taskApi.correctMeetingOutput(flight.credential, taskId, intent.body, intent.key)
      if (!currentMutation(flight) || generation !== viewGeneration) return
      if (continued.id !== taskId || continued.task_type !== 'ai_completion' || continued.resource_type !== intent.resourceType || continued.resource_id !== intent.resourceId) throw new Error('纠正响应与原任务不一致，保留原请求号')
      saveMeetingCorrectionIntents(meetingCorrectionIntents.value.filter(item => item.key !== intent?.key))
      upsertTask(continued)
      ElMessage.info('原节点纠正请求已确认；请读取同一任务查看后续成果、来源和费用。')
    } catch (error) {
      if (currentMutation(flight) && generation === viewGeneration && !clearOnAccessDenied(error)) lastError.value = toUiError(error)
      restoreMeetingCorrectionIntents()
    } finally {
      if (currentMutation(flight)) busy.value = ''
      finishMutation(flight)
    }
  }

  const aiForm = reactive({
    model: '',
    content: '',
    sourceBlockIdsText: ''
  })
  const summaryModel = ref('')
  const summaryModels = ref<RuntimeChatModel[]>([])
  const summaryModelsLoading = ref(false)
  const summaryModelsError = ref('')
  let summaryModelsEpoch = 0
  let summaryModelsBearer = ''
  const audioReview = ref<(AudioReviewSnapshot & { taskId: number }) | null>(null)
  const audioSource = ref<{ taskId: number; url: string } | null>(null)
  const audioPlayer = ref<HTMLAudioElement | null>(null)
  const reviewSegmentIndex = ref<number | null>(null)
  const reviewText = ref('')
  const reviewSpeaker = ref('')
  const REVIEW_PAGE_SIZE = 50
  const reviewSearch = ref('')
  const reviewOnlyUnresolved = ref(false)
  const reviewPage = ref(1)
  const reviewSegmentOptions = computed(() => (audioReview.value?.segments ?? []).map((segment) => ({
    value: segment.segment_index,
    label: `${audioReview.value && reviewDrafts.get(audioReview.value.taskId)?.has(segment.segment_index) ? '[未保存草稿] ' : ''}${audioRangeLabel(segment.start_ms, segment.end_ms,
      segment.source?.locator ?? activeTask.value?.result?.segments?.[segment.segment_index]?.source?.locator)} · ${segment.speaker || '说话人未知'} · ${segment.text || '未获得可用转写'}`
  })))
  const filteredReviewSegments = computed(() => {
    const query = reviewSearch.value.trim().toLocaleLowerCase()
    return (audioReview.value?.segments ?? []).filter((segment) =>
      (!reviewOnlyUnresolved.value || segment.text.trim() === '' || segment.speaker === null
        || segment.warnings.some((warning) => warning !== 'human_reviewed'))
      && (!query || segment.text.toLocaleLowerCase().includes(query)
        || (segment.speaker ?? '说话人未知').toLocaleLowerCase().includes(query)))
  })
  const visibleReviewSegments = computed(() => filteredReviewSegments.value.slice(
    (reviewPage.value - 1) * REVIEW_PAGE_SIZE, reviewPage.value * REVIEW_PAGE_SIZE
  ))
  watch([reviewSearch, reviewOnlyUnresolved, () => audioReview.value?.taskId], () => {
    reviewPage.value = 1
  })
  watch(() => filteredReviewSegments.value.length, (count) => {
    reviewPage.value = Math.min(reviewPage.value, Math.max(1, Math.ceil(count / REVIEW_PAGE_SIZE)))
  })
  const hasInputRangeTranscript = computed(() =>
    activeTask.value?.result?.segments?.some((segment) =>
      segment.source?.locator.granularity === 'input_range_only') === true
  )
  const hasInputRangeReview = computed(() =>
    audioReview.value?.segments.some((segment) =>
      reviewLocator(segment.segment_index)?.granularity === 'input_range_only') === true
  )
  const selectedReviewLocator = computed(() =>
    reviewSegmentIndex.value === null ? undefined : reviewLocator(reviewSegmentIndex.value)
  )
  type PendingMeetingSummary = { audioTaskId: number; key: string; summaryTaskId: number | null; reviewRevision: number | null }
  const pendingSummary = ref<PendingMeetingSummary | null>(null)
  const summaryStorageBlocked = ref(false)
  const summaryLookupAudioId = ref<number | null>(null)
  const summaryLookupKey = ref('')
  const SUMMARY_RECOVERY_STORAGE = 'sand-ai-meeting-summary-recovery'
  let legacySummaryRaw: string | null = null

  function recoveryOwner(): number {
    const owner = userStore.info.id
    if (typeof owner !== 'number' || !Number.isSafeInteger(owner) || owner < 1) throw new Error('当前用户身份尚未确认，未发送请求')
    return owner
  }
  function summaryStorageKey(): string { return `${SUMMARY_RECOVERY_STORAGE}:${recoveryOwner()}` }
  function showSummary(value: PendingMeetingSummary | null): void {
    pendingSummary.value = value
    summaryLookupAudioId.value = value?.audioTaskId ?? null
    summaryLookupKey.value = value?.key ?? ''
  }
  function currentPendingSummary(): PendingMeetingSummary | null {
    // Re-read after submission: retainSummary and recovery can change the original journal.
    return pendingSummary.value
  }
  function retainSummary(value: PendingMeetingSummary | null, beforeSend = false): boolean {
    try {
      // Scoped opaque metadata only; never persist a Bearer, transcript, speaker or model input.
      if (value) sessionStorage.setItem(summaryStorageKey(), JSON.stringify(value))
      else sessionStorage.removeItem(summaryStorageKey())
      showSummary(value)
      summaryStorageBlocked.value = false
      if (!value) restoreSummary()
      return true
    } catch {
      summaryStorageBlocked.value = true
      // Failed removal must keep the original lock; a post-send update keeps its memory key too.
      if (value && !beforeSend) showSummary(value)
      ElMessage.warning(beforeSend ? '原摘要请求号保存失败，本次未发送' : '摘要原号的会话记录未能更新，已保留原号锁，请勿重复提交')
      return false
    }
  }
  function restoreSummary(): void {
    showSummary(null)
    legacySummaryRaw = null
    try {
      // An old unscoped key proves no owner; retain it for authorized GET recovery only.
      const scopedRaw = sessionStorage.getItem(summaryStorageKey())
      if (!scopedRaw) legacySummaryRaw = sessionStorage.getItem(SUMMARY_RECOVERY_STORAGE)
      const raw = scopedRaw ?? legacySummaryRaw
      summaryStorageBlocked.value = false
      if (!raw) return
      const value: unknown = JSON.parse(raw)
      if (typeof value !== 'object' || value === null || Array.isArray(value)) throw new Error('摘要原号记录无效')
      const item = value as Record<string, unknown>
      if (typeof item.audioTaskId !== 'number' || !Number.isSafeInteger(item.audioTaskId) || item.audioTaskId < 1
        || typeof item.key !== 'string' || !/^[\x21-\x7e]{1,512}$/.test(item.key)
        || (item.summaryTaskId !== null && (typeof item.summaryTaskId !== 'number' || !Number.isSafeInteger(item.summaryTaskId) || item.summaryTaskId < 1))
        || (item.reviewRevision !== undefined && item.reviewRevision !== null && (typeof item.reviewRevision !== 'number' || !Number.isSafeInteger(item.reviewRevision) || item.reviewRevision < 0))) throw new Error('摘要原号记录无效')
      showSummary({ audioTaskId: item.audioTaskId, key: item.key, summaryTaskId: item.summaryTaskId,
        reviewRevision: !legacySummaryRaw && typeof item.reviewRevision === 'number' ? item.reviewRevision : null })
    } catch { summaryStorageBlocked.value = true }
  }

  async function recoverMeetingSummary(): Promise<void> {
    if (busy.value || !apiKey.value.trim()) return
    const audioTaskId = pendingSummary.value?.audioTaskId ?? summaryLookupAudioId.value
    const key = pendingSummary.value?.key ?? summaryLookupKey.value.trim()
    if (!audioTaskId || !Number.isSafeInteger(audioTaskId) || audioTaskId < 1 || !/^[\x21-\x7e]{1,512}$/.test(key)) {
      ElMessage.warning('请填写原音频任务号和最多 512 字节的可见 ASCII 请求键')
      return
    }
    // Read recovery may proceed, but unavailable storage must never overwrite an existing journal.
    if (!summaryStorageBlocked.value && !legacySummaryRaw) retainSummary({ audioTaskId, key, summaryTaskId: pendingSummary.value?.summaryTaskId ?? null, reviewRevision: pendingSummary.value?.reviewRevision ?? null })
    const generation = ++viewGeneration
    stopPolling()
    busy.value = 'summary-recovery'
    lastError.value = null
    try {
      const task = await taskApi.readMeetingSummaryByKey(apiKey.value, audioTaskId, key)
      if (generation !== viewGeneration) return
      if (task.task_type !== 'ai_completion' || !Number.isSafeInteger(task.id) || task.id < 1) {
        throw new Error('找回的会议摘要任务无效；请保留原请求键核对')
      }
      const knownId = pendingSummary.value?.summaryTaskId
      if (knownId && knownId !== task.id) throw new Error('找回的会议摘要任务号与原记录不一致')
      if (!summaryStorageBlocked.value) {
        const migrated = retainSummary({ audioTaskId, key, summaryTaskId: task.id, reviewRevision: pendingSummary.value?.reviewRevision ?? null })
        if (migrated && legacySummaryRaw) {
          // This GET has proved the current scope; preserve legacy until scoped persistence succeeds.
          try {
            if (sessionStorage.getItem(SUMMARY_RECOVERY_STORAGE) === legacySummaryRaw) sessionStorage.removeItem(SUMMARY_RECOVERY_STORAGE)
            legacySummaryRaw = null
          } catch {
            summaryStorageBlocked.value = true
            ElMessage.warning('原摘要已只读找到，但旧原号记录尚未清除；继续保留原号锁')
          }
        }
      }
      upsertTask(task)
      lookupId.value = task.id
      ElMessage.success(`已只读找回摘要任务 #${task.id}`)
      if (!TERMINAL.has(task.state)) startPolling(task.id)
    } catch (error) {
      // A 404 or denied/unknown response is not permission to create another POST or key.
      if (generation === viewGeneration && !clearOnAccessDenied(error)) lastError.value = toUiError(error)
    } finally { if (generation === viewGeneration) busy.value = '' }
  }
  type ReviewDraft = { revision: number; text: string; speaker: string }
  type PendingReview = { audioTaskId: number; revision: number; index: number; key: string }
  type PendingReviewBody = { text: string; speaker?: string | null }
  const reviewDrafts = reactive(new Map<number, Map<number, ReviewDraft>>())
  const pendingReviews = reactive(new Map<number, PendingReview>())
  const pendingReviewBodies = new Map<string, PendingReviewBody>()
  const reviewNeedsRefresh = reactive(new Set<number>())
  const reviewRecoveryBlocked = reactive(new Set<number>())
  const activePendingReview = computed(() => activeTask.value ? pendingReviews.get(activeTask.value.id) : undefined)
  const selectedReviewPending = computed(() => activePendingReview.value?.index === reviewSegmentIndex.value)
  const selectedReviewDraft = computed(() => audioReview.value && reviewSegmentIndex.value !== null
    ? reviewDrafts.get(audioReview.value.taskId)?.get(reviewSegmentIndex.value) : undefined)

  function reviewStorageKey(taskId: number): string { return `sand-ai-audio-review-recovery:${recoveryOwner()}:${taskId}` }
  function restoreReview(taskId: number): void {
    if (pendingReviews.has(taskId)) return
    try {
      const raw = sessionStorage.getItem(reviewStorageKey(taskId))
      reviewRecoveryBlocked.delete(taskId)
      if (!raw) return
      const value: unknown = JSON.parse(raw)
      if (typeof value !== 'object' || value === null || Array.isArray(value)) throw new Error('原复核记录无效')
      const item = value as Record<string, unknown>
      if (item.audioTaskId !== taskId || typeof item.revision !== 'number' || !Number.isSafeInteger(item.revision) || item.revision < 0
        || typeof item.index !== 'number' || !Number.isSafeInteger(item.index) || item.index < 0
        || typeof item.key !== 'string' || !/^[\x21-\x7e]{1,512}$/.test(item.key)) throw new Error('原复核记录无效')
      pendingReviews.set(taskId, { audioTaskId: taskId, revision: item.revision, index: item.index, key: item.key })
    } catch { reviewRecoveryBlocked.add(taskId) }
  }
  function persistReview(pending: PendingReview): boolean {
    try {
      sessionStorage.setItem(reviewStorageKey(pending.audioTaskId), JSON.stringify(pending))
      pendingReviews.set(pending.audioTaskId, pending)
      reviewRecoveryBlocked.delete(pending.audioTaskId)
      return true
    } catch {
      reviewRecoveryBlocked.add(pending.audioTaskId)
      ElMessage.warning('原复核请求号保存失败，本次未发送；请恢复会话存储后重新读取片段复核')
      return false
    }
  }
  function confirmReview(pending: PendingReview): boolean {
    reviewNeedsRefresh.add(pending.audioTaskId)
    try {
      sessionStorage.removeItem(reviewStorageKey(pending.audioTaskId))
      pendingReviews.delete(pending.audioTaskId)
      pendingReviewBodies.delete(pending.key)
      reviewDrafts.get(pending.audioTaskId)?.delete(pending.index)
      reviewRecoveryBlocked.delete(pending.audioTaskId)
      return true
    } catch {
      reviewRecoveryBlocked.add(pending.audioTaskId)
      ElMessage.warning('已收到复核回执，但原号记录未能清除；继续保留原号锁，请只读找回，勿新建请求')
      return false
    }
  }
  function rejectReview(pending: PendingReview): void {
    reviewNeedsRefresh.add(pending.audioTaskId)
    try {
      sessionStorage.removeItem(reviewStorageKey(pending.audioTaskId))
      pendingReviews.delete(pending.audioTaskId)
      pendingReviewBodies.delete(pending.key)
      reviewRecoveryBlocked.delete(pending.audioTaskId)
      ElMessage.warning('本次复核未保存，草稿已保留；请重新读取当前版本后确认并保存')
    } catch {
      reviewRecoveryBlocked.add(pending.audioTaskId)
      ElMessage.warning('本次复核未保存，但原号记录未能清除；继续保留原号锁，请勿新建请求')
    }
  }
  function summaryReviewBlock(taskId: number): string {
    if (pendingReviews.has(taskId) || reviewRecoveryBlocked.has(taskId)) return '本任务有尚未确认的复核，请先只读找回原复核结果。'
    if (reviewNeedsRefresh.has(taskId)) return '请先读取片段复核，取得当前复核版本后再生成摘要。'
    if (reviewDrafts.get(taskId)?.size) return '本任务有未保存的复核草稿，请先确认并保存后再生成摘要。'
    if (audioReview.value?.taskId !== taskId) return '生成摘要前，请先读取片段复核，确认当前版本。'
    return ''
  }

  function retainReviewDraft(): void {
    const snapshot = audioReview.value
    const index = reviewSegmentIndex.value
    if (!snapshot || snapshot.taskId !== activeTask.value?.id || index === null || selectedReviewPending.value) return
    const segment = snapshot.segments.find((item) => item.segment_index === index)
    if (!segment) return
    const drafts = reviewDrafts.get(snapshot.taskId) ?? new Map<number, ReviewDraft>()
    if (reviewText.value === segment.text && reviewSpeaker.value === (segment.speaker ?? '')) drafts.delete(index)
    else drafts.set(index, {
      revision: drafts.get(index)?.revision ?? snapshot.revision,
      text: reviewText.value, speaker: reviewSpeaker.value
    })
    reviewDrafts.set(snapshot.taskId, drafts)
  }

  const materialActions = computed(() => new Map(recentTasks.value.flatMap((task) => {
    if (task.task_type !== 'structured_workflow' || task.resource_type !== 'workflow_run') return []
    const action = readMaterialTaskAction(task, userStore.info.id)
    return action ? [[task.id, action] as const] : []
  })))

  function materialActionLabel(kind: MaterialTaskAction['kind'] | MutationFlight['kind'] | undefined): string {
    if (kind === 'authorization_continue') return '授权续接'
    if (kind === 'review_save') return '片段复核保存'
    if (kind === 'summary_submit') return '会议摘要提交'
    if (kind === 'meeting_correction') return '失败摘要节点纠正'
    return kind === 'cancel' ? '取消' : kind === 'retry' ? '定向重试' : kind === 'receipt' ? '原回执恢复' : '原操作'
  }

  async function recoverMaterialReceipt(taskId: number): Promise<void> {
    refreshMaterialPending()
    if (busy.value || newCallsBlocked.value || materialChunkUnconfirmed.value) return
    const source = activeTask.value
    if (!source || source.id !== taskId || source.task_type !== 'structured_workflow'
      || source.resource_type !== 'workflow_run' || source.state !== 'recovery_pending'
      || source.receipt_only_recovery?.target !== 'cross_file_review'
      || source.receipt_only_recovery.available !== true || !apiKey.value.trim()) return
    const beforeConfirm = viewGeneration
    const flight = beginMutation(taskId, 'receipt')
    try {
      try { await ElMessageBox.confirm('仅使用原回执恢复跨文件复核或汇总，不新增模型调用。服务端会重新核对当前身份、来源和回执；复核拒绝时仍保留已完成文件成果。',
        '恢复原材料结果', { type: 'warning', confirmButtonText: '确认仅恢复', cancelButtonText: '取消' }) } catch { return }
      if (!currentMutation(flight) || beforeConfirm !== viewGeneration || activeTask.value?.id !== taskId) return
      const generation = ++viewGeneration
      stopPolling()
      lastError.value = null
      const key = newRuntimeRequestId('material-receipt')
      retainMaterialTaskAction(source, flight.owner, { kind: 'receipt', attempt: source.attempt_count, key })
      refreshMaterialPending()
      try {
        const task = await taskApi.recoverReviewReceipt(flight.credential, taskId, key)
        if (!currentMutation(flight) || generation !== viewGeneration) return
        if (task.id !== taskId || task.task_type !== source.task_type || task.resource_type !== source.resource_type || task.resource_id !== source.resource_id) throw new Error('恢复响应与原材料任务不一致，请只读原任务确认')
        upsertTask(task)
        await loadWorkflow(task, generation)
        if (!currentMutation(flight) || generation !== viewGeneration) return
        ElMessage.info(task.state === 'succeeded' ? '原材料任务已恢复' : task.state === 'failed' ? '原汇总已失败，已完成文件成果保留' : '原恢复结果尚待确认，请读取原任务')
      } catch (error) {
        if (currentMutation(flight) && !clearOnAccessDenied(error) && generation === viewGeneration) lastError.value = toUiError(error)
      }
    } finally { finishMutation(flight) }
  }

  const columns: ColumnOption<SandAiTask>[] = [
    { prop: 'id', label: 'ID', width: 80, align: 'center' },
    { prop: 'task_type', label: '类型', minWidth: 120 },
    { prop: 'resource_type', label: '资源', width: 110 },
    { prop: 'resource_id', label: '资源 ID', width: 100 },
    { prop: 'state', label: '状态', width: 110 },
    {
      prop: 'attempt_count',
      label: '尝试',
      width: 100,
      formatter: (row) => `${row.attempt_count}/${row.max_attempts}`
    },
    { prop: 'error_code', label: 'error_code', minWidth: 140 },
    {
      prop: 'id',
      label: '操作',
      width: 260,
      fixed: 'right',
      formatter: (row) =>
        h(ElSpace, { wrap: true }, () => [
          h(
            ElButton,
            { size: 'small', loading: busy.value === 'read', onClick: () => void refreshOne(row.id) },
            () => '刷新'
          ),
          h(
            ElButton,
            {
              size: 'small',
              type: 'warning',
              disabled: Boolean(busy.value) || newCallsBlocked.value || row.state !== 'failed' || fileParseAttemptsExhausted(row) || row.authorization_continuation?.required === true || materialActions.value.has(row.id),
              title: fileParseAttemptsExhausted(row) ? '原任务尝试次数已耗尽，请查看任务中的恢复状态' : undefined,
              loading: mutationInFlight.value?.kind === 'retry' && mutationInFlight.value.taskId === row.id,
              onClick: () => void handleRetry(row.id)
            },
            () => '重试'
          ),
          h(
            ElButton,
            {
              size: 'small',
              type: 'danger',
              disabled: Boolean(busy.value) || newCallsBlocked.value || TERMINAL.has(row.state) || materialActions.value.has(row.id),
              loading: mutationInFlight.value?.kind === 'cancel' && mutationInFlight.value.taskId === row.id,
              onClick: () => void handleCancel(row.id)
            },
            () => '取消'
          )
        ])
    }
  ]

  const stepColumns: ColumnOption<SandAiTaskStep>[] = [
    { prop: 'code', label: '步骤', minWidth: 140 },
    { prop: 'state', label: '状态', width: 110 },
    { prop: 'attempt_count', label: '尝试', width: 90 },
    { prop: 'error_code', label: 'error_code', minWidth: 140 },
    { prop: 'error_summary', label: '摘要', minWidth: 180 },
    { prop: 'started_at', label: '开始', width: 170 },
    { prop: 'completed_at', label: '完成', width: 170 }
  ]

  const fileColumns: ColumnOption<SandAiWorkflowFileState>[] = [
    { prop: 'file_id', label: '文件 ID', width: 110 },
    { prop: 'analysis_state', label: '分析状态', width: 150 },
    {
      prop: 'chunk_progress',
      label: '分段整理与来源覆盖',
      minWidth: 340,
      formatter: (row) => {
        const progress = row.chunk_progress
        if (!progress) return '未记录分块覆盖；沿原文件成果与来源查看'
        return h('div', [
          h('p', materialChunkStage(progress)),
          h('p', `分段整理 ${progress.completed_leaf_count} / ${progress.leaf_count} · 归并 ${progress.completed_merge_count} / ${progress.merge_count}`),
          h('p', `来源 ${progress.source_block_count} 块 · ${progress.source_character_count} 字符 · ${materialCoverageLabel(progress.coverage_state)}`),
          ...(progress.failed_node_count > 0 ? [h('p', `确认失败 ${progress.failed_node_count} 处`)] : []),
          ...(progress.unknown_node_count > 0 ? [h('p', `结果待确认 ${progress.unknown_node_count} 处；只读刷新原任务找回`)] : [])
        ])
      }
    },
    { prop: 'attempt_count', label: '尝试', width: 90 },
    { prop: 'error_code', label: '错误码', minWidth: 190 }
  ]

  const refColumns: ColumnOption<SandAiSourceReference>[] = [
    { prop: 'source_block_id', label: 'block', width: 90 },
    { prop: 'file_id', label: 'file', width: 90 },
    { prop: 'locator_type', label: 'locator_type', width: 120 },
    {
      prop: 'locator',
      label: 'locator',
      minWidth: 180,
      formatter: (row) => JSON.stringify(row.locator)
    }
  ]

  const workflowCitationColumns: ColumnOption<SandAiSourceReference & { field_path: string }>[] = [
    { prop: 'field_path', label: '字段', minWidth: 170 },
    ...refColumns,
    {
      prop: 'source_block_id',
      label: '查看文字',
      width: 110,
      formatter: (row) => h(ElButton, {
        link: true,
        type: 'primary',
        onClick: () => openWorkflowSource(row.source_block_id)
      }, () => '查看')
    }
  ]

  const audioSegmentColumns: ColumnOption<SandAiAudioSegment>[] = [
    {
      prop: 'start_ms',
      label: '音频范围',
      minWidth: 230,
      formatter: (row) => audioRangeLabel(row.start_ms, row.end_ms, row.source?.locator)
    },
    {
      prop: 'speaker',
      label: '说话人',
      width: 145,
      formatter: (row) => row.speaker ?? '说话人未知'
    },
    {
      prop: 'text',
      label: '转写文本',
      minWidth: 280,
      formatter: (row) => row.warnings.includes('no_recognized_speech')
        ? '未获得可用转写，请按时间回听' : row.text
    },
    {
      prop: 'confidence',
      label: '置信度',
      width: 130,
      formatter: (row) => audioConfidenceLabel(row.confidence, row.warnings)
    },
    {
      prop: 'warnings',
      label: '提示',
      minWidth: 170,
      formatter: (row) => row.warnings.map(audioWarningLabel).join('、') || '—'
    }
  ]

  function audioTime(milliseconds: number): string {
    const minutes = Math.floor(milliseconds / 60000)
    const seconds = Math.floor((milliseconds % 60000) / 1000)
    const remainder = milliseconds % 1000
    return `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}.${String(remainder).padStart(3, '0')}`
  }

  function audioRangeLabel(startMs: number, endMs: number, locator?: SandAiAudioLocator): string {
    const range = `${audioTime(startMs)}–${audioTime(endMs)}`
    if (locator?.granularity === 'input_range_only') return `整段输入范围 ${range}`
    if (locator?.granularity === 'engine_segment') return `引擎时间片段 ${range}`
    return `音频范围 ${range}（定位粒度未标明）`
  }

  function audioConfidenceLabel(confidence: string | null, warnings: string[]): string {
    if (confidence === null || warnings.includes('confidence_unavailable')
      || warnings.includes('local_confidence_unavailable')) return '未提供'
    return confidence
  }

  function audioWarningLabel(warning: string): string {
    if (warning === 'human_reviewed') return '已保存应用复核；原识别置信度不代表修订文字质量'
    if (warning === 'local_timing_clipped_to_audio') return '识别尾部时间已按真实音频范围裁剪'
    if (warning === 'no_recognized_speech') return '未获得可用转写，请回听确认'
    if (warning === 'uncovered_audio_range') return '该时段无识别输出'
    if (warning === 'timing_unavailable') return '引擎未提供句子时间'
    if (warning === 'confidence_unavailable') return '引擎未提供置信度'
    if (warning === 'local_confidence_unavailable') return '本地引擎未提供置信度'
    return '存在其他转写提示，请结合原音频复核'
  }

  function reviewLocator(segmentIndex: number): SandAiAudioLocator | undefined {
    const snapshot = audioReview.value
    if (!snapshot || snapshot.taskId !== activeTask.value?.id) return undefined
    return snapshot.segments.find((segment) => segment.segment_index === segmentIndex)?.source?.locator
      ?? activeTask.value.result?.segments?.[segmentIndex]?.source?.locator
  }

  function canSummarize(task: SandAiTask): boolean {
    if (audioReview.value?.taskId === task.id) {
      return audioReview.value.segments.some((segment) => segment.text.trim() !== '')
    }
    return task.result?.segments?.some((segment) => segment.text.trim() !== '') === true
  }

  function workflowCitationKey(row: SandAiSourceReference & { field_path: string }): string {
    return `${row.field_path}:${row.source_block_id}:${row.file_id}`
  }

  function usageStateLabel(state: string): string {
    return ({
      measured: '已测得',
      unmeasured: '未测得',
      unavailable: '暂不可确认',
      not_applicable: '不适用'
    } as Record<string, string>)[state] ?? state
  }

  function costStateLabel(state: string): string {
    return ({
      priced: '已定价',
      unpriced: '未定价',
      mixed_currency: '多币种，无法合计',
      unavailable: '总额暂不可确认',
      not_applicable: '不适用'
    } as Record<string, string>)[state] ?? state
  }

  function upsertTask(task: TaskSummaryWithReceiptRecovery): void {
    if (retainedMeetingTask.value && task.id !== retainedMeetingTask.value.id
      && (task.task_type !== 'audio_transcription' || task.id !== retainedMeetingTask.value.result?.audio_task_id)) {
      retainedMeetingTask.value = null
    }
    reconcileMaterialTaskAction(task, userStore.info.id)
    refreshMaterialPending()
    // Failure/cancellation alone does not prove that an external outcome was received.
    const summary = pendingSummary.value
    if (summary?.summaryTaskId === task.id && task.state === 'succeeded') {
      const actualRevision = task.result?.audio_review_revision
      if (task.result?.audio_task_id !== summary.audioTaskId || typeof actualRevision !== 'number'
        || !Number.isSafeInteger(actualRevision) || actualRevision < 0
        || (summary.reviewRevision !== null && actualRevision !== summary.reviewRevision)) {
        throw new Error('原摘要成果的音频任务或复核版本尚未确认，已保留原请求号锁')
      }
      retainSummary(null)
    }
    const next = recentTasks.value.filter((item) => item.id !== task.id)
    next.unshift(task)
    recentTasks.value = next
    if (activeTask.value?.id !== task.id || task.task_type !== 'structured_workflow') {
      workflowRun.value = null
      workflowError.value = null
    }
    if (activeTask.value?.id !== task.id) {
      clearWorkflowSource()
      clearAudioSource()
      audioReview.value = null
      reviewSegmentIndex.value = null
      reviewText.value = ''
      reviewSpeaker.value = ''
      reviewSearch.value = ''
      reviewOnlyUnresolved.value = false
    }
    if (task.cleanup?.source_available === false || task.cleanup?.resources.results === 'purged') {
      retainedMeetingTask.value = null
      clearWorkflowSource()
      clearAudioSource()
      workflowRun.value = null
      audioReview.value = null
      reviewSegmentIndex.value = null
      reviewText.value = ''
      reviewSpeaker.value = ''
      reviewDrafts.delete(task.id)
      const pendingReview = pendingReviews.get(task.id)
      if (pendingReview) pendingReviewBodies.delete(pendingReview.key)
    }
    activeTask.value = task
    if (task.task_type === 'audio_transcription') restoreReview(task.id)
    if (task.task_type === 'audio_transcription' && task.state === 'succeeded'
      && apiKey.value.trim() && summaryModelsBearer !== apiKey.value.trim()) {
      void loadSummaryModels()
    }
  }

  async function loadSummaryModels(): Promise<void> {
    const credential = apiKey.value.trim()
    if (!credential || summaryModelsLoading.value) return
    const epoch = ++summaryModelsEpoch
    summaryModelsLoading.value = true
    summaryModelsError.value = ''
    try {
      const result = await taskApi.models(credential)
      if (epoch !== summaryModelsEpoch || apiKey.value.trim() !== credential) return
      if (!Array.isArray(result.data)) throw new Error('模型目录响应无效')
      summaryModels.value = result.data.filter((model) =>
        typeof model.code === 'string' && model.code !== ''
        && typeof model.name === 'string' && model.type === 'chat'
        && Array.isArray(model.capabilities) && model.capabilities.includes('chat'))
      summaryModelsBearer = credential
    } catch (error) {
      if (epoch === summaryModelsEpoch) {
        summaryModels.value = []
        summaryModelsError.value = error instanceof Error ? error.message : '无法读取模型目录'
      }
    } finally {
      if (epoch === summaryModelsEpoch) summaryModelsLoading.value = false
    }
  }

  function clearWorkflowSource(): void {
    sourceGeneration++
    sourceOpen.value = false
    sourceLoading.value = false
    sourcePreview.value = null
    sourceError.value = ''
  }

  function clearProtectedTaskDetails(): void {
    ++credentialEpoch
    mutationInFlight.value = null
    restoreOcrRetryIntents()
    restoreAuthorizationIntents()
    restoreMeetingCorrectionIntents()
    refreshMaterialPending()
    ++viewGeneration
    stopPolling()
    recentTasks.value = []
    activeTask.value = null
    retainedMeetingTask.value = null
    workflowRun.value = null
    workflowError.value = null
    audioReview.value = null
    reviewSegmentIndex.value = null
    reviewText.value = ''
    reviewSpeaker.value = ''
    reviewDrafts.clear()
    pendingReviews.clear()
    pendingReviewBodies.clear()
    reviewRecoveryBlocked.clear()
    reviewNeedsRefresh.clear()
    aiForm.content = ''
    aiForm.sourceBlockIdsText = ''
    reviewSearch.value = ''
    reviewOnlyUnresolved.value = false
    clearWorkflowSource()
    clearAudioSource()
    busy.value = ''
    lastError.value = null
  }

  function clearOnAccessDenied(error: unknown): boolean {
    if (!(error instanceof SandAiRuntimeError) || (![401, 403].includes(error.httpStatus) && error.errorCode !== 'SAND_AI_CONTENT_PURGED')) return false
    clearProtectedTaskDetails()
    lastError.value = toUiError(error)
    return true
  }

  function clearUnavailableAudioContent(error: unknown, audioTaskId: number): boolean {
    if (!(error instanceof SandAiRuntimeError) || error.errorCode !== 'SAND_AI_RESOURCE_NOT_FOUND') return false
    const matchesSource = (task: SandAiTask | null): boolean => Boolean(task
      && ((task.task_type === 'audio_transcription' && task.id === audioTaskId)
        || (task.result?.meeting_summary && task.result.audio_task_id === audioTaskId)))
    const currentSource = matchesSource(activeTask.value)
    if (!currentSource && !matchesSource(retainedMeetingTask.value)
      && audioReview.value?.taskId !== audioTaskId && audioSource.value?.taskId !== audioTaskId) return false
    ++viewGeneration
    stopPolling()
    if (audioSource.value?.taskId === audioTaskId) clearAudioSource()
    if (audioReview.value?.taskId === audioTaskId || currentSource) {
      audioReview.value = null
      reviewSegmentIndex.value = null
      reviewText.value = ''
      reviewSpeaker.value = ''
      reviewSearch.value = ''
      reviewOnlyUnresolved.value = false
    }
    reviewDrafts.delete(audioTaskId)
    const pending = pendingReviews.get(audioTaskId)
    if (pending) pendingReviewBodies.delete(pending.key)
    reviewNeedsRefresh.add(audioTaskId)
    if (matchesSource(retainedMeetingTask.value)) retainedMeetingTask.value = null
    recentTasks.value = recentTasks.value.filter((task) => !matchesSource(task))
    if (currentSource) activeTask.value = null
    // Keep the original request metadata and the physical POST flight for receipt recovery.
    busy.value = ''
    lastError.value = toUiError(error)
    return true
  }

  async function openWorkflowSource(sourceBlockId: number): Promise<void> {
    const task = activeTask.value
    if (task?.cleanup?.source_available === false || task?.task_type !== 'structured_workflow' || task.resource_type !== 'workflow_run'
      || workflowRun.value?.run_id !== task.resource_id) return
    const runId = task.resource_id
    const current = ++sourceGeneration
    sourceOpen.value = true
    sourceLoading.value = true
    sourcePreview.value = null
    sourceError.value = ''
    try {
      const source = await taskApi.readWorkflowSource(apiKey.value, runId, sourceBlockId)
      if (current === sourceGeneration && activeTask.value?.id === task.id) sourcePreview.value = source
    } catch (error) {
      if (current === sourceGeneration && !clearOnAccessDenied(error)) {
        sourceError.value = toUiError(error).description
      }
    } finally {
      if (current === sourceGeneration) sourceLoading.value = false
    }
  }

  function toUiError(error: unknown): RuntimeUiError {
    return toRuntimeUiError(error)
  }

  async function loadWorkflow(task: SandAiTask, generation: number): Promise<SandAiWorkflowRun | null> {
    if (task.task_type !== 'structured_workflow' || task.resource_type !== 'workflow_run') {
      if (generation === viewGeneration) {
        workflowRun.value = null
        workflowError.value = null
      }
      return null
    }
    try {
      const run = await taskApi.readWorkflow(apiKey.value, task.resource_id)
      if (generation === viewGeneration && activeTask.value?.id === task.id) {
        workflowRun.value = run
        workflowError.value = null
      }
      return run
    } catch (error) {
      if (generation === viewGeneration && activeTask.value?.id === task.id) {
        if (!clearOnAccessDenied(error)) {
          workflowRun.value = null
          workflowError.value = toUiError(error)
        }
      }
      return null
    }
  }

  function retryTarget(run: SandAiWorkflowRun): {
    target: 'failed_files' | 'cross_file_review'
    label: string
  } | null {
    if (!Array.isArray(run.file_results) || hasUnconfirmedMaterialChunks(run)) return null
    const failedFiles = run.file_states.filter((file) => file.analysis_state === 'failed')
    if (failedFiles.length > 0) {
      return { target: 'failed_files', label: `只重试 ${failedFiles.length} 个失败文件` }
    }
    if (
      run.file_states.length > 1 &&
      run.file_states.every((file) => file.analysis_state === 'succeeded') &&
      ['pending', 'failed'].includes(run.cross_file_review?.state ?? '')
    ) {
      return { target: 'cross_file_review', label: '只重试跨文件核对' }
    }
    return null
  }

  function taskActionSnapshot(task: SandAiTask): string {
    return JSON.stringify([
      task.id, task.task_type, task.resource_type, task.resource_id, task.file_parse_id,
      task.state, task.attempt_count, task.max_attempts, task.started_at, task.completed_at,
      task.error_code, task.authorization_continuation?.required ?? false,
      task.provider_outcome_state ?? null, task.cleanup?.source_available ?? null,
      task.cleanup?.resources.results ?? null
    ])
  }

  function taskActionExpectation(task: SandAiTask): TaskActionExpectation {
    if (!Number.isSafeInteger(task.attempt_count) || task.attempt_count < 0
      || !['authorization_pending', 'queued', 'running', 'retrying', 'recovery_pending',
        'succeeded', 'failed', 'canceled'].includes(task.state)) {
      throw new Error('原任务执行代次或状态尚未确认，本次操作未发送；请只读刷新原任务')
    }
    return { expected_attempt_count: task.attempt_count, expected_state: task.state }
  }

  function materialRetryExpectation(run: SandAiWorkflowRun, target: 'failed_files' | 'cross_file_review',
    taskExpectation: TaskActionExpectation): MaterialRetryExpectation {
    if (target === 'cross_file_review') {
      const attempt = run.cross_file_review?.attempt_count
      if (typeof attempt !== 'number' || !Number.isSafeInteger(attempt) || attempt < 0) {
        throw new Error('原跨文件复核代次尚未确认，本次重试未发送；请只读刷新原任务')
      }
      return { ...taskExpectation, expected_review_attempt_count: attempt }
    }
    const files = run.file_states.filter(file => file.analysis_state === 'failed')
      .map(file => ({ file_id: file.file_id, attempt_count: file.attempt_count }))
      .sort((left, right) => left.file_id - right.file_id)
    if (!files.length || new Set(files.map(file => file.file_id)).size !== files.length
      || files.some(file => !Number.isSafeInteger(file.file_id) || file.file_id < 1
        || !Number.isSafeInteger(file.attempt_count) || file.attempt_count < 0)) {
      throw new Error('原失败文件集合或执行代次尚未确认，本次重试未发送；请只读刷新原任务')
    }
    return { ...taskExpectation, expected_failed_files: files }
  }

  function materialRetrySnapshot(run: SandAiWorkflowRun): string {
    return JSON.stringify([
      run.run_id, run.state, run.workflow.code, run.workflow.version, run.analysis_profile,
      [...run.file_states].sort((left, right) => left.file_id - right.file_id).map(file => [
        file.file_id, file.analysis_state, file.attempt_count, file.error_code,
        file.chunk_progress?.version ?? null, file.chunk_progress?.source_block_count ?? null,
        file.chunk_progress?.source_character_count ?? null, file.chunk_progress?.leaf_count ?? null,
        file.chunk_progress?.merge_count ?? null,
        file.chunk_progress?.state ?? null, file.chunk_progress?.completed_leaf_count ?? null,
        file.chunk_progress?.completed_merge_count ?? null, file.chunk_progress?.failed_node_count ?? null,
        file.chunk_progress?.unknown_node_count ?? null, file.chunk_progress?.coverage_state ?? null
      ]),
      run.cross_file_review?.state ?? null, run.cross_file_review?.attempt_count ?? null,
      run.cross_file_review?.error_code ?? null
    ])
  }

  function assertTaskActionTarget(task: SandAiTask, source: SandAiTask): void {
    if (task.id !== source.id || task.task_type !== source.task_type
      || task.resource_type !== source.resource_type || task.resource_id !== source.resource_id) {
      throw new Error('原任务读取目标不一致，本次操作未发送；请保留原任务号只读确认')
    }
  }

  function workflowMatchesTask(run: SandAiWorkflowRun, task: SandAiTask, snapshot: string): boolean {
    if (run.run_id !== task.resource_id || run.task === null) {
      throw new Error('原材料运行与任务的关系尚未确认，本次重试未发送')
    }
    assertTaskActionTarget(run.task, task)
    return taskActionSnapshot(run.task) === snapshot
  }

  function hasUnconfirmedMaterialChunks(run: SandAiWorkflowRun): boolean {
    return run.file_states.some(file => file.chunk_progress
      && (file.chunk_progress.unknown_node_count > 0 || file.chunk_progress.state === 'recovery_pending'))
  }

  const materialChunkUnconfirmed = computed(() => workflowRun.value
    ? hasUnconfirmedMaterialChunks(workflowRun.value) : false)

  function materialChunkStage(progress: SandAiMaterialChunkProgress): string {
    if (progress.unknown_node_count > 0 || progress.state === 'recovery_pending') return '原分块调用结果待确认'
    if (progress.state === 'failed') return '分段整理或归并失败'
    if (progress.state === 'canceled') return '分段处理已取消'
    if (progress.state === 'succeeded') return '分块结果已封存'
    return progress.completed_leaf_count < progress.leaf_count ? '正在分段整理' : '正在归并分段结果'
  }

  function materialCoverageLabel(state: SandAiMaterialChunkProgress['coverage_state']): string {
    return state === 'complete' ? '完整来源已覆盖'
      : state === 'incomplete' ? '未覆盖完整来源，文件尚未完成' : '完整来源覆盖尚未确认'
  }

  function parseSourceBlockIds(text: string): number[] {
    if (!text.trim()) return []
    return text
      .split(',')
      .map((part) => part.trim())
      .filter((part) => /^\d+$/.test(part))
      .map((part) => Number(part))
  }

  function stopPolling(): void {
    polling.value = false
    pollCount = 0
    if (pollTimer !== null) {
      clearTimeout(pollTimer)
      pollTimer = null
    }
  }

  function schedulePoll(taskId: number): void {
    if (!polling.value) return
    if (pollCount >= MAX_POLLS) {
      stopPolling()
      ElMessage.warning(`已轮询 ${MAX_POLLS} 次仍未终态，已停止`)
      return
    }
    pollTimer = setTimeout(() => {
      void refreshOne(taskId, true)
    }, POLL_MS)
  }

  function startPolling(taskId: number): void {
    stopPolling()
    polling.value = true
    pollCount = 0
    schedulePoll(taskId)
  }

  async function handleLookup(): Promise<void> {
    if (!lookupId.value) {
      ElMessage.warning('请输入 task id')
      return
    }
    stopPolling()
    await refreshOne(lookupId.value)
  }

  async function refreshOne(taskId: number, fromPoll = false): Promise<void> {
    const resumePolling = !fromPoll && polling.value && activeTask.value?.id === taskId
    if (!fromPoll && polling.value) stopPolling()
    const generation = ++viewGeneration
    if (!fromPoll) {
      busy.value = 'read'
    }
    lastError.value = null
    try {
      const task = await taskApi.read(apiKey.value, taskId)
      if (generation !== viewGeneration) return
      reconcileOcrRetry(task)
      upsertTask(task)
      if (!fromPoll || task.task_type === 'structured_workflow' || TERMINAL.has(task.state)) {
        await loadWorkflow(task, generation)
      }
      if (generation !== viewGeneration) return
      if (TERMINAL.has(task.state)) {
        if (polling.value) {
          stopPolling()
          ElMessage.success(`任务 #${taskId} 已终态：${task.state}`)
        }
        return
      }
      if (resumePolling) {
        startPolling(taskId)
      } else if (polling.value) {
        pollCount += 1
        schedulePoll(taskId)
      }
    } catch (error) {
      if (generation === viewGeneration) {
        const current = activeTask.value
        const audioTaskId = current?.id === taskId
          ? (current.task_type === 'audio_transcription' ? current.id : current.result?.audio_task_id)
          : undefined
        if (!clearOnAccessDenied(error)
          && !(audioTaskId && clearUnavailableAudioContent(error, audioTaskId))) {
          lastError.value = toUiError(error)
        }
        if (fromPoll && generation === viewGeneration) {
          stopPolling()
        }
      }
    } finally {
      if (!fromPoll && generation === viewGeneration) {
        busy.value = ''
      }
    }
  }

  async function refreshRejectedTaskAction(source: SandAiTask, flight: MutationFlight, generation: number): Promise<void> {
    try {
      const current = await taskApi.read(flight.credential, source.id)
      if (!currentMutation(flight) || generation !== viewGeneration) return
      assertTaskActionTarget(current, source)
      upsertTask(current)
      await loadWorkflow(current, generation)
    } catch (error) {
      if (currentMutation(flight) && generation === viewGeneration) clearOnAccessDenied(error)
      // A failed read or a generic conflict is not proof that the original action was not applied.
    }
  }

  async function handleCancel(taskId: number): Promise<void> {
    refreshMaterialPending()
    if (busy.value || newCallsBlocked.value) return
    const source = recentTasks.value.find(task => task.id === taskId)
    if (!source || TERMINAL.has(source.state)) return
    const snapshot = taskActionSnapshot(source)
    const beforeConfirm = viewGeneration
    const flight = beginMutation(taskId, 'cancel')
    try {
      const expectation = taskActionExpectation(source)
      try { await ElMessageBox.confirm(`确认取消任务 #${taskId}？`, '取消确认', { type: 'warning' }) } catch { return }
      if (!currentMutation(flight) || beforeConfirm !== viewGeneration || !recentTasks.value.some(task => task.id === taskId)) return
      const generation = ++viewGeneration
      stopPolling()
      lastError.value = null
      try {
        const current = await taskApi.read(flight.credential, taskId)
        if (!currentMutation(flight) || generation !== viewGeneration) return
        assertTaskActionTarget(current, source)
        if (taskActionSnapshot(current) !== snapshot) {
          upsertTask(current)
          await loadWorkflow(current, generation)
          if (currentMutation(flight) && generation === viewGeneration) {
            ElMessage.info('原任务状态或执行代次已变化，本次取消未发送；请按当前状态重新确认')
          }
          return
        }
        if (source.task_type === 'structured_workflow' && source.resource_type === 'workflow_run') {
          retainMaterialTaskAction(source, flight.owner, { kind: 'cancel', attempt: source.attempt_count, key: null })
          refreshMaterialPending()
        }
        const task = await taskApi.cancel(flight.credential, taskId, expectation)
        if (!currentMutation(flight) || generation !== viewGeneration) return
        if (task.id !== taskId || task.task_type !== source.task_type || task.resource_type !== source.resource_type || task.resource_id !== source.resource_id) throw new Error('取消响应与原任务不一致，请只读原任务确认')
        upsertTask(task)
        await loadWorkflow(task, generation)
        if (!currentMutation(flight) || generation !== viewGeneration) return
        ElMessage.info(task.state === 'canceled' ? `任务 #${taskId} 已取消；已有成果和费用保留` : TERMINAL.has(task.state) ? `任务 #${taskId} 已结束，实际状态为 ${task.state}` : `任务 #${taskId} 取消结果尚待确认，请读取原任务`)
        if (!TERMINAL.has(task.state)) startPolling(taskId)
        else stopPolling()
      } catch (error) {
        if (currentMutation(flight) && generation === viewGeneration && error instanceof SandAiRuntimeError
          && error.errorCode === 'SAND_AI_RESOURCE_CONFLICT') await refreshRejectedTaskAction(source, flight, generation)
        if (currentMutation(flight) && !clearOnAccessDenied(error) && generation === viewGeneration) lastError.value = toUiError(error)
      }
    } catch (error) {
      if (currentMutation(flight) && !clearOnAccessDenied(error) && beforeConfirm === viewGeneration) lastError.value = toUiError(error)
    } finally { finishMutation(flight) }
  }

  function fileParseAttemptsExhausted(task: SandAiTask): boolean {
    return task.task_type === 'file_parse' && task.state === 'failed' && task.attempt_count >= task.max_attempts
  }

  async function handleRetry(taskId: number): Promise<void> {
    refreshMaterialPending()
    if (busy.value || newCallsBlocked.value) return
    const source = recentTasks.value.find(item => item.id === taskId)
    if (!source || source.state !== 'failed' || fileParseAttemptsExhausted(source) || source.authorization_continuation?.required === true) return
    const snapshot = taskActionSnapshot(source)
    const generation = ++viewGeneration
    const flight = beginMutation(taskId, 'retry')
    stopPolling()
    lastError.value = null
    try {
      let expectation: MaterialRetryExpectation = taskActionExpectation(source)
      let target: 'failed_files' | 'cross_file_review' | undefined
      let label = '重试失败任务'
      let materialSnapshot: string | null = null
      if (source.task_type === 'structured_workflow' && source.resource_type === 'workflow_run') {
        const run = await taskApi.readWorkflow(flight.credential, source.resource_id)
        if (!currentMutation(flight) || generation !== viewGeneration) return
        if (!workflowMatchesTask(run, source, snapshot)) {
          const current = await taskApi.read(flight.credential, taskId)
          if (!currentMutation(flight) || generation !== viewGeneration) return
          assertTaskActionTarget(current, source)
          upsertTask(current)
          await loadWorkflow(current, generation)
          if (currentMutation(flight) && generation === viewGeneration) {
            ElMessage.info('原材料任务状态或执行代次已变化，本次重试未发送；请按当前状态重新确认')
          }
          return
        }
        if (activeTask.value?.id === taskId) { workflowRun.value = run; workflowError.value = null }
        const selected = retryTarget(run)
        if (!selected) {
          ElMessage.info(hasUnconfirmedMaterialChunks(run)
            ? '原分块调用结果待确认，请只读刷新原任务，不要重新提交材料或更换请求号'
            : '没有已确认可重试的失败文件或跨文件核对，请只读刷新原任务')
          return
        }
        target = selected.target
        label = selected.label
        expectation = materialRetryExpectation(run, target, expectation)
        materialSnapshot = materialRetrySnapshot(run)
      }
      try { await ElMessageBox.confirm(`确认${label} #${taskId}？`, '重试确认', { type: 'warning' }) } catch { return }
      if (!currentMutation(flight) || generation !== viewGeneration) return
      const current = await taskApi.read(flight.credential, taskId)
      if (!currentMutation(flight) || generation !== viewGeneration) return
      assertTaskActionTarget(current, source)
      if (taskActionSnapshot(current) !== snapshot) {
        upsertTask(current)
        await loadWorkflow(current, generation)
        if (currentMutation(flight) && generation === viewGeneration) {
          ElMessage.info('原任务状态或执行代次已变化，本次重试未发送；请按当前状态重新确认')
        }
        return
      }
      if (materialSnapshot !== null) {
        const run = await taskApi.readWorkflow(flight.credential, source.resource_id)
        if (!currentMutation(flight) || generation !== viewGeneration) return
        const selected = retryTarget(run)
        if (!workflowMatchesTask(run, source, snapshot) || materialRetrySnapshot(run) !== materialSnapshot
          || !selected || selected.target !== target) {
          upsertTask(run.task ?? current)
          workflowRun.value = run
          workflowError.value = null
          ElMessage.info('原失败文件、分块进度或跨文件核对代次已变化，本次重试未发送；请按当前结果重新确认')
          return
        }
      }
      if (source.task_type === 'structured_workflow' && source.resource_type === 'workflow_run') {
        retainMaterialTaskAction(source, flight.owner, { kind: 'retry', attempt: source.attempt_count, key: null })
        refreshMaterialPending()
      }
      const task = await taskApi.retry(flight.credential, taskId, target, expectation)
      if (!currentMutation(flight) || generation !== viewGeneration) return
      if (task.id !== taskId || task.task_type !== source.task_type || task.resource_type !== source.resource_type || task.resource_id !== source.resource_id) throw new Error('重试响应与原任务不一致，请只读原任务确认')
      upsertTask(task)
      await loadWorkflow(task, generation)
      if (!currentMutation(flight) || generation !== viewGeneration) return
      ElMessage.info(task.state === 'queued' || task.state === 'retrying' ? `任务 #${taskId} 已重新入队：${label}` : `任务 #${taskId} 实际状态为 ${task.state}，请读取原任务确认`)
      if (!TERMINAL.has(task.state)) startPolling(taskId)
    } catch (error) {
      if (currentMutation(flight) && generation === viewGeneration && error instanceof SandAiRuntimeError
        && error.errorCode === 'SAND_AI_RESOURCE_CONFLICT') await refreshRejectedTaskAction(source, flight, generation)
      if (currentMutation(flight) && !clearOnAccessDenied(error) && generation === viewGeneration) lastError.value = toUiError(error)
    } finally { finishMutation(flight) }
  }

  async function continueTaskAuthorization(taskId: number, recoverOriginal = false): Promise<void> {
    refreshMaterialPending()
    restoreAuthorizationIntents()
    restoreMeetingCorrectionIntents()
    if (busy.value || mutationInFlight.value || materialStorageUnconfirmed.value || pendingMaterialTaskIds.value.length
      || authorizationStorageBlocked.value || !apiKey.value.trim()) return
    let intent = authorizationIntents.value.find(item => item.taskId === taskId)
    if (recoverOriginal ? !intent : newCallsBlocked.value) return
    let source = recentTasks.value.find(item => item.id === taskId)
    if (!recoverOriginal && (!source || source.authorization_continuation?.mode !== 'authorization_continue'
      || !source.authorization_continuation.required || !source.authorization_continuation.available)) return
    const flight = beginMutation(taskId, 'authorization_continue')
    const generation = ++viewGeneration
    busy.value = 'authorization-continue'
    stopPolling()
    lastError.value = null
    try {
      if (intent) {
        source = await taskApi.read(flight.credential, taskId)
        if (!currentMutation(flight) || generation !== viewGeneration) return
        if (!matchesAuthorizationIntent(source, intent)) throw new Error('原任务与续接记录不一致，请保留原请求号')
        upsertTask(source)
      }
      if (!source || !['ai_completion', 'audio_transcription', 'file_parse', 'structured_workflow'].includes(source.task_type)) return
      try {
        await ElMessageBox.confirm(intent
          ? `按原请求号确认任务 #${taskId} 的授权续接？原意图已应用时仅返回原任务；未应用时重新核验当前应用授权后继续同一意图，不生成新请求号。`
          : `使用当前应用授权继续任务 #${taskId}？服务端重新核验原调用范围和来源；已完成节点及费用保留，剩余调用仍可能计费，结果未知的节点只找回原回执。`,
          '明确继续原任务', { type: 'warning', confirmButtonText: intent ? '按原号确认并继续' : '确认继续', cancelButtonText: '取消' })
      } catch { return }
      if (!currentMutation(flight) || generation !== viewGeneration) return
      if (!intent) {
        intent = { taskId: source.id, taskType: source.task_type, resourceType: source.resource_type,
          resourceId: source.resource_id, key: newRuntimeRequestId('task-authorization') }
        if (!isAuthorizationIntent(intent) || !saveAuthorizationIntents([...authorizationIntents.value, intent])) return
      }
      const continued = await taskApi.continueAuthorization(flight.credential, taskId, intent.key)
      if (!currentMutation(flight) || generation !== viewGeneration) return
      if (!matchesAuthorizationIntent(continued, intent)) throw new Error('续接响应与原任务不一致，已保留原请求号')
      // Only this POST response confirms the intent; ordinary task reads do not.
      const confirmed = saveAuthorizationIntents(authorizationIntents.value.filter(item => item.key !== intent?.key))
      upsertTask(continued)
      if (!confirmed) return
      const task = await taskApi.read(flight.credential, taskId)
      if (!currentMutation(flight) || generation !== viewGeneration) return
      if (!matchesAuthorizationIntent(task, intent)) throw new Error('原任务读取响应不一致，请只读原任务确认')
      upsertTask(task)
      await loadWorkflow(task, generation)
      if (!currentMutation(flight) || generation !== viewGeneration) return
      ElMessage.info(task.state === 'recovery_pending'
        ? '原授权续接已确认；调用结果仍待原回执找回，未重新派发未知节点'
        : '原授权续接已确认，后续状态、成果和费用以原任务记录为准')
    } catch (error) {
      if (currentMutation(flight) && generation === viewGeneration && !clearOnAccessDenied(error)) lastError.value = toUiError(error)
    } finally {
      if (currentMutation(flight)) busy.value = ''
      finishMutation(flight)
    }
  }

  async function handleAiSubmit(): Promise<void> {
    refreshMaterialPending()
    if (busy.value || newCallsBlocked.value) return
    if (!aiForm.model.trim() || !aiForm.content.trim()) {
      ElMessage.warning('请填写 model 与 user message')
      return
    }
    const generation = ++viewGeneration
    busy.value = 'submit'
    lastError.value = null
    stopPolling()
    try {
      const submitted = await aiTaskApi.submit(
        apiKey.value,
        {
          model: aiForm.model.trim(),
          messages: [{ role: 'user', content: aiForm.content.trim() }],
          source_block_ids: parseSourceBlockIds(aiForm.sourceBlockIdsText)
        },
        newRuntimeRequestId('ai')
      )
      if (generation !== viewGeneration) return
      upsertTask(submitted.task)
      lookupId.value = submitted.task.id
      ElMessage.success(
        submitted.idempotent_replay
          ? `幂等重放 task #${submitted.task.id}`
          : `已提交 task #${submitted.task.id}`
      )
      const refreshGeneration = viewGeneration + 1
      await refreshOne(submitted.task.id)
      if (viewGeneration === refreshGeneration
        && activeTask.value?.id === submitted.task.id
        && !TERMINAL.has(activeTask.value.state)) {
        startPolling(submitted.task.id)
      }
    } catch (error) {
      if (generation === viewGeneration) lastError.value = toUiError(error)
    } finally {
      if (generation === viewGeneration) busy.value = ''
    }
  }

  function editReviewSegment(index: number): void {
    reviewSegmentIndex.value = index
    selectReviewSegment(index)
  }

  function selectReviewSegment(index: number): void {
    const segment = audioReview.value?.segments.find((item) => item.segment_index === index)
    const draft = audioReview.value ? reviewDrafts.get(audioReview.value.taskId)?.get(index) : undefined
    const pending = audioReview.value ? pendingReviews.get(audioReview.value.taskId) : undefined
    const body = pending?.index === index ? pendingReviewBodies.get(pending.key) : undefined
    reviewText.value = draft?.text ?? body?.text ?? segment?.text ?? ''
    reviewSpeaker.value = draft?.speaker ?? (body?.speaker !== undefined ? body.speaker ?? '' : segment?.speaker ?? '')
    if (segment && audioPlayer.value) audioPlayer.value.currentTime = segment.start_ms / 1000
  }

  function clearAudioSource(): void {
    if (audioPlayer.value) audioPlayer.value.pause()
    if (audioSource.value) URL.revokeObjectURL(audioSource.value.url)
    audioSource.value = null
  }

  function canReadAudioSource(audioTaskId: number): boolean {
    const task = activeTask.value
    if (task?.cleanup?.source_available === false) return false
    return (task?.id === audioTaskId && task.task_type === 'audio_transcription')
      || (task?.result?.meeting_summary != null && task.result.audio_task_id === audioTaskId)
  }

  async function loadAudioSource(audioTaskId: number): Promise<boolean> {
    if (!canReadAudioSource(audioTaskId) || busy.value) return false
    const generation = viewGeneration
    const viewTaskId = activeTask.value?.id
    busy.value = 'audio-source'
    lastError.value = null
    try {
      const blob = await audioReviewApi.source(apiKey.value, audioTaskId)
      if (generation !== viewGeneration || activeTask.value?.id !== viewTaskId
        || !canReadAudioSource(audioTaskId)) return false
      clearAudioSource()
      audioSource.value = { taskId: audioTaskId, url: URL.createObjectURL(blob) }
      await nextTick()
      return true
    } catch (error) {
      if (generation === viewGeneration && !clearOnAccessDenied(error)
        && !clearUnavailableAudioContent(error, audioTaskId)) lastError.value = toUiError(error)
      return false
    } finally {
      if (generation === viewGeneration) busy.value = ''
    }
  }

  async function openMeetingAudioReview(): Promise<void> {
    const summary = displayedMeetingTask.value
    const audioTaskId = summary?.result?.audio_task_id
    const frozenRevision = summary?.result?.audio_review_revision
    if (!summary || !audioTaskId || !Number.isSafeInteger(audioTaskId) || audioTaskId < 1
      || busy.value || !apiKey.value.trim()) return
    const credential = apiKey.value
    const generation = ++viewGeneration
    stopPolling()
    busy.value = 'summary-audio-review'
    lastError.value = null
    try {
      // Re-read the old artifact under its own task authorization; its references
      // stay frozen even when the current review snapshot differs.
      const oldSummary = await taskApi.read(credential, summary.id)
      if (generation !== viewGeneration || apiKey.value !== credential) return
      if (oldSummary.id !== summary.id || !oldSummary.result?.meeting_summary
        || oldSummary.result.audio_task_id !== audioTaskId
        || oldSummary.result.audio_review_revision !== frozenRevision) {
        throw new Error('原摘要或冻结复核版本已不可确认，未打开其他音频任务')
      }
      const audioTask = await taskApi.read(credential, audioTaskId)
      if (generation !== viewGeneration || apiKey.value !== credential) return
      if (audioTask.id !== audioTaskId || audioTask.task_type !== 'audio_transcription'
        || audioTask.state !== 'succeeded') throw new Error('原音频任务尚不可复核')
      const snapshot = await audioReviewApi.read(credential, audioTaskId)
      if (generation !== viewGeneration || apiKey.value !== credential) return
      if (!Number.isSafeInteger(snapshot.revision) || snapshot.revision < 0) {
        throw new Error('当前复核版本尚未确认')
      }
      upsertTask(audioTask)
      retainedMeetingTask.value = oldSummary
      lookupId.value = audioTaskId
      audioReview.value = { ...snapshot, taskId: audioTaskId }
      reviewNeedsRefresh.delete(audioTaskId)
      reviewSegmentIndex.value = pendingReviews.get(audioTaskId)?.index
        ?? reviewDrafts.get(audioTaskId)?.keys().next().value ?? null
      if (reviewSegmentIndex.value !== null) selectReviewSegment(reviewSegmentIndex.value)
      ElMessage.success(`已读取原音频任务 #${audioTaskId} 的当前复核版本 ${snapshot.revision}；旧摘要来源保留在本页`)
    } catch (error) {
      if (generation === viewGeneration && !clearOnAccessDenied(error)
        && !clearUnavailableAudioContent(error, audioTaskId)) lastError.value = toUiError(error)
    } finally { if (generation === viewGeneration) busy.value = '' }
  }

  async function playMeetingSource(audioTaskId: number | undefined, startMs: number): Promise<void> {
    if (!audioTaskId || !canReadAudioSource(audioTaskId)) return
    if (audioSource.value?.taskId !== audioTaskId && !await loadAudioSource(audioTaskId)) return
    await nextTick()
    if (audioSource.value?.taskId === audioTaskId && canReadAudioSource(audioTaskId) && audioPlayer.value) {
      audioPlayer.value.currentTime = startMs / 1000
      await audioPlayer.value.play().catch(() => undefined)
    }
  }

  async function playReviewSegment(audioTaskId: number): Promise<void> {
    const segment = audioReview.value?.segments.find((item) => item.segment_index === reviewSegmentIndex.value)
    if (!segment) return
    if (audioSource.value?.taskId !== audioTaskId && !await loadAudioSource(audioTaskId)) return
    if (audioSource.value?.taskId === audioTaskId && canReadAudioSource(audioTaskId) && audioPlayer.value) {
      audioPlayer.value.currentTime = segment.start_ms / 1000
      await audioPlayer.value.play().catch(() => undefined)
    }
  }

  async function loadAudioReviews(audioTaskId: number): Promise<void> {
    if (activeTask.value?.id !== audioTaskId || busy.value) return
    restoreReview(audioTaskId)
    const generation = viewGeneration
    busy.value = 'review-read'
    lastError.value = null
    try {
      const snapshot = await audioReviewApi.read(apiKey.value, audioTaskId)
      if (generation !== viewGeneration || activeTask.value?.id !== audioTaskId) return
      audioReview.value = { ...snapshot, taskId: audioTaskId }
      reviewNeedsRefresh.delete(audioTaskId)
      const drafts = reviewDrafts.get(audioTaskId)
      const restoredIndex = reviewSegmentIndex.value ?? pendingReviews.get(audioTaskId)?.index
        ?? drafts?.keys().next().value ?? null
      reviewSegmentIndex.value = restoredIndex
      if (restoredIndex !== null) selectReviewSegment(restoredIndex)
    } catch (error) {
      if (generation === viewGeneration && !clearOnAccessDenied(error)
        && !clearUnavailableAudioContent(error, audioTaskId)) lastError.value = toUiError(error)
    } finally {
      if (generation === viewGeneration) busy.value = ''
    }
  }

  async function recoverAudioReview(audioTaskId: number): Promise<void> {
    if (busy.value || materialTaskActionInFlight() || activeTask.value?.id !== audioTaskId || !apiKey.value.trim()) return
    restoreReview(audioTaskId)
    const original = pendingReviews.get(audioTaskId)
    if (!original) return
    const generation = viewGeneration
    busy.value = 'review-recovery'
    lastError.value = null
    try {
      const receipt = await audioReviewApi.readByRequestKey(apiKey.value, audioTaskId, original.key)
      if (generation !== viewGeneration || activeTask.value?.id !== audioTaskId) return
      if (receipt.audio_task_id !== original.audioTaskId || receipt.request_key !== original.key
        || receipt.segment_index !== original.index || receipt.expected_revision !== original.revision
        || receipt.revision !== original.revision + 1) throw new Error('复核回执与原任务、请求号、片段或版本不一致，已保留原号锁')
      if (!confirmReview(original)) return
      ElMessage.success(`已只读找回本次复核，版本 ${receipt.revision}`)
      busy.value = ''
      await loadAudioReviews(audioTaskId)
    } catch (error) {
      // Denial, missing ledger and unknown failures never justify another POST or request key.
      if (generation === viewGeneration && !clearOnAccessDenied(error)) lastError.value = toUiError(error)
    } finally { if (generation === viewGeneration) busy.value = '' }
  }

  async function saveAudioReview(audioTaskId: number): Promise<void> {
    refreshMaterialPending()
    if (newCallsBlocked.value || busy.value || activeTask.value?.id !== audioTaskId || !apiKey.value.trim()) return
    restoreReview(audioTaskId)
    const snapshot = audioReview.value
    if (snapshot?.taskId !== audioTaskId) return
    if (pendingReviews.has(audioTaskId) || reviewRecoveryBlocked.has(audioTaskId)) {
      ElMessage.warning('请先只读找回本任务的原复核请求，确认后再保存新复核')
      return
    }
    if (reviewNeedsRefresh.has(audioTaskId)) return
    const index = reviewSegmentIndex.value
    if (index === null) return
    const segment = snapshot.segments.find(item => item.segment_index === index)
    if (!segment) return
    const text = reviewText.value.trim()
    const selectedSpeaker = reviewSpeaker.value.trim() || null
    if (selectedSpeaker !== segment.speaker && selectedSpeaker !== null && !/^speaker_[1-9]\d{0,3}$/.test(selectedSpeaker)) {
      ElMessage.warning('说话人编号须为 speaker_1 至 speaker_9999；留空表示未知')
      return
    }
    const body: PendingReviewBody = { text, speaker: selectedSpeaker === segment.speaker ? undefined : selectedSpeaker }
    const source = activeTask.value
    const expectedRevision = snapshot.revision
    const draft = reviewDrafts.get(audioTaskId)?.get(index)
    const draftRevision = draft?.revision ?? expectedRevision
    const draftText = reviewText.value
    const draftSpeaker = reviewSpeaker.value
    const accessToken = userStore.accessToken
    const generation = viewGeneration
    const flight = beginMutation(audioTaskId, 'review_save')
    let original: PendingReview | null = null
    lastError.value = null
    try {
      const versionNotice = draftRevision === expectedRevision
        ? `当前复核版本 ${expectedRevision}。`
        : `草稿来自复核版本 ${draftRevision}，当前复核版本为 ${expectedRevision}。确认后将把这份草稿保存到当前版本；取消会保留草稿，可重新读取片段复核再核对。`
      try {
        await ElMessageBox.confirm(`保存原音频任务 #${audioTaskId} 的片段 #${index}？${versionNotice}原音频时间和来源保持不变。`,
          '保存复核确认', { type: 'warning', confirmButtonText: '确认保存', cancelButtonText: '保留草稿' })
      } catch { return }
      if (!currentMutation(flight) || userStore.accessToken !== accessToken || generation !== viewGeneration
        || activeTask.value !== source || source?.id !== audioTaskId || source.task_type !== 'audio_transcription'
        || source.cleanup?.source_available === false || audioReview.value !== snapshot
        || snapshot.revision !== expectedRevision || audioReview.value.revision !== expectedRevision
        || reviewSegmentIndex.value !== index
        || snapshot.segments.find(item => item.segment_index === index) !== segment
        || reviewText.value !== draftText || reviewSpeaker.value !== draftSpeaker
        || reviewDrafts.get(audioTaskId)?.get(index) !== draft
        || pendingReviews.has(audioTaskId) || reviewRecoveryBlocked.has(audioTaskId) || reviewNeedsRefresh.has(audioTaskId)) {
        ElMessage.warning('复核目标、版本、草稿或身份已变化，本次未发送；请重新读取并核对当前片段后保存')
        return
      }
      const drafts = reviewDrafts.get(audioTaskId) ?? new Map<number, ReviewDraft>()
      drafts.set(index, { revision: draftRevision, text: draftText, speaker: draftSpeaker })
      reviewDrafts.set(audioTaskId, drafts)
      original = { audioTaskId, revision: expectedRevision, index, key: crypto.randomUUID() }
      if (!persistReview(original)) return
      pendingReviewBodies.set(original.key, body)
      const saved = await audioReviewApi.save(flight.credential, audioTaskId, original.revision, original.index, body.text, original.key, body.speaker)
      if (!currentMutation(flight)) return
      if (saved.segment_index !== original.index || saved.text !== body.text || saved.revision !== original.revision + 1) throw new Error('复核保存回执与原片段或版本不一致，请保留原号只读找回')
      const pending = pendingReviews.get(audioTaskId)
      if (pending && (pending.key !== original.key || pending.index !== original.index || pending.revision !== original.revision)) throw new Error('当前复核原号与此回执不一致，未改变当前记录')
      if (pending && !confirmReview(original)) return
      ElMessage.success(`原音频任务 #${audioTaskId} 的复核已保存为版本 ${saved.revision}`)
      // A late receipt confirms its original journal but cannot replace another viewed task.
      if (generation === viewGeneration && activeTask.value?.id === audioTaskId) await loadAudioReviews(audioTaskId)
    } catch (error) {
      if (!currentMutation(flight) || clearOnAccessDenied(error) || !original) return
      const pending = pendingReviews.get(audioTaskId)
      if (pending?.key === original.key && error instanceof SandAiRuntimeError
        && ['SAND_AI_RESOURCE_CONFLICT', 'SAND_AI_VALIDATION_ERROR'].includes(error.errorCode ?? '')) rejectReview(original)
      if (generation === viewGeneration && activeTask.value?.id === audioTaskId) lastError.value = toUiError(error)
      else ElMessage.warning(`原音频任务 #${audioTaskId} 的复核结果需按原号核对`)
    } finally { finishMutation(flight) }
  }

  async function handleMeetingSummary(audioTaskId: number): Promise<void> {
    refreshMaterialPending()
    if (newCallsBlocked.value || busy.value) return
    if (pendingSummary.value || summaryLookupKey.value.trim()) {
      ElMessage.warning('请先只读找回原摘要请求；未确认前不能生成新摘要')
      return
    }
    const reviewBlock = summaryReviewBlock(audioTaskId)
    if (summaryStorageBlocked.value || reviewBlock) { ElMessage.warning(reviewBlock || '摘要原号会话存储尚未确认，本次未发送'); return }
    const model = summaryModel.value.trim()
    if (!model) {
      ElMessage.warning('请填写已发布的聊天模型名')
      return
    }
    const source = activeTask.value
    if (source?.id !== audioTaskId || source.task_type !== 'audio_transcription'
      || source.state !== 'succeeded') return
    const reviewRevision = audioReview.value?.taskId === audioTaskId ? audioReview.value.revision : 0
    const generation = ++viewGeneration
    stopPolling()
    lastError.value = null
    const key = crypto.randomUUID()
    if (!retainSummary({ audioTaskId, key, summaryTaskId: null, reviewRevision }, true)) return
    const flight = beginMutation(audioTaskId, 'summary_submit')
    try {
      const submitted = await taskApi.submitMeetingSummary(flight.credential, audioTaskId, model, reviewRevision, key)
      if (!currentMutation(flight)) return
      const task = submitted.task
      if (task.task_type !== 'ai_completion' || !Number.isSafeInteger(task.id) || task.id < 1) throw new Error('摘要提交未返回有效任务号；请保留原请求键只读找回')
      const actualAudio = task.result?.audio_task_id
      const actualRevision = task.result?.audio_review_revision
      if ((actualAudio !== undefined && actualAudio !== audioTaskId) || (actualRevision !== undefined && actualRevision !== reviewRevision)) throw new Error('摘要回执的音频任务或复核版本与原请求不一致，已保留原号')
      const pending = currentPendingSummary()
      if (pending && (pending.audioTaskId !== audioTaskId || pending.key !== key || pending.reviewRevision !== reviewRevision
        || (pending.summaryTaskId !== null && pending.summaryTaskId !== task.id))) throw new Error('摘要回执与当前原号记录不一致，未改变当前记录')
      if (pending) retainSummary({ audioTaskId, key, summaryTaskId: task.id, reviewRevision })
      if (task.state === 'succeeded' && pending) {
        if (actualAudio !== audioTaskId || actualRevision !== reviewRevision) throw new Error('原摘要成果的音频任务或复核版本尚未确认，已保留原请求号锁')
        retainSummary(null)
      }
      ElMessage.success(submitted.idempotent_replay ? `已找到原摘要任务 #${task.id}` : `原音频任务 #${audioTaskId} 已提交摘要任务 #${task.id}`)
      // Persist/reconcile the original receipt even after another history GET changed the view.
      if (generation !== viewGeneration || activeTask.value?.id !== audioTaskId) return
      upsertTask(task)
      lookupId.value = task.id
      const refreshGeneration = viewGeneration + 1
      await refreshOne(task.id)
      if (currentMutation(flight) && viewGeneration === refreshGeneration && activeTask.value?.id === task.id && !TERMINAL.has(activeTask.value.state)) startPolling(task.id)
    } catch (error) {
      if (!currentMutation(flight) || clearOnAccessDenied(error)) return
      if (generation === viewGeneration) lastError.value = toUiError(error)
      else ElMessage.warning(`原音频任务 #${audioTaskId} 的摘要结果需按原请求号核对`)
    } finally { finishMutation(flight) }
  }

  const route = useRoute()
  watch(() => route.query.id, (raw) => {
    ++viewGeneration
    stopPolling()
    clearWorkflowSource()
    clearAudioSource()
    activeTask.value = null
    retainedMeetingTask.value = null
    workflowRun.value = null
    workflowError.value = null
    audioReview.value = null
    reviewSegmentIndex.value = null
    reviewText.value = ''
    reviewSpeaker.value = ''
    reviewSearch.value = ''
    reviewOnlyUnresolved.value = false
    busy.value = ''
    lastError.value = null
    const value = typeof raw === 'string' ? raw : Array.isArray(raw) ? raw[0] : undefined
    const id = typeof value === 'string' && /^[1-9][0-9]*$/.test(value) ? Number(value) : undefined
    lookupId.value = id !== undefined && Number.isSafeInteger(id) ? id : undefined
    if (lookupId.value && apiKey.value.trim()) void refreshOne(lookupId.value)
  }, { flush: 'sync' })

  onMounted(() => {
    restoreOcrRetryIntents()
    refreshMaterialPending()
    restoreAuthorizationIntents()
    restoreMeetingCorrectionIntents()
    restoreSummary()
    apiKey.value = readRuntimeBearer()
    const raw = route.query.id
    const fromQuery = typeof raw === 'string' ? raw : Array.isArray(raw) ? raw[0] : undefined
    if (typeof fromQuery === 'string' && /^\d+$/.test(fromQuery)) {
      lookupId.value = Number(fromQuery)
      void refreshOne(lookupId.value)
    }

    const blocksRaw = route.query.source_block_ids
    const blocks =
      typeof blocksRaw === 'string' ? blocksRaw : Array.isArray(blocksRaw) ? blocksRaw[0] : undefined
    if (typeof blocks === 'string' && blocks.trim()) {
      aiForm.sourceBlockIdsText = blocks.trim()
    }
    const qRaw = route.query.q
    const q = typeof qRaw === 'string' ? qRaw : Array.isArray(qRaw) ? qRaw[0] : undefined
    if (typeof q === 'string' && q.trim() && !aiForm.content.trim()) {
      aiForm.content = `请结合给定来源回答：${q.trim()}`
    }
  })

  onUnmounted(() => {
    unsentOcrRetryKeys.clear(); rejectedOcrRetryKeys.clear()
    retainedMeetingTask.value = null
    ++credentialEpoch
    mutationInFlight.value = null
    ++viewGeneration
    reviewDrafts.clear()
    pendingReviews.clear()
    pendingReviewBodies.clear()
    reviewRecoveryBlocked.clear()
    reviewNeedsRefresh.clear()
    ++summaryModelsEpoch
    stopPolling()
    clearWorkflowSource()
    clearAudioSource()
  })
  watch([() => userStore.info.id, () => userStore.accessToken], () => {
    if (apiKey.value) apiKey.value = ''
    else { clearProtectedTaskDetails(); restoreSummary() }
  }, { flush: 'sync' })
  watch(apiKey, () => {
    clearProtectedTaskDetails()
    restoreSummary()
    ++summaryModelsEpoch
    summaryModelsLoading.value = false
    summaryModels.value = []
    summaryModelsError.value = ''
    summaryModelsBearer = ''
    if (apiKey.value.trim()) void loadSummaryModels()
  }, { flush: 'sync' })
</script>

<style scoped lang="scss">
  .task-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 16px;
  }

  .task-title {
    margin: 0;
    font-size: 18px;
    font-weight: 600;
  }

  .task-desc {
    margin: 4px 0 0;
    font-size: 13px;
    color: var(--el-text-color-secondary);
    max-width: 720px;
  }

  .task-cred,
  .task-ai {
    margin-bottom: 8px;
  }

  .task-alert {
    margin-bottom: 16px;
  }

  .task-detail {
    margin-top: 20px;
  }

  .task-detail__title {
    margin: 16px 0 12px;
    font-size: 15px;
    font-weight: 600;
  }

  .task-footnote {
    margin: 8px 0 0;
    font-size: 12px;
    color: var(--el-text-color-secondary);
  }

  .task-review__player {
    display: block;
    width: min(100%, 640px);
    margin: 12px 0;
  }

  .task-file-result {
    max-height: 420px;
    overflow: auto;
    padding: 12px;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    background: var(--el-fill-color-light);
    border-radius: var(--el-border-radius-base);
  }
</style>
