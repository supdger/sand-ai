<?php

declare(strict_types=1);

$root = getenv('SANDPACKAGE_TEST_ROOT') ?: dirname(__DIR__);
if (!class_exists(\app\Api\Support\ApiProblem::class)) {
    require $root . '/app/Api/Support/ApiProblem.php';
}
if (!class_exists(\app\Application\Workflow\MaterialAnalysisFileAttemptIdentity::class)) {
    require $root . '/app/Application/Workflow/MaterialAnalysisFileAttemptIdentity.php';
}
if (!class_exists(\app\Application\Workflow\MaterialAnalysisFileResultPolicy::class)) {
    require $root . '/app/Application/Workflow/MaterialAnalysisFileResultPolicy.php';
}

use app\Api\Support\ApiProblem;
use app\Application\Workflow\MaterialAnalysisFileAttemptIdentity;
use app\Application\Workflow\MaterialAnalysisFileResultPolicy;

$childLogicalId = (new MaterialAnalysisFileAttemptIdentity(str_repeat('b', 48), 6, 2))
    ->dispatchLogicalExecutionId();
$expected = [
    'environment_id' => 1,
    'organization_id' => 2,
    'application_id' => 3,
    'workload_client_id' => 4,
    'workflow_run_id' => 5,
    'workflow_run_file_id' => 6,
    'file_id' => 7,
    'parse_id' => 8,
    'task_id' => 9,
    'step_id' => 10,
    'attempt_no' => 2,
    'execution_snapshot_sha256' => str_repeat('a', 64),
    'logical_execution_id' => str_repeat('b', 48),
];
$invocation = [
    'id' => 50,
    'environment_id' => 1,
    'organization_id' => 2,
    'application_id' => 3,
    'workload_client_id' => 4,
    'parent_workflow_run_id' => 5,
    'parent_task_run_id' => 9,
    'parent_task_step_id' => 10,
    'parent_workflow_run_file_id' => 6,
    'execution_attempt_no' => 2,
    'execution_snapshot_sha256' => str_repeat('a', 64),
    'logical_execution_id' => $childLogicalId,
    'request_id' => 'structured_' . str_repeat('b', 48) . '_file_6_attempt_2',
    'model_id' => 60,
    'deployment_id' => 70,
    'state' => 'succeeded',
    'status' => 1,
    'delete_time' => null,
];
$dispatch = [
    'invocation_id' => 50,
    'environment_id' => 1,
    'parent_workflow_run_id' => 5,
    'parent_task_run_id' => 9,
    'parent_task_step_id' => 10,
    'parent_workflow_run_file_id' => 6,
    'attempt_no' => 2,
    'execution_snapshot_sha256' => str_repeat('a', 64),
    'logical_execution_id' => $childLogicalId,
    'model_id' => 60,
    'deployment_id' => 70,
    'capability_code' => 'structured_extraction',
    'state' => 'succeeded',
];
$usageEvent = [
    'invocation_id' => 50,
    'environment_id' => 1,
    'organization_id' => 2,
    'application_id' => 3,
    'workload_client_id' => 4,
    'model_id' => 60,
    'deployment_id' => 70,
    'service_action' => 'sand_ai.workflow.run',
    'source_kind' => 'structured_workflow',
    'capability_code' => 'structured_extraction',
    'idempotency_key' => 'invocation:50',
];
$manifest = [
    'payload' => [
        'schema' => ['code' => 'sand_ai.material_analysis', 'version' => '1.0.0'],
        'documents' => [['file_id' => 7]],
    ],
    'field_sources' => [
        ['field_path' => '/documents/0/title/value', 'source_block_id' => 12],
        ['field_path' => '/documents/0/classification/value', 'source_block_id' => 11],
    ],
];
$policy = new MaterialAnalysisFileResultPolicy();
$prepare = static fn (
    ?array $e = null,
    ?array $i = null,
    ?array $d = null,
    ?array $u = null,
    ?array $m = null,
    ?array $prompt = null,
    ?array $current = null,
): array => $policy->prepare(
    $e ?? $expected, $i ?? $invocation, $d ?? $dispatch, $u ?? $usageEvent,
    $m ?? $manifest, $prompt ?? [11, 12], $current ?? [11, 12],
);
$checks = 0;
$valid = $prepare();
if ($valid['field_source_count'] !== 2
    || $valid['source_rows'][0] !== ['field_path' => '/documents/0/classification/value', 'source_block_id' => 11]
    || $valid['payload_sha256'] !== hash('sha256', json_encode($manifest['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))) {
    throw new RuntimeException('Valid single-file evidence was changed');
}
++ $checks;
$emptyEvidence = $prepare(m: [
    'payload' => [
        'schema' => ['code' => 'sand_ai.material_analysis', 'version' => '1.0.0'],
        'documents' => [[
            'file_id' => 7,
            'classification' => ['value' => null, 'source_block_ids' => [], 'evidence_state' => 'missing_source'],
            'title' => ['value' => null, 'source_block_ids' => [], 'evidence_state' => 'missing_source'],
        ]],
    ],
    'field_sources' => [],
]);
if ($emptyEvidence['field_source_count'] !== 0 || $emptyEvidence['source_rows'] !== []) {
    throw new RuntimeException('A source-free file result could not retain its real invocation and usage');
}
++ $checks;
$reject = static function (callable $call) use (&$checks): void {
    try {
        $call();
    } catch (ApiProblem $problem) {
        if ($problem->errorCode !== 'SAND_AI_ARTIFACT_PROVENANCE_INVALID') {
            throw $problem;
        }
        ++$checks;
        return;
    }
    throw new RuntimeException('Untrusted single-file evidence was accepted');
};
$reject(static fn () => $prepare(i: [...$invocation, 'parent_workflow_run_file_id' => 99]));
$reject(static fn () => $prepare(i: [...$invocation, 'request_id' => 'structured_batch']));
$reject(static fn () => $prepare(i: [...$invocation, 'state' => 'failed']));
$reject(static fn () => $prepare(d: [...$dispatch, 'state' => 'ambiguous']));
$reject(static fn () => $prepare(d: [...$dispatch, 'invocation_id' => 51]));
$reject(static fn () => $prepare(u: [...$usageEvent, 'invocation_id' => 51]));
$reject(static fn () => $prepare(m: [
    ...$manifest,
    'payload' => [...$manifest['payload'], 'documents' => [['file_id' => 99]]],
]));
$reject(static fn () => $prepare(m: [
    ...$manifest,
    'field_sources' => [['field_path' => '/documents/0/title/value', 'source_block_id' => 99]],
]));
$reject(static fn () => $prepare(m: [
    ...$manifest,
    'field_sources' => [$manifest['field_sources'][0], $manifest['field_sources'][0]],
]));
$reject(static fn () => $prepare(prompt: [11, 12], current: [11, 12, 13]));
$reject(static fn () => $prepare(e: [...$expected, 'execution_snapshot_sha256' => str_repeat('c', 64)]));

echo 'material-file-result-policy ' . $checks . '/' . $checks . PHP_EOL;
