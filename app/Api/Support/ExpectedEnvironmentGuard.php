<?php

declare(strict_types=1);

namespace app\Api\Support;

use support\Request;

/** A page may declare its selected environment; SandIAM remains the identity authority. */
final class ExpectedEnvironmentGuard
{
    public function assert(Request $request, int $verifiedEnvironmentId): void
    {
        $declared = $request->header('x-sand-ai-environment-id');
        if ($declared === null) {
            return;
        }
        if (!is_string($declared) || preg_match('/\A[1-9][0-9]*\z/D', $declared) !== 1
            || filter_var($declared, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', '所选环境声明无效，请重新选择环境');
        }
        if ($declared !== (string) $verifiedEnvironmentId) {
            throw new ApiProblem('SAND_AI_ENVIRONMENT_MISMATCH', '应用运行身份与所选环境不一致，请使用该环境的 SandIAM 运行身份');
        }
    }
}
