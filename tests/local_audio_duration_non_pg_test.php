<?php

declare(strict_types=1);

$root = getenv('SANDPACKAGE_TEST_ROOT') ?: dirname(__DIR__);
$baseRoot = getenv('SANDPACKAGE_BASE_ROOT') ?: $root;
foreach ([
    'app/Api/Support/ApiProblem.php' => \app\Api\Support\ApiProblem::class,
    'app/Application/Audio/LocalAudioTranscriptionDriver.php' => \app\Application\Audio\LocalAudioTranscriptionDriver::class,
    'app/Application/Audio/VerifiedPrivateAudioReference.php' => \app\Application\Audio\VerifiedPrivateAudioReference::class,
    'app/Application/Audio/ControlledAudioInput.php' => \app\Application\Audio\ControlledAudioInput::class,
    'app/Application/Audio/AudioTranscriptionResultPolicy.php' => \app\Application\Audio\AudioTranscriptionResultPolicy::class,
    'app/Application/Audio/LocalAudioDurationProbe.php' => \app\Application\Audio\LocalAudioDurationProbe::class,
    'app/Application/Audio/LocalAudioWavConverter.php' => \app\Application\Audio\LocalAudioWavConverter::class,
    'app/Application/Audio/WhisperCppExecutionConfig.php' => \app\Application\Audio\WhisperCppExecutionConfig::class,
    'app/Application/Audio/WhisperCppAudioTranscriptionDriver.php' => \app\Application\Audio\WhisperCppAudioTranscriptionDriver::class,
] as $file => $class) {
    if (!class_exists($class) && !interface_exists($class)) {
        require is_file($root . '/' . $file) ? $root . '/' . $file : $baseRoot . '/' . $file;
    }
}

use app\Api\Support\ApiProblem;
use app\Application\Audio\AudioTranscriptionResultPolicy;
use app\Application\Audio\ControlledAudioInput;
use app\Application\Audio\VerifiedPrivateAudioReference;
use app\Application\Audio\WhisperCppAudioTranscriptionDriver;
use app\Application\Audio\WhisperCppExecutionConfig;

$probe = tempnam(sys_get_temp_dir(), 'sand-ai-probe-');
$converter = tempnam(sys_get_temp_dir(), 'sand-ai-converter-');
$whisper = tempnam(sys_get_temp_dir(), 'sand-ai-whisper-');
$model = tempnam(sys_get_temp_dir(), 'sand-ai-model-');
$audio = tempnam(sys_get_temp_dir(), 'sand-ai-audio-');
$marker = tempnam(sys_get_temp_dir(), 'sand-ai-whisper-count-');
$wavPathMarker = tempnam(sys_get_temp_dir(), 'sand-ai-wav-path-');
if (!is_string($probe) || !is_string($converter) || !is_string($whisper) || !is_string($model)
    || !is_string($audio) || !is_string($marker) || !is_string($wavPathMarker)) {
    throw new RuntimeException('Could not prepare local audio fixtures');
}
file_put_contents($probe, '#!' . PHP_BINARY . "\n<?php\n"
    . "if (getenv('SAND_FAKE_PROBE_MODE') === 'bad') { echo \"N/A\\n\"; exit; }\n"
    . "if (getenv('SAND_FAKE_PROBE_MODE') === 'timeout') { sleep(2); }\n"
    . "echo \"2.000400\\n\";\n");
file_put_contents($converter, '#!' . PHP_BINARY . "\n<?php\n"
    . '$args = $argv; $input = array_search("-i", $args, true);'
    . ' if ($input === false || ($args[$input + 1] ?? "") !== getenv("SAND_FAKE_ORIGINAL_AUDIO")'
    . ' || !in_array("16000", $args, true) || !in_array("pcm_s16le", $args, true)) exit(2);'
    . ' $output = end($args); file_put_contents(getenv("SAND_FAKE_WAV_PATH"), $output);'
    . ' if (getenv("SAND_FAKE_CONVERTER_MODE") === "bad") exit(3);'
    . ' if (getenv("SAND_FAKE_CONVERTER_MODE") === "timeout") sleep(2);'
    . ' if (getenv("SAND_FAKE_CONVERTER_MODE") === "invalid_header") { file_put_contents($output, "bad"); exit; }'
    . ' $header = "RIFF" . str_repeat("\\0", 4) . "WAVE";'
    . ' file_put_contents($output, $header . str_repeat("\\0", 40));'
    . "\n");
