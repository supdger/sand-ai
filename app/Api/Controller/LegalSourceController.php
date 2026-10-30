<?php

declare(strict_types=1);

namespace app\Api\Controller;

use app\Api\Support\ApiProblem;
use app\Api\Support\ApiResponder;
use app\Application\File\FileOwnershipGuard;
use app\Application\Gateway\SandIamContextAuthenticator;
use app\Application\Legal\LegalSourceGateway;
use app\Application\Usage\UsageAttribution;
use app\Identity\Infrastructure\HostSandIamInvocationAuthorizer;
use app\Identity\Infrastructure\RequestAuditId;
use support\Request;
use support\Response;

final class LegalSourceController
{
    public function snapshot(Request $request): Response
    {
        $requestId = RequestAuditId::ensure($request);
        try {
            $context = (new SandIamContextAuthenticator())->authenticate($request, 'sand_ai.file.read');
            $payload = $request->post();
            if (!is_array($payload)) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'A legal source snapshot request is required');
            }
            $gateway = new LegalSourceGateway();
            $sources = $gateway->normalize($payload);
            $authorizer = new HostSandIamInvocationAuthorizer();
            $decisions = [];
            foreach ($gateway->fileIds($sources) as $fileId) {
                $decisions[$fileId] = $authorizer->authorize(
                    $request, $context['context'], 'sand_ai.file.read', 'sand_ai.file',
                    (string) $fileId, 'legal-source-read:' . bin2hex(random_bytes(16)), $requestId,
                );
            }
            $scope = FileOwnershipGuard::scope(
                UsageAttribution::fromIdentity($context['context'], 'file', 'sand_ai.file.read', 'file'),
            );
            $result = $gateway->resolve($context['environment_id'], $scope, $sources);
            $signedContext = trim(substr(trim((string) $request->header('authorization', '')), 7));
            foreach ($decisions as $fileId => $decision) {
                $authorizer->revalidate(
                    $decision['authorization_id'], $signedContext, $context['context'],
                    'sand_ai.file.read', 'sand_ai.file', (string) $fileId,
                    $request->getRemoteIp(), $requestId,
                );
            }
            return ApiResponder::success($result, $requestId)->header('Cache-Control', 'no-store');
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $requestId)->header('Cache-Control', 'no-store');
        }
    }
}
