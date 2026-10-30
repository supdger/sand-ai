<template>
  <ElCard shadow="never">
    <template #header>
      <div class="panel-head">
        <div><h3>渠道绑定</h3><p>将机器人渠道连接到已发布智能体，消息由指定应用身份调用。每条新消息使用并保留当时的已发布版本。</p></div>
        <ElButton :disabled="!environment" :loading="loading" @click="refreshBindingPanel">刷新</ElButton>
      </div>
    </template>
    <ElEmpty v-if="!environment" description="先选择应用环境" />
    <template v-else>
      <ElAlert v-if="error" type="error" :title="error" show-icon class="panel-alert" @close="error = ''" />
      <ElAlert v-if="!deliveryRetryLockAvailable" type="warning" :closable="false" class="panel-alert"
        title="当前浏览器不能安全发起回信重试" description="浏览器缺少跨标签互斥能力，暂不能发起新的重试；已有原请求仍可只读找回。请在支持此能力的浏览器中核对回信状态。" />
      <ElAlert v-if="deliveryRetryJournalInvalid" type="warning" :closable="false" class="panel-alert"
        title="回信重试记录无法保存或读取" description="为避免重复回信，暂不能发起新的回信重试。请保留此浏览器的原请求记录并核对回信；记录异常不会按未发送处理。" />
      <ElAlert v-if="otherDeliveryRecoveryCount" type="warning" :closable="false" class="panel-alert"
        title="存在尚未确认的回信重试" description="请切回原应用环境与渠道绑定，使用原管理员身份沿原请求号找回。切换页面或身份不会清除未确认请求，也不会自动重新发送。" />
      <ElAlert v-if="pendingFeishu || pendingWecom" type="warning" :closable="false" class="panel-alert"
        title="渠道配置仍有待确认保存" description="凭据已从本页清除，只保留原请求记录。请按原请求只读找回；未知期间不会再次保存或替换凭据。" />
      <ElAlert v-if="credentialJournalInvalid" type="warning" :closable="false" class="panel-alert"
        title="渠道配置恢复记录无法完整保存或读取" description="暂不能提交新的凭据配置。记录异常不能视为没有待确认保存；请保留当前浏览器的原请求记录。" />
      <ElButton v-if="credentialJournalInvalid" link type="primary" :disabled="feishuWriting || wecomWriting || !!credentialRecoveringKey"
        @click="readCredentialJournal">重新读取原配置恢复记录</ElButton>
      <ElAlert v-if="otherCredentialRecoveryCount" type="warning" :closable="false" class="panel-alert"
        title="其他身份或环境有待确认渠道配置" description="请切回原管理员与原环境，只读找回原配置收据。不会保留或回填秘密凭据，也不会自动重新保存。" />
      <ElAlert v-if="credentialRecoveryError" type="warning" :closable="false" class="panel-alert" :title="credentialRecoveryError" />
      <div v-if="currentCredentialRecoveries.length" aria-label="原渠道配置保存恢复" class="panel-alert">
        <p v-for="item in currentCredentialRecoveries" :key="`${item.platform}:${item.request_key}`">
          {{ item.platform === 'feishu' ? '飞书' : '企业微信' }} · 渠道 {{ item.code }}（#{{ item.channel_binding_id }}）· 原请求 {{ item.request_key }}
          <ElButton link type="primary" :loading="credentialRecoveringKey === item.request_key"
            :disabled="!!credentialRecoveringKey || feishuWriting || wecomWriting" @click="confirmCredentialSave(item)">只读找回原配置收据</ElButton>
        </p>
        <p class="preview-note">已确认的是这次原保存操作；当前渠道配置是否后来变化，以重新读取的渠道记录为准。</p>
      </div>
      <ElAlert v-if="bindingJournalInvalid" type="warning" :closable="false" class="panel-alert"
        title="原渠道操作记录无法安全保存或读取" description="暂不能提交保存或停用。请保留当前浏览器记录；记录异常不能视为未提交。" />
      <ElAlert v-if="otherBindingRecoveryCount" type="warning" :closable="false" class="panel-alert"
        title="其他身份或环境有待确认渠道操作" description="请切回原管理员与原环境，只读找回原请求；切换不会重新提交或清除原记录。" />
      <div v-if="currentBindingRecoveries.length" aria-label="原渠道操作恢复" class="panel-alert">
        <ElAlert type="warning" :closable="false" show-icon title="渠道绑定操作结果尚未确认"
          description="只查询原请求回执。未找到或无法完整确认时保留原请求，不会再次保存或停用。" />
        <p v-for="item in currentBindingRecoveries" :key="item.request_key">
          {{ item.operation === 'save' ? `保存渠道 ${item.code}` : `停用绑定 #${item.channel_binding_id}` }} · 原请求 {{ item.request_key }}
          <ElButton link type="primary" :loading="bindingRecoveringKey === item.request_key"
            :disabled="!!bindingRecoveringKey" @click="confirmBindingMutation(item)">只读找回原结果</ElButton>
        </p>
      </div>
      <ElTable v-loading="loading" :data="rows" row-key="channel_binding_id" empty-text="还没有渠道绑定">
        <ElTableColumn label="渠道名称" prop="name" min-width="150" />
        <ElTableColumn label="渠道代码" prop="code" min-width="140" />
        <ElTableColumn label="智能体" min-width="170"><template #default="{ row }">{{ row.agent_name }} · {{ row.agent_code }}</template></ElTableColumn>
        <ElTableColumn label="调用身份" prop="workload_client_id" min-width="100" />
        <ElTableColumn label="飞书配置" width="105"><template #default="{ row }"><ElTag :type="row.feishu_configured ? 'success' : 'info'">{{ row.feishu_configured ? '已托管' : '未托管' }}</ElTag></template></ElTableColumn>
        <ElTableColumn label="企业微信配置" width="120"><template #default="{ row }"><ElTag :type="row.wecom_configured ? 'success' : 'info'">{{ row.wecom_configured ? '已托管' : '未托管' }}</ElTag></template></ElTableColumn>
        <ElTableColumn label="状态" width="100"><template #default="{ row }"><ElTag :type="row.status === 1 ? 'success' : 'info'">{{ row.status === 1 ? '可用' : '已停用' }}</ElTag></template></ElTableColumn>
        <ElTableColumn label="操作" width="485"><template #default="{ row }">
          <ElButton link type="primary" :disabled="writing || !!pendingBindingMutation" @click="edit(row)">编辑绑定</ElButton>
          <ElButton v-if="row.status === 1" link type="primary" :disabled="feishuWriting" @click="openFeishu(row)">飞书配置</ElButton>
          <ElButton v-if="row.status === 1" link type="primary" :disabled="wecomWriting" @click="openWecom(row)">企业微信配置</ElButton>
            <ElButton
              v-if="row.status === 1"
              link
              type="primary"
              :disabled="previewLoading || previewClosing"
              @click="openPreview(row)"
              >应用试用</ElButton
            >
          <ElButton link type="primary" @click="showDeliveries(row)">回信记录</ElButton>
          <ElButton v-if="row.status === 1" link type="danger" :disabled="writing || !!pendingBindingMutation" @click="disable(row)">停用</ElButton>
        </template></ElTableColumn>
      </ElTable>
      <ElPagination v-if="total > 20" class="pager" background layout="prev, pager, next, total"
        :current-page="page" :page-size="20" :total="total" @current-change="load" />
      <template v-if="selectedBinding">
        <ElDivider />
        <div class="panel-head">
          <h3>「{{ selectedBinding.name }}」连接与回信</h3>
          <ElButton :loading="deliveryLoading || statusLoading || wecomStatusLoading || wecomDeliveryLoading" @click="refreshChannelStatus">刷新状态</ElButton>
        </div>
        <div v-if="currentDeliveryRecoveries.length" aria-label="未确认回信重试恢复" class="panel-alert">
          <ElAlert type="warning" :closable="false" show-icon title="回信重试提交结果尚未确认"
            description="只按原请求号读取受理回执，不再次发送回信。未找到或无权读取时仍保留原请求锁；实际发送进度以刷新后的回信记录为准。" />
          <p v-for="item in currentDeliveryRecoveries" :key="item.key">
            {{ item.platform === 'wecom' ? '企业微信' : '飞书' }} · 原任务 #{{ item.task_id }}
            <ElButton link type="primary" :loading="deliveryRecoveringKey === item.key"
              :disabled="Boolean(retryingTaskId) || Boolean(deliveryRecoveringKey)" @click="recoverDeliveryRetry(item)">沿原请求号找回</ElButton>
          </p>
          <ElAlert v-if="deliveryRecoveryError" type="error" :closable="false" :title="deliveryRecoveryError" />
        </div>
        <h4>飞书连接状态</h4>
        <ElAlert v-if="statusError" type="error" :title="statusError" show-icon class="panel-alert" />
        <div v-if="channelStatus" class="channel-status">
          <p>已验签回调：{{ channelStatus.last_verified_callback_at || '尚未观察到' }}</p>
          <p>已受理消息：{{ channelStatus.last_accepted_message_at || '尚未观察到' }}</p>
          <p>最近回信：{{ channelStatus.last_delivery_state ? `${deliveryState(channelStatus.last_delivery_state)} · ${channelStatus.last_delivery_at}` : '尚未观察到' }}</p>
          <p v-if="channelStatus.last_callback_error_code">最近回调异常：{{ channelStatus.last_callback_error_code }} · {{ channelStatus.last_callback_error_at }}</p>
          <p v-if="channelStatus.last_delivery_error_code">最近回信异常：{{ channelStatus.last_delivery_error_code }} · {{ channelStatus.last_delivery_error_at }}</p>
        </div>
        <ElAlert v-if="deliveryError" type="error" :title="deliveryError" show-icon class="panel-alert" @close="deliveryError = ''" />
        <ElTable v-loading="deliveryLoading" :data="deliveries" row-key="task_id" empty-text="还没有飞书回信记录">
          <ElTableColumn label="任务" prop="task_id" width="110" />
          <ElTableColumn label="任务状态" prop="task_state" width="110" />
          <ElTableColumn label="回信状态" width="110"><template #default="{ row }">{{ deliveryState(row.state) }}</template></ElTableColumn>
          <ElTableColumn label="发送次数" prop="attempt_count" width="95" />
          <ElTableColumn label="失败原因" min-width="180"><template #default="{ row }">{{ row.error_code || '—' }}</template></ElTableColumn>
          <ElTableColumn label="更新时间" prop="update_time" min-width="165" />
          <ElTableColumn label="操作" width="110"><template #default="{ row }">
            <ElButton v-if="row.state === 'failed' && selectedBinding?.status === 1 && row.channel_revision === selectedBinding.revision"
              link type="primary" :loading="retryingTaskId === row.task_id" :disabled="Boolean(retryingTaskId) || deliveryRetryBlocked(row.task_id, 'feishu')" @click="retryDelivery(row)">重试回信</ElButton>
          </template></ElTableColumn>
        </ElTable>
        <ElAlert v-if="channelStatus?.last_delivery_state === 'uncertain' || deliveries.some(row => row.state === 'uncertain')"
          type="warning" :closable="false" show-icon class="panel-alert"
          title="飞书回信结果无法确认" description="可能已经送达，系统不会自动重发，也不能重试。请先在飞书侧核实；刷新状态只读取原投递记录，不会重新发送回信。" />
        <ElPagination v-if="deliveryTotal > 20" class="pager" background layout="prev, pager, next, total"
          :current-page="deliveryPage" :page-size="20" :total="deliveryTotal" @current-change="loadDeliveries" />
        <h4>企业微信连接与回信</h4>
        <ElAlert v-if="wecomStatusError" type="error" :title="wecomStatusError" show-icon class="panel-alert" />
        <div v-if="wecomStatus" class="channel-status">
          <p>已验签回调：{{ wecomStatus.last_verified_callback_at || '尚未观察到' }}</p>
          <p>已受理消息：{{ wecomStatus.last_accepted_message_at || '尚未观察到' }}</p>
          <p>最近回信：{{ wecomStatus.last_delivery_state ? `${deliveryState(wecomStatus.last_delivery_state)} · ${wecomStatus.last_delivery_at}` : '尚未观察到' }}</p>
          <p v-if="wecomStatus.last_callback_error_code">最近回调异常：{{ wecomStatus.last_callback_error_code }} · {{ wecomStatus.last_callback_error_at }}</p>
          <p v-if="wecomStatus.last_delivery_error_code">最近回信异常：{{ wecomStatus.last_delivery_error_code }} · {{ wecomStatus.last_delivery_error_at }}</p>
        </div>
        <ElAlert v-if="wecomDeliveryError" type="error" :title="wecomDeliveryError" show-icon class="panel-alert" />
        <ElTable v-loading="wecomDeliveryLoading" :data="wecomDeliveries" row-key="task_id" empty-text="还没有企业微信回信记录">
          <ElTableColumn label="任务" prop="task_id" width="110" />
          <ElTableColumn label="任务状态" prop="task_state" width="110" />
          <ElTableColumn label="回信状态" width="110"><template #default="{ row }">{{ deliveryState(row.state) }}</template></ElTableColumn>
          <ElTableColumn label="已确认段数" width="110"><template #default="{ row }">{{ row.part_count == null ? '—' : `${row.confirmed_parts}/${row.part_count}` }}</template></ElTableColumn>
          <ElTableColumn label="发送次数" prop="attempt_count" width="95" />
          <ElTableColumn label="错误码" min-width="200"><template #default="{ row }">{{ row.error_code || '—' }}</template></ElTableColumn>
          <ElTableColumn label="更新时间" prop="update_time" min-width="165" />
          <ElTableColumn label="操作" width="105">
            <template #default="{ row }">
              <ElButton v-if="row.state === 'failed' && selectedBinding?.status === 1 && row.channel_revision === selectedBinding.revision"
                link type="primary" :loading="retryingTaskId === row.task_id"
                :disabled="Boolean(retryingTaskId) || deliveryRetryBlocked(row.task_id, 'wecom')" @click="retryWecomDelivery(row)">重试剩余段</ElButton>
            </template>
          </ElTableColumn>
        </ElTable>
        <ElAlert v-if="wecomDeliveries.some(row => row.state === 'uncertain')" type="warning" :closable="false" show-icon
          title="部分回信结果无法确认" description="可能已经送达，系统不会自动重发；请先在企业微信侧核实。" class="panel-alert" />
        <ElPagination v-if="wecomDeliveryTotal > 20" class="pager" background layout="prev, pager, next, total"
          :current-page="wecomDeliveryPage" :page-size="20" :total="wecomDeliveryTotal" @current-change="loadWecomDeliveries" />
      </template>
      <template v-if="previewBinding">
        <ElDivider />
        <div class="panel-head">
          <div>
            <h3>「{{ previewBinding.name }}」应用消息试用</h3>
            <p>使用绑定的应用与工作负载身份调用正式渠道接口；每轮模型调用独立计费。</p>
          </div>
          <ElButton
            :loading="previewLoading"
            :disabled="previewClosing || loading || previewBinding.status !== 1"
            @click="loadPreviewMessages()"
            >刷新会话</ElButton
          >
        </div>
        <ElAlert v-if="previewError" type="error" :title="previewError" show-icon class="panel-alert" />
        <ElAlert v-if="receiptRecoveryInvalid" type="warning" :closable="false" show-icon class="panel-alert"
          title="原结果恢复记录无法确认，暂不能提交新恢复；请保留原记录。会话仍可只读刷新。" />
        <p v-if="!deliveryRetryLockAvailable" class="preview-note">此浏览器不支持安全保存恢复意图，请换支持浏览器锁的浏览器；已有原请求仍可只读找回。</p>
        <ElAlert v-if="previewRecoveryInvalid" type="warning" :closable="false" show-icon class="panel-alert"
          title="原会话恢复记录无法确认"
          description="记录损坏或无法读取，暂不能发送、关闭或新建会话。请保留原记录；已有有效标识仍可只读查询原消息与历史。" />
        <ElForm label-width="110px" @submit.prevent>
          <RuntimeApiKeyField
            v-model="previewApiKey"
            :persist-in-session="false"
            placeholder="粘贴 SandIAM 工作负载上下文；刷新页面后需重新填写"
          />
          <ElFormItem label="消息入口">
            <ElSelect v-model="previewPlatform" style="width: 220px"
              :disabled="previewLoading || previewClosing || !!pendingPreview || !!pendingCloseKey"
              @change="changePreviewPlatform">
              <ElOption label="应用试聊" value="" />
              <ElOption label="飞书原会话恢复" value="feishu" />
              <ElOption label="企业微信原会话恢复" value="wecom" />
            </ElSelect>
          </ElFormItem>
          <ElAlert v-if="previewPlatform" type="info" :closable="false"
            title="填写真实平台的原会话与发送者标识"
            description="这里只读找回已受理消息、来源与费用；新的消息请在对应平台发送。未找到原记录不会重新发送或调用模型。当前平台配置、身份及原消息归属仍由服务端核验。" />
          <ElFormItem label="会话标识">
            <ElInput v-model.trim="previewConversationId" maxlength="128" style="max-width: 440px"
              :disabled="previewLoading || previewClosing || !!pendingPreview || !!pendingCloseKey || (previewRecoveryInvalid && !previewPlatform)"
              placeholder="用于续接同一会话"
            />
            <ElButton
              :disabled="previewLoading || previewClosing || !!pendingPreview || !!pendingCloseKey || previewRecoveryInvalid"
              v-if="!previewPlatform" @click="newPreviewConversation"
              >新会话</ElButton
            >
          </ElFormItem>
          <ElFormItem label="发送者标识">
            <ElInput v-model.trim="previewSenderId" maxlength="128" style="max-width: 440px"
              :disabled="previewLoading || previewClosing || !!pendingPreview || !!pendingCloseKey || (previewRecoveryInvalid && !previewPlatform)"
              placeholder="用于隔离不同发送者"
            />
          </ElFormItem>
          <ElFormItem v-if="previewPlatform" label="原消息标识">
            <ElInput v-model.trim="platformMessageId" maxlength="128" style="max-width: 440px"
              :disabled="previewLoading || previewClosing" placeholder="飞书 message_id 或企业微信 MsgId" />
            <ElButton :loading="previewLoading"
              :disabled="previewLoading || previewClosing || !previewApiKey.trim() || !platformMessageId.trim() || !previewConversationId.trim() || !previewSenderId.trim()"
              @click="recoverPlatformMessage">只读找回原消息</ElButton>
          </ElFormItem>
          <ElFormItem v-if="!previewPlatform" label="消息">
            <ElInput
              v-model="previewQuery"
              :disabled="previewLoading || previewClosing || !!pendingPreview"
              type="textarea"
              :rows="3"
              maxlength="500"
              style="max-width: 640px"
            />
          </ElFormItem>
          <ElFormItem>
            <ElButton v-if="!previewPlatform" type="primary" :loading="previewLoading"
              :disabled="previewLoading || previewClosing || !!pendingCloseKey || !previewApiKey.trim() || !previewConversationId.trim() || !previewSenderId.trim() || (!pendingPreview && !previewQuery.trim()) || (previewRecoveryInvalid && (!pendingPreview || pendingPreviewUnsent))"
              @click="sendPreviewMessage"
              >{{ pendingPreview ? (pendingPreviewUnsent ? '保存原号后首次发送' : '找回原消息') : '发送应用消息' }}</ElButton
            >
            <ElButton :loading="previewClosing"
              :disabled="previewLoading || !!pendingPreview || !previewApiKey.trim() || !previewConversationId.trim() || !previewSenderId.trim() || previewRecoveryInvalid"
              @click="closePreviewConversation">{{ pendingCloseKey ? (pendingCloseUnsent ? '保存原号后关闭' : '只读找回原关闭') : '关闭当前对话' }}</ElButton>
          </ElFormItem>
        </ElForm>
        <ElAlert v-if="pendingPreview && !previewLoading" type="warning" :closable="false" show-icon
          :title="pendingPreviewUnsent
            ? '本页原消息尚未发送；恢复会话存储后可明确保存原号并首次发送。刷新或身份变化后仅能只读找回。'
            : '原消息提交结果尚未确认，请只读找回；无记录或读取失败不证明未发送，不再次提交。'"
          :description="
            pendingPreview.query
              ? `待确认消息：${pendingPreview.query}`
              : '已保留原消息编号；找回将读取原任务，不会重新生成回复。'
          "
          class="panel-alert"
        />
        <ElAlert v-if="pendingCloseKey && !previewClosing" type="warning" :closable="false" show-icon
          :title="pendingCloseUnsent ? '关闭请求尚未发送，请保留当前页面并沿原标识重试。' : '关闭结果尚未确认，请重试确认关闭后再发送消息。'" class="panel-alert" />
        <p v-if="previewClosedAfterTaskId" class="preview-note">已关闭的消息仅供查阅；下一条消息将从新的上下文开始，不带入已关闭的历史。</p>
        <p v-if="lastPreviewRequest?.taskId" class="preview-note"
          >已确认原任务 #{{
            lastPreviewRequest.taskId
          }}；刷新会话会读取当前进度，不会重新调用模型。</p
        >
        <section v-for="intent in receiptRecoveryIntents.filter(item => !previewMessages.some(message => message.task.id === item.task_id))"
          :key="intent.key" class="preview-note">
          <p>原任务 #{{ intent.task_id }} 的恢复提交结果待确认；保留原授权请求号。</p>
          <ElButton :loading="receiptRecoveryBusy === intent.task_id"
            :disabled="previewLoading || previewClosing || receiptRecoveryBusy !== null || !previewApiKey.trim()"
            @click="findReceiptRecovery(intent.task_id)">只读找回原恢复收据</ElButton>
          <p v-if="receiptRecoveryErrors[intent.task_id]" role="status">{{ receiptRecoveryErrors[intent.task_id] }}</p>
        </section>
        <ElEmpty
          v-if="previewMessages.length === 0"
          :description="
            pendingPreview || lastPreviewRequest
              ? '请找回原消息或刷新会话，确认任务状态'
              : '当前会话还没有消息'
          "
        />
        <div v-for="message in previewMessages" :key="message.task.id" class="preview-message">
          <ElTag :type="message.task.id <= previewClosedAfterTaskId ? 'info' : 'success'" size="small">
            {{ message.task.id <= previewClosedAfterTaskId ? '历史消息 · 只读' : '当前上下文' }}
          </ElTag>
          <p v-if="message.query !== null"><strong>问题：</strong>{{ message.query }}</p>
          <p v-else class="preview-note">原消息内容未在恢复元数据中展示。</p>
          <p
            ><strong>任务 #{{ message.task.id }}：</strong
            >{{ previewTaskState(message.task.state) }}</p
          >
          <p v-if="message.task.result?.output?.content"><strong>回复：</strong>{{ message.task.result.output.content }}</p>
          <section v-if="message.task.streaming_enabled" class="preview-note">
            <ElButton link type="primary" :disabled="previewClosing || !!previewStreamTaskId"
              @click="startPreviewStream(message.task.id)">{{ previewStreamViews[message.task.id] ? '沿原游标只读重连' : '读取这次原运行的实时预览' }}</ElButton>
            <ElButton v-if="previewStreamTaskId === message.task.id" link @click="pausePreviewStream(message.task.id)">暂停实时读取</ElButton>
            <p v-if="previewStreamViews[message.task.id]" role="status">
              实时连接：{{ previewConnectionLabel(previewStreamViews[message.task.id].transport) }} · 原任务 #{{ message.task.id }} · 已读事件 {{ previewStreamViews[message.task.id].lastEvent }}
            </p>
            <p v-if="previewStreamViews[message.task.id]">{{ previewStreamViews[message.task.id].status }}</p>
            <template v-if="previewStreamViews[message.task.id] && !message.task.result?.output?.content">
              <p>以下为生成中的预览，不代表已完成回复；失败或中断时请查看原任务和费用。</p>
              <p v-for="(text, round) in previewStreamViews[message.task.id].rounds" :key="round">第 {{ round }} 轮预览：{{ text }}</p>
            </template>
            <p v-if="previewStreamViews[message.task.id]?.error">{{ previewStreamViews[message.task.id].error }}</p>
          </section>

          <p v-if="message.task.result?.review_required" class="preview-note">结果需人工复核后使用。</p>
          <p v-if="message.task.error_code" class="preview-note">失败原因：{{ message.task.error_code }}</p>
          <section v-if="receiptProjection(message.task) || receiptIntent(message.task.id) || receiptRecoveryErrors[message.task.id]" class="preview-note">
            <p>原结果恢复只使用已完成的原回执，不重新生成回复或调用模型、检索和工具。</p>
            <ElButton v-if="receiptIntent(message.task.id)" :loading="receiptRecoveryBusy === message.task.id"
              :disabled="previewLoading || previewClosing || receiptRecoveryBusy !== null || !previewApiKey.trim()"
              @click="findReceiptRecovery(message.task.id)">只读找回原恢复收据</ElButton>
            <ElButton v-else-if="receiptProjection(message.task)?.available" type="primary"
              :loading="receiptRecoveryBusy === message.task.id" :disabled="!canRecoverReceipt(message.task)"
              @click="recoverOriginalReceipt(message.task)">恢复原结果</ElButton>
            <p v-if="receiptIntent(message.task.id)" role="status">这次恢复提交结果待确认；保留原授权请求号，不重复提交。</p>
            <p v-else-if="receiptProjection(message.task) && !receiptProjection(message.task)?.available">
              {{ receiptRecoveryStatus(message.task) }}
            </p>
            <p v-if="receiptProjection(message.task) && !receiptProjection(message.task)?.available">
              已记录的调用费用可在下方查看；未确认的费用仍为未知。
            </p>
            <p v-if="receiptRecoveryErrors[message.task.id]" role="status">{{ receiptRecoveryErrors[message.task.id] }}</p>
          </section>
          <ChannelAgentRecoveryActions
            :key="`${currentAdminId}:${previewScope}:${message.task.id}`"
            :api-key="previewApiKey" :task="message.task" :channel-code="previewBinding.code"
            :conversation-id="previewConversationId.trim()" :sender-id="previewSenderId.trim()"
            :delivery-platform="previewDeliveryPlatform" :scope="`${currentAdminId}:${previewScope}`"
            :disabled="previewBinding.status !== 1 || previewLoading || previewClosing || !!pendingPreview || !!pendingCloseKey"
            @updated="updatePreviewTask" @denied="clearDeniedPreview" />
          <section v-if="message.task.result?.source_references?.length" class="preview-note">
            <p>回复来源</p>
            <p v-for="source in message.task.result.source_references" :key="source.source_block_id">
              文件 #{{ source.file_id }} · {{ sourcePosition(source) }} · 来源块 #{{ source.source_block_id }}
              <ElButton link type="primary" :disabled="!previewApiKey.trim() || previewSourceLoading"
                @click="openPreviewSource(message.task.id, source.source_block_id)">查看来源原文</ElButton>
            </p>
          </section>
          <section v-else-if="previewStreamViews[message.task.id] && Object.keys(previewStreamViews[message.task.id].sourceEvents).length" class="preview-note">
            <p>预览来源 · 仅说明本轮生成片段使用了这些资料，不代表已完成回复。</p>
            <ElButton v-for="(eventNo, blockId) in previewStreamViews[message.task.id].sourceEvents" :key="blockId"
              link type="primary" :disabled="!previewApiKey.trim() || previewSourceLoading"
              @click="openPreviewSource(message.task.id, Number(blockId), eventNo)">查看来源块 #{{ blockId }}</ElButton>
          </section>
          <section v-if="message.task.summary_coverage" class="preview-note">
            <p>历史摘要：{{ previewTaskState(message.task.summary_coverage.state) }}。摘要用于对话上下文，事实仍以本轮授权来源为依据。</p>
            <p>完整归入摘要：{{ message.task.summary_coverage.covered_task_ids.map(id => `#${id}`).join('、') || '无' }}；部分归入：{{ message.task.summary_coverage.partially_covered_task_ids.map(id => `#${id}`).join('、') || '无' }}。</p>
            <p>仍保留的历史：{{ message.task.summary_coverage.retained_task_ids.map(id => `#${id}`).join('、') || '无' }}；本轮未载入的更早历史：{{ message.task.summary_coverage.omitted_prior_turn_count ?? '数量未确认' }} 轮。</p>
            <p>原消息仍可只读查阅，摘要不会删除历史。</p>
          </section>
          <section class="preview-note">
            <p
              >模型调用：{{
                previewInvocations(message.task).length
                  ? `${message.task.agent_invocations?.length ?? previewInvocations(message.task).length} 轮${message.task.summary_invocation ? '，另有 1 次历史摘要' : ''}`
                  : '尚无调用记录，费用未确认'
              }}</p
            >
            <ElButton
              v-if="previewInvocations(message.task).length"
              link
              type="primary"
              :loading="previewCostLoading === message.task.id"
              :disabled="previewLoading || previewClosing || previewCostLoading !== null"
              @click="loadPreviewCosts(message.task)"
              >查看模型与摘要费用</ElButton
            >
            <p
              v-for="(invocation, index) in previewInvocations(message.task)"
              :key="invocation.request_id"
            >
              {{ invocation.request_id === message.task.summary_invocation?.request_id ? '历史摘要' : `第 ${index + 1} 轮` }} · {{ previewTaskState(invocation.state) }} · 预估：{{
                previewAmount(previewCosts[invocation.request_id], 'estimated')
              }}
              · 计量：{{ previewAmount(previewCosts[invocation.request_id], 'metered') }} ·
              已核对账单：{{ previewAmount(previewCosts[invocation.request_id], 'reconciled') }}
              <span v-if="previewCostErrors[invocation.request_id]">
                · {{ previewCostErrors[invocation.request_id] }}</span
              >
            </p>
            <p>累计预估：{{ previewCostTotal(message.task, 'estimated') }}</p>
            <p>累计计量：{{ previewCostTotal(message.task, 'metered') }}</p>
            <p>已核对账单累计：{{ previewCostTotal(message.task, 'reconciled') }}</p>
            <p
              >预估仅供参考；计量按实际用量与冻结价格计算，未对账金额不能当作账单。不同币种分别累计。</p
            >
          </section>
          <section
            v-if="
              message.task.embedding_invocations !== undefined || message.task.embedding_invocation
            "
            class="preview-note"
          >
            <p v-if="!previewEmbeddingInvocations(message.task).length"
              >当前没有模型检索调用记录；这不代表所有处理均无费用。</p
            >
            <p
              v-for="(retrieval, index) in previewEmbeddingInvocations(message.task)"
              :key="retrieval.request_id ?? index"
            >
              检索调用 {{ index + 1 }} ·
              {{
                retrieval.invocation ? previewTaskState(retrieval.invocation.state) : '调用待确认'
              }}
              · 计量：{{ previewEmbeddingAmount(retrieval) }}
            </p>
            <p>已知检索计量累计：{{ previewEmbeddingTotal(message.task) }}</p>
            <p>检索计量与回答模型费用分别展示；账单金额请在费用页核对。</p>
          </section>
        </div>
        <ElButton v-if="previewNextBeforeTaskId" :loading="previewLoading" :disabled="previewClosing"
          @click="loadPreviewMessages(true)">加载更早消息</ElButton>
      </template>
      <ElDialog v-model="previewSourceVisible" title="来源原文" width="640px" @closed="clearPreviewSource">
        <p v-if="previewSourceLoading">正在核对当前权限和原来源…</p>
        <ElAlert v-if="previewSourceError" type="error" :closable="false" :title="previewSourceError" />
        <template v-if="previewSource">
          <p>文件 #{{ previewSource.file_id }} · {{ sourcePosition(previewSource) }} · 来源块 #{{ previewSource.source_block_id }}</p>
          <ElAlert v-if="previewSource.reference_kind === 'stream_preview'" type="warning" :closable="false"
            title="生成预览使用的来源" description="这里是生成预览曾使用的来源；最终回复及引用以原任务记录为准。" />
          <pre class="preview-source-content">{{ previewSource.content }}</pre>
        </template>
        <template #footer><ElButton @click="clearPreviewSource">返回原消息</ElButton></template>
      </ElDialog>
      <ElDivider />
      <div class="panel-head">
        <h3>{{ editingId ? '编辑渠道绑定' : '新增渠道绑定' }}</h3>
        <div>
          <ElButton :loading="directoryLoading" :disabled="writing || !!pendingBindingMutation" @click="refreshBindingDirectory">重新读取可用目录</ElButton>
          <ElButton :disabled="writing || !!pendingBindingMutation" @click="newBinding">新建</ElButton>
        </div>
      </div>
      <ElForm label-width="110px" @submit.prevent>
        <ElFormItem label="渠道名称"><ElInput v-model="form.name" maxlength="128" :disabled="writing || !!pendingBindingMutation" placeholder="例如企业微信客服" /></ElFormItem>
        <ElFormItem label="渠道代码"><ElInput v-model="form.code" maxlength="64" :disabled="writing || !!pendingBindingMutation || !!editingId" placeholder="例如 wecom_support" /></ElFormItem>
        <ElFormItem label="使用智能体">
          <ElSelect v-model="form.agent_definition_id" filterable :disabled="writing || !!pendingBindingMutation" placeholder="选择已发布智能体" style="width: 100%">
            <ElOption v-if="form.agent_definition_id !== null && !selectedAgent" :value="form.agent_definition_id"
              :label="`原选择智能体 #${form.agent_definition_id}（未核对）`" disabled />
            <ElOption v-for="agent in agents" :key="agent.agent_definition_id" :label="`${agent.name} · ${agent.code} · 第${agent.active_version_no}版`" :value="agent.agent_definition_id" />
          </ElSelect>
          <ElButton v-if="agentMore" link :disabled="directoryLoading || writing || !!pendingBindingMutation" @click="loadAgents">加载更多智能体</ElButton>
          <p v-if="form.agent_definition_id !== null && !selectedAgent" class="preview-note">此选择尚未在可用目录中核对。原绑定仍保留；可加载更多或重新读取目录，也可重新选择已发布智能体。核对后才能保存。</p>
        </ElFormItem>
        <ElFormItem label="调用身份">
          <ElSelect v-model="form.workload_client_id" filterable :disabled="writing || !!pendingBindingMutation" placeholder="选择 SandIAM 服务调用身份" style="width: 100%">
            <ElOption v-if="form.workload_client_id && !selectedClient" :value="form.workload_client_id"
              :label="`原选择调用身份 #${form.workload_client_id}（未核对）`" disabled />
            <ElOption v-for="client in clients" :key="client.id" :label="`${client.name} · ${client.code}`" :value="String(client.id)" />
          </ElSelect>
          <ElButton v-if="clientMore" link :disabled="directoryLoading || writing || !!pendingBindingMutation" @click="loadClients">加载更多调用身份</ElButton>
          <p v-if="form.workload_client_id && !selectedClient" class="preview-note">此调用身份尚未在当前应用环境的可用目录中核对。原绑定仍保留；请加载更多、重新读取目录或重新选择，再保存。</p>
        </ElFormItem>
        <ElFormItem><ElButton type="primary" :loading="writing" :disabled="!canSave" @click="save">保存绑定</ElButton></ElFormItem>
      </ElForm>
      <ElAlert type="info" :closable="false" show-icon title="渠道只负责消息接入" description="适配器还需验证平台签名；资料访问和模型调用权限继续由 SandIAM 控制。" />
      <ElDialog v-model="feishuVisible" :title="`飞书配置 · ${feishuBinding?.name ?? ''}`" width="600px" @closed="closeFeishu">
        <ElAlert type="info" :closable="false" show-icon title="凭据只写，保存后不回显" description="五项凭据每次完整替换。工作负载凭据须来自当前绑定的 SandIAM 调用身份；服务授权仍在 SandIAM 配置。" />
        <p class="preview-note">回调路径：/api/sand-ai/v1/channels/{{ feishuBinding?.code }}/feishu/events</p>
        <ElAlert v-if="feishuError" type="error" :title="feishuError" show-icon class="panel-alert" />
        <ElAlert v-if="pendingFeishu" type="warning" :closable="false" show-icon title="上次保存结果未确认" description="原凭据已清除，只读找回原请求收据；确认前不接受另一组凭据，不再次提交保存。" class="panel-alert" />
        <ElForm label-width="145px" @submit.prevent>
          <ElFormItem label="Encrypt Key"><ElInput v-model="feishuForm.encrypt_key" type="password" show-password :disabled="feishuWriting || !!pendingFeishu" autocomplete="off" /></ElFormItem>
          <ElFormItem label="Verification Token"><ElInput v-model="feishuForm.verification_token" type="password" show-password :disabled="feishuWriting || !!pendingFeishu" autocomplete="off" /></ElFormItem>
          <ElFormItem label="SandIAM 工作负载凭据"><ElInput v-model="feishuForm.workload_credential" type="password" show-password :disabled="feishuWriting || !!pendingFeishu" autocomplete="off" /></ElFormItem>
          <ElFormItem label="App ID"><ElInput v-model="feishuForm.app_id" :disabled="feishuWriting || !!pendingFeishu" autocomplete="off" /></ElFormItem>
          <ElFormItem label="App Secret"><ElInput v-model="feishuForm.app_secret" type="password" show-password :disabled="feishuWriting || !!pendingFeishu" autocomplete="off" /></ElFormItem>
        </ElForm>
        <template #footer>
          <ElButton v-if="pendingFeishu" :loading="credentialRecoveringKey === pendingFeishu.request_key"
            :disabled="feishuWriting || !!credentialRecoveringKey" @click="confirmCredentialSave(pendingFeishu)">只读找回原配置收据</ElButton>
          <ElButton @click="feishuVisible = false">关闭</ElButton>
          <ElButton v-if="!pendingFeishu" type="primary" :loading="feishuWriting" :disabled="!canSaveFeishu" @click="saveFeishu">保存配置</ElButton>
        </template>
      </ElDialog>
      <ElDialog v-model="wecomVisible" :title="`企业微信配置 · ${wecomBinding?.name ?? ''}`" width="600px" @closed="closeWecom">
        <ElAlert type="info" :closable="false" show-icon title="凭据只写，保存后不回显" description="六项配置每次完整替换；工作负载凭据来自当前绑定的 SandIAM 调用身份。" />
        <p class="preview-note">回调路径：/api/sand-ai/v1/channels/{{ wecomBinding?.code }}/wecom/events</p>
        <ElAlert v-if="wecomError" type="error" :title="wecomError" show-icon class="panel-alert" />
        <ElAlert v-if="pendingWecom" type="warning" :closable="false" show-icon title="上次保存结果未确认" description="原凭据已清除，只读找回原请求收据；确认前不接受另一组凭据，不再次提交保存。" class="panel-alert" />
        <ElForm label-width="150px" @submit.prevent>
          <ElFormItem label="回调 Token"><ElInput v-model="wecomForm.token" type="password" show-password :disabled="wecomWriting || !!pendingWecom" autocomplete="off" /></ElFormItem>
          <ElFormItem label="EncodingAESKey"><ElInput v-model="wecomForm.encoding_aes_key" type="password" show-password :disabled="wecomWriting || !!pendingWecom" autocomplete="off" /></ElFormItem>
          <ElFormItem label="企业 ID"><ElInput v-model="wecomForm.corp_id" :disabled="wecomWriting || !!pendingWecom" autocomplete="off" /></ElFormItem>
          <ElFormItem label="应用 AgentId"><ElInput v-model="wecomForm.agent_id" :disabled="wecomWriting || !!pendingWecom" autocomplete="off" /></ElFormItem>
          <ElFormItem label="应用 Secret"><ElInput v-model="wecomForm.corp_secret" type="password" show-password :disabled="wecomWriting || !!pendingWecom" autocomplete="off" /></ElFormItem>
          <ElFormItem label="SandIAM 工作负载凭据"><ElInput v-model="wecomForm.workload_credential" type="password" show-password :disabled="wecomWriting || !!pendingWecom" autocomplete="off" /></ElFormItem>
        </ElForm>
        <template #footer>
          <ElButton v-if="pendingWecom" :loading="credentialRecoveringKey === pendingWecom.request_key"
            :disabled="wecomWriting || !!credentialRecoveringKey" @click="confirmCredentialSave(pendingWecom)">只读找回原配置收据</ElButton>
          <ElButton @click="wecomVisible = false">关闭</ElButton>
          <ElButton v-if="!pendingWecom" type="primary" :loading="wecomWriting" :disabled="!canSaveWecom" @click="saveWecom">保存配置</ElButton>
        </template>
      </ElDialog>
    </template>
  </ElCard>
