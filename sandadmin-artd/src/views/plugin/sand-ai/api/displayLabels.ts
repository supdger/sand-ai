/**
 * SandAI 管理端统一中文标签。
 * 服务动作中文名与 server/app/Identity/Infrastructure/HostSandIamServiceCatalog.php 保持一致；
 * 仅用于界面展示，不改变接口字段与取值。
 */

/** 服务动作（能力权限）中文名。 */
export const serviceActionLabels: Readonly<Record<string, string>> = {
  'sand_ai.chat.complete': 'AI 对话',
  'sand_ai.agent.submit': '智能体提交',
  'sand_ai.agent.run': '智能体读取来源',
  'sand_ai.retrieval.search': '私有来源检索',
  'sand_ai.invocation.read': '调用状态读取',
  'sand_ai.usage.read': '调用费用读取',
  'sand_ai.knowledge.query': '知识库查询',
  'sand_ai.document_parse': '私有文件解析',
  'sand_ai.workflow.run': '结构化工作流运行',
  'sand_ai.workflow.read': '结构化工作流读取',
  'sand_ai.file.write': '私有文件写入',
  'sand_ai.file.read': '私有文件读取',
  'sand_ai.model.read': '模型目录读取',
  'sand_ai.task.read': '任务结果读取',
  'sand_ai.task.cancel': '任务取消',
  'sand_ai.task.retry': '任务重试',
  'sand_ai.tool.run': '工具运行',
  'sand_ai.tool.read': '工具结果读取',
  'sand_ai.skill.run': '使用技能模板',
  'sand_ai.skill.read': '读取技能模板',
  'sand_ai.skill.manage': '管理技能模板与案例',
  'sand_ai.skill.test': '测试技能模板并计费',
  'sand_ai.skill.publish': '启用、撤销及回退技能模板',
  'sand_ai.audio.transcription': '音频转写',
  'sand_ai.audio.review': '音频片段复核',
  'sand_ai.audio.speech': '语音合成',
  'sand_ai.audio.speech.read': '语音合成结果读取'
}

/** 能力代码中文名。 */
export const capabilityLabels: Readonly<Record<string, string>> = {
  chat: '对话',
  tool_call: '工具调用',
  structured_extraction: '结构化抽取',
  vision: '图像理解',
  embedding: '向量检索',
  rerank: '结果重排',
  document_parse: '文档读取',
  image_ocr: '图片文字识别',
  audio_transcription: '录音转写',
  audio_speech: '语音合成'
}

/** 模型类型中文名。 */
export const modelTypeLabels: Readonly<Record<string, string>> = {
  chat: '对话',
  embedding: '向量检索',
  rerank: '结果重排',
  image_ocr: '图片文字识别',
  audio_transcription: '录音转写',
  audio_speech: '语音合成'
}

/** 解析/音频驱动中文名。 */
export const driverLabels: Readonly<Record<string, string>> = {
  native_document_parse: '文档读取（本地，不调用模型）',
  local_paddle_ocr: '本地图片文字识别引擎',
  ocr_unconfigured: '图片文字识别（待配置）',
  local_whisper_cpp: '本地转写引擎',
  glm_asr_audio: '智谱语音识别',
  openai_audio_transcription: 'OpenAI 兼容转写',
  openai_audio_speech: 'OpenAI 兼容语音合成'
}

/** 调用/任务状态中文名。 */
export const runStateLabels: Readonly<Record<string, string>> = {
  queued: '排队中',
  running: '进行中',
  retrying: '重试中',
  pending: '等待中',
  dispatching: '派发中',
  recovery_pending: '恢复待确认',
  succeeded: '成功',
  failed: '失败',
  canceled: '已取消',
  authorization_pending: '待授权',
  ambiguous: '结果不明',
  prepared: '已准备',
  awaiting_usage: '费用入账中'
}

/** 已发布/草稿等配置状态中文名。 */
export const configurationStateLabels: Readonly<Record<string, string>> = {
  draft: '草稿',
  verifying: '验证中',
  tested: '已验证',
  verification_ambiguous: '验证结果不明',
  published: '已启用',
  revoked: '已撤销',
  archived: '已归档'
}

export function serviceActionLabel(code: string): string {
  return serviceActionLabels[code] ?? code
}

export function capabilityLabel(code: string): string {
  return capabilityLabels[code] ?? code
}

export function modelTypeLabel(type: string): string {
  return modelTypeLabels[type] ?? type
}

export function driverLabel(code: string): string {
  return driverLabels[code] ?? code
}

export function runStateLabel(state: string): string {
  return runStateLabels[state] ?? state
}

export function configurationStateLabel(state: string): string {
  return configurationStateLabels[state] ?? state
}
