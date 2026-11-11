<?php

declare(strict_types=1);

/**
 * Neuron SDK 的隔离测试替身：覆盖 SandAI 受控调用链实际触达的面
 * （受控 provider 的 setTools/chat、协议请求的工具映射与只读工具）。
 * 仓库不携带 vendor；真实 SDK 存在时本文件不应被加载，宿主运行的是
 * neuron-core/neuron-ai 4.0.2 本体。替身只保留测试触达的行为，不模拟
 * HTTP、流式或 checkpoint。
 */

namespace NeuronAI\Exceptions {
    class NeuronException extends \Exception {}
    class ProviderException extends NeuronException {}
}

namespace NeuronAI\Chat\Enums {
    enum MessageRole: string
    {
        case USER = 'user';
        case ASSISTANT = 'assistant';
        case MODEL = 'model';
        case TOOL = 'tool';
        case SYSTEM = 'system';
        case DEVELOPER = 'developer';
    }
}

namespace NeuronAI\Chat\Messages\ContentBlocks {
    interface ContentBlockInterface {}
    final class TextContent implements ContentBlockInterface
    {
        public function __construct(public string $content) {}
    }
}

namespace NeuronAI\Chat\Messages {
    use NeuronAI\Chat\Enums\MessageRole;
    use NeuronAI\Chat\Messages\ContentBlocks\TextContent;

    class Usage
    {
        public function __construct(
            public int $inputTokens,
            public int $outputTokens,
            public int $cachedInputTokens = 0,
            public int $reasoningTokens = 0,
        ) {}
    }

    class Message
    {
        private array $metadata = [];
        private ?Usage $usage = null;

        public function __construct(protected MessageRole $role, protected ?string $content = null) {}

        public function getRole(): MessageRole { return $this->role; }
        public function getContent(): ?string { return $this->content; }
        public function getContentBlocks(): array
        {
            return $this->content === null ? [] : [new TextContent($this->content)];
        }
        public function addMetadata(string $key, mixed $value): static
        {
            $this->metadata[$key] = $value;
            return $this;
        }
        public function setUsage(Usage $usage): static
        {
            $this->usage = $usage;
            return $this;
        }
        public function getUsage(): ?Usage { return $this->usage; }
    }

    class SystemMessage extends Message
    {
        public function __construct(?string $content = null)
        {
            parent::__construct(MessageRole::SYSTEM, $content);
        }
    }

    class UserMessage extends Message
    {
        public function __construct(?string $content)
        {
            parent::__construct(MessageRole::USER, $content);
        }
    }

    class AssistantMessage extends Message
    {
        public function __construct(?string $content = null)
        {
            parent::__construct(MessageRole::ASSISTANT, $content);
        }
    }

    class ToolCallMessage extends AssistantMessage
    {
        public function __construct(?string $content = null, protected array $tools = [])
        {
            parent::__construct($content);
        }
        public function getToolCalls(): array { return $this->tools; }
    }

    class ToolResultMessage extends UserMessage
    {
        public function __construct(protected array $tools)
        {
            parent::__construct(null);
        }
        public function getToolCalls(): array { return $this->tools; }
    }
}

namespace NeuronAI\Chat\Messages\Stream\Chunks {
    abstract class StreamChunk
    {
        public function __construct(public readonly ?string $messageId = null) {}
        abstract public function toArray(): array;
    }
}

namespace NeuronAI\Tools {
    interface ToolInterface
    {
        public function getName(): string;
        public function getDescription(): ?string;
        public function getInputSchema(): array;
        public function getParameters(): array;
    }
    interface ProviderToolInterface {}
    interface DeferredToolInterface {}

    class ToolCall
    {
        private mixed $result = null;

        public function __construct(
            protected string $name,
            protected ?string $callId = null,
            protected array $inputs = [],
            protected ?string $description = null,
            protected bool $deferred = false,
        ) {}

        public function getName(): string { return $this->name; }
        public function getCallId(): ?string { return $this->callId; }
        public function getInputs(): array { return $this->inputs; }
        public function getDescription(): ?string { return $this->description; }
        public function isDeferred(): bool { return $this->deferred; }
        public function setResult(mixed $result): static
        {
            $this->result = $result;
            return $this;
        }
        public function getResult(): mixed { return $this->result; }
    }

    abstract class Tool implements ToolInterface
    {
        protected string $name;
        protected ?string $description = null;
        protected array $properties = [];
        protected array $parameters = [];
        protected array $inputs = [];
        protected ?string $callId = null;
        protected int $maxRuns = 1;

        public function getName(): string { return $this->name; }
        public function getDescription(): ?string { return $this->description; }
        public function getInputSchema(): array
        {
            return ['type' => 'object', 'properties' => new \stdClass(), 'required' => []];
        }
        public function getParameters(): array { return $this->parameters; }
        public function setParameters(array $parameters): static
        {
            $this->parameters = $parameters;
            return $this;
        }
        public function setMaxRuns(int $maxRuns): static
        {
            $this->maxRuns = $maxRuns;
            return $this;
        }
        public function getInputs(): array { return $this->inputs; }
        public function getCallId(): ?string { return $this->callId; }
        public function setCallId(string $callId): static
        {
            $this->callId = $callId;
            return $this;
        }
    }

