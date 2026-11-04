<?php

declare(strict_types=1);

namespace app\Application\Audit;

/**
 * 审计记录展示字典：sand_ai_audit_log 的 action / resource_type 是内部
 * 查询键，管理端列表与概览展示统一经此映射为中文。新写入的中文值原样
 * 透传；未收录的代码值回退为中文兜底，不向前端裸透英文标识。
 */
final class AuditDisplayDictionary
{
    /** 目录回执类审计的全部存储值（中英文并存，供调用方做上下文脱敏判断）。 */
    public const CATALOG_ACTIONS = [
        '保存服务商', '更新服务商', '保存模型', '更新模型',
        'catalog.provider.save', 'catalog.provider.update', 'catalog.model.save', 'catalog.model.update',
    ];

    /** @var array<string,string> 存储的 action 值 → 中文操作名 */
    private const ACTIONS = [
        // 目录与部署（含本轮新写入的中文值，见 CATALOG_ACTIONS）
        'catalog.provider.save' => '保存服务商',
        'catalog.provider.update' => '更新服务商',
        'catalog.model.save' => '保存模型',
        'catalog.model.update' => '更新模型',
        'draft_save@1' => '保存部署草稿',
        'onboarding.model.verified' => '验证模型部署草稿',
        'deployment.verification.passed' => '部署连接验证通过',
        'onboarding.deployment.published' => '发布部署',
        'onboarding.default_chat_bound' => '绑定默认对话能力',
        'capability_profile.draft' => '保存能力配置草稿',
        'capability_profile.publish' => '发布能力配置',
        'sand_ai.composition.draft' => '保存工作流配置草稿',
        'sand_ai.composition.publish' => '发布工作流配置',
        // 账单、退款与价格
        'billing.credential.update' => '更新服务商账单凭证',
        'billing.review' => '人工审核账单',
        'billing.evidence.upload_started' => '开始上传账单凭证',
        'billing.evidence.upload' => '上传账单凭证',
        'billing.evidence.clear_started' => '开始清除账单凭证',
        'billing.evidence.clear' => '清除账单凭证',
        'billing.import.create' => '导入账单',
        'billing.sync.import' => '同步导入服务商账单',
        'billing.sync.no_data' => '账单同步无可入账单据',
        'usage.refund.record' => '记录人工退款调整',
        'pricing.revision.draft' => '保存能力价格草稿',
        'pricing.revision.publish' => '发布能力价格修订',
        'platform.validation.recorded' => '记录平台模型验证结果',
        // 预算
        'budget.policy.draft' => '保存预算策略草稿',
        'budget.policy.publish' => '发布预算策略',
        'budget_policy.archive' => '归档预算策略',
        'budget.soft.threshold_alert' => '软预算阈值提醒',
        'budget.soft.cost_pending' => '专业费用待确认',
        'budget.soft.released' => '未发送的专业费用已释放',
        'budget.soft.held' => '专业费用结算挂起',
        'budget.soft.settled' => '专业费用已结算',
        // 法律与材料配置
        'legal_analysis_profile.draft' => '保存法律分析配置草稿',
        'legal_analysis_profile.publish' => '发布法律分析配置',
        'legal_analysis_profile.revoke' => '撤销法律分析配置',
        'material_analysis_profile.draft' => '保存材料整理配置草稿',
        'material_analysis_profile.publish' => '发布材料整理配置',
        'material_analysis_profile.revoke' => '撤销材料整理配置',
        // 技能模板与案例
        'sand_ai.skill.create_draft' => '创建技能模板草稿',
        'sand_ai.skill.publish' => '发布技能模板',
        'sand_ai.skill.revoke' => '撤销技能模板',
        'sand_ai.skill.rollback' => '回退技能模板版本',
        'sand_ai.skill.completed' => '技能模板调用完成',
        'sand_ai.skill.recovered' => '技能模板调用已恢复',
        'sand_ai.skill.case.create' => '创建技能案例',
        'sand_ai.skill.case.review' => '判定技能案例',
        'sand_ai.skill.case.retire' => '废弃技能案例',
        'sand_ai.skill.source_content.purge' => '清除技能来源内容',
        // 外部工具
        'sand_ai.tool.connection.create' => '创建工具连接',
        'sand_ai.tool.create_draft' => '创建工具草稿',
        'sand_ai.tool.bind_environment' => '绑定工具到环境',
        'sand_ai.tool.publish' => '发布工具',
        'sand_ai.tool.revoke' => '撤销工具',
        'sand_ai.tool.run' => '运行外部工具',
        'sand_ai.tool.run_rejected' => '外部工具运行被拒绝',
        'sand_ai.tool.expired_receipt_finalized' => '过期工具回执已终结',
        'sand_ai.tool.mcp.discover' => '发现 MCP 工具',
        'sand_ai.tool.mcp.discover_dispatch' => '派发 MCP 工具发现',
        'sand_ai.tool.mcp.discover_failed' => 'MCP 工具发现失败',
        // 知识库
        'knowledge_base.draft' => '保存知识库草稿',
        'knowledge_base.publish' => '发布知识库',
        'knowledge_base.disable' => '停用知识库',
        'knowledge_base.publication.publish' => '发布知识库到应用',
        'knowledge_base.publication.revoke' => '撤销知识库应用发布',
        'knowledge.sources.purge' => '清除知识库来源',
        'knowledge.answer_receipts.purge' => '销毁知识答案回执',
        'knowledge.embedding.indexed' => '重建知识向量索引',
        'knowledge.embedding.purge' => '清除知识向量索引',
        'knowledge_conversation.close' => '关闭知识会话',
        'knowledge_conversation.turn.cancel' => '取消知识问答轮次',
        // 私有文件
        'file.upload' => '上传文件',
        'file.delete' => '删除文件',
        'file.delete_failed' => '删除文件失败',
        'file.parse_queued' => '排队解析文件',
        'file.parse' => '完成文件解析',
        'file.parse_failed' => '文件解析失败',
        'file.ocr_dispatch_prepared' => '准备图片文字识别派发',
        // 智能体与 AI 任务
        'agent.authorization_pending' => '智能体任务待授权',
        'agent.queued' => '智能体任务已排队',
        'agent.authorization_denied' => '智能体任务授权被拒绝',
        'ai_task.authorization_pending' => 'AI 任务待授权',
        'ai_task.queued' => 'AI 任务已排队',
        'ai_task.authorization_denied' => 'AI 任务授权被拒绝',
        'agent_definition.draft' => '保存智能体草稿',
        'agent_definition.publish' => '发布智能体',
        'agent_definition.disable' => '停用智能体',
        'agent_definition.rollback' => '回退智能体版本',
        // 任务生命周期
        'task.cancel' => '取消任务',
        'task.retry_queued' => '任务重新排队',
        'task.meeting_output_correction_queued' => '会议产出修正已排队',
        'task.authorization_continued' => '任务授权续期',
        'task.authorization_paused' => '任务授权已暂停',
        'task.authorization_expired' => '任务授权已过期',
        'task.local_finalization_pending' => '本地任务等待收尾',
        'task.manual_review_required' => '任务需人工复核',
        'task.retrying' => '任务重试中',
        'task.failed' => '任务失败',
        'task.succeeded' => '任务成功',
        'task.agent_expired_receipt_failed' => '智能体过期回执处理失败',
        'task.receipt_recovery_integrity_failure' => '回执恢复完整性校验失败',
        // 结构化工作流
        'workflow.authorization_pending' => '结构化工作流待授权',
        'workflow.queued' => '结构化工作流已排队',
        'workflow.authorization_denied' => '结构化工作流授权被拒绝',
        'workflow.material_output_rejected' => '材料产出被驳回',
        'workflow.material_partial_recovered' => '材料产出部分恢复',
        'workflow.succeeded' => '工作流成功',
        'workflow.recovered_from_receipt' => '工作流已从回执恢复',
        'workflow.usage_recovered' => '工作流费用已恢复入账',
        'workflow.legal_output_rejected' => '法律审核产出被驳回',
        'workflow.review_output_rejected' => '复核产出被驳回',
        'workflow.receipt_recovery_review_required' => '工作流回执恢复需复核',
        // 调用与派发
        'chat.completion' => '对话调用完成',
        'model.dispatch.failed' => '模型派发失败',
        'model.dispatch.not_dispatched' => '模型未派发',
        'model.dispatch.succeeded' => '模型派发成功',
        'model.dispatch.ambiguous' => '模型派发结果不明',
        'outcome_receipt.destroyed' => '销毁调用回执',
        // 渠道
        'channel_binding.save' => '保存渠道绑定',
        'channel_binding.disable' => '停用渠道绑定',
        'channel_wecom_credential.save' => '保存企业微信凭证',
        'channel_feishu_credential.save' => '保存飞书凭证',
        'channel.conversation.close' => '关闭渠道会话',
        'channel.receipt_recovery.accepted' => '接受渠道回执恢复',
        'channel_delivery.retry' => '重试渠道消息投递',
        'wecom_delivery.retry' => '重试企业微信投递',
        // 音频
        'audio_transcription.segment_review' => '复核转写片段',
        // 服务动作（与 HostSandIamServiceCatalog 中文名一致）
        'sand_ai.chat.complete' => 'AI 对话',
        'sand_ai.agent.submit' => '智能体提交',
        'sand_ai.agent.run' => '智能体读取来源',
        'sand_ai.retrieval.search' => '私有来源检索',
        'sand_ai.invocation.read' => '调用状态读取',
        'sand_ai.usage.read' => '调用费用读取',
        'sand_ai.knowledge.query' => '知识库查询',
        'sand_ai.knowledge.index' => '知识库索引',
        'sand_ai.document_parse' => '私有文件解析',
        'sand_ai.workflow.run' => '结构化工作流运行',
        'sand_ai.workflow.read' => '结构化工作流读取',
        'sand_ai.file.write' => '私有文件写入',
        'sand_ai.file.read' => '私有文件读取',
        'sand_ai.model.read' => '模型目录读取',
        'sand_ai.task.read' => '任务结果读取',
        'sand_ai.task.cancel' => '任务取消',
        'sand_ai.task.retry' => '任务重试',
        'sand_ai.tool.read' => '工具结果读取',
        'sand_ai.skill.run' => '使用技能模板',
        'sand_ai.skill.read' => '读取技能模板',
        'sand_ai.skill.manage' => '管理技能模板与案例',
        'sand_ai.skill.test' => '测试技能模板并计费',
        'sand_ai.audio.transcription' => '音频转写',
        'sand_ai.audio.review' => '音频片段复核',
        'sand_ai.audio.speech' => '语音合成',
        'sand_ai.audio.speech.read' => '语音合成结果读取',
    ];

