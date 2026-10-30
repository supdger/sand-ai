<?php

declare(strict_types=1);

namespace app\Mcp;

use app\Api\Controller\AudioSegmentReviewController;
use app\Api\Controller\KnowledgeBaseController;
use app\Api\Controller\SkillController;
use app\Api\Controller\StructuredWorkflowController;
use app\Api\Controller\TaskController;
use app\Api\Controller\ToolController;
use app\Mcp\Identity\McpInvocationContext;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\Builder;
use support\Request;

/** Explicit business tools; every call keeps the existing Controller resource guards. */
final class SandAiMcpTools
{
    private readonly McpBusinessBridge $bridge;

    public function __construct(Request $request, private readonly McpInvocationContext $identity)
    {
        $this->bridge = new McpBusinessBridge($request);
    }

    /** @return array<string,list<string>> */
    public static function actions(): array
    {
        return [
            'sand_ai_task_read' => ['sand_ai.task.read'],
            'sand_ai_workflow_source' => ['sand_ai.workflow.read'],
            'sand_ai_material_file_result_page' => ['sand_ai.workflow.read'],
            'sand_ai_audio_review_read' => ['sand_ai.audio.transcription', 'sand_ai.audio.review'],
            'sand_ai_knowledge_query' => ['sand_ai.knowledge.query'],
            'sand_ai_knowledge_recover' => ['sand_ai.knowledge.query'],
            'sand_ai_skill_run' => ['sand_ai.skill.run'],
            'sand_ai_skill_recover' => ['sand_ai.skill.run'],
            'sand_ai_tool_run' => ['sand_ai.tool.run'],
            'sand_ai_tool_recover' => ['sand_ai.tool.read'],
        ];
    }

