<?php

declare(strict_types=1);

namespace plugin\SandAi\app\admin\logic;

use app\Api\Support\ApiProblem;
use app\Application\Usage\UsageGateway;
use app\Identity\Contract\IdentityContextException;
use app\Identity\Infrastructure\InvocationReadFacts;
use app\Infrastructure\Persistence\Model\Invocation;

/** Reads one invocation's persisted cost after the administrator permission gate. */
final class UsageCostLogic
{
    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function read(array $input, mixed $adminInfo): array
    {
        if (array_diff(array_keys($input), ['environment_id', 'request_id', 'invocation_id']) !== []) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Cost query contains unknown fields');
        }
        $environmentId = filter_var($input['environment_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $hasRequestId = array_key_exists('request_id', $input);
        $hasInvocationId = array_key_exists('invocation_id', $input);
        if ($environmentId === false || $hasRequestId === $hasInvocationId) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'environment_id and exactly one of request_id or invocation_id are required');
        }
        $scope = (new UsageAdminScopeLogic())->resolve($adminInfo, ['environment_id' => $environmentId]);
        $invocationId = null;
        if ($hasRequestId) {
            $requestId = $input['request_id'];
            if (!is_string($requestId) || $requestId === ''
                || strlen($requestId) > 128 || preg_match('/[\x00-\x1f\x7f]/', $requestId) === 1) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'request_id must be a non-empty request identifier');
            }
        } else {
            $rawInvocationId = $input['invocation_id'];
            $invocationId = filter_var($rawInvocationId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ((!is_int($rawInvocationId) && !is_string($rawInvocationId)) || $invocationId === false
                || (is_string($rawInvocationId) && preg_match('/^[1-9][0-9]{0,18}$/D', $rawInvocationId) !== 1)) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'invocation_id must be a positive integer');
            }
            $selected = Invocation::where('environment_id', $environmentId)->where('id', $invocationId)->find();
            if ($selected === null) {
                throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Invocation not found');
            }
            $requestId = (string) $selected->request_id;
        }

        return (new UsageGateway())->invocationCost(
            $environmentId,
            $requestId,
            static function (Invocation $invocation) use ($environmentId, $invocationId, $scope): void {
                // The ID bridge must still name the same invocation after the request-key lookup.
                if ((int) $invocation->environment_id !== $environmentId
                    || ($invocationId !== null && (int) $invocation->id !== $invocationId)) {
                    throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Invocation ownership facts differ');
                }
                try {
                    $facts = (new InvocationReadFacts())->resolve(
                        'sand_ai', 'sand_ai.usage.read', (string) $invocation->id,
                    );
                } catch (IdentityContextException $problem) {
                    throw new ApiProblem($problem->errorCode, 'Invocation ownership facts are unavailable');
                }
                if ($facts['environment_id'] !== $environmentId
                    || $facts['resource_ref'] !== (string) $invocation->id) {
                    throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Invocation ownership facts differ');
                }
                foreach ($scope as $environment) {
                    if ($environment['id'] === $facts['environment_id']
                        && $environment['organization_id'] === $facts['organization_id']
                        && $environment['application_id'] === $facts['application_id']) return;
                }
                throw new ApiProblem('SAND_AI_USAGE_SCOPE_DENIED', 'Invocation is outside the official administrator environment scope');
            },
        );
    }
}
