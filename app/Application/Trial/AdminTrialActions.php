<?php

declare(strict_types=1);

namespace app\Application\Trial;

use app\Api\Support\ApiProblem;

/**
 * Administrator trial action policy. The whitelist is the single boundary
 * deciding which service actions a trial identity context may carry;
 * administrative actions (catalog/skill/tool management, agent submission,
 * workflow execution) are never part of it.
 */
final class AdminTrialActions
{
    /**
     * Trial-capable read/run subset of HostSandIamServiceCatalog declarations.
     * Deliberately excludes agent.*, tool.*, skill.*, knowledge.*,
     * retrieval.search, workflow.run and every audio.speech action.
     *
     * @var list<string>
     */
    public const WHITELIST = [
        'sand_ai.model.read',
        'sand_ai.chat.complete',
        'sand_ai.invocation.read',
        'sand_ai.usage.read',
        'sand_ai.file.read',
        'sand_ai.file.write',
        'sand_ai.document_parse',
        'sand_ai.task.read',
        'sand_ai.task.cancel',
        'sand_ai.task.retry',
        'sand_ai.audio.transcription',
        'sand_ai.audio.review',
        'sand_ai.workflow.read',
    ];

    /** @var list<string> */
    public const DEFAULT_ACTIONS = [
        'sand_ai.model.read',
        'sand_ai.chat.complete',
        'sand_ai.invocation.read',
        'sand_ai.usage.read',
    ];

    /**
     * Intersect the request with the whitelist, preserving whitelist order.
     * An absent list takes the minimal default set; unknown actions are
     * dropped, and a request that names only non-trial actions is refused
     * rather than silently upgraded to the default set.
     *
     * @param mixed $requested raw `actions` value from the admin body
     * @return list<string>
     */
    public static function resolve(mixed $requested): array
    {
        if ($requested === null) {
            return self::DEFAULT_ACTIONS;
        }
        if (!is_array($requested) || !array_is_list($requested)) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', '试用能力列表必须是字符串数组');
        }
        $picked = [];
        foreach ($requested as $action) {
            if (!is_string($action) || $action === '') {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', '试用能力列表必须是字符串数组');
            }
            if (in_array($action, self::WHITELIST, true)) {
                $picked[$action] = true;
            }
        }
        $resolved = array_values(array_intersect(self::WHITELIST, array_keys($picked)));
        if ($resolved === []) {
            throw new ApiProblem('SAND_AI_TRIAL_ACTION_FORBIDDEN', '请求的试用能力不在允许范围内');
        }
        return $resolved;
    }

    /** @return list<string> every action the trial workload client is granted */
    public static function all(): array
    {
        return self::WHITELIST;
    }
}
