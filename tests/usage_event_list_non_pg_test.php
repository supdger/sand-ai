<?php

declare(strict_types=1);

namespace UsageEventListFixture {
    final class Query
    {
        private array $filters = [];
        private array $fields = [];
        public function order(string $field, string $direction): self { return $this; }
        public function field(string $fields): self { $this->fields = explode(',', $fields); return $this; }
        public function where(string $field, mixed $value): self { $this->filters[$field] = $value; return $this; }
        public function paginate(array $options): object
        {
            $rows = array_values(array_filter(\app\Infrastructure\Persistence\Model\UsageEvent::$rows, function (array $row): bool {
                foreach ($this->filters as $field => $value) {
                    if ($row[$field] !== $value) return false;
                }
                return true;
            }));
            $page = $options['page'];
            $limit = $options['list_rows'];
            $projected = array_map(fn (array $row): array => array_intersect_key($row, array_flip($this->fields)), array_slice($rows, ($page - 1) * $limit, $limit));
            return new class($page, $limit, count($rows), $projected) {
                public function __construct(private int $page, private int $limit, private int $total, private array $data) {}
                public function toArray(): array { return ['current_page' => $this->page, 'per_page' => $this->limit, 'total' => $this->total, 'data' => $this->data]; }
            };
        }
    }
}

namespace app\Infrastructure\Persistence\Model {
    final class UsageEvent
    {
        public static array $rows = [];
        public static function order(string $field, string $direction): \UsageEventListFixture\Query { return (new \UsageEventListFixture\Query())->order($field, $direction); }
    }
}

namespace {
    require dirname(__DIR__) . '/app/Api/Support/ApiProblem.php';
    require dirname(__DIR__) . '/plugin/sand-ai/app/admin/logic/UsageEventListPolicy.php';
    require dirname(__DIR__) . '/plugin/sand-ai/app/admin/logic/UsageLogic.php';

    $base = [
        'invocation_id' => 101, 'organization_id' => 11, 'application_id' => 21,
        'environment_id' => 31, 'provider_id' => 41, 'model_id' => 51,
        'service_action' => 'sand_ai.chat.complete', 'source_kind' => 'chat', 'capability_code' => 'chat',
        'metering_unit' => null, 'metering_quantity' => null,
        'input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0,
        'usage_state' => 'measured', 'price_revision_id' => 61,
        'metered_amount' => '0.000000', 'currency' => 'CNY', 'pricing_state' => 'priced',
        'create_time' => '2026-09-25 12:00:00', 'identity_snapshot' => ['secret' => 'must-not-leak'],
    ];
    \app\Infrastructure\Persistence\Model\UsageEvent::$rows = [
        ['id' => 1] + $base,
        ['id' => 2, 'usage_state' => 'unmeasured', 'price_revision_id' => null, 'metered_amount' => null, 'currency' => null, 'pricing_state' => 'unpriced'] + $base,
        ['id' => 3, 'invocation_id' => null, 'metering_unit' => 'audio_seconds', 'metering_quantity' => '12.500000', 'service_action' => 'sand_ai.audio.transcription', 'source_kind' => 'capability', 'capability_code' => 'audio_transcription'] + $base,
    ];
    $list = (new \plugin\SandAi\app\admin\logic\UsageLogic())->index([]);
    if ($list['total'] !== 3 || count($list['data']) !== 3) throw new \RuntimeException('Immutable event list is incomplete');
    foreach ($list['data'] as $row) {
        if (array_key_exists('identity_snapshot', $row) || array_key_exists('secret', $row)) throw new \RuntimeException('Private snapshot leaked');
    }
    if ($list['data'][0]['total_tokens'] !== 0 || $list['data'][0]['metered_amount'] !== '0.000000') throw new \RuntimeException('Measured zero changed');
    if ($list['data'][1]['total_tokens'] !== null || $list['data'][1]['metered_amount'] !== null) throw new \RuntimeException('Unknown usage became zero');
    if ($list['data'][2]['total_tokens'] !== null || $list['data'][2]['metering_quantity'] !== '12.500000') throw new \RuntimeException('Audio seconds became tokens');
    $filtered = (new \plugin\SandAi\app\admin\logic\UsageLogic())->index(['application_id' => '21', 'service_action' => 'sand_ai.audio.transcription']);
    if ($filtered['total'] !== 1 || $filtered['data'][0]['id'] !== 3) throw new \RuntimeException('Application/action filter failed');
    foreach ([['status' => '1'], ['limit' => '101'], ['model_id' => '0'], ['usage_state' => 'unknown']] as $invalid) {
        try {
            (new \plugin\SandAi\app\admin\logic\UsageLogic())->index($invalid);
            throw new \RuntimeException('Invalid usage list filter passed');
        } catch (\app\Api\Support\ApiProblem $problem) {
            if ($problem->errorCode !== 'SAND_AI_VALIDATION_ERROR') throw $problem;
        }
    }
    echo "immutable usage event list non-PG checks passed\n";
}
