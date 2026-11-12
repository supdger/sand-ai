import { ElMessageBox } from 'element-plus'

/**
 * 宿主主题把默认按钮描边染成主色，删除确认框的取消按钮要固定成普通灰按钮，
 * 样式在 components/sandAiPage.css，不走 confirmDeletion 的删除框可复用这个类名。
 */
export const DELETION_CANCEL_CLASS = 'sand-ai-confirm-cancel'

/**
 * 全站统一的删除确认：红色「删除」、灰色「再想想」，标题问句、正文写后果。
 * 只用于真正的删除/永久移除；停用、取消等动作沿用各自文案。
 */
export function confirmDeletion(message: string, title: string) {
  return ElMessageBox.confirm(message, title, {
    type: 'warning',
    confirmButtonText: '删除',
    cancelButtonText: '再想想',
    confirmButtonClass: 'el-button--danger',
    cancelButtonClass: DELETION_CANCEL_CLASS
  })
}