</template>

<script setup lang="ts">
  import { useUserStore } from '@/store/modules/user'
  import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
  import { ElMessage, ElMessageBox } from 'element-plus'
  import agentDefinitionApi, { type AgentDefinitionRow } from '../../api/agentDefinition'
  import channelBindingApi, { isChannelReceiptRecoveryProjection, type ChannelReceiptRecoveryInput, type ChannelReceiptRecoveryTask, readChannelTokenStream, channelBindingInputSha256, channelCredentialInputSha256, provesChannelBindingSnapshot, type ChannelConversationCloseReceipt, type ChannelCredentialSaveReference, type ChannelCredentialSaveReceipt, type ChannelCredentialPlatform, type ChannelBindingOperationReference, type ChannelBindingDraft, type ChannelBindingRow, type ChannelDeliveryRow, type ChannelMessage, type FeishuCredentialDraft, type FeishuChannelStatus, type WecomCredentialDraft, type WecomChannelStatus } from '../../api/channelBinding'
  import type { SandAiChannelSource, SandAiSourceReference, SandAiInvocationCost, SandAiTask } from '../../api/types'
  import RuntimeApiKeyField from '../../components/RuntimeApiKeyField.vue'
  import ChannelAgentRecoveryActions from './ChannelAgentRecoveryActions.vue'
  import iamApi, { type SandIamEnvironmentReference, type SandIamWorkloadClientReference } from '../../api/iam'
  import { newRuntimeRequestId, SandAiRuntimeError } from '../../api/runtime'
  import { AgentReplayError, isAgentReadDenied } from '../../api/agentEventReplay'

  const props = defineProps<{ environment: SandIamEnvironmentReference | null }>()
  const userStore = useUserStore()
  const deliveryRetryLockAvailable = typeof navigator !== 'undefined' && !!navigator.locks
  const deliveryRetryJournalKey = 'sand-ai-channel-delivery-retry-journal:v1'
  type DeliveryPlatform = 'feishu' | 'wecom'
  type DeliveryRetryReference = Readonly<{
    key: string; user_id: string; environment_id: number; channel_binding_id: number; task_id: number; platform: DeliveryPlatform
  }>
  const deliveryRetryJournal = ref<DeliveryRetryReference[]>([])
  const deliveryRetryJournalInvalid = ref(false)
  const deliveryRecoveryError = ref('')
  const deliveryRecoveringKey = ref('')
  const currentAdminId = computed(() => userStore.info.id == null ? '' : String(userStore.info.id))
  const currentDeliveryRecoveries = computed(() => deliveryRetryJournal.value.filter(item => item.user_id === currentAdminId.value
    && item.environment_id === props.environment?.id && item.channel_binding_id === selectedBinding.value?.channel_binding_id))
  const otherDeliveryRecoveryCount = computed(() => deliveryRetryJournal.value.length - currentDeliveryRecoveries.value.length)
  let deliveryIdentityEpoch = 0
  const rows = ref<ChannelBindingRow[]>([])
  const agents = ref<AgentDefinitionRow[]>([])
  const clients = ref<SandIamWorkloadClientReference[]>([])
  const total = ref(0)
  const page = ref(1)
  const agentPage = ref(0)
  const clientPage = ref(0)
  const agentMore = ref(false)
  const clientMore = ref(false)
  const loading = ref(false)
  const writingFlag = ref(false)
  const bindingWriteOwner = ref<symbol | null>(null)
  const writing = computed(() => writingFlag.value || bindingWriteOwner.value !== null)
  const directoryLoading = ref(false)
  type BindingJournalReference = ChannelBindingOperationReference & { readonly user_id: string }
  const bindingJournalStorageKey = 'sand-ai-channel-binding-operation-journal:v1'
  const bindingJournal = ref<BindingJournalReference[]>([])
  const bindingJournalInvalid = ref(false)
  const bindingRecoveringKey = ref('')
  const currentBindingRecoveries = computed(() => bindingJournal.value.filter(item =>
    item.user_id === currentAdminId.value && item.environment_id === props.environment?.id))
  const otherBindingRecoveryCount = computed(() => bindingJournal.value.length - currentBindingRecoveries.value.length)
  const pendingBindingMutation = computed(() => bindingJournalInvalid.value || currentBindingRecoveries.value.length > 0)
  let bindingIdentityEpoch = 0
  let bindingReadOwner: symbol | null = null
  function readBindingJournal(): void {
    try {
      const raw = sessionStorage.getItem(bindingJournalStorageKey)
      const parsed: unknown = raw === null ? [] : JSON.parse(raw)
      if (!Array.isArray(parsed) || !parsed.every(isBindingJournalReference) ||
        new Set(parsed.map(item => item.request_key)).size !== parsed.length) throw new Error('Invalid binding journal')
      if (bindingJournal.value.some(known => !parsed.some(item => item.request_key === known.request_key &&
        JSON.stringify(item) === JSON.stringify(known)))) throw new Error('Unresolved binding journal was removed or changed')
      bindingJournal.value = parsed
      bindingJournalInvalid.value = false
    } catch { bindingJournalInvalid.value = true }
  }
  function isBindingJournalReference(value: unknown): value is BindingJournalReference {
    if (typeof value !== 'object' || value === null || Array.isArray(value)) return false
    const keys = Object.keys(value)
    return keys.length === 6 && ['user_id', 'environment_id', 'operation', 'request_key', 'input_sha256'].every(key => keys.includes(key)) &&
      'user_id' in value && typeof value.user_id === 'string' && /^[1-9][0-9]*$/.test(value.user_id) &&
      'environment_id' in value && typeof value.environment_id === 'number' && Number.isSafeInteger(value.environment_id) && value.environment_id > 0 &&
      'request_key' in value && typeof value.request_key === 'string' && value.request_key.length > 0 && value.request_key.length <= 128 && !/[\x00-\x1f\x7f]/.test(value.request_key) &&
      'input_sha256' in value && typeof value.input_sha256 === 'string' && /^[a-f0-9]{64}$/.test(value.input_sha256) &&
      'operation' in value && (value.operation === 'save' ? 'code' in value && typeof value.code === 'string' && /^[a-z][a-z0-9_-]{0,63}$/.test(value.code) :
        value.operation === 'disable' && 'channel_binding_id' in value && typeof value.channel_binding_id === 'number' && Number.isSafeInteger(value.channel_binding_id) && value.channel_binding_id > 0)
  }
  function persistBindingJournal(next: BindingJournalReference[]): boolean {
    try {
      const encoded = JSON.stringify(next)
      sessionStorage.setItem(bindingJournalStorageKey, encoded)
      if (sessionStorage.getItem(bindingJournalStorageKey) !== encoded) throw new Error('Binding journal not retained')
      bindingJournal.value = next
      bindingJournalInvalid.value = false
      return true
    } catch {
      bindingJournalInvalid.value = true
      error.value = '原请求记录无法安全保存；暂不能提交或解除原请求锁。'
      return false
    }
  }
  function bindingOperationQuery(item: BindingJournalReference): ChannelBindingOperationReference {
    const owner = { operation: item.operation, environment_id: item.environment_id,
      request_key: item.request_key, input_sha256: item.input_sha256 }
    return item.operation === 'save' ? { ...owner, operation: 'save', code: item.code } :
      { ...owner, operation: 'disable', channel_binding_id: item.channel_binding_id }
  }
  function bindingIdentityCurrent(item: BindingJournalReference, identity: number, token: string): boolean {
    return bindingIdentityEpoch === identity && currentAdminId.value === item.user_id &&
      props.environment?.id === item.environment_id && userStore.accessToken === token && !!token &&
      bindingJournal.value.some(existing => existing.request_key === item.request_key && existing.user_id === item.user_id)
  }
  function completeBindingJournal(item: BindingJournalReference): boolean {
    return persistBindingJournal(bindingJournal.value.filter(existing => existing.request_key !== item.request_key))
  }
  const feishuWriting = ref(false)
  const feishuVisible = ref(false)
  const feishuBinding = ref<ChannelBindingRow | null>(null)
  const feishuError = ref('')
  const feishuForm = reactive({ encrypt_key: '', verification_token: '', workload_credential: '', app_id: '', app_secret: '' })
  const pendingFeishu = computed(() => currentCredentialRecoveries.value.find(item => item.platform === 'feishu'))
  const canSaveFeishu = computed(() => !!feishuBinding.value && !!currentAdminId.value && !!userStore.accessToken &&
    feishuBinding.value.environment_id === props.environment?.id && !feishuWriting.value && !credentialJournalInvalid.value &&
    !pendingFeishu.value && Object.values(feishuForm).every(value => value.trim() !== ''))
  const wecomWriting = ref(false)
  const wecomVisible = ref(false)
  const wecomBinding = ref<ChannelBindingRow | null>(null)
  const wecomError = ref('')
  const wecomForm = reactive({ token: '', encoding_aes_key: '', corp_id: '', agent_id: '', corp_secret: '', workload_credential: '' })
  const pendingWecom = computed(() => currentCredentialRecoveries.value.find(item => item.platform === 'wecom'))
  const canSaveWecom = computed(() => !!wecomBinding.value && !!currentAdminId.value && !!userStore.accessToken &&
    wecomBinding.value.environment_id === props.environment?.id && !wecomWriting.value && !credentialJournalInvalid.value &&
    !pendingWecom.value && Object.values(wecomForm).every(value => value.trim() !== ''))
  type CredentialJournalReference = ChannelCredentialSaveReference & { readonly user_id: string }
  const credentialJournalKey = 'sand-ai-channel-credential-save-journal:v1'
  const credentialJournal = ref<CredentialJournalReference[]>([])
  const credentialJournalInvalid = ref(false)
  const credentialRecoveringKey = ref('')
  const credentialRecoveryError = ref('')
  const currentCredentialRecoveries = computed(() => credentialJournal.value.filter(item => item.user_id === currentAdminId.value &&
    item.environment_id === props.environment?.id))
  const otherCredentialRecoveryCount = computed(() => credentialJournal.value.length - currentCredentialRecoveries.value.length)
  let credentialIdentityEpoch = 0
  let credentialReadOwner: symbol | null = null
  const credentialWriteOwners: Record<ChannelCredentialPlatform, symbol | null> = { feishu: null, wecom: null }
  // Secrets exist only while preparing this explicit save; recovery keeps metadata only.
  const credentialBodies = new Map<symbol,
    { platform: 'feishu'; draft: FeishuCredentialDraft } | { platform: 'wecom'; draft: WecomCredentialDraft }>()
  function readCredentialJournal(): void {
    const wasInvalid = credentialJournalInvalid.value
    try {
      const raw: unknown = JSON.parse(sessionStorage.getItem(credentialJournalKey) || '[]')
      if (!Array.isArray(raw) || raw.length > 100) throw new Error('invalid credential journal')
      const entries: CredentialJournalReference[] = []
      for (const value of raw) {
        if (typeof value !== 'object' || value === null || Array.isArray(value)) throw new Error('invalid credential reference')
        const item = value as Record<string, unknown>
        if (Object.keys(item).length !== 7 || !Object.keys(item).every(key =>
          ['platform', 'user_id', 'environment_id', 'channel_binding_id', 'code', 'request_key', 'input_sha256'].includes(key)) ||
          !['feishu', 'wecom'].includes(String(item.platform)) || typeof item.user_id !== 'string' || !/^[1-9][0-9]{0,18}$/.test(item.user_id) ||
          typeof item.environment_id !== 'number' || !Number.isSafeInteger(item.environment_id) || item.environment_id <= 0 ||
          typeof item.channel_binding_id !== 'number' || !Number.isSafeInteger(item.channel_binding_id) || item.channel_binding_id <= 0 ||
          typeof item.code !== 'string' || !/^[a-z][a-z0-9_-]{0,63}$/.test(item.code) ||
          typeof item.request_key !== 'string' || !/^[\x21-\x7e]{1,128}$/.test(item.request_key) ||
          typeof item.input_sha256 !== 'string' || !/^[a-f0-9]{64}$/.test(item.input_sha256)) throw new Error('invalid credential reference')
        const platform = item.platform === 'feishu' ? 'feishu' : 'wecom'
        if (entries.some(prior => prior.platform === platform && prior.environment_id === item.environment_id && prior.request_key === item.request_key))
          throw new Error('duplicate credential reference')
        entries.push(Object.freeze({ platform, user_id: item.user_id, environment_id: item.environment_id,
          channel_binding_id: item.channel_binding_id, code: item.code, request_key: item.request_key, input_sha256: item.input_sha256 }))
      }
      credentialJournal.value = entries
      credentialJournalInvalid.value = false
      if (wasInvalid) credentialRecoveryError.value = ''
    } catch {
      credentialJournalInvalid.value = true
      credentialRecoveryError.value = '原渠道配置记录无法完整读取，不能视为没有待确认保存。暂勿再次替换凭据。'
    }
  }
  function persistCredentialJournal(entries: CredentialJournalReference[]): boolean {
    try {
      const json = JSON.stringify(entries)
      sessionStorage.setItem(credentialJournalKey, json)
      if (sessionStorage.getItem(credentialJournalKey) !== json) throw new Error('credential reference not saved')
      credentialJournal.value = entries
      credentialJournalInvalid.value = false
      return true
    } catch {
      credentialJournalInvalid.value = true
      credentialRecoveryError.value = '原渠道配置记录未能安全保存或更新，保留原请求，暂勿再次替换凭据。'
      return false
    }
  }
  function credentialReferenceCurrent(reference: CredentialJournalReference, identity: number, token: string): boolean {
    return identity === credentialIdentityEpoch && token === userStore.accessToken && !!token &&
      reference.user_id === currentAdminId.value && reference.environment_id === props.environment?.id &&
      credentialJournal.value.some(item => item.platform === reference.platform && item.user_id === reference.user_id &&
        item.environment_id === reference.environment_id && item.request_key === reference.request_key &&
        item.channel_binding_id === reference.channel_binding_id && item.code === reference.code && item.input_sha256 === reference.input_sha256)
  }
  function credentialBodyDigest(owner: symbol): Promise<string> {
    const body = credentialBodies.get(owner)
    if (!body) throw new Error('当前身份已变化，本次配置不再提交')
    return channelCredentialInputSha256(body.draft)
  }
  function sendCredentialBody(owner: symbol, key: string): Promise<ChannelCredentialSaveReceipt> {
    const body = credentialBodies.get(owner)
    if (!body) throw new Error('当前身份已变化，本次配置不再提交')
    try {
      return body.platform === 'feishu' ? channelBindingApi.saveFeishuCredentials(body.draft, key) :
        channelBindingApi.saveWecomCredentials(body.draft, key)
    } finally { credentialBodies.delete(owner) }
  }
  async function confirmCredentialSave(reference: CredentialJournalReference): Promise<void> {
    if (credentialRecoveringKey.value || credentialJournalInvalid.value || reference.user_id !== currentAdminId.value ||
      reference.environment_id !== props.environment?.id || !userStore.accessToken) return
    const identity = credentialIdentityEpoch
    const token = userStore.accessToken
    const owner = Symbol('credential-save-read')
    credentialReadOwner = owner
    const isCurrent = () => credentialReadOwner === owner && credentialReferenceCurrent(reference, identity, token)
    credentialRecoveringKey.value = reference.request_key
    credentialRecoveryError.value = ''
    try {
      const result = await channelBindingApi.readCredentialOperation(reference, reference.user_id)
      if (!isCurrent()) return
      if (result.state !== 'completed') {
        credentialRecoveryError.value = '原配置保存仍未获得完整证明。保留原请求，只读找回；未找到不表示没有保存。'
        return
      }
      if (!persistCredentialJournal(credentialJournal.value.filter(item => !(item.platform === reference.platform &&
        item.environment_id === reference.environment_id && item.user_id === reference.user_id && item.request_key === reference.request_key)))) return
      if (reference.platform === 'feishu' && feishuBinding.value?.channel_binding_id === reference.channel_binding_id) {
        feishuVisible.value = false; closeFeishu()
      }
      if (reference.platform === 'wecom' && wecomBinding.value?.channel_binding_id === reference.channel_binding_id) {
        wecomVisible.value = false; closeWecom()
      }
      ElMessage.success('原渠道配置保存已确认；当前配置状态以重新读取的渠道记录为准')
      await load(page.value)
    } catch (failure) {
      if (isCurrent()) credentialRecoveryError.value = `${failure instanceof Error ? failure.message : '原渠道配置结果尚未确认'}；保留原请求，只读找回。`
    } finally {
      if (identity === credentialIdentityEpoch && token === userStore.accessToken && credentialReadOwner === owner) {
        credentialReadOwner = null
        credentialRecoveringKey.value = ''
      }
    }
  }
  const selectedBinding = ref<ChannelBindingRow | null>(null)
  const deliveries = ref<ChannelDeliveryRow[]>([])
  const wecomDeliveries = ref<ChannelDeliveryRow[]>([])
  const wecomDeliveryPage = ref(1)
  const wecomDeliveryTotal = ref(0)
  const wecomDeliveryLoading = ref(false)
  const wecomDeliveryError = ref('')
  const deliveryPage = ref(1)
  const deliveryTotal = ref(0)
  const deliveryLoading = ref(false)
  const channelStatus = ref<FeishuChannelStatus | null>(null)
  const wecomStatus = ref<WecomChannelStatus | null>(null)
  const wecomStatusLoading = ref(false)
  const wecomStatusError = ref('')
  const statusLoading = ref(false)
  const statusError = ref('')
  const deliveryError = ref('')
  const retryingTaskId = ref<number | null>(null)
  const previewBinding = ref<ChannelBindingRow | null>(null)
  const previewApiKey = ref('')
  const previewPlatform = ref<'' | 'feishu' | 'wecom'>('')
  const previewDeliveryPlatform = computed(() => previewPlatform.value || undefined)
  const platformMessageId = ref('')
  let restoringPreview = false
  const previewConversationId = ref('')
  const previewSenderId = ref('')
  const previewQuery = ref('')
  const previewMessages = ref<ChannelMessage[]>([])
  type PreviewConnectionState = 'connecting' | 'reading' | 'disconnected' | 'paused' | 'ended'
  type PreviewStreamView = { transport: PreviewConnectionState; lastEvent: number; rounds: Record<number, string>; sourceEvents: Record<number, number>; status: string; error: string }
  const previewStreamViews = ref<Record<number, PreviewStreamView>>({})
  const previewStreamTaskId = ref<number | null>(null)
  let previewStreamAbort: AbortController | null = null
  const previewSourceVisible = ref(false)
  const previewSource = ref<SandAiChannelSource | null>(null)
  const previewSourceLoading = ref(false)
  const previewSourceError = ref('')
  let previewSourceEpoch = 0
  function clearPreviewSource(): void {
    ++previewSourceEpoch
    previewSource.value = null
    previewSourceVisible.value = false
    previewSourceLoading.value = false
    previewSourceError.value = ''
  }
  function sourcePosition(source: SandAiSourceReference): string {
    const locator = source.locator
    const positive = (value: unknown): value is number => typeof value === 'number' && Number.isSafeInteger(value) && value > 0
    if (source.locator_type === 'line' && positive(locator.line)) return `第 ${locator.line} 行`
    if (positive(locator.page)) return `第 ${locator.page} 页`
    if (source.locator_type === 'table_row' && positive(locator.row)) {
      return `${typeof locator.sheet === 'string' ? `工作表「${locator.sheet}」· ` : ''}第 ${locator.row} 行`
    }
    return '来源位置尚无可展示说明'
  }
  async function openPreviewSource(taskId: number, blockId: number, eventNo?: number): Promise<void> {
    const binding = previewBinding.value
    const apiKey = previewApiKey.value.trim()
    const conversationId = previewConversationId.value.trim()
    const senderId = previewSenderId.value.trim()
    const platform = previewDeliveryPlatform.value
    if (!validPreviewBinding(binding) || !apiKey || previewSourceLoading.value
      || !previewMessages.value.some(message => message.task.id === taskId)) return
    clearPreviewSource()
    const current = previewSourceEpoch
    const epoch = previewEpoch
    previewSourceVisible.value = true
    previewSourceLoading.value = true
    try {
      const result = await channelBindingApi.source(apiKey, binding.code, taskId, blockId, conversationId, senderId, eventNo, platform)
      if (current === previewSourceEpoch && epoch === previewEpoch) previewSource.value = result
    } catch (failure) {
      if (current === previewSourceEpoch && epoch === previewEpoch) {
        previewSource.value = null
        try {
          if (!Number.isSafeInteger(taskId) || taskId < 1 || taskId >= Number.MAX_SAFE_INTEGER) throw new Error('原消息定位不可用')
          const page = await channelBindingApi.conversation(apiKey, binding.code, conversationId, senderId, taskId + 1, platform)
          if (current !== previewSourceEpoch || epoch !== previewEpoch || apiKey !== previewApiKey.value.trim()) return
          const message = page.messages.find(item => item.task.id === taskId)
          if (!message) throw new Error('原消息不在当前会话中')
          updatePreviewTask(message.task)
          // Costs require their own current read; a readable answer does not authorize old cost snapshots.
          const costs = { ...previewCosts.value }
          const errors = { ...previewCostErrors.value }
          for (const invocation of previewInvocations(message.task)) {
            delete costs[invocation.request_id]
            errors[invocation.request_id] = '请按原请求号重新读取当前费用'
          }
          previewCosts.value = costs
          previewCostErrors.value = errors
        } catch {
          if (current !== previewSourceEpoch || epoch !== previewEpoch) return
          clearDeniedPreview()
          previewError.value = '未能确认当前身份仍可读取原会话，私有视图已清除。请沿原消息标识只读找回，不会重新调用模型。'
          return
        }
        const code = failure instanceof SandAiRuntimeError && /^[A-Z0-9_]+$/.test(failure.errorCode ?? '') ? `（${failure.errorCode}）` : ''
        previewSourceError.value = `该来源当前不可读取，可能已撤销、清理或不在当前读取权限内。原消息已重新核对，可返回查看；费用请独立按原请求号读取，不需要重新发送消息。${code}`
      }
    } finally {
      if (current === previewSourceEpoch) previewSourceLoading.value = false
    }
  }
  const previewCosts = ref<Record<string, SandAiInvocationCost>>({})
  const previewCostErrors = ref<Record<string, string>>({})
  const previewCostLoading = ref<number | null>(null)
  const previewLoadingFlag = ref(false)
  const messageFlight = ref<symbol | null>(null)
  const previewLoading = computed(() => previewLoadingFlag.value || messageFlight.value !== null)
  const previewError = ref('')
  const previewClosingFlag = ref(false)
  const closeFlight = ref<symbol | null>(null)
  const previewClosing = computed(() => previewClosingFlag.value || closeFlight.value !== null)
  // Server reads revalidate the current caller; browser recovery stores only business identifiers.
  const pendingCloses = reactive(new Map<string, string>())
  const unsentPreviewCloses = reactive(new Set<string>())
  type PreviewRequestReceipt = { messageId: string; key: string; taskId?: number }
  type PendingPreviewRequest = PreviewRequestReceipt & { query: string; dispatched?: boolean }
  const pendingMessages = reactive(new Map<string, PendingPreviewRequest>())
  const unsentPreviewMessages = reactive(new Map<string, { key: string; messageId: string; query: string; credential: string; admin: number; adminToken: string }>())
  const previewRequests = reactive(new Map<string, PreviewRequestReceipt>())
  const previewRecoveryMemory = new Map<string, string>()
  const invalidPreviewRecoveries = reactive(new Set<string>())
  const previewRecoveryInvalid = computed(() => !!previewBinding.value && invalidPreviewRecoveries.has(recoveryStorageKey(previewBinding.value)))
  const previewScope = computed(() => JSON.stringify({
    environmentId: props.environment?.id,
    bindingId: previewBinding.value?.channel_binding_id,
    code: previewBinding.value?.code,
    revision: previewBinding.value?.revision,
      status: previewBinding.value?.status,
      applicationId: previewBinding.value?.application_id,
      workloadClientId: previewBinding.value?.workload_client_id,
    conversationId: previewConversationId.value.trim(),
    senderId: previewSenderId.value.trim(),
    ...(previewDeliveryPlatform.value ? { deliveryPlatform: previewDeliveryPlatform.value } : {})
  }))
  type ReceiptRecoveryIntent = { key: string; key_sha256: string; task_id: number; input: ChannelReceiptRecoveryInput }
  const receiptRecoveryIntents = ref<ReceiptRecoveryIntent[]>([])
  const receiptRecoveryInvalid = ref(false)
  const receiptRecoveryErrors = ref<Record<number, string>>({})
  const receiptRecoveryBusy = ref<number | null>(null)
  let receiptRecoveryFlight: symbol | null = null
  const receiptRecoveryStorageKey = computed(() => `sand-ai-channel-receipt-recovery:v1:${currentAdminId.value}:${previewScope.value}`)
  function receiptProjection(task: ChannelReceiptRecoveryTask) {
    return isChannelReceiptRecoveryProjection(task.channel_receipt_recovery) ? task.channel_receipt_recovery : null
  }
  function receiptRecoveryStatus(task: ChannelReceiptRecoveryTask): string {
    switch (receiptProjection(task)?.reason) {
      case 'receipt_unavailable':
        return '原调用结果尚未找回，不能确认成功或失败；为避免重复调用，本次不会重新发送。'
      case 'local_recovery_exhausted':
        return '原结果的恢复次数已用完，不能再次恢复；这不代表模型预算已用完。'
      case 'source_unavailable':
        return '原任务当前不可用，不能恢复原结果。'
      case 'source_or_snapshot_unavailable':
        return '原来源或任务版本尚不可确认，当前不能恢复原结果。'
      case 'checkpoint_unavailable':
        return '原任务的恢复记录尚不可确认，不能恢复原结果。'
      case 'lease_active':
        return '原任务仍在处理中，请刷新会话确认当前状态。'
      case 'intent_accepted':
        return '原结果恢复已受理，请刷新会话确认当前结果。'
      case 'task_not_recoverable':
        return '原任务当前不适用原结果恢复，请以任务状态和已有结果为准。'
      default:
        return '原结果的恢复条件尚不可确认，请刷新会话读取当前状态；不会重新发送消息。'
    }
  }
  function receiptIntent(taskId: number): ReceiptRecoveryIntent | undefined {
    return receiptRecoveryIntents.value.find(item => item.task_id === taskId)
  }
  function readReceiptRecoveryIntents(): void {
    receiptRecoveryIntents.value = []
    receiptRecoveryInvalid.value = false
    try {
      const raw = localStorage.getItem(receiptRecoveryStorageKey.value)
      if (raw === null) return
      const value: unknown = JSON.parse(raw)
      const record = (item: unknown): item is Record<string, unknown> => typeof item === 'object' && item !== null && !Array.isArray(item)
      const positive = (item: unknown): item is number => typeof item === 'number' && Number.isSafeInteger(item) && item > 0
      const intents: ReceiptRecoveryIntent[] = []
      if (!Array.isArray(value)) throw new Error('恢复记录格式不可确认')
      for (const item of value) {
        if (!record(item) || !record(item.input) || typeof item.key !== 'string' || !/^[A-Za-z0-9._:-]{1,128}$/.test(item.key) ||
          typeof item.key_sha256 !== 'string' || !/^[a-f0-9]{64}$/.test(item.key_sha256) || !positive(item.task_id) ||
          !positive(item.input.expected_task_version) || typeof item.input.expected_lease_fence !== 'number' ||
          !Number.isSafeInteger(item.input.expected_lease_fence) || item.input.expected_lease_fence < 0 ||
          item.input.conversation_id !== previewConversationId.value.trim() || item.input.sender_id !== previewSenderId.value.trim() ||
          item.input.delivery_platform !== previewDeliveryPlatform.value || intents.some(existing => existing.task_id === item.task_id))
          throw new Error('原恢复记录与当前会话不一致')
        intents.push({ key: item.key, key_sha256: item.key_sha256, task_id: item.task_id, input: {
          conversation_id: previewConversationId.value.trim(), sender_id: previewSenderId.value.trim(),
          expected_task_version: item.input.expected_task_version, expected_lease_fence: item.input.expected_lease_fence,
          ...(previewDeliveryPlatform.value ? { delivery_platform: previewDeliveryPlatform.value } : {}) } })
      }
      receiptRecoveryIntents.value = intents
    } catch { receiptRecoveryInvalid.value = true }
  }
  function persistReceiptRecoveryIntents(items: ReceiptRecoveryIntent[]): boolean {
    try {
      const encoded = JSON.stringify(items)
      localStorage.setItem(receiptRecoveryStorageKey.value, encoded)
      if (localStorage.getItem(receiptRecoveryStorageKey.value) !== encoded) throw new Error('保存未确认')
      receiptRecoveryIntents.value = items
      return true
    } catch {
      receiptRecoveryInvalid.value = true
      return false
    }
  }
  function canRecoverReceipt(task: ChannelReceiptRecoveryTask): boolean {
    return !!receiptProjection(task)?.available && validPreviewBinding(previewBinding.value) && !!previewApiKey.value.trim() &&
      !!previewConversationId.value.trim() && !!previewSenderId.value.trim() && !previewLoading.value && !previewClosing.value &&
      receiptRecoveryBusy.value === null && !receiptRecoveryInvalid.value && !receiptIntent(task.id) && deliveryRetryLockAvailable
  }
  function receiptRecoveryFailure(failure: unknown): string {
    if (!(failure instanceof SandAiRuntimeError)) return failure instanceof Error ? failure.message : '恢复结果未确认'
    const code = failure.errorCode && /^[A-Z0-9_]+$/.test(failure.errorCode) ? `（${failure.errorCode}）` : ''
    if ([401, 403].includes(failure.httpStatus) || [401, 403].includes(failure.businessCode))
      return `当前身份没有恢复或读取原结果的权限，请核对当前应用身份及授权${code}`
    const message = failure.errorCode === 'SAND_AI_RESOURCE_CONFLICT' ? '原任务状态或版本已变化，请刷新会话；此次意图仍须沿原号确认' :
      failure.errorCode === 'SAND_AI_DISPATCH_AMBIGUOUS' ? '原完成回执尚不可确认，不能恢复或重新生成' :
      failure.errorCode === 'SAND_AI_RESOURCE_NOT_FOUND' ? '当前范围未找到原任务或此次恢复收据，不能据此认定未提交' :
      failure.errorCode === 'SAND_AI_SCOPE_SNAPSHOT_INVALID' ? '原来源或恢复依据不可确认，不能恢复原结果' : failure.message
    return `${message}${code}`
  }
  async function receiptIntentHash(key: string): Promise<string> {
    const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(key))
    return Array.from(new Uint8Array(digest), byte => byte.toString(16).padStart(2, '0')).join('')
  }
  async function findReceiptRecovery(taskId: number): Promise<void> {
    readReceiptRecoveryIntents()
    const original = receiptIntent(taskId), binding = previewBinding.value
    const apiKey = previewApiKey.value.trim(), scope = receiptRecoveryStorageKey.value, epoch = previewEpoch
    if (!original || !validPreviewBinding(binding) || !apiKey || receiptRecoveryBusy.value !== null) return
    const current = () => epoch === previewEpoch && scope === receiptRecoveryStorageKey.value && apiKey === previewApiKey.value.trim()
    const flight = Symbol('receipt-recovery-read')
    receiptRecoveryFlight = flight
    receiptRecoveryBusy.value = taskId
    receiptRecoveryErrors.value = { ...receiptRecoveryErrors.value, [taskId]: '' }
    try {
      const receipt = await channelBindingApi.receiptRecoveryByRequestKey(apiKey, binding.code, taskId, original.input, original.key)
      if (!current()) return
      const hash = await receiptIntentHash(original.key)
      if (!current()) return
      if (receipt.intent_key_sha256 !== hash || hash !== original.key_sha256)
        throw new Error('恢复收据与原授权请求号不一致')
      const acknowledge = () => {
        if (!current()) return false
        readReceiptRecoveryIntents()
        const saved = receiptIntent(taskId)
        if (receiptRecoveryInvalid.value || !saved || JSON.stringify(saved) !== JSON.stringify(original)) return false
        return persistReceiptRecoveryIntents(receiptRecoveryIntents.value.filter(item => item.task_id !== taskId))
      }
      const confirmed = deliveryRetryLockAvailable
        ? await navigator.locks.request(scope, { mode: 'exclusive' }, acknowledge) : acknowledge()
      if (!current()) return
      if (!confirmed) throw new Error('原恢复收据已找到，但本地记录尚未确认保存')
      previewMessages.value = previewMessages.value.map(message => message.task.id === taskId
        ? { ...message, task: { ...message.task, channel_receipt_recovery: null } } : message)
      receiptRecoveryErrors.value = { ...receiptRecoveryErrors.value, [taskId]: '原结果恢复已受理，等待刷新原会话确认当前结果。' }
      ElMessage.success('原结果恢复已受理，当前结果以原会话读取为准')
      await loadPreviewMessages()
    } catch (failure) {
      if (current()) receiptRecoveryErrors.value = { ...receiptRecoveryErrors.value, [taskId]:
        `${receiptRecoveryFailure(failure)}；保留原授权请求号，仅只读找回。` }
    } finally { if (receiptRecoveryFlight === flight) { receiptRecoveryFlight = null; receiptRecoveryBusy.value = null } }
  }
  async function recoverOriginalReceipt(task: ChannelReceiptRecoveryTask): Promise<void> {
    readReceiptRecoveryIntents()
    const projection = receiptProjection(task), binding = previewBinding.value
    if (!projection || !canRecoverReceipt(task) || !binding) return
    const apiKey = previewApiKey.value.trim(), scope = receiptRecoveryStorageKey.value, epoch = previewEpoch
    const current = () => epoch === previewEpoch && scope === receiptRecoveryStorageKey.value && apiKey === previewApiKey.value.trim()
    const flight = Symbol('receipt-recovery-submit')
    receiptRecoveryFlight = flight
    receiptRecoveryBusy.value = task.id
    let original: ReceiptRecoveryIntent | null = null
    try {
      try { await ElMessageBox.confirm('使用当前身份恢复这条消息已经完成的原结果；不会重新生成或执行工具。', '恢复原结果', { type: 'warning' }) }
      catch { return }
      if (!current()) return
      const key = newRuntimeRequestId('channel-receipt-recovery')
      const keyHash = await receiptIntentHash(key)
      if (!current()) return
      original = await navigator.locks.request(scope, { mode: 'exclusive' }, (): ReceiptRecoveryIntent | null => {
        if (!current()) return null
        readReceiptRecoveryIntents()
        if (receiptRecoveryInvalid.value || receiptIntent(task.id)) return null
        const intent: ReceiptRecoveryIntent = { key, key_sha256: keyHash, task_id: task.id, input: {
          conversation_id: previewConversationId.value.trim(), sender_id: previewSenderId.value.trim(),
          expected_task_version: projection.expected_task_version, expected_lease_fence: projection.expected_lease_fence,
          ...(previewDeliveryPlatform.value ? { delivery_platform: previewDeliveryPlatform.value } : {}) } }
        return persistReceiptRecoveryIntents([...receiptRecoveryIntents.value, intent]) ? intent : null
      })
      if (!original || !current()) return
      // Durable original intent precedes the single POST; every outcome is confirmed by its dedicated read.
      await channelBindingApi.recoverReceipt(apiKey, binding.code, task.id, original.input, original.key)
    } catch (failure) {
      if (current()) receiptRecoveryErrors.value = { ...receiptRecoveryErrors.value, [task.id]:
        `${receiptRecoveryFailure(failure)}；保留原授权请求号，不重复提交。` }
    } finally {
      if (receiptRecoveryFlight === flight) { receiptRecoveryFlight = null; receiptRecoveryBusy.value = null }
    }
    if (original && current()) await findReceiptRecovery(task.id)
  }
  const pendingCloseKey = computed(() => pendingCloses.get(previewScope.value))
  const pendingCloseUnsent = computed(() => unsentPreviewCloses.has(previewScope.value))
  const pendingPreview = computed(() => pendingMessages.get(previewScope.value))
  const pendingPreviewUnsent = computed(() => canSendUnsentPreviewMessage(previewScope.value))
  const lastPreviewRequest = computed(() => previewRequests.get(previewScope.value))
  const previewClosedAfterTaskId = ref(0)
  const previewNextBeforeTaskId = ref<number | null>(null)
  const previewHistoryPageLoaded = ref(false)
  let previewEpoch = 0
  const error = ref('')
  const editingId = ref<number | null>(null)
  let epoch = 0
  let directoryEpoch = 0
  let deliveryEpoch = 0
  let wecomDeliveryEpoch = 0
  let statusEpoch = 0
  let wecomStatusEpoch = 0
  let deliverySelectionEpoch = 0
  let deliveryRetryOwner: { selectionEpoch: number } | null = null
  const form = reactive({ code: '', name: '', agent_definition_id: null as number | null, workload_client_id: '' })
  function isPublishedAgent(agent: AgentDefinitionRow): boolean {
    return agent.state === 'published' && agent.status === 1 &&
      typeof agent.active_version_id === 'number' && Number.isInteger(agent.active_version_id) && agent.active_version_id > 0 &&
      typeof agent.active_version_no === 'number' && Number.isInteger(agent.active_version_no) && agent.active_version_no > 0
  }
  const selectedAgent = computed(() => agents.value.find(agent => agent.agent_definition_id === form.agent_definition_id && isPublishedAgent(agent)))
  const selectedClient = computed(() => clients.value.find(client => String(client.id) === form.workload_client_id &&
    client.environment_id === props.environment?.id && client.status === 1))
  const canSave = computed(() => !pendingBindingMutation.value && !!currentAdminId.value && !!props.environment &&
    !!form.code.trim() && !!form.name.trim() && !!selectedAgent.value && !!selectedClient.value && !writing.value && !directoryLoading.value)

  function newBinding(): void { editingId.value = null; Object.assign(form, { code: '', name: '', agent_definition_id: null, workload_client_id: '' }) }
  function openFeishu(row: ChannelBindingRow): void {
    if (pendingFeishu.value && (pendingFeishu.value.user_id !== currentAdminId.value ||
      pendingFeishu.value.environment_id !== props.environment?.id ||
      pendingFeishu.value.channel_binding_id !== row.channel_binding_id)) {
      ElMessage.warning(`请使用原管理员身份回到应用环境 #${pendingFeishu.value.environment_id}，确认原渠道 #${pendingFeishu.value.channel_binding_id} 的飞书配置保存结果`)
      return
    }
    feishuBinding.value = row
    feishuError.value = ''
    Object.assign(feishuForm, { encrypt_key: '', verification_token: '', workload_credential: '', app_id: '', app_secret: '' })
    feishuVisible.value = true
  }
  function closeFeishu(): void {
    Object.assign(feishuForm, { encrypt_key: '', verification_token: '', workload_credential: '', app_id: '', app_secret: '' })
    if (!pendingFeishu.value) feishuBinding.value = null
  }
  async function saveCredentialConfiguration(platform: ChannelCredentialPlatform): Promise<void> {
    const binding = platform === 'feishu' ? feishuBinding.value : wecomBinding.value
    const environment = props.environment
    if (!binding || !environment || !(platform === 'feishu' ? canSaveFeishu.value : canSaveWecom.value)) return
    const identity = credentialIdentityEpoch
    const token = userStore.accessToken
    const owner = Symbol('credential-save')
    const original = Object.freeze({ platform, user_id: currentAdminId.value, environment_id: environment.id,
      channel_binding_id: binding.channel_binding_id, code: binding.code, request_key: newRuntimeRequestId(`${platform}-credential-save`) })
    credentialWriteOwners[platform] = owner
    const writing = platform === 'feishu' ? feishuWriting : wecomWriting
    const message = platform === 'feishu' ? feishuError : wecomError
    writing.value = true
    message.value = ''
    const isCurrent = () => identity === credentialIdentityEpoch && token === userStore.accessToken &&
      original.user_id === currentAdminId.value && original.environment_id === props.environment?.id && credentialWriteOwners[platform] === owner
    if (platform === 'feishu') {
      credentialBodies.set(owner, { platform, draft: Object.freeze({ environment_id: original.environment_id,
        channel_binding_id: original.channel_binding_id, encrypt_key: feishuForm.encrypt_key,
        verification_token: feishuForm.verification_token, workload_credential: feishuForm.workload_credential,
        app_id: feishuForm.app_id, app_secret: feishuForm.app_secret }) })
      Object.assign(feishuForm, { encrypt_key: '', verification_token: '', workload_credential: '', app_id: '', app_secret: '' })
    } else {
      credentialBodies.set(owner, { platform, draft: Object.freeze({ environment_id: original.environment_id,
        channel_binding_id: original.channel_binding_id, token: wecomForm.token, encoding_aes_key: wecomForm.encoding_aes_key,
        corp_id: wecomForm.corp_id, agent_id: wecomForm.agent_id, corp_secret: wecomForm.corp_secret,
        workload_credential: wecomForm.workload_credential }) })
      Object.assign(wecomForm, { token: '', encoding_aes_key: '', corp_id: '', agent_id: '', corp_secret: '', workload_credential: '' })
    }
    let reference: CredentialJournalReference | null = null
    let sent = false
    try {
      const input_sha256 = await credentialBodyDigest(owner)
      if (!isCurrent()) return
      reference = Object.freeze({ ...original, input_sha256 })
      readCredentialJournal()
      if (credentialJournalInvalid.value || currentCredentialRecoveries.value.some(item => item.platform === platform) ||
        !persistCredentialJournal([...credentialJournal.value, reference])) return
      sent = true
      const receipt = await sendCredentialBody(owner, original.request_key)
      if (!isCurrent() || !credentialReferenceCurrent(reference, identity, token)) return
      const configured = platform === 'feishu' ? 'feishu_configured' in receipt && receipt.feishu_configured === true :
        'wecom_configured' in receipt && receipt.wecom_configured === true
      if (receipt.channel_binding_id !== original.channel_binding_id || receipt.code !== original.code || !configured)
        throw new Error('配置保存回执与原渠道不一致')
      // The three-field POST receipt does not prove the original actor/digest; only the original GET can unlock it.
      await confirmCredentialSave(reference)
    } catch (failure) {
      if (isCurrent()) message.value = sent
        ? `${failure instanceof Error ? failure.message : '配置保存结果未知'}；已保留原请求，只读找回，不再次保存。`
        : '本次配置尚未提交；恢复记录或原输入摘要无法安全准备。请核对浏览器会话存储，暂勿再次替换凭据。'
    } finally {
      credentialBodies.delete(owner)
      if (isCurrent()) { credentialWriteOwners[platform] = null; writing.value = false }
    }
  }
  async function saveFeishu(): Promise<void> { await saveCredentialConfiguration('feishu') }
  function openWecom(row: ChannelBindingRow): void {
    if (pendingWecom.value && (pendingWecom.value.user_id !== currentAdminId.value ||
      pendingWecom.value.environment_id !== props.environment?.id ||
      pendingWecom.value.channel_binding_id !== row.channel_binding_id)) {
      ElMessage.warning(`请使用原管理员身份回到应用环境 #${pendingWecom.value.environment_id}，确认原渠道 #${pendingWecom.value.channel_binding_id} 的企业微信配置保存结果`)
      return
    }
    wecomBinding.value = row
    wecomError.value = ''
    Object.assign(wecomForm, { token: '', encoding_aes_key: '', corp_id: '', agent_id: '', corp_secret: '', workload_credential: '' })
    wecomVisible.value = true
  }
  function closeWecom(): void {
    Object.assign(wecomForm, { token: '', encoding_aes_key: '', corp_id: '', agent_id: '', corp_secret: '', workload_credential: '' })
    if (!pendingWecom.value) wecomBinding.value = null
  }
  async function saveWecom(): Promise<void> { await saveCredentialConfiguration('wecom') }
  async function load(targetPage: number): Promise<void> {
    const environment = props.environment
    if (!environment) return
    const current = ++epoch
    loading.value = true
    error.value = ''
    try {
      const result = await channelBindingApi.list(environment.id, targetPage)
      if (current !== epoch) return
      rows.value = result.data
      total.value = result.total
      page.value = targetPage
      if (selectedBinding.value) {
        const updated = result.data.find(item => item.channel_binding_id === selectedBinding.value?.channel_binding_id)
        if (updated) selectedBinding.value = updated
      }
      if (previewBinding.value) {
        const updated = result.data.find(
          (item) => item.channel_binding_id === previewBinding.value?.channel_binding_id
        )
        if (updated) {
          previewBinding.value = updated
          if (updated.status !== 1)
            previewError.value = '此绑定已停用，原消息标识已保留。请核对绑定状态后再读取。'
        }
      }
    } catch (failure) { if (current === epoch) error.value = failure instanceof Error ? failure.message : '渠道列表读取失败' }
    finally { if (current === epoch) loading.value = false }
  }
  async function loadAgents(): Promise<void> {
    const environment = props.environment
    if (!environment) return
    const current = epoch
    const directory = directoryEpoch
    try {
      const result = await agentDefinitionApi.list(environment.id, agentPage.value + 1, 100)
      if (current !== epoch || directory !== directoryEpoch) return
      agents.value.push(...result.data.filter(isPublishedAgent))
      agentPage.value += 1
      agentMore.value = agentPage.value * 100 < result.total
    } catch (failure) { if (current === epoch && directory === directoryEpoch) error.value = failure instanceof Error ? failure.message : '智能体列表读取失败' }
  }
  async function loadClients(): Promise<void> {
    const environment = props.environment
    if (!environment) return
    const current = epoch
    const directory = directoryEpoch
    try {
      const result = await iamApi.listClients({ application_id: environment.application_id, page: clientPage.value + 1, limit: 100 })
      if (current !== epoch || directory !== directoryEpoch) return
      clients.value.push(...result.data.filter(item => item.environment_id === environment.id && item.status === 1))
      clientPage.value += 1
      clientMore.value = clientPage.value * 100 < result.total
    } catch (failure) { if (current === epoch && directory === directoryEpoch) error.value = failure instanceof Error ? failure.message : '调用身份读取失败' }
  }
  async function refreshBindingDirectory(): Promise<void> {
    if (!props.environment || directoryLoading.value || writing.value || pendingBindingMutation.value) return
    const identity = bindingIdentityEpoch
    directoryLoading.value = true
    ++directoryEpoch
    agents.value = []; clients.value = []
    agentPage.value = 0; clientPage.value = 0; agentMore.value = false; clientMore.value = false
    try { await Promise.all([loadAgents(), loadClients()]) }
    finally { if (identity === bindingIdentityEpoch) directoryLoading.value = false }
  }
  function edit(row: ChannelBindingRow): void {
    editingId.value = row.channel_binding_id
    Object.assign(form, { code: row.code, name: row.name, agent_definition_id: row.agent_definition_id, workload_client_id: row.workload_client_id })
  }
  function deliveryState(state: ChannelDeliveryRow['state']): string {
    return { pending: '待发送', sending: '发送中', sent: '已送达', failed: '发送失败', uncertain: '结果待核实' }[state]
  }
  function invalidateDeliverySelection(): void {
    ++deliverySelectionEpoch
    ++deliveryEpoch; ++wecomDeliveryEpoch; ++statusEpoch; ++wecomStatusEpoch
    deliveryLoading.value = false; wecomDeliveryLoading.value = false
    statusLoading.value = false; wecomStatusLoading.value = false
    deliveryRetryOwner = null
    retryingTaskId.value = null
    deliveryRecoveringKey.value = ''
    deliveryRecoveryError.value = ''
  }
  function isCurrentDeliveryRetry(owner: { selectionEpoch: number }): boolean {
    return deliveryRetryOwner === owner && deliverySelectionEpoch === owner.selectionEpoch
  }
  function showDeliveries(row: ChannelBindingRow): void {
    invalidateDeliverySelection()
    selectedBinding.value = row
    deliveries.value = []
    deliveryTotal.value = 0
    wecomDeliveries.value = []
    wecomDeliveryTotal.value = 0
    channelStatus.value = null
    wecomStatus.value = null
    void loadStatus()
    void loadWecomStatus()
    void loadDeliveries(1)
    void loadWecomDeliveries(1)
  }
  function refreshChannelStatus(): void {
    void loadStatus()
    void loadWecomStatus()
    void loadDeliveries(1)
    void loadWecomDeliveries(1)
  }
  async function loadStatus(): Promise<void> {
    const environment = props.environment
    const binding = selectedBinding.value
    if (!environment || !binding) return
    const current = ++statusEpoch
    statusLoading.value = true
    statusError.value = ''
    try {
      const result = await channelBindingApi.feishuStatus(environment.id, binding.channel_binding_id)
      if (current === statusEpoch && selectedBinding.value?.channel_binding_id === result.channel_binding_id) channelStatus.value = result
    } catch (failure) {
      if (current === statusEpoch) statusError.value = failure instanceof Error ? failure.message : '渠道状态读取失败'
    } finally {
      if (current === statusEpoch) statusLoading.value = false
    }
  }
  async function loadWecomStatus(): Promise<void> {
    const environment = props.environment
    const binding = selectedBinding.value
    if (!environment || !binding) return
    const current = ++wecomStatusEpoch
    wecomStatusLoading.value = true
    wecomStatusError.value = ''
    try {
      const result = await channelBindingApi.wecomStatus(environment.id, binding.channel_binding_id)
      if (current === wecomStatusEpoch && selectedBinding.value?.channel_binding_id === result.channel_binding_id) {
        wecomStatus.value = result
      }
    } catch (failure) {
      if (current === wecomStatusEpoch) wecomStatusError.value = failure instanceof Error ? failure.message : '企业微信状态读取失败'
    } finally {
      if (current === wecomStatusEpoch) wecomStatusLoading.value = false
    }
  }
  function resetPreview(): void {
    clearPreviewSource()
    stopPreviewStream()
    ++previewEpoch
    previewBinding.value = null
    previewQuery.value = ''
    previewMessages.value = []
    previewClosedAfterTaskId.value = 0
    previewNextBeforeTaskId.value = null; previewHistoryPageLoaded.value = false
    previewClosingFlag.value = false
    previewCosts.value = {}
    previewCostErrors.value = {}
    previewCostLoading.value = null
    previewLoadingFlag.value = false
    previewError.value = ''
  }
  function recoveryStorageKey(binding: ChannelBindingRow): string {
    return `sand-ai-channel-recovery-v1:${JSON.stringify([
      binding.environment_id,
      binding.channel_binding_id,
      binding.revision,
      binding.application_id,
      binding.workload_client_id
    ])}${previewPlatform.value ? `:platform:${currentAdminId.value}:${previewPlatform.value}` : ''}`
  }
  function savePreviewRecovery(): boolean {
    const binding = previewBinding.value
    if (!binding || restoringPreview || !previewConversationId.value.trim() || !previewSenderId.value.trim() || previewRecoveryInvalid.value) return false
    const pending = pendingMessages.get(previewScope.value) ?? previewRequests.get(previewScope.value)
    const storageKey = recoveryStorageKey(binding)
    const encoded = JSON.stringify({
      conversationId: previewConversationId.value,
      senderId: previewSenderId.value,
      ...(previewPlatform.value ? { platformMessageId: platformMessageId.value } : {}),
      ...(pending ? { messageId: pending.messageId, key: pending.key,
        ...(pending.taskId ? { taskId: pending.taskId } : {}) } : {}),
      closeKey: pendingCloses.get(previewScope.value) ?? null
    })
    // Keep the same opaque identities in this page even when browser persistence fails.
    previewRecoveryMemory.set(storageKey, encoded)
    try {
      sessionStorage.setItem(storageKey, encoded)
      if (sessionStorage.getItem(storageKey) !== encoded) throw new Error('Recovery record was not retained')
      return true
    } catch {
      previewError.value = '此浏览器无法保存原请求标识。新发送或关闭请求不会提交；请保留当前页面，恢复保存能力后沿原标识重试。已提交请求仍可只读找回。'
      return false
    }
  }
  function restorePreviewRecovery(binding: ChannelBindingRow): 'absent' | 'restored' | 'blocked' {
    const storageKey = recoveryStorageKey(binding)
    let raw: string | null
    try {
      raw = sessionStorage.getItem(storageKey)
    } catch {
      invalidPreviewRecoveries.add(storageKey)
      raw = null
    }
    raw = previewRecoveryMemory.get(storageKey) ?? raw
    if (raw === null) return invalidPreviewRecoveries.has(storageKey) ? 'blocked' : 'absent'
    try {
      const stored: unknown = JSON.parse(raw)
      if (typeof stored !== 'object' || stored === null || Array.isArray(stored)) throw new Error('Invalid recovery record')
      const value = stored as Record<string, unknown>
      const validId = (id: unknown): id is string =>
        typeof id === 'string' && id.length > 0 && id === id.trim() && id.length <= 128 && !/[\p{Cc}\p{Cs}]/u.test(id)
      const validKey = (key: unknown): key is string =>
        typeof key === 'string' && /^[\x21-\x7e]{1,512}$/.test(key)
      const hasMessage = value.messageId !== undefined || value.key !== undefined || value.taskId !== undefined
      if (!validId(value.conversationId) || !validId(value.senderId)) throw new Error('Invalid conversation identities')
      // Valid conversation identities remain available for reads even if another field is damaged.
      previewConversationId.value = value.conversationId
      previewSenderId.value = value.senderId
      if (previewPlatform.value) platformMessageId.value = validId(value.platformMessageId) ? value.platformMessageId : ''
      const invalidIdentities = (hasMessage && (!validId(value.messageId) || !validKey(value.key)))
        || (value.taskId !== undefined && (typeof value.taskId !== 'number' || !Number.isSafeInteger(value.taskId) || value.taskId < 1))
        || (value.closeKey !== undefined && value.closeKey !== null && !validKey(value.closeKey))
      if (validId(value.messageId) && validKey(value.key)) {
        const receipt: PreviewRequestReceipt = { messageId: value.messageId, key: value.key }
        if (typeof value.taskId === 'number' && Number.isSafeInteger(value.taskId) && value.taskId > 0) receipt.taskId = value.taskId
        previewRequests.set(previewScope.value, receipt)
        // Do not replace this page's original body or known unsent state on reopening.
        if (!pendingMessages.has(previewScope.value)) pendingMessages.set(previewScope.value, { ...receipt, query: '' })
      }
      if (validKey(value.closeKey)) pendingCloses.set(previewScope.value, value.closeKey)
      if (invalidIdentities) invalidPreviewRecoveries.add(storageKey)
      return invalidPreviewRecoveries.has(storageKey) ? 'blocked' : 'restored'
    } catch {
      invalidPreviewRecoveries.add(storageKey)
      return 'blocked'
    }
  }
  function newPreviewConversation(): void {
    if (previewPlatform.value) return
    if (
      previewLoading.value ||
      previewClosing.value ||
      pendingPreview.value ||
      pendingCloseKey.value ||
      previewRecoveryInvalid.value
    )
      return
    stopPreviewStream()
    ++previewEpoch
    previewQuery.value = ''
    previewConversationId.value = newRuntimeRequestId('preview-conversation')
    previewMessages.value = []
    previewClosedAfterTaskId.value = 0
    previewNextBeforeTaskId.value = null; previewHistoryPageLoaded.value = false
    previewClosingFlag.value = false
    previewCosts.value = {}
    previewCostErrors.value = {}
    previewCostLoading.value = null
    previewLoadingFlag.value = false
    previewError.value = ''
    savePreviewRecovery()
  }
  function openPreview(row: ChannelBindingRow): void {
    if (
      previewLoading.value ||
      previewClosing.value ||
      row.status !== 1 ||
      row.environment_id !== props.environment?.id ||
      String(row.application_id) !== String(props.environment.application_id)
    )
      return
    resetPreview()
    previewPlatform.value = ''
    platformMessageId.value = ''
    previewBinding.value = row
    previewConversationId.value = ''
    previewSenderId.value = ''
    const recovery = restorePreviewRecovery(row)
    if (recovery === 'blocked') {
      previewError.value = '原会话恢复记录损坏或无法读取，无法确认此前请求。暂不能发送、关闭或新建会话；请保留原记录，已有有效标识仅用于只读找回。'
    } else if (recovery === 'absent') {
      previewSenderId.value = newRuntimeRequestId('preview-sender')
      newPreviewConversation()
    }
  }
  function changePreviewPlatform(): void {
    const binding = previewBinding.value
    if (!binding) return
    restoringPreview = true
    try {
      previewConversationId.value = ''
      previewSenderId.value = ''
      platformMessageId.value = ''
      const recovery = restorePreviewRecovery(binding)
      if (recovery === 'blocked') {
        previewError.value = '原会话恢复记录无法确认；已有有效标识仍可只读找回，不会重发消息。'
      } else if (recovery === 'absent' && !previewPlatform.value) {
        previewSenderId.value = newRuntimeRequestId('preview-sender')
        newPreviewConversation()
      }
    } finally { restoringPreview = false }
  }
  async function recoverPlatformMessage(): Promise<void> {
    const binding = previewBinding.value, platform = previewDeliveryPlatform.value
    const apiKey = previewApiKey.value.trim(), conversationId = previewConversationId.value.trim()
    const senderId = previewSenderId.value.trim(), messageId = platformMessageId.value.trim()
    if (!platform || !validPreviewBinding(binding) || !apiKey || !conversationId || !senderId
      || previewLoading.value || previewClosing.value) return
    if (!/^[\x21-\x7e]{1,128}$/.test(messageId)) {
      previewError.value = '请输入真实平台的原消息标识，不能用新生成的请求编号代替。'
      return
    }
    const current = ++previewEpoch
    previewLoadingFlag.value = true
    previewError.value = ''
    try {
      const original = await channelBindingApi.messageByRequestKey(apiKey, binding.code, messageId,
        conversationId, senderId, messageId, platform)
      if (current !== previewEpoch) return
      mergePreviewMessages([original])
      savePreviewRecovery()
      ElMessage.success(`已只读找回原任务 #${original.task.id}`)
      if (original.task.streaming_enabled) void startPreviewStream(original.task.id)
    } catch (failure) {
      if (current !== previewEpoch) return
      previewFailure(failure, '原消息目前不可读取，请核对原平台、会话和发送者标识及当前权限；不会重新发送消息。')
    } finally {
      if (current === previewEpoch) previewLoadingFlag.value = false
    }
  }
  function updatePreviewTask(task: ChannelReceiptRecoveryTask): void {
    previewMessages.value = previewMessages.value.map(message => message.task.id === task.id
      ? { ...message, task } : message)
  }
  function previewTaskState(state: string): string {
    return (
      (
        {
          queued: '排队中',
          running: '处理中',
          retrying: '重试中',
          succeeded: '已完成',
          failed: '失败',
          canceled: '已取消',
          recovery_pending: '结果待恢复',
          ambiguous: '结果待确认',
          authorization_pending: '等待授权',
          prepared: '待调用',
          dispatching: '调用中',
          not_dispatched: '未调用',
          pending: '等待处理'
        } as Record<string, string>
      )[state] ?? '状态待确认'
    )
  }
  function previewReadDenied(failure: unknown): boolean {
    return failure instanceof AgentReplayError ? isAgentReadDenied(failure.code, failure.status)
      : failure instanceof SandAiRuntimeError && isAgentReadDenied(failure.errorCode, failure.httpStatus)
  }
  function clearPendingPreviewBodies(): void {
    unsentPreviewMessages.clear()
    unsentPreviewCloses.clear()
    for (const [scope, pending] of pendingMessages) {
      pendingMessages.set(scope, { messageId: pending.messageId, key: pending.key, query: '', dispatched: pending.dispatched,
        ...(pending.taskId === undefined ? {} : { taskId: pending.taskId }) })
    }
  }
  function clearDeniedPreview(): void {
    // Invalidate first: old stream finally blocks and parallel history/cost reads cannot restore private data.
    ++previewEpoch
    stopPreviewStream()
    clearPreviewSource()
    previewQuery.value = ''
    previewMessages.value = []
    previewCosts.value = {}
    previewCostErrors.value = {}
    previewCostLoading.value = null
    previewClosedAfterTaskId.value = 0
    previewNextBeforeTaskId.value = null; previewHistoryPageLoaded.value = false
    previewLoadingFlag.value = false
    previewClosingFlag.value = false
    // Keep every opaque recovery identity and key; remove only the original private request body.
    clearPendingPreviewBodies()
    previewError.value = '当前身份或来源已无法读取此对话，旧回答、生成预览、摘要、来源和费用已清除。原消息与关闭请求标识仍保留；请核对当前权限后沿原标识找回，不会自动重发。'
  }
  function previewFailure(failure: unknown, fallback: string): void {
    if (previewReadDenied(failure)) { clearDeniedPreview(); return }
    clearPreviewSource()
    // An ordinary failed read does not revoke already read history, costs or this run's stream cursor.
    stopPreviewStream(false)
    previewCostLoading.value = null
    if (failure instanceof SandAiRuntimeError) {
      const unauthorized =
        [401, 403].includes(failure.httpStatus) ||
        [401, 403].includes(failure.businessCode) ||
        failure.errorCode === 'SAND_AI_ENVIRONMENT_MISMATCH'
      const code =
        failure.errorCode && /^[A-Z0-9_]+$/.test(failure.errorCode)
          ? `（${failure.errorCode}）`
          : ''
      previewError.value = `${unauthorized ? '当前身份无法读取此绑定。请核对应用身份与绑定后再找回；系统不会重发消息。' : fallback}${code}`
    } else previewError.value = failure instanceof Error ? failure.message : fallback
  }
  function validPreviewBinding(binding: ChannelBindingRow | null): binding is ChannelBindingRow {
    return (
      binding !== null &&
      binding.status === 1 &&
      binding.environment_id === props.environment?.id &&
      String(binding.application_id) === String(props.environment.application_id)
    )
  }
  function previewConnectionLabel(state: PreviewConnectionState): string {
    return { connecting: '正在连接原运行', reading: '正在读取', disconnected: '连接已断开',
      paused: '已暂停读取', ended: '连接已结束' }[state]
  }
  function pausePreviewStream(taskId: number): void {
    if (previewStreamTaskId.value !== taskId) return
    stopPreviewStream(false)
    const view = previewStreamViews.value[taskId]
    if (view) previewStreamViews.value = { ...previewStreamViews.value, [taskId]: { ...view, transport: 'paused',
      error: '', status: '已暂停本页实时读取；原任务继续处理，可沿原游标重连或只读找回原消息。' } }
  }
  function stopPreviewStream(clear = true): void {
    const active = previewStreamTaskId.value
    previewStreamAbort?.abort()
    if (!clear && active !== null && previewStreamViews.value[active]) {
      const view = previewStreamViews.value[active]
      previewStreamViews.value = { ...previewStreamViews.value, [active]: { ...view, transport: 'paused' } }
    }
    previewStreamAbort = null
    previewStreamTaskId.value = null
    if (clear) previewStreamViews.value = {}
  }
  async function startPreviewStream(taskId: number): Promise<void> {
    const binding = previewBinding.value
    const apiKey = previewApiKey.value.trim()
    const conversationId = previewConversationId.value.trim()
    const senderId = previewSenderId.value.trim()
    const platform = previewDeliveryPlatform.value
    const task = previewMessages.value.find(message => message.task.id === taskId)?.task
    if (!validPreviewBinding(binding) || !apiKey || !conversationId || !senderId || !task?.streaming_enabled) return
    stopPreviewStream(false)
    const controller = new AbortController()
    previewStreamAbort = controller
    previewStreamTaskId.value = taskId
    const epoch = previewEpoch
    const isCurrent = () => !controller.signal.aborted && epoch === previewEpoch
      && previewBinding.value?.channel_binding_id === binding.channel_binding_id
      && previewApiKey.value.trim() === apiKey && previewConversationId.value.trim() === conversationId
      && previewSenderId.value.trim() === senderId && previewDeliveryPlatform.value === platform
    const view: PreviewStreamView = previewStreamViews.value[taskId] ?? { transport: 'connecting', lastEvent: 0, rounds: {}, sourceEvents: {}, status: '只读读取原运行；连接状态与任务状态分别显示', error: '' }
    view.transport = 'connecting'
    view.error = ''
    let sawTerminal = false
    previewStreamViews.value = { ...previewStreamViews.value, [taskId]: view }
    try {
      await readChannelTokenStream({ apiKey, code: binding.code, taskId, conversationId, senderId, deliveryPlatform: platform,
        afterEventId: view.lastEvent, signal: controller.signal,
        onToken: event => {
          if (!isCurrent()) return
          const total = Object.values(view.rounds).reduce((count, text) => count + text.length, 0)
          if (total + event.text.length > 262144) throw new Error('生成预览已到读取上限，请读取原任务状态和费用，不要重新发送。')
          view.rounds[event.round] = (view.rounds[event.round] ?? '') + event.text
          view.lastEvent = event.event_no
          for (const id of event.source_block_ids) view.sourceEvents[id] ??= event.event_no
          view.transport = 'reading'
          view.status = '正在接收生成文本，完整回复尚未确认；最终结果以原任务记录为准'
          previewStreamViews.value = { ...previewStreamViews.value, [taskId]: { ...view, rounds: { ...view.rounds } } }
        },
        onTaskState: state => {
          if (!isCurrent()) return
          view.transport = 'reading'
          sawTerminal = ['succeeded', 'failed', 'canceled', 'recovery_pending'].includes(state)
          view.status = `事件报告原任务${previewTaskState(state)}；请只读找回原消息确认结果和费用`
          previewStreamViews.value = { ...previewStreamViews.value, [taskId]: { ...view, rounds: { ...view.rounds } } }
        }
      })
      if (isCurrent()) {
        view.transport = sawTerminal ? 'ended' : 'disconnected'
        view.error = sawTerminal ? '' : '连接已结束，不能据此判定原任务完成或失败；可沿原游标重连或只读找回原消息，不会重新调用模型。'
      }
    } catch (failure) {
      if (!isCurrent()) return
      if (previewReadDenied(failure)) { clearDeniedPreview(); return }
      // Network and other protocol failures retain this original task's cursor and preview for a read-only reconnect.
      view.transport = 'disconnected'
      view.error = failure instanceof Error ? failure.message : '读取生成中的回复已中断，请接续原任务。'
    } finally {
      if (isCurrent()) {
        previewStreamViews.value = { ...previewStreamViews.value, [taskId]: { ...view, rounds: { ...view.rounds } } }
        previewStreamTaskId.value = null
        previewStreamAbort = null
      }
    }
  }
  function mergePreviewMessages(messages: readonly ChannelMessage[]): void {
    const byTask = new Map(previewMessages.value.map(message => [message.task.id, message]))
    for (const message of messages) byTask.set(message.task.id, message)
    previewMessages.value = [...byTask.values()].sort((left, right) => right.task.id - left.task.id)
  }
  function applyPreviewPage(result: {
    messages: ChannelMessage[]; next_before_task_id: number | null; closed_after_task_id: number
  }, older = false): void {
    const oldestIncoming = result.messages.reduce((oldest, message) => Math.min(oldest, message.task.id), Infinity)
    const hasEarlierHistory = previewHistoryPageLoaded.value && result.messages.length > 0 &&
      previewMessages.value.some(message => message.task.id < oldestIncoming)
    if (!older && result.messages.length === 0) previewMessages.value = []
    else mergePreviewMessages(result.messages)
    previewClosedAfterTaskId.value = result.closed_after_task_id
    // Refresh the latest page without moving an already loaded older-history boundary.
    if (older || !hasEarlierHistory) previewNextBeforeTaskId.value = result.next_before_task_id
    previewHistoryPageLoaded.value = true
  }
  async function loadPreviewMessages(older = false): Promise<void> {
    const binding = previewBinding.value
    const apiKey = previewApiKey.value.trim()
    const conversationId = previewConversationId.value.trim()
    const senderId = previewSenderId.value.trim()
    if (
      !validPreviewBinding(binding) ||
      !apiKey ||
      !conversationId ||
      !senderId ||
      previewLoading.value ||
      previewClosing.value
    )
      return
    stopPreviewStream(false)
    const current = ++previewEpoch
    previewLoadingFlag.value = true
    previewError.value = ''
    try {
      const result = await channelBindingApi.conversation(apiKey, binding.code, conversationId, senderId, older ? previewNextBeforeTaskId.value ?? undefined : undefined, previewDeliveryPlatform.value)
      if (current === previewEpoch && previewBinding.value?.channel_binding_id === binding.channel_binding_id) {
        applyPreviewPage(result, older)
      }
    } catch (failure) {
      if (current === previewEpoch)
        previewFailure(failure, '会话读取失败，请保留原标识，核对后再读取。')
    } finally {
      if (current === previewEpoch) previewLoadingFlag.value = false
    }
  }
  function canSendUnsentPreviewMessage(scope: string): boolean {
    const original = unsentPreviewMessages.get(scope)
    const pending = pendingMessages.get(scope)
    return !!original && !!pending && scope === previewScope.value
      && original.key === pending.key && original.messageId === pending.messageId
      && original.query === pending.query && original.query !== ''
      && original.credential === previewApiKey.value.trim() && original.admin === currentAdminId.value
      && original.adminToken === userStore.accessToken
  }
  async function sendPreviewMessage(): Promise<void> {
    if (previewPlatform.value) return
    const binding = previewBinding.value
    const apiKey = previewApiKey.value.trim()
    const conversationId = previewConversationId.value.trim()
    const senderId = previewSenderId.value.trim()
    const scope = previewScope.value
    const originalPending = pendingMessages.get(scope)
    const query = originalPending?.query || previewQuery.value.trim()
    if (!validPreviewBinding(binding) || !apiKey || !conversationId || !senderId
      || (!query && !originalPending) || previewLoading.value || previewClosing.value || pendingCloseKey.value
      || (previewRecoveryInvalid.value && !originalPending)) return
    const owner = currentAdminId.value
    const adminToken = userStore.accessToken
    if (!owner || !adminToken) return
    const pending = originalPending ?? {
      messageId: newRuntimeRequestId('preview-message'), key: newRuntimeRequestId('channel'), query, dispatched: false
    }
    const flight = Symbol('channel-message')
    messageFlight.value = flight
    stopPreviewStream(false)
    const current = ++previewEpoch
    const stillCurrent = () => current === previewEpoch && scope === previewScope.value
      && owner === currentAdminId.value && adminToken === userStore.accessToken
      && apiKey === previewApiKey.value.trim() && pendingMessages.get(scope)?.key === pending.key
      && previewBinding.value?.channel_binding_id === binding.channel_binding_id
    pendingMessages.set(scope, pending)
    if (!originalPending) {
      unsentPreviewMessages.set(scope, { key: pending.key, messageId: pending.messageId, query,
        credential: apiKey, admin: owner, adminToken })
    }
    const recovering = !canSendUnsentPreviewMessage(scope)
    previewRequests.set(scope, { messageId: pending.messageId, key: pending.key,
      ...(pending.taskId === undefined ? {} : { taskId: pending.taskId }) })
    previewLoadingFlag.value = true
    previewError.value = ''
    try {
      let taskId: number
      let streamEnabled = false
      let submittedMessage: ChannelMessage | null = null
      if (recovering) {
        // A missing receipt is not proof of an unsent message; recovery never dispatches.
        const recovered = await channelBindingApi.messageByRequestKey(apiKey, binding.code,
          pending.messageId, conversationId, senderId, pending.key)
        if (!stillCurrent()) return
        taskId = recovered.task.id
        streamEnabled = recovered.task.streaming_enabled === true
        if (pending.taskId !== undefined && pending.taskId !== taskId)
          throw new Error('原任务标识不一致，请保留原号并核对当前绑定。')
        if (!Number.isSafeInteger(taskId) || taskId < 1)
          throw new Error('原消息回执任务号无效，请保留原号。')
        mergePreviewMessages([recovered])
      } else {
        const unsent = unsentPreviewMessages.get(scope)
        if (!unsent || !stillCurrent() || !canSendUnsentPreviewMessage(scope)) return
        if (!savePreviewRecovery() || !stillCurrent() || !canSendUnsentPreviewMessage(scope)) return
        // Only this page's original payload and read-back journal permit the first POST.
        unsentPreviewMessages.delete(scope)
        pending.dispatched = true
        const submitted = await channelBindingApi.submitMessage(apiKey, binding.code,
          { message_id: pending.messageId, conversation_id: conversationId, sender_id: senderId, query: unsent.query }, pending.key)
        if (!stillCurrent()) return
        taskId = submitted.task.id
        streamEnabled = submitted.task.streaming_enabled === true
        submittedMessage = { query: unsent.query, task: submitted.task }
      }
      if (!Number.isSafeInteger(taskId) || taskId < 1)
        throw new Error('未收到有效任务编号，请用原消息编号只读找回，不另建请求。')
      if (!stillCurrent()) return
      if (submittedMessage) mergePreviewMessages([submittedMessage])
      unsentPreviewMessages.delete(scope)
      pending.taskId = taskId
      previewRequests.set(scope, { messageId: pending.messageId, key: pending.key, taskId })
      pendingMessages.delete(scope)
      if (!savePreviewRecovery()) {
        pendingMessages.set(scope, pending)
        throw new Error('原任务已确认，但原号记录保存未确认；保留原号，只读找回同一消息。')
      }
      previewQuery.value = ''
      ElMessage.success(recovering ? `已只读找回原任务 #${taskId}` : `消息已提交为任务 #${taskId}`)
      if (streamEnabled) void startPreviewStream(taskId)
      for (let attempt = 0; attempt < 15; attempt += 1) {
        const result = await channelBindingApi.conversation(apiKey, binding.code, conversationId, senderId)
        if (current !== previewEpoch || scope !== previewScope.value || owner !== currentAdminId.value
          || adminToken !== userStore.accessToken || apiKey !== previewApiKey.value.trim()) return
        applyPreviewPage(result)
        const task = result.messages.find(item => item.task.id === taskId)?.task
        if (task && ['succeeded', 'failed', 'canceled', 'recovery_pending'].includes(task.state)) break
        await new Promise(resolve => setTimeout(resolve, 2000))
        if (current !== previewEpoch) return
      }
    } catch (failure) {
      if (stillCurrent()) previewFailure(failure, '消息结果尚未确认；原消息和请求号保留，只读找回，不再次发送。')
    } finally {
      if (messageFlight.value === flight) messageFlight.value = null
      if (current === previewEpoch) previewLoadingFlag.value = false
    }
  }
  async function closePreviewConversation(): Promise<void> {
    const binding = previewBinding.value
    const apiKey = previewApiKey.value.trim()
    const conversationId = previewConversationId.value.trim()
    const senderId = previewSenderId.value.trim()
    const platform = previewDeliveryPlatform.value
    const scope = previewScope.value
    if (!validPreviewBinding(binding) || !apiKey || !conversationId || !senderId || previewLoading.value ||
      previewClosing.value || pendingMessages.has(scope) || previewRecoveryInvalid.value) return
    const flight = Symbol('channel-close')
    closeFlight.value = flight
    stopPreviewStream(false)
    const current = ++previewEpoch
    const stillCurrent = () => current === previewEpoch && scope === previewScope.value && apiKey === previewApiKey.value.trim()
    previewClosingFlag.value = true
    try {
      let key = pendingCloses.get(scope)
      if (!key) {
        try {
          await ElMessageBox.confirm('关闭后，已有消息保留为只读历史，下一条消息从新的上下文开始。关闭本身不调用模型或计费。', '关闭当前对话', { type: 'warning', confirmButtonText: '关闭对话' })
        } catch { return }
        if (!stillCurrent()) return
        key = newRuntimeRequestId('channel-close')
        pendingCloses.set(scope, key)
        unsentPreviewCloses.add(scope)
      }
      const originalKey = key
      previewError.value = ''
      let result: ChannelConversationCloseReceipt
      if (!unsentPreviewCloses.has(scope)) {
        // Persisted keys and absent GET results never prove a close POST was not sent.
        result = await channelBindingApi.readConversationClose(apiKey, binding, conversationId, senderId, originalKey, platform)
      } else {
        if (!savePreviewRecovery() || !stillCurrent()) return
        // Consume only this page's known-unsent proof before its first POST.
        unsentPreviewCloses.delete(scope)
        result = await channelBindingApi.closeConversation(apiKey, binding.code, conversationId, senderId, originalKey, platform)
      }
      if (!stillCurrent() || pendingCloses.get(scope) !== originalKey) return
      pendingCloses.delete(scope)
      if (!savePreviewRecovery()) {
        pendingCloses.set(scope, originalKey)
        throw new Error('原关闭已确认，但原号记录尚未清除；请只读找回，不再关闭')
      }
      previewClosedAfterTaskId.value = result.closed_after_task_id
      ElMessage.success('原关闭已确认，历史消息仍可查阅')
    } catch (failure) {
      if (stillCurrent()) previewFailure(failure, '关闭结果尚未确认，原号保留；请只读找回，不重新提交。')
    } finally {
      if (closeFlight.value === flight) closeFlight.value = null
      if (current === previewEpoch) previewClosingFlag.value = false
    }
  }
  type PreviewEmbedding = {
    request_id?: string
    invocation: SandAiTask['embedding_invocation']
    usage_cost: SandAiTask['embedding_usage_cost']
  }
  function previewEmbeddingInvocations(task: SandAiTask): readonly PreviewEmbedding[] {
    if (task.embedding_invocations !== undefined) {
      const seen = new Set<string>()
      return task.embedding_invocations.filter((item) => {
        if (seen.has(item.request_id)) return false
        seen.add(item.request_id)
        return true
      })
    }
    return task.embedding_invocation
      ? [{ invocation: task.embedding_invocation, usage_cost: task.embedding_usage_cost }]
      : []
  }
  function previewEmbeddingAmount(item: PreviewEmbedding): string {
    const cost = item.usage_cost
    return item.invocation &&
      cost?.usage_state === 'measured' &&
      cost.pricing_state === 'priced' &&
      cost.metered_amount !== null &&
      cost.currency
      ? `${cost.metered_amount} ${cost.currency}`
      : '金额未确认'
  }
  function previewEmbeddingTotal(task: SandAiTask): string {
    const invocations = previewEmbeddingInvocations(task)
    if (!invocations.length) return '尚无调用记录，金额未确认'
    const totals: Record<string, string> = {}
    let unknown = 0
    for (const invocation of invocations) {
      const cost = invocation.usage_cost
      if (
        !invocation.invocation ||
        !cost ||
        cost.usage_state !== 'measured' ||
        cost.pricing_state !== 'priced' ||
        cost.metered_amount === null ||
        !cost.currency
      ) {
        unknown++
        continue
      }
      const next = addPreviewAmounts(totals[cost.currency] ?? '0', cost.metered_amount)
      if (next === null) {
        unknown++
        continue
      }
      totals[cost.currency] = next
    }
    const known = Object.entries(totals)
      .map(([currency, amount]) => `${amount} ${currency}`)
      .join('；')
    return `${known || '尚无已确认金额'}${unknown ? ` · ${unknown} 次检索金额未确认` : ''}`
  }
  type PreviewAmountKind = 'estimated' | 'metered' | 'reconciled'
  function previewInvocations(task: SandAiTask): readonly { request_id: string; state: string }[] {
    const invocations =
      task.agent_invocations !== undefined
        ? task.agent_invocations
        : task.result?.invocation?.request_id
          ? [
              {
                request_id: task.result.invocation.request_id,
                state: task.result.invocation.state ?? task.state
              }
            ]
          : []
    const seen = new Set<string>()
    const billableInvocations = task.summary_invocation ? [...invocations, task.summary_invocation] : invocations
    return billableInvocations.filter((invocation) => {
      if (seen.has(invocation.request_id)) return false
      seen.add(invocation.request_id)
      return true
    })
  }
  function previewAmountValue(
    cost: SandAiInvocationCost | undefined,
    kind: PreviewAmountKind
  ): string | null {
    if (!cost) return null
    if (kind === 'estimated') return cost.price.estimated_amount
    if (kind === 'reconciled') return cost.reconciliation.reconciled_amount
    return cost.usage.state === 'measured' && cost.price.pricing_state === 'priced'
      ? cost.price.metered_amount
      : null
  }
  function previewAmount(cost: SandAiInvocationCost | undefined, kind: PreviewAmountKind): string {
    const amount = previewAmountValue(cost, kind)
    return amount !== null && cost?.price.currency ? `${amount} ${cost.price.currency}` : '未确认'
  }
  function addPreviewAmounts(left: string, right: string): string | null {
    const decimal = /^(-?)(\d+)(?:\.(\d+))?$/
    const a = decimal.exec(left)
    const b = decimal.exec(right)
    if (!a || !b) return null
    const scale = Math.max(a[3]?.length ?? 0, b[3]?.length ?? 0)
    const scaled = (match: RegExpExecArray) =>
      BigInt(`${match[1]}${match[2]}${(match[3] ?? '').padEnd(scale, '0')}`)
    const total = scaled(a) + scaled(b)
    const magnitude = (total < 0n ? -total : total).toString().padStart(scale + 1, '0')
    return `${total < 0n ? '-' : ''}${scale ? `${magnitude.slice(0, -scale)}.${magnitude.slice(-scale)}` : magnitude}`
  }
  function previewCostTotal(task: SandAiTask, kind: PreviewAmountKind): string {
    const invocations = previewInvocations(task)
    if (!invocations.length) return '尚无调用记录，金额未确认'
    const totals: Record<string, string> = {}
    let unknown = 0
    for (const invocation of invocations) {
      const cost = previewCosts.value[invocation.request_id]
      const amount = previewAmountValue(cost, kind)
      const currency = cost?.price.currency
      if (amount === null || !currency || !/^-?\d+(?:\.\d+)?$/.test(amount)) {
        unknown++
        continue
      }
      const next = addPreviewAmounts(totals[currency] ?? '0', amount)
      if (next === null) {
        unknown++
        continue
      }
      totals[currency] = next
    }
    const known = Object.entries(totals)
      .map(([currency, amount]) => `${amount} ${currency}`)
      .join('；')
    return `${known || '尚无已确认金额'}${unknown ? ` · ${unknown} 轮金额未确认` : ''}`
  }
  async function loadPreviewCosts(task: SandAiTask): Promise<void> {
    const binding = previewBinding.value
    const apiKey = previewApiKey.value.trim()
    const current = previewEpoch
    if (
      !validPreviewBinding(binding) ||
      !apiKey ||
      previewCostLoading.value !== null ||
      !previewMessages.value.some((message) => message.task.id === task.id)
    )
      return
    const invocations = previewInvocations(task)
    previewCostLoading.value = task.id
    try {
      await Promise.all(
        invocations.map(async (invocation) => {
          try {
            const cost = await channelBindingApi.messageCost(apiKey, invocation.request_id)
            if (
              current !== previewEpoch ||
              previewBinding.value?.channel_binding_id !== binding.channel_binding_id
            )
              return
            if (cost.request_id !== invocation.request_id) throw new Error('费用记录与原调用不一致')
            previewCosts.value = { ...previewCosts.value, [invocation.request_id]: cost }
            const errors = { ...previewCostErrors.value }
            delete errors[invocation.request_id]
            previewCostErrors.value = errors
          } catch (failure) {
            if (current !== previewEpoch) return
            if (previewReadDenied(failure)) { clearDeniedPreview(); return }
            const costs = { ...previewCosts.value }
            delete costs[invocation.request_id]
            previewCosts.value = costs
            const code =
              failure instanceof SandAiRuntimeError &&
              failure.errorCode &&
              /^[A-Z0-9_]+$/.test(failure.errorCode)
                ? `（${failure.errorCode}）`
                : ''
            previewCostErrors.value = {
              ...previewCostErrors.value,
              [invocation.request_id]: `费用读取未确认${code}`
            }
          }
        })
      )
    } finally {
      if (current === previewEpoch) previewCostLoading.value = null
    }
  }
  async function loadDeliveries(targetPage: number): Promise<void> {
    const environment = props.environment
    const binding = selectedBinding.value
    if (!environment || !binding) return
    const current = ++deliveryEpoch
    deliveryLoading.value = true
    deliveryError.value = ''
    try {
      const result = await channelBindingApi.deliveries(environment.id, binding.channel_binding_id, targetPage)
      if (current !== deliveryEpoch) return
      deliveries.value = result.data
      deliveryTotal.value = result.total
      deliveryPage.value = targetPage
    } catch (failure) {
      if (current === deliveryEpoch) deliveryError.value = failure instanceof Error ? failure.message : '回信状态读取失败'
    } finally {
      if (current === deliveryEpoch) deliveryLoading.value = false
    }
  }
  async function loadWecomDeliveries(targetPage: number): Promise<void> {
    const environment = props.environment
    const binding = selectedBinding.value
    if (!environment || !binding) return
    const current = ++wecomDeliveryEpoch
    wecomDeliveryLoading.value = true
    wecomDeliveryError.value = ''
    try {
      const result = await channelBindingApi.wecomDeliveries(environment.id, binding.channel_binding_id, targetPage)
      if (current !== wecomDeliveryEpoch) return
      wecomDeliveries.value = result.data
      wecomDeliveryTotal.value = result.total
      wecomDeliveryPage.value = targetPage
    } catch (failure) {
      if (current === wecomDeliveryEpoch) wecomDeliveryError.value = failure instanceof Error ? failure.message : '企业微信回信读取失败'
    } finally {
      if (current === wecomDeliveryEpoch) wecomDeliveryLoading.value = false
    }
  }
  function validDeliveryRetryReference(raw: unknown): raw is DeliveryRetryReference {
    if (!raw || typeof raw !== 'object' || Array.isArray(raw)) return false
    const value = raw as Record<string, unknown>
    const positiveId = (id: unknown): id is number => typeof id === 'number' && Number.isSafeInteger(id) && id > 0
    return typeof value.key === 'string' && /^[\x21-\x7e]{1,128}$/.test(value.key)
      && typeof value.user_id === 'string' && /^\d+$/.test(value.user_id)
      && positiveId(value.environment_id) && positiveId(value.channel_binding_id) && positiveId(value.task_id)
      && (value.platform === 'feishu' || value.platform === 'wecom')
  }
  function readDeliveryRetryJournal(): void {
    try {
      const raw: unknown = JSON.parse(localStorage.getItem(deliveryRetryJournalKey) || '[]')
      if (!Array.isArray(raw) || raw.length > 256 || !raw.every(validDeliveryRetryReference)) throw new Error('invalid retry journal')
      deliveryRetryJournal.value = raw
      deliveryRetryJournalInvalid.value = false
    } catch {
      // Do not delete damaged references or infer that an unknown request never reached the server.
      deliveryRetryJournalInvalid.value = true
    }
  }
  function writeDeliveryRetryJournal(references: DeliveryRetryReference[]): boolean {
    try {
      if (references.length > 256) throw new Error('retry journal full')
      localStorage.setItem(deliveryRetryJournalKey, JSON.stringify(references))
      deliveryRetryJournal.value = references
      return true
    } catch {
      deliveryRetryJournalInvalid.value = true
      deliveryRecoveryError.value = '原请求记录未能保存，暂不能发起新重试。请保留本页并核对原回信。'
      return false
    }
  }
  function deliveryRetryBlocked(taskId: number, platform: DeliveryPlatform): boolean {
    return !deliveryRetryLockAvailable || deliveryRetryJournalInvalid.value || !currentAdminId.value || deliveryRetryJournal.value.some(item => item.task_id === taskId
      && item.platform === platform && item.environment_id === props.environment?.id
      && item.channel_binding_id === selectedBinding.value?.channel_binding_id)
  }
  function originalDeliveryScope(reference: DeliveryRetryReference): boolean {
    return reference.user_id === currentAdminId.value && reference.environment_id === props.environment?.id
      && reference.channel_binding_id === selectedBinding.value?.channel_binding_id
  }
  async function refreshRetryDelivery(reference: DeliveryRetryReference): Promise<void> {
    const identity = deliveryIdentityEpoch
    const selection = deliverySelectionEpoch
    const stillCurrent = () => identity === deliveryIdentityEpoch && selection === deliverySelectionEpoch && originalDeliveryScope(reference)
    if (!stillCurrent()) return
    if (reference.platform === 'wecom') {
      await loadWecomDeliveries(wecomDeliveryPage.value)
      if (stillCurrent()) await loadWecomStatus()
    } else {
      await loadDeliveries(deliveryPage.value)
      if (stillCurrent()) await loadStatus()
    }
  }
  function sameDeliveryRetryReference(left: DeliveryRetryReference, right: DeliveryRetryReference): boolean {
    return left.key === right.key && left.user_id === right.user_id && left.environment_id === right.environment_id
      && left.channel_binding_id === right.channel_binding_id && left.task_id === right.task_id && left.platform === right.platform
  }
  async function acknowledgeDeliveryRetry(reference: DeliveryRetryReference, stillCurrent: () => boolean): Promise<boolean> {
    const removeConfirmed = () => {
      if (!stillCurrent()) return false
      // Match the complete original entry; never remove another request for this delivery.
      readDeliveryRetryJournal()
      if (deliveryRetryJournalInvalid.value) return false
      return writeDeliveryRetryJournal(deliveryRetryJournal.value.filter(item => !sameDeliveryRetryReference(item, reference)))
    }
    // Unsupported browsers cannot create entries; their existing entries can still be read and acknowledged.
    return deliveryRetryLockAvailable
      ? await navigator.locks.request(deliveryRetryJournalKey, { mode: 'exclusive' }, removeConfirmed)
      : removeConfirmed()
  }
  async function recoverDeliveryRetry(reference: DeliveryRetryReference): Promise<void> {
    if (!originalDeliveryScope(reference) || deliveryRecoveringKey.value || retryingTaskId.value !== null) return
    const identity = deliveryIdentityEpoch
    const selection = deliverySelectionEpoch
    const stillCurrent = () => identity === deliveryIdentityEpoch && selection === deliverySelectionEpoch && originalDeliveryScope(reference)
    deliveryRecoveringKey.value = reference.key
    deliveryRecoveryError.value = ''
    try {
      const result = reference.platform === 'wecom'
        ? await channelBindingApi.retryWecomDeliveryByRequestKey(reference.environment_id, reference.channel_binding_id, reference.task_id, reference.key)
        : await channelBindingApi.retryDeliveryByRequestKey(reference.environment_id, reference.channel_binding_id, reference.task_id, reference.key)
      if (!stillCurrent()) return
      if (result.task_id !== reference.task_id || result.idempotent_replay !== true || result.state !== 'pending')
        throw new Error('原请求回执与当前任务不一致，仍保留原请求锁。')
      if (!await acknowledgeDeliveryRetry(reference, stillCurrent) || !stillCurrent()) return
      ElMessage.success('已找回原重试受理回执，当前发送状态以回信记录为准')
      await refreshRetryDelivery(reference)
    } catch (failure) {
      if (stillCurrent()) deliveryRecoveryError.value = `${failure instanceof Error ? failure.message : '原重试回执尚未找回'}；仍保留原请求号，请勿再次重试。`
    } finally {
      if (stillCurrent()) deliveryRecoveringKey.value = ''
    }
  }
  async function retryChannelDelivery(row: ChannelDeliveryRow, platform: DeliveryPlatform): Promise<void> {
    const environment = props.environment
    const binding = selectedBinding.value
    readDeliveryRetryJournal()
    if (!environment || !binding || binding.status !== 1 || binding.revision !== row.channel_revision
      || row.state !== 'failed' || retryingTaskId.value !== null || deliveryRecoveringKey.value || deliveryRetryBlocked(row.task_id, platform)) return
    const owner = { selectionEpoch: deliverySelectionEpoch }
    const identity = deliveryIdentityEpoch
    const userId = currentAdminId.value
    const stillCurrent = () => identity === deliveryIdentityEpoch && userId === currentAdminId.value && isCurrentDeliveryRetry(owner)
    deliveryRetryOwner = owner
    retryingTaskId.value = row.task_id
    try {
      try {
        await ElMessageBox.confirm(platform === 'wecom'
          ? '只发送尚未确认的企业微信回信段；结果不明的回信不可重试。提交结果未知时只按原请求号找回，不重复发送。'
          : '将使用原任务和同一条飞书消息标识重新发送。提交结果未知时只按原请求号找回，不重复发送。',
        '重试回信', { type: 'warning' })
      } catch { return }
      if (!stillCurrent()) return
      // A browser-wide exclusive lock makes the reload/check/persist step atomic across tabs.
      const reference = await navigator.locks.request(deliveryRetryJournalKey, { mode: 'exclusive' }, (): DeliveryRetryReference | null => {
        if (!stillCurrent()) return null
        readDeliveryRetryJournal()
        if (deliveryRetryBlocked(row.task_id, platform)) return null
        const original: DeliveryRetryReference = {
          key: newRuntimeRequestId(platform === 'wecom' ? 'wecom-delivery-retry' : 'channel-delivery-retry'),
          user_id: userId, environment_id: environment.id, channel_binding_id: binding.channel_binding_id, task_id: row.task_id, platform
        }
        // Persist before dispatch; every failure keeps this original key until its receipt is read.
        return writeDeliveryRetryJournal([...deliveryRetryJournal.value, original]) ? original : null
      })
      if (!reference || !stillCurrent()) return
      deliveryRecoveryError.value = ''
      if (platform === 'wecom') wecomDeliveryError.value = ''
      else deliveryError.value = ''
      const result = platform === 'wecom'
        ? await channelBindingApi.retryWecomDelivery(reference.environment_id, reference.channel_binding_id, reference.task_id, reference.key)
        : await channelBindingApi.retryDelivery(reference.environment_id, reference.channel_binding_id, reference.task_id, reference.key)
      if (!stillCurrent()) return
      if (result.task_id !== reference.task_id || result.state !== 'pending')
        throw new Error('重试受理回执与原任务不一致，请沿原请求号找回。')
      if (!await acknowledgeDeliveryRetry(reference, stillCurrent) || !stillCurrent()) return
      ElMessage.success('回信重试已受理，当前发送状态以回信记录为准')
      await refreshRetryDelivery(reference)
    } catch (failure) {
      if (stillCurrent()) {
        const message = `${failure instanceof Error ? failure.message : '回信重试提交结果未确认'}；请沿原请求号找回，不要再次发送。`
        if (platform === 'wecom') wecomDeliveryError.value = message
        else deliveryError.value = message
      }
    } finally {
      if (deliveryRetryOwner === owner) {
        deliveryRetryOwner = null
        retryingTaskId.value = null
      }
    }
  }
  async function retryDelivery(row: ChannelDeliveryRow): Promise<void> { await retryChannelDelivery(row, 'feishu') }
  async function retryWecomDelivery(row: ChannelDeliveryRow): Promise<void> { await retryChannelDelivery(row, 'wecom') }
  async function refreshBindingPanel(): Promise<void> {
    const identity = bindingIdentityEpoch
    await load(1)
    if (identity !== bindingIdentityEpoch || bindingRecoveringKey.value) return
    readBindingJournal()
    for (const item of currentBindingRecoveries.value) {
      if (identity !== bindingIdentityEpoch) return
      await confirmBindingMutation(item)
    }
  }
  async function confirmBindingMutation(item: BindingJournalReference): Promise<void> {
    if (bindingRecoveringKey.value || bindingJournalInvalid.value ||
      item.user_id !== currentAdminId.value || item.environment_id !== props.environment?.id || !userStore.accessToken) return
    const identity = bindingIdentityEpoch
    const token = userStore.accessToken
    const owner = Symbol('binding-operation-read')
    bindingReadOwner = owner
    const isCurrent = () => bindingReadOwner === owner && bindingIdentityCurrent(item, identity, token)
    bindingRecoveringKey.value = item.request_key
    error.value = ''
    try {
      const receipt = await channelBindingApi.operationByRequestKey(bindingOperationQuery(item))
      if (!isCurrent()) return
      if (receipt.state !== 'completed') { error.value = '原操作结果仍未确认，请保留原请求，稍后只读找回。'; return }
      if (!completeBindingJournal(item)) return
      error.value = ''
      ElMessage.success(item.operation === 'save' ? '原渠道绑定保存已确认' : '原渠道停用已确认')
      await load(1)
    } catch (failure) {
      if (isCurrent()) error.value = `${failure instanceof Error ? failure.message : '原操作结果未确认'}；保留原请求，只读找回。`
    } finally {
      if (identity === bindingIdentityEpoch && token === userStore.accessToken && bindingReadOwner === owner) {
        bindingReadOwner = null
        bindingRecoveringKey.value = ''
      }
    }
  }
  async function submitBindingMutation(operation: 'save' | 'disable', input: ChannelBindingDraft | number, heldOwner?: symbol): Promise<void> {
    const environment = props.environment
    const userId = currentAdminId.value
    const token = userStore.accessToken
    if (!environment || !userId || !token || (writing.value && (!heldOwner || bindingWriteOwner.value !== heldOwner))
      || pendingBindingMutation.value || bindingRecoveringKey.value) return
    const identity = bindingIdentityEpoch
    const owner = heldOwner ?? Symbol('binding-operation')
    bindingWriteOwner.value = owner
    writingFlag.value = true
    error.value = ''
    let reference: BindingJournalReference | null = null
    try {
      const hash = await channelBindingInputSha256(input)
      if (identity !== bindingIdentityEpoch || token !== userStore.accessToken || userId !== currentAdminId.value || environment.id !== props.environment?.id) return
      if (operation === 'save' && typeof input !== 'number') reference = { operation: 'save', code: input.code,
        user_id: userId, environment_id: environment.id, request_key: newRuntimeRequestId('channel-save'), input_sha256: hash }
      else if (operation === 'disable' && typeof input === 'number') reference = { operation: 'disable', channel_binding_id: input,
        user_id: userId, environment_id: environment.id, request_key: newRuntimeRequestId('channel-disable'), input_sha256: hash }
      else return
      readBindingJournal()
      if (bindingJournalInvalid.value || currentBindingRecoveries.value.length || !persistBindingJournal([...bindingJournal.value, reference])) return
      // Only this explicit action keeps the frozen body in memory and sends once after metadata is durable.
      const receipt = typeof input === 'number' ? await channelBindingApi.disable(environment.id, input, reference.request_key) :
        await channelBindingApi.save(input, reference.request_key)
      if (!bindingIdentityCurrent(reference, identity, token)) return
      const proven = await provesChannelBindingSnapshot(receipt, bindingOperationQuery(reference))
      if (!bindingIdentityCurrent(reference, identity, token)) return
      if (!proven) throw new Error('渠道操作回执与原目标或配置不一致')
      if (!completeBindingJournal(reference)) return
      ElMessage.success(operation === 'save' ? '渠道绑定已保存' : '渠道已停用')
      await load(1)
    } catch (failure) {
      if (identity === bindingIdentityEpoch && token === userStore.accessToken &&
        (!reference || bindingIdentityCurrent(reference, identity, token))) {
        error.value = `${failure instanceof Error ? failure.message : '渠道操作结果未确认'}；保留原请求，只读找回。`
      }
    } finally {
      if (bindingWriteOwner.value === owner) {
        bindingWriteOwner.value = null
        writingFlag.value = false
        if (reference && bindingIdentityCurrent(reference, identity, token)) void confirmBindingMutation(reference)
      }
    }
  }
  async function save(): Promise<void> {
    const environment = props.environment
    if (!environment || !canSave.value || form.agent_definition_id === null) return
    const input: ChannelBindingDraft = Object.freeze({ environment_id: environment.id, code: form.code.trim(), name: form.name.trim(),
      agent_definition_id: form.agent_definition_id, application_id: String(environment.application_id).trim(), workload_client_id: form.workload_client_id.trim() })
    await submitBindingMutation('save', input)
  }
  async function disable(row: ChannelBindingRow): Promise<void> {
    const environment = props.environment
    const identity = bindingIdentityEpoch
    const token = userStore.accessToken
    if (!environment || writing.value || pendingBindingMutation.value || !currentAdminId.value || row.environment_id !== environment.id) return
    const userId = currentAdminId.value
    const target = Object.freeze({ channel_binding_id: row.channel_binding_id, name: row.name,
      environment_id: row.environment_id, revision: row.revision, status: row.status })
    const owner = Symbol('binding-disable')
    bindingWriteOwner.value = owner
    try {
      try { await ElMessageBox.confirm(`停用「${target.name}」后，旧渠道任务也将不可读取。`, '停用渠道', { type: 'warning' }) }
      catch { return }
      if (bindingWriteOwner.value !== owner || identity !== bindingIdentityEpoch || token !== userStore.accessToken
        || userId !== currentAdminId.value || props.environment?.id !== environment.id) return
      const current = rows.value.find(item => item.channel_binding_id === target.channel_binding_id)
      if (!current || current.environment_id !== target.environment_id || current.revision !== target.revision
        || current.status !== target.status) {
        error.value = '渠道绑定已变化，请刷新后重新确认停用。'
        return
      }
      await submitBindingMutation('disable', target.channel_binding_id, owner)
    } finally {
      if (bindingWriteOwner.value === owner) {
        bindingWriteOwner.value = null
        writingFlag.value = false
      }
    }
  }
  watch(() => props.environment?.id, () => {
    clearPendingPreviewBodies()
    previewApiKey.value = ''
    invalidateDeliverySelection()
    ++statusEpoch; channelStatus.value = null; statusError.value = ''; statusLoading.value = false
    ++wecomStatusEpoch; wecomStatus.value = null; wecomStatusError.value = ''; wecomStatusLoading.value = false
    // Keep unknown credential mutations with their original environment, binding and key.
    feishuVisible.value = false; closeFeishu(); feishuBinding.value = null
    wecomVisible.value = false; closeWecom(); wecomBinding.value = null
    ++epoch; ++deliveryEpoch; ++wecomDeliveryEpoch; resetPreview(); rows.value = []; agents.value = []; clients.value = []; total.value = 0; directoryLoading.value = false
    selectedBinding.value = null; deliveries.value = []; deliveryTotal.value = 0; deliveryError.value = ''
    wecomDeliveries.value = []; wecomDeliveryTotal.value = 0; wecomDeliveryError.value = ''
    agentPage.value = 0; clientPage.value = 0; agentMore.value = false; clientMore.value = false
    newBinding(); void load(1); void loadAgents(); void loadClients()
  }, { immediate: true, flush: 'sync' })
  watch(() => selectedBinding.value?.revision, invalidateDeliverySelection, { flush: 'sync' })
  watch(previewScope, () => {
    unsentPreviewMessages.clear()
    unsentPreviewCloses.clear()
    clearPreviewSource()
    stopPreviewStream()
    ++previewEpoch
      previewQuery.value = ''
    previewMessages.value = []
    previewClosedAfterTaskId.value = 0
    previewNextBeforeTaskId.value = null; previewHistoryPageLoaded.value = false
    previewClosingFlag.value = false
      previewCosts.value = {}
      previewCostErrors.value = {}
      previewCostLoading.value = null
    previewLoadingFlag.value = false
    previewError.value = ''
  }, { flush: 'sync' })
  watch(
    previewApiKey,
    () => {
      clearPendingPreviewBodies()
      clearPreviewSource()
      stopPreviewStream()
      receiptRecoveryFlight = null
      receiptRecoveryBusy.value = null
      receiptRecoveryErrors.value = {}
    ++previewEpoch
      previewMessages.value = []
      previewCosts.value = {}
      previewCostErrors.value = {}
      previewCostLoading.value = null
      previewLoadingFlag.value = false
      previewClosingFlag.value = false
      previewQuery.value = ''
      previewClosedAfterTaskId.value = 0
      previewNextBeforeTaskId.value = null; previewHistoryPageLoaded.value = false
      previewError.value = ''
    },
    { flush: 'sync' }
  )
  watch(receiptRecoveryStorageKey, () => {
    receiptRecoveryFlight = null
    receiptRecoveryBusy.value = null
    receiptRecoveryErrors.value = {}
    readReceiptRecoveryIntents()
  }, { immediate: true, flush: 'sync' })
  watch([previewConversationId, previewSenderId], () => savePreviewRecovery())
  function observeRetryJournal(event: StorageEvent): void {
    if (event.key === deliveryRetryJournalKey || event.key === null) readDeliveryRetryJournal()
    if (event.key === credentialJournalKey || event.key === null) readCredentialJournal()
    if (event.key === receiptRecoveryStorageKey.value || event.key === null) readReceiptRecoveryIntents()
  }
  watch([currentAdminId, () => userStore.accessToken], () => {
    ++deliveryIdentityEpoch
    clearPendingPreviewBodies()
    previewApiKey.value = ''
    platformMessageId.value = ''
    previewConversationId.value = ''
    previewSenderId.value = ''
    feishuVisible.value = false; closeFeishu(); feishuBinding.value = null; feishuError.value = ''
    wecomVisible.value = false; closeWecom(); wecomBinding.value = null; wecomError.value = ''
    invalidateDeliverySelection()
    selectedBinding.value = null
    deliveries.value = []; deliveryTotal.value = 0
    wecomDeliveries.value = []; wecomDeliveryTotal.value = 0
    channelStatus.value = null; statusError.value = ''; deliveryError.value = ''
    wecomStatus.value = null; wecomStatusError.value = ''; wecomDeliveryError.value = ''
    ++epoch
    rows.value = []; total.value = 0; agents.value = []; clients.value = []
    loading.value = false; directoryLoading.value = false
    agentPage.value = 0; clientPage.value = 0; agentMore.value = false; clientMore.value = false
    newBinding()
    resetPreview()
    readDeliveryRetryJournal()
    if (currentAdminId.value && userStore.accessToken) { void load(1); void loadAgents(); void loadClients() }
  }, { flush: 'sync' })
  watch([currentAdminId, () => userStore.accessToken, () => props.environment?.id], () => {
    ++bindingIdentityEpoch
    bindingReadOwner = null
    writingFlag.value = false
    bindingRecoveringKey.value = ''
    readBindingJournal()
    const identity = bindingIdentityEpoch
    void (async () => {
      for (const item of currentBindingRecoveries.value) {
        if (identity !== bindingIdentityEpoch) return
        await confirmBindingMutation(item)
      }
    })()
  }, { immediate: true, flush: 'sync' })
  watch([currentAdminId, () => userStore.accessToken, () => props.environment?.id], () => {
    ++credentialIdentityEpoch
    credentialBodies.clear()
    credentialWriteOwners.feishu = null; credentialWriteOwners.wecom = null
    credentialReadOwner = null; credentialRecoveringKey.value = ''; credentialRecoveryError.value = ''
    feishuWriting.value = false; wecomWriting.value = false
    feishuVisible.value = false; closeFeishu(); feishuBinding.value = null; feishuError.value = ''
    wecomVisible.value = false; closeWecom(); wecomBinding.value = null; wecomError.value = ''
    readCredentialJournal()
  }, { immediate: true, flush: 'sync' })
  readDeliveryRetryJournal()
  onMounted(() => { window.addEventListener('storage', observeRetryJournal) })
  onUnmounted(() => { clearPendingPreviewBodies(); ++credentialIdentityEpoch; credentialBodies.clear(); credentialWriteOwners.feishu = null; credentialWriteOwners.wecom = null; credentialReadOwner = null; ++bindingIdentityEpoch; window.removeEventListener('storage', observeRetryJournal); clearPreviewSource(); stopPreviewStream(); ++previewEpoch; invalidateDeliverySelection() })
</script>

<style scoped lang="scss">
  .panel-head { display: flex; justify-content: space-between; align-items: center; gap: 12px; }
  .panel-head h3 { margin: 0; }
  .panel-head p { color: var(--el-text-color-secondary); font-size: 13px; }
  .panel-alert, .pager { margin: 14px 0; }
  .preview-source-content { max-height: 360px; overflow: auto; white-space: pre-wrap; overflow-wrap: anywhere; }
  .preview-message { padding: 12px 0; border-bottom: 1px solid var(--el-border-color-lighter); }
  .preview-message p { margin: 4px 0; }
  .preview-note { color: var(--el-text-color-secondary); font-size: 13px; }
  .channel-status { padding: 8px 0; color: var(--el-text-color-regular); }
  .channel-status p { margin: 5px 0; }
</style>
