<?php

declare(strict_types=1);

namespace app\Infrastructure\Provider;

use app\Application\Gateway\ProviderSendBoundary;

/** A fully validated Provider request whose only remaining side effect is send(). */
final readonly class PreparedChatProviderCall
{
    /** @param \Closure(ProviderSendBoundary):array{content:?string,input_tokens:int,output_tokens:int,usage_measured:bool,response_kind?:string,tool_calls?:array} $send */
    public function __construct(private \Closure $send)
    {
    }

    /** @return array{content:?string,input_tokens:int,output_tokens:int,usage_measured:bool,response_kind?:string,tool_calls?:array} */
    public function send(ProviderSendBoundary $boundary): array
    {
        return ($this->send)($boundary);
    }
}
