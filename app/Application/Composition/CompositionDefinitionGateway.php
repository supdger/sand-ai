<?php

declare(strict_types=1);
namespace app\Application\Composition;

use app\Api\Support\ApiProblem;
use app\Identity\Infrastructure\HostSandIamEnvironmentReferenceVerifier;
use think\facade\Db;

final class CompositionDefinitionGateway
{
    public function index(int $environmentId): array
    {
        $this->environment($environmentId);
        $items = Db::connect('pgsql')->query('SELECT id,environment_id,code,name,state,active_version_id FROM sand_ai_composition_definition WHERE environment_id=? AND delete_time IS NULL ORDER BY id DESC LIMIT 200', [$environmentId]);
        foreach ($items as &$item) { $item['id'] = (int) $item['id']; $item['environment_id'] = (int) $item['environment_id']; $item['active_version_id'] = $item['active_version_id'] === null ? null : (int) $item['active_version_id']; }
        unset($item); return ['items' => $items];
    }

    public function read(int $environmentId, string $code): array
    {
        $this->environment($environmentId);
        $definition = $this->definition($environmentId, $code);
        $versions = Db::connect('pgsql')->query('SELECT id,version_no,state,revision,snapshot_sha256,graph FROM sand_ai_composition_version WHERE definition_id=? ORDER BY version_no DESC', [(int) $definition['id']]);
        foreach ($versions as &$version) {
            foreach (['id', 'version_no', 'revision'] as $field) $version[$field] = (int) $version[$field];
            $version['graph'] = self::decode($version['graph']);
        }
        unset($version);
        return ['definition' => $definition, 'versions' => $versions];
    }

