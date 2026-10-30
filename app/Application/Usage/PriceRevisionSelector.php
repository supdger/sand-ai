<?php

declare(strict_types=1);

namespace app\Application\Usage;

/** Selects the newest published revision effective at a call time. */
final class PriceRevisionSelector
{
    /** @param list<array<string, mixed>> $revisions @return array<string, mixed>|null */
    public function select(array $revisions, string $calledAt): ?array
    {
        $effective = array_filter($revisions, static function (array $revision) use ($calledAt): bool {
            if (($revision['state'] ?? null) !== 'published' || !is_string($revision['effective_from'] ?? null)) {
                return false;
            }

            return $revision['effective_from'] <= $calledAt
                && (!is_string($revision['effective_until'] ?? null) || $revision['effective_until'] === '' || $revision['effective_until'] > $calledAt);
        });
        if ($effective === []) {
            return null;
        }

        usort($effective, static fn (array $left, array $right): int => [
            $right['effective_from'],
            (int) ($right['id'] ?? 0),
        ] <=> [
            $left['effective_from'],
            (int) ($left['id'] ?? 0),
        ]);

        return $effective[0];
    }
}
