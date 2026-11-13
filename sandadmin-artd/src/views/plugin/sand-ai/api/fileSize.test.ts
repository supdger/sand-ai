import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { formatFileSize } from './fileSize'

describe('formatFileSize', () => {
  it('不足 1024 的字节取整带 B，不带小数', () => {
    assert.equal(formatFileSize(0), '0 B')
    assert.equal(formatFileSize(70), '70 B')
    assert.equal(formatFileSize(1023), '1023 B')
  })

  it('KB 及以上 1024 进位并保留一位小数', () => {
    assert.equal(formatFileSize(1024), '1.0 KB')
    assert.equal(formatFileSize(1536), '1.5 KB')
    assert.equal(formatFileSize(2411724), '2.3 MB')
    assert.equal(formatFileSize(1288490189), '1.2 GB')
    assert.equal(formatFileSize(5 * 1024 ** 4), '5.0 TB')
  })

  it('无法解释的值显示占位符', () => {
    assert.equal(formatFileSize(null), '—')
    assert.equal(formatFileSize(undefined), '—')
    assert.equal(formatFileSize(-1), '—')
    assert.equal(formatFileSize(Number.NaN), '—')
  })
})
