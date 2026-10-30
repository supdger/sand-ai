<?php

declare(strict_types=1);

namespace app\Application\Audio;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\RequestIdempotencyKey;
use app\Application\Task\AudioTaskResultPresenter;
use app\Application\Task\TaskState;
use app\Application\Usage\UsageAttribution;
use app\Identity\Infrastructure\FileInvocationFacts;
use app\Infrastructure\Persistence\Model\AiFile;
use app\Infrastructure\Persistence\Model\TaskRun;
use think\facade\Db;

/** Rechecks the finished audio task and private file before summary submission or dispatch. */
final class MeetingSummarySource
{
    /** Submission facts without loading a long transcript or building a plan.
     * @return array<string,mixed>
     */
    public function compact(int $environmentId, int $audioTaskId, UsageAttribution $identity, int $reviewRevision = 0): array
    {
        $metadata = Db::connect('pgsql')->query(
            "SELECT jsonb_exists(result, '_audio_chunk_aggregation') AS aggregated FROM sand_ai_task_run
             WHERE id=? AND environment_id=? AND task_type='audio_transcription'", [$audioTaskId, $environmentId]);
        if (count($metadata) !== 1) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Completed audio task not found');
        if (in_array($metadata[0]['aggregated'], [true, 1, '1', 't'], true)) {
            return (new PostgresMeetingAggregationSource())->compact($environmentId, $audioTaskId, $identity, $reviewRevision);
        }
        $seals = Db::connect('pgsql')->query(
            'SELECT id,normalization_version,transcript_sha256 FROM sand_ai_audio_transcript_seal
             WHERE audio_task_id=? AND environment_id=? AND review_revision=?', [$audioTaskId, $environmentId, $reviewRevision]);
        if (count($seals) !== 1) {
            throw new ApiProblem('SAND_AI_AUDIO_SUMMARY_SOURCE_INVALID', '会议来源尚未形成可分页读取的固定凭据');
        }
        return $this->current($environmentId, $audioTaskId, $identity, $seals[0]['transcript_sha256'], $reviewRevision,
            ['id' => (int) $seals[0]['id'], 'version' => $seals[0]['normalization_version']]);
    }
    /** @return array{task:TaskRun,file_id:int,file_ref:string,sha256:string,data_class:?string,data_egress_policy:string,review_revision:int,transcript:array<string,mixed>,prepared:?array<string,mixed>} */
    public function load(int $environmentId, int $audioTaskId, UsageAttribution $identity, ?string $expectedHash = null, int $reviewRevision = 0, bool $allowEmpty = false, ?array $manifest = null, ?array $capacity = null): array
    {
        $task = TaskRun::where('id', $audioTaskId)->where('environment_id', $environmentId)
            ->where('task_type', 'audio_transcription')->where('resource_type', 'audio_transcription')
            ->where('state', TaskState::SUCCEEDED)->where('status', 1)->whereNull('delete_time')->find();
        if ($task === null || (int) $task->resource_id !== $audioTaskId) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Completed audio task not found');
        }
        $input = is_object($task->input) ? (array) $task->input : $task->input;
        $raw = is_object($task->result) ? (array) $task->result : $task->result;
        $request = is_array($input) ? ($input['request'] ?? null) : null;
        $driver = is_array($input) ? ($input['execution_driver'] ?? null) : null;
        if (!is_array($input) || !is_array($request) || !is_array($driver) || !is_array($raw)
            || !is_int($input['file_id'] ?? null) || $input['file_id'] < 1
            || !is_string($request['file_ref'] ?? null)
            || !in_array($request['data_egress_policy'] ?? null, ['local_only', 'private_only', 'approved_cloud'], true)
            || !is_string($input['file_sha256'] ?? null)
            || !in_array($driver['code'] ?? null, ['local_whisper_cpp', 'openai_compatible_audio', 'glm_asr_audio'], true)
            || ($driver['version'] ?? null) !== '1.0.0') {
            throw new ApiProblem('SAND_AI_AUDIO_SUMMARY_SOURCE_INVALID', 'The audio task source is invalid');
        }
        try {
            $frozenIdentity = UsageAttribution::fromCurrentPersisted(
                is_array($input['usage_attribution'] ?? null) ? $input['usage_attribution'] : null,
                $environmentId, 'capability', 'sand_ai.audio.transcription',
            );
        } catch (\Throwable) {
            throw new ApiProblem('SAND_AI_AUDIO_SUMMARY_SOURCE_INVALID', 'The audio task identity is invalid');
        }
        if ($frozenIdentity->canonicalTrustedIdentity() !== $identity->canonicalTrustedIdentity()) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Completed audio task not found');
        }
        $file = AiFile::where('id', $input['file_id'])->where('environment_id', $environmentId)
            ->where('file_ref', $request['file_ref'])
            ->where('owner_scope', RequestIdempotencyKey::trustedScopeForAttribution($frozenIdentity))
            ->where('sha256', $input['file_sha256'])->where('state', 'uploaded')
            ->where('status', 1)->whereNull('delete_time')->find();
        if ($file === null) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Private audio source not found');
        }
        try {
            $fileFacts = FileInvocationFacts::fromPersisted([
                'id' => $file->id,
                'status' => $file->status,
                'delete_time' => $file->delete_time,
                'state' => $file->state,
                'environment_id' => $file->environment_id,
                'owner_scope' => $file->owner_scope,
                'owner_attribution' => $file->owner_attribution,
            ], (int) $file->id);
        } catch (\Throwable) {
            throw new ApiProblem('SAND_AI_AUDIO_SUMMARY_SOURCE_INVALID', 'The audio file facts are invalid');
        }
        if ($fileFacts['organization_id'] !== $identity->organizationId
            || $fileFacts['application_id'] !== $identity->applicationId
            || $fileFacts['workload_client_id'] !== $identity->workloadClientId) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Private audio source not found');
        }
        $dataClass = $fileFacts['data_class'];
        $transcript = \app\Application\Audio\Chunk\PostgresAudioChunkAggregation::marked($raw)
            ? (new \app\Application\Audio\Chunk\PostgresAudioChunkAggregation())->read($task)['transcript']
            : (new AudioTaskResultPresenter())->present($raw, $driver['code'], $driver['version']);
        foreach ($transcript['segments'] as $segment) {
            if (!hash_equals($request['file_ref'], $segment['source']['file_ref'])
                || !hash_equals($input['file_sha256'], $segment['source']['content_sha256'])) {
                throw new ApiProblem('SAND_AI_AUDIO_SUMMARY_SOURCE_INVALID', 'The audio transcript source changed');
            }
        }
        $transcript = (new AudioSegmentReviewLedger())->snapshot(
            $audioTaskId, $transcript, RequestIdempotencyKey::trustedScopeForAttribution($identity),
            $reviewRevision,
        );
        $hash = hash('sha256', json_encode($transcript, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        if ($expectedHash !== null && !hash_equals($expectedHash, $hash)) {
            throw new ApiProblem('SAND_AI_AUDIO_SUMMARY_SOURCE_INVALID', 'The audio transcript changed');
        }
        $eligible = 0;
        foreach ($transcript['segments'] as $segment) {
            if (trim($segment['text']) !== '') ++$eligible;
        }
        $seal = (new AudioTranscriptSealLedger())->reference(
            $task, $reviewRevision, $hash, count($transcript['segments']), $eligible,
            RequestIdempotencyKey::trustedScopeForAttribution($identity),
        );
        return [
            'task' => $task,
            'file_id' => (int) $file->id,
            'file_ref' => $request['file_ref'],
            'sha256' => $hash,
            'data_class' => $dataClass,
            'data_egress_policy' => $request['data_egress_policy'],
            'review_revision' => $reviewRevision,
            'total_segments' => count($transcript['segments']),
            'eligible_segments' => $eligible,
            'transcript_seal' => $seal,
            'transcript' => $transcript,
            'prepared' => $allowEmpty ? null : (new MeetingSummaryEvidence())->prepare($transcript, $manifest, $capacity),
        ];
    }

    /**
     * Current facts for one invocation. An exact frozen seal allows omission of the
     * transcript; legacy/unsealed tasks still run the complete presenter and ledger.
     *
     * @param array{id:int,version:string}|null $frozenSeal
     * @return array<string,mixed>
     */
    public function current(int $environmentId, int $audioTaskId, UsageAttribution $identity, string $expectedHash, int $reviewRevision, ?array $frozenSeal, ?array $aggregationSource = null): array
    {
        if ($aggregationSource !== null) {
            if ($frozenSeal !== null) AudioTranscriptSealLedger::invalid();
            return (new PostgresMeetingAggregationSource())->current($environmentId, $audioTaskId, $identity,
                $expectedHash, $reviewRevision, $aggregationSource);
        }
        $frozenSeal = AudioTranscriptSealLedger::normalizeReference($frozenSeal);
        if ($frozenSeal === null) {
            return $this->load($environmentId, $audioTaskId, $identity, $expectedHash, $reviewRevision, true);
        }
        AudioTranscriptSealLedger::assertReady();
        if ($reviewRevision < 0 || $reviewRevision > 10000) AudioTranscriptSealLedger::invalid();
        // One statement observes task, file and the exact historical seal together.
        // Do not filter seal status/hash/owner: a bad row must reject, not look absent.
        $task = TaskRun::withTrashed()->alias('audio')
            ->leftJoin('sand_ai_audio_transcript_seal seal', 'seal.audio_task_id=audio.id AND seal.review_revision=' . $reviewRevision)
            ->leftJoin('sand_ai_file file', 'file.id=seal.file_id')
            ->field('audio.id,audio.environment_id,audio.task_type,audio.resource_type,audio.resource_id,audio.state,audio.status,audio.delete_time,audio.content_purged_time,audio.input,audio.version,audio.audio_seal_eligible')
            ->fieldRaw('seal.id AS source_seal_id,seal.normalization_version AS source_seal_version,seal.transcript_sha256 AS source_sha256,seal.environment_id AS source_environment_id,seal.task_version AS source_task_version,seal.file_id AS source_file_id,seal.file_ref AS source_file_ref,seal.file_sha256 AS source_file_sha256,seal.owner_scope AS source_owner_scope,seal.data_egress_policy AS source_egress_policy,seal.total_segments AS source_total_segments,seal.eligible_segments AS source_eligible_segments,seal.content_purged_time AS source_purged_time')
            ->fieldRaw('file.id AS current_file_id,file.environment_id AS current_file_environment_id,file.file_ref AS current_file_ref,file.sha256 AS current_file_sha256,file.state AS current_file_state,file.status AS current_file_status,file.delete_time AS current_file_delete_time,file.owner_scope AS current_file_owner_scope,file.owner_attribution AS current_file_owner_attribution')
            ->where('audio.id', $audioTaskId)->where('audio.environment_id', $environmentId)->find();
        if ($task === null || (string) $task->task_type !== 'audio_transcription'
            || (string) $task->resource_type !== 'audio_transcription' || (int) $task->resource_id !== $audioTaskId
            || (string) $task->state !== TaskState::SUCCEEDED || (int) $task->status !== 1
            || $task->delete_time !== null || $task->content_purged_time !== null) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Completed audio task not found');
        }
        $input = is_object($task->input) ? (array) $task->input : $task->input;
        $request = is_array($input) ? ($input['request'] ?? null) : null;
        if (!is_array($input) || !is_array($request) || !is_int($input['file_id'] ?? null)
            || !is_string($input['file_sha256'] ?? null) || !is_string($request['file_ref'] ?? null)) {
            AudioTranscriptSealLedger::invalid();
        }
        try {
            $owner = UsageAttribution::fromCurrentPersisted(
                is_array($input['usage_attribution'] ?? null) ? $input['usage_attribution'] : null,
                $environmentId, 'capability', 'sand_ai.audio.transcription',
            );
        } catch (\Throwable) {
            AudioTranscriptSealLedger::invalid();
        }
        if ($owner->canonicalTrustedIdentity() !== $identity->canonicalTrustedIdentity()) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Completed audio task not found');
        }
        $scope = RequestIdempotencyKey::trustedScopeForAttribution($owner);
        if (!AudioTranscriptSealLedger::eligible($task)
            || (int) $task->getAttr('source_seal_id') !== $frozenSeal['id']
            || $task->getAttr('source_seal_version') !== $frozenSeal['version']
            || $task->getAttr('source_purged_time') !== null
            || !hash_equals($expectedHash, (string) $task->getAttr('source_sha256'))
            || (int) $task->getAttr('source_environment_id') !== $environmentId
            || (int) $task->getAttr('source_task_version') !== (int) $task->version
            || (int) $task->getAttr('source_file_id') !== $input['file_id']
            || $task->getAttr('source_file_ref') !== $request['file_ref']
            || !hash_equals($input['file_sha256'], (string) $task->getAttr('source_file_sha256'))
            || $task->getAttr('source_owner_scope') !== $scope
            || $task->getAttr('source_egress_policy') !== ($request['data_egress_policy'] ?? null)) {
            AudioTranscriptSealLedger::invalid();
        }
        if ((int) $task->getAttr('current_file_id') !== $input['file_id']
            || (int) $task->getAttr('current_file_environment_id') !== $environmentId
            || $task->getAttr('current_file_ref') !== $request['file_ref']
            || $task->getAttr('current_file_owner_scope') !== $scope
            || !hash_equals($input['file_sha256'], (string) $task->getAttr('current_file_sha256'))
            || $task->getAttr('current_file_state') !== 'uploaded'
            || (int) $task->getAttr('current_file_status') !== 1
            || $task->getAttr('current_file_delete_time') !== null) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Private audio source not found');
        }
        $fileOwner = $task->getAttr('current_file_owner_attribution');
        if (is_string($fileOwner)) {
            try { $fileOwner = json_decode($fileOwner, true, 512, JSON_THROW_ON_ERROR); }
            catch (\Throwable) { AudioTranscriptSealLedger::invalid(); }
        }
        try {
            $fileFacts = FileInvocationFacts::fromPersisted([
                'id' => $input['file_id'], 'status' => $task->getAttr('current_file_status'),
                'delete_time' => $task->getAttr('current_file_delete_time'), 'state' => $task->getAttr('current_file_state'),
                'environment_id' => $environmentId, 'owner_scope' => $scope, 'owner_attribution' => $fileOwner,
            ], $input['file_id']);
        } catch (\Throwable) {
            AudioTranscriptSealLedger::invalid();
        }
        if ($fileFacts['organization_id'] !== $identity->organizationId
            || $fileFacts['application_id'] !== $identity->applicationId
            || $fileFacts['workload_client_id'] !== $identity->workloadClientId) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Private audio source not found');
        }
        return [
            'task' => $task, 'file_id' => $input['file_id'], 'file_ref' => $request['file_ref'],
            'sha256' => $expectedHash, 'data_class' => $fileFacts['data_class'],
            'data_egress_policy' => $request['data_egress_policy'], 'review_revision' => $reviewRevision,
            'total_segments' => (int) $task->getAttr('source_total_segments'),
            'eligible_segments' => (int) $task->getAttr('source_eligible_segments'),
            'transcript_seal' => $frozenSeal,
        ];
    }

    /** @return list<array{role:string,content:string}> */
    public static function taskMarker(int $audioTaskId, string $sha256): array
    {
        return [['role' => 'user', 'content' => 'meeting_summary_source:' . $audioTaskId . ':' . $sha256]];
    }
}
