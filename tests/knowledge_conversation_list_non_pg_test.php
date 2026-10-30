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
    $source = getenv('SAND_AI_TEST_SOURCE_ROOT') ?: dirname(__DIR__) . '/app/';
    $candidate = getenv('SAND_AI_TEST_APP_ROOT') ?: '';
    spl_autoload_register(static function (string $class) use ($source, $candidate): void {
        if (!str_starts_with($class, 'app\\')) return;
        $path = str_replace('\\', '/', substr($class, 4)) . '.php';
        $file = $candidate !== '' && is_file($candidate . $path) ? $candidate . $path : $source . $path;
        if (is_file($file)) require $file;
    }, true, true);

    use app\Api\Support\ApiProblem;
    use app\Application\Knowledge\KnowledgeConversationGateway;
    use app\Application\Knowledge\KnowledgeConversationPolicy;
    use app\Identity\Contract\IdentityContext;
    use think\facade\Db;

    $identity = new IdentityContext('11', '22', 33, '44', 'sand-ai', ['sand_ai.knowledge.query']);
    $other = new IdentityContext('11', '22', 33, '45', 'sand-ai', ['sand_ai.knowledge.query']);
    $publication = [
        'id' => 50, 'active_version_id' => 60,
        'source_scope_sha256' => str_repeat('a', 64), 'data_class' => 'internal',
    ];
    $db = new class($identity) {
        public array $binds = [];
        public bool $cursorFound = true;
        public int $count = 21;
        public function __construct(private IdentityContext $identity) {}
        public function query(string $sql, array $binds): array
        {
            $scope = KnowledgeConversationPolicy::ownerScope($this->identity);
            if (str_starts_with($sql, 'SELECT id FROM sand_ai_knowledge_conversation')) {
                if (!str_contains($sql, 'owner_scope=?') || !str_contains($sql, 'source_scope_sha256=?')
                    || !str_contains($sql, 'data_class IS NOT DISTINCT FROM ?')) {
                    throw new \RuntimeException('Cursor lookup is not scoped');
                }
                return $this->cursorFound && $binds === [str_repeat('c', 32), 33, $scope, 50, 60, str_repeat('a', 64), 'internal']
                    ? [['id' => 41]] : [];
            }
            if (!str_contains($sql, 'owner_scope=?') || !str_contains($sql, 'publication_id=?')
                || !str_contains($sql, 'knowledge_base_version_id=?')
                || !str_contains($sql, 'source_scope_sha256=?')
                || !str_contains($sql, 'data_class IS NOT DISTINCT FROM ?')
                || !str_contains($sql, 'ORDER BY id DESC LIMIT 21')) {
                throw new \RuntimeException('Conversation page is not scoped');
            }
            $this->binds = $binds;
            if ($binds[1] !== $scope) return [];
            $rows = [];
            for ($id = 40; $id > 40 - $this->count; --$id) {
                $rows[] = [
                    'public_id' => str_pad(dechex($id), 32, '0', STR_PAD_LEFT),
                    'knowledge_base_version_id' => 60, 'state' => 'active',
                    'create_time' => '2026-09-25 12:00:00',
                    'update_time' => '2026-09-25 12:00:00',
                ];
            }
            return $rows;
        }
    };
    Db::$connection = $db;
    $gateway = new KnowledgeConversationGateway();
    $page = $gateway->list($publication, $identity);
    if (count($page['conversations']) !== 20
        || $page['next_before'] !== str_pad(dechex(21), 32, '0', STR_PAD_LEFT)
        || $page['conversations'][0]['conversation_id'] !== str_pad(dechex(40), 32, '0', STR_PAD_LEFT)) {
        throw new \RuntimeException('Conversation page or cursor is incorrect');
    }
    $db->count = 1;
    $last = $gateway->list($publication, $identity, str_repeat('c', 32));
    if (count($last['conversations']) !== 1 || $last['next_before'] !== null
        || $db->binds !== [33, KnowledgeConversationPolicy::ownerScope($identity), 50, 60, str_repeat('a', 64), 'internal', 41]) {
        throw new \RuntimeException('Last page is incorrect');
    }
    $db->cursorFound = false;
    try {
        $gateway->list($publication, $identity, str_repeat('c', 32));
        throw new \RuntimeException('Foreign cursor was accepted');
    } catch (ApiProblem $problem) {
        if ($problem->errorCode !== 'SAND_AI_RESOURCE_NOT_FOUND') throw $problem;
    }
    $foreign = $gateway->list($publication, $other);
    if ($foreign['conversations'] !== [] || $foreign['next_before'] !== null) {
        throw new \RuntimeException('Foreign workload saw conversations');
    }
    echo "knowledge-conversation-list 4/4\n";
}
