<?php

declare(strict_types=1);

namespace app\Infrastructure\Billing;

use app\Api\Support\ApiProblem;
use app\Application\Billing\BillingAdminCredentialProvider;
use app\Application\Billing\ProviderBillingConnector;

final class OpenAiOrganizationCostsConnector implements ProviderBillingConnector
{
    private const MAX_PAGES = 200;
    private const MAX_LINES = 500;
    private const MAX_SYNC_RAW_BYTES = 1048576;

    public function __construct(
        private BillingAdminCredentialProvider $credentials,
        private BillingHttpTransport $transport,
        private ?LosslessJsonDecoder $decoder = null,
        private ?OpenAiCostDecimal $decimal = null,
    ) {
        $this->decoder ??= new LosslessJsonDecoder();
        $this->decimal ??= new OpenAiCostDecimal();
    }

    public function providerCode(): string
    {
        return 'openai';
    }

    public function fetch(array $request): array
    {
        $providerId = (int) $request['provider_id'];
        $secret = $this->credentials->secretFor($providerId, 'billing_admin');
        $organization = $this->credentials->organizationFor($providerId);
        $page = null;
        $seenPages = [];
        $seenLineIds = [];
        $lines = [];
        $rawBytes = 0;
        $allPagesVerified = true;
        for ($pageNumber = 0; $pageNumber < self::MAX_PAGES; $pageNumber++) {
            $query = [
                'start_time' => $request['start_unix'] ?? strtotime($request['period_start']),
                'end_time' => $request['end_unix'] ?? strtotime($request['period_end']),
                'bucket_width' => '1d',
                'limit' => 180,
                'group_by' => ['project_id', 'line_item'],
            ];
            if ($page !== null) {
                $query['page'] = $page;
            }
            $response = $this->request($query, $secret, $organization);
            if (($response['organization'] ?? null) !== $organization) {
                $allPagesVerified = false;
            }
            $rawBytes += strlen($response['body']);
            if ($rawBytes > self::MAX_SYNC_RAW_BYTES) {
                throw new ApiProblem('SAND_AI_BILLING_RESPONSE_TOO_LARGE', 'OpenAI billing response too large');
            }
            $body = $this->body($response);
            foreach ($body['data'] as $bucket) {
                foreach ($this->bucketLines($providerId, $bucket) as $line) {
                    $lineId = $line['external_line_id'];
                    if (isset($seenLineIds[$lineId])) {
                        throw new ApiProblem('SAND_AI_BILLING_INVALID_RESPONSE', 'OpenAI Costs response contains a duplicate billing tuple');
                    }
                    $seenLineIds[$lineId] = true;
                    $lines[] = $line;
                    if (count($lines) > self::MAX_LINES) {
                        throw new ApiProblem('SAND_AI_BILLING_IMPORT_TOO_LARGE', 'OpenAI Costs result exceeds 500 lines; shorten period and retry');
                    }
                }
            }
            if (!$body['has_more']) {
                foreach ($lines as &$line) {
                    $line['_fact_account_scope'] = $allPagesVerified ? $organization : null;
                }
                unset($line);
                usort($lines, static fn (array $left, array $right): int => strcmp($left['external_line_id'], $right['external_line_id']));

                return $lines;
            }
            $nextPage = $this->cursor($body['next_page'] ?? null);
            if (isset($seenPages[$nextPage])) {
                throw new ApiProblem('SAND_AI_BILLING_PAGE_INCOMPLETE', 'OpenAI Costs pagination incomplete');
            }
            $seenPages[$nextPage] = true;
            $page = $nextPage;
        }

        throw new ApiProblem('SAND_AI_BILLING_PAGE_INCOMPLETE', 'OpenAI Costs page limit exceeded');
    }

    private function cursor(mixed $value): string
    {
        if (!is_string($value) || preg_match('/\A[\x21-\x7E]{1,512}\z/D', $value) !== 1) {
            throw new ApiProblem('SAND_AI_BILLING_PAGE_INCOMPLETE', 'OpenAI Costs pagination incomplete');
        }

        return $value;
    }

