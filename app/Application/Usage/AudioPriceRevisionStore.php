<?php
declare(strict_types=1);
namespace app\Application\Usage;
interface AudioPriceRevisionStore { /** @return list<array<string,mixed>> */ public function published(int $providerId,int $modelId,string $capabilityCode): array; /** @return list<array<string,mixed>> */ public function rates(int $revisionId): array; }
