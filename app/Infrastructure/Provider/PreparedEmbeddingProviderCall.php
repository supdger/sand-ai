<?php

declare(strict_types=1);

namespace app\Infrastructure\Provider;

use app\Application\Gateway\ProviderSendBoundary;

/** A verified embedding request, ready for the durable dispatch boundary. */
final readonly class PreparedEmbeddingProviderCall
{
    /**
     * @param \Closure(ProviderSendBoundary):array{vectors:list<list<float>>,input_tokens:int,usage_measured:bool} $send
     */
    public function __construct(private \Closure $send)
    {
    }

    /** @return array{vectors:list<list<float>>,input_tokens:int,usage_measured:bool} */
    public function send(ProviderSendBoundary $boundary): array
    {
        return ($this->send)($boundary);
    }
}
