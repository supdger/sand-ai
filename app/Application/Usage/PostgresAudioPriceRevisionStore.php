<?php
declare(strict_types=1);
namespace app\Application\Usage;
use think\facade\Db;
final class PostgresAudioPriceRevisionStore implements AudioPriceRevisionStore
{
    public function published(int $providerId,int $modelId,string $capabilityCode): array
    { return Db::connect('pgsql')->table('sand_ai_price_revision')->where('provider_id',$providerId)->where('model_id',$modelId)->where('capability_code',$capabilityCode)->where('state','published')->whereNull('delete_time')->select()->toArray(); }
    public function rates(int $revisionId): array { return Db::connect('pgsql')->table('sand_ai_price_rate')->where('price_revision_id',$revisionId)->select()->toArray(); }
}
