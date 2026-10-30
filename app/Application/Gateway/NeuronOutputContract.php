<?php

declare(strict_types=1);

namespace app\Application\Gateway;

/** Data shared by official request construction; domain subclasses own schema rules. */
readonly class NeuronOutputContract
{
    /** @param array<string,mixed> $schema */
    public function __construct(public string $strategy, public array $schema)
    {
        \app\Application\Skill\SkillOutputStrategy::assert($strategy);
    }

    /** @return array{strategy:string,schema:array<string,mixed>} */
    public function fingerprint(): array
    {
        return ['strategy' => $this->strategy, 'schema' => $this->schema];
    }
}
