<?php

declare(strict_types=1);

namespace app\Identity\Infrastructure;

use app\Api\Support\ApiProblem;
use Throwable;

/** Fixed SandAI declaration; SandIAM owns persistence and disabled-state policy. */
final class HostSandIamServiceCatalog
{
    private const SERVICE = ['code' => 'sand_ai', 'name' => 'SandAI'];
    /** SandIAM B3 catalog group; persisted once the host port accepts it. */
    private const SERVICE_GROUP_NAME = 'AI 能力';
    /**
     * Chinese action descriptions declared for the SandIAM service catalog
     * (WP-A1/B3). The runtime registration payload stays `code => name` so
     * older SandIAM `ServiceCatalog` ports keep working unchanged; richer
     * ports consume `serviceDeclaration()`/`actionDeclarations()`.
     */
    private const ACTION_DETAILS = [
        'sand_ai.chat.complete' => '和 AI 对话',
        'sand_ai.agent.submit' => '提交智能体任务',
        'sand_ai.agent.run' => '读取智能体来源',
        'sand_ai.retrieval.search' => '检索私有来源',
        'sand_ai.invocation.read' => '查看调用状态',
        'sand_ai.usage.read' => '查看调用费用',
        'sand_ai.knowledge.query' => '查询知识库',
        'sand_ai.document_parse' => '解析私有文件',
        'sand_ai.workflow.run' => '运行结构化工作流',
        'sand_ai.workflow.read' => '查看结构化工作流结果',
        'sand_ai.file.write' => '写入私有文件',
        'sand_ai.file.read' => '读取私有文件',
        'sand_ai.model.read' => '查看模型目录',
        'sand_ai.task.read' => '查看任务结果',
        'sand_ai.task.cancel' => '取消任务',
        'sand_ai.task.retry' => '重试任务',
        'sand_ai.tool.run' => '运行外部工具',
        'sand_ai.tool.read' => '查看工具结果',
        'sand_ai.skill.run' => '运行个人 Skill',
        'sand_ai.skill.read' => '读取应用方法包',
        'sand_ai.skill.manage' => '管理应用方法包与案例',
        'sand_ai.skill.test' => '测试应用方法包并计费',
        'sand_ai.skill.publish' => '发布、撤销及回退应用方法包',
        'sand_ai.audio.transcription' => '音频转写',
        'sand_ai.audio.review' => '音频片段复核',
        'sand_ai.audio.speech' => '语音合成',
        'sand_ai.audio.speech.read' => '查看语音合成结果',
    ];
    private const ACTIONS = [
        'sand_ai.chat.complete' => 'AI 对话',
        'sand_ai.agent.submit' => '智能体提交',
        'sand_ai.agent.run' => '智能体读取来源',
        'sand_ai.retrieval.search' => '私有来源检索',
        'sand_ai.invocation.read' => '调用状态读取',
        'sand_ai.usage.read' => '调用费用读取',
        'sand_ai.knowledge.query' => '知识库查询',
        'sand_ai.document_parse' => '私有文件解析',
        'sand_ai.workflow.run' => '结构化工作流运行',
        'sand_ai.workflow.read' => '结构化工作流读取',
        'sand_ai.file.write' => '私有文件写入',
        'sand_ai.file.read' => '私有文件读取',
        'sand_ai.model.read' => '模型目录读取',
        'sand_ai.task.read' => '任务结果读取',
        'sand_ai.task.cancel' => '任务取消',
        'sand_ai.task.retry' => '任务重试',
        'sand_ai.tool.run' => '工具运行',
        'sand_ai.tool.read' => '工具结果读取',
    ];
    private const SKILL_ACTIONS = [
        'sand_ai.skill.run' => '运行个人 Skill',
        'sand_ai.skill.read' => '读取应用方法包',
        'sand_ai.skill.manage' => '管理应用方法包与案例',
        'sand_ai.skill.test' => '测试应用方法包并计费',
        'sand_ai.skill.publish' => '发布、撤销及回退应用方法包',
    ];
    private const AUDIO_ACTIONS = [
        'sand_ai.audio.transcription' => '音频转写',
        'sand_ai.audio.review' => '音频片段复核',
        'sand_ai.audio.speech' => '语音合成',
        'sand_ai.audio.speech.read' => '语音合成结果读取',
    ];

    /**
     * Rich service declaration for SandIAM catalog ports that accept metadata
     * (WP-A1). Older ports keep receiving the legacy `code => name` payload
     * through `register()`/`registerSkill()`/`registerAudio()`.
     *
     * @return array{code:string,name:string,group_name:string}
     */
    public static function serviceDeclaration(): array
    {
        return self::SERVICE + ['group_name' => self::SERVICE_GROUP_NAME];
    }

    /**
     * @param array<string,string> $actions legacy `code => name` map
     * @return array<string,array{name:string,description:string}>
     */
    public static function actionDeclarations(array $actions): array
    {
        $declarations = [];
        foreach ($actions as $code => $name) {
            $declarations[$code] = [
                'name' => $name,
                'description' => self::ACTION_DETAILS[$code] ?? $name,
            ];
        }
        return $declarations;
    }

