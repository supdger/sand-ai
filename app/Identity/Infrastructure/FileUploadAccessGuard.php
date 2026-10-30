<?php

declare(strict_types=1);

namespace app\Identity\Infrastructure;

use app\Identity\Contract\IdentityContext;
use support\Request;

/** Authorizes a current workload client before private upload creates a file. */
final class FileUploadAccessGuard
{
    public function __construct(private readonly ?HostSandIamInvocationAuthorizer $authorizer = null)
    {
    }

    /** @return array<string,mixed> */
    public function authorize(Request $request, IdentityContext $identity, string $requestId): array
    {
        return ($this->authorizer ?? new HostSandIamInvocationAuthorizer())->authorize(
            $request, $identity, 'sand_ai.file.write', 'sand_iam.workload_client',
            $identity->workloadClientId, 'file-upload:' . bin2hex(random_bytes(16)), $requestId,
        );
    }
}
