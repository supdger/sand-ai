<?php

declare(strict_types=1);

namespace app\Application\Usage;

use app\Api\Support\ApiProblem;

/** Safe frozen Provider/Model/Deployment evidence for a future audio task. */
final readonly class AudioExecutionTarget
{
    /** @param array{id:int,code:string,name:string} $provider @param array{id:int,code:string,name:string} $model @param array{id:int,remote_model:string,revision:int,route:array<string,int|string>} $deployment */
    private function __construct(public array $provider, public array $model, public array $deployment) {}

    /** @param array<string,mixed> $provider @param array<string,mixed> $model @param array<string,mixed> $deployment */
    public static function fromSnapshots(int $providerId, int $modelId, int $deploymentId, array $provider, array $model, array $deployment): self
    {
        self::exact($provider, ['id','code','name']); self::exact($model, ['id','code','name']); self::exact($deployment, ['id','remote_model','revision','route']);
        foreach ([[$provider,$providerId],[$model,$modelId],[$deployment,$deploymentId]] as [$snapshot,$id]) if (!is_int($snapshot['id']) || $snapshot['id'] !== $id || $id < 1) self::invalid();
        foreach ([$provider['code'],$provider['name'],$model['code'],$model['name'],$deployment['remote_model']] as $value) if (!is_string($value) || trim($value) === '' || strlen($value) > 256) self::invalid();
        if (!is_int($deployment['revision']) || $deployment['revision'] < 1 || !is_array($deployment['route'])) self::invalid();
        self::route($deployment['route']);
        self::safe($provider); self::safe($model); self::safe($deployment);
        return new self($provider, $model, $deployment);
    }
    /** @param array<string,mixed> $value */
    public static function fromPersistence(array $value): self
    { self::exact($value,['provider','model','deployment']); if(!is_array($value['provider'])||!is_array($value['model'])||!is_array($value['deployment'])||!is_int($value['provider']['id']??null)||!is_int($value['model']['id']??null)||!is_int($value['deployment']['id']??null)) self::invalid(); return self::fromSnapshots($value['provider']['id'],$value['model']['id'],$value['deployment']['id'],$value['provider'],$value['model'],$value['deployment']); }
    /** @return array<string,mixed> */
    public function persistence(): array { return ['provider'=>$this->provider,'model'=>$this->model,'deployment'=>$this->deployment]; }
    /** @param array<string,mixed> $value @param list<string> $keys */
    private static function exact(array $value, array $keys): void { $actual=array_keys($value); sort($actual); sort($keys); if ($actual !== $keys) self::invalid(); }
    private static function safe(mixed $value): void
    {
        if (is_array($value)) { foreach ($value as $key=>$item) { if (!is_string($key) || preg_match('/secret|token|password|api_?key|credential|endpoint|url|response|raw|path|object_key|bucket|storage|location/i',$key)) self::invalid(); self::safe($item); } return; }
        if (!is_int($value) && !is_string($value)) self::invalid();
        if (is_string($value) && (preg_match('#(?:[a-z][a-z0-9+.-]*)://#i',$value) || preg_match('#\A(?:/|\\\\|[A-Za-z]:[\\\\/]|\./|\.\./|~)#',$value))) self::invalid();
    }
    /** @param array<string,mixed> $route */
    private static function route(array $route): void
    {
        if ($route === []) return;
        self::exact($route, ['selection_kind','profile_id','profile_revision','route_id']);
        if (($route['selection_kind'] ?? null) !== 'capability_profile') self::invalid();
        foreach (['profile_id','profile_revision','route_id'] as $key) if (!is_int($route[$key]) || $route[$key] < 1) self::invalid();
    }
    private static function invalid(): never { throw new ApiProblem('SAND_AI_AUDIO_TARGET_INVALID', 'Audio execution target snapshot is invalid'); }
}
