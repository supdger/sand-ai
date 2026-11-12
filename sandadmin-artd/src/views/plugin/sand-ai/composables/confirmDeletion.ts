import { ElMessageBox, type MessageBoxData } from 'element-plus'

/**
 * 全站统一的删除确认：红色「删除」、灰色「再想想」，标题问句、正文写后果。
 * 只用于真正的删除/永久移除；停用、取消等动作沿用各自文案。
 */
export function confirmDeletion(message: string, title: string): Promise<MessageBoxData> {
  return ElMessageBox.confirm(message, title, {
    type: 'warning',
    confirmButtonText: '删除',
    cancelButtonText: '再想想',
    confirmButtonClass: 'el-button--danger'
  })
}
