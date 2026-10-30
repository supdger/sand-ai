<?php

declare(strict_types=1);
namespace app\Application\Composition;

use app\Api\Support\ApiProblem;
use app\Identity\Contract\IdentityContext;
use think\facade\Db;
use Throwable;

/** Canonical node identities and progress. Child services own every effect, source and charge. */
final class CompositionRunGateway
{
    public function submit(IdentityContext $context, string $code, int $version, array $input, string $key): array
    {
        CompositionDefinitionGateway::key($key);
        $resolved = (new CompositionDefinitionGateway())->resolve($context->environmentId, $code, $version);
        $fingerprint = CompositionGraphPolicy::digest(['code' => $code, 'version' => $version, 'input' => $input]);
        $runId = Db::connect('pgsql')->transaction(function () use ($context, $resolved, $key, $input, $fingerprint): int {
            Db::connect('pgsql')->query('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['composition-run:' . CompositionGraphPolicy::digest([$this->scope($context), $key])]);
            $prior = Db::connect('pgsql')->query('SELECT * FROM sand_ai_composition_run WHERE environment_id=? AND organization_id=? AND application_id=? AND workload_client_id=? AND request_key=? FOR UPDATE', [...$this->scope($context), $key]);
            if ($prior !== []) {
                if (!hash_equals((string) $prior[0]['fingerprint'], $fingerprint)) self::conflict();
                return (int) $prior[0]['id'];
            }
            $run = Db::connect('pgsql')->query("INSERT INTO sand_ai_composition_run(environment_id,organization_id,application_id,workload_client_id,version_id,request_key,fingerprint,snapshot_sha256,input_sha256,retain_until) VALUES(?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP(0)+INTERVAL '24 hours') RETURNING *", [...$this->scope($context), (int) $resolved['version']['id'], $key, $fingerprint, (string) $resolved['version']['snapshot_sha256'], CompositionGraphPolicy::digest($input)])[0];
            $sealed = (new CompositionInputVault())->seal($input, $this->binding($run));
            Db::connect('pgsql')->execute('UPDATE sand_ai_composition_run SET encrypted_input=CAST(? AS jsonb) WHERE id=?', [$sealed, (int) $run['id']]);
            foreach ($resolved['snapshot']['graph']['nodes'] as $node) {
                $nodeKey = 'composition:' . (int) $run['id'] . ':' . $node['id'] . ':' . substr(CompositionGraphPolicy::digest([$fingerprint, $node['id']]), 0, 24);
                Db::connect('pgsql')->execute('INSERT INTO sand_ai_composition_node(run_id,node_code,request_key) VALUES(?,?,?)', [(int) $run['id'], $node['id'], $nodeKey]);
            }
            return (int) $run['id'];
        });
        return ['id' => $runId, 'code' => $code, 'version' => $version, 'state' => 'pending', 'request_key' => $key];
    }

    public function byKey(IdentityContext $context, string $code, int $version, string $key): int
    {
        CompositionDefinitionGateway::key($key);
        $rows = Db::connect('pgsql')->query('SELECT r.id FROM sand_ai_composition_run r JOIN sand_ai_composition_version v ON v.id=r.version_id JOIN sand_ai_composition_definition d ON d.id=v.definition_id WHERE r.environment_id=? AND r.organization_id=? AND r.application_id=? AND r.workload_client_id=? AND r.request_key=? AND d.code=? AND v.version_no=?', [...$this->scope($context), $key, $code, $version]);
        if ($rows === []) self::missing(); return (int) $rows[0]['id'];
    }

    public function read(IdentityContext $context, int $id, CompositionServiceAdapter $adapter): array
    {
        $this->purgeExpired();
        $run = $this->owned($context, $id);
        $resolved = $this->resolved($run);
        $input = $run['encrypted_input'] === null ? null : (new CompositionInputVault())->open($run['encrypted_input'], $this->binding($run));
        $nodes = $this->nodes($id); $results = []; $public = [];
        foreach ($resolved['snapshot']['graph']['nodes'] as $definition) {
            $node = $nodes[$definition['id']]; $result = null;
            if ($input !== null && $node['state'] !== 'pending') {
                $bound = $this->nodeInput($definition, $input, $results, $resolved['snapshot']['references'][$definition['id']], $node);
                $fresh = $adapter->recover($resolved['snapshot']['references'][$definition['id']], $bound, $node['request_key'], self::child($node));
                if ($node['state'] === 'succeeded') {
                    $result = $this->verifiedOutput($node, $fresh);
                    $results[$definition['id']] = $result;
                } elseif ($fresh['state'] !== 'unknown') {
                    // The GET only reads the original child; this materializes its proven progress.
                    $node = $this->reconcileRead($node, $fresh, $id);
                    if ($node['state'] === 'succeeded') {
                        $result = $this->verifiedOutput($node, $fresh);
                        $results[$definition['id']] = $result;
                    }
                }
            }
            $nodes[$definition['id']] = $node;
            $state = $node['state'];
            if ($state === 'pending' && ($input === null || $this->dependencyFailed($definition, $nodes))) $state = 'blocked';
            $publicChild = self::child($node);
            if ($publicChild !== null) unset($publicChild['keyword_cost_proof']);
            $public[] = ['id' => $definition['id'], 'name' => $definition['name'], 'state' => $state,
                'request_key' => $node['request_key'], 'error_code' => $node['error_code'], 'child' => $publicChild, 'result' => $result];
        }
        $this->reconcileRunRead($id, $nodes);
        $run = $this->owned($context, $id);
        $output = [];
        if ($run['state'] === 'succeeded' && $input !== null) foreach ($resolved['snapshot']['graph']['output_nodes'] as $nodeId) $output[$nodeId] = $results[$nodeId] ?? null;
        // Recheck ownership/version after child reads so expiry or disable cannot disclose a late body.
        $current = $this->owned($context, $id); $this->resolved($current);
        if (($current['encrypted_input'] === null) !== ($input === null)) throw new ApiProblem('SAND_AI_CONTENT_PURGED', '工作流私有输入已清理，请刷新原运行');
        return ['id' => $id, 'code' => $resolved['definition']['code'], 'version' => (int) $resolved['version']['version_no'],
            'state' => $run['state'], 'snapshot_sha256' => $run['snapshot_sha256'], 'nodes' => $public, 'output' => $output,
            'input_state' => $input === null ? 'purged' : 'retained', 'retain_until' => (string) $current['retain_until'],
            'content_purged_time' => $current['content_purged_time'] === null ? null : (string) $current['content_purged_time'], 'usage' => ['basis' => 'child_runs', 'parent_charge' => false]];
    }

    public function operate(IdentityContext $context, int $id, string $operation, string $key,
        CompositionServiceAdapter $adapter, \Closure $authorize): array
    {
        CompositionDefinitionGateway::key($key); $authorize(); $this->purgeExpired();
        $contentAction = in_array($operation, ['cancel', 'purge_input'], true);
        $fingerprint = CompositionGraphPolicy::digest(['scope' => $this->scope($context), 'run_id' => $id, 'operation' => $operation]);
        $token = bin2hex(random_bytes(24)); $recoveryOnly = false; $rejected = false;
        $admitted = Db::connect('pgsql')->transaction(function () use ($context, $id, $operation, $key, $fingerprint, $token, $contentAction, &$recoveryOnly, &$rejected): bool {
            $run = $this->owned($context, $id, true);
            $prior = Db::connect('pgsql')->query('SELECT * FROM sand_ai_composition_run_operation WHERE run_id=? AND request_key=? FOR UPDATE', [$id, $key]);
            if ($prior !== []) {
                if (!hash_equals((string) $prior[0]['fingerprint'], $fingerprint)) self::conflict();
                if ($prior[0]['state'] === 'finished') {
                    $receipt = $prior[0]['response'] === null ? [] : CompositionDefinitionGateway::decode($prior[0]['response']);
                    $rejected = ($receipt['admission'] ?? 'admitted') === 'rejected';
                    return false;
                }
                // The same action key can reconcile its attempted children; it cannot start a new node or retry.
                $recoveryOnly = true;
            }
            if ($run['lease_token'] !== null && $run['lease_until'] !== null && strtotime((string) $run['lease_until']) > time()) {
                if ($recoveryOnly) self::busy(); // An existing admitted action must remain admitted/unknown.
                $receipt = ['run_id' => $id, 'operation' => $operation, 'admission' => 'rejected', 'error_code' => 'SAND_AI_COMPOSITION_RUN_BUSY'];
                Db::connect('pgsql')->execute("INSERT INTO sand_ai_composition_run_operation(run_id,operation,request_key,fingerprint,state,response) VALUES(?,?,?,?,'finished',CAST(? AS jsonb))", [$id,$operation,$key,$fingerprint,CompositionGraphPolicy::json($receipt)]);
                $rejected = true;
                return false; // Commit the refusal proof before returning the compatible BUSY error.
            }
            if (!$recoveryOnly && !$contentAction && ($run['encrypted_input'] === null || in_array($run['state'], ['cancelled', 'succeeded'], true))) throw new ApiProblem('SAND_AI_CONTENT_PURGED', '该运行已结束或输入已清理，请只读查看原子运行');
            if (!$recoveryOnly) Db::connect('pgsql')->execute("INSERT INTO sand_ai_composition_run_operation(run_id,operation,request_key,fingerprint,state) VALUES(?,?,?,?,'started')", [$id, $operation, $key, $fingerprint]);
            Db::connect('pgsql')->execute("UPDATE sand_ai_composition_run SET lease_token=?,lease_until=CURRENT_TIMESTAMP(0)+INTERVAL '10 minutes',update_time=CURRENT_TIMESTAMP(0) WHERE id=?", [$token, $id]);
            return true;
        });
        if ($rejected) self::busy();
        if (!$admitted) return $this->read($context, $id, $adapter);
        try {
            $run = $this->owned($context, $id);
            if ($contentAction) {
                $authorize();
                Db::connect('pgsql')->execute("UPDATE sand_ai_composition_run SET state=CASE WHEN state IN ('succeeded','partial_failed') THEN state ELSE 'cancelled' END,encrypted_input=NULL,content_purged_time=COALESCE(content_purged_time,CURRENT_TIMESTAMP(0)),update_time=CURRENT_TIMESTAMP(0) WHERE id=? AND lease_token=?", [$id, $token]);
            } else {
                $resolved = $this->resolved($run);
                $input = (new CompositionInputVault())->open($run['encrypted_input'], $this->binding($run));
                if ($recoveryOnly) {
                    $this->recoverNodes($context, $run, $resolved, $input, $adapter, $token, $authorize);
                } elseif (str_starts_with($operation, 'retry:')) {
                    $nodeId = substr($operation, 6); $definition = null;
                    foreach ($resolved['snapshot']['graph']['nodes'] as $item) if ($item['id'] === $nodeId) $definition = $item;
                    $nodes = $this->nodes($id); $node = $nodes[$nodeId] ?? null;
                    if ($definition === null || $node === null || $node['state'] !== 'failed' || self::child($node) === null) throw new ApiProblem('SAND_AI_COMPOSITION_RETRY_UNSUPPORTED', '只能重试原服务明确允许的失败节点');
                    $results = $this->recoverNodes($context, $run, $resolved, $input, $adapter, $token, $authorize);
                    $bound = $this->nodeInput($definition, $input, $results, $resolved['snapshot']['references'][$nodeId], $node);
                    if (!in_array($resolved['snapshot']['references'][$nodeId]['kind'], ['agent','material'], true)) throw new ApiProblem('SAND_AI_COMPOSITION_RETRY_UNSUPPORTED','该原服务没有可安全重试的正式任务入口');
                    $authorize(); $this->fence($id, $token);
                    Db::connect('pgsql')->execute("UPDATE sand_ai_composition_node SET state='submitting' WHERE id=? AND state='failed'", [(int) $node['id']]);
                    $child = self::child($node); $child['composition_retry_key'] = $key;
                    if ($resolved['snapshot']['references'][$nodeId]['kind'] === 'agent') { $child['continuation_task_id'] = $child['task_id']; $child['continuation_request_key'] = $key; }
                    Db::connect('pgsql')->execute('UPDATE sand_ai_composition_node SET child=CAST(? AS jsonb) WHERE id=?', [CompositionGraphPolicy::json($child), (int) $node['id']]);
                    try { $outcome = $adapter->retry($resolved['snapshot']['references'][$nodeId], $bound, $node['request_key'], $child, $key); }
                    catch (Throwable $exception) { $this->unknown($node, $id, $token, $exception); throw $exception; }
                    $this->finishNode($node, $outcome, $id, $token);
                } elseif ($operation === 'advance') {
                    (new NeuronCompositionWorkflow())->run(new CompositionResources(
                        fn (): ?array => $this->ready($context, $run, $resolved, $input, $adapter, $token, $authorize),
                        function (array $action) use ($context, $id, $token, $adapter, $authorize): void {
                            $authorize(); $this->fence($id, $token);
                            $adapterReference = $action['reference'];
                            (new CompositionServiceCatalog())->assertCurrent($context->environmentId, $adapterReference);
                            $adapter->validateInput($adapterReference, $action['input']);
                            $changed = Db::connect('pgsql')->execute("UPDATE sand_ai_composition_node SET state='submitting',input_sha256=?,update_time=CURRENT_TIMESTAMP(0) WHERE id=? AND state='pending'", [CompositionGraphPolicy::digest($action['input']), (int) $action['node']['id']]);
                            if ($changed !== 1) self::busy();
                            try { $outcome = $adapter->submit($adapterReference, $action['input'], $action['node']['request_key']); }
                            catch (Throwable $exception) { $this->unknown($action['node'], $id, $token, $exception); throw $exception; }
                            $this->finishNode($action['node'], $outcome, $id, $token);
                        },
                    ));
                } else throw new ApiProblem('SAND_AI_VALIDATION_ERROR', '工作流运行操作无效');
                $this->refreshState($id, $resolved['snapshot']['graph'], $token);
            }
            $authorize(); $this->fence($id, $token, $contentAction);
            $unresolved = array_filter($this->nodes($id), static fn (array $node): bool => in_array($node['state'], ['submitting','unknown'], true));
            if ($unresolved === [] || $contentAction) Db::connect('pgsql')->execute("UPDATE sand_ai_composition_run_operation SET state='finished',response=CAST(? AS jsonb) WHERE run_id=? AND request_key=?", [CompositionGraphPolicy::json(['run_id' => $id, 'operation' => $operation]), $id, $key]);
        } finally {
            Db::connect('pgsql')->execute('UPDATE sand_ai_composition_run SET lease_token=NULL,lease_until=NULL WHERE id=? AND lease_token=?', [$id, $token]);
        }
        return $this->read($context, $id, $adapter);
    }

    public function readOperation(IdentityContext $context, int $id, string $key): array
    {
        $this->owned($context, $id); CompositionDefinitionGateway::key($key);
        $rows = Db::connect('pgsql')->query('SELECT operation,request_key,state,response FROM sand_ai_composition_run_operation WHERE run_id=? AND request_key=?', [$id, $key]);
        if ($rows === []) self::missing();
        $row = $rows[0];
        $receipt = $row['response'] === null ? [] : CompositionDefinitionGateway::decode($row['response']);
        return ['operation' => $row['operation'], 'request_key' => $row['request_key'], 'state' => $row['state'], 'run_id' => $id,
            'admission' => $receipt['admission'] ?? 'admitted', 'error_code' => $receipt['error_code'] ?? null];
    }

    /** A periodic private-input retention action, also called before every public parent read/operation. */
    public function purgeExpired(): int
    {
        $exists = Db::connect('pgsql')->query("SELECT to_regclass('sand_ai_composition_run') AS relation");
        if (($exists[0]['relation'] ?? null) === null) return 0;
        return Db::connect('pgsql')->execute("UPDATE sand_ai_composition_run SET encrypted_input=NULL,content_purged_time=CURRENT_TIMESTAMP(0),state=CASE WHEN state IN ('succeeded','partial_failed') THEN state ELSE 'cancelled' END WHERE encrypted_input IS NOT NULL AND retain_until<=CURRENT_TIMESTAMP(0)");
    }

    private function ready(IdentityContext $context, array $run, array $resolved, array $input,
        CompositionServiceAdapter $adapter, string $token, \Closure $authorize): ?array
    {
        $results = $this->recoverNodes($context, $run, $resolved, $input, $adapter, $token, $authorize);
        $nodes = $this->nodes((int) $run['id']); $graph = $resolved['snapshot']['graph'];
        if ($graph['failure_policy'] === 'stop') foreach ($nodes as $node) if (in_array($node['state'], ['failed', 'unknown', 'submitting'], true)) return null;
        foreach ($graph['nodes'] as $definition) {
            $node = $nodes[$definition['id']];
            if ($node['state'] !== 'pending' || array_diff($definition['depends_on'], array_keys($results)) !== []) continue;
            $reference = $resolved['snapshot']['references'][$definition['id']];
            return ['node' => $node, 'reference' => $reference, 'input' => $this->nodeInput($definition, $input, $results, $reference, $node)];
        }
        return null;
    }

    private function recoverNodes(IdentityContext $context, array $run, array $resolved, array $input,
        CompositionServiceAdapter $adapter, string $token, \Closure $authorize): array
    {
        $results = []; $nodes = $this->nodes((int) $run['id']);
        foreach ($resolved['snapshot']['graph']['nodes'] as $definition) {
            $node = $nodes[$definition['id']]; if ($node['state'] === 'pending') continue;
            if (array_diff($definition['depends_on'], array_keys($results)) !== []) continue;
            $authorize(); $this->fence((int) $run['id'], $token);
            $reference = $resolved['snapshot']['references'][$definition['id']];
            (new CompositionServiceCatalog())->assertCurrent($context->environmentId, $reference);
            $bound = $this->nodeInput($definition, $input, $results, $reference, $node);
            $fresh = $adapter->recover($reference, $bound, $node['request_key'], self::child($node));
            if ($node['state'] === 'succeeded') $results[$definition['id']] = $this->verifiedOutput($node, $fresh);
            else {
                $this->finishNode($node, $fresh, (int) $run['id'], $token);
                if ($fresh['state'] === 'succeeded') $results[$definition['id']] = $fresh['output'];
            }
        }
        return $results;
    }

    private function nodeInput(array $definition, array $input, array $results, array $reference, array $node): array
    {
        $bound = (new CompositionGraphPolicy())->inputs($definition, $input, $results, $reference['input_fields']);
        if ($node['input_sha256'] !== null && !hash_equals((string) $node['input_sha256'], CompositionGraphPolicy::digest($bound))) throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', '节点原输入摘要不一致');
        return $bound;
    }
    private function reconcileRead(array $node, array $fresh, int $runId): array
    {
        if (!in_array($fresh['state'], ['waiting','succeeded','failed'], true)) return $node;
        if ($fresh['state'] === 'succeeded' && !is_array($fresh['output'] ?? null)) return $node;
        $digest = $fresh['state'] === 'succeeded' ? CompositionGraphPolicy::digest($fresh['output']) : null;
        $changed = Db::connect('pgsql')->execute("UPDATE sand_ai_composition_node n SET state=?,child=CAST(? AS jsonb),output_sha256=?,error_code=?,update_time=CURRENT_TIMESTAMP(0) WHERE n.id=? AND n.state=? AND EXISTS(SELECT 1 FROM sand_ai_composition_run r WHERE r.id=n.run_id AND (r.lease_token IS NULL OR r.lease_until<=CURRENT_TIMESTAMP(0)))", [$fresh['state'], CompositionGraphPolicy::json($fresh['child'] ?? null), $digest, $fresh['error_code'] ?? null, (int) $node['id'], $node['state']]);
        if ($changed === 1) return [...$node, 'state' => $fresh['state'], 'child' => CompositionGraphPolicy::json($fresh['child'] ?? null), 'output_sha256' => $digest, 'error_code' => $fresh['error_code'] ?? null];
        return $node;
    }
    private function reconcileRunRead(int $id, array $nodes): void
    {
        $states = array_column($nodes, 'state');
        $known = array_intersect($states, ['submitting','unknown']) === [];
        $state = count(array_filter($states, static fn (string $state): bool => $state === 'succeeded')) === count($states) ? 'succeeded'
            : (array_intersect($states, ['unknown','failed']) !== [] ? 'partial_failed' : (array_intersect($states, ['waiting','submitting']) !== [] ? 'waiting' : (array_unique($states) === ['pending'] ? 'pending' : 'running')));
        Db::connect('pgsql')->execute("UPDATE sand_ai_composition_run SET state=? WHERE id=? AND encrypted_input IS NOT NULL AND state<>'cancelled' AND (lease_token IS NULL OR lease_until<=CURRENT_TIMESTAMP(0))", [$state,$id]);
        if ($known) Db::connect('pgsql')->execute("UPDATE sand_ai_composition_run_operation o SET state='finished',response=jsonb_build_object('run_id',run_id,'operation',operation) WHERE run_id=? AND state='started' AND EXISTS(SELECT 1 FROM sand_ai_composition_run r WHERE r.id=o.run_id AND (r.lease_token IS NULL OR r.lease_until<=CURRENT_TIMESTAMP(0)))", [$id]);
    }

    private function finishNode(array $node, array $outcome, int $runId, string $token): void
    {
        $this->fence($runId, $token);
        if (!in_array($outcome['state'] ?? null, ['waiting','succeeded','failed','unknown'], true)) throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', '节点原服务状态无效');
        if ($outcome['state'] === 'succeeded' && !is_array($outcome['output'] ?? null)) throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', '节点成功成果无效');
        Db::connect('pgsql')->execute('UPDATE sand_ai_composition_node SET state=?,child=CAST(? AS jsonb),output_sha256=?,error_code=?,update_time=CURRENT_TIMESTAMP(0) WHERE id=? AND run_id=?', [$outcome['state'], CompositionGraphPolicy::json($outcome['child'] ?? null), $outcome['state'] === 'succeeded' ? CompositionGraphPolicy::digest($outcome['output']) : null, $outcome['error_code'] ?? null, (int) $node['id'], $runId]);
    }
    private function verifiedOutput(array $node, array $fresh): array
    {
        if ($fresh['state'] !== 'succeeded' || !is_array($fresh['output'] ?? null) || !is_string($node['output_sha256'])
            || !hash_equals($node['output_sha256'], CompositionGraphPolicy::digest($fresh['output']))) throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', '原节点成果已不可读或摘要发生变化');
        return $fresh['output'];
    }
    private function unknown(array $node, int $runId, string $token, Throwable $exception): void
    {
        $this->fence($runId, $token);
        Db::connect('pgsql')->execute("UPDATE sand_ai_composition_node SET state='unknown',error_code=? WHERE id=? AND run_id=?", [$exception instanceof ApiProblem ? $exception->errorCode : 'SAND_AI_COMPOSITION_NODE_UNKNOWN', (int) $node['id'], $runId]);
    }
    private function refreshState(int $id, array $graph, string $token): void
    {
        $nodes = $this->nodes($id); $states = array_column($nodes, 'state');
        $state = count(array_filter($states, static fn (string $state): bool => $state === 'succeeded')) === count($states) ? 'succeeded'
            : (array_intersect($states, ['unknown','failed']) !== [] ? 'partial_failed' : (array_intersect($states, ['waiting','submitting']) !== [] ? 'waiting' : 'running'));
        Db::connect('pgsql')->execute('UPDATE sand_ai_composition_run SET state=?,update_time=CURRENT_TIMESTAMP(0) WHERE id=? AND lease_token=?', [$state, $id, $token]);
    }
    private function dependencyFailed(array $definition, array $nodes): bool { foreach ($definition['depends_on'] as $id) if (in_array($nodes[$id]['state'], ['failed','unknown'], true)) return true; return false; }
    private function resolved(array $run): array
    {
        $rows = Db::connect('pgsql')->query('SELECT d.code,v.version_no FROM sand_ai_composition_version v JOIN sand_ai_composition_definition d ON d.id=v.definition_id WHERE v.id=?', [(int) $run['version_id']]);
        if ($rows === []) self::missing();
        if ($run['encrypted_input'] === null) {
            $version = Db::connect('pgsql')->query('SELECT * FROM sand_ai_composition_version WHERE id=?', [(int) $run['version_id']])[0];
            $definition = Db::connect('pgsql')->query('SELECT * FROM sand_ai_composition_definition WHERE id=?', [(int) $version['definition_id']])[0];
            $resolved = ['version' => $version, 'definition' => $definition, 'snapshot' => CompositionDefinitionGateway::decode($version['snapshot'])];
        } else {
            try { $resolved = (new CompositionDefinitionGateway())->resolve((int) $run['environment_id'], $rows[0]['code'], (int) $rows[0]['version_no']); }
            catch (ApiProblem $problem) {
                if (in_array($problem->errorCode, ['SAND_AI_COMPOSITION_REFERENCE_CHANGED','SAND_AI_RESOURCE_NOT_FOUND'], true)) Db::connect('pgsql')->execute("UPDATE sand_ai_composition_run SET encrypted_input=NULL,content_purged_time=CURRENT_TIMESTAMP(0),state=CASE WHEN state IN ('succeeded','partial_failed') THEN state ELSE 'cancelled' END WHERE id=?", [(int) $run['id']]);
                throw $problem;
            }
        }
        if (!hash_equals((string) $run['snapshot_sha256'], (string) $resolved['version']['snapshot_sha256'])) throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', '原运行发布快照发生变化');
        return $resolved;
    }
    private function nodes(int $id): array { $rows = Db::connect('pgsql')->query('SELECT * FROM sand_ai_composition_node WHERE run_id=? ORDER BY id', [$id]); $nodes=[]; foreach ($rows as $row) $nodes[$row['node_code']]=$row; return $nodes; }
    private function owned(IdentityContext $context, int $id, bool $lock=false): array { $rows=Db::connect('pgsql')->query('SELECT * FROM sand_ai_composition_run WHERE id=? AND environment_id=? AND organization_id=? AND application_id=? AND workload_client_id=?' . ($lock ? ' FOR UPDATE' : ''), [$id,...$this->scope($context)]); if ($rows===[]) self::missing(); return $rows[0]; }
    private function scope(IdentityContext $context): array { return [$context->environmentId,$context->organizationId,$context->applicationId,$context->workloadClientId]; }
    private function binding(array $run): array { return ['run_id'=>(int)$run['id'],'environment_id'=>(int)$run['environment_id'],'organization_id'=>(string)$run['organization_id'],'application_id'=>(string)$run['application_id'],'workload_client_id'=>(string)$run['workload_client_id'],'input_sha256'=>(string)$run['input_sha256']]; }
    private function fence(int $id,string $token,bool $allowCancelled=false): void { $rows=Db::connect('pgsql')->query('SELECT state,encrypted_input,lease_token FROM sand_ai_composition_run WHERE id=?',[$id]); if ($rows===[] || !hash_equals((string)$rows[0]['lease_token'],$token) || (!$allowCancelled && ($rows[0]['state']==='cancelled' || $rows[0]['encrypted_input']===null))) self::busy(); }
    private static function child(array $node): ?array { return $node['child']===null ? null : CompositionDefinitionGateway::decode($node['child']); }
    private static function missing(): never { throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND','原 AI 工作流运行不存在或不属于当前应用身份'); }
    private static function conflict(): never { throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT','原请求号已绑定另一工作流操作'); }
    private static function busy(): never { throw new ApiProblem('SAND_AI_COMPOSITION_RUN_BUSY','原运行正在推进，请只读找回状态'); }
}
