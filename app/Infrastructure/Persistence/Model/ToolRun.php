<?php

declare(strict_types=1);

namespace app\Infrastructure\Persistence\Model;

final class ToolRun extends SandAiModel
{
    protected $table = 'sand_ai_tool_run';
    protected $deleteTime = false;
    protected $json = ['identity_snapshot', 'execution_snapshot', 'citations', 'metering', 'error_detail', 'queued_authorization'];
    protected $jsonAssoc = true;

    /** Keep JSON object/array shape through PostgreSQL jsonb reads. */
    public function getInputAttr(mixed $value, array $data): array|\stdClass
    {
        return $this->objectPayload($value);
    }

    public function setInputAttr(array|\stdClass $value, array $data): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public function getResultAttr(mixed $value, array $data): array|\stdClass|null
    {
        return $value === null ? null : $this->objectPayload($value);
    }

    private function objectPayload(mixed $value): array|\stdClass
    {
        if (is_string($value)) {
            return \app\Application\Tool\StrictJsonObjectDecoder::decodeObjectValue($value);
        }
        if ((is_array($value) && !array_is_list($value)) || $value instanceof \stdClass) return $value;
        throw new \app\Api\Support\ApiProblem('SAND_AI_TOOL_RECEIPT_INVALID', 'External tool object payload is invalid');
    }
}
