<template>
  <div class="art-full-height">
    <ElCard shadow="never">
      <div class="heading">
        <div>
          <h2>开始使用 SandAI</h2>
          <p>应用可直接选用已授权的聊天模型，无需模型 API Key。管理员需要新增能力时，再选择应用和环境，配置本地解析或连接模型服务。</p>
        </div>
        <ElSpace wrap>
          <ElButton type="primary" @click="openCapabilityConfiguration">先配置本地解析能力</ElButton>
          <ElButton @click="router.push('/sand-ai/provider')">服务商配置</ElButton>
        </ElSpace>
      </div>

      <p class="muted">本地解析可读取 TXT、文本型 PDF、DOCX 等，不调用模型。材料 AI 抽取与样本验证仍需要已发布模型和 SandIAM 服务授权；业务应用调用时无需持有模型 API Key。</p>
      <ElAlert v-if="navigationNotice" :title="navigationNotice" type="warning" :closable="false" class="notice" />
      <ElAlert v-if="message" :type="messageType" :title="message" show-icon :closable="false" class="notice" />
      <details class="section">
        <summary>使用已授权聊天能力（无需模型 API Key）</summary>
        <p class="muted">填写应用提供的 SandIAM 短期运行身份，读取当前凭证可用的模型并明确选择。后台登录及本页应用、环境选择不会代替调用授权；没有可用模型时，请管理员发布所需能力路线并在 SandIAM 配置授权。</p>
        <ChatRouteTrialPanel :environment-id="environmentId || undefined" />
      </details>
      <section class="section">
        <h3>准备 SandIAM 服务目录</h3>
        <p class="muted">先确认身份服务可用，再由管理员登记 SandAI 所需的服务动作。登记不会创建应用、凭证或授权。</p>
        <ElAlert v-if="catalogMessage" :type="catalogMessageType" :title="catalogMessage" show-icon :closable="false" class="catalog-notice" />
        <p v-if="catalogPreflight?.port_available" class="muted">
          已读取 SandIAM 服务目录状态。登记仅补齐缺失项，已有登记可安全重复提交。
        </p>
        <p v-if="catalogPreflight?.action_codes.length" class="muted">
          {{ catalogPreflight.service_code }} 服务动作（{{ catalogPreflight.action_codes.length }} 项）：{{ catalogPreflight.action_codes.join('、') }}
        </p>
        <ElSpace v-if="catalogDisabled" wrap>
          <ElButton @click="router.push('/sand-iam/service')">查看 SandIAM 平台服务</ElButton>
          <ElButton @click="router.push('/sand-iam/action')">查看 SandIAM 服务动作</ElButton>
        </ElSpace>
        <ElSpace wrap>
          <ElButton :loading="catalogBusy === 'checking'" :disabled="Boolean(catalogBusy)" @click="loadCatalog">检查 SandIAM 连接</ElButton>
          <ElButton type="primary" :loading="catalogBusy === 'registering'" :disabled="Boolean(catalogBusy) || !canRegisterCatalog(catalogPreflight) || catalogDisabled || catalogRegistered" @click="registerCatalog">
            {{ catalogRegistered ? '本次已登记' : '登记 SandAI 服务动作' }}
          </ElButton>
        </ElSpace>
      </section>
      <section class="section">
        <h3>个人 Skill 服务授权准备</h3>
        <p class="muted">Skill 的运行、读取、管理、测试和发布服务动作单独登记。登记后，还需在 SandIAM 为应用和客户端配置授权；此操作不会授予任何调用权限。</p>
        <ElAlert v-if="skillCatalogMessage" :type="skillCatalogMessageType" :title="skillCatalogMessage" show-icon :closable="false" class="catalog-notice" />
        <p v-if="skillCatalogPreflight?.action_codes.length" class="muted">
          {{ skillCatalogPreflight.service_code }} 服务动作（{{ skillCatalogPreflight.action_codes.length }} 项）：{{ skillCatalogPreflight.action_codes.join('、') }}
        </p>
        <ElSpace v-if="skillCatalogDisabled" wrap>
          <ElButton @click="router.push('/sand-iam/service')">查看 SandIAM 平台服务</ElButton>
          <ElButton @click="router.push('/sand-iam/action')">查看 SandIAM 服务动作</ElButton>
        </ElSpace>
        <ElSpace wrap>
          <ElButton :loading="skillCatalogBusy === 'checking'" :disabled="Boolean(skillCatalogBusy)" @click="loadSkillCatalog">检查 Skill 服务动作</ElButton>
          <ElButton type="primary" :loading="skillCatalogBusy === 'registering'" :disabled="Boolean(skillCatalogBusy) || !canRegisterCatalog(skillCatalogPreflight) || skillCatalogDisabled || skillCatalogRegistered" @click="registerSkillCatalog">
            {{ skillCatalogRegistered ? '本次已登记' : '登记 Skill 服务动作' }}
          </ElButton>
        </ElSpace>
      </section>
      <section class="section">
        <h3>音频服务授权准备</h3>
        <p class="muted">音频转写、片段复核和语音合成动作在这里显式登记。登记后，还需在 SandIAM 为应用和客户端配置授权；此操作不会授予任何调用权限。</p>
        <ElAlert v-if="audioCatalogMessage" :type="audioCatalogMessageType" :title="audioCatalogMessage" show-icon :closable="false" class="catalog-notice" />
        <p v-if="audioCatalogPreflight?.action_codes.length" class="muted">
          {{ audioCatalogPreflight.service_code }} 服务动作（{{ audioCatalogPreflight.action_codes.length }} 项）：{{ audioCatalogPreflight.action_codes.join('、') }}
        </p>
        <ElSpace v-if="audioCatalogDisabled" wrap>
          <ElButton @click="router.push('/sand-iam/service')">查看 SandIAM 平台服务</ElButton>
          <ElButton @click="router.push('/sand-iam/action')">查看 SandIAM 服务动作</ElButton>
        </ElSpace>
        <ElSpace wrap>
          <ElButton :loading="audioCatalogBusy === 'checking'" :disabled="Boolean(audioCatalogBusy)" @click="loadAudioCatalog">检查音频服务动作</ElButton>
          <ElButton type="primary" :loading="audioCatalogBusy === 'registering'" :disabled="Boolean(audioCatalogBusy) || !canRegisterCatalog(audioCatalogPreflight) || audioCatalogDisabled || audioCatalogRegistered" @click="registerAudioCatalog">
            {{ audioCatalogRegistered ? '本次已登记' : '登记音频服务动作' }}
          </ElButton>
        </ElSpace>
        <h4>转写引擎</h4>
        <p v-if="audioRuntime" class="muted">
          应用接口已安装；本地引擎：{{ audioAdapterState('local') }}，外部引擎：{{ audioAdapterState('external') }}。
          {{ audioRuntime.readiness_note }}
        </p>
        <p v-if="audioRuntimeError" class="muted">{{ audioRuntimeError }}</p>
        <ElButton :loading="audioRuntimeBusy" @click="loadAudioRuntime">检查引擎配置</ElButton>
        <TranscriptionDeploymentSetup
          :audio-runtime="audioRuntime"
          :audio-runtime-busy="audioRuntimeBusy"
          :audio-runtime-error="audioRuntimeError"
          @refresh-runtime="loadAudioRuntime"
        />
        <SpeechDeploymentSetup />
        <SpeechTrialPanel :environment-id="environmentId || null" />
        <EmbeddingDeploymentSetup />
        <OcrDeploymentSetup />
      </section>
      <ElSkeleton v-if="loading" :rows="5" animated />
      <ElResult v-else-if="!guide" icon="error" title="无法读取首次配置引导" :sub-title="guideError || '请稍后重试。'">
        <template #extra><ElButton @click="load">重试</ElButton></template>
      </ElResult>
      <template v-else>
        <ElSteps :active="step" finish-status="success" class="steps">
          <ElStep title="选择场景" />
          <ElStep title="连接服务商" />
          <ElStep title="验证并发布模型" />
          <ElStep :title="`配置${guide.display_name}服务`" />
        </ElSteps>

        <section class="section">
          <h3>1. 工作目标</h3>
          <ElRadioGroup v-model="selectedScene">
            <ElRadio v-for="item in solutionGuides" :key="item.technical.code" :value="item.technical.code">{{ item.display_name }}</ElRadio>
          </ElRadioGroup>
          <p>{{ guide.plain_summary }}</p>
          <p class="muted">{{ guide.when_to_use }}</p>
          <p>需要准备：</p>
          <ul><li v-for="item in guide.what_you_need" :key="item">{{ item }}</li></ul>
          <p>配置步骤：</p>
          <ol><li v-for="item in guide.setup_steps" :key="item">{{ item }}</li></ol>
          <p>完成后：{{ guide.outcome }}</p>
          <p class="muted">{{ guide.readiness_note }}</p>
          <p class="muted">切换工作目标只调整本页引导，已保存的模型与环境配置会保留；各项配置仍需明确保存和发布。</p>
          <template v-if="iamError">
            <p class="muted">应用或环境暂时无法读取。模型连接可以继续，业务环境请稍后在能力配置中选择。</p>
            <ElButton v-if="!applicationId" :loading="loadingIam" @click="loadApplications">重新读取应用</ElButton>
            <ElButton v-else :loading="loadingIam" @click="loadEnvironments">重新读取环境</ElButton>
          </template>
          <ElForm v-else label-width="90px" class="narrow">
            <ElFormItem label="应用">
              <ElSelect v-model="applicationId" clearable filterable placeholder="可选，选择 SandIAM 应用" :loading="loadingIam" :disabled="connectionSubmitting || bindingSubmitting || busy === 'bind'" @change="loadEnvironments">
                <ElOption v-for="app in applications" :key="app.id" :label="app.name" :value="app.id" />
              </ElSelect>
            </ElFormItem>
            <ElFormItem label="环境">
              <ElSelect v-model="environmentId" clearable filterable placeholder="可选，稍后绑定服务" :disabled="!applicationId || connectionSubmitting || bindingSubmitting || busy === 'bind'" :loading="loadingIam">
                <ElOption v-for="env in environments" :key="env.id" :label="env.name" :value="env.id" />
              </ElSelect>
            </ElFormItem>
          </ElForm>
          <p class="muted">选择应用和环境仅用于确认目标，不会自动绑定或发布业务能力。</p>
        </section>

        <section class="section">
          <ElAlert v-if="journey" type="info" :closable="false" :title="`原开通目标：应用 ${journey.application_id ?? '未选'}，环境 ${journey.environment_id ?? '未选'}；${journey.deployment_id ? `部署 ${journey.deployment_id} · 修订 ${journey.revision}` : '保存回执待找回'}。此目标不代表环境绑定或服务授权。`" />
          <ElAlert v-if="connectionSubmitting" type="info" :closable="false" title="原连接操作仍在处理。切换登录状态不会解除在途锁；请等待原操作结束，再读取状态或配置。" />
          <ElAlert v-else-if="journeyLocked" type="warning" :closable="false" title="原开通状态尚未确认，已锁定新增保存、验证、发布和换目标。请只读找回原开通状态。" />
          <h3>2. 服务商连接</h3>
          <p class="muted">此处供管理员新增模型连接。应用选用已有授权能力时，可直接使用上方聊天入口；其他能力沿“能力配置”选择已发布部署，不需要再次输入服务商 Key。</p>
          <ElSelect v-model="selectedProviderCode" class="narrow provider-select" placeholder="选择服务商" :disabled="Boolean(draft || saveAttempt || journeyLocked)">
            <ElOption v-for="item in providerGuides" :key="item.provider_code || item.adapter" :label="item.name" :value="item.provider_code || item.adapter" />
          </ElSelect>
          <p>{{ providerGuide?.api_key_help || '在服务商控制台创建 API Key。' }}</p>
          <p v-if="providerGuide?.api_key_url && safeGuideUrl(providerGuide.api_key_url)"><a :href="safeGuideUrl(providerGuide.api_key_url)" target="_blank" rel="noopener noreferrer">前往服务商创建 API Key</a></p>
          <p v-if="providerGuide?.billing_help_url && safeGuideUrl(providerGuide.billing_help_url)"><a :href="safeGuideUrl(providerGuide.billing_help_url)" target="_blank" rel="noopener noreferrer">查看服务商计费说明</a></p>
          <p v-else class="muted">服务商未提供计费说明链接。请在所选服务商控制台查看当前模型的计费规则。</p>
          <p class="muted">连接前请核对所选模型的输入 Token、输出 Token 计量单位、单价和币种；其他能力按服务商公布的实际单位核对。</p>
          <p class="muted">调用费用按本次调用冻结的价格版本和实际用量记录。未配置价格时费用未计价，缺少用量或币种时保持未知；预估金额、计量金额和对账金额分开查看。对账金额需依据真实服务商账单核对，人工补录保留来源与核对状态。</p>
          <ElSpace wrap>
            <ElButton @click="router.push('/sand-ai/usage')">查看费用与账单</ElButton>
            <span class="muted">模型验证后查看“模型验证费用”；应用调用查看“调用费用”，在“账单核对”核查实际收费。查看费用需要相应后台权限。</span>
          </ElSpace>
          <p class="muted">{{ providerGuide?.data_egress_notice }}</p>
          <p class="muted">{{ providerGuide?.discovery_notice }}</p>
          <ElAlert v-if="connectionNotice" :title="connectionNotice" type="info" :closable="false" class="catalog-notice" />
          <ElAlert v-if="connectionInputError" :title="connectionInputError" type="warning" :closable="false" class="catalog-notice" />
          <ElForm label-width="110px" class="narrow" @submit.prevent>
            <ElFormItem label="服务商名称">
              <ElInput v-model.trim="providerName" maxlength="80" placeholder="例如：我的模型服务" :disabled="Boolean(draft || saveAttempt || journeyLocked)" />
              <span v-if="providerNameError" class="muted">{{ providerNameError }}</span>
            </ElFormItem>
            <ElFormItem label="服务地址"><ElInput v-model="baseUrl" placeholder="https://服务商提供的 API 基础地址" :disabled="Boolean(draft || saveAttempt || journeyLocked)" /></ElFormItem>
            <ElFormItem label="API Key" required>
              <ElInput ref="apiKeyInput" v-model="apiKey" type="password" show-password autocomplete="off" aria-required="true" aria-describedby="onboarding-api-key-help" placeholder="只在本次连接中使用" :disabled="Boolean(draft || saveAttempt || journeyLocked)" />
              <span id="onboarding-api-key-help" class="muted">{{ apiKeyWhitespaceError || '必填；请使用服务商提供的 API Key，仅在本次连接中使用。' }}</span>
            </ElFormItem>
            <ElFormItem v-if="!draft" label="模型请求超时">
              <ElInputNumber v-model="providerTimeoutSeconds" :min="1" :max="120" :step="1" :precision="0" :disabled="Boolean(draft || saveAttempt || journeyLocked)" />
              <span class="muted">秒，默认 120 秒。保存后用于此服务商的模型验证与调用；超时可能已产生费用，请先核对调用记录。</span>
            </ElFormItem>
            <ElCollapse v-if="supportsExtraHeaders">
              <ElCollapseItem title="额外请求头（可选）" name="extra-headers">
                <p class="muted">仅在服务商要求时填写组织、项目标识或额外密钥。保存后不会回显，也不会保存在浏览器中。</p>
                <ElFormItem v-for="field in extraHeaderFields" :key="field.name" :label="field.label">
                  <ElInput v-model="extraHeaders[field.name]" type="password" show-password autocomplete="off"
                    :placeholder="field.name" :disabled="Boolean(draft || saveAttempt || journeyLocked)" />
                </ElFormItem>
                <p v-if="extraHeaderError" class="muted">{{ extraHeaderError }}</p>
              </ElCollapseItem>
            </ElCollapse>
            <ElFormItem>
              <ElButton :loading="busy === 'discover'" :disabled="Boolean(busy || draft || saveAttempt || verificationRecoveryPending || verificationStorageError || journeyLocked) || !canConnect" @click="discover">发现模型</ElButton>
            </ElFormItem>
            <ElFormItem label="模型">
              <ElSelect v-if="models.length" v-model="remoteModel" filterable allow-create default-first-option placeholder="选择或输入模型标识" :disabled="Boolean(draft || saveAttempt || journeyLocked)">
                <ElOption v-for="model in models" :key="model.remote_model" :label="model.remote_model" :value="model.remote_model" />
              </ElSelect>
              <ElInput v-else v-model="remoteModel" maxlength="256" placeholder="发现不可用时，手动填写服务商给出的模型标识" :disabled="Boolean(draft || saveAttempt || journeyLocked)" />
              <span class="muted">请选用支持聊天的模型。模型列表不证明聊天或材料抽取能力，保存后仍须验证。</span>
              <span v-if="modelIdentifierError" class="muted">{{ modelIdentifierError }}</span>
            </ElFormItem>
            <ElFormItem label="模型名称">
              <ElInput v-model.trim="modelName" maxlength="160" :placeholder="defaultModelName || '可选，用于后台识别此模型'" :disabled="Boolean(draft || saveAttempt || journeyLocked)" />
              <span class="muted">可选；留空时使用上方模型标识作为后台名称，过长时仅缩短名称，调用仍使用完整模型标识。</span>
              <span v-if="modelNameError" class="muted">{{ modelNameError }}</span>
            </ElFormItem>
            <ElFormItem label="材料抽取">
              <ElCheckbox v-model="materialExtraction" :disabled="Boolean(draft || saveAttempt || journeyLocked)">
                将此聊天模型作为材料结构化抽取候选
              </ElCheckbox>
              <span class="muted">聊天验证不证明材料抽取质量；材料配置须运行真实样本并核验后才可发布。</span>
            </ElFormItem>
            <ElFormItem label="输出上限">
              <ElInputNumber v-model="maxOutputTokens" :min="1" :max="131072" :step="1" :disabled="Boolean(draft || saveAttempt || journeyLocked)" />
            </ElFormItem>
            <ElFormItem label="方法包输出">
              <ElSelect v-model="skillOutputStrategy" aria-label="方法包输出策略" :disabled="Boolean(draft || saveAttempt || journeyLocked)">
                <ElOption v-for="(label, value) in skillStrategyLabels" :key="value" :label="label" :value="value" />
              </ElSelect>
              <span class="muted">默认要求完整 JSON。JSON 对象需模型服务支持 json_object 协议，JSON Schema 需支持 OpenAI json_schema 协议；选择后仍须通过方法包案例评测。</span>
              <span class="muted">仅新建方法包版本采用此配置，已冻结版本和历史调用保持原策略。</span>
            </ElFormItem>
            <ElFormItem label="推理额度">
              <ElSelect v-model="reasoningEffort" aria-label="推理额度" :disabled="Boolean(draft || saveAttempt || journeyLocked)">
                <ElOption label="由模型服务决定（默认）" value="" />
                <ElOption label="低" value="low" />
                <ElOption label="高" value="high" />
                <ElOption label="最大" value="max" />
              </ElSelect>
              <span class="muted">控制模型用于推理的额度。仅在模型服务明确支持时选择低、高或最大；默认不指定，具体效果以该模型说明为准。</span>
              <span class="muted">保存请求和修订会锁定所选额度。修改配置后须重新保存、验证和发布。</span>
            </ElFormItem>
            <ElFormItem>
              <ElButton type="primary" :loading="busy === 'save'" :disabled="Boolean(busy || draft || verificationRecoveryPending || verificationStorageError || journeyLocked) || (!saveAttempt && !canSave)" @click="saveDraft">保存模型草稿</ElButton>
              <ElButton v-if="journey || journeyLocked" :loading="busy === 'read'" :disabled="Boolean(busy)" @click="restoreJourney">只读找回原开通状态</ElButton>
            </ElFormItem>
          </ElForm>
          <p class="muted">发现模型仅查询列表；保存成功或离开页面后 Key 会清空，不会保存在浏览器存储中。</p>
        </section>

        <section class="section">
          <h3>已保存模型配置（只读）</h3>
          <p class="muted">按已发布模型名称选择部署与修订，读取原保存值。此处不重新验证、保存、发布或绑定环境。</p>
          <ElForm label-width="110px" class="narrow" @submit.prevent>
            <ElFormItem label="名称筛选"><ElInput v-model.trim="inspectionSearchName" placeholder="可选，输入模型或服务商名称后读取部署列表" :disabled="inspectionLoading || inspectionReading" /></ElFormItem>
            <ElFormItem label="已发布模型">
              <ElSelect v-model="inspectionDeploymentId" filterable placeholder="先读取部署列表，再选择名称与修订" :loading="inspectionLoading" :disabled="inspectionLoading || inspectionReading" @change="chooseInspectionDeployment">
                <ElOption v-for="item in inspectionDeployments" :key="item.id" :label="`${item.display_name} · 修订 ${item.deployment_revision}`" :value="item.id" />
              </ElSelect>
              <ElButton :loading="inspectionLoading" :disabled="inspectionReading" @click="loadInspectionDeployments">读取部署列表</ElButton>
            </ElFormItem>
            <ElFormItem>
              <ElButton :loading="inspectionReading" :disabled="inspectionLoading || !validInspectionTarget" @click="readInspectionRevision">读取所选修订</ElButton>
            </ElFormItem>
            <ElCollapse>
              <ElCollapseItem title="按保存回执编号读取历史修订（专家备用）" name="inspection-reference">
                <p class="muted">已发布列表展示当前修订。读取历史修订时，使用原保存回执中的部署号与修订号；不会猜测或创建配置。</p>
                <ElFormItem label="部署号"><ElInputNumber v-model="inspectionTargetId" :min="1" :precision="0" :disabled="inspectionReading" aria-label="已有部署号" /></ElFormItem>
                <ElFormItem label="修订号"><ElInputNumber v-model="inspectionTargetRevision" :min="1" :precision="0" :disabled="inspectionReading" aria-label="已有修订号" /></ElFormItem>
              </ElCollapseItem>
            </ElCollapse>
          </ElForm>
          <template v-if="inspectedRevision">
            <p>部署 {{ inspectedRevision.deployment_id }} · 修订 {{ inspectedRevision.revision }} · {{ inspectedRevision.state === 'published' ? '已发布（只读）' : '保存值（只读）' }}</p>
            <p>{{ inspectedRevision.provider.name }} · {{ inspectedRevision.model.name }} · {{ inspectedRevision.deployment.remote_model }}</p>
            <p>输出上限：{{ inspectedRevision.deployment.config.max_output_tokens }}</p>
            <p>方法包输出：{{ inspectedRevision.deployment.config.skill_output_strategy ? skillStrategyLabels[inspectedRevision.deployment.config.skill_output_strategy] : '未指定（新方法包采用标准输出，历史版本保持原策略）' }}</p>
            <p class="muted">显示值来自指定修订的冻结配置。未指定策略的旧修订保持缺字段；新建配置的默认选择不会写回这里。</p>
          </template>
        </section>

        <section class="section">
          <h3>3. 验证并发布模型连接</h3>
          <p v-if="draft">部署 {{ draft.deployment_id }} · 修订 {{ draft.revision }} · {{ verificationStateLabel }}</p>
          <p v-if="verificationRequest" class="muted">原验证请求号：{{ verificationRequest.key }} · 部署 {{ verificationRequest.deployment_id }} · 修订 {{ verificationRequest.revision }}。此号用于找回本次验证，与费用明细中的尝试 ID 不同。</p>
          <ElAlert v-if="verificationRecoveryPending" type="warning" :closable="false" title="原验证结果尚未确认。本页保留原请求号，暂时锁定新保存、验证和重新配置；只读找回不会再次调用模型。" />
          <ElAlert v-if="verificationStorageError" type="warning" :closable="false" title="无法确认本机会话中的原验证记录，暂时不能发起新验证。请保留原部署与修订号，由管理员核查；不会换请求号重发。" />
          <ElButton v-if="verificationRecoveryPending || verificationStorageError || journeyLocked" :loading="busy === 'read'" :disabled="Boolean(busy)" @click="restoreVerificationRequest">找回原验证状态</ElButton>
          <p v-if="draft" class="muted">当前修订的推理额度：{{ savedReasoningEffortLabel }}。此值来自服务器保存的修订配置。</p>
          <p v-if="draft" class="muted">当前修订的方法包输出：{{ draft.deployment.config.skill_output_strategy ? skillStrategyLabels[draft.deployment.config.skill_output_strategy] : '未指定（新方法包采用标准 JSON，历史版本保持原策略）' }}。</p>
          <p v-else class="muted">请先保存模型草稿。验证会发送极小样本，可能产生服务商费用。</p>
          <ElAlert v-if="publicationConfirmationRequired" type="warning" :closable="false" title="发布结果或当前修订尚未确认。请先读取当前部署修订并核对；读取不会验证或发布模型，也不代表原发布没有执行。" />
          <ElButton v-if="journey?.phase === 'publish_unknown'" :loading="busy === 'read' || busy === 'publish'" :disabled="Boolean(busy) || originalPublicationConfirming || journeyRecoveryPending || verificationRecoveryPending || verificationStorageError" @click="confirmOriginalPublication">读取原目标发布状态</ElButton>
          <ElButton v-if="publicationConfirmationRequired" :loading="busy === 'read'" :disabled="Boolean(busy) || verificationRecoveryPending || verificationStorageError || journeyStorageError || journeyRecoveryPending" @click="readCurrentPublicationRevision">读取并确认当前修订</ElButton>
          <div v-if="publicationConfirmationRequired && publicationPreview" class="muted">
            <p>当前部署 {{ publicationPreview.draft.deployment_id }} · 修订 {{ publicationPreview.draft.revision }} · {{ verificationLabel(publicationPreview.verification.state) }}。服务商：{{ publicationPreview.draft.provider.name }}；模型：{{ publicationPreview.draft.model.name }}（{{ publicationPreview.draft.deployment.remote_model }}）。</p>
            <p>输出上限 {{ publicationPreview.draft.deployment.config.max_output_tokens }}；推理额度 {{ publicationPreview.draft.deployment.config.reasoning_effort || '由模型服务决定' }}；方法包输出 {{ publicationPreview.draft.deployment.config.skill_output_strategy ? skillStrategyLabels[publicationPreview.draft.deployment.config.skill_output_strategy] : '标准输出（默认）' }}。</p>
            <ElButton :disabled="Boolean(busy) || verificationRecoveryPending || verificationStorageError || journeyLocked" @click="confirmCurrentPublicationRevision">确认使用此修订</ElButton>
          </div>
          <ElForm v-if="draft" label-width="110px" class="narrow" @submit.prevent>
            <ElFormItem label="处理位置">
              <ElSelect v-model="processingLocation" placeholder="请按服务商实际处理位置确认" :disabled="Boolean(busy) || connectionSubmitting || verification?.state === 'tested' || verificationRecoveryPending">
                <ElOption label="本地运行" value="local_runtime" />
                <ElOption label="私有网络" value="private_network" />
                <ElOption label="公共云" value="public_cloud" />
              </ElSelect>
            </ElFormItem>
            <ElFormItem>
              <ElSpace wrap>
                <ElButton :loading="busy === 'verify'" :disabled="!canVerify" @click="verifyDraft">验证聊天模型</ElButton>
                <ElButton :loading="busy === 'read'" :disabled="Boolean(busy)" @click="readVerification">读取当前修订配置与状态</ElButton>
                <ElButton type="primary" :loading="busy === 'publish'" :disabled="Boolean(busy) || publicationConfirmationRequired || verificationRecoveryPending || verificationStorageError || journeyLocked || verification?.state !== 'tested' || !verification.validation_evidence" @click="publish">发布模型连接</ElButton>
                <ElButton v-if="canReconfigure" :disabled="Boolean(busy)" @click="reconfigureConnection">重新配置连接</ElButton>
              </ElSpace>
            </ElFormItem>
          </ElForm>
          <ElAlert v-if="verificationFailure" type="warning" :closable="false" :title="verificationFailure.message" />
          <p v-if="verificationFailure?.failed_at" class="muted">失败时间：{{ verificationFailure.failed_at }}</p>
          <ElAlert v-if="!verificationFailure && verification?.state === 'draft' && (verificationRejected || verificationAttempted || hasVerificationFailure) && !verificationUncertain" type="warning" :closable="false"
            :title="verificationRejected && !hasVerificationFailure
              ? '服务商拒绝了聊天验证。当前修订已确认回到待验证，可重新配置连接；此处不会重复验证失败修订。'
              : !hasVerificationFailure
                ? '当前修订已确认回到待验证。可重新配置连接；此处不会重复验证已经发送过的修订。'
                : '当前修订的失败反馈无法确认。请保留部署与修订号并联系管理员核查；此处不会重复验证或重新配置。'" />
          <ElAlert v-if="verification?.validation_evidence?.usage_measured === false" type="warning" :closable="false"
            title="模型已通过调用验证，但服务商未返回 Token 用量。运行费用会保持未知，依赖精确用量的限额和低成本路由可能不可用。" />
          <ElAlert v-if="verification?.state === 'verifying'" type="info" :closable="false" title="服务商验证仍在进行。请稍后读取当前修订状态，不要重复发送验证请求。" />
          <ElAlert v-if="verificationUncertain" type="warning" :closable="false" title="验证结果无法确定，可能已产生服务商费用。请保留上方部署与修订号，请到服务商控制台核查调用记录并联系管理员；此处不会重新发送验证请求。" />
          <ElAlert v-if="bindingStorageError" type="warning" :closable="false"
            title="原绑定操作记录无法确认，暂不发送新的绑定。请恢复会话存储后重读原记录。" />
          <ElButton v-if="bindingStorageError" :disabled="bindingSubmitting || bindingReading || Boolean(busy)" @click="loadOriginalBindingIntent">重读原绑定记录</ElButton>
          <section v-if="bindingIntent">
            <ElAlert type="warning" :closable="false"
              title="原绑定结果尚未确认"
              :description="`原目标：应用 ${bindingIntent.application_id}，环境 ${bindingIntent.environment_id}，部署 ${bindingIntent.deployment_id} · 修订 ${bindingIntent.revision}。`" />
            <ElButton :loading="bindingReading" :disabled="bindingSubmitting || bindingReading || Boolean(busy)" @click="readOriginalBinding">读取原绑定目标</ElButton>
            <p class="muted">仅核对原目标当前的已发布默认聊天配置。这不是服务器原操作回执；未找到、读取失败或目标不匹配不证明原绑定未发生，不会重新发送绑定。</p>
          </section>
          <p v-if="bindingNotice" class="muted">{{ bindingNotice }}</p>
          <ElAlert v-if="published && !publicationConfirmationRequired" type="success" :closable="false" title="模型连接已发布。请选择目标环境并绑定默认聊天能力，应用才能调用。" />
          <ElSpace v-if="published && !publicationConfirmationRequired" wrap>
            <ElButton v-permission="'sand_ai:onboarding:publish'" type="primary" :loading="busy === 'bind'"
              :disabled="Boolean(busy) || journeyLocked || !environmentId || boundEnvironmentId === environmentId" @click="bindDefaultChat">
              {{ boundEnvironmentId === environmentId ? '本次已绑定到该环境' : '绑定到所选环境' }}
            </ElButton>
            <span v-if="!environmentId" class="muted">先在工作目标中选择 SandIAM 应用和环境。</span>
          </ElSpace>
          <p v-if="boundEnvironmentId === environmentId && environmentId && !publicationConfirmationRequired" class="muted">聊天能力已绑定；所选工作目标仍需按对应配置入口继续准备、试用并发布。</p>
          <ElSpace v-if="boundEnvironmentId === environmentId && environmentId && !publicationConfirmationRequired" wrap>
            <ElButton type="primary" :disabled="Boolean(busy)" @click="openChatTrial">试用已发布聊天能力</ElButton>
            <span class="muted">后台管理员负责配置；应用调用使用 SandIAM 工作负载 Bearer，调用环境由凭证决定。进入“聊天路线试用”后填写该凭证，无需填写服务商 API Key。</span>
          </ElSpace>
        </section>

        <section class="section">
          <SuccessorDeploymentSetup :application-id="applicationId || null" :environment-id="environmentId || null" />
        </section>

        <section v-if="selectedScene === 'meeting_recording_organization'" class="section">
          <h3>4. {{ guide.display_name }}服务</h3>
          <p>{{ guide.module_explanation }}</p>
          <p class="muted">先配置并发布转写能力与聊天路线，再核定会议执行设置。模型验证不证明转写准确率或会议摘要完整性。</p>
          <ElButton @click="openCapabilityConfiguration">配置会议所需能力</ElButton>
          <MeetingExecutionSetup
            :environment-id="environmentId"
            :application-name="applications.find(item => item.id === applicationId)?.name || '未选择应用'"
            :environment-name="environments.find(item => item.id === environmentId)?.name || '未选择环境'"
            @configure="openCapabilityConfiguration"
          />
        </section>

        <section v-else-if="selectedScene === 'material_organization'" class="section">
          <h3>4. {{ guide.display_name }}服务</h3>
          <p>{{ guide.module_explanation }}</p>
          <p class="muted">先保存环境配置草稿；后续仍需运行真实样本并发布服务。</p>
          <MaterialCapabilitySetup
            :key="environmentId || 'no-environment'"
            :environment-id="environmentId"
            :application-name="applications.find(item => item.id === applicationId)?.name || '未选择应用'"
            :environment-name="environments.find(item => item.id === environmentId)?.name || '未选择环境'"
            :deployment-id="published ? draft?.deployment_id ?? null : null"
            :deployment-name="draft?.model.name ?? ''"
            :extraction-declared="materialExtraction"
            @status="onMaterialCapabilityStatus"
            @professional="openCapabilityConfiguration"
          />
          <MaterialProfileEditor v-if="materialReadyEnvironmentId === environmentId && environmentId" :environment-id="environmentId" />
        </section>

        <section v-else class="section">
          <h3>4. {{ guide.display_name }}服务</h3>
          <p>{{ guide.module_explanation }}</p>
          <p class="muted">{{ guide.readiness_note }}</p>
          <p v-if="!environmentId" class="muted">先在工作目标中选择应用和环境，再在对应模块明确配置使用范围。</p>
          <ElSpace wrap>
            <ElButton @click="openCapabilityConfiguration">配置并发布所需能力</ElButton>
            <ElButton v-if="guide.technical.module_codes.includes('knowledge_base')" @click="router.push('/sand-ai/knowledge')">准备知识材料</ElButton>
            <ElButton v-if="guide.technical.module_codes.includes('agent')" @click="router.push('/sand-ai/agent')">配置知识问答智能体</ElButton>
          </ElSpace>
          <p class="muted">模型连接发布与环境能力发布是各自的操作。知识材料、智能体和渠道仍按对应模块的实际状态与授权使用，不会因选择目标自动开通。</p>
        </section>
      </template>
    </ElCard>
  </div>
