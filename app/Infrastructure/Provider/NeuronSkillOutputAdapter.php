<?php

declare(strict_types=1);

namespace app\Infrastructure\Provider;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\NeuronOutputContract;
use app\Application\Skill\SkillOutputStrategy;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Providers\ProviderResponse;

/** Uses official request construction twice: preflight capture, then the once-only real transport. */
final class NeuronSkillOutputAdapter
{
    /** @param array<string,mixed> $parameters @param array<string,string> $headers */
    public static function provider(NeuronOutputContract $output, string $key, string $model, array $parameters, string $endpoint, array $headers, HttpClientInterface $client): OpenAI
    {
        if (!\Composer\InstalledVersions::isInstalled('neuron-core/neuron-ai')
            || !version_compare((string) \Composer\InstalledVersions::getVersion('neuron-core/neuron-ai'), '4.0.2', '>=')) {
            throw new ApiProblem('SAND_AI_PROVIDER_CONFIG_INVALID', 'The required Neuron Skill runtime is unavailable');
        }
        // SDK owns messages and response_format. Frozen legacy messages are only
        // passed by the old material adapter, never by these new strategies.
        if (array_key_exists('messages', $parameters) || array_key_exists('response_format', $parameters)) {
            throw new ApiProblem('SAND_AI_DISPATCH_STATE_INVALID', 'Official Skill protocol fields cannot be overridden');
        }
        return match ($output->strategy) {
            SkillOutputStrategy::ZAI => new NeuronSkillZaiProvider($key, $model, $parameters, $endpoint, $headers, $client),
            SkillOutputStrategy::OPENAI => new NeuronMaterialChatProvider($key, $model, $parameters, $endpoint, $headers, $client),
            default => throw new ApiProblem('SAND_AI_CAPABILITY_UNSUPPORTED', 'This strategy does not use structured request construction'),
        };
    }

    /** @param list<array{role:string,content:string}> $messages @return list<Message> */
    public static function messages(array $messages): array
    {
        return array_map(static fn (array $message): Message => new Message(MessageRole::from($message['role']), $message['content']), $messages);
    }

    /** @param list<Message> $messages */
    public static function structured(OpenAI $provider, array $messages, NeuronOutputContract $output): ProviderResponse
    {
        // OpenAI strict=false preserves the exact dynamic schema, including
        // optional properties. The domain validator remains authoritative.
        return $provider->structured($messages, $output::class, $output->schema);
    }

    /** @param array<string,mixed> $parameters @param list<array{role:string,content:string}> $messages @return array<string,mixed> */
    public static function requestBody(string $model, array $parameters, array $messages, NeuronOutputContract $output): array
    {
        $capture = new NeuronSkillRequestCapture();
        $provider = self::provider($output, 'request-preparation-only', $model, $parameters, 'https://request-preparation.invalid/chat/completions', [], $capture);
        try {
            self::structured($provider, self::messages($messages), $output);
        } catch (NeuronSkillRequestCaptured) {
            return $capture->body();
        }
        throw new ApiProblem('SAND_AI_DISPATCH_STATE_INVALID', 'Official Skill request preparation did not stop before transport');
    }
}
