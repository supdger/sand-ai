<?php

declare(strict_types=1);

namespace app\Application\Tool;

use app\Api\Support\ApiProblem;
use app\Application\File\FileOwnershipGuard;
use app\Application\Gateway\SandIamContextAuthenticator;
use app\Application\Usage\UsageAttribution;
use app\Identity\Contract\IdentityContext;
use app\Identity\Infrastructure\HostSandIamInvocationAuthorizer;
use app\Identity\Infrastructure\ToolRunAccessGuard;
use app\Infrastructure\Persistence\Model\AiFile;
use app\Infrastructure\Persistence\Model\ToolAttempt;
use app\Infrastructure\Persistence\Model\ToolConnection;
use app\Infrastructure\Persistence\Model\ToolConnectionRevision;
use app\Infrastructure\Persistence\Model\ToolOutcomeReceipt;
use app\Infrastructure\Persistence\Model\ToolRun;
use app\Infrastructure\Persistence\Model\ToolSource;
use app\Infrastructure\Persistence\Model\ToolVersion;
use support\Request;
use think\facade\Db;

/** Private material association and terminal body destruction; never dispatches or refunds. */
final class ToolFulltextFileGateway
{
    /** @return array{run_id:int,private_file_id:int,idempotent_replay:bool} */
    public function bind(IdentityContext $identity, string $key, int $fileId, Request $request, string $requestId): array
    {
        if (trim($key) === '' || strlen($key) > 128 || $fileId < 1) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Original fulltext key and a real private file id are required');
        }
        $readIdentity = (new SandIamContextAuthenticator())->authenticate($request, 'sand_ai.tool.read')['context'];
        $this->sameCaller($identity, $readIdentity);
        return Db::connect('pgsql')->transaction(function () use ($identity, $key, $fileId, $request, $requestId): array {
            // Lock order matches FileGateway.destroy: file, then tool run.
            [$file, $fileRevalidate] = $this->authorizedFile($identity, $fileId, $request, $requestId, true);
            $run = ToolRun::where('environment_id', $identity->environmentId)->where('request_id', trim($key))->lock(true)->find();
            $this->ownedPrivateRun($identity, $run);
            $guard = new ToolRunAccessGuard();
            $decisions = [];
            foreach (['sand_ai.tool.run', 'sand_ai.tool.read'] as $action) {
                $decisions[$action] = $guard->authorize($request, $identity, (int) $run->id, $action, $requestId,
                    $run->execution_snapshot['data_class'] ?? null);
            }
            $parentRevalidate = (new \app\Application\Agent\AgentReadonlyToolReadGuard())->authorizeParent($request, $identity, $run, $requestId);
            if ($run->content_purged_time !== null) $this->gone();
            $hash = $this->assertSettled($run);
            if (!is_string($file->sha256) || !hash_equals($hash, (string) $file->sha256)) {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Uploaded private file bytes differ from the original fulltext');
            }
            $existing = $this->association((int) $run->id);
            if ($existing !== null && ((int) $existing['file_id'] !== $fileId
                || !hash_equals(trim((string) $existing['content_sha256']), $hash))) {
                throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'Original fulltext is already bound to another file');
            }
            foreach ($decisions as $action => $decision) $guard->revalidate($request, $identity, (int) $run->id, $action, $requestId, $decision);
            $parentRevalidate();
            $fileRevalidate();
            if ($existing === null) {
                Db::connect('pgsql')->execute(
                    'INSERT INTO sand_ai_tool_fulltext_file(tool_run_id,file_id,environment_id,content_sha256) VALUES(?,?,?,?)',
                    [(int) $run->id, $fileId, $identity->environmentId, $hash],
                );
            }
            return ['run_id' => (int) $run->id, 'private_file_id' => $fileId, 'idempotent_replay' => $existing !== null];
        });
    }

    /** Call inside the private-read transaction; keeps the bound file locked until projection is built. */
    public function boundFile(IdentityContext $identity, ToolRun $run, Request $request, string $requestId): ?int
    {
        if ($run->content_purged_time !== null) $this->gone();
        $association = $this->association((int) $run->id);
        if ($association === null) {
            // Freeze "unbound" through the read. Bind takes this row before inserting the link.
            try {
                Db::connect('pgsql')->query('SELECT id FROM sand_ai_tool_run WHERE id=? FOR SHARE NOWAIT', [(int) $run->id]);
            } catch (\think\db\exception\PDOException $failure) {
                if (($failure->getData()['PDO Error Info']['SQLSTATE'] ?? null) !== '55P03') throw $failure;
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Private fulltext binding is changing; read the original key again');
            }
            if ($this->association((int) $run->id) !== null) {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Private fulltext acquired a material binding; read the original key again');
            }
            return null;
        }
        [$file, $revalidate] = $this->authorizedFile($identity, (int) $association['file_id'], $request, $requestId, false);
        Db::connect('pgsql')->query('SELECT id FROM sand_ai_tool_run WHERE id=? FOR SHARE', [(int) $run->id]);
        $result = $run->result;
        if ((int) $association['environment_id'] !== $identity->environmentId || !is_array($result)
            || !is_string($result['content'] ?? null) || !is_string($file->sha256)
            || !hash_equals(trim((string) $association['content_sha256']), hash('sha256', $result['content']))
            || !hash_equals(trim((string) $association['content_sha256']), (string) $file->sha256)) $this->gone();
        $revalidate();
        return (int) $file->id;
    }

    /** FileGateway holds the deleting file lock. All proof must exist before any bytes are cleared. */
    public function purgeForFile(int $environmentId, int $fileId): array
    {
        $db = Db::connect('pgsql');
        $links = $db->query('SELECT tool_run_id,content_sha256 FROM sand_ai_tool_fulltext_file WHERE environment_id=? AND file_id=? ORDER BY tool_run_id',
            [$environmentId, $fileId]);
        $counts = ['runs' => 0, 'receipts' => 0];
        foreach ($links as $link) {
            try {
                $db->query('SELECT id FROM sand_ai_tool_run WHERE id=? AND environment_id=? FOR UPDATE NOWAIT',
                    [(int) $link['tool_run_id'], $environmentId]);
            } catch (\think\db\exception\PDOException $failure) {
                if (($failure->getData()['PDO Error Info']['SQLSTATE'] ?? null) !== '55P03') throw $failure;
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Fulltext tool is changing during file deletion');
            }
            $run = ToolRun::where('id', (int) $link['tool_run_id'])->where('environment_id', $environmentId)->find();
            if ($run === null) $this->unsettled();
            if ($run->content_purged_time !== null) {
                $receipt = ToolOutcomeReceipt::where('tool_run_id', (int) $run->id)->find();
                if ($run->result !== null || $receipt === null || (string) $receipt->state !== 'destroyed'
                    || $receipt->ciphertext !== null) $this->unsettled();
                continue;
            }
            if (!hash_equals(trim((string) $link['content_sha256']), $this->assertSettled($run))) $this->unsettled();
            // Receipt guard observes the still-present original canonical result and proof.
            $counts['receipts'] += $db->execute(
                "UPDATE sand_ai_tool_outcome_receipt SET state='destroyed',key_id=NULL,nonce=NULL,tag=NULL,ciphertext=NULL,
                 plaintext_bytes=NULL,ciphertext_bytes=NULL,content_hmac=NULL,content_purged_time=CURRENT_TIMESTAMP(0)
                 WHERE tool_run_id=? AND environment_id=? AND state='available' AND content_purged_time IS NULL",
                [(int) $run->id, $environmentId],
            );
            $counts['runs'] += $db->execute(
                'UPDATE sand_ai_tool_run SET result=NULL,content_purged_time=CURRENT_TIMESTAMP(0),update_time=CURRENT_TIMESTAMP(0)
                 WHERE id=? AND environment_id=? AND content_purged_time IS NULL',
                [(int) $run->id, $environmentId],
            );
        }
        return $counts;
    }

    /** Authenticated sealed canonical result plus actual finalized MCP quantities; no pricing mutation. */
    private function assertSettled(ToolRun $run): string
    {
        if ((string) $run->state !== 'succeeded' || $run->content_purged_time !== null
            || ($run->execution_snapshot['result_visibility'] ?? null) !== 'private_fulltext'
            || ($run->execution_snapshot['adapter'] ?? null) !== 'mcp_streamable_http'
            || $run->lease_expires_at !== null || $run->completed_time === null || $run->audit_log_id === null) $this->unsettled();
        $attempt = ToolAttempt::where('tool_run_id', (int) $run->id)->where('attempt_no', 1)->lock(true)->find();
        $receipt = ToolOutcomeReceipt::where('tool_run_id', (int) $run->id)->lock(true)->find();
        if ($attempt === null || (string) $attempt->state !== 'succeeded' || $attempt->completed_time === null
            || $receipt === null || (string) $receipt->state !== 'available' || $receipt->content_purged_time !== null
            || (int) $receipt->environment_id !== (int) $run->environment_id
            || (int) $receipt->tool_id !== (int) $run->tool_id || (int) $receipt->tool_version_id !== (int) $run->tool_version_id
            || !hash_equals((string) $receipt->request_fingerprint, (string) $run->request_fingerprint)
            || !hash_equals((string) $attempt->dispatch_fingerprint, ToolFingerprint::for('tool_dispatch',
                ['run_id' => (int) $run->id, 'request_fingerprint' => (string) $run->request_fingerprint]))) $this->unsettled();
        $result = $run->result;
        if (!is_array($result) || !is_string($result['content'] ?? null)) $this->unsettled();
        $version = ToolVersion::find((int) $run->tool_version_id);
        $connection = ToolConnection::find((int) $run->tool_connection_id);
        $revision = ToolConnectionRevision::find((int) $run->tool_connection_revision_id);
        if ($version === null || $connection === null || $revision === null) $this->unsettled();
        ToolSchemaPolicy::assertValue((array) $version->output_schema, $result);
        $record = [];
        foreach (['receipt_version', 'cipher_version', 'key_id', 'nonce', 'tag', 'ciphertext', 'plaintext_bytes', 'ciphertext_bytes', 'content_hmac'] as $field) {
            $record[$field] = $receipt->getAttr($field);
        }
        $protocol = McpStreamableHttpClient::protocolVersionFromWireContract($run->execution_snapshot['wire_contract'] ?? null);
        $outcome = ToolProviderOutcome::decode((new ToolOutcomeReceiptCipher())->decrypt($record, [
            'environment_id' => (int) $run->environment_id, 'tool_id' => (int) $run->tool_id,
            'tool_version_id' => (int) $run->tool_version_id, 'tool_run_id' => (int) $run->id,
            'request_fingerprint' => (string) $run->request_fingerprint, 'version' => 'mcp_streamable_http/' . $protocol . '/json',
        ]));
        if ($outcome->state !== 'succeeded' || $outcome->httpStatus !== (int) $attempt->http_status
            || $outcome->latencyMs !== (int) $run->latency_ms || !hash_equals(
            ToolFingerprint::canonicalJson(['result' => $result, 'citations' => $run->citations]),
            ToolFingerprint::canonicalJson(['result' => $outcome->result, 'citations' => $outcome->citations]))) $this->unsettled();
        $hash = hash('sha256', $result['content']);
        $sources = $outcome->citations;
        if (count($sources) !== 1 || ($sources[0]['content_hash'] ?? null) !== $hash
            || !is_string($sources[0]['source_ref'] ?? null) || !str_starts_with($sources[0]['source_ref'], 'legal_fulltext:')) $this->unsettled();
        $sourceRows = ToolSource::where('tool_run_id', (int) $run->id)->order('sequence_no')->select();
        if (count($sourceRows) !== 1 || (int) $sourceRows[0]->sequence_no !== 1 || !hash_equals(
            ToolFingerprint::canonicalJson($sources[0]),
            ToolFingerprint::canonicalJson(['source_ref' => $sourceRows[0]->source_ref, 'locator' => $sourceRows[0]->locator,
                'source_version' => $sourceRows[0]->source_version, 'content_hash' => $sourceRows[0]->content_hash]))) $this->unsettled();
        $meter = new ToolUsageMeter();
        $evidence = $meter->protocolRecoveryEvidence($run);
        if (!$evidence['tools_call_dispatched'] || !$evidence['tools_call_response_known']
            || $evidence['tools_call_http_status'] === null || $evidence['tools_call_http_status'] >= 300
            || (int) $evidence['tools_call_http_status'] !== (int) $attempt->http_status) $this->unsettled();
        $quantity = $meter->protocolRequestCount($run);
        $actual = $meter->requireRecordedQuantity($run, $version, $connection, $revision, $quantity, 'mcp_streamable_http');
        if (!hash_equals(ToolFingerprint::canonicalJson($actual), ToolFingerprint::canonicalJson($run->metering))) $this->unsettled();
        return $hash;
    }

    /** @return array{AiFile,\Closure} */
    private function authorizedFile(IdentityContext $identity, int $fileId, Request $request, string $requestId, bool $writeLock): array
    {
        $fileIdentity = (new SandIamContextAuthenticator())->authenticate($request, 'sand_ai.file.read')['context'];
        $this->sameCaller($identity, $fileIdentity);
        Db::connect('pgsql')->query('SELECT id FROM sand_ai_file WHERE id=? AND environment_id=? ' . ($writeLock ? 'FOR UPDATE' : 'FOR SHARE'),
            [$fileId, $identity->environmentId]);
        $file = AiFile::where('id', $fileId)->where('environment_id', $identity->environmentId)->where('status', 1)->whereNull('delete_time')->find();
        if ($file === null || !in_array((string) $file->state, ['uploaded', 'parsing', 'parsed', 'parse_failed', 'parse_canceled'], true)) $this->gone();
        (new FileOwnershipGuard())->assert($file, FileOwnershipGuard::scope(UsageAttribution::fromIdentity($identity, 'file', 'sand_ai.file.read', 'file')));
        $authorizer = new HostSandIamInvocationAuthorizer();
        $decision = $authorizer->authorize($request, $fileIdentity, 'sand_ai.file.read', 'sand_ai.file', (string) $fileId,
            'tool-fulltext-file:' . bin2hex(random_bytes(16)), $requestId);
        $revalidate = function () use ($authorizer, $decision, $request, $fileIdentity, $fileId, $requestId): void {
            $header = trim((string) $request->header('authorization', ''));
            $signed = str_starts_with($header, 'Bearer ') ? trim(substr($header, 7)) : '';
            $current = $authorizer->revalidate((int) $decision['authorization_id'], $signed, $fileIdentity,
                'sand_ai.file.read', 'sand_ai.file', (string) $fileId, $request->getRemoteIp(), $requestId);
            if (($current['data_class'] ?? null) !== ($decision['data_class'] ?? null)) $this->gone();
        };
        return [$file, $revalidate];
    }

    private function association(int $runId): ?array
    {
        $rows = Db::connect('pgsql')->query('SELECT file_id,environment_id,content_sha256 FROM sand_ai_tool_fulltext_file WHERE tool_run_id=?', [$runId]);
        return $rows[0] ?? null;
    }

    private function ownedPrivateRun(IdentityContext $identity, ?ToolRun $run): void
    {
        if ($run === null || ($run->execution_snapshot['result_visibility'] ?? null) !== 'private_fulltext'
            || ($run->identity_snapshot['organization_ref'] ?? null) !== $identity->organizationId
            || ($run->identity_snapshot['application_ref'] ?? null) !== $identity->applicationId
            || ($run->identity_snapshot['workload_client_ref'] ?? null) !== $identity->workloadClientId) $this->gone();
    }

    private function sameCaller(IdentityContext $left, IdentityContext $right): void
    {
        if ($left->organizationId !== $right->organizationId || $left->applicationId !== $right->applicationId
            || $left->environmentId !== $right->environmentId || $left->workloadClientId !== $right->workloadClientId
            || $left->audience !== $right->audience || $left->expiresAt !== $right->expiresAt) $this->gone();
    }

    private function gone(): never
    {
        throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Private fulltext material is deleted or unavailable');
    }

    private function unsettled(): never
    {
        throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Original fulltext dispatch and usage evidence must be finalized before binding or deleting its material');
    }
}