</template>

<script setup lang="ts">
import { computed, nextTick, onActivated, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessageBox } from 'element-plus'
import { HttpError } from '@/utils/http/error'
import { useUserStore } from '@/store/modules/user'
import iamApi, { type SandIamApplicationReference, type SandIamEnvironmentReference } from '../api/iam'
import onboardingApi, { type AudioRuntimeMetadata, type DiscoveredModel, type DraftSave, type NewDeployment, type ProviderConnectionHeader, type ProviderGuide, type PublishedDeployment, type ReasoningEffort, type SkillOutputStrategy, type ServiceCatalogPreflight, type SolutionGuide, type Verification, type VerificationFailure, type VerificationFailureReason } from '../api/onboarding'
import capabilityApi from '../api/capability'
import { readOnboardingBindingIntent, retainOnboardingBindingIntent, resolveOnboardingBindingIntent, type OnboardingBindingIntent } from '../api/onboardingBindingIntent'
import MaterialProfileEditor from '../components/MaterialProfileEditor.vue'
import materialProfileApi from '../api/materialProfile'
import SuccessorDeploymentSetup from '../components/SuccessorDeploymentSetup.vue'
import MaterialCapabilitySetup from '../components/MaterialCapabilitySetup.vue'
import MeetingExecutionSetup from '../components/MeetingExecutionSetup.vue'
import OcrDeploymentSetup from '../components/OcrDeploymentSetup.vue'
import TranscriptionDeploymentSetup from '../components/TranscriptionDeploymentSetup.vue'
import SpeechDeploymentSetup from '../components/SpeechDeploymentSetup.vue'
import SpeechTrialPanel from '../components/SpeechTrialPanel.vue'
import EmbeddingDeploymentSetup from '../components/EmbeddingDeploymentSetup.vue'
import ChatRouteTrialPanel from '../components/ChatRouteTrialPanel.vue'