    public function __construct(private readonly ?object $hostCatalog = null)
    {
    }

    /** @return array{port_available:bool,service_code:string,action_codes:list<string>,service_status:?string,action_statuses:array<string,string>,can_register:bool} */
    public function preflight(): array
    {
        return $this->inspectActions(self::ACTIONS);
    }

    /** @return array{registered:bool,service_code:string,action_codes:list<string>} */
    public function register(): array
    {
        return $this->registerActions(self::ACTIONS);
    }

    /** @return array{port_available:bool,service_code:string,action_codes:list<string>,service_status:?string,action_statuses:array<string,string>,can_register:bool} */
    public function preflightSkill(): array
    {
        return $this->inspectActions(self::SKILL_ACTIONS);
    }

    /** @return array{registered:bool,service_code:string,action_codes:list<string>} */
    public function registerSkill(): array
    {
        return $this->registerActions(self::SKILL_ACTIONS);
    }

    /** @return array{port_available:bool,service_code:string,action_codes:list<string>,service_status:?string,action_statuses:array<string,string>,can_register:bool} */
    public function preflightAudio(): array
    {
        return $this->inspectActions(self::AUDIO_ACTIONS);
    }

    /** @return array{registered:bool,service_code:string,action_codes:list<string>} */
    public function registerAudio(): array
    {
        return $this->registerActions(self::AUDIO_ACTIONS);
    }

    /** @param array<string,string> $actions
     *  @return array{registered:bool,service_code:string,action_codes:list<string>}
     */
    private function registerActions(array $actions): array
    {
        $host = $this->catalog();
        $inspection = $this->inspectActions($actions);
        if (!$inspection['port_available']) {
            throw new ApiProblem('SAND_AI_SERVICE_CATALOG_UNAVAILABLE', 'SandIAM service catalog is unavailable');
        }
        if (!$inspection['can_register']) {
            throw new ApiProblem('SAND_AI_SERVICE_CATALOG_DISABLED', 'SandIAM service or action is disabled');
        }
        try {
            $host->registerServiceActions(self::SERVICE, $actions);
        } catch (Throwable $exception) {
            if (str_contains($exception->getMessage(), 'SAND_IAM_SERVICE_CATALOG_DISABLED')) {
                throw new ApiProblem('SAND_AI_SERVICE_CATALOG_DISABLED', 'SandIAM service or action is disabled');
            }
            throw new ApiProblem('SAND_AI_SERVICE_CATALOG_UNAVAILABLE', 'SandIAM service catalog registration failed');
        }
        return [
            'registered' => true,
            'service_code' => self::SERVICE['code'],
            'action_codes' => array_keys($actions),
        ];
    }

    /** @param array<string,string> $actions
     *  @return array{port_available:bool,service_code:string,action_codes:list<string>,service_status:?string,action_statuses:array<string,string>,can_register:bool}
     */
    private function inspectActions(array $actions): array
    {
        $host = $this->catalog();
        $result = [
            'port_available' => false,
            'service_code' => self::SERVICE['code'],
            'action_codes' => array_keys($actions),
            'service_status' => null,
            'action_statuses' => [],
            'can_register' => false,
        ];
        if ($host === null || !method_exists($host, 'registerServiceActions')
            || !method_exists($host, 'inspectServiceActions')) {
            return $result;
        }
        try {
            $inspection = $host->inspectServiceActions(self::SERVICE, $actions);
        } catch (Throwable) {
            throw new ApiProblem('SAND_AI_SERVICE_CATALOG_UNAVAILABLE', 'SandIAM service catalog inspection failed');
        }
        if (!is_array($inspection) || ($inspection['service_code'] ?? null) !== self::SERVICE['code']
            || !in_array($inspection['service_status'] ?? null, ['missing', 'active', 'disabled'], true)
            || !is_array($inspection['action_statuses'] ?? null)
            || array_keys($inspection['action_statuses']) !== array_keys($actions)
            || !is_bool($inspection['can_register'] ?? null)) {
            throw new ApiProblem('SAND_AI_SERVICE_CATALOG_UNAVAILABLE', 'SandIAM service catalog inspection is invalid');
        }
        foreach ($inspection['action_statuses'] as $status) {
            if (!in_array($status, ['missing', 'active', 'disabled'], true)) {
                throw new ApiProblem('SAND_AI_SERVICE_CATALOG_UNAVAILABLE', 'SandIAM service catalog inspection is invalid');
            }
        }
        $canRegister = $inspection['service_status'] !== 'disabled'
            && !in_array('disabled', $inspection['action_statuses'], true);
        if ($inspection['can_register'] !== $canRegister) {
            throw new ApiProblem('SAND_AI_SERVICE_CATALOG_UNAVAILABLE', 'SandIAM service catalog inspection is inconsistent');
        }
        return [
            ...$result,
            'port_available' => true,
            'service_status' => $inspection['service_status'],
            'action_statuses' => $inspection['action_statuses'],
            'can_register' => $canRegister,
        ];
    }

    private function catalog(): ?object
    {
        return $this->hostCatalog ?? HostSandIamRuntime::serviceCatalog();
    }
}
