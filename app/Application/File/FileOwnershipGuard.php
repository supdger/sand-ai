<?php

declare(strict_types=1);

namespace app\Application\File;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\RequestIdempotencyKey;
use app\Application\Usage\UsageAttribution;
use app\Infrastructure\Persistence\Model\AiFile;

/** Keeps direct file access bound to the identity frozen when it was uploaded. */
final class FileOwnershipGuard
{
    public static function scope(UsageAttribution $attribution): string
    {
        return RequestIdempotencyKey::trustedScopeForAttribution($attribution);
    }

    public function assert(AiFile $file, string $currentScope): void
    {
        $storedScope = $file->owner_scope;
        $snapshot = is_object($file->owner_attribution) ? (array) $file->owner_attribution : $file->owner_attribution;
        if (!is_string($storedScope) || preg_match('/\A[a-f0-9]{64}\z/', $storedScope) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/', $currentScope) !== 1 || !is_array($snapshot)) {
            $this->notFound();
        }
        try {
            $attribution = UsageAttribution::fromCurrentPersisted(
                $snapshot, (int) $file->environment_id, 'file', 'sand_ai.file.write',
            );
            $expected = self::scope($attribution);
        } catch (\InvalidArgumentException) {
            $this->notFound();
        }
        if (!hash_equals($expected, $storedScope) || !hash_equals($storedScope, $currentScope)) {
            $this->notFound();
        }
    }

    private function notFound(): never
    {
        throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'File not found');
    }
}