const skillCatalogActionCodes = [
  'sand_ai.skill.run',
  'sand_ai.skill.read',
  'sand_ai.skill.manage',
  'sand_ai.skill.test',
  'sand_ai.skill.publish'
] as const

function hasSkillCatalogActions(codes: readonly string[]): boolean {
  return codes.length === skillCatalogActionCodes.length
    && skillCatalogActionCodes.every((code, index) => codes[index] === code)
}

const router = useRouter()
const route = useRoute()
const loading = ref(true)
const guideError = ref('')
const apiKeyInput = ref<{ focus: () => void } | null>(null)
const loadingIam = ref(false)
const iamError = ref(false)
const solutionGuides = ref<SolutionGuide[]>([])
const guide = computed(() => solutionGuides.value.find(item => item.technical.code === selectedScene.value) ?? null)
const catalogPreflight = ref<ServiceCatalogPreflight | null>(null)
const catalogRegistered = ref(false)
const catalogDisabled = ref(false)
const catalogBusy = ref<'checking' | 'registering' | ''>('')
const catalogMessage = ref('')
const catalogMessageType = ref<'error' | 'warning' | 'success'>('error')
const skillCatalogPreflight = ref<ServiceCatalogPreflight | null>(null)
const skillCatalogRegistered = ref(false)
const skillCatalogDisabled = ref(false)
const skillCatalogBusy = ref<'checking' | 'registering' | ''>('')
const skillCatalogMessage = ref('')
const skillCatalogMessageType = ref<'error' | 'warning' | 'success'>('error')
const audioCatalogPreflight = ref<ServiceCatalogPreflight | null>(null)
const audioCatalogRegistered = ref(false)
const audioCatalogDisabled = ref(false)
const audioCatalogBusy = ref<'checking' | 'registering' | ''>('')
const audioCatalogMessage = ref('')
const audioCatalogMessageType = ref<'error' | 'warning' | 'success'>('error')
const audioRuntime = ref<AudioRuntimeMetadata | null>(null)
const audioRuntimeBusy = ref(false)
const audioRuntimeError = ref('')
const providerGuides = ref<ProviderGuide[]>([])
const selectedProviderCode = ref('')
const providerGuide = computed(() => providerGuides.value.find(item => (item.provider_code || item.adapter) === selectedProviderCode.value) ?? null)
const selectedScene = ref('')
const applications = ref<SandIamApplicationReference[]>([])
const environments = ref<SandIamEnvironmentReference[]>([])
const applicationId = ref<number | ''>('')
const environmentId = ref<number | ''>('')
const materialReadyEnvironmentId = ref<number | null>(null)
const navigationNotice = ref('')
let restoringConfigurationLocation = false
let configurationEntryLoaded = false
function onMaterialCapabilityStatus(target: number, ready: boolean): void {
  if (target === environmentId.value) materialReadyEnvironmentId.value = ready ? target : null
}
function openCapabilityConfiguration(): void {
  const query: Record<string, string | undefined> = {
    application_id: applicationId.value ? String(applicationId.value) : undefined,
    environment_id: environmentId.value ? String(environmentId.value) : undefined
  }
  if (selectedScene.value === 'material_organization' && applicationId.value && environmentId.value) {
    query.configuration_origin = 'material_guide'
    query.guided_application_id = String(applicationId.value)
    query.guided_environment_id = String(environmentId.value)
    const code = route.query.material_profile_code
    const version = route.query.material_profile_version
    if (route.query.application_id === String(applicationId.value)
      && route.query.environment_id === String(environmentId.value)
      && typeof code === 'string' && /^[a-z][a-z0-9_]{0,63}$/.test(code)
      && typeof version === 'string' && version.length <= 32 && /^\d+\.\d+\.\d+$/.test(version)) {
      query.material_profile_code = code; query.material_profile_version = version
      query.guided_profile_code = code; query.guided_profile_version = version
      const run = route.query.material_run_id
      if (typeof run === 'string' && /^[1-9][0-9]*$/.test(run) && Number.isSafeInteger(Number(run))) {
        query.material_run_id = run; query.guided_run_id = run
      }
    } else if (code !== undefined || version !== undefined) {
      navigationNotice.value = '原材料配置标识或环境未确认，请重新选择原配置后进入专业模块。'
      return
    }
  }
  void router.push({ path: '/sand-ai/capability', query })
}
function openChatTrial(): void {
  if (!environmentId.value || boundEnvironmentId.value !== environmentId.value || publicationConfirmationRequired.value || busy.value) return
  void router.push({ path: '/sand-ai/capability', query: { application_id: applicationId.value || undefined, environment_id: environmentId.value }, hash: '#chat-route-trial' })
}
watch(environmentId, () => { materialReadyEnvironmentId.value = null }, { flush: 'sync' })
const providerName = ref('')
const baseUrl = ref('')
const apiKey = ref('')
const providerTimeoutSeconds = ref(120)
const extraHeaderFields: Array<{ name: ProviderConnectionHeader; label: string }> = [
  { name: 'OpenAI-Organization', label: 'OpenAI 组织' },
  { name: 'OpenAI-Project', label: 'OpenAI 项目' },
  { name: 'X-Api-Key', label: '额外 API Key' },
  { name: 'X-Organization', label: '组织标识' },
  { name: 'X-Project', label: '项目标识' }
]
const extraHeaders = ref<Partial<Record<ProviderConnectionHeader, string>>>({})
const supportsExtraHeaders = computed(() => providerGuide.value?.fields?.some(field => field.name === 'extra_headers') === true)
const extraHeaderError = computed(() => {
  if (!supportsExtraHeaders.value) return ''
  for (const field of extraHeaderFields) {
    const value = extraHeaders.value[field.name] ?? ''
    if (/[\r\n]/.test(value)) return `${field.label}不能包含换行，请按服务商提供的原值填写。`
    if (new TextEncoder().encode(value).length > 512) return `${field.label}超过 512 字节，请核对服务商提供的值。`
  }
  return ''
})
const models = ref<DiscoveredModel[]>([])
const remoteModel = ref('')
const modelName = ref('')
const connectionNotice = ref('')
const materialExtraction = ref(true)
const maxOutputTokens = ref(1024)
const reasoningEffort = ref<'' | ReasoningEffort>('')
const skillOutputStrategy = ref<SkillOutputStrategy>('neuron_json_extract_v1')
const inspectionSearchName = ref('')
const inspectionDeployments = ref<PublishedDeployment[]>([])
const inspectionDeploymentId = ref<number>()
const inspectionTargetId = ref<number>()
const inspectionTargetRevision = ref<number>()
const inspectionLoading = ref(false)
const inspectionReading = ref(false)
const inspectedRevision = ref<DraftSave | null>(null)
let inspectionGeneration = 0
const validInspectionTarget = computed(() => Number.isSafeInteger(inspectionTargetId.value) && Number(inspectionTargetId.value) > 0 && Number.isSafeInteger(inspectionTargetRevision.value) && Number(inspectionTargetRevision.value) > 0)

const skillStrategyLabels: Record<SkillOutputStrategy, string> = { neuron_json_extract_v1: '标准输出（默认）', neuron_zai_json_object_v1: 'JSON 对象', neuron_openai_json_schema_v1: 'JSON Schema' }
const processingLocation = ref('')
const draft = ref<DraftSave | null>(null)
const saveAttempt = ref<{ payload: NewDeployment; key: string; hadUncertainOutcome: boolean } | null>(null)
type VerificationRequest = { admin_id: number; deployment_id: number; revision: number; key: string }
const userStore = useUserStore()
type Journey = {
  version: 1; admin_id: number; application_id: number | null; environment_id: number | null
  key: string; provider_code: string; model_code: string; model_type: 'chat'
  phase: 'save_unknown' | 'saved' | 'publish_unknown'
  deployment_id: number | null; revision: number | null; provider_id: number | null; model_id: number | null
}
const journey = ref<Journey | null>(null)
const journeyStorageError = ref(false)
const journeyRecoveryPending = ref(false)
const originalPublicationConfirming = ref(false)
const connectionSubmitting = ref(false)
let connectionFlightSerial = 0
const bindingIntent = ref<OnboardingBindingIntent | null>(null)
const bindingStorageError = ref(false)
const bindingSubmitting = ref(false)
let bindingFlightSerial = 0
let live = true
const bindingReading = ref(false)
const bindingNotice = ref('')
const journeyLocked = computed(() => connectionSubmitting.value || bindingStorageError.value || Boolean(bindingIntent.value) || journeyStorageError.value || journeyRecoveryPending.value || journey.value?.phase === 'save_unknown' || journey.value?.phase === 'publish_unknown')
const verificationRequest = ref<VerificationRequest | null>(null)
const verificationRecoveryPending = ref(false)
const verificationStorageError = ref(false)
const verification = ref<Verification | null>(null)

