<?php

declare(strict_types=1);

namespace app\Infrastructure\Provider;

use app\Api\Support\ApiProblem;

/** Classifies transport facts using local messages, never upstream error bodies. */
final class ChatProviderFailurePolicy
{
    private const INVALID_KEY = '服务商未接受 API Key，请核对 Key 是否有效并属于当前服务地址';
    private const FORBIDDEN = '服务商拒绝了账号权限，请核对项目、模型和聊天接口权限';
    private const MODEL_UNAVAILABLE = '服务商没有提供请求的聊天模型或接口，请核对模型标识与 API 基础地址';
    private const ENDPOINT_UNSUPPORTED = '服务商未接受聊天接口，请核对 API 基础地址和模型聊天能力';
    private const PARAMETERS_REJECTED = '服务商拒绝了模型或参数，请核对聊天能力、Token 额度与推理额度';
    private const TIMEOUT = '服务商请求超时，可能已发送并产生费用，请核查调用记录后再决定后续操作';

    public static function http(int $status): ApiProblem
    {
        $problems = [
            401 => new ApiProblem('SAND_AI_PROVIDER_REJECTED', self::INVALID_KEY),
            403 => new ApiProblem('SAND_AI_PROVIDER_REJECTED', self::FORBIDDEN),
            404 => new ApiProblem('SAND_AI_PROVIDER_REJECTED', self::MODEL_UNAVAILABLE),
            405 => new ApiProblem('SAND_AI_PROVIDER_REJECTED', self::ENDPOINT_UNSUPPORTED),
            400 => new ApiProblem('SAND_AI_PROVIDER_REJECTED', self::PARAMETERS_REJECTED),
            422 => new ApiProblem('SAND_AI_PROVIDER_REJECTED', self::PARAMETERS_REJECTED),
            429 => new ApiProblem('SAND_AI_PROVIDER_RATE_LIMITED', '服务商当前限流，请稍后核对验证状态', true),
        ];
        return $problems[$status] ?? new ApiProblem('SAND_AI_PROVIDER_UNAVAILABLE', '服务商响应未确认，可能已处理请求，请核对调用记录和验证状态', true);
    }

    public static function network(?int $curlErrno): ApiProblem
    {
        $timeoutErrno = 28;
        return new ApiProblem(
            'SAND_AI_PROVIDER_UNAVAILABLE',
            $curlErrno === $timeoutErrno ? self::TIMEOUT : '服务商连接未确认，请核对网络、服务地址和调用记录',
            true,
        );
    }

    /** Only messages produced here may establish a specific persisted reason. */
    public static function reason(ApiProblem $problem): ?string
    {
        if ($problem->errorCode === 'SAND_AI_PROVIDER_REJECTED') {
            return match ($problem->getMessage()) {
                self::INVALID_KEY => 'invalid_api_key',
                self::FORBIDDEN => 'provider_access_denied',
                self::MODEL_UNAVAILABLE => 'model_or_endpoint_unavailable',
                self::ENDPOINT_UNSUPPORTED => 'chat_endpoint_unsupported',
                self::PARAMETERS_REJECTED => 'model_or_parameters_rejected',
                default => 'provider_rejected',
            };
        }
        if ($problem->errorCode === 'SAND_AI_PROVIDER_UNAVAILABLE') {
            return $problem->getMessage() === self::TIMEOUT ? 'provider_timeout' : 'provider_unavailable';
        }
        if ($problem->errorCode === 'SAND_AI_PROVIDER_RATE_LIMITED') {
            return 'provider_rate_limited';
        }
        return null;
    }
}
