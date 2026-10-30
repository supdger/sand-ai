<?php

declare(strict_types=1);

namespace app\Application\Budget;

use app\Api\Support\ApiProblem;

/**
 * Reviewed protocol capacities, never a caller's token count or an arbitrary
 * deployment input ceiling. A new source/model requires a new implementation.
 */
final class OfficialModelCapacity
{
    public const VERSION = 'official_capacity@1';
    public const FLASH_TEXT = 'bigmodel_flash_text@20261004';
    public const FLASH_CHAT_WIRE = 'bigmodel_flash_chat_wire@20261004';
    public const EMBEDDING_TEXT = 'bigmodel_embedding_text@20261004';
    public const EMBEDDING_BATCH_PROTOCOL = 'neuron_embedding_batch@1';
    public const BASE_URL = 'https://open.bigmodel.cn/api/paas/v4';

    /** @return array<string,mixed> */
    public static function sources(): array
    {
        return [
            self::FLASH_TEXT => [
                'source_url' => 'https://docs.bigmodel.cn/cn/guide/models/vlm/glm-5.3-flash.md',
                'source_sha256' => 'd9e2d56363f9e04e18c5bb60756edffaf565d2ccb28c420552da2ead3eecef9c',
                'definition_url' => 'https://docs.bigmodel.cn/cn/guide/start/concept-param.md',
                'definition_sha256' => '4b7b116c1fe477e6f3defeb55f5714dd6a65576f0c565af1d89f3557dffe9a05',
                'models' => ['glm-5.3-flash'],
                'capability_code' => 'chat',
                // "1M" can mean 1,000,000 or 1,048,576; use the larger bound.
                // Do not subtract output: full context plus output is safer.
                'input_capacity_tokens' => 1048576,
                'max_output_tokens' => 131072,
                'protocol' => 'plain_text_messages',
            ],
            self::FLASH_CHAT_WIRE => [
                'source_url' => 'https://docs.bigmodel.cn/cn/guide/models/vlm/glm-5.3-flash.md',
                'source_sha256' => 'd9e2d56363f9e04e18c5bb60756edffaf565d2ccb28c420552da2ead3eecef9c',
                'definition_url' => 'https://docs.bigmodel.cn/cn/guide/start/concept-param.md',
                'definition_sha256' => '4b7b116c1fe477e6f3defeb55f5714dd6a65576f0c565af1d89f3557dffe9a05',
                'models' => ['glm-5.3-flash'],
                'capability_code' => 'chat',
                'capability_codes' => ['chat', 'structured_extraction'],
                'input_capacity_tokens' => 1048576,
                'max_output_tokens' => 131072,
                'protocol' => BudgetChatWireProtocol::VERSION,
                'wire_evidence' => [
                    ['url' => 'https://docs.bigmodel.cn/api-reference/模型-api/对话补全.md',
                        'sha256' => '9844803948f2c1fd80562d9c617b7cb9bc0ddbac66e97db7a3d65279c9753a8a'],
                    ['url' => 'https://docs.bigmodel.cn/cn/guide/capabilities/function-calling.md',
                        'sha256' => 'cc0a96bb44732fd86898859715ddf3eab5f51f959d45730569cea53ee7febaa8'],
                    ['url' => 'https://docs.bigmodel.cn/cn/guide/capabilities/struct-output.md',
                        'sha256' => '54032feb664afb1adc96b90f4198c4a690936988526cfc6cdb18e3da6b715b74'],
                ],
                // This registry proves a financial input capacity. Official
                // function/tool auto and json_object support do not establish
                // provider json_schema functionality merely from its SDK wire.
                'official_tool_choices' => ['auto'],
                'official_response_formats' => ['text', 'json_object'],
            ],
            self::EMBEDDING_TEXT => [
                'source_url' => 'https://docs.bigmodel.cn/api-reference/模型-api/文本嵌入.md',
                'source_sha256' => 'd465ffcadcccb27f6991431449200ed80dd33109c9f5988f39ffcdf9ad222c74',
                'definition_url' => 'https://docs.bigmodel.cn/api-reference/模型-api/文本嵌入.md',
                'definition_sha256' => 'd465ffcadcccb27f6991431449200ed80dd33109c9f5988f39ffcdf9ad222c74',
                'models' => ['embedding-2', 'embedding-3'],
                'capability_code' => 'embedding',
                'input_capacity_tokens_by_model' => ['embedding-2' => 512, 'embedding-3' => 3072],
                'max_output_tokens' => 0,
                'protocol' => 'single_text_embedding',
                'batch_model' => 'embedding-3',
                'batch_request_protocol' => self::EMBEDDING_BATCH_PROTOCOL,
                // The supplier supports 64; the current product connector admits 16.
                'official_max_batch_entries' => 64,
                'max_batch_entries' => 16,
            ],
        ];
    }

    /**
     * The registration is selected in a published deployment configuration.
     * It cannot lower the compiled, reviewed capacity or change source hashes.
     * @param array<string,mixed> $registration
     * @return array<string,mixed>
     */
    public static function resolve(array $registration, string $remoteModel, string $capability): array
    {
        $keys = ['version', 'source_code', 'source_sha256', 'definition_sha256'];
        if (array_diff(array_keys($registration), $keys) !== []
            || array_diff($keys, array_keys($registration)) !== []
            || ($registration['version'] ?? null) !== self::VERSION
            || !is_string($registration['source_code'] ?? null)) self::unavailable();
        $source = self::sources()[$registration['source_code']] ?? null;
        if (!is_array($source) || !in_array($capability, $source['capability_codes'] ?? [$source['capability_code']], true)
            || !in_array($remoteModel, $source['models'], true)
            || ($registration['source_sha256'] ?? null) !== $source['source_sha256']
            || ($registration['definition_sha256'] ?? null) !== $source['definition_sha256']) self::unavailable();
        if ($source['protocol'] === BudgetChatWireProtocol::VERSION) $source['capability_code'] = $capability;
        $source['version'] = self::VERSION;
        $source['source_code'] = $registration['source_code'];
        if ($capability === 'embedding') {
            $source['input_capacity_tokens'] = $source['input_capacity_tokens_by_model'][$remoteModel];
        }
        return $source;
    }

    private static function unavailable(): never
    {
        throw new ApiProblem('SAND_AI_BUDGET_CAPACITY_UNVERIFIED',
            'This published deployment has no supported official capacity registration');
    }
}