// Only opaque intent/target metadata is persisted. Connection, names, DTOs and results stay in memory.
function positiveId(value: unknown): value is number { return typeof value === 'number' && Number.isSafeInteger(value) && value > 0 }
function nullableId(value: unknown): value is number | null { return value === null || positiveId(value) }
function journeyOwner(): number {
  const id = Number(userStore.info.id)
  if (!userStore.accessToken || !positiveId(id)) throw new Error('Administrator unavailable')
  return id
}
function journeyIndex(): string { return `sand-ai:onboarding:journey:${journeyOwner()}:active` }
function journeyNamespace(scope: { application_id: number | null; environment_id: number | null }): string {
  return `sand-ai:onboarding:journey:${journeyOwner()}:${scope.application_id ?? 'none'}:${scope.environment_id ?? 'none'}`
}
function readStoredJourney(): Journey | null {
  const pointer = sessionStorage.getItem(journeyIndex())
  if (pointer === null) return null
  const scope: unknown = JSON.parse(pointer)
  if (!isRecord(scope) || !nullableId(scope.application_id) || !nullableId(scope.environment_id)
    || (scope.environment_id !== null && scope.application_id === null)) throw new Error('Original scope unavailable')
  const raw = sessionStorage.getItem(journeyNamespace({ application_id: scope.application_id, environment_id: scope.environment_id }))
  if (raw === null) throw new Error('Original journey missing')
  const value: unknown = JSON.parse(raw)
  if (!isRecord(value) || value.version !== 1 || value.admin_id !== journeyOwner()
    || value.application_id !== scope.application_id || value.environment_id !== scope.environment_id
    || typeof value.key !== 'string' || !/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i.test(value.key)
    || typeof value.provider_code !== 'string' || !/^onboard_[a-f0-9]{12}$/.test(value.provider_code)
    || typeof value.model_code !== 'string' || !/^chat_[a-f0-9]{12}$/.test(value.model_code)
    || value.model_type !== 'chat' || !['save_unknown', 'saved', 'publish_unknown'].includes(String(value.phase))
    || !nullableId(value.deployment_id) || !nullableId(value.revision) || !nullableId(value.provider_id) || !nullableId(value.model_id)
    || (value.phase === 'save_unknown' ? [value.deployment_id, value.revision, value.provider_id, value.model_id].some(id => id !== null)
      : ![value.deployment_id, value.revision, value.provider_id, value.model_id].every(positiveId))) throw new Error('Original journey invalid')
  return { version: 1, admin_id: journeyOwner(), application_id: scope.application_id, environment_id: scope.environment_id,
    key: value.key, provider_code: value.provider_code, model_code: value.model_code, model_type: 'chat',
    phase: value.phase === 'save_unknown' ? 'save_unknown' : value.phase === 'publish_unknown' ? 'publish_unknown' : 'saved',
    deployment_id: value.deployment_id, revision: value.revision, provider_id: value.provider_id, model_id: value.model_id }
}
function persistJourney(value: Journey): void {
  if (value.admin_id !== journeyOwner()) throw new Error('Administrator changed')
  sessionStorage.setItem(journeyNamespace(value), JSON.stringify(value))
  sessionStorage.setItem(journeyIndex(), JSON.stringify({ application_id: value.application_id, environment_id: value.environment_id }))
  const stored = readStoredJourney()
  if (!stored || JSON.stringify(stored) !== JSON.stringify(value)) throw new Error('Original journey not retained')
  journey.value = stored
  journeyStorageError.value = false
}
function releaseJourney(): void {
  const target = journey.value
  if (!target || target.phase !== 'saved') throw new Error('Unresolved original journey')
  const stored = readStoredJourney()
  if (!stored || JSON.stringify(stored) !== JSON.stringify(target)) throw new Error('Original journey changed')
  sessionStorage.removeItem(journeyIndex())
  sessionStorage.removeItem(journeyNamespace(target))
  journey.value = null
}
function assertJourneyDraft(saved: DraftSave, target: Journey): void {
  if (!['draft', 'verifying', 'tested', 'published', 'verification_ambiguous'].includes(saved.state)
    || saved.provider.code !== target.provider_code || saved.model.code !== target.model_code || saved.model.type !== target.model_type
    || !positiveId(saved.provider.id) || !positiveId(saved.model.id) || !positiveId(saved.deployment_id) || !positiveId(saved.revision)
    || (target.deployment_id !== null && (saved.deployment_id !== target.deployment_id || saved.revision !== target.revision
      || saved.provider.id !== target.provider_id || saved.model.id !== target.model_id))) throw new Error('Original saved target mismatch')
  readChatDraftSettings(saved)
}
function savedJourney(target: Journey, saved: DraftSave): Journey {
  assertJourneyDraft(saved, target)
  return { ...target, phase: 'saved', deployment_id: saved.deployment_id, revision: saved.revision, provider_id: saved.provider.id, model_id: saved.model.id }
}
async function restoreJourney(): Promise<void> {
  if (busy.value) return
  const current = epoch
  journeyRecoveryPending.value = true
  try {
    const stored = readStoredJourney()
    if (current !== epoch) return
    if (!stored) {
      if (journey.value) throw new Error('Original journey disappeared')
      journeyRecoveryPending.value = false
      journeyStorageError.value = false
      await restoreVerificationRequest()
      return
    }
    journey.value = stored
    busy.value = 'read'
    if (stored.phase !== 'save_unknown' && (!positiveId(stored.deployment_id) || !positiveId(stored.revision))) throw new Error('Original target unavailable')
    const saved = stored.phase === 'save_unknown'
      ? await onboardingApi.readSavedDraftByKey(stored.key)
      : await onboardingApi.readDraft(Number(stored.deployment_id), Number(stored.revision))
    if (current !== epoch) return
    assertJourneyDraft(saved, stored)
    // Resolve only the original save receipt. The environment is UI context, never backend authorization.
    const target = stored.phase === 'save_unknown' ? savedJourney(stored, saved) : stored
    persistJourney(target)
    const originalVerification = readStoredVerification()
    if (originalVerification && (originalVerification.deployment_id !== saved.deployment_id || originalVerification.revision !== saved.revision)) throw new Error('Original verification target differs')
    const state = await onboardingApi.readVerification(saved.deployment_id, saved.revision, originalVerification?.key)
    if (current !== epoch) return
    const verifiedPublished = saved.state === 'published' && state.state === 'tested' && Boolean(state.validation_evidence)
    if (state.deployment_id !== saved.deployment_id || state.revision !== saved.revision || (state.state !== saved.state && !verifiedPublished)) throw new Error('Original revision state mismatch')
    hydrateDraftConfiguration(saved)
    draft.value = saved
    applyVerification(verifiedPublished ? { ...state, state: 'published' } : state)
    verificationAttempted.value = Boolean(originalVerification || state.state !== 'draft' || hasVerificationFailure.value || originalAttemptIsTerminal(state))
    clearKey()
    if (originalVerification) {
      verificationRequest.value = originalVerification
      verificationRecoveryPending.value = true
      verificationAttempted.value = true
      if (originalAttemptIsTerminal(state)) resolveVerificationRequest(originalVerification)
    }
    if (target.phase === 'publish_unknown') {
      if (saved.state === 'published' && (state.state === 'published' || verifiedPublished)) persistJourney({ ...target, phase: 'saved' })
      else publicationConfirmationRequired.value = true
    }
    if (journey.value?.phase === 'saved') { publicationConfirmationRequired.value = false; publicationPreview.value = null }
    journeyRecoveryPending.value = false
    journeyStorageError.value = false
    notice(journey.value?.phase === 'publish_unknown'
      ? '原发布结果仍未确认。保留原部署与修订，只读找回不会重新发布；未找到或状态未发布均不表示原请求未执行。'
      : `已找回原部署 ${saved.deployment_id} 的修订 ${saved.revision}，请按实际状态继续。原应用/环境仅为配置目标，不代表服务授权。`, journeyLocked.value ? 'warning' : 'success')
    void restoreJourneyScope(target, current)
  } catch (error) {
    if (current === epoch) {
      journeyStorageError.value = true
      notice(accessFailureNotice(error) || '原首次开通记录尚未确认。请继续只读找回原请求或修订；不能新建请求、放弃原目标或重复发布。', 'warning')
    }
  } finally { if (current === epoch) busy.value = '' }
}
async function restoreJourneyScope(target: Journey, current: number): Promise<void> {
  const selectedApplication = applicationId.value
  const selectedEnvironment = environmentId.value
  try {
    const apps = await iamApi.activeApplications()
    if (current !== epoch || journey.value?.key !== target.key || applicationId.value !== selectedApplication || environmentId.value !== selectedEnvironment) return
    if (target.application_id === null) return
    const app = apps.find(item => item.id === target.application_id)
    if (!app) return
    const envs = await iamApi.activeEnvironments(app.id)
    if (current !== epoch || journey.value?.key !== target.key || applicationId.value !== selectedApplication || environmentId.value !== selectedEnvironment) return
    applications.value = apps
    applicationId.value = app.id
    environments.value = envs
    environmentId.value = envs.some(item => item.id === target.environment_id) ? target.environment_id ?? '' : ''
  } catch { /* The original target remains visible; an unavailable IAM directory grants no environment access. */ }
}

function verificationNamespace(): string | null {
  const adminId = Number(userStore.info.id)
  return userStore.accessToken && Number.isSafeInteger(adminId) && adminId > 0 ? `sand-ai:onboarding:verification:${adminId}` : null
}
function readStoredVerification(): VerificationRequest | null {
  const namespace = verificationNamespace()
  if (!namespace) throw new Error('Administrator unavailable')
  const raw = sessionStorage.getItem(namespace)
  if (raw === null) return null
  const value: unknown = JSON.parse(raw)
  if (!isRecord(value) || value.admin_id !== Number(userStore.info.id)
    || !Number.isSafeInteger(value.deployment_id) || Number(value.deployment_id) < 1
    || !Number.isSafeInteger(value.revision) || Number(value.revision) < 1
    || typeof value.key !== 'string' || !/^[\x20-\x7E]{1,128}$/.test(value.key) || value.key.trim() === '') {
    throw new Error('Original verification intent unavailable')
  }
  return { admin_id: Number(value.admin_id), deployment_id: Number(value.deployment_id), revision: Number(value.revision), key: value.key }
}
function resolveVerificationRequest(request: VerificationRequest): void {
  const stored = readStoredVerification()
  if (!stored || stored.key !== request.key || stored.deployment_id !== request.deployment_id || stored.revision !== request.revision) {
    throw new Error('Original verification intent changed')
  }
  const namespace = verificationNamespace()
  if (!namespace) throw new Error('Administrator unavailable')
  sessionStorage.removeItem(namespace)
  verificationRecoveryPending.value = false
}
function originalAttemptIsTerminal(state: Verification): boolean {
  if (state.state === 'tested' && state.validation_evidence) return true
  const failure = safeVerificationFailure(state)
  if (state.state === 'draft' && failure && !failure.outcome_uncertain) return true
  const usage: unknown = Reflect.get(state, 'platform_usage')
  return state.state === 'draft' && isRecord(usage) && usage.scope_kind === 'platform_admin'
    && Number.isSafeInteger(usage.attempt_id) && Number(usage.attempt_id) > 0 && usage.state === 'not_dispatched'
}

const verificationRejected = ref(false)
const verificationAttempted = ref(false)
const publicationConfirmationRequired = ref(false)
const publicationPreview = ref<{ draft: DraftSave; verification: Verification } | null>(null)
const published = ref(false)
const boundEnvironmentId = ref<number | null>(null)
const busy = ref<'discover' | 'save' | 'verify' | 'read' | 'publish' | 'bind' | ''>('')
const message = ref('')
const messageType = ref<'error' | 'warning' | 'success'>('error')
let epoch = 0
let guideGeneration = 0
let discoveryGeneration = 0
let environmentGeneration = 0
let catalogGeneration = 0
let skillCatalogGeneration = 0
let audioCatalogGeneration = 0
let audioRuntimeGeneration = 0

const requiredAudioActions = [
  'sand_ai.audio.transcription',
  'sand_ai.audio.review',
  'sand_ai.audio.speech',
  'sand_ai.audio.speech.read'
] as const

function hasRequiredAudioActions(codes: string[]): boolean {
  return requiredAudioActions.every(code => codes.includes(code))
}

const connectionInputError = computed(() => {
  if (/\p{Cc}/u.test(baseUrl.value) || /\p{Cc}/u.test(apiKey.value)) {
    return '服务地址和 API Key 不能包含换行、制表符或其他控制字符，请按服务商提供的原值填写。'
  }
  return ''
})
const apiKeyWhitespaceError = computed(() => apiKey.value && !apiKey.value.trim() && !/\p{Cc}/u.test(apiKey.value)
  ? 'API Key 不能只包含空白字符，请填写服务商提供的 Key。' : '')
const canConnect = computed(() => Boolean(baseUrl.value.trim() && apiKey.value.trim())
  && !connectionInputError.value && !extraHeaderError.value)
const modelIdentifierError = computed(() => {
  const value = remoteModel.value
  if (!value) return ''
  if (new TextEncoder().encode(value).length > 256) return '模型标识最多 256 字节，请按服务商给出的标识填写。'
  if (/\p{Cc}/u.test(value) || value.includes('\\') || value.startsWith('/') || value.includes('//')
    || value.split('/').some(segment => segment === '.' || segment === '..')) {
    return '模型标识不能包含控制字符或路径跳转段，请按服务商给出的标识填写。'
  }
  return ''
})
const defaultModelName = computed(() => {
  const value = remoteModel.value.trim()
  if (new TextEncoder().encode(value).length <= 160) return value
  let name = ''
  for (const character of value) {
    if (new TextEncoder().encode(name + character).length > 157) break
    name += character
  }
  return `${name}...`
})
const modelNameError = computed(() => {
  if (new TextEncoder().encode(modelName.value).length > 160) return '后台模型名称最多 160 字节，请缩短名称。'
  if (/\p{Cc}/u.test(modelName.value)) return '后台模型名称不能包含控制字符。'
  return ''
})
const providerNameError = computed(() => {
  if (new TextEncoder().encode(providerName.value).length > 160) return '服务商名称最多 160 字节，请缩短名称。'
  if (/\p{Cc}/u.test(providerName.value)) return '服务商名称不能包含控制字符。'
  return ''
})
const canSave = computed(() => !verificationRecoveryPending.value && !verificationStorageError.value && !journeyLocked.value && Boolean(canConnect.value && providerName.value && remoteModel.value.trim()
  && !providerNameError.value && !modelIdentifierError.value && !modelNameError.value && Number.isInteger(maxOutputTokens.value)
  && maxOutputTokens.value >= 1 && maxOutputTokens.value <= 131072
  && Number.isInteger(providerTimeoutSeconds.value) && providerTimeoutSeconds.value >= 1 && providerTimeoutSeconds.value <= 120))
// A malformed optional failure must not grant permission to send another request.
const verificationUncertain = computed(() => verification.value?.state === 'verification_ambiguous'
  || (isRecord(verification.value?.failure) && verification.value.failure.outcome_uncertain === true))
const verificationFailure = computed(() => safeVerificationFailure(verification.value))
const hasVerificationFailure = computed(() => verification.value?.failure !== null
  && verification.value?.failure !== undefined)
const canVerify = computed(() => Boolean(!verificationRecoveryPending.value && !verificationStorageError.value && !journeyLocked.value && draft.value && !busy.value && !verificationRejected.value
  && !verificationUncertain.value && !verificationAttempted.value && !hasVerificationFailure.value && processingLocation.value
  && verification.value?.state === 'draft'))
const canReconfigure = computed(() => Boolean(!verificationRecoveryPending.value && !verificationStorageError.value && !journeyLocked.value && draft.value && !published.value && !busy.value
  && verification.value?.state === 'draft' && !verificationUncertain.value
  && (!hasVerificationFailure.value || (verificationFailure.value?.recovery_action === 'reconfigure_connection'
    && verificationFailure.value.outcome_uncertain === false))))
const savedReasoningEffortLabel = computed(() => {
  const value = draft.value?.deployment.config.reasoning_effort
  return value ? { low: '低', high: '高', max: '最大' }[value] : '由模型服务决定（默认）'
})
function verificationLabel(state: Verification['state']): string {
  const labels = {
    draft: '待验证', verifying: '验证中', tested: '已验证，待发布',
    verification_ambiguous: '验证结果未确认', published: '已发布'
  }
  return labels[state]
}
const verificationStateLabel = computed(() => verificationLabel(verification.value?.state ?? 'draft'))
const step = computed(() => published.value ? 3 : verification.value?.state === 'tested' ? 2 : draft.value ? 2 : 1)

function safeGuideUrl(value: string): string | undefined {
  try {
    const url = new URL(value)
    return url.protocol === 'https:' ? url.toString() : undefined
  } catch {
    return undefined
  }
}

function notice(text: string, type: 'error' | 'warning' | 'success' = 'error'): void {
  message.value = text
  messageType.value = type
}

function clearKey(): void {
  apiKey.value = ''
  extraHeaders.value = {}
  saveAttempt.value = null
}

function connectionSnapshot(): NewDeployment['connection'] {
  const headers: Partial<Record<ProviderConnectionHeader, string>> = {}
  if (supportsExtraHeaders.value) {
    for (const field of extraHeaderFields) {
      const value = extraHeaders.value[field.name] ?? ''
      if (value !== '') headers[field.name] = value
    }
  }
  return {
    base_url: baseUrl.value,
    api_key: apiKey.value,
    ...(Object.keys(headers).length ? { extra_headers: headers } : {})
  }
}

watch([baseUrl, apiKey, extraHeaders], () => {
  discoveryGeneration++
  models.value = []
  // Saving already froze an attempt; clearing its secret after success must
  // not erase the selected model of the saved draft.
  if (draft.value || saveAttempt.value) return
  if (remoteModel.value) connectionNotice.value = '连接信息已改变，旧模型选择已清空。请重新发现模型或填写当前账号可用的模型标识。'
  remoteModel.value = ''
  modelName.value = ''
}, { deep: true, flush: 'sync' })

watch(selectedProviderCode, () => {
  const selected = providerGuide.value
  if (!selected || draft.value || saveAttempt.value) return
  clearKey()
  providerName.value = selected.name
  baseUrl.value = selected.default_base_url ?? ''
})

async function reconfigureConnection(): Promise<void> {
  if (busy.value || !canReconfigure.value || !draft.value) return
  const target = draft.value
  try {
    await ElMessageBox.confirm(
      `服务器会保留部署 ${target.deployment_id} 的修订 ${target.revision} 草稿。继续后请重新填写 Key 和聊天模型；新的连接仍需保存、验证和发布。`,
      '重新配置连接',
      { type: 'warning', confirmButtonText: '重新填写', cancelButtonText: '保留当前草稿' }
    )
  } catch {
    return
  }
  if (busy.value || draft.value !== target || !canReconfigure.value) return
  try { if (journey.value) releaseJourney() } catch { journeyStorageError.value = true; notice('无法安全解除原开通目标，请先找回原状态。', 'warning'); return }
  draft.value = null
  publicationConfirmationRequired.value = false
  publicationPreview.value = null
  verification.value = null
  verificationRejected.value = false
  verificationAttempted.value = false


  verificationRequest.value = null
  processingLocation.value = ''
  boundEnvironmentId.value = null
  clearKey()
  models.value = []
  remoteModel.value = ''
  modelName.value = ''
  reasoningEffort.value = ''
  skillOutputStrategy.value = 'neuron_json_extract_v1'
  connectionNotice.value = `原部署 ${target.deployment_id} 的修订 ${target.revision} 仍保留在服务器。请在服务商连接中填写新的 Key 或模型标识。`
  notice('可以重新填写服务商连接。新连接不会自动发布或绑定环境。', 'warning')
  await nextTick()
  apiKeyInput.value?.focus()
}

function fail(text: string): void {
  notice(text)
}

function providerProblemCode(error: unknown): string {
  if (!(error instanceof HttpError)) return ''
  return /^SAND_AI_[A-Z_]+(?=:)/.exec(error.message)?.[0] ?? ''
}

