<?php

declare(strict_types=1);

namespace app\Identity\Infrastructure;

use app\Api\Support\ApiProblem;
use app\Application\Usage\UsageAttribution;
use app\Identity\Contract\IdentityContext;
use support\Request;

/** Authorizes and revalidates the actual private files returned by retrieval. */
final class RetrievalSourceAccessGuard
{
    public function __construct(
        private readonly ?HostSandIamInvocationAuthorizer $authorizer = null,
        private readonly ?AiTaskSourceFacts $sourceFacts = null,
    ) {
    }

    /**
     * @param list<array<string,mixed>> $matches
     * @return array<int,array{authorization_id:int,data_class:?string}>
     */
    public function authorize(
        Request $request,
        IdentityContext $identity,
        UsageAttribution $attribution,
        array $matches,
        string $requestId,
    ): array {
        $facts = $this->resolve($identity, $attribution, $matches);
        $receipts = [];
        foreach ($facts['file_ids'] as $fileId) {
            $decision = $this->host()->authorize(
                $request, $identity, 'sand_ai.retrieval.search', 'sand_ai.file',
                (string) $fileId, 'retrieval-file:' . bin2hex(random_bytes(16)), $requestId,
            );
            self::assertDecision($decision, $facts['data_class']);
            $receipts[$fileId] = [
                'authorization_id' => $decision['authorization_id'],
                'data_class' => $decision['data_class'],
            ];
        }
        return $receipts;
    }

    /**
     * @param list<array<string,mixed>> $matches
     * @param array<int,array{authorization_id:int,data_class:?string}> $receipts
     */
    public function revalidate(
        Request $request,
        IdentityContext $identity,
        UsageAttribution $attribution,
        array $matches,
        string $requestId,
        array $receipts,
    ): void {
        $facts = $this->resolve($identity, $attribution, $matches);
        $expected = $facts['file_ids'];
        $actual = array_keys($receipts);
        sort($expected, SORT_NUMERIC);
        sort($actual, SORT_NUMERIC);
        if ($actual !== $expected) {
            self::unverified();
        }
        $signedContext = trim((string) $request->header('authorization', ''));
        if (str_starts_with($signedContext, 'Bearer ')) {
            $signedContext = trim(substr($signedContext, 7));
        }
        foreach ($facts['file_ids'] as $fileId) {
            $receipt = $receipts[$fileId];
            self::assertDecision($receipt, $facts['data_class']);
            $decision = $this->host()->revalidate(
                $receipt['authorization_id'], $signedContext, $identity,
                'sand_ai.retrieval.search', 'sand_ai.file', (string) $fileId,
                $request->getRemoteIp(), $requestId,
            );
            self::assertDecision($decision, $facts['data_class']);
        }
    }

    /**
     * @param list<array<string,mixed>> $matches
     * @return array{data_class:?string,file_ids:list<int>,block_file_ids:array<int,int>}
     */
    private function resolve(IdentityContext $identity, UsageAttribution $attribution, array $matches): array
    {
        if (count($matches) > 20
            || (string) $attribution->organizationId !== $identity->organizationId
            || (string) $attribution->applicationId !== $identity->applicationId
            || $attribution->environmentId !== $identity->environmentId
            || (string) $attribution->workloadClientId !== $identity->workloadClientId) {
            self::unverified();
        }
        $blockIds = [];
        foreach ($matches as $match) {
            if (!is_array($match) || !is_int($match['source_block_id'] ?? null)
                || $match['source_block_id'] < 1
                || !is_int($match['file_id'] ?? null) || $match['file_id'] < 1) {
                self::unverified();
            }
            $blockIds[] = $match['source_block_id'];
        }
        $facts = ($this->sourceFacts ?? new AiTaskSourceFacts())->resolveFiles(
            $identity->environmentId, $blockIds, $attribution,
        );
        AiTaskSourceFacts::assertMatches($matches, $facts);
        return $facts;
    }

    /** @param array<string,mixed> $decision */
    private static function assertDecision(array $decision, ?string $dataClass): void
    {
        if (!is_int($decision['authorization_id'] ?? null) || $decision['authorization_id'] < 1
            || !array_key_exists('data_class', $decision) || $decision['data_class'] !== $dataClass) {
            self::unverified();
        }
    }

    private function host(): HostSandIamInvocationAuthorizer
    {
        return $this->authorizer ?? new HostSandIamInvocationAuthorizer();
    }

    private static function unverified(): never
    {
        throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Retrieval source facts are unavailable');
    }
}
