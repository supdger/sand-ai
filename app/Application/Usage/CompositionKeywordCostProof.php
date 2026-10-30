<?php

declare(strict_types=1);

namespace app\Application\Usage;

use app\Application\Composition\CompositionDefinitionGateway;
use app\Application\Gateway\RequestIdempotencyKey;
use think\facade\Db;

/** A successful internal keyword branch, sealed with the original node result. */
final class CompositionKeywordCostProof
{
    public static function verified(array $run, array $node): bool
    {
        if ($node['state'] !== 'succeeded' || $node['child'] === null) return false;
        $child = CompositionDefinitionGateway::decode($node['child']);
        $proof = $child['keyword_cost_proof'] ?? null;
        if (!is_array($proof) || ($proof['schema'] ?? null) !== 'sand_ai.keyword_no_provider/1'
            || ($child['kind'] ?? null) !== 'knowledge'
            || ($child['request_key'] ?? null) !== $node['request_key']
            || ($proof['node_request_key'] ?? null) !== $node['request_key']
            || !is_string($node['input_sha256']) || !is_string($node['output_sha256'])
            || ($proof['input_sha256'] ?? null) !== $node['input_sha256']
            || ($proof['output_sha256'] ?? null) !== $node['output_sha256']) return false;
        foreach (['environment_id', 'organization_id', 'application_id', 'workload_client_id'] as $field) {
            if ((string) ($proof[$field] ?? '') !== (string) $run[$field]) return false;
        }
        foreach (['embedding_invocation', 'rerank_invocation', 'embedding_usage', 'rerank_usage'] as $field) {
            if (isset($child[$field])) return false;
        }
        $versions = Db::connect('pgsql')->query(
            'SELECT snapshot,snapshot_sha256 FROM sand_ai_composition_version WHERE id=?',
            [(int) $run['version_id']],
        );
        if (count($versions) !== 1 || $versions[0]['snapshot_sha256'] !== $run['snapshot_sha256']) return false;
        $snapshot = CompositionDefinitionGateway::decode($versions[0]['snapshot']);
        $reference = $snapshot['references'][$node['node_code']] ?? null;
        if (!is_array($reference) || ($reference['kind'] ?? null) !== 'knowledge'
            || ($reference['code'] ?? null) !== ($child['code'] ?? null)
            || ($reference['version'] ?? null) !== ($child['version'] ?? null)
            || ($reference['reference_sha256'] ?? null) !== ($proof['reference_sha256'] ?? null)
            || ($reference['target']['id'] ?? null) !== ($proof['publication_id'] ?? null)
            || ($reference['target']['active_version_id'] ?? null) !== ($proof['knowledge_version_id'] ?? null)
            || ($reference['target']['source_scope_sha256'] ?? null) !== ($proof['source_scope_sha256'] ?? null)) return false;
        $scope = RequestIdempotencyKey::trustedScopeForValues((int) $run['environment_id'],
            (string) $run['application_id'], (string) $run['workload_client_id']);
        $key = 'knowledge-query:' . hash('sha256', $scope . ':' . $node['request_key']);
        if (($proof['query_request_id'] ?? null) !== $key
            || !is_string($proof['retrieval_snapshot_sha256'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/', $proof['retrieval_snapshot_sha256']) !== 1) return false;
        $intents = Db::connect('pgsql')->query(
            'SELECT publication_id,knowledge_base_version_id,source_scope_sha256,retrieval_snapshot_sha256
             FROM sand_ai_knowledge_query_intent WHERE environment_id=? AND organization_id=?
             AND application_id=? AND workload_client_id=? AND request_id=?',
            [(int) $run['environment_id'], $run['organization_id'], $run['application_id'], $run['workload_client_id'], $key],
        );
        if (count($intents) !== 1) return false;
        $intent = $intents[0];
        return (int) $intent['publication_id'] === $proof['publication_id']
            && (int) $intent['knowledge_base_version_id'] === $proof['knowledge_version_id']
            && $intent['source_scope_sha256'] === $proof['source_scope_sha256']
            && $intent['retrieval_snapshot_sha256'] === $proof['retrieval_snapshot_sha256'];
    }
}
