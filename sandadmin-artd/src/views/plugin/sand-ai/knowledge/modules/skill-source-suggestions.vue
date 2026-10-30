<template>
  <ElCollapse>
    <ElCollapseItem title="从获授权来源提炼方法建议" name="suggestions">
      <p>先选择固定知识版本并填写消费后端提供的资料复用确认。生成单独计费，建议必须人工采用为草稿，再进行案例评测与发布。</p>
      <ElForm label-width="120px" @submit.prevent>
        <ElFormItem label="固定知识版本">
          <ElSelect v-model="sourceId" :disabled="busy || blocked" placeholder="选择当前范围开放的版本">
            <ElOption v-for="item in sources" :key="item.knowledge_base_version_id"
              :value="item.knowledge_base_version_id" :label="`${item.name} · v${item.version}`" />
          </ElSelect>
        </ElFormItem>
        <ElFormItem label="提炼目的">
          <ElInput v-model="query" type="textarea" maxlength="500" show-word-limit :disabled="busy || blocked" />
        </ElFormItem>
        <ElFormItem label="聊天模型代码">
          <ElInput v-model="model" placeholder="管理员已发布的正式模型代码" :disabled="busy || blocked" />
        </ElFormItem>
        <ElFormItem label="复用确认引用">
          <ElInput v-model="reuseDeclaration" type="textarea"
            placeholder="消费后端记录的资料许可、脱敏与复用确认引用；不填写API Key" :disabled="busy || blocked" />
        </ElFormItem>
        <ElFormItem>
          <ElButton :disabled="!ready || busy || blocked || !validInput" @click="generate">确认提炼并计费</ElButton>
        </ElFormItem>
      </ElForm>
      <h4>原请求找回</h4>
      <p>换短凭证后先读取同一消费范围，再用原请求键只读找回。运行中或结果未知不会自动再次生成。</p>
      <ElInput :model-value="recoveryKey" :disabled="busy" placeholder="原来源提炼 Idempotency-Key"
        @update:model-value="emit('update:recoveryKey', $event)" />
      <ElButton :disabled="!ready || busy || !recoveryKey.trim()" @click="emit('recover')">只读原请求</ElButton>
      <template v-if="result">
        <ElAlert :closable="false" :type="result.state === 'failed' ? 'error' : 'info'"
          :title="statusLabel(result.state)" />
        <template v-if="result.state === 'content_purged'">
          <p>内容清理时间：{{ result.content_purged_at }}；账务：{{ accountingLabel(result.accounting_state) }}</p>
          <p>剩余私有内容项 {{ result.remaining_private_fields }}；剩余回执密文 {{ result.remaining_receipt_ciphertexts }}。费用与审计保留。</p>
        </template>
        <template v-else>
          <p v-if="result.error_code">错误：{{ result.error_code }}。保留原调用及费用，不换键重新生成。</p>
          <ElCheckboxGroup v-model="selected" :disabled="busy || blocked || result.state !== 'pending_review'">
            <div v-for="method in result.methods" :key="method.suggestion_no" class="method-suggestion">
              <ElCheckbox :value="method.suggestion_no">{{ method.name }}</ElCheckbox>
              <p>{{ method.method }}</p>
              <p>适用条件：{{ method.trigger }}</p>
              <p>禁止事项：{{ method.prohibitions }}</p>
              <p>引用来源块：{{ method.source_block_ids.join('、') }}</p>
            </div>
          </ElCheckboxGroup>
          <ElTable :data="result.citations" empty-text="暂无可读来源">
            <ElTableColumn label="来源块" prop="source_block_id" />
            <ElTableColumn label="文件" prop="file_id" />
            <ElTableColumn label="位置">
              <template #default="{ row }">{{ row.locator_type }} · {{ JSON.stringify(row.locator) }}</template>
            </ElTableColumn>
            <ElTableColumn label="摘录" prop="excerpt" min-width="240" />
          </ElTable>
          <ElSpace wrap>
            <ElButton :disabled="!ready || busy || blocked || !canAdopt" @click="prepare">
              将所选建议填入下方草稿表单
            </ElButton>
            <ElButton :disabled="!ready || busy || blocked || !canAdopt" @click="emit('adopt', [...selected])">
              确认采用下方草稿
            </ElButton>
            <ElButton :disabled="!ready || busy || result.invocation_id < 1" @click="emit('cost')">
              读取本次费用
            </ElButton>
            <ElButton type="danger" :disabled="!ready || busy || blocked" @click="emit('purge')">
              清理建议内容
            </ElButton>
          </ElSpace>
          <p>采用只保存草稿，不判定案例、不发布。请检查下方名称、指令、输入与输出结构，并保持原固定知识版本。</p>
        </template>
      </template>
    </ElCollapseItem>
  </ElCollapse>
