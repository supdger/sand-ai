<?php

declare(strict_types=1);

namespace app\Application\Billing;

use app\Api\Support\ApiProblem;
use think\facade\Db;

/** Reads a committed original operation; absence never proves that it was not sent. */
final class BillingOperationRecovery
{
    public function __construct(private readonly ?BillingUsageTrace $traces = null)
    {
    }

    /** Digest only the original public JSON input, never persist its contents here. */
    public static function inputSha(array $input): string
    {
        $sort = static function (mixed $value) use (&$sort): mixed {
            if (!is_array($value)) return $value;
            if (!array_is_list($value)) ksort($value, SORT_STRING);
            foreach ($value as &$member) $member = $sort($member);
            unset($member);
            return $value;
        };
        return hash('sha256', json_encode($sort($input), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS));
    }

    public static function syncInput(array $input): array
    {
        return array_intersect_key($input, array_flip(['provider_id', 'period_start', 'period_end', 'idempotency_key']));
    }

    /** Exact query boundary. The actor always comes from the authenticated controller. */
    public function read(string $operation, array $query, int $adminId): array
    {
        if ($adminId < 1) throw new ApiProblem('SAND_AI_AUTHENTICATION_FAILED', 'Authenticated administrator required');
        if (!in_array($operation, ['import', 'sync', 'review'], true)) $this->invalid();
        $targetField = $operation === 'review' ? 'billing_line_id' : 'provider_id';
        if (count($query) !== 3 || array_diff(array_keys($query), [$targetField, 'request_key', 'input_sha256']) !== []) $this->invalid();
        $target = BillingPositiveId::parse($query[$targetField] ?? null, $targetField, 'SAND_AI_BILLING_RECOVERY_INVALID');
        $key = $query['request_key'] ?? null;
        $digest = $query['input_sha256'] ?? null;
        if (!is_string($key) || trim($key) !== $key || $key === '' || strlen($key) > 128
            || preg_match('//u', $key) !== 1 || preg_match('/[\p{Cc}]/u', $key) === 1
            || !is_string($digest) || preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1) $this->invalid();
        $result = ['operation' => $operation, 'actor_id' => $adminId, 'request_key' => $key,
            'input_sha256' => $digest, 'state' => 'unknown', 'billing_import' => null, 'review' => null, 'sync_status' => null, 'rejection' => null];
        $rejection = (new BillingOperationReceipt())->rejected($operation, $target, $key, $digest, $adminId);
        if ($rejection !== null) {
            $result['state'] = 'rejected';
            $result['rejection'] = $rejection;
            return $result;
        }
        if ($operation === 'review') {
            $store = new PostgresBillingReviewStore($this->traces);
            $original = $store->replay($adminId, $target, $key);
            if ($original === null || (int) $original['admin_id'] !== $adminId
                || (int) $original['billing_line_id'] !== $target || $original['idempotency_key'] !== $key) return $result;
            $proof = $this->proof('billing.review', 'billing_review', (int) $original['id'], $adminId, $key);
            if (!$this->matches($proof, $digest, (string) $original['fingerprint'])) return $result;
            $result['review'] = $store->read((int) $original['id']);
            $result['state'] = 'completed';
            return $result;
        }
        $importStore = new PostgresBillingImportStore($this->traces);
        $original = $importStore->findImport($target, $key);
        $importProof = $original === null ? null : $this->proof($operation === 'sync' ? 'billing.sync.import' : 'billing.import.create',
            'billing_import', (int) $original['id'], $adminId, $key);
        $importMatches = $original !== null && (int) $original['provider_id'] === $target
            && $original['idempotency_key'] === $key && $original['state'] === 'imported'
            && $this->matches($importProof, $digest, (string) $original['fingerprint']);
        $noDataProof = $operation === 'sync' ? $this->proof('billing.sync.no_data', 'provider', $target, $adminId, $key) : null;
        $noDataMatches = $this->matches($noDataProof, $digest);
        if ($importMatches && $noDataMatches) $this->conflict();
        if ($importMatches) {
            $result['billing_import'] = $importStore->read((int) $original['id']);
            $result['state'] = 'completed';
            $result['sync_status'] = $operation === 'sync' ? 'imported' : null;
        } elseif ($noDataMatches) {
            $result['state'] = 'completed';
            $result['sync_status'] = 'no_data';
        }
        return $result;
    }

    private function proof(string $action, string $type, int $id, int $adminId, string $key): ?array
    {
        // The immutable operation and its audit were committed in the same original transaction.
        $rows = Db::connect('pgsql')->query(
            'SELECT context FROM sand_ai_audit_log WHERE actor_type=? AND actor_ref=? AND action=? AND resource_type=? AND resource_id=? AND context->>\'request_key\'=? ORDER BY id DESC LIMIT 2',
            ['admin', (string) $adminId, $action, $type, $id, $key],
        );
        $proof = null;
        foreach ($rows as $row) {
            $context = is_string($row['context']) ? json_decode($row['context'], true, 32, JSON_THROW_ON_ERROR) : $row['context'];
            if (!is_array($context) || ($context['request_key'] ?? null) !== $key) return null;
            if ($proof !== null && ($context['input_sha256'] ?? null) !== ($proof['input_sha256'] ?? null)) $this->conflict();
            $proof = $context;
        }
        return $proof;
    }

    private function matches(?array $proof, string $digest, ?string $fingerprint = null): bool
    {
        if ($proof === null || !is_string($proof['input_sha256'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/D', $proof['input_sha256']) !== 1) return false;
        if (!hash_equals($proof['input_sha256'], $digest)) $this->conflict();
        if ($fingerprint !== null && (!is_string($proof['billing_fingerprint'] ?? null)
            || !hash_equals($fingerprint, $proof['billing_fingerprint']))) return false;
        return true;
    }

    private function invalid(): never
    {
        throw new ApiProblem('SAND_AI_BILLING_RECOVERY_INVALID', 'Original target, request key and input digest required');
    }

    private function conflict(): never
    {
        throw new ApiProblem('SAND_AI_BILLING_RECOVERY_CONFLICT', 'Original billing key has different input or inconsistent committed evidence');
    }
}
