<?php

declare(strict_types=1);

/**
 * Neuron SDK 的隔离测试替身：覆盖 SandAI 受控调用链实际触达的面
 * （受控 provider 的 setTools/chat、协议请求的工具映射与只读工具、
 * 文本解析用的 RAG Document/分隔符 splitter/文本读取器）。
 * 仓库不携带 vendor；真实 SDK 存在时本文件不应被加载，宿主运行的是
 * neuron-core/neuron-ai 本体。替身只保留测试触达的行为，不模拟
 * HTTP、流式或 checkpoint；RAG 部分按 vendor 4.1.22 语义逐字复制。
 */

namespace NeuronAI\Exceptions {
    class NeuronException extends \Exception {}
    class ProviderException extends NeuronException {}
    class VectorStoreException extends NeuronException {}
    class DataReaderException extends NeuronException {}
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

    final class TextChunk extends StreamChunk
    {
        public function __construct(public readonly string $content, ?string $messageId = null)
        {
            parent::__construct($messageId);
        }
        public function toArray(): array
        {
            return ['type' => 'text', 'content' => $this->content];
        }
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
    enum HttpMethod: string
    {
        case GET = 'GET';
        case POST = 'POST';
    }

    interface HttpClientInterface {}

    /** The transport reads raw SSE lines through the pinned stream. */
    interface StreamInterface
    {
        public function eof(): bool;
        public function readLine(): string;
        public function read(int $length): string;
    }

    final class HttpRequest
    {
        public function __construct(
            public readonly HttpMethod $method,
            public readonly string $uri,
            public readonly array $headers,
            public readonly mixed $body,
            public readonly ?float $timeout = null,
        ) {}

        public static function post(string $uri, array $body, array $headers): self
        {
            return new self(HttpMethod::POST, $uri, $headers, $body);
        }
    }

    final class HttpResponse {}
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
    use NeuronAI\Chat\Messages\AssistantMessage;
    use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
    use NeuronAI\Chat\Messages\Message;
    use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
    use NeuronAI\Chat\Messages\SystemMessage;
    use NeuronAI\Chat\Messages\ToolCallMessage;
    use NeuronAI\Chat\Messages\ToolResultMessage;
    use NeuronAI\Chat\Messages\Usage;
    use NeuronAI\Exceptions\ProviderException;
    use NeuronAI\HttpClient\HttpClientInterface;
    use NeuronAI\HttpClient\HttpRequest;
    use NeuronAI\Providers\AIProviderInterface;
    use NeuronAI\Providers\HandleWithTools;
    use NeuronAI\Providers\ProviderResponse;
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

    /**
     * Stub of the official OpenAI provider's stream surface SandAI pins:
     * the subclass freezes the request body, this consumes the pinned
     * transport's SSE lines, yields text chunks and returns usage.
     */
    class OpenAI implements AIProviderInterface
    {
        use HandleWithTools;

        public function __construct(
            protected string $key,
            protected string $model,
            protected array $parameters = [],
            protected bool $stream = false,
            protected ?HttpClientInterface $httpClient = null,
        ) {}

