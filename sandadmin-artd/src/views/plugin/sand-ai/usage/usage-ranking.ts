import type { SandAiUsageSummaryRow } from '../api/types'

export type MeteredRankRow = {
  rank: number
  value: string | null
  currency: string
  total_events: number
  metered_amount: string
}

type RankableRow = SandAiUsageSummaryRow & { currency: string; metered_amount: string }

function isRankable(row: SandAiUsageSummaryRow): row is RankableRow {
  return (
    row.value !== null &&
    row.value !== 'unattributed' &&
    row.currency !== null &&
    row.metered_amount !== null &&
    /^[A-Z]{3}$/.test(row.currency) &&
    /^-?\d+(?:\.\d+)?$/.test(row.metered_amount) &&
    row.total_events > 0 &&
    row.measured_events === row.total_events &&
    row.priced_events === row.total_events
  )
}

function compareAmount(left: string, right: string): number {
  const parts = (value: string) => {
    const negative = value.startsWith('-')
    const [whole, fraction = ''] = (negative ? value.slice(1) : value).split('.')
    return { negative, whole: whole.replace(/^0+/, '') || '0', fraction }
  }
  const a = parts(left)
  const b = parts(right)
  if (a.negative !== b.negative) return a.negative ? -1 : 1
  const direction = a.negative ? -1 : 1
  const wholeOrder =
    a.whole.length - b.whole.length || (a.whole < b.whole ? -1 : a.whole > b.whole ? 1 : 0)
  if (wholeOrder !== 0) return wholeOrder * direction
  const length = Math.max(a.fraction.length, b.fraction.length)
  const leftFraction = a.fraction.padEnd(length, '0')
  const rightFraction = b.fraction.padEnd(length, '0')
  return (leftFraction < rightFraction ? -1 : leftFraction > rightFraction ? 1 : 0) * direction
}

export function rankMeteredRows(
  rows: SandAiUsageSummaryRow[],
  truncated: boolean,
  order: 'descending' | 'ascending' = 'descending'
): MeteredRankRow[] {
  if (truncated) return []
  const incompleteValues = new Set<string | null>()
  for (const row of rows) {
    if (!isRankable(row)) incompleteValues.add(row.value)
  }
  const byCurrency = new Map<string, RankableRow[]>()
  for (const row of rows) {
    if (!isRankable(row) || incompleteValues.has(row.value)) continue
    const group = byCurrency.get(row.currency) ?? []
    group.push(row)
    byCurrency.set(row.currency, group)
  }

  const ranked: MeteredRankRow[] = []
  for (const currency of [...byCurrency.keys()].sort()) {
    const group = byCurrency.get(currency) ?? []
    group.sort((a, b) => {
      const amountOrder = order === 'descending'
        ? compareAmount(b.metered_amount, a.metered_amount)
        : compareAmount(a.metered_amount, b.metered_amount)
      if (amountOrder !== 0) return amountOrder
      const left = a.value ?? ''
      const right = b.value ?? ''
      return left < right ? -1 : left > right ? 1 : 0
    })
    let previousAmount: string | null = null
    let rank = 0
    for (const [index, row] of group.entries()) {
      const amount = row.metered_amount
      if (previousAmount === null || compareAmount(previousAmount, amount) !== 0) rank = index + 1
      ranked.push({
        rank,
        value: row.value,
        currency,
        total_events: row.total_events,
        metered_amount: amount
      })
      previousAmount = amount
    }
  }
  return ranked
}
