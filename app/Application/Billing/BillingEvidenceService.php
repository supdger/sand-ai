<?php

declare(strict_types=1);

namespace app\Application\Billing;

use app\Api\Support\ApiProblem;
use app\Infrastructure\Storage\StorageDriverFactory;
use think\facade\Db;
use Webman\Http\UploadFile;

/** Operator attachments have durable ownership before any private storage mutation. */
final class BillingEvidenceService
{
    /** @return array<string,mixed> */
    public function upload(int $providerId, UploadFile $upload, int $adminId): array
    {
        $this->admin($adminId);
        $path = $upload->getPathname();
        $bytes = filesize($path);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $extension = strtolower(pathinfo((string) $upload->getUploadName(), PATHINFO_EXTENSION));
        if (!$upload->isValid() || $bytes === false || $bytes < 1 || $bytes > 10485760
            || !in_array($extension, ['csv','pdf','xlsx'], true)
            || ($extension === 'pdf' && $mime !== 'application/pdf')
            || ($extension === 'csv' && !in_array($mime, ['text/plain','text/csv','application/csv'], true))
            || ($extension === 'xlsx' && !in_array($mime, ['application/zip','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'], true))) {
            throw new ApiProblem('SAND_AI_BILLING_EVIDENCE_INVALID', 'Upload a CSV, PDF or XLSX between 1 byte and 10 MiB');
        }
        if ($extension === 'xlsx') {
            $this->xlsx($path);
            $mime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        }
        $sha = hash_file('sha256', $path);
        if ($sha === false) { throw new ApiProblem('SAND_AI_BILLING_EVIDENCE_INVALID', 'Evidence digest unavailable'); }
        $driver = StorageDriverFactory::create();
        // This transaction commits the object owner/key before uploadFile can run.
        $claim = Db::connect('pgsql')->transaction(function () use ($providerId,$adminId,$bytes,$mime,$sha,$driver): array {
            $db = Db::connect('pgsql');
            $db->query('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['sand_ai:billing_evidence:' . $providerId . ':' . $sha]);
            if ($db->query('SELECT id FROM sand_ai_provider WHERE id = ? AND status = 1 AND delete_time IS NULL FOR SHARE', [$providerId]) === []) {
                throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Provider not found');
            }
            $existing = $db->table('sand_ai_billing_evidence')->where('provider_id',$providerId)->where('sha256',$sha)->find();
            if (is_array($existing)) { return ['replay' => true, 'row' => $existing]; }
            $key = 'billing-evidence/' . $providerId . '/' . bin2hex(random_bytes(24));
            $rows = $db->query(
                "INSERT INTO sand_ai_billing_evidence (provider_id,admin_id,sha256,byte_size,media_type,storage_driver,object_key,state) VALUES (?,?,?,?,?,?,?,'upload_pending') RETURNING *",
                [$providerId,$adminId,$sha,$bytes,$mime,$driver->code(),$key],
            );
            $this->audit($adminId,(int) $rows[0]['id'],'billing.evidence.upload_started',$sha);
            return ['replay' => false, 'row' => $rows[0]];
        });
        $id = (int) $claim['row']['id'];
        try {
            $row = Db::connect('pgsql')->transaction(function () use ($id,$path,$sha,$bytes,$adminId): array {
                $row = $this->locked($id);
                if ($row['state'] !== 'upload_pending') { return $row; }
                if ($row['sha256'] !== $sha || (int) $row['byte_size'] !== $bytes) {
                    throw new ApiProblem('SAND_AI_BILLING_EVIDENCE_INVALID','Evidence upload content changed');
                }
                $driver = StorageDriverFactory::create($row['storage_driver']);
                $driver->uploadFile($row['object_key'],$path,$row['media_type']);
                $this->readVerified($row);
                Db::connect('pgsql')->query("UPDATE sand_ai_billing_evidence SET state='available' WHERE id=? AND state='upload_pending'", [$id]);
                $this->audit($adminId,$id,'billing.evidence.upload',$sha);
                return $this->locked($id);
            });
        } catch (\Throwable $error) {
            // Failed upload/commit leaves the same durable owner/key. Same-file retry finds it.
            $row = Db::connect('pgsql')->table('sand_ai_billing_evidence')->where('id',$id)->find();
            if (!is_array($row)) { throw $error; }
        }
        $dto = $this->dto($row);
        return ['idempotent_replay' => $claim['replay'], 'upload_complete' => $dto['state'] === 'available', 'evidence' => $dto];
    }

    /** @return array{body:string,media_type:string,sha256:string} */
    public function download(int $id): array
    {
        return Db::connect('pgsql')->transaction(function () use ($id): array {
            $row = $this->locked($id);
            if ($row['state'] !== 'available') {
                throw new ApiProblem('SAND_AI_BILLING_EVIDENCE_UNAVAILABLE','Evidence is pending or cleared');
            }
            return ['body' => $this->readVerified($row), 'media_type' => $row['media_type'], 'sha256' => $row['sha256']];
        });
    }

    /** @return array<string,mixed> */
    public function clear(int $id, int $adminId): array
    {
        $this->admin($adminId);
        // Disable reads/reviews durably before attempting storage deletion.
        $row = Db::connect('pgsql')->transaction(function () use ($id,$adminId): array {
            $row = $this->locked($id);
            if (in_array($row['state'], ['clearing','cleared'], true)) { return $row; }
            Db::connect('pgsql')->query("UPDATE sand_ai_billing_evidence SET state='clearing' WHERE id=?", [$id]);
            $this->audit($adminId,$id,'billing.evidence.clear_started',$row['sha256']);
            return $this->locked($id);
        });
        if ($row['state'] === 'cleared') { return ['cleared' => false,'evidence' => $this->dto($row)]; }
        $row = Db::connect('pgsql')->transaction(function () use ($id,$adminId): array {
            $row = $this->locked($id);
            if ($row['state'] === 'cleared') { return $row; }
            if ($row['state'] !== 'clearing') { throw new ApiProblem('SAND_AI_BILLING_EVIDENCE_UNAVAILABLE','Evidence clearing state changed'); }
            StorageDriverFactory::create($row['storage_driver'])->delete($row['object_key']);
            Db::connect('pgsql')->query("UPDATE sand_ai_billing_evidence SET state='cleared',object_key=NULL,cleared_time=CURRENT_TIMESTAMP WHERE id=? AND state='clearing'", [$id]);
            $this->audit($adminId,$id,'billing.evidence.clear',$row['sha256']);
            return $this->locked($id);
        });
        return ['cleared' => true,'evidence' => $this->dto($row)];
    }

    /** Validates actual private content, not only metadata. @param array<string,mixed> $row */
    public function availability(array $row): string
    {
        if ($row['state'] !== 'available') { return 'evidence_unavailable'; }
        try { $this->readVerified($row); return 'available'; }
        catch (\Throwable) { return 'evidence_unavailable'; }
    }

    /** Called inside the billing import transaction; clearing takes the same row lock. */
    public function importSource(int $providerId, int $evidenceId): array
    {
        $row = $this->locked($evidenceId);
        if ((int) $row['provider_id'] !== $providerId || $this->availability($row) !== 'available') {
            throw new ApiProblem('SAND_AI_BILLING_EVIDENCE_UNAVAILABLE', 'Original evidence is unavailable or belongs to another provider');
        }
        return ['evidence_id' => (int) $row['id'], 'provider_id' => $providerId,
            'sha256' => (string) $row['sha256'], 'locator_basis' => 'operator_declared'];
    }
    /** @param array<string,mixed> $row */
    private function readVerified(array $row): string
    {
        try { $body = StorageDriverFactory::create($row['storage_driver'])->read($row['object_key']); }
        catch (\Throwable) { throw new ApiProblem('SAND_AI_BILLING_EVIDENCE_UNAVAILABLE','Evidence content cannot be read'); }
        if (strlen($body) !== (int) $row['byte_size'] || !hash_equals($row['sha256'],hash('sha256',$body))) {
            throw new ApiProblem('SAND_AI_BILLING_EVIDENCE_UNAVAILABLE','Evidence content differs from its digest');
        }
        return $body;
    }
    /** Checks the original package without evaluating cells, macros or external links. */
    private function xlsx(string $path): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CHECKCONS) !== true) {
            throw new ApiProblem('SAND_AI_BILLING_EVIDENCE_INVALID', 'XLSX package cannot be opened');
        }
        try {
            if ($zip->numFiles < 3 || $zip->numFiles > 2000
                || $zip->locateName('xl/workbook.xml') === false
                || $zip->locateName('_rels/.rels') === false) {
                throw new ApiProblem('SAND_AI_BILLING_EVIDENCE_INVALID', 'XLSX package structure is invalid');
            }
            $total = 0;
            $names = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entry = $zip->statIndex($index);
                $name = is_array($entry) ? (string) $entry['name'] : '';
                $total += is_array($entry) ? (int) $entry['size'] : 0;
                if ($name === '' || isset($names[$name]) || str_contains($name, '\\')
                    || str_starts_with($name, '/') || in_array('..', explode('/', $name), true)
                    || preg_match('/[\x00-\x1f\x7f]/', $name) === 1
                    || str_ends_with(strtolower($name), 'vbaproject.bin')
                    || ($entry['encryption_method'] ?? 0) !== 0 || $total > 52428800) {
                    throw new ApiProblem('SAND_AI_BILLING_EVIDENCE_INVALID', 'XLSX package contains unsupported entries');
                }
                $names[$name] = true;
            }
            $types = $zip->getFromName('[Content_Types].xml');
            if (!is_string($types) || strlen($types) > 1048576
                || stripos($types, '<!DOCTYPE') !== false || stripos($types, '<!ENTITY') !== false
                || stripos($types, 'macroEnabled') !== false
                || !str_contains($types, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml')) {
                throw new ApiProblem('SAND_AI_BILLING_EVIDENCE_INVALID', 'Only non-macro XLSX originals are supported');
            }
        } finally { $zip->close(); }
    }
    /** @return array<string,mixed> */
    private function locked(int $id): array
    {
        $rows = Db::connect('pgsql')->query('SELECT * FROM sand_ai_billing_evidence WHERE id=? FOR UPDATE', [$id]);
        if ($rows === []) { throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND','Evidence not found'); }
        return $rows[0];
    }
    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function dto(array $row): array
    {
        $state = match ($row['state']) {
            'upload_pending' => 'upload_pending', 'clearing' => 'clearing',
            default => $this->availability($row),
        };
        return ['id'=>(int) $row['id'],'provider_id'=>(int) $row['provider_id'],'sha256'=>(string) $row['sha256'],
            'byte_size'=>(int) $row['byte_size'],'media_type'=>(string) $row['media_type'],
            'trust_basis'=>'operator_supplied','state'=>$state];
    }
    private function admin(int $id): void
    {
        if ($id < 1) { throw new ApiProblem('SAND_AI_AUTHENTICATION_FAILED','Authenticated administrator required'); }
    }
    private function audit(int $adminId,int $id,string $action,string $sha): void
    {
        Db::connect('pgsql')->query('INSERT INTO sand_ai_audit_log (actor_type,actor_ref,action,resource_type,resource_id,summary,context) VALUES (?,?,?,?,?,?,CAST(? AS jsonb))',
            ['admin',(string) $adminId,$action,'billing_evidence',$id,'账单私有证据',json_encode(['sha256'=>$sha],JSON_THROW_ON_ERROR)]);
    }
}
