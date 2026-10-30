<?php

declare(strict_types=1);
namespace app\Infrastructure\Provider;

use app\Api\Support\ApiProblem;
use app\Application\Knowledge\KnowledgeProviderOutcome;
use app\Application\Usage\UsageAttribution;
use app\Infrastructure\Persistence\Model\AiModel;
use app\Infrastructure\Persistence\Model\ModelDeployment;
use app\Infrastructure\Persistence\Model\Provider;

/** Versioned private semantic payload composed with the existing immutable accounting snapshot. */
final readonly class AgentToolProviderOutcome
{
    private function __construct(public array $response, public KnowledgeProviderOutcome $accounting) {}

    public static function fromProviderResult(array $result, UsageAttribution $attribution, Provider $provider, AiModel $model, ModelDeployment $deployment, array $usageRoute): self
    {
        $semantic = ['content'=>$result['content'], 'response_kind'=>$result['response_kind'], 'tool_calls'=>$result['tool_calls']];
        self::validate($semantic);
        $result['content'] = json_encode($semantic, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return new self($semantic, KnowledgeProviderOutcome::fromProviderResult($result, $attribution, $provider, $model, $deployment, $usageRoute));
    }

    public function encode(): string { return json_encode(['agent_tool_version'=>1,'accounting'=>$this->accounting->encode()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }

    public static function decode(string $encoded): self
    {
        try {
            $outer=json_decode($encoded,true,32,JSON_THROW_ON_ERROR);
            if (!is_array($outer) || array_keys($outer)!==['agent_tool_version','accounting'] || $outer['agent_tool_version']!==1 || !is_string($outer['accounting'])) throw new \UnexpectedValueException();
            $accounting=KnowledgeProviderOutcome::decode($outer['accounting']);
            $semantic=json_decode($accounting->content,true,16,JSON_THROW_ON_ERROR);
            if (!is_array($semantic)) throw new \UnexpectedValueException();
            self::validate($semantic);
            return new self($semantic,$accounting);
        } catch (\Throwable) { throw new ApiProblem('SAND_AI_RECEIPT_INVALID','Agent tool outcome receipt is invalid'); }
    }

    private static function validate(array $value): void
    {
        if (array_keys($value)!==['content','response_kind','tool_calls'] || !is_array($value['tool_calls'])) throw new \UnexpectedValueException();
        $message=['content'=>$value['content']];
        if ($value['response_kind']==='tool_calls') {
            $message['tool_calls']=array_map(static fn(array $call):array=>['id'=>$call['id'],'type'=>'function','function'=>['name'=>$call['name'],'arguments'=>json_encode($call['arguments'],JSON_THROW_ON_ERROR)]],$value['tool_calls']);
        } elseif ($value['response_kind']!=='text' || $value['tool_calls']!==[]) throw new \UnexpectedValueException();
        if (AgentToolChatRequest::normalizeMessage($message)!==$value) throw new \UnexpectedValueException();
    }
}
