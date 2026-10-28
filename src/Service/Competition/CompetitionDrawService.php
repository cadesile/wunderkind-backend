<?php

declare(strict_types=1);

namespace App\Service\Competition;

use App\Entity\Competition\ActiveCompetition;
use App\Entity\Competition\CompetitionEntrant;
use App\Entity\Competition\CompetitionFixture;
use App\Entity\Competition\CompetitionRound;
use App\Enum\Competition\ActiveCompetitionStatus;
use App\Enum\Competition\CompetitionEntrantStatus;
use App\Enum\Competition\EntrantSquadState;
use App\Enum\Competition\CompetitionRoundStatus;
use App\Enum\MessageSenderType;
use App\Repository\Competition\CompetitionEntrantRepository;
use App\Repository\Competition\CompetitionFixtureRepository;
use App\Repository\Competition\CompetitionRoundRepository;
use App\Service\InboxService;
use App\Service\Notification\PushNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use App\Message\Competition\DrawCompetitionRoundMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Owns the entire draw phase (DRAW_PENDING -> DRAWN), symmetric for round 1 and every
 * later round — round 1 no longer gets synchronous fixture seeding at lock time, it goes
 * through this exact same path as everything else, just with its scheduledAt fixed at
 * lock time instead of written by CompetitionResultsService. Behind
 * app:competition:draw-rounds (cron every 1 min).
 */
class CompetitionDrawService
{
    /**
     * Prewarming the bracket response is deliberately NOT done here.
     *
     * It used to be, and it was wrong twice over:
     *
     *  - The cache write went through the shared EntityManager, and a failed `flush()` closes
     *    it. The `catch (\Throwable)` around the prewarm logged and continued, but the next
     *    due round in this loop was then claimed by raw SQL and died on
     *    `EntityManagerClosed` — leaving `*_locked_at` set with nothing able to reclaim it,
     *    so that round was stuck permanently.
     *  - Even on success it could store a stale document as fresh. The state version comes
     *    from live SQL aggregates, but this tick has already loaded the competition's
     *    entrants, and Doctrine does not re-hydrate an already-managed entity. A resubmission
     *    committing mid-tick is counted in the version and absent from the document.
     *
     * There is no safe way to get fresh reads from inside this loop: `em->clear()` would
     * detach the round the loop is still iterating, which `claim*()` refreshes. So the
     * prewarm belongs off the tick entirely, as a queued message — see
     * `docs/superpowers/plans/2026-10-28-tournament-concurrency-and-queues.md` §3.4. Until
     * then the next reader builds it, in its own request, from fresh state.
     */

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CompetitionRoundRepository $roundRepository,
        private readonly CompetitionFixtureRepository $fixtureRepository,
        private readonly CompetitionEntrantRepository $entrantRepository,
        private readonly CompetitionScheduleCalculator $scheduleCalculator,
        private readonly PushNotificationService $pushNotificationService,
        private readonly MessageBusInterface $messageBus,
        private readonly ?InboxService $inboxService = null,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * Queues every due round for drawing, one message each.
     *
     * What the cron calls. The tick itself now does O(due rounds) cheap inserts instead of
     * drawing them all inline — which is the whole point when several tournaments are
     * rolling at once, since a draw also writes an inbox message per entrant and a push per
     * fixture.
     *
     * Nothing dedupes the dispatch, deliberately. StarterPoolAuditService gates its sweep on
     * an undrained queue because its queue depth scales with how far a pool has drifted, so a
     * second sweep genuinely stacks. Here a dispatch is one row per due round and a duplicate
     * is free — the handler claims, and the second claim fails — so gating would stall every
     * *other* competition's progress for no benefit. Throughput is bounded at the consumer,
     * where the expensive work actually is.
     *
     * @return int Number of rounds queued.
     */
    public function dispatchDueDraws(\DateTimeImmutable $now): int
    {
        $queued = 0;
        foreach ($this->roundRepository->findDueForDraw($now) as $round) {
            $this->messageBus->dispatch(new DrawCompetitionRoundMessage((string) $round->getId()));
            $queued++;
        }

        return $queued;
    }

    /**
     * Draws one round if its claim can be taken.
     *
     * The single per-round implementation, shared by the queue handler and by
     * drawDueRounds() below, so the synchronous and queued paths cannot drift.
     *
     * @return bool False when another tick (or handler) holds the lease, or the round is no
     *              longer draw-pending — both of which are ordinary, not errors.
     */
    public function drawRoundIfClaimable(CompetitionRound $round, \DateTimeImmutable $now): bool
    {
        // Checked BEFORE claiming, not after. Taking the lease first and then bailing would
        // park a 300-second claim on a round that was never drawn, so every retry for the
        // next five minutes would find it claimed and skip — turning a few seconds of queue
        // latency into a five-minute stall.
        if ($this->hasUnstoredSubmissions($round->getActiveCompetition())) {
            $this->logger?->info('competition.draw.deferred', [
                'roundId' => (string) $round->getId(),
                'reason'  => 'entrant submissions still pending',
            ]);

            return false;
        }

        if (!$this->claimDraw($round, $now)) {
            return false;
        }

        $this->drawRound($round, $now);

        return true;
    }

