import { useUserStore } from '@/store/modules/user'
import { FILE_API_KEY_STORAGE } from './config'

const OWNER_STORAGE = `${FILE_API_KEY_STORAGE}:owner`

function ownerFor(id: number | undefined): string | null {
  return typeof id === 'number' && Number.isSafeInteger(id) && id > 0 ? String(id) : null
}

let unsubscribeOwner: (() => void) | null = null

function observeOwner(): void {
  if (unsubscribeOwner !== null) return
  const userStore = useUserStore()
  let previousOwner = ownerFor(userStore.info.id)
  unsubscribeOwner = userStore.$subscribe((_mutation, state) => {
    const owner = ownerFor(state.info.id)
    if (owner !== previousOwner) {
      const oldOwner = previousOwner
      previousOwner = owner
      if (oldOwner !== null) clearRuntimeBearer(oldOwner)
    }
  }, { detached: true, flush: 'sync' })
}

if (import.meta.hot) {
  import.meta.hot.dispose(() => {
    unsubscribeOwner?.()
    unsubscribeOwner = null
  })
}

export function currentRuntimeBearerOwner(): string | null {
  observeOwner()
  return ownerFor(useUserStore().info.id)
}

export function clearRuntimeBearer(expectedOwner: string | null = currentRuntimeBearerOwner()): void {
  try {
    if (expectedOwner === null || sessionStorage.getItem(OWNER_STORAGE) !== expectedOwner) return
    sessionStorage.removeItem(FILE_API_KEY_STORAGE)
    sessionStorage.removeItem(OWNER_STORAGE)
  } catch {
    // Storage availability must not prevent the form from being cleared.
  }
}

export function readRuntimeBearer(): string {
  const owner = currentRuntimeBearerOwner()
  if (owner === null) return ''
  try {
    const storedOwner = sessionStorage.getItem(OWNER_STORAGE)
    if (storedOwner !== owner) {
      if (storedOwner !== null) clearRuntimeBearer(storedOwner)
      else sessionStorage.removeItem(FILE_API_KEY_STORAGE)
      return ''
    }
    return sessionStorage.getItem(FILE_API_KEY_STORAGE) ?? ''
  } catch {
    return ''
  }
}

export function saveRuntimeBearer(value: string): void {
  const owner = currentRuntimeBearerOwner()
  const key = value.trim()
  if (owner === null || key === '') {
    clearRuntimeBearer(owner)
    return
  }
  try {
    sessionStorage.removeItem(FILE_API_KEY_STORAGE)
    sessionStorage.setItem(OWNER_STORAGE, owner)
    sessionStorage.setItem(FILE_API_KEY_STORAGE, key)
  } catch {
    clearRuntimeBearer(owner)
  }
}
