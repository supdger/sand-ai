/**
 * 生成符合目录编码规则（小写字母数字开头，仅含 a-z 0-9 _ -）的编码。
 * 编码对管理员隐藏，新增服务商/模型时自动生成。
 */
export function generateCatalogCode(prefix: string): string {
  const body = crypto.randomUUID().replace(/-/g, '').slice(0, 16)
  return `${prefix}${body}`
}
