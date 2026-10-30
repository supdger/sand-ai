<template>
  <p v-if="sourceLocation" class="muted source-location">来源位置：{{ sourceLocation }}</p>
  <div v-if="cells" class="table-source">
    <p class="muted">表格来源按原文件单元格坐标展示；合并单元格显示其起始坐标。</p>
    <div class="table-source__scroll">
      <table>
        <thead>
          <tr><th scope="col">单元格</th><th scope="col">原文</th></tr>
        </thead>
        <tbody>
          <tr v-for="cell in cells" :key="cell.coordinate">
            <th scope="row">{{ cell.coordinate }}</th>
            <td>{{ cell.content }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
  <pre v-else class="source-text">{{ source.content }}</pre>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import type { SandAiWorkflowSource } from '../api/types'

type TableCell = { coordinate: string; content: string; column: number }

const props = defineProps<{ source: Pick<SandAiWorkflowSource, 'content' | 'locator_type' | 'locator'> }>()

function locatorInteger(value: unknown, minimum = 1): number | null {
  return typeof value === 'number' && Number.isSafeInteger(value) && value >= minimum ? value : null
}

function locatorCell(value: unknown, row: number | null): string | null {
  if (typeof value !== 'string' || row === null) return null
  const match = /^([A-Z]{1,3})([1-9][0-9]{0,6})$/.exec(value)
  if (!match || Number(match[2]) !== row || row > 1048576) return null
  let column = 0
  for (const letter of match[1]) column = column * 26 + letter.charCodeAt(0) - 64
  return column <= 16384 ? value : null
}

const sourceLocation = computed<string | null>(() => {
  if (props.source.locator_type !== 'paragraph' && props.source.locator_type !== 'table_row') return null
  const locator = props.source.locator
  const parts: string[] = []
  const partIndex = locatorInteger(locator.part_index)
  const referenceId = locatorInteger(locator.reference_id, 0)
  switch (locator.document_part) {
    case undefined:
      break
    case 'header':
      parts.push(partIndex === null ? '页眉' : `页眉部件 ${partIndex}`)
      break
    case 'footer':
      parts.push(partIndex === null ? '页脚' : `页脚部件 ${partIndex}`)
      break
    case 'footnote':
      parts.push(referenceId === null ? '脚注' : `脚注引用号 ${referenceId}`)
      break
    case 'endnote':
      parts.push(referenceId === null ? '尾注' : `尾注引用号 ${referenceId}`)
      break
    default:
      return null
  }
  const textbox = locatorInteger(locator.textbox)
  const anchorTable = locatorInteger(locator.anchor_table)
  const anchorRow = locatorInteger(locator.anchor_row)
  const anchorCell = locatorCell(locator.anchor_cell, anchorRow)
  const anchorParagraph = locatorInteger(locator.anchor_paragraph)
  if (textbox !== null) parts.push(`文本框 ${textbox}`)
  if (anchorTable !== null) parts.push(`所属表格 ${anchorTable}`)
  if (anchorRow !== null) parts.push(`所属行 ${anchorRow}`)
  if (anchorCell !== null) parts.push(`所属单元格 ${anchorCell}`)
  if (anchorParagraph !== null) {
    parts.push(anchorTable === null ? `所属段落 ${anchorParagraph}` : `单元格内段落 ${anchorParagraph}`)
  }
  if (props.source.locator_type === 'paragraph') {
    const paragraph = locatorInteger(locator.paragraph)
    if (paragraph !== null) parts.push(`段落 ${paragraph}`)
  } else {
    const table = locatorInteger(locator.table)
    const row = locatorInteger(locator.row)
    if (table !== null) parts.push(`表格 ${table}`)
    if (row !== null) parts.push(`第 ${row} 行`)
  }
  const part = locatorInteger(locator.part)
  if (part !== null) parts.push(`分片 ${part}`)
  return parts.length > 0 ? parts.join(' · ') : null
})

const cells = computed<TableCell[] | null>(() => {
  if (props.source.locator_type !== 'table_row') return null
  let parsed: unknown
  try {
    parsed = JSON.parse(props.source.content)
  } catch {
    return null
  }
  if (typeof parsed !== 'object' || parsed === null || Array.isArray(parsed)) return null
  const entries = Object.entries(parsed)
  if (entries.length === 0) return null
  const row = props.source.locator.row
  if (typeof row !== 'number' || !Number.isSafeInteger(row) || row < 1) return null
  const result: TableCell[] = []
  for (const [coordinate, content] of entries) {
    const match = /^([A-Z]{1,3})([1-9][0-9]{0,6})$/.exec(coordinate)
    if (!match || typeof content !== 'string' || Number(match[2]) !== row) {
      return null
    }
    let column = 0
    for (const letter of match[1]) column = column * 26 + letter.charCodeAt(0) - 64
    if (column > 16384) return null
    result.push({ coordinate, content, column })
  }
  return result.sort((left, right) => left.column - right.column)
})
</script>

<style scoped>
.source-location { margin: 0 0 8px; }
.table-source { display: grid; gap: 8px; }
.table-source p { margin: 0; }
.table-source__scroll { max-height: 360px; overflow: auto; }
.table-source table { width: 100%; border-collapse: collapse; }
.table-source th, .table-source td { padding: 8px; border: 1px solid var(--el-border-color); text-align: left; vertical-align: top; white-space: pre-wrap; overflow-wrap: anywhere; }
.table-source th { width: 100px; }
.source-text { max-height: 360px; overflow: auto; white-space: pre-wrap; overflow-wrap: anywhere; }
</style>
