<?php

declare(strict_types=1);

namespace app\Application\Onboarding;

use app\Api\Support\ApiProblem;

/** Strict public DTO and keyed idempotency boundary for API 1.44 draft saves. */
final class DeploymentDraftSaveRequestPolicy
{
    private const FINGERPRINT_VERSION = 'deployment_draft_save_hmac@2';
    private const ROUTE = '/app/sand-ai/admin/setup/deployment/save';
    private const PROVIDER_FIELDS = ['code', 'name', 'adapter', 'timeout_ms'];
    private const MODEL_FIELDS = ['code', 'name', 'type', 'capabilities'];

    /**
     * @param array<string,mixed> $input
     * @return array{provider_id:?int,provider:?array{code:string,name:string,adapter:string,timeout_ms:int},model_id:?int,model:?array{code:string,name:string,type:string,capabilities:list<string>},deployment_id:?int,connection_submitted:bool,remote_model:string,config:array<string,int|string>}
     */
    public function normalize(array $input, bool $hasEncryptedConnection): array
    {
        $allowed = ['provider_id', 'provider', 'model_id', 'model', 'deployment_id', 'remote_model', 'config', 'connection_submitted'];
        if (array_diff(array_keys($input), $allowed) !== []) {
            throw $this->invalid('Deployment draft request contains unsupported fields');
        }
        $providerId = $this->optionalPositiveInt($input, 'provider_id');
        $provider = $this->optionalProvider($input);
        if (($providerId === null) === ($provider === null)) {
            throw $this->invalid('Exactly one of provider_id or provider is required');
        }
        $modelId = $this->optionalPositiveInt($input, 'model_id');
        $model = $this->optionalModel($input);
        if (($modelId === null) === ($model === null)) {
            throw $this->invalid('Exactly one of model_id or model is required');
        }
        $connectionSubmitted = $input['connection_submitted'] ?? false;
        if (!is_bool($connectionSubmitted)) {
            throw $this->invalid('connection must be an object when provided');
        }
        $localProvider = in_array($provider['adapter'] ?? null, ['local_runtime', 'local_ocr_runtime'], true);
        if ($localProvider && ($connectionSubmitted || $hasEncryptedConnection)) {
            throw $this->invalid('A local runtime provider must not contain a remote connection');
        }
        if ($provider !== null && !$localProvider && (!$connectionSubmitted || !$hasEncryptedConnection)) {
            throw $this->invalid('A new provider requires a valid connection');
        }
        if ($providerId !== null && ($connectionSubmitted || $hasEncryptedConnection)) {
            throw $this->invalid('An existing provider cannot be updated with connection');
        }

        return [
            'provider_id' => $providerId,
            'provider' => $provider,
            'model_id' => $modelId,
            'model' => $model,
            'deployment_id' => $this->optionalPositiveInt($input, 'deployment_id'),
            'connection_submitted' => $connectionSubmitted,
            'remote_model' => RemoteModelIdentifier::normalize($input['remote_model'] ?? null),
            'config' => $this->config($input['config'] ?? null),
        ];
    }

    /** @param array<string,mixed> $canonical @return array{idempotency_hmac:string,request_fingerprint_hmac:string,key_version:string,key_id:string} */
    public function fingerprint(array $canonical, string $idempotencyKey, string $actorRef): array
    {
        return $this->fingerprintForKeyId($canonical, $idempotencyKey, $actorRef, (string) env('SAND_AI_IDEMPOTENCY_KEY_ID', 'active'));
    }

