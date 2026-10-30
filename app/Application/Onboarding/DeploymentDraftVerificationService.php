<?php

declare(strict_types=1);

namespace app\Application\Onboarding;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\ChatCompletionOptions;
use app\Application\Gateway\ProviderSendBoundary;
use app\Application\Audio\Speech\OpenAiCompatibleSpeechSynthesisDriver;
use app\Application\Audio\Speech\SpeechSynthesisRequest;
use app\Infrastructure\Persistence\Model\AuditLog;
use app\Infrastructure\Persistence\Model\ConfigRevision;
use app\Infrastructure\Provider\OpenAiCompatibleChatProvider;
use app\Infrastructure\Provider\ChatProviderFailurePolicy;
use app\Infrastructure\Provider\ProviderSecretDecryptor;
use app\Application\Usage\AdminValidationUsage;
use app\Infrastructure\Provider\MeasuredChatFailure;
use think\db\ConnectionInterface;
use think\facade\Db;

/** Verifies one existing draft revision without accepting a temporary connection. */
final class DeploymentDraftVerificationService
{
    public function __construct(
        private readonly DeploymentProcessingPolicy $processingPolicy = new DeploymentProcessingPolicy(),
        private readonly DeploymentValidationEvidence $evidence = new DeploymentValidationEvidence(),
        private readonly DeploymentVerificationRequestPolicy $requestPolicy = new DeploymentVerificationRequestPolicy(),
        private readonly DeploymentExecutionSnapshot $executionSnapshot = new DeploymentExecutionSnapshot(),
        private readonly ProviderSecretDecryptor $decryptor = new ProviderSecretDecryptor(),
        private readonly ?OpenAiCompatibleChatProvider $provider = null,
    ) {
    }

