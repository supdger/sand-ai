<?php

declare(strict_types=1);

namespace app\Infrastructure\Agent;

use app\Api\Support\ApiProblem;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Providers\OpenAI\MessageMapper;

/** Official SDK message mapping with the existing frozen text-only dispatch boundary. */
final class NeuronTextWireMapper
{
    /** @param list<Message> $messages */
    public static function map(array $messages, ?string $instructions): array
    {
        // Tool outputs crossing SandAI's authorized source boundary must remain textual.
        foreach ($messages as $message) {
            if (!$message instanceof Message) self::invalid();
            if ($message instanceof ToolResultMessage) {
                foreach ($message->getToolCalls() as $call) {
                    if (!is_string($call->getResult())) self::invalid();
                }
            }
        }
        $mapped = (new MessageMapper())->map($messages);
        $wire = [];
        if ($instructions !== null && $instructions !== '') {
            $wire[] = ['role' => 'system', 'content' => $instructions];
        }
        foreach ($mapped as $item) {
            if (($item['role'] ?? null) instanceof MessageRole) $item['role'] = $item['role']->value;
            $content = $item['content'] ?? null;
            if (is_array($content)) {
                $text = [];
                foreach ($content as $block) {
                    if (!is_array($block) || array_keys($block) !== ['type', 'text']
                        || $block['type'] !== 'text' || !is_string($block['text'])) self::invalid();
                    $text[] = $block['text'];
                }
                // Match SDK Message::getContent's text projection; do not broaden the frozen wire.
                $content = implode(' ', $text);
                if ($content === '') $content = null;
            } elseif ($content !== null && !is_string($content)) self::invalid();
            $item['content'] = $content;
            if (isset($item['tool_calls'])) {
                foreach ($item['tool_calls'] as &$call) {
                    $arguments = $call['function']['arguments'] ?? null;
                    if (!is_string($arguments)) self::invalid();
                    // Keep existing child-body fingerprints, including Unicode and slash encoding.
                    try {
                        $inputs = json_decode($arguments, true, 512, JSON_THROW_ON_ERROR);
                        $call['function']['arguments'] = json_encode($inputs, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    } catch (\Throwable) { self::invalid(); }
                }
                unset($call);
                // Existing body order also belongs to the persisted request fingerprint.
                $item = ['role' => $item['role'], 'content' => $content, 'tool_calls' => $item['tool_calls']];
            }
            $wire[] = $item;
        }
        return $wire;
    }

    private static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_AGENT_PROTOCOL_INVALID', 'SDK message exceeds the frozen textual agent protocol');
    }
}
