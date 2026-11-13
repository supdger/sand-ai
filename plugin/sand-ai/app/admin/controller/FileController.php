<?php

declare(strict_types=1);

namespace plugin\SandAi\app\admin\controller;

use app\Api\Support\ApiProblem;
use app\Application\File\FileGateway;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

final class FileController extends SandAiAdminController
{
    #[Permission('SandAI 文件列表', 'sand_ai:file:index')]
    public function index(Request $request): Response
    {
        return $this->guard(function () use ($request): array {
            $query = $request->get();
            if (!is_array($query)
                || array_diff(array_keys($query), ['environment_id', 'page', 'limit', 'keyword', 'state']) !== []
                || $request->rawBody() !== '') {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'File list request is invalid');
            }
            $keyword = $query['keyword'] ?? null;
            if ($keyword !== null && !is_string($keyword)) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'File list request is invalid');
            }
            return (new FileGateway())->index(
                $this->queryInt($query['environment_id'] ?? null),
                $this->queryInt($query['page'] ?? 1),
                $this->queryInt($query['limit'] ?? 20),
                $keyword === null ? null : trim($keyword),
                $this->stateFilter($query['state'] ?? null),
            );
        }, '文件列表已加载');
    }

    /** @return list<string>|null */
    private function stateFilter(mixed $raw): ?array
    {
        if ($raw === null) {
            return null;
        }
        $candidates = is_string($raw) ? [$raw] : (is_array($raw) ? $raw : null);
        if ($candidates === null || !array_is_list($candidates)) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'File list request is invalid');
        }
        $states = [];
        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || !in_array($candidate, FileGateway::LIST_STATES, true)) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'File list request is invalid');
            }
            $states[$candidate] = true;
        }
        return array_keys($states);
    }

    /** @param callable():array<string,mixed> $callback */
    private function guard(callable $callback, string $message): Response
    {
        try {
            return $this->success($callback(), $message);
        } catch (ApiProblem $problem) {
            throw $this->adminProblemException($problem, 400);
        }
    }

    private function queryInt(mixed $value): int
    {
        $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($parsed === false) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'File list request is invalid');
        }
        return (int) $parsed;
    }
}
