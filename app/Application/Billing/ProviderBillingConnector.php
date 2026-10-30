<?php
declare(strict_types=1);
namespace app\Application\Billing;
interface ProviderBillingConnector { public function providerCode(): string; /** @return list<array<string,mixed>> */ public function fetch(array $request): array; }
