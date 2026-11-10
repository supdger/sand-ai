<template>
  <ElCard shadow="never">
    <template #header>
      <div class="panel-head">
        <div>
          <h3>渠道</h3>
          <p>把智能体接到网页或接口上，让外面的人能问到它。</p>
        </div>
        <ElButton :disabled="!environment" :loading="loading" @click="refreshBindingPanel">刷新</ElButton>
      </div>
    </template>
    <ElEmpty v-if="!environment" description="先在上方选择应用环境" />
    <template v-else>
      <ElAlert v-if="error" type="error" :title="error" show-icon class="panel-alert" @close="error = ''" />
      <ElAlert v-if="!deliveryRetryLockAvailable" type="warning" :closable="false" class="panel-alert"
        title="当前浏览器不能安全发起回信重试" description="浏览器缺少跨标签互斥能力，暂不能发起新的重试；已有原请求仍可只读找回。请在支持此能力的浏览器中核对回信状态。" />
      <ElAlert v-if="deliveryRetryJournalInvalid" type="warning" :closable="false" class="panel-alert"
        title="回信重试记录无法保存或读取" description="为避免重复回信，暂不能发起新的回信重试。请保留此浏览器的原请求记录并核对回信；记录异常不会按未发送处理。" />
      <ElAlert v-if="otherDeliveryRecoveryCount" type="warning" :closable="false" class="panel-alert"
        title="存在尚未确认的回信重试" description="请切回原应用环境与渠道绑定，使用原管理员身份查看原请求结果。切换页面或身份不会清除未确认请求，也不会自动重新发送。" />
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
          {{ item.platform === 'feishu' ? '飞书' : '企业微信' }} · 渠道 {{ item.code }}
          <ElButton link type="primary" :loading="credentialRecoveringKey === item.request_key"
            :disabled="!!credentialRecoveringKey || feishuWriting || wecomWriting" @click="confirmCredentialSave(item)">查看这次保存的结果</ElButton>
        </p>
        <p class="preview-note">已确认的是这次原保存操作；当前渠道配置是否后来变化，以重新读取的渠道记录为准。</p>
      </div>
      <ElAlert v-if="bindingJournalInvalid" type="warning" :closable="false" class="panel-alert"
        title="原渠道操作记录无法安全保存或读取" description="暂不能提交保存或停用。请保留当前浏览器记录；记录异常不能视为未提交。" />
      <ElAlert v-if="otherBindingRecoveryCount" type="warning" :closable="false" class="panel-alert"
        title="其他身份或环境有待确认渠道操作" description="请切回原管理员与原环境，只读找回原请求；切换不会重新提交或清除原记录。" />
      <div v-if="currentBindingRecoveries.length" aria-label="原渠道操作恢复" class="panel-alert">
        <ElAlert type="warning" :closable="false" show-icon title="渠道操作结果还没确认"
          description="只查询原请求回执。未找到或无法完整确认时保留原请求，不会再次保存或停用。" />
        <p v-for="item in currentBindingRecoveries" :key="item.request_key">
          {{ item.operation === 'save' ? `保存渠道 ${item.code}` : '停用渠道' }}
          <ElButton link type="primary" :loading="bindingRecoveringKey === item.request_key"
            :disabled="!!bindingRecoveringKey" @click="confirmBindingMutation(item)">查看这次操作的结果</ElButton>
        </p>
      </div>

      <div class="filter-row">
        <ElInput v-model="keyword" clearable placeholder="搜索渠道名称" style="width: 220px" aria-label="搜索渠道名称" />
        <ElSelect v-model="statusFilter" clearable placeholder="全部状态" style="width: 140px" aria-label="按状态筛选">
          <ElOption label="可用" value="1" />
          <ElOption label="已停用" value="2" />
        </ElSelect>
      </div>

      <ElTable v-loading="loading" :data="pagedRows" row-key="channel_binding_id"
        :empty-text="rows.length === 0 ? '还没有渠道' : '没有找到符合条件的渠道'">
        <template #empty>
          <div v-if="rows.length === 0" class="empty-state">
            <p class="empty-title">还没有渠道</p>
            <p class="empty-desc">把智能体接到网页或接口上，外面的人就能问到它</p>
            <ElButton type="primary" :disabled="!environment" @click="openCreate">新建渠道</ElButton>
          </div>
          <div v-else class="empty-state">
            <p class="empty-title">没有找到符合条件的渠道</p>
            <ElButton link type="primary" @click="clearFilters">清空筛选条件</ElButton>
          </div>
        </template>
        <ElTableColumn label="渠道名" prop="name" min-width="150" />
        <ElTableColumn label="类型" width="110">
          <template #default="{ row }">{{ channelKindLabel(row) }}</template>
        </ElTableColumn>
        <ElTableColumn label="接的智能体" min-width="180">
          <template #default="{ row }">
            <template v-if="agentOf(row)">
              <span>{{ row.agent_name }}</span>
              <span v-if="agentNeedsEnable(row)" class="agent-warn">
                接的智能体还没启用，渠道里的人问了不会回答
                <ElButton link type="primary" @click="goEnableAgent(row)">去启用</ElButton>
              </span>
            </template>
            <span v-else>{{ row.agent_name }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn label="状态" width="100">
          <template #default="{ row }"><ElTag :type="row.status === 1 ? 'success' : 'info'" size="small">{{ row.status === 1 ? '可用' : '已停用' }}</ElTag></template>
        </ElTableColumn>
        <ElTableColumn label="操作" min-width="200">
          <template #default="{ row }">
            <ElButton link type="primary" :disabled="writing || !!pendingBindingMutation" @click="edit(row)">编辑</ElButton>
            <ElDropdown trigger="click" :disabled="writing || !!pendingBindingMutation">
              <ElButton link type="primary">更多</ElButton>
              <template #dropdown>
                <ElDropdownMenu>
                  <ElDropdownItem v-if="row.feishu_configured || row.wecom_configured" @click="openToolsMenu(row)">聊天工具配置</ElDropdownItem>
                  <ElDropdownItem v-if="row.feishu_configured || row.wecom_configured" @click="showDeliveries(row)">回信记录</ElDropdownItem>
                  <ElDropdownItem v-if="row.status === 1" divided :disabled="writing || !!pendingBindingMutation" @click="disable(row)">停用</ElDropdownItem>
                </ElDropdownMenu>
              </template>
            </ElDropdown>
          </template>
        </ElTableColumn>
      </ElTable>
      <ElPagination v-if="filteredTotal > pageSize" class="pager" background layout="prev, pager, next, total"
        :current-page="currentPage" :page-size="pageSize" :total="filteredTotal" @current-change="currentPage = $event" />

      <ElDialog v-model="editorVisible" :title="editingId ? '编辑渠道' : '新建渠道'" width="min(560px, 94vw)" destroy-on-close>
        <!-- 第一步：选类型（仅新建） -->
        <template v-if="!editingId && editorStep === 'type'">
          <div class="type-cards">
            <button type="button" class="type-card" :class="{ 'type-card--active': formKind === 'api' }" @click="formKind = 'api'">
              <strong>接口调用</strong>
              <span>你的系统或脚本通过接口把消息发给智能体</span>
            </button>
            <button type="button" class="type-card" :class="{ 'type-card--active': formKind === 'web' }" @click="formKind = 'web'">
              <strong>网页对话</strong>
              <span>在网页上直接和智能体对话</span>
            </button>
          </div>
          <p class="type-note">企业微信、钉钉、飞书即将支持</p>
          <div class="drawer-actions">
            <ElButton @click="editorVisible = false">取消</ElButton>
            <ElButton type="primary" :disabled="!formKind" @click="editorStep = 'form'">下一步</ElButton>
          </div>
        </template>
        <template v-else>
          <ElAlert v-if="editorError" type="error" :title="editorError" show-icon :closable="false" class="panel-alert" />
          <ElAlert v-if="testState === 'ok'" type="success" :closable="false" class="panel-alert"
            title="连接正常，可以保存" />
          <ElAlert v-else-if="testState === 'fail'" type="error" :closable="false" class="panel-alert" :title="testError" />
          <ElForm label-position="top" @submit.prevent>
            <ElFormItem label="渠道名" required>
              <ElInput v-model="form.name" maxlength="64" placeholder="例如官网客服" />
              <div v-if="fieldErrors.name" class="field-error">{{ fieldErrors.name }}</div>
            </ElFormItem>
            <ElFormItem label="接的智能体" required>
              <ElSelect v-model="form.agent_definition_id" filterable clearable :disabled="writing || !!pendingBindingMutation"
                placeholder="选择已启用的智能体" style="width: 100%" aria-label="接的智能体">
                <ElOption v-for="agent in publishedAgents" :key="agent.agent_definition_id" :value="agent.agent_definition_id"
                  :label="`${agent.name}（第 ${agent.active_version_no} 版）`" />
              </ElSelect>
              <ElButton v-if="agentMore" link :disabled="directoryLoading || writing || !!pendingBindingMutation" @click="loadAgents">加载更多智能体</ElButton>
              <div v-if="fieldErrors.agent" class="field-error">{{ fieldErrors.agent }}</div>
              <p class="preview-note">只列出已启用的智能体；还没启用的请先到「智能体」页启用。</p>
            </ElFormItem>
            <ElFormItem label="调用身份" required>
              <ElSelect v-model="form.workload_client_id" filterable clearable :disabled="writing || !!pendingBindingMutation"
                placeholder="选择调用时使用的应用身份" style="width: 100%" aria-label="调用身份">
                <ElOption v-if="form.workload_client_id && !selectedClient" :value="form.workload_client_id"
                  :label="`原选择调用身份（未核对）`" disabled />
                <ElOption v-for="client in clients" :key="client.id" :value="String(client.id)" :label="client.name" />
              </ElSelect>
              <ElButton v-if="clientMore" link :disabled="directoryLoading || writing || !!pendingBindingMutation" @click="loadClients">加载更多调用身份</ElButton>
              <ElButton v-if="agentMore || clientMore" link :disabled="directoryLoading || writing || !!pendingBindingMutation" @click="refreshBindingDirectory">重新读取全部目录</ElButton>
              <p v-if="form.workload_client_id && !selectedClient" class="preview-note">此调用身份尚未在当前应用环境的可用目录中核对。原绑定仍保留；请加载更多、重新读取目录或重新选择，再保存。</p>
              <div v-if="fieldErrors.client" class="field-error">{{ fieldErrors.client }}</div>
            </ElFormItem>
            <p class="preview-note">保存后外部系统用「接口调用」地址和调用身份发消息；密钥类配置在对应工具里单独管理，不在这里填写。</p>
          </ElForm>
          <div class="drawer-actions">
            <template v-if="!editingId">
              <ElButton @click="editorStep = 'type'">上一步</ElButton>
            </template>
            <ElButton @click="editorVisible = false">取消</ElButton>
            <ElButton :loading="testing" :disabled="!canTest" @click="testConnection">测试连接</ElButton>
            <ElButton type="primary" :loading="writing" :disabled="!canSaveDialog" @click="save">保存</ElButton>
          </div>
        </template>
      </ElDialog>

      <ElDrawer v-model="deliveryVisible" :title="`「${selectedBinding?.name ?? ''}」回信记录`" size="min(860px, 95vw)" destroy-on-close>
        <template v-if="selectedBinding">
          <template v-if="selectedBinding.feishu_configured">
            <div class="panel-head">
              <h3>飞书连接状态</h3>
              <ElButton :loading="deliveryLoading || statusLoading || wecomStatusLoading || wecomDeliveryLoading" @click="refreshChannelStatus">刷新状态</ElButton>
            </div>
            <div v-if="currentDeliveryRecoveries.length" aria-label="未确认回信重试恢复" class="panel-alert">
              <ElAlert type="warning" :closable="false" show-icon title="回信重试提交结果还没确认"
                description="只按原请求查看受理回执，不再次发送回信。未找到或无权读取时仍保留原请求锁；实际发送进度以刷新后的回信记录为准。" />
              <p v-for="item in currentDeliveryRecoveries" :key="item.key">
                {{ item.platform === 'wecom' ? '企业微信' : '飞书' }} 的回信重试
                <ElButton link type="primary" :loading="deliveryRecoveringKey === item.key"
                  :disabled="Boolean(retryingTaskId) || Boolean(deliveryRecoveringKey)" @click="recoverDeliveryRetry(item)">查看这次重试的结果</ElButton>
              </p>
              <ElAlert v-if="deliveryRecoveryError" type="error" :closable="false" :title="deliveryRecoveryError" />
            </div>
            <ElAlert v-if="statusError" type="error" :title="statusError" show-icon class="panel-alert" />
            <div v-if="channelStatus" class="channel-status">
              <p>已验签回调：{{ channelStatus.last_verified_callback_at || '尚未观察到' }}</p>
              <p>已受理消息：{{ channelStatus.last_accepted_message_at || '尚未观察到' }}</p>
              <p>最近回信：{{ channelStatus.last_delivery_state ? `${deliveryState(channelStatus.last_delivery_state)} · ${channelStatus.last_delivery_state === 'sent' ? channelStatus.last_delivery_at : ''}` : '尚未观察到' }}</p>
              <p v-if="channelStatus.last_callback_error_code">最近回调异常，请联系管理员核对配置</p>
              <p v-if="channelStatus.last_delivery_error_code">最近回信异常，请联系管理员核对配置</p>
            </div>
            <ElAlert v-if="deliveryError" type="error" :title="deliveryError" show-icon class="panel-alert" @close="deliveryError = ''" />
            <ElTable v-loading="deliveryLoading" :data="deliveries" row-key="task_id" empty-text="还没有回信记录">
              <ElTableColumn label="回信状态" width="110"><template #default="{ row }">{{ deliveryState(row.state) }}</template></ElTableColumn>
              <ElTableColumn label="发送次数" prop="attempt_count" width="95" />
              <ElTableColumn label="更新时间" prop="update_time" min-width="165" />
              <ElTableColumn label="操作" width="110"><template #default="{ row }">
                <ElButton v-if="row.state === 'failed' && selectedBinding?.status === 1 && row.channel_revision === selectedBinding.revision"
                  link type="primary" :loading="retryingTaskId === row.task_id" :disabled="Boolean(retryingTaskId) || deliveryRetryBlocked(row.task_id, 'feishu')" @click="retryDelivery(row)">重试回信</ElButton>
              </template></ElTableColumn>
            </ElTable>
            <ElAlert v-if="channelStatus?.last_delivery_state === 'uncertain' || deliveries.some(row => row.state === 'uncertain')"
              type="warning" :closable="false" show-icon class="panel-alert"
              title="回信结果无法确认" description="可能已经送达，系统不会自动重发，也不能重试。请先在对方平台侧核实；刷新状态只读取原投递记录，不会重新发送回信。" />
            <ElPagination v-if="deliveryTotal > 20" class="pager" background layout="prev, pager, next, total"
              :current-page="deliveryPage" :page-size="20" :total="deliveryTotal" @current-change="loadDeliveries" />
          </template>
          <template v-if="selectedBinding.wecom_configured">
            <div class="panel-head">
              <h4>企业微信连接与回信</h4>
              <ElButton :loading="wecomStatusLoading || wecomDeliveryLoading" @click="refreshChannelStatus">刷新状态</ElButton>
            </div>
            <ElAlert v-if="wecomStatusError" type="error" :title="wecomStatusError" show-icon class="panel-alert" />
            <div v-if="wecomStatus" class="channel-status">
              <p>已验签回调：{{ wecomStatus.last_verified_callback_at || '尚未观察到' }}</p>
              <p>已受理消息：{{ wecomStatus.last_accepted_message_at || '尚未观察到' }}</p>
              <p>最近回信：{{ wecomStatus.last_delivery_state ? `${deliveryState(wecomStatus.last_delivery_state)} · ${wecomStatus.last_delivery_at}` : '尚未观察到' }}</p>
              <p v-if="wecomStatus.last_callback_error_code">最近回调异常，请联系管理员核对配置</p>
              <p v-if="wecomStatus.last_delivery_error_code">最近回信异常，请联系管理员核对配置</p>
            </div>
            <ElAlert v-if="wecomDeliveryError" type="error" :title="wecomDeliveryError" show-icon class="panel-alert" />
            <ElTable v-loading="wecomDeliveryLoading" :data="wecomDeliveries" row-key="task_id" empty-text="还没有回信记录">
              <ElTableColumn label="回信状态" width="110"><template #default="{ row }">{{ deliveryState(row.state) }}</template></ElTableColumn>
              <ElTableColumn label="已确认段数" width="110"><template #default="{ row }">{{ row.part_count == null ? '—' : `${row.confirmed_parts}/${row.part_count}` }}</template></ElTableColumn>
              <ElTableColumn label="发送次数" prop="attempt_count" width="95" />
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
        </template>
      </ElDrawer>

      <ElDialog v-model="toolsVisible" title="聊天工具配置" width="min(480px, 92vw)">
        <p class="preview-note">这个渠道已接入聊天工具。凭据只写不回显，保存后只显示是否已配置。</p>
        <ElSpace direction="vertical" style="width: 100%">
          <ElButton v-if="toolsBinding?.feishu_configured" style="width: 100%" @click="openFeishu(toolsBinding!)">飞书配置</ElButton>
          <ElButton v-if="toolsBinding?.wecom_configured" style="width: 100%" @click="openWecom(toolsBinding!)">企业微信配置</ElButton>
        </ElSpace>
      </ElDialog>

      <ElDialog v-model="feishuVisible" :title="`飞书配置 · ${feishuBinding?.name ?? ''}`" width="600px" @closed="closeFeishu">
        <ElAlert type="info" :closable="false" show-icon title="凭据只写，保存后不回显" description="五项凭据每次完整替换。工作负载凭据须来自当前绑定的 SandIAM 调用身份；允许使用仍在 SandIAM 配置。" />
        <p class="preview-note">回调路径：/api/sand-ai/v1/channels/{{ feishuBinding?.code }}/feishu/events</p>
        <ElAlert v-if="feishuError" type="error" :title="feishuError" show-icon class="panel-alert" />
        <ElAlert v-if="pendingFeishu" type="warning" :closable="false" show-icon title="上次保存结果未确认" description="原凭据已清除，只读找回原请求收据；确认前不接受另一组凭据，不再次提交保存。" class="panel-alert" />
        <ElForm label-width="145px" @submit.prevent>
          <ElFormItem label="Encrypt Key"><ElInput v-model="feishuForm.encrypt_key" type="password" show-password :disabled="feishuWriting || !!pendingFeishu" autocomplete="off" /></ElFormItem>
          <ElFormItem label="验证令牌"><ElInput v-model="feishuForm.verification_token" type="password" show-password :disabled="feishuWriting || !!pendingFeishu" autocomplete="off" /></ElFormItem>
          <ElFormItem label="SandIAM 工作负载凭据"><ElInput v-model="feishuForm.workload_credential" type="password" show-password :disabled="feishuWriting || !!pendingFeishu" autocomplete="off" /></ElFormItem>
          <ElFormItem label="应用编号"><ElInput v-model="feishuForm.app_id" :disabled="feishuWriting || !!pendingFeishu" autocomplete="off" /></ElFormItem>
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
          <ElFormItem label="回调令牌"><ElInput v-model="wecomForm.token" type="password" show-password :disabled="wecomWriting || !!pendingWecom" autocomplete="off" /></ElFormItem>
          <ElFormItem label="EncodingAESKey"><ElInput v-model="wecomForm.encoding_aes_key" type="password" show-password :disabled="wecomWriting || !!pendingWecom" autocomplete="off" /></ElFormItem>
          <ElFormItem label="企业编号"><ElInput v-model="wecomForm.corp_id" :disabled="wecomWriting || !!pendingWecom" autocomplete="off" /></ElFormItem>
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
  import { computed, inject, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
  import { ElMessage, ElMessageBox } from 'element-plus'
  import agentDefinitionApi, { type AgentDefinitionRow } from '../../api/agentDefinition'
  import channelBindingApi, { channelBindingInputSha256, channelCredentialInputSha256, provesChannelBindingSnapshot, type ChannelCredentialSaveReference, type ChannelCredentialSaveReceipt, type ChannelCredentialPlatform, type ChannelBindingOperationReference, type ChannelBindingDraft, type ChannelBindingRow, type ChannelDeliveryRow, type FeishuCredentialDraft, type FeishuChannelStatus, type WecomCredentialDraft, type WecomChannelStatus } from '../../api/channelBinding'
  import { requestAgentTrial, requestAgentTrialTask, isAgentTrialTerminal } from '../../api/adminTrial'
  import { newRuntimeRequestId } from '../../api/runtime'
  import { errorDisplayText } from '../../api/errorText'
  import { displayableRuntimeMessage, hintForRuntimeErrorCode } from '../../api/runtimeErrors'
  import iamApi, { type SandIamEnvironmentReference, type SandIamWorkloadClientReference } from '../../api/iam'
  import { AGENT_PAGE_BRIDGE } from '../pageBridge'

  const props = defineProps<{ environment: SandIamEnvironmentReference | null }>()
  const userStore = useUserStore()
  const pageBridge = inject(AGENT_PAGE_BRIDGE, null)
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
      if (isCurrent()) credentialRecoveryError.value = `${errorDisplayText(failure, '原渠道配置结果尚未确认')}；保留原请求，只读找回。`
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
  const deliveryVisible = ref(false)
  const toolsVisible = ref(false)
  const toolsBinding = ref<ChannelBindingRow | null>(null)
  const error = ref('')
  const editingId = ref<number | null>(null)
  const editorVisible = ref(false)
  const editorStep = ref<'type' | 'form'>('type')
  const editorError = ref('')
  const formKind = ref<'api' | 'web' | ''>('')
  const testing = ref(false)
  const testState = ref<'' | 'ok' | 'fail'>('')
  const testError = ref('')
  const submitAttempted = ref(false)
  let epoch = 0
  let directoryEpoch = 0
  let deliveryEpoch = 0
  let wecomDeliveryEpoch = 0
  let statusEpoch = 0
  let wecomStatusEpoch = 0
  let deliverySelectionEpoch = 0
  let deliveryRetryOwner: { selectionEpoch: number } | null = null
  let testEpoch = 0
  const form = reactive({ code: '', name: '', agent_definition_id: null as number | null, workload_client_id: '' })
  function isPublishedAgent(agent: AgentDefinitionRow | undefined): boolean {
    return !!agent && agent.state === 'published' && agent.status === 1 &&
      typeof agent.active_version_id === 'number' && Number.isInteger(agent.active_version_id) && agent.active_version_id > 0 &&
      typeof agent.active_version_no === 'number' && Number.isInteger(agent.active_version_no) && agent.active_version_no > 0
  }
  const publishedAgents = computed(() => agents.value.filter(isPublishedAgent))
  function agentOf(row: ChannelBindingRow): AgentDefinitionRow | undefined {
    return agents.value.find(agent => agent.agent_definition_id === row.agent_definition_id)
  }
  /** 绑定的智能体已不在「已启用」集合时提示；目录未加载到该智能体时不误报。 */
  function agentNeedsEnable(row: ChannelBindingRow): boolean {
    const agent = agentOf(row)
    return !!agent && !isPublishedAgent(agent)
  }
  const selectedAgent = computed(() => agents.value.find(agent => agent.agent_definition_id === form.agent_definition_id && isPublishedAgent(agent)))
  const selectedClient = computed(() => clients.value.find(client => String(client.id) === form.workload_client_id &&
    client.environment_id === props.environment?.id && client.status === 1))
  const canSave = computed(() => !pendingBindingMutation.value && !!currentAdminId.value && !!props.environment &&
    !!form.code.trim() && !!form.name.trim() && !!selectedAgent.value && !!selectedClient.value && !writing.value && !directoryLoading.value)
  /** 新建/编辑弹窗内：名称、智能体、调用身份必填；保存还要求本次会话测试连接通过。 */
  const canSaveDialog = computed(() => canSave.value && testState.value === 'ok' && !testing.value)
  const canTest = computed(() => !!props.environment && !!form.agent_definition_id && !testing.value && !writing.value && !pendingBindingMutation.value)
  const fieldErrors = computed(() => ({
    name: submitAttempted.value && !form.name.trim() ? '请填写渠道名' : '',
    agent: submitAttempted.value && !selectedAgent.value ? '请选择接的智能体' : '',
    client: submitAttempted.value && !selectedClient.value ? '请选择调用身份' : ''
  }))

  /* ---------- 列表筛选与分页（客户端） ---------- */
  const keyword = ref('')
  const statusFilter = ref<string>('')
  const currentPage = ref(1)
  const pageSize = 20
  const filteredRows = computed(() => {
    const text = keyword.value.trim()
    return rows.value.filter(row => {
      if (text && !row.name.includes(text)) return false
      if (statusFilter.value === '1' && row.status !== 1) return false
      if (statusFilter.value === '2' && row.status !== 2) return false
      return true
    })
  })
  const filteredTotal = computed(() => filteredRows.value.length)
  const pagedRows = computed(() => {
    const start = (currentPage.value - 1) * pageSize
    return filteredRows.value.slice(start, start + pageSize)
  })
  function clearFilters(): void {
    keyword.value = ''
    statusFilter.value = ''
    currentPage.value = 1
  }
  watch([keyword, statusFilter], () => { currentPage.value = 1 })
  watch(filteredTotal, totalCount => { if (currentPage.value > Math.max(1, Math.ceil(totalCount / pageSize))) currentPage.value = Math.max(1, Math.ceil(totalCount / pageSize)) })
  /** 渠道类型由渠道代码前缀区分：后端无类型字段，web- 前缀表示网页对话，其余为接口调用。 */
  function channelKindLabel(row: ChannelBindingRow): string {
    return row.code.startsWith('web-') ? '网页对话' : '接口调用'
  }
  function generateChannelCode(kind: 'api' | 'web'): string {
    const prefix = kind === 'web' ? 'web' : 'api'
    return `${prefix}-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 8)}`
  }
  function resetTestState(): void {
    ++testEpoch
    testing.value = false
    testState.value = ''
    testError.value = ''
  }
  function openCreate(): void {
    if (!props.environment || writing.value) return
    editingId.value = null
    editorStep.value = 'type'
    editorError.value = ''
    formKind.value = ''
    resetTestState()
    submitAttempted.value = false
    Object.assign(form, { code: '', name: '', agent_definition_id: null, workload_client_id: '' })
    editorVisible.value = true
  }
  defineExpose({ openCreate })
  function openToolsMenu(row: ChannelBindingRow): void {
    toolsBinding.value = row
    toolsVisible.value = true
  }
  function goEnableAgent(row: ChannelBindingRow): void {
    pageBridge?.requestEnableAgent(row.agent_code)
  }

  function openFeishu(row: ChannelBindingRow): void {
    if (pendingFeishu.value && (pendingFeishu.value.user_id !== currentAdminId.value ||
      pendingFeishu.value.environment_id !== props.environment?.id ||
      pendingFeishu.value.channel_binding_id !== row.channel_binding_id)) {
      ElMessage.warning(`请使用原管理员身份回到原环境，确认原渠道的飞书配置保存结果`)
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
        ? `${errorDisplayText(failure, '配置保存结果未知')}；已保留原请求，只读找回，不再次保存。`
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
      ElMessage.warning(`请使用原管理员身份回到原环境，确认原渠道的企业微信配置保存结果`)
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
    } catch (failure) { if (current === epoch) error.value = errorDisplayText(failure, '渠道列表读取失败') }
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
      agents.value.push(...result.data)
      agentPage.value += 1
      agentMore.value = agentPage.value * 100 < result.total
    } catch (failure) { if (current === epoch && directory === directoryEpoch) error.value = errorDisplayText(failure, '智能体列表读取失败') }
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
    } catch (failure) { if (current === epoch && directory === directoryEpoch) error.value = errorDisplayText(failure, '调用身份读取失败') }
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
    editorStep.value = 'form'
    editorError.value = ''
    formKind.value = row.code.startsWith('web-') ? 'web' : 'api'
    resetTestState()
    submitAttempted.value = false
    Object.assign(form, { code: row.code, name: row.name, agent_definition_id: row.agent_definition_id, workload_client_id: row.workload_client_id })
    editorVisible.value = true
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
    deliveryVisible.value = true
    if (row.feishu_configured) {
      void loadStatus()
      void loadDeliveries(1)
    }
    if (row.wecom_configured) {
      void loadWecomStatus()
      void loadWecomDeliveries(1)
    }
  }
  function refreshChannelStatus(): void {
    const binding = selectedBinding.value
    if (binding?.feishu_configured) {
      void loadStatus()
      void loadDeliveries(1)
    }
    if (binding?.wecom_configured) {
      void loadWecomStatus()
      void loadWecomDeliveries(1)
    }
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
      if (current === statusEpoch) statusError.value = errorDisplayText(failure, '渠道状态读取失败')
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
      if (current === wecomStatusEpoch && selectedBinding.value?.channel_binding_id === result.channel_binding_id) wecomStatus.value = result
    } catch (failure) {
      if (current === wecomStatusEpoch) wecomStatusError.value = errorDisplayText(failure, '企业微信状态读取失败')
    } finally {
      if (current === wecomStatusEpoch) wecomStatusLoading.value = false
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
      if (current === deliveryEpoch) deliveryError.value = errorDisplayText(failure, '回信状态读取失败')
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
      if (current === wecomDeliveryEpoch) wecomDeliveryError.value = errorDisplayText(failure, '企业微信回信读取失败')
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
      if (stillCurrent()) deliveryRecoveryError.value = `${errorDisplayText(failure, '原重试回执尚未找回')}；仍保留原请求，请勿再次重试。`
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
          ? '只发送尚未确认的企业微信回信段；结果不明的回信不可重试。提交结果未知时只查看原请求结果，不重复发送。'
          : '将使用原任务和同一条飞书消息标识重新发送。提交结果未知时只查看原请求结果，不重复发送。',
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
        throw new Error('重试受理回执与原任务不一致，请查看原请求结果。')
      if (!await acknowledgeDeliveryRetry(reference, stillCurrent) || !stillCurrent()) return
      ElMessage.success('回信重试已受理，当前发送状态以回信记录为准')
      await refreshRetryDelivery(reference)
    } catch (failure) {
      if (stillCurrent()) {
        const message = `${errorDisplayText(failure, '回信重试提交结果未确认')}；请查看原请求结果，不要再次发送。`
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

  /** 连接测试：用管理员试用身份对所选智能体发一条探测，验证「智能体可正常回答」这条链路。 */
  async function testConnection(): Promise<void> {
    const environment = props.environment
    const agent = selectedAgent.value
    const agentId = form.agent_definition_id
    if (!environment || !agent || agentId === null || testing.value || writing.value || pendingBindingMutation.value) return
    const current = ++testEpoch
    const epochSnapshot = epoch
    testing.value = true
    testState.value = ''
    testError.value = ''
    editorError.value = ''
    try {
      const probe = '请只回复“连接正常”四个字。'
      const key = newRuntimeRequestId('channel-test')
      const result = await requestAgentTrial(
        { application_id: environment.application_id, agent_id: agentId, query: probe },
        key
      )
      if (current !== testEpoch || epoch !== epochSnapshot || props.environment?.id !== environment.id) return
      let final = result
      // 服务端最长原地等待约 25 秒；仍非终态时短轮询到终态或超时。
      const deadline = Date.now() + 45000
      while (!isAgentTrialTerminal(final.state) && Date.now() < deadline) {
        await new Promise(resolve => setTimeout(resolve, 2000))
        if (current !== testEpoch || epoch !== epochSnapshot) return
        final = await requestAgentTrialTask(final.task_id)
      }
      if (current !== testEpoch || epoch !== epochSnapshot) return
      if (final.state === 'succeeded') {
        testState.value = 'ok'
        ElMessage.success('连接正常')
      } else if (!isAgentTrialTerminal(final.state)) {
        testState.value = 'fail'
        testError.value = '智能体还在处理，稍后再点一次「测试连接」确认。'
      } else {
        testState.value = 'fail'
        const code = typeof final.error_code === 'string' ? final.error_code : ''
        testError.value = (code ? hintForRuntimeErrorCode(code) : undefined)
          ?? '这次试用没有成功，请检查智能体和模型服务商后重试。'
      }
    } catch (failure) {
      if (current !== testEpoch || epoch !== epochSnapshot) return
      testState.value = 'fail'
      const text = failure instanceof Error ? displayableRuntimeMessage(failure.message) : undefined
      testError.value = text ?? errorDisplayText(failure, '连接测试没有成功，请稍后重试')
    } finally {
      if (current === testEpoch) testing.value = false
    }
  }

  async function refreshBindingPanel(): Promise<void> {
    const identity = bindingIdentityEpoch
    await load(1)
    if (identity !== bindingIdentityEpoch || bindingRecoveringKey.value) return
    readBindingJournal()
    for (const item of currentBindingRecoveries.value) {
      if (identity !== bindingIdentityEpoch) return
      await confirmBindingMutation(item)
    }
    void refreshBindingDirectory()
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
      if (isCurrent()) error.value = `${errorDisplayText(failure, '原操作结果未确认')}；保留原请求，只读找回。`
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
      ElMessage.success(operation === 'save' ? '渠道已保存' : '渠道已停用')
      await load(1)
    } catch (failure) {
      if (identity === bindingIdentityEpoch && token === userStore.accessToken &&
        (!reference || bindingIdentityCurrent(reference, identity, token))) {
        error.value = `${errorDisplayText(failure, '渠道操作结果未确认')}；保留原请求，只读找回。`
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
    submitAttempted.value = true
    if (!environment || !canSaveDialog.value || form.agent_definition_id === null) return
    // 新建时按所选类型生成代码前缀；编辑沿用原代码，类型不变。
    const code = editingId.value ? form.code.trim() : generateChannelCode(formKind.value === 'web' ? 'web' : 'api')
    const input: ChannelBindingDraft = Object.freeze({ environment_id: environment.id, code, name: form.name.trim(),
      agent_definition_id: form.agent_definition_id, application_id: String(environment.application_id).trim(), workload_client_id: form.workload_client_id.trim() })
    await submitBindingMutation('save', input)
    if (!error.value) {
      editorVisible.value = false
      editorStep.value = 'type'
    }
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
      try { await ElMessageBox.confirm(`停用「${target.name}」后，这个渠道里的人问了不会再有回答。`, '停用渠道', { type: 'warning', confirmButtonText: '停用' }) }
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
    invalidateDeliverySelection()
    ++statusEpoch; channelStatus.value = null; statusError.value = ''; statusLoading.value = false
    ++wecomStatusEpoch; wecomStatus.value = null; wecomStatusError.value = ''; wecomStatusLoading.value = false
    // Keep unknown credential mutations with their original environment, binding and key.
    feishuVisible.value = false; closeFeishu(); feishuBinding.value = null
    wecomVisible.value = false; closeWecom(); wecomBinding.value = null
    editorVisible.value = false; deliveryVisible.value = false; toolsVisible.value = false; toolsBinding.value = null
    ++epoch; ++deliveryEpoch; ++wecomDeliveryEpoch; rows.value = []; agents.value = []; clients.value = []; total.value = 0; directoryLoading.value = false
    selectedBinding.value = null; deliveries.value = []; deliveryTotal.value = 0; deliveryError.value = ''
    wecomDeliveries.value = []; wecomDeliveryTotal.value = 0; wecomDeliveryError.value = ''
    agentPage.value = 0; clientPage.value = 0; agentMore.value = false; clientMore.value = false
    keyword.value = ''; statusFilter.value = ''; currentPage.value = 1
    resetTestState()
    Object.assign(form, { code: '', name: '', agent_definition_id: null, workload_client_id: '' })
    editingId.value = null; editorStep.value = 'type'; formKind.value = ''
    void load(1); void loadAgents(); void loadClients()
  }, { immediate: true, flush: 'sync' })
  watch(() => selectedBinding.value?.revision, invalidateDeliverySelection, { flush: 'sync' })
  watch([form.agent_definition_id, form.workload_client_id, form.name], () => { resetTestState() })
  function observeRetryJournal(event: StorageEvent): void {
    if (event.key === deliveryRetryJournalKey || event.key === null) readDeliveryRetryJournal()
    if (event.key === credentialJournalKey || event.key === null) readCredentialJournal()
  }
  watch([currentAdminId, () => userStore.accessToken], () => {
    ++deliveryIdentityEpoch
    platformReset()
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
    editorVisible.value = false; deliveryVisible.value = false
    keyword.value = ''; statusFilter.value = ''; currentPage.value = 1
    resetTestState()
    Object.assign(form, { code: '', name: '', agent_definition_id: null, workload_client_id: '' })
    editingId.value = null; editorStep.value = 'type'; formKind.value = ''
    readDeliveryRetryJournal()
    if (currentAdminId.value && userStore.accessToken) { void load(1); void loadAgents(); void loadClients() }
  }, { flush: 'sync' })
  function platformReset(): void {
    feishuVisible.value = false; closeFeishu(); feishuBinding.value = null; feishuError.value = ''
    wecomVisible.value = false; closeWecom(); wecomBinding.value = null; wecomError.value = ''
  }
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
    platformReset()
    readCredentialJournal()
  }, { immediate: true, flush: 'sync' })
  readDeliveryRetryJournal()
  onMounted(() => { window.addEventListener('storage', observeRetryJournal) })
  onUnmounted(() => { ++credentialIdentityEpoch; credentialBodies.clear(); credentialWriteOwners.feishu = null; credentialWriteOwners.wecom = null; credentialReadOwner = null; ++bindingIdentityEpoch; window.removeEventListener('storage', observeRetryJournal); invalidateDeliverySelection(); ++testEpoch })
