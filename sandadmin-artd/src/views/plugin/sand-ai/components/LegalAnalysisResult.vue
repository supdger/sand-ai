<template>
  <section class="legal-result">
    <ElAlert title="AI 分析成果须人工复核；来源效力为消费者声明，未经官方核验" type="warning" :closable="false" />
    <ElAlert v-if="!result" title="成果字段类型或复核状态不符合契约，无法展示，请检查运行记录" type="error" :closable="false" />
    <template v-else>
      <h3>{{ result.profile ? legalModes[result.profile.code] : '法律分析' }}成果</h3>
      <ElDescriptions :column="1" border>
        <ElDescriptionsItem label="冻结配置版本">{{ result.profile?.version ?? '未返回配置版本' }}</ElDescriptionsItem>
        <ElDescriptionsItem label="成果状态">草稿 · 待人工复核</ElDescriptionsItem>
        <ElDescriptionsItem label="复核事项">风险 {{ result.risks.length }} 项 · 待核实 {{ result.unverified_items.length }} 项 · 效力提示 {{ result.source_warnings.length }} 项</ElDescriptionsItem>
      </ElDescriptions>
      <p class="hint">条目为空仅表示本次未返回相应内容，仍需结合原始来源进行复核。</p>
      <h4>结论与依据</h4>
      <p v-if="!result.findings.length" class="empty">未返回结论与依据</p>
      <article v-for="(finding, index) in result.findings" :key="index">
        <h5>结论 {{ index + 1 }}</h5><p>{{ finding.conclusion || '未返回结论正文' }}</p>
        <p><span>支持来源：</span><span v-if="!finding.supporting_source_block_ids.length">未返回来源引用</span><ElButton v-for="id in finding.supporting_source_block_ids" :key="`support-${id}`" link type="primary" @click="emit('open-source', id)">查看来源 #{{ id }}</ElButton></p>
        <p v-if="finding.contrary_source_block_ids.length"><span>相反来源：</span><ElButton v-for="id in finding.contrary_source_block_ids" :key="`contrary-${id}`" link type="warning" @click="emit('open-source', id)">查看来源 #{{ id }}</ElButton></p>
      </article>
      <template v-for="section in [{ title: '风险', items: result.risks }, { title: '待核实事项', items: result.unverified_items }]" :key="section.title">
        <h4>{{ section.title }}</h4><p v-if="!section.items.length" class="empty">未返回{{ section.title }}条目</p>
        <article v-for="(entry, index) in section.items" :key="index"><p>{{ entry.description || '未返回说明' }}</p><span v-if="!entry.source_block_ids.length">未返回来源引用</span><ElButton v-for="id in entry.source_block_ids" :key="id" link type="primary" @click="emit('open-source', id)">查看来源 #{{ id }}</ElButton></article>
      </template>
      <h4>{{ result.draft ? draftLabels[result.draft.kind] : '分析草稿' }}</h4>
      <p class="content">{{ result.draft?.content || '未生成草稿' }}</p>
      <ElButton v-for="id in result.draft?.source_block_ids ?? []" :key="id" link type="primary" @click="emit('open-source', id)">查看来源 #{{ id }}</ElButton>
      <template v-if="result.complaint">
        <h4>起诉状章节与来源</h4><p>输入版本 {{ result.complaint.input_version }} · 模板版本 {{ result.complaint.template_version }}</p>
        <article v-for="section in result.complaint.sections" :key="section.key"><h5>{{ section.title }}</h5><p>{{ section.content }}</p><ElButton v-for="id in section.source_block_ids" :key="id" link type="primary" @click="emit('open-source', id)">查看来源 #{{ id }}</ElButton></article>
        <h4>要素与引用矩阵</h4><article v-for="(entry, index) in result.complaint.element_matrix" :key="index"><h5>{{ entry.element }}</h5><p>事实引用：{{ entry.fact_refs.join('、') }} · 证据引用：{{ entry.evidence_refs.join('、') }}</p><ElButton v-for="id in entry.source_block_ids" :key="id" link type="primary" @click="emit('open-source', id)">查看来源 #{{ id }}</ElButton></article>
        <template v-for="section in [{ title: '可能抗辩', items: result.complaint.possible_defenses }, { title: '缺失证据', items: result.complaint.missing_evidence }, { title: '待律师决定', items: result.complaint.pending_decisions }, { title: '法规、司法解释与案例缺口', items: result.complaint.authority_gaps }]" :key="section.title"><h4>{{ section.title }}</h4><p v-if="!section.items.length">未列出条目，请继续人工核对。</p><article v-for="(entry, index) in section.items" :key="index"><p>{{ entry.description }}</p><ElButton v-for="id in entry.source_block_ids" :key="id" link type="primary" @click="emit('open-source', id)">查看来源 #{{ id }}</ElButton></article></template>
      </template>
      <h4>冻结的法律来源版本</h4>
      <p v-if="!result.source_versions.length" class="empty">未返回法律来源版本</p>
      <article v-for="entry in result.source_versions" :key="entry.source_ref">
        <p>{{ entry.source_ref }} · 来源版本 {{ entry.source_version }} · {{ effectLabels[entry.declared_effect_status] }} · 消费者声明</p>
        <p>文件 {{ entry.file_id ? `#${entry.file_id}` : '未返回' }} · 解析 {{ entry.parse_id ? `#${entry.parse_id}` : '未返回' }} · 文件版本 {{ entry.version_ref || '未返回' }}</p>
        <span v-if="!entry.source_block_ids.length">未返回来源块</span><ElButton v-for="id in entry.source_block_ids" :key="id" link type="primary" @click="emit('open-source', id)">核对来源位置 #{{ id }}</ElButton>
      </article>
      <h4>效力风险提示</h4>
      <p v-if="!result.source_warnings.length" class="empty">未返回效力风险提示，来源效力仍需人工核实</p>
      <article v-for="entry in result.source_warnings" :key="`${entry.source_ref}:${entry.warning_code}`"><p>{{ entry.source_ref }} · 来源版本 {{ entry.source_version }}：{{ warningLabel(entry.warning_code) }}</p><ElButton v-for="id in entry.source_block_ids" :key="id" link type="warning" @click="emit('open-source', id)">查看来源 #{{ id }}</ElButton></article>
      <h4>采用的事实版本</h4>
      <p v-if="!result.fact_versions.length" class="empty">未返回已确认事实版本</p>
      <article v-for="entry in result.fact_versions" :key="entry.fact_ref"><p>{{ entry.fact_ref }} · 事实版本 {{ entry.fact_version }}</p><ElButton v-for="id in entry.source_block_ids" :key="id" link type="primary" @click="emit('open-source', id)">查看来源 #{{ id }}</ElButton></article>
      <p class="hint">点击来源将按当前应用授权重新读取原运行的来源正文与定位信息。</p>
    </template>
  </section>
