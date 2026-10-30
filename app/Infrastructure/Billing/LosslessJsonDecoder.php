<?php

declare(strict_types=1);

namespace app\Infrastructure\Billing;

use app\Api\Support\ApiProblem;

final class LosslessJsonDecoder
{
    public const MAX_BYTES = 1048576;

    public function decode(string $json): mixed
    {
        return $this->numbersAsStrings($this->decodeTyped($json));
    }

    public function decodeTyped(string $json): mixed
    {
        if (strlen($json) > self::MAX_BYTES) {
            throw new ApiProblem('SAND_AI_BILLING_RESPONSE_TOO_LARGE', 'OpenAI billing response too large');
        }
        $marker = '__sand_ai_json_number_' . bin2hex(random_bytes(12));
        try {
            $decoded = json_decode($this->tokenize($json, $marker), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiProblem('SAND_AI_BILLING_INVALID_RESPONSE', 'OpenAI Costs response invalid');
        }

        return $this->restoreNumbers($decoded, $marker);
    }

    private function tokenize(string $json, string $marker): string
    {
        $length = strlen($json);
        $result = '';
        for ($index = 0; $index < $length;) {
            $character = $json[$index];
            if ($character === '"') {
                $start = $index++;
                $escaped = false;
                $closed = false;
                while ($index < $length) {
                    $current = $json[$index++];
                    if ($escaped) {
                        $escaped = false;
                        continue;
                    }
                    if ($current === '\\') {
                        $escaped = true;
                        continue;
                    }
                    if ($current === '"') {
                        $closed = true;
                        break;
                    }
                }
                if (!$closed) {
                    throw new ApiProblem('SAND_AI_BILLING_INVALID_RESPONSE', 'OpenAI Costs response invalid');
                }
                $result .= substr($json, $start, $index - $start);
                continue;
            }
            if ($character === '-' || ($character >= '0' && $character <= '9')) {
                [$number, $index] = $this->numberToken($json, $index);
                $result .= '{"' . $marker . '":' . json_encode($number, JSON_THROW_ON_ERROR) . '}';
                continue;
            }
            $result .= $character;
            $index++;
        }

        return $result;
    }

    /** @return array{0:string,1:int} */
    private function numberToken(string $json, int $start): array
    {
        $length = strlen($json);
        $index = $start;
        if ($json[$index] === '-') {
            $index++;
            if ($index >= $length) {
                throw new ApiProblem('SAND_AI_BILLING_INVALID_RESPONSE', 'OpenAI Costs response invalid');
            }
        }
        if ($json[$index] === '0') {
            $index++;
        } elseif ($json[$index] >= '1' && $json[$index] <= '9') {
            do {
                $index++;
            } while ($index < $length && $json[$index] >= '0' && $json[$index] <= '9');
        } else {
            throw new ApiProblem('SAND_AI_BILLING_INVALID_RESPONSE', 'OpenAI Costs response invalid');
        }
        if ($index < $length && $json[$index] === '.') {
            $index++;
            $fractionStart = $index;
            while ($index < $length && $json[$index] >= '0' && $json[$index] <= '9') {
                $index++;
            }
            if ($index === $fractionStart) {
                throw new ApiProblem('SAND_AI_BILLING_INVALID_RESPONSE', 'OpenAI Costs response invalid');
            }
        }
        if ($index < $length && ($json[$index] === 'e' || $json[$index] === 'E')) {
            $index++;
            if ($index < $length && ($json[$index] === '+' || $json[$index] === '-')) {
                $index++;
            }
            $exponentStart = $index;
            while ($index < $length && $json[$index] >= '0' && $json[$index] <= '9') {
                $index++;
            }
            if ($index === $exponentStart) {
                throw new ApiProblem('SAND_AI_BILLING_INVALID_RESPONSE', 'OpenAI Costs response invalid');
            }
        }

        return [substr($json, $start, $index - $start), $index];
    }

    private function restoreNumbers(mixed $value, string $marker): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (count($value) === 1 && array_key_exists($marker, $value) && is_string($value[$marker])) {
            return new LosslessJsonNumber($value[$marker]);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->restoreNumbers($item, $marker);
        }

        return $value;
    }

    private function numbersAsStrings(mixed $value): mixed
    {
        if ($value instanceof LosslessJsonNumber) {
            return $value->value;
        }
        if (!is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->numbersAsStrings($item);
        }

        return $value;
    }
}
