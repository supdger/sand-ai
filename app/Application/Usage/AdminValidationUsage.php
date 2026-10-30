<?php

declare(strict_types=1);

namespace app\Application\Usage;

use app\Api\Support\ApiProblem;
use app\Application\Budget\BudgetDecimal;
use think\db\ConnectionInterface;
use think\facade\Db;

/** Immutable platform-call facts; never supplies a workload identity or a cash bill. */
final class AdminValidationUsage
{
    public function beginDraft(ConnectionInterface $connection, array $revision, array $claim, array $capture, int $actor, array $encryptedConfig): int
    {
        $snapshot = $capture['snapshot'];
        $bodySha = null;
        $settings = is_array($snapshot['deployment']['config'] ?? null) ? $snapshot['deployment']['config'] : [];
        if ($snapshot['model']['type'] === 'chat') {
            $settings = (new \app\Application\Onboarding\DeploymentDraftConfigurationPolicy())->normalize($settings);
            $config = (new \app\Infrastructure\Provider\ProviderSecretDecryptor())->decrypt($encryptedConfig);
            $body = (new \app\Infrastructure\Provider\OpenAiCompatibleChatProvider())->requestBody($config,
                $snapshot['deployment']['remote_model'], [['role' => 'user', 'content' => 'Reply with OK.']],
                new \app\Application\Gateway\ChatCompletionOptions(max(1, min(1024, $settings['max_output_tokens'])), $settings['reasoning_effort'] ?? null));
            $bodySha = \app\Application\Gateway\ProviderRequestBodyBuilder::digest($body);
        }
        $calledAt = gmdate('Y-m-d H:i:s');
        $price = $snapshot['model']['type'] === 'image_ocr'
            ? CallStartPricingSnapshot::unpriced($calledAt)
            : ($snapshot['model']['type'] === 'rerank'
            ? \app\Application\Knowledge\KnowledgeProfessionalRerankPrice::resolve((int) $snapshot['provider']['id'], (int) $snapshot['model']['id'],
                \app\Application\Knowledge\KnowledgeProfessionalRerankPrice::unitForDriver((string) $snapshot['provider']['adapter']), $calledAt)
            : CallStartPricingSnapshot::resolve(new PriceResolver(), (int) $snapshot['provider']['id'], (int) $snapshot['model']['id'], (string) $snapshot['model']['type'], $calledAt));
        return $this->insertAttempt($connection, [
            'target_kind' => 'deployment', 'config_revision_id' => (int) $revision['id'],
            'deployment_id' => (int) $revision['resource_id'], 'deployment_revision' => (int) $revision['revision'],
            'provider_id' => (int) $snapshot['provider']['id'], 'model_id' => (int) $snapshot['model']['id'],
            'attempt_id' => $claim['attempt_id'], 'admin_actor_id' => $actor,
            'capability_code' => $snapshot['model']['type'], 'service_action' => 'sand_ai.setup.deployment.verify',
            'request_sha256' => $claim['request_sha256'], 'idempotency_hmac' => $claim['idempotency_hmac'],
            'execution_snapshot_sha256' => $capture['sha256'], 'execution_snapshot' => $snapshot,
            'target_hmac' => null, 'body_sha256' => $bodySha, 'settings_snapshot' => $settings,
        ] + $price->persistence());
    }

    /** Normalized temporary connection fingerprint includes credential changes, without storing credentials. */
    public function temporaryRequest(array $connection, string $remoteModel, int $maxOutput, ?int $inputCeiling, string $key): array
    {
        $keyHmac = (new \app\Application\Onboarding\DeploymentVerificationRequestPolicy())->keyHmac($key);
        $fingerprint = (new \app\Infrastructure\Provider\ProviderExecutionConfigFingerprint())->fromPlaintext('openai_compatible', [
            'base_url' => $connection['endpoint']->baseUrl, 'api_key' => $connection['api_key'], 'extra_headers' => $connection['extra_headers'],
        ]);
        $targetHmac = hash_hmac('sha256', self::digest(['adapter' => 'openai_compatible', 'remote_model' => $remoteModel]), $fingerprint['hmac_sha256']);
        $body = \app\Application\Gateway\ProviderRequestBodyBuilder::chat($remoteModel,
            [['role' => 'user', 'content' => 'Reply with OK.']], new \app\Application\Gateway\ChatCompletionOptions($maxOutput));
        $settings = ['max_output_tokens' => $maxOutput, 'input_token_ceiling' => $inputCeiling];
        return [
            'idempotency_hmac' => $keyHmac, 'target_hmac' => $targetHmac,
            'body_sha256' => \app\Application\Gateway\ProviderRequestBodyBuilder::digest($body),
            'request_sha256' => self::digest(['target' => $targetHmac, 'body' => \app\Application\Gateway\ProviderRequestBodyBuilder::digest($body), 'settings' => $settings]),
            'settings_snapshot' => $settings,
        ];
    }