</template>
<script setup lang="ts">
import { computed } from 'vue'
import { effectLabels, legalModes, type EffectStatus, type LegalMode } from '../api/legalAnalysis'
const props = defineProps<{ payload: unknown }>()
const emit = defineEmits<{ 'open-source': [id: number] }>()
const draftLabels = {
  research_memo: '法律研究备忘录草稿',
  litigation_outline: '诉讼准备提纲草稿',
  judgment_analysis_memo: '裁判分析备忘录草稿',
  complaint_draft: '完整民事起诉状草稿'
} as const
type DraftKind = keyof typeof draftLabels
type Finding = { conclusion: string; supporting_source_block_ids: number[]; contrary_source_block_ids: number[] }
type Item = { description: string; source_block_ids: number[] }
type Source = { source_ref: string; source_version: string; authority_state: 'consumer_declared'; declared_effect_status: EffectStatus; source_block_ids: number[]; file_id?: number; parse_id?: number; version_ref?: string }
type Warning = { source_ref: string; source_version: string; warning_code: string; source_block_ids: number[] }
type Fact = { fact_ref: string; fact_version: string; source_block_ids: number[] }
type Draft = { kind: DraftKind; content: string; source_block_ids: number[] }
type Profile = { code: LegalMode; version: string }
type ComplaintSection = { key: string; title: string; content: string; source_block_ids: number[] }
type ComplaintElement = { element: string; fact_refs: string[]; evidence_refs: string[]; source_block_ids: number[] }
type Complaint = { document_type: 'civil_complaint'; input_version: string; template_version: string; sections: ComplaintSection[]; element_matrix: ComplaintElement[]; possible_defenses: Item[]; missing_evidence: Item[]; pending_decisions: Item[]; authority_gaps: Item[] }
type Result = { complaint: Complaint | null; profile: Profile | null; findings: Finding[]; risks: Item[]; unverified_items: Item[]; draft: Draft | null; source_versions: Source[]; source_warnings: Warning[]; fact_versions: Fact[] }
function record(value: unknown): value is Record<string, unknown> { return typeof value === 'object' && value !== null && !Array.isArray(value) }
function positiveId(value: unknown): value is number { return typeof value === 'number' && Number.isSafeInteger(value) && value > 0 }
function ids(value: unknown): value is number[] { return Array.isArray(value) && value.every(positiveId) }
function effect(value: unknown): value is EffectStatus { return typeof value === 'string' && Object.hasOwn(effectLabels, value) }
function finding(value: unknown): value is Finding { return record(value) && typeof value.conclusion === 'string' && ids(value.supporting_source_block_ids) && ids(value.contrary_source_block_ids) }
function item(value: unknown): value is Item { return record(value) && typeof value.description === 'string' && ids(value.source_block_ids) }
function source(value: unknown): value is Source {
  return record(value) && typeof value.source_ref === 'string' && typeof value.source_version === 'string' && value.authority_state === 'consumer_declared' && effect(value.declared_effect_status) && ids(value.source_block_ids)
    && (value.file_id === undefined || positiveId(value.file_id)) && (value.parse_id === undefined || positiveId(value.parse_id)) && (value.version_ref === undefined || typeof value.version_ref === 'string')
}
function warning(value: unknown): value is Warning { return record(value) && typeof value.source_ref === 'string' && typeof value.source_version === 'string' && typeof value.warning_code === 'string' && ids(value.source_block_ids) }
function fact(value: unknown): value is Fact { return record(value) && typeof value.fact_ref === 'string' && typeof value.fact_version === 'string' && ids(value.source_block_ids) }
function draft(value: unknown): value is Draft { return record(value) && typeof value.kind === 'string' && Object.hasOwn(draftLabels, value.kind) && typeof value.content === 'string' && ids(value.source_block_ids) }
function profile(value: unknown): value is Profile { return record(value) && typeof value.code === 'string' && Object.hasOwn(legalModes, value.code) && typeof value.version === 'string' }
function list<T>(value: unknown, guard: (entry: unknown) => entry is T): T[] | null {
  if (value === undefined) return []
  return Array.isArray(value) && value.every(guard) ? value : null
}
function textList(value: unknown): value is string[] { return Array.isArray(value) && value.every(v => typeof v === 'string') }
function complaintSection(value: unknown): value is ComplaintSection { return record(value) && typeof value.key === 'string' && ['parties', 'claims', 'facts_reasons', 'legal_basis', 'evidence', 'closing'].includes(value.key) && typeof value.title === 'string' && typeof value.content === 'string' && ids(value.source_block_ids) }
function complaintElement(value: unknown): value is ComplaintElement { return record(value) && typeof value.element === 'string' && textList(value.fact_refs) && textList(value.evidence_refs) && ids(value.source_block_ids) }
function complaint(value: unknown): value is Complaint { return record(value) && value.document_type === 'civil_complaint' && typeof value.input_version === 'string' && typeof value.template_version === 'string' && Array.isArray(value.sections) && value.sections.length === 6 && value.sections.every(complaintSection) && new Set(value.sections.map(s => s.key)).size === 6 && Array.isArray(value.element_matrix) && value.element_matrix.every(complaintElement) && Array.isArray(value.possible_defenses) && value.possible_defenses.every(item) && Array.isArray(value.missing_evidence) && value.missing_evidence.every(item) && Array.isArray(value.pending_decisions) && value.pending_decisions.every(item) && Array.isArray(value.authority_gaps) && value.authority_gaps.every(item) }
function read(value: unknown): Result | null {
  if (!record(value) || value.review_required !== true) return null
  const findings = list(value.findings, finding)
  const risks = list(value.risks, item)
  const unverified = list(value.unverified_items, item)
  const sources = list(value.source_versions, source)
  const warnings = list(value.source_warnings, warning)
  const facts = list(value.fact_versions, fact)
  if (!findings || !risks || !unverified || !sources || !warnings || !facts
    || (value.complaint !== undefined && !complaint(value.complaint))
    || (value.draft !== undefined && !draft(value.draft)) || (value.legal_profile !== undefined && !profile(value.legal_profile))) return null
  return {
    complaint: complaint(value.complaint) ? value.complaint : null,
    profile: profile(value.legal_profile) ? value.legal_profile : null,
    findings, risks, unverified_items: unverified,
    draft: draft(value.draft) ? value.draft : null,
    source_versions: sources, source_warnings: warnings, fact_versions: facts
  }
}
const result = computed(() => read(props.payload))
function warningLabel(code: string): string { return ({ declared_amendment_check_required: '声明已修订，请核对适用版本', declared_repealed_source: '声明已废止，请核对适用时点', effect_status_unverified: '效力尚未核实' } as Record<string, string>)[code] ?? '未识别的效力提示，请核对来源' }
</script>
<style scoped>
.legal-result h3, .legal-result h4 { margin: 16px 0 8px; }
.legal-result h5 { margin: 0; font: inherit; font-weight: 600; }
article { padding: 8px 0; border-bottom: 1px solid var(--el-border-color-lighter); }
.content, article p { white-space: pre-wrap; overflow-wrap: anywhere; }
.hint, .empty { color: var(--el-text-color-secondary); }
</style>
