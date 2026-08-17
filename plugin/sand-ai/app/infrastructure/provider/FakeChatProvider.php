<?php

declare(strict_types=1);

namespace plugin\SandAi\app\infrastructure\provider;

final class FakeChatProvider
{
    /** @param list<array{role: string, content: string}> $messages @return array{content: string, input_tokens: int, output_tokens: int} */
    public function complete(array $messages): array
    {
        $prompt = implode("\n", array_map(static fn (array $message): string => $message['role'] . ': ' . $message['content'], $messages));
        $last = end($messages);
        $content = 'Fake provider response: ' . ($last['content'] ?? '');

        return [
            'content' => $content,
            'input_tokens' => $this->tokenCount($prompt),
            'output_tokens' => $this->tokenCount($content),
        ];
    }

    private function tokenCount(string $text): int
    {
        return max(1, count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: []));
    }
}
