<?php

declare(strict_types=1);

namespace app\Application\Gateway;

use app\Api\Support\ApiProblem;

/**
 * The sole persistence path for API 1.47 evidence.  It shares the exact
 * request advisory lock with ChatBudgetGuard so a routing rejection cannot
 * race a later accepted invocation under the same trusted derived key.
 */
final class PreDispatchRejectionStore
{
    public function __construct(private readonly PreDispatchRejectionPersistence $persistence = new PostgresPreDispatchRejectionPersistence())
    {
    }

    public function replayOrNull(int $environmentId, \app\Application\Usage\UsageAttribution $attribution, string $requestId, string $requestFingerprint): ?ApiProblem
    {
        return $this->persistence->transaction(function () use ($environmentId, $attribution, $requestId, $requestFingerprint): ?ApiProblem {
            $this->persistence->lockRequest($requestId);
            $scope = RequestIdempotencyKey::trustedScopeForAttribution($attribution);
            $existing = $this->persistence->findRejection($scope, $requestId);
            if ($existing === null) {
                return null;
            }
            if ((int) $existing['environment_id'] !== $environmentId) {
                throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'The request id was already used with different input');
            }
            PreDispatchRejectionPolicy::admission($existing, $requestFingerprint);
            return new ApiProblem((string) $existing['error_code'], 'Provider dispatch was not sent');
        });
    }

    public function recordOrReplay(PreDispatchRejectionEvidence $evidence): ApiProblem
    {
        return $this->persistence->transaction(fn (): ApiProblem => $this->recordWithinTransaction($evidence));
    }

    public function recordWithinTransaction(PreDispatchRejectionEvidence $evidence): ApiProblem
    {
        $this->persistence->lockRequest($evidence->requestId);
        $row = $evidence->persistence();
        $accepted = $evidence->invocationId === null ? $this->persistence->findInvocation($evidence->requestId) : null;
        if ($accepted !== null) {
            // The accepted path won the shared request lock.  Never add a
            // second terminal result after configuration changes beneath it.
            throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'The request id already has an invocation result');
        }
        $existing = $this->persistence->findRejection((string) $row['trusted_scope'], (string) $row['request_id']);
        if ($existing !== null) {
            PreDispatchRejectionPolicy::admission($existing, $evidence->requestFingerprint);
            return new ApiProblem((string) $existing['error_code'], 'Provider dispatch was not sent');
        }
        $this->persistence->createRejection($row);

        return $evidence->problem;
    }
}
