<?php

declare(strict_types=1);

namespace app\Application\Gateway;

use app\Api\Support\ApiProblem;
use app\Application\Usage\UsageAttribution;

final class ModelCapabilityInvocationPolicy
{
    /** @return array{action:string,context:array<string,string>} */
    public static function audit(string $capabilityCode, string $requestId, string $modelCode): array
    {
        $context = ['request_id' => $requestId, 'model' => $modelCode];
        if ($capabilityCode !== 'chat') {
            $context['capability_code'] = $capabilityCode;
        }

        return [
            'action' => $capabilityCode === 'chat' ? 'chat.completion' : "capability.{$capabilityCode}.completion",
            'context' => $context,
        ];
    }

    public static function attribution(int $environmentId, string $capabilityCode, ?UsageAttribution $attribution): UsageAttribution
    {
        if ($capabilityCode !== 'chat' && $attribution === null) {
            throw new ApiProblem('SAND_AI_CAPABILITY_UNSUPPORTED', 'A non-chat capability requires trusted usage attribution');
        }
        $attribution ??= UsageAttribution::fallback($environmentId, 'chat', 'sand_ai.chat.complete', $capabilityCode);
        if ($attribution->environmentId !== $environmentId || $attribution->capabilityCode !== $capabilityCode) {
            throw new ApiProblem('SAND_AI_CAPABILITY_UNSUPPORTED', 'The trusted invocation capability or environment is inconsistent');
        }

        return $attribution;
    }
}