</template>

<script setup lang="ts">
  import { computed, ref, watch } from 'vue'
  import type { SkillSource } from '../../api/skill'
  import type { SkillMethodSuggestion, SkillSuggestionInput, SkillSuggestionRead } from '../../api/consumerSkill'

  const props = defineProps<{
    sources: SkillSource[]
    ready: boolean
    busy: boolean
    blocked: boolean
    result: SkillSuggestionRead | null
    recoveryKey: string
    resetEpoch: number
  }>()
  const emit = defineEmits<{
    generate: [input: SkillSuggestionInput]
    recover: []
    adopt: [selected: number[]]
    prepare: [methods: SkillMethodSuggestion[], sourceId: number | undefined]
    purge: []
    cost: []
    'update:recoveryKey': [key: string]
  }>()
  const sourceId = ref<number>()
  const query = ref('')
  const model = ref('')
  const reuseDeclaration = ref('')
  const selected = ref<number[]>([])
  const source = computed(() => props.sources.find(item => item.knowledge_base_version_id === sourceId.value))
  const validInput = computed(() => source.value && query.value.trim() && Array.from(query.value).length <= 500
    && /^[a-zA-Z0-9][a-zA-Z0-9._/-]{0,127}$/.test(model.value)
    && reuseDeclaration.value.trim() && new TextEncoder().encode(reuseDeclaration.value).length <= 1000)
  const canAdopt = computed(() => props.result?.state === 'pending_review' && selected.value.length > 0)
  function generate(): void {
    const fixed = source.value
    if (!fixed || !validInput.value) return
    emit('generate', {
      knowledge_base_version_id: fixed.knowledge_base_version_id,
      snapshot_sha256: fixed.snapshot_sha256,
      query: query.value,
      model: model.value,
      reuse_declaration: reuseDeclaration.value
    })
  }
  function prepare(): void {
    const result = props.result
    if (!result || result.state !== 'pending_review') return
    emit('prepare', result.methods.filter(item => selected.value.includes(item.suggestion_no)), sourceId.value)
  }
  function statusLabel(state: string): string {
    return ({ generating: '原请求正在生成，尚无人工可采用结果', pending_review: '建议待人工审阅',
      adopted: '建议已采用为草稿，仍须案例评测和发布', failed: '原请求失败，费用事实保留',
      content_purged: '建议内容已清理，不能再采用' } as Record<string, string>)[state] ?? state
  }
  function accountingLabel(state: string): string {
    return ({ settled: '已按原冻结价格计价，未表示账单对账', pending: '账务待恢复',
      not_invoked: '没有关联模型调用', unknown: '用量或金额未知' } as Record<string, string>)[state] ?? state
  }
  watch(() => props.result, () => { selected.value = [] })
  watch(() => props.resetEpoch, () => {
    selected.value = []
    sourceId.value = undefined
    query.value = ''
    model.value = ''
    reuseDeclaration.value = ''
  })
</script>

<style scoped>
  .method-suggestion { margin-block: 12px; }
  .method-suggestion p { margin-block: 4px; }
</style>
