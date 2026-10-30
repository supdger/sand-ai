<?php

declare(strict_types=1);

namespace think\facade {
    final class Db
    {
        public static object $connection;
        public static function connect(string $name): object
        {
            if ($name !== 'pgsql') throw new \RuntimeException('Wrong connection');
            return self::$connection;
        }
    }
}

namespace {
    $root = dirname(__DIR__) . '/app/';
    spl_autoload_register(static function (string $class) use ($root): void {
        if (!str_starts_with($class, 'app\\')) return;
        $path = $root . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($path)) require $path;
    }, true, true);

    use app\Application\Knowledge\KnowledgeConversationGateway;
    use app\Application\Knowledge\KnowledgeConversationPolicy;
    use app\Identity\Contract\IdentityContext;
    use think\facade\Db;

    $identity = new IdentityContext('11', '22', 33, '44', 'sand-ai', ['sand_ai.knowledge.query']);
    $publication = ['id' => 50, 'active_version_id' => 60,
        'source_scope_sha256' => str_repeat('a', 64), 'data_class' => 'internal'];
    $db = new class($identity) {
        public int $limit = 21;
        public array $lastBinds = [];
        public function __construct(private IdentityContext $identity) {}
        public function query(string $sql, array $binds): array
        {
            if (str_starts_with($sql, 'SELECT * FROM sand_ai_knowledge_conversation WHERE public_id=')) {
                return [['id' => 9, 'public_id' => str_repeat('c', 32), 'environment_id' => 33,
                    'owner_scope' => KnowledgeConversationPolicy::ownerScope($this->identity),
                    'publication_id' => 50, 'knowledge_base_version_id' => 60,
                    'source_scope_sha256' => str_repeat('a', 64), 'data_class' => 'internal',
                    'state' => 'active', 'create_time' => '2026-09-25 12:00:00',
                    'update_time' => '2026-09-25 12:00:00']];
            }
            if (!str_contains($sql, 'LEFT JOIN sand_ai_invocation i ON i.request_id=t.request_id AND i.environment_id=?')
                || !str_contains($sql, 'LEFT JOIN sand_ai_invocation_dispatch d ON d.invocation_id=i.id')
                || !str_contains($sql, 'LIMIT 21')) {
                throw new \RuntimeException('Conversation read omitted the scoped invocation ledger');
            }
            $this->lastBinds = $binds;
            $rows = [];
            for ($sequence = 25; $sequence > 25 - $this->limit; --$sequence) {
                $rows[] = ['sequence_no' => $sequence, 'query' => '问题', 'state' => 'pending',
                    'result' => null, 'claim_active' => 0,
                    'invocation_state' => 'running', 'dispatch_state' => 'dispatching',
                    'create_time' => '2026-09-25 12:00:00', 'complete_time' => null];
            }
            return $rows;
        }
    };
    Db::$connection = $db;
    $gateway = new KnowledgeConversationGateway();
    $page = $gateway->read(str_repeat('c', 32), $publication, $identity);
    if ($db->lastBinds !== [33, 9] || count($page['turns']) !== 20
        || $page['turns'][0]['sequence_no'] !== 6
        || $page['turns'][19]['sequence_no'] !== 25
        || $page['next_before_sequence'] !== 6
        || $page['turns'][0]['execution_state'] !== 'outcome_unknown') {
        throw new \RuntimeException('Conversation state page is incorrect');
    }
    $db->limit = 1;
    $last = $gateway->read(str_repeat('c', 32), $publication, $identity, 6);
    if ($db->lastBinds !== [33, 9, 6] || count($last['turns']) !== 1
        || $last['next_before_sequence'] !== null) {
        throw new \RuntimeException('Conversation cursor added a false next page');
    }
    echo "knowledge-conversation-read-projection 2/2\n";
}
