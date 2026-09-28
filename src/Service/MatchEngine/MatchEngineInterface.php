<?php

namespace App\Service\MatchEngine;

use App\Entity\Competition\CompetitionEntrant;
use App\Entity\Competition\CompetitionFixture;
use App\Enum\Competition\MatchEngineIdentifier;

interface MatchEngineInterface
{
    public function supports(MatchEngineIdentifier $identifier): bool;

    public function resolve(CompetitionEntrant $home, CompetitionEntrant $away, CompetitionFixture $fixture): MatchEngineResult;
}