file_put_contents($whisper, '#!' . PHP_BINARY . "\n<?php\n"
    . '$args = $argv; $index = array_search("-of", $args, true);'
    . ' if ($index === false || !isset($args[$index + 1])) exit(2);'
    . ' $input = array_search("-f", $args, true); $wav = $args[$input + 1] ?? "";'
    . ' if ($wav === getenv("SAND_FAKE_ORIGINAL_AUDIO") || !str_ends_with($wav, ".wav")'
    . ' || (fileperms(dirname($wav)) & 0777) !== 0700'
    . ' || substr((string) file_get_contents($wav), 0, 4) !== "RIFF") exit(3);'
    . ' $language = array_search("-l", $args, true);'
    . ' if ($language === false || ($args[$language + 1] ?? "") !== getenv("SAND_FAKE_LANGUAGE")) exit(4);'
    . ' $prompt = array_search("--prompt", $args, true); $expected = getenv("SAND_FAKE_GLOSSARY");'
    . ' if (($expected === "" && $prompt !== false)'
    . ' || ($expected !== "" && ($prompt === false || ($args[$prompt + 1] ?? "") !== $expected))) exit(5);'
    . ' file_put_contents(getenv("SAND_FAKE_WHISPER_MARKER"), "x", FILE_APPEND);'
    . ' $mode = getenv("SAND_FAKE_WHISPER_MODE");'
    . ' $items = $mode === "empty" ? [] : ($mode === "invalid" ? [["text" => "无定位"]]'
    . ' : ($mode === "gaps" ? [["text" => "第一段", "offsets" => ["from" => 200, "to" => 900]],'
    . ' ["text" => "第二段", "offsets" => ["from" => 1300, "to" => 1500]]]'
    . ' : [["text" => "会议开始", "offsets" => ["from" => 0, "to" => 1500]]]));'
    . ' file_put_contents($args[$index + 1] . ".json", json_encode(["transcription" => $items]));'
    . "\n");
chmod($probe, 0700);
chmod($converter, 0700);
chmod($whisper, 0700);
putenv('SAND_FAKE_WHISPER_MARKER=' . $marker);
putenv('SAND_FAKE_ORIGINAL_AUDIO=' . $audio);
putenv('SAND_FAKE_WAV_PATH=' . $wavPathMarker);
putenv('SAND_FAKE_LANGUAGE=zh');
putenv('SAND_FAKE_GLOSSARY=杭州商事法庭, 澄海公司');
$reference = new VerifiedPrivateAudioReference('saf_1234567890123456', 'audio/mpeg', str_repeat('a', 64));
$input = new ControlledAudioInput($audio, $reference);
$driver = new WhisperCppAudioTranscriptionDriver(new WhisperCppExecutionConfig($whisper, $model, 1, $probe, $converter));
$request = ['language' => 'zh-Hans-CN', 'speaker_diarization' => false,
    'glossary' => ['杭州商事法庭', '澄海公司']];