</script>

<style scoped lang="scss">
  .panel-head { display: flex; justify-content: space-between; align-items: center; gap: 12px; }
  .panel-head h3 { margin: 0; }
  .panel-head p, .preview-note { color: var(--el-text-color-secondary); font-size: 13px; }
  .panel-alert, .pager { margin: 14px 0; }
  .filter-row { display: flex; gap: 10px; margin: 12px 0; }
  .field-error { color: var(--el-color-danger); font-size: 12px; margin-top: 4px; }
  .empty-state { padding: 28px 0; text-align: center; }
  .empty-title { margin: 0 0 6px; font-size: 15px; color: var(--el-text-color-primary); }
  .empty-desc { margin: 0 0 14px; color: var(--el-text-color-secondary); font-size: 13px; }
  .type-cards { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
  .type-card { display: flex; flex-direction: column; gap: 6px; padding: 16px; border: 1px solid var(--el-border-color); border-radius: 8px; background: var(--el-fill-color-blank); cursor: pointer; text-align: left; }
  .type-card strong { font-size: 15px; }
  .type-card span { color: var(--el-text-color-secondary); font-size: 12px; }
  .type-card--active { border-color: var(--el-color-primary); background: var(--el-color-primary-light-9); }
  .type-note { margin: 12px 0 0; color: var(--el-text-color-secondary); font-size: 12px; }
  .drawer-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 16px; }
  .agent-warn { display: block; color: var(--el-color-warning); font-size: 12px; }
  .channel-status { padding: 8px 0; color: var(--el-text-color-regular); }
  .channel-status p { margin: 5px 0; }
</style>
