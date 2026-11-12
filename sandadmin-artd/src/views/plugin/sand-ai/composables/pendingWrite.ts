/**
 * 「结果未确认」写操作的统一出口。
 *
 * 写请求断网/超时且查不到服务端回执时，本地操作记录会一直锁着页面。
 * confirmAbandonPendingWrite 给用户一个明确出口：确认后清掉本地记录解锁；
 * 清掉前提示「请求可能其实已送到服务端」，让用户先刷新核对。
 */

import { ElMessageBox } from 'element-plus'

/**
 * 弹确认框；用户确认放弃后执行 clear（移除本地操作记录并解锁）。
 * clear 内即使记录已损坏也要尽量移除存储键，保证页面能解锁。
 */
export function confirmAbandonPendingWrite(clear: () => void | Promise<void>): void {
  void ElMessageBox.confirm(
    '上次操作的结果没能确认。放弃后本地记录会清掉、页面恢复可编辑；' +
      '若请求其实已经送到服务端，改动可能已经生效，请先刷新核对。',
    '放弃此次变更',
    { type: 'warning', confirmButtonText: '放弃', cancelButtonText: '再想想' }
  )
    .then(() => clear())
    .catch(() => {})
}