    public function register(Builder $builder): void
    {
        $id = ['type' => 'integer', 'minimum' => 1];
        $code = ['type' => 'string', 'minLength' => 1, 'maxLength' => 64];
        $key = ['type' => 'string', 'minLength' => 1, 'maxLength' => 512, 'pattern' => '^[!-~]+$'];
        if ($this->identity->allowed('sand_ai_task_read')) {
            $builder->addTool(
                handler: function (int $task_id): CallToolResult {
                    $this->identity->requireTool('sand_ai_task_read');
                    $path = '/api/sand-ai/v1/tasks/' . $task_id;
                    return $this->bridge->result((new TaskController())->read($this->bridge->request('GET', $path), (string) $task_id));
                },
                name: 'sand_ai_task_read', description: '读取已获授权的原任务、处理状态与成果；资源及附加业务权限仍由原任务接口核对。',
                inputSchema: self::object(['task_id' => $id], ['task_id']),
            );
        }
        if ($this->identity->allowed('sand_ai_workflow_source')) {
            $builder->addTool(
                handler: function (int $run_id, int $source_block_id): CallToolResult {
                    $this->identity->requireTool('sand_ai_workflow_source');
                    $path = '/api/sand-ai/v1/structured-workflows/runs/' . $run_id . '/sources/' . $source_block_id;
                    return $this->bridge->result((new StructuredWorkflowController())->source(
                        $this->bridge->request('GET', $path), (string) $run_id, (string) $source_block_id,
                    ));
                },
                name: 'sand_ai_workflow_source', description: '按原材料工作流运行和来源块读取获授权来源，不返回私有存储地址。',
                inputSchema: self::object(['run_id' => $id, 'source_block_id' => $id], ['run_id', 'source_block_id']),
            );
        }
        if ($this->identity->allowed('sand_ai_material_file_result_page')) {
            $pageSchema = self::object([
                'run_id' => $id,
                'file_id' => $id,
                'section' => ['type' => 'string', 'enum' => ['documents', 'entities', 'events', 'conflicts', 'items_to_verify', 'warnings', 'extensions']],
                'extension_field' => ['type' => 'string', 'minLength' => 1, 'description' => '仅 extensions 集合必填，使用该文件成果 collection_counts.extensions 中的字段名。'],
                'offset' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 9007199254740991, 'default' => 0],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 50],
            ], ['run_id', 'file_id', 'section']);
            $pageSchema['oneOf'] = [
                ['properties' => ['section' => ['const' => 'extensions']], 'required' => ['extension_field']],
                ['properties' => ['section' => ['enum' => ['documents', 'entities', 'events', 'conflicts', 'items_to_verify', 'warnings']]], 'not' => ['required' => ['extension_field']]],
            ];
            $builder->addTool(
                handler: function (int $run_id, int $file_id, string $section, ?string $extension_field = null, int $offset = 0, int $limit = 50): CallToolResult {
                    $this->identity->requireTool('sand_ai_material_file_result_page');
                    $path = '/api/sand-ai/v1/structured-workflows/runs/' . $run_id . '/files/' . $file_id . '/result';
                    $query = ['section' => $section, 'offset' => $offset, 'limit' => $limit];
                    if ($extension_field !== null) $query['extension_field'] = $extension_field;
                    return $this->bridge->result((new StructuredWorkflowController())->fileResult(
                        $this->bridge->request('GET', $path, [], $query), (string) $run_id, (string) $file_id,
                    ));
                },
                name: 'sand_ai_material_file_result_page', description: '只读读取已获授权材料文件的完整成果页。原任务成果 paged=true 时，按 collection_counts 选择集合并分页读取；返回真实 total、items 及原完整字段索引 citations，每页重验当前来源授权。run_id、file_id 取自原任务成果；不返回私有存储地址。',
                inputSchema: $pageSchema,
            );
        }
        if ($this->identity->allowed('sand_ai_audio_review_read')) {
            $builder->addTool(
                handler: function (int $task_id): CallToolResult {
                    $this->identity->requireTool('sand_ai_audio_review_read');
                    $path = '/api/sand-ai/v1/audio-transcriptions/' . $task_id . '/reviews';
                    return $this->bridge->result((new AudioSegmentReviewController())->read(
                        $this->bridge->request('GET', $path), (string) $task_id,
                    ));
                },
                name: 'sand_ai_audio_review_read', description: '读取原会议转写的审阅段及定位；不读取原音频二进制。',
                inputSchema: self::object(['task_id' => $id], ['task_id']),
            );
        }
        $knowledgeSchema = self::object(['code' => $code, 'idempotency_key' => $key, 'input_body' => self::knowledgeBody()], ['code', 'idempotency_key', 'input_body']);
        if ($this->identity->allowed('sand_ai_knowledge_query')) {
            $builder->addTool(
                handler: function (string $code, string $idempotency_key, array $input_body): CallToolResult {
                    $this->identity->requireTool('sand_ai_knowledge_query');
                    $path = '/api/sand-ai/v1/knowledge-bases/' . rawurlencode($code) . '/query';
                    return $this->bridge->result((new KnowledgeBaseController())->query(
                        $this->bridge->request('POST', $path, $input_body, [], $idempotency_key), $code,
                    ));
                },
                name: 'sand_ai_knowledge_query', description: '使用调用方保留的原请求号检索已获授权知识来源；可选择聊天或专业重排，可能产生费用。',
                inputSchema: $knowledgeSchema,
            );
        }
        if ($this->identity->allowed('sand_ai_knowledge_recover')) {
            $builder->addTool(
                handler: function (string $code, string $idempotency_key, array $input_body): CallToolResult {
                    $this->identity->requireTool('sand_ai_knowledge_recover');
                    $path = '/api/sand-ai/v1/knowledge-bases/' . rawurlencode($code) . '/query/by-request-key';
                    return $this->bridge->result((new KnowledgeBaseController())->queryByRequestKey(
                        $this->bridge->request('POST', $path, $input_body, [], $idempotency_key), $code,
                    ));
                },
                name: 'sand_ai_knowledge_recover', description: '只读找回原知识查询：必须提供原号及完整原参数；不补发检索或重排，不猜测参数。',
                inputSchema: $knowledgeSchema,
            );
        }
        $toolKey = $key;
        $toolKey['maxLength'] = 128;
        if ($this->identity->allowed('sand_ai_tool_run')) {
            $builder->addTool(
                handler: function (string $code, int $version, string $idempotency_key, array $input_body): CallToolResult {
                    $this->identity->requireTool('sand_ai_tool_run');
                    $path = '/api/sand-ai/v1/tools/' . rawurlencode($code) . '/versions/' . $version . '/runs';
                    $body = ['business_scope' => $input_body['business_scope'],
                        'input' => $input_body['input']];
                    return $this->bridge->result((new ToolController())->run(
                        $this->bridge->request('POST', $path, $body, [], $idempotency_key), $code, (string) $version,
                    ));
                },
                name: 'sand_ai_tool_run', description: '运行现有注册的固定已发布只读工具版本。业务输入由该版本 input_schema 校验；沿正式资源授权、端点校验、预算及计量执行，可能产生费用。不能指定连接地址或凭据。',
                inputSchema: self::object([
                    'code' => $code + ['pattern' => '^[a-z0-9][a-z0-9_-]{1,63}$'],
                    'version' => $id, 'idempotency_key' => $toolKey,
                    'input_body' => self::object([
                        'business_scope' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 128, 'pattern' => '^[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,127}$'],
                        'input' => ['type' => 'object', 'additionalProperties' => true, 'description' => '遵循所选固定已发布工具版本的 input_schema；由正式工具 validator 校验。'],
                    ], ['business_scope', 'input']),
                ], ['code', 'version', 'idempotency_key', 'input_body']),
            );
        }
        if ($this->identity->allowed('sand_ai_tool_recover')) {
            $builder->addTool(
                handler: function (string $request_key): CallToolResult {
                    $this->identity->requireTool('sand_ai_tool_recover');
                    return $this->bridge->result((new ToolController())->readByKey(
                        $this->bridge->request('GET', '/api/sand-ai/v1/tool-runs/by-key', [], ['request_key' => $request_key]),
                    ));
                },
                name: 'sand_ai_tool_recover', description: '只读查找同身份下原 request_key 对应工具运行；不重发、不启动外部恢复调用、不提供替代原请求号。',
                inputSchema: self::object(['request_key' => $toolKey], ['request_key']),
            );
        }
        $skillCode = $code + ['pattern' => '^[a-z0-9][a-z0-9_-]{1,63}$'];
        $skillKey = $key;
        $skillKey['maxLength'] = 128;
        $skillFields = ['code' => $skillCode, 'version' => $id, 'idempotency_key' => $skillKey];
        if ($this->identity->allowed('sand_ai_skill_run')) {
            $builder->addTool(
                handler: function (string $code, int $version, string $idempotency_key, array $input_body): CallToolResult {
                    $this->identity->requireTool('sand_ai_skill_run');
                    $path = '/api/sand-ai/v1/skills/' . rawurlencode($code) . '/versions/' . $version . '/runs';
                    // The frozen Skill API uses request_id in its body, not a generated audit ID.
                    $body = ['input' => $input_body['input'], 'request_id' => $idempotency_key];
                    return $this->bridge->result((new SkillController())->run(
                        $this->bridge->request('POST', $path, $body), $code, (string) $version,
                    ));
                },
                name: 'sand_ai_skill_run', description: '运行固定已发布 Skill 版本。input 字段由该固定版本现有输入 validator 最终校验；原号映射正式 request_id，可能产生费用。',
                inputSchema: self::object($skillFields + ['input_body' => self::object([
                    'input' => ['type' => 'object', 'additionalProperties' => true, 'description' => '所选固定 Skill 版本的业务输入；遵循其已发布 input_schema。'],
                ], ['input'])], ['code', 'version', 'idempotency_key', 'input_body']),
            );
        }
        if ($this->identity->allowed('sand_ai_skill_recover')) {
            $builder->addTool(
                handler: function (string $code, int $version, string $idempotency_key): CallToolResult {
                    $this->identity->requireTool('sand_ai_skill_recover');
                    $path = '/api/sand-ai/v1/skills/' . rawurlencode($code) . '/versions/' . $version . '/runs/by-request-key';
                    return $this->bridge->result((new SkillController())->readRun(
                        $this->bridge->request('GET', $path, [], ['request_id' => $idempotency_key]), $code, (string) $version,
                    ));
                },
                name: 'sand_ai_skill_recover', description: '按固定 Skill 版本与原 request_id 只读找回运行，不重新运行。',
                inputSchema: self::object($skillFields, ['code', 'version', 'idempotency_key']),
            );
        }
    }

    /** @param array<string,mixed> $properties @param list<string> $required @return array<string,mixed> */
    private static function object(array $properties, array $required): array
    {
        return ['type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false];
    }

    /** @return array<string,mixed> */
    private static function knowledgeBody(): array
    {
        $fields = [
            'query' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 500],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20],
            'rerank' => ['type' => 'boolean'],
            'model' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 128],
            'rerank_mode' => ['type' => 'string', 'const' => 'professional'],
        ];
        $schema = self::object($fields, ['query', 'limit', 'rerank']);
        $schema['oneOf'] = [
            ['properties' => ['rerank' => ['const' => false]], 'not' => ['anyOf' => [['required' => ['model']], ['required' => ['rerank_mode']]]]],
            ['properties' => ['rerank' => ['const' => true], 'limit' => ['maximum' => 10]], 'required' => ['model'], 'not' => ['required' => ['rerank_mode']]],
            ['properties' => ['rerank' => ['const' => true], 'limit' => ['maximum' => 10]], 'required' => ['model', 'rerank_mode']],
        ];
        return $schema;
    }
}
