<?php

declare(strict_types=1);

namespace app\Application\Usage;

use app\Infrastructure\Persistence\Model\PriceRate;
use app\Infrastructure\Persistence\Model\PriceRevision;

final class PriceResolver
{
    public function resolve(int $providerId, int $modelId, string $capabilityCode, string $calledAt): ?ResolvedTokenPrice
    {
        $revisions = PriceRevision::where('provider_id', $providerId)
            ->where('model_id', $modelId)
            ->where('capability_code', $capabilityCode)
            ->where('state', 'published')
            ->select()
            ->toArray();
        $revision = (new PriceRevisionSelector())->select($revisions, $calledAt);
        if ($revision === null) {
            return null;
        }

        return $this->resolved($revision);
    }

    public function resolveRevision(int $revisionId, int $providerId, int $modelId, string $capabilityCode, string $calledAt): ?ResolvedTokenPrice
    {
        $revision = PriceRevision::where('id', $revisionId)
            ->where('provider_id', $providerId)
            ->where('model_id', $modelId)
            ->where('capability_code', $capabilityCode)
            ->where('state', 'published')
            ->find();
        if ($revision === null) {
            return null;
        }
        $row = $revision->toArray();
        if ((string) $row['effective_from'] > $calledAt
            || (($row['effective_until'] ?? null) !== null && (string) $row['effective_until'] <= $calledAt)) {
            return null;
        }

        return $this->resolved($row);
    }

    /** @param array<string,mixed> $revision */
    private function resolved(array $revision): ?ResolvedTokenPrice
    {
        $rates = PriceRate::where('price_revision_id', (int) $revision['id'])->select()->toArray();
        $ratesByMeter = [];
        foreach ($rates as $rate) {
            $ratesByMeter[(string) $rate['meter_code']] = [
                'unit_price' => (string) $rate['unit_price'],
                'per_quantity' => (int) $rate['per_quantity'],
            ];
        }
        if (!isset($ratesByMeter['input_tokens'], $ratesByMeter['output_tokens'])) {
            return null;
        }

        return new ResolvedTokenPrice(
            (int) $revision['id'],
            (string) $revision['currency'],
            $ratesByMeter['input_tokens'],
            $ratesByMeter['output_tokens'],
            (string) $revision['effective_from'],
            isset($revision['effective_until']) ? (string) $revision['effective_until'] : null,
        );
    }
}