    /** @var array<string,string> 存储的 resource_type 值 → 中文对象名 */
    private const RESOURCES = [
        'provider' => '服务商',
        'model' => '模型',
        'model_deployment' => '模型部署',
        'capability_profile' => '能力配置',
        'composition_definition' => '工作流配置',
        'skill' => '技能模板',
        'skill_case' => '技能案例',
        'skill_source_suggestion' => '技能来源建议',
        'tool' => '工具',
        'tool_connection' => '工具连接',
        'tool_environment_binding' => '工具环境绑定',
        'tool_run' => '工具运行',
        'tool_mcp_snapshot' => 'MCP 工具快照',
        'task' => '任务',
        'ai_task' => 'AI 任务',
        'task_run' => '任务运行',
        'agent_run' => '智能体运行',
        'agent_definition' => '智能体',
        'workflow_run' => '工作流运行',
        'invocation' => '调用',
        'file' => '文件',
        'file_parse' => '文件解析',
        'knowledge_base' => '知识库',
        'knowledge_publication' => '知识库应用发布',
        'knowledge_conversation' => '知识会话',
        'channel_binding' => '渠道绑定',
        'legal_analysis_profile' => '法律分析配置',
        'material_analysis_profile' => '材料整理配置',
        'billing_review' => '账单审核',
        'billing_evidence' => '账单凭证',
        'billing_import' => '账单导入',
        'price_revision' => '价格修订',
        'usage_adjustment' => '用量调整',
        'budget_policy' => '预算策略',
        'rerank_soft_budget' => '重排软预算',
        'admin_validation_attempt' => '平台验证尝试',
        'audio_transcription' => '音频转写',
    ];

