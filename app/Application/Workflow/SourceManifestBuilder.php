<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use app\Api\Support\ApiProblem;

final class SourceManifestBuilder
{
    /**
     * @param list<array{file_id:int,sha256:string,version_ref:string,blocks:list<array{id:int,locator_type:string,locator:array,content:string,content_sha256:string}>}> $files
     * @return array{payload:array<string, mixed>, field_sources:list<array{field_path:string,source_block_id:int}>}
     */
    public function build(array $files): array
    {
        $payloadFiles = [];
        $fieldSources = [];
        foreach ($files as $fileIndex => $file) {
            $blocks = [];
            foreach ($file['blocks'] as $blockIndex => $block) {
                $excerpt = mb_substr(trim($block['content']), 0, 500, 'UTF-8');
                if ($excerpt === '') {
                    continue;
                }
                $blocks[] = [
                    'source_block_id' => $block['id'],
                    'locator_type' => $block['locator_type'],
                    'locator' => $block['locator'],
                    'content_sha256' => $block['content_sha256'],
                    'excerpt' => $excerpt,
                ];
                $fieldSources[] = [
                    'field_path' => sprintf('/files/%d/source_blocks/%d/excerpt', $fileIndex, count($blocks) - 1),
                    'source_block_id' => $block['id'],
                ];
            }
            $payloadFiles[] = [
                'file_id' => $file['file_id'],
                'sha256' => $file['sha256'],
                'version_ref' => $file['version_ref'],
                'source_blocks' => $blocks,
            ];
        }
        if ($fieldSources === []) {
            throw new ApiProblem('SAND_AI_ARTIFACT_PROVENANCE_INVALID', 'A source manifest requires at least one source excerpt');
        }

        return [
            'payload' => [
                'schema' => ['code' => 'sand_ai.source_manifest', 'version' => '1.0.0'],
                'files' => $payloadFiles,
            ],
            'field_sources' => $fieldSources,
        ];
    }
}
