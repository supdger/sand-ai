<?php

declare(strict_types=1);

namespace app\Infrastructure\Provider;

use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\RAG\PostProcessor\JinaRerankerPostProcessor;

/** Overrides only the configured URI; request and ranking remain official SDK behavior. */
final class NeuronProfessionalRerankJinaPostProcessor extends JinaRerankerPostProcessor
{
    public function __construct(
        string $key,
        string $model,
        int $topN,
        string $baseUri,
        HttpClientInterface $httpClient,
    ) {
        parent::__construct($key, $model, $topN, $httpClient);
        $this->baseUri = $baseUri;
    }
}