    /** @return array{status:int,body:string,organization?:string|null} */
    private function request(array $query, string $secret, string $organization): array
    {
        try {
            $response = $this->transport->get('/v1/organization/costs', $query, $secret, $organization);
        } catch (ApiProblem $problem) {
            throw $problem;
        } catch (\Throwable) {
            throw new ApiProblem('SAND_AI_BILLING_UNAVAILABLE', 'OpenAI billing unavailable');
        }
        $status = (int) ($response['status'] ?? 0);
        if ($status === 401 || $status === 403) {
            throw new ApiProblem('SAND_AI_BILLING_AUTH_FAILED', 'OpenAI billing authorization failed');
        }
        if ($status === 429) {
            throw new ApiProblem('SAND_AI_BILLING_RATE_LIMITED', 'OpenAI billing rate limited');
        }
        if ($status >= 500 || $status === 408 || $status === 0) {
            throw new ApiProblem('SAND_AI_BILLING_UNAVAILABLE', 'OpenAI billing unavailable');
        }
        if ($status !== 200) {
            throw new ApiProblem('SAND_AI_BILLING_REQUEST_INVALID', 'OpenAI billing request rejected');
        }

        return ['status' => $status, 'body' => (string) ($response['body'] ?? ''), 'organization' => $response['organization'] ?? null];
    }

    /** @return array{data:list<array<string,mixed>>,has_more:bool,next_page?:mixed} */
    private function body(array $response): array
    {
        $body = $this->decoder->decodeTyped($response['body']);
        if (!is_array($body)
            || ($body['object'] ?? null) !== 'page'
            || !is_array($body['data'] ?? null)
            || !array_is_list($body['data'])
            || !is_bool($body['has_more'] ?? null)) {
            throw new ApiProblem('SAND_AI_BILLING_INVALID_RESPONSE', 'OpenAI Costs response invalid');
        }

        return $body;
    }

    /** @return list<array<string,mixed>> */
    private function bucketLines(int $providerId, mixed $bucket): array
    {
        if (!is_array($bucket)) {
            throw new ApiProblem('SAND_AI_BILLING_INVALID_RESPONSE', 'OpenAI Costs bucket invalid');
        }
        $start = $this->unix($bucket['start_time'] ?? null);
        $end = $this->unix($bucket['end_time'] ?? null);
        $results = $bucket['results'] ?? null;
        if ($start === null || $end === null || (int) $end < (int) $start || !is_array($results) || !array_is_list($results)) {
            throw new ApiProblem('SAND_AI_BILLING_INVALID_RESPONSE', 'OpenAI Costs bucket invalid');
        }

        $lines = [];
        foreach ($results as $result) {
            if (!is_array($result)) {
                throw new ApiProblem('SAND_AI_BILLING_INVALID_RESPONSE', 'OpenAI Costs result invalid');
            }
            $amountNode = $result['amount'] ?? null;
            if (!is_array($amountNode)
                || !($amountNode['value'] ?? null) instanceof LosslessJsonNumber
                || !is_string($amountNode['currency'] ?? null)
                || preg_match('/^[A-Za-z]{3}$/', $amountNode['currency']) !== 1) {
                throw new ApiProblem('SAND_AI_BILLING_INVALID_RESPONSE', 'OpenAI Costs amount invalid');
            }
            $amount = $this->decimal->normalize($amountNode['value']->value);
            $currency = strtoupper($amountNode['currency']);
            $externalLineId = $this->externalLineId($start, $end, $result, $currency);
            $lines[] = [
                'external_line_id' => $externalLineId,
                'model_code' => 'provider_cost',
                'capability_code' => 'provider_billing',
                'meter_code' => 'cost_line',
                'quantity' => '1',
                'amount' => $amount,
                'currency' => $currency,
                'occurred_start' => gmdate('Y-m-d H:i:s', (int) $start),
                'occurred_end' => gmdate('Y-m-d H:i:s', (int) $end),
            ];
        }

        return $lines;
    }

    private function unix(mixed $value): ?string
    {
        if (!$value instanceof LosslessJsonNumber || preg_match('/^[0-9]{1,12}$/', $value->value) !== 1) {
            return null;
        }

        return $value->value;
    }

    private function externalLineId(string $start, string $end, array $result, string $currency): string
    {
        $tuple = [
            'start_time' => $start,
            'end_time' => $end,
            'project_id_hash' => $this->opaqueHash($result, 'project_id'),
            'line_item_hash' => $this->opaqueHash($result, 'line_item'),
            'api_key_id_hash' => $this->opaqueHash($result, 'api_key_id'),
            'currency' => $currency,
        ];

        return hash('sha256', json_encode($tuple, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function opaqueHash(array $result, string $field): string
    {
        $value = $result[$field] ?? null;
        if ($value !== null && (!is_string($value) || strlen($value) > 512 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1)) {
            throw new ApiProblem('SAND_AI_BILLING_INVALID_RESPONSE', 'OpenAI Costs grouping field invalid');
        }

        return hash('sha256', $value === null ? 'null' : 'string:' . $value);
    }
}
