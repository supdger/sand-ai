<?php
declare(strict_types=1);
namespace app\Application\Audio;
/** Bridges the loader to the generic 121A port; the wrapped engine never sees object storage. */
final readonly class LoadedLocalAudioDriver implements AudioTranscriptionDriver
{
    public function __construct(private int $environmentId, private PrivateAudioFileLoader $loader, private LocalAudioTranscriptionDriver $driver) {}
    public function code(): string { return 'local_whisper_cpp'; }
    public function adapterKind(): string { return 'local'; }
    public function supportedDataEgressPolicies(): array { return ['local_only', 'private_only', 'approved_cloud']; }
    public function transcribe(VerifiedPrivateAudioReference $reference, array $request): array
    { return $this->loader->withInput($this->environmentId, $reference, fn (ControlledAudioInput $input): array => $this->driver->transcribeLocal($input, $request)); }
}
