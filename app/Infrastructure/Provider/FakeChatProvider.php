<?php

declare(strict_types=1);

namespace app\Infrastructure\Provider;

use app\Application\Gateway\ChatCompletionOptions;

final class FakeChatProvider
{
    private ?ChatCompletionOptions $lastOptions = null;

    /** @param list<array{role: string, content: string}> $messages @return array{content: string, input_tokens: int, output_tokens: int, usage_measured: bool} */
    public function complete(array $messages, ?ChatCompletionOptions $options = null): array
    {
        $this->lastOptions = $options;
        $prompt = implode("\n", array_map(static fn (array $message): string => $message['role'] . ': ' . $message['content'], $messages));
        $last = end($messages);
        $content = 'Fake provider response: ' . ($last['content'] ?? '');
        if ($options?->maxOutputTokens !== null) {
            $content = $this->limitContent($content, $options->maxOutputTokens);
        }
        return [
            'content' => $content,
            'input_tokens' => $this->tokenCount($prompt),
            'output_tokens' => $this->tokenCount($content),
            'usage_measured' => true,
        ];
    }

    public function lastOptions(): ?ChatCompletionOptions
    {
        return $this->lastOptions;
    }

    private function limitContent(string $content, int $maxOutputTokens): string
    {
        $tokens = preg_split('/\s+/u', trim($content), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($tokens) <= $maxOutputTokens) {
            return $content;
        }

        return implode(' ', array_slice($tokens, 0, $maxOutputTokens));
    }

    private function tokenCount(string $text): int
    {
        return max(1, count(preg_split('/\\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: []));
    }
}
