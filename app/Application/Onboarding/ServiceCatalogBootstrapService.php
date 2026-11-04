<?php

declare(strict_types=1);

namespace app\Application\Onboarding;

use app\Api\Support\ApiProblem;
use app\Identity\Infrastructure\HostSandIamServiceCatalog;
use Throwable;

/**
 * Idempotent bootstrap of the SandAI declaration in the SandIAM service catalog.
 *
 * Only the shared service/action directory is touched. No credential, workload
 * client or grant is created, and a disabled service or action is never
 * re-enabled; the three registration calls are insert-if-missing in SandIAM.
 */
final class ServiceCatalogBootstrapService
{
    public const REASON_MISSING = 'sandiam_unavailable';
    public const REASON_DISABLED = 'sandiam_disabled';
    public const REASON_FAILED = 'catalog_error';

    private const MESSAGE_MISSING = '需要先安装身份与授权插件（SandIAM）';
    private const MESSAGE_DISABLED = 'SandIAM 中的 SandAI 服务或动作已被停用，请先在 SandIAM 中启用后重试';
    private const MESSAGE_FAILED = '系统准备未完成：服务目录登记失败，请稍后重试';

    private readonly HostSandIamServiceCatalog $catalog;

    public function __construct(?HostSandIamServiceCatalog $catalog = null)
    {
        $this->catalog = $catalog ?? new HostSandIamServiceCatalog();
    }

    /**
     * @return array{ready:bool,service_code:?string,groups:array<string,int>,reason:?string,message:?string,retryable:bool}
     */
    public function bootstrap(): array
    {
        try {
            $preflight = $this->catalog->preflight();
            if ($preflight['port_available'] !== true) {
                return $this->failure(self::REASON_MISSING, self::MESSAGE_MISSING, false);
            }

            $base = $this->catalog->register();
            $skill = $this->catalog->registerSkill();
            $audio = $this->catalog->registerAudio();
        } catch (ApiProblem $problem) {
            if ($problem->errorCode === 'SAND_AI_SERVICE_CATALOG_DISABLED') {
                return $this->failure(self::REASON_DISABLED, self::MESSAGE_DISABLED, false);
            }

            return $this->failure(self::REASON_FAILED, self::MESSAGE_FAILED, true);
        } catch (Throwable) {
            return $this->failure(self::REASON_FAILED, self::MESSAGE_FAILED, true);
        }

        return [
            'ready' => true,
            'service_code' => $base['service_code'],
            'groups' => [
                'base' => count($base['action_codes']),
                'skill' => count($skill['action_codes']),
                'audio' => count($audio['action_codes']),
            ],
            'reason' => null,
            'message' => null,
            'retryable' => false,
        ];
    }

    /**
     * @return array{ready:bool,service_code:?string,groups:array<string,int>,reason:?string,message:?string,retryable:bool}
     */
    private function failure(string $reason, string $message, bool $retryable): array
    {
        return [
            'ready' => false,
            'service_code' => null,
            'groups' => [],
            'reason' => $reason,
            'message' => $message,
            'retryable' => $retryable,
        ];
    }
}
