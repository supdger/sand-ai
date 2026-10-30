<?php

declare(strict_types=1);

namespace app\Application\Audio\External;

use app\Application\Audio\ControlledAudioInput;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\AIProviderInterface;

/** Official SDK request assembly with exact existing SandAI wire projections. */
final class NeuronAudioTranscriptionRequestBuilder
{
    private const VERIFICATION_SAMPLE_SHA256 = '4ad32f3c91f35d132bd740fd63cf0aab2e8c0636936d4a9b0e7f5c0f659328e3';

    /** @param array<string,mixed> $parameters */
    public function openAi(OpenAiCompatibleAudioRuntime $runtime, ControlledAudioInput $input, array $parameters, bool $verification = false): OpenAiCompatibleAudioHttpRequest
    {
        if ($verification && ($input->reference->mediaType !== 'audio/wav'
            || !hash_equals(self::VERIFICATION_SAMPLE_SHA256, $input->reference->contentSha256)
            || ($parameters['language'] ?? null) !== 'en' || ($parameters['glossary'] ?? null) !== [])) {
            throw new \RuntimeException('SDK verification input differs from the frozen public sample');
        }
        $diarized = $parameters['speaker_diarization'];
        $model = $runtime->remoteModel();
        if ((!$diarized && $model !== 'whisper-1') || ($diarized && $model !== 'gpt-4o-transcribe-diarize')
            || ($diarized && $parameters['glossary'] !== [])) {
            throw new \RuntimeException('Unsupported frozen transcription model or prompt');
        }
        $sdkParameters = ['response_format' => $diarized ? 'diarized_json' : 'verbose_json'];
        $sdkParameters[$diarized ? 'chunking_strategy' : 'timestamp_granularities[]'] = $diarized ? 'auto' : 'segment';
        $fields = ['model' => $model, ...$sdkParameters];
        $order = ['file', ...array_keys($fields)];
        $language = $parameters['language'];
        if (!in_array($language, ['auto', 'zh', 'en'], true)) throw new \RuntimeException('Unsupported frozen transcription language');
        if ($language !== 'auto') {
            $fields['language'] = $language;
            $order[] = 'language';
        }
        $prompt = $parameters['glossary'] === [] ? null : implode(', ', $parameters['glossary']);
        if ($prompt !== null) {
            $fields['prompt'] = $prompt;
            $order[] = 'prompt';
        }
        if ($verification) {
            $order = ['file', 'model', 'response_format', 'language', $diarized ? 'chunking_strategy' : 'timestamp_granularities[]'];
        }
        $capture = new NeuronAudioTranscriptionRequestCapture($runtime, $input, $fields, $order, $this->extension($input), $language === 'auto', $verification);
        $provider = new NeuronOpenAiTranscriptionProvider(
            $runtime->apiKey(), $model, $language, $sdkParameters, $this->baseUri($runtime),
            NeuronAudioTranscriptionRequestCapture::headers($runtime), $capture,
        );
        return $this->assemble($provider, $input, $prompt, $capture);
    }

    /** @param list<string> $glossary */
    public function glm(OpenAiCompatibleAudioRuntime $runtime, ControlledAudioInput $input, array $glossary): OpenAiCompatibleAudioHttpRequest
    {
        $sdkParameters = ['stream' => 'false'];
        if ($glossary !== []) $sdkParameters['hotwords'] = json_encode($glossary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $fields = ['model' => $runtime->remoteModel(), ...$sdkParameters];
        $capture = new NeuronAudioTranscriptionRequestCapture($runtime, $input, $fields, ['file', ...array_keys($fields)], $this->extension($input), false);
        $provider = new NeuronZaiTranscriptionProvider(
            $runtime->apiKey(), $runtime->remoteModel(), $sdkParameters, $this->baseUri($runtime),
            NeuronAudioTranscriptionRequestCapture::headers($runtime), $capture,
        );
        return $this->assemble($provider, $input, null, $capture);
    }

    private function assemble(AIProviderInterface $provider, ControlledAudioInput $input, ?string $prompt, NeuronAudioTranscriptionRequestCapture $capture): OpenAiCompatibleAudioHttpRequest
    {
        if (!is_file($input->temporaryPath) || !is_readable($input->temporaryPath)) {
            throw new \RuntimeException('Controlled private audio file is unavailable');
        }
        $message = new UserMessage($prompt);
        $message->addContent(new AudioContent($input->temporaryPath, SourceType::URL, $input->reference->mediaType));
        try {
            $provider->chat($message);
        } catch (\Throwable $exception) {
            if (!$capture->capturedBy($exception)) throw $exception;
            return $capture->completion();
        }
        throw new \RuntimeException('SDK returned a response instead of a bounded request');
    }

    private function baseUri(OpenAiCompatibleAudioRuntime $runtime): string
    {
        $uri = $runtime->endpoint()->url;
        $suffix = '/audio/transcriptions';
        if (!str_ends_with($uri, $suffix)) throw new \RuntimeException('Verified ASR endpoint has an invalid path');
        return substr($uri, 0, -strlen($suffix));
    }

    private function extension(ControlledAudioInput $input): string
    {
        return match ($input->reference->mediaType) {
            'audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a', 'audio/ogg' => 'ogg',
            'audio/wav' => 'wav', 'audio/webm' => 'webm', 'audio/flac' => 'flac',
            default => throw new \RuntimeException('Unsupported controlled audio media type'),
        };
    }
}
