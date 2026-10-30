<?php

declare(strict_types=1);

namespace app\Infrastructure\Provider;

/** Stops SDK request preparation before transport; conveys no provider outcome. */
final class NeuronSkillRequestCaptured extends \RuntimeException
{
}
