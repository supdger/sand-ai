<?php

declare(strict_types=1);

namespace app\Application\Onboarding;

use app\Api\Support\ApiProblem;
use app\Application\Budget\OfficialModelCapacity;

/** Deployment draft settings are specific to the model capability being verified. */
final class DeploymentDraftConfigurationPolicy
{
    /** @return array<string,mixed> */
    public function normalize(mixed $value): array
    {
        if (!is_array($value)) {
            throw $this->invalid();
        }
        if ($value === []) {
            return [];
        }
        if (array_key_exists('budget_capacity_attestation', $value)) {
            $registration = $value['budget_capacity_attestation'];
            if (!is_array($registration) || !is_string($registration['source_code'] ?? null)) {
                throw new ApiProblem('SAND_AI_BUDGET_CAPACITY_UNVERIFIED', 'Official capacity registration fields are invalid');
            }
            $source = OfficialModelCapacity::sources()[$registration['source_code']] ?? null;
            if (!is_array($source)) {
                throw new ApiProblem('SAND_AI_BUDGET_CAPACITY_UNVERIFIED', 'Official capacity source is not implemented');
            }
            // Shape/source validation only. Publication must separately pass
            // the actual remote model and capability to assertForModel().
            OfficialModelCapacity::resolve($registration, $source['models'][0], $source['capability_code']);
            unset($value['budget_capacity_attestation']);
            if ($source['capability_code'] === 'embedding') {
                if ($value !== []) throw $this->invalid();
                return ['budget_capacity_attestation' => $registration];
            }
            $config = $this->normalize($value);
            if (!isset($config['max_output_tokens']) || $config['max_output_tokens'] > $source['max_output_tokens']) throw $this->invalid();
            return $config + ['budget_capacity_attestation' => $registration];
        }
        if (array_diff(array_keys($value), ['verification_voice', 'speech_response_format']) === [] && array_key_exists('verification_voice', $value)) {
            $voice = $value['verification_voice'];
            if (!is_string($voice) || preg_match('/\A[a-zA-Z][a-zA-Z0-9_-]{0,63}\z/D', $voice) !== 1) {
                throw $this->invalid();
            }
            $speech = ['verification_voice' => $voice];
            if (array_key_exists('speech_response_format', $value)) {
                if (!is_string($value['speech_response_format'])) throw $this->invalid();
                $speech['speech_response_format'] = \app\Application\Audio\Speech\SpeechAudioFormat::validate($value['speech_response_format']);
            }
            return $speech;
        }
        if (array_diff(array_keys($value), ['max_output_tokens', 'input_token_ceiling', 'reasoning_effort', 'skill_output_strategy']) !== []
            || !is_int($value['max_output_tokens'] ?? null)
            || $value['max_output_tokens'] < 1 || $value['max_output_tokens'] > 131072) {
            throw $this->invalid();
        }
        $config = ['max_output_tokens' => $value['max_output_tokens']];
        if (array_key_exists('input_token_ceiling', $value)) {
            if (!is_int($value['input_token_ceiling']) || $value['input_token_ceiling'] < 1 || $value['input_token_ceiling'] > 131072) {
                throw $this->invalid();
            }
            $config['input_token_ceiling'] = $value['input_token_ceiling'];
        }
        if (array_key_exists('reasoning_effort', $value)) {
            if (!is_string($value['reasoning_effort'])
                || !in_array($value['reasoning_effort'], ['low', 'high', 'max'], true)) {
                throw $this->invalid();
            }
            $config['reasoning_effort'] = $value['reasoning_effort'];
        }
        if (array_key_exists('skill_output_strategy', $value)) {
            if (!is_string($value['skill_output_strategy']) || !in_array($value['skill_output_strategy'], [\app\Application\Skill\SkillOutputStrategy::EXTRACT, \app\Application\Skill\SkillOutputStrategy::ZAI, \app\Application\Skill\SkillOutputStrategy::OPENAI], true)) throw $this->invalid();
            $config['skill_output_strategy'] = $value['skill_output_strategy'];
        }
        return $config;
    }

    /** @param array<string,mixed> $config */
    public function assertForModel(array $config, string $modelType, ?string $remoteModel = null): void
    {
        if (array_key_exists('budget_capacity_attestation', $config)) {
            $registration = $config['budget_capacity_attestation'];
            if ($remoteModel === null || $remoteModel === '' || !is_array($registration)) {
                throw new ApiProblem('SAND_AI_BUDGET_CAPACITY_UNVERIFIED', 'Actual remote model is required before publishing an official capacity registration');
            }
            $source = OfficialModelCapacity::resolve($registration, $remoteModel, $modelType);
            if ($modelType === 'embedding' && array_keys($config) === ['budget_capacity_attestation']) return;
            if ($modelType !== 'chat' || !is_int($config['max_output_tokens'] ?? null)
                || $config['max_output_tokens'] < 1 || $config['max_output_tokens'] > $source['max_output_tokens']) throw $this->invalid();
        }
        if (($modelType === 'chat' && isset($config['max_output_tokens']))
            || ($modelType === 'audio_speech' && isset($config['verification_voice']))
            || (in_array($modelType, ['embedding', 'rerank', 'audio_transcription', 'image_ocr'], true) && $config === [])) {
            return;
        }
        throw new ApiProblem('SAND_AI_CAPABILITY_UNSUPPORTED', 'Deployment settings do not match the model type');
    }

    private function invalid(): ApiProblem
    {
        return new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Deployment config must match the model type');
    }
}
