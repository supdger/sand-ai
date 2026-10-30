<?php

declare(strict_types=1);

namespace app\Application\Workflow;

/**
 * The approval blueprint SandAI publishes into the installed SandWorkflow
 * plugin for a meeting summary whose output contract set review_required.
 *
 * The blueprint carries references back to the SandAI source task only. Summary
 * text, citations, private object locators and provider responses stay in
 * SandAI and remain readable through the existing task API; nothing is copied
 * into another plugin's storage.
 */
final class MeetingReviewWorkflowDefinition
{
    /** Single-person participant binding resolved at start from the caller. */
    public const REVIEWER_PARTICIPANT_KEY = 'meeting_summary_reviewer';

    /** Workflow business scope owned by this SandAI handoff. */
    public const TENANT_ID = 'sandadmin';
    public const OWNER_SCOPE = 'sand_ai';
    public const BUSINESS_TYPE = 'sand_ai_meeting_review';

    public const NAME = 'SandAI 会议摘要人工复核';

    /**
     * Publishable payload for plugin\sandworkflow\app\service\WorkflowDefinitionService::publish().
     * @return array<string,mixed>
     */
    public static function blueprint(?int $groupId = null): array
    {
        return [
            'group_id' => $groupId,
            'name' => self::NAME,
            'icon' => '',
            'description' => 'SandAI 会议摘要标记 review_required 后，由具名管理员显式提交的人工复核审批；摘要正文仍只在 SandAI 私有存储内读取。',
            'definition_json' => self::definitionJson(),
            'permission_type' => 1,
            'is_cancelable' => 1,
            'status' => 1,
        ];
    }

    /** Stable identity of the published blueprint, used to reuse one definition per environment. */
    public static function sha256(): string
    {
        return hash('sha256', json_encode(
            self::definitionJson(),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }

    /** @return array<string,mixed> */
    public static function definitionJson(): array
    {
        return ['flowWidgets' => self::widgets(), 'nodeConfig' => self::nodeConfig()];
    }

    /** @return list<array{name:string,label:string,type:int}> */
    public static function widgets(): array
    {
        return [
            ['name' => 'sand_ai_meeting_task', 'label' => 'SandAI 会议摘要任务号', 'type' => 1],
            ['name' => 'sand_ai_audio_task', 'label' => '录音转写任务号', 'type' => 1],
            ['name' => 'sand_ai_audio_review_revision', 'label' => '转写人工复核版本', 'type' => 1],
            ['name' => 'sand_ai_summary_contract', 'label' => '摘要输出契约', 'type' => 1],
            ['name' => 'sand_ai_summary_claims', 'label' => '摘要条目统计', 'type' => 1],
            ['name' => 'sand_ai_summary_coverage', 'label' => '原文覆盖', 'type' => 1],
            ['name' => 'sand_ai_owner', 'label' => 'SandAI 归属', 'type' => 1],
            ['name' => 'sand_ai_result_entry', 'label' => '成果与来源读取入口', 'type' => 1],
        ];
    }

    /** @return list<string> */
    public static function formFieldNames(): array
    {
        return array_map(static fn (array $widget): string => $widget['name'], self::widgets());
    }

    /** @return array<string,mixed> */
    private static function nodeConfig(): array
    {
        return [
            'id' => 'sand_ai_meeting_review_start',
            'nodeId' => 'SE_sand_ai_meeting_review',
            'name' => '提交复核',
            'type' => 0,
            'childNode' => [
                'id' => 'sand_ai_meeting_review_approve',
                'nodeId' => 'UT_sand_ai_meeting_review',
                'name' => '会议摘要人工复核',
                'type' => 1,
                'multiInstanceApprovalType' => 0,
                // The reviewer is a verified SandAdmin person supplied by the
                // caller at start; the blueprint never names a fixed account.
                'assignees' => [['participant_key' => self::REVIEWER_PARTICIPANT_KEY]],
                'formAuths' => self::readOnlyFormAuths(),
                'childNode' => [
                    'id' => 'sand_ai_meeting_review_end',
                    'nodeId' => 'EE_sand_ai_meeting_review',
                    'name' => '复核结束',
                    'type' => 9,
                ],
            ],
        ];
    }

    /** @return list<array{name:string,readable:bool,editable:bool}> */
    private static function readOnlyFormAuths(): array
    {
        return array_map(
            static fn (array $widget): array => [
                'name' => $widget['name'],
                'readable' => true,
                'editable' => false,
            ],
            self::widgets(),
        );
    }
}
