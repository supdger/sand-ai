import { reactive } from 'vue'
import type { SandAiTask } from './types'

export type MaterialTaskAction = { kind: 'cancel' | 'retry' | 'receipt' | 'unknown'; attempt: number; key: string | null }
// Shared by the sample runner and task page; no credential, material text or result is stored.
const actions = reactive(new Map<string, MaterialTaskAction>())
const terminal = new Set(['succeeded', 'failed', 'canceled'])
// Live requests outlast private view resets and component navigation; tokens contain no task data.
const flights = reactive(new Set<symbol>())

export function beginMaterialTaskActionFlight(): symbol {
  const token = Symbol('material-task-action')
  flights.add(token)
  return token
}

export function finishMaterialTaskActionFlight(token: symbol): void {
  flights.delete(token)
}

export function materialTaskActionInFlight(): boolean {
  return flights.size > 0
}

export function pendingMaterialTaskActionIds(owner: number | undefined): number[] {
  const prefix = `sand-ai-task-material-action:${owner}:`
  const ids = new Set<number>()
  for (const key of actions.keys()) {
    if (!key.startsWith(prefix)) continue
    const id = Number(key.slice(prefix.length).split(':')[0])
    if (Number.isSafeInteger(id) && id > 0) ids.add(id)
  }
  return [...ids]
}


function storageKey(task: SandAiTask, owner: number | undefined): string {
  return `sand-ai-task-material-action:${owner}:${task.id}:${task.resource_id}`
}

export function retainMaterialTaskAction(task: SandAiTask, owner: number | undefined, action: MaterialTaskAction | null): void {
  const key = storageKey(task, owner)
  if (action) actions.set(key, action)
  try {
    if (action) sessionStorage.setItem(key, JSON.stringify(action))
    else sessionStorage.setItem(key, JSON.stringify({ confirmed: true }))
    if (!action) actions.delete(key)
  } catch { /* A storage failure keeps any existing memory lock. */ }
}

export function readMaterialTaskAction(task: SandAiTask, owner: number | undefined): MaterialTaskAction | undefined {
  const key = storageKey(task, owner)
  const remembered = actions.get(key)
  if (remembered) return remembered
  try {
    const raw = sessionStorage.getItem(key)
    if (raw) {
      const item: unknown = JSON.parse(raw)
      if (typeof item === 'object' && item !== null && !Array.isArray(item)) {
        const value = item as Record<string, unknown>
        if (value.confirmed === true) return undefined
        if ((value.kind === 'cancel' || value.kind === 'retry' || value.kind === 'receipt' || value.kind === 'unknown')
          && typeof value.attempt === 'number' && Number.isSafeInteger(value.attempt) && value.attempt >= 0
          && (value.key === null || (typeof value.key === 'string' && /^[\x21-\x7e]{1,512}$/.test(value.key)))) {
          const action: MaterialTaskAction = { kind: value.kind, attempt: value.attempt, key: value.key }
          actions.set(key, action)
          return action
        }
      }
      throw new Error('Invalid original material action metadata')
    }
    // Preserve old sample-runner receipt intents when entering the task page.
    const prefix = `sand-ai-material-receipt-intent:${owner}:`
    const suffix = `:${task.resource_id}:${task.id}`
    for (let index = 0; !terminal.has(task.state) && index < sessionStorage.length; index++) {
      const legacy = sessionStorage.key(index)
      if (legacy?.startsWith(prefix) && legacy.endsWith(suffix) && sessionStorage.getItem(legacy)) {
        const action: MaterialTaskAction = { kind: 'receipt', attempt: task.attempt_count, key: null }
        actions.set(key, action)
        return action
      }
    }
  } catch {
    const action: MaterialTaskAction = { kind: 'unknown', attempt: task.attempt_count, key: null }
    actions.set(key, action)
    return action
  }
  return undefined
}

// Called only with a currently authorized original-task response, never a local assumed outcome.
export function reconcileMaterialTaskAction(task: SandAiTask, owner: number | undefined): void {
  const action = readMaterialTaskAction(task, owner)
  if (!action) return
  const confirmed = action.kind === 'retry'
    ? task.state !== 'failed' || task.attempt_count > action.attempt : terminal.has(task.state)
  if (confirmed) retainMaterialTaskAction(task, owner, null)
}
