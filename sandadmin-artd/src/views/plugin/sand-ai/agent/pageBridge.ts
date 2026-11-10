import type { InjectionKey } from 'vue'

/**
 * 「智能体与渠道」页内跨页签桥接：渠道页签需要把「去启用」交回智能体页签。
 * 由 index.vue provide，渠道面板 inject；只承载页面级导航动作，不承载业务数据。
 */
export interface AgentPageBridge {
  /** 切到智能体页签，并对指定智能体发起启用流程（已有草稿则确认后启用，否则打开编辑抽屉）。 */
  requestEnableAgent: (agentCode: string) => void
}

export const AGENT_PAGE_BRIDGE: InjectionKey<AgentPageBridge> = Symbol('sand-ai-agent-page-bridge')