    /**
     * Draws every due round inline.
     *
     * Kept as the synchronous driver: it is what the tests exercise, and it remains a usable
     * fallback if the `competition` consumer is ever not running. Production goes through
     * dispatchDueDraws() instead.
     *
     * @return int Number of rounds this call actually drew (0 if none were due, or all were claimed by an overlapping tick).
     */
    public function drawDueRounds(\DateTimeImmutable $now): int
    {
        $drawn = 0;
        foreach ($this->roundRepository->findDueForDraw($now) as $round) {
            if ($this->drawRoundIfClaimable($round, $now)) {
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

        if ($this->hasUnstoredSubmissions($round->getActiveCompetition())) {
            throw new \RuntimeException(
                'One or more entrants are still storing their submitted squad. Drawing now would '
                . 'seed a fixture against an empty XI. Wait a moment and retry; if it persists, check '
                . 'app:competition:health for a failed submission.',
            );
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
    /**
     * Takes the draw lease for this round, or reports that someone else holds it.
     *
     * Two deliberate changes from how this used to work, both of which were causing rounds
     * to become permanently unprocessable:
     *
     *  - **It no longer flips the status to DRAWN.** That flip was committed here, by raw
     *    SQL, *before* drawRound() created a single fixture — so a failure in drawRound()'s
     *    flush left a round marked DRAWN with no fixtures and `matches_resolve_at` still
     *    NULL. `findDueForDraw()` then skipped it (it wants DRAW_PENDING) and
     *    `findDueForResults()` skipped it too (`matches_resolve_at <= now` is never true for
     *    NULL), so the round was invisible to **both** ticks, forever. The transition now
     *    happens inside drawRound()'s own flush, atomically with the fixtures it describes.
     *  - **The claim is a lease.** `draw_locked_at IS NULL` meant a claim was never
     *    releasable: any exception after claiming stranded the round. A claim older than
     *    CompetitionRound::CLAIM_LEASE_SECONDS is now reclaimable, so a crashed tick
     *    self-heals.
     */
    private function claimDraw(CompetitionRound $round, \DateTimeImmutable $now): bool
    {
        $affected = $this->em->getConnection()->executeStatement(
            <<<'SQL'
            UPDATE competition_round
               SET draw_locked_at = :now
             WHERE id = :id
               AND status = :draw_pending
               AND (draw_locked_at IS NULL OR draw_locked_at < :reclaimBefore)
            SQL,
            [
                'now'           => $now->format('Y-m-d H:i:s'),
                'id'            => $round->getId()->toRfc4122(),
                'draw_pending'  => CompetitionRoundStatus::DRAW_PENDING->value,
                'reclaimBefore' => $now->modify(sprintf('-%d seconds', CompetitionRound::CLAIM_LEASE_SECONDS))->format('Y-m-d H:i:s'),
            ],
        );

        if ($affected === 0) {
            return false;
        }

        $this->em->refresh($round);

        return true;
    }

    /**
     * Refuses to draw while any entrant's squad row is still being written.
     *
     * Registration reserves a slot and queues the squad write, so an entrant can hold a place
     * in a full competition for the moment before its XI lands. The squad row is the match
     * engine's only input: drawing against a PENDING entrant would not fail, it would resolve
     * a real fixture against an empty XI and publish the result. Returning false leaves the
     * round due and unclaimed, so the next tick retries — the queue is drained by an always-on
     * worker, so "next tick" is normally within seconds.
     *
     * FAILED is deliberately **not** treated as drawable-after-all. A submission whose retries
     * are spent needs an operator, and quietly drawing around it would turn a visible stall
     * into a silently wrong bracket. `app:competition:health` is what surfaces both.
     */
    private function hasUnstoredSubmissions(ActiveCompetition $activeCompetition): bool
    {
        return $this->entrantRepository->countUnstoredForCompetition($activeCompetition) > 0;
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

        // DRAWN is set here, not in claimDraw(), so the status and the fixtures it refers to
        // commit together — see claimDraw()'s docblock. Clearing the lease in the same flush
        // makes `draw_locked_at IS NOT NULL AND status = 'draw_pending'` mean exactly "being
        // drawn right now, or a tick died mid-draw", which is monitorable.
        $round->setStatus(CompetitionRoundStatus::DRAWN);
        $round->setDrawLockedAt(null);
        $round->setStartedAt($now);
        $round->setMatchesResolveAt($this->resolveDueAt($round, $now));

        if ($this->inboxService !== null) {
            $templateName  = $activeCompetition->getTemplate()->getName();
            $competitionId = (string) $activeCompetition->getId();
            foreach ($fixtures as $fixture) {
                $home = $fixture->getHomeEntrant();
                $away = $fixture->getAwayEntrant();
                foreach ([[$home, $away], [$away, $home]] as [$entrant, $opponent]) {
                    if (!$entrant instanceof CompetitionEntrant) {
                        continue;
                    }
                    $opponentName = $opponent?->getClub()->getName() ?? 'TBD';
                    $this->inboxService->createMessage(
                        club: $entrant->getClub(),
                        senderType: MessageSenderType::COMPETITION,
                        senderName: $templateName,
                        subject: sprintf('%s draw: %s vs %s', $round->getLabel(), $home?->getClub()->getName() ?? 'TBD', $away?->getClub()->getName() ?? 'TBD'),
                        body: sprintf('You have been drawn against %s in the %s of the %s.', $opponentName, $round->getLabel(), $templateName),
                        offerData: [
                            'type'          => 'ROUND_DRAWN',
                            'link'          => ['kind' => 'match', 'id' => (string) $fixture->getId()],
                            'competitionId' => $competitionId,
                            'roundId'       => (string) $round->getId(),
                            'fixtureId'     => (string) $fixture->getId(),
                        ],
                        relatedEntityType: 'competition_fixture',
                        relatedEntityId: (string) $fixture->getId(),
                    );
                }
            }
        }

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