        public function getModel(): string { return $this->model; }
        public function systemPrompt(SystemMessage|string|null $prompt): AIProviderInterface { return $this; }
        public function chat(Message ...$messages): ProviderResponse
        {
            throw new ProviderException('Buffered chat is outside the stub surface');
        }
        public function stream(Message ...$messages): \Generator
        {
            if ($this->httpClient === null) throw new ProviderException('No HTTP client');
            $stream = $this->httpClient->stream($this->createChatHttpRequest($this->parameters));
            $content = '';
            $usage = null;
            while (!$stream->eof()) {
                $line = trim($stream->readLine());
                if (!str_starts_with($line, 'data:')) continue;
                $data = trim(substr($line, 5));
                if ($data === '[DONE]') break;
                if ($data === '') continue;
                $event = json_decode($data, true);
                if (!is_array($event)) continue;
                if (isset($event['usage'])) $usage = $event['usage'];
                $delta = $event['choices'][0]['delta'] ?? [];
                $text = is_array($delta) && is_string($delta['content'] ?? null) ? $delta['content'] : '';
                if ($text !== '') {
                    $content .= $text;
                    yield new TextChunk($text);
                }
            }
            $message = new AssistantMessage($content);
            if (is_array($usage)) {
                $message->setUsage(new Usage((int) $usage['prompt_tokens'], (int) $usage['completion_tokens']));
            }
            return new ProviderResponse($message);
        }
        public function structured(array|Message $messages, string $class, array $response_schema): ProviderResponse
        {
            throw new ProviderException('Structured output is outside the stub surface');
        }
        public function setHttpClient(HttpClientInterface $client): AIProviderInterface
        {
            $this->httpClient = $client;
            return $this;
        }
        protected function createChatHttpRequest(array $payload): HttpRequest
        {
            return HttpRequest::post('/', $payload, []);
        }
    }
}

namespace NeuronAI {
    /** 计数器替身：测试只要求 ID 唯一且形似 UUID，不复现 UUIDv7 时序。 */
    class UniqueIdGenerator
    {
        private static int $sequenceNo = 0;

        public static function generateId(string $prefix = ''): string
        {
            return $prefix . self::generateUUID();
        }

        public static function generateUUID(): string
        {
            return sprintf('00000000-0000-7000-8000-%012d', ++self::$sequenceNo);
        }
    }
}

namespace NeuronAI\RAG\Schema {
    use NeuronAI\Exceptions\VectorStoreException;

    class DocumentSchemaException extends VectorStoreException
    {
    }

    /** 只保留替身触达的保留字段常量。 */
    final class DocumentSchema
    {
        public const RESERVED_FIELDS = [
            'id',
            'content',
            'embedding',
            'score',
            'sourceType',
            'sourceName',
            'metadata',
            '_neuron_metadata',
            '_vectors',
            '_rankingScore',
            'vector_distance',
        ];
    }
}

namespace NeuronAI\RAG {
    use JsonSerializable;
    use NeuronAI\RAG\Schema\DocumentSchema;
    use NeuronAI\RAG\Schema\DocumentSchemaException;
    use NeuronAI\UniqueIdGenerator;

    use function array_keys;
    use function array_map;
    use function in_array;
    use function is_float;
    use function is_int;
    use function is_string;

    final class Document implements JsonSerializable
    {
        protected string|int $id;

        /**
         * @var float[]|null
         */
        protected ?array $embedding = null;

        protected string $sourceType = 'manual';

        protected string $sourceName = 'manual';

        protected ?float $score = null;

        /**
         * @var array<string, mixed>
         */
        protected array $metadata = [];

        /**
         * @throws DocumentSchemaException
         */
        public function __construct(protected string $content)
        {
            if ($content === '') {
                throw new DocumentSchemaException('Document content cannot be empty.');
            }

            $this->id = UniqueIdGenerator::generateUUID();
        }

        public function getId(): string|int
        {
            return $this->id;
        }

        public function getContent(): string
        {
            return $this->content;
        }

        /**
         * @return float[]|null
         */
        public function getEmbedding(): ?array
        {
            return $this->embedding;
        }

        /**
         * @param array<int, int|float>|null $embedding
         * @throws DocumentSchemaException
         */
        public function setEmbedding(?array $embedding): self
        {
            if ($embedding === null) {
                $this->embedding = null;

                return $this;
            }

            if ($embedding === []) {
                throw new DocumentSchemaException('Document embedding cannot be empty.');
            }

            foreach ($embedding as $value) {
                if (!is_int($value) && !is_float($value)) {
                    throw new DocumentSchemaException('Document embedding accepts numeric values only.');
                }
            }

            $this->embedding = array_map(static fn (int|float $value): float => (float) $value, $embedding);

            return $this;
        }

        public function setId(string|int $id): self
        {
            $this->id = $id;

            return $this;
        }

