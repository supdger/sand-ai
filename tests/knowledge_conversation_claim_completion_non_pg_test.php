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

    use app\Api\Support\ApiProblem;
    use app\Application\Knowledge\KnowledgeConversationGateway;
    use app\Application\Knowledge\KnowledgeConversationPolicy;
    use app\Identity\Contract\IdentityContext;
    use think\facade\Db;

    $identity = new IdentityContext('11', '22', 33, '44', 'sand-ai', ['sand_ai.knowledge.query']);
    $publication = ['id' => 50, 'active_version_id' => 60,
        'source_scope_sha256' => str_repeat('a', 64), 'data_class' => 'internal'];
    $currentClaim = str_repeat('b', 32);
    $result = ['answer_state' => 'answered', 'answer' => '回答', 'citations' => []];
    $db = new class($identity, $publication, $currentClaim) {
        public array $writes = [];
        public bool $casMiss = false;
        public bool $sealed = false;
        public function __construct(
            private IdentityContext $identity, private array $publication, public string $claim,
        ) {
        }
        public function transaction(\Closure $callback): mixed { return $callback(); }
        public function query(string $sql, array $params): array
        {
            if (str_starts_with($sql, 'SELECT * FROM sand_ai_knowledge_conversation WHERE public_id=')) {
                return [['id' => 9, 'environment_id' => 33,
                    'owner_scope' => KnowledgeConversationPolicy::ownerScope($this->identity),
                    'publication_id' => 50, 'knowledge_base_version_id' => 60,
                    'source_scope_sha256' => $this->publication['source_scope_sha256'],
                    'data_class' => 'internal', 'state' => 'active']];
            }
            if (str_starts_with($sql, 'SELECT * FROM sand_ai_knowledge_conversation_turn WHERE id=')) {
                return [['id' => 7, 'state' => $this->sealed ? 'answered' : 'pending',
                    'claim_token' => $this->claim, 'result' => $this->sealed
                        ? json_encode(['answer_state' => 'answered', 'answer' => '已封存', 'citations' => []], JSON_THROW_ON_ERROR)
                        : null]];
            }
            throw new \RuntimeException('Unexpected query');
        }
        public function execute(string $sql, array $params): int
        {
            $this->writes[] = [$sql, $params];
            if (str_starts_with($sql, 'UPDATE sand_ai_knowledge_conversation_turn')) {
                if (!str_contains($sql, 'AND claim_token=?') || end($params) !== $this->claim) {
                    throw new \RuntimeException('Completion omitted claim compare-and-set');
                }
                return $this->casMiss ? 0 : 1;
            }
            if (str_starts_with($sql, 'UPDATE sand_ai_knowledge_conversation SET')) return 1;
            throw new \RuntimeException('Unexpected write');
        }
    };
    Db::$connection = $db;
    $gateway = new KnowledgeConversationGateway();
    $reject = static function (string $claim) use ($gateway, $publication, $identity, $result): void {
        try {
            $gateway->complete(str_repeat('c', 32), $publication, $identity, 7, 'turn-request-1234', $claim, $result);
            throw new \RuntimeException('Stale completion was accepted');
        } catch (ApiProblem $problem) {
            if ($problem->errorCode !== 'SAND_AI_CONVERSATION_CLAIM_LOST') throw $problem;
        }
    };
    $reject(str_repeat('d', 32));
    if ($db->writes !== []) throw new \RuntimeException('Stale completion wrote a turn');
    $saved = $gateway->complete(str_repeat('c', 32), $publication, $identity, 7, 'turn-request-1234', $currentClaim, $result);
    if ($saved !== $result || count($db->writes) !== 2) throw new \RuntimeException('Current claim did not seal the turn');
    $db->writes = [];
    $db->casMiss = true;
    $reject($currentClaim);
    if (count($db->writes) !== 1) throw new \RuntimeException('Failed compare-and-set still updated conversation');
    $db->sealed = true;
    $stored = $gateway->complete(str_repeat('c', 32), $publication, $identity, 7, 'turn-request-1234', str_repeat('d', 32), $result);
    if ($stored['answer'] !== '已封存') throw new \RuntimeException('Completed turn did not replay stored result');
    echo "knowledge-conversation-claim-completion 4/4\n";
}
