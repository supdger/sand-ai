<?php

declare(strict_types=1);

$vendor = getenv('SANDPACKAGE_TEST_VENDOR');
$root = getenv('SANDPACKAGE_TEST_ROOT');
if (!is_string($vendor) || !is_file($vendor) || !is_string($root) || !is_dir($root . '/app')) {
    throw new RuntimeException('Set SANDPACKAGE_TEST_VENDOR and SANDPACKAGE_TEST_ROOT');
}
require $vendor;
$stage = dirname(__DIR__) . '/app/';
spl_autoload_register(static function (string $class) use ($stage, $root): void {
    if (!str_starts_with($class, 'app\\')) return;
    $candidate = __DIR__ . '/' . substr($class, strrpos($class, '\\') + 1) . '.php';
    if (is_file($candidate)) {
        require $candidate;
        return;
    }
    $relative = str_replace('\\', '/', substr($class, 4)) . '.php';
    foreach ([$stage, $root . '/app/'] as $base) {
        if (is_file($base . $relative)) {
            require $base . $relative;
            return;
        }
    }
}, true, true);

use app\Api\Support\ApiProblem;
use app\Application\Workflow\MaterialAnalysisSampleEvidencePolicy;
use app\Application\Workflow\StructuredWorkflowPolicy;

$config = [
    'code' => 'generic_material_analysis', 'version' => '1.0.0', 'output_language' => 'zh-CN',
    'document_classifications' => ['record'], 'entity_types' => ['person'], 'extension_fields' => [],
    'max_prompt_characters' => 48000, 'max_output_characters' => 120000,
];
$profile = [
    'id' => 7, 'environment_id' => 31, 'code' => $config['code'],
    'version' => $config['version'], 'revision' => 4, 'config' => $config,
];
$sample = ['profile_id' => 7, 'revision' => 4, 'config_sha256' => StructuredWorkflowPolicy::fingerprint($config)];
$frozen = ['code' => $config['code'], 'version' => $config['version'], 'snapshot' => $config];
$execution = ['analysis_profile' => $frozen, 'material_profile_sample' => $sample];
$executionHash = StructuredWorkflowPolicy::fingerprint($execution);
$requestHash = str_repeat('a', 64);
$run = [
    'id' => 51, 'environment_id' => 31, 'organization_id' => 2, 'application_id' => 3,
    'workload_client_id' => 4, 'workflow_code' => 'material_analysis', 'status' => 1,
    'task_id' => 61, 'last_invocation_id' => 71, 'logical_execution_id' => 'logical-sample-51',
    'snapshot_sha256' => $requestHash, 'execution_snapshot_sha256' => $executionHash,
    'input_snapshot' => ['analysis_profile' => $frozen, 'material_profile_sample' => $sample],
    'execution_snapshot' => $execution,
];
$task = [
    'id' => 61, 'environment_id' => 31, 'resource_type' => 'workflow_run', 'resource_id' => 51,
    'state' => 'succeeded', 'input' => [
        'workflow_run_id' => 51, 'snapshot_sha256' => $requestHash, 'execution_snapshot_sha256' => $executionHash,
    ],
];
$artifact = ['workflow_run_id' => 51, 'state' => 'succeeded', 'status' => 1];
$invocation = [
    'id' => 71, 'state' => 'succeeded', 'environment_id' => 31,
    'organization_id' => 2, 'application_id' => 3, 'workload_client_id' => 4,
    'parent_workflow_run_id' => 51, 'parent_task_run_id' => 61,
    'request_id' => 'structured_logical-sample-51', 'logical_execution_id' => 'logical-sample-51',
    'execution_snapshot_sha256' => $executionHash, 'model_id' => 81, 'deployment_id' => 91,
];
$dispatch = [
    'invocation_id' => 71, 'state' => 'succeeded', 'environment_id' => 31,
    'application_id' => 3, 'parent_workflow_run_id' => 51, 'parent_task_run_id' => 61,
    'logical_execution_id' => 'logical-sample-51', 'execution_snapshot_sha256' => $executionHash,
    'service_action' => 'sand_ai.workflow.run', 'source_kind' => 'structured_workflow',
    'capability_code' => 'structured_extraction', 'model_id' => 81, 'deployment_id' => 91,
];
$usage = [
    'invocation_id' => 71, 'environment_id' => 31, 'organization_id' => 2, 'application_id' => 3,
    'workload_client_id' => 4, 'source_kind' => 'structured_workflow',
    'service_action' => 'sand_ai.workflow.run', 'capability_code' => 'structured_extraction',
    'model_id' => 81, 'deployment_id' => 91,
];
$policy = new MaterialAnalysisSampleEvidencePolicy();
$checks = 0;
$accept = static function (array $p, array $r, array $t, array $a, array $i, array $d, array $u) use ($policy, &$checks): void {
    $policy->assertPublishable($p, $r, $t, $a, $i, $d, $u);
    ++$checks;
};
$reject = static function (array $p, array $r, array $t, array $a, array $i, array $d, array $u) use ($policy, &$checks): void {
    try {
        $policy->assertPublishable($p, $r, $t, $a, $i, $d, $u);
    } catch (ApiProblem $problem) {
        if ($problem->errorCode !== 'SAND_AI_RESOURCE_CONFLICT') throw $problem;
        ++$checks;
        return;
    }
    throw new RuntimeException('Unproven material sample was accepted at check ' . ($checks + 1));
};
$accept($profile, $run, $task, $artifact, $invocation, $dispatch, $usage);
$reject([...$profile, 'revision' => 5], $run, $task, $artifact, $invocation, $dispatch, $usage);
$reject([...$profile, 'environment_id' => 32], $run, $task, $artifact, $invocation, $dispatch, $usage);
$reject([...$profile, 'config' => [...$config, 'entity_types' => ['organization']]], $run, $task, $artifact, $invocation, $dispatch, $usage);
$reject($profile, [...$run, 'input_snapshot' => ['analysis_profile' => $frozen]], $task, $artifact, $invocation, $dispatch, $usage);
$reject($profile, [...$run, 'input_snapshot' => ['analysis_profile' => $frozen, 'material_profile_sample' => [...$sample, 'config_sha256' => str_repeat('0', 64)]]], $task, $artifact, $invocation, $dispatch, $usage);
$reject($profile, [...$run, 'execution_snapshot' => ['analysis_profile' => $frozen]], $task, $artifact, $invocation, $dispatch, $usage);
$reject($profile, [...$run, 'execution_snapshot_sha256' => str_repeat('0', 64)], $task, $artifact, $invocation, $dispatch, $usage);
$reject($profile, $run, [...$task, 'input' => ['workflow_run_id' => 51, 'snapshot_sha256' => $requestHash, 'execution_snapshot_sha256' => str_repeat('0', 64)]], $artifact, $invocation, $dispatch, $usage);
$reject($profile, [...$run, 'application_id' => 8], $task, $artifact, $invocation, $dispatch, $usage);
$reject($profile, $run, [...$task, 'state' => 'failed'], $artifact, $invocation, $dispatch, $usage);
$reject($profile, $run, $task, [...$artifact, 'workflow_run_id' => 52], $invocation, $dispatch, $usage);
$reject($profile, $run, $task, $artifact, $invocation, $dispatch, [...$usage, 'invocation_id' => 72]);
$reject($profile, $run, $task, $artifact, $invocation, $dispatch, [...$usage, 'source_kind' => 'skill_test']);
$reject($profile, $run, $task, $artifact, $invocation, $dispatch, [...$usage, 'service_action' => 'sand_ai.skill.run']);
$reject($profile, $run, $task, $artifact, $invocation, $dispatch, [...$usage, 'application_id' => 8]);
$reject($profile, $run, $task, $artifact, [...$invocation, 'parent_workflow_run_id' => 52], $dispatch, $usage);
$reject($profile, $run, $task, $artifact, [...$invocation, 'request_id' => 'structured_other'], $dispatch, $usage);
$reject($profile, $run, $task, $artifact, [...$invocation, 'workload_client_id' => 5], $dispatch, $usage);
$reject($profile, $run, $task, $artifact, $invocation, [...$dispatch, 'parent_task_run_id' => 62], $usage);
$reject($profile, $run, $task, $artifact, $invocation, [...$dispatch, 'state' => 'dispatching'], $usage);
$reject($profile, $run, $task, $artifact, $invocation, $dispatch, [...$usage, 'model_id' => 82]);
echo 'material-sample-evidence ' . $checks . '/' . $checks . PHP_EOL;
