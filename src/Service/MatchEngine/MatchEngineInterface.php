<?php

namespace App\Service\MatchEngine;

use App\Enum\Competition\MatchEngineIdentifier;

interface MatchEngineInterface
{
    public function supports(MatchEngineIdentifier $identifier): bool;

    public function resolve(MatchEngineSide $home, MatchEngineSide $away, MatchEngineContext $context): MatchEngineResult;
}
