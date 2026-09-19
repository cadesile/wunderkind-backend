<?php

namespace App\Service\Competition;

use App\Entity\Competition\ActiveCompetition;
use App\Entity\Competition\CompetitionEntrant;
use App\Entity\Competition\CompetitionFixture;
use App\Entity\Competition\CompetitionResult;
use App\Entity\Competition\CompetitionRound;
use App\Enum\Competition\ActiveCompetitionStatus;
use App\Enum\Competition\CompetitionEntrantStatus;
use App\Enum\Competition\CompetitionFixtureStatus;
use App\Enum\Competition\CompetitionRoundStatus;
use App\Repository\Competition\CompetitionEntrantRepository;
use App\Repository\Competition\CompetitionFixtureRepository;
use App\Repository\Competition\CompetitionResultRepository;
use App\Repository\Competition\CompetitionRoundRepository;
use App\Service\Appearance\SeededRng;
use App\Service\MatchEngine\MatchEngineRegistry;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The core scheduling/idempotency/bracket-advancement logic behind
 * app:competition:process-rounds. Split out from the command for testability.
 */
class CompetitionRoundProcessorService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CompetitionRoundRepository $roundRepository,
        private readonly CompetitionFixtureRepository $fixtureRepository,
        private readonly CompetitionEntrantRepository $entrantRepository,
        private readonly CompetitionResultRepository $resultRepository,
        private readonly MatchEngineRegistry $matchEngineRegistry,
        private readonly RewardApplierService $rewardApplierService,
    ) {}

    /** @return int Number of rounds this call actually processed (0 if none were due, or all were claimed by an overlapping tick). */
    public function processDueRounds(\DateTimeImmutable $now): int
    {
        $processed = 0;
        foreach ($this->roundRepository->findDueRounds($now) as $round) {
            if ($this->claimRound($round, $now)) {
                $this->processRound($round, $now);
                $processed++;
            }
        }

        return $processed;
    }

    /**
     * Claim-lock: an atomic conditional UPDATE, not ORM flush+catch. If 0 rows are
     * affected, another overlapping tick already claimed this round — the guard
     * against double-execution.
     */
    private function claimRound(CompetitionRound $round, \DateTimeImmutable $now): bool
    {
        $affected = $this->em->getConnection()->executeStatement(
            <<<'SQL'
            UPDATE competition_round
               SET status = :running, locked_for_processing_at = :now
             WHERE id = :id
               AND status IN (:pending, :scheduled)
               AND locked_for_processing_at IS NULL
            SQL,
            [
                'running'   => CompetitionRoundStatus::RUNNING->value,
                'now'       => $now->format('Y-m-d H:i:s'),
                'id'        => $round->getId()->toRfc4122(),
                'pending'   => CompetitionRoundStatus::PENDING->value,
                'scheduled' => CompetitionRoundStatus::SCHEDULED->value,
            ],
        );

        if ($affected === 0) {
            return false;
        }

        $this->em->refresh($round);

        return true;
    }

    private function processRound(CompetitionRound $round, \DateTimeImmutable $now): void
    {
        $this->beginRoundProcessing($round, $now);

        $winners = [];
        foreach ($this->fixtureRepository->findByRoundOrderedBySlot($round) as $fixture) {
            if ($fixture->getProcessedAt() !== null) {
                // Defensive: already resolved by a prior partial run.
                if ($fixture->getWinnerEntrant() !== null) {
                    $winners[] = $fixture->getWinnerEntrant();
                }
                continue;
            }

            $winner = $this->resolveFixture($fixture, $round, $now);
            if ($winner !== null) {
                $winners[] = $winner;
            }
        }

        $this->finalizeRound($round, $now, $winners);
    }

    /**
     * Admin-only test tool: forces a single PENDING fixture to resolve immediately,
     * bypassing the round's scheduledAt wait entirely — for exercising round-by-round
     * competition processing locally without waiting out real durations. Shares the same
     * claim-lock, resolution, and round-completion/bracket-advancement logic as the
     * scheduled cron path (processDueRounds()), just triggered per-fixture instead of
     * per-due-round, so a forced result is indistinguishable from a naturally processed one.
     *
     * @throws \RuntimeException if the fixture is already resolved, is a bye (nothing to
     *         simulate — no opposing entrant), or its round can no longer be processed
     *         (already COMPLETED/CANCELLED).
     */
    public function forceResolveFixture(CompetitionFixture $fixture): CompetitionResult
    {
        if ($fixture->getProcessedAt() !== null) {
            throw new \RuntimeException('This fixture has already been resolved.');
        }
        if ($fixture->getHomeEntrant() === null || $fixture->getAwayEntrant() === null) {
            throw new \RuntimeException('This fixture has no opposing entrant yet — nothing to simulate.');
        }

        $round = $fixture->getRound();
        if (!in_array($round->getStatus(), [CompetitionRoundStatus::PENDING, CompetitionRoundStatus::SCHEDULED, CompetitionRoundStatus::RUNNING], true)) {
            throw new \RuntimeException('This round is no longer processable.');
        }

        $now = new \DateTimeImmutable();

        if ($round->getStatus() !== CompetitionRoundStatus::RUNNING) {
            if (!$this->claimRound($round, $now)) {
                throw new \RuntimeException('Could not claim this round for processing — a scheduled tick may be running it right now. Try again.');
            }
            $this->beginRoundProcessing($round, $now);
        }

        $this->resolveFixture($fixture, $round, $now);
        $this->em->flush();

        $remainingFixtures = $this->fixtureRepository->findByRoundOrderedBySlot($round);
        $roundFullyResolved = true;
        $winners             = [];
        foreach ($remainingFixtures as $roundFixture) {
            if ($roundFixture->getProcessedAt() === null) {
                $roundFullyResolved = false;
                break;
            }
            if ($roundFixture->getWinnerEntrant() !== null) {
                $winners[] = $roundFixture->getWinnerEntrant();
            }
        }

        if ($roundFullyResolved) {
            $this->finalizeRound($round, $now, $winners);
        }

        return $this->resultRepository->findOneBy(['fixture' => $fixture])
            ?? throw new \RuntimeException('Fixture was resolved but no result was recorded — this should never happen.');
    }

    /** Pre-loop side effects shared by the scheduled and forced processing paths. */
    private function beginRoundProcessing(CompetitionRound $round, \DateTimeImmutable $now): void
    {
        $activeCompetition = $round->getActiveCompetition();

        if ($round->getRoundIndex() === 1 && $activeCompetition->getStatus() === ActiveCompetitionStatus::SCHEDULED) {
            $activeCompetition->setStatus(ActiveCompetitionStatus::RUNNING);
        }

        // Close the resubmission window — snapshots are locked for the duration of this round.
        foreach ($this->entrantRepository->findByCompetitionOrderedByRegistration($activeCompetition) as $entrant) {
            if ($entrant->getStatus() === CompetitionEntrantStatus::ACTIVE) {
                $entrant->setSnapshotLockedAt($now);
            }
        }
    }

    /**
     * Post-loop side effects shared by the scheduled and forced processing paths — only
     * called once every fixture in $round has been resolved.
     *
     * @param list<CompetitionEntrant> $winners
     */
    private function finalizeRound(CompetitionRound $round, \DateTimeImmutable $now, array $winners): void
    {
        $activeCompetition = $round->getActiveCompetition();

        $round->setStatus(CompetitionRoundStatus::COMPLETED);
        $round->setCompletedAt($now);

        if ($this->isFinalRound($round)) {
            foreach ($winners as $winner) {
                $winner->setStatus(CompetitionEntrantStatus::WINNER);
                $this->rewardApplierService->applyVictorPrize($winner, $activeCompetition);
            }
            $activeCompetition->setStatus(ActiveCompetitionStatus::COMPLETED);
            $activeCompetition->setCompletedAt($now);
        } else {
            $this->populateNextRoundFixtures($round, $winners);
        }

        $this->em->flush();
    }

    private function resolveFixture(CompetitionFixture $fixture, CompetitionRound $round, \DateTimeImmutable $now): ?CompetitionEntrant
    {
        $home = $fixture->getHomeEntrant();
        $away = $fixture->getAwayEntrant();

        if ($home === null || $away === null) {
            // The no-registration-deadline design means byes shouldn't occur in Phase 1 (capacities
            // are powers of 2, locking only happens on exact capacity fill) — this is a defensive
            // fallback, not an expected path: advance whichever side exists so a stuck edge case
            // doesn't wedge the whole bracket.
            $survivor = $home ?? $away;
            $fixture->setStatus(CompetitionFixtureStatus::BYE);
            $fixture->setWinnerEntrant($survivor);
            $fixture->setProcessedAt($now);

            return $survivor;
        }

        $engine      = $this->matchEngineRegistry->resolveFor($round->getMatchEngineIdentifier());
        $matchResult = $engine->resolve($home, $away, $fixture);

        $result = new CompetitionResult(
            $fixture,
            $matchResult->homeScore,
            $matchResult->awayScore,
            $matchResult->eventLog,
            $round->getMatchEngineIdentifier(),
            $this->clubJsonOf($home),
            $this->clubJsonOf($away),
            $matchResult->homeLineup,
            $matchResult->awayLineup,
        );
        $result->setNarrativePayload($matchResult->narrativePayload);
        $result->setShootoutInfo($matchResult->wentToExtraTime, $matchResult->wentToPenalties, $matchResult->penaltyHomeScore, $matchResult->penaltyAwayScore);
        $this->em->persist($result);

        $winner = match (true) {
            $matchResult->homeScore > $matchResult->awayScore => $home,
            $matchResult->awayScore > $matchResult->homeScore => $away,
            $matchResult->wentToPenalties => ($matchResult->penaltyHomeScore > $matchResult->penaltyAwayScore ? $home : $away),
            // Defensive fallback only — DeterministicEngine now guarantees a decisive result
            // via extra time + penalties, so this branch is unreachable in practice; kept in
            // case an engine that hasn't implemented ET/penalties yet (e.g. a future real AI
            // tier) ever produces a level score.
            default => $this->breakTie($home, $away, $fixture),
        };
        $loser = $winner === $home ? $away : $home;

        $fixture->setStatus(CompetitionFixtureStatus::COMPLETE);
        $fixture->setWinnerEntrant($winner);
        $fixture->setProcessedAt($now);

        $loser->setStatus(CompetitionEntrantStatus::ELIMINATED);
        $loser->setEliminatedInRound($round);

        return $winner;
    }

    /** @return array<string, mixed> */
    private function clubJsonOf(CompetitionEntrant $entrant): array
    {
        $club = $entrant->getSnapshotJson()['club'] ?? [];

        return is_array($club) ? $club : [];
    }

    private function breakTie(CompetitionEntrant $home, CompetitionEntrant $away, CompetitionFixture $fixture): CompetitionEntrant
    {
        $rng = new SeededRng(SeededRng::hashId($fixture->getId()->toRfc4122() . ':tiebreak'));

        return $rng->chance(0.5) ? $home : $away;
    }

    /** @param list<CompetitionEntrant> $winners In slot order — adjacent winners (0&1, 2&3, ...) meet in the next round. */
    private function populateNextRoundFixtures(CompetitionRound $completedRound, array $winners): void
    {
        $activeCompetition = $completedRound->getActiveCompetition();
        $rounds            = $this->roundRepository->findByCompetitionOrderedByIndex($activeCompetition);
        // Rounds are 0-indexed by array position but 1-based by roundIndex, so the round
        // right after $completedRound sits at array offset == completedRound's roundIndex.
        $nextRound = $rounds[$completedRound->getRoundIndex()] ?? null;

        if ($nextRound === null) {
            return;
        }

        $slot = 0;
        for ($i = 0; $i < count($winners); $i += 2) {
            $home    = $winners[$i] ?? null;
            $away     = $winners[$i + 1] ?? null;
            $fixture   = new CompetitionFixture($nextRound, $slot, $home, $away);
            $this->em->persist($fixture);
            $slot++;
        }
    }

    private function isFinalRound(CompetitionRound $round): bool
    {
        $labels = BracketLabeler::labelsForCapacity($round->getActiveCompetition()->getEntrantCapacity());

        return $round->getRoundIndex() === count($labels);
    }
}
