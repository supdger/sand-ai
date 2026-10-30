<?php

declare(strict_types=1);

namespace app\Application\Composition;

use app\Identity\Infrastructure\RequestAuditId;
use support\Request;

/** In-process child request. Identity and transport facts always come from the original request. */
final class CompositionNodeRequest extends Request
{
    public function __construct(private readonly Request $original, string $method, array $body, array $query, string $key)
    {
        RequestAuditId::ensure($original);
        $raw = $body === [] ? '' : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        parent::__construct($method . " / HTTP/1.1\r\n\r\n");
        $this->setGet($query);
        $this->setPost($body);
        $headers = $original->header();
        $headers['idempotency-key'] = $key;
        $headers['content-type'] = 'application/json';
        $headers['content-length'] = (string) strlen($raw);
        $this->setHeader($headers);
        $this->nodeMethod = $method;
        $this->nodeBody = $raw;
        $this->connection = $original->connection;
    }

    private string $nodeMethod;
    private string $nodeBody;
    public function method(): string { return $this->nodeMethod; }
    public function rawBody(): string { return $this->nodeBody; }
    public function getRemoteIp(): string { return $this->original->getRemoteIp(); }
    public function getRealIp(bool $safeMode = true): string { return $this->original->getRealIp($safeMode); }
}
