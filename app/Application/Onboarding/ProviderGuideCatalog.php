<?php

declare(strict_types=1);

namespace app\Application\Onboarding;

final class ProviderGuideCatalog
{
    /** @return list<array<string, mixed>> */
    public static function all(bool $debug): array
    {
        $guides = [[
            'provider_code' => 'openai',
            'adapter' => 'openai_compatible',
            'name' => 'OpenAI API',
            'what_it_is' => '使用自己的 OpenAI API 项目账号连接模型，费用由服务商收取。',
            'suitable_for' => ['材料整理', '知识问答', '结构化提取'],
            'default_base_url' => 'https://api.openai.com/v1',
            'api_key_url' => 'https://platform.openai.com/api-keys',
            'billing_help_url' => 'https://help.openai.com/en/articles/9039756',
            'api_key_help' => '在 OpenAI API 平台创建项目 API Key，并确认 API 账号的计费设置；不要填写 ChatGPT 工作区的 Admin key。',
            'data_egress_notice' => '测试和后续调用会把提交给模型的内容发送到 OpenAI，请先确认数据外发与地区要求。',
            'discovery_notice' => '发现模型通常不产生模型推理费用；验证模型会发送极小样本文本，可能产生少量费用。',
            'fields' => [
                ['name' => 'base_url', 'label' => '服务地址', 'purpose' => '默认使用 OpenAI 官方 API 地址。'],
                ['name' => 'api_key', 'label' => 'API Key', 'purpose' => '仅用于服务商认证，保存后不可回显。'],
            ],
        ], [
            'provider_code' => 'custom_openai_compatible',
            'adapter' => 'openai_compatible',
            'name' => '兼容 OpenAI 接口的 AI 服务',
            'what_it_is' => '可通过标准 OpenAI 兼容接口提供模型的服务账号。',
            'suitable_for' => ['材料整理', '知识问答', '结构化提取'],
            'api_key_help' => '请在服务商控制台创建仅供 SandAI 使用的 API Key；Key 只会写入服务器加密存储，之后不会显示。',
            'data_egress_notice' => '测试和后续调用会把提交给模型的内容发送到该服务商，请先确认数据外发与地区要求。',
            'discovery_notice' => '发现模型通常不产生模型推理费用；验证模型会发送极小样本文本，可能产生少量费用。',
            'fields' => [
                ['name' => 'base_url', 'label' => '服务地址', 'purpose' => '服务商提供的 HTTPS API 基础地址。'],
                ['name' => 'api_key', 'label' => 'API Key', 'purpose' => '仅用于服务商认证，保存后不可回显。'],
                ['name' => 'extra_headers', 'label' => '额外请求头', 'purpose' => '仅在服务商明确要求时填写允许的组织或项目标识。'],
            ],
        ]];
        if ($debug) {
            $guides[] = [
                'provider_code' => 'fake',
                'adapter' => 'fake',
                'name' => '演示服务（仅开发/测试）',
                'debug_only' => true,
                'not_real_service' => true,
                'what_it_is' => '仅用于演示页面和自动化测试，不会连接真实模型，也不能用于生产发布。',
            ];
        }

        return $guides;
    }
}
