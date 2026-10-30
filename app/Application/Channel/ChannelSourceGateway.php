<?php

declare(strict_types=1);

namespace app\Application\Channel;

use app\Api\Support\ApiProblem;
use app\Application\Agent\AgentStreamEventGateway;
use app\Infrastructure\Persistence\Model\SourceBlock;
use app\Infrastructure\Persistence\Model\TaskRun;

/** Reads only a server-persisted task result or an exact encrypted native event reference. */
final class ChannelSourceGateway
{
    public function read(TaskRun $task, int $blockId, ?int $eventNo): array
    {
        if ($task->content_purged_time !== null) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Channel source is unavailable');
        $references = [];
        if ($eventNo === null) {
            $result = is_object($task->result) ? (array) $task->result : $task->result;
            if ((string) $task->state === 'succeeded' && is_array($result)) {
                $references = $result['source_references'] ?? [];
            }
        } else {
            $events = (new AgentStreamEventGateway())->read($task, $eventNo - 1);
            foreach ($events as $event) {
                if ($event['event_no'] === $eventNo) {
                    $references = $event['source_references'];
                    break;
                }
            }
        }
        $references = ChannelConversationPolicy::sourceReferences($references);
        $matched = array_values(array_filter($references,
            static fn (array $reference): bool => $reference['source_block_id'] === $blockId));
        if (count($matched) !== 1) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Channel source is not referenced');
        }
        $reference = $matched[0];
        $block = SourceBlock::where('id', $blockId)->where('file_id', $reference['file_id'])
            ->where('status', 1)->whereNull('delete_time')->find();
        $locator = $block === null ? null : $block->locator;
        if (is_object($locator)) $locator = (array) $locator;
        if ($block === null || !is_array($locator)
            || (string) $block->locator_type !== $reference['locator_type']
            || self::canonicalLocator($locator) !== self::canonicalLocator($reference['locator'])
            || !hash_equals((string) $block->content_sha256, hash('sha256', (string) $block->content))) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Channel source changed');
        }
        // Caller authorizes and revalidates this exact persisted reference before returning content.
        return ['task_id' => (int) $task->id, ...$reference, 'content' => (string) $block->content,
            'reference_kind' => $eventNo === null ? 'result' : 'stream_preview', 'event_no' => $eventNo];
    }
    private static function canonicalLocator(array $locator): array
    {
        $list = array_is_list($locator);
        foreach ($locator as $key => $value) {
            if (is_array($value)) $locator[$key] = self::canonicalLocator($value);
        }
        if (!$list) ksort($locator, SORT_STRING);
        return $locator;
    }
}