    /** @param array<string,mixed> $canonical @return array{idempotency_hmac:string,request_fingerprint_hmac:string,key_version:string,key_id:string} */
    public function fingerprintForKeyId(array $canonical, string $idempotencyKey, string $actorRef, string $keyId): array
    {
        $idempotencyKey = $this->validatedIdempotencyKey($idempotencyKey);
        $idempotencyKeyMaterial = $this->keyForId($keyId);
        $actorRef = $this->validatedActorRef($actorRef);
        if (preg_match('/^[A-Za-z0-9._-]{1,64}$/', $keyId) !== 1) {
            throw new ApiProblem('SAND_AI_IDEMPOTENCY_KEY_UNAVAILABLE', 'Idempotency key identifier is unavailable');
        }
        $key = hash_hmac('sha256', self::FINGERPRINT_VERSION . "\0" . self::ROUTE . "\0" . $keyId, $idempotencyKeyMaterial, true);
        $encoded = json_encode(['version' => self::FINGERPRINT_VERSION, 'route' => self::ROUTE, 'actor_ref' => $actorRef, 'request' => $this->canonical($canonical)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return [
            'idempotency_hmac' => hash_hmac('sha256', self::ROUTE . "\0" . $actorRef . "\0" . $idempotencyKey, $key),
            'request_fingerprint_hmac' => hash_hmac('sha256', $encoded, $key),
            'key_version' => self::FINGERPRINT_VERSION,
            'key_id' => $keyId,
        ];
    }

    /** @return array{idempotency_hmac:string,key_id:string} */
    public function idempotencyForKeyId(string $idempotencyKey, string $actorRef, string $keyId): array
    {
        $fingerprint = $this->fingerprintForKeyId(['operation' => 'deployment_draft_save'], $idempotencyKey, $actorRef, $keyId);
        return ['idempotency_hmac' => $fingerprint['idempotency_hmac'], 'key_id' => $fingerprint['key_id']];
    }

    /**
     * Internal transaction-lock namespace. It deliberately does not use any
     * rotating signing key and is neither stored nor exposed in a DTO.
     */
    public function advisoryLockFingerprint(string $idempotencyKey, string $actorRef): string
    {
        $idempotencyKey = $this->validatedIdempotencyKey($idempotencyKey);
        $actorRef = $this->validatedActorRef($actorRef);
        return hash('sha256', 'sand-ai-deployment-draft-save-advisory-lock@1' . "\0" . self::ROUTE . "\0" . $actorRef . "\0" . $idempotencyKey);
    }

    public function keyVersion(): string
    {
        return self::FINGERPRINT_VERSION;
    }

    /**
     * Returns null for every pre-API-1.44 revision. Once a record explicitly
     * declares `draft_save@1`, incomplete or unavailable idempotency metadata
     * is a fail-closed condition instead of a reason to create another draft.
     *
     * @param array<string,mixed> $payload
     */
    public function replayKeyId(array $payload): ?string
    {
        $save = $payload['draft_save'] ?? null;
        if (!is_array($save) || ($save['version'] ?? null) !== 'draft_save@1') {
            return null;
        }
        if (($save['key_version'] ?? null) !== self::FINGERPRINT_VERSION
            || !is_string($save['key_id'] ?? null)
            || preg_match('/^[A-Za-z0-9._-]{1,64}$/', $save['key_id']) !== 1
            || !is_string($save['idempotency_hmac'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $save['idempotency_hmac']) !== 1
            || !is_string($save['request_fingerprint_hmac'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $save['request_fingerprint_hmac']) !== 1) {
            throw new ApiProblem('SAND_AI_IDEMPOTENCY_KEY_UNAVAILABLE', 'Stored idempotency key material is unavailable');
        }
        return $save['key_id'];
    }

    private function keyForId(string $keyId): string
    {
        $activeId = (string) env('SAND_AI_IDEMPOTENCY_KEY_ID', 'active');
        $key = $keyId === $activeId ? (string) env('SAND_AI_IDEMPOTENCY_KEY', '') : '';
        if ($key === '') {
            $keyring = json_decode((string) env('SAND_AI_IDEMPOTENCY_KEYRING', '{}'), true);
            $key = is_array($keyring) && is_string($keyring[$keyId] ?? null) ? $keyring[$keyId] : '';
        }
        if (strlen($key) < 32) {
            throw new ApiProblem('SAND_AI_IDEMPOTENCY_KEY_UNAVAILABLE', 'Idempotency key material is unavailable');
        }
        return $key;
    }

    private function validatedIdempotencyKey(string $idempotencyKey): string
    {
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 128 || preg_match('/[\r\n]/', $idempotencyKey) === 1) {
            throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'Idempotency-Key is required and must not exceed 128 characters');
        }
        return $idempotencyKey;
    }

    private function validatedActorRef(string $actorRef): string
    {
        $actorRef = trim($actorRef);
        if ($actorRef === '') {
            throw new ApiProblem('SAND_AI_AUTHENTICATION_FAILED', 'An authenticated administrator is required');
        }
        return $actorRef;
    }

    /** @param array<string,mixed> $input @return array{code:string,name:string,adapter:string,timeout_ms:int}|null */
    private function optionalProvider(array $input): ?array
    {
        if (!array_key_exists('provider', $input)) {
            return null;
        }
        $provider = $input['provider'];
        if (!is_array($provider) || array_diff(array_keys($provider), self::PROVIDER_FIELDS) !== []) {
            throw $this->invalid('Provider contains unsupported fields');
        }
        if (!in_array($provider['adapter'] ?? null, ['openai_compatible', 'local_runtime', 'local_ocr_runtime', 'cohere_rerank', 'jina_rerank'], true)) {
            throw $this->invalid('New provider adapter is unsupported');
        }
        return [
            'code' => $this->requiredCode($provider, 'code'),
            'name' => $this->requiredString($provider, 'name', 160),
            'adapter' => $provider['adapter'],
            'timeout_ms' => $this->requiredBoundedInt($provider, 'timeout_ms', 1000, 120000),
        ];
    }

    /** @param array<string,mixed> $input @return array{code:string,name:string,type:string,capabilities:list<string>}|null */
    private function optionalModel(array $input): ?array
    {
        if (!array_key_exists('model', $input)) {
            return null;
        }
        $model = $input['model'];
        if (!is_array($model) || array_diff(array_keys($model), self::MODEL_FIELDS) !== []) {
            throw $this->invalid('Model contains unsupported fields');
        }
        $type = $model['type'] ?? null;
        if (!in_array($type, ['chat', 'embedding', 'rerank', 'audio_speech', 'audio_transcription', 'image_ocr'], true)
            || !is_array($model['capabilities'] ?? null) || !array_is_list($model['capabilities'])) {
            throw $this->invalid('New model type or capabilities are invalid');
        }
        $capabilities = [];
        foreach ($model['capabilities'] as $capability) {
            if (!is_string($capability) || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $capability) !== 1) {
                throw $this->invalid('Model capabilities are invalid');
            }
            $capabilities[] = $capability;
        }
        if ($type === 'image_ocr' && $capabilities !== ['image_ocr']) {
            throw $this->invalid('Local OCR model requires only the image_ocr capability');
        }
        if ($type === 'rerank' && $capabilities !== ['rerank']) {
            throw $this->invalid('Professional rerank model requires only the rerank capability');
        }
        if (!in_array($type, $capabilities, true)) {
            throw $this->invalid('New model must include its model capability');
        }
        return [
            'code' => $this->requiredCode($model, 'code'),
            'name' => $this->requiredString($model, 'name', 160),
            'type' => $type,
            'capabilities' => $capabilities,
        ];
    }

    /** @return array<string,int|string> */
    private function config(mixed $value): array
    {
        return (new DeploymentDraftConfigurationPolicy())->normalize($value);
    }

    /** @param array<string,mixed> $input */
    private function optionalPositiveInt(array $input, string $field): ?int
    {
        if (!array_key_exists($field, $input)) {
            return null;
        }
        return $this->requiredBoundedInt($input, $field, 1, PHP_INT_MAX);
    }

    /** @param array<string,mixed> $input */
    private function requiredBoundedInt(array $input, string $field, int $minimum, int $maximum): int
    {
        if (!is_int($input[$field] ?? null) || $input[$field] < $minimum || $input[$field] > $maximum) {
            throw $this->invalid($field . ' must be a JSON integer in range');
        }
        return $input[$field];
    }

    /** @param array<string,mixed> $input */
    private function requiredCode(array $input, string $field): string
    {
        $value = $this->requiredString($input, $field, 96);
        if (preg_match('/^[a-z][a-z0-9_-]{0,95}$/', $value) !== 1) {
            throw $this->invalid($field . ' is invalid');
        }
        return $value;
    }

    /** @param array<string,mixed> $input */
    private function requiredString(array $input, string $field, int $maximum): string
    {
        if (!is_string($input[$field] ?? null)) {
            throw $this->invalid($field . ' must be a string');
        }
        $value = trim($input[$field]);
        if ($value === '' || strlen($value) > $maximum || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            throw $this->invalid($field . ' is invalid');
        }
        return $value;
    }

    private function invalid(string $message): ApiProblem
    {
        return new ApiProblem('SAND_AI_VALIDATION_ERROR', $message);
    }

    private function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map($this->canonical(...), $value);
        }
        ksort($value, SORT_STRING);
        return array_map($this->canonical(...), $value);
    }
}