    /** @var array<string,string> 能力代码 → 中文名（capability.*.completion 组合规则用） */
    private const CAPABILITIES = [
        'chat' => '对话',
        'tool_call' => '工具调用',
        'structured_extraction' => '结构化抽取',
        'vision' => '图像理解',
        'embedding' => '向量检索',
        'rerank' => '结果重排',
        'document_parse' => '文档读取',
        'image_ocr' => '图片文字识别',
        'audio_transcription' => '录音转写',
        'audio_speech' => '语音合成',
    ];

    /** @var array<string,string> action 值 → 中文摘要（存储的英文摘要按操作归一） */
    private const SUMMARIES = [
        'catalog.provider.save' => '已保存服务商目录变更',
        'catalog.provider.update' => '已更新服务商目录变更',
        'catalog.model.save' => '已保存模型目录变更',
        'catalog.model.update' => '已更新模型目录变更',
        'draft_save@1' => '已保存部署草稿',
        'onboarding.model.verified' => '已验证模型部署草稿',
        'deployment.verification.passed' => '部署连接验证已通过',
        'onboarding.deployment.published' => '已发布部署',
        'onboarding.default_chat_bound' => '已绑定默认对话能力',
        'capability_profile.draft' => '已保存能力配置草稿',
        'capability_profile.publish' => '已发布能力配置',
        'sand_ai.composition.draft' => '已保存工作流配置草稿',
        'sand_ai.composition.publish' => '已发布工作流配置',
        'billing.credential.update' => '已更新服务商账单凭证',
        'billing.review' => '已完成人工账单审核',
        'billing.evidence.upload_started' => '已开始上传账单凭证',
        'billing.evidence.upload' => '已上传账单凭证',
        'billing.evidence.clear_started' => '已开始清除账单凭证',
        'billing.evidence.clear' => '已清除账单凭证',
        'billing.import.create' => '已导入账单',
        'billing.sync.import' => '已同步导入服务商账单',
        'billing.sync.no_data' => '账单同步无可入账单据',
        'usage.refund.record' => '已记录人工退款调整',
        'pricing.revision.draft' => '已保存能力价格草稿',
        'pricing.revision.publish' => '已发布能力价格修订',
        'platform.validation.recorded' => '已记录平台模型验证结果',
        'budget.policy.draft' => '已保存预算策略草稿',
        'budget.policy.publish' => '已发布预算策略',
        'budget_policy.archive' => '已归档预算策略',
        'budget.soft.threshold_alert' => '已触发软预算阈值提醒',
        'budget.soft.cost_pending' => '专业费用待确认',
        'budget.soft.released' => '未发送的专业费用已释放',
        'budget.soft.held' => '专业费用结算已挂起',
        'budget.soft.settled' => '专业费用已结算',
        'legal_analysis_profile.draft' => '已保存法律分析配置草稿',
        'legal_analysis_profile.publish' => '已发布法律分析配置',
        'legal_analysis_profile.revoke' => '已撤销法律分析配置',
        'material_analysis_profile.draft' => '已保存材料整理配置草稿',
        'material_analysis_profile.publish' => '已发布材料整理配置',
        'material_analysis_profile.revoke' => '已撤销材料整理配置',
        'sand_ai.skill.create_draft' => '已创建技能模板草稿',
        'sand_ai.skill.publish' => '已发布技能模板',
        'sand_ai.skill.revoke' => '已撤销技能模板',
        'sand_ai.skill.rollback' => '已回退技能模板版本',
        'sand_ai.skill.completed' => '技能模板调用已完成',
        'sand_ai.skill.recovered' => '技能模板调用已恢复',
        'sand_ai.skill.case.create' => '已创建技能案例',
        'sand_ai.skill.case.review' => '已判定技能案例',
        'sand_ai.skill.case.retire' => '已废弃技能案例',
        'sand_ai.skill.source_content.purge' => '已清除技能来源内容',
        'sand_ai.tool.connection.create' => '已创建工具连接',
        'sand_ai.tool.create_draft' => '已创建工具草稿',
        'sand_ai.tool.bind_environment' => '已绑定工具到环境',
        'sand_ai.tool.publish' => '已发布工具',
        'sand_ai.tool.revoke' => '已撤销工具',
        'sand_ai.tool.run' => '外部工具运行已结束',
        'sand_ai.tool.run_rejected' => '外部工具运行被拒绝',
        'sand_ai.tool.expired_receipt_finalized' => '过期工具回执已终结',
        'sand_ai.tool.mcp.discover' => '已完成 MCP 工具发现',
        'sand_ai.tool.mcp.discover_dispatch' => '已派发 MCP 工具发现',
        'sand_ai.tool.mcp.discover_failed' => 'MCP 工具发现失败',
        'knowledge_base.draft' => '已保存知识库草稿',
        'knowledge_base.publish' => '已发布知识库',
        'knowledge_base.disable' => '已停用知识库',
        'knowledge_base.publication.publish' => '已发布知识库到应用',
        'knowledge_base.publication.revoke' => '已撤销知识库应用发布',
        'knowledge.sources.purge' => '已清除知识库来源',
        'knowledge.answer_receipts.purge' => '已销毁知识答案回执',
        'knowledge.embedding.indexed' => '已重建知识向量索引',
        'knowledge.embedding.purge' => '已清除知识向量索引',
        'knowledge_conversation.close' => '已关闭知识会话',
        'knowledge_conversation.turn.cancel' => '已取消知识问答轮次',
        'file.upload' => '已上传文件',
        'file.delete' => '已删除文件',
        'file.delete_failed' => '文件删除失败',
        'file.parse_queued' => '已排队解析文件',
        'file.parse' => '已完成文件解析',
        'file.parse_failed' => '文件解析失败',
        'file.ocr_dispatch_prepared' => '已准备图片文字识别派发',
        'agent.authorization_pending' => '智能体任务待授权',
        'agent.queued' => '智能体任务已排队',
        'agent.authorization_denied' => '智能体任务授权被拒绝',
        'ai_task.authorization_pending' => 'AI 任务待授权',
        'ai_task.queued' => 'AI 任务已排队',
        'ai_task.authorization_denied' => 'AI 任务授权被拒绝',
        'agent_definition.draft' => '已保存智能体草稿',
        'agent_definition.publish' => '已发布智能体',
        'agent_definition.disable' => '已停用智能体',
        'agent_definition.rollback' => '已回退智能体版本',
        'task.cancel' => '已取消任务',
        'task.retry_queued' => '任务已重新排队',
        'task.meeting_output_correction_queued' => '会议产出修正已排队',
        'task.authorization_continued' => '任务授权已续期',
        'task.authorization_paused' => '任务授权已暂停',
        'task.authorization_expired' => '任务授权已过期',
        'task.local_finalization_pending' => '本地任务等待收尾',
        'task.manual_review_required' => '任务需人工复核',
        'task.retrying' => '任务重试中',
        'task.failed' => '任务失败',
        'task.succeeded' => '任务成功',
        'task.agent_expired_receipt_failed' => '智能体过期回执处理失败',
        'task.receipt_recovery_integrity_failure' => '回执恢复完整性校验失败',
        'workflow.authorization_pending' => '结构化工作流待授权',
        'workflow.queued' => '结构化工作流已排队',
        'workflow.authorization_denied' => '结构化工作流授权被拒绝',
        'workflow.material_output_rejected' => '材料产出被驳回',
        'workflow.material_partial_recovered' => '材料产出已部分恢复',
        'workflow.succeeded' => '工作流已成功',
        'workflow.recovered_from_receipt' => '工作流已从回执恢复',
        'workflow.usage_recovered' => '工作流费用已恢复入账',
        'workflow.legal_output_rejected' => '法律审核产出被驳回',
        'workflow.review_output_rejected' => '复核产出被驳回',
        'workflow.receipt_recovery_review_required' => '工作流回执恢复需复核',
        'chat.completion' => '对话调用已完成',
        'model.dispatch.failed' => '模型派发失败',
        'model.dispatch.not_dispatched' => '模型未派发',
        'model.dispatch.succeeded' => '模型派发成功',
        'model.dispatch.ambiguous' => '模型派发结果不明',
        'outcome_receipt.destroyed' => '已销毁调用回执',
        'channel_binding.save' => '已保存渠道绑定',
        'channel_binding.disable' => '已停用渠道绑定',
        'channel_wecom_credential.save' => '已保存企业微信凭证',
        'channel_feishu_credential.save' => '已保存飞书凭证',
        'channel.conversation.close' => '已关闭渠道会话',
        'channel.receipt_recovery.accepted' => '已接受渠道回执恢复',
        'channel_delivery.retry' => '已重试渠道消息投递',
        'wecom_delivery.retry' => '已重试企业微信投递',
        'audio_transcription.segment_review' => '已复核转写片段',
    ];

