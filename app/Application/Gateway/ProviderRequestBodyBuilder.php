<?php

declare(strict_types=1);

namespace app\Application\Gateway;

/** The exact provider request body and its ledger digest are produced together. */
final class ProviderRequestBodyBuilder
{
    /** @param list<array{role:string,content:string}> $messages @return array<string,mixed> */
    public static function chat(string $remoteModel, array $messages, ?ChatCompletionOptions $options, string $outputTokenParameter = 'max_tokens', bool $streamSegments = false, ?\app\Infrastructure\Provider\AgentToolChatRequest $agentToolRequest = null): array
    {
        if ($agentToolRequest !== null) {
            if ($streamSegments) throw new \app\Api\Support\ApiProblem('SAND_AI_CAPABILITY_UNSUPPORTED', 'Agent tool rounds cannot stream');
            $agentToolRequest->assertMessages($messages);
        }
        $stream=$streamSegments || ($agentToolRequest?->stream??false);
        $body = ['model' => $remoteModel, 'messages' => $messages, 'stream' => $stream];
        if ($agentToolRequest !== null && $agentToolRequest->tools !== []) { $body['tools'] = $agentToolRequest->tools; $body['tool_choice'] = 'auto'; }
        if ($stream) {
            $body['stream_options'] = ['include_usage' => true];
        }
        if ($options?->maxOutputTokens !== null) { $body[$outputTokenParameter] = $options->maxOutputTokens; }
        if ($options?->reasoningEffort !== null) { $body['reasoning_effort'] = $options->reasoningEffort; }
        if ($options?->skillOutput !== null && \app\Application\Skill\SkillOutputStrategy::isStructured($options->skillOutput->strategy)) {
            if ($stream || $agentToolRequest !== null) throw new \app\Api\Support\ApiProblem('SAND_AI_CAPABILITY_UNSUPPORTED', 'Skill structured output is a single non-stream completion');
            unset($body['model'], $body['messages']);
            return \app\Infrastructure\Provider\NeuronSkillOutputAdapter::requestBody($remoteModel, $body, $messages, $options->skillOutput);
        }
        return $body;
    }

    /** @param array<string,mixed> $body */
    public static function digest(array $body): string
    {
        return CanonicalProviderRequest::digest($body);
    }

    /** @param array<string,mixed> $body */
    public static function bytes(array $body): string
    {
        return CanonicalProviderRequest::encode($body);
    }
}
