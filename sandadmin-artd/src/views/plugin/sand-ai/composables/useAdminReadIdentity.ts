import { computed, onBeforeUnmount, readonly, ref, watch } from 'vue'
import { useUserStore } from '@/store/modules/user'

type AdminReadIdentity = Readonly<{ actor: number; epoch: number }>

/** Bind read results to the administrator and session that requested them. */
export function useAdminReadIdentity() {
  const userStore = useUserStore()
  const actor = computed(() => {
    const id = userStore.info.id
    return typeof id === 'number' && Number.isSafeInteger(id) && id > 0 ? id : null
  })
  const epoch = ref(0)
  let alive = true
  watch([actor, () => userStore.accessToken], () => { ++epoch.value }, { flush: 'sync' })
  onBeforeUnmount(() => { alive = false; ++epoch.value })
  function capture(): AdminReadIdentity | null {
    return alive && actor.value !== null ? { actor: actor.value, epoch: epoch.value } : null
  }
  function current(identity: AdminReadIdentity | null): boolean {
    return alive && identity !== null && identity.actor === actor.value && identity.epoch === epoch.value
  }
  return { actor, epoch: readonly(epoch), capture, current }
}
