<?php

declare(strict_types=1);

namespace app\Application\Knowledge;

/** Roll back an accepted budget transaction when its knowledge source closed. */
final class KnowledgeEmbeddingDispatchClosed extends \RuntimeException
{
}
