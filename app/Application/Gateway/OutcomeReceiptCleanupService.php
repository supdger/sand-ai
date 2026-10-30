<?php

declare(strict_types=1);

namespace app\Application\Gateway;

use app\Api\Support\ApiProblem;

/** Internal retention cleanup; no public API or completion content is involved. */
final class OutcomeReceiptCleanupService
{
    public function __construct(
        private readonly OutcomeReceiptCleanupStore $store,
        private readonly OutcomeReceiptCleanupConfig $config,
        private readonly string $owner,
        private readonly ?\Closure $clock = null,
    ) {
        if (preg_match('/\A[a-z0-9:_-]{1,128}\z/', $owner) !== 1) {
            throw new ApiProblem('SAND_AI_RECEIPT_UNAVAILABLE', 'Outcome receipt cleanup owner is invalid');
        }
    }

    public function run(int $limit): int
    {
        if ($limit < 1 || $limit > 200) {
            throw new ApiProblem('SAND_AI_RECEIPT_UNAVAILABLE', 'Outcome receipt cleanup batch is not configured');
        }
        if (!$this->config->enabled) {
            return 0;
        }
        $timestamp = $this->clock === null ? time() : ($this->clock)();
        if (!is_int($timestamp) || $timestamp < 1) {
            throw new ApiProblem('SAND_AI_RECEIPT_UNAVAILABLE', 'Outcome receipt cleanup clock is invalid');
        }
        $now = gmdate('Y-m-d H:i:s', $timestamp);
        $terminalGraceBefore = gmdate('Y-m-d H:i:s', $timestamp - $this->config->graceSeconds);
        $staleBefore = gmdate('Y-m-d H:i:s', $timestamp - $this->config->leaseSeconds);
        $destroyed = 0;
        foreach ($this->store->claimExpired($now, $terminalGraceBefore, $staleBefore, $this->owner, $limit) as $receipt) {
            if (!$this->store->destroyClaimedWithAudit($receipt['id'], $this->owner, $receipt['fence_version'], $now)) {
                continue;
            }
            $destroyed++;
        }
        return $destroyed;
    }
}
