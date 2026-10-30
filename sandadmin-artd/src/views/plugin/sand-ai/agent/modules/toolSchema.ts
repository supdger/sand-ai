/** The same bounded subset published by ToolSchemaPolicy; no provider declarations are inferred. */
export interface ToolSchema {
  type: 'object' | 'array' | 'string' | 'integer' | 'number' | 'boolean'
  format?: 'date' | 'date-time'
  properties?: Record<string, ToolSchema>
  required?: string[]
  items?: ToolSchema
  maxItems?: number
  enum?: unknown[]
  minLength?: number
  maxLength?: number
  minimum?: number
  maximum?: number
  additionalProperties?: false
}

export function record(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

const keywords = ['type', 'properties', 'required', 'items', 'maxItems', 'enum',
  'minLength', 'maxLength', 'minimum', 'maximum', 'additionalProperties', 'format']
const types = ['object', 'array', 'string', 'integer', 'number', 'boolean']
const bytes = (value: string): number => new TextEncoder().encode(value).length
const nodes = (value: unknown): number => Array.isArray(value)
  ? 1 + value.reduce<number>((sum, item) => sum + nodes(item), 0)
  : record(value) ? 1 + Object.values(value).reduce<number>((sum, item) => sum + nodes(item), 0) : 1

export function parseToolSchema(value: unknown): ToolSchema {
  if (bytes(JSON.stringify(value) ?? '') > 32768 || nodes(value) > 256) {
    throw new Error('Schema 超出大小或字段数量限制')
  }
  function child(raw: unknown, path: string, depth: number): ToolSchema {
    if (!record(raw) || depth > 8 || !types.includes(String(raw.type))
      || Object.keys(raw).some(key => !keywords.includes(key))) {
      throw new Error(`${path}包含不支持的 Schema 定义`)
    }
    const type = raw.type
    if (type !== 'object' && type !== 'array' && type !== 'string'
      && type !== 'integer' && type !== 'number' && type !== 'boolean') throw new Error(`${path}类型无效`)
    const result: ToolSchema = { type }
    if ('properties' in raw) {
      if (type !== 'object' || !record(raw.properties) || Object.keys(raw.properties).length > 64) {
        throw new Error(`${path}的 properties 必须是最多 64 项的对象`)
      }
      result.properties = Object.fromEntries(Object.entries(raw.properties).map(([name, definition]) => {
        if (!name || Array.from(name).length > 128) throw new Error(`${path}字段名无效`)
        return [name, child(definition, `${path}.${name}`, depth + 1)]
      }))
    }
    if ('required' in raw) {
      if (type !== 'object' || !Array.isArray(raw.required) || raw.required.length > 64
        || !raw.required.every((item): item is string => typeof item === 'string' && item.length > 0)
        || new Set(raw.required).size !== raw.required.length
        || raw.required.some(name => !Object.hasOwn(result.properties ?? {}, name))) {
        throw new Error(`${path}的 required 必须引用已声明的字段`)
      }
      result.required = raw.required
    }
    if (type === 'object') {
      if (raw.additionalProperties !== false) throw new Error(`${path}必须声明 additionalProperties: false`)
      result.additionalProperties = false
    } else if ('additionalProperties' in raw) throw new Error(`${path}不能声明 additionalProperties`)
    if ('items' in raw) {
      if (type !== 'array') throw new Error(`${path}只有数组可以声明 items`)
      result.items = child(raw.items, `${path}[]`, depth + 1)
    }
    for (const name of ['maxItems', 'minLength', 'maxLength', 'minimum', 'maximum'] as const) {
      if (!(name in raw)) continue
      const bound = raw[name]
      const validType = name === 'maxItems' ? type === 'array'
        : name === 'minimum' || name === 'maximum' ? type === 'integer' || type === 'number' : type === 'string'
      if (!validType || typeof bound !== 'number' || !Number.isFinite(bound)
        || (name !== 'minimum' && name !== 'maximum'
          && (!Number.isInteger(bound) || bound < 0 || bound > (name === 'maxItems' ? 1000 : 65536)))) {
        throw new Error(`${path}的 ${name} 限制无效`)
      }
      result[name] = bound
    }
    if (type === 'array' && (!result.items || result.maxItems === undefined)) {
      throw new Error(`${path}数组必须声明 items 和 maxItems`)
    }
    if ((result.minLength ?? 0) > (result.maxLength ?? Infinity)
      || (result.minimum ?? -Infinity) > (result.maximum ?? Infinity)) throw new Error(`${path}的范围上下限冲突`)
    if ('format' in raw) {
      if (type !== 'string' || (raw.format !== 'date' && raw.format !== 'date-time')) {
        throw new Error(`${path}仅支持字符串的 date 或 date-time 格式`)
      }
      result.format = raw.format
    }
    if ('enum' in raw) {
      if (!Array.isArray(raw.enum) || !raw.enum.length || raw.enum.length > 100) throw new Error(`${path}的 enum 无效`)
      result.enum = raw.enum
    }
    return result
  }
  const schema = child(value, 'Schema', 0)
  if (schema.type !== 'object') throw new Error('Schema 根节点必须是关闭额外字段的对象')
  return schema
}

export function toolInputIssues(schema: ToolSchema, input: unknown): string[] {
  const issues: string[] = []
  if (bytes(JSON.stringify(input) ?? '') > 65536 || nodes(input) > 1024) {
    return ['工具输入超出 64 KiB 或 1024 个节点限制']
  }
  const equal = (a: unknown, b: unknown): boolean => {
    if (Array.isArray(a) && Array.isArray(b)) return a.length === b.length && a.every((value, index) => equal(value, b[index]))
    if (record(a) && record(b)) return Object.keys(a).length === Object.keys(b).length
      && Object.keys(a).every(key => Object.hasOwn(b, key) && equal(a[key], b[key]))
    return a === b
  }
  function check(definition: ToolSchema, value: unknown, path: string, depth: number): void {
    if (depth > 8) { issues.push(`${path}嵌套超过 8 层`); return }
    const valid = definition.type === 'object' ? record(value)
      : definition.type === 'array' ? Array.isArray(value)
      : definition.type === 'integer' ? typeof value === 'number' && Number.isSafeInteger(value)
      : definition.type === 'number' ? typeof value === 'number' && Number.isFinite(value)
      : typeof value === definition.type
    if (!valid) { issues.push(`${path}必须是${typeLabel(definition.type)}`); return }
    if (definition.enum && !definition.enum.some(item => equal(item, value))) issues.push(`${path}必须选择枚举中的值`)
    if (typeof value === 'string') {
      if (definition.format && !dateFormatValid(definition.format, value)) {
        issues.push(definition.format === 'date'
          ? `${path}必须是有效日期，格式为 YYYY-MM-DD`
          : `${path}必须是带时区的有效 RFC3339 日期时间，例如 2026-10-09T09:30:00+08:00`)
      }
      const length = Array.from(value).length
      if (length < (definition.minLength ?? 0)) issues.push(`${path}至少 ${definition.minLength} 个字符`)
      if (length > (definition.maxLength ?? Infinity)) issues.push(`${path}最多 ${definition.maxLength} 个字符`)
    }
    if (typeof value === 'number') {
      if (value < (definition.minimum ?? -Infinity)) issues.push(`${path}不得小于 ${definition.minimum}`)
      if (value > (definition.maximum ?? Infinity)) issues.push(`${path}不得大于 ${definition.maximum}`)
    }
    if (Array.isArray(value)) {
      if (value.length > (definition.maxItems ?? 0)) issues.push(`${path}最多 ${definition.maxItems} 项`)
      if (definition.items) value.forEach((item, index) => check(definition.items!, item, `${path}[${index}]`, depth + 1))
    }
    if (record(value)) {
      for (const name of definition.required ?? []) {
        if (!Object.hasOwn(value, name)) issues.push(`${path}.${name}为必填参数`)
      }
      for (const [name, item] of Object.entries(value)) {
        const property = definition.properties && Object.hasOwn(definition.properties, name)
          ? definition.properties[name] : undefined
        if (!property) issues.push(`${path}.${name}未在工具 Schema 中声明`)
        else check(property, item, `${path}.${name}`, depth + 1)
      }
    }
  }
  check(schema, input, '输入', 0)
  return issues
}

function dateFormatValid(format: 'date' | 'date-time', value: string): boolean {
  const pattern = format === 'date' ? /^([0-9]{4})-([0-9]{2})-([0-9]{2})$/
    : /^([0-9]{4})-([0-9]{2})-([0-9]{2})[Tt](?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9](?:\.[0-9]+)?(?:[Zz]|[+-](?:[01][0-9]|2[0-3]):[0-5][0-9])$/
  const match = pattern.exec(value)
  if (!match || match[0] !== value) return false
  const year = Number(match[1])
  const month = Number(match[2])
  const day = Number(match[3])
  if (year < 1 || month < 1 || month > 12 || day < 1) return false
  const leap = year % 4 === 0 && (year % 100 !== 0 || year % 400 === 0)
  const monthDays = [31, leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31]
  return day <= (monthDays[month - 1] ?? 0)
}

export function typeLabel(type: ToolSchema['type']): string {
  return { object: 'JSON 对象', array: 'JSON 数组', string: '文字', integer: '整数', number: '数值', boolean: '布尔值' }[type]
}
