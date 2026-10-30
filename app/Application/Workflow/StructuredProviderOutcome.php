<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use app\Api\Support\ApiProblem;
use app\Application\Knowledge\KnowledgeProviderOutcome;

/** Private framing distinguishes accounting receipts from legacy model JSON. */
final class StructuredProviderOutcome
{
    private const PREFIX = "sand-ai/structured-provider-outcome@1\n";

    public static function encode(KnowledgeProviderOutcome $outcome): string
    {
        self::assertStructured($outcome);
        return self::PREFIX . $outcome->encode();
    }

    public static function decode(string $receipt): ?KnowledgeProviderOutcome
    {
        if (!str_starts_with($receipt, self::PREFIX)) {
            // Content-only legacy receipts cannot prove consumed Token counts.
            return null;
        }
        $outcome = KnowledgeProviderOutcome::decode(substr($receipt, strlen(self::PREFIX)));
        self::assertStructured($outcome);
        return $outcome;
    }

    public static function content(string $receipt): string
    {
        return self::decode($receipt)?->content ?? $receipt;
    }

    private static function assertStructured(KnowledgeProviderOutcome $outcome): void
    {
        if (($outcome->attribution['capability_code'] ?? null) !== 'structured_extraction'
            || ($outcome->attribution['source_kind'] ?? null) !== 'structured_workflow'
            || ($outcome->attribution['service_action'] ?? null) !== 'sand_ai.workflow.run') {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Structured provider accounting attribution is invalid');
        }
    }
}
