<?php
declare(strict_types=1);
namespace app\Application\Usage;
use app\Api\Support\ApiProblem;
final readonly class AudioPriceResolver
{
    public function __construct(private AudioPriceRevisionStore $store) {}
    public function resolve(int $providerId,int $modelId,string $calledAt): ?ResolvedAudioPrice
    {
        $revisions=$this->store->published($providerId,$modelId,'audio_transcription');
        foreach ($revisions as $row) if (!is_array($row) || ($row['state']??null)!=='published' || !is_int($row['id']??null) || !is_string($row['currency']??null) || !is_string($row['effective_from']??null) || (($row['effective_until']??null)!==null && !is_string($row['effective_until']))) throw new ApiProblem('SAND_AI_PRICING_SNAPSHOT_INVALID','Persisted audio price revision is invalid');
        $revision=(new PriceRevisionSelector())->select($revisions,$calledAt);
        if ($revision===null) return null;
        $rates=$this->store->rates((int)$revision['id']);
        if (count($rates)!==1 || !is_array($rates[0]) || ($rates[0]['meter_code']??null)!=='audio_seconds' || !is_string($rates[0]['unit_price']??null) || !is_int($rates[0]['per_quantity']??null)) throw new ApiProblem('SAND_AI_PRICING_SNAPSHOT_INVALID','Published audio price rates are invalid');
        return new ResolvedAudioPrice($revision['id'],$revision['currency'],['unit_price'=>$rates[0]['unit_price'],'per_quantity'=>$rates[0]['per_quantity']],$revision['effective_from'],$revision['effective_until']??null);
    }
}
