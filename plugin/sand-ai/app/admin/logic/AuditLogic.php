<?php

declare(strict_types=1);

namespace plugin\SandAi\app\admin\logic;

use app\Application\Audit\AuditDisplayDictionary;
use app\Application\Audit\AuditRows;
use app\Infrastructure\Persistence\Model\AiFile;
use app\Infrastructure\Persistence\Model\AiModel;
use app\Infrastructure\Persistence\Model\AuditLog;
use app\Infrastructure\Persistence\Model\CapabilityProfile;
use app\Infrastructure\Persistence\Model\KnowledgeBase;
use app\Infrastructure\Persistence\Model\Provider;
use think\facade\Db;

/** Read-only SandAI audit search use case owned by the full package. */
final class AuditLogic
{
    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function index(array $input): array
    {
        $page = max(1, (int) ($input['page'] ?? 1));
        $limit = min(100, max(1, (int) ($input['limit'] ?? 10)));
        $offset = AuditRows::clampOffset((int) ($input['offset_minutes'] ?? 0));
        $query = AuditLog::order('create_time', 'desc')->order('id', 'desc');
        $name = trim((string) ($input['name'] ?? ''));
        if ($name !== '') {
            $query->where(function (mixed $filter) use ($name): void {
                $filter->whereLike('summary', '%' . addcslashes($name, '%_\\') . '%')
                    ->whereOr('actor_ref', 'like', '%' . addcslashes($name, '%_\\') . '%');
            });
        }
        $action = trim((string) ($input['action'] ?? ''));
        if ($action !== '') {
            $candidates = AuditDisplayDictionary::actionCandidates($action);
            $query->where(function (mixed $filter) use ($action, $candidates): void {
                $filter->whereLike('action', '%' . addcslashes($action, '%_\\') . '%');
                if ($candidates !== []) {
                    $filter->whereOr('action', 'in', $candidates);
                }
            });
        }
        $stored = $query->field('id,actor_type,actor_ref,action,resource_type,resource_id,summary,create_time')
            ->select()
            ->toArray();
        $merged = AuditRows::mergeTrialIssues($stored, $offset, AuditRows::today($offset));
        $total = count($merged);
        $slice = array_slice($merged, ($page - 1) * $limit, $limit);
        $people = $this->personNames($slice);
        $objects = $this->objectNames($slice);
        $visible = [];
        foreach ($slice as $row) {
            $visible[] = $this->present($row, $people, $objects);
        }
        $lastPage = (int) ceil($total / $limit);
        return [
            'total' => $total,
            'per_page' => $limit,
            'current_page' => $page,
            'last_page' => max(1, $lastPage),
            'data' => $visible,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, string> $people
     * @param array<string, array<int, string>> $objects
     * @return array<string, mixed>
     */
    private function present(array $row, array $people, array $objects): array
    {
        $actorType = (string) $row['actor_type'];
        $actorRef = (string) $row['actor_ref'];
        if ($actorType === 'admin' && ctype_digit($actorRef)) {
            $actorName = $people[(int) $actorRef] ?? '管理员';
        } elseif ($actorType === 'api_credential') {
            $actorName = '应用调用';
        } elseif ($actorType === 'system_worker') {
            $actorName = '系统';
        } else {
            $actorName = '未知';
        }
        $resourceType = (string) $row['resource_type'];
        $resourceId = (int) $row['resource_id'];
        $lookupType = $this->lookupType($resourceType);
        $objectName = $objects[$lookupType][$resourceId] ?? AuditDisplayDictionary::resource($resourceType);
        $action = (string) $row['action'];
        $preset = (string) ($row['sentence_key'] ?? '');
        if ($preset !== '') {
            $sentence = $preset;
        } elseif ($action === 'file.upload' && $objectName !== '' && $objectName !== '文件') {
            $sentence = '上传了「' . $objectName . '」';
        } else {
            $sentence = AuditDisplayDictionary::summary($action, (string) $row['summary']);
        }
        $entries = [];
        foreach ($row['entries'] as $entry) {
            if (is_array($entry)) {
                $entries[] = ['create_time' => (string) ($entry['create_time'] ?? '')];
            }
        }
        return [
            'id' => (int) $row['id'],
            'create_time' => (string) $row['create_time'],
            'actor_name' => $actorName,
            'sentence' => AuditRows::plainSentence($sentence),
            'object_name' => $objectName,
            'result' => AuditRows::resultLabel($action),
            'issue_count' => (int) $row['issue_count'],
            'entries' => $entries,
        ];
    }

    private function lookupType(string $resourceType): string
    {
        if ($resourceType === '服务商') {
            return 'provider';
        }
        if ($resourceType === '模型') {
            return 'model';
        }
        return $resourceType;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<int, string>
     */
    private function personNames(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $actorRef = (string) ($row['actor_ref'] ?? '');
            if (($row['actor_type'] ?? '') === 'admin' && ctype_digit($actorRef)) {
                $ids[] = (int) $actorRef;
            }
        }
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return [];
        }
        $found = Db::table('sand_system_user')->whereIn('id', $ids)->field('id,realname,username')->select();
        $names = [];
        foreach ($found as $user) {
            $record = is_array($user) ? $user : $user->toArray();
            $names[(int) $record['id']] = AuditRows::personName(
                (string) ($record['realname'] ?? ''),
                (string) ($record['username'] ?? '')
            );
        }
        return $names;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, array<int, string>>
     */
    private function objectNames(array $rows): array
    {
        $ids = [
            'file' => [],
            'knowledge_base' => [],
            'agent_definition' => [],
            'provider' => [],
            'model' => [],
            'capability_profile' => [],
            'workload_client' => [],
        ];
        foreach ($rows as $row) {
            $type = $this->lookupType((string) ($row['resource_type'] ?? ''));
            if (!isset($ids[$type])) {
                continue;
            }
            $ids[$type][] = (int) ($row['resource_id'] ?? 0);
        }
        $fileIds = $this->cleanIds($ids['file']);
        $knowledgeIds = $this->cleanIds($ids['knowledge_base']);
        $providerIds = $this->cleanIds($ids['provider']);
        $modelIds = $this->cleanIds($ids['model']);
        $profileIds = $this->cleanIds($ids['capability_profile']);
        return [
            'file' => $fileIds === [] ? [] : $this->column(AiFile::whereIn('id', $fileIds), 'original_name'),
            'knowledge_base' => $knowledgeIds === [] ? [] : $this->column(KnowledgeBase::whereIn('id', $knowledgeIds), 'name'),
            'provider' => $providerIds === [] ? [] : $this->column(Provider::whereIn('id', $providerIds), 'name'),
            'model' => $modelIds === [] ? [] : $this->column(AiModel::whereIn('id', $modelIds), 'name'),
            'capability_profile' => $profileIds === [] ? [] : $this->column(CapabilityProfile::whereIn('id', $profileIds), 'name'),
            'agent_definition' => $this->agentNames($ids['agent_definition']),
            'workload_client' => $this->clientNames($ids['workload_client']),
        ];
    }

    /**
     * @param list<int> $ids
     * @return list<int>
     */
    private function cleanIds(array $ids): array
    {
        $clean = [];
        foreach ($ids as $id) {
            if ($id > 0) {
                $clean[] = $id;
            }
        }
        return array_values(array_unique($clean));
    }

    /**
     * @param list<int> $ids
     * @return array<int, string>
     */
    private function agentNames(array $ids): array
    {
        $clean = $this->cleanIds($ids);
        if ($clean === []) {
            return [];
        }
        return $this->idNames(Db::table('sand_ai_agent_definition')->whereIn('id', $clean)->column('name', 'id'));
    }

    /**
     * @param list<int> $ids
     * @return array<int, string>
     */
    private function clientNames(array $ids): array
    {
        $clean = $this->cleanIds($ids);
        if ($clean === []) {
            return [];
        }
        return $this->idNames(Db::table('sand_iam_workload_client')->whereIn('id', $clean)->column('name', 'id'));
    }

    /**
     * @param mixed $found
     * @return array<int, string>
     */
    private function idNames(mixed $found): array
    {
        if (!is_array($found)) {
            return [];
        }
        $names = [];
        foreach ($found as $id => $name) {
            $names[(int) $id] = (string) $name;
        }
        return $names;
    }

    /**
     * @param mixed $query
     * @return array<int, string>
     */
    private function column(mixed $query, string $field): array
    {
        $found = $query->column($field, 'id');
        if (!is_array($found) || $found === []) {
            return [];
        }
        $names = [];
        foreach ($found as $id => $name) {
            $names[(int) $id] = (string) $name;
        }
        return $names;
    }
}
