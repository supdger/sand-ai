<?php
declare(strict_types=1);
namespace app\Application\Task;
use app\Api\Support\ApiProblem;
use app\Application\Audio\AudioTranscriptionResultPolicy;
use app\Application\File\OpaqueFileReference;
use app\Application\Gateway\RequestPayloadFingerprint;
/** Rebuilds a public audio Task result scalar-by-scalar; no opaque engine payload crosses this boundary. */
final class AudioTaskResultPresenter
{
    /** @param array<string,mixed> $result @return array<string,mixed> */
    public function present(array $result, string $expectedDriverCode = 'local_whisper_cpp', string $expectedDriverVersion = '1.0.0'): array
    {
        if (!is_array($result['schema']??null) || !RequestPayloadFingerprint::same($result['schema'],['code'=>'sand_ai.audio_transcription','version'=>'1.0.0'],[]) || !is_array($result['segments']??null) || !array_is_list($result['segments']??null) || !is_array($result['warnings']??null) || !is_array($result['usage']??null) || ($result['review_required']??null) !== true || !is_array($result['technical']??null)) $this->invalid();
        $raw=[]; $segments=[];
        foreach ($result['segments'] as $segment) { if(!is_array($segment)||!is_array($segment['source']??null)) $this->invalid(); $source=$this->source($segment['source'],$segment); $raw[]=['start_ms'=>$segment['start_ms']??null,'end_ms'=>$segment['end_ms']??null,'speaker'=>$segment['speaker']??null,'text'=>$segment['text']??null,'confidence'=>$segment['confidence']??null,'warnings'=>$segment['warnings']??null]; $segments[]=['start_ms'=>$segment['start_ms'],'end_ms'=>$segment['end_ms'],'speaker'=>$segment['speaker'],'text'=>$segment['text'],'confidence'=>$segment['confidence'],'warnings'=>array_values($segment['warnings']),'source'=>$source]; }
        $normalized=(new AudioTranscriptionResultPolicy())->normalize(['segments'=>$raw,'audio_seconds'=>$result['usage']['audio_seconds']??null,'warnings'=>$result['warnings']], false);
        if ($normalized['segments'] !== array_map(static fn(array $s):array=>array_diff_key($s,['source'=>true]),$segments) || $normalized['warnings'] !== $result['warnings'] || $normalized['audio_seconds'] !== ($result['usage']['audio_seconds']??null)) $this->invalid();
        if ($expectedDriverCode === 'glm_asr_audio') {
            $durationMs = \app\Application\Audio\External\GlmAsrAudioInputPolicy::durationMs($normalized['audio_seconds']);
            if (count($segments) !== 1 || $segments[0]['start_ms'] !== 0 || $segments[0]['end_ms'] !== $durationMs
                || $segments[0]['speaker'] !== null || !in_array($segments[0]['confidence'], [null, '0.0000'], true)
                || ($segments[0]['source']['locator']['granularity'] ?? null) !== 'input_range_only'
                || !in_array('timing_unavailable', $segments[0]['warnings'], true)
                || !in_array('confidence_unavailable', $segments[0]['warnings'], true)
                || !in_array('timing_unavailable', $normalized['warnings'], true)) $this->invalid();
        }
        $sources=[]; foreach($segments as $i=>$segment) foreach(['start_ms','end_ms','speaker','text','confidence','warnings'] as $field) $sources[]=['field_path'=>"/segments/{$i}/{$field}",'source'=>$segment['source']];
        if (!is_array($result['field_sources']??null) || !array_is_list($result['field_sources']) || !RequestPayloadFingerprint::same($result['field_sources'],$sources,[])) $this->invalid();
        if (preg_match('/\A[a-z][a-z0-9_-]{1,63}\z/D', $expectedDriverCode) !== 1 || preg_match('/\A[a-z0-9][a-z0-9._-]{0,63}\z/D', $expectedDriverVersion) !== 1) $this->invalid();
        $technical=['capability_code'=>'audio_transcription','capability_version'=>'audio_transcription@1.0.0','driver_code'=>$expectedDriverCode,'driver_version'=>$expectedDriverVersion]; if(!RequestPayloadFingerprint::same($result['technical'],$technical,[])) $this->invalid();
        return ['schema'=>['code'=>'sand_ai.audio_transcription','version'=>'1.0.0'],'segments'=>$segments,'field_sources'=>$sources,'warnings'=>array_values($normalized['warnings']),'usage'=>['audio_seconds'=>$normalized['audio_seconds']],'review_required'=>$result['review_required'],'technical'=>$technical];
    }
    /** @param array<string,mixed> $source @param array<string,mixed> $segment @return array<string,mixed> */
    private function source(array $source, array $segment): array
    {
        if (count($source) !== 3 || array_diff(array_keys($source), ['file_ref', 'content_sha256', 'locator']) !== []
            || !is_string($source['file_ref']) || !is_string($source['content_sha256'])
            || preg_match('/\A[a-f0-9]{64}\z/D', $source['content_sha256']) !== 1
            || !is_array($source['locator']) || !is_int($segment['start_ms'] ?? null) || !is_int($segment['end_ms'] ?? null)) $this->invalid();
        try {
            OpaqueFileReference::assert($source['file_ref']);
            $locator = \app\Application\Audio\AudioTimeRangeLocator::normalize($source['locator'], $segment['start_ms'], $segment['end_ms']);
        } catch (ApiProblem) { $this->invalid(); }
        return ['file_ref' => $source['file_ref'], 'content_sha256' => $source['content_sha256'], 'locator' => $locator];
    }
    private function invalid(): never { throw new ApiProblem('SAND_AI_AUDIO_TRANSCRIPTION_RESULT_INVALID','The stored audio task result is invalid'); }
}
