<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use app\Api\Support\ApiProblem;

final class MaterialFileResultPager
{
    private const SECTIONS = ['documents', 'entities', 'events', 'conflicts', 'items_to_verify', 'warnings'];
    private const MAX_SAFE_INTEGER = 9007199254740991;

    /**
     * @param array{file_id:int,attempt_count:int,result:array{schema:array{code:string,version:string},payload:array<string,mixed>},citations:list<array{field_path:string,source_block_id:int,file_id:int,locator_type:string,locator:array<string,mixed>}>} $fileResult
     * @return array<string,mixed>
     */
    public function summary(array $fileResult): array
    {
        $payload = $fileResult['result']['payload'];
        $counts = [];
        foreach (self::SECTIONS as $section) {
            $counts[$section] = count($this->collection($payload, $section, null));
        }
        $extensionCounts = [];
        foreach ($this->extensions($payload) as $field => $items) {
            $extensionCounts[$field] = count($this->collection($payload, 'extensions', (string) $field));
        }
        $counts['extensions'] = $extensionCounts;

        return [
            'file_id' => $fileResult['file_id'],
            'attempt_count' => $fileResult['attempt_count'],
            'paged' => true,
            'collection_counts' => $counts,
            'result' => ['schema' => $fileResult['result']['schema'], 'payload' => null],
            'citations' => [],
        ];
    }

    /**
     * @param array{file_id:int,attempt_count:int,result:array{schema:array{code:string,version:string},payload:array<string,mixed>},citations:list<array{field_path:string,source_block_id:int,file_id:int,locator_type:string,locator:array<string,mixed>}>} $fileResult
     * @return array{file_id:int,schema:array{code:string,version:string},section:string,extension_field:string|null,offset:int,limit:int,total:int,items:list<mixed>,citations:list<array{field_path:string,source_block_id:int,file_id:int,locator_type:string,locator:array<string,mixed>}>}
     */
    public function page(array $fileResult, string $section, ?string $extensionField, int $offset, int $limit): array
    {
        if ((!in_array($section, self::SECTIONS, true) && $section !== 'extensions')
            || ($section !== 'extensions' && $extensionField !== null)
            || $offset < 0 || $offset > self::MAX_SAFE_INTEGER || $limit < 1 || $limit > 100) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'A valid material result section, offset and limit are required');
        }
        $collection = $this->collection($fileResult['result']['payload'], $section, $extensionField);
        $items = array_slice($collection, $offset, $limit);
        $citations = [];
        if ($section !== 'warnings' && $items !== []) {
            $collectionPath = $section === 'extensions'
                ? '/extensions/' . str_replace(['~', '/'], ['~0', '~1'], (string) $extensionField)
                : '/' . $section;
            $paths = [];
            foreach ($items as $index => $item) {
                $paths[$collectionPath . '/' . ($offset + $index)] = true;
            }
            foreach ($fileResult['citations'] as $citation) {
                $fieldPath = $citation['field_path'];
                foreach ($paths as $path => $included) {
                    if ($fieldPath === $path || str_starts_with($fieldPath, $path . '/')) {
                        $citations[] = [
                            'field_path' => $fieldPath,
                            'source_block_id' => $citation['source_block_id'],
                            'file_id' => $citation['file_id'],
                            'locator_type' => $citation['locator_type'],
                            'locator' => $citation['locator'],
                        ];
                        break;
                    }
                }
            }
        }

        return [
            'file_id' => $fileResult['file_id'],
            'schema' => $fileResult['result']['schema'],
            'section' => $section,
            'extension_field' => $extensionField,
            'offset' => $offset,
            'limit' => $limit,
            'total' => count($collection),
            'items' => $items,
            'citations' => $citations,
        ];
    }

    /** @param array<string,mixed> $payload @return list<mixed> */
    private function collection(array $payload, string $section, ?string $extensionField): array
    {
        if ($section === 'extensions') {
            $extensions = $this->extensions($payload);
            if ($extensionField === null || $extensionField === '' || !property_exists($extensions, $extensionField)) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'An actual material result extension field is required');
            }
            $items = $extensions->{$extensionField};
        } else {
            $items = $payload[$section] ?? null;
        }
        if (!is_array($items) || !array_is_list($items)) {
            throw new ApiProblem('SAND_AI_ARTIFACT_PROVENANCE_INVALID', 'A sealed material result collection is invalid');
        }
        return $items;
    }

    /** @param array<string,mixed> $payload */
    private function extensions(array $payload): \stdClass
    {
        $extensions = $payload['extensions'] ?? null;
        if (!$extensions instanceof \stdClass) {
            throw new ApiProblem('SAND_AI_ARTIFACT_PROVENANCE_INVALID', 'A sealed material result extension collection is invalid');
        }
        return $extensions;
    }
}
