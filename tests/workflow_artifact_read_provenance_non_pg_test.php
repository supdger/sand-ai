<?php

declare(strict_types=1);

namespace app\Infrastructure\Persistence\Model {
    class FakeQuery
    {
        private array $filters = [];
        private array $nullFields = [];

        public function __construct(private string $model)
        {
        }

        public function where(string $field, mixed $value): self
        {
            $this->filters[$field] = $value;
            return $this;
        }

        public function whereNull(string $field): self
        {
            $this->nullFields[] = $field;
            return $this;
        }

        public function order(string $field): self
        {
            return $this;
        }

        public function select(): array
        {
            return array_values(array_filter($this->model::$rows, function ($row): bool {
                foreach ($this->filters as $field => $value) {
                    if (($row->$field ?? null) !== $value) {
                        return false;
                    }
                }
                foreach ($this->nullFields as $field) {
                    if (($row->$field ?? null) !== null) {
                        return false;
                    }
                }
                return true;
            }));
        }

        public function find(): ?object
        {
            return $this->select()[0] ?? null;
        }
    }

    #[\AllowDynamicProperties]
    class FakeModel
    {
        public static array $rows = [];

        public function __construct(array $values)
        {
            foreach ($values as $field => $value) {
                $this->$field = $value;
            }
        }

        public static function where(string $field, mixed $value): FakeQuery
        {
            return (new FakeQuery(static::class))->where($field, $value);
        }
    }

    class AiFile extends FakeModel { public static array $rows = []; }
    class AiFileParse extends FakeModel { public static array $rows = []; }
    class Artifact extends FakeModel { public static array $rows = []; }
    class ArtifactFieldSource extends FakeModel { public static array $rows = []; }
    class SourceBlock extends FakeModel { public static array $rows = []; }
    class WorkflowRun extends FakeModel { public static array $rows = []; }
    class WorkflowRunFile extends FakeModel { public static array $rows = []; }
}

namespace {
    $root = getenv('SANDPACKAGE_TEST_ROOT') ?: dirname(__DIR__);
    require $root . '/app/Api/Support/ApiProblem.php';
    require $root . '/app/Application/Usage/UsageAttribution.php';
    require $root . '/app/Application/Gateway/RequestIdempotencyKey.php';
    require $root . '/app/Application/File/FileOwnershipGuard.php';
    require $root . '/app/Application/Workflow/StructuredWorkflowPolicy.php';
    require $root . '/app/Application/Workflow/ExecutionSnapshotBuilder.php';
    require $root . '/app/Application/Workflow/SourceManifestBuilder.php';
    require getenv('SANDPACKAGE_TEST_GATEWAY') ?: $root . '/app/Application/Workflow/StructuredWorkflowGateway.php';