try {
    $result = (new AudioTranscriptionResultPolicy())->normalize($driver->transcribeLocal($input, $request), false);
    if ($result['audio_seconds'] !== '2.001' || $result['segments'][0]['end_ms'] !== 1500
        || count($result['segments']) !== 2 || $result['segments'][1]['start_ms'] !== 1500
        || $result['segments'][1]['end_ms'] !== 2001
        || !in_array('uncovered_audio_range', $result['segments'][1]['warnings'], true)) {
        throw new RuntimeException('Trailing audio was omitted from local ASR metering');
    }
    putenv('SAND_FAKE_WHISPER_MODE=gaps');
    $gaps = (new AudioTranscriptionResultPolicy())->normalize($driver->transcribeLocal($input, $request), false);
    $expectedRanges = [[0, 200], [200, 900], [900, 1300], [1300, 1500], [1500, 2001]];
    foreach ($expectedRanges as $index => [$start, $end]) {
        if (($gaps['segments'][$index]['start_ms'] ?? null) !== $start
            || ($gaps['segments'][$index]['end_ms'] ?? null) !== $end
            || (($index % 2 === 0) !== in_array('uncovered_audio_range', $gaps['segments'][$index]['warnings'] ?? [], true))) {
            throw new RuntimeException('An unrecognized local audio range cannot be located for review');
        }
    }
    putenv('SAND_FAKE_WHISPER_MODE');
    if (is_file((string) file_get_contents($wavPathMarker))
        || is_dir(dirname((string) file_get_contents($wavPathMarker)))) {
        throw new RuntimeException('Converted private audio was not removed after transcription');
    }
    $withoutGlossary = $request;
    $withoutGlossary['glossary'] = [];
    putenv('SAND_FAKE_GLOSSARY=');
    $plain = $driver->transcribeLocal($input, $withoutGlossary);
    if ($plain['segments'][0]['text'] !== '会议开始') {
        throw new RuntimeException('A request without glossary did not transcribe');
    }
    putenv('SAND_FAKE_GLOSSARY=杭州商事法庭, 澄海公司');
    $calledBeforeInvalidHints = filesize($marker);
    $invalidHints = $request;
    $invalidHints['glossary'] = ['重复', '重复'];
    try {
        $driver->transcribeLocal($input, $invalidHints);
        throw new RuntimeException('Duplicate local glossary entries were accepted');
    } catch (ApiProblem $problem) {
        if ($problem->errorCode !== 'SAND_AI_AUDIO_TRANSCRIPTION_VALIDATION_ERROR'
            || filesize($marker) !== $calledBeforeInvalidHints) {
            throw $problem;
        }
    }
    putenv('SAND_FAKE_WHISPER_MODE=empty');
    $empty = (new AudioTranscriptionResultPolicy())->normalize($driver->transcribeLocal($input, $request), false);
    if ($empty['audio_seconds'] !== '2.001' || $empty['segments'][0]['end_ms'] !== 2001
        || $empty['segments'][0]['warnings'][1] !== 'no_recognized_speech') {
        throw new RuntimeException('Speech-free audio was not retained for review and metering');
    }
    putenv('SAND_FAKE_WHISPER_MODE=invalid');
    try {
        $driver->transcribeLocal($input, $request);
        throw new RuntimeException('Malformed local ASR output was silently omitted');
    } catch (ApiProblem $problem) {
        if ($problem->errorCode !== 'SAND_AI_AUDIO_TRANSCRIPTION_RESULT_INVALID') {
            throw $problem;
        }
    }
    $called = filesize($marker);
    putenv('SAND_FAKE_PROBE_MODE=bad');
    try {
        $driver->transcribeLocal($input, $request);
        throw new RuntimeException('Unverifiable duration was accepted');
    } catch (ApiProblem $problem) {
        if ($problem->errorCode !== 'SAND_AI_AUDIO_UNAVAILABLE' || filesize($marker) !== $called) {
            throw $problem;
        }
    }
    putenv('SAND_FAKE_PROBE_MODE');
    putenv('SAND_FAKE_CONVERTER_MODE=bad');
    try {
        $driver->transcribeLocal($input, $request);
        throw new RuntimeException('Failed audio conversion was accepted');
    } catch (ApiProblem $problem) {
        if ($problem->errorCode !== 'SAND_AI_AUDIO_UNAVAILABLE' || filesize($marker) !== $called
            || is_file((string) file_get_contents($wavPathMarker))
            || is_dir(dirname((string) file_get_contents($wavPathMarker)))) {
            throw $problem;
        }
    }
    putenv('SAND_FAKE_CONVERTER_MODE=invalid_header');
    try {
        $driver->transcribeLocal($input, $request);
        throw new RuntimeException('Invalid WAV conversion output was accepted');
    } catch (ApiProblem $problem) {
        if ($problem->errorCode !== 'SAND_AI_AUDIO_UNAVAILABLE' || filesize($marker) !== $called
            || is_file((string) file_get_contents($wavPathMarker))
            || is_dir(dirname((string) file_get_contents($wavPathMarker)))) {
            throw $problem;
        }
    }
    putenv('SAND_FAKE_CONVERTER_MODE=timeout');
    $started = microtime(true);
    try {
        $driver->transcribeLocal($input, $request);
        throw new RuntimeException('Audio conversion timeout was ignored');
    } catch (ApiProblem $problem) {
        if ($problem->errorCode !== 'SAND_AI_AUDIO_UNAVAILABLE'
            || !$problem->retryable || microtime(true) - $started > 3
            || is_file((string) file_get_contents($wavPathMarker))
            || is_dir(dirname((string) file_get_contents($wavPathMarker)))) {
            throw $problem;
        }
    }
    putenv('SAND_FAKE_CONVERTER_MODE');
    putenv('SAND_FAKE_PROBE_MODE=timeout');
    $started = microtime(true);
    try {
        $driver->transcribeLocal($input, $request);
        throw new RuntimeException('Duration probe timeout was ignored');
    } catch (ApiProblem $problem) {
        if ($problem->errorCode !== 'SAND_AI_AUDIO_UNAVAILABLE'
            || !$problem->retryable || microtime(true) - $started > 3) {
            throw $problem;
        }
    }
    try {
        new WhisperCppExecutionConfig($whisper, $model, 1, '', $converter);
        throw new RuntimeException('Missing duration probe was accepted as configured');
    } catch (ApiProblem $problem) {
        if ($problem->errorCode !== 'SAND_AI_AUDIO_NOT_CONFIGURED') {
            throw $problem;
        }
    }
    try {
        new WhisperCppExecutionConfig($whisper, $model, 1, $probe, '');
        throw new RuntimeException('Missing audio converter was accepted as configured');
    } catch (ApiProblem $problem) {
        if ($problem->errorCode !== 'SAND_AI_AUDIO_NOT_CONFIGURED') {
            throw $problem;
        }
    }
} finally {
    putenv('SAND_FAKE_WHISPER_MARKER');
    putenv('SAND_FAKE_WHISPER_MODE');
    putenv('SAND_FAKE_PROBE_MODE');
    putenv('SAND_FAKE_CONVERTER_MODE');
    putenv('SAND_FAKE_ORIGINAL_AUDIO');
    putenv('SAND_FAKE_WAV_PATH');
    putenv('SAND_FAKE_LANGUAGE');
    putenv('SAND_FAKE_GLOSSARY');
    foreach ([$probe, $converter, $whisper, $model, $audio, $marker, $wavPathMarker] as $path) {
        @unlink($path);
    }
}

echo 'local-audio-duration 14/14' . PHP_EOL;
