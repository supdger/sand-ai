<?php

declare(strict_types=1);

namespace app\Infrastructure\Persistence\Model;

use app\Infrastructure\Capability\CapabilityDriverRegistry;

final class CapabilityRoute extends SandAiModel
{
    protected $table = 'sand_ai_capability_route';

    protected $json = ['config'];

    protected $append = ['route_kind'];

    /** @param array<string, mixed> $data */
    public function getRouteKindAttr(mixed $value, array $data): string
    {
        $capabilityCode = $data['capability_code'] ?? null;
        $driverCode = $data['driver_code'] ?? null;
        if (is_string($capabilityCode) && is_string($driverCode) && $driverCode !== '') {
            try {
                $capability = CapabilityDriverRegistry::capability($capabilityCode);
                if (is_array($capability['drivers'][$driverCode] ?? null)) {
                    return ($capability['route_kind'] ?? null) === 'audio_driver' ? 'audio_driver' : 'parse_driver';
                }
            } catch (\Throwable) {
                // An unregistered string cannot alter persisted route semantics.
            }
        }
        return (int) ($data['deployment_id'] ?? 0) > 0 ? 'model_deployment' : 'parse_driver';
    }
}
