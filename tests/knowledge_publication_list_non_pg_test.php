<?php

declare(strict_types=1);

namespace think\facade {
    final class Db
    {
        public static function connect(string $name): self
        {
            if ($name !== 'pgsql') throw new \RuntimeException('Unexpected database');
            return new self();
        }

        public function query(string $sql, array $params): array
        {
            if ($params === [9] && str_contains($sql, 'count(*) total')
                && str_contains($sql, "p.consumer_environment_id=? AND p.state='published'")) {
                return [['total' => '1']];
            }
            if ($params === [9] && str_contains($sql, 'FROM sand_ai_knowledge_publication p')
                && str_contains($sql, "p.consumer_environment_id=? AND p.state='published'")) {
                if (!str_contains($sql, 'kb.state=\'published\'') || !str_contains($sql, 'v.state=\'published\'')
                    || !str_contains($sql, "v.source_scope_sha256<>''")) {
                    throw new \RuntimeException('Consumer list included an unpublished knowledge base');
                }
                return [[
                    'id' => '81', 'code' => 'reference', 'knowledge_base_id' => '51',
                    'owner_environment_id' => '7', 'consumer_environment_id' => '9',
                    'owner_code' => 'source', 'owner_name' => '参考资料',
                    'version_id' => '72', 'version_no' => '2', 'data_class' => null,
                ]];
            }
            if ($params === [7] && str_contains($sql, 'count(*) total')) return [['total' => '1']];
            if ($params === [7] && str_contains($sql, 'FROM sand_ai_knowledge_base kb')) {
                return [[
                    'id' => '51', 'code' => 'reference', 'name' => '参考资料',
                    'environment_id' => '7', 'version_id' => '72',
                    'version_no' => '2', 'data_class' => null,
                ]];
            }
            if ($params === [7, 51] && str_contains($sql, 'count(*) total')) return [['total' => '1']];
            if ($params === [7, 51] && str_contains($sql, 'FROM sand_ai_knowledge_publication p')) {
                return [[
                    'id' => '81', 'knowledge_base_id' => '51', 'owner_environment_id' => '7',
                    'consumer_organization_id' => '11', 'consumer_application_id' => '30',
                    'consumer_environment_id' => '9', 'code' => 'reference',
                    'state' => 'published', 'create_time' => '2026-09-25 10:00:00',
                    'update_time' => '2026-09-25 10:00:00',
                ]];
            }
            throw new \RuntimeException('Unexpected publication list query');
        }
    }
}

namespace {
    $vendor = getenv('SANDPACKAGE_TEST_VENDOR');
    if (!is_string($vendor) || !is_file($vendor)) throw new \RuntimeException('Set SANDPACKAGE_TEST_VENDOR');
    require $vendor;
    $app = dirname(__DIR__) . '/app/';
    spl_autoload_register(static function (string $class) use ($app): void {
        if (!str_starts_with($class, 'app\\')) return;
        $path = $app . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($path)) require $path;
    }, true, true);

    $gateway = new \app\Application\Knowledge\KnowledgePublicationGateway();
    $bases = $gateway->listKnowledgeBases(7, 1, 20);
    if ($bases['total'] !== 1 || $bases['data'][0]['id'] !== 51 ||
        $bases['data'][0]['version_id'] !== 72 || $bases['data'][0]['data_class'] !== null) {
        throw new \RuntimeException('Publishable list contract drifted');
    }
    $publications = $gateway->listPublications(7, 51, 1, 20);
    if ($publications['total'] !== 1 || $publications['data'][0]['id'] !== 81 ||
        $publications['data'][0]['consumer_application_id'] !== 30) {
        throw new \RuntimeException('Publication list contract drifted');
    }
    $consumer = $gateway->listForConsumer(9, 1, 20);
    if ($consumer['total'] !== 1 || $consumer['data'][0]['code'] !== 'reference'
        || $consumer['data'][0]['owner_name'] !== '参考资料'
        || $consumer['data'][0]['version_id'] !== 72) {
        throw new \RuntimeException('Consumer publication list contract drifted');
    }
    try {
        $gateway->listPublications(7, 51, 1, 101);
        throw new \RuntimeException('Oversized page was accepted');
    } catch (\app\Api\Support\ApiProblem $problem) {
        if ($problem->errorCode !== 'SAND_AI_KNOWLEDGE_VALIDATION_ERROR') throw $problem;
    }
    echo "knowledge-publication-list 4/4\n";
}