    /** @return array<string,mixed> */
    public function read(int $deploymentId, int $revision): array
    {
        if ($deploymentId < 1 || $revision < 1) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Deployment revision not found');
        }
        // Commit stale recovery before checking any other mutable row: a
        // deleted deployment must not roll back the exact revision's terminal
        // ambiguous decision.
        $this->recoverExpiredClaim($deploymentId, $revision);
        return Db::connect('pgsql')->transaction(function (ConnectionInterface $connection) use ($deploymentId, $revision): array {
            $configuration = $connection->table('sand_ai_config_revision')->json(['payload'], true)
                ->where('resource_type', 'model_deployment')->where('resource_id', $deploymentId)
                ->where('revision', $revision)->whereNull('delete_time')->lock(true)->find();
            if (!is_array($configuration)) {
                throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Deployment revision not found');
            }
            $deployment = $connection->table('sand_ai_model_deployment')->where('id', $deploymentId)->whereNull('delete_time')->find();
            if (!is_array($deployment)) {
                throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Deployment revision not found');
            }
            return $this->summary($configuration, $deploymentId, $revision, false);
        });
    }

    /** Read one administrator's original attempt without dispatching or borrowing a later key's state. @return array<string,mixed> */
    public function readByRequestKey(int $deploymentId, int $revision, string $idempotencyKey, string $actorRef): array
    {
        if ($deploymentId < 1 || $revision < 1 || filter_var($actorRef, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'deployment_id, revision and an authenticated administrator are required');
        }
        $hmac = $this->requestPolicy->keyHmac($idempotencyKey);
        $this->recoverExpiredClaim($deploymentId, $revision);
        return Db::connect('pgsql')->transaction(function (ConnectionInterface $connection) use ($deploymentId, $revision, $idempotencyKey, $hmac, $actorRef): array {
            $configuration = $connection->table('sand_ai_config_revision')->json(['payload'], true)
                ->where('resource_type', 'model_deployment')->where('resource_id', $deploymentId)
                ->where('revision', $revision)->whereNull('delete_time')->lock(true)->find();
            if (!is_array($configuration)) {
                throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Deployment revision not found');
            }
            $attempt = $connection->table('sand_ai_admin_validation_attempt')
                ->where('config_revision_id', (int) $configuration['id'])
                ->where('idempotency_hmac', $hmac)->where('admin_actor_id', (int) $actorRef)->find();
            if (!is_array($attempt) || (int) $attempt['deployment_id'] !== $deploymentId
                || (int) $attempt['deployment_revision'] !== $revision) {
                throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Original deployment verification attempt not found');
            }
            // Older attempts retain the canonical request digest, not plaintext processing inputs.
            // Reconstruct only a unique registered policy matching this original request.
            $policy = null;
            $matches = 0;
            foreach (['local_runtime', 'private_network', 'public_cloud'] as $location) {
                $request = $this->requestPolicy->fingerprint($deploymentId, $revision, $location, $idempotencyKey);
                if (hash_equals((string) $attempt['request_sha256'], $request['request_sha256'])) {
                    $policy = $this->processingPolicy->create($location);
                    $matches++;
                }
            }
            if ($matches !== 1 || $policy === null) {
                throw new ApiProblem('SAND_AI_CONFIGURATION_DRIFTED', 'Original verification processing policy cannot be confirmed');
            }
            $receipt = $connection->table('sand_ai_admin_validation_receipt')->where('attempt_id', (int) $attempt['id'])->find();
            if (is_array($receipt)) {
                return $this->attemptReplaySummary($configuration, $attempt, $receipt, $policy, $deploymentId, $revision);
            }
            return [
                'deployment_id' => $deploymentId, 'revision' => $revision, 'state' => 'verifying',
                'processing_location' => $policy['processing_location'],
                'compatible_data_egress_policies' => $policy['compatible_data_egress_policies'],
                'egress_source' => $policy['egress_source'], 'validation_evidence' => null,
                'failure' => null, 'idempotent_replay' => true,
                'platform_usage' => (new AdminValidationUsage())->projection((int) $attempt['id']),
            ];
        });
    }

    /** @return array<string,mixed> */
    public function verify(int $deploymentId, int $revision, string $processingLocation, string $idempotencyKey, string $actorRef): array
    {
        if ($deploymentId < 1 || $revision < 1 || filter_var($actorRef, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'deployment_id, revision and an authenticated administrator are required');
        }
        $policy = $this->processingPolicy->create($processingLocation);
        $request = $this->requestPolicy->fingerprint($deploymentId, $revision, $policy['processing_location'], $idempotencyKey);
        if ($this->recoverExpiredClaim($deploymentId, $revision)) {
            throw new ApiProblem('SAND_AI_DEPLOYMENT_VERIFICATION_AMBIGUOUS', 'Deployment verification claim expired with an ambiguous outcome', true);
        }
        $claim = Db::connect('pgsql')->transaction(function (ConnectionInterface $connection) use ($deploymentId, $revision, $policy, $request, $actorRef): array {
            $chain = $this->loadExact($connection, $deploymentId, $revision, true);
            $this->assertVerifiable($chain, $deploymentId, $revision);
            if (in_array($chain['provider']['adapter'] ?? null, ['local_runtime', 'local_ocr_runtime'], true) && $policy['processing_location'] !== 'local_runtime') {
                throw new ApiProblem('SAND_AI_DATA_EGRESS_FORBIDDEN', 'Local transcription verification requires local_runtime processing');
            }
            $payload = $this->payload($chain['revision']);
            $state = (string) $chain['revision']['state'];
            $prior = $connection->table('sand_ai_admin_validation_attempt')->json(['execution_snapshot'], true)->where('config_revision_id', (int) $chain['revision']['id'])->where('idempotency_hmac', $request['idempotency_hmac'])->find();
            if (is_array($prior)) {
                if (!hash_equals((string) $prior['request_sha256'], $request['request_sha256'])) throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'Verification key belongs to a different request');
                // Rebuild the stable execution/credential HMAC, not DNS resolution
                // or TTL. The old attempt cannot borrow another attempt's result.
                try {
                    $this->executionSnapshot->assertArrayMatches($prior['execution_snapshot'], (string) $prior['execution_snapshot_sha256'], $chain['provider'], $chain['model'], $chain['deployment']);
                } catch (ApiProblem) {
                    throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'Verification connection or execution inputs changed');
                }
                $receipt = $connection->table('sand_ai_admin_validation_receipt')->where('attempt_id', (int) $prior['id'])->find();
                if (!is_array($receipt)) throw new ApiProblem('SAND_AI_DEPLOYMENT_VERIFICATION_IN_PROGRESS', 'The original verification attempt has no terminal receipt; do not resend', true);
                return ['replay' => true, 'result' => $this->attemptReplaySummary($chain['revision'], $prior, $receipt, $policy, $deploymentId, $revision)];
            }
            $storedClaim = is_array($payload['deployment_verification_claim'] ?? null) ? $payload['deployment_verification_claim'] : [];
            if ($state === 'tested') {
                if (!$this->sameRequest($storedClaim, $request) || $this->processingPolicy->fromPayload($payload)['policy'] !== $policy) {
                    throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'Deployment verification idempotency key belongs to another request');
                }
                if ($this->evidence->fromPayload($payload, $deploymentId, $revision) === null) {
                    throw new ApiProblem('SAND_AI_CONFIGURATION_DRIFTED', 'Deployment verification evidence is incomplete');
                }
                return ['replay' => true, 'result' => $this->summary($chain['revision'], $deploymentId, $revision, true)];
            }
            if ($state === 'verifying') {
                if ($this->sameRequest($storedClaim, $request)) {
                    throw new ApiProblem('SAND_AI_DEPLOYMENT_VERIFICATION_IN_PROGRESS', 'Deployment verification is already in progress', true);
                }
                throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'Deployment verification idempotency key belongs to another request');
            }
            if ($state === 'verification_ambiguous') {
                throw new ApiProblem('SAND_AI_DEPLOYMENT_VERIFICATION_AMBIGUOUS', 'Deployment verification outcome is ambiguous; create a new draft revision', true);
            }
            if ($state !== 'draft') {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Deployment revision is not a draft');
            }
            $capture = $this->executionSnapshot->captureArrays($chain['provider'], $chain['model'], $chain['deployment']);
            $attemptId = bin2hex(random_bytes(16));
            $timeoutMs = max(1000, min(120000, (int) ($chain['provider']['timeout_ms'] ?? 10000)));
            $claimSeconds = max(90, intdiv($timeoutMs + 999, 1000) + 30);
            if (($chain['provider']['adapter'] ?? null) === 'local_runtime') {
                $localConfig = \app\Application\Audio\WhisperCppExecutionConfig::fromConfig((array) config('sand_ai_audio_execution.local_whisper_cpp', []));
                $claimSeconds = max($claimSeconds, $localConfig->timeoutSeconds * 2 + 90);
            }
            if (($chain['provider']['adapter'] ?? null) === 'local_ocr_runtime') {
                $claimSeconds = max($claimSeconds, intdiv(\app\Infrastructure\Parsing\LocalOcrExecutionConfig::configuration()['timeout_ms'] + 999, 1000) + 90);
            }
            $payload['deployment_verification_claim'] = [
                'version' => 'deployment_verification_claim@1',
                'attempt_id' => $attemptId,
                'claimed_at' => date('Y-m-d H:i:s'),
                'expires_at' => date('Y-m-d H:i:s', time() + $claimSeconds),
                'idempotency_hmac' => $request['idempotency_hmac'],
                'request_sha256' => $request['request_sha256'],
                'execution_snapshot_digest' => $capture['sha256'],
                'processing_policy' => $policy,
                'admin_actor_id' => (int) $actorRef,
            ];
            $this->saveRevision($chain['revision'], $payload, 'verifying');
            $meterAttempt = (new AdminValidationUsage())->beginDraft($connection, $chain['revision'], $payload['deployment_verification_claim'], $capture, (int) $actorRef, is_array($chain['provider']['encrypted_config'] ?? null) ? $chain['provider']['encrypted_config'] : []);
            return [
                'replay' => false,
                'attempt_id' => $attemptId,
                'meter_attempt_id' => $meterAttempt,
                'capture' => $capture,
                'encrypted_config' => is_array($chain['provider']['encrypted_config'] ?? null) ? $chain['provider']['encrypted_config'] : [],
                'remote_model' => (string) ($chain['deployment']['remote_model'] ?? ''),
                'timeout_ms' => (int) ($chain['provider']['timeout_ms'] ?? 10000),
            ];
        });
        if ($claim['replay'] === true) {
            return $claim['result'];
        }
        $nonChatVerification = in_array(
            $claim['capture']['snapshot']['model']['type'] ?? null,
            ['embedding', 'rerank', 'audio_speech', 'audio_transcription', 'image_ocr'],
            true,
        );

        $boundary = new ProviderSendBoundary();
        try {
            $verification = $this->sendVerification($claim, $boundary);
        } catch (RerankVerificationFailure $failure) {
            $problem = $failure->problem;
            $facts = $failure->evidence;
            (new AdminValidationUsage())->receipt($claim['meter_attempt_id'],
                $facts !== null && $facts->httpResponded ? 'response_known' : ($boundary->started() ? 'ambiguous' : 'not_dispatched'),
                $facts === null ? null : ['usage_measured' => $facts->usageMeasured, 'input_tokens' => null, 'output_tokens' => null,
                    'rerank_meter_code' => $facts->meteringUnit, 'rerank_quantity' => $facts->meteringQuantity],
                $problem->errorCode);
            $this->finishFailure($deploymentId, $revision, $claim['attempt_id'], $problem, true);
            throw $problem;
        } catch (MeasuredChatFailure $failure) {
            $problem = $failure->problem ?? new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Provider returned no usable completion', true);
            (new AdminValidationUsage())->receipt($claim['meter_attempt_id'], 'response_known', ['usage_measured' => true, 'input_tokens' => $failure->inputTokens, 'output_tokens' => $failure->outputTokens], $problem->errorCode);
            $this->finishFailure($deploymentId, $revision, $claim['attempt_id'], $problem, true);
            throw $problem;
        } catch (EmbeddingVerificationFailure $failure) {
            (new AdminValidationUsage())->receipt($claim['meter_attempt_id'], 'ambiguous', errorCode: 'SAND_AI_DEPLOYMENT_VERIFICATION_AMBIGUOUS');
            $problem = new ApiProblem('SAND_AI_DEPLOYMENT_VERIFICATION_AMBIGUOUS', 'Embedding verification outcome is ambiguous', true);
            $this->finishFailure($deploymentId, $revision, $claim['attempt_id'], $problem, true, $failure->diagnostic());
            throw $problem;
        } catch (ApiProblem $problem) {
            (new AdminValidationUsage())->receipt($claim['meter_attempt_id'], $boundary->started() ? 'ambiguous' : 'not_dispatched', errorCode: $problem->errorCode);
            $this->finishFailure($deploymentId, $revision, $claim['attempt_id'], $problem, $nonChatVerification);
            throw $problem;
        } catch (\Throwable) {
            (new AdminValidationUsage())->receipt($claim['meter_attempt_id'], $boundary->started() ? 'ambiguous' : 'not_dispatched', errorCode: 'SAND_AI_DEPLOYMENT_VERIFICATION_AMBIGUOUS');
            $problem = new ApiProblem('SAND_AI_DEPLOYMENT_VERIFICATION_AMBIGUOUS', 'Deployment verification outcome is ambiguous', true);
            $this->finishFailure($deploymentId, $revision, $claim['attempt_id'], $problem, $nonChatVerification);
            throw $problem;
        }

        (new AdminValidationUsage())->receipt($claim['meter_attempt_id'], 'response_known', $verification);
        try {
            return Db::connect('pgsql')->transaction(function (ConnectionInterface $connection) use ($deploymentId, $revision, $actorRef, $claim, $verification): array {
            $chain = $this->loadExact($connection, $deploymentId, $revision, true);
            $this->assertVerifiable($chain, $deploymentId, $revision, ['verifying']);
            $payload = $this->payload($chain['revision']);
            $storedClaim = is_array($payload['deployment_verification_claim'] ?? null) ? $payload['deployment_verification_claim'] : [];
            if (!hash_equals($claim['attempt_id'], (string) ($storedClaim['attempt_id'] ?? ''))
                || !hash_equals($claim['capture']['sha256'], (string) ($storedClaim['execution_snapshot_digest'] ?? ''))) {
                throw new ApiProblem('SAND_AI_DEPLOYMENT_VERIFICATION_IN_PROGRESS', 'Deployment verification ownership changed', true);
            }
            $this->executionSnapshot->assertArrayMatches($claim['capture']['snapshot'], $claim['capture']['sha256'], $chain['provider'], $chain['model'], $chain['deployment']);
            $policy = $this->processingPolicy->fromPayload(['processing_policy' => $storedClaim['processing_policy'] ?? null]);
            if ($policy['policy'] === null) {
                throw new ApiProblem('SAND_AI_CONFIGURATION_DRIFTED', 'Deployment verification processing policy changed');
            }
            $payload['processing_policy'] = $policy['policy'];
            $payload['execution_snapshot'] = $claim['capture']['snapshot'];
            $payload['execution_snapshot_digest'] = $claim['capture']['sha256'];
            $payload['deployment_validation'] = $this->evidence->create($deploymentId, $revision, $actorRef, $verification, (new AdminValidationUsage())->projection($claim['meter_attempt_id']));
            $payload['deployment_verification_claim'] = $storedClaim;
            $this->saveRevision($chain['revision'], $payload, 'tested');
            AuditLog::create([
                'actor_type' => 'admin', 'actor_ref' => $actorRef, 'action' => 'deployment.verification.passed',
                'resource_type' => 'model_deployment', 'resource_id' => $deploymentId,
                'summary' => 'Verified an existing SandAI deployment draft',
                'context' => ['revision' => $revision, 'outcome' => 'passed'],
            ]);
            $revisionRow = [...$chain['revision'], 'payload' => $payload, 'state' => 'tested'];
            return $this->summary($revisionRow, $deploymentId, $revision, false);
            });
        } catch (\Throwable) {
            $problem = new ApiProblem('SAND_AI_DEPLOYMENT_VERIFICATION_AMBIGUOUS', 'Deployment verification could not be finalized safely', true);
            $this->finishFailure($deploymentId, $revision, $claim['attempt_id'], $problem, $nonChatVerification);
            throw $problem;
        }
    }

    /** @param array<string,mixed> $claim @return array{input_tokens:?int,output_tokens:?int,usage_measured:bool} */
    private function sendVerification(array $claim, ProviderSendBoundary $boundary): array
    {
        $capture = $claim['capture'] ?? null;
        $provider = is_array($capture) ? ($capture['snapshot']['provider'] ?? null) : null;
        $deployment = is_array($capture) ? ($capture['snapshot']['deployment'] ?? null) : null;
        if (!is_array($provider) || !is_array($deployment)) {
            throw new ApiProblem('SAND_AI_CONFIGURATION_DRIFTED', 'Deployment execution snapshot is invalid');
        }
        if (($provider['adapter'] ?? null) === 'local_ocr_runtime') {
            (new DeploymentOcrVerifier())->verify((string) ($claim['remote_model'] ?? ''), $boundary);
            return ['input_tokens' => null, 'output_tokens' => null, 'usage_measured' => false];
        }
        if (($provider['adapter'] ?? null) === 'local_runtime') {
            $boundary->markStarted();
            (new DeploymentTranscriptionVerifier())->verifyLocal((string) ($claim['remote_model'] ?? ''));
            return ['input_tokens' => null, 'output_tokens' => null, 'usage_measured' => false];
        }
        if (($capture['snapshot']['model']['type'] ?? null) === 'rerank') {
            return (new DeploymentRerankVerifier())->verify(
                (string) ($provider['adapter'] ?? ''),
                $this->decryptor->decrypt(is_array($claim['encrypted_config'] ?? null) ? $claim['encrypted_config'] : []),
                (string) ($claim['remote_model'] ?? ''),
                (int) ($claim['timeout_ms'] ?? 10000), $boundary, (string) $claim['attempt_id'],
            );
        }
        if (($provider['adapter'] ?? null) !== 'openai_compatible') {
            throw new ApiProblem('SAND_AI_CAPABILITY_UNSUPPORTED', 'Deployment adapter does not support verification');
        }
        $config = $this->decryptor->decrypt(is_array($claim['encrypted_config'] ?? null) ? $claim['encrypted_config'] : []);
        $model = is_array($capture['snapshot']['model'] ?? null) ? $capture['snapshot']['model'] : [];
        if (($model['type'] ?? null) === 'embedding') {
            $boundary->markStarted();
            return (new DeploymentEmbeddingVerifier())->verify(
                $config,
                (string) ($claim['remote_model'] ?? ''),
                (int) ($claim['timeout_ms'] ?? 10000),
            );
        }
        if (($model['type'] ?? null) === 'audio_transcription') {
            $boundary->markStarted();
            (new DeploymentTranscriptionVerifier())->verify(
                $config,
                (string) ($claim['remote_model'] ?? ''),
                (int) ($claim['timeout_ms'] ?? 10000),
            );
            return ['input_tokens' => null, 'output_tokens' => null, 'usage_measured' => false];
        }
        if (($model['type'] ?? null) === 'audio_speech') {
            $settings = is_array($deployment['config'] ?? null) ? $deployment['config'] : [];
            $settings = (new DeploymentDraftConfigurationPolicy())->normalize($settings);
            (new DeploymentDraftConfigurationPolicy())->assertForModel($settings, 'audio_speech', (string) ($claim['remote_model'] ?? ''));
            $boundary->markStarted();
            (new OpenAiCompatibleSpeechSynthesisDriver($config, timeoutMs: (int) ($claim['timeout_ms'] ?? 10000)))
                ->synthesize(new SpeechSynthesisRequest(
                    'SandAI deployment verification.',
                    (string) ($claim['remote_model'] ?? ''),
                    (string) $settings['verification_voice'],
                    (string) ($settings['speech_response_format'] ?? 'mp3'),
                ));
            return ['input_tokens' => null, 'output_tokens' => null, 'usage_measured' => false];
        }
        if (($model['type'] ?? null) !== 'chat') {
            throw new ApiProblem('SAND_AI_CAPABILITY_UNSUPPORTED', 'Deployment model does not support verification');
        }
        $settings = (new DeploymentDraftConfigurationPolicy())->normalize(
            is_array($deployment['config'] ?? null) ? $deployment['config'] : [],
        );
        (new DeploymentDraftConfigurationPolicy())->assertForModel($settings, 'chat', (string) ($claim['remote_model'] ?? ''));
        $verificationOutputTokens = max(1, min(1024, $settings['max_output_tokens']));
        $result = ($this->provider ?? new OpenAiCompatibleChatProvider())->prepare(
            $config,
            (string) ($claim['remote_model'] ?? ''),
            [['role' => 'user', 'content' => 'Reply with OK.']],
            (int) ($claim['timeout_ms'] ?? 10000),
            new ChatCompletionOptions($verificationOutputTokens, $settings['reasoning_effort'] ?? null),
            useNeuron: true,
        )->send($boundary);
        $measured = $result['usage_measured'] ?? null;
        $inputTokens = $result['input_tokens'] ?? null;
        $outputTokens = $result['output_tokens'] ?? null;
        if (!is_bool($measured)
            || ($measured && (!is_int($inputTokens) || !is_int($outputTokens)
                || $inputTokens < 0 || $outputTokens < 0))) {
            throw new ApiProblem('SAND_AI_PROVIDER_RESPONSE_INVALID', 'Deployment verification usage is invalid');
        }
        return [
            'input_tokens' => $measured ? $inputTokens : null,
            'output_tokens' => $measured ? $outputTokens : null,
            'usage_measured' => $measured,
        ];
    }

    private function finishFailure(int $deploymentId, int $revision, string $attemptId, ApiProblem $problem, bool $nonChatVerification, ?array $diagnostic = null): void
    {
        Db::connect('pgsql')->transaction(function (ConnectionInterface $connection) use ($deploymentId, $revision, $attemptId, $problem, $nonChatVerification, $diagnostic): void {
            $chain = $this->loadExact($connection, $deploymentId, $revision, true);
            $this->assertExactChain($chain, $deploymentId, $revision);
            if ((string) ($chain['revision']['state'] ?? '') !== 'verifying') {
                return;
            }
            $payload = $this->payload($chain['revision']);
            $storedClaim = is_array($payload['deployment_verification_claim'] ?? null) ? $payload['deployment_verification_claim'] : [];
            if (!hash_equals($attemptId, (string) ($storedClaim['attempt_id'] ?? ''))) {
                return;
            }
            $payload['deployment_verification_failure'] = [
                'attempt_id' => $attemptId,
                'code' => $problem->errorCode,
                'reason' => ChatProviderFailurePolicy::reason($problem),
                'time' => date('Y-m-d H:i:s'),
                'diagnostic' => EmbeddingVerificationFailure::readDiagnostic($diagnostic),
            ];
            $this->saveRevision($chain['revision'], $payload, ($nonChatVerification || $problem->retryable) ? 'verification_ambiguous' : 'draft');
        });
    }

    /** @return array{reference:?array<string,mixed>,provider:?array<string,mixed>,model:?array<string,mixed>,deployment:?array<string,mixed>,revision:?array<string,mixed>} */
    private function loadExact(ConnectionInterface $connection, int $deploymentId, int $revision, bool $lock): array
    {
        // The reference is a non-locking identity read. Every mutable row is
        // then locked in the same Provider -> Model -> Deployment -> Revision
        // order as publication before it is trusted.
        $referenceQuery = $connection->table('sand_ai_model_deployment')->field(['id', 'provider_id', 'model_id', 'revision'])
            ->where('id', $deploymentId)->whereNull('delete_time');
        $reference = $referenceQuery->find();
        if (!is_array($reference)) {
            return ['reference' => null, 'provider' => null, 'model' => null, 'deployment' => null, 'revision' => null];
        }
        $providerQuery = $connection->table('sand_ai_provider')->json(['encrypted_config'], true)->where('id', (int) $reference['provider_id'])->whereNull('delete_time');
        $modelQuery = $connection->table('sand_ai_model')->json(['capabilities', 'config'], true)->where('id', (int) $reference['model_id'])->whereNull('delete_time');
        $deploymentQuery = $connection->table('sand_ai_model_deployment')->json(['config'], true)->where('id', $deploymentId)->whereNull('delete_time');
        $revisionQuery = $connection->table('sand_ai_config_revision')->json(['payload'], true)->where('resource_type', 'model_deployment')->where('resource_id', $deploymentId)->where('revision', $revision)->whereNull('delete_time');
        if ($lock) { $providerQuery->lock(true); $modelQuery->lock(true); $deploymentQuery->lock(true); $revisionQuery->lock(true); }
        $provider = $providerQuery->find();
        $model = $modelQuery->find();
        $deployment = $deploymentQuery->find();
        $revisionRow = $revisionQuery->find();
        return ['reference' => $reference, 'provider' => is_array($provider) ? $provider : null, 'model' => is_array($model) ? $model : null, 'deployment' => is_array($deployment) ? $deployment : null, 'revision' => is_array($revisionRow) ? $revisionRow : null];
    }

    /** @param array<string,mixed> $chain @param list<string>|null $states */
    private function assertVerifiable(array $chain, int $deploymentId, int $revision, ?array $states = null): void
    {
        $this->assertExactChain($chain, $deploymentId, $revision);
        if ((int) ($chain['provider']['status'] ?? 0) !== 1 || (int) ($chain['model']['status'] ?? 0) !== 1
            || (int) ($chain['deployment']['status'] ?? 0) !== 2 || (int) ($chain['revision']['status'] ?? 0) !== 1) {
            throw new ApiProblem('SAND_AI_CONFIGURATION_DRIFTED', 'Deployment verification chain is unavailable');
        }
        $modelType = (string) ($chain['model']['type'] ?? '');
        if (!in_array((string) ($chain['provider']['adapter'] ?? ''), ['openai_compatible', 'local_runtime', 'local_ocr_runtime', 'cohere_rerank', 'jina_rerank'], true)
            || !in_array($modelType, ['chat', 'embedding', 'rerank', 'audio_speech', 'audio_transcription', 'image_ocr'], true)
            || !in_array($modelType, is_array($chain['model']['capabilities'] ?? null) ? $chain['model']['capabilities'] : [], true)) {
            throw new ApiProblem('SAND_AI_CAPABILITY_UNSUPPORTED', 'Deployment does not support the registered verification capability');
        }
        $professionalDriver = in_array($chain['provider']['adapter'] ?? null, ['cohere_rerank', 'jina_rerank'], true);
        if (($modelType === 'rerank') !== $professionalDriver || ($modelType === 'rerank' && ($chain['model']['capabilities'] ?? null) !== ['rerank'])) {
            throw new ApiProblem('SAND_AI_CAPABILITY_UNSUPPORTED', 'Professional rerank requires a matching rerank model and provider');
        }
        $settings = (new DeploymentDraftConfigurationPolicy())->normalize(
            is_array($chain['deployment']['config'] ?? null) ? $chain['deployment']['config'] : [],
        );
        (new DeploymentDraftConfigurationPolicy())->assertForModel($settings, $modelType, (string) ($chain['deployment']['remote_model'] ?? ''));
        if ($modelType === 'image_ocr' || ($chain['provider']['adapter'] ?? null) === 'local_ocr_runtime') {
            \app\Application\File\LocalOcrDeploymentPolicy::assertTarget((string) $chain['provider']['adapter'], $modelType, (string) ($chain['deployment']['remote_model'] ?? ''));
            $saved = $this->payload($chain['revision'])['local_runtime_execution_config'] ?? null;
            $current = \app\Infrastructure\Parsing\LocalOcrExecutionConfig::fingerprint();
            if (!\app\Application\Audio\AudioSnapshot::same($saved, $current)) {
                throw new ApiProblem('SAND_AI_CONFIGURATION_DRIFTED', 'The saved local OCR runtime changed; create a new draft revision');
            }
        } elseif (($chain['provider']['adapter'] ?? null) === 'local_runtime') {
            \app\Application\Audio\LocalAudioDeploymentPolicy::assertTarget('local_runtime', $modelType, (string) ($chain['deployment']['remote_model'] ?? ''));
            $saved = $this->payload($chain['revision'])['local_runtime_execution_config'] ?? null;
            $current = (new \app\Application\Audio\LocalAudioExecutionFingerprintPolicy())->capture();
            if (!\app\Application\Audio\AudioSnapshot::same($saved, $current)) {
                throw new ApiProblem('SAND_AI_CONFIGURATION_DRIFTED', 'The saved local transcription runtime changed; create a new draft revision');
            }
        } elseif ($modelType === 'audio_transcription'
            && !in_array((string) ($chain['deployment']['remote_model'] ?? ''), ['whisper-1', 'gpt-4o-transcribe-diarize', 'glm-asr-2512'], true)) {
            throw new ApiProblem('SAND_AI_CAPABILITY_UNSUPPORTED', 'Transcription model is unsupported');
        }
        if ($states !== null && !in_array((string) ($chain['revision']['state'] ?? ''), $states, true)) {
            throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Deployment revision verification state changed');
        }
    }

    /** @param array<string,mixed> $chain */
    private function assertExactChain(array $chain, int $deploymentId, int $revision): void
    {
        $reference = $chain['reference'] ?? null;
        $deployment = $chain['deployment'] ?? null;
        if (!is_array($reference) || !is_array($deployment)) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Deployment revision not found');
        }
        if (!is_array($chain['provider'] ?? null) || !is_array($chain['model'] ?? null) || !is_array($chain['revision'] ?? null)
            || (int) ($reference['id'] ?? 0) !== $deploymentId || (int) ($reference['revision'] ?? 0) !== $revision
            || (int) ($deployment['revision'] ?? 0) !== $revision
            || (int) ($deployment['provider_id'] ?? 0) !== (int) ($reference['provider_id'] ?? 0)
            || (int) ($deployment['model_id'] ?? 0) !== (int) ($reference['model_id'] ?? 0)
            || (int) ($chain['provider']['id'] ?? 0) !== (int) ($reference['provider_id'] ?? 0)
            || (int) ($chain['model']['id'] ?? 0) !== (int) ($reference['model_id'] ?? 0)
            || (int) ($chain['revision']['resource_id'] ?? 0) !== $deploymentId
            || (int) ($chain['revision']['revision'] ?? 0) !== $revision) {
            throw new ApiProblem('SAND_AI_CONFIGURATION_DRIFTED', 'Deployment verification chain changed');
        }
    }

    /** @param array<string,mixed> $revision */
    private function saveRevision(array $revision, array $payload, string $state): void
    {
        (new ConfigRevision($revision))->exists(true)->save(['payload' => $payload, 'state' => $state]);
    }

    /** @param array<string,mixed> $revision @return array<string,mixed> */
    private function payload(array $revision): array
    {
        return is_array($revision['payload'] ?? null) ? $revision['payload'] : [];
    }

    /** @param array<string,mixed> $stored @param array{idempotency_hmac:string,request_sha256:string} $request */
    private function sameRequest(array $stored, array $request): bool
    {
        return is_string($stored['idempotency_hmac'] ?? null) && is_string($stored['request_sha256'] ?? null)
            && hash_equals($stored['idempotency_hmac'], $request['idempotency_hmac'])
            && hash_equals($stored['request_sha256'], $request['request_sha256']);
    }

    /** @param array<string,mixed> $claim */
    private function claimExpired(array $claim): bool
    {
        $expiresAt = $claim['expires_at'] ?? null;
        if (!is_string($expiresAt)) {
            return true;
        }
        $timestamp = strtotime($expiresAt);
        return $timestamp === false || $timestamp <= time();
    }

    private function recoverExpiredClaim(int $deploymentId, int $revision): bool
    {
        return Db::connect('pgsql')->transaction(function (ConnectionInterface $connection) use ($deploymentId, $revision): bool {
            $configuration = $connection->table('sand_ai_config_revision')->json(['payload'], true)
                ->where('resource_type', 'model_deployment')->where('resource_id', $deploymentId)
                ->where('revision', $revision)->whereNull('delete_time')->lock(true)->find();
            if (!is_array($configuration) || (string) ($configuration['state'] ?? '') !== 'verifying') {
                return false;
            }
            $payload = $this->payload($configuration);
            $claim = is_array($payload['deployment_verification_claim'] ?? null) ? $payload['deployment_verification_claim'] : [];
            if (!$this->claimExpired($claim)) {
                return false;
            }
            $payload['deployment_verification_failure'] = ['code' => 'SAND_AI_DEPLOYMENT_VERIFICATION_AMBIGUOUS', 'time' => date('Y-m-d H:i:s')];
            $this->saveRevision($configuration, $payload, 'verification_ambiguous');
            $attempt = $connection->table('sand_ai_admin_validation_attempt')->where('config_revision_id', (int) $configuration['id'])->where('attempt_id', (string) ($claim['attempt_id'] ?? ''))->find();
            if (is_array($attempt) && $connection->table('sand_ai_admin_validation_receipt')->where('attempt_id', (int) $attempt['id'])->find() === null) {
                (new AdminValidationUsage())->receipt((int) $attempt['id'], 'ambiguous', errorCode: 'SAND_AI_DEPLOYMENT_VERIFICATION_AMBIGUOUS');
            }
            return true;
        });
    }

    /** @param array<string,mixed> $revision @return array<string,mixed> */
    private function summary(array $revision, int $deploymentId, int $revisionNumber, bool $replay): array
    {
        $payload = $this->payload($revision);
        $policy = $this->processingPolicy->fromPayload($payload);
        return [
            'deployment_id' => $deploymentId,
            'revision' => $revisionNumber,
            'state' => (string) ($revision['state'] ?? ''),
            'processing_location' => $policy['processing_location'],
            'compatible_data_egress_policies' => $policy['compatible_data_egress_policies'],
            'egress_source' => $policy['egress_source'],
            'validation_evidence' => $this->evidence->fromPayload($payload, $deploymentId, $revisionNumber),
            'failure' => $this->failureFeedback($payload, (string) ($revision['state'] ?? '')),
            'idempotent_replay' => $replay,
            'platform_usage' => (new AdminValidationUsage())->forRevision((int) $revision['id']),
        ];
    }

    /** Replay the selected immutable attempt, even if a later key finalized this revision. */
    private function attemptReplaySummary(array $revision, array $attempt, array $receipt, array $policy, int $deploymentId, int $revisionNumber): array
    {
        $payload = $this->payload($revision);
        $storedEvidence = $payload['deployment_validation'] ?? null;
        $evidence = is_array($storedEvidence)
            && ($storedEvidence['platform_attempt_id'] ?? null) === (int) $attempt['id']
            ? $this->evidence->fromPayload($payload, $deploymentId, $revisionNumber) : null;
        // Only this attempt's immutable outcome and its own validated evidence
        // determine replay status; current revision state may describe another key.
        $error = $receipt['error_code'];
        $state = $evidence !== null ? 'tested'
            : (($attempt['capability_code'] === 'chat' && $error === 'SAND_AI_PROVIDER_REJECTED')
                || $receipt['outcome_state'] === 'not_dispatched' ? 'draft' : 'verification_ambiguous');
        $failure = $evidence !== null ? null : $this->failureFeedback([
            'deployment_verification_failure' => [
                'code' => $error ?? 'SAND_AI_DEPLOYMENT_VERIFICATION_AMBIGUOUS',
                'time' => (string) $receipt['create_time'],
            ],
        ], $state);
        $storedFailure = $payload['deployment_verification_failure'] ?? null;
        $storedClaim = $payload['deployment_verification_claim'] ?? null;
        if ($evidence === null && is_array($storedFailure)) {
            // Older terminal failures belonged to the still-current claim.
            // A new verifying claim must not borrow that earlier failure.
            $failureAttempt = $storedFailure['attempt_id'] ?? (
                in_array($revision['state'] ?? null, ['draft', 'verification_ambiguous'], true)
                && is_array($storedClaim) ? ($storedClaim['attempt_id'] ?? null) : null
            );
            if (is_string($failureAttempt) && $failureAttempt === $attempt['attempt_id']
                && ($storedFailure['code'] ?? null) === $error) {
                $failure = $this->failureFeedback([
                    'deployment_verification_failure' => [
                        'code' => $error,
                        'reason' => $storedFailure['reason'] ?? null,
                        'time' => (string) $receipt['create_time'],
                    ],
                ], $state) ?? $failure;
            }
        }
        return [
            'deployment_id' => $deploymentId, 'revision' => $revisionNumber, 'state' => $state,
            'processing_location' => $policy['processing_location'],
            'compatible_data_egress_policies' => $policy['compatible_data_egress_policies'],
            'egress_source' => $policy['egress_source'], 'validation_evidence' => $evidence,
            'failure' => $failure, 'idempotent_replay' => true,
            'platform_usage' => (new AdminValidationUsage())->projection((int) $attempt['id']),
        ];
    }

    /**
     * Reconstruct recovery feedback from bounded local facts. Do not persist or
     * expose exception messages, HTTP bodies, endpoint URLs or provider secrets.
     *
     * @param array<string,mixed> $payload
     * @return array{error_code:string,reason:string,message:string,recovery_action:string,outcome_uncertain:bool,failed_at:?string,diagnostic?:?array<string,mixed>}|null
     */
    private function failureFeedback(array $payload, string $state): ?array
    {
        if (!in_array($state, ['draft', 'verification_ambiguous'], true)) {
            return null;
        }
        $failure = $payload['deployment_verification_failure'] ?? null;
        if (!is_array($failure)) {
            return null;
        }
        if (array_key_exists('local_runtime_execution_config', $payload)
            && in_array($failure['code'] ?? null, [
                'SAND_AI_OCR_UNAVAILABLE', 'SAND_AI_PARSE_FAILED', 'SAND_AI_PARSE_EMPTY', 'SAND_AI_OCR_DISPATCH_AMBIGUOUS',
                'SAND_AI_AUDIO_NOT_CONFIGURED', 'SAND_AI_AUDIO_UNAVAILABLE',
                'SAND_AI_AUDIO_TRANSCRIPTION_RESULT_INVALID', 'SAND_AI_CONFIGURATION_DRIFTED',
                'SAND_AI_DEPLOYMENT_VERIFICATION_AMBIGUOUS',
            ], true)) {
            $time = $failure['time'] ?? null;
            return [
                'error_code' => $failure['code'],
                'reason' => 'local_runtime_verification_failed',
                'message' => ($payload['local_runtime_execution_config']['version'] ?? null) === 'local_ocr_execution_config@1'
                    ? '本地 OCR 验证未完成，请核对本机 OCR 服务和超时配置；保留原部署与修订号核查，另存新草稿后再验证，当前修订不能发布'
                    : '本地录音验证未完成，请核对转写工具、模型和超时配置，另存新部署草稿后重新验证；当前修订不能发布',
                'recovery_action' => 'configure_local_runtime_and_save_draft',
                'outcome_uncertain' => $state === 'verification_ambiguous',
                'failed_at' => is_string($time) && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/', $time) === 1 ? $time : null,
            ];
        }
        $reason = $failure['reason'] ?? null;
        if ($reason === null) {
            // Historical failures retained only a code. Keep that evidence
            // general; never infer a bad Key or timeout from the code alone.
            $reason = match ($failure['code'] ?? null) {
                'SAND_AI_PROVIDER_REJECTED' => 'provider_rejected',
                'SAND_AI_PROVIDER_UNAVAILABLE' => 'provider_unavailable',
                'SAND_AI_PROVIDER_RATE_LIMITED' => 'provider_rate_limited',
                'SAND_AI_DEPLOYMENT_VERIFICATION_AMBIGUOUS' => 'verification_ambiguous',
                default => null,
            };
        }
        $notices = [
            'invalid_api_key' => ['SAND_AI_PROVIDER_REJECTED', '服务商未接受 API Key，请核对有效性及所属服务地址'],
            'provider_access_denied' => ['SAND_AI_PROVIDER_REJECTED', '服务商拒绝了账号权限，请核对项目、模型和聊天接口权限'],
            'model_or_endpoint_unavailable' => ['SAND_AI_PROVIDER_REJECTED', '服务商没有提供请求的聊天模型或接口，请核对模型标识与 API 基础地址'],
            'chat_endpoint_unsupported' => ['SAND_AI_PROVIDER_REJECTED', '服务商未接受聊天接口，请确认模型支持聊天以及 API 基础地址正确'],
            'model_or_parameters_rejected' => ['SAND_AI_PROVIDER_REJECTED', '服务商拒绝了模型或参数，请核对聊天能力、Token 额度与推理额度'],
            'provider_rejected' => ['SAND_AI_PROVIDER_REJECTED', '服务商拒绝了验证请求，请核对 Key、权限、模型和参数'],
            'provider_timeout' => ['SAND_AI_PROVIDER_UNAVAILABLE', '模型验证超时，可能已发送并产生费用，请核对服务商调用记录；不要重复验证此修订'],
            'provider_unavailable' => ['SAND_AI_PROVIDER_UNAVAILABLE', '模型验证连接或响应未确认，请核对服务商调用记录和当前修订状态'],
            'provider_rate_limited' => ['SAND_AI_PROVIDER_RATE_LIMITED', '服务商当前限流，请核对当前修订状态和服务商调用记录后决定后续操作'],
            'verification_ambiguous' => ['SAND_AI_DEPLOYMENT_VERIFICATION_AMBIGUOUS', '当前修订验证结果无法确定，可能已产生费用；请保留部署与修订号并联系管理员核查'],
        ];
        if (!is_string($reason) || !isset($notices[$reason])
            || ($failure['code'] ?? null) !== $notices[$reason][0]
            || ($state === 'draft' && $failure['code'] !== 'SAND_AI_PROVIDER_REJECTED')) {
            return null;
        }
        $uncertain = $state === 'verification_ambiguous';
        $message = $notices[$reason][1];
        if ($failure['code'] === 'SAND_AI_PROVIDER_REJECTED') {
            $message .= $uncertain
                ? '；当前修订结果仍无法确定，请先核查服务商调用记录，不要重复验证此修订'
                : '，修正后可重新配置连接';
        }
        $time = $failure['time'] ?? null;
        return [
            'error_code' => $notices[$reason][0],
            'reason' => $reason,
            'message' => $message,
            'recovery_action' => $uncertain ? 'check_provider_record' : 'reconfigure_connection',
            'outcome_uncertain' => $uncertain,
            'diagnostic' => $reason === 'verification_ambiguous' ? EmbeddingVerificationFailure::readDiagnostic($failure['diagnostic'] ?? null) : null,
            'failed_at' => is_string($time) && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/', $time) === 1 ? $time : null,
        ];
    }
}
