<?php

namespace App\Service\MatchEngine;

use App\Entity\Competition\CompetitionEntrant;
use App\Entity\Competition\CompetitionFixture;
use App\Enum\Competition\MatchEngineIdentifier;
use Psr\Log\LoggerInterface;

/**
 * Stands in for Type B (AI-Assisted) and Type C (Narrative AI) in Phase 1. Both
 * identifiers are real, selectable MatchEngineIdentifier values — a template can
 * already be configured to use either — but both are served by this single stub,
 * which delegates the scoreline (and its already-generated narrativePayload) to
 * DeterministicEngine. A template pointed at an AI tier still produces a playable round instead of
 * hard-failing a live tournament. Real LLM integration (prompt/data mapping) is
 * explicitly out of scope for Phase 1 per the source brief's own open question.
 */
class AiEngineStub implements MatchEngineInterface
{
    public function __construct(
        private readonly DeterministicEngine $deterministicEngine,
        private readonly LoggerInterface $logger,
    ) {}

    public function supports(MatchEngineIdentifier $identifier): bool
    {
        return in_array($identifier, [MatchEngineIdentifier::AI_ASSISTED, MatchEngineIdentifier::AI_NARRATIVE], true);
    }

    public function resolve(CompetitionEntrant $home, CompetitionEntrant $away, CompetitionFixture $fixture): MatchEngineResult
    {
        $this->logger->warning('AI match engine is not implemented in Phase 1 — falling back to deterministic scoring.', [
            'fixtureId' => (string) $fixture->getId(),
        ]);

        $result = $this->deterministicEngine->resolve($home, $away, $fixture);

        return new MatchEngineResult(
            $result->homeScore,
            $result->awayScore,
            $result->eventLog,
            $result->narrativePayload,
            $result->homeLineup,
            $result->awayLineup,
            $result->wentToExtraTime,
            $result->wentToPenalties,
            $result->penaltyHomeScore,
            $result->penaltyAwayScore,
        );
    }
}
