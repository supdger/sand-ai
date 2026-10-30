<?php

declare(strict_types=1);

return [
    'version' => 'audio_transcription_catalog@2',
    'display_name' => '录音转写',
    'plain_summary' => '把受控私有录音转换为可按时间回看的文字片段。',
    'runtime_state' => 'route_available',
    'readiness_note' => '应用转写接口已交付；仍须配置转写引擎并发布音频能力路由。配置状态不能代替真实录音验证。',
    'adapter_kinds' => [
        [
            'code' => 'local',
            'display_name' => '本地转写适配器',
            'runtime_state' => 'not_configured',
            'supported_data_egress_policies' => ['local_only', 'private_only', 'approved_cloud'],
            'private_input_rule' => '适配器只接收验证后的私有文件引用，不向调用方返回本地路径或存储位置。',
            'temporary_cleanup_rule' => '适配器必须在每次调用成功或失败后清理自己创建的临时音频和临时结果。',
        ],
        [
            'code' => 'external',
            'display_name' => '外部转写服务适配器',
            'runtime_state' => 'not_configured',
            'supported_data_egress_policies' => ['approved_cloud'],
            'private_input_rule' => '适配器只可按已发布的数据外发政策读取最小必要音频，不得生成永久公开地址。',
            'temporary_cleanup_rule' => '适配器必须清理临时传输物和临时结果；服务商侧留存期限由后续版本化连接政策约束。',
        ],
    ],
];
