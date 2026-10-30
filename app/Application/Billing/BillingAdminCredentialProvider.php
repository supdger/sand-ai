<?php
declare(strict_types=1);
namespace app\Application\Billing;
use app\Api\Support\ApiProblem;
interface BillingAdminCredentialProvider {
    public function secretFor(int $providerId, string $purpose): string;
    public function organizationFor(int $providerId): string;
}
final class MissingBillingAdminCredentialProvider implements BillingAdminCredentialProvider {
    public function secretFor(int $providerId, string $purpose): string { throw new ApiProblem('SAND_AI_BILLING_CONFIG_INVALID', 'A dedicated billing_admin credential is required'); }
    public function organizationFor(int $providerId): string { throw new ApiProblem('SAND_AI_BILLING_CONFIG_INVALID', 'A billing organization is required'); }
}
