<?php

declare(strict_types=1);

namespace App\Service\Competition;

use App\Entity\Competition\ActiveCompetition;
use App\Entity\Competition\CompetitionEntrant;
use App\Entity\Competition\CompetitionFixture;
use App\Entity\Competition\CompetitionRound;
use App\Enum\Competition\ActiveCompetitionStatus;
use App\Enum\Competition\CompetitionEntrantStatus;
use App\Enum\Competition\CompetitionRoundStatus;
use App\Repository\Competition\CompetitionEntrantRepository;
use App\Repository\Competition\CompetitionFixtureRepository;
use App\Repository\Competition\CompetitionRoundRepository;
use App\Service\Notification\PushNotificationService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Owns the entire draw phase (DRAW_PENDING -> DRAWN), symmetric for round 1 and every
 * later round — round 1 no longer gets synchronous fixture seeding at lock time, it goes
 * through this exact same path as everything else, just with its scheduledAt fixed at
 * lock time instead of written by CompetitionResultsService. Behind
 * app:competition:draw-rounds (cron every 1 min).
 */
class CompetitionDrawService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CompetitionRoundRepository $roundRepository,
        private readonly CompetitionFixtureRepository $fixtureRepository,
        private readonly CompetitionEntrantRepository $entrantRepository,
        private readonly CompetitionScheduleCalculator $scheduleCalculator,
        private readonly PushNotificationService $pushNotificationService,
    ) {}

    /** @return int Number of rounds this call actually drew (0 if none were due, or all were claimed by an overlapping tick). */
    public function drawDueRounds(\DateTimeImmutable $now): int
    {
        $drawn = 0;
        foreach ($this->roundRepository->findDueForDraw($now) as $round) {
            if ($this->claimDraw($round, $now)) {
                $this->drawRound($round, $now);
                $drawn++;
            }
        }

        return $drawn;
    }

    /**
     * Admin-only test tool: draws a single DRAW_PENDING round immediately, bypassing its
     * scheduledAt wait — mirrors CompetitionResultsService::forceResolveFixture()'s bypass
     * of matchesResolveAt, for exercising the lifecycle locally without waiting out real
     * durations.
     *
     * @throws \RuntimeException if the round is not currently draw-pending, or a scheduled
     *         tick is claiming it right now.
     */
    public function forceDrawRound(CompetitionRound $round): void
    {
        if ($round->getStatus() !== CompetitionRoundStatus::DRAW_PENDING) {
            throw new \RuntimeException('This round has already been drawn or is no longer draw-pending.');
        }

        $now = new \DateTimeImmutable();
        if (!$this->claimDraw($round, $now)) {
            throw new \RuntimeException('Could not claim this round for drawing — a scheduled tick may be running it right now. Try again.');
        }

        $this->drawRound($round, $now);
    }

    /**
     * Claim-lock: an atomic conditional UPDATE, not ORM flush+catch. Sets DRAWN as part of
     * the same statement as the old fused claimRound() did with RUNNING — if 0 rows are
     * affected, another overlapping tick already claimed this round.
     */
    private function claimDraw(CompetitionRound $round, \DateTimeImmutable $now): bool
    {
        $affected = $this->em->getConnection()->executeStatement(
            <<<'SQL'
            UPDATE competition_round
               SET status = :drawn, draw_locked_at = :now
             WHERE id = :id
               AND status = :draw_pending
               AND draw_locked_at IS NULL
            SQL,
            [
                'drawn'        => CompetitionRoundStatus::DRAWN->value,
                'now'          => $now->format('Y-m-d H:i:s'),
                'id'           => $round->getId()->toRfc4122(),
                'draw_pending' => CompetitionRoundStatus::DRAW_PENDING->value,
            ],
        );

        if ($affected === 0) {
            return false;
        }

        $this->em->refresh($round);

        return true;
    }

    private function drawRound(CompetitionRound $round, \DateTimeImmutable $now): void
    {
        $activeCompetition = $round->getActiveCompetition();

        if ($round->getRoundIndex() === 1) {
            $fixtures = $this->seedFirstRoundFixtures($round);
            if ($activeCompetition->getStatus() === ActiveCompetitionStatus::SCHEDULED) {
                $activeCompetition->setStatus(ActiveCompetitionStatus::RUNNING);
            }
        } else {
            $fixtures = $this->seedFixturesFromPreviousRoundWinners($round);
        }

        // Close the resubmission window — snapshots are locked for the duration of this round.
        foreach ($this->entrantRepository->findByCompetitionOrderedByRegistration($activeCompetition) as $entrant) {
            if ($entrant->getStatus() === CompetitionEntrantStatus::ACTIVE) {
                $entrant->setSnapshotLockedAt($now);
            }
        }

        $round->setStartedAt($now);
        $round->setMatchesResolveAt($this->resolveDueAt($round, $now));

        $this->pushNotificationService->notifyUsers(
            $this->userIdsFor($fixtures),
            $round->getRoundIndex() === 1 ? 'The draw is in!' : 'Your next match is set!',
            $round->getRoundIndex() === 1
                ? 'Round 1 fixtures have been set — check your opponent.'
                : sprintf('The %s draw is in — see who you\'re facing.', $round->getLabel()),
            ['type' => 'ROUND_DRAWN', 'competitionId' => (string) $activeCompetition->getId(), 'roundId' => (string) $round->getId()],
        );

        $this->em->flush();
    }

    /** @return list<CompetitionFixture> */
    private function seedFirstRoundFixtures(CompetitionRound $round): array
    {
        $entrants = $this->entrantRepository->findByCompetitionOrderedByRegistration($round->getActiveCompetition());

        $fixtures = [];
        $slot     = 0;
        for ($i = 0; $i < count($entrants); $i += 2) {
            $home       = $entrants[$i] ?? null;
            $away       = $entrants[$i + 1] ?? null;
            $fixture    = new CompetitionFixture($round, $slot, $home, $away);
            $this->em->persist($fixture);
            $fixtures[] = $fixture;
            $slot++;
        }

        return $fixtures;
    }

    /** @return list<CompetitionFixture> */
    private function seedFixturesFromPreviousRoundWinners(CompetitionRound $round): array
    {
        $previousRound = $this->roundRepository->findByCompetitionAndIndex($round->getActiveCompetition(), $round->getRoundIndex() - 1);
        if ($previousRound === null) {
            return [];
        }

        $winners = [];
        foreach ($this->fixtureRepository->findByRoundOrderedBySlot($previousRound) as $fixture) {
            if ($fixture->getWinnerEntrant() !== null) {
                $winners[] = $fixture->getWinnerEntrant();
            }
        }

        $fixtures = [];
        $slot     = 0;
        for ($i = 0; $i < count($winners); $i += 2) {
            $home       = $winners[$i] ?? null;
            $away       = $winners[$i + 1] ?? null;
            $fixture    = new CompetitionFixture($round, $slot, $home, $away);
            $this->em->persist($fixture);
            $fixtures[] = $fixture;
            $slot++;
        }

        return $fixtures;
    }

    private function resolveDueAt(CompetitionRound $round, \DateTimeImmutable $now): \DateTimeImmutable
    {
        $activeCompetition = $round->getActiveCompetition();
        $roundCount        = count(BracketLabeler::labelsForCapacity($activeCompetition->getEntrantCapacity()));
        $totalSeconds       = $activeCompetition->getEndsAt()->getTimestamp() - $activeCompetition->getStartsAt()->getTimestamp();
        $intervalSeconds     = $this->scheduleCalculator->intervalSeconds($totalSeconds, $roundCount);
        [$revealSeconds, ]  = $this->scheduleCalculator->splitInterval($intervalSeconds, $activeCompetition->getTemplate()->getIntermissionRatio());

        $matchesResolveAt = $now->modify(sprintf('+%d seconds', $revealSeconds));

        $isFinalRound = $round->getRoundIndex() === $roundCount;
        if ($isFinalRound && $matchesResolveAt > $activeCompetition->getEndsAt()) {
            return $activeCompetition->getEndsAt();
        }

        return $matchesResolveAt;
    }

    /** @param list<CompetitionFixture> $fixtures @return list<string> */
    private function userIdsFor(array $fixtures): array
    {
        $userIds = [];
        foreach ($fixtures as $fixture) {
            foreach ([$fixture->getHomeEntrant(), $fixture->getAwayEntrant()] as $entrant) {
                if ($entrant instanceof CompetitionEntrant) {
                    $userIds[] = (string) $entrant->getClub()->getUser()->getId();
                }
            }
        }

        return array_values(array_unique($userIds));
    }
}