        public function getSourceType(): string
        {
            return $this->sourceType;
        }

        public function setSourceType(string $sourceType): self
        {
            $this->sourceType = $sourceType;

            return $this;
        }

        public function getSourceName(): string
        {
            return $this->sourceName;
        }

        public function setSourceName(string $sourceName): self
        {
            $this->sourceName = $sourceName;

            return $this;
        }

        public function getScore(): ?float
        {
            return $this->score;
        }

        public function setScore(?float $score): self
        {
            $this->score = $score;
            return $this;
        }

        /**
         * @return array<string, mixed>
         */
        public function getMetadata(): array
        {
            return $this->metadata;
        }

        /**
         * @param array<string, mixed> $metadata
         * @throws DocumentSchemaException
         */
        public function setMetadata(array $metadata): self
        {
            foreach (array_keys($metadata) as $key) {
                if (!is_string($key)) {
                    throw new DocumentSchemaException('Document metadata must be an associative array with string keys.');
                }

                $this->guardMetadataKey($key);
            }

            $this->metadata = $metadata;

            return $this;
        }

        /**
         * @throws DocumentSchemaException
         */
        public function addMetadata(string $key, mixed $value): self
        {
            $this->guardMetadataKey($key);
            $this->metadata[$key] = $value;
            return $this;
        }

        /**
         * @throws DocumentSchemaException
         */
        protected function guardMetadataKey(string $key): void
        {
            if ($key === '') {
                throw new DocumentSchemaException('Document metadata field name cannot be empty.');
            }

            if (in_array($key, DocumentSchema::RESERVED_FIELDS, true)) {
                throw new DocumentSchemaException("Document metadata field \"{$key}\" is reserved by the framework.");
            }
        }

        public function jsonSerialize(): array
        {
            return [
                'id' => $this->getId(),
                'content' => $this->getContent(),
                'embedding' => $this->getEmbedding(),
                'sourceType' => $this->getSourceType(),
                'sourceName' => $this->getSourceName(),
                'score' => $this->getScore(),
                'metadata' => $this->getMetadata(),
            ];
        }
    }
}

namespace NeuronAI\RAG\Splitter {
    use InvalidArgumentException;
    use NeuronAI\RAG\Document;
    use NeuronAI\RAG\Schema\DocumentSchemaException;

    use function array_map;
    use function array_slice;
    use function count;
    use function explode;
    use function implode;
    use function mb_strlen;
    use function array_sum;
    use function min;

    interface SplitterInterface
    {
        /**
         * @return Document[]
         */
        public function splitDocument(Document $document): array;

        /**
         * @param  Document[]  $documents
         * @return Document[]
         */
        public function splitDocuments(array $documents): array;
    }

    abstract class AbstractSplitter implements SplitterInterface
    {
        /**
         * @param  Document[]  $documents
         * @return Document[]
         */
        public function splitDocuments(array $documents): array
        {
            $split = [];

            foreach ($documents as $document) {
                $split = [...$split, ...$this->splitDocument($document)];
            }

            return $split;
        }
    }

    class DelimiterTextSplitter extends AbstractSplitter
    {
        private readonly int $separatorLength;

        public function __construct(
            private readonly int $maxLength = 1000,
            private readonly string $separator = ' ',
            private readonly int $wordOverlap = 0,
            private readonly int $minLength = 0,
        ) {
            if ($maxLength <= 0) {
                throw new InvalidArgumentException('maxLength must be greater than 0');
            }

            if ($separator === '') {
                throw new InvalidArgumentException('separator must not be empty');
            }

            if ($wordOverlap < 0) {
                throw new InvalidArgumentException('wordOverlap must be greater than or equal to 0');
            }

            if ($minLength < 0) {
                throw new InvalidArgumentException('minLength must be greater than or equal to 0');
            }

            if ($minLength > 0 && $minLength >= $maxLength) {
                throw new InvalidArgumentException('minLength must be less than maxLength');
            }

            $this->separatorLength = mb_strlen($separator);
        }