    $identity = ['organization_ref' => 'org', 'application_ref' => 'app', 'workload_client_ref' => 'client'];
    $fileAttribution = new \app\Application\Usage\UsageAttribution(1, null, null, null, $identity, 'file', 'sand_ai.file.write');
    $runAttribution = new \app\Application\Usage\UsageAttribution(1, null, null, null, $identity, 'structured_workflow', 'sand_ai.workflow.run');
    $scope = \app\Application\File\FileOwnershipGuard::scope($runAttribution);
    $hash = hash('sha256', 'source');
    $run = new \app\Infrastructure\Persistence\Model\WorkflowRun([
        'id' => 1, 'environment_id' => 1, 'attribution_snapshot' => $runAttribution->toPersisted(),
    ]);
    $artifact = new \app\Infrastructure\Persistence\Model\Artifact([
        'id' => 1, 'workflow_run_id' => 1, 'schema_code' => 'sand_ai.source_manifest',
        'schema_version' => '1.0.0', 'state' => 'succeeded', 'review_required' => false,
        'payload' => [],
    ]);
    $runFile = new \app\Infrastructure\Persistence\Model\WorkflowRunFile([
        'workflow_run_id' => 1, 'file_id' => 10, 'parse_id' => 20,
        'sha256' => $hash, 'version_ref' => 'file-10-v1', 'status' => 1, 'delete_time' => null,
    ]);
    $file = new \app\Infrastructure\Persistence\Model\AiFile([
        'id' => 10, 'environment_id' => 1, 'owner_scope' => $scope,
        'owner_attribution' => $fileAttribution->toPersisted(), 'sha256' => $hash,
        'state' => 'parsed', 'status' => 1, 'delete_time' => null,
    ]);
    $parse = new \app\Infrastructure\Persistence\Model\AiFileParse([
        'id' => 20, 'file_id' => 10, 'state' => 'succeeded',
        'status' => 1, 'delete_time' => null,
    ]);
    $block = new \app\Infrastructure\Persistence\Model\SourceBlock([
        'id' => 30, 'file_id' => 10, 'parse_id' => 20, 'sequence_no' => 1, 'status' => 1,
        'delete_time' => null, 'content' => 'source',
        'content_sha256' => $hash, 'locator_type' => 'page', 'locator' => ['page' => 1],
    ]);
    \app\Infrastructure\Persistence\Model\WorkflowRunFile::$rows = [$runFile];
    \app\Infrastructure\Persistence\Model\AiFile::$rows = [$file];
    \app\Infrastructure\Persistence\Model\AiFileParse::$rows = [$parse];
    \app\Infrastructure\Persistence\Model\SourceBlock::$rows = [$block];
    $read = new \ReflectionMethod(\app\Application\Workflow\StructuredWorkflowGateway::class, 'artifact');
    $gateway = new \app\Application\Workflow\StructuredWorkflowGateway();
    // Freeze the same controlled source data used by the production submit path.
    // Negative cases below change live rows, never rebuild this saved snapshot.
    $sources = new \ReflectionMethod(\app\Application\Workflow\StructuredWorkflowGateway::class, 'snapshotSourceFiles');
    $capturedSourceFiles = $sources->invoke($gateway, [[
        'file_id' => 10, 'parse_id' => 20, 'sha256' => $hash, 'version_ref' => 'file-10-v1',
    ]]);
    $snapshot = (new \app\Application\Workflow\ExecutionSnapshotBuilder())->build([
        'environment_id' => 1,
        'attribution' => $runAttribution->toPersisted(),
        'workflow_definition' => ['version' => '1.0.0', 'steps' => ['build_source_manifest']],
        'business_scope' => ['ref' => 'case-1', 'snapshot_ref' => 'case-1-v1', 'snapshot_version' => '1'],
        'workflow' => ['code' => 'source_manifest', 'version' => '1.0.0'],
        'output_schema' => ['code' => 'sand_ai.source_manifest', 'version' => '1.0.0'],
        'analysis_profile' => null,
        'review_required' => false,
        'logical_execution_id' => str_repeat('a', 48),
        'files' => $capturedSourceFiles,
    ]);
    $run->execution_snapshot_version = \app\Application\Workflow\ExecutionSnapshotBuilder::VERSION;
    $run->execution_snapshot = $snapshot['snapshot'];
    $run->execution_snapshot_sha256 = $snapshot['sha256'];
    $manifest = (new \app\Application\Workflow\SourceManifestBuilder())->build($capturedSourceFiles);
    $artifact->payload = $manifest['payload'];
    \app\Infrastructure\Persistence\Model\ArtifactFieldSource::$rows = array_map(
        static fn (array $source): \app\Infrastructure\Persistence\Model\ArtifactFieldSource =>
            new \app\Infrastructure\Persistence\Model\ArtifactFieldSource(['artifact_id' => 1, ...$source]),
        $manifest['field_sources'],
    );
    $valid = $read->invoke($gateway, $artifact, $run);
    if (($valid['citations'][0]['source_block_id'] ?? null) !== 30) {
        throw new \RuntimeException('Current artifact citation did not resolve');
    }

    $checks = 0;
    $reject = function (string $label) use ($read, $gateway, $artifact, $run, &$checks): void {
        try {
            $read->invoke($gateway, $artifact, $run);
        } catch (\app\Api\Support\ApiProblem $problem) {
            if ($problem->errorCode === 'SAND_AI_ARTIFACT_PROVENANCE_INVALID') {
                ++$checks;
                return;
            }
            throw $problem;
        }
        throw new \RuntimeException($label . ' returned a stale artifact');
    };
    $file->status = 0;
    $reject('disabled file');
    $file->status = 1;
    $file->owner_scope = str_repeat('a', 64);
    $reject('changed file owner');
    $file->owner_scope = $scope;
    $file->sha256 = str_repeat('b', 64);
    $reject('changed file digest');
    $file->sha256 = $hash;
    $parse->status = 0;
    $reject('disabled parse');
    $parse->status = 1;
    $block->content = 'changed';
    $reject('changed cited content');
    $block->content = 'source';

