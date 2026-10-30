<?php

declare(strict_types=1);

namespace app\Application\Audio\Speech;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\ProviderSendBoundary;
use app\Infrastructure\Provider\HttpsProviderEndpointResolver;
use app\Infrastructure\Provider\OpenAiCompatibleExecutionConfigPolicy;
use NeuronAI\Chat\Messages\UserMessage;

/** Provider JSON and binary response remain within this replaceable connector. */
final class OpenAiCompatibleSpeechSynthesisDriver implements SpeechSendBoundaryDriver
{
    private const MAX_AUDIO_BYTES = 8388608;

    /** @param array<string,mixed> $providerConfig */
    public function __construct(
        private readonly array $providerConfig,
        private readonly HttpsProviderEndpointResolver $endpoints = new HttpsProviderEndpointResolver(),
        private readonly int $timeoutMs = 120000,
    ) {
        if ($timeoutMs < 1000 || $timeoutMs > 120000) {
            throw new ApiProblem('SAND_AI_PROVIDER_CONFIG_INVALID', 'Speech provider timeout is invalid');
        }
    }

    public function code(): string
    {
        return 'openai_compatible_speech';
    }

    public function synthesize(SpeechSynthesisRequest $request): SpeechSynthesisResult
    {
        return $this->synthesizeWithBoundary($request, new ProviderSendBoundary());
    }

    public function synthesizeWithBoundary(SpeechSynthesisRequest $request, ProviderSendBoundary $boundary): SpeechSynthesisResult
    {
        $config = (new OpenAiCompatibleExecutionConfigPolicy())->normalize($this->providerConfig);
        if ($config['idempotency_mode'] !== 'none' || $config['idempotency_header'] !== null) {
            throw new ApiProblem('SAND_AI_PROVIDER_CONFIG_INVALID', 'Speech provider idempotency is not configured');
        }
        $maxBytes = min(self::MAX_AUDIO_BYTES, $config['response_max_bytes']);
        $endpoint = $this->endpoints->audioSpeech($config);
        $headers = $config['extra_headers'] + [
            'Authorization' => 'Bearer ' . $config['api_key'],
            'Accept' => SpeechAudioFormat::mime($request->format),
            'Content-Type' => 'application/json',
        ];
        $client = new NeuronSpeechHttpClient($endpoint, $headers, $request, $boundary, $this->timeoutMs, $maxBytes);
        $provider = new NeuronSpeechProvider($config['api_key'], $request->model, $request->voice,
            $config['base_url'], $headers, $client, $request->format);
        $body = $client->completion($provider->chat(new UserMessage($request->text))->message());
        if (!($request->format === 'wav' ? SpeechAudioFormat::validWav($body) : $this->looksLikeMp3($body))) {
            throw new ApiProblem('SAND_AI_AUDIO_SPEECH_PROVIDER_INVALID', 'Speech provider returned invalid audio');
        }
        return new SpeechSynthesisResult($body, mb_strlen($request->text, 'UTF-8'), $request->format);
    }

    private function looksLikeMp3(string $body): bool
    {
        $offset = 0;
        if (str_starts_with($body, 'ID3')) {
            if (strlen($body) < 10 || !in_array(ord($body[3]), [2, 3, 4], true)) {
                return false;
            }
            $size = 0;
            for ($index = 6; $index <= 9; $index++) {
                $part = ord($body[$index]);
                if (($part & 0x80) !== 0) {
                    return false;
                }
                $size = ($size << 7) | $part;
            }
            $offset = 10 + $size + (ord($body[3]) === 4 && (ord($body[5]) & 0x10) !== 0 ? 10 : 0);
        }
        $first = $this->frameLength($body, $offset);
        if ($first === null || $offset + $first >= strlen($body)) {
            return false;
        }
        $second = $this->frameLength($body, $offset + $first);
        return $second !== null && $offset + $first + $second <= strlen($body);
    }

    private function frameLength(string $body, int $offset): ?int
    {
        if ($offset < 0 || $offset + 4 > strlen($body)
            || ord($body[$offset]) !== 0xff || (ord($body[$offset + 1]) & 0xe0) !== 0xe0) {
            return null;
        }
        $version = (ord($body[$offset + 1]) >> 3) & 0x03;
        $layer = (ord($body[$offset + 1]) >> 1) & 0x03;
        $rateIndex = (ord($body[$offset + 2]) >> 4) & 0x0f;
        $sampleIndex = (ord($body[$offset + 2]) >> 2) & 0x03;
        if ($version === 1 || $layer !== 1 || $rateIndex === 0 || $rateIndex === 15 || $sampleIndex === 3) {
            return null;
        }
        $rates = $version === 3
            ? [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320]
            : [0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160];
        $sampleRate = intdiv([44100, 48000, 32000][$sampleIndex], $version === 3 ? 1 : ($version === 2 ? 2 : 4));
        $padding = (ord($body[$offset + 2]) >> 1) & 1;
        $length = intdiv(($version === 3 ? 144 : 72) * $rates[$rateIndex] * 1000, $sampleRate) + $padding;
        return $length >= 24 ? $length : null;
    }
}
