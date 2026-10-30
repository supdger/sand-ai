<?php
declare(strict_types=1);
namespace app\Application\Audio;
final readonly class FrozenAudioReferenceVerifier implements PrivateAudioReferenceVerifier
{
    public function __construct(private VerifiedPrivateAudioReference $reference) {}
    public function verify(string $fileRef): VerifiedPrivateAudioReference
    { if ($fileRef !== $this->reference->fileRef) throw new \app\Api\Support\ApiProblem('SAND_AI_AUDIO_REFERENCE_FORBIDDEN','The frozen audio reference differs'); return $this->reference; }
}
