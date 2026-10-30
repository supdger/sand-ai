<?php

declare(strict_types=1);

namespace app\Application\Audio\External;

use app\Api\Support\ApiProblem;
use app\Infrastructure\Provider\OpenAiCompatibleExecutionConfigPolicy;

/** Audio has no provider idempotency/query override until that contract is verified. */
final class OpenAiCompatibleAudioTranscriptionConfigPolicy
{
    /** @param array<string,mixed> $config @return array{base_url:string,api_key:string,extra_headers:array<string,string>,response_max_bytes:int,provider_idempotency_mode:string} */
    public function normalize(array $config): array
    {
        $canonicalKeys = ['base_url', 'api_key', 'extra_headers', 'output_token_parameter', 'response_max_bytes', 'idempotency_mode', 'idempotency_header'];
        $keys = array_keys($config);
        if ($keys !== $canonicalKeys && $keys !== [...$canonicalKeys, 'stream_options']) throw $this->invalid();
        // Kept only for shared chat-provider ciphertext compatibility; audio never reads it.
        if (array_key_exists('stream_options', $config)) {
            $streamOptions = $config['stream_options'];
            if (!is_array($streamOptions) || array_keys($streamOptions) !== ['include_usage'] || !is_bool($streamOptions['include_usage'])) throw $this->invalid();
            unset($config['stream_options']);
        }
        $normalized = (new OpenAiCompatibleExecutionConfigPolicy())->normalize($config);
        if ($normalized !== $config) throw $this->invalid();
        if ($normalized['idempotency_mode'] !== 'none' || $normalized['idempotency_header'] !== null) {
            throw $this->invalid();
        }
        return [
            'base_url' => $normalized['base_url'],
            'api_key' => $normalized['api_key'],
            'extra_headers' => $normalized['extra_headers'],
            'response_max_bytes' => $normalized['response_max_bytes'],
            'provider_idempotency_mode' => 'none',
        ];
    }

    private function invalid(): ApiProblem { return new ApiProblem('SAND_AI_PROVIDER_CONFIG_INVALID', 'OpenAI-compatible audio configuration is invalid'); }
}