    /** 操作列中文名；新写入的中文值与未知代码都不裸透英文。 */
    public static function action(string $action): string
    {
        if (isset(self::ACTIONS[$action])) {
            return self::ACTIONS[$action];
        }
        if (str_starts_with($action, 'capability.') && str_ends_with($action, '.completion')) {
            $code = substr($action, strlen('capability.'), -strlen('.completion'));
            return (self::CAPABILITIES[$code] ?? '模型能力') . '调用完成';
        }
        if (self::containsChinese($action)) {
            return $action;
        }
        return '未归类操作';
    }

    /** 对象列中文名。 */
    public static function resource(string $resourceType): string
    {
        if (isset(self::RESOURCES[$resourceType])) {
            return self::RESOURCES[$resourceType];
        }
        if (self::containsChinese($resourceType)) {
            return $resourceType;
        }
        return $resourceType === '' ? '未记录对象' : '未归类对象';
    }

    /** 摘要列中文文案；已是中文的原样保留，未知英文摘要不裸透。 */
    public static function summary(string $action, string $summary): string
    {
        if (self::containsChinese($summary)) {
            return $summary;
        }
        if (isset(self::SUMMARIES[$action])) {
            return self::SUMMARIES[$action];
        }
        if ($summary === '') {
            return '操作已记录';
        }
        return self::action($action) . '已记录';
    }

    /**
     * 操作筛选：管理员输入中文时同时匹配映射后的存储代码与原始片段，
     * 让新旧记录都能按同一中文词检索到。
     *
     * @return list<string>
     */
    public static function actionCandidates(string $term): array
    {
        $candidates = [];
        foreach (self::ACTIONS as $code => $label) {
            if (mb_stripos($label, $term) !== false || mb_stripos($code, $term) !== false) {
                $candidates[] = $code;
            }
        }
        foreach (self::CATALOG_ACTIONS as $code) {
            if (mb_stripos($code, $term) !== false) {
                $candidates[] = $code;
            }
        }
        $candidates[] = $term;
        return array_values(array_unique($candidates));
    }

    private static function containsChinese(string $value): bool
    {
        return $value !== '' && preg_match('/\p{Han}/u', $value) === 1;
    }
}
