<?php

declare(strict_types=1);

namespace app\Application\Audio\External;

use app\Application\Audio\PostgresPrivateAudioFileLoader;

/** Default production composition. Configuration remains disabled unless explicitly authorized. */
final class PostgresExternalAudioTaskExecutor extends LoaderBoundExternalAudioTaskExecutor
{
    public function __construct()
    {
        $cipher = new ExternalAudioReceiptCipher((array) config('sand_ai_external_audio_receipt', []));
        parent::__construct(
            new PostgresPrivateAudioFileLoader(),
            new ExternalAudioDispatchGateway(new PostgresExternalAudioDispatchStore(), $cipher, new PostgresExternalAudioResultRecoveryPort($cipher)),
            new PostgresExternalAudioRequestFactory(),
            new PostgresOpenAiCompatibleAudioAdapterFactory(),
            static function (int $dispatchId, ExternalAudioDispatchRequest $request, \app\Application\Audio\ControlledAudioInput $input): void {
                if ($input->verifiedDurationSeconds === null) {
                    throw new \app\Api\Support\ApiProblem('SAND_AI_AUDIO_UNAVAILABLE', 'Verified audio duration is unavailable');
                }
                PostgresExternalAudioDispatchStore::freezeDuration(
                    $dispatchId, $request->fingerprint(),
                    new \app\Application\Usage\AudioSeconds($input->verifiedDurationSeconds),
                );
            },
        );
    }
}