    /** Serializes the same-key decision; a pending or unknown call is never sent again. */
    public function beginTemporary(array $request, int $actor): array
    {
        return Db::connect('pgsql')->transaction(function (ConnectionInterface $connection) use ($request, $actor): array {
            $connection->query('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['admin-verify:' . $actor . ':' . $request['idempotency_hmac']]);
            $existing = $this->attemptQuery($connection)->where('target_kind', 'temporary_connection')->where('admin_actor_id', $actor)->where('idempotency_hmac', $request['idempotency_hmac'])->find();
            if (is_array($existing)) {
                if (!hash_equals((string) $existing['request_sha256'], $request['request_sha256'])) {
                    throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'Verification key belongs to a different request or connection');
                }
                return ['id' => (int) $existing['id'], 'replay' => true];
            }
            $calledAt = gmdate('Y-m-d H:i:s');
            $id = $this->insertAttempt($connection, [
                'target_kind' => 'temporary_connection', 'config_revision_id' => null, 'deployment_id' => null,
                'deployment_revision' => null, 'provider_id' => null, 'model_id' => null,
                'attempt_id' => bin2hex(random_bytes(16)), 'admin_actor_id' => $actor, 'capability_code' => 'chat',
                'service_action' => 'sand_ai.onboarding.verify', 'execution_snapshot_sha256' => $request['request_sha256'],
                'execution_snapshot' => ['version' => 'temporary_validation_execution@1', 'target_hmac' => $request['target_hmac'], 'body_sha256' => $request['body_sha256'], 'settings' => $request['settings_snapshot']],
            ] + $request + CallStartPricingSnapshot::unpriced($calledAt, 'temporary_target_unregistered')->persistence());
            return ['id' => $id, 'replay' => false];
        });
    }

