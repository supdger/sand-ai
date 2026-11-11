<template>
  <SandAiErrorBoundary>
    <div class="sand-ai-page">
      <ElCard v-if="materialConfigurationPage" class="art-table-card sand-ai-page-card" shadow="never">
        <div class="cap-header">
          <div>
            <h2 class="cap-title">材料配置</h2>
            <p class="cap-desc">
              为指定应用环境选择解析工具、模型和材料处理位置。仅配置本地文本解析时无需密钥；AI 抽取仍需已启用模型。
            </p>
          </div>
        
        </div>

        <ElAlert v-if="navigationNotice" :title="navigationNotice" type="warning" :closable="false" class="cap-alert" />
        <ElButton v-if="route.query.configuration_origin === 'material_guide'"
          :disabled="Boolean(busy) || writeInFlight || restoringEntry" @click="returnToMaterialGuide">返回原材料整理引导</ElButton>
        <ElForm label-width="120px" class="cap-form" @submit.prevent>
          <ElFormItem label="应用">
            <ElSelect
              v-model="applicationId"
              filterable
              clearable
              placeholder="选择应用"
              style="width: 360px"
              :loading="loadingApps"
              :disabled="busy === 'save' || busy === 'publish' || Boolean(pending) || writeInFlight || journalError"
              @change="onApplicationChange"
            >
              <ElOption
                v-for="item in applications"
                :key="item.id"
                :label="item.name"
                :value="item.id"
              />
              <template #empty>
                <div class="cap-app-empty">
                  <span>暂无应用——请先到 SandIAM 登记应用</span>
                  <ElButton link type="primary" @click="router.push('/sand-iam/application')">去登记</ElButton>
                </div>
              </template>
            </ElSelect>
          </ElFormItem>
          <ElFormItem label="环境">
            <ElSelect
              v-model="environmentId"
              filterable
              clearable
              placeholder="选择环境"
              style="width: 360px"
              :loading="loadingEnvs"
              :disabled="!applicationId || busy === 'save' || busy === 'publish' || Boolean(pending) || writeInFlight || journalError"
              @change="onEnvironmentChange"
            >
              <ElOption
                v-for="item in environments"
                :key="item.id"
                :label="item.name"
                :value="item.id"
              />
            </ElSelect>
          </ElFormItem>
        </ElForm>

        <ElAlert
          v-if="errorMessage"
          type="error"
          :closable="true"
          :title="errorMessage"
          class="cap-alert"
          @close="errorMessage = ''"
        />
        <ElButton v-if="iamListError === 'applications'" :loading="loadingApps" @click="loadApps">重新读取应用</ElButton>
        <ElButton v-if="iamListError === 'environments'" :loading="loadingEnvs" @click="onApplicationChange">重新读取环境</ElButton>

        <details v-if="pending || journalError" class="cap-alert cap-recover">
          <summary>原{{ pending?.action === 'publish' ? '启用' : '保存' }}结果待确认（查看详情）</summary>
          <ElAlert v-if="pending" type="warning" :closable="false"
            :title="`原${pending.action === 'save' ? '保存' : '启用'}结果待确认：应用 ${pending.application_id}／环境 ${pending.environment_id}。仅重新读取原目标，不会重发；当前配置匹配也不代表取得原请求回执。`" />
          <ElButton :disabled="Boolean(busy) || writeInFlight" @click="restoreIntent(); loadEntryContext()">读取原操作状态</ElButton>
          <ElButton :disabled="Boolean(busy) || writeInFlight" @click="abandonPendingWrite">放弃此次变更</ElButton>
        </details>
        <MaterialProfileEditor v-if="materialConfigurationPage" :environment-id="environmentId" />
        <template v-if="environmentId">
          <ElAlert v-if="revisionConflict" type="warning" :closable="false"
            title="配置已被其他管理员修改。本地表单仍在下方。重新加载以查看最新版本；核对后可选择采用最新配置，再重新填写自己的改动。" />
          <ElButton v-if="revisionConflict && profileLoaded" :disabled="Boolean(busy)" @click="adoptLatestProfile">
            采用最新配置并放弃本地修改
          </ElButton>
          <p v-if="revisionConflict && profileLoaded" class="cap-muted">
            服务端修订：{{ profile?.revision ?? '尚无配置' }}；
            名称：{{ profile?.name ?? '尚无配置' }}；
            默认模型：{{ profile?.routes?.map(route => `${capabilityLabel(route.capability_code)} / ${routeKindLabel(route.route_kind)} / ${route.deployment_id !== null && route.deployment_id !== undefined ? `部署 #${route.deployment_id}` : route.driver_code ? driverLabel(route.driver_code) : '未设置'}`).join('、') || '无' }}。
          </p>
          <ElAlert v-if="unsafeExistingConfig" type="warning" :closable="false"
            title="既有默认模型未提供可安全保留的空配置，或含已停用项。当前配置只读，请联系管理员核查读取接口或历史配置；此页不会覆盖保存。" />
          <ElForm :disabled="editBlocked" label-width="120px" class="cap-form" @submit.prevent>
            <ElFormItem label="配置名称">
              <ElInput v-model="form.name" style="width: 360px" placeholder="如 日常默认" />
            </ElFormItem>
            <ElFormItem label="模板">
              <ElSelect
                v-model="form.template_code"
                clearable
                placeholder="可选模板起点"
                style="width: 360px"
                @change="applyTemplate"
              >
                <ElOption
                  v-for="(tpl, code) in metadata?.templates ?? {}"
                  :key="code"
                  :label="tpl.name"
                  :value="code"
                />
              </ElSelect>
            </ElFormItem>
            <ElFormItem label="材料发送位置">
              <ElSelect v-model="form.data_egress_policy" style="width: 360px">
                <ElOption label="仅本地：材料留在本机" value="local_only" />
                <ElOption label="仅私有网络：用于内部服务" value="private_only" />
                <ElOption label="已审核公共云：允许发送至已审核服务商" value="approved_cloud" />
              </ElSelect>
            </ElFormItem>
            <ElFormItem v-if="profile" label="当前版本">
              <ElSpace wrap>
                <ElTag>第 {{ profile.revision }} 版</ElTag>
                <ElTag :type="profile.state === 'published' ? 'success' : 'warning'">
                  {{ configurationStateLabel(profile.state) }}
                </ElTag>
              </ElSpace>
            </ElFormItem>
          </ElForm>

          <div class="cap-routes-toolbar">
            <h3 class="cap-section-title">能力与处理工具</h3>
            <ElSpace wrap class="cap-route-actions">
              <ElButton size="small" :disabled="editBlocked || Boolean(busy)" @click="prepareLocalTextRoute">添加文档读取</ElButton>
              <ElButton size="small" :disabled="editBlocked || Boolean(busy)" @click="prepareMaterialRoutes">补齐材料整理默认模型</ElButton>
              <ElButton size="small" :disabled="editBlocked || Boolean(busy)" @click="addRoute">添加默认模型</ElButton>
            </ElSpace>
          </div>

          <p class="cap-muted">首次只需文档读取时，点击“添加文档读取”，保存并启用即可。该工具支持 TXT、文本型 PDF、DOCX 等，不调用模型；扫描件 OCR 和材料 AI 抽取需另行配置。</p>
          <ElTable :data="form.routes" border empty-text="暂无默认模型">
            <ElTableColumn label="能力" min-width="140">
              <template #default="{ row }">
                <ElSelect
                  v-model="row.capability_code"
                  :disabled="editBlocked"
                  filterable
                  @change="() => syncRouteKind(row)"
                >
                  <ElOption
                    v-for="code in capabilityCodes"
                    :key="code"
                    :label="capabilityLabel(code)"
                    :value="code"
                  />
                </ElSelect>
              </template>
            </ElTableColumn>
            <ElTableColumn label="处理方式" width="130">
              <template #default="{ row }">{{ routeKindLabel(row.route_kind) }}</template>
            </ElTableColumn>
            <ElTableColumn label="模型部署" min-width="220">
              <template #default="{ row }">
                <ElSelect v-if="usesDeployment(row)" v-model="row.deployment_id"
                  :disabled="editBlocked" filterable remote clearable :remote-method="(query: string) => searchDeployments(query, row)"
                  :loading="deploymentsLoading" placeholder="选择已启用部署" class="cap-deployment"
                  @visible-change="(visible: boolean) => visible && searchDeployments('', row)">
                  <ElOption v-for="item in deploymentOptions" :key="item.id"
                    :label="item.display_name" :value="item.id"
                    :disabled="!item.compatible_data_egress_policies.includes(form.data_egress_policy)" />
                  <ElOption v-if="row.deployment_id && !deploymentOptions.some(item => item.id === row.deployment_id)"
                    :label="`现有部署 #${row.deployment_id}（请核对）`" :value="row.deployment_id" />
                </ElSelect>
                <span v-else class="cap-muted">—</span>
                <p v-if="isLocalOcr(row) && !row.deployment_id" class="cap-muted">未绑定部署时费用保持未计价，硬预算请求会被拒绝。可先保存，配置并启用部署价格后再绑定。</p>
              </template>
            </ElTableColumn>
            <ElTableColumn label="解析工具" min-width="160">
              <template #default="{ row }">
                <ElSelect
                  v-if="row.route_kind === 'parse_driver' || row.route_kind === 'audio_driver'"
                  v-model="row.driver_code"
                  @change="!usesDeployment(row) && (row.deployment_id = undefined)"
                  :disabled="editBlocked"
                  clearable
                  filterable
                >
                  <ElOption
                    v-for="code in driverCodes(row.capability_code)"
                    :key="code"
                    :label="driverLabel(code)"
                    :value="code"
                  />
                </ElSelect>
                <span v-else class="cap-muted">—</span>
              </template>
            </ElTableColumn>
            <ElTableColumn label="优先顺序" width="90">
              <template #default="{ row }">
                <ElInputNumber :disabled="editBlocked" v-model="row.priority" :min="1" :controls="false" class="cap-number" />
              </template>
            </ElTableColumn>
            <ElTableColumn label="操作" width="80">
              <template #default="{ $index }">
                <ElButton :disabled="editBlocked" link type="danger" @click="removeRoute($index)">删除</ElButton>
              </template>
            </ElTableColumn>
          </ElTable>

          <ElSpace wrap class="cap-actions">
            <ElButton
              v-permission="'sand_ai:setup:capability:save'"
              type="primary"
              :loading="busy === 'save'"
              :disabled="editBlocked || Boolean(busy)"
              @click="handleSave"
            >
              保存草稿
            </ElButton>
            <ElButton
              v-permission="'sand_ai:setup:capability:publish'"
              :disabled="editBlocked || Boolean(busy) || !profile || profile.state === 'published'"
              :loading="busy === 'publish'"
              @click="handlePublish"
            >
              启用配置档
            </ElButton>
            <ElButton :disabled="Boolean(busy)" :loading="busy === 'load'" @click="reloadProfile">重新加载</ElButton>
          </ElSpace>
        </template>
      </ElCard>
      <ElCard v-else class="art-table-card sand-ai-page-card" shadow="never">
        <div class="cap-header">
          <div>
            <h2 class="cap-title">能力配置</h2>
            <p class="cap-desc">给每项 AI 能力选好默认和备用模型</p>
            <p class="cap-hint">这是高级功能，开通时系统已经替你选好，一般不用改</p>
          </div>
        </div>

        <ElAlert v-if="navigationNotice" :title="navigationNotice" type="warning" :closable="false" class="cap-alert" />
        <ElButton v-if="route.query.configuration_origin === 'material_guide'"
          :disabled="Boolean(busy) || writeInFlight || restoringEntry" @click="returnToMaterialGuide">返回原材料整理引导</ElButton>

        <ElForm v-if="showApplicationPicker || showEnvironmentPicker" label-width="72px" class="cap-form" @submit.prevent>
          <ElFormItem v-if="showApplicationPicker" label="应用">
            <ElSelect
              v-model="applicationId"
              filterable
              clearable
              placeholder="选择应用"
              class="cap-target-select"
              :loading="loadingApps"
              :disabled="writeInFlight || journalError"
              @change="onApplicationChange"
            >
              <ElOption
                v-for="item in applications"
                :key="item.id"
                :label="item.name"
                :value="item.id"
              />
              <template #empty>
                <div class="cap-app-empty">
                  <span>暂无应用——请先到 SandIAM 登记应用</span>
                  <ElButton link type="primary" @click="router.push('/sand-iam/application')">去登记</ElButton>
                </div>
              </template>
            </ElSelect>
          </ElFormItem>
          <ElFormItem v-if="showEnvironmentPicker" label="环境">
            <ElSelect
              v-model="environmentId"
              filterable
              clearable
              placeholder="选择环境"
              class="cap-target-select"
              :loading="loadingEnvs"
              :disabled="!applicationId || writeInFlight || journalError"
              @change="onEnvironmentChange"
            >
              <ElOption
                v-for="item in environments"
                :key="item.id"
                :label="item.name"
                :value="item.id"
              />
            </ElSelect>
          </ElFormItem>
        </ElForm>

        <ElAlert
          v-if="errorMessage"
          type="error"
          :closable="true"
          :title="errorMessage"
          class="cap-alert"
          @close="errorMessage = ''"
        />
        <ElButton v-if="iamListError === 'applications'" :loading="loadingApps" @click="loadApps">重新读取应用</ElButton>
        <ElButton v-if="iamListError === 'environments'" :loading="loadingEnvs" @click="onApplicationChange">重新读取环境</ElButton>

        <details v-if="pending || journalError" class="cap-alert cap-recover">
          <summary>原{{ pending?.action === 'publish' ? '启用' : '保存' }}结果待确认（查看详情）</summary>
          <ElAlert v-if="pending" type="warning" :closable="false"
            title="原保存/启用结果待确认。仅重新读取原目标，不会重发；当前配置匹配也不代表取得原请求回执。" />
          <ElButton :disabled="Boolean(busy) || writeInFlight" @click="restoreIntent(); loadEntryContext()">读取原操作状态</ElButton>
          <ElButton :disabled="Boolean(busy) || writeInFlight" @click="abandonPendingWrite">放弃此次变更</ElButton>
        </details>
        <ElAlert v-if="revisionConflict" type="warning" :closable="false"
          title="配置已被其他管理员修改。本地选择仍保留在下方。重新加载以查看最新配置；核对后可选择采用最新配置，再重新选择自己的改动。" />
        <ElButton v-if="revisionConflict && profileLoaded" :disabled="Boolean(busy) || writeInFlight" @click="adoptLatestProfile">
          采用最新配置并放弃本地修改
        </ElButton>
        <ElAlert v-if="unsafeExistingConfig" type="warning" :closable="false"
          title="既有默认模型未提供可安全保留的空配置，或含已停用项。当前配置只读，请联系管理员核查读取接口或历史配置；此页不会覆盖保存。" />

        <ElEmpty
          v-if="capabilityNeedsPick"
          :description="capabilityPickDescription"
          :image-size="72"
        />
        <template v-else-if="capabilityLoading">
          <ElSkeleton v-for="index in 3" :key="index" :rows="4" animated class="cap-card-skeleton" />
        </template>
        <ElResult v-else-if="capabilityLoadFailed" icon="error" title="能力配置没能加载出来">
          <template #extra>
            <ElSpace wrap>
              <ElButton type="primary" :loading="busy === 'load'" @click="retryCapabilityLoad">重新加载</ElButton>
              <ElButton @click="copyCapabilityProblem">复制问题信息</ElButton>
            </ElSpace>
          </template>
        </ElResult>
        <ElEmpty v-else-if="applications.length === 0 && iamListError !== 'applications'" description="还没有可管理的应用">
          <ElButton @click="router.push('/sand-iam/application')">去登记应用</ElButton>
        </ElEmpty>
        <ElEmpty
          v-else-if="capabilityNoEnvironment"
          description="这个应用还没有可用环境"
        />
        <div v-else class="cap-cards">
          <section v-for="card in capabilityCards" :key="card.code" class="cap-card">
            <div class="cap-card__head">
              <div class="cap-card__title">
                <h3 class="cap-card__name">{{ card.name }}</h3>
                <span class="cap-card__usage">用在：{{ card.usage }}</span>
              </div>
              <ElTag :type="cardTag(card).type" size="small">{{ cardTag(card).text }}</ElTag>
            </div>

            <template v-if="!card.disabled">
            <ElForm
              label-width="88px"
              class="cap-card__form"
              :disabled="cardFormDisabled(card)"
              @submit.prevent
            >
              <template v-if="card.kind === 'model'">
                <ElFormItem label="默认模型" :error="card.fieldError || offlineModelText(card, card.defaultDeploymentId)">
                  <ElSelect
                    v-model="card.defaultDeploymentId"
                    :key="cardDeploymentsKey(card, 'default')"
                    clearable
                    filterable
                    remote
                    :remote-method="(query: string) => searchCardDeployments(card, query)"
                    :loading="cardDeploymentsLoading(card)"
                    :placeholder="modelPlaceholder(card, 'default')"
                    class="cap-card__select"
                    @visible-change="(visible: boolean) => visible && ensureCardDeployments(card)"
                  >
                    <ElOption
                      v-for="item in cardDeploymentOptions(card, undefined, card.defaultDeploymentId)"
                      :key="item.id"
                      :label="item.display_name"
                      :value="item.id"
                    />
                  </ElSelect>
                </ElFormItem>
                <ElFormItem label="备用模型" :error="offlineModelText(card, card.backupDeploymentId)">
                  <ElSelect
                    v-model="card.backupDeploymentId"
                    :key="cardDeploymentsKey(card, 'backup')"
                    clearable
                    filterable
                    remote
                    :remote-method="(query: string) => searchCardDeployments(card, query)"
                    :loading="cardDeploymentsLoading(card)"
                    :placeholder="modelPlaceholder(card, 'backup')"
                    class="cap-card__select"
                    @visible-change="(visible: boolean) => visible && ensureCardDeployments(card)"
                  >
                    <ElOption
                      v-for="item in cardDeploymentOptions(card, undefined, card.backupDeploymentId)"
                      :key="item.id"
                      :label="item.display_name"
                      :value="item.id"
                    />
                  </ElSelect>
                </ElFormItem>
              </template>
              <template v-else-if="card.kind === 'audio'">
                <ElFormItem label="默认引擎">
                  <ElSelect v-model="card.defaultDriverCode" clearable filterable placeholder="选择引擎" class="cap-card__select">
                    <ElOption
                      v-for="code in card.drivers"
                      :key="code"
                      :label="driverOptionLabel(code)"
                      :value="code"
                    />
                  </ElSelect>
                </ElFormItem>
                <ElFormItem label="默认模型" :error="offlineModelText(card, card.defaultDeploymentId)">
                  <ElSelect
                    v-model="card.defaultDeploymentId"
                    :key="cardDeploymentsKey(card, 'default')"
                    clearable
                    filterable
                    remote
                    :remote-method="(query: string) => searchCardDeployments(card, query)"
                    :loading="cardDeploymentsLoading(card)"
                    :placeholder="modelPlaceholder(card, 'default')"
                    class="cap-card__select"
                    @visible-change="(visible: boolean) => visible && ensureCardDeployments(card)"
                  >
                    <ElOption
                      v-for="item in cardDeploymentOptions(card, card.defaultDriverCode, card.defaultDeploymentId)"
                      :key="item.id"
                      :label="item.display_name"
                      :value="item.id"
                    />
                  </ElSelect>
                </ElFormItem>
                <ElFormItem label="备用引擎">
                  <ElSelect v-model="card.backupDriverCode" clearable filterable placeholder="可不选" class="cap-card__select">
                    <ElOption
                      v-for="code in card.drivers"
                      :key="code"
                      :label="driverOptionLabel(code)"
                      :value="code"
                    />
                  </ElSelect>
                </ElFormItem>
                <ElFormItem label="备用模型" :error="offlineModelText(card, card.backupDeploymentId)">
                  <ElSelect
                    v-model="card.backupDeploymentId"
                    :key="cardDeploymentsKey(card, 'backup')"
                    clearable
                    filterable
                    remote
                    :remote-method="(query: string) => searchCardDeployments(card, query)"
                    :loading="cardDeploymentsLoading(card)"
                    :placeholder="modelPlaceholder(card, 'backup')"
                    class="cap-card__select"
                    @visible-change="(visible: boolean) => visible && ensureCardDeployments(card)"
                  >
                    <ElOption
                      v-for="item in cardDeploymentOptions(card, card.backupDriverCode, card.backupDeploymentId)"
                      :key="item.id"
                      :label="item.display_name"
                      :value="item.id"
                    />
                  </ElSelect>
                </ElFormItem>
              </template>
              <template v-else>
                <ElFormItem label="解析方式">
                  <ElSelect v-model="card.parseDriverCode" clearable filterable placeholder="选择解析方式" class="cap-card__select">
                    <ElOption
                      v-for="code in card.drivers"
                      :key="code"
                      :label="driverOptionLabel(code)"
                      :value="code"
                    />
                  </ElSelect>
                </ElFormItem>
                <p v-if="card.code === 'image_ocr'" class="cap-card__note">
                  扫描件识别需要另外绑定识别部署；未绑定时只识别图片里的文字。
                </p>
              </template>
            </ElForm>

            <p v-if="cardShowProviderLink(card)" class="cap-card__note">
              还没有支持这项能力的模型，
              <ElButton link type="primary" @click="router.push('/sand-ai/provider')">去 AI 服务商添加</ElButton>
            </p>
            </template>
            <p v-else-if="card.snapshot === '[]' && !card.wasBound" class="cap-card__note">还没有配置，选好模型保存后即可使用。</p>
            <p v-else class="cap-card__note">已停用，{{ card.usage }}暂不可用；点「重新启用」可恢复。</p>

            <div class="cap-card__foot">
              <span class="cap-card__foot-gap" />
              <ElButton
                v-if="card.disabled"
                link
                type="primary"
                :disabled="cardWriteBlocked()"
                @click="enableCapabilityCard(card)"
              >
                {{ card.snapshot === '[]' && !card.wasBound ? '启用' : '重新启用' }}
              </ElButton>
              <ElButton
                v-else
                link
                type="danger"
                :disabled="cardWriteBlocked()"
                @click="askDisableCapability(card)"
              >
                停用这项能力
              </ElButton>
            </div>
          </section>
          <ElEmpty
            v-if="capabilityCards.length === 0"
            description="还没有可配置的能力"
            :image-size="72"
          />
        </div>
        <div v-if="hasUnsavedCards" class="cap-cards-foot">
          <span class="cap-muted">{{ unsavedCardsHint }}</span>
          <ElButton
            type="primary"
            :loading="busy === 'save'"
            :disabled="cardWriteBlocked()"
            @click="saveCapabilityCards"
          >
            保存
          </ElButton>
        </div>
      </ElCard>
    </div>
  </SandAiErrorBoundary>
</template>

<script setup lang="ts">
  import SandAiErrorBoundary from '../components/SandAiErrorBoundary.vue'
  import '../components/sandAiPage.css'
  import { computed, onActivated, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
  import { useUserStore } from '@/store/modules/user'
  import { useAuth } from '@/hooks/core/useAuth'
  import { readCapabilityProfileIntent, retainCapabilityProfileIntent, resolveCapabilityProfileIntent, dropCapabilityProfileIntent, type CapabilityProfileIntent } from '../api/capabilityProfileIntent'
  import { errorDisplayText } from '../api/errorText'
  import { isWriteRejected, writeRejectionText } from '../api/writeRejection'
  import { confirmAbandonPendingWrite } from '../composables/pendingWrite'
  import { HttpError } from '@/utils/http/error'
  import { ElMessage, ElMessageBox } from 'element-plus'
  import { useRoute, useRouter } from 'vue-router'
  import iamApi, {
    type SandIamApplicationReference,
    type SandIamEnvironmentReference
  } from '../api/iam'
  import capabilityApi, {
    type CapabilitySavePayload,
    type CapabilityTargetParams,
    type SandAiCapabilityMetadata,
    type SandAiCapabilityProfile,
    type SandAiCapabilityRoute,
    type CapabilityTarget,
    type SandAiDataEgressPolicy
  } from '../api/capability'
  import { capabilityLabel, configurationStateLabel, driverLabel } from '../api/displayLabels'
  import onboardingApi, { type PublishedDeployment } from '../api/onboarding'
  import { describeBoundaryFault, problemInfoText } from '../composables/errorBoundary'
  import MaterialProfileEditor from '../components/MaterialProfileEditor.vue'

  type RouteDraft = {
    capability_code: string
    route_kind: SandAiCapabilityRoute['route_kind']
    deployment_id: number | undefined
    driver_code: string | null
    priority: number
  }

  const route = useRoute()
  const router = useRouter()
  const { hasAuth } = useAuth()
  let restoringEntry = true
  let entryInitialized = false
  const navigationNotice = ref('')
  const materialConfigurationPage = computed(() => route.path.endsWith('/material-config'))
  const loadingApps = ref(false)
  const loadingEnvs = ref(false)
  const iamListError = ref<'applications' | 'environments' | ''>('')
  const busy = ref<'load' | 'save' | 'publish' | ''>('')
  const errorMessage = ref('')
  const applications = ref<SandIamApplicationReference[]>([])
  const environments = ref<SandIamEnvironmentReference[]>([])
  const metadata = ref<SandAiCapabilityMetadata | null>(null)
  const deploymentOptions = ref<PublishedDeployment[]>([])
  const deploymentsLoading = ref(false)
  let deploymentGeneration = 0
  const profile = ref<SandAiCapabilityProfile | null>(null)
  const profileLoaded = ref(false)
  const revisionConflict = ref(false)
  let profileGeneration = 0
  let applicationGeneration = 0
  let environmentGeneration = 0
  const userStore = useUserStore()
  const adminId = computed(() => {
    const id = Number(userStore.info.id)
    return userStore.accessToken && Number.isSafeInteger(id) && id > 0 ? id : 0
  })
  let identityEpoch = 0
  const writeInFlight = ref(false)
  let writeFlightSerial = 0
  const journalError = ref(false)
  const pending = ref<CapabilityProfileIntent | null>(null)
  function restoreIntent(): void {
    pending.value = null
    journalError.value = false
    try { pending.value = readCapabilityProfileIntent(adminId.value) }
    catch (error) { journalError.value = true; errorMessage.value = errorDisplayText(error, '无法读取原操作记录，写操作已锁定') }
  }
  function persistIntent(operation: CapabilityProfileIntent, previous: CapabilityProfileIntent | null = null): void {
    retainCapabilityProfileIntent(operation, previous)
    // Use the normalized persisted value for exact compare-and-remove later.
    pending.value = readCapabilityProfileIntent(operation.admin_id)
    if (!pending.value) throw new Error('原操作记录未保存')
  }
  function clearIntent(): void {
    if (!pending.value) return
    resolveCapabilityProfileIntent(pending.value)
    pending.value = null
  }
  const unsafeExistingConfig = computed(() => Boolean(profile.value && (!Array.isArray(profile.value.routes)
    || profile.value.routes.some(route => route.status !== 1 || typeof route.config !== 'object'
      || route.config === null || Array.isArray(route.config) || Object.keys(route.config).length !== 0))))
  const editBlocked = computed(() => revisionConflict.value || writeInFlight.value || journalError.value || Boolean(pending.value) || !profileLoaded.value || unsafeExistingConfig.value
    || Boolean(profile.value && profile.value.environment_id !== environmentId.value))
  const applicationId = ref<number | undefined>(undefined)
  const environmentId = ref<number | undefined>(undefined)
  // 后端解析出的实际目标；自动定向（唯一应用/环境）时由读取响应回填。
  const resolvedTarget = ref<CapabilityTarget | null>(null)

  const showApplicationPicker = computed(() => !materialConfigurationPage.value && applications.value.length > 1)
  const showEnvironmentPicker = computed(() => !materialConfigurationPage.value && environments.value.length > 1)
  const capabilityPickRequired = computed(() => showApplicationPicker.value || showEnvironmentPicker.value)
  // 已选应用且环境列表读取完毕但为空时，展示空环境态而不是发起必然失败的读取。
  const capabilityNoEnvironment = computed(() =>
    !materialConfigurationPage.value && Boolean(applicationId.value) && !loadingEnvs.value
    && environments.value.length === 0 && iamListError.value !== 'environments')
  const targetSelectionReady = computed(() =>
    (!showApplicationPicker.value || Boolean(applicationId.value))
    && (!showEnvironmentPicker.value || Boolean(environmentId.value))
    && !capabilityNoEnvironment.value)
  const capabilityNeedsPick = computed(() =>
    !materialConfigurationPage.value && capabilityPickRequired.value && !targetSelectionReady.value
    && !capabilityNoEnvironment.value)
  const capabilityPickDescription = computed(() => {
    if (showApplicationPicker.value && !applicationId.value) return '请先选择应用'
    if (showEnvironmentPicker.value && !environmentId.value) return '请先选择环境'
    return '请先选择应用和环境'
  })

  const form = reactive({
    name: 'default-capability',
    template_code: '' as string,
    data_egress_policy: 'local_only' as SandAiDataEgressPolicy | string,
    routes: [] as RouteDraft[]
  })

  const capabilityCodes = computed(() => Object.keys(metadata.value?.capabilities ?? {}))

  function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null
  }

  function isProfileRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value)
  }
  function positiveId(value: unknown): value is number {
    return typeof value === 'number' && Number.isSafeInteger(value) && value > 0
  }
  function isProfileRoute(value: unknown): value is SandAiCapabilityRoute {
    return isProfileRecord(value) && typeof value.capability_code === 'string' && value.capability_code.length > 0
      && ['model_deployment', 'parse_driver', 'audio_driver'].includes(String(value.route_kind))
      && positiveId(value.priority) && (value.status === 1 || value.status === 2)
      && (value.deployment_id === undefined || value.deployment_id === null || positiveId(value.deployment_id))
      && (value.driver_code === undefined || value.driver_code === null || typeof value.driver_code === 'string')
      && (value.config === undefined || isProfileRecord(value.config))
  }
  function isProfile(value: unknown, environmentId?: number): value is SandAiCapabilityProfile {
    return isProfileRecord(value) && positiveId(value.id)
      && (environmentId === undefined || value.environment_id === environmentId)
      && positiveId(value.environment_id)
      && positiveId(value.revision) && typeof value.name === 'string' && value.name.length > 0
      && (value.template_code === null || typeof value.template_code === 'string')
      && typeof value.data_egress_policy === 'string'
      && ['local_only', 'private_only', 'approved_cloud'].includes(value.data_egress_policy)
      && typeof value.state === 'string' && ['draft', 'published', 'archived'].includes(value.state)
      && (value.status === 1 || value.status === 2) && Array.isArray(value.routes) && value.routes.every(isProfileRoute)
  }
  function readProfileResponse(response: unknown, environmentId?: number): SandAiCapabilityProfile | null {
    if (!isProfileRecord(response) || !Object.prototype.hasOwnProperty.call(response, 'profile')) throw new Error('Incomplete capability response')
    if (response.profile === null) return null
    if (!isProfile(response.profile, environmentId)) throw new Error('Invalid capability profile')
    return response.profile
  }
  function readCapabilityTarget(response: unknown): CapabilityTarget | null {
    if (!isProfileRecord(response) || response.target === undefined || response.target === null) return null
    const target = response.target
    if (!isProfileRecord(target) || !positiveId(target.environment_id)) return null
    if (target.application_id !== null && target.application_id !== undefined && !positiveId(target.application_id)) return null
    return { application_id: positiveId(target.application_id) ? target.application_id : null, environment_id: target.environment_id }
  }
  function writtenProfileResponse(response: unknown, environmentId?: number): SandAiCapabilityProfile {
    const value = readProfileResponse(response, environmentId)
    if (value === null) throw new Error('Missing written capability profile')
    return value
  }
  function sameConfiguration(value: SandAiCapabilityProfile, payload: CapabilitySavePayload): boolean {
    if (!((value.state === 'draft' && value.status === 2) || (value.state === 'published' && value.status === 1))) return false
    if (!value.routes || value.routes.some(route => !isProfileRecord(route.config) || Object.keys(route.config).length !== 0 || route.status !== 1)) return false
    const canonical = (routes: SandAiCapabilityRoute[]) => JSON.stringify(routes.map(route => JSON.stringify({
      capability_code: route.capability_code, route_kind: route.route_kind,
      deployment_id: route.deployment_id ?? null, driver_code: route.driver_code ?? null,
      priority: route.priority, config: route.config
    })).sort())
    return value.environment_id === payload.environment_id && value.name === payload.name
      && value.template_code === (payload.template_code ?? null) && value.data_egress_policy === payload.data_egress_policy
      && canonical(value.routes) === canonical(payload.routes)
  }
  function profilePayload(value: SandAiCapabilityProfile): CapabilitySavePayload {
    return { environment_id: value.environment_id, expected_revision: value.revision,
      name: value.name, template_code: value.template_code,
      data_egress_policy: value.data_egress_policy, routes: (value.routes ?? []).map(route => ({
        capability_code: route.capability_code, route_kind: route.route_kind, deployment_id: route.deployment_id,
        driver_code: route.driver_code, priority: route.priority, config: {}
      })) }
  }
  function confirmPending(value: SandAiCapabilityProfile | null, ownWriteRead = false): boolean {
    const operation = pending.value
    // 自动定向时以前端已解析目标为准；尚未解析时回退到显式选择的应用/环境。
    const expectedApplication = resolvedTarget.value?.application_id ?? applicationId.value
    const expectedEnvironment = resolvedTarget.value?.environment_id ?? environmentId.value
    if (!operation || !value || (writeInFlight.value && !ownWriteRead) || operation.admin_id !== adminId.value
      || operation.application_id !== expectedApplication || operation.environment_id !== expectedEnvironment
      || !sameConfiguration(value, operation.payload)) return !operation
    const confirmed = operation.action === 'publish'
      ? value.id === operation.id && value.revision === operation.revision && value.state === 'published' && value.status === 1
      : value.revision === operation.previousRevision + 1 && (value.state === 'draft' || value.state === 'published')
        && (!operation.id || (value.id === operation.id && value.revision === operation.revision))
    if (confirmed) clearIntent()
    return confirmed
  }
  function applyProfileRead(value: SandAiCapabilityProfile | null, ownWriteRead = false): void {
    const confirmed = confirmPending(value, ownWriteRead)
    profile.value = value
    profileLoaded.value = true
    if (!confirmed) return // Keep the attempted draft while its write outcome remains unconfirmed.
    if (value) {
      form.name = value.name
      form.template_code = value.template_code ?? ''
      form.data_egress_policy = value.data_egress_policy
      form.routes = toDraftRoutes(value.routes)
    } else {
      form.name = 'default-capability'
      form.template_code = 'local_development'
      applyTemplate('local_development')
    }
  }
  function adoptLatestProfile(): void {
    if (!revisionConflict.value || !profileLoaded.value || pending.value) return
    revisionConflict.value = false
    applyProfileRead(profile.value)
    // 同一对象引用不会触发 watch，这里手动重建能力卡片。
    buildCapabilityCards()
    errorMessage.value = ''
  }
  function definitelyRejected(error: unknown): boolean {
    // 明确拒绝统一判定：全部 4xx 与校验类业务码都算，不再只认 RESOURCE_CONFLICT。
    return isWriteRejected(error)
  }
  function isRevisionConflict(error: unknown): boolean {
    return error instanceof HttpError && /^SAND_AI_RESOURCE_CONFLICT(?=:)/.test(error.message)
  }
  function writeFailure(error: unknown, phase: 'preflight' | 'write' | 'confirm'): string {
    if (phase === 'preflight') return '无法确认当前完整配置，写操作已锁定。请成功重新读取状态后再继续。'
    if (phase === 'write' && isRevisionConflict(error))
      return '这条刚被别人改过，本地表单仍保留；请重新加载对比最新版本后再改。'
    if (phase === 'write' && definitelyRejected(error))
      return writeRejectionText(error, '这次操作被拒绝了，请核对内容后重试。')
    return '结果尚未确认。请重新读取状态核对同一配置；不会自动重发，请不要重复保存或启用。'
  }
  /** 「放弃此次变更」：无条件清本地记录解锁；先经用户确认，提示请求可能已送达。 */
  function abandonPendingWrite(): void {
    confirmAbandonPendingWrite(() => {
      try {
        if (adminId.value) dropCapabilityProfileIntent(adminId.value)
        pending.value = null
        journalError.value = false
        errorMessage.value = ''
        ElMessage.success('已放弃未确认的本地记录，页面已解锁')
      } catch {
        errorMessage.value = '本地记录清除失败，请检查浏览器存储后重试'
      }
    })
  }
  const routeKindLabels: Readonly<Record<string, string>> = {
    model_deployment: '模型服务',
    parse_driver: '本地解析工具',
    audio_driver: '音频引擎'
  }
  function routeKindLabel(kind: string): string {
    return routeKindLabels[kind] ?? kind
  }
  function routeKindOf(capabilityCode: string): SandAiCapabilityRoute['route_kind'] {
    const meta = metadata.value?.capabilities[capabilityCode]
    const kind = isRecord(meta) ? meta.route_kind : undefined
    return kind === 'model_deployment' || kind === 'audio_driver' ? kind : 'parse_driver'
  }

  function driverCodes(capabilityCode: string): string[] {
    const meta = metadata.value?.capabilities[capabilityCode]
    if (!isRecord(meta) || !isRecord(meta.drivers)) return []
    return Object.keys(meta.drivers)
  }

  function isLocalOcr(row: RouteDraft): boolean {
    return row.route_kind === 'parse_driver' && row.capability_code === 'image_ocr' && row.driver_code === 'local_paddle_ocr'
  }
  function usesDeployment(row: RouteDraft): boolean {
    return row.route_kind === 'model_deployment' || row.route_kind === 'audio_driver' || isLocalOcr(row)
  }

  async function searchDeployments(query: string, row: RouteDraft): Promise<void> {
    const capability = metadata.value?.capabilities[row.capability_code]
    const types = isRecord(capability) ? capability.model_types : undefined
    const modelType = isLocalOcr(row) ? 'image_ocr' : Array.isArray(types) && typeof types[0] === 'string' ? types[0] : null
    if (!modelType || !usesDeployment(row) || (isLocalOcr(row) && !environmentId.value)) return
    const current = ++deploymentGeneration
    const epoch = identityEpoch
    deploymentsLoading.value = true
    try {
      const page = await onboardingApi.publishedDeployments(modelType, query.trim(), row.capability_code, environmentId.value,
        isLocalOcr(row) ? 'ocr_route_configuration' : undefined)
      if (current !== deploymentGeneration || epoch !== identityEpoch) return
      deploymentOptions.value = page.data.filter(item => item.model_type === modelType && item.publication_status === 'published')
      if (page.total > page.per_page && !query.trim()) {
        ElMessage.info('已启用部署超过 100 项，请输入模型或服务商名称搜索')
      }
    } catch {
      if (current === deploymentGeneration && epoch === identityEpoch) errorMessage.value = '读取已启用部署失败，请检查后台权限'
    } finally {
      if (current === deploymentGeneration && epoch === identityEpoch) deploymentsLoading.value = false
    }
  }

  function syncRouteKind(row: RouteDraft): void {
    row.route_kind = routeKindOf(row.capability_code)
    row.deployment_id = undefined
    if (row.route_kind === 'model_deployment') {
      row.driver_code = null
    } else if (row.route_kind === 'audio_driver') {
      const drivers = driverCodes(row.capability_code)
      row.driver_code = drivers[0] ?? null
    } else {
      const drivers = driverCodes(row.capability_code)
      row.driver_code = drivers[0] ?? null
    }
  }

  function toDraftRoutes(routes: SandAiCapabilityRoute[] | undefined): RouteDraft[] {
    if (!routes) return []
    return routes.map((route) => ({
      capability_code: route.capability_code,
      route_kind: route.route_kind,
      deployment_id: route.deployment_id ?? undefined,
      driver_code: route.driver_code ?? null,
      priority: route.priority
    }))
  }

  function addRoute(): void {
    const code = capabilityCodes.value[0] ?? 'document_parse'
    const draft: RouteDraft = {
      capability_code: code,
      route_kind: routeKindOf(code),
      deployment_id: undefined,
      driver_code: null,
      priority: 100 + form.routes.length
    }
    syncRouteKind(draft)
    form.routes.push(draft)
  }

  function prepareLocalTextRoute(): void {
    if (form.routes.some(route => route.capability_code === 'document_parse')) {
      ElMessage.info('已配置文档解析，请核对该行的解析工具后保存')
      return
    }
    if (!driverCodes('document_parse').includes('native_document_parse')) {
      ElMessage.warning('本地文档解析工具暂不可用，请重新读取能力目录')
      return
    }
    form.routes.push({ capability_code: 'document_parse', route_kind: 'parse_driver',
      deployment_id: undefined, driver_code: 'native_document_parse', priority: 100 })
    ElMessage.info('已添加本地文本解析；请保存并启用能力配置')
  }

  function removeRoute(index: number): void {
    form.routes.splice(index, 1)
  }

  function prepareMaterialRoutes(): void {
    const chat = form.routes.find(route => route.capability_code === 'chat'
      && route.route_kind === 'model_deployment' && Number.isInteger(route.deployment_id)
      && (route.deployment_id ?? 0) > 0)
    if (!chat?.deployment_id) {
      ElMessage.warning('请先给 chat 路由选择已启用的聊天模型部署')
      return
    }
    if (!form.routes.some(route => route.capability_code === 'structured_extraction')) {
      form.routes.push({
        capability_code: 'structured_extraction',
        route_kind: 'model_deployment',
        deployment_id: chat.deployment_id,
        driver_code: null,
        priority: 100
      })
    }
    if (!form.routes.some(route => route.capability_code === 'document_parse')) {
      form.routes.push({
        capability_code: 'document_parse',
        route_kind: 'parse_driver',
        deployment_id: undefined,
        driver_code: 'native_document_parse',
        priority: 100
      })
    }
    ElMessage.info('已补齐本地文档解析和结构化抽取路由；请核对外发策略，再保存并启用配置档')
  }

  function applyTemplate(code: string | number | boolean | undefined): void {
    if (typeof code !== 'string' || !code || !metadata.value) return
    const tpl = metadata.value.templates[code]
    if (!tpl) return
    form.data_egress_policy = tpl.data_egress_policy
    form.routes = toDraftRoutes(tpl.default_routes)
    if (form.routes.length === 0) {
      addRoute()
    }
  }

  type CapabilityCardKind = 'model' | 'audio' | 'parse'
  type CapabilityCard = {
    code: string
    name: string
    usage: string
    kind: CapabilityCardKind
    modelType: string | null
    drivers: string[]
    disabled: boolean
    wasBound: boolean
    defaultDeploymentId: number | undefined
    backupDeploymentId: number | undefined
    defaultDriverCode: string
    backupDriverCode: string
    parseDriverCode: string
    parseDeploymentId: number | undefined
    extraRoutes: SandAiCapabilityRoute[]
    snapshot: string
    fieldError: string
  }

  type CapabilityCardDraft = {
    disabled: boolean
    defaultDeploymentId: number | undefined
    backupDeploymentId: number | undefined
    defaultDriverCode: string
    backupDriverCode: string
    parseDriverCode: string
    parseDeploymentId: number | undefined
  }

  const capabilityCards = ref<CapabilityCard[]>([])
  // 首次进入即处于加载态，直到首次配置档读取结束。
  const capabilityLoading = ref(true)
  const capabilityLoadFailed = ref(false)
  const capabilityLoadError = ref<unknown>(null)
  const cardDeploymentCache = ref<Record<string, PublishedDeployment[]>>({})
  const cardDeploymentLoading = ref<Record<string, boolean>>({})
  const cardDeploymentRequested = ref<Record<string, boolean>>({})
  /** 未过滤的已发布部署目录：既给已选值补真实名称，也作为「模型是否已下线」的判定依据。 */
  const cardDeploymentCatalog = ref<Record<string, { items: PublishedDeployment[]; total: number }>>({})
  const cardDrafts = new Map<string, CapabilityCardDraft>()
  /** 同一模型类型的目录请求按序号作废旧结果；不同类型互不取消，可并行预取。 */
  const cardDeploymentSeq: Record<string, number> = {}
  /** 未过滤目录失败后的有限自动重试；成功或身份/目标重置时清零。 */
  const cardDeploymentRetries: Record<string, number> = {}
  const cardRetryTimers = new Map<string, ReturnType<typeof setTimeout>>()

  /** 卡片展示顺序：先对话等高频能力，再文件/多模态，最后检索与音频。 */
  const CAPABILITY_ORDER = [
    'chat', 'structured_extraction', 'vision',
    'document_parse', 'image_ocr',
    'embedding', 'rerank',
    'audio_transcription', 'audio_speech'
  ]
  /** 一句话说明这项能力用在什么场景，只用于卡片副标题。 */
  const CAPABILITY_USAGE: Readonly<Record<string, string>> = {
    chat: '试用对话、智能体对话',
    structured_extraction: '从材料里抽取字段',
    vision: '看图问答、图片理解',
    document_parse: '上传文档的正文读取',
    image_ocr: '扫描件、图片里的文字识别',
    embedding: '知识库、私有资料检索',
    rerank: '检索结果排序',
    audio_transcription: '录音、会议转文字',
    audio_speech: '文字转语音播放'
  }
  /** 本能力暂不在本页展示的内部能力码（本期仅隐藏法律分析入口）。 */
  const CAPABILITY_HIDDEN = new Set(['legal_analysis'])

  function capabilityMeta(code: string): Record<string, unknown> | null {
    const meta = metadata.value?.capabilities[code]
    return isRecord(meta) ? meta : null
  }

  function capabilityModelType(code: string): string | null {
    const meta = capabilityMeta(code)
    const types = meta?.model_types
    return Array.isArray(types) && typeof types[0] === 'string' ? types[0] : null
  }

  function cardKindOf(code: string): CapabilityCardKind {
    const kind = capabilityMeta(code)?.route_kind
    if (kind === 'model_deployment') return 'model'
    if (kind === 'audio_driver') return 'audio'
    return 'parse'
  }

  /** 卡片可选驱动：本地/私有网络发送策略下只保留本地处理驱动，与后端校验一致。 */
  function cardDrivers(code: string): string[] {
    const meta = capabilityMeta(code)
    const driverMap = meta !== null && isRecord(meta.drivers) ? meta.drivers : null
    const drivers = driverMap !== null ? Object.keys(driverMap) : []
    if (!['local_only', 'private_only'].includes(effectiveEgressPolicy())) return drivers
    return drivers.filter(driver => {
      const entry = driverMap?.[driver]
      return isRecord(entry) && entry.data_egress === 'local_only'
    })
  }

  /** 语义化路线签名：只比较类型/部署/驱动，忽略优先级数字与配置细节。 */
  function semanticRoutes(routes: SandAiCapabilityRoute[]): string {
    return JSON.stringify(routes.map(route => [
      route.route_kind, route.deployment_id ?? null, route.driver_code ?? null
    ]).sort())
  }

  /** 把服务端路线收成卡片可编辑的载荷形状（去掉 id/status/config）。 */
  function routePayload(route: SandAiCapabilityRoute): SandAiCapabilityRoute {
    return {
      capability_code: route.capability_code,
      route_kind: route.route_kind,
      deployment_id: route.deployment_id ?? null,
      driver_code: route.driver_code ?? null,
      priority: route.priority,
      config: {}
    }
  }

  /** 当前卡片草稿对应的路线集合；disabled 或未选全时按空/部分处理。 */
  function cardRoutes(card: CapabilityCard): SandAiCapabilityRoute[] {
    if (card.disabled) return []
    const routes: SandAiCapabilityRoute[] = []
    const push = (priority: number, routeKind: SandAiCapabilityRoute['route_kind'], deploymentId: number | null, driverCode: string | null): void => {
      routes.push({
        capability_code: card.code, route_kind: routeKind,
        deployment_id: deploymentId, driver_code: driverCode, priority, config: {}
      })
    }
    if (card.kind === 'model') {
      if (card.defaultDeploymentId) push(100, 'model_deployment', card.defaultDeploymentId, null)
      if (card.backupDeploymentId) push(200, 'model_deployment', card.backupDeploymentId, null)
    } else if (card.kind === 'audio') {
      if (card.defaultDriverCode && card.defaultDeploymentId) push(100, 'audio_driver', card.defaultDeploymentId, card.defaultDriverCode)
      if (card.backupDriverCode && card.backupDeploymentId) push(200, 'audio_driver', card.backupDeploymentId, card.backupDriverCode)
    } else {
      // 解析类能力：单一驱动时视为已选；多驱动必须显式选择。
      const driver = card.parseDriverCode || (card.drivers.length === 1 ? card.drivers[0] : '')
      if (driver) {
        const bound = driver === 'local_paddle_ocr' ? card.parseDeploymentId ?? null : null
        push(100, 'parse_driver', bound, driver)
      }
    }
    card.extraRoutes.forEach((route, index) => {
      routes.push({ ...routePayload(route), priority: 300 + index * 100 })
    })
    return routes
  }

  function cardDirty(card: CapabilityCard): boolean {
    return semanticRoutes(cardRoutes(card)) !== card.snapshot
  }

  /** 页面级保存按钮只在确有未保存改动时可点；停用卡片同样计入改动。 */
  const dirtyCardCount = computed(() => capabilityCards.value.filter(card => cardDirty(card)).length)
  const hasUnsavedCards = computed(() => dirtyCardCount.value > 0)
  const unsavedCardsHint = computed(() => `${dirtyCardCount.value} 项能力有未保存的改动`)

  function cardValidationError(card: CapabilityCard): string {
    if (card.disabled) return ''
    if (card.kind === 'model') {
      if (!card.defaultDeploymentId) return '请选一个默认模型，或停用这项能力'
      if (card.backupDeploymentId && card.backupDeploymentId === card.defaultDeploymentId) return '备用模型要和默认模型不一样'
      return ''
    }
    if (card.kind === 'audio') {
      const defaultPair = Boolean(card.defaultDriverCode) !== Boolean(card.defaultDeploymentId)
      const backupPair = Boolean(card.backupDriverCode) !== Boolean(card.backupDeploymentId)
      if (defaultPair) return '默认引擎和默认模型要一起选择'
      if (backupPair) return '备用引擎和备用模型要一起选择'
      if (card.defaultDriverCode && !card.drivers.includes(card.defaultDriverCode)) return '默认引擎在当前设置下不可用，请重新选择或停用这项能力'
      if (card.backupDriverCode && !card.drivers.includes(card.backupDriverCode)) return '备用引擎在当前设置下不可用，请重新选择或停用这项能力'
      if (!card.defaultDriverCode || !card.defaultDeploymentId) return '请选默认引擎和默认模型，或停用这项能力'
      if (card.backupDeploymentId && card.backupDeploymentId === card.defaultDeploymentId) return '备用模型要和默认模型不一样'
      return ''
    }
    if (card.drivers.length === 0) return '当前设置下没有可用的解析方式，请停用这项能力'
    if (card.parseDriverCode && !card.drivers.includes(card.parseDriverCode)) return '解析方式在当前设置下不可用，请重新选择或停用这项能力'
    if (card.drivers.length > 1 && !card.parseDriverCode) return '请选择解析方式，或停用这项能力'
    return ''
  }

  function cardTag(card: CapabilityCard): { text: string; type: 'success' | 'warning' | 'info' | 'danger' } {
    if (card.disabled) {
      if (card.snapshot !== '[]') return { text: '待停用', type: 'warning' }
      return card.wasBound ? { text: '已停用', type: 'info' } : { text: '未配置', type: 'info' }
    }
    if (cardRoutes(card).length === 0) return { text: '未配置', type: 'info' }
    return profile.value?.state === 'published' ? { text: '已启用', type: 'success' } : { text: '待启用', type: 'warning' }
  }

  const canWriteCards = computed(() =>
    hasAuth('sand_ai:setup:capability:save') && hasAuth('sand_ai:setup:capability:publish'))

  function cardWriteBlocked(): boolean {
    return Boolean(busy.value) || writeInFlight.value || journalError.value || Boolean(pending.value)
      || revisionConflict.value || unsafeExistingConfig.value || !profileLoaded.value || !canWriteCards.value
  }

  function cardFormDisabled(card: CapabilityCard): boolean {
    return cardWriteBlocked() || card.disabled
  }

  /** 部署目录按模型类型缓存；卡片只按类型+策略+驱动规则过滤展示。 */
  function effectiveEgressPolicy(): string {
    const policy = profile.value?.data_egress_policy
    return typeof policy === 'string' && policy !== '' ? policy : 'approved_cloud'
  }

  function egressCompatible(item: PublishedDeployment): boolean {
    const policy = effectiveEgressPolicy()
    return !policy || item.compatible_data_egress_policies.includes(policy)
  }

  function providerAdapterAllowed(item: PublishedDeployment, adapters: readonly string[]): boolean {
    return adapters.length === 0 || (item.provider_adapter !== undefined && adapters.includes(item.provider_adapter))
  }

  function audioDriverDeploymentMatch(item: PublishedDeployment, driverCode: string): boolean {
    if (driverCode === 'local_whisper_cpp') {
      return item.provider_adapter === 'local_runtime' && item.remote_model === 'local_whisper_cpp'
    }
    return item.provider_adapter === 'openai_compatible'
  }

  function visibleDeployments(card: CapabilityCard, driverCode?: string): PublishedDeployment[] {
    if (!card.modelType) return []
    const items = cardDeploymentCache.value[card.modelType] ?? []
    // 服务商适配器限制优先读能力元数据（如专业重排），缺省时仅重排能力保留内置白名单。
    const adaptersMeta = capabilityMeta(card.code)?.provider_adapters
    const adapters = Array.isArray(adaptersMeta)
      ? adaptersMeta.filter((item): item is string => typeof item === 'string')
      : card.code === 'rerank' ? ['cohere_rerank', 'jina_rerank'] : []
    return items.filter(item => {
      if (!egressCompatible(item)) return false
      if (adapters.length > 0) return providerAdapterAllowed(item, adapters)
      if (card.kind === 'audio') return driverCode ? audioDriverDeploymentMatch(item, driverCode) : item.provider_adapter === 'openai_compatible' || item.provider_adapter === 'local_runtime'
      return true
    })
  }

  /** 已选部署是否已从已发布目录消失；目录未完整加载时不下结论，避免分页截断误报。 */
  function deploymentOffline(card: CapabilityCard, id: number | undefined): boolean {
    if (!id || !card.modelType) return false
    const catalog = cardDeploymentCatalog.value[card.modelType]
    if (!catalog || catalog.items.some(item => item.id === id)) return false
    return catalog.total <= catalog.items.length
  }

  function offlineModelText(card: CapabilityCard, id: number | undefined): string {
    return deploymentOffline(card, id) ? '这个模型已下线，请换一个' : ''
  }

  /** remote 下拉的选中文案只在挂载、选项成员增删或 modelValue 变化时重算；
      * 目录到达后同 key 选项只是纯 label 补丁，不会触发重算，因此换 key 强制重挂。 */
  function cardDeploymentsKey(card: CapabilityCard, slot: 'default' | 'backup'): string {
    const ready = card.modelType !== null && cardDeploymentCatalog.value[card.modelType] !== undefined ? 'ready' : 'pending'
    return `${card.modelType ?? 'none'}-${ready}-${slot}`
  }

  /** 当前已选值不在过滤后目录时补一条占位选项：目录里能找到就显示真实名称，确认下线才标注。 */
  function withCurrentOption(card: CapabilityCard, options: PublishedDeployment[], currentId: number | undefined): PublishedDeployment[] {
    if (!currentId || options.some(item => item.id === currentId)) return options
    const catalog = card.modelType ? cardDeploymentCatalog.value[card.modelType] : undefined
    const cached = card.modelType ? cardDeploymentCache.value[card.modelType] : undefined
    const known = catalog?.items.find(item => item.id === currentId) ?? cached?.find(item => item.id === currentId)
    const placeholder: PublishedDeployment = {
      id: currentId,
      deployment_revision: 0,
      display_name: known?.display_name ?? (deploymentOffline(card, currentId) ? '原设置的模型（已下线）' : '原设置的模型'),
      model_type: card.modelType ?? '',
      publication_status: 'published',
      compatible_data_egress_policies: []
    }
    return [placeholder, ...options]
  }

  function cardDeploymentOptions(card: CapabilityCard, driverCode?: string, currentId?: number): PublishedDeployment[] {
    return withCurrentOption(card, visibleDeployments(card, driverCode), currentId)
  }

  function cardDeploymentsLoading(card: CapabilityCard): boolean {
    return Boolean(card.modelType && cardDeploymentLoading.value[card.modelType])
  }

  function deploymentsRequested(card: CapabilityCard): boolean {
    return Boolean(card.modelType && cardDeploymentRequested.value[card.modelType])
  }

  function modelPlaceholder(card: CapabilityCard, slot: 'default' | 'backup'): string {
    // 音频能力的可选部署随所选引擎变化，占位提示按对应引擎过滤后的结果判断。
    const driver = card.kind === 'audio'
      ? (slot === 'default' ? card.defaultDriverCode : card.backupDriverCode) || undefined
      : undefined
    const options = driver ? visibleDeployments(card, driver) : visibleDeployments(card)
    if (deploymentsRequested(card) && options.length === 0) return '还没有支持这项能力的模型'
    return slot === 'default' ? '选择默认模型' : '可不选，默认连不上时改用'
  }

  function cardShowProviderLink(card: CapabilityCard): boolean {
    return !card.disabled && card.kind !== 'parse' && deploymentsRequested(card)
      && visibleDeployments(card).length === 0
  }

  async function ensureCardDeployments(card: CapabilityCard): Promise<void> {
    if (!card.modelType || cardDeploymentLoading.value[card.modelType]) return
    // requested 只证明拿过一次目录；目录缺失（失败或被作废）时允许重试，避免占位文案卡死。
    if (cardDeploymentRequested.value[card.modelType] && cardDeploymentCatalog.value[card.modelType]) return
    cardDeploymentRequested.value = { ...cardDeploymentRequested.value, [card.modelType]: true }
    await loadCardDeployments(card.modelType, '')
  }

  async function searchCardDeployments(card: CapabilityCard, query: string): Promise<void> {
    if (!card.modelType) return
    cardDeploymentRequested.value = { ...cardDeploymentRequested.value, [card.modelType]: true }
    await loadCardDeployments(card.modelType, query.trim())
  }

  async function loadCardDeployments(modelType: string, query: string): Promise<void> {
    const current = (cardDeploymentSeq[modelType] = (cardDeploymentSeq[modelType] ?? 0) + 1)
    const epoch = identityEpoch
    cardDeploymentLoading.value = { ...cardDeploymentLoading.value, [modelType]: true }
    try {
      const page = await onboardingApi.publishedDeployments(modelType, query || undefined)
      if (epoch !== identityEpoch) return
      // 只有未过滤的整页结果才更新下线判定目录；搜索结果只进下拉缓存。
      if (query === '') {
        cardDeploymentCatalog.value = { ...cardDeploymentCatalog.value, [modelType]: { items: page.data, total: page.total } }
        cardDeploymentRetries[modelType] = 0
        const pendingTimer = cardRetryTimers.get(modelType)
        if (pendingTimer !== undefined) {
          clearTimeout(pendingTimer)
          cardRetryTimers.delete(modelType)
        }
      }
      if (current !== cardDeploymentSeq[modelType]) return
      cardDeploymentCache.value = { ...cardDeploymentCache.value, [modelType]: page.data }
      if (page.total > page.per_page && !query) {
        ElMessage.info('可选模型较多，请输入模型或服务商名称搜索')
      }
    } catch (error) {
      if (current === cardDeploymentSeq[modelType] && epoch === identityEpoch) {
        errorMessage.value = errorDisplayText(error, '读取可选模型失败，请检查后台权限')
        // 目录缺失时安排有限自动重试，避免已选模型一直停在中性占位；搜索请求不重试。
        if (query === '') scheduleCardDeploymentRetry(modelType, epoch)
      }
    } finally {
      if (current === cardDeploymentSeq[modelType] && epoch === identityEpoch) {
        cardDeploymentLoading.value = { ...cardDeploymentLoading.value, [modelType]: false }
      }
    }
  }

  /** 未过滤目录失败后有限自动重试（每类型最多 2 次）；身份或目标变化后旧定时器作废。 */
  function scheduleCardDeploymentRetry(modelType: string, epoch: number): void {
    const attempts = cardDeploymentRetries[modelType] ?? 0
    if (attempts >= 2 || cardRetryTimers.has(modelType)) return
    cardDeploymentRetries[modelType] = attempts + 1
    const timer = setTimeout(() => {
      cardRetryTimers.delete(modelType)
      if (epoch === identityEpoch) void loadCardDeployments(modelType, '')
    }, 1200)
    cardRetryTimers.set(modelType, timer)
  }

  function clearCardDeploymentRetries(): void {
    for (const timer of cardRetryTimers.values()) clearTimeout(timer)
    cardRetryTimers.clear()
    for (const key of Object.keys(cardDeploymentRetries)) delete cardDeploymentRetries[key]
  }

  function driverOptionLabel(code: string): string {
    const label = driverLabel(code)
    // 未收录的驱动码不直接回显英文码，给一句中性说明。
    return label === code ? '原设置的方式（可能已停用）' : label
  }

  function cardDraftOf(card: CapabilityCard): CapabilityCardDraft {
    return {
      disabled: card.disabled,
      defaultDeploymentId: card.defaultDeploymentId,
      backupDeploymentId: card.backupDeploymentId,
      defaultDriverCode: card.defaultDriverCode,
      backupDriverCode: card.backupDriverCode,
      parseDriverCode: card.parseDriverCode,
      parseDeploymentId: card.parseDeploymentId
    }
  }

  function applyCardDraft(card: CapabilityCard, draft: CapabilityCardDraft | undefined): void {
    if (!draft) return
    card.disabled = draft.disabled
    card.defaultDeploymentId = draft.defaultDeploymentId
    card.backupDeploymentId = draft.backupDeploymentId
    card.defaultDriverCode = draft.defaultDriverCode
    card.backupDriverCode = draft.backupDriverCode
    card.parseDriverCode = draft.parseDriverCode
    card.parseDeploymentId = draft.parseDeploymentId
  }

  function buildCapabilityCards(): void {
    if (materialConfigurationPage.value) {
      capabilityCards.value = []
      return
    }
    // 重建前保留各卡片未保存的草稿，避免保存一张卡时冲掉其他卡的修改；
    // 「未配置却被点启用」也算未提交意图，一并保留。
    for (const card of capabilityCards.value) {
      const baselineDisabled = card.snapshot === '[]'
      if (cardDirty(card) || card.disabled !== baselineDisabled) cardDrafts.set(card.code, cardDraftOf(card))
    }
    const routes = profile.value?.routes ?? []
    const capabilities = metadata.value?.capabilities ?? {}
    const codes = Object.keys(capabilities)
      .filter(code => !CAPABILITY_HIDDEN.has(code))
    codes.sort((left, right) => {
      const li = CAPABILITY_ORDER.indexOf(left)
      const ri = CAPABILITY_ORDER.indexOf(right)
      return (li < 0 ? CAPABILITY_ORDER.length : li) - (ri < 0 ? CAPABILITY_ORDER.length : ri)
        || left.localeCompare(right)
    })
    capabilityCards.value = codes.map(code => {
      const kind = cardKindOf(code)
      const modelType = kind === 'parse' && code !== 'image_ocr' ? null : capabilityModelType(code)
      const own = routes
        .filter(route => route.capability_code === code && route.status !== 2)
        .sort((a, b) => a.priority - b.priority)
      // 曾绑定后被停用的路线（status=2）不参与编辑，但要把卡片与从未配置区分开。
      const wasBound = routes.some(route => route.capability_code === code && route.status === 2)
      const consumed = kind === 'parse' ? 1 : 2
      const extras = own.slice(consumed).map(routePayload)
      const primary = own[0]
      const secondary = own[1]
      const label = capabilityLabel(code)
      const card: CapabilityCard = {
        code,
        // 未收录中文名的能力不回显英文码，给中性名称。
        name: label === code ? 'AI 能力' : label,
        usage: CAPABILITY_USAGE[code] ?? 'AI 能力',
        kind,
        modelType,
        drivers: cardDrivers(code),
        disabled: own.length === 0,
        wasBound,
        defaultDeploymentId: undefined,
        backupDeploymentId: undefined,
        defaultDriverCode: '',
        backupDriverCode: '',
        parseDriverCode: '',
        parseDeploymentId: undefined,
        extraRoutes: extras,
        snapshot: '',
        fieldError: ''
      }
      if (kind === 'model') {
        card.defaultDeploymentId = primary?.deployment_id ?? undefined
        card.backupDeploymentId = secondary?.deployment_id ?? undefined
      } else if (kind === 'audio') {
        card.defaultDriverCode = primary?.driver_code ?? ''
        card.defaultDeploymentId = primary?.deployment_id ?? undefined
        card.backupDriverCode = secondary?.driver_code ?? ''
        card.backupDeploymentId = secondary?.deployment_id ?? undefined
      } else if (primary) {
        card.parseDriverCode = primary.driver_code ?? ''
        card.parseDeploymentId = primary.deployment_id ?? undefined
      }
      card.snapshot = semanticRoutes(own.slice(0, consumed).map(routePayload).concat(extras))
      applyCardDraft(card, cardDrafts.get(code))
      return card
    })
    // 预取各能力模型类型的已发布部署目录，让卡片直接显示真实模型名；请求按类型去重。
    for (const card of capabilityCards.value) {
      if (card.modelType) void ensureCardDeployments(card)
    }
  }

  function clearCapabilityCards(): void {
    cardDrafts.clear()
    capabilityCards.value = []
    cardDeploymentCache.value = {}
    cardDeploymentLoading.value = {}
    cardDeploymentRequested.value = {}
    cardDeploymentCatalog.value = {}
    for (const key of Object.keys(cardDeploymentSeq)) delete cardDeploymentSeq[key]
    clearCardDeploymentRetries()
    capabilityLoading.value = true
    capabilityLoadFailed.value = false
    capabilityLoadError.value = null
    resolvedTarget.value = null
  }

  function retryCapabilityLoad(): void {
    void reloadProfile()
  }

  /** 没有可加载目标时结束骨架态，交给空态或「请先选择」提示接管。 */
  function settleCapabilityIdle(): void {
    if (materialConfigurationPage.value) return
    capabilityLoading.value = false
    capabilityLoadFailed.value = false
    capabilityLoadError.value = null
  }

  async function copyCapabilityProblem(): Promise<void> {
    const fault = describeBoundaryFault(capabilityLoadError.value ?? new Error('capability load failed'))
    const lines = ['SandAI 管理页问题信息', '页面：/sand-ai/capability', ...problemInfoText(fault).split('\n').slice(1)]
    const text = lines.join('\n')
    try {
      if (navigator.clipboard?.writeText) {
        await navigator.clipboard.writeText(text)
      } else {
        const area = document.createElement('textarea')
        area.value = text
        area.style.position = 'fixed'
        area.style.opacity = '0'
        document.body.appendChild(area)
        area.select()
        document.execCommand('copy')
        area.remove()
      }
      ElMessage.success('已复制，可发给管理员')
    } catch (error) {
      console.error('[sand-ai] capability copy failed', error)
      ElMessage.warning('复制未完成，请稍后重试')
    }
  }

  function enableCapabilityCard(card: CapabilityCard): void {
    card.disabled = false
    card.fieldError = ''
  }

  async function askDisableCapability(card: CapabilityCard): Promise<void> {
    if (cardWriteBlocked()) return
    try {
      await ElMessageBox.confirm(
        `停用「${card.name}」后，${card.usage}将不可用，随时可以再启用。`,
        `停用「${card.name}」？`,
        { type: 'warning', confirmButtonText: '停用', cancelButtonText: '再想想' }
      )
    } catch {
      return
    }
    card.disabled = true
    card.fieldError = ''
  }

  /** 页面级保存载荷：全部卡片草稿合并为一次配置；卡片未覆盖的能力码原样保留。 */
  function cardsSavePayload(): CapabilitySavePayload | string {
    const targetEnvironment = resolvedTarget.value?.environment_id ?? environmentId.value
    const targetApplication = resolvedTarget.value?.application_id ?? applicationId.value
    if (!targetEnvironment) return '请先选择应用和环境'
    // 写操作记录要求明确的应用编号；解析不到时不允许保存，避免产生不可确认的写入。
    if (!targetApplication) return '无法确认当前应用，请重新加载后重试'
    const cardCodes = new Set(capabilityCards.value.map(card => card.code))
    const preserved = (profile.value?.routes ?? [])
      .filter(route => route.status !== 2 && !cardCodes.has(route.capability_code))
      .map(routePayload)
    const routes = [...preserved, ...capabilityCards.value.flatMap(card => cardRoutes(card))]
    if (routes.length === 0) return '至少要保留一项能力的配置'
    return {
      application_id: targetApplication,
      environment_id: targetEnvironment,
      expected_revision: profile.value?.revision ?? null,
      name: profile.value?.name ?? 'default-capability',
      template_code: profile.value?.template_code ?? null,
      // 无既有配置档时取最宽松策略，保证云端模型可选；既有策略原样保留。
      data_egress_policy: profile.value?.data_egress_policy ?? 'approved_cloud',
      routes
    }
  }

  /** 页面级保存：逐项校验能力卡，合并全部卡片草稿为一次保存+启用事务。 */
  async function saveCapabilityCards(): Promise<void> {
    if (cardWriteBlocked() || busy.value) { ElMessage.warning('请先读取可安全编辑的完整配置。'); return }
    for (const card of capabilityCards.value) card.fieldError = ''
    const problems = capabilityCards.value
      .map(card => ({ card, error: cardValidationError(card) }))
      .filter(item => item.error !== '')
    if (problems.length > 0) {
      for (const item of problems) item.card.fieldError = item.error
      ElMessage.warning(problems.length === 1
        ? `${problems[0].card.name}：${problems[0].error}`
        : `${problems.length} 项能力还没选完整，请逐项检查后再保存`)
      return
    }
    const payload = cardsSavePayload()
    if (typeof payload === 'string') {
      ElMessage.warning(payload)
      return
    }
    await commitCapabilityWrite(payload, '已保存并启用，能力配置已更新')
  }

  /** 单次写事务：预检 → 保存草稿 → 立即启用 → 回读确认，与既有写路径一致。 */
  async function commitCapabilityWrite(payload: CapabilitySavePayload, successText: string): Promise<void> {
    const targetEnvironment = payload.environment_id
    const targetApplication = payload.application_id
    if (!targetApplication) {
      errorMessage.value = '无法确认当前应用，请重新加载后重试'
      ElMessage.warning(errorMessage.value)
      return
    }
    const baseline = JSON.stringify(profile.value)
    const previousRevision = profile.value?.revision ?? 0
    const current = ++profileGeneration
    const epoch = identityEpoch
    const owner = adminId.value
    const ownsOperation = () => current === profileGeneration && epoch === identityEpoch
      && owner === adminId.value
      && (resolvedTarget.value?.environment_id ?? environmentId.value) === targetEnvironment
      && (resolvedTarget.value?.application_id ?? applicationId.value ?? null) === targetApplication
    const flight = ++writeFlightSerial
    writeInFlight.value = true
    busy.value = 'save'
    profileLoaded.value = false
    errorMessage.value = ''
    let phase: 'preflight' | 'write' | 'confirm' = 'preflight'
    try {
      const preflight = await capabilityApi.readProfile({
        ...(targetApplication ? { application_id: targetApplication } : {}),
        environment_id: targetEnvironment
      })
      if (!ownsOperation()) return
      const resolved = readCapabilityTarget(preflight)
      if (resolved) resolvedTarget.value = resolved
      const latest = readProfileResponse(preflight, targetEnvironment)
      if (JSON.stringify(latest) !== baseline) {
        profile.value = latest
        profileLoaded.value = true
        revisionConflict.value = true
        errorMessage.value = '配置已经变化，本地选择仍保留。请比较最新版本后再保存。'
        return
      }
      persistIntent({ version: 1, admin_id: owner, application_id: targetApplication, environment_id: targetEnvironment, action: 'save', payload, previousRevision })
      phase = 'write'
      const saved = writtenProfileResponse(await capabilityApi.saveProfile(payload), targetEnvironment)
      if (!ownsOperation()) return
      if (!sameConfiguration(saved, payload) || saved.revision !== previousRevision + 1) throw new Error('Unexpected save response')
      const operation = pending.value
      if (!operation) throw new Error('原保存记录缺失')
      persistIntent({ ...operation, id: saved.id, revision: saved.revision }, operation)
      const published = writtenProfileResponse(await capabilityApi.publishProfile({
        ...(targetApplication ? { application_id: targetApplication } : {}),
        environment_id: targetEnvironment,
        id: saved.id,
        expected_revision: saved.revision
      }), targetEnvironment)
      if (!ownsOperation()) return
      if (published.id !== saved.id || published.revision !== saved.revision
        || published.state !== 'published' || published.status !== 1
        || !sameConfiguration(published, payload)) throw new Error('Unexpected publish response')
      phase = 'confirm'
      const reread = await capabilityApi.readProfile({
        ...(targetApplication ? { application_id: targetApplication } : {}),
        environment_id: targetEnvironment
      })
      if (!ownsOperation()) return
      const rereadTarget = readCapabilityTarget(reread)
      if (rereadTarget) resolvedTarget.value = rereadTarget
      applyProfileRead(readProfileResponse(reread, targetEnvironment), true)
      if (pending.value) errorMessage.value = '保存结果尚未确认，请重新读取状态；不会重发。'
      else ElMessage.success(successText)
    } catch (error) {
      if (!ownsOperation()) return
      if (phase === 'write' && definitelyRejected(error)) {
        try { clearIntent() } catch { errorMessage.value = '原记录未解除，结果保持待确认；请重新读取原目标'; return }
        // 拒绝已结清后恢复可编辑
        profileLoaded.value = true
        errorMessage.value = writeFailure(error, phase)
        return
      }
      if (phase === 'write' && isRevisionConflict(error)) revisionConflict.value = true
      profileLoaded.value = false
      errorMessage.value = writeFailure(error, phase)
    } finally {
      if (flight === writeFlightSerial) writeInFlight.value = false
      if (ownsOperation()) busy.value = ''
    }
  }

  async function loadApps(): Promise<void> {
    const current = ++applicationGeneration
    loadingApps.value = true
    if (iamListError.value === 'applications') errorMessage.value = ''
    iamListError.value = ''
    try {
      const rows = await iamApi.activeApplications()
      if (current !== applicationGeneration) return
      applications.value = rows
      // 零应用时没有可解析目标，结束骨架态交给空态提示。
      if (rows.length === 0) settleCapabilityIdle()
      if (!applicationId.value && rows.length === 1) {
        applicationId.value = rows[0].id
        void onApplicationChange()
      }
    } catch (error) {
      if (current === applicationGeneration) {
        applications.value = []
        iamListError.value = 'applications'
        settleCapabilityIdle()
        errorMessage.value = errorDisplayText(error, '读取应用失败，请重试')
      }
    } finally {
      if (current === applicationGeneration) loadingApps.value = false
    }
  }

  async function loadMetadata(): Promise<void> {
    const epoch = identityEpoch
    try {
      const result = await capabilityApi.metadata()
      if (epoch === identityEpoch) metadata.value = result
    } catch (error) {
      if (epoch !== identityEpoch) return
      errorMessage.value = errorDisplayText(error, '加载能力元数据失败')
    }
  }

  async function onApplicationChange(): Promise<void> {
    const current = ++environmentGeneration
    environmentId.value = undefined
    environments.value = []
    loadingEnvs.value = false
    if (iamListError.value === 'environments') errorMessage.value = ''
    iamListError.value = ''
    profileGeneration++
    revisionConflict.value = false
    profileLoaded.value = false
    profile.value = null
    form.routes = []
    // 切换应用后正在重新解析目标，骨架态持续到首次读取结束或出现选择提示。
    if (!materialConfigurationPage.value) capabilityLoading.value = true
    if (!applicationId.value) { settleCapabilityIdle(); return }
    const selectedApplication = applicationId.value
    loadingEnvs.value = true
    try {
      const rows = await iamApi.activeEnvironments(selectedApplication)
      if (current !== environmentGeneration || selectedApplication !== applicationId.value) return
      environments.value = rows
      if (!environmentId.value && rows.length === 1) {
        environmentId.value = rows[0].id
        onEnvironmentChange()
      } else if (rows.length === 0) {
        // 环境为空时让 reloadProfile 走「未就绪」分支，结束加载态交给空环境提示。
        loadingEnvs.value = false
        void reloadProfile()
      }
    } catch (error) {
      if (current === environmentGeneration && selectedApplication === applicationId.value) {
        iamListError.value = 'environments'
        settleCapabilityIdle()
        errorMessage.value = errorDisplayText(error, '读取环境失败，请重试')
      }
    } finally {
      if (current === environmentGeneration) loadingEnvs.value = false
    }
  }

  /** 能力页读取参数：已知的应用/环境显式带上，未知字段由后端在唯一候选时解析。 */
  function capabilityReadParams(): CapabilityTargetParams | undefined {
    if (materialConfigurationPage.value) {
      return environmentId.value ? { environment_id: environmentId.value } : undefined
    }
    const params: CapabilityTargetParams = {}
    if (applicationId.value) params.application_id = applicationId.value
    if (environmentId.value) params.environment_id = environmentId.value
    return params
  }

  async function reloadProfile(): Promise<void> {
    if (busy.value || writeInFlight.value || journalError.value) return
    const capabilityPage = !materialConfigurationPage.value
    const ready = capabilityPage ? targetSelectionReady.value : Boolean(environmentId.value)
    if (!ready) {
      // 能力页选择未完成时清空旧配置，展示「请先选择」而不是静默卡住。
      if (capabilityPage) {
        profileGeneration++
        profileLoaded.value = false
        profile.value = null
        resolvedTarget.value = null
        revisionConflict.value = false
        capabilityLoading.value = false
        capabilityLoadFailed.value = false
      }
      return
    }
    const expectedApplication = resolvedTarget.value?.application_id ?? applicationId.value
    const expectedEnvironment = resolvedTarget.value?.environment_id ?? environmentId.value
    if (pending.value && (pending.value.admin_id !== adminId.value
      || pending.value.application_id !== expectedApplication
      || pending.value.environment_id !== expectedEnvironment)) {
      errorMessage.value = '请回到原操作应用和环境读取状态'
      return
    }
    const targetApplication = applicationId.value
    const targetEnvironment = environmentId.value
    const current = ++profileGeneration
    const epoch = identityEpoch
    profileLoaded.value = false
    if (capabilityPage) {
      capabilityLoading.value = true
      capabilityLoadFailed.value = false
      capabilityLoadError.value = null
    }
    busy.value = 'load'
    errorMessage.value = ''
    const targetUnchanged = () => current === profileGeneration && epoch === identityEpoch
      && applicationId.value === targetApplication && environmentId.value === targetEnvironment
    try {
      const response = await capabilityApi.readProfile(capabilityReadParams())
      if (!targetUnchanged()) return
      const resolved = readCapabilityTarget(response)
      if (resolved) resolvedTarget.value = resolved
      const latest = readProfileResponse(response, resolvedTarget.value?.environment_id ?? targetEnvironment)
      if (latest && resolvedTarget.value && latest.environment_id !== resolvedTarget.value.environment_id) {
        throw new Error('Resolved capability target mismatch')
      }
      if (revisionConflict.value) {
        profile.value = latest
        profileLoaded.value = true
      } else {
        applyProfileRead(latest)
      }
      if (pending.value) errorMessage.value = '先前操作仍未确认。请核查同一环境的配置；不会重新发送写请求。'
    } catch (error) {
      if (targetUnchanged()) {
        if (capabilityPage) {
          capabilityLoadFailed.value = true
          capabilityLoadError.value = error
        }
        errorMessage.value = errorDisplayText(error, '无法读取完整配置，写操作保持锁定。请检查权限或连接后重新加载。')
      }
    } finally {
      if (targetUnchanged()) {
        busy.value = ''
        if (capabilityPage) capabilityLoading.value = false
      }
    }
  }

  async function onEnvironmentChange(): Promise<void> {
    await reloadProfile()
  }

  async function handleSave(): Promise<void> {
    if (editBlocked.value || busy.value) { ElMessage.warning('请先读取可安全编辑的完整配置。'); return }
    if (!applicationId.value || !environmentId.value) {
      ElMessage.warning('请先选择环境')
      return
    }
    if (!form.name.trim() || form.routes.length === 0) {
      ElMessage.warning('请填写名称并至少配置一条路由')
      return
    }
    const incomplete = form.routes.find(route =>
      ((route.route_kind === 'model_deployment' || route.route_kind === 'audio_driver')
        && (!Number.isInteger(route.deployment_id) || (route.deployment_id ?? 0) <= 0))
      || ((route.route_kind === 'parse_driver' || route.route_kind === 'audio_driver')
        && !route.driver_code)
    )
    if (incomplete) {
      ElMessage.warning(`请补全 ${incomplete.capability_code} 的部署编号与驱动`)
      return
    }
    const targetEnvironment = environmentId.value
    const baseline = JSON.stringify(profile.value)
    const previousRevision = profile.value?.revision ?? 0
    const payload: CapabilitySavePayload = {
      environment_id: targetEnvironment, expected_revision: profile.value?.revision ?? null,
      name: form.name.trim(), template_code: form.template_code || null,
      data_egress_policy: form.data_egress_policy,
      routes: form.routes.map(route => ({ capability_code: route.capability_code, route_kind: route.route_kind,
        deployment_id: usesDeployment(route) ? route.deployment_id ?? null : null,
        driver_code: route.route_kind === 'parse_driver' || route.route_kind === 'audio_driver' ? route.driver_code : null,
        priority: route.priority, config: {} }))
    }
    const current = ++profileGeneration
    const epoch = identityEpoch
    const owner = adminId.value
    const targetApplication = applicationId.value
    if (!targetApplication) return
    const ownsOperation = () => current === profileGeneration && epoch === identityEpoch && owner === adminId.value && targetApplication === applicationId.value && targetEnvironment === environmentId.value
    const flight = ++writeFlightSerial
    writeInFlight.value = true
    busy.value = 'save'
    profileLoaded.value = false
    errorMessage.value = ''
    let phase: 'preflight' | 'write' | 'confirm' = 'preflight'
    try {
      const preflight = await capabilityApi.readProfile(targetEnvironment)
      if (!ownsOperation()) return
      const latest = readProfileResponse(preflight, targetEnvironment)
      if (JSON.stringify(latest) !== baseline) {
        profile.value = latest
        profileLoaded.value = true
        revisionConflict.value = true
        errorMessage.value = '配置已经变化，本地表单仍保留。请比较最新版本后再保存。'
        return
      }
      persistIntent({ version: 1, admin_id: owner, application_id: targetApplication, environment_id: targetEnvironment, action: 'save', payload, previousRevision })
      phase = 'write'
      const response = await capabilityApi.saveProfile(payload)
      if (!ownsOperation()) return
      phase = 'confirm'
      const saved = writtenProfileResponse(response, targetEnvironment)
      if (!sameConfiguration(saved, payload) || saved.revision !== previousRevision + 1) throw new Error('Unexpected save response')
      const operation = pending.value
      if (!operation) throw new Error('原保存记录缺失')
      persistIntent({ ...operation, id: saved.id, revision: saved.revision }, operation)
      const reread = await capabilityApi.readProfile(targetEnvironment)
      if (!ownsOperation()) return
      applyProfileRead(readProfileResponse(reread, targetEnvironment), true)
      if (pending.value) errorMessage.value = '保存结果尚未确认，请重新读取状态；不会重发。'
      else ElMessage.success(`已重新读取并确认配置 revision=${profile.value?.revision}`)
    } catch (error) {
      if (!ownsOperation()) return
      if (phase === 'write' && definitelyRejected(error)) {
        try { clearIntent() } catch { errorMessage.value = '原记录未解除，结果保持待确认；请重新读取原目标'; return }
        // 拒绝已结清后恢复可编辑
        profileLoaded.value = true
        errorMessage.value = writeFailure(error, phase)
        return
      }
      if (phase === 'write' && isRevisionConflict(error)) revisionConflict.value = true
      profileLoaded.value = false
      errorMessage.value = writeFailure(error, phase)
    } finally {
      if (flight === writeFlightSerial) writeInFlight.value = false
      if (ownsOperation()) busy.value = ''
    }
  }

  async function handlePublish(): Promise<void> {
    if (editBlocked.value || busy.value || !applicationId.value || !environmentId.value || !profile.value || profile.value.state !== 'draft') return
    const targetEnvironment = environmentId.value
    const target = profile.value
    const baseline = JSON.stringify(target)
    const payload = profilePayload(target)
    const formSnapshot = JSON.stringify(form)
    const displayedPayload: CapabilitySavePayload = {
      ...payload, name: form.name.trim(), template_code: form.template_code || null,
      data_egress_policy: form.data_egress_policy,
      routes: form.routes.map(route => ({
        capability_code: route.capability_code, route_kind: route.route_kind,
        deployment_id: usesDeployment(route) ? route.deployment_id ?? null : null,
        driver_code: route.route_kind === 'parse_driver' || route.route_kind === 'audio_driver' ? route.driver_code : null,
        priority: route.priority, config: {}
      }))
    }
    if (!sameConfiguration(target, displayedPayload)) {
      ElMessage.warning('表单有未保存的修改。请先保存草稿，再核对已保存修订后启用。')
      return
    }
    const current = ++profileGeneration
    const epoch = identityEpoch
    const owner = adminId.value
    const targetApplication = applicationId.value
    if (!targetApplication) return
    const ownsOperation = () => current === profileGeneration && epoch === identityEpoch && owner === adminId.value && targetApplication === applicationId.value && targetEnvironment === environmentId.value
    const flight = ++writeFlightSerial
    writeInFlight.value = true
    busy.value = 'publish'
    profileLoaded.value = false
    errorMessage.value = ''
    let phase: 'preflight' | 'write' | 'confirm' = 'preflight'
    try {
      try {
        await ElMessageBox.confirm(
          `确认启用应用 ${targetApplication}、环境 ${targetEnvironment} 的能力档 ${target.id} · 修订 ${target.revision}（草稿）？名称：${payload.name}；模板：${payload.template_code || '未指定'}；材料发送策略：${payload.data_egress_policy}；路线：${payload.routes.map(route => `${route.capability_code} / ${route.route_kind} / ${route.deployment_id ?? route.driver_code ?? '未设置'} / 优先顺序 ${route.priority}`).join('、')}。启用仅使用这份已保存配置。`,
          '确认启用能力配置', { type: 'warning', confirmButtonText: '启用此修订', cancelButtonText: '取消' }
        )
      } catch {
        if (ownsOperation()) profileLoaded.value = true
        return
      }
      if (!ownsOperation()) return
      if (JSON.stringify(form) !== formSnapshot || JSON.stringify(profile.value) !== baseline) {
        profileLoaded.value = true
        errorMessage.value = '确认期间配置或表单已变化，尚未启用。请重新核对已保存修订。'
        return
      }
      const preflight = await capabilityApi.readProfile(targetEnvironment)
      if (!ownsOperation()) return
      const latest = readProfileResponse(preflight, targetEnvironment)
      if (JSON.stringify(form) !== formSnapshot || JSON.stringify(profile.value) !== baseline || JSON.stringify(latest) !== baseline) {
        applyProfileRead(latest)
        errorMessage.value = '配置已经变化，已重新读取。请核对后再启用。'
        return
      }
      persistIntent({ version: 1, admin_id: owner, application_id: targetApplication, environment_id: targetEnvironment, action: 'publish', payload, previousRevision: target.revision, id: target.id, revision: target.revision })
      phase = 'write'
      const response = await capabilityApi.publishProfile({ environment_id: targetEnvironment, id: target.id, expected_revision: target.revision })
      if (!ownsOperation()) return
      phase = 'confirm'
      const published = writtenProfileResponse(response, targetEnvironment)
      if (published.id !== target.id || published.revision !== target.revision || published.state !== 'published' || published.status !== 1 || !sameConfiguration(published, payload)) throw new Error('Unexpected publish response')
      const reread = await capabilityApi.readProfile(targetEnvironment)
      if (!ownsOperation()) return
      applyProfileRead(readProfileResponse(reread, targetEnvironment), true)
      if (pending.value) errorMessage.value = '启用结果尚未确认，请重新读取状态；不会重发。'
      else ElMessage.success('已重新读取并确认能力配置档启用')
    } catch (error) {
      if (!ownsOperation()) return
      if (phase === 'write' && definitelyRejected(error)) {
        try { clearIntent() } catch { errorMessage.value = '原记录未解除，结果保持待确认；请重新读取原目标'; return }
        // 拒绝已结清后恢复可编辑
        profileLoaded.value = true
        errorMessage.value = writeFailure(error, phase)
        return
      }
      profileLoaded.value = false
      errorMessage.value = writeFailure(error, phase)
    } finally {
      if (flight === writeFlightSerial) writeInFlight.value = false
      if (ownsOperation()) busy.value = ''
    }
  }

  function returnToMaterialGuide(): void {
    if (busy.value || writeInFlight.value || restoringEntry) return
    const application = route.query.guided_application_id
    const environment = route.query.guided_environment_id
    const code = route.query.guided_profile_code
    const version = route.query.guided_profile_version
    const positive = (value: unknown): value is string => typeof value === 'string'
      && /^[1-9][0-9]*$/.test(value) && Number.isSafeInteger(Number(value))
    if (route.query.configuration_origin !== 'material_guide' || !positive(application) || !positive(environment)
      || ((code !== undefined || version !== undefined) && (typeof code !== 'string'
        || !/^[a-z][a-z0-9_]{0,63}$/.test(code) || typeof version !== 'string'
        || version.length > 32 || !/^\d+\.\d+\.\d+$/.test(version)))) {
      navigationNotice.value = '原引导配置标识不完整，请从材料整理引导重新选择原应用、环境和配置。'
      return
    }
    const query: Record<string, string> = { configuration_return: 'material_guide', application_id: application, environment_id: environment }
    if (typeof code === 'string' && typeof version === 'string') {
      query.material_profile_code = code; query.material_profile_version = version
      const run = route.query.guided_run_id
      if (run !== undefined && (!positive(run))) {
        navigationNotice.value = '原样本标识无效，请从原材料配置或运行历史找回。'
        return
      }
      if (typeof run === 'string') query.material_run_id = run
    }
    // The fixed destination reloads current IAM references and the exact profile.
    void router.push({ path: '/sand-ai/onboarding', query })
  }

  async function loadEntryContext(): Promise<void> {
    const epoch = identityEpoch
    await loadApps()
    if (epoch !== identityEpoch) return
    if (iamListError.value) { settleCapabilityIdle(); return }
    if (journalError.value) { settleCapabilityIdle(); return }
    const operation = pending.value
    if (!operation && (typeof route.query.application_id !== 'string' || typeof route.query.environment_id !== 'string')) return
    let application = operation?.application_id ?? Number(route.query.application_id)
    let environment = operation?.environment_id ?? Number(route.query.environment_id)
    // 入口应用只是提示：无在途操作且仅一个应用时直接采用它，环境沿用自动选择。
    let environmentFromEntry = true
    if (!operation && applications.value.length === 1
        && (!Number.isSafeInteger(application) || application < 1
          || !applications.value.some(item => item.id === application))) {
      application = applications.value[0].id
      environmentFromEntry = false
    }
    const applicationMissing = !Number.isSafeInteger(application) || application < 1
      || !applications.value.some(item => item.id === application)
    if (applicationMissing || (environmentFromEntry && (!Number.isSafeInteger(environment) || environment < 1))) {
      if (applicationMissing && Number.isSafeInteger(application) && application > 0) {
        errorMessage.value = '入口应用已不可用，请重新选择应用和环境。'
      }
      settleCapabilityIdle()
      return
    }
    applicationId.value = application
    await onApplicationChange()
    if (epoch !== identityEpoch) return
    if (iamListError.value) { settleCapabilityIdle(); return }
    // 在途操作与显式入口环境必须命中；提示性入口允许沿用自动选中的唯一环境。
    const expectedEnvironment = environmentFromEntry ? environment : environmentId.value
    // Recheck IAM references; a query string does not confer environment access.
    if (applicationId.value !== application || !environments.value.some(item => item.id === expectedEnvironment)) {
      errorMessage.value = '入口环境已不可用或不属于该应用，请重新选择。'
      settleCapabilityIdle()
      return
    }
    environmentId.value = expectedEnvironment
    if (operation) {
      form.name = operation.payload.name
      form.template_code = operation.payload.template_code ?? ''
      form.data_egress_policy = operation.payload.data_egress_policy ?? 'local_only'
      form.routes = toDraftRoutes(operation.payload.routes)
    }
    await reloadProfile()
  }

  watch([applicationId, environmentId], () => {
    profileGeneration++
    profileLoaded.value = false
    profile.value = null
    revisionConflict.value = false
    busy.value = ''
    resolvedTarget.value = null
    cardDrafts.clear()
    cardDeploymentCache.value = {}
    cardDeploymentLoading.value = {}
    cardDeploymentRequested.value = {}
    cardDeploymentCatalog.value = {}
    for (const key of Object.keys(cardDeploymentSeq)) delete cardDeploymentSeq[key]
    clearCardDeploymentRetries()
    capabilityLoadFailed.value = false
    capabilityLoadError.value = null
  }, { flush: 'sync' })
  watch([applicationId, environmentId], () => {
    if (
      restoringEntry ||
      !applicationId.value ||
      !environmentId.value ||
      !applications.value.some((item) => item.id === applicationId.value) ||
      !environments.value.some((item) => item.id === environmentId.value)
    )
      return
    const query = { ...route.query }
    if (
      query.application_id !== String(applicationId.value) ||
      query.environment_id !== String(environmentId.value)
    ) {
      delete query.material_profile_code
      delete query.material_profile_version
      delete query.material_run_id
    }
    query.application_id = String(applicationId.value)
    query.environment_id = String(environmentId.value)
    void router.replace({ query })
  })
  // 配置档或能力目录变化时重建能力卡片；草稿选择由 buildCapabilityCards 内部保留。
  watch([profile, metadata], () => {
    buildCapabilityCards()
  })

  onBeforeUnmount(() => {
    identityEpoch++; profileGeneration++; applicationGeneration++; environmentGeneration++; deploymentGeneration++
    clearCardDeploymentRetries()
    form.name = 'default-capability'; form.template_code = ''; form.data_egress_policy = 'local_only'; form.routes = []
    profile.value = null; deploymentOptions.value = []
    capabilityCards.value = []
  })

  watch([adminId, () => userStore.accessToken], () => {
    identityEpoch++; profileGeneration++; applicationGeneration++; environmentGeneration++; deploymentGeneration++
    busy.value = ''; applicationId.value = undefined; environmentId.value = undefined
    applications.value = []; environments.value = []; profile.value = null; profileLoaded.value = false
    metadata.value = null; deploymentOptions.value = []; deploymentsLoading.value = false
    loadingApps.value = false; loadingEnvs.value = false; iamListError.value = ''; revisionConflict.value = false
    form.name = 'default-capability'; form.template_code = ''; form.data_egress_policy = 'local_only'; form.routes = []
    errorMessage.value = ''
    clearCapabilityCards()
    restoreIntent()
    void loadMetadata()
    void loadEntryContext()
  }, { flush: 'sync' })
  onMounted(() => {
    restoreIntent()
    void loadEntryContext().finally(() => {
      restoringEntry = false
      entryInitialized = true
    })
    void loadMetadata()
  })
  onActivated(() => {
    if (!entryInitialized || route.path !== '/sand-ai/capability' || writeInFlight.value
      || route.query.configuration_origin !== 'material_guide') return
    restoringEntry = true
    void loadEntryContext().finally(() => {
      restoringEntry = false
    })
  })
</script>

<style scoped lang="scss">
  .cap-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 16px;
  }

  .cap-title {
    margin: 0;
    font-size: 18px;
    font-weight: 600;
  }

  .cap-desc {
    margin: 4px 0 0;
    font-size: 13px;
    color: var(--el-text-color-secondary);
    max-width: 760px;
  }

  .cap-form {
    margin-bottom: 8px;
  }

  .cap-alert {
    margin-bottom: 12px;
  }

  .cap-recover > summary {
    cursor: pointer;
    font-size: 13px;
    color: var(--el-color-warning-dark-2);
    list-style: none;
  }

  .cap-recover > summary::before {
    content: '▸ ';
  }

  .cap-recover[open] > summary::before {
    content: '▾ ';
  }

  .cap-routes-toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin: 12px 0;
  }

  .cap-route-actions {
    min-width: 0;
    max-width: 100%;
  }

  .cap-section-title {
    flex-shrink: 0;
    margin: 0;
    font-size: 15px;
    font-weight: 600;
  }

  .cap-actions {
    margin-top: 16px;
  }

  .cap-muted {
    color: var(--el-text-color-secondary);
    font-size: 13px;
  }

  .cap-number {
    width: 100%;
  }

  .cap-deployment {
    width: 100%;
  }

  .cap-app-empty {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 8px 0;
    font-size: 13px;
    color: var(--el-text-color-secondary);
  }

  .cap-hint {
    margin: 4px 0 0;
    font-size: 13px;
    color: var(--el-text-color-secondary);
  }

  .cap-target-select {
    width: 280px;
    max-width: 100%;
  }

  .cap-cards {
    display: flex;
    flex-direction: column;
    gap: 24px;
    margin-top: 8px;
  }

  .cap-card {
    padding: 24px;
    border: 1px solid var(--el-border-color-lighter);
    border-radius: 8px;
  }

  .cap-card__head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 16px;
  }

  .cap-card__title {
    display: flex;
    flex-direction: column;
    gap: 4px;
    min-width: 0;
  }

  .cap-card__name {
    margin: 0;
    font-size: 16px;
    font-weight: 600;
  }

  .cap-card__usage {
    font-size: 13px;
    color: var(--el-text-color-secondary);
  }

  .cap-card__form {
    margin-bottom: 4px;
  }

  .cap-card__select {
    width: 100%;
    max-width: 360px;
  }

  .cap-card__note {
    margin: 4px 0 12px;
    font-size: 13px;
    color: var(--el-text-color-secondary);
  }

  .cap-card__foot {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 8px;
    margin-top: 8px;
  }

  .cap-card__foot-gap {
    flex: 1;
  }

  .cap-cards-foot {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-top: 24px;
    padding-top: 16px;
    border-top: 1px solid var(--el-border-color-lighter);
  }

  .cap-card-skeleton {
    margin-bottom: 24px;
    border: 1px solid var(--el-border-color-lighter);
    border-radius: 8px;
  }

  .cap-card-skeleton:last-child {
    margin-bottom: 0;
  }
</style>