function accessFailureNotice(error: unknown): string | null {
  if (!(error instanceof HttpError)) return null
  if (error.code === 403) return '当前后台账号没有执行此操作的权限。请联系 SandAdmin 管理员授权后重试；无需更换服务商 Key。'
  if (error.code === 401) return '登录已失效，请重新登录后读取原修订状态。'
  return null
}

function localInputFailureNotice(error: unknown): string | null {
  if (!(error instanceof HttpError) || error.code !== 400) return null
  switch (providerProblemCode(error)) {
    case 'SAND_AI_ONBOARDING_CONNECTION_INVALID':
      return '连接信息未接受。请填写公共 HTTPS 服务地址和 API Key，删除换行或制表符后重试。'
    case 'SAND_AI_ONBOARDING_HEADER_INVALID':
      return '额外请求头未接受。请核对允许的字段、长度和服务商提供的值后重试。'
    case 'SAND_AI_ONBOARDING_MODEL_INVALID':
      return '模型标识未接受。请使用服务商提供的标识，删除控制字符或路径跳转段后重试。'
    case 'SAND_AI_VALIDATION_ERROR':
      return '配置输入未接受。请核对名称、模型能力、输出上限和超时设置后重新保存。'
    default:
      return null
  }
}

function discoveryFailureNotice(error: unknown): string {
  const inputNotice = localInputFailureNotice(error)
  if (inputNotice) return inputNotice
  const accessNotice = accessFailureNotice(error)
  if (accessNotice) return accessNotice
  // Compare only reviewed local backend text; never render upstream error content.
  if (error instanceof HttpError) {
    const notices: Readonly<Record<string, string>> = {
      'SAND_AI_ONBOARDING_CONNECTION_FORBIDDEN: 服务地址解析到了不允许访问的网络地址': '服务商地址解析到了内网或保留地址，尚未连接服务商。请联系宿主操作员检查 DNS 和网络配置；确认恢复公共地址后再发现模型。',
      'SAND_AI_PROVIDER_REJECTED: 服务商未接受 API Key；请在服务商控制台确认 Key 有效且属于此服务地址': '服务商未接受 API Key，请核对有效性及所属服务地址。',
      'SAND_AI_PROVIDER_REJECTED: 服务商拒绝了账号权限；请确认项目、模型和接口访问权限': '服务商拒绝了账号权限，请核对项目、模型及接口访问权限。',
      'SAND_AI_PROVIDER_REJECTED: 当前服务地址未提供模型列表；请从服务商控制台取得聊天模型标识后手动填写并验证': '当前服务地址未提供模型列表。请从服务商控制台取得聊天模型标识并手动填写，保存草稿后再验证。',
      'SAND_AI_PROVIDER_UNAVAILABLE: 发现模型连接超时；请检查网络和服务地址后重试，也可手动填写服务商提供的模型标识': '模型列表查询超时。请检查网络与服务地址，或从控制台取得模型标识后手动填写。'
    }
    const notice = notices[error.message]
    if (notice) return notice
  }
  switch (providerProblemCode(error)) {
    case 'SAND_AI_PROVIDER_RATE_LIMITED':
      return '服务商暂时限流。请稍后再发现模型，也可手动填写模型标识。'
    case 'SAND_AI_ONBOARDING_CONNECTION_INVALID':
    case 'SAND_AI_ONBOARDING_CONNECTION_FORBIDDEN':
      return '服务地址或连接信息不符合要求。请核对服务商提供的公共 HTTPS API 基础地址与请求头。'
    case 'SAND_AI_ONBOARDING_DNS_UNAVAILABLE':
      return '宿主无法解析服务商地址，尚未连接服务商。请核对地址；地址正确时联系宿主操作员检查 DNS，再重新发现模型。'
    case 'SAND_AI_ONBOARDING_CONNECTION_UNVERIFIABLE':
      return '宿主暂时无法安全连接服务商，尚未发出模型发现请求。请联系宿主操作员检查安全连接能力。'
    case 'SAND_AI_ONBOARDING_RESPONSE_TOO_LARGE':
      return '服务商模型列表超过发现上限。请手动填写服务商控制台中可用的聊天模型标识。'
    default:
      return '模型发现未完成。请检查服务地址，也可手动填写服务商提供的聊天模型标识。'
  }
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

const failureNotices: Readonly<Record<Exclude<VerificationFailureReason, 'local_runtime_verification_failed'>, readonly [VerificationFailure['error_code'], string]>> = {
  invalid_api_key: ['SAND_AI_PROVIDER_REJECTED', '服务商未接受 API Key，请核对有效性及所属服务地址'],
  provider_access_denied: ['SAND_AI_PROVIDER_REJECTED', '服务商拒绝了账号权限，请核对项目、模型和聊天接口权限'],
  model_or_endpoint_unavailable: ['SAND_AI_PROVIDER_REJECTED', '服务商没有提供请求的聊天模型或接口，请核对模型标识与 API 基础地址'],
  chat_endpoint_unsupported: ['SAND_AI_PROVIDER_REJECTED', '服务商未接受聊天接口，请确认模型支持聊天以及 API 基础地址正确'],
  model_or_parameters_rejected: ['SAND_AI_PROVIDER_REJECTED', '服务商拒绝了模型或参数，请核对聊天能力、Token 额度与推理额度'],
  provider_rejected: ['SAND_AI_PROVIDER_REJECTED', '服务商拒绝了验证请求，请核对 Key、权限、模型和参数'],
  provider_timeout: ['SAND_AI_PROVIDER_UNAVAILABLE', '模型验证超时，可能已发送并产生费用，请核对服务商调用记录；不要重复验证此修订'],
  provider_unavailable: ['SAND_AI_PROVIDER_UNAVAILABLE', '模型验证连接或响应未确认，请核对服务商调用记录和当前修订状态'],
  provider_rate_limited: ['SAND_AI_PROVIDER_RATE_LIMITED', '服务商当前限流，请核对当前修订状态和服务商调用记录后决定后续操作'],
  verification_ambiguous: ['SAND_AI_DEPLOYMENT_VERIFICATION_AMBIGUOUS', '当前修订验证结果无法确定，可能已产生费用；请保留部署与修订号并联系管理员核查']
}

function isFailureReason(value: unknown): value is keyof typeof failureNotices {
  return typeof value === 'string' && Object.hasOwn(failureNotices, value)
}

function safeVerificationFailure(state: Verification | null): VerificationFailure | null {
  const failure: unknown = state?.failure
  if (!state || !['draft', 'verification_ambiguous'].includes(state.state) || !isRecord(failure)
    || !isFailureReason(failure.reason)) return null
  const [code, text] = failureNotices[failure.reason]
  const uncertain = state.state === 'verification_ambiguous'
  const expectedMessage = text + (code === 'SAND_AI_PROVIDER_REJECTED'
    ? uncertain ? '；当前修订结果仍无法确定，请先核查服务商调用记录，不要重复验证此修订' : '，修正后可重新配置连接'
    : '')
  if (failure.error_code !== code || failure.message !== expectedMessage
    || failure.outcome_uncertain !== uncertain
    || failure.recovery_action !== (uncertain ? 'check_provider_record' : 'reconfigure_connection')
    || (!uncertain && code !== 'SAND_AI_PROVIDER_REJECTED')
    || (failure.failed_at !== null && (typeof failure.failed_at !== 'string'
      || !/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(failure.failed_at)))) return null
  return { error_code: code, reason: failure.reason, message: expectedMessage,
    recovery_action: uncertain ? 'check_provider_record' : 'reconfigure_connection',
    outcome_uncertain: uncertain, failed_at: failure.failed_at }
}

function matchesDraft(target: DraftSave): boolean {
  return draft.value?.deployment_id === target.deployment_id && draft.value.revision === target.revision
}

function applyVerification(state: Verification): void {
  if (!['draft', 'verifying', 'tested', 'published', 'verification_ambiguous'].includes(state.state)) {
    throw new Error('未知验证状态')
  }
  verification.value = ['tested', 'published', 'verifying'].includes(state.state) ? { ...state, failure: null } : state
  if (state.state !== 'draft') verificationRejected.value = false
  else if (state.failure !== null && state.failure !== undefined) verificationRejected.value = true
  published.value = state.state === 'published'
}

function disabledCatalogItems(result: ServiceCatalogPreflight): string[] {
  const items = result.service_status === 'disabled' ? [`服务 ${result.service_code}`] : []
  for (const code of result.action_codes) {
    if (result.action_statuses?.[code] === 'disabled') items.push(`动作 ${code}`)
  }
  return items
}

function canRegisterCatalog(result: ServiceCatalogPreflight | null): boolean {
  return result?.port_available === true && result.can_register === true
    && (result.service_status === 'missing' || result.service_status === 'active')
    && result.action_codes.length > 0
    && result.action_codes.every(code => ['missing', 'active'].includes(result.action_statuses?.[code]))
}

function catalogPreflightNotice(result: ServiceCatalogPreflight): string {
  if (!result.port_available) return 'SandIAM 服务目录暂不可用。请先完成 SandIAM 安装和配置。'
  const disabled = disabledCatalogItems(result)
  if (disabled.length) return `已停用：${disabled.join('、')}。请由 SandIAM 管理员核查停用决定；此页不会自动恢复。`
  return canRegisterCatalog(result) ? '' : '目录状态尚未确认，暂不能登记。请重新检查或联系 SandIAM 管理员。'
}

function isCatalogDisabled(error: unknown): boolean {
  if (!(error instanceof HttpError)) return false
  const data = error.data
  const details = data !== null && typeof data === 'object'
    ? [Reflect.get(data, 'error_code'), Reflect.get(data, 'message'), Reflect.get(data, 'msg')]
    : []
  return [error.message, ...details].some(
    value => typeof value === 'string' && (
      value.startsWith('SAND_IAM_SERVICE_CATALOG_DISABLED')
      || value.startsWith('SAND_AI_SERVICE_CATALOG_DISABLED')
    )
  )
}

async function loadCatalog(): Promise<void> {
  if (catalogBusy.value) return
  const generation = ++catalogGeneration
  catalogBusy.value = 'checking'
  catalogMessage.value = ''
  catalogDisabled.value = false
  try {
    const result = await onboardingApi.serviceCatalogPreflight()
    if (generation !== catalogGeneration) return
    if (result.service_code !== 'sand_ai' || !Array.isArray(result.action_codes)) {
      throw new Error('Unexpected service catalog declaration')
    }
    catalogPreflight.value = result
    catalogDisabled.value = disabledCatalogItems(result).length > 0
    catalogMessage.value = catalogPreflightNotice(result)
    if (catalogMessage.value) catalogMessageType.value = 'warning'
  } catch {
    if (generation === catalogGeneration) {
      catalogPreflight.value = null
      catalogMessage.value = '无法检查 SandIAM 服务目录，请确认后台权限与插件安装状态。'
      catalogMessageType.value = 'warning'
    }
  } finally {
    if (generation === catalogGeneration) catalogBusy.value = ''
  }
}

async function registerCatalog(): Promise<void> {
  if (catalogBusy.value || !canRegisterCatalog(catalogPreflight.value) || catalogDisabled.value || catalogRegistered.value) return
  const confirmedGeneration = catalogGeneration
  try {
    await ElMessageBox.confirm(
      `将向 SandIAM 登记 SandAI 的 ${catalogPreflight.value?.action_codes.length ?? 0} 个服务动作。此操作不会为应用或客户端创建授权。`,
      '登记服务目录',
      { type: 'warning', confirmButtonText: '确认登记' }
    )
  } catch {
    return
  }
  if (confirmedGeneration !== catalogGeneration
    || catalogBusy.value || !canRegisterCatalog(catalogPreflight.value) || catalogDisabled.value || catalogRegistered.value) return
  const generation = ++catalogGeneration
  catalogBusy.value = 'registering'
  catalogMessage.value = ''
  catalogDisabled.value = false
  try {
    const result = await onboardingApi.registerServiceCatalog()
    if (generation !== catalogGeneration) return
    if (!result.registered || result.service_code !== 'sand_ai' || !Array.isArray(result.action_codes)) {
      throw new Error('Unexpected service catalog registration result')
    }
    catalogRegistered.value = true
    catalogMessage.value = 'SandAI 服务动作已登记。请到 SandIAM 为目标应用和工作负载客户端配置授权。'
    catalogMessageType.value = 'success'
  } catch (error) {
    if (generation === catalogGeneration) {
      catalogDisabled.value = isCatalogDisabled(error)
      catalogMessage.value = catalogDisabled.value
        ? 'SandIAM 中的 SandAI 服务或动作已停用。请由 SandIAM 管理员核查停用决定；此页不会自动恢复。'
        : '登记结果未确认。请先到 SandIAM 核查服务目录，再决定是否重试。'
      catalogMessageType.value = 'warning'
    }
  } finally {
    if (generation === catalogGeneration) catalogBusy.value = ''
  }
}

async function loadSkillCatalog(): Promise<void> {
  if (skillCatalogBusy.value) return
  const generation = ++skillCatalogGeneration
  skillCatalogBusy.value = 'checking'
  skillCatalogMessage.value = ''
  skillCatalogDisabled.value = false
  try {
    const result = await onboardingApi.skillServiceCatalogPreflight()
    if (generation !== skillCatalogGeneration) return
    if (result.service_code !== 'sand_ai' || !hasSkillCatalogActions(result.action_codes)) {
      throw new Error('Unexpected Skill service catalog declaration')
    }
    skillCatalogPreflight.value = result
    skillCatalogDisabled.value = disabledCatalogItems(result).length > 0
    skillCatalogMessage.value = catalogPreflightNotice(result)
    if (skillCatalogMessage.value) skillCatalogMessageType.value = 'warning'
  } catch {
    if (generation === skillCatalogGeneration) {
      skillCatalogPreflight.value = null
      skillCatalogMessage.value = '无法检查 Skill 服务动作，请确认后台权限与 SandIAM 状态。'
      skillCatalogMessageType.value = 'warning'
    }
  } finally {
    if (generation === skillCatalogGeneration) skillCatalogBusy.value = ''
  }
}

async function registerSkillCatalog(): Promise<void> {
  if (skillCatalogBusy.value || !canRegisterCatalog(skillCatalogPreflight.value) || skillCatalogDisabled.value || skillCatalogRegistered.value) return
  const confirmedGeneration = skillCatalogGeneration
  try {
    await ElMessageBox.confirm(
      '将登记 Skill 的运行、读取、管理、测试和发布五项服务动作。此操作不会创建应用或授予客户端调用权限。',
      '登记个人 Skill 服务动作',
      { type: 'warning', confirmButtonText: '确认登记' }
    )
  } catch {
    return
  }
  if (confirmedGeneration !== skillCatalogGeneration
    || skillCatalogBusy.value || !canRegisterCatalog(skillCatalogPreflight.value) || skillCatalogDisabled.value || skillCatalogRegistered.value) return
  const generation = ++skillCatalogGeneration
  skillCatalogBusy.value = 'registering'
  skillCatalogMessage.value = ''
  skillCatalogDisabled.value = false
  try {
    const result = await onboardingApi.registerSkillServiceCatalog()
    if (generation !== skillCatalogGeneration) return
    if (!result.registered || result.service_code !== 'sand_ai'
      || !hasSkillCatalogActions(result.action_codes)) {
      throw new Error('Unexpected Skill service catalog registration result')
    }
    skillCatalogRegistered.value = true
    skillCatalogMessage.value = 'Skill 服务动作已登记。请到 SandIAM 为目标应用和客户端配置授权。'
    skillCatalogMessageType.value = 'success'
  } catch (error) {
    if (generation === skillCatalogGeneration) {
      skillCatalogDisabled.value = isCatalogDisabled(error)
      skillCatalogMessage.value = skillCatalogDisabled.value
        ? 'SandIAM 中的服务或 Skill 动作已停用。请由 SandIAM 管理员核查；此页不会自动恢复。'
        : '登记结果未确认。请先到 SandIAM 核查，再决定是否重试。'
      skillCatalogMessageType.value = 'warning'
    }
  } finally {
    if (generation === skillCatalogGeneration) skillCatalogBusy.value = ''
  }
}

async function loadAudioCatalog(): Promise<void> {
  if (audioCatalogBusy.value) return
  const generation = ++audioCatalogGeneration
  audioCatalogBusy.value = 'checking'
  audioCatalogMessage.value = ''
  audioCatalogDisabled.value = false
  try {
    const result = await onboardingApi.audioServiceCatalogPreflight()
    if (generation !== audioCatalogGeneration) return
    if (result.service_code !== 'sand_ai' || !Array.isArray(result.action_codes)
      || !hasRequiredAudioActions(result.action_codes)) {
      throw new Error('Unexpected audio service catalog declaration')
    }
    audioCatalogPreflight.value = result
    audioCatalogDisabled.value = disabledCatalogItems(result).length > 0
    audioCatalogMessage.value = catalogPreflightNotice(result)
    if (audioCatalogMessage.value) audioCatalogMessageType.value = 'warning'
  } catch {
    if (generation === audioCatalogGeneration) {
      audioCatalogPreflight.value = null
      audioCatalogMessage.value = '无法检查音频服务动作，请确认后台权限与 SandIAM 状态。'
      audioCatalogMessageType.value = 'warning'
    }
  } finally {
    if (generation === audioCatalogGeneration) audioCatalogBusy.value = ''
  }
}

function audioAdapterState(kind: 'local' | 'external'): string {
  const adapter = audioRuntime.value?.adapter_kinds.find(item => item.technical.adapter_kind === kind)
  return adapter?.runtime_state === 'configured' ? '宿主配置可用，仍需真实录音验证' : '未配置'
}

async function loadAudioRuntime(): Promise<void> {
  if (audioRuntimeBusy.value) return
  const generation = ++audioRuntimeGeneration
  audioRuntimeBusy.value = true
  audioRuntimeError.value = ''
  try {
    const result = await onboardingApi.audioRuntimeMetadata()
    if (generation === audioRuntimeGeneration) audioRuntime.value = result
  } catch {
    if (generation === audioRuntimeGeneration) {
      audioRuntime.value = null
      audioRuntimeError.value = '无法检查转写引擎，请核对后台权限和宿主配置。'
    }
  } finally {
    if (generation === audioRuntimeGeneration) audioRuntimeBusy.value = false
  }
}

async function registerAudioCatalog(): Promise<void> {
  if (audioCatalogBusy.value || !canRegisterCatalog(audioCatalogPreflight.value) || audioCatalogDisabled.value || audioCatalogRegistered.value) return
  const confirmedGeneration = audioCatalogGeneration
  try {
    await ElMessageBox.confirm(
      '将登记音频转写、片段复核、语音合成和结果读取动作。此操作不会创建应用或授予客户端调用权限。',
      '登记音频服务动作',
      { type: 'warning', confirmButtonText: '确认登记' }
    )
  } catch {
    return
  }
  if (confirmedGeneration !== audioCatalogGeneration
    || audioCatalogBusy.value || !canRegisterCatalog(audioCatalogPreflight.value) || audioCatalogDisabled.value || audioCatalogRegistered.value) return
  const generation = ++audioCatalogGeneration
  audioCatalogBusy.value = 'registering'
  audioCatalogMessage.value = ''
  audioCatalogDisabled.value = false
  try {
    const result = await onboardingApi.registerAudioServiceCatalog()
    if (generation !== audioCatalogGeneration) return
    if (!result.registered || result.service_code !== 'sand_ai'
      || !Array.isArray(result.action_codes) || !hasRequiredAudioActions(result.action_codes)) {
      throw new Error('Unexpected audio service catalog registration result')
    }
    audioCatalogRegistered.value = true
    audioCatalogMessage.value = '音频服务动作已登记。请到 SandIAM 为目标应用和客户端配置授权。'
    audioCatalogMessageType.value = 'success'
  } catch (error) {
    if (generation === audioCatalogGeneration) {
      audioCatalogDisabled.value = isCatalogDisabled(error)
      audioCatalogMessage.value = audioCatalogDisabled.value
        ? 'SandIAM 中的服务或音频动作已停用。请由 SandIAM 管理员核查；此页不会自动恢复。'
        : '登记结果未确认。请先到 SandIAM 核查，再决定是否重试。'
      audioCatalogMessageType.value = 'warning'
    }
  } finally {
    if (generation === audioCatalogGeneration) audioCatalogBusy.value = ''
  }
}

async function load(): Promise<void> {
  // Guide reloads must not invalidate a saved verification request or its busy cleanup.
  const current = epoch
  const generation = ++guideGeneration
  const ownsLoad = () => current === epoch && generation === guideGeneration
  loading.value = true
  message.value = ''
  guideError.value = ''
  try {
    const [catalog, guides] = await Promise.all([onboardingApi.guides(), onboardingApi.providerGuides()])
    if (!ownsLoad()) return
    solutionGuides.value = catalog.templates
    if (!solutionGuides.value.some(item => item.technical.code === selectedScene.value)) {
      selectedScene.value = solutionGuides.value.find(item => item.technical.code === 'material_organization')?.technical.code
        ?? solutionGuides.value[0]?.technical.code ?? ''
    }
    providerGuides.value = guides.guides.filter(item => item.adapter === 'openai_compatible')
    selectedProviderCode.value = providerGuides.value[0]?.provider_code || providerGuides.value[0]?.adapter || ''
    if (!guide.value) fail('首次开通目标暂不可用，请联系管理员检查场景目录。')
  } catch (error) {
    if (ownsLoad()) {
      solutionGuides.value = []
      guideError.value = error instanceof HttpError && error.code === 403
        ? '请联系 SandAdmin 管理员授予首次开通读取权限后重试。'
        : accessFailureNotice(error) || '无法读取场景引导或服务商说明，请稍后重试。'
      fail(guideError.value)
    }
  } finally {
    if (ownsLoad()) loading.value = false
  }
  if (ownsLoad()) void loadApplications()
}

async function restoreConfigurationLocation(current: number): Promise<void> {
  if (route.query.configuration_return !== 'material_guide') return
  const rawApplication = route.query.application_id
  const rawEnvironment = route.query.environment_id
  const code = route.query.material_profile_code
  const version = route.query.material_profile_version
  const positive = (value: unknown): value is string => typeof value === 'string'
    && /^[1-9][0-9]*$/.test(value) && Number.isSafeInteger(Number(value))
  if (!positive(rawApplication) || !positive(rawEnvironment)
    || ((code !== undefined || version !== undefined) && (typeof code !== 'string'
      || !/^[a-z][a-z0-9_]{0,63}$/.test(code) || typeof version !== 'string'
      || version.length > 32 || !/^\d+\.\d+\.\d+$/.test(version)))) {
    navigationNotice.value = '原引导配置链接不完整，请选择原应用、环境和材料配置后继续。'
    return
  }
  const application = Number(rawApplication); const environment = Number(rawEnvironment)
  if (!applications.value.some(item => item.id === application)
    || (journey.value && (journey.value.application_id !== application || journey.value.environment_id !== environment))) {
    navigationNotice.value = '原引导应用已不可读，或与尚未确认的开通目标不同。请先处理原开通记录，不会替换原目标。'
    return
  }
  const selectedApplication = applicationId.value; const selectedEnvironment = environmentId.value
  // Supersede any earlier environment read, even when returning to the same application.
  const locationEnvironmentGeneration = ++environmentGeneration
  try {
    const rows = await iamApi.activeEnvironments(application)
    if (!live || current !== epoch || locationEnvironmentGeneration !== environmentGeneration
      || applicationId.value !== selectedApplication || environmentId.value !== selectedEnvironment) return
    if (!rows.some(item => item.id === environment && item.application_id === application)) {
      navigationNotice.value = '原引导环境已不可读或不属于原应用，请重新核对；链接不会授予访问权限。'
      return
    }
    if (journey.value && (journey.value.application_id !== application || journey.value.environment_id !== environment)) {
      navigationNotice.value = '原引导目标与正在找回的开通记录不同，请先处理原开通记录。'
      return
    }
    restoringConfigurationLocation = true
    applicationId.value = application; environments.value = rows; environmentId.value = environment
    restoringConfigurationLocation = false
    selectedScene.value = 'material_organization'
    navigationNotice.value = ''
    if (typeof code === 'string' && typeof version === 'string') {
      const response = await materialProfileApi.read(environment, code, version)
      if (!live || current !== epoch || locationEnvironmentGeneration !== environmentGeneration
        || applicationId.value !== application || environmentId.value !== environment) return
      if (response.profile.environment_id !== environment || response.profile.code !== code || response.profile.version !== version)
        throw new Error('原材料配置关联不一致')
      navigationNotice.value = `已返回原材料配置：${response.profile.name}。代码 ${code} · 版本 ${version}；请按当前状态继续编辑或复制新版本。`
    }
  } catch {
    if (live && current === epoch && locationEnvironmentGeneration === environmentGeneration) navigationNotice.value = '原引导配置当前不可读取。原代码和版本保留，请核对当前权限后重新读取，不会新建替代配置。'
  } finally { if (current === epoch) restoringConfigurationLocation = false }
}
watch([applicationId, environmentId], () => {
  if (restoringConfigurationLocation || !applicationId.value || !environmentId.value
    || !applications.value.some(item => item.id === applicationId.value)
    || !environments.value.some(item => item.id === environmentId.value && item.application_id === applicationId.value)) return
  const query = { ...route.query }
  if (query.application_id !== String(applicationId.value) || query.environment_id !== String(environmentId.value)) {
    delete query.material_profile_code; delete query.material_profile_version; delete query.material_run_id
    delete query.configuration_return
  }
  query.application_id = String(applicationId.value); query.environment_id = String(environmentId.value)
  void router.replace({ query })
}, { flush: 'sync' })

async function loadApplications(): Promise<void> {
  const current = epoch
  loadingIam.value = true
  iamError.value = false
  try {
    const rows = await iamApi.activeApplications()
    if (current !== epoch) return
    applications.value = rows
    await restoreConfigurationLocation(current)
  } catch {
    if (current === epoch) {
      applications.value = []
      iamError.value = true
    }
  } finally {
    if (current === epoch) { loadingIam.value = false; configurationEntryLoaded = true }
  }
}

async function loadEnvironments(): Promise<void> {
  const generation = ++environmentGeneration
  environmentId.value = ''
  environments.value = []
  if (!applicationId.value) {
    loadingIam.value = false
    return
  }
  const selected = applicationId.value
  const current = epoch
  loadingIam.value = true
  iamError.value = false
  try {
    const rows = await iamApi.activeEnvironments(selected)
    if (current !== epoch || generation !== environmentGeneration || selected !== applicationId.value) return
    environments.value = rows
    iamError.value = false
  } catch {
    if (current === epoch && generation === environmentGeneration && selected === applicationId.value) iamError.value = true
  } finally {
    if (current === epoch && generation === environmentGeneration) loadingIam.value = false
  }
}

async function discover(): Promise<void> {
  if (busy.value || verificationRecoveryPending.value || verificationStorageError.value || journeyLocked.value || !canConnect.value) return
  const flight = ++connectionFlightSerial
  connectionSubmitting.value = true
  try {
    busy.value = 'discover'
    const current = epoch
    const generation = discoveryGeneration
    models.value = []
    connectionNotice.value = ''
    try {
      const result = await onboardingApi.discover(connectionSnapshot())
      if (current !== epoch || generation !== discoveryGeneration) return
      models.value = result.models
      notice(result.models.length ? '已发现模型；也可以手动填写其他模型标识。' : '服务商未返回模型列表，请从控制台取得聊天模型标识后手动填写；保存草稿后仍需验证。', 'success')
    } catch (error) {
      if (current === epoch && generation === discoveryGeneration) notice(discoveryFailureNotice(error), 'warning')
    } finally {
      if (current === epoch) busy.value = ''
    }
  } finally {
    if (flight === connectionFlightSerial) connectionSubmitting.value = false
  }
}

function readChatDraftSettings(saved: DraftSave): { remoteModel: string; maxOutputTokens: number; effort: '' | ReasoningEffort; strategy: SkillOutputStrategy } {
  const config = saved.deployment.config
  const effort = config.reasoning_effort
  const strategy = config.skill_output_strategy
  if (saved.model.type !== 'chat' || typeof config.max_output_tokens !== 'number'
    || !Number.isInteger(config.max_output_tokens) || config.max_output_tokens < 1 || config.max_output_tokens > 131072
    || (effort !== undefined && effort !== 'low' && effort !== 'high' && effort !== 'max')
    || (strategy !== undefined && strategy !== 'neuron_json_extract_v1' && strategy !== 'neuron_zai_json_object_v1' && strategy !== 'neuron_openai_json_schema_v1')) {
    throw new Error('聊天模型修订配置无效')
  }
  return { remoteModel: saved.deployment.remote_model, maxOutputTokens: config.max_output_tokens, effort: effort ?? '', strategy: strategy ?? 'neuron_json_extract_v1' }
}

function hydrateDraftConfiguration(saved: DraftSave): void {
  const settings = readChatDraftSettings(saved)
  remoteModel.value = settings.remoteModel
  maxOutputTokens.value = settings.maxOutputTokens
  reasoningEffort.value = settings.effort
  skillOutputStrategy.value = settings.strategy
}

async function loadInspectionDeployments(): Promise<void> {
  if (inspectionLoading.value || inspectionReading.value) return
  inspectionLoading.value = true
  const generation = ++inspectionGeneration
  try {
    const result = await onboardingApi.publishedDeployments('chat', inspectionSearchName.value.trim())
    if (generation !== inspectionGeneration) return
    if (!Array.isArray(result.data) || result.data.some(item => !Number.isSafeInteger(item.id) || item.id < 1 || !Number.isSafeInteger(item.deployment_revision) || item.deployment_revision < 1 || typeof item.display_name !== 'string' || item.model_type !== 'chat' || item.publication_status !== 'published')) throw new Error('部署列表无效')
    inspectionDeployments.value = result.data
    if (!result.data.length) notice('没有匹配的已发布聊天模型。可调整名称筛选；历史修订可用原保存回执编号读取。', 'warning')
    else if (result.total > result.per_page) notice('已发布模型超过当前页，请输入名称筛选后读取部署列表。', 'warning')
  } catch (error) {
    if (generation === inspectionGeneration) notice(accessFailureNotice(error) || '部署列表读取失败。请检查当前管理权限后重试。', 'warning')
  } finally {
    if (generation === inspectionGeneration) inspectionLoading.value = false
  }
}

function chooseInspectionDeployment(id: number): void {
  const selected = inspectionDeployments.value.find(item => item.id === id)
  inspectedRevision.value = null
  inspectionTargetId.value = selected?.id
  inspectionTargetRevision.value = selected?.deployment_revision
}

async function readInspectionRevision(): Promise<void> {
  if (inspectionLoading.value || inspectionReading.value || !validInspectionTarget.value) return
  const deploymentId = inspectionTargetId.value
  const revision = inspectionTargetRevision.value
  if (deploymentId === undefined || revision === undefined) return
  const generation = ++inspectionGeneration
  inspectedRevision.value = null
  inspectionReading.value = true
  try {
    const saved = await onboardingApi.readDraft(deploymentId, revision)
    if (generation !== inspectionGeneration) return
    if (saved.deployment_id !== deploymentId || saved.revision !== revision || !Number.isSafeInteger(saved.provider.id) || saved.provider.id < 1 || !Number.isSafeInteger(saved.model.id) || saved.model.id < 1) throw new Error('返回的部署修订与请求不一致')
    readChatDraftSettings(saved)
    // Keep the exact server DTO, including absent fields. Do not populate the
    // mutable new-connection draft or unlock verification/publish controls.
    inspectedRevision.value = saved
    notice('已只读取得原修订保存值。不会重新发送模型验证或写入配置。', 'success')
  } catch (error) {
    if (generation === inspectionGeneration) notice(accessFailureNotice(error) || '原修订读取失败。请核对保存回执与当前管理权限。', 'warning')
  } finally {
    if (generation === inspectionGeneration) inspectionReading.value = false
  }
}

function readInspectionRoute(): void {
  const id = route.query.deployment_id
  const revision = route.query.revision
  if (id === undefined && revision === undefined) return
  if (typeof id !== 'string' || typeof revision !== 'string' || !/^[1-9][0-9]*$/.test(id) || !/^[1-9][0-9]*$/.test(revision) || !Number.isSafeInteger(Number(id)) || !Number.isSafeInteger(Number(revision))) {
    notice('部署修订链接无效。请从原保存回执或已发布模型列表读取。', 'warning')
    return
  }
  inspectionTargetId.value = Number(id)
  inspectionTargetRevision.value = Number(revision)
  void readInspectionRevision()
}

async function saveDraft(): Promise<void> {
  if (busy.value || draft.value || verificationRecoveryPending.value || verificationStorageError.value || journeyLocked.value || (!saveAttempt.value && !canSave.value)) return
  const flight = ++connectionFlightSerial
  connectionSubmitting.value = true
  try {
    busy.value = 'save'
    const current = epoch
    if (!saveAttempt.value) {
      const suffix = crypto.randomUUID().replaceAll('-', '').slice(0, 12)
      const selectedReasoningEffort = reasoningEffort.value
      saveAttempt.value = {
        key: crypto.randomUUID(),
        hadUncertainOutcome: false,
        payload: {
          provider: { code: `onboard_${suffix}`, name: providerName.value, adapter: 'openai_compatible', timeout_ms: providerTimeoutSeconds.value * 1000 },
          model: { code: `chat_${suffix}`, name: modelName.value || defaultModelName.value, type: 'chat',
            capabilities: materialExtraction.value ? ['chat', 'structured_extraction'] : ['chat'] },
          connection: connectionSnapshot(),
          remote_model: remoteModel.value,
          config: {
            max_output_tokens: maxOutputTokens.value,
            skill_output_strategy: skillOutputStrategy.value,
            ...(selectedReasoningEffort ? { reasoning_effort: selectedReasoningEffort } : {})
          }
        }
      }
    }
    const attempt = saveAttempt.value
    try {
      if (readStoredJourney()) throw new Error('Original journey already exists')
      const selectedApp = applicationId.value || null
      const selectedEnv = environmentId.value || null
      if ((selectedApp !== null && !applications.value.some(item => item.id === selectedApp))
        || (selectedEnv !== null && !environments.value.some(item => item.id === selectedEnv && item.application_id === selectedApp))) throw new Error('Selected IAM target unavailable')
      persistJourney({ version: 1, admin_id: journeyOwner(), application_id: selectedApp, environment_id: selectedEnv,
        key: attempt.key, provider_code: attempt.payload.provider.code, model_code: attempt.payload.model.code, model_type: 'chat',
        phase: 'save_unknown', deployment_id: null, revision: null, provider_id: null, model_id: null })
    } catch {
      journeyStorageError.value = true
      clearKey()
      busy.value = ''
      notice('无法可靠保留原保存请求，本次尚未发送。请恢复会话存储并只读找回已有开通记录后继续。', 'warning')
      return
    }
    try {
      const saved = await onboardingApi.save(attempt.payload, attempt.key)
      if (current !== epoch || saveAttempt.value !== attempt) return
      if (!journey.value) throw new Error('Original journey missing')
      persistJourney(savedJourney(journey.value, saved))
      hydrateDraftConfiguration(saved)
      draft.value = saved
      verification.value = { deployment_id: saved.deployment_id, revision: saved.revision, state: saved.state, validation_evidence: null }
      verificationRejected.value = false
      verificationAttempted.value = false


      clearKey()
      notice('草稿已保存。请确认处理位置后验证聊天模型。', 'success')
    } catch (error) {
      if (current === epoch && saveAttempt.value === attempt) {
        const inputNotice = localInputFailureNotice(error)
        if (inputNotice && error instanceof HttpError && isRecord(error.data)
          && error.data.submission_state === 'not_submitted'
          && error.data.error_code === providerProblemCode(error) && !attempt.hadUncertainOutcome) {
          // Only the explicit pre-save proof unlocks this intent. Unknown outcomes
          // keep the original frozen intent/key and must not unlock a fresh save.
          try {
            const original = readStoredJourney()
            if (!original || original.key !== attempt.key || original.phase !== 'save_unknown') throw new Error('Original intent changed')
            sessionStorage.removeItem(journeyIndex())
            sessionStorage.removeItem(journeyNamespace(original))
            journey.value = null
            saveAttempt.value = null
            notice(inputNotice, 'warning')
          } catch { journeyStorageError.value = true; notice('本次输入未提交，但原本机记录未能安全解除。请恢复会话存储并找回原状态。', 'warning') }
        } else {
          attempt.hadUncertainOutcome = true
          clearKey()
          notice(accessFailureNotice(error) || '草稿保存未确认。请只读找回原保存回执；未找到或读取失败不会解除原请求锁，也不会重发保存。', 'warning')
        }
      }
    } finally {
      if (current === epoch) busy.value = ''
    }
  } finally {
    if (flight === connectionFlightSerial) connectionSubmitting.value = false
  }
}

async function readVerification(): Promise<void> {
  const request = verificationRecoveryPending.value ? verificationRequest.value : null
  const target = request ?? draft.value
  if (!target || busy.value || (request && request.admin_id !== Number(userStore.info.id))) return
  busy.value = 'read'
  const current = epoch
  const ownsRead = () => current === epoch && (!request || verificationRequest.value === request)
  try {
    const [saved, state] = await Promise.all([
      onboardingApi.readDraft(target.deployment_id, target.revision),
      onboardingApi.readVerification(target.deployment_id, target.revision, request?.key)
    ])
    if (!ownsRead()) return
    if (saved.deployment_id !== target.deployment_id || saved.revision !== target.revision
      || state.deployment_id !== target.deployment_id || state.revision !== target.revision) {
      throw new Error('返回的部署修订与请求不一致')
    }
    if (journey.value) assertJourneyDraft(saved, journey.value)
    hydrateDraftConfiguration(saved)
    draft.value = saved
    applyVerification(state)
    if (saved.state === 'published' && state.state === 'tested' && state.validation_evidence) applyVerification({ ...state, state: 'published' })
    if (request && originalAttemptIsTerminal(state)) {
      resolveVerificationRequest(request)
      verificationStorageError.value = false
      if (state.state === 'draft') verificationRejected.value = true
      if (saved.state === 'published' && state.state === 'tested' && state.validation_evidence) {
        applyVerification({ ...state, state: 'published' })
      }
    }
    if (verificationRecoveryPending.value) notice('原验证尚无明确终态。请继续用原请求号只读找回；不会换号验证或重新配置。', 'warning')
    else if (verificationFailure.value) notice('已读取原验证的失败状态，请查看下方恢复提示。', 'warning')
    else if (state.state === 'verifying') notice('验证仍在进行。稍后读取同一修订状态。', 'warning')
    else if (state.state === 'verification_ambiguous') notice('验证结果无法确定，可能已产生服务商费用。请保留部署、修订和原请求号，联系管理员核查；不会自动重试。', 'warning')
    else if (state.state === 'draft' && (verificationRejected.value || verificationAttempted.value || hasVerificationFailure.value)) notice('已读取当前修订状态，请查看下方恢复提示。', 'warning')
    else if (state.validation_evidence?.usage_measured === false) notice('模型已验证；服务商未返回 Token 用量，费用暂不可确认。', 'warning')
    else notice(`当前修订状态：${verificationStateLabel.value}`, 'success')
  } catch (error) {
    if (ownsRead()) notice(accessFailureNotice(error) || '无法确认原验证尝试。未找到或读取失败不表示未发送；请保留原请求号并继续只读核查。', 'warning')
  } finally {
    if (ownsRead()) busy.value = ''
  }
}

async function restoreVerificationRequest(): Promise<void> {
  if (busy.value) return
  try {
    const stored = readStoredVerification()
    verificationStorageError.value = false
    if (!stored) {
      // A missing entry cannot discard an unresolved in-memory request.
      if (verificationRecoveryPending.value) throw new Error('Original request disappeared')
      return
    }
    verificationRequest.value = stored
    verificationRecoveryPending.value = true
    verificationAttempted.value = true
    await readVerification()
  } catch {
    verificationStorageError.value = true
    notice('原验证记录无法读取。不能据此认定请求未发送，也不会生成新请求号。', 'warning')
  }
}

async function verifyDraft(): Promise<void> {
  if (!draft.value || !canVerify.value) return
  const flight = ++connectionFlightSerial
  connectionSubmitting.value = true
  try {
    const target = draft.value
    let request: VerificationRequest
    try {
      const stored = readStoredVerification()
      if (stored) {
        verificationRequest.value = stored
        verificationRecoveryPending.value = true
        await readVerification()
        return
      }
      const namespace = verificationNamespace()
      if (!namespace) throw new Error('Administrator unavailable')
      request = { admin_id: Number(userStore.info.id), deployment_id: target.deployment_id, revision: target.revision, key: crypto.randomUUID() }
      sessionStorage.setItem(namespace, JSON.stringify(request))
      const frozen = readStoredVerification()
      if (!frozen || frozen.key !== request.key || frozen.deployment_id !== request.deployment_id || frozen.revision !== request.revision) {
        throw new Error('Original verification intent changed')
      }
      verificationRequest.value = request
      verificationRecoveryPending.value = true
    } catch {
      verificationStorageError.value = true
      notice('无法保留原验证请求号，本次尚未发送。请恢复本机会话存储后找回原验证状态。', 'warning')
      return
    }
    busy.value = 'verify'
    const current = epoch
    // Until the precise revision is read back, a failed request may already
    // have reached the provider. Never expose a new-connection action from
    // the old local "draft" state after a timeout or a failed status read.
    verificationAttempted.value = true


    verification.value = { deployment_id: target.deployment_id, revision: target.revision, state: 'verifying', validation_evidence: null }
    try {
      const result = await onboardingApi.verify(target.deployment_id, target.revision, processingLocation.value, request.key, 150000)
      if (current !== epoch || draft.value !== target) return
      if (result.deployment_id !== target.deployment_id || result.revision !== target.revision
        || result.state !== 'tested') throw new Error('返回的验证修订或状态不一致')
      if (!result.validation_evidence) throw new Error('验证证据缺失')
      applyVerification(result)
      resolveVerificationRequest(request)
      notice(result.validation_evidence?.usage_measured === false
        ? '聊天模型验证通过；服务商未返回 Token 用量，运行费用暂不可确认。'
        : '聊天模型验证通过。发布后仍需配置所选目标的业务能力。',
      result.validation_evidence?.usage_measured === false ? 'warning' : 'success')
    } catch (error) {
      if (current === epoch && matchesDraft(target)) {
        if (providerProblemCode(error) === 'SAND_AI_PROVIDER_REJECTED') verificationRejected.value = true

        // An access denial does not substitute for a receipt associated with the original key.
        notice(accessFailureNotice(error) || '验证未确认。请读取同一修订状态；结果不明时不要重复验证。', 'warning')
        busy.value = ''
        await readVerification()
      }
    } finally {
      if (current === epoch && matchesDraft(target)) busy.value = ''
    }
  } finally {
    if (flight === connectionFlightSerial) connectionSubmitting.value = false
  }
}

async function readCurrentPublicationRevision(): Promise<void> {
  const target = draft.value
  if (!target || busy.value || !publicationConfirmationRequired.value || verificationRecoveryPending.value || verificationStorageError.value) return
  busy.value = 'read'
  publicationPreview.value = null
  const current = epoch
  try {
    const saved = await onboardingApi.readDraft(target.deployment_id)
    if (current !== epoch || !matchesDraft(target)) return
    if (saved.deployment_id !== target.deployment_id || !Number.isSafeInteger(saved.revision) || saved.revision < 1) throw new Error('当前修订不一致')
    readChatDraftSettings(saved)
    if (journey.value && (saved.provider.code !== journey.value.provider_code || saved.model.code !== journey.value.model_code || saved.model.type !== journey.value.model_type || !positiveId(saved.provider.id) || !positiveId(saved.model.id))) throw new Error('Original deployment identity differs')
    const state = await onboardingApi.readVerification(saved.deployment_id, saved.revision)
    if (current !== epoch || !matchesDraft(target)) return
    if (state.deployment_id !== saved.deployment_id || state.revision !== saved.revision || state.state !== saved.state
      || !['draft', 'verifying', 'tested', 'published', 'verification_ambiguous'].includes(state.state)) throw new Error('当前修订状态不一致')
    publicationPreview.value = { draft: saved, verification: state }
    notice('已读取当前修订。请核对上方模型、配置和状态后明确确认；本页不会自动验证或发布。', 'warning')
  } catch (error) {
    if (current === epoch && matchesDraft(target)) notice(accessFailureNotice(error) || '当前部署修订读取未确认，请重试读取；保留原目标，不会自动发布或解除原验证锁。', 'warning')
  } finally {
    if (current === epoch && matchesDraft(target)) busy.value = ''
  }
}

async function confirmCurrentPublicationRevision(): Promise<void> {
  const preview = publicationPreview.value
  if (!preview || busy.value || verificationRecoveryPending.value || verificationStorageError.value || journeyLocked.value) return
  const current = epoch
  try {
    await ElMessageBox.confirm(
      `确认切换到部署 ${preview.draft.deployment_id} 的修订 ${preview.draft.revision}，模型 ${preview.draft.model.name}（${preview.draft.deployment.remote_model}）？这只更新本页操作目标，不会验证或发布。`,
      '确认当前修订', { confirmButtonText: '确认此修订', cancelButtonText: '保留原目标', type: 'warning' }
    )
  } catch { return }
  if (current !== epoch || publicationPreview.value !== preview || busy.value || verificationRecoveryPending.value || verificationStorageError.value || journeyLocked.value) return
  try {
    const stored = readStoredJourney()
    if (!stored || stored.phase !== 'saved') throw new Error('Original journey unresolved')
    if (preview.draft.provider.code !== stored.provider_code || preview.draft.model.code !== stored.model_code || preview.draft.model.type !== 'chat') throw new Error('Original deployment identity changed')
    persistJourney({ ...stored, deployment_id: preview.draft.deployment_id, revision: preview.draft.revision, provider_id: preview.draft.provider.id, model_id: preview.draft.model.id })
  } catch { journeyStorageError.value = true; notice('无法可靠保留已确认的修订，请先找回原开通状态。', 'warning'); return }
  hydrateDraftConfiguration(preview.draft)
  draft.value = preview.draft
  applyVerification(preview.verification)
  verificationRequest.value = null // Any unresolved original request blocks reaching this explicit confirmation.
  verificationAttempted.value = preview.verification.state !== 'draft' || hasVerificationFailure.value
  verificationRejected.value = preview.verification.state === 'draft' && hasVerificationFailure.value
  boundEnvironmentId.value = null
  publicationConfirmationRequired.value = false
  publicationPreview.value = null
  notice(published.value ? '已确认当前修订已经发布；可按实际状态继续绑定环境。' : '已确认当前修订。请按其实际状态选择验证、发布或重新配置；不会自动执行。', 'success')
}

async function confirmOriginalPublication(): Promise<void> {
  if (busy.value || originalPublicationConfirming.value || journeyRecoveryPending.value || verificationRecoveryPending.value || verificationStorageError.value
    || journey.value?.phase !== 'publish_unknown') return
  const current = epoch
  originalPublicationConfirming.value = true
  busy.value = 'read'
  try {
    const stored = readStoredJourney()
    if (!stored || stored.phase !== 'publish_unknown' || !positiveId(stored.deployment_id) || !positiveId(stored.revision)
      || stored.key !== journey.value.key) throw new Error('Original publication target unavailable')
    const [saved, state] = await Promise.all([
      onboardingApi.readDraft(stored.deployment_id, stored.revision),
      onboardingApi.readVerification(stored.deployment_id, stored.revision)
    ])
    if (current !== epoch) return
    const original = readStoredJourney()
    if (JSON.stringify(original) !== JSON.stringify(stored) || stored.admin_id !== journeyOwner())
      throw new Error('Original publication target changed')
    assertJourneyDraft(saved, stored)
    const verifiedPublished = saved.state === 'published' && state.state === 'tested' && Boolean(state.validation_evidence)
    if (state.deployment_id !== stored.deployment_id || state.revision !== stored.revision
      || (state.state !== saved.state && !verifiedPublished)) throw new Error('Original revision state mismatch')
    hydrateDraftConfiguration(saved)
    draft.value = saved
    applyVerification(verifiedPublished ? { ...state, state: 'published' } : state)
    publicationConfirmationRequired.value = true
    publicationPreview.value = null
    if (saved.state !== 'published' || (state.state !== 'published' && !verifiedPublished)) {
      notice(`原部署 ${stored.deployment_id} · 修订 ${stored.revision} 当前为${verificationLabel(saved.state)}。原发布结果仍未确认；当前状态不能证明原发布未发生，保留原目标，不再次提交发布。`, 'warning')
      return
    }
    persistJourney(savedJourney(stored, saved))
    journeyStorageError.value = false
    publicationConfirmationRequired.value = false
    notice(`已只读确认原部署 ${stored.deployment_id} · 修订 ${stored.revision} 当前已发布。这是固定修订的当前状态，不是原发布操作回执；目标环境仍需显式绑定。`, 'success')
  } catch (error) {
    if (current === epoch) {
      publicationConfirmationRequired.value = true
      publicationPreview.value = null
      notice(accessFailureNotice(error) || '原目标发布状态尚未确认。未找到、读取失败或目标不符不能证明原发布未发生；保留原目标，仅只读找回，不再次提交发布。', 'warning')
    }
  } finally {
    if (current === epoch) { busy.value = ''; originalPublicationConfirming.value = false }
  }
}

async function publish(): Promise<void> {
  if (!draft.value || busy.value || publicationConfirmationRequired.value || verificationRecoveryPending.value || verificationStorageError.value || journeyLocked.value || verification.value?.state !== 'tested' || !verification.value.validation_evidence) return
  const target = draft.value
  const current = epoch
  const selectedApplication = applicationId.value
  const selectedEnvironment = environmentId.value
  const targetSnapshot = JSON.stringify(target)
  const verificationSnapshot = JSON.stringify(verification.value)
  const originalJourney = JSON.stringify(journey.value)
  const flight = ++connectionFlightSerial
  connectionSubmitting.value = true
  busy.value = 'publish'
  try {
    try {
      await ElMessageBox.confirm(
        `确认发布部署 ${target.deployment_id} · 修订 ${target.revision}？服务商：${target.provider.name}；模型：${target.model.name}（${target.deployment.remote_model}）；验证状态：${verificationLabel(verification.value.state)}；输出上限：${target.deployment.config.max_output_tokens}；推理额度：${target.deployment.config.reasoning_effort || '由模型服务决定'}；方法包输出：${target.deployment.config.skill_output_strategy ? skillStrategyLabels[target.deployment.config.skill_output_strategy] : '标准输出（默认）'}。当前应用 ${selectedApplication || '未选'}、环境 ${selectedEnvironment || '未选'}；发布不会自动绑定环境。`,
        '确认发布模型修订', { type: 'warning', confirmButtonText: '发布此修订', cancelButtonText: '取消' }
      )
    } catch { return }
    if (current !== epoch) return
    if (draft.value !== target || JSON.stringify(draft.value) !== targetSnapshot
      || JSON.stringify(verification.value) !== verificationSnapshot || JSON.stringify(journey.value) !== originalJourney
      || applicationId.value !== selectedApplication || environmentId.value !== selectedEnvironment) {
      notice('确认期间模型修订、配置、验证状态或目标环境已变化，尚未发布。请重新读取并核对原目标。', 'warning')
      return
    }
    try {
      const stored = readStoredJourney()
      if (!stored || stored.phase !== 'saved') throw new Error('Original journey unavailable')
      assertJourneyDraft(target, stored)
      persistJourney({ ...stored, phase: 'publish_unknown' })
    } catch { journeyStorageError.value = true; notice('无法保留原发布目标，本次发布尚未发送。请先只读找回原状态。', 'warning'); return }
    busy.value = 'publish'
    try {
      const result = await onboardingApi.publish(target.deployment_id, target.revision)
      if (current !== epoch || !matchesDraft(target)) return
      if (result.deployment_id !== target.deployment_id || result.state !== 'published') throw new Error('发布结果不一致')
      const saved = await onboardingApi.readDraft(target.deployment_id, target.revision)
      const state = await onboardingApi.readVerification(target.deployment_id, target.revision)
      if (current !== epoch || !matchesDraft(target)) return
      if (saved.deployment_id !== target.deployment_id || saved.revision !== target.revision || saved.state !== 'published'
        || state.deployment_id !== target.deployment_id || state.revision !== target.revision || state.state !== 'published') throw new Error('发布修订未确认')
      hydrateDraftConfiguration(saved)
      draft.value = saved
      applyVerification(state)
      if (!journey.value) throw new Error('Original journey missing')
      persistJourney(savedJourney(journey.value, saved))
      publicationConfirmationRequired.value = false
      publicationPreview.value = null
      notice('模型连接已发布。请选择目标环境并显式绑定聊天能力。', 'success')
    } catch (error) {
      if (current === epoch && matchesDraft(target)) {
        publicationConfirmationRequired.value = true
        publicationPreview.value = null
        published.value = false
        boundEnvironmentId.value = null
        notice(accessFailureNotice(error) || '原发布结果尚未确认。请只读找回原部署与修订；可预览当前修订，但原结果未知时不能切换目标或重新发布。', 'warning')
      }
    } finally {
      if (current === epoch) busy.value = ''
    }
  } finally {
    if (flight === connectionFlightSerial) connectionSubmitting.value = false
    if (current === epoch) busy.value = ''
  }
}

function loadOriginalBindingIntent(): void {
  if (bindingSubmitting.value || bindingReading.value) return
  bindingNotice.value = ''
  try {
    bindingIntent.value = readOnboardingBindingIntent(journeyOwner())
    bindingStorageError.value = false
  } catch {
    bindingStorageError.value = true
  }
}

async function readOriginalBinding(): Promise<void> {
  const target = bindingIntent.value
  if (!target || bindingSubmitting.value || bindingReading.value || busy.value || bindingStorageError.value) return
  const current = epoch
  bindingReading.value = true
  bindingNotice.value = ''
  const stillOriginal = (): boolean => current === epoch && !bindingSubmitting.value
    && bindingIntent.value === target && Number(userStore.info.id) === target.admin_id
  try {
    const stored = readOnboardingBindingIntent(target.admin_id)
    if (!stored || JSON.stringify(stored) !== JSON.stringify(target)) throw new Error('原绑定记录已变化')
    const before = await onboardingApi.readDraft(target.deployment_id)
    if (!stillOriginal()) return
    if (before.deployment_id !== target.deployment_id || before.revision !== target.revision || before.state !== 'published') {
      bindingNotice.value = '原部署当前修订或发布状态不匹配。保留原目标，请到专业配置核对；不会重新绑定。'
      return
    }
    const result = await capabilityApi.readProfile(target.environment_id)
    if (!stillOriginal()) return
    const profile = result.profile
    const routes = profile?.routes
    if (!profile || profile.environment_id !== target.environment_id || profile.state !== 'published'
      || profile.status !== 1 || !Array.isArray(routes)
      || routes.some(item => !Number.isSafeInteger(item.priority) || !Number.isSafeInteger(item.status))) {
      bindingNotice.value = '尚未确认原环境的已发布配置。保留原目标，请稍后读取；当前状态不能证明原绑定未发生。'
      return
    }
    const defaultChat = [...routes].sort((left, right) => left.priority - right.priority)
      .find(item => item.capability_code === 'chat' && item.status === 1)
    if (!defaultChat || defaultChat.route_kind !== 'model_deployment' || defaultChat.deployment_id !== target.deployment_id) {
      bindingNotice.value = '原环境当前默认聊天目标不匹配。保留原目标，请到专业配置核对；不会覆盖当前配置。'
      return
    }
    const after = await onboardingApi.readDraft(target.deployment_id)
    if (!stillOriginal()) return
    if (after.deployment_id !== target.deployment_id || after.revision !== target.revision || after.state !== 'published') {
      bindingNotice.value = '读取期间原部署修订或状态已变化，绑定结果仍未确认。请保留原目标。'
      return
    }
    resolveOnboardingBindingIntent(target)
    if (!stillOriginal()) return
    bindingIntent.value = null
    bindingStorageError.value = false
    boundEnvironmentId.value = target.environment_id
    bindingNotice.value = `已核对环境 ${target.environment_id} 当前默认聊天使用部署 ${target.deployment_id} · 修订 ${target.revision}。这是当前配置状态，不是原操作回执。`
  } catch {
    if (stillOriginal()) bindingNotice.value = '原绑定目标读取或记录确认失败，结果仍未确认。请保留原目标，恢复权限或会话存储后再读取；不会重新发送绑定。'
  } finally {
    if (current === epoch) bindingReading.value = false
  }
}

async function bindDefaultChat(): Promise<void> {
  const selectedEnvironment = environmentId.value
  const selectedDraft = draft.value
  const deploymentId = selectedDraft?.deployment_id
  if (journeyLocked.value || bindingSubmitting.value || !published.value || publicationConfirmationRequired.value
    || !selectedEnvironment || !selectedDraft || !deploymentId || busy.value) return
  const current = epoch
  const selectedApplication = applicationId.value
  const revision = selectedDraft.revision
  const draftSnapshot = JSON.stringify(selectedDraft)
  const verificationSnapshot = JSON.stringify(verification.value)
  if (!positiveId(selectedApplication) || !applications.value.some(item => item.id === selectedApplication)
    || !environments.value.some(item => item.id === selectedEnvironment && item.application_id === selectedApplication)) {
    notice('请先从当前 SandIAM 目录重新选择应用与环境。', 'warning'); return
  }
  const ownsTarget = (): boolean => current === epoch && applicationId.value === selectedApplication
    && environmentId.value === selectedEnvironment && draft.value === selectedDraft
    && JSON.stringify(draft.value) === draftSnapshot && JSON.stringify(verification.value) === verificationSnapshot
  const samePublishedDeployment = (saved: DraftSave): boolean => saved.deployment_id === deploymentId
    && saved.revision === revision && saved.state === 'published'
    && saved.provider.id === selectedDraft.provider.id && saved.model.id === selectedDraft.model.id
    && saved.deployment.remote_model === selectedDraft.deployment.remote_model
    && JSON.stringify(saved.deployment.config) === JSON.stringify(selectedDraft.deployment.config)
  const flight = ++bindingFlightSerial
  bindingSubmitting.value = true
  busy.value = 'bind'
  bindingNotice.value = ''
  let retainedTarget: OnboardingBindingIntent | null = null
  try {
    const saved = await onboardingApi.readDraft(deploymentId)
    if (!ownsTarget()) return
    if (!samePublishedDeployment(saved)) {
      bindingNotice.value = '原部署修订、配置或发布状态已变化，尚未发送绑定。请重新读取并核对原修订。'
      return
    }
    const before = await capabilityApi.readProfile(selectedEnvironment)
    if (!ownsTarget()) return
    const previous = before.profile
    if (previous !== null && (previous.environment_id !== selectedEnvironment || !positiveId(previous.id)
      || !positiveId(previous.revision) || !Array.isArray(previous.routes))) throw new Error('当前能力配置无法确认')
    const bindingSnapshot = JSON.stringify(previous)
    const previousDescription = previous
      ? `当前能力档 ${previous.id} · 修订 ${previous.revision}（${previous.state}）；名称：${previous.name}；模板：${previous.template_code || '未指定'}；材料发送策略：${previous.data_egress_policy}；路线：${previous.routes?.map(route => `${route.capability_code} / ${route.deployment_id ?? route.driver_code ?? '未设置'}`).join('、') || '无'}`
      : '当前环境尚无能力档'
    try {
      await ElMessageBox.confirm(
        `确认将应用 ${selectedApplication}、环境 ${selectedEnvironment} 的默认聊天绑定到部署 ${deploymentId} · 修订 ${revision}？模型：${selectedDraft.model.name}（${selectedDraft.deployment.remote_model}）；验证状态：${verificationLabel(verification.value?.state ?? 'draft')}；输出上限：${selectedDraft.deployment.config.max_output_tokens}；推理额度：${selectedDraft.deployment.config.reasoning_effort || '由模型服务决定'}；方法包输出：${selectedDraft.deployment.config.skill_output_strategy ? skillStrategyLabels[selectedDraft.deployment.config.skill_output_strategy] : '标准输出（默认）'}。${previousDescription}。本操作采用首次开通默认聊天配置、允许已批准云端处理；不会覆盖不同的已发布能力档。`,
        '确认默认聊天绑定', { type: 'warning', confirmButtonText: '绑定此修订和环境', cancelButtonText: '保留原目标' }
      )
    } catch { return }
    if (!ownsTarget()) return
    const [head, latest] = await Promise.all([
      onboardingApi.readDraft(deploymentId), capabilityApi.readProfile(selectedEnvironment)
    ])
    if (!ownsTarget()) return
    if (!samePublishedDeployment(head) || JSON.stringify(latest.profile) !== bindingSnapshot) {
      bindingNotice.value = '确认期间部署修订或当前环境能力配置已变化，尚未发送绑定。请重新核对当前配置。'
      return
    }
    const target: OnboardingBindingIntent = {
      version: 1, admin_id: journeyOwner(), application_id: selectedApplication,
      environment_id: selectedEnvironment, deployment_id: deploymentId, revision
    }
    try {
      retainOnboardingBindingIntent(target)
      bindingIntent.value = target
      bindingStorageError.value = false
    } catch {
      bindingStorageError.value = true
      notice('原绑定目标无法保存，尚未发送绑定。请恢复会话存储后重读原记录。', 'warning')
      return
    }
    retainedTarget = bindingIntent.value
    boundEnvironmentId.value = null
    const result = await onboardingApi.bindDefaultChat(selectedEnvironment, deploymentId, revision)
    if (!ownsTarget()) return
    if (!positiveId(result.profile.id) || result.profile.environment_id !== selectedEnvironment || result.profile.state !== 'published') {
      throw new Error('绑定结果与原目标不一致')
    }
    bindingNotice.value = '绑定请求已返回，正在只读核对原目标当前配置。'
  } catch (error) {
    if (ownsTarget()) bindingNotice.value = retainedTarget
      ? '绑定结果尚未确认，服务器可能已完成原绑定。请读取原目标；不会重新绑定或覆盖配置。'
      : accessFailureNotice(error) || '无法读取当前部署或环境能力配置，尚未发送绑定。请恢复读取条件后重新核对。'
  } finally {
    if (flight === bindingFlightSerial) {
      bindingSubmitting.value = false
      if (live && current !== epoch && verificationNamespace()) loadOriginalBindingIntent()
    }
    if (current === epoch) busy.value = ''
  }
  if (current === epoch && retainedTarget && bindingIntent.value === retainedTarget) await readOriginalBinding()
}

watch([() => userStore.info.id, () => userStore.accessToken], () => {
  epoch++
  inspectionGeneration++
  discoveryGeneration++
  clearKey()
  processingLocation.value = ''
  connectionNotice.value = ''
  message.value = ''
  navigationNotice.value = ''
  providerName.value = ''
  baseUrl.value = ''
  remoteModel.value = ''
  modelName.value = ''
  models.value = []
  inspectedRevision.value = null
  inspectionDeployments.value = []
  inspectionSearchName.value = ''
  journey.value = null
  journeyStorageError.value = false
  journeyRecoveryPending.value = false
  originalPublicationConfirming.value = false
  bindingIntent.value = null
  bindingStorageError.value = false
  bindingReading.value = false
  bindingNotice.value = ''
  draft.value = null
  saveAttempt.value = null
  verification.value = null
  verificationRequest.value = null
  verificationRecoveryPending.value = false
  verificationStorageError.value = false
  verificationAttempted.value = false
  verificationRejected.value = false
  published.value = false
  publicationConfirmationRequired.value = false
  publicationPreview.value = null
  boundEnvironmentId.value = null
  busy.value = ''
  // Identity hydration or account changes invalidate the old guide and IAM reads.
  // Start a fresh read without disturbing recovery for this administrator.
  applications.value = []
  environments.value = []
  applicationId.value = ''
  environmentId.value = ''
  environmentGeneration++
  if (verificationNamespace()) { loadOriginalBindingIntent(); void restoreJourney(); void load() }
}, { flush: 'sync' })

onActivated(() => {
  if (configurationEntryLoaded && route.query.configuration_return === 'material_guide' && !busy.value && !bindingSubmitting.value)
    void loadApplications()
})
onMounted(() => { loadOriginalBindingIntent(); void restoreJourney(); void load(); void loadCatalog(); void loadSkillCatalog(); void loadAudioCatalog(); void loadAudioRuntime(); readInspectionRoute() })
onBeforeUnmount(() => { live = false; inspectionGeneration++; epoch++; guideGeneration++; catalogGeneration++; skillCatalogGeneration++; audioCatalogGeneration++; audioRuntimeGeneration++; clearKey() })
</script>

<style scoped>
.heading { display: flex; justify-content: space-between; gap: 20px; align-items: flex-start; }
.heading h2 { margin: 0 0 8px; }
.heading p, .muted { color: var(--el-text-color-secondary); }
.notice, .steps { margin: 20px 0; }
.catalog-notice { margin: 12px 0; }
.section { border-top: 1px solid var(--el-border-color-light); padding: 20px 0; }
.section h3 { margin: 0 0 12px; }
.narrow { max-width: 650px; }
.provider-select { width: 100%; }
.narrow :deep(.el-select) { width: 100%; }
</style>