    final class ToolPropertyFactory
    {
        public static function fromSchema(array $schema): array { return []; }
    }
}

namespace NeuronAI\HttpClient {
    interface HttpClientInterface {}
}

namespace NeuronAI\Providers {
    use NeuronAI\Chat\Messages\Message;
    use NeuronAI\Chat\Messages\Stream\Chunks\StreamChunk;
    use NeuronAI\Chat\Messages\SystemMessage;
    use NeuronAI\Exceptions\ProviderException;
    use NeuronAI\HttpClient\HttpClientInterface;
    use NeuronAI\Tools\DeferredToolInterface;
    use NeuronAI\Tools\ToolCall;
    use NeuronAI\Tools\ToolInterface;

    interface AIProviderInterface
    {
        public function getModel(): string;
        public function systemPrompt(SystemMessage|string|null $prompt): AIProviderInterface;
        public function setTools(array $tools): AIProviderInterface;
        public function chat(Message ...$messages): ProviderResponse;
        /** @return \Generator<int, StreamChunk, mixed, ProviderResponse> */
        public function stream(Message ...$messages): \Generator;
        public function structured(array|Message $messages, string $class, array $response_schema): ProviderResponse;
        public function setHttpClient(HttpClientInterface $client): AIProviderInterface;
    }

    class ProviderResponse
    {
        public function __construct(
            protected Message $message,
            protected ?string $body = null,
            protected array $headers = [],
            protected array $metadata = [],
        ) {}
        public function message(): Message { return $this->message; }
        public function body(): ?string { return $this->body; }
        public function headers(): array { return $this->headers; }
        public function metadata(string $key, mixed $default = null): mixed
        {
            return $this->metadata[$key] ?? $default;
        }
    }

    trait HandleWithTools
    {
        protected array $tools = [];

        public function setTools(array $tools): AIProviderInterface
        {
            $this->tools = $tools;
            return $this;
        }

        public function findTool(string $name): ToolInterface
        {
            foreach ($this->tools as $tool) {
                if ($tool instanceof ToolInterface && $tool->getName() === $name) return $tool;
            }
            throw new ProviderException('Tool not found: ' . $name);
        }

        public function newToolCall(string $name, ?string $callId, array $inputs): ToolCall
        {
            $tool = $this->findTool($name);
            return new ToolCall($name, $callId, $inputs, $tool->getDescription(), $tool instanceof DeferredToolInterface);
        }
    }
}

namespace NeuronAI\Providers\OpenAI {
    use NeuronAI\Chat\Enums\MessageRole;
    use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
    use NeuronAI\Chat\Messages\Message;
    use NeuronAI\Chat\Messages\ToolCallMessage;
    use NeuronAI\Chat\Messages\ToolResultMessage;
    use NeuronAI\Exceptions\ProviderException;
    use NeuronAI\Tools\ProviderToolInterface;
    use NeuronAI\Tools\ToolCall;
    use NeuronAI\Tools\ToolInterface;

    class ToolMapper
    {
        public function map(array $tools): array
        {
            $mapping = [];
            foreach ($tools as $tool) {
                $mapping[] = match (true) {
                    $tool instanceof ToolInterface => $this->mapTool($tool),
                    $tool instanceof ProviderToolInterface => throw new ProviderException('Provider tools are unsupported'),
                    default => throw new ProviderException('Could not map tool type ' . $tool::class),
                };
            }
            return $mapping;
        }

        private function mapTool(ToolInterface $tool): array
        {
            $payload = [
                'type' => 'function',
                'function' => [
                    'name' => $tool->getName(),
                    'description' => $tool->getDescription(),
                    'parameters' => $tool->getInputSchema(),
                ],
            ];
            if ($tool->getParameters() !== []) {
                $payload['function'] = array_merge($payload['function'], $tool->getParameters());
            }
            return $payload;
        }
    }

    class MessageMapper
    {
        public function map(array $messages): array
        {
            $mapped = [];
            foreach ($messages as $message) {
                if ($message instanceof ToolResultMessage) {
                    foreach ($message->getToolCalls() as $call) {
                        $mapped[] = [
                            'role' => MessageRole::TOOL,
                            'tool_call_id' => $call->getCallId(),
                            'content' => is_string($call->getResult()) ? $call->getResult() : json_encode($call->getResult()),
                        ];
                    }
                    continue;
                }
                $item = ['role' => $message->getRole(), 'content' => $this->mapBlocks($message)];
                if ($message instanceof ToolCallMessage) {
                    $item['tool_calls'] = array_map(
                        static fn (ToolCall $call): array => [
                            'id' => $call->getCallId(),
                            'type' => 'function',
                            'function' => [
                                'name' => $call->getName(),
                                'arguments' => json_encode(
                                    $call->getInputs() === [] ? new \stdClass() : $call->getInputs(),
                                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                                ),
                            ],
                        ],
                        $message->getToolCalls(),
                    );
                }
                $mapped[] = $item;
            }
            return $mapped;
        }

        private function mapBlocks(Message $message): array
        {
            $blocks = [];
            foreach ($message->getContentBlocks() as $block) {
                if ($block instanceof TextContent) $blocks[] = ['type' => 'text', 'text' => $block->content];
            }
            return $blocks;
        }
    }
}