    /** Response facts commit before any mutable configuration finalization. */
    public function receipt(int $id, string $outcome, ?array $usage = null, ?string $errorCode = null): array
    {
        if (!in_array($outcome, ['response_known', 'not_dispatched', 'ambiguous'], true)
            || ($errorCode !== null && preg_match('/\ASAND_AI_[A-Z0-9_]{1,100}\z/', $errorCode) !== 1)) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Platform validation outcome is invalid');
        }
        $measured = $usage['usage_measured'] ?? false;
        $input = $usage['input_tokens'] ?? null;
        $output = $usage['output_tokens'] ?? null;
        $rerankUnit = $usage['rerank_meter_code'] ?? null;
        $rerankQuantity = $usage['rerank_quantity'] ?? null;
        $professional = in_array($rerankUnit, ['rerank_search_units', 'rerank_tokens'], true);
        if ($professional && (!is_bool($measured) || $measured !== ($rerankQuantity !== null)
            || ($rerankQuantity !== null && (!is_int($rerankQuantity) || $rerankQuantity < 0))
            || $input !== null || $output !== null)) throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Professional validation quantity is invalid');
        if (!$professional && (!is_bool($measured) || ($measured && (!is_int($input) || !is_int($output) || $input < 0 || $output < 0 || $input > 2147483647 - $output))
            || (!$measured && ($input !== null || $output !== null)) || !in_array($outcome, ['response_known', 'not_dispatched', 'ambiguous'], true)
            || ($outcome !== 'response_known' && $measured) || ($errorCode !== null && preg_match('/\ASAND_AI_[A-Z0-9_]{1,100}\z/', $errorCode) !== 1))) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Platform validation receipt is invalid');
        }
        $facts = ['attempt_id' => $id, 'outcome_state' => $outcome, 'usage_measured' => $measured,
            'input_tokens' => $measured && !$professional ? $input : null, 'output_tokens' => $measured && !$professional ? $output : null,
            'total_tokens' => $measured && !$professional ? $input + $output : null, 'error_code' => $errorCode];
        if ($professional) $facts += ['rerank_meter_code' => $rerankUnit, 'rerank_quantity' => $rerankQuantity];
        return Db::connect('pgsql')->transaction(function (ConnectionInterface $connection) use ($id, $facts): array {
            $attempt = $this->attemptQuery($connection)->where('id', $id)->lock(true)->find();
            if (is_array($attempt) && $attempt['capability_code'] === 'rerank') {
                $snapshot = is_string($attempt['execution_snapshot']) ? json_decode($attempt['execution_snapshot'], true, 32, JSON_THROW_ON_ERROR) : $attempt['execution_snapshot'];
                $unit = \app\Application\Knowledge\KnowledgeProfessionalRerankPrice::unitForDriver((string) ($snapshot['provider']['adapter'] ?? ''));
                if (isset($facts['rerank_meter_code']) && $facts['rerank_meter_code'] !== $unit) throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Professional validation meter changed');
                $facts += ['rerank_meter_code' => $unit, 'rerank_quantity' => null];
                if ($facts['input_tokens'] !== null || $facts['output_tokens'] !== null || $facts['total_tokens'] !== null
                    || $facts['usage_measured'] !== ($facts['rerank_quantity'] !== null)
                    || ($facts['outcome_state'] !== 'response_known' && $facts['usage_measured'])) throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Professional validation facts are invalid');
            } elseif (isset($facts['rerank_meter_code'])) {
                throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Professional validation belongs to another capability');
            }
            if (!is_array($attempt)) throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Platform validation attempt is unavailable');
            $existing = $connection->table('sand_ai_admin_validation_receipt')->where('attempt_id', $id)->find();
            $sha = self::digest($facts);
            if (is_array($existing)) {
                if (!hash_equals((string) $existing['evidence_sha256'], $sha)) throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'Platform receipt differs from the immutable outcome');
            } else {
                $connection->table('sand_ai_admin_validation_receipt')->insert($facts + ['evidence_sha256' => $sha, 'create_time' => gmdate('Y-m-d H:i:s')]);
                \app\Infrastructure\Persistence\Model\AuditLog::create(['actor_type' => 'admin', 'actor_ref' => (string) $attempt['admin_actor_id'], 'action' => 'platform.validation.recorded', 'resource_type' => 'admin_validation_attempt', 'resource_id' => $id, 'summary' => 'Recorded platform model validation outcome', 'context' => ['outcome' => $facts['outcome_state'], 'receipt_sha256' => $sha, 'cash_bill_verified' => false]]);
            }
            if ($facts['outcome_state'] === 'response_known') (new UsageMeter())->meterAdminValidation($attempt, $facts);
            return $this->projection($id);
        });
    }

    public function forRevision(int $configurationId): array
    {
        $attempt = Db::connect('pgsql')->table('sand_ai_admin_validation_attempt')->where('config_revision_id', $configurationId)->order('id', 'desc')->find();
        return $this->projection(is_array($attempt) ? (int) $attempt['id'] : null);
    }

    public function projection(?int $id): array
    {
        $receipt = $id === null ? null : Db::connect('pgsql')->table('sand_ai_admin_validation_receipt')->where('attempt_id', $id)->find();
        $event = $id === null ? null : Db::connect('pgsql')->table('sand_ai_usage_event')->where('admin_validation_attempt_id', $id)->find();
        $state = $id === null ? 'untracked' : (!is_array($receipt) ? 'pending' : ($receipt['outcome_state'] === 'response_known' ? 'recorded' : $receipt['outcome_state']));
        if ($state === 'recorded' && !is_array($event)) throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Platform usage event is unavailable');
        return ['scope_kind' => 'platform_admin', 'attempt_id' => $id, 'usage_event_id' => is_array($event) ? (int) $event['id'] : null,
            'state' => $state, 'usage_state' => $event['usage_state'] ?? null, 'pricing_state' => $event['pricing_state'] ?? null,
            'price_revision_id' => isset($event['price_revision_id']) ? (int) $event['price_revision_id'] : null,
            'metered_amount' => isset($event['metered_amount']) ? BudgetDecimal::normalize((string) $event['metered_amount'], false) : null,
            'currency' => $event['currency'] ?? null, 'reconciliation_state' => 'unreconciled', 'cash_billed_amount' => null];
    }

    public function matchesEvidence(array $evidence, int $deploymentId, int $revision): bool
    {
        $id = $evidence['platform_attempt_id'] ?? null;
        $eventId = $evidence['usage_event_id'] ?? null;
        if (!is_int($id) || $id < 1 || !is_int($eventId) || $eventId < 1) return false;
        $db = Db::connect('pgsql');
        $attempt = $db->table('sand_ai_admin_validation_attempt')->where('id', $id)->find();
        $receipt = $db->table('sand_ai_admin_validation_receipt')->where('attempt_id', $id)->find();
        $event = $db->table('sand_ai_usage_event')->where('admin_validation_attempt_id', $id)->where('id', $eventId)->find();
        if (!is_array($attempt) || !is_array($receipt) || !is_array($event) || $receipt['outcome_state'] !== 'response_known' || $receipt['error_code'] !== null
            || (string) $attempt['admin_actor_id'] !== ($evidence['validated_by'] ?? null)) return false;
        if ($attempt['target_kind'] === 'deployment') {
            if ((int) $attempt['deployment_id'] !== $deploymentId || (int) $attempt['deployment_revision'] !== $revision) return false;
        } else {
            $binding = $db->table('sand_ai_admin_validation_draft_binding')->where('attempt_id', $id)->find();
            $config = is_array($binding) ? $db->table('sand_ai_config_revision')->where('id', $binding['config_revision_id'])->find() : null;
            if (!is_array($config) || $config['resource_type'] !== 'model_deployment' || (int) $config['resource_id'] !== $deploymentId || (int) $config['revision'] !== $revision) return false;
        }
        $measured = in_array($receipt['usage_measured'], [true, 1, '1', 't'], true);
        if ($measured !== ($evidence['usage_measured'] ?? null)) return false;
        if ($attempt['capability_code'] === 'rerank') {
            if (($receipt['rerank_meter_code'] ?? null) !== ($evidence['rerank_meter_code'] ?? null)
                || ($receipt['rerank_quantity'] === null ? ($evidence['rerank_quantity'] ?? null) !== null
                    : !is_int($evidence['rerank_quantity'] ?? null) || (string) $receipt['rerank_quantity'] !== (string) $evidence['rerank_quantity'])) return false;
            foreach (['input_tokens', 'output_tokens', 'total_tokens'] as $field) {
                if ($receipt[$field] !== null || !array_key_exists($field, $evidence) || $evidence[$field] !== null) return false;
            }
            return true;
        }
        foreach (['input_tokens','output_tokens','total_tokens'] as $field) {
            if ($measured ? (int) $receipt[$field] !== ($evidence[$field] ?? null) : (!array_key_exists($field, $evidence) || $evidence[$field] !== null)) return false;
        }
        return true;
    }

    public function temporaryReplay(int $id): array
    {
        $projection = $this->projection($id);
        $receipt = Db::connect('pgsql')->table('sand_ai_admin_validation_receipt')->where('attempt_id', $id)->find();
        if (!is_array($receipt) || $receipt['outcome_state'] !== 'response_known' || $receipt['error_code'] !== null) {
            throw new ApiProblem('SAND_AI_DEPLOYMENT_VERIFICATION_AMBIGUOUS', 'Existing verification outcome cannot be sent again; inspect its platform usage', true);
        }
        $binding = Db::connect('pgsql')->table('sand_ai_admin_validation_draft_binding')->json(['draft_result'], true)->where('attempt_id', $id)->find();
        if (!is_array($binding)) throw new ApiProblem('SAND_AI_DEPLOYMENT_VERIFICATION_AMBIGUOUS', 'Provider result was recorded but the draft was not finalized; do not resend', true);
        return ['state' => 'tested', 'usage_measured' => in_array($receipt['usage_measured'], [true, 1, '1', 't'], true),
            'usage' => ['input_tokens' => isset($receipt['input_tokens']) ? (int) $receipt['input_tokens'] : null, 'output_tokens' => isset($receipt['output_tokens']) ? (int) $receipt['output_tokens'] : null],
            'cost_tracking_state' => 'recorded', 'draft' => $binding['draft_result'], 'platform_usage' => $projection, 'idempotent_replay' => true];
    }

    public function bindDraft(ConnectionInterface $connection, int $attemptId, int $configurationId, array $draft): void
    {
        $connection->table('sand_ai_admin_validation_draft_binding')->insert(['attempt_id' => $attemptId, 'config_revision_id' => $configurationId,
            'draft_result' => json_encode($draft, JSON_THROW_ON_ERROR), 'create_time' => gmdate('Y-m-d H:i:s')]);
    }

    public static function digest(array $value): string
    {
        return hash('sha256', json_encode(self::canonical($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        return array_map(self::canonical(...), $value);
    }

    private function attemptQuery(ConnectionInterface $connection): \think\db\Query
    {
        return $connection->table('sand_ai_admin_validation_attempt')->json(['execution_snapshot', 'call_start_price_snapshot', 'settings_snapshot'], true);
    }

    private function insertAttempt(ConnectionInterface $connection, array $values): int
    {
        if (($values['admin_actor_id'] ?? 0) < 1) throw new ApiProblem('SAND_AI_AUTHENTICATION_FAILED', 'An authenticated administrator is required');
        $values['execution_snapshot_canonical'] = json_encode(self::canonical($values['execution_snapshot']), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        foreach (['execution_snapshot', 'call_start_price_snapshot', 'settings_snapshot'] as $field) $values[$field] = json_encode($values[$field], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $values['create_time'] = gmdate('Y-m-d H:i:s');
        return (int) $connection->table('sand_ai_admin_validation_attempt')->insertGetId($values);
    }
}