    $run->execution_snapshot = null;
    $reject('missing execution snapshot');
    $run->execution_snapshot = $snapshot['snapshot'];
    $run->execution_snapshot_sha256 = str_repeat('0', 64);
    $reject('tampered execution snapshot digest');
    $run->execution_snapshot_sha256 = $snapshot['sha256'];
    $block->content = 'changed';
    $block->content_sha256 = hash('sha256', $block->content);
    $reject('consistent live content and digest changed since freezing');
    $block->content = 'source';
    $block->content_sha256 = $hash;
    $block->locator = ['page' => 2];
    $reject('current locator changed since freezing');
    $block->locator = ['page' => 1];
    $block->id = 31;
    \app\Infrastructure\Persistence\Model\ArtifactFieldSource::$rows[0]->source_block_id = 31;
    $reject('valid live block was not in the frozen execution');
    $block->id = 30;
    \app\Infrastructure\Persistence\Model\ArtifactFieldSource::$rows[0]->source_block_id = 30;
    $file->environment_id = 2;
    $reject('source file belongs to another environment');
    $file->environment_id = 1;
    $file->delete_time = '2026-09-28 00:00:00';
    $reject('deleted source file');
    $file->delete_time = null;

    $citation = $valid['citations'][0];
    $references = new \ReflectionMethod(\app\Application\Workflow\StructuredWorkflowGateway::class, 'matchingSourceReferences');
    $detail = [
        'citations' => [$citation],
        'file_results' => [['citations' => [
            $citation,
        ]]],
    ];
    if (count($references->invoke($gateway, $detail, 30)) !== 2
        || $references->invoke($gateway, $detail, 31) !== []) {
        throw new \RuntimeException('Only cited source blocks may be previewed');
    }
    $preview = new \ReflectionMethod(\app\Application\Workflow\StructuredWorkflowGateway::class, 'currentSourcePreview');
    $actual = $preview->invoke($gateway, $run, $block, [$citation]);
    if ($actual['content'] !== 'source' || $actual['field_paths'] !== [$manifest['field_sources'][0]['field_path']]) {
        throw new \RuntimeException('Current cited source did not resolve');
    }
    $rejectPreview = function (string $label, array $matched = []) use ($preview, $gateway, $run, $block, $citation, &$checks): void {
        try {
            $preview->invoke($gateway, $run, $block, $matched ?: [$citation]);
        } catch (\app\Api\Support\ApiProblem $problem) {
            if ($problem->errorCode === 'SAND_AI_ARTIFACT_PROVENANCE_INVALID') {
                ++$checks;
                return;
            }
            throw $problem;
        }
        throw new \RuntimeException($label . ' returned stale source content');
    };
    $file->status = 0;
    $rejectPreview('disabled source file');
    $file->status = 1;
    $file->owner_scope = str_repeat('a', 64);
    $rejectPreview('source owner changed');
    $file->owner_scope = $scope;
    $file->sha256 = str_repeat('b', 64);
    $rejectPreview('source digest changed');
    $file->sha256 = $hash;
    $parse->status = 0;
    $rejectPreview('source parse disabled');
    $parse->status = 1;
    $block->content = 'changed';
    $rejectPreview('source content changed');
    $block->content = 'source';
    $rejectPreview('citation locator changed', [array_replace($citation, ['locator' => ['page' => 2]])]);
    $block->content = 'changed';
    $block->content_sha256 = hash('sha256', $block->content);
    $rejectPreview('preview live content and digest changed since freezing');
    $block->content = 'source';
    $block->content_sha256 = $hash;
    $block->locator = ['page' => 2];
    $rejectPreview('preview locator and citation both changed since freezing', [array_replace($citation, ['locator' => ['page' => 2]])]);
    $block->locator = ['page' => 1];
    $file->environment_id = 2;
    $rejectPreview('preview source belongs to another environment');
    $file->environment_id = 1;
    $runFile->status = 0;
    $rejectPreview('preview source removed from active run files');
    $runFile->status = 1;
    echo 'workflow-artifact-source-provenance ' . ($checks + 4) . '/26' . PHP_EOL;
}