    public function mutate(int $environmentId, string $operation, array $body, string $key, string $actor, \Closure $authorize): array
    {
        $this->environment($environmentId);
        self::key($key);
        $code = $body['code'] ?? null;
        if (!is_string($code) || preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/', $code) !== 1) self::invalid();
        $hasVersion = array_key_exists('version_id', $body);
        if ($hasVersion && (!in_array($operation, ['draft', 'publish'], true)
            || !is_int($body['version_id']) || $body['version_id'] < 1)) self::invalid();
        $fingerprint = CompositionGraphPolicy::digest(['operation' => $operation, 'actor' => $actor, 'body' => $body]);
        $authorize();
        return Db::connect('pgsql')->transaction(function () use ($environmentId, $operation, $body, $key, $actor, $code, $fingerprint, $authorize): array {
            Db::connect('pgsql')->query('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['composition-definition:' . $environmentId . ':' . $code]);
            $prior = Db::connect('pgsql')->query('SELECT * FROM sand_ai_composition_operation WHERE environment_id=? AND operation=? AND request_key=? FOR UPDATE', [$environmentId, $operation, $key]);
            if ($prior !== []) {
                if ((string) $prior[0]['actor_ref'] !== $actor || !hash_equals((string) $prior[0]['fingerprint'], $fingerprint)) self::conflict();
                $authorize();
                return self::decode($prior[0]['response']);
            }
            $rows = Db::connect('pgsql')->query('SELECT * FROM sand_ai_composition_definition WHERE environment_id=? AND code=? AND delete_time IS NULL FOR UPDATE', [$environmentId, $code]);
            if ($rows === [] && $operation !== 'draft') self::missing();
            if ($rows === []) {
                if (($body['expected_revision'] ?? null) !== 0) self::revision();
                $rows = Db::connect('pgsql')->query('INSERT INTO sand_ai_composition_definition(environment_id,code,name,created_by,updated_by) VALUES(?,?,?,?,?) RETURNING *', [$environmentId, $code, $this->name($body['name'] ?? null), $actor, $actor]);
            }
            $definition = $rows[0];
            $drafts = Db::connect('pgsql')->query("SELECT * FROM sand_ai_composition_version WHERE definition_id=? AND state='draft' FOR UPDATE", [(int) $definition['id']]);
            if (array_key_exists('version_id', $body)
                && ($drafts === [] || (int) $drafts[0]['id'] !== $body['version_id'])) self::revision();
            if ($operation === 'draft') {
                if (!is_array($body['graph'] ?? null) || !is_int($body['expected_revision'] ?? null)) self::invalid();
                $graph = (new CompositionGraphPolicy())->validate($body['graph']);
                $this->freeze($environmentId, $graph);
                $name = $this->name($body['name'] ?? null);
                if ($drafts === []) {
                    if ($body['expected_revision'] !== 0) self::revision();
                    $version = Db::connect('pgsql')->query('INSERT INTO sand_ai_composition_version(definition_id,version_no,graph,snapshot_sha256,created_by) SELECT ?,COALESCE(MAX(version_no),0)+1,CAST(? AS jsonb),?,? FROM sand_ai_composition_version WHERE definition_id=? RETURNING *', [(int) $definition['id'], CompositionGraphPolicy::json($graph), CompositionGraphPolicy::digest($graph), $actor, (int) $definition['id']])[0];
                } else {
                    $version = $drafts[0];
                    if ((int) $version['revision'] !== $body['expected_revision']) self::revision();
                    $version = Db::connect('pgsql')->query('UPDATE sand_ai_composition_version SET graph=CAST(? AS jsonb),snapshot_sha256=?,revision=revision+1,update_time=CURRENT_TIMESTAMP(0) WHERE id=? RETURNING *', [CompositionGraphPolicy::json($graph), CompositionGraphPolicy::digest($graph), (int) $version['id']])[0];
                }
                Db::connect('pgsql')->execute('UPDATE sand_ai_composition_definition SET name=?,updated_by=?,update_time=CURRENT_TIMESTAMP(0) WHERE id=?', [$name, $actor, (int) $definition['id']]);
            } elseif ($operation === 'publish') {
                if ($drafts === [] || !is_int($body['expected_revision'] ?? null) || $body['expected_revision'] !== (int) $drafts[0]['revision']) self::revision();
                $version = $drafts[0];
                $graph = (new CompositionGraphPolicy())->validate(self::decode($version['graph']));
                $snapshot = $this->freeze($environmentId, $graph);
                $authorize();
                $version = Db::connect('pgsql')->query("UPDATE sand_ai_composition_version SET state='published',snapshot=CAST(? AS jsonb),snapshot_sha256=?,published_time=CURRENT_TIMESTAMP(0) WHERE id=? RETURNING *", [CompositionGraphPolicy::json($snapshot), CompositionGraphPolicy::digest($snapshot), (int) $version['id']])[0];
                Db::connect('pgsql')->execute("UPDATE sand_ai_composition_definition SET state='published',status=1,active_version_id=?,updated_by=?,update_time=CURRENT_TIMESTAMP(0) WHERE id=?", [(int) $version['id'], $actor, (int) $definition['id']]);
            } elseif ($operation === 'disable') {
                $versions = Db::connect('pgsql')->query('SELECT * FROM sand_ai_composition_version WHERE id=?', [(int) $definition['active_version_id']]);
                if ($versions === []) self::missing();
                $version = $versions[0];
                Db::connect('pgsql')->execute("UPDATE sand_ai_composition_definition SET state='disabled',status=2,updated_by=?,update_time=CURRENT_TIMESTAMP(0) WHERE id=?", [$actor, (int) $definition['id']]);
                // Own transient inputs are purged; child runs and their original accounting remain intact.
                Db::connect('pgsql')->execute("UPDATE sand_ai_composition_run SET encrypted_input=NULL,content_purged_time=COALESCE(content_purged_time,CURRENT_TIMESTAMP(0)),state=CASE WHEN state IN ('succeeded','partial_failed') THEN state ELSE 'cancelled' END WHERE version_id IN (SELECT id FROM sand_ai_composition_version WHERE definition_id=?)", [(int) $definition['id']]);
            } else self::invalid();
            $authorize();
            $result = ['definition_id' => (int) $definition['id'], 'version_id' => (int) $version['id'], 'code' => $code,
                'version_no' => (int) $version['version_no'], 'revision' => (int) $version['revision'],
                'state' => $operation === 'disable' ? 'disabled' : (string) $version['state'], 'snapshot_sha256' => (string) $version['snapshot_sha256']];
            \app\Infrastructure\Persistence\Model\AuditLog::create(['actor_type' => 'admin', 'actor_ref' => $actor, 'action' => 'sand_ai.composition.' . $operation, 'resource_type' => 'composition_definition', 'resource_id' => (int) $definition['id'], 'summary' => 'AI 工作流配置变更', 'context' => ['version_no' => (int) $version['version_no'], 'snapshot_sha256' => (string) $version['snapshot_sha256']]]);
            Db::connect('pgsql')->execute('INSERT INTO sand_ai_composition_operation(environment_id,operation,request_key,actor_ref,fingerprint,definition_id,response) VALUES(?,?,?,?,?,?,CAST(? AS jsonb))', [$environmentId, $operation, $key, $actor, $fingerprint, (int) $definition['id'], CompositionGraphPolicy::json($result)]);
            return $result;
        });
    }

    public function readOperation(int $environmentId, string $operation, string $key, string $actor): array
    {
        $this->environment($environmentId); self::key($key);
        $rows = Db::connect('pgsql')->query('SELECT response FROM sand_ai_composition_operation WHERE environment_id=? AND operation=? AND request_key=? AND actor_ref=?', [$environmentId, $operation, $key, $actor]);
        if ($rows === []) self::missing();
        return self::decode($rows[0]['response']);
    }

    public function resolve(int $environmentId, string $code, int $versionNo): array
    {
        $this->environment($environmentId);
        $definition = $this->definition($environmentId, $code);
        if ($definition['state'] !== 'published' || (int) $definition['status'] !== 1) self::missing();
        $rows = Db::connect('pgsql')->query("SELECT * FROM sand_ai_composition_version WHERE definition_id=? AND version_no=? AND state='published'", [(int) $definition['id'], $versionNo]);
        if ($rows === []) self::missing();
        $version = $rows[0];
        $snapshot = self::decode($version['snapshot']);
        if (!hash_equals((string) $version['snapshot_sha256'], CompositionGraphPolicy::digest($snapshot))) self::reference();
        foreach ($snapshot['references'] as $reference) (new CompositionServiceCatalog())->assertCurrent($environmentId, $reference);
        return ['definition' => $definition, 'version' => $version, 'snapshot' => $snapshot];
    }

    private function freeze(int $environmentId, array $graph): array
    {
        $catalog = new CompositionServiceCatalog(); $references = [];
        foreach ($graph['nodes'] as $node) {
            $reference = $catalog->freeze($environmentId, $node['service']);
            (new CompositionGraphPolicy())->validateInputs($node, $reference['input_fields']);
            $references[$node['id']] = $reference;
        }
        (new CompositionGraphPolicy())->validateOutputBindings($graph, $references);
        return ['schema' => 'sand_ai.composition/1.0', 'graph' => $graph, 'references' => $references];
    }
    private function definition(int $environmentId, string $code): array { $rows = Db::connect('pgsql')->query('SELECT * FROM sand_ai_composition_definition WHERE environment_id=? AND code=? AND delete_time IS NULL', [$environmentId, $code]); if ($rows === []) self::missing(); $row = $rows[0]; foreach (['id','environment_id'] as $field) $row[$field] = (int) $row[$field]; $row['active_version_id'] = $row['active_version_id'] === null ? null : (int) $row['active_version_id']; return $row; }
    private function environment(int $id): void { try { (new HostSandIamEnvironmentReferenceVerifier())->requireEnvironment($id); } catch (\app\Identity\Contract\IdentityContextException $exception) { throw \app\Api\Support\IdentityContextProblem::from($exception); } }
    private function name(mixed $name): string { if (!is_string($name) || trim($name) === '' || strlen($name) > 300) self::invalid(); return trim($name); }
    public static function decode(mixed $value): array { $decoded = is_string($value) ? json_decode($value, true, 64, JSON_THROW_ON_ERROR) : $value; if (!is_array($decoded)) self::invalid(); return $decoded; }
    public static function key(string $key): void { if (trim($key) === '' || strlen($key) > 128) self::invalid(); }
    private static function invalid(): never { throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'AI 工作流请求无效'); }
    private static function missing(): never { throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'AI 工作流或原操作不存在'); }
    private static function conflict(): never { throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', '原请求号已绑定另一个操作'); }
    private static function revision(): never { throw new ApiProblem('SAND_AI_COMPOSITION_REVISION_CONFLICT', '草稿已变化，请读取原草稿后继续编辑'); }
    private static function reference(): never { throw new ApiProblem('SAND_AI_COMPOSITION_REFERENCE_CHANGED', '已发布服务引用发生变化，请重新选择并发布'); }
}