        /**
         * @return Document[]
         * @throws DocumentSchemaException
         */
        public function splitDocument(Document $document): array
        {
            $text = $document->getContent();

            if (mb_strlen($text) <= $this->maxLength) {
                return [$document];
            }

            $parts = explode($this->separator, $text);

            $chunks = $this->createChunksWithOverlap($parts);
            $chunks = $this->enforceMinLength($chunks);

            $split = [];
            foreach ($chunks as $chunk) {
                $newDocument = new Document($chunk);
                $newDocument->setSourceType($document->getSourceType());
                $newDocument->setSourceName($document->getSourceName());
                $newDocument->setMetadata($document->getMetadata());
                $split[] = $newDocument;
            }

            return $split;
        }

        /**
         * @param  array<string>  $words
         * @return array<string>
         */
        private function createChunksWithOverlap(array $words): array
        {
            $chunks = [];
            $currentChunk = [];
            $currentChunkLength = 0;

            foreach ($words as $word) {
                if ($word === '') {
                    continue;
                }

                $wordLength = mb_strlen($word);
                $addedLength = ($currentChunk === [] ? 0 : $this->separatorLength) + $wordLength;

                if ($currentChunkLength + $addedLength <= $this->maxLength || $currentChunk === []) {
                    $currentChunk[] = $word;
                    $currentChunkLength += $addedLength;
                } else {
                    $chunks[] = implode($this->separator, $currentChunk);

                    $calculatedOverlap = min($this->wordOverlap, count($currentChunk) - 1);
                    $overlapWords = $calculatedOverlap > 0 ? array_slice($currentChunk, -$calculatedOverlap) : [];

                    while ($overlapWords !== [] && $this->calculateChunkLength([...$overlapWords, $word]) > $this->maxLength) {
                        $overlapWords = array_slice($overlapWords, 1);
                    }

                    $currentChunk = [...$overlapWords, $word];
                    $currentChunkLength = $this->calculateChunkLength($currentChunk);
                }
            }

            if ($currentChunk !== []) {
                $chunks[] = implode($this->separator, $currentChunk);
            }

            return $chunks;
        }

        /**
         * Merges chunks that fall below minLength into the previous chunk while that one is
         * within maxLength, so the maximum is exceeded by one short chunk at most.
         *
         * @param  array<string>  $chunks
         * @return array<string>
         */
        private function enforceMinLength(array $chunks): array
        {
            if ($this->minLength <= 0 || count($chunks) <= 1) {
                return $chunks;
            }

            $result = [$chunks[0]];

            for ($i = 1, $count = count($chunks); $i < $count; $i++) {
                $lastIndex = count($result) - 1;

                if (mb_strlen($chunks[$i]) < $this->minLength && mb_strlen($result[$lastIndex]) <= $this->maxLength) {
                    $result[$lastIndex] = implode($this->separator, [$result[$lastIndex], $chunks[$i]]);
                } else {
                    $result[] = $chunks[$i];
                }
            }

            return $result;
        }

        /**
         * @param  array<string>  $chunk
         */
        private function calculateChunkLength(array $chunk): int
        {
            if ($chunk === []) {
                return 0;
            }

            return array_sum(array_map(mb_strlen(...), $chunk))
                + (count($chunk) - 1) * $this->separatorLength;
        }
    }
}

namespace NeuronAI\RAG\DataLoader {
    use NeuronAI\Exceptions\DataReaderException;

    use function file_get_contents;

    interface ReaderInterface
    {
        public function read(string $filePath): string;
    }

    class TextFileReader implements ReaderInterface
    {
        /**
         * @throws DataReaderException
         */
        public function read(string $filePath): string
        {
            $content = @file_get_contents($filePath);

            if ($content === false) {
                throw new DataReaderException("Could not read `{$filePath}`. Invalid path or permission denied.");
            }

            return $content;
        }
    }
}
