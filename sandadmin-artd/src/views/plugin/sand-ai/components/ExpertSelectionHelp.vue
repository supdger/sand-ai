<template>
  <ElCard class="expert-selection-help" shadow="never">
    <h2 class="selection-title">按任务选择能力</h2>
    <p class="selection-intro">先看要完成什么，再选能力。展开查看输入、成果、准备条件和组合方式。</p>
    <ElCollapse accordion>
      <ElCollapseItem v-for="choice in choices" :key="choice.id" :name="choice.id" :title="choice.title">
        <dl class="selection-detail">
          <dt>适合输入</dt><dd>{{ choice.input }}</dd>
          <dt>能得到什么</dt><dd>{{ choice.result }}</dd>
          <dt>先准备什么</dt><dd>{{ choice.preparation }}</dd>
          <dt>在哪里使用</dt><dd>{{ choice.location }}</dd>
          <dt>如何组合</dt><dd>{{ choice.combination }}</dd>
        </dl>
      </ElCollapseItem>
    </ElCollapse>
    <p class="selection-note">AI 成果需要人工复核。AI 工作流按步骤执行 AI 任务；业务审批由独立的业务流程处理。</p>
  </ElCard>
</template>

<script setup lang="ts">
  import { ElCard, ElCollapse, ElCollapseItem } from 'element-plus'

  interface CapabilityChoice {
    id: 'knowledge' | 'skill' | 'agent' | 'bot' | 'workflow'
    title: string
    input: string
    result: string
    preparation: string
    location: string
    combination: string
  }

  const choices: readonly CapabilityChoice[] = [
    {
      id: 'knowledge',
      title: '从资料找依据 → 知识库',
      input: '已整理的材料，以及需要查找的问题或关键词。',
      result: '与问题相关的材料摘录和来源定位，可回到原材料核对。',
      preparation: '先导入并解析私有材料；需要复用时，整理知识库并启用可用版本。',
      location: '在已授权的“检索 / 知识”页面管理知识库、检索资料。',
      combination: '只需找原文依据时直接检索；需要按固定方法处理资料时，在技能模板中引用知识版本；需要持续问答时，将已开放知识库绑定到智能体。'
    },
    {
      id: 'skill',
      title: '复用固定方法、步骤和输出结构 → 技能模板',
      input: '同类任务的固定处理方法、输入要求、输出结构与样例。',
      result: '可重复使用的固定方法版本，以及按该方法生成的成果。',
      preparation: '先确认可用模型、已授权资料和适用的个人、团队或组织消费范围；保存技能模板草稿，用案例核对成果并由人确认，再启用固定版本供使用。',
      location: '在已授权的“检索 / 知识”页面，使用“应用技能模板”区域。',
      combination: '知识库提供依据，技能模板规定处理步骤和成果结构。启用后可单独运行固定版本；需要接续其他服务时，在 AI 工作流中选择当前环境目录可用的已启用技能模板。'
    },
    {
      id: 'agent',
      title: '让助手结合模型、资料和受控工具执行任务 → 智能体',
      input: '任务问题、回答要求，以及需要引用的已启用知识库。',
      result: '按助手配置生成的回答或任务成果，可查看关联来源与执行情况。',
      preparation: '选择应用环境和可用模型，绑定已启用知识库；需要工具时，选择已启用且获准的受控工具。保存并启用后试用。',
      location: '在已授权的“智能体与渠道”页面，使用“智能体配置”和“启用版本试用”。',
      combination: '知识库提供私有资料；需要查询外部资料时再选只读工具，并确认工具版本和业务范围。常用组合保存为智能体；一次性需求使用“按次组合试用”。启用的智能体可绑定机器人渠道，也可作为 AI 工作流节点。'
    },
    {
      id: 'bot',
      title: '让同一已启用助手接收消息 → 机器人',
      input: '消息渠道中的提问或任务消息。',
      result: '通过该渠道使用已启用助手并收到回复；绑定渠道不需要重新训练助手。',
      preparation: '先有已启用的智能体版本，再完成可用消息渠道的绑定与授权。消息回复不代表业务已获人工批准。',
      location: '在已授权的“智能体与渠道”页面，使用“渠道绑定”。',
      combination: '先启用智能体，再绑定飞书或企业微信等可用渠道。同一个智能体可供多个渠道使用；每个绑定仍分别配置调用身份、渠道授权和会话。'
    },
    {
      id: 'workflow',
      title: '让多个已启用服务按依赖接续处理 → AI 工作流',
      input: '本次任务输入、各节点使用的已启用服务，以及节点之间要传递的成果字段。',
      result: '固定版本的多节点任务、选定成果节点的结果，以及各节点的状态、来源和费用。',
      preparation: '先启用所需服务，在当前环境可组合目录中选择节点；设置依赖、参数来源、失败处理和成果节点，保存草稿后明确启用。',
      location: '在已授权的“智能体与渠道”页面，使用“AI 工作流”配置与“应用版本试用”。',
      combination: '需要多个处理步骤时，将目录中可用的知识库、技能模板、智能体、材料配置或只读工具分别设为节点。参数可来自本次输入、已依赖节点的成果或固定值；按实际任务选择服务，不必选齐所有类型。'
    }
  ]
</script>

<style scoped lang="scss">
  .expert-selection-help { margin-bottom: 16px; }
  .selection-title { margin: 0; font-size: 18px; }
  .selection-intro, .selection-note { color: var(--el-text-color-secondary); line-height: 1.6; }
  .selection-detail { display: grid; grid-template-columns: max-content minmax(0, 1fr); gap: 8px 16px; margin: 0; }
  .selection-detail dt { font-weight: 600; }
  .selection-detail dd { margin: 0; overflow-wrap: anywhere; }
  .selection-note { margin-bottom: 0; font-size: 13px; }
  @media (max-width: 600px) { .selection-detail { grid-template-columns: 1fr; gap: 4px; } .selection-detail dd { margin-bottom: 8px; } }
</style>
